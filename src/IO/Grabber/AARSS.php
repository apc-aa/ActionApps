<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:04
 */

namespace AA\IO\Grabber;

use AA_Item;
use AA_Log;
use AA_Slice;
use ItemContent;
use LastEditList;

/** Saver / AbstractGrabber API
 *
 *   Defines AA\IO\AbstractGrabber\AbstractGrabber base class, which is used as abstraction for data input
 *   From this class we derive concrete data grabbers, like RSS, AARSS, CSV, ...
 */
class AARSS extends AbstractGrabber
{
    protected $feed_id;
    protected $feed;
    protected $name;
    protected $slice_id;
    protected $aa_rss;
    protected $channel;
    protected $map;
    protected $cat_field_id;
    protected $status_code_id;
    protected $ext_categs;
    protected $l_categs;
    protected $r_slice_id;
    protected $fire;

    /** AA\IO\AARSS function
     * @param $feed_id
     * @param $feed
     * @param $fire
     */
    function __construct($feed_id, $feed, $fire) {

        /** Process one feed and returns parsed RSS (both AA and other)
         *  in $this->aa_rss */
        $this->slice_id = unpack_id($feed['slice_id']);        // local slice id
        $this->r_slice_id = unpack_id($feed['remote_slice_id']); // remote slice id
        $this->feed_id = $feed_id;
        $this->feed = $feed;
        $this->fire = $fire;
    }

    /** name function
     *
     */
    public function name(): string {
        return _m("AA RSS");
    }

    /** description function
     *
     */
    public function description(): string {
        return _m("Grabs data from generic RSS or AA RSS (used for item exchange between different AA installations)");
    }

    /** setUrl function
     * @param $url
     */
    function setUrl($url) {
        $this->feed['server_url'] = $url;
    }

    /** setTime function
     * @param $time
     */
    function setTime($time) {
        $this->feed['newest_item'] = $time;
    }

    /** _getRssData function
     *
     */
    function _getRssData() {
        return trim(http_fetch($this->feed['server_url']), "\xef\xbb\xbf \t\n\r\0\x0B"); // remove also BOM (the first three characters)
    }

    /** _getApcData function
     *
     */
    function _getApcData() {
        // for APC feeds we need to list all categories, which we want receive
        $this->ext_categs = GetExternalCategories($this->feed_id); // we will need it later

        // select external categories in format
        // array('unpacked_cat_id'=> array( 'value'=>, 'name'=>, 'approved'=>, 'target_category_id'=>))
        $cat_ids = [];
        if ($this->ext_categs AND is_array($this->ext_categs)) {
            foreach ($this->ext_categs as $ext_cat_id => $ext_cat) {
                if ($ext_cat['target_category_id']) {  // the feeding is set for this categor
                    $cat_ids[] = $ext_cat_id;
                }
            }
        }

        /* Mention, that $cat_ids now contain also AA_Other_Categor, which is
           used on oposite site of feeding as command to send all categories.
           The category list is sent from historical reasons (AA before 2.8
           do not send category informations without this array().
           Current AA use another approach for APC feeds - we will get all items
           regardless on category. The filtering we will do after that.
           This approach means more data to be transfered, but on the other hand
           there is no need to update filters after any category addition
           (Honzam 04/26/04) */

        // now we have cat_ids[] array => we can ask for data
        $categories2fed = implode(" ", $cat_ids);

        return xml_fetch($this->feed['server_url'], ORG_NAME, $this->feed['password'], $this->feed['user_id'], $this->r_slice_id, $this->feed['newest_item'], $categories2fed);
    }

    /** _getExactData function
     *
     */
    function _getExactData() {
        global $debugfeed;

        // get local item list (with last edit times)
        $slice = AA_Slice::getModule($this->slice_id);
        $local_list = new LastEditList();
        $local_list->setFromSlice('', $slice);  // no conditions - all items
        $local_pairs = $local_list->getPairs();

        if ($debugfeed > 8) {
            huhl('_getExactData() - Local pairs:', $local_pairs);
        }

        $base = [];
        $base["node_name"] = ORG_NAME;
        $base["password"] = $this->feed['password'];
        $base["user"] = $this->feed['user_id'];
        $base["slice_id"] = $this->r_slice_id;
        $base["start_timestamp"] = $this->feed['newest_item'];
        $base["exact"] = 1;

        $init = $base;
        $init['conds[0][last_edit.......]'] = 1;
        $init['conds[0][value]'] = iso8601_to_unixstamp($this->feed['newest_item']);
        $init['conds[0][operator]'] = '>=';

        $remote_list = new LastEditList();
        $remote_list->setList(http_fetch($this->feed['server_url'], $init));
        $remote_pairs = $remote_list->getPairs();

        if ($debugfeed > 8) {
            huhl('_getExactData() - Remote pairs:', $remote_pairs);
        }

        // Get all ids, which was updated later than items in local slice
        $ids = [];   // initialize
        foreach ($remote_pairs as $id => $time) {
            if (!isset($local_pairs[$id]) OR ($local_pairs[$id] < $time)) {
                $ids[] = $id;  // array of ids to ask for
            }
        }

        if ($debugfeed >= 2) {
            huhl(' Local items: ', count($local_pairs), ' Remote items: ', count($remote_pairs), ' Asked for update: ', count($ids));
        }

        // No items to fed?
        if (count($ids) <= 0) {
            return '';
        }

        $finish = $base;
        $finish['ids'] = implode('-', $ids);

        if ($debugfeed > 8) {
            huhl('_getExactData() - http_fetch:', $this->feed['server_url'], $finish);
        }

        return http_fetch($this->feed['server_url'], $finish);
    }

    /** prepare function
     *  Fetch data and parse it
     */
    function prepare() {
        global $DEFAULT_RSS_MAP, $debugfeed;

        // just shortcut
        $feed_type = $this->feed['feed_type'];

        set_time_limit(240); // Allow 4 minutes per feed

        $slice = AA_Slice::getModule($this->slice_id);
        $feed_debug_name = 'Feed #' . $this->feed_id . ' (' . getFeedTypeName($feed_type) . '): ' .
            $this->feed['name'] . ' : ' . $this->feed['remote_slice_name'] .
            ' -> ' . $slice->getName();
        // Get XML Data
        if ($debugfeed >= 1) {
            print("\n<br>$feed_debug_name");
        }

        switch ($feed_type) {
            case FEEDTYPE_RSS:
                $xml_data = $this->_getRssData();
                break;
            case FEEDTYPE_EXACT:
                $xml_data = $this->_getExactData();
                break;
            case FEEDTYPE_APC:
            default:
                $xml_data = $this->_getApcData();
                break;
        }

        // Special option - it only dispays fed data
        if ($this->fire == 'display') {
            echo $xml_data;
            return false;
        }

        if (!$xml_data) {
            AA_Log::write("CSN", '', "No data returned for $feed_debug_name");
            if ($debugfeed >= 1) {
                print("\n<br>$feed_debug_name: no data returned");
            }
            return false;
        }
        if ($debugfeed >= 8) {
            huhl("Fetched data=", myspecialchars($xml_data));
        }

        // if an error occured, write it to the LOG
        if (($first_char = substr($xml_data, 0, 1)) != "<") {
            AA_Log::write("CSN", '', "Feeding mode ($feed_debug_name): $xml_data");
            if ($debugfeed >= 1) {
                print("\n<br>$feed_debug_name:bad data returned (first character &quot;$first_char&quot; (ord=" . ord($first_char) . ") is not &quot;&lt;&quot;):$xml_data");
            }
            return false;
        }

        /** $g_slice_encoding is passed to aa_rss_parse() - it defines output character encoding */
        $GLOBALS['g_slice_encoding'] = $slice->getCharset();

        if (!($this->aa_rss = aa_rss_parse($xml_data))) {
            AA_Log::write("CSN", '', "Feeding mode ($feed_debug_name): Unable to parse XML data");
            if ($debugfeed >= 1) {
                print("\n<br>$feed_debug_name:" . $this->feed['server_url'] . ":unparsable: <hr>" . myspecialchars($xml_data) . "<hr>");
            }
            return false;
        }

        if ($debugfeed >= 5) {
            print("\n<br>Parses ok");
        }

        //  --- output parsed - great! - we are going to store

        $this->l_categs = GetGroupConstants($this->slice_id);       // category definitions
        // - used only for FEEDTYPE_APC

        if ($feed_type == FEEDTYPE_APC) {
            // Update the slice categories in the ef_categories table,
            // that is, if the set of possible slice categories has changed
            updateCategories($this->feed_id, $this->l_categs, $this->ext_categs, $this->aa_rss['channels'][$this->r_slice_id]['categories'], $this->aa_rss['categories']);
        }

        if (($feed_type == FEEDTYPE_APC) OR ($feed_type == FEEDTYPE_EXACT)) {
            //Update the field names and add new fields to feedmap table
            updateFieldsMapping($this->slice_id, $this->r_slice_id, $this->aa_rss['channels'][$this->r_slice_id]['fields'], $this->aa_rss['fields']);
        }

        // Find channel definition
        if (!($this->channel = $this->aa_rss['channels'][$this->r_slice_id])) {
            foreach ($this->aa_rss['channels'] as $ch) {
                if ($this->channel = $ch) {  // assignment
                    break;
                }
            }
        }

        [, $map] = GetExternalMapping($this->slice_id, $this->r_slice_id);
        if ($debugfeed >= 7) {
            print("\n<br>Mapping: $map, $this->slice_id, $this->r_slice_id");
        }
        if (!$map && ($feed_type == FEEDTYPE_RSS)) {
            if ($debugfeed >= 2) {
                print("\n<br>using default mapping");
            }
            $map = $DEFAULT_RSS_MAP;
        }
        $this->map = $map;

        // Use the APC specific fields from the item
        if ($feed_type == FEEDTYPE_APC) {
            $this->cat_field_id = GetBaseFieldId($this->aa_rss['fields'], "category");
            $this->status_code_id = GetBaseFieldId($this->aa_rss['fields'], "status_code");
        }

        if (is_array($this->aa_rss['items'])) {
            reset($this->aa_rss['items']);
        }

    }

    /** getItem function
     *
     */
    function getItem() {
        if (!is_array($this->aa_rss['items'])) {
            return false;
        }

        if (!($item = current($this->aa_rss['items']))) {
            return false;
        }
        $item_id = key($this->aa_rss['items']);

        // A series of steps to make field specific edits
        // set fulltext field back from the content field, where it was put by
        // APC for RSS compatability
        if ($fulltext_field_id = GetBaseFieldId($this->aa_rss['fields'], "full_text")) {
            $item['fields_content'][$fulltext_field_id][0] = contentvalue($item);
        }

        /** Apply filters - rename categories and bin (approved/holding/trash) */
        if ($this->feed['feed_type'] == FEEDTYPE_APC) { // Use the APC specific fields from the item

            // apply categories mapping. $item is updated accordingly
            $approved = translateCategories($this->cat_field_id, $item, $this->ext_categs, $this->l_categs);

            // set status_code - according to the settings of ef_categories table
            // RSS feeds have approved set from DEFAULT_RSS_MAP
            $item['fields_content'][$this->status_code_id][0]['value'] = $approved ? 1 : 2;
        }


        // create item from source data (in order we can unalias)
        $item2fed = new AA_Item($item['fields_content'], []);

        foreach ($this->map as $to_field_id => $v) {
            switch ($v['feedmap_flag']) {
                case FEEDMAP_FLAG_VALUE:
                    // value could contain {switch()} and other {constructs}
                    $content4id[$to_field_id][0]['value'] = $item2fed->unalias($v['value']);
                    break;
                case FEEDMAP_FLAG_EXTMAP:   // Check this really works when val in from_field_id
                case FEEDMAP_FLAG_RSS:
                    $values = map1field($v['value'], $item, $this->channel);
                    if (isset($values) && is_array($values)) {
                        foreach ($values as $k => $v2) {
                            $values[$k]['value'] = $v2['value'];
                        }
                        $content4id[$to_field_id] = $values;
                    }
                    break;
            } // switch
        }

        next($this->aa_rss['items']);

        $ic = new ItemContent($content4id);
        $ic->setValue('externally_fed..', $this->feed['name']);  // TODO - move one layer up - to saver transactions
        $ic->setItemID($item_id);
        return $ic;
    }

    /** finish function
     *
     */
    function finish() {
        if ($this->feed['feed_type'] == FEEDTYPE_APC) {
            $db = getDB();
            //update the newest item
            $SQL = "UPDATE external_feeds SET newest_item='" . quote($this->aa_rss['channels'][$this->r_slice_id]['timestamp']) . "'
                     WHERE feed_id='" . quote($this->feed_id) . "'";
            $db->query($SQL);
            freeDB($db);
        }
    }
}