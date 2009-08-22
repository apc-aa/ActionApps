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

// $slice_id - should be defined
// $r_slice_view_url - should be defined
// $editor_page or $usermng_page or $settings_page - should be defined
// $g_modules - should be defined

/*  Top level (navigation bar) menu description:
    label       to be shown
    cond        if not satisfied, don't show the label linked
                slice_id is included in the cond automatically
    href        link, relative to aa/
    exact_href  link, absolute (use either exact_href or href, not both)
*/

require_once AA_INC_PATH."menu_util.php3";
require_once AA_INC_PATH."perm_core.php3";
require_once AA_INC_PATH."mgettext.php3";

// I don't want to call AA menus as early as including menu.php3, because some permissions' functions are called. Hence I call get_aamenus in showMenu().
$GLOBALS['aamenus']       = "aamenus";
$GLOBALS['menu_function'] = 'get_aamenus_sites';

function get_aamenus_sites() {
    global $r_slice_view_url, $auth;

    $aamenus["view"] = array (
        "label" => _m("View site"),
        "exact_href"  => $r_slice_view_url,
        "cond"  => 1,
        "level" => "main");

    $aamenus["codemanager"] = array (
        "label" => _m("Code&nbsp Manager"),
        "title" => _m("Code&nbsp Manager"),
        "href"  => "modules/site/index.php3" . ($r_slot_id ? "?r_slot_id=$r_slot_id" : ""),
        "level" => "main");

    $aamenus["modadmin"] = array (
        "label" => _m("Module Settings"),
        "title" => _m("Module Settings"),
        "href"  => "modules/site/modedit.php3" . ($r_slot_id ? "?r_slot_id=$r_slot_id" : ""),
        "cond"  => IfSlPerm(PS_MODW_SETTINGS),
        "level" => "main");

    $aamenus["aaadmin"] = array (
        "label" => _m("AA"),
        "title" => _m("AA Administration"),
        "href"  => "admin/aafinder.php3",
        "cond"  => IfSlPerm(PS_NEW_USER),
        "level" => "main",
        "submenu"=>"aaadmin_submenu");

    /*  Second-level (left) menu description:
        bottom_td       empty space under the menu
        items           array of menu items in form item_id => properties
                        if item_id is "headerxxx", shows a header,
                            be careful that xxx be always a different number
                        if item_id is "line", shows a line
            label       to be shown
            cond        if not satisfied, don't show the label linked
                        slice_id is included in the cond automatically
            href        link, relative to aa/
            exact_href  link, absolute (use either exact_href or href, not both)
            show_always don't include slice_id in cond
    */

    // left menu for aaadmin is common to all modules, so it is shared
    require_once AA_INC_PATH."menu_aa.php3";
    return $aamenus;
}
?>
