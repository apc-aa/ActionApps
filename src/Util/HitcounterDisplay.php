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
                    ? $title_help . ' — ' . \_m('click to show these items in the table')
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

        $h  = '<div class="aa-stat-tips">';
        $h .= '<div class="aa-stat-tips-head">';
        $h .= '<h3>' . \safe(\_m('Quick diagnostics')) . '</h3>';
        $h .= $period_select;
        $h .= '<small class="aa-stat-tips-range">' . $period_lbl . ' &middot; ' . $scope_lbl . '</small>';
        $h .= '</div>';

        if (empty($globals)) {
            $h .= '<p class="aa-stat-tips-empty">' . \safe(\_m('No measured data in the selected window — Diagnostics needs at least the AddDurationLog feature on and a non-empty period.')) . '</p>';
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
                \_m('Items with the most page views over the diagnostics window — where the public traffic is going.'),
                $top_hits,
                fn($v, $sid) => \safe($fmt_int($v)) . ' hits',
                'top',
            );
            $h .= $card_html(
                \_m('Heaviest (ms × hits)'),
                \_m('Items where the server spent the most aggregate render time across all hits, i.e. avg page render time × hit count. The biggest performance levers — even a small per-hit speedup compounds across many requests.'),
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
                \_m('Items with the lowest AA PageCache hit ratio — fraction of requests served from the cache vs regenerated fresh. Low % means most requests pay the regeneration cost.'),
                $top_lowc,
                fn($v, $sid) => \safe(number_format($v, 0)) . '%',
                'worst-cached',
            );
            $h .= $card_html(
                \_m('Most wasted (NC+NM)'),
                \_m('Items with the most "wasted work" hits — page regenerated fresh AND then a 304 returned because the client already had it. Pure server cost with no benefit.'),
                $top_waste,
                fn($v, $sid) => \safe($fmt_int($v)) . ' hits',
                'most-wasted',
            );

            if ($prev_tip_from > 0) {
                $compared = ' (' . \safe($fmt_date($prev_tip_from)) . ' → ' . \safe($fmt_date($prev_tip_to)) . ')';
                $h .= $card_html(
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
                $h .= $card_html(
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

        $s_ids = $s_zids->shortids();
        if (in_array('SUM', $reports, true)) {
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
                $item_labels[$sid] = $headline !== '' ? "$sid — $headline" : (string) $sid;
            }
        }

        // ---- Per-item metadata (long_id, slice_id, slice_short) for header links
        $item_meta = self::buildItemMeta($s_ids);

        // ---- Render: CSS + legend + table + charts
        $ret  = self::renderStatsCss();
        $ret .= self::renderLegend();
        $ret .= self::renderStatsTable($collected, $s_ids, $item_labels, $col_max_ms, $do_duration, $item_meta);
        $ret .= self::renderHitsChart($cols, $labels, $item_labels);
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
        $slice_short = $meta['slice_short'] ?? '';
        $slice_name  = $meta['slice_name']  ?? '';
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
            . '.aa-stat-table th,.aa-stat-table td{padding:2px 6px;text-align:right;white-space:nowrap;line-height:1.3}'
            . '.aa-stat-table thead th{position:sticky;background-color:var(--clr-basic-dark,#e8e3d4);color:rgb(0 0 0 / 80%);font-weight:normal;border-left:solid 1px rgb(255 255 255 / 45%);border-top:solid 1px rgb(255 255 255 / 45%);z-index:5}'
            . '.aa-stat-table thead tr:first-child th{top:0;z-index:6}'
            . '.aa-stat-table thead tr:nth-child(2) th{top:32px;box-shadow:0 2px 4px rgba(0,0,0,.08)}'
            . '.aa-stat-table tbody td:first-child{position:sticky;left:0;background-color:#fff;z-index:3;text-align:left}'
            . '.aa-stat-table tbody tr:nth-of-type(odd) td:first-child{background-color:rgb(255 255 255 / 100%)}'
            . '.aa-stat-table tbody tr:nth-of-type(even) td:first-child{background-color:#f3efe6}'
            . '.aa-stat-table thead tr:first-child th:first-child{left:0;z-index:7;text-align:left}'
            . '.aa-stat-table thead tr:nth-child(2) th:first-child{left:0;z-index:6;text-align:left}'
            . '.aa-stat-table th:nth-child(4n+2),.aa-stat-table td:nth-child(4n+2){border-left:1px solid rgb(0 0 0 / 8%)}'
            /* line charts (Hits, Avg duration) — short and wide; max-height caps them so they
               don't dominate tall viewports. Stacked-bar variants override aspect-ratio below. */
            . '.aa-stat-chart{position:relative;width:100%;aspect-ratio:3/1;max-height:50vh;margin:1em 0}'
            . '@media (max-width:900px){.aa-stat-chart{aspect-ratio:5/2}}'
            . '.aa-stat-chart-pct{aspect-ratio:5/1}'
            . '.aa-stat-chart-grid{display:flex;flex-wrap:wrap;gap:1em;margin:1em 0}'
            . '.aa-stat-chart-mini-wrap{display:flex;flex-direction:column;width:280px}'
            . '.aa-stat-chart-mini{width:280px;max-width:280px;aspect-ratio:1/1;margin:0;flex:0 0 auto}'
            . '.aa-stat-chart-mini-title{font-size:90%;color:#555;margin-bottom:.25em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px}'
            . '.aa-stat-legend{margin:.5em 0;color:#555;font-size:90%}'
            . '.aa-stat-legend code{background:#eee;padding:0 .2em}'
            . '.aa-stat-bar{background-image:linear-gradient(to right,var(--bar,#7a9) calc(var(--pct,0)*1%),transparent 0);background-repeat:no-repeat;background-position:left bottom;background-size:100% 3px}'
            . '.aa-stat-bar--pct{--bar:#5b8}'
            . '.aa-stat-bar--ms{--bar:hsl(calc(120 - var(--pct,0) * 1.2),60%,55%)}'
            . '.aa-stat-zero{color:#aaa}'
            . '.aa-stat-all-bdr{border-left:2px solid #999}'
            . '.aa-stat-all{background-color:#f0f0f0!important;font-weight:bold}'
            . '.aa-stat-slice-prefix{color:#888}'
            . '.aa-stat-item-sid{color:#aaa}'
            . '.aa-stat-th-link{color:inherit;text-decoration:none}'
            . '.aa-stat-th-link:hover{text-decoration:underline}'
            . '.aa-stat-detail{font-size:90%;margin:.3em 0;border-collapse:collapse}'
            . '.aa-stat-detail th,.aa-stat-detail td{padding:1px 4px;border:1px solid #ddd}'
            . '.aa-stat-detail td:first-child{text-align:left}'
            . '.aa-stat-table summary{cursor:pointer;color:#06c;font-size:90%}'
            . '</style>';
    }

    private static function renderLegend(): string
    {
        $h  = '<div class="aa-stat-legend">';
        $h .= '<strong>' . \_m('Cache state') . ':</strong> ';
        $h .= '<code>C</code> = ' . \_m('page served from AA PageCache (cheap)') . '; ';
        $h .= '<code>NC</code> = ' . \_m('PageCache miss — page regenerated') . '; ';
        $h .= '<code>M</code> = ' . \_m('HTTP 200, full body sent') . '; ';
        $h .= '<code>NM</code> = ' . \_m('HTTP 304 Not Modified, body omitted') . '.<br>';
        $h .= '<strong>' . \_m('Combined') . ':</strong> ';
        $h .= '<code>NC+M</code> ' . \_m('normal request') . '; ';
        $h .= '<em><code>NC+NM</code> ' . \_m('wasted work: page rebuilt, then client cache matched (304)') . '</em>; ';
        $h .= '<code>C+M</code> ' . \_m('cheap request') . '; ';
        $h .= '<code>C+NM</code> ' . \_m('cheapest') . '.<br>';
        $h .= '<small>' . \_m('Background bars show value relative to row maximum (cache % uses raw value). Avg ms tints red as it grows. The "all" column aggregates across queried items.') . '</small>';
        $h .= '</div>';
        return $h;
    }

    private static function renderStatsTable(array $collected, array $s_ids, array $item_labels, array $col_max_ms, bool $do_duration, array $item_meta = []): string
    {
        $per_item_cols = $do_duration ? 4 : 1;

        // ---- Header
        $ret  = "\n<div class=\"aa-stat-wrap\"><table class=\"aa-stat-table\">";
        $ret .= "\n  <thead>\n    <tr>";
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
        $ret .= "\n    </tr>\n  </thead>\n  <tbody>";

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
                    $cp_str  = is_null($cp) ? '&mdash;' : \safe((string) $cp) . '%';
                    $ret .= "\n      <td class=\"aa-stat-bar aa-stat-bar--pct$all_cls$cp_zero\" style=\"--pct:$cp_pct\">$cp_str</td>";

                    $am       = $r['avg_ms'];
                    $col_max  = $col_max_ms[$sid] ?? 0.0;
                    $am_pct   = ($col_max > 0 && !is_null($am)) ? (int) round(((float) $am / $col_max) * 100) : 0;
                    $am_zero  = (is_null($am) || (float) $am === 0.0) ? ' aa-stat-zero' : '';
                    $am_str   = is_null($am) ? '&mdash;' : \safe((string) $am);
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
                                  . '<td>' . ((int) $d_cnt ? \safe((string) $d_dur) : '&mdash;') . '</td></tr>';
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
        $ret = '<h3>' . \_m('Hits') . '</h3>';
        $datasets = [];
        $i = 0;
        foreach ($cols as $sid => $sidcols) {
            $hue   = self::generateSaturatedColor($i++);
            $color = $sid === 'all' ? '#222' : self::hslToHex($hue, 70, 45);
            if (array_filter($sidcols['H'] ?? [], static fn($v) => $v !== 0)) {
                $datasets[] = ['label' => $item_labels[$sid] ?? $sid, 'data' => $sidcols['H'], 'color' => $color, 'dash' => [], 'width' => $sid === 'all' ? 3 : 2];
            }
        }
        return $ret . self::getGraph($datasets, $labels);
    }

    private static function renderCacheStateCharts(array $cols, array $s_ids, array $labels, array $item_labels): string
    {
        // Aggregate "all" stacked %
        $sidcols  = $cols['all'] ?? [];
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
        $ret = '<h3>' . \_m('Cache state breakdown for all queried items') . '</h3>';
        $ret .= self::getGraph($datasets, $labels, 'stacked', 'aa-stat-chart-pct');

        // Per-item mini stacks
        $item_sids = array_filter($s_ids, static fn($s) => $s !== 'all');
        if (count($item_sids) > 0) {
            $ret .= '<h3>' . \_m('Cache state per item') . '</h3>';
            $ret .= '<div class="aa-stat-chart-grid">';
            $series_keys = ['cnmH', 'cmH', 'ncmH', 'ncnmH'];
            foreach ($item_sids as $sid) {
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
                $ret .= '<div class="aa-stat-chart-mini-wrap">';
                $ret .= '<div class="aa-stat-chart-mini-title" title="' . \safe($label) . '">' . \safe($label) . '</div>';
                $ret .= self::getGraph($datasets_i, $mini_labels, 'stacked', 'aa-stat-chart-mini');
                $ret .= '</div>';
            }
            $ret .= '</div>';
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
        $ret .= "<script> new Chart(document.getElementById('$chart_id'), " . json_encode($conf) . "); </script>";
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
.aa-stat-tips{margin:.25em 0 1em;padding:.75em 1em;background:#fafafa;border:1px solid #e2e2e2;border-radius:4px}
.aa-stat-tips-head{display:flex;align-items:center;flex-wrap:wrap;gap:.5em .75em;margin:0 0 .5em}
.aa-stat-tips-head h3{margin:0;font-size:1em;color:#333}
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
}
