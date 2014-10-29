<?php
/**  um_uedit.php3 - adds new user to permission system
 *     optionaly $Msg to show under <h1>Headline</h1> (typicaly: update successful)
 *     selected_user
 *     state variables:
 *        $usr_edit       - comes from um_usrch - button Edit $selected_user
 *        $usr_del        - comes from um_usrch - button Delete $selected_user
 *        $usr_new        - comes from um_inc   - New user link
 *        $submit_action  - = update_submit if pressed update
 *                          = usr_del if delete user is confirmed
 *        $add_submit     - if new user Add button pressed
 *
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php3)
require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."msgpage.php3";
require_once AA_INC_PATH."um_util.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_NEW_USER)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("No permission to create new user"), "admin");
    exit;
}

if ( ($submit_action == "usr_del") AND $selected_user ) {
    DelUser( $selected_user );    // default is to delete any references as well
    go_url( $sess->url($_SERVER['PHP_SELF']) );
}

$sess->register("rusr");
if ( $usr OR $UsrSrch ) {
    $rusr = $usr;
}

if ( $usr_new ) {
    $rusr = $selected_user = "";
}

$users  = GetFiltered("U", $rusr, _m("Too many users or groups found."), _m("No user (group) found"));   // get list of users
if ( $UsrSrch ) {
    reset( $users );
    $selected_user = key($users);
    $usr_edit      = true;
}
$groups = GetFiltered("G", $grp, _m("Too much groups found."), _m("No groups found")); // get list of groups

if ( $grp1_flt ) {  // user editation - list of all groups
    $all_groups = GetFiltered("G", $grp1_flt, _m("Too much groups found."), _m("No groups found"));
} else {
    $all_groups = $groups;  // in user editation is $grp=="", so $groups are list of all groups
}

if ( $usr1_flt ) {  // group editation - list of all users
    $all_users = GetFiltered("U", $usr1_flt, _m("Too many users or groups found."), _m("No user (group) found"));
}  else {
    $all_users = $users;  // in group editation is $rusr=="", so $users are list of all users
}

if ( $selected_user ) {
    if ( $selected_user != "n" ) { // none user selected
        $user_groups = AA::$perm->getMembership($selected_user,1);   // get list of groups in which the user is (just first level groups)
    }
    if ( !isset($user_groups) OR !is_array($user_groups) ) {
        $sel_groups["n"]['name'] = (( $user_groups == "too much" ) ? _m("Too much groups found.") : "");
    } else {
        foreach ($user_groups as $foo_gid) {
            $sel_groups[$foo_gid] = AA::$perm->getGroup($foo_gid);
        }
    }
}

if ( $selected_group ) {
    if ( $selected_group != "n" ) { // none group selected
        $groups_user = AA::$perm->getGroupMembers($selected_group);   // get list of users and groups right under $selected_group
    }
    if ( !isset($group_users) OR !is_array($group_users) ) {
        $sel_users["n"]['name'] = (( $group_users == "too much" ) ? _m("Too many users or groups found.") : "");
    } else {
        $sel_users = $groups_user;
    }
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new Cvarset();

// Process submited form -------------------------------------------------------

if ( $add_submit OR ($submit_action == "update_submit")) {

    // following code (in do {}) is used also in slice wizard
    do  {
        // Procces user data -------------------------------------------------------
        if ( ($submit_action != "update_submit") OR AA::$perm->isUserEditable($selected_user)) {
            $userrecord = FillUserRecord($err, ($add_submit ? $user_login : 'nOnEwlOgiN'), $user_surname, $user_firstname, $user_password1, $user_password2,  $user_mail1, $user_mail2, $user_mail3);
        }

        if ( count($err) > 1) {
            break;
        }

        if ( $add_submit ) {      // -------------------- new user ------------------
            NewUserData($err, $user_login, $userrecord, $user_super, $perms_roles, $um_uedit_no_go_url);
        } elseif (AA::$perm->isUserEditable($selected_user))  {      // ----------------- update user ------------------
            ChangeUserData($err, $selected_user, $userrecord, $user_super, $perms_roles);
        }

        // Procces group data ------------------------------------------------------
        ChangeUserGroups($posted_groups, $sel_groups, $selected_user);

        // Procces module permissions ----------------------------------------------

        // Change module permissions if user wants
        ChangeUserModulePerms( $perm_mod, $selected_user, $perms_roles );

        // Add new modules for this user
        AddUserModulePerms( $new_module, $new_module_role, $selected_user, $perms_roles);

    } while (false);

    if ( count($err) <= 1 ) {
        $Msg = MsgOK( $add_submit ? _m("User successfully added to permission system") : _m("User data modified"));
        go_url( get_url($sess->url($_SERVER['PHP_SELF']), 'usr_edit=1&selected_user='. urlencode($selected_user)), $Msg);
    }
}

// Print HTML start page tags (html begin, encoding, style sheet, but no title)
// Include also js_lib.js javascript library
HtmlPageBegin('default', true);

?>
 <title><?php echo _m("User management - Users");?></title>
<script Language="JavaScript"><!--
  function UpdateUser(action) {
    document.fx.posted_groups.value = CommaDelimeted( 'document.fx.sel_groups_sel' )
    document.fx.submit_action.value = action
    document.fx.submit()
  }

  function RealyDelete() {
    if ( window.confirm('<?php echo _m("Are you sure you want to delete selected user from whole permission system?") ?>')) {
      document.f2.submit_action.value = 'usr_del'
      document.f2.submit()
    }
  }

  // function changes content of role listbox for new module, when user selects another module to be added
  function SetRole(no) {
    var idx=document.fx.elements['new_module['+no+']'].selectedIndex;
    var roles;
    var roles_names;
    // which roles is defined for the module
    if ( idx > 0 ) {
        roles       = mod[mod_types.charCodeAt(idx-1)];
        roles_names = mod_names[mod_types.charCodeAt(idx-1)];
    } else {
        roles       = new Array('                     ');
        roles_names = new Array('                     ');
    }
    // clear selectbox
    for ( i=(document.fx.elements['new_module_role['+no+']'].options.length-1); i>=0; i--){
      document.fx.elements['new_module_role['+no+']'].options[i] = null
    }
    // fill selectbox from the right slice
    for ( i=0; i<roles.length ; i++) {
      document.fx.elements['new_module_role['+no+']'].options[i] = new Option(roles_names[i], roles[i])
    }
  }
// -->
</script>
</head>

<?php

require_once menu_include();   //show navigation column depending on $show
showMenu($aamenus, "aaadmin", $usr_new ? "u_new" : "u_edit");

echo "<h1><b>". ( $usr_new ? _m("New User") : _m("Edit User") )."</b></h1>";
PrintArray($err);
echo $Msg;

?>
<!-- Select user form -->
<?php
FrmTabCaption(_m("Users"));
?>
 <tr><td>
   <form method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align="center">
     <tr>
            <td width="20%">&nbsp;</td>
            <td width="46%"><input type="text" name="usr" value="<?php echo safe($rusr)?>"></td>
            <td width="33%"><input type="submit" value="<?php echo _m("Search")?>">
          <input type="hidden" name="UsrSrch" value="1"></td>
     </tr>
    </table>
   </form>
  </td>
 </tr>
<?php
FrmTabSeparator("");
?>
 <tr>
  <td><form name="f2" method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align="center">
     <tr>
            <td width="20%" class="tabtxt"><b><?php echo _m("User") ?></b></td>
            <td width="46%"><?php SelectGU_ID("selected_user", $users, $selected_user);
          ?></td>
            <td width="33%"><input type="submit" name="usr_edit" value="<?php echo _m("Edit")?>">&nbsp;
                <input type="hidden" name="submit_action" value="0">  <!-- to this variable store "usr_del" (by javascript) -->
                <input type="button" name="usr_del" value="<?php echo _m("Delete")?>" onclick="RealyDelete()"></td>
     </tr>
    </table>
   </form>
<?php
FrmTabEnd();

if ( !($usr_new OR ($usr_edit AND ($selected_user!="n"))) ) {
    HtmlPageEnd();
    page_close();
    exit;
}

do {
    if ($usr_edit ) {
        if ( !is_array($user_data = AA::$perm->getIDsInfo($selected_user))) {
            break;
        }
        $user_login     = $user_data['login'];
        if ( $submit_action != "update_submit") {
            $user_firstname = $user_data['givenname'];
            $user_surname   = $user_data['sn'];
            $user_password1 = "nOnEwpAsswD";    // unchanged password
            $user_password2 = "nOnEwpAsswD";    // unchanged password
            if ( is_array($user_data['mails'])) {
                $user_mail1 = $user_data['mails'][0];
                $user_mail2 = $user_data['mails'][1];
                $user_mail3 = $user_data['mails'][2];
            }
            $aa_users = AA::$perm->getObjectsPerms(AA_ID, "aa");
            if (IsPerm($aa_users[$selected_user], $perms_roles["SUPER"]['id'])) {
                $user_super = true;
            }
        }
    }
} while (false);


?>
<br />
<form name="fx" method="post" autocomplete="off" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php

// User data ---------------------------------------------------

if ( $usr_edit OR ($submit_action == "update_submit") ) {
    FrmTabCaption(_m("Edit User"));
    FrmStaticText( _m("Login name"), $user_data['login']);
    FrmStaticText( _m("User Id"),    $user_data['id']);
} else {
    FrmTabCaption(_m("New user"));
    FrmInputText("user_login", _m("Login name"), $user_login, 50, 50, true);
}

if ( !($usr_edit OR ($submit_action == "update_submit")) OR AA::$perm->isUserEditable($selected_user)) {
    FrmInputPwd("user_password1", _m("Password"),       $user_password1, 50, 50, true);
    FrmInputPwd("user_password2", _m("Retype password"),$user_password2, 50, 50, true);
    FrmInputText("user_firstname",_m("First name"),     $user_firstname, 50, 50, true);
    FrmInputText("user_surname",  _m("Surname"),        $user_surname, 50, 50, true);
    FrmInputText("user_mail1",    _m("E-mail")." 1",    $user_mail1, 50, 50, true);
}

//  FrmInputText("user_mail2",_m("E-mail")." 2",    $user_mail2, 50, 50, false);  // removed for compatibility with perm_sql.php3
//  FrmInputText("user_mail3",_m("E-mail")." 3",    $user_mail3, 50, 50, false);
FrmInputChBox("user_super",   _m("Superadmin account"), $user_super, false, "", 1, false);

if ( !$add_submit AND !$usr_new) {

    // User - group membership -----------------------------------------

    FrmTabSeparator(_m("Groups"));

    echo '<tr><td width="190" align="center">'. _m("All Groups") .'</td>
                    <td width="60">&nbsp;</td>
                    <td width="190" align="center">'. _m("User's Groups") .'</td></tr>
          <tr><td><input type="text" name="grp1_flt" value="'. safe($grp1_flt) .'">
                  <input type="submit" name="grp1_submit" value="'. _m("Search") .'"></td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td></tr>
          <tr><td align="center" valign="top">';
                SelectGU_ID("all_groups_sel", $all_groups, $all_groups_sel, "long", $sel_groups);
    echo '    </td>
              <td><input type="button" VALUE="  >>  " onClick = "MoveSelected(\'document.fx.all_groups_sel\',\'document.fx.sel_groups_sel\')" align=center><br><br>
                  <input type="button" VALUE="  <<  " onClick = "MoveSelected(\'document.fx.sel_groups_sel\',\'document.fx.all_groups_sel\')" align=center></td>
                    <td align="center" valign="TOP">';
                SelectGU_ID("sel_groups_sel", $sel_groups, $sel_groups_sel, "long");
    echo '    </td>
          </tr>';

    // User - permissions -----------------------------------------
    $mod_types = PrintModulePermModificator($selected_user);   // shared with um_gedit.php3
}

if ($usr_new or $add_submit) {
    // buttons for adding new user
    $form_buttons = array("add_submit"=>array("value"=>_m("Add"),
                                              "type"=>"submit",
                                              "accesskey"=>"S"),
                           "usr_new"=>array("value"=>"1"));
} else {
    // buttons for update user
    $form_buttons = array("submit_button"=>array("value"=>_m("Update"),
                                                 "type"=>"button",
                                                 "add"=>'onclick="UpdateUser(\'update_submit\')"',
                                                 "accesskey"=>"S"),
                          "usr_edit"=>array("value"=>"1"));
}

// add common fields
$form_buttons["selected_user"] = array("value"=>$selected_user);
$form_buttons["posted_groups"] = array("value"=>"0");
$form_buttons["submit_action"] = array("value"=>"0");
$form_buttons["cancel"]        = array("url"=>"um_uedit.php3");


FrmTabEnd($form_buttons, $sess, $slice_id);

if ( !$add_submit AND !$usr_new) {
    PrintPermUmPageEnd($MODULES, $mod_types, $perms_roles_modules);
}

HtmlPageEnd();
page_close();
?>