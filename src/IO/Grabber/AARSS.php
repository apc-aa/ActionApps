<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 13.2.19
 * Time: 11:04
 */

namespace AA\IO\Grabber;

use AA\IO\Feed;
use AA\IO\FeedUtil;
use AA_Fields;
use AA_Item;
use AA_Log;
use AA_Slice;
use ItemContent;
use AA\IO\LastEditList;

require_once __DIR__ . "/../../../include/xml_rssparse.php";

// started to rewrite xml_rssparese.php to be more object oriented
class AARSS_Parser {

    // An array mapping all the name spaces we've seen in RSS feeds to abbreviations
    // Only a few of the abbreviations are likely to be used below
    const MODULE2ABBREV = [
        "HTTP://WWW.W3.ORG/1999/02/22-RDF-SYNTAX-NS#"   => "RDF",
        "HTTP://PURL.ORG/RSS/1.0/"                      => "RSS",
        "HTTP://MY.NETSCAPE.COM/RDF/SIMPLE/0.9/"        => "RSS", //same items
        "HTTP://PURL.ORG/DC/ELEMENTS/1.1/"              => "DC",
        "HTTP://WWW.APC.ORG/RSS/AA-MODULE.HTML"         => "AA",
        "HTTP://PURL.ORG/RSS/1.0/ITEM-IMAGES/"          => "IM",   // Unused
        "HTTP://RECORDS.SOURCEFORGE.NET/SCHEMAS/RSS-META-MODULE/" => "RECORD", // Unused
        "HTTP://WWW.W3.ORG/1999/XHMTL"                  => "XHTML",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/CONTENT/"      => "CONTENT",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/SUBSCRIPTION/" => "SUBSCRIPTION",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/LINK/"         => "LINK",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/RICHEQUIV/"    => "REQV",
        "HTTP://XMLNS.COM/FOAF/0.1/"					=> "FOAF",
        "HTTP://PURL.ORG/RSS/1.0/MODULES/SYNDICATION/"  => "SYNDICATION",
        "HTTP://WEBNS.NET/MVCB/"                        => "ADMIN"
    ];

    /** nsName2abbrevname function
     * @param $name
     * @return string
     */
    public static function nsName2abbrevname($name) {
        preg_match("/(.+):([^:]+)/", $name, $nameparts);
        if ($ab = self::MODULE2ABBREV[$nameparts[1]]) {
            return $ab . ":" . $nameparts[2];
        }
        return $name;
    }

    /** nsAbbrev2name function
     * @param $abbrev
     * @return false|int|string
     */
    public static function nsAbbrev2name($abbrev) {
        return array_search($abbrev, self::MODULE2ABBREV);
    }
}




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
    public static function name(): string {
        return _m("AA RSS");
    }

    /** description function
     *
     */
    public static function description(): string {
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
        return trim(FeedUtil::http_fetch($this->feed['server_url']), "\xef\xbb\xbf \t\n\r\0\x0B"); // remove also BOM (the first three characters)
    }

    /** _getApcData function
     *
     */
    function _getApcData() {
        // for APC feeds we need to list all categories, which we want receive
        $this->ext_categs = FeedUtil::GetExternalCategories($this->feed_id); // we will need it later

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
           do not send category information without this []).
           Current AA use another approach for APC feeds - we will get all items
           regardless on category. The filtering we will do after that.
           This approach means more data to be transfered, but on the other hand
           there is no need to update filters after any category addition
           (Honzam 04/26/04) */

        // now we have cat_ids[] array => we can ask for data
        $categories2fed = implode(" ", $cat_ids);

        return FeedUtil::xml_fetch($this->feed['server_url'], ORG_NAME, $this->feed['password'], $this->feed['user_id'], $this->r_slice_id, $this->feed['newest_item'], $categories2fed);
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
        $init['conds[0][value]'] =  FeedUtil::iso8601_to_unixstamp($this->feed['newest_item']);
        $init['conds[0][operator]'] = '>=';

        $remote_list = new LastEditList();
        $remote_list->setList(FeedUtil::http_fetch($this->feed['server_url'], $init));
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

        return FeedUtil::http_fetch($this->feed['server_url'], $finish);
    }

    /**
     * @return array[]
     */
    public static function getDefaultRssMap(): array {
        return [
            // Note this matches code in xml_rssparse.php for parsing DC fields
            // Can change the names without affecting anything
            "author.........." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/creator",                                "from_field_name"=>"DC:creator"],
            "abstract........" => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"ITEM/description|DC/description|DC/subject","from_field_name"=>"Any abstract"],
            "publish_date...." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DATE(DC/date)|DATE(ITEM/pubdate)|NOW",      "from_field_name"=>"DC:date"],
            "source.........." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/source|CHANNEL/title",                   "from_field_name"=>"DC:source"],
            "lang_code......." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/language",                               "from_field_name"=>"DC:language"],
            "source_href....." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/relation|CHANNEL/link",                  "from_field_name"=>"DC:relation"],
            "place..........." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/coverage",                               "from_field_name"=>"DC:coverage"],
            "headline........" => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"DC/title|ITEM/title",                       "from_field_name"=>"DC:title"],
            "full_text......." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"CONTENT",                                   "from_field_name"=>"Content"],
            "status_code....." => ["feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>2,                                           "from_field_name"=>"Approved"],
            "hl_href........." => ["feedmap_flag"=>FEEDMAP_FLAG_RSS,  "value"=>"ITEM/link|ITEM/guid",                       "from_field_name"=>"ITEM:link"],
            "expiry_date....." => ["feedmap_flag"=>FEEDMAP_FLAG_VALUE,"value"=>(time()+2000*24*60*60),                      "from_field_name"=>"Expiry Date"]
        ];
    }

    /** loads all the data based on grabber setting and return it */
    public function getSources(): array {
        $feed_type = $this->feed['feed_type'];

        switch ($feed_type) {
            case Feed::FEEDTYPE_RSS:
                $xml_data = $this->_getRssData();
                break;
            case Feed::FEEDTYPE_EXACT:
                $xml_data = $this->_getExactData();
                break;
            case Feed::FEEDTYPE_APC:
            default:
                $xml_data = $this->_getApcData();
                break;
        }

        return [$xml_data];
    }


    /** prepare function
     *  Fetch data and parse it
     */
    function prepare() {
        global $debugfeed;

        // just shortcut
        $feed_type = $this->feed['feed_type'];

        set_time_limit(240); // Allow 4 minutes per feed

        $slice = AA_Slice::getModule($this->slice_id);
        $feed_debug_name = 'Feed #' . $this->feed_id . ' (' . Feed::getFeedTypeName($feed_type) . '): ' .
            $this->feed['name'] . ' : ' . $this->feed['remote_slice_name'] .
            ' -> ' . $slice->getName();
        // Get XML Data
        if ($debugfeed >= 1) {
            print("\n<br>$feed_debug_name");
        }

        [$xml_data] = $this->getSources();

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
            huhl("Fetched data=", safe($xml_data));
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

        // huhl($xml_data);

        if (!($this->aa_rss = aa_rss_parse($xml_data))) {
            AA_Log::write("CSN", '', "Feeding mode ($feed_debug_name): Unable to parse XML data");
            if ($debugfeed >= 1) {
                print("\n<br>$feed_debug_name:" . $this->feed['server_url'] . ":unparsable: <hr>" . safe($xml_data) . "<hr>");
            }
            return false;
        }

        if ($debugfeed >= 5) {
            print("\n<br>Parses ok");
        }

        //  --- output parsed - great! - we are going to store

        $this->l_categs = FeedUtil::GetGroupConstants($this->slice_id);       // category definitions
        // - used only for FEEDTYPE_APC

        if ($feed_type == Feed::FEEDTYPE_APC) {
            // Update the slice categories in the ef_categories table,
            // that is, if the set of possible slice categories has changed
            FeedUtil::updateCategories($this->feed_id, $this->l_categs, $this->ext_categs, $this->aa_rss['channels'][$this->r_slice_id]['categories'], $this->aa_rss['categories']);
        }

        if (($feed_type == Feed::FEEDTYPE_APC) OR ($feed_type == Feed::FEEDTYPE_EXACT)) {
            //Update the field names and add new fields to feedmap table
            FeedUtil::updateFieldsMapping($this->slice_id, $this->r_slice_id, $this->aa_rss['channels'][$this->r_slice_id]['fields'], $this->aa_rss['fields']);
        }

        // Find channel definition
        if (!($this->channel = $this->aa_rss['channels'][$this->r_slice_id])) {
            foreach ($this->aa_rss['channels'] as $ch) {
                if ($this->channel = $ch) {  // assignment
                    break;
                }
            }
        }

        [, $map] = FeedUtil::GetExternalMapping($this->slice_id, $this->r_slice_id);
        if ($debugfeed >= 7) {
            print("\n<br>Mapping: $map, $this->slice_id, $this->r_slice_id");
        }
        if (!$map && ($feed_type == Feed::FEEDTYPE_RSS)) {
            if ($debugfeed >= 2) {
                print("\n<br>using default mapping");
            }
            $map = self::getDefaultRssMap();
        }
        $this->map = $map;

        // Use the APC specific fields from the item
        if ($feed_type == Feed::FEEDTYPE_APC) {
            $this->cat_field_id = AA_Fields::getBaseFieldId($this->aa_rss['fields'], "category");
            $this->status_code_id = AA_Fields::getBaseFieldId($this->aa_rss['fields'], "status_code");
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
        if ($fulltext_field_id = AA_Fields::getBaseFieldId($this->aa_rss['fields'], "full_text")) {
            $item['fields_content'][$fulltext_field_id][0] = FeedUtil::contentvalue($item);
        }

        /** Apply filters - rename categories and bin (approved/holding/trash) */
        if ($this->feed['feed_type'] == Feed::FEEDTYPE_APC) { // Use the APC specific fields from the item

            // apply categories mapping. $item is updated accordingly
            $approved = FeedUtil::translateCategories($this->cat_field_id, $item, $this->ext_categs, $this->l_categs);

            // set status_code - according to the settings of ef_categories table
            // RSS feeds have approved set from AARSS::getDefaultRssMap()
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
                    $values = FeedUtil::map1field($v['value'], $item, $this->channel);
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
        if ($this->feed['feed_type'] == Feed::FEEDTYPE_APC) {
            $db = getDB();
            //update the newest item
            $SQL = "UPDATE external_feeds SET newest_item='" . quote($this->aa_rss['channels'][$this->r_slice_id]['timestamp']) . "'
                     WHERE feed_id='" . quote($this->feed_id) . "'";
            $db->query($SQL);
            freeDB($db);
        }
    }
}