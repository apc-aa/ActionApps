<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Jakub Adamek
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tableview.html

require_once AA_INC_PATH."tv_email.php3";

// Settings for miscellaneous table views (see doc/tabledit.html for more info)
/** GetMiscTableView function
 *  see class tabledit :: var $getTableViewsFn for an explanation of the parameters
 * @param $viewID
 * @param $processForm
 */
function GetMiscTableView($viewID) {
    global $slice_id;
    global $attrs_edit, $attrs_browse, $format, $langs;

    $p_slice_id = q_pack_id($slice_id);

    if ($viewID == "email_edit") {
        $tableview = GetEmailTableView($viewID);
        $tableview["mainmenu"] = "sliceadmin";
        return $tableview;
    }

    if ($viewID == "email") {
        $tableview = GetEmailTableView($viewID);
        $tableview["mainmenu"] = "sliceadmin";
        $tableview["submenu"] = "te_emails";
        return $tableview;
    }


    /* ------------------------------------------------------------------------------------
       wt -- browse wizard templates
    */
    if ($viewID == "wt") {
        return  array (
        "table" => "wizard_template",
        "type" => "browse",
        "readonly" => false,
        "cond" => IsSuperadmin(),
        "title" => _m("Wizard Templates"),
        "caption" => _m("Wizard Templates"),
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
        "attrs" => $attrs_browse);
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
        "cond" => IsSuperadmin(),
        "title" => _m ("Cron"),
        "caption" => _m("Cron"),
        "attrs" => $attrs_browse,
        "fields" => array (
            "minutes" => array ("default"=>"*","view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "hours" => array ("default"=>"*","view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "mday" => array ("default"=>"*","view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "mon" => array ("default"=>"*","view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "wday" => array ("default"=>"*","view" => array ("type" => "text", "size" => array ("cols"=>2))),
            "script" => array ("view" => array ("type" => "text", "size" => array ("cols"=>25)),
                "required" => true),
            "params" => array ("view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "last_run" => array ("view" => array ("readonly" => true, "type" => "date", "format" => "j.n.Y G:i"))
        ));
    }
    /* ------------------------------------------------------------------------------------
       log
    */
    if ($viewID == "log") {
        return  array (
        "table"     => "log",
        "type"      => "browse",
        "mainmenu"  => "aaadmin",
        "help"      => _m("COUNT_HIT events will be used for counting item hits. After a while it will be automaticaly deleted."),
        "submenu"   => "te_log",
        "readonly"  => false,
        "addrecord" => false,
        "orderby"   => 'time',
        "orderdir"  => 'd',
        "listlen"   => 100,
        "cond"      => IsSuperadmin(),
        "title"     => _m("Log view"),
        "caption"   => _m("Log view"),
        "attrs"     => $attrs_browse,
        "fields"    => array (
            'time'     => array ("view" => array ("type" => "date", "readonly" => true, "format" => "j.n.Y_G:i")),
            'type'     => array ("view" => array ("type" => "text", "readonly" => true, "size" => array ("cols"=>10))),
            'selector' => array ("view" => array ("type" => "text", "readonly" => true, "size" => array ("cols"=>10))),
            'params'   => array ("view" => array ("type" => "text", "readonly" => true, "size" => array ("cols"=>20))),
            'user'     => array ("view" => array ("type" => "text", "readonly" => true, "size" => array ("cols"=>10))),
            'id'       => array ("view" => array ("type" => "text", "readonly" => true, "size" => array ("cols"=>10)))
            ),
        "buttons_down" => array ("delete_all"=>1)
        );
    }
    /* ------------------------------------------------------------------------------------
       searchlog
    */
    if ($viewID == "searchlog") {
        $url = 'http://actionapps.org/faq/detail.shtml?x=1767';
        return  array (
        "table"     => "searchlog",
        "type"      => "browse",
        "mainmenu"  => "aaadmin",
        "help"      => _m("See searchlog=1 parameter for slice.php3 in FAQ: ")."<a target=\"_blank\" href=\"$url\">$url</a>",
        "submenu"   => "te_searchlog",
        "readonly"  => false,
        "addrecord" => false,
        "orderby"   => 'date',
        "orderdir"  => 'd',
        "listlen"   => 50,
        "cond"      => IsSuperadmin(),
        "title"     => _m("SearchLog view"),
        "caption"   => _m("SearchLog view"),
        "attrs"     => $attrs_browse,
        "fields"    => array (
            'date'        => array ("view" => array ("type" => "date", "readonly" => true, "format" => "j.n.Y_G:i")),
            'found_count' => array ("view" => array ("type" => "text", "readonly" => true), 'caption' => _m('items found')),
            'search_time' => array ("view" => array ("type" => "text", "readonly" => true), 'caption' => _m('search time')),
            'additional1' => array ("view" => array ("type" => "text", "readonly" => true), 'caption' => _m('addition')),
            'query'       => array ("view" => array ("type" => "text", "readonly" => true)),
            'user'        => array ("view" => array ("type" => "text", "readonly" => true)),
            'id'          => array ("view" => array ("type" => "text", "readonly" => true))
            ),
        "buttons_down" => array ("delete_all"=>1)
        );
    }

    /* ------------------------------------------------------------------------------------
       fields
    */
    if ($viewID == "fields") {
        return  array (
        "table" => "field",
        "type" => "browse",
        "mainmenu" => "aaadmin",
        "submenu" => "fields",
        "readonly" => false,
        "addrecord" => true,
        "cond" => IsSuperadmin(),
        "title" => _m ("Configure Fields"),
        "caption" => _m("Configure Fields"),
        "attrs" => $attrs_browse,
        "where" => "slice_id='$p_slice_id'",
        "primary" => array ('slice_id', 'id'),
        "fields" => array (
            "id"              => array (                                         "view" => array ("type" => "text", "readonly" => true)),
            "name"            => array ("required"=>true,  "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "input_pri"       => array ("required"=>true,  "validate"=>'number', "view" => array ("type" => "text", "size" => array ("cols"=>5))),
            "input_help"      => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "input_morehlp"   => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "input_default"   => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>12))),
            "required"        => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "checkbox")),
            "feed"            => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "select", "source" => inputFeedModes())),
            "multiple"        => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "checkbox")),
            "input_show_func" => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>30))),
            "alias1"          => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>10))),
            "alias1_func"     => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "alias1_help"     => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "alias2"          => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>10))),
            "alias2_func"     => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "alias2_help"     => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "alias3"          => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>10))),
            "alias3_func"     => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "alias3_help"     => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "input_before"    => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>20))),
            "html_default"    => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "checkbox")),
            "html_show"       => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "checkbox")),
            "in_item_tbl"     => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "checkbox")),
            "input_validate"  => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>6))),
            "input_insert_func"=>array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "text", "size" => array ("cols"=>6))),
            "input_show"      => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "checkbox")),
            "text_stored"     => array ("required"=>false, "validate"=>'text',   "view" => array ("type" => "checkbox"))
        ));
    }


} // end of GetTableView
?>
