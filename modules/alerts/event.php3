<?php
/** Event handlers called from include/event_handler.php3 
 * 
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, March 2003
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications 
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

require_once $GLOBALS["AA_INC_PATH"]."mail.php3";
require_once $GLOBALS["AA_INC_PATH"]."mgettext.php3";
require_once "util.php3";
require_once "alerts_sending.php3";

/** Sends welcome e-mail to newly subscribed readers, which means new
*   "items" appearing in a Reader Management Slice.
*/
function AlertsSendWelcome( $item_id, $slice_id, &$itemContent ) {
    $mydb = new DB_AA;

    $mydb->query ("SELECT alerts_collection.id, slice_url, emailid_welcome
        FROM alerts_collection INNER JOIN module
        ON alerts_collection.module_id = module.id
        WHERE alerts_collection.slice_id='".q_pack_id($slice_id)."'");

    // One Reader Management Slice may belong to several Alerts Collections
    while ($mydb->next_record()) {

        $alias["_#COLLFORM"] = alerts_con_url ($mydb->f("slice_url"), 
            "aw=".$itemContent->getValue(FIELDID_ACCESS_CODE));
        $alias["_#HOWOFTEN"] = $itemContent->getValue(
            getAlertsField (FIELDID_HOWOFTEN, $mydb->f("id")));
        $alias["_#CONFIRM_"] = $itemContent->getValue(FIELDID_MAIL_CONFIRMED);
            
        if ($mydb->f("emailid_welcome")) {            
            send_mail_from_table ($mydb->f("emailid_welcome"), 
                $itemContent->getValue(FIELDID_EMAIL), $alias);
        }
    }
}            

/** Sends instant Alert when a new item appears in any slice which is 
*   involved by selections in some of its "Alerts Selection Set" view
*   in an Alert module. 
*
*   Only the Active items are sent, not the Pending ones. The Pending items
*   are sent when they become active
*   by the regular call from the cron.php3 script in the same way
*   as daily, weekly or monthly alerts.
*/
function AlertsSendInstantAlert( $item_id, $slice_id ) {
    global $db;
    
    $db->query ("SELECT moved2active, publish_date, expiry_date FROM item 
        WHERE id = '".q_pack_id($item_id)."'");

    if ($db->next_record() && $db->f("moved2active")
        && time() >= $db->f("publish_date") && time() <= $db->f("expiry_date")) {    
        $db->query ("
            SELECT DISTINCT ACF.collectionid FROM alerts_collection_filter ACF 
            INNER JOIN alerts_filter AF ON ACF.filterid = AF.id
            INNER JOIN view ON view.id = AF.vid
            WHERE view.slice_id='".q_pack_id($slice_id)."'");
        while ($db->next_record())
            $collection_ids[] = $db->f("collectionid");
        if (is_array ($collection_ids)) {
            initialize_last();
            send_emails ("instant", $collection_ids, "", true, $item_id);
            // We must reset moved2active so that the item is not re-sent on update.
            $db->query ("UPDATE item SET moved2active = 0 WHERE item_id='"
                .q_pack_id($item_id)."'");
        }
    }
}
                          
?>