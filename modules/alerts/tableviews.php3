<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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

require_once __DIR__."/util.php3";
require_once __DIR__."/../../include/tv_email.php3";
require_once __DIR__."/../../include/locauth.php3";

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

    global $auth;
    global $attrs_edit, $attrs_browse;

    // ------------------------------------------------------------------------------------

    if ($viewID == "acf") {
        global $collectionid;

        $db = getDB();
        $db->query("SELECT AF.description, AF.id FROM alerts_filter AF");
        if ($db->num_rows()) {
            while ($db->next_record()) {
                $collection_filters[$db->f("id")] = $db->f("description");
            }
        }
        else {
            $collection_filters[-1] = _m("No selections defined. You must define some.");
        }

        // filter select box
        $SQL = "SELECT slice.name, DF.description as fdesc, DF.id AS filterid,
                       view.id AS view_id, view.type as view_type, view.slice_id FROM
                        slice INNER JOIN
                        view ON slice.id = view.slice_id INNER JOIN
                        alerts_filter DF ON DF.vid = view.id";
        $SQL .= " ORDER BY DF.description";
        $db->query ($SQL);
        $myslices = GetUserSlices();
        while ($db->next_record()) {
            $txt = myspecialchars($db->f("fdesc"));
            if (IsSuperadmin() || strchr($myslices[unpack_id($db->f("slice_id"))], PS_FULLTEXT)) {
                $new_filters[$db->f("filterid")] = $txt;
                $txt = "<a href='".StateUrl(AA_INSTAL_PATH
                    ."admin/se_view.php3?slice_id=".unpack_id($db->f("slice_id"))
                    ."&view_id=".$db->f("view_id")
                    ."&view_type=".$db->f("view_type"))
                    ."'>".$txt." ("."f".$db->f("filterid").")"."</a>";
            }
            $filters[$db->f("filterid")] = $txt;
        }
        freeDB($db);

        return  [
        "table" => "alerts_collection_filter",
        "type" => "browse",
        "readonly" => false, //$no_filters,
        "buttons_left" => ["delete_checkbox" => 1],
        "buttons_down" => ["update_all" => 1, "delete_all" => 1],
        "addrecord" => is_array($new_filters),
        "gotoview" => "au_edit",
        "mainmenu" => "admin",
        "submenu" => "filters",
        "search" => false,
        "caption" => _m("Alerts Selections"),
        "title" => _m("Selections"),
        "cond" => IfSlPerm(PS_FULLTEXT),
        "orderby" => "myindex",
        "help" => _m("Choose selections which form the Alert email."),
        "where" => "collectionid = '$collectionid'",
        "fields" => [
            "collectionid" => [
                "view" => ["type" => "hide"],
                "default" => $collectionid
            ],
            "filterid" => [
                "caption" => _m("selection"),
                "view" => [
                    "readonly" => true,
                    "type" => "select",
                    "html" => true,
                    "source" => $filters
                ],
                "view_new_record" => [
                    "type" => "select",
                    "source" => $new_filters
                ]
            ],
            "myindex" => [
                "caption" => _m("order"),
                "default" => 1
            ]
        ],
        "attrs" => $attrs_browse
        ];
    }

    /* ------------------------------------------------------------------------------------
       modedit
       Alerts collection setting

       modedit_insert
       Processing form data on Alerts module addition.
    */

    if ($viewID == "modedit") {
        $alertslangs = AA_Langs::getFiles('alerts');
        return [
        "table" => "module",
        "join" => [
            "alerts_collection" => [
                "joinfields" => [
                    "id" => "module_id"
                ],
                "jointype" => "1 to 1"
            ]
        ],
        "type" => "edit",
        "readonly" => false,
        "cond" => 1,
        "title" => _m("Alerts Settings"),
        "caption" => _m("Alerts Settings"),
        "mainmenu" => "admin",
        "submenu" => "settings",
        "help" => _m("Core settings for the Alerts."),
        "triggers" => [
            "AfterInsert" => "AlertsModeditAfterInsert"
        ],
        "fields" => [
            "_alerts_collection_id_" => [
                "table" => "alerts_collection",
                "field" => "id",
                "default" => new_collection_id(),
                "view" => ["readonly" => true],
                "caption" => _m("alerts ID")
            ],
/*            "slice_id" => array (
                "table" => "alerts_collection",
                "view" => array ("type"=>"select", "source"=>getReaderManagementSlices()),
                "caption" => _m("reader management slice")),*/
            "name" => [
                "view" => ["type" => "text", "size" => ["cols"=>60]],
                "caption" => _m("name"),
                "required" => true
            ],
            "slice_url" => ["caption" => _m("form URL"), "required"=>true],
            "lang_file" => [
                "caption" => _m("language"),
                "view" => ["type"=>"select","source"=>$alertslangs]
            ],
            "deleted" => [
                "caption" => _m("deleted"),
                "hint" => _m("Use AA Admin / Delete<br>to delete permanently"),
                "view" => ["type"=>"checkbox"]
            ],
            "emailid_welcome" => [
                "table" => "alerts_collection",
                "caption" => _m("welcome email"),
                "view" => ($processForm ? "" : [
                    "type"=>"select",
                    "href_view" => "email_edit",
                    "source"=>GetUserEmails("alerts welcome")
                ])
            ],
            "emailid_alert" => [
                "table" => "alerts_collection",
                "caption" => _m("alert email"),
                "view" => ($processForm ? "" : [
                    "type"=>"select",
                    "href_view" => "email_edit",
                    "source"=>GetUserEmails("alerts alert")
                ])
            ],
            "type"  => ["default" => "Alerts", "view" => ["type"=>"hide"]],
            "owner" => [
                "default" => "",       "view" => [
                "type"=>"text",
                "readonly" => 1
                ]
            ],
            "id" => [
                "default" => pack_id(new_id()),
                "view" => ["type"=>"text", "unpacked" => true, "readonly" => true]
            ],
            "created_at" => [
                "caption" => _m("created at"),
                "default"=>time(),
                "view"=> [
                    "type"=>"date",
                    "format" => "j.m.y",
                    "readonly" => 1]
            ],
            "created_by" => [
                "caption" => _m("created by"),
                "default"=>$auth->auth["uid"],
                "view"=> [
                    "type"=>"text",
                    "readonly" => 1
                ]
            ]
        ],
        "attrs" => $attrs_edit,
        "messages" => [
            "no_item" => _m("You don't have permissions to edit any collection or no collection exists.")
        ]
        ];
    }

    /* ------------------------------------------------------------------------------------
       send_emails
    */
    if ($viewID == "send_emails") {
        return [
        "table" => "alerts_collection",
        "type" => "edit",
        "readonly" => false,
        "cond" => 1,
        "title" => _m("Send Emails"),
        "caption" => _m("Send Emails"),
        "mainmenu" => "admin",
        "submenu" => "send_emails",
        "attrs" => $attrs_edit,
        "messages" => [
            "no_item" => _m("You don't have permissions to edit any collection or no collection exists.")
        ],
        "help" => _m("Here you send the Alert emails manually."),
        "fields" => [
            "emailid_alert" => [
                "table" => "alerts_collection",
                "caption" => _m("alert email"),
                "view" => ($processForm ? "" : [
                    "type"=>"select",
                    "href_view" => "email_edit",
                    "source"=>GetUserEmails("alerts alert")
                ])
            ],
        ]
        ];
    }

} // end of GetTableView

// ----------------------------------------------------------------------------------

function FindAlertsUserPermissions() {
    global $Tab, $collectionid;
    $now = time();
    switch ($Tab) {
        case 'appb': $where = "status_code = 1 AND start_date > $now"; break;
        case 'appc': $where = "status_code = 1 AND start_date <= $now AND expiry_date < $now"; break;
        case 'hold': $where = "status_code = 2"; break;
        case 'trash':$where = "status_code = 3"; break;
        case 'app':
        default: $where = "status_code = 1 AND start_date <= $now AND expiry_date >= $now"; break;
    }

    $db = getDB();
    $db->query("SELECT userid
        FROM alerts_user_collection
        WHERE collectionid='$collectionid' AND $where");
    $retval = [];
    while ($db->next_record()) {
        $retval[] = $db->f("userid");
    }
    freeDB($db);
    return $retval;
}

// ----------------------------------------------------------------------------------

function AlertsModeditAfterInsert($varset) {
    // called for all tables
    if ($varset->get("module_id") AND is_long_id($module_id = unpack_id($varset->get("module_id")))) {
        AddPermObject($module_id, "slice");
        AA::$module_id = $module_id;
    }
}
