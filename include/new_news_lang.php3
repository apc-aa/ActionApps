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
define("L_CONSTANT_OWNER_HELP", "Whoever first updates values becomes owner.");

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

define('L_AA_ADMIN','AA Administration');
define('L_SLICE_ADMIN','Slice Administration');
define('L_AA_ADMIN2','AA');
define('L_SLICE_ADMIN2','Slice Admin');
define('L_ARTICLE_MANAGER2','Item Manager');
define('L_MODULES', 'Slices / Modules');
define ('L_ADD_MODULE', "Create new");
define ('L_DELETE_MODULE', "Delete");
define ('L_A_MODULE_ADD', 'Create New Slice / Module');
define ('L_A_SLICE', 'Slice');
define ('L_A_MODULE', 'Module');

define('L_MODULE_NAME','Module name');
define('L_JUMP_TO','Jump to');
define('L_AA_RELATIVE','Type in an AA-relative path, e.g.');
define('L_JUMP_SLICE','Jump to slice');
define('L_A_JUMP_EDT','Edit Jump module');
define('L_A_JUMP_ADD','Create new Jump module');
define('L_EDIT_JUMP','Edit Jump');
define('L_MODULE_ID','Module ID');
define('L_UPDATE','Update');
define('L_CREATE','Create');

define("L_E_IMPORT_TITLE", "Import exported data (slice structure and content)");
define("L_E_IMPORT_MEMO", "The import of the slices structure and content is done this way:<br>"
			."Insert the exported text into the frame and click on Send. <br>"
			."The slices structure with fields and its content definitions will be read and added to the ActionApps.");
define("L_E_IMPORT_OPEN_ERROR","Unknown failur when opening the file.");
define("L_E_IMPORT_WRONG_FILE","ERROR: Text is not OK. Check whether you copied it well from the Export.");
define("L_E_IMPORT_WRONG_ID","ERROR: ");
define("L_E_IMPORT_INSERT", "Insert");
define("L_E_IMPORT_OVERWRITE", "Overwrite");
define("L_E_IMPORT_INSERT_AS_NEW","Insert with new ids");
define("L_E_IMPORT_SEND","Send the slice structure and data");
define("L_E_IMPORT_IDLENGTH", "The identificator should be 32 characters long, not ");

define("L_E_IMPORT_IDCONFLICT", 
			"Slices with some of the IDs exist already. Change the IDs on the right side of the arrow.<br> "
			."Use only hexadecimal characters 0-9,a-f. "
			."If you do something wrong (wrong characters count, wrong characters, or if you change the ID on the arrow's left side), "
			."that ID will be considered unchanged.</p>");
define ("L_E_IMPORT_COUNT", "Count of imported slices: %d.");			
define("L_E_IMPORT_DATA_IDCONFLICT", 
			"<p>Slice content with some of the IDs exist already. Change the IDs on the right side of the arrow.<br> "
			."Use only hexadecimal characters 0-9,a-f. </p>");
define("L_E_IMPORT_CONFLICT_INFO","<p>If you choose OVERWRITE, the slices and data with unchanged ID will be overwritten and the new ones added. <br>"
			."If you choose INSERT, the slices and data with ID conflict will be ignored and the new ones added.<br>"
			."And finally, if you choose \"Insert with new ids\", slice structures gets new ids and it's content too.</p>");
define("L_E_IMPORT_IMPORT_SLICE","Import slice definition");		
define("L_E_IMPORT_IMPORT_ITEMS","Import slice items");		
		
define ("L_E_IMPORT_DATA_COUNT", "Count of imported stories: %d.");			
define ("L_E_IMPORT_ADDED", "Added were:");
define ("L_E_IMPORT_OVERWRITTEN", "Overwritten were:");
define ("L_CHOOSE_JUMP", "Choose module to be edited");

define ("L_A_FIELD_IDS_TIT", "Admin - change Field IDs");
define ("L_FIELD_IDS", "Change field IDs");
define ("L_FIELD_IDS_CHANGED", "field IDs were changed");
define ("L_V_MONTH_LIST", "Month list (separated by ,)");
define ("L_F_JAVASCRIPT", "Field Triggers");
define ("L_FIELD_ALIASES", "Aliases");

define('L_CONSTANT_WHERE_USED', 'Where are these constants used?');
define('L_CONSTANT_USED','Constants used in slice');

define('L_CHANGE_FROM','Change from');
define('L_TO', 'to');
define('L_FIELD_ID_HELP',
    'This page allows to change field IDs. It is a bit dangerous operation and may last long. 
    You need to do it only in special cases, like using search form for multiple slices. <br><br>
    Choose a field ID to be changed and the new name and number, the dots ..... will be 
    added automatically.<br>');
define('L_VIEW_CREATE_NEW', 'Create new view');    
define('L_VIEW_CREATE_TYPE', 'by&nbsp;type:');    
define('L_VIEW_CREATE_TEMPL', 'by&nbsp;template:');
define('L_USE_AS_NEW', 'Use&nbsp;as&nbsp;new');
define('L_SLICE_NOT_CONST', 'You selected slice and not constant group. It is unpossible to change slice. Go up in the list.');
define('L_JS_HELP','Enter code in the JavaScript language. It will be included in the Add / Edit item page (itemedit.php3).');
define('L_JS_FIELDS','Field IDs');
define('L_JS_TRIGGERS','Triggers');
define('L_JS_TRIG_HELP1','Write trigger functions like "aa_onSubmit (fieldid) { }"');
define('L_JS_TRIG_HELP2','see FAQ</a> for more details and examples');
define("L_JS_FIELD_TYPE", "Field Type");
define("L_JS_POSSIBLE_TRIGGERS", "Triggers Available -- see some JavaScript help for when a trigger is run");
define("L_DELETE_FILE","Delete permanently");
define("L_FILL_GROUP_NAME","Fill group name before using hierarchical editor.");
define("L_A_SLICE_WIZ_TIT","Add Slice Wizard");
define("L_ADD_SLICE_WIZ","Create new Wizard");
define("L_WIZ_NEWUSER_HDR","[Optional] Create New User");
define("L_USER_LEVEL_OF_ACCESS", "Level of Access");
define("L_ITEM_MANAGER","Editor");
define("L_SLICE_ADMINIS","Slice Administrator");
define("L_EMAIL_WELCOME", "Email Welcome");
define("L_ADD_SLICE", "Add Slice");
define("L_COPY_VIEWS","Copy Views");
define("L_CATEGO_CONST", "Categories/Constants");
define("L_SHARE_WITH_TEMPLATE","Share with Template");
define("L_COPY_FROM_TEMPLATE","Copy from Template");
define("L_NOT_EMAIL_WELCOME","Do Not Email Welcome");
define("L_ERROR_CONS","Error when copying constants.");
define("L_ERROR_VIEWS","Error when copying views.");            
define("L_ERROR_CHANGE_ROLE","Internal error when changing user role.");
define("L_SENT_TO","sent to");
define("L_INTERNAL_ERROR","Internal error");        
define("L_ERROR_MAILING","Error mailing");        
define("L_USER_NOT_FOUND","User not found");
define("L_WRONG_NUMBER_OF_ROWS","Wrong number of rows.");
define("L_EDIT_WIZARD_WELCOME","Wizard Welcomes");
define("L_TABLE_EMPTY","Table is empty");
define("L_OBJECT","Object");
define("L_ASCENDING_PRI","Ascending by Priority");
define("L_DESCENDING_PRI","Descending by Priority");
define("L_SORT_DIRECTION_HLP","'by Priority' is usable just for fields using constants (like category)");
define("L_FILEMAN_ACCESS","File Manager Access");
define("L_FILEMAN_DIR","File Manager Directory");
define("L_NOBODY","Nobody");
define("L_EDITOR","Slice Editor");
define("L_ADMINISTRATOR","Slice Administrator");
define("L_SUPERADMIN","Superadmin");

/* FILE MANAGER */

define("L_A_FTP_TIT","File Manager");
define("L_DIRECTORY","Directory");
define("L_FILE","File");
define("L_RENAME","Rename");
define("L_UPLOAD","Upload new file");
define("L_FILEMAN","File Manager");
define("L_DIR_NOT_EXISTS", "Internal error. File upload: Dir does not exist?!");
define("L_FILE_NAME_EXISTS","File with this name already exists.");
define("L_FILETYPE_HTML","HTML file");
define("L_FILETYPE_WEB","Web file");
define("L_FILETYPE_IMAGE","Image file");
define("L_FILETYPE_TEXT","Text file");
define("L_FILETYPE_DIRECTORY","Directory");
define("L_FILETYPE_PARENT","Parent");
define("L_FILETYPE_OTHER","Other");
define("L_FILE_CONTENT","File content");
define("L_BACK_TO_FILE_LIST","Back to file list");
define("L_DOWNLOAD_BY_RIGHTCLICK","Download (right-click)");
define("L_RENAME_TO","Rename to");
define("L_SAVE_CHANGES","Save changes");
define("L_RESET_CONTENT","Reset content");
define("L_THIS_IS_FILE","This is a file of type");
define("L_I_CANT_VIEW","I can't view it. If you want to view or edit it, change it's extension.");
define("L_CREATE_NEW_FILE","Create new file");
define("L_UPLOAD_FILE","Upload file");
define("L_COPY_TEMPLATE","Copy template dir");
define("L_CREATE_NEW_DIR","Create new directory");
define("L_SELECT_ALL","Select All");
define("L_UNSELECT_ALL","Unselect All");
define("L_DELETE","Delete");
define("L_FILE_EXISTS","File already exists");        
define("L_UNABLE_TO_CREATE_FILE","Unable to create file");
define("L_WRONG_FILE_NAME","Wrong file name.");
define("L_WRONG_DIR_NAME","Wrong directory name.");
define("L_UNABLE_TO_CREATE_DIR","Unable to create directory");
define("L_FIRST_DELETE_ALL_FILES","First delete all files from directory");
define("L_UNABLE_TO_DELETE_FILE","Unable to delete file");
define("L_UNABLE_TO_DELETE_DIR","Unable to delete directory");
define("L_UNABLE_TO_WRITE","Unable to open file for writing");                
define("L_ERROR_WRITING","Error writing to file");
define("L_FILE_ALREADY_EXISTS","File with this name already exists");            
define("L_UNABLE_RENAME","Unable to rename");
define("L_SURE_TO_DELETE","Are you sure you want to delete the selected files and folders?");                
define("L_SC_NAME","Name");
define("L_SC_SIZE","Size");
define("L_SC_TYPE","Type");
define("L_SC_LAST_MODIFIED","Last modified");    
define("L_ERR_WRONG_DIR","Wrong directory name");    
define("L_SOME_FILES_EXIST","Files with the same names as some in the template already exist. Please change the file names first.");
define("L_FILEMAN_DIR_USED","This File Manager Directory is already used by another slice.");

/* END OF FILE MANAGER */

define("L_RESERVED_ID","This ID is reserved");            
define("L_ID_EXISTS","This ID is already used");
define("L_EDIT_WIZARD_TEMPLATE","Wizard Templates");

/* E-MAIL ALERTS */

define("L_ALERTS_VIEW","Alerts Digest");
define("L_V_MAXLISTLEN","Max number of items");
define("L_ALERTS_COLLECTIONS","Collections");
define("L_A_COLLECTIONS_TIT","Alerts Collections");
define("L_FILTER","Filter");
define("L_FILTERS","Filters");
define("L_DESCRIPTION","Description");
define("L_ALERTS","Alerts");
define("L_ALERTS_COLLECTION_TITLE", "Alerts Collections");
define("L_ALERTS_USERS", "Users");
define("L_ALERTS_UI", "User Interface");

/* END OF E-MAIL ALERTS */
define("L_DISCUSSION_2_MAIL","Discussion To Mail");
define("L_V_MAIL_FROM","From: (email header)");
define("L_V_MAIL_REPLY_TO","Reply-To:");
define("L_V_MAIL_ERRORS_TO","Errors-To:");
define("L_V_MAIL_SENDER","Sender:");
define("L_V_MAIL_SUBJECT","Mail Subject:");
define("L_V_MAIL_BODY","Mail Body:");

define("L_AUTO_CHECKBOX", "Auto Update Checkbox");
?>