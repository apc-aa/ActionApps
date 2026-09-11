<?php

/**
 * State Check - one page that says whether this installation is healthy: the
 * findings of every Optimize class tagged as a check (AbstractOptimize::CHECK_*),
 * worst first, with counts, evidence and what to do. Basic checks run on every
 * load; extended ones on request. Optimize (aa_optimize.php) stays the workshop
 * with every action; this page is the overview.
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

use AA\Later\StateCheck;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckRunner;
use AA\Optimize\Finding;
use AA\Util\FormToken;
use AA\Util\InputVars;
use AA\Util\Note;
use AA\Util\Notes;

require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/mgettext.php";
// ----------------------------------------------------------------------------------------
try {
    $run_extended = InputVars::boolPOST('run_extended');
    $repair       = InputVars::stringPOST('repair');
    $token        = InputVars::stringPOST('token');
    $recheck      = InputVars::stringPOST('recheck');
    $recheck_key  = InputVars::stringPOST('key');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to see State Check"), "admin");
    exit;
}

const STATECHECK_TAGS = [AbstractOptimize::CHECK_BASIC, AbstractOptimize::CHECK_EXTENDED, AbstractOptimize::CHECK_SECURITY];
const STATECHECK_TOKEN = 'aa_statecheck';

/** severity as a word in a wash - never a red frame or stripe (Reserved-Red Rule) */
function statecheck_severity(Finding $finding): string
{
    return match ($finding->severity) {
        Finding::ERROR   => '<span class="aa-err">' . _m('Problem') . '</span>',
        Finding::WARNING => '<span class="aa-info">' . _m('Warning') . '</span>',
        Finding::INFO    => '<span>' . _m('Advice') . '</span>',
        default          => '<span class="aa-ok">' . _m('OK') . '</span>',
    };
}

function statecheck_actions(Finding $finding): string
{
    $out = [];
    foreach ($finding->links as $label => $url) {
        $out[] = '<a class="aa-button-show" href="' . safe($url) . '">' . safe($label) . '</a>';
    }
    if ($finding->repairable) {
        $out[] = '<form method="post" class="aa-statecheck-inline">' . FormToken::field(STATECHECK_TOKEN) . '<button class="aa-button-run" type="submit" name="repair" value="' . safe($finding->check) . '">' . _m('Repair') . '</button></form>';
    }
    if ($finding->isProblem()) {
        $out[] = Notes::resolveForm(Note::resource('finding', $finding->check, $finding->key), get_admin_url('aa_statecheck.php'));
        $out[] = '<form method="post" class="aa-statecheck-inline">' . FormToken::field(STATECHECK_TOKEN) . '<input type="hidden" name="key" value="' . safe($finding->key) . '"><button class="aa-button-run" type="submit" name="recheck" value="' . safe($finding->check) . '">' . _m('Recheck') . '</button></form>';
    }
    return implode(' ', $out);
}

/** @param Finding[] $findings */
function statecheck_table(array $findings): string
{
    if (!$findings) {
        return '';
    }
    $html = '<table class="aa-statecheck"><thead><tr><th>' . _m('Severity') . '</th><th>' . _m('Check') . '</th><th>' . _m('Finding') . '</th><th class="aa-num">' . _m('Count') . '</th><th>' . _m('Since') . '</th><th>' . _m('Actions') . '</th></tr></thead><tbody>';
    foreach ($findings as $finding) {
        // the title is the row; evidence and advice open under it
        $cell = safe($finding->title);
        if ($finding->evidence || $finding->advice) {
            $cell = '<details><summary>' . $cell . '</summary>'
                . ($finding->advice ? '<p>' . safe($finding->advice) . '</p>' : '')
                . GetHtmlTable($finding->evidence, 'key')
                . '</details>';
        }
        $html .= '<tr>'
            . '<td>' . statecheck_severity($finding) . '</td>'
            . '<td>' . safe($finding->check) . '<br><small>' . sprintf('%.2f s', $finding->seconds) . '</small></td>'
            . '<td>' . $cell . '</td>'
            . '<td class="aa-num">' . ($finding->count ?: '') . '</td>'
            . '<td class="aa-num">' . ($finding->since ? date('Y-m-d H:i', $finding->since) : '') . '</td>'
            . '<td>' . statecheck_actions($finding) . '</td>'
            . '</tr>';
    }
    return $html . '</tbody></table>';
}

/** @param ?int $count  shown in the header, so a closed section still says how much is inside */
function statecheck_section(string $title, string $body, bool $open, ?int $count = null): void
{
    $title .= ($count === null) ? '' : ' (' . $count . ')';
    echo '<details class="aa-page-section"' . ($open ? ' open' : '') . '><summary><h2 class="aa-tab-title">' . $title . '</h2></summary>' . $body . '</details>';
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
        // both texts spelled out - AA\Lang\MsgExtractor skips an _m() whose first argument is not a literal
        $repair_report = ($optimizer->repair() ? _m('Repair of %1 done.', [$repair]) : _m('Repair of %1 failed.', [$repair])) . $optimizer->report();
    }
}

// ---- run the checks ----------------------------------------------------------------
$started       = microtime(true);
$findings      = CheckRunner::run([AbstractOptimize::CHECK_BASIC]);
$basic_seconds = microtime(true) - $started;
// every POST carries the same token: repair writes, the other two only run checks, but extended ones are minutes of DB time
$posted = FormToken::check(STATECHECK_TOKEN, $token);
$extended_seconds = 0.0;
if ($run_extended && $posted) {
    MoreTimeLimit();
    $started          = microtime(true);
    $findings         = CheckRunner::sort(array_merge($findings, CheckRunner::run([AbstractOptimize::CHECK_EXTENDED])));
    $extended_seconds = microtime(true) - $started;
}
// Recheck of one row: the basic checks re-ran with this load anyway, so only an extended one has to be run on its own.
// The answer is about that one problem (check + key), not the page: still there, or gone.
$toast = [];
if ($recheck !== '' && $posted && isset(($classes = CheckRunner::classes(STATECHECK_TAGS))[$recheck])) {
    if (!in_array(AbstractOptimize::CHECK_BASIC, $classes[$recheck]::types()) && !$run_extended) {
        MoreTimeLimit();
        $findings = CheckRunner::sort(array_merge($findings, CheckRunner::runClass($recheck, $classes[$recheck])));
    }
    $still = null;
    foreach ($findings as $finding) {
        if ($finding->check === $recheck && $finding->key === $recheck_key && $finding->isProblem()) {
            $still = $finding;
            break;
        }
    }
    $toast = $still
        ? [_m('Checked again at %1: still there. %2', [date('H:i'), safe($still->title)]), 'err']   // AA_Message sets innerHTML
        : [_m('Checked again at %1: the problem is gone.', [date('H:i')]), 'ok'];
}

[$findings, $resolved] = CheckRunner::splitResolved($findings, now());
$environment = array_filter($findings, static fn(Finding $f) => $f->category === 'environment');
$findings    = array_filter($findings, static fn(Finding $f) => $f->category !== 'environment');
$by_severity = array_fill_keys([Finding::ERROR, Finding::WARNING, Finding::INFO, Finding::OK], []);
foreach ($findings as $finding) {
    $by_severity[$finding->severity][] = $finding;
}
$summary       = Finding::summary($findings);
$last_snapshot = AA_Log::getLastLogEvents(StateCheck::EVENT, StateCheck::SUMMARY);

// ---- page --------------------------------------------------------------------------
$apage = new AA_Adminpageutil('aaadmin', 'statecheck');
$apage->setTitle(_m("State Check"));
$apage->addRequire('
    .aa-statecheck { width: 100%; border-collapse: collapse; font-variant-numeric: tabular-nums; }
    .aa-statecheck th { text-align: left; }
    .aa-statecheck th, .aa-statecheck td { padding: 0.4em 0.6em; vertical-align: top; border-bottom: 1px solid var(--clr-border, #ddd); }
    .aa-statecheck .aa-num { text-align: right; white-space: nowrap; }
    .aa-statecheck .aa-err, .aa-statecheck .aa-info, .aa-statecheck .aa-ok { display: inline-block; margin: 0; padding: 0.2em 0.6em; white-space: nowrap; }
    .aa-statecheck summary { cursor: pointer; }
    .aa-statecheck details > p { margin: 0.5em 0; }
    .aa-statecheck-inline { display: inline; }
    .aa-statecheck-summary { margin: 0.5em 0 1em; }
    .aa-statecheck-inline > summary { display: inline-block; list-style: none; }
    .aa-statecheck-inline[open] > summary { margin-bottom: 0.3em; }
    .aa-note { margin: 0.4em 0; }
', 'AA_Req_CSScode');
if ($toast) {
    $apage->addRequire('aa-jslib');
    $apage->addRequire("AA_Message('" . escape4js($toast[0]) . "', '" . $toast[1] . "')", 'AA_Req_Load');
}
$apage->printHead();

if ($repair_report !== '') {
    echo '<div class="aa-info">' . $repair_report . '</div>';
}

echo '<p class="aa-statecheck-summary">'
    . _m('%1 problems, %2 warnings, %3 recommendations, %4 checks passed.', [$summary[Finding::ERROR], $summary[Finding::WARNING], $summary[Finding::INFO], $summary[Finding::OK]])
    . ' ' . _m('Basic checks ran now in %1 s.', [sprintf('%.1f', $basic_seconds)])
    . ' ' . ($run_extended ? _m('Extended checks ran now in %1 s.', [sprintf('%.1f', $extended_seconds)]) : _m('Extended checks were not run.'))
    . ' ' . ($last_snapshot ? _m('The daily snapshot last ran %1.', [date('Y-m-d H:i', (int) $last_snapshot['time'])]) : _m('No daily snapshot yet.'))
    . '</p>';

statecheck_section(_m('Problems'), $by_severity[Finding::ERROR] ? statecheck_table($by_severity[Finding::ERROR]) : '<p class="aa-empty-note">' . _m('No problems found. %1 checks passed at %2.', [$summary[Finding::OK], date('H:i')]) . '</p>', true, $summary[Finding::ERROR]);
if ($by_severity[Finding::WARNING]) {
    statecheck_section(_m('Warnings'), statecheck_table($by_severity[Finding::WARNING]), true, $summary[Finding::WARNING]);
}
if ($by_severity[Finding::INFO]) {
    statecheck_section(_m('Recommendations'), statecheck_table($by_severity[Finding::INFO]), true, $summary[Finding::INFO]);
}
if ($resolved) {
    $back = get_admin_url('aa_statecheck.php');
    $rows = '';
    foreach ($resolved as [$finding, $note]) {
        $rows .= '<tr><td>' . statecheck_severity($finding) . '</td><td>' . safe($finding->check) . '</td>'
            . '<td>' . safe($finding->title) . '</td><td>' . Notes::render($note, $back) . '</td></tr>';
    }
    $table = '<table class="aa-statecheck">'
        . '<thead><tr><th>' . _m('Severity') . '</th><th>' . _m('Check') . '</th><th>' . _m('Finding') . '</th><th>' . _m('Resolution') . '</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';
    statecheck_section(_m('Resolved'), $table, false, count($resolved));
}
statecheck_section(_m('Passed checks'), statecheck_table($by_severity[Finding::OK]) ?: '<p class="aa-empty-note">' . _m('No check passed.') . '</p>', false, $summary[Finding::OK]);

$extended_names = array_map(static fn(string $class) => $class::name(), CheckRunner::classes([AbstractOptimize::CHECK_EXTENDED]));
statecheck_section(
    _m('Extended checks'),
    '<p>' . _m('Full scans of the database; they take seconds to minutes on a large installation. Their findings are merged into the sections above once run.') . '</p>'
    . '<p>' . safe(implode(', ', $extended_names)) . '</p>'
    . '<form method="post">' . FormToken::field(STATECHECK_TOKEN) . '<button class="aa-button-run" type="submit" name="run_extended" value="1">' . _m('Run extended checks now') . '</button></form>',
    (bool) $run_extended,
);
statecheck_section(_m('Environment'), statecheck_table($environment), false);

$apage->printFoot();
