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

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_USERS)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_USERS, "admin");
  exit;
}  

// Function decides whether current user can change role
// of specified user. Only allowed when $editor_perm (current user) is greater 
// than $perm (user's role) and $perm_role (new user's role)
function CanChangeRole ($user_perm, $editor_perm, $role_perm) {
  if ((ComparePerms($editor_perm, $user_perm)=="G") &&
      (ComparePerms($editor_perm, $role_perm)=="G")) {
    return true;
  } else {
    return false;
  }
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
  global $perms_roles_perms, $perms_roles_id, $sess, $auth;
  $usr_id = rawurlencode($usr_id);
  // select role icon
  $role_images = array(0=>"rolex.gif",
                       1=>"role1.gif",
                       2=>"role2.gif",
                       3=>"role3.gif",
                       4=>"role4.gif"); 
  $perm = $usr["perm"];
  $role = 0;
  
  if( strstr($perm,$perms_roles_id["SUPER"]) )
    $role = 4;
  elseif( strstr($perm,$perms_roles_id["ADMINISTRATOR"] ) )
    $role = 3;
  elseif( strstr($perm,$perms_roles_id["EDITOR"] ) )
    $role = 2;
  elseif( strstr($perm,$perms_roles_id["AUTHOR"] ) )
    $role = 1;
    
  echo "<tr><td><img src=\"../images/". $role_images[$role] .
       "\" width=50 height=25 border=0></td>\n";
  echo "<td class=tabtxt>". $usr[name] ."</td>\n";
  echo "<td class=tabtxt>". (($usr[mail]) ? $usr[mail] : "&nbsp;") ."</td>\n";
  echo "<td class=tabtxt>". $usr[type] ."</td>\n";

  IfLink( CanChangeRole($perm, $editor_perm, $perms_roles_perms["AUTHOR"]),
          $sess->url(self_base() . "se_users.php3") .
               "&UsrAdd=$usr_id&role=AUTHOR", L_ROLE_AUTHOR);
  IfLink( CanChangeRole($perm, $editor_perm, $perms_roles_perms["EDITOR"]), 
          $sess->url(self_base() . "se_users.php3") .
                "&UsrAdd=$usr_id&role=EDITOR", L_ROLE_EDITOR);
  IfLink( CanChangeRole($perm, $editor_perm,
                        $perms_roles_perms["ADMINISTRATOR"]), 
          $sess->url(self_base() . "se_users.php3") .
                "&UsrAdd=$usr_id&role=ADMINISTRATOR",
          L_ROLE_ADMINISTRATOR);
  IfLink( CanChangeRole($perm, $editor_perm, $perms_roles_perms["AUTHOR"]),
          $sess->url(self_base() . "se_users.php3") .
                     "&UsrDel=$usr_id", L_REVOKE);
  echo "<td class=tabtxt>". (($usr[type]!=L_USER)? "&nbsp;" :
         "<input type='button' name='uid' value='". L_PROFILE ."' 
           onclick=\"document.location='". $sess->url("se_profile.php3?uid=$usr_id") ."'\"></td>\n");
  echo "</tr>\n";
}

$show_adduser = $adduser || $GrpSrch || $UsrSrch;    // show add user form?

HtmlPageBegin();   // Prints HTML start page tags 
                   // (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_PERMISSIONS;?></TITLE>
</HEAD>
<?php

  $show[ $show_adduser ?  "addusers" : "users" ] = false;

  require $GLOBALS[AA_INC_PATH]."se_inc.php3";   // show navigation column in dependance
                                                 // on $show variable

  echo "<H1><B>".L_A_PERMISSIONS."</B></H1>";
//  PrintArray($err);
  echo $Msg;

  $continue=true;
  $editor_perms = GetSlicePerms($auth->auth["uid"], $slice_id);

  $cache = new PageCache($db,CACHE_TTL,CACHE_PURGE_FREQ); # database changed - 
  
  if( $show_adduser ) {
    include "./se_users_add.php3";
  } elseif( $UsrAdd ) {
    if( CanChangeRole( GetSlicePerms($UsrAdd, $slice_id, false),
                       $editor_perms,
                       $perms_roles_perms[$role]) ) {
      AddPerm($UsrAdd, $slice_id, "slice", $perms_roles_id[$role]);
      $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
    }
  } elseif( $UsrDel ) {
    if( CanChangeRole(GetSlicePerms($UsrDel, $slice_id, false),
                      $editor_perms,
                      $perms_roles_perms["AUTHOR"]) )  // smallest permission
      DelPerm($UsrDel, $slice_id, "slice");
      $cache->invalidateFor("slice_id=$slice_id");  # invalidate old cached values
  }
  if( $continue ) {
/* # unused code (I hope)

   # create or update scroller
    if(is_object($st_usr))
      $st_usr->updateScr($sess->url($PHP_SELF) . "&");
    else {
      $st_usr = new scroller("st_usr", $sess->url($PHP_SELF) . "&");    
        $st_usr->addFilter("headline", "char");
        $sess->register(st_usr); 
    }
    $db->query("select count(*) as cnt from slices where ". $st_usr->sqlCondFilter());
    $db->next_record();
    $st_usr->countPages($db->f(cnt));
    $pgsize = $st_usr->metapage; # scroller page size 
*/    
    ?>
    
    <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
    <tr>
     <td class=tabtit><b>&nbsp;<?php echo L_PERM_CURRENT ?></b></td>
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
              <td class=tabtxt colspan='7'>". L_DEFAULT_USER_PROFILE ."</td>
              <td class=tabtxt><input type='button' name='uid' value='". L_PROFILE ."' 
           onclick=\"document.location='". $sess->url("se_profile.php3?uid=*") ."'\"></td>\n";
  echo "</tr>\n";
      
    echo "</table>
    </form></td></tr></table>";
  }  
?>
</BODY>
</HTML>
<?php page_close();?>