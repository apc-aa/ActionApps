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

require_once $GLOBALS["AA_INC_PATH"]."mail.php3";
require_once $GLOBALS["AA_INC_PATH"]."mgettext.php3";
require_once "util.php3";
require_once "alerts_sending.php3";

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
            
        if ($mydb->f("emailid_welcome")) {            
            send_mail_from_table ($mydb->f("emailid_welcome"), 
                $itemContent->getValue(FIELDID_EMAIL), $alias);
        }
    }
}            

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
        }
    }
}
                          
?>