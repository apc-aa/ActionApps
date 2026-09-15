<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Util\Adminlink;
use AA_Items;
use AA_Module;
use AA_Module_Site;
use AA_Slice;
use zids;

/** A site module resolves a URL through {seo2ids}, which searches the SEO field
 *  across all slices related to the site, in the active, pending and expired
 *  bins. Two items sharing one SEO name inside one site therefore compete for
 *  the same URL - the page shows both, or the wrong one. This lists such
 *  collisions per site.
 */
class SeoDuplicates extends AbstractOptimize
{
    /** a field id is padded to 16 characters - kept in one place, the dots are not countable by eye */
    private const SEO_FIELD = 'seo.............';

    /** {seo2ids} asks for the ACTIVE|EXPIRED|PENDING bins, which CreateBinCondition()
     *  turns into status_code=1 - an item in the holding bin (2) or in the trash (3)
     *  never owns a URL, so it cannot collide with anything */
    private const ACTIVE = 1;

    /** report caps - a runaway import (thousands of items with one name) must not
     *  turn the report into thousands of AA_Items::getItem() calls */
    private const MAX_GROUPS = 100;  // duplicate names reported per site
    private const MAX_LINKS = 5;     // item links shown per name

    /** a GROUP BY over every item of the site (NSZM: 500k items, ~2.5 s), so not a basic check */
    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function name(): string
    {
        return _m("SEO name duplicates");
    }

    public static function description(): string
    {
        return _m("Lists items which share one SEO name inside a site module - such items compete for the same URL. Active, pending and expired items are compared; items waiting in the holding bin and in the trash own no URL, so they are not. Which item to rename is the editor's call, so there is no repair.");
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function test(): bool
    {
        $this->clear_report();
        $found = false;

        foreach ($this->scan() as $site_id => $site) {
            $site_link = AA_Slice::getModuleNameSafe($site_id, true);   // the same for every row of this site
            foreach ($site['rows'] as $row) {
                $found = true;
                $this->message([_m('Site') => $site_link] + $row);
            }
        }

        if ($found) {
            return false;
        }
        $this->message(_m('OK - no duplicate SEO names in any site module'));
        return true;
    }

    /** One finding per site: the editor of that site is the one who renames, so a
     *  resolve should hide that site and leave a collision elsewhere visible.
     * @return Finding[]
     */
    public function findings(): array
    {
        $findings = [];
        foreach ($this->scan() as $site_id => $site) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('%1: %2 SEO names are used by more than one item', [$site['name'], count($site['rows'])]),
                count: count($site['rows']),
                evidence: $site['rows'],
                advice: _m('The site resolves such a URL to whichever item the query returns first, so the visitor may land on the wrong one. Rename all but one of them.'),
                links: [$site['name'] => AA_Module_Site::getModuleUrl($site_id)],
                key: 'site:' . $site_id,
            );
        }
        if (!$findings) {
            $findings[] = new Finding(Finding::OK, _m('No duplicate SEO names in any site module'));
        }
        return $findings;
    }

    /** a collision is the editor's to resolve, not a broken install */
    public static function failSeverity(): string
    {
        return Finding::WARNING;
    }

    /** The duplicate SEO names of every site that has any. A slice pooled into two
     *  sites is scanned under both - each site resolves its own URLs, so the same
     *  pair of items really does collide twice.
     * @return array<string,array{name:string,rows:list<array<string,string>>}>
     */
    private function scan(): array
    {
        MoreTimeLimit();
        $sites = [];
        foreach (AA_Module::getModules('W', true) as $site_id => $site_name) {
            $slices = self::seoSlices(AA_Module_Site::getRelatedModules([$site_id], true));
            if ($slices && ($groups = DB_AA::select([], self::duplicatesSql($slices)))) {
                $sites[$site_id] = ['name' => (string) $site_name, 'rows' => self::evidence($groups)];
            }
        }
        return $sites;
    }

    /** slices (of the given modules) which have the SEO field at all - the rest cannot collide */
    private static function seoSlices(array $module_ids): array
    {
        if (!$module_ids) {
            return [];
        }
        return DB_AA::select('lid', 'SELECT LOWER(HEX(`slice_id`)) AS lid FROM `field`', [['slice_id', $module_ids, 'l'], ['id', self::SEO_FIELD]]);
    }

    /** one report row per duplicate name; the items are preloaded in a single query,
     *  because itemLink() loads each of them through AA_Items::getItem()
     * @return list<array<string,string>>
     */
    private static function evidence(array $groups): array
    {
        $shown = array_map(static fn(array $group): array => self::shownIds((string) $group['ids']), $groups);
        if ($ids = array_merge(...$shown)) {
            AA_Items::preload(new zids($ids, 'l'));
        }
        $rows = [];
        foreach ($groups as $i => $group) {
            $rows[] = [
                _m('SEO name') => safe((string) $group['seo']),
                _m('Items')    => (string) (int) $group['cnt'],
                _m('Which')    => self::itemLinks($shown[$i], (int) $group['cnt']),
            ];
        }
        return $rows;
    }

    /** the ids the report links to: GROUP_CONCAT is cut at group_concat_max_len
     *  (1024 B, ~30 ids), so the last one can be a fragment - hence is_long_id()
     * @return list<string>
     */
    private static function shownIds(string $ids): array
    {
        return array_slice(array_values(array_filter(explode(',', $ids), 'is_long_id')), 0, self::MAX_LINKS);
    }

    /** links to the first MAX_LINKS items of one group, the rest only counted.
     *  The count is COUNT(DISTINCT) from SQL, never the number of links: the id list
     *  is capped, and itemLink() returns '' for an item deleted in the meantime. */
    private static function itemLinks(array $item_ids, int $count): string
    {
        $links = array_filter(array_map(static fn(string $id): string => Adminlink::itemLink($id, '', true), $item_ids));
        if ($count > count($links)) {
            $links[] = _m('... and %1 more', [$count - count($links)]);
        }
        return implode('<br>', $links);
    }

    /** SEO names used by more than one item of the slices, most shared first.
     *  Row: seo, cnt, ids (comma separated unpacked item ids, ordered so that two
     *  runs over the same data report the same items).
     *  The item side goes through slice_id_2 (slice_id, status_code); the content
     *  side has no field_id-leading index, so the join walks the items - the
     *  reason this stays an extended check.
     *  Public only so the test can pin the SQL: every id goes through makeWhere's
     *  'l' type (xpack_id -> 0x… or 0x00), the rest are class constants. Request
     *  data has no business here. */
    public static function duplicatesSql(array $slice_ids): string
    {
        return 'SELECT `c`.`text` AS seo, COUNT(DISTINCT `i`.`id`) AS cnt,'
            . ' GROUP_CONCAT(DISTINCT LOWER(HEX(`i`.`id`)) ORDER BY LOWER(HEX(`i`.`id`))) AS ids'
            . ' FROM `item` AS `i` JOIN `content` AS `c` ON `c`.`item_id` = `i`.`id`'
            . " AND `c`.`field_id` = '" . self::SEO_FIELD . "' "
            . DB_AA::makeWhere([['i.slice_id', $slice_ids, 'l'], ['i.status_code', self::ACTIVE, 'i'], ['c.text', '', 'FILLED']])
            . ' GROUP BY `c`.`text` HAVING cnt > 1 ORDER BY cnt DESC, seo LIMIT 0,' . self::MAX_GROUPS;
    }
}
