<?php

/**
 * Install an app of this AA: a site with its modules, or slices and Alerts modules, as a clean app
 * (install) or with their items (transfer). The same path as a package from elsewhere: the source makes
 * XML (Collector -> Writer), this AA reads it (Reader -> Validator -> Planner -> Importer). A transfer
 * then moves the items in batches, one request each, driven by the progress page - each batch as XML
 * too (TransferProgress). A transfer carries password hashes and 2FA secrets: superadmins only.
 *
 * GET               the form, or with run=<id> the progress / report of a run
 * POST preview      the preview; creates the InstallRun
 * POST write        writes the definitions
 * POST batch|continue|end   JSON for the progress page
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

use AA\Central\InstallRun;
use AA\Central\Package\AaAfterCommit;
use AA\Central\Package\CollectOptions;
use AA\Central\Package\Collector;
use AA\Central\Package\DbImportTarget;
use AA\Central\Package\DbSource;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\ImportReport;
use AA\Central\Package\ItemLint;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Planner;
use AA\Central\Package\Reader;
use AA\Central\Package\Validator;
use AA\Central\Package\ValueCodec;
use AA\Central\Package\Writer;
use AA\Central\Package\WriteError;
use AA\Central\TransferProgress;
use AA\Util\FormToken;
use AA\Util\InputVars;

require_once __DIR__ . "/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";

const INSTALLAPP_TOKEN = 'central-installapp';
const INSTALLAPP_JSON  = ['batch', 'continue', 'end'];

pageOpen();

try {
    $action   = InputVars::stringPOST('action');
    $run_id   = strtolower(InputVars::idPOST('run') ?: InputVars::idGET('run'));
    $token    = InputVars::stringPOST('token');
    $site     = strtolower(InputVars::idPOST('site'));
    $modules  = array_values(array_map('strtolower', array_filter(InputVars::arrayPOST('modules'), static fn($id): bool => is_string($id) && is_long_id($id))));
    $mode     = (InputVars::stringPOST('mode') === InstallRun::TRANSFER) ? InstallRun::TRANSFER : InstallRun::INSTALL;
    $all_bins = InputVars::stringPOST('bins') === 'all';
    $position = [strtolower(InputVars::stringPOST('slice')), strtolower(InputVars::stringPOST('cursor'))];
    $confirm  = InputVars::stringPOST('confirm');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}
$uid  = (string) AuthGetUid();
$json = in_array($action, INSTALLAPP_JSON, true);

if (!Importer::hasInstallPermission($uid)) {
    $json ? installapp_json(['status' => 'denied', 'message' => _m('You may not install apps (PS_INSTALL_APP).')], 403)
          : AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m('You may not install apps (PS_INSTALL_APP).'), 'admin');
}
if (($_SERVER['REQUEST_METHOD'] === 'POST') && !FormToken::check(INSTALLAPP_TOKEN, $token)) {
    $json ? installapp_json(['status' => 'expired', 'message' => _m('The form has expired; reload the page and try again.')], 403)
          : AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "installapp.php", _m('The form has expired; reload the page and try again.'), 'admin');
}

// a transfer hands out password hashes and 2FA secrets of the readers
$transfer = $json || (($action === 'preview') && ($mode === InstallRun::TRANSFER)) || (($action === 'write') && (InstallRun::get($run_id)?->mode() === InstallRun::TRANSFER));
if ($transfer && !IsSuperadmin()) {
    $json ? installapp_json(['status' => 'denied', 'message' => _m('Only a superadmin may transfer apps with their items.')], 403)
          : AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "installapp.php", _m('Only a superadmin may transfer apps with their items.'), 'admin');
}

if ($json) {
    // the batches of a long transfer must not block the other pages of this session
    Session::page_close();
    session_write_close();
    MoreTimeLimit(120);
    $progress = new TransferProgress(new Importer(new DbImportTarget(), $uid), new DbSource(), new AaAfterCommit(), installapp_source(InstallRun::get($run_id)?->started() ?? time()), $uid);
    try {
        installapp_json(match ($action) {
            'batch'    => $progress->next($run_id, $position),
            'continue' => $progress->resume($run_id),
            'end'      => $progress->end($run_id),
        });
    } catch (ImportFailed) {
        installapp_json(['status' => 'denied', 'message' => _m('This is no transfer of yours, or you may not install apps.')], 403);
    } catch (Throwable $e) {
        installapp_log('the progress page request failed', $e);
        installapp_json(['status' => 'failed', 'message' => _m('The request failed - details are in the PHP error log.')], 500);
    }
}

$apage = new AA_Adminpageutil('central', 'installapp');
$apage->setTitle(_m('Install an app'));
$apage->setForm();
$apage->addRequire('
    .aa-installapp-findings { border-collapse: collapse; margin: 0.3em 0 1em; }
    .aa-installapp-findings td, .aa-installapp-findings th { padding: 0.2em 0.6em; text-align: left; vertical-align: top; border-bottom: 1px solid var(--clr-border, #ddd); }
    .aa-installapp-findings .aa-num { text-align: right; font-variant-numeric: tabular-nums; }
    .aa-installapp-findings code { word-break: break-all; }
    .aa-installapp fieldset { border: 0; padding: 0; margin: 0 0 1em; }
    .aa-installapp [hidden] { display: none; }
', 'AA_Req_CSScode');
$apage->printHead();
echo '<div class="aa-installapp">';

MoreTimeLimit();
switch ($action) {
    case 'preview':
        installapp_preview($site, $modules, $mode, $all_bins, $uid);
        break;
    case 'write':
        installapp_write($run_id, $site, $modules, ($confirm === '') ? null : $confirm, $uid);
        break;
    default:
        ($run_id !== '') ? installapp_progress($run_id, $uid) : installapp_form($uid);
}

echo '</div>';
$apage->printFoot();

// ---------------------------------------------------------------------------------------------------

/** @param array<string, mixed> $data */
function installapp_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);
    exit;
}

/** one log line: what failed and why - the page shows only a generic text */
function installapp_log(string $what, Throwable $e): void
{
    error_log('AA Central installapp: ' . $what . ': ' . preg_replace('/[\x00-\x1F\x7F]+/', ' ', get_class($e) . ': ' . $e->getMessage()));
}

/** the generic text of an exception for the page: its code, the detail goes to the log */
function installapp_error(string $what, Throwable $e): string
{
    installapp_log($what, $e);
    $cause = ($e instanceof ImportFailed) ? $e->getPrevious() : $e;
    $code  = ($cause instanceof WriteError) ? $cause->reason : substr((string) strrchr('\\' . get_class($cause ?? $e), '\\'), 1);
    return _m('%1 failed (%2) - details are in the PHP error log.', [$what, $code]);
}

/** meta/source of the packages; exported = the start of the run, so the same selection gives the same package
 *  @return array<string, string> */
function installapp_source(int $started): array
{
    return ['url' => (string) constant('AA_INSTAL_URL'), 'aa-version' => aa_version('aa'), 'exported' => gmdate('Y-m-d\TH:i:s\Z', $started)];
}

function installapp_url(array $params = []): string
{
    return StateUrl(get_url(self_base() . 'installapp.php', $params));
}

/** the hidden inputs of the write form: the selection is collected again and compared with the preview */
function installapp_hidden(string $action, string $site, array $modules, string $run = ''): string
{
    $html = FormToken::field(INSTALLAPP_TOKEN) . '<input type="hidden" name="action" value="' . safe($action) . '"><input type="hidden" name="site" value="' . safe($site) . '">';
    foreach ($modules as $id) {
        $html .= '<input type="hidden" name="modules[]" value="' . safe($id) . '">';
    }
    return $html . (($run === '') ? '' : '<input type="hidden" name="run" value="' . safe($run) . '">');
}

function installapp_form(string $uid): void
{
    $mine = array_filter(InstallRun::unfinished(), static fn(InstallRun $run): bool => $run->user() === $uid);
    if ($mine) {
        echo '<div class="aa-info"><p>' . _m('Unfinished transfers') . ':</p><ul>';
        foreach ($mine as $run) {
            echo '<li>' . a_href(installapp_url(['run' => $run->getId()]), safe(date('Y-m-d H:i', $run->started())) . ' - ' . safe(installapp_state($run->state()))) . '</li>';
        }
        echo '</ul></div>';
    }
    $sites  = ['' => _m('- no site, the modules below -')] + AA_Module::getModules('W', true);
    $others = AA_Module::getModules('S', true) + AA_Module::getModules('Alerts', true);

    echo '<form method="post" action="' . safe(installapp_url()) . '">' . FormToken::field(INSTALLAPP_TOKEN) . '<input type="hidden" name="action" value="preview">';
    FrmTabCaption(_m('What to install from this AA'));
    echo '<tr><td class="tabtxt"><label for="installapp-site">' . _m('Site') . '</label></td><td><select name="site" id="installapp-site">';
    foreach ($sites as $id => $name) {
        echo '<option value="' . safe((string) $id) . '">' . safe((string) $name) . '</option>';
    }
    echo '</select><div class="tabhlp">' . _m('The site brings the slices and Alerts modules it uses.') . '</div></td></tr>';
    echo '<tr><td class="tabtxt"><label for="installapp-modules">' . _m('Slices and Alerts') . '</label></td><td><select name="modules[]" id="installapp-modules" multiple size="10">';
    foreach ($others as $id => $name) {
        echo '<option value="' . safe((string) $id) . '">' . safe((string) $name) . '</option>';
    }
    echo '</select><div class="tabhlp">' . _m('Only when no site is chosen.') . '</div></td></tr>';
    echo '<tr><td class="tabtxt">' . _m('Mode') . '</td><td><fieldset>'
        . '<label><input type="radio" name="mode" value="install" checked> ' . _m('Install - a clean app, no items') . '</label><br>'
        . '<label><input type="radio" name="mode" value="transfer"> ' . _m('Transfer - a copy with the items and readers') . '</label></fieldset></td></tr>';
    echo '<tr><td class="tabtxt">' . _m('Items of') . '</td><td><fieldset>'
        . '<label><input type="radio" name="bins" value="active" checked> ' . _m('the active bin') . '</label><br>'
        . '<label><input type="radio" name="bins" value="all"> ' . _m('all bins') . '</label></fieldset>'
        . '<div class="tabhlp">' . _m('A transfer only.') . '</div></td></tr>';
    FrmTabEnd();
    echo '<p><button class="btn-l" type="submit">' . _m('Preview') . '</button></p></form>';
}

/** Collects the app and plans it on this AA.
 *  @param list<string> $modules
 *  @return array{error: string, xml: string, findings: list<array{string, LintFinding}>, plan: ?ImportPlan} */
function installapp_prepare(string $site, array $modules, string $mode, bool $all_bins, int $started): array
{
    $out      = ['error' => '', 'xml' => '', 'findings' => [], 'plan' => null];
    $transfer = ($mode === InstallRun::TRANSFER);
    try {
        $collector = new Collector(new DbSource());
        $options   = new CollectOptions(installapp_source($started), $transfer ? CollectOptions::TRANSFER : CollectOptions::INSTALL, $all_bins);
        $collected = ($site !== '') ? $collector->fromSite($site, $options) : $collector->fromModules($modules, $options);
        foreach ($collected->checkPermissions as $module) {
            if (!IfSlPerm(PS_EDIT, $module)) {
                $out['error'] = _m('You may not change the module %1 - it cannot be exported.', [AA_Module::getModuleName($module) ?: $module]);
                return $out;
            }
        }
        if ($collected->failed()) {
            $out['findings'] = $collected->findings;
            return $out;
        }
        $written         = (new Writer())->toXml($collected->package);
        $out['xml']      = $written->xml;
        $out['findings'] = $collected->withWriter($written);
        $read            = (new Reader())->read($written->xml);
        $out['findings'] = [...$out['findings'], ...$read->findings];
        $readers         = [];
        foreach ($read->package->modules as $m) {
            if (($m->type === Module::SLICE) && (ValueCodec::bytes($m->rows['slice'][0]['type'] ?? '') === ItemLint::READER_SLICE)) {
                $readers[] = $m->id;
            }
        }
        [$logins, $login_findings] = ($transfer && $readers) ? $collector->readerLogins($readers, $all_bins) : [[], []];
        $out['findings'] = [...$out['findings'], ...$login_findings];
        $validation      = (new Validator())->validate($read->package, $transfer);
        if (!$validation->importable()) {
            $out['findings'] = [...$out['findings'], ...$validation->findings];
            return $out;
        }
        $out['plan']     = (new Planner(new DbImportTarget()))->plan($read->package, $validation, $transfer, $logins);
        $out['findings'] = [...$out['findings'], ...$out['plan']->allFindings()];
    } catch (Throwable $e) {
        $out['error'] = installapp_error(_m('Reading the app'), $e);
    }
    return $out;
}

/** no E anywhere - the definitions may be written */
function installapp_importable(array $prepared): bool
{
    return ($prepared['error'] === '') && $prepared['plan'] && !array_filter($prepared['findings'], static fn(array $f): bool => $f[1]->level === LintFinding::ERROR);
}

/** @param list<string> $modules */
function installapp_preview(string $site, array $modules, string $mode, bool $all_bins, string $uid): void
{
    if (($site === '') === !$modules) {
        echo '<div class="aa-err">' . _m('Choose a site, or slices and Alerts modules - not both.') . '</div>';
        installapp_form($uid);
        return;
    }
    $now      = time();
    $prepared = installapp_prepare($site, $modules, $mode, $all_bins, $now);
    if ($prepared['error'] !== '') {
        echo '<div class="aa-err">' . safe($prepared['error']) . '</div>';
        return;
    }
    $plan = $prepared['plan'];
    if ($plan) {
        $preview = $plan->preview();
        echo '<h2>' . safe($preview['name']) . ' - ' . safe(($mode === InstallRun::TRANSFER) ? _m('transfer with the items') : _m('install, no items')) . '</h2>';
        echo '<table class="aa-installapp-findings"><tr><th>' . _m('New module') . '</th><th>' . _m('Type') . '</th><th>' . _m('Source id') . '</th>'
            . (($mode === InstallRun::TRANSFER) ? '<th class="aa-num">' . _m('Items') . '</th>' : '') . '</tr>';
        $source = new DbSource();
        foreach ($preview['modules'] as $m) {
            echo '<tr><td>' . safe($m['name']) . '</td><td>' . safe($m['type']) . '</td><td><code>' . safe($m['old']) . '</code></td>'
                . (($mode === InstallRun::TRANSFER) ? '<td class="aa-num">' . (($m['type'] === Module::SLICE) ? $source->itemCount($m['old'], $all_bins) : '') . '</td>' : '') . '</tr>';
        }
        echo '</table>';
    }
    installapp_findings($prepared['findings']);
    if (!installapp_importable($prepared)) {
        echo '<div class="aa-err">' . _m('The app cannot be installed - see the errors (E) above.') . '</div>';
        return;
    }
    $run = InstallRun::preview($mode, $all_bins, InstallRun::LOCAL, $uid, '', $now, hash('sha256', $prepared['xml']));

    // one write per preview: the second click of a double click would only fail
    echo '<form method="post" action="' . safe(installapp_url()) . '" onsubmit="this.querySelector(\'button[type=submit]\').disabled = true">' . installapp_hidden('write', $site, $modules, $run->getId());
    if ($plan->preview()['confirm']) {
        echo '<p><label><input type="checkbox" name="confirm" value="' . safe((string) (new ImportConfirmation())->token($plan, $uid)) . '" required> '
            . _m('I have read the security findings (S) and want to install the app with them.') . '</label></p>';
    }
    if ($mode === InstallRun::TRANSFER) {
        echo '<div class="aa-info">' . _m('Do not edit the source while the transfer runs.') . '</div>';
    }
    echo '<p><button class="btn-l" type="submit">' . (($mode === InstallRun::TRANSFER) ? _m('Write the definitions and transfer the items') : _m('Install')) . '</button></p></form>';
}

/** @param list<string> $modules */
function installapp_write(string $run_id, string $site, array $modules, ?string $confirm, string $uid): void
{
    $run = InstallRun::get($run_id);
    if (($run === null) || ($run->user() !== $uid) || ($run->state() !== InstallRun::PREVIEW)) {
        echo '<div class="aa-err">' . _m('This preview is no longer open - it was written already or forgotten.') . '</div>';
        if ($run && ($run->user() === $uid) && ($run->mode() === InstallRun::TRANSFER)) {
            installapp_progress($run_id, $uid);
        }
        return;
    }
    $prepared = installapp_prepare($site, $modules, $run->mode(), $run->allBins(), $run->started());
    if (($prepared['error'] !== '') || !hash_equals($run->fingerprint(), hash('sha256', $prepared['xml']))) {
        echo '<div class="aa-err">' . _m('The source changed since the preview - preview again.') . '</div>';
        return;
    }
    if (!installapp_importable($prepared)) {
        installapp_findings($prepared['findings']);
        echo '<div class="aa-err">' . _m('The app cannot be installed - see the errors (E) above.') . '</div>';
        return;
    }
    try {
        $report = (new Importer(new DbImportTarget(), $uid))->write($prepared['plan'], $run_id, $prepared['xml'], $confirm);
    } catch (ImportFailed $e) {
        $now = InstallRun::get($run_id);
        if (($now !== null) && ($now->user() === $uid) && ($now->state() !== InstallRun::PREVIEW)) {
            installapp_progress($run_id, $uid);         // another request of this preview wrote it
            return;
        }
        echo '<div class="aa-err">' . safe(installapp_error(_m('Writing the app'), $e)) . '</div>';
        return;
    }
    installapp_report($report);
    if ($run->mode() === InstallRun::TRANSFER) {
        installapp_progress($run_id, $uid, true);
    }
}

function installapp_report(ImportReport $report): void
{
    echo '<div class="aa-ok">' . _m('The definitions are written.') . '</div><table class="aa-installapp-findings"><tr><th>' . _m('New module') . '</th><th>' . _m('Type') . '</th><th>' . _m('Id') . '</th></tr>';
    foreach ($report->modules as $m) {
        echo '<tr><td>' . safe($m['name']) . '</td><td>' . safe($m['type']) . '</td><td><code>' . safe($m['new']) . '</code></td></tr>';
    }
    echo '</table>';
    foreach ($report->settings as $s) {
        echo '<p>' . a_href(get_aa_url($s['url']), safe(_m('Fill %1 settings of %2', [count($s['fields']), $s['name']]))) . '</p>';
    }
    foreach ($report->failures() as [$step, $errors]) {
        echo '<div class="aa-err">' . safe($step . ': ' . implode('; ', $errors)) . '</div>';
    }
    installapp_findings($report->findings);
}

/** findings by level; every text is package text
 *  @param list<array{string, LintFinding}> $findings */
function installapp_findings(array $findings): void
{
    $levels = [LintFinding::SECURITY => _m('Security findings (S)'), LintFinding::ERROR => _m('Errors (E)'), LintFinding::WARNING => _m('Warnings (W)'), LintFinding::INFO => _m('Notes (I)')];
    $unique = [];
    foreach ($findings as $f) {                  // the export lint and the import lint read the same texts
        $unique[implode("\0", [$f[1]->level, $f[1]->code, $f[0], $f[1]->ref])] ??= $f;
    }
    foreach ($levels as $level => $label) {
        $of = array_filter($unique, static fn(array $f): bool => $f[1]->level === $level);
        if (!$of) {
            continue;
        }
        echo '<details' . (in_array($level, [LintFinding::SECURITY, LintFinding::ERROR], true) ? ' open' : '') . '><summary>' . $label . ' - ' . count($of) . '</summary><table class="aa-installapp-findings">';
        foreach ($of as [$path, $finding]) {
            echo '<tr><td>' . safe($finding->code) . '</td><td><code>' . safe($path) . '</code></td><td><code>' . safe($finding->ref) . '</code></td></tr>';
        }
        echo '</table></details>';
    }
}

function installapp_state(string $state): string
{
    return [InstallRun::PREVIEW => _m('Preview'), InstallRun::ITEMS => _m('Transferring the items'), InstallRun::ERROR => _m('Stopped by an error'),
        InstallRun::DONE => _m('Finished'), InstallRun::ENDED => _m('Ended')][$state] ?? $state;
}

/** the progress of a transfer; its report when it is finished
 *  @param bool $start run the batches at once - only right after the definitions; a link opens it paused */
function installapp_progress(string $run_id, string $uid, bool $start = false): void
{
    $run = InstallRun::get($run_id);
    if (($run === null) || ($run->user() !== $uid)) {
        echo '<div class="aa-err">' . _m('No such install run of yours.') . '</div>';
        return;
    }
    if ($run->state() === InstallRun::PREVIEW) {
        echo '<div class="aa-info">' . _m('This app was previewed, but not installed yet.') . ' ' . a_href(installapp_url(), _m('Preview it again')) . '</div>';
        return;
    }
    if ($run->mode() !== InstallRun::TRANSFER) {
        echo '<p>' . safe(installapp_state($run->state())) . '</p>';
        return;
    }
    $source  = new DbSource();
    $written = $run->written();
    if ($run->state() === InstallRun::DONE) {
        installapp_check($run);
        return;
    }
    [$slice, $cursor] = $run->position();
    $states           = [InstallRun::ITEMS, InstallRun::ERROR, InstallRun::DONE, InstallRun::ENDED];
    echo '<div class="aa-info">' . _m('Do not edit the source while the transfer runs.') . '</div>';
    echo '<div id="installapp-progress" data-url="' . safe(installapp_url()) . '" data-report="' . safe(installapp_url(['run' => $run_id])) . '" data-token="' . safe(FormToken::get(INSTALLAPP_TOKEN)) . '"'
        . ' data-run="' . safe($run_id) . '" data-state="' . safe($run->state()) . '" data-slice="' . safe($slice) . '" data-cursor="' . safe($cursor) . '"'
        . ' data-states="' . safe(json_encode(array_combine($states, array_map('installapp_state', $states)))) . '"'
        . ' data-confirm-end="' . safe(_m('End the transfer? The items written so far stay.')) . '" data-login="' . safe(_m('The session has expired - log in again and reload this page.')) . '"'
        . ' data-report-link="' . safe(_m('The report')) . '"' . ($start ? ' data-start="1"' : '') . '>';
    echo '<table class="aa-installapp-findings"><tr><th>' . _m('Slice') . '</th><th class="aa-num">' . _m('Written') . '</th><th class="aa-num">' . _m('On the source') . '</th></tr>';
    foreach ($run->slices() as $id) {
        echo '<tr><td>' . safe(AA_Module::getModuleName($id) ?: $id) . '</td><td class="aa-num" data-slice="' . safe($id) . '">' . (int) ($written[$id] ?? 0) . '</td><td class="aa-num">' . $source->itemCount($id, $run->allBins()) . '</td></tr>';
    }
    $error = ($run->state() === InstallRun::ERROR) ? implode(' ', $run->error() ?? []) : '';
    echo '</table><p><strong data-role="state">' . safe(installapp_state($run->state())) . '</strong> <span data-role="error">' . safe($error) . '</span></p><ul data-role="findings"></ul>';
    echo '<p><button type="button" class="btn-l" data-role="continue" hidden>' . _m('Continue') . '</button> <button type="button" class="btn-l btn-secondary" data-role="end">' . _m('End') . '</button></p></div>';
    installapp_script();
}

/** the report of a finished transfer, as its finish stored it */
function installapp_check(InstallRun $run): void
{
    $check = $run->report() + ['checked' => 0, 'slices' => [], 'findings' => []];
    echo '<div class="aa-ok">' . _m('The transfer is finished. Checked at %1:', [date('Y-m-d H:i', (int) $check['checked'])]) . '</div><table class="aa-installapp-findings"><tr><th>' . _m('Slice') . '</th><th class="aa-num">' . _m('Written') . '</th><th class="aa-num">' . _m('On the source') . '</th><th class="aa-num">' . _m('In the new slice') . '</th></tr>';
    foreach ($check['slices'] as $s) {
        echo '<tr><td>' . a_href(AA_Module::getModuleUrl($s['target'], 'admin/index.php'), safe(AA_Module::getModuleName($s['target']) ?: $s['target'])) . '</td><td class="aa-num">' . $s['written'] . '</td><td class="aa-num">' . $s['sourceCount'] . '</td><td class="aa-num">' . $s['targetCount'] . '</td></tr>';
    }
    echo '</table>';
    installapp_findings(array_map(static fn(array $f): array => [(string) $f['path'], new LintFinding((string) $f['level'], (string) $f['code'], (string) $f['ref'], 0)], $check['findings']));
    echo '<p>' . _m('Uploaded files are not transferred - copy them by hand. Switch the domain to the new site only after checking it.') . '</p>';
}

/** the loop of the progress page: POST "next batch" until the run leaves the items; the run says where it
 *  is, so a stale answer only moves the position on */
function installapp_script(): void
{
    echo <<<'HTML'
<script>
(() => {
    const box = document.getElementById('installapp-progress');
    const el = role => box.querySelector(`[data-role="${role}"]`);
    const states = JSON.parse(box.dataset.states);
    let pos = {slice: box.dataset.slice, cursor: box.dataset.cursor};
    let running = false;
    let state = box.dataset.state;

    const post = async action => {
        const body = new URLSearchParams({action, run: box.dataset.run, token: box.dataset.token, slice: pos.slice, cursor: pos.cursor});
        const response = await fetch(box.dataset.url, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}});
        const data = await response.json().catch(() => null);       // a login page after the session expired
        if (!data) {
            throw new Error(box.dataset.login);
        }
        if (!response.ok || !data.state) {
            throw new Error(data.message || `HTTP ${response.status}`);
        }
        return data;
    };
    const show = s => {
        pos = s.position;
        for (const [slice, n] of Object.entries(s.written || {})) {
            const cell = box.querySelector(`td[data-slice="${CSS.escape(slice)}"]`);
            if (cell) cell.textContent = n;
        }
        el('state').textContent = states[s.state] || s.state;
        el('error').textContent = s.error ? `${s.error.code} ${s.error.path}` : '';
        const lines = [
            ...Object.entries(s.warnings || {}).flatMap(([slice, codes]) => Object.entries(codes).map(([code, n]) => `W ${code} slice-module[${slice}] ${n}x`)),
            ...(s.findings || []).filter(f => f.level !== 'W').map(f => `${f.level} ${f.code} ${f.path} ${f.ref}`),
            ...(s.cleanup || []).map(([step, error]) => `W ${step} ${error}`),
        ];
        el('findings').replaceChildren(...lines.map(line => Object.assign(document.createElement('li'), {textContent: line})));
        state = s.state;
        el('continue').hidden = s.state !== 'error';
        el('end').hidden = !['items', 'error'].includes(s.state);
        if (s.state === 'done') {
            if ((s.cleanup || []).length) {
                const li = document.createElement('li');
                li.append(Object.assign(document.createElement('a'), {href: box.dataset.report, textContent: box.dataset.reportLink}));
                el('findings').append(li);
            } else {
                location.href = box.dataset.report;
            }
        }
    };
    const fail = e => {
        el('error').textContent = e.message;
        el('continue').hidden = false;
    };
    const loop = async () => {
        if (running) return;
        running = true;
        try {
            for (let s = {state: 'items'}; s.state === 'items';) {
                s = await post('batch');
                show(s);
                if (s.status === 'stale') await new Promise(resolve => setTimeout(resolve, 500));
            }
        } catch (e) {
            fail(e);
        }
        running = false;
    };
    el('continue').addEventListener('click', async () => {
        if (state === 'items') {
            loop();
            return;
        }
        try {
            const s = await post('continue');
            show(s);
            if (s.state === 'items') loop();
        } catch (e) {
            fail(e);
        }
    });
    el('end').addEventListener('click', async () => {
        if (!confirm(box.dataset.confirmEnd)) return;
        try {
            show(await post('end'));
        } catch (e) {
            fail(e);
        }
    });
    el('continue').hidden = !['items', 'error'].includes(state) || (state === 'items' && box.dataset.start);
    el('end').hidden = !['items', 'error'].includes(state);
    if (state === 'items' && box.dataset.start) loop();
})();
</script>
HTML;
}
