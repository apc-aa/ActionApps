<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Optimize\Convert2innodb;
use AA_Metabase;
use AA_Value;
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
    /** E: a value typed in the preview for a settings field of numbers is no whole number; ref = the value */
    public const SETTING_NUMBER = 'setting-not-number';
    /** E: a value typed in the preview for a settings field whose insert function changes it (a password
     *  stores its hash) - it is filled in the slice after installing; ref = the insert function */
    public const SETTING_INSERT = 'setting-insert-func';
    /** E: a value typed in the preview for a settings field does not pass its input_validate; ref = the value */
    public const SETTING_INVALID = 'setting-invalid';
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
     *  @param ImportChoices|null $choices a new owner; the names, the slices left out and the values typed
     *                                    for the settings are choose()'s, before the validation
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
        $created   = array_diff_key($modules, $targets);
        if ($transfer || !$created) {
            $owner = '';            // no module gets it - the writer need not check it
        } elseif ($choices->newOwner !== null) {
            [$owner, $new_owner] = ['', $choices->newOwner];
            $this->newOwner($new_owner, $charsets[array_key_first($created)] ?? 'utf-8');
        } else {
            $this->owner($owner);
        }
        $defaults = [];
        if (!$transfer) {           // a transfer writes the values it brings, the typed ones among them (choose())
            $cleared = [];
            foreach ($fixed->modules as $m) {
                // an existing slice: only the fields it lacks and has no value of (ExistingSlices::into())
                if (($m->type === Module::SLICE) && ($values = SettingDefaults::values($m, $this->schema, $this->findings, $targets[$m->id]->settings ?? []))) {
                    $defaults[$m->id] = $values;
                }
                $cleared[] = ($m->transfer === []) ? $m : self::withTransfer($m, []);     // the writer takes the values from the defaults
            }
            $fixed = new Package(...array_merge(get_object_vars($fixed), ['modules' => $cleared]));
        }
        $this->engines($transfer, (bool) $defaults, $new_owner !== null);
        return new ImportPlan($fixed, $map, $charsets, $lang_files, $group_charsets, $settings, $fix_ups, $validation->findings, $this->findings->all(), $targets, $defaults, $owner, $new_owner);
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

    /** E OWNER_INVALID unless the new owner has a name and a valid e-mail that RowWriter::owner() writes:
     *  each in the charset of the first module it is created for and within its slice_owner column
     *  @param array{name: string, email: string} $owner */
    private function newOwner(array $owner, string $charset): void
    {
        $limits = $this->schema->tableLimits('slice_owner');
        try {
            foreach (['name', 'email'] as $column) {
                RowStore::plain($owner[$column], $charset, self::TARGET, $limits[$column] ?? null);
            }
            $valid = ($owner['name'] !== '') && filter_var($owner['email'], FILTER_VALIDATE_EMAIL);
        } catch (WriteError) {
            $valid = false;
        }
        if (!$valid) {
            $this->add(self::TARGET, LintFinding::ERROR, self::OWNER_INVALID, $owner['email']);
        }
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
    private function engines(bool $transfer, bool $settingValues, bool $newOwner): void
    {
        $not = $this->target->nonTransactional(self::tables($this->schema, $transfer, $settingValues, $newOwner));
        if ($not) {
            $this->findings->add(self::TARGET, new LintFinding(LintFinding::ERROR, self::NO_TRANSACTION, self::noTransaction($not), 0));
        }
    }

    /** The tables an import writes - what must be InnoDB: the planner's E and the writer's check
     *  (Importer::write()) ask ImportTarget::nonTransactional() about these
     *  @param bool $settingValues an install writes the defaults of settings fields (ImportPlan::$settingDefaults)
     *  @param bool $newOwner      an install creates an owner (ImportPlan::$newOwner)
     *  @return list<string> */
    public static function tables(Schema $schema, bool $transfer, bool $settingValues = false, bool $newOwner = false): array
    {
        return array_values(array_unique([...array_values($schema->rowTables()), ...ObjectExport::TABLES, ...($transfer ? self::TRANSFER_TABLES : []),
            ...($settingValues ? ['content'] : []), ...($newOwner ? ['slice_owner'] : [])]));
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
     *  slices left out, the values typed for the settings of the slices (typed()). What names a left-out
     *  slice structurally goes with it or is cut off: the site's "uses" row, the reader slice of an Alerts
     *  module (W - it stays without readers), the owner of a constant group the rest still uses (W), a group
     *  only it used (left out); a reference in a text stays and the plan reports it (W).
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
        $renames = [];
        foreach ($package->modules as $m) {         // the form sends every name back - a rename is another one
            $name = $choices->names[strtolower($m->id)] ?? null;
            if (($name !== null) && ($name !== trim(ValueCodec::bytes($m->rows['module'][0]['name'] ?? '')))) {
                $renames[$m->id] = $name;
            }
        }
        if (!$skip && !$renames && !$choices->settings) {
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
            if (($name = $renames[$m->id] ?? null) !== null) {
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
            $modules[] = new Module($m->type, $m->id, $rows, $m->settings, $m->objects, $m->spotTree, $m->items, $m->doc, $this->typed($m, $choices->settings[strtolower($m->id)] ?? []));
        }
        $kept = new Package(...array_merge(get_object_vars($package), ['modules' => $modules]));
        if (!array_filter($package->constantGroups, static fn(ConstantGroup $g): bool => isset($skip[$g->owner]))) {
            return $kept;
        }
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

    /** The transfer part of a slice with the values typed for its settings fields, over those of the source
     *  (its translations stay): the Validator lints them and the plan checks their charset and references as
     *  it does the source's. E for a value the field's input_validate refuses, no whole number in a number
     *  field, a boo field other than 0 or 1, a field whose insert function changes the value (a password its
     *  hash) - that one is filled in the slice later.
     *  @param array<string, string> $typed field id => value (ImportChoices::$settings of the slice)
     *  @return array<string, mixed> */
    private function typed(Module $m, array $typed): array
    {
        if (!$typed || ($m->type !== Module::SLICE)) {
            return $m->transfer;
        }
        $values = [];
        foreach ($m->rows['field'] ?? [] as $row) {
            $id = ValueCodec::bytes($row['id'] ?? '');
            if (!isset($typed[$id])) {
                continue;
            }
            $flag   = SettingDefaults::flag($row, $this->schema);
            $insert = SettingDefaults::insert($row, $this->schema);
            $number = ($insert !== 'boo') && !($flag & FLAG_TEXT_STORED);
            $value  = ($number || ($insert === 'boo')) ? trim($typed[$id]) : $typed[$id];
            $at     = "$m->type[$m->id]/setting-values/{$id}[0]";
            if (!SettingDefaults::plainInsert($insert)) {
                $this->add($at, LintFinding::ERROR, self::SETTING_INSERT, $insert);
                continue;
            }
            if ($number && (($value = self::wholeNumber($value)) === null)) {
                $this->add($at, LintFinding::ERROR, self::SETTING_NUMBER, $typed[$id]);
                continue;
            }
            // boo: the preview offers 0 and 1 - PHP would take "no" for true
            if ((($insert === 'boo') && !in_array($value, ['0', '1'], true)) || (($value = SettingDefaults::checked($row, $this->schema, $value)) === null)) {
                $this->add($at, LintFinding::ERROR, self::SETTING_INVALID, $typed[$id]);
                continue;
            }
            $translations = array_filter($m->transfer['setting-values'][$id] ?? [], static fn(int|string $key): bool => (int) $key >= AA_Value::MAX_INDEX, ARRAY_FILTER_USE_KEY);
            $values[$id]  = [0 => ['value' => SettingDefaults::stored($insert, $value), 'flag' => $flag]] + $translations;
        }
        return $values ? ['setting-values' => $values + ($m->transfer['setting-values'] ?? [])] + $m->transfer : $m->transfer;
    }

    /** a whole number content.number (bigint) holds, without leading zeros; null = none */
    private static function wholeNumber(string $value): ?string
    {
        if (!preg_match('/^([+-]?)0*(\d{1,19})\z/', $value, $match)) {
            return null;
        }
        $int = filter_var($match[1] . $match[2], FILTER_VALIDATE_INT);
        return ($int === false) ? null : (string) $int;
    }

    /** @param array<string, mixed> $transfer */
    private static function withTransfer(Module $m, array $transfer): Module
    {
        return new Module($m->type, $m->id, $m->rows, $m->settings, $m->objects, $m->spotTree, $m->items, $m->doc, $transfer);
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings->add($path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0));
    }
}
