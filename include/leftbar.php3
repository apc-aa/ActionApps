<!-- left navigate column    -->
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

$menus = array (
    "sliceadmin" => array ("bottom_td"=>50, "items"=>
    array (
    "header1" => L_MAIN_SET,
    "main" => array ("label"=>L_SLICE_SET, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_EDIT), "href"=>"slicedit.php3?slice_id=$slice_id", "show_always"=>1), 
    "category" => array("label"=>L_CATEGORY, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_CATEGORY), "href"=> "se_constant.php3"),
    "fields" => array ("label"=>L_FIELDS, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"se_fields.php3?slice_id=$slice_id"), 
    "javascript" => array ("label"=>L_F_JAVASCRIPT, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"se_javascript.php3"),
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
    "field_ids" => array ("label"=>L_FIELD_IDS, "cond"=>CheckPerms( $auth->auth["uid"], "slice", $slice_id, PS_FIELDS), "href"=>"se_fieldid.php3?slice_id=$slice_id")    
)),


    "itemmanager" => array("bottom_td"=>200,"items"=>
    array(
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
)),

    "aaadmin" => array ("bottom_td" => 300, "items" => 
    array (
    "header0" => L_MODULES,
    "sliceadd" => array ("label" => L_ADD_MODULE, "cond"=>IfSlPerm(PS_ADD), "href"=>"sliceadd.php3"),
    "slicedel" => array ("label" => L_DELETE_MODULE, "cond"=>IsSuperadmin(), "href"=>"slicedel.php3"),
    "jumpedit" => array ("label"=>L_EDIT_JUMP, "cond"=>IfSlPerm(PS_ADD), "exact_href" => AA_INSTAL_URL."modules/jump/modedit.php3?edit=1&AA_CP_Session=$AA_CP_Session"),
/*    "delete" => array ("label" => L_DELETE_TRASH, "cond"=>IfSlPerm(PS_DELETE_ITEMS), "href"=>"index.php3?Delete=trash"),*/
    "header1"=>L_USERS,
    "u_edit" => array ("href"=>"um_uedit.php3", "cond"=>1, "label"=>L_EDIT_USER),
    "u_new" => array ("href"=>"um_uedit.php3?usr_new=1", "cond"=>1, "label"=>L_NEW_USER),
    "header2"=>L_GROUPS,
    "g_edit" => array ("href"=>"um_gedit.php3", "cond"=>1, "label"=>L_EDIT_GROUP),
    "g_new" => array ("href"=>"um_gedit.php3?grp_new=1", "cond"=>1, "label"=>L_NEW_GROUP),
    "header5"=>L_EXPIMP_SET,
    "sliceexp"=>array("cond"=>IfSlPerm(PS_ADD), "href"=>"sliceexp.php3", "label"=>L_EXPORT_SLICE), 
    "sliceimp"=>array("cond"=>IfSlPerm(PS_ADD), "href"=>"sliceimp.php3", "label"=>L_IMPORT_SLICE)
)));
  
echo '<table width="122" border="0" cellspacing="0" bgcolor="'.COLOR_TABBG.' cellpadding="1" align="LEFT" class="leftmenu">';

if (isset ($menu)) $menu = $menus[$menu];
else $menu = $menus["sliceadmin"];

function get_admin_url ($href) {
    $res = AA_INSTAL_URL."admin/".$href;
    if (strstr ($href,"?")) $res .= "&"; else $res .= "?";
    $res .= "AA_CP_Session=".$GLOBALS[AA_CP_Session];
    return $res;
}

$menuitems = $menu["items"];
reset ($menuitems);
while (list ($itemshow, $item) = each ($menuitems)) {
    if (substr($itemshow,0,6) == "header") 
        echo '<tr><td>&nbsp;</td></tr>
  <tr><td><img src="'.AA_INSTAL_URL.'images/black.gif" width=120 height=1></td></tr>
              <tr><td class=leftmenu>'.$item.'</td></tr>
              <tr><td><img src="'.AA_INSTAL_URL.'images/black.gif" width=120 height=1></td></tr>';
    else if (substr($itemshow,0,4) == "line")
        echo '<tr><td><img src="'.AA_INSTAL_URL.'images/black.gif" width=120 height=1></td></tr>';
    else {
        echo '<tr><td width="122" valign="TOP">&nbsp;&nbsp;';
        if (($slice_id || $item["show_always"]) && !isset($show[$itemshow]) && $item["cond"]) {
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
      <tr><td height="'.$menu["bottom_td"].'">
      <tr><td class=copymsg ><small>'. L_COPYRIGHT .'</small>
      </td></tr></table>';
