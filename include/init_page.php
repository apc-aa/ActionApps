<?php

/**
 * Basic script to be included into every Control Panel script
 *
 * @param $no_slice_id - Used during creation of any module. Tells init_page
 *                     no slice ID is yet defined. Replaces both $Add_slice
 *                     and $New_slice used before.
 * @param $slice_id  - The same as $change_id.
 * @param $change_id - Change to another slice / module. If $change_id == session
 *                   stored $slice_id, it is ignored.
 *
 * WARNING: The variable slice_id (p_slice_id respectively)
 *          does not hold just id of slices, but it may
 *          hold id of any module. The name slice_id comes from
 *          history, when there was no other module than slice.
 *
 * PHP version 7.2+
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
 * @author    Honza Malik, Jakub Adamek, Econnect
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

use AA\Doit\RequestMonitor;
use AA\Util\Langs;

// Request inputs used by this bootstrap. Everything else an including page needs it reads
// for itself: the $$k = AddslashesDeep($v) register_globals emulation that used to seed the
// global scope here was removed 2026-09 (finding N14), together with the AA_REGISTER_GLOBALS
// gate that gated it. The flags below ($no_slice_id, $save_hidden, $unset_r_hidden,
// $require_default_lang) are therefore what their docblock always said they were - messages
// set by the including page before the require, never request parameters.
$encap   ??= $_REQUEST['encap'] ?? false;
$free    ??= $_REQUEST['free'] ?? false;
$freepwd ??= $_REQUEST['freepwd'] ?? '';   // password for the $free anonymous login below

if ($encap === "false") {   // used in itemedit for anonymous form
    $encap = false;
}

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/mgettext.php";

if ($free) {
    // message for locauth.php to not display loginform
    $nobody = true;
}

require_once __DIR__ . "/locauth.php";

(new RequestMonitor())->record();


// Save before getting the session stored variables
// At least it does sanity check.
//                               AA::$module_id - could be set on new Alerts module - see AlertsModeditAfterInsert()
if (!is_long_id(AA::$module_id = AA::$module_id ?: $_POST['change_id'] ?: $_GET['change_id'] ?: $_POST['module_id'] ?: $_GET['module_id'] ?: $_POST['slice_id'] ?: $_GET['slice_id'])) {
    AA::$module_id = '';
}

// Load the session stored variables.
pageOpen($nobody ? 'nobody' : '');

// anonymous login
if ($nobody) {
    $_POST['username'] = $free;
    $_POST['password'] = $freepwd;
    if (!$auth->auth_validatelogin()) {
        echo _m("Either your username or your password is not valid");
        exit;
    }
}

// relogin if requested
if ($_GET['relogin']) {
    $auth->relogin();
}

if ($no_slice_id) {
    unset($slice_id);
    AA::$module_id = null; // unset is_a not allowed for static variables
}
$slice_id      = AA::$module_id;   // @deprecated - use AA::$module_id instead

require_once __DIR__ . "/util.php";  // must be after language include because of lang constants in util.php
require_once __DIR__ . "/event.class.php";
require_once __DIR__ . "/slice.class.php";  // AA_Moduleregistry

// array of variables - used to transport variables between pages (instead of dangerous hidden tag)
$sess->register('r_hidden');

// sometimes we need to not unset hidden - popup for related stories ...
// only acceptor can read values. For others, they are destroyed.
$my_document_uri = $_SERVER['DOCUMENT_URI'] ?: $_SERVER['PHP_SELF'];
if (!$save_hidden && ($unset_r_hidden || $r_hidden["hidden_acceptor"] != $my_document_uri)) {
    unset($r_hidden);
}
$after_login = !$no_slice_id && !AA::$module_id;
$perm_slices = GetUserSlices();

if (!$no_slice_id && !IsSuperadmin() && !$perm_slices[AA::$module_id] && !$after_login) {
    MsgPage(_m("You do not have permission to edit items in the slice:") . ' ' . AA_Slice::getModuleNameSafe(AA::$module_id));
    exit;
}

if (!is_object($db)) {
    $db = getDB();
}

// Create g_modules: a global array which holds user editable modules
$db->query("SELECT id, name, type, deleted FROM module ORDER BY priority, name");
while ($db->next_record()) {
    $my_slice_id = unpack_id($db->f('id'));
    if (IsSuperadmin() || (!$db->f('deleted') && $perm_slices[$my_slice_id])) {
        $g_modules[$my_slice_id] = [
            'name' => $db->f('name'),
            'type' => AA_Moduleregistry::singleton()[$db->f('type')] ? $db->f('type') : 'S',   // @todo remove type and use AA_Module::getModuleType() or AA_Module::getModuleInfo() instead
        ];
    }
}



if (!$no_slice_id) {
    if (!is_array($g_modules)) {
        $auth->auth_loginform('<div style="color:red;"><b>' . _m("No slice found for you") . '</b></div>');
        //$auth->relogin();
        exit;
    }


    if ($after_login || !$g_modules[AA::$module_id]) {
        // no module id, or one the user can't access (e.g. just deleted) - prefer the page's own module type
        $script        = substr((string) realpath(get_included_files()[0]), strlen(dirname(__DIR__)) + 1);
        $page_type     = AA_Moduleregistry::typeOfScript($script);
        AA::$module_id = AA_Module::getFallbackModuleId($g_modules, $page_type);
        // nothing of this page's type - go to the fallback module's own page rather than run e.g. alerts/modedit.php on a slice
        if ($g_modules[AA::$module_id]['type'] !== $page_type) {
            go_url(AA_Module::getModuleUrl(AA::$module_id));
        }
    }

    $p_slice_id = q_pack_id(AA::$module_id);
    $slice_id   = AA::$module_id;

    $db->query("SELECT * FROM module WHERE id='$p_slice_id'");
    $db->next_record();

    // These variables have names of $r_ but are not session stored
    // because this is unnecessary: their evaluation is very fast.
    $r_lang_file      = $db->f('lang_file');
    $r_slice_view_url = $db->f('slice_url');

    //    $module_type         = $g_modules[AA::$module_id]['type'];
    //
    //    $module_type_changed = $after_login || ($g_modules[$r_last_module_id]['type'] != $module_type);
    //
    //    //$module_type_changed = $after_login || ($_GET['from_type'] != $module_type);  // from_type used on slice selectbox in admin interface
    //
    ///* If we switch to another module type, we try whether the requested file
    //   exists in the module direcory and if not, we go to module's index.php page.
    //
    //   Discussion: There is a chance two modules will have the same page name
    //   for very different behavior and that this would be a bit confusing when
    //   using the Select Slice box.
    //*/
    //    if ( $module_type_changed && !$jumping ) {
    //        $page    = pathinfo( $_SERVER['PHP_SELF'], PATHINFO_BASENAME);
    //        $hdd_dir = "../".$MODULES[$module_type]['directory'];
    //        $web_dir = AA_INSTAL_PATH   .$MODULES[$module_type]['directory'];
    //        if (!file_exists($hdd_dir.$page) || ($page==='tabledit.php') || ($module_type==='J') ) {
    //            $page = "index.php";
    //        }
    //        if ($web_dir.$page != $_SERVER['PHP_SELF']) {
    //            $page = StateUrl($web_dir.$page."?slice_id=$slice_id");
    //            Session::page_close();
    //            go_url($page);
    //            exit;
    //        }
    //    }
}

// freeDB($db);   we left it open for /admin/ scripts  - otherwise we have to ask getDB() for every admin page (which is OK as well)

// Per-user UI language: on a fresh browser seed the override cookie from the
// AA_ID-scoped profile, then let it swap the language while keeping the module's
// section. No override (or a non-admin request) -> the module's own lang_file,
// exactly as before.
if (AuthGetUid()) {
    \AA\Util\UiPrefs::seedCookiesFromProfile(AuthGetUid());
}
$module_lang_file = (!$require_default_lang && $r_lang_file != "") ? $r_lang_file : DEFAULT_LANG_INCLUDE;
$mgettext_file    = \AA\Util\UiPrefs::langOverrideFile($module_lang_file);
bind_mgettext_domain($mgettext_file);
AA::$lang         = strtolower(substr($mgettext_file, 0, 2));      // actual language - two letter shortcut cz / es / en
AA::$langnum      = [Langs::getLangName2Num(AA::$lang)]; // array of prefered languages in priority order.
AA::$slice_id     = AA::$module_id;
