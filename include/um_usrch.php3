<?php  #um_usrch.php3  - include file with user search form
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


  function GetFiltered($type, $filter, $to_much, $none) {
    switch( $type ) {
      case "U": $list = FindUsers($filter); break;
      case "G": $list = FindGroups($filter); break;
    }  
    if( !is_array($list) ) {
      unset($list);
      $list["n"][name] = (( $list == "too much" ) ? $to_much : $none);
    }
    return $list;
  }  
      
  $users  = GetFiltered("U", $usr, L_TOO_MUCH_USERS, L_NO_USERS);   // get list of users
  $groups = GetFiltered("G", $grp, L_TOO_MUCH_GROUPS, L_NO_GROUPS); // get list of groups

  if( $grp1_flt )   // user editation - list of all groups
    $all_groups = GetFiltered("G", $grp1_flt, L_TOO_MUCH_GROUPS, L_NO_GROUPS);  
   else
    $all_groups = $groups;  // in user editation is $grp=="", so $groups are list of all groups 

  if( $usr1_flt )   // group editation - list of all users
    $all_users = GetFiltered("U", $usr1_flt, L_TOO_MUCH_USERS, L_NO_USERS);  
   else
    $all_users = $users;  // in group editation is $usr=="", so $users are list of all users 

  if( $selected_user ) {
    $user_groups = GetMembership($selected_user,1);   // get list of groups in which the user is (just first level groups)
    if( !is_array($user_groups) ) 
      $sel_groups["n"][name] = (( $user_groups == "too much" ) ? L_TOO_MUCH_GROUPS : "");
     else {
      reset($user_groups);
      while( list(,$foo_uid) = each($user_groups) )
        $sel_groups[$foo_uid] = GetIDsInfo($foo_uid);
    }  
  }

  if( $selected_group ) {
    $groups_user = GetGroupMembers($selected_group);   // get list of users and groups right under $selected_group
    if( !is_array($group_users) ) 
      $sel_users["n"][name] = (( $group_users == "too much" ) ? L_TOO_MUCH_USERS : "");
     else 
      $sel_users = $groups_user;
  }

/*
$Log$
Revision 1.1  2000/06/21 18:40:49  madebeer
Initial revision

Revision 1.1.1.1  2000/06/12 21:50:27  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.5  2000/06/12 19:58:37  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.4  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.3  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.2  2000/03/22 09:38:40  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/

?>
<!-- Select user form -->
<form enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
 <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="#584011" align="center">
  <tr><td class=tabtit><b>&nbsp;<?php echo L_USERS?></b></td></tr>
  <tr><td>
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
     <tr>
    	<td>&nbsp;</td>
    	<td><input type=Text name=usr value="<?php echo $usr?>"></td>
    	<td><input type=submit name="UsrSrch" value="<?php echo L_SEARCH?>"></td>
     </tr>
     <tr>
    	<td class=tabtxt><b><?php echo L_USER ?></b></td>
    	<td><?php SelectGU_ID("selected_user", $users, $selected_user) ?></td>
    	<td><input type=submit name="usr_edit" value="<?php echo L_EDIT?>">&nbsp;
          <input type=submit name="usr_del" value="<?php echo L_DELETE?>"></td>
     </tr>
    </table>
   </td>
  </tr>
 </table>
</FORM>

