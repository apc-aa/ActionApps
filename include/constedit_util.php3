<?php
/**
 * The "util" create a string $consts, which contains the JavaScript array
 *    with all constants, prepare some JavaScript constants and call the constedit.js
 *    JavaScript.
 *
 *    Function "showHierConstBoxes" paints a table with level boxes.
 *    Function "showHierConstInitJavaScript (group_id)" prints JavaScript definitions needed for the editor
 *    Function "hcUpdate" deletes and updates all things in Admin panel
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
 * @author    Jakub Adámek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

$hcCol = array("Name"  => 0,
               "Value" => 1,
               "Prior" => 2,
               "Desc"  => 3,
               "ID"    => 4,
               "Dirty" => 5,
               "Child" => 6       // always leave colChild as the last one
              );

/**  getHierConstInitJavaScript function
Params:
 * @param  $hcid     - hc identifier
 * @param  $group_id - name of constant group
 * @param  $levelCount = 3 - count of level boxes
 * @param  $formName = 'f' - from <form name="formName"></form>
 * @param  $admin = true - if true, send all info (for constants admin)
*/
function getHierConstInitJavaScript($hcid, $group_id, $levelCount=3, $formName='f', $admin=true) {
    global $hcCol;
    $js = "
        hcEasyDelete[$hcid] = 0;     // if set to 1, doesn't uncheck the confirmation check box
        // consts columns: name, value, priority, description, ID, dirty flag, children
        colName  = ".$hcCol['Name'] .";
        colValue = ".$hcCol['Value'].";
        colPrior = ".$hcCol['Prior'].";
        colDesc  = ".$hcCol['Desc'] .";
        colID    = ".$hcCol['ID']   .";
        colDirty = ".$hcCol['Dirty'].";
        colChild = ".($admin ? $hcCol['Child'] : $hcCol['Prior']).";

        // count of levels in hierarchy
        hcLevelCount[$hcid] = $levelCount;

        // name of form in which are the fields
        hcForm = '$formName';";

        // this will be supplied by the database
    $js.= "
        hcConsts[$hcid] = ".createConstsJavaScript ($group_id, $admin).";";
    $out  = getFrmJavascript($js);
    return $out ;
}

/** getHierConstBoxes function
 *     paints horizontally or vertically the level boxes in a table. Params:
 *  @param $hcid
 *  @param  $levelCount - count of boxes
 *  @param  $horizontal = 0 - should be the boxes placed horizontal?
 *  @param  $targetBox = "" - where to put selected values (usefull by admin=false)
 *  @param  $admin = true - are the boxes in admin interface?
 *           if yes, buttons are "Add new" and "Select",
 *           else, buttons are "Select" - moves to the targetBox
 *  @param  $minLevelSelect = 0 - from which level should be the "Select" button shown
 *  @param  $boxwidth = 0
 *  @param  $levelNames = array() - names for the level boxes (if you don't like Level 0, Level 1, etc.)
*/
function getHierConstBoxes($hcid, $levelCount, $horizontal=0, $targetBox="", $admin=true,
    $minLevelSelect=0, $boxWidth=0, $levelNames=array()) {

    $admin = $admin ? 1 : 0;
    if ($boxWidth == 0) $boxWidth = $horizontal ? 30 : 70;

    /* OFMG
       20060421
       When you add a value in a hierarchicalConstant
       it does not respond to the onChange triger on the mainbox
    */
    $this_triggers = getTriggers("select",$targetBox);
    $aa_onchange_exist = strstr($this_triggers,'aa_onChange(');
    $add_button_trigger = "";
    if( $aa_onchange_exist ){
       list($aux1,$fieldid,$aux2) = split("'",$aa_onchange_exist,3);
       $add_button_trigger = "aa_onChange('".$fieldid."'); ";
    }

    $out = "<table border=\"0\" cellpadding=\"3\">";
    if ($horizontal) $out .= "<tr>";
    $widthTxt = str_repeat("m",$boxWidth);

    for ($i=0; $i < $levelCount; ++$i) {
        if ($admin) {
            $buttonAdd = "<input type=\"button\" value=\""._m("Add new")."\" onClick=\"hcAddNew($hcid,$i)\">";
            $buttonSelect = "<input type=\"button\" value=\""._m("Select")."\" onClick=\"hcSelectItem($hcid,$i,1)\">";
        }
        else {
            $buttonAdd = "";
            if ($minLevelSelect > $i) $buttonSelect = "";
            else $buttonSelect = "<input type=\"button\" value=\""._m("Select")."\" onClick=\"hcAddItemTo($hcid,$i,'$targetBox'); ".$add_button_trigger."\">";
        }
        if (!$levelNames[$i]) $levelNames[$i] = _m("Level")." $i";
        if ($horizontal) {
            $out .= "
              <td align=\"left\" valign=\"top\" width=\"10%\"><b>".$levelNames[$i]."</b><br>
              <select name=\"hclevel{$i}_{$hcid}\" multiple size=\"10\" onChange=\"hcSelectItem($hcid,$i,$admin)\">
                <option>$widthTxt</select>
                <br><br>$buttonAdd&nbsp;&nbsp;$buttonSelect
              </td>";
        } else {
            $out .= "
              <tr><td align=\"right\" valign=\"top\">
                <b>".$levelNames[$i]."</b><br>$buttonAdd<br>
                <img src=\"../images/spacer.gif\" width=\"1\" height=\"2\"><br>$buttonSelect
                </td><td>
                <select name=\"hclevel{$i}_{$hcid}\" multiple size=\"4\" onChange=\"hcSelectItem($hcid,$i,$admin)\">
                <option>$widthTxt</select>
              </td></tr>";
        }
    }

    if ($horizontal) $out .= "</tr>";
    $out .= "</table>";
    return $out;
}
/** showHierConstInitJavaScript function
 *
 */
function showHierConstInitJavaScript($hcid, $group_id, $levelCount=3, $formName='f', $admin=true) {
    echo getHierConstInitJavaScript($hcid, $group_id, $levelCount, $formName, $admin);
}
/** showHierConstBoxes function
 *
 */
function showHierConstBoxes($hcid, $levelCount, $horizontal=0, $targetBox="", $admin=true, $minLevelSelect=0, $boxWidth=0, $levelNames=array()) {
    echo getHierConstBoxes($hcid, $levelCount, $horizontal, $targetBox, $admin, $minLevelSelect, $boxWidth, $levelNames);
}

/** createConstsJavaScript function
 *  creates string forming JavaScript array definition
 *
 *   @param $group_id - name of constants group
 *   @param $admin - admin pages
*/
function createConstsJavaScript($group_id, $admin)
{
    createConstsArray($group_id, $admin, $myconsts);
    eval('$data = '.$myconsts.';');
    hcSortArray($data);

    $consts = "new Array(";
    for ( $i=0, $ino=count($data); $i<$ino; ++$i) {
        if ($i) $consts .= ",";
        $consts .= printConstsArray($data[$i], $admin);
    }
    $consts .= ")";
    return $consts;
}

/** createConstsArray function
 *  creates string forming PHP array definition
 *  @param $group_id
 *  @param $admin
 *  @param $consts (by link)
 */
function createConstsArray($group_id, $admin, &$consts)
{
    global $get_method;
    $dbc = getDB();

    $data = array();
    $dbc->query("SELECT * FROM constant WHERE group_id = '$group_id'");
    while ($dbc->next_record()) {
        // & in from _GET is taken as parameter delimiter, so we replace it
        // with %26. We do not replace it, if it is called from editing form
        $value = ( $get_method ? str_replace ("&","%26",ff($dbc->f("value"))) :
                                                        ff($dbc->f("value")) );

        if (ff($dbc->f("name")) == $value) {
            $value = '#';
        }
        $data[$dbc->f("ancestors").$dbc->f("id")] = "'"
                .ff($dbc->f("name"))."','"
                .$value."',"
                .ff($dbc->f("pri")).",'"
                .($admin ? ff($dbc->f("description")) : "")."',"
                .ff($dbc->f("short_id")).","
                ."false";
    }

    freeDB(	$dbc );
    ksort($data);

    $path       = "";
    $consts     = "array(";
    $error_data = array();
    $ok_data    = 0;
    $depth      = 0;
    foreach ( $data as $ancestors => $col) {
        $error = false;
        if (!$path) {
            if (strlen($ancestors) != 16) {
                $error = true;
            } else {
                $consts .= "array($col";
            }
        } elseif (strlen($ancestors) % 16 != 0) {
            $error = true;
        // step over one layer
        } elseif (strlen($ancestors) > strlen($path)
            && substr ($ancestors,0,strlen($path)) == $path) {
            if (strlen($ancestors)-strlen($path) == 16) {
                $consts .= ",array(array($col";
                $depth++;
            } else {
                $error = true; // error: missing layer, jump over
            }
        } elseif (strlen($ancestors) == strlen($path)) {
            if (substr($ancestors,0,strlen($path)-16) != substr($path,0,strlen($path)-16)) {
                $error = true;
            } else {
                $consts .= "),array($col";
            }
        } else {
            $consts .= ")";
            $level=0;
            while (substr($ancestors,0,$level*16) == substr($path,0,$level*16)) {
                ++$level;
            }
            for ($i = 0, $ino = strlen($path); $i < $ino / 16 - $level && $depth > 0; ++$i) {
                $consts .= "))";
                $depth --;
            }
            $consts .= ",array($col";
        }
        if ($error) {
            $error_data[] = $col;
        } else {
            $path = $ancestors;
            ++$ok_data;
        }
    }
    if ($ok_data) {
        $consts .= ")";
    }
    for ($i = 0; $i < $depth; ++$i) {
        $consts .= "))";
    }
    if (count($error_data)) {
        if ($ok_data) $consts .= ",";
        foreach ( $error_data as $col) {
            $consts .= "array($col),";
        }
        $consts = substr ($consts,0,strlen($consts)-1);
    }

    $consts .= ")";
}
/** hcCompareConstants function
 * @param $a
 * @param $b
 */
function hcCompareConstants($a, $b) {
    global $hcCol;
    if ($a[$hcCol["Prior"]] > $b[$hcCol["Prior"]]) {
        return 1;
    } elseif ($a[$hcCol["Prior"]] < $b[$hcCol["Prior"]]) {
        return -1;
    }
    return $a[$hcCol["Name"]] > $b[$hcCol["Name"]];
}
/** hcSortArray function
 * @param $arr (by link)
 */
function hcSortArray(&$arr) {
    global $hcCol;
    usort($arr, "hcCompareConstants");
    for ( $i=0, $ino=count($arr); $i<$ino; ++$i) {
        if (count ($arr[$i]) > $hcCol["Child"]) {
            hcSortArray($arr[$i][$hcCol["Child"]]);
        }
    }
}
/** ff function
 * @param $str
 */
function ff($str) {
    return str_replace(array("\r","\n","'"), array('','',"\\'"), $str);
}

/** printConstsArray function
 * @param $arr (by link)
 * @param $admin
 */
function printConstsArray(&$arr, $admin) {
    global $hcCol;
    $value = ff($arr[$hcCol["Value"]]);
    if (ff($arr[$hcCol["Name"]]) == $value) {
        $value = '#';
    }
    $retval = "new Array('"
            .ff($arr[$hcCol["Name"]])."','"
            .$value."'".
            ($admin ? ","
            .ff($arr[$hcCol["Prior"]]).",'"
            .ff($arr[$hcCol["Desc"]])."',"
            .ff($arr[$hcCol["ID"]]).","
            ."false"
            : "");
    if (count ($arr) > $hcCol["Child"]) {
        $retval .= ",new Array(";
        for ( $i=0, $ino=count($arr[$hcCol["Child"]]); $i<$ino; ++$i) {
            if ($i) {
                $retval .= ",";
            }
            $retval .= printConstsArray ($arr[$hcCol["Child"]][$i], $admin);
        }
        $retval .= ")";
    }
    $retval .= ")";
    return $retval;
}
/** hcUpdate function
 *
 */
function hcUpdate()
{
    global $levelCount, $hide_value, $levelsHorizontal, $group_id, $p_slice_id;
    $db = getDB();

    $db->query("SELECT * FROM constant_slice WHERE group_id = '$group_id'");
    if ($levelCount) {
        if ($db->next_record()) {
            $db->query(
                "UPDATE constant_slice SET levelcount=$levelCount,
                horizontal=".($levelsHorizontal ? 1 : 0).",
                hidevalue=".($hide_value ? 1 : 0)."
                WHERE group_id='$group_id'");
        } else {
            $db->query(
                "INSERT INTO constant_slice (group_id,slice_id,horizontal,hidevalue,levelcount)
                VALUES ('$group_id','$p_slice_id',".($levelsHorizontal ? 1 : 0)
                .",".($hide_value ? 1 : 0).",".$levelCount.")");
        }
    } else {
        $hide_value = 0;
        $levelCount = 2;
        $levelsHorizontal = 0;
        if ($db->next_record()) {
            $hide_value = $db->f("hidevalue");
            $levelCount = $db->f("levelcount");
            $levelsHorizontal = $db->f("horizontal");
        }
    }

    global $hcalldata, $varset;
    if ($hcalldata) {
        if (get_magic_quotes_gpc()) {
            $hcalldata = stripslashes($hcalldata);
        }
        $hcalldata = str_replace("\\'","'",$hcalldata);
        $hcalldata = str_replace("\\:", "--$--", $hcalldata);
        $hcalldata = str_replace("\\~", "--$$--", $hcalldata);
        $chtag = ":changes:";
        if (strstr ($hcalldata, "$chtag")) {
            $changes   = substr ($hcalldata, strpos ($hcalldata,$chtag) + strlen($chtag) + 1);
            $hcalldata = substr ($hcalldata, 0, strpos ($hcalldata,$chtag) - 1);
            $chs       = explode(":", $changes);
            $changes   = array();
            foreach ($chs as $ch) {
                if (!strchr($ch,"~")) {
                    continue;
                }
                $ar = explode("~",$ch);
                for ( $i=0, $ino=count($ar); $i<$ino; ++$i) {
                    $ar[$i] = str_replace ("--$$--","~",str_replace("--$--",":",$ar[$i]));
                }
                $changes[] = $ar;
            }
        }
    }

    // delete items
    if ($hcalldata > "0") {
        $db->query("DELETE FROM constant WHERE short_id IN ($hcalldata)");
    }

    // update items

    if (is_array($changes)) {
        $db->query("SELECT id, short_id FROM constant;");
        while ($db->next_record())
            $shortIDmap [$db->f("short_id")] = addslashes($db->f("id"));

        $db->query("SELECT propagate FROM constant_slice WHERE group_id='$group_id'");
        if ($db->next_record()) {
            $propagate_changes = $db->f("propagate");
        } else {
            $propagate_changes = false;
        }

        reset ($changes);
        while (list (,$change) = each($changes)) {
            $column_id = 4;
            $column_ancestors = 5;
            for ($i = 0; $i < $column_id; ++$i) {
                $change[$i] = str_replace ("'","\\'",$change[$i]);
            }
            $varset->clear();
            $varset->set("name",  $change[0], "quoted");
            $varset->set("value", $change[1], "quoted");
            $varset->set("pri", ( $change[2] ? $change[2] : 1000), "number");
            $varset->set("description", $change[3], "quoted");

            $newvalue = $change[1];
            $new_id = $change[$column_id];
            if (substr($new_id,0,1) == "#") {
                $id = q_pack_id (new_id());
                $shortIDmap[$new_id] = $id;
                $ancestors = "";
                $path = explode(",",$change[$column_ancestors]);
                reset ($path);
                while (list (,$myid) = each ($path)) {
                    if (!$myid) {
                        continue;
                    }
                    $ancestors .= $shortIDmap[$myid];
                }
                $varset->set("id",$id,"quoted");
                $varset->set("group_id",$group_id,"quoted");
                $varset->set("ancestors",$ancestors,"quoted");
                $db->query("INSERT INTO constant ".$varset->makeINSERT());
            }
            else {
                if ($propagate_changes) {
                    $db->tquery("SELECT id, value FROM constant WHERE short_id=$new_id");
                    if ($db->next_record()) {
                        propagateChanges(unpack_id($db->f("id")), $newvalue, addslashes($db->f("value")));
                    }
                }
                $db->query("UPDATE constant SET ". $varset->makeUPDATE() ." WHERE short_id = ".$new_id);
            }
        }
    }
    freeDB($db);
}

// Copy and rename constant groups in slice $slice_id so that they are not shared with other slices
// WARNING: doesn't work when the group id contains a ":" ??
// find new group_id by trying to add "_1", "_2", "_3", ... to the old one
/** CopyConstants function
 * @param $slice_id
 */
function CopyConstants($slice_id)
{
    global $err, $debug;
    $db = getDB();

    // max. length of the group_id field
    $max_group_id_len = 16;
    $q_slice_id       = q_pack_id($slice_id);

    $db->query("SELECT name FROM constant WHERE group_id='lt_groupNames'");
    while ($db->next_record()) {
        $group_list[] = $db->f("name");
    }
    $db->query("SELECT id, input_show_func FROM field WHERE slice_id ='$q_slice_id'");
    while ($db->next_record()) {
        $shf = $db->f("input_show_func");
        if (strlen ($shf) > 4) {
            list(,$group_id) = explode(":",$shf);
            if (in_array($group_id, $group_list)) {
                $group_ids[$group_id][$db->f("id")] = $shf;
            }
        }
    }
    if (!is_array($group_ids)) {
        freeDB($db);
        return true;
    }

    foreach ($group_ids as $old_id => $fields) {

        // find new id by trying to add "_1", "_2", "_3", ... to the old one
        $new_id = $old_id;
        for ($i = 1; in_array($new_id, $group_list); ++$i) {
            $postfix = "_".$i;
            $new_id = substr ($old_id,0, min (strlen($old_id)+strlen($postfix), $max_group_id_len) - strlen($postfix)) . $postfix;
        }
        $group_list[] = $new_id;

        if ($debug) {
            echo "Changing $old_id to $new_id.<br>";
        }

        // copy group name in table constant
        if (!CopyTableRows(
            "constant",
            "group_id='lt_groupNames' AND name='$old_id'",
            array ("name"=>$new_id,"value"=>$new_id), // set_columns
            array ("short_id"),                       // omit_columns
            array ("id")                              // id_columns
            )) {
            $err[] = "Could not copy constant group.";
            freeDB($db);
            return false;
        }

        // copy group values in table constant
        if (!CopyTableRows(
            "constant",
            "group_id='$old_id'",
            array ("group_id"=>$new_id),              // set_columns
            array ("short_id"),                       // omit_columns
            array ("id")                              // id_columns
            )) {
            $err[] = "Could not copy constant group.";
            freeDB($db);
           return false;
        }

        // update fields
        foreach ( $fields as $field_id => $shf) {
            if (!$db->query("UPDATE field SET input_show_func = '"
                .addslashes(str_replace ($old_id, $new_id, $shf))."'
                WHERE id='$field_id' AND slice_id='$q_slice_id'")) {
                $err[] = "Could not update fields.";
                freeDB($db);
                return false;
            }
        }
    }

    return true;
}
/** get_unique_group_id function
 *   Looks into database if the group already exists. If yes, it returns modified,
 *   but unique group name
 *
 * @param $group_id
 */
function get_unique_group_id($group_id) {
    $db = getDB();

    $group_id = str_replace(':','-',$group_id);  // we don't need ':'

    $db->query("SELECT value FROM constant WHERE group_id = 'lt_groupNames'
                AND value LIKE '".quote($group_id)."%'");
    while ($db->next_record()) {
        $gnames[$db->f("value")] = 1;
    }
    $unique_name = $group_id;
    if (is_array($gnames)) {
        $i = 1;
        while ($gnames[$unique_name]) {
            $unique_name = $group_id." ".($i++);
        }
    }
    freeDB($db);
    return $unique_name;
}


// -------------------------------------------------------------------
/** add_constant_group function
*    Adds a new constant group. Sets priority increasingly in the same
*   order as are the constants in @c $items.
*
*   @author Jakub Adamek, Econnect, January 2003
*   @param string $group_id Desired group name, may be changed on conflicts
*                           and $unique is set
*   @param array $constants2import array('name'=>.., 'value'=>.., 'pri'=>.., 'group'=>..)
*   @return true on succes or error string if it fails
*/
function add_constant_group($group_id, $constants2import)
{
    $db = getDB();

    if ( strlen($group_id) < 1 ) {
        return _m("No group id specified");
    }

    $SQL = "SELECT * FROM constant WHERE group_id = '$group_id'";
    $db->tquery($SQL);
    if ($db->next_record()) {
        return _m("This constant group already exists");
    }

    if (!is_array($constants2import) OR (count($constants2import) < 1)) {
        return _m('No constants specified');
    }

    // set in seconds - allows the script to work so long
    set_time_limit(600);

    $varset = new CVarset;
    $varset->add("group_id", "text",     "lt_groupNames");
    $varset->add("value",    "text",     $group_id);
    $varset->add("name",     "text",     $group_id);
    $varset->add("id",       "unpacked", new_id());
    $varset->doINSERT("constant");

    $priority_step = 10;
    $priority      = 0;
    foreach ($constants2import as $constant) {
        $priority = isset($constant['pri']) ? $constant['pri'] : $priority + $priority_step;
        $varset->clear();
        $varset->add("value",    "text",     $constant['value']);
        $varset->add("name",     "text",     $constant['name']);
        $varset->add("pri",      "number",   $priority);
        $varset->add("class",    "text",     $constant['class']);
        $varset->add("id",       "unpacked", new_id());
        $varset->add("group_id", "text",     $group_id);
        $varset->doINSERT("constant");
    }
    freeDB($db);
    return true;
}

// -------------------------------------------------------------------

/** delete_constant_group function
*   Deletes a constant group. If $slice_id is provided, the group
*   is deleted only if it is used only in the given slice.
*
*   @author Jakub Adamek, Econnect, January 2003
*   @param $group_id
*   @param string $slice_id  unpacked slice ID
*   @return bool  @c true if group was deleted, @c false otherwise	*/
function delete_constant_group($group_id, $slice_id = "") {
    $db = getDB();

    $delete = true;
    if ($slice_id) {
        $db->query("
            SELECT slice.id FROM slice INNER JOIN field ON slice.id = field.slice_id
            WHERE field.input_show_func LIKE '%$group_id%'
            AND slice.id <> '".q_pack_id($slice_id)."'");
        if ($db->next_record()) {
            $delete = false;
        }
    }
    if ($delete) {
        // delete group name and constants
        $db->query ("
            DELETE FROM constant
            WHERE (group_id='lt_groupNames' AND value='$group_id')
                  OR group_id='$group_id'");
        $delete = $db->affected_rows();
    }
    freeDB($db);
    return $delete;
}

// -------------------------------------------------------------------

/** refresh_constant_group function
*   Refreshes a constant group: replaces old members with the new.
*   If the group does not exist, it is created.
*   @param $group_id
*   @param $items
*   @return bool true if the group existed
*/
function refresh_constant_group($group_id, $items) {
    $db = getDB();

    $varset = new CVarset;

    $db->query ("SELECT * FROM constant WHERE group_id='lt_groupNames'
        AND name='$group_id'");
    if (!$db->next_record()) {
        $existed = false;
        $varset->add ("group_id", "text", "lt_groupNames");
        $varset->add ("value","text",$group_id);
        $varset->add ("name","text",$group_id);
        $varset->add ("id", "unpacked", new_id());
        $db->query ($varset->makeINSERT ("constant"));
    }
    else {
        $existed = true;
        $db->query ("DELETE FROM constant WHERE group_id='$group_id'");
    }

    $priority = 100;
    if (is_array($items)) {
        reset ($items);
        while (list ($value, $name) = each ($items)) {
            $varset->clear();
            $varset->add ("value", "text", $value);
            $varset->add ("name", "text", $name);
            $varset->add ("pri", "number", $priority);
            $priority += 100;
            $varset->add ("id", "unpacked", new_id());
            $varset->add ("group_id", "text", $group_id);
            $db->query ($varset->makeINSERT ("constant"));
        }
    }
    freeDB($db);
    return $existed;
}
?>
