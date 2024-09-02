<?php

namespace AA\IO;

use AA;
use AA\IO\DB\DB_AA;
use AA\Later\Toexecute;
use AA_File_Wrapper;
use itemview;

class Feed {
    public const FEEDTYPE_RSS   = 1;
    public const FEEDTYPE_APC   = 2;
    public const FEEDTYPE_EXACT = 3;
    protected $grabber;
    protected $type;
    protected $id;
    protected $destination_slice_id;

    /** @var $fire - write | test | display
     *       - write   - feed and write the items to the databse
     *       - test    - process without write anything to the database
     *       - display - only display the data from the feed
     */
    protected $fire;


    /** AA\IO\AA_Feed function
     * @param $grabber              = null
     * @param $destination_slice_id = null
     * @param $fire                 = 'write'
     */
    function __construct($grabber = null, $destination_slice_id = null, $fire = 'write') {
        $this->grabber              = $grabber;
        $this->destination_slice_id = $destination_slice_id;
        $this->fire                 = $fire;
    }

    /** loadRSSFeed function
     * @param             $id
     * @param string|null $url = null
     */
    function loadRSSFeed($id, ?string $url = null) {
        $this->id   = $id;
        $this->type = Feed::FEEDTYPE_RSS;

        //$SQL = "SELECT feed_id, server_url, name, slice_id FROM rssfeeds WHERE feed_id='$id'";
        //$feeddata                    = GetTable2Array($SQL, 'aa_first', 'aa_fields');
        $feeddata              = DB_AA::select1([], "SELECT `feed_id`, `server_url`, `name`, `slice_id` FROM rssfeeds", [['feed_id', $id,]]);
        $feeddata['feed_type'] = Feed::FEEDTYPE_RSS;
        // fictive remote slice id, but always the same for the same url
        $feeddata['remote_slice_id'] = pack_id(FeedUtil::attr2id($feeddata['server_url']));

        $this->grabber              = new AA\IO\Grabber\AARSS($id, $feeddata, $this->fire);
        $this->destination_slice_id = unpack_id($feeddata['slice_id']);

        if ($url) {
            $this->grabber->setUrl($url);
        }
    }

    /** loadAAFeed function
     * @param $id
     * @param $time = null
     */
    function loadAAFeed($id, $time = null) {
        $this->id   = $id;
        $this->type = Feed::FEEDTYPE_APC;

        // $SQL = "SELECT feed_id, password, server_url, name, slice_id, remote_slice_id, newest_item, user_id, remote_slice_name, feed_mode
        //           FROM nodes, external_feeds WHERE nodes.name=external_feeds.node_name AND feed_id='$id'";
        // $feeddata                    = GetTable2Array($SQL, 'aa_first', 'aa_fields');
        $feeddata = DB_AA::select1([], "SELECT feed_id, password, server_url, name, slice_id, remote_slice_id, newest_item, user_id, remote_slice_name, feed_mode FROM nodes, external_feeds", [
            ['nodes.name', 'external_feeds.node_name', 'j',], ['feed_id', $id,]
        ]);
        $feeddata['feed_type'] = ($feeddata['feed_mode'] == 'exact') ? Feed::FEEDTYPE_EXACT : Feed::FEEDTYPE_APC;

        $this->grabber              = new AA\IO\Grabber\AARSS($id, $feeddata, $this->fire);
        $this->destination_slice_id = unpack_id($feeddata['slice_id']);

        if ($time) {
            $this->grabber->setTime($time);
        }
    }

    /** feed function
     * Process one feed RSS, or APC AA RSS, or CSV or ... - based on AA\IO\AbstractGrabber\AbstractGrabber
     * @param string $fire - write   - feed and write the items to the databse
     *                       test    - process without using queue and write anything to the database
     *                       display - only display the data from the feed
     * @return string saver report
     */
    function feed(string $fire=''): string {
        $fire = $fire ?: $this->fire;
        $run = ($this->fire == 'write');
        $saver = new AA\IO\Saver($this->grabber, null, $this->destination_slice_id, 'by_grabber', 'by_grabber');
        // $saver->run();
        $name     = 'feed&id=' . $this->id . '&type=' . self::getFeedTypeName($this->type);
        $importer = new AA\IO\Importer($saver, $run, $name);

        $ret = '';
        switch($fire) {
            case 'test': // do not use toexecute queue
                $ret = $importer->toexecutelater();
                break;
            case 'write':
                (new Toexecute)->laterOnce($importer, [], $importer->getId(), 10); // write   - feed and write the items to the database
                $ret = 'queued';
                break;
            case 'display':
                $ret = join('',$importer->getSources());
        }
        return $ret;
    }

    /** getFeedTypeName function
     *  Translates feed type number to human readable name (1 -> RSS, ...)
     * @param $type
     * @return string
     */
    public static function getFeedTypeName($type) {
        return (($type == 1) ? 'RSS' : (($type == 2) ? 'APC' : 'EXACT'));
    }

    /** onefeed function
     *  Process one feed RSS or APC
     *  @param  $feed_id   - id of feed (it is autoincremented number from 1 ...
     *                     - RSS and APC feeds could have the same id :-(
     *  @param  $feed      - feed definition array (server_url, password, ...)
     *  @param  $debugfeed - just for debuging purposes
     *  @param  $fire      - write   - feed and write the items to the databse
     *                       test    - process without write anything to the database
     *                       display - only display the data from the feed
     *
     */
    public static function onefeed($feed_id, $feed, $debugfeed, $fire = 'write') {
        $slice_id = unpack_id($feed['slice_id']);
        if ($fire == 'write') {
            $grabber = new AA\IO\Grabber\AARSS($feed_id, $feed, $fire);
            $saver   = new AA\IO\Saver($grabber, null, $slice_id);
            $saver->run();
        }
        if ($debugfeed >= 8) {
            print("\n<br>onefeed: done");
        }
    }
}


class FeedUtil {
    public const CONTENT_FORMATS           = [
        "http://www.isi.edu/in-notes/iana/assignments/media-types/text/html"  => self::HTML,
        "http://www.isi.edu/in-notes/iana/assignments/media-types/text/plain" => self::PLAIN
    ];

    // server module's error message

    public const ERR_NO_SLICE              = "Error 1";
    public const ERR_PASSWORD              = "Error 2";
    public const HTML                      = 0;
    public const PLAIN                     = 1;
    public const UNPACKED_AA_THE_SAME_CATE = '41415f5468655f53616d655f43617465';  // unpack_id('AA_The_Same_Cate') );

    public const UNPACKED_AA_OTHER_CATEGOR = '41415f4f746865725f43617465676f72';  // unpack_id('AA_Other_Categor') );

    /**  xml_fetch function
     *   Fetch xml data from $url through http.
     *   This function is used by the rss aa module client as well as by the admin
     *   interface.
     * @param $url
     * @param $node_name - the name of the node making the request
     * @param $password - the password of the node
     * @param $user - a user at the remote node. This is the user who
     *                             is trying to establish a feed or who established
     *                             the feed
     * @param $slice_id - The id of the local slice from which a feed is
     *                             requested
     * @param $start_timestamp - a timestamp which indicates the creation time
     *                             of the first item to be sent.
     *                             (www.w3.org/TR/NOTE-datetime format)
     * @param $categories - a list of local categories ids separated by space
     *                             (can be empty)
     * @return string
     */
    public static function xml_fetch($url, $node_name, $password, $user, $slice_id, $start_timestamp, $categories) {
        $d["node_name"]       = $node_name;
        $d["password"]        = $password;
        $d["user"]            = $user;
        $d["slice_id"]        = $slice_id;
        $d["start_timestamp"] = $start_timestamp;
        $d["categories"]      = $categories;
        return self::http_fetch($url, $d);
    }

    /** http_fetch function
     *  A generic fetching routine that takes an array of params (possibly empty)
     * @param $url
     * @param $d
     * @return string
     */
    public static function http_fetch($url, $d = null) {
        if (isset($d)) {
            $param = [];
            foreach ($d as $k => $v) {
                if ($v) {
                    $param[] = rawurlencode($k) . "=" . rawurlencode($v);
                }
            }
            if ($tl = implode("&", $param)) {
                $url = $url . "?" . $tl;
            }
        }
        if ($GLOBALS['debugfeed'] >= 8) {
            print("http_fetch:$url\n");
        }
        $file = AA_File_Wrapper::wrapper($url);
        // $file->contents(); opens the stream, reads the data and close the stream
        $data = $file->contents();
        if ($GLOBALS['debugfeed'] >= 8) {
            huhl('data obtained:', $data);
        }
        return trim($data);
    }

    /** translteFeedCatid2Value function
     * @param $remote_cat_id
     * @param $remote_cat_value
     * @param $ext_categs
     * @param $l_categs
     * @param $all_categories
     * @return array
     */
    public static function translteFeedCatid2Value($remote_cat_id, $remote_cat_value, $ext_categs, $l_categs, $all_categories) {

        // we have set mapping for this category
        $ext_category_id = ((!$all_categories and isset($ext_categs[$remote_cat_id])) ?
            $remote_cat_id : self::UNPACKED_AA_OTHER_CATEGOR);

        $local_cat_id = $ext_categs[$ext_category_id]['target_category_id'];
        $approved     = $ext_categs[$ext_category_id]['approved'];

        if ($local_cat_id == FeedUtil::UNPACKED_AA_THE_SAME_CATE) {
            // we have to not rename the category - return original name
            return [$remote_cat_value, $approved];
        }

        // return local category name or empty value for "not feed"
        return [$l_categs[$local_cat_id]['value'], $approved];
    }

    /** translateCategories function
     *  Translates remote categories to local one using external ext_categs array
     * @param $cat_field_id - field id for category (category.......1)
     * @param $item - data for current item. This will be updated by the values
     *                    for new categories ( $cat_field_id )
     * @param $ext_categs - remote categories array (structure with name, value,
     *                    target_category_id, approved)
     * @param $l_categs - local categories array [id] => (name, value, parent_id)
     * @return mixed|null
     */
    public static function translateCategories($cat_field_id, &$item, $ext_categs, $l_categs) {

        $return_approved = null;

        // true if filters are set for 'All categories' option (and not separately
        // for each category)
        $all_categories = FeedUtil::UseAllCategoriesOption($ext_categs);

        // Create [id] => value array of all items categories
        if (isset($item['categories']) and is_array($item['categories'])) {
            // This categories are regular categories on remote slice, so it
            // should have value defined
            // This categories are set in special $item[categories] array
            foreach ($item['categories'] as $r_cid) {
                [
                    $new_cat,
                    $approved
                ] = FeedUtil::translteFeedCatid2Value($r_cid, $ext_categs[$r_cid]['value'], $ext_categs, $l_categs, $all_categories);
                if (is_null($return_approved)) {
                    $return_approved = $approved;
                }
                $used_values[trim($ext_categs[$r_cid]['value'])] = true; // mark it
                if ($new_cat) {
                    // create new $content4id entry for categories
                    $new_categories[] = ['value' => $new_cat];
                }
            }
        }
        if ($cat_field_id and is_array($item['fields_content'][$cat_field_id])) {
            // Now do the same also for "dirty" categories - categories, which are
            // not in the list of current remote categories (item has another than
            // listed category - strange, but obvious (due feeding, importing, ...)
            foreach ($item['fields_content'][$cat_field_id] as $r_value) {
                if (!$used_values[trim($r_value['value'])]) {
                    [
                        $new_cat,
                        $approved
                    ] = FeedUtil::translteFeedCatid2Value(self::UNPACKED_AA_OTHER_CATEGOR, $r_value['value'], $ext_categs, $l_categs, $all_categories);
                    if (is_null($return_approved)) {
                        $return_approved = $approved;
                    }
                    if ($new_cat) {
                        // create new $content4id entry for categories
                        $new_categories[] = ['value' => $new_cat];
                    }
                }
            }
        }

        // And now somethig completely different - substitute old categories with new ones
        $item['fields_content'][$cat_field_id] = $new_categories;

        return $return_approved;
    }

    /** updateCategories function
     *  Update the slice categories in the ef_categories table, that is, if the set
     *  of possible slice categories has changed
     * @param $feed_id
     * @param $l_categs
     * @param $ext_categs
     * @param $cat_refs
     * @param $categs
     */
    public static function updateCategories($feed_id, &$l_categs, $ext_categs, $cat_refs, $categs) {
        global $debugfeed;
        $db = getDB();
        // add new categories or update categories' fields
        if (isset($cat_refs) && is_array($cat_refs)) {
            foreach ($cat_refs as $r_cat_id => $foo) {
                $category = $categs[$r_cat_id];

                if ($ext_categs[$r_cat_id]) {
                    // remote category is in the ef_categories table, so update name and value
                    $SQL = "UPDATE ef_categories SET category_name='" . $category['name'] . "',
                         category='" . $category['value'] . "'
                         WHERE feed_id='$feed_id' AND category_id='" . q_pack_id($r_cat_id) . "'";
                    if ($debugfeed >= 8) {
                        print("\n<br>$SQL");
                    }
                    $db->query($SQL);
                } else {
                    $l_cat_id = FeedUtil::MapDefaultCategory($l_categs, $category['value'], $category['catparent']);
                    $SQL      = "INSERT INTO ef_categories VALUES ('" . $category['value'] . "','" . $category['name'] . "',
                         '" . q_pack_id($category['id']) . "','" . $feed_id . "','" . q_pack_id($l_cat_id) . "','0')";
                    if ($debugfeed >= 8) {
                        print("\n<br>$SQL");
                    }
                    $db->query($SQL);
                }
            }
        }

        // remove the categories from table, which were not sent
        if (isset($ext_categs) and is_array($ext_categs)) {
            foreach ($ext_categs as $r_cat_id => $foo) {
                if (isset($cat_refs[$r_cat_id])) {
                    continue;
                }
                $SQL = "DELETE FROM ef_categories WHERE feed_id='$feed_id' AND category_id='" . q_pack_id($r_cat_id) . "'";
                if ($debugfeed >= 8) {
                    print("\n<br>$SQL");
                }
                $db->query($SQL);
            }
        }
        freeDB($db);
    }

    /** updateFieldsMapping function
     *  Update the fields mapping from the remote slice to the local slice
     * @param $feed_id
     * @param $l_slice_id
     * @param $r_slice_id
     * @param $field_refs
     * @param $fields
     */
    public static function updateFieldsMapping($l_slice_id, $r_slice_id, $field_refs, $fields) {
        global $debugfeed;

        [$ext_map, $field_map] = FeedUtil::GetExternalMapping($l_slice_id, $r_slice_id);
        $p_l_slice_id = q_pack_id($l_slice_id);
        $p_r_slice_id = q_pack_id($r_slice_id);

        // add new ones
        $db = getDB();
        if (isset($field_refs) and is_array($field_refs)) {
            foreach ($field_refs as $r_field_id => $val) {
                $new_name = $fields[$r_field_id]['name'];
                if ($ext_map && $ext_map[$r_field_id]) {
                    // remote field is in the feedmap table => update name
                    if ($ext_map[$r_field_id] != $new_name) { // update if field name changed on remote AA
                        $SQL = "UPDATE feedmap SET from_field_name='" . quote($new_name) . "'
                            WHERE from_slice_id='$p_r_slice_id'
                            AND to_slice_id='$p_l_slice_id'
                            AND from_field_id='$r_field_id'";
                        if ($debugfeed >= 8) {
                            print("\n<br>$SQL");
                        }
                        $db->query($SQL);
                    }
                } else {
                    $SQL = "INSERT INTO feedmap VALUES('$p_r_slice_id','$r_field_id','$p_l_slice_id','$r_field_id','" . FEEDMAP_FLAG_EXTMAP . "','','" . quote($new_name) . "')";
                    if ($debugfeed >= 8) {
                        print("\n<br>$SQL");
                    }
                    $db->query($SQL);
                }
            }
        }
        freeDB($db);
        if (!$ext_map) {
            return;
        }
        $db = getDB();
        foreach ($ext_map as $r_field_id => $foo) {
            if (!$field_refs[$r_field_id]) {
                $SQL = "DELETE FROM feedmap WHERE from_slice_id='$p_r_slice_id'
                    AND to_slice_id='$p_l_slice_id'
                    AND from_field_id='$r_field_id'";
                if ($debugfeed >= 8) {
                    print("\n<br>$SQL");
                }
                $db->query($SQL);
            }
        }
        freeDB($db);
    }

    /** field2arr function
     * Consider value, and return array depending on whether it is HTML or not
     * Assumes that RSS1.0 will be explicit, RSS 0.9 and RSS2.0
     * should check
     * @param $field
     * @return array
     */
    public static function field2arr($field) {
        global $rss_version;
        if ((strpos($rss_version, '2.') !== false) or (strpos($rss_version, '0.9') !== false)) {
            $flag = ((strpos($field, '<') !== false) ? FLAG_HTML : "");
        } else { // Must be 1.0 which doesn't have an RSS version
            $flag = FLAG_HTML;
        }
        return ['value' => $field, 'flag' => $flag];
    }

    /** map1field function
     *  @param $value
     * @param $item
     * @param $channel
     *@return array suitable for insertion into content4id[aaa....]
     * Recognized special cases of values to tell it how to find, the field, or
     * how to interpret it.
     */

    public static function map1field($value, $item, $channel) {
        global $debugfeed;
        if ($debugfeed >= 8) {
            print("\n<br>xmlclient:map1field:$value");
        }
        if (preg_match("/(.*)\|(.*)/", $value, $vals)) {  // Process alternatives, first if non-blank else second
            $try1 = self::map1field($vals[1], $item, $channel);
            if ($try1[0]['value']) {
                return $try1;
            }
            return self::map1field($vals[2], $item, $channel);
        } elseif (preg_match("/^DATE\((.*)\)$/", $value, $vals)) { // Postprocess to turn into unix
            $try1 = self::map1field($vals[1], $item, $channel);
            if ($debugfeed >= 9) {
                huhl($try1);
            }
            if (isset($try1) && is_array($try1) && $try1[0]['value']) {
                // Often won't work cos not iso8601
                // Wed, 25 Feb 2004 17:19:37 EST   - BAD
                // 2004-02-25 17:19:37+10:00   GOOD
                $try1[0]['value'] = FeedUtil::iso8601_to_unixstamp($try1[0]['value']);
            }
            if ($try1[0]['value'] == -1) {
                $try1[0]['value'] = null;
            }
            if ($debugfeed >= 9) {
                huhl($try1);
            }
            return $try1;
        } elseif ($value == "NOW") {
            return [0 => ['value' => time(), 'flag' => 0]];
        } elseif (preg_match("~CHANNEL/(.*)~", $value, $vals)) {
            return [0 => FeedUtil::field2arr($channel[$vals[1]])];
        } elseif (preg_match("~ITEM/(.*)~", $value, $vals)) {
            return [0 => FeedUtil::field2arr($item[$vals[1]])];
        } elseif (preg_match("~DC/(.*)~", $value, $vals)) {
            // Dont believe DC fields can be HTML
            return [0 => ['value' => $item['dc'][$vals[1]], 'flag' => 0]];
        } elseif ($value == "CONTENT") {
            // Note this code is repeated above in map1field
            return [0 => FeedUtil::contentvalue($item)];
        } else {
            return $item['fields_content'][$value];
        }
    }

    /** contentvalue function
     *  Extract the content from where the parser put it, and return as a value array.
     * @param $item
     * @return array
     */
    public static function contentvalue($item) {
        $flag = "";
        if (isset($item['content'][self::HTML])) { // HTML is constant!!!
            // choose HTML content first
            $flag      = FLAG_HTML;
            $cont_flag = self::HTML;
        } else {   // otherwise PLAIN. Other formats are not supported,
            $cont_flag = self::PLAIN;      // but they can be added in future
        }
        return ["value" => $item['content'][$cont_flag], "flag" => $flag];
    }

    /** GetExternalCategories function
     *  Get categories from table ef_categories
     *  ef_categories is used only for APC type of feedin (APC RSS) and the change
     *  would by tricky, since there could unfortunatelly be two feeds with the same
     *  id (one APC and one other RSS)
     * @param $feed_id
     * @param $add_other - if there are external categories it will add default
     *                      (AA_Other_Categor) category to the array (if not present
     *                      already
     * @return array
     */
    public static function GetExternalCategories($feed_id, $add_other = false) {
        $db         = getDB();
        $ext_categs = [];
        $db->query("SELECT category_id, category, category_name, target_category_id, approved FROM ef_categories WHERE feed_id='$feed_id' ORDER BY category_name");
        while ($db->next_record()) {
            $ext_categs[unpack_id($db->f('category_id'))] = [
                "value"              => $db->f('category'),
                "name"               => $db->f('category_name'),
                "approved"           => $db->f('approved'),
                "target_category_id" => unpack_id($db->f('target_category_id'))
            ];
        }
        freeDB($db);
        if ($add_other and (count($ext_categs) > 0) and !isset($ext_categs[unpack_id('AA_Other_Categor')])) {
            $ext_categs[self::UNPACKED_AA_OTHER_CATEGOR] = [
                "value"              => 'AA_Other_Categor',
                "name"               => _m('Other categories'),
                "approved"           => false,
                "target_category_id" => ''  // default is umpapped, not UNPACKED_AA_THE_SAME_CATE
            ];
        }
        return $ext_categs;
    }

    /** UseAllCategoriesOption
     *  Returns true if filters are set for 'All categories' option
     *  (and not separately for each category)
     * @param $ext_categs (by link)
     * @return bool
     */
    public static function UseAllCategoriesOption($ext_categs) {
        foreach ($ext_categs as $k => $v) {
            if ($v['target_category_id'] and ($k != self::UNPACKED_AA_OTHER_CATEGOR)) {
                return false;
            }
        }
        return true;
    }

    /** GetExternalMapping function
     *  Get external mapping from remote slice to local slice = returns two array
     *  map_to = from_field_id -> from_field_name  (but just for fields with flag = FEEDMAP_FLAG_MAP
     *  map_from = to_field_id -> { feedmap_flag => flag, value => from_field_id|value, from_field_name
     * @param $l_slice_id
     * @param $r_slice_id
     * @return array
     */
    public static function GetExternalMapping($l_slice_id, $r_slice_id) {
        $map_to   = [];
        $map_from = [];

        $db = getDB();
        $db->query("SELECT * FROM feedmap WHERE from_slice_id='" . q_pack_id($r_slice_id) . "' AND to_slice_id='" . q_pack_id($l_slice_id) . "' ORDER BY from_field_name");
        while ($db->next_record()) {
            switch ($f = $db->f('flag')) {
                case FEEDMAP_FLAG_EXTMAP :
                case FEEDMAP_FLAG_MAP:
                    $v          = $db->f('from_field_id');
                    $map_to[$v] = $db->f('from_field_name');
                    break;
                case FEEDMAP_FLAG_JOIN:
                case FEEDMAP_FLAG_RSS:
                case FEEDMAP_FLAG_VALUE :
                    $v = $db->f('value');
                    break;
                case FEEDMAP_FLAG_EMPTY :
                    $v = "";
                    break;
            }
            $map_from[$db->f('to_field_id')] = ["feedmap_flag"    => $f,
                                                "value"           => $v,
                                                "from_field_name" => $db->f('from_field_name')
            ];
        }
        freeDB($db);
        return [$map_to, $map_from];
    }

    /** attr2id function
     * @param $str
     *
     * This function converts an attribute string to a unique id,
     * this function must: always return the same result; and not contain 00 or 27
     * the tricky part is that APC attribute strings contain a prefix and 32 digits,
     * while non APC strings need the whole string hashed.
     * @return string id
     */
    public static function attr2id($str) {
        if (preg_match("~/(items|cat|slices)/([0-9a-f]{32})~", $str, $regs)) { // Looks like an APC id
            return $regs[2]; // Maybe this should be 0 ?
        } else {
            return string2id($str);
        }
    }

    /** iso8601_to_unixstamp function
     * @param $t
     * @return false|int
     */
    public static function iso8601_to_unixstamp($t) {
        // It seems to be better to use strtotime() function instead custom parsing as
        // it supports also RFC-822 date time (e.g. "Fri, 30 Jun 2006 00:00:00 +0200")
        // used in rss feeds.
        return strtotime($t);
    }

    /** name2rssfeed function
     * @param string $rss_id as stored in DB
     * @return string identifier of remote slice created from server_url
     * @todo rewrite the concept to not rely on server_url
     */
    public static function rssid2rssfeed(int $rss_id): string {
        $server_url = DB_AA::select1('server_url', 'SELECT `server_url` FROM `rssfeeds`', [['feed_id',$rss_id,'i']]);
        return $server_url ? FeedUtil::attr2id($server_url) : '';
    }

    /** GetGroupConstants function
     *  Returns Category definitions for specified slice
     *  $cat[<unpacked_id>] = array( 'name'=> , 'value'=> 'parent_id'=> );
     * @param $slice_id
     * @return array|bool
     */
    public static function GetGroupConstants($slice_id) {
        $cat_ids   = [];
        $cat_group = GetCategoryGroup($slice_id);
        if (!$cat_group) {
            return false;
        }

        $db  = getDB();
        $SQL = "SELECT id, name, value, class FROM constant WHERE group_id = '$cat_group' ORDER BY pri";
        $db->query($SQL);         // get all categories
        while ($db->next_record()) {
            $cat_ids[unpack_id($db->f('id'))] = [
                "name"      => $db->f('name'),
                "value"     => $db->f('value'),
                "parent_id" => $db->f('class')
            ];
        }
        freeDB($db);
        return $cat_ids;
    }

    /** MapDefaultCategory function
     * @param $categories (by link)
     * @param $value
     * @param $parent_id
     * @return int|null|string
     */
    public static function MapDefaultCategory(&$categories, $value, $parent_id) {
        // try to find the same category
        foreach ($categories as $to_id => $v) {
            if ($v['value'] == $value) {
                return $to_id;
            }
        }
        // try to find the same parent category
        foreach ($categories as $to_id => $v) {
            if ($v['parent_id'] == $parent_id) {
                return $to_id;
            }
        }
        // return the first category
        reset($categories);
        return key($categories);
    }

    /** unixstamp_to_iso8601 function
     * @param $t
     * @return string
     */
    public static function unixstamp_to_iso8601($t) {
        $tz = date("Z", $t) / 60;
        $tm = $tz % 60;
        $tz /= 60;
        if ($tz < 0) {
            $ts = "-";
            $tz = -$tz;
        } else {
            $ts = "+";
        }
        $tz = substr("0" . $tz, -2);
        $tm = substr("0" . $tm, -2);
        return date("Y-m-d\TH:i:s", $t) . "$ts$tz:$tm";
    }
}

class LastEditList {
    var $lastlist = null;

    /** return list of items with last_edit date (id-last_edit,) used for 'exact'
     *  feeding between slices
     *  @param $conds
     *  @param $slice (by link)
     */
    function setFromSlice($conds, $slice) {
        ParseEasyConds($conds);
        $zids    = QueryZids( [$slice->getId()], $conds, '', 'ALL');
        $format  = [
            'odd_row_format' => '{id..............}-{last_edit.......}',
            'row_delimiter'  => ','
        ];
        $this->lastlist = (new itemview($format, null, $zids))->setContentFunct(['AA\IO\LastEditList::getContent', []])->get_output("view");
    }

    /** used as parameter for $itemview in generating item_id-lastedit pair */
    static function getContent($zids, $settings): ?array {
        return GetItemContentMinimal($zids, ['id', 'last_edit']);
    }

    /** setList function
     * @param $list
     */
    function setList($list) {
        $this->lastlist = $list;
    }

    /** printList function
     *
     */
    function printList() {        // print() can't be name of the method :-( -
        echo $this->lastlist;     // parse error, unexpected T_PRINT, expecting
    }                             // T_STRING (php 4.3.10),
    // so I postfixed all functions with *List :-(
    /** getList function
     *
     */
    function getList() {
        return $this->lastlist;
    }

    /** getPairs function
     *
     */
    function getPairs() {
        $ret = [];
        foreach (explode(',', $this->lastlist) as $pair) {
            [$id,$time] = explode('-', $pair);
            if ($id) {
                $ret[$id]   = $time;
            }
        }
        return $ret;
    }
}
