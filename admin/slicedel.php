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

use AA\Util\InputVars;

$require_default_lang = true;      // do not use module specific language file
// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";

try {
    $slices2show = InputVars::stringGET('slices2show'); // it means modules2show
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

if (!IsSuperadmin()) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You don't have permissions to delete module."), "admin");
    exit;
}
/** Prints a table row with a checkbox and a link
 * @param $id string module id
 * @param $name
 * @param $type
 */
function PrintModule($id, $name, $type) {

    $name = safe($name); $id = safe($id);
    $url  = (($type === 'S') ? './slicedel2.php' : AA_INSTAL_PATH. AA_Moduleregistry::info($type,'dir')."moddelete.php" );
    $module_type = AA_Moduleregistry::info($type,'name');

    echo "<tr>
            <td><input type=\"checkbox\" name=\"deletearr[]\" value=\"$id\"></td>
            <td>$name</td>
            <td>$module_type</td>
            <td><a class=aa-button-delete href=\"javascript:DeleteModule('$id', '$url')\">". _m("Delete") ."</a></td>
            </tr>
         ";
}

$headcode = '
 <script>
   function DeleteModule(id,url2go) {
     if ( !confirm("'. _m("Do you really want to delete this module and all its fields and all its items?") .'")) {
         return;
     }
     var url=url2go+"'. StateUrl("?") .'"
     window.location=url+"&del="+id;
   }
 </script>
';

$apage = new AA_Adminpageutil('aaadmin','slicedel');
$apage->setTitle(_m("Admin - Delete Module"));
$apage->setForm(['action'=>'slicedel2.php']);
$apage->addRequire($headcode, 'AA_Req_Headcode');
$apage->printHead($err, $Msg);


$form_buttons= [
    "delete" => ["type"=>"submit", "value" => _m('Delete selected')],
    "cancel" => ["url"  => "um_uedit.php"]
];

if ( !$slices2show ) {
    $slices2show = 'todelete';
}

FrmTabCaption(_m("Select module to delete"), $form_buttons);
FrmInputRadio('slices2show', _m('Modules to show'), ['todelete'=>_m('Marked as "Deleted"'), 'all'=>"All modules"],
              $slices2show, false, _m('This option allows you to display all the slices and delete them, so be careful!'), '', 0, true,
              "onClick='window.location = \"". get_admin_url('slicedel.php') ."&slices2show=\" + this.value'");
FrmTabSeparator(_m('Modules to delete'));

if ($slices2show === 'all') {
    $SQL = "SELECT * FROM module ORDER BY type, name";
} else {
    $SQL = "SELECT * FROM module WHERE deleted>0 ORDER BY type, name";
}

$db->query($SQL);
while ( $db->next_record() ) {
  PrintModule(unpack_id($db->f('id')), $db->f('name'), $db->f('type') );
  $slice_to_delete = true;
}
if ( !$slice_to_delete ) {
  echo "<tr><td>". _m("No module marked for deletion") ."</td></tr>";
}

FrmTabEnd($form_buttons);

$apage->printFoot();
