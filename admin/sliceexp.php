<?php
/**  Exports the slice definition as a template
 *    Two kinds of export:
 *        * for another AA installation - allows to change the id
 *        * for backup reasons - allows to export more defs at once
 *    Now you can export slice data too (all data or some only, if date
 *    is specified.
 *
 *    To show the exported text the page sliceexp_text.php is called.
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
 * @author    Jakub Adamek, Pavel Jisl
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php)
// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../admin/sliceexp_text.php";
require_once __DIR__ . "/../include/formutil.php";

try {
    // array ( '12345678901…' => 3, 'template' => 1, 'templateddd…' => 2, '' => 4, )
    $SliceID = InputVars::stringPOST('SliceID'); // alnum, empty
    // array ( 1 => 7, )
    $b_export_data = InputVars::boolPOST('b_export_data');
    // array ( 'Pjet' => 3, '12.5.2000' => 3, '' => 4, )
    $b_export_from_date = InputVars::stringPOST('b_export_from_date'); // empty
    // array ( 1 => 4, )
    $b_export_gzip = InputVars::boolPOST('b_export_gzip');
    // array ( 1 => 4, )
    $b_export_hex = InputVars::boolPOST('b_export_hex');
    // array ( 1 => 6, )
    $b_export_spec_date = InputVars::boolPOST('b_export_spec_date');
    // array ( 1 => 8, )
    $b_export_struct = InputVars::boolPOST('b_export_struct');
    // array ( 'Štyry' => 3, '25.4.2021' => 3, '' => 4, )
    $b_export_to_date = InputVars::stringPOST('b_export_to_date'); // empty
    // array ( 1 => 6, )
    $b_export_to_file = InputVars::boolPOST('b_export_to_file');
    // array ( 1 => 7, )
    $b_export_to_utf = InputVars::boolPOST('b_export_to_utf');
    // array ( 'backup' => 4, 'doc' => 6, )
    $b_export_type = InputVars::stringPOST('b_export_type'); // alpha
    // array ( 1 => 7, )
    $b_export_views = InputVars::boolPOST('b_export_views');
    // array ( )
    $export_slices = InputVars::arrayPOST('export_slices');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

// Make sure you have permissions to do this
if (!CheckPerms( AuthGetUid(), "aa", AA_ID, PS_ADD) ) {
    MsgPage(_m("You are not allowed to export / import slices"));
    exit;
}

/* Three likely options
1: Write a file  ($b_export_to_file set)
2: export to screen: Gives a textarea that can be cut-and-pasted from $SHOWTEXT
3: Neither, display a form
*/

MoreTimeLimit(); // set_time_limit to 5 minutes (at least)

if ($_POST['b_export_type']=='doc') {
    $exporter = new AA_Slice_Documentator($b_export_type, $b_export_gzip, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date, $b_export_hex, $b_export_views, $b_export_to_utf);
} else {
    $exporter = new AA_Slice_Exporter($b_export_type, $b_export_gzip, $b_export_struct, $b_export_data, $b_export_spec_date, $b_export_from_date, $b_export_to_date, $b_export_hex, $b_export_views, $b_export_to_utf);
}

if ($b_export_to_file) {
    $exporter->exportToFile(AA::$module_id, $export_slices, $SliceID);
    exit;
}

$apage = new AA_Adminpageutil('aaadmin','sliceexp');
$apage->setTitle(_m("Export slice structure"));
//$apage->addRequire(get_aa_url('/javascript/js_lib.min.js?v='.AA_JS_VERSION, '', false ));
$apage->setForm(['id'=>'formf','onsubmit'=>"return validate2()"]);
$apage->printHead($err, $Msg);

FrmTabCaption(_m("Export slice structure"));

if ($_POST['b_export_type']) {
    $exporter->exportToForm( AA::$module_id, $export_slices, $SliceID );
} else {
    $all_slices = AA_Module::getModules('S');
?>
    <tr><td colspan="2">
    <table>
        <tr><td>
        <b><?php echo _m("Choose, if you want export slices structure, data or both.") ?></b><br>
        <input type="checkbox" name="b_export_struct" value="1" checked><?php echo _m("Export structure") ?><br>
        <input type="checkbox" name="b_export_data" value="1"><?php echo _m("Export data") ?><br>
        <input type="checkbox" name="b_export_views" value="1"><?php echo _m("Export views") ?><br>
        <?php if (function_exists('gzcompress')) {  //Check for Zlib support
        ?>
        <input type="checkbox" name="b_export_gzip" value="1"><?php echo _m("Use compression") ?><br>
        <?php } ?>
        <input type="checkbox" name="b_export_hex" value="1" checked><?php echo _m("HEX output") ?><br>
        <input type="checkbox" name="b_export_to_file" value="1"><?php echo _m("Store exported data in file") ?><br><br>
        <input type="checkbox" name="b_export_to_utf" value="1"><?php echo _m("Convert to UTF") ?><br><br>
        <table>
        <tr>
            <td><input type="checkbox" name="b_export_spec_date" value="1"><?php echo _m("Export data from specified dates: ") ?></td>
            <td><?php echo _m("From ") ?><input type="date" name="b_export_from_date"></td>
            <td><?php echo _m("to") ?><input type="date" name="b_export_to_date"></td>
        </tr>
        </table>
        </td>
    </tr>
<?php
    FrmTabSeparator(_m("Choose one of two export kinds:"));
?>
    <tr>
        <td width="50%">
        <table>
            <tr><td>
            <b><?php echo _m("When exporting \"to Backup\" you may choose more slices at once.") ?></b></P>
            <?php echo _m("Select slices which you WANT to export:") ?>
            </td>
            <tr><td>
                <table width="200"><tr><td>
                <SELECT name="export_slices[]" size="8" multiple>
                <?php
                    foreach ($all_slices as $s_id => $name) {
                        echo "<option value=\"$s_id\"> $name </option>";
                    }
                ?>
                </select>
                </td></tr>
                </table>
        </table>
        </td>
        <td width="50%">
            <b><?php echo _m("When exporting \"to another ActionApps\" only the current slice will be exported and you choose its new identificator.") ?></b></p>
            <b><?php echo _m("Choose a new slice identificator exactly 16 characters long: ") ?></b>
            <input type="text" name="SliceID" value="template" size="16" maxlength="16"></P>
            <input type="hidden" name="b_export_type" id="b_export_type" value="export">
        </td>
    </tr>
    <tr>
        <td>
          <input type="button" name="btn1" value="<?php echo _m("Export to Backup") ?>" onclick="document.getElementById('b_export_type').value='backup'; document.getElementById('formf').submit();">
          <input type="button" name="btn2" value="<?php echo _m("Show Datastructure Documentation") ?>" onclick="document.getElementById('b_export_type').value='doc'; document.getElementById('formf').submit();">
        </td>
        <td><input type="button" name="btn3" value="<?php echo _m("Export to another ActionApps") ?>" onclick="validate()"></td>
    </tr>
    </tr></td>
<?php
}
?>

</table>
<script>
    function validate () {
        const form = document.forms["f"];
        if (form.SliceID.value.length != 16) {
            alert (<?php echo '"'._m("The identificator should be 16 characters long, not ").'"' ?>
                + form.SliceID.value.length);
            form.SliceID.focus();
        } else {
            form.submit();
        }
    }
    function validate2() {
        let sl_count = 0;
        const x = document.f['export_slices[]'];
        const form = document.forms["f"];
        for (let i=0; i<x.length; i++) {
            sl_count += (x.options[i].selected ? 1 : 0);
        }
        if (sl_count === 0) {
            alert (<?php  echo '"'._m("You must select one or more slices to backup").'"' ?>);
            return false;
        }  else {
            return true;
        }
    }
</script>

<?php
$apage->printFoot();
