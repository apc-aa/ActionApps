<?php # navbar - application navigation bar 
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

/* Author: Jakub Adamek (but the menu structure is the old one, not mine) */
    
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

if (!defined ("AA_MENU_INCLUDED"))
    define("AA_MENU_INCLUDED","1");
else return;

$aamenus["view"] = array (
    "label" => L_VIEW_SLICE,
    "exact_href"  => $r_slice_view_url,
    "cond"  => 1,
    "level" => "main");    
    
$aamenus["additem"] = array (
    "label" => L_ADD_NEW_ITEM,
    "href"  => "itemedit.php3?encap=false&add=1",
    "level" => "main");
    
$aamenus["itemmanager"] = array (
    "label" => L_ARTICLE_MANAGER, 
    "title" => L_ARTICLE_MANAGER,
    "href"  => "index.php3?Tab=app",
    "level" => "main",
    "submenu" => "itemmanager_submenu");
    
$aamenus["sliceadmin"] = array (
    "label" => L_SLICE_ADMIN2,
    "title" => L_SLICE_ADMIN,
    "href"  => "slicedit.php3",
    "cond"  => IfSlPerm(PS_EDIT),
    "level" => "main",
    "submenu"=>"sliceadmin_submenu");    
    
$aamenus["aaadmin"] = array (
    "label" => L_AA_ADMIN2,
    "title" => L_AA_ADMIN,
    "href"  => "um_uedit.php3",
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
    "main" => array ("label"=>L_SLICE_SET, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT), "href"=>"slicedit.php3?slice_id=$slice_id", "show_always"=>1), 
    "category" => array("label"=>L_CATEGORY, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY), "href"=> "se_constant.php3"),
    "fields" => array ("label"=>L_FIELDS, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"se_fields.php3?slice_id=$slice_id"), 
    "notify" => array ("label"=>L_NOTIFY, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT), "href"=>"se_notify.php3?slice_id=$slice_id"), 
    "header2" => L_PERMISSIONS,
    "addusers"=> array ("label"=>L_PERM_ASSIGN, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_ADD_USER), "href"=> "se_users.php3?adduser=1&slice_id=$slice_id"),
    "users"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_USERS), "href"=>"se_users.php3?slice_id=$slice_id", "label"=>L_PERM_CHANGE),
    "header3" => L_DESIGN,
    "compact"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_COMPACT), "href"=>"se_compact.php3?slice_id=$slice_id", "label"=>L_COMPACT),
    "fulltext"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"se_fulltext.php3?slice_id=$slice_id", "label"=>L_FULLTEXT),
    "views"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FULLTEXT), "href"=>"se_views.php3?slice_id=$slice_id", "label"=>L_VIEWS),
    "config"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CONFIG), "href"=>"se_admin.php3?slice_id=$slice_id", "label"=>L_SLICE_CONFIG),
    "header4" => L_FEEDING,
    "nodes"=>array("cond"=>isSuperadmin(), "href"=>"se_nodes.php3?slice_id=$slice_id", "label"=>L_NODES_MANAGER),
    "import"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"se_import.php3?slice_id=$slice_id", "label"=>L_INNER_IMPORT),
    "n_import"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"se_inter_import.php3?slice_id=$slice_id", "label"=>L_INTER_IMPORT),
    "n_export"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"se_inter_export.php3?slice_id=$slice_id", "label"=>L_INTER_EXPORT),
    "filters"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"se_filters.php3?slice_id=$slice_id", "label"=>L_FILTERS),   
    "mapping"=>array("cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FEEDING), "href"=>"se_mapping.php3?slice_id=$slice_id", "label"=>L_MAP),
    "header5" => L_MISC,
    "field_ids" => array ("label"=>L_FIELD_IDS, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"se_fieldid.php3?slice_id=$slice_id"),    
    "javascript" => array ("label"=>L_F_JAVASCRIPT, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"se_javascript.php3")
    ,"fileman" => array ("label"=>L_FILEMAN, "cond"=>FilemanPerms ($auth, $slice_id), "href"=>"fileman.php3")
));

$aamenus["itemmanager_submenu"] = array(
    "bottom_td"=>200,
    "level"=>"submenu",
    "items"=> array(
    
    "header1"=>L_OTHER_ARTICLES,
    "item1"=>array ("cond"=> $r_bin_state != "app", "href"=>"index.php3?Tab=app", "label"=>L_ACTIVE_BIN." (".($item_bin_cnt[1]-$item_bin_cnt_exp-$item_bin_cnt_pend).")"),
    "item2"=>array ("show"=>!$apple_design, "cond" => $r_bin_state != "appb", "href"=>"index.php3?Tab=appb", "label"=>L_ACTIVE_BIN_PENDING_MENU." ($item_bin_cnt_pend)"),
    "item3"=>array ("show"=>!$apple_design, "cond" => $r_bin_state != "appc", "href"=>"index.php3?Tab=appc", "label"=>L_ACTIVE_BIN_EXPIRED_MENU." ($item_bin_cnt_exp)"),
    "item4"=>array ("cond"=> $r_bin_state != "hold", "href"=>"index.php3?Tab=hold", "label"=>L_HOLDING_BIN." ($item_bin_cnt[2])"),
    "item5"=>array ("cond"=> $r_bin_state != "trash", "href"=>"index.php3?Tab=trash", "label"=>L_TRASH_BIN." ($item_bin_cnt[3])"),
    "header2" => L_MISC,
    "item6"=>array ("cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"index.php3?Delete=trash", "label"=>L_DELETE_TRASH),
    "line" => "",
    "item7"=>array ("cond"=>1, "exact_href"=>"javascript:SelectVis(true)", "label"=>L_SELECT_VISIBLE),
    "item8"=>array ("cond"=>1, "exact_href"=>"javascript:SelectVis(false)", "label"=>L_UNSELECT_VISIBLE)
));

$aamenus["aaadmin_submenu"] = array (
    "bottom_td" => 300,    
    "level" => "submenu",
    "items" => array (
    
    "header0" => L_MODULES,
    "sliceadd" => array ("label" => L_ADD_MODULE, "cond"=>IfSlPerm(PS_ADD), "href"=>"sliceadd.php3"),
    "slicewiz" => array ("label" => L_ADD_SLICE_WIZ, "cond"=>IfSlPerm(PS_ADD), "href"=>"slicewiz.php3"),
    "slicedel" => array ("label" => L_DELETE_MODULE, "cond"=>IsSuperadmin(), "href"=>"slicedel.php3"),
    "jumpedit" => array ("label"=>L_EDIT_JUMP, "cond"=>IfSlPerm(PS_ADD), "exact_href" => $AA_INSTAL_PATH."modules/jump/modedit.php3?edit=1&AA_CP_Session=$AA_CP_Session"),
/*    "delete" => array ("label" => L_DELETE_TRASH, "cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"index.php3?Delete=trash"),*/
    "header1"=>L_USERS,
    "u_edit" => array ("href"=>"um_uedit.php3", "cond"=>1, "label"=>L_EDIT_USER),
    "u_new" => array ("href"=>"um_uedit.php3?usr_new=1", "cond"=>1, "label"=>L_NEW_USER),
    "header2"=>L_GROUPS,
    "g_edit" => array ("href"=>"um_gedit.php3", "cond"=>1, "label"=>L_EDIT_GROUP),
    "g_new" => array ("href"=>"um_gedit.php3?grp_new=1", "cond"=>1, "label"=>L_NEW_GROUP),
    "header5"=>L_EXPIMP_SET,
    "sliceexp"=>array("cond"=>IfSlPerm(PS_ADD), "href"=>"sliceexp.php3", "label"=>L_EXPORT_SLICE), 
    "sliceimp"=>array("cond"=>IfSlPerm(PS_ADD), "href"=>"sliceimp.php3", "label"=>L_IMPORT_SLICE),
    "header70"=>L_MISC,
    "te_wizard_welcome" => array ("label"=>L_EDIT_WIZARD_WELCOME, "cond"=>IsSuperadmin(), "href"=>"te_wizard_welcome.php3"),
    "te_wizard_template" => array ("label"=>L_EDIT_WIZARD_TEMPLATE, "cond"=>IsSuperadmin(), "href"=>"te_wizard_template.php3")
));

// ----------------------------------------------------------------------------------------
//                                SHOW MENU
    
function showMenu ($smmenus,$activeMain, $activeSubmenu = "", $showMain = 1, $showSub = 1)
{
    global $slice_id, $AA_INSTAL_PATH, $r_slice_headline, $useOnLoad;
    global $debug;
    if ($debug) { echo "<p><font color=purple>showMenu:activeMain=$activeMain;activeSubmenu=$activeSubmenu;showMain=$showMain;showSub=$showSub:</font></p>";  }
    if( $useOnLoad )
        echo '<body OnLoad="InitPage()" background="'. COLOR_BACKGROUND .'">';
    else
        echo '<body background="'. COLOR_BACKGROUND .'">';
   
    if( !$slice_id )
        $r_slice_headline = L_NEW_SLICE_HEAD;

    $nb_logo = '<a href="'. $AA_INSTAL_PATH .'"><img src="'.$AA_INSTAL_PATH.'images/action.gif" width="106" height="73" border="0" alt="'. L_LOGO .'"></a>';

    if ($showMain) {
        echo "
        <TABLE border=0 cellpadding=0 cellspacing=0>
            <TR><TD><IMG src=\"$AA_INSTAL_PATH"."images/spacer.gif\" width=122 height=1></TD>
                <TD><IMG src=\"$AA_INSTAL_PATH"."images/spacer.gif\" width=300 height=1></TD>
                <TD><IMG src=\"$AA_INSTAL_PATH"."images/spacer.gif\" width=267 height=1></TD>
            </TR>
            <TR><TD rowspan=2 align=center class=nblogo>$nb_logo</td>
                <TD height=43 colspan=2 align=center valign=middle class=slicehead>
                    ".$smmenus[$activeMain]["title"]."  -  $r_slice_headline</TD>
            </TR>
            <TR><td align=center class=navbar>";
                            
        $first = true;
        reset ($smmenus);
        while (list ($aamenu,$aamenuprop) = each ($smmenus)) {
            if ($aamenuprop["level"] == "main") {
                if ($first) $first = false;
                else echo " | ";
                if (!isset ($aamenuprop["cond"])) $aamenuprop["cond"] = 1;
                if ($slice_id && $aamenuprop["cond"] && $aamenu != $activeMain) {
                     $href = $aamenuprop["exact_href"];
                     if (!$href) $href = get_admin_url($aamenuprop["href"]);                    
                     echo "<a href=\"$href\">"
                         ."<span class=nbenable>$aamenuprop[label]</span></a>";
                }
                else echo "<span class=nbdisable>$aamenuprop[label]</span>";
            }
        }
        
        echo "</td><TD valign=center class=navbar>";
        PrintModuleSelection();
        echo "</TD></TR></TABLE>";
    }

    if ($showSub) {
        $submenu = $smmenus[$activeMain]["submenu"];
        if ($submenu)
            showSubmenu ($smmenus[$submenu], $activeSubmenu);
    }
}   

// ----------------------------------------------------------------------------------------
//                                SHOW SUBMENU

function showSubmenu (&$aamenu, $active)
{
    global $AA_INSTAL_PATH, $slice_id,$debug;
    if ($debug) { echo "<p><font color=purple>showSubmenu:active=$active</font></p>"; }
    echo '<table width="122" border="0" cellspacing="0" bgcolor="'.COLOR_TABBG.'" cellpadding="1" align="LEFT" class="leftmenu">';

    $aamenuitems = $aamenu["items"];
    reset ($aamenuitems);
    while (list ($itemshow, $item) = each ($aamenuitems)) {
        if (substr($itemshow,0,6) == "header") 
            echo '<tr><td>&nbsp;</td></tr>
      <tr><td><img src="'.$AA_INSTAL_PATH.'images/black.gif" width=120 height=1></td></tr>
                  <tr><td class=leftmenu>'.$item.'</td></tr>
                  <tr><td><img src="'.$AA_INSTAL_PATH.'images/black.gif" width=120 height=1></td></tr>';
        else if (substr($itemshow,0,4) == "line")
            echo '<tr><td><img src="'.$AA_INSTAL_PATH.'images/black.gif" width=120 height=1></td></tr>';
        else {
            echo '<tr><td width="122" valign="TOP">&nbsp;&nbsp;';
            if (!isset ($item["cond"])) $item["cond"] = 1;
            if (($slice_id || $item["show_always"]) 
                && $itemshow != $active && $item["cond"]) {
                echo '<a href="';
                if ($item["exact_href"]) echo $item["exact_href"]; 
                else echo get_admin_url($item["href"]);
                echo '" class=leftmenuy>'.$item["label"].'</a>';
            }  
            else echo "<span class=leftmenun>".$item["label"]."</span>";
            echo "</td></tr>";
        }
    }
  
    echo '<tr><td>&nbsp;</td></tr>
          <tr><td height="'.$aamenu["bottom_td"].'">
          <tr><td class=copymsg ><small>'. L_COPYRIGHT .'</small>
          </td></tr></table>';
}
