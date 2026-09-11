<?php

declare(strict_types=1);

namespace AA\Util;

use AA\IO\DB\DB_AA;

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
 *  - Durations are stored as a 6-bit logarithmic index, one step being 18.7%,
 *    and days older than about eight are averaged per day. Averaging a cohort
 *    recovers the resolution the rounding costs, but the noise floor of a
 *    window-to-window comparison is still a few percent. The sign test in the
 *    last column, not the percentage, is what says whether a move is real.
 */
final class VersionProgress
{
    /** Days of history compared by default. */
    public const WINDOW_DAYS = 60;

    /** Items carrying the comparison. Large enough for the sign test to speak. */
    public const COHORT = 20;

    /** Windows shown, newest first. */
    public const MAX_INTERVALS = 8;

    /** Measured hits an item needs in every window to join the cohort. */
    public const MIN_HITS = 20;

    /** Measured hits an item-day needs before it may move the daily curve. */
    public const MIN_DAY_HITS = 10;

    /** Below this many items the numbers are not worth showing. */
    private const MIN_COHORT = 3;

    /** One duration index step, as a ratio. @see LogarithmIndex::calculateStep() */
    private const STEP = 1.18728;

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

        $now       = time();
        $from      = ConfigTimeline::dayStart($now - ($window_days * ConfigTimeline::DAY));
        $intervals = array_slice(ConfigTimeline::buildIntervals(ConfigTimeline::getChanges(), $from, $now), -self::MAX_INTERVALS);

        if (count($intervals) < 2) {
            return self::renderNote(\_m('Not enough recorded configuration changes in this period to compare. AA logs one whenever its revision, the PHP version or the database version changes.'));
        }

        // The cohort is checked twice, and says the same thing both times: once on the
        // candidates, which spares a quiet install the month of hit rows read below,
        // and once on the items that survived the per-window traffic floor.
        $no_cohort = \_m('Not enough items with steady traffic through the whole period. A comparison needs the same pages measured in every window.');

        $cohort = self::getCohort($slice_ids, $intervals);
        if (count($cohort) < self::MIN_COHORT) {
            return self::renderNote($no_cohort);
        }

        $summary = self::summarize(self::getItemDays($cohort, $from, $now), $intervals);
        if (count($summary['cohort']) < self::MIN_COHORT) {
            return self::renderNote($no_cohort);
        }

        return ProgressPanel::css()
             . self::renderTable($summary, $base_qs)
             . self::renderTrend($summary);
    }

    /**
     * Items that can carry the comparison: busy in the newest window, and still
     * present in every older one. Candidates come from the newest window only,
     * which keeps the expensive ranking query off the whole history.
     *
     * @return list<string> short ids
     */
    private static function getCohort(array $slice_ids, array $intervals): array
    {
        $newest = $intervals[count($intervals) - 1];
        return array_keys(HitcounterDisplay::getGlobalTotals($slice_ids, $newest['from'], $newest['to'], self::COHORT * 4));
    }

    /**
     * Per item and day, hits and summed duration index for each cache state.
     *
     * @param  list<string> $short_ids
     * @return array<string, array<string, array{h:array<int,int>,w:array<int,int>}>> item => day => counts
     */
    private static function getItemDays(array $short_ids, int $from, int $to): array
    {
        if (!$short_ids) {
            return [];
        }
        $cols = ['`id`', "DATE_FORMAT(FROM_UNIXTIME(`time`), '%Y-%m-%d') AS `day`"];
        foreach ([0, 1, 2, 3] as $state) {
            $cols[] = "SUM(CASE WHEN `duration` & 3 = $state THEN `hits` ELSE 0 END) AS `h$state`";
        }
        foreach ([self::STATE_UNCACHED, self::STATE_CACHED] as $state) {
            $cols[] = "SUM(CASE WHEN `duration` & 3 = $state THEN (`duration` >> 2) * `hits` ELSE 0 END) AS `w$state`";
        }
        $rows = DB_AA::select(
            [],
            'SELECT ' . implode(', ', $cols) . ' FROM `hit_archive`',
            [['id', $short_ids, 'i'], ['time', $from, '>='], ['time', $to, '<'], ['duration', 0, '<>']],
            null,
            ['id', 'day'],
            // One row per item-day, so the cohort size and the window bound this
            // already. The cap is here so a later change to either cannot quietly
            // turn an admin page into an unbounded read.
            self::COHORT * 4 * self::WINDOW_DAYS,
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['id']][(string) $row['day']] = [
                'h' => [(int) $row['h0'], (int) $row['h1'], (int) $row['h2'], (int) $row['h3']],
                'w' => [self::STATE_UNCACHED => (int) $row['w0'], self::STATE_CACHED => (int) $row['w2']],
            ];
        }
        return $out;
    }

    /**
     * The numbers behind the panel. Pure: everything it needs is in its arguments.
     *
     * @param  array $item_days  from getItemDays()
     * @param  array $intervals  from ConfigTimeline::buildIntervals()
     * @return array{rows:list<array<string,mixed>>,cohort:list<string>,cached_cohort:list<string>,daily:array<string,mixed>}
     */
    public static function summarize(array $item_days, array $intervals): array
    {
        $per_item = self::foldIntoIntervals($item_days, $intervals);
        $count    = count($intervals);

        // The cohort is fixed for the whole table, so plain means are comparable
        // between rows: any item missing from any window is out.
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

        // Each item's duration index per window, once: the cohort mean and the sign
        // test below then provably read the same numbers.
        $nc_index = self::indexPerWindow($per_item, self::STATE_UNCACHED);
        $c_index  = self::indexPerWindow($per_item, self::STATE_CACHED);

        $rows = [];
        foreach ($intervals as $i => $interval) {
            $idx_nc = self::mean($nc_index, $cohort, $i);
            $idx_c  = self::mean($c_index, $cached_cohort, $i);
            $rows[] = [
                'interval'  => $interval,
                'idx_nc'    => $idx_nc,
                'idx_c'     => $idx_c,
                'ms_nc'     => self::indexToMs($idx_nc),
                'ms_c'      => self::indexToMs($idx_c),
                'cache_pct' => self::meanCachePct($per_item, $cohort, $i),
                'hits'      => self::sumHits($per_item, $cohort, $i),
            ];
        }

        // Each window against the one before it: how big the move was, and how
        // many of the cohort's items agree with its direction.
        foreach ($rows as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $rows[$i]['d_nc']     = self::ratioPct($row['idx_nc'], $rows[$i - 1]['idx_nc']);
            $rows[$i]['d_c']      = self::ratioPct($row['idx_c'], $rows[$i - 1]['idx_c']);
            $rows[$i]['d_cache']  = ($row['cache_pct'] === null || $rows[$i - 1]['cache_pct'] === null)
                ? null
                : $row['cache_pct'] - $rows[$i - 1]['cache_pct'];
            $rows[$i]['agree_nc'] = ProgressPanel::agreement($nc_index, $cohort, $i);
            $rows[$i]['agree_c']  = ProgressPanel::agreement($c_index, $cached_cohort, $i);
        }

        return [
            'rows'          => $rows,
            'cohort'        => $cohort,
            'cached_cohort' => $cached_cohort,
            'daily'         => self::dailySeries($item_days, $cohort, $cached_cohort),
        ];
    }

    /**
     * Item days added up per measurement window. Days outside every window
     * (the configuration-change days) are dropped here.
     *
     * @return array<string, array<int, array{h:array<int,int>,w:array<int,int>}>> item => window index => counts
     */
    private static function foldIntoIntervals(array $item_days, array $intervals): array
    {
        $per_item = [];
        foreach ($item_days as $sid => $days) {
            foreach ($days as $day => $counts) {
                $i = ConfigTimeline::windowOf((int) strtotime($day . ' 00:00:00'), $intervals);
                if ($i === null) {
                    continue;
                }
                if (!isset($per_item[$sid][$i])) {
                    $per_item[$sid][$i] = ['h' => [0, 0, 0, 0], 'w' => [self::STATE_UNCACHED => 0, self::STATE_CACHED => 0]];
                }
                foreach ($counts['h'] as $state => $hits) {
                    $per_item[$sid][$i]['h'][$state] += $hits;
                }
                foreach ($counts['w'] as $state => $weight) {
                    $per_item[$sid][$i]['w'][$state] += $weight;
                }
            }
        }
        return $per_item;
    }

    /**
     * Each item's hit-weighted duration index in each window, for one cache state.
     *
     * @param  array $per_item  from foldIntoIntervals()
     * @return array<string,array<int,?float>> item => window index => index, null where too quiet to average
     */
    private static function indexPerWindow(array $per_item, int $state): array
    {
        $index = [];
        foreach ($per_item as $sid => $windows) {
            foreach ($windows as $i => $window) {
                $index[$sid][$i] = self::itemIndex($window, $state);
            }
        }
        return $index;
    }

    /**
     * Cohort mean of a per-item measurement in one window.
     *
     * @param array<string,array<int,?float>> $values  from indexPerWindow()
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
     * One item's hit-weighted duration index over a span, or null when there is
     * not enough of it to average. The stored index is a coarse logarithmic byte,
     * so a handful of hits is a quantised sample, not a measurement.
     */
    private static function itemIndex(?array $window, int $state, int $min_hits = 1): ?float
    {
        if (!$window || $window['h'][$state] < $min_hits) {
            return null;
        }
        return $window['w'][$state] / $window['h'][$state];
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

    /** Measured hits the window rests on, across the cohort. */
    private static function sumHits(array $per_item, array $cohort, int $i): int
    {
        $sum = 0;
        foreach ($cohort as $sid) {
            $sum += array_sum($per_item[$sid][$i]['h'] ?? []);
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
                $value = self::itemIndex($counts, $state, self::MIN_DAY_HITS);
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
                $value = self::itemIndex($item_days[$sid][$day] ?? null, $state, self::MIN_DAY_HITS);
                if ($value !== null && isset($baseline[$sid])) {
                    $deltas[] = $value - $baseline[$sid];
                }
            }
            $series[] = $deltas
                ? round(100 * (self::STEP ** (array_sum($deltas) / count($deltas))), 1)
                : null;
        }
        return $series;
    }

    /** Duration index to milliseconds. The index is logarithmic; see AA_Packtime. */
    private static function indexToMs(?float $index): ?float
    {
        return $index === null ? null : (new LogarithmIndex(.002, 80, 63))->index2float($index) * 1000;
    }

    /** Change between two duration indexes, in percent. Negative is faster. */
    private static function ratioPct(?float $now, ?float $before): ?float
    {
        if ($now === null || $before === null) {
            return null;
        }
        return ((self::STEP ** ($now - $before)) - 1) * 100;
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
            [\_m('Running since'),   \_m('The day after the change was recorded. The change day itself is left out, because older hit data is stored per whole day.')],
            [\_m('Changed'),         \_m('What this window changed against the one above it.')],
            [\_m('Uncached (NC+M)'), \_m('Average time to build a page that was not in the page cache. This is the number AA code changes move.')],
            [\_m('Cached (C+M)'),    \_m('Average time to serve a page out of the page cache.')],
            [\_m('Cache %'),         \_m('Share of hits served from the page cache, averaged over the cohort.')],
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
        $h .= ProgressPanel::whenCells($interval, $previous, sprintf(
            \_m('%1$s to %2$s, %3$d days, %4$s measured hits'),
            date('Y-m-d', $interval['from']),
            date('Y-m-d', $interval['to'] - 1),
            $interval['days'],
            number_format($row['hits'], 0, '.', ' '),
        ));
        $h .= self::renderMsCell($row['ms_nc'], $row['d_nc'] ?? null, $row['agree_nc'] ?? null, $interval['short']);
        $h .= self::renderMsCell($row['ms_c'], $row['d_c'] ?? null, $row['agree_c'] ?? null, $interval['short']);
        $h .= self::renderCacheCell($row['cache_pct'], $row['d_cache'] ?? null);
        $h .= ProgressPanel::agreeCell($row['agree_nc'] ?? null);

        return $h . '</tr>';
    }

    /** A duration cell: the measured time, and the move against the window above it. */
    private static function renderMsCell(?float $ms, ?float $delta, ?array $agreement, bool $short): string
    {
        if ($ms === null) {
            return '<td class="aa-stat-zero">-</td>';
        }
        return '<td>' . \safe(($ms < 10 ? number_format($ms, 1) : number_format($ms, 0)) . ' ms')
            . ProgressPanel::delta(
                $delta,
                !$short && $agreement && ProgressPanel::isSignificant($agreement),
                \_m('Most items moved this way, so the change is larger than the usual noise.'),
                \_m('Within the usual noise of this measurement, or too few days to tell.'),
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
        // real move into a straight line.
        $h .= HitcounterDisplay::getGraph($datasets, $daily['labels'], 'line', 'aa-stat-chart-pct', ['beginAtZero' => false]);
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
