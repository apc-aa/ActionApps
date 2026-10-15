<?php

declare(strict_types=1);

namespace AA\Util;

use AA\IO\DB\DB_AA;
use AA\Later\HitcounterGroup;

/**
 * "Did what we shipped make the site faster?" - page speed compared between
 * the configuration changes ConfigTimeline reads out of the log.
 *
 * The measurement is built around one idea: compare the same pages to
 * themselves. A cohort of items with steady traffic in every window carries
 * every row of the table, so a quiet week, a new landing page or a crawler
 * discovering an archive cannot move the numbers. Without that, the mix of
 * pages measured changes from window to window and swamps the effect being
 * looked for - one item dropping out of an 18-item average moved a day by 16%
 * in testing, which is larger than most optimisations.
 *
 * What it can and cannot see, both worth knowing before reading the table:
 *
 *  - The clock is Hitcounter's, which starts at the request and stops when the
 *    page has been echoed. Storing the page cache, writing the log and the hit
 *    row, the whole admin interface and everything cron does all happen after
 *    it stops. See TaskProgress for the queue side.
 *  - Durations are stored as a 6-bit logarithmic index, one step being 18.6%,
 *    and days older than a week are averaged per day. An item's duration is the
 *    mean time of its hits, so a single slow render moves an item with few hits
 *    a lot; the cohort then averages the items' logs, so each page counts as one
 *    relative change. Averaging a cohort recovers the resolution the rounding
 *    costs, but the noise floor of a window-to-window comparison is still a few
 *    percent. The sign test in the last column, not the percentage, is what
 *    says whether a move is real.
 *  - Days grouped by an AA older than mid-October 2026 hold the mean of the
 *    logs, which runs lower than the mean time, so a window made of such days
 *    reads too fast against the later ones.
 *  - Within the last week hits keep their time, so windows start at the change
 *    itself. Each window is measured over its freshest whole days only
 *    (ConfigTimeline::measuredSpan()): pages are built faster at night than in
 *    the evening, and a window holding each hour equally often cancels that out.
 */
final class VersionProgress
{
    /** Days of history compared by default. */
    public const WINDOW_DAYS = 60;

    /** Items carrying the comparison. Large enough for the sign test to speak. */
    public const COHORT = 20;

    /** Windows shown, newest first. A busy stretch of development is about this many. */
    public const MAX_INTERVALS = 12;

    /** Rows a quiet install still gets: the period reaches back for them, up to MAX_DAYS. */
    public const MIN_ROWS = 4;

    /** How far back the period may reach for MIN_ROWS. Bounds the hit rows read. */
    public const MAX_DAYS = 120;

    /** Measured hits an item needs in a window (or a day of the curve) for its average to count. */
    public const MIN_HITS = 10;

    /** Below this many items the numbers are not worth showing. */
    private const MIN_COHORT = 3;

    /** The fewest items the sign test can call a move real with. @see ProgressPanel::isSignificant() */
    private const SIGN_TEST_MIN = 5;

    /** Cache states, as encoded by AA_Packtime: 0 = NC+M, 1 = NC+NM, 2 = C+M, 3 = C+NM. */
    private const STATE_UNCACHED = 0;
    private const STATE_CACHED   = 2;

    /**
     * The whole panel. Empty string when the install cannot answer the question
     * at all (no duration logging); a short explanation when it has the feature
     * but not the data yet.
     *
     * @param  array  $slice_ids  empty = whole AA, otherwise scoped to these slices
     * @param  int    $window_days  days of history to compare
     * @param  array  $base_qs    query parameters drill-down links should preserve
     */
    public static function render(array $slice_ids, int $window_days = self::WINDOW_DAYS, array $base_qs = []): string
    {
        if (!DB_AA::exists_column('hit_archive', 'duration')) {
            return '';   // no duration logging - the diagnostics panel above says so already
        }

        $now        = time();
        $exact_from = HitcounterGroup::exactSince($now);
        $changes    = ConfigTimeline::getChanges();
        $intervals  = array_slice(
            ConfigTimeline::buildIntervals($changes, self::periodStart($changes, $now, $window_days), $now, ConfigTimeline::MIN_SPAN, $exact_from),
            -self::MAX_INTERVALS,
        );
        $measured = array_values(array_filter($intervals, static fn($interval) => !$interval['short']));

        if (count($measured) < 2) {
            return self::renderNote(\_m('Not enough recorded configuration changes in this period to compare. AA logs one whenever its revision, the PHP version or the database version changes.'));
        }

        // The cohort is checked twice, and says the same thing both times: once on the
        // candidates, which spares a quiet install the month of hit rows read below,
        // and once on the items that survived the per-window traffic floor.
        $no_cohort = \_m('Not enough items with steady traffic through the whole period. A comparison needs the same pages measured in every window.');

        $cohort = self::getCohort($slice_ids, $measured[count($measured) - 1]);
        if (count($cohort) < self::MIN_COHORT) {
            return self::renderNote($no_cohort);
        }

        $summary = self::summarize(self::getItemHours($cohort, $intervals[0]['from'], $now), $intervals);
        if (count($summary['cohort']) < self::MIN_COHORT) {
            return self::renderNote($no_cohort);
        }

        return ProgressPanel::css()
             . self::renderTable($summary, $base_qs)
             . self::renderItems($summary)
             . self::renderTrend($summary);
    }

    /**
     * Where the compared period starts: $window_days back, or further when that
     * holds fewer than MIN_ROWS deployments. On a quiet install the last few
     * deployments are still the question, however long ago they were.
     *
     * @param list<array{time:int}> $changes  from ConfigTimeline::getChanges(), oldest first
     */
    public static function periodStart(array $changes, int $now, int $window_days): int
    {
        $from = ConfigTimeline::dayStart($now - ($window_days * ConfigTimeline::DAY));
        // Starting the day after the MIN_ROWS-th newest change makes its configuration
        // the oldest row, the baseline the newer ones are compared to.
        $oldest_row = $changes[count($changes) - self::MIN_ROWS] ?? $changes[0] ?? null;
        if ($oldest_row) {
            $from = min($from, ConfigTimeline::nextDayStart($oldest_row['time']));
        }
        return max($from, ConfigTimeline::dayStart($now - (self::MAX_DAYS * ConfigTimeline::DAY)));
    }

    /**
     * Items that can carry the comparison: busy in the newest measured window, and
     * still present in every older one. Candidates come from that one window only,
     * which keeps the expensive ranking query off the whole history.
     *
     * @return list<string> short ids
     */
    private static function getCohort(array $slice_ids, array $newest): array
    {
        return array_keys(HitcounterDisplay::getGlobalTotals($slice_ids, $newest['measure_from'], $newest['measure_to'], self::COHORT * 4));
    }

    /**
     * Per item and hour, hits and summed duration in seconds for each cache state.
     *
     * Hours rather than days so a window can start at the change itself. Only the
     * last week has them: hit_archive keeps older days as one row stamped at
     * midnight, which comes back here as that day's only hour.
     *
     * @param  list<string> $short_ids
     * @return array<string, array<int, array{h:array<int,int>,w:array<int,float>}>> item => hour start => counts
     */
    private static function getItemHours(array $short_ids, int $from, int $to): array
    {
        if (!$short_ids) {
            return [];
        }
        $cols = ['`id`', '`time` DIV 3600 AS `hour`'];
        foreach ([0, 1, 2, 3] as $state) {
            $cols[] = "SUM(CASE WHEN `duration` & 3 = $state THEN `hits` ELSE 0 END) AS `h$state`";
        }
        $seconds = AA_Packtime::sqlSeconds();
        foreach ([self::STATE_UNCACHED, self::STATE_CACHED] as $state) {
            $cols[] = "SUM(CASE WHEN `duration` & 3 = $state THEN `hits` * $seconds ELSE 0 END) AS `w$state`";
        }
        // One row per item and grouped day, or item and hour in the last week: the
        // cap is that count, here so a later change to the period or the cohort
        // cannot quietly turn an admin page into an unbounded read.
        $max_rows = self::COHORT * 4 * ((int) ceil(($to - $from) / ConfigTimeline::DAY) + 24 * (HitcounterGroup::EXACT_DAYS + 1));
        $rows     = DB_AA::select(
            [],
            'SELECT ' . implode(', ', $cols) . ' FROM `hit_archive`',
            [['id', $short_ids, 'i'], ['time', $from, '>='], ['time', $to, '<'], ['duration', 0, '<>']],
            null,
            ['id', 'hour'],
            $max_rows,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['id']][((int) $row['hour']) * 3600] = [
                'h' => [(int) $row['h0'], (int) $row['h1'], (int) $row['h2'], (int) $row['h3']],
                'w' => [self::STATE_UNCACHED => (float) $row['w0'], self::STATE_CACHED => (float) $row['w2']],
            ];
        }
        return $out;
    }

    /**
     * The numbers behind the panel. Pure: everything it needs is in its arguments.
     *
     * @param  array $item_hours  from getItemHours()
     * @param  array $intervals   from ConfigTimeline::buildIntervals()
     * @return array{rows:list<array<string,mixed>>,cohort:list<string>,cached_cohort:list<string>,items:array<string,array<int,array{0:int,1:int}>>,daily:array<string,mixed>}
     */
    public static function summarize(array $item_hours, array $intervals): array
    {
        $intervals = self::dropThinWindows(self::foldIntoIntervals($item_hours, $intervals), $intervals);
        // Folded again: a dropped window renumbers every window after it.
        $per_item = self::foldIntoIntervals($item_hours, $intervals);
        // A window too short to measure (the newest, just started) is listed without
        // numbers, so it must not decide who is in the cohort either.
        $count = count(array_filter($intervals, static fn($interval) => !$interval['short']));

        // The cohort is fixed for the whole table, so plain means are comparable
        // between rows: any item missing from any measured window is out.
        $cohort = $cached_cohort = [];
        foreach ($per_item as $sid => $windows) {
            if (count($windows) < $count) {
                continue;
            }
            $has_uncached = $has_cached = true;
            foreach ($windows as $window) {
                $has_uncached = $has_uncached && $window['h'][self::STATE_UNCACHED] >= self::MIN_HITS;
                $has_cached   = $has_cached   && $window['h'][self::STATE_CACHED] >= self::MIN_HITS;
            }
            if ($has_uncached) {
                $cohort[] = (string) $sid;
            }
            if ($has_uncached && $has_cached) {
                $cached_cohort[] = (string) $sid;
            }
        }

        // Each item's log time per window, once: the cohort mean and the sign
        // test below then provably read the same numbers.
        $nc_log = self::logPerWindow($per_item, self::STATE_UNCACHED);
        $c_log  = self::logPerWindow($per_item, self::STATE_CACHED);

        $rows = [];
        foreach ($intervals as $i => $interval) {
            $log_nc = self::mean($nc_log, $cohort, $i);
            $log_c  = self::mean($c_log, $cached_cohort, $i);
            $rows[] = [
                'interval'  => $interval,
                'log_nc'    => $log_nc,
                'log_c'     => $log_c,
                'ms_nc'     => self::logToMs($log_nc),
                'ms_c'      => self::logToMs($log_c),
                'cache_pct' => self::meanCachePct($per_item, $cohort, $i),
                'hits_nc'   => self::sumHits($per_item, $cohort, $i, self::STATE_UNCACHED),
                'hits_c'    => self::sumHits($per_item, $cached_cohort, $i, self::STATE_CACHED),
            ];
        }

        // Each window against the one before it: how big the move was, and how
        // many of the cohort's items agree with its direction.
        foreach ($rows as $i => $row) {
            if ($i === 0 || $row['interval']['short']) {
                continue;
            }
            $rows[$i]['d_nc']     = self::ratioPct($row['log_nc'], $rows[$i - 1]['log_nc']);
            $rows[$i]['d_c']      = self::ratioPct($row['log_c'], $rows[$i - 1]['log_c']);
            $rows[$i]['d_cache']  = ($row['cache_pct'] === null || $rows[$i - 1]['cache_pct'] === null)
                ? null
                : $row['cache_pct'] - $rows[$i - 1]['cache_pct'];
            $rows[$i]['agree_nc'] = ProgressPanel::agreement($nc_log, $cohort, $i);
            $rows[$i]['agree_c']  = ProgressPanel::agreement($c_log, $cached_cohort, $i);
        }

        // What each measured item rests on, so a reader can judge how far to trust a row.
        $items = [];
        foreach ($cohort as $sid) {
            foreach ($per_item[$sid] as $i => $window) {
                $items[$sid][$i] = [$window['h'][self::STATE_UNCACHED], $window['h'][self::STATE_CACHED]];
            }
        }

        return [
            'rows'          => $rows,
            'cohort'        => $cohort,
            'cached_cohort' => $cached_cohort,
            'items'         => $items,
            'daily'         => self::dailySeries(self::perDay($item_hours), $cohort, $cached_cohort),
        ];
    }

    /**
     * The windows this install has enough traffic to compare: the thinnest one is
     * taken out, again and again, until SIGN_TEST_MIN items are measured in every
     * window that is left, or only two are.
     *
     * Fifteen hours of a busy site are plenty and fifteen hours of a quiet one are
     * a handful of hits, so this is decided by the data, the way a too-short window
     * is decided by the clock - and handled the same way: its hits are left out
     * and its deployments are named in the next row. The newest window has no next
     * row, so it stays, listed without numbers and flagged `thin`.
     *
     * @param  array $per_item   from foldIntoIntervals() over $intervals
     * @param  array $intervals  from ConfigTimeline::buildIntervals()
     * @return list<array<string,mixed>> the intervals to report
     */
    private static function dropThinWindows(array $per_item, array $intervals): array
    {
        $kept = array_keys(array_filter($intervals, static fn($interval) => !$interval['short']));
        while (count($kept) > 2) {
            $speaking = array_fill_keys($kept, 0);   // window => items measured in it
            $cohort   = 0;
            foreach ($per_item as $windows) {
                $everywhere = true;
                foreach ($kept as $i) {
                    $measured       = ($windows[$i]['h'][self::STATE_UNCACHED] ?? 0) >= self::MIN_HITS;
                    $speaking[$i]  += $measured ? 1 : 0;
                    $everywhere     = $everywhere && $measured;
                }
                $cohort += $everywhere ? 1 : 0;
            }
            if ($cohort >= self::SIGN_TEST_MIN) {
                break;
            }
            asort($speaking);
            $thinnest = array_key_first($speaking);
            $kept     = array_values(array_filter($kept, static fn($i) => $i !== $thinnest));
        }

        $out     = [];
        $carried = [];
        $last    = count($intervals) - 1;
        foreach ($intervals as $i => $interval) {
            $dropped = !$interval['short'] && !in_array($i, $kept, true);
            if ($dropped && $i !== $last) {
                $carried = array_merge($carried, $interval['changes']);
                continue;
            }
            $interval['changes'] = array_merge($carried, $interval['changes']);
            $interval['thin']    = $dropped;
            $interval['short']   = $interval['short'] || $dropped;
            $carried             = [];
            $out[]               = $interval;
        }
        return $out;
    }

    /**
     * Item hours added up per local day, for the daily curve.
     *
     * @return array<string, array<string, array{h:array<int,int>,w:array<int,float>}>> item => Y-m-d => counts
     */
    private static function perDay(array $item_hours): array
    {
        $days = [];
        foreach ($item_hours as $sid => $hours) {
            foreach ($hours as $start => $counts) {
                $day              = date('Y-m-d', $start);
                $days[$sid][$day] = self::addCounts($days[$sid][$day] ?? null, $counts);
            }
        }
        return $days;
    }

    /** Two sets of hit counts added up; null stands for none yet. */
    private static function addCounts(?array $into, array $counts): array
    {
        $into ??= ['h' => [0, 0, 0, 0], 'w' => [self::STATE_UNCACHED => 0.0, self::STATE_CACHED => 0.0]];
        foreach ($counts['h'] as $state => $hits) {
            $into['h'][$state] += $hits;
        }
        foreach ($counts['w'] as $state => $weight) {
            $into['w'][$state] += $weight;
        }
        return $into;
    }

    /**
     * Item hours added up per measurement window. Hours in no window are dropped
     * here: a change day in grouped data, the hour a change cut through, and the
     * whole of a window too short to measure yet.
     *
     * @return array<string, array<int, array{h:array<int,int>,w:array<int,float>}>> item => window index => counts
     */
    private static function foldIntoIntervals(array $item_hours, array $intervals): array
    {
        $per_item = [];
        foreach ($item_hours as $sid => $hours) {
            foreach ($hours as $start => $counts) {
                $i = ConfigTimeline::windowOf($start, $intervals, 3600);
                if ($i !== null && !$intervals[$i]['short']) {
                    $per_item[$sid][$i] = self::addCounts($per_item[$sid][$i] ?? null, $counts);
                }
            }
        }
        return $per_item;
    }

    /**
     * Each item's log time in each window, for one cache state.
     *
     * @param  array $per_item  from foldIntoIntervals()
     * @return array<string,array<int,?float>> item => window index => log time, null where too quiet to average
     */
    private static function logPerWindow(array $per_item, int $state): array
    {
        $log = [];
        foreach ($per_item as $sid => $windows) {
            foreach ($windows as $i => $window) {
                $log[$sid][$i] = self::itemLog($window, $state);
            }
        }
        return $log;
    }

    /**
     * Cohort mean of a per-item measurement in one window.
     *
     * @param array<string,array<int,?float>> $values  from logPerWindow()
     */
    private static function mean(array $values, array $cohort, int $i): ?float
    {
        $found = [];
        foreach ($cohort as $sid) {
            $value = $values[$sid][$i] ?? null;
            if ($value !== null) {
                $found[] = $value;
            }
        }
        return $found ? array_sum($found) / count($found) : null;
    }

    /**
     * Natural log of one item's mean time over a span, or null when there is not
     * enough of it to average. The stored time is a coarse logarithmic byte, so a
     * handful of hits is a quantised sample, not a measurement.
     */
    private static function itemLog(?array $window, int $state, int $min_hits = 1): ?float
    {
        if (!$window || $window['h'][$state] < $min_hits) {
            return null;
        }
        return log($window['w'][$state] / $window['h'][$state]);
    }

    /** Cohort mean of the per-item share of hits served from the page cache. */
    private static function meanCachePct(array $per_item, array $cohort, int $i): ?float
    {
        $values = [];
        foreach ($cohort as $sid) {
            $window = $per_item[$sid][$i] ?? null;
            $total  = $window ? array_sum($window['h']) : 0;
            if ($total) {
                $values[] = (($window['h'][2] + $window['h'][3]) / $total) * 100;
            }
        }
        return $values ? array_sum($values) / count($values) : null;
    }

    /** Measured hits in one cache state the window rests on, across the cohort. */
    private static function sumHits(array $per_item, array $cohort, int $i, int $state): int
    {
        $sum = 0;
        foreach ($cohort as $sid) {
            $sum += $per_item[$sid][$i]['h'][$state] ?? 0;
        }
        return $sum;
    }

    /**
     * The daily curve behind the table, as a percentage of each item's own
     * average over the whole period. Normalising per item is what lets a day
     * where one item is missing sit next to a day where it is not.
     *
     * @return array{labels:list<string>,nc:list<?float>,c:list<?float>}
     */
    private static function dailySeries(array $item_days, array $cohort, array $cached_cohort): array
    {
        $labels = [];
        foreach ($item_days as $days) {
            foreach (array_keys($days) as $day) {
                $labels[$day] = true;
            }
        }
        $labels = array_keys($labels);
        sort($labels);

        return [
            'labels' => $labels,
            'nc'     => self::relativeSeries($item_days, $cohort, $labels, self::STATE_UNCACHED),
            'c'      => self::relativeSeries($item_days, $cached_cohort, $labels, self::STATE_CACHED),
        ];
    }

    /** @return list<?float> */
    private static function relativeSeries(array $item_days, array $cohort, array $labels, int $state): array
    {
        // Each item's own average first, so the series measures change, not which
        // pages happened to be measured that day.
        $baseline = [];
        foreach ($cohort as $sid) {
            $values = [];
            foreach ($item_days[$sid] ?? [] as $counts) {
                $value = self::itemLog($counts, $state, self::MIN_HITS);
                if ($value !== null) {
                    $values[] = $value;
                }
            }
            if ($values) {
                $baseline[$sid] = array_sum($values) / count($values);
            }
        }

        $series = [];
        foreach ($labels as $day) {
            $deltas = [];
            foreach ($cohort as $sid) {
                $value = self::itemLog($item_days[$sid][$day] ?? null, $state, self::MIN_HITS);
                if ($value !== null && isset($baseline[$sid])) {
                    $deltas[] = $value - $baseline[$sid];
                }
            }
            $series[] = $deltas
                ? round(100 * exp(array_sum($deltas) / count($deltas)), 1)
                : null;
        }
        return $series;
    }

    /** Log time in seconds to milliseconds. */
    private static function logToMs(?float $log): ?float
    {
        return $log === null ? null : exp($log) * 1000;
    }

    /** Change between two log times, in percent. Negative is faster. */
    private static function ratioPct(?float $now, ?float $before): ?float
    {
        if ($now === null || $before === null) {
            return null;
        }
        return (exp($now - $before) - 1) * 100;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /** A short explanation in place of the panel, for the states where there is nothing to compare yet. */
    private static function renderNote(string $text): string
    {
        return ProgressPanel::css() . ProgressPanel::note($text);
    }

    private static function renderTable(array $summary, array $base_qs): string
    {
        $cohort_n = count($summary['cohort']);
        $cached_n = count($summary['cached_cohort']);

        // The cohort is the whole basis of the table, so it is one click from being
        // listed item by item in the per-day table further down the page.
        $cohort_href = \get_admin_url('aa_statistics.php', $base_qs + [
            'item_sort'  => 'exact',
            'items_code' => implode('-', $summary['cohort']),
            'item_count' => (string) $cohort_n,
            'show'       => '1',
        ]);

        $h  = ProgressPanel::head(
            '<a class="aa-stat-th-link" href="' . \safe($cohort_href) . '" title="'
                . \safe(\_m('Show these items one by one in the table below.')) . '">'
                . \safe(sprintf(\_m('Same %1$d items in every row, %2$d of them with page-cache traffic.'), $cohort_n, $cached_n))
                . '</a>',
            \_m('Lower is faster. A change is highlighted only when most items moved the same way.'),
        );
        $h .= ProgressPanel::tableOpen([
            [\_m('Running since'),   \_m('When this configuration started. Within the last week that is the change itself. Older hit data is stored per whole day, so there the change day is left out and the window starts the next day. Only the freshest whole days of each window are measured, so night and evening weigh the same in every row.')],
            [\_m('Changed'),         \_m('What this window changed against the one above it.')],
            [\_m('Uncached (NC+M)'), \_m('Average time to build a page that was not in the page cache. This is the number AA code changes move.')],
            [\_m('Cached (C+M)'),    \_m('Average time to serve a page out of the page cache.')],
            [\_m('Cache %'),         \_m('Share of hits served from the page cache, averaged over the cohort.')],
            [\_m('Hits'),            \_m('Measured hits the row rests on: uncached (NC+M) / cached (C+M), over the items of each column. The list of items is below the table.')],
            [\_m('Items faster'),    \_m('How many cohort items got faster, out of those measurable in both windows. This, not the percentage, says whether a move is real.')],
        ]);

        // Newest first: the question is almost always about what was shipped last.
        foreach (array_reverse($summary['rows'], true) as $i => $row) {
            $h .= self::renderRow($row, $i > 0 ? $summary['rows'][$i - 1]['interval'] : null);
        }

        return $h . ProgressPanel::tableClose();
    }

    private static function renderRow(array $row, ?array $previous): string
    {
        $interval = $row['interval'];

        $h  = '<tr' . ($interval['short'] ? ' class="aa-ver-short"' : '') . '>';
        $h .= ProgressPanel::whenCells($interval, $previous, ProgressPanel::spanTitle($interval));
        if ($interval['short']) {
            $unmeasured = empty($interval['thin'])
                ? ProgressPanel::tooEarlyCell(5)
                : '<td class="aa-ver-early" colspan="5">' . \safe(\_m('Too few hits in this window yet to compare it.')) . '</td>';
            return $h . $unmeasured . '</tr>';
        }
        $h .= self::renderMsCell($row['ms_nc'], $row['d_nc'] ?? null, $row['agree_nc'] ?? null);
        $h .= self::renderMsCell($row['ms_c'], $row['d_c'] ?? null, $row['agree_c'] ?? null);
        $h .= self::renderCacheCell($row['cache_pct'], $row['d_cache'] ?? null);
        $h .= '<td class="aa-ver-hits">' . \safe(self::hitsLabel($row['hits_nc'], $row['hits_c'])) . '</td>';
        $h .= ProgressPanel::agreeCell($row['agree_nc'] ?? null);

        return $h . '</tr>';
    }

    /** A duration cell: the measured time, and the move against the window above it. */
    private static function renderMsCell(?float $ms, ?float $delta, ?array $agreement): string
    {
        if ($ms === null) {
            return '<td class="aa-stat-zero">-</td>';
        }
        return '<td>' . \safe(($ms < 10 ? number_format($ms, 1) : number_format($ms, 0)) . ' ms')
            . ProgressPanel::delta(
                $delta,
                $agreement && ProgressPanel::isSignificant($agreement),
                \_m('Most items moved this way, so the change is larger than the usual noise.'),
                \_m('Within the usual noise of this measurement.'),
            )
            . '</td>';
    }

    /**
     * Share of hits served from the page cache. Its move is in percentage points and
     * never highlighted: the cache hit rate follows traffic, not what was deployed.
     */
    private static function renderCacheCell(?float $pct, ?float $delta): string
    {
        if ($pct === null) {
            return '<td class="aa-stat-zero">-</td>';
        }
        $h = '<td class="aa-stat-bar aa-ver-cache" style="--pct:' . (int) round($pct) . '">'
           . \safe(number_format($pct, 0) . '%');
        if ($delta !== null) {
            $h .= '<span class="aa-ver-delta aa-ver-delta--flat" title="'
                . \safe(\_m('Change in percentage points against the window above.')) . '">'
                . \safe(sprintf('%+.0f', $delta)) . '</span>';
        }
        return $h . '</td>';
    }

    /** "15 139 / 1 180": uncached / cached hits, the way the Hits column and the item list print them. */
    private static function hitsLabel(int $uncached, int $cached): string
    {
        return number_format($uncached, 0, '.', ' ') . ' / ' . number_format($cached, 0, '.', ' ');
    }

    /**
     * Which items the table rests on and how many hits each brought to each window,
     * deferred. A row resting on a few hundred hits of three pages says less than
     * one resting on thousands of twenty, and only this list shows which it is.
     */
    private static function renderItems(array $summary): string
    {
        $measured = array_filter($summary['rows'], static fn($row) => !$row['interval']['short']);
        $windows  = array_reverse(array_keys($measured));   // newest first, as in the table
        $items    = $summary['items'];
        // Busiest first: the items that weigh most in every average lead the list.
        uasort($items, static fn($a, $b) => array_sum(array_column($b, 0)) <=> array_sum(array_column($a, 0)));

        \AA_Items::preload(new \zids(array_keys($items), 's'));
        $meta = HitcounterDisplay::buildItemMeta(array_map('strval', array_keys($items)));

        $h  = '<details class="aa-ver-trend">';
        $h .= '<summary>' . \safe(sprintf(\_m('Measured items and their hits (%d)'), count($items))) . '</summary>';
        $h .= '<p class="aa-ver-axis">' . \safe(sprintf(
            \_m('Hits in the measured part of each window, uncached / cached. An item is compared only with at least %d uncached hits in every window; its cached time only with as many cached hits too.'),
            self::MIN_HITS,
        )) . '</p>';
        $columns = [[\_m('Item')]];
        foreach ($windows as $i) {
            $interval  = $summary['rows'][$i]['interval'];
            $columns[] = [date($interval['exact'] ? 'm-d H:i' : 'm-d', $interval['from']), ProgressPanel::spanTitle($interval)];
        }
        $h .= ProgressPanel::tableOpen($columns);
        foreach ($items as $sid => $hits) {
            $sid      = (string) $sid;
            $headline = trim(\AA::Un("{item:$sid:_#HEADLINE}"));
            $h .= '<tr><td class="aa-ver-item">'
                . HitcounterDisplay::renderItemLink($sid, Txt::shorten($headline, 40, '…'), $headline, $meta[$sid] ?? [], 'aa-stat-tip-name')
                . '</td>';
            foreach ($windows as $i) {
                $h .= '<td>' . \safe(self::hitsLabel(...($hits[$i] ?? [0, 0]))) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= ProgressPanel::tableClose();
        return $h . '</details>';
    }

    /** The daily curve, deferred: the table answers the question, this shows the shape. */
    private static function renderTrend(array $summary): string
    {
        $daily = $summary['daily'];
        if (count($daily['labels']) < 4) {
            return '';
        }

        $datasets = [
            ['label' => \_m('Uncached (NC+M)'), 'data' => $daily['nc'], 'color' => '#dd6644', 'dash' => [], 'width' => 2],
            ['label' => \_m('Cached (C+M)'),    'data' => $daily['c'],  'color' => '#7fb069', 'dash' => [], 'width' => 2],
        ];
        if ($marks = self::changeMarks($summary['rows'], $daily)) {
            $datasets[] = $marks;
        }

        $h  = '<details class="aa-ver-trend">';
        $h .= '<summary>' . \safe(\_m('Day by day, relative to each item\'s own average')) . '</summary>';
        $h .= '<p class="aa-ver-axis">' . \safe(\_m('100% is the item\'s own average over the whole period. Below 100 is faster than that average.')) . '</p>';
        // The series varies around 100, so an axis anchored at zero would flatten every
        // real move into a straight line. The weekday is on the label: a quiet weekend
        // reads as a faster site, and the label is where the reader can see it was one.
        $labels = array_map(static fn(string $day) => date('D m-d', (int) strtotime($day)), $daily['labels']);
        $h .= HitcounterDisplay::getGraph($datasets, $labels, 'line', 'aa-stat-chart-pct', ['beginAtZero' => false]);
        $h .= '</details>';
        return $h;
    }

    /**
     * A marker per configuration change, as one more dataset, or null when there is
     * nothing plotted to hang them on.
     *
     * The markers sit in a row along the top of the plot rather than on a curve: one
     * placed on the line lands underneath it and is unreadable exactly where the
     * curve is busiest, which is where a step needs reading. The row doubles as a
     * deployment timeline over the chart.
     *
     * @param  array $rows   from summarize(), one per window, oldest first
     * @param  array $daily  from dailySeries()
     * @return array<string,mixed>|null
     */
    private static function changeMarks(array $rows, array $daily): ?array
    {
        $plotted = array_filter(array_merge($daily['nc'], $daily['c']), static fn($v) => $v !== null);
        if (!$plotted) {
            return null;
        }

        $top    = max($plotted);
        $marks  = array_fill(0, count($daily['labels']), null);
        $marked = false;
        foreach ($rows as $i => $row) {
            if (!$i) {
                continue;   // the leading window opens the plot, it changed nothing inside it
            }
            $pos = array_search(date('Y-m-d', $row['interval']['from']), $daily['labels'], true);
            if ($pos !== false) {
                $marks[$pos] = $top;
                $marked      = true;
            }
        }
        if (!$marked) {
            return null;
        }

        return [
            'label'       => \_m('Configuration change'),
            'data'        => $marks,
            'color'       => '#1f1f1f',
            'dash'        => [],
            'width'       => 0,
            'showLine'    => false,
            'pointStyle'  => 'triangle',
            'pointRadius' => 6,
            'order'       => -1,   // Chart.js draws sorted datasets back to front
        ];
    }
}
