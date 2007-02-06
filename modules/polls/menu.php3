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
bind_mgettext_domain(AA_INC_PATH."lang/".substr(LANG_FILE,0,2)."_news_lang.php3");

// I don't want to call AA menus as early as including menu.php3, because some permissions' functions are called. Hence I call get_aamenus in showMenu().
$aamenus = "aamenus";

function get_aamenus()
{
    global $r_slice_view_url,
           $auth,
           $AA_CP_Session,
           $polledit,
           $item_bin_cnt,
           $item_bin_cnt_exp;

    $module_location = "modules/polls/";


    $aamenus["view"] = array (
        "label" => _m("View poll"),
        "exact_href"  => $r_slice_view_url,
        "cond"  => 1,
        "level" => "main");

    $aamenus["addpoll"] = array (
        "label" => ($polledit ? _m("Edit poll") : _m("Add poll")),
        "title" => ($polledit ? _m("Edit poll") : _m("Add new poll")),
        "href"  => $module_location."addpoll.php3",
        "level" => "main",
        "submenu" => "addpoll_submenu");

    $aamenus["pollsmanager"] = array (
        "label" => _m("Polls manager"),
        "title" => _m("Polls manager"),
        "href"  => $module_location."index.php3?Tab=app",
        "level" => "main",
        "submenu" => "pollsmanager_submenu");

    $aamenus["modadmin"] = array (
        "label" => _m("Polls admin"),
        "title" => _m("Polls admin"),
        "href"  => $module_location."modedit.php3",
        "cond"  => IfSlPerm(PS_MODP_SETTINGS),
        "level" => "main",
        "submenu"=>"modadmin_submenu");

    $aamenus["aaadmin"] = array (
        "label" => _m("AA"),
        "title" => _m("AA"),
        "href"  => "admin/um_uedit.php3",
        "cond"  => IsSuperadmin(),
        "level" => "main",
        "submenu"=>"aaadmin_submenu");

    $aamenus["pollsmanager_submenu"] = array(
        "bottom_td"=>200,
        "level"=>"submenu",
        "items"=> array(
        "header1"=>_m("Folders"),
        "app"=>array ("cond"=> $r_bin_state != "app",
                        "href"=>"modules/polls/index.php3?Tab=app",
                        "label"=>"<img src='../../images/ok.gif' border=0>"._m("Active")." (".($item_bin_cnt[1]-$item_bin_cnt_exp).")"),
        "appc"=>array ("show"=>!$apple_design,
                        "cond" => $r_bin_state != "appc",
                        "href"=>"modules/polls/index.php3?Tab=appc",
                        "label"=>_m("... expired")." (".$item_bin_cnt_exp.")"),
        "hold"=>array ("cond"=> $r_bin_state != "hold",
                        "href"=>"modules/polls/index.php3?Tab=hold",
                        "label"=>"<img src='../../images/edit.gif' border=0>"._m("Hold bin")." ($item_bin_cnt[2])"),
        "trash"=>array ("cond"=> $r_bin_state != "trash",
                        "href"=>"modules/polls/index.php3?Tab=trash",
                        "label"=>"<img src='../../images/delete.gif' border=0>"._m("Trash bin")." ($item_bin_cnt[3])"),
        "header2" => _m("Misc"),
        "item6"=>array ("cond"=>IfSlPerm(PS_MODP_DELETE_POLLS),
                        "href"=>"modules/polls/index.php3?Delete=trash",
                        "label"=>"<img src='../../images/empty_trash.gif' border=0>"._m("Empty trash")),
        "line" => "",
    ));

    $aamenus["addpoll_submenu"] = array (
        "bottom_td"=>200,
        "level"=>"submenu",
        "items" => array(),);

    $aamenus["modadmin_submenu"] = array(
        "bottom_td"=>200,
        "level"=>"submenu",
        "items"=> array(
        "header1"=>_m("Main settings"),
        "main"=>array ("cond"=>IfSlPerm(PS_MODP_SETTINGS), "href"=>"modules/polls/modedit.php3", "label"=>_m("Polls")),
        "design"=>array ("cond"=>IfSlPerm(PS_MODP_EDIT_DESIGN), "href"=>"admin/tabledit.php3?set_tview=polls_designs", "label"=>_m("Designs")),
    ));

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
