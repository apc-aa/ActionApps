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
        // Only collections which still have a filter can mail anything - the rest are noise.
        $rows = DB_AA::select([], 'SELECT ch.collectionid, ch.howoften, ch.last, m.name,
                (SELECT COUNT(*) FROM alerts_collection_filter cf WHERE cf.collectionid = ch.collectionid) AS filters
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
                    _m('Collection') => safe((string) $row['name']),
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

        return $findings;
    }
}
