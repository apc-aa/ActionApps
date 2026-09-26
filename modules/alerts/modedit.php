<?php

/**
 * Alerts admin: Settings.
 *
 * Core settings of an Alerts module. Edits the module row (name, form URL,
 * language, owner, deleted) via the shared include/modutils.php helpers, plus
 * the per-module alerts_collection satellite (welcome/alert email templates).
 * Also handles creating a brand new Alerts module (no_slice_id=1 from
 * admin/slicedit.php). Mirrors modules/polls/modedit.php and replaces the
 * legacy tabledit `modedit` table view.
 *
 * @package Alerts
 * @author Jakub Adamek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;
use AA\Util\Langs;

// Creating a module: the insert posts no_slice_id back as a hidden field, and init_page.php must
// know on the POST too, or it falls back to a slice. Not $_REQUEST - that can include cookies.
if ($_GET['no_slice_id'] ?? $_POST['no_slice_id'] ?? null) {
    $no_slice_id = true;       // message for init_page.php (no existing slice yet)
}

$directory_depth = "../";
require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/modutils.php";
require_once __DIR__ . "/../../include/perm_core.php";
require_once __DIR__ . "/util.php";

try {
    $name            = InputVars::stringPOST('name');
    $slice_url       = InputVars::stringPOST('slice_url');
    $lang_file       = InputVars::stringPOST('lang_file');
    $priority        = InputVars::intPOST('priority');
    $deleted         = InputVars::boolPOST('deleted');
    $emailid_welcome = InputVars::intPOST('emailid_welcome');
    $emailid_alert   = InputVars::intPOST('emailid_alert');
    $owner           = InputVars::idPOST('owner');           // empty for new owner
    $new_owner       = InputVars::stringPOST('new_owner');
    $new_owner_email = InputVars::stringPOST('new_owner_email');
    $no_slice_id     = InputVars::boolGETPOST('no_slice_id');   // POST too - $is_add below, and the gate above
    $insert          = InputVars::boolPOST('insert');
    $update          = InputVars::boolPOST('update');
    $cancel          = InputVars::boolPOST('cancel');

    $Msg = InputVars::messageGETPOST();
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

if ($cancel) {
    go_url(StateUrl(self_base() . "index.php"));
}

$err         = [];
$superadmin  = IsSuperadmin();
$module_id   = AA::$module_id;
$is_add      = $no_slice_id || $insert;   // adding a new Alerts module

if ($is_add) {
    if (!CheckPerms(AuthGetUid(), "aa", AA_ID, PS_ADD)) {
        MsgPage(_m("You do not have permissions to add slice"));
        exit;
    }
} elseif (!IfSlPerm(PS_USERS)) {
    MsgPage(_m("You do not have permissions to edit this Alerts module"));
    exit;
}

if ($insert || $update) {
    do {
        if (!$owner) {  // insert new owner
            if (!($owner = CreateNewOwner($new_owner, $new_owner_email, $err, new CVarset()))) {
                break;
            }
        }

        ValidateModuleFields($name, $slice_url, $priority, $lang_file, $owner, $err);
        $deleted = ($deleted ? 1 : 0);
        if (count($err)) {
            break;
        }

        // module table (also runs AddPermObject() on insert)
        $module_id = WriteModuleFields(($update && $module_id) ? $module_id : null, $superadmin, 'Alerts', $name, $slice_url, $priority, $lang_file, $owner, $deleted);
        if (!$module_id) {
            break;
        }
        AA::$module_id = $module_id;

        // alerts_collection satellite (1:1 by module_id)
        if ($update && DB_AA::test('alerts_collection', [['module_id', $module_id, 'l']])) {
            DB_AA::update(
                'alerts_collection',
                [['emailid_welcome', $emailid_welcome, 'i'], ['emailid_alert', $emailid_alert, 'i']],
                [['module_id', $module_id, 'l']],
            );
        } else {
            $cv = new CVarset();
            $cv->set('id', new_collection_id(), 'text');
            $cv->set('module_id', $module_id, 'unpacked');
            $cv->set('emailid_welcome', $emailid_welcome, 'number');
            $cv->set('emailid_alert', $emailid_alert, 'number');
            $cv->doInsert('alerts_collection');
        }

        AA::Pagecache()->invalidateFor($module_id);  // invalidate cached values for this slice
    } while (false);

    if (!count($err)) {
        if ($insert) {   // brand new Alerts module
            go_url(StateUrl(self_base() . "index.php"));
            exit;
        }
        go_return_or_url(self_base() . "modedit.php", 0, 0);
    }
}

// And the form -----------------------------------------------------

$source_id     = $module_id;
$collection_id = '';

if (!count($err) && !$is_add && $module_id) {   // fresh edit - load current collection values
    $coll            = (array) DB_AA::select1('', "SELECT id, emailid_welcome, emailid_alert FROM alerts_collection", [['module_id', $module_id, 'l']]);
    $collection_id   = $coll['id'] ?? '';
    $emailid_welcome = $coll['emailid_welcome'] ?? 0;
    $emailid_alert   = $coll['emailid_alert'] ?? 0;
}

if ($is_add) {
    $form_buttons = ['insert', 'no_slice_id' => ['type' => 'hidden', 'value' => '1']];
} else {
    $form_buttons = ['update', 'update' => ['type' => 'hidden', 'value' => '1']];
}

$apage = new AA_Adminpageutil('admin', 'settings');
$apage->setTitle($is_add ? _m("Add Alerts") : _m("Alerts Settings"));
$apage->printHead($err, $Msg);

FrmTabCaption(_m("Alerts Settings"), $form_buttons);

$marr = RenderModuleFieldsForm($source_id, $superadmin, $is_add, (bool) count($err), compact('name', 'slice_url', 'priority', 'lang_file', 'owner', 'deleted'), $new_owner, $new_owner_email);
$lang_file = rtrim((string) ($marr[3] ?? ''), '3');   // trim old php3 lang-file extension

FrmInputSelect("lang_file", _m("language"), Langs::getFiles('alerts'), $lang_file);

// alerts-specific - email templates (per-module, see emails.php).
// The link to edit the currently-selected template is shown as the widget's help.
FrmTabSeparator(_m("Alert emails"));

$welcome_opts = alertsEmailOptions("alerts welcome", (int) $emailid_welcome);
$welcome_hlp  = (int) $emailid_welcome && isset($welcome_opts[(int) $emailid_welcome])
    ? '<a href="' . StateUrl(self_base() . "emails.php?id=" . (int) $emailid_welcome) . '">' . _m("Edit '%1'", [safe($welcome_opts[(int) $emailid_welcome])]) . '</a>'
    : '';
FrmInputSelect("emailid_welcome", _m("welcome email"), $welcome_opts, $emailid_welcome, false, $welcome_hlp);

$alert_opts = alertsEmailOptions("alerts alert", (int) $emailid_alert);
$alert_hlp  = (int) $emailid_alert && isset($alert_opts[(int) $emailid_alert])
    ? '<a href="' . StateUrl(self_base() . "emails.php?id=" . (int) $emailid_alert) . '">' . _m("Edit '%1'", [safe($alert_opts[(int) $emailid_alert])]) . '</a>'
    : '';
FrmInputSelect("emailid_alert", _m("alert email"), $alert_opts, $emailid_alert, false, $alert_hlp);

if (!$is_add && $collection_id !== '') {
    FrmStaticText(_m("alerts ID"), safe($collection_id));
}

FrmTabEnd($form_buttons);

$apage->printFoot();
