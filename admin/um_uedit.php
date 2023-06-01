<?php
/**  um_uedit.php - adds new user to permission system
 *     optionally $Msg to show under <h1>Headline</h1> (typically: update successful)
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
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/


use AA\Util\InputVars;

$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php)
// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/um_util.php";

try {
    // array ( 1 => 3, )-array ( 1 => 5, )
    $UsrSrch = InputVars::boolGETPOST('UsrSrch'); // -
    // array ( 'Přidat [^…' => 1, )
    $add_submit = InputVars::boolPOST('add_submit');
    // array ( 'Jeď!' => 2, )
    $go_finduser = InputVars::boolGET('go_finduser');
    // array ( )
    $new_module = InputVars::arrayPOST('new_module');
    // array ( )
    $new_module_role = InputVars::arrayPOST('new_module_role');
    // array ( 0 => 1, '' => 1, )
    // comma separated list of user groups
    $posted_groups = InputVars::stringPOST('posted_groups'); // empty
    // array ( 'P' => 1, )
    // $sel_groups_selp = InputVars::stringSlashedPOST('sel_groups_selp'); // alpha
    // array ( 5 => 1, 4 => 1, 1 => 1, 2 => 1, 6 => 2, )-array ( '' => 1, 6 => 3, 2 => 1, )
    $selected_user = InputVars::stringGETPOST('selected_user'); // -
    // array ( 0 => 4, 'update_subm…' => 1, )
    $submit_action = InputVars::stringPOST('submit_action');
    // array ( 'Matěj' => 2, )
    $user_firstname = InputVars::stringPOST('user_firstname');
    // array ( 'hejny' => 1, )
    $user_login = InputVars::stringPOST('user_login'); // alpha
    // array ( 'mati.hejny@…' => 2, )
    $user_mail1 = InputVars::stringPOST('user_mail1');
    $user_mail2 = InputVars::stringPOST('user_mail2');
    $user_mail3 = InputVars::stringPOST('user_mail3');
    // array ( '...' => 1, 'nOnEwpAsswD' => 1, )
    $user_password1 = InputVars::stringPOST('user_password1');
    // array ( '...' => 1, 'nOnEwpAsswD' => 1, )
    $user_password2 = InputVars::stringPOST('user_password2');
    // array ( 'on' => 2, )
    $user_super = InputVars::boolPOST('user_super');
    $um_uedit_no_go_url = InputVars::boolPOST('user_super');
    // array ( 'Hejný' => 2, )
    $user_surname = InputVars::stringPOST('user_surname');
    // array ( 'hejny' => 1, 'asdfghjkl' => 1, 'b' => 1, )-array ( 'hejn' => 1, 'asdfghjkl' => 2, '' => 2, )
    $usr = InputVars::stringGETPOST('usr'); // alpha-alpha
    $grp = InputVars::stringGETPOST('grp'); // alpha-alpha
    // array ( 1 => 6, )-array ( 'Editace' => 3, 1 => 1, )
    $usr_edit = InputVars::boolGETPOST('usr_edit'); // -alnum
    // array ( 1 => 3, )-array ( 1 => 1, )
    $usr_new = InputVars::boolGETPOST('usr_new'); // -
    // array ( 3 => 3, 7 => 1, )-array ( 3 => 6, '011ba695b78…' => 1, '' => 1, 7 => 1, )
    $selected_group = InputVars::stringGETPOST('selected_group'); // -alnum
    // array ( )
    $perm_mod = InputVars::arrayPOST('perm_mod'); // alpha
    // array ( '' => 4, )
    $usr1_flt = InputVars::stringPOST('usr1_flt'); // empty
    $grp1_flt = InputVars::stringPOST('grp1_flt'); // empty
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_NEW_USER)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php3", _m("No permission to create new user"), "admin");
    exit;
}

if ( ($submit_action == "usr_del") AND $selected_user ) {
    DelUser( $selected_user );    // default is to delete any references as well
    go_url( StateUrl($_SERVER['PHP_SELF']) );
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

$sel_groups = [];
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

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

// Process submited form -------------------------------------------------------

if ($add_submit OR ($submit_action == "update_submit")) {

    $err = ProcessUser($submit_action, $selected_user, $err, $add_submit, $user_login, $user_surname, $user_firstname, $user_password1, $user_password2, $user_mail1, $user_mail2, $user_mail3, $user_super, $um_uedit_no_go_url, $posted_groups, $sel_groups, $perm_mod, $new_module, $new_module_role);

    if (!count($err) ) {
        $Msg = MsgOk( $add_submit ? _m("User successfully added to permission system") : _m("User data modified"));
        go_url(get_admin_url('', ['usr_edit'=>1, 'selected_user'=>$selected_user, 'Msg'=>$Msg])); // @todo Mssg
    }
}


$headcode = "
<script>
  function UpdateUser(action) {
    document.fx.posted_groups.value = CommaDelimeted( 'sel_groups_sel' )
    document.fx.submit_action.value = action
    document.fx.submit()
  }

  function RealyDelete() {
    if ( window.confirm('". _m("Are you sure you want to delete selected user from whole permission system?") ."')) {
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
</script>";


$apage = new AA_Adminpageutil('aaadmin',$usr_new ? "u_new" : "u_edit");
$apage->setTitle(_m("User management").' - '. ($usr_new ? _m("New User") : _m("Edit User")));
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->addRequire(get_aa_url('javascript/inputform.min.js?v='.AA_JS_VERSION, '', false)); // for MoveSelected(), Moveitem()
$apage->addRequire(get_aa_url('javascript/js_lib.min.js?v='.AA_JS_VERSION, '', false)); // for CommaDelimited
$apage->setForm();
$apage->printHead($err, $Msg);

FrmTabCaption(_m("Users"));
?>
 <tr><td>
   <form method="post" action="<?php echo StateUrl() ?>">
    <table>
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
  <td><form name="f2" method="post" action="<?php echo StateUrl() ?>">
    <table>
     <tr class="aa-tbl-header">
            <th width="20%"><?php echo _m("User") ?></th>
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
    $apage->printFoot();
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
            if (IsPerm($aa_users[$selected_user], AA_Perm::ROLES["SUPER"]['id'])) {
                $user_super = true;
            }
        }
    }
} while (false);


?>
<br />
<form name="fx" method="post" autocomplete="off" action="<?php echo StateUrl() ?>">
<?php

// User data ---------------------------------------------------

if ( $usr_edit OR ($submit_action == "update_submit") ) {
    FrmTabCaption(_m("Edit User"));
    FrmStaticText(_m("Login name"), $user_data['login']);
    FrmStaticText(_m("User Id"), $user_data['id']);
} else {
    FrmTabCaption(_m("New user"));
    FrmInputText("user_login", _m("Login name"), $user_login, 50, 50, true);
}

if ( !($usr_edit OR ($submit_action == "update_submit")) OR AA::$perm->isUserEditable($selected_user)) {
    FrmInputPwd("user_password1", _m("Password"), $user_password1);
    FrmInputPwd("user_password2", _m("Retype password"), $user_password2);
    FrmInputText("user_firstname", _m("First name"), $user_firstname, 50, 50, true);
    FrmInputText("user_surname", _m("Surname"), $user_surname, 50, 50, true);
    FrmInputEmail("user_mail1",    _m("E-mail")." 1",    $user_mail1, 50, 50, true);
}

//  FrmInputEmail("user_mail2",_m("E-mail")." 2",    $user_mail2, 50, 50, false);  // removed for compatibility with perm_sql.php
//  FrmInputEmail("user_mail3",_m("E-mail")." 3",    $user_mail3, 50, 50, false);
FrmInputChBox("user_super",   _m("Superadmin account"), $user_super);

if ( !$add_submit AND !$usr_new) {

    // User - group membership -----------------------------------------

    FrmTabSeparator(_m("Groups"));
    array_walk($all_groups, function (&$v, $k) {$v = ((($userlogin = AA::$perm->getUserLoginName($k)) == $v['name']) OR !$userlogin) ? $v['name'] : $v['name']. " ($userlogin)";});
    $writable_groups        = array_filter($all_groups, [AA::$perm, 'isGroupWritable'], ARRAY_FILTER_USE_KEY);
    $user_writable_groups   = array_filter($sel_groups, [AA::$perm, 'isGroupWritable'], ARRAY_FILTER_USE_KEY);
    $user_unwritable_groups = array_diff_key($sel_groups, $user_writable_groups);

    FrmTwoBox('sel_groups_sel', _m("Groups"), $writable_groups, array_keys($user_writable_groups), 8, false, _m("All Writable Groups"), _m("User's Groups"));

    array_walk($user_unwritable_groups, function (&$v, $k) {$v = ((($userlogin = AA::$perm->getUserLoginName($k)) == $v['name']) OR !$userlogin) ? $v['name'] : $v['name']. " ($userlogin)";});

    if ($user_unwritable_groups) {
        FrmStaticText(_m("Member of Unwritable Groups"), join("<br>\n", $user_unwritable_groups), '', '', false);
    }




//    echo '<tr><td width="190">'. _m("All Groups") .'</td>
//                    <td width="60">&nbsp;</td>
//                    <td width="190">'. _m("User's Groups") .'</td></tr>
//          <tr><td><input type="text" name="grp1_flt" value="'. safe($grp1_flt) .'">
//                  <input type="submit" name="grp1_submit" value="'. _m("Search") .'"></td>
//                    <td>&nbsp;</td>
//                    <td>&nbsp;</td></tr>
//          <tr><td>';
//                SelectGU_ID("all_groups_sel", array_diff_key($all_groups, $sel_groups), $all_groups_sel, "multi");
//    echo '    </td>
//              <td><input type="button" VALUE="  &#9654;  " onClick = "MoveSelected(document.fx.all_groups_sel, document.fx.sel_groups_sel\')"><br><br>
//                  <input type="button" VALUE="  &#9664;  " onClick = "MoveSelected(document.fx.sel_groups_sel, document.fx.all_groups_sel\')"></td>
//                    <td>';
//                SelectGU_ID("sel_groups_sel", $sel_groups, $sel_groups_sel, "multi");
//    echo '    </td>
//          </tr>';

    // User - permissions -----------------------------------------
    $mod_types = PrintModulePermModificator($selected_user);   // shared with um_gedit.php
}

if ($usr_new or $add_submit) {
    // buttons for adding new user
    $form_buttons = [
        "add_submit"=> [
            "value"=>_m("Add"),
            "type"=>"submit",
            "accesskey"=>"S"
        ],
        "usr_new"=> ["value"=>"1"]
    ];
} else {
    // buttons for update user
    $form_buttons = [
        "submit_button"=> [
            "value"=>_m("Update"),
            "type"=>"button",
            "add"=>'onclick="UpdateUser(\'update_submit\')"',
            "accesskey"=>"S"
        ],
        "usr_edit"=> ["value"=>"1"]
    ];
}

// add common fields
$form_buttons["selected_user"] = ["value"=>$selected_user];
$form_buttons["posted_groups"] = ["value"=>"0"];
$form_buttons["submit_action"] = ["value"=>"0"];
$form_buttons["cancel"]        = ["url"=>"um_uedit.php"];


FrmTabEnd($form_buttons);

if ( !$add_submit AND !$usr_new) {
    PrintPermUmPageEnd($MODULES, $mod_types);
}

$apage->printFoot();
