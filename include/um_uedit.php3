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
    # Procces user data -------------------------------------------------------
    if(($submit_action == "update_submit") AND ($user_password1 == "nOnEwpAsswD") AND ($user_password2 == "nOnEwpAsswD"))
      $passwd_stay=true;
    ValidateInput("user_login", L_USER_LOGIN, $user_login, $err, ($add_submit ? true : false), "login");
    if( !$passwd_stay ) {
      ValidateInput("user_password1", L_USER_PASSWORD1, $user_password1, $err, true, "password");
      ValidateInput("user_password2", L_USER_PASSWORD2, $user_password2, $err, true, "password");
    }
    ValidateInput("user_mail1", L_USER_MAIL." 1", $user_mail1, $err, false, "email");
    ValidateInput("user_mail2", L_USER_MAIL." 2", $user_mail2, $err, false, "email");
    ValidateInput("user_mail3", L_USER_MAIL." 3", $user_mail3, $err, false, "email");
    ValidateInput("user_surname", L_USER_SURNAME, $user_surname, $err, true, "text");
    ValidateInput("user_firstname", L_USER_FIRSTNAME, $user_firstname, $err, true, "text");
    if( $user_password1 != $user_password2 )
      $err[$user_password2] = MsgErr(L_BAD_RETYPED_PWD);
    if( count($err) > 1)
      break;

    if( !$passwd_stay )      // if unchanged password, don't post it
      $userrecord["userpassword"] = $user_password1;
    $userrecord["givenname"] = $user_firstname;
    $userrecord["sn"] = $user_surname;

    if($user_mail1) $userrecord["mail"][] = $user_mail1;
    if($user_mail2) $userrecord["mail"][] = $user_mail2;
    if($user_mail3) $userrecord["mail"][] = $user_mail3;

    if( $add_submit ) {      # -------------------- new user ------------------
      $userrecord["uid"] = $user_login;
      if(!($newuserid = AddUser($userrecord)))
        $err["LDAP"] = MsgErr( L_ERR_USER_ADD );
      if( count($err) <= 1 ) {
      	if ($user_super) {	// set super admin privilege
      	  AddPerm($newuserid, AA_ID, "aa", $perms_roles["SUPER"]['id']);
      	}
        $Msg = MsgOK(L_NEWUSER_OK);
        if (!$um_uedit_no_go_url)
          go_url( con_url($sess->url($PHP_SELF), 'UsrSrch=1&usr='. urlencode($user_login)), $Msg);
      }
    } else {                 # ----------------- update user ------------------
      $userrecord["uid"] = $selected_user;
      if(!ChangeUser($userrecord)) {
        $err["LDAP"] = MsgErr( L_ERR_USER_CHANGE );
      } else {
      	if ($user_super) {		// set or revoke super admin privilege
      	  AddPerm($userrecord["uid"], AA_ID, "aa", $perms_roles["SUPER"]['id']);
      	} else {
      	  DelPerm($userrecord["uid"], AA_ID, "aa");
      	}
      }
    }

    # Procces group data ------------------------------------------------------
    $assigned_groups = explode(",",$posted_groups); //posted_groups contains comma delimeted list of selected groups for user
    if( isset($sel_groups) AND is_array($sel_groups) AND ($sel_groups["n"]=="")) {
      reset($sel_groups);
      while( list($foo_gid,) = each($sel_groups))  // first we remove user from all groups
        DelGroupMember ($foo_gid, $selected_user);
    }
    if( isset($assigned_groups) AND is_array($assigned_groups) ) {
      reset($assigned_groups);
      while( list(,$foo_gid) = each($assigned_groups)){  // then we add user to specified groups
        $foo_gid = urldecode($foo_gid);
        AddGroupMember ($foo_gid, $selected_user);
      }
    }

    # Procces module permissions ----------------------------------------------
   
    # Change module permissions if user wants
    if( isset($perm_mod) AND is_array($perm_mod) ) {
      $perm_slices = GetIDPerms($selected_user, "slice", 1);  # there are not only Slices, but other Modules too
      reset($perm_mod);
      while( list($xmid,$role) = each($perm_mod) ) {
        $mid=substr($xmid,1);   # removes first 'x' character (makes index string)
        if( $role == 'REVOKE' )
          DelPerm($selected_user, $mid, 'slice');
        elseif( ComparePerms($perm_slices[$mid], $perms_roles[$role]['id']) != 'E' )
          ChangePerm($selected_user, $mid, 'slice', $perms_roles[$role]['id']);
      }
    }  
    
    # Add new modules for this user
    if( isset($new_module) AND is_array($new_module) ) {
      reset($new_module);
      while( list($no,$mid) = each($new_module) ) {
        if( (trim($mid) != "") AND isset($perms_roles[$new_module_role[$no]]) )
          AddPerm($selected_user, $mid, 'slice', $perms_roles[$new_module_role[$no]]['id']); 
      }
    }
  } while(false);
?>

