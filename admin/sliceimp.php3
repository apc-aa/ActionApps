<?php
/**  Imports the slice definition and data, exported from toolkit
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
 * @author    Jakub Adamek, Pavel Jisl
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


$require_default_lang = true;      // do not use module specific language file
                                   // (message for init_page.php3)
require_once "../include/init_page.php3";
require_once AA_INC_PATH."itemfunc.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."feeding.php3";
require_once AA_INC_PATH."notify.php3";
require_once AA_INC_PATH."mgettext.php3";
require_once AA_INC_PATH."formutil.php3";

if (!CheckPerms( $auth->auth["uid"], "aa", AA_ID, PS_ADD) ) {
    MsgPage($sess->url(self_base())."index.php3", _m("You are not allowed to export / import slices"));
    exit;
}

$varset     = new Cvarset();
$itemvarset = new Cvarset();

// Prooves whether this ID already exists in the slices table,
// changes the ID to a new chosen one
/** proove_ID function
 * @param $slice
 * @return true
 */
function proove_ID($slice) {
    global $resolve_conflicts, $overwrite, $new_slice_ids;

    $res = $resolve_conflicts[$slice["id"]];
    if ((strlen($res)!=0)&&(strlen($res) != 16))	{
        if ((strlen($res) != 16)&&(strlen($res) != 32)) {
            echo "Warning: ". _m("Slice_ID (%1) has wrong length (%2, should be 32)",
                array ($res, strlen ($res)))."<br>\n";
        }
        $res = pack_id($res);
        if (strlen($res) == 16) {
            $slice["id"] = unpack_id($res);
        }
    }
    // Find out whether a slice of the same ID already exists

        // ac je to nepochopitene, nektera ID maji 30 znaku (ale niz uz nejdu)

//    if ((strlen($slice["id"]) != 32)&&(strlen($slice["id"]) != 30)) {
//		echo "Warning: ". _m("Slice_ID (%1) has wrong length (%2, should be 32)", $slice["id"], strlen($slice["id"]));
//	}
    // back-up old ids, if you want import slice definition with new id
    $new_slice_ids[$slice["id"]]["new_id"] = new_id();

    $slice_id = q_pack_id($slice["id"]);
    //echo "$slice_id";
    $SQL = "SELECT id FROM slice WHERE id=\"$slice_id\"";

    global $db;
    $db->query($SQL);
    if ($db->next_record()) {
        // if we want overwrite, delete old slice definition
        if ($GLOBALS["Submit"] == _m("Overwrite")) {
            $SQL = "DELETE FROM slice WHERE id='$slice_id'";	$db->query($SQL);
            $SQL = "DELETE FROM field WHERE slice_id='$slice_id'";	$db->query($SQL);
            $SQL = "DELETE FROM module WHERE id='$slice_id'"; $db->query($SQL);
            $overwrite = true;
        }
        else return false;
    } else {
        $overwrite = false;
    }
    return true;
}

/** proove_data_ID function
 * @param $data_id
 * @return true
 */
 // same function as above, but for table item and content
function proove_data_ID($data_id) {
    global $data_resolve_conflicts, //Set from data_conflicts_list
           $data_overwrite;
    $res = $data_resolve_conflicts[$data_id];
    if ($res && strlen($res) != 16)	{
        if ((strlen($res) != 16)||(strlen($res) != 32)) {
//			echo "Warning: ". _m("Slice_data_ID (".$res.") has wrong length (".strlen($res).", should be 32)<br>\n");
        }
        $res = q_pack_id($res);
    }
    if (strlen($res) == 16) {
        $data_id = unpack_id($res);
    }
    // Find out whether item with the same ID already exists
//	if ((strlen($data_id) != 32)&&(strlen($data_id) != 30)) {
//		echo "Warning: ". _m("Slice_ID (%1) has wrong length (%2, should be 32)", $slice["id"], strlen($slice["id"]));
//	}
    $old_data_id = addslashes(pack_id($data_id));
    $SQL         = "SELECT * FROM item WHERE id=\"$old_data_id\"";
    global $db;
    $db->query($SQL);
    if ($db->next_record()) {
        // if we want overwrite existing items, delete it
        if ($GLOBALS["Submit"] == _m("Overwrite")) {
            $SQL = "DELETE FROM item WHERE id='$old_data_id'";	$db->query($SQL);
            $SQL = "DELETE FROM content WHERE item_id='$old_data_id'";	$db->query($SQL);
            $data_overwrite = true;
        } else {
              return false;
        }
    } else {
          $data_overwrite = false;
    }
    return true;
}
/** import_slice function
 * @param $slice (by link)
 */
// imports one slice (called by XML parser)
function import_slice(&$slice){
    global $db,
           $IDconflict,
           $conflicts_ID,
           $Cancel,
           $imported_list,
           $overwritten_list,
           $overwrite,
           $only_slice,
           $new_slice_ids;

    if ($only_slice) { // import slice definition ?
        $IDconflict = !proove_ID($slice);
        if (($IDconflict)&&($GLOBALS["Submit"]!=_m("Insert with new ids"))) {
            $conflicts_ID[$slice["id"]] = $slice["name"];
            $Cancel = 0;
            return false;
        }
        if ($GLOBALS["Submit"] == _m("Insert with new ids")) {
          $slice["id"]=$new_slice_ids[$slice["id"]]["new_id"];
        }
        // inserting to table slice
        $sqltext = create_SQL_insert_statement ($slice, "slice", ";id;owner;","","")."\n";
//		exit;
        $db->query($sqltext);
        // inserting to table module
        $sqltext = create_SQL_insert_statement ($slice, "module", ";id;owner;", ";id;name;deleted;slice_url;lang_file;created_at;created_by;owner;flag;","type=S")."\n";
        $db->query($sqltext);
        $fields = $slice["fields"];
        reset($fields);
        while (list(,$curf) = each($fields)) {
            $curf["slice_id"] = $slice["id"];
            // inserting to table fields
            $sqltext = create_SQL_insert_statement ($curf, "field", ";slice_id;","","")."\n";
            $db->query($sqltext);
        }
        if ($overwrite) {
            $overwritten_list[] = $slice["name"]." (id:".$slice["id"].")";
        } else {
            $imported_list[] = $slice["name"]." (id:".$slice["id"].")";
        }
        $Cancel = "OHYES";
    }
}

/** import_slice_data function
 * @param $slice_id
 * @param $id
 * @param $content4id
 * @param $insert
 * @param $feed
 */
 // returns false on failure, but ignored
function import_slice_data($slice_id, $id, $content4id, $insert, $feed) {
    global $data_IDconflict,
           $data_conflicts_ID,
           $Cancel,
           $data_imported_list,
           $data_overwritten_list,
           $data_import_failure,
           $data_overwrite,
           $only_data,
           $new_slice_ids;
    if ($only_data) { // import slice items ?
        $slice = AA_Slice::getModule($slice_id);
        if (!$slice OR !count($slice->fields('pri')))
            $data_import_failure[] = $id." No fields for slice_id=$slice_id";
            return false;
        }
        $cont            = $content4id[$id];
        $data_IDconflict = !proove_data_ID($id);
        if (($data_IDconflict)&&($GLOBALS["Submit"]!=_m("Insert with new ids"))) {
            $data_conflicts_ID[$id] = $cont["headline........"][0]['value'];
            $Cancel = 0;
            return false;
        }

        if ($GLOBALS["Submit"] == _m("Insert with new ids")) {
          // when importing with new ids, we need create new id for item
          // and get new id of slice
        // This looks like a bug to me, won't use new id if Overwrite (mitra)
          $new_data_id  = new_id();
          $new_slice_id = $new_slice_ids[$slice_id]["new_id"];
          $slice_id     = $new_slice_id;
          $id           = $new_data_id;
        }

        if ( StoreItem($id, $slice_id, $cont, $insert, true, $feed)) {
            if ($data_overwrite) {
                $data_overwritten_list[] = $id." (id:".$id.")";
            } else {
                $data_imported_list[] = $id." (id:".$id.")";
            }
        } else {
            $data_import_failure[] = $id." StoreItem failed";
        }
        $Cancel = "OHYES";
    }
}

if ($Cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

$IDconflict = false;
$slice_def_bck  = $slice_def = stripslashes($slice_def);
$imported_count = 0;

// insert xml parser
require_once AA_BASE_PATH."admin/sliceimp_xml.php3";

// import via exported file
if (is_uploaded_file($_FILES['slice_def_file']['tmp_name'])) {
    $dirname = IMG_UPLOAD_PATH;
    $fileman_used=false;
    $dest_file = $_FILES['slice_def_file']['name'];
    $perms = 0664;

  // i must copy this from aa_move_uploaded_file because of some variables,that i can't (don't know how) set

    if (is_uploaded_file($_FILES['slice_def_file']['tmp_name'])) {
        if ( !move_uploaded_file($_FILES['slice_def_file']['tmp_name'], "$dirname$dest_file")) {
            echo _m("Can't upload Import file") . "to $dirname$dest_file";
        } else if ($perms) {
            chmod ($dirname.$dest_file, $perms);
        }
    }
    $fd            = fopen ($dirname.$dest_file, "r");
    $slice_def_bck = $slice_def = fread ($fd, filesize ($dirname.$dest_file));
    fclose ($fd);

    unlink($dirname.$dest_file); // delete file...
}

if ($conflicts_list) {
    $temp = explode("\n",$conflicts_list);
    foreach ($temp as $line) {
        list(,$line)    = explode(":",$line);
        list($old,$new) = explode("->",$line);
        $resolve_conflicts[trim($old)] = trim($new);
    }
}

if ($view_conflicts_list) {
    $temp = explode("\n",$view_conflicts_list);
    foreach ($temp as $line) {
        list(,$line)    = explode(":",$line);
        list($old,$new) = explode("->",$line);
        $view_resolve_conflicts[trim($old)] = trim($new);
    }
}

if ($data_conflicts_list) {
    $temp = explode("\n",$data_conflicts_list);
    foreach ($temp as $line) {
        list(,$line)    = explode(":",$line);
        list($old,$new) = explode("->",$line);
        $data_resolve_conflicts[trim($old)] = trim($new);
    }
}

if ($slice_def != "") {
    $err = sliceimp_xml_parse($slice_def,false,$force_this_slice);
    if ($err != "") {
        si_err($err);
    }
}

HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
?>
<title><?php echo _m("Import exported data (slice structure and content)")?></title>

</head>

<?php
  require_once menu_include();   //show navigation column depending on $show
  showMenu ($aamenus, "aaadmin","sliceimp");
?>
<?php echo $pom ?>
<form name="formular" method="post" action="<?php echo $sess->url("sliceimp.php3") ?>"
enctype="multipart/form-data">

<h1><b><?php echo _m("Import exported data (slice structure and content)").$pom?></b></h1>
<?php
  FrmTabCaption( _m("Import exported data"));

echo "<tr><td class=\"tabtxt\">";

if ($Cancel || $conflicts_list || $view_conflicts_list || $data_conflicts_list || $data_import_failure) {
    echo "<b>".sprintf(_m("Count of imported slices: %d."),count($imported_list)+count($overwritten_list))."</p>";
    if (is_array($imported_list)) {
        echo "</p>"._m("Added were:")."</p>";
        reset($imported_list);
        while (list(,$desc)=each($imported_list)) {
            echo $desc."<br>";
        }
    }
    if (is_array($overwritten_list)) {
        echo "</p>"._m("Overwritten were:")."</p>";
        reset($overwritten_list);
        while (list(,$desc)=each($overwritten_list)) {
            echo $desc."<br>";
        }
    }

    echo "<br><br><b>".sprintf(_m("Count of imported stories: %d."),count($data_imported_list)+count($data_overwritten_list))."</p>";
    echo $data_showme;
    if (is_array($data_imported_list)) {
        echo "</p>"._m("Added were:")."</p>";
        reset($data_imported_list);
        while (list(,$desc)=each($data_imported_list)) {
            echo $desc."<br>";
        }
    }
    if (is_array($data_overwritten_list)) {
        echo "</p>"._m("Overwritten were:")."</p>";
        reset($data_overwritten_list);
        while (list(,$desc)=each($data_overwritten_list)) {
            echo $desc."<br>";
        }
    }
    if (is_array($data_import_failure)) {
        echo "</p>"._m("Failed were:")."</p>";
        reset($data_import_failure);
        while (list(,$desc)=each($data_import_failure)) {
            echo $desc."<br>";
        }
    }

    ?>
    </p>
    <input type="submit" name="Cancel" value="  OK  ">
<?php
} else {
?>

<?php echo _m("Here you can import exported data to toolkit. You can use two types of import:") ?></p>
</td></tr>
<?php
if ($IDconflict) {?>
    <tr><td class="tabtxt">
<b><?php echo _m("Slices with some of the IDs exist already. Change the IDs on the right side of the arrow.<br> Use only hexadecimal characters 0-9,a-f. If you do something wrong (wrong characters count, wrong characters, or if you change the ID on the arrow's left side), that ID will be considered unchanged.</p>") ?></b></p>
    <p align="center">
<textarea name="conflicts_list" rows=<?php echo count($conflicts_ID) ?> cols="90">
<?php
    reset($conflicts_ID);
    while (list($c_id,$name)=each($conflicts_ID)) {
        echo $name.":\t".$c_id." -> ".$c_id."\n";
    }
?>
</textarea>

<?php
}
if ($view_IDconflict) { ?>
<tr><td class="tabtxt">
<b><?php echo sprintf (_m("<p>Views with some of the same IDs exist already. Please edit on the right hands side of the arrow</p>")) ?></b></p>
<p align="center">
<textarea name="view_conflicts_list" rows=<?php echo count($view_conflicts_ID) ?> cols="90">
<?php
    reset($view_conflicts_ID);
    while (list($c_id,$name)=each($view_conflicts_ID)) {
        echo $name.":\t".$c_id." -> ".$c_id."\n";
    }
?>
</textarea>
<?php
}
if ($data_IDconflict) { ?>

<tr><td class="tabtxt">
<b><?php echo sprintf (_m("<p>Slice content with some of the IDs exist already. Change the IDs on the right side of the arrow.<br> Use only hexadecimal characters 0-9,a-f. </p>")) ?></b></p>
<p align="center">
<textarea name="data_conflicts_list" rows=<?php echo count($data_conflicts_ID) ?> cols="90">
<?php
    reset($data_conflicts_ID);
    while (list($c_id,$name)=each($data_conflicts_ID)) {
        echo substr($name,0,27).":\t".$c_id." -> ".$c_id."\n";
    }
?>
</textarea>
<?php
}
if ($IDconflict || $data_IDconflict) { ?>
    </p>
<?php	echo _m("<p>If you choose OVERWRITE, the slices and data with unchanged ID will be overwritten and the new ones added. <br>If you choose INSERT, the slices and data with ID conflict will be ignored and the new ones added.<br>And finally, if you choose \"Insert with new ids\", slice structures gets new ids and it's content too.</p>") ?>
    <p align="center">
<?php if ($only_slice)	 {?>
    <input type="hidden" name="only_slice" value="1">
<?php };
    if ($only_data) { ?>
    <input type="hidden" name="only_data" value="1">
<?php }; ?>
    <input type="submit" name="Submit" VALUE="<?php echo _m("Overwrite") ?>">
    <input type="submit" name="Submit" VALUE="<?php echo _m("Insert") ?>">
    <input type="submit" name="Submit" VALUE="<?php echo _m("Insert with new ids") ?>">
    <input type="submit" name="Cancel" VALUE="<?php echo _m("Cancel") ?>">
    </p>
    </td></tr>
<?php
}?>
<?php if (!$IDconflict || !$data_IDconflict) { ?>
<tr><td class="tabtxt" align="center">
<br>
        <?php echo _m("1) If you have exported data in file, insert it's name here (eg. D:\data\apc_aa_slice.aaxml):") ?><p>
        <input type="file" name="slice_def_file" size="60">
<!--		<p><input type="submit" name="file_submit" value="<?php echo _m("Send file with slice structure and data"); ?>">  -->
    </td></tr>
    <?php } ?>
<tr><td class="tabtxt" align="center">
<br>
<?php if (!$IDconflict || !$data_IDconflict) { ?>
        <?php echo _m("2) If you have exported data in browser's window, insert the exported text into the textarea below:") ?><p>
    <?php }
?>
    <textarea name="slice_def" rows="10" cols="100"><?php if ($IDconflict || $data_IDconflict)
// Be careful here, $slice_def_bck contains structures line
// <xxx>&lt;BR&gt;some content</xxx>
// Which the browser will convert to <xxx><BR>some content</xxx>
// which is invalid XML. So, convert & to &amp; first, so pass through
// htmlspecialchars, which browser will undo.
echo myspecialchars($slice_def_bck) ?></textarea>
    <p>
    <?php if (!$IDconflict || !$data_IDconflict) { ?>
<?php if (!$GLOBALS["Submit"]) { ?>
    <?php echo _m("Here specify, what do you want to import:"); ?><p>
    <input type="checkbox" name="only_slice" checked><?php echo _m("Import slice definition") ?><br>
    <input type="checkbox" name="only_data" checked><?php echo _m("Import slice items") ?><br><br>
    <input type="checkbox" name="force_this_slice"><?php echo _m("Import into this slice - whatever file says") ?><br><br>
<?php
    FrmTabEnd(array("Submit"=>array("value"=>_m("Send the slice structure and data"), "accesskey"=>"S", "type"=>"submit"),
              "Cancel"=>array("value"=>_m("Cancel"), "type"=>"submit")), $sess, $slice_id);
 } ?>
<?php
}
} //if ($cancel || $coflicts_list)?>
</form>
</tr></td>
</table>

<?php
/* Testing ....
*/
HtmlPageEnd();
page_close();
?>
