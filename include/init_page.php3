<?php
/**
 * Basic script to be included into every Control Panel script
 *
 * @param $no_slice_id Used during creation of any module. Tells init_page
 *                     no slice ID is yet defined. Replaces both $Add_slice
 *                     and $New_slice used before.
 * @param $slice_id  The same as $change_id.
 * @param $change_id Change to another slice / module. If $change_id == session
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
 * @return array|string
 */

// ----- input variables normalization - start
// This code handles with "magic quotes" and "register globals" PHP (<5.4) setting
// It make us sure, taht
//  1) in $_POST,$_GET,$_COOKIE,$_REQUEST variables the values are not quoted
//  2) the variables are imported in global scope and is quoted
// We are trying to remove any dependecy on the point 2) and use only $_* superglobals
function AddslashesDeep($value)   { return is_array($value) ? array_map('AddslashesDeep',   $value) : addslashes($value);   }

foreach ($_REQUEST as $k => $v) {
    $$k = AddslashesDeep($v);
}
// ----- input variables normalization - end

if ($encap == "false") {   // used in itemedit for anonymous form
    $encap = false;        // it must be here, because the variable is rewriten (see above)
}

require_once __DIR__."/config.php3";
require_once __DIR__."/mgettext.php3";

if ($free) {
    // message for locauth.php3 to not display loginform
    $nobody = true;
}

require_once __DIR__."/locauth.php3";

// Save before getting the session stored variables
// Now it should not be in session so mayby the $pass_sliceid is not necessary and we van use just slice_id
// At least it do sanity check.
//                               AA::$module_id - could be set on new Alterts module - see AlertsModeditAfterInsert()
if (!is_long_id(AA::$module_id = AA::$module_id ?: $_POST['change_id'] ?: $_GET['change_id'] ?: $_POST['module_id'] ?: $_GET['module_id'] ?: $_POST['slice_id'] ?: $_GET['slice_id'])) {
    AA::$module_id = '';
}

// Load the session stored variables.
pageOpen($nobody ? 'nobody': '');

// anonymous login
if ($nobody) {
    $_POST['username'] = $free;
    $_POST['password'] = $freepwd;
    $auth->auth["uid"] = $auth->auth_validatelogin();
    if ( !$auth->auth["uid"] ) {
        echo _m("Either your username or your password is not valid.");
        exit;
    }
}

// relogin if requested
if ($_GET['relogin']) {
    $auth->relogin();
}

$slice_id      = AA::$module_id;   // @deprecated - use AA::$module_id instead

if ( $no_slice_id ) {
    unset($slice_id);
    AA::$module_id = null; // unset is_a not allowed for static variables
}

require_once __DIR__."/util.php3";  // must be after language include because of lang constants in util.php3
require_once __DIR__."/event.class.php3";

/* It is not a good idea to store $slice_id, it made some damage in AA
   installations already. But for historical reasons before somebody ensures
   that all Admin Panel modules send $slice_id each time, we leave it here.
   But if a script sends slice_id, the session-stored one is overrided
   (see $pass_sliceid above). */
// $sess->register("slice_id");
// array of variables - used to transport variables between pages (instead of dangerous hidden tag)
$sess->register('r_hidden');

// sometimes we need not to unset hidden - popup for related stories ...
// only acceptor can read values. For others they are destroyed.
$my_document_uri = $_SERVER['DOCUMENT_URI'] ? $_SERVER['DOCUMENT_URI'] : $_SERVER['PHP_SELF'];
if ( !$save_hidden AND ($unset_r_hidden OR $r_hidden["hidden_acceptor"] != $my_document_uri)) {
    unset( $r_hidden );
}
$after_login = !$no_slice_id && !AA::$module_id;
$perm_slices = GetUserSlices();

if ( !$no_slice_id AND !IsSuperadmin() AND !$perm_slices[AA::$module_id] AND !$after_login ) {
    MsgPage(StateUrl(self_base())."index.php3", _m("You do not have permission to edit items in the slice").": ".AA_Slice::getModuleName(AA::$module_id));
    exit;
}

is_object( $db ) || ($db = getDB());

$event        = new aaevent;

// Create g_modules: a global array which holds user editable modules
$db->query("SELECT id, name, type, deleted FROM module ORDER BY priority, name");
while ($db->next_record()) {
    $my_slice_id = unpack_id($db->f('id'));
    if (IsSuperadmin() OR ( !$db->f('deleted') AND $perm_slices[$my_slice_id] )) {
        $g_modules[$my_slice_id] = [
            'name' => $db->f('name'),
            'type' => $MODULES[$db->f('type')] ? $db->f('type') : 'S'
        ];
    }
}



if (!$no_slice_id) {
    if (!is_array($g_modules)) {
        $auth->auth_loginform('<div style="color:red;"><b>'._m("No slice found for you").'</b></div>');
        //$auth->relogin();
        exit;
    }


    if ($after_login OR !$g_modules[AA::$module_id]) {
        // slice was just deleted, thus is not in $g_modules
        foreach ($g_modules as $mid => $mmodule) {
            // skip AA Core Field slice, if possible
            if (($mmodule['type']=='S') AND ($mid <>  "41415f436f72655f4669656c64732e2e")) {
                AA::$module_id = $mid;
                break;
            }
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
//   exists in the module direcory and if not, we go to module's index.php3 page.
//
//   Discussion: There is a chance two modules will have the same page name
//   for very different behavior and that this would be a bit confusing when
//   using the Select Slice box.
//*/
//    if ( $module_type_changed && !$jumping ) {
//        $page    = pathinfo( $_SERVER['PHP_SELF'], PATHINFO_BASENAME);
//        $hdd_dir = "../".$MODULES[$module_type]['directory'];
//        $web_dir = AA_INSTAL_PATH   .$MODULES[$module_type]['directory'];
//        if (!file_exists($hdd_dir.$page) OR ($page=='tabledit.php3') OR ($module_type=='J') ) {
//            $page = "index.php3";
//        }
//        if ($web_dir.$page != $_SERVER['PHP_SELF']) {
//            $page = StateUrl($web_dir.$page."?slice_id=$slice_id");
//            page_close();
//            go_url($page);
//            exit;
//        }
//    }
}

$mgettext_file    = (!$require_default_lang AND ($r_lang_file != "")) ? $r_lang_file : DEFAULT_LANG_INCLUDE;
bind_mgettext_domain($mgettext_file);
AA::$lang         = strtolower(substr($mgettext_file,0,2));      // actual language - two letter shortcut cz / es / en
AA::$langnum      = [AA_Langs::getLangName2Num(AA::$lang)]; // array of prefered languages in priority order.
AA::$slice_id     = AA::$module_id;
$_SESSION['r_last_module_id'] = AA::$module_id; // we need it only in filebrowser of CKEditor
