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

// defines the left submenu for menu AA Admin (AA)

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

    "header7"=>_m("Wizard"),
    "te_wizard_welcome" => array ("label"=>_m("Welcomes"), "cond"=>IsSuperadmin(), "href"=>"admin/tabledit.php3?set_tview=ww"),
    "te_wizard_template" => array ("label"=>_m("Templates"), "cond"=>IsSuperadmin(), "href"=>"admin/tabledit.php3?set_tview=wt"),

    "header8"=>_m("Misc"),
    "te_cron" => array ("label"=>_m("Cron"), "cond"=>IsSuperadmin(), "href"=>"admin/tabledit.php3?set_tview=cron"),
    "aafinder" => array ("label"=>_m("AA finder"), "cond"=>IsSuperadmin(), "href"=>"admin/aafinder.php3")
));

?>