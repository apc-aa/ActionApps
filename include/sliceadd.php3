<?php
/**
 *
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
*/

use AA\IO\DB\DB_AA;

if (!CheckPerms($auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
    MsgPage(StateUrl(self_base())."index.php3", _m("You have not permissions to add slice"));
    exit;
}

$templates   = DB_AA::select(['unpackid'=>'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice`', [
    ['deleted',1,'<>'],
    ['template',1]
], ['name']);
$temp_slices = DB_AA::select(['unpackid'=>'name'], 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice`', [
    ['deleted',1,'<>'],
    ['template',1,'<>']
], ['name']);
// 'Action Aplication Core' slice - do not use as template
unset($temp_slices['41415f436f72655f4669656c64732e2e']);

FrmTabCaption(_m("Slice"));

if (isset( $templates ) AND is_array( $templates ) AND isset( $temp_slices ) AND is_array( $temp_slices )) {
    echo "<tr><td class=tabtxt colspan=4>" . _m("To create the new Slice, please choose a template.\n        The new slice will inherit the template's default fields.  \n        You can also choose a non-template slice to base the new slice on, \n        if it has the fields you want.") . "</TD></TR>";
}
echo '<tr><td class="tabtxt" colspan="2"><input type="hidden" name="no_slice_id" value="1"></td></tr>';


if ( isset( $templates ) AND is_array( $templates )) {
    echo "<tr><td width=\"20%\" class=\"tabtxt\"><b>". _m("Template") ."</b>";
    echo "</td><td width=\"60%\">";
    FrmSelectEasy('template_id',$templates);
    echo '</td><td width=\"20%\">';
    if ($wizard) {
        echo '<input type="radio" name="template_slice_radio" value="template" checked>';
    } else {
        echo '<input type="SUBMIT" name="template_from_template" value="'._m("Add").'">';
    }
    echo '</td></tr>';
} else {
    echo "<tr><td class=\"tabtxt\" colspan=\"2\">". _m("No templates") ."</td></tr>";
}

if ( isset( $temp_slices ) AND is_array( $temp_slices )) {
    echo "<tr><td class=\"tabtxt\"><b>". _m("Slice") ."</b>";
    echo "</td>\n <td>";
    FrmSelectEasy('template_id2',$temp_slices);
    echo '</td><td>';
    if ($wizard) {
        echo '<input type="radio" name="template_slice_radio" value="slice" checked>';
    } else {
        echo '<input type="SUBMIT" name="template_from_slice" value="'._m("Add").'">';
    }
    echo '</td></tr>';
} else {
    echo "<tr><td class=\"tabtxt\" colspan=\"2\">". _m("No slices") ."</td></tr>";
}

if ($$display_views_constants) {
    FrmInputRadio("wiz[copyviews]", _m("Copy Views"), [1=>_m("yes"),0=>_m("no")], 1);
    FrmInputRadio("wiz[constants]", _m("Categories/Constants"), ['share'=>_m("Share with Template"),'copy'=>_m("Copy from Template")],'copy');
}

FrmTabEnd();

echo '<br><br>';

