<?php
/**
* Polls module is based on Till Gerken's phpPolls version 1.0.3. Thanks!
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
* @author    Pavel Jisl <pavel@cetoraz.info>, Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      https://www.apc.org/ APC
*
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

require_once __DIR__ . "/../../include/menu_util.php";
require_once __DIR__ . "/../../include/perm_core.php";
require_once __DIR__ . "/../../include/mgettext.php";
//mgettext_bind(substr(LANG_FILE,0,2), 'news');

// I don't want to call AA menus as early as including menu.php, because some permissions' functions are called. Hence I call get_aamenus in showMenu().

function get_aamenus() {
    global $r_slice_view_url, $r_state, $polledit, $slice_id;

    $module_location = "modules/polls/";


    $aamenus["view"] = [
        "label" => _m("View poll"),
        "exact_href"  => $r_slice_view_url,
        "cond"  => 1,
        "level" => "main"
    ];

    $aamenus["polledit"] = [
        "label" => ($polledit ? _m("Edit poll") : _m("Add poll")),
        "title" => ($polledit ? _m("Edit poll") : _m("Add new poll")),
        "href"  => $module_location."polledit.php",
        "level" => "main",
        "submenu" => "polledit_submenu"
    ];

    $aamenus["pollsmanager"] = [
        "label" => _m("Polls manager"),
        "title" => _m("Polls manager"),
        "href"  => $module_location."index.php3?Tab=app",
        "level" => "main",
        "submenu" => "pollsmanager_submenu"
    ];

    $aamenus["modadmin"] = [
        "label" => _m("Polls admin"),
        "title" => _m("Polls admin"),
        "href"  => $module_location."modedit.php",
        "cond"  => IfSlPerm(PS_MODP_SETTINGS),
        "level" => "main",
        "submenu"=>"modadmin_submenu"
    ];

    $aamenus["pollsmanager_submenu"] = [
        "bottom_td" => 200,
        "level"     => "submenu",
        "items"     => [
            "header1"     => _m("Folders"),
            "app"       => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder1a=1",                         "label"=>"<img src='../../images/ico-items.png'>"._m("Active")." <span class='aa-bin-cnt'>". $r_state['bin_cnt']['app'] ."</span>"],
            "appb"      => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder1b=1",                         "label"=>"<img src='../../images/ico-items-pend.png'>"._m("... pending")." <span class='aa-bin-cnt'>". $r_state['bin_cnt']['appb'] ."</span>"],
            "appc"      => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder1c=1",                         "label"=>"<img src='../../images/ico-items-exp.png'>"._m("... expired")." <span class='aa-bin-cnt'>". $r_state['bin_cnt']['appc'] ."</span>"],
            "folder2"   => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder2=1",                          "label"=>"<img src='../../images/ico-holdbin.png'>"._m("Hold bin")." <span class='aa-bin-cnt'>". $r_state['bin_cnt']['folder2'] ."</span>"],
            "folder3"   => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder3=1",                          "label"=>"<img src='../../images/ico-trashbin.png'>"._m("Trash bin")." <span class='aa-bin-cnt'>". $r_state['bin_cnt']['folder3'] ."</span>"],

            "header2"     => _m("Misc"),
            "deletetrash" => ["cond"=>IfSlPerm(PS_DELETE_ITEMS),   "href"=>"modules/polls/index.php3?DeleteTrash=1",                     "label"=>_m("Empty trash"), "js"=>"EmptyTrashQuestion('{href}','"._m("Are You sure to empty trash?")."')"]
        ]
    ];

    $aamenus["polledit_submenu"] = [
        "bottom_td"=>200,
        "level"=>"submenu",
        "items" => [],
    ];

    $aamenus["modadmin_submenu"] = [
        "bottom_td"=>200,
        "level"=>"submenu",
        "items"=> [
        "header1"=>_m("Main settings"),
        "main"=> ["cond"=>IfSlPerm(PS_MODP_SETTINGS), "href"=>"modules/polls/modedit.php", "label"=>_m("Polls")],
        "design"=> ["cond"=>IfSlPerm(PS_MODP_EDIT_DESIGN), "href"=>"modules/polls/designs.php", "label"=>_m("Designs")],
        ]
    ];

    /*  Second-level (left) menu description:
        bottom_td       empty space under the menu
        items           array of menu items in form item_id => properties
                        if item_id is "headerxxx", shows a header,
                            be careful that xxx be always a different number
            label       to be shown
            cond        if not satisfied, don't show the label linked
                        slice_id is included in the cond automatically
            href        link, relative to aa/
            exact_href  link, absolute (use either exact_href or href, not both)
            show_always don't include slice_id in cond
    */

    /*  Second-level (left) menu description:
        bottom_td       empty space under the menu
        items           array of menu items in form item_id => properties
                        if item_id is "headerxxx", shows a header,
                            be careful that xxx be always a different number
            label       to be shown
            cond        if not satisfied, don't show the label linked
                        slice_id is included in the cond automatically
            href        link, relative to aa/
            exact_href  link, absolute (use either exact_href or href, not both)
            show_always don't include slice_id in cond
    */

    $profile = AA_Profile::getProfile(AuthGetUid(), $slice_id); // current user settings

    // left menu for aaadmin is common to all modules, so it is shared
    return array_merge($aamenus, GetCommonMenu($profile));
}

