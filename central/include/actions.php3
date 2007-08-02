<?php
/**
 * File contains definitions of functions which corresponds with actions
 * on Item Manager page (admin/index.php3) - manipulates with central_confs
 *
 * Should be included to other scripts (admin/index.php3)
 *
 *   Move central_conf to app/hold/trash based on param
 *  @param $status    static function parameter defined in manager action
 *                   in this case it holds bin number, where the central_confs should go
 *  @param $item_arr array, where keys are unpacked ids of items prefixed by
 *                   'x' character (javascript purposes only)
 *  @param $akce_param additional parameter for the action - not used here
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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

require_once AA_INC_PATH."linkcheck.class.php3";

/** Central_MoveItem function
 * @param $status
 * @param $item_arr
 * @param $akce_param
 * @return false
 */
function Central_Linkcheck($param, $item_arr, $akce_param) {
    $item_ids = array_keys($item_arr);

    if (count($item_ids)<1) {
        return false;                                     // OK - no error
    }
    
    $db  = getDB();    
    $SQL = "SELECT * FROM central_conf WHERE id IN ('".join_and_quote("','",$item_ids)."')";
    $db->tquery($SQL);
    $results[] = array('<b>'._m('AA (Organization)').'</b>', '<b>'._m('URL').'</b>', '<b>'._m('Status code').'</b>', '<b>'._m('Description').'</b>');
    $linkcheck = new linkcheck();

    while ($db->next_record()) {
        $url       = $db->f('AA_HTTP_DOMAIN'). $db->f('AA_BASE_DIR'). "view.php3";
        $status    = $linkcheck->check_url($url);
        $results[] = array($db->f('ORG_NAME'), $url, $status['code'], $status['comment']);
    }
    
    freeDB($db);
    return GetHtmlTable($results). "<br>";                                     // OK - no error
}



/** Central_MoveItem function
 * @param $status
 * @param $item_arr
 * @param $akce_param
 * @return false
 */
function Central_Sqlupdate($param, $item_arr, $akce_param) {
    $item_ids = array_keys($item_arr);

    if (count($item_ids)<1) {
        return false;                                     // OK - no error
    }
    set_time_limit(360);
    $db  = getDB();
    
    $SQL = "SELECT * FROM central_conf WHERE id IN ('".join_and_quote("','",$item_ids)."')";
    $db->tquery($SQL);
    $ret = '';
    while ($db->next_record()) {
        $params   = 'dbpw5='.substr($db->f('db_pwd'),0,5).'&fire=1&dbcreate=on&copyold=on&backup=on&replacecateg=on&replaceconst=on&newcore=on&templates=on&view_templates=on&addstatistic=on&additemidfields=on&fixmissingfields=on&update_modules=on&cron=on&generic_emails=on&links_create=on&update=Run+Update';
        $file     = $db->f('AA_HTTP_DOMAIN'). $db->f('AA_BASE_DIR'). "sql_update.php3?$params";
        $response = file_get_contents($file);
        $toggle   = '{htmltoggle:&gt;&gt;:'.AA_Stringexpand::quoteColons($db->f('AA_HTTP_DOMAIN')).':&lt;&lt;:'. AA_Stringexpand::quoteColons($response).'}';
        $ret     .= AA_Stringexpand::unalias($toggle);
    }
    freeDB($db);
    return $ret;                                     // OK - no error
}

/** Central_MoveItem function
 * @param $status
 * @param $item_arr
 * @param $akce_param
 * @return false
 */
function Central_MoveItem($status, $item_arr, $akce_param) {
    $db  = getDB();
    $item_ids = array_keys($item_arr);

    if ($item_ids) {
        $SQL = "UPDATE central_conf SET status_code = '$status'
                 WHERE id IN ('".join_and_quote("','",$item_ids)."')";
        $db->tquery($SQL);
    }

    freeDB($db);
    return false;                                     // OK - no error
}

/** Central_DeleteTrash function
 *  Handler for DeleteTrash switch - Delete all aas in the trash bin
 *  @param $param       'selected' if we have to delete only items specified
 *                      in $item_arr - otherwise delete all items in Trash
 *  @param $item_arr    Items to delete (if 'selected' is $param)
 *  @param $akce_param  Not used
 * @return false or error message
 */
function Central_DeleteTrash($param, $item_arr, $akce_param) {
    global $pagecache, $slice_id, $event;
    $db = getDB();

    if ( !isSuperadmin() ) {    // permission to delete items?
        return _m("You have not permissions to remove items");
    }

    $wherein = '';

    // restrict the deletion only to selected items
    if ($param == 'selected') {
        $items_to_delete = array();
        $item_ids        = array_keys($item_arr);
        if (count($items_to_delete) < 1) {
            freeDB($db);
            return;
        }
        $wherein = " AND id IN ('".join_and_quote("','", $items_to_delete)."')";
    }

    // now we ask, which items we have to delete. We are checking the items even
    // it is specified in $item_arr - for security reasons - we can delete only
    // items in current slice and in trash
    $db->query("SELECT id FROM central_conf WHERE status_code=3 $wherein");
    $items_to_delete = array();
    while ( $db->next_record() ) {
        $items_to_delete[] = $db->f("id");
    }
    if (count($items_to_delete) < 1) {
        freeDB($db);
        return;
    }

    // delete content of all fields
    // don't worry about fed fields - content is copied
    $wherein = "IN ('".join_and_quote("','", $items_to_delete)."')";
    $db->query("DELETE FROM central_conf WHERE id ".$wherein);
    freeDB($db);
}

/** Item_Tab function
 * Handler for Tab switch - switch between bins
 * @param $value
 * @param $param
 */
function Central_Tab($value, $param) {
    global $manager;
    $GLOBALS['r_state']['bin'] = $value;
    $manager->go2page(1);
}

?>
