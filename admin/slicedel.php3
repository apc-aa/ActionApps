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
require "../include/init_page.php3";
require $GLOBALS[AA_INC_PATH]."formutil.php3";
require $GLOBALS[AA_INC_PATH]."pagecache.php3";
require $GLOBALS[AA_INC_PATH]."msgpage.php3";

if($cancel)
  go_url( $sess->url(self_base() . "index.php3"));

if(!IsSuperadmin()) {
  MsgPageMenu($sess->url(self_base())."index.php3", L_NO_PS_DEL_SLICE, "admin");
  exit;
}

function PrintSlice($id, $name, $type) {
  global $sess, $MODULES;

  $name=safe($name); $id=safe($id);
  $url = (($type=='S') ? './slicedel2.php3' : $MODULES[$type]['directory']."moddelete.php3" );

  echo "<tr class=tabtxt><td>$name</td>
          <td class=tabtxt><a href=\"javascript:DeleteSlice('$id', '$url')\">". L_DELETE ."</a></td></tr>";
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <TITLE><?php echo L_DELSLICE_TIT;?></TITLE>
 <SCRIPT Language="JavaScript"><!--
   function DeleteSlice(id,url2go) {
     if( !confirm("<?php echo L_DELETE_SLICE; ?>"))
       return
     var url=url2go+"<?php echo $sess->url("?"); ?>"
     document.location=url+'&del='+id;
   }
// -->
</SCRIPT>
</HEAD>
<?php

$useOnLoad = ($new_compact ? true : false);

require $MODULES[$g_modules[$slice_id]['type']]['menu'];   //show navigation column depending on $show
showMenu ($aamenus, "aaadmin","slicedel");

echo "<H1><B>" . L_A_DELSLICE . "</B></H1>";
echo $Msg;
echo L_DEL_SLICE_HLP;

?>
<table width="440" border="0" cellspacing="0" cellpadding="1" bgcolor="<?php echo COLOR_TABTITBG ?>" align="center">
 <tr><td class=tabtit><b>&nbsp;<?php echo L_DELSLICE_HDR?></b><BR>
  </td></tr>
 <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
<?php

# -- get views for current slice --
$SQL = "SELECT * FROM module WHERE deleted>0";
$db->query($SQL);
while( $db->next_record() ) {
  PrintSlice(unpack_id($db->f(id)), $db->f(name), $db->f(type) );
  $slice_to_delete = true;
}
if( !$slice_to_delete )
  echo "<tr class=tabtxt><td>". L_NO_SLICE_TO_DELETE ."</td></tr>";

echo '
  </table>
 <tr><td align="center">
  <input type=submit name=cancel value="'. L_CANCEL .'">&nbsp;&nbsp;
 </td></tr></table>
</FORM>';

HtmlPageEnd();
page_close();
?>