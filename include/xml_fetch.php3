<?php
/**
 *
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
//
// Cross-Server Networking - xml_aa_rss fetch function
//

require_once AA_INC_PATH."logs.php3";
require_once AA_INC_PATH."slice.class.php3";
require_once AA_INC_PATH."csn_util.php3";
require_once AA_INC_PATH."xml_rssparse.php3";
require_once AA_INC_PATH."grabber.class.php3";
require_once AA_INC_PATH."files.class.php3";  // file wrapper;


/**  xml_fetch function
 *   Fetch xml data from $url through http.
 *   This function is used by the rss aa module client as well as by the admin
 *   interface.
 * @param $url
 *   @param $node_name       - the name of the node making the request
 *   @param $password        - the password of the node
 *   @param $user            - a user at the remote node. This is the user who
 *                             is trying to establish a feed or who established
 *                             the feed
 *   @param $slice_id        - The id of the local slice from which a feed is
 *                             requested
 *   @param $start_timestamp - a timestamp which indicates the creation time
 *                             of the first item to be sent.
 *                             (www.w3.org/TR/NOTE-datetime format)
 *   @param $categories      - a list of local categories ids separated by space
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

/** http_fetch function
 *  A generic fetching routine that takes an array of params (possibly empty)
 * @param $url
 * @param $d
 */
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
    if ($GLOBALS['debugfeed'] >= 8) {
        print("http_fetch:$url\n");
    }
    $file = &AA_File_Wrapper::wrapper($url);
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
 */
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

/** translateCategories function
 *  Translates remote categories to local one using external ext_categs array
 * @param $cat_field_id   - field id for category (category.......1)
 * @param $item           - data for current item. This will be updated by the values
 *                    for new categories ( $cat_field_id )
 * @param $ext_categs     - remote categories array (structure with name, value,
 *                    target_category_id, approved)
 * @param $l_categs       - local categories array [id] => (name, value, parent_id)
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

/** updateCategories function
 *  Update the slice categories in the ef_categories table, that is, if the set
 *  of possible slice categories has changed
 * @param $feed_id
 * @param $l_categs
 * @param $ext_categs
 * @param $cat_refs
 * @param $categs
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
                 $SQL = "UPDATE ef_categories SET category_name='".$category['name']."',
                         category='".$category['value']."'
                         WHERE feed_id='$feed_id' AND category_id='".q_pack_id($r_cat_id)."'";
                 if ($debugfeed >= 8) print("\n<br>$SQL");
                 $db->query($SQL);
             } else {
                 $l_cat_id = MapDefaultCategory($l_categs,$category['value'], $category['catparent']);
                 $SQL = "INSERT INTO ef_categories VALUES ('".$category['value']."','".$category['name']."',
                         '".q_pack_id($category['id'])."','".$feed_id."','".q_pack_id($l_cat_id)."','0')";
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
                    if ($debugfeed >= 8) {
                        print("\n<br>$SQL");
                    }
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

/** onefeed function
 *  Process one feed RSS or APC
 *  @param  $feed_id   - id of feed (it is autoincremented number from 1 ...
 *                     - RSS and APC feeds could have the same id :-(
 *  @param  $feed      - feed definition array (server_url, password, ...)
 *  @param  $debugfeed - just for debuging purposes
 *  @param  $fire      - write   - feed and write the items to the databse
 *                       test    - proccesd without write anything to the database
 *                       display - only display the data from the feed
 *
 */
function onefeed($feed_id, $feed, $debugfeed, $fire = 'write') {
    $slice_id = unpack_id128($feed['slice_id']);
    if ( $fire=='write' ) {
        $grabber      = new AA_Grabber_Aarss($feed_id, $feed, $fire);
        $translations = null;
        $saver        = new AA_Saver($grabber, $translations, $slice_id);
        $saver->run();
    }
    if ($debugfeed >= 8) {
        print("\n<br>onefeed: done");
    }
}

/** field2arr function
 * Consider value, and return array depending on whether it is HTML or not
 * Assumes that RSS1.0 will be explicit, RSS 0.9 and RSS2.0
 * should check
 * @param $field
 */
function field2arr($field) {
    global $rss_version;
    if ((strpos($rss_version, '2.') !== false) OR (strpos($rss_version, '0.9') !== false)) {
        $flag = ((strpos($field, '<') !== false) ? FLAG_HTML : "");
    } else { // Must be 1.0 which doesn't have an RSS version
        $flag = FLAG_HTML;
    }
    return array(value => $field, flag => $flag);
}

/** map1field function
 *  @return array suitable for insertion into content4id[aaa....]
 * Recognized special cases of values to tell it how to find, the field, or
 * how to interpret it.
 * @param $value
 * @param $item
 * @param $channel
 */
function map1field($value,$item,$channel) {
    global $debugfeed;
    if ($debugfeed >= 8) {
        print("\n<br>xmlclient:map1field:$value");
    }
    if (ereg("(.*)\|(.*)",$value,$vals)) {  // Process alternatives, first if non-blank else second
        $try1 = map1field($vals[1],$item,$channel);
        if ($try1[0]['value']) { return $try1; }
        return map1field($vals[2],$item,$channel);
    } elseif (ereg("^DATE\((.*)\)$",$value,$vals)) { // Postprocess to turn into unix
        $try1 = map1field($vals[1],$item,$channel);
        if ($debugfeed >= 9) {
            huhl($try1);
        }
        if (isset($try1) && is_array($try1) && $try1[0]['value']) {
        // Often won't work cos not iso8601
        // Wed, 25 Feb 2004 17:19:37 EST   - BAD
        // 2004-02-25 17:19:37+10:00   GOOD
                $try1[0]['value'] =  iso8601_to_unixstamp($try1[0]['value']);
        }
        if ($try1[0]['value'] == -1) {
            $try1[0]['value'] = null;
        }
        if ($debugfeed >= 9) {
            huhl($try1);
        }
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

/** contentvalue function
 *  Extract the content from where the parser put it, and return as a value array.
 * @param $item
 */
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
