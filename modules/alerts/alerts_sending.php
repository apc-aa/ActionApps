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
use AA\Later\AlertsSweep;

require_once __DIR__ . "/../../include/item.php";
require_once __DIR__ . "/../../include/view.php";

require_once __DIR__ . "/../../include/searchlib.php";
require_once __DIR__ . "/../../include/mail.php";
require_once __DIR__ . "/../../include/item_content.php";
require_once __DIR__ . "/../../modules/alerts/util.php";

//$debug = 1;

class AA_Collection
{
    public $id;                 /** Collection ID   */
    public $reader_slice_id = null;    /** Slice id of Reder slice */
    public $alerts_module_id = null;   /** Module id of Alerts */
    public $email_id_welcome = null;   /** Id of welcome email template */
    public $email_id_alert = null;     /** Id of alert email template */
    public $slice_url = null;          /** Used for caching of slice_url */

    public function __construct($collection_id)
    {
        $this->id               = $collection_id;
    }

    /** A collection id as new_collection_id() makes it: gensalt(5), i.e. five ASCII letters or digits.
     *  For ids arriving from a request - send_emails() trusts the ids it gets. */
    public static function isValidId(mixed $collection_id): bool
    {
        return is_string($collection_id) && preg_match('/\A[A-Za-z0-9]{5}\z/', $collection_id) === 1;
    }

    /** The test address of a trial send, as a request gives it.
     *  @return string[]|null  [] when none is given (the real send to all readers), [$address] when valid,
     *                         null when given but not a valid address - the caller must refuse, not send to all */
    public static function testEmails(mixed $test_email): ?array
    {
        if ($test_email === null || $test_email === '') {
            return [];
        }
        return (is_string($test_email) && filter_var($test_email, FILTER_VALIDATE_EMAIL) !== false) ? [$test_email] : null;
    }

    /** @return string[] filter ids of this collection, in the collection's order */
    public function getFilterIds(): array
    {
        return DB_AA::select('filterid', 'SELECT filterid FROM alerts_collection_filter', [['collectionid', $this->id]], ['myindex']);
    }

    protected function getProp($property)
    {
        if (is_null($this->reader_slice_id)) {
            //$data = GetTable2Array('SELECT * FROM alerts_collection WHERE id = \''. $this->id .'\'', 'aa_first', 'aa_fields');
            $data = DB_AA::select1([], 'SELECT * FROM alerts_collection', [['id', $this->id]]);
            $this->reader_slice_id  = unpack_id($data['slice_id']);
            $this->alerts_module_id = unpack_id($data['module_id']);
            $this->email_id_welcome = $data['emailid_welcome'];
            $this->email_id_alert   = $data['emailid_alert'];
        }
        return $this->$property;
    }

    public function getReaderSlice(): ?AA_Module
    {
        return AA_Slice::getModule($this->getProp('reader_slice_id'));
    }

    public function getSliceUrl()
    {
        if (is_null($this->slice_url)) {
            $this->slice_url = DB_AA::select1('slice_url', "SELECT slice_url FROM `module`", [['id', $this->getProp('alerts_module_id'), 'l' ]]);
            //GetTable2Array('SELECT slice_url FROM module WHERE id=\''.q_pack_id($alerts_id).'\'', 'aa_first', 'slice_url');
        }
        return $this->slice_url;
    }

    /**
     * @param null $how_often
     * @return zids
     */
    public function getReaders($how_often = null): zids
    {
        if (!($slice          = $this->getReaderSlice())) {
            return new zids();
        }
        $field_howoften = getAlertsField(FIELDID_HOWOFTEN, $this->id);

        $aa_set         = new AA_Set($slice->getId(), new AA_Condition(FIELDID_MAIL_CONFIRMED, '==', 1));
        if (!is_null($how_often)) {
            $aa_set->addCondition(new AA_Condition($field_howoften, '==', $how_often));
        }

        return $aa_set->query();
    }

    public function getReadersSelectArray($how_often = null): array
    {
        $ret = [];
        $content4ids = GetItemContent($this->getReaders($how_often), false, true, [FIELDID_USERNAME, FIELDID_EMAIL]);
        foreach ($content4ids as $reader_id => $content4id) {
            $ret[$reader_id] = trim($content4id[FIELDID_USERNAME][0]['value'] . ' (' . $content4id[FIELDID_EMAIL][0]['value'] . ')');
        }
        return $ret;
    }

    /**
     * @param string        $ho = how often
     * @param string|array  $emails (email, email, ...)
     *                              If set to "all", all Alerts users are processed.
     * @param bool $update    decides whether alerts_filter_howoften.last would be updated,
     *                        i.e. whether this is only a debug trial or the real life
     * @param string $item_id
     * @param null   $reader_id
     * @return int - count of emails sent
     */
    public function sendEmails($ho, $emails, $update, $item_id, $reader_id): int
    {

        // allow to access Reader data (and the data of all the slices with the same password)
        AA_Credentials::singleton()->loadFromSlice($this->getProp('reader_slice_id'));

        $GLOBALS['apc_state']['xlang'] = substr(AA_Module_Alerts::getModule($this->getProp('alerts_module_id'))->getLang(), 0, 2);

        // get array of all filters of current collection ($collection_id)
        // !if $update is set (default), then it updates date for lastsent - for
        // collections
        $unordered_filters = $this->createFilterText($ho, $update, $item_id);
        $email_count       = 0;

        // find filters for this collection
        $filters = [];
        foreach ($this->getFilterIds() as $filter_id) {
            $filters[$filter_id] = &$unordered_filters[$filter_id];
        }
        $aliases    = [];

        // Find all users who should receive anything
        if (!is_array($emails) || !count($emails)) {

            // get all confirmed users for this collection and frequency
            $zids = is_null($reader_id) ? $this->getReaders($ho) : new zids($reader_id, 'l');
            // a collection with no readers writes no row: every 5 minutes x every
            // collection at zero was 12 % of the whole log on geoinformace
            if ($zids->count() > 0) {
                AA_Log::write("ALERTS", $ho, new AA_Logentry_Alerts(['phase' => 'users', 'collection' => $this->id, 'count' => (int) $zids->count()]));
            }

            $readerContent = new ItemContent();

            // loop through readers might want to send
            for ($i = 0, $zcount = $zids->count(); $i < $zcount; $i++) {
                $readerContent->setByItemID($zids->longids($i));

                $user_text = $this->getFilterText4Reader($readerContent, $filters);

                // Don't send if nothing new emerged
                if ($user_text) {

                    $als = new AA_Aliases();
                    $als->addTextAlias("_#FILTERS_", $user_text);
                    $als->addTextAlias("_#HOWOFTEN", $ho);
                    $als->addTextAlias("_#COLLFORM", get_url($this->getSliceUrl(), ['ac' => $readerContent->getValue(FIELDID_ACCESS_CODE)]));
                    $als->addTextAlias("_#UNSBFORM", get_url($this->getSliceUrl(), ['au' => $readerContent->getValue(FIELDID_ACCESS_CODE),'c' => $this->id]));

                    $reader_slice = $this->getReaderSlice();
                    $aliases      = array_merge($reader_slice->aliases(), $als->getArray());
                    $item         = new AA_Item($readerContent, $aliases);

                    if (AA_Mail::sendTemplate($this->getProp('email_id_alert'), $readerContent->getValue(FIELDID_EMAIL), $item)) {
                        AA_Log::write("ALERTS", $ho, new AA_Logentry_Alerts(['phase' => 'queued', 'collection' => $this->id, 'detail' => $readerContent->getValue(FIELDID_EMAIL)]));
                        $email_count++;
                    }
                }
            }

            // Use the emails sent as param
        } else {
            AA_Log::write("ALERTS", $ho, new AA_Logentry_Alerts(['phase' => 'emails', 'collection' => $this->id, 'count' => count($emails)]));
            $als = new AA_Aliases();
            //          $als->addAlias(new AA_Alias("_#FILTERS_", "id..............", 'f_t', array(get_filter_text_4_reader(null, $filters, $this->id))));
            $als->addTextAlias("_#FILTERS_", $this->getFilterText4Reader(null, $filters));
            $als->addTextAlias("_#HOWOFTEN", $ho);
            $als->addTextAlias("_#COLLFORM", get_url($this->getSliceUrl(), ['ac' => 'ABCDE']));
            $als->addTextAlias("_#UNSBFORM", get_url($this->getSliceUrl(), ['au' => 'ABCDE','c' => $this->id]));
            $item = new AA_Item('', $als);
            foreach ((array) $emails as $email) {
                if (AA_Mail::sendTemplate($this->getProp('email_id_alert'), $email, $item)) {
                    $email_count++;
                }
            }
            $aliases = $als->getArray();
        }
        // nothing sent = nothing to record per collection; the per-run `start` and `sent`
        // rows in modules/alerts/alerts.php still show that the sweep itself ran
        if ($email_count > 0) {
            AA_Log::write("ALERTS", $ho, new AA_Logentry_Alerts(['phase' => 'sent', 'collection' => $this->id, 'count' => $email_count]));
            AA_Log::write("ALERTS", $ho, new AA_Logentry_Alerts(['phase' => 'debug', 'collection' => $this->id, 'detail' => implode('; ', [
                $this->getProp('email_id_alert'),
                implode(',', (array) $emails),
                implode(',', $aliases),
            ])]));
        }
        return $email_count;
    }

    /**  Creates Filter output. Called from sendEmails().
    *    Finds and formats items.
    *    WARNING:  the function does not check when were the items sent last time,
    *              call it only once a day / week / month. In fact, this function
    *              works completely the same for any how often (only that it writes
    *              the values to the appropriate field).
    *
    * The only exception are the "instant" and "irregular" messages: If the paramter $item_id
    * contains an unpacked item ID, nothing or a message containing just that item is sent.
    *
    * @param bool $update    decides whether alerts_filter_howoften.last would be updated,
    *                        i.e. whether this is only a debug trial or the real life
    * @param $ho             = how often
    * @param $item_id        = if only one item should be send (instant alerts)
    * @return array ($filterid => new items)
    */
    public function createFilterText($ho, $update, $item_id): array
    {

        // the view.aditional field stores info about grouping by selections
        $SQL = "
        SELECT F.conds, view.slice_id, view.aditional, view.aditional3,
            F.id AS filterid, F.vid, slice.name AS slicename,
            slice.lang_file, CH.last
            FROM alerts_filter F INNER JOIN
                 alerts_collection_filter CF ON CF.filterid = F.id INNER JOIN
                 alerts_collection_howoften CH ON CF.collectionid = CH.collectionid INNER JOIN
                 view ON view.id = F.vid INNER JOIN
                 slice ON slice.id = view.slice_id ";
        // Values through makeWhere, not into the string: $ho comes from POST on the admin
        // "send now" forms and $this->id from a remote request (central/responder.php).
        $where = [['CH.howoften', $ho, 's'], ['CH.collectionid', $this->id, 's']];
        $db = getDB();
        if ($item_id) {
            $db->query("SELECT slice_id FROM item WHERE id=" . xpack_id($item_id));
            if (!$db->next_record()) {
                freeDB($db);
                return [];
            }
            $where[] = ['slice.id', $db->f("slice_id"), 's'];
        }

        // fill alerts_collection_howoften.last in cases the row for this period
        // (= how_often) not exist, yet
        initialize_last();
        $slices = [];
        $last   = 0;

        $db->query($SQL . DB_AA::makeWhere($where));
        while ($db->next_record()) {
            $last                                  = (int) $db->f("last");   // goes into the item query unquoted
            $sid                                   = unpack_id($db->f("slice_id"));
            $slices[$sid]["name"]                  = $db->f("slicename");
            $slices[$sid]["lang"]                  = substr($db->f("lang_file"), 0, 2);
            $myview                                = &$slices[$sid]["views"][$db->f("vid")];
            $myview["filters"][$db->f("filterid")] = ["conds" => $db->f("conds")];
            // Group by selections?
            $myview["group"] = $db->f("aditional");
            if (! $myview["group"]) {
                // Sort variable for the whole view
                $myview["sort"] = $db->f("aditional3");
            }
        }
        if (!$slices) {
            freeDB($db);
            return [];
        }

        // first I create a hierarchical array $slices and than use it instead of the recordset

        $now    = time();
        $retval = [];

        // A sweep that has not run for ages must not mail the whole backlog (2026-09-22).
        // Capping drops items silently, so say so - that incident was read out of this log.
        $earliest = AlertsSweep::earliestBacklog($ho, $now);
        if ($last < $earliest) {
            AA_Log::write('ALERTS', $ho, new AA_Logentry_Alerts(['phase' => 'capped', 'collection' => $this->id,
                'detail' => 'last ' . date('Y-m-d H:i', $last) . ' -> ' . date('Y-m-d H:i', $earliest)]));
            $last = $earliest;
        }

        foreach ($slices as $slice_id => $slice) {
            $p_slice_id   = xpack_id($slice_id);

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
               to Active bin or when it is a new item inserted into Active bin.
           The field is cleared whenever the item is moved from Active bin
               to another one.
            */

            $SQL = "SELECT id FROM item ";

            if ($item_id) {
                $SQL .= "WHERE id = " . xpack_id($item_id);
            } else {
                $SQL
                .= "WHERE slice_id = $p_slice_id AND
                       publish_date <= $now AND expiry_date >= $last "  // a) 2. and b) 2.
                   . "AND ((moved2active >= $last AND moved2active < $now) "  // a) 1. half-open: defer the current (possibly still-open) second to the next sweep so a boundary item isn't sent twice
                         . "OR (moved2active < $last "                   // b) 1.
                             . "AND publish_date > $last) "              // b) 3.
                       . ")";
            }

            $db->query($SQL);

            $all_ids = [];
            while ($db->next_record()) {
                $all_ids[] = $db->f("id");
            }

            foreach ($slice["views"] as $vid => $view) {
                foreach ($view["filters"] as $fid => $filter) {
                    parse_str($filter["conds"], $dbconds_arr);
                    $conds = $dbconds_arr['conds'];
                    $sort  = $dbconds_arr['sort'];
                    $zids  = new zids(null, "p");
                    if ($all_ids) {
                        // find items for the given filter
                        $zids = QueryZids([$slice_id], $conds, $sort, "ACTIVE", 0, new zids($all_ids, 'p'));
                    }

                    $retval[$fid] = [
                        "group" => $view["group"],
                        "sort"  => $view["sort"],
                        "vid"   => $vid,
                        "zids"  => $zids,
                    ];
                }
            }
        }

        if ($update) {
            $varset = new CVarset();
            $varset->addkey("howoften", "text", $ho);
            $varset->addkey("collectionid", "text", $this->id);
            $varset->add("last", "number", $now);
            $db->query($varset->makeINSERTorUPDATE("alerts_collection_howoften"));
        }

        //print_r ($retval);
        freeDB($db);
        return $retval;
    }

    /** Used in send_emails(). Finds filter text for the given reader.
     *   There are two modes: group by Selection and don't group. The
     *   second one is more difficult.
     * @param ItemContent|null $readerContent if null, use all filters
     * @return string
     */
    public function getFilterText4Reader($readerContent, array $filters): string
    {
        $user_filters = [];
        if ($readerContent) {
            $user_filters_value = $readerContent->getValues(getAlertsField(FIELDID_FILTERS, $this->id));

            if (empty($user_filters_value)) {
                return "";
            }

            foreach ($user_filters_value as $user_filter) {
                // filter numbers are stored with "f" added to the beginning
                $user_filters[substr($user_filter['value'], 1)] = 1;
            }
        }

        $last_fprop = [];
        $filter_ids = [];  // $filter_ids = "";   // changed to array() for php 7.1 - Jirka
        $user_zids  = new zids(null, "p");

        // add dummy filter
        $filters[99999] = ["dummy" => true];
        $user_text = '';

        foreach ($filters as $filterid => $fprop) {
            // Send items from filters with "group" not set when the view changes.
            if ($last_fprop["vid"] != $fprop["vid"] && $filter_ids && !$user_zids->isEmpty()) {
                // WARNING - HACK: we need to sort the zids according to the common
                // sort[]: we use the same global trick as in createFilterText
                if ($last_fprop["sort"]) {
                    parse_str($last_fprop["sort"], $dbconds_arr);
                    $sort      = $dbconds_arr['sort'];
                    $set       = get_view_settings_cached($last_fprop["vid"]);
                    $user_zids = QueryZids([unpack_id($set["info"]->f("slice_id"))], "", $sort, "ACTIVE", 0, $user_zids);
                }

                $user_text .= get_filter_output_cached($last_fprop["vid"], implode(",", $filter_ids), $user_zids);

                // $filter_ids = "";   // changed to array() for php 7.1 - Jirka
                $filter_ids = [];
                $user_zids->clear("p");
            }
            if ($fprop["dummy"]) {
                break;
            }
            if (! $readerContent || $user_filters[$filterid]) {
                if ($fprop["group"]) {
                    $user_text .= get_filter_output_cached($fprop["vid"], $filterid, $fprop["zids"]);
                } else {
                    $user_zids->union($fprop["zids"]);
                    $filter_ids[] = $filterid;
                }
                $last_fprop = $fprop;
            }
        }
        return $user_text;
    }

    /** get_email_types function
     *  List of email types with translated description.
     *  You should never list email types directly, always call this function.
     */
    public static function get_email_types(): array
    {
        return [
            "alerts alert"         => _m("alerts alert"),
            "alerts welcome"       => _m("alerts welcome"),
            "slice wizard welcome" => _m("slice wizard welcome"),
            "user template"        => _m("user template"),
            "password change"      => _m("password change"),
            "other"                => _m("other"),
        ];
    }

    /**
     *
     */
    public static function getEmailAliasesHelpbox(): string
    {
        $ali   = [];
        $ali[] = [
            "group"   => _m("Aliases for Alerts Alert"),
            "aliases" => [
                "_#FILTERS_" => _m("complete filter text"),
                "_#HOWOFTEN" => _m("howoften") . " (" . implode(", ", get_howoften_options()) . ")",
                "_#COLLFORM" => _m("Anonym Form URL (set in Alerts Admin - Settings)"),
                "_#UNSBFORM" => _m("Unsubscribe Form URL"),
            ],
        ];

        $ali[] = [
            "group"   => _m("Aliases for Alerts Welcome"),
            "aliases" => [
                "_#HOWOFTEN" => _m("howoften") . " (" . implode(", ", get_howoften_options()) . ")",
                "_#COLLFORM" => _m("Collection Form URL (set in Alerts Admin - Settings)"),
                "_#CONFIRM_" => _m("email confirmed"),
            ],
        ];

        // these aliases are used in include/slicewiz.php
        $ali[] = [
            "group"   => _m("Aliases for Slice Wizard Welcome"),
            "aliases" => [
                "_#SLICNAME" => _m("Slice name"),
                "_#NAME____" => _m("New user name"),
                "_#LOGIN___" => _m("New user login name"),
                "_#ROLE____" => _m("New user role (editor / admin)"),
                "_#ME_NAME_" => _m("My name"),
                "_#ME_MAIL_" => _m("My email"),
            ],
        ];

        $ali[] = [
            "group"   => _m("Aliases for User Templates (you can use also all aliases of the user)"),
            "aliases" => [
                "_#BODYTEXT" => _m("Slice name"),
                "_#SUBJECT_" => _m("New user name"),
            ],
        ];

        $ali[] = [
            "group"   => _m("Aliases for Password Change email (you can use also all aliases of the user)"),
            "aliases" => [
                "_#PWD_LINK" => _m("HTML link to the password change page for current user"),
                "_#PWD_URLP" => _m('URL parameter used for password change link in _#PWD_LINK (you can use it, if you want to use your own url for it)'),
            ],
        ];


        $ret = "<table>";
        foreach ($ali as $aligroup) {
            $ret .= "<tr><th colspan=\"2\">" . $aligroup['group'] . "</th></tr>";
            foreach ($aligroup["aliases"] as $alias => $desc) {
                $ret .= "<tr><td>&nbsp;$alias&nbsp;</td><td>&nbsp;$desc&nbsp;</td></tr>";
            }
        }
        $ret .= "</table>";
        return $ret;
    }
}

// -------------------------------------------------------------------------------------
/** Sends emails to all or chosen users, from all or chosen collections.
 *
 * @param string $ho = how often
 * @param array  $collection_ids (collection_id, collection_id, ...).
 *            If set to "all", all collections are processed.
 * @param string|array  $emails (email, email, ...)
 *            If set to "all", all Alerts users are processed.
 * @param bool   $update
 * @param string $item_id
 * @param null   $reader_id
 * @return int - count of emails sent
 */
function send_emails($ho, $collection_ids, $emails, $update, $item_id, $reader_id = null): int
{
    // $ho reaches the SQL below unescaped and comes straight from POST on the admin
    // "send now" forms (modules/alerts/send.php), so only the known frequencies pass.
    // is_string() first: isset($arr[$array]) is itself a TypeError.
    if (!is_string($ho) || !isset(get_howoften_options()[$ho])) {
        return 0;
    }

    /* get all (or just some, if $collection_ids specified) collections and put
       the infop into $colls array */
    if (!is_array($collection_ids)) {
        $collection_ids = GetTable2Array('SELECT alerts_collection.id FROM alerts_collection, module WHERE module.id=alerts_collection.module_id AND module.deleted<1', 'NoCoLuMn', 'id');
    }

    $total_emails = 0;
    if (is_array($collection_ids)) {
        foreach ($collection_ids as $collection_id) {
            $collection = new AA_Collection($collection_id);
            $total_emails += $collection->sendEmails($ho, $emails, $update, $item_id, $reader_id);

        }
    }
    return $total_emails;
}

// -------------------------------------------------------------------------------------
/**  Initializes the "last" field (which tells when were the Alerts last time sent)
*   for all unfilled filter / howoften combinations
*   to a value depending on howoften, i.e. to one week ago for weekly etc.
*/
function initialize_last(): void
{
    $now = getdate();

    $init["instant"] = time();
    // one day ago
    $init["daily"]     = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday'] - 1);
    // one week (7 days) ago
    $init["weekly"]    = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday'] - 7);
    // two week (14 days) ago
    $init["twoweeks"]  = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'], $now['mday'] - 14);
    // one month ago
    $init["monthly"]   = mktime($now['hours'], $now['minutes'], $now['seconds'], $now['mon'] - 1);
    $init["irregular"] = 0;

    $db  = getDB();
    $db2 = getDB();

    $hos = get_howoften_options();
    foreach ($hos as $ho => $v) {
        // fill alerts_collection_howoften.last in cases the row for this period
        // (= how_often) not exist, yet
        $db->query("SELECT C.id FROM alerts_collection C LEFT JOIN
            alerts_collection_howoften CH ON CH.collectionid = C.id AND howoften='$ho'
            WHERE last IS NULL");
        while ($db->next_record()) {
            $db2->query("INSERT INTO alerts_collection_howoften (collectionid, howoften, last)
                VALUES ('" . $db->f("id") . "', '$ho', " . $init[$ho] . ")");
        }

        // the same as above, but for rows, which exist but with last=0
        $db->query("SELECT C.id FROM alerts_collection C INNER JOIN
            alerts_collection_howoften CH ON CH.collectionid = C.id
            WHERE last=0 AND howoften='$ho'");
        while ($db->next_record()) {
            DB_AA::update('alerts_collection_howoften', [['last',$init[$ho],'i']], [
                ['collectionid', $db->f("id")],
                ['howoften', $ho],
            ]);
            // $db2->query("UPDATE alerts_collection_howoften SET last=".$init[$ho]
            //     ." WHERE collectionid=".$db->f("id")." AND howoften='$ho'");
        }
    }
    freeDB($db);
    freeDB($db2);
}

// -------------------------------------------------------------------------------------

function get_view_settings_cached($vid)
{
    static $cached_view_settings;

    if (!$vid) {
        return "";
    }
    if (!$cached_view_settings[$vid]) {
        $view         = AA_Views::getViewNumeric($vid);
        $slice        = AA_Slice::getModule($module_id = unpack_id($view->f("slice_id")));
        $cached_view_settings[$vid] = [
            "lang"    => substr($slice->getProperty('lang_file'), 0, 2),
            "info"    => $view,
            "aliases" => new AA_Aliases(new AA_Aliasgenerator_Slice($module_id)),
            "format"  => $view->getViewFormat(),
        ];
    }
    return $cached_view_settings[$vid];
}

// -------------------------------------------------------------------------------------
/** Warning: This function caches all combinations of selections. If there
 *   are too many different combinations, memory may be exhausted.
 *
 *   TODO: Rewrite this function using a DB table.
 *
 * @param string $filter_settings One or more filter IDs separted by comma ",".
 * @return mixed|null|string|string[]|void
 */
function get_filter_output_cached($vid, $filter_settings, $zids)
{
    static $cached_filter_outputs;

    if ($zids->isEmpty()) {
        return "";
    }
    if (!isset($cached_filter_outputs[$filter_settings])) {
        $set = get_view_settings_cached($vid);
        // set language
        mgettext_bind($set["lang"], 'alerts', true);

        // $set["info"]["aditional2"] stores item URL
        $item_url = $set["info"]->f("aditional2");

        if (! $item_url) {
            $item_url = "You didn't set the item URL in the view $vid settings!";
        }
        $itemview   = new itemview($set["format"], $set["aliases"], $zids, 0, $set["info"]->f("listlen"), $item_url);
        $items_text = $itemview->get_output("view");
        //if (! strstr ($filter_settings, ","))
        $cached_filter_outputs[$filter_settings] = $items_text;
    } else {
        $items_text = $cached_filter_outputs[$filter_settings];
    }
    return $items_text;
}

// -------------------------------------------------------------------------------------
