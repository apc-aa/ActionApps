<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA_Metabase;
use InvalidArgumentException;

/** The import planner (design balicek-v1.md § 7.2 step 3; C4.4 part 3): a package that passed the
 *  Validator + a target => ImportPlan, with no write. Pure except for ImportTarget - the questions to
 *  the target (group names taken, new ids) - so tests run without a DB.
 *
 *  1. FixUps: what the importer changes (attachments, lang_file, settings, spot markup, structure,
 *     a shared alerts filter once, publish = users) - applied to a copy, listed for the preview.
 *  2. The charset of each module: its lang_file (ModuleCharset::ofModule(); the target module gets the
 *     package's lang_file - normalised, $langFiles - § 2.4); of a constant group: the export's rule
 *     (GroupPlan: the owner's, else the first module naming it in a widget or a text, a slice preferred).
 *  3. The maps (§ 7.1) - modules, then per module its objects and subobjects and items, then the
 *     constants: new_id(), none twice in the plan; settings objects: string2id(class . new module id),
 *     old => new in the long map too (a package id equal to a new settings id = E; to an old one: the
 *     Validator's E); alerts collections: new_collection_id(); group names: GroupPlan. Views, e-mails
 *     and filters: the writer.
 *  4. TargetCharset: every value converts to its target charset and fits its column (E, W).
 *  5. ImportLint: references the rewrite cannot map or that lead outside the package (W, I); ItemLint:
 *     readers whose login or e-mail the target has (E), the login accounts and 2FA seeds the package
 *     brings (I, W), relations and alerts subscriptions leading outside it (W, I).
 *  6. The engine (§ 7.2 step 4): the tables the import writes that are not InnoDB (ImportTarget::
 *     nonTransactional()) - W NO_TRANSACTION: no rollback there, a failure is cleaned up by the
 *     compensation (Importer) only. Here, not in the writer, so the preview shows it before the write.
 *
 *  Not here (they plug in around plan()): the "keep module ids" restore mode (§ 7.1: would replace
 *  the module part of the map after its checks), rights (PS_INSTALL_APP, Q-H2) and the S confirmation
 *  (UI, part 5), writing, the re-check of the names and ids inside the transaction and compensation
 *  (part 4, ImportPlan), the chunk mode (part 6). */
final class Planner
{
    /** I: a constant group gets another name on the target; ref = "old => new" */
    public const GROUP_RENAMED = GroupPlan::RENAMED;
    /** E: no free name for a group */
    public const GROUP_NAME_EXHAUSTED = GroupPlan::EXHAUSTED;
    /** E: the module's lang_file names no language - its charset is unknown (Validator's E already) */
    public const LANG_FILE = Validator::LANG_FILE;
    /** E: an object, item or constant id of the package equals a new settings object id */
    public const SETTINGS_ID = Validator::DUPLICATE;
    /** I: an alerts field (`alerts1....<cid>`, getAlertsField()) of a collection the package does not carry
     *  is written as it is - AA reads it only through a collection whose reader slice is this new slice, so
     *  it is an inert definition; ref = the collection id */
    public const ALERTS_FIELD_KEPT = 'alerts-field-kept';
    /** W: tables the import writes are not InnoDB - no transaction; after a failure the compensation removes
     *  what the import wrote there; ref = "table (engine), ..." (engine '' = no such table) */
    public const NO_TRANSACTION = 'no-transaction';
    /** the path of a finding about the target AA, not the package */
    public const TARGET = 'target';

    /** tables of the objects (§ 5.4, ObjectWriter) and of the items (ItemWriter), beside the rows of Schema */
    private const OBJECT_TABLES = ['object_text', 'object_integer', 'object_float'];
    private const ITEM_TABLES   = ['item', 'content'];

    /** an alerts field id: FIELDID_HOWOFTEN / FIELDID_FILTERS padded with dots, then the collection id */
    private const ALERTS_FIELD_RE = '/^alerts[12]\.+([A-Za-z0-9]{1,9})\z/';

    private readonly Schema $schema;
    private FindingList $findings;
    /** @var array<string, true> new long ids handed out in this plan */
    private array $used = [];

    public function __construct(private readonly ImportTarget $target, ?AA_Metabase $metabase = null)
    {
        $this->schema = new Schema($metabase);
    }

    /** @throws InvalidArgumentException the validation has an E - such a package is never planned
     *  @throws RewriteFailed a regex of the length check failed */
    public function plan(Package $package, ValidationResult $validation): ImportPlan
    {
        if (!$validation->importable()) {
            throw new InvalidArgumentException('The package did not pass the validation - no import plan');
        }
        $this->findings = new FindingList();
        $this->used     = [];
        [$fixed, $fix_ups] = (new FixUps($this->schema))->apply($package);

        $charsets = $lang_files = [];
        foreach ($fixed->modules as $m) {
            [$lang_file, $charset] = ModuleCharset::ofModule(['type' => $m->type] + ($m->rows['module'][0] ?? [])) ?? [null, null];
            if ($charset === null) {
                $this->add("$m->type[$m->id]/module[#0]/lang_file", LintFinding::ERROR, self::LANG_FILE, ValueCodec::bytes($m->rows['module'][0]['lang_file'] ?? ''));
            } else {
                [$charsets[$m->id], $lang_files[$m->id]] = [$charset, $lang_file];
            }
        }
        $groups         = new GroupPlan($this->target, new LintTexts($this->schema));
        $group_charsets = $groups->charsets($fixed, $charsets);

        $modules = $long = $settings = $collections = [];
        foreach ($fixed->modules as $m) {
            $modules[$m->id] = $this->newId();
        }
        foreach ($fixed->modules as $m) {       // before any other new id - none may equal one
            if (isset(Schema::SETTINGS_CLASSES[$m->type])) {
                $class                      = Schema::SETTINGS_CLASSES[$m->type];
                $settings[$m->id]           = string2id($class . strtolower($modules[$m->id]));
                $long[string2id($class . strtolower($m->id))] = $settings[$m->id];     // the Validator: no package id
                $this->used[$settings[$m->id]] = true;
            }
        }
        $settings_of = array_flip($settings);
        foreach ($fixed->modules as $m) {
            foreach ([...ObjectExport::ids($m->objects), ...array_map(static fn(array $item): string => ValueCodec::bytes($item['id'] ?? ''), $m->items)] as $id) {
                $long[$this->sourceId($id, $settings_of, "$m->type[$m->id]")] = $this->newId();
            }
            if (($id = ValueCodec::bytes($m->rows['alerts_collection'][0]['id'] ?? '')) !== '') {
                $collections[$id] = $this->newCollectionId($collections);
            }
        }
        foreach ($fixed->constantGroups as $group) {
            foreach ($group->rows['constant'] ?? [] as $row) {
                $long[$this->sourceId(ValueCodec::bytes($row['id'] ?? ''), $settings_of, "constant-group[$group->name]")] = $this->newId();
            }
        }
        $map = new IdMap(modules: $modules, longIds: $long, groups: $groups->names($fixed, $this->findings), collections: $collections);

        $this->alertsFields($fixed, $map);
        (new TargetCharset($this->schema, $map))->check($fixed, $charsets, $group_charsets, $this->findings);
        (new ImportLint($this->schema))->check($fixed, $this->findings);
        (new ItemLint($this->target))->check($fixed, $charsets, $this->findings);
        $this->engines($fixed);
        return new ImportPlan($fixed, $map, $charsets, $lang_files, $group_charsets, $settings, $fix_ups, $validation->findings, $this->findings->all());
    }

    /** W NO_TRANSACTION for the tables the import writes without a transaction */
    private function engines(Package $package): void
    {
        $tables = array_values(array_unique([...array_values($this->schema->rowTables()), ...self::OBJECT_TABLES]));
        if (array_filter($package->modules, static fn(Module $m): bool => (bool) $m->items)) {
            $tables = [...$tables, ...self::ITEM_TABLES];
        }
        $not = $this->target->nonTransactional($tables);
        if ($not) {
            $list = implode(', ', array_map(static fn(string $t, string $e): string => "$t ($e)", array_keys($not), $not));
            $this->add(self::TARGET, LintFinding::WARNING, self::NO_TRANSACTION, $list);
        }
    }

    /** I ALERTS_FIELD_KEPT for the alerts fields of collections outside the package */
    private function alertsFields(Package $package, IdMap $map): void
    {
        foreach ($package->modules as $m) {
            foreach ($m->rows['field'] ?? [] as $i => $row) {
                if (preg_match(self::ALERTS_FIELD_RE, ValueCodec::bytes($row['id'] ?? ''), $match) && ($map->collection($match[1]) === null)) {
                    $this->add($this->schema->rowPath("$m->type[$m->id]", 'field', $row, $i), LintFinding::INFO, self::ALERTS_FIELD_KEPT, $match[1]);
                }
            }
        }
    }

    /** the package id; E when it equals a new settings object id (the map would send it there)
     *  @param array<string, string> $settings_of new settings id => module */
    private function sourceId(string $id, array $settings_of, string $path): string
    {
        if (isset($settings_of[strtolower($id)])) {
            $this->add($path, LintFinding::ERROR, self::SETTINGS_ID, $id);
        }
        return $id;
    }

    /** a new long id, none twice in the plan */
    private function newId(): string
    {
        do {
            $id = strtolower($this->target->newId());
        } while (isset($this->used[$id]));
        $this->used[$id] = true;
        return $id;
    }

    /** @param array<string, string> $planned collection ids of this plan */
    private function newCollectionId(array $planned): string
    {
        do {
            $id = $this->target->newCollectionId();
        } while (in_array($id, $planned, true));
        return $id;
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings->add($path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0));
    }
}
