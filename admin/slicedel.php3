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

# expected $slice_id for edit slice, nothing for adding slice

$require_default_lang = true;      // do not use module specific language file
require_once "../include/init_page.php3";
require_once $GLOBALS["AA_INC_PATH"]."formutil.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."msgpage.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!IsSuperadmin()) {
  MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to delete slice."), "admin");
  exit;
}

function PrintSlice($id, $name, $type) {
  global $sess, $MODULES, $AA_INSTAL_PATH;

  $name=safe($name); $id=safe($id);
  $url = (($type=='S') ? './slicedel2.php3' : $AA_INSTAL_PATH.$MODULES[$type]['directory']."moddelete.php3" );

  echo "<tr class=tabtxt><td>$name</td>
          <td class=tabtxt><a href=\"javascript:DeleteSlice('$id', '$url')\">". _m("Delete") ."</a></td></tr>";
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo _m("Admin - Delete Slice");?></TITLE>
 <SCRIPT Language="JavaScript"><!--
   function DeleteSlice(id,url2go) {
     if( !confirm("<?php echo _m("Do you really want to delete this slice and all its fields and all its items?"); ?>"))
       return
     var url=url2go+"<?php echo $sess->url("?"); ?>"
     document.location=url+'&del='+id;
   }
// -->
</SCRIPT>
</HEAD>
<?php

$useOnLoad = ($new_compact ? true : false);

require_once menu_include();   //show navigation column depending on $show
showMenu ($aamenus, "aaadmin","slicedel");

echo "<H1><B>" . _m("Admin - Delete Slice") . "</B></H1>";
echo $Msg;
echo _m("<p>You can delete only slices which are marked as &quot;<b>deleted</b>&quot; on &quot;<b>Slice</b>&quot; page.</p>");

?>
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
 <tr><td class=tabtit><b>&nbsp;<?php echo _m("Select slice to delete")?></b><BR>
  </td></tr>
 <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php

# -- get views for current slice --
$SQL = "SELECT * FROM module WHERE deleted>0";
$db->query($SQL);
while( $db->next_record() ) {
  PrintSlice(unpack_id128($db->f(id)), $db->f(name), $db->f(type) );
  $slice_to_delete = true;
}
if( !$slice_to_delete )
  echo "<tr class=tabtxt><td>". _m("No slice marked for deletion") ."</td></tr>";

echo '
  </table>
 <tr><td align="center">
  <input type=submit name=cancel value="'. _m("Cancel") .'">&nbsp;&nbsp;
 </td></tr></table>
</FORM>';

HtmlPageEnd();
page_close();
?>