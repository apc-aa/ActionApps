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
 * @author    Pavel Jisl <pavelji@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
*/
/** constants select - for searchbar.class.php, pavelji@ecn.cz
 *
 * field_name - expected - field id from which we want to take constants
 * var_id     - expected - id of variable in calling form, which should be filled
 * sel_text   - expected - current setting of the search
 */

use AA\Util\InputVars;

$save_hidden = true;   // do not delete r_hidden session variable in init_page!

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/item.php";
require_once __DIR__ . "/../include/itemfunc.php";
require_once __DIR__ . "/../include/formutil.php";

try {
    // array ( 'category...…' => 2, )
    $field_name = InputVars::stringGET('field_name', true);
    // array ( '' => 1, '"windows-12…' => 1, )
    $sel_text = InputVars::stringGET('sel_text', true); // empty
    // array ( 'srchbr_valu…' => 2, )
    $var_id = InputVars::stringGET('var_id', true);
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}



//$module_id   = AA::$module_id; // get from session
$slice       = AA_Slice::getModule(AA::$module_id);

// Print HTML start page tags (html begin, encoding, style sheet, but no title)
// Include also js_lib.min.js javascript library
HtmlPageBegin(true);
?>
<title><?php echo _m("Editor window - item manager"); echo " - "._m("select constants window");  ?></title>
<script>
    // update changes in parent window
    function updateChanges(myform) {
      let filterf = window.opener.document["filterform"]
        filterf.elements["<?php echo $var_id ?>"].value = "";
        let t_val;
        for (var i = 0; i < myform.elements.length - 1; i++) {
            if ((myform.elements[i].type === "checkbox") && (myform.elements[i].checked === true)) {
                t_val = filterf.elements["<?php echo $var_id ?>"].value;
                if (t_val == "") {
                    t_val = '"' + myform.elements[i].value + '"';
                } else {
                    t_val = t_val + ' OR "' + myform.elements[i].value + '"';
                }
                filterf.elements["<?php echo $var_id ?>"].value = t_val;
            }
        }
      window.close();
    }
</script>
</head> <?php

$preset_value = new AA_Value();
// parse selected values
if ($sel_text) {
    $content_tmp = explode(" OR ", $sel_text);
    if (is_array($content_tmp)) {
        for ( $i=0, $ino=count($content_tmp); $i<$ino; ++$i) {
            $preset_value->addValue(trim($content_tmp[$i],'"'));
        }
    }
}

$field = $slice->getField($field_name);
echo "$Msg <br>";

// ------- Caption -----------

echo '<form name="inputform" method=post action="'. StateUrl() .'">';
FrmTabCaption(_m("Select constants"));

echo '<tr><td>'. ($field ? $field->getWidgetNewHtml(null, null, 'mch', ['columns' => 1], $preset_value) : '') .'</td></tr>';

$form_buttons = [
    "var_id" => ["type" =>"hidden", "value"=>$var_id],
    "btn_ok" => [
        "type" =>"button",
        "value"=> _m("OK"),
        "add"  => 'onclick=\'updateChanges(this.form)\''
    ],
    "close"
];

FrmTabEnd($form_buttons);
echo "</form>
</body></html>";
page_close();
?>
