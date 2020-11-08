<?php
/**
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

use AA\IO\DB\DB_AA;

require_once __DIR__."/constants.php3";

/** SelectGU_ID function
 * Prints html tag <select ..
 * @param $name
 * @param $arr
 * @param $selected
 * @param $type
 */
function SelectGU_ID($name, $arr, $selected="", $type="short") {
    if ( !is_array($arr)) {
        $arr = [];
    }
    array_walk($arr, function (&$v, $k) {$v = ((($userlogin = AA::$perm->getUserLoginName($k)) == $v['name']) OR !$userlogin) ? $v['name'] : $v['name']. " ($userlogin)";});
    echo getFrmSelectEasy($name, $arr, $selected, "id=\"$name\"". ($type=="short" ? '' : ' size=8'). ($arr ? '' : ' style="min-width:100px;"'), true);
}

/** GetFiltered function
 * @param $type
 * @param $filter
 * @param $to_much
 * @param $none
 * @return array
 */
function GetFiltered($type, $filter, $to_much, $none) {
    switch( $type ) {
        case "U": $list = AA::$perm->findUsernames($filter);  break;
        case "G": $list = AA::$perm->findGroups($filter); break;
    }
    if ( !is_array($list) OR !count($list)) {
        unset($list);
        $list["n"]['name'] = (( $list == "too much" ) ? $to_much : $none);
    }
    return $list;
}

/** PrintModulePermModificator function
 * @param $selected_user
 * @return string
 */
function PrintModulePermModificator($selected_user) {
    $perm_slices = AA::$perm->getUserPerms($selected_user, "slice", 1);  // there are not only Slices, but other Modules too
    $all_modules = DB_AA::select(['unid'=> []],'SELECT LOWER(HEX(`id`)) AS unid, name, type FROM module', '', ['type', 'name']);
    $i=0;
    $current_perm_table = '';
    foreach ( $all_modules as $mid => $mod ) {
        if ( $perm_slices[$mid] ) {
            $odd = ((gettype($i/2) == "integer") ? true : false);
            $current_perm_table .= GetModulePermRow($mid, $mod['type'], $mod['name'], $perm_slices[$mid], $odd);
            $i++;
        } else {               // no permission to this module
            // this module should be listed in 'Add perm' listbox
            $mod_2B_add .= "<option value=\"$mid\">". safe($mod['name']) .'</option>';
            $mod_types .= GetModuleLetter($mod['type']);  // string for javascript
        }                       // to know, what type of module the $mod_2B_add is
    }

    if ($current_perm_table) {
        FrmTabSeparator(_m("Permissions"));
        echo '<tr>
            <td><b>'. _m("Object") .'</b></td>
            <td><b>'. _m("Permissions") .'</b></td>
            <td><b>'. _m("Revoke") .'</b></td></tr>';
        echo $current_perm_table;
    }

    FrmTabSeparator(_m("Assign new permissions"));
    ?>
   <tr><td>
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
    <?php
    if ( isset($mod_2B_add) ) {          // there is some module to add
        PrintModuleAddRow($mod_2B_add, 1);
        PrintModuleAddRow($mod_2B_add, 2);
        PrintModuleAddRow($mod_2B_add, 3);
        PrintModuleAddRow($mod_2B_add, 4);
        PrintModuleAddRow($mod_2B_add, 5);
        PrintModuleAddRow($mod_2B_add, 6);
        PrintModuleAddRow($mod_2B_add, 7);
    }
    ?>
    </table></td></tr>
    <?php
    return $mod_types;
}

/** GetModulePermRow function
 * @param $mid
 * @param $type
 * @param $name
 * @param $perm
 * @param $odd
 * @return string
 */
function GetModulePermRow($mid, $type, $name, $perm, $odd=false) {
    global $MODULES, $perms_roles_modules, $perms_roles;
    $ret = "<tr>
        <td ".($odd ? " bgcolor=\"".COLOR_BACKGROUND."\"" : "")." align='top'>".$MODULES[$type]['name'] .":&nbsp;$name<br>&nbsp;&nbsp;&nbsp;&nbsp;($mid)</td>
        <td ".($odd ? " bgcolor=\"".COLOR_BACKGROUND."\"" : "")." nowrap align='top'>";
    if ( isset($perms_roles_modules[$type]) AND is_array($perms_roles_modules[$type]) ) {
        foreach ($perms_roles_modules[$type] as $role) {
            $ret .= "<label><input type=\"radio\" name=\"perm_mod[x$mid]\" value=\"$role\"";
            $ret .= ( AA_Perm::compare($perm,$perms_roles[$role]['id'])=='E' ) ? ' checked>' : '>';
            $ret .= _mdelayed($role). '</label> ';
        }
    } else {
        $ret .= "<label><input type=\"radio\" name=\"perm_mod[x$mid]\" value=\"ADMINISTRATOR\" checked>" . _m('ADMINISTRATOR'). '</label>';
    }
    $ret .= "  </td>
    <td ".($odd ? " bgcolor=\"".COLOR_BACKGROUND."\"" : "")." nowrap align='top'>
    <label><input type=\"radio\" name=\"perm_mod[x$mid]\" value=\"REVOKE\">". _m("Revoke") ."</label></td>
    </tr>";
    return $ret;
}

/** PrintModuleAddRow function
 * @param $mod_options
 * @param $no
 */
function PrintModuleAddRow($mod_options, $no) {
    echo "<tr>
           <td><select name=\"new_module[$no]\" onchange=\"SetRole($no)\">
                 <option> </option>
                 $mod_options</select></td>
           <td><select name=\"new_module_role[$no]\">
                  <option> </option>
                  <option value=\"AUTHOR\">". _m('AUTHOR'). "</option>
                  <option value=\"EDITOR\">". _m('EDITOR'). "</option>
                  <option value=\"ADMINISTRATOR\">". _m('ADMINISTRATOR'). "</option>
               </select></td>
          </tr>";
}


/** ChangeUserModulePerms function
 *  Change module permissions if user wants
 * Works not only with users, but with groups too
 * @param $perm_mod
 * @param $selected_user
 * @param $perms_roles
 */
function ChangeUserModulePerms( $perm_mod, $selected_user, $perms_roles ) {
    if ( isset($perm_mod) AND is_array($perm_mod) ) {
        $perm_slices = AA::$perm->getUserPerms($selected_user, "slice", 1);  // there are not only Slices, but other Modules too
        foreach ($perm_mod as $xmid => $role) {
            $mid = substr($xmid,1);   // removes first 'x' character (makes index string)
            if ( $role == 'REVOKE' ) {
                DelPerm($selected_user, $mid, 'slice');
            }
            elseif ( AA_Perm::compare($perm_slices[$mid], $perms_roles[$role]['id']) != 'E' ) {
                ChangePerm($selected_user, $mid, 'slice', $perms_roles[$role]['id']);
            }
        }
    }
}

/** AddUserModulePerms function
 *  Add new modules for this user
 *  Works not only with users, but with groups too
 * @param $new_module
 * @param $new_module_role
 * @param $selected_user
 * @param $perms_roles
 */
function AddUserModulePerms( $new_module, $new_module_role, $selected_user, $perms_roles) {
    if ( isset($new_module) AND is_array($new_module) ) {
        foreach ($new_module as $no => $mid) {
            if ( (trim($mid) != "") AND isset($perms_roles[$new_module_role[$no]]) ) {
                AddPerm($selected_user, $mid, 'slice', $perms_roles[$new_module_role[$no]]['id']);
            }
        }
    }
}

/** GetModuleLetter function
 * Returned Module letter is used for as full identification of the module
 * by 1-letter long id (we need it for some javascripts in um_util.php3)
 * @param $type
 * @return bool|string
 */
function GetModuleLetter($type) {
    global $MODULES;
    // get 'letter' or first letter of MODULE type
    return ($MODULES[$type]['letter'] ? $MODULES[$type]['letter'] : substr($type,0,1));
}

/** PrintPermUmPageEnd function
 * @param $MODULES
 * @param $mod_types
 * @param $perms_roles_modules
 */
function PrintPermUmPageEnd($MODULES, $mod_types, $perms_roles_modules) { ?>
    <script>
      var mod       = [];
      var mod_names = [];
    <?php
    // tell javascript, which module uses which permission roles
    echo "\n var mod_types='$mod_types';\n";
    foreach ($MODULES as $k => $v) {
        $letter = GetModuleLetter($k);             // get 'letter' or first letter of MODULE type
        if ( isset($perms_roles_modules[$k]) AND is_array($perms_roles_modules[$k]) ) {
            echo " mod[".ord($letter)."] = new Array('". join("','", $perms_roles_modules[$k]) ."');  // module type $k \n";
            echo " mod_names[".ord($letter)."] = new Array('". join("','", array_map( '_mdelayed', $perms_roles_modules[$k])) ."');\n";
        }
    }
    ?>
      // set right roles for modules listed in 'Add rows'
      SetRole(1);
      SetRole(2);
      SetRole(3);
      SetRole(4);
      SetRole(5);
      SetRole(6);
      SetRole(7);
    </script>
    <?php
}

/** ChangeUserGroups function
 *  Procces group data
 * @param $posted_groups
 * @param $sel_groups
 * @param $selected_user
 */
function ChangeUserGroups($posted_groups, $sel_groups, $selected_user) {
    if ( isset($sel_groups) AND is_array($sel_groups) AND ($sel_groups["n"]=="")) {
        // first we remove user from all groups
        foreach ($sel_groups as $foo_gid => $foo) {
            DelGroupMember($foo_gid, $selected_user);
        }
    }
    // now we add user to specified groups

    // posted_groups contains comma delimeted list of selected groups for user
    $assigned_groups = explode(",",$posted_groups);
    foreach ($assigned_groups as $foo_gid) {
        if ($foo_gid) {
            AddGroupMember(urldecode($foo_gid), $selected_user);
        }
    }
}

/** FillUserRecord function
 * @param $err
 * @param $user_login
 * @param $user_surname
 * @param $user_firstname
 * @param $user_password1
 * @param $user_password2
 * @param $user_mail1
 * @param $user_mail2
 * @param $user_mail3
 * @return array
 */
function FillUserRecord(&$err, $user_login, $user_surname, $user_firstname, $user_password1, $user_password2,  $user_mail1, $user_mail2, $user_mail3) {

    $userrecord = [];
    ValidateInput("user_login", _m("Login name"), $user_login, $err, true, "login");
    ValidateInput("user_surname", _m("Surname"), $user_surname, $err, true, "text");
    ValidateInput("user_firstname", _m("First name"), $user_firstname, $err, true, "text");
    // if unchanged password, don't post it
    if (($user_password1 != "nOnEwpAsswD") OR ($user_password2 != "nOnEwpAsswD")) {
        ValidateInput("user_password1", _m("Password"), $user_password1, $err, true, "password");
        ValidateInput("user_password2", _m("Retype password"), $user_password2, $err, true, "password");
        if ( $user_password1 != $user_password2 ) {
            $err[$user_password2] = MsgErr(_m("Retyped password is not the same as the first one"));
        }
        $userrecord["userpassword"]        = $user_password1;
    }
    ValidateInput("user_mail1", _m("E-mail")." 1", $user_mail1, $err, true, "email");
    ValidateInput("user_mail2", _m("E-mail")." 2", $user_mail2, $err, false, "email");
    ValidateInput("user_mail3", _m("E-mail")." 3", $user_mail3, $err, false, "email");

    $userrecord["givenname"]               = $user_firstname;
    $userrecord["sn"]                      = $user_surname;

    if ($user_mail1) {
        $userrecord["mail"][] = $user_mail1;
    }
    if ($user_mail2) {
        $userrecord["mail"][] = $user_mail2;
    }
    if ($user_mail3) {
        $userrecord["mail"][] = $user_mail3;
    }
    return $userrecord;
}
/** NewUserData function
 * @param $err
 * @param $uid
 * @param $userrecord
 * @param $user_super
 * @param $perms_roles
 * @param $um_uedit_no_go_url
 */
function NewUserData( &$err, $uid, &$userrecord, $user_super, $perms_roles, $um_uedit_no_go_url) {
    $userrecord["uid"] = $uid;
    if (!($newuserid = AddUser($userrecord))) {
        $err["LDAP"] = MsgErr( _m("It is impossible to add user to permission system") );
    }
    if ( !count($err) ) {
        if ($user_super) {	// set super admin privilege
            AddPerm($newuserid, AA_ID, "aa", $perms_roles["SUPER"]['id']);
        }
        $Msg = MsgOk(_m("User successfully added to permission system"));
        if (!$um_uedit_no_go_url) {
            go_url( get_url(StateUrl($_SERVER['PHP_SELF']), 'UsrSrch=1&usr='. urlencode($uid)), $Msg);
        }
    }
}
/** ChangeUserData function
 * @param $err
 * @param $uid
 * @param $userrecord
 * @param $user_super
 * @param $perms_roles
 */
function ChangeUserData( &$err, $uid, &$userrecord, $user_super, $perms_roles) {
      $userrecord["uid"] = $uid;
      if (!ChangeUser($userrecord)) {
          $err["LDAP"] = MsgErr( _m("Can't change user") );
      } elseif ($user_super != 'AA_NO_CHANGE') {
          if ($user_super) {		// set or revoke super admin privilege
              AddPerm($userrecord["uid"], AA_ID, "aa", $perms_roles["SUPER"]['id']);
          } else {
              DelPerm($userrecord["uid"], AA_ID, "aa");
          }
      }
}

?>
