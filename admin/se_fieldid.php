<?php
/**
 * This script allows to change a field ID in hopefully all tables where it occurs.
 *
 *  The array MAINTAIN_FIELDS contains database fields to be checked. All such fields
 *  are downloaded from database, the old ID occurring anywhere in them is changed to the
 *  new one and the fields are uploaded back.
 *
 *  Some texts cannot be described in this easy way, so MAINTAIN_SQL may contain other
 *  SQL commands. You may use the :old_id: and :new_id: strings which will be replaced by the old / new ids.
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
 * @author    Jakub Adamek, May 2002
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/


use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

// const  AA_REGISTER_GLOBALS = true;
require_once __DIR__ . "/../include/init_page.php";
require_once __DIR__ . "/../include/formutil.php";
require_once __DIR__ . "/../include/varset.php";

require_once __DIR__ . "/../include/util.php";

try {
    // array ( '.', 2038 => 1, 19 => 1, )
    $new_id_number = InputVars::stringPOST('new_id_number');
    // array ( '_year' => 1, 'code' => 1, )
    $new_id_text = InputVars::stringSlashedPOST('new_id_text');
    // array ( 'time.......…' => 1, 'id.........…' => 1, )
    $old_id = InputVars::stringSlashedPOST('old_id');
    // array ( ' Změnit [^…' => 2, )
    $update = InputVars::boolPOST('update');
    $cancel = InputVars::boolPOST('cancel');
    
    $Msg = InputVars::stringGETPOST('Msg');
} catch (Exception) {}


MoreTimeLimit(600); // set_time_limit to 10 minutes (at least)

const RESERVED_IDS = [
  "disc_app........",
  "disc_count......",
  "display_count...",
  "edited_by.......",
  "expiry_date.....",
  "flags...........",
  "highlight.......",
  "last_edit.......",
  "posted_by.......",
  "post_date.......",
  "publish_date....",
  "slice_id........",
  "status_code....."
];

$xslice_id = xpack_id(AA::$module_id);

if ($cancel) {
    go_url( StateUrl(self_base() . "index.php"));
}


if (!IfSlPerm(PS_FIELDS)) {
    AA_Adminpageutil::MsgPageMenu(StateUrl(self_base()) . "index.php", _m("You have not permissions to change fields settings"), "admin");
    exit;
}

$err = [];          // error array (Init - just for initializing variable
$varset = new Cvarset();

/** ChangefieldID function
 * @param $old_id
 * @param $new_id
 */
function ChangeFieldID($old_id, $new_id) {
    define("MAINTAIN_FIELDS", [
        "slice" => [
            "primary"      => "id",  // primary key
            "primary_type" => "text", // key type (text / number)
            "slice_id"     => "id", // slice_id key
            "fields"       => [     // fields to be changed
                                    "fulltext_format_top",
                                    "fulltext_format",
                                    "fulltext_format_bottom",
                                    "odd_row_format",
                                    "even_row_format",
                                    "compact_top",
                                    "compact_bottom",
                                    "category_top",
                                    "category_format",
                                    "category_bottom",
                                    "admin_format_top",
                                    "admin_format",
                                    "admin_format_bottom",
                                    "aditional",
                                    "javascript"
            ]
        ],
        "field" => [
            "primary_part" => "id", // the whole key is (id,slice_id)
            "primary_type" => "text",
            "slice_id"     => "slice_id",
            "fields"       => [
                "id",
                "alias1_func",
                "alias2_func",
                "alias3_func"
            ]
        ],
        "view"  => [
            "primary"      => "id",
            "primary_type" => "number",
            "slice_id"     => "slice_id",
            "fields"       => [
                "before",
                "even",
                "odd",
                "after",
                "group_title",
                "order1",
                "order2",
                "group_by1",
                "group_by2",
                "cond1field",
                "cond2field",
                "cond3field",
                "aditional",
                "aditional2",
                "aditional3",
                "aditional4",
                "aditional5",
                "aditional6",
                "group_bottom",
                "field1",
                "field2",
                "field3"
            ]
        ]
    ]);

    $xslice_id = xpack_id(AA::$module_id);

    define("MAINTAIN_SQL", [
        "UPDATE feedmap SET to_field_id=':new_id:' WHERE to_field_id=':old_id:' AND to_slice_id = $xslice_id",
        "UPDATE feedmap SET from_field_id=':new_id:' WHERE from_field_id=':old_id:' AND from_slice_id = $xslice_id"
    ]);

    $varset = new Cvarset();
    foreach ( MAINTAIN_FIELDS as $table => $settings) {
        $keyfield = $settings['primary'];
        if (!$keyfield) {
            $keyfield = $settings['primary_part'];
        }
        $SQL = "SELECT `$keyfield`, `". implode("`, `", $settings['fields'])."` FROM `$table`  WHERE ".$settings['slice_id']." = $xslice_id";
        $rows = GetTable2Array ($SQL);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $varset->clear();
                foreach ($settings['fields'] as $field) {
                    $cont = $row[$field];
                    if (str_contains($cont, $old_id)) {
                        $cont = str_replace($old_id, $new_id, $cont);
                        $varset->set($field, $cont, "text");
                    }
                }
                if ($varset->vars) {
                    $SQL = "UPDATE $table SET ".$varset->makeUPDATE();
                    $SQL .= " WHERE $keyfield = ";
                    if ($settings['primary_type'] === "text") {
                        $SQL .= "'".$row[$keyfield]."'";
                    } else {
                        $SQL .= $row[$keyfield];
                    }
                    if ($settings['primary_part']) {
                        $SQL .= " AND ". $settings["slice_id"]." = $xslice_id";
                    }
                    DB_AA::sql($SQL);
                }
            }
        }
    }

    foreach (MAINTAIN_SQL as $sql) {
        $sql = str_replace([":old_id:", ":new_id:"], [$old_id, $new_id], $sql);
        DB_AA::sql($sql);
    }

    // replace the field id in table content
    DB_AA::sql("UPDATE content c INNER JOIN item i ON c.item_id = i.id SET c.field_id = '$new_id' WHERE i.slice_id = $xslice_id AND c.field_id = '$old_id';");
    // DB_AA::sql("UPDATE `content` SET `field_id`='$new_id' WHERE `item_id` IN (SELECT `id` FROM `item` WHERE `slice_id` = $xslice_id) AND `field_id` = '$old_id'");  // slow
}

$nchanges = 0;
if ($update && $new_id_text && AA::$module_id) {
    $new_id = AA_Fields::createFieldId($new_id_text, $new_id_number);
    if ($old_id == $new_id) {
        $err[] = _m("The new ID is the same as the old one");
    } elseif (strlen($old_id) !== 16) {
        $err[] = _m("The old ID is not valid");
    } elseif (strlen($new_id) !== 16) {
        $err[] = _m("The new ID is not valid");
    } elseif (in_array($new_id, RESERVED_IDS)) {
        $err[] = _m("This ID is reserved")." ($new_id).";
    } elseif (DB_AA::test("field", [["slice_id", AA::$module_id, 'l'], ["id", $new_id]])) {    // prove the field does not exist
        $err[] = _m("This ID is already used")." ($new_id).";
    } else {
        ++$nchanges;
        ChangeFieldID($old_id, $new_id);
    }
}

// lookup source fields
$s_fields = AA_Fields::getFields4Select(AA::$module_id, 'all', 'id');
// lookup destination fields
$d_fields = AA_Fields::getFields4Select(unpack_id('AA_Core_Fields..'), 'all', 'id');


$footcode = '<script>
  function ChangeFld(id) {
      document.getElementById("old_id").value=id;
      const type = id.split(".")[0];
      const selectElement = document.getElementById("new_id_text");
      if (Array.from(selectElement.options).find(opt => opt.value === type)) {
          selectElement.value = type;
      }
      return false;
  }
</script>';


$apage = new AA_Adminpageutil('sliceadmin','field_ids');
$apage->setTitle(_m("Admin - change Field IDs"));
$apage->addRequire($footcode, 'AA_Req_Footcode');

if ($update) {
    $Msg = "$nchanges "._m("field IDs were changed").".";
}

$apage->printHead($err, $Msg);

FrmTabCaption(_m("Change Field IDs"));

echo"<tr><td>"._m("This page allows to change field IDs. It is a bit dangerous operation and may last long.\n    You need to do it only in special cases, like using search form for multiple slices. <br><br>\n    Choose a field ID to be changed and the new name and number, the dots ..... will be\n    added automatically.<br>")."</td></tr>
<tr><td><br>"._m("Change from").": <select name='old_id' id='old_id'>";
$slice_fields = false;
foreach ($s_fields as $fid => $fname) {
    if (!in_array($fid, RESERVED_IDS)) {
         if ( AA_Fields::isSliceField($fid)) {
            $slice_fields = true;
        }
        echo "<option>$fid";
    }
}
echo "</select> ";

echo _m("to")." <select name='new_id_text' id='new_id_text'>";
foreach ($d_fields as $fid => $fname) {
    echo "<option>$fid";
}
// if we use also slice setting fields in this slice, then we should generate
// it also as proposal for renaming
if ($slice_fields) {
    // once again, but with underscore before field - so it is "slice field"
    foreach ($d_fields as $fid => $fname) {
        echo "<option>_$fid";
    }
}

echo "</select> <select name='new_id_number'>
<option value='.'>.";
for ($i = 1; $i <= 2999; ++$i) {
    echo "<option>$i";
}
echo "</select><br><br></td></tr>";

$form_buttons =  [
    "update" => ["type" => "hidden", "value" => "1"],
    "update",
    "cancel" => ["url" => "se_fields.php"]
];;
FrmTabEnd($form_buttons);

$rows = [[_m("Id"), _m("Field")]];
foreach ($s_fields as $fid => $fname) {
    if (!in_array($fid, RESERVED_IDS)) {
        $setlink = "<a href=\"javascript:;\" onclick=\"return ChangeFld('$fid');\">$fid</a>";
        $rows[] = [$setlink, $fname];
    }
}
$apage->addHelpbox('', GetHtmlTable($rows, 'th'));
$apage->printFoot();
