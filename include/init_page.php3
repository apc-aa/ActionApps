<?php
//$Id$
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

// this allows to require this script any number of times - it will be read only once
if (!defined ("INIT_PAGE_INCLUDED"))
	define ("INIT_PAGE_INCLUDED",1);
else return;

# parameter Add_slice - lost slice_id (so no slice context)
# parameter New_slice - used in sliceadd.php3 page
#                     - do not unset slice_id but slice_id could not be defined
/* @param $change_id .. change to another slice / module
   @param $change_page .. when going to another module type, the script usually jumps to index.php3
                             you can choose another page from admin dir, e.g. change_page=se_view.php3
   @param $change_params .. array of parameters to be given to $change_page,
                            e.g. change_params[vid]=8
*/

 # handle with PHP magic quotes - quote the variables if quoting is set off
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

if($encap == "false")    # used in itemedit for anonymous form
  $encap = false;        # it must be here, because the variable is rewriten
                         # if the get_magic_quotes_gpc()==false (see above)

# modules other than slices are in deeper directory -> $directory_depth
require "$directory_depth../include/config.php3";

# should be set in config.php3 - used for relative path to image directory
if (!$AA_INSTAL_PATH) {
  $url_components = parse_url(AA_INSTAL_URL);
	$AA_INSTAL_PATH = $url_components['path'];
}

# should be set in config.php3 - base is better for modules
if (!$AA_BASE_PATH) {
  $AA_BASE_PATH = substr($AA_INC_PATH, 0, -8);
}

if($free)            // anonymous authentication
  $nobody = true;

require $GLOBALS[AA_INC_PATH] . "locauth.php3";
require $GLOBALS[AA_INC_PATH] . "scroller.php3";

$new_sliceid = $change_id;

if( $encap ) // we can't use AA_CP_Session - it uses more Header information
  page_open(array("sess" => "AA_SL_Session", "auth" => "AA_CP_Auth"));
else
  page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));

if($free) {           // anonymous login
  $username = $free;
  $password = $freepwd;
  if( !($auth->auth["uid"] = $auth->auth_validatelogin()) ){
    echo L_BAD_LOGIN;
    exit;
  }
}

$auth->relogin_if($relogin); // relogin if requested

if( $new_sliceid )
  $slice_id = $new_sliceid;

if( $Add_slice )
  unset($slice_id);

if( !$require_default_lang AND isset($r_lang_file) AND is_array($r_lang_file) AND ($r_lang_file[$slice_id] != "")) {
  include $GLOBALS[AA_INC_PATH] . $r_lang_file[$slice_id];  # do not delete the curly braces - include in condition statement must be in braces!
}else{
  include $GLOBALS[AA_INC_PATH] . DEFAULT_LANG_INCLUDE ;
}


require $GLOBALS[AA_INC_PATH] . "util.php3"; // must be after language include because of lang constants in util.php3

if( $slice_id )
  $p_slice_id = q_pack_id($slice_id);

$sess->register("slice_id");
$sess->register("p_slice_id");
$sess->register("r_lang_file");
$sess->register("r_slice_headline");    // stores headline of slice
$sess->register("r_slice_view_url");    // url of slice
$sess->register("r_stored_module");     // id of module which values are in r_slice_headline, r_slice_view_url
$sess->register("r_hidden");            // array of variables - used to transport variables between pages (instead of dangerous hidden tag)
$sess->register("r_profile");           // stores profile for loged user and current slice
$sess->register("wrong_language_file"); // cares about the infinite loop with wrong language file
//$sess->register("r_fields");            // array of fields for current slice

if( !$save_hidden ) {      # sometimes we need to not unset hidden - popup for related stories ...
  if( $unset_r_hidden OR
     ($r_hidden["hidden_acceptor"] != (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF))) {
    unset( $r_hidden );    // only acceptor can read this values.
                           // For others they are destroyed.
  }
}

if (!$New_silce AND !$Add_slice AND !$slice_id)
    $after_login = true;

$perm_slices = GetUsersSlices( $auth->auth[uid] );

if( !$New_slice AND !$Add_slice AND is_array($perm_slices) AND (reset($perm_slices)=="") ) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT_ITEMS, "standalone");
  exit;
}

$db  = new DB_AA;

# if we want to use random number generator, we have to use srand just once per
# script. That's why we called it here. Do not use it on other places in scripts
srand((double)microtime()*1000000);

# get all modules
$SQL= "SELECT id, name, type, deleted FROM module ORDER BY name";
$db->query($SQL);
while($db->next_record()) {
  $up = unpack_id($db->f('id'));

  # g_modules is global array which holds user editable modules
  # hide the deleted slices (if the user is not superadmin)

  #        superadmin         or       user have permission to the slice
  if( ($perm_slices == "all") OR ( !$db->f('deleted') AND $perm_slices[$up] ) )
    $g_modules[$up] = array('name' => $db->f('name'),
                            'type' => ( ($db->f('type') AND $MODULES[$db->f('type')] ) ? $db->f('type') : 'S'));
}

if( !$Add_slice AND !$New_slice ) {
  if( !is_array($g_modules)) {   // this slice was deleted
    MsgPage($sess->url(self_base())."index.php3", L_DELETED_SLICE, "standalone");
    exit;
  }
  if(!$slice_id) {       // user is here for the first time -  find any slice for him
    reset($g_modules);
    $slice_id = key($g_modules);   # the variable slice_id (p_slice_id respectively)
                                   # do not hold just id of slices, but it possibly
                                   # holds id of any module. The name comes from
                                   # history, when there was no other modules
                                   # than slices

      # skip AA Core Field slice, if possible
    if( ($slice_id == "41415f436f72655f4669656c64732e2e") AND next($g_modules) )
      # 41415f436f72655f4669656c64732e2e is unpacked "AA_Core_Fields.."
      $slice_id = key($g_modules);
    $p_slice_id = q_pack_id($slice_id);
    $after_login = true;
  }

  if( !isset($g_modules[$slice_id])) {   # this module was deleted
    MsgPage($sess->url(self_base())."index.php3", L_DELETED_SLICE, "standalone");
    exit;
  }
  $p_slice_id = q_pack_id($slice_id);
  if( $slice_id != $r_stored_module ) {  # it is not cached - we must get it

    # go to main administration script for the module, if the module changed
    $module_change = $after_login
        || ($r_stored_module AND ($g_modules[$slice_id]['type'] != $g_modules[$r_stored_module]['type']));

    # Get module informations and store them to session variables
    $r_slice_headline = $g_modules[$slice_id]['name'];

#    $SQL= "SELECT * FROM ". $MODULES[ $g_modules[$slice_id]['type'] ]['table'] .
    $SQL= "SELECT * FROM module ".
          " WHERE id='$p_slice_id'";
    $db->query($SQL);
    if($db->next_record()) {
      $r_lang_file[$slice_id] = $db->f('lang_file');
      $r_stored_module = $slice_id;
      $r_slice_view_url = ($db->f('slice_url')=="" ? $sess->url("../slice.php3"). "&slice_id=$slice_id&encap=false"
                                      : $db->f('slice_url'));
    }

    # Get user profile for the slice
    unset( $r_profile );
    $SQL= " SELECT * FROM profile
             WHERE slice_id='$p_slice_id'
               AND (uid='". $auth->auth["uid"] ."' OR uid='*')";

    $db->query($SQL);
    while($db->next_record()) {
      # default setting for the slice is stored as user 'uid=*'
      if( $db->f('uid') == '*' ) {
        $general_profile[] = $db->Record;   # store the row for this moment
        continue;
      }
      $r_profile[$db->f('property')][$db->f('selector')] = $db->f('value');
    }
    if( isset($general_profile) ) {         # use general preferences if not
      reset( $general_profile );            # definned special
      while( list(,$v) = each($general_profile) ) {
        if( !GetProfileProperty($v['property'],$v['selector']) )
          $r_profile[$v['property']][$v['selector']] = $v['value'];
      }
    }
//print_r( $r_profile );
  }

  # if we switch to another module type, we should go to module main page
  # but not if we are jumping with the Jump module
  if( $module_change && !$jumping) {
    page_close();
    go_other_module_entry_page ();
    exit;
  }

	if( (LANG_FILE != $r_lang_file[$slice_id]) AND !$require_default_lang ) {
  	if (++$wrong_language_file == 10) {
      echo "<b>WRONG LANGUAGE FILE</b>: you must have<br>
		    define(\"LANG_FILE\",\"file_name.php3\") in the language file, with file_name.php3 replaced by the real file name (which seems to be ".$r_lang_file[$slice_id].").";
      page_close();
      exit;
    }

    page_close();             // save variables

  	if( $free && $encap)  {// anonymous login
      $to_go_url = (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF);
   	  echo '<SCRIPT Language="JavaScript"><!--
         		  document.location = "'. $sess->url($to_go_url) .'";
              // -->
            </SCRIPT>';
    }
    go_url( $sess->url($PHP_SELF));
    exit;
  }
}

# if we switch to another module type, we should go to module main page
# but not if we are jumping with the Jump module
if( $after_login || ($module_change && !$jumping)) {
  page_close();
  go_other_module_entry_page ();
  exit;
}

function go_other_module_entry_page ()
{
    global $slice_id, $change_page, $change_params, $MODULES, $g_modules, $sess;
    $page = $change_page ? $change_page : "index.php3";
    $page = $sess->url($MODULES[$g_modules[$slice_id]['type']]['directory'].$page);
    if ($change_params) {
        reset ($change_params);
        while (list ($param, $val) = each ($change_params))
            $page .= "&$param=$val";
    }
    go_url($page);
}    

?>
