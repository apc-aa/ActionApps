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

/* Here are new constants to be added to all xx_news_lang.php3 files.
	You don't have to put them in every language file, just put them here
	- this file should be included at the end of every language file. */

/*	Please insert constants always AT THE END of the file */

define("L_CONSTANT_HIERARCH_EDITOR","Edit in Hierarchical editor (allows to create constant hierarchy)");
define("L_CONSTANT_PROPAGATE","Propagate changes into current items");
define("L_CONSTANT_OWNER","Constant group owner - slice");
define("L_A_CONSTANTS_HIER_EDT","Admin - Hierarchical Constants Setting");
define("L_CONSTANT_HIER_SORT","Changes are not saved into database until you click on the button at the bottom of this page.<br>Constants are sorted first by Priority, second by Name.");
define("L_CONSTANT_DESC","Description");
define("L_CONSTANT_HIER_SAVE","Save all changes to database");
define("L_CONSTANT_CHOWN", "Change owner");

define("L_ITEM_NOT_ANONYMOUS","This item isn't allowed to be changed anonymously.");
define("L_MY_ITEM_ID_MISSING","You must supply a my_item_id to this script.");
define("L_ITEM_NOT_EXISTS","Item with this ID does not exist.");

define("L_NO_PS_FIELDS_GROUP", "You have not permissions to change fields settings for the slice owning this group");
define("L_NO_PS_CATEGORY_GROUP", "You have not permissions to change category settings for the slice owning this group");
define("L_CONSTANTS_HIER_EDT","Constants - Hiearchical editor");
define("L_CONSTANT_OWNER_HELP", "Owner becomes whoever first updates values.");

define ("L_CONSTANT_ITEM_CHNG"," items changed to new value ");

define ("L_CONSTANT_LEVELS_HORIZONTAL", "Levels horizontal");
define ("L_CONSTANT_VIEW_SETTINGS", "View settings");
define ("L_CONSTANT_HIERARCHICAL", "Hierarchical");
define ("L_CONSTANT_HIDE_VALUE", "Hide value");
define ("L_CONSTANT_CONFIRM_DELETE","Check to confirm deleting");
define ("L_CONSTANT_COPY_VALUE","Copy value from name");
define ("L_CONSTANT_LEVEL_COUNT","Level count");
define ("L_CONSTANT_LEVEL", "Level");
define ("L_SELECT","Select");
define ("L_ADD_NEW","Add new");
?>
