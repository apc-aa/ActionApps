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

# expected $slice_id for edit slice
# optionaly $Msg to show under <h1>Headline</h1>
# (typicaly: Category update successful)

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."se_users.php3";
require $GLOBALS[AA_INC_PATH]."msgpage.php3";
require $GLOBALS[AA_INC_PATH]."profile.php3";

if(!IfSlPerm(PS_USERS)) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to manage users"), "admin");
  exit;
}  

// function shows link only if condition is true
function IfLink( $cond, $url, $txt ) {
  echo "<td class=tabtxt>";
  if( $cond )
    echo "<a href=\"$url\">$txt</a>";
  else
    echo $txt;
  echo  "</td>\n";
}    

function PrintUser($usr, $usr_id, $editor_perm) {
  global $perms_roles, $sess, $auth;
  $usr_id = rawurlencode($usr_id);
  // select role icon
  $role_images = array(0=>"rolex.gif",
                       1=>"role1.gif",
                       2=>"role2.gif",
                       3=>"role3.gif",
                       4=>"role4.gif"); 
  $perm = $usr["perm"];
  $role = 0;
  
  if( IsPerm($perm,$perms_roles["SUPER"]['id']) )
    $role = 4;
  elseif( IsPerm($perm,$perms_roles["ADMINISTRATOR"]['id'] ) )
    $role = 3;
  elseif( IsPerm($perm,$perms_roles["EDITOR"]['id'] ) )
    $role = 2;
  elseif( IsPerm($perm,$perms_roles["AUTHOR"]['id'] ) )
    $role = 1;
    
  echo "<tr><td><img src=\"../images/". $role_images[$role] .
       "\" width=50 height=25 border=0></td>\n";
  echo "<td class=tabtxt>". $usr[name] ."</td>\n";
  echo "<td class=tabtxt>". (($usr[mail]) ? $usr[mail] : "&nbsp;") ."</td>\n";
  echo "<td class=tabtxt>". $usr[type] ."</td>\n";

  IfLink( CanChangeRole($perm, $editor_perm, $perms_roles["AUTHOR"]['perm']),
          $sess->url(self_base() . "se_users.php3") .
               "&UsrAdd=$usr_id&role=AUTHOR", _m("Author"));
  IfLink( CanChangeRole($perm, $editor_perm, $perms_roles["EDITOR"]['perm']), 
          $sess->url(self_base() . "se_users.php3") .
                "&UsrAdd=$usr_id&role=EDITOR", _m("Editor"));
  IfLink( CanChangeRole($perm, $editor_perm,
                        $perms_roles["ADMINISTRATOR"]['perm']), 
          $sess->url(self_base() . "se_users.php3") .
                "&UsrAdd=$usr_id&role=ADMINISTRATOR",
          _m("Administrator"));
  IfLink( CanChangeRole($perm, $editor_perm, $perms_roles["AUTHOR"]['perm']),
          $sess->url(self_base() . "se_users.php3") .
                     "&UsrDel=$usr_id", _m("Revoke"));
  echo "<td class=tabtxt>". (($usr[type]!=_m("User"))? "&nbsp;" :
         "<input type='button' name='uid' value='". _m("Profile") ."' 
           onclick=\"document.location='". $sess->url("se_profile.php3?uid=$usr_id") ."'\"></td>\n");
  echo "</tr>\n";
}

$show_adduser = $adduser || $GrpSrch || $UsrSrch;    // show add user form?

HtmlPageBegin();   // Prints HTML start page tags 
                   // (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - Permissions");?></TITLE>
</HEAD>
<?php
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin", $show_adduser ? "addusers" : "users");

  echo "<H1><B>"._m("Admin - Permissions")."</B></H1>";
//  PrintArray($err);
  echo $Msg;

  $continue=true;

  if( $show_adduser ) {
    include "./se_users_add.php3";
  } elseif( $UsrAdd || $UsrDel) 
    ChangeRole (); // in include/se_users.php3
  if( $continue ) {?>
    
    <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr>
     <td class=tabtit><b>&nbsp;<?php echo _m("Change current permissions") ?></b></td>
    </tr>
    <tr><td><form>
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">  <?php

    $slice_users = GetObjectsPerms($slice_id, "slice");
    $aa_users = GetObjectsPerms(AA_ID, "aa");   // higher than slice
    
    if( isset($slice_users) AND !is_array($slice_users) )
      unset($slice_users);
    if( isset($aa_users) AND !is_array($aa_users) )
      unset($aa_users);

    if(isset($slice_users) AND is_array($slice_users)) {
      reset($slice_users);  // if conflicts slice perms and aa perms - solve it
      while( list($usr_id,$usr)= each($slice_users))
        if( $aa_users[$usr_id] )
          $slice_users[$usr_id][perm] = JoinAA_SlicePerm($slice_users[$usr_id][perm], $aa_users[$usr_id][perm]);
    }
    
    if(isset($aa_users) AND is_array($aa_users)) {
      reset($aa_users);    // no slice permission set, but aa perms yes
      while( list($usr_id,$usr)= each($aa_users)) {
        if( !isset($slice_users) OR !is_array($slice_users) OR !$slice_users[$usr_id] )
          $slice_users[$usr_id] = $usr;
      }    
    }      
        
    reset($slice_users);
    while( list($usr_id,$usr)= each($slice_users))
      PrintUser($usr,$usr_id,$editor_perms);
      
    echo "<tr><td class=tabtxt>&nbsp;</td>
              <td class=tabtxt colspan='7'>". _m("Default user profile") ."</td>
              <td class=tabtxt><input type='button' name='uid' value='". _m("Profile") ."' 
           onclick=\"document.location='". $sess->url("se_profile.php3?uid=*") ."'\"></td>\n";
  echo "</tr>\n";
      
    echo "</table>
    </form></td></tr></table>";
  }  
HtmlPageEnd();
page_close();?>