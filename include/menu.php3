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

# $r_slice_headline - should be defined
# $slice_id - should be defined
# $r_slice_view_url - should be defined
# $editor_page or $usermng_page or $settings_page - should be defined
# $g_modules - should be defined

/*  Top level (navigation bar) menu description:
    label       to be shown
    cond        if not satisfied, don't show the label linked
                slice_id is included in the cond automatically
    href        link, relative to aa/
    exact_href  link, absolute (use either exact_href or href, not both)
*/

if (!defined("AA_MENU_INCLUDED"))
      define("AA_MENU_INCLUDED",1);
else return;

require $GLOBALS[AA_INC_PATH]."menu_util.php3";
require $GLOBALS[AA_INC_PATH]."perm_core.php3";
require $GLOBALS[AA_INC_PATH]."mgettext.php3";

// I don't want to call AA menus as early as including menu.php3, because some permissions' functions are called. Hence I call get_aamenus in showMenu().
$aamenus = "aamenus";

function get_aamenus ()
{
    global $r_slice_view_url,
           $auth,
           $slice_id,
           $r_bin_state,
           $item_bin_cnt,
           $item_bin_cnt_exp,
           $item_bin_cnt_pend,
           $apple_design,
           $AA_INSTAL_PATH,
           $AA_CP_Session;

    $aamenus["view"] = array (
        "label" => _m("View site"),
        "exact_href"  => $r_slice_view_url,
        "cond"  => 1,
        "level" => "main");

    $aamenus["additem"] = array (
        "label" => _m("Add Item"),
        "href"  => "admin/itemedit.php3?encap=false&add=1",
        "level" => "main");

    $aamenus["itemmanager"] = array (
        "label" => _m("Item Manager"),
        "title" => _m("Item Manager"),
        "href"  => "admin/index.php3?Tab=app",
        "level" => "main",
        "submenu" => "itemmanager_submenu");

    $aamenus["sliceadmin"] = array (
        "label" => _m("Slice Admin"),
        "title" => _m("Slice Administration"),
        "href"  => "admin/slicedit.php3",
        "cond"  => IfSlPerm(PS_EDIT),
        "level" => "main",
        "submenu"=>"sliceadmin_submenu");

    $aamenus["aaadmin"] = array (
        "label" => _m("AA"),
        "title" => _m("AA Administration"),
        "href"  => "admin/um_uedit.php3",
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

    $aamenus ["sliceadmin_submenu"] = array (
        "bottom_td"=>50,
        "level"=>"submenu",

        "items"=>array (

        "header1" => _m("Main settings"),
        "main" => array ("label"=>_m("Slice"), "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT), "href"=>"admin/slicedit.php3?slice_id=$slice_id", "show_always"=>1),
        "category" => array("label"=>_m("Category"), "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY), "href"=>"admin/se_constant.php3?category=1"),
        "fields" => array ("label"=>_m("Fields"), "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"admin/se_fields.php3?slice_id=$slice_id"),
        "notify" => array ("label"=>_m("Email Notification"), "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT), "href"=>"admin/se_notify.php3?slice_id=$slice_id"),
        //"te_emails" => array ("label"=>_m("Emails"), "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/tabledit.php3?set_tview=email&slice_id=$slice_id"), 

        "header2" => _m("Permissions"),
        "addusers"=> array ("label"=>_m("Assign"), "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ADD_USER), "href"=>"admin/se_users.php3?adduser=1&slice_id=$slice_id"),
        "users"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_USERS), "href"=>"admin/se_users.php3?slice_id=$slice_id", "label"=>_m("Change")),

        "header3" => _m("Design"),
        "compact"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_COMPACT), "href"=>"admin/se_compact.php3?slice_id=$slice_id", "label"=>_m("Index")),
        "fulltext"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/se_fulltext.php3?slice_id=$slice_id", "label"=>_m("Fulltext")),
        "views"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/se_views.php3?slice_id=$slice_id", "label"=>_m("Views")),
        "config"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CONFIG), "href"=>"admin/se_admin.php3?slice_id=$slice_id", "label"=>_m("Item Manager")),

        "header4" => _m("Content Pooling"),
        "nodes"=>array("cond"=>isSuperadmin(), "href"=>"admin/se_nodes.php3?slice_id=$slice_id", "label"=>_m("Nodes")),
        "import"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_import.php3?slice_id=$slice_id", "label"=>_m("Inner Node Feeding")),
        "n_import"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_inter_import.php3?slice_id=$slice_id", "label"=>_m("Inter Node Import")),
        "n_export"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_inter_export.php3?slice_id=$slice_id", "label"=>_m("Inter Node Export")),
        "rssfeeds"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_rssfeeds.php3?slice_id=$slice_id", "label"=>_m("RSS Feeds")),
        "filters"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_filters.php3?slice_id=$slice_id", "label"=>_m("Filters")),
        "mapping"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_mapping.php3?slice_id=$slice_id", "label"=>_m("Mapping")),
/*
        "header6"=>_m("Alerts"),
        "te_alerts_admin" => array("cond"=>IsSuperadmin(), "href" => "admin/tabledit.php3?set_tview=alerts_admin", "label" => _m("Admin")),
        "te_alerts_collections"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/tabledit.php3?set_tview=ac", "label"=>_m("Collections")),
        "te_alerts_users"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/tabledit.php3?set_tview=au", "label"=>_m("Users")),
        "alerts_ui"=>array("cond"=>1, "exact_href"=>$AA_INSTAL_PATH."misc/alerts/index.php3?lang=".get_mgettext_lang(), "label"=>_m("User Interface")),
*/
        "header5" => _m("Misc"),
        "field_ids" => array ("label"=>_m("Change field IDs"), "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"admin/se_fieldid.php3?slice_id=$slice_id"),
        "javascript" => array ("label"=>_m("Field Triggers"), "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"admin/se_javascript.php3")
        ,"fileman" => array ("label"=>_m("File Manager"), "cond"=>FilemanPerms ($auth, $slice_id), "href"=>"admin/fileman.php3")
    ));

    $aamenus["itemmanager_submenu"] = array(
        "bottom_td"=>200,
        "level"=>"submenu",
        "items"=> array(
        "header1"=>_m("Folders"),
        "app"=>array ("cond"=> 1, "href"=>"admin/index.php3?Tab=app", 
            "label"=>"<img src='../images/ok.gif' border=0>"._m("Active")." (".($item_bin_cnt[1]-$item_bin_cnt_exp-$item_bin_cnt_pend).")"),
        "appb"=>array ("show"=>!$apple_design, "cond" => 1, "href"=>"admin/index.php3?Tab=appb", 
            "label"=>_m("... pending")." ($item_bin_cnt_pend)"),
        "appc"=>array ("show"=>!$apple_design, "cond" => 1, "href"=>"admin/index.php3?Tab=appc", 
            "label"=>_m("... expired")." ($item_bin_cnt_exp)"),
        "hold"=>array ("cond"=> 1, "href"=>"admin/index.php3?Tab=hold", 
            "label"=>"<img src='../images/edit.gif' border=0>"._m("Hold bin")." ($item_bin_cnt[2])"),
        "trash"=>array ("cond"=> 1, "href"=>"admin/index.php3?Tab=trash", 
            "label"=>"<img src='../images/delete.gif' border=0>"._m("Trash bin")." ($item_bin_cnt[3])"),
        "header2" => _m("Misc"),
        "item6"=>array ("cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"admin/index.php3?Delete=trash", 
            "label"=>"<img src='../images/empty_trash.gif' border=0>"._m("Empty trash")),
        "line" => "",
    ));

    // left menu for aaadmin is common to all modules, so it is shared 
    require $GLOBALS[AA_INC_PATH]."menu_aa.php3";
    return $aamenus;
}
?>
