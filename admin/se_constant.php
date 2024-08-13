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
// Parameters: group_id - identifier of constant group
//             as_new - if we want to create new category group based on an existing (id of "template" group)

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";
require_once __DIR__ . "/../include/constedit_util.php";

try {
    // array ( )
    $cid = InputVars::arrayPOST('cid'); // alnum
    // array ( 0 => 7, )
    $deleteGroup = InputVars::boolPOST('deleteGroup');
    // array ( 'seo........…' => 4, )-array ( 'seo........…' => 7, )
    $fid = InputVars::stringGETPOST('fid'); // -
    $as_new = InputVars::stringGET('as_new');
    $new_owner_id = InputVars::stringPOST('new_owner_id');
    $hierarch = InputVars::boolGETPOST('hierarch');
    // array ( 1 => 4, )
    $foo = InputVars::boolGET('foo');
    // array ( 0 => 4, )-array ( '' => 3, 'v4161616161…' => 1, 'v4955595446' => 3, )
    $group_id = InputVars::stringGETPOST('group_id'); // -alnum
    // array ( )
    $name = InputVars::arrayPOST('name');
    // array ( 'JVFTGMCGHC' => 1, 'Aaaaaaa' => 1, 'IUYTF' => 1, )
    $new_group_id = InputVars::stringPOST('new_group_id'); // alpha
    // array ( )
    $pri = InputVars::arrayPOST('pri');
    // array ( 'on' => 4, )
    $propagate_changes = InputVars::boolPOST('propagate_changes');
    // array ( 'se_inputfor…' => 4, )-array ( 'se_inputfor…' => 7, )
    $return_url = InputVars::stringGETPOST('return_url'); // -
    // array ( ' Změnit [^…' => 7, )
    $update = InputVars::boolPOST('update');
    // array ( )
    $used = InputVars::arrayPOST('used');
    // array ( )
    $chown = InputVars::boolPOST('chown');

    // Hierarchy editor
    $hcalldata = InputVars::stringPOST('hcalldata');
    $hierarch  = InputVars::stringPOST('hierarch');

    $value = InputVars::arrayPOST('value');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}


if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

if (!IfSlPerm(PS_FIELDS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

// as_new and $group_id is varname4form()-ed (for easier parameter passing)
$as_new    = (strlen($as_new) > 1)   ? pack_id(substr($as_new,1)) : null;
$group_id  = (strlen($group_id) > 1) ? pack_id(substr($group_id,1)) : null;
$back_url  = ($return_url ? ($fid    ? con_url($return_url,"fid=".$fid) : $return_url) : "index.php");
$from_form = false;  // display the values from form (after unsucessful update)

if ($deleteGroup AND $group_id) {
    delete_constant_group($group_id);
    go_url(StateUrl($back_url));
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

// Check permissions
if ( $group_id ) {
    $constant_info = DB_AA::select1('', 'SELECT  LOWER(HEX(`slice_id`)) AS owner_id, name, propagate FROM constant_slice, slice', [['constant_slice.slice_id', 'slice.id', 'j'], ['group_id', $group_id]]);
    if ($constant_info && !CheckPerms( AuthGetUid(), "slice", $constant_info["owner_id"], PS_FIELDS)) {
        AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You have not permissions to change fields settings for the slice owning this group") . " (" . $constant_info['name'] . ")", "admin");
        exit;
    }
}

/** ShowConstant function
 * @param      $id
 * @param      $name
 * @param      $value
 * @param      $cid
 * @param      $pri
 * @param null|array $using_slices_arr
 */
function ShowConstant($id, $name, $value, $cid, $pri, ?array $using_slices_arr=null) {
    $count = '';
    if (is_array($using_slices_arr) AND count($using_slices_arr)) {
        $count = 0;
        foreach ($using_slices_arr as $arr) {
            $set = new AA_Set([$arr['unpackid']], new AA_Condition($arr['fid'], '==', $value), null, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING);
            $count += count($set->query());
        }
    }

    $name = safe($name); $value=safe($value); $pri=safe($pri); $cid=safe($cid); $id=safe($id);

    echo "
    <tr>
      <td><input type=\"text\" name=\"name[$id]\" size=\"60\" maxlength=\"149\" value=\"$name\"></td>
      <td><input type=\"text\" name=\"value[$id]\" size=\"60\" maxlength=\"255\" value=\"$value\">
          <input type=\"hidden\" name=\"cid[$id]\" value=\"$cid\"></td>
      <td><input type=\"text\" name=\"pri[$id]\" size=\"4\" maxlength=\"4\" value=\"$pri\"></td>
      <td>$count<input type=\"hidden\" name=\"used[$id]\" value=\"".str_pad($count, 7, "0", STR_PAD_LEFT)."\"></td>";
    echo "</tr>\n";
}

/** propagateChanges function
 * Propagates changes to a constant value to the items which contain this value.
 * @param string $group_id packed id of constant group
 * @param        $constant_id
 * @param string $newvalue The new value with added slashes (e.g. from a form)
 * @param        $oldvalue
 */
function propagateChanges($group_id, $constant_id, $newvalue, $oldvalue) {
    global $Msg;

    if ($oldvalue == $newvalue) return;

    $event = aaevent::singleton();
    $event->comes('CONSTANT_BEFORE_UPDATE', AA::$module_id, 'S', $newvalue, $oldvalue, $constant_id);

    $cnt = 0;
    $items2update = [];
    $db = getDB();

    if ($oldvalue) {
        $qgroup_id = quote($group_id);

        // we have to join also item table in order we make sure field is in right slice
        $db->query("
        SELECT item_id,field_id
          FROM content, item, field
         WHERE content.item_id=item.id
           AND item.slice_id = field.slice_id
           AND content.field_id = field.id
           AND (field.input_show_func LIKE '___:$qgroup_id:%'
            OR  field.input_show_func LIKE '___:$qgroup_id')
           AND content.text = '$oldvalue'");
        while ($db->next_record()) {
            ++$cnt;
            if ( !$items2update[$db->f("field_id")] ) {
                $items2update[$db->f("field_id")] = new zids( null, 'p');
            }
            $items2update[$db->f("field_id")]->add($db->f("item_id"));
        }
    }
    foreach ( $items2update as $field => $zids ) {
        $SQL = "UPDATE content SET text='$newvalue' WHERE ". $zids->sqlin('item_id') ." AND field_id='".addslashes($field)."' AND text='$oldvalue'";
        $db->query($SQL);
    }
    freeDB($db);

    if ($cnt) {
        $Msg .= $cnt . _m(" items changed to new value ") . "'$newvalue'<br>";
    }

    $event->comes('CONSTANT_UPDATED', AA::$module_id, 'S', $newvalue, $oldvalue, $constant_id);
}


if ($hcalldata || $hierarch) {
    hcUpdate();
}

if ($update) {
    $from_form = true;
    do {
        if (!(isset($name) AND is_array($name))) {
            break;
        }
        foreach ($name as $key => $nam) {
            $prior     = $pri[$key];
            $val       = $value[$key];
            ValidateInput("nam", _m("Name"), $nam, $err, false, "text");   // if not filled it will be deleted
            ValidateInput("val", _m("Value"), $val, $err, false, "text");
            ValidateInput("prior", _m("Priority"), $prior, $err, false, "number");
        }

        if (!strlen($group_id)) {  // new constant group
            $new_group_id = substr(ConvertEncoding(str_replace(':','-',$new_group_id),'','us-ascii'),0,16);  // we don't need ':' nor UTF characters - we have 16 Bytes
                                                                 // in id (parameter separator)
            ValidateInput("new_group_id", _m("Constant Group"), $new_group_id, $err, true, "text");
            if (count($err)) {
                break;
            }
            if (DB_AA::test('constant', [['group_id',$new_group_id]])) {
                $err["DB"] = _m("This constant group already exists");
            } else {
                $add_new_group = true;
                $group_id = $new_group_id;
            }
        }

        if (count($err)) {
            break;
        }

        if ($group_id) {
            // if there is no group owner, promote this slice to owner
            $data_constant_slice = ['group_id' => $group_id, 'propagate' => ($propagate_changes ? 1 : 0)];
            if (!DB_AA::test('constant_slice', [['group_id',$group_id]])) {
                $data_constant_slice['slice_id'] = AA::$module_id;
                AA::Metabase()->doInsert('constant_slice', $data_constant_slice);
            } else {
                if ($new_owner_id) {
                    $data_constant_slice['slice_id'] = $new_owner_id;
                    $chown = false;
                }
                AA::Metabase()->doUpdate('constant_slice', $data_constant_slice);
            }
        }

        // add new group to constant group list
        if ($add_new_group) {
            if (!AA::Metabase()->doInsert('constant', ['id' => new_id(), 'group_id' => 'lt_groupNames', 'name' => $group_id, 'value' => $group_id, 'pri' => 100])) {
                $err["DB"] .= MsgErr("Can't create constant group");
                break;
            }
        }

        foreach ($name as $key => $foonam) {
            $new_constant = ($cid[$key]=="");
            if ($new_constant) {
                $cid[$key] = 'x'.new_id();
            }
            $category_id = substr($cid[$key],1);     // remove beginning 'x'
            // if name is empty, delete the constant
            if (($foonam == "")) {
                if (!$new_constant) {
                    if (!DB_AA::delete('constant', [['id',$category_id, 'l']])) {
                        $err["DB"] .= MsgErr("Can't delete constant $category_id");
                        break;
                    }
                }
                continue;
            }
            $data_constant = ['id'=> $category_id, 'name' => $name[$key], 'value' => $value[$key], 'pri' => ( $pri[$key] ?: 1000)];
            if (false !== ($oldval = DB_AA::select1('value', 'SELECT `value` FROM `constant`',[['id',$category_id,'l']]))) {
                if ($propagate_changes) {
                    propagateChanges($group_id, $category_id, $value[$key], $oldval);
                }
                $data_constant['id'] = $category_id;
                if (!AA::Metabase()->doUpdate('constant', $data_constant)) {
                    $err["DB"] .= MsgErr("Can't update constant");
                    break;
                }
            } else {
                $data_constant['group_id'] = $group_id;
                if (!AA::Metabase()->doInsert('constant', $data_constant)) {
                    $err["DB"] .= MsgErr("Can't copy constant");
                    break;
                }
            }
        }
        $event_return_state = '';
        aaevent::singleton()->comes('CONSTANTGROUP_UPDATED', AA::$module_id, 'S', $event_return_state, $group_id);
        AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

        if (!count($err)) {
            $from_form = false;
            $Msg .= MsgOk(_m("Constants update successful"));
        }
    } while( 0 );           // in order we can use "break;" statement
}

// lookup constants
if ($group_id OR $as_new) {
    $s_constants = DB_AA::select([], 'SELECT `id`, `name`, `value`, `pri` FROM `constant`', [['group_id', ( $as_new ?: $group_id )]], ['pri', 'name']);
}

$apage = new AA_Adminpageutil('sliceadmin','fields');
$apage->setTitle(_m("Admin - Constants Setting"));
$apage->printHead($err, $Msg);

$form_buttons = [
    "update",
    "cancel"   => ["url" => $back_url],
    "delgroup" => [
        "type"  => "button",
        "value" => _m("Delete whole group"),
        "add"   => 'onclick="deleteWholeGroup();"',
    ],
    "hierarch" => [
        "value" => _m("Edit in Hierarchical editor"),
        "type"  => "submit",
        "title"  => _m("Edit in Hierarchical editor (allows to create constant hierarchy)")
    ]
];
?>
 <input type="hidden" name="group_id" value="<?php echo varname4form($group_id); /* do not move it to $form_buttons - we need it also in hierarchical editor, which do not use $form_buttons!!! */ ?>">
<?php

// load the HIERARCHICAL EDITOR
if ($hierarch) {
    require_once __DIR__ . "/../include/constedit.php";
    // it exits here
}

FrmTabCaption(_m("Constants"), $form_buttons);

// this must be just once on the page
$form_buttons["deleteGroup"] = ["value" => "0"];
$form_buttons["return_url"]  = ["value" => $return_url];
$form_buttons["fid"]         = ["value" => $fid];


echo "<th>"._m("Constant Group") ."</th>
  <td colspan=\"3\">";

if ( $group_id ) {
    echo safe($group_id);
} else {
    echo '<input type="text" name="new_group_id" size="16" maxlength="16" pattern="[a-zA-Z0-9_ .+-]{5,16}" required value="'.safe($new_group_id).'" title="'._m('alphanumeric 5-16 character long identifier').'">
          <a href="'.get_admin_url('se_constant_import.php?return_url=se_inputform.php&amp;fid='. rawurlencode($fid)).
          '">'._m('Import Constants...').'</a>';
}
echo "\n     </td>\n</tr>";

// Find slices, where the constant group is used
if ($group_id) {
    $using_slices_arr =  DB_AA::select([], "SELECT LOWER(HEX(module.id)) AS unpackid, module.deleted, module.name as sname, field.id as fid, field.name as fname FROM `module`, `field` WHERE module.id = field.slice_id AND (field.input_show_func LIKE '%:$group_id' OR field.input_show_func LIKE '%:$group_id:%')");

    echo "
      <tr><th>"._m("Constants used in slice")."</th>
        <td colspan=\"3\">". join('<br>', array_map(fn ($arr) => safe($arr['deleted'] ? "&times;&times;&times; ". $arr['sname']." &times;&times;&times;" : $arr['sname'])." <small>[". $arr['unpackid']."]</small> (".safe($arr['fname'])." <small>[".safe($arr['fid'])."]</small>)", $using_slices_arr)) ."</td>
      </tr>";
}

echo "
<tr><th>"._m("Constant group owner - slice")."</th>
<td colspan=\"3\">";

if (!$constant_info["owner_id"] || !$group_id) {
    echo _m("Whoever first updates values becomes owner.");
} elseif ($chown AND is_array($g_modules) AND (count($g_modules) > 1) ) {
    // display the select box to change group owner if requested ($chown)
    echo "<select name=\"new_owner_id\">";
    foreach ($g_modules as $k => $v) {
        echo "<option value='". safe($k)."'". ($constant_info["owner_id"] == $k ? " selected" : ""). "> ". safe($v["name"]);
    }
    echo "</select>\n";
} else {
    echo $constant_info["name"]."&nbsp;&nbsp;&nbsp;&nbsp;
    <input type=\"submit\" name=\"chown\" value=\""._m("Change owner")."\">";
}

$propagate_ch = ( $group_id ? $constant_info["propagate"] : 1);   // default is checked for new constant group;

echo "</td></tr>
<tr><th>"._m("Propagate changes into current items")."</th><td colspan=\"3\"><input type=\"checkbox\" name=\"propagate_changes\"".($propagate_ch ? " checked" : "")."></td></tr>
<tr class='aa-tbl-header'>
 <th><b><a href=\"javascript:SortConstants('name')\">". _m("Name") ."</a></b><br>". _m("shown&nbsp;on&nbsp;inputpage") ."</th>
 <th><b><a href=\"javascript:SortConstants('value')\">". _m("Value") ."</a></b><br>". _m("stored&nbsp;in&nbsp;database") ."</th>
 <th><b><a href=\"javascript:SortPri()\">". _m("Priority") ."</a></b><br>". _m("constant&nbsp;order") ."</th>
 <th><b><a href=\"javascript:SortConstants('used')\">". _m("Used") ."</a></b><br>". _m("times") ."</th>
</tr>";

$pri_max = 1000;
$i=0;
// existing constants
if ($s_constants) {
    foreach ($s_constants as $v) {
        if ($from_form) {  // get values from form
            ShowConstant($i, $name[$i], $value[$i], $cid[$i], $pri[$i], $using_slices_arr);
            $pri_max = $pri[$i];
        } else {        // get values from database
            ShowConstant($i, $v["name"], $v["value"], $as_new ? '' : 'x'.unpack_id($v["id"]), $v["pri"], $using_slices_arr);
            $pri_max = $v['pri'];
        }
        $i++;
    }
}

// ten rows for possible new constants
for ($j=1; $j<=10; $j++) {
    ShowConstant($i, "", "", "", $pri_max + $j*10);
    $i++;
}

$lastIndex = $i-1;    // lastindex used in javascript (below) to get number of rows

FrmTabEnd($form_buttons);

echo '
<script>
    function deleteWholeGroup() {
        if (confirm("'._m("Are you sure you want to PERMANENTLY DELETE this group?"). '")) {
            document.f.elements[\'deleteGroup\'].value = 1;
            document.f.submit();
        }
    }

  var data2sort;

  function GetFormData( col2sort ) {
    let i,element;
    data2sort = [];
    for (i=0; i<=' . $lastIndex .'; i++) {
      element = document.getElementsByName(col2sort+"["+i+"]")[0];
      // add rownumber at the end of the text (to be able to get old position)
      data2sort[i] = element.value + " ~~"+i;
    }
  }

  function SortConstants(col2sort) {
    let i, element, element2, text, row, counter = 10;
    GetFormData(col2sort);
    data2sort.sort();
    for (i = 0; i <= '. $lastIndex .'; i++) {
      text = data2sort[i];
      row = text.substring(text.lastIndexOf(" ~~") + 3);
      element = document.getElementsByName("pri[" + row + "]")[0];
      element2 = document.getElementsByName(col2sort + "[" + row + "]")[0];
      if (element2.value === "") {
        element.value = 9000;
      } else {
        element.value = counter;
        counter += 10;  
      }
    }
  }

  function SortPri( ) {
    let i,element,counter=10;
    for (i=0; i<='. $lastIndex .'; i++) {
      element = document.getElementsByName("pri["+i+"]")[0];
      element.value = counter;
      counter += 10;
    }
  }
</script>';

$apage->printFoot();
