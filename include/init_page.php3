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

# parameter Add_slice - lost slice_id (so no slice context)
# parameter New_slice - used in sliceadd.php3 page
#                     - do not unset slice_id but slice_id could not be defined

# handle with PHP magic quotes - quote the variables if quoting is set off
function Myaddslashes($val, $n=1) {
  if (!is_array($val)) {
    return addslashes($val);
  }  
  for (reset($val); list($k, $v) = each($val); )
    $ret[$k] = Myaddslashes($v, $n+1);
  return $ret;
}    

if (!get_magic_quotes_gpc()) { 
  // Overrides GPC variables 
  for (reset($HTTP_GET_VARS); list($k, $v) = each($HTTP_GET_VARS); ) 
  $$k = Myaddslashes($v); 
  for (reset($HTTP_POST_VARS); list($k, $v) = each($HTTP_POST_VARS); ) 
  $$k = Myaddslashes($v); 
  for (reset($HTTP_COOKIE_VARS); list($k, $v) = each($HTTP_COOKIE_VARS); ) 
  $$k = Myaddslashes($v); 
}

if($encap == "false")    # used in itemedit for anonymous form
  $encap = false;        # it must be here, because the variable is rewriten
                         # if the get_magic_quotes_gpc()==false (see above)

require "../include/config.php3";

if($free)                # anonymous authentication
  $nobody = true;

require $GLOBALS[AA_INC_PATH] . "locauth.php3";
require $GLOBALS[AA_INC_PATH] . "scroller.php3";  

$new_sliceid = $change_id;
 
if( $encap ) // we can't use AA_CP_Session - it uses more Header information
  page_open(array("sess" => "AA_SL_Session", "auth" => "AA_CP_Auth"));
 else 
  page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));
//page_open(array("sess" => "AA_CP_Session"));

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

if( isset($slice_lang_file) ) {                             // used if new slice is created
  include $GLOBALS[AA_INC_PATH] . $slice_lang_file;
} elseif(isset($r_config_file) AND is_array($r_config_file) AND ($r_config_file[$slice_id] != "")) {
  include $GLOBALS[AA_INC_PATH] . $r_config_file[$slice_id];
} else {
  include $GLOBALS[AA_INC_PATH] . DEFAULT_LANG_INCLUDE ;
}

require $GLOBALS[AA_INC_PATH] . "util.php3"; // must be after language include because of lang constants in util.php3

if( $slice_id )
  $p_slice_id = q_pack_id($slice_id);
  
$sess->register("slice_id");
$sess->register("p_slice_id");
$sess->register("r_config_file");
$sess->register("r_slice_headline");    // stores headline of slice
$sess->register("r_slice_view_url");    // url of slice
$sess->register("r_stored_slice");      // id of slice which values are in r_slice_headline, r_slice_view_url
$sess->register("r_hidden");            // array of variables - used to transport variables between pages (instead of dangerous hidden tag)
$sess->register("r_profile");           // stores profile for loged user and current slice
//$sess->register("r_fields");            // array of fields for current slice

if( !$save_hidden ) {      # sometimes we need to not unset hidden - popup for related stories ...
  if( $unset_r_hidden OR 
     ($r_hidden["hidden_acceptor"] != (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF))) {
    unset( $r_hidden );    // only acceptor can read this values. 
                           // For others they are destroyed.
  }
}  

$ldap_slices = GetUsersSlices( $auth->auth[uid] );

if( !$New_slice AND !$Add_slice AND is_array($ldap_slices) AND (reset($ldap_slices)=="") ) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT_ITEMS, "standalone");
  exit;
}  

$db  = new DB_AA;

// lookup (not deleted slices) 
$SQL= " SELECT id, name FROM slice WHERE deleted<1 ORDER BY name";
$db->query($SQL);
while($db->next_record()) 
  $all_slices[unpack_id($db->f(id))] = $db->f(name);

if( $ldap_slices == "all" ) {  // super admin - permission to manage all slices (deleted too)
  $SQL= " SELECT id, name FROM slice ORDER BY name";
  $db->query($SQL);
  while($db->next_record()) {
    $up = unpack_id($db->f(id));
    $g_slices[$up] = $db->f(name);
  }
} else {
  # find names for slice ids and hide the deleted ones
  reset($ldap_slices);  
  while( list($slid,) = each($ldap_slices) ) {
    if( $all_slices[$slid] != "" )  
      $g_slices[$slid] = $all_slices[$slid];
  }  
}  

if( !$Add_slice AND !$New_slice ) {
  if( !is_array($g_slices)) {   // this slice was deleted
    MsgPage($sess->url(self_base())."index.php3", L_DELETED_SLICE, "standalone");
    exit;
  }  
  if(!$slice_id) {       // user is here for the first time -  find any slice for him
    reset($g_slices);
    $slice_id = key($g_slices);
      # skip AA Core Field slice, if possible
    if( ($slice_id == "41415f436f72655f4669656c64732e2e") AND next($g_slices) ) 
      # 41415f436f72655f4669656c64732e2e is unpacked "AA_Core_Fields.."
      $slice_id = key($g_slices);
    $p_slice_id = q_pack_id($slice_id);
  }    

  if( !isset($g_slices[$slice_id])) {   // this slice was deleted
    MsgPage($sess->url(self_base())."index.php3", L_DELETED_SLICE, "standalone");
    exit;
  }  
  $p_slice_id = q_pack_id($slice_id);
  if( $slice_id != $r_stored_slice ) {                     // it is not cached - we must get it

    # Get slice information and store it to session veriables
    $SQL= " SELECT * FROM slice WHERE id='$p_slice_id'"; 
    $db->query($SQL);
    if($db->next_record()) {
      $r_slice_headline = $db->f(name);
      $r_config_file[$slice_id] = $db->f(lang_file);
      $r_stored_slice = $slice_id;
      $r_slice_view_url = ($db->f(slice_url)=="" ? $sess->url("../slice.php3"). "&slice_id=$slice_id&encap=false"
                                      : $db->f(slice_url));
      list($r_fields,) = GetSliceFields($slice_id);
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
  
  // The config file not loaded -> the slice type was changed
  if( CONFIG_FILE != $r_config_file[$slice_id] ) {
    page_close();             // save variables


    if( $free )  // anonymous login
      if( $encap ) {
        $to_go_url = (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF);
        echo '<SCRIPT Language="JavaScript"><!--
                document.location = "'. $sess->url($to_go_url) .'";
              // -->
             </SCRIPT>';
      } else
        go_url( $sess->url($PHP_SELF));
    else 
      go_url( $sess->url($PHP_SELF));
    exit;
  }
}
/*
$Log$
Revision 1.19  2002/01/15 13:04:34  honzam
fixed bug of not displayed inputform for systems with 'magic quotes' off

Revision 1.18  2002/01/10 13:56:58  honzam
fixed bug in user profiles

Revision 1.17  2001/12/18 12:19:14  honzam
new user profile feature, scripts are now "magic_quotes" independent - no matter how it is set

Revision 1.16  2001/09/27 15:57:59  honzam
Starting with slice other than AA Core for admins, New related stories support

Revision 1.15  2001/05/18 13:55:04  honzam
New View feature, new and improve d search function (QueryIDs)

Revision 1.14  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.13  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

Revision 1.11  2001/01/22 17:32:48  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.10  2001/01/10 15:49:16  honzam
Fixed problem with unpack_id (No content Error on index.php3)

Revision 1.9  2001/01/08 13:31:58  honzam
Small bugfixes

Revision 1.8  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.7  2000/11/20 16:45:58  honzam
fixed bug with anonymous posting to other aplications than news

Revision 1.6  2000/11/15 16:20:41  honzam
Fixed bugs with anonymous posting via SSI and bad viewed item in itemedit

Revision 1.5  2000/10/10 18:28:00  honzam
Support for Web.net's extended item table

Revision 1.4  2000/08/03 15:36:52  kzajicek
The WDDX warning deleted, there are other potential redirects in other files

Revision 1.3  2000/08/03 15:18:41  kzajicek
The WDDX warning is printed after possible header() call

Revision 1.2  2000/08/03 12:36:21  honzam
Session variable r_hidden used instead of HIDDEN html tag.

Revision 1.1.1.1  2000/06/21 18:40:39  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:24  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.10  2000/06/12 19:58:36  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.9  2000/06/09 15:14:11  honzama
New configurable admin interface

Revision 1.8  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.7  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.6  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>