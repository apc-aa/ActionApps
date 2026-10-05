<?php

/**
 * The app store: the apps another AA publishes, installed from there. This AA logs in by the public
 * account of the store (AA\Central\AppStore) - no password is asked - and keeps its short token in an
 * install run, as installapp.php does after a login. "Install" goes on in installapp.php (the preview of
 * that run). The store is AppStore::url() or a row of the Central list; its address never comes from the
 * request.
 *
 * GET               the choice of the store
 * POST connect      logs in to the store (source = store | <central_conf id>) and lists its apps
 * GET run=<id>      the apps of the store of an open run
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
use AA\Central\AppStore;
use AA\Central\InstallRun;
use AA\Central\Package\Importer;
use AA\Central\RemoteSource;
use AA\Central\SourceFailed;
use AA\Later\BatchFailed;
use AA\Util\FormToken;
use AA\Util\InputVars;

require_once __DIR__ . "/include/init_central.php";
require_once __DIR__ . "/../include/formutil.php";

const APPSTORE_TOKEN = 'central-appstore';

pageOpen();

try {
    $action = InputVars::stringPOST('action');
    $source = InputVars::stringPOST('source');
    $run_id = strtolower(InputVars::idGET('run'));
    $token  = InputVars::stringPOST('token');
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}
$uid = (string) AuthGetUid();

// the run keeps a token of another AA, and an app is code that runs with all rights here
if (!IsSuperadmin() || !Importer::hasInstallPermission($uid)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m('Only a superadmin may install apps from the app store.'), 'admin');
}
if (($_SERVER['REQUEST_METHOD'] === 'POST') && !FormToken::check(APPSTORE_TOKEN, $token)) {
    AA_Adminpageutil::MsgPageMenu(appstore_url(), _m('The form has expired; reload the page and try again.'), 'admin');
}

$apage = new AA_Adminpageutil('central', 'appstore');
$apage->setTitle(_m('App store'));
$apage->setForm();
$apage->addRequire('
    .aa-appstore { border-collapse: collapse; margin: 0.3em 0 1em; }
    .aa-appstore td, .aa-appstore th { padding: 0.3em 0.6em; text-align: left; vertical-align: top; border-bottom: 1px solid var(--clr-border, #ddd); }
    .aa-appstore form { margin: 0; }
', 'AA_Req_CSScode');
$apage->printHead();

if ($action === 'connect') {
    appstore_connect($source, $uid);
} elseif ($run_id !== '') {
    appstore_list($run_id, $uid);
} else {
    appstore_form();
}

$apage->printFoot();

// ---------------------------------------------------------------------------------------------------

function appstore_url(array $params = []): string
{
    return StateUrl(get_url(self_base() . 'appstore.php', $params));
}

/** the store: AppStore::url() by default, or an approved row of the Central list */
function appstore_form(): void
{
    $options = [InstallRun::STORE => _m('The app store') . ' - ' . AppStore::url()];
    foreach (Actionapps::getArray() as $id => $aa) {
        $options[$id] = $aa->getName();
    }
    echo '<form method="post" action="' . safe(appstore_url()) . '">' . FormToken::field(APPSTORE_TOKEN) . '<input type="hidden" name="action" value="connect">';
    FrmTabCaption(_m('App store'));
    FrmInputSelect('source', _m('Apps of'), $options, InstallRun::STORE, true, _m('This AA logs in to it by the public account of the app store; it lists the apps that AA publishes.'));
    FrmTabEnd();
    echo '<p><button class="btn-l" type="submit">' . _m('Show the apps') . '</button></p></form>';
}

/** Logs in to the store by the public account and opens an install run with its token. */
function appstore_connect(string $source, string $uid): void
{
    if (($source !== InstallRun::STORE) && !(ctype_digit($source) && Actionapps::listed((int) $source))) {
        echo '<div class="aa-err">' . _m('No such AA in the Central list.') . '</div>';
        appstore_form();
        return;
    }
    try {
        $remote = RemoteSource::forSource($source);
        $sealed = $remote->seal($remote->jwt(AppStore::PUBLIC_USER, AppStore::PUBLIC_PASSWORD));
    } catch (SourceFailed $e) {
        echo '<div class="aa-err">' . safe(($e->reason === SourceFailed::LOGIN) ? _m('That AA has no app store account (Optimize "App store account" creates it there), or it is too old.') : $e->getMessage()) . '</div>';
        appstore_form();
        return;
    }
    try {
        $run = InstallRun::preview(InstallRun::INSTALL, false, $source, $uid, $sealed, time());
    } catch (BatchFailed $e) {
        echo '<div class="aa-err">' . safe(_m('A table of this AA has no transactions (not InnoDB) - convert it in AA > Optimize: %1', [$e->where])) . '</div>';
        return;
    }
    appstore_apps($run);
}

/** the apps of the store of an open run of the user */
function appstore_list(string $run_id, string $uid): void
{
    $run = InstallRun::get($run_id);
    // the store installs only; a transfer run belongs to the progress page of installapp.php
    if (($run === null) || ($run->user() !== $uid) || ($run->state() !== InstallRun::PREVIEW) || ($run->mode() !== InstallRun::INSTALL) || ($run->source() === InstallRun::LOCAL)) {
        echo '<div class="aa-err">' . _m('This preview is no longer open - it was written already or forgotten.') . '</div>';
        appstore_form();
        return;
    }
    appstore_apps($run);
}

/** the apps with an Install button each - the preview of installapp.php for the run */
function appstore_apps(InstallRun $run): void
{
    try {
        $apps = $run->remoteSource()->apps();
    } catch (SourceFailed $e) {
        echo '<div class="aa-err">' . safe($e->getMessage()) . '</div>';
        appstore_form();
        return;
    }
    if (!$apps) {
        echo '<div class="aa-info">' . _m('That AA publishes no app.') . '</div>';
        return;
    }
    $install = StateUrl(get_url(self_base() . 'installapp.php'));
    echo '<table class="aa-appstore"><tr><th>' . _m('App') . '</th><th>' . _m('Version') . '</th><th>' . _m('Slices') . '</th><th>' . _m('Alerts') . '</th><th></th></tr>';
    foreach ($apps as $app) {         // the store's texts - escaped
        echo '<tr><td><strong>' . safe($app['name']) . '</strong>' . ($app['published'] ? '' : ' <small>(' . _m('not published') . ')</small>')
            . (($app['description'] !== '') ? '<div class="tabhlp">' . nl2br(safe($app['description'])) . '</div>' : '') . '</td>'
            . '<td>' . safe($app['version']) . '</td><td>' . (int) $app['slices'] . '</td><td>' . (int) $app['alerts'] . '</td>'
            . '<td><form method="post" action="' . safe($install) . '"><input type="hidden" name="token" value="' . safe(FormToken::get(InstallRun::FORM_TOKEN)) . '">'
            . '<input type="hidden" name="action" value="preview"><input type="hidden" name="run" value="' . safe($run->getId()) . '">'
            . '<input type="hidden" name="site" value="' . safe($app['id']) . '"><button class="btn" type="submit">' . _m('Install') . '</button></form></td></tr>';
    }
    echo '</table><p>' . _m('Install shows a preview first; nothing is written before you confirm it.') . '</p>';
}
