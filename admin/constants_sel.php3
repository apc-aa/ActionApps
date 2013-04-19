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
 * @author    Pavel Jisl <pavelji@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
*/
/** constants select - for searchbar.class.php3, pavelji@ecn.cz
 *
 * field_name - expected - field id from which we want take the constants
 * var_id     - expected - id of variable in calling form, which should be filled
 * design     - ???      - boolean - use standard or admin design (currently always 1)
 * sel_text   - expected - current setting of the search
 */

$save_hidden = true;   // do not delete r_hidden session variable in init_page!

require_once "../include/init_page.php3";
require_once AA_INC_PATH . "item.php3";
require_once AA_INC_PATH . "itemfunc.php3";
require_once AA_INC_PATH . "formutil.php3";
require_once AA_INC_PATH . "slice.class.php3";

$module_id   = $slice_id; // get from session
$p_module_id = q_pack_id($module_id); // packed to 16-digit as stored in database
$slice       = AA_Slices::getSlice($module_id);
$fields      = $slice->fields('record');

// Print HTML start page tags (html begin, encoding, style sheet, but no title)
// Include also js_lib.js javascript library
HtmlPageBegin('default', true);
?>
<title><?php echo _m("Editor window - item manager"); echo " - "._m("select constants window");  ?></title>
<script type="text/javascript">
<!--

    // update changes in parent window
    function updateChanges(myform) {
      window.opener.document.filterform.elements["<?php echo $var_id ?>"].value = "";
      for (var i = 0; i < myform.elements.length-1; i++) {
            if ((myform.elements[i].type == "checkbox") && (myform.elements[i].checked == true)) {
                t_val = window.opener.document.filterform.elements["<?php echo $var_id ?>"].value;
                if (t_val == "") {
                    t_val = '"' + myform.elements[i].value + '"';
                } else {
                    t_val = t_val + ' OR "' + myform.elements[i].value + '"';
                }
                window.opener.document.filterform.elements["<?php echo $var_id ?>"].value = t_val;
            }
      }
      window.close();
    }
//-->
</script>
</head> <?php

$preset_value = new AA_Value();
// parse selected values
if ($sel_text) {
    $content_tmp = explode(" OR ", $sel_text);
    if (is_array($content_tmp)) {
        for ( $i=0, $ino=count($content_tmp); $i<$ino; ++$i) {
            $preset_value->addValue(str_replace("\\\"", "", $content_tmp[$i]));
        }
    }
}

$field = $slice->getField($field_name);

echo "<center>";
echo "$Msg <br>";

// ------- Caption -----------

echo '<form name="inputform" method=post action="'. $sess->url($_SERVER['PHP_SELF']) .'">';
FrmTabCaption(_m("Select constants"), '','', '', $sess, $slice_id);

echo '<tr><td>'. ($field ? $field->getWidgetNewHtml(null, 'mch', array('columns' => 1), $preset_value) : '') .'</td></tr>';

// following definition MUST be after $aainput->get() - this method modifies
// $aainput->varname() !!!
$form_buttons = array("var_id" => array("type"=>"hidden", "value"=>$var_id),
                      "btn_ok" => array("type"=>"button",
                                        "value"=> _m("OK"),
                                        "add"=> 'onclick=\'updateChanges(this.form)\''),
                      "cancel");

FrmTabEnd($form_buttons,$sess, $slice_id);
echo "</form>
</body></html>";
page_close();
?>
