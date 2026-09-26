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
    // array ( )
    $desc = InputVars::arrayPOST('desc');
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

    $Msg = InputVars::messageGETPOST();
} catch (Exception $e) { AA_Adminpageutil::pageParameterError($e); }


if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}

if (!IfSlPerm(PS_FIELDS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You do not have permissions to change fields settings"), "admin");
    exit;
}

// as_new and $group_id is varname4form()-ed (for easier parameter passing)
$as_new    = constant_group_from_form($as_new);
$group_id  = constant_group_from_form($group_id);
if (($as_new === null) || ($group_id === null)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m('Wrong constant group id'), "admin");
    exit;
}
$back_url  = ($return_url ? ($fid    ? con_url($return_url,"fid=".$fid) : $return_url) : "index.php");
$from_form = false;  // display the values from form (after unsucessful update)
$may_edit_slice = static fn(string $slice_id): bool => CheckPerms(AuthGetUid(), "slice", $slice_id, PS_FIELDS);
$constant_info  = $group_id ? constant_group_owner($group_id) : false;

$superadmin     = IsSuperadmin();

// Check permissions - before anything is changed. Some groups may be only viewed (core, shared).
$readonly = '';     // why the group can't be changed
if ($group_id) {
    $changes  = $update || $deleteGroup || ($hcalldata !== '') || ($new_owner_id !== '');
    $denied   = constant_group_denied($group_id, $constant_info, $may_edit_slice, false, $superadmin, false);
    $readonly = $denied ?: constant_group_denied($group_id, $constant_info, $may_edit_slice, $deleteGroup, $superadmin);
    if ($denied || ($changes && $readonly)) {
        AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", $denied ?: $readonly, "admin");
        exit;
    }
}

if ($deleteGroup && $group_id) {
    if (!delete_constant_group($group_id)) {
        AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("Can't delete constant group"), "admin");
        exit;
    }
    go_url(StateUrl($back_url));
}

$err = [];          // error array (Init - just for initializing variable
$varset      = new Cvarset();

/** ShowConstant function
 *
 * @param      $id
 * @param      $name
 * @param      $value
 * @param      $desc
 * @param      $cid
 * @param      $pri
 * @param null|array $using_slices_arr
 */
function ShowConstant($id, $name, $value, $desc, $cid, $pri, ?array $using_slices_arr, array $sizes): void
{
    $count = '';
    if (is_array($using_slices_arr) && count($using_slices_arr)) {
        $count = 0;
        foreach ($using_slices_arr as $arr) {
            $set = new AA_Set([$arr['unpackid']], new AA_Condition($arr['fid'], '==', $value), null, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING);
            $count += count($set->query());
        }
    }

    $name = safe($name); $value=safe($value); $desc=safe($desc); $pri=safe($pri); $cid=safe($cid); $id=safe($id);

    echo "
    <tr>
      <td><input type=\"text\" name=\"name[$id]\" size=\"{$sizes['name']}\" maxlength=\"149\" value=\"$name\"></td>
      <td><input type=\"text\" name=\"value[$id]\" size=\"{$sizes['value']}\" maxlength=\"255\" value=\"$value\">
          <input type=\"hidden\" name=\"cid[$id]\" value=\"$cid\"></td>
      <td><input type=\"text\" name=\"desc[$id]\" size=\"{$sizes['desc']}\" maxlength=\"250\" value=\"$desc\"></td>
      <td><input type=\"text\" name=\"pri[$id]\" size=\"4\" maxlength=\"4\" value=\"$pri\"></td>
      <td>$count<input type=\"hidden\" name=\"used[$id]\" value=\"".str_pad($count, 7, "0", STR_PAD_LEFT)."\"></td>";
    echo "</tr>\n";
}

if ($hcalldata || $hierarch) {
    hcUpdate();
}

if ($update) {
    $from_form = true;
    do {
        if (!(isset($name) && is_array($name))) {
            break;
        }
        foreach ($name as $key => $nam) {
            $prior     = $pri[$key];
            $val       = $value[$key];
            ValidateInput("nam", _m("Name"), $nam, $err, false, "text");   // if not filled it will be deleted
            ValidateInput("val", _m("Value"), $val, $err, false, "text");
            ValidateInput("desc", _m("Description"), $desc, $err, false, "text");
            ValidateInput("prior", _m("Priority"), $prior, $err, false, "number");
        }

        if (!strlen($group_id)) {  // new constant group
            $new_group_id = substr(ConvertEncoding(str_replace(':','-',$new_group_id),'','us-ascii'),0,16);  // we don't need ':' nor UTF characters - we have 16 Bytes
                                                                 // in id (parameter separator)
            ValidateInput("new_group_id", _m("Constant Group"), $new_group_id, $err, true, "text");
            if (!count($err) && !constant_group_id_valid($new_group_id)) {
                $err["new_group_id"] = _m('Wrong constant group id');
            }
            if (count($err)) {
                break;
            }
            if (DB_AA::test('constant', [['group_id',$new_group_id]])) {
                $err["DB"] = _m("This constant group already exists");
            } elseif ($denied = constant_group_denied($new_group_id, constant_group_owner($new_group_id), $may_edit_slice, false, $superadmin)) {
                $err["DB"] = $denied;    // an orphan constant_slice row may still name its owner
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
                    if (!is_long_id($new_owner_id) || !$may_edit_slice($new_owner_id)) {
                        $err["DB"] = _m("You do not have permissions to change fields settings in the new owner slice");
                        break;
                    }
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
                    // only constants of this group - the ids come from the form
                    if (!DB_AA::delete('constant', [['id',$category_id, 'l'], ['group_id', $group_id]])) {
                        $err["DB"] .= MsgErr("Can't delete constant " . safe($category_id));
                        break;
                    }
                }
                continue;
            }
            $data_constant = ['id'=> $category_id, 'name' => $name[$key], 'value' => $value[$key], 'description' => $desc[$key], 'pri' => ( $pri[$key] ?: 1000)];
            if (false !== ($oldval = DB_AA::select1('value', 'SELECT `value` FROM `constant`',[['id',$category_id,'l'], ['group_id', $group_id]]))) {
                if ($propagate_changes) {
                    propagateChanges($group_id, $category_id, $value[$key], $oldval);
                }
                $data_constant['id'] = $category_id;
                if (!AA::Metabase()->doUpdate('constant', $data_constant)) {
                    $err["DB"] .= MsgErr("Can't update constant");
                    break;
                }
            } elseif (DB_AA::test('constant', [['id', $category_id, 'l']])) {
                $err["DB"] .= MsgErr("Can't update constant");   // the id belongs to another group
                break;
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
        ConstantsChanged();                              // constant reads are cached per request
        AA::Pagecache()->invalidateFor(AA::$module_id);  // invalidate old cached values

        if (!count($err)) {
            $from_form = false;
            $Msg .= MsgOk(_m("Constants update successful"));
        }
    } while( 0 );           // in order we can use "break;" statement
}

// lookup constants
$s_constants = ($group_id || $as_new) ? DB_AA::select([], 'SELECT `id`, `name`, `value`, `description`, `pri` FROM `constant`', [['group_id', ( $as_new ?: $group_id )]], ['pri', 'name']) : [];

$apage = new AA_Adminpageutil('sliceadmin','fields');
$apage->setTitle(_m("Admin - Constants Setting"));
if ($readonly) {
    $Msg .= MsgErr(_m('The constant group can be only viewed here') . ': ' . $readonly);
}
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
if ($readonly) {
    $form_buttons = ["cancel" => ["url" => $back_url]];
}
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
  <td colspan=\"4\">";

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
    $using_slices_arr = constant_group_fields($group_id);
    echo "
      <tr><th>"._m("Constants used in slice")."</th>
        <td colspan=\"4\">".implode(
            '<br>',
            array_map(
                static fn($arr) => AA_Module::getModuleNameSafe($arr['unpackid'], IfSlPerm(PS_FIELDS, $arr['unpackid']), 'admin/se_inputform.php', ['fid' => $arr['fid']])." <small>[".$arr['unpackid']."]</small> (".safe($arr['fname'])." <small>[".safe($arr['fid'])."]</small>)",
                $using_slices_arr,
            ),
        )."</td>
      </tr>";
}

echo "
<tr><th>"._m("Constant group owner - slice")."</th>
<td colspan=\"4\">";

if (!$constant_info["owner_id"] || !$group_id) {
    echo _m("Whoever first updates values becomes owner.");
} elseif ($chown && is_array($g_modules) && count($g_modules) > 1 ) {
    // display the select box to change group owner if requested ($chown)
    echo "<select name=\"new_owner_id\">";
    foreach ($g_modules as $k => $v) {
        echo "<option value='". safe($k)."'". ($constant_info["owner_id"] == $k ? " selected" : ""). "> ". safe($v["name"]);
    }
    echo "</select>\n";
} else {
    echo safe($constant_info["name"]). ($readonly ? '' : "&nbsp;&nbsp;&nbsp;&nbsp;
    <input type=\"submit\" name=\"chown\" value=\""._m("Change owner")."\">");
}

$propagate_ch = ( $group_id ? $constant_info["propagate"] : 1);   // default is checked for new constant group;

echo "</td></tr>
<tr><th>"._m("Propagate changes into current items")."</th><td colspan=\"4\"><input type=\"checkbox\" name=\"propagate_changes\"".($propagate_ch ? " checked" : "")."></td></tr>
<tr class='aa-tbl-header'>
 <th><b><a href=\"javascript:SortConstants('name')\">". _m("Name") ."</a></b><br>". _m("shown&nbsp;on&nbsp;inputpage") ."</th>
 <th><b><a href=\"javascript:SortConstants('value')\">". _m("Value") ."</a></b><br>". _m("stored&nbsp;in&nbsp;database") ."</th>
 <th><b><a href=\"javascript:SortConstants('desc')\">". _m("Description") ."</a></b><br>". _m("can be viewed by {constant:&lt;group&gt;:&lt;value&gt;:description}") ."</th>
 <th><b><a href=\"javascript:SortPri()\">". _m("Priority") ."</a></b><br>". _m("constant&nbsp;order") ."</th>
 <th><b><a href=\"javascript:SortConstants('used')\">". _m("Used") ."</a></b><br>". _m("times") ."</th>
</tr>";

$SIZES = ['name'=> min(80, max(40, 40, ...array_map('strlen', array_column($s_constants, 'name')))),  // find max length in $name arr and use that value, but minimum 10 and max 60
          'value'=>min(80, max(40, 40, ...array_map('strlen', array_column($s_constants, 'value')))),
          'desc'=>min(80, max(40, 40, ...array_map('strlen', array_column($s_constants, 'description'))))];

$pri_max = 1000;
$i=0;
// existing constants
if ($s_constants) {
    foreach ($s_constants as $v) {
        if ($from_form) {  // get values from form
            ShowConstant($i, $name[$i], $value[$i], $desc[$i], $cid[$i], $pri[$i], $using_slices_arr, $SIZES);
            $pri_max = $pri[$i];
        } else {        // get values from database
            ShowConstant($i, $v["name"], $v["value"], $v["description"], $as_new ? '' : 'x'.unpack_id($v["id"]), $v["pri"], $using_slices_arr, $SIZES);
            $pri_max = $v['pri'];
        }
        $i++;
    }
}

// ten rows for possible new constants
for ($j=1; $j<=10; $j++) {
    ShowConstant($i, "", "", "", "", $pri_max + $j*10, null, $SIZES);
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
