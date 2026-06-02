<?php

namespace AA\Util;

use AA\IO\DB\DB_AA;
use AA\Optimize\AddDurationLog;
use AA\Util\Txt;

/**
 * Display-side helpers for hitcounter data — used by admin/aa_statistics.php
 * and by AA_Stringexpand_Hitcounter::expand() ({hitcounter:…} template, which is
 * now a thin shim that delegates here).
 *
 * Capture-side concerns (Hitcounter::hit, AA_Packtime) live in Hitcounter.php.
 *
 * Public surface:
 *   - getGlobalTotals()        — AA-wide (or per-slice) per-item aggregates
 *   - periodToRange()          — period-label → [from, to] timestamps
 *   - renderDiagnosticsPanel() — top-of-page diagnostics widget (ranked top-N cards)
 *   - renderTable()            — per-day stats table + ChartJS canvases for selected items
 *   - countStats()             — per-(day, item) bucketed aggregates from hit_archive
 */
class HitcounterDisplay
{
    /**
     * AA-wide (or per-slice) per-item totals over a time window.
     * Returns [short_id => totals] where totals = {hits, bucket_sum, cache_hits, wasted, ms_x_hits, long_id}.
     *
     * @param  array  $slice_ids  empty = all AA, otherwise filters by item.slice_id
     * @param  int    $from       unix timestamp lower bound (inclusive); 0 = no lower bound
     * @param  int    $to         unix timestamp upper bound (exclusive); 0 = no upper bound
     * @param  int    $limit      cap for safety (default 1000)
     */
    public static function getGlobalTotals(array $slice_ids, int $from, int $to, int $limit = 1000): array
    {
        // Don't run unbounded scans on the multi-million-row hit_archive — require at least one bound.
        if ($from === 0 && $to === 0) {
            return [];
        }
        // This aggregate is entirely about the optional 'duration' column (AddDurationLog); without
        // it there are no measured rows to rank, so skip rather than reference a missing column.
        if (!DB_AA::exists_column('hit_archive', 'duration')) {
            return [];
        }
        $conds = [['hit_archive.duration', 0, '<>']];
        if ($from) {
            $conds[] = ['hit_archive.time', $from, '>='];
        }
        if ($to) {
            $conds[] = ['hit_archive.time', $to, '<'];
        }
        if ($slice_ids) {
            $conds[] = ['item.slice_id', $slice_ids, 'l'];
        }

        $sql = 'SELECT
            LOWER(HEX(`item`.`id`))       AS long_id,
            `hit_archive`.`id`            AS short_id,
            LOWER(HEX(`item`.`slice_id`)) AS slice_id,
            SUM(`hits`)                   AS total_hits,
            SUM(CASE WHEN `duration` & 3 = 0 THEN `hits` ELSE 0 END) AS h0,
            SUM(CASE WHEN `duration` & 3 = 1 THEN `hits` ELSE 0 END) AS h1,
            SUM(CASE WHEN `duration` & 3 = 2 THEN `hits` ELSE 0 END) AS h2,
            SUM(CASE WHEN `duration` & 3 = 3 THEN `hits` ELSE 0 END) AS h3,
            SUM(CASE WHEN `duration` & 3 = 0 THEN (`duration` >> 2) * `hits` ELSE 0 END) AS w0,
            SUM(CASE WHEN `duration` & 3 = 1 THEN (`duration` >> 2) * `hits` ELSE 0 END) AS w1,
            SUM(CASE WHEN `duration` & 3 = 2 THEN (`duration` >> 2) * `hits` ELSE 0 END) AS w2,
            SUM(CASE WHEN `duration` & 3 = 3 THEN (`duration` >> 2) * `hits` ELSE 0 END) AS w3
        FROM `hit_archive` INNER JOIN `item` ON `hit_archive`.`id` = `item`.`short_id`';

        $rows = DB_AA::select([], $sql, $conds, ['total_hits-'], ['hit_archive.id'], $limit);

        $timex  = new LogarithmIndex(.002, 80, 63);
        $totals = [];
        foreach ($rows as $r) {
            $hits = (int) $r['total_hits'];
            if ($hits <= 0) {
                continue;
            }
            $bucket_sum = (int) $r['h0'] + (int) $r['h1'] + (int) $r['h2'] + (int) $r['h3'];
            $ms_x_hits  = 0.0;
            for ($b = 0; $b < 4; $b++) {
                $bh = (int) $r["h$b"];
                $bw = (int) $r["w$b"];
                if ($bh > 0) {
                    $avg_ms_bucket = $timex->index2float($bw / $bh) * 1000;
                    $ms_x_hits     += $avg_ms_bucket * $bh;
                }
            }
            $totals[(string) $r['short_id']] = [
                'hits'       => $hits,
                'bucket_sum' => $bucket_sum,
                'cache_hits' => (int) $r['h2'] + (int) $r['h3'],
                'wasted'     => (int) $r['h1'],
                'ms_x_hits'  => $ms_x_hits,
                'long_id'    => $r['long_id'],
                'slice_id'   => $r['slice_id'],
            ];
        }
        return $totals;
    }

    /**
     * Compact lifetime stats for ONE item (admin/itemstats.php + the Item-info "Reach" box).
     * A single indexed query on hit_archive (id is the short_id). total/recent hit counts use
     * every row; the duration/cache figures use only measured rows (duration <> 0), matching
     * getGlobalTotals' convention.
     *
     * @param  int  $short_id  item.short_id
     * @return array{}|array{total_hits:int,hits_7d:int,hits_30d:int,first_t:int,last_t:int,measured_hits:int,avg_ms:float|null,cache_pct:float|int|null}
     *         Empty array on no data; otherwise the full per-item summary.
     */
    public static function summaryForItem(int $short_id): array
    {
        if ($short_id <= 0) {
            return [];
        }
        $now = time();
        $wk  = $now - 7 * 86400;
        $mo  = $now - 30 * 86400;
        // The 'duration' column is an optional optimisation (AddDurationLog); on installs without
        // it we must not reference it or the query fails. Query the cache/latency buckets only when
        // the column exists - otherwise the cache % and avg-ms figures stay null.
        $do_duration = DB_AA::exists_column('hit_archive', 'duration');
        $dur_cols = $do_duration ? ",
            SUM(CASE WHEN `duration` <> 0 AND `duration` & 3 = 0 THEN `hits` ELSE 0 END) AS h0,
            SUM(CASE WHEN `duration` <> 0 AND `duration` & 3 = 1 THEN `hits` ELSE 0 END) AS h1,
            SUM(CASE WHEN `duration` <> 0 AND `duration` & 3 = 2 THEN `hits` ELSE 0 END) AS h2,
            SUM(CASE WHEN `duration` <> 0 AND `duration` & 3 = 3 THEN `hits` ELSE 0 END) AS h3,
            SUM(CASE WHEN `duration` <> 0 AND `duration` & 3 = 0 THEN (`duration` >> 2) * `hits` ELSE 0 END) AS w0,
            SUM(CASE WHEN `duration` <> 0 AND `duration` & 3 = 1 THEN (`duration` >> 2) * `hits` ELSE 0 END) AS w1,
            SUM(CASE WHEN `duration` <> 0 AND `duration` & 3 = 2 THEN (`duration` >> 2) * `hits` ELSE 0 END) AS w2,
            SUM(CASE WHEN `duration` <> 0 AND `duration` & 3 = 3 THEN (`duration` >> 2) * `hits` ELSE 0 END) AS w3" : '';
        // $wk/$mo are ints computed here; inlined into the SELECT (no user input).
        $sql = "SELECT
            SUM(`hits`)                                                       AS total_hits,
            SUM(CASE WHEN `time` >= $wk THEN `hits` ELSE 0 END)               AS hits_7d,
            SUM(CASE WHEN `time` >= $mo THEN `hits` ELSE 0 END)               AS hits_30d,
            MIN(`time`)                                                       AS first_t,
            MAX(`time`)                                                       AS last_t$dur_cols
            FROM `hit_archive`";
        $rows = DB_AA::select([], $sql, [['id', $short_id, 'i']]);
        $r    = $rows[0] ?? null;
        if (!$r || $r['total_hits'] === null) {
            return [];
        }

        $bucket_sum = 0;
        $cache_hits = 0;
        $ms_x_hits  = 0.0;
        if ($do_duration) {
            $timex = new LogarithmIndex(.002, 80, 63);
            for ($b = 0; $b < 4; $b++) {
                $bh = (int) $r["h$b"];
                $bw = (int) $r["w$b"];
                $bucket_sum += $bh;
                if ($b >= 2) {
                    $cache_hits += $bh;   // buckets 2,3 = served from PageCache
                }
                if ($bh > 0) {
                    $ms_x_hits += $timex->index2float($bw / $bh) * 1000 * $bh;
                }
            }
        }

        return [
            'total_hits'    => (int) $r['total_hits'],
            'hits_7d'       => (int) $r['hits_7d'],
            'hits_30d'      => (int) $r['hits_30d'],
            'first_t'       => (int) $r['first_t'],
            'last_t'        => (int) $r['last_t'],
            'measured_hits' => $bucket_sum,
            'avg_ms'        => $bucket_sum ? $ms_x_hits / $bucket_sum : null,
            'cache_pct'     => $bucket_sum ? ($cache_hits / $bucket_sum) * 100 : null,
        ];
    }

    /**
     * Period label (e.g. 'week', 'last_month', 'all') → [from_ts, to_ts]. Used by
     * both the items-selection logic in aa_statistics.php and the diagnostics window
     * here. Stays canonical so click-throughs match what the box was measuring.
     */
    public static function periodToRange(string $period, int $now): array
    {
        return match ($period) {
            'all'        => [0,                              $now],
            'year'       => [$now - 60 * 60 * 24 * 365,      $now],
            'month'      => [$now - 60 * 60 * 24 * 30,       $now],
            'week'       => [$now - 60 * 60 * 24 * 7,        $now],
            'day'        => [$now - 60 * 60 * 24,            $now],
            'last_year'  => [$now - 60 * 60 * 24 * 365 * 2,  $now - 60 * 60 * 24 * 365],
            'last_month' => [$now - 60 * 60 * 24 * 30 * 2,   $now - 60 * 60 * 24 * 30],
            'last_week'  => [$now - 60 * 60 * 24 * 7 * 2,    $now - 60 * 60 * 24 * 7],
            'last_day'   => [$now - 60 * 60 * 24 * 2,        $now - 60 * 60 * 24],
            default      => [$now - 60 * 60 * 24 * 7,        $now],   // fallback to week
        };
    }

    /**
     * Render the AA-wide diagnostics panel (independent of the per-day table below it).
     *
     * @param  array  $slice_ids   empty = all AA, otherwise scoped to these slices
     * @param  string $tip_period  period label for the measurement window (default 'week')
     * @param  int    $item_count  N for "click → table shows N items" round-trip (default 5)
     * @param  array  $base_qs     extra GET params card click-through URLs should preserve
     *                              (typically: for_module, date_from, item_sort fallback)
     * @return string HTML
     */
    public static function renderDiagnosticsPanel(array $slice_ids, string $tip_period = 'week', int $item_count = 5, array $base_qs = []): string
    {
        if (!(new AddDurationLog())->test()) {
            return '';   // duration log feature off — nothing to diagnose
        }

        $now = time();
        [$tip_from, $tip_to] = self::periodToRange($tip_period, $now);
        $tip_duration_secs   = max(1, $tip_to - $tip_from);
        $prev_tip_from       = $tip_from - $tip_duration_secs;
        $prev_tip_to         = $tip_from;

        // Limit 10000 matches the items-selection path in aa_statistics.php so the box's
        // top-N is identical to what the form returns when the operator clicks through.
        $globals      = self::getGlobalTotals($slice_ids, $tip_from, $tip_to, 10000);
        $globals_prev = self::getGlobalTotals($slice_ids, $prev_tip_from, $prev_tip_to, 10000);

        // Per-item display data — populated lazily; safe() handles HTML escaping.
        // The diagnostics card shows "[Slicena…] headline" as the visible label,
        // with the numeric short-id appended in small grey text. Full headline +
        // slice name go into the title tooltip.
        $item_meta_cache = [];
        $get_meta = static function (string $sid) use (&$item_meta_cache, $globals): array {
            if (!isset($item_meta_cache[$sid])) {
                $headline    = trim(\AA::Un("{shorten:{item:$sid:_#HEADLINE}:30:1}"));
                $headline    = $headline !== '' ? $headline : $sid;
                $slice_id    = $globals[$sid]['slice_id'] ?? '';
                $long_id     = $globals[$sid]['long_id']  ?? '';
                $slice_nm    = $slice_id ? (string) \AA_Slice::getModuleProperty($slice_id, 'name') : '';
                $item_meta_cache[$sid] = [
                    'headline'    => $headline,
                    'sid'         => $sid,
                    'slice_name'  => $slice_nm,
                    'slice_short' => Txt::shorten($slice_nm, 11, '…'),
                    'long_id'     => $long_id,
                    'slice_id'    => $slice_id,
                ];
            }
            return $item_meta_cache[$sid];
        };

        // ---- Build top-N rankings -------------------------------------------------
        $top_n = static function (array $totals_map, callable $metric, int $n = 3, bool $desc = true): array {
            $scored = [];
            foreach ($totals_map as $sid => $t) {
                $v = $metric($t);
                if ($v === null) {
                    continue;
                }
                $scored[$sid] = $v;
            }
            $desc ? arsort($scored) : asort($scored);
            return array_slice($scored, 0, $n, true);
        };

        $min_hits_for_avg = 5;
        $top_hits  = $top_n($globals, static fn($t) => $t['hits'], 3, true);
        $top_srvt  = $top_n($globals, static fn($t) => $t['ms_x_hits'], 3, true);
        $top_slow  = $top_n($globals, static fn($t) => $t['bucket_sum'] >= $min_hits_for_avg ? $t['ms_x_hits'] / $t['bucket_sum'] : null, 3, true);
        $top_lowc  = $top_n($globals, static fn($t) => $t['bucket_sum'] >= $min_hits_for_avg ? ($t['cache_hits'] / $t['bucket_sum']) * 100 : null, 3, false);
        $top_waste = $top_n($globals, static fn($t) => $t['wasted'] > 0 ? $t['wasted'] : null, 3, true);

        $diff        = [];   // [sid => pct change], used for sorting
        $diff_detail = [];   // [sid => [cur_avg_ms, prv_avg_ms]], used in tooltips
        foreach ($globals as $sid => $tc) {
            $tp = $globals_prev[$sid] ?? null;
            if (!$tp) {
                continue;
            }
            if ($tc['bucket_sum'] < $min_hits_for_avg || $tp['bucket_sum'] < $min_hits_for_avg) {
                continue;
            }
            $cur_avg = $tc['ms_x_hits'] / $tc['bucket_sum'];
            $prv_avg = $tp['ms_x_hits'] / $tp['bucket_sum'];
            if ($prv_avg <= 0) {
                continue;
            }
            $diff[$sid]        = (($cur_avg - $prv_avg) / $prv_avg) * 100;
            $diff_detail[$sid] = [$cur_avg, $prv_avg];
        }
        arsort($diff);
        $top_slowed_down = array_filter(array_slice($diff, 0, 3, true), static fn($v) => $v >  1);
        asort($diff);
        $top_sped_up     = array_filter(array_slice($diff, 0, 3, true), static fn($v) => $v < -1);

        // ---- Format helpers + card builder ----------------------------------------
        $fmt_int  = static fn($v) => number_format((int) $v, 0, '.', ' ');
        $fmt_ms   = static fn($v) => number_format($v, $v < 10 ? 1 : 0, '.', ' ');
        $fmt_pct  = static fn($v) => sprintf('%+.0f%%', $v);
        $fmt_date = static fn($ts) => date('Y-m-d', $ts);

        // Card click-through URL: clicking title sets item_sort=<key> in the form
        // below and passes through item_period = tip_period so the resulting table
        // shows exactly the items the box surfaced.
        $card_base_qs = $base_qs + [
            'item_period' => $tip_period,
            'tip_period'  => $tip_period,
            'item_count'  => (string) $item_count,
            'show'        => '1',
        ];

        // card_html accepts a 5th $title_help arg with a longer plain-text explanation
        // surfaced as a tooltip on the card title (or the click-link if linkable).
        $card_html = static function (string $title, string $title_help, array $rows, callable $row_fmt, ?string $sort_key = null) use ($get_meta, $card_base_qs): string {
            if (empty($rows)) {
                return '';
            }
            if ($sort_key !== null) {
                $href = 'aa_statistics.php?' . http_build_query(['item_sort' => $sort_key] + $card_base_qs);
                $title_attr = $title_help !== ''
                    ? $title_help . ' ' . \_m('Click to show these items in the table.')
                    : \_m('Show these items in the table');
                $title_html = '<a href="' . \safe($href) . '" class="aa-stat-tip-link" title="' . \safe($title_attr) . '">' . \safe($title) . ' &raquo;</a>';
            } else {
                $title_html = $title_help !== ''
                    ? '<span title="' . \safe($title_help) . '">' . \safe($title) . '</span>'
                    : \safe($title);
            }
            $h  = '<div class="aa-stat-tip-card"><h4>' . $title_html . '</h4><ol>';
            foreach ($rows as $sid => $val) {
                $m = $get_meta($sid);
                $h .= '<li>'
                    . self::renderItemLink($sid, $m['headline'], $m['headline'], $m, 'aa-stat-tip-name')
                    . ' <span class="aa-stat-tip-val">' . $row_fmt($val, $sid) . '</span>'
                    . '</li>';
            }
            $h .= '</ol></div>';
            return $h;
        };

        // ---- Inline period selector ----------------------------------------------
        // Plain <select> with JS onchange that navigates GET. Wrapping it in a <form>
        // would nest inside AA's admin <form name="aanbform">, which HTML5 resolves by
        // closing the outer form — detaching the Show button below from its form.
        $periods = [
            'day'        => \_m('Today'),
            'week'       => \_m('This week'),
            'month'      => \_m('This month'),
            'year'       => \_m('This year'),
            'last_day'   => \_m('Last day'),
            'last_week'  => \_m('Last week'),
            'last_month' => \_m('Last month'),
            'last_year'  => \_m('Last year'),
            'all'        => \_m('All time'),
        ];
        // Preserved query params for the navigation URL. tip_period is set by the dropdown;
        // show=1 ensures the table re-renders if the operator was already viewing results.
        $preserve_qs = $base_qs;
        unset($preserve_qs['tip_period']);
        $preserve_json = json_encode($preserve_qs + ['show' => '1'], JSON_UNESCAPED_SLASHES);
        $onchange = sprintf(
            "var p=%s;p.tip_period=this.value;location.href='aa_statistics.php?'+new URLSearchParams(p).toString()",
            $preserve_json,
        );
        $period_select  = '<select class="aa-stat-tips-period" onchange="' . \safe($onchange) . '">';
        foreach ($periods as $k => $lbl) {
            $sel = $k === $tip_period ? ' selected' : '';
            $period_select .= '<option value="' . \safe($k) . '"' . $sel . '>' . \safe($lbl) . '</option>';
        }
        $period_select .= '</select>';

        // ---- Render the panel ------------------------------------------------------
        $period_lbl = \safe($fmt_date($tip_from)) . ' → ' . \safe($fmt_date($tip_to));
        $scope_lbl  = $slice_ids ? \safe(\_m('current slice')) : \safe(\_m('all AA'));

        // No section <h3> emitted here: the caller (admin/aa_statistics.php) now wraps this panel in
        // <details class="aa-page-section"> with the section title in its <summary>, so we would otherwise
        // double up the heading. Period selector + range/scope label stay; they belong with the data.
        $h  = '<div class="aa-stat-tips">';
        $h .= '<div class="aa-stat-tips-head">';
        $h .= $period_select;
        $h .= '<small class="aa-stat-tips-range">' . $period_lbl . ' &middot; ' . $scope_lbl . '</small>';
        $h .= '</div>';

        if (empty($globals)) {
            $h .= '<p class="aa-stat-tips-empty">' . \safe(\_m('No measured data in the selected window. Diagnostics needs at least the AddDurationLog feature on and a non-empty period.')) . '</p>';
        } else {
            // Format helper: total ms → "1.2 s" / "5.4 s" / "12 s", small ms stays as "120 ms"
            $fmt_total = static function (float $total_ms) use ($fmt_int): string {
                if ($total_ms < 1000) {
                    return number_format($total_ms, 0) . ' ms';
                }
                if ($total_ms < 60000) {
                    return number_format($total_ms / 1000, 1) . ' s';
                }
                return number_format($total_ms / 60000, 1) . ' min';
            };
            $compared_lbl = $prev_tip_from > 0
                ? \_m('Current period') . ' ' . $fmt_date($tip_from) . ' → ' . $fmt_date($tip_to)
                  . '; ' . \_m('Prior period') . ' ' . $fmt_date($prev_tip_from) . ' → ' . $fmt_date($prev_tip_to)
                : '';

            $h .= '<div class="aa-stat-tip-grid">';
            $h .= $card_html(
                \_m('Most hits'),
                \_m('Items with the most page views over the diagnostics window. Where the public traffic is going.'),
                $top_hits,
                fn($v, $sid) => \safe($fmt_int($v)) . ' hits',
                'top',
            );
            $h .= $card_html(
                \_m('Heaviest (ms × hits)'),
                \_m('Items where the server spent the most aggregate render time across all hits, i.e. avg page render time × hit count. The biggest performance levers: even a small per-hit speedup compounds across many requests.'),
                $top_srvt,
                function ($v, $sid) use ($globals, $fmt_ms, $fmt_int, $fmt_total) {
                    $t = $globals[$sid];
                    $avg_ms = $t['bucket_sum'] > 0 ? $t['ms_x_hits'] / $t['bucket_sum'] : 0;
                    $tip = $fmt_ms($avg_ms) . ' ms × ' . $fmt_int($t['hits']) . ' hits = ' . $fmt_total($t['ms_x_hits']);
                    return '<span title="' . \safe($tip) . '">' . \safe($fmt_total($t['ms_x_hits'])) . '</span>';
                },
                'most-time',
            );
            $h .= $card_html(
                \_m('Slowest (avg ms)'),
                \_m('Items with the highest average page render time across all hits in the window. Best targets for individual-page optimisation.'),
                $top_slow,
                function ($v, $sid) use ($globals, $fmt_ms, $fmt_int) {
                    $t = $globals[$sid];
                    $tip = $fmt_int($t['hits']) . ' hits';
                    return '<span title="' . \safe($tip) . '">' . \safe($fmt_ms($v)) . ' ms</span>';
                },
                'slowest',
            );
            $h .= $card_html(
                \_m('Worst cached'),
                \_m('Items with the lowest AA PageCache hit ratio: the fraction of requests served from the cache vs regenerated fresh. Low % means most requests pay the regeneration cost.'),
                $top_lowc,
                fn($v, $sid) => \safe(number_format($v, 0)) . '%',
                'worst-cached',
            );
            $h .= $card_html(
                \_m('Most wasted (NC+NM)'),
                \_m('Items with the most "wasted work" hits: page regenerated fresh AND then a 304 returned because the client already had it. Pure server cost with no benefit.'),
                $top_waste,
                fn($v, $sid) => \safe($fmt_int($v)) . ' hits',
                'most-wasted',
            );

            // Period-over-period cards are secondary. Build them into a buffer and, if non-empty,
            // hang them off a nested <details> chip below the main 5-up grid. Honors PRODUCT.md
            // Principle 1: clean default, depth on demand.
            $period_cards = '';
            if ($prev_tip_from > 0) {
                $compared = ' (' . \safe($fmt_date($prev_tip_from)) . ' → ' . \safe($fmt_date($prev_tip_to)) . ')';
                $period_cards .= $card_html(
                    \_m('Slowed down vs') . $compared,
                    \_m('Items whose average render time grew the most between the prior equivalent window and the current one. ') . $compared_lbl,
                    $top_slowed_down,
                    function ($v, $sid) use ($diff_detail, $fmt_ms, $fmt_pct) {
                        [$cur, $prv] = $diff_detail[$sid];
                        $tip = \_m('current') . ' ' . $fmt_ms($cur) . ' ms · ' . \_m('prior') . ' ' . $fmt_ms($prv) . ' ms';
                        return '<span style="color:#a02222" title="' . \safe($tip) . '">' . \safe($fmt_pct($v)) . '</span>';
                    },
                    'slowed-down',
                );
                $period_cards .= $card_html(
                    \_m('Sped up vs') . $compared,
                    \_m('Items whose average render time fell the most between the prior equivalent window and the current one. ') . $compared_lbl,
                    $top_sped_up,
                    function ($v, $sid) use ($diff_detail, $fmt_ms, $fmt_pct) {
                        [$cur, $prv] = $diff_detail[$sid];
                        $tip = \_m('current') . ' ' . $fmt_ms($cur) . ' ms · ' . \_m('prior') . ' ' . $fmt_ms($prv) . ' ms';
                        return '<span style="color:#2e7d52" title="' . \safe($tip) . '">' . \safe($fmt_pct($v)) . '</span>';
                    },
                    'sped-up',
                );
            }
            $h .= '</div>';
            if ($period_cards !== '') {
                $h .= '<details class="aa-stat-tip-more"><summary>'
                    . \safe(\_m('Period-over-period changes'))
                    . '</summary>'
                    . '<div class="aa-stat-tip-grid">' . $period_cards . '</div>'
                    . '</details>';
            }
        }
        $h .= '</div>';

        // CSS — emitted with the panel; will dedupe naturally if rendered twice on a page
        $h .= self::renderTipsCss();

        return $h;
    }

    // -------------------------------------------------------------------------
    // Table + chart rendering (used by {hitcounter:…} template)
    // -------------------------------------------------------------------------

    /**
     * Per-day statistics (for the operator-picked items) with ChartJS canvases below.
     * Replaces the old in-place rendering that lived in AA_Stringexpand_Hitcounter::expand().
     *
     * @param  \zids   $zids  the operator-picked items (long or short ids)
     * @param  string  $type  granularity: minutes|hours|days|dayhours|weeks|weekdays|months|years
     * @param  int     $from  unix timestamp lower bound for the per-day query (0 = no lower bound)
     * @return string         HTML
     */
    public static function renderTable(\zids $zids, string $type, int $from = 0): string
    {
        $group_string = match ($type) {
            'minutes'  => 'Y-m-d H:i',
            'hours'    => 'Y-m-d H:00',
            'days'     => 'Y-m-d',
            'dayhours' => 'H',
            'weeks'    => 'o-W',           // o = ISO year for week numbering
            'weekdays' => 'N-D',
            'months'   => 'Y-m',
            'years'    => 'Y',
            default    => null,
        };
        if ($group_string === null) {
            return '';
        }

        $s_zids = new \zids($zids->shortids(), 's');

        $reports     = ['HITS', 'DURATION', 'SUM'];
        $do_duration = in_array('DURATION', $reports, true) && (new AddDurationLog())->test();
        $stat        = self::countStats($s_zids, $group_string, $reports, $from);

        if (!$stat) {
            return '';
        }

        $s_ids  = $s_zids->shortids();
        $single = count($s_ids) === 1;   // a per-item view (e.g. admin/itemstats.php)
        // The "all" summary column only makes sense when aggregating several items; for a
        // single item it just duplicates that item's own column, so skip it there.
        if (in_array('SUM', $reports, true) && !$single) {
            $s_ids[] = 'all';
        }

        // ---- Pass 1: per-(day, item) aggregates + chart-data arrays + per-row/col maxes
        $timex      = new LogarithmIndex(.002, 80, 63);
        ksort($stat);
        $cols       = [];   // [sid => [H => [], ncmH => [], … avgMs => []]]
        $labels     = [];
        $collected  = [];   // [day => [row_max_hits, items: [sid => row_data]]]
        $col_max_ms = [];   // [sid => max ms across all days]

        foreach ($stat as $day => $counts) {
            $labels[] = $day;
            $row          = [];
            $row_max_hits = 0;
            foreach ($s_ids as $sid) {
                $hits = ($counts[$sid][0] ?? '0');
                $cols[$sid]['H'][] = $hits;

                $b0_cnt = ($counts[$sid][2] ?? '0');
                $b0_dur = $b0_cnt ? \StrExp('math', [1000 * $timex->index2float($counts[$sid][1] ?? '0'), 1, 'EN']) : 0;
                $cols[$sid]['ncmH'][] = $b0_cnt;
                $cols[$sid]['ncmD'][] = $b0_dur;

                $b1_cnt = ($counts[$sid][4] ?? '0');
                $b1_dur = $b1_cnt ? \StrExp('math', [1000 * $timex->index2float($counts[$sid][3] ?? '0'), 1, 'EN']) : 0;
                $cols[$sid]['ncnmH'][] = $b1_cnt;
                $cols[$sid]['ncnmD'][] = $b1_dur;

                $b2_cnt = ($counts[$sid][6] ?? '0');
                $b2_dur = $b2_cnt ? \StrExp('math', [1000 * $timex->index2float($counts[$sid][5] ?? '0'), 1, 'EN']) : 0;
                $cols[$sid]['cmH'][] = $b2_cnt;
                $cols[$sid]['cmD'][] = $b2_dur;

                $b3_cnt = ($counts[$sid][8] ?? '0');
                $b3_dur = $b3_cnt ? \StrExp('math', [1000 * $timex->index2float($counts[$sid][7] ?? '0'), 1, 'EN']) : 0;
                $cols[$sid]['cnmH'][] = $b3_cnt;
                $cols[$sid]['cnmD'][] = $b3_dur;

                $bucket_sum = (int) $b0_cnt + (int) $b1_cnt + (int) $b2_cnt + (int) $b3_cnt;
                $cache_pct  = $bucket_sum
                    ? round((((int) $b2_cnt + (int) $b3_cnt) / $bucket_sum) * 100)
                    : null;
                $avg_ms = $bucket_sum
                    ? round(
                        ((float) $b0_dur * (int) $b0_cnt
                         + (float) $b1_dur * (int) $b1_cnt
                         + (float) $b2_dur * (int) $b2_cnt
                         + (float) $b3_dur * (int) $b3_cnt) / $bucket_sum,
                        1,
                    )
                    : null;

                $row[$sid] = [
                    'hits'       => (int) $hits,
                    'cache_pct'  => $cache_pct,
                    'avg_ms'     => $avg_ms,
                    'bucket_sum' => $bucket_sum,
                    'b0_cnt' => $b0_cnt, 'b0_dur' => $b0_dur,
                    'b1_cnt' => $b1_cnt, 'b1_dur' => $b1_dur,
                    'b2_cnt' => $b2_cnt, 'b2_dur' => $b2_dur,
                    'b3_cnt' => $b3_cnt, 'b3_dur' => $b3_dur,
                ];
                $cols[$sid]['avgMs'][] = is_null($avg_ms) ? 0 : $avg_ms;

                // Per-bucket average duration (already decoded to ms above), kept as four
                // separate series so each cache-state cohort gets its own chart. Blending
                // C+M with C+NM (or NC+M with NC+NM) would hide the very differences we want
                // to benchmark. 0 means "no hits in this bucket on this day".
                $cols[$sid]['avgMsB2'][] = (float) $b2_dur;   // C+M   — cached, 200
                $cols[$sid]['avgMsB3'][] = (float) $b3_dur;   // C+NM  — cached, 304
                $cols[$sid]['avgMsB0'][] = (float) $b0_dur;   // NC+M  — uncached, 200
                $cols[$sid]['avgMsB1'][] = (float) $b1_dur;   // NC+NM — uncached, 304
                if ((int) $hits > $row_max_hits) {
                    $row_max_hits = (int) $hits;
                }
                if (!is_null($avg_ms)) {
                    $col_max_ms[$sid] = max($col_max_ms[$sid] ?? 0.0, $avg_ms);
                }
            }
            $collected[$day] = ['row_max_hits' => $row_max_hits, 'items' => $row];
        }

        // ---- Item display labels (table headers + chart legends)
        $item_labels = [];
        foreach ($s_ids as $sid) {
            if ($sid === 'all') {
                $item_labels[$sid] = 'all';
            } else {
                $headline = trim(\AA::Un("{shorten:{item:$sid:_#HEADLINE}:30:1}"));
                $item_labels[$sid] = $headline !== '' ? "$sid · $headline" : (string) $sid;
            }
        }

        // ---- Per-item metadata (long_id, slice_id, slice_short) for header links
        $item_meta = self::buildItemMeta($s_ids);

        // ---- Render: CSS + (single: trend then table / multi: table then trend) + charts
        $ret = self::renderStatsCss();
        // For a single item the hits trend leads (data-first; the KPI strip already sits above this on
        // the Item statistics page), then the per-day detail table. For multiple items the dense
        // comparison table stays first.
        $hits_chart = self::renderHitsChart($cols, $labels, $item_labels);
        $table      = self::renderLegend($single)
                    . self::renderStatsTable($collected, $s_ids, $item_labels, $col_max_ms, $do_duration, $item_meta, $single);
        $ret .= $single ? ($hits_chart . $table) : ($table . $hits_chart);
        $ret .= self::renderCacheStateCharts($cols, $s_ids, $labels, $item_labels);
        $ret .= self::renderAvgMsChart($cols, $labels, $item_labels);
        // Per-bucket duration benchmarks (cached pair first, then uncached) — each only
        // shows up once it has at least two data points to draw a trend line.
        $ret .= self::renderAvgMsChart($cols, $labels, $item_labels, 'avgMsB2', \_m('Average duration, cached + 200 (C+M, ms)'), 2);
        $ret .= self::renderAvgMsChart($cols, $labels, $item_labels, 'avgMsB3', \_m('Average duration, cached + 304 (C+NM, ms)'), 2);
        $ret .= self::renderAvgMsChart($cols, $labels, $item_labels, 'avgMsB0', \_m('Average duration, uncached + 200 (NC+M, ms)'), 2);
        $ret .= self::renderAvgMsChart($cols, $labels, $item_labels, 'avgMsB1', \_m('Average duration, uncached + 304 (NC+NM, ms)'), 2);

        \AA::Stringexpander()->addRequire('chartjs@4');
        return $ret;
    }

    /**
     * Per-(day, item) bucketed aggregates from hit_archive.
     * Each entry is [hits, dur_0, count_0, dur_1, count_1, dur_2, count_2, dur_3, count_3]
     * — bucket index N corresponds to (cached, not_modified) per AA_Packtime encoding.
     */
    public static function countStats(\zids $s_zids, string $group_string, array $columns, int $from = 0, int $to = 0): array
    {
        $duration_helper = new AddDurationLog();
        $do_duration     = in_array('DURATION', $columns, true) && $duration_helper->test();
        $do_sum          = $do_duration && in_array('SUM', $columns, true);
        $duration_from   = $do_duration ? $duration_helper->getFromTime() : 0;

        $conds = [['id', $s_zids->getArray(), 'i']];
        if ($from) {
            $conds[] = ['time', $from, '>='];
        }
        if ($to) {
            $conds[] = ['time', $to, '<'];
        }
        $hits = DB_AA::select(
            [],
            'SELECT id, time, hits' . ($do_duration ? ', duration' : '') . ' FROM hit_archive',
            $conds,
        );

        $stat  = [];
        $empty = $do_duration ? [0, 0.0, 0, 0.0, 0, 0.0, 0, 0.0, 0] : [0];
        foreach ($hits as $hit) {
            ['id' => $hit_id, 'time' => $hit_time, 'hits' => $hit_hits] = $hit;
            $day = date($group_string, $hit_time);
            $stat[$day] ??= [];
            $stat[$day][$hit_id] ??= $empty;
            $stat[$day][$hit_id][0] += $hit_hits;
            if ($do_sum) {
                $stat[$day]['all']    ??= $empty;
                $stat[$day]['all'][0] += $hit_hits;
            }

            if ($do_duration && ($hit_time >= $duration_from)) {
                $index    = 1 + (2 * ($hit['duration'] & 3));
                $duration = $hit['duration'] >> 2;
                // weighted avg: (old_dur * old_count + new_dur * new_count) / total_count
                $stat[$day][$hit_id][$index]     = ($stat[$day][$hit_id][$index] * $stat[$day][$hit_id][$index + 1] + $duration * $hit_hits) / ($stat[$day][$hit_id][$index + 1] + $hit_hits);
                $stat[$day][$hit_id][$index + 1] += $hit_hits;
                if ($do_sum) {
                    $stat[$day]['all'][$index]     = ($stat[$day]['all'][$index] * $stat[$day]['all'][$index + 1] + $duration * $hit_hits) / ($stat[$day]['all'][$index + 1] + $hit_hits);
                    $stat[$day]['all'][$index + 1] += $hit_hits;
                }
            }
        }
        return $stat;
    }

    // -------------------------------------------------------------------------
    // Sub-rendering helpers — small cohesive units that the public renderTable
    // composes, so the main flow stays readable.
    // -------------------------------------------------------------------------

    /**
     * Batched item-meta lookup for hit_archive short_ids. One JOIN — matches the
     * pattern in admin/aafinder.php — instead of one SELECT plus N per-slice
     * AA_Slice::getModuleProperty() calls.
     *
     * @param  string[] $sids  short_ids (numeric strings); 'all' is ignored
     * @return array<string, array{long_id:string,slice_id:string,slice_name:string,slice_short:string}>
     */
    private static function buildItemMeta(array $sids): array
    {
        $real_sids = array_values(array_filter($sids, static fn($s) => $s !== 'all'));
        if (empty($real_sids)) {
            return [];
        }

        $rows = DB_AA::select(
            [],
            'SELECT `item`.`short_id`,
                    LOWER(HEX(`item`.`id`))       AS long_id,
                    LOWER(HEX(`item`.`slice_id`)) AS slice_id,
                    `slice`.`name`                AS slice_name
             FROM `item` INNER JOIN `slice` ON `slice`.`id` = `item`.`slice_id`',
            [['item.short_id', $real_sids, 'i']],
        );

        $meta = [];
        foreach ($rows as $r) {
            $slice_nm = (string) $r['slice_name'];
            $meta[(string) $r['short_id']] = [
                'long_id'     => (string) $r['long_id'],
                'slice_id'    => (string) $r['slice_id'],
                'slice_name'  => $slice_nm,
                'slice_short' => Txt::shorten($slice_nm, 11, '…'),
            ];
        }
        return $meta;
    }

    /**
     * Render an item label as a linked anchor (or span when long_id is unknown)
     * with optional "[slice_short]" prefix and "headline · slice · #sid" tooltip.
     * Shared between the diagnostics card list and the per-day stats-table header
     * so both UIs stay in sync.
     *
     * @param string $sid              short_id
     * @param string $visible_headline pre-truncated headline shown inline (raw text)
     * @param string $tooltip_headline headline used in title attribute (often full)
     * @param array  $meta             keys: long_id, slice_id, slice_name, slice_short
     * @param string $anchor_class     CSS class applied to the wrapping <a>
     */
    private static function renderItemLink(string $sid, string $visible_headline, string $tooltip_headline, array $meta, string $anchor_class): string
    {
        // trim() guards against slice names stored with stray whitespace (e.g. " Sandbox"),
        // which otherwise rendered as "[ Sandbox]" in the column header.
        $slice_short = trim($meta['slice_short'] ?? '');
        $slice_name  = trim($meta['slice_name']  ?? '');
        $long_id     = $meta['long_id']     ?? '';
        $slice_id    = $meta['slice_id']    ?? '';

        $tooltip = ($tooltip_headline !== '' ? $tooltip_headline : $sid)
                 . ($slice_name !== '' ? ' · ' . $slice_name : '')
                 . ' · #' . $sid;

        $prefix = $slice_short !== ''
            ? '<span class="aa-stat-slice-prefix">[' . \safe($slice_short) . ']</span> '
            : '';
        $inner = $prefix
               . \safe($visible_headline !== '' ? $visible_headline : $sid)
               . ' <small class="aa-stat-item-sid">' . \safe($sid) . '</small>';

        if ($long_id !== '') {
            $href = \get_admin_url('itemedit.php', ['id' => $long_id, 'change_id' => $slice_id, 'edit' => 1]);
            return '<a class="' . \safe($anchor_class) . '" href="' . \safe($href) . '" title="' . \safe($tooltip) . '">' . $inner . '</a>';
        }
        return '<span title="' . \safe($tooltip) . '">' . $inner . '</span>';
    }

    /** Cache-state palette ordered by quality (best → worst) — used by stacked charts. */
    private const CACHE_STATE_ORDER = [
        ['cnmH',  'C+NM (cheapest)', '#2e7d52'],   // cache hit + 304   — best  (deepest green, bottom of stack)
        ['cmH',   'C+M (cheap)',     '#7fb069'],   // cache hit + body
        ['ncmH',  'NC+M (normal)',   '#dd6644'],   // regen + body      — bad   (red-orange)
        ['ncnmH', 'NC+NM (wasted)',  '#a02222'],   // regen + 304       — worst (deep red, top of stack)
    ];

    private static function renderStatsCss(): string
    {
        return '<style>'
            . '.aa-stat-wrap{max-height:calc(100vh - 200px);overflow:auto;border:1px solid var(--clr-basic-dark,#ccc);margin:.5em 0}'
            . '.aa-stat-table{border-collapse:collapse;font-variant-numeric:tabular-nums;width:auto;margin:0}'
            . '.aa-stat-table--wide{width:100%}'
            . '.aa-stat-table th,.aa-stat-table td{padding:2px 6px;text-align:right;white-space:nowrap;line-height:1.3}'
            . '.aa-stat-table thead th{position:sticky;background-color:var(--clr-basic-dark,#e8e3d4);color:rgb(0 0 0 / 80%);font-weight:normal;border-left:solid 1px rgb(255 255 255 / 45%);border-top:solid 1px rgb(255 255 255 / 45%);z-index:5}'
            . '.aa-stat-table thead tr:first-child th{top:0;z-index:6}'
            . '.aa-stat-table thead tr:nth-child(2) th{top:32px;box-shadow:0 2px 4px rgba(60,50,20,.12)}'
            . '.aa-stat-table tbody td:first-child{position:sticky;left:0;background-color:#fff;z-index:3;text-align:left}'
            . '.aa-stat-table tbody tr:nth-of-type(odd) td:first-child{background-color:rgb(255 255 255 / 100%)}'
            . '.aa-stat-table tbody tr:nth-of-type(even) td:first-child{background-color:var(--clr-basic-light)}'
            . '.aa-stat-table thead tr:first-child th:first-child{left:0;z-index:7;text-align:left}'
            . '.aa-stat-table thead tr:nth-child(2) th:first-child{left:0;z-index:6;text-align:left}'
            . '.aa-stat-table th:nth-child(4n+2),.aa-stat-table td:nth-child(4n+2){border-left:1px solid rgb(0 0 0 / 8%)}'
            /* single-item ("wide") table: a hairline before every value column so each in-cell bar reads inside its column */
            . '.aa-stat-table--wide th:not(:first-child),.aa-stat-table--wide td:not(:first-child){border-left:1px solid var(--clr-border)}'
            /* line charts (Hits, Avg duration) — short and wide; max-height caps them so they
               don't dominate tall viewports. Stacked-bar variants override aspect-ratio below. */
            . '.aa-stat-chart{position:relative;width:100%;aspect-ratio:3/1;max-height:50vh;margin:1em 0}'
            . '@media (max-width:900px){.aa-stat-chart{aspect-ratio:5/2}}'
            . '.aa-stat-chart-pct{aspect-ratio:5/1}'
            . '.aa-stat-chart-grid{display:flex;flex-wrap:wrap;gap:1em;margin:1em 0}'
            . '.aa-stat-chart-mini-wrap{display:flex;flex-direction:column;width:280px}'
            . '.aa-stat-chart-mini{width:280px;max-width:280px;aspect-ratio:1/1;margin:0;flex:0 0 auto}'
            . '.aa-stat-chart-mini-title{font-size:90%;color:var(--clr-text-accent);margin-bottom:.25em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px}'
            . '.aa-stat-legend{margin:.5em 0;color:var(--clr-text-accent);font-size:90%}'
            . '.aa-stat-legend code{background:var(--clr-basic-light);padding:0 .2em}'
            . '.aa-stat-bar{background-image:linear-gradient(to right,var(--bar,#7a9) calc(var(--pct,0)*1%),transparent 0);background-repeat:no-repeat;background-position:left bottom;background-size:100% 3px}'
            . '.aa-stat-bar--pct{--bar:#5b8}'
            . '.aa-stat-bar--ms{--bar:hsl(calc(120 - var(--pct,0) * 1.2),60%,55%)}'
            . '.aa-stat-zero{color:var(--clr-basic-darker)}'
            . '.aa-stat-all-bdr{border-left:2px solid var(--clr-basic-darker)}'
            . '.aa-stat-all{background-color:var(--clr-footer)!important;font-weight:bold}'
            . '.aa-stat-slice-prefix{color:var(--clr-basic-darker)}'
            . '.aa-stat-item-sid{color:var(--clr-basic-darker)}'
            . '.aa-stat-th-link{color:inherit;text-decoration:none}'
            . '.aa-stat-th-link:hover{text-decoration:underline}'
            . '.aa-stat-detail{font-size:90%;margin:.3em 0;border-collapse:collapse}'
            . '.aa-stat-detail th,.aa-stat-detail td{padding:1px 4px;border:1px solid var(--clr-border)}'
            . '.aa-stat-detail td:first-child{text-align:left}'
            . '.aa-stat-table summary{cursor:pointer;color:#2F3D99;font-size:90%}'
            . '</style>';
    }

    /**
     * Compact cache-state legend rendered as a <details> chip. Sits inline next to other controls
     * (e.g. the Slice Comparison's low-traffic toggle) so first-paint surfaces don't require prior
     * knowledge of the C / NC / M / NM vocabulary. The full legend lives in renderLegend(); this is
     * the abbreviated, controls-bar version.
     */
    private static function renderCacheLegendChip(): string
    {
        $h  = '<details class="aa-stat-legend-chip">';
        $h .= '<summary>' . \safe(\_m('What do C+M, NC+NM mean?')) . '</summary>';
        $h .= '<p><strong>' . \safe(\_m('Cache states.')) . '</strong> '
            . '<code>C</code> ' . \safe(\_m('cached')) . ', '
            . '<code>NC</code> ' . \safe(\_m('not cached')) . '. '
            . '<code>M</code> ' . \safe(\_m('body sent (HTTP 200)')) . ', '
            . '<code>NM</code> ' . \safe(\_m('not modified (HTTP 304)')) . '. '
            . \safe(\_m('Cache % counts C+M and C+NM as hits.'))
            . '</p>';
        $h .= '</details>';
        return $h;
    }

    private static function renderLegend(bool $single = false): string
    {
        $h  = '<div class="aa-stat-legend">';
        $h .= '<strong>' . \_m('Cache state') . ':</strong> ';
        $h .= '<code>C</code> = ' . \_m('page served from AA PageCache (cheap)') . '; ';
        $h .= '<code>NC</code> = ' . \_m('PageCache miss, page regenerated') . '; ';
        $h .= '<code>M</code> = ' . \_m('HTTP 200, full body sent') . '; ';
        $h .= '<code>NM</code> = ' . \_m('HTTP 304 Not Modified, body omitted') . '.<br>';
        $h .= '<strong>' . \_m('Combined') . ':</strong> ';
        $h .= '<code>NC+M</code> ' . \_m('normal request') . '; ';
        $h .= '<em><code>NC+NM</code> ' . \_m('wasted work: page rebuilt, then client cache matched (304)') . '</em>; ';
        $h .= '<code>C+M</code> ' . \_m('cheap request') . '; ';
        $h .= '<code>C+NM</code> ' . \_m('cheapest') . '.<br>';
        $h .= '<small>' . \_m('Background bars show value relative to row maximum (cache % uses raw value). Avg ms tints red as it grows.')
            . ($single ? '' : ' ' . \_m('The "all" column aggregates across queried items.')) . '</small>';
        $h .= '</div>';
        return $h;
    }

    private static function renderStatsTable(array $collected, array $s_ids, array $item_labels, array $col_max_ms, bool $do_duration, array $item_meta = [], bool $wide = false): string
    {
        $per_item_cols = $do_duration ? 4 : 1;

        // ---- Header
        // For a single item the table is narrow; --wide makes it span the full content width
        // ("tab wide") so the few columns aren't crammed into the left edge.
        $table_cls = 'aa-stat-table' . ($wide ? ' aa-stat-table--wide' : '');
        $ret  = "\n<div class=\"aa-stat-wrap\"><table class=\"$table_cls\">";
        $ret .= "\n  <thead>";
        if ($wide) {
            // Single-item view: one flat header row. No per-item name row + rowspan means the
            // sticky header can't misalign (the two-row variant pins row 2 at a fixed offset that
            // only matches a taller first row), and the item-name column-header - redundant on a
            // page that is already about this one item - is dropped.
            $ret .= "\n    <tr>\n      <th>" . \_m('Date') . "</th>";
            $ret .= "\n      <th>" . \_m('Hits') . "</th>";
            if ($do_duration) {
                $ret .= "\n      <th>" . \_m('Cache %') . "</th>";
                $ret .= "\n      <th>" . \_m('Avg ms') . "</th>";
                $ret .= "\n      <th>" . \_m('More') . "</th>";
            }
            $ret .= "\n    </tr>";
        } else {
            $ret .= "\n    <tr>";
            $ret .= "\n      <th rowspan=\"2\">" . \_m('Date') . "</th>";
            foreach ($s_ids as $sid) {
                $is_all   = ($sid === 'all');
                $cls      = trim(($is_all ? 'aa-stat-all ' : '') . ($is_all ? 'aa-stat-all-bdr' : ''));
                $cls_attr = $cls ? " class=\"$cls\"" : '';
                if ($is_all) {
                    $head_html = \safe('all');
                } else {
                    $headline_short = trim(\AA::Un("{shorten:{item:$sid:_#HEADLINE}:25:1}"));
                    $headline_full  = trim(\AA::Un("{item:$sid:_#HEADLINE}"));
                    $head_html      = self::renderItemLink(
                        (string) $sid,
                        $headline_short,
                        $headline_full,
                        $item_meta[$sid] ?? [],
                        'aa-stat-th-link',
                    );
                }
                $ret .= "\n      <th colspan=\"$per_item_cols\"$cls_attr>$head_html</th>";
            }
            $ret .= "\n    </tr>";
            $ret .= "\n    <tr>";
            foreach ($s_ids as $sid) {
                $is_all = ($sid === 'all');
                $base = $is_all ? 'aa-stat-all' : '';
                $bdr  = $is_all ? ' aa-stat-all-bdr' : '';
                $ret .= "\n      <th class=\"$base$bdr\">" . \_m('Hits') . "</th>";
                if ($do_duration) {
                    $ret .= "\n      <th class=\"$base\">" . \_m('Cache %') . "</th>";
                    $ret .= "\n      <th class=\"$base\">" . \_m('Avg ms') . "</th>";
                    $ret .= "\n      <th class=\"$base\">" . \_m('More') . "</th>";
                }
            }
            $ret .= "\n    </tr>";
        }
        $ret .= "\n  </thead>\n  <tbody>";

        // ---- Body
        foreach ($collected as $day => $bag) {
            $row_max_hits = $bag['row_max_hits'];
            $row          = $bag['items'];
            $ret .= "\n    <tr>";
            $ret .= "\n      <td>" . \safe((string) $day) . "</td>";
            foreach ($s_ids as $sid) {
                $r       = $row[$sid];
                $is_all  = ($sid === 'all');
                $all_cls = $is_all ? ' aa-stat-all' : '';
                $bdr_cls = $is_all ? ' aa-stat-all-bdr' : '';

                $h_pct  = $row_max_hits > 0 ? (int) round(($r['hits'] / $row_max_hits) * 100) : 0;
                $h_zero = ($r['hits'] === 0) ? ' aa-stat-zero' : '';
                $ret .= "\n      <td class=\"aa-stat-bar$all_cls$bdr_cls$h_zero\" style=\"--pct:$h_pct\">" . \safe((string) $r['hits']) . "</td>";

                if ($do_duration) {
                    $cp      = $r['cache_pct'];
                    $cp_pct  = is_null($cp) ? 0 : (int) $cp;
                    $cp_zero = (is_null($cp) || (int) $cp === 0) ? ' aa-stat-zero' : '';
                    $cp_str  = is_null($cp) ? '&ndash;' : \safe((string) $cp) . '%';
                    $ret .= "\n      <td class=\"aa-stat-bar aa-stat-bar--pct$all_cls$cp_zero\" style=\"--pct:$cp_pct\">$cp_str</td>";

                    $am       = $r['avg_ms'];
                    $col_max  = $col_max_ms[$sid] ?? 0.0;
                    $am_pct   = ($col_max > 0 && !is_null($am)) ? (int) round(((float) $am / $col_max) * 100) : 0;
                    $am_zero  = (is_null($am) || (float) $am === 0.0) ? ' aa-stat-zero' : '';
                    $am_str   = is_null($am) ? '&ndash;' : \safe((string) $am);
                    $ret .= "\n      <td class=\"aa-stat-bar aa-stat-bar--ms$all_cls$am_zero\" style=\"--pct:$am_pct\">$am_str</td>";

                    $ret .= "\n      <td class=\"$all_cls\">";
                    if ($r['bucket_sum']) {
                        $ret .= '<details><summary>' . \_m('more') . '</summary>';
                        $ret .= '<table class="aa-stat-detail"><thead><tr>';
                        $ret .= '<th>' . \_m('Cache state') . '</th><th>' . \_m('Hits') . '</th><th>' . \_m('Avg ms') . '</th>';
                        $ret .= '</tr></thead><tbody>';
                        $detail = [
                            [\_m('NC+M (normal)'),    $r['b0_cnt'], $r['b0_dur']],
                            [\_m('NC+NM (wasted)'),   $r['b1_cnt'], $r['b1_dur']],
                            [\_m('C+M (cheap)'),      $r['b2_cnt'], $r['b2_dur']],
                            [\_m('C+NM (cheapest)'),  $r['b3_cnt'], $r['b3_dur']],
                        ];
                        foreach ($detail as [$d_label, $d_cnt, $d_dur]) {
                            $d_zero = ((int) $d_cnt === 0) ? ' class="aa-stat-zero"' : '';
                            $ret .= '<tr' . $d_zero . '><td>' . \safe((string) $d_label) . '</td>'
                                  . '<td>' . \safe((string) $d_cnt) . '</td>'
                                  . '<td>' . ((int) $d_cnt ? \safe((string) $d_dur) : '&ndash;') . '</td></tr>';
                        }
                        $ret .= '</tbody></table></details>';
                    }
                    $ret .= '</td>';
                }
            }
            $ret .= "\n    </tr>";
        }
        $ret .= "\n  </tbody>\n</table></div>";
        return $ret;
    }

    private static function renderHitsChart(array $cols, array $labels, array $item_labels): string
    {
        $datasets = [];
        $i = 0;
        foreach ($cols as $sid => $sidcols) {
            $hue   = self::generateSaturatedColor($i++);
            $color = $sid === 'all' ? '#222' : self::hslToHex($hue, 70, 45);
            if (array_filter($sidcols['H'] ?? [], static fn($v) => $v !== 0)) {
                $datasets[] = ['label' => $item_labels[$sid] ?? $sid, 'data' => $sidcols['H'], 'color' => $color, 'dash' => [], 'width' => $sid === 'all' ? 3 : 2];
            }
        }
        if (empty($datasets)) {
            return '';   // no hits at all - skip the empty chart + heading
        }
        return '<h3>' . \_m('Hits') . '</h3>' . self::getGraph($datasets, $labels);
    }

    private static function renderCacheStateCharts(array $cols, array $s_ids, array $labels, array $item_labels): string
    {
        $ret = '';

        // Aggregate "all" stacked % — only meaningful when several items are combined, so it is
        // absent for a single-item view (no 'all' column) and skipped when it carries no data.
        if (isset($cols['all'])) {
            $sidcols = $cols['all'];
            foreach (['ncmH', 'ncnmH', 'cmH', 'cnmH'] as $t) {
                if (isset($sidcols[$t], $sidcols['H']) && count($sidcols[$t]) === count($sidcols['H'])) {
                    array_walk($sidcols[$t], static fn(&$v, $i) => $v = ($sidcols['H'][$i] ?? 0) != 0 ? $v * 100 / $sidcols['H'][$i] : 0);
                }
            }
            $datasets = [];
            foreach (self::CACHE_STATE_ORDER as [$key, $label, $color]) {
                if (!empty($sidcols[$key]) && array_filter($sidcols[$key], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => $label, 'data' => $sidcols[$key], 'color' => $color, 'dash' => []];
                }
            }
            if ($datasets) {
                $ret .= '<h3>' . \_m('Cache state breakdown for all queried items') . '</h3>';
                $ret .= self::getGraph($datasets, $labels, 'stacked', 'aa-stat-chart-pct');
            }
        }

        // Per-item mini stacks — built into a buffer so the heading is only emitted when at least
        // one item actually has cache-state data (no empty "Cache state per item" header).
        $minis       = '';
        $series_keys = ['cnmH', 'cmH', 'ncmH', 'ncnmH'];
        foreach (array_filter($s_ids, static fn($s) => $s !== 'all') as $sid) {
            if (!isset($cols[$sid])) {
                continue;
            }
            $sidcols = $cols[$sid];
            foreach ($series_keys as $t) {
                if (isset($sidcols[$t], $sidcols['H']) && count($sidcols[$t]) === count($sidcols['H'])) {
                    array_walk($sidcols[$t], static fn(&$v, $i) => $v = ($sidcols['H'][$i] ?? 0) != 0 ? $v * 100 / $sidcols['H'][$i] : 0);
                }
            }
            // trim leading zero days so wide pre-feature ranges don't squash real data
            $first_idx = null;
            $n_days    = count($labels);
            for ($i = 0; $i < $n_days; $i++) {
                foreach ($series_keys as $k) {
                    if (!empty($sidcols[$k][$i])) {
                        $first_idx = $i;
                        break 2;
                    }
                }
            }
            if ($first_idx === null) {
                continue;
            }
            $mini_labels = array_slice($labels, $first_idx);
            $datasets_i = [];
            foreach (self::CACHE_STATE_ORDER as [$key, $label, $color]) {
                if (empty($sidcols[$key])) {
                    continue;
                }
                $vals = array_slice($sidcols[$key], $first_idx);
                if (array_filter($vals, static fn($v) => $v !== 0)) {
                    $datasets_i[] = ['label' => $label, 'data' => $vals, 'color' => $color, 'dash' => []];
                }
            }
            if (empty($datasets_i)) {
                continue;
            }

            $label = $item_labels[$sid] ?? $sid;
            $minis .= '<div class="aa-stat-chart-mini-wrap">';
            $minis .= '<div class="aa-stat-chart-mini-title" title="' . \safe($label) . '">' . \safe($label) . '</div>';
            $minis .= self::getGraph($datasets_i, $mini_labels, 'stacked', 'aa-stat-chart-mini');
            $minis .= '</div>';
        }
        if ($minis !== '') {
            $ret .= '<h3>' . \_m('Cache state per item') . '</h3>';
            $ret .= '<div class="aa-stat-chart-grid">' . $minis . '</div>';
        }
        return $ret;
    }

    /**
     * Average page-render duration over time. $col_key picks which series to plot:
     * 'avgMs' (blended over all requests, default), or one of the four per-bucket
     * cohorts 'avgMsB0'..'avgMsB3' (NC+M / NC+NM / C+M / C+NM). A per-item line is
     * drawn only when it has at least $min_points non-zero values — a single point
     * is no trend — and the whole chart (heading included) is skipped when no line
     * qualifies. The colour index advances per item across all charts so the same
     * item keeps the same colour everywhere.
     */
    private static function renderAvgMsChart(array $cols, array $labels, array $item_labels, string $col_key = 'avgMs', ?string $title = null, int $min_points = 1): string
    {
        $datasets = [];
        $i = 0;
        foreach ($cols as $sid => $sidcols) {
            if (empty($sidcols[$col_key])) {
                continue;
            }
            $hue   = self::generateSaturatedColor($i++);
            $color = $sid === 'all' ? '#222' : self::hslToHex($hue, 70, 45);
            if (count(array_filter($sidcols[$col_key], static fn($v) => $v != 0)) >= $min_points) {
                $datasets[] = ['label' => $item_labels[$sid] ?? $sid, 'data' => $sidcols[$col_key], 'color' => $color, 'dash' => [], 'width' => $sid === 'all' ? 3 : 2];
            }
        }
        if (empty($datasets)) {
            return '';
        }
        return '<h3>' . ($title ?? \_m('Average duration (ms)')) . '</h3>' . self::getGraph($datasets, $labels);
    }

    /**
     * Render a Chart.js chart inside a responsive container.
     *
     * @param  array   $data         dataset list: [['label', 'data', 'color', 'dash', 'width']]
     * @param  array   $labels       x-axis labels
     * @param  string  $type         'line' (default) | 'stacked' (mapped to bar with stacked:true axes)
     * @param  string  $class_extra  extra CSS class on the chart container (e.g. 'aa-stat-chart-mini')
     */
    private static function getGraph(array $data, array $labels, string $type = 'line', string $class_extra = ''): string
    {
        $is_bar = ($type === 'stacked');
        $datasets = [];
        foreach ($data as $set) {
            $datasets[] = [
                'label'           => $set['label'],
                'data'            => $set['data'],
                'borderColor'     => $set['color'],
                'backgroundColor' => $set['color'],
                'borderDash'      => $set['dash'] ?? [],
                'tension'         => 0.2,
                'borderWidth'     => $is_bar ? 0 : ($set['width'] ?? 2),
            ];
        }

        $is_mini = str_contains($class_extra, 'aa-stat-chart-mini');

        $conf = [
            'type' => $type,
            'data' => ['labels' => $labels, 'datasets' => $datasets],
            'options' => [
                'responsive' => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'top', 'display' => !$is_mini],
                    'title'  => ['display' => false],
                ],
            ],
        ];
        if ($type === 'stacked') {
            $conf['type'] = 'bar';
            $conf['options']['scales'] = ['y' => ['stacked' => true], 'x' => ['stacked' => true]];
        }
        if ($is_mini) {
            $conf['options']['scales']             ??= [];
            $conf['options']['scales']['x']        ??= [];
            $conf['options']['scales']['y']        ??= [];
            $conf['options']['scales']['x']['ticks'] = ['display' => false];
            $conf['options']['scales']['y']['ticks'] = ['display' => false];
        }

        $chart_id = \gensalt(8);
        $cls      = trim('aa-stat-chart ' . $class_extra);

        $ret  = "<div class=\"$cls\">";
        $ret .= "<canvas id=\"$chart_id\"></canvas>";
        $ret .= "</div>";
        // JSON_HEX_TAG | JSON_HEX_AMP keeps an admin-typed dataset label like "Foo</script>"
        // from breaking out of this inline <script>. Cheap defense-in-depth — the chart
        // config carries slice/item names from the DB.
        $ret .= "<script> new Chart(document.getElementById('$chart_id'), " . json_encode($conf, JSON_HEX_TAG | JSON_HEX_AMP) . "); </script>";
        return $ret;
    }

    /** Hue (0-360) for indexed series — first 10 evenly spaced, then a slow drift for unique-ish 350+. */
    private static function generateSaturatedColor(int $index): float
    {
        if ($index < 10) {
            return ($index * 36) % 360;
        }
        return (($index - 10) * 1.06);
    }

    private static function hslToHex(float $h, float $s, float $l): string
    {
        $s /= 100;
        $l /= 100;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - ($c / 2);
        if ($h < 60) {
            $r = $c;
            $g = $x;
            $b = 0;
        } elseif ($h < 120) {
            $r = $x;
            $g = $c;
            $b = 0;
        } elseif ($h < 180) {
            $r = 0;
            $g = $c;
            $b = $x;
        } elseif ($h < 240) {
            $r = 0;
            $g = $x;
            $b = $c;
        } elseif ($h < 300) {
            $r = $x;
            $g = 0;
            $b = $c;
        } else {
            $r = $c;
            $g = 0;
            $b = $x;
        }
        return sprintf('#%02X%02X%02X', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
    }

    /**
     * CSS for the diagnostics panel (panel + cards + inline period form).
     */
    private static function renderTipsCss(): string
    {
        return '<style>
.aa-stat-tips{margin:0}                                          /* outer .aa-page-section now provides bg + border + padding; this is just a layout pass-through */
.aa-stat-tips-head{display:flex;align-items:center;flex-wrap:wrap;gap:.5em .75em;margin:0 0 .5em}
.aa-stat-tips-period{margin:0}
.aa-stat-tips-period select{font-size:90%;padding:1px 4px;border:1px solid #c8c8c8;border-radius:3px;background:#fff}
.aa-stat-tips-range{color:#777;font-size:90%}
.aa-stat-tips-empty{color:#777;margin:0}
.aa-stat-tip-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.5em}
.aa-stat-tip-card{border:1px solid #ddd;border-radius:4px;padding:.4em .6em;background:#fff;transition:border-color .15s}
.aa-stat-tip-card:has(.aa-stat-tip-link):hover{border-color:#888}
.aa-stat-tip-card h4{margin:0 0 .25em 0;font-size:90%;color:#555;font-weight:normal}
.aa-stat-tip-link{color:#06c;text-decoration:none}
.aa-stat-tip-link:hover{text-decoration:underline}
.aa-stat-tip-card ol{margin:0;padding-left:1.4em;font-size:90%}
.aa-stat-tip-card li{margin:.15em 0;display:flex;justify-content:space-between;gap:.5em}
.aa-stat-tip-name{flex:1 1 auto;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#06c;text-decoration:none}
.aa-stat-tip-name:hover{text-decoration:underline}
.aa-stat-tip-val{flex:0 0 auto;color:#222;font-variant-numeric:tabular-nums}
.aa-stat-slice-prefix{color:#888}
.aa-stat-item-sid{color:#aaa}
</style>';
    }

    // -------------------------------------------------------------------------
    // "Compare slices" — slice-level rollups + snapshot table + trend chart.
    // Used by admin/aa_statistics.php to surface which slice is dragging AA
    // cache health down at a glance, instead of forcing one-slice-at-a-time
    // navigation.
    // -------------------------------------------------------------------------

    /**
     * Per-slice rollups for the "Slice comparison" snapshot table.
     *
     * For each slice in $slice_ids, fetches its items in the window via
     * getGlobalTotals() (already ordered by hits DESC) and aggregates the
     * cache-hit ratio across cohorts: top-N for each N in $top_ns, plus
     * "all items in slice" (capped at $limit_per_slice — see note).
     *
     * The top-N cohorts answer "for the most-accessed items in this slice,
     * what % are served from cache?" — the right question for cache health:
     * long-tail items dominate item counts but not request volume, so a
     * blended slice-wide ratio can hide bad hot-item caching.
     *
     * Returns [slice_id => [
     *   'total_hits'         => int,
     *   'cache_pct_top<N>'   => float|null  for each N in $top_ns,
     *   'cache_pct_all'      => float|null,
     *   'avg_ms'             => float|null,
     * ]]. Slices with no hits in the window are omitted entirely.
     *
     * Note on $limit_per_slice: the top-N cohorts are exact as long as
     * N <= $limit_per_slice. "cache_pct_all" and "total_hits" approximate the
     * slice total — a slice with more than $limit_per_slice items with traffic
     * has its long tail truncated. 1000 covers nearly every realistic slice
     * over a daily window.
     *
     * @param  string[] $slice_ids        slice ids to compare
     * @param  int      $from             unix ts lower bound (inclusive)
     * @param  int      $to               unix ts upper bound (exclusive)
     * @param  int[]    $top_ns           cohort sizes, e.g. [1, 10, 50]
     * @param  int      $limit_per_slice  safety cap on items considered per slice
     */
    public static function getSliceTotals(array $slice_ids, int $from, int $to, array $top_ns = [1, 10, 50], int $limit_per_slice = 1000): array
    {
        $out = [];
        foreach ($slice_ids as $sid) {
            $items = self::getGlobalTotals([$sid], $from, $to, $limit_per_slice);
            if (empty($items)) {
                continue;
            }
            // getGlobalTotals already orders by total_hits DESC; reindex for array_slice.
            $items_ordered = array_values($items);

            $all_cache = $all_bucket = $all_hits = 0;
            $all_msx   = 0.0;
            foreach ($items_ordered as $t) {
                $all_hits   += $t['hits'];
                $all_cache  += $t['cache_hits'];
                $all_bucket += $t['bucket_sum'];
                $all_msx    += $t['ms_x_hits'];
            }

            $row = ['total_hits' => $all_hits];
            foreach ($top_ns as $n) {
                $cache = $bucket = 0;
                foreach (array_slice($items_ordered, 0, $n) as $t) {
                    $cache  += $t['cache_hits'];
                    $bucket += $t['bucket_sum'];
                }
                $row["cache_pct_top$n"] = $bucket > 0 ? ($cache / $bucket) * 100 : null;
            }
            $row['cache_pct_all'] = $all_bucket > 0 ? ($all_cache / $all_bucket) * 100 : null;
            $row['avg_ms']        = $all_bucket > 0 ? $all_msx / $all_bucket : null;

            $out[$sid] = $row;
        }
        return $out;
    }

    /**
     * Per-slice per-time-bucket cache% timeline — drives the comparison trend chart.
     *
     * Returns [slice_id => [bucket_label => ['hits' => int, 'cache_pct' => float|null]]].
     * Bucket labels come from MySQL DATE_FORMAT with a whitelisted format string —
     * no user input touches the SQL. Slices with no hits in any bucket are omitted.
     *
     * @param string[] $slice_ids
     * @param string   $granularity 'hour' | 'day' | 'week' | 'month' (anything else → day)
     */
    private static function getSliceTimeline(array $slice_ids, int $from, int $to, string $granularity): array
    {
        if ($from === 0 && $to === 0) {
            return [];
        }
        // Whitelisted MySQL DATE_FORMAT strings — caller passes a granularity name, never the format.
        $fmt = match ($granularity) {
            'hour'  => '%Y-%m-%d %H:00',
            'week'  => '%x-W%v',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $conds = [['hit_archive.duration', 0, '<>']];
        if ($from) {
            $conds[] = ['hit_archive.time', $from, '>='];
        }
        if ($to) {
            $conds[] = ['hit_archive.time', $to, '<'];
        }
        if ($slice_ids) {
            $conds[] = ['item.slice_id', $slice_ids, 'l'];
        }

        $sql = "SELECT
            LOWER(HEX(`item`.`slice_id`)) AS slice_id,
            DATE_FORMAT(FROM_UNIXTIME(`hit_archive`.`time`), '$fmt') AS bucket,
            SUM(`hits`) AS bucket_hits,
            SUM(CASE WHEN `duration` & 3 IN (2, 3) THEN `hits` ELSE 0 END) AS bucket_cache
        FROM `hit_archive` INNER JOIN `item` ON `hit_archive`.`id` = `item`.`short_id`";

        $rows = DB_AA::select([], $sql, $conds, ['bucket'], ['item.slice_id', 'bucket']);

        $out = [];
        foreach ($rows as $r) {
            $hits = (int) $r['bucket_hits'];
            if ($hits <= 0) {
                continue;
            }
            $cache = (int) $r['bucket_cache'];
            $out[(string) $r['slice_id']][(string) $r['bucket']] = [
                'hits'      => $hits,
                'cache_pct' => ($cache / $hits) * 100,
            ];
        }
        return $out;
    }

    /**
     * "Slice comparison" section — snapshot table + cache% trend chart.
     *
     * Renders one row per slice with cache% over top-1/10/50 (and "all") as
     * columns. Each cache-percent cell gets a length-encoded green-to-red bar
     * so outliers (a red cell among greens) are spottable without reading the
     * numbers. The slice name links into the existing diagnostics panel
     * re-scoped to that slice and pre-sorted by worst-cached, so the natural
     * next step — drill into the bad slice's worst items — is one click.
     *
     * Below the table, a line chart shows cache% per slice over time (top-N
     * slices by hits, to keep the chart readable). This answers "is this
     * regression new?" — most cache problems are dated, and easier to find
     * when you can see the cliff.
     *
     * @param string[] $slice_ids    slices to compare; caller must expand 'all' first
     *                               (this method does no permission scoping)
     * @param int      $from         unix ts lower bound (inclusive)
     * @param int      $to           unix ts upper bound (exclusive; 0 = no upper bound)
     * @param string   $period_label period name (e.g. 'last_week') preserved into drill-down links
     * @param int      $min_hits     hide slices with fewer hits in window — noise filter, toggleable
     * @param int      $chart_top_n  cap trend-chart series at top-N slices by hits
     */
    public static function renderSliceComparison(array $slice_ids, int $from, int $to, string $period_label, int $min_hits = 10, int $chart_top_n = 10): string
    {
        if (!(new AddDurationLog())->test()) {
            return '';   // duration log feature off — nothing to compare
        }
        if (empty($slice_ids)) {
            return '';   // caller must fill scope; we deliberately don't enumerate AA-wide here
        }

        $top_ns = [1, 10, 50];
        $rows   = self::getSliceTotals($slice_ids, $from, $to, $top_ns);
        if (empty($rows)) {
            // No <h3> here: the caller wraps this section in <details class="aa-page-section">
            // whose <summary> already carries the section title.
            return '<p class="aa-stat-compare-empty">' . \safe(\_m('No measured data for the selected scope and period.')) . '</p>';
        }

        // Trend-chart granularity follows window size — keeps point counts roughly bounded.
        $duration    = max(1, ($to ?: time()) - $from);
        $granularity = match (true) {
            $duration <= 36 * 3600   => 'hour',
            $duration <= 90 * 86400  => 'day',
            default                  => 'month',
        };
        $timeline = self::getSliceTimeline($slice_ids, $from, $to, $granularity);

        // Batched slice-name lookup — one SELECT beats N AA_Slice::getModuleProperty calls.
        $name_rows = DB_AA::select(
            [],
            'SELECT LOWER(HEX(`id`)) AS sid, `name` FROM `slice`',
            [['id', array_keys($rows), 'l']],
        );
        $names = [];
        foreach ($name_rows as $r) {
            $names[(string) $r['sid']] = (string) $r['name'];
        }

        // Default sort: biggest slices first — their cache health weighs most on AA-wide totals.
        // $rows is non-empty by the early-return above, so array_column always returns ≥1 value.
        uasort($rows, static fn($a, $b) => $b['total_hits'] <=> $a['total_hits']);
        $max_hits   = max(array_column($rows, 'total_hits'));
        $avgms_vals = array_filter(array_column($rows, 'avg_ms'), static fn($v) => $v !== null);
        $max_avgms  = $avgms_vals ? max($avgms_vals) : 1;

        $fmt_int = static fn($v) => number_format((int) $v, 0, '.', ' ');
        $fmt_ms  = static fn($v) => $v === null ? '–' : ($v < 10 ? number_format($v, 1) . ' ms' : number_format($v, 0) . ' ms');
        $fmt_pct = static fn($v) => $v === null ? '–' : number_format($v, 0) . '%';

        $cache_cols = [];
        foreach ($top_ns as $n) {
            $cache_cols[] = [
                'key'   => "cache_pct_top$n",
                'label' => sprintf(\_m('Cache %% top-%d'), $n),
                'hint'  => sprintf(\_m('Cache hit ratio across the top-%d items by hits in this slice. Higher is better.'), $n),
            ];
        }
        $cache_cols[] = [
            'key'   => 'cache_pct_all',
            'label' => \_m('Cache % all'),
            'hint'  => \_m('Cache hit ratio across every item with traffic in this slice (capped at 1000 items per slice).'),
        ];

        $toggle_js = "document.querySelectorAll('.aa-stat-compare-low')"
                   . ".forEach(r=>r.style.display=this.checked?'table-row':'none')";

        // No <h3> here: caller's <details class="aa-page-section"> <summary> carries the section title.
        // Controls row pairs the low-traffic toggle with a compact legend chip explaining the cache-state
        // vocabulary (C, NC, M, NM), so the column labels above the table do not require prior knowledge
        // of "C+M" / "NC+NM" jargon.
        $h  = '<div class="aa-stat-compare-controls">'
            . '<label><input type="checkbox" onchange="' . \safe($toggle_js) . '"> '
            . \safe(sprintf(\_m('Show low-traffic slices (< %d hits)'), $min_hits))
            . '</label>'
            . self::renderCacheLegendChip()
            . '</div>';

        $h .= '<div class="aa-stat-wrap">';
        $h .= '<table class="aa-stat-table aa-stat-compare">';
        $h .= '<thead><tr>';
        $h .= '<th>' . \safe(\_m('Slice')) . '</th>';
        $h .= '<th title="' . \safe(\_m('Total page hits in the window across all items in the slice.')) . '">' . \safe(\_m('Total hits')) . '</th>';
        foreach ($cache_cols as $col) {
            $h .= '<th title="' . \safe($col['hint']) . '">' . \safe($col['label']) . '</th>';
        }
        $h .= '<th title="' . \safe(\_m('Hits-weighted average page render time.')) . '">' . \safe(\_m('Avg ms')) . '</th>';
        $h .= '</tr></thead><tbody>';

        foreach ($rows as $sid => $r) {
            $is_low   = $r['total_hits'] < $min_hits;
            $row_cls  = $is_low ? ' class="aa-stat-compare-low"' : '';
            $drill_qs = [
                'for_module'  => $sid,
                'item_sort'   => 'worst-cached',
                'item_period' => $period_label,
                'tip_period'  => $period_label,
                'show'        => '1',
            ];
            $drill_href = \get_admin_url('aa_statistics.php', $drill_qs);
            $slice_nm   = $names[$sid] ?? $sid;
            $hits_pct   = ($r['total_hits'] / $max_hits) * 100;

            $h .= "<tr$row_cls>";
            $h .= '<td><a class="aa-stat-th-link" href="' . \safe($drill_href) . '" title="'
                . \safe(\_m('Drill into this slice. Worst-cached items first.')) . '">'
                . \safe($slice_nm) . '</a></td>';
            $h .= '<td class="aa-stat-bar aa-stat-bar--pct" style="--pct:' . (int) round($hits_pct) . '">'
                . \safe($fmt_int($r['total_hits'])) . '</td>';
            foreach ($cache_cols as $col) {
                $v = $r[$col['key']];
                if ($v === null) {
                    $h .= '<td class="aa-stat-zero">–</td>';
                } else {
                    $h .= '<td class="aa-stat-bar aa-stat-compare-cache" style="--pct:' . (int) round($v) . '">'
                        . \safe($fmt_pct($v)) . '</td>';
                }
            }
            $avg = $r['avg_ms'];
            if ($avg === null) {
                $h .= '<td class="aa-stat-zero">–</td>';
            } else {
                $ms_pct = min(100, ($avg / $max_avgms) * 100);
                $h .= '<td class="aa-stat-bar aa-stat-bar--ms" style="--pct:' . (int) round($ms_pct) . '">'
                    . \safe($fmt_ms($avg)) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';
        $h .= '</div>';

        // Trend chart: cache% over time, top-N slices by hits as series.
        $top_slices = array_slice(array_keys($rows), 0, $chart_top_n);
        $label_set  = [];
        foreach ($timeline as $by_bucket) {
            foreach (array_keys($by_bucket) as $bucket) {
                $label_set[$bucket] = true;
            }
        }
        $labels = array_keys($label_set);
        sort($labels);

        $datasets = [];
        $i = 0;
        foreach ($top_slices as $sid) {
            if (empty($timeline[$sid])) {
                continue;
            }
            $data = [];
            foreach ($labels as $b) {
                // null leaves a gap in the line — better than fabricating a 0% drop where there were no hits.
                $cell   = $timeline[$sid][$b] ?? null;
                $data[] = $cell ? round($cell['cache_pct'], 1) : null;
            }
            // single dot is no trend — skip series with fewer than two real points.
            if (count(array_filter($data, static fn($v) => $v !== null)) < 2) {
                continue;
            }
            $hue        = self::generateSaturatedColor($i++);
            $datasets[] = [
                'label' => $names[$sid] ?? $sid,
                'data'  => $data,
                'color' => self::hslToHex($hue, 70, 45),
                'dash'  => [],
                'width' => 2,
            ];
        }
        if (!empty($datasets)) {
            // Trend chart sits behind a closed <details> chip by default. Snapshot table answers
            // "which slice is broken right now"; the trend answers the less-common "when did it
            // regress" follow-up. Deferring it keeps the snapshot above the fold even when the
            // outer section is open. The chip's <summary> styling is inherited from admin-ecn.css.
            $h .= '<details class="aa-stat-trend-toggle">';
            $h .= '<summary>' . \safe(\_m('Cache % per slice over time')) . '</summary>';
            $h .= self::getGraph($datasets, $labels, 'line', 'aa-stat-chart-pct');
            $h .= '</details>';
        }

        // Compare-specific CSS (renderStatsCss below covers the table/bar primitives).
        // - .aa-stat-compare-cache: HSL hue derived from --pct so 0%→red, 100%→green.
        // - .aa-stat-compare-controls: flex row so the low-traffic toggle and the legend chip sit on one line.
        // - .aa-stat-legend-chip: inline-block chip; the <details summary> inherits the chip styling from admin-ecn.css.
        // - .aa-stat-trend-toggle: spacing around the trend-chart disclosure chip.
        // - .aa-stat-tip-more: separator above the period-over-period secondary cards <details>.
        $h .= '<style>'
            . '.aa-stat-compare-controls{display:flex;align-items:center;gap:1em;flex-wrap:wrap;margin:.5em 0;font-size:90%;color:#555}'
            . '.aa-stat-compare-controls label{display:inline-flex;align-items:center;gap:.4em}'
            . '.aa-stat-compare-low{display:none}'
            . '.aa-stat-compare-cache{--bar:hsl(calc(var(--pct,0) * 1.2),60%,55%)}'
            . '.aa-stat-compare-empty{padding:.5em;color:#777}'
            . '.aa-stat-legend-chip{display:inline-block;margin:0}'
            . '.aa-stat-legend-chip > summary{margin-bottom:0}'
            . '.aa-stat-legend-chip p{margin:.5em 0 0;max-width:60ch;color:#444;line-height:1.5}'
            . '.aa-stat-legend-chip code{background:#eee;padding:0 .25em;border-radius:2px}'
            . '.aa-stat-trend-toggle{margin:1em 0}'
            . '.aa-stat-trend-toggle > summary{margin-bottom:.5em}'
            . '.aa-stat-tip-more{margin-top:1em}'
            . '.aa-stat-tip-more > summary{margin-bottom:.5em}'
            . '</style>';
        $h .= self::renderStatsCss();

        return $h;
    }
}
