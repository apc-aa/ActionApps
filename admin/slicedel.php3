<?php
/**
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

// expected $slice_id for edit slice, nothing for adding slice

$require_default_lang = true;      // do not use module specific language file
require_once __DIR__."/../include/init_page.php3";
require_once __DIR__."/../include/formutil.php3";
require_once __DIR__."/../include/msgpage.php3";

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IsSuperadmin()) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You don't have permissions to delete slice."), "admin");
    exit;
}
/** PrintSlice function
 * @param $id
 * @param $name
 * @param $type
 * Prints a table row with a checkbox and a link
 */
function PrintSlice($id, $name, $type) {
    global $MODULES;

    $name = safe($name); $id = safe($id);
    $url  = (($type=='S') ? './slicedel2.php3' : AA_INSTAL_PATH.$MODULES[$type]['directory']."moddelete.php3" );

    echo "<tr class=\"tabtxt\">
            <td><input type=\"checkbox\" name=\"deletearr[]\" value=\"$id\"></td>
            <td>$name</td>
            <td>$type</td>
            <td class=\"tabtxt\"><a href=\"javascript:DeleteSlice('$id', '$url')\">". _m("Delete") ."</a></td>
            </tr>
         ";
}

$headcode = '
 <script>
   function DeleteSlice(id,url2go) {
     if ( !confirm("'. _m("Do you really want to delete this slice and all its fields and all its items?") .'")) {
         return;
     }
     var url=url2go+"'. StateUrl("?") .'"
     document.location=url+"&del="+id;
   }
 </script>
';

$apage = new AA_Adminpageutil('aaadmin','slicedel');
$apage->setTitle(_m("Admin - Delete Slice"));
$apage->setForm(['action'=>'slicedel2.php3']);
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->printHead($err, $Msg);


$form_buttons= [
    "submit" => ['name' => _m('Delete selected')],
                    "cancel" => ["url"  => "um_uedit.php3"]
];

if ( !isset($slices2show) ) {
    $slices2show = 'todelete';
}
FrmTabCaption(_m("Select slice to delete"), $form_buttons);
FrmInputRadio('slices2show', _m('Slices to show'), ['todelete'=>_m('Marked as "Deleted"'), 'all'=>"All slices"],
              $slices2show, false, _m('This option allows you to display all the slices and delete them, so be careful!'), '', 0, true,
              "onClick='document.location = \"". get_admin_url('slicedel.php3') ."&slices2show=\" + this.value'");
FrmTabSeparator(_m('Slices to delete'));

// -- get views for current slice --
if ($slices2show == 'all') {
    $SQL = "SELECT * FROM module ORDER BY type, name";
} else {
    $SQL = "SELECT * FROM module WHERE deleted>0 ORDER BY type, name";
}

$db->query($SQL);
while ( $db->next_record() ) {
  PrintSlice(unpack_id($db->f('id')), $db->f('name'), $db->f('type') );
  $slice_to_delete = true;
}
if ( !$slice_to_delete ) {
  echo "<tr class=tabtxt><td>". _m("No slice marked for deletion") ."</td></tr>";
}

FrmTabEnd($form_buttons);

$apage->printFoot();
