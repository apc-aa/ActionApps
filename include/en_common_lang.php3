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

// loginform language constants
define("L_LOGIN", "Welcome!");
define("L_LOGIN_TXT", "Welcome! Please identify yourself with a username and a password:");
define("L_LOGINNAME_TIP", "Type your username or mail");
define("L_LOGINORG_TIP", "like econet.org -- (if you leave it blank, default is ".DEFAULT_ORG_ID.")");
define("L_SEARCH_TIP", "List is limitted to 5 users.<br>If some user is not in list, try to be more specific in your query");
define("L_USERNAME", "Username:");
define("L_PASSWORD", "Password:");
define("L_LOGINNOW", "Login now");
define("L_BAD_LOGIN", "Either your username or your password are invalid.");
define("L_TRY_AGAIN", "Please try again!");
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
define("L_ALLCTGS", "All categories");
define("L_SELECT_CATEGORY", "Select Category ");
define("L_NO_ITEM", "No item found");
define("L_VIEW_SLICE", "View site");
define("L_SLICE_INACCESSIBLE", "Invalid slice number or slice was deleted");
define("L_APP_TYPE", "Slice type");
define("L_SELECT_APP", "Select slice type");
define("L_APP_TYPE_HELP", "<small><br><br><br><br> You can easy create new slice type:<br><ul>
                           <li>create new application language file (see en_news_lang.php3)
                           <li>edit config.php3 and to add new app type:<br>
                            &nbsp; \$ActionAppConfig[en_news][name] = \"News\";<br>
                            &nbsp; \$ActionAppConfig[en_news][file] = \"en_news_lang.php3\";
                           </ul></small>");

define( "L_ICON_LEGEND", '
                  <br>
                  <i>Icon Legend</i>
                  <TABLE BORDER=1>
                  <TR>
                  <TD><img src="../images/notpubl.gif" width=24 height=24 border=0 alt=""> Pending item</TD>
                  <TD><img src="../images/publish.gif" width=24 height=24 border=0 alt=""> Published item</TD>
                  <TD><img src="../images/expired.gif" width=24 height=24 border=0 alt=""> Expired item</TD>
                  </TR>
                  <TR bgcolor="#FFCC66">
                  <TD><img src="../images/hlight.gif" border=0 alt=""> Highlighted item</TD>
                  <TD COLSPAN=2><img src="../images/feed.gif" border=0 alt=""> Item from feed</TD></TR>
                  <TR>
                  <TD><img src="../images/app.gif" border=0 alt=""> Approved bin</TD><TD>
                  <img src="../images/hold.gif" border=0 alt=""> Holding bin</TD><TD>
                  <img src="../images/trsh.gif" border=0 alt=""> Trash bin</TD></TR>
                  <TR bgcolor="#FFCC66">
                  <TD><img src="../images/less.gif" border=0 alt=""> Less detail view</TD>
                  <TD COLSPAN=2><img src="../images/more.gif" border=0 alt=""> More detail view</TD></TR>
                  </TABLE>
                  Categories in red do not belongs to this slice (they were fed ...)<br><br>
                  To include slice in your webpage type next line to your html code:<br><code>&lt;!--#include virtual=&quot;/aa/slice.php3?slice_id='. $slice_id .'&quot;--&gt;
                  </code>');
                           
//transforms my_sql date to human date format
function datetime2date ($dttm) {
	return ereg_replace("^([[:digit:]]{4})-([[:digit:]]{2})-([[:digit:]]{2}).*", 
		"\\2/\\3/\\1", $dttm);
}

// tranformation from english style datum (3/16/1999 or 3/16/99) to mySQL date
// break year for short year description is 1950
function date2datetime ($dttm) {
  if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $dttm, $part))
    if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $dttm, $part))
      return "";
     else
      $part[3] = ($part[3]<50 ? "20".$part[3] : "19".$part[3]);
	return $part[3] . "-" . $part[1] . "-" . $part[2];
}

function dateExample() {
	return "mm/dd/yyyy";
}

                   
/*
$Log$
Revision 1.1  2000/06/21 18:40:31  madebeer
Initial revision

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
