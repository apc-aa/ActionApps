<?php

/**
 * Optimize runs: Optimize tasks - a class, or the whole sql_update of each AA's own version - on chosen
 * AAs of the Central list, a run for each task. The task queue asks the AAs one after another
 * (AA\Central\OptimizeRun); this page starts the runs and shows their results. Superadmins only, as the
 * whole Central.
 *
 * GET               the form (aa=<id>,<id> - the AAs chosen in the Central list) and the last runs
 * POST start        task[], method, aa[] - starts a run for each task
 * GET run=<id>      the results of a run; while it goes on, the page runs its steps (step)
 * POST continue|end run=<id>
 * POST step [run=<id>] [look=1] JSON: the due step of the run - of the list: of its oldest open run with
 *                   one; look=1 none - and what the page shows anew
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
    $tasks  = array_filter(InputVars::arrayPOST('task'), 'is_string');
    $method = InputVars::stringPOST('method');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}
$uid = (string) AuthGetUid();

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permission to manage ActionApps installations"), 'admin');
}
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (ResponderPolicy::isForeignBrowserRequest($_SERVER) || !FormToken::check(OptimizeRun::FORM_TOKEN, $token))) {
    if ($action === 'step') {
        optimize_json(['html' => '', 'wait' => null, 'ran' => false], 403);
    }
    AA_Adminpageutil::MsgPageMenu(optimize_url(), _m('The form has expired; reload the page and try again.'), 'admin');
}
if ($action === 'step') {
    // the step may take half a minute - the other pages of this session must not wait for it
    Session::page_close();
    session_write_close();
    optimize_json(optimize_step($run_id, $uid, InputVars::stringPOST('look') === '1'));
}

$err   = '';
$tasks = $tasks ? optimize_tasks($tasks) : [];
if (($action === 'start') && (count($tasks) > OptimizeRun::KEEP)) {
    $err = _m('Choose at most %1 tasks - only the last %1 runs keep their results.', [OptimizeRun::KEEP]);
} elseif ($action === 'start') {
    try {
        $listed = Actionapps::getArray();          // the approved bin, as the form offers
        $aas    = array_values(array_filter(array_map('intval', $posted), static fn(int $id): bool => isset($listed[$id])));
        if (!$tasks || !in_array($method, OptimizeRun::METHODS, true)) {
            throw new InvalidArgumentException('No task or no method');
        }
        $runs = [];
        foreach ($tasks as $task) {
            $runs[] = OptimizeRun::begin($task, OptimizeRun::methodFor($task, $method), $aas, $uid)->getId();
        }
        // several runs: their list, and the same AAs chosen for the next start
        go_url((count($runs) === 1) ? optimize_url(['run' => $runs[0]]) : optimize_url(['aa' => implode(',', $aas)]) . '#aa-optimize-runs');
    } catch (InvalidArgumentException) {
        $err = _m('Choose at least one task and at least one AA of the list.');
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
    .aa-optimize tr:has(+ .aa-optimize-reports) td { border-bottom: 0; }
    .aa-optimize-reports > td { padding-top: 0; }
', 'AA_Req_CSScode');
if ($run_id === '') {
    // select2 brings jQuery - the prototype of aa-jslib@1 would take its $; manager.js has its own AA_$$
    $apage->addRequire(get_aa_url('javascript/manager.min.js', ['v' => AA_JS_VERSION]));
    $apage->addRequire('select2@4');
    $apage->addRequire('jQuery("#aa-optimize-tasks").select2({width: "100%", closeOnSelect: false});', 'AA_Req_Load');
    $apage->addRequire('AA_AllowMultiselect(\'input[name="aa[]"]\', \'' . escape4js(_m('Hold Shift and click to select multiple rows')) . '\');', 'AA_Req_Load');
    // the box in the head of the list checks all the AAs and shows whether all or some are checked
    $apage->addRequire(<<<'JS'
        const all = AA_$('#aa-optimize-all'), boxes = AA_$$('input[name="aa[]"]');
        if (all) {
            const sync = () => {
                const n = boxes.filter(b => b.checked).length;
                all.checked = (n > 0) && (n === boxes.length);
                all.indeterminate = (n > 0) && (n < boxes.length);
            };
            all.addEventListener('change', () => boxes.forEach(b => { b.checked = all.checked; }));
            boxes.forEach(b => b.addEventListener('change', sync));
            sync();
        }
        JS, 'AA_Req_Load');
}
// while a run is open, the page runs its steps one right after another; while a step runs, it shows
// each AA as it is done
$apage->addRequire(<<<'JS'
    const box = document.getElementById('aa-optimize-live');
    if (box?.dataset.open) {
        let stepping = false, steps = 0;
        const ask = async (look) => {
            const body = new URLSearchParams({action: 'step', token: box.dataset.token, run: box.dataset.run, look: look ? '1' : ''});
            const response = await fetch(box.dataset.url, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: AbortSignal.timeout?.(look ? 20000 : 120000)});
            return (response.status === 403) ? null : response.json();
        };
        // a report being read is not replaced under the reader - it waits till it is closed
        const show = s => {
            const reading = box.querySelector('details[open]');
            if (!reading) {
                box.innerHTML = s.html;
            }
            return !reading;
        };
        const watch = setInterval(async () => {
            const at = steps;
            try {
                const s = stepping ? await ask(true) : null;
                if (s && stepping && (at === steps)) {
                    show(s);
                }
            } catch {
                // the next look tries again
            }
        }, 3000);
        const step = async () => {
            let wait = 30;
            stepping = true;
            try {
                const s = await ask(false);
                if (s === null) {
                    clearInterval(watch);
                    return;
                }
                const shown = show(s);
                if ((s.wait === null) && shown) {
                    clearInterval(watch);
                    return;
                }
                // nothing ran although a step is due: another page or the queue has it
                wait = (s.wait === null) ? 10 : (s.wait > 0) ? Math.min(s.wait, 10) : (s.ran ? 0 : 2);
            } catch {
                // a lost connection: the queue goes on meanwhile
            } finally {
                stepping = false;
                steps++;
            }
            setTimeout(step, wait * 1000);
        };
        step();
    }
    JS, 'AA_Req_Load');
$apage->printHead($err);

if ($run_id !== '') {
    optimize_live($run_id, $uid, static fn() => optimize_run($run_id, $uid));
} else {
    // a refused start keeps what was chosen
    $checked = ($action === 'start') ? $posted : explode(',', $chosen);
    optimize_form(array_map('intval', array_filter($checked, 'ctype_digit')), $tasks ?: [OptimizeRun::SQL_UPDATE], $method ?: 'test');
    optimize_live('', $uid, static fn() => optimize_recent());
}

$apage->printFoot();

// ---------------------------------------------------------------------------------------------------

function optimize_url(array $params = []): string
{
    return StateUrl(get_url(self_base() . 'optimize.php', $params));
}

/** what the page shows anew on each look (optimize_step()), and whether it looks: a run that goes on
 *  @param string $run_id the run shown, '' = the list of the last runs */
function optimize_live(string $run_id, string $uid, Closure $show): void
{
    $open = array_filter(optimize_open_runs($run_id), static fn(OptimizeRun $run): bool => $run->mayControl($uid));
    echo '<div id="aa-optimize-live" data-url="' . safe(optimize_url()) . '" data-run="' . safe($run_id) . '" data-token="' . safe(FormToken::get(OptimizeRun::FORM_TOKEN)) . '"' . ($open ? ' data-open="1"' : '') . '>';
    $show();
    echo '</div>';
}

/** the runs that go on of those a page shows - the run, or the last runs, the oldest first
 *  @return list<OptimizeRun> */
function optimize_open_runs(string $run_id): array
{
    $runs = ($run_id !== '') ? array_filter([OptimizeRun::get($run_id)]) : array_reverse(OptimizeRun::recent());
    return array_values(array_filter($runs, static fn(OptimizeRun $run): bool => $run->state() === OptimizeRun::RUNNING));
}

/** A look of the page: the due step of the run shown - of the list, of its oldest open run with one, so the
 *  runs go one after another - and what the page shows now
 *  @param bool $only_look no step - the page looks while its step runs
 *  @return array{html: string, wait: ?int, ran: bool} wait: seconds till the next look, null = no run goes on */
function optimize_step(string $run_id, string $uid, bool $only_look): array
{
    $runs = array_filter(optimize_open_runs($run_id), static fn(OptimizeRun $run): bool => $run->mayControl($uid));
    $ran  = false;
    foreach ($only_look ? [] : $runs as $run) {
        if (OptimizeRun::stepNow($run->getId(), $uid)) {
            $ran = true;
            break;
        }
    }
    $now   = OptimizeRun::clock();
    $waits = array_filter(array_map(static fn(OptimizeRun $run): ?int => OptimizeRun::get($run->getId())?->nextLookIn($now), $runs), 'is_int');
    ob_start();
    ($run_id !== '') ? optimize_run($run_id, $uid) : optimize_recent();
    return ['html' => (string) ob_get_clean(), 'wait' => $waits ? min($waits) : null, 'ran' => $ran];
}

function optimize_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @return array<string, string> method => its name */
function optimize_methods(): array
{
    return ['test' => _m('Test only'), 'repair' => _m('Repair where the test fails')];
}

/** the chosen tasks the form offers, in its order - the whole sql_update before the single classes
 *  @param array<string> $posted
 *  @return list<string> */
function optimize_tasks(array $posted): array
{
    return array_values(array_intersect(array_keys(OptimizeRun::tasks()), $posted));
}

function optimize_title(OptimizeRun $run): string
{
    return OptimizeRun::taskName($run->task()) . ' - ' . (optimize_methods()[$run->method()] ?? $run->method());
}

/** @param list<int> $checked the AAs chosen in the Central list
 *  @param list<string> $tasks tasks of OptimizeRun::tasks() */
function optimize_form(array $checked, array $tasks, string $method): void
{
    $aas = Actionapps::getArray();
    if (!$aas) {
        echo '<div class="aa-info">' . _m('The Central list holds no active AA.') . '</div>';
        return;
    }
    echo '<form method="post" action="' . safe(optimize_url()) . '">' . FormToken::field(OptimizeRun::FORM_TOKEN) . '<input type="hidden" name="action" value="start">';
    FrmTabCaption(_m('Run an Optimize task on several AAs'));
    $options = [];
    foreach (OptimizeRun::taskGroups() as $group => $group_tasks) {
        $options['AA_OPTGROUP ' . $group] = $group;
        $options += $group_tasks;
    }
    $input = new AA_Inputfield(getFrmSelectEasy('task[]', $options, $tasks, ['id' => 'aa-optimize-tasks', 'multiple' => 'multiple', 'size' => 10, 'aria-label' => _m('Tasks')]), false, 'normal', '', _m('Tasks'), '', true, _m('Each task is a run of its own. While a page of the runs is open, it runs them one after another, the oldest first; otherwise the task queue runs them side by side. Each AA runs the task of its own version; an AA without it says so.'));
    $input->staticText(false);
    echo $input->get_result();
    FrmInputRadio('method', _m('Do'), optimize_methods(), $method, true, _m('Repair tests first, repairs only where the test fails, then tests again. A task without a repair is only tested.'));
    FrmTabEnd();
    echo '<table class="aa-optimize"><tr><th><input type="checkbox" id="aa-optimize-all" aria-label="' . safe(_m('Select all')) . '"></th><th>' . _m('AA') . '</th><th>' . _m('Auth') . '</th></tr>';
    foreach ($aas as $id => $aa) {
        $badge = $aa->credentialBadge();
        echo '<tr><td><input type="checkbox" name="aa[]" value="' . (int) $id . '" id="aa-optimize-' . (int) $id . '"' . (in_array((int) $id, $checked, true) ? ' checked' : '') . '></td>'
            . '<td><label for="aa-optimize-' . (int) $id . '">' . safe($aa->getName()) . '</label></td><td><span class="aa-auth aa-auth-' . safe($badge['state']) . '">' . safe($badge['text']) . '</span></td></tr>';
    }
    echo '</table><p>' . _m('An AA without a valid token answers nothing - get its token in the Central list first (it lasts a day).') . '</p>'
        . '<p><button class="btn-l aa-once" type="submit">' . _m('Start') . '</button></p></form>';
}

/** the last runs, newest first */
function optimize_recent(): void
{
    $runs = OptimizeRun::recent();
    if (!$runs) {
        return;
    }
    $now = OptimizeRun::clock();
    echo '<h2 id="aa-optimize-runs">' . _m('Last runs') . '</h2><table class="aa-optimize"><tr><th>' . _m('Started') . '</th><th>' . _m('Task') . '</th><th>' . _m('State') . '</th><th>' . _m('Results') . '</th></tr>';
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
    // each AA shows its result once it is done, so the first one without it is the one asked now
    $asking  = $run->hasLiveLease($now) ? current(array_diff($run->aas(), array_keys($results))) : false;
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
            . '<p class="tabhlp">' . _m('While this page is open, it runs the steps one right after another. Closed, the run goes on in the task queue of this AA.') . '</p>';
    }
    if (!$open && ($run->method() === 'test')) {
        optimize_repair_button($run->task(), $results);
    }
    echo '<table class="aa-optimize"><tr><th>' . _m('AA') . '</th><th>' . _m('Result') . '</th><th>' . _m('Message') . '</th></tr>';
    foreach ($run->aas() as $id) {
        $name = isset($aas[$id]) ? $aas[$id]->getName() : "#$id";
        $r    = $results[$id] ?? null;
        echo '<tr><td>' . safe($name) . '</td>';
        if ($r === null) {
            echo '<td>' . safe(!$open ? _m('Not asked') : (($id === $asking) ? _m('Asking now…') : _m('Waiting'))) . '</td><td></td></tr>';
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
        echo '</td></tr>';
        // the AA's own HTML - only in a sandbox, over the whole width: its tables are wide
        $reports = (($r['r'] !== '') ? '<details><summary>' . _m('Report') . '</summary>' . RemoteHtml::sandboxed(_m('Report') . " - $name", $r['r']) . '</details>' : '')
            . (($r['c'] !== '') ? '<details><summary>' . _m('The test after the repair') . '</summary>' . RemoteHtml::sandboxed(_m('The test after the repair') . " - $name", $r['c']) . '</details>' : '');
        if ($reports !== '') {
            echo '<tr class="aa-optimize-reports"><td colspan="3">' . $reports . '</td></tr>';
        }
    }
    echo '</table>';
    echo '<p>' . a_href(optimize_url(), _m('Another run')) . '</p>';
}

/** A finished test run: a button that starts the repair of the task on the AAs whose test failed
 *  @param array<int, array{s: string, m: string, r: string, c: string}> $results */
function optimize_repair_button(string $task, array $results): void
{
    $problems = array_keys(array_filter($results, static fn(array $r): bool => $r['s'] === OptimizeRun::PROBLEM));
    // a class gone or renamed by an update of this AA is no task any more
    if (!$problems || !isset(OptimizeRun::tasks()[$task]) || (OptimizeRun::methodFor($task, 'repair') !== 'repair')) {
        return;
    }
    echo '<form method="post" action="' . safe(optimize_url()) . '">' . FormToken::field(OptimizeRun::FORM_TOKEN)
        . '<input type="hidden" name="action" value="start"><input type="hidden" name="method" value="repair">'
        . '<input type="hidden" name="task[]" value="' . safe($task) . '">'
        . implode('', array_map(static fn($id): string => '<input type="hidden" name="aa[]" value="' . (int) $id . '">', $problems))
        . '<p><button class="btn-l aa-once" type="submit">' . _m('Repair the AAs with a problem (%1)', [count($problems)]) . '</button></p></form>';
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
