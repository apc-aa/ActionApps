<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Later\AlertsSweep;

/** Do the alert sweeps still reach their subscribers, and would the next one skip items.
 *  Both questions come from the 2026-09-22 incident on toolkit: the daily sweep had not run
 *  since 2013, and when the task queue finally planned it, 52 readers got items from 2014.
 *  AA_Collection::createFilterText() now caps how far back a sweep reaches, so the same
 *  situation drops items instead of mailing them - quietly, unless something checks for it.
 */
class CheckAlerts extends AbstractOptimize
{
    /** initialize_last() pins it to 0 on purpose and nothing sweeps it periodically */
    private const NOT_SWEPT = 'irregular';

    public static function name(): string
    {
        return _m('Alerts sending');
    }

    public static function description(): string
    {
        return _m('Checks that every frequency readers can subscribe to is really swept, and that no collection is so far behind that the next sweep would skip items instead of mailing them.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'cron';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $now      = now();
        $findings = [];
        $links    = [_m('Task Manager') => get_admin_url('aa_toexecute.php')];

        // tiny table (~100 rows on the biggest install), so one read and the rest in PHP.
        // Only collections which still have a live selection can mail anything - the rest are noise
        // here and reported below, since a sweep without a selection never moves `last`.
        $rows = DB_AA::select([], 'SELECT ch.collectionid, ch.howoften, ch.last, m.name, m.id AS module_id,
                (SELECT COUNT(*) FROM alerts_collection_filter cf
                    INNER JOIN alerts_filter f ON f.id = cf.filterid
                    INNER JOIN view v ON v.id = f.vid
                    WHERE cf.collectionid = ch.collectionid) AS filters
            FROM alerts_collection_howoften ch
            INNER JOIN alerts_collection c ON c.id = ch.collectionid
            INNER JOIN module m ON m.id = c.module_id', [['m.deleted', 1, '<']]);

        $behind      = [];
        $subscribable = [];
        foreach ($rows as $row) {
            $howoften = (string) $row['howoften'];
            $last     = (int) $row['last'];
            $subscribable[$howoften] = true;
            if ($howoften === self::NOT_SWEPT || $last === 0 || (int) $row['filters'] === 0) {
                continue;
            }
            $earliest = AlertsSweep::earliestBacklog($howoften, $now);
            if ($last < $earliest) {
                $behind[] = [
                    _m('Collection') => a_href(self::selectionsUrl((string) $row['module_id']), safe((string) $row['name'])),
                    _m('Frequency')  => safe($howoften),
                    _m('Last sent')  => date('Y-m-d H:i', $last),
                    _m('Would skip') => _m('up to %1 days', [(int) (($earliest - $last) / 86400)]),
                ];
            }
        }

        if ($behind) {
            $findings[] = new Finding(
                Finding::ERROR,
                _m('%1 alert collections are so far behind that the next sweep will skip items', [count($behind)]),
                count: count($behind),
                evidence: $behind,
                advice: _m('A sweep never reaches back more than two of its periods, so everything older is dropped, not mailed. Check that the task queue plans this frequency and decide whether to let the gap go or to send it by hand from the module.'),
                links: $links,
                key: 'behind',
            );
        }

        // the twoweeks bug: readers could subscribe to a frequency the queue never swept
        $unplanned = array_diff(array_keys($subscribable), array_keys(AlertsSweep::SCHEDULE), [self::NOT_SWEPT]);
        if ($unplanned) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Readers can subscribe to a frequency nothing sweeps: %1', [implode(', ', $unplanned)]),
                count: count($unplanned),
                advice: _m('AlertsSweep::SCHEDULE has no entry for it, so no task is ever planned and those readers get nothing.'),
                links: $links,
                key: 'unplanned',
            );
        }

        $findings = array_merge($findings, $this->missingSelections($links));
        return $findings ?: [new Finding(Finding::OK, _m('Every alert collection is swept on time and all its selections exist'))];
    }

    /** A selection is a filter of a view (alerts_filter); deleting the filter or its view leaves the
     *  collection pointing at nothing. The sweep then finds no item, sends nothing and logs nothing -
     *  safaripark.cz newsletter sat so on a deleted f259 since 2016.
     *  @param array<string,string> $links
     *  @return Finding[] one per collection */
    private function missingSelections(array $links): array
    {
        $rows = DB_AA::select([], 'SELECT cf.collectionid, cf.filterid, f.id AS filter_exists, f.vid, m.name, m.id AS module_id,
                (SELECT COUNT(*) FROM alerts_collection_filter cf2
                    INNER JOIN alerts_filter f2 ON f2.id = cf2.filterid
                    INNER JOIN view v2 ON v2.id = f2.vid
                    WHERE cf2.collectionid = cf.collectionid) AS live
            FROM alerts_collection_filter cf
            INNER JOIN alerts_collection c ON c.id = cf.collectionid
            INNER JOIN module m ON m.id = c.module_id
            LEFT JOIN alerts_filter f ON f.id = cf.filterid
            LEFT JOIN view v ON v.id = f.vid', [['m.deleted', 1, '<'], ['v.id', '', 'ISNULL']], ['cf.collectionid', 'cf.myindex']);

        $by_collection = [];
        foreach ($rows as $row) {
            $by_collection[(string) $row['collectionid']][] = $row;
        }

        $findings = [];
        foreach ($by_collection as $collection_id => $missing) {
            $first    = $missing[0];
            $name     = (string) $first['name'];
            $sends    = (int) $first['live'] > 0;
            $evidence = [];
            foreach ($missing as $row) {
                $evidence[] = [
                    _m('Selection') => 'f' . (int) $row['filterid'],
                    _m('Problem')   => $row['filter_exists'] === null
                        ? safe(_m('the selection was deleted'))
                        : safe(_m('its view %1 was deleted', [(int) $row['vid']])),
                ];
            }
            $findings[] = new Finding(
                $sends ? Finding::WARNING : Finding::ERROR,
                $sends
                    ? _m('Alerts "%1" use a selection that no longer exists', [$name])
                    : _m('Alerts "%1" send nothing: every selection of the collection no longer exists', [$name]),
                count: count($missing),
                evidence: $evidence,
                advice: _m('A selection is a filter of a view of the slice the alert takes its items from. When the filter or the view is deleted, the collection keeps pointing at it and its readers get nothing from it - with no error in the log. On the Selections page remove the selection marked as missing and add an existing one (filters are defined in the view of the type Alerts Selection Set). An Alerts module nobody uses any more can be deleted instead.'),
                links: [
                    _m('Selections') => self::selectionsUrl((string) $first['module_id']),
                    _m('Alerts settings') => get_aa_url('modules/alerts/modedit.php', ['module_id' => unpack_id((string) $first['module_id'])], false),
                ] + $links,
                key: 'missing-' . bin2hex((string) $collection_id),   // a numeric id became an int array key
                kind: _m('Alert selection no longer exists'),
                place: $name,
            );
        }
        return $findings;
    }

    /** @param string $module_id packed */
    private static function selectionsUrl(string $module_id): string
    {
        return get_aa_url('modules/alerts/acf.php', ['module_id' => unpack_id($module_id)], false);
    }
}
