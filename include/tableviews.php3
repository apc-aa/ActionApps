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

// Settings for each table view (see include/tabledit.php3 for more info)

/* Table Views Grammar: * = required, | = alternatives
    
   "table"* => table name
   "type"* => "edit" | "browse"  browse view = table, edit view = fields one on a row each 
   "caption"* => caption shown above the table
   "cond"* => permissions needed to access this site
   "mainmenu"* => menu
   "submenu"* => menu
   "title"* => HTML page title

   "attrs" => attributes for TABLE and TD
   "addrecord" => true | false  show an empty record, applicable only with readonly = false
                  default true
   "buttons"  => buttons to be shown on each record row in browse view
                 array (button_name => 1, ...), where button_name is 
                 "delete" | "update" | "edit" 
   "button_add" => true | false  show Insert button in browse view
   "gotoview" => which view to show after clicking on Edit or Insert (Browse view)
   				 or after clicking on Cancel (Edit view)
				 default: stay in the same view
   "help" => text to be shown above the table
   "listlen" => number of records to be shown at once, default: 15
   "orderby" => field to sort by (default: don't sort)
   "orderdir" => order direction, "a" = ascending or "d" = descending (default: "a")
   "messages" => array (
	   "no_item" => message to be shown when no items pass the WHERE SQL clause
	   "error_insert" => when insert fails
	   "error_update" => when update fails
	   "error_delete" => when delete fails
   "readonly" => true | false  default for all fields
   "where" => SQL WHERE condition
   "search" => view the search form?, default: true for browse view, false for edit view
               the form is shown only if scroller is shown (i.e. there are more records than 
               what fits to one page) 

   "fields"* => (array of many)
        "field_name"* => (array of)
            "hint" => hint to be shown in Edit view
            "validate" => "number" | "email" | "filename"
            "validate_min" => useful only with validate=number
            "validate_max" => -"-
            "default" => default value (for new records)
            "required" => is the field required? default: false
            "view" => (array of) special view (if other than default)
                "type" => view type = "select" | "blob" | "hide" | "text" | "date"
                "source" => required for "select", array of ("value"=>"option")
                "size" => required for "text", array ("cols"=>..)
                "format" => required for "date", usable only on readonly field
                "readonly" => true | false  if not set, the default readonly is used   
                "href_view" => applicable only with readonly=true, links the text to another table view
            "view_new_record" => the same as "view", applied only on empty new record
                                   if not filled, "view" is used instead
   "children" => (array of many) tables with relationship n:1 
        "table_name"* => (array of)
            "join"* => (array of many) master fields must be the ones with "primary" set
                "master field" => "child field" (child field is in table table_name)
            "fields"* => the same as above
*/        

function GetTableView ($viewID) {        
    global $auth, $slice_id;
    $db = new DB_AA;

    $attrs_edit = array (
            "table"=>"border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
            "td"=>"class=tabtxt");
    $attrs_browse = array (
            "table"=>"border=1 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
            "td"=>"class=tabtxt");
            
    /* ------------------------------------------------------------------------------------
       ww -- browse wizard welcomes
       ww_edit -- edit -"-
    */         
    if ($viewID == "ww") return  array (
        "table" => "wizard_welcome",
        "type" => "browse",
        "readonly" => true,
        "title" => L_EDIT_WIZARD_WELCOME,
        "caption" => L_EDIT_WIZARD_WELCOME,
        "mainmenu" => "aaadmin",
        "submenu" => "te_wizard_welcome",
        "cond" => IsSuperadmin(),
        "fields" => array (
            "description" => array ("required" => true),
            "email" => array ("hint" => _m("mail body"), "validate" => "email", "required" => true),
            "subject" => array ("required" => true),
            "mail_from" => array ("hint" => _m("From: mail header"), "required" => true)),
        "attrs" => $attrs_browse,
        "buttons" => array ("edit" => 1,"delete" => 1),
        "button_add" => 1,
        "gotoview" => "ww_edit");
        
    if ($viewID == "ww_edit") {
        $retval = GetTableView ("ww");
        $retval["type"] = "edit";
        $retval["attrs"] = $attrs_edit;
        $retval["readonly"] = false;
        $retval["gotoview"] = "ww";
        $retval["addrecord"] = false;
        return $retval;
    }
    
    /* ------------------------------------------------------------------------------------
       wt -- browse wizard templates 
    */
    if ($viewID == "wt") return  array (
        "table" => "wizard_template",
        "type" => "browse",
        "readonly" => false,
        "cond" => IsSuperadmin(),
        "title" => L_EDIT_WIZARD_TEMPLATE,
        "caption" => L_EDIT_WIZARD_TEMPLATE,
        "mainmenu" => "aaadmin",
        "submenu" => "te_wizard_template",
        "fields" => array (
            "dir"=> array (
                "view" => array ("type" => "text", "size" => array ("cols" => 10)),
                "validate" => "filename",
                "required" => true), 
            "description"=> array (
                "view" => array ("type" => "text", "size" => array ("cols" => 40)),
                "required" => true)
        ),
        "attrs" => $attrs_browse,
        "buttons" => array ("update" => 1, "delete" => 1));
    
    /* ------------------------------------------------------------------------------------
       ac_edit and its children acf and acu
       Alerts collection editing with its users and filters
    */    
    
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
            "description" => array (
                "required" => true),
            "editorial" => "",
            "showme" => array (
                "default" => 1,
                "view" => array ("readonly" => true),
                "view_new_record" => array ("readonly" => true)),    
            "mail_from" => "",
            "mail_reply_to" => "",
            "mail_errors_to" => "",
            "mail_sender" => ""),
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
        $SQL = "SELECT slice.name, DF.description as fdesc, DF.id AS filterid FROM
                        slice INNER JOIN
                        view ON slice.id = view.slice_id INNER JOIN
                        alerts_digest_filter DF ON DF.vid = view.id";
        $SQL .= " ORDER BY slice.name, DF.description";  
        $db->tquery ($SQL);
        $filter_perms = FindAlertsFilterPermissions();
        while ($db->next_record()) {
            $txt = $db->f("name"). " - ". $db->f("fdesc");
            $filters[$db->f("filterid")] = $txt;
            if (!is_array ($filter_perms) || my_in_array ($db->f("filterid"), $filter_perms))
                $new_filters[$db->f("filterid")] = $txt;
        }
        
        return  array (            
        "table" => "alerts_collection_filter",
        "type" => "browse",
        "readonly" => false,
        "buttons" => array ("update" => 1, "delete" => 1),
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
                "view" => array (
                    "readonly" => true,
                    "type" => "select",
                    "source" => $filters),
                "view_new_record" => array (
                    "type" => "select",
                    "source" => $new_filters)),
            "myindex" => array (
                "validate" => "number",
                "validate_min" => 1,
                "validate_max" => 99,
                "required" => true,
                "hint" => _m("order"),
                "default" => 1,
                "view" => array (
                    "type" => "text",
                    "size" => array ("cols" => 2)))));
    }
    
    if ($viewID == "acu") {
        $db->query ("SELECT id, email FROM alerts_user");
        while ($db->next_record())
            $alerts_users[$db->f("id")] = $db->f("id")." (".$db->f("email").")";
                    
        return  array (
        "table" => "alerts_user_filter",
        "type" => "browse",
        "readonly" => false,
        "addrecord" => false,
        "buttons" => array ("update" => 1, "delete" => 1),
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => L_ALERTS_COLLECTION_TITLE, 
        "caption" => L_ALERTS_COLLECTION_TITLE,
        "attrs" => $attrs_browse,
        "orderby" => "howoften",
		"messages" => array (
	        "no_item" => _m ("There are no users subscribed to this collection yet.")),
        "fields" => array (
            "userid" => array (
                "view" => array (
                    "readonly" => true,
                    "href_view" => "au_edit",
                    "type" => "select",
                    "size" => array ("cols" => 4),
                    "source" => $alerts_users)),
            "howoften" => array (
                "view" => array ("type" => "select", "source" => get_howoften_options ()))
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
        "buttons" => array ("update"=>1,"delete"=>1,"edit"=>1),
        "button_add"=>1,
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
                "view" => array ("type"=>"text","size"=>array("cols"=>30)),
                "required" => true),
            "showme" => array ("view" => array (
                                    "type"=>"text",
                                    "size"=>array("cols"=>8),
                                    "readonly" => true),
                               "hint" => _m("0 = special or user def")),
            "editorial" => array ("view"=>array("type"=>"text","size"=>array("cols"=>35))),
            "mail_from" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "mail_reply_to" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "mail_errors_to" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "mail_sender" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15)))),
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
        "addrecord" => false,
        "help" => _m("To add users use the standard Alerts User Interface."),
        "buttons" => array ("update"=>1,"delete"=>1,"edit"=>1),
        "gotoview" => "au_edit",
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => _m("Alerts Users"), 
        "caption" => _m("Alerts Users"),
        "mainmenu" => "sliceadmin",
        "submenu" => "te_alerts_users",
        "fields" => array (
            "email" => array (
                "view" => array ("type"=>"text","size"=>array("cols"=>30)), 
                "validate"=>"email",
                "required" => true),
            "firstname" => array ("view" => array ("type"=>"text","size"=>array("cols"=>8))),
            "lastname" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "lang" => array ("view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2)))),
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
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => _m("Alerts User"), 
        "caption" => _m("Alerts User"),
        "mainmenu" => "sliceadmin",
        "submenu" => "te_alerts_users",
        "fields" => array (
            "email" => array ("view" => array ("type"=>"text","size"=>array("cols"=>30)),
                "validate"=>"email",
                "required" => true),
            "firstname" => array ("view" => array ("type"=>"text","size"=>array("cols"=>8))),
            "lastname" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "lang" => array ("view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2)))),
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
        "buttons" => array ("update" => 1, "delete" => 1),
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => L_ALERTS_COLLECTION_TITLE, 
        "caption" => L_ALERTS_COLLECTION_TITLE,
        "attrs" => $attrs_browse,
        "where" => CreateWhereFromList ("collectionid", FindCollectionPermissions()),
        "fields" => array (
            "collectionid" => array (
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
                "view" => array ("type" => "select", "source" => get_howoften_options ()))
        ));        
    }
    
    /* ------------------------------------------------------------------------------------
       cron 
    */
    if ($viewID == "cron") {
        $url = "http://apc-aa.sourceforge.net/faq/#cron";    
        return  array (
        "table" => "cron",
        "type" => "browse",
        "mainmenu" => "aaadmin",
        "submenu" => "te_cron",
        "help" => _m("For help see FAQ: ")."<a target=\"_blank\" href=\"$url\">$url</a>",
        "readonly" => false,
        "addrecord" => true,
        "buttons" => array ("update" => 1, "delete" => 1),
        "cond" => IsSuperadmin(),
        "title" => _m ("Cron"),
        "caption" => _m("Cron"),
        "attrs" => $attrs_browse,
        "fields" => array (
            "minutes" => array ("view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "hours" => array ("view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "mday" => array ("view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "mon" => array ("view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "wday" => array ("view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "script" => array ("view" => array ("type" => "text", "size" => array ("cols"=>20)),
                "required" => true),
            "params" => array ("view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "last_run" => array ("view" => array ("readonly" => true, "type" => "date", "format" => "j.n.Y G:i"))
        ));
    }
    
    /* ------------------------------------------------------------------------------------
       slice -- for debug purposes only 
    */    
    if ($viewID == "slice") return array (
        "table" => "slice",
        "type" => "browse",
        "readonly" => true,
        "attrs" => $attrs_browse,
        "fields" => array (
            "name"));    
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
        $db->query ("SELECT DISTINCT ADF.id FROM alerts_digest_filter ADF 
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
    
    $myslices = GetUsersSlices( $auth->auth["uid"] );
    reset ($myslices);
    while (list ($my_slice_id, $perms) = each ($myslices)) 
        if (strchr ($perms, PS_FULLTEXT))
            $restrict_slices[] = q_pack_id($my_slice_id);
    $_collection_permissions = array ();
    if (is_array ($restrict_slices)) {
        $db->query ("SELECT DISTINCT AC.id FROM alerts_collection AC
                     INNER JOIN alerts_collection_filter ACF ON AC.id = ACF.collectionid
                     INNER JOIN alerts_digest_filter ADF ON ADF.id = ACF.filterid
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

function CreateWhereFromList ($column, $list, $type="number") {
    if (!is_array ($list)) return "1";
    if (count ($list) == 0) return "0";
    if ($type == "number") 
         return $column." IN (". join (",",$list). ")";
    else {
        $in = "";
        reset ($list);
        while (list (,$item) = each ($list)) {
            if ($in) $in .= ",";
            $in .= "'".addslashes ($item)."'";
        }
        return $column." IN ($in)";
    }
}
?>

