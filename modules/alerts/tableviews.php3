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

require "util.php3";
require $GLOBALS[AA_INC_PATH]."tv_email.php3";

/** see class tabledit :: var $getTableViewsFn for an explanation of the parameters */                        
function GetAlertsTableView ($viewID, $processForm = false) {        

    if ($viewID == "email_edit") {
        $tableview = GetEmailTableView ($viewID);
        $tableview["mainmenu"] = "admin";
        return $tableview;
    }

    if ($viewID == "email") {
        $tableview = GetEmailTableView ($viewID);
        $tableview["mainmenu"] = "admin";
        $tableview["submenu"] = "email";
        return $tableview;
    }
	
    global $auth, $slice_id, $db, $collectionid;
    global $attrs_edit, $attrs_browse, $format, $langs;
    
    /* ------------------------------------------------------------------------------------
       au -- browse Alerts users
    */       

    global $sess, $Tab, $setTab;
    if (is_object ($sess))
        $sess->register ("Tab");
    if ($setTab) $Tab = $setTab;    

    // ------------------------------------------------------------------------------------
    // au: this is the user manager view 
    if ($viewID == "au") {        
        $new_user_id = new_user_id();
        return  array (
        "table" => "alerts_user_collection",
        "join" => array (
            "alerts_user" => array (
                "joinfields" => array (
                    "userid" => "id"),
                "jointype" => "n to 1")),                    
        "type" => "browse",
        "mainmenu" => "usermanager",
        "submenu" => $GLOBALS["Tab"],        
        "readonly" => true, //!IsSuperadmin(),
        "buttons_down" => array ("delete_all" => 1),
        "buttons_left" => array ("delete_checkbox" => 1, "edit" => 1),
        "addrecord" => false,
        //"help" => _m("To add users use the standard Alerts User Interface."),
        "gotoview" => "au_edit",
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => _m("Alerts User"), 
        "caption" => _m("Alerts User"),
        "orderby" => "email",
        "fields" => array (
            "userid" => array (
                "view" => array ("type" => "hide"),
                "default" => $new_user_id),                        
            "email" => array (
                "table" => "alerts_user",
				"caption" => _m("email"),
                "view" => array ("type"=>"text","size"=>array("cols"=>30)), 
                "validate"=>"email",
                "required" => true),
            "firstname" => array ("table" => "alerts_user", "caption"=>_m("first name"),"view" => array ("type"=>"text","size"=>array("cols"=>8))),
            "lastname" => array ("table" => "alerts_user", "caption"=>_m("last name"),"view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "owner_module_id" => array (
                "table" => "alerts_user",
                "caption" => _m("owner"),
                "view" => array ("type"=>"select","source"=>SelectModule(true),"unpacked"=>true)),
            "confirm" => array ("caption" =>_m("confirmed"),"view" => array ("type" => "userdef", "function" => "te_au_confirm")),
            //"lang" => array ("table" => "alerts_user","caption"=>_m("language"),"view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2))),
            "howoften" => array (
                "view" => array ("type"=>"select","source"=>get_howoften_options()),
                "caption" => _m("how often"))),
        "attrs" => $attrs_browse,
        "where" => CreateWhereFromList ("alerts_user.id", FindAlertsUserPermissions())." AND collectionid=$collectionid",
		"messages" => array (
	        "no_item" => _m("No user in this bin.")));
    }
    
    // ------------------------------------------------------------------------------------
    // au_edit: this is the user edit view 
    if ($viewID == "au_edit") {
        $keys = split_escaped (":", GetEditedKey ("au_edit"), "#:");
        $userid = $keys[0];
        if (IsSuperadmin())
            $useredit = 1;
        else {
            $modules = SelectModule (false);
            $db->query("SELECT owner_module_id FROM alerts_user WHERE id = $userid");
            $db->next_record();
            $useredit = $modules[unpack_id($db->f("owner_module_id"))] ? true : false;            
        }

        return  array (
        "table" => "alerts_user_collection",
        "join" => array (
            "alerts_user" => array (
                "joinfields" => array (
                    "userid" => "id"),
                "jointype" => "n to 1")),                    
        "type" => "edit",
        "mainmenu" => "usermanager",
        "submenu" => "",
        "readonly" => false,
        "addrecord" => false,
        "gotoview" => "au",
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => _m("Alerts Users"), 
        "caption" => _m("Alerts Users"),
        "orderby" => "email",
        "fields" => array (
            "userid" => array (
                "caption" => _m("user ID"),
                "view" => array ("readonly" => true)),
            "email" => array (
                "table" => "alerts_user",
				"caption" => _m("email"),
                "view" => array ("type"=>"text","size"=>array("cols"=>30),"readonly"=>!$useredit), 
                "validate"=>"email",
                "required" => true),
            "firstname" => array ("table" => "alerts_user", "caption"=>_m("first name"),
                "view" => array ("type"=>"text","size"=>array("cols"=>8),"readonly"=>!$useredit)),
            "lastname" => array ("table" => "alerts_user", "caption"=>_m("last name"),
                "view" => array ("type"=>"text","size"=>array("cols"=>15),"readonly"=>!$useredit)),
            "owner_module_id" => array (
                "table" => "alerts_user",
                "caption" => _m("owner"),
                "view" => array ("type"=>"select","source"=>SelectModule(!$useredit),"unpacked"=>true
                    ,"readonly"=>!$useredit)),
            "lang" => array ("table" => "alerts_user","caption"=>_m("language"),
                "view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2),"readonly"=>!$useredit)),
            "organisation" => array (
                "table" => "alerts_user",
                "view"=>array("readonly"=>!$useredit, "size"=>array("cols"=>40)),
                "caption" => _m("organisation")),
            "postal_code" => array ("table" => "alerts_user", "caption" => _m("postal code"),
                "view"=>array("readonly"=>!$useredit)),
            "year_of_birth" => array ("table" => "alerts_user", "caption" => _m("year of birth"),
                "view"=>array("readonly"=>!$useredit)),
            "remark" => array ("table" => "alerts_user", "caption" => _m("remark"),
                "hint"=>_m("Not shown to the user."),
                "view"=>array("readonly"=>!$useredit, "type"=>"area", "size"=>array ("cols"=>50,"rows"=>3))),
            "confirm" => array ("caption" =>_m("confirmation"),
                "hint"=>_m("if empty, user is confirmed")),
            "status_code" => array (
                "caption" => _m("status"),
                "view"=>array("type"=>"select","source"=>get_bin_names())),
            "howoften" => array (
                "view" => array ("type"=>"select","source"=>get_howoften_options()),
                "caption" => _m("how often")),
            "start_date" => array ("caption"=>_m("start date"),
                "view"=>array("type"=>"date","format"=>"j.m.Y")),
            "expiry_date" => array ("caption"=>_m("expiry date"),
                "view"=>array("type"=>"date","format"=>"j.m.Y")),
            "allfilters" => array ("caption"=>_m("all filters"),
                "view"=>array("type"=>"select","source"=>array("0"=>_m("no"),"1"=>_m("yes"))))    
        ),
        "attrs" => $attrs_edit,
        "where" => CreateWhereFromList ("alerts_user.id", FindAlertsUserPermissions())." AND collectionid=$collectionid",
		"messages" => array (
	        "no_item" => _m("No user in this bin.")),
        "children" => array (
            "aucf" => array (
                 "header" => _m ("Filters"),
                 "join" => array ("id" => "userid")
             )
         ));
    }
         
    if ($viewID == "aucf") {
        global $collectionid;
        $db->query ("SELECT AF.description, AF.id FROM alerts_collection_filter ACF
            INNER JOIN alerts_filter AF ON AF.id = ACF.filterid
            WHERE collectionid=$collectionid");
        if ($db->num_rows()) {
            while ($db->next_record()) 
                $collection_filters [$db->f("id")] = $db->f("description");
            $no_filters = false;
        }
        else {
            $collection_filters[-1] = _m("No filters found for this collection.");
            $no_filters = true;
        }
        return  array (
        "table" => "alerts_user_collection_filter",
        "type" => "browse",
        "readonly" => false, //$no_filters,
        //"buttons_down" => array (), //"update_all" => 1, "delete_all" => 1),
        "addrecord" => true,
        "gotoview" => "au_edit",
        "cond" => 0,
        "orderby" => "myindex",
        "search" => false,
        "messages" => array (
            "error_insert" => _m("This filter has already been added.")),
        "fields" => array (
            "collectionid" => array (
                "view" => array ("type"=>"hide"),
                "default" => $collectionid),
            "filterid" => array (
                "caption" => _m("filter"),
                "view" => array ("type"=>"select","source"=>$collection_filters)),
            "myindex" => array (
                "default" => 1,
                "caption" => _m("order"))),
        "attrs" => $attrs_browse,
		"help" => _m("If 'all filters' is set to 'yes', no filters should be assigned to this user."));
    }
         
    // ------------------------------------------------------------------------------------

    if ($viewID == "acf") {
        global $collectionid;
        $db->query ("SELECT AF.description, AF.id FROM alerts_filter AF");
        if ($db->num_rows()) {
            while ($db->next_record()) 
                $collection_filters [$db->f("id")] = $db->f("description");
            $no_filters = false;
        }
        else {
            $collection_filters[-1] = _m("No filters defined. You must define some.");
            $no_filters = true;
        }

        // filter select box        
        $SQL = "SELECT slice.name, DF.description as fdesc, DF.id AS filterid, 
                       view.id AS view_id, view.type as view_type, view.slice_id FROM
                        slice INNER JOIN
                        view ON slice.id = view.slice_id INNER JOIN
                        alerts_filter DF ON DF.vid = view.id";
        $SQL .= " ORDER BY slice.name, DF.description";  
        $db->tquery ($SQL);
        global $sess;
        $myslices = GetUsersSlices( $auth->auth["uid"] );    
        while ($db->next_record()) {
            $txt = HTMLSpecialChars ($db->f("fdesc"));
            if (IsSuperadmin() || strchr ($myslices [unpack_id ($db->f("slice_id"))], PS_FULLTEXT)) {
                $new_filters[$db->f("filterid")] = $txt;
                $txt =            
                    "<a href='".$sess->url("tabledit.php3"
                    ."?change_id=".unpack_id($db->f("slice_id"))
                    ."&change_page=se_view.php3"
                    ."&change_params[view_id]=".$db->f("view_id")
                    ."&change_params[view_type]=".$db->f("view_type"))
                    ."'>".$txt."</a>";
            }
            $filters[$db->f("filterid")] = $txt;
        }        

        return  array (
        "table" => "alerts_collection_filter",
        "type" => "browse",
        "readonly" => false, //$no_filters,
        "buttons_left" => array ("delete_checkbox" => 1),
        "buttons_down" => array ("update_all" => 1, "delete_all" => 1),
        "addrecord" => is_array ($new_filters),
        "gotoview" => "au_edit",
        "mainmenu" => "admin",
        "submenu" => "design",
        "search" => false,
        "caption" => _m("Filters"),
        "title" => _m("Filters"),
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "orderby" => "myindex",
        "where" => "collectionid = $collectionid",
        "fields" => array (
            "collectionid" => array (
                "view" => array ("type" => "hide"),
                "default" => $collectionid),
            "filterid" => array (
				"caption" => _m("filter"),
                "view" => array (
                    "readonly" => true,
                    "type" => "select",
                    "html" => true,
                    "source" => $filters),
                "view_new_record" => array (
                    "type" => "select",
                    "source" => $new_filters)),
            "myindex" => array (
                "caption" => _m("order"),
                "default" => 1)),
        "attrs" => $attrs_browse);
    }
         
    /* ------------------------------------------------------------------------------------
       modedit 
       Alerts collection setting 
       
       modedit_insert
       Processing form data on Alerts module addition.
    */    
    
    if ($viewID == "modedit") {
        $fix_howoften_options = get_howoften_options();
        $fix_howoften_options[""] = _m("don't fix");
        return array (
        "table" => "module",
        "join" => array (
            "alerts_collection" => array (
                "joinfields" => array (
                    "id" => "moduleid"),
                "jointype" => "1 to 1")),                    
        "type" => "edit",
        "readonly" => false,
        "cond" => 1,
        "title" => _m("Alerts Collection"), 
        "caption" => _m("Alerts Collection"),
        "mainmenu" => "admin",
        "submenu" => "settings",
        "triggers" => array ( 
            "AfterInsert" => "AlertsModeditAfterInsert"),
        "fields" => array (
            "_alerts_collection_id_" => array (
                "table" => "alerts_collection",
                "field" => "id",
                "default" => new_collection_id(),
                "view" => array ("readonly" => true),
                "caption" => _m("collection ID")),
            "name" => array (
                "view" => array ("type" => "text", "size" => array("cols"=>60)),
				"caption" => _m("name"),
                "required" => true),
            "slice_url" => array ("caption" => _m("form URL"), "required"=>true),
            "lang_file" => array (
                "caption" => _m("language"),
                "view" => array ("type"=>"select","source"=>$GLOBALS["biglangs"])),
            "deleted" => array (
                "caption" => _m("deleted"),
                "hint" => _m("Use AA Admin / Delete to permanently delete"),
                "view" => array ("type"=>"checkbox")),
            "notconfirmed_status_code" => array (
                "table" => "alerts_collection",
                "caption" => _m("place users on subscription to"),
                "view" => array ("type"=>"select", "source"=>array (               
                    2=>get_bin_name(2),
                    3=>get_bin_name(3)))),                   
            "confirmed_status_code" => array (
                "table" => "alerts_collection",
                "caption" => _m("place confirmed users to"),
                "view" => array ("type"=>"select", "source"=>array (
                    1=>get_bin_name(1),
                    2=>get_bin_name(2)))),
            "expiry_months" => array (
                "table" => "alerts_collection",
                "default" => 36,
                "caption" => _m("set expiry date to x months after today")),
            "fix_howoften" => array (
                "table" => "alerts_collection",
                "caption" => _m("fix howoften (allow only)"),
                "view" => array ("type"=>"select", "source"=>$fix_howoften_options)),                    
/*            "showme" => array (
				"caption" => _m("standard"),
                "default" => 1,
                "view" => array (
					"readonly" => true,
					"type"=>"select",
					"source"=>array("0"=>_m("no"),"1"=>_m("yes"))),
                "view_new_record" => array ("readonly" => true)), */
            "emailid_welcome" => array (
                "table" => "alerts_collection",
                "caption" => _m("welcome email"),
                "view" => ($processForm ? "" : array (
                    "type"=>"select",
                    "href_view" => "email_edit",
                    "source"=>GetUserEmails()))),
            "emailid_alert" => array (
                "table" => "alerts_collection",
                "caption" => _m("alert email"),
                "view" => ($processForm ? "" : array (
                    "type"=>"select",
                    "href_view" => "email_edit",
                    "source"=>GetUserEmails()))),
            "type" => array ("default" => "Alerts", "view" => array ("type"=>"hide")),
            "id" => array (
                "default" => pack_id (new_id()),                 
                "view" => array("type"=>"text", "unpacked" => true, "readonly" => true)),
            "created_at" => array (
                "caption" => _m("created at"),
                "default"=>time(), 
                "view"=>array (
                    "type"=>"date",
                    "format" => "j.m.y",
                    "readonly" => 1)),
            "created_by" => array (
                "caption" => _m("created by"),
                "default"=>$auth->auth["uid"], 
                "view"=>array(
                    "type"=>"text",
                    "readonly" => 1))
        ),    
        "attrs" => $attrs_edit,
		"messages" => array (
	        "no_item" => _m("You don't have permissions to edit any collection or no collection exists.")
		));        
    }
    
    
    if ($viewID == "ac_edit") return  array (
        "table" => "alerts_collection",
        "type" => "edit",
        "readonly" => false,
        "gotoview" => "ac",
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => _m("Alerts Collection"), 
        "caption" => _m("Alerts Collection"),
        "mainmenu" => "sliceadmin",
        "fields" => array (
            "id" => array("view"=>array("readonly"=>1)),
            "description" => array (
                "view" => array ("type" => "area", "size" => array("cols"=>60,"rows"=>2)),
				"caption" => _m("description"),
                "required" => true),
            "url" => array ("caption" => _m("collection form URL")),
            "showme" => array (
				"caption" => _m("standard"),
                "default" => 1,
                "view" => array (
					"readonly" => true,
					"type"=>"select",
					"source"=>array("0"=>_m("no"),"1"=>_m("yes"))),
                "view_new_record" => array ("readonly" => true)),
            "emailid_welcome" => array (
                "caption" => _m("welcome email"),
                "view" => array (
                    "type"=>"select",
                    "source"=>GetEmailSelectBox()))),    
        "attrs" => $attrs_edit,
        "children" => array (
            "acf" => array (
                 "header" => _m ("Filters"),
                 "join" => array ("id" => "collectionid")
             ),
            "acu" => array (
                 "header" => _m ("Users"),
                 "join" => array ("id" => "collectionid")
             )
        ),
        "where" => CreateWhereFromList ("id", FindCollectionPermissions()),
		"messages" => array (
	        "no_item" => _m("You don't have permissions to edit any collection or no collection exists.")
		)
	);
    
    if ($viewID == "acf") {
        // filter select box        
        $SQL = "SELECT slice.name, DF.description as fdesc, DF.id AS filterid, 
                       view.id AS view_id, view.type as view_type, view.slice_id FROM
                        slice INNER JOIN
                        view ON slice.id = view.slice_id INNER JOIN
                        alerts_filter DF ON DF.vid = view.id";
        $SQL .= " ORDER BY slice.name, DF.description";  
        $db->tquery ($SQL);
        $filter_perms = FindAlertsFilterPermissions();
        global $sess;
        while ($db->next_record()) {
            $txt = $db->f("name"). " - ". $db->f("fdesc");
            $filters[$db->f("filterid")] = 
                "<a href=\"".$sess->url("se_view.php3?view_id=".$db->f("view_id")."&view_type=".$db->f("view_type")
                ."&change_id=".unpack_id($db->f("slice_id")))
                ."\">".HTMLEntities($txt)."</a>";
            if (!is_array ($filter_perms) || my_in_array ($db->f("filterid"), $filter_perms))
                $new_filters[$db->f("filterid")] = $txt;
        }
        
        return  array (            
        "table" => "alerts_collection_filter",
        "type" => "browse",
        "readonly" => false,
        "addrecord" => true,
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => L_ALERTS_COLLECTION_TITLE, 
        "caption" => L_ALERTS_COLLECTION_TITLE,
        "attrs" => $attrs_browse,
        "search" => false,
        "orderby" => "myindex",
		"messages" => array (
			"error_insert" => _m("Error inserting Filter. Perhaps it is already in the collection.")),
        "fields" => array (
            "filterid" => array (
				"caption" => _m("filter"),
                "view" => array (
                    "readonly" => true,
                    "type" => "select",
                    "html" => true,
                    "source" => $filters),
                "view_new_record" => array (
                    "type" => "select",
                    "source" => $new_filters)),
            "myindex" => array (
				"caption" => _m("order"),
                "validate" => "number",
                "validate_min" => 1,
                "validate_max" => 99,
                "required" => true,
                "default" => 1,
                "view" => array (
                    "type" => "text",
                    "size" => array ("cols" => 2)))));
    }
    
    if ($viewID == "acu") {
        $db->query ("SELECT id, email, confirm FROM alerts_user");
        while ($db->next_record()) 
            $alerts_users[$db->f("id")] = $db->f("email")
                . ($db->f("confirm") ? " ("._m("Not yet confirmed").")" : "");
                    
        return  array (
        "table" => "alerts_user_collection",
        "type" => "browse",
        "readonly" => false,
        "addrecord" => false,
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => L_ALERTS_COLLECTION_TITLE, 
        "caption" => L_ALERTS_COLLECTION_TITLE,
        "attrs" => $attrs_browse,
        "orderby" => "howoften",
		"messages" => array (
	        "no_item" => _m ("There are no users subscribed to this collection yet.")),
        "fields" => array (
            "userid" => array (
				"caption" => _m("email"),
                "view" => array (
                    "readonly" => true,
                    "href_view" => "au_edit",
                    "type" => "select",
                    "size" => array ("cols" => 4),
                    "source" => $alerts_users)),
            "howoften" => array (
				"caption" => _m("how often"),
                "view" => array (
					"type" => "select", 
					"source" => get_howoften_options ()))
        ));
    }
    
    /* ------------------------------------------------------------------------------------
       ac -- browse Alerts collections
    */   
    if ($viewID == "ac") return  array (
        "table" => "alerts_collection",
        "type" => "browse",
        "readonly" => false,
        "addrecord" => false,
        "gotoview" => "ac_edit",
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => L_ALERTS_COLLECTION_TITLE, 
        "caption" => L_ALERTS_COLLECTION_TITLE,
        "mainmenu" => "sliceadmin",
        "submenu" => "te_alerts_collections",
        "orderby" => "description",
		"messages" => array (
	        "no_item" => _m("No collection uses any filter defined in any slice you have Admin permissions to.")),
        "fields" => array (
            "id" => array (
                "view" => array ("readonly" => true)),
            "description" => array (
				"caption" => _m("description"),
                "view" => array ("type"=>"text","size"=>array("cols"=>30)),
                "required" => true),
            "showme" => array ("caption" => _m("standard"),
							   "view" => array (
									"type"=>"select",
									"source"=>array("0"=>_m("no"),"1"=>_m("yes")),
                                    "readonly" => true)),
            "emailid_welcome" => array (
                "caption" => _m("welcome email"),
                "view"=>array ("type"=>"select","source"=>GetUserEmails()))),
        "attrs" => $attrs_browse,
        "where" => CreateWhereFromList ("id", FindCollectionPermissions()));
    
    /* ------------------------------------------------------------------------------------
       au_edit and its child auc
       Alerts user editing with her collections
    */        
/*    if ($viewID == "au_edit") return  array (
        "table" => "alerts_user",
        "type" => "edit",
        "readonly" => !IsSuperadmin(),
        "addrecord" => false,
        "gotoview" => "au",
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => _m("Alerts User"), 
        "caption" => _m("Alerts User"),
        "mainmenu" => "sliceadmin",
        "submenu" => "te_alerts_users",
        "fields" => array (
            "id" => array ("view"=>array("readonly"=>true)),
            "email" => array ("view" => array ("type"=>"text","size"=>array("cols"=>30)),
				"caption"=>_m("email"),
                "validate"=>"email",
                "required" => true),
            "firstname" => array ("caption"=>_m("first name"),"view" => array ("type"=>"text","size"=>array("cols"=>8))),
            "lastname" => array ("caption"=>_m("last name"),"view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "confirm" => array ("caption" =>_m("confirmed"),"view" => array ("type" => "userdef", "function" => "te_au_confirm")),
            "lang" => array ("caption"=>_m("language"),"view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2)))),
        "attrs" => $attrs_edit,
        "children" => array (
            "auc" => array (
                 "header" => _m ("Collections"),
                 "join" => array ("id" => "userid")
             )
         ),
         "where" => CreateWhereFromList ("id", FindAlertsUserPermissions())
    );
    
    if ($viewID == "auc") {
        $db->query ("SELECT id,description,showme FROM alerts_collection ".
            "WHERE ".CreateWhereFromList ("id", FindCollectionPermissions()));
        while ($db->next_record()) {
            $alerts_collection[$db->f("id")] = $db->f("description");
            if ($db->f("showme")) 
                $alerts_collection_show[$db->f("id")] = $db->f("description");
        }
        
        return  array (
        "table" => "alerts_user_collection",
        "type" => "browse",
        "readonly" => false,
        "addrecord" => true,
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => L_ALERTS_COLLECTION_TITLE, 
        "caption" => L_ALERTS_COLLECTION_TITLE,
        "attrs" => $attrs_browse,
        "where" => CreateWhereFromList ("collectionid", FindCollectionPermissions()),
        "orderby" => "howoften",
        "fields" => array (
            "collectionid" => array (
				"caption"=>_m("collection"),
                "view" => array (
                    "readonly" => true,
                    "type" => "select",
                    "size" => array ("cols" => 4),
                    "href_view" => "ac_edit",
                    "source" => $alerts_collection),
                "view_new_record" => array (
                    "readonly" => false,
                    "type" => "select",
                    "size" => array ("cols" => 4),
                    "source" => $alerts_collection_show)),
            "howoften" => array (
				"caption"=>_m("how often"),
                "view" => array ("type" => "select", "source" => get_howoften_options ()))
        ));        
    }
    
    /* ------------------------------------------------------------------------------------
       alerts_admin
    */
    if ($viewID == "alerts_admin") {
        $db->query ("SELECT * FROM alerts_admin");
        if ($db->num_rows() == 0)
            $db->query ("INSERT INTO alerts_admin (mail_confirm, delete_not_confirmed) VALUES (0,0)");
        return array (
        "table" => "alerts_admin",
        "caption" => _m("Alerts Admin"),
        "title" => _m("Alerts Admin"),
        "mainmenu" => "sliceadmin",
        "submenu" => "te_alerts_admin",
        "buttons_down" => array ("update"=>1),
        "attrs" => array ("table"=>"border=1 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'"),    
        "type" => "edit",
        "readonly" => false,
        "addrecord" => false,
        "cond" => IsSuperadmin(),
        "newrecord" => false,
        "fields" => array (
            "mail_confirm" => array (
                "caption" => _m("confirm mail"),
                "hint" => _m("number of days, 0 = off"),
                "view" => array (
                    "type" => "text",
                    "size" => array ("cols" => 3)),
                "validate" => "number"),
            "delete_not_confirmed" => array (
                "caption" => _m("delete not confirmed"),
                "hint" => _m("number of days, 0 = off"),
                "view" => array (
                    "type" => "text",
                    "size" => array ("cols" => 3)),
                "validate" => "number"),
            "last_mail_confirm" => array (
                "caption" => _m ("last confirm mail"),
                "view" => array (
                    "readonly" => true,
                    "type" => "date",
                    "size" => array ("cols" => 6), 
                    "format" => "j.m.y G:i")),
            "last_delete" => array (
                "caption" => _m ("last delete not confirmed"),
                "view" => array (
                    "readonly" => true,
                    "type" => "date",
                    "size" => array ("cols" => 6), 
                    "format" => "j.m.y G:i"))),
        "help" => _m (
            "This table sets handling of not confirmed users. It's accessible only
            to superadmins.
            You can delete not confirmed users after a number of days and / or send them an email 
            demanding them to do confirmation
            after a smaller number of days. To switch either of the actions off,
            set number of days to 0. The two last fields are for your information only.<br>
            <br>
            To run the script, you must have cron set up with a row running
            misc/alerts/admin_mails.php3.<br>
            For more information, see <a href='http://apc-aa.sourceforge.net/faq/#1389'>the FAQ</a>."));
    }            
} // end of GetTableView
            
// ----------------------------------------------------------------------------------        

function FindAlertsFilterPermissions() {   
    global $auth, $_filter_permissions;
    $db = new DB_AA;
    
    // work only once
    if (isset ($_filter_permissions))
        return $_filter_permissions;
        
    if (IsSuperadmin()) 
        return 0;
    
    $myslices = GetUsersSlices( $auth->auth["uid"] );
    reset ($myslices);
    while (list ($my_slice_id, $perms) = each ($myslices)) 
        if (strchr ($perms, PS_FULLTEXT))
            $restrict_slices[] = q_pack_id($my_slice_id);
    $_filter_permissions = array ();
    if (is_array ($restrict_slices)) {
        $db->query ("SELECT DISTINCT ADF.id FROM alerts_filter ADF 
                     INNER JOIN view ON view.id = ADF.vid
                     INNER JOIN slice ON slice.id = view.slice_id
                     WHERE slice_id IN ('".join("','",$restrict_slices)."')");
        $_filter_permissions = array ();             
        while ($db->next_record())
            $_filter_permissions[] = $db->f("id");
    }
    return $_filter_permissions;
}

// ----------------------------------------------------------------------------------        

function FindAlertsUserPermissions () {
    global $Tab, $db, $collectionid, $sess, $setTab;
    $now = time();
    switch ($Tab) {
        case 'appb': $where = "status_code = 1 AND start_date > $now"; break;
        case 'appc': $where = "status_code = 1 AND start_date <= $now AND expiry_date < $now"; break;
        case 'hold': $where = "status_code = 2"; break;
        case 'trash':$where = "status_code = 3"; break;        
        case 'app': 
        default: $where = "status_code = 1 AND start_date <= $now
            AND expiry_date >= $now"; break;
    }
    
    $db->query ("SELECT userid 
        FROM alerts_user_collection 
        WHERE collectionid=$collectionid AND $where");
    $retval = array ();
    while ($db->next_record()) 
        $retval[] = $db->f("userid");
    return $retval;
}

// ----------------------------------------------------------------------------------        

// user function for confirmed
function te_au_confirm ($val) {
    return $val ? _m("no") : _m("yes");
}

function AlertsModeditAfterInsert ($varset) {
    global $change_id;
    $change_id = unpack_id ($varset->get ("id"));
}
?>