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
use AA\Managed;

require_once __DIR__ . "/stringexpand.php"; // for translateString()

/** GetFieldMapping function
 *  Find fields mapping.
 * @param $from_slice_id
 * @param $destination_id
 * @return array
 */
function GetFieldMapping($from_slice_id, $destination_id): array
{
    $fields_from = AA_Slice::getModule($from_slice_id)->getFields()->getPriorityArray();
    $fields_to   = AA_Slice::getModule($destination_id)->getFields()->getPriorityArray();

    if (empty($fields_to)) {
        return [];
    }

    // fill $m array with mapping set by user on mapping page (and stored to db)
    foreach (DB_AA::select([], 'SELECT from_field_id, to_field_id, flag, value FROM feedmap', [['from_slice_id', $from_slice_id, 'l'], ['to_slice_id', $destination_id, 'l']]) as $row) {
        switch ($row['flag']) {
            case FEEDMAP_FLAG_MAP:
                $val = $row['from_field_id'];
                break;
            case FEEDMAP_FLAG_VALUE:
            case FEEDMAP_FLAG_JOIN:
                $val = $row['value'];
                break;
            case FEEDMAP_FLAG_RSS:
                huhe("Warning: RSS field mapping appearing in non RSS feed");
                $val = "";
                break;
            default:
                $val = "";
                break;
        }
        $m[$row['to_field_id']] = ["feedmap_flag" => $row['flag'],"val" => $val];
    }

    foreach ($fields_to as $field_id) {
        if ($m[$field_id]) {
            $map[$field_id] = $m[$field_id];                 // set if mapped
        } else {                                 // if not mapped - store in the same; if not exist, set empty
            $map[$field_id] = in_array($field_id, $fields_from) ? ["feedmap_flag" => FEEDMAP_FLAG_MAP,"val" => $field_id]
                                                                  : ["feedmap_flag" => FEEDMAP_FLAG_EMPTY,"val" => ""];
        }
    }

    return $map;
}

/** GetBaseItem function
 * get base item of $item_id from relation table
 *
 * @param  string  $item_id
 *
 * @return string
 */
function GetBaseItem(string $item_id): string
{
    $dest_id = $item_id;
    $seen    = [];   // a broken relation - an item fed to itself, a loop - would be traced forever

    while ($item_id && !isset($seen[$item_id])) {
        $seen[$item_id] = true;
        $dest_id = $item_id;
        $source_id = DB_AA::select1('source_id', 'SELECT source_id FROM relation', [['destination_id', $dest_id, 'l'], ['flag', REL_FLAG_FEED, 'set']]);
        $item_id = ($source_id !== false) ? unpack_id($source_id) : false;
    }
    return $dest_id;
}

/** IsItemFed function
 * find if item was already fed to $destination slice or it comes from it
 *
 * @param  string  $item_id
 * @param  string  $destination
 *
 * @return bool
 */
function IsItemFed(string $item_id, string $destination): bool
{
    $p_destination = q_pack_id($destination);
    $base_id       = GetBaseItem($item_id);  // Trace back to the original id

    // if item comes from $destination slice (i.e. $destination slice contains base record)
    if ($p_destination == DB_AA::select1('slice_id', 'SELECT slice_id FROM `item`', [['id', $base_id, 'l']])) {
        return true;
    }

    $sources = array_map('unpack_id', DB_AA::select('source_id', 'SELECT source_id FROM relation, item', [['relation.destination_id', 'item.id', 'j'], ['item.slice_id', pack_id($destination), 'b'], ['flag', REL_FLAG_FEED, 'set']]));

    // Test array for containing an item fed from same baseid
    foreach ($sources as $source_id) {
        if ($base_id === GetBaseItem($source_id)) {
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
function FeedJoin($columns, $fields, $params, &$result): void
{
    $params = str_replace("#:", "#~", $params);
    $params = explode(":", $params);
    $i = 0;
    $result_val = '';
    // should all the joined fields be updated? 0 = don't know, -1 = no, 1 = yes
    $update = 0;
    foreach ($params as $val) {
        if ($i++ % 2 == 0) {
            switch ($fields->getProperty($val, 'feed')) {
                case STATE_UNFEEDABLE:	 return;
                case STATE_FEEDNOCHANGE:
                    $result['flag'] |= FLAG_FREEZE;
                    $update = -1;
                    break;
                case STATE_FEEDABLE_UPDATE_LOCKED:
                    $result['flag'] |= FLAG_FREEZE; // break shouldn't be here!

                    // no break
                case STATE_FEEDABLE_UPDATE:
                    if ($update > -1) {
                        $update = 1;
                    }
                    break;
            }
            $result_val .= (is_array($columns[$val]) ? $columns[$val][0]['value'] : $val);
        } else {
            $result_val .= str_replace('\n', "\n", $val);
        }
    }
    $result['flag'] |= FLAG_UPDATE;
    //if ($update != 1) // the function "update" doesn't support joined fields
    $result['flag'] -= FLAG_UPDATE;
    $result['value'] = str_replace("#~", ":", $result_val);
}


/** FeedItemTo function
 * copy one item
 *
 * @param  string  $item_id
 * @param  string  $from_slice_id
 * @param  string  $destination_id
 * @param          $approved
 * @param  int     $tocategory
 * @param  array   $content
 *
 * @return string|false
 */
function FeedItemTo(string $item_id, string $from_slice_id, string $destination_id, $approved, $tocategory = 0, array $content = []): string|false
{

    if ($destination_id === $from_slice_id) { // don't feed into the same slice
        return false;
    }

    // don't feed if the item is already fed.
    if (IsItemFed($item_id, $destination_id)) {
        return false;
    }

    $map = GetFieldMapping($from_slice_id, $destination_id);

    if (!$content) {
        $content = GetItemContent($item_id);
    }
    $content4id    = $content[$item_id];   // shortcut

    $from_slice  = AA_Slice::getModule($from_slice_id);
    $destination = AA_Slice::getModule($destination_id);
    $fields      = $from_slice->getFields();
    $catfieldid  = $fields->firstOfTypeFieldId();

    if ($catfieldid && (string) $tocategory != "0") {
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
            case FEEDMAP_FLAG_EMPTY: continue(2);
            case FEEDMAP_FLAG_MAP:
                if ($fields->getProperty($val, 'feed') == STATE_UNFEEDABLE) {
                    continue(2);
                }

                $new4id[$newfld] = $content4id[$val];
                $minkey          = (is_array($new4id[$newfld]) && $new4id[$newfld]) ? min(array_keys($new4id[$newfld])) : 0; // for language translation we do not start with 0
                if ($fields->getProperty($val, 'feed') == STATE_FEEDNOCHANGE) {
                    $new4id[$newfld][$minkey]['flag'] |= FLAG_FREEZE;  // don't allow to change
                } elseif ($fields->getProperty($val, 'feed') == STATE_FEEDABLE_UPDATE_LOCKED) {
                    $new4id[$newfld][$minkey]['flag'] |= FLAG_FREEZE | FLAG_UPDATE;   //update and don't allow to change
                }
                break;
                // in value you can specify not only new value but you can write there
                // also AA expression, which is unaliased - example:
                // <a href="{source_href.....}">{source..........}</a>
            case FEEDMAP_FLAG_VALUE:
                // create item from source data (in order we can unalias)
                if (!$item2fed) {
                    $item2fed = new AA_Item($content4id, $fields->getAliases());
                }
                $new4id[$newfld][0]['value'] = $item2fed->unalias($val);
                break;
            case FEEDMAP_FLAG_JOIN:
                FeedJoin($content4id, $fields, $val, $new4id[$newfld][0]);
                break;
            case FEEDMAP_FLAG_RSS:
                huhe("Warning RSS feed mapping found in non RSS feedat feeding:240");
                break;
        }

        $new4id[$newfld][$minkey]['flag'] |= FLAG_FEED;      // mark as fed

        // category mapping
        if ($newfld == $catfieldid && (string) $tocategory != "0") {
            // if 0 - don't change category
            $new4id[$newfld][$minkey]['value'] = $destinationcat;
        }
    }

    // --- fill required fields if not set ---

    // status_code can be redefined in 'Mapping'
    // Use it only when 'Value' is used for status_code (we do not want to copy
    // status_code field from source item (since it is always '1')

    if (!($map['status_code.....']['feedmap_flag'] == FEEDMAP_FLAG_VALUE && (int) $new4id['status_code.....'][0]['value'] > 0)) {
        $new4id['status_code.....'][0]['value'] = ($approved === 'y' ? 1 : 2);
    }
    $field_ids = ["post_date", "publish_date","expiry_date", "highlight", "posted_by", "edited_by", "last_edit"];
    foreach ($field_ids as $fid) {
        $f_id = AA_Fields::createFieldId($fid);
        if (!$new4id[$f_id]) {
            $new4id[$f_id] = $content4id[$f_id];
        }
    }

    // insert, invalidatecache, not feed
    if (StoreItem($id, $destination_id, $new4id, true, true, false, 'feed')) {
        AddRelationFeed($id, $item_id); // Add to relation table
    }
    return $id;
}

/** AddRelationFeed function
 * @param     $dest_id
 * @param     $source_id
 * @param int $flag
 */
function AddRelationFeed($dest_id, $source_id, $flag = REL_FLAG_FEED): void
{
    if ($dest_id != $source_id) {
        $p_dest_id = xpack_id($dest_id);
        $p_source_id = xpack_id($source_id);
        // update relation table - stores where is what fed
        DB_AA::sql("INSERT INTO relation (destination_id, source_id, flag ) VALUES ( $p_dest_id, $p_source_id, '$flag' )");
    }
}

function FromFed($item_id): array
{
    return array_map('unpack_id', DB_AA::select('', 'SELECT source_id FROM relation', [['destination_id', $item_id, 'l'], ['flag', REL_FLAG_FEED, 'i']]));
}

function WhereFed($item_id): array
{
    return array_map('unpack_id', DB_AA::select('', 'SELECT destination_id FROM relation', [['source_id', $item_id, 'l'], ['flag', REL_FLAG_FEED, 'i']]));
}

/** UpdateFedItem function
 * Update $dest_d item according to $item_id
 * @param $item_id
 * @param $slice_id
 * @param $dest_id
 * @param $destination_id
 */
function UpdateFedItem($item_id, $slice_id, $dest_id, $destination_id): void
{

    if ($item_id == $dest_id || $slice_id == $destination_id) {
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

    foreach ($destcontent4id as $key => $fval) {

        // skip field, if field from source item is not mapped to dest
        if (!isset($map[$key]) || $map[$key]['feedmap_flag'] != FEEDMAP_FLAG_MAP) {
            continue;
        }
        $val = $map[$key]['val'];
        // There is a question if we would not handle status_code in special way.
        // Current setting is, that if source slice admin sets status_code
        // as update&change, then status_code is changed also in destination slice
        // - Source admin APPROVE OR TRASHES items in destiation slice !!!
        if (($fval[0]['flag'] & FLAG_UPDATE) || $fields->getProperty($val, 'feed') == STATE_FEEDABLE_UPDATE) {
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
function UpdateItems($item_id, $slice_id): bool
{     // function UpdateItems($item_id, $slice_id, $tree)
    $items  = [$item_id => $slice_id];
    $update = false;

    foreach ($items as $item_id => $slice_id) {
        $destinations = DB_AA::select(['iid' => 'sid'], "SELECT LOWER(HEX(`destination_id`)) AS iid, LOWER(HEX(`slice_id`)) as sid FROM `relation`, `item`", [
            ['relation.destination_id', 'item.id', 'j'],
            ['source_id', $item_id, 'l'],
            ['flag', REL_FLAG_FEED, 'set'],
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
 *
 * @param  ItemContent  $content
 *
 * @return bool
 */
function FeedItem(ItemContent $content): bool
{
    // if not approved - exit
    if ($content->getStatusCode() != '1') {
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

function GetFeedManaged(bool $extended = false): Managed
{
    $buttons  = [
        'Map'      => a_href(get_admin_url('se_mapping.php', ['rssfeed_id={feed_id}']), _m('Map'), ['class' => 'aa-button']),
        'Queue'    => a_href(get_admin_url('xmlclient.php', ['rssfeed_id={feed_id}']), _m('Run (queue)'), ['class' => 'aa-button-run']),
        'Test'     => a_href(get_admin_url('xmlclient.php', ['rssfeed_id={feed_id}&fill=0&debugfeed=9']), _m('Test'), ['class' => 'aa-button-test']),
        'Source'   => a_href(get_admin_url('xmlclient.php', ['rssfeed_id={feed_id}&display=1']), _m('Source'), ['class' => 'aa-button-show']),
        'Validate' => a_href('https://validator.w3.org/feed/check.cgi?url={safe:{server_url}}', _m('Validate'), ['class' => 'aa-button-test']),
    ];
    $managed = AA::Metabase()->getManagerConf('rssfeeds', null, null, [], $buttons);

    $managed->setColumn('server_url', '<a href="{server_url}">{shorten:{server_url}:60:3}</a>');

    // the superadmin list of all feeds edits the feeds of any slice; else only those of this one
    if (!$extended) {
        $managed->setModule(AA::$module_id);
    }
    $managed->setWidgetSetting('slice_id', ['enum' => AA_Module::getUserModules(), 'name' => _m('Local slice')]);
    if ($extended) {
        $managed->setColumns2show(['name','server_url','feed_id', 'warnings', 'slice_id']);
        $managed->setColumn('slice_id', '{modulefield:{slice_id}:name}');
    } else {
        $managed->setColumns2show(['name','server_url','feed_id', 'warnings']);
        $managed->setWidgetSetting('slice_id', ['type' => 'hidden', 'name' => _m('Local slice')]);
    }
    $managed->setWidgetSetting('feed_id', ['name' => _m('ID')]);
    $managed->setWidgetSetting('server_url', ['name' => _m('URL of the feed'), 'help' => _m("e.g. https://www.someplace.com/rss/index.xml") . '<br>' . _m("Do NOT change this URL if you already set the Mapping (or update mapping after the change). The mapping is tied to this url!")]);
    $managed->setWidgetSetting('name', ['name' => _m('RSS Feed name')]);
    $logtext = '<span title="' . _m('warnings last hour') . '">{internal:AA_Log:count:CSN:RSS#:{feed_id}:3600}</span> / <span title="' . _m('warnings last day') . '">{internal:AA_Log:count:CSN:RSS#:{feed_id}:86400}</span> / <span title="' . _m('warnings last month') . '">{internal:AA_Log:count:CSN:RSS#:{feed_id}:2592000}</span>';

    if (IsSuperadmin()) {
        $logtext = a_href(get_admin_url('aa_log.php?&srchbr_akce=1&srchbr_field%5B0%5D=selector&srchbr_oper%5B0%5D==&srchbr_value%5B0%5D=RSS:{feed_id}&srchbr_order%5B0%5D=time&srchbr_order_dir%5B0%5D=on'), $logtext);
    }
    $managed->addColumn('warnings', _m('Warnings in log [hour/day/month]'), $logtext);
    return $managed;
}

function GetAAFeedManaged(): Managed
{
    $base_feed_url = get_admin_url('xmlclient.php', ['feed_id={feed_id}&time={rawurlencode:{newest_item}}']);
    $buttons  = [
        'Test'     => a_href(get_url($base_feed_url, ['fill' => 0, 'debugfeed' => 9]), _m('Test'), ['class' => 'aa-button-test']),
        'Source'   => a_href(get_url($base_feed_url, ['display' => 1 ]), _m('Source'), ['class' => 'aa-button-show']),
        'Queue'    => a_href($base_feed_url, _m('Run (queue)'), ['class' => 'aa-button-run']),
        'Map'      => a_href(get_admin_url('se_mapping.php', ['feed_id={feed_id}']), _m('Map'), ['class' => 'aa-button']),
        'Edit'     => false,
        'Delete'   => false,
    ];

    // Old tabledit functions
    //
    //function tv_field_value($feed_id,$param,$var) {
    //    return "+'&$param='+encodeURIComponent(document.tv_aarsstest.elements['val[$feed_id][$var]'].value)";
    //}
    ///** showFeedActions function
    // * @param $feed_id
    // * @return string - set of links
    // * @noinspection PhpUnused
    // */
    //    function showFeedActions($feed_id) {
    //        $url = "'".AA_INSTAL_URL ."admin/xmlclient.php?feed_id=$feed_id'".
    //            tv_field_value($feed_id,'fill','fire').
    //            tv_field_value($feed_id,'time','newest_item').
    //            tv_field_value($feed_id,'debugfeed','debug');
    //        $out  = "<a href=\"javascript:OpenWindowTop($url)\" title=\"downloads remote items from the feed and possibly store it to the desired slice (if 'write' checkbox is checked\">"._m('feed')."</a>&nbsp;";
    //        $out .= "<a href=\"javascript:OpenWindowTop('https://validator.w3.org/feed/check.cgi?url='+encodeURIComponent($url+'&display=1'))\" title=\"checks the validity of the feed by feedvalidator.org\">"._m('validate')."</a>&nbsp;";
    //        $out .= "<a href=\"javascript:OpenWindowTop($url+'&display=1')\" title=\"displays the source data in new window\">"._m('show')."</a>";
    //        return $out;
    //}

    $managed = AA::Metabase()->getManagerConf('external_feeds', null, null, [], $buttons);
    $managed->setModule(AA::$module_id);
    $managed->setColumns2show(['name',  'feed_id', 'node_name', 'remote_slice_id', 'slice_id', 'feed_mode', 'warnings', 'newest_item' ]);
    $managed->setAction('add', false);
    $managed->setAction('edit', false);

    $managed->setColumn('slice_id', '{modulefield:{slice_id}:name}<br><small>{slice_id}</small>');
    $managed->setColumn('remote_slice_id', '{remote_slice_name}<br><small>{remote_slice_id}</small>');

    $managed->setWidgetSetting('node_name', ['name' => _m('Remote node')]);
    $managed->setWidgetSetting('slice_id', ['name' => _m('Local slice')]);
    $managed->setWidgetSetting('remote_slice_id', ['name' => _m('Remote slice')]);
    $managed->setWidgetSetting('feed_id', ['name' => _m('ID')]);

    $logtext = '<span title="' . _m('warnings last hour') . '">{internal:AA_Log:count:CSN:RSS#:{feed_id}:3600}</span> / <span title="' . _m('warnings last day') . '">{internal:AA_Log:count:CSN:RSS#:{feed_id}:86400}</span> / <span title="' . _m('warnings last month') . '">{internal:AA_Log:count:CSN:RSS#:{feed_id}:2592000}</span>';
    if (IsSuperadmin()) {
        $logtext = a_href(get_admin_url('aa_log.php?&srchbr_akce=1&srchbr_field%5B0%5D=selector&srchbr_oper%5B0%5D==&srchbr_value%5B0%5D=RSS:{feed_id}&srchbr_order%5B0%5D=time&srchbr_order_dir%5B0%5D=on'), $logtext);
    }
    $managed->addColumn('warnings', _m('Warnings in log [hour/day/month]'), $logtext);

    return $managed;
}
