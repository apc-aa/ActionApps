<?php
/**
*   Shows the confirmation web page and confirms user in Alerts. 
*   Global parameters:
*       $id - confirmation ID to look for in database (required)
*       $lang - set language 
*       $ss - set style sheet URL
*
 * @package Alerts
 * @version $Id$
 * @author Jakub Adámek <jakubadamek@ecn.cz>, Econnect, December 2002
 * @copyright Copyright (C) 1999-2002 Association for Progressive Communications 
*/
/* 
Copyright (C) 1999-2002 Association for Progressive Communications 
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

require "./lang.php3";
require $GLOBALS["AA_INC_PATH"]."varset.php3";

$db = new DB_AA;

if ($id) {
    $db->query ("SELECT confirm,email,userid,collectionid FROM alerts_user AU
        INNER JOIN alerts_user_collection AUC ON AU.id = AUC.userid
        WHERE confirm='$id'");
    if ($db->next_record()) {
        $uid = $db->f("userid");
        $cid = $db->f("collectionid");
        $alerts_session = new_id();        
        $db->query ("SELECT confirmed_status_code FROM alerts_collection WHERE id=$cid");
        $db->next_record();
        $varset = new CVarset;
        $varset->addkey ("userid", "number", $uid);
        $varset->addkey ("collectionid", "number", $cid);
        $varset->add ("confirm", "text", "");
        $varset->add ("status_code", "number", $db->f("confirmed_status_code"));
        $db->query ($varset->makeUPDATE ("alerts_user_collection"));
        
        $varset->clear();
        $varset->addkey ("id", "number", $uid);        
        $varset->add ("session", "text", $alerts_session);
        $varset->add ("sessiontime", "number", time());
        $db->query ($varset->makeUPDATE ("alerts_user"));
        $msg = _m("Congratulations. Your subscription is finished.");        
        go_url ("user_filter.php3?Msg=$msg&id=$id");
    }   
    else $msg = _m("Your code is not valid any more. Please subscribe again.");
}
go_url ("subscribe.php3?Msg=$msg&lang=$lang");
?>