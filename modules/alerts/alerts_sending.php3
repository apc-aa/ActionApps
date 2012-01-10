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

require_once AA_INC_PATH."item.php3";
require_once AA_INC_PATH."slice.class.php3";
require_once AA_INC_PATH."view.php3";
require_once AA_INC_PATH."pagecache.php3";
require_once AA_INC_PATH."searchlib.php3";
require_once AA_INC_PATH."mail.php3";
require_once AA_INC_PATH."item_content.php3";
require_once AA_BASE_PATH."modules/alerts/util.php3";

//$debug = 1;

class AA_Collection {

    var $id;                 /** Collection ID   */
    var $reader_slice_id;    /** Slice id of Reder slice */
    var $alerts_module_id;   /** Module id of Alerts */
    var $email_id_welcome;   /** Id of welcome email template */
    var $email_id_alert;     /** Id of alert email template */
    var $slice_url;          /** Used for caching of slice_url */

    function AA_Collection($collection_id) {
        $this->id               = $collection_id;
        $this->reader_slice_id  = null;
        $this->alerts_module_id = null;
        $this->email_id_welcome = null;
        $this->email_id_alert   = null;
        $this->slice_url        = null;
    }

    function _get( $property ) {
        if ( is_null($this->reader_slice_id) ) {
            $data = GetTable2Array('SELECT * FROM alerts_collection WHERE id = \''. $this->id .'\'', 'aa_first', 'aa_fields');
            $this->reader_slice_id  = unpack_id($data['slice_id']);
            $this->alerts_module_id = unpack_id($data['module_id']);
            $this->email_id_welcome = $data['emailid_welcome'];
            $this->email_id_alert   = $data['emailid_alert'];
        }
        return $this->$property;
    }

    function getReaderSlice()    { return AA_Slices::getSlice($this->_get('reader_slice_id'));  }
    function getAlertsModuleId() { return $this->_get('alerts_module_id');   }
    function getEmailIdWelcome() { return $this->_get('email_id_welcome'); }
    function getEmailIdAlert()   { return $this->_get('email_id_alert');   }

    function getSliceUrl()       {
        if ( is_null($this->slice_url) ) {
            $alerts_id = $this->getAlertsModuleId();
            $this->slice_url =  GetTable2Array('SELECT slice_url FROM module WHERE id=\''.q_pack_id($alerts_id).'\'', 'aa_first', 'slice_url');
        }
        return $this->slice_url;
    }

    function getReaders($how_often = null) {
        $slice          = $this->getReaderSlice();
        $field_howoften = getAlertsField(FIELDID_HOWOFTEN, $this->id);

        $aa_set         = new AA_Set($slice->unpacked_id(), new AA_Condition(FIELDID_MAIL_CONFIRMED, '=', 1));
        if ( !is_null( $how_often ) ) {
            $aa_set->addCondition(new AA_Condition($field_howoften, '=', $how_often));
        }

        return $aa_set->query();
    }

    function getReadersSelectArray($ignore_reading_password, $how_often = null) {
        $ret = array();
        $content4ids = GetItemContent($this->getReaders($how_often), false, $ignore_reading_password, array(FIELDID_USERNAME, FIELDID_EMAIL));
        if ( is_array($content4ids) ) {
            foreach ($content4ids as $reader_id => $content4id ) {
                $ret[$reader_id] = $content4id[FIELDID_USERNAME][0]['value'] . ' ('. $content4id[FIELDID_EMAIL][0]['value'] . ')';
            }
        }
        return $ret;
    }

    function sendEmails($ho, $emails, $update, $item_id, $reader_id) {
        // get array of all filters of current collection ($collection_id)
        // !if $update is set (default), then it updates date for lastsent - for
        // collections
        $unordered_filters = $this->createFilterText($ho, $update, $item_id);
        $email_count       = 0;

        // find filters for this collection
        $filter_ids = GetTable2Array("SELECT filterid FROM alerts_collection_filter WHERE collectionid = '".$this->id."' ORDER BY myindex", 'NoCoLuMn', 'filterid');

        $filters = array();
        if ( is_array($filter_ids) ) {
            foreach ($filter_ids as $filter_id) {
                $filters[$filter_id] = &$unordered_filters[$filter_id];
            }
        }

        // Find all users who should receive anything
        if ( !is_array($emails) ) {

            // get all confirmed users for this collection and frequency
            $zids = is_null($reader_id) ? $this->getReaders($ho) : new zids($reader_id, 'l');
            AA_Log::write("ALERTS", "Users for collection ".$this->id.": ". ((int)$zids->count()), $ho);

            $readerContent = new ItemContent();

            // loop through readers might want to send
            for ( $i=0, $zcount=$zids->count(); $i<$zcount; $i++) {
                $readerContent->setByItemID( $zids->longids($i), true);

                $user_text = $this->getFilterText4Reader($readerContent, $filters);

                // Don't send if nothing new emerged
                if ($user_text) {

                    $als = new AA_Aliases();
                    $als->addTextAlias("_#FILTERS_",$user_text);
                    $als->addTextAlias("_#HOWOFTEN",$ho);
                    $als->addTextAlias("_#COLLFORM",alerts_con_url($this->getSliceUrl(), "ac=".$readerContent->getValue(FIELDID_ACCESS_CODE)));
                    $als->addTextAlias("_#UNSBFORM",alerts_con_url($this->getSliceUrl(), "au=".$readerContent->getValue(FIELDID_ACCESS_CODE). "&c=".$this->id));

                    $reader_slice = $this->getReaderSlice();
                    $aliases      = array_merge($reader_slice->aliases(), $als->getArray());
                    $item         = new AA_Item($readerContent, $aliases);

                    if (AA_Mail::sendTemplate($this->getEmailIdAlert(), $readerContent->getValue(FIELDID_EMAIL), $item)) {
                        AA_Log::write("ALERTS", $this->id.": ". $readerContent->getValue(FIELDID_EMAIL), $ho);
                        $email_count++;
                    }
                }
            }

        // Use the emails sent as param
        } else {
            AA_Log::write("ALERTS", "Emails for collection ".$this->id.": ". ((int)count($emails)), $ho);
            $als = new AA_Aliases();
//          $als->addAlias(new AA_Alias("_#FILTERS_", "id..............", 'f_t', array(get_filter_text_4_reader(null, $filters, $this->id))));
            $als->addTextAlias("_#FILTERS_", $this->getFilterText4Reader(null, $filters));
            $als->addTextAlias("_#HOWOFTEN", $ho);
            $als->addTextAlias("_#COLLFORM", alerts_con_url($this->getSliceUrl(), "ac=ABCDE"));
            $als->addTextAlias("_#UNSBFORM", alerts_con_url($this->getSliceUrl(), "au=ABCDE&c=".$this->id));
            $aliases = $als->getArray();

            $item = new AA_Item('', $aliases);

            foreach ( (array)$emails as $email ) {
                if (AA_Mail::sendTemplate($this->getEmailIdAlert(), $email, $item)) {
                    $email_count++;
                }
            }
        }
        AA_Log::write("ALERTS", "Sent for collection ".$this->id.": ". ((int)$email_count[$this->id]), $ho);
        return $email_count;
    }

    /**  Creates Filter output. Called from sendEmails().
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
    function createFilterText($ho, $update, $item_id) {

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
            AND CH.collectionid='".$this->id."'";
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
            $sid                                   = unpack_id($db->f("slice_id"));
            $slices[$sid]["name"]                  = $db->f("slicename");
            $slices[$sid]["lang"]                  = substr($db->f("lang_file"),0,2);
            $myview                                = &$slices[$sid]["views"][$db->f("vid")];
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

        foreach ( $slices as $slice_id => $slice ) {
            $p_slice_id   = q_pack_id($slice_id);

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
                       publish_date <= $now AND expiry_date >= $last "  // a) 2. and b) 2.
                   ."AND ((moved2active BETWEEN $last AND $now) "       // a) 1.
                         ."OR (moved2active < $last "                   // b) 1.
                             ."AND publish_date > $last) "              // b) 3.
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
                    $zids  = new zids(null, "p");
                    if (is_array($all_ids)) {
                        // find items for the given filter
                        $zids = QueryZIDs(array($slice_id), $conds, $sort, "ACTIVE", 0, new zids( $all_ids,'p' ));
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

        if ($update) {
            $varset = new CVarset();
            $varset->addkey("howoften", "text", $ho);
            $varset->addkey("collectionid", "text", $this->id);
            $varset->add ("last", "number", $now);
            $db->tquery($varset->makeINSERTorUPDATE("alerts_collection_howoften"));
        }

        //print_r ($retval);
        freeDB($db);
        return $retval;
    }

    /** Used in send_emails(). Finds filter text for the given reader.
    *   There are two modes: group by Selection and don't group. The
    *   second one is more difficult.
    *   @param object $readerContent  if null, use all filters
    */
    function getFilterText4Reader($readerContent, $filters) {
        if ($readerContent) {
            $user_filters_value = $readerContent->getValues(getAlertsField(FIELDID_FILTERS, $this->id));

            if ( empty($user_filters_value)) {
                return "";
            }

            foreach ($user_filters_value as $user_filter) {
                // filter numbers are stored with "f" added to the beginning
                $user_filters[substr($user_filter['value'], 1)] = 1;
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
                // sort[]: we use the same global trick as in createFilterText
                if ($last_fprop["sort"]) {
                    parse_str( $last_fprop["sort"], $dbconds_arr);
                    $sort      = $dbconds_arr['sort'];
                    $set       = &get_view_settings_cached($last_fprop["vid"]);
                    $user_zids = QueryZIDs(array(unpack_id($set["info"]->f("slice_id"))), "", $sort, "ACTIVE", 0, $user_zids);
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
function send_emails($ho, $collection_ids, $emails, $update, $item_id, $reader_id = null) {

    /* get all (or just some, if $collection_ids specified) collections and put
       the infop into $colls array */
    if (!is_array($collection_ids)) {
        $collection_ids = GetTable2Array('SELECT alerts_collection.id FROM alerts_collection, module WHERE module.id=alerts_collection.module_id AND module.deleted<1', 'NoCoLuMn', 'id');
    }

    $total_emails = 0;
    foreach ( $collection_ids as $collection_id ) {
        $collection = new AA_Collection($collection_id);
        $total_emails += $collection->sendEmails($ho, $emails, $update, $item_id, $reader_id);

    }
    return $total_emails;
}

// -------------------------------------------------------------------------------------
/**  Initializes the "last" field (which tells when were the Alerts last time sent)
*   for all unfilled filter / howoften combinations
*   to a value depending on howoften, i.e. to one week ago for weekly etc.
*/
function initialize_last() {
    $now = getdate ();

    $init["instant"] = time();
    // one day ago
    $init["daily"]   = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday']-1);
    // one week (7 days) ago
    $init["weekly"]  = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday']-7);
    // two week (14 days) ago
    $init["twoweeks"]  = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday']-14);
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
        while ($db->next_record()) {
            $db2->tquery("INSERT INTO alerts_collection_howoften (collectionid, howoften, last)
                VALUES ('".$db->f("id")."', '$ho', ".$init[$ho].")");
        }

        // the same as above, but for rows, which exist but with last=0
        $db->tquery("SELECT C.id FROM alerts_collection C INNER JOIN
            alerts_collection_howoften CH ON CH.collectionid = C.id
            WHERE last=0 AND howoften='$ho'");
        while ($db->next_record()) {
            $db2->tquery("UPDATE alerts_collection_howoften SET last=".$init[$ho]
                ." WHERE collectionid=".$db->f("id")." AND howoften='$ho'");
        }
    }
    freeDB($db);
    freeDB($db2);
}

// -------------------------------------------------------------------------------------

function get_view_settings_cached($vid) {
    global $cached_view_settings;
    if (!$vid) {
        return "";
    }
    if (!$cached_view_settings[$vid]) {
        $view         = AA_Views::getView($vid);
        $slice        = AA_Slices::getSlice(unpack_id($view->f("slice_id")));
        $fields       = $slice->fields('record');
        $cached_view_settings[$vid] = array (
            "lang"    => substr($slice->getProperty('lang_file'),0,2),
            "info"    => $view,
            "fields"  => $fields,
            "aliases" => GetAliasesFromFields($fields),
            "format"  => $view->getViewFormat()
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
function get_filter_output_cached($vid, $filter_settings, $zids) {
    global $cached_view_settings, $cached_filter_outputs;

    if ($zids->count() == 0) {
        return "";
    }
    if ( !isset($cached_filter_settings[$filter_settings])) {
        $set = &get_view_settings_cached($vid);
        // set language
        mgettext_bind($set["lang"], 'alerts', true);

        // $set["info"]["aditional2"] stores item URL
        $item_url = $set["info"]->f("aditional2");
        if (! $item_url) {
            $item_url = "You didn't set the item URL in the view $vid settings!";
        }
        $itemview   = new itemview($set["format"], $set["fields"], $set["aliases"], $zids, 0, 9999, $item_url);
        $items_text = $itemview->get_output ("view");
        //if (! strstr ($filter_settings, ","))
        $cached_filter_settings[$filter_settings] = $items_text;
    } else {
        $items_text = $cached_filter_settings[$filter_settings];
    }
    return $items_text;
}

// -------------------------------------------------------------------------------------

?>
