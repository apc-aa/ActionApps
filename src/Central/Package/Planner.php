<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
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

    /** E: the owner chosen for the new modules of an install is none of this AA's (slice_owner), or none
     *  was chosen though it has some; ref = the id */
    public const OWNER_UNKNOWN = 'owner-unknown';
    /** E: the new owner chosen in the preview has no name or no valid e-mail; ref = the e-mail */
    public const OWNER_INVALID = 'owner-invalid';
    /** W: an Alerts module using a slice the preview left out - its readers, the view of a filter or an
     *  e-mail: it gets none; ref = the slice id, the view or e-mail number */
    public const READER_LEFT_OUT = 'reader-left-out';
    /** W: a constant group of a slice the preview left out stays, used by the rest - it gets no owner; ref = the slice id */
    public const GROUP_OWNER_LEFT_OUT = 'group-owner-left-out';

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
     *  @param string       $owner        slice_owner id for the modules an install creates (defaultOwner());
     *                                    E unless it is one of this AA's - '' only when it has none; the plan
     *                                    keeps '' when no module is created
     *  @param ImportChoices|null $choices a new owner and typed settings values; the names and the slices
     *                                    left out are choose()'s, before the validation
     *  @throws InvalidArgumentException the validation has an E - such a package is never planned; an owner in a transfer
     *  @throws RewriteFailed a regex of the length check failed */
    public function plan(Package $package, ValidationResult $validation, bool $transfer = false, array $readerLogins = [], array $into = [], bool $sameAa = false, string $owner = '', ?ImportChoices $choices = null): ImportPlan
    {
        $choices ??= new ImportChoices();
        if (!$validation->importable()) {
            throw new InvalidArgumentException('The package did not pass the validation - no import plan');
        }
        if ($transfer && ($owner !== '')) {
            throw new InvalidArgumentException('A transfer brings the owners of the source - no owner is chosen');
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
        $new_owner = null;
        if ($transfer || !array_diff_key($modules, $targets)) {
            $owner = '';            // no module gets it - the writer need not check it
        } elseif ($choices->newOwner !== null) {
            [$owner, $new_owner] = ['', $choices->newOwner];
            if (($new_owner['name'] === '') || !filter_var($new_owner['email'], FILTER_VALIDATE_EMAIL)) {
                $this->add(self::TARGET, LintFinding::ERROR, self::OWNER_INVALID, $new_owner['email']);
            }
        } else {
            $this->owner($owner);
        }
        $defaults = $of_app = [];
        if (!$transfer) {           // a transfer brings the values of the source
            foreach ($fixed->modules as $m) {
                // an existing slice: $m holds only the fields it lacks
                if ($m->type !== Module::SLICE) {
                    continue;
                }
                // the values of the source (a superadmin's export), else the defaults of the fields
                $source         = array_intersect_key($m->transfer['setting-values'] ?? [], array_flip(array_map(static fn(array $r): string => ValueCodec::bytes($r['id'] ?? ''), $m->rows['field'] ?? [])));
                $source         = array_map(static fn(array $values): array => array_map(static fn(array $v): array => ['value' => ValueCodec::bytes($v['value'] ?? ''), 'flag' => (int) ($v['flag'] ?? 0)], $values), $source);
                $of_app[$m->id] = $source + SettingDefaults::of($m, $this->schema, $this->findings, [...($targets[$m->id]->settings ?? []), ...array_keys($source)]);
                if ($values = $this->typed($m, $choices, $of_app[$m->id])) {
                    $defaults[$m->id] = $values;
                }
            }
        }
        if (!$transfer) {           // the values of the source went into the defaults - the writer takes those
            $fixed = new Package(...array_merge(get_object_vars($fixed), ['modules' => array_map(static fn(Module $m): Module => ($m->transfer === [])
                ? $m : new Module($m->type, $m->id, $m->rows, $m->settings, $m->objects, $m->spotTree, $m->items, $m->doc, []), $fixed->modules)]));
        }
        $this->engines($transfer, (bool) $defaults, $new_owner !== null);
        return new ImportPlan($fixed, $map, $charsets, $lang_files, $group_charsets, $settings, $fix_ups, $validation->findings, $this->findings->all(), $targets, $defaults, $owner, $new_owner, array_filter($of_app));
    }

    /** The owner the preview offers first: the one most of the modules the user may edit have; with none
     *  such, the first by name; '' = this AA has no owner */
    public function defaultOwner(): string
    {
        $owners = $this->target->owners();
        $counts = [];
        foreach ($this->target->moduleOwners() as $module => $owner) {
            if (isset($owners[$owner]) && $this->target->mayEditSlice((string) $module)) {
                $counts[$owner] = ($counts[$owner] ?? 0) + 1;
            }
        }
        $best = (string) array_key_first($owners);
        foreach (array_keys($owners) as $id) {          // by name: the first of a tie wins
            if (($counts[$id] ?? 0) > ($counts[$best] ?? 0)) {
                $best = (string) $id;
            }
        }
        return $best;
    }

    /** E OWNER_UNKNOWN: the id comes from the request */
    private function owner(string $owner): void
    {
        $owners = $this->target->owners();
        if (($owner === '') ? (bool) $owners : !isset($owners[$owner])) {
            $this->add(self::TARGET, LintFinding::ERROR, self::OWNER_UNKNOWN, $owner);
        }
    }

    /** E NO_TRANSACTION for the tables the import writes without a transaction - the whole list (a short
     *  one: the tables of AA), not cut to ReferenceLint::MAX_REF */
    private function engines(bool $transfer, bool $settingValues, bool $newOwner = false): void
    {
        $not = $this->target->nonTransactional(array_values(array_unique([...self::tables($this->schema, $transfer, $settingValues), ...($newOwner ? ['slice_owner'] : [])])));
        if ($not) {
            $this->findings->add(self::TARGET, new LintFinding(LintFinding::ERROR, self::NO_TRANSACTION, self::noTransaction($not), 0));
        }
    }

    /** The tables an import writes - what must be InnoDB: the planner's E and the writer's check
     *  (Importer::write()) ask ImportTarget::nonTransactional() about these
     *  @param bool $settingValues an install writes the defaults of settings fields (ImportPlan::$settingDefaults)
     *  @return list<string> */
    public static function tables(Schema $schema, bool $transfer, bool $settingValues = false): array
    {
        return array_values(array_unique([...array_values($schema->rowTables()), ...ObjectExport::TABLES, ...($transfer ? self::TRANSFER_TABLES : []), ...($settingValues ? ['content'] : [])]));
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

    /** The package as the preview chose it, to be validated and planned: new names of the modules, the
     *  slices left out. What names a left-out slice structurally goes with it or is cut off: the site's
     *  "uses" row, the reader slice of an Alerts module (W - it stays without readers), the owner of a
     *  constant group the rest still uses (W), a group only it used (left out); a reference in a text stays
     *  and the plan reports it (W).
     *  @return array{Package, list<array{string, LintFinding}>} */
    public function choose(Package $package, ImportChoices $choices): array
    {
        $this->findings = new FindingList();
        return [$this->chosen($package, $choices), $this->findings->all()];
    }

    private function chosen(Package $package, ImportChoices $choices): Package
    {
        $skip = array_flip(array_map(static fn(Module $m): string => $m->id, array_filter(
            $package->modules,
            static fn(Module $m): bool => ($m->type === Module::SLICE) && $choices->skips($m->id),
        )));
        if (!$skip && !$choices->names) {
            return $package;
        }
        $gone = ['view' => [], 'email' => []];      // what the Alerts may use of a slice left out
        foreach ($package->modules as $m) {
            foreach (isset($skip[$m->id]) ? array_keys($gone) : [] as $element) {
                $gone[$element] += array_fill_keys(array_map(static fn(array $r): string => ValueCodec::bytes($r['id'] ?? ''), $m->rows[$element] ?? []), true);
            }
        }
        $modules = [];
        foreach ($package->modules as $m) {
            if (isset($skip[$m->id])) {
                continue;
            }
            $rows = $m->rows;
            if (($name = $choices->names[strtolower($m->id)] ?? null) !== null) {
                foreach (($m->type === Module::SLICE) ? ['module', 'slice'] : ['module'] as $element) {
                    if (isset($rows[$element][0])) {
                        $rows[$element][0]['name'] = $name;
                    }
                }
            }
            $rows['uses'] = array_values(array_filter($rows['uses'] ?? [], static fn(array $r): bool => !isset($skip[ValueCodec::bytes($r['destination_id'] ?? '')])));
            if (($reader = ValueCodec::bytes($rows['alerts_collection'][0]['slice_id'] ?? '')) !== '' && isset($skip[$reader])) {
                $rows['alerts_collection'][0]['slice_id'] = '';
                $this->add("$m->type[$m->id]/alerts_collection/slice_id", LintFinding::WARNING, self::READER_LEFT_OUT, $reader);
            }
            foreach (['emailid_welcome', 'emailid_alert'] as $column) {
                if (isset($rows['alerts_collection'][0][$column], $gone['email'][ValueCodec::bytes($rows['alerts_collection'][0][$column])])) {
                    $this->add("$m->type[$m->id]/alerts_collection/$column", LintFinding::WARNING, self::READER_LEFT_OUT, ValueCodec::bytes($rows['alerts_collection'][0][$column]));
                    $rows['alerts_collection'][0][$column] = '0';
                }
            }
            foreach ($rows['alerts_filter'] ?? [] as $i => $row) {
                if (isset($row['vid'], $gone['view'][ValueCodec::bytes($row['vid'])])) {
                    $this->add($this->schema->rowPath("$m->type[$m->id]", 'alerts_filter', $row, $i) . '/vid', LintFinding::WARNING, self::READER_LEFT_OUT, ValueCodec::bytes($row['vid']));
                    $rows['alerts_filter'][$i]['vid'] = '0';
                }
            }
            $rows = array_filter($rows, static fn(array $r, string $element): bool => ($r !== []) || isset($m->rows[$element]), ARRAY_FILTER_USE_BOTH);
            $modules[] = new Module($m->type, $m->id, $rows, $m->settings, $m->objects, $m->spotTree, $m->items, $m->doc, $m->transfer);
        }
        $kept   = new Package(...array_merge(get_object_vars($package), ['modules' => $modules]));
        $used   = (new GroupPlan($this->target, new LintTexts($this->schema)))->usedNames($kept);
        $groups = [];
        foreach ($package->constantGroups as $g) {
            if (!isset($skip[$g->owner])) {
                $groups[] = $g;
            } elseif (isset($used[$g->name])) {
                $this->add("constant-group[$g->name]", LintFinding::WARNING, self::GROUP_OWNER_LEFT_OUT, $g->owner);
                $groups[] = new ConstantGroup($g->name, '', $g->rows);
            }
        }
        return new Package(...array_merge(get_object_vars($kept), ['constantGroups' => $groups]));
    }

    /** the values typed in the preview over the defaults of the app, each through the security lint (a
     *  setting is a template like any other); only fields the slice writes
     *  @param array<string, array<int, array{value: string, flag: int}>> $defaults
     *  @return array<string, array<int, array{value: string, flag: int}>> */
    private function typed(Module $m, ImportChoices $choices, array $defaults): array
    {
        $lint = null;
        foreach ($m->rows['field'] ?? [] as $row) {
            $id = ValueCodec::bytes($row['id'] ?? '');
            if (($value = $choices->settings[strtolower($m->id)][$id] ?? null) === null) {
                continue;
            }
            $column = fn(string $c): string => ValueCodec::bytes(array_key_exists($c, $row) ? $row[$c] : $this->schema->columnDefault('field', $c));
            $flag   = ((int) $column('html_default') > 0 ? FLAG_HTML : 0) | ((int) $column('text_stored') !== 0 ? FLAG_TEXT_STORED : 0);
            $defaults[$id] = [0 => ['value' => $value, 'flag' => $flag]];
            foreach (($lint ??= new SecurityLint($this->schema))->lint($value) as $finding) {
                $this->findings->add("$m->type[$m->id]/setting-values/{$id}[0]", $finding);
            }
        }
        return $defaults;
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings->add($path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0));
    }
}
