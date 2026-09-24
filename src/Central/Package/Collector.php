<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
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
 *  Not yet: objects and the spot tree (C4.3 part 4); the export option "unused groups too" (§ 5.3).
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
    /** E: this iconv converts windows-1250 wrongly - no text can be trusted */
    public const CHARSET_BROKEN = 'charset-broken';
    /** E: the slice / site row of a module is missing */
    public const ROW_MISSING = 'row-missing';
    /** W: a field widget names a constant group that does not exist */
    public const GROUP_MISSING = 'group-missing';
    /** E: items of a ReaderManagement slice (readers' logins and e-mails) never travel */
    public const READER_ITEMS = 'reader-items';
    /** E: a published app carries no items */
    public const ITEMS_PUBLISHED = 'items-published';
    /** E: more items than CollectOptions::$maxItems - the rest is left out */
    public const ITEMS_LIMIT = 'items-limit';

    private const TYPES = ['W' => Module::SITE, 'S' => Module::SLICE, 'Alerts' => Module::ALERTS];

    /** the one row a module of the type must have */
    private const ROW = ['W' => 'site', 'S' => 'slice'];

    /** GetItemContent() pseudo fields and the item columns that are state of the source AA - users,
     *  counters, feeding (§ 6.2; the id is the attribute) */
    private const ITEM_LEFT_OUT = [
        'u_slice_id......', 'unpacked_id.....', 'id..............', 'short_id........', 'slice_id........', 'posted_by.......',
        'edited_by.......', 'externally_fed..', 'disc_count......', 'disc_app........', 'display_count...',
    ];

    private readonly Schema $schema;
    private readonly ExportLint $lint;
    /** @var list<array{string, LintFinding}> */
    private array $findings = [];

    public function __construct(private readonly Source $source, ?Schema $schema = null)
    {
        $this->schema = $schema ?? new Schema();
        $this->lint   = new ExportLint($source, $this->schema);
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
        $with_items = $options->items && !$options->published();
        if ($options->items && $options->published()) {
            $this->add('meta/contents', LintFinding::ERROR, self::ITEMS_PUBLISHED, (string) $options->publish);
        }
        $first = (string) array_key_first($app);
        ksort($app, SORT_STRING);           // "the first slice using a group" does not depend on the order given
        $charsets = $raw = $rows = $items = [];
        $count    = 0;
        foreach ($app as $id => $module) {
            $path          = self::TYPES[$module['type']] . "[$id]";
            $charsets[$id] = $this->charset($module, "$path/module[#0]/lang_file");
            $raw[$id]      = ['module' => [$module]] + match ($module['type']) {
                'W'     => $this->siteRows($id, array_keys($app)),
                'S'     => $this->sliceRows($id),
                default => $this->alertsRows($id),
            };
            if (isset(self::ROW[$module['type']]) && !$raw[$id][self::ROW[$module['type']]]) {
                $this->add("$path/" . self::ROW[$module['type']], LintFinding::ERROR, self::ROW_MISSING, self::ROW[$module['type']]);
            }
            foreach (array_filter($raw[$id]) as $element => $list) {
                $rows[$id][$element] = $this->clean($element, $list, $charsets[$id], $path);
            }
            if ($with_items && ($module['type'] === 'S')) {
                $items[$id] = $this->items($id, $raw[$id], $charsets[$id], $path, $options, $count);
            }
        }
        [$groups, $owners] = $this->groups($raw, $app, $charsets);
        $types             = array_map(static fn(array $m): string => self::TYPES[$m['type']], $app);
        $item_ids          = array_merge([], ...array_values(array_map(static fn(array $list): array => array_column($list, 'id'), $items)));
        [$findings, $outside] = $this->lint->check($rows, $types, $groups, $item_ids);
        $findings = [...$this->findings, ...$findings];

        $modules = [];
        foreach ($rows as $id => $elements) {
            // TODO C4.3 part 4: objects (<settings>, AA_Aliasfunc, AA_Set) and the spot tree (sitetree::toArray())
            $modules[] = new Module($types[$id], $id, $elements, items: $items[$id] ?? []);
        }
        $package = new Package(
            $options->name ?? (string) ($rows[$first]['module'][0]['name'] ?? ''),
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
     *  AA and the values of password fields; none of a ReaderManagement slice; at most maxItems in all.
     *  @param array<string, list<array<string, mixed>>> $raw the slice's rows @return list<array<string, mixed>> */
    private function items(string $slice_id, array $raw, string $charset, string $path, CollectOptions $options, int &$count): array
    {
        if (($raw['slice'][0]['type'] ?? '') === 'ReaderManagement') {
            $this->add("$path/item", LintFinding::ERROR, self::READER_ITEMS, $slice_id);
            return [];
        }
        $secret = array_column(array_filter($raw['field'], static fn(array $f): bool => str_starts_with(strtolower(trim((string) ($f['input_insert_func'] ?? ''))), 'pwd')), 'id');
        $drop   = array_flip([...self::ITEM_LEFT_OUT, ...$secret]);
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
     *  @param array<string, string> $charsets
     *  @return array{list<ConstantGroup>, list<string>} the groups, the owners outside the app */
    private function groups(array $raw, array $app, array $charsets): array
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
            foreach ($this->lint->texts('', array_diff_key($elements, ['module' => 1])) as [, $text]) {
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
        $foreign   = array_values(array_diff(array_filter(array_map('strval', array_column($slices, 'slice_id'))), array_keys($app)));
        $modules   = $this->modules($foreign);
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
            $charset = $charsets[$owner] ?? ($owner === '' ? $charsets[$user] : $this->charset($modules[$owner] ?? ['lang_file' => '?'], $path, $charsets[$user]));
            $rows    = [];
            foreach (array_filter(['constant_slice' => isset($slices[$name]) ? [$slices[$name]] : [], 'constant' => $consts[$name] ?? []]) as $element => $list) {
                $rows[$element] = $this->clean($element, $list, $charset, $path);
            }
            // owned outside the app: the first app slice using it, else the first app slice (D3); unowned stays unowned
            $at       = isset($charsets[$owner]) ? $owner : (($owner === '') ? '' : (($app[$user]['type'] === 'S') ? $user : (string) $app_slice));
            $groups[] = new ConstantGroup($utf_name, $at, $rows);
        }
        return [$groups, $foreign];
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

    /** § 2.4 the value in UTF-8; a value the charset cannot hold stays raw, with a finding */
    private function utf8(string $value, string $charset, string $path): string
    {
        [$utf, $finding] = ModuleCharset::toUtf8($value, $charset);
        if ($finding !== null) {
            $this->findings[] = [$path, $finding];
        }
        return $utf;
    }

    /** the charset of the module; lang_file off the allowlist is an error at $path, $fallback is used
     *  @param array<string, mixed> $module module row */
    private function charset(array $module, string $path, string $fallback = 'utf-8'): string
    {
        $charset = ModuleCharset::ofModule($module);
        if ($charset === null) {
            $this->add($path, LintFinding::ERROR, self::LANG_FILE, (string) ($module['lang_file'] ?? ''));
        }
        return $charset ?? $fallback;
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

    private static function id(string $id): string
    {
        return preg_match('/^[0-9a-f]{32}\z/i', $id) ? strtolower($id) : throw new InvalidArgumentException("Invalid module id $id");
    }
}
