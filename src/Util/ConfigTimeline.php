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
 *    and buildIntervals() drops it. Within the last week it can cut exactly,
 *    and a burst of deployments there still gets a row a day: a window that
 *    cannot reach MIN_SPAN before the next change runs for MIN_SPAN anyway and
 *    names every change that landed inside it.
 *
 * The row also carries a few server settings (SERVER_VARS), so that a change
 * of the database configuration opens a window like a release does.
 */
final class ConfigTimeline
{
    /** Seconds in a day. */
    public const DAY = 86400;

    /** The shortest window worth measuring: a day, so that it holds every hour of
     *  one (see measuredSpan()). On whole-day data a shorter one is dropped and its
     *  deployments are reported with the next window's; on exact data it is
     *  stretched to this length instead (see buildIntervals()). */
    public const MIN_SPAN = self::DAY;

    /**
     * Server settings the SYS_STAT row keeps, token letter => global variable.
     * Chosen for what moves AA's speed and changes without any AA release: the
     * first four decide what a commit costs (a write is ~25x a read with flush=1
     * and sync_binlog=1), the buffer pool decides what a read costs. Letters,
     * because log.params is 128 bytes and the row is 104 of them already;
     * LogSystemstatus drops the whole field when it does not fit.
     */
    public const SERVER_VARS = [
        'f' => 'innodb_flush_log_at_trx_commit',
        's' => 'sync_binlog',
        'c' => 'log_bin_compress',
        'd' => 'innodb_doublewrite',
        'b' => 'innodb_buffer_pool_size',
    ];

    /**
     * The server settings as the SYS_STAT row stores them: "f1 s1 c0 d0 b40G".
     * ON/OFF become 1/0 and byte sizes G or M, so the field stays short and stable.
     *
     * @param array<string,string> $variables  variable name => value, as SHOW GLOBAL VARIABLES lists them
     */
    public static function encodeServer(array $variables): string
    {
        $variables = array_change_key_case($variables, CASE_LOWER);
        $parts     = [];
        foreach (self::SERVER_VARS as $letter => $name) {
            if (!isset($variables[$name])) {
                continue;
            }
            $value = strtoupper(trim((string) $variables[$name]));
            if ($value === 'ON') {
                $value = '1';
            } elseif ($value === 'OFF') {
                $value = '0';
            } elseif (ctype_digit($value) && (int) $value >= (1 << 20)) {
                $bytes = (int) $value;
                $value = $bytes % (1 << 30) === 0 ? intdiv($bytes, 1 << 30) . 'G' : intdiv($bytes, 1 << 20) . 'M';
            }
            $parts[] = $letter . $value;
        }
        return implode(' ', $parts);
    }

    /**
     * encodeServer() read back.
     *
     * @return array<string,string> variable name => value
     */
    public static function decodeServer(string $server): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($server), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $name = self::SERVER_VARS[$token[0]] ?? null;
            if ($name !== null) {
                $out[$name] = substr($token, 1);
            }
        }
        return $out;
    }

    /**
     * Every recorded configuration change, oldest first, one entry per actual
     * change. Repeated rows and unreadable tree versions are folded away.
     *
     * The whole history, unbounded: it is a handful of rows on a healthy install,
     * buildIntervals() bounds it anyway, and a constant argument is what lets both
     * panels on a page share one read instead of each doing its own.
     *
     * @return list<array{time:int,aa:string,tree:string,rev:string,php:string,db:string,mem:string,cpu:string,server:string}>
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
     * @return list<array{time:int,aa:string,tree:string,rev:string,php:string,db:string,mem:string,cpu:string,server:string}>
     */
    public static function foldChanges(array $rows): array
    {
        $changes     = [];
        $last_tree   = '';
        $last_server = '';
        $last_finger = null;
        foreach ($rows as $row) {
            $change = self::parse((int) $row['time'], (string) $row['params'], $last_tree);
            if (!$change) {
                continue;
            }
            $last_tree = $change['tree'];
            // A row without server settings (an older AA sharing the database, or a
            // row too long to hold them) does not say they changed: the last known stand.
            if ($change['server'] === '') {
                $change['server'] = $last_server;
            }
            $last_server = $change['server'];
            $finger      = self::fingerprint($change);
            if ($finger === $last_finger) {
                continue;   // the same configuration logged again - not a change
            }
            $count = count($changes);
            // The first row carrying server settings only says what the configuration
            // already running had been on all along: known now, not changed.
            if ($count > 0 && $changes[$count - 1]['server'] === '' && $change['server'] !== ''
                && self::fingerprint(['server' => ''] + $change) === self::fingerprint($changes[$count - 1])) {
                $changes[$count - 1]['server'] = $change['server'];
                $last_finger = $finger;
                continue;
            }
            // A, then B, then A again within a day: B never replaced A. Two installs
            // sharing one database write their own SYS_STAT rows into it and the two
            // series interleave, which without this reads as a deployment every few
            // minutes. A configuration that stood for a day is kept whatever follows.
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
     * On exact data a window that would end before $min_span, because the next
     * change comes sooner, runs for $min_span anyway: every change inside it is
     * listed with it, and the window after it opens at that cut with no change
     * of its own (`running` says which configuration it measures). A day with
     * five releases is then one row naming five revisions, and the day after it
     * a clean row of the last one, instead of both days folding into one row.
     *
     * Only part of a window is measured, `measure_from` to `measure_to`: its
     * freshest whole days, see measuredSpan(). `days` is the length of that part.
     *
     * @param  list<array<string,mixed>> $changes     from getChanges(), oldest first
     * @param  int      $from        start of the measured span
     * @param  int      $to          end of the measured span (exclusive)
     * @param  int      $min_span    seconds; a shorter window is stretched (exact data) or dropped with its deployments carried into the next one (whole-day data)
     * @param  int|null $exact_from  where data with exact times starts, see above; null = nowhere
     * @return list<array{from:int,to:int,span:int,measure_from:int,measure_to:int,days:float,exact:bool,changes:list<array<string,mixed>>,running:?array<string,mixed>,short:bool}>
     *         `changes` are the deployments inside the window, `running` the one in force when it opened
     */
    public static function buildIntervals(array $changes, int $from, int $to, int $min_span = self::MIN_SPAN, ?int $exact_from = null): array
    {
        $start = self::dayStart($from);
        if ($to <= $start) {
            return [];
        }
        $exact_from ??= PHP_INT_MAX;

        // The configuration in force when the period opens, and every change inside it.
        $running = null;
        $queue   = [];
        foreach ($changes as $change) {
            if ($change['time'] >= $to) {
                break;
            }
            if ($change['time'] < $start) {
                $running = $change;
                continue;
            }
            $queue[] = $change;
        }

        // The leading window carries whatever was already running; each further one
        // opens at its change, the day after it on whole-day data, or at a cut.
        $intervals = [];
        $carried   = [];   // deployments of windows too short to measure, named with the next one
        $window    = ['start' => $start, 'exact' => false, 'changes' => $running ? [$running] : []];
        while (true) {
            $next_change = $queue[0] ?? null;
            $exact       = $next_change !== null && $next_change['time'] >= $exact_from;
            // The window ends at the next change, or - on whole-day data, where the change
            // day belongs to neither window - at the midnight before it.
            $end = match (true) {
                $next_change === null => $to,
                $exact                => $next_change['time'],
                default               => self::dayStart($next_change['time']),
            };

            $next_window = null;
            if ($next_change !== null && $window['start'] >= $exact_from && $end - $window['start'] < $min_span) {
                // Exact data and the next change comes too soon: the window runs for
                // $min_span anyway and reports every change that landed inside it.
                $end = min($to, $window['start'] + $min_span);
                while ($queue && $queue[0]['time'] < $end) {
                    $window['changes'][] = array_shift($queue);
                }
                if ($end < $to) {
                    $next_window = ['start' => $end, 'exact' => true, 'changes' => []];
                }
            } elseif ($next_change !== null) {
                array_shift($queue);
                $opens       = $exact ? $next_change['time'] : self::nextDayStart($next_change['time']);
                $next_window = ['start' => $opens, 'exact' => $exact, 'changes' => [$next_change]];
            }

            $span           = max(0, $end - $window['start']);
            $window_changes = array_merge($carried, $window['changes']);
            [$measure_from, $measure_to] = self::measuredSpan($window['start'], $window['start'] + $span, $window['exact']);
            // Long enough, and with a whole day in it: a 25-hour DST day can hold a
            // window of 24 hours that ends before its first midnight.
            $short = $span < $min_span || $measure_to <= $measure_from;
            if ($short && $next_window !== null) {
                // Whole-day data: nothing measurable, the next window names its deployments.
                $carried = $window_changes;
            } else {
                $carried = [];
                if ($end < $window['start'] && $window_changes) {
                    // A change today on whole-day data: its window would open tomorrow. It is
                    // listed as running since the change itself, and not measured.
                    $window['start'] = min($end, $window_changes[count($window_changes) - 1]['time']);
                    $window['exact'] = true;
                    $span            = $end - $window['start'];
                }
                if ($short) {
                    $measure_from = $measure_to = $window['start'];
                }
                $intervals[] = [
                    'from'         => $window['start'],
                    'to'           => $window['start'] + $span,
                    'span'         => $span,
                    'measure_from' => $measure_from,
                    'measure_to'   => $measure_to,
                    'days'         => (float) ($measure_to - $measure_from) / self::DAY,
                    'exact'        => $window['exact'],
                    'changes'      => $window_changes,
                    'running'      => $running,
                    'short'        => $short,
                ];
                // What the next row runs: this row's last deployment, or, when it brought
                // none, whatever this one was already running. A dropped window does not
                // move it - its deployments are named in the row that follows it.
                $running = $window_changes ? $window_changes[count($window_changes) - 1] : $running;
            }
            if ($next_window === null) {
                return $intervals;
            }
            $window = $next_window;
        }
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
     * ("r5345", "PHP 8.2.33"), each with the tooltip that spells it out - the git
     * commit behind a date label. An empty list means nothing identifiable changed.
     *
     * @param  list<array<string,mixed>> $changes   the window's deployments
     * @param  array<string,mixed>|null  $previous  the last deployment of the previous window
     * @return list<array{0:string,1:string}>  label, tooltip
     */
    public static function describeDiff(array $changes, ?array $previous): array
    {
        if (!$changes) {
            return [];
        }
        $parts = [];

        // Every revision the window brought, so a window that swallowed short-lived
        // deployments still names them all.
        $was  = $previous ? self::revKey($previous) : '';
        $revs = [];
        foreach ($changes as $change) {
            $key = self::revKey($change);
            if ($key !== '' && $key !== $was && !isset($revs[$key])) {
                $revs[$key] = self::revLabel($change);
            }
        }
        if ($revs) {
            // one tag per window, so the labels join and so do the commits behind them
            $parts[] = [implode(', ', array_column($revs, 0)), implode(', ', array_filter(array_column($revs, 1)))];
        }
        if (!$previous) {
            return $parts;   // the leading window has nothing to have changed against
        }

        $now = $changes[count($changes) - 1];
        // A checkout synced by rsync keeps one svnversion for months while util.php
        // moves with every release: then the util.php revision is the deployment.
        if (!$revs && $now['rev'] !== '' && $now['rev'] !== $previous['rev']) {
            $parts[] = ['util.php r' . $now['rev'], ''];
        }
        if ($now['aa'] !== $previous['aa'] && $now['aa'] !== '') {
            $parts[] = ['AA ' . $now['aa'], ''];
        }
        if ($now['php'] !== $previous['php'] && $now['php'] !== '') {
            $parts[] = ['PHP ' . $now['php'], ''];
        }
        if ($now['db'] !== $previous['db'] && $now['db'] !== '') {
            $parts[] = [self::dbLabel($now['db']), ''];
        }
        if ($now['cpu'] !== $previous['cpu'] && $now['cpu'] !== '') {
            $parts[] = [\_m('new hardware'), ''];
        }
        if ($now['mem'] !== $previous['mem'] && $now['mem'] !== '') {
            $parts[] = [\_m('memory') . ' ' . $now['mem'], ''];
        }
        $server = array_map(static fn(string $change) => [$change, ''], self::serverDiff((string) ($previous['server'] ?? ''), (string) ($now['server'] ?? '')));
        return array_merge($parts, $server);
    }

    /**
     * Which server settings moved between two rows, as "sync_binlog 1 → 0". Nothing
     * when either row has none: a row from before the field existed is not a change.
     *
     * @return list<string>
     */
    public static function serverDiff(string $before, string $after): array
    {
        if ($before === '' || $after === '') {
            return [];
        }
        $was   = self::decodeServer($before);
        $parts = [];
        foreach (self::decodeServer($after) as $name => $value) {
            if (isset($was[$name]) && $was[$name] !== $value) {
                $parts[] = "$name {$was[$name]} → $value";
            }
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

    /** What makes two deployments the same code: the tree version when it is readable, else the util.php revision. */
    private static function revKey(array $change): string
    {
        return $change['tree'] !== '' ? $change['tree'] : (string) $change['rev'];
    }

    /** How a revision is shown. An svnversion number reads by itself ("r5345"); a git commit does not,
     *  so there the label is when the configuration first appeared and the commit stays as the tooltip.
     *
     * @return array{0:string,1:string} label, tooltip
     */
    private static function revLabel(array $change): array
    {
        $rev = self::revKey($change);
        if ($rev === '') {
            return ['', ''];
        }
        if (preg_match('/^\d[\d:]*[MSP]*$/', $rev)) {
            return ['r' . $rev, ''];
        }
        return [date('y-m-d-H-i', (int) $change['time']), $rev];
    }

    /**
     * One SYS_STAT row as a configuration, or null when it cannot be read.
     *
     * @param  string $last_tree  the last readable tree version, used when this row has none
     * @return array{time:int,aa:string,tree:string,rev:string,php:string,db:string,mem:string,cpu:string,server:string}|null
     */
    private static function parse(int $time, string $params, string $last_tree): ?array
    {
        $entry = AA_Logentry::factoryByString($params);
        if (!($entry instanceof AA_Logentry_Sys)) {
            return null;
        }
        [$tree, $rev] = self::splitSvn((string) $entry->svn);
        return [
            'time'   => $time,
            'aa'     => (string) $entry->aa,
            'tree'   => $tree !== '' ? $tree : $last_tree,   // unreadable svnversion is not a change
            'rev'    => $rev,
            'php'    => (string) $entry->php,
            'db'     => (string) $entry->db,
            'mem'    => (string) $entry->mem,
            'cpu'    => (string) $entry->cpu,
            'server' => (string) $entry->server,   // '' on rows from before the field existed
        ];
    }

    /**
     * "5348M (5345)" is the whole tree's svnversion plus the revision of
     * util.php (rows from the SVN era); "a1b2c3d (a1b2c3d)" is the main commit a
     * dist tree was built from (include/release.php, since the git migration).
     * Either half can be missing: LogSystemstatus writes "" or "Unversioned
     * directory" for the tree when svnversion cannot read the working copy, and
     * a source checkout has no release.php.
     *
     * Public because AA\Later\LogSystemstatus applies the same rule when it
     * decides whether the tree version it just read is worth a new SYS_STAT row.
     *
     * @return array{0:string,1:string} tree version, code revision
     */
    public static function splitSvn(string $svn): array
    {
        $rev = '';
        if (preg_match('/\(([0-9a-f]+)\)\s*$/', $svn, $m)) {
            $rev = $m[1];
            $svn = substr($svn, 0, (int) strrpos($svn, '('));
        }
        $tree = trim($svn);
        // Only what a real tool produced: svnversion's digits, optionally a range
        // (5240:5245) and the M / S / P flags, or an abbreviated git commit.
        // "Unversioned directory" is neither.
        return [preg_match('/^(\d[\d:]*[MSP]*|[0-9a-f]{7,40})$/', $tree) ? $tree : '', $rev];
    }

    /** Identity of a configuration: two rows with the same fingerprint are the same deployment. */
    private static function fingerprint(array $change): string
    {
        return implode('|', [$change['aa'], $change['tree'], $change['rev'], $change['php'], $change['db'], $change['mem'], $change['cpu'], $change['server']]);
    }
}
