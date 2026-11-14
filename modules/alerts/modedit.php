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

use AA\Central\Package\Importer;
use AA\Central\ResponderPolicy;
use AA\IO\DB\DB_AA;
use AA\Util\FormToken;
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
require_once __DIR__ . "/alerts_sending.php";

const MODEDIT_ALERTS_TOKEN = 'aa-modedit-alerts';

/** Slices the user may choose as the statistics slice - those whose settings they may change - plus the
 *  stored one, which another admin may have chosen.
 *  @return array<string, string> long id => name */
function alertsStatSliceOptions(string $stored): array
{
    static $options = [];   // asked by the check of a submit and by the form after it
    if (isset($options[$stored])) {
        return $options[$stored];
    }
    $slices = GetUserSlices(PS_EDIT);   // 'all' for a superadmin
    $where  = [['type', 'S'], ['deleted', 0, 'i']];
    if (is_array($slices)) {
        $where[] = ['id', array_keys($slices) ?: [str_repeat('0', 32)], 'l'];
    }
    $ret = [];
    foreach (DB_AA::select([], 'SELECT id, name FROM module', $where, ['name']) as $row) {
        $ret[unpack_id($row['id'])] = $row['name'];
    }
    if (($stored !== '') && !isset($ret[$stored])) {
        $ret[$stored] = (string) DB_AA::select1('name', 'SELECT name FROM module', [['id', $stored, 'l']]);
    }
    return $options[$stored] = $ret;
}

try {
    $name            = InputVars::stringPOST('name');
    $slice_url       = InputVars::stringPOST('slice_url');
    $lang_file       = InputVars::stringPOST('lang_file');
    $priority        = InputVars::intPOST('priority');
    $deleted         = InputVars::boolPOST('deleted');
    $emailid_welcome = InputVars::intPOST('emailid_welcome');
    $emailid_alert   = InputVars::intPOST('emailid_alert');
    $stat_slice_id   = InputVars::idPOST('stat_slice_id');   // '' = nothing measured
    $owner           = InputVars::idPOST('owner');           // empty for new owner
    $new_owner       = InputVars::stringPOST('new_owner');
    $new_owner_email = InputVars::stringPOST('new_owner_email');
    $no_slice_id     = InputVars::boolGETPOST('no_slice_id');   // POST too - $is_add below, and the gate above
    $insert          = InputVars::boolPOST('insert');
    $update          = InputVars::boolPOST('update');
    $cancel          = InputVars::boolPOST('cancel');
    $token           = InputVars::stringPOST('token');

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
// Adding or editing is decided ONCE - for the permission check, for the processing gate and for the
// write target. They used to be derived separately, so a request carrying both "insert" and "update"
// passed the "add" check and then overwrote an existing module.
$is_add      = $no_slice_id || $insert;

if ($is_add) {
    if (!CheckPerms(AuthGetUid(), "aa", AA_ID, PS_ADD)) {
        MsgPage(_m("You do not have permissions to add slice"));
        exit;
    }
} elseif ($module_id && !AA_Module_Alerts::getOwnModule($module_id)) {   // an id of another module type
    MsgPage(_m("Module not found."));
    exit;
} elseif (!IfSlPerm(PS_USERS)) {
    MsgPage(_m("You do not have permissions to edit this Alerts module"));
    exit;
}

// the module changes only from this page's own form; the add-module page opens it by a GET without insert/update
$may_write = !ResponderPolicy::isForeignBrowserRequest($_SERVER) && FormToken::checkPost(MODEDIT_ALERTS_TOKEN, $token, $_SERVER);
$write     = $is_add ? $insert : $update;
$expired   = $write && !$may_write;
if ($expired) {
    $Msg = MsgErr(_m('The form has expired; reload the page and try again.'));   // on the page, not a passing popup
}

// SELECT *: until sql_update adds stat_slice_id, the page works without the statistics slice
$coll          = (!$is_add && $module_id) ? (array) DB_AA::select1([], 'SELECT * FROM alerts_collection', [['module_id', $module_id, 'l']]) : [];
$collection_id = (string) ($coll['id'] ?? '');
$has_stat      = $coll ? array_key_exists('stat_slice_id', $coll) : DB_AA::exists_column('alerts_collection', 'stat_slice_id');
$stored_stat   = ((string) ($coll['stat_slice_id'] ?? '') === '') ? '' : unpack_id($coll['stat_slice_id']);

if ($write && $may_write) {
    do {
        if (!$owner) {  // insert new owner
            if (!($owner = CreateNewOwner($new_owner, $new_owner_email, $err))) {
                break;
            }
        }

        ValidateModuleFields($name, $slice_url, $priority, $lang_file, $owner, $err);
        $deleted = ($deleted ? 1 : 0);
        // the stored slice is not checked again - a later change of it must not block the other settings
        if ($has_stat && ($stat_slice_id !== '') && ($stat_slice_id !== $stored_stat)) {
            if (!isset(alertsStatSliceOptions($stored_stat)[$stat_slice_id])) {
                $err[] = _m('You may not choose this statistics slice');
            } elseif ($refusal = AA_Collection::statSliceRefusal($stat_slice_id, $collection_id)) {
                $err[] = $refusal;
            }
        }
        if (count($err)) {
            break;
        }

        // module table (also runs AA::$perm->addPermObject() on insert)
        $module_id = WriteModuleFields($is_add ? null : ($module_id ?: null), $superadmin, 'Alerts', $name, $slice_url, $priority, $lang_file, $owner, $deleted);
        if (!$module_id) {
            break;
        }
        AA::$module_id = $module_id;

        // alerts_collection satellite (1:1 by module_id)
        $collection = ['emailid_welcome' => $emailid_welcome, 'emailid_alert' => $emailid_alert];
        if ($has_stat) {
            $collection['stat_slice_id'] = $stat_slice_id;
        }
        if (!$is_add && ($collection_id !== '')) {
            AA::Metabase()->doUpdate('alerts_collection', ['id' => $collection_id] + $collection);
        } else {
            AA::Metabase()->doInsert('alerts_collection', ['id' => new_collection_id(), 'module_id' => $module_id] + $collection);
        }

        AA::Pagecache()->invalidateFor($module_id);  // invalidate cached values for this slice
    } while (false);

    if (!count($err)) {
        if ($is_add) {   // brand new Alerts module
            go_url(StateUrl(self_base() . "index.php"));
            exit;
        }
        go_return_or_url(self_base() . "modedit.php", 0, 0);
    }
}

// And the form -----------------------------------------------------

$redisplay     = $expired || count($err);   // show the submitted values, not the stored ones
$source_id     = $module_id;

if (!$redisplay && !$is_add && $module_id) {   // fresh edit - load current collection values
    $emailid_welcome = $coll['emailid_welcome'] ?? 0;
    $emailid_alert   = $coll['emailid_alert'] ?? 0;
    $stat_slice_id   = $stored_stat;
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

$marr = RenderModuleFieldsForm($source_id, $superadmin, $is_add, $redisplay, compact('name', 'slice_url', 'priority', 'lang_file', 'owner', 'deleted'), $new_owner, $new_owner_email);
$lang_file = rtrim((string) ($marr[3] ?? ''), '3');   // trim old php3 lang-file extension
// the unsubscribe link is built on the list's URL, and AA_Collection::unsubscribeHeaders() needs it https
if (!AA_Collection::unsubscribeHeaders(get_url((string) ($marr[1] ?? ''), ['au' => 'x', 'c' => 'x']))) {
    FrmStaticText('', _m('The URL of this list is not https - the mails go without the one-click unsubscribe header.'));
}

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

if ($has_stat) {
    $stat_hlp = [_m('The slice where the opens and clicks of the mailings are counted; empty = nothing is measured. Each list needs its own slice.')];
    if ($superadmin && Importer::hasInstallPermission((string) AuthGetUid())) {
        $stat_hlp[] = '<a href="' . safe(AA_INSTAL_PATH . 'central/appstore.php') . '">' . _m('Install from the App store') . '</a>';
    }
    FrmTabSeparator(_m("Statistics"));
    FrmInputSelect("stat_slice_id", _m("Statistics slice"), alertsStatSliceOptions($stored_stat), $stat_slice_id, false, implode('<br>', $stat_hlp));
}

if (!$is_add && $collection_id !== '') {
    FrmStaticText(_m("alerts ID"), safe($collection_id));
}

echo FormToken::field(MODEDIT_ALERTS_TOKEN);
FrmTabEnd($form_buttons);

$apage->printFoot();
