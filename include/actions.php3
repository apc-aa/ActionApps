<?php
/**
 * File contains definitions of functions which corresponds with actions
 * on Item Manager page (admin/index.php3) - manipulates with items
 *
 * Should be included to other scripts (admin/index.php3)
 *
 * @package ControlPanel
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
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

/** Move item to app/hold/trash based on param
 *  @param $status    static function parameter defined in manager action
 *                   in this case it holds bin number, where the items should go
 *  @param $item_arr array, where keys are unpacked ids of items prefixed by
 *                   'x' character (javascript purposes only)
 *  @param $akce_param additional parameter for the action - not used here
 */
function Item_MoveItem($status, $item_arr, $akce_param) {
    global $event, $auth, $slice_id, $pagecache;
    $db = getDB();
    $now = now();
    foreach ( $item_arr as $it_id => $foo ) {
        $item_ids[] = pack_id(substr($it_id,1));      // remove initial 'x'
    }

    if ($item_ids) {
        $SQL = "UPDATE item SET
           status_code = $status,
           last_edit   = '$now',
           edited_by   = '". quote(isset($auth) ? $auth->auth["uid"] : "9999999999")."'";

        // E-mail Alerts
        $moved2active = ( ($status == 1) ? $now : 0 );
        $SQL .= ", moved2active = $moved2active";

        $SQL .= " WHERE id IN ('".join_and_quote("','",$item_ids)."')";
        $db->tquery ($SQL);
        $event->comes('ITEMS_MOVED', $slice_id, 'S', $item_ids, $status );
    }
    $pagecache->invalidateFor("slice_id=$slice_id");  // invalidate old cached values

    freeDB($db);
    return false;                                     // OK - no error
}

/** Export items to another slice
 *  @param $slice      slice object - slice, from which we export
 *  @param $item_arr   array, where keys are unpacked ids of items prefixed by
 *                     'x' character (javascript purposes only)
 *  @param $akce_param Special string, where destination slices are coded.
 *                     The format is "<status>-<unpacked_slice_id>,<status>-.."
 */
function Item_Feed($slice, $item_arr, $akce_param) {
    if (strlen($akce_param) < 1) {
        return _m('No slice selected');
    }
    $export_to = split(",", $akce_param);          // <status>-<slice_id> pairs

    foreach ( $item_arr as $it_id => $foo ) {
        $it_id = substr($it_id,1);                 // remove initial 'x'
        foreach ( $export_to as $exp_slice_pair ) {
            list($status,$sid) = split("-", $exp_slice_pair);
            FeedItemTo($it_id, $slice->unpacked_id(), $sid, $slice->fields('record'),
                     ($status=='1' ? 'y':'n'), 0);
        }
    }
    return false;                                  // OK - no error
}

/**
 *  Handler for DeleteTrash switch - Delete all items in the trash bin
 *  @param $value      Not used in this handler
 *  @param $param      Not used in this handler
 */
function Item_DeleteTrash($param, $item_arr, $akce_param) {
    global $pagecache, $slice_id;
    $db = getDB();

    if ( !IfSlPerm(PS_DELETE_ITEMS) ) {    // permission to delete items?
        return _m("You have not permissions to remove items");
    }
    $db->query("SELECT id FROM item
               WHERE status_code=3 AND slice_id = '". q_pack_id($slice_id) ."'");
    $items_to_delete = "";
    while( $db->next_record() )
        $items_to_delete[] = $db->f("id");

//    $event->comes('ITEMS_BEFORE_DELETE', $slice_id, 'S', $items_to_delete);

    // delete content of all fields
    // don't worry about fed fields - content is copied
    $wherein = "IN ('".join_and_quote("','", $items_to_delete)."')";
    $db->query("DELETE FROM content WHERE item_id ".$wherein);
    $db->query("DELETE FROM item WHERE id ".$wherein);

    $pagecache->invalidateFor("slice_id=$slice_id");
    freeDB($db);
}

/** Handler for Tab switch - switch between bins */
function Item_Tab($value, $param) {
    global $manager;
    $GLOBALS['r_state']['bin'] = $value;
    $manager->go2page(1);
}


function FeedAllItems($chb, $fields) {    // Feed all checked items
  global $db;
  if( isset($chb) AND is_array($chb) ) {
    reset( $chb );
    while( list($it_id,) = each( $chb ) ) {
      FeedItem( substr($it_id,1), $fields );       // substr removes first 'x'
    }
  }
}

?>
