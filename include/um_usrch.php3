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
      
  $users  = GetFiltered("U", $usr, _m("Too many users or groups found."), _m("No user (group) found"));   // get list of users
  $groups = GetFiltered("G", $grp, _m("Too much groups found."), _m("No groups found")); // get list of groups

  if( $grp1_flt )   // user editation - list of all groups
    $all_groups = GetFiltered("G", $grp1_flt, _m("Too much groups found."), _m("No groups found"));  
   else
    $all_groups = $groups;  // in user editation is $grp=="", so $groups are list of all groups 

  if( $usr1_flt )   // group editation - list of all users
    $all_users = GetFiltered("U", $usr1_flt, _m("Too many users or groups found."), _m("No user (group) found"));  
   else
    $all_users = $users;  // in group editation is $usr=="", so $users are list of all users 

  if( $selected_user ) {
    $user_groups = GetMembership($selected_user,1);   // get list of groups in which the user is (just first level groups)
    if( !is_array($user_groups) ) 
      $sel_groups["n"][name] = (( $user_groups == "too much" ) ? _m("Too much groups found.") : "");
     else {
      reset($user_groups);
      while( list(,$foo_uid) = each($user_groups) )
        $sel_groups[$foo_uid] = GetIDsInfo($foo_uid);
    }  
  }

  if( $selected_group ) {
    $groups_user = GetGroupMembers($selected_group);   // get list of users and groups right under $selected_group
    if( !is_array($group_users) ) 
      $sel_users["n"][name] = (( $group_users == "too much" ) ? _m("Too many users or groups found.") : "");
     else 
      $sel_users = $groups_user;
  }

?>
<!-- Select user form -->
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
 <table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
  <tr><td class=tabtit><b>&nbsp;<?php echo _m("Users")?></b></td></tr>
  <tr><td>
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
     <tr>
    	<td>&nbsp;</td>
    	<td><input type=Text name=usr value="<?php echo safe($usr)?>"></td>
    	<td><input type=submit name="UsrSrch" value="<?php echo _m("Search")?>"></td>
     </tr>
     <tr>
    	<td class=tabtxt><b><?php echo _m("User") ?></b></td>
    	<td><?php SelectGU_ID("selected_user", $users, $selected_user) ?></td>
    	<td><input type=submit name="usr_edit" value="<?php echo _m("Edit")?>">&nbsp;
          <input type=submit name="usr_del" value="<?php echo _m("Delete")?>"></td>
     </tr>
    </table>
   </td>
  </tr>
 </table>
</FORM>

