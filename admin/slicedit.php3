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
# expected $slice_id for edit slice, no_slice_id=1 for adding slice

// set template id (changes language file => must be here):
require_once "../include/slicedit2.php3";
  
require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."date.php3";
require_once $GLOBALS["AA_INC_PATH"]."varset.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";

$PERMS_STATE = array( "0" => _m("Not allowed"),
                      "1" => _m("Active"),
                      "2" => _m("Hold bin") );

$PERMS_ANONYMOUS_EDIT = array( 
    ANONYMOUS_EDIT_NOT_ALLOWED => _m("Not allowed"),
    ANONYMOUS_EDIT_ALL => _m("All items"),
    ANONYMOUS_EDIT_ONLY_ANONYMOUS => _m("Only items posted anonymously"),
    ANONYMOUS_EDIT_NOT_EDITED_IN_AA => _m("-\"- and not edited in AA"),
    ANONYMOUS_EDIT_PASSWORD => _m("Authorized by a password field"),
    ANONYMOUS_EDIT_HTTP_AUTH => _m("Readers, authorized by HTTP auth"),
    );
                               
if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

reset ($MODULES);
while (list ($type,$module) = each ($MODULES)) {
    if ($create[$type]) {
        $url = $sess->url($AA_INSTAL_PATH.$module["directory"] . "modedit.php3?no_slice_id=1");
        if( $template[$type] )
            $url = con_url( $url, "template%5B$type%5D=". $template[$type]);
        go_url( $url );
    }
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$superadmin = IsSuperadmin();

require_once $GLOBALS["AA_INC_PATH"]."slicedit.php3";

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
$id = unpack_id128($db->f("id"));  // correct ids
$owner = unpack_id($db->f("owner"));  // correct ids

if( $slice_id == "" ) {         // load default values for new slice
  $name = "";
  $owner = "";
  $template = "";
  $slice_url = "";
}

# lookup owners
$slice_owners[0] = _m("Select owner");
$SQL= " SELECT id, name FROM slice_owner ORDER BY name";
$db->query($SQL);
while ($db->next_record()) {
  $slice_owners[unpack_id128($db->f(id))] = $db->f(name);
}

reset ($LANGUAGE_NAMES);
while (list ($l, $langname) = each ($LANGUAGE_NAMES)) 
    $biglangs[$l."_news_lang.php3"] = $langname;

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Slice Administration");?></TITLE>
</HEAD>
<?php
  require_once $GLOBALS["AA_INC_PATH"]."menu.php3";
  showMenu ($aamenus, "sliceadmin","main");

  echo "<H1><B>" . ( $slice_id=="" ? _m("Add Slice") : _m("Admin - Slice settings")) . "</B></H1>";
  PrintArray($err);
  echo $Msg;
?>
<form method=post action="<?php echo $sess->url($PHP_SELF) ?>">
<table border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
<tr><td class=tabtit><b>&nbsp;<?php echo _m("Slice")?></b>
</td>
</tr>
<tr><td>
<table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php
  FrmStaticText(_m("Id"), $slice_id);
  FrmInputText("name", _m("Title"), $name, 99, 25, true);
//echo "****************************************";
  FrmInputText("slice_url", _m("URL of .shtml page (often leave blank)"), $slice_url, 254, 25, false);
  $ssiuri = ereg_replace("/admin/.*", "/slice.php3", $PHP_SELF);
  echo "<TR><TD colspan=2>" . _m("<br>To include slice in your webpage type next line \n                         to your shtml code: ") . "<BR><pre>" . 
       "&lt;!--#include virtual=&quot;" . $ssiuri .
     "?slice_id=" . $slice_id . "&quot;--&gt;</pre></TD></TR>";

  FrmInputSelect("owner", _m("Owner"), $slice_owners, $owner, false);
  if( !$owner ) {
    FrmInputText("new_owner", _m("New Owner"), $new_owner, 99, 25, false);
    FrmInputText("new_owner_email", _m("New Owner's E-mail"), $new_owner_email, 99, 25, false);
  }  
  FrmInputText("d_listlen", _m("Listing length"), $d_listlen, 5, 5, true);
  if( $superadmin ) {
    FrmInputChBox("template", _m("Template"), $template);
    FrmInputChBox("deleted", _m("Deleted"), $deleted);
  }  
  FrmInputSelect("permit_anonymous_post", _m("Allow anonymous posting of items"), 
      $PERMS_STATE, $permit_anonymous_post, false);
  FrmInputSelect("permit_anonymous_edit", _m("Allow anonymous editing of items"), 
      $PERMS_ANONYMOUS_EDIT, $permit_anonymous_edit, false, "", "../doc/anonym.html");
  FrmInputSelect("permit_offline_fill", _m("Allow off-line item filling"), 
      $PERMS_STATE, $permit_offline_fill, false);
  FrmInputSelect("lang_file", _m("Language"), $biglangs, $lang_file, false);
  if ($superadmin) {
      FrmInputSelect("fileman_access", _m("File Manager Access"), getFilemanAccesses(), $fileman_access, false, "", "http://apc-aa.sourceforge.net/faq/#1106");
      FrmInputText("fileman_dir", _m("File Manager Directory"), $fileman_dir, 99, 25, false,
        "", "http://apc-aa.sourceforge.net/faq/#1106");
  }
    
    // Reader Management specific settings (Jakub, 7.2.2003)
  
    $slice_info = GetSliceInfo ($slice_id);
    $slicetype = $slice_info["type"];
    if ($slicetype == 'ReaderManagement') {
        $db->query ("SELECT id, name FROM field WHERE slice_id='"
            .q_pack_id($slice_id)."' ORDER BY input_pri");
        $slicefields[] = "";
        while ($db->next_record())
            $slicefields[$db->f("id")] = $db->f("name");
        FrmInputSelect("auth_field_group", _m("Auth Group Field"), $slicefields, 
            $auth_field_group, false, "", "../doc/reader.html#auth_field_group");
        FrmInputSelect("mailman_field_lists",_m("Mailman Lists Field"), $slicefields,
            $mailman_field_lists, false, "", "../doc/reader.html#mailman");
    }
    
    FrmInputText ("reading_password", _m("Password for Reading"), $reading_password, 
        100, 25, false, "", "http://apc-aa.sourceforge.net/faq/#slice_pwd");
?>
</table>
<tr><td align="center">
<?php
if($slice_id=="") {
  echo "<input type=hidden name=\"add\" value=1>";        // action
  echo "<input type=hidden name=\"no_slice_id\" value=1>";  // detects new slice
  echo "<input type=hidden name=template_id value=\"". $set_template_id .'">';
  
  // fields storing values from wizard
  echo "<input type=hidden name=\"wiz[copyviews]\" value='$wiz[copyviews]'>";
  echo "<input type=hidden name=\"wiz[constants]\" value='$wiz[constants]'>";
  echo "<input type=hidden name=\"wiz[welcome]\" value='$wiz[welcome]'>";
  echo "<input type=hidden name=\"user_login\" value='$user_login'>";
  echo "<input type=hidden name=\"user_role\" value='$user_role'>";
  // end of fields storing values from wizard
  
  echo "<input type=submit name=insert value=\"". _m("Insert") .'">';
}else{
  echo "<input type=hidden name=\"update\" value=1>";
  echo '<input type=submit name=update value="'. _m("Update") .'">&nbsp;&nbsp;';
  echo '<input type=reset value="'. _m("Reset form") .'">&nbsp;&nbsp;';
  echo '<input type=submit name=cancel value="'. _m("Cancel") .'">';
}
?>
</td></tr></table>
</FORM>
<?php 
HTMLPageEnd();
page_close()?>