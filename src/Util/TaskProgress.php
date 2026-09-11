<?php

declare(strict_types=1);

namespace AA\Util;

use AA\IO\DB\DB_AA;
use AA_Log;

/**
 * The other half of "did what we shipped make AA faster": how long the queue's
 * tasks take, compared between the configuration changes ConfigTimeline reads
 * out of the log.
 *
 * This exists because the page-speed panel structurally cannot see most of the
 * work AA does. Hitcounter's clock stops when the page has been echoed, so
 * storing the page cache, writing the log, saving an item and everything cron
 * does fall outside it. The queue is where that work lives, and AA\Later\Toexecute
 * already times every task it runs (AA_Logentry_X, written to the log as
 * `X:{seconds}:{memory}:{peak}:{result}`).
 *
 * Queue tasks make a better benchmark than page hits in one important way: the
 * same task runs again and again over the same shape of work, thousands of times
 * a month. They make a worse one in another: how long an import takes depends on
 * how much arrived, so a single task's duration is heavy-tailed. Hence medians
 * rather than averages, a cohort of tasks that ran in every window, and a sign
 * test over that cohort rather than a headline percentage.
 *
 * The history is shorter than the page-speed one: AA\Later\LogCleanup keeps
 * TOEXECUTE rows for 40 days.
 */
final class TaskProgress
{
    /** Days of history compared by default. LogCleanup keeps 40; the cost of this
     *  panel is one indexed scan of the queue log over this span, so it buys history
     *  with page time. Two weeks spans several deployments on a busy install. */
    public const WINDOW_DAYS = 14;

    /** Runs a task needs in every window before it may speak for the comparison. */
    public const MIN_RUNS = 20;

    /** Windows shown, newest first. */
    public const MAX_INTERVALS = 6;

    /** Below this many task types the numbers are not worth showing. */
    private const MIN_COHORT = 3;

    /** Runs read into memory. Above this the queue is sampled rather than truncated,
     *  because a truncated read is a biased one and a sampled read is not. */
    private const TARGET_ROWS = 20000;

    /** Task types listed one by one under the table. */
    private const BREAKDOWN_ROWS = 12;

    /** The whole panel, or a short explanation when there is nothing to compare. */
    public static function render(): string
    {
        $now       = time();
        $from      = ConfigTimeline::dayStart($now - (self::WINDOW_DAYS * ConfigTimeline::DAY));
        $intervals = array_slice(ConfigTimeline::buildIntervals(ConfigTimeline::getChanges(), $from, $now), -self::MAX_INTERVALS);

        if (count($intervals) < 2) {
            return '';   // the page-speed panel above already says why
        }

        $runs = self::getRuns($from, $now);
        if (!$runs) {
            return self::renderNote(\_m('No task runs recorded in this period. AA logs one row per queue task, and keeps them for 40 days.'));
        }

        $summary = self::summarize($runs, $intervals);
        if (count($summary['cohort']) < self::MIN_COHORT) {
            return self::renderNote(\_m('Not enough task types ran in every window to compare. The queue has to have been doing the same work throughout.'));
        }

        return self::css()
             . self::renderTable($summary)
             . self::renderBreakdown($summary);
    }

    /**
     * Every timed task run in the period, by task, sampled if there are too many.
     *
     * The duration is the second colon-separated field of AA_Logentry_X, pulled
     * out in SQL so a fortnight of rows does not have to be deserialized one by one.
     * `X:` also keeps out the run summaries (`Xrun:`) and the plain "start" rows,
     * whose second field means something else entirely.
     *
     * A busy queue logs far more runs than a median needs: a production install
     * measured 142 000 in a month. Reading every one of them would cost tens of
     * megabytes for numbers that a sample answers just as well, so past
     * TARGET_ROWS the query keeps every Nth row by primary key. That key is an
     * autoincrement shared with every other log type, so which rows it keeps is
     * unrelated to task or time, and the medians come out unbiased. A LIMIT would
     * not: it would silently drop whole windows.
     *
     * @return array<string, list<array{time:int,secs:float}>>
     */
    private static function getRuns(int $from, int $to): array
    {
        $where = [['type', 'TOEXECUTE'], ['params', 'X:%', 'LIKE'], ['time', $from, '>='], ['time', $to, '<']];
        $every = self::sampleEvery(DB_AA::count('log', $where));
        if ($every > 1) {
            $where[] = ["MOD(`id`, $every)", 0, 'i'];   // $every is computed here, never an input
        }

        $rows = DB_AA::select(
            [],
            "SELECT `selector`, `time`, SUBSTRING_INDEX(SUBSTRING_INDEX(`params`, ':', 2), ':', -1) AS `secs` FROM `log`",
            $where,
        );

        $runs = [];
        foreach ($rows as $row) {
            $task = self::taskName((string) $row['selector']);
            // sprintf('%.3g') can write 1.23e+3 for a long task; (float) reads that, a
            // SQL CAST would have stopped at 1.23.
            $secs = (float) $row['secs'];
            if ($task === '' || $secs <= 0) {
                continue;
            }
            $runs[$task][] = ['time' => (int) $row['time'], 'secs' => $secs];
        }
        return $runs;
    }

    /** Keep every Nth run, so a queue of any size reads into about TARGET_ROWS rows. */
    public static function sampleEvery(int $total): int
    {
        return (int) max(1, ceil($total / self::TARGET_ROWS));
    }

    /**
     * The stable name of a task, which is not quite what the log stores.
     *
     * AA_Log::selector() serializes an AA_Logentry_Obj reversed, so the same task
     * is written as `HitcounterUpdate` by an older AA and `HitcounterUpdate::Obj`
     * by a newer one. That suffix is a serialization artifact, not part of the
     * task's identity, and taking it at face value splits every task in two at the
     * upgrade - which is exactly the moment this panel exists to measure across.
     */
    public static function taskName(string $selector): string
    {
        $selector = trim($selector);
        if (str_ends_with($selector, ':Obj')) {
            $selector = rtrim(substr($selector, 0, -4), ':');
        }
        return $selector;
    }

    /**
     * The numbers behind the panel. Pure: everything it needs is in its arguments.
     *
     * @param  array $runs       from getRuns()
     * @param  array $intervals  from ConfigTimeline::buildIntervals()
     * @return array{rows:list<array<string,mixed>>,cohort:list<string>,medians:array<string,array<int,float>>}
     */
    public static function summarize(array $runs, array $intervals): array
    {
        $count = count($intervals);

        // Per task and window: the median run, and how much time the task spent in total.
        $medians = $totals = $counts = [];
        foreach ($runs as $task => $task_runs) {
            $by_window = [];
            foreach ($task_runs as $run) {
                $i = ConfigTimeline::windowOf($run['time'], $intervals);
                if ($i !== null) {
                    $by_window[$i][] = $run['secs'];
                }
            }
            foreach ($by_window as $i => $values) {
                $counts[$i][$task]  = count($values);
                $totals[$i]         = ($totals[$i] ?? 0.0) + array_sum($values);
                $medians[$task][$i] = self::median($values);
            }
        }

        // Each task's own yardstick, once: typical() reads it for every window.
        $baselines = [];
        foreach ($medians as $task => $windows) {
            $baselines[$task] = self::baseline($windows);
        }

        // A task speaks only if it ran often enough in every window: the queue's
        // work changes over a month, and a task that stopped running would otherwise
        // read as the whole queue getting faster.
        $cohort = [];
        foreach ($medians as $task => $windows) {
            if (count($windows) < $count || $baselines[$task] <= 0) {
                continue;
            }
            $enough = true;
            foreach (array_keys($windows) as $i) {
                $enough = $enough && ($counts[$i][$task] ?? 0) >= self::MIN_RUNS;
            }
            if ($enough) {
                $cohort[] = (string) $task;
            }
        }

        $rows = [];
        foreach ($intervals as $i => $interval) {
            $runs_in_window = 0;
            foreach ($cohort as $task) {
                $runs_in_window += $counts[$i][$task] ?? 0;
            }
            $rows[] = [
                'interval'  => $interval,
                'typical'   => self::typical($medians, $baselines, $cohort, $i),
                'seconds'   => ($totals[$i] ?? 0.0) / max(1, $interval['days']),
                'runs'      => $runs_in_window / max(1, $interval['days']),
                'run_total' => $runs_in_window,
            ];
        }

        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $before                = $rows[$i - 1];
            $rows[$i]['d_typical'] = ($row['typical'] === null || $before['typical'] === null || $before['typical'] <= 0)
                ? null
                : ((($row['typical'] / $before['typical']) - 1) * 100);
            $rows[$i]['d_seconds'] = $before['seconds'] > 0 ? ((($row['seconds'] / $before['seconds']) - 1) * 100) : null;
            $rows[$i]['agree']     = ProgressPanel::agreement($medians, $cohort, $i);
        }

        return ['rows' => $rows, 'cohort' => $cohort, 'medians' => $medians];
    }

    /** A task's typical run over the whole period, used as its own yardstick. */
    private static function baseline(array $windows): float
    {
        $values = array_filter($windows, static fn($v) => $v !== null && $v > 0);
        return $values ? self::median(array_values($values)) : 0.0;
    }

    /**
     * How long the cohort's tasks took in one window, as a percentage of their own
     * usual duration. Each task is measured against itself and the results combined
     * geometrically, so a task that takes a minute and one that takes a tenth of a
     * second count the same.
     */
    private static function typical(array $medians, array $baselines, array $cohort, int $i): ?float
    {
        $logs = [];
        foreach ($cohort as $task) {
            $value = $medians[$task][$i] ?? null;
            $base  = $baselines[$task] ?? 0.0;
            if ($value !== null && $value > 0 && $base > 0) {
                $logs[] = log($value / $base);
            }
        }
        return $logs ? 100 * exp(array_sum($logs) / count($logs)) : null;
    }

    /** @param list<float> $values */
    private static function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $middle = (int) floor((count($values) - 1) / 2);
        return count($values) % 2
            ? $values[$middle]
            : ($values[$middle] + $values[$middle + 1]) / 2;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    private static function renderNote(string $text): string
    {
        return self::css() . ProgressPanel::note($text);
    }

    private static function renderTable(array $summary): string
    {
        $h  = ProgressPanel::head(
            \safe(sprintf(\_m('Same %d task types in every row, across the whole install.'), count($summary['cohort']))),
            \_m('Lower is faster. A change is highlighted only when most tasks moved the same way.'),
        );
        $h .= ProgressPanel::tableOpen([
            [\_m('Running since'),      \_m('The day after the change was recorded.')],
            [\_m('Changed'),            \_m('What this window changed against the one above it.')],
            [\_m('Queue time per day'), \_m('Server time the whole queue spent per day. Real seconds, so it also moves when there is more work to do.')],
            [\_m('Typical task'),       \_m('How long the cohort\'s tasks took, as a percentage of their own usual duration. Free of how much work arrived.')],
            [\_m('Runs per day'),       \_m('Runs per day across the cohort, so the column above can be read against how busy the queue was.')],
            [\_m('Tasks faster'),       \_m('How many cohort tasks got quicker. This, not the percentage, says whether a move is real.')],
        ]);

        foreach (array_reverse($summary['rows'], true) as $i => $row) {
            $h .= self::renderRow($row, $i > 0 ? $summary['rows'][$i - 1]['interval'] : null);
        }

        return $h . ProgressPanel::tableClose();
    }

    private static function renderRow(array $row, ?array $previous): string
    {
        $interval = $row['interval'];
        $agree    = $row['agree'] ?? null;

        $h  = '<tr' . ($interval['short'] ? ' class="aa-ver-short"' : '') . '>';
        $h .= ProgressPanel::whenCells($interval, $previous, sprintf(
            \_m('%1$s to %2$s, %3$d days, %4$s runs'),
            date('Y-m-d', $interval['from']),
            date('Y-m-d', $interval['to'] - 1),
            $interval['days'],
            number_format($row['run_total'], 0, '.', ' '),
        ));

        // Real seconds: it answers "is the server doing less work", but it moves with
        // the amount of work too, so it never gets the significance highlight.
        $h .= '<td>' . \safe(self::formatSeconds($row['seconds']))
            . self::renderDelta($row['d_seconds'] ?? null, false)
            . '</td>';

        $h .= '<td>' . ($row['typical'] === null ? '<span class="aa-stat-zero">-</span>' : \safe(number_format($row['typical'], 0) . '%'))
            . self::renderDelta($row['d_typical'] ?? null, !$interval['short'] && $agree && ProgressPanel::isSignificant($agree))
            . '</td>';

        $h .= '<td>' . \safe(number_format($row['runs'], 0, '.', ' ')) . '</td>';
        $h .= ProgressPanel::agreeCell($agree);

        return $h . '</tr>';
    }

    /** The move against the window above, worded for the queue. */
    private static function renderDelta(?float $delta, bool $real): string
    {
        return ProgressPanel::delta(
            $delta,
            $real,
            \_m('Most tasks moved this way, so the change is larger than the usual noise.'),
            \_m('Within the usual noise of this measurement, or moved by how much work arrived.'),
        );
    }

    /** Which tasks actually moved, deferred: the table says whether the queue changed, this says where. */
    private static function renderBreakdown(array $summary): string
    {
        $last = count($summary['rows']) - 1;
        if ($last < 1) {
            return '';
        }

        $moves = [];
        foreach ($summary['cohort'] as $task) {
            $now  = $summary['medians'][$task][$last] ?? null;
            $prev = $summary['medians'][$task][$last - 1] ?? null;
            if ($now === null || $prev === null || $prev <= 0) {
                continue;
            }
            $moves[$task] = [(($now / $prev) - 1) * 100, $prev, $now];
        }
        if (!$moves) {
            return '';
        }
        // Biggest movers either way first - a task that halved matters as much as one that doubled.
        uasort($moves, static fn($a, $b) => abs($b[0]) <=> abs($a[0]));

        $h  = '<details class="aa-ver-trend">';
        $h .= '<summary>' . \safe(\_m('Which tasks moved, newest window against the one before')) . '</summary>';
        $h .= ProgressPanel::tableOpen([
            [\_m('Task')],
            [\_m('Before')],
            [\_m('After')],
            [\_m('Median change')],
        ]);
        foreach (array_slice($moves, 0, self::BREAKDOWN_ROWS, true) as $task => [$pct, $prev, $now]) {
            $class = abs($pct) < 10 ? 'aa-ver-delta--flat' : ($pct < 0 ? 'aa-ver-delta--good' : 'aa-ver-delta--bad');
            $h .= '<tr><td class="aa-ver-when">' . \safe($task) . '</td>'
                . '<td>' . \safe(self::formatSeconds($prev)) . '</td>'
                . '<td>' . \safe(self::formatSeconds($now)) . '</td>'
                . '<td><span class="aa-ver-delta ' . $class . '">' . \safe(sprintf('%+.0f%%', $pct)) . '</span></td></tr>';
        }
        $h .= ProgressPanel::tableClose();
        $h .= '<p class="aa-ver-axis">' . \safe(\_m('Median run of one task. Queue rows are kept for 40 days, so this cannot reach further back.'))
            . ' <a class="aa-stat-th-link" href="' . \safe(AA_Log::getAdminUrl('TOEXECUTE')) . '">'
            . \safe(\_m('Full task log')) . ' &raquo;</a></p>';
        $h .= '</details>';
        return $h;
    }

    /**
     * A duration at a precision that can be read against the one next to it. Queue
     * tasks span five orders of magnitude, from a housekeeping task that finishes in
     * a few milliseconds to an import that runs for an hour, and a fixed number of
     * decimals turns one end or the other into a column of identical values.
     */
    private static function formatSeconds(float $secs): string
    {
        if ($secs >= 3600) {
            return number_format($secs / 3600, 1) . ' h';
        }
        if ($secs >= 60) {
            return number_format($secs / 60, 1) . ' min';
        }
        if ($secs >= 1) {
            return number_format($secs, $secs < 10 ? 2 : 1) . ' s';
        }
        return number_format($secs * 1000, $secs < 0.01 ? 1 : 0) . ' ms';
    }

    /** The shared panel styling, plus the one rule this table needs of its own:
     *  a task name is a long identifier, not a date, so its cell has to wrap. */
    private static function css(): string
    {
        return ProgressPanel::css()
            . '<style>.aa-ver-table td.aa-ver-when{white-space:normal;max-width:32ch}</style>';
    }
}
