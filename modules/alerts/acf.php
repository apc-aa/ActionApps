<?php

/**
 * Alerts admin: Selections (alerts_collection_filter).
 *
 * Chooses which selections (alerts_filter rows = filters defined in Alerts
 * Selection Set views) form the Alert email of the current collection, and in
 * which order. Replaces the legacy tabledit `acf` table view
 * (modedit.php?set_tview=acf). The addable selections are restricted to the
 * user's PS_FULLTEXT slices (superadmins see all); showSelectionTable() below
 * lists the available selections grouped by slice/view.
 *
 * @package Alerts
 */
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

use AA\IO\DB\DB_AA;
use AA\Util\InputVars;

require_once __DIR__ . "/../../include/init_page.php";
require_once __DIR__ . "/../../include/formutil.php";
require_once __DIR__ . "/../../include/varset.php";
require_once __DIR__ . "/../../include/perm_core.php";
require_once __DIR__ . "/util.php";
require_once __DIR__ . "/send_emails.php";   // showSelectionTable()

if (!IfSlPerm(PS_FULLTEXT)) {
    MsgPage(_m("You do not have permissions to add slice"));
    exit;
}

try {
    $add          = InputVars::boolPOST('add');
    $add_filterid = InputVars::intPOST('add_filterid');
    $add_myindex  = InputVars::intPOST('add_myindex');
    $del          = InputVars::arrayPOST('del');          // [filterid => 1]
    $do_delete    = InputVars::boolPOST('delete');
    $do_save      = InputVars::boolPOST('save_order');
    $idx          = InputVars::arrayPOST('idx');          // [filterid => order]
    $Msg          = InputVars::messageGETPOST();
} catch (Exception $e) {
    AA_Adminpageutil::pageParameterError($e);
}

set_collectionid();
$collectionid = $GLOBALS['collectionid'];

/** Selections the current user may add: filterid => description, restricted to the user's PS_FULLTEXT slices. */
function alertsAvailableSelections(): array
{
    $available = [];
    $myslices  = GetUserSlices();
    foreach (DB_AA::select([], "SELECT DF.description AS fdesc, DF.id AS filterid, view.slice_id
                  FROM slice
                  INNER JOIN view ON slice.id = view.slice_id
                  INNER JOIN alerts_filter DF ON DF.vid = view.id
                  ORDER BY DF.description") as $row) {
        if (IsSuperadmin() || str_contains($myslices[unpack_id($row["slice_id"])] ?? '', PS_FULLTEXT)) {
            $available[$row["filterid"]] = $row["fdesc"];
        }
    }
    return $available;
}

$available = alertsAvailableSelections();
// descriptions for every filter (so already-chosen selections show a label even
// if the user no longer has fulltext access to the originating slice)
$all_desc  = DB_AA::select(['id' => 'description'], "SELECT id, description FROM alerts_filter");

// ---- process actions -------------------------------------------------------
if ($do_delete && is_array($del)) {
    foreach (array_keys($del) as $fid) {
        DB_AA::delete('alerts_collection_filter', [['collectionid', $collectionid, 's'], ['filterid', (int) $fid, 'i']]);
    }
}
if ($do_save && is_array($idx)) {
    foreach ($idx as $fid => $order) {
        DB_AA::update('alerts_collection_filter', [['myindex', (int) $order, 'i']], [['collectionid', $collectionid, 's'], ['filterid', (int) $fid, 'i']]);
    }
}
if ($add && $add_filterid && isset($available[$add_filterid])
        && !DB_AA::test('alerts_collection_filter', [['collectionid', $collectionid, 's'], ['filterid', $add_filterid, 'i']])) {
    $varset = new CVarset();
    $varset->set('collectionid', $collectionid, 'text');
    $varset->set('filterid', $add_filterid, 'number');
    $varset->set('myindex', $add_myindex ?: 1, 'number');
    $varset->doInsert('alerts_collection_filter');
}

// ---- current selections ----------------------------------------------------
$current = DB_AA::select(['filterid' => 'myindex'], "SELECT filterid, myindex FROM alerts_collection_filter", [['collectionid', $collectionid, 's']], ['myindex']);

$apage = new AA_Adminpageutil('admin', 'filters');
$apage->setTitle(_m("Alerts Selections"));
$apage->setForm();
$apage->printHead($err, $Msg);

echo "<p>" . _m("Choose selections which form the Alert email.") . "</p>";

// chosen selections - reorder / remove
echo '<form method="post">';
echo '<table><tr class="aa-tbl-header"><th>' . _m("order") . '</th><th>' . _m("selection") . '</th><th>' . _m("remove") . '</th></tr>';
if ($current) {
    foreach ($current as $fid => $order) {
        $label = isset($all_desc[$fid]) ? safe($all_desc[$fid]) : '';
        echo '<tr><td><input type="number" name="idx[' . (int) $fid . ']" value="' . (int) $order . '" size="3"></td>'
            . '<td>f' . (int) $fid . ' ' . $label . '</td>'
            . '<td><input type="checkbox" name="del[' . (int) $fid . ']" value="1"></td></tr>';
    }
} else {
    echo '<tr><td colspan="3">' . _m("No selections chosen yet.") . '</td></tr>';
}
echo '</table>';
echo '<button type="submit" name="save_order" value="1" class="aa-button">' . _m("Save order") . '</button> ';
echo '<button type="submit" name="delete" value="1" class="aa-button">' . _m("Remove checked") . '</button>';
echo '</form>';

// add a selection
echo '<br><form method="post">';
if ($available) {
    $next_index = $current ? (max(array_map('intval', $current)) + 1) : 1;
    echo '<select name="add_filterid"><option value="">--</option>';
    foreach ($available as $fid => $desc) {
        if (!isset($current[$fid])) {
            echo '<option value="' . (int) $fid . '">f' . (int) $fid . ' ' . safe($desc) . '</option>';
        }
    }
    echo '</select> ';
    echo _m("order") . ' <input type="number" name="add_myindex" value="' . (int) $next_index . '" size="3"> ';
    echo '<button type="submit" name="add" value="1" class="aa-button">' . _m("Add selection") . '</button>';
} else {
    echo '<b>' . _m("No selections defined. You must define some.") . '</b>';
}
echo '</form>';

// help: available selections grouped by slice and view
showSelectionTable();

$apage->printFoot();
