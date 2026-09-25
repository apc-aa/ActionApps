<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA_Metabase;
use AA_Property;
use InvalidArgumentException;
use Throwable;

/** The semantic checks of a read package: what the XSD cannot say, and again what it says of structure
 *  and ids - the model may come from elsewhere than Reader. Pure: the target, the charsets and the
 *  reference lint are the planner's.
 *  E = the package must not be imported; S (SecurityLint) = confirm in the preview; W, I = report.
 *  Findings quote package text - escape them (safe()) when shown. */
final class Validator
{
    /** E: a row a module type needs is missing or there twice (module; slice / site; constant_slice at
     *  most once); W: an Alerts module without its collection (deleting the reader slice deletes it) */
    public const MODULE_ROWS = 'module-rows';
    /** E: a table, settings, objects, spot tree or items the container does not have */
    public const TABLE = 'table-not-allowed';
    /** E: an id of the wrong shape: long ids 32 lower-case hex, field ids 16 chars, view / e-mail / filter /
     *  spot ids 1-10 digits without a leading 0, collection ids 5 letters or digits; a packed column
     *  (slice.mlxctrl, alerts_collection.slice_id, constant.ancestors: several) not empty and no long id */
    public const INVALID_ID = 'invalid-id';
    /** E: an id twice - long ids across modules, their settings objects (string2id(class . module id)),
     *  constants, objects, subobjects and items (one id map),
     *  views, e-mails, groups, collections across the package; fields, spots, uses, filters per module; a
     *  filter id of several Alerts modules with other content */
    public const DUPLICATE = 'duplicate-id';
    /** E: uses, a group owner (a slice), a collection filter / howoften outside the package;
     *  W: an ancestor constant not in the group (se_constant.php leaves them when deleting a parent) */
    public const DANGLING = 'dangling-ref';
    /** E: the alerts collection reads a slice outside the package. Also E (ReferenceLint codes): an
     *  alerts_filter.vid or alerts_collection.emailid_* outside the package (0 / empty = none, allowed) - the
     *  new collection would mail a view or template of the target to readers the package brings */
    public const READER_OUTSIDE = ExportLint::READER_OUTSIDE;
    /** E: an object class off the allowlist, a property its class lacks, a subobject off its property, a malformed object */
    public const OBJECT = 'object';
    /** W: ga_id, alternate_url, app_* of the site settings - the importer drops them */
    public const SETTINGS_DROPPED = 'settings-dropped';
    /** E: sitetree::fromArray() refused the spot tree; ref = its message */
    public const SPOT_TREE = Collector::SPOT_TREE;
    /** W: site_spot of a spot the tree lacks - a new spot could get its id and content on the target */
    public const SPOT_UNUSED = Collector::SPOT_UNUSED;
    /** E: module.lang_file not in Langs::getFiles(<type>) */
    public const LANG_FILE = Collector::LANG_FILE;
    /** W: slice.lang_file (the old copy) non-empty and not of the list - the importer writes the module's lang_file instead */
    public const LANG_FILE_REPLACED = 'lang-file-replaced';
    /** E: no constant_group_id_valid() name (1-16 BYTES, IdMap::GROUP_RE), spaces around it (AA trims the
     *  name where it reads it - such a group cannot be addressed), or a core group (never mapped - the import would fill the target's) */
    public const GROUP_NAME = 'group-name';
    /** W: a field widget names a group neither in the package nor a core group */
    public const GROUP_MISSING = Collector::GROUP_MISSING;
    /** E: site.state_file names a control file (flag ≠ 1, stateFileName() ≠ '') - site.php would include it */
    public const STATE_FILE = Collector::STATE_FILE;
    /** E: items without meta/contents/@items, or items in a module other than a slice */
    public const ITEMS = 'items';
    /** E: items (or the item flag) with meta/publish = all - the public catalogue carries no data */
    public const ITEMS_PUBLISHED = Collector::ITEMS_PUBLISHED;
    /** E: an item value of a pseudo field or of source state (Collector::ITEM_LEFT_OUT) */
    public const ITEM_KEY = 'item-key';
    /** W: an item value of a field the slice does not have */
    public const ITEM_FIELD = 'item-field';
    /** W: an item without a status_code of a bin (1 active, 2 holding, 3 trash) - ItemWriter writes it as it
     *  is (a missing one: the column default 0), and no bin shows it; ref = the value ('' = none) */
    public const ITEM_STATUS = 'item-status';
    /** W: a default profile property AA does not know (admin/se_profile.php) - ignored by AA */
    public const PROFILE = 'profile-property';
    /** W: a switch who may write or log in - slice.permit_anonymous_post/_edit, fileman_access, auth_field_group */
    public const PERMISSION = 'permission';
    /** W: site perm_mode not 1/2 - AA + all Reader Management slices of the target may log in */
    public const PERM_MODE = 'perm-mode';
    /** E: an integer out of the range of its column (strict sql_mode would fail the insert) */
    public const INT_RANGE = 'int-range';

    /** keyed rows => [id pool (`module` = per module), the key's shape]; long ids share the pool `long` */
    private const UNIQUE = ['field' => ['module', '/^[A-Za-z0-9_.]{16}\z/'], 'view' => ['view', self::NUMBER_RE], 'email' => ['email', self::NUMBER_RE],
        'site_spot' => ['module', self::NUMBER_RE], 'uses' => ['module', ''], 'alerts_collection' => ['collection', '/^[A-Za-z0-9]{5}\z/'],
        'alerts_filter' => ['module', self::NUMBER_RE], 'constant' => ['long', self::LONG_RE]];

    private const LONG_RE   = '/^[0-9a-f]{32}\z/';
    private const NUMBER_RE = '/^[1-9][0-9]{0,9}\z/';

    /** properties of admin/se_profile.php $PROPERTY_TYPES */
    private const PROFILE_PROPERTIES = ['listlen', 'input_view', 'admin_search', 'admin_order', 'admin_perm', 'hide', 'show', 'hide&fill',
        'fill', 'predefine', 'bookmark', 'ui_manager', 'ui_manager_hide', 'ui_inputform', 'ui_inputform_hide'];

    private const PERMISSIONS = ['permit_anonymous_post', 'permit_anonymous_edit', 'fileman_access', 'auth_field_group'];

    /** a packed id, any case (pack_id() takes any) */
    private const LONG_ID_RE = '/^[0-9a-f]{32}\z/i';
    /** packed columns holding several ids joined (AA_Metabase::getPacked(): "ancestors are multiple") */
    private const PACKED_LISTS = ['constant.ancestors' => true];
    private const LONG_IDS_RE  = '/^(?:[0-9a-f]{32})+\z/i';

    private readonly AA_Metabase $metabase;
    private readonly Schema $schema;
    private readonly SecurityLint $lint;
    private FindingList $findings;
    /** @var array<string, array<string, true>> pool => ids seen */
    private array $seen = [];
    /** @var array<string, string> module id => type */
    private array $types = [];
    /** @var array<string, array<int|string, mixed>> 'view' | 'email' | 'group' => ids in the package; 'filter' => id => row */
    private array $has = [];

    public function __construct(?AA_Metabase $metabase = null)
    {
        $this->metabase = $metabase ?? AA_Metabase::singleton();
        $this->schema   = new Schema($this->metabase);
        $this->lint     = new SecurityLint($this->schema);
    }

    public function validate(Package $package): ValidationResult
    {
        $this->findings = new FindingList();
        $this->seen     = $this->types = [];
        $this->has      = ['view' => [], 'email' => [], 'group' => [], 'filter' => []];
        foreach ($package->modules as $m) {
            $this->types[$m->id] ??= $m->type;
            foreach (['view', 'email'] as $element) {
                foreach ($m->rows[$element] ?? [] as $row) {
                    $this->has[$element][ValueCodec::bytes($row['id'] ?? '')] = true;
                }
            }
        }
        foreach ($package->constantGroups as $group) {
            $this->has['group'][$group->name] = true;
        }
        foreach ($package->modules as $m) {
            $this->module($m, $package->withItems);
        }
        foreach ($package->constantGroups as $group) {
            $this->group($group);
        }
        if (($package->publish === 'all') && ($package->withItems || array_filter($package->modules, static fn(Module $m): bool => $m->items !== []))) {
            $this->add('meta/publish', LintFinding::ERROR, self::ITEMS_PUBLISHED, 'all');
        }
        return new ValidationResult($this->lint->package($package, $this->findings));
    }

    private function module(Module $m, bool $with_items): void
    {
        $path = "$m->type[$m->id]";
        $this->id('long', $m->id, $path, self::LONG_RE);
        if (isset(Schema::SETTINGS_CLASSES[$m->type]) && preg_match(self::LONG_RE, $m->id)) {    // the settings object id shares the long id pool
            $this->id('long', string2id(Schema::SETTINGS_CLASSES[$m->type] . $m->id), "$path/settings");
        }
        $this->rows($path, $m->type, $m->rows);
        $objects = isset(Schema::SETTINGS_CLASSES[$m->type]);
        foreach (['settings' => $objects || ($m->settings === null), 'object' => $objects || !$m->objects,
            'spot-tree' => ($m->type === Module::SITE) || ($m->spotTree === null), 'item' => ($m->type === Module::SLICE) || !$m->items] as $what => $ok) {
            if (!$ok) {
                $this->add("$path/$what", LintFinding::ERROR, self::TABLE, $what);
            }
        }
        if ($m->items && !$with_items) {
            $this->add("$path/item", LintFinding::ERROR, self::ITEMS, (string) count($m->items));
        }
        if ($objects) {
            $this->objects($m, $path);
        }
        if (!ModuleCharset::accepts($m->type, $file = ValueCodec::bytes($m->rows['module'][0]['lang_file'] ?? ''))) {
            $this->add("$path/module[#0]/lang_file", LintFinding::ERROR, self::LANG_FILE, $file);
        }
        match ($m->type) {
            Module::SITE   => $this->site($m, $path),
            Module::SLICE  => $this->slice($m, $path),
            Module::ALERTS => $this->alerts($m, $path),
            default        => null,
        };
    }

    /** rows of a container: allowed tables and their counts, integer ranges, unique keys
     *  @param array<string, list<array<string, mixed>>> $elements */
    private function rows(string $path, string $container, array $elements): void
    {
        $tables = Schema::rowCounts($container);
        foreach (array_keys($tables + $elements) as $element) {
            $need = $tables[$element] ?? null;
            if ($need === null) {
                $this->add("$path/$element", LintFinding::ERROR, self::TABLE, (string) $element);
                continue;
            }
            $count = count($elements[$element] ?? []);
            if ((($need === '1') && ($count !== 1)) || (($need === '?') && ($count > 1))) {
                $this->add("$path/$element", LintFinding::ERROR, self::MODULE_ROWS, (string) $count);
            }
            $key  = isset(self::UNIQUE[$element]) ? array_key_first($this->schema->rowAttributes($element)) : null;
            $ints = array_diff_key($this->schema->intRanges($element), [(string) $key => 1]);     // a key: its shape (id())
            foreach ($elements[$element] ?? [] as $i => $row) {
                $at = $this->schema->rowPath($path, $element, $row, $i);
                foreach (array_intersect_key($row, $ints) as $column => $value) {
                    $int = is_int($value) ? (string) $value : $value;
                    if (is_string($int) && ($int !== '') && (((string) (int) $int !== $int) || ((int) $int < $ints[$column][0]) || ((int) $int > $ints[$column][1]))) {
                        $this->add("$at/$column", LintFinding::ERROR, self::INT_RANGE, $int);
                    }
                }
                if ($key !== null) {
                    [$pool, $re] = self::UNIQUE[$element];
                    $this->id(($pool === 'module') ? "$path/$element" : $pool, ValueCodec::bytes($row[$key] ?? ''), $at, $re);
                }
                $this->packed($element, $row, (string) $key, $at);
            }
        }
    }

    /** E: a packed column (AA_Metabase::getPacked()) with a value that is no long id - Cvariable writes '' for
     *  it (include/varset.php); constant.ancestors holds several joined. '' and NULL are fine. The key column
     *  is id()'s (uses: DANGLING). */
    private function packed(string $element, array $row, string $key, string $at): void
    {
        $table = $this->schema->rowTables()[$element] ?? '';
        foreach (array_diff(AA_Metabase::getPacked($table), [$key]) as $column) {
            $value = ValueCodec::bytes($row[$column] ?? '');
            if (($value !== '') && !preg_match((isset(self::PACKED_LISTS["$table.$column"]) ? self::LONG_IDS_RE : self::LONG_ID_RE), $value)) {
                $this->add("$at/$column", LintFinding::ERROR, self::INVALID_ID, $value);
            }
        }
    }

    /** the id is of its shape and new in its pool */
    private function id(string $pool, string $id, string $path, string $re = ''): void
    {
        if (($re !== '') && !preg_match($re, $id)) {
            $this->add($path, LintFinding::ERROR, self::INVALID_ID, $id);
        } elseif (isset($this->seen[$pool][$id])) {
            $this->add($path, LintFinding::ERROR, self::DUPLICATE, $id);
        }
        $this->seen[$pool][$id] = true;
    }

    /** settings and objects: allowlisted classes, module ids of their properties */
    private function objects(Module $m, string $path): void
    {
        if ($m->settings !== null) {
            $this->props(Schema::SETTINGS_CLASSES[$m->type], $m->settings, "$path/settings", false);
        }
        foreach ($m->objects as $object) {
            $at = "$path/object[" . ValueCodec::bytes($object['id'] ?? '') . ']';
            $this->id('long', ValueCodec::bytes($object['id'] ?? ''), $at, self::LONG_RE);
            if (in_array($object['class'] ?? '', Schema::TOP_CLASSES, true)) {
                $this->props($object['class'], (array) ($object['props'] ?? []), $at, true);
            } else {
                $this->add($at, LintFinding::ERROR, self::OBJECT, ValueCodec::bytes($object['class'] ?? ''));
            }
        }
        try {
            $values = ObjectExport::values($m->type, $m->settings, $m->objects, $path);
        } catch (Throwable $e) {
            $this->add("$path/object", LintFinding::ERROR, self::OBJECT, 'object cannot be read (' . $e::class . ')');
            return;
        }
        foreach ($values as [$at, $class, $name, $value]) {
            if (in_array($name, ObjectExport::MODULE_PROPS[$class] ?? [], true)) {
                $this->moduleRef(ValueCodec::bytes($value), $at);
            }
        }
    }

    /** the properties of an allowlisted class: its own (getClassProperties()), a subobject of the property's
     *  class only where the property holds one (AA_Set.conds, .sort), nothing nested deeper
     *  @param class-string $class @param array<string, mixed> $props */
    private function props(string $class, array $props, string $path, bool $top): void
    {
        /** @var array<string, AA_Property> $properties */
        $properties = $class::getClassProperties();
        foreach ($props as $name => $values) {
            $property = $properties[$name] ?? null;
            $list     = is_array($values) ? $values : [$values];
            if (ObjectExport::isLeftOut($class, (string) $name)) {
                $this->add("$path/$name", LintFinding::WARNING, self::SETTINGS_DROPPED, (string) $name);
                continue;
            }
            if (($property === null) || (!$property->isMulti() && (array_keys($list) !== [0]))) {
                $this->add("$path/$name", LintFinding::ERROR, self::OBJECT, (string) $name);
                continue;
            }
            $sub_class = ($top && $property->isObject() && in_array($property->getType(), Schema::SUB_CLASSES, true)) ? $property->getType() : null;
            foreach ($list as $i => $value) {
                $at = "$path/{$name}[$i]";
                if (is_array($value) !== ($sub_class !== null) || (is_array($value) && (($value['class'] ?? '') !== $sub_class))) {
                    $this->add($at, LintFinding::ERROR, self::OBJECT, is_array($value) ? ValueCodec::bytes($value['class'] ?? '') : (string) $name);
                } elseif (is_array($value)) {
                    $this->id('long', ValueCodec::bytes($value['id'] ?? ''), $at, self::LONG_RE);
                    $this->props($sub_class, (array) ($value['props'] ?? []), $at, false);
                }
            }
        }
    }

    private function site(Module $m, string $path): void
    {
        $site = $m->rows['site'][0] ?? [];
        if (Schema::controlFile($site)) {
            $this->add("$path/site[#0]/state_file", LintFinding::ERROR, self::STATE_FILE, ValueCodec::bytes($site['state_file']));
        }
        $mode = ValueCodec::bytes($m->settings['perm_mode'] ?? '');
        if (!in_array($mode, ['1', '2'], true)) {
            $this->add("$path/settings/perm_mode", LintFinding::WARNING, self::PERM_MODE, $mode);
        }
        foreach ($m->rows['uses'] ?? [] as $i => $row) {
            if (!isset($this->types[ValueCodec::bytes($row['destination_id'] ?? '')])) {
                $this->add($this->schema->rowPath($path, 'uses', $row, $i), LintFinding::ERROR, self::DANGLING, ValueCodec::bytes($row['destination_id'] ?? ''));
            }
        }
        if ($m->spotTree === null) {
            return;
        }
        if (!class_exists(\sitetree::class)) {      // AA's autoloader has it (include/locsess.php), composer's not
            require_once __DIR__ . '/../../../modules/site/sitetree.php';
        }
        try {
            \sitetree::fromArray($m->spotTree);
        } catch (InvalidArgumentException $e) {       // its own message: what makes it no tree
            $this->add("$path/spot-tree", LintFinding::ERROR, self::SPOT_TREE, $e->getMessage());
            return;
        } catch (Throwable $e) {
            $this->add("$path/spot-tree", LintFinding::ERROR, self::SPOT_TREE, 'spot tree cannot be read (' . $e::class . ')');
            return;
        }
        $spots = array_flip(array_map('strval', array_column($m->spotTree['spots'], 'id')));
        foreach ($m->rows['site_spot'] ?? [] as $i => $row) {
            if (!isset($spots[ValueCodec::bytes($row['spot_id'] ?? '')])) {
                $this->add($this->schema->rowPath($path, 'site_spot', $row, $i), LintFinding::WARNING, self::SPOT_UNUSED, ValueCodec::bytes($row['spot_id'] ?? ''));
            }
        }
    }

    private function slice(Module $m, string $path): void
    {
        $slice = $m->rows['slice'][0] ?? [];
        $this->viewRef($slice['vid'] ?? '', "$path/slice[#0]/vid");
        $this->moduleRef(ValueCodec::bytes($slice['mlxctrl'] ?? ''), "$path/slice[#0]/mlxctrl");
        if ((($file = ValueCodec::bytes($slice['lang_file'] ?? '')) !== '') && !ModuleCharset::accepts($m->type, $file)) {   // the old copy, '' = module's
            $this->add("$path/slice[#0]/lang_file", LintFinding::WARNING, self::LANG_FILE_REPLACED, $file);
        }
        foreach (self::PERMISSIONS as $column) {
            if (!in_array(ValueCodec::bytes($slice[$column] ?? ''), ['', '0'], true)) {
                $this->add("$path/slice[#0]/$column", LintFinding::WARNING, self::PERMISSION, ValueCodec::bytes($slice[$column]));
            }
        }
        foreach ($m->rows['profile'] ?? [] as $i => $row) {
            $property = ValueCodec::bytes($row['property'] ?? '');
            if (!in_array($property, self::PROFILE_PROPERTIES, true)) {
                $this->add("$path/profile[#$i]/property", LintFinding::WARNING, self::PROFILE, $property);
            } elseif ($property === 'input_view') {
                $this->viewRef($row['value'] ?? '', "$path/profile[#$i]/value");
            }
        }
        $fields = [];
        foreach ($m->rows['field'] ?? [] as $i => $row) {
            $fields[ValueCodec::bytes($row['id'] ?? '')] = true;
            $group = ReferenceRewriter::inputShowFuncGroup(ValueCodec::bytes($row['input_show_func'] ?? ''));
            if (($group !== null) && IdMap::isAppGroup($group) && !isset($this->has['group'][$group])) {
                $this->add($this->schema->rowPath($path, 'field', $row, $i) . '/input_show_func', LintFinding::WARNING, self::GROUP_MISSING, $group);
            }
        }
        foreach ($m->items as $item) {
            $id = ValueCodec::bytes($item['id'] ?? '');
            $this->id('long', $id, "$path/item[$id]", self::LONG_RE);
            $status = ValueCodec::bytes(((array) (array_values((array) ($item['content']['status_code.....'] ?? []))[0] ?? []))['value'] ?? '');
            if (!in_array($status, ['1', '2', '3'], true)) {
                $this->add("$path/item[$id]/status_code.....", LintFinding::WARNING, self::ITEM_STATUS, $status);
            }
            foreach (array_keys((array) ($item['content'] ?? [])) as $field) {
                if (in_array((string) $field, Collector::ITEM_LEFT_OUT, true)) {
                    $this->add("$path/item[$id]/$field", LintFinding::ERROR, self::ITEM_KEY, (string) $field);
                } elseif (!isset($fields[$field]) && !$this->metabase->isColumn('item', rtrim((string) $field, '.'))) {
                    $this->add("$path/item[$id]/$field", LintFinding::WARNING, self::ITEM_FIELD, (string) $field);
                }
            }
        }
    }

    private function alerts(Module $m, string $path): void
    {
        $collection = $m->rows['alerts_collection'][0] ?? null;
        $filters    = [];
        foreach ($m->rows['alerts_filter'] ?? [] as $i => $row) {
            $id = ValueCodec::bytes($row['id'] ?? '');
            $at = $this->schema->rowPath($path, 'alerts_filter', $row, $i);
            if (isset($this->has['filter'][$id]) && ($this->has['filter'][$id] != $row) && !isset($filters[$id])) {
                $this->add($at, LintFinding::ERROR, self::DUPLICATE, $id);       // shared by Alerts modules only as the same row
            }
            $this->has['filter'][$id] ??= $row;
            $filters[$id] = true;
            $this->viewRef($row['vid'] ?? '', "$at/vid", LintFinding::ERROR);
        }
        foreach (['alerts_collection_filter', 'alerts_collection_howoften'] as $element) {
            foreach ($m->rows[$element] ?? [] as $i => $row) {
                if (($collection === null) || (($row['collectionid'] ?? '') !== ($collection['id'] ?? ''))
                    || (($element === 'alerts_collection_filter') && !isset($filters[ValueCodec::bytes($row['filterid'] ?? '')]))) {
                    $this->add($this->schema->rowPath($path, $element, $row, $i), LintFinding::ERROR, self::DANGLING, ValueCodec::bytes($row['collectionid'] ?? ''));
                }
            }
        }
        if ($collection === null) {
            $this->add("$path/alerts_collection", LintFinding::WARNING, self::MODULE_ROWS, '0');
            return;
        }
        $at     = $this->schema->rowPath($path, 'alerts_collection', $collection, 0);
        $reader = ValueCodec::bytes($collection['slice_id'] ?? '');
        if (($reader !== '') && (($this->types[$reader] ?? '') !== Module::SLICE)) {
            $this->add("$at/slice_id", LintFinding::ERROR, self::READER_OUTSIDE, $reader);
        }
        foreach (['emailid_welcome', 'emailid_alert'] as $column) {
            $email = (int) ValueCodec::bytes($collection[$column] ?? '');
            if (($email > 0) && !isset($this->has['email'][(string) $email])) {
                $this->add("$at/$column", LintFinding::ERROR, ReferenceLint::EMAIL_OUTSIDE, (string) $email);
            }
        }
    }

    private function group(ConstantGroup $g): void
    {
        $path = "constant-group[$g->name]";
        if (!preg_match(IdMap::GROUP_RE, $g->name) || in_array($g->name, IdMap::CORE_GROUPS, true) || (trim($g->name) !== $g->name)) {
            $this->add($path, LintFinding::ERROR, self::GROUP_NAME, $g->name);
        }
        $this->id('group', $g->name, $path);
        if (($g->owner !== '') && (($this->types[$g->owner] ?? '') !== Module::SLICE)) {
            $this->add("$path@owner", LintFinding::ERROR, self::DANGLING, $g->owner);
        }
        $this->rows($path, 'constant-group', $g->rows);
        $ids = array_flip(array_map([ValueCodec::class, 'bytes'], array_column($g->rows['constant'] ?? [], 'id')));
        foreach ($g->rows['constant'] ?? [] as $i => $row) {
            foreach (str_split(ValueCodec::bytes($row['ancestors'] ?? ''), 32) as $ancestor) {
                if (!isset($ids[$ancestor])) {
                    $this->add($this->schema->rowPath($path, 'constant', $row, $i) . '/ancestors', LintFinding::WARNING, self::DANGLING, $ancestor);
                    break;
                }
            }
        }
    }

    /** a structured view reference (slice.vid, profile input_view: W; alerts_filter.vid: E) outside the package */
    private function viewRef(mixed $vid, string $at, string $level = LintFinding::WARNING): void
    {
        $vid = (int) ValueCodec::bytes($vid);
        if (($vid > 0) && !isset($this->has['view'][(string) $vid])) {
            $this->add($at, $level, ReferenceLint::VIEW_OUTSIDE, (string) $vid);
        }
    }

    /** W: a module id in a column or property that is no module of the package */
    private function moduleRef(string $id, string $at): void
    {
        if (preg_match('/^[0-9a-f]{32}\z/i', $id) && !isset($this->types[strtolower($id)])) {
            $this->add($at, LintFinding::WARNING, ReferenceLint::MODULE_OUTSIDE, $id);
        }
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings->add($path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0));
    }
}
