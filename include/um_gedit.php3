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

  do  {
    # Procces group data ---------------------
    ValidateInput("group_name", _m("Name"), $group_name, $err, ($add_submit ? true : false), "text");
    ValidateInput("group_description", _m("Description"), $group_description, $err, false, "text");
    if( count($err) > 1)
      break;

    $grouprecord["description"] = $group_description;
    $grouprecord["name"] = $group_name;
//  $grouprecord["owner"] = ...;           // not used, for now

    if( $add_submit ) {
      if(!($newgroupid = AddGroup($grouprecord)))
        $err["LDAP"] = MsgErr( _m("It is impossible to add group to permission system") );
      if( count($err) <= 1 ) {
	if ($group_super) {	// set super admin privilege
	  AddPerm($newgroupid, AA_ID, "aa", $perms_roles["SUPER"]['id']);
	}
        $Msg = MsgOK(_m("Group successfully added to permission system"));
        go_url( con_url($sess->url($PHP_SELF), 'GrpSrch=1&grp='. urlencode($group_name)), $Msg);
      }
    } else {
      $grouprecord["uid"] = $selected_group;
      if(!ChangeGroup($grouprecord))
        $err["LDAP"] = MsgErr( _m("Can't change group") );
      if ($group_super) {		// set or revoke super admin privilege
	AddPerm($grouprecord["uid"], AA_ID, "aa", $perms_roles["SUPER"]['id']);
      } else {
	DelPerm($grouprecord["uid"], AA_ID, "aa");
      }
    }

    # Procces users data ---------------------
    //posted_users contains comma delimeted list of selected users of group
    if ($posted_users) {
       $assigned_users = explode(",",$posted_users);
    }

    if (isset($sel_users) AND is_array($sel_users) AND ($sel_users["n"]=="")) {
      reset($sel_users);
      while( list($foo_uid,) = each($sel_users))  // first remove all members
        DelGroupMember ($selected_group, $foo_uid);
    }
    if (isset($assigned_users)) {
      reset($assigned_users);
      while( list(,$foo_uid) = each($assigned_users)){  // then we add specified users to group
        $foo_uid = urldecode($foo_uid);                 // we use urldecode in order to use comma as separator
        AddGroupMember ($selected_group, $foo_uid);
      }
    }

    # Procces module permissions ----------------------------------------------

    # Change module permissions if user wants
    ChangeUserModulePerms( $perm_mod, $selected_group, $perms_roles );

    # Add new modules for this user
    AddUserModulePerms( $new_module, $new_module_role, $selected_group, $perms_roles);

  } while(false);
?>