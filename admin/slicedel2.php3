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
        MsgPage($sess->url(self_base())."index.php3", _m("You don't have permissions to delete slice."), "admin");
        exit;
    }
} else {
    MsgPage($sess->url(self_base())."index.php3", _m("You don't have permissions to delete slice."), "admin");
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
go_url(con_url($sess->url("slicedel.php3"),
                                          "Msg=".rawurlencode(_m("Slice successfully deleted, tables are optimized"))));

/** DeleteOneModule function
 * @param $del
 */
function DeleteOneModule($del) {
    global $db, $g_modules;

    // check if module can be deleted
    ExitIfCantDelete( $del, $db );

    // delete module (from common module table)
    DeleteModule( $del, $db );

    // delete slice from permission system -----------------------------------------
    DelPermObject($del, "slice");

    switch ($g_modules[$del]['type']) {
        case 'Alerts': DeleteAlerts($del); break;
        case 'S': DeleteSlice($del); break;
        default: echo "Functions for deleting module type ".$g_modules[$slice_id]['type']
        ." are not yet defined."; exit;
    }
}
/** DeleteAlerts function
 * @param $module_id
 */
function DeleteAlerts($module_id) {
    global $db;

    $db->query("SELECT id FROM alerts_collection WHERE moduleid='".q_pack_id($module_id)."'");
    if (!$db->next_record()) {
        return;
    }
    $collectionid = $db->f ("id");
    $db->query("DELETE LOW_PRIORITY FROM alerts_collection_filter WHERE collectionid=$collectionid");
    $db->query("DELETE LOW_PRIORITY FROM alerts_collection WHERE id=$collectionid");
    $db->query("DELETE LOW_PRIORITY FROM alerts_collection_howoften WHERE id=$collectionid");
    $db->query("DELETE LOW_PRIORITY FROM module WHERE id='".q_pack_id($module_id)."'");
}
/** DeleteSlice function
 * @param $del
 */
function DeleteSlice($del) {
    global $db;
    $p_del = q_pack_id($del);

    // delete items
    $db2  = new DB_AA;
    $SQL = "SELECT id FROM item WHERE slice_id='$p_del'";
    $db->query($SQL);
    while ( $db->next_record() ) {
      DeleteItem($db2, unpack_id128($db->f('id'))); // deletes from content, offline and
    }                                           // relation tables

    // delete items
    $SQL = "DELETE LOW_PRIORITY FROM item WHERE slice_id='$p_del'";
    $db->query($SQL);

    // delete feedmap
    $SQL = "DELETE LOW_PRIORITY FROM feedmap WHERE from_slice_id='$p_del'
                                                OR to_slice_id='$p_del'";
    $db->query($SQL);

    // delete feedprms
    $SQL = "DELETE LOW_PRIORITY FROM feedperms WHERE from_id='$p_del'
                                                OR to_id='$p_del'";
    $db->query($SQL);

    // delete email_notify
    $SQL = "DELETE LOW_PRIORITY FROM email_notify WHERE slice_id='$p_del'";
    $db->query($SQL);

    // delete fields
    $SQL = "DELETE LOW_PRIORITY FROM field WHERE slice_id='$p_del'";
    $db->query($SQL);

    // delete view
    $SQL = "DELETE LOW_PRIORITY FROM view WHERE slice_id='$p_del'";
    $db->query($SQL);

    // delete email
    $SQL = "DELETE LOW_PRIORITY FROM email WHERE owner_module_id='$p_del'";
    $db->query($SQL);

    // delete profile
    $SQL = "DELETE LOW_PRIORITY FROM profile WHERE slice_id='$p_del'";
    $db->query($SQL);

    // delete rssfeeds
    $SQL = "DELETE LOW_PRIORITY FROM rssfeeds WHERE slice_id='$p_del'";
    $db->query($SQL);

    // delete constant_slice
    $SQL = "DELETE LOW_PRIORITY FROM constant_slice WHERE slice_id='$p_del'";
    $db->query($SQL);

    // delete all module specific tables
    $SQL = "DELETE LOW_PRIORITY FROM slice WHERE id='$p_del'";
    $db->query($SQL);

    $SQL = "DELETE LOW_PRIORITY FROM module WHERE id='$p_del'";
    $db->query($SQL);
}

?>

