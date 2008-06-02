<?php
/**
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

// expected $slice_id for edit slice, nothing for adding slice

$require_default_lang = true;      // do not use module specific language file
require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IsSuperadmin()) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You don't have permissions to delete slice."), "admin");
    exit;
}
/** PrintSlice function
 * @param $id
 * @param $name
 * @param $type
 * @return prints a table row with a checkbox and a link
 */
function PrintSlice($id, $name, $type) {
    global $sess, $MODULES;

    $name=safe($name); $id=safe($id);
    $url = (($type=='S') ? './slicedel2.php3' : AA_INSTAL_PATH.$MODULES[$type]['directory']."moddelete.php3" );

    echo "<tr class=\"tabtxt\">
            <td><input type=\"checkbox\" name=\"deletearr[]\" value=\"$id\"></td>
            <td>$name</td>
            <td>$type</td>
            <td class=\"tabtxt\"><a href=\"javascript:DeleteSlice('$id', '$url')\">". _m("Delete") ."</a></td>
            </tr>
         ";
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Admin - Delete Slice");?></title>
 <script Language="JavaScript"><!--
   function DeleteSlice(id,url2go) {
     if ( !confirm("<?php echo _m("Do you really want to delete this slice and all its fields and all its items?"); ?>"))
       return
     var url=url2go+"<?php echo $sess->url("?"); ?>"
     document.location=url+'&del='+id;
   }
// -->
</script>
</head>
<?php

$useOnLoad = ($new_compact ? true : false);

require_once menu_include();   //show navigation column depending on $show
showMenu($aamenus, "aaadmin","slicedel");

echo "<h1><b>" . _m("Admin - Delete Slice") . "</b></h1>";
echo $Msg;
// echo _m("<p>You can delete only slices which are marked as &quot;<b>deleted</b>&quot; on &quot;<b>Slice</b>&quot; page.</p>");

?>
<form name="f" method="post" action="<?php echo $sess->url(self_base() . "slicedel2.php3")?>">
<?php


$form_buttons=array("submit" => array('name' => _m('Delete selected') ),
                    "cancel" => array("url"  => "um_uedit.php3"));

if ( !isset($slices2show) ) {
    $slices2show = 'todelete';
}
FrmTabCaption(_m("Select slice to delete"), '','', $form_buttons);
FrmInputRadio('slices2show', _m('Slices to show'), array('todelete'=>_m('Marked as "Deleted"'), 'all'=>"All slices" ),
              $slices2show, false, _m('This option allows you to display all the slices and delete them, so be careful!'), '', 0, true,
              "onClick='document.location = \"". get_url($sess->url(self_base(). "slicedel.php3"), "slices2show=") ."\" + this.value'");
FrmTabSeparator(_m('Slices to delete') );

// -- get views for current slice --
if ($slices2show == 'all') {
    $SQL = "SELECT * FROM module ORDER BY type, name";
} else {
    $SQL = "SELECT * FROM module WHERE deleted>0 ORDER BY type, name";
}

$db->query($SQL);
while ( $db->next_record() ) {
  PrintSlice(unpack_id128($db->f('id')), $db->f('name'), $db->f('type') );
  $slice_to_delete = true;
}
if ( !$slice_to_delete ) {
  echo "<tr class=tabtxt><td>". _m("No slice marked for deletion") ."</td></tr>";
}

FrmTabEnd($form_buttons, $sess, $slice_id);

echo '</form>';

HtmlPageEnd();
page_close();
?>