<?php
/**  um_gedit.php - adds and edits groups in permission system (now LDAP directory)
 *    optionally $Msg to show under <h1>Headline</h1> (typically: update successful)
 *    selected_group
 *    state variables:
 *       $grp_edit       - comes from um_gsrch - button Edit $selected_group
 *       $grp_new        - comes from um_inc   - New user link
 *       $submit_action  - = update_submit if pressed update
 *                         = grp_del if delete group is confirmed
 *       $add_submit     - if new group Add button pressed
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
    // array ( 1 => 4, )-array ( 1 => 2, )
    $GrpSrch = InputVars::boolGETPOST('GrpSrch'); // -
    // array ( 'Přidat [^…' => 1, )
    $add_submit = InputVars::boolPOST('add_submit');
    // array ( 'd2be4ff5625…' => 1, '36445fffe87…' => 2, '1bc21a04f1e…' => 1, )
    $all_users_sel = InputVars::idPOST('all_users_sel');
    // array ( 'Jeď!' => 3, )
    $go_findgroup = InputVars::boolGET('go_findgroup');
    // array ( 'test group …' => 2, 'Kachny jsou…' => 1, 'o!:"/(?ˇ' => 1, 'o!:\\"/(?ˇ' => 1, )
    $group_description = InputVars::stringPOST('group_description');
    // array ( 'test group' => 2, '🦆🦆�…' => 1, 'Bbbbbbbbbbb…' => 2, )
    $group_name = InputVars::stringPOST('group_name');
    // array ( 'on' => 2, )
    $group_super = InputVars::boolPOST('group_super'); // alpha
    // array ( 'Bbbbbbbbbbb…' => 1, 'kvaq' => 3, )-array ( 'o' => 1, '' => 1, )
    $grp = InputVars::stringGETPOST('grp'); // alpha-alpha
    // array ( 1 => 4, )-array ( 'Editace' => 4, 1 => 4, )
    $grp_edit = InputVars::boolGETPOST('grp_edit'); // -alnum
    // array ( 1 => 2, )-array ( 1 => 1, )
    $grp_new = InputVars::boolGETPOST('grp_new'); // -
    // array ( )
    $new_module = InputVars::arrayPOST('new_module');
    // array ( )
    $new_module_role = InputVars::arrayPOST('new_module_role');
    // array ( )
    $perm_mod = InputVars::arrayPOST('perm_mod'); // alpha
    // array ( '1,4b108926e…' => 3, 0 => 1, 6 => 1, )
    $posted_users = InputVars::stringPOST('posted_users');
    // array ( '4b108926e80…' => 1, )
    $sel_users_sel = InputVars::idPOST('sel_users_sel');
    // array ( 3 => 3, 7 => 1, )-array ( 3 => 6, '011ba695b78…' => 1, '' => 1, 7 => 1, )
    $selected_group = InputVars::stringGETPOST('selected_group'); // -alnum
    // array ( 0 => 5, 'update_subm…' => 4, )
    $submit_action = InputVars::stringPOST('submit_action');
    // array ( '' => 4, )
    $usr1_flt = InputVars::stringPOST('usr1_flt'); // empty
    $cancel = InputVars::boolGETPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

if (!IfSlPerm(PS_NEW_USER)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("No permission to create new user"), "admin");
    exit;
}

if ( ($submit_action === "grp_del") AND $selected_group ) {
    DelGroup( $selected_group );     // default is to delete any references as well
    go_url( StateUrl($_SERVER['PHP_SELF']) );
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
    if ( $selected_group !== "n" ) { // none group selected
        // get list of users and groups right under $selected_group
        $group_users = AA::$perm->getGroupMembers($selected_group);
    }
    if ( !is_array($group_users) ) {
        $sel_users["n"]['name'] = (( $group_users === "too much" ) ? _m("Too many users or groups found.") : "");
    } else {
        $sel_users = $group_users;
    }
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

// Process submited form -------------------------------------------------------

if ( $add_submit OR ($submit_action === "update_submit")) {
    // all the actions are in following require_once (we reuse this part of code for slice wizard)
    require_once __DIR__ . "/../include/um_gedit.php";
}



$headcode = "
<script>
function UpdateGroup(action) {
  document.f.posted_users.value  = CommaDelimeted( 'sel_users_sel' )
  document.f.submit_action.value = action
  document.f.submit()
}

function RealyDelete() {
  if ( window.confirm('". _m("Are you sure you want to delete selected group from whole permission system?") . "')) {
    document.f2.submit_action.value = 'grp_del'
    document.f2.submit()
  }
}

// function changes content of role listbox for new module, when user selects another module to be added
function SetRole(no) {
    const idx=document.f.elements['new_module['+no+']'].selectedIndex;
    let roles;
    let roles_names;
    // which roles is defined for the module
    if ( idx > 0 ) {
        roles       = mod[mod_types.charCodeAt(idx-1)];
        roles_names = mod_names[mod_types.charCodeAt(idx-1)];
    } else {
        roles       = new Array('                     ');
        roles_names = new Array('                     ');
    }
    // clear selectbox
    for ( let i=(document.f.elements['new_module_role['+no+']'].options.length-1); i>=0; i--){
        document.f.elements['new_module_role['+no+']'].options[i] = null
    }
    // fill selectbox from the right slice
    for ( let i=0; i<roles.length ; i++) {
        document.f.elements['new_module_role['+no+']'].options[i] = new Option(roles_names[i], roles[i])
    }
}
</script>
";

$apage = new AA_Adminpageutil('aaadmin',$grp_new ? "g_new" : "g_edit");
$apage->setTitle(_m("User management - Groups"));
//$apage->setSubtitle($grp_new ? _m("New Group") : _m("Edit Group"));
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->addRequire(get_aa_url('/javascript/js_lib.min.js?v='.AA_JS_VERSION, '', false));
$apage->setForm();
$apage->printHead($err, $Msg);

FrmTabCaption(_m("Groups"));

?>
 <tr><td>
   <form method="post" action="<?php echo StateUrl() ?>">
    <table>
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
  <td><form name="f2" method="post" action="<?php echo StateUrl() ?>">
    <table>
     <tr>
            <th width="20%"><?php echo _m("Group") ?></th>
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
    if ( $grp_new OR ($grp_edit AND ($selected_group !== "n")) ) {
        if ($grp_edit AND !($submit_action === "update_submit")) {
            if ( !is_array($group_data = AA::$perm->getGroup($selected_group))) {
                break;
            }
            $group_name        = $group_data['name'];
            $group_description = $group_data['description'];
            $aa_users          = AA::$perm->getObjectsPerms(AA_ID, "aa");
            if (str_contains($aa_users[$selected_group], AA_Perm::ROLES["SUPER"]['id'])) {
                $group_super   = true;
            }
        }
    } else {
        $apage->printFoot();
        exit;
    }
} while (false);

echo "\n<form name=\"f\" method=\"post\" action=\"".StateUrl() ."\">";

echo "<br />";
if ( $grp_edit OR ($submit_action === "update_submit") ) {
    FrmTabCaption(_m("Edit group"));
} else {
    FrmTabCaption(_m("New group"));
}
?>
</td>
</tr>
<tr><td>
<table>

<?php

// User data ---------------------------------------------------

if ( $grp_new OR $add_submit ) {
    // buttons for adding new group
    $form_buttons = [
        "add_submit" => ["type"=>"submit","value"=>_m("Add"),"accesskey"=>"S"],
        "grp_new" => ["value"=>"1"]
    ];
} else {
    // buttons for update group
    $form_buttons = [
        "submit_button" => [
            "type"=>"button","value"=>_m("Update"),"accesskey"=>"S",
            "add"=>'onclick="UpdateGroup(\'update_submit\')"'
        ],
        "grp_edit" => ["value"=>"1"]
    ];
}

// common "buttons" (hidden fields)
$form_buttons["cancel"]         = ["url"=>"um_gedit.php"];
$form_buttons["selected_group"] = ["value"=>$selected_group];
$form_buttons["posted_users"]   = ["value"=>"0"];
$form_buttons["submit_action"]  = ["value"=>"0"];

if ( $grp_edit OR ($submit_action === "update_submit") ) {
    FrmStaticText(_m("Group Id"), $group_data['uid']);
}

if ($grp_new OR ($selected_group AND AA::$perm->isUserEditable($selected_group))) {
    FrmInputText("group_name", _m("Name"), $group_name, 50, 50, true);
    FrmInputText("group_description", _m("Description"), $group_description, 50, 50);
} else {
    if ($group_name) { FrmStaticText(_m("Name"), $group_name); }
    if ($group_name) { FrmStaticText(_m("Description"), $group_description); }
}

FrmInputChBox("group_super", _m("Superadmin group"), $group_super);
echo '</table></td></tr>';

if ( !$add_submit AND !$grp_new ) {

    if ($selected_group AND AA::$perm->isUserEditable($selected_group)) {

        FrmTabSeparator(_m("Users"));

        echo "
        <tr><td>
        <table>";

        // User - group membership -----------------------------------------

        echo '<tr><td width="190">' . _m("All Users") . '</td>
                      <td width="60">&nbsp;</td>
                      <td width="190">' . _m("Group's Users") . '</td></tr>
            <tr><td><input type="text" name="usr1_flt" value="' . safe($usr1_flt) . '">
                    <input type="submit" name="usr1_submit" value="' . _m("Search") . '"></td>
                      <td>&nbsp;</td>
                      <td>&nbsp;</td></tr>
            <tr><td>';
        SelectGU_ID("all_users_sel", array_diff_key($all_users,$sel_users), $all_users_sel, "multi");
        echo '    </td>
                <td><input type="button" VALUE="  &#9654;  " onClick = "MoveSelected(document.f.all_users_sel, document.f.sel_users_sel)"><br><br>
                    <input type="button" VALUE="  &#9664;  " onClick = "MoveSelected(document.f.sel_users_sel, document.f.all_users_sel)"></td>
                      <td>';
        SelectGU_ID("sel_users_sel", $sel_users, $sel_users_sel, "multi");
        echo '    </td>
            </tr></table></td></tr>
            ';
    }

    // User - permissions -----------------------------------------

    $mod_types = PrintModulePermModificator($selected_group);   // shared with um_gedit.php
}

FrmTabEnd($form_buttons);

echo '</form>';

if ( !$add_submit AND !$grp_new) {
    PrintPermUmPageEnd($MODULES, $mod_types);
}

$apage->printFoot();
