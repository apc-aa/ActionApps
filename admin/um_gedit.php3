<?php
/**  um_gedit.php3 - adds and edits groups in permission system (now LDAP directory)
 *    optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
 *    selected_group
 *    state variables:
 *       $grp_edit       - comes from um_gsrch - button Edit $selected_group
 *       $grp_new        - comes from um_inc   - New user link
 *       $submit_action  - = update_submit if pressed update
 *                         = grp_del if delete group is confirmed
 *       $add_submit     - if new group Add button pressed
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

if ( ($submit_action == "grp_del") AND $selected_group ) {
    DelGroup( $selected_group );     // default is to delete any references as well
    go_url( $sess->url($_SERVER['PHP_SELF']) );
}

$sess->register("rgrp");
if ( $grp OR $GrpSrch ) {
    $rgrp = $grp;
}

if ( $grp_new ) {
    $rgrp = $selected_group = "";
}

$groups  = GetFiltered("G", $rgrp, _m("Too much groups found."), _m("No groups found"));   // get list of users
if ( $GrpSrch ) {
    reset( $groups );
    $selected_group = key($groups);
    $grp_edit       = true;
}

$all_users = GetFiltered("U", $usr1_flt, _m("Too many users or groups found."), _m("No user (group) found"));

if ( $selected_group ) {
    if ( $selected_group != "n" ) { // none group selected
        // get list of users and groups right under $selected_group
        $group_users = GetGroupMembers($selected_group);
    }
    if ( !is_array($group_users) ) {
        $sel_users["n"]['name'] = (( $group_users == "too much" ) ? _m("Too many users or groups found.") : "");
    } else {
        $sel_users = $group_users;
    }
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new Cvarset();


// Process submited form -------------------------------------------------------

if ( $add_submit OR ($submit_action == "update_submit")) {

    // all the actions are in following require_once (we reuse this part of code for
    // slice wizard ...
    require_once AA_INC_PATH."um_gedit.php3";

    if (count($err) <= 1) {
        $Msg = MsgOK(_m("Group successfully added to permission system"));
        go_url( get_url($sess->url($_SERVER['PHP_SELF']), 'grp_edit=1&selected_group='. urlencode($selected_group)), $Msg);
    }
}

// Print HTML start page tags (html begin, encoding, style sheet, but no title)
// Include also js_lib.js javascript library
HtmlPageBegin('default', true);
?>
 <title><?php echo _m("User management - Groups");?></title>
<script Language="JavaScript"><!--
function UpdateGroup(action) {
  var foo= CommaDelimeted( 'document.f.sel_users_sel' )
  document.f.posted_users.value = foo
  document.f.submit_action.value = action
  document.f.submit()
}

function RealyDelete() {
  if ( window.confirm('<?php echo _m("Are you sure you want to delete selected group from whole permission system?") ?>')) {
    document.f2.submit_action.value = 'grp_del'
    document.f2.submit()
  }
}

// function changes content of role listbox for new module, when user selects another module to be added
function SetRole(no) {
    var idx=document.f.elements['new_module['+no+']'].selectedIndex;
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
    for ( i=(document.f.elements['new_module_role['+no+']'].options.length-1); i>=0; i--){
        document.f.elements['new_module_role['+no+']'].options[i] = null
    }
    // fill selectbox from the right slice
    for ( i=0; i<roles.length ; i++) {
        document.f.elements['new_module_role['+no+']'].options[i] = new Option(roles_names[i], roles[i])
    }
}

// -->
</script>
</head>

<?php
require_once menu_include();   //show navigation column depending on $show
showMenu($aamenus, "aaadmin", $grp_new ? "g_new" : "g_edit");

echo "<h1><b>". ( $grp_new ? _m("New Group") : _m("Edit Group") )."</b></h1>";
PrintArray($err);
echo $Msg;

FrmTabCaption(_m("Groups"));

?>
 <tr><td>
   <form method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align="center">
     <tr>
            <td width="20%">&nbsp;</td>
            <td width="46%"><input type="text" name="grp" value="<?php echo safe($rgrp)?>"></td>
            <td width="33%"><input type="submit" value="<?php echo _m("Search")?>">
          <input type="hidden" name="GrpSrch" value="1"></td>
     </tr>
    </table>
   </form>
  </td>
<?php
FrmTabSeparator("");
?>
 <tr>
  <td><form name="f2" method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
     <tr>
            <td width="20%"class="tabtxt"><b><?php echo _m("Group") ?></b></td>
            <td width="46%"><?php SelectGU_ID("selected_group", $groups, $selected_group);
          ?></td>
            <td width="33%"><input type="submit" name="grp_edit" value="<?php echo _m("Edit")?>">&nbsp;
                <input type="hidden" name="submit_action" value=0>  <!-- to this variable store "usr_del" (by javascript) -->
                <input type="button" name="grp_del" value="<?php echo _m("Delete")?>" onclick="RealyDelete()"></td>
     </tr>
    </table>
   </form>
<?php

FrmTabEnd();

do {
    if ( $grp_new OR ($grp_edit AND ($selected_group!="n")) ) {
        if ($grp_edit AND !($submit_action == "update_submit")) {
            if ( !is_array($group_data = AA::$perm->getGroup($selected_group))) {
                break;
            }
            $group_name        = $group_data['name'];
            $group_description = $group_data['description'];
            $aa_users          = GetObjectsPerms(AA_ID, "aa");
            if (strstr($aa_users[$selected_group]["perm"], $perms_roles["SUPER"]['id'])) {
                $group_super   = true;
            }
        }
    } else {
        HtmlPageEnd();
        page_close();
        exit;
    }
} while (false);

echo "\n<form name=\"f\" method=\"post\" action=\"".$sess->url($_SERVER['PHP_SELF']) ."\">";

echo "<br />";
if ( $grp_edit OR ($submit_action == "update_submit") ) {
    FrmTabCaption(_m("Edit group"));
} else {
    FrmTabCaption(_m("New group"));
}
?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align="center">

<?php

// User data ---------------------------------------------------

if ( $grp_new OR $add_submit ) {
    // buttons for adding new group
    $form_buttons = array("add_submit" => array("type"=>"submit","value"=>_m("Add"),"accesskey"=>"S"),
                          "grp_new" => array("value"=>"1"));
} else {
    // buttons for update group
    $form_buttons = array("submit_button" => array("type"=>"button","value"=>_m("Update"),"accesskey"=>"S",
                                                   "add"=>'onclick="UpdateGroup(\'update_submit\')"'),
                          "grp_edit" => array("value"=>"1"));
}

// common "buttons" (hidden fields)
$form_buttons["cancel"]         = array("url"=>"um_gedit.php3");
$form_buttons["selected_group"] = array("value"=>$selected_group);
$form_buttons["posted_users"]   = array("value"=>"0");
$form_buttons["submit_action"]  = array("value"=>"0");

if ( $grp_edit OR ($submit_action == "update_submit") ) {
    FrmStaticText( _m("Group Id"), $group_data['uid']);
}
FrmInputText("group_name", _m("Name"), $group_name, 50, 50, true);
FrmInputText("group_description", _m("Description"), $group_description, 50, 50, false);
FrmInputChBox("group_super", _m("Superadmin group"), $group_super, false, "", 1, false);
echo '</table></td></tr>';

if ( !$add_submit AND !$grp_new) {
    FrmTabSeparator(_m("Users"));

    echo "
  <tr><td>
  <table width=\"440\" border=\"0\" cellspacing=\"0\" cellpadding=\"4\" bgcolor=\"".COLOR_TABBG."\">";

  // User - group membership -----------------------------------------

    echo '<tr><td width="190" align="center">'. _m("All Users") .'</td>
                  <td width="60">&nbsp;</td>
                  <td width="190" align="center">'. _m("Group's Users") .'</td></tr>
        <tr><td><input type="text" name="usr1_flt" value="'. safe($usr1_flt) .'">
                <input type="submit" name="usr1_submit" value="'. _m("Search") .'"></td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td></tr>
        <tr><td align="center" valign="TOP">';
              SelectGU_ID("all_users_sel", $all_users, $all_users_sel, "long", $sel_users);
    echo '    </td>
            <td><input type="button" VALUE="  >>  " onClick = "MoveSelected(\'document.f.all_users_sel\',\'document.f.sel_users_sel\')" align="center"><br><br>
                <input type="button" VALUE="  <<  " onClick = "MoveSelected(\'document.f.sel_users_sel\',\'document.f.all_users_sel\')" align="center"></td>
                  <td align="CENTER" valign="TOP">';
              SelectGU_ID("sel_users_sel", $sel_users, $sel_users_sel, "long");
    echo '    </td>
        </tr></table></td></tr>
        ';

  // User - permissions -----------------------------------------

  $mod_types = PrintModulePermModificator($selected_group, $form_buttons, $sess, $slice_id);   // shared with um_gedit.php3
}

FrmTabEnd($form_buttons, $sess, $slice_id);

echo '</form>';

if ( !$add_submit AND !$grp_new) {
    PrintPermUmPageEnd($MODULES, $mod_types, $perms_roles_modules);
}

HtmlPageEnd();
page_close();
?>