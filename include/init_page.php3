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

require "../include/config.php3";

if($free)            // anonymous authentication
  $nobody = true;

require $GLOBALS[AA_INC_PATH] . "locauth.php3";
require $GLOBALS[AA_INC_PATH] . "scroller.php3";  

$new_sliceid = $slice_id;

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

if( isset($slice_type) ) {                             // used if new slice is created
  include $GLOBALS[AA_INC_PATH] . $ActionAppConfig[$slice_type][file];
} elseif(isset($r_config_type) AND is_array($r_config_type) AND ($r_config_type[$slice_id] != "")) {
  include $GLOBALS[AA_INC_PATH] . $ActionAppConfig[$r_config_type[$slice_id]][file];
} else {
  include $GLOBALS[AA_INC_PATH] . DEFAULT_LANG_INCLUDE ;
}

require $GLOBALS[AA_INC_PATH] . "util.php3"; // must be after language include because of lang constants in util.php3

$sess->register("slice_id");
$sess->register("r_config_type");
$sess->register("r_slice_config");      // stores many config parameters for slice (WDDX in slices.config field)
$sess->register("r_slice_headline");    // stores headline of slice
$sess->register("r_slice_view_url");    // url of slice
$sess->register("r_stored_slice");      // id of slice which values are in r_slice_headline ,r_slice_config and r_slice_view_url
$sess->register("r_hidden");            // array of variables - used to transport variables between pages (instead of dangerous hidden tag)

//huh( $r_hidden["hidden_acceptor"]. " = ". (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF));
if( $r_hidden["hidden_acceptor"] != (($DOCUMENT_URI != "") ? $DOCUMENT_URI : $PHP_SELF))
  unset( $r_hidden );    // only acceptor can read this values. 
                         // For others they are destroyed.

$ldap_slices = GetUsersSlices( $auth->auth[uid] );

if( !$New_slice AND !$Add_slice AND is_array($ldap_slices) AND (reset($ldap_slices)=="") ) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_EDIT_ITEMS);
  exit;
}  

$db  = new DB_AA;

// lookup (not deleted slices) 
$SQL= " SELECT id, headline, short_name FROM slices WHERE deleted<1";
$db->query($SQL);
while($db->next_record()) 
  $all_slices[unpack_id($db->f(id))] = $db->f(short_name);

if( $ldap_slices[all] ) {  // super admin - permission to manage all slices (deleted too)
  $SQL= " SELECT id, short_name FROM slices";
  $db->query($SQL);
  while($db->next_record()) 
    $slices[unpack_id($db->f(id))] = $db->f(short_name);
 } else {
  reset($ldap_slices);  // find names for slice ids
  while( list($slid,) = each($ldap_slices) ) {
    if( $all_slices[$slid] != "" )  // do not show deleted slices
      $slices[$slid] = $all_slices[$slid];
  }  
}  

if( !$Add_slice AND !$New_slice ) {
  if( !is_array($slices)) {   // this slice was deleted
    MsgPage($sess->url(self_base())."index.php3", L_DELETED_SLICE);
    exit;
  }  
  if(!$slice_id) {       // user is here for the first time -  find any slice for him
    reset($slices);
    $slice_id = key($slices);
  }    
  if( !isset($slices[$slice_id])) {   // this slice was deleted
    MsgPage($sess->url(self_base())."index.php3", L_DELETED_SLICE);
    exit;
  }  
  $p_slice_id = q_pack_id($slice_id);


  if( $slice_id != $r_stored_slice ) {                     // it is not cached - we must get it
    $SQL= " SELECT * FROM slices WHERE id='$p_slice_id'";  // check for hedline and slice type
    $db->query($SQL);
    if($db->next_record()) {
      $r_slice_headline = $db->f(headline);
      $r_config_type[$slice_id] = $db->f(type);
      if(!$db->f(config)) {
        $r_slice_config = wddx_deserialize(DEFAULT_SLICE_CONFIG);
        huh('This slice have not config string (WDDX). The reason is:<br>
                1) You have not new column "config" in "slices" table or<br>
                2) This is old slice, where "config" string is not used.<br>
                The default values are used.');
      }
      else
        $r_slice_config = wddx_deserialize($db->f(config));            // in config field are stored many parameters of this slice
      $r_stored_slice = $slice_id;
      $r_slice_view_url = ($db->f(slice_url)=="" ? $sess->url("../slice.php3"). "&slice_id=$slice_id&encap=false"
                                      : $db->f(slice_url));
    }
  }  

  if( CONFIG_FILE != $ActionAppConfig[$r_config_type[$slice_id]][file] ) { // The config file not loaded
    page_close();             // save variables
    $netscape = (r=="") ? "r=1" : "r=".++$r;   // special parameter for Natscape to reload page
  	header("Location: ". con_url($sess->url($PHP_SELF),$netscape));
   	exit;
  }
}
/*
$Log$
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