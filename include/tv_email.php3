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

require_once $GLOBALS["AA_BASE_PATH"]."modules/alerts/util.php3";

function ShowEmailAliases () {    
    $ali[] = array (
        "group" => _m("Aliases for Alerts Alert"), 
        "aliases" => array (
            "_#FILTERS_" => _m("complete filter text"),
            "_#HOWOFTEN" => _m("howoften")." (".join(", ",get_howoften_options()).")",
            "_#COLLFORM" => _m("Collection Form URL (set in Alerts Admin - Settings)"),
        ));
        
    $ali[] = array (
        "group" => _m("Aliases for Alerts Welcome"), 
        "aliases" => array (
            "_#HOWOFTEN" => _m("howoften")." (".join(", ",get_howoften_options()).")",
            "_#COLLFORM" => _m("Collection Form URL (set in Alerts Admin - Settings)"),
        ));

    echo "<TABLE border=0 cellspacing=0 cellpadding=0>";
    reset ($ali);
    while (list (, $aligroup) = each ($ali)) {
        echo "<TR><TD class=tabtit colspan=2><B>&nbsp;$aligroup[group]&nbsp;</B></TD></TR>";
        reset ($aligroup["aliases"]);
        while (list ($alias, $desc) = each ($aligroup["aliases"])) 
            echo "<TR><TD class=tabtxt>&nbsp;$alias&nbsp;</TD>
                <TD class=tabtxt>&nbsp;$desc&nbsp;</TD></TR>";
    }
    echo "</TABLE>";
}
    
// Settings for emails table views 
/** see class tabledit :: var $getTableViewsFn for an explanation of the parameters */                        
function GetEmailTableView ($viewID, $processForm = false)
{
    global $auth, $slice_id, $db;
    global $attrs_edit, $attrs_browse, $format, $langs;

    if ($viewID == "email_edit") {
        global $LANGUAGE_CHARSETS, $LANGUAGE_NAMES;
        reset ($LANGUAGE_CHARSETS);
        while (list ($l,$charset) = each ($LANGUAGE_CHARSETS))
            $mylangs[$l] = $LANGUAGE_NAMES[$l]." (".$charset.")";
        return  array (
        "table" => "email",
        "type" => "edit",
        //"help" => _m("For help see FAQ: ")."<a target=\"_blank\" href=\"$url\">$url</a>",
        //"buttons_down" => array ("add"=>1, "update"=>1),
        "readonly" => false,
        "attrs" => $attrs_edit,
        "caption" => _m("Email"),
        "addrecord" => false,
        "gotoview" => "email",
        "where" => GetEmailWhere(),        
        "cond" => 1,
        "fields" => array (
            "id" => array ("view" => array ("readonly" => true)),
            "description" => array (
                "required" => true,
                "caption" => _m("description")),
            "type" => array (
                "required" => true,
                "caption" => _m("email type"),
                "view" => array ("type"=>"select","source"=>get_email_types())),
            "subject" => array (
                "required" => true,
                "caption" => _m("subject")),
            "body" => array (
                "required" => true,
                "caption" => _m("body"),
                "view" => array ("type" => "area", "size" => array ("rows"=>8))),
            "header_from" => array (
                "required" => true,
                "caption" => _m("from")),
            "reply_to" => array (
                "caption" => _m("reply to")),
            "errors_to" => array (
                "caption" => _m("errors to")),
            "sender" => array (
                "caption" => _m("sender")),
            "lang" => array (
                "caption" => _m("language (charset)"),
                "default" => get_mgettext_lang(),
                "view" => array ("type" => "select", "source" => $mylangs)),
            "html" => array (
                "caption" => _m("use HTML"),
                "default" => 1,
                "view" => array ("type" => "checkbox")),
            "owner_module_id" => array (
                "caption" => _m("owner"),
                "default" => pack_id128($GLOBALS["slice_id"]),
                "view" => array ("type"=>"select","source"=>SelectModule(),"unpacked"=>true),
            )
        ));
    }

    // ------------------------------------------------------------------------------------
    // email: this view browses emails, it is currently used in Alerts module
    //        but may be added anywhere else
    
    if ($viewID == "email") {
        global $LANGUAGE_CHARSETS, $LANGUAGE_NAMES;
        reset ($LANGUAGE_CHARSETS);
        while (list ($l,$charset) = each ($LANGUAGE_CHARSETS))
            $mylangs[$l] = $LANGUAGE_NAMES[$l]." (".$charset.")";
        return  array (
        "table" => "email",
        "type" => "browse",
        //"help" => _m("For help see FAQ: ")."<a target=\"_blank\" href=\"$url\">$url</a>",
        //"buttons_down" => array ("add"=>1, "update"=>1),
        "readonly" => true,
        "attrs" => $attrs_browse,
        "caption" => _m("Email"),
        "buttons_down" => array ("add"=>1,"delete_all"=>1),
        "buttons_left" => array ("delete_checkbox"=>1,"edit"=>1),
        "gotoview" => "email_edit",
        "cond" => 1,
        "where" => GetEmailWhere(),        
        "fields" => array (
            "description" => array (
                "caption" => _m("description")),
            "subject" => array (
                "caption" => _m("subject"), "view"=>array("maxlen"=>50)),
            "body" => array (
                "caption" => _m("body"),
                "view" => array (
                    "maxlen" => 100,
                    "type" => "text", 
                    "size" => array ("rows"=>8))),
            "header_from" => array (
                "caption" => _m("from")),
            "reply_to" => array (
                "caption" => _m("reply to")),
            "errors_to" => array (
                "caption" => _m("errors to")),
            "sender" => array (
                "caption" => _m("sender"))
        ));
    }
}            

function GetEmailWhere () {
	global $auth, $db;
    if (IsSuperadmin ()) 
        return 1;
    else {
        $myslices = GetUsersSlices( $auth->auth["uid"] );    
        if (is_array ($myslices)) {
            reset ($myslices);
            while (list ($my_slice_id, $perms) = each ($myslices)) 
                if (strchr ($perms, PS_FULLTEXT))
                    $restrict_slices[] = q_pack_id($my_slice_id);
            return "owner_module_id IN ('".join("','",$restrict_slices)."')";
        }
        else return 0;
    }
    return $retval;
}
   
?>
