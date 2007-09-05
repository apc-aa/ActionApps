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
 *
*/
// Parameters: group_id - identifier of constant group
//             categ - if true, constants are taken as category, so
//                     APC parent categories are displayed for selecting parent
//             category - edit categories for this slice (no group_id nor categ required)
//             as_new - if we want to create new category group based on an existing (id of "template" group)

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."constedit_util.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

if ($categ OR $category) {
    if (!IfSlPerm(PS_CATEGORY)) {
        MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change category settings"), "admin");
        exit;
    }
}

// as_new and $group_id is varname4form()-ed (for easier parameter passing)
$as_new   = (strlen($as_new) > 1) ? pack_id(substr($as_new,1)) : null;
$group_id = (strlen($group_id) > 1) ? pack_id(substr($group_id,1)) : null;
$back_url = ($return_url ? ($fid ? con_url($return_url,"fid=".$fid) : $return_url) : "index.php3");

if ($deleteGroup && $group_id && !$category) {
    delete_constant_group($group_id);
    go_url($sess->url($back_url));
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset      = new Cvarset();

// Check permissions
if (! $category && $group_id ) {
    $SQL = "SELECT * FROM constant_slice INNER JOIN slice
        ON constant_slice.slice_id = slice.id
        WHERE group_id='$group_id'";

    $db->tquery($SQL);

    if ($db->next_record() && !CheckPerms( $auth->auth["uid"], "slice", unpack_id($db->f("slice_id")), PS_FIELDS)) {
        MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings for the slice owning this group")." (".$db->f("name").")", "admin");
        exit;
    }
}
/** ShowConstant function
 * @param $id
 * @param $name
 * @param $value
 * @param $cid
 * @param $pri
 * @param $class
 * @param $categ
 * @param $classes
 * @return
 *
 */
function ShowConstant($id, $name, $value, $cid, $pri, $class, $categ, $classes) {
    global $sess;
    $name = safe($name); $value=safe($value); $pri=safe($pri); $cid=safe($cid);

    echo "
    <tr>
      <td><input type=\"text\" name=\"name[$id]\" size=\"25\" maxlength=\"149\" value=\"$name\"></td>
      <td><input type=\"text\" name=\"value[$id]\" size=\"25\" maxlength=\"255\" value=\"$value\">
          <input type=\"hidden\" name=\"cid[$id]\" value=\"$cid\"></td>
      <td class=\"tabtxt\"><input type=\"text\" name=\"pri[$id]\" size=\"4\" maxlength=\"4\" value=\"$pri\"></td>";
    if ($categ) {   // it is categories - show APC wide categories for parent category select
        echo "<td class=\"tabtxt\">";
        echo "<select name=\"class[$id]\" $add>";
        foreach ($classes as $k => $v) {
            echo "<option value=\"". htmlspecialchars($k)."\"";
            if ((string)$class == (string)$k) {
                echo " selected";
            }
            echo "> ". htmlspecialchars($v['name']) ." </option>";
        }
        echo "</select>\n";
        echo "</td>";
    } else {
        echo "<td class=\"tabtxt\">&nbsp;</td>";
    }
    echo "</tr>\n";
}

/** propagateChanges function
* Propagates changes to a constant value to the items which contain this value.
*   @param $constant_id
*   @param string $newvalue The new value with added slashes (e.g. from a form)
*   @param $oldvalue
*/
function propagateChanges($constant_id, $newvalue, $oldvalue) {
    global $db, $group_id, $Msg, $debug, $event, $slice_id;

    if ($oldvalue == $newvalue) return;

    $event->comes('CONSTANT_BEFORE_UPDATE', $slice_id, 'S', $newvalue, $oldvalue, $constant_id);

    if ($oldvalue) {
        // we have to join also item table in order we make sure field is in right slice
        $db->tquery("
        SELECT item_id,field_id
          FROM content, item, field
         WHERE content.item_id=item.id
           AND item.slice_id = field.slice_id
           AND content.field_id = field.id
           AND (field.input_show_func LIKE '___:$group_id:%'
            OR  field.input_show_func LIKE '___:$group_id')
           AND content.text = '$oldvalue'");
    }
    $cnt = 0;
    $items2update = array();
    while ($db->next_record()) {
        ++$cnt;
        if ( !$items2update[$db->f("field_id")] ) {
            $items2update[$db->f("field_id")] = new zids( null, 'p');
        }
        $items2update[$db->f("field_id")]->add($db->f("item_id"));
    }
    foreach ( $items2update as $field => $zids ) {
        $SQL = "UPDATE content SET text='$newvalue' WHERE ". $zids->sqlin('item_id') ."
                AND field_id='".addslashes($field)."' AND text='$oldvalue'";
        $db->tquery($SQL);
    }
    if ($cnt) {
        $Msg .= $cnt . _m(" items changed to new value ") . "'$newvalue'<br>";
    }

    $event->comes('CONSTANT_UPDATED', $slice_id, 'S', $newvalue, $oldvalue, $constant_id);
}

hcUpdate();

if ($update) {
    do {
        if (!(isset($name) AND is_array($name))) {
            break;
        }
        foreach ($name as $key => $nam) {
            $prior     = $pri[$key];
            $val       = $value[$key];
            $cid[$key] = (($cid[$key]=="") ? "x".new_id() : $cid[$key] );  // unpacked, with beginning 'x' for string indexing array
            ValidateInput("nam", _m("Name"), $nam, $err, false, "text");   // if not filled it will be deleted
            ValidateInput("val", _m("Value"), $val, $err, false, "text");
            ValidateInput("prior", _m("Priority"), $prior, $err, false, "number");
        }

        if (!$group_id) {  // new constant group
            $new_group_id = str_replace(':','-',$new_group_id);  // we don't need ':'
                                                                 // in id (parameter separator)
            ValidateInput("new_group_id", _m("Constant Group"), $new_group_id, $err, true, "text");
            if (count($err) > 1) {
                break;
            }
            $SQL = "SELECT * FROM constant WHERE group_id = '$new_group_id'";
            $db->tquery($SQL);
            if ($db->next_record()) {
                $err["DB"] = _m("This constant group already exists");
            } else {
                $add_new_group = true;
                $group_id = $new_group_id;
            }
        }

        if (count($err) > 1) {
            break;
        }

        if ($group_id) {
            // if there is no group owner, promote this slice to owner
            $db->tquery("SELECT * FROM constant_slice WHERE group_id='$group_id'");
            if (!$db->next_record()) {
                $db->tquery("
                INSERT INTO constant_slice (slice_id,group_id,propagate)
                VALUES ('$p_slice_id','$group_id',".($propagate_changes ? 1 : 0).");");
            } else {
                $db->tquery("
                UPDATE constant_slice SET propagate=".($propagate_changes ? 1 : 0)."
                WHERE group_id = '$group_id'");
                if ($new_owner_id) {
                    $db->tquery("
                    UPDATE constant_slice SET slice_id='".addslashes(pack_id($new_owner_id))."'
                    WHERE group_id = '$group_id'");
                    $chown = 0;
                }
            }
        }

        // add new group to constant group list
        if ($add_new_group) {
            $varset->clear();
            $varset->set("id", new_id(), "unpacked" );
            $varset->set("group_id", 'lt_groupNames', "quoted" );
            $varset->set("name", $group_id, "quoted");
            $varset->set("value", $group_id, "quoted");
            $varset->set("class", '', "quoted");
            $varset->set("pri", 100, "number");
            if (!$varset->doInsert('constant')) {
                $err["DB"] .= MsgErr("Can't create constant group");
                break;
            }
        }

        foreach ($name as $key => $foonam) {
            $category_id = substr($cid[$key],1);     // remove beginning 'x'
            $p_cid       = q_pack_id($category_id);
            // if name is empty, delete the constant
            if ($foonam == "") {
                if (!$db->tquery("DELETE FROM constant WHERE id='$p_cid'")) {
                    $err["DB"] .= MsgErr("Can't delete constant");
                    break;
                }
                continue;
            }
            $varset->clear();
            $varset->set("name",  $name[$key], "quoted");
            $varset->set("value", $value[$key], "quoted");
            $varset->set("pri", ( $pri[$key] ? $pri[$key] : 1000), "number");
            $varset->set("class", $class[$key], "quoted");
            $db->tquery("SELECT * FROM constant WHERE id='$p_cid'");
            if ($db->next_record()) {
                if ($propagate_changes) {
                    propagateChanges($category_id, $value[$key], addslashes($db->f('value')));
                }
                if (!$db->tquery("UPDATE constant SET ". $varset->makeUPDATE() ." WHERE id='$p_cid'")) {
                    $err["DB"] .= MsgErr("Can't update constant");
                    break;
                }
            } else {
                $varset->set("id", $category_id, "unpacked" );
                $varset->set("group_id", $group_id, "quoted" );
                if (!$varset->doInsert('constant')) {
                    $err["DB"] .= MsgErr("Can't copy constant");
                    break;
                }
            }
        }

        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

        if (count($err) <= 1) {
            $Msg .= MsgOK(_m("Constants update successful"));
        }
    } while( 0 );           // in order we can use "break;" statement
}

// edit categories for this slice
if ($category) {
    $group_id = GetCategoryGroup($slice_id);
    if ($group_id) {
        $categ = true;
    } else {
        MsgPage($sess->url(self_base()."slicedit.php3"), _m("No category field defined in this slice.<br>Add category field to this slice first (see Field page)."), "admin");
        exit;
    }
}

// lookup constants
if ($group_id OR $as_new) {
    $gid = ( $as_new ? $as_new : $group_id );
    $SQL = "SELECT id, name, value, class, pri FROM constant
            WHERE group_id='$gid' ORDER BY pri, name";
    $s_constants = GetTable2Array($SQL, "NoCoLuMn");
}

// lookup apc categories classes
$SQL = "SELECT name, value, pri, id FROM constant
         WHERE group_id='lt_apcCategories' ORDER BY name";
$classes = GetTable2Array($SQL, "id");

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Admin - Constants Setting");?></title>
</head>
<?php

require_once AA_INC_PATH."menu.php3";
showMenu($aamenus, "sliceadmin", $categ ? "category" : "");

echo "<h1><b>" . _m("Admin - Constants Setting") . "</b></h1>";
PrintArray($err);
echo $Msg;

$form_buttons = array("update",
                      "cancel"   => array("url"=> $back_url),
                      "delgroup" => array("type"  => "button",
                                          "value" => _m("Delete whole group"),
                                          "add"   => 'onclick="deleteWholeGroup();"')
                      );
?>
<form method="post" name="f" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
 <input type="hidden" name="group_id" value="<?php echo varname4form($group_id); /* do not move it to $form_buttons - we need it also in hierarchical editor, which do not use $form_buttons!!! */ ?>">
 <input type="hidden" name="categ" value="<?php echo $categ;       /* the same as above for group_id */ ?>">
<?php

// load the HIERARCHICAL EDITOR
if ($hierarch) {
    require_once AA_INC_PATH."constedit.php3";
    // it exits here
}

FrmTabCaption(_m("Constants"), '', '', $form_buttons, $sess, $slice_id);

// this must be just once on the page
$form_buttons["deleteGroup"] = array("value" => "0");
$form_buttons["return_url"]  = array("value" => $return_url);
$form_buttons["fid"]         = array("value" => $fid);


echo "<td class=\"tabtxt\"><b>"._m("Constant Group") ."</b></td>
  <td class=\"tabtxt\" colspan=\"3\">";

if ( $group_id ) {
    echo safe($group_id);
} else {
    echo '<input type="text" name="new_group_id" size=\"16\" maxlength=\"16\" value="'.safe($new_group_id).'">
          <a href="'.get_admin_url('se_constant_import.php3?return_url=se_inputform.php3&amp;fid='. urlencode($fid)).
          '">'._m('Import Constants...').'</a>';
}
echo "\n     </td>\n</tr>";

// Find slices, where the constant group is used
if ($group_id) {
    $delim = '';
    $db->tquery("
        SELECT slice.name FROM slice, field
         WHERE slice.id = field.slice_id
           AND (field.input_show_func LIKE '%:$group_id' OR field.input_show_func LIKE '%:$group_id:%')");
    while( $db->next_record() ) {
        $using_slices .= $delim. $db->f('name');
        $delim = ', ';
    }
    echo "
      <tr><td><b>"._m("Constants used in slice")."</b></td>
        <td colspan=\"3\">$using_slices</td>
      </tr>";
}

// Find the slice owner of this group
$db->tquery("
    SELECT * FROM constant_slice INNER JOIN slice
    ON constant_slice.slice_id = slice.id
    WHERE group_id='$group_id'");
    if ($db->next_record()) {
        $owner_id = unpack_id128($db->f("slice_id"));
    }

echo "
<tr><td><b>"._m("Constant group owner - slice")."</b></td>
<td colspan=\"3\">";

if (!$owner_id || !$group_id) {
    echo _m("Whoever first updates values becomes owner.");
}
elseif($chown AND is_array($g_modules) AND (count($g_modules) > 1) ) {
    // display the select box to change group owner if requested ($chown)
    echo "<select name=\"new_owner_id\">";
    foreach ($g_modules as $k => $v) {
        echo "<option value='". htmlspecialchars($k)."'". ($owner_id == $k ? " selected" : ""). "> ". htmlspecialchars($v["name"]);
    }
    echo "</select>\n";
}
else {
    echo $db->f("name")."&nbsp;&nbsp;&nbsp;&nbsp;
    <input type=\"submit\" name=\"chown\" value=\""._m("Change owner")."\">";
}

$propagate_ch = ( $group_id ? $db->f("propagate") : 1);   // default is checked for new constant group;

echo "</td></tr>
<tr><td colspan=\"4\"><input type=\"checkbox\" name=\"propagate_changes\"".($propagate_ch ? " checked" : "").">"._m("Propagate changes into current items");
echo "'</td></tr>
<tr><td colspan=\"4\"><input type=\"submit\" name=\"hierarch\" value=\""._m("Edit in Hierarchical editor (allows to create constant hierarchy)")."\"></td></tr>
<tr>
 <td class=\"tabtxt\" align=\"center\"><b><a href=\"javascript:SortConstants('name')\">". _m("Name") ."</a></b><br>". _m("shown&nbsp;on&nbsp;inputpage") ."</td>
 <td class=\"tabtxt\" align=\"center\"><b><a href=\"javascript:SortConstants('value')\">". _m("Value") ."</a></b><br>". _m("stored&nbsp;in&nbsp;database") ."</td>
 <td class=\"tabtxt\" align=\"center\"><b><a href=\"javascript:SortPri()\">". _m("Priority") ."</a></b><br>". _m("constant&nbsp;order") ."</td>
 <td class=\"tabtxt\" align=\"center\"><b><a href=\"javascript:SortConstants('class')\">". _m("Parent") ."</a></b><br>". _m("categories&nbsp;only") ."</td>
</tr>
<tr><td colspan=\"4\"><hr></td></tr>";

// existing constants
if ($s_constants) {
    $i=0;
    foreach ($s_constants as $v) {
        if ($update) {  // get values from form
            ShowConstant($i, $name[$i], $value[$i], $cid[$i], $pri[$i], $class[$i], $categ, $classes);
        } else {        // get values from database
            ShowConstant($i, $v["name"], $v["value"], $as_new ? '' : 'x'.unpack_id128($v["id"]), $v["pri"], $v["class"], $categ, $classes);
        }
        $i++;
    }
}

// ten rows for possible new constants
for ($j=0; $j<10; $j++) {
    ShowConstant($i, "", "", "", 1000, "", $categ, $classes);
    $i++;
}

$lastIndex = $i-1;    // lastindex used in javascript (below) to get number of rows

FrmTabEnd($form_buttons, $sess, $slice_id);

echo '
</form>
<script language=javascript>
<!--
    function deleteWholeGroup() {
        if (confirm("'._m("Are you sure you want to PERMANENTLY DELETE this group?"). '")) {
            document.f.elements[\'deleteGroup\'].value = 1;
            document.f.submit();
        }
    }

  var data2sort;

  function GetFormData( col2sort ) {
    var i,element,varname;
    data2sort = null;
    data2sort = new Array();
    for (i=0; i<='. $lastIndex .'; i++) {
      element = "document.f.elements[\'"+col2sort+"["+i+"]\']";
      // add rownumber at the end of the text (to be able to get old possition)
      data2sort[i] = eval(element).value + " ~~"+i;
    }
  }

  function SortConstants( col2sort ) {
    var i,element,element2, text,row,counter=10;
    GetFormData(col2sort);
    data2sort.sort();
    for (i=0; i<='. $lastIndex .'; i++) {
      text = data2sort[i];
      row = text.substr(text.lastIndexOf(" ~~")+3);
      element = "document.f.elements[\'pri["+row+"]\']";
      element2 = "document.f.elements[\'"+col2sort+"["+row+"]\']";
      if (eval(element2).value == "")
        eval(element).value = 9000;
       else {
        eval(element).value = counter;
        counter += 10;
      }
    }
  }

  function SortPri( ) {
    var i,element,counter=10;
    for (i=0; i<='. $lastIndex .'; i++) {
      element = "document.f.elements[\'pri["+i+"]\']";
      eval(element).value = counter;
      counter += 10;
    }
  }

//-->
</script>';
HtmlPageEnd();
page_close()
?>
