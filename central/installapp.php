<?php

/**
 * Install an app of this AA or of another one (aa=<central_conf id>, or the app store - appstore.php opens
 * its runs): a site with its modules, or slices
 * and Alerts modules, as a clean app (install) or with their items (transfer). One path for both: the
 * source makes XML (AppExport here, Get_App there), this AA reads it (Reader -> Validator -> Planner ->
 * Importer). A transfer then moves the items in batches, one request each, driven by the progress page -
 * each batch as XML too (TransferProgress). A transfer carries password hashes and 2FA secrets:
 * superadmins only, on both AAs. Another AA gives its app only to its superadmin: the admin logs in to
 * it here, this AA keeps the short token it gets only in the run (encrypted), never the password.
 *
 * GET               the form (aa=<id>: the login to another AA), or with run=<id> the progress / report of a run
 * POST connect      logs in to another AA: the run opens in preview with its token, the choice of the app follows
 * POST preview      the preview; creates the InstallRun (this AA) or fills the one of the login. Each slice of the
 *                   app goes into a new slice or an existing one of this AA (into[<source id>]); the preview
 *                   offers the choice and is sent again with it. The first preview chooses by the role of the
 *                   slice (SliceRoles)
 * POST write        writes the definitions; an install gives its new modules the owner its form chose (owner)
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
use AA\Central\AppStore;
use AA\Central\ExportRefused;
use AA\Central\FindingText;
use AA\Central\InstallRun;
use AA\Central\ItemIdCheck;
use AA\Central\Package\AaAfterCommit;
use AA\Central\Package\DbImportTarget;
use AA\Central\Package\DbSource;
use AA\Central\Package\ExistingSlices;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\ImportReport;
use AA\Central\Package\ItemLint;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Planner;
use AA\Central\Package\Reader;
use AA\Central\Package\SliceRoles;
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

const INSTALLAPP_TOKEN = InstallRun::FORM_TOKEN;
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
    $into     = InstallRun::targetsFrom(InputVars::arrayPOST('into'));
    $choice   = InstallRun::choiceMade(InputVars::arrayPOST('into'));
    $choices  = ImportChoices::fromPost($_POST);             // names, slices left out, owner, settings - the planner checks them
    $sum      = InputVars::stringPOST('choices_sum');
    // the choice between a site and modules: the other one is still in the form, only not shown
    match (InputVars::stringPOST('what')) {
        'site'    => $modules = [],
        'modules' => $site = '',
        default   => null,
    };
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
                $remote  = RemoteSource::forSource($run->source());
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
    .aa-installapp-findings small code { color: var(--clr-muted, #777); }
    .aa-installapp-code { margin: 0.5em 0 1em; max-width: 60em; }
    .aa-installapp-code h4 { margin: 0.8em 0 0.2em; }
    .aa-installapp-code p { margin: 0.2em 0; }
    .aa-installapp fieldset { border: 0; padding: 0; margin: 0 0 1em; }
    .aa-installapp h3 { margin: 1.2em 0 0.3em; }
    .aa-installapp-plan input[type="text"] { width: 100%; max-width: 30em; box-sizing: border-box; }
    .aa-installapp-skipped td { color: var(--clr-muted, #777); }
    .aa-installapp-skipped input[type="text"] { text-decoration: line-through; }
    .aa-installapp-settings th.aa-installapp-group { padding-top: 0.8em; }
    .aa-installapp-settings td:first-child { max-width: 24em; }
    .aa-installapp-owner { display: flex; flex-wrap: wrap; gap: 0.5em 1em; align-items: baseline; }
    .aa-installapp-owner details { display: inline-block; }
    .aa-installapp-owner details[open] label { display: inline-block; margin: 0.3em 1em 0 0; }
    .aa-installapp [hidden] { display: none; }
    .aa-installapp td.tabtxt { width: 11em; }
    .aa-installapp-what { display: inline-flex; border: 1px solid var(--clr-basic-darker, #A8977A); border-radius: 4px; overflow: hidden; background: #fff; }
    .aa-installapp-what label { position: relative; padding: 0.35em 1em; cursor: pointer; }
    .aa-installapp-what label + label { border-left: 1px solid var(--clr-basic-darker, #A8977A); }
    .aa-installapp-what label:hover { background: var(--clr-surface-selected, #f3e6c3); }
    .aa-installapp-what input { position: absolute; opacity: 0; }
    .aa-installapp-what label:has(:checked) { background: var(--clr-dark, #1f1f1f); color: #fff; }
    .aa-installapp-what label:has(:focus-visible) { outline: 2px solid var(--clr-border-hover, #a59850); outline-offset: -3px; }
    form:has([name="what"][value="site"]:checked) .aa-installapp-modules,
    form:has([name="what"][value="modules"]:checked) .aa-installapp-site,
    form:has([name="mode"][value="install"]:checked) .aa-installapp-bins { display: none; }
', 'AA_Req_CSScode');
$apage->printHead();
echo '<div class="aa-installapp">';

MoreTimeLimit();
switch ($action) {
    case 'connect':
        installapp_connect($aa, $mode, $all_bins, $login, $uid);
        break;
    case 'preview':
        ($run_id !== '') ? installapp_remote_preview($run_id, $site, $modules, $into, !$choice, $choices, $uid) : installapp_preview($site, $modules, $mode, $all_bins, $into, !$choice, $choices, $uid);
        break;
    case 'write':
        installapp_write($run_id, $site, $modules, $into, $sum, ($confirm === '') ? null : $confirm, $uid);
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

/** the AA of the run's central_conf row or the app store, with the token the run keeps sealed (the store
 *  logs in again by itself)
 *  @throws SourceFailed no such row, the store refused its login */
function installapp_remote_source(InstallRun $run): RemoteAppSource
{
    try {
        $sealed = $run->token();
    } catch (RuntimeException) {            // AA_SECRET changed: the source refuses the empty token, Continue logs in again
        $sealed = '';
    }
    $remote = RemoteSource::forSource($run->source());
    return ($run->source() === InstallRun::STORE) ? AppStore::source($remote, $remote->unseal($sealed)) : new RemoteAppSource($remote, $remote->unseal($sealed));
}

/** the source of a run; no run - this AA (the progress requests then refuse it anyway)
 *  @throws SourceFailed */
function installapp_run_source(?InstallRun $run): AppSource
{
    return (($run === null) || ($run->source() === InstallRun::LOCAL)) ? installapp_local_source() : installapp_remote_source($run);
}

/** the link back to the login to the source AA of the run; the store logs in by itself */
function installapp_relogin(InstallRun $run): string
{
    return ($run->source() === InstallRun::STORE)
        ? a_href(StateUrl(get_url(self_base() . 'appstore.php')), _m('Open the app store again'))
        : a_href(installapp_url(['aa' => $run->source()]), _m('Log in to the source AA again'));
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

/** a site, or slices and Alerts modules - a switch shows one of them, the request drops the other (`what`)
 *  @param array<string, string> $sites id => name @param array<string, string> $others slices and Alerts, id => name */
function installapp_module_rows(array $sites, array $others): void
{
    if ($sites && $others) {
        echo '<tr><td class="tabtxt">' . _m('Install') . '</td><td><div class="aa-installapp-what" role="radiogroup" aria-label="' . safe(_m('Install')) . '">'
            . '<label><input type="radio" name="what" value="site" checked> ' . _m('a site') . '</label>'
            . '<label><input type="radio" name="what" value="modules"> ' . _m('slices and Alerts') . '</label></div></td></tr>';
    }
    if ($sites) {
        echo '<tr class="aa-installapp-site"><td class="tabtxt"><label for="installapp-site">' . _m('Site') . '</label></td><td><select name="site" id="installapp-site">';
        foreach ($sites as $id => $name) {
            echo '<option value="' . safe((string) $id) . '">' . safe((string) $name) . '</option>';
        }
        echo '</select><div class="tabhlp">' . _m('The site brings the slices and Alerts modules it uses.') . '</div></td></tr>';
    }
    if ($others) {
        echo '<tr class="aa-installapp-modules"><td class="tabtxt"><label for="installapp-modules">' . _m('Slices and Alerts') . '</label></td><td><select name="modules[]" id="installapp-modules" multiple size="10">';
        foreach ($others as $id => $name) {
            echo '<option value="' . safe((string) $id) . '">' . safe((string) $name) . '</option>';
        }
        echo '</select><div class="tabhlp">' . _m('Hold Ctrl to choose more of them.') . '</div></td></tr>';
    }
}

/** a transfer is a superadmin's (it hands out the readers' passwords) - others install without asking */
function installapp_mode_rows(): void
{
    if (!IsSuperadmin()) {
        return;
    }
    echo '<tr><td class="tabtxt">' . _m('Mode') . '</td><td><fieldset>'
        . '<label><input type="radio" name="mode" value="install" checked> ' . _m('Install - a clean app, no items') . '</label><br>'
        . '<label><input type="radio" name="mode" value="transfer"> ' . _m('Transfer - a copy with the items and readers') . '</label></fieldset></td></tr>';
    echo '<tr class="aa-installapp-bins"><td class="tabtxt">' . _m('Items of') . '</td><td><fieldset>'
        . '<label><input type="radio" name="bins" value="active" checked> ' . _m('the active bin') . '</label><br>'
        . '<label><input type="radio" name="bins" value="all"> ' . _m('all bins') . '</label></fieldset></td></tr>';
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

/** the choice of the app of a run opened at the login to another AA; the store lists its apps itself */
function installapp_choose(InstallRun $run): void
{
    $store = a_href(StateUrl(get_url(self_base() . 'appstore.php', ['run' => $run->getId()])), _m('Choose the app from the list of its apps'));
    if ($run->source() === InstallRun::STORE) {
        echo "<p>$store</p>";
        return;
    }
    try {
        $from   = installapp_remote_source($run);
        $sites  = $from->modules(['W']);
        $others = $from->modules(['S', 'Alerts']);
    } catch (SourceFailed $e) {         // the app store account of a Central row lists no modules
        echo '<div class="aa-err">' . safe($e->getMessage()) . ' ' . installapp_relogin($run) . "</div><p>$store</p>";
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

/** the slices of this AA a slice of the package may go into: those the user may edit @return array<string, string> id => name */
function installapp_slice_choices(): array
{
    $target = new DbImportTarget();
    return array_filter(AA_Module::getModules('S', true), static fn($name, $id): bool => $target->mayEditSlice((string) $id), ARRAY_FILTER_USE_BOTH);
}

/** Reads the app from its source and plans it on this AA.
 *  @param list<string>          $modules
 *  @param array<string, string> $into    source slice => slice of this AA it goes into; the others get new slices
 *  @param bool                  $preselect no choice made yet: SliceRoles makes it
 *  @param ImportChoices|null    $choices names, slices left out, the owner ('' = Planner::defaultOwner()), settings values
 *  @return array{error: string, login: bool, xml: string, now: int, same: bool, findings: list<array{string, LintFinding}>, plan: ?ImportPlan,
 *                into: array<string, string>, roles: array<string, string>, package: ?\AA\Central\Package\Model\Package,
 *                all: ?\AA\Central\Package\Model\Package, choices: ImportChoices}
 *          login: the source refused its token; now: the time of the source; same: the source is this AA;
 *          roles: source slice => the role its target was preselected by; choices: as planned, the default owner filled in;
 *          all: the app as the source gave it, package: as chosen (names, slices left out) */
function installapp_prepare(AppSource $from, string $site, array $modules, string $mode, bool $all_bins, int $started, array $into = [], bool $preselect = false, ?ImportChoices $choices = null): array
{
    $choices ??= new ImportChoices();
    $out      = ['error' => '', 'login' => false, 'xml' => '', 'now' => 0, 'same' => false, 'findings' => [], 'plan' => null, 'into' => [], 'roles' => [], 'package' => null, 'all' => null, 'choices' => $choices];
    $transfer = ($mode === InstallRun::TRANSFER);
    try {
        [$out['xml'], $out['findings'], $out['now']] = $from->definitions(($site !== '') ? [$site] : $modules, $transfer, $all_bins, $started);
        $out['same']     = $from->sameAa();
        $read            = (new Reader())->read($out['xml']);
        $out['findings'] = [...$out['findings'], ...$read->findings];
        $out['all']      = $read->package;
        $planner         = new Planner(new DbImportTarget());
        // the names and the slices left out come first: the check and the plan see the app as it will be
        [$package, $chosen] = $planner->choose($read->package, $choices);
        $skipped         = array_map(static fn(string $id): string => Module::SLICE . "[$id]", $choices->skip);
        $out['findings'] = [...array_values(array_filter($out['findings'], static fn(array $f): bool => !installapp_under($f[0], $skipped))), ...$chosen];
        $readers         = [];
        foreach ($package->modules as $m) {
            if (($m->type === Module::SLICE) && (ValueCodec::bytes($m->rows['slice'][0]['type'] ?? '') === ItemLint::READER_SLICE)) {
                $readers[] = $m->id;
            }
        }
        $logins     = ($transfer && $readers) ? $from->logins($readers, $all_bins) : [];
        $validation = (new Validator())->validate($package, $transfer);
        $out['package'] = $package;
        $out['into']    = $into;
        if (!$validation->importable()) {
            $out['findings'] = [...$out['findings'], ...$validation->findings];
            return $out;
        }
        $owner   = ($transfer || ($choices->newOwner !== null)) ? '' : ($choices->owner ?: $planner->defaultOwner());
        $out['choices'] = new ImportChoices($choices->names, $choices->skip, $owner, $transfer ? null : $choices->newOwner, $choices->settings);
        $plan    = static fn(array $targets): ImportPlan => $planner->plan($package, $validation, $transfer, $logins, $targets, $out['same'], $owner, $out['choices']);
        if ($preselect) {           // stored with the preview as any choice - the write writes what it showed
            [$out['plan'], $out['into'], $preselected, $out['roles']] = SliceRoles::plan($package, new DbImportTarget(), $out['same'], $transfer, $plan);
            $out['findings'] = [...$out['findings'], ...$preselected];
        } else {
            $out['plan'] = $plan($into);
        }
        $out['findings'] = [...$out['findings'], ...$out['plan']->allFindings()];
        if ($transfer && !$out['same']) {        // another AA keeps the item ids: none may be here already
            $slices          = array_values(array_map(static fn(Module $m): string => $m->id, array_filter($package->modules, static fn(Module $m): bool => $m->type === Module::SLICE)));
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

/** the path is of one of the modules (their paths, `slice-module[<id>]`), any case @param list<string> $modules */
function installapp_under(string $path, array $modules): bool
{
    foreach ($modules as $module) {
        if (strncasecmp($path, $module, strlen($module)) === 0) {
            return true;
        }
    }
    return false;
}

/** no E anywhere - the definitions may be written */
function installapp_importable(array $prepared): bool
{
    return ($prepared['error'] === '') && $prepared['plan'] && !array_filter($prepared['findings'], static fn(array $f): bool => $f[1]->level === LintFinding::ERROR);
}

/** the preview of an app of this AA: the run is created with it
 *  @param list<string> $modules @param array<string, string> $into */
function installapp_preview(string $site, array $modules, string $mode, bool $all_bins, array $into, bool $preselect, ImportChoices $choices, string $uid): void
{
    if (($site === '') === !$modules) {
        echo '<div class="aa-err">' . _m('Choose a site, or slices and Alerts modules - not both.') . '</div>';
        installapp_form(0, $uid);
        return;
    }
    $now      = time();
    $from     = installapp_local_source();
    $prepared = installapp_prepare($from, $site, $modules, $mode, $all_bins, $now, $into, $preselect, $choices);
    if (installapp_show_preview($from, $prepared, $mode, $all_bins, installapp_hidden('preview', $site, $modules) . installapp_mode_hidden($mode, $all_bins))) {
        $run = InstallRun::preview($mode, $all_bins, InstallRun::LOCAL, $uid, '', $now, hash('sha256', $prepared['xml']), $prepared['into'], $prepared['choices']);
        installapp_write_form($run, $site, $modules, $prepared['plan'], $uid);
    }
}

/** the preview of an app of another AA: the run of the login takes its fingerprint
 *  @param list<string> $modules @param array<string, string> $into */
function installapp_remote_preview(string $run_id, string $site, array $modules, array $into, bool $preselect, ImportChoices $choices, string $uid): void
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
    $prepared = installapp_prepare($from, $site, $modules, $run->mode(), $run->allBins(), $run->started(), $into, $preselect, $choices);
    if (!installapp_show_preview($from, $prepared, $run->mode(), $run->allBins(), installapp_hidden('preview', $site, $modules, $run_id))) {
        echo '<p>' . ($prepared['login'] ? installapp_relogin($run) : a_href(installapp_url(['run' => $run_id]), _m('Choose another app'))) . '</p>';
        return;
    }
    try {
        DB_AA::transaction(static fn() => (InstallRun::lockForUpdate($run_id) ?? throw new LogicException('The run is gone'))->previewed(hash('sha256', $prepared['xml']), $prepared['now'], $prepared['same'], $prepared['into'], $prepared['choices']));
    } catch (LogicException) {              // another tab wrote it meanwhile
        echo '<div class="aa-err">' . _m('The run has moved on meanwhile.') . ' ' . a_href(installapp_url(['run' => $run_id]), _m('Show the run')) . '</div>';
        return;
    }
    installapp_write_form(InstallRun::get($run_id) ?? $run, $site, $modules, $prepared['plan'], $uid);       // as previewed() left it: its targets
}

/** the hidden mode fields of the preview of this AA sent again */
function installapp_mode_hidden(string $mode, bool $all_bins): string
{
    return '<input type="hidden" name="mode" value="' . safe($mode) . '"><input type="hidden" name="bins" value="' . ($all_bins ? 'all' : 'active') . '">';
}

/** the preview of installapp_prepare(); each slice goes into a new slice or an existing one - the choice
 *  sends the preview again with $again (the hidden fields of the same selection)
 *  @return bool the app may be written */
function installapp_show_preview(AppSource $from, array $prepared, string $mode, bool $all_bins, string $again): bool
{
    if ($prepared['error'] !== '') {
        echo '<div class="aa-err">' . safe($prepared['error']) . '</div>';
        return false;
    }
    $plan = $prepared['plan'];
    if ($plan) {
        installapp_choices_form($from, $prepared, $plan, $mode, $all_bins, $again);
    }
    installapp_findings($prepared['findings'], $prepared['package']);
    if (!installapp_importable($prepared)) {
        echo '<div class="aa-err">' . _m('The app cannot be installed - see the errors (E) above.') . '</div>';
        return false;
    }
    return true;
}

/** the choice of the slice a package slice goes into: a new one, or one of $choices (id => name) */
function installapp_into_select(string $old, string $chosen, array $choices): string
{
    $html = '<select name="into[' . safe($old) . ']" aria-label="' . safe(_m('Into')) . '"><option value="">' . safe(_m('a new slice')) . '</option>';
    foreach ($choices as $id => $name) {
        $html .= '<option value="' . safe((string) $id) . '"' . (((string) $id === $chosen) ? ' selected' : '') . '>' . safe(_m('existing: %1', [ExistingSlices::choiceLabel((string) $name, (string) $id)])) . '</option>';
    }
    return $html . '</select>';
}

/** why the preview chose the slice: its role; a Reader Management slice warns - the app works on its live readers */
function installapp_role_note(?string $role): string
{
    if ($role === null) {
        return '';
    }
    $note = _m('chosen by its role: %1', [AA_Slice::getSliceTypes()[$role] ?? $role]);
    return ($role === ItemLint::READER_SLICE)
        ? '<div class="aa-info">W: ' . safe($note . ' - ' . _m('the views and fields of the app will work on its live reader data')) . '</div>'
        : '<div class="tabhlp">' . safe($note) . '</div>';
}

/** the form of the choices of the preview: what is imported and under which name, into which slice, the
 *  owner of the new modules, the values of the slice settings; sent again it is checked by a new preview -
 *  the write takes only what a preview checked (the run keeps it)
 *  @param array<string, mixed> $prepared installapp_prepare() */
function installapp_choices_form(AppSource $from, array $prepared, ImportPlan $plan, string $mode, bool $all_bins, string $again): void
{
    $preview  = $plan->preview();
    $choices  = $prepared['choices'];
    $transfer = ($mode === InstallRun::TRANSFER);
    $planned  = array_column($preview['modules'], null, 'old');
    $slices   = array_values(array_map(static fn(Module $m): string => $m->id, array_filter($prepared['all']->modules, static fn(Module $m): bool => $m->type === Module::SLICE)));
    $counts   = $transfer ? installapp_counts($from, array_values(array_intersect($slices, array_keys($planned))), $all_bins) : [];
    $into     = $slices ? installapp_slice_choices() : [];
    $types    = [Module::SITE => _m('Site'), Module::SLICE => _m('Slice'), Module::ALERTS => _m('Alerts')];

    echo '<h2>' . safe($preview['name']) . ' - ' . safe($transfer ? _m('transfer with the items') : _m('install, no items')) . '</h2>';
    echo '<form method="post" action="' . safe(installapp_url()) . '" class="aa-installapp-choices" data-role="installapp-choices">' . $again;
    echo '<h3>' . _m('What to install') . '</h3><table class="aa-installapp-findings aa-installapp-plan"><tr><th>' . _m('Install') . '</th><th>' . _m('Name') . '</th><th>' . _m('Type') . '</th><th>' . _m('Into') . '</th>'
        . ($transfer ? '<th class="aa-num">' . _m('Items') . '</th>' : '') . '</tr>';
    foreach ($prepared['all']->modules as $m) {
        $old     = strtolower($m->id);
        $skipped = !isset($planned[$m->id]);
        $name    = $choices->names[$old] ?? ValueCodec::bytes($m->rows['module'][0]['name'] ?? '');
        $keep    = ($m->type === Module::SLICE)       // unchecked, the hidden field says "left out"
            ? '<input type="hidden" name="skip[' . safe($old) . ']" value="1"><input type="checkbox" name="skip[' . safe($old) . ']" value=""' . ($skipped ? '' : ' checked') . ' aria-label="' . safe(_m('Install %1', [$name])) . '">'
            : '<input type="checkbox" checked disabled aria-label="' . safe(_m('Install %1', [$name])) . '">';
        echo '<tr' . ($skipped ? ' class="aa-installapp-skipped"' : '') . '><td>' . $keep . '</td>'
            . '<td><input type="text" name="name[' . safe($old) . ']" value="' . safe($name) . '" maxlength="100" size="40" aria-label="' . safe(_m('Name')) . '"'
            . ($skipped ? ' disabled' : '') . '><br><small><code>' . safe($old) . '</code></small></td>'
            . '<td>' . safe($types[$m->type] ?? $m->type) . '</td><td>'
            . match (true) {
                $skipped                                => safe(_m('not installed')),
                ($m->type === Module::SLICE) && $into   => installapp_into_select($m->id, $prepared['into'][$old] ?? '', $into) . installapp_role_note($prepared['roles'][$old] ?? null),
                default                                 => safe(_m('a new module')),
            } . '</td>'
            . ($transfer ? '<td class="aa-num">' . ((($m->type === Module::SLICE) && !$skipped) ? (isset($counts[$m->id]) ? (int) $counts[$m->id] : '?') : '') . '</td>' : '') . '</tr>';
    }
    echo '</table><p class="tabhlp">' . _m('A slice you leave out is not installed; what points to it in the rest of the app is listed in the warnings below. A slice going into an existing slice keeps the name of that slice.') . '</p>';
    if (!$transfer && array_diff_key($plan->map->modules(), $plan->existing)) {
        installapp_owner_rows($plan->owner, $choices->newOwner, (bool) $plan->existing);
    }
    installapp_setting_rows($plan, $choices);
    echo '<p><button class="btn" type="submit">' . _m('Check the changes') . '</button> <span class="tabhlp">' . _m('After a change, check it before installing.') . '</span></p></form>';
    // the write takes what this preview checked - after a change the Install button waits for the check
    echo '<script>document.querySelector(\'[data-role="installapp-choices"]\').addEventListener(\'input\', () => {'
        . ' const b = document.querySelector(\'[data-role="installapp-write"]\'); if (b && !b.disabled) { b.disabled = true; b.textContent = '
        . json_encode(_m('Check the changes first'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '; } });</script>';
}

/** the owner of the new modules of an install: one of this AA's, or a new one (name and e-mail) the
 *  install creates @param array{name: string, email: string}|null $new @param bool $existing some slice goes into an existing one */
function installapp_owner_rows(string $chosen, ?array $new, bool $existing): void
{
    $owners = (new DbImportTarget())->owners();
    echo '<h3>' . _m('Owner of the new modules') . '</h3><div class="aa-installapp-owner">';
    if ($owners) {
        echo '<select name="owner" aria-label="' . safe(_m('Owner of the new modules')) . '"' . ($new ? ' disabled' : '') . '>';
        foreach ($owners as $id => $name) {
            echo '<option value="' . safe((string) $id) . '"' . (((string) $id === $chosen) ? ' selected' : '') . '>' . safe((string) $name) . '</option>';
        }
        echo '</select> ';
    }
    echo '<details' . (($new || !$owners) ? ' open' : '') . '><summary>' . _m('+ Add new owner') . '</summary>'
        . '<label>' . _m('New Owner') . ' <input type="text" name="new_owner" value="' . safe($new['name'] ?? '') . '" maxlength="255"></label> '
        . '<label>' . _m("New Owner's E-mail") . ' <input type="email" name="new_owner_email" value="' . safe($new['email'] ?? '') . '" maxlength="255"></label></details></div>';
    if ($existing) {
        echo '<p class="tabhlp">' . _m('A slice going into an existing slice keeps the owner of that slice.') . '</p>';
    }
    // a new owner wins over the list - the list is off while the new owner is being written
    echo '<script>(() => { const d = document.querySelector(\'.aa-installapp-owner details\'), s = document.querySelector(\'.aa-installapp-owner select\');'
        . ' if (d && s) { d.addEventListener(\'toggle\', () => { s.disabled = d.open; if (!d.open) { d.querySelectorAll(\'input\').forEach(i => { i.value = \'\'; }); } }); } })();</script>';
}

/** the settings fields of the slices the app writes: what the app brings, and a value to write instead */
function installapp_setting_rows(ImportPlan $plan, ImportChoices $choices): void
{
    $html = '';
    foreach ($plan->package->modules as $m) {
        $fields = array_filter($m->rows['field'] ?? [], static fn(array $row): bool => str_starts_with(ValueCodec::bytes($row['id'] ?? ''), '_'));
        if (($m->type !== Module::SLICE) || !$fields) {
            continue;
        }
        $old   = strtolower($m->id);
        $html .= '<tr><th colspan="3" class="aa-installapp-group">' . safe($plan->moduleName($m)) . '</th></tr>';
        foreach ($fields as $row) {
            $id    = ValueCodec::bytes($row['id'] ?? '');
            $app   = $plan->settingsOfApp[$m->id][$id][0]['value'] ?? $m->transfer['setting-values'][$id][0]['value'] ?? '';
            $app   = is_string($app) ? $app : ValueCodec::bytes($app);
            $help  = ValueCodec::bytes($row['input_help'] ?? '');
            $html .= '<tr><td><label for="installapp-set-' . safe($old . $id) . '">' . safe(ValueCodec::bytes($row['name'] ?? '') ?: $id) . '</label>'
                . (((int) ValueCodec::bytes($row['required'] ?? '0')) ? ' <span class="aa-required">*</span>' : '') . '<br><small><code>' . safe($id) . '</code></small>'
                . (($help !== '') ? '<div class="tabhlp">' . safe($help) . '</div>' : '') . '</td>'
                . '<td>' . (($app === '') ? '<span class="tabhlp">' . _m('(empty)') . '</span>' : '<code>' . safe(mb_strimwidth($app, 0, 200, '…')) . '</code>') . '</td>'
                . '<td><input type="text" name="setting[' . safe($old) . '][' . safe($id) . ']" id="installapp-set-' . safe($old . $id) . '" value="' . safe($choices->settings[$old][$id] ?? '') . '" size="30"'
                . ' placeholder="' . safe(_m('keep the value of the app')) . '"></td></tr>';
        }
    }
    if ($html !== '') {
        echo '<h3>' . _m('Slice settings') . '</h3><p class="tabhlp">' . _m('Values that hold for the whole slice, not for single items. Leave a field empty to keep the value of the app; you can change them later in the slice under Setting.') . '</p>'
            . '<table class="aa-installapp-findings aa-installapp-settings"><tr><th>' . _m('Setting') . '</th><th>' . _m('Value of the app') . '</th><th>' . _m('New value') . '</th></tr>' . $html . '</table>';
    }
}

/** @param list<string> $modules */
function installapp_write_form(InstallRun $run, string $site, array $modules, ImportPlan $plan, string $uid): void
{
    // one write per preview: the second click of a double click would only fail
    echo '<form method="post" action="' . safe(installapp_url()) . '" onsubmit="this.querySelector(\'button[type=submit]\').disabled = true">' . installapp_hidden('write', $site, $modules, $run->getId())
        . '<input type="hidden" name="choices_sum" value="' . safe($run->choicesSum()) . '">';
    foreach ($run->targets() as $old => $new) {         // compared with the run: a form of an older preview is refused
        echo '<input type="hidden" name="into[' . safe($old) . ']" value="' . safe($new) . '">';
    }
    if ($plan->preview()['confirm']) {
        echo '<p><label><input type="checkbox" name="confirm" value="' . safe((string) (new ImportConfirmation())->token($plan, $uid)) . '" required> '
            . _m('I have read the security findings (S) and want to install the app with them.') . '</label></p>';
    }
    if ($run->mode() === InstallRun::TRANSFER) {
        echo '<div class="aa-info">' . _m('Do not edit the source while the transfer runs.') . '</div>';
    }
    echo '<p><button class="btn-l" type="submit" data-role="installapp-write">' . (($run->mode() === InstallRun::TRANSFER) ? _m('Write the definitions and transfer the items') : _m('Install')) . '</button></p></form>';
}

/** @param list<string> $modules @param array<string, string> $into the targets the write form sends
 *  @param string $sum InstallRun::choicesSum() of the preview the write form comes from */
function installapp_write(string $run_id, string $site, array $modules, array $into, string $sum, ?string $confirm, string $uid): void
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
        $prepared = installapp_prepare(installapp_run_source($run), $site, $modules, $run->mode(), $run->allBins(), $run->started(), $run->targets(), false, $run->choices());
    } catch (SourceFailed $e) {
        $prepared = ['error' => $e->getMessage(), 'login' => false, 'xml' => '', 'same' => false];
    }
    if ($prepared['error'] !== '') {
        echo '<div class="aa-err">' . safe($prepared['error']) . ($prepared['login'] ? ' ' . installapp_relogin($run) : '') . '</div>';
        return;
    }
    if (!$run->chose($into) || !hash_equals($run->choicesSum(), $sum)) {
        echo '<div class="aa-err">' . _m('The choice changed since the preview - preview again.') . '</div>';
        return;
    }
    if (!$run->matchesPreview($prepared['xml'], $prepared['same'], $into)) {
        echo '<div class="aa-err">' . _m('The source changed since the preview - preview again.') . '</div>';
        return;
    }
    if (!installapp_importable($prepared)) {
        installapp_findings($prepared['findings'], $prepared['package']);
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
    installapp_report($report, $prepared['package']);
    if ($run->mode() === InstallRun::TRANSFER) {
        installapp_progress($run_id, $uid, true);
    }
}

function installapp_report(ImportReport $report, ?Package $package): void
{
    echo '<div class="aa-ok">' . _m('The definitions are written.') . '</div><table class="aa-installapp-findings"><tr><th>' . _m('Module') . '</th><th>' . _m('Type') . '</th><th>' . _m('Id') . '</th></tr>';
    foreach ($report->modules as $m) {
        echo '<tr><td>' . safe($m['name'] . ($m['existing'] ? ' (' . _m('existing slice') . ')' : '')) . '</td><td>' . safe($m['type']) . '</td><td><code>' . safe($m['new']) . '</code></td></tr>';
    }
    echo '</table>';
    installapp_settings($report->settings);
    foreach ($report->failures() as [$step, $errors]) {
        echo '<div class="aa-err">' . safe($step . ': ' . implode('; ', $errors)) . '</div>';
    }
    installapp_findings($report->findings, $package);
}

/** the slices with settings fields left empty, linked to the settings page once the slice exists; the
 *  names are package text @param list<array{name: string, fields: array<string, string>, url?: string}> $settings */
function installapp_settings(array $settings): void
{
    if (!$settings) {
        return;
    }
    echo '<div class="aa-installapp-code"><h4>' . _m('Slice settings to fill in') . '</h4><p>'
        . safe(_m('Some slices have settings - values that hold for the whole slice, not for single items (an address, a version, a switch shown on the pages). An install does not bring their values: the slices start with them empty, and the pages that show them show nothing.'))
        . '</p><p><strong>' . _m('What to do') . ':</strong> '
        . safe(array_filter(array_column($settings, 'url')) ? _m('Fill them in by the links below, or later: in the slice open Setting in the menu of the item manager.')
            : _m('After installing fill them in: in the slice open Setting in the menu of the item manager. The report after installing links there.'))
        . '</p><ul>';
    foreach ($settings as $s) {
        $names = implode(', ', array_map(static fn(string $id, string $name): string => ($name !== '') ? $name : $id, array_keys($s['fields']), $s['fields']));
        echo '<li>' . safe($s['name']) . ': ' . safe($names) . (isset($s['url']) ? ' - ' . a_href(get_aa_url($s['url']), safe(_m('fill them in'))) : '') . '</li>';
    }
    echo '</ul></div>';
}

/** findings by level, a level by code - a security code with what it means and what to check; every
 *  text is package text
 *  @param list<array{string, LintFinding}> $findings @param Package|null $package names the places */
function installapp_findings(array $findings, ?Package $package = null): void
{
    $levels = [LintFinding::SECURITY => _m('Security findings (S)'), LintFinding::ERROR => _m('Errors (E)'), LintFinding::WARNING => _m('Warnings (W)'), LintFinding::INFO => _m('Notes (I)')];
    $unique = [];
    foreach ($findings as $f) {                  // the export lint and the import lint read the same texts
        $unique[implode("\0", [$f[1]->level, $f[1]->code, $f[0], $f[1]->ref])] ??= $f;
    }
    $text = new FindingText($package);
    foreach ($levels as $level => $label) {
        $by_code = [];
        foreach ($unique as $f) {
            if ($f[1]->level === $level) {
                $by_code[$f[1]->code][] = $f;
            }
        }
        if (!$by_code) {
            continue;
        }
        echo '<details' . (in_array($level, [LintFinding::SECURITY, LintFinding::ERROR], true) ? ' open' : '') . '><summary>' . $label . ' - ' . array_sum(array_map('count', $by_code)) . '</summary>'
            . '<p class="tabhlp">' . safe(FindingText::level($level)) . '</p>';
        foreach ($by_code as $code => $of) {
            $explained = FindingText::explain($of[0][1]);
            echo '<div class="aa-installapp-code"><h4>' . safe($explained['title'] ?? (string) $code) . ' - ' . count($of) . ($explained ? ' <small><code>' . safe((string) $code) . '</code></small>' : '') . '</h4>';
            if ($explained) {
                echo '<p>' . safe($explained['what']) . '</p><p><strong>' . _m('What to do') . ':</strong> ' . safe($explained['todo']) . '</p>';
            }
            $any_ref = (bool) array_filter($of, static fn(array $f): bool => FindingText::found($f[1], $f[0]) !== '');
            echo '<table class="aa-installapp-findings"><tr><th>' . _m('Where') . '</th>' . ($any_ref ? '<th>' . safe($explained['found'] ?? _m('Found')) . '</th>' : '') . '</tr>';
            foreach ($of as [$path, $finding]) {
                $place = $text->place($path);
                echo '<tr><td>' . safe($place) . (($place !== $path) ? '<br><small><code>' . safe($path) . '</code></small>' : '') . '</td>'
                    . ($any_ref ? '<td><code>' . safe(FindingText::found($finding, $path)) . '</code></td>' : '') . '</tr>';
            }
            echo '</table></div>';
        }
        echo '</details>';
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
    echo '<div class="aa-ok">' . _m('The transfer is finished. Checked at %1:', [date('Y-m-d H:i', (int) $check['checked'])]) . '</div><table class="aa-installapp-findings"><tr><th>' . _m('Slice') . '</th><th class="aa-num">' . _m('Written') . '</th><th class="aa-num">' . _m('On the source') . '</th><th class="aa-num">' . _m('On this AA') . '</th></tr>';
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
