<?php

/**
 * State Check - one page that says whether this installation is healthy: the
 * findings of every Optimize class tagged as a check (AbstractOptimize::CHECK_*),
 * worst first, with counts, evidence and what to do. Basic checks run on every
 * load; extended ones daily and on request, slow ones on request only.
 * Optimize (aa_optimize.php) stays the workshop with every action; this page
 * is the overview.
 *
 * PHP version 8.2+
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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckRunner;
use AA\Optimize\Finding;
use AA\Optimize\StateCheckItem;
use AA\Optimize\StateCheckModel;
use AA\Util\FormToken;
use AA\Util\InputVars;
use AA\Util\Note;
use AA\Util\Notes;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/mgettext.php";
// the nightly test pushing its findings - answered with JSON, never with the page
if (isset($_POST[\AA\Optimize\NightlyImport::FIELD])) {
    \AA\Optimize\NightlyImport::respond();
}
// ----------------------------------------------------------------------------------------
try {
    $run_extended = InputVars::boolPOST('run_extended');
    $run_slow     = InputVars::boolPOST('run_slow');
    $repair       = InputVars::stringPOST('repair');
    $token        = InputVars::stringPOST('token');
    $recheck      = InputVars::stringPOST('recheck');
    $recheck_key  = InputVars::stringPOST('key');
    $format       = InputVars::stringGET('format');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

if (!IsSuperadmin()) {
    if ($format === 'json') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['ok' => false, 'error' => 'State Check is for superadmins only']);
        exit;
    }
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to see State Check"), "admin");
    exit;
}

const STATECHECK_TAGS = StateCheckModel::TAGS;
const STATECHECK_TOKEN = 'aa_statecheck';

/** an empty cell: a dash to the eye, $missing to a screen reader */
function statecheck_dash(string $missing): string
{
    return '<span class="aa-sc-none"><span aria-hidden="true">—</span><span class="aa-sc-sr">' . safe($missing) . '</span></span>';
}

/** a time cell: how long ago for scanning, the exact moment as the tooltip */
function statecheck_when(int $time, string $label): string
{
    return ($label === '') ? statecheck_dash(_m('unknown')) : '<time datetime="' . date('c', $time) . '" title="' . date('Y-m-d H:i', $time) . '">' . safe($label) . '</time>';
}

/** a POST button of this page; posting to #id brings the reader back to the item */
function statecheck_button(string $id, string $name, string $value, string $label, string $hidden = ''): string
{
    return '<form method="post" action="#' . safe($id) . '" class="aa-statecheck-inline">' . FormToken::field(STATECHECK_TOKEN) . $hidden
        . '<button class="aa-button-run" type="submit" name="' . $name . '" value="' . safe($value) . '">' . $label . '</button></form>';
}

/** One row: how bad, what, its state and when; it opens into why and what to do, the
 *  evidence, where it happens, how to verify a fix and the actions that can really be done.
 *  A group row opens into its members, each a row of its own with its own anchor.
 *  The severity is a word in a wash - never a red frame or stripe (Reserved-Red Rule).
 * @param string $context        the section it is in, or its group's state for a member - its chip says only what that does not
 * @param string $shared_advice  the advice its group already shows - not repeated in a member
 */
function statecheck_item(StateCheckItem $item, int $now, string $context, string $shared_advice = ''): string
{
    $back  = get_admin_url('aa_statecheck.php') . '#' . $item->id;
    $wash  = match ($item->severity) {
        Finding::ERROR   => ' aa-err',
        Finding::WARNING => ' aa-info',
        Finding::OK      => ' aa-ok',
        default          => '',
    };
    $label = $item->chip($context);
    $chips = ($label === '') ? '' : '<span class="aa-sc-state aa-sc-state-' . $item->state . '">' . safe($label) . '</span>';
    $heads = StateCheckModel::labels();
    $last  = $item->holds_now ? '<span>' . safe($item->lastSeenLabel($now)) . '</span>' : statecheck_when($item->last_seen, $item->lastSeenLabel($now));
    $times = '<span class="aa-sc-last" data-l="' . safe($heads['last_seen']) . '">' . $last . '</span>'
           . '<span class="aa-sc-first" data-l="' . safe($heads['since']) . '">' . statecheck_when($item->first_seen, $item->sinceLabel($now)) . '</span>'
           . '<span class="aa-sc-count">' . ($item->count ? $item->count . '×' : statecheck_dash(_m('not counted'))) . '</span>';

    $body = (($item->advice !== '') && ($item->advice !== $shared_advice)) ? '<p class="aa-sc-advice">' . safe($item->advice) . '</p>' : '';
    // a resolution shows as the note itself: who, when, why, and Unresolve
    $body .= $item->note ? Notes::render($item->note, $back) : (($item->reason !== '') ? '<p class="aa-sc-reason">' . safe($item->reason) . '</p>' : '');
    $verify = ($item->verify === '') ? '' : '<p class="aa-sc-verify"><span class="aa-sc-lbl">' . _m('Verify again') . '</span>' . safe($item->verify) . '</p>';
    if ($item->members) {
        $body .= $verify . '<div class="aa-sc-members">' . implode('', array_map(static fn(StateCheckItem $m) => statecheck_item($m, $now, $item->state, $item->advice), $item->members)) . '</div>';
    } else {
        if ($item->finding?->evidence) {
            $body .= GetHtmlTable($item->finding->evidence, 'key');
        }
        if ($item->where) {
            $body .= '<p class="aa-sc-where"><span class="aa-sc-lbl">' . _m('Where') . '</span>' . implode(' ', array_map(static fn(array $w) => '<a href="' . safe($w['url']) . '">' . safe($w['label']) . '</a>', $item->where)) . '</p>';
        }
        $body   .= $verify;
        $key     = $item->recheckKey();
        $actions = [];
        if ($item->can(StateCheckModel::ACTION_REPAIR)) {
            $actions[] = statecheck_button($item->id, 'repair', $item->check, _m('Repair'));
        }
        if ($item->can(StateCheckModel::ACTION_RESOLVE)) {     // only a row with a finding can be resolved
            $actions[] = Notes::resolveForm(Note::resource('finding', $item->check, $key), $back);
        }
        if ($item->can(StateCheckModel::ACTION_RECHECK)) {
            $actions[] = statecheck_button($item->id, 'recheck', $item->check, _m('Recheck'), '<input type="hidden" name="key" value="' . safe($key) . '">');
        }
        if ($actions) {
            $body .= '<div class="aa-sc-actions">' . implode(' ', $actions) . '</div>';
        }
        $body .= '<p class="aa-sc-meta">' . safe($item->check) . ($item->finding ? sprintf(' · %.2f s', $item->finding->seconds) : '') . '</p>';
    }

    return '<details class="aa-sc-item' . ($item->members ? ' aa-sc-group' : '') . '" id="' . safe($item->id) . '" data-state="' . $item->state . '" data-severity="' . $item->severity . '">'
        . '<summary><span class="aa-sc-sev' . $wash . '">' . safe(StateCheckModel::severityLabel($item->severity)) . '</span>'
        . '<span class="aa-sc-main"><span class="aa-sc-title">' . safe($item->title) . '</span>' . $chips
        . '<span class="aa-sc-cat">' . safe($item->category) . '</span></span>'
        . '<span class="aa-sc-times">' . $times . '</span></summary>'
        . '<div class="aa-sc-body">' . $body . '</div></details>';
}

/** @param int                $count       rows, shown in the header, so a closed section still says how much is inside
 *  @param array<string, int> $severities  rows per severity - a section closed by default marks the problems inside
 *  @param string             $intro       under the head: what belongs there */
function statecheck_section(string $id, string $title, string $intro, string $body, bool $open, int $count, array $severities): void
{
    $heads = StateCheckModel::labels();
    $head  = '<p class="aa-sc-intro">' . safe($intro) . '</p>'
          . '<span class="aa-sc-sechead" aria-hidden="true"><span></span><span></span><span></span><span>' . safe($heads['last_seen']) . '</span><span>' . safe($heads['since']) . '</span><span>' . safe($heads['count']) . '</span></span>';
    $marks = '';
    if (!$open) {
        foreach (array_filter($severities) as $severity => $n) {
            $marks .= '<span class="aa-sc-sev ' . (($severity === Finding::ERROR) ? 'aa-err' : (($severity === Finding::WARNING) ? 'aa-info' : '')) . '">' . safe(StateCheckModel::severityLabel($severity)) . ' ' . $n . '</span>';
        }
    }
    echo '<details class="aa-page-section aa-sc-section" id="' . $id . '"' . ($open ? ' open' : '') . '><summary><h2 class="aa-tab-title">' . $title
        . ' <span class="aa-sc-secn">' . $count . '</span></h2>' . (($marks === '') ? '' : '<span class="aa-sc-marks">' . $marks . '</span>')
        . '<span class="aa-sc-tog"><span class="aa-sc-show">' . safe(_m('Show')) . '</span><span class="aa-sc-hide">' . safe(_m('Hide')) . '</span></span></summary>' . $head . $body . '</details>';
}

// ---- ?format=json: the page's model for the nightly mail - read-only, so before any POST action
if ($format === 'json') {
    ob_start();                 // a notice printed by a check would break the JSON
    $model = StateCheckModel::load(CheckRunner::run([AbstractOptimize::CHECK_BASIC]), array_keys(CheckRunner::classes([AbstractOptimize::CHECK_BASIC])), now());
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $model->json();
    exit;
}

// ---- POST actions ------------------------------------------------------------------
$repair_report = '';
if ($repair !== '') {
    $classes = CheckRunner::classes(STATECHECK_TAGS);
    if (!FormToken::check(STATECHECK_TOKEN, $token)) {
        $repair_report = _m('The form has expired; reload the page and try again.');
    } elseif (!isset($classes[$repair])) {
        $repair_report = _m('No such check: %1', [safe($repair)]);   // raw POST, printed as HTML below
    } elseif (!(new $classes[$repair]())->hasAction('repair')) {
        $repair_report = _m('%1 has no repair action', [safe($repair)]);
    } else {
        MoreTimeLimit();
        $optimizer     = new $classes[$repair]();
        $error         = '';
        try {
            $repaired = $optimizer->repair();
        } catch (Throwable $e) {
            $repaired = false;
            $error    = ' ' . safe($e->getMessage());
        }
        // both texts spelled out - AA\Lang\MsgExtractor skips an _m() whose first argument is not a literal
        $repair_report = ($repaired ? _m('Repair of %1 done.', [$repair]) : _m('Repair of %1 failed.', [$repair])) . $error . $optimizer->report();
    }
}

// ---- run the checks ----------------------------------------------------------------
$started       = microtime(true);
$findings      = CheckRunner::run([AbstractOptimize::CHECK_BASIC]);
$basic_seconds = microtime(true) - $started;
// every POST carries the same token: repair writes, the others only run checks, but extended and slow ones are minutes of DB time
$posted = FormToken::check(STATECHECK_TOKEN, $token);
// a stale token means the extended/slow run was refused - the summary must not claim it ran
$extended_ran     = $run_extended && $posted;
$extended_seconds = 0.0;
if ($extended_ran) {
    MoreTimeLimit();
    $started          = microtime(true);
    $findings         = CheckRunner::sort(array_merge($findings, CheckRunner::run([AbstractOptimize::CHECK_EXTENDED])));
    $extended_seconds = microtime(true) - $started;
}
$slow_ran     = $run_slow && $posted;
$slow_seconds = 0.0;
if ($slow_ran) {
    MoreTimeLimit();
    $started      = microtime(true);
    $findings     = CheckRunner::sort(array_merge($findings, CheckRunner::run([AbstractOptimize::CHECK_SLOW])));
    $slow_seconds = microtime(true) - $started;
}
// Recheck of one row: only a check that did not already run with this load has to be run on its own.
// The answer is about that one problem (check + key), not the page: still there, or gone.
$toast    = [];
$ran_tags = array_keys(array_filter([AbstractOptimize::CHECK_BASIC => true, AbstractOptimize::CHECK_EXTENDED => $extended_ran, AbstractOptimize::CHECK_SLOW => $slow_ran]));
$ran      = array_keys(CheckRunner::classes($ran_tags));
if ($recheck !== '' && $posted && isset(($classes = CheckRunner::classes(STATECHECK_TAGS))[$recheck])) {
    if (!array_intersect($ran_tags, $classes[$recheck]::types())) {
        MoreTimeLimit();
        $findings = CheckRunner::sort(array_merge($findings, CheckRunner::runClass($recheck, $classes[$recheck])));
        $ran[]    = $recheck;
    }
    $still = StateCheckModel::stillThere($findings, $recheck, $recheck_key);
    $toast = match (true) {
        !$still => [_m('Checked again at %1: the problem is gone.', [date('H:i')]), 'ok'],
        // counted from what already happened (log rows, the last run), so a fix cannot show at once
        $still->last > 0 => [_m('Checked again at %1: still listed, last seen %2. %3 This finding is counted from what already happened (log rows, the last run), so a fix does not show here at once - it goes when those age out or the next run passes. After the fix mark it resolved; it comes back if it happens again.', [date('H:i'), date('Y-m-d H:i', $still->last), safe($still->title)]), 'err'],
        default => [_m('Checked again at %1: still there. %2', [date('H:i'), safe($still->title)]), 'err'],   // AA_Message sets innerHTML
    };
} elseif (($recheck !== '' || $run_extended || $run_slow) && !$posted) {
    $toast = [_m('The form has expired; reload the page and try again.'), 'err'];
}

$model = StateCheckModel::load($findings, $ran, now());

// ---- page --------------------------------------------------------------------------
$apage = new AA_Adminpageutil('aaadmin', 'statecheck');
$apage->setTitle(_m("State Check"));
// no page-wide <form>: the rows carry their own (Repair, Recheck, Mark resolved) and the HTML parser drops a nested one
$apage->setForm();
$apage->addRequire('
    .aa-sc { --sc-cols: 1em 6.5em minmax(0, 1fr) 5.5em 5.5em 3.5em; }
    .aa-sc .aa-sc-section, .aa-sc .aa-sc-item { scroll-margin-top: 80px; }
    .aa-sc .aa-sc-intro { margin: 0.5em 0 0; color: #5c5649; font-size: 0.9em; max-width: 80ch; }
    .aa-sc .aa-sc-lines { margin: 0.6em 0 0; padding: 0 0.5em 0 1.6em; color: #4a463c; font-size: 0.92em; max-width: 90ch; }
    .aa-sc .aa-sc-lines li { margin: 0.3em 0; }
    .aa-sc .aa-sc-none { color: #a9a293; }
    .aa-sc .aa-sc-state.aa-sc-state-ongoing, .aa-sc .aa-sc-state.aa-sc-state-not_verified, .aa-sc .aa-sc-state.aa-sc-state-muted { background: transparent; color: #4a463c; box-shadow: inset 0 0 0 1px #b9b09b; text-transform: none; letter-spacing: 0; font-weight: 500; }
    .aa-sc .aa-sc-state.aa-sc-state-regressed { background: #7d2a1b; }
    .aa-sc .aa-sc-main .aa-sc-state { display: inline-block; white-space: nowrap; }
    .aa-sc .aa-sc-group > .aa-sc-body { padding-right: 0; }
    .aa-sc .aa-sc-body > .aa-sc-members { max-width: none; margin: 0.6em 0 0; border-top: 1px solid var(--clr-border, #ddd); }
    .aa-sc .aa-sc-members .aa-sc-item:last-child { border-bottom: 0; }
    .aa-sc .aa-sc-members .aa-sc-item > summary { padding-left: 0; }
    .aa-sc .aa-sc-members .aa-sc-cat { display: none; }
    .aa-sc .aa-statecheck-summary { font-size: 1.2em; line-height: 1.4; max-width: 60ch; margin: 0.4em 0 0.3em; }
    .aa-sc .aa-statecheck-runs { margin: 0 0 1.2em; max-width: 80ch; color: #5c5649; font-size: 0.9em; }
    .aa-sc .aa-sc-section { background: transparent; margin: 1.6em 0 0; }
    .aa-sc .aa-sc-section > :not(summary) { padding: 0; background: transparent; }
    .aa-sc .aa-sc-section > summary { display: flex; align-items: baseline; gap: 0.8em; padding: 0 0 0.3em; border-bottom: 1px solid var(--clr-gold, #c9b57f); }
    .aa-sc .aa-sc-section > summary > .aa-tab-title { background: transparent; border: 0; margin: 0; padding: 0; flex: none; font-size: 0.78rem; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #5c5649; }
    .aa-sc .aa-sc-section > summary > .aa-tab-title::after { display: none; }
    .aa-sc .aa-sc-secn { font-weight: 400; letter-spacing: 0; margin-left: 0.3em; font-variant-numeric: tabular-nums; }
    .aa-sc .aa-sc-tog { padding: 0.15em 0.9em; border: 1px solid var(--clr-btn-border, #bbb); border-radius: 4px; background: #fff; color: #1f1f1f; font-size: 0.8rem; }
    .aa-sc .aa-sc-section > summary:hover .aa-sc-tog { background: var(--clr-basic-light, #eee); }
    .aa-sc .aa-sc-marks { display: flex; flex-wrap: wrap; gap: 0.4em; }
    .aa-sc .aa-sc-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    .aa-sc .aa-sc-hide, .aa-sc .aa-sc-section[open] .aa-sc-show { display: none; }
    .aa-sc .aa-sc-section[open] .aa-sc-hide { display: inline; }
    .aa-sc .aa-sc-section:not([open]) > summary { border-bottom-color: var(--clr-border, #ddd); }
    .aa-sc .aa-sc-section:not([open]) > summary > .aa-tab-title { font-weight: 500; }
    .aa-sc .aa-sc-sechead { display: grid; grid-template-columns: var(--sc-cols); gap: 0 0.8em; padding: 0.4em 0.5em; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: #7a7364; text-align: right; }
    .aa-sc .aa-sc-sechead span:nth-child(-n+3) { visibility: hidden; }
    .aa-sc .aa-sc-item { border-bottom: 1px solid var(--clr-border, #ddd); }
    .aa-sc .aa-sc-item > summary { display: grid; grid-template-columns: var(--sc-cols); gap: 0 0.8em; align-items: baseline; margin: 0; padding: 0.55em 0.5em; background: transparent; border: 0; border-radius: 0; font-weight: 400; transition: none; }
    .aa-sc .aa-sc-item > summary::after { display: none; }
    .aa-sc .aa-sc-item > summary::before { content: ""; justify-self: center; border: solid 4px transparent; border-left-color: rgba(0,0,0,0.5); border-right: 0; transition: transform 0.12s; }
    .aa-sc .aa-sc-item[open] > summary::before { transform: rotate(90deg); }
    .aa-sc .aa-sc-item > summary:hover { background: var(--clr-opacity-dark, rgb(0 0 0 / 0.03)); }
    .aa-sc .aa-sc-item:target { background: var(--clr-surface-selected, #f3e6c3); }
    .aa-sc .aa-sc-body th { background: transparent; font-weight: 600; font-size: 0.85em; color: #6b6556; }
    .aa-sc .aa-sc-sev { justify-self: start; margin: 0; padding: 0.05em 0.55em; border-radius: 3px; font-size: 0.82em; font-weight: 600; white-space: nowrap; background: transparent; border: 0; color: #5c5649; box-shadow: inset 0 0 0 1px var(--clr-border, #ddd); }
    .aa-sc .aa-sc-sev.aa-err { background: #f4dcd5; color: #7d2a1b; box-shadow: none; }
    .aa-sc .aa-sc-sev.aa-info { background: #f3e6c3; color: #5e5320; box-shadow: none; }
    .aa-sc .aa-sc-sev.aa-ok { background: #dcefdc; color: #14532d; box-shadow: none; }
    .aa-sc .aa-sc-main { min-width: 0; overflow-wrap: anywhere; }
    .aa-sc .aa-sc-title { font-weight: 600; }
    .aa-sc .aa-sc-state { margin-left: 0.6em; padding: 0 0.45em; border-radius: 3px; font-size: 0.75em; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; background: #3a3a35; color: #fff; }
    .aa-sc .aa-sc-cat { margin-left: 0.6em; padding: 0 0.5em; border-radius: 999px; font-size: 0.78em; color: #6b6556; box-shadow: inset 0 0 0 1px var(--clr-border, #ddd); white-space: nowrap; }
    .aa-sc .aa-sc-cat:empty { display: none; }
    .aa-sc .aa-sc-times { display: contents; font-variant-numeric: tabular-nums; }
    .aa-sc .aa-sc-times > span { text-align: right; white-space: nowrap; font-size: 0.9em; color: #4a463c; }
    .aa-sc .aa-sc-sechead span { white-space: nowrap; }
    .aa-sc .aa-sc-body { padding: 0.2em 0.5em 1em calc(1em + 0.5em + 0.8em); background: transparent; }
    .aa-sc .aa-sc-body > * { max-width: 90ch; }
    .aa-sc .aa-sc-body > p { margin: 0.6em 0; }
    .aa-sc .aa-sc-advice { font-size: 1.02em; line-height: 1.5; }
    .aa-sc .aa-sc-lbl { display: inline-block; min-width: 7.5em; margin-right: 0.5em; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #7a7364; }
    .aa-sc .aa-sc-where a { margin-right: 0.9em; overflow-wrap: anywhere; }
    .aa-sc .aa-sc-body table { font-size: 0.88em; margin: 0.6em 0; border-collapse: collapse; max-width: 100%; display: block; overflow-x: auto; }
    .aa-sc .aa-sc-body td, .aa-sc .aa-sc-body th { padding: 0.15em 0.9em 0.15em 0; vertical-align: top; text-align: left; border: 0; overflow-wrap: anywhere; }
    .aa-sc .aa-sc-body th { overflow-wrap: normal; }
    .aa-sc .aa-sc-body .aa-tbl-header > th:first-child { padding-top: 0.15em; padding-bottom: 0.15em; }
    .aa-sc .aa-sc-actions { display: flex; flex-wrap: wrap; gap: 0.5em; margin: 0.9em 0 0.4em; }
    .aa-sc .aa-sc-actions .aa-button-run, .aa-sc .aa-sc-actions button { background: transparent; border: 1px solid var(--clr-btn-border, #bbb); border-radius: 4px; padding: 0.25em 0.9em; font-size: 0.9em; color: #1f1f1f; box-shadow: none; }
    .aa-sc .aa-sc-actions button:hover { background: var(--clr-basic-light, #eee); }
    .aa-sc .aa-sc-meta { font-size: 0.78em; color: #8a8372; }
    .aa-sc .aa-sc-run { padding: 1em 0.5em; max-width: 80ch; }
    .aa-sc .aa-statecheck-inline, .aa-statecheck-inline { display: inline; }
    @media (max-width: 700px) {
        .aa-sc .aa-sc-sechead { display: none; }
        .aa-sc .aa-sc-item > summary { grid-template-columns: 1em auto minmax(0, 1fr); grid-template-areas: "tog sev times" ". main main"; row-gap: 0.25em; }
        .aa-sc .aa-sc-item > summary::before { grid-area: tog; }
        .aa-sc .aa-sc-sev { grid-area: sev; }
        .aa-sc .aa-sc-main { grid-area: main; }
        .aa-sc .aa-sc-times { display: flex; grid-area: times; justify-content: flex-end; align-self: center; gap: 0.9em; flex-wrap: wrap; }
        .aa-sc .aa-sc-times > span { font-size: 0.82em; color: #6b6556; }
        .aa-sc .aa-sc-times > span:empty { display: none; }
        .aa-sc .aa-sc-times > span[data-l]::before { content: attr(data-l) " "; color: #8a8372; }
        .aa-sc .aa-sc-body { padding-left: 1.5em; }
        .aa-sc .aa-sc-lbl { display: block; }
    }
', 'AA_Req_CSScode');
// a link to #f-... (or #new, #passed, ...) must show it: open the sections and the group around it, then scroll to it
$apage->addRequire("
    (() => {
        const show = () => {
            let el = null;
            try { el = location.hash.length > 1 && document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch (e) {}
            if (!el) { return; }
            for (let d = el.closest('details'); d; d = d.parentElement?.closest('details')) { d.open = true; }
            // a member shows under its group row, unless the group is too tall for the window
            const group = el.parentElement?.closest('.aa-sc-group');
            (group ?? el).scrollIntoView();
            if (group && (el.getBoundingClientRect().bottom > innerHeight)) { el.scrollIntoView(); }
        };
        addEventListener('hashchange', show);
        // once now, so the details are open for the browser's own jump; again after it, which would win
        show();
        addEventListener('load', show);
    })();
", 'AA_Req_Run');
if ($toast) {
    $apage->addRequire('aa-jslib');
    $apage->addRequire("AA_Message('" . escape4js($toast[0]) . "', '" . $toast[1] . "')", 'AA_Req_Load');
}
$apage->printHead();

echo '<div class="aa-sc">';
if ($repair_report !== '') {
    echo '<div class="aa-info">' . $repair_report . '</div>';
}

echo '<p class="aa-statecheck-summary">' . safe($model->summary()) . '</p>';
echo '<p class="aa-statecheck-runs">'
    . _m('Basic checks ran now in %1 s.', [sprintf('%.1f', $basic_seconds)])
    . ' ' . ($extended_ran ? _m('Extended checks ran now in %1 s.', [sprintf('%.1f', $extended_seconds)]) : _m('Extended checks were not run.'))
    . ($slow_ran ? ' ' . _m('Slow checks ran now in %1 s.', [sprintf('%.1f', $slow_seconds)]) : '')
    . ' ' . ($model->snapshot ? _m('The daily snapshot last ran %1.', [date('Y-m-d H:i', $model->snapshot)]) : _m('No daily snapshot yet.'))
    . '</p>';

if (!$model->hasProblems()) {
    echo '<p class="aa-empty-note">' . _m('No problems found. %1 checks passed at %2.', [$model->count(StateCheckModel::PASSED), date('H:i')]) . '</p>';
}
// the extended and slow checks that did not run are the not verified section - their buttons go there
$run_buttons = ($extended_ran ? '' : '<p>' . _m('Full scans of the database; they take seconds to minutes on a large installation. Their findings are merged into the sections above once run.') . '</p>'
        . '<form method="post">' . FormToken::field(STATECHECK_TOKEN) . '<button class="aa-button-run" type="submit" name="run_extended" value="1">' . _m('Run extended checks now') . '</button></form>')
    . ($slow_ran ? '' : '<p>' . _m('Full scans of the largest tables; on a big installation they take many minutes and load the database server, so they are not part of the daily snapshot. Run them now and then, preferably outside peak hours. Their findings are merged into the sections above once run.') . '</p>'
        . '<form method="post">' . FormToken::field(STATECHECK_TOKEN) . '<button class="aa-button-run" type="submit" name="run_slow" value="1">' . _m('Run slow checks now') . '</button></form>');
foreach ($model->sections as $id => $items) {
    $lines = $model->lines[$id] ?? [];
    if ($items || $lines) {
        $body = ($items ? '<div class="aa-sc-items">' . implode('', array_map(static fn(StateCheckItem $item) => statecheck_item($item, $model->now, $id), $items)) . '</div>' : '')
              . ($lines ? '<ul class="aa-sc-lines">' . implode('', array_map(static fn(array $line) => '<li>' . safe($line['text']) . '</li>', $lines)) . '</ul>' : '');
        statecheck_section($id, StateCheckModel::sectionTitle($id), $model->sectionIntro($id), ($id === StateCheckModel::NOT_VERIFIED) ? $body . '<div class="aa-sc-run">' . $run_buttons . '</div>' : $body, in_array($id, StateCheckModel::OPEN_SECTIONS, true), $model->count($id), $model->severities($id));
    }
}

echo '</div>';
$apage->printFoot();
