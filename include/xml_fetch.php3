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
#
# Cross-Server Networking - xml_aa_rss fetch function
#

require_once $GLOBALS["AA_INC_PATH"]."logs.php3";
require_once $GLOBALS["AA_INC_PATH"]."sliceobj.php3";
require_once $GLOBALS["AA_INC_PATH"]."csn_util.php3";
require_once $GLOBALS["AA_INC_PATH"]."xml_rssparse.php3";

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
        while (list($k,$v) = each($d)) {
            if (!$v) {
                unset($d[$k]);
            } else {
                $d[$k] = $k."=".urlencode($v);
            }
        }
        if ($tl = implode("&",$d)) {
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
    return trim($data);
}

/** Get APC Feed definitions (from nodes and external_feeds tables)
 *  Returns array('feed_id'=>array( feed_informations_like: url, password... ))
 */
function apcfeeds() {
    global $debugfeed;
    $db = getDB();
    // select all incoming feeds from table external_feeds
    $SQL="SELECT feed_id, password, server_url, name, slice_id, remote_slice_id, newest_item, user_id, remote_slice_name
            FROM nodes, external_feeds WHERE nodes.name=external_feeds.node_name";
    if ($debugfeed >= 8) print("\n<br>$SQL");
    $db->query($SQL);

    $feeds=array();
    while ($db->next_record()) {
        $fi                       = $db->f('feed_id');
        $feeds[$fi]               = $db->Record;
        $feeds[$fi]['field_type'] = FEEDTYPE_APC;
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

/** Process one feed and returns parsed RSS (both AA and other)
 *  in $feed["aa_rss"] */
function onefeedFetchAndParse($feed_id, &$feed, $debugfeed, $display_only=false) {
    // Can use l_slice_id (older) or l_slice (newer)
    $l_slice = new slice(unpack_id128($feed['slice_id']));

    set_time_limit(240); // Allow 4 minutes per feed

    // Get XML Data
    if ($feed['feed_type'] == FEEDTYPE_APC) {

        // for APC feeds we need to list all categories, which we want receive
        $feed['ext_categs'] = GetExternalCategories($feed_id); // used by oneFeedStore

        // select external categories in format
        // array('unpacked_cat_id'=> array( 'value'=>, 'name'=>, 'approved'=>, 'target_category_id'=>))
        $cat_ids = array();
        if ($feed['ext_categs'] && is_array($feed['ext_categs'])) {
            foreach ( $feed['ext_categs'] as $ext_cat_id => $ext_cat ) {
                if ( $ext_cat['target_category_id'] ) {  // the feeding is set for this categor
                    $cat_ids[] = $ext_cat_id;
                }
            }
        }

        /* Mention, that $cat_ids now contaion also AA_Other_Categor, which is
           used on oposite site of feeding as command to send all categories.
           The category list is sent from historical reasons (AA before 2.8
           do not send category informations without this array().
           Current AA use another approach for APC feeds - we will get all items
           regardless on category. The filtering we will do after that.
           This approach means more data to be transfered, but on the other hand
           there is no need to update filters after any category addition
           (Honzam 04/26/04) */


        $feed["DebugName"] = "APC Feed #$feed_id: $feed[name] : $feed[remote_slice_name] -> ".$l_slice->name();
        if ($debugfeed >= 1) print("\n<br>".$feed["DebugName"]);

        // now we have cat_ids[] array => we can ask for data
        $categories2fed = implode(" ",$cat_ids);

        // temporary solution - remove next line please - Honza
        $categories2fed = (($debugfeed == 1) ? null : implode(" ",$cat_ids));


        $xml_data = xml_fetch( $feed['server_url'], ORG_NAME, $feed['password'], $feed['user_id'], unpack_id128($feed['remote_slice_id']),  $feed['newest_item'], $categories2fed);
    } else {   // not FEEDTYPE_APC
        $feed["DebugName"] = "RSS Feed #$feed_id: $feed[name]: -> ".$l_slice->name();
        if ($debugfeed >= 1) print("\n<br>$feed[DebugName]");
        if ($debugfeed >= 8) huhl("onefeedFetchAndParse:url=",$feed['server_url']);

        $xml_data = http_fetch($feed['server_url']);
    }

    // Special option - it only dispays fed data
    if ( $display_only ) {
        echo $xml_data;
        return false;
    }

    if (!$xml_data) {
        writeLog("CSN","No data returned for ". $feed["DebugName"]);
        if ($debugfeed >= 1) print("\n<br>$feed[DebugName]: no data returned");
        return false;
    }
    if ($debugfeed >= 8) huhl("Fetched data=",htmlspecialchars($xml_data));

    // if an error occured, write it to the LOG
    if (substr($xml_data,0,1) != "<") {
        writeLog("CSN","Feeding mode: $xml_data");
        if ($debugfeed >= 1) print("\n<br>$feed[DebugName]:bad data returned: $xml_data");
        return false;
    }

    /** $g_slice_encoding is passed to aa_rss_parse() - it defines output character encoding */
    $GLOBALS['g_slice_encoding'] = getSliceEncoding(unpack_id128($feed['slice_id']));

    if (!( $feed["aa_rss"] = aa_rss_parse( $xml_data ))) {
        writeLog("CSN","Feeding mode: Unable to parse XML data");
        if ($debugfeed >= 1) print("\n<br>$feed[DebugName]:$feed[server_url]:unparsable: <hr>".htmlspecialchars($xml_data)."<hr>");
        return false;
    }

    if ($debugfeed >= 5) { print("\n<br>Parses ok"); }
    return true;
}

function onefeedStore($feed_id, $feed, $debugfeed) {
    global $db;
    $l_slice_id = unpack_id128($feed['slice_id']);        // local slice id
    $l_slice    = new slice($l_slice_id);
    $r_slice_id = unpack_id128($feed['remote_slice_id']); // remote slice id
    $l_categs   = GetGroupConstants( $l_slice_id );       // category definitions
                                                          // - used only for FEEDTYPE_APC
    $aa_rss     = $feed['aa_rss'];

    if ($feed['feed_type'] == FEEDTYPE_APC) {
        // Update the slice categories in the ef_categories table,
        // that is, if the set of possible slice categories has changed
        updateCategories($feed_id, $l_categs, $feed['ext_categs'], $aa_rss['channels'][$r_slice_id]['categories'], $aa_rss['categories']);

        //Update the field names and add new fields to feedmap table
        updateFieldsMapping($feed_id, $l_slice->fields(), $l_slice_id, $r_slice_id, $aa_rss['channels'][$r_slice_id]['fields'],$aa_rss['fields']);
    }

    // update items
    if (isset($aa_rss['items'])) {
        if ($debugfeed >= 8) print("\n<br>onefeed: there are some items to update");
        xmlUpdateItems($feed_id, $feed, $aa_rss, $l_slice_id, $r_slice_id, $l_slice, $feed['ext_categs'], $l_categs,$debugfeed);
        if ($feed['feed_type'] == FEEDTYPE_APC) {
            //update the newest item
            $SQL = "UPDATE external_feeds SET newest_item='".$aa_rss['channels'][$r_slice_id]['timestamp']."'
                     WHERE feed_id='$feed_id'";
            $db->tquery($SQL);
        }
    }
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

/** Stores items to the table item */
function xmlUpdateItems($feed_id, &$feed, &$aa_rss, $l_slice_id, $r_slice_id, $l_sliceobj, &$ext_categs, &$l_categs, $debugfeed) {
    global $db, $varset, $itemvarset, $default_rss_map;

    if ($debugfeed >= 8) print("\n<br>xmlUpdateItems");

    $lf             = $l_sliceobj->fields();
    $l_slice_fields = $lf[0];

    // Find channel definition
    if (!($channel = $aa_rss['channels'][$r_slice_id])) {
        while (list (,$channel) = each($aa_rss['channels'])) {
            if ($channel) { break; }
        }
    }

    /** Now import all items to the slice */
    while (list($item_id, $item) = each($aa_rss['items'])) {

        // Create new item id (always the same for item-slice pair)
        $new_item_id = string2id($item_id . $l_slice_id);

        // Skip already fed items
        if (itemIsDuplicate($new_item_id,$l_slice_id)) {
            //if (ItemIsFed($item_id,$l_slice_id)) {     // Alternative more complex
                if ($debugfeed >= 4) print("\n<br>skipping duplicate: ".$aa_rss['items'][$item_id]['title']);
                continue;
        }

        $varset     = new Cvarset;
        $itemvarset = new CVarset;

        // A series of steps to make field specific edits
        // set fulltext field back from the content field, where it was put by
        // APC for RSS compatability
        if ($fulltext_field_id = GetBaseFieldId($aa_rss['fields'],"full_text")) {
            $item['fields_content'][$fulltext_field_id][0] = contentvalue($item);
        }

        /** Apply filters - rename categories and bin (approved/holding/trash) */
        if ($feed['feed_type'] == FEEDTYPE_APC) { // Use the APC specific fields from the item

            $cat_field_id   = GetBaseFieldId( $aa_rss['fields'], "category" );
            $status_code_id = GetBaseFieldId( $aa_rss['fields'], "status_code" );

            // apply categories mapping. $item is updated accordingly
            $approved = translateCategories( $cat_field_id, $item, $ext_categs, $l_categs );

            // set status_code - according to the settings of ef_categories table
            // RSS feeds have approved set from default_rss_map
            $item['fields_content'][$status_code_id][0]['value'] = $approved ? 1 : 2;
        }

        // create $content4id from $item['fields_content']
        // note that each item in content4id is an array
        list(,$map) = GetExternalMapping($l_slice_id,$r_slice_id);
        if (!$map && ($feed['feed_type'] == FEEDTYPE_RSS)) {
            $map = $default_rss_map;
        }

        while (list($to_field_id,$v) = each($map)) {
            switch ($v['feedmap_flag']) {
                case FEEDMAP_FLAG_VALUE:
                            if ($debugfeed >= 9) print("\n<br>Setting default $to_field_id to ".$v['value']);
                            $content4id[$to_field_id][0]['value'] = quote($v['value']);
                            break;
                case FEEDMAP_FLAG_EXTMAP:   // Check this really works when val in from_field_id
                case FEEDMAP_FLAG_RSS:
                            $values = map1field($v['value'],$item,$channel);
                            if (isset($values) && is_array($values)) {
                                // quote all values
                                while (list($k,$v2) = each($values)) {
                                    $values[$k]['value'] = quote($v2['value']);
                                }
                                $content4id[$to_field_id] = $values;
                            }
                            break;
            } //switch
        } //while each($map)

        if ($debugfeed >= 3) print("\n<br>      " . $content4id['headline........'][0]['value']);
        if ($debugfeed >= 8) { print("\n<br>xmlUpdateItems:content4id="); print_r($content4id); }
        if (! StoreItem( $new_item_id, $l_slice_id, $content4id, $l_slice_fields, true, true, false )) {
            print("\n<br>xmlUpdateItems:StoreItem failed");
        }
        else {
            # insert, invalidatecache, not feed
            // set the item to be recevied from remote node (todo - set via content4id)
            $SQL = "UPDATE item SET externally_fed='".quote($feed['name'])
                 ."' WHERE id='".q_pack_id($new_item_id)."'";
            // Update relation table to show where came from
            AddRelationFeed($new_item_id,$item_id);
            if ($debugfeed >= 8) print("\n<br>xmlUpdateItems:$SQL");
            $db->query($SQL);
        }
    } // while $aa_rss['items']
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
    if (onefeedFetchAndParse($feed_id, $feed, $debugfeed, $fire=='display')) {
        if ( $fire=='write' ) {
            onefeedStore($feed_id, $feed, $debugfeed);
        }
        if ($debugfeed >= 8) print("\n<br>onefeed: done");
    }
}

// Figure out if item alreaady imported into this slice
// Id's are unpacked
// Note that this could be replaced by feeding.php3:IsItemFed which is more complex and would use orig id
function itemIsDuplicate($item_id,$slice_id) {
    global $debugfeed, $db;
      // Only store items that have an id which is not already contained in the items table for this slice
//    $SQL="SELECT id FROM item WHERE id='".q_pack_id($item_id)."' AND slice_id='".q_pack_id($slice_id)."'" ;
// oops - that doesn't work, the item_id is a key.
    $SQL="SELECT id FROM item WHERE id='".q_pack_id($item_id)."'" ;
    $db->query($SQL);
    if ($db->next_record()) {
        return true;
    }
    return false;
}

// Consider value, and return array depending on whether it is HTML or not
// Assumes that RSS1.0 will be explicit, RSS 0.9 and RSS2.0
// should check
function field2arr($field) {
    global $rss_version;
    if (ereg("2\.",$rss_version) || ereg("^0\.9",$rss_version)) {
        $flag = (ereg("<",$field) ? FLAG_HTML : "");
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
        # Often won't work cos not iso8601
        # Wed, 25 Feb 2004 17:19:37 EST   - BAD
        # 2004-02-25 17:19:37+10:00   GOOD
        $try1[0]['value'] =  iso8601_to_unixstamp($try1[0]['value']);
        if ($try1[0]['value'] == -1) $try1[0]['value'] = null;
        if ($debugfeed >= 9) huhl($try1);
        return $try1;
    } elseif ($value == "NOW") {
        return array (0 => array ( value => time(), flag => 0, format => 1 ));
    } elseif (ereg("CHANNEL/(.*)",$value,$vals)) {
        return array ( 0 => field2arr($channel[$vals[1]]));
    } elseif (ereg("ITEM/(.*)",$value,$vals)) {
        return array ( 0 => field2arr($item[$vals[1]]));
    } elseif (ereg("DC/(.*)",$value,$vals)) {
        // Dont believe DC fields can be HTML
        return array ( 0 => array ( value => $item['dc'][$vals[1]], flag => 0, format => 1 ));
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
