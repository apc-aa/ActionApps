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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

// Settings for each table view (see doc/tabledit.html for more info)
/** GetTableView function
 * @param $viewID
 * @return array
 */
function GetTableView($viewID) {
    global $auth, $slice_id;

    $attrs_edit = [
        "table"=>"border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'"
    ];
    $attrs_browse = [
        "table"=>"border=1 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'",
        "table_search" => "border=0 cellpadding=3 cellspacing=0 bgcolor='".COLOR_TABBG."'"
    ];
    // $format = array (...

    /* ------------------------------------------------------------------------------------
       polls_design
    */
    if ($viewID == "polls_design") {
        return  [
        "table"     => "polls_design",
        "type"      => "browse",
        "mainmenu"  => "modadmin",
        "submenu"   => "design",
        "modulemenu"=> "modules/polls",
        "readonly"  => true,
        "addrecord" => false,
        "where"     => "(module_id='". q_pack_id($slice_id)."')",
        "cond"      => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_EDIT_DESIGN),
        "title"     => _m("Polls Design"),
        "caption"   => _m("Polls Design"),
        "attrs"     => $attrs_browse,
        "gotoview"  => "polls_design_edit",
        "fields"    => [
            "id"       => ["caption" => _m("Id")],
            "name"     => ["caption" => _m("Name")],
            "comment"  => ["caption" => _m("Comment")]
        ]
        ];
    }

    if ($viewID == "polls_design_edit") {
        $retval = GetTableView("polls_design");
        $retval["type"] = "edit";
        $retval["attrs"] = $attrs_edit;
        $retval["readonly"] = false;
        $retval["gotoview"] = "polls_design";
        $retval["addrecord"] = true;
        $retval["fields"] = [
            "id"        => [
                "caption" => _m("Id"),
                                  "default" => new_id(),
                                        "view" => [
                                            "type"=>"text",
                                                         "readonly" => true
                                        ]
            ],
            "module_id" => [
                "caption"  => _m("Module Id"),
                                  "default"  => pack_id($GLOBALS["slice_id"]),
                                      "view" => [
                                          "type"=>"hide",
                                                       "unpacked" => true,
                                                       "readonly" => true
                                      ]
            ],
            "name"            => [
                "caption" => _m("Name"),
                                        "view" => ["type"=>"text"],
                                        "required" => true
            ],
            "comment"         => [
                "caption" => _m("Comment"),
                                        "view" => ["type"=>"text"],
                                         "hint" => _m("design description (for administrators only)")
            ],
            "top"             => [
                "caption" => _m("Top HTML"),
                                        "view" => ["type"=>"area"]
            ],
            "answer"          => [
                "caption" => _m("Answer HTML"),
                                        "view" => ["type"=>"area"]
            ],
            "bottom"          => [
                "caption" => _m("Bottom HTML"),
                                        "view" => ["type"=>"area"]
            ]
        ];
        return $retval;
    }
} // end of GetTableView

