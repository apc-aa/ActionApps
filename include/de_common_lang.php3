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

# common language file

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
define("L_LOGIN", "Willkommen bei den APC Action Aplications!");
define("L_LOGIN_TXT", "&nbsp;&nbsp;&nbsp; Bitte identifizieren Sie sich mit Benutzernamen (Username) und Passwort:");
define("L_LOGINNAME_TIP", "");
#define("L_SEARCH_TIP", "List is limitted to 5 users.<br>If some user is not in list, try to be more specific in your query");
define("L_SEARCH_TIP", "Die Liste ist auf 5 Namen begrenzt.<br>Falls der Name nicht auftaucht, bitte weiter eingrenzen");
define("L_USERNAME", "Benutzername:");
define("L_PASSWORD", "Passwort:");
define("L_LOGINNOW", "Bitte einloggen");
define("L_BAD_LOGIN", "Benutzername und/oder Passwort sind ungültig.");
define("L_TRY_AGAIN", "Bitte versuchen Sie es nocheinmal!");
define("L_BAD_HINT", "Wenn Sie sicher sind, die richtigen Daten eingegeben zu haben, wenden Sie sich an <a href=mailto:". ERROR_REPORTING_EMAIL . ">" . ERROR_REPORTING_EMAIL . "</a>");
define("LOGIN_PAGE_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
		
// scroller language constants
define("L_NEXT", "Nächste");
define("L_PREV", "Vorherige");
define("L_BACK", "Zurück");
define("L_HOME", "Home");

// permission language constants (perm_ldap.php3, perm_all.php3)
define("L_USER", "User");
define("L_GROUP", "Group");

// permission configuration constants um_uedit
define("L_NEW_USER", "Neue/r Benutzer/in");
define("L_NEW_GROUP", "Neue Gruppe");
define("L_EDIT_GROUP", "Gruppe bearbeiten");

// application not specific strings
define("NO_PICTURE_URL", AA_INSTAL_URL ."images/pixel_blank.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)

define("L_ALLCTGS", "Alle Kategorien");
define("L_NO_SUCH_FILE", "Datei nicht vorhanden");
define("L_BAD_INC", "Ungültiger Parameter - die Datei muss im selben Verzeichnis sein und darf nur alphanumerische Zeichen enthalten."); 
// "Bad inc parameter - included file must be in the same directory as this .shtml file and must contain only alphanumeric characters");
define("L_SELECT_CATEGORY", "Kategorie auswählen ");
define("L_NO_ITEM", "Kein Artikel gefunden");
define("L_SLICE_INACCESSIBLE", "Ungültige Slice (Rubrik)-Nr. oder Rubrik gelöscht");
define("L_APP_TYPE", "Art der Rubrik");
define("L_SELECT_APP", "Art der Rubrik auswählen");
define("L_APP_TYPE_HELP", "<br><br><br><br>");

// log texts
define( "LOG_EVENTS_UNDEFINED", "Nicht definiert" );

// offline filling --------------
define( "L_OFFLINE_ERR_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="./'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
  </HEAD>
  <BODY>');
define( "L_OFFLINE_OK_BEGIN",L_OFFLINE_ERR_BEGIN);
define( "L_OFFLINE_ERR_END","</body></html>");
define( "L_OFFLINE_OK_END",L_OFFLINE_ERR_END);
define( "L_NO_SLICE_ID","Rubrik (Slice ID) nicht definiert");
define( "L_NO_SUCH_SLICE","Ungültige Rubrik (Slice ID)");
define( "L_OFFLINE_ADMITED","Sie haben keine Berechtigung in diese Rubrik offline Daten einzugeben");
define( "L_WDDX_DUPLICATED","Artikel doppelt - übersprungen");
define( "L_WDDX_BAD_PACKET","Ungültige Daten (WDDX packet)");
define( "L_WDDX_OK","Artikel OK. In der Datenbank gespeichert.");
define( "L_CAN_DELETE_WDDX_FILE","Sie können jetzt die lokale Datei löschen.");
define( "L_DELETE_WDDX"," Löschen ");

// copyright message for all screens
define( "L_COPYRIGHT", 'Copyright (C) 2001 the 
						<a href="http://www.apc.org">Association for Progressive  Communications (APC)</a> 
						under the 
						<a href="http://www.gnu.org/copyleft/gpl.html">GNU General Public License</a>'); 

define("DEFAULT_CODEPAGE","iso-8859-1");

# ------------------- New constants (not in other lang files ------------------

?>
