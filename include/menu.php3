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
    href        link, relative to aa/admin/
    exact_href  link, absolute (use either exact_href or href, not both)
*/

if (!defined("AA_MENU_INCLUDED"))
      define("AA_MENU_INCLUDED",1);
else return;

require $GLOBALS[AA_INC_PATH]."menu_util.php3";
require $GLOBALS[AA_INC_PATH]."perm_core.php3";

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
        "label" => L_VIEW_SLICE, 
        "exact_href"  => $r_slice_view_url,
        "cond"  => 1,
        "level" => "main");    
        
    $aamenus["additem"] = array (
        "label" => L_ADD_NEW_ITEM,
        "href"  => "admin/itemedit.php3?encap=false&add=1",
        "level" => "main");
        
    $aamenus["itemmanager"] = array (
        "label" => L_ARTICLE_MANAGER, 
        "title" => L_ARTICLE_MANAGER,
        "href"  => "admin/index.php3?Tab=app",
        "level" => "main",
        "submenu" => "itemmanager_submenu");
        
    $aamenus["sliceadmin"] = array (
        "label" => L_SLICE_ADMIN2,
        "title" => L_SLICE_ADMIN,
        "href"  => "admin/slicedit.php3",
        "cond"  => IfSlPerm(PS_EDIT),
        "level" => "main",
        "submenu"=>"sliceadmin_submenu");    
        
    $aamenus["aaadmin"] = array (
        "label" => L_AA_ADMIN2,
        "title" => L_AA_ADMIN,
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
            href        link, relative to aa/admin/
            exact_href  link, absolute (use either exact_href or href, not both)
            show_always don't include slice_id in cond
    */
    
    $aamenus ["sliceadmin_submenu"] = array (
        "bottom_td"=>50, 
        "level"=>"submenu",
        
        "items"=>array (
    
        "header1" => L_MAIN_SET,
        "main" => array ("label"=>L_SLICE_SET, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT), "href"=>"admin/slicedit.php3?slice_id=$slice_id", "show_always"=>1), 
        "category" => array("label"=>L_CATEGORY, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY), "href"=>"admin/se_constant.php3"),
        "fields" => array ("label"=>L_FIELDS, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"admin/se_fields.php3?slice_id=$slice_id"), 
        "notify" => array ("label"=>L_NOTIFY, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT), "href"=>"admin/se_notify.php3?slice_id=$slice_id"), 
    
        "header2" => L_PERMISSIONS,
        "addusers"=> array ("label"=>L_PERM_ASSIGN, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ADD_USER), "href"=>"admin/se_users.php3?adduser=1&slice_id=$slice_id"),
        "users"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_USERS), "href"=>"admin/se_users.php3?slice_id=$slice_id", "label"=>L_PERM_CHANGE),
    
        "header3" => L_DESIGN,
        "compact"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_COMPACT), "href"=>"admin/se_compact.php3?slice_id=$slice_id", "label"=>L_COMPACT),
        "fulltext"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/se_fulltext.php3?slice_id=$slice_id", "label"=>L_FULLTEXT),
        "views"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/se_views.php3?slice_id=$slice_id", "label"=>L_VIEWS),
        "config"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CONFIG), "href"=>"admin/se_admin.php3?slice_id=$slice_id", "label"=>L_SLICE_CONFIG),
    
        "header4" => L_FEEDING,
        "nodes"=>array("cond"=>isSuperadmin(), "href"=>"admin/se_nodes.php3?slice_id=$slice_id", "label"=>L_NODES_MANAGER),
        "import"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_import.php3?slice_id=$slice_id", "label"=>L_INNER_IMPORT),
        "n_import"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_inter_import.php3?slice_id=$slice_id", "label"=>L_INTER_IMPORT),
        "n_export"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_inter_export.php3?slice_id=$slice_id", "label"=>L_INTER_EXPORT),
        "filters"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_filters.php3?slice_id=$slice_id", "label"=>L_FILTERS),   
        "mapping"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"admin/se_mapping.php3?slice_id=$slice_id", "label"=>L_MAP),
    
        "header5" => L_MISC,
        "field_ids" => array ("label"=>L_FIELD_IDS, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"admin/se_fieldid.php3?slice_id=$slice_id"),    
        "javascript" => array ("label"=>L_F_JAVASCRIPT, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"admin/se_javascript.php3")
        ,"fileman" => array ("label"=>L_FILEMAN, "cond"=>FilemanPerms ($auth, $slice_id), "href"=>"admin/fileman.php3")
    ));
    
    $aamenus["itemmanager_submenu"] = array(
        "bottom_td"=>200,
        "level"=>"submenu",
        "items"=> array(
        "header1"=>L_OTHER_ARTICLES,
        "item1"=>array ("cond"=> $r_bin_state != "app", "href"=>"admin/index.php3?Tab=app", "label"=>L_ACTIVE_BIN." (".($item_bin_cnt[1]-$item_bin_cnt_exp-$item_bin_cnt_pend).")"),
        "item2"=>array ("show"=>!$apple_design, "cond" => $r_bin_state != "appb", "href"=>"admin/index.php3?Tab=appb", "label"=>L_ACTIVE_BIN_PENDING_MENU." ($item_bin_cnt_pend)"),
        "item3"=>array ("show"=>!$apple_design, "cond" => $r_bin_state != "appc", "href"=>"admin/index.php3?Tab=appc", "label"=>L_ACTIVE_BIN_EXPIRED_MENU." ($item_bin_cnt_exp)"),
        "item4"=>array ("cond"=> $r_bin_state != "hold", "href"=>"admin/index.php3?Tab=hold", "label"=>L_HOLDING_BIN." ($item_bin_cnt[2])"),
        "item5"=>array ("cond"=> $r_bin_state != "trash", "href"=>"admin/index.php3?Tab=trash", "label"=>L_TRASH_BIN." ($item_bin_cnt[3])"),
        "header2" => L_MISC,
        "item6"=>array ("cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"admin/index.php3?Delete=trash", "label"=>L_DELETE_TRASH),
        "line" => "",
        "item7"=>array ("cond"=>1, "exact_href"=>"javascript:SelectVis(true)", "label"=>L_SELECT_VISIBLE),
        "item8"=>array ("cond"=>1, "exact_href"=>"javascript:SelectVis(false)", "label"=>L_UNSELECT_VISIBLE)
    ));
    
    $aamenus["aaadmin_submenu"] = array (
        "bottom_td" => 300,    
        "level" => "submenu",
        "items" => array (
        
        "header0" => L_MODULES,
        "sliceadd" => array ("label" => L_ADD_MODULE, "cond"=>IfSlPerm(PS_ADD), "href"=>"admin/sliceadd.php3"),
        "slicewiz" => array ("label" => L_ADD_SLICE_WIZ, "cond"=>IfSlPerm(PS_ADD), "href"=>"admin/slicewiz.php3"),
        "slicedel" => array ("label" => L_DELETE_MODULE, "cond"=>IsSuperadmin(), "href"=>"admin/slicedel.php3"),
        "jumpedit" => array ("label"=>L_EDIT_JUMP, "cond"=>IfSlPerm(PS_ADD), "exact_href" => $AA_INSTAL_PATH."modules/jump/modedit.php3?edit=1&AA_CP_Session=$AA_CP_Session"),
    /*    "delete" => array ("label" => L_DELETE_TRASH, "cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"admin/index.php3?Delete=trash"),*/

        "header1"=>L_USERS,
        "u_edit" => array ("href"=>"admin/um_uedit.php3", "cond"=>1, "label"=>L_EDIT_USER),
        "u_new" => array ("href"=>"admin/um_uedit.php3?usr_new=1", "cond"=>1, "label"=>L_NEW_USER),
        
        "header2"=>L_GROUPS,
        "g_edit" => array ("href"=>"admin/um_gedit.php3", "cond"=>1, "label"=>L_EDIT_GROUP),
        "g_new" => array ("href"=>"admin/um_gedit.php3?grp_new=1", "cond"=>1, "label"=>L_NEW_GROUP),
        
        "header5"=>L_EXPIMP_SET,
        "sliceexp"=>array("cond"=>IfSlPerm(PS_ADD), "href"=>"admin/sliceexp.php3", "label"=>L_EXPORT_SLICE), 
        "sliceimp"=>array("cond"=>IfSlPerm(PS_ADD), "href"=>"admin/sliceimp.php3", "label"=>L_IMPORT_SLICE),

        "header6"=>L_ALERTS,
        "alerts_collections"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/alerts_collections.php3", "label"=>L_ALERTS_COLLECTIONS),
        "te_alerts_collections"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"admin/tabledit.php3?set[tview]=ac", "label"=>L_TE_ALERTS_COLLECTIONS),        
        
        "header70"=>L_MISC,
        "te_wizard_welcome" => array ("label"=>L_EDIT_WIZARD_WELCOME, "cond"=>IsSuperadmin(), "href"=>"admin/tabledit.php3?set[tview]=ww"),
        "te_wizard_template" => array ("label"=>L_EDIT_WIZARD_TEMPLATE, "cond"=>IsSuperadmin(), "href"=>"admin/tabledit.php3?set[tview]=wt")
    ));
    
    return $aamenus;
}
?>