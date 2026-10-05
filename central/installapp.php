<?php

/**
 * Install an app of this AA or of another one (aa=<central_conf id>, or the app store - appstore.php opens
 * its runs): a site with its modules, or slices
 * and Alerts modules, as a clean app (install) or with their items (transfer). One path for both: the
 * source makes XML (AppExport here, Get_App there), this AA reads it (Reader -> Validator -> Planner ->
 * Importer). A transfer then moves the items in batches - the steps of the run in the task queue, each
 * batch as XML too (InstallRun); the progress page only shows them. A transfer carries password hashes
 * and 2FA secrets: superadmins only, on both AAs. Another AA gives its app only to its superadmin: the
 * admin logs in to it here, this AA keeps the short token it gets only in the run (encrypted), never the
 * password.
 *
 * GET               the form (aa=<id>: the login to another AA), or with run=<id> the progress / report of a run
 * POST connect      logs in to another AA: the run opens in preview with its token, the choice of the app follows
 * POST preview      the preview; creates the InstallRun (this AA) or fills the one of the login. Each slice of the
 *                   app goes into a new slice or an existing one of this AA (into[<source id>]); the preview
 *                   offers the choice and is sent again with it. The first preview chooses by the role of the
 *                   slice (SliceRoles)
 * POST write        writes the definitions; an install gives its new modules the owner its form chose (owner)
 * POST state|continue|end   JSON for the progress page
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
use AA\Central\FilterCheck;
use AA\Central\FindingText;
use AA\Central\InstallRun;
use AA\Central\ItemIdCheck;
use AA\Central\Package\DbImportTarget;
use AA\Central\Package\DbSource;
use AA\Central\Package\ExistingSlices;
use AA\Central\Package\FindingList;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\ImportReport;
use AA\Central\Package\ItemFilter;
use AA\Central\Package\ItemLint;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Planner;
use AA\Central\Package\Reader;
use AA\Central\Package\ReferenceRewriter;
use AA\Central\Package\Schema;
use AA\Central\Package\SettingDefaults;
use AA\Central\Package\SliceRoles;
use AA\Central\Package\Validator;
use AA\Central\Package\ValueCodec;
use AA\Central\Package\WriteError;
use AA\Central\RemoteSource;
use AA\Central\SourceFailed;
use AA\Central\TransferProgress;
use AA\Central\TransferText;
use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;
use AA\Util\FormToken;
use AA\Util\InputVars;

require_once __DIR__ . "/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";

const INSTALLAPP_TOKEN = InstallRun::FORM_TOKEN;
const INSTALLAPP_JSON  = ['state', 'continue', 'end'];
/** places of a note listed - its count says the rest */
const INSTALLAPP_PLACES = 5;

pageOpen();

try {
    $action   = InputVars::stringPOST('action');
    $run_id   = strtolower(InputVars::idPOST('run') ?: InputVars::idGET('run'));
    $token    = InputVars::stringPOST('token');
    $site     = strtolower(InputVars::idPOST('site'));
    $modules  = array_values(array_map('strtolower', array_filter(InputVars::arrayPOST('modules'), static fn($id): bool => is_string($id) && is_long_id($id))));
    $mode     = (InputVars::stringPOST('mode') === InstallRun::TRANSFER) ? InstallRun::TRANSFER : InstallRun::INSTALL;
    $all_bins = InputVars::stringPOST('bins') === 'all';
    $confirm  = InputVars::stringPOST('confirm');
    $aa       = max(0, InputVars::intGETPOST('aa'));     // a row of the Central list: the app comes from that AA
    // not username/password: those would log this session in as the user of the other AA
    $login    = [InputVars::stringPOST('source_username'), InputVars::stringPOST('source_password'), InputVars::stringPOST('source_code2fa')];
    $into     = InstallRun::targetsFrom(InputVars::arrayPOST('into'));
    $choice   = InstallRun::choiceMade(InputVars::arrayPOST('into'));
    $choices  = ImportChoices::fromPost([       // the planner checks them
        'name'            => InputVars::arrayPOST('name'),
        'skip'            => InputVars::arrayPOST('skip'),
        'setting'         => InputVars::arrayPOST('setting'),
        'owner'           => InputVars::stringPOST('owner'),
        'new_owner'       => InputVars::stringPOST('new_owner'),
        'new_owner_email' => InputVars::stringPOST('new_owner_email'),
        'filter'          => InputVars::arrayPOST('filter'),
    ]);
    $sum      = InputVars::stringPOST('choices_sum');
    $into     = array_diff_key($into, array_flip($choices->skip));     // a slice left out goes nowhere
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

$run_of   = ($run_id === '') ? null : InstallRun::get($run_id);
// a transfer hands out password hashes and 2FA secrets of the readers - its progress page shows their findings
$transfer = $json || (in_array($action, ['connect', 'preview'], true) && ($mode === InstallRun::TRANSFER)) || ($run_of?->mode() === InstallRun::TRANSFER);
if ($transfer && !IsSuperadmin()) {
    $json ? installapp_json(['status' => 'denied', 'message' => _m('Only a superadmin may transfer apps with their items.')], 403)
          : AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "installapp.php", _m('Only a superadmin may transfer apps with their items.'), 'admin');
}
// the run of another AA keeps a token of that AA's superadmin - for an install too
if ((($action === 'connect') || (($run_of !== null) && ($run_of->source() !== InstallRun::LOCAL))) && !IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "installapp.php", _m('Only a superadmin may install apps from another AA.'), 'admin');
}

if ($json) {
    // the page asks every few seconds - it must not block the other pages of this session
    Session::page_close();
    session_write_close();
    $run = $run_of;
    try {
        $text   = new TransferText($run ? installapp_slice_names($run) : []);
        $status = '';
        if ($action === 'continue') {
            $sealed = null;
            // only the user of the run logs in to the source for it - Continue is that user's
            if ($run && ($run->user() === $uid) && TransferProgress::needsLogin($run)) {
                if ($login[0] === '') {
                    installapp_json($text->page(TransferProgress::state($run_id, $uid, TransferProgress::LOGIN)));
                }
                $remote = RemoteSource::forSource($run->source());
                $sealed = $remote->seal($remote->login($login));
            }
            $status = TransferProgress::resume($run_id, $uid, $sealed);
        } elseif ($action === 'end') {
            $status = TransferProgress::end($run_id, $uid);
        }
        installapp_json($text->page(TransferProgress::state($run_id, $uid, $status)));
    } catch (BatchFailed) {
        installapp_json(['status' => 'denied', 'message' => _m('No such transfer, or not yours.')], 403);
    } catch (SourceFailed $e) {
        installapp_json(['status' => 'denied', 'message' => $e->getMessage()], 403);
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
    .aa-installapp-progress progress { width: 100%; max-width: 30em; }
    .aa-installapp-notes { padding-left: 1.2em; max-width: 60em; }
    .aa-installapp-notes li { margin: 0.6em 0; overflow-wrap: anywhere; }
    .aa-installapp-notes li div { margin-top: 0.2em; }
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
    .aa-installapp { --aa-muted: rgb(31 31 31 / 66%); --aa-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; --aa-ring: 0 0 0 3px rgba(184, 162, 70, .20); }
    .aa-sr, .aa-plan-lbl { position: absolute; width: 1px; height: 1px; margin: -1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    .aa-plan { container: aa-plan / inline-size; max-width: 70em; }
    .aa-installapp-plan { width: 100%; table-layout: fixed; }
    .aa-installapp-plan .aa-c-on { width: 3.4em; }
    .aa-installapp-plan .aa-c-name { width: 18.5em; }
    .aa-installapp-plan .aa-c-type { width: 4em; }
    .aa-installapp-plan td { border-bottom: 0; }
    .aa-installapp-plan .aa-plan-a > td { padding-top: 8px; }
    .aa-installapp-plan tbody > tr:last-child > td { padding-bottom: 8px; border-bottom: 1px solid var(--clr-border, #ddd); }
    .aa-plan-name small { display: block; margin-top: 1px; font-size: var(--sz-xs); }
    .aa-plan-into select { width: 100%; max-width: 36em; }
    .aa-installapp-plan .aa-plan-flabel { padding-top: 11px; text-align: right; font-size: var(--sz-s); }
    .aa-plan-scopebox { display: grid; grid-template-columns: minmax(0, 1fr) 10.8em; grid-template-areas: "field count" "note note"; column-gap: 8px; max-width: 52em; }
    .aa-cond-in { grid-area: field; box-sizing: border-box; width: 100%; min-height: 2.15em; padding: 4px 6px; font-family: var(--aa-mono); line-height: 1.4; resize: none; field-sizing: content; overflow-wrap: anywhere; }
    .aa-cond-in::placeholder { font-family: inherit; color: var(--aa-muted); }
    .aa-cond-in:focus { outline: 0; border-color: var(--clr-border-hover, #a59850); box-shadow: var(--aa-ring); }
    .aa-cond-in[aria-invalid="true"] { border-color: var(--clr-dark, #1f1f1f); background: var(--clr-surface-selected, #f3e6c3); }
    .aa-count { grid-area: count; padding-top: 7px; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .aa-count strong { font-weight: 600; }
    .aa-count-of { color: var(--aa-muted); }
    .aa-count-bar { display: block; height: 3px; margin-top: 4px; background: linear-gradient(to right, var(--clr-dark, #1f1f1f) max(2px, calc(var(--pct) * 1%)), var(--clr-border, #ddd) 0); }
    .aa-cond-note { grid-area: note; display: flex; gap: 8px; margin-top: 4px; font-size: var(--sz-s); }
    .aa-cond-note > div { min-width: 0; }
    .aa-lv { flex: none; box-sizing: border-box; min-width: 1.9em; height: 1.55em; border: 1px solid var(--clr-dark, #1f1f1f); font-size: var(--sz-xs); font-weight: 600; line-height: calc(1.55em - 2px); text-align: center; }
    .aa-lv-e { background: var(--clr-dark, #1f1f1f); color: var(--clr-body, #f3f3f1); }
    .aa-installapp code.aa-cond-code { display: block; margin: 4px 0 8px; padding: 4px 8px; background: var(--clr-basic-light, #eeebe3); font-family: var(--aa-mono); overflow-wrap: anywhere; user-select: all; }
    .aa-filterhint, .aa-filterhelp { max-width: 62em; }
    .aa-filterhint code { white-space: nowrap; }
    .aa-filterhelp-body { margin: 8px 0 18px; padding: 2px 18px 12px; background: var(--clr-basic-light, #eeebe3); border: 1px solid var(--clr-border, #ddd); font-size: var(--sz-s); line-height: 1.6; }
    .aa-filterhelp-body h4 { margin: 18px 0 2px; }
    .aa-filterhelp-body p { margin: 2px 0; }
    .aa-filterhelp-body code.aa-cond-code { background: var(--clr-body, #f3f3f1); }
    .aa-filterhelp-parts { display: grid; grid-template-columns: max-content 1fr; gap: 2px 18px; margin: 2px 0; }
    .aa-filterhelp-parts dt { font-weight: 600; }
    .aa-filterhelp-parts dd { margin: 0; }
    .aa-filterhelp-ops { width: 100%; }
    .aa-filterhelp-ops td:first-child { white-space: nowrap; }
    .aa-filterhelp-slice > summary { display: block; padding: 4px 0; background: none; border: 0; border-radius: 0; border-bottom: 1px solid var(--clr-border, #ddd); font-weight: 600; }
    .aa-filterhelp-slice > summary small { font-weight: 400; color: var(--aa-muted); }
    .aa-filterhelp-fields { margin: 8px 0 12px; padding: 0; list-style: none; columns: 26em; column-gap: 2em; }
    .aa-filterhelp-fields li { break-inside: avoid; padding: 1px 0; overflow-wrap: anywhere; }
    .aa-filterhelp-fields small { margin-left: 8px; color: var(--aa-muted); font-size: var(--sz-xs); }
    @container aa-plan (max-width: 46em) {
      .aa-installapp-plan, .aa-installapp-plan tbody, .aa-installapp-plan tr, .aa-installapp-plan td { display: block; }
      .aa-installapp-plan { box-sizing: border-box; padding-right: 20px; }
      .aa-installapp-plan colgroup, .aa-installapp-plan thead, .aa-plan-b > td:first-child { display: none; }
      .aa-installapp-plan tbody > tr > td, .aa-installapp-plan tbody > tr:last-child > td { padding: 0; border: 0; }
      .aa-installapp-plan tbody { padding: 12px 0; border-bottom: 1px solid var(--clr-border, #ddd); }
      .aa-installapp-plan .aa-plan-a { display: grid; grid-template-columns: 2.6em 1fr auto; grid-template-areas: "on name type" "into into into"; }
      .aa-plan-on { grid-area: on; }
      .aa-plan-name { grid-area: name; }
      .aa-installapp-plan .aa-plan-type { grid-area: type; padding: 4px 2px 0 8px; font-size: var(--sz-xs); color: var(--aa-muted); }
      .aa-installapp-plan .aa-plan-into { grid-area: into; margin-top: 8px; }
      .aa-plan-into .aa-plan-lbl, .aa-installapp-plan .aa-plan-flabel { position: static; width: auto; height: auto; margin: 0 0 2px; padding: 0; clip-path: none; text-align: left; white-space: normal; font-size: var(--sz-s); }
      .aa-installapp-plan .aa-plan-b { margin-top: 8px; }
      .aa-plan-scopebox { grid-template-columns: minmax(0, 1fr); grid-template-areas: "field" "note" "count"; }
      .aa-count { padding-top: 4px; text-align: left; }
    }
    @media (max-width: 600px) {
      .aa-filterhelp-body { padding: 2px 8px 12px; }
      .aa-filterhelp-ops td:first-child { white-space: normal; }
    }
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

/** the source of a run, with the rights of the user here
 *  @throws SourceFailed */
function installapp_run_source(InstallRun $run): AppSource
{
    return ($run->source() === InstallRun::LOCAL) ? installapp_local_source() : $run->remoteSource();
}

/** the link back to the login to the source AA of the run; the store logs in by itself */
function installapp_relogin(InstallRun $run): string
{
    return ($run->source() === InstallRun::STORE)
        ? a_href(StateUrl(get_url(self_base() . 'appstore.php')), _m('Open the app store again'))
        : a_href(installapp_url(['aa' => $run->source()]), _m('Log in to the source AA again'));
}

/** a run cannot start: start() refuses a table without transactions */
function installapp_refused(BatchFailed $e): string
{
    return '<div class="aa-err">' . safe(_m('A table of this AA has no transactions (not InnoDB) - convert it in AA > Optimize: %1', [$e->where])) . '</div>';
}

/** the items the preview counted on the source per transferred slice - of a filtered one those its filter selects
 *  @return array<string, int> */
function installapp_source_counts(array $prepared): array
{
    return array_replace($prepared['counts'], $prepared['matching']);
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
    // a superadmin may end the transfers of the others too
    $open = array_filter(InstallRun::unfinished(), static fn(InstallRun $run): bool => ($run->state() !== InstallRun::PREVIEW) && ($run->mode() === InstallRun::TRANSFER) && $run->mayControl($uid));
    if ($open) {
        echo '<div class="aa-info"><p>' . _m('Unfinished transfers') . ':</p><ul>';
        foreach ($open as $run) {
            $who = ($run->user() === $uid) ? '' : ' - ' . perm_username($run->user());
            echo '<li>' . a_href(installapp_url(['run' => $run->getId()]), safe(date('Y-m-d H:i', $run->started())) . ' - ' . safe(installapp_state($run->state()) . $who)) . '</li>';
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
        $sealed = $remote->seal($remote->login($login));
    } catch (SourceFailed $e) {
        echo '<div class="aa-err">' . safe($e->getMessage()) . '</div>';
        installapp_form($aa, $uid);
        return;
    }
    try {
        installapp_choose(InstallRun::preview($mode, $all_bins, (string) $aa, $uid, $sealed, time()));
    } catch (BatchFailed $e) {
        echo installapp_refused($e);
    }
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
        $from   = $run->remoteSource();
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
 *  @param ImportChoices|null    $choices names, slices left out, the owner ('' = Planner::defaultOwner()), settings values, filters
 *  @return array{error: string, login: bool, xml: string, now: int, same: bool, findings: list<array{string, LintFinding}>, plan: ?ImportPlan,
 *                into: array<string, string>, roles: array<string, string>, package: ?\AA\Central\Package\Model\Package,
 *                all: ?\AA\Central\Package\Model\Package, choices: ImportChoices, counts: array<string, int>, matching: array<string, int>}
 *          login: the source refused its token; now: the time of the source; same: the source is this AA;
 *          roles: source slice => the role its target was preselected by; choices: as planned, the default owner filled in;
 *          all: the app as the source gave it, package: as chosen (names, slices left out);
 *          counts, matching: the items of the transferred slices on the source, of the filtered ones those their filter selects */
function installapp_prepare(AppSource $from, string $site, array $modules, string $mode, bool $all_bins, int $started, array $into = [], bool $preselect = false, ?ImportChoices $choices = null): array
{
    $choices ??= new ImportChoices();
    $out      = ['error' => '', 'login' => false, 'xml' => '', 'now' => 0, 'same' => false, 'findings' => [], 'plan' => null, 'into' => [], 'roles' => [], 'package' => null, 'all' => null, 'choices' => $choices, 'counts' => [], 'matching' => []];
    $transfer = ($mode === InstallRun::TRANSFER);
    try {
        [$out['xml'], $out['findings'], $out['now']] = $from->definitions(($site !== '') ? [$site] : $modules, $transfer, $all_bins, $started);
        $out['same']     = $from->sameAa();
        $read            = (new Reader())->read($out['xml']);
        $out['findings'] = [...$out['findings'], ...$read->findings];
        $out['all']      = $read->package;
        $target          = new DbImportTarget();
        $planner         = new Planner($target);
        // a slice going into an existing one keeps the settings fields it has - a value typed for one is no choice;
        // only a slice of the app into one the user may edit is read, the plan refuses the others anyway
        $app_slices      = array_flip(array_map(static fn(Module $m): string => strtolower($m->id), array_filter($read->package->modules, static fn(Module $m): bool => $m->type === Module::SLICE)));
        $editable        = array_filter(array_intersect_key($into, $app_slices), $target->mayEditSlice(...));
        $choices         = $choices->withoutSettings(array_map(static fn(string $id): array => array_keys($target->existingSlice($id)->fields ?? []), $editable));
        // the names and the slices left out come first: the check and the plan see the app as it will be
        [$package, $chosen] = $planner->choose($read->package, $choices);
        // the source's findings of what is not written: a slice left out, a setting value typed over
        $skipped   = array_map(static fn(string $id): string => Module::SLICE . "[$id]", $choices->skip);
        // the translations of a typed-over value stay (Planner::typed())
        $typed_over = static fn(string $path): bool => (bool) preg_match('~^' . Module::SLICE . '\[([0-9a-f]{32})\]/setting-values/([^/\[]+)\[(\d+)\]~i', $path, $p)
            && isset($choices->settings[strtolower($p[1])][$p[2]]) && ((int) $p[3] < AA_Value::MAX_INDEX);
        $out['findings'] = [...array_values(array_filter($out['findings'], static fn(array $f): bool => !installapp_under($f[0], $skipped) && !$typed_over($f[0]))), ...$chosen];
        $validation = (new Validator())->validate($package, $transfer, array_map('array_keys', $choices->settings));
        $out['package'] = $package;
        $out['into']    = $into;
        if (!$validation->importable()) {
            $out['findings'] = [...$out['findings'], ...$validation->findings];
            return $out;
        }
        $slices   = $transfer ? array_values(array_map(static fn(Module $m): string => $m->id, array_filter($package->modules, static fn(Module $m): bool => $m->type === Module::SLICE))) : [];
        $filtered = (new FilterCheck($from))->check($slices, $all_bins, $choices->filters);
        // null: a filter cannot be used - its E stops the write, the readers and ids of all the items would only add noise
        $filters  = $filtered['usable'] ? ItemFilter::ofSlices($choices->filters, $slices) : null;
        [$out['counts'], $out['matching']] = [$filtered['counts'], $filtered['matching']];
        $readers  = array_values(array_map(static fn(Module $m): string => $m->id, array_filter($package->modules, static fn(Module $m): bool => ($m->type === Module::SLICE) && (ValueCodec::bytes($m->rows['slice'][0]['type'] ?? '') === ItemLint::READER_SLICE))));
        $logins   = ($transfer && $readers && ($filters !== null)) ? $from->logins($readers, $all_bins, $filters) : [];
        $owner    = ($transfer || ($choices->newOwner !== null)) ? '' : ($choices->owner ?: $planner->defaultOwner());
        // the typed text of a filter stays, even one that cannot be used: left out, it would take all the items
        $out['choices'] = new ImportChoices($choices->names, $choices->skip, $owner, $transfer ? null : $choices->newOwner, $choices->settings, ItemFilter::ofSlices($choices->filters, $slices));
        $plan    = static fn(array $targets): ImportPlan => $planner->plan($package, $validation, $transfer, $logins, $targets, $out['same'], $owner, $out['choices']);
        if ($preselect) {           // stored with the preview as any choice - the write writes what it showed
            [$out['plan'], $out['into'], $preselected, $out['roles']] = SliceRoles::plan($package, new DbImportTarget(), $out['same'], $transfer, $plan);
            $out['findings'] = [...$out['findings'], ...$preselected];
        } else {
            $out['plan'] = $plan($into);
        }
        $out['findings'] = [...$out['findings'], ...$out['plan']->allFindings(), ...$filtered['findings']];
        if ($transfer && !$out['same'] && ($filters !== null)) {        // another AA keeps the item ids: none may be here already
            $out['findings'] = [...$out['findings'], ...(new ItemIdCheck($from, new DbImportTarget()))->check($slices, $all_bins, $filters)];
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
    if (installapp_show_preview($prepared, $mode, installapp_hidden('preview', $site, $modules) . installapp_mode_hidden($mode, $all_bins))) {
        try {
            $run = InstallRun::preview($mode, $all_bins, InstallRun::LOCAL, $uid, '', $now, hash('sha256', $prepared['xml']), $prepared['into'], $prepared['choices'], installapp_source_counts($prepared));
        } catch (BatchFailed $e) {
            echo installapp_refused($e);
            return;
        }
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
        $from = $run->remoteSource();
    } catch (SourceFailed $e) {
        echo '<div class="aa-err">' . safe($e->getMessage()) . '</div>';
        return;
    }
    $prepared = installapp_prepare($from, $site, $modules, $run->mode(), $run->allBins(), $run->started(), $into, $preselect, $choices);
    if (!installapp_show_preview($prepared, $run->mode(), installapp_hidden('preview', $site, $modules, $run_id))) {
        echo '<p>' . ($prepared['login'] ? installapp_relogin($run) : a_href(installapp_url(['run' => $run_id]), _m('Choose another app'))) . '</p>';
        return;
    }
    try {
        $locked = DB_AA::transaction(static function () use ($run_id, $prepared): InstallRun {
            $locked = InstallRun::lockForUpdate($run_id) ?? throw new LogicException('The run is gone');
            $locked->previewed(hash('sha256', $prepared['xml']), $prepared['now'], $prepared['same'], $prepared['into'], $prepared['choices'], installapp_source_counts($prepared));
            return $locked;
        });
    } catch (LogicException) {              // another tab wrote it meanwhile
        echo '<div class="aa-err">' . _m('The run has moved on meanwhile.') . ' ' . a_href(installapp_url(['run' => $run_id]), _m('Show the run')) . '</div>';
        return;
    }
    installapp_write_form($locked, $site, $modules, $prepared['plan'], $uid);       // this preview's choices, not those of another tab since
}

/** the hidden mode fields of the preview of this AA sent again */
function installapp_mode_hidden(string $mode, bool $all_bins): string
{
    return '<input type="hidden" name="mode" value="' . safe($mode) . '"><input type="hidden" name="bins" value="' . ($all_bins ? 'all' : 'active') . '">';
}

/** the preview of installapp_prepare(); each slice goes into a new slice or an existing one - the choice
 *  sends the preview again with $again (the hidden fields of the same selection)
 *  @return bool the app may be written */
function installapp_show_preview(array $prepared, string $mode, string $again): bool
{
    if ($prepared['error'] !== '') {
        echo '<div class="aa-err">' . safe($prepared['error']) . '</div>';
        return false;
    }
    $plan = $prepared['plan'];
    if ($plan) {
        installapp_choices_form($prepared, $plan, $mode, $again);
    }
    // the findings of the conditions answer what was just typed: first, open
    $of_conditions = static fn(array $f): bool => in_array($f[1]->code, [ItemFilter::INVALID, FilterCheck::MATCHES_ALL, FilterCheck::LEFT_OUT], true);
    installapp_findings(array_values(array_filter($prepared['findings'], $of_conditions)), $prepared['package'], true);
    installapp_findings(array_values(array_filter($prepared['findings'], static fn(array $f): bool => !$of_conditions($f))), $prepared['package']);
    if (!installapp_importable($prepared)) {
        echo '<div class="aa-err">' . _m('The app cannot be installed - see the errors (E) above.') . '</div>';
        return false;
    }
    return true;
}

/** the choice of the slice a package slice goes into: a new one, or one of $choices (id => name) */
function installapp_into_select(string $old, string $chosen, array $choices): string
{
    $html = '<select name="into[' . safe($old) . ']" id="installapp-into-' . safe(strtolower($old)) . '"><option value="">' . safe(_m('a new slice')) . '</option>';
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
function installapp_choices_form(array $prepared, ImportPlan $plan, string $mode, string $again): void
{
    $preview  = $plan->preview();
    $choices  = $prepared['choices'];
    $transfer = ($mode === InstallRun::TRANSFER);
    $planned  = array_column($preview['modules'], null, 'old');
    $slices   = array_values(array_map(static fn(Module $m): string => $m->id, array_filter($prepared['all']->modules, static fn(Module $m): bool => $m->type === Module::SLICE)));
    $into     = $slices ? installapp_slice_choices() : [];
    $types    = [Module::SITE => _m('Site'), Module::SLICE => _m('Slice'), Module::ALERTS => _m('Alerts')];

    echo '<h2>' . safe($preview['name']) . ' - ' . safe($transfer ? _m('transfer with the items') : _m('install, no items')) . '</h2>';
    // UTF-8 whatever the charset of the admin: the choices are text of the app, which is UTF-8
    echo '<form method="post" action="' . safe(installapp_url()) . '" accept-charset="UTF-8" class="aa-installapp-choices" data-role="installapp-choices">' . $again;
    $names    = [];
    foreach ($prepared['all']->modules as $m) {
        $names[strtolower($m->id)] = $choices->names[strtolower($m->id)] ?? ValueCodec::bytes($m->rows['module'][0]['name'] ?? '');
    }
    $taken    = $transfer ? array_filter($prepared['all']->modules, static fn(Module $m): bool => ($m->type === Module::SLICE) && isset($planned[$m->id])) : [];
    $notes    = $transfer ? installapp_filter_notes($prepared['findings'], $taken, $names, $choices->filters) : [];
    echo '<h3>' . _m('What to install') . '</h3><div class="aa-plan"><table class="aa-installapp-findings aa-installapp-plan">'
        . '<colgroup><col class="aa-c-on"><col class="aa-c-name"><col class="aa-c-type"><col></colgroup>'
        . '<thead><tr><th><span class="aa-sr">' . _m('Install') . '</span></th><th>' . _m('Name') . '</th><th>' . _m('Type') . '</th><th>' . _m('Into') . '</th></tr></thead>';
    foreach ($prepared['all']->modules as $k => $m) {
        $old     = strtolower($m->id);
        $skipped = !isset($planned[$m->id]);
        $name    = $names[$old];
        $keep    = ($m->type === Module::SLICE)       // unchecked, the hidden field says "left out"
            ? '<input type="hidden" name="skip[' . safe($old) . ']" value="1"><input type="checkbox" name="skip[' . safe($old) . ']" value=""' . ($skipped ? '' : ' checked') . ' aria-label="' . safe(_m('Install %1', [$name])) . '">'
            : '<input type="checkbox" checked disabled aria-label="' . safe(_m('Install %1', [$name])) . '">';
        $cell    = match (true) {
            $skipped                                => safe(_m('not installed')),
            ($m->type === Module::SLICE) && $into   => '<label class="aa-plan-lbl" for="installapp-into-' . safe($old) . '">' . _m('Into') . '</label>' . installapp_into_select($m->id, $prepared['into'][$old] ?? '', $into) . installapp_role_note($prepared['roles'][$old] ?? null),
            default                                 => safe(_m('a new module')),
        };
        echo '<tbody class="aa-plan-slice' . ($skipped ? ' aa-installapp-skipped' : '') . '"><tr class="aa-plan-a"><td class="aa-plan-on">' . $keep . '</td>'
            . '<td class="aa-plan-name"><label class="aa-plan-lbl" for="installapp-name-' . safe($old) . '">' . _m('Name') . '</label>'
            . '<input type="text" id="installapp-name-' . safe($old) . '" name="name[' . safe($old) . ']" value="' . safe($name) . '" maxlength="100" size="40"' . ($skipped ? ' readonly' : '') . '>'
            . '<small><code>' . safe($old) . '</code></small></td>'
            . '<td class="aa-plan-type">' . safe($types[$m->type] ?? $m->type) . '</td><td class="aa-plan-into">' . $cell . '</td></tr>'
            . (isset($taken[$k]) ? installapp_filter_row($old, $choices->filters[$old] ?? '', $prepared['counts'][$m->id] ?? null, $prepared['matching'][$old] ?? null, $notes[$old] ?? null) : '')
            . '</tbody>';
    }
    echo '</table></div><p class="tabhlp">' . _m('A slice you leave out is not installed; what points to it in the rest of the app is listed in the warnings below. A slice going into an existing slice keeps the name of that slice.') . '</p>';
    if ($taken) {
        installapp_filter_help($taken, $names);
    }
    if (!$transfer && array_diff_key($plan->map->modules(), $plan->existing)) {
        installapp_owner_rows($plan->owner, $choices->newOwner, (bool) $plan->existing);
    }
    installapp_setting_rows($plan, $choices, $transfer, $prepared['all']);
    echo '<p><button class="btn" type="submit">' . _m('Check the changes') . '</button> <span class="tabhlp">' . _m('After a change, check it before installing.') . '</span></p></form>';
    // a condition is one line that wraps: Enter checks the changes; the field grows where field-sizing does not
    echo '<script>document.querySelectorAll(\'.aa-cond-in\').forEach(t => { const fit = () => { t.style.height = \'auto\'; t.style.height = (t.scrollHeight + t.offsetHeight - t.clientHeight) + \'px\'; };'
        . ' if (!CSS.supports(\'field-sizing\', \'content\')) { t.addEventListener(\'input\', fit); fit(); }'
        . ' t.addEventListener(\'keydown\', e => { if ((e.key === \'Enter\') && !e.isComposing) { e.preventDefault(); t.form.requestSubmit(); } }); });</script>';
    // the write takes what this preview checked - after a change the Install button waits for the check
    echo '<script>document.querySelector(\'[data-role="installapp-choices"]\').addEventListener(\'input\', () => {'
        . ' const b = document.querySelector(\'[data-role="installapp-write"]\'); if (b && !b.disabled) { b.disabled = true; b.textContent = '
        . json_encode(_m('Check the changes first'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '; } });</script>';
}

/** The second row of a transferred slice: its condition as typed, its items - matching of all when the
 *  source read the condition - and the note of a finding of the condition.
 *  @param array{level: string, html: string}|null $note */
function installapp_filter_row(string $slice, string $filter, ?int $count, ?int $matching, ?array $note): string
{
    $id      = 'installapp-filter-' . safe($slice);
    $invalid = ($note['level'] ?? '') === LintFinding::ERROR;
    $number  = static fn(int $n): string => number_format($n, 0, '.', "\u{00A0}");
    $items   = match (true) {
        ($count === null) && ($matching !== null) => '<strong>' . $number($matching) . '</strong> <span class="aa-count-of">' . safe(_m('of %1 items', ['?'])) . '</span>',
        $count === null    => '? ' . _m('items'),
        $invalid           => '<strong aria-hidden="true">-</strong> <span class="aa-count-of">' . safe(_m('of %1 items', [$number($count)])) . '</span>',
        ($matching === null) && ($filter !== '') => '<strong>?</strong> <span class="aa-count-of">' . safe(_m('of %1 items', [$number($count)])) . '</span>',
        $matching !== null => '<strong>' . $number($matching) . '</strong> <span class="aa-count-of">' . safe(_m('of %1 items', [$number($count)])) . '</span>'
            . '<span class="aa-count-bar" style="--pct:' . ($count ? round(100 * $matching / $count, 1) : 100) . '" aria-hidden="true"></span>',
        default            => '<strong>' . $number($count) . '</strong> <span class="aa-count-of">' . _m('items') . '</span>',
    };
    $html    = $note ? '<div class="aa-cond-note" id="' . $id . '-note"><span class="aa-lv' . ($invalid ? ' aa-lv-e' : '') . '">' . safe($note['level']) . '</span><div>' . $note['html'] . '</div></div>' : '';
    return '<tr class="aa-plan-b"><td></td><td class="aa-plan-flabel"><label for="' . $id . '">' . _m('Only items matching') . '</label></td><td colspan="2"><div class="aa-plan-scopebox">'
        . '<textarea class="aa-cond-in" id="' . $id . '" name="filter[' . safe($slice) . ']" rows="1" placeholder="' . safe(_m('all items')) . '"'
        . ' spellcheck="false" autocomplete="off" autocapitalize="off" aria-describedby="' . ($html ? "$id-note " : '') . "$id-count\"" . ($invalid ? ' aria-invalid="true"' : '') . '>' . safe($filter) . '</textarea>'
        . '<span class="aa-count" id="' . $id . '-count">' . $items . '</span>' . $html . '</div></td></tr>';
}

/** The findings of the conditions as a note under the field they are about: why a condition cannot be
 *  used (with the field of the slice its typo means), that it takes every item, the links it breaks in
 *  another slice - with the condition that follows the link when only one relation leads there.
 *  @param list<array{string, LintFinding}> $findings @param array<int, Module> $taken the transferred slices
 *  @param array<string, string> $names module => name @param array<string, string> $filters
 *  @return array<string, array{level: string, html: string}> slice => note */
function installapp_filter_notes(array $findings, array $taken, array $names, array $filters): array
{
    $notes  = [];
    $fields = [];
    foreach ($taken as $m) {
        $fields[strtolower($m->id)] = installapp_slice_fields($m, $names);
    }
    foreach ($findings as [$path, $finding]) {
        $slice = preg_match('/^' . preg_quote(Module::SLICE, '/') . '\[([0-9a-f]{32})\]\z/', $path, $p) ? $p[1] : '';
        if (!isset($fields[$slice])) {
            continue;
        }
        $html = match ($finding->code) {
            ItemFilter::INVALID      => '<strong>' . _m('Cannot be used.') . '</strong> ' . safe(FindingText::found($finding, $path)) . installapp_field_meant($finding->ref, $fields[$slice]),
            FilterCheck::MATCHES_ALL => '<strong>' . _m('Takes every item.') . '</strong> ' . safe(_m('It narrows nothing; empty the field if all the items should go.')),
            FilterCheck::LEFT_OUT    => installapp_left_out_note((int) $finding->ref, $slice, $fields[$slice], $names, $filters),
            default                  => null,
        };
        if ($html !== null) {
            $notes[$slice] = isset($notes[$slice])
                ? ['level' => (($notes[$slice]['level'] === LintFinding::ERROR) ? LintFinding::ERROR : $finding->level), 'html' => $notes[$slice]['html'] . '<br>' . $html]
                : ['level' => $finding->level, 'html' => $html];
        }
    }
    return $notes;
}

/** the field of the slice a field id of a filter-invalid means: the same letters and digits, other dots
 *  @param list<array{id: string, name: string, kind: string, target: string}> $fields */
function installapp_field_meant(string $reason, array $fields): string
{
    if (!str_starts_with($reason, 'field:')) {
        return '';
    }
    $typed = str_replace('.', '', substr($reason, 6));
    foreach ($fields as $f) {
        if (($typed !== '') && (str_replace('.', '', $f['id']) === $typed)) {
            return ' ' . _m('The slice has %1 "%2".', ['<code>' . safe($f['id']) . '</code>', safe($f['name'])]);
        }
    }
    return '';
}

/** @param string $holder the slice holding the links
 *  @param list<array{id: string, name: string, kind: string, target: string}> $fields of $holder
 *  @param array<string, string> $names @param array<string, string> $filters */
function installapp_left_out_note(int $links, string $holder, array $fields, array $names, array $filters): string
{
    $leading = array_values(array_filter($fields, static fn(array $f): bool => isset($filters[$f['target']])));
    $text    = '<strong>' . _m('Links will point nowhere.') . '</strong> ';
    if (count($leading) !== 1) {
        return $text . safe(_m('%1 values link to items a condition leaves behind.', [number_format($links, 0, '.', "\u{00A0}")]));
    }
    $target  = $leading[0]['target'];
    // a link within the slice would need the condition of every item up the chain
    $follow  = ($target === $holder) ? null : installapp_follow_link($filters[$target], $leading[0]['id'], $filters[$holder] ?? '');
    return $text . safe(_m('%1 values link to items of %2 that stay behind.', [number_format($links, 0, '.', "\u{00A0}"), $names[$target] ?? $target]))
        . (($follow === null) ? '' : '<br>' . safe(_m('To take only the items linking to items that go:')) . '<code class="aa-cond-code">' . safe($follow) . '</code>');
}

/** The condition of the slice holding the links: its own conditions, then the one of the linked slice
 *  with its field read through the link (`field@link`) - an item linking to several items matches when one
 *  of them goes. Null for a filter of more conditions (each read through the link on its own, they could
 *  match different linked items), of other field forms, or too long together.
 *  @param string $filter the filter of the linked slice @param string $link the relation field leading there
 *  @param string $own the filter of the slice holding the links, '' = none */
function installapp_follow_link(string $filter, string $link, string $own = ''): ?string
{
    $params = (new AA_View_Commands($filter))->get('d')?->getParameterArray() ?? [];
    $kept   = ($own === '') ? [] : ((new AA_View_Commands($own))->get('d')?->getParameterArray() ?? []);
    if ((count($params) !== 3) || (count($kept) % 3) || ((count($kept) / 3 + 1) > ItemFilter::MAX_CONDITIONS)) {
        return null;
    }
    [$fields, $operator, $value] = array_map('strval', $params);
    // `a,b` matches either field, `a@link,b@link` both - so one field only
    $field = str_replace(' ', '', $fields);
    if (!AA_Field::isValidFieldId($field)) {
        return null;
    }
    $follow = 'd-' . implode('-', array_map(static fn(string $part): string => str_replace('-', '--', $part), [...array_map('strval', $kept), "$field@$link", $operator, $value]));
    return (strlen($follow) > ItemFilter::MAX_BYTES) ? null : $follow;
}

/** the item fields of a package slice for the help of the conditions: id, name, the kind of value it holds
 *  @param array<string, string> $names module => name
 *  @return list<array{id: string, name: string, kind: string, target: string}> target: the slice a relation field links to */
function installapp_slice_fields(Module $m, array $names): array
{
    $fields = [];
    foreach ($m->rows['field'] ?? [] as $row) {
        $id = ValueCodec::bytes($row['id'] ?? '');
        if (($id === '') || str_starts_with($id, '_')) {           // a settings field holds no item value
            continue;
        }
        $widget   = ValueCodec::bytes($row['input_show_func'] ?? '');
        $target   = (string) ReferenceRewriter::relationSlice($widget);
        $fields[] = ['id' => $id, 'name' => ValueCodec::bytes($row['name'] ?? ''), 'target' => $target, 'kind' => match (true) {
            $target !== ''                  => _m('relation to %1, holds the item id', [$names[$target] ?? _m('another slice')]),
            str_starts_with($widget, 'chb') => _m('checkbox, 1 when ticked'),
            str_starts_with($widget, 'dte') => _m('date'),
            default                         => '',
        }];
    }
    return $fields;
}

/** How to write a condition: a short hint, then on demand the parts, examples from the fields of the app,
 *  the operators, AND, the doubled dash and the field ids of every transferred slice.
 *  @param array<int, Module> $taken @param array<string, string> $names */
function installapp_filter_help(array $taken, array $names): void
{
    $fields  = array_map(static fn(Module $m): array => installapp_slice_fields($m, $names), $taken);
    $code    = static fn(string $c): string => '<code class="aa-cond-code">' . safe($c) . '</code>';
    $example = '';
    foreach ($taken as $i => $m) {
        foreach ($fields[$i] as $f) {
            if ($f['target'] !== '') {
                $example = '<p>' . safe(_m('The items of %1 linked to one item of %2: %3 is a relation field, it holds the item id of the linked item (32 characters, the id= in the address of its edit page), not its name.', [$names[strtolower($m->id)], $names[$f['target']] ?? _m('another slice'), $f['name']])) . '</p>'
                    . $code('d-' . $f['id'] . '-==-<item id>');
                break 2;
            }
        }
    }
    $year    = date('Y');
    $ops     = [['==', _m('equals the value exactly'), '42'], ['=', _m('contains the text; "a" OR "b" for either'), 'energy'], ['<>', _m('differs from the value (one word or number)'), '3'],
        ['< > <= >=', _m('is smaller or greater (numbers)'), '100'], ['BEGIN', _m('begins with the text'), 'Pra'], ['ISNULL', _m('is not set (write any value)'), '1'],
        ['NOTNULL', _m('is set (write any value)'), '1'], ['BETWEEN', _m('lies between two numbers'), '1,5'], ['REGEXP', _m('matches the regular expression'), '^CZ[0-9]+'],
        ['d:< d:>', _m('is before or after a date (any form PHP strtotime reads)'), "$year--01--01"], ['e:< e:>', _m('is before or after a date written dd.mm.yyyy'), "1.1.$year"]];
    echo '<p class="tabhlp aa-filterhint"><strong>' . _m('Only items matching') . ':</strong> '
        . _m('empty takes every item. To take only some, write a condition, %1, for example %2. Check the changes then shows beside the field how many items it takes.', ['<code>d-&lt;field id&gt;-&lt;operator&gt;-&lt;value&gt;</code>', '<code>d-headline........-=-energy</code>']) . '</p>';
    $html = '<details class="aa-filterhelp"><summary>' . _m('How to write a condition, with field ids') . '</summary><div class="aa-filterhelp-body">'
        . '<h4>' . _m('Parts') . '</h4><dl class="aa-filterhelp-parts">'
        . '<dt>' . _m('field id') . '</dt><dd>' . safe(_m('16 characters, a short id padded with dots: headline........, relation.......4. A dot too many or too few is an unknown field. The ids of every slice are listed at the end.')) . '</dd>'
        . '<dt>' . _m('operator') . '</dt><dd>' . safe(_m('how to compare, see the operators below.')) . '</dd>'
        . '<dt>' . _m('value') . '</dt><dd>' . safe(_m('what to compare with. Capitals count. A dash inside a value is written doubled, see below.')) . '</dd></dl>'
        . '<h4>' . _m('Examples') . '</h4>' . $example
        . '<p>' . safe(_m('Edited after 1 January %1. d:> is after, d:< before.', [$year])) . '</p>' . $code("d-last_edit.......-d:>-$year--01--01")
        . '<h4>' . _m('Operators') . '</h4><table class="aa-installapp-findings aa-filterhelp-ops"><tr><th>' . _m('Operator') . '</th><th>' . _m('Keeps the items whose field') . '</th><th>' . _m('Value, e.g.') . '</th></tr>';
    foreach ($ops as [$op, $means, $value]) {
        $html .= '<tr><td><code>' . safe($op) . '</code></td><td>' . safe($means) . '</td><td><code>' . safe($value) . '</code></td></tr>';
    }
    $html .= '</table><h4>' . _m('More than one condition') . '</h4><p>' . safe(_m('Repeat field, operator and value after the first one, without a second d-. An item must match all of them. Up to %1 conditions and %2 characters.', [ItemFilter::MAX_CONDITIONS, ItemFilter::MAX_BYTES])) . '</p>'
        . $code("d-last_edit.......-d:>-$year--01--01-status_code.....-==-1")
        . '<h4>' . _m('A dash inside a value') . '</h4><p>' . safe(_m('A single dash ends the value, so a dash in a date or a name is written doubled (--). Otherwise the condition cannot be used.')) . '</p>'
        . $code('d-headline........-=-Ústí--nad--Labem')
        . '<h4>' . _m('Field ids') . '</h4>';
    foreach ($taken as $i => $m) {
        $html .= '<details class="aa-filterhelp-slice"><summary>' . safe($names[strtolower($m->id)]) . ' <small>' . safe(_m('%1 fields', [count($fields[$i])])) . '</small></summary><ul class="aa-filterhelp-fields">';
        foreach ($fields[$i] as $f) {
            $html .= '<li><code>' . safe($f['id']) . '</code> ' . safe($f['name']) . (($f['kind'] !== '') ? ' <small>' . safe($f['kind']) . '</small>' : '') . '</li>';
        }
        $html .= '</ul></details>';
    }
    echo $html . '</div></details>';
}

/** the owner of the new modules of an install: one of this AA's, or a new one (name and e-mail) the
 *  install creates @param array{name: string, email: string}|null $new @param bool $existing some slice goes into an existing one */
function installapp_owner_rows(string $chosen, ?array $new, bool $existing): void
{
    $owners = (new DbImportTarget())->owners();
    $limits = (new Schema())->tableLimits('slice_owner');
    echo '<h3>' . _m('Owner of the new modules') . '</h3><div class="aa-installapp-owner">';
    if ($owners) {
        echo '<select name="owner" aria-label="' . safe(_m('Owner of the new modules')) . '"' . ($new ? ' disabled' : '') . '>';
        foreach ($owners as $id => $name) {
            echo '<option value="' . safe((string) $id) . '"' . (((string) $id === $chosen) ? ' selected' : '') . '>' . safe((string) $name) . '</option>';
        }
        echo '</select> ';
    }
    echo '<details' . (($new || !$owners) ? ' open' : '') . '><summary>' . _m('+ Add new owner') . '</summary>'
        . '<label>' . _m('New Owner') . ' <input type="text" name="new_owner" value="' . safe($new['name'] ?? '') . '" maxlength="' . (int) ($limits['name'][0] ?? 80) . '"></label> '
        . '<label>' . _m("New Owner's E-mail") . ' <input type="email" name="new_owner_email" value="' . safe($new['email'] ?? '') . '" maxlength="' . (int) ($limits['email'][0] ?? 80) . '"></label></details></div>';
    if ($existing) {
        echo '<p class="tabhlp">' . _m('A slice going into an existing slice keeps the owner of that slice.') . '</p>';
    }
    // a new owner wins over the list - the list is off while a new name is written
    echo '<script>(() => { const d = document.querySelector(\'.aa-installapp-owner details\'), s = document.querySelector(\'.aa-installapp-owner select\'), n = d?.querySelector(\'[name="new_owner"]\');'
        . ' if (d && s && n) { const sync = () => { s.disabled = d.open && (n.value.trim() !== \'\'); }; n.addEventListener(\'input\', sync);'
        . ' d.addEventListener(\'toggle\', () => { if (!d.open) { d.querySelectorAll(\'input\').forEach(i => { if (i.value !== \'\') { i.value = \'\'; i.dispatchEvent(new Event(\'input\', { bubbles: true })); } }); } sync(); }); } })();</script>';
}

/** the settings fields of the slices the app writes: what the app brings - the value of the source, else the
 *  default of the field - and a value to write instead; none for a field whose insert function changes the
 *  value (Planner::SETTING_INSERT) @param Package $all the app as the source gave it, before the choices */
function installapp_setting_rows(ImportPlan $plan, ImportChoices $choices, bool $transfer, Package $all): void
{
    $schema = new Schema();
    $source = array_column($all->modules, null, 'id');
    $html   = '';
    foreach ($plan->package->modules as $m) {
        // an existing slice keeps the values it has; in a transfer it takes none
        $has    = isset($plan->existing[$m->id]) ? ($transfer ? null : $plan->existing[$m->id]->settings) : [];
        $fields = ($has === null) ? [] : array_filter($m->rows['field'] ?? [], static fn(array $row): bool => str_starts_with($id = ValueCodec::bytes($row['id'] ?? ''), '_') && !in_array($id, $has, true));
        if (($m->type !== Module::SLICE) || !$fields) {
            continue;
        }
        $app_m  = $source[$m->id] ?? $m;
        $of_app = $transfer ? ($app_m->transfer['setting-values'] ?? []) : SettingDefaults::values($app_m, $schema, new FindingList());
        $old    = strtolower($m->id);
        $html  .= '<tr><th colspan="3" class="aa-installapp-group">' . safe($plan->moduleName($m)) . '</th></tr>';
        foreach ($fields as $row) {
            $id     = ValueCodec::bytes($row['id'] ?? '');
            $insert = SettingDefaults::insert($row, $schema);
            $secret = SettingDefaults::secret($id) || !SettingDefaults::plainInsert($insert);     // a pwd field holds a hash
            $app    = ValueCodec::bytes((array_values($of_app[$id] ?? [])[0] ?? [])['value'] ?? '');     // a translation when the value has none
            $app    = (($app !== '') && $secret) ? '••••••' : $app;         // as the export never quotes them
            $help   = ValueCodec::bytes($row['input_help'] ?? '');
            $for    = 'installapp-set-' . safe($old . $id);
            $name   = 'setting[' . safe($old) . '][' . safe($id) . ']';
            $typed  = $choices->settings[$old][$id] ?? '';
            if (!SettingDefaults::plainInsert($insert)) {
                $input = '<span class="tabhlp">' . _m('The field changes the value when it is saved - fill it in the slice after installing.') . '</span>';
            } elseif ($insert === 'boo') {
                $input = '<select name="' . $name . '" id="' . $for . '"><option value="">' . safe(_m('keep the value of the app')) . '</option>';
                foreach (['1' => _m('Yes'), '0' => _m('No')] as $v => $label) {
                    $input .= '<option value="' . $v . '"' . (($typed === (string) $v) ? ' selected' : '') . '>' . safe($label) . '</option>';
                }
                $input .= '</select>';
            } else {
                $attrs = $secret ? ' type="password" autocomplete="new-password"' : ' type="text"' . ((SettingDefaults::flag($row, $schema) & FLAG_TEXT_STORED) ? '' : ' inputmode="numeric"');
                $input = '<input' . $attrs . ' name="' . $name . '" id="' . $for . '" value="' . safe($typed) . '" size="30" placeholder="' . safe(_m('keep the value of the app')) . '">';
            }
            $html  .= '<tr><td><label for="' . $for . '">' . safe(ValueCodec::bytes($row['name'] ?? '') ?: $id) . '</label>'
                . (((int) ValueCodec::bytes($row['required'] ?? '0')) ? ' <span class="aa-required">*</span>' : '') . '<br><small><code>' . safe($id) . '</code></small>'
                . (($help !== '') ? '<div class="tabhlp">' . safe($help) . '</div>' : '') . '</td>'
                . '<td>' . (($app === '') ? '<span class="tabhlp">' . _m('(empty)') . '</span>' : '<code>' . safe(mb_strimwidth($app, 0, 200, '…')) . '</code>') . '</td>'
                . '<td>' . $input . '</td></tr>';
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
        echo '<div class="aa-info">' . _m('Do not edit the source while the transfer runs. The items then go in batches in the task queue of this AA - the progress page only shows them, it may be closed.') . '</div>';
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
    if (!$run->chose($into) || !hash_equals($run->choicesSum(), $sum)) {       // before the source is read again
        echo '<div class="aa-err">' . _m('The choice changed since the preview - preview again.') . '</div>';
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
        $report = (new Importer(new DbImportTarget(), $uid))->write($prepared['plan'], $run_id, $prepared['xml'], $confirm, $sum);
    } catch (ImportFailed $e) {
        $now = InstallRun::get($run_id);
        if (($now !== null) && ($now->user() === $uid) && ($now->state() !== InstallRun::PREVIEW)) {
            installapp_progress($run_id, $uid);         // another request of this preview wrote it
            return;
        }
        if (($now !== null) && !hash_equals($now->choicesSum(), $sum)) {       // another preview of the run meanwhile
            echo '<div class="aa-err">' . _m('The choice changed since the preview - preview again.') . '</div>';
            return;
        }
        echo '<div class="aa-err">' . safe(installapp_error(_m('Writing the app'), $e)) . '</div>';
        return;
    }
    installapp_report($report, $prepared['package']);
    if ($run->mode() === InstallRun::TRANSFER) {
        installapp_progress($run_id, $uid);
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

/** the slices with settings fields left empty, linked to the settings page of the slice; the names are
 *  package text @param list<array{name: string, fields: array<string, string>, url: string}> $settings */
function installapp_settings(array $settings): void
{
    if (!$settings) {
        return;
    }
    echo '<div class="aa-installapp-code"><h4>' . _m('Slice settings to fill in') . '</h4><p>'
        . safe(_m('Some slices have settings - values that hold for the whole slice, not for single items (an address, a version, a switch shown on the pages). These got no value: neither the app nor you gave one, so the pages that show them show nothing.'))
        . '</p><p><strong>' . _m('What to do') . ':</strong> '
        . safe(_m('Fill them in by the links below, or later: in the slice open Setting in the menu of the item manager.'))
        . '</p><ul>';
    foreach ($settings as $s) {
        $names = implode(', ', array_map(static fn(string $id, string $name): string => ($name !== '') ? $name : $id, array_keys($s['fields']), $s['fields']));
        echo '<li>' . safe($s['name']) . ': ' . safe($names) . ' - ' . a_href(get_aa_url($s['url']), safe(_m('fill them in'))) . '</li>';
    }
    echo '</ul></div>';
}

/** findings by level, a level by code - a security code with what it means and what to check; every
 *  text is package text
 *  @param list<array{string, LintFinding}> $findings @param Package|null $package names the places
 *  @param bool $conditions the findings of the item conditions: their groups say so and are open */
function installapp_findings(array $findings, ?Package $package = null, bool $conditions = false): void
{
    $levels = FindingText::levels();
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
        echo '<details' . (($conditions || in_array($level, [LintFinding::SECURITY, LintFinding::ERROR], true)) ? ' open' : '') . '><summary>' . $label . ($conditions ? ' ' . _m('in conditions') : '') . ' - ' . array_sum(array_map('count', $by_code)) . '</summary>'
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

/** the progress of a transfer - for the user who started it and for any superadmin, who may end it; its
 *  report when it is finished */
function installapp_progress(string $run_id, string $uid): void
{
    $run = InstallRun::get($run_id);
    if (($run === null) || !$run->mayControl($uid)) {
        echo '<div class="aa-err">' . _m('No such install run of yours.') . '</div>';
        return;
    }
    if (($run->state() === InstallRun::PREVIEW) && ($run->source() !== InstallRun::LOCAL) && ($run->user() === $uid)) {
        installapp_choose($run);           // logged in to the other AA, the app not chosen or not written yet
        return;
    }
    if ($run->state() === InstallRun::PREVIEW) {
        echo '<div class="aa-info">' . _m('This app was previewed, but not installed yet.') . ' ' . a_href(installapp_url(), _m('Preview it again')) . '</div>';
        return;
    }
    if ($run->mode() !== InstallRun::TRANSFER) {
        echo '<p>' . (in_array($run->state(), [InstallRun::ITEMS, InstallRun::DONE], true) ? _m('The app is installed.') : safe(installapp_state($run->state()))) . '</p>';
        return;
    }
    if ($run->state() === InstallRun::DONE) {
        installapp_check($run);
        return;
    }
    $names  = installapp_slice_names($run);
    $counts = $run->sourceCounts();
    $states = [InstallRun::ERROR, InstallRun::DONE, InstallRun::ENDED];
    // {x} and the like are filled in by the page
    $texts  = [
        'states'     => array_combine($states, array_map('installapp_state', $states)),
        'running'    => _m('Transferring a batch…'),
        'queued'     => _m('Waiting for the task queue…'),
        'waiting'    => _m('The source failed (%1 of %2 tries) - the next try at %3', ['{n}', '{m}', '{t}']),
        'stuck'      => _m('No batch has come for a long time - is the task queue of this AA running? Continue tries at once.'),
        'writtenOf'  => _m('Written %1 of %2 items (%3 %)', ['{x}', '{y}', '{p}']),
        'written'    => _m('Written %1 items', ['{x}']),
        'moved'      => _m('Last progress: %1', ['{t}']),
        'lost'       => _m('This page lost the connection to the server - the transfer goes on without it. Asking again…'),
        'busy'       => _m('A batch may still be working - press Continue again in a few minutes.'),
        'loginFirst' => _m('Log in to the source AA again, then press Continue.'),
        'confirmEnd' => _m('End the transfer? The items written so far stay.'),
        'login'      => _m('The session has expired - log in again and reload this page.'),
        'more'       => _m('and %1 more', ['{n}']),
    ];
    echo '<div class="aa-info">' . _m('Do not edit the source while the transfer runs. The batches go in the task queue of this AA - this page only shows them and may be closed.') . '</div>';
    echo '<div id="installapp-progress" class="aa-installapp-progress" data-url="' . safe(installapp_url()) . '" data-report="' . safe(installapp_url(['run' => $run_id])) . '" data-token="' . safe(FormToken::get(INSTALLAPP_TOKEN)) . '"'
        . ' data-run="' . safe($run_id) . '" data-tries="' . BatchRun::MAX_FAILURES . '" data-places="' . INSTALLAPP_PLACES . '" data-texts="' . safe(installapp_js_data($texts)) . '"'
        . ' data-initial="' . safe(installapp_js_data((new TransferText($names))->page(TransferProgress::of($run, $uid)))) . '">';
    echo '<p><strong data-role="total"></strong><br><progress data-role="bar" hidden></progress></p>'
        . '<p><strong data-role="state" aria-live="polite">' . safe(installapp_state($run->state())) . '</strong> <span class="tabhlp" data-role="moved"></span></p>'
        . '<div class="aa-err" data-role="error" hidden></div>';
    if ($run->source() !== InstallRun::LOCAL) {        // the source refused its token, or it expired: Continue logs in again
        echo '<fieldset data-role="login" hidden><legend>' . _m('Log in to the source AA again') . '</legend><table>' . installapp_login_rows() . '</table></fieldset>';
    }
    echo '<p><button type="button" class="btn-l" data-role="continue" hidden>' . _m('Continue') . '</button> <button type="button" class="btn-l btn-secondary" data-role="end" hidden>' . _m('End') . '</button></p>';
    echo '<table class="aa-installapp-findings"><tr><th>' . _m('Slice') . '</th><th class="aa-num">' . _m('Written') . '</th><th class="aa-num">' . _m('On the source') . '</th></tr>';
    foreach ($names as $id => $name) {
        echo '<tr><td>' . safe($name) . '</td><td class="aa-num" data-slice="' . safe((string) $id) . '">0</td><td class="aa-num">' . (isset($counts[$id]) ? (int) $counts[$id] : '?') . '</td></tr>';
    }
    echo '</table><ul class="aa-installapp-notes" data-role="notes"></ul></div>';
    installapp_script();
}

/** source slice => the name of the slice its items go into; none before the definitions are written
 *  @return array<string, string> */
function installapp_slice_names(InstallRun $run): array
{
    if ($run->state() === InstallRun::PREVIEW) {
        return [];          // no id map yet
    }
    $map   = $run->idMap();
    $names = [];
    foreach ($run->slices() as $id) {
        $names[$id] = AA_Module::getModuleName($map->module($id) ?? '') ?: $id;
    }
    return $names;
}

/** JSON for a data attribute - safe() it @param array<string, mixed> $data */
function installapp_js_data(array $data): string
{
    return (string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
}

/** the report of a finished transfer, as its finish stored it, with what its batches found - the queue
 *  may have run them with no page open */
function installapp_check(InstallRun $run): void
{
    $check = $run->report() + ['checked' => 0, 'slices' => [], 'findings' => []];
    $page  = (new TransferText(installapp_slice_names($run)))->page(['warnings' => $run->warnings(), 'findings' => $run->findings(), 'afterClose' => $run->cachesLeft()]);
    echo '<div class="aa-ok">' . _m('The transfer is finished. Checked at %1:', [date('Y-m-d H:i', (int) $check['checked'])]) . '</div><table class="aa-installapp-findings"><tr><th>' . _m('Slice') . '</th><th class="aa-num">' . _m('Written') . '</th><th class="aa-num">' . _m('On the source') . '</th><th class="aa-num">' . _m('On this AA') . '</th></tr>';
    foreach ($check['slices'] as $s) {
        echo '<tr><td>' . a_href(AA_Module::getModuleUrl($s['target'], 'admin/index.php'), safe(AA_Module::getModuleName($s['target']) ?: $s['target'])) . '</td><td class="aa-num">' . $s['written'] . '</td><td class="aa-num">' . (($s['sourceCount'] === null) ? '?' : (int) $s['sourceCount']) . '</td><td class="aa-num">' . $s['targetCount'] . '</td></tr>';
    }
    echo '</table>';
    installapp_notes([...$page['found'], ...$page['notes']]);
    installapp_findings(array_map(static fn(array $f): array => [(string) $f['path'], new LintFinding((string) $f['level'], (string) $f['code'], (string) $f['ref'], 0)], $check['findings']));
    echo '<p>' . _m('Uploaded files are not transferred - copy them by hand. Switch the domain to the new site only after checking it.') . '</p>';
}

/** the notes of TransferText::page() as the progress page shows them, the most dangerous first
 *  @param list<array{level: string, title: string, count: int, places: list<array{string, int}>, lines: list<string>}> $notes */
function installapp_notes(array $notes): void
{
    if (!$notes) {
        return;
    }
    $rank = array_flip(array_keys(FindingText::levels()));
    usort($notes, static fn(array $a, array $b): int => ($rank[$a['level']] ?? 9) <=> ($rank[$b['level']] ?? 9));
    echo '<ul class="aa-installapp-notes">';
    foreach ($notes as $n) {
        $places = array_map(static fn(array $p): string => "{$p[0]} ({$p[1]}×)", $n['places']);
        $more   = count($places) - INSTALLAPP_PLACES;
        $lines  = [implode(', ', array_slice($places, 0, INSTALLAPP_PLACES)) . (($more > 0) ? ' ' . _m('and %1 more', [$more]) : ''), ...$n['lines']];
        echo '<li><strong>' . safe("{$n['title']} - {$n['count']}×") . '</strong>' . implode('', array_map(static fn(string $l): string => '<div>' . safe($l) . '</div>', array_filter($lines, static fn(string $l): bool => $l !== ''))) . '</li>';
    }
    echo '</ul>';
}

/** the progress page asks for the state of the run every few seconds while the queue moves it, more
 *  seldom while it waits; Continue and End answer with the state too */
function installapp_script(): void
{
    echo <<<'HTML'
<script>
(() => {
    const box = document.getElementById('installapp-progress');
    const el = role => box.querySelector(`[data-role="${role}"]`);
    const t = JSON.parse(box.dataset.texts);
    const LIVE = 3000;          // a batch moves the counts
    const IDLE = 20000;         // an error, a retry minutes away
    const LEVELS = ['S', 'E', 'W', 'I'];
    const PLACES = Number(box.dataset.places);
    const fill = (text, values) => text.replace(/\{(\w)\}/g, (all, key) => values[key] ?? all);
    const login = el('login');
    let s = JSON.parse(box.dataset.initial);
    let busy = false;           // Continue or End on its way
    let lost = 0;               // questions in a row without an answer
    let timer = 0;
    let acts = 0;               // Continue and End sent - an older answer of the poll must not undo theirs

    const written = () => Object.values(s.written || {}).reduce((sum, n) => sum + n, 0);
    const when = unix => {
        const d = new Date(1000 * unix);
        return (d.toDateString() === new Date().toDateString()) ? d.toLocaleTimeString() : d.toLocaleString();
    };
    const open = () => ['items', 'error'].includes(s.state);
    const stateText = () => {
        if (s.state !== 'items') return t.states[s.state] || s.state;
        if (s.running) return t.running;
        if (s.waiting) return fill(t.waiting, {n: s.failures, m: box.dataset.tries, t: when(s.nextAt)});
        return s.stuck ? t.stuck : t.queued;
    };
    const paint = () => {
        const x = written();
        el('total').textContent = s.total ? fill(t.writtenOf, {x: x.toLocaleString(), y: s.total.toLocaleString(), p: Math.min(100, Math.floor(100 * x / s.total))})
            : fill(t.written, {x: x.toLocaleString()});
        el('bar').hidden = !s.total;
        if (s.total) Object.assign(el('bar'), {max: s.total, value: x});
        el('state').textContent = stateText();
        el('moved').textContent = s.moved ? fill(t.moved, {t: when(s.moved)}) : '';
        el('continue').hidden = busy || !s.mayContinue;
        el('end').hidden = busy || !s.mayEnd;
        if (login) login.hidden = !(s.login && s.mayContinue);
    };
    const say = text => {
        el('error').textContent = text;
        el('error').hidden = !text;
    };
    const note = n => {
        const places = n.places.map(([place, k]) => `${place} (${k}×)`);
        const more = places.length - PLACES;
        const lines = [places.slice(0, PLACES).join(', ') + ((more > 0) ? ` ${fill(t.more, {n: more})}` : ''), ...n.lines];
        const li = document.createElement('li');
        li.append(Object.assign(document.createElement('strong'), {textContent: `${n.title} - ${n.count}×`}),
            ...lines.filter(Boolean).map(line => Object.assign(document.createElement('div'), {textContent: line})));
        return li;
    };
    const show = next => {
        s = next;
        for (const [slice, n] of Object.entries(s.written || {})) {
            const cell = box.querySelector(`td[data-slice="${CSS.escape(slice)}"]`);
            if (cell) cell.textContent = n.toLocaleString();
        }
        const said = {busy: t.busy, login: t.loginFirst}[s.status];
        say([s.error, said].filter(Boolean).join(' - '));
        el('notes').replaceChildren(...[...s.found, ...s.notes].sort((a, b) => LEVELS.indexOf(a.level) - LEVELS.indexOf(b.level)).map(note));
        paint();
        if (s.state === 'done') location.href = box.dataset.report;
    };
    const post = async (action, more = {}) => {
        const body = new URLSearchParams({action, run: box.dataset.run, token: box.dataset.token, ...more});
        // a connection dropped silently would keep the page waiting for ever; Continue may log in to the source first
        const response = await fetch(box.dataset.url, {method: 'POST', body, credentials: 'same-origin', headers: {Accept: 'application/json'}, signal: AbortSignal.timeout?.((action === 'continue') ? 180000 : 60000)});
        let data = null;
        try {
            data = JSON.parse(await response.text());
        } catch {
            // a login page after the session expired
            throw Object.assign(new Error(response.ok ? t.login : `HTTP ${response.status}`), {answered: response.ok});
        }
        if (!response.ok || !data.state) throw Object.assign(new Error(data.message || `HTTP ${response.status}`), {answered: true});
        return data;
    };
    const ask = () => {
        clearTimeout(timer);
        if (!open()) return;
        // after a lost answer less often, up to a minute
        const wait = lost ? Math.min(60000, LIVE * 2 ** lost) : ((s.state === 'items') && !s.waiting) ? LIVE : IDLE;
        timer = setTimeout(async () => {
            if (busy) return ask();
            const sent = acts;
            try {
                const next = await post('state');
                if (sent === acts) show(next);
                lost = 0;
            } catch (e) {
                lost++;
                say(e.answered ? e.message : t.lost);
            }
            ask();
        }, wait);
    };
    const act = async (action, more) => {
        acts++;
        busy = true;
        paint();
        try {
            show(await post(action, more));
        } catch (e) {
            say(e.message);
        }
        busy = false;
        paint();
        ask();
    };
    el('continue').addEventListener('click', () => {
        const more = {};
        if (login && !login.hidden) {
            for (const input of login.querySelectorAll('input')) {
                more[input.name] = input.value;
                if (input.type === 'password') input.value = '';
            }
        }
        act('continue', more);
    });
    el('end').addEventListener('click', () => {
        if (confirm(t.confirmEnd)) act('end');
    });
    show(s);
    ask();
})();
</script>
HTML;
}
