<?php
/**
 * Functions for preparing the Filters and Collections output and sending Alerts with new messages.
 *
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications
*/
/*
Copyright (C) 1999-2002 Association for Progressive Communications
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

//require_once "lang.php3";
require_once $GLOBALS["AA_INC_PATH"]."item.php3";
require_once $GLOBALS["AA_INC_PATH"]."view.php3";
require_once $GLOBALS["AA_INC_PATH"]."pagecache.php3";
require_once $GLOBALS["AA_INC_PATH"]."searchlib.php3";
require_once $GLOBALS["AA_INC_PATH"]."mail.php3";
require_once $GLOBALS["AA_INC_PATH"]."item_content.php3";
require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/util.php3";

//$debug = 1;

// -------------------------------------------------------------------------------------
/**  Creates Filter output. Called from send_emails().
*    Finds and formats items.
*    WARNING:  the function does not check when were the items sent last time,
*              call it only once a day / week / month. In fact, this function
*              works completely the same for any how often (only that it writes
*              the values to the appropriate field).
*
* The only exception are the "instant" messages: If the paramter $item_id
* contains an unpacked item ID, nothing or a message containing just that item is sent.
*
* @param bool $update    decides whether alerts_filter_howoften.last would be updated,
*                        i.e. whether this is only a debug trial or the real life
* @param $ho = how often
* @return array ($filterid => new items)
*/
function create_filter_text($ho, $collectionid, $update, $item_id)
{
    global $debug_alerts, $auth;

    if ( $debug_alerts ) { huhl("create_filter_text($ho, $collectionid, $update, $item_id)"); }

    // the view.aditional field stores info about grouping by selections
    $SQL = "
    SELECT F.conds, view.slice_id, view.aditional, view.aditional3,
        F.id AS filterid, F.vid, slice.name AS slicename,
        slice.lang_file, CH.last
        FROM alerts_filter F INNER JOIN
             alerts_collection_filter CF ON CF.filterid = F.id INNER JOIN
             alerts_collection_howoften CH ON CF.collectionid = CH.collectionid INNER JOIN
             view ON view.id = F.vid INNER JOIN
             slice ON slice.id = view.slice_id
        WHERE CH.howoften='$ho'
        AND CH.collectionid='$collectionid'";
    $db = getDB();
    if ($item_id) {
        $db->tquery("SELECT slice_id FROM item WHERE id='".q_pack_id($item_id)."'");
        if (!$db->next_record()) {
            freeDB($db);
            return "";
        }
        $SQL .= " AND slice.id='".addslashes($db->f("slice_id"))."'";
    }

    // fill alerts_collection_howoften.last in cases the row for this period
    // (= how_often) not exist, yet
    initialize_last();

    $db->tquery($SQL);
    while ($db->next_record()) {
        $last                                  = $db->f("last");
        $slices[$db->f("slice_id")]["name"]    = $db->f("slicename");
        $slices[$db->f("slice_id")]["lang"]    = substr($db->f("lang_file"),0,2);
        $myview                                = &$slices[$db->f("slice_id")]["views"][$db->f("vid")];
        $myview["filters"][$db->f("filterid")] = array ("conds"=>$db->f("conds"));
        // Group by selections?
        $myview["group"] = $db->f("aditional");
        if (! $myview["group"]) {
            // Sort variable for the whole view
            $myview["sort"] = $db->f("aditional3");
        }
    }
    if (! is_array($slices)) {
        freeDB($db);
        return;
    }

    $howoften_options = get_howoften_options();

    // first I create a hierarchical array $slices and than use it instead of the recordset

    $now = time();

    foreach ( $slices as $p_slice_id => $slice ) {
        $slice_id     = unpack_id128($p_slice_id);
        list($fields) = GetSliceFields($slice_id);

        /*
        this query is carefully made to include only items, which:
         a) 1. were moved to active between $last and $now
            2. and were active (not expired nor pending) between $last and $now,
                i.e. publish_date <= $now AND expiry_date >= $last
         b) 1. were moved to active before $last
            2. and were active (not expired nor pending) between $last and $now
            3. and were not active until $last,
                i.e. publish_date > $last

       The field moved2active is filled whenever an item is moved from other bin
           to Active binor when it is a new item inserted into Active bin.
       The field is cleared whenever the item is moved from Active bin
           to another one.
        */

        $SQL = "SELECT id FROM item ";

        if ($item_id) {
            $SQL .= "WHERE id = '".q_pack_id($item_id)."'";
        } else {
            $SQL .=
            "WHERE slice_id = '$p_slice_id' AND
                   publish_date <= $now AND expiry_date >= $last "  # a) 2. and b) 2.
               ."AND ((moved2active BETWEEN $last AND $now) "       # a) 1.
                     ."OR (moved2active < $last "                   # b) 1.
                         ."AND publish_date > $last) "              # b) 3.
                   .")";
        }

        $db->tquery($SQL);

        $all_ids = "";
        while ($db->next_record()) {
            $all_ids[] = $db->f("id");
        }

        foreach ($slice["views"] as $vid => $view) {
            foreach ($view["filters"] as $fid => $filter) {
                parse_str( $filter["conds"], $dbconds_arr);
                $conds = $dbconds_arr['conds'];
                $sort  = $dbconds_arr['sort'];
                $zids  = new zids (null, "p");
                if (is_array ($all_ids)) {
                    // find items for the given filter
                    if ($debug_alerts) { print_r ($conds); echo "----<br>"; $GLOBALS['debug']=1; }
                    $zids = QueryZIDs ($fields, $slice_id, $conds, $sort, "", "ACTIVE", "", 0, new zids( $all_ids,'p' ));
                    if ($debug_alerts) { $GLOBALS['debug']=0; echo "<br>Item IDs count: ".$zids->count()."<br>"; }
                }

                $retval[$fid] = array (
                    "group" => $view["group"],
                    "sort"  => $view["sort"],
                    "vid"   => $vid,
                    "zids"  => $zids,
                );
            }
        }
    }

    if (!$debug_alerts && $update) {
        $varset = new CVarset();
        $varset->addkey("howoften", "text", $ho);
        $varset->addkey("collectionid", "text", $collectionid);
        $varset->add ("last", "number", $now);
        $db->tquery($varset->makeINSERTorUPDATE("alerts_collection_howoften"));
    }

    //print_r ($retval);
    freeDB($db);
    return $retval;
}

// -------------------------------------------------------------------------------------
/** Sends emails to all or chosen users, from all or chosen collections.
*
*   @param string $ho      = how often
*   @param array $collection_ids (collection_id, collection_id, ...).
*            If set to "all", all collections are processed.
*   @param array $email (email, email, ...)
*            If set to "all", all Alerts users are processed.
*   @return count of emails sent
*/
function send_emails ($ho, $collection_ids, $emails, $update, $item_id)
{
    global $debug_alerts;

    $db = getDB();
    if (is_array($collection_ids)) {
        $where = " WHERE AC.id IN ('".join ("','", $collection_ids)."')";
    }
    $db->tquery("
            SELECT module.slice_url, AC.id AS collectionid, AC.*, email.*
            FROM alerts_collection AC
            INNER JOIN email ON AC.emailid_alert = email.id
            INNER JOIN module ON AC.module_id = module.id
            $where");
    while ($db->next_record()) {
        $colls[$db->f("collectionid")] = $db->Record;
    }
    if (!is_array ($colls)) {
        freeDB($db);
        return;
    }

    $readerContent = new ItemContent ();

    foreach ($colls as $cid => $collection) {
        $unordered_filters = create_filter_text ($ho, $cid, $update, $item_id);

        // find filters for this collection
        $db->tquery("SELECT CF.filterid
            FROM alerts_collection_filter CF
            WHERE CF.collectionid = '$cid'
            ORDER BY CF.myindex");

        $filters = "";
        while ($db->next_record()) {
            $filters[$db->f("filterid")] = &$unordered_filters[$db->f("filterid")];
        }

        if ($GLOBALS['debug_email']) { huhl("\n-------\n send_emails\n",$collection); }

        // Find all users who should receive anything
        if (! is_array ($emails)) {
            $db->tquery("
                SELECT id FROM item
                WHERE slice_id = '".addslashes($collection["slice_id"])."'
                  AND status_code = 1
                  AND publish_date <= ".time()."
                  AND expiry_date >= ".time());

            $field_howoften = getAlertsField(FIELDID_HOWOFTEN, $cid);

            // loop through items might want to send
            while ($db->next_record()) {
                $readerContent->setByItemID( unpack_id( $db->f("id")), true);
                if ( $readerContent->getValue( $field_howoften ) != $ho
                     || ! $readerContent->getValue( FIELDID_MAIL_CONFIRMED )) {
                    continue;
                }

                $user_text = get_filter_text_4_reader($readerContent, $filters, $cid);

                // Don't send if nothing new emerged
                if ($user_text) {
                    $alias["_#FILTERS_"] = $user_text;
                    $alias["_#HOWOFTEN"] = $ho;
                    $alias["_#COLLFORM"] = alerts_con_url ($collection["slice_url"],
                                           "ac=".$readerContent->getValue(FIELDID_ACCESS_CODE));
                    $alias["_#UNSBFORM"] = alerts_con_url ($collection["slice_url"],
                                           "au=".$readerContent->getValue(FIELDID_ACCESS_CODE).
                                           "&c=".$cid);

                    if ($GLOBALS['debug_email']) {
                        huhl("\n<br>send_mail_from_table(".$collection["emailid_alert"].", ".$readerContent->getValue(FIELDID_EMAIL).", $alias)");
                        $email_count++;
                    } elseif (send_mail_from_table($collection["emailid_alert"], $readerContent->getValue(FIELDID_EMAIL), $alias)) {
                        $email_count++;
                    }
                }
            }

        // Use the emails sent as param
        } else {
            foreach ( (array)$emails as $email ) {
                $alias["_#FILTERS_"] = get_filter_text_4_reader(null, $filters, $cid);
                $alias["_#HOWOFTEN"] = $ho;
                $alias["_#COLLFORM"] = alerts_con_url($collection["slice_url"], "ac=ABCDE");
                $alias["_#UNSBFORM"] = alerts_con_url($collection["slice_url"], "au=ABCDE&c=".$cid);

                if (send_mail_from_table($collection["emailid_alert"], $email, $alias)) {
                    $email_count++;
                }
            }
        }
    }
    freeDB($db);
    return $email_count;
}

// -------------------------------------------------------------------------------------
/**  Initializes the "last" field (which tells when were the Alerts last time sent)
*   for all unfilled filter / howoften combinations
*   to a value depending on howoften, i.e. to one week ago for weekly etc.
*/
function initialize_last ()
{
    $now = getdate ();

    $init["instant"] = time();
    // one day ago
    $init["daily"]   = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday']-1);
    // one week (7 days) ago
    $init["weekly"]  = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday']-7);
    // one month ago
    $init["monthly"] = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon']-1);

    $db  = getDB();
    $db2 = getDB();

    $hos = get_howoften_options();
    foreach ($hos as $ho => $v ) {
        // fill alerts_collection_howoften.last in cases the row for this period
        // (= how_often) not exist, yet
        $db->tquery("SELECT C.id FROM alerts_collection C LEFT JOIN
            alerts_collection_howoften CH ON CH.collectionid = C.id AND howoften='$ho'
            WHERE last IS NULL");
        while ($db->next_record())
            $db2->tquery("INSERT INTO alerts_collection_howoften (collectionid, howoften, last)
                VALUES ('".$db->f("id")."', '$ho', ".$init[$ho].")");

        // the same as above, but for rows, which exist but with last=0
        $db->tquery("SELECT C.id FROM alerts_collection C INNER JOIN
            alerts_collection_howoften CH ON CH.collectionid = C.id
            WHERE last=0 AND howoften='$ho'");
        while ($db->next_record())
            $db2->tquery("UPDATE alerts_collection_howoften SET last=".$init[$ho]
                ." WHERE collectionid=".$db->f("id")." AND howoften='$ho'");
    }
    freeDB($db);
    freeDB($db2);
}

// -------------------------------------------------------------------------------------

function get_view_settings_cached ($vid) {
    global $cached_view_settings;
    if (!$vid) {
        return "";
    }
    if (!$cached_view_settings[$vid]) {
        $view_info    = GetViewInfo($vid);
        list($fields) = GetSliceFields( unpack_id($view_info["slice_id"]));
        $slice_info   = GetSliceInfo( unpack_id($view_info["slice_id"]));
        $cached_view_settings[$vid] = array (
            "lang"    => substr($slice_info["lang_file"],0,2),
            "info"    => $view_info,
            "fields"  => $fields,
            "aliases" => GetAliasesFromFields($fields),
            "format"  => GetViewFormat($view_info),
        );
    }
    return $cached_view_settings[$vid];
}

// -------------------------------------------------------------------------------------
/** Warning: This function caches all combinations of selections. If there
*   are too many different combinations, memory may be exhausted.
*
*   TODO: Rewrite this function using a DB table.
*
*   @param string $filter_settings  One or more filter IDs separted by comma ",".
*/
function get_filter_output_cached ($vid, $filter_settings, $zids) {
    global $cached_view_settings,
           $cached_filter_outputs;

    //echo "zids"; print_r ($zids);
    if ($zids->count() == 0) {
        return "";
    }
    if ( !isset($cached_filter_settings[$filter_settings])) {
        $set = &get_view_settings_cached($vid);
        // set language
        bind_mgettext_domain($GLOBALS["AA_INC_PATH"]."lang/". $set["lang"]."_alerts_lang.php3", true);
        // $set["info"]["aditional2"] stores item URL
        $item_url = $set["info"]["aditional2"];
        if (! $item_url) {
            $item_url = "You didn't set the item URL in the view $vid settings!";
        }
        $itemview   = new itemview($set["format"], $set["fields"], $set["aliases"], $zids, 0, 9999, $item_url);
        $items_text = $itemview->get_output ("view");
    //echo "<h1>items $items_text</h1>"; print_r ($set["format"]); exit;
        //if (! strstr ($filter_settings, ","))
        $cached_filter_settings [$filter_settings] = $items_text;
    } else {
        $items_text = $cached_filter_settings[$filter_settings];
    }
    return $items_text;
}

// -------------------------------------------------------------------------------------
/** Used in send_emails(). Finds filter text for the given reader.
*   There are two modes: group by Selection and don't group. The
*   second one is more difficult.
*   @param object $readerContent  if null, use all filters
*/
function get_filter_text_4_reader ($readerContent, $filters, $cid)
{
    if ($readerContent) {
        $user_filters_value = $readerContent->getValues( getAlertsField(FIELDID_FILTERS, $cid));

        if ( !is_array($user_filters_value)) {
            return "";
        }

        foreach ($user_filters_value as $user_filter) {
            // filter numbers are stored with "f" added to the beginning
            $user_filters [substr ($user_filter ["value"], 1)] = 1;
        }
    }

    $last_fprop = "";
    $filter_ids = "";
    $user_zids  = new zids(null, "p");

    // add dummy filter
    $filters[99999] = "dummy";

    foreach ( $filters as $filterid => $fprop ) {
        // Send items from filters with "group" not set when the view changes.
        if ( $last_fprop["vid"] != $fprop["vid"] AND is_array($filter_ids) AND $user_zids->count()) {
            // WARNING - HACK: we need to sort the zids according to the common
            // sort[]: we use the same global trick as in create_filter_text
            if ($last_fprop["sort"]) {
                parse_str( $last_fprop["sort"], $dbconds_arr);
                $sort = $dbconds_arr['sort'];
                $set  = &get_view_settings_cached ($last_fprop["vid"]);
                $user_zids = QueryZIDs ($set["fields"],
                    unpack_id ($set["info"]["slice_id"]),
                    "", $sort, "", "ACTIVE", "", 0, $user_zids);
            }

            $user_text .= get_filter_output_cached($last_fprop["vid"], join (",",$filter_ids), $user_zids);
            $filter_ids = "";
            $user_zids->clear("p");
        }
        if ($fprop == "dummy") {
            break;
        }
        if (! $readerContent || $user_filters[$filterid]) {
            if ($fprop["group"]) {
                $user_text .= get_filter_output_cached( $fprop["vid"], $filterid, $fprop["zids"]);
            } else {
                $user_zids->union($fprop["zids"]);
                $filter_ids[] = $filterid;
            }
            $last_fprop = $fprop;
        }
    }

    return $user_text;
}

?>
