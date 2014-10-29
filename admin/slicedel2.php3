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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
// expected $del - unpacked id of slice to delete

require_once dirname(__FILE__). "/../include/init_page.php3";
require_once AA_INC_PATH . "feeding.php3";
require_once AA_INC_PATH . "msgpage.php3";
require_once AA_INC_PATH . "modutils.php3";


if ($cancel) {
    go_url( $sess->url(self_base() . "index.php3"));
}

if ($del OR $deletearr) {
    if (!IsSuperadmin()) {
        MsgPage($sess->url(self_base())."index.php3", _m("You don't have permissions to delete slice."));
        exit;
    }
} else {
    MsgPage($sess->url(self_base())."index.php3", _m("You don't have permissions to delete slice."));
    exit;
}

$err["Init"] = "";      // error array (Init - just for initializing variable
$p_del       = q_pack_id($del);

if ($del) {
    DeleteOneModule($del);
} else {
    foreach ($deletearr as $del_id) {
        DeleteOneModule($del_id);
    }
}

page_close();                                // to save session variables
// There is a bug in here, that typically if you go SliceAdmin->delete->AA->delete it
// will delete your current slice, and leave you nowhere to go to, you have to login again (mitra)
go_url(con_url($sess->url("slicedel.php3"), "Msg=".rawurlencode(_m("Slice successfully deleted, tables are optimized"))));

/** DeleteOneModule function
 * @param $del
 */
function DeleteOneModule($del) {
    global $g_modules;

    // check if module can be deleted
    ExitIfCantDelete( $del);

    // delete module (from common module table)
    DeleteModule( $del );

    // delete slice from permission system -----------------------------------------
    DelPermObject($del, "slice");

    switch ($g_modules[$del]['type']) {
        case 'Alerts': DeleteAlerts($del); break;
        case 'S': DeleteSlice($del); break;
        default: echo "Functions for deleting module type ".$g_modules[$del]['type']." are not yet defined."; exit;
    }
}
/** DeleteAlerts function
 * @param $module_id
 */
function DeleteAlerts($module_id) {
    if ( ($collectionid = DB_AA::select1('SELECT id FROM `alerts_collection`', 'id', array(array('moduleid',$module_id, 'l')))) === false) {
        return;
    }
    DB_AA::sql('DELETE LOW_PRIORITY FROM `alerts_collection_filter`', array(array('collectionid', $collectionid)));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `alerts_collection`', array(array('id', $collectionid)));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `alerts_collection_howoften`', array(array('id', $collectionid)));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `module`', array(array('id', $module_id, 'l')));
}

/** DeleteItem function
 *  Completely deletes item content from database with all subsequencies
 *  but not deleted item from item table !!!
 *  @param $id
 */
function DeleteItem($id) {
    $p_itm_id = q_pack_id($id);

    DB_AA::sql('DELETE LOW_PRIORITY FROM `content`', array(array('item_id', $id, 'l')));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `offline`', array(array('id', $id, 'l')));
    // delete feeding relation
    DB_AA::sql("DELETE LOW_PRIORITY FROM `relation` WHERE (source_id='$p_itm_id' OR destination_id='$p_itm_id') AND ((flag & ". REL_FLAG_FEED .") != 0)");
}

/** DeleteSlice function
 * @param $del
 */
function DeleteSlice($del) {
    $p_del = q_pack_id($del);

    // delete items
    $item_ids = DB_AA::select('id', 'SELECT id FROM `item`', array(array('slice_id', $del, 'l')));
    foreach ($item_ids as $item_id) {
        DeleteItem(unpack_id($item_id)); // deletes from content, offline and
    }                                           // relation tables

    DB_AA::sql('DELETE LOW_PRIORITY FROM `item`', array(array('slice_id', $del, 'l')));
    DB_AA::sql("DELETE LOW_PRIORITY FROM `feedmap`   WHERE from_slice_id='$p_del' OR to_slice_id='$p_del'");
    DB_AA::sql("DELETE LOW_PRIORITY FROM `feedperms` WHERE from_id='$p_del' OR to_id='$p_del'");
    DB_AA::sql('DELETE LOW_PRIORITY FROM `email_notify`', array(array('slice_id', $del, 'l')));
    // delete fields
    DB_AA::sql('DELETE LOW_PRIORITY FROM `field`', array(array('slice_id', $del, 'l')));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `view`', array(array('slice_id', $del, 'l')));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `email`', array(array('owner_module_id', $del, 'l')));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `profile`', array(array('slice_id', $del, 'l')));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `rssfeeds`', array(array('slice_id', $del, 'l')));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `constant_slice`', array(array('slice_id', $del, 'l')));
    // delete all module specific tables
    DB_AA::sql('DELETE LOW_PRIORITY FROM `slice`', array(array('id', $del, 'l')));
    DB_AA::sql('DELETE LOW_PRIORITY FROM `module`', array(array('id', $del, 'l')));
}

?>

