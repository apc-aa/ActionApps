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

# um_uedit.php3 - adds new user to permission system
# optionaly $Msg to show under <h1>Headline</h1> (typicaly: update successful)
# selected_user
# state variables:
#    $usr_edit       - comes from um_usrch - button Edit $selected_user
#    $usr_del        - comes from um_usrch - button Delete $selected_user
#    $usr_new        - comes from um_inc   - New user link
#    $submit_action  - = update_submit if pressed update
#                      = usr_del if delete user is confirmed
#    $add_submit     - if new user Add buutton pressed

require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

# Functions definitions ----------------------------------------

# Prints html tag <select ..
function SelectGU_ID($name, $arr, $selected="", $type="short", $substract="") {
  if( $substract=="" )                 // $substract list of values not shovn in <select> even if in $arr
    $substract = array();
  if( $type == "short" )               // 1-row listbox
    echo "<select name=\"$name\">";
   else                                // 8-row listbox
    echo "<select name=\"$name\" size=8>";
  if( isset($arr) AND is_array($arr)) {
    reset($arr);
    while(list($k, $v) = each($arr)) {
      if( ($v[name] != "") AND ($substract[$k] == "") ) {
        $option_exist = true;
        echo "<option value=\"". htmlspecialchars($k)."\"";
        if ((string)$selected == (string)$k)
          echo " selected";
        echo "> ". htmlspecialchars($v[name]) ." </option>";
      }
    }
    if( !$option_exist )  // if no options, we must set width of <select> box
      echo '<option value="wIdThTor"> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; </option>';
  }
  echo "</select>\n";
}

function GetFiltered($type, $filter, $to_much, $none) {
  switch( $type ) {
    case "U": $list = FindUsers($filter); break;
    case "G": $list = FindGroups($filter); break;
  }
  if( !is_array($list) ) {
    unset($list);
    $list["n"][name] = (( $list == "too much" ) ? $to_much : $none);
  }
  //p_arr_m($list);
  return $list;
}

# End functions definitions ----------------------------------------

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_NEW_USER)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_NEW_USER, "admin");
  exit;
}

if( ($submit_action == "usr_del") AND $selected_user ) {
  DelUser( $selected_user );    // default is to delete any references as well
  go_url( $sess->url($PHP_SELF) );
}  
  
$sess->register("rusr");
if( $usr OR $UsrSrch )
  $rusr = $usr;

if( $usr_new )
  $rusr = $selected_user = "";
  
$users  = GetFiltered("U", $rusr, L_TOO_MUCH_USERS, L_NO_USERS);   // get list of users
if( $UsrSrch ) {
  reset( $users );
  $selected_user = key($users);
  $usr_edit = true;
}
$groups = GetFiltered("G", $grp, L_TOO_MUCH_GROUPS, L_NO_GROUPS); // get list of groups

if( $grp1_flt )   // user editation - list of all groups
  $all_groups = GetFiltered("G", $grp1_flt, L_TOO_MUCH_GROUPS, L_NO_GROUPS);
 else
  $all_groups = $groups;  // in user editation is $grp=="", so $groups are list of all groups

if( $usr1_flt )   // group editation - list of all users
  $all_users = GetFiltered("U", $usr1_flt, L_TOO_MUCH_USERS, L_NO_USERS);
 else
  $all_users = $users;  // in group editation is $rusr=="", so $users are list of all users

if( $selected_user ) {
  if( $selected_user != "n" )  // none user selected 
    $user_groups = GetMembership($selected_user,1);   // get list of groups in which the user is (just first level groups)
  if( !isset($user_groups) OR !is_array($user_groups) )
    $sel_groups["n"][name] = (( $user_groups == "too much" ) ? L_TOO_MUCH_GROUPS : "");
   else {
    reset($user_groups);
    while( list(,$foo_gid) = each($user_groups) )
      $sel_groups[$foo_gid] = GetGroup($foo_gid);
  }
}

if( $selected_group ) {
  if( $selected_group != "n" )  // none group selected 
    $groups_user = GetGroupMembers($selected_group);   // get list of users and groups right under $selected_group
  if( !isset($group_users) OR !is_array($group_users) )
    $sel_users["n"][name] = (( $group_users == "too much" ) ? L_TOO_MUCH_USERS : "");
   else
    $sel_users = $groups_user;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

# Process submited form ----------------------------------------

if( $add_submit OR ($submit_action == "update_submit"))
{
  do  {
    # Procces user data ---------------------
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

    if( $add_submit ) {
      $userrecord["uid"] = $user_login;
      if(!($newuserid = AddUser($userrecord)))
        $err["LDAP"] = MsgErr( L_ERR_USER_ADD );
      if( count($err) <= 1 ) {
	if ($user_super) {	// set super admin privilege
	  AddPerm($newuserid, AA_ID, "aa", $perms_roles_id["SUPER"]);
	}
        $Msg = MsgOK(L_NEWUSER_OK);
        go_url( con_url($sess->url($PHP_SELF), 'UsrSrch=1&usr='. urlencode($user_login)), $Msg);
      }
    } else {
      $userrecord["uid"] = $selected_user;
      if(!ChangeUser($userrecord)) {
        $err["LDAP"] = MsgErr( L_ERR_USER_CHANGE );
      } else {
	if ($user_super) {		// set or revoke super admin privilege
	  AddPerm($userrecord["uid"], AA_ID, "aa", $perms_roles_id["SUPER"]);
	} else {
	  DelPerm($userrecord["uid"], AA_ID, "aa");
	}
      }
    }

    # Procces group data ---------------------
//huh("Posted_groups:".$posted_groups);
    $assigned_groups = explode(",",$posted_groups); //posted_groups contains comma delimeted list of selected groups for user
//p_arr_m($sel_groups);
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
  } while(false);
  if( count($err) <= 1 ) {
    $Msg = MsgOK(L_NEWUSER_OK);
    go_url( con_url($sess->url($PHP_SELF), 'usr_edit=1&selected_user='. urlencode($selected_user)), $Msg);
  }
}

# HTML form -----------------------------------------

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
include $GLOBALS[AA_INC_PATH]."js_lib.js";
?>
 <TITLE><?php echo L_A_UM_USERS_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--
function UpdateUser(action) {
  var foo= CommaDelimeted( 'document.f.sel_groups_sel' )
  document.f.posted_groups.value = foo
  document.f.submit_action.value = action
  document.f.submit()
}

function RealyDelete() {
  if( window.confirm('<?php echo L_REALY_DELETE_USER ?>')) {
    document.f2.submit_action.value = 'usr_del'
    document.f2.submit()
  }  
}
// -->
</SCRIPT>
</HEAD>

<?php
  if ($usr_new) $show["u_new"] = false; else $show["u_edit"] = false;
  require $GLOBALS[AA_INC_PATH]."aa_inc.php3";   //show navigation column depending on $show   

  echo "<H1><B>". ( $usr_new ? L_NEW_USER : L_EDIT_USER )."</B></H1>";
  PrintArray($err);
  echo $Msg;

?>
<!-- Select user form -->
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align=center>
 <tr><td class=tabtit><b>&nbsp;<?php echo L_USERS?></b></td></tr>
 <tr><td>
   <form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
     <tr>
            <td>&nbsp;</td>
            <td><input type=Text name=usr value="<?php echo safe($rusr)?>"></td>
            <td><input type=submit value="<?php echo L_SEARCH?>">
          <input type=hidden name="UsrSrch" value=1></td>
     </tr>
    </table>
   </form>
  </td>
 </tr>
 <tr>
  <td><form name=f2 method=post action="<?php echo $sess->url($PHP_SELF) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
     <tr>
            <td class=tabtxt><b><?php echo L_USER ?></b></td>
            <td><?php SelectGU_ID("selected_user", $users, $selected_user);
          ?></td>
            <td><input type=submit name="usr_edit" value="<?php echo L_EDIT?>">&nbsp;
                <input type=hidden name=submit_action value=0>  <!-- to this variable store "usr_del" (by javascript) -->
                <input type=button name="usr_del" value="<?php echo L_DELETE?>" onclick="RealyDelete()"></td>
     </tr>
    </table>
   </FORM>
  </td>
 </tr>
</table>

<?php
do {
  if( $usr_new OR ($usr_edit AND ($selected_user!="n")) ) {
    if($usr_edit AND !($submit_action == "update_submit")) {
      if( !is_array($user_data = GetUser($selected_user)))
        break;
      $user_login = $user_data[login];
      $user_firstname = $user_data[givenname];
      $user_surname = $user_data[sn];
      $user_password1 = "nOnEwpAsswD";    // unchanged password
      $user_password2 = "nOnEwpAsswD";    // unchanged password
      if( is_array($user_data[mail]))
        $user_mail1 = $user_data[mail][0];
        $user_mail2 = $user_data[mail][1];
        $user_mail3 = $user_data[mail][2];
      $aa_users = GetObjectsPerms(AA_ID, "aa");
      if (strstr($aa_users[$selected_user]["perm"], $perms_roles_id["SUPER"])) {
	$user_super = true;
      }
    }
  } else {
    echo '</BODY></HTML>';
    page_close();
    exit;
  }
} while(false);

?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;
<?php
if( $usr_edit OR ($submit_action == "update_submit") )
  echo L_EDITUSER_HDR;
 else
  echo L_NEWUSER_HDR;
?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
<?php

# User data ---------------------------------------------------

  if( $usr_edit OR ($submit_action == "update_submit") )
    FrmStaticText( L_USER_LOGIN, $user_data[login]);
   else
    FrmInputText("user_login", L_USER_LOGIN, $user_login, 50, 50, true);
  FrmInputPwd("user_password1", L_USER_PASSWORD1, $user_password1, 50, 50, true);
  FrmInputPwd("user_password2", L_USER_PASSWORD2, $user_password2, 50, 50, true);
  FrmInputText("user_firstname", L_USER_FIRSTNAME, $user_firstname, 50, 50, true);
  FrmInputText("user_surname", L_USER_SURNAME, $user_surname, 50, 50, true);
  FrmInputText("user_mail1", L_USER_MAIL." 1", $user_mail1, 50, 50, false);
//  FrmInputText("user_mail2", L_USER_MAIL." 2", $user_mail2, 50, 50, false);  // removed for compatibility with perm_sql.php3
//  FrmInputText("user_mail3", L_USER_MAIL." 3", $user_mail3, 50, 50, false);
  FrmInputChBox("user_super", L_USER_SUPER, $user_super, false, "", 1, false);
echo '</table></td></tr>';

if( !$add_submit AND !$usr_new) {?>

  <tr><td class=tabtit><b>&nbsp;<?php echo L_GROUPS?></b></td></tr>
  <tr><td>
  <table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
  <?php
  
  # User - group membership -----------------------------------------
  
  echo '<tr><td width=190 align=center>'. L_ALL_GROUPS .'</td>
                  <td width=60>&nbsp;</td>
                  <td width=190 align=center>'. L_USERS_GROUPS .'</td></tr>
        <tr><td><input type=Text name=grp1_flt value="'. safe($grp1_flt) .'">
                <input type=submit name="grp1_submit" value="'. L_SEARCH .'"></td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td></tr>
        <tr><td align="CENTER" valign="TOP">';
              SelectGU_ID("all_groups_sel", $all_groups, $all_groups_sel, "long", $sel_groups);
  echo '    </td>
            <td><input type="button" VALUE="  >>  " onClick = "MoveSelected(\'document.f.all_groups_sel\',\'document.f.sel_groups_sel\')" align=center><br><br>
                <input type="button" VALUE="  <<  " onClick = "MoveSelected(\'document.f.sel_groups_sel\',\'document.f.all_groups_sel\')" align=center></td>
                  <td align="CENTER" valign="TOP">';
              SelectGU_ID("sel_groups_sel", $sel_groups, $sel_groups_sel, "long");
  echo '    </td>
        </tr>
      </table></td></tr>';
}  
      
echo '<tr><td align="center">';

if( $usr_new OR $add_submit ){
  echo '<input type=submit name=add_submit value="'. L_ADD .'" >&nbsp;&nbsp;';
  echo '<input type=hidden name=usr_new value=1>&nbsp;&nbsp;';
} else {
  echo '<input type=button name=submit_button value="'. L_UPDATE .'" onClick="UpdateUser(\'update_submit\')">&nbsp;&nbsp;';
  echo '<input type=hidden name=usr_edit value=1>&nbsp;&nbsp;';
}
echo '<input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;';
echo '<input type=hidden name=selected_user value="'.$selected_user.'">&nbsp;&nbsp;';
echo '<input type=hidden name=posted_groups value=0>';  // to this variable store assigned groups (by javascript)
echo '<input type=hidden name=submit_action value=0>';  // to this variable store "add_submit" or "update_submit" (by javascript)
?>
  </td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()
?>

