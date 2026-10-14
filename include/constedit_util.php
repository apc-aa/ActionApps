<?php

/**
 * The "util" create a string $consts, which contains the JavaScript array
 *    with all constants, prepare some JavaScript constants and call the constedit.min.js
 *    JavaScript.
 *
 *    Function "showHierConstBoxes" paints a table with level boxes.
 *    Function "showHierConstInitJavaScript (group_id)" prints JavaScript definitions needed for the editor
 *    Function "hcUpdate" deletes and updates all things in Admin panel
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
 * @author    Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

use AA\IO\DB\DB_AA;
use AA\Util\JsTriggers;

$hcCol = [
    "Name"  => 0,
    "Value" => 1,
    "Prior" => 2,
    "Desc"  => 3,
    "ID"    => 4,
    "Dirty" => 5,
    "Child" => 6,       // always leave colChild as the last one
];

/**  getHierConstInitJavaScript function
 * Params:
 * @param  $hcid - hc identifier
 * @param  $group_id - name of constant group
 * @param  $levelCount = 3 - count of level boxes
 * @param  $formName = 'f' - from <form name="formName"></form>
 * @param  $admin = true - if true, send all info (for constants admin)
 * @return string
 */
function getHierConstInitJavaScript($hcid, $group_id, $levelCount = 3, $formName = 'f', $admin = true): string
{
    global $hcCol;
    $hcid       = (int)$hcid;
    $levelCount = (int)$levelCount;
    $js = "
        hcEasyDelete[$hcid] = 0;     // if set to 1, doesn't uncheck the confirmation check box
        // consts columns: name, value, priority, description, ID, dirty flag, children
        colName  = " . $hcCol['Name'] . ";
        colValue = " . $hcCol['Value'] . ";
        colPrior = " . $hcCol['Prior'] . ";
        colDesc  = " . $hcCol['Desc'] . ";
        colID    = " . $hcCol['ID'] . ";
        colDirty = " . $hcCol['Dirty'] . ";
        colChild = " . ($admin ? $hcCol['Child'] : $hcCol['Prior']) . ";

        // count of levels in hierarchy
        hcLevelCount[$hcid] = $levelCount;

        // name of form in which are the fields
        hcForm = " . hcJsString((string)$formName) . ";";

    // this will be supplied by the database
    $js .= "
        hcConsts[$hcid] = " . createConstsJavaScript($group_id, $admin) . ";";
    return getFrmJavascript($js);
}

function getHierConstStart(bool $horizontal): string
{
    return  $horizontal ? '<div style="display: flex; align-items: flex-start;">' : '<div style="display: flex; align-items: flex-start; flex-direction: column;">';
}
function getHierConstEnd(): string
{
    return  '</div>';
}

/** getHierConstBoxes function
 *     paints horizontally or vertically the level boxes in a table. Params:
 *
 * @param          $hcid
 * @param  int     $levelCount      - count of boxes
 * @param  bool    $horizontal      = 0 - should be the boxes placed horizontal?
 * @param  string  $targetBox       = "" - where to put selected values (useful by admin=false)
 * @param  bool    $admin           = true - are the boxes in admin interface?
 *                                  if yes, buttons are "Add new" and "Select",
 *                                  else, buttons are "Select" - moves to the targetBox
 * @param  int     $minLevelSelect  = 0 - from which level should be the "Select" button shown
 * @param  int     $boxWidth
 * @param  array   $levelNames      = array() - names for the level boxes (if you don't like Level 0, Level 1, etc.)
 *
 * @return string
 */
function getHierConstBoxes($hcid, int $levelCount, bool $horizontal = false, string $targetBox = "", $admin = true, $minLevelSelect = 0, int $boxWidth = 0, array $levelNames = []): string
{
    $admin = $admin ? 1 : 0;
    if ($boxWidth === 0) {
        $boxWidth = $horizontal ? 30 : 70;
    }

    /* OFMG
       20060421
       When you add a value in a hierarchicalConstant
       it does not respond to the onChange triger on the mainbox
    */
    $this_triggers = JsTriggers::getFromGlobalSliceId("select", $targetBox);
    $aa_onchange_exist = strstr($this_triggers, 'aa_onChange(');
    $add_button_trigger = "";
    if ($aa_onchange_exist) {
        [,$fieldid,] = explode("'", $aa_onchange_exist, 3);
        $add_button_trigger = "aa_onChange('" . $fieldid . "'); ";
    }

    $widthTxt = str_repeat("m", $boxWidth);
    $out = '';

    for ($i = 0; $i < $levelCount; ++$i) {
        if ($admin) {
            $buttonAdd = "<input type=\"button\" value=\"" . _m("Add new") . "\" onClick=\"hcAddNew($hcid,$i)\">";
            $buttonSelect = "<input type=\"button\" value=\"" . _m("Select") . "\" onClick=\"hcSelectItem($hcid,$i,1)\">";
        } else {
            $buttonAdd = "";
            $buttonSelect = $minLevelSelect > $i ? "" : "<input type=\"button\" value=\"" . _m("Select") . "\" onClick=\"hcAddItemTo($hcid,$i,'$targetBox'); " . $add_button_trigger . "\">";
        }
        if (!$levelNames[$i]) {
            $levelNames[$i] = _m("Level") . " $i";
        }
        // if ($horizontal) { ...
        $out .= "<div>
              <label class=\"aa-label-above\" for=\"hclevel{$i}_{$hcid}\">" . $levelNames[$i] . "</label>
              <select id=\"hclevel{$i}_{$hcid}\" name=\"hclevel{$i}_{$hcid}\" multiple size=\"10\" onChange=\"hcSelectItem($hcid,$i,$admin)\">
                <option>$widthTxt
              </select>
              <br><br>$buttonAdd&nbsp;&nbsp;$buttonSelect
              </div>";
    }

    return $out;
}

/** showHierConstInitJavaScript function
 *
 */
function showHierConstInitJavaScript($hcid, $group_id, $levelCount = 3, $formName = 'f', $admin = true): void
{
    echo getHierConstInitJavaScript($hcid, $group_id, $levelCount, $formName, $admin);
}
/** showHierConstBoxes function
 *
 */
function showHierConstBoxes($hcid, int $levelCount, bool $horizontal = false, string $targetBox = "", bool $admin = true, int $minLevelSelect = 0, int $boxWidth = 0, array $levelNames = []): void
{
    echo getHierConstStart($horizontal);
    echo getHierConstBoxes($hcid, $levelCount, $horizontal, $targetBox, $admin, $minLevelSelect, $boxWidth, $levelNames);
    echo getHierConstEnd();
}

/** createConstsJavaScript function
 *  creates string forming JavaScript array definition
 *
 * @param $group_id - name of constants group
 * @param $admin - admin pages
 * @return string
 */
function createConstsJavaScript($group_id, $admin): string
{
    $data = createConstsArray($group_id, $admin);
    hcSortArray($data);

    $consts = "new Array(";
    foreach ($data as $i => $iValue) {
        if ($i) {
            $consts .= ",";
        }
        $consts .= printConstsArray($data[$i], $admin);
    }
    $consts .= ")";
    return $consts;
}

/** createConstsArray function
 *  The constants of the group as a tree of nodes [name, value, pri, desc, short_id, false, children]
 *  (columns in $hcCol, children only when there are some). The tree follows the `ancestors` paths;
 *  rows whose ancestors do not fit into it are added to the top level, or next to the preceding node.
 *  Built as data, never as PHP code - the texts are the constants' own (C1.11).
 *  @param $group_id
 *  @param $admin - with descriptions
 *  @return array
 */
function createConstsArray($group_id, $admin): array
{
    global $get_method;

    $data = [];
    foreach (DB_AA::select([], 'SELECT * FROM constant', [['group_id', $group_id]]) as $row) {
        $name  = str_replace(["\r", "\n"], '', (string)$row["name"]);
        $value = str_replace(["\r", "\n"], '', (string)$row["value"]);
        // & in from _GET is taken as parameter delimiter, so we replace it
        // with %26. We do not replace it, if it is called from editing form
        if ($get_method) {
            $value = str_replace("&", "%26", $value);
        }
        if ($name == $value) {   // loose, as always: '1.0' == '1'
            $value = '#';
        }
        $data[$row["ancestors"] . $row["id"]] = [
            $name,
            $value,
            (int)$row["pri"],
            $admin ? str_replace(["\r", "\n"], '', (string)$row["description"]) : "",
            (int)$row["short_id"],
            false,
        ];
    }

    ksort($data);

    $levels     = [];   // node lists of the branch being built, top level first - the last node of each list is the parent of the next one
    $path       = "";
    $error_data = [];
    foreach ($data as $ancestors => $node) {
        $error = false;
        if (!$path) {
            if (strlen($ancestors) !== 16) {
                $error = true;
            } else {
                $levels = [[$node]];
            }
        } elseif (strlen($ancestors) % 16) {
            $error = true;
            // step over one layer
        } elseif (strlen($ancestors) > strlen($path) && str_starts_with($ancestors, $path)) {
            if (strlen($ancestors) - strlen($path) === 16) {
                $levels[] = [$node];
            } else {
                $error = true; // error: missing layer, jump over
            }
        } elseif (strlen($ancestors) === strlen($path)) {
            if (!str_starts_with($ancestors, substr($path, 0, -16))) {
                $error = true;
            } else {
                $levels[count($levels) - 1][] = $node;
            }
        } else {
            $level = 0;
            while (substr($ancestors, 0, $level * 16) === substr($path, 0, $level * 16)) {
                ++$level;
            }
            for ($i = 0, $ino = strlen($path); $i < $ino / 16 - $level && count($levels) > 1; ++$i) {
                hcCloseLevel($levels);
            }
            $levels[count($levels) - 1][] = $node;
        }
        if ($error) {
            $error_data[] = $node;
        } else {
            $path = $ancestors;
        }
    }
    while (count($levels) > 1) {
        hcCloseLevel($levels);
    }
    return array_merge($levels[0] ?? [], $error_data);
}

/** Hangs the deepest node list of the branch under its parent - the last node one level up.
 *  @param $levels (by link) - see createConstsArray()
 */
function hcCloseLevel(array &$levels): void
{
    global $hcCol;
    $children = array_pop($levels);
    $parent   = count($levels) - 1;
    $levels[$parent][array_key_last($levels[$parent])][$hcCol["Child"]] = $children;
}

/** hcCompareConstants function
 *
 * @param $a
 * @param $b
 *
 * @return int
 */
function hcCompareConstants($a, $b): int
{
    global $hcCol;
    $cmp = $a[$hcCol["Prior"]] <=> $b[$hcCol["Prior"]];
    if ($cmp !== 0) {
        return $cmp;
    }
    return $a[$hcCol["Name"]] <=> $b[$hcCol["Name"]];
}

/** hcSortArray function
 * @param $arr (by link)
 */
function hcSortArray(&$arr): void
{
    global $hcCol;
    usort($arr, "hcCompareConstants");
    foreach ($arr as $iValue) {
        if (count($iValue) > $hcCol["Child"]) {
            hcSortArray($iValue[$hcCol["Child"]]);
        }
    }
}

/** A text as a single-quoted JavaScript string for hcConsts[] inside <script>: \ and ' escaped,
 *  CR/LF dropped, < as \x3C (no </script> nor <!-- can appear), U+2028/U+2029 escaped
 *  (they end a string in older engines). Ordinary text comes out as it is.
 * @param $str
 * @return string
 */
function hcJsString($str): string
{
    return "'" . str_replace(
        ["\\", "'", "\r", "\n", "<", "\u{2028}", "\u{2029}"],
        ["\\\\", "\\'", "", "", "\\x3C", "\\u2028", "\\u2029"],
        (string)$str
    ) . "'";
}

/** printConstsArray function
 * @param $arr (by link)
 * @param $admin
 * @return string
 */
function printConstsArray(&$arr, $admin): string
{
    global $hcCol;
    $value = $arr[$hcCol["Value"]];
    if ($arr[$hcCol["Name"]] == $value) {
        $value = '#';
    }
    $retval = "new Array("
            . hcJsString($arr[$hcCol["Name"]]) . ","
            . hcJsString($value)
            . ($admin ? ","
            . (int)$arr[$hcCol["Prior"]] . ","
            . hcJsString($arr[$hcCol["Desc"]]) . ","
            . (int)$arr[$hcCol["ID"]] . ","
            . "false"
            : "");
    if (count($arr) > $hcCol["Child"]) {
        $retval .= ",new Array(";
        foreach ($arr[$hcCol["Child"]] as $i => $iValue) {
            if ($i) {
                $retval .= ",";
            }
            $retval .= printConstsArray($arr[$hcCol["Child"]][$i], $admin);
        }
        $retval .= ")";
    }
    $retval .= ")";
    return $retval;
}
/** Parses the hcalldata the hierarchical editor sends (constedit.js hcSendAll()):
 *  "<deleted short ids, comma separated> :changes: :name~value~pri~desc~id~ancestors:..."
 *  where ' : ~ inside the values are escaped by a backslash.
 *  @return array{int[], array|null}  [short ids to delete, changes - null when none were sent] */
function hcParseCalldata(string $hcalldata): array
{
    $changes = null;
    $hcalldata = str_replace(["\\'", "\\:", "\\~"], ["'", "--$--", "--$$--"], $hcalldata);
    $chtag     = ":changes:";
    if (str_contains($hcalldata, $chtag)) {
        $chs       = explode(":", substr($hcalldata, strpos($hcalldata, $chtag) + strlen($chtag) + 1));
        $hcalldata = substr($hcalldata, 0, max(0, strpos($hcalldata, $chtag) - 1));
        $changes   = [];
        foreach ($chs as $ch) {
            if (!str_contains($ch, "~")) {
                continue;
            }
            $changes[] = array_map(static fn($part) => str_replace(["--$--", "--$$--"], [":", "~"], $part), explode("~", $ch));
        }
    }
    return [array_values(array_filter(array_map('intval', explode(',', $hcalldata)))), $changes];
}

/** Stores the changes from the hierarchical editor into the group.
 *  @param array $changes  [[name, value, pri, description, short id or "#n" for a new one, ancestors], ...] raw values */
function hcStoreChanges(string $group_id, array $changes, bool $propagate_changes): void
{
    // short_id => unpacked id - only this group's constants, the ancestors come from the form
    $shortIDmap = DB_AA::select(['short_id' => 'id'], 'SELECT short_id, LOWER(HEX(`id`)) AS id FROM `constant`', [['group_id', $group_id]]);
    $varset = new Cvarset();

    foreach ($changes as $change) {
        [$name, $value, $pri, $desc, $new_id, $ancestor_ids] = array_pad($change, 6, '');
        $varset->clear();
        $varset->set("name", $name, "text");
        $varset->set("value", $value, "text");
        $varset->set("pri", (string) ((int) $pri ?: 1000), "number");
        $varset->set("description", $desc, "text");

        if (str_starts_with($new_id, "#")) {
            $id = new_id();
            $shortIDmap[$new_id] = $id;
            $ancestors = '';
            foreach (explode(",", $ancestor_ids) as $myid) {
                $ancestors .= $shortIDmap[$myid] ?? '';
            }
            $varset->set("id", $id, "unpacked");
            $varset->set("group_id", $group_id, "text");
            $varset->set("ancestors", $ancestors, $ancestors === '' ? "text" : "unpacked");
            $varset->doINSERT('constant');
        } else {
            $where = [['short_id', (int) $new_id, 'i'], ['group_id', $group_id]];
            if ($propagate_changes && ($old = DB_AA::select1('', 'SELECT LOWER(HEX(`id`)) AS id, value FROM `constant`', $where))) {
                propagateChanges($group_id, $old['id'], $value, $old['value']);
            }
            DB_AA::sql($varset->makeUPDATE('constant'), $where);
        }
    }
}

/** hcUpdate function
 *
 */
function hcUpdate(): void
{
    global $levelCount, $hide_value, $levelsHorizontal, $group_id, $p_slice_id;

    $qgroup_id = quote($group_id);
    $group_setting = DB_AA::select1([], 'SELECT * FROM constant_slice', [['group_id', $group_id]]);
    $db = getDB();

    if ($levelCount) {
        if ($group_setting) {
            $db->query(
                "UPDATE constant_slice SET levelcount=$levelCount,
                horizontal=" . ($levelsHorizontal ? 1 : 0) . ",
                hidevalue=" . ($hide_value ? 1 : 0) . "
                WHERE group_id='$qgroup_id'",
            );
        } else {
            $db->query(
                "INSERT INTO constant_slice (group_id,slice_id,horizontal,hidevalue,levelcount)
                VALUES ('$qgroup_id','$p_slice_id'," . ($levelsHorizontal ? 1 : 0)
                . "," . ($hide_value ? 1 : 0) . "," . $levelCount . ")",
            );
        }
    } else {
        $hide_value = 0;
        $levelCount = 2;
        $levelsHorizontal = 0;
        if ($group_setting) {
            $hide_value = $group_setting["hidevalue"];
            $levelCount = $group_setting["levelcount"];
            $levelsHorizontal = $group_setting["horizontal"];
        }
    }

    global $hcalldata;
    [$delete_ids, $changes] = hcParseCalldata((string) $hcalldata);

    // delete items - short ids from the form, so only in this group
    if ($delete_ids) {
        DB_AA::delete('constant', [['short_id', $delete_ids, 'i'], ['group_id', $group_id]]);
    }

    // update items
    if (is_array($changes)) {
        $propagate_changes = (bool) DB_AA::select1('propagate', 'SELECT propagate FROM constant_slice', [['group_id', $group_id]]);
        hcStoreChanges((string) $group_id, $changes, $propagate_changes);
        aaevent::singleton()->comes('CONSTANTGROUP_UPDATED', AA::$module_id, 'S', $event_return_state, $group_id);
    }
    ConstantsChanged();
    freeDB($db);
}

// Copy and rename constant groups in slice $slice_id so that they are not shared with other slices
// WARNING: doesn't work when the group id contains a ":" ??
// find new group_id by trying to add "_1", "_2", "_3", ... to the old one
/** CopyConstants function
 * @param $slice_id
 * @return bool
 */
function CopyConstants($slice_id): bool
{
    global $err, $debug;

    // max. length of the group_id field
    $max_group_id_len = 16;
    $q_slice_id       = xpack_id($slice_id);

    $group_list = DB_AA::select('name', 'SELECT name FROM constant', [['group_id', 'lt_groupNames']]);
    foreach (DB_AA::select([], 'SELECT id, input_show_func FROM field', [['slice_id', $slice_id, 'l']]) as $row) {
        $shf = $row["input_show_func"];
        if (strlen($shf) > 4) {
            [,$group_id] = explode(":", $shf);
            if (in_array($group_id, $group_list)) {
                $group_ids[$group_id][$row["id"]] = $shf;
            }
        }
    }
    $db = getDB();
    if (!is_array($group_ids)) {
        freeDB($db);
        return true;
    }

    foreach ($group_ids as $old_id => $fields) {

        // find new id by trying to add "_1", "_2", "_3", ... to the old one
        $new_id = $old_id;
        for ($i = 1; in_array($new_id, $group_list); ++$i) {
            $postfix = "_" . $i;
            $new_id = substr($old_id, 0, min(strlen($old_id) + strlen($postfix), $max_group_id_len) - strlen($postfix)) . $postfix;
        }
        $group_list[] = $new_id;

        if ($debug) {
            echo "Changing $old_id to $new_id.<br>";
        }

        // copy group name in table constant
        if (!CopyTableRows(
            "constant",
            "group_id='lt_groupNames' AND name='$old_id'",
            ["name" => $new_id,"value" => $new_id], // set_columns
            ["short_id"],                       // omit_columns
            ["id"],                              // id_columns
        )) {
            $err[] = "Could not copy constant group.";
            freeDB($db);
            return false;
        }

        // copy group values in table constant
        if (!CopyTableRows(
            "constant",
            "group_id='$old_id'",
            ["group_id" => $new_id],              // set_columns
            ["short_id"],                       // omit_columns
            ["id"],                              // id_columns
        )) {
            $err[] = "Could not copy constant group.";
            freeDB($db);
            return false;
        }

        // update fields
        foreach ($fields as $field_id => $shf) {
            if (!$db->query("UPDATE field SET input_show_func = '"
                . addslashes(str_replace($old_id, $new_id, $shf)) . "'
                WHERE id='$field_id' AND slice_id=$q_slice_id")) {
                $err[] = "Could not update fields.";
                freeDB($db);
                return false;
            }
        }
    }

    ConstantsChanged();
    return true;
}

/** get_unique_group_id function
 *   Looks into database if the group already exists. If yes, it returns modified,
 *   but unique group name
 *
 * @param  string  $group_id
 */
function get_unique_group_id(string $group_id): string
{
    $group_id = str_replace(':', '-', $group_id);  // we don't need ':'

    foreach (DB_AA::select('value', 'SELECT value FROM constant', [['group_id', 'lt_groupNames'], ['value', $group_id, 'RLIKE']]) as $gname) {
        $gnames[$gname] = 1;
    }
    $unique_name = $group_id;
    if (is_array($gnames)) {
        $i = 1;
        while ($gnames[$unique_name]) {
            $unique_name = $group_id . " " . ($i++);
        }
    }
    return $unique_name;
}


// -------------------------------------------------------------------
/** add_constant_group function
*    Adds a new constant group. Sets priority increasingly in the same
*   order as are the constants in @c $items.
*
*   @param string $group_id Desired group name, may be changed on conflicts
*                           and $unique is set
*   @param array $constants2import array('name'=>.., 'value'=>.., 'pri'=>.., 'group'=>..)
*
*   @return true|string - true on success or error string if it fails
*@author Jakub Adamek, Econnect, January 2003
*/
function add_constant_group(string $group_id, $constants2import): true|string
{
    if ($group_id === '') {
        return _m("No group id specified");
    }
    if (!constant_group_id_valid($group_id)) {
        return _m('Wrong constant group id');
    }

    if (DB_AA::select1([], 'SELECT * FROM constant', [['group_id', $group_id]])) {
        return _m("This constant group already exists");
    }

    if (!is_array($constants2import) || count($constants2import) < 1) {
        return _m('No constants specified');
    }

    // set in seconds - allows the script to work so long
    MoreTimeLimit(); // set_time_limit to 5 minutes (at least)

    $varset = new CVarset();
    $varset->add("group_id", "text", "lt_groupNames");
    $varset->add("value", "text", $group_id);
    $varset->add("name", "text", $group_id);
    $varset->add("id", "unpacked", new_id());
    $varset->doINSERT("constant");

    $priority_step = 10;
    $priority      = 0;
    foreach ($constants2import as $constant) {
        $priority = $constant['pri'] ?? $priority + $priority_step;
        $varset->clear();
        $varset->add("value", "text", $constant['value']);
        $varset->add("name", "text", $constant['name']);
        $varset->add("pri", "number", $priority);
        $varset->add("class", "text", $constant['class']);
        $varset->add("id", "unpacked", new_id());
        $varset->add("group_id", "text", $group_id);
        $varset->doINSERT("constant");
    }
    ConstantsChanged();
    return true;
}

// -------------------------------------------------------------------

/** Groups AA itself defines and relies on - never deleted, edited only by a superadmin.
 *  lt_groupNames is the list of all the groups. */
const AA_CONSTANT_GROUPS_CORE = ['lt_groupNames', ...AA\Optimize\RedefineCoreConstants::GROUPS];

/** delete_constant_group function
*   Deletes a constant group. If $slice_id is provided, the group
*   is deleted only if it is used only in the given slice.
*   AA's core groups (AA_CONSTANT_GROUPS_CORE) and malformed ids are never deleted.
*
*   @author Jakub Adamek, Econnect, January 2003
*   @param string $group_id  group ID
*   @param string $slice_id  unpacked slice ID
*   @return bool  @c true if group was deleted (or there was nothing to delete), @c false otherwise	*/
function delete_constant_group($group_id, $slice_id = ""): bool
{
    $group_id = (string) $group_id;
    if (!constant_group_id_valid($group_id) || in_array($group_id, AA_CONSTANT_GROUPS_CORE, true)) {
        return false;
    }
    // a group shared with another slice stays
    if ($slice_id && array_filter(constant_group_fields($group_id), static fn($field) => $field['unpackid'] !== strtolower($slice_id))) {
        return false;
    }
    // delete group name, constants and the ownership
    $qgroup_id = quote($group_id);
    $ret = DB_AA::sql("DELETE FROM `constant` WHERE (`group_id`='lt_groupNames' AND `value`='$qgroup_id') OR `group_id`='$qgroup_id'");
    $ret_owner = DB_AA::delete('constant_slice', [['group_id', $group_id]]);
    ConstantsChanged();
    return ($ret !== false) && ($ret_owner !== false);   // 0 rows is fine - nothing was left to delete
}

/** A constant group id as `constant`.group_id holds it: 1-16 bytes (varbinary(16)). No ':' -
 *  field.input_show_func separates its parameters by it - and nothing that could leave an SQL
 *  string or act as a LIKE wildcard beyond '_' (quotes, backslash, %, control characters). */
function constant_group_id_valid(string $group_id): bool
{
    return (bool) preg_match('/^[^\x00-\x1F\x7F:\'"`\\\\%]{1,16}\z/', $group_id);
}

/** Group id as the constants admin sends it - varname4form(): 'v' + hex.
 *  @return string|null  the group id, '' when none was sent, null for anything that is no valid group id */
function constant_group_from_form(string $form_value): ?string
{
    if (strlen($form_value) <= 1) {
        return '';
    }
    $hex = substr($form_value, 1);
    if (!ctype_xdigit($hex) || (strlen($hex) % 2)) {
        return null;
    }
    $group_id = pack_id($hex);
    return constant_group_id_valid($group_id) ? $group_id : null;
}

/** Fields using the constant group (field.input_show_func is like 'sel:<group_id>:...')
 *  @return array  [['unpackid' => slice id, 'deleted', 'sname', 'fid', 'fname'], ...] */
function constant_group_fields(string $group_id): array
{
    $like = constant_group_like($group_id);
    return DB_AA::select([], 'SELECT LOWER(HEX(module.id)) AS unpackid, module.deleted, module.name as sname, field.id as fid, field.name as fname FROM `module`, `field`', [
        ['module.id', 'field.slice_id', 'j'],
        [null, [[['field.input_show_func', "%:$like", 'LIKE']], [['field.input_show_func', "%:$like:%", 'LIKE']]], 'OR'],
    ]);
}

/** The slice owning the group: ['owner_id' => unpacked slice id, 'name' => slice name, 'propagate'],
 *  false when no slice owns it */
function constant_group_owner(string $group_id): array|false
{
    return DB_AA::select1('', 'SELECT LOWER(HEX(`slice_id`)) AS owner_id, name, propagate FROM constant_slice, slice', [['constant_slice.slice_id', 'slice.id', 'j'], ['group_id', $group_id]]);
}

/** Why the constants admin must not change (edit, or with $delete delete) the group - '' when it may.
 *  With $change = false: why it must not even show it.
 *  $owner is constant_group_owner($group_id).
 *  $may_edit_slice(unpacked slice id): bool - does the user have PS_FIELDS in that slice.
 *  A core group is never deleted and changed by a superadmin only (lt_groupNames not at all).
 *  A group owned by a slice needs PS_FIELDS there - even to be shown. A group nobody owns is shown
 *  to anyone, but changed only when every slice using it is the user's - saving propagates the
 *  values into those slices' items. Whoever saves it first becomes its owner. */
function constant_group_denied(string $group_id, array|false $owner, callable $may_edit_slice, bool $delete = false, bool $superadmin = false, bool $change = true): string
{
    if (!constant_group_id_valid($group_id)) {
        return _m('Wrong constant group id');
    }
    if (($group_id === 'lt_groupNames') || (in_array($group_id, AA_CONSTANT_GROUPS_CORE, true) && $change && ($delete || !$superadmin))) {
        return _m('This constant group is used by ActionApps itself and can\'t be changed here');
    }
    if ($owner) {
        return $may_edit_slice($owner['owner_id']) ? '' : _m("You do not have permissions to change fields settings for the slice owning this group") . " (" . safe($owner['name']) . ")";
    }
    if ($change) {
        foreach (constant_group_fields($group_id) as $field) {
            if (!$may_edit_slice($field['unpackid'])) {
                return _m("The constant group is used also in slice you do not have permissions to change fields settings") . " (" . safe($field['sname']) . ")";
            }
        }
    }
    return '';
}

/** Rewrites $oldvalue to $newvalue in the items of all the fields using the constant group.
 *  Both values are raw (not escaped).
 *  @return int  number of changed item values */
function constant_value_replace(string $group_id, string $oldvalue, string $newvalue): int
{
    if ($oldvalue === '') {
        return 0;
    }
    $like  = constant_group_like($group_id);
    $cnt   = 0;
    $items2update = [];

    // we have to join also item table in order we make sure field is in right slice
    foreach (DB_AA::select([], 'SELECT item_id,field_id FROM content, item, field', [
        ['content.item_id', 'item.id', 'j'],
        ['item.slice_id', 'field.slice_id', 'j'],
        ['content.field_id', 'field.id', 'j'],
        [null, [[['field.input_show_func', "___:$like:%", 'LIKE']], [['field.input_show_func', "___:$like", 'LIKE']]], 'OR'],
        ['content.text', $oldvalue],
    ]) as $row) {
        ++$cnt;
        $items2update[$row['field_id']] ??= new zids(null, 'p');
        $items2update[$row['field_id']]->add($row['item_id']);
    }
    foreach ($items2update as $field => $zids) {
        DB_AA::sql("UPDATE content SET text=" . qquote($newvalue) . " WHERE " . $zids->sqlin('item_id') . " AND field_id=" . qquote($field) . " AND text=" . qquote($oldvalue));
    }
    return $cnt;
}

/** propagateChanges function
 * Propagates changes to a constant value to the items which contain this value.
 * @param string $group_id    constant group id
 * @param string $constant_id
 * @param string $newvalue    the new value (raw, not escaped)
 * @param string $oldvalue
 */
function propagateChanges($group_id, $constant_id, $newvalue, $oldvalue): void
{
    global $Msg;

    if ((string) $oldvalue === (string) $newvalue) {    // not ==, '01' -> '1' is a change
        return;
    }

    $event = aaevent::singleton();
    $event->comes('CONSTANT_BEFORE_UPDATE', AA::$module_id, 'S', $newvalue, $oldvalue, $constant_id);

    if ($cnt = constant_value_replace((string) $group_id, (string) $oldvalue, (string) $newvalue)) {
        $Msg .= $cnt . _m(" items changed to new value ") . "'" . safe($newvalue) . "'<br>";
    }

    $event->comes('CONSTANT_UPDATED', AA::$module_id, 'S', $newvalue, $oldvalue, $constant_id);
}

// -------------------------------------------------------------------

/** refresh_constant_group function
*   Refreshes a constant group: replaces old members with the new.
*   If the group does not exist, it is created.
*   @param $group_id
*   @param $items
*   @return bool true if the group existed - an invalid or core group id is left alone, false
*/
function refresh_constant_group($group_id, $items): bool
{
    if (!constant_group_id_valid((string) $group_id) || in_array($group_id, AA_CONSTANT_GROUPS_CORE, true)) {
        return false;
    }

    $varset = new CVarset();

    $group_name = DB_AA::select1([], 'SELECT * FROM constant', [['group_id', 'lt_groupNames'], ['name', $group_id]]);
    $db = getDB();
    if (!$group_name) {
        $existed = false;
        $varset->add("group_id", "text", "lt_groupNames");
        $varset->add("value", "text", $group_id);
        $varset->add("name", "text", $group_id);
        $varset->add("id", "unpacked", new_id());
        $varset->doINSERT('constant');
    } else {
        $existed = true;
        $db->query("DELETE FROM constant WHERE group_id=" . qquote($group_id));
    }

    $priority = 100;
    if (is_array($items)) {
        foreach ($items as $value => $name) {
            $varset->clear();
            $varset->add("value", "text", $value);
            $varset->add("name", "text", $name);
            $varset->add("pri", "number", $priority);
            $priority += 100;
            $varset->add("id", "unpacked", new_id());
            $varset->add("group_id", "text", $group_id);
            $varset->doINSERT('constant');
        }
    }
    ConstantsChanged();
    freeDB($db);
    return $existed;
}
