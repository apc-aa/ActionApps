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


# config file identifier
# must correspond with this file name
define("LANG_FILE", "en_site_lang.php3");

# HTML begin of admin page
# You should set language of admin pages and possibly any meta tags
define("HTML_PAGE_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
    

// ------------------------- New ----------------------------
// not appended to other lang files
//define("", "");
define("L_ERR_BAD_START_SPOT", "Starting spot not found");
define("L_NO_PS_EDIT_ITEMS", "You haven't permission to edit this site");
define("L_EDITOR_TITLE", "APC ActionApps - Site editor");
define("L_ADD_SPOT", "Add&nbsp;spot");
define("L_ADD_CHOICE", "Add&nbsp;choice");
define("L_DELETE_SPOT", "Delete&nbsp;spot");
define("L_MOVEUP_SPOT", "Move&nbsp;up");
define("L_MOVEDOWN_SPOT", "Move&nbsp;down");
define("L_NEW_SLICE_HEAD", "New site");
define("L_CODE_MANAGER", "Code&nbsp Manager");
define("L_SITE_SETTINGS", "Module Settings");
define("L_USER_MANAGEMENT", "AA");
define("L_VIEW_SITE", "View site");
define("L_LOGO", "APC ActionApps Logo");
define("L_GO", "Go");
define("L_SPOT_VARIABLES", "Spot&nbsp;variables");
define("L_DELETE", "Delete");
define("L_ADD", "Add");
define("L_SPOT_CONDITIONS", "Spot&nbsp;conditions");
define("L_SPOT_NAME", "Spot name");
define("L_SUBMIT", "Change");
?> 