<?php
/**
 * Executes actions called from synchro.php3:
 * Adds Alerts-specific fields to the Reader Management Slice.
 * @package Alerts
 * @version $Id$
 * @author Jakub Adamek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
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

require_once __DIR__."/../../include/init_page.php3";
require_once __DIR__."/../../include/util.php3";
require_once __DIR__."/../../include/varset.php3";
require_once __DIR__."/../../include/constedit_util.php3";
require_once __DIR__."/../../modules/alerts/util.php3";

// -------------------------------------------------------------------
/** Returns $alerts_specific_fields with keys updated to the values needed.
 * @param string $collectionid - 5 letters id of collection
 * @return array
 */
function get_alerts_specific_fields($collectionid) {
    /* Fields to be added into the Reader Management Slice.
       Field ID consists of "alerts1/2/3/4", dots, and collection ID, e.g. "alerts1.....154".
    */

    // Add this to each field definition alerts1, 2, ...
    $FIELD_DEFAULTS = [
        'type' => '',
        "input_default" => "txt:",
        "required" => 0,
        "feed" => 0,
        "multiple" => 0,
        "html_default" => 0,
        "html_show" => 0,
        "input_insert_func" => "qte",
        "input_show" => 1,
        "text_stored" => 1,
        'input_validate' => ''
    ];

    $retval[getAlertsField('alerts1', $collectionid)] = array_merge($FIELD_DEFAULTS, [
            "name" => _m("How often"),
            "input_help" => _m("How often for {ALERNAME}"),   // {ALERNAME} will be replaced by the current Alerts Name
            "input_show_func" => "sel:{CONSTGROUP}",          // Add a constant group and add its name to "input_show_func"
            "constants" => [
                "group" => "How often",
                "items" => get_howoften_options() + [" " => _m("not subscribed")]
            ],
            "alias1" => "_#HOWOFTEN",
            "alias1_func" => "f_c:!:::&nbsp;",
            "alias1_help" => _m("How often for {ALERNAME}"),
    ]);
    $retval[getAlertsField('alerts2', $collectionid)] = array_merge($FIELD_DEFAULTS, [
        "name" => _m("Selections"),
        "input_help" => _m("Selections for {ALERNAME}"),
        "input_show_func" => "mch:{CONSTGROUP}:3:1",
        "constants" => [
            "group" => "Selections",
            "items" => "{FILTERS}"
        ],
        "alias1" => "_#FILTERS_",
        "alias1_func" => "f_h:,",
        "alias1_help" => _m("Selecetion IDs for {ALERNAME}"),
    ]);
    return $retval;
}

// -------------------------------------------------------------------

/** Adds Alerts-specific fields to the Reader Management Slice.
 *   Skips fields which already are in the slice.
 *   @param string $collectionid 5 letters logn collection id
 *   @param string $slice_id	long ID of Reader Management Slice
 *	 @return string Message about the number of field added. */
function add_fields_2_slice($collectionid, $slice_id) {
    $alerts_specific_fields = get_alerts_specific_fields ($collectionid);

    // find current Alerts Name
    $alerts_name = DB_AA::select1('name', 'SELECT module.name FROM alerts_collection, module', [
        ['alerts_collection.module_id', 'module.id', 'j'],
        ['alerts_collection.id', $collectionid]
    ]);

    // find filters to fill into the Filters constant group
    $filters = DB_AA::select(['fid'=>'description'], 'SELECT CONCAT("f",alerts_filter.id) AS fid, alerts_filter.description FROM alerts_filter, alerts_collection_filter', [
        ['alerts_filter.id','alerts_collection_filter.filterid','j'],
        ['alerts_collection_filter.collectionid',$collectionid]
    ], ['alerts_collection_filter.myindex']);

    // find priority: find gap beginning by 2000 with step 200
    $input_pri = 2000;
    while (DB_AA::test('field', [['slice_id',$slice_id,'l'], ['id', '\_%', 'NOT LIKE'], ['input_pri',$input_pri,'i']])) {
        $input_pri += 200;
    }

    $varset = new CVarset;
    // count of added fields
    $nadded = 0;

    foreach ($alerts_specific_fields as $field_id => $foo) {
        $fprop = &$alerts_specific_fields[$field_id];

        $varset->clear();
        $varset->addkey("slice_id", "unpacked", $slice_id);
        $varset->addkey("id",       "text", $field_id);

        // don't add fields twice
        $field_info = DB_AA::select1('', 'SELECT * FROM field', [['slice_id', $slice_id, 'l'], ['id', $field_id]]);

        if (! is_array($field_info) ) {
            $nadded ++;

            $varset->add ("input_pri", "number", $input_pri);
            $input_pri += 10;
        }

        if ($fprop ["constants"]["items"] == "{FILTERS}") {
            $fprop ["constants"]["items"] = $filters;
        }
        if ($fprop ["constants"]) {
            if (is_array($field_info)) {
                [,$groupname] = explode(":", $field_info["input_show_func"]);
                refresh_constant_group($groupname, $fprop["constants"]["items"]);
                $fprop["input_show_func"] = $field_info["input_show_func"];
            } else {
                $groupname = get_unique_group_id($fprop["constants"]["group"]);

                // prepare array for adding to constants
                $constants = [];
                if (isset($fprop["constants"]["items"]) AND is_array($fprop["constants"]["items"])) {
                    foreach ( $fprop["constants"]["items"] as $value => $name ) {
                        $constants[] = ['name'=>$name, 'value'=>$value];
                    }
                }
                add_constant_group($groupname, $constants);
                $fprop["input_show_func"] = str_replace("{CONSTGROUP}", $groupname, $fprop["input_show_func"]);
            }
        }
        foreach ($fprop as $name => $value) {
            if (!is_array($value)) {
                $value = str_replace ("{ALERNAME}", $alerts_name, $value);
                $varset->add ($name, "text", $value);
            }
        }
        $varset->doREPLACE("field");
    }
    return _m("%1 field(s) added", [$nadded]);
}

// -------------------------------------------------------------------

/** Deletes Alerts-specific fields from slice, including constant groups.
*   Negates add_fields_2_slice() doings. */
function delete_fields_from_slice($collectionid, $slice_id) {
    $ndeleted_groups        = 0;
    $ndeleted               = 0;
    $alerts_specific_fields = get_alerts_specific_fields($collectionid);
    $varset = new CVarset;
    $varset->addkey ("slice_id", "unpacked", $slice_id);

    $db = getDB();
    foreach ($alerts_specific_fields as $field_id => $foo) {
        $varset->addkey("id", "text", $field_id);

        $db->query($varset->makeSELECT ("field"));
        if ($db->next_record()) {
            list (, $group_id) = explode (":", $db->f("input_show_func"));
            if (delete_constant_group($group_id, $slice_id)) {
                $ndeleted_groups++;
            }
            $ndeleted++;
            $varset->doDelete("field");
        }
    }
    freeDB($db);
    return _m("%1 field(s) and %2 constant group(s) deleted", [$ndeleted+0, $ndeleted_groups+0]);
}

// -------------------------------------------------------------------

/** Returns array (unpacked_slice_id => name) of slices which contain
*   all fields listed in $required_fields_in_reader_management. */
function getReaderManagementSlices () {
    global $slice_id, $collectionprop;

    $slices = GetUserSlices();
    $SQL = "SELECT id, name FROM slice WHERE type='ReaderManagement'
        AND id <> '".addslashes($collectionprop["sliceid"])."'";
    if (is_array($slices)) {
        $delim = "";
        foreach ($slices as $slice_id => $foo) {
            $where .= $delim . "'".q_pack_id ($slice_id)."'";
            $delim = ",";
        }
        $SQL .= " AND id IN (".$where.")";
    }
    $db = getDB();
    $db->query ($SQL);
    while ($db->next_record()) {
        $retval [unpack_id ($db->f("id"))] = $db->f("name");
    }
    if ($collectionprop["slice_id"]) {
        $retval[""] = _m("not set");
    }
    freeDB($db);
    return $retval;
}
