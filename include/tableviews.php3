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

// Settings for each table view (see doc/tabledit.html for more info)       

function GetTableView ($viewID) {        
    global $auth, $slice_id;
    $db = new DB_AA;

    $attrs_edit = array (
        "table"=>"border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'");
    $attrs_browse = array (
        "table"=>"border=1 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
        "table_search" => "border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'");
    $format = array (
        "hint" => array (
            "before" => "<i>",
            "after" => "</i>"),
        "caption" => array (
            "before" => "<b>",
            "after" => "</b>"));
            
    /* ------------------------------------------------------------------------------------
       ac_edit and its children acf and acu
       Alerts collection editing with its users and filters
    */    
    
    if ($viewID == "ac_edit") return  array (
        "table" => "alerts_collection",
        "type" => "edit",
        "readonly" => false,
        "gotoview" => "ac",
        "cond" => IfSlPerm(PS_FULLTEXT),
        "title" => _m("Alerts Collection"), 
        "caption" => _m("Alerts Collection"),
        "mainmenu" => "sliceadmin",
        "fields" => array (
            "description" => array (
                "view" => array ("type" => "area", "size" => array("cols"=>60,"rows"=>2)),
				"caption" => _m("description"),
                "required" => true),
            "editorial" => "",
            "showme" => array (
				"caption" => _m("standard"),
                "default" => 1,
                "view" => array (
					"readonly" => true,
					"type"=>"select",
					"source"=>array("0"=>_m("no"),"1"=>_m("yes"))),
                "view_new_record" => array ("readonly" => true)),    
            "mail_from" => array ("caption" => "From:", "hint"=>_m("mail header")),
            "mail_reply_to" => array ("caption" => "Reply-To:"),
            "mail_errors_to" => array ("caption" => "Errors-To:"),
            "mail_sender" => array ("caption" => "Sender:")),
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
        global $AA_CP_Session;
        while ($db->next_record()) {
            $txt = $db->f("name"). " - ". $db->f("fdesc");
            $filters[$db->f("filterid")] = 
                "<a href=\"se_view.php3?view_id=".$db->f("view_id")."&view_type=".$db->f("view_type")
                ."&change_id=".unpack_id($db->f("slice_id"))
                ."&AA_CP_Session=$AA_CP_Session\">".HTMLEntities($txt)."</a>";
            if (!is_array ($filter_perms) || my_in_array ($db->f("filterid"), $filter_perms))
                $new_filters[$db->f("filterid")] = $txt;
        }
        
        return  array (            
        "table" => "alerts_collection_filter",
        "type" => "browse",
        "readonly" => false,
        "addrecord" => true,
        "cond" => IfSlPerm(PS_FULLTEXT),
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
        "table" => "alerts_user_filter",
        "type" => "browse",
        "readonly" => false,
        "addrecord" => false,
        "cond" => IfSlPerm(PS_FULLTEXT),
        "title" => L_ALERTS_COLLECTION_TITLE, 
        "caption" => L_ALERTS_COLLECTION_TITLE,
        "attrs" => $attrs_browse,
        "orderby" => "howoften",
		"messages" => array (
	        "no_item" => _m ("There are no users subscribed to this collection yet.")),
        "fields" => array (
            "collectionid" => array ("view" => array ("type" => "hide")),
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
        "cond" => IfSlPerm(PS_FULLTEXT),
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
            "editorial" => array ("view"=>array("type"=>"text","size"=>array("cols"=>35))),
            "mail_from" => array (
				"caption"=>"From:",
                "hint"=>_m("mail header"),
				"view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "mail_reply_to" => array ("caption"=>"Reply-To:","view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "mail_errors_to" => array ("caption"=>"Errors-To:","view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "mail_sender" => array ("caption"=>"Sender:","view" => array ("type"=>"text","size"=>array("cols"=>15)))),
        "attrs" => $attrs_browse,
        "where" => CreateWhereFromList ("id", FindCollectionPermissions()));
    
    /* ------------------------------------------------------------------------------------
       au -- browse Alerts users
    */       
    global $LANGUAGE_CHARSETS;
    reset ($LANGUAGE_CHARSETS);
    while (list ($l) = each ($LANGUAGE_CHARSETS))
        $langs[$l] = $l;

    if ($viewID == "au") return  array (
        "table" => "alerts_user",
        "type" => "browse",
        "mainmenu" => "sliceadmin",
        "submenu" => "te_alerts_users",
        "readonly" => !IsSuperadmin(),
        //"buttons_down" => array ("update_all" => 1, "delete_all" => 1),
        "addrecord" => false,
        "help" => _m("To add users use the standard Alerts User Interface."),
        "gotoview" => "au_edit",
        "cond" => IfSlPerm(PS_FULLTEXT),
        "title" => _m("Alerts Users"), 
        "caption" => _m("Alerts Users"),
        "mainmenu" => "sliceadmin",
        "submenu" => "te_alerts_users",
        "orderby" => "email",
        "fields" => array (
            "email" => array (
				"caption" => _m("email"),
                "view" => array ("type"=>"text","size"=>array("cols"=>30)), 
                "validate"=>"email",
                "required" => true),
            "firstname" => array ("caption"=>_m("first name"),"view" => array ("type"=>"text","size"=>array("cols"=>8))),
            "lastname" => array ("caption"=>_m("last name"),"view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "confirm" => array ("caption" =>_m("confirmed"),"view" => array ("type" => "userdef", "function" => "te_au_confirm")),
            "lang" => array ("caption"=>_m("language"),"view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2)))),
        "attrs" => $attrs_browse,
        "where" => CreateWhereFromList ("id", FindAlertsUserPermissions()),
		"messages" => array (
	        "no_item" => _m("No user is subscribed to any collection you have permissions to.")));
    
    /* ------------------------------------------------------------------------------------
       au_edit and its child auc
       Alerts user editing with her collections
    */        
    if ($viewID == "au_edit") return  array (
        "table" => "alerts_user",
        "type" => "edit",
        "readonly" => !IsSuperadmin(),
        "addrecord" => false,
        "gotoview" => "au",
        "cond" => IfSlPerm(PS_FULLTEXT),
        "title" => _m("Alerts User"), 
        "caption" => _m("Alerts User"),
        "mainmenu" => "sliceadmin",
        "submenu" => "te_alerts_users",
        "fields" => array (
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
        "table" => "alerts_user_filter",
        "type" => "browse",
        "readonly" => false,
        "addrecord" => true,
        "cond" => IfSlPerm(PS_FULLTEXT),
        "title" => L_ALERTS_COLLECTION_TITLE, 
        "caption" => L_ALERTS_COLLECTION_TITLE,
        "attrs" => $attrs_browse,
        "where" => CreateWhereFromList ("collectionid", FindCollectionPermissions()),
        "orderby" => "howoften",
        "fields" => array (
            "userid" => array (
                "view" => array ("type" => "hide")),
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
    
    /* ------------------------------------------------------------------------------------
       polls_designs
    */
    if ($viewID == "polls_designs") {
        return  array (
        "table" => "polls_designs",
        "type" => "browse",
        "mainmenu" => "modadmin",
        "submenu" => "design",
        "readonly" => true,
        "addrecord" => false,
        "where" => "(pollsModuleID='". q_pack_id($slice_id)."')",
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_POLLS_EDIT_DESIGN),
        "title" => _m ("Polls Design"),
        "caption" => _m("Polls Design"),
        "attrs" => $attrs_browse,
        "gotoview" => "polls_designs_edit",
        "fields" => array (
            "designID" => array ("caption" => _m("Id")),
            "name"     => array ("caption" => _m("Name")),
            "comment"  => array ("caption" => _m("Comment"))
        ));
    }

    if ($viewID == "polls_designs_edit") {
        $retval = GetTableView ("polls_designs");
        $retval["type"] = "edit";
        $retval["attrs"] = $attrs_edit;
        $retval["readonly"] = false;
        $retval["gotoview"] = "polls_designs";
        $retval["addrecord"] = false;
        $retval["fields"] = array (
            "designID"        => array ("caption" => _m("Id"),
                                        "view" => array( "type"=>"text",
                                                         "readonly" => true )),
            "name"            => array ("caption" => _m("Name"),
                                        "view" => array( "type"=>"text" ),
                                        "required" => true ),
            "comment"         => array ("caption" => _m("Comment"),
                                        "view" => array( "type"=>"text" ),
                                         "hint" => _m("design description (for administrators only)")),
            "resultBarFile"   => array ("caption" => _m("Bar image"),
                                        "view" => array( "type"=>"text" ),
                                        "hint" => _m("url of image for bar")),
            "resultBarWidth"  => array ("caption" => _m("Bar width"),
                                        "hint" => _m("width of poll bar")),
            "resultBarHeight" => array ("caption" => _m("Bar height"),
                                        "hint" => _m("height of poll bar")),
            "top"             => array ("caption" => _m("Top HTML")),
            "answer"          => array ("caption" => _m("Answer HTML")),
            "bottom"          => array ("caption" => _m("Bottom HTML")),
            "params"          => array ("caption" => _m("Params"))
            );
        return $retval;
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
    
    $myslices = GetUserSlices();
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
        
// find collection permissions: user may edit collections which contain any filter
// defined in any view in a slice to which she / he has permissions
function FindCollectionPermissions () {                   
    global $auth, $_collection_permissions;
    $db = new DB_AA;
    
    // work only once
    if (isset ($_collection_permissions))
        return $_collection_permissions;
        
    if (IsSuperadmin()) 
        return 0;
    
    $myslices = GetUserSlices();
    reset ($myslices);
    while (list ($my_slice_id, $perms) = each ($myslices)) 
        if (strchr ($perms, PS_FULLTEXT))
            $restrict_slices[] = q_pack_id($my_slice_id);
    $_collection_permissions = array ();
    if (is_array ($restrict_slices)) {
        $db->query ("SELECT DISTINCT AC.id FROM alerts_collection AC
                     INNER JOIN alerts_collection_filter ACF ON AC.id = ACF.collectionid
                     INNER JOIN alerts_filter ADF ON ADF.id = ACF.filterid
                     INNER JOIN view ON view.id = ADF.vid
                     INNER JOIN slice ON slice.id = view.slice_id
                     WHERE slice_id IN ('".join("','",$restrict_slices)."')");
        $_collection_permissions = array ();             
        while ($db->next_record())
            $_collection_permissions[] = $db->f("id");
            
        // collections with no filters
        $db->query ("SELECT id, collectionid FROM alerts_collection AC
                     LEFT JOIN alerts_collection_filter ACF ON AC.id = ACF.collectionid
                     WHERE showme=1 AND collectionid IS NULL");
        while ($db->next_record()) 
            $_collection_permissions[] = $db->f("id");
    }
    return $_collection_permissions;
}

// ----------------------------------------------------------------------------------        

function FindAlertsUserPermissions () {
    global $_alerts_user_permissions;
    $db = new DB_AA;
    // work only once
    if (!isset ($_alerts_user_permissions)) {
        $coll = FindCollectionPermissions();
        if (!is_array ($coll))
            return 0;
        $_alerts_user_permissions = array ();
        if (count ($coll)) {            
            $db->query ("SELECT DISTINCT AU.id FROM alerts_user AU INNER JOIN 
                         alerts_user_filter AUF ON AU.id = AUF.userid
                         WHERE AUF.collectionid IN (". join (",",$coll) .")");
            while ($db->next_record())
                $_alerts_user_permissions[] = $db->f("id");
        }
    }
    return $_alerts_user_permissions;
}

// ----------------------------------------------------------------------------------        

// user function for confirmed
function te_au_confirm ($val) {
    return $val ? _m("no") : _m("yes");
}
?>

