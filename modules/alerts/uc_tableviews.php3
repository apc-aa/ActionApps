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

// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tableview.html

// Settings for Alerts-related table views 

require_once "util.php3";

/** see class tabledit :: var $getTableViewsFn for an explanation of the parameters */                        
function GetAlertsUCTableView ($viewID, $processForm = false) {        
    global $db, $collectionid;
    global $attrs_edit, $attrs_browse, $format, $langs;
    
    // ------------------------------------------------------------------------------------
    // au_edit: this is the user edit view 
    if ($viewID == "au_edit") {
        return  array (
        "table" => "alerts_user",
        "type" => "edit",
        "readonly" => false,
        "addrecord" => false,
        "mainmenu" => "user",
        "cond" => 1,
        "title" => _m("Alerts User Settings"), 
        "caption" => _m("Alerts User Settings"),
        "fields" => array (
            "email" => array (
				"caption" => _m("email"),
                "view" => array ("type"=>"text","size"=>array("cols"=>30)), 
                "validate"=>"email",
                "required" => true),
            "firstname" => array ("caption"=>_m("first name"),
                "view" => array ("type"=>"text","size"=>array("cols"=>8))),
            "lastname" => array ("caption"=>_m("last name"),
                "view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "lang" => array ("caption"=>_m("language"),
                "view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2))),
            "organisation" => array (
                "table" => "alerts_user",
                "view"=>array("size"=>array("cols"=>40)),
                "caption" => _m("organisation")),
            "postal_code" => array ("caption" => _m("postal code")),
            "year_of_birth" => array ("caption" => _m("year of birth")),
            "id" => array (
                "view"=>array("readonly"=>true),
                "caption" => _m("user ID"))
        ),
        "attrs" => $attrs_edit,
		"messages" => array (
	        "no_item" => _m("No user in this bin.")),
/*        "children" => array (
            "auc" => array (
                 "header" => _m ("Subscribed Collections"),
                 "join" => array ("id" => "userid")
             )*/
         );
    }
    
    if ($viewID == "auc") {
        global $auth;
        $uid = $auth->auth["uid"];
        $db->query("SELECT AC.id, name, slice_url FROM alerts_collection AC
                INNER JOIN module ON AC.moduleid = module.id");
        while ($db->next_record()) 
            $collections[$db->f("id")] = 
                '<a href="'.$GLOBALS["AA_INSTAL_PATH"]."post2shtml.php3"
                    .'?shtml_page='.$db->f("slice_url").'&uid='.$uid.'">'.$db->f("name").'</a>';
            
        return array (
        "table" => "alerts_user_collection",
        "type" => "browse",
        "readonly" => true,
        "addrecord" => false,
       // "gotoview" => "au_edit",
        "mainmenu" => "subscribed",
        "attrs" => $attrs_browse,
        "buttons_left" => array ("delete" => 1),
        "listlen" => 1000,
        "search" => false,
        "help" => _m("To edit your settings, click on the collection name. <br>
            To unsubscribe from a collection, use the \"delete\" button. <br>
            You will <b>receive email alerts</b> only from collections where your <b>status</b> is
            'Active bin', you are not expired, your subscription has already started and you
            have <b>confirmed</b> it by the link sent in an email."),
		"messages" => array (
	        "no_item" => _m("You are not subscribed to any Collection.")),
        "fields" => array (
            "collectionid" => array (
                "caption" => _m("collection"),
                "view" => array ("type"=>"select","source"=>$collections,"html"=>true)),
            "howoften" => array (
                "caption" => _m("how often"),
                "view" => array ("type"=>"select","source"=>get_howoften_options())),
            "receive_alerts" => array (
                "caption" => _m("receive alerts"),
                "field" => "confirm",
                "view"=>array ("type"=>"calculated", "function"=>"showReceiveAlerts")),
            "status_code"=>array (
                "caption" => _m("status"),
                "view"=>array ("type"=>"select","source"=>get_bin_names())),
            "start_date" => array (
                "caption" => _m("start date"),
                "view"=>array ("type"=>"date","format"=>"j.m.Y")),                
            "expiry_date" => array (
                "caption" => _m("expiration date"),
                "view"=>array ("type"=>"date","format"=>"j.m.Y")),                
            "confirm" => array (
                "caption" => _m("confirmed"),
                "view"=>array ("type"=>"userdef", "function"=>"showConfirm")),
         ));
     }
         
} // end of GetTableView
            
// ----------------------------------------------------------------------------------        

function showConfirm ($val) {
    return $val ? _m("no") : _m("yes");
}

function showReceiveAlerts ($record) {
    if ($record["confirm"] == ""
        && $record["status_code"] == 1
        && $record["start_date"] <= time()
        && $record["expiry_date"] >= time())
        return _m("yes");
    else return _m("no");
} 
