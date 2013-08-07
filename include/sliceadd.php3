<?php
/**
 *
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
*/
/** cmp function
 * @param $a
 * @param $b
 */
function cmp($a, $b) {
  return strcmp($a["name"], $b["name"]);
}

if (!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD)) {
    MsgPage($sess->url(self_base())."index.php3", _m("You have not permissions to add slice"), "standalone");
    exit;
}

$SQL = "SELECT name, id, template, lang_file FROM slice WHERE deleted<>1";
$db->query($SQL);
while ( $db->next_record() ) {
    if ( $db->f('template') ) {
        $templates[unpack_id( $db->f('id') )]['value'] = unpack_id( $db->f('id') ) ."{". $db->f('lang_file');
        $templates[unpack_id( $db->f('id') )]['name']  = $db->f('name');
    }
    else {
        $temp_slices[unpack_id( $db->f('id') )]['value'] = unpack_id( $db->f('id') ) ."{". $db->f('lang_file');
        $temp_slices[unpack_id( $db->f('id') )]['name']  = $db->f('name');
    }
}

require_once AA_INC_PATH."formutil.php3";


FrmTabCaption(_m("Slice"));

if (isset( $templates ) AND is_array( $templates ) AND isset( $temp_slices ) AND is_array( $temp_slices )) {
    echo "<tr><td class=tabtxt colspan=4>" . _m("To create the new Slice, please choose a template.\n        The new slice will inherit the template's default fields.  \n        You can also choose a non-template slice to base the new slice on, \n        if it has the fields you want.") . "</TD></TR>";
}
echo '<tr><td class="tabtxt" colspan="2"><input type="hidden" name="no_slice_id" value="1"></td></tr>';


if ( isset( $templates ) AND is_array( $templates )) {
    usort($templates, "cmp");
    echo "<tr><td width=\"20%\" class=\"tabtxt\"><b>". _m("Template") ."</b>";
    echo "</td><td width=\"60%\"><select name=\"template_id\">";
    foreach ($templates as $v) {
        echo "<option value=\"". myspecialchars($v['value'])."\"";
        echo "> ". myspecialchars($v['name']) ." </option>";
    }
    echo '</select></td><td width=\"20%\">';
    if ($wizard) {
        echo '<input type="radio" name="template_slice_radio" value="template" checked>';
    } else {
        echo '<input type="SUBMIT" name="template_slice_sel[template]" value="'._m("Add").'" checked>';
    }
    echo '</td></tr>';
} else {
    echo "<tr><td class=\"tabtxt\" colspan=\"2\">". _m("No templates") ."</td></tr>";
}

if ( isset( $temp_slices ) AND is_array( $temp_slices )) {
    usort($temp_slices, "cmp");
    $out =  "<tr><td class=\"tabtxt\"><b>". _m("Slice") ."</b>";
    $out .= "</td>\n <td><select name=\"template_id2\">";
    foreach ($temp_slices as $v) {
        if ( substr( $v['value'], 0, 32 ) == '41415f436f72655f4669656c64732e2e' ) {
            continue;    // 'Action Aplication Core' slice - do not use as template
        }
        $slice_sb .= "<option value=\"". myspecialchars($v['value'])."\"";
        $slice_sb .= "> ". myspecialchars($v['name']) ." </option>";
    }
    if ( $slice_sb ) {
        echo $out . $slice_sb . '</select></td><td>';
        if ($wizard) {
            echo '<input type="radio" name="template_slice_radio" value="slice" checked>';
        } else {
            echo '<input type="SUBMIT" name="template_slice_sel[slice]" value="'._m("Add").'">';
        }
        echo '</td></tr>';
    }
} else {
    echo "<tr><td class=\"tabtxt\" colspan=\"2\">". _m("No slices") ."</td></tr>";
}

?>
