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


// expected $slice_id for edit slice, nothing for adding slice
// optional slice_fields = 1 (for slice fields)

require_once "../include/init_page.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."msgpage.php3";

if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if (!IfSlPerm(PS_FIELDS)) {
    MsgPageMenu($sess->url(self_base())."index.php3", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$err["Init"] = "";          // error array (Init - just for initializing variable
$varset = new Cvarset();

// lookup - APC wide possible field types are defined as special slice AA_Core_Fields..
$SQL = "SELECT * FROM field  WHERE slice_id='AA_Core_Fields..'";
if (!$slice_fields) {
    // AA_Core_Fields.. holds also templates for special slice fields, like _upload_url.....
    // and we do not want to list it as option for normal fields
    $SQL .= " AND name NOT LIKE '\_%'";
}
$field_types  = GetTable2Array($SQL);

/** ShowField function
 * @param $id
 * @param $name
 * @param $pri
 * @param $required
 * @param $show
 * @param $type=""
 * @param $alias=""
 * @param $separate=false
 */
function ShowField($id, $name, $pri, $required, $show, $type="", $alias="", $separate=false) {
    global $sess, $field_types, $AA_CP_Session;
    $name = safe($name); $pri=safe($pri);

    $rowclass = ((substr ($id,0,6) == "alerts") ? 'tabtxt_field_alerts' : 'tabtxt');
    if ( $separate ) {
        $rowclass .= ' separator';
    }
    echo "<tr class=\"$rowclass\">
      <td><input type=\"Text\" name=\"name[$id]\" size=25 maxlength=254 value=\"$name\"></td>";
    if ( $type=="new" ) {
        echo '<td>
              <select name="ftype">';
        foreach ( $field_types as $k => $v) {
            echo '<option value="'. htmlspecialchars($k).'"> '.
                 htmlspecialchars($v['name']) ." </option>";
        }
        echo "</select>\n </td>";
    } else {
        echo "<td>$id</td>";
    }
    echo "
        <td><input type=\"text\" name=\"pri[$id]\" size=\"4\" maxlength=\"4\" value=\"$pri\"></td>
        <td><input type=\"checkbox\" name=\"req[$id]\"". ($required ? " checked" : "") ."></td>
        <td><input type=\"checkbox\" name=\"shw[$id]\"". ($show ? " checked" : "") ."></td>";
    if ( $type=="new") {
        echo "<td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>";
    } else {
        echo "<td><a href=\"". $sess->url(con_url("./se_inputform.php3", "fid=".urlencode($id))) ."\">". _m("Edit") ."</a></td>";
        if ( $type=="in_item_tbl" ) {
            echo "<td>". _m("Delete") ."</td>";
        } else {
            echo "<td><a href=\"javascript:DeleteField('$id')\">". _m("Delete") ."</a></td>";
        }
        $alias_list = (is_array($alias) ? join($alias," ") : '');
        echo "<td class=\"tabhlp\">$alias_list</td>";
    }
    echo "</tr>\n";
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

        if (count($err) > 1) {
          break;
        }

        $db = getDB();
        foreach ($name as $key => $val) {
            if ($key == "New_Field") {   // add new field
                if ($val == '') {        // if not filled - don't add the field
                    continue;
                }

                // copy fields
                // use the same setting for new field as template in AA_Core_Fields..
                $varset->clear();
                $varset->addArray( $FIELD_FIELDS_TEXT, $FIELD_FIELDS_NUM );
                $varset->setFromArray($field_types[$ftype]);   // from template for this field

                // in AA_Core_Fields.. are fields identified by 'switch' or 'text'
                // identifiers (without dots!) by default. However if user add new
                // "template" field to the AA_Core_Fields.. slice, then the identifier
                // is full (it contains dots). We need base identifier, for now.
                // Also we will add underscore for all "slice fields" - the ones
                // which are not set for items, but rather for slice (settings)
                $ftype_base = ($slice_fields ? '_' : '') . AA_Fields::getFieldType($ftype);

                // get new field id
                $SQL = "SELECT id FROM field
                        WHERE slice_id='$p_slice_id' AND id like '". $ftype_base ."%'";
                $max = -1;  // Was 0
                $db->query($SQL);   // get all fields with the same type in this slice
                while ( $db->next_record() ) {
                    $max = max( $max, AA_Fields::getFieldNo($db->f('id')), 0);
                }
                $max++;
                //create name like "time...........2"
                $fieldid = AA_Fields::createFieldId($ftype_base, $max);

                $varset->set("slice_id", $slice_id, "unpacked" );
                $varset->set("id", $fieldid, "quoted" );
                $varset->set("name",  $val, "quoted");
                $varset->set("input_pri", $pri[$key], "number");
                $varset->set("required", ($req[$key] ? 1 : 0), "number");
                $varset->set("input_show", ($shw[$key] ? 1 : 0), "number");
                if (!$varset->doInsert('field')) {
                    $err["DB"] .= MsgErr("Can't copy field");
                    break;
                }
            } else { // current field
                $varset->clear();
                $varset->add("name", "quoted", $val);
                $varset->add("input_pri", "number", $pri[$key]);
                $varset->add("required", "number", ($req[$key] ? 1 : 0));
                $varset->add("input_show", "number", ($shw[$key] ? 1 : 0));
                $SQL = "UPDATE field SET ". $varset->makeUPDATE() .
                      " WHERE id='$key' AND slice_id='$p_slice_id'";
                if (!$db->query($SQL)) {  // not necessary - we have set the halt_on_error
                    $err["DB"] = MsgErr("Can't change field");
                    break;
                }
            }
        }
        freeDB($db);
        $GLOBALS['pagecache']->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

        if (count($err) <= 1) {
            $Msg = MsgOK(_m("Fields update successful"));
            if ($name["New_Field"]) {
                $url2go = $slice_fields ? get_url($_SERVER['PHP_SELF'],'slice_fields=1') : $_SERVER['PHP_SELF'];
                go_url($sess->url($url2go));  // reload to incorporate new field
            }
        }
    } while (false);           //in order we can use "break;" statement
}

// slice_fields are begins with underscore
// slice fields are the fields, which we do not use for items in the slice, but
// rather for setting parameters of the slice
$slice_fields_where = ($slice_fields) ? "AND id LIKE '\_%'" : "AND id NOT LIKE '\_%'";

// lookup fields
$SQL = "SELECT id, name, input_pri, required, input_show, in_item_tbl, alias1, alias2, alias3, input_before
        FROM field
        WHERE slice_id='$p_slice_id' $slice_fields_where ORDER BY input_pri";
$s_fields = GetTable2Array($SQL);

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
 <title><?php echo _m("Admin - configure Fields");?></title>
 <script type="text/javascript"><!--
   function DeleteField(id) {
     if ( !confirm("<?php echo _m("Do you really want to delete this field from this slice?"); ?>"))
       return
     var url="<?php echo $sess->url(con_url("./se_inputform.php3", "del=1")); ?>"
     document.location=url + "&fid=" + escape(id);
   }
// -->
</script>

</head>
<?php
  require_once AA_INC_PATH."menu.php3";
  showMenu($aamenus, "sliceadmin", $slice_fields ? 'slice_fields' : 'fields');

  echo "<h1><b>" . _m("Admin - configure Fields") . "</b></h1>";
  PrintArray($err);
  echo $Msg;



?>

<form method="post" action="<?php echo $sess->url($_SERVER['PHP_SELF']) ?>">
<?php
$form_buttons = array("update", "cancel"=>array("url"=>"se_fields.php3"));
FrmTabCaption(_m("Fields"), '','', $form_buttons, $sess, $slice_id);
?>
<tr>
 <td class="tabtxt" align="center"><b><?php echo _m("Field") ?></b></td>
 <td class="tabtxt" align="center"><b><?php echo _m("Id") ?></b></td>
 <td class="tabtxt" align="center"><b><?php echo _m("Priority") ?></b></td>
 <td class="tabtxt" align="center"><b><?php echo _m("Required") ?></b></td>
 <td class="tabtxt" align="center"><b><?php echo _m("Show") ?></b></td>
 <td class="tabtxt" colspan="2">&nbsp;</td>
 <td class="tabtxt" align="center"><b><?php echo _m("Aliases")?></b></td>
</tr>
<tr><td colspan="8"><hr></td></tr>
<?php
if ( isset($s_fields) and is_array($s_fields)) {
    foreach ( $s_fields as $v) {
        $type = ( $v['in_item_tbl'] ? "in_item_tbl" : "" );

        if ( $update ) {# get values from form
            ShowField($v['id'], $name[$v['id']], $pri[$v['id']], $req[$v['id']], $shw[$v['id']], $type, array($v['alias1'], $v['alias2'], $v['alias3']), strpos($v['input_before'],'{formbreak')!==false);
        } else {
            ShowField($v['id'], $v['name'], $v['input_pri'], $v['required'], $v['input_show'], $type, array($v['alias1'], $v['alias2'], $v['alias3']), strpos($v['input_before'],'{formbreak')!==false);
        }
    }
}
$form_buttons['slice_fields'] = array('value' => ($slice_fields ? 1 : 0));

// one row for possible new field
ShowField("New_Field", "", "1000", false, true, "new", "", true);
FrmTabEnd( $form_buttons, $sess, $slice_id);

echo '</form>';

HtmlPageEnd();
page_close();
?>
