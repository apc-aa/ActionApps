<?php
//$Id$
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
//
// Cross-Server Networking - xml_aa_rss fetch function
//

require_once $GLOBALS['AA_INC_PATH']."logs.php3";
require_once $GLOBALS['AA_INC_PATH']."sliceobj.php3";
require_once $GLOBALS['AA_INC_PATH']."csn_util.php3";
require_once $GLOBALS['AA_INC_PATH']."xml_rssparse.php3";

/**  Fetch xml data from $url through http.
 *   This function is used by the rss aa module client as well as by the admin
 *   interface.
 *   @param $node_name       - the name of the node making the request
 *          $password        - the password of the node
 *          $user            - a user at the remote node. This is the user who
 *                             is trying to establish a feed or who established
 *                             the feed
 *          $slice_id        - The id of the local slice from which a feed is
 *                             requested
 *          $start_timestamp - a timestamp which indicates the creation time
 *                             of the first item to be sent.
 *                             (www.w3.org/TR/NOTE-datetime format)
 *          $categories      - a list of local categories ids separated by space
 *                             (can be empty)
 */
function xml_fetch($url, $node_name, $password, $user, $slice_id, $start_timestamp, $categories) {
    $d["node_name"]       = $node_name;
    $d["password"]        = $password;
    $d["user"]            = $user;
    $d["slice_id"]        = $slice_id;
    $d["start_timestamp"] = $start_timestamp;
    $d["categories"]      = $categories;
    return http_fetch($url,$d);
}

/** A generic fetching routine that takes an array of params (possibly empty) */
function http_fetch($url, $d=null) {
    if (isset($d)) {
        $param = array();
        foreach ($d as $k =>$v) {
            if ($v) {
                $param[] = urlencode($k). "=". urlencode($v);
            }
        }
        if ($tl = implode("&",$param)) {
            $url = $url."?".$tl;
        }
    }
    if ($GLOBALS['debugfeed'] >= 8) print("http_fetch:$url\n");
    /* This old version breaks on 4.3.1 and later, ends after first packet
    if (!($fp = fopen($url, "r"))) {
        writeLog("CSN","Unable to connect remote node $url");
        return false;
    }
    $data = fread($fp, 4000000);
    fclose($fp);
    */
    // Replacement only works php >4.3.0
    $data = file_get_contents($url);
    if ($GLOBALS['debugfeed'] >= 8) huhl('data obtained:', $data);
    return trim($data);
}

/** Get APC Feed definitions (from nodes and external_feeds tables)
 *  Returns array('feed_id'=>array( feed_informations_like: url, password... ))
 */
function apcfeeds() {
    global $debugfeed;
    $db = getDB();
    // select all incoming feeds from table external_feeds
    $SQL="SELECT feed_id, password, server_url, name, slice_id, remote_slice_id, newest_item, user_id, remote_slice_name, feed_mode
            FROM nodes, external_feeds WHERE nodes.name=external_feeds.node_name";
    if ($debugfeed >= 8) print("\n<br>$SQL");
    $db->query($SQL);

    $feeds=array();
    while ($db->next_record()) {
        $fi                       = $db->f('feed_id');
        $feeds[$fi]               = $db->Record;
        $feeds[$fi]['feed_type']  = $db->f('feed_mode')=='exact' ? FEEDTYPE_EXACT : FEEDTYPE_APC;
    }
    freeDB($db);
    if ($debugfeed >= 8) { print("\n<br>feeds="); print_r($feeds); }
    return $feeds;
}

/** Get APC Feed definitions (from nodes and external_feeds tables)
 *  Returns array('feed_id'=>array( feed_informations_like: url, password... ))
 */
function rssfeeds() {
    global $debugfeed;
    $db = getDB();
    $SQL="SELECT feed_id, server_url, name, slice_id FROM rssfeeds";
    if ($debugfeed >= 8) print("\n<br>$SQL");
    $db->query($SQL);

    $rssfeeds=array();
    while ($db->next_record()) {
        $fi                               = $db->f('feed_id');
        $rssfeeds[$fi]                    = $db->Record;
        $rssfeeds[$fi]['feed_type']       = FEEDTYPE_RSS;
        $rssfeeds[$fi]['remote_slice_id'] = q_pack_id(attr2id($rssfeeds[$fi]['server_url']));
    }
    freeDB($db);
    if ($debugfeed >= 9) { print("\n<br>rssfeeds="); print_r($rssfeeds); }
    return $rssfeeds;
}

function translteFeedCatid2Value($remote_cat_id, $remote_cat_value, &$ext_categs, &$l_categs, $all_categories) {

    // we have set mapping for this category
    $ext_category_id = ( (!$all_categories AND isset($ext_categs[$remote_cat_id])) ?
                           $remote_cat_id : UNPACKED_AA_OTHER_CATEGOR );

    $local_cat_id = $ext_categs[$ext_category_id]['target_category_id'];
    $approved     = $ext_categs[$ext_category_id]['approved'];

    if ( $local_cat_id == UNPACKED_AA_THE_SAME_CATE ) {
        // we have to not rename the category - return original name
        return array( $remote_cat_value, $approved );
    }

    // return local category name or empty value for "not feed"
    return array( $l_categs[$local_cat_id]['value'], $approved );
}

/** Translates remote categories to local one using external ext_categs array
 *  $cat_field_id   - field id for category (category.......1)
 *  $item           - data for current item. This will be updated by the values
 *                    for new categories ( $cat_field_id )
 *  $ext_categs     - remote categories array (structure with name, value,
 *                    target_category_id, approved)
 *  $l_categs       - local categories array [id] => (name, value, parent_id)
 */
function translateCategories( $cat_field_id, &$item, &$ext_categs, &$l_categs ) {

    $return_approved = null;

    // true if filters are set for 'All categories' option (and not separately
    // for each category)
    $all_categories = UseAllCategoriesOption( $ext_categs );

    // Create [id] => value array of all items categories
    if ( isset($item['categories']) AND is_array($item['categories']) ) {
        // This categories are regular categories on remote slice, so it
        // should have value defined
        // This categories are set in special $item[categories] array
        foreach ( $item['categories'] as $r_cid ) {
            list( $new_cat, $approved ) = translteFeedCatid2Value( $r_cid, $ext_categs[$r_cid]['value'], $ext_categs, $l_categs, $all_categories );
            if ( is_null( $return_approved ) ) {
                $return_approved = $approved ;
            }
            $used_values[trim($ext_categs[$r_cid]['value'])] = true; // mark it
            if ( $new_cat ) {
                // create new $content4id entry for categories
                $new_categories[] = array( 'value' => $new_cat );
            }
        }
    }
    if ( $cat_field_id AND is_array($item['fields_content'][$cat_field_id]) ) {
        // Now do the same also for "dirty" categories - categories, which are
        // not in the list of current remote categories (item has another than
        // listed category - strange, but obvious (due feeding, importing, ...)
        foreach ( $item['fields_content'][$cat_field_id] as $r_value ) {
            if ( !$used_values[trim($r_value['value'])] ) {
                list( $new_cat, $approved ) = translteFeedCatid2Value( UNPACKED_AA_OTHER_CATEGOR, $r_value['value'], $ext_categs, $l_categs, $all_categories );
                if ( is_null( $return_approved ) ) {
                    $return_approved = $approved ;
                }
                if ( $new_cat ) {
                    // create new $content4id entry for categories
                    $new_categories[] = array( 'value' => $new_cat );
                }
            }
        }
    }

    // And now somethig completely different - substitute old categories with new ones
    $item['fields_content'][$cat_field_id] = $new_categories;

    return $return_approved;
}

/** Update the slice categories in the ef_categories table, that is, if the set
 *  of possible slice categories has changed
 */
 function updateCategories($feed_id, &$l_categs, &$ext_categs,&$cat_refs, &$categs) {
     global $debugfeed;
     $db = getDB();
     // add new categories or update categories' fields
     if (isset($cat_refs) && is_array($cat_refs)) {
         foreach ($cat_refs as $r_cat_id => $foo) {
             $category = $categs[$r_cat_id];

             if ($ext_categs[$r_cat_id])  {
                 // remote category is in the ef_categories table, so update name and value
                 $SQL = "UPDATE ef_categories SET category_name='".$category[name]."',
                         category='".$category[value]."'
                         WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'";
                 if ($debugfeed >= 8) print("\n<br>$SQL");
                 $db->query($SQL);
             } else {
                 $l_cat_id = MapDefaultCategory($l_categs,$category[value], $category[catparent]);
                 $SQL = "INSERT INTO ef_categories VALUES ('".$category[value]."','".$category[name]."',
                         '".q_pack_id($category[id])."','".$feed_id."','".q_pack_id($l_cat_id)."','0')";
                 if ($debugfeed >= 8) print("\n<br>$SQL");
                 $db->query($SQL);
             }
         }
     }

     // remove the categories from table, which were not sent
     if (isset($ext_categs) AND is_array($ext_categs)) {
         foreach ($ext_categs as $r_cat_id => $foo) {
             if (isset($cat_refs[$r_cat_id])) {
                 continue;
             }
             $SQL = "DELETE FROM ef_categories WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'";
             if ($debugfeed >= 8) print("\n<br>$SQL");
             $db->query($SQL);
         }
     }
     freeDB($db);
 }

/** Update the fields mapping from the remote slice to the local slice */
function updateFieldsMapping($feed_id, $l_slice_id, $r_slice_id, &$field_refs, &$fields) {
    global $debugfeed;

    list($ext_map,$field_map) = GetExternalMapping($l_slice_id, $r_slice_id);
    $p_l_slice_id = q_pack_id($l_slice_id);
    $p_r_slice_id = q_pack_id($r_slice_id);

    // add new ones
    $db = getDB();
    if ( isset($field_refs) AND is_array($field_refs) ) {
        foreach( $field_refs as $r_field_id => $val ) {
            $new_name = $fields[$r_field_id]['name'];
            if ($ext_map && $ext_map[$r_field_id]) {
                // remote field is in the feedmap table => update name
                if ($ext_map[$r_field_id] != $new_name) { // update if field name changed on remote AA
                    $SQL = "UPDATE feedmap SET from_field_name='".quote($new_name)."'
                            WHERE from_slice_id='$p_r_slice_id'
                            AND to_slice_id='$p_l_slice_id'
                            AND from_field_id='$r_field_id'";
                    if ($debugfeed >= 8) print("\n<br>$SQL");
                    $db->query($SQL);
                }
            } else {
                $SQL = "INSERT INTO feedmap VALUES('$p_r_slice_id','$r_field_id','$p_l_slice_id','$r_field_id',
                       '".FEEDMAP_FLAG_EXTMAP ."','','".quote($new_name)."')";
                if ($debugfeed >= 8) print("\n<br>$SQL");
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
            if ($debugfeed >= 8) print("\n<br>$SQL");
            $db->query($SQL);
        }
    }
    freeDB($db);
}


class saver {
    var $grabber;            /** the object which deliveres the data */
    var $transformations;    /** describes, what to do with data before storing */
    var $slice_id;           /** id of destination slice */
    var $store_mode;         /** store-policy - how to store - overwrite | insert_if_new */
    var $id_mode;            /** id-policy    - how to construct id - old | new | combined */

    function saver(&$grabber, &$transformations, $slice_id, $store_mode='overwrite', $id_mode='old') {
        $this->grabber         = $grabber;
        $this->transformations = $transformations;
        $this->slice_id        = $slice_id;
        $this->store_mode      = $store_mode;
        $this->id_mode         = $id_mode;
    }

    /** Now import all items to the slice */
    function run() {
        global $debugfeed;

        $this->grabber->prepare();    // maybe some initialization in grabber
        while ($content4id = $this->grabber->getItem()) {

            if ($debugfeed >= 8) {
                print("\n<br>saver->run(): we have item to store, hurray! -- ". $content4id->getItemID());
            }

            switch ($this->id_mode) {
                // Create new item id (always the same for item-slice pair)
                case 'combined' : $new_item_id = string2id($content4id->getItemID(). $this->slice_id); break;

                // Use id from source
                case 'old'      : $new_item_id = $content4id->getItemID(); break;

                // Generate completely new id
                default         :
                case 'new'      : $new_item_id = new_id();                 break;
            }

            // set the item to be recevied from remote node
            $content4id->setItemID($new_item_id);

            // TODO - move to translations
            $content4id->setSliceID($this->slice_id);

            if ($debugfeed >= 3) print("\n<br>      ". $content4id->getValue('headline........'));
            if ($debugfeed >= 8) { print("\n<br>xmlUpdateItems:content4id="); huhl($content4id); }

            // id_mode - overwrite or insert_if_new
            // (the $new_item_id should not be changed by storeItem)
            if (!($new_item_id = $content4id->storeItem($this->store_mode))) {     // invalidatecache, feed
                print("\n<br>saver->run(): storeItem failed or skiped duplicate");
            } else {
                if ($debugfeed >= 1) print("\n<br>  + stored OK: ". $content4id->getValue('headline........'));
                // Update relation table to show where came from
                AddRelationFeed($new_item_id, $content4id->getItemID());
            }
        } // while grabber->getItem()
        $this->grabber->finish();    // maybe some initialization in grabber
    }

    /** Toexecutelater - special function called from toexecute class
     *  - used for queued tasks (runed from cron)
     *  You can use $toexecute->later($saver, array(), 'feed_external') instead
     *  of calling $saver->run() - the saving will be planed for future
     */
    function toexecutelater() {
        return $this->run();
    }
}

class grabber {
    function getItem() {}

    /** Possibly preparation of grabber - it is called directly before getItem()
     *  method is called - it means "we are going really to grab the data
     */
    function prepare() {}

    function finish()  {}
}

class grabber_aarss extends grabber {
    var $feed_id;
    var $feed;
    var $name;
    var $slice_id;
    var $aa_rss;
    var $channel;
    var $map;
    var $cat_field_id;
    var $status_code_id;
    var $ext_categs;
    var $l_categs;
    var $r_slice_id;
    var $fire;

    function grabber_aarss($feed_id, &$feed, $fire) {
        global $debugfeed;

        /** Process one feed and returns parsed RSS (both AA and other)
         *  in $this->aa_rss */
        $this->slice_id   = unpack_id128($feed['slice_id']);        // local slice id
        $this->r_slice_id = unpack_id128($feed['remote_slice_id']); // remote slice id
        $this->feed_id    = $feed_id;
        $this->feed       = $feed;
        $this->fire       = $fire;
    }


    function _getRssData() {
        return http_fetch($this->feed['server_url']);
    }

    function _getApcData() {
        // for APC feeds we need to list all categories, which we want receive
        $this->ext_categs = GetExternalCategories($this->feed_id); // we will need it later

        // select external categories in format
        // array('unpacked_cat_id'=> array( 'value'=>, 'name'=>, 'approved'=>, 'target_category_id'=>))
        $cat_ids = array();
        if ($this->ext_categs AND is_array($this->ext_categs)) {
            foreach ( $this->ext_categs as $ext_cat_id => $ext_cat ) {
                if ( $ext_cat['target_category_id'] ) {  // the feeding is set for this categor
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

    function _getExactData() {
        global $debugfeed;

        // get local item list (with last edit times)
        $slice       = new slice($this->slice_id);
        $local_list  = new LastEditList();
        $local_list->setFromSlice('', $slice);  // no conditions - all items
        $local_pairs = $local_list->getPairs();

        if ($debugfeed > 8) { huhl('_getExactData() - Local pairs:', $local_pairs); }

        $base["node_name"]       = ORG_NAME;
        $base["password"]        = $this->feed['password'];
        $base["user"]            = $this->feed['user_id'];
        $base["slice_id"]        = $this->r_slice_id;
        $base["start_timestamp"] = $this->feed['newest_item'];
        $base["exact"]           = 1;

        $init = $base;
        $init['conds[0][last_edit.......]'] = 1;
        $init['conds[0][value]']            = iso8601_to_unixstamp($this->feed['newest_item']);
        $init['conds[0][operator]']         = '>=';

        $remote_list  = new LastEditList();
        $remote_list->setList(http_fetch($this->feed['server_url'], $init));
        $remote_pairs = $remote_list->getPairs();

        if ($debugfeed > 8) { huhl('_getExactData() - Remote pairs:', $remote_pairs); }

        // Get all ids, which was updated later than items in local slice
        $ids = array();   // initialize
        foreach ($remote_pairs as $id => $time) {
            if (!isset($local_pairs[$id]) OR ($local_pairs[$id] < $time)) {
                $ids[] = $id;  // array of ids to ask for
            }
        }

        if ($debugfeed >= 2) { huhl(' Local items: ', count($local_pairs), ' Remote items: ', count($remote_pairs), ' Asked for update: ', count($ids)); }

        // No items to fed?
        if (count($ids) <= 0) {
            return '';
        }

        $finish        = $base;
        $finish['ids'] = implode('-',$ids);

        if ($debugfeed > 8) { huhl('_getExactData() - http_fetch:', $this->feed['server_url'], $finish); }

        return http_fetch($this->feed['server_url'], $finish);
    }

    /** Fetch data and parse it **/
    function prepare() {
        global $DEFAULT_RSS_MAP, $debugfeed;

        // just shortcut
        $feed_type = $this->feed['feed_type'];

        set_time_limit(240); // Allow 4 minutes per feed

        // Get XML Data
        if ($debugfeed >= 1) {
            $slice           = new slice($this->slice_id);
            $feed_debug_name = 'Feed #'. $this->feed_id .' ('. getFeedTypeName($feed_type).'): '.
                               $this->feed['name'] .' : ' .$this->feed['remote_slice_name'].
                               ' -> '.$slice->name();
            print("\n<br>$feed_debug_name");
        }

        switch($feed_type) {
            case FEEDTYPE_RSS:   $xml_data = $this->_getRssData();   break;
            case FEEDTYPE_EXACT: $xml_data = $this->_getExactData(); break;
            case FEEDTYPE_APC:
            default:             $xml_data = $this->_getApcData();   break;
        }

        // Special option - it only dispays fed data
        if ($this->fire == 'display') {
            echo $xml_data;
            return false;
        }

        if (!$xml_data) {
            writeLog("CSN","No data returned for $feed_debug_name");
            if ($debugfeed >= 1) print("\n<br>$feed_debug_name: no data returned");
            return false;
        }
        if ($debugfeed >= 8) huhl("Fetched data=",htmlspecialchars($xml_data));

        // if an error occured, write it to the LOG
        if (substr($xml_data,0,1) != "<") {
            writeLog("CSN","Feeding mode: $xml_data");
            if ($debugfeed >= 1) print("\n<br>$feed_debug_name:bad data returned: $xml_data");
            return false;
        }

        /** $g_slice_encoding is passed to aa_rss_parse() - it defines output character encoding */
        $GLOBALS['g_slice_encoding'] = getSliceEncoding($this->slice_id);

        if (!( $this->aa_rss = aa_rss_parse( $xml_data ))) {
            writeLog("CSN","Feeding mode: Unable to parse XML data");
            if ($debugfeed >= 1) print("\n<br>$feed_debug_name:$feed[server_url]:unparsable: <hr>".htmlspecialchars($xml_data)."<hr>");
            return false;
        }

        if ($debugfeed >= 5) { print("\n<br>Parses ok"); }

        //  --- output parsed - great! - we are going to store

        $this->l_categs   = GetGroupConstants($this->slice_id);       // category definitions
                                                          // - used only for FEEDTYPE_APC

        if ($feed_type == FEEDTYPE_APC) {
            // Update the slice categories in the ef_categories table,
            // that is, if the set of possible slice categories has changed
            updateCategories($this->feed_id, $this->l_categs, $this->ext_categs, $this->aa_rss['channels'][$this->r_slice_id]['categories'], $this->aa_rss['categories']);
        }

        if (($feed_type == FEEDTYPE_APC) OR ($feed_type == FEEDTYPE_EXACT)) {
            //Update the field names and add new fields to feedmap table
            updateFieldsMapping($this->feed_id, $this->slice_id, $this->r_slice_id, $this->aa_rss['channels'][$this->r_slice_id]['fields'],$this->aa_rss['fields']);
        }

        // Find channel definition
        if (!($this->channel = $aa_rss['channels'][$this->r_slice_id])) {
            while (list(,$this->channel) = each($this->aa_rss['channels'])) {
                if ($this->channel) {
                   break;
                }
            }
        }

        list(,$map) = GetExternalMapping($this->slice_id,$this->r_slice_id);
        if (!$map && ($feed_type == FEEDTYPE_RSS)) {
            $map = $DEFAULT_RSS_MAP;
        }
        $this->map = $map;

        // Use the APC specific fields from the item
        if ($feed_type == FEEDTYPE_APC) {
            $this->cat_field_id   = GetBaseFieldId( $this->aa_rss['fields'], "category" );
            $this->status_code_id = GetBaseFieldId( $this->aa_rss['fields'], "status_code" );
        }

        if (is_array($this->aa_rss['items'])) {
            reset($this->aa_rss['items']);
        }

    }

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
        if ($fulltext_field_id = GetBaseFieldId($this->aa_rss['fields'],"full_text")) {
            $item['fields_content'][$fulltext_field_id][0] = contentvalue($item);
        }

        /** Apply filters - rename categories and bin (approved/holding/trash) */
        if ($this->feed['feed_type'] == FEEDTYPE_APC) { // Use the APC specific fields from the item

            // apply categories mapping. $item is updated accordingly
            $approved = translateCategories( $this->cat_field_id, $item, $this->ext_categs, $this->l_categs );

            // set status_code - according to the settings of ef_categories table
            // RSS feeds have approved set from DEFAULT_RSS_MAP
            $item['fields_content'][$this->status_code_id][0]['value'] = $approved ? 1 : 2;
        }


        // create item from source data (in order we can unalias)
        $item2fed = new item($item['fields_content'], array());

        foreach ( $this->map as $to_field_id => $v) {
            switch ($v['feedmap_flag']) {
                case FEEDMAP_FLAG_VALUE:
                            // value could contain {switch()} and other {constructs}
                            $content4id[$to_field_id][0]['value'] = $item2fed->unalias($v['value']);
                            break;
                case FEEDMAP_FLAG_EXTMAP:   // Check this really works when val in from_field_id
                case FEEDMAP_FLAG_RSS:
                            $values = map1field($v['value'],$item,$this->channel);
                            if (isset($values) && is_array($values)) {
                                while (list($k,$v2) = each($values)) {
                                    $values[$k]['value'] = $v2['value'];
                                }
                                $content4id[$to_field_id] = $values;
                            }
                            break;
            } // switch
        } // while each($map)

        next($this->aa_rss['items']);

        $ic = new ItemContent($content4id);
        $ic->setItemValue('externally_fed', $this->feed['name']);  // TODO - move one layer up - to saver transactions
        $ic->setItemId($item_id);
        return $ic;
    }

    function finish() {
        if ($this->feed['feed_type'] == FEEDTYPE_APC) {
            $db = getDB();
            //update the newest item
            $SQL = "UPDATE external_feeds SET newest_item='".quote($this->aa_rss['channels'][$this->r_slice_id]['timestamp'])."'
                     WHERE feed_id='".quote($this->feed_id)."'";
            $db->tquery($SQL);
            freeDB($db);
        }
    }
}

/** Process one feed RSS or APC
 *  @param  $feed_id   - id of feed (it is autoincremented number from 1 ...
 *                     - RSS and APC feeds could have the same id :-(
 *          $feed      - feed definition array (server_url, password, ...)
 *          $debugfeed - just for debuging purposes
 *          $fire      - write   - feed and write the items to the databse
 *                       test    - proccesd without write anything to the database
 *                       display - only display the data from the feed
 *
 */
function onefeed($feed_id, $feed, $debugfeed, $fire = 'write') {
    $slice_id = unpack_id128($feed['slice_id']);
    if ( $fire=='write' ) {
        $grabber      = new grabber_aarss($feed_id, $feed, $fire);
        $translations = null;
        $saver        = new saver($grabber, $translations, $slice_id);
        $saver->run();
    }
    if ($debugfeed >= 8) print("\n<br>onefeed: done");
}

// Consider value, and return array depending on whether it is HTML or not
// Assumes that RSS1.0 will be explicit, RSS 0.9 and RSS2.0
// should check
function field2arr($field) {
    global $rss_version;
    if ((strpos($rss_version, '2.') !== false) OR (strpos($rss_version, '0.9') !== false)) {
        $flag = ((strpos($field, '<') !== false) ? FLAG_HTML : "");
    } else { // Must be 1.0 which doesn't have an RSS version
        $flag = FLAG_HTML;
    }
    return array(value => $field, flag => $flag);
}

// Return array suitable for insertion into content4id[aaa....]
// Recognized special cases of values to tell it how to find, the field, or
// how to interpret it.
function map1field($value,$item,$channel) {
    global $debugfeed;
    if ($debugfeed >= 8) print("\n<br>xmlclient:map1field:$value");
    if (ereg("(.*)\|(.*)",$value,$vals)) {  // Process alternatives, first if non-blank else second
        $try1 = map1field($vals[1],$item,$channel);
        if ($try1[0]['value']) { return $try1; }
        return map1field($vals[2],$item,$channel);
    } elseif (ereg("^DATE\((.*)\)$",$value,$vals)) { // Postprocess to turn into unix
        $try1 = map1field($vals[1],$item,$channel);
        if ($debugfeed >= 9) huhl($try1);
        if (isset($try1) && is_array($try1) && $try1[0]['value'])
        // Often won't work cos not iso8601
        // Wed, 25 Feb 2004 17:19:37 EST   - BAD
        // 2004-02-25 17:19:37+10:00   GOOD
        $try1[0]['value'] =  iso8601_to_unixstamp($try1[0]['value']);
        if ($try1[0]['value'] == -1) $try1[0]['value'] = null;
        if ($debugfeed >= 9) huhl($try1);
        return $try1;
    } elseif ($value == "NOW") {
        return array(0 => array('value' => time(), 'flag' => 0));
    } elseif (ereg("CHANNEL/(.*)",$value,$vals)) {
        return array ( 0 => field2arr($channel[$vals[1]]));
    } elseif (ereg("ITEM/(.*)",$value,$vals)) {
        return array ( 0 => field2arr($item[$vals[1]]));
    } elseif (ereg("DC/(.*)",$value,$vals)) {
        // Dont believe DC fields can be HTML
        return array(0 => array('value' => $item['dc'][$vals[1]], 'flag' => 0));
    } elseif ($value == "CONTENT") {
        // Note this code is repeated above in map1field
        return array (0 => contentvalue($item));
    } else {
        return $item['fields_content'][$value];
    }
}

// Extract the content from where the parser put it, and return as a value array.
function contentvalue($item) {
    $flag="";
    if (isset($item['content'][HTML])) { // HTML is constant!!!
        // choose HTML content first
        $flag      = FLAG_HTML;
        $cont_flag = HTML;
    } else {   // otherwise PLAIN. Other formats are not supported,
        $cont_flag = PLAIN;      // but they can be added in future
    }
    return array("value"=>$item['content'][$cont_flag], "flag"=>$flag);
}

?>
