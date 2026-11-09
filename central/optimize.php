<?php

/**
 * Optimize runs: one Optimize task - a class, or the whole sql_update of each AA's own version - on chosen
 * AAs of the Central list. The task queue asks the AAs one after another (AA\Central\OptimizeRun); this
 * page starts the run and shows its results. Superadmins only, as the whole Central.
 *
 * GET               the form (aa=<id>,<id> - the AAs chosen in the Central list) and the last runs
 * POST start        task, method, aa[] - starts a run
 * GET run=<id>      the results of a run; the page reloads itself while the run goes on
 * POST continue|end run=<id>
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
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

use AA\Central\Actionapps;
use AA\Central\OptimizeRun;
use AA\Central\RemoteHtml;
use AA\Central\ResponderPolicy;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Util\FormToken;
use AA\Util\InputVars;

require_once __DIR__ . "/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";

pageOpen();

try {
    $action = InputVars::stringPOST('action');
    $run_id = strtolower(InputVars::idGETPOST('run'));
    $token  = InputVars::stringPOST('token');
    $chosen = InputVars::stringGET('aa');
    $posted = array_filter(InputVars::arrayPOST('aa'), 'is_string');
    $task   = InputVars::stringPOST('task');
    $method = InputVars::stringPOST('method');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}
$uid = (string) AuthGetUid();

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permission to manage ActionApps installations"), 'admin');
}
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (ResponderPolicy::isForeignBrowserRequest($_SERVER) || !FormToken::check(OptimizeRun::FORM_TOKEN, $token))) {
    AA_Adminpageutil::MsgPageMenu(optimize_url(), _m('The form has expired; reload the page and try again.'), 'admin');
}

$err = '';
if ($action === 'start') {
    try {
        $listed = Actionapps::getArray();          // the approved bin, as the form offers
        $run    = OptimizeRun::begin($task, $method, array_filter(array_map('intval', $posted), static fn(int $id): bool => isset($listed[$id])), $uid);
        go_url(optimize_url(['run' => $run->getId()]));
    } catch (InvalidArgumentException) {
        $err = _m('Choose a task, a method the task has and at least one AA of the list.');
    } catch (BatchFailed $e) {
        $err = _m('A table of this AA has no transactions (not InnoDB) - convert it in AA > Optimize: %1', [safe($e->where)]);
    }
} elseif (in_array($action, ['continue', 'end'], true)) {
    try {
        ($action === 'continue') ? OptimizeRun::resumeRun($run_id, $uid) : OptimizeRun::endRun($run_id, $uid);
        go_url(optimize_url(['run' => $run_id]));
    } catch (BatchFailed $e) {
        $err = match ($e->reason) {
            'LEASE'             => _m('An AA may still be asked - press Continue again in a few minutes.'),
            BatchRun::FORBIDDEN => _m('No such run.'),
            default             => _m('The run has moved on meanwhile - see its state below.'),
        };
    }
}

$apage = new AA_Adminpageutil('central', 'optimize');
$apage->setTitle(_m('Optimize on several AAs'));
$apage->addRequire('
    .aa-optimize { border-collapse: collapse; margin: 0.3em 0 1em; width: 100%; }
    .aa-optimize td, .aa-optimize th { padding: 0.3em 0.6em; text-align: left; vertical-align: top; border-bottom: 1px solid var(--clr-border, #ddd); }
    .aa-optimize-ok { color: var(--clr-green, #1a7f5a); font-weight: bold; }
    .aa-optimize-bad { color: var(--clr-red, #b3261e); font-weight: bold; }
', 'AA_Req_CSScode');
$apage->printHead($err);

if ($run_id !== '') {
    optimize_run($run_id, $uid);
} else {
    // a refused start keeps what was chosen
    $checked = ($action === 'start') ? $posted : explode(',', $chosen);
    optimize_form(array_map('intval', array_filter($checked, 'ctype_digit')), $task ?: OptimizeRun::SQL_UPDATE, $method ?: 'test');
    optimize_recent();
}

$apage->printFoot();

// ---------------------------------------------------------------------------------------------------

function optimize_url(array $params = []): string
{
    return StateUrl(get_url(self_base() . 'optimize.php', $params));
}

/** @return array<string, string> method => its name */
function optimize_methods(): array
{
    return ['test' => _m('Test only'), 'repair' => _m('Repair where the test fails')];
}

function optimize_title(OptimizeRun $run): string
{
    return OptimizeRun::taskName($run->task()) . ' - ' . (optimize_methods()[$run->method()] ?? $run->method());
}

/** @param list<int> $checked the AAs chosen in the Central list */
function optimize_form(array $checked, string $task, string $method): void
{
    $aas = Actionapps::getArray();
    if (!$aas) {
        echo '<div class="aa-info">' . _m('The Central list holds no active AA.') . '</div>';
        return;
    }
    echo '<form method="post" action="' . safe(optimize_url()) . '">' . FormToken::field(OptimizeRun::FORM_TOKEN) . '<input type="hidden" name="action" value="start">';
    FrmTabCaption(_m('Run an Optimize task on several AAs'));
    $options = [];
    foreach (OptimizeRun::taskGroups() as $group => $tasks) {
        $options['AA_OPTGROUP ' . $group] = $group;
        $options += $tasks;
    }
    // a task this AA does not offer is not chosen again
    $task  = (isset($options[$task]) && !str_starts_with($task, 'AA_OPTGROUP')) ? $task : OptimizeRun::SQL_UPDATE;
    $input = new AA_Inputfield(getFrmSelectEasy('task', $options, $task, ['aria-label' => _m('Task'), 'required' => 'required']), false, 'normal', '', _m('Task'), '', true, _m('Each AA runs the task of its own version; an AA without it says so.'));
    $input->staticText(false);
    echo $input->get_result();
    FrmInputRadio('method', _m('Do'), optimize_methods(), $method, true, _m('Repair tests first, repairs only where the test fails, then tests again.'));
    FrmTabEnd();
    echo '<table class="aa-optimize"><tr><th></th><th>' . _m('AA') . '</th><th>' . _m('Auth') . '</th></tr>';
    foreach ($aas as $id => $aa) {
        echo '<tr><td><input type="checkbox" name="aa[]" value="' . (int) $id . '" id="aa-optimize-' . (int) $id . '"' . (in_array((int) $id, $checked, true) ? ' checked' : '') . '></td>'
            . '<td><label for="aa-optimize-' . (int) $id . '">' . safe($aa->getName()) . '</label></td><td>' . safe($aa->credentialBadge()) . '</td></tr>';
    }
    echo '</table><p>' . _m('An AA without a valid token answers nothing - get its token in the Central list first (it lasts a day).') . '</p>'
        . '<p><button class="btn-l" type="submit">' . _m('Start') . '</button></p></form>';
}

/** the last runs, newest first */
function optimize_recent(): void
{
    $runs = OptimizeRun::recent();
    if (!$runs) {
        return;
    }
    $now = OptimizeRun::clock();
    echo '<h2>' . _m('Last runs') . '</h2><table class="aa-optimize"><tr><th>' . _m('Started') . '</th><th>' . _m('Task') . '</th><th>' . _m('State') . '</th><th>' . _m('Results') . '</th></tr>';
    foreach ($runs as $run) {
        $counts = array_count_values(array_column($run->results(), 's'));
        $summary = implode(', ', array_map(static fn(string $s, int $n): string => optimize_status($s, $run->method()) . " {$n}×", array_keys($counts), $counts));
        echo '<tr><td>' . a_href(optimize_url(['run' => $run->getId()]), safe(date('Y-m-d H:i', $run->started()))) . '</td>'
            . '<td>' . safe(optimize_title($run)) . '</td>'
            . '<td>' . safe(optimize_state($run, $now)) . '</td><td>' . safe($summary) . ' (' . count($run->aas()) . ' AA)</td></tr>';
    }
    echo '</table>';
}

/** the results of a run, AA by AA */
function optimize_run(string $run_id, string $uid): void
{
    $run = OptimizeRun::get($run_id);
    if (($run === null) || !$run->mayControl($uid)) {
        echo '<div class="aa-err">' . _m('No such run.') . '</div>';
        return;
    }
    $now     = OptimizeRun::clock();
    $results = $run->results();
    $aas     = Actionapps::all();
    $open    = in_array($run->state(), [OptimizeRun::RUNNING, OptimizeRun::ERROR], true);
    $again   = $run->isWaiting($now) ? $run->progress($uid)['next_at'] : 0;
    [$current, $next] = ($open ? $run->current() : null) ?? [0, ''];
    echo '<h2>' . safe(optimize_title($run)) . '</h2>'
        . '<p>' . _m('Started %1 by %2', [safe(date('Y-m-d H:i', $run->started())), safe(perm_username($run->user()))]) . '</p>'
        . '<p><strong>' . safe(optimize_state($run, $now)) . '</strong> ' . safe(_m('%1 of %2 AAs done', [$run->done(), count($run->aas())])) . '</p>';
    $failure = $run->lastFailure();
    if ($failure && ($run->state() !== OptimizeRun::DONE)) {
        $aa = isset($aas[$current]) ? $aas[$current]->getName() : "#$current";
        echo '<div class="' . (($run->state() === OptimizeRun::ERROR) ? 'aa-err' : 'aa-info') . '">' . safe(optimize_failure($failure, $aa, $again)) . '</div>';
    }
    if ($open) {
        echo '<form method="post" action="' . safe(optimize_url()) . '"><div>' . FormToken::field(OptimizeRun::FORM_TOKEN) . '<input type="hidden" name="run" value="' . safe($run_id) . '">'
            . ($run->mayResume($now) ? '<button class="btn-l" type="submit" name="action" value="continue">' . _m('Continue') . '</button> ' : '')
            . '<button class="btn-l btn-secondary" type="submit" name="action" value="end">' . _m('End') . '</button></div></form>'
            . '<p class="tabhlp">' . _m('The run goes on in the task queue of this AA - you may close this page.') . '</p>';
    }
    echo '<table class="aa-optimize"><tr><th>' . _m('AA') . '</th><th>' . _m('Result') . '</th><th>' . _m('Report') . '</th></tr>';
    foreach ($run->aas() as $id) {
        $name = isset($aas[$id]) ? $aas[$id]->getName() : "#$id";
        $r    = $results[$id] ?? null;
        echo '<tr><td>' . safe($name) . '</td>';
        if ($r === null) {
            echo '<td>' . safe($open ? _m('Waiting') : _m('Not asked')) . '</td><td></td></tr>';
            continue;
        }
        $good = in_array($r['s'], [OptimizeRun::OK, OptimizeRun::REPAIRED], true);
        echo '<td class="' . ($good ? 'aa-optimize-ok' : 'aa-optimize-bad') . '">' . safe(optimize_status($r['s'], $run->method())) . '</td><td>';
        if ($r['m'] !== '') {
            echo '<div>' . safe($r['m']) . '</div>';
        }
        if (($id === $current) && ($next === 'check')) {
            echo '<div>' . _m('The test after the repair comes next.') . '</div>';
        }
        // the AA's own HTML - only in a sandbox
        if ($r['r'] !== '') {
            echo '<details><summary>' . _m('Report') . '</summary>' . RemoteHtml::sandboxed($name, $r['r']) . '</details>';
        }
        if ($r['c'] !== '') {
            echo '<details><summary>' . _m('The test after the repair') . '</summary>' . RemoteHtml::sandboxed($name, $r['c']) . '</details>';
        }
        echo '</td></tr>';
    }
    echo '</table>';
    if (($run->state() === OptimizeRun::RUNNING) && !$run->isStuck($now)) {
        // a new look after the next step - not while a report is open
        $seconds = $again ? max(10, $again - $now + 5) : 10;
        echo '<script>setInterval(() => { if (!document.querySelector("details[open]")) { location.replace(' . json_encode(optimize_url(['run' => $run_id]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '); } }, ' . ($seconds * 1000) . ');</script>';
    }
    echo '<p>' . a_href(optimize_url(), _m('Another run')) . '</p>';
}

function optimize_status(string $status, string $method): string
{
    return match ($status) {
        OptimizeRun::OK       => ($method === 'repair') ? _m('OK - nothing to repair') : _m('OK'),
        OptimizeRun::PROBLEM  => _m('Problem'),
        OptimizeRun::REPAIRED => _m('Repaired'),
        OptimizeRun::FAILED   => _m('Repair failed'),
        default               => _m('Not done'),
    };
}

function optimize_state(OptimizeRun $run, int $now): string
{
    return match (true) {
        $run->state() === OptimizeRun::DONE  => _m('Finished'),
        $run->state() === OptimizeRun::ENDED => _m('Ended'),
        $run->state() === OptimizeRun::ERROR => _m('Stopped by an error'),
        $run->isStuck($now)                  => _m('No step has come for a long time - is the task queue of this AA running? Continue tries at once.'),
        $run->isWaiting($now)                => _m('Waiting to ask an AA again'),
        default                              => _m('Running in the task queue'),
    };
}

/** why the run stopped, or why it waits
 *  @param array{code: string, where: string, detail: string} $failure
 *  @param string $aa the name of the AA asked now @param int $again when it is asked again, 0 = not waiting */
function optimize_failure(array $failure, string $aa, int $again): string
{
    $text = match ($failure['code']) {
        OptimizeRun::AA_BUSY      => _m('%1 runs another update now.', [$aa]),
        OptimizeRun::AA_NO_ANSWER => _m('%1 did not answer in time.', [$aa]),
        OptimizeRun::DENIED       => _m('The user who started the run is no superadmin any more.'),
        default                   => _m('Error %1', [$failure['code']]),
    };
    return $again ? $text . ' ' . _m('Asked again at %1 - Continue asks now.', [date('H:i', $again)]) : $text;
}
