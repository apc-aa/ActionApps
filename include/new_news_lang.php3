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

define('L_ID_COUNT_ALIAS','number of found items');
define('L_V_NO_ITEM','HTML code for "No item found" message');
define('L_INPUT_SHOW_HCO','Hierachical constants');

define("L_GROUP_BY", "Group by");
define("L_GROUP_BY_HLP", "");
define("L_GROUP_HEADER", "Group header");
define ("L_WHOLE_TEXT", "Whole text");
define ("L_FIRST_LETTER", "1st letter");
define ("L_LETTERS", "letters");
define ("L_CASE_NONE", "Don't change");
define ("L_CASE_UPPER","UPPERCASE");
define ("L_CASE_LOWER","lowercase");
define ("L_CASE_FIRST","First Upper");

define("L_INPUT_VALIDATE_USER","User");			# added 03/01/02, setu@gwtech.org
define("L_INPUT_DEFAULT_VAR", "Variable"); # Added by Ram on 5th March 2002 (Only for English)

define("L_E_EXPORT_DESC_EXPORT","Choose, if you want export slices structure, data or both.");
define("L_E_EXPORT_EXPORT_DATA","Export data");
define("L_E_EXPORT_EXPORT_STRUCT","Export structure");
define("L_E_EXPORT_EXPORT_GZIP","Use compression");
define("L_E_EXPORT_EXPORT_TO_FILE","Store exported data in file");
define("L_E_EXPORT_MUST_SELECT","You must select one or more slices to backup");
define("L_E_EXPORT_SPEC_DATE","Export data from specified dates: ");
define("L_E_EXPORT_FROM_DATE","From ");
define("L_E_EXPORT_TO_DATE","to");
define("L_E_EXPORT_DATE_ERROR","Sorry, this isn't valid date");
define("L_E_EXPORT_DATE_TYPE_ERROR","Sorry, You must use this format: DD.MM.YYYY");

define ("L_CALENDAR_VIEW", "Calendar");
define ("L_V_FROM_DATE", "Start date field");
define ("L_V_TO_DATE", "End date field");
define ("L_V_DATEFLD","Date field");
define ("L_V_GROUP_BOTTOM", "Group bottom format");
define ("L_V_DAY", "Day cell top format");
define ("L_V_DAY_BOTTOM", "Day cell bottom format");
define ("L_V_EVENT", "Event format");
define ("L_V_EMPTY_DIFFER", "Use other header for empty cells");
define ("L_V_DAY_EMPTY", "Empty day cell top format");
define ("L_V_DAY_EMPTY_BOTTOM", "Empty day cell bottom format");
define ("L_MONTH", "Month List");
define ("L_MONTH_TABLE", "Month Table");
define ("L_V_CALENDAR_TYPE", "Calendar Type");
define ("L_CONST_DELETE", "Delete whole group");
define ("L_CONST_DELETE_PROMPT","Are you sure you want to PERMANENTLY DELETE this group? Type yes or no.");
define ("L_NO", "no");
define ("L_YES", "yes");
define ("L_V_EVENT_TD", "Additional attribs to the TD event tag");
define('L_C_TIMESTAMP1','Calendar: Time stamp at 0:00 of processed cell');
define('L_C_TIMESTAMP2', 'Calendar: Time stamp at 24:00 of processed cell');
define('L_C_NUMD','Calendar: Day in month of processed cell');
define('L_C_NUMM','Calendar: Month number of processed cell');
define('L_C_NUMY','Calendar: Year number of processed cell');

define('L_V_D_SPACE','HTML code for space before comment');
define('L_V_D_SEL_BUTTON','HTML code for "Show selected" button');
define('L_V_D_ALL_BUTTON','HTML code for "Show all" button');
define('L_V_D_NEW_BUTTON','HTML code for "Add" button');

define('L_INPUT_SHOW_TPR','Textarea with Presets');
define('L_CONSTANT_USED','Constants used in slice');

?>
