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

if (!defined("ALERTS_MENU_INCLUDED"))
      define("ALERTS_MENU_INCLUDED",1);
else return;

require $GLOBALS[AA_INC_PATH]."menu_util.php3";
require $GLOBALS[AA_INC_PATH]."perm_core.php3";
require $GLOBALS[AA_INC_PATH]."mgettext.php3";
require "util.php3";
bind_mgettext_domain ($GLOBALS[AA_INC_PATH]."lang/".substr(LANG_FILE,0,2)."_alerts_lang.inc");

// I don't want to call AA menus as early as including menu.php3, because some permissions' functions are called. Hence I call get_aamenus in showMenu().
$aamenus = "aamenus";

function get_aamenus ()
{
    global $r_slice_view_url,
           $auth,
           $AA_INSTAL_PATH,
           $AA_CP_Session;

    $collectionid = 1;

    $aamenus["addusers"] = array (
        "label" => _m("Add Users"),
        "title" => _m("Alerts - Add Users"),
        "href" => "modules/alerts/addusers.php3",
        "cond" => IfSlPerm (PS_USERS),
        "level" => "main");    
        
    $aamenus["usermanager"] = array (
        "label" => _m("User Manager"),
        "title" => _m("Alerts User Manager"),
        "href" => "modules/alerts/tabledit.php3?set_tview=au&setTab=app",
        "cond" => IfSlPerm (PS_USERS),
        "level" => "main",
        "submenu" => "usermanager_submenu");
        
    $aamenus["admin"] = array (    
        "label" => _m("Alerts Admin"),
        "title" => _m("Alerts Admin"),
        "href" => "modules/alerts/tabledit.php3?set_tview=modedit&cmd[modedit][edit]["
            .urlencode ($GLOBALS["slice_id"])."]=1", 
        "cond" => IfSlPerm (PS_USERS),
        "level" => "main",
        "submenu" => "admin_submenu");        
        
    $aamenus["aaadmin"] = array (
        "label" => L_AA_ADMIN2,
        "title" => L_AA_ADMIN,
        "href"  => "admin/um_uedit.php3",
        "cond"  => IfSlPerm(PS_NEW_USER),
        "level" => "main",
        "submenu"=>"aaadmin_submenu");
              
    // left menu for aaadmin is common to all modules, so it is shared
    require $GLOBALS[AA_INC_PATH]."menu_aa.php3";
        
    $aamenus["admin_submenu"] = array (
        "bottom_td"=>200,
        "level"=>"submenu",
        "items"=> array(
        "header1"=>_m("Alerts Admin"),
        "formwizard"=>array ("cond"=>IfSlPerm(PS_USERS), "href"=>"modules/alerts/cf_wizard.php3", "label"=>_m("Form Wizard")),
        "design"=>array ("cond"=>IfSlPerm(PS_USERS), 
            "href"=>"modules/alerts/tabledit.php3?set_tview=acf", 
            "label"=>_m("Design")),
        "settings"=>array ("cond"=>IfSlPerm(PS_USERS), 
            "href" => "modules/alerts/tabledit.php3?set_tview=modedit&cmd[modedit][edit]["
                .urlencode ($GLOBALS["slice_id"])."]=1", "label"=>_m("Settings"))
    ));
        
    global $db, $collectionid;
    
    $db->query ("SELECT status_code, COUNT(*) AS mycount 
        FROM alerts_user_collection 
        WHERE collectionid=$collectionid
        GROUP BY status_code
    ");
    $now = time();
    while ($db->next_record()) 
        $item_bin_cnt [$db->f("status_code")] = $db->f("mycount");
    $db->query ("SELECT COUNT(*) AS mycount
        FROM alerts_user_collection
        WHERE collectionid=$collectionid
        AND start_date > $now");
    if ($db->next_record())
        $item_bin_cnt_pend = $db->f("mycount");
    $db->query ("SELECT COUNT(*) AS mycount
        FROM alerts_user_collection
        WHERE collectionid=$collectionid
        AND start_date <= $now AND expiry_date < $now");
    if ($db->next_record())
        $item_bin_cnt_exp = $db->f("mycount");
        
    $aamenus["usermanager_submenu"] = array(
        "bottom_td"=>200,
        "level"=>"submenu",
        "items"=> array(
        "header1"=>_m("Users"),
        "app"=>array ("cond"=> 1, "href"=>"modules/alerts/tabledit.php3?set_tview=au&setTab=app", 
            "label"=>"<img src='".$AA_INSTAL_PATH."images/ok.gif' border=0>".get_bin_name("app")." (".($item_bin_cnt[1]-$item_bin_cnt_exp-$item_bin_cnt_pend).")"),
        "appb"=>array ("show"=>!$apple_design, "cond" => 1, "href"=>"modules/alerts/tabledit.php3?set_tview=au&setTab=appb", 
            "label"=>"... ".get_bin_name("appb")." ($item_bin_cnt_pend)"),
        "appc"=>array ("show"=>!$apple_design, "cond" => 1, "href"=>"modules/alerts/tabledit.php3?set_tview=au&setTab=appc", 
            "label"=>"... ".get_bin_name("appc")." ($item_bin_cnt_exp)"),
        "hold"=>array ("cond"=> 1, "href"=>"modules/alerts/tabledit.php3?set_tview=au&setTab=hold", 
            "label"=>"<img src='".$AA_INSTAL_PATH."images/edit.gif' border=0>".get_bin_name("hold")." (".($item_bin_cnt[2]+0).")"),
        "trash"=>array ("cond"=> 1, "href"=>"modules/alerts/tabledit.php3?set_tview=au&setTab=trash", 
            "label"=>"<img src='".$AA_INSTAL_PATH."images/delete.gif' border=0>".get_bin_name("trash")." (".($item_bin_cnt[3]+0).")"),
        "header2" => L_MISC,
        "item6"=>array ("cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"modules/alerts/index.php3?Delete=trash", 
            "label"=>"<img src='".$AA_INSTAL_PATH."images/empty_trash.gif' border=0>".L_DELETE_TRASH),
        "line" => ""
    ));
    
    return $aamenus;
}
?>
