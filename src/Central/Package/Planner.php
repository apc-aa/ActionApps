<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Optimize\Convert2innodb;
use AA_Metabase;
use InvalidArgumentException;

/** The import planner: a package that passed the Validator + a target => ImportPlan, with no write. Pure
 *  except for ImportTarget, so tests run without a DB. The engine check (E NO_TRANSACTION) is here so the
 *  preview shows it: the rollback is the only cleanup of a failed import.
 *  The rights and the S confirmation are the writer's (Importer::write()). */
final class Planner
{
    /** I: a constant group gets another name on the target; ref = "old => new" */
    public const GROUP_RENAMED = GroupPlan::RENAMED;
    /** E: no free name for a group */
    public const GROUP_NAME_EXHAUSTED = GroupPlan::EXHAUSTED;
    /** E: the module's lang_file names no language - its charset is unknown (Validator's E already) */
    public const LANG_FILE = Validator::LANG_FILE;
    /** E: an object or constant id of the package equals a new settings object id */
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
    /** S: a domain of the source site a transfer gives the new site - confirm it is to move; ref = the URL */
    public const DOMAIN_KEPT = 'domain-kept';
    /** W: a domain of the source site left out - a site of this AA answers to it, it is this AA's own
     *  host, or it names no host; ref = the URL */
    public const DOMAIN_DROPPED = 'domain-dropped';

    /** tables only a transfer writes: the item batches, the owners of the modules */
    private const TRANSFER_TABLES = ['item', 'content', 'slice_owner'];

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

    /** @param bool         $transfer     the importer's mode is a transfer (the Validator was asked the same)
     *  @param list<string> $readerLogins the login names of the readers the transfer brings (Collector::readerLogins()) -
     *                                    E for those of a user, or of a reader with AA permissions, of this AA
     *  @param array<string, string> $into slices of the package going into existing slices of this AA: source id => id here
     *  @param bool         $sameAa       the source is this AA (AppSource::sameAa())
     *  @throws InvalidArgumentException the validation has an E - such a package is never planned
     *  @throws RewriteFailed a regex of the length check failed */
    public function plan(Package $package, ValidationResult $validation, bool $transfer = false, array $readerLogins = [], array $into = [], bool $sameAa = false): ImportPlan
    {
        if (!$validation->importable()) {
            throw new InvalidArgumentException('The package did not pass the validation - no import plan');
        }
        $this->findings = new FindingList();
        $this->used     = [];
        [$fixed, $fix_ups] = (new FixUps($this->schema))->apply($package);
        $whole             = $this->domains($fixed);
        $existing          = new ExistingSlices($this->target, $this->schema, $this->findings);
        $fixed             = $existing->prune($whole, $into, $transfer, $sameAa);
        $targets           = $existing->targets();

        $charsets = $lang_files = [];
        foreach ($fixed->modules as $m) {
            if (isset($targets[$m->id])) {
                [$charsets[$m->id], $lang_files[$m->id]] = [$targets[$m->id]->charset, $targets[$m->id]->langFile];
                continue;
            }
            [$lang_file, $charset] = ModuleCharset::ofModule(['type' => $m->type] + ($m->rows['module'][0] ?? [])) ?? [null, null];
            if ($charset === null) {
                $this->add("$m->type[$m->id]/module[#0]/lang_file", LintFinding::ERROR, self::LANG_FILE, ValueCodec::bytes($m->rows['module'][0]['lang_file'] ?? ''));
            } else {
                [$charsets[$m->id], $lang_files[$m->id]] = [$charset, $lang_file];
            }
        }
        $groups         = new GroupPlan($this->target, new LintTexts($this->schema));
        $fixed          = $existing->groups($whole, $fixed, $groups);
        $group_charsets = $groups->charsets($fixed, $charsets);

        $modules = $long = $settings = $collections = [];
        foreach ($fixed->modules as $m) {
            $modules[$m->id] = isset($targets[$m->id]) ? $targets[$m->id]->id : $this->newId();
        }
        foreach ($fixed->modules as $m) {       // before any other new id - none may equal one
            if (isset($targets[$m->id])) {      // its settings stay; references to them go there
                $class = Schema::SETTINGS_CLASSES[$m->type];
                $long[string2id($class . strtolower($m->id))] = string2id($class . $targets[$m->id]->id);
            } elseif (isset(Schema::SETTINGS_CLASSES[$m->type])) {
                $class                      = Schema::SETTINGS_CLASSES[$m->type];
                $settings[$m->id]           = string2id($class . strtolower($modules[$m->id]));
                $long[string2id($class . strtolower($m->id))] = $settings[$m->id];     // the Validator: no package id
                $this->used[$settings[$m->id]] = true;
            }
        }
        $settings_of = array_flip($settings);
        foreach ($fixed->modules as $m) {
            foreach (ObjectExport::ids($m->objects) as $id) {
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
        $long += $existing->objects();
        $map   = new IdMap($modules, $long, $existing->views(), $existing->emails(), $groups->names($fixed, $this->findings) + $existing->groupMap(), $collections);

        $existing->differences($map, $transfer);
        $this->alertsFields($fixed, $map);
        (new TargetCharset($this->schema, $map))->check($fixed, $charsets, $group_charsets, $this->findings);
        (new ImportLint($this->schema))->check($fixed, $this->findings, $whole);
        $this->readers($readerLogins);
        $this->engines($transfer);
        return new ImportPlan($fixed, $map, $charsets, $lang_files, $group_charsets, $settings, $fix_ups, $validation->findings, $this->findings->all(), $targets);
    }

    /** E NO_TRANSACTION for the tables the import writes without a transaction - the whole list (a short
     *  one: the tables of AA), not cut to ReferenceLint::MAX_REF */
    private function engines(bool $transfer): void
    {
        $not = $this->target->nonTransactional(self::tables($this->schema, $transfer));
        if ($not) {
            $this->findings->add(self::TARGET, new LintFinding(LintFinding::ERROR, self::NO_TRANSACTION, self::noTransaction($not), 0));
        }
    }

    /** The tables an import writes - what must be InnoDB: the planner's E and the writer's check
     *  (Importer::write()) ask ImportTarget::nonTransactional() about these
     *  @return list<string> */
    public static function tables(Schema $schema, bool $transfer): array
    {
        return array_values(array_unique([...array_values($schema->rowTables()), ...ObjectExport::TABLES, ...($transfer ? self::TRANSFER_TABLES : [])]));
    }

    /** the host a site domain of a package names, lower case; null = none @param string $url a URL or a bare host */
    public static function domainHost(string $url): ?string
    {
        $host = parse_url(str_contains($url, '://') ? $url : "http://$url", PHP_URL_HOST);
        return (is_string($host) && ($host !== '')) ? strtolower($host) : null;
    }

    /** a domain the site would take over from another site of this AA, or from AA itself */
    public function domainTaken(string $host): bool
    {
        $bare = static fn(string $h): string => str_starts_with($h, 'www.') ? substr($h, 4) : $h;
        return in_array($bare(strtolower($host)), array_map($bare, $this->target->ownHosts()), true) || $this->target->domainTaken($host);
    }

    /** The domains a transfer brings (alternate-url) that no site of this AA answers to and that are not
     *  this AA's own host - the rest dropped (W): the new site would take a live domain over. A kept one is
     *  S, confirmed in the preview. */
    private function domains(Package $package): Package
    {
        $modules = [];
        foreach ($package->modules as $m) {
            $kept = [];
            foreach ($m->transfer['alternate-url'] ?? [] as $i => $url) {
                $text = ValueCodec::bytes($url);
                $host = self::domainHost($text);
                $keep = ($host !== null) && !$this->domainTaken($host);
                $this->add("$m->type[$m->id]/alternate-url[$i]", $keep ? LintFinding::SECURITY : LintFinding::WARNING, $keep ? self::DOMAIN_KEPT : self::DOMAIN_DROPPED, $text);
                if ($keep) {
                    $kept[] = $url;
                }
            }
            $transfer = array_diff_key($m->transfer, ['alternate-url' => true]) + ($kept ? ['alternate-url' => $kept] : []);
            $modules[] = isset($m->transfer['alternate-url']) ? new Module($m->type, $m->id, $m->rows, $m->settings, $m->objects, $m->spotTree, $m->items, $m->doc, $transfer) : $m;
        }
        return new Package(...array_merge(get_object_vars($package), ['modules' => $modules]));
    }

    /** E for the reader logins a transfer brings that a user of this AA, or a reader holding AA
     *  permissions, has - before any definition is written; the batches check again @param list<string> $logins */
    private function readers(array $logins): void
    {
        $readers = $this->target->readerLogins(ItemLint::USERNAME, $logins);
        foreach ($logins as $login) {
            if ($this->target->adminLoginTaken($login)) {
                $this->add(self::TARGET, LintFinding::ERROR, ItemLint::READER_ADMIN, $login);
            } elseif ($readers[ItemLint::loginKey($login)] ?? false) {
                $this->add(self::TARGET, LintFinding::ERROR, ItemLint::READER_PERMS, $login);
            }
        }
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
