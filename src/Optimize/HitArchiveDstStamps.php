<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Later\HitcounterGroup;
use AA\Util\ConfigTimeline;
use DateTimeZone;

/**
 * Puts the hit statistics of the days around each clock change back on their own
 * midnight.
 *
 * AA\Later\HitcounterGroup used to step from one day to the one before by 86400 s.
 * Across a switch to or from summer time the step came out an hour off midnight, and
 * the window regrouped the next day, already grouped and stamped at its midnight,
 * under its own start: statistics show one day twice and the next one empty, at
 * every clock change since 2009. Only the stamps are wrong, no hit is lost. The
 * fixed day can hold two rows per item and cache state; everything that reads the
 * table sums them.
 */
class HitArchiveDstStamps extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Hit statistics around clock changes');
    }

    public static function description(): string
    {
        return _m('Until November 2026, hits grouped by day around a switch to or from summer time were stamped an hour off midnight, so the statistics show one day twice and the next one empty. Repair moves them to their own day.');
    }

    /** Not basic: the lookup took 0.5 s on the largest table. */
    public static function types(): array
    {
        return ['sql_update', self::CHECK_EXTENDED];
    }

    /** Only the daily statistics read wrong, nothing is lost. */
    public static function failSeverity(): string
    {
        return Finding::WARNING;
    }

    public function test(): bool
    {
        $misplaced = $this->misplaced();
        foreach ($misplaced as $stamp => $day) {
            $this->message(_m('Hits stamped %1 belong to %2', [date('Y-m-d H:i', $stamp), date('Y-m-d', $day)]));
        }
        if (!$misplaced) {
            $this->message(_m('No hits stamped off midnight around a clock change'));
        }
        return !$misplaced;
    }

    /** One UPDATE per stamp, no transaction: each leaves the table consistent, and a rerun moves the rest. */
    public function repair(): bool
    {
        $ok = true;
        foreach ($this->misplaced() as $stamp => $day) {
            $moved = DB_AA::update('hit_archive', [['time', $day, 'i']], [['time', $stamp, 'i']]);
            $ok    = $ok && $moved !== false;
            $this->message(_m('%1 rows moved from %2 to %3', [(int) $moved, date('Y-m-d H:i', $stamp), date('Y-m-d', $day)]));
        }
        return $ok;
    }

    /** @return array<int,int> misplaced stamp => midnight of the day its rows came from */
    private function misplaced(): array
    {
        $oldest = HitcounterGroup::oldestHit();
        // A day is grouped about two days after exactSince() passes it, so around a
        // fresher switch there are raw hits, any of which can fall on a whole hour.
        $candidates = $oldest ? self::candidates($oldest, HitcounterGroup::exactSince(time()) - 2 * ConfigTimeline::DAY) : [];
        if (!$candidates) {
            return [];
        }
        $out = [];
        foreach (DB_AA::select('time', 'SELECT DISTINCT `time` FROM `hit_archive`', [['time', $candidates, 'i']]) as $stamp) {
            $out[(int) $stamp] = self::dayOf((int) $stamp);
        }
        return $out;
    }

    /**
     * Where the old step left its stamps, for each clock change in [$from, $to): the
     * midnight of the switch day and of the day before it, moved by the change of the
     * offset the other way - 23:00 the evening before in spring, 01:00 in autumn.
     *
     * @return list<int>
     */
    public static function candidates(int $from, int $to): array
    {
        // the first entry is the offset in force at $from, not a switch
        $transitions = (new DateTimeZone(date_default_timezone_get()))->getTransitions($from, $to) ?: [];
        $out         = [];
        for ($i = 1, $n = count($transitions); $i < $n; $i++) {
            $shift      = $transitions[$i]['offset'] - $transitions[$i - 1]['offset'];
            $switch_day = ConfigTimeline::dayStart($transitions[$i]['ts']);
            foreach ([ConfigTimeline::dayStart($switch_day - 1), $switch_day] as $midnight) {
                $out[] = $midnight - $shift;
            }
        }
        return $out;
    }

    /**
     * The day the rows under a misplaced stamp came from. Its window ran 24 hours from
     * the stamp: when that reached over a midnight, it took in the day grouped there;
     * when not, it holds what was left of its own day.
     */
    public static function dayOf(int $stamp): int
    {
        $next = ConfigTimeline::nextDayStart($stamp);
        return $next < $stamp + ConfigTimeline::DAY ? $next : ConfigTimeline::dayStart($stamp);
    }
}
