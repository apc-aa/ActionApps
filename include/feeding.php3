<?php
/**
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
 * @copyright Copyright (c) 2002-3 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/


//
// Functions for feeding
//

require_once AA_INC_PATH."stringexpand.php3"; // for translateString()

/** GetFieldMapping function
 *  Find fields mapping.
 * @param $from_slice_id
 * @param $destination_id
 * @return array
 */
function GetFieldMapping($from_slice_id, $destination_id) {
    $fields_from = AA_Slice::getModule($from_slice_id)->getFields()->getRecordArray();
    $fields_to   = AA_Slice::getModule($destination_id)->getFields()->getPriorityArray();

    if (empty($fields_to)) {
        return array();
    }

    $p_from_slice_id  = q_pack_id($from_slice_id);
    $p_destination_id = q_pack_id($destination_id);

    $db = getDb();
    // fill $m array with mapping set by user on mapping page (and stored to db)
    $SQL = "SELECT from_field_id, to_field_id, flag, value
              FROM feedmap
             WHERE from_slice_id = '$p_from_slice_id'
               AND to_slice_id = '$p_destination_id'";
    $db->query($SQL);
    while ( $db->next_record() ) {
        switch ($db->f('flag')) {
            case FEEDMAP_FLAG_MAP:
                $val = $db->f('from_field_id'); break;
            case FEEDMAP_FLAG_VALUE:
            case FEEDMAP_FLAG_JOIN:
                $val = $db->f('value'); break;
            case FEEDMAP_FLAG_RSS:
                huhe("Warning: RSS field mapping appearing in non RSS feed");
                $val = ""; break;
            default:
                $val = ""; break;
        }
        $m[$db->f('to_field_id')] = array("feedmap_flag"=>$db->f('flag'),"val"=>$val);
    }

    foreach ($fields_to as $field_id ) {
        if ( $m[$field_id] ) {
            $map[$field_id] = $m[$field_id];                 // set if mapped
        } else {                                 // if not mapped - store in the same; if not exist, set empty
            $map[$field_id] = $fields_from[$field_id] ? array("feedmap_flag"=>FEEDMAP_FLAG_MAP,"val"=>$field_id)  :
                                                        array("feedmap_flag"=>FEEDMAP_FLAG_EMPTY,"val"=>"") ;
        }
    }

    freeDb($db);
    return $map;
}

/** GetBaseItem function
 * get base item of $item_id from relation table
 * @param $item_id
 */
function GetBaseItem($item_id) {
    $db = getDb();
    $dest_id = $item_id;

    while ($item_id) {
        $dest_id = $item_id;
        $p_dest_id = q_pack_id($dest_id);
        $SQL=  "SELECT source_id FROM relation
                 WHERE  destination_id = '$p_dest_id'
                   AND ((flag & ". REL_FLAG_FEED .") != 0)";
        $db->query($SQL);
        $item_id = $db->next_record() ? unpack_id($db->f('source_id')) : false;
    }
    freeDb($db);
    return $dest_id;
}

/** IsItemFed function
 * find if item was already fed to $destination slice or it comes from it
 * @param $item_id
 * @param $destination
 */
function IsItemFed($item_id, $destination) {
    $p_destination = q_pack_id($destination);
    $base_id       = GetBaseItem($item_id);  // Trace back to the original id

    // if item comes from $destination slice (i.e. $destination slice contains base record)
    if ( $p_destination == DB_AA::select1('SELECT slice_id FROM `item`', 'slice_id', array(array('id',$base_id, 'l'))) ) {
        return true;
    }

    $SQL     = "SELECT source_id FROM relation, item WHERE relation.destination_id = item.id AND item.slice_id = '$p_destination' AND ((flag & ". REL_FLAG_FEED .") != 0)";
    $sources = array_map('unpack_id', DB_AA::select('source_id', $SQL));

    // Test array for containing an item fed from same baseid
    foreach ($sources as $source_id) {
        if ($base_id == GetBaseItem($source_id)) {
            return true;
        }
    }

    return false;
}

/** FeedJoin function
 *  Returns the joined fields
 *  @param  columns - values of the processed item (db record)
 *  @param  fields  - source fields description (object)
 *  @param  params  - parameters of the "join" mapping function - separated by
 *                    ':', contains field names separated by HTML separator
 *                    description (the result will be the fields' content
 *                    separated by the separators)
 *  @param  result  - the value to be changed (see FeedItemTo)
 *  @return  result
 *  @author   Jakub Adamek
 */
function FeedJoin($columns, $fields, $params, &$result) {
    $params = str_replace ("#:","#~",$params);
    $params = explode(":",$params);
    $i = 0;
    // should all the joined fields be updated? 0 = don't know, -1 = no, 1 = yes
    $update = 0;
    foreach ($params as $val) {
        if ($i++ % 2 == 0) {
            switch ($fields->getProperty($val,'feed')) {
            case STATE_UNFEEDABLE:	 return;
            case STATE_FEEDNOCHANGE:
                $result['flag'] |= FLAG_FREEZE;
                $update = -1;
                break;
            case STATE_FEEDABLE_UPDATE_LOCKED:
                $result['flag'] |= FLAG_FREEZE; // break shouldn't be here!
            case STATE_FEEDABLE_UPDATE:
                if ($update > -1) {
                    $update = 1;
                }
                break;
            }
            $result_val .= (is_array($columns[$val]) ? $columns[$val][0]['value'] : $val);
        } else {
            $result_val .= str_replace('\n',"\n",$val);
        }
    }
    $result['flag'] |= FLAG_UPDATE;
    //if ($update != 1) // the function "update" doesn't support joined fields
    $result['flag'] -= FLAG_UPDATE;
    $result['value'] = str_replace ("#~",":",$result_val);
}


/** FeedItemTo function
 * copy one item
 * @param $item_id
 * @param $from_slice_id
 * @param $destination_id
 * @param $approved
 * @param $tocategory
 * @param $content
 */
function FeedItemTo($item_id, $from_slice_id, $destination_id, $approved, $tocategory=0, $content="") {
    global $varset, $itemvarset;

    if ( $destination_id == $from_slice_id ) { // don't feed into the same slice
        return false;
    }

    // don't feed if the item is already fed.
    if (isItemFed($item_id, $destination_id)) {
        return false;
    }

    $map = GetFieldMapping($from_slice_id, $destination_id);

    if ( !$content ) {
        $content = GetItemContent($item_id);
    }
    $content4id    = $content[$item_id];   // shortcut

    $from_slice  = AA_Slice::getModule($from_slice_id);
    $destination = AA_Slice::getModule($destination_id);
    $fields      = $from_slice->getFields();
    $catfieldid  = $fields->getCategoryFieldId();

    if ( $catfieldid AND ( (string)$tocategory != "0" ) ) {
        $destinationcat = DB_AA::select1('SELECT value FROM constant', 'value', array(array('id',$tocategory, 'l')));
    }

    $varset     = new Cvarset;
    $itemvarset = new Cvarset;  // must be defined before insert_fnc_qte
    $id         = new_id();


    $fields_to   = $destination->fields('record');

    // prepare new4id array before call StoreItem function
    while (list($newfld,$newfldname) = each($fields_to)) {
        $flag = $map[$newfld]['feedmap_flag'];
        $val  = $map[$newfld]['val'];

        switch ($flag) {
            case FEEDMAP_FLAG_EMPTY : continue;
            case FEEDMAP_FLAG_MAP :
                if ($fields->getProperty($val,'feed')==STATE_UNFEEDABLE) {
                    continue;
                } else {
                    $new4id[$newfld] = $content4id[$val];
                    if ($fields->getProperty($val,'feed')==STATE_FEEDNOCHANGE ) {
                        $new4id[$newfld][0]['flag'] |= FLAG_FREEZE;  // don't allow to change
                    } elseif ($fields->getProperty($val,'feed')==STATE_FEEDABLE_UPDATE_LOCKED) {
                        $new4id[$newfld][0]['flag'] |= FLAG_FREEZE | FLAG_UPDATE;   //update and don't allow to change
                    }
                }
                break;
            // in value you can specify not only new value but you can write there
            // also AA expression, which is unaliased - example:
            // <a href="{source_href.....}">{source..........}</a>
            case FEEDMAP_FLAG_VALUE :
                // create item from source data (in order we can unalias)
                if ( !$item2fed ) {
                    $item2fed = new AA_Item($content4id, $fields->getAliases());
                }
                $new4id[$newfld][0]['value'] = $item2fed->unalias($val);
                break;
            case FEEDMAP_FLAG_JOIN:
                FeedJoin($content4id, $fields, $val, $new4id[$newfld][0]); break;
            case FEEDMAP_FLAG_RSS:
                huhe("Warning RSS feed mapping found in non RSS feedat feeding:240");
                break;
        }

        $new4id[$newfld][0]['flag'] |= FLAG_FEED;      // mark as fed

        // category mapping
        if ( $newfld == $catfieldid ) {
            if ( (string)$tocategory != "0" ) {    // if 0 - don't change category
                $new4id[$newfld][0]['value'] = $destinationcat;
            }
        }
    }

    // --- fill required fields if not set ---

    // status_code can be redefined in 'Mapping'
    // Use it only when 'Value' is used for status_code (we do not want to copy
    // status_code field from source item (since it is allways '1')

    if ( !(($map['status_code.....']['feedmap_flag'] == FEEDMAP_FLAG_VALUE) AND
          ((int)$new4id['status_code.....'][0]['value'] > 0)) ) {
        $new4id['status_code.....'][0]['value'] = ($approved=='y' ? 1 : 2);
    }
    $field_ids = array("post_date", "publish_date","expiry_date", "highlight", "posted_by", "edited_by", "last_edit");
    while (list(,$fid) = each($field_ids)) {
        $f_id = AA_Fields::createFieldId($fid);
        if (!$new4id[$f_id]) {
            $new4id[$f_id] = $content4id[$f_id];
        }
    }

    // insert, invalidatecache, not feed
    if ( StoreItem($id, $destination_id, $new4id, true, true, false, 'feed' ) ) {
        AddRelationFeed($id,$item_id); // Add to relation table
    }
    return $id;
}
/** AddRelationFeed function
 * @param $dest_id
 * @param $source_id
 */
function AddRelationFeed($dest_id, $source_id) {
    $p_dest_id   = q_pack_id($dest_id);
    $p_source_id = q_pack_id($source_id);
    // update relation table - stores where is what fed
    DB_AA::sql("INSERT INTO relation ( destination_id, source_id,   flag ) VALUES ( '$p_dest_id', '$p_source_id', '". REL_FLAG_FEED ."' )");
}

function FromFed($item_id) {
    return array_map('unpack_id', DB_AA::select('', 'SELECT source_id FROM relation', array(array('destination_id', $item_id, 'l'), array('flag', REL_FLAG_FEED, 'i'))));
}

function WhereFed($item_id) {
    return array_map('unpack_id', DB_AA::select('', 'SELECT destination_id FROM relation', array(array('source_id', $item_id, 'l'), array('flag', REL_FLAG_FEED, 'i'))));
}

/** CreateFeedTree function
 *  Return feeding tree where items should be fed.
 *  $tree[$from, $to] = array(approved=>$appr, category=>$cat_id) means, that item from
 *  slice $from will be fed to slice $to, category $cat_id and bin depending on $appr :
 *  $appp="y" => active folder else hold bin.
 * @param $sl_id
 * @param $from_category_id
 */

function CreateFeedTree($sl_id, $from_category_id) {
    $slice_queue[$sl_id] = array('approved'=>"y", 'category'=>$from_category_id);

    while (list($sl_id, $val) = each($slice_queue)) {
        if ($val['approved'] == "y") {
            $from_category_id = $val['category'];
            if ($from_category_id) {
                $p_from_cat_id = pack_id($from_category_id);
            }

            $db = getDB();
            $SQL = "SELECT feeds.to_id, feeds.category_id, feeds.all_categories,
                           feeds.to_approved, feeds.to_category_id
                     FROM slice, feeds LEFT JOIN feedperms ON feedperms.from_id=feeds.from_id
                    WHERE feeds.from_id = slice.id
                      AND feeds.from_id='". q_pack_id($sl_id) ."'
                      AND (slice.export_to_all=1
                       OR  feedperms.to_id = feeds.to_id)";  // check perms to feed, too
            $db->query($SQL);

            while ($db->next_record()) {
                $to_id = unpack_id($db->f('to_id'));
                // condition is necessary for multi feeding to this slice
                if (isset($slice_queue[$to_id])) {
                    continue;
                }
                $approved = $db->f('to_approved') ? "y" : "n";
                if (($p_from_cat_id == $db->f('category_id')) OR $db->f('all_categories')) {
                    $slice_queue[$to_id] = $tree[$sl_id][$to_id] = array( 'approved'=>$approved, 'category'=>unpack_id($db->f('to_category_id')));
                }
            }
            freeDB($db);
        }
    }
    return $tree;
}
/** Update function
 * Update $dest_d item according to $item_id
 * @param $item_id
 * @param $slice_id
 * @param $dest_id
 * @param $destination_id
 */
function Update($item_id, $slice_id, $dest_id, $destination_id) {
    global $varset, $itemvarset;

    $slice       = AA_Slice::getModule($slice_id);
    $fields      = $slice->getFields();

    $map         = GetFieldMapping($slice_id, $destination_id);

    $oldcontent    = GetItemContent($dest_id);
    $oldcontent4id = $oldcontent[$dest_id];
    $content       = GetItemContent($item_id);
    $content4id    = $content[$item_id];   // shortcut

    $varset     = new Cvarset;
    $itemvarset = new Cvarset;  // must be defined before insert_fnc_qte

    while (list($key,$fval) = each($oldcontent4id)) {
        // skip field, if field from source item is not mapped to dest
        if ($map[$key]['feedmap_flag'] != FEEDMAP_FLAG_MAP) {
            continue;
        }
        $val = $map[$key]['val'];
        // There is a question if we would not handle status_code in special way.
        // Current setting is, that if source slice admin sets status_code
        // as update&change, then status_code is changed also in destination slice
        // - Source admin APPROVE OR TRASHES items in destiation slice !!!
        if (($fval[0]['flag'] & FLAG_UPDATE) || $fields->getProperty($val,'feed')==STATE_FEEDABLE_UPDATE ) {
            $oldcontent4id[$key][0]['value'] = $content4id[$val][0]['value'];
        }
    }
    StoreItem( $dest_id, $destination_id, $oldcontent4id, false, true, false, 'feed' );
    // update, invalidatecache, not feed
}

/** UpdateItems function
 *  Update all items descending from $item_id
 *  it's expected, that items don't change their category, so $cat_id is unneccessary.
 * @param $item_id
 * @param $slice_id
 */
function UpdateItems($item_id, $slice_id) {     // function UpdateItems($item_id, $slice_id, $tree)
    $items[$item_id] = $slice_id;

    $db = getDB(); 	// do not use global db, because of conflict in Update - StoreItem
    while (list($item_id,$slice_id) = each($items)) {
        $p_item_id = q_pack_id($item_id);

        // get fed items
        $SQL = "SELECT destination_id, slice_id FROM relation INNER JOIN item ON relation.destination_id = item.id
                 WHERE source_id='$p_item_id'
                   AND ((flag & ". REL_FLAG_FEED .") != 0)";
        $db->query($SQL);
        while ( $db->next_record() ) {
            $update     = true;
            $d_id       = unpack_id($db->f('destination_id'));
            $dest_sl_id = unpack_id($db->f('slice_id'));
            //    if (!isset($tree[$slice_id][$dest_sl_id]))        // option : take a $tree into account or not
            //      continue;

            Update($item_id,$slice_id,$d_id,$dest_sl_id);
            $items[$d_id] = $dest_sl_id;
        }
    }
    freeDB($db);
    return $update;
}

/** FeedItem function
 *  Feeds item to all apropriate slices
 *  @param $item_id is unpacked id of feeded item
 */
function FeedItem($item_id) {
    // get item field definition
    $content    = GetItemContent($item_id);
    $content4id = $content[$item_id];   // shortcut

    // if not approved - exit
    if ( $content4id["status_code....."][0]['value'] != '1' ) {
        return false;
    }

    $slice_id   = $content4id["u_slice_id......"][0]['value'];
    $slice      = AA_Slice::getModule($slice_id);

    // get this item category_id
    $cat_group = GetCategoryGroup($slice_id);
    $cat_field = $slice->getFields()->getCategoryFieldId();

    if ($cat_group AND $cat_field) {
        $p_cat_id = DB_AA::select1('SELECT id FROM `constant`', 'id', array(array('group_id', $cat_group), array('value', $content4id[$cat_field][0]['value'])));
        if ( $p_cat_id ) {
            $cat_id = unpack_id($p_cat_id);
        }
    }

    // we try to update items
    if (!UpdateItems($item_id, $slice_id)) {  // if update was done, don't feed;
        if ($tree = CreateFeedTree($slice_id, $cat_id)) {    // if empty tree => no feed
            // now we have the feeding tree in $tree array
            // feed item to all destination slices
            $items_id[$slice_id] = $item_id;
            foreach ($tree as $from_slice_id => $slices) {
                foreach ($slices as $to_slice_id => $atribs) {
                    if ($items_id[$from_slice_id]) {
                        $new_item = FeedItemTo($items_id[$from_slice_id], $from_slice_id, $to_slice_id, $atribs['approved'], $atribs['category']);
                        $items_id[$to_slice_id] = $new_item;
                    }
                }
            }
        }
    }
}
?>