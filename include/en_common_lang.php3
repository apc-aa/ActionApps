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

# common language file - comm

// setup constats
define("L_SETUP_PAGE_BEGIN", 
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
define("L_SETUP_TITLE", "AA Setup");
define("L_SETUP_H1", "AA Setup");
define("L_SETUP_NO_ACTION", "This script can't be used on a configured system.");
define("L_SETUP_INFO1", "Welcome! Use this script to create " .
                        "the superadmin account.<p>" .
      "If you are installing a new copy of AA, press <b>Init</b>.<br>");
define("L_SETUP_INFO2", "If you deleted your superadmin account by mistake, press <b>Recover</b>.<br>");
define("L_SETUP_INIT", " Init ");  
define("L_SETUP_RECOVER", "Recover");
define("L_SETUP_TRY_RECOVER", "Can't add primary permission object.<br>" .
       "Please check the access settings to your permission system.<br>" .
       "If you just deleted your superadmin account, use <b>Recover</b>");
define("L_SETUP_USER", "Superadmin account");
define("L_SETUP_LOGIN", "Login name");
define("L_SETUP_PWD1", "Password");
define("L_SETUP_PWD2", "Retype Password");
define("L_SETUP_FNAME", "First name");
define("L_SETUP_LNAME", "Last name");
define("L_SETUP_EMAIL", "E-mail");
define("L_SETUP_CREATE", "Create");
define("L_SETUP_DELPERM", "Invalid permission deleted (no such user/group): ");
define("L_SETUP_ERR_ADDPERM", "Can't assign super access permission.");
define("L_SETUP_ERR_DELPERM", "Can't delete invalid permission.");
define("L_SETUP_OK", "Congratulations! The account was created.");
define("L_SETUP_NEXT", "Use this account to login and add your first slice:");
define("L_SETUP_SLICE", "Add Slice");

// loginform language constants
define("L_LOGIN", "Welcome!");
define("L_LOGIN_TXT", "Welcome! Please identify yourself with a username and a password:");
define("L_LOGINNAME_TIP", "Type your username or mail");
define("L_SEARCH_TIP", "List is limitted to 5 users.<br>If some user is not in list, try to be more specific in your query");
define("L_USERNAME", "Username:");
define("L_PASSWORD", "Password:");
define("L_LOGINNOW", "Login now");
define("L_BAD_LOGIN", "Either your username or your password is not valid.");
define("L_TRY_AGAIN", "Please try again!");
define("L_BAD_HINT", "If you are sure you have typed the correct password, please e-mail <a href=mailto:". ERROR_REPORTING_EMAIL . ">" . ERROR_REPORTING_EMAIL . "</a>.");
define("LOGIN_PAGE_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
		
// scroller language constants
define("L_NEXT", "Next");
define("L_PREV", "Previous");
define("L_BACK", "Back");
define("L_HOME", "Home");

// permission language constants (perm_ldap.php3, perm_all.php3)
define("L_USER", "User");
define("L_GROUP", "Group");

// permission configuration constants um_uedit
define("L_NEW_USER", "New User");
define("L_NEW_GROUP", "New Group");
define("L_EDIT_GROUP", "Edit Group");

// application not specific strings
define("NO_PICTURE_URL", AA_INSTAL_URL ."images/pixel_blank.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)

define("L_ALLCTGS", "All categories");
define("L_NO_SUCH_FILE", "No such file");
define("L_BAD_INC", "Bad inc parameter - included file must be in the same directory as this .shtml file and must contain only alphanumeric characters");
define("L_SELECT_CATEGORY", "Select Category ");
define("L_NO_ITEM", "No item found");
define("L_VIEW_SLICE", "View site");
define("L_SLICE_INACCESSIBLE", "Invalid slice number or slice was deleted");
define("L_APP_TYPE", "Slice type");
define("L_SELECT_APP", "Select slice type");
define("L_APP_TYPE_HELP", "<br><br><br><br>");

define( "L_ICON_LEGEND", '');

define( "L_SLICE_HINT", '
                  <br>
                  To include slice in your webpage type next line to your shtml code:
                  ');


// log texts
define( "LOG_EVENTS_UNDEFINED", "Undefined" );

//transforms database date to human date format
function sec2userdate($timestamp, $format="") {
  if( !$format )
    $format = "m/d/Y";
	return date($format, $timestamp);
}

// tranformation from english style datum (3/16/1999 or 3/16/99) to mySQL date
// break year for short year description is 1950
function userdate2sec ($dttm, $time="") {
  if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $dttm, $part))
    if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $dttm, $part))
      return "";
  if( !ereg("^ *([[:digit:]]{1,2}) *: *([[:digit:]]{1,2}) *: *([[:digit:]]{1,2}) *$", $time, $tpart))
    return mktime(0,0,0,$part[1],$part[2],$part[3]);
   else
    return mktime($tpart[1],$tpart[2],$tpart[3],$part[1],$part[2],$part[3]);
}

function dateExample() {
	return "mm/dd/yyyy";
}

                   
/*
$Log$
Revision 1.13  2001/01/22 17:32:48  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.12  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.11  2000/12/05 14:01:19  honzam
Better help for upload image alias

Revision 1.10  2000/11/16 11:48:39  madebeer
11/16/00 a- changed admin leftbar menu order and labels
         b- changed default article editor field order & fields
         c- improved some of the english labels

Revision 1.9  2000/08/23 12:29:57  honzam
fixed security problem with inc parameter to slice.php3

Revision 1.8  2000/08/17 15:17:55  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.7  2000/08/15 16:20:40  kzajicek
en_common_lang.php3

Revision 1.6  2000/08/14 12:41:50  kzajicek
*** empty log message ***

Revision 1.5  2000/08/14 12:39:13  kzajicek
Language definitions required by setup.php3

Revision 1.4  2000/07/26 16:01:48  kzajicek
More descriptive message for "login failed"

Revision 1.3  2000/07/12 14:26:40  kzajicek
Poor printing of the SSI statement fixed

Revision 1.2  2000/07/03 15:00:14  honzam
Five table admin interface. 'New slice expiry date bug' fixed.

Revision 1.1.1.1  2000/06/21 18:40:31  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:15  madebeer
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
