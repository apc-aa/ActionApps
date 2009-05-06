<?php
/**
 * Alerts menu: Slice Synchro.
 * Adds Alerts-specific fields to the Reader Management Slice.
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

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

require_once dirname(__FILE__). "/../../include/init_page.php3";
require_once menu_include();
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."varset.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_BASE_PATH."modules/alerts/synchro2.php3";

// -------------------------------------------------------------------
// HTML code

$collectionprop = GetCollection($slice_id);
if ($collectionprop) {
    $collectionid = $collectionprop['id'];
} else {
    echo "Can't find collection with module_id=$slice_id. Bailing out.<br>";
}

HTMLPageBegin();
echo "<title>"._m("Slice Synchro")."</title>";
echo "</head>";
echo "<body>";
showMenu ($aamenus, "admin", "synchro");

echo '<h1>'._m("Synchronization with Reader Management Slice").'</h1>';

// Execute requested actions from FORM

if ($add_fields) {
    echo "<b>".add_fields_2_slice($collectionid, $collectionprop["slice_id"])."</b><br><br>";
}

if ($change_to_cmd && ($change_to != $collectionprop["slice_id"]))	{
    if ($change_to_delete) {
        echo "<b>".delete_fields_from_slice($collectionid, $collectionprop["slice_id"])."</b><br><br>";
    }
    if ($change_to) {
        $db->query ("UPDATE alerts_collection SET slice_id='".q_pack_id($change_to)."' WHERE id='$collectionid'");
    } else {
        $db->query ("UPDATE alerts_collection SET slice_id = NULL WHERE id='$collectionid'");
    }
    $collectionprop["slice_id"] = $change_to;
}

$db->query ("SELECT name FROM slice WHERE id = '". q_pack_id($collectionprop["slice_id"])."'");
if ($db->next_record()) {
    $slice_name = $db->f("name");
    $slice_set  = true;
} else {
    $slice_set  = false;
    $slice_name = _m("Not Yet Set");
}

// Choose Reader Management Slice

echo '
<form method="post" name="form_choose" action="'.$sess->url("synchro.php3").'">
<table border="1" cellspacing="0" cellpadding="10" align="center" width="440">
    <tr><td class=tabtxt>
    <h2>'._m("Choose Reader Management Slice").'</h2>
    '._m("This Alerts Collection takes user data from the slice").":<br><b>";

if ($slice_set) {
    echo "<a href=\"".$sess->url(AA_INSTAL_PATH."admin/index.php3?slice_id=". $collectionprop["slice_id"])."\">".$slice_name.'</a>';
} else {
    echo $slice_name;
}
echo '</b><br><br>'._m("Change to: ");

FrmSelectEasy("change_to", getReaderManagementSlices(), $collectionprop["slice_id"]);

if ($slice_set) {
    echo '<br><input type="checkbox" name="change_to_delete" checked> '
        ._m("and delete the %1-specific fields from %2", array($collectionprop["name"], $slice_name));
}

echo '
    <br><br><input type="submit" name="change_to_cmd" value="'._m("Change").'">
</td></tr></table></form>
<br>';

// Add Alerts-specific fields to Reader Management

echo '
<form method="post" name="form_add" action="'.$sess->url("synchro.php3").'">
<table border="1" cellspacing="0" cellpadding="10" align="center" width="440">
    <tr><td class=tabtxt>
    <h2>'._m("Add %1-specific fields to %2",
             array ($collectionprop["name"], $slice_name)).'</h2>';
echo _m("Adds only fields the IDs of which don't yet exist in the slice.
    Refreshes the constant group containing selections if it already exists.");

// Field table

echo '<br><br><table border="1" cellspacing="0" cellpadding="3">
    <tr><td class="tabtit">'._m("Field Name").'</td>
        <td class="tabtit">'._m("Field ID").'</td></tr>';

$fields = get_alerts_specific_fields($collectionid);
foreach ($fields as $field_id => $fprop) {
    echo "<tr><td class=\"tabtxt\">".$fprop["name"]."</td><td class=\"tabtxt\">$field_id</td></tr>\n";
}
echo '</table><br>';

if ($slice_set) {
    echo '<input type="submit" name="add_fields" value="'._m("Add or refresh fields").'">';
} else {
    echo _m("This command can not be used until you choose the Reader Management Slice.");
}

echo "\n</td></tr></table></form>";

HTMLPageEnd();
page_close();
?>