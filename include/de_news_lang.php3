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
define("CONFIG_FILE", "de_news_lang.php3");

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
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/DE">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">');
    
# aa toolkit specific labels
define("L_VIEW_SLICE", "Vorschau");
define( "L_SLICE_HINT", '<br>Um diesen Slice in Ihre Webseiten einzubauen,
												 fügen Sie folgendes in Ihre SHTML-Seite ein: '); 
define("L_ITEM_ID_ALIAS",'alias for Item ID');
define("L_EDITITEM_ALIAS",'alias used on admin page index.php3 for itemedit url');
define("L_LANG_FILE","Sprach-Datei");
define("L_PARAMETERS","Parameter");
define("L_SELECT_APP","Anwendung auswählen");
define("L_SELECT_OWNER","Eigentümer auswählen");

define("L_CANT_UPLOAD","Kann Bild nicht hochladen"); 
define("L_MSG_PAGE", "Toolkit news message");   // title of message page
define("L_EDITOR_TITLE", "Bearbeiten Artikel Verwaltung");
define("L_FULLTEXT_FORMAT_TOP", "HTML-Ccode oben");
define("L_FULLTEXT_FORMAT", "HTML-Code Volltext");
define("L_FULLTEXT_FORMAT_BOTTOM", "HTML-Code unten");
define("L_A_FULLTEXT_TIT", "Verwaltung - Volltext-Ansicht gestalten");
define("L_FULLTEXT_HDR", "HTML-Code für Volltext-Ansicht");
define("L_COMPACT_HDR", "HTML-Code für Index-Ansicht");
define("L_ITEM_HDR", "Artikel bearbeiten");
define("L_A_ITEM_ADD", "Artikel hinzufügen");
define("L_A_ITEM_EDT", "Artikel bearbeiten");
define("L_IMP_", "Exportieren nach:");
define("L_ADD_NEW_ITEM", "Neuer Artikel");
define("L_DELETE_TRASH", "Papierkorb leeren");
define("L_VIEW_FULLTEXT", "Vorschau");
define("L_FULLTEXT", "Volltext");
define("L_HIGHLIGHTED", "Hervorgehoben");
define("L_A_FIELDS_EDT", "Verwaltung - Felder konfigurieren");
define("L_FIELDS_HDR", "Felder");
define("L_NO_PS_EDIT_ITEMS", "Sie haben keine Berechtigung, Artikel in dieser Rubrik zu bearbeiten.");
// "You do not have permission to edit items in this slice");
define("L_NO_DELETE_ITEMS", "Sie haben keine Berechtigung, Artikel zu löschen.");
define("L_NO_PS_MOVE_ITEMS", "Sie haben keine Berechtigung, Artikel zu verschieben.");
define("L_FULLTEXT_OK", "Volltext-Format erfolgreich geändert.");

# aa toolkit common labels
# can be the same for all toolkit aplications
define("L_ACTIVE_BIN", "Aktiv");
define("L_HOLDING_BIN", "Hold bin");
define("L_TRASH_BIN", "Papierkorb");

define("L_CATEGORY","Kategorien");
define("L_SLICE_NAME", "Titel");          // slice
define("L_DELETED", "Gelöscht");           // slice
define("L_D_LISTLEN", "Listenlänge");  // slice
define("L_ERR_CANT_CHANGE", "Kann Rubrik-Einstellungen nicht verändern");
define("L_ODD_ROW_FORMAT", "Ungerade Zeilen");
define("L_EVEN_ROW_FORMAT", "Gerade Zeilen");
define("L_EVEN_ODD_DIFFER", "Anderen HTML-Code für gerade Zeilen verwenden");
define("L_CATEGORY_TOP", "Kategorie HTML Oben");
define("L_CATEGORY_FORMAT", "Kategorie Überschrift");
define("L_CATEGORY_BOTTOM", "Kategorie HTML Unten");
define("L_CATEGORY_SORT", "Sortiere Artikel nach Kategorie");
define("L_COMPACT_TOP", "HTML Oben");
define("L_COMPACT_BOTTOM", "HTML Unten");
define("L_A_COMPACT_TIT", "Verwaltung - Inhaltsansicht gestalten");
define("L_A_FILTERS_TIT", "Verwaltung - Datenaustausch - Filter");
define("L_FLT_SETTING", "Datenaustausch - Filter konfigurieren");
define("L_FLT_FROM_SL", "Filter für importierte Rubrik");
define("L_FLT_FROM", "Von");
define("L_FLT_TO", "Nach");
define("L_FLT_APPROVED", "Aktiv");
define("L_FLT_CATEGORIES", "Kategorien");
define("L_ALL_CATEGORIES", "Alle Kategorien");
define("L_FLT_NONE", "Keine Von-Kategorie ausgewählt!");
define("L_THE_SAME", "-- Dieselbe --");
define("L_EXPORT_TO_ALL", "Ermögliche Export zu jeder Rubrik");

define("L_IMP_EXPORT",   "Export nach Rubrik:");
define("L_IMP_EXPORT_Y", "Export ermöglichen");
define("L_IMP_EXPORT_N", "Export verbieten");
define("L_IMP_IMPORT",   "Importiere von Rubrik:");
define("L_IMP_IMPORT_Y", "Importieren");
define("L_IMP_IMPORT_N", "Nicht Importieren");
define("L_CONSTANTS_HLP","Benutze diese Aliase für Datenbankfelder");

define("L_ERR_IN", "Fehler in");
define("L_ERR_NEED", "muss ausgefüllt werden");
define("L_ERR_LOG", "bitte nur a-z, A-Z und 0-9 verwenden");
define("L_ERR_LOGLEN", "muss 5 - 32 Zeichen lang sein");
define("L_ERR_NO_SRCHFLDS", "Kein Suchfeld angegeben!");

define("L_FIELDS", "Felder");
define("L_EDIT", "Editieren");
define("L_DELETE", "Löschen");
define("L_REVOKE", "Zurücksetzen");
define("L_UPDATE", "Aktualisieren");
define("L_RESET", "Zurücksetzen");
define("L_CANCEL", "Abbruch");
define("L_ACTION", "Aktion");
define("L_INSERT", "Einfügen");
define("L_NEW", "Neu");
define("L_GO", "Los!");
define("L_ADD", "Hinzufügen");
define("L_USERS", "Benutzer");
define("L_GROUPS", "Gruppen");
define("L_SEARCH", "Suchen");
define("L_DEFAULTS", "Standard");
define("L_SLICE", "Rubrik");
define("L_DELETED_SLICE", "Keine Rubrik für Sie gefunden");
define("L_A_NEWUSER", "Neuer Benutzer in Veraltungssystem");
define("L_NEWUSER_HDR", "Neuer Benutzer");
define("L_USER_LOGIN", "Benutzername");
define("L_USER_PASSWORD1", "Passwort");
define("L_USER_PASSWORD2", "Passwort wiederholen");
define("L_USER_FIRSTNAME", "Vorname");
define("L_USER_SURNAME", "Nachname");
define("L_USER_MAIL", "E-Mail-Adresse");
define("L_USER_SUPER", "Superadmin");
define("L_A_USERS_TIT", "Admin - User Management");
define("L_A_PERMISSIONS", "Admin - Permissions");
define("L_A_ADMIN", "Admin - design Item Manager view");
define("L_A_ADMIN_TIT", "Admin - design Item Manager view");
define("L_ADMIN_FORMAT", "Artikel-Format");
define("L_ADMIN_FORMAT_BOTTOM", "HTML Unten");
define("L_ADMIN_FORMAT_TOP", "HTML Oben");
define("L_ADMIN_HDR", "Listing of items in Admin interface");
define("L_ADMIN_OK", "Admin fields update successful");
define("L_ADMIN_REMOVE", "Remove strings");

define("L_ROLE_AUTHOR", "Author");
define("L_ROLE_EDITOR", "Herausgeber");
define("L_ROLE_ADMINISTRATOR", "Verwalter");
define("L_ROLE_SUPER", "SuperAdmin");

define("L_MAIN_SET", "Haupt-Einstellungen");
define("L_PERMISSIONS", "Berechtigungen");
define("L_PERM_CHANGE", "Ändern");
define("L_PERM_ASSIGN", "Zuweisen");
define("L_PERM_NEW", "Benutzer oder Gruppe suchen");
define("L_PERM_SEARCH", "Neue Berechtigungen zuweisen");
define("L_PERM_CURRENT", "Berechtigungen ändern");
define("L_USER_NEW", "Neuer Benutzer");
define("L_DESIGN", "Design ändern");
define("L_COMPACT", "Index");
define("L_COMPACT_REMOVE", "Zeichenketten entfernen");
define("L_FEEDING", "Datenaustausch");
define("L_IMPORT", "Partner");
define("L_FILTERS", "Filter");

define("L_A_SLICE_ADD", "Slice hinzufügen");
define("L_A_SLICE_EDT", "Verwaltung - Rubrik-Einstellungen");
define("L_A_SLICE_CAT", "Verwaltung - Kategorien konfigurieren ");
define("L_A_SLICE_IMP", "Verwaltung - Datenaustausch konfigurieren");
define("L_FIELD", "Feld");
define("L_FIELD_IN_EDIT", "Zeigen");
define("L_NEEDED_FIELD", "Benötigt");
define("L_A_SEARCH_TIT", "Verwaltung - Suchformular konfigurieren");
define("L_SEARCH_HDR", "Such-Kriterien");
define("L_SEARCH_HDR2", "Suchen in Feldern:");
define("L_SEARCH_SHOW", "Zeige");
define("L_SEARCH_DEFAULT", "Standard-Einstellungen");
define("L_SEARCH_SET", "Suche");
define("L_AND", "UND");
define("L_OR", "ODER");
define("L_SRCH_KW", "Suche nache");
define("L_SRCH_FROM", "Von");
define("L_SRCH_TO", "An");
define("L_SRCH_SUBMIT", "Suchen");
define("L_NO_PS_EDIT", "Sie haben keine Berechtigung zum Bearbeiten dieser Rubrik");
define("L_NO_PS_ADD", "Sie haben keine Berechtigung, Rubriken hinzuzufügen");
define("L_NO_PS_COMPACT", "Sie haben keine Berechtigung zum Bearbeiten der Index-Ansicht");
define("L_NO_PS_FULLTEXT", "Sie haben keine Berechtigung zum Bearbeiten der Volltext-Ansicht");
define("L_NO_PS_CATEGORY", "Sie haben keine Berechtigung zum Bearbeiten der Kategorien");
define("L_NO_PS_FEEDING", "Sie haben keine Berechtigung zum Bearbeiten des Pooling");
define("L_NO_PS_USERS", "Sie haben keine Berechtigung, um Benutzer/innen zu bearbeiten");
define("L_NO_PS_FIELDS", "Sie haben keine Berechtigung, um Felder zu bearbeiten");
define("L_NO_PS_SEARCH", "Sie haben keine Berechtigung, um Suchen zu bearbeiten");

define("L_BAD_RETYPED_PWD", "Die Passworte stimmen nicht überein");
// "Retyped password is not the same as the first one");
define("L_ERR_USER_ADD", "Benutzer/in kann nicht hinzugefügt werden");
// "It is impossible to add user to permission system");
define("L_NEWUSER_OK", "Benutzer/in erfolgreich hinzugefügt.");
define("L_COMPACT_OK", "Design der Inhaltsübersicht erfolgreich geändert");
define("L_BAD_ITEM_ID", "Ungültige Artikel-ID");
define("L_ALL", " - alle - ");
define("L_CAT_LIST", "Kategorien der Rubrik");
define("L_CAT_SELECT", "Kategorien dieser Rubrik");
define("L_NEW_SLICE", "Rubrik hinzufügen");
define("L_ASSIGN", "Zuweisen");
define("L_CATBINDS_OK", "Kategorie erfolgreich aktualisiert");
define("L_IMPORT_OK", "Datenaustausch erfolgreich aktualisert");
define("L_FIELDS_OK", "Felder erfolgreich aktualisiert");
define("L_SEARCH_OK", "Suchfelder erfolgreich aktualisiert");
define("L_NO_CATEGORY", "Keine Kategorie definiert");
define("L_NO_IMPORTED_SLICE", "Keine importierten Rubriken");
define("L_NO_USERS", "Keine Benutzer oder Gruppe gefunden");

define("L_TOO_MUCH_USERS", "Zu viele Benutzer oder Gruppen gefunden.");
define("L_MORE_SPECIFIC", "Bitte genauer spezifizieren.");
define("L_REMOVE", "Entfernen");
define("L_ID", "Id");
define("L_SETTINGS", "Verwaltung");
define("L_LOGO", "APC Action Applications");
define("L_USER_MANAGEMENT", "Benutzer");
define("L_ITEMS", "Artikel");
define("L_NEW_SLICE_HEAD", "Neue Rubrik");
define("L_ERR_USER_CHANGE", "Kann Benutzer nicht verändern");
define("L_PUBLISHED", "Veröffentlicht");
define("L_EXPIRED", "Abgelaufen");
define("L_NOT_PUBLISHED", "Noch nicht veröffentlicht");
define("L_EDIT_USER", "Benutzer editieren");
define("L_EDITUSER_HDR", L_EDIT_USER);

define("NO_SOURCE_URL", "javascript: window.alert('Kein URL angegeben')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('Keine Outer-URL angegeben')");

# editors interface constants
define("L_PUBLISHED_HEAD", "Aktiv");
define("L_HIGHLIGHTED_HEAD", "&nbsp;!&nbsp;");
define("L_FEEDED_HEAD", "Fed");
define("L_MORE_DETAILS", "Mehr Details");
define("L_LESS_DETAILS", "Weniger Details");
define("L_UNSELECT_ALL", "Alle abwählen");
define("L_SELECT_VISIBLE", "Alle auswählen");
define("L_UNSELECT_VISIBLE", "Alle abwählen");

define("L_SLICE_ADM", "Rubrik-Verwaltung");
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
define("L_FEEDTO_TITLE", "Exportiere Artikel in ausgewählte Rubrik");
define("L_FEED_TO", "Exportiere ausgewählte Artikel in ausgewählte Rubrik");
define("L_NO_PERMISSION_TO_FEED", "Keine Berechtigung");
define("L_NO_PS_CONFIG", "Sie haben keine Berechtigung um die Einstellungen dieser Rubrik zu ändern");
define("L_SLICE_CONFIG", "Artikel-Verwaltung");
define("L_CHBOX_HEAD", "&nbsp;");   // title of checkbox in admin interface
define("L_CHBOX", "Checkbox"); 
define("L_CATNAME", "Name der Kategorie");
define("L_CATEGORY_ID", "Kategorie ID");
define("L_EDITED_BY","Bearbeitet von");
define("L_MASTER_ID", "Master id");
define("L_CHANGE_MARKED", "Ausgewählte Artikel");
define("L_MOVE_TO_ACTIVE_BIN", "Veröffentlichen (Aktiv)");
define("L_MOVE_TO_HOLDING_BIN", "In 'Hold Bin' legen");
define("L_MOVE_TO_TRASH_BIN", "In Papierkorb werfen");
define("L_OTHER_ARTICLES", "Ordner");
define("L_MISC", "Sonstiges");
define("L_HEADLINE_EDIT", "Überschrift (Bearbeiten)");
define("L_HEADLINE_PREVIEW", "Überschrift (Vorschau)");
define("L_EDIT_LINK", "&nbsp;");
define("L_PREVIEW_LINK", "&nbsp;");
define("L_ARTICLE_MANAGER", "Artikel");
define("L_SWITCH_TO", "Gehe zu:");
define("L_ADMIN", "Verwaltung");

define("L_NO_PS_NEW_USER", "Keine Berechtigung, um einen neuen Benutzer anzulegen.");
define("L_ALL_GROUPS", "Alle Gruppen");
define("L_USERS_GROUPS", "Gruppen des/der Benutzer/in");
define("L_REALY_DELETE_USER","Sind Sie sicher, dass Sie diese/n Benutzer/in aus dem System entfernen wollen?"); 
// "Are you sure you want to delete selected user from whole permission system?");
define("L_REALY_DELETE_GROUP","Sind Sie sicher, dass Sie diese Gruppe aus dem System entfernen wollen?"); 
// "Are you sure you want to delete selected group from whole permission system?");
define("L_TOO_MUCH_GROUPS", "Zu viele Gruppen gefunden.");
define("L_NO_GROUPS", "Keine Gruppen gefunden.");
define("L_GROUP_NAME", "Name");
define("L_GROUP_DESCRIPTION", "Beschreibung");
define("L_GROUP_SUPER", "Superadmin-Gruppe");
define("L_ERR_GROUP_ADD", "Kann Gruppe nicht hinzufügen.");
define("L_NEWGROUP_OK", "Gruppe erfolgreich hinzugefügt.");
define("L_ERR_GROUP_CHANGE", "Kann Gruppe nicht verändern.");
define("L_A_UM_USERS_TIT", "Benutzer/innen-Verwaltung");
define("L_A_UM_GROUPS_TIT", "Gruppen-Verwaltung");
define("L_EDITGROUP_HDR", "Gruppe bearbeiten");
define("L_NEWGROUP_HDR", "Neue Gruppe");
define("L_GROUP_ID", "Gruppen-Id");
define("L_ALL_USERS", "Alle Benutzer/innnen");
define("L_GROUPS_USERS", "Benutzer/innen dieser Gruppe");
define("L_POST", "OK");
define("L_POST_PREV", "OK & Vorschau");
define("L_OK", "OK");
define("L_ACTIVE_BIN_EXPIRED", "Abgelaufen");
define("L_ACTIVE_BIN_PENDING", "Wartend");
define("L_ACTIVE_BIN_EXPIRED_MENU", "Abgelaufen");
define("L_ACTIVE_BIN_PENDING_MENU", "Wartend");

define("L_FIELD_PRIORITY", "Priorität");
define("L_FIELD_TYPE", "Id");
define("L_CONSTANTS", "Konstanten");
define("L_DEFAULT", "Vorbelegung");
define("L_DELETE_FIELD", "Wollen Sie dieses Feld wirklich aus dieser Rubrik löschen?");
define("L_FEEDED", "Exportiert");
define("L_HTML_DEFAULT", "Standard: HTML-codiert");
define("L_HTML_SHOW", "Zeige Auswahl 'HTML' / 'normaler Text'");
define("L_NEW_OWNER", "Neuer Inhaber");
define("L_NEW_OWNER_EMAIL", "E-Mail des Inhabers");
define("L_NO_FIELDS", "Keine Felder für diese Rurik definiert");
define("L_NO_FIELD", "");
define("L_NO_PERM_TO_FEED", "Keine Berechtigung um Export zu definieren");
define("L_NO_SLICES", "Keine Rubriken");
define("L_NO_TEMPLATES", "Keine Vorlagen");
define("L_OWNER", "Inhaber");
define("L_SLICES", "Rubriken");
define("L_TEMPLATE", "Vorlagen");
define("L_VALIDATE", "Überprüfen");

define("L_FIELD_DELETE_OK", "OK, Feld löschen!");

define("L_WARNING_NOT_CHANGE","<p>WARNUNG: Ändern Sie diese Einstellungen nicht, wenn Sie nicht wissen was Sie tun!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Funktion um dieses Feld in der Eingabemaske zu zeigen. Einige erlauben folgende Parameter:");
// Function used for displaying in inputform. For some types you can use parameters, which follows.");
define("L_INPUT_SHOW_FUNC_C_HLP","Vorgaben für Auswahl-Knöpfe");
// Constants used with Select or Radio input function.");
define("L_INPUT_SHOW_FUNC_HLP","Textfeld - Anzahl der Zeilen<br>Textfeld - Maximal und angezeigte Länge (Vorgabe 255:60)."); 
// Text Area - number of rows.<br>Text Field - maxlength and size (default is 255 : 60).");
define("L_INPUT_DEFAULT_F_HLP","<b>Vorbelegung des Feldes</b>
														   <BR>Text: Text aus Parameter-Feld
															 <BR>Date: Aktuelles Datum plus x Tage aus Parameter-Feld
															 <BR>User ID: Benutzer ID
															 <BR>Now: Aktuelles Datum");
// Which function should be used as default:<BR>Now - default is current date<BR>User ID - current user ID<BR>Text - default is text in Parameter field<br>Date - as default is used current date plus <Parameter> number of days");
define("L_INPUT_DEFAULT_HLP","Wenn oben 'Text': Text-Vorbelegung hier eingeben
                          <BR>Wenn oben 'Date': Datum plus hier angegebene Tage."); 
// If default-type is Text, this sets the default text.<BR>If the default-type is Date, this sets the default date to the current date plus the number of days you set here.");

define("L_INPUT_DEFAULT_TXT", "Text");
define("L_INPUT_DEFAULT_DTE", "Datum");
define("L_INPUT_DEFAULT_UID", "Benutzer ID");
define("L_INPUT_DEFAULT_NOW", "Jetzt");

define("L_INPUT_SHOW_TXT","Textfeld");
define("L_INPUT_SHOW_FLD","Eingabezeile");
define("L_INPUT_SHOW_SEL","Auswahlbox");
define("L_INPUT_SHOW_RIO","Auswahlknöpfe");
define("L_INPUT_SHOW_DTE","Datum");
define("L_INPUT_SHOW_CHB","Check Box");
define("L_INPUT_SHOW_MCH", "Mehrfache Checkbox");
define("L_INPUT_SHOW_MSE", "Mehrfache Auswahlbox");
define("L_INPUT_SHOW_FIL","Datei Upload");
define("L_INPUT_SHOW_ISI","Related Item Select Box");   # added 08/22/01
define("L_INPUT_SHOW_ISO","Related Item Window");       # added 08/22/01
define("L_INPUT_SHOW_WI2","Zwei Kästchen");                 # added 08/22/01
define("L_INPUT_SHOW_PRE","Auswahlbox mit Voreinstellung");   # added 08/22/01
define("L_INPUT_SHOW_NUL","Nicht anzeigen");
                              
define("L_INPUT_VALIDATE_TEXT","Text");
define("L_INPUT_VALIDATE_URL","URL");
define("L_INPUT_VALIDATE_EMAIL","E-Mail");
define("L_INPUT_VALIDATE_NUMBER","Zahl");
define("L_INPUT_VALIDATE_ID","Id");
define("L_INPUT_VALIDATE_DATE","Datum");
define("L_INPUT_VALIDATE_BOOL","Ja/Nein");

define("L_INPUT_INSERT_QTE","Text");
define("L_INPUT_INSERT_DTE","Datum");
define("L_INPUT_INSERT_CNS","Konstante");
define("L_INPUT_INSERT_NUM","Zahl");
define("L_INPUT_INSERT_IDS","Artikel IDs");
define("L_INPUT_INSERT_BOO","Ja/Nein");
define("L_INPUT_INSERT_UID","Benutzer ID");
define("L_INPUT_INSERT_NOW","Jetzt");
define("L_INPUT_INSERT_FIL","Datei");
define("L_INPUT_INSERT_NUL","Keine");

define("L_INPUT_DEFAULT","Standard");
define("L_INPUT_BEFORE","Vor HTML-Code");
define("L_INPUT_BEFORE_HLP","Code shown in input form before this field");
define("L_INPUT_FUNC","Eingabe Typ");
define("L_INPUT_HELP","Hilfe für dieses Feld");
define("L_INPUT_HELP_HLP","Für dieses Feld gezeigte Hilfe");
define("L_INPUT_MOREHLP","Mehr Hilfe");
define("L_INPUT_MOREHLP_HLP","Text shown after user click on '?' in input form");
define("L_INPUT_INSERT_HLP",
"Definition, wie der Wert in der Datenbank gespeichert wird. Im allg. 'Text' benutzen.
<BR>'File' für Datei-Upload.
<BR>'Now': aktuelles Datum ohne Berücksichtigung der Eingaben.
<BR>'Uid': aktuelle Benutzer-ID ohne Berücksichtigung der Eingaben.
<BR>'Boolean': 1 oder 0 (Ja/Nein)."); 
/* This defines how the value is stored in the database.  Generally, use 'Text'.
<BR>File will store an uploaded file.<BR>Now will insert the current time, 
no matter what the user sets.  Uid will insert the identity of the Current user,
no matter what the user sets.  Boolean will store either 1 or 0.  ");*/
define("L_INPUT_VALIDATE_HLP","Eingabe-Überprüfung");

define("L_CONSTANT_NAME", "Name");
define("L_CONSTANT_VALUE", "Wert");
define("L_CONSTANT_PRIORITY", "Priorität");
define("L_CONSTANT_PRI", "Priorität");
define("L_CONSTANT_GROUP", "Constant Group");
define("L_CONSTANT_GROUP_EXIST", "This constant group already exists");
define("L_CONSTANTS_OK", "Constants update successful");
define("L_A_CONSTANTS_TIT", "Verwaltung - Constants Setting");
define("L_A_CONSTANTS_EDT", "Verwaltung - Constants Setting");
define("L_CONSTANTS_HDR", "Constants");
define("L_CONSTANT_NAME_HLP", "shown&nbsp;on&nbsp;inputpage");
define("L_CONSTANT_VALUE_HLP", "stored&nbsp;in&nbsp;database");
define("L_CONSTANT_PRI_HLP", "constant&nbsp;order");
define("L_CONSTANT_CLASS", "Parent");
define("L_CONSTANT_CLASS_HLP", "categories&nbsp;only");
define("L_CONSTANT_DEL_HLP", "Remove constant name for its deletion");

$L_MONTH = array( 1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 
		'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember');

define("L_NO_CATEGORY_FIELD","Keine Kategorie definiert.
													<BR>Bitte zuerst eine Kategorie hinzufügen (Seite 'Felder')");
//No category field defined in this slice.<br>Add category field to this slice first (see Field page).");
define("L_PERMIT_ANONYMOUS_POST","Anonyme Artikel erlauben");
// Allow anonymous posting of items");
define("L_PERMIT_OFFLINE_FILL","Off-line Artikel erlauben");
define("L_SOME_CATEGORY", "<Kategorie eingeben>");

define("L_ALIAS_FUNC_A", "f_a - Kurzfassung");
define("L_ALIAS_FUNC_B", "f_b - Link zum Volltext");
define("L_ALIAS_FUNC_C", "f_c - Bedingung");
define("L_ALIAS_FUNC_D", "f_d - Datum");
define("L_ALIAS_FUNC_E", "f_e - Text bearbeiten");
define("L_ALIAS_FUNC_F", "f_f - Link zum Volltext");
define("L_ALIAS_FUNC_G", "f_g - Bildhöhe");
define("L_ALIAS_FUNC_H", "f_h - Ausgeben");
define("L_ALIAS_FUNC_I", "f_i - Bildquelle [image src]");
define("L_ALIAS_FUNC_L", "f_l - Link");
define("L_ALIAS_FUNC_M", "f_m - e-mail");
define("L_ALIAS_FUNC_N", "f_n - id");
define("L_ALIAS_FUNC_Q", "f_q - Text aus anderer Rubrik");
define("L_ALIAS_FUNC_R", "f_r - rss util");
define("L_ALIAS_FUNC_S", "f_s - URL");
define("L_ALIAS_FUNC_T", "f_t - Volltext");
define("L_ALIAS_FUNC_U", "f_u - Benutzerdefinierte Funktion");
define("L_ALIAS_FUNC_V", "f_v - Zeige Ansicht Nr.");
define("L_ALIAS_FUNC_W", "f_w - Bildbreite");
define("L_ALIAS_FUNC_0", "f_0 - Nichts");

define("L_ALIASES", "Unter 'Design ändern' folgende 'Aliase' benutzen, um dieses Feld zu zeigen");
define("L_ALIAS1", "Alias 1"); 
define("L_ALIAS_HLP", "Der Alias muss mit _# beginnen und insgesamt genau 10 Zeichen lang sein. ".
                      "Bitte nur GROSSBUCHSTABEN oder # verwenden.");
// Must begin with _#.<br>Alias must be exactly ten characters long including \"_#\".
//<br>Alias should be in upper case letters."); 
define("L_ALIAS_FUNC", "Funktion"); 
define("L_ALIAS_FUNC_F_HLP", "Funktion, die das Feld auf der Seite anzeigt. 
  												<BR>Im allgemeinen verwenden Sie 'Anzeigen'.<BR>"); 
// Funktion which handles the database field and displays it on page<BR>usually, use 'print'.<BR>"); 
define("L_ALIAS_FUNC_HLP", "Parameter für die Funktion. Einzelheiten siehe Anleitung (FAQ)");
// Parameter passed to alias handling function. For detail see include/item.php3 file"); 
define("L_ALIAS_HELP", "Hilfe-Text"); 
define("L_ALIAS_HELP_HLP", "Erläuterungen zu diesem Alias"); 
define("L_ALIAS2", "Alias 2"); 
define("L_ALIAS3", "Alias 3"); 

define("L_TOP_HLP", "HTML-Code an Anfang (oben)");
define("L_FORMAT_HLP", "Hier den HTML-Code mit den Aliases von dieser Seite eingeben. ".
									     "Die Aliase werden durch den Inhalt der Felder ersetzt, wenn die Seite gezeigt wird.");
/* Put here the HTML-Code combined with aliases form bottom of this page
                     <br>The aliase will be substituted by real values from database when it will be posted to page");
*/										 
define("L_BOTTOM_HLP", "HTML-Code am Ende (unten)");
define("L_EVEN_ROW_HLP", "Sie können verschiedenen HTML-Code für ungerade und ". 
											   "gerade Zeilen verwenden, z.B. für wechselnde Hintergundfarben");

define("L_SLICE_URL", "URL der .shtml-Seite (Kann leer bleiben)");
define( "L_BRACKETS_ERR", "Klammern passen nicht zusammen");
// Brackets doesn't match in query: ");
define("L_A_SLICE_ADD_HELP", "Bitte eine Vorlage auswählen, um eine neue Rubrik einzurichten. ".
														 "Die neue Rubrik enthält die Vorgabe-Felder der Vorlage. ".
														 "Sie können auch eine existierende Rubrik kopieren, wenn sie ". 
														 "die gewünschten Felder enthält.");
/* To create the new Slice, please choose a template.
        The new slice will inherit the template's default fields.  
        You can also choose a non-template slice to base the new slice on, 
        if it has the fields you want."); */ 
define("L_REMOVE_HLP", "Entfernt z.B. leere Klammern (). ## als Begrenzer verwenden.");
// Removes empty brackets etc. Use ## as delimeter.");

define("L_COMPACT_HELP", "Design der Index-(Übersichts-)Seite bearbeiten.");
// Use these boxes ( and the tags listed below ) to control what appears on summary page");
define("L_A_FULLTEXT_HELP", "Design der Volltext-Ansicht bearbeiten."); 
// "Use these boxes ( with the tags listed below ) to control what appears on full text view of each item");
define("L_PROHIBITED", "Nicht erlaubt");
define("L_HTML", "HTML");
define("L_PLAIN_TEXT", "Normaler Text");
define("L_A_DELSLICE", "Verwaltung - Rubrik löschen");
define("L_DELSLICE_TIT", L_A_DELSLICE);
define("L_DELSLICE_HDR", "Rubrik zum Löschen auswählen");
define("L_DEL_SLICE_HLP", "Sie können nur Rubriken entfernen, die auf der &quot;<b>".
        L_SLICE_SET ."</b>&quot;-Seite als &quot;<b>gelöscht</b>&quot; markiert sind.<p>");
//"<p>You can delete only slices which are marked as &quot;<b>deleted</b>&quot; on &quot;<b>". L_SLICE_SET ."</b>&quot; page.</p>");

define("L_A_DELSLICE", L_DELSLICE_TIT);
define("L_DELETE_SLICE", "Wollen Sie wirklich diese Rubrik mit allen Feldern und Artikeln löschen?"); 
//"Do you really want to delete this slice and all its fields and all its items?");
define("L_NO_SLICE_TO_DELETE", "Keine Rubrik zum Löschen markiert.");
define("L_NO_SUCH_SLICE", "Ungültige Rubrik (Slice-Id)");
define("L_NO_DELETED_SLICE", "Rubrik ist nicht als &quot;gelöscht&quot; markiert");
define("L_DELSLICE_OK", "Rubrik erfolgreich gelöscht.");
define("L_DEL_SLICE", "Rubrik löschen");
define("L_FEED_STATE", "Export-Modus"); 
// "Feeding mode");
define("L_STATE_FEEDABLE", "Exportieren" );
define("L_STATE_UNFEEDABLE", "Nicht exportieren" );
define("L_STATE_FEEDNOCHANGE", "Exportieren und Sperren" );
define("L_INPUT_FEED_MODES_HLP", "Soll der Inhalt dieses Feldes beim Austausch exportiert werden?"); 
// "Should the content of this field be copied to another slice if it is fed?");

define("L_CANT_CREATE_IMG_DIR", "Kann Verzeichnis für Datei/Bild-Upload nicht anlegen.");

  # constants for View setting 
define('L_VIEWS','Ansicht');
define('L_ASCENDING','Aufsteigend');
define('L_DESCENDING','Absteigend');
define('L_NO_PS_VIEWS','Sie haben keine Berechtigung, Ansichten zu verändern');
define('L_VIEW_OK','Ansicht erfolgreich bearbeitet');
define('L_A_VIEW_TIT','Verwaltung - Absicht bearbeiten');
define('L_A_VIEWS','Verwaltung - Ansicht bearbeiten');
define('L_VIEWS_HDR','Ansicht');
define('L_VIEW_DELETE_OK','Ansicht gelöscht');
define('L_DELETE_VIEW','Wollen Sie wirklich die ausgewählte Ansicht löschen?');
define('L_V_BEFORE',L_COMPACT_TOP);
define('L_V_ODD',L_ODD_ROW_FORMAT);
define('L_V_EVENODDDIF',L_EVEN_ODD_DIFFER);
define('L_V_EVEN',L_EVEN_ROW_FORMAT);
define('L_V_AFTER',L_COMPACT_BOTTOM);
define('L_V_GROUP_BY1','Gruppierung');
define('L_V_GROUP1DIR',' ');
define('L_V_GROUP_BY2',L_V_GROUP_BY1);
define('L_V_GROUP2DIR',' ');
define('L_V_GROUP','Gruppierung Überschrift');
define('L_V_REMOVE_STRING',L_COMPACT_REMOVE);
define('L_V_MODIFICATION','Typ');
define('L_V_PARAMETER','Parameter');
define('L_V_IMG1','View image 1');
define('L_V_IMG2','View image 2');
define('L_V_IMG3','View image 3');
define('L_V_IMG4','View image 4');
define('L_V_ORDER1','1. Sortierung');
define('L_V_ORDER1DIR',' ');
define('L_V_ORDER2','2. Sortierung');
define('L_V_ORDER2DIR',' ');
define('L_V_SELECTED','HTML für ausgewählte');
define('L_V_COND1FLD','Bedingung 1');
define('L_V_COND1OP',' ');
define('L_V_COND1COND',' ');
define('L_V_COND2FLD','Bedingung 2');
define('L_V_COND2OP',' ');
define('L_V_COND2COND',' ');
define('L_V_COND3FLD','Bedingung 3');
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
define("L_MAP_TIT","Verwaltung - Datenaustausch - Feldzuordnung");
define("L_MAP_FIELDS","Feldzuordnung");
define("L_MAP_TABTIT","Datenaustausch - Feldzuordnung");
define("L_MAP_FROM_SLICE","Mapping from slice");
define("L_MAP_FROM","Von");
define("L_MAP_TO","Nach");
define("L_MAP_DUP","Cannot map to same field");
define("L_MAP_NOTMAP","-- Not map --");
define("L_MAP_OK","Feldzuordnung erfolgreich aktualisiert");

define("L_STATE_FEEDABLE_UPDATE", "Exportieren & Aktualisieren" );
define("L_STATE_FEEDABLE_UPDATE_LOCKED", "Exportieren, Aktualisieren & Sperren" );

define("L_SITEM_ID_ALIAS",'Alias für kurze Artikel-ID');
define("L_MAP_VALUE","-- Wert --");
define("L_MAP_VALUE2","Wert");
define("L_ORDER", "Reihenfolge");
define("L_INSERT_AS_NEW","Als 'neu' einsetzen");

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
                             <i>Usage: </i>in HTML-Code for index view of the comment<br>
                             <i>Example: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>");
define("L_D_CHECKBOX_ALIAS","Alias for checkbox used for choosing discussion comment");
define("L_D_TREEIMGS_ALIAS","Alias for images");
define("L_D_ALL_COUNT_ALIAS","Alias for the number of all comments to the item");
define("L_D_APPROVED_COUNT_ALIAS","Alias for the number of approve comments to the item");
define("L_D_URLREPLY_ALIAS","Alias for link to a form<br>
                             <i>Usage: </i>in HTML-Code for fulltext view of the comment<br>
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
                             <i>Usage: </i> in the Bottom HTML-Code");

define("L_D_COMPACT" , "HTML-Code for index view of the comment");
define("L_D_SHOWIMGS" , "Show images");
define("L_D_ORDER" , "Order by");
define("L_D_FULLTEXT" ,"HTML-Code for fulltext view of the comment");

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

define("L_D_FORM","HTML-Code of the form for posting comment");
define("L_D_ITEM","Item: ");

define("L_D_SHOW_SELECTED","Zeige ausgewählte");
define("L_D_SHOW_ALL","Zeige alle");
define("L_D_ADD_NEW","Hinzufügen");

define("L_TOO_MUCH_RELATED","There are too much related items. The number of related items is limitted.");
define("L_SELECT_RELATED","Select related items");
define("L_SELECT_RELATED_1WAY","Add");
define("L_SELECT_RELATED_2WAY","Add&nbsp;mutual");

define("L_D_BACK","Zurück");
define("L_D_ADMIN2","Discussion comments managment");

define("L_INNER_IMPORT","Pooling");
define("L_INTER_IMPORT","Server-Import");
define("L_INTER_EXPORT","Server-Export");

define("L_NODES_MANAGER","andere Server");
define("L_NO_PS_NODES_MANAGER","Sie haben keine Berechtigung, Server zu bearbeiten");
define("L_NODES_ADMIN_TIT","Server bearbeiten");
define("L_NODES_LIST","Bekannte entfernte Server");
define("L_NODES_ADD_NEW","Neuen Server hinzufügen");
define("L_NODES_EDIT","Server bearbeiten");
define("L_NODES_NODE_NAME","Server-Name");
define("L_NODES_YOUR_NODE","Your node name");
define("L_NODES_SERVER_URL","URL der getxml.php3");
define("L_NODES_YOUR_GETXML","Your getxml is");
define("L_NODES_PASWORD","Passwort");
define("L_SUBMIT","OK");
define("L_NODES_SEL_NONE","Kein Server ausgewählt");
define("L_NODES_CONFIRM_DELETE","Sind Sie sicher, dass Sie diesen Server löschen wollen?");
define("L_NODES_NODE_EMPTY","Der Servername muss angegeben werden.");

define("L_IMPORT_TIT","Server Import Einstellungen");
define("L_IMPORT_LIST","Importe von anderen Servern in die Rubrik ");
define("L_IMPORT_CONFIRM_DELETE","Sind Sie sicher, dass Sie diesen Import löschen wollen?");
define("L_IMPORT_SEL_NONE","Kein Imoort ausgewählt");
define("L_IMPORT_NODES_LIST","Andere Server");
define("L_IMPORT_CREATE","Neuen Import von entferntem Server erstellen");
define("L_IMPORT_NODE_SEL","Kein Server ausgewählt");
define("L_IMPORT_SLICES","Liste der Rubriken auf anderen Servern");
define("L_IMPORT_SLICES2","Liste verfügbarer Rubriken von ");
define("L_IMPORT_SUBMIT","Rubrik auswählen");
define("L_IMPORT2_OK","Der Import wurde erfolgreich eingerichtet.");
define("L_IMPORT2_ERR","Der Import wurde bereits eingerichtet.");

define("L_RSS_ERROR","Kann keine Verbindung mit dem Server herstellen. Bitte nehmen Sie mit dem Administrator Kontakt auf, wenn die Störung anadauert.");
// "Unable to connect and/or retrieve data from the remote node. Contact the administrator of the local node.");

define("L_RSS_ERROR2","Ungültiges Passwort für den Server: ");
define("L_RSS_ERROR3","Bitte nehmen Sie mit dem Administrator Kontakt auf.");
define("L_RSS_ERROR4","Keine Rubriken verfügbar. Sie haben keine Berechtigung, Daten von diesem Server ".
                      "zu importieren. Setzen Sie sich mit dem Verwalter der anderen Rubrik in Verbindung."); 
// "No slices available. You have not permissions to import any data of that node. Contact ".
// "the administrator of the remote slice and check, that he obtained your correct username.");


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
		."and you choose her new identificator.");
define("L_E_EXPORT_DESC_BACKUP", "When exporting \"to Backup\" you may choose more slices at once.");
define("L_E_EXPORT_MEMO_ID","Choose a new slice identificator exactly 16 characters long: ");
define("L_E_EXPORT_SWITCH", "Export to Backup");
define("L_E_EXPORT_SWITCH_BACKUP", "Export to another ActionApps");
define("L_E_EXPORT_IDLENGTH", "The identificator should be 16 characters long, not ");
define("L_E_EXPORT_TEXT_LABEL", "Save this text. You may use it to import the slices into any ActionApps:");
define("L_E_EXPORT_LIST", "Select slices which you WANT to export:");

define("L_E_IMPORT_TITLE", "Import slice structure");
define("L_E_IMPORT_SEND", "Send the slices structure");
define("L_E_IMPORT_MEMO", "The import of the slices structure is done this way:<br>"
			."Insert the exported text into the frame and click on Send. <br>"
			."The slices structure with fields definitions will be read and added to the ActionApps.");
define("L_E_IMPORT_OPEN_ERROR","Unknown failur when opening the file.");
define("L_E_IMPORT_WRONG_FILE","ERROR: Text is not OK. Check whether you copied it well from the Export.");
define("L_E_IMPORT_WRONG_ID","ERROR: ");
define("L_E_IMPORT_OVERWRITE", "Overwrite");
define("L_E_IMPORT_IDLENGTH", "The identificator should be 32 characters long, not ");

define("L_E_IMPORT_IDCONFLICT", 
			"Slices with some of the IDs exist already. Change the IDs on the right side of the arrow.<br> "
			."Use only hexadecimal characters 0-9,a-f. "
			."If you do something wrong (wrong characters count, wrong characters, or if you change the ID on the arrow's left side), "
			."that ID will be considered unchanged.</p>"
			."If you choose OVERWRITE, the slices with unchanged ID will be overwritten and the new ones added. <br>"
			."If you choose SEND, the slices with ID conflict will be ignored and the new ones added.");
define ("L_E_IMPORT_COUNT", "Count of imported slices: %d.");			
define ("L_E_IMPORT_ADDED", "Added were:");
define ("L_E_IMPORT_OVERWRITTEN", "Overwritten were:");

require  $GLOBALS[AA_INC_PATH]."de_param_wizard_lang.php3";

define("L_PARAM_WIZARD_LINK", "Feld-Assistent");
define("L_SHOW_RICH", "Show this field as a rich text editor (use only after having installed the necessary components!)");

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

// ------------------------- New ----------------------------
// not appended to other lang files
//define("", "");


/*
$Log$
Revision 1.5  2001/12/26 22:11:37  honzam
Customizable 'No item found' message. Added missing language constants.

Revision 1.4  2001/11/29 08:40:08  mitraearth
Provides help when using the Nodes screen to configre inter-node feeding
It informs of the correect values to tell the superadmin of the other node.

Revision 1.3  2001/11/07 22:26:33  udosw
Another bugfic (CR/LF)s (Sorry)

Revision 1.1  2001/11/07 16:04:55  udosw
German translation (partly) by UdoSW

Revision 1.51  2001/10/08 17:03:35  honzam
Language constants fixes

Revision 1.50  2001/10/05 10:51:29  honzam
Slice import/export allows backup of more slices, bugfixes

Revision 1.49  2001/10/02 11:36:41  honzam
bugfixes

Revision 1.48  2001/09/27 13:09:53  honzam
New Cross Server Networking now is working (RSS item exchange)

Revision 1.47  2001/09/12 06:19:00  madebeer
Added ability to generate RSS views.
Added f_q to item.php3, to grab 'blurbs' from another slice using aliases

Revision 1.46  2001/07/09 09:28:44  honzam
New supported User defined alias functions in include/usr_aliasfnc.php3 file

Revision 1.45  2001/06/24 16:46:22  honzam
new sort and search possibility in admin interface

Revision 1.44  2001/06/21 14:15:44  honzam
feeding improved - field value redefine possibility in se_mapping.php3

Revision 1.43  2001/06/12 16:07:22  honzam
new feeding modes -  "Feed & update" and "Feed & update & lock"

Revision 1.42  2001/06/03 16:00:49  honzam
multiple categories (multiple values at all) for item now works

Revision 1.41  2001/05/26 14:50:58  honzam
Field ID is displayed instead of field type

Revision 1.40  2001/05/21 13:52:32  honzam
New "Field mapping" feature for internal slice to slice feeding

Revision 1.39  2001/05/18 13:55:04  honzam
New View feature, new and improved search function (QueryIDs)

Revision 1.38  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.37  2001/04/17 21:32:08  honzam
New conditional alias. Fixed bug of not displayed top/bottom HTML code in fulltext and category

Revision 1.36  2001/04/10 02:00:31  keb
Added explanation for Text Field parameters.
Handle case of multiple parameter delimiters, e.g. " : " or ", ".

Revision 1.35  2001/03/30 11:54:35  honzam
offline filling bug and others small bugs fixed

Revision 1.34  2001/03/30 06:17:44  keb
Spelling and minor grammar corrections to english text constant strings.

Revision 1.33  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.32  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...


Revision 1.31  2001/02/25 08:49:54  madebeer
added help for admin-Fields-Edit

Revision 1.30  2001/02/25 08:33:40  madebeer
fixed some table formats, cleaned up admin headlines

Revision 1.29  2001/02/23 11:18:04  madebeer
interface improvements merged from wn branch

Revision 1.28  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

Revision 1.27  2001/01/31 02:46:03  madebeer
moved Fields leftbar section back up to Slice main settings section.
updated some english language titles

Revision 1.26  2001/01/25 10:09:09  honzam
Gived back acidentaly deleted Michael's definitions

Revision 1.25  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.21  2001/01/08 13:31:58  honzam
Small bugfixes

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
