<?php
/**
 * PHP version 7.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malík, August 2023
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\Later\HitcounterStats;
use AA\Util\HitcounterDisplay;
use AA\Util\InputVars;
use AA\Widget\Widget;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/mgettext.php";
// ----------------------------------------------------------------------------------------
try {
    // GET-or-POST so diagnostics-card click links work (?item_sort=slowest&item_period=last_day)
    $items_code  = InputVars::stringGETPOST('items_code');
    $item_count  = InputVars::intGETPOST('item_count');
    $item_sort   = InputVars::stringGETPOST('item_sort');
    $item_period = InputVars::stringGETPOST('item_period');
    $tip_period  = InputVars::stringGETPOST('tip_period');
    $item_set    = InputVars::stringGETPOST('item_set');   // legacy single-selectbox value
    $date_from   = InputVars::stringGETPOST('date_from');
    $for_module  = InputVars::stringGETPOST('for_module');
    $show        = InputVars::boolGETPOST('show');
} catch (Exception $e) { AA_Adminpageutil::pageParameterError($e); }

if (!IfSlPerm(PS_EDIT_ALL_ITEMS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You have not permissions to see statistics"), "admin");
    exit;
}

// Bridge legacy `item_set` (single selectbox) → new `item_sort` × `item_period` pair.
// Old bookmarks like `item_set=last_day` map to (top hits, last day).
if (!$item_sort && !$item_period && $item_set) {
    if ($item_set === 'exact') {
        $item_sort   = 'exact';
        $item_period = 'last_day';
    } else {
        $item_sort   = 'top';
        $item_period = $item_set;     // 'all' | 'year' | … | 'last_day'
    }
}

$item_count  = $item_count  ?: 5;
$item_sort   = $item_sort   ?: 'top';
$item_period = $item_period ?: 'last_day';
$tip_period  = $tip_period  ?: 'last_day';   // diagnostics window — default last day (cheap on heavy sites)
$for_module  = $for_module  ?: 'all';
$date_from   = $date_from   ?: date('Y-m-d', strtotime('-1 month'));

//$script2run = '
//   var opts = { lineWrapping:true, matchBrackets: true, matchTags: true, viewportMargin: 10000, mode: "htmlmixed" };
//   window.cm_items  = CodeMirror.fromTextArea(document.getElementById("items"), opts);
//   ';
$apage = new AA_Adminpageutil('aaadmin','statistics');
$apage->setTitle(_m("Admin - Statistics"));
//$apage->addRequire($script2run, 'AA_Req_Load');
$apage->addRequire('chartjs@4');
$apage->printHead();

$form_buttons = ["show" => ["type"=>"submit", "value"=>_m("Show")]];

define('HITCOUNTER_OPTIONS', [
    'years'    => _m('years'),
    'months'   => _m('months'),
    'weeks'    => _m('weeks'),
    'days'     => _m('days'),
    'hours'    => _m('hours'),
    'minutes'  => _m('minutes'),
    'dayhours' => _m('day-hours'),
    'weekdays' => _m('week-days'),
]);

define('ITEM_SORTS', [
    'top'          => _m('Most hits'),
    'most-time'    => _m('Most server time'),
    'slowest'      => _m('Slowest (avg ms)'),
    'fastest'      => _m('Fastest (avg ms)'),
    'worst-cached' => _m('Worst cached'),
    'best-cached'  => _m('Best cached'),
    'most-wasted'  => _m('Most wasted (NC+NM)'),
    'slowed-down'  => _m('Slowed down vs prior period'),
    'sped-up'      => _m('Sped up vs prior period'),
    'exact'        => _m('Specified below'),
]);

define('ITEM_PERIODS', [
    'last_day'   => _m('Last day'),
    'last_week'  => _m('Last week'),
    'last_month' => _m('Last month'),
    'last_year'  => _m('Last year'),
    'day'        => _m('This day'),
    'week'       => _m('This week'),
    'month'      => _m('This month'),
    'year'       => _m('This year'),
    'all'        => _m('All time'),
]);

$MODULES_FROM = [
    'all' => _m('All AA'),
    'AA_OPTGROUP2' => _m('Sites'),
    ];

$MODULES_FROM += AA_Module::getModules('W', true, ['deleted','type','priority','name']);   // ORDER matches the DB index
$MODULES_FROM['AA_OPTGROUP3'] = _m('Slices');
$MODULES_FROM += AA_Module::getModules('S', true, ['deleted','type','priority','name']);   // ORDER matches the DB index

// Diagnostics scope (slices) — needed before form to render the panel above it.
$slices = [];
if ($for_module !== 'all') {
    $type = AA_Module::getModuleType($for_module);
    if ($type === 'W') {
        $slices = AA_Module_Site::getRelatedModules([$for_module]);
    } elseif ($type === 'S') {
        $slices = [$for_module];
    }
    if (!$slices) {
        $slices = ['11112222333344445555666677778888'];   // sentinel — no slice for this site
    }
}

// Quick diagnostics panel — rendered ABOVE the form. Independent of the form's
// item-selection choices: shows AA-wide (or per-slice) ranked top-N over the
// tip_period window. Card click-throughs land in the form below.
//
// Wrapped in <details class="aa-page-section"> with $show-driven open state: when
// the operator submits the form (show=1) and the per-day table renders below, the
// diagnostics auto-collapse so the answer sits above the fold. On first load
// (show=0) the diagnostics stay open as the quick orientation surface. The
// section <h2> lives in <summary>; the panel itself no longer emits its own <h3>.
$diag_open = !$show ? ' open' : '';
echo "<details class=\"aa-page-section\"$diag_open>";
echo '<summary><h2 class="aa-tab-title">' . _m('Quick diagnostics') . '</h2></summary>';
echo HitcounterDisplay::renderDiagnosticsPanel(
    $slices,
    $tip_period,
    (int)$item_count,
    [
        'for_module' => $for_module,
        'date_from'  => $date_from,
    ]
);
echo '</details>';

// Slice comparison — same scope expansion, uses item_period as the window so all
// three sections (diagnostics, comparison, per-item table below) stay aligned.
// for_module='all' (the default) expands to every slice the operator can edit;
// a Site or Slice scope is used verbatim. Only renders when there is more than
// one slice to compare — a single-row table tells the operator nothing.
$compare_slices = $slices;
if (empty($compare_slices)) {
    $perm_slices    = GetUserSlices(PS_EDIT_ALL_ITEMS);
    $compare_slices = $perm_slices === 'all'
        ? array_keys(AA_Module::getModules('S', true))
        : $perm_slices;
}
if (count($compare_slices) > 1) {
    [$compare_from, $compare_to] = HitcounterDisplay::periodToRange($item_period, time());
    // Same $show-driven open state as Quick Diagnostics: open on first paint, collapses when the
    // per-day table becomes the hero below. Section <h2> in <summary>.
    $cmp_open = !$show ? ' open' : '';
    echo "<details class=\"aa-page-section\"$cmp_open>";
    echo '<summary><h2 class="aa-tab-title">' . _m('Slice comparison') . '</h2></summary>';
    echo HitcounterDisplay::renderSliceComparison($compare_slices, $compare_from, $compare_to, $item_period);
    echo '</details>';
}

// Granularity widget (mch multi-checkbox) needs $content prepared up-front so it
// can render with the right pre-checked values.
$w = Widget::factoryByName('mch', ['const_arr' => HITCOUNTER_OPTIONS]);
if (is_array($_POST['aa'] ?? null)) {
    try {
        $grabber = new AA\IO\Grabber\ObjectForm();
        $grabber->prepare();
        $content = $grabber->getItem();
        $grabber->finish();
    } catch (Exception $e) {
        $content = (new AA_Content())->setId('admin_statistics');
    }
} else {
    // first-load default: pre-check the "days" granularity
    $content = (new AA_Content())->setId('admin_statistics')->setValue('stats', 'days');
}

// ---- Section 1: Stats to show (granularity for the per-day table) ----
FrmTabCaption(_m('Stats to show'));
echo $w->getHtml(new AA_Property('stats', _m('Granularity'), 'string', true), $content);
FrmTabEnd();

// ---- Section 2: Items to show (which items to put in the table) ----
FrmTabCaption(_m('Items to show'));
FrmStaticText(_m('Display for module'), getFrmSelectEasy('for_module', $MODULES_FROM, $for_module), '', '', false);
FrmInputSelect('item_sort',   _m('Sort items by'),         ITEM_SORTS,   $item_sort,   true);
FrmInputSelect('item_period', _m('Period (item ranking)'), ITEM_PERIODS, $item_period, true);
FrmTextarea('items_code', _m('Exact items to show'), $items_code, 4, 60, false, _m('dash-separated list of item ids/short_ids or AA expression like {ids:{_#SLICE_ID}:d-publish_date....-gt-{timestamp:2023-08-22}}}'));
FrmInputNumber('item_count', _m('Number of items to show'), $item_count, 1, 9999, true);
FrmTabEnd();

// ---- Section 3: Show for period (table time window) — Show button lives here ----
FrmTabCaption(_m('Show for period'));
FrmInputDatetime('date_from', _m('From date'), $date_from, );
FrmTabEnd($form_buttons);

if ($show) {

    // Per-day detail wrapped in <details class="aa-page-section" open>: the operator just submitted
    // the form to see these results, so this section is always expanded on render. The matching
    // </details> closes at the end of this block (after the table or the "no items" message).
    echo '<br><br><details class="aa-page-section" open>';
    echo '<summary><h2 class="aa-tab-title">'._m('Display Statistics for Item').'</h2></summary>';

    // ranking time window from $item_period
    $now = time();
    [$rank_from, $rank_to] = match ($item_period) {
        'all'        => [0,                                  0],
        'year'       => [$now - 60 * 60 * 24 * 365,          0],
        'month'      => [$now - 60 * 60 * 24 * 30,           0],
        'week'       => [$now - 60 * 60 * 24 * 7,            0],
        'day'        => [$now - 60 * 60 * 24,                0],
        'last_year'  => [$now - 60 * 60 * 24 * 365 * 2,      $now - 60 * 60 * 24 * 365],
        'last_month' => [$now - 60 * 60 * 24 * 30 * 2,       $now - 60 * 60 * 24 * 30],
        'last_week'  => [$now - 60 * 60 * 24 * 7 * 2,        $now - 60 * 60 * 24 * 7],
        'last_day'   => [$now - 60 * 60 * 24 * 2,            $now - 60 * 60 * 24],
        default      => [$now - 60 * 60 * 24 * 2,            $now - 60 * 60 * 24],
    };

    // items selection — combine sort × period
    if ($item_sort === 'exact') {
        $items = $items_code;
    } elseif ($item_sort === 'top') {
        // ranking by hits — uses HitcounterStats::getStats (existing path)
        $items = implode('-', array_keys(HitcounterStats::getStats($slices, $rank_from, $rank_to, true, $item_count)));
    } else {
        // ranking by performance metrics — uses Hitcounter::getGlobalTotals.
        $rank_to_safe = $rank_to ?: $now;
        $globals = HitcounterDisplay::getGlobalTotals($slices, $rank_from, $rank_to_safe, 10000);

        // slowed-down / sped-up need a second query for the prior-equivalent window
        $globals_prev = [];
        if ($item_sort === 'slowed-down' || $item_sort === 'sped-up') {
            $duration_secs = max(1, $rank_to_safe - $rank_from);
            $prev_from     = $rank_from - $duration_secs;
            $globals_prev  = HitcounterDisplay::getGlobalTotals($slices, $prev_from, $rank_from, 10000);
        }

        $scored = [];
        if ($item_sort === 'slowed-down' || $item_sort === 'sped-up') {
            foreach ($globals as $sid => $tc) {
                $tp = $globals_prev[$sid] ?? null;
                if (!$tp) continue;
                if ($tc['bucket_sum'] < 5 || $tp['bucket_sum'] < 5) continue;
                $cur_avg = $tc['ms_x_hits'] / $tc['bucket_sum'];
                $prv_avg = $tp['ms_x_hits'] / $tp['bucket_sum'];
                if ($prv_avg <= 0) continue;
                $scored[$sid] = (($cur_avg - $prv_avg) / $prv_avg) * 100;   // % delta
            }
            $desc = ($item_sort === 'slowed-down');
        } else {
            $key_fn = match ($item_sort) {
                'slowest'      => static fn($t) => $t['bucket_sum'] >= 5 ? $t['ms_x_hits'] / $t['bucket_sum'] : null,
                'fastest'      => static fn($t) => $t['bucket_sum'] >= 5 ? $t['ms_x_hits'] / $t['bucket_sum'] : null,
                'worst-cached' => static fn($t) => $t['bucket_sum'] >= 5 ? ($t['cache_hits'] / $t['bucket_sum']) * 100 : null,
                'best-cached'  => static fn($t) => $t['bucket_sum'] >= 5 ? ($t['cache_hits'] / $t['bucket_sum']) * 100 : null,
                'most-wasted'  => static fn($t) => $t['wasted'] > 0 ? $t['wasted'] : null,
                'most-time'    => static fn($t) => $t['ms_x_hits'],
                default        => static fn($t) => $t['hits'],
            };
            $desc = !in_array($item_sort, ['fastest', 'best-cached'], true);
            foreach ($globals as $sid => $t) {
                $v = $key_fn($t);
                if ($v === null) continue;
                $scored[$sid] = $v;
            }
        }
        $desc ? arsort($scored) : asort($scored);
        $items = implode('-', array_slice(array_keys($scored), 0, $item_count));
    }

    if (strlen($items)) {
        $from = $date_from ? strtotime($date_from) : 0;
        $template = '<div class=aa-table>';
        foreach ($content->getValuesArray('stats') as $code) {
            $name = HITCOUNTER_OPTIONS[$code];
            $template .= "<h2>$name</h2>\n{hitcounter:$code:$items:$from}\n";
        }
        $template .= '</div>';
        $template .= '<div>'._m('Items found').': '. StrExp('count', [$items]).'</div>';
        //FrmStaticText(_m('Display Statistics for Item'), AA::Un($template), '', '', false);
        echo AA::Un($template);
    } else {
        echo MsgOk(_m('No items found'));
    }
    echo '</details>';
}
// echo getFrmTabEnd($form_buttons);

if ($tab = AA_Log::getLogTable('SYS_STAT', 'basic', $from ?: time()-60*60*24*7 , 0, IsSuperadmin())) {
    echo '<br><br><br>';
    $apage->addHelpbox(_m('Server configuration changes'), $tab);
}



$apage->printFoot();
