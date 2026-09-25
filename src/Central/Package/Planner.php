<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Optimize\Convert2innodb;
use AA_Metabase;
use InvalidArgumentException;

/** The import planner (design balicek-v1.md § 7.2 step 3): a package that passed the Validator + a target =>
 *  ImportPlan, with no write. Pure except for ImportTarget, so tests run without a DB. In order: FixUps; the
 *  charset of each module (its lang_file) and group (GroupPlan); the maps (§ 7.1) - new ids none twice, the
 *  derived settings ids, collections, group names; TargetCharset, ImportLint, ItemLint; the engine - E
 *  NO_TRANSACTION here, so the preview shows it: the rollback is the only cleanup of a failed import.
 *  The rights and the S confirmation are the writer's (Importer::write()). */
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
    /** E: tables the import writes are not InnoDB - no transaction would roll a failed import back; ref =
     *  noTransaction(): "table (engine), ... - ..." (engine '' = no such table), naming the AA optimization for it */
    public const NO_TRANSACTION = 'no-transaction';
    /** the path of a finding about the target AA, not the package */
    public const TARGET = 'target';

    /** tables of the items (ItemWriter), beside the rows of Schema and the objects */
    private const ITEM_TABLES = ['item', 'content'];

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

    /** E NO_TRANSACTION for the tables the import writes without a transaction - the whole list (a short
     *  one: the tables of AA), not cut to ReferenceLint::MAX_REF */
    private function engines(Package $package): void
    {
        $not = $this->target->nonTransactional(self::tables($this->schema, $package));
        if ($not) {
            $this->findings->add(self::TARGET, new LintFinding(LintFinding::ERROR, self::NO_TRANSACTION, self::noTransaction($not), 0));
        }
    }

    /** The tables an import of $package writes - what must be InnoDB: the planner's E and the writer's check
     *  (Importer::write()) ask ImportTarget::nonTransactional() about these
     *  @return list<string> */
    public static function tables(Schema $schema, Package $package): array
    {
        $tables = array_values(array_unique([...array_values($schema->rowTables()), ...ObjectExport::TABLES]));
        if (array_filter($package->modules, static fn(Module $m): bool => (bool) $m->items)) {
            $tables = [...$tables, ...self::ITEM_TABLES];
        }
        return $tables;
    }

    /** the text of the E NO_TRANSACTION (and of the writer's WriteError::ENGINE): the tables, what to do
     *  @param array<string, string> $not ImportTarget::nonTransactional() - table => engine */
    public static function noTransaction(array $not): string
    {
        $list = implode(', ', array_map(static fn(string $t, string $e): string => "$t ($e)", array_keys($not), $not));
        return "$list - no transaction would roll a failed import back: run \"" . Convert2innodb::name() . '" (State Check, Optimize) and plan again';
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
