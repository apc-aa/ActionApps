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
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

// Settings for each table view (see doc/tabledit.html for more info)
/** GetTableView function
 * @param $viewID
 */
function GetTableView($viewID) {
    global $auth, $slice_id;

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
       polls_design
    */
    if ($viewID == "polls_design") {
        return  array (
        "table"     => "polls_design",
        "type"      => "browse",
        "mainmenu"  => "modadmin",
        "submenu"   => "design",
        "readonly"  => true,
        "addrecord" => false,
        "where"     => "(module_id='". q_pack_id($slice_id)."')",
        "cond"      => CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_MODP_EDIT_DESIGN),
        "title"     => _m("Polls Design"),
        "caption"   => _m("Polls Design"),
        "attrs"     => $attrs_browse,
        "gotoview"  => "polls_design_edit",
        "fields"    => array (
            "id"       => array ("caption" => _m("Id")),
            "name"     => array ("caption" => _m("Name")),
            "comment"  => array ("caption" => _m("Comment"))
        ));
    }

    if ($viewID == "polls_design_edit") {
        $retval = GetTableView("polls_design");
        $retval["type"] = "edit";
        $retval["attrs"] = $attrs_edit;
        $retval["readonly"] = false;
        $retval["gotoview"] = "polls_design";
        $retval["addrecord"] = true;
        $retval["fields"] = array (
            "id"        => array ("caption" => _m("Id"),
                                  "default" => new_id(),
                                        "view" => array( "type"=>"text",
                                                         "readonly" => true )),
            "module_id" => array ("caption"  => _m("Module Id"),
                                  "default"  => pack_id($GLOBALS["slice_id"]),
                                      "view" => array( "type"=>"hide",
                                                       "unpacked" => true,
                                                       "readonly" => true )),
            "name"            => array ("caption" => _m("Name"),
                                        "view" => array( "type"=>"text" ),
                                        "required" => true ),
            "comment"         => array ("caption" => _m("Comment"),
                                        "view" => array( "type"=>"text" ),
                                         "hint" => _m("design description (for administrators only)")),
            "top"             => array ("caption" => _m("Top HTML"),
                                        "view" => array( "type"=>"area" )),
            "answer"          => array ("caption" => _m("Answer HTML"),
                                        "view" => array( "type"=>"area" )),
            "bottom"          => array ("caption" => _m("Bottom HTML"),
                                        "view" => array( "type"=>"area" ))
            );
        return $retval;
    }
} // end of GetTableView

// ----------------------------------------------------------------------------------

/** te_au_confirm function
 *  user function for confirmed
 * @param $val
 */
function te_au_confirm($val) {
    return $val ? _m("no") : _m("yes");
}
?>

