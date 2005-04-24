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

// variable $editor_perms should be set
// variable $slice_id should be set

function PrintAddableUser($usr, $usr_id, $editor_role, $new_usr=true) {
// $usr_id is DN in LDAP
  global $sess, $perms_roles;
  $usr_id = rawurlencode($usr_id);

  echo "<tr><td class=tabtxt width=\"25%\">". $usr['name'] ."</td>\n";
  echo "<td class=tabtxt width=\"25%\">".
       (($usr[mail]) ? $usr[mail] : "&nbsp;") ."</td>\n";

  IfLink(($editor_role > $perms_roles["AUTHOR"]['id']) && $new_usr,
         $sess->url(self_base() . "se_users.php3") .
               "&UsrAdd=$usr_id&role=AUTHOR", _m("Author"));
  IfLink(($editor_role > $perms_roles["EDITOR"]['id']) && $new_usr,
         $sess->url(self_base() . "se_users.php3") .
               "&UsrAdd=$usr_id&role=EDITOR", _m("Editor"));
  IfLink(($editor_role > $perms_roles["ADMINISTRATOR"]['id']) && $new_usr,
         $sess->url(self_base() . "se_users.php3") .
               "&UsrAdd=$usr_id&role=ADMINISTRATOR", _m("Administrator"));
  echo "</tr>\n";
}

    $form_buttons = array("back" => array("type" => "submit",
                                          "name" => "back",
                                          "value" => _m("Back")));

?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<?php
/*
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Search user or group") ?></b></td></tr>
<tr><td>
<table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">*/
    FrmTabCaption(_m("Search user or group"));
    ?>
<tr>
        <td width="30%" class=tabtxt><b><?php echo _m("Users") ?></b></td>
        <td width="40%"><input type=Text name=usr value=<?php echo safe($usr)?>></td>
        <td width="30%"><input type=submit name="UsrSrch" value=<?php echo _m("Search")?>></td>
</tr>
<tr>
        <td class=tabtxt><b><?php echo _m("Groups") ?></b></td>
        <td><input type=Text name=grp value=<?php echo safe($grp)?>></td>
        <td><input type=submit name="GrpSrch" value=<?php echo _m("Search")?>></td>
</tr>
 <?php
$continue=false;
if ($GrpSrch || $UsrSrch) {
  if ($GrpSrch)
    $addable = FindGroups($grp);
   else
    $addable = FindUsers($usr);

  FrmTabSeparator(_m("Assign new permissions"));
/*
  <tr><td class=tabtit><b>&nbsp;<?php echo _m("Assign new permissions") ?></b></td></tr>
  <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
*/
  // determine role of this user
  if (ComparePerms($editor_perms, $perms_roles["SUPER"]['perm'])!="L")
    $curr_role = $perms_roles["SUPER"]['id'];
  elseif (ComparePerms($editor_perms, $perms_roles["ADMINISTRATOR"]['perm'])!="L")
    $curr_role = $perms_roles["ADMINISTRATOR"]['id'];
  elseif (ComparePerms($editor_perms, $perms_roles["EDITOR"]['perm'])!="L")
    $curr_role = $perms_roles["EDITOR"]['id'];
  elseif (ComparePerms($editor_perms, $perms_roles["AUTHOR"]['perm'])!="L")
    $curr_role = $perms_roles["AUTHOR"]['id'];
  else
    $curr_role=0;

  $slice_users = GetObjectsPerms($slice_id, "slice");
  $aa_users = GetObjectsPerms(AA_ID, "aa");   // higher than slice

  if ( isset($slice_users) AND !is_array($slice_users) )
    unset($slice_users);
  if ( isset($aa_users) AND !is_array($aa_users) )
    unset($aa_users);

  if ( is_array($aa_users)) {
    reset($aa_users);    // add aa users too
    while ( list($usr_id,$usr)= each($aa_users))
      if ( !$slice_users[$usr_id] )
        $slice_users[$usr_id] = $aa_users[$usr_id];
  }

  $l_counter = 1;
  if ( !is_array($addable) ) {
    if ( $addable == "too much" )
      echo "<tr><td class=tabtxt>". _m("Too many users or groups found.") ." ". _m("Try to be more specific.")."</td>\n";
     else
      echo "<tr><td class=tabtxt>". _m("No user (group) found") ."</td>\n";
  } else {
    reset($addable);
    while ( list($usr_id,$usr) = each($addable)) {
      if (!$slice_users[$usr_id])                         // show only new users
        PrintAddableUser($usr, $usr_id, $curr_role, true);
       else
        PrintAddableUser($usr, $usr_id, $curr_role, false);
      if ($l_counter++ >= MAX_ENTRIES_SHOWN)
        break;
    }
  }

}
  FrmTabEnd($form_buttons, false, $slice_id);

/*
<tr><td align="center">
<input type=hidden name="slice_id" value="<?php echo $slice_id ?>">
<input type=submit name=back value="<?php echo _m("Back") ?>">
</td></tr>
</table>*/
?>
<br><br><small><?php echo _m("List is limitted to 5 users.<br>If some user is not in list, try to be more specific in your query") ?></small>
</FORM>

