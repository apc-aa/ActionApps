<?php
/**
 * Executes actions called from synchro.php3:
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
require_once AA_INC_PATH."constedit_util.php3";
require_once AA_BASE_PATH."modules/alerts/util.php3";

// --------------------------------------------------------------

/* Fields to be added into the Reader Management Slice.
   Field ID consists of "alerts1/2/3/4", dots, and collection ID, e.g. "alerts1.....154".
*/
$alerts_specific_fields = array (
    "alerts1" => array (
        "name" => _m("How often"),
        // {ALERNAME} will be replaced by the current Alerts Name
        "input_help" => _m("How often for {ALERNAME}"),
        "input_show_func" => "sel:{CONSTGROUP}",
        // Add a constant group and add its name to "input_show_func"
        "constants" => array (
            "group" => "How often",
            "items" => get_howoften_options() + array (" " => _m("not subscribed"))),
        "alias1" => "_#HOWOFTEN",
        "alias1_func" => "f_c:!:::&nbsp;",
        "alias1_help" => _m("How often for {ALERNAME}"),
    ),
/*	"alerts3" => array (
        "name" => _m("Status"),
        "input_help" => _m("Status for {ALERNAME}"),
        "input_show_func" => "sel:",
        "constants" => array (
            "group" => "Status",
            "items" => get_bin_names()),
        "alias1" => "_#STATCODE",
        "alias1_func" => "f_h",
        "alias1_help" => _m("Status for {ALERNAME}"),
    ),*/
    "alerts2" => array (
        "name" => _m("Selections"),
        "input_help" => _m("Selections for {ALERNAME}"),
        "input_show_func" => "mch:{CONSTGROUP}:3:1",
        "constants" => array (
            "group" => "Selections",
            "items" => "{FILTERS}"),
        "alias1" => "_#FILTERS_",
        "alias1_func" => "f_h:,",
        "alias1_help" => _m("Selecetion IDs for {ALERNAME}"),
    ));

// Add this to each field definition alerts1-4
$field_defaults = array (
    "input_default" => "txt:",
    "required" => 0,
    "feed" => 0,
    "multiple" => 0,
    "html_default" => 0,
    "html_show" => 0,
    "input_insert_func" => "qte",
    "input_show" => 1,
    // stored in content.text?
    "text_stored" => 1,
    );

// -------------------------------------------------------------------
/** Returns $alerts_specific_fields with keys updated to the values needed. */
function get_alerts_specific_fields($collectionid) {
    global $alerts_specific_fields;
    reset ($alerts_specific_fields);
    while (list ($field_id, $fprop) = each ($alerts_specific_fields)) {
        $field_id = getAlertsField ($field_id, $collectionid);
        $retval[$field_id] = $fprop;
    }
    return $retval;
}

// -------------------------------------------------------------------

/** Adds Alerts-specific fields to the Reader Management Slice.
*   Skips fields which already are in the slice.
*   @param string $slice_id	long ID of Reader Management Slice
*	@return string Message about the number of field added. */
function add_fields_2_slice($collectionid, $slice_id) {
    global $db, $field_defaults;
    $alerts_specific_fields = get_alerts_specific_fields ($collectionid);

    // find current Alerts Name
    $db->query ("
        SELECT module.name FROM alerts_collection AC
        INNER JOIN module ON AC.module_id = module.id
        WHERE AC.id = '$collectionid'");
    $db->next_record();
    $alerts_name = $db->f ("name");

    // find filters to fill into the Filters constant group
    $db->query("
        SELECT AF.description, AF.id FROM alerts_filter AF
        INNER JOIN alerts_collection_filter ACF ON AF.id = ACF.filterid
        WHERE ACF.collectionid = '$collectionid'
        ORDER BY ACF.myindex");
    while ($db->next_record()) {
        $filters["f".$db->f("id")] = $db->f("description");
    }

    // find priority: find gap beginning by 2000 with step 200
    $input_pri = 1800;
    do {
        $input_pri += 200;
        $db->query("SELECT id FROM field WHERE slice_id = '". q_pack_id($slice_id)."' AND id NOT LIKE '\_%' AND input_pri = $input_pri");
    } while ($db->next_record());

    $varset = new CVarset;
    // count of added fields
    $nadded = 0;
    foreach ($alerts_specific_fields as $field_id => $foo) {
        $fprop = &$alerts_specific_fields[$field_id];

        $varset->clear();
        $varset->addkey("slice_id", "unpacked", $slice_id);
        $varset->addkey("id",       "text", $field_id);

        // don't add fields twice
        $db->query($varset->makeSELECT ("field"));
        $exists     = $db->next_record();
        $field_info = $db->Record;
        if (! $exists ) {
            $nadded ++;

            $varset->add ("input_pri", "number", $input_pri);
            $input_pri += 10;
        }

        if ($fprop ["constants"]["items"] == "{FILTERS}") {
            $fprop ["constants"]["items"] = $filters;
        }
        if ($fprop ["constants"]) {
            if ($exists) {
                list(,$groupname) = explode(":", $field_info["input_show_func"]);
                refresh_constant_group($groupname, $fprop["constants"]["items"]);
                $fprop["input_show_func"] = $field_info["input_show_func"];
            } else {
                $groupname = get_unique_group_id($fprop["constants"]["group"]);

                // prepare array for adding to constants
                $constants = array();
                if (isset($fprop["constants"]["items"]) AND is_array($fprop["constants"]["items"])) {
                    foreach ( $fprop["constants"]["items"] as $value => $name ) {
                        $constants[] = array('name'=>$name, 'value'=>$value);
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

        foreach ($field_defaults as $name => $value) {
            $varset->add ($name, "text", $value);
        }
        $db->query($varset->makeINSERTorUPDATE("field"));
    }
    return _m("%1 field(s) added", array ($nadded));
}

// -------------------------------------------------------------------

/** Deletes Alerts-specific fields from slice, including constant groups.
*   Negates add_fields_2_slice() doings. */
function delete_fields_from_slice($collectionid, $slice_id) {
    global $db;
    $ndeleted_groups        = 0;
    $ndeleted               = 0;
    $alerts_specific_fields = get_alerts_specific_fields($collectionid);
    $varset = new CVarset;
    $varset->addkey ("slice_id", "unpacked", $slice_id);
    foreach ($alerts_specific_fields as $field_id => $foo) {
        $varset->addkey("id", "text", $field_id);
        $db->query($varset->makeSELECT ("field"));
        if ($db->next_record()) {
            list ($fnc, $group_id) = explode (":", $db->f("input_show_func"));
            if (delete_constant_group($group_id, $slice_id)) {
                $ndeleted_groups++;
            }
            $ndeleted++;
            $varset->doDelete("field");
        }
    }
    return _m("%1 field(s) and %2 constant group(s) deleted", array($ndeleted+0, $ndeleted_groups+0));
}

// -------------------------------------------------------------------

/** Returns array (unpacked_slice_id => name) of slices which contain
*   all fields listed in $required_fields_in_reader_management. */
function getReaderManagementSlices () {
    global $db, $required_fields_in_reader_management, $slice_id,
           $collectionprop;

    $slices = GetUserSlices();
    $SQL = "SELECT id, name FROM slice WHERE type='ReaderManagement'
        AND id <> '".addslashes($collectionprop["sliceid"])."'";
    if (is_array($slices)) {
        reset ($slices);
        $delim = "";
        while (list ($slice_id) = each ($slices)) {
            $where .= $delim . "'".q_pack_id ($slice_id)."'";
            $delim = ",";
        }
        $SQL .= " AND id IN (".$where.")";
    }
    $db->query ($SQL);
    while ($db->next_record())
        $retval [unpack_id128 ($db->f("id"))] = $db->f("name");
    if ($collectionprop["slice_id"])
        $retval[""] = _m("not set");
    return $retval;
}
?>