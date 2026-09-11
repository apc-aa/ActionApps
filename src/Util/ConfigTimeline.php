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
 * whole-day spans during which one configuration was in force, so a speed
 * number can be attributed to a deployment rather than to a date.
 *
 * Two things make that harder than slicing on the raw timestamps:
 *
 *  - `svnversion` fails on some installs (a checkout synced by rsync without
 *    .svn, for instance) and then reports an empty string or "Unversioned
 *    directory" every other run. Taken at face value that flaps between two
 *    configurations several times an hour. carryTree() treats an unreadable
 *    tree version as "unchanged" instead of as a change.
 *  - hit_archive rows older than about eight days are grouped per day and
 *    carry the day's midnight as their timestamp (AA\Later\HitcounterGroup),
 *    so a day cannot be split. A configuration change therefore invalidates
 *    the whole calendar day it happened on, and buildIntervals() drops it.
 */
final class ConfigTimeline
{
    /** Seconds in a day. Windows are whole local days, see the class docblock. */
    public const DAY = 86400;

    /** A window shorter than this cannot be measured; its days are dropped and
     *  its deployments are reported together with the next window's. */
    public const MIN_DAYS = 2;

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
     * Whole-day measurement windows, oldest first. Each window is one
     * configuration in force over a whole number of days.
     *
     * @param  list<array<string,mixed>> $changes  from getChanges(), oldest first
     * @param  int $from      start of the measured span
     * @param  int $to        end of the measured span (exclusive)
     * @param  int $min_days  windows shorter than this are dropped, see the class docblock
     * @return list<array{from:int,to:int,days:int,changes:list<array<string,mixed>>,short:bool}>
     */
    public static function buildIntervals(array $changes, int $from, int $to, int $min_days = self::MIN_DAYS): array
    {
        $window_start = self::dayStart($from);
        if ($to <= $window_start) {
            return [];
        }

        // The configuration in force when the window opens, plus every change inside it.
        $prior  = null;
        $inside = [];
        foreach ($changes as $change) {
            if ($change['time'] >= $to) {
                break;
            }
            if (self::dayStart($change['time']) < $window_start) {
                $prior = $change;
                continue;
            }
            $inside[] = $change;
        }

        // One candidate window per configuration: the leading one carries whatever
        // was already running, each further one starts the day after its change.
        $candidates = [['start' => $window_start, 'changes' => $prior ? [$prior] : []]];
        foreach ($inside as $change) {
            $candidates[count($candidates) - 1]['end'] = self::dayStart($change['time']);
            $candidates[] = ['start' => self::dayStart($change['time']) + self::DAY, 'changes' => [$change]];
        }
        $candidates[count($candidates) - 1]['end'] = $to;

        // A window too short to measure contributes no days; its deployments are
        // carried into the next window, which is the first one that can show them.
        $intervals = [];
        $carried   = [];
        $last      = count($candidates) - 1;
        foreach ($candidates as $i => $candidate) {
            $days           = (int) floor(max(0, $candidate['end'] - $candidate['start']) / self::DAY);
            $window_changes = array_merge($carried, $candidate['changes']);
            if (!$days || ($days < $min_days && $i !== $last)) {
                $carried = $window_changes;
                continue;
            }
            $carried     = [];
            $intervals[] = [
                'from'    => $candidate['start'],
                'to'      => $candidate['end'],
                'days'    => $days,
                'changes' => $window_changes,
                'short'   => $days < $min_days,
            ];
        }
        return $intervals;
    }

    /**
     * The window $time falls in, or null when it falls in none: a configuration
     * change day belongs to neither side of it, see the class docblock.
     *
     * @param list<array{from:int,to:int,days:int,changes:list<array<string,mixed>>,short:bool}> $intervals  from buildIntervals()
     */
    public static function windowOf(int $time, array $intervals): ?int
    {
        foreach ($intervals as $i => $interval) {
            if ($time >= $interval['from'] && $time < $interval['to']) {
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
     * @return array{0:string,1:string} tree version, util.php revision
     */
    private static function splitSvn(string $svn): array
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
