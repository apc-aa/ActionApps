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
# expected $slice_id for edit slice, Add_slice=1 for adding slice

// set template id (changes language file => must be here):
require "../include/slicedit2.php3";
  
require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."date.php3";
require $GLOBALS[AA_INC_PATH]."varset.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

reset ($MODULES);
while (list ($letter,$module) = each ($MODULES)) {
    if ($create[$letter]) 
        go_url($sess->url($module["directory"] . "modedit.php3"));
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$superadmin = IsSuperadmin();

require $GLOBALS[AA_INC_PATH]."slicedit.php3";

$foo_source = ( ( $slice_id=="" ) ? $set_template_id : $slice_id);
  # set variables from database - allways
$SQL= " SELECT * FROM slice WHERE id='".q_pack_id($foo_source)."'";
$db->query($SQL);
if ($db->next_record())
  while (list($key,$val,,) = each($db->Record)) {
    if( EReg("^[0-9]*$", $key))
      continue;
    $$key = $val; // variables and database fields have identical names
  }
$id = unpack_id($db->f("id"));  // correct ids
$owner = unpack_id($db->f("owner"));  // correct ids

if( $slice_id == "" ) {         // load default values for new slice
  $name = "";
  $owner = "";
  $template = "";
  $slice_url = "";
}

# lookup owners
$slice_owners[0] = L_SELECT_OWNER;
$SQL= " SELECT id, name FROM slice_owner ORDER BY name";
$db->query($SQL);
while ($db->next_record()) {
  $slice_owners[unpack_id($db->f(id))] = $db->f(name);
}

$PERMS_STATE = array( "0" => L_PROHIBITED,
                      "1" => L_ACTIVE_BIN,
                      "2" => L_HOLDING_BIN );

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_A_SLICE_TIT;?></TITLE>
</HEAD>
<?php
  require $GLOBALS[AA_INC_PATH]."menu.php3";
  showMenu ($aamenus, "sliceadmin","main");

  echo "<H1><B>" . ( $slice_id=="" ? L_A_SLICE_ADD : L_A_SLICE_EDT) . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo L_SLICES_HDR?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmStaticText(L_ID, $slice_id);
  FrmInputText("name", L_SLICE_NAME, $name, 99, 25, true);
  FrmInputText("slice_url", L_SLICE_URL, $slice_url, 254, 25, false);
  $ssiuri = ereg_replace("/admin/.*", "/slice.php3", $PHP_SELF);
  echo "<TR><TD colspan=2>" . L_SLICE_HINT . "<BR><pre>" . 
       "&lt;!--#include virtual=&quot;" . $ssiuri .
     "?slice_id=" . $slice_id . "&quot;--&gt;</pre></TD></TR>";

  FrmInputSelect("owner", L_OWNER, $slice_owners, $owner, false);
  if( !$owner ) {
    FrmInputText("new_owner", L_NEW_OWNER, $new_owner, 99, 25, false);
    FrmInputText("new_owner_email", L_NEW_OWNER_EMAIL, $new_owner_email, 99, 25, false);
  }  
  FrmInputText("d_listlen", L_D_LISTLEN, $d_listlen, 5, 5, true);
  if( $superadmin ) {
    FrmInputChBox("template", L_TEMPLATE, $template);
    FrmInputChBox("deleted", L_DELETED, $deleted);
  }  
  FrmInputSelect("permit_anonymous_post", L_PERMIT_ANONYMOUS_POST, $PERMS_STATE, $permit_anonymous_post, false);
  FrmInputSelect("permit_offline_fill", L_PERMIT_OFFLINE_FILL, $PERMS_STATE, $permit_offline_fill, false);
  FrmInputSelect("lang_file", L_LANG_FILE, $LANGUAGE_FILES, $lang_file, false);
  if ($superadmin) {
      FrmInputSelect("fileman_access", L_FILEMAN_ACCESS, $FILEMAN_ACCESSES, $fileman_access, false);
      FrmInputText("fileman_dir", L_FILEMAN_DIR, $fileman_dir, 99, 25, false);
  }
?>
</table>
<tr><td align="center">
<?php
if($slice_id=="") {
  echo "<input type=hidden name=\"add\" value=1>";        // action
  echo "<input type=hidden name=\"Add_slice\" value=1>";  // detects new slice
  echo "<input type=hidden name=template_id value=\"". $set_template_id .'">';
  
  // fields storing values from wizard
  echo "<input type=hidden name=\"wiz[copyviews]\" value='$wiz[copyviews]'>";
  echo "<input type=hidden name=\"wiz[constants]\" value='$wiz[constants]'>";
  echo "<input type=hidden name=\"wiz[welcome]\" value='$wiz[welcome]'>";
  echo "<input type=hidden name=\"user_login\" value='$user_login'>";
  echo "<input type=hidden name=\"user_role\" value='$user_role'>";
  // end of fields storing values from wizard
  
  echo "<input type=submit name=insert value=\"". L_INSERT .'">';
}else{
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. L_UPDATE .'">&nbsp;&nbsp;';
  echo '<input type=reset value="'. L_RESET .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. L_CANCEL .'">';
}
?>
</td></tr></table>
</FORM>
</BODY>
</HTML>
<?php page_close()?>