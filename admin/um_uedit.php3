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
#    $add_submit     - if new user Add button pressed


$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php3)
require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."msgpage.php3";
require $GLOBALS[AA_INC_PATH]."um_util.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_NEW_USER)) {
  MsgPageMenu($sess->url(self_base())."index.php3", L_NO_PS_NEW_USER, "admin");
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

# Process submited form -------------------------------------------------------

if( $add_submit OR ($submit_action == "update_submit")) {

  # all the actions are in following require (we reuse this part of code for
  # slice wizard ...
  require $GLOBALS[AA_INC_PATH]."um_uedit.php3";

  if( count($err) <= 1 ) {
    $Msg = MsgOK(L_NEWUSER_OK);
    go_url( con_url($sess->url($PHP_SELF), 'usr_edit=1&selected_user='. urlencode($selected_user)), $Msg);
  }
}

# HtmlPageBegin----------------------------------------

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
include $GLOBALS[AA_BASE_PATH]."javascript/js_lib.js";
?>
 <TITLE><?php echo L_A_UM_USERS_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--
  function UpdateUser(action) {
    var foo= CommaDelimeted( 'document.fx.sel_groups_sel' )
    document.fx.posted_groups.value = foo
    document.fx.submit_action.value = action
    document.fx.submit()
  }

  function RealyDelete() {
    if( window.confirm('<?php echo L_REALY_DELETE_USER ?>')) {
      document.f2.submit_action.value = 'usr_del'
      document.f2.submit()
    }
  }

  // function changes content of role listbox for new module, when user selects another module to be added
  function SetRole(no) {
    var idx=document.fx.elements['new_module['+no+']'].selectedIndex;
    var roles;
    // which roles is defined for the module
    roles = ( idx > 0 ) ? mod[mod_types.charCodeAt(idx-1)] : new Array('                     ');
    // clear selectbox
    for( i=(document.fx.elements['new_module_role['+no+']'].options.length-1); i>=0; i--){
      document.fx.elements['new_module_role['+no+']'].options[i] = null
    }
    // fill selectbox from the right slice
    for( i=0; i<roles.length ; i++) {
      document.fx.elements['new_module_role['+no+']'].options[i] = new Option(roles[i], roles[i])
    }
  }
// -->
</SCRIPT>
</HEAD>

<?php

  require $MODULES[$g_modules[$slice_id]['type']]['menu'];   //show navigation column depending on $show
  showMenu ($aamenus, "aaadmin", $usr_new ? "u_new" : "u_edit");

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
if( !($usr_new OR ($usr_edit AND ($selected_user!="n"))) ) {
  echo '</BODY></HTML>';
  page_close();
  exit;
}

do {
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
    if (IsPerm($aa_users[$selected_user]["perm"], $perms_roles["SUPER"]['id'])) {
      $user_super = true;
    }
  }
} while(false);


?>
<form name=fx method=post action="<?php echo $sess->url($PHP_SELF) ?>">
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
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
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

if( !$add_submit AND !$usr_new) {

  # User - group membership -----------------------------------------?>

  <tr><td class=tabtit><b>&nbsp;<?php echo L_GROUPS?></b></td></tr>
  <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
  <?php
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
            <td><input type="button" VALUE="  >>  " onClick = "MoveSelected(\'document.fx.all_groups_sel\',\'document.fx.sel_groups_sel\')" align=center><br><br>
                <input type="button" VALUE="  <<  " onClick = "MoveSelected(\'document.fx.sel_groups_sel\',\'document.fx.all_groups_sel\')" align=center></td>
                  <td align="CENTER" valign="TOP">';
              SelectGU_ID("sel_groups_sel", $sel_groups, $sel_groups_sel, "long");
  echo '    </td>
        </tr>
      </table></td></tr>';

  # User - permissions -----------------------------------------

  $mod_types = PrintModulePermModificator($selected_user);   # shared with um_gedit.php3

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
echo '</td></tr></table></FORM>';

if( !$add_submit AND !$usr_new) {
  PrintPermUmPageEnd($MODULES, $mod_types, $perms_roles_modules);
}

HtmlPageEnd();
page_close(); ?>