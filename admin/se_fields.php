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
 *
*/


// expected $slice_id for edit slice, nothing for adding slice
// optional slice_fields = 1 (for slice fields)

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";


try {
    // array ( '' => 7, )-array ( 'Analyze' => 2, )
    $analyze = InputVars::boolGETPOST('analyze'); // -alpha
    // array ( '1f5e8011cce…' => 1, )
    $change_id = InputVars::idGET('change_id');
    // array ( 1 => 7, )
    $del = InputVars::boolGET('del');
    // array ( 'source.....…' => 1, 'start_date.…' => 1, 'category...…' => 1, '_abstract..…' => 2, '_place.....…' => 1, '_cp_code...…' => 1, )
    $fid = InputVars::stringGET('fid');
    // array ( 'change all' => 2, )
    $fix = InputVars::boolPOST('fix');
    // array ( '41415f436f7…' => 30, '4085d9fe61e…' => 5, '4429a8e44bb…' => 1, )
    $from_slice = InputVars::idPOST('from_slice');
    // array ( '41415f436f7…' => 30, '4085d9fe61e…' => 5, '4429a8e44bb…' => 1, )
    $ftype = InputVars::stringPOST('ftype');
    // array ( )
    $name = InputVars::arrayPOST('name');
    // array ( )
    $pri = InputVars::arrayPOST('pri');
    // array ( )
    $req = InputVars::arrayPOST('req'); // alpha
    // array ( )
    $shw = InputVars::arrayPOST('shw'); // alpha
    // array ( 1 => 9, )-array ( 0 => 30, 1 => 6, )
    $slice_fields = InputVars::boolGETPOST('slice_fields'); // -
    // array ( 'Přihlásit' => 3, )
    $submit = InputVars::boolPOST('submit');
    // array ( ' Změnit [^…' => 32, )
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception $e) {}

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php3"));
}

$slice = AA_Slice::getModule(AA::$module_id);

if (!IfSlPerm(PS_FIELDS) OR !$slice) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable
$varset = new Cvarset();

/** ShowNewField function
 * @param      $from_slice
 * @param      $slice_id
 * @param bool $slice_fields
 */
function ShowNewField($from_slice, $slice_id, bool $slice_fields) {
    $id       = 'New_Field';
    $name     = '';
    $show     = true;
    $required = false;
    $pri      = 1000;

    // --------------- from template --------------------------
    echo '<tr class="aa-tab-title aa-tab-separate"><td colspan="8">'. _m('Add new field from template') .'</td></tr>';
    echo "\n<tr class=bg-clr-opacity-dark>
          <td colspan=\"8\">". _m('Slice') .' ';
          $template_id = unpack_id('AA_Core_Fields..');
          $from_slice  = get_if($from_slice, $template_id);
          $slice_array = AA_Module::getUserModules('S');
          unset($slice_array[$template_id], $slice_array[$slice_id]);
          $slice_array = array_merge( [$template_id => '* Action Aplication Core', $slice_id => '* '.AA_Slice::getModuleName($slice_id)._m(' (this)')], $slice_array);
          FrmSelectEasy('from_slice', $slice_array, $from_slice, ['onchange' => "DisplayAaResponse('fieldselection', 'Get_Fields', {slice_id:this.value,slice_fields:" . ($slice_fields ? 1 : 0) . "})"]);
    echo "\n</td>
          </tr>
          <tr class=bg-clr-opacity-dark>
          <td><input type=\"Text\" name=\"name[$id]\" size=50 maxlength=254 value=\"$name\"></td>
          <td id=\"fieldselection\"></td>
        <td><input type=\"text\" name=\"pri[$id]\" size=\"4\" maxlength=\"4\" value=\"$pri\"></td>
        <td><input type=\"checkbox\" name=\"req[$id]\"". ($required ? " checked" : "") ."></td>
        <td><input type=\"checkbox\" name=\"shw[$id]\"". ($show ? " checked" : "") ."></td>";

    echo "<td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>";
    echo "</tr>\n";
}


if ($del) {
    if (!DB_AA::delete('field', [['id',$fid], ['slice_id',AA::$module_id,'l']])) {  // not necessary - we have set the halt_on_error
        $err["DB"] = MsgErr("Can't change field");
        exit;
    }
    AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

    $Msg = MsgOk(_m("Field delete OK"));
}


if ($update) {
    do {
        if (!(isset($name) AND is_array($name))) {
            break;
        }
        foreach ($name as $key => $val) {
            if ($key == "New_Field") {
                continue;
            }
            $prior = $pri[$key];
            ValidateInput("val", _m("Field"), $val, $err, false, "text");
            ValidateInput("prior", _m("Priority"), $prior, $err, true, "number");
        }

        if (count($err)) {
          break;
        }

        $db = getDB();
        foreach ($name as $key => $val) {
            if ($key == "New_Field") {   // add new field
                if ($val == '') {        // if not filled - don't add the field
                    continue;
                }

                [$field_slice, $field_type] = explode('-',$ftype,2);
                $field_setting = DB_AA::select1('', "SELECT * FROM field", [
                    ['slice_id', $field_slice, 'l'],
                    ['id', $field_type]
                ]);

                // copy fields
                // use the same setting for new field as template in AA_Core_Fields..
                $varset->clear();
                $varset->addArray( AA_Fields::FIELDS_TEXT, AA_Fields::FIELDS_NUM );
                $varset->setFromArray($field_setting);   // from template for this field

                // in AA_Core_Fields.. are fields identified by 'switch' or 'text'
                // identifiers (without dots!) by default. However, if user add new
                // "template" field to the AA_Core_Fields.. slice, then the identifier
                // is full (it contains dots). We need base identifier, for now.
                // Also, we will add underscore for all "slice fields" - the ones
                // which are not set for items, but rather for slice (settings)
                $ftype_base = ($slice_fields ? '_' : '') . AA_Fields::getFieldType($field_type);

                // get new field id
                $max = -1;  // Was 0
                $arr = DB_AA::select('id',"SELECT id FROM field", [['slice_id', AA::$module_id, 'l'], ['id',$ftype_base."%", 'LIKE']]);
                foreach ($arr as $arr_fid) {
                    $max = max( $max, AA_Fields::getFieldNo($arr_fid), 0);
                }
                $max++;
                //create name like "time...........2"
                $fieldid = AA_Fields::createFieldId($ftype_base, $max);

                $varset->set("slice_id", AA::$module_id, "unpacked" );
                $varset->set("id", $fieldid, "text" );
                $varset->set("name",  $val, "text");
                $varset->set("input_pri", $pri[$key], "number");
                $varset->set("required", ($req[$key] ? 1 : 0), "number");
                $varset->set("input_show", ($shw[$key] ? 1 : 0), "number");
                if (!$varset->doInsert('field')) {
                    $err["DB"] .= MsgErr("Can't copy field");
                    break;
                }
            } else { // current field
                $varset->clear();
                $varset->add("name", "text", $val);
                $varset->add("input_pri", "number", $pri[$key]);
                $varset->add("required", "number", ($req[$key] ? 1 : 0));
                $varset->add("input_show", "number", ($shw[$key] ? 1 : 0));
                $SQL = "UPDATE field SET ". $varset->makeUPDATE() ." WHERE id='$key' AND slice_id=". xpack_id(AA::$module_id);
                if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                    $err["DB"] = MsgErr("Can't change field");
                    break;
                }
            }
        }
        freeDB($db);
        AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

        if (!count($err)) {
            $Msg = MsgOk(_m("Fields update successful"));
            $update = false;   // displyas fields from database instead of posted values (next in the code)
            }
    } while (false);           //in order we can use "break;" statement
}

// slice_fields are begins with underscore
// slice fields are the fields, which we do not use for items in the slice, but
// rather for setting parameters of the slice
$s_fields = $slice->getFields((bool)$slice_fields);

// check duplicated aliases
$all_aliasses = [];
foreach ($s_fields as $fld) {
    foreach (['alias1','alias2','alias3',] as $fldname) {
        $alias = $fld[$fldname];
        if (!trim($alias) OR ($alias =='_#UNDEFINE')) {
            continue;
        }
        if (isset($all_aliasses[$alias])) {
            $err[] = _m('Alias <b>%1</b> is duplicated', [$alias]);
        }
        $all_aliasses[$alias] = true;
    }
}

$apage = new AA_Adminpageutil('sliceadmin',$slice_fields ? 'slice_fields' : 'fields');
$apage->setTitle(_m("Admin - configure Fields"));
$apage->addRequire('aa-jslib');
//$apage->addRequire("DisplayAaResponse('fieldselection', 'Get_Fields', {slice_id:'". get_if($from_slice, unpack_id('AA_Core_Fields..')) ."'});", 'AA_Req_Load');
$apage->addRequire("document.querySelector('select[name=from_slice]').dispatchEvent(new Event('change'));", 'AA_Req_Load');
$apage->printHead($err, $Msg);

if ($analyze) {
    $to_fix = false;
    if ( !($optimizer = new \AA\Optimize\ChangeF_h2F_1())->test4modules([AA::$module_id])) {
        echo $optimizer->report();
        $to_fix = true;
    }
    if ( !($optimizer = new \AA\Optimize\ChangeF_t2F_1())->test4modules([AA::$module_id])) {
        echo $optimizer->report();
        $to_fix = true;
    }
    if ( !($optimizer = new \AA\Optimize\ChangeF_t2F__())->test4modules([AA::$module_id])) {
        echo $optimizer->report();
        $to_fix = true;
    }
    if ($to_fix) {
        echo "<div><input type='submit' value='"._m('change all')."' name='fix'>";
    }
}

if ($fix) {
    ($optimizer = new \AA\Optimize\ChangeF_h2F_1())->repair4modules([AA::$module_id]);
    echo $optimizer->report();
    ($optimizer = new \AA\Optimize\ChangeF_t2F_1())->repair4modules([AA::$module_id]);
    echo $optimizer->report();
    ($optimizer = new \AA\Optimize\ChangeF_t2F__())->repair4modules([AA::$module_id]);
    echo $optimizer->report();
}

$form_buttons = ["update", "analyze" => ["type"=>"submit", "value"=>_m("Analyze")], "cancel"=> ["url"=>"se_fields.php"]];
FrmTabCaption('', $form_buttons);
?>
<thead>
<tr>
 <th scope=col><?php echo _m("Field") ?></th>
 <th scope=col><?php echo _m("Id") ?></th>
 <th scope=col><?php echo _m("Priority") ?></th>
 <th scope=col><?php echo _m("Required") ?></th>
 <th scope=col><?php echo _m("Show") ?></th>
 <th scope=col colspan="2">&nbsp;</td>
 <th scope=col><?php echo _m("Aliases")?></th>
</tr>
</thead>
<tbody>
<?php
foreach ( $s_fields as $fld) {
    $fld->showFieldAdminRow($analyze OR $fix);
}
$form_buttons['slice_fields'] = ['value' => ($slice_fields ? 1 : 0)];

// one row for possible new field
ShowNewField($from_slice, AA::$module_id, $slice_fields);
?>
</tbody>
<?php
FrmTabEnd( $form_buttons);

?>
<script>
    function DeleteField(id) {
        if ( !confirm("<?php echo _m("Do you really want to delete this field from this slice?"); ?>")) {
            return;
        }
        var url="<?php echo StateUrl(get_url('', ["del"=>1,"analyze"=>($analyze OR $fix)])); ?>"
        window.location=url + "&fid=" + escape(id);
    }
</script>

<?php
$apage->printFoot();

