<?php

// ----------------------------------------------------------------------------------------    

// Settings for each table view 
/*  Each table is shown in the Browse view where you see the table contents and buttons Edit, Delete and Add. After clicking on Edit or Add the Edit view is shown where you can edit the fields. 

    The advanced feature "children" allows to view children from tables which have a relationship 1:n to the table edited.

/* Grammar: * = required, | = alternatives
    
   "table"* => table name
   "type"* => "edit" | "browse"  browse view = table, edit view = fields one on a row each 
   "title"* => HTML page title
   "caption"* => caption shown above the table
   "cond"* => permissions needed to access this site
   "mainmenu"* => menu
   "submenu"* => menu
   "fields"* => (array of many)
        "field_name"* => (array of)
            "hint" => hint to be shown in Edit view
            "view" => (array of) special view (if other than default)
                "type" => view type = "select" | "blob" | "hide" | "text"
                "source" => required for "select", array of ("value"=>"option")
                "size" => required for "text", array ("cols"=>..)
                "readonly" => true | false  
                "href_view" => applicable only with readonly=true, links the text to another table view
            "view_new_record" => the same as "view", applied only on empty new record
                                   if not filled, "view" is used instead
                "default" => default value for new record                                 
            "primary" => "number" | "text" | "packed"  
                if filled, this field is a part of primary key
   "attrs_edit" => attributes for TABLE and TD in Edit view
   "attrs_browse" => attributes for TABLE and TD in Browse view
   "children" => (array of many) tables with relationship n:1 
        "table_name"* => (array of)
            "join"* => (array of many) master fields must be the ones with "primary" set
                "master field" => "child field" (child field is in table table_name)
            "fields"* => the same as above
   "gotoview" => which to show after submitting form (if other than current view)
   "readonly" => true | false  default for all fields
   "addrecord" => true | false  show an empty record, applicable only with readonly = false
*/        

$attrs_edit = array (
        "table"=>"border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
        "td"=>"class=tabtxt");
$attrs_browse = array (
        "table"=>"border=1 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
        "td"=>"class=tabtxt");

$tableviews["ww"] = array (
    "table" => "wizard_welcome",
    "type" => "browse",
    "readonly" => true,
    "title" => L_EDIT_WIZARD_WELCOME,
    "caption" => L_EDIT_WIZARD_WELCOME,
    "mainmenu" => "aaadmin",
    "submenu" => "te_wizard_welcome",
    "cond" => IsSuperadmin(),
    "fields" => array (
        "id" => array (
            "primary" => "number", 
            "view" => array ("type" => _m("hide"))),
        "description" => "",
        "email" => array ("hint" => _m("mail body")),
        "subject" => "",
        "mail_from" => array ("hint" => _m("From: mail header"))),
    "attrs" => $attrs_browse,
    "buttons" => array (
        "edit" => 1,
        "delete" => 1,
        "add" => 1),
    "gotoview" => "ww_edit");
    
$tableviews["ww_edit"] = $tableviews["ww"];
$tableviews["ww_edit"]["type"] = "edit";
$tableviews["ww_edit"]["attrs"] = $attrs_edit;
$tableviews["ww_edit"]["readonly"] = false;
$tableviews["ww_edit"]["gotoview"] = "ww";
$tableviews["ww_edit"]["addrecord"] = false;

$tableviews["wt"] = array (
    "table" => "wizard_template",
    "type" => "browse",
    "readonly" => false,
    "cond" => IsSuperadmin(),
    "title" => L_EDIT_WIZARD_TEMPLATE,
    "caption" => L_EDIT_WIZARD_TEMPLATE,
    "mainmenu" => "aaadmin",
    "submenu" => "te_wizard_template",
    "fields" => array (
        "id" => array (
            "primary" => "number", 
            "view" => array ("type" => "hide")),        
        "dir"=> array (
            "view" => array ("type" => "text", "size" => array ("cols" => 10))), 
        "description"=> array (
            "view" => array ("type" => "text", "size" => array ("cols" => 40)))
    ),
    "attrs" => $attrs_browse,
    "buttons" => array ("update" => 1, "delete" => 1));

$db = new DB_AA;
$db->query ("SELECT slice.name, DF.description as fdesc, DF.id AS filterid FROM
                slice INNER JOIN
                view ON slice.id = view.slice_id INNER JOIN
                alerts_digest_filter DF ON DF.vid = view.id
                ORDER BY slice.name, DF.description");  
while ($db->next_record()) 
    $filters[$db->f("filterid")] = $db->f("name"). " - ". $db->f("fdesc");
    
$tableviews["acf"] = array (
    "table" => "alerts_collection_filter",
    "type" => "browse",
    "readonly" => false,
    "buttons" => array ("update" => 1, "delete" => 1),
    "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
    "title" => L_ALERTS_COLLECTION_TITLE, 
    "caption" => L_ALERTS_COLLECTION_TITLE,
    "attrs" => $attrs_browse,
    "fields" => array (
        "collectionid" => array (
            "primary" => "number", 
            "view" => array ("type" => "hide")),
        "filterid" => array (
            "primary" => "number", 
            "view" => array (
                "type" => "select",
                "source" => $filters)),
        "myindex" => array (
            "view" => array (
                "type" => "text",
                "size" => array ("cols" => 2)))));

$db->query ("SELECT id, email FROM alerts_user");
while ($db->next_record())
    $alerts_users[$db->f("id")] = $db->f("email");
                
$tableviews["acu"] = array (
    "table" => "alerts_user_filter",
    "type" => "browse",
    "readonly" => false,
    "addrecord" => false,
    "buttons" => array ("update" => 1, "delete" => 1),
    "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
    "title" => L_ALERTS_COLLECTION_TITLE, 
    "caption" => L_ALERTS_COLLECTION_TITLE,
    "attrs" => $attrs_browse,
    "fields" => array (
        "id" => array (
            "primary" => "number", 
            "view" => array ("type" => "hide")),
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
    
$tableviews["ac"] = array (
    "table" => "alerts_collection",
    "type" => "browse",
    "readonly" => false,
    "addrecord" => false,
    "buttons" => array ("update"=>1,"delete"=>1,"edit"=>1,"add"=>1),
    "gotoview" => "ac_edit",
    "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
    "title" => L_ALERTS_COLLECTION_TITLE, 
    "caption" => L_ALERTS_COLLECTION_TITLE,
    "mainmenu" => "aaadmin",
    "submenu" => "te_alerts_collections",
    "fields" => array (
        "id" => array (
            "primary" => "number", 
            "view" => array ("type" => "hide")),
        "description" => array ("view" => array ("type"=>"text","size"=>array("cols"=>30))),
        "showme" => array ("view" => array (
                                "type"=>"text",
                                "size"=>array("cols"=>8),
                                "readonly" => true),
                           "hint" => _m("0 = special or user def")),
        "mail_from" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
        "mail_reply_to" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
        "mail_errors_to" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
        "mail_sender" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15)))),
    "attrs" => $attrs_browse);
    
$tableviews["ac_edit"] = array (
    "table" => "alerts_collection",
    "type" => "edit",
    "readonly" => false,
    "gotoview" => "ac",
    //"buttons" => array ("update"=>1,"delete"=>1,"edit"=>1),
    "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
    "title" => _m("Alerts Collection"), 
    "caption" => _m("Alerts Collection"),
    "mainmenu" => "aaadmin",
    "submenu" => "te_alerts_collections",
    "fields" => array (
        "id" => array (
            "primary" => "number", 
            "view" => array ("type" => "hide")),
        "description" => "",
        "showme" => array (
            "view" => array ("readonly" => true),
            "view_new_record" => array ("readonly" => true, "default" => 1)),    
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
    ));
    
reset ($LANGUAGE_CHARSETS);
while (list ($l) = each ($LANGUAGE_CHARSETS))
    $langs[$l] = $l;
    
$tableviews["au"] = array (
    "table" => "alerts_user",
    "type" => "browse",
    "readonly" => false,
    "buttons" => array ("update"=>1,"delete"=>1,"edit"=>1),
    "gotoview" => "au_edit",
    "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
    "title" => _m("Alerts Users"), 
    "caption" => _m("Alerts Users"),
    "mainmenu" => "aaadmin",
    "submenu" => "te_alerts_users",
    "fields" => array (
        "id" => array (
            "primary" => "number", 
            "view" => array ("type" => "hide")),
        "email" => array ("view" => array ("type"=>"text","size"=>array("cols"=>30))),
        "firstname" => array ("view" => array ("type"=>"text","size"=>array("cols"=>8))),
        "lastname" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
        "lang" => array ("view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2)))),
    "attrs" => $attrs_browse);

$tableviews["au_edit"] = array (
    "table" => "alerts_user",
    "type" => "edit",
    "readonly" => false,
    "buttons" => array ("update"=>1,"delete"=>1,"edit"=>1),
    "gotoview" => "au",
    "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
    "title" => _m("Alerts User"), 
    "caption" => _m("Alerts User"),
    "mainmenu" => "aaadmin",
    "submenu" => "te_alerts_users",
    "fields" => array (
        "id" => array (
            "primary" => "number", 
            "view" => array ("type" => "hide")),
        "email" => array ("view" => array ("type"=>"text","size"=>array("cols"=>30))),
        "firstname" => array ("view" => array ("type"=>"text","size"=>array("cols"=>8))),
        "lastname" => array ("view" => array ("type"=>"text","size"=>array("cols"=>15))),
        "lang" => array ("view" => array ("type"=>"select","source"=>$langs,"size"=>array("cols"=>2)))),
    "attrs" => $attrs_edit,
    "children" => array (
        "auc" => array (
             "header" => _m ("Collections"),
             "join" => array ("id" => "userid")
         )
     )
);

$db->query ("SELECT id,description,showme FROM alerts_collection");
while ($db->next_record()) {
    $alerts_collection[$db->f("id")] = $db->f("description");
    if ($db->f("showme")) 
        $alerts_collection_show[$db->f("id")] = $db->f("description");
}

$tableviews["auc"] = array (
    "table" => "alerts_user_filter",
    "type" => "browse",
    "readonly" => false,
    "addrecord" => true,
    "buttons" => array ("update" => 1, "delete" => 1),
    "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
    "title" => L_ALERTS_COLLECTION_TITLE, 
    "caption" => L_ALERTS_COLLECTION_TITLE,
    "attrs" => $attrs_browse,
    "fields" => array (
        "id" => array (
            "primary" => "number", 
            "view" => array ("type" => "hide")),
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
?>
