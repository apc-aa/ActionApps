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
# must correspond with fileneme in $ActionAppConfig[xxx][file]!!
define("CONFIG_FILE", "en_pr_lang.php3");

$l_month = array( 1 => 'January', 'February', 'March', 'April', 'May', 'June', 
		'July', 'August', 'September', 'October', 'November', 'December');

define("L_SEARCH_TIP", "List is limitted to 5 users.<br>If some user is not in list, try to be more specific in your query");
		
define("L_HEADLINE", "Press release");
define("L_POSTDATE", "Post date");
define("L_POSTED_BY", "Posted by");
define("L_PUBLISH_DATE", "Publish date");
define("L_EXPIRY_DATE", "Expiry date");
define("L_CATEGORY", "Category");
define("L_FIELDS", "Fields");
define("L_ABSTRACT", "Summary");
define("L_FULL_TEXT", "Full text");
define("L_STATUS_CODE", "Status");
define("L_LANGUAGE_CODE", "Language");
define("L_CP_CODE", "Encoding");
define("L_LINK_ONLY", "Outer link");
define("L_HL_HREF", "Outer link URL");
define("L_FT_FORMATTING", "Formatting");
define("L_FT_FORMATTING_HTML", "HTML tags");
define("L_FT_FORMATTING_PLAIN", "Plain text"); 
define("L_HTML_FORMATTED", "HTML formatted");
define("L_HIGHLIGHT", "Highlight");
define("L_IMG_SRC","Image URL"); 
define("L_IMG_WIDTH","Image width"); 
define("L_IMG_HEIGHT","Image height");
define("L_E_POSTED_BY","E-mail"); 
define("L_PLACE","Place");
define("L_SOURCE","Source ");
define("L_SOURCE_HREF","Source URL");
define("L_CREATED_BY","Author");
define("L_LASTEDIT","Last edited by");
define("L_AT","at");   
define("L_EDIT_NOTE","Editor's note"); 
define("L_ACTIVE_BIN", "Approved");
define("L_HOLDING_BIN", "Holding bin");
define("L_TRASH_BIN", "Trash");

define("L_SHORT_NAME", "Short name");
define("L_GRAB_LEN", "Fulltext into abstract grab length");
define("L_POST_ENABLED", "Posting enabled");
define("L_DELETED", "Deleted");
define("L_SLICE_DEFAULTS", "Default values for new items");
define("L_D_LANGUAGE_CODE", L_LANGUAGE_CODE);
define("L_D_CP_CODE", L_CP_CODE);
define("L_D_CATEGORY_ID", L_CATEGORY);
define("L_CATEGORY_ID", L_CATEGORY);
define("L_D_STATUS_CODE", L_STATUS_CODE);
define("L_D_HIGHLIGHT", L_HIGHLIGHT);
define("L_D_EXPIRY_LIMIT", "Expire limit [days]");
define("L_D_EXPIRY_DATE", L_EXPIRY_DATE);
define("L_D_HL_HREF", L_HL_HREF);
define("L_D_SOURCE", L_SOURCE);
define("L_D_SOURCE_HREF", L_SOURCE_HREF);
define("L_D_PLACE", L_PLACE);
define("L_D_LISTLEN", "Listing length");
define("L_D_HTML_FORMATTED", "HTML formated");
define("L_D_IMG_SRC", L_IMG_SRC);
define("L_D_IMG_WIDTH", L_IMG_WIDTH);
define("L_D_IMG_HEIGHT", L_IMG_HEIGHT);
define("L_D_POSTED_BY", L_POSTED_BY);
define("L_D_E_POSTED_BY", L_E_POSTED_BY);
define("L_D_LINK_ONLY", L_LINK_ONLY);

define("L_FULLTEXT_FORMAT", "Fulltext HTML code");
define("L_A_FULLTEXT_TIT", "Slice Administration - Fulltext format");
define("L_A_FULLTEXT", L_A_FULLTEXT_TIT);
define("L_ERR_CANT_CHANGE", "Can't change slice settings");
define("L_FULLTEXT_HDR", "HTML code for fulltext view of item");
define("L_CONSTANTS_HLP", "Use these aliases instead of database fields");

define("L_ODD_ROW_FORMAT", "Odd Rows");
define("L_EVEN_ROW_FORMAT", "Even Rows");
define("L_EVEN_ODD_DIFFER", "Use another HTML code for even rows");
define("L_CATEGORY_FORMAT", "Category Headline");
define("L_CATEGORY_SORT", "Sort items by category");
define("L_COMPACT_TOP", "Top HTML");
define("L_COMPACT_BOTTOM", "Bottom HTML");
define("L_A_COMPACT_TIT", "Slice Administration - Compact view format");
define("L_A_COMPACT", L_A_COMPACT_TIT);
define("L_COMPACT_HDR", "HTML code for compact view of items");

define("L_A_FILTERS_TIT", "Slice Administration - Feeding filters setting");
define("L_A_FILTERS_FLT", L_A_FILTERS_TIT);
define("L_FLT_SETTING", "Feeding import filters setting");
define("L_FLT_FROM_SL", "Filter for imported slice");
define("L_FLT_FROM", "From");
define("L_FLT_TO", "To");
define("L_FLT_APPROVED", "Approved");
define("L_FLT_CATEGORIES", "Categories");
define("L_ALL_CATEGORIES", "All Categories");
define("L_FLT_NONE", "No From category selected!");
define("L_THE_SAME", "-- The same --");

define("L_ITEM_HDR", "Item Data");
define("L_A_ITEM_ADD", "Add Item");
define("L_A_ITEM_EDT", "Edit Item");
define("L_IMP_EXPORT", "Enable export of items to slice:");
define("L_IMP_EXPORT_Y", "Export enable");
define("L_IMP_EXPORT_N", "Export disable");
define("L_EXPORT_TO_ALL", "Enable export to any slice");
define("L_IMP_IMPORT", "Import items from slice:");
define("L_IMP_IMPORT_Y", "Import");
define("L_IMP_IMPORT_N", "Do not import");

//define("", "");

define("L_RELOGIN", "Logout and login as another user");
define("L_ADD_NEW_ITEM", "Add new item");

define("L_ERR_DATE", "Enter a valid date!");
define("L_ERR_HEADLINE", "Headline must be filled in!");
define("L_ERR_ABSTRACT", "Summary must be filled in!");
define("L_ERR_IN", "Error in");
define("L_ERR_NEED", "it must be filled");
define("L_ERR_LOG", "you should use a-z, A-Z and 0-9 characters");
define("L_ERR_LOGLEN", "it must by 5 - 32 characters long");
define("L_ERR_FEEDED_ITEMS", "There is an item in trash bin, which is fed. It is imposible to delete it.");
define("L_ERR_NO_SRCHFLDS", "No searchfield specified!");
define("L_NO_PRMS_SLICE", "You have no permissions for changing slice");

define("L_EDIT", "Edit");
define("L_EDIT_SLICE", "Edit Slice");
define("L_EDIT_ITEMS", "Edit Items");
define("L_DELETE", "Delete");
define("L_UPDATE", "Update");
define("L_RESET", "Reset form");
define("L_CANCEL", "Cancel");
define("L_ACTION", "Action");
define("L_INSERT", "Insert");
define("L_VIEW", "View");
define("L_BACK", "Back");
define("L_HOME", "Home");
define("L_NEW", "New");
define("L_GO", "Go");
define("L_ADD", "ADD");
define("L_USERS", "Users");
define("L_GROUPS", "Groups");
define("L_ORGANIZATION", "Organization");
define("L_SEARCH", "Search");
define("L_RENAME", "Rename");
define("L_DEFAULTS", "Default");
define("L_DELETE_TRASH", "Delete items in trash bin");
define("L_SLICE", "Slice");
define("L_DELETED_SLICE", "Specified slice was deleted");
define("L_SLICE_URL", "Slice URL");
define("L_CURRENT_USERS", "Current Users");
define("L_VIEW_FULLTEXT", "Preview");
define("L_A_NEWUSER", "New user in permission system");
define("L_NEWUSER_HDR", "New user");
define("L_USER_LOGIN", "Login name");
define("L_USER_PASSWORD1", "Password");
define("L_USER_PASSWORD2", "Retype password");
define("L_USER_FIRSTNAME", "First name");
define("L_USER_SURNAME", "Surname");
define("L_USER_MAIL", "E-mail");
define("L_A_USERS_TIT", "Slice Administration - User Management");

define("L_ROLE_AUTHOR", "Author");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administrator");
define("L_ROLE_SUPER", "Super");

define("L_SLICE_ADM", "Slice Administration");
define("L_MAIN_SET", "Main settings");
define("L_SLICE_SET", L_SLICE);
define("L_PERMISSIONS", "Permissions");
define("L_PERM_CHANGE", "Change");
define("L_PERM_ASSIGN", "Assign");
define("L_PERM_NEW", "Search user or group");
define("L_PERM_SEARCH", "Assign new permissions");
define("L_PERM_CURRENT", "Change current permissions");
define("L_USER_NEW", "New User");
define("L_DESIGN", "Design");
define("L_COMPACT", "Compact");
define("L_COMPACT_REMOVE", "Remove strings");
define("L_FULLTEXT_REMOVE", L_COMPACT_REMOVE);
define("L_FULLTEXT", "Full Text");
define("L_FEEDING", "Feeding");
define("L_IMPORT", "Import & Export");
define("L_FILTERS", "Filters");
define("L_HIGHLIGHTED", "Highlighted");
define("L_NO_HIGHLIGHTED", "No highlighted");

define("L_A_SLICE_ADD", "Slice Administration - Add Slice");
define("L_A_SLICE_EDT", "Slice Administration - Edit Slice");
define("L_A_SLICE_CAT", "Slice Administration - Category Assignment");
define("L_A_SLICE_IMP", "Slice Administration - Feeding Settings");
define("L_A_SLICE_USERS", "Slice Administration - User Management");
define("L_A_FIELDS_EDT", "Slice Administration - Item Fields Settings");
define("L_A_FIELDS_TIT", L_A_FIELDS_EDT);
define("L_A_SLICE_TIT", L_SLICE_ADM);
define("L_FIELD", "Field");
define("L_FIELD_IN_EDIT", "Show");
define("L_NEEDED_FIELD", "Needed");
define("L_FIELDS_HDR", "Item fields");
define("L_A_SLICE_TIT", L_SLICE_ADM);
define("L_A_SEARCH_TIT", "Slice Administration - Search Form Settings");
define("L_A_SEARCH_EDT", L_A_SEARCH_TIT);

define("L_SLICES_HDR", L_SLICE);

define("L_SEARCH_HDR", "Search form criteria");
define("L_SEARCH_HDR2", "Search in fields");
define("L_SEARCH_SHOW", "Show");
define("L_SEARCH_DEFAULT", "Default settings");
define("L_SEARCH_SET", "Search form");

define("L_NO_PRMS_SLICE", "You have not permissions to add/edit slice");
define("L_NO_PS_EDIT_ITEMS", "You have not permissions to edit items in this slice");
define("L_NO_DELETE_ITEMS", "You have not permissions to remove items");
define("L_NO_PS_MOVE_ITEMS", "You have not permissions to move items");
define("L_NO_PS_EDIT", "You have not permissions to edit this slice");
define("L_NO_PS_ADD", "You have not permissions to add slice");
define("L_NO_PS_COPMPACT", "You have not permissions to change compact view formatting");
define("L_NO_PS_FULLTEXT", "You have not permissions to change fulltext formatting");
define("L_NO_PS_CATEGORY", "You have not permissions to change category settings");
define("L_NO_PS_FEEDING", "You have not permissions to change feeding setting");
define("L_NO_PS_USERS", "You have not permissions to manage users");
define("L_NO_PS_FIELDS", "You have not permissions to change fields settings");
define("L_NO_PS_SEARCH", "You have not permissions to change search settings");
define("L_PS_NO_NEW_USER", "You have not permissions to create new user");

define("L_BAD_RETYPED_PWD", "Retyped password is not the same as the first one");
define("L_ERR_USER_ADD", "It is impossible to add user to permission system - LDAP error");
define("L_NEWUSER_OK", "User successfully added to permission system");
define("L_COMPACT_OK", "Design of compact design successfully changed");

define("L_NEEDED", "Must be filled");

define("L_ALL", " - all - ");
define("L_CAT_LIST", "Slice Categories");
define("L_CAT_SELECT", "This Slice Categories");
define("L_NEW_CATEG", "Enter the name for the new category");
define("L_NEW_SLICE", "Add Slice");
define("L_SLICE_NEW", "New Slice");
define("L_RENAME_CATEG", "Enter the new name for this category");
define("L_ASSIGN", "Assign");
define("L_CATBINDS_OK", "Category update succesfull");
define("L_IMPORT_OK", "Feeding update succesfull");
define("L_FULLTEXT_OK", "Fulltext format update succesfull");
define("L_FIELDS_OK", "Fields update succesfull");
define("L_SEARCH_OK", "Search fields update succesfull");
define("L_ADMINPAGE", "Back to Main Administration Page");
define("L_NO_CATEGORY", "No category defined");
define("L_NO_IMPORTED_SLICE", "There are no imported slices");
define("L_NO_USERS", "No user (group) found");
define("L_AND", "AND");
define("L_OR", "OR");
define("L_SRCH_ALL", L_ALL);
define("L_SRCH_KW", "Search for");
define("L_SRCH_SLICE", L_SLICE);
define("L_SRCH_CATEGORY", L_CATEGORY);
define("L_SRCH_AUTHOR", L_CREATED_BY);
define("L_SRCH_LANGUAGE", L_LANGUAGE_CODE);
define("L_SRCH_FROM", "From");
define("L_SRCH_TO", "To");
define("L_SRCH_HEADLINE", L_HEADLINE);
define("L_SRCH_ABSTRACT", L_ABSTRACT);
define("L_SRCH_FULL_TEXT", L_FULL_TEXT);
define("L_SRCH_EDIT_NOTE", L_EDIT_NOTE);
define("L_SRCH_SUBMIT", "Search");

define("DEFAULT_FULLTEXT_HTML", '<BR><FONT SIZE=+2 COLOR=blue>_#HEADLINE</FONT> <BR><B>_#PUB_DATE</B> <BR>_#FULLTEXT');
define("DEFAULT_ODD_HTML", '<font face=Arial color=#808080 size=-2>_#PUB_DATE - </font><font color=#FF0000><strong><a href=_#HDLN_URL>_#HEADLINE</strong></font><font color=#808080 size=-1></a><br>_#PLACE###(<a href="_#SRC_URL#">_#SOURCE##</a>) - </font><font color=black size=-1>_#ABSTRACT<br></font><br>');
define("DEFAULT_EVEN_HTML", "");
define("DEFAULT_TOP_HTML", "<br>");
define("DEFAULT_BOTTOM_HTML", "<br>");
define("DEFAULT_CATEGORY_HTML", "<p>_#CATEGORY</p>");
define("DEFAULT_EVEN_ODD_DIFFER", false);
define("DEFAULT_CATEGORY_SORT", true);
define("DEFAULT_COMPACT_REMOVE", "()");
define("DEFAULT_FULLTEXT_REMOVE", "()");
/*
$Log$
Revision 1.3  2000/07/17 13:40:11  kzajicek
Alert box when no input category selected

Revision 1.2  2000/07/17 12:29:56  kzajicek
Language changes

Revision 1.1.1.1  2000/06/21 18:40:34  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:20  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.8  2000/06/12 19:58:35  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.7  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.6  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.5  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
