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
define("CONFIG_FILE", "en_news_lang.php3");

define("IMG_UPLOAD_MAX_SIZE", "400000");    // max size of file in picture uploading
define("IMG_UPLOAD_URL", "http://web.ecn.cz/aauser/img_upload/");
define("IMG_UPLOAD_PATH", "/usr/local/httpd/htdocs/aauser/img_upload/");
define("EDITOR_GRAB_LEN", 200);                 // not used, i think
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
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
    
# aa toolkit specific labels
define("L_ITEM_ID_ALIAS",'alias for Item ID');
define("L_EDITITEM_ALIAS",'alias used on admin page index.php3 for itemedit url');
define("L_LANG_FILE","Used Language File");
define("L_PARAMETERS","Parameters");
define("L_SELECT_APP","Select application");
define("L_SELECT_OWNER","Select owner");

define("L_CANT_UPLOAD","Can't upload Image"); 
define("L_GRAB_LEN", "Fulltext into abstract grab length [characters]");
define("L_MSG_PAGE", "Toolkit news message");   // title of message page
define("L_EDITOR_TITLE", "Editor window - news management");
define("L_FULLTEXT_FORMAT_TOP", "Top HTML code");
define("L_FULLTEXT_FORMAT", "Fulltext HTML code");
define("L_FULLTEXT_FORMAT_BOTTOM", "Bottom HTML code");
define("L_A_FULLTEXT_TIT", "Slice Administration - News Fulltext Format");
define("L_FULLTEXT_HDR", "HTML code for fulltext view of news");
define("L_COMPACT_HDR", "HTML code for compact view of news");
define("L_ITEM_HDR", "News Data");
define("L_A_ITEM_ADD", "Add News");
define("L_A_ITEM_EDT", "Edit News");
define("L_IMP_EXPORT", "Enable export of news to slice:");
define("L_ADD_NEW_ITEM", "Add Article");
define("L_DELETE_TRASH", "Empty trash");
define("L_VIEW_FULLTEXT", "Preview");
define("L_FULLTEXT", "Public View: Fulltext");
define("L_HIGHLIGHTED", "Highlighted");
define("L_A_FIELDS_EDT", "Slice Administration - News Fields Settings");
define("L_FIELDS_HDR", "News fields");
define("L_NO_PS_EDIT_ITEMS", "You have not permissions to edit news in this slice");
define("L_NO_DELETE_ITEMS", "You have not permissions to remove news");
define("L_NO_PS_MOVE_ITEMS", "You have not permissions to move news");
define("L_FULLTEXT_OK", "Fulltext format update successful");
define("L_NO_ITEM", "No news matching your query.");

# aa toolkit common labels
# can be the same for all toolkit aplications
define("L_ACTIVE_BIN", "Approved");
define("L_HOLDING_BIN", "Holding bin");
define("L_TRASH_BIN", "Trash");

define("L_CATEGORY","Category");
define("L_SLICE_NAME", "Title");          // slice
define("L_DELETED", "Deleted");           // slice
define("L_D_LISTLEN", "Listing length");  // slice
define("L_ERR_CANT_CHANGE", "Can't change slice settings");
define("L_ODD_ROW_FORMAT", "Odd Rows");
define("L_EVEN_ROW_FORMAT", "Even Rows");
define("L_EVEN_ODD_DIFFER", "Use another HTML code for even rows");
define("L_CATEGORY_TOP", "Category top HTML");
define("L_CATEGORY_FORMAT", "Category Headline");
define("L_CATEGORY_BOTTOM", "Category bottom HTML");
define("L_CATEGORY_SORT", "Sort items by category");
define("L_COMPACT_TOP", "Top HTML");
define("L_COMPACT_BOTTOM", "Bottom HTML");
define("L_A_COMPACT_TIT", "Slice Administration - Compact view format");
define("L_A_FILTERS_TIT", "Slice Administration - Feeding filters setting");
define("L_FLT_SETTING", "Feeding import filters setting");
define("L_FLT_FROM_SL", "Filter for imported slice");
define("L_FLT_FROM", "From");
define("L_FLT_TO", "To");
define("L_FLT_APPROVED", "Approved");
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

define("L_FIELDS", "Author: Add Article");
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
define("L_SLICE_URL", "Slice URL");
define("L_A_NEWUSER", "New user in permission system");
define("L_NEWUSER_HDR", "New user");
define("L_USER_LOGIN", "Login name");
define("L_USER_PASSWORD1", "Password");
define("L_USER_PASSWORD2", "Retype password");
define("L_USER_FIRSTNAME", "First name");
define("L_USER_SURNAME", "Surname");
define("L_USER_MAIL", "E-mail");
define("L_USER_SUPER", "Superadmin account");
define("L_A_USERS_TIT", "Slice Administration - User Management");
define("L_A_PERMISSIONS", "Slice Administration - Permissions");
define("L_A_ADMIN", "Slice Administration - Administration Design");
define("L_A_ADMIN_TIT", "Slice Administration - Administration Design");
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
define("L_COMPACT", "Public View: Index");
define("L_COMPACT_REMOVE", "Remove strings");
define("L_FEEDING", "Feeding");
define("L_IMPORT", "Import & Export");
define("L_FILTERS", "Filters");

define("L_A_SLICE_ADD", "Slice Administration - Add Slice");
define("L_A_SLICE_EDT", "Slice Administration - Edit Slice");
define("L_A_SLICE_CAT", "Slice Administration - Category Assignment");
define("L_A_SLICE_IMP", "Slice Administration - Feeding Settings");
define("L_FIELD", "Field");
define("L_FIELD_IN_EDIT", "Show");
define("L_NEEDED_FIELD", "Required");
define("L_A_SEARCH_TIT", "Slice Administration - Search Form Settings");
define("L_SEARCH_HDR", "Search form criteria");
define("L_SEARCH_HDR2", "Search in fields");
define("L_SEARCH_SHOW", "Show");
define("L_SEARCH_DEFAULT", "Default settings");
define("L_SEARCH_SET", "Public View: Search");
define("L_AND", "AND");
define("L_OR", "OR");
define("L_SRCH_KW", "Search for");
define("L_SRCH_FROM", "From");
define("L_SRCH_TO", "To");
define("L_SRCH_SUBMIT", "Search");
define("L_NO_PS_EDIT", "You have not permissions to edit this slice");
define("L_NO_PS_ADD", "You have not permissions to add slice");
define("L_NO_PS_COPMPACT", "You have not permissions to change compact view formatting");
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
define("L_IMPORT_OK", "Feeding update successful");
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
define("L_LOGO", "APC toolkit");
define("L_USER_MANAGEMENT", "Users");
define("L_ITEMS", "Item management page");
define("L_NEW_SLICE_HEAD", "New slice");
define("L_ERR_USER_CHANGE", "Can't change user");
define("L_PUBLISHED", "Published");
define("L_EXPIRED", "Expired");
define("L_NOT_PUBLISHED", "Not published, yet");
define("L_EDIT_USER", "Edit User");
define("L_EDITUSER_HDR", L_EDIT_USER);

define("NO_PICTURE_URL", "http://web.ecn.cz/aauser/images/no_pict.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)
define("NO_SOURCE_URL", "javascript: window.alert('No source url specified')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('No outer url specified')");

# editors interface constants
define("L_PUBLISHED_HEAD", "Pub");
define("L_HIGHLIGHTED_HEAD", "&nbsp;!&nbsp;");
define("L_FEEDED_HEAD", "Fed");
define("L_MORE_DETAILS", "More details");
define("L_LESS_DETAILS", "Less details");
define("L_UNSELECT_ALL", "Unselect all");
define("L_SELECT_VISIBLE", "Select visible");
define("L_UNSELECT_VISIBLE", "Unselect visible");

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

//define("", "");   //prepared for new constants
define("DEFAULT_SLICE_CONFIG", "<wddxPacket version='0.9'><header/><data><struct><var name='admin_fields'><struct><var name='chbox'><struct><var name='width'><number>24</number></var></struct></var><var name='post_date'><struct><var name='width'><number>70</number></var></struct></var><var name='headline'><struct><var name='width'><number>224</number></var></struct></var><var name='catname'><struct><var name='width'><number>70</number></var></struct></var><var name='published'><struct><var name='width'><number>24</number></var></struct></var><var name='highlight'><struct><var name='width'><number>24</number></var></struct></var><var name='feed'><struct><var name='width'><number>24</number></var></struct></var></struct></var></struct></data></wddxPacket>");

define("L_FEED", "Export");
define("L_FEEDTO_TITLE", "Export Item to Selected Slice");
define("L_FEED_TO", "Export selected items to selected slice");
define("L_NO_PERMISSION_TO_FEED", "No permission");
define("L_NO_PS_CONFIG", "You have no permission to set configuration parameters of this slice");
define("L_SLICE_CONFIG", "Parameters");
define("L_CHBOX_HEAD", "&nbsp;");   // title of checkbox in admin interface
define("L_CHBOX", "Checkbox"); 
define("L_CATNAME", "Category Name");
define("L_CATEGORY_ID", "Category ID");
define("L_EDITED_BY","Edited by");
define("L_MASTER_ID", "Master id");
define("L_CHANGE_MARKED", "Change Marked");
define("L_MOVE_TO_ACTIVE_BIN", "Move to Approved");
define("L_MOVE_TO_HOLDING_BIN", "Move to Holding bin");
define("L_MOVE_TO_TRASH_BIN", "Move to Trash");
define("L_OTHER_ARTICLES", "Other Articles");
define("L_MISC", "Misc");
define("L_HEADLINE_EDIT", "Headline (edit on click)");
define("L_HEADLINE_PREVIEW", "Headline (preview on click)");
define("L_EDIT_LINK", "&nbsp;");
define("L_PREVIEW_LINK", "&nbsp;");
define("L_ARTICLE_MANAGER", "Article Manager");
define("L_SWITCH_TO", "Switch to slice");
define("L_ADMIN", "Admin");

//new_constants
define("L_NO_PS_NEW_USER", "No permission to create new user");
define("L_ALL_GROUPS", "All Groups");
define("L_USERS_GROUPS", "User's Groups");
define("L_REALY_DELETE_USER", "Are you sure to delete selected user from whole permission system?");
define("L_REALY_DELETE_GROUP", "Are you sure to delete selected group from whole permission system?");
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
define("L_POST", "Post");
define("L_POST_PREV", "Post & Preview");
define("L_OK", "OK");
define("L_ACTIVE_BIN_EXPIRED", "Approved - Expired");
define("L_ACTIVE_BIN_PENDING", "Approved - Pending");
define("L_ACTIVE_BIN_EXPIRED_MENU", "... expired");
define("L_ACTIVE_BIN_PENDING_MENU", "... pending");

define("L_FIELD_PRIORITY", "Priority");
define("L_FIELD_TYPE", "Type");
define("L_CONSTANTS", "Constants");
define("L_DEFAULT", "Default");
define("L_DELETE_FIELD", "Do you realy want to delete this field from this slice?");
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
define("", "");
define("", "");

define("L_FIELD_DELETE_OK", "Field delete OK");

define("L_WARNING_NOT_CHANGE","<p>WARNING: Do not change this setting if you are not sure what you do!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Function used for displaying in inputform. For some types you can use parameters, which follows.");
define("L_INPUT_SHOW_FUNC_C_HLP","Constants used with Select or Radio input function.");
define("L_INPUT_SHOW_FUNC_HLP","Parameter for use with Text (<number of rows>) or Date (<minus years>'<plus years>'<from now? >) input function.");
define("L_INPUT_DEFAULT_F_HLP","Which function should be used as default:<BR>Now - default is current date<BR>User ID - current user ID<BR>Text - default is text in Parameter field<br>Date - as default is used current date plus <Parameter> number of days");
define("L_INPUT_DEFAULT_HLP","Parameter for default Text and Date (see above)");

define("L_INPUT_DEFAULT_TXT", "Text");
define("L_INPUT_DEFAULT_DTE", "Date");
define("L_INPUT_DEFAULT_UID", "User ID");
define("L_INPUT_DEFAULT_NOW", "Now");

define("L_INPUT_SHOW_TXT","Text Area");
define("L_INPUT_SHOW_FLD","Text Field");
define("L_INPUT_SHOW_SEL","Select Box");
define("L_INPUT_SHOW_RIO","Radio Button");
define("L_INPUT_SHOW_DTE","Date");
define("L_INPUT_SHOW_CHB","Check Box");
define("L_INPUT_SHOW_FIL","File Upload");
define("L_INPUT_SHOW_NUL","Do not show");
                              
define("L_INPUT_VALIDATE_TEXT","Text");
define("L_INPUT_VALIDATE_URL","URL");
define("L_INPUT_VALIDATE_EMAIL","E-mail");
define("L_INPUT_VALIDATE_NUMBER","Number");
define("L_INPUT_VALIDATE_ID","Id");
define("L_INPUT_VALIDATE_DATE","Date");
define("L_INPUT_VALIDATE_BOOL","Boolean");

define("L_INPUT_INSERT_QTE","Text");
define("L_INPUT_INSERT_DTE","Date");
define("L_INPUT_INSERT_CNS","Constant");
define("L_INPUT_INSERT_NUM","Number");
define("L_INPUT_INSERT_BOO","Boolean");
define("L_INPUT_INSERT_UID","User ID");
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
define("L_INPUT_INSERT_HLP","Database insert function");
define("L_INPUT_VALIDATE_HLP","Validate function");

define("L_CONSTANT_NAME", "Name");
define("L_CONSTANT_VALUE", "Value");
define("L_CONSTANT_PRIORITY", "Priority");
define("L_CONSTANT_PRI", "Priority");
define("L_CONSTANT_GROUP", "Constant Group");
define("L_CONSTANT_GROUP_EXIST", "This constant group already exists");
define("L_CONSTANTS_OK", "Constants update successful");
define("L_A_CONSTANTS_TIT", "Slice Administration - Constants Setting");
define("L_A_CONSTANTS_EDT", "Slice Administration - Constants Setting");
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

// not appended to other lang files
//define("", "");

// ------------------------- New ----------------------------
    
/*
$Log$
Revision 1.20  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.19  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.18  2000/12/05 14:01:19  honzam
Better help for upload image alias

Revision 1.17  2000/11/16 11:48:39  madebeer
11/16/00 a- changed admin leftbar menu order and labels
         b- changed default article editor field order & fields
         c- improved some of the english labels

Revision 1.16  2000/11/13 10:41:14  honzam
Fixed bad order for default setting of show fields and needed fields

Revision 1.15  2000/10/12 15:56:09  honzam
Updated language files with better defaults

Revision 1.14  2000/10/11 20:18:29  honzam
Upadted database structure and language files for web.net's extended item table

Revision 1.13  2000/10/10 18:28:00  honzam
Support for Web.net's extended item table

Revision 1.12  2000/08/17 15:17:55  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.11  2000/08/15 08:58:31  kzajicek
Added missing L_HLP_CATEGORY_ID

Revision 1.10  2000/08/15 08:43:41  kzajicek
Fixed spelling error in constant name

Revision 1.9  2000/08/03 12:49:22  kzajicek
English editing

Revision 1.8  2000/08/03 12:34:27  honzam
Default values for new slice defined.

Revision 1.7  2000/07/27 18:17:21  kzajicek
Added superadmin settings in User/Group management

Revision 1.6  2000/07/27 13:23:58  kzajicek
Language correction

Revision 1.5  2000/07/17 13:40:11  kzajicek
Alert box when no input category selected

Revision 1.4  2000/07/17 12:29:56  kzajicek
Language changes

Revision 1.3  2000/07/12 11:06:26  kzajicek
names of image upload variables were a bit confusing

Revision 1.2  2000/07/03 15:00:14  honzam
Five table admin interface. 'New slice expiry date bug' fixed.

Revision 1.1.1.1  2000/06/21 18:40:33  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:19  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.12  2000/06/12 19:58:35  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.11  2000/06/09 15:14:11  honzama
New configurable admin interface

Revision 1.10  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.9  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.8  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
