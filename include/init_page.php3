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
 * @package ControlPanel
 * @version $Id$
 * @author Honza Malík, Jakub Adámek, Econnect
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

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

/** Use require menu_include(); to include module-specific menu,
*   call showMenu() afterwards. */
function menu_include() {
    global $AA_BASE_PATH, $MODULES, $g_modules, $slice_id;
    $menu = $MODULES[$g_modules[$slice_id]["type"]]["menu"];
    return $AA_BASE_PATH. ($menu ? $menu : 'include/menu.php3');
}

 // handle with PHP magic quotes - quote the variables if quoting is set off
function Myaddslashes($val, $n=1) {
    if (!is_array($val))
        return addslashes($val);
    for (reset($val); list($k, $v) = each($val); )
        $ret[$k] = Myaddslashes($v, $n+1);
    return $ret;
}

if (!get_magic_quotes_gpc()) {
  // Overrides GPC variables
  if( isset($HTTP_GET_VARS) AND is_array($HTTP_GET_VARS))
    for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); )
      $$k = Myaddslashes($v);
  if( isset($HTTP_POST_VARS) AND is_array($HTTP_POST_VARS))
    for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); )
      $$k = Myaddslashes($v);
  if( isset($HTTP_COOKIE_VARS) AND is_array($HTTP_COOKIE_VARS))
    for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); )
      $$k = Myaddslashes($v);
}

if($encap == "false")    // used in itemedit for anonymous form
  $encap = false;        // it must be here, because the variable is rewriten
                         // if the get_magic_quotes_gpc()==false (see above)

// modules other than slices are in deeper directory -> $directory_depth
$config_path = ( $directory_depth == 'base' ) ? "./include/config.php3" :  // base (for live_checkbox.php3)
                               "$directory_depth../include/config.php3";
require_once $config_path;
require_once $GLOBALS["AA_INC_PATH"]."mgettext.php3";

// should be set in config.php3
if (!$AA_INSTAL_PATH) {
    $url_components = parse_url(AA_INSTAL_URL);
    $AA_INSTAL_PATH = $url_components['path'];
}
// should be set in config.php3
if (!$AA_BASE_PATH)
    $AA_BASE_PATH = substr($AA_INC_PATH, 0, -8);

// anonymous authentication - locauth calls extauthnobody
if($free)
    $nobody = true;

require_once $GLOBALS["AA_INC_PATH"] . "locauth.php3";
require_once $GLOBALS["AA_INC_PATH"] . "scroller.php3";
require_once $GLOBALS["AA_INC_PATH"] . "perm_core.php3";

// save before getting the session stored variables
if ($change_id)
    $pass_sliceid = $change_id;
else $pass_sliceid = $slice_id;

// Load the session stored variables.
if( $encap ) // we can't use AA_CP_Session - it uses more Header information
     page_open(array("sess" => "AA_SL_Session", "auth" => "AA_CP_Auth"));
else page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));

// anonymous login
if($nobody) {
    $username = $free;
    $password = $freepwd;
    $auth->auth["uid"] = $auth->auth_validatelogin();
    if( !$auth->auth["uid"] ) {
        echo _m("Either your username or your password is not valid.");
        exit;
    }
}

// relogin if requested
$auth->relogin_if($relogin);

$last_slice_id = $slice_id;

if( $pass_sliceid )
    $slice_id = $pass_sliceid;

if( $no_slice_id )
    unset($slice_id);

require_once $GLOBALS["AA_INC_PATH"] . "util.php3";  // must be after language include because of lang constants in util.php3
require_once $GLOBALS["AA_INC_PATH"] . "event.class.php3";

/* It is not a good idea to store $slice_id, it made some damage in AA
   installations already. But for historical reasons before somebody ensures
   that all Admin Panel modules send $slice_id each time, we leave it here.
   But if a script sends slice_id, the session-stored one is overrided
   (see $pass_sliceid above). */
$sess->register("slice_id");
// array of variables - used to transport variables between pages (instead of dangerous hidden tag)
$sess->register("r_hidden");

// sometimes we need not to unset hidden - popup for related stories ...
// only acceptor can read values. For others they are destroyed.
$my_document_uri = $DOCUMENT_URI ? $DOCUMENT_URI : $PHP_SELF;
if( !$save_hidden AND ($unset_r_hidden OR $r_hidden["hidden_acceptor"] != $my_document_uri))
    unset( $r_hidden );

$after_login = !$no_slice_id && !$slice_id;
$perm_slices = GetUserSlices();

if( !$no_slice_id AND !IsSuperadmin() AND !$perm_slices[$slice_id] AND !$after_login ) {
    MsgPage($sess->url(self_base())."index.php3",
        _m("You do not have permission to edit items in the slice").": ".slice2name($slice_id),"standalone");
    exit;
}

$db    = new DB_AA;
$event = new aaevent;
$contentcache = new contentcache;

// if we want to use random number generator, we have to use srand just once per
// script. That's why we called it here. Do not use it on other places in scripts
srand((double)microtime()*1000000);

// Create g_modules: a global array which holds user editable modules
$db->query("SELECT id, name, type, deleted FROM module ORDER BY name");
while( $db->next_record() ) {
    $my_slice_id = unpack_id128($db->f('id'));
    if( IsSuperadmin() OR ( !$db->f('deleted') AND $perm_slices[$my_slice_id] ) )
        $g_modules[$my_slice_id] = array(
            'name' => $db->f('name'),
            'type' => $MODULES[$db->f('type')] ? $db->f('type') : 'S');
}

if( !$no_slice_id ) {
    if( !is_array($g_modules)) {
        MsgPage($sess->url(self_base())."index.php3", _m("No slice found for you"), "standalone");
        exit;
    }

    if($after_login
        // slice was just deleted, thus is not in $g_modules
        OR ! $g_modules[$slice_id]) {
        reset($g_modules);
        $slice_id = key($g_modules);
        // skip AA Core Field slice, if possible
        if( ($slice_id == "41415f436f72655f4669656c64732e2e") AND next($g_modules) )
            $slice_id = key($g_modules);
    }

    $p_slice_id = q_pack_id($slice_id);

    $db->query("SELECT * FROM module WHERE id='$p_slice_id'");
    $db->next_record();

    // These variables have names of $r_ but are not session stored
    // because this is unnecessary: their evaluation is very fast.
    $r_slice_headline = $db->f("name");
    $r_lang_file = $db->f('lang_file');
    $r_slice_view_url = $db->f('slice_url') ? $db->f('slice_url')
        : $sess->url("../slice.php3"). "&slice_id=$slice_id&encap=false";

    // Get user profile for the slice.
    // Default setting for the slice is stored as user with uid = *
    unset( $r_profile );
    $SQL= " SELECT * FROM profile
            WHERE slice_id='$p_slice_id'
            AND (uid='". $auth->auth["uid"] ."' OR uid='*')";

    $db->query($SQL);
    while($db->next_record())
        if( $db->f('uid') == '*' )
             $general_profile[] = $db->Record;
        else $r_profile[$db->f('property')][$db->f('selector')] = $db->f('value');

    if( $general_profile ) {
        reset( $general_profile );
        while( list(,$v) = each($general_profile) )
            if( !GetProfileProperty($v['property'],$v['selector']) )
                $r_profile[$v['property']][$v['selector']] = $v['value'];
    }
    $module_type = $g_modules[$slice_id]['type'];
    $module_type_changed = $after_login
        || $g_modules[$last_slice_id]['type'] != $module_type;

/* If we switch to another module type, we try whether the requested file
   exists in the module direcory and if not, we go to module's index.php3 page.

   Discussion: There is a chance two modules will have the same page name
   for very different behavior and that this would be a bit confusing when
   using the Select Slice box.
*/
    if( $module_type_changed && !$jumping ) {
        $page = filename ($PHP_SELF);
        $hdd_dir = $AA_INC_PATH."../".$MODULES[$module_type]['directory'];
        $web_dir = $AA_INSTAL_PATH   .$MODULES[$module_type]['directory'];
        if (!file_exists($hdd_dir.$page) OR ($page=='tabledit.php3') OR ($module_type=='J') )
            $page = "index.php3";
        if ($web_dir.$page != $PHP_SELF) {
            $page = $sess->url($web_dir.$page."?slice_id=$slice_id");
            page_close();
            go_url($page);
            exit;
        }
    }
}

if( !$require_default_lang AND ($r_lang_file != "") ) {
    // do not delete the curly braces - include in condition statement must be in braces!
    bind_mgettext_domain ($GLOBALS["AA_INC_PATH"]."lang/".$r_lang_file);
}
else bind_mgettext_domain ($GLOBALS["AA_INC_PATH"]."lang/".DEFAULT_LANG_INCLUDE);

?>
