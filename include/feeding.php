<?php
/**
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/


//
// Functions for feeding
//

use AA\IO\DB\DB_AA;

require_once __DIR__ . "/stringexpand.php"; // for translateString()

/** GetFieldMapping function
 *  Find fields mapping.
 * @param $from_slice_id
 * @param $destination_id
 * @return array
 */
function GetFieldMapping($from_slice_id, $destination_id) {
    $fields_from = AA_Slice::getModule($from_slice_id)->getFields()->getPriorityArray();
    $fields_to   = AA_Slice::getModule($destination_id)->getFields()->getPriorityArray();

    if (empty($fields_to)) {
        return [];
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
        $m[$db->f('to_field_id')] = ["feedmap_flag"=>$db->f('flag'),"val"=>$val];
    }

    foreach ($fields_to as $field_id ) {
        if ( $m[$field_id] ) {
            $map[$field_id] = $m[$field_id];                 // set if mapped
        } else {                                 // if not mapped - store in the same; if not exist, set empty
            $map[$field_id] = in_array($field_id, $fields_from) ? ["feedmap_flag"=>FEEDMAP_FLAG_MAP,"val"=>$field_id] :
                                                                  ["feedmap_flag"=>FEEDMAP_FLAG_EMPTY,"val"=>""];
        }
    }

    freeDb($db);
    return $map;
}

/** GetBaseItem function
 * get base item of $item_id from relation table
 * @param $item_id
 * @return mixed
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
 * @return bool
 */
function IsItemFed($item_id, $destination) {
    $p_destination = q_pack_id($destination);
    $base_id       = GetBaseItem($item_id);  // Trace back to the original id

    // if item comes from $destination slice (i.e. $destination slice contains base record)
    if ( $p_destination == DB_AA::select1('slice_id', 'SELECT slice_id FROM `item`', [['id', $base_id, 'l']]) ) {
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
 *  @param  $columns - values of the processed item (db record)
 *  @param  $fields  - source fields description (object)
 *  @param  $params  - parameters of the "join" mapping function - separated by
 *                    ':', contains field names separated by HTML separator
 *                    description (the result will be the fields' content
 *                    separated by the separators)
 *  @param  $result  - the value to be changed (see FeedItemTo)
 *  @return void - changed $result
 *  @author   Jakub Adamek
 */
function FeedJoin($columns, $fields, $params, &$result) {
    $params = str_replace ("#:","#~",$params);
    $params = explode(":",$params);
    $i = 0;
    $result_val = '';
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
 * @return bool|mixed|string
 */
function FeedItemTo($item_id, $from_slice_id, $destination_id, $approved, $tocategory=0, $content="") {

    if ( $destination_id == $from_slice_id ) { // don't feed into the same slice
        return false;
    }

    // don't feed if the item is already fed.
    if (IsItemFed($item_id, $destination_id)) {
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
    $catfieldid  = $fields->firstOfTypeFieldId();

    if ( $catfieldid AND ( (string)$tocategory != "0" ) ) {
        $destinationcat = DB_AA::select1('value', 'SELECT value FROM constant', [['id', $tocategory, 'l']]);
    }

    $id          = new_id();
    $fields_to   = $destination->getFields()->getPriorityArray();
    $item2fed    = null;

    // prepare new4id array before call StoreItem function
    foreach ($fields_to as $newfld) {
        $flag   = $map[$newfld]['feedmap_flag'];
        $val    = $map[$newfld]['val'];
        $minkey = 0;

        switch ($flag) {
            case FEEDMAP_FLAG_EMPTY : continue(2);
            case FEEDMAP_FLAG_MAP :
                if ($fields->getProperty($val,'feed')==STATE_UNFEEDABLE) {
                    continue(2);
                }

                $new4id[$newfld] = $content4id[$val];
                $minkey          = (is_array($new4id[$newfld]) AND $new4id[$newfld]) ? min(array_keys($new4id[$newfld])) : 0; // for language translation we do not start with 0
                if ($fields->getProperty($val,'feed')==STATE_FEEDNOCHANGE ) {
                    $new4id[$newfld][$minkey]['flag'] |= FLAG_FREEZE;  // don't allow to change
                } elseif ($fields->getProperty($val,'feed')==STATE_FEEDABLE_UPDATE_LOCKED) {
                    $new4id[$newfld][$minkey]['flag'] |= FLAG_FREEZE | FLAG_UPDATE;   //update and don't allow to change
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

        $new4id[$newfld][$minkey]['flag'] |= FLAG_FEED;      // mark as fed

        // category mapping
        if ( $newfld == $catfieldid ) {
            if ( (string)$tocategory != "0" ) {    // if 0 - don't change category
                $new4id[$newfld][$minkey]['value'] = $destinationcat;
            }
        }
    }

    // --- fill required fields if not set ---

    // status_code can be redefined in 'Mapping'
    // Use it only when 'Value' is used for status_code (we do not want to copy
    // status_code field from source item (since it is always '1')

    if ( !(($map['status_code.....']['feedmap_flag'] == FEEDMAP_FLAG_VALUE) AND
          ((int)$new4id['status_code.....'][0]['value'] > 0)) ) {
        $new4id['status_code.....'][0]['value'] = ($approved==='y' ? 1 : 2);
    }
    $field_ids = ["post_date", "publish_date","expiry_date", "highlight", "posted_by", "edited_by", "last_edit"];
    foreach($field_ids as $fid) {
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
 * @param     $dest_id
 * @param     $source_id
 * @param int $flag
 */
function AddRelationFeed($dest_id, $source_id, $flag = REL_FLAG_FEED) {
    if ($dest_id != $source_id) {
        $p_dest_id = q_pack_id($dest_id);
        $p_source_id = q_pack_id($source_id);
        // update relation table - stores where is what fed
        DB_AA::sql("INSERT INTO relation (destination_id, source_id, flag ) VALUES ( '$p_dest_id', '$p_source_id', '$flag' )");
    }
}

function FromFed($item_id) {
    return array_map('unpack_id', DB_AA::select('', 'SELECT source_id FROM relation', [['destination_id', $item_id, 'l'], ['flag', REL_FLAG_FEED, 'i']]));
}

function WhereFed($item_id) {
    return array_map('unpack_id', DB_AA::select('', 'SELECT destination_id FROM relation', [['source_id', $item_id, 'l'], ['flag', REL_FLAG_FEED, 'i']]));
}

/** UpdateFedItem function
 * Update $dest_d item according to $item_id
 * @param $item_id
 * @param $slice_id
 * @param $dest_id
 * @param $destination_id
 */
function UpdateFedItem($item_id, $slice_id, $dest_id, $destination_id) {

    if (($item_id == $dest_id) OR ($slice_id==$destination_id)) {
        return;
    }

    $fields      = AA_Slice::getModule($slice_id)->getFields();

    $map         = GetFieldMapping($slice_id, $destination_id);

    $destcontent    = GetItemContent($dest_id);
    $destcontent4id = $destcontent[$dest_id];
    $content        = GetItemContent($item_id);
    $content4id     = $content[$item_id];   // shortcut

    $updatecontent  = new ItemContent();
    $updatecontent->setItemID($dest_id);
    $updatecontent->setSliceID($destination_id);

    $do_update = false;

    foreach($destcontent4id as $key => $fval) {

        // skip field, if field from source item is not mapped to dest
        if (!isset($map[$key]) or ($map[$key]['feedmap_flag'] != FEEDMAP_FLAG_MAP)) {
            continue;
        }
        $val = $map[$key]['val'];
        // There is a question if we would not handle status_code in special way.
        // Current setting is, that if source slice admin sets status_code
        // as update&change, then status_code is changed also in destination slice
        // - Source admin APPROVE OR TRASHES items in destiation slice !!!
        if (($fval[0]['flag'] & FLAG_UPDATE) || $fields->getProperty($val,'feed')==STATE_FEEDABLE_UPDATE ) {
            $updatecontent->setAaValue($key, new AA_Value($content4id[$val], $fval[0]['flag']));
            $do_update = true;
        }
    }

    if ($do_update) {                       // update, invalidatecache, not feed
        $updatecontent->storeItem('update', [true, false], 'feed');
    }
}

/** UpdateItems function
 *  Update all items descending from $item_id
 *  it's expected, that items don't change their category, so $cat_id is unneccessary.
 * @param $item_id
 * @param $slice_id
 * @return bool
 */
function UpdateItems($item_id, $slice_id) {     // function UpdateItems($item_id, $slice_id, $tree)
    $items  = [$item_id => $slice_id];
    $update = false;

    foreach($items as $item_id => $slice_id) {
        $destinations = DB_AA::select(['iid'=>'sid'], "SELECT LOWER(HEX(`destination_id`)) AS iid, LOWER(HEX(`slice_id`)) as sid FROM `relation`, `item`", [
            ['relation.destination_id', 'item.id', 'j'],
            ['source_id', $item_id, 'l'],
            ['flag', REL_FLAG_FEED, 'set']
        ]);
        foreach ($destinations as $d_iid => $d_sid) {
            $update     = true;
            //    if (!isset($tree[$slice_id][$dest_sl_id]))        // option : take a $tree into account or not
            //      continue;

            UpdateFedItem($item_id, $slice_id, $d_iid, $d_sid);
            $items[$d_iid] = $d_sid;
        }
    }
    return $update;
}

/** FeedItem function
 *  Feeds item to all appropriate slices
 * @param ItemContent $content4id - fed item
 * @return bool
 */
function FeedItem(ItemContent $content): bool {
    // if not approved - exit
    if ( $content->getStatusCode() != '1' ) {
        return false;
    }

    $item_id    = $content->getItemID();
    $slice_id   = $content->getSliceID();

    // we try to update items
    if (!UpdateItems($item_id, $slice_id)) {  // if update was done, don't feed;
        $slice = AA_Slice::getModule($slice_id);
        if ($tree = $slice->getFeedTree($content)) {    // if empty tree ([]) => no feed
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
    return true;
}

function GetFeedManaged(): \AA\Managed {
    $buttons  = [
        'Map'      => a_href(get_admin_url('se_mapping.php', ['rssfeed_id={feed_id}']), _m('Map'), ['class' => 'aa-button']),
        'Queue'    => a_href(get_admin_url('xmlclient.php',  ['rssfeed_id={feed_id}']), _m('Run (queue)'), ['class' => 'aa-button-run']),
        'Test'     => a_href(get_admin_url('xmlclient.php',  ['rssfeed_id={feed_id}&fill=0']), _m('Test'), ['class' => 'aa-button-test']),
        'Source'   => a_href(get_admin_url('xmlclient.php',  ['rssfeed_id={feed_id}&display=1']), _m('Source'), ['class' => 'aa-button-show']),
        'Validate' => a_href('https://validator.w3.org/feed/check.cgi?url={safe:{server_url}}', _m('Validate'), ['class' => 'aa-button-test']),
    ];

    $managed = AA::Metabase()->getManagerConf('rssfeeds',null,null,[],$buttons);
    $managed->setColumns2show(['name','server_url','feed_id', 'warnings']);
    $managed->setColumn('server_url', '<a href="{server_url}">{shorten:{server_url}:60:3}</a>');

    $managed->setModule(AA::$module_id);
    $managed->setWidgetSetting('slice_id', ['enum'=>AA_Module::getUserModules(), 'name'=>_m('Owner')]);
    $managed->setWidgetSetting('slice_id', ['type'=>'hidden', 'name'=>_m('Owner')]);
    $managed->setWidgetSetting('feed_id',                                       ['name'=>_m('ID')]);
    $managed->setWidgetSetting('server_url',                                    ['name'=>_m('URL of the feed'), 'help'=> _m("e.g. https://www.someplace.com/rss/index.xml").'<br>'._m("Do NOT change this URL if you already set the Mapping (or update mapping after the change). The mapping is tied to this url!")]);
    $managed->setWidgetSetting('name',                                          ['name'=>_m('RSS Feed name')]);
    $logtext = '<span title="'._m('warnings last hour').'">{internal:AA_Log:CSN:RSS#:{feed_id}:3600}</span> / <span title="'._m('warnings last day').'">{internal:AA_Log:CSN:RSS#:{feed_id}:86400}</span> / <span title="'._m('warnings last month').'">{internal:AA_Log:CSN:RSS#:{feed_id}:2592000}</span>';

    if (IsSuperadmin()) {
        $logtext = a_href(get_admin_url('aa_log.php?&srchbr_akce=1&srchbr_field%5B0%5D=selector&srchbr_oper%5B0%5D==&srchbr_value%5B0%5D=RSS:{feed_id}&srchbr_order%5B0%5D=time&srchbr_order_dir%5B0%5D=on'), $logtext);
    }
    $managed->addColumn('warnings',_m('Warnings in log [hour/day/month]'), $logtext);
    return $managed;
}

