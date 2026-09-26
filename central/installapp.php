<?php

/**
 * Install an app of this AA or of another one (aa=<central_conf id>): a site with its modules, or slices
 * and Alerts modules, as a clean app (install) or with their items (transfer). One path for both: the
 * source makes XML (AppExport here, Get_App there), this AA reads it (Reader -> Validator -> Planner ->
 * Importer). A transfer then moves the items in batches, one request each, driven by the progress page -
 * each batch as XML too (TransferProgress). A transfer carries password hashes and 2FA secrets:
 * superadmins only, on both AAs. Another AA gives its app only to its superadmin: the admin logs in to
 * it here, this AA keeps the short token it gets only in the run (encrypted), never the password.
 *
 * GET               the form (aa=<id>: the login to another AA), or with run=<id> the progress / report of a run
 * POST connect      logs in to another AA: the run opens in preview with its token, the choice of the app follows
 * POST preview      the preview; creates the InstallRun (this AA) or fills the one of the login
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

use AA\Central\Actionapps;
use AA\Central\AppExport;
use AA\Central\AppSource;
use AA\Central\ExportRefused;
use AA\Central\InstallRun;
use AA\Central\ItemIdCheck;
use AA\Central\Package\AaAfterCommit;
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
use AA\Central\Package\WriteError;
use AA\Central\RemoteAppSource;
use AA\Central\RemoteSource;
use AA\Central\SourceFailed;
use AA\Central\TransferProgress;
use AA\IO\DB\DB_AA;
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
    $aa       = max(0, InputVars::intGETPOST('aa'));     // a row of the Central list: the app comes from that AA
    // not username/password: those would log this session in as the user of the other AA
    $login    = [InputVars::stringPOST('source_username'), InputVars::stringPOST('source_password'), InputVars::stringPOST('source_code2fa')];
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
$transfer = $json || (in_array($action, ['connect', 'preview'], true) && ($mode === InstallRun::TRANSFER)) || (in_array($action, ['preview', 'write'], true) && (InstallRun::get($run_id)?->mode() === InstallRun::TRANSFER));
if ($transfer && !IsSuperadmin()) {
    $json ? installapp_json(['status' => 'denied', 'message' => _m('Only a superadmin may transfer apps with their items.')], 403)
          : AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "installapp.php", _m('Only a superadmin may transfer apps with their items.'), 'admin');
}
// the run of another AA keeps a token of that AA's superadmin - for an install too
if (($action === 'connect') && !IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "installapp.php", _m('Only a superadmin may install apps from another AA.'), 'admin');
}

if ($json) {
    // the batches of a long transfer must not block the other pages of this session
    Session::page_close();
    session_write_close();
    MoreTimeLimit(120);
    $run = InstallRun::get($run_id);
    try {
        // built only by the steps that ask the source: End works when its Central row is gone
        $progress = new TransferProgress(new Importer(new DbImportTarget(), $uid), static fn(): AppSource => installapp_run_source($run), new AaAfterCommit(), $uid);
        $renewed  = '';
        if (($action === 'continue') && $run && ($run->source() !== InstallRun::LOCAL) && ($run->user() === $uid) && ($run->state() === InstallRun::ERROR)) {
            if ($login[0] !== '') {
                $remote  = RemoteSource::forAa((int) $run->source());
                $renewed = $remote->seal($remote->jwt(...$login));
            } elseif (($run->error()[0] ?? '') === SourceFailed::LOGIN) {
                installapp_json(TransferProgress::state($run, 'login'));
            }
        }
        installapp_json(match ($action) {
            'batch'    => $progress->next($run_id, $position),
            'continue' => $progress->resume($run_id, $renewed),
            'end'      => $progress->end($run_id),
        });
    } catch (ImportFailed) {
        installapp_json(['status' => 'denied', 'message' => _m('This is no transfer of yours, or you may not install apps.')], 403);
    } catch (SourceFailed $e) {
        installapp_json(['status' => 'denied', 'message' => $e->getMessage()], 403);
    } catch (ExportRefused) {
        installapp_json(['status' => 'denied', 'message' => _m('The source has no such app, or you may not export it.')], 403);
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
    case 'connect':
        installapp_connect($aa, $mode, $all_bins, $login, $uid);
        break;
    case 'preview':
        ($run_id !== '') ? installapp_remote_preview($run_id, $site, $modules, $uid) : installapp_preview($site, $modules, $mode, $all_bins, $uid);
        break;
    case 'write':
        installapp_write($run_id, $site, $modules, ($confirm === '') ? null : $confirm, $uid);
        break;
    default:
        ($run_id !== '') ? installapp_progress($run_id, $uid) : installapp_form($aa, $uid);
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

/** an app of this AA, with the rights of the user here */
function installapp_local_source(): AppExport
{
    $attributes = ['url' => (string) constant('AA_INSTAL_URL'), 'aa-version' => aa_version('aa')];
    return new AppExport(new DbSource(), $attributes, static fn(string $module): bool => IfSlPerm(PS_EDIT, $module), IsSuperadmin());
}

/** the AA of the run's central_conf row, with the token of its superadmin the run keeps sealed
 *  @throws SourceFailed no such row */
function installapp_remote_source(InstallRun $run): RemoteAppSource
{
    try {
        $sealed = $run->token();
    } catch (RuntimeException) {            // AA_SECRET changed: the source refuses the empty token, Continue logs in again
        $sealed = '';
    }
    $remote = RemoteSource::forAa((int) $run->source());
    return new RemoteAppSource($remote, $remote->unseal($sealed));
}

/** the source of a run; no run - this AA (the progress requests then refuse it anyway)
 *  @throws SourceFailed */
function installapp_run_source(?InstallRun $run): AppSource
{
    return (($run === null) || ($run->source() === InstallRun::LOCAL)) ? installapp_local_source() : installapp_remote_source($run);
}

/** the link back to the login to the source AA of the run */
function installapp_relogin(InstallRun $run): string
{
    return a_href(installapp_url(['aa' => $run->source()]), _m('Log in to the source AA again'));
}

/** the items of the slices on the source; [] when it cannot tell
 *  @param list<string> $slices @return array<string, int> */
function installapp_counts(AppSource $from, array $slices, bool $all_bins): array
{
    try {
        return $slices ? $from->itemCounts($slices, $all_bins) : [];
    } catch (ExportRefused | SourceFailed $e) {
        installapp_log('the item counts of the source', $e);
        return [];
    }
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

function installapp_form(int $aa, string $uid): void
{
    $mine = array_filter(InstallRun::unfinished(), static fn(InstallRun $run): bool => $run->user() === $uid);
    if ($mine) {
        echo '<div class="aa-info"><p>' . _m('Unfinished transfers') . ':</p><ul>';
        foreach ($mine as $run) {
            echo '<li>' . a_href(installapp_url(['run' => $run->getId()]), safe(date('Y-m-d H:i', $run->started())) . ' - ' . safe(installapp_state($run->state()))) . '</li>';
        }
        echo '</ul></div>';
    }
    if ($aa) {
        installapp_login_form($aa);
        return;
    }
    installapp_source_form();
    echo '<form method="post" action="' . safe(installapp_url()) . '">' . FormToken::field(INSTALLAPP_TOKEN) . '<input type="hidden" name="action" value="preview">';
    FrmTabCaption(_m('What to install from this AA'));
    installapp_module_rows(AA_Module::getModules('W', true), AA_Module::getModules('S', true) + AA_Module::getModules('Alerts', true));
    installapp_mode_rows();
    FrmTabEnd();
    echo '<p><button class="btn-l" type="submit">' . _m('Preview') . '</button></p></form>';
}

/** The choice of the source: this AA, or another one of the Central list, which leads to its login.
 *  Superadmins only - the login to another AA is theirs anyway. */
function installapp_source_form(): void
{
    $aas = IsSuperadmin() ? Actionapps::getArray() : [];
    if (!$aas) {
        return;
    }
    $options = [0 => _m('This AA')];
    foreach ($aas as $id => $aa) {
        $options[$id] = $aa->getName();
    }
    // a GET form drops the query of its action - the state goes in a hidden field too
    echo '<form method="get" action="' . safe(installapp_url()) . '">' . StateHidden();
    FrmTabCaption(_m('Source'));
    FrmInputSelect('aa', _m('Install from'), $options, '0', true, _m('Another AA asks for the login of its superadmin.'));
    FrmTabEnd();
    echo '<p><button class="btn" type="submit">' . _m('Choose the source') . '</button></p></form>';
}

/** the first step for another AA: the mode and the login of its superadmin - the app is chosen after it,
 *  from the modules that login sees */
function installapp_login_form(int $aa): void
{
    $remote = Actionapps::listed($aa);
    if (!$remote) {
        echo '<div class="aa-err">' . _m('No such AA in the Central list.') . '</div>';
        return;
    }
    echo '<form method="post" action="' . safe(installapp_url()) . '">' . FormToken::field(INSTALLAPP_TOKEN) . '<input type="hidden" name="action" value="connect"><input type="hidden" name="aa" value="' . $aa . '">';
    FrmTabCaption(_m('Install an app from %1', [safe($remote->getName())]));
    installapp_mode_rows();
    echo installapp_login_rows();
    FrmTabEnd();
    echo '<p><button class="btn-l" type="submit">' . _m('Log in and choose the app') . '</button></p></form>';
}

/** @param array<string, string> $sites id => name @param array<string, string> $others slices and Alerts, id => name */
function installapp_module_rows(array $sites, array $others): void
{
    echo '<tr><td class="tabtxt"><label for="installapp-site">' . _m('Site') . '</label></td><td><select name="site" id="installapp-site">';
    foreach (['' => _m('- no site, the modules below -')] + $sites as $id => $name) {
        echo '<option value="' . safe((string) $id) . '">' . safe((string) $name) . '</option>';
    }
    echo '</select><div class="tabhlp">' . _m('The site brings the slices and Alerts modules it uses.') . '</div></td></tr>';
    echo '<tr><td class="tabtxt"><label for="installapp-modules">' . _m('Slices and Alerts') . '</label></td><td><select name="modules[]" id="installapp-modules" multiple size="10">';
    foreach ($others as $id => $name) {
        echo '<option value="' . safe((string) $id) . '">' . safe((string) $name) . '</option>';
    }
    echo '</select><div class="tabhlp">' . _m('Only when no site is chosen.') . '</div></td></tr>';
}

function installapp_mode_rows(): void
{
    echo '<tr><td class="tabtxt">' . _m('Mode') . '</td><td><fieldset>'
        . '<label><input type="radio" name="mode" value="install" checked> ' . _m('Install - a clean app, no items') . '</label><br>'
        . '<label><input type="radio" name="mode" value="transfer"> ' . _m('Transfer - a copy with the items and readers') . '</label></fieldset></td></tr>';
    echo '<tr><td class="tabtxt">' . _m('Items of') . '</td><td><fieldset>'
        . '<label><input type="radio" name="bins" value="active" checked> ' . _m('the active bin') . '</label><br>'
        . '<label><input type="radio" name="bins" value="all"> ' . _m('all bins') . '</label></fieldset>'
        . '<div class="tabhlp">' . _m('A transfer only.') . '</div></td></tr>';
}

/** Logs in to the other AA and opens the run in preview with its token - the only place the token is
 *  kept - then offers the modules of the source.
 *  @param array{string, string, string} $login username, password, 2FA code of the source */
function installapp_connect(int $aa, string $mode, bool $all_bins, array $login, string $uid): void
{
    if (!Actionapps::listed($aa)) {
        echo '<div class="aa-err">' . _m('No such AA in the Central list.') . '</div>';
        return;
    }
    try {
        $remote = RemoteSource::forAa($aa);
        $sealed = $remote->seal($remote->jwt(...$login));
    } catch (SourceFailed $e) {
        echo '<div class="aa-err">' . safe($e->getMessage()) . '</div>';
        installapp_form($aa, $uid);
        return;
    }
    installapp_choose(InstallRun::preview($mode, $all_bins, (string) $aa, $uid, $sealed, time()));
}

/** the choice of the app of a run opened at the login to another AA */
function installapp_choose(InstallRun $run): void
{
    try {
        $from   = installapp_remote_source($run);
        $sites  = $from->modules(['W']);
        $others = $from->modules(['S', 'Alerts']);
    } catch (SourceFailed $e) {
        echo '<div class="aa-err">' . safe($e->getMessage()) . ' ' . installapp_relogin($run) . '</div>';
        return;
    }
    echo '<form method="post" action="' . safe(installapp_url()) . '">' . FormToken::field(INSTALLAPP_TOKEN)
        . '<input type="hidden" name="action" value="preview"><input type="hidden" name="run" value="' . safe($run->getId()) . '">';
    FrmTabCaption(_m('What to install') . ' - ' . safe(($run->mode() === InstallRun::TRANSFER) ? _m('transfer with the items') : _m('install, no items')));
    installapp_module_rows($sites, $others);
    FrmTabEnd();
    echo '<p><button class="btn-l" type="submit">' . _m('Preview') . '</button></p></form>';
}

/** the login to the source AA: a superadmin of it; used for one request, never stored */
function installapp_login_rows(): string
{
    return '<tr><td class="tabtxt"><label for="installapp-user">' . _m('Username on the source AA') . '</label></td><td><input type="text" name="source_username" id="installapp-user" required autocomplete="off">'
        . '<div class="tabhlp">' . _m('A superadmin of the source AA. The password is used for this request only; this AA keeps a login token for a day, until the transfer ends.') . '</div></td></tr>'
        . '<tr><td class="tabtxt"><label for="installapp-password">' . _m('Password') . '</label></td><td><input type="password" name="source_password" id="installapp-password" required autocomplete="off"></td></tr>'
        . '<tr><td class="tabtxt"><label for="installapp-code2fa">' . _m('2FA code') . '</label></td><td><input type="text" name="source_code2fa" id="installapp-code2fa" inputmode="numeric" autocomplete="off">'
        . '<div class="tabhlp">' . _m('Only when the user has two-factor login on the source AA.') . '</div></td></tr>';
}

/** Reads the app from its source and plans it on this AA.
 *  @param list<string> $modules
 *  @return array{error: string, login: bool, xml: string, now: int, same: bool, findings: list<array{string, LintFinding}>, plan: ?ImportPlan}
 *          login: the source refused its token; now: the time of the source; same: the source is this AA */
function installapp_prepare(AppSource $from, string $site, array $modules, string $mode, bool $all_bins, int $started): array
{
    $out      = ['error' => '', 'login' => false, 'xml' => '', 'now' => 0, 'same' => false, 'findings' => [], 'plan' => null];
    $transfer = ($mode === InstallRun::TRANSFER);
    try {
        [$out['xml'], $out['findings'], $out['now']] = $from->definitions(($site !== '') ? [$site] : $modules, $transfer, $all_bins, $started);
        $out['same']     = $from->sameAa();
        $read            = (new Reader())->read($out['xml']);
        $out['findings'] = [...$out['findings'], ...$read->findings];
        $readers         = [];
        foreach ($read->package->modules as $m) {
            if (($m->type === Module::SLICE) && (ValueCodec::bytes($m->rows['slice'][0]['type'] ?? '') === ItemLint::READER_SLICE)) {
                $readers[] = $m->id;
            }
        }
        $logins     = ($transfer && $readers) ? $from->logins($readers, $all_bins) : [];
        $validation = (new Validator())->validate($read->package, $transfer);
        if (!$validation->importable()) {
            $out['findings'] = [...$out['findings'], ...$validation->findings];
            return $out;
        }
        $out['plan']     = (new Planner(new DbImportTarget()))->plan($read->package, $validation, $transfer, $logins);
        $out['findings'] = [...$out['findings'], ...$out['plan']->allFindings()];
        if ($transfer && !$out['same']) {        // another AA keeps the item ids: none may be here already
            $slices          = array_values(array_map(static fn(Module $m): string => $m->id, array_filter($read->package->modules, static fn(Module $m): bool => $m->type === Module::SLICE)));
            $out['findings'] = [...$out['findings'], ...(new ItemIdCheck($from, new DbImportTarget()))->check($slices, $all_bins)];
        }
    } catch (ExportRefused $e) {
        if ($e->isDenied()) {
            $out['error'] = ($e->module !== '') ? _m('You may not change the module %1 - it cannot be exported.', [AA_Module::getModuleName($e->module) ?: $e->module])
                                                : _m('The source has no such app, or you may not export it.');
        }
        $out['findings'] = [...$out['findings'], ...$e->findings];
        $out['plan']     = null;
    } catch (SourceFailed $e) {
        installapp_log('the source of the app', $e);
        $out['error'] = $e->getMessage();
        $out['login'] = ($e->reason === SourceFailed::LOGIN);
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

/** the preview of an app of this AA: the run is created with it
 *  @param list<string> $modules */
function installapp_preview(string $site, array $modules, string $mode, bool $all_bins, string $uid): void
{
    if (($site === '') === !$modules) {
        echo '<div class="aa-err">' . _m('Choose a site, or slices and Alerts modules - not both.') . '</div>';
        installapp_form(0, $uid);
        return;
    }
    $now      = time();
    $from     = installapp_local_source();
    $prepared = installapp_prepare($from, $site, $modules, $mode, $all_bins, $now);
    if (installapp_show_preview($from, $prepared, $mode, $all_bins)) {
        $run = InstallRun::preview($mode, $all_bins, InstallRun::LOCAL, $uid, '', $now, hash('sha256', $prepared['xml']));
        installapp_write_form($run, $site, $modules, $prepared['plan'], $uid);
    }
}

/** the preview of an app of another AA: the run of the login takes its fingerprint
 *  @param list<string> $modules */
function installapp_remote_preview(string $run_id, string $site, array $modules, string $uid): void
{
    $run = InstallRun::get($run_id);
    if (($run === null) || ($run->user() !== $uid) || ($run->state() !== InstallRun::PREVIEW) || ($run->source() === InstallRun::LOCAL)) {
        echo '<div class="aa-err">' . _m('This preview is no longer open - it was written already or forgotten.') . '</div>';
        return;
    }
    if (($site === '') === !$modules) {
        echo '<div class="aa-err">' . _m('Choose a site, or slices and Alerts modules - not both.') . '</div>';
        installapp_choose($run);
        return;
    }
    try {
        $from = installapp_remote_source($run);
    } catch (SourceFailed $e) {
        echo '<div class="aa-err">' . safe($e->getMessage()) . '</div>';
        return;
    }
    $prepared = installapp_prepare($from, $site, $modules, $run->mode(), $run->allBins(), $run->started());
    if (!installapp_show_preview($from, $prepared, $run->mode(), $run->allBins())) {
        echo '<p>' . ($prepared['login'] ? installapp_relogin($run) : a_href(installapp_url(['run' => $run_id]), _m('Choose another app'))) . '</p>';
        return;
    }
    try {
        DB_AA::transaction(static fn() => (InstallRun::lockForUpdate($run_id) ?? throw new LogicException('The run is gone'))->previewed(hash('sha256', $prepared['xml']), $prepared['now'], $prepared['same']));
    } catch (LogicException) {              // another tab wrote it meanwhile
        echo '<div class="aa-err">' . _m('The run has moved on meanwhile.') . ' ' . a_href(installapp_url(['run' => $run_id]), _m('Show the run')) . '</div>';
        return;
    }
    installapp_write_form($run, $site, $modules, $prepared['plan'], $uid);
}

/** the preview of installapp_prepare()
 *  @return bool the app may be written */
function installapp_show_preview(AppSource $from, array $prepared, string $mode, bool $all_bins): bool
{
    if ($prepared['error'] !== '') {
        echo '<div class="aa-err">' . safe($prepared['error']) . '</div>';
        return false;
    }
    $plan = $prepared['plan'];
    if ($plan) {
        $preview = $plan->preview();
        $slices  = array_column(array_filter($preview['modules'], static fn(array $m): bool => $m['type'] === Module::SLICE), 'old');
        $counts  = ($mode === InstallRun::TRANSFER) ? installapp_counts($from, $slices, $all_bins) : [];
        echo '<h2>' . safe($preview['name']) . ' - ' . safe(($mode === InstallRun::TRANSFER) ? _m('transfer with the items') : _m('install, no items')) . '</h2>';
        echo '<table class="aa-installapp-findings"><tr><th>' . _m('New module') . '</th><th>' . _m('Type') . '</th><th>' . _m('Source id') . '</th>'
            . (($mode === InstallRun::TRANSFER) ? '<th class="aa-num">' . _m('Items') . '</th>' : '') . '</tr>';
        foreach ($preview['modules'] as $m) {
            echo '<tr><td>' . safe($m['name']) . '</td><td>' . safe($m['type']) . '</td><td><code>' . safe($m['old']) . '</code></td>'
                . (($mode === InstallRun::TRANSFER) ? '<td class="aa-num">' . (($m['type'] === Module::SLICE) ? (isset($counts[$m['old']]) ? (int) $counts[$m['old']] : '?') : '') . '</td>' : '') . '</tr>';
        }
        echo '</table>';
    }
    installapp_findings($prepared['findings']);
    if (!installapp_importable($prepared)) {
        echo '<div class="aa-err">' . _m('The app cannot be installed - see the errors (E) above.') . '</div>';
        return false;
    }
    return true;
}

/** @param list<string> $modules */
function installapp_write_form(InstallRun $run, string $site, array $modules, ImportPlan $plan, string $uid): void
{
    // one write per preview: the second click of a double click would only fail
    echo '<form method="post" action="' . safe(installapp_url()) . '" onsubmit="this.querySelector(\'button[type=submit]\').disabled = true">' . installapp_hidden('write', $site, $modules, $run->getId());
    if ($plan->preview()['confirm']) {
        echo '<p><label><input type="checkbox" name="confirm" value="' . safe((string) (new ImportConfirmation())->token($plan, $uid)) . '" required> '
            . _m('I have read the security findings (S) and want to install the app with them.') . '</label></p>';
    }
    if ($run->mode() === InstallRun::TRANSFER) {
        echo '<div class="aa-info">' . _m('Do not edit the source while the transfer runs.') . '</div>';
    }
    echo '<p><button class="btn-l" type="submit">' . (($run->mode() === InstallRun::TRANSFER) ? _m('Write the definitions and transfer the items') : _m('Install')) . '</button></p></form>';
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
    try {
        $prepared = installapp_prepare(installapp_run_source($run), $site, $modules, $run->mode(), $run->allBins(), $run->started());
    } catch (SourceFailed $e) {
        $prepared = ['error' => $e->getMessage(), 'login' => false, 'xml' => '', 'same' => false];
    }
    if ($prepared['error'] !== '') {
        echo '<div class="aa-err">' . safe($prepared['error']) . ($prepared['login'] ? ' ' . installapp_relogin($run) : '') . '</div>';
        return;
    }
    if (!$run->matchesPreview($prepared['xml'], $prepared['same'])) {
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
    if (($run->state() === InstallRun::PREVIEW) && ($run->source() !== InstallRun::LOCAL)) {
        installapp_choose($run);           // logged in to the other AA, the app not chosen or not written yet
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
    $written = $run->written();
    if ($run->state() === InstallRun::DONE) {
        installapp_check($run);
        return;
    }
    try {
        $counts = installapp_counts(installapp_run_source($run), $run->slices(), $run->allBins());
    } catch (SourceFailed $e) {
        $counts = [];
    }
    $remote = ($run->source() !== InstallRun::LOCAL);
    [$slice, $cursor] = $run->position();
    $states           = [InstallRun::ITEMS, InstallRun::ERROR, InstallRun::DONE, InstallRun::ENDED];
    echo '<div class="aa-info">' . _m('Do not edit the source while the transfer runs.') . '</div>';
    echo '<div id="installapp-progress" data-url="' . safe(installapp_url()) . '" data-report="' . safe(installapp_url(['run' => $run_id])) . '" data-token="' . safe(FormToken::get(INSTALLAPP_TOKEN)) . '"'
        . ' data-run="' . safe($run_id) . '" data-state="' . safe($run->state()) . '" data-slice="' . safe($slice) . '" data-cursor="' . safe($cursor) . '"'
        . ' data-states="' . safe(json_encode(array_combine($states, array_map('installapp_state', $states)))) . '"'
        . ' data-confirm-end="' . safe(_m('End the transfer? The items written so far stay.')) . '" data-login="' . safe(_m('The session has expired - log in again and reload this page.')) . '"'
        . ' data-report-link="' . safe(_m('The report')) . '" data-error-code="' . safe($run->error()[0] ?? '') . '"' . ($start ? ' data-start="1"' : '') . '>';
    echo '<table class="aa-installapp-findings"><tr><th>' . _m('Slice') . '</th><th class="aa-num">' . _m('Written') . '</th><th class="aa-num">' . _m('On the source') . '</th></tr>';
    foreach ($run->slices() as $id) {
        echo '<tr><td>' . safe(AA_Module::getModuleName($remote ? ($run->idMap()->module($id) ?? '') : $id) ?: $id) . '</td><td class="aa-num" data-slice="' . safe($id) . '">' . (int) ($written[$id] ?? 0) . '</td><td class="aa-num">' . (isset($counts[$id]) ? (int) $counts[$id] : '?') . '</td></tr>';
    }
    $error = ($run->state() === InstallRun::ERROR) ? implode(' ', $run->error() ?? []) : '';
    echo '</table><p><strong data-role="state">' . safe(installapp_state($run->state())) . '</strong> <span data-role="error">' . safe($error) . '</span></p><ul data-role="findings"></ul>';
    if ($remote) {        // the source refused its token (it expired): Continue logs in again
        echo '<fieldset data-role="login" hidden><legend>' . _m('Log in to the source AA again') . '</legend><table>' . installapp_login_rows() . '</table></fieldset>';
    }
    echo '<p><button type="button" class="btn-l" data-role="continue" hidden>' . _m('Continue') . '</button> <button type="button" class="btn-l btn-secondary" data-role="end">' . _m('End') . '</button></p></div>';
    installapp_script();
}

/** the report of a finished transfer, as its finish stored it */
function installapp_check(InstallRun $run): void
{
    $check = $run->report() + ['checked' => 0, 'slices' => [], 'findings' => []];
    echo '<div class="aa-ok">' . _m('The transfer is finished. Checked at %1:', [date('Y-m-d H:i', (int) $check['checked'])]) . '</div><table class="aa-installapp-findings"><tr><th>' . _m('Slice') . '</th><th class="aa-num">' . _m('Written') . '</th><th class="aa-num">' . _m('On the source') . '</th><th class="aa-num">' . _m('In the new slice') . '</th></tr>';
    foreach ($check['slices'] as $s) {
        echo '<tr><td>' . a_href(AA_Module::getModuleUrl($s['target'], 'admin/index.php'), safe(AA_Module::getModuleName($s['target']) ?: $s['target'])) . '</td><td class="aa-num">' . $s['written'] . '</td><td class="aa-num">' . (($s['sourceCount'] === null) ? '?' : (int) $s['sourceCount']) . '</td><td class="aa-num">' . $s['targetCount'] . '</td></tr>';
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

    const login = el('login');
    const showLogin = (st, code) => {
        if (login) login.hidden = !(st === 'error' && code === 'SOURCE_LOGIN');
    };
    const post = async action => {
        const body = new URLSearchParams({action, run: box.dataset.run, token: box.dataset.token, slice: pos.slice, cursor: pos.cursor});
        if (action === 'continue' && login && !login.hidden) {
            for (const input of login.querySelectorAll('input')) {
                body.set(input.name, input.value);
                if (input.type === 'password') input.value = '';
            }
        }
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
        showLogin(s.state, s.error?.code);
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
    showLogin(state, box.dataset.errorCode);
    if (state === 'items' && box.dataset.start) loop();
})();
</script>
HTML;
}
