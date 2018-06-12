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
 * @author    Jakub Adamek, June 2002
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu(StateUrl(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable

// update database or get the value


if ($slice_id && $update) {
    DB_AA::update('slice', array(array('javascript',$_POST['javascript'])), array(array('id', $slice_id, 'l')));
    $javascript = $_POST['javascript'];
} else {
    $javascript = DB_AA::select1('SELECT javascript FROM `slice`', 'javascript', array(array('id', $slice_id, 'l')));
}

$apage = new AA_Adminpageutil('sliceadmin','javascript');
$apage->setTitle(_m("Field Triggers"));
$apage->printHead($err, $Msg);

FrmTabCaption(_m("JavaScript for fields"));
?>
<tr><td><?php FrmStaticText(_m("Enter code in the JavaScript language. It will be included in the Add / Edit item page (itemedit.php3)."),""); ?></td></tr>
<tr><td class="tabtxt"><hr></td></tr>
<tr><td class="tabtxt"><textarea name="javascript" cols="100" rows="20">
<?php
echo $javascript.'</textarea></td></tr>';
FrmTabSeparator(_m("Available fields and triggers"), array("update", "update" => array("type" => "hidden", "value" => "1"), "cancel" => array("url" => "se_fields.php3")), $slice_id);

$fields = AA_Slice::getModule($slice_id)->fields('pri');

echo '
<tr><td valign="top"><table border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABBG.'">
<tr><td class="tabtit">'._m("Field IDs").':</td></tr>';
foreach ($fields as $fid) {
    echo "<tr><td class=\"tabtxt\">$fid</td></tr>";
}
echo '</table>
</td>
<td valign="top"><table border="0" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABBG.'">
<tr><td class="tabtit">'._m("Triggers").':</td></tr>
<tr><td class="tabtxt">'._m("Write trigger functions like").' "aa_onSubmit (fieldid) { }", <a href="http://actionapps.org/faq/detail.shtml?x=1706" target="_blank">'._m("see FAQ</a> for more details and examples").'</td></td></tr>
<tr><td class="tabtxt"><table border="1" cellspacing="0" cellpadding="1" bgcolor="'.COLOR_TABBG.'">';
echo '<tr><td class="tabtit"><b>'._m("Field Type").'</b></td><td class=tabtit><b>'._m("Triggers Available -- see some JavaScript help for when a trigger is run").'</b></td></tr>';
AA_Jstriggers::printSummary();
echo '
</table></td></tr>
</table></td>
</tr></table>';

$apage->printFoot();
