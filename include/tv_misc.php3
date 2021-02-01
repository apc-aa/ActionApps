<?php
/**
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 *
*/
// (c) Econnect, Jakub Adamek, December 2002
// DOCUMENTATION: doc/tableview.html

require_once __DIR__."/tv_email.php3";

// Settings for miscellaneous table views (see doc/tabledit.html for more info)
/** GetMiscTableView function
 *  see class tabledit :: var $getTableViewsFn for an explanation of the parameters
 * @param $viewID
 * @param $processForm
 * @return array
 */
function GetMiscTableView($viewID) {
    global $slice_id;
//  global $attrs_edit, $attrs_browse, $format, $langs;
    global              $attrs_browse;

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
        return  [
        "table" => "wizard_template",
        "type" => "browse",
        "readonly" => false,
        "cond" => IsSuperadmin(),
        "title" => _m("Wizard Templates"),
        "caption" => _m("Wizard Templates"),
        "mainmenu" => "aaadmin",
        "submenu" => "te_wizard_template",
        "fields" => [
            "dir"=> [
                "view" => ["type" => "text", "size" => ["cols" => 10]],
                "validate" => "filename",
                "required" => true
            ],
            "description"=> [
                "view" => ["type" => "text", "size" => ["cols" => 40]],
                "required" => true
            ]
        ],
        "attrs" => $attrs_browse
        ];
    }

    /* ------------------------------------------------------------------------------------
       cron
    */
    if ($viewID == "cron") {
        $doc_url = "https://apc-aa.sourceforge.net/faq/#cron";
        $run_url = get_aa_url('cron.php3','',false);
        return  [
        "table" => "cron",
        "type" => "browse",
        "mainmenu" => "aaadmin",
        "submenu" => "te_cron",
        "help" => _m("For help see FAQ: ")."<a href=\"$doc_url\">$doc_url</a><br>".
                  _m("To run cron manualy now: ")."<a href=\"$run_url\">$run_url</a>",
        "readonly" => false,
        "addrecord" => true,
        "cond" => IsSuperadmin(),
        "title" => _m ("Cron"),
        "caption" => _m("Cron"),
        "attrs" => $attrs_browse,
        "fields" => [
            "minutes" => ["default"=>"*","view" => ["type" => "text", "size" => ["cols"=>2]]],
            "hours" => ["default"=>"*","view" => ["type" => "text", "size" => ["cols"=>2]]],
            "mday" => ["default"=>"*","view" => ["type" => "text", "size" => ["cols"=>2]]],
            "mon" => ["default"=>"*","view" => ["type" => "text", "size" => ["cols"=>2]]],
            "wday" => ["default"=>"*","view" => ["type" => "text", "size" => ["cols"=>2]]],
            "script" => [
                "view" => ["type" => "text", "size" => ["cols"=>25]],
                "required" => true
            ],
            "params" => ["view" => ["type" => "text", "size" => ["cols"=>20]]],
            "last_run" => ["view" => ["readonly" => true, "type" => "date", "format" => "j.n.Y G:i"]]
        ]
        ];
    }
    /* ------------------------------------------------------------------------------------
       log
    */
    if ($viewID == "log") {
        return  [
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
        "fields"    => [
            'time'     => ["view" => ["type" => "date", "readonly" => true, "format" => "j.n.Y_G:i"]],
            'type'     => ["view" => ["type" => "text", "readonly" => true, "size" => ["cols"=>10]]],
            'selector' => ["view" => ["type" => "text", "readonly" => true, "size" => ["cols"=>10]]],
            'params'   => ["view" => ["type" => "text", "readonly" => true, "size" => ["cols"=>20]]],
            'user'     => ["view" => ["type" => "text", "readonly" => true, "size" => ["cols"=>10]]],
            'id'       => ["view" => ["type" => "text", "readonly" => true, "size" => ["cols"=>10]]]
        ],
        "buttons_down" => ["delete_all"=>1]
        ];
    }
    /* ------------------------------------------------------------------------------------
       searchlog
    */
    if ($viewID == "searchlog") {
        $doc_url = 'https://actionapps.org/faq/detail.shtml?x=1767';
        return  [
        "table"     => "searchlog",
        "type"      => "browse",
        "mainmenu"  => "aaadmin",
        "help"      => _m("See searchlog=1 parameter for slice.php3 in FAQ: ")."<a target=\"_blank\" href=\"$doc_url\">$doc_url</a>",
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
        "fields"    => [
            'date'        => ["view" => ["type" => "date", "readonly" => true, "format" => "j.n.Y_G:i"]],
            'found_count' => ["view" => ["type" => "text", "readonly" => true], 'caption' => _m('items found')],
            'search_time' => ["view" => ["type" => "text", "readonly" => true], 'caption' => _m('search time')],
            'additional1' => ["view" => ["type" => "text", "readonly" => true], 'caption' => _m('addition')],
            'query'       => ["view" => ["type" => "text", "readonly" => true]],
            'user'        => ["view" => ["type" => "text", "readonly" => true]],
            'id'          => ["view" => ["type" => "text", "readonly" => true]]
        ],
        "buttons_down" => ["delete_all"=>1]
        ];
    }

    /* ------------------------------------------------------------------------------------
       fields
    */
    if ($viewID == "fields") {
        return  [
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
        "primary" => ['slice_id', 'id'],
        "fields" => [
            "id"              => ["view" => ["type" => "text", "readonly" => true]],
            "name"            => ["required"=>true,  "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "input_pri"       => ["required"=>true,  "validate"=>'number', "view" => ["type" => "text", "size" => ["cols"=>5]]],
            "input_help"      => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "input_morehlp"   => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "input_default"   => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>12]]],
            "required"        => ["required"=>false, "validate"=>'text',   "view" => ["type" => "checkbox"]],
            "feed"            => ["required"=>false, "validate"=>'text',   "view" => ["type" => "select", "source" => inputFeedModes()]],
            "multiple"        => ["required"=>false, "validate"=>'text',   "view" => ["type" => "checkbox"]],
            "input_show_func" => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>30]]],
            "alias1"          => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>10]]],
            "alias1_func"     => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "alias1_help"     => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "alias2"          => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>10]]],
            "alias2_func"     => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "alias2_help"     => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "alias3"          => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>10]]],
            "alias3_func"     => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "alias3_help"     => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "input_before"    => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>20]]],
            "html_default"    => ["required"=>false, "validate"=>'text',   "view" => ["type" => "checkbox"]],
            "html_show"       => ["required"=>false, "validate"=>'text',   "view" => ["type" => "checkbox"]],
            "in_item_tbl"     => ["required"=>false, "validate"=>'text',   "view" => ["type" => "checkbox"]],
            "input_validate"  => ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>6]]],
            "input_insert_func"=> ["required"=>false, "validate"=>'text',   "view" => ["type" => "text", "size" => ["cols"=>6]]],
            "input_show"      => ["required"=>false, "validate"=>'text',   "view" => ["type" => "checkbox"]],
            "text_stored"     => ["required"=>false, "validate"=>'text',   "view" => ["type" => "checkbox"]]
        ]
        ];
    }


} // end of GetTableView

