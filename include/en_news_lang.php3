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
define("LANG_FILE", "en_news_lang.php3");

define("EDIT_ITEM_COUNT", 20);                  // number of items in editor window

define("DEFAULT_FULLTEXT_HTML", '<BR><FONT SIZE=+2 COLOR=blue>_#HEADLINE</FONT> <BR><B>_#PUB_DATE</B> <BR>_#FULLTEXT');
define("DEFAULT_ODD_HTML", '<font face=Arial color=#808080 size=-2>_#PUB_DATE - </font><font color=#FF0000><strong><a href=_#HDLN_URL>_#HEADLINE</a></strong></font><font color=#808080 size=-1><br>_#PLACE###(<a href="_#SRC_URL#">_#SOURCE##</a>) - </font><font color=black size=-1>_#ABSTRACT<br></font><br>');
define("DEFAULT_EVEN_HTML", "");
define("DEFAULT_TOP_HTML", "<br>");
define("DEFAULT_BOTTOM_HTML", "<br>");
define("DEFAULT_CATEGORY_HTML", "<p>_#CATEGORY</p>");
define("DEFAULT_EVEN_ODD_DIFFER", false);
define("DEFAULT_CATEGORY_SORT", true);
define("DEFAULT_COMPACT_REMOVE", "()");
define("DEFAULT_FULLTEXT_REMOVE", "()");


# HTML begin of admin page
# You should set language of admin pages and possibly any meta tags
define("HTML_PAGE_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="'.$AA_INSTAL_PATH.ADMIN_CSS.'" type="text/css"  title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
    
# aa toolkit specific labels
define("L_VIEW_SLICE", "View site");
define( "L_SLICE_HINT", '<br>To include slice in your webpage type next line 
                         to your shtml code: ');
define("L_ITEM_ID_ALIAS",'alias for Item ID');
define("L_EDITITEM_ALIAS",'alias used on admin page index.php3 for itemedit url');
define("L_LANG_FILE","Used Language File");
define("L_PARAMETERS","Parameters");
define("L_SELECT_APP","Select application");
define("L_SELECT_OWNER","Select owner");

define("L_CANT_UPLOAD","Can't upload Image"); 
define("L_MSG_PAGE", "Toolkit news message");   // title of message page
define("L_EDITOR_TITLE", "Editor window - item manager");
define("L_FULLTEXT_FORMAT_TOP", "Top HTML code");
define("L_FULLTEXT_FORMAT", "Fulltext HTML code");
define("L_FULLTEXT_FORMAT_BOTTOM", "Bottom HTML code");
define("L_A_FULLTEXT_TIT", "Admin - design Fulltext view");
define("L_FULLTEXT_HDR", "HTML code for fulltext view");
define("L_COMPACT_HDR", "HTML code for index view");
define("L_ITEM_HDR", "News Article");
define("L_A_ITEM_ADD", "Add Item");
define("L_A_ITEM_EDT", "Edit Item");
define("L_IMP_EXPORT", "Enable export to slice:");
define("L_ADD_NEW_ITEM", "Add Item");
define("L_DELETE_TRASH", "Empty trash");
define("L_VIEW_FULLTEXT", "Preview");
define("L_FULLTEXT", "Fulltext");
define("L_HIGHLIGHTED", "Highlighted");
define("L_A_FIELDS_EDT", "Admin - configure Fields");
define("L_FIELDS_HDR", "Fields");
define("L_NO_PS_EDIT_ITEMS", "You do not have permission to edit items in this slice");
define("L_NO_DELETE_ITEMS", "You have not permissions to remove items");
define("L_NO_PS_MOVE_ITEMS", "You have not permissions to move items");
define("L_FULLTEXT_OK", "Fulltext format update successful");

# aa toolkit common labels
# can be the same for all toolkit aplications
define("L_ACTIVE_BIN", "Active");
define("L_HOLDING_BIN", "Hold bin");
define("L_TRASH_BIN", "Trash bin");

define("L_CATEGORY","Category");
define("L_SLICE_NAME", "Title");          // slice
define("L_DELETED", "Deleted");           // slice
define("L_D_LISTLEN", "Listing length");  // slice
define("L_ERR_CANT_CHANGE", "Can't change slice settings");
define("L_ODD_ROW_FORMAT", "Odd Rows");
define("L_EVEN_ROW_FORMAT", "Even Rows");
define("L_EVEN_ODD_DIFFER", "Use different HTML code for even rows");
define("L_CATEGORY_TOP", "Category top HTML");
define("L_CATEGORY_FORMAT", "Category Headline");
define("L_CATEGORY_BOTTOM", "Category bottom HTML");
define("L_CATEGORY_SORT", "Sort items by category");
define("L_COMPACT_TOP", "Top HTML");
define("L_COMPACT_BOTTOM", "Bottom HTML");
define("L_A_COMPACT_TIT", "Admin - design Index view");
define("L_A_FILTERS_TIT", "Admin - Content Pooling - Filters");
define("L_FLT_SETTING", "Content Pooling - Configure Filters");
define("L_FLT_FROM_SL", "Filter for imported slice");
define("L_FLT_FROM", "From");
define("L_FLT_TO", "To");
define("L_FLT_APPROVED", "Active");
define("L_FLT_CATEGORIES", "Categories");
define("L_ALL_CATEGORIES", "All Categories");
define("L_FLT_NONE", "No From category selected!");
define("L_THE_SAME", "-- The same --");
define("L_EXPORT_TO_ALL", "Enable export to any slice");

define("L_IMP_EXPORT_Y", "Export enable");
define("L_IMP_EXPORT_N", "Export disable");
define("L_IMP_IMPORT", "Import from slice:");
define("L_IMP_IMPORT_Y", "Import");
define("L_IMP_IMPORT_N", "Do not import");
define("L_CONSTANTS_HLP", "Use these aliases for database fields");

define("L_ERR_IN", "Error in");
define("L_ERR_NEED", "it must be filled");
define("L_ERR_LOG", "you should use a-z, A-Z and 0-9 characters");
define("L_ERR_LOGLEN", "it must by 5 - 32 characters long");
define("L_ERR_NO_SRCHFLDS", "No searchfield specified!");

define("L_FIELDS", "Fields");
define("L_EDIT", "Edit");
define("L_DELETE", "Delete");
define("L_REVOKE", "Revoke");
define("L_UPDATE", "Update");
define("L_RESET", "Reset form");
define("L_CANCEL", "Cancel");
define("L_ACTION", "Action");
define("L_INSERT", "Insert");
define("L_NEW", "New");
define("L_GO", "Go");
define("L_ADD", "Add");
define("L_USERS", "Users");
define("L_GROUPS", "Groups");
define("L_SEARCH", "Search");
define("L_DEFAULTS", "Default");
define("L_SLICE", "Slice");
define("L_DELETED_SLICE", "No slice found for you");
define("L_A_NEWUSER", "New user in permission system");
define("L_NEWUSER_HDR", "New user");
define("L_USER_LOGIN", "Login name");
define("L_USER_PASSWORD1", "Password");
define("L_USER_PASSWORD2", "Retype password");
define("L_USER_FIRSTNAME", "First name");
define("L_USER_SURNAME", "Surname");
define("L_USER_MAIL", "E-mail");
define("L_USER_SUPER", "Superadmin account");
define("L_A_USERS_TIT", "Admin - User Management");
define("L_A_PERMISSIONS", "Admin - Permissions");
define("L_A_ADMIN", "Admin - design Item Manager view");
define("L_A_ADMIN_TIT", "Admin - design Item Manager view");
define("L_ADMIN_FORMAT", "Item format");
define("L_ADMIN_FORMAT_BOTTOM", "Bottom HTML");
define("L_ADMIN_FORMAT_TOP", "Top HTML");
define("L_ADMIN_HDR", "Listing of items in Admin interface");
define("L_ADMIN_OK", "Admin fields update successful");
define("L_ADMIN_REMOVE", "Remove strings");

define("L_ROLE_AUTHOR", "Author");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administrator");
define("L_ROLE_SUPER", "Super");

define("L_MAIN_SET", "Main settings");
define("L_PERMISSIONS", "Permissions");
define("L_PERM_CHANGE", "Change");
define("L_PERM_ASSIGN", "Assign");
define("L_PERM_NEW", "Search user or group");
define("L_PERM_SEARCH", "Assign new permissions");
define("L_PERM_CURRENT", "Change current permissions");
define("L_USER_NEW", "New User");
define("L_DESIGN", "Design");
define("L_COMPACT", "Index");
define("L_COMPACT_REMOVE", "Remove strings");
define("L_FEEDING", "Content Pooling");
define("L_IMPORT", "Partners");
define("L_FILTERS", "Filters");

define("L_A_SLICE_ADD", "Add Slice");
define("L_A_SLICE_EDT", "Admin - Slice settings");
define("L_A_SLICE_CAT", "Admin - configure Categories");
define("L_A_SLICE_IMP", "Admin - configure Content Pooling");
define("L_FIELD", "Field");
define("L_FIELD_IN_EDIT", "Show");
define("L_NEEDED_FIELD", "Required");
define("L_A_SEARCH_TIT", "Admin - design Search Page");
define("L_SEARCH_HDR", "Search form criteria");
define("L_SEARCH_HDR2", "Search in fields");
define("L_SEARCH_SHOW", "Show");
define("L_SEARCH_DEFAULT", "Default settings");
define("L_SEARCH_SET", "Search");
define("L_AND", "AND");
define("L_OR", "OR");
define("L_SRCH_KW", "Search for");
define("L_SRCH_FROM", "From");
define("L_SRCH_TO", "To");
define("L_SRCH_SUBMIT", "Search");
define("L_NO_PS_EDIT", "You have not permissions to edit this slice");
define("L_NO_PS_ADD", "You have not permissions to add slice");
define("L_NO_PS_COMPACT", "You have not permissions to change compact view formatting");
define("L_NO_PS_FULLTEXT", "You have not permissions to change fulltext formatting");
define("L_NO_PS_CATEGORY", "You have not permissions to change category settings");
define("L_NO_PS_FEEDING", "You have not permissions to change feeding setting");
define("L_NO_PS_USERS", "You have not permissions to manage users");
define("L_NO_PS_FIELDS", "You have not permissions to change fields settings");
define("L_NO_PS_SEARCH", "You have not permissions to change search settings");

define("L_BAD_RETYPED_PWD", "Retyped password is not the same as the first one");
define("L_ERR_USER_ADD", "It is impossible to add user to permission system");
define("L_NEWUSER_OK", "User successfully added to permission system");
define("L_COMPACT_OK", "Design of compact design successfully changed");
define("L_BAD_ITEM_ID", "Bad item ID");
define("L_ALL", " - all - ");
define("L_CAT_LIST", "Slice Categories");
define("L_CAT_SELECT", "This Slice Categories");
define("L_NEW_SLICE", "Add Slice");
define("L_ASSIGN", "Assign");
define("L_CATBINDS_OK", "Category update successful");
define("L_IMPORT_OK", "Content Pooling update successful");
define("L_FIELDS_OK", "Fields update successful");
define("L_SEARCH_OK", "Search fields update successful");
define("L_NO_CATEGORY", "No category defined");
define("L_NO_IMPORTED_SLICE", "There are no imported slices");
define("L_NO_USERS", "No user (group) found");

define("L_TOO_MUCH_USERS", "Too many users or groups found.");
define("L_MORE_SPECIFIC", "Try to be more specific.");
define("L_REMOVE", "Remove");
define("L_ID", "Id");
define("L_SETTINGS", "Admin");
define("L_LOGO", "APC Action Applications");
define("L_USER_MANAGEMENT", "Users");
define("L_ITEMS", "Item management page");
define("L_NEW_SLICE_HEAD", "New slice");
define("L_ERR_USER_CHANGE", "Can't change user");
define("L_PUBLISHED", "Published");
define("L_EXPIRED", "Expired");
define("L_NOT_PUBLISHED", "Not published, yet");
define("L_EDIT_USER", "Edit User");
define("L_EDITUSER_HDR", L_EDIT_USER);

define("NO_SOURCE_URL", "javascript: window.alert('No source url specified')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('No outer url specified')");

# editors interface constants
define("L_PUBLISHED_HEAD", "Pub");
define("L_HIGHLIGHTED_HEAD", "&nbsp;!&nbsp;");
define("L_FEEDED_HEAD", "Fed");
define("L_MORE_DETAILS", "More details");
define("L_LESS_DETAILS", "Less details");
define("L_UNSELECT_ALL", "Unselect all");
define("L_SELECT_VISIBLE", "Select all");
define("L_UNSELECT_VISIBLE", "Unselect all");

define("L_SLICE_ADM", "Slice Administration");
define("L_A_FILTERS_FLT", L_A_FILTERS_TIT);
define("L_A_COMPACT", L_A_COMPACT_TIT);
define("L_A_FULLTEXT", L_A_FULLTEXT_TIT);
define("L_SRCH_ALL", L_ALL);
define("L_SRCH_SLICE", L_SLICE);
define("L_SRCH_CATEGORY", L_CATEGORY);
define("L_SRCH_AUTHOR", L_CREATED_BY);
define("L_SRCH_LANGUAGE", L_LANGUAGE_CODE);
define("L_SRCH_HEADLINE", L_HEADLINE);
define("L_SRCH_ABSTRACT", L_ABSTRACT);
define("L_SRCH_FULL_TEXT", L_FULL_TEXT);
define("L_SRCH_EDIT_NOTE", L_EDIT_NOTE);
define("L_SLICES_HDR", L_SLICE);
define("L_A_SEARCH_EDT", L_A_SEARCH_TIT);
define("L_A_SLICE_TIT", L_SLICE_ADM);
define("L_A_FIELDS_TIT", L_A_FIELDS_EDT);
define("L_SLICE_SET", L_SLICE);
define("L_FULLTEXT_REMOVE", L_COMPACT_REMOVE);

define("L_FEED", "Export");
define("L_FEEDTO_TITLE", "Export Item to Selected Slice");
define("L_FEED_TO", "Export selected items to selected slice");
define("L_NO_PERMISSION_TO_FEED", "No permission");
define("L_NO_PS_CONFIG", "You have no permission to set configuration parameters of this slice");
define("L_SLICE_CONFIG", "Item Manager");
define("L_CHBOX_HEAD", "&nbsp;");   // title of checkbox in admin interface
define("L_CHBOX", "Checkbox"); 
define("L_CATNAME", "Category Name");
define("L_CATEGORY_ID", "Category ID");
define("L_EDITED_BY","Edited by");
define("L_MASTER_ID", "Master id");
define("L_NO_ITEM_FOUND", "No item found");
define("L_CHANGE_MARKED", "Selected items");
define("L_MOVE_TO_ACTIVE_BIN", "Move to Active");
define("L_MOVE_TO_HOLDING_BIN", "Move to Holding bin");
define("L_MOVE_TO_TRASH_BIN", "Move to Trash");
define("L_OTHER_ARTICLES", "Folders");
define("L_MISC", "Misc");
define("L_HEADLINE_EDIT", "Headline (edit on click)");
define("L_HEADLINE_PREVIEW", "Headline (preview on click)");
define("L_EDIT_LINK", "&nbsp;");
define("L_PREVIEW_LINK", "&nbsp;");
define("L_ARTICLE_MANAGER", "Item Manager");
define("L_SWITCH_TO", "Switch to:");
define("L_ADMIN", "Admin");

define("L_NO_PS_NEW_USER", "No permission to create new user");
define("L_ALL_GROUPS", "All Groups");
define("L_USERS_GROUPS", "User's Groups");
define("L_REALY_DELETE_USER", "Are you sure you want to delete selected user from whole permission system?");
define("L_REALY_DELETE_GROUP", "Are you sure you want to delete selected group from whole permission system?");
define("L_TOO_MUCH_GROUPS", "Too much groups found.");
define("L_NO_GROUPS", "No groups found");
define("L_GROUP_NAME", "Name");
define("L_GROUP_DESCRIPTION", "Description");
define("L_GROUP_SUPER", "Superadmin group");
define("L_ERR_GROUP_ADD", "It is impossible to add group to permission system");
define("L_NEWGROUP_OK", "Group successfully added to permission system");
define("L_ERR_GROUP_CHANGE", "Can't change group");
define("L_A_UM_USERS_TIT", "User management - Users");
define("L_A_UM_GROUPS_TIT", "User management - Groups");
define("L_EDITGROUP_HDR", "Edit group");
define("L_NEWGROUP_HDR", "New group");
define("L_GROUP_ID", "Group Id");
define("L_ALL_USERS", "All Users");
define("L_GROUPS_USERS", "Group's Users");
define("L_POST", "Update");
define("L_POST_PREV", "Update & View");
define("L_INSERT_PREV", "Insert & View");
define("L_OK", "OK");
define("L_ACTIVE_BIN_EXPIRED", "Expired");
define("L_ACTIVE_BIN_PENDING", "Pending");
define("L_ACTIVE_BIN_EXPIRED_MENU", "Expired");
define("L_ACTIVE_BIN_PENDING_MENU", "Pending");

define("L_FIELD_PRIORITY", "Priority");
define("L_FIELD_TYPE", "Id");
define("L_CONSTANTS", "Constants");
define("L_DEFAULT", "Default");
define("L_DELETE_FIELD", "Do you really want to delete this field from this slice?");
define("L_FEEDED", "Fed");
define("L_HTML_DEFAULT", "HTML coded as default");
define("L_HTML_SHOW", "Show 'HTML' / 'plain text' option");
define("L_NEW_OWNER", "New Owner");
define("L_NEW_OWNER_EMAIL", "New Owner's E-mail");
define("L_NO_FIELDS", "No fields defined for this slice");
define("L_NO_FIELD", "");
define("L_NO_PERM_TO_FEED", "No permission to set feeding for any slice");
define("L_NO_SLICES", "No slices");
define("L_NO_TEMPLATES", "No templates");
define("L_OWNER", "Owner");
define("L_SLICES", "Slices");
define("L_TEMPLATE", "Template");
define("L_VALIDATE", "Validate");

define("L_FIELD_DELETE_OK", "Field delete OK");

define("L_WARNING_NOT_CHANGE","<p>WARNING: Do not change this setting if you are not sure what you're doing!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Function used for displaying in inputform. Some of them use the Constants,some of them use the Parameters. To get some more info, use the Wizard with Help.");
define("L_INPUT_SHOW_FUNC_C_HLP","Choose a Constant Group or a Slice.");
define("L_INPUT_SHOW_FUNC_HLP","Parameters are divided by double dot (:) or (in some special cases) by apostrophy (').");
define("L_INPUT_DEFAULT_F_HLP","Which function should be used as default:<BR>Now - default is current date<BR>User ID - current user ID<BR>Text - default is text in Parameter field<br>Date - as default is used current date plus <Parameter> number of days");
define("L_INPUT_DEFAULT_HLP","If default-type is Text, this sets the default text.<BR>If the default-type is Date, this sets the default date to the current date plus the number of days you set here.");

define("L_INPUT_DEFAULT_TXT", "Text");
define("L_INPUT_DEFAULT_DTE", "Date");
define("L_INPUT_DEFAULT_UID", "User ID");
define("L_INPUT_DEFAULT_LOG", "Login name");
define("L_INPUT_DEFAULT_NOW", "Now");
define("L_INPUT_DEFAULT_VAR", "Variable"); # Added by Ram on 5th March 2002 (Only for English)

define("L_INPUT_SHOW_TXT","Text Area");
define("L_INPUT_SHOW_EDT","Rich Edit Text Area");
define("L_INPUT_SHOW_FLD","Text Field");
define("L_INPUT_SHOW_SEL","Select Box");
define("L_INPUT_SHOW_RIO","Radio Button");
define("L_INPUT_SHOW_DTE","Date");
define("L_INPUT_SHOW_CHB","Check Box");
define("L_INPUT_SHOW_MCH", "Multiple Checkboxes");
define("L_INPUT_SHOW_MSE", "Multiple Selectbox");
define("L_INPUT_SHOW_FIL","File Upload");
define("L_INPUT_SHOW_ISI","Related Item Select Box");   # added 08/22/01
define("L_INPUT_SHOW_ISO","Related Item Window");       # added 08/22/01
define("L_INPUT_SHOW_WI2","Two Boxes");                 # added 08/22/01
define("L_INPUT_SHOW_PRE","Select Box with Presets");   # added 08/22/01
define("L_INPUT_SHOW_NUL","Do not show");
                              
define("L_INPUT_VALIDATE_TEXT","Text");
define("L_INPUT_VALIDATE_URL","URL");
define("L_INPUT_VALIDATE_EMAIL","E-mail");
define("L_INPUT_VALIDATE_NUMBER","Number");
define("L_INPUT_VALIDATE_ID","Id");
define("L_INPUT_VALIDATE_DATE","Date");
define("L_INPUT_VALIDATE_BOOL","Boolean");
define("L_INPUT_VALIDATE_USER","User");			# added 03/01/02, setu@gwtech.org

define("L_INPUT_INSERT_QTE","Text");
define("L_INPUT_INSERT_DTE","Date");
define("L_INPUT_INSERT_CNS","Constant");
define("L_INPUT_INSERT_NUM","Number");
define("L_INPUT_INSERT_IDS","Item IDs");
define("L_INPUT_INSERT_BOO","Boolean");
define("L_INPUT_INSERT_UID","User ID");
define("L_INPUT_INSERT_LOG", "Login name");
define("L_INPUT_INSERT_NOW","Now");
define("L_INPUT_INSERT_FIL","File");
define("L_INPUT_INSERT_NUL","None");

define("L_INPUT_DEFAULT","Default");
define("L_INPUT_BEFORE","Before HTML code");
define("L_INPUT_BEFORE_HLP","Code shown in input form before this field");
define("L_INPUT_FUNC","Input type");
define("L_INPUT_HELP","Help for this field");
define("L_INPUT_HELP_HLP","Shown help for this field");
define("L_INPUT_MOREHLP","More help");
define("L_INPUT_MOREHLP_HLP","Text shown after user click on '?' in input form");
define("L_INPUT_INSERT_HLP","This defines how the value is stored in the database.  Generally, use 'Text'.<BR>File will store an uploaded file.<BR>Now will insert the current time, no matter what the user sets.  Uid will insert the identity of the Current user, no matter what the user sets.  Boolean will store either 1 or 0.  ");
define("L_INPUT_VALIDATE_HLP","Validate function");

define("L_CONSTANT_NAME", "Name");
define("L_CONSTANT_VALUE", "Value");
define("L_CONSTANT_PRIORITY", "Priority");
define("L_CONSTANT_PRI", "Priority");
define("L_CONSTANT_GROUP", "Constant Group");
define("L_CONSTANT_GROUP_EXIST", "This constant group already exists");
define("L_CONSTANTS_OK", "Constants update successful");
define("L_A_CONSTANTS_TIT", "Admin - Constants Setting");
define("L_A_CONSTANTS_EDT", "Admin - Constants Setting");
define("L_CONSTANTS_HDR", "Constants");
define("L_CONSTANT_NAME_HLP", "shown&nbsp;on&nbsp;inputpage");
define("L_CONSTANT_VALUE_HLP", "stored&nbsp;in&nbsp;database");
define("L_CONSTANT_PRI_HLP", "constant&nbsp;order");
define("L_CONSTANT_CLASS", "Parent");
define("L_CONSTANT_CLASS_HLP", "categories&nbsp;only");
define("L_CONSTANT_DEL_HLP", "Remove constant name for its deletion");

$L_MONTH = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 
		'July', 'August', 'September', 'October', 'November', 'December');

define("L_NO_CATEGORY_FIELD","No category field defined in this slice.<br>Add category field to this slice first (see Field page).");
define("L_PERMIT_ANONYMOUS_POST","Allow anonymous posting of items");
define("L_PERMIT_OFFLINE_FILL","Allow off-line item filling");
define("L_SOME_CATEGORY", "<some category>");

define("L_ALIASES", "When you go to Admin-Design, you use an Alias to show this field");
define("L_ALIAS1", "Alias 1"); 
define("L_ALIAS_HLP", "Must begin with _#.<br>Alias must be exactly ten characters long including \"_#\".<br>Alias should be in upper case letters."); 
define("L_ALIAS_FUNC", "Function"); 
define("L_ALIAS_FUNC_F_HLP", "Function which handles the database field and displays it on page<BR>usually, use 'print'.<BR>"); 
define("L_ALIAS_FUNC_HLP", "Parameter passed to alias handling function. For detail see include/item.php3 file"); 
define("L_ALIAS_HELP", "Help text"); 
define("L_ALIAS_HELP_HLP", "Help text for the alias"); 
define("L_ALIAS2", "Alias 2"); 
define("L_ALIAS3", "Alias 3"); 

define("L_TOP_HLP", "HTML code which appears at the top of slice area");
define("L_FORMAT_HLP", "Put here the HTML code combined with aliases form bottom of this page
                     <br>The aliase will be substituted by real values from database when it will be posted to page");
define("L_BOTTOM_HLP", "HTML code which appears at the bottom of slice area");
define("L_EVEN_ROW_HLP", "You can define different code for odd and ever rows
                         <br>first red, second black, for example");

define("L_SLICE_URL", "URL of .shtml page (often leave blank)");
define( "L_BRACKETS_ERR", "Brackets doesn't match in query: ");
define("L_A_SLICE_ADD_HELP", "To create the new Slice, please choose a template.
        The new slice will inherit the template's default fields.  
        You can also choose a non-template slice to base the new slice on, 
        if it has the fields you want."); 
define("L_REMOVE_HLP", "Removes empty brackets etc. Use ## as delimeter.");

define("L_COMPACT_HELP", "Use these boxes ( and the tags listed below ) to control what appears on summary page");
define("L_A_FULLTEXT_HELP", "Use these boxes ( with the tags listed below ) to control what appears on full text view of each item");
define("L_PROHIBITED", "Not allowed");
define("L_HTML", "HTML");
define("L_PLAIN_TEXT", "Plain text");
define("L_A_DELSLICE", "Admin - Delete Slice");
define("L_DELSLICE_TIT", L_A_DELSLICE);
define("L_DELSLICE_HDR", "Select slice to delete");
define("L_DEL_SLICE_HLP","<p>You can delete only slices which are marked as &quot;<b>deleted</b>&quot; on &quot;<b>". L_SLICE_SET ."</b>&quot; page.</p>");
define("L_A_DELSLICE", L_DELSLICE_TIT);
define("L_DELETE_SLICE", "Do you really want to delete this slice and all its fields and all its items?");
define("L_NO_SLICE_TO_DELETE", "No slice marked for deletion");
define("L_NO_SUCH_SLICE", "Bad slice id");
define("L_NO_DELETED_SLICE", "Slice is not marked for deletion");
define("L_DELSLICE_OK", "Slice successfully deleted, tables are optimized");
define("L_DEL_SLICE", "Delete Slice");
define("L_FEED_STATE", "Feeding mode");
define("L_STATE_FEEDABLE", "Feed" );
define("L_STATE_UNFEEDABLE", "Do not feed" );
define("L_STATE_FEEDNOCHANGE", "Feed locked" );
define("L_INPUT_FEED_MODES_HLP", "Should the content of this field be copied to another slice if it is fed?");
define("L_CANT_CREATE_IMG_DIR","Can't create directory for image uploads");

  # constants for View setting 
define('L_VIEWS','Views');
define('L_ASCENDING','Ascending');
define('L_DESCENDING','Descending');
define('L_NO_PS_VIEWS','You do not have permission to change views');
define('L_VIEW_OK','View successfully changed');
define('L_A_VIEW_TIT','Admin - design View');
define('L_A_VIEWS','Admin - design View');
define('L_VIEWS_HDR','Defined Views');
define('L_VIEW_DELETE_OK','View successfully deleted');
define('L_DELETE_VIEW','Are you sure you want to delete selected view?');
define('L_V_BEFORE',L_COMPACT_TOP);
define('L_V_ODD',L_ODD_ROW_FORMAT);
define('L_V_EVENODDDIF',L_EVEN_ODD_DIFFER);
define('L_V_EVEN',L_EVEN_ROW_FORMAT);
define('L_V_AFTER',L_COMPACT_BOTTOM);
define('L_V_GROUP_BY1','Group by');
define('L_V_GROUP1DIR',' ');
define('L_V_GROUP_BY2',L_V_GROUP_BY1);
define('L_V_GROUP2DIR',' ');
define('L_V_GROUP','Group title format');
define('L_V_REMOVE_STRING',L_COMPACT_REMOVE);
define('L_V_MODIFICATION','Type');
define('L_V_PARAMETER','Parameter');
define('L_V_IMG1','View image 1');
define('L_V_IMG2','View image 2');
define('L_V_IMG3','View image 3');
define('L_V_IMG4','View image 4');
define('L_V_ORDER1','Sort primary');
define('L_V_ORDER1DIR',' ');
define('L_V_ORDER2','Sort secondary');
define('L_V_ORDER2DIR',' ');
define('L_V_SELECTED','HTML for Selected');
define('L_V_COND1FLD','Condition 1');
define('L_V_COND1OP',' ');
define('L_V_COND1COND',' ');
define('L_V_COND2FLD','Condition 2');
define('L_V_COND2OP',' ');
define('L_V_COND2COND',' ');
define('L_V_COND3FLD','Condition 3');
define('L_V_COND3OP',' ');
define('L_V_COND3COND',' ');
define('L_V_LISTLEN',L_D_LISTLEN);
define('L_V_FLAG','Flag');
define('L_V_SCROLLER','Display page scroller');
define('L_V_ADITIONAL','Additional');
define('L_COMPACT_VIEW','Item listing');
define('L_FULLTEXT_VIEW','Fulltext view');
define('L_DIGEST_VIEW','Item digest');
define('L_DISCUSSION_VIEW','Discussion');
define('L_RELATED_VIEW','Related item');
define('L_CONSTANT_VIEW','View of Constants');
define('L_RSS_VIEW','RSS exchange');
define('L_STATIC_VIEW','Static page');
define('L_SCRIPT_VIEW','Javascript item exchange');

define("L_MAP","Mapping");
define("L_MAP_TIT","Admin - Content Pooling - Fields' Mapping");
define("L_MAP_FIELDS","Fields' mapping");
define("L_MAP_TABTIT","Content Pooling - Fields' mapping");
define("L_MAP_FROM_SLICE","Mapping from slice");
define("L_MAP_FROM","From");
define("L_MAP_TO","To");
define("L_MAP_DUP","Cannot map to same field");
define("L_MAP_NOTMAP","-- Not map --");
define("L_MAP_OK","Fields' mapping update succesful");

define("L_STATE_FEEDABLE_UPDATE", "Feed & update" );
define("L_STATE_FEEDABLE_UPDATE_LOCKED", "Feed & update & lock" );

define("L_SITEM_ID_ALIAS",'alias for Short Item ID');
define("L_MAP_VALUE","-- Value --");
define("L_MAP_VALUE2","Value");
define("L_ORDER", "Order");
define("L_INSERT_AS_NEW","Insert as new");

// Constant view constants
define("L_CONST_NAME_ALIAS", "Constant name");
define("L_CONST_VALUE_ALIAS", "Constant value");
define("L_CONST_PRIORITY_ALIAS", "Constant priority");
define("L_CONST_GROUP_ALIAS", "Constant group id");
define("L_CONST_CLASS_ALIAS", "Category class (for categories only)");
define("L_CONST_COUNTER_ALIAS", "Constant number");
define("L_CONST_ID_ALIAS", "Constant unique id");

define('L_V_CONSTANT_GROUP','Constant Group');
define("L_NO_CONSTANT", "Constant not found");

// Discussion constants.
define("L_DISCUS_SEL","Show discussion");
define("L_DISCUS_EMPTY"," -- Empty -- ");
define("L_DISCUS_HTML_FORMAT","Use HTML tags");
define("L_EDITDISC_ALIAS",'Alias used on admin page index.php3 for edit discussion url');

define("L_D_SUBJECT_ALIAS","Alias for subject of the discussion comment");
define("L_D_BODY_ALIAS"," Alias for text of the discussion comment");
define("L_D_AUTHOR_ALIAS"," Alias for written by");
define("L_D_EMAIL_ALIAS","Alias for author's e-mail");
define("L_D_WWWURL_ALIAS","Alias for url address of author's www site");
define("L_D_WWWDES_ALIAS","Alias for description of author's www site");
define("L_D_DATE_ALIAS","Alias for publish date");
define("L_D_REMOTE_ADDR_ALIAS","Alias pro IP address of author's computer");
define("L_D_URLBODY_ALIAS","Alias for link to text of the discussion comment<br>
                             <i>Usage: </i>in HTML code for index view of the comment<br>
                             <i>Example: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>");
define("L_D_CHECKBOX_ALIAS","Alias for checkbox used for choosing discussion comment");
define("L_D_TREEIMGS_ALIAS","Alias for images");
define("L_D_ALL_COUNT_ALIAS","Alias for the number of all comments to the item");
define("L_D_APPROVED_COUNT_ALIAS","Alias for the number of approve comments to the item");
define("L_D_URLREPLY_ALIAS","Alias for link to a form<br>
                             <i>Usage: </i>in HTML code for fulltext view of the comment<br>
                             <i>Example: </i>&lt;a href=_#URLREPLY&gt;Reply&lt;/a&gt;");
define("L_D_URL","Alias for link to discussion<br>
                             <i>Usage: </i>in form code<br>
                             <i>Example: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">");
define("L_D_ID_ALIAS"," Alias for item ID<br>
                             <i>Usage: </i>in form code<br>
                             <i>Example: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">");
define("L_D_ITEM_ID_ALIAS"," Alias for comment ID<br>
                             <i>Usage: </i>in form code<br>
                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">");

define("L_D_BUTTONS","Alias for buttons Show all, Show selected, Add new<br>
                             <i>Usage: </i> in the Bottom HTML code");

define("L_D_COMPACT" , "HTML code for index view of the comment");
define("L_D_SHOWIMGS" , "Show images");
define("L_D_ORDER" , "Order by");
define("L_D_FULLTEXT" ,"HTML code for fulltext view of the comment");

define("L_D_ADMIN","Discussion comments management");
define("L_D_NODISCUS","No discussion comments");
define("L_D_TOPIC","Title");
define("L_D_AUTHOR","Author");
define("L_D_DATE","Date");
define("L_D_ACTIONS","Actions");
define("L_D_DELETE","Delete");
define("L_D_EDIT","Edit");
define("L_D_HIDE","Hide");
define("L_D_APPROVE","Approve");

define("L_D_EDITDISC","Items managment - Discussion comments managment - Edit comment");
define("L_D_EDITDISC_TABTIT","Edit comment");
define("L_D_SUBJECT","Subject");
define("L_D_AUTHOR","Author");
define("L_D_EMAIL","E-mail");
define("L_D_BODY","Text of discussion comment");
define("L_D_URL_ADDRESS","Authors's WWW  - URL");
define("L_D_URL_DES","Authors's WWW - description");
define("L_D_HOSTNAME","IP address of authors's computer");

define("L_D_SELECTED_NONE","No comment was selected");
define("L_D_DELETE_COMMENT","Are you sure you want to delete selected comment?");

define("L_D_FORM","HTML code of the form for posting comment");
define("L_D_ITEM","Item: ");

define("L_D_SHOW_SELECTED","Show selected");
define("L_D_SHOW_ALL","Show all");
define("L_D_ADD_NEW","Add new");

define("L_TOO_MUCH_RELATED","There are too much related items. The number of related items is limitted.");
define("L_SELECT_RELATED","Select related items");
define("L_SELECT_RELATED_1WAY","Add");
define("L_SELECT_RELATED_2WAY","Add&nbsp;mutual");

define("L_D_BACK","Back");
define("L_D_ADMIN2","Discussion comments managment");

define("L_INNER_IMPORT","Inner Node Feeding");
define("L_INTER_IMPORT","Inter Node Import");
define("L_INTER_EXPORT","Inter Node Export");

define("L_NODES_MANAGER","Nodes");
define("L_NO_PS_NODES_MANAGER","You have not permissions to manage nodes");
define("L_NODES_ADMIN_TIT","Remote node administration");
define("L_NODES_LIST","Known remote nodes");
define("L_NODES_ADD_NEW","Add new node");
define("L_NODES_EDIT","Edit node data");
define("L_NODES_NODE_NAME","Node name");
define("L_NODES_YOUR_NODE","Your node name");
define("L_NODES_SERVER_URL","URL of the getxml.php3");
define("L_NODES_YOUR_GETXML","Your getxml is");
define("L_NODES_PASWORD","Password");
define("L_SUBMIT","Submit");
define("L_NODES_SEL_NONE","No selected node");
define("L_NODES_CONFIRM_DELETE","Are you sure you want to delete the node?");
define("L_NODES_NODE_EMPTY","Node name must be filled");

define("L_IMPORT_TIT","Inter node import settings");
define("L_IMPORT_LIST","Existing remote imports into the slice ");
define("L_IMPORT_CONFIRM_DELETE","Are you sure you want to delete the import?");
define("L_IMPORT_SEL_NONE","No selected import");
define("L_IMPORT_NODES_LIST","All remote nodes");
define("L_IMPORT_CREATE","Create new feed from node");
define("L_IMPORT_NODE_SEL","No selected node");
define("L_IMPORT_SLICES","List of remote slices");
define("L_IMPORT_SLICES2","List of available slices from the node ");
define("L_IMPORT_SUBMIT","Choose slice");
define("L_IMPORT2_OK","The import was successfully created");
define("L_IMPORT2_ERR","The import was already created");

define("L_RSS_ERROR","Unable to connect and/or retrieve data from the remote node. Contact the administrator of the local node.");
define("L_RSS_ERROR2","Invalid password for the node name:");
define("L_RSS_ERROR3","Contact the administrator of the local node.");
define("L_RSS_ERROR4","No slices available. You have not permissions to import any data of that node. Contact ".
          "the administrator of the remote slice and check, that he obtained your correct username.");


define("L_EXPORT_TIT","Inter node export settings");
define("L_EXPORT_CONFIRM_DELETE","Are you sure you want to delete the export?");
define("L_EXPORT_SEL_NONE","No selected export");
define("L_EXPORT_LIST","Existing exports of the slice ");
define("L_EXPORT_ADD","Insert new item");
define("L_EXPORT_NAME","User name");
define("L_EXPORT_NODES","Remote Nodes");

define("L_RSS_TITL", "Title of Slice for RSS");
define("L_RSS_LINK", "Link to the Slice for RSS");
define("L_RSS_DESC", "Short description (owner and name) of slice for RSS");
define("L_RSS_DATE", "Date RSS information is generated, in RSS date format");

define("L_NO_PS_EXPORT_IMPORT", "You are not allowed to export / import slices");
define("L_EXPORT_SLICE", "Export");
define("L_IMPORT_SLICE", "Import");
define("L_EXPIMP_SET", "Slice structure");

define("L_E_EXPORT_TITLE", "Export slice structure");
define("L_E_EXPORT_MEMO", "Choose one of two export kinds:");
define("L_E_EXPORT_DESC", "When exporting \"to another ActionApps\" only the current slice will be exported "
		."and you choose its new identificator.");
define("L_E_EXPORT_DESC_BACKUP", "When exporting \"to Backup\" you may choose more slices at once.");
define("L_E_EXPORT_MEMO_ID","Choose a new slice identificator exactly 16 characters long: ");
define("L_E_EXPORT_SWITCH", "Export to Backup");
define("L_E_EXPORT_SWITCH_BACKUP", "Export to another ActionApps");
define("L_E_EXPORT_IDLENGTH", "The identificator should be 16 characters long, not ");
define("L_E_EXPORT_TEXT_LABEL", "Save this text. You may use it to import the slices into any ActionApps:");
define("L_E_EXPORT_LIST", "Select slices which you WANT to export:");

define("L_PARAM_WIZARD_LINK", "Wizard with help");
define("L_SHOW_RICH", "Show this field as a rich text editor (use only after having installed the necessary components!)");
define("L_MAP_JOIN","-- Joined fields --");

// aliases used in se_notify.php3 
define("L_NOTIFY_SUBJECT", "Subject of the Email message"); 
define("L_NOTIFY_BODY", "Body of the Email message"); 
define("L_NOTIFY_EMAILS", "Email addresses, one per line");
define("L_NOTIFY_HOLDING", "<h4>New Item in Holding Bin</h4> People can be notified by email when an item is created and put into the Holding Bin.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."); 
define("L_NOTIFY_HOLDING_EDIT", "<h4>Item Changed in Holding Bin</h4>  People can be notified by email when an item in the Holding Bin is modified.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."); 
define("L_NOTIFY_APPROVED", "<h4>New Item in Approved Bin</h4>  People can be notified by email when an item is created and put into the Approved Bin.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."); 
define("L_NOTIFY_APPROVED_EDIT", "<h4>Item Changed in Approved Bin</h4>  People can be notified by email when an item in the Approved Bin is modified.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive.");
define("L_NOTIFY", "Email Notification"); 
define("L_A_NOTIFY_TIT", "Email Notifications of Events");

define("L_NOITEM_MSG", "'No item found' message");
define("L_NOITEM_MSG_HLP", "message to show in place of slice.php3, if no item matches the query");

# ---------------- Users profiles -----------------------------------------
define('L_PROFILE','Profile');
define('L_DEFAULT_USER_PROFILE','Default user profile');
define('L_PROFILE_DELETE_OK','Rule deleted');
define('L_PROFILE_ADD_OK','Rule added');
define('L_PROFILE_ADD_ERR',"Error: Can't add rule");
define('L_PROFILE_LISTLEN','Item number');
define('L_PROFILE_ADMIN_SEARCH','Item filter');
define('L_PROFILE_ADMIN_ORDER','Item order');
define('L_PROFILE_HIDE','Hide field');
define('L_PROFILE_HIDEFILL','Hide and Fill');
define('L_PROFILE_FILL','Fill field');
define('L_PROFILE_PREDEFINE','Predefine field');
define('L_A_PROFILE_TIT','Admin - user Profiles');
define('L_PROFILE_HDR','Rules');
define('L_NO_RULE_SET','No rule is set');
define('L_PROFILE_ADD_HDR','Add Rule');
define('L_PROFILE_LISTLEN_DESC','number of item displayed in Item Manager');
define('L_PROFILE_ADMIN_SEARCH_DESC','preset "Search" in Itme Manager');
define('L_PROFILE_ADMIN_ORDER_DESC','preset "Order" in Itme Manager');
define('L_PROFILE_HIDE_DESC','hide the field in inputform');
define('L_PROFILE_HIDEFILL_DESC','hide the field in inputform and fill it by the value');
define('L_PROFILE_FILL_DESC','fill the field in inputform by the value');
define('L_PROFILE_PREDEFINE_DESC','predefine value of the field in inputform');
define('L_VALUE',L_MAP_VALUE2);
define('L_FUNCTION',L_ALIAS_FUNC);
define('L_RULE','Rule');

define('L_ID_COUNT_ALIAS','number of found items');

define("L_PARAM_WIZARD", "Param Wizard");

// constants used in param wizard only:
require  $GLOBALS[AA_INC_PATH]."en_param_wizard_lang.php3";
// new constants to be translated are here. Leave this "require" always at the 
// end of this file
require  $GLOBALS[AA_INC_PATH]."new_news_lang.php3"; ?>