<?php

declare(strict_types=1);

namespace AA\Util;

use AA\IO\DB\DB_AA;
use AA_Logentry;
use AA_Logentry_Sys;

/**
 * What was running when: the install's configuration history, read from the
 * SYS_STAT log rows AA\Later\LogSystemstatus writes.
 *
 * Those rows are written at most every ten minutes and only when something
 * actually changed (AA_Log::writeIfChanged), and LogCleanup never deletes
 * them, so the table holds the full deployment history of the install.
 *
 * The point of this class is to turn that history into measurement windows:
 * spans during which one configuration was in force, so a speed number can be
 * attributed to a deployment rather than to a date.
 *
 * Two things make that harder than slicing on the raw timestamps:
 *
 *  - `svnversion` fails on some installs (a checkout synced by rsync without
 *    .svn, for instance) and then reports an empty string or "Unversioned
 *    directory" every other run. Taken at face value that flaps between two
 *    configurations several times an hour. carryTree() treats an unreadable
 *    tree version as "unchanged" instead of as a change.
 *  - hit_archive rows older than a week are grouped per day and carry the
 *    day's midnight as their timestamp (AA\Later\HitcounterGroup), so such a
 *    day cannot be split. A change on it invalidates the whole calendar day,
 *    and buildIntervals() drops it. Within the last week it can cut exactly.
 */
final class ConfigTimeline
{
    /** Seconds in a day. */
    public const DAY = 86400;

    /** The shortest window worth measuring: a day, so that it holds every hour of
     *  one (see measuredSpan()). A shorter one is dropped and its deployments are
     *  reported with the next window's, which keeps the table at a row a day at most. */
    public const MIN_SPAN = self::DAY;

    /**
     * Every recorded configuration change, oldest first, one entry per actual
     * change. Repeated rows and unreadable tree versions are folded away.
     *
     * The whole history, unbounded: it is a handful of rows on a healthy install,
     * buildIntervals() bounds it anyway, and a constant argument is what lets both
     * panels on a page share one read instead of each doing its own.
     *
     * @return list<array{time:int,aa:string,tree:string,rev:string,php:string,db:string,mem:string,cpu:string}>
     */
    public static function getChanges(): array
    {
        return \AA::Contentcache()->get_result([self::class, 'getChangesFromDb']);
    }

    /** @see getChanges() - the uncached worker the per-request memo wraps. */
    public static function getChangesFromDb(): array
    {
        $conds = [['type', 'SYS_STAT'], ['selector', 'basic']];
        return self::foldChanges(DB_AA::select([], 'SELECT `time`, `params` FROM `log`', $conds, ['time']));
    }

    /**
     * SYS_STAT rows folded into actual changes: repeated rows and unreadable tree
     * versions drop out. Split from getChanges() so the folding can be exercised
     * without a database.
     *
     * @param  list<array{time:mixed,params:mixed}> $rows  oldest first
     * @return list<array{time:int,aa:string,tree:string,rev:string,php:string,db:string,mem:string,cpu:string}>
     */
    public static function foldChanges(array $rows): array
    {
        $changes     = [];
        $last_tree   = '';
        $last_finger = null;
        foreach ($rows as $row) {
            $change = self::parse((int) $row['time'], (string) $row['params'], $last_tree);
            if (!$change) {
                continue;
            }
            $last_tree = $change['tree'];
            $finger    = self::fingerprint($change);
            if ($finger === $last_finger) {
                continue;   // the same configuration logged again - not a change
            }
            // A, then B, then A again within a day: B never replaced A. Two installs
            // sharing one database write their own SYS_STAT rows into it and the two
            // series interleave, which without this reads as a deployment every few
            // minutes. A configuration that stood for a day is kept whatever follows.
            $count = count($changes);
            if ($count >= 2
                && self::fingerprint($changes[$count - 2]) === $finger
                && ($change['time'] - $changes[$count - 1]['time']) < self::DAY) {
                array_pop($changes);
                $last_finger = $finger;
                continue;
            }
            $last_finger = $finger;
            $changes[]   = $change;
        }
        return $changes;
    }

    /**
     * Measurement windows, oldest first. Each window is one configuration in force.
     *
     * Where a change falls decides how exactly its window can be cut. From
     * $exact_from on, the measured data carries its own time, so the window
     * starts at the change itself. Before that the data exists only per whole
     * day: the change day holds both configurations and belongs to neither
     * window, the new one opening the day after.
     *
     * The newest window is always listed, however short: it is what is running
     * now, and flagged `short` until it has run for $min_span.
     *
     * Only part of a window is measured, `measure_from` to `measure_to`: its
     * freshest whole days, see measuredSpan(). `days` is the length of that part.
     *
     * @param  list<array<string,mixed>> $changes     from getChanges(), oldest first
     * @param  int      $from        start of the measured span
     * @param  int      $to          end of the measured span (exclusive)
     * @param  int      $min_span    seconds; a shorter window is dropped and its deployments carried into the next one
     * @param  int|null $exact_from  where data with exact times starts, see above; null = nowhere
     * @return list<array{from:int,to:int,span:int,measure_from:int,measure_to:int,days:float,exact:bool,changes:list<array<string,mixed>>,short:bool}>
     */
    public static function buildIntervals(array $changes, int $from, int $to, int $min_span = self::MIN_SPAN, ?int $exact_from = null): array
    {
        $window_start = self::dayStart($from);
        if ($to <= $window_start) {
            return [];
        }
        $exact_from ??= PHP_INT_MAX;

        // The configuration in force when the window opens, plus every change inside it.
        $prior  = null;
        $inside = [];
        foreach ($changes as $change) {
            if ($change['time'] >= $to) {
                break;
            }
            if ($change['time'] < $window_start) {
                $prior = $change;
                continue;
            }
            $inside[] = $change;
        }

        // One candidate window per configuration: the leading one carries whatever
        // was already running, each further one starts at its change, or the day after.
        $candidates = [['start' => $window_start, 'exact' => false, 'changes' => $prior ? [$prior] : []]];
        foreach ($inside as $change) {
            $exact = $change['time'] >= $exact_from;
            $candidates[count($candidates) - 1]['end'] = $exact ? $change['time'] : self::dayStart($change['time']);
            $candidates[] = [
                'start'   => $exact ? $change['time'] : self::nextDayStart($change['time']),
                'exact'   => $exact,
                'changes' => [$change],
            ];
        }
        $candidates[count($candidates) - 1]['end'] = $to;

        // A window too short to measure contributes nothing; its deployments are
        // carried into the next window, which is the first one that can show them.
        $intervals = [];
        $carried   = [];
        $last      = count($candidates) - 1;
        foreach ($candidates as $i => $candidate) {
            $span           = max(0, $candidate['end'] - $candidate['start']);
            $window_changes = array_merge($carried, $candidate['changes']);
            [$measure_from, $measure_to] = self::measuredSpan($candidate['start'], $candidate['start'] + $span, $candidate['exact']);
            // Long enough, and with a whole day in it: a 25-hour DST day can hold a
            // window of 24 hours that ends before its first midnight.
            $short = $span < $min_span || $measure_to <= $measure_from;
            if ($short && $i !== $last) {
                $carried = $window_changes;
                continue;
            }
            $carried = [];
            if ($candidate['end'] < $candidate['start'] && $window_changes) {
                // A change today on whole-day data: its window would open tomorrow. It is
                // listed as running since the change itself, and not measured.
                $candidate['start'] = min($candidate['end'], $window_changes[count($window_changes) - 1]['time']);
                $candidate['exact'] = true;
                $span               = $candidate['end'] - $candidate['start'];
            }
            if ($short) {
                $measure_from = $measure_to = $candidate['start'];
            }
            $intervals[] = [
                'from'         => $candidate['start'],
                'to'           => $candidate['start'] + $span,
                'span'         => $span,
                'measure_from' => $measure_from,
                'measure_to'   => $measure_to,
                'days'         => (float) ($measure_to - $measure_from) / self::DAY,
                'exact'        => $candidate['exact'],
                'changes'      => $window_changes,
                'short'        => $short,
            ];
        }
        return $intervals;
    }

    /**
     * The part of a window that is measured: its freshest whole days, so every hour
     * of the day is in it equally often. Pages are built faster at night than in the
     * evening (hour by hour 86-115 % of the daily average on a production install),
     * and a 30-hour window measured whole would count six evening hours twice.
     *
     * A window cut at a change counts back whole days from its last full hour, since
     * the newest hit data is kept per hour. When the change fell inside that first
     * hour, the hour belongs to neither side, which leaves 23 hours of a day. A window
     * on whole-day data is whole days already and ends at the last midnight in it.
     *
     * @return array{0:int,1:int} measure_from, measure_to
     */
    private static function measuredSpan(int $from, int $to, bool $exact): array
    {
        if (!$exact) {
            return [$from, max($from, self::dayStart($to))];
        }
        $end  = intdiv($to, 3600) * 3600;
        $days = max(1, intdiv($end - $from, self::DAY));
        return [max($from, $end - ($days * self::DAY)), $end];
    }

    /**
     * The window whose measured part holds the whole of [$start, $start + $length),
     * or null when none does: data from a configuration change day, an hour a change
     * cut through, or the older end of a window that is not measured.
     *
     * @param list<array{measure_from:int,measure_to:int}> $intervals  from buildIntervals()
     */
    public static function windowOf(int $start, array $intervals, int $length = 1): ?int
    {
        foreach ($intervals as $i => $interval) {
            if ($start >= $interval['measure_from'] && $start + $length <= $interval['measure_to']) {
                return $i;
            }
        }
        return null;
    }

    /**
     * What a window changed against the one before it, as short display labels
     * ("r5345", "PHP 8.2.33"). An empty list means nothing identifiable changed.
     *
     * @param  list<array<string,mixed>> $changes   the window's deployments
     * @param  array<string,mixed>|null  $previous  the last deployment of the previous window
     * @return list<string>
     */
    public static function describeDiff(array $changes, ?array $previous): array
    {
        if (!$changes) {
            return [];
        }
        $parts = [];

        // Every revision the window brought, so a window that swallowed short-lived
        // deployments still names them all.
        $was  = $previous ? self::revLabel($previous) : '';
        $revs = [];
        foreach ($changes as $change) {
            $rev = self::revLabel($change);
            if ($rev !== '' && $rev !== $was) {
                $revs[$rev] = true;
            }
        }
        if ($revs) {
            $parts[] = implode(', ', array_keys($revs));
        }
        if (!$previous) {
            return $parts;   // the leading window has nothing to have changed against
        }

        $now = $changes[count($changes) - 1];
        if ($now['aa'] !== $previous['aa'] && $now['aa'] !== '') {
            $parts[] = 'AA ' . $now['aa'];
        }
        if ($now['php'] !== $previous['php'] && $now['php'] !== '') {
            $parts[] = 'PHP ' . $now['php'];
        }
        if ($now['db'] !== $previous['db'] && $now['db'] !== '') {
            $parts[] = self::dbLabel($now['db']);
        }
        if ($now['cpu'] !== $previous['cpu'] && $now['cpu'] !== '') {
            $parts[] = \_m('new hardware');
        }
        return $parts;
    }

    /** Display form of a database version: "10.11.19-MariaDB-log/DB_PDO" reads as "MariaDB 10.11.19". */
    public static function dbLabel(string $db): string
    {
        $version = trim(explode('/', $db)[0]);
        if (preg_match('/^([0-9][0-9.]*)-([A-Za-z]+)/', $version, $m)) {
            return $m[2] . ' ' . $m[1];
        }
        return $version !== '' ? $version : $db;
    }

    /** Local midnight of the day $time falls in. */
    public static function dayStart(int $time): int
    {
        return (int) mktime(0, 0, 0, (int) date('n', $time), (int) date('j', $time), (int) date('Y', $time));
    }

    /** Local midnight that ends the day $time falls in. Not dayStart() + DAY: a DST day is 23 or 25 hours. */
    public static function nextDayStart(int $time): int
    {
        return (int) mktime(0, 0, 0, (int) date('n', $time), (int) date('j', $time) + 1, (int) date('Y', $time));
    }

    /** Short revision label of a change: the tree version when it is readable, else the util.php revision. */
    private static function revLabel(array $change): string
    {
        if ($change['tree'] !== '') {
            return 'r' . $change['tree'];
        }
        return $change['rev'] !== '' ? 'r' . $change['rev'] : '';
    }

    /**
     * One SYS_STAT row as a configuration, or null when it cannot be read.
     *
     * @param  string $last_tree  the last readable tree version, used when this row has none
     * @return array{time:int,aa:string,tree:string,rev:string,php:string,db:string,mem:string,cpu:string}|null
     */
    private static function parse(int $time, string $params, string $last_tree): ?array
    {
        $entry = AA_Logentry::factoryByString($params);
        if (!($entry instanceof AA_Logentry_Sys)) {
            return null;
        }
        [$tree, $rev] = self::splitSvn((string) $entry->svn);
        return [
            'time' => $time,
            'aa'   => (string) $entry->aa,
            'tree' => $tree !== '' ? $tree : $last_tree,   // unreadable svnversion is not a change
            'rev'  => $rev,
            'php'  => (string) $entry->php,
            'db'   => (string) $entry->db,
            'mem'  => (string) $entry->mem,
            'cpu'  => (string) $entry->cpu,
        ];
    }

    /**
     * "5348M (5345)" is the whole tree's svnversion plus the revision of
     * util.php. Either half can be missing: LogSystemstatus writes "" or
     * "Unversioned directory" for the tree when svnversion cannot read the
     * working copy.
     *
     * Public because AA\Later\LogSystemstatus applies the same rule when it
     * decides whether the tree version it just read is worth a new SYS_STAT row.
     *
     * @return array{0:string,1:string} tree version, util.php revision
     */
    public static function splitSvn(string $svn): array
    {
        $rev = '';
        if (preg_match('/\((\d+)\)\s*$/', $svn, $m)) {
            $rev = $m[1];
            $svn = substr($svn, 0, (int) strrpos($svn, '('));
        }
        $tree = trim($svn);
        // Only a version svnversion actually produced: digits, optionally a range
        // (5240:5245) and the M / S / P flags. "Unversioned directory" is not one.
        return [preg_match('/^\d[\d:]*[MSP]*$/', $tree) ? $tree : '', $rev];
    }

    /** Identity of a configuration: two rows with the same fingerprint are the same deployment. */
    private static function fingerprint(array $change): string
    {
        return implode('|', [$change['aa'], $change['tree'], $change['rev'], $change['php'], $change['db'], $change['mem'], $change['cpu']]);
    }
}
