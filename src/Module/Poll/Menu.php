<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 1.11.18
 * Time: 18:05
 */

namespace AA\Module\Poll;


class Menu
{
    static public function get_menu() {
        global $r_slice_view_url, $r_state, $auth, $polledit, $slice_id;

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
            "href"  => $module_location."polledit.php3",
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
            "href"  => $module_location."modedit.php3",
            "cond"  => IfSlPerm(PS_MODP_SETTINGS),
            "level" => "main",
            "submenu"=>"modadmin_submenu"
        ];

        $aamenus["pollsmanager_submenu"] = [
            "bottom_td" => 200,
            "level"     => "submenu",
            "items"     => [
                "header1"     => _m("Folders"),
                "app"       => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder1a=1",                         "label"=>"<img src='../../images/ok.gif' border=0>"._m("Active")." (". $r_state['bin_cnt']['app'] .")"],
                "appb"      => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder1b=1",                         "label"=>_m("... pending")." (". $r_state['bin_cnt']['appb'] .")"],
                "appc"      => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder1c=1",                         "label"=>_m("... expired")." (". $r_state['bin_cnt']['appc'] .")"],
                "folder2"   => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder2=1",                          "label"=>"<img src='../../images/edit.gif' border=0>"._m("Hold bin")." (". $r_state['bin_cnt']['folder2'] .")"],
                "folder3"   => ["cond"=>1,                           "href"=>"modules/polls/index.php3?Folder3=1",                          "label"=>"<img src='../../images/delete.gif' border=0>"._m("Trash bin")." (". $r_state['bin_cnt']['folder3'] .")"],

                "header2"     => _m("Misc"),
                "deletetrash" => ["cond"=>IfSlPerm(PS_DELETE_ITEMS),   "href"=>"modules/polls/index.php3?DeleteTrash=1",                     "label"=>"<img src='../../images/empty_trash.gif' border=0>"._m("Empty trash"), "js"=>"EmptyTrashQuestion('{href}','"._m("Are You sure to empty trash?")."')"],
                "line"        => ""
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
                "main"=> ["cond"=>IfSlPerm(PS_MODP_SETTINGS), "href"=>"modules/polls/modedit.php3", "label"=>_m("Polls")],
                "design"=> ["cond"=>IfSlPerm(PS_MODP_EDIT_DESIGN), "href"=>"admin/tabledit.php3?set_tview=polls_design", "label"=>_m("Designs")],
            ]
        ];

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

        $profile = AA_Profile::getProfile($auth->auth["uid"], $slice_id); // current user settings

        // left menu for aaadmin is common to all modules, so it is shared
        return array_merge($aamenus, GetCommonMenu($profile));
    }
}