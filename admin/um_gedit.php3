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

# um_gedit.php3 - adds and edits groups in permission system (now LDAP directory)
# optionaly $Msg to show under <h1>Hedline</h1> (typicaly: update successful)
# selected_group
# state variables:
#    $grp_edit       - comes from um_gsrch - button Edit $selected_group
#    $grp_new        - comes from um_inc   - New user link
#    $submit_action  - = update_submit if pressed update
#                      = grp_del if delete group is confirmed
#    $add_submit     - if new group Add button pressed

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
  MsgPageMenu($sess->url(self_base())."index.php3", _m("No permission to create new user"), "admin");
  exit;
}

if( ($submit_action == "grp_del") AND $selected_group ) {
  DelGroup( $selected_group );     // default is to delete any references as well
  go_url( $sess->url($PHP_SELF) );
}

$sess->register("rgrp");
if( $grp OR $GrpSrch )
  $rgrp = $grp;

if( $grp_new )
  $rgrp = $selected_group = "";

$groups  = GetFiltered("G", $rgrp, _m("Too much groups found."), _m("No groups found"));   // get list of users
if( $GrpSrch ) {
  reset( $groups );
  $selected_group = key($groups);
  $grp_edit = true;
}

$all_users = GetFiltered("U", $usr1_flt, _m("Too many users or groups found."), _m("No user (group) found"));

if( $selected_group ) {
  if( $selected_group != "n" )  // none group selected
    $group_users = GetGroupMembers($selected_group);   // get list of users and groups right under $selected_group
  if( !is_array($group_users) )
    $sel_users["n"][name] = (( $group_users == "too much" ) ? _m("Too many users or groups found.") : "");
   else
    $sel_users = $group_users;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();


# Process submited form -------------------------------------------------------

if( $add_submit OR ($submit_action == "update_submit")) {

  # all the actions are in following require (we reuse this part of code for
  # slice wizard ...
  require $GLOBALS[AA_INC_PATH]."um_gedit.php3";

  if (count($err) <= 1) {
    $Msg = MsgOK(_m("Group successfully added to permission system"));
    go_url( con_url($sess->url($PHP_SELF), 'grp_edit=1&selected_group='. urlencode($selected_group)), $Msg);
  }
}

# HTML form -----------------------------------------

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
include $GLOBALS[AA_BASE_PATH]."javascript/js_lib.js";
?>
 <TITLE><?php echo _m("User management - Groups");?></TITLE>
<SCRIPT Language="JavaScript"><!--
function UpdateGroup(action) {
  var foo= CommaDelimeted( 'document.f.sel_users_sel' )
  document.f.posted_users.value = foo
  document.f.submit_action.value = action
  document.f.submit()
}

function RealyDelete() {
  if( window.confirm('<?php echo _m("Are you sure you want to delete selected group from whole permission system?") ?>')) {
    document.f2.submit_action.value = 'grp_del'
    document.f2.submit()
  }  
}

  // function changes content of role listbox for new module, when user selects another module to be added
  function SetRole(no) {
    var idx=document.f.elements['new_module['+no+']'].selectedIndex;
    var roles;
    // which roles is defined for the module
    roles = ( idx > 0 ) ? mod[mod_types.charCodeAt(idx-1)] : new Array('                     ');
    // clear selectbox
    for( i=(document.f.elements['new_module_role['+no+']'].options.length-1); i>=0; i--){
      document.f.elements['new_module_role['+no+']'].options[i] = null
    }
    // fill selectbox from the right slice
    for( i=0; i<roles.length ; i++) {
      document.f.elements['new_module_role['+no+']'].options[i] = new Option(roles[i], roles[i])
    }
  }
// -->
</SCRIPT>
</HEAD>

<?php
  require $MODULES[$g_modules[$slice_id]['type']]['menu'];   //show navigation column depending on $show
  showMenu ($aamenus, "aaadmin",$usr_new? "g_new" : "g_edit");

  echo "<H1><B>". ( $grp_new ? _m("New Group") : _m("Edit Group") )."</B></H1>";
  PrintArray($err);
  echo $Msg;

?>
<!-- Select user form -->
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align=center>
 <tr><td class=tabtit><b>&nbsp;<?php echo _m("Groups")?></b></td></tr>
 <tr><td>
   <form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
     <tr>
            <td>&nbsp;</td>
            <td><input type=Text name=grp value="<?php echo safe($rgrp)?>"></td>
            <td><input type=submit value="<?php echo _m("Search")?>">
          <input type=hidden name="GrpSrch" value=1></td>
     </tr>
    </table>
   </form>
  </td>
 </tr>
 <tr>
  <td><form name=f2 method=post action="<?php echo $sess->url($PHP_SELF) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
     <tr>
            <td class=tabtxt><b><?php echo _m("Group") ?></b></td>
            <td><?php SelectGU_ID("selected_group", $groups, $selected_group);
          ?></td>
            <td><input type=submit name="grp_edit" value="<?php echo _m("Edit")?>">&nbsp;
                <input type=hidden name=submit_action value=0>  <!-- to this variable store "usr_del" (by javascript) -->
                <input type=button name="grp_del" value="<?php echo _m("Delete")?>" onclick="RealyDelete()"></td>
     </tr>
    </table>
   </FORM>
  </td>
 </tr>
</table>

<?php
do {
  if( $grp_new OR ($grp_edit AND ($selected_group!="n")) ) {
    if($grp_edit AND !($submit_action == "update_submit")) {
      if( !is_array($group_data = GetGroup($selected_group)))
        break;
      $group_name = $group_data[name];
      $group_description = $group_data[description];
      $aa_users = GetObjectsPerms(AA_ID, "aa");
      if (strstr($aa_users[$selected_group]["perm"], $perms_roles["SUPER"]['id'])) {
	$group_super = true;
      }
    }  
  } else {
    HtmlPageEnd();
    page_close();
    exit;
  }
} while(false);

?>
<form name=f method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;
<?php
if( $grp_edit OR ($submit_action == "update_submit") )
  echo _m("Edit group");
 else
  echo _m("New group");
?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
<?php

# User data ---------------------------------------------------

  if( $grp_edit OR ($submit_action == "update_submit") )
    FrmStaticText( _m("Group Id"), $group_data[uid]);
  FrmInputText("group_name", _m("Name"), $group_name, 50, 50, true);
  FrmInputText("group_description", _m("Description"), $group_description, 50, 50, false);
  FrmInputChBox("group_super", _m("Superadmin group"), $group_super, false, "", 1, false);
echo '</table></td></tr>';

if( !$add_submit AND !$grp_new) {?>

  <tr><td class=tabtit><b>&nbsp;<?php echo _m("Users")?></b></td></tr>
  <tr><td>
  <table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
  <?php

  # User - group membership -----------------------------------------

  echo '<tr><td width=190 align=center>'. _m("All Users") .'</td>
                  <td width=60>&nbsp;</td>
                  <td width=190 align=center>'. _m("Group's Users") .'</td></tr>
        <tr><td><input type=Text name=usr1_flt value="'. safe($usr1_flt) .'">
                <input type=submit name="usr1_submit" value="'. _m("Search") .'"></td>
                  <td>&nbsp;</td>
                  <td>&nbsp;</td></tr>
        <tr><td align="CENTER" valign="TOP">';
              SelectGU_ID("all_users_sel", $all_users, $all_users_sel, "long", $sel_users);
  echo '    </td>
            <td><input type="button" VALUE="  >>  " onClick = "MoveSelected(\'document.f.all_users_sel\',\'document.f.sel_users_sel\')" align=center><br><br>
                <input type="button" VALUE="  <<  " onClick = "MoveSelected(\'document.f.sel_users_sel\',\'document.f.all_users_sel\')" align=center></td>
                  <td align="CENTER" valign="TOP">';
              SelectGU_ID("sel_users_sel", $sel_users, $sel_users_sel, "long");
  echo '    </td>
        </tr>
      </table></td></tr>';

  # User - permissions -----------------------------------------

  $mod_types = PrintModulePermModificator($selected_group);   # shared with um_gedit.php3

}

echo '<tr><td align="center">';

if( $grp_new OR $add_submit ){
  echo '<input type=submit name=add_submit value="'. _m("Add") .'" >&nbsp;&nbsp;';
  echo '<input type=hidden name=grp_new value=1>&nbsp;&nbsp;';
} else {
  echo '<input type=button name=submit_button value="'. _m("Update") .'" onClick="UpdateGroup(\'update_submit\')">&nbsp;&nbsp;';
  echo '<input type=hidden name=grp_edit value=1>&nbsp;&nbsp;';
}
echo '<input type=submit name=cancel value="'. _m("Cancel") .'">&nbsp;&nbsp;';
echo '<input type=hidden name=selected_group value="'.$selected_group.'">&nbsp;&nbsp;';
echo '<input type=hidden name=posted_users value=0>';  // to this variable store assigned users (by javascript)
echo '<input type=hidden name=submit_action value=0>';  // to this variable store "add_submit" or "update_submit" (by javascript)
echo '</td></tr></table></FORM>';

if( !$add_submit AND !$grp_new) {
  PrintPermUmPageEnd($MODULES, $mod_types, $perms_roles_modules);
}

HtmlPageEnd();
page_close(); ?>