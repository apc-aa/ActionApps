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

// Settings for emails table views (see doc/tabledit.html for more info)       

function GetTableView ($viewID) {        
    global $auth, $slice_id, $db;
    global $attrs_edit, $attrs_browse, $format, $langs;

    /* ------------------------------------------------------------------------------------
       email 
    */
    
    if ($viewID == "emails") {
        if (IsSuperadmin())
            $restrict_slices = 1;
        else {
            $restrict_slices = array ();
            $myslices = GetUsersSlices( $auth->auth["uid"] );
            reset ($myslices);
            while (list ($my_slice_id, $perms) = each ($myslices)) 
                if (strchr ($perms, PS_FULLTEXT))
                    $restrict_slices[] = pack_id($my_slice_id);
        }
        $db->query ("SELECT id, name FROM slice WHERE "
            .CreateWhereFromList ("id", $restrict_slices, "text"));
        while ($db->next_record()) 
            $slice_names[$db->f("id")] = $db->f("name");
        
        return  array (
        "table" => "email",
        "type" => "browse",
        "mainmenu" => "sliceadmin",
        "submenu" => "te_emails",
        "help" => _m("You may use aliases related to the usage of the emails."),
        "readonly" => false,
        "addrecord" => true,
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => _m("Emails"),
        "caption" => _m("Emails"),
        "attrs" => $attrs_browse,
        "where" => CreateWhereFromList ("slice_id", $restrict_slices, "text"),
        "gotoview" => "email",
        "fields" => array (
            "slice_id" => array (
                "view" => array ("type" => "select", "source" => $slice_names),
                "default" => pack_id ($slice_id),
                "caption" => _m ("owner (slice)")),
            "description" => array (
				"caption" => _m("description"),
                "view" => array ("type"=>"text","size"=>array("cols"=>30)),
                "required" => true),
            "lang" => array (
                "view" => array ("type" => "select", "source" => $langs),
                "caption" => _m("language"),
                "default" => get_mgettext_lang()),
            "subject" => array ("caption"=>_m("subject"),"view"=>array("type"=>"text","size"=>array("cols"=>15))),
            "body" => array ("caption"=>_m("body"),"view"=>array("type"=>"text","size"=>array("cols"=>15))),
            "header_from" => array (
				"caption"=>"From:",
				"view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "reply_to" => array ("caption"=>"Reply-To:","view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "errors_to" => array ("caption"=>"Errors-To:","view" => array ("type"=>"text","size"=>array("cols"=>15))),
            "sender" => array ("caption"=>"Sender:","view" => array ("type"=>"text","size"=>array("cols"=>15))))
        );
    }
            
    if ($viewID == "email") {
        if (IsSuperadmin())
            $restrict_slices = 1;
        else {
            $restrict_slices = array ();
            $myslices = GetUsersSlices( $auth->auth["uid"] );
            reset ($myslices);
            while (list ($my_slice_id, $perms) = each ($myslices)) 
                if (strchr ($perms, PS_FULLTEXT))
                    $restrict_slices[] = pack_id($my_slice_id);
        }
        $db->query ("SELECT id, name FROM slice WHERE "
            .CreateWhereFromList ("id", $restrict_slices, "text"));
        while ($db->next_record()) 
            $slice_names[$db->f("id")] = $db->f("name");
        
        return  array (
        "table" => "email",
        "type" => "edit",
        "mainmenu" => "sliceadmin",
        "submenu" => "te_emails",
        "help" => _m("You may use aliases related to the usage of the email."),
        "readonly" => false,
        "addrecord" => true,
        "cond" => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT),
        "title" => _m("Email"),
        "caption" => _m("Email"),
        "attrs" => $attrs_edit,
        "where" => CreateWhereFromList ("slice_id", $restrict_slices, "text"),
        "gotoview" => "emails",
        "fields" => array (
            "slice_id" => array (
                "view" => array ("type" => "select", "source" => $slice_names),
                "default" => pack_id ($slice_id),
                "caption" => _m ("owner (slice)")),
            "description" => array (
				"caption" => _m("description"),
                "view" => array ("type"=>"text","size"=>array("cols"=>30)),
                "required" => true),
            "lang" => array (
                "view" => array ("type" => "select", "source" => $langs),
                "caption" => _m("language"),
                "default" => get_mgettext_lang()),
            "subject" => array("caption"=>_m("subject"),
                "view"=>array("type"=>"area","size"=>array("rows"=>2))),
            "body" => array("caption"=>_m("body"),
                "view"=>array("type"=>"area","size"=>array("rows"=>7))),
            "header_from" => array ("caption" => "From:",
                "view"=>array("type"=>"area","size"=>array("rows"=>2))),
            "reply_to" => array ("caption" => "Reply-To:",
                "view"=>array("type"=>"area","size"=>array("rows"=>2))),
            "errors_to" => array ("caption" => "Errors-To:",
                "view"=>array("type"=>"area","size"=>array("rows"=>2))),
            "sender" => array ("caption" => "Sender:",
                "view"=>array("type"=>"area","size"=>array("rows"=>2))))
        );
    }
} // end of GetTableView
            

?>

