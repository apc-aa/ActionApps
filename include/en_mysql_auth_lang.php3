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
define("LANG_FILE", "en_mysql_auth_lang.php3");

# HTML begin of admin page
# You should set language of admin pages and possibly any meta tags
define("HTML_PAGE_BEGIN",
 '<!DOCTYPE html public "-//W3C//DTD HTML 4.0 Transitional//EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
    
define("L_NO_PS_EDIT_ITEMS", "You are not allowed to manage users.");
define("L_EDITOR_TITLE", "User management");
define ("L_ADD_USER","Add User");
define ("L_EDIT_USER","Edit User");
define ("L_ITEM_MANAGER","User Manager");
define ("L_SWITCH_TO","Switch to:");
 
define ("L_FIRST_LAST_REQUIRED","First and last name are required.");
define ("L_THANKS1","Thank you for your subscription. You have been assigned the user name ");
define ("L_THANKS2","and the password");
define ("L_THANKS3",". Please remember it and keep it secure - you will need it to log in to our web.");

?>
