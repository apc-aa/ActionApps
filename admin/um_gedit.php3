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
  return $list;
}

# End functions definitions ----------------------------------------

if(!CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_NEW_USER)) {
  MsgPage($sess->url(self_base())."index.php3", L_NO_PS_NEW_USER);
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
  
$groups  = GetFiltered("G", $rgrp, L_TOO_MUCH_GROUPS, L_NO_GROUPS);   // get list of users
if( $GrpSrch ) {
  reset( $groups );
  $selected_group = key($groups);
  $grp_edit = true;
}

$all_users = GetFiltered("U", $usr1_flt, L_TOO_MUCH_USERS, L_NO_USERS);

if( $selected_group ) {
  if( $selected_group != "n" )  // none group selected 
    $group_users = GetGroupMembers($selected_group);   // get list of users and groups right under $selected_group
  if( !is_array($group_users) )
    $sel_users["n"][name] = (( $group_users == "too much" ) ? L_TOO_MUCH_USERS : "");
   else
    $sel_users = $group_users;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

# Process submited form ----------------------------------------

if( $add_submit OR ($submit_action == "update_submit"))
{
  do  {
    # Procces group data ---------------------
    ValidateInput("group_name", L_GROUP_NAME, &$group_name, &$err, ($add_submit ? true : false), "text");
    ValidateInput("group_description", L_GROUP_DESCRIPTION, &$group_description, &$err, false, "text");
    if( count($err) > 1)
      break;

    $grouprecord["description"] = $group_description;
    $grouprecord["name"] = $group_name;
//  $grouprecord["owner"] = ...;           // not used, for now

    if( $add_submit ) {
      if(!($newgroupid = AddGroup($grouprecord)))
        $err["LDAP"] = MsgErr( L_ERR_GROUP_ADD );
      if( count($err) <= 1 ) {
	if ($group_super) {	// set super admin privilege
	  AddPerm($newgroupid, AA_ID, "aa", $perms_roles_id["SUPER"]);
	}
        $Msg = MsgOK(L_NEWGROUP_OK);
        go_url( con_url($sess->url($PHP_SELF), 'GrpSrch=1&grp='. urlencode($group_name)), $Msg);
      }
    } else {
      $grouprecord["uid"] = $selected_group;
      if(!ChangeGroup($grouprecord))
        $err["LDAP"] = MsgErr( L_ERR_GROUP_CHANGE );
      if ($group_super) {		// set or revoke super admin privilege
	AddPerm($grouprecord["uid"], AA_ID, "aa", $perms_roles_id["SUPER"]);
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
  } while(false);
  if (count($err) <= 1) {
    $Msg = MsgOK(L_NEWGROUP_OK);
    go_url( con_url($sess->url($PHP_SELF), 'grp_edit=1&selected_group='. urlencode($selected_group)), $Msg);
  }
}

# HTML form -----------------------------------------

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
include $GLOBALS[AA_INC_PATH]."js_lib.js";
?>
 <TITLE><?php echo L_A_UM_GROUPS_TIT;?></TITLE>
<SCRIPT Language="JavaScript"><!--
function UpdateGroup(action) {
  var foo= CommaDelimeted( 'document.f.sel_users_sel' )
  document.f.posted_users.value = foo
  document.f.submit_action.value = action
  document.f.submit()
}

function RealyDelete() {
  if( window.confirm('<?php echo L_REALY_DELETE_GROUP ?>')) {
    document.f2.submit_action.value = 'grp_del'
    document.f2.submit()
  }  
}
// -->
</SCRIPT>
</HEAD>

<?php
  $xx = ($slice_id!="");
  $show = Array("u_new"=>$xx, "u_edit"=>$xx, "g_new"=>$xx, "g_edit"=>$xx);
  require $GLOBALS[AA_INC_PATH]."um_inc.php3";   //show navigation column depending on $show variable

  echo "<H1><B>". ( $grp_new ? L_NEW_GROUP : L_EDIT_GROUP )."</B></H1>";
  PrintArray($err);
  echo $Msg;

?>
<!-- Select user form -->
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align=center>
 <tr><td class=tabtit><b>&nbsp;<?php echo L_GROUPS?></b></td></tr>
 <tr><td>
   <form enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
     <tr>
            <td>&nbsp;</td>
            <td><input type=Text name=grp value="<?php echo $rgrp?>"></td>
            <td><input type=submit value="<?php echo L_SEARCH?>">
          <input type=hidden name="GrpSrch" value=1></td>
     </tr>
    </table>
   </form>
  </td>
 </tr>
 <tr>
  <td><form enctype="multipart/form-data" name=f2 method=post action="<?php echo $sess->url($PHP_SELF) ?>">
    <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
     <tr>
            <td class=tabtxt><b><?php echo L_GROUP ?></b></td>
            <td><?php SelectGU_ID("selected_group", $groups, $selected_group);
          ?></td>
            <td><input type=submit name="grp_edit" value="<?php echo L_EDIT?>">&nbsp;
                <input type=hidden name=submit_action value=0>  <!-- to this variable store "usr_del" (by javascript) -->
                <input type=button name="grp_del" value="<?php echo L_DELETE?>" onclick="RealyDelete()"></td>
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
      if (strstr($aa_users[$selected_group]["perm"], $perms_roles_id["SUPER"])) {
	$group_super = true;
      }
    }  
  } else {
    echo '</BODY></HTML>';
    page_close();
    exit;
  }
} while(false);

?>
<form name=f enctype="multipart/form-data" method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;
<?php
if( $grp_edit OR ($submit_action == "update_submit") )
  echo L_EDITGROUP_HDR;
 else
  echo L_NEWGROUP_HDR;
?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>" align=center>
<?php

# User data ---------------------------------------------------

  if( $grp_edit OR ($submit_action == "update_submit") )
    FrmStaticText( L_GROUP_ID, $group_data[uid]);
  FrmInputText("group_name", L_GROUP_NAME, $group_name, 50, 50, true);
  FrmInputText("group_description", L_GROUP_DESCRIPTION, $group_description, 50, 50, false);
  FrmInputChBox("group_super", L_GROUP_SUPER, $group_super, false, "", 1, false);
echo '</table></td></tr>';

if( !$add_submit AND !$grp_new) {?>

  <tr><td class=tabtit><b>&nbsp;<?php echo L_USERS?></b></td></tr>
  <tr><td>
  <table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
  <?php
  
  # User - group membership -----------------------------------------
  
  echo '<tr><td width=190 align=center>'. L_ALL_USERS .'</td>
                  <td width=60>&nbsp;</td>
                  <td width=190 align=center>'. L_GROUPS_USERS .'</td></tr>
        <tr><td><input type=Text name=usr1_flt value="'. $usr1_flt .'">
                <input type=submit name="usr1_submit" value="'. L_SEARCH .'"></td>
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
}  
      
echo '<tr><td align="center">';

if( $grp_new OR $add_submit ){
  echo '<input type=submit name=add_submit value="'. L_ADD .'" >&nbsp;&nbsp;';
  echo '<input type=hidden name=grp_new value=1>&nbsp;&nbsp;';
} else {
  echo '<input type=button name=submit_button value="'. L_UPDATE .'" onClick="UpdateGroup(\'update_submit\')">&nbsp;&nbsp;';
  echo '<input type=hidden name=grp_edit value=1>&nbsp;&nbsp;';
}
echo '<input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;';
echo '<input type=hidden name=selected_group value="'.$selected_group.'">&nbsp;&nbsp;';
echo '<input type=hidden name=posted_users value=0>';  // to this variable store assigned users (by javascript)
echo '<input type=hidden name=submit_action value=0>';  // to this variable store "add_submit" or "update_submit" (by javascript)
?>
  </td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()
/*
$Log$
Revision 1.7  2001/02/26 17:26:08  honzam
color profiles

Revision 1.6  2000/08/14 12:20:57  kzajicek
$assigned_users was always non-empty array after explode().

Revision 1.5  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.4  2000/07/28 15:11:41  kzajicek
Functions DeleteUserComplete and buggy DeleteGroupComlete are now
obsolete, DelUser and DelGroup do the job.

Revision 1.3  2000/07/27 18:42:40  kzajicek
*** empty log message ***

Revision 1.2  2000/07/27 18:17:21  kzajicek
Added superadmin settings in User/Group management

Revision 1.1.1.1  2000/06/21 18:40:06  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:49:57  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.4  2000/06/12 19:58:25  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.3  2000/05/30 09:11:49  honzama
MySQL permissions upadted and completed.

Revision 1.2  2000/04/28 09:48:13  honzama
Small bug in user/group search fixed.

Revision 1.1  2000/04/24 16:45:03  honzama
New usermanagement interface.

Revision 1.4  2000/03/29 14:34:12  honzama
Better Netscape Navigator support in javascripts.

Revision 1.3  2000/03/22 09:36:44  madebeer
also added Id and Log keywords to all .php3 and .inc files
*.php3 makes use of new variables in config.inc
*/
?>

