<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use AA_Module_Site;
use InvalidArgumentException;

/** Collects an app from the source AA into the package model (design balicek-v1.md § 5, § 6; C4.3
 *  part 3): the rows of each module type scrubbed to the columns Schema carries, the constant groups
 *  the app uses, texts in UTF-8 (ModuleCharset, § 2.4), items on request and the export lint
 *  (ExportLint, § 8.4).
 *
 *  App boundary (§ 5.1, § 5.2): fromSite() = the site + its "uses modules" (relation with
 *  REL_FLAG_MODULE_DEPEND) of type S and Alerts; fromModules() = the given slices / alerts modules.
 *  Nothing else is pulled in: a module the app refers to from outside is a finding and lands in
 *  CollectResult::$outside - the author adds it to "uses" and exports again.
 *
 *  PERMISSIONS - the caller's job: the collector reads with no check. Before offering the package
 *  the caller must check PS_EDIT on EVERY module of CollectResult::$checkPermissions - the app
 *  modules, including the "uses" modules fromSite() resolved itself, and the owners of foreign
 *  constant groups. An anonymous download may only serve a package already published.
 *
 *  ITEMS (CollectOptions::$items) are for moving a site between AAs by its admins: the package may
 *  hold personal data and password hashes (readers of a ReaderManagement slice). The caller must not
 *  publish it - publish = all with items is an error, users (admins of the site, Q-H1) or no is fine.
 *
 *  Objects and the site tree (§ 5.4, § 6.3; C4.3 part 4): ObjectExport reads the settings and the
 *  allowlisted objects of a site / slice, site.structure becomes sitetree::toArray(); both in UTF-8.
 *
 *  Not yet: the export option "unused groups too" (§ 5.3).
 *  @throws RewriteFailed from both entry points - a regex of the lint failed: a failed export */
final class Collector
{
    /** W: a "uses" module of a type v1 does not carry - P, Links, J, W (§ 5.2, D1) */
    public const MODULE_TYPE = 'module-type';
    /** I: a "uses" module deleted or missing - AA ignores it too (AA_Module_Site::getRelatedModules()) */
    public const MODULE_MISSING = 'module-missing';
    /** W: site.state_file names a control file of the source installation - left out (§ 6.3) */
    public const STATE_FILE = 'state-file';
    /** E: module.lang_file not in Langs::getFiles(<type>) - the charset, so the conversion, is unknown (§ 2.4) */
    public const LANG_FILE = 'lang-file';
    /** I: lang_file off the list of its type, of a known language - the package carries the language's
     *  file of the type (the charset is the same: AA takes it from the language code) */
    public const LANG_FILE_NORMALISED = 'lang-file-normalised';
    /** W: the owner of a constant group (constant_slice.slice_id) is no module - its user's charset is used */
    public const OWNER_MISSING = 'owner-missing';
    /** E: this iconv converts windows-1250 wrongly - no text can be trusted */
    public const CHARSET_BROKEN = 'charset-broken';
    /** E: the slice / site row of a module is missing */
    public const ROW_MISSING = 'row-missing';
    /** W: a field widget names a constant group that does not exist */
    public const GROUP_MISSING = 'group-missing';
    /** E: a public app (publish = all, the catalogue) carries no items */
    public const ITEMS_PUBLISHED = 'items-published';
    /** E: more items than CollectOptions::$maxItems - the rest is left out */
    public const ITEMS_LIMIT = 'items-limit';
    /** E: site.structure is no site tree, or a text of it cannot be carried (charset, control character) */
    public const SPOT_TREE = 'spot-tree';
    /** I: site_spot rows of spots the tree no longer has - left out, ref = the count (on the target new_id()
     *  could give a new spot their id, so their content); a row {sitespot:N} includes stays */
    public const SPOT_UNUSED = 'spot-unused';

    private const TYPES = ['W' => Module::SITE, 'S' => Module::SLICE, 'Alerts' => Module::ALERTS];

    /** the one row a module of the type must have */
    private const ROW = ['W' => 'site', 'S' => 'slice'];

    /** GetItemContent() pseudo fields and the item columns that are state of the source AA - users,
     *  counters, feeding (§ 6.2; the id is the attribute) */
    public const ITEM_LEFT_OUT = [
        'u_slice_id......', 'unpacked_id.....', 'id..............', 'short_id........', 'slice_id........', 'posted_by.......',
        'edited_by.......', 'externally_fed..', 'disc_count......', 'disc_app........', 'display_count...',
    ];

    private readonly Schema $schema;
    private readonly ExportLint $lint;
    private readonly ObjectExport $objects;
    /** @var list<array{string, LintFinding}> */
    private array $findings = [];

    public function __construct(private readonly Source $source, ?Schema $schema = null)
    {
        $this->schema = $schema ?? new Schema();
        $this->lint    = new ExportLint($source, $this->schema);
        $this->objects = new ObjectExport($source);
    }

    /** The site and its "uses modules". The caller checks CollectResult::$checkPermissions (class doc).
     *  @throws InvalidArgumentException not a site, or a deleted one */
    public function fromSite(string $site_id, CollectOptions $options): CollectResult
    {
        $site_id = self::id($site_id);
        $app     = $this->modules([$site_id]);
        if ((($app[$site_id]['type'] ?? '') !== 'W') || $app[$site_id]['deleted']) {
            throw new InvalidArgumentException("Module $site_id is no site (or it is deleted)");
        }
        $this->findings = [];
        $uses           = array_column($this->source->select('relation', [['source_id', $site_id, 'l'], ['flag', REL_FLAG_MODULE_DEPEND, 'i']]), 'destination_id');
        $modules        = $this->modules($uses);
        foreach ($uses as $id) {
            $path = Module::SITE . "[$site_id]/uses[$id]";
            if (!isset($modules[$id]) || $modules[$id]['deleted']) {
                $this->add($path, LintFinding::INFO, self::MODULE_MISSING, $id);
            } elseif (in_array($modules[$id]['type'], ['S', 'Alerts'], true)) {
                $app[$id] = $modules[$id];
            } else {
                $this->add($path, LintFinding::WARNING, self::MODULE_TYPE, $id);
            }
        }
        return $this->collect($app, $site_id, $options);
    }

    /** An app without a site. The caller checks CollectResult::$checkPermissions (class doc).
     *  @param list<string> $module_ids slices and alerts modules; the first names the app
     *  @throws InvalidArgumentException another type, a deleted or unknown module, none */
    public function fromModules(array $module_ids, CollectOptions $options): CollectResult
    {
        $module_ids = array_map(self::id(...), $module_ids);
        $modules    = $this->modules($module_ids);
        $app        = [];
        foreach ($module_ids as $id) {
            $ok       = in_array($modules[$id]['type'] ?? '', ['S', 'Alerts'], true) && !$modules[$id]['deleted'];
            $app[$id] = $ok ? $modules[$id] : throw new InvalidArgumentException("Module $id is no slice or alerts module (or it is deleted)");
        }
        if (!$app) {
            throw new InvalidArgumentException('No module to export');
        }
        $this->findings = [];
        return $this->collect($app, null, $options);
    }

    /** @param array<string, array<string, mixed>> $app id => module row, the first names the app */
    private function collect(array $app, ?string $site_id, CollectOptions $options): CollectResult
    {
        if (!ModuleCharset::works()) {
            $this->add('meta', LintFinding::ERROR, self::CHARSET_BROKEN, 'iconv windows-1250');
        }
        $with_items = $options->items && !$options->public();
        if ($options->items && $options->public()) {
            $this->add('meta/contents', LintFinding::ERROR, self::ITEMS_PUBLISHED, (string) $options->publish);
        }
        $first = (string) array_key_first($app);
        ksort($app, SORT_STRING);           // "the first slice using a group" does not depend on the order given
        $charsets = $raw = $rows = $items = $objects = $trees = [];
        $count    = 0;
        foreach ($app as $id => $module) {
            $path          = self::TYPES[$module['type']] . "[$id]";
            [$charsets[$id], $module['lang_file']] = $this->charset($module, "$path/module[#0]/lang_file");
            $raw[$id] = ['module' => [$module]] + match ($module['type']) {
                'W'     => $this->siteRows($id, array_keys($app)),
                'S'     => $this->sliceRows($id),
                default => $this->alertsRows($id),
            };
            if (isset(self::ROW[$module['type']]) && !$raw[$id][self::ROW[$module['type']]]) {
                $this->add("$path/" . self::ROW[$module['type']], LintFinding::ERROR, self::ROW_MISSING, self::ROW[$module['type']]);
            }
            foreach ($raw[$id]['slice'] ?? [] as $i => $slice) {     // the old copy of module.lang_file, normalised alike
                if (isset($slice['lang_file'])) {
                    $raw[$id]['slice'][$i]['lang_file'] = (ModuleCharset::ofModule(['type' => 'S'] + $slice) ?? [$slice['lang_file']])[0];
                }
            }
            foreach (array_filter($raw[$id]) as $element => $list) {
                $rows[$id][$element] = $this->clean($element, $list, $charsets[$id], $path);
            }
            if ($with_items && ($module['type'] === 'S')) {
                $items[$id] = $this->items($id, $charsets[$id], $path, $options, $count);
            }
            if (isset(Schema::SETTINGS_CLASSES[self::TYPES[$module['type']]])) {      // site, slice: alerts own none
                [$settings, $list, $findings] = $this->objects->collect($id, self::TYPES[$module['type']], $path);
                $objects[$id]                 = [$settings, $list];
                array_push($this->findings, ...$findings);
            }
            if ($module['type'] === 'W') {
                $structure  = (string) ($raw[$id]['site'][0]['structure'] ?? '');
                $trees[$id] = $this->spotTree($structure, $charsets[$id], $path);
                if ($trees[$id] !== null) {
                    $rows[$id]['site_spot'] = $this->usedSpots($rows[$id]['site_spot'] ?? [], $trees[$id], $path);
                }
            }
        }
        $types             = array_map(static fn(array $m): string => self::TYPES[$m['type']], $app);
        [$groups, $owners] = $this->groups($raw, $app, $charsets, $objects);   // object texts as stored, like the rows
        foreach ($objects as $id => [$settings, $list]) {      // a name that stays RawBytes is dropped (map())
            $utf8         = fn(mixed $v, string $at): mixed => is_string($v) ? $this->utf8($v, $charsets[$id], $at) : $v;
            $objects[$id] = ObjectExport::map($types[$id], $settings, $list, "$types[$id][$id]", $utf8);
        }
        $item_ids          = array_merge([], ...array_values(array_map(static fn(array $list): array => array_column($list, 'id'), $items)));
        [$findings, $outside] = $this->lint->check($rows, $types, $groups, $item_ids, $objects, array_filter($trees));
        $findings = [...$this->findings, ...$findings];

        $modules = [];
        foreach ($rows as $id => $elements) {
            [$settings, $list] = $objects[$id] ?? [null, []];
            $modules[]         = new Module($types[$id], $id, $elements, $settings, $list, $trees[$id] ?? null, $items[$id] ?? []);
        }
        $package = new Package(
            $options->name ?? self::text($rows[$first]['module'][0]['name'] ?? ''),
            $options->source + ($site_id === null ? [] : ['site' => $site_id]),
            $modules,
            $groups,
            $options->description,
            $options->version,
            $options->publish,
            $options->requires,
            $options->items,
        );
        $check = array_values(array_unique([...array_keys($app), ...$owners]));
        return new CollectResult($package, $options->published() ? ExportLint::published($findings) : $findings, $outside, $check);
    }

    /** @param list<string> $app_ids @return array<string, list<array<string, mixed>>> */
    private function siteRows(string $id, array $app_ids): array
    {
        $site = $this->source->select('site', [['id', $id, 'l']]);
        foreach ($site as $i => $row) {
            // a control file of the source installation, included by site.php - never code of the app (§ 6.3)
            if (((int) ($row['flag'] ?? 0) !== 1) && (AA_Module_Site::stateFileName((string) $row['state_file']) !== '')) {
                unset($site[$i]['state_file']);
                $this->add(Module::SITE . "[$id]/site[#$i]/state_file", LintFinding::WARNING, self::STATE_FILE, (string) $row['state_file']);
            }
        }
        return [
            'site'      => $site,
            'site_spot' => $this->source->select('site_spot', [['site_id', $id, 'l']]),
            'uses'      => array_map(static fn(string $m): array => ['destination_id' => $m], array_values(array_diff($app_ids, [$id]))),
        ];
    }

    /** § 6.3 site.structure as sitetree::toArray() with its texts in UTF-8; null = no structure - AA
     *  builds the tree from spot 1 then (AA_Module_Site::getTree()), and so will the imported site.
     *  Unserialized as AA does (allowed_classes sitetree, spot); fromArray() checks it is a tree. */
    private function spotTree(string $structure, string $charset, string $path): ?array
    {
        if (trim($structure) === '') {
            return null;
        }
        if (!class_exists(\sitetree::class)) {      // AA's autoloader has it (include/locsess.php), composer's not
            require_once __DIR__ . '/../../../modules/site/sitetree.php';
        }
        try {
            $tree = @unserialize($structure, ['allowed_classes' => ['sitetree', 'spot']]);
            $tree = ($tree instanceof \sitetree) ? \sitetree::fromArray($tree->toArray())->toArray() : null;
        } catch (\Throwable) {
            $tree = null;
        }
        $bad  = ($tree === null) ? 'structure' : null;
        $text = static function (string $value) use ($charset, &$bad): string {
            [$utf, $finding] = ModuleCharset::toUtf8($value, $charset);
            if (($finding !== null) || !ValueCodec::isPlain($utf)) {
                $bad ??= bin2hex(substr($value, 0, 64));      // the bytes as they are - not text for a report
            }
            return $utf;
        };
        foreach ($tree['spots'] ?? [] as $i => $spot) {
            $tree['spots'][$i]['n'] = $text($spot['n']);
            foreach (['v', 'c'] as $key) {
                if ($spot[$key] !== null) {
                    $keys                    = array_map(static fn($k): string => $text((string) $k), array_keys($spot[$key]));
                    $tree['spots'][$i][$key] = array_combine($keys, array_map($text, $spot[$key]));
                }
            }
        }
        if ($bad !== null) {
            $this->add("$path/spot-tree", LintFinding::ERROR, self::SPOT_TREE, $bad);
            return null;
        }
        return $tree;
    }

    /** the site_spot rows of the tree's spots and of the spots {sitespot:N} includes
     *  @param list<array<string, mixed>> $rows @param array<string, mixed> $tree @return list<array<string, mixed>> */
    private function usedSpots(array $rows, array $tree, string $path): array
    {
        $used = array_flip(array_column($tree['spots'], 'id'));
        foreach ($rows as $row) {
            $content = ($row['content'] ?? '') instanceof RawBytes ? $row['content']->bytes : (string) ($row['content'] ?? '');
            RewriteFailed::check(preg_match_all('/\{sitespot:\s*(\d+)\s*\}/i', $content, $m));
            $used += array_flip($m[1]);
        }
        $kept = array_values(array_filter($rows, static fn(array $row): bool => isset($used[(string) $row['spot_id']])));
        if (($left_out = count($rows) - count($kept)) > 0) {
            $this->add("$path/site_spot", LintFinding::INFO, self::SPOT_UNUSED, (string) $left_out);
        }
        return $kept;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function sliceRows(string $id): array
    {
        return [
            'slice'   => $this->source->select('slice', [['id', $id, 'l']]),
            'field'   => $this->source->select('field', [['slice_id', $id, 'l']]),
            'view'    => $this->source->select('view', [['slice_id', $id, 'l']]),
            'email'   => $this->source->select('email', [['owner_module_id', $id, 'l']]),
            'profile' => $this->source->select('profile', [['slice_id', $id, 'l'], ['uid', '*', 's']]),   // the default profile only (§ 6.2)
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function alertsRows(string $id): array
    {
        $collections = $this->source->select('alerts_collection', [['module_id', $id, 'l']]);
        $cids        = array_column($collections, 'id');
        $filters     = $cids ? $this->source->select('alerts_collection_filter', [['collectionid', $cids, 's']]) : [];
        return [
            'email'                      => $this->source->select('email', [['owner_module_id', $id, 'l']]),
            'alerts_collection'          => $collections,
            'alerts_collection_filter'   => $filters,
            'alerts_collection_howoften' => $cids ? $this->source->select('alerts_collection_howoften', [['collectionid', $cids, 's']]) : [],
            'alerts_filter'              => $filters ? $this->source->select('alerts_filter', [['id', array_values(array_unique(array_column($filters, 'filterid'))), 'i']]) : [],
        ];
    }

    /** Items {id, content}, values in UTF-8, NULL as '' (Model\Module), without the state of the source
     *  AA (users, counters, feeding); at most maxItems in all. Every slice alike - ReaderManagement
     *  too, password hashes included: a site moved to another AA keeps its readers able to log in.
     *  @return list<array<string, mixed>> */
    private function items(string $slice_id, string $charset, string $path, CollectOptions $options, int &$count): array
    {
        $drop   = array_flip(self::ITEM_LEFT_OUT);
        $items  = [];
        foreach ($this->source->items($slice_id, $options->allBins) as $item) {
            if ($count++ >= $options->maxItems) {
                $this->add("$path/item", LintFinding::ERROR, self::ITEMS_LIMIT, (string) $options->maxItems);
                break;
            }
            $content = array_diff_key($item['content'], $drop);
            foreach ($content as $field => $values) {
                foreach ($values as $i => $v) {
                    $value                        = $v['value'] ?? '';
                    $content[$field][$i]['value'] = is_string($value) ? $this->utf8($value, $charset, "$path/item[{$item['id']}]/{$field}[$i]") : $value;
                }
            }
            $items[] = ['id' => $item['id'], 'content' => $content];
        }
        return $items;
    }

    /** § 5.3: the groups of field widgets and of {constant(s)/options:<g>} in the texts, looked up as
     *  stored - in the charset of the module that names them. Core groups and `#sLiCe-` (items of a
     *  slice) are none. slice.auth_field_group / mailman_field_lists name FIELDS (include/auth.php:81,
     *  include/mailman.php:53) - their groups come with the field.
     *  @param array<string, array<string, list<array<string, mixed>>>> $raw  @param array<string, array<string, mixed>> $app
     *  @param array<string, string> $charsets @param array<string, array{?array<string, mixed>, list<array<string, mixed>>}> $objects as stored
     *  @return array{list<ConstantGroup>, list<string>} the groups, the owners outside the app */
    private function groups(array $raw, array $app, array $charsets, array $objects): array
    {
        $used = $widgets = [];     // raw name => the first app slice naming it, else the first module; => field paths
        foreach ($raw as $id => $elements) {
            $path  = self::TYPES[$app[$id]['type']] . "[$id]";
            $names = [];
            foreach ($elements['field'] ?? [] as $i => $field) {
                if (($name = ReferenceRewriter::inputShowFuncGroup((string) ($field['input_show_func'] ?? ''))) !== null) {
                    $names[]          = $name;
                    $widgets[$name][] = $this->lint->rowPath($path, 'field', $field, $i) . '/input_show_func';
                }
            }
            $texts = iterator_to_array($this->lint->texts('', array_diff_key($elements, ['module' => 1])), false);
            $values = isset($objects[$id]) ? ObjectExport::values(self::TYPES[$app[$id]['type']], $objects[$id][0], $objects[$id][1], '') : [];
            foreach ($values as [$at, $class, $property, $value]) {
                if (ObjectExport::isCode($class, $property) && is_string($value)) {
                    $texts[] = [$at, $value];
                }
            }
            foreach ($texts as [, $text]) {
                RewriteFailed::check(preg_match_all(ReferenceRewriter::GROUP_RE, $text, $m));
                array_push($names, ...array_map('trim', $m[0]));
            }
            foreach ($names as $name) {
                if (($name !== '') && preg_match(IdMap::GROUP_RE, $name) && !str_starts_with($name, '#sLiCe-') && ($name[0] !== '[') && !in_array($name, IdMap::CORE_GROUPS, true)
                    && (!isset($used[$name]) || (($app[$used[$name]]['type'] !== 'S') && ($app[$id]['type'] === 'S')))) {
                    $used[$name] = $id;
                }
            }
        }
        $names  = array_map('strval', array_keys($used));
        $slices = $names ? array_column($this->source->select('constant_slice', [['group_id', $names, 's']]), null, 'group_id') : [];
        $consts = [];
        foreach ($names ? $this->source->select('constant', [['group_id', $names, 's']]) : [] as $row) {
            $consts[$row['group_id']][] = $row;
        }
        $modules   = $this->modules(array_values(array_diff(array_filter(array_map('strval', array_column($slices, 'slice_id'))), array_keys($app))));
        $app_slice = array_key_first(array_filter($app, static fn(array $m): bool => $m['type'] === 'S'));
        $groups    = [];
        foreach ($used as $name => $user) {
            $name  = (string) $name;
            $owner = (string) ($slices[$name]['slice_id'] ?? '');
            if (!isset($slices[$name]) && !isset($consts[$name])) {
                foreach ($widgets[$name] ?? [] as $at) {    // a text reference is ReferenceLint's group-outside
                    $this->add($at, LintFinding::WARNING, self::GROUP_MISSING, $name);
                }
                continue;
            }
            [$utf_name, $bad] = ModuleCharset::toUtf8($name, $charsets[$user]);
            $path             = "constant-group[$utf_name]";
            if ($bad) {
                $this->findings[] = [$path, new LintFinding(LintFinding::ERROR, $bad->code, $bad->ref, $bad->offset)];
                continue;
            }
            // the charset of the owning slice (§ 2.4) - never a silent utf-8; an unowned group has its user's
            if (($owner !== '') && !isset($charsets[$owner]) && !isset($modules[$owner])) {
                $this->add($path, LintFinding::WARNING, self::OWNER_MISSING, $owner);
            }
            $charset = $charsets[$owner] ?? (isset($modules[$owner]) ? $this->charset($modules[$owner], $path, $charsets[$user])[0] : $charsets[$user]);
            $rows    = [];
            foreach (array_filter(['constant_slice' => isset($slices[$name]) ? [$slices[$name]] : [], 'constant' => $consts[$name] ?? []]) as $element => $list) {
                $rows[$element] = $this->clean($element, $list, $charset, $path);
            }
            // owned outside the app: the first app slice using it, else the first app slice (D3); unowned stays unowned
            $at       = isset($charsets[$owner]) ? $owner : (($owner === '') ? '' : (($app[$user]['type'] === 'S') ? $user : (string) $app_slice));
            $groups[] = new ConstantGroup($utf_name, $at, $rows);
        }
        return [$groups, array_keys($modules)];
    }

    /** Only the columns a package carries (Schema), strings in UTF-8.
     *  @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function clean(string $element, array $rows, string $charset, string $path): array
    {
        $carried = $this->schema->rowColumns($element) + $this->schema->rowAttributes($element);
        foreach ($rows as $i => $row) {
            $row = array_intersect_key($row, $carried);
            foreach ($row as $column => $value) {
                if (is_string($value)) {
                    $row[$column] = $this->utf8($value, $charset, $this->lint->rowPath($path, $element, $row, $i) . "/$column");
                }
            }
            $rows[$i] = $row;
        }
        return $rows;
    }

    /** § 2.4 the value in UTF-8; a value the charset cannot hold stays as the source bytes - RawBytes,
     *  which Writer carries as base64 - with a finding */
    private function utf8(string $value, string $charset, string $path): string|RawBytes
    {
        [$utf, $finding] = ModuleCharset::toUtf8($value, $charset);
        if ($finding !== null) {
            $this->findings[] = [$path, $finding];
            return new RawBytes($value);
        }
        return $utf;
    }

    /** The charset of the module and the lang_file the package carries (ModuleCharset::ofModule()):
     *  a normalised one is an I at $path, an unknown language an E - and $fallback is used.
     *  @param array<string, mixed> $module module row @return array{string, string} */
    private function charset(array $module, string $path, string $fallback = 'utf-8'): array
    {
        $file = (string) ($module['lang_file'] ?? '');
        [$lang_file, $charset] = ModuleCharset::ofModule($module) ?? [$file, null];
        if ($charset === null) {
            $this->add($path, LintFinding::ERROR, self::LANG_FILE, $file);
        } elseif ($lang_file !== preg_replace('/\.php3$/', '.php', $file)) {
            $this->add($path, LintFinding::INFO, self::LANG_FILE_NORMALISED, $file);
        }
        return [$charset ?? $fallback, $lang_file];
    }

    /** @param list<string> $ids @return array<string, array<string, mixed>> id => module row */
    private function modules(array $ids): array
    {
        return $ids ? array_column($this->source->select('module', [['id', array_values(array_unique($ids)), 'l']]), null, 'id') : [];
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings[] = [$path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0)];
    }

    /** a text for meta: RawBytes cut to valid UTF-8 (the name is shown, not imported) */
    private static function text(mixed $value): string
    {
        return ($value instanceof RawBytes) ? mb_scrub($value->bytes, 'UTF-8') : (string) $value;
    }

    private static function id(string $id): string
    {
        return preg_match('/^[0-9a-f]{32}\z/i', $id) ? strtolower($id) : throw new InvalidArgumentException("Invalid module id $id");
    }
}
