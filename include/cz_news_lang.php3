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
define("CONFIG_FILE", "cz_news_lang.php3");

define("IMG_UPLOAD_MAX_SIZE", "400000");    // max size of file in picture uploading
define("IMG_UPLOAD_URL", "http://aa.ecn.cz/img_upload/");
define("IMG_UPLOAD_PATH", "/raid/www/htdocs/aa.ecn.cz/img_upload/");
define("IMG_UPLOAD_TYPE", "image/*");
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

define("HTML_PAGE_BEGIN", 
'<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'. ADMIN_CSS .'" 
        type="text/css" title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
               
# aa toolkit specific labels
define("L_ITEM_ID_ALIAS",'alias pro èíslo èlánku');
define("L_EDITITEM_ALIAS",'alias používaný v administrativních stránkách index.php3 pro URL itemedit.php3');
define("L_LANG_FILE","Použitý language soubor");
define("L_PARAMETERS","Parametry");
define("L_SELECT_APP","Vyber aplikaci");
define("L_SELECT_OWNER","Vyber vlastníka");
     
# toolkit aplication dependent labels
define("L_CANT_UPLOAD", "Soubor (obrázek) nelze uložit");
define("L_GRAB_LEN", "Poèet znakù textu použitých jako abstrakt");
define("L_MSG_PAGE", "Zpráva aplikace");   // title of message page
define("L_EDITOR_TITLE", "Správa zpráv");
define("L_FULLTEXT_FORMAT_TOP", "Horní HTML kód");
define("L_FULLTEXT_FORMAT", "HTML kód textu zprávy");
define("L_FULLTEXT_FORMAT_BOTTOM", "Spodní HTML kód");
define("L_A_FULLTEXT_TIT", "Správa webíku - Vzhled jedné zprávy");
define("L_FULLTEXT_HDR", "HTML kód pro zobrazení zprávy");
define("L_COMPACT_HDR", "HTML kód pro pøehled zpráv");
define("L_ITEM_HDR", "Vstupní formuláø zprávy");
define("L_A_ITEM_ADD", "Pøidat zprávu");
define("L_A_ITEM_EDT", "Upravit zprávu");
define("L_IMP_EXPORT", "Povolit zasílání zpráv do webíku:");
define("L_ADD_NEW_ITEM", "Nová zpráva");
define("L_DELETE_TRASH", "Vysypat koš");
define("L_VIEW_FULLTEXT", "Zobraz zprávu");
define("L_FULLTEXT", "Text zprávy");
define("L_HIGHLIGHTED", "Dùležitá zpráva");
define("L_A_FIELDS_EDT", "Správa webíku - Nastavení polí");
define("L_FIELDS_HDR", "Pole zpráv");
define("L_NO_PS_EDIT_ITEMS", "Nemáte právo upravovat zprávy v tomto webíku");
define("L_NO_DELETE_ITEMS", "Nemáte právo mazat zprávy");
define("L_NO_PS_MOVE_ITEMS", "Nemáte právo pøesouvat zprávy");
define("L_NO_PS_COPMPACT", "Nemáte právo upravovat vzhled pøehledu zpráv");
define("L_FULLTEXT_OK", "Vzhled textu zprávy byl úspìšnì zmìnìn");
define("L_NO_ITEM", "Žádná zpráva nevyhovuje vašemu dotazu.");



# aa toolkit common labels
# can be the same for all toolkit aplications
define("L_ACTIVE_BIN", "Aktuální");
define("L_HOLDING_BIN", "Zásobník");
define("L_TRASH_BIN", "Koš");

define("L_CATEGORY","Kategorie");
define("L_SLICE_NAME", "Jméno");
define("L_DELETED", "Vymazán");
define("L_D_LISTLEN", "Poèet vypisovaných zpráv");  // slice
define("L_ERR_CANT_CHANGE", "Nepodaøilo se zmìnit nastavení webíku");
define("L_ODD_ROW_FORMAT", "Liché øádky");
define("L_EVEN_ROW_FORMAT", "Sudé øádky");
define("L_EVEN_ODD_DIFFER", "Použij odlišný HTML kód pro sudé øadky");
define("L_CATEGORY_TOP", "Horní HTML k=od pro kategorii");
define("L_CATEGORY_FORMAT", "Nadpis kategorie");
define("L_CATEGORY_BOTTOM", "Spodné HTML kód pro kategorii");
define("L_CATEGORY_SORT", "Seøaï zprávy v pøehledu podle kategorie");
define("L_COMPACT_TOP", "Horní HTML kód");
define("L_COMPACT_BOTTOM", "Spodní HTML kód");
define("L_A_COMPACT_TIT", "Správa webíku - Vzhled pøehledu zpráv");
define("L_A_FILTERS_TIT", "Správa webíku - Filtry pro výmìnu zpráv");
define("L_FLT_SETTING", "Nastavení filtrù pro pøíjem zpráv");
define("L_FLT_FROM_SL", "Filtr pro pøíjem zpráv z webíku");
define("L_FLT_FROM", "Z");
define("L_FLT_TO", "Do");
define("L_FLT_APPROVED", "Jako aktuální zprávu");
define("L_FLT_CATEGORIES", "Kategorie");
define("L_ALL_CATEGORIES", "Všechny kategorie");
define("L_FLT_NONE", "Není vybrána žádná vstupní kategorie!");
define("L_THE_SAME", "-- stejná --");
define("L_EXPORT_TO_ALL", "Povol exportovat zprávy do všech webíkù");

define("L_IMP_EXPORT_Y", "Zasílání povoleno");
define("L_IMP_EXPORT_N", "Zasílání zakázáno");
define("L_IMP_IMPORT", "Pøijímat zprávy z:");
define("L_IMP_IMPORT_Y", "Pøijímat");
define("L_IMP_IMPORT_N", "Nepøijímat");
define("L_CONSTANTS_HLP", "Použij následujítí aliasy databázových polí");

define("L_ERR_IN", "Chyba v");
define("L_ERR_NEED", "musí být vyplnìno");
define("L_ERR_LOG", "použijte znaky a-z, A-Z a 0-9");
define("L_ERR_LOGLEN", "musí být dlouhé 5 - 32 znakù");
define("L_ERR_NO_SRCHFLDS", "Nebylo zadáno prohledávané pole!");

define("L_FIELDS", "Políèka");
define("L_EDIT", "Editace");
define("L_DELETE", "Vymazat");
define("L_REVOKE", "Odstranit");
define("L_UPDATE", "Zmìnit");
define("L_RESET", "Vymazat formuláø");
define("L_CANCEL", "Zrušit");
define("L_ACTION", "Akce");
define("L_INSERT", "Vložit");
define("L_NEW", "Nový");
define("L_GO", "Jdi");
define("L_ADD", "Pøidat");
define("L_USERS", "Uživatelé");
define("L_GROUPS", "Skupiny");
define("L_SEARCH", "Hledání");
define("L_DEFAULTS", "Default");
define("L_SLICE", "Webík");
define("L_DELETED_SLICE", "Nebyl nalezen žádný webík, ke kterému máte pøístup");
define("L_SLICE_URL", "URL webíku");
define("L_A_NEWUSER", "Nový uživatel v systému");
define("L_NEWUSER_HDR", "Nový uživatel");
define("L_USER_LOGIN", "Uživatelské jméno");
define("L_USER_PASSWORD1", "Heslo");
define("L_USER_PASSWORD2", "Potvrdit heslo");
define("L_USER_FIRSTNAME", "Jméno");
define("L_USER_SURNAME", "Pøíjmení");
define("L_USER_MAIL", "E-mail");
define("L_USER_SUPER", "Administrativní úèet");
define("L_A_USERS_TIT", "Správa webíku - Uživatelé");
define("L_A_PERMISSIONS", "Správa webíku - Pøístupová práva");
define("L_A_ADMIN", "Správa webíku - Vzhled Administrace");
define("L_A_ADMIN_TIT", L_A_ADMIN);
define("L_ADMIN_FORMAT", "HTML kód pro zobrazení zprávy");
define("L_ADMIN_FORMAT_BOTTOM", "Spodní HTML");
define("L_ADMIN_FORMAT_TOP", "Horní HTML");
define("L_ADMIN_HDR", "Výpis zpráv v administrativních stránkách");
define("L_ADMIN_OK", "Vzheld administrativních stánek úspìšnì zmìnìn");
define("L_ADMIN_REMOVE", "Odstraòované øetìzce");

define("L_ROLE_AUTHOR", "Autor");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administrátor");
define("L_ROLE_SUPER", "Super");

define("L_MAIN_SET", "Hlavní nastavení");
define("L_PERMISSIONS", "Nastavení práv");
define("L_PERM_CHANGE", "Zmìna souèasných práv");
define("L_PERM_ASSIGN", "Pøidìlení nových práv");
define("L_PERM_NEW", "Hledej uživatele nebo skupinu");
define("L_PERM_SEARCH", "Pøiøazení nových práv");
define("L_PERM_CURRENT", "Zmìna souèasných práv");
define("L_USER_NEW", "Nový uživatel");
define("L_DESIGN", "Vzhled");
define("L_COMPACT", "Pøehled zpráv");
define("L_COMPACT_REMOVE", "Odstraòované øetìzce");
define("L_FEEDING", "Výmìna zpráv");
define("L_IMPORT", "Zasílání & Pøíjem");
define("L_FILTERS", "Filtry");

define("L_A_SLICE_ADD", "Správa webíku - Pøidání webíku");
define("L_A_SLICE_EDT", "Správa webíku - Úprava webíku");
define("L_A_SLICE_CAT", "Správa webíku - Nastavení kategorií");
define("L_A_SLICE_IMP", "Správa webíku - Výmìna zpráv");
define("L_FIELD", "Položka");
define("L_FIELD_IN_EDIT", "Zobrazit");
define("L_NEEDED_FIELD", "Povinná");
define("L_A_SEARCH_TIT", "Správa webíku - Vyhledávací formuláø");
define("L_SEARCH_HDR", "Vyhledávací kritéria");
define("L_SEARCH_HDR2", "Vyhledávat v položkách");
define("L_SEARCH_SHOW", "Zobrazit");
define("L_SEARCH_DEFAULT", "Standardni nastavení");
define("L_SEARCH_SET", "Vyhledávací formuláø");
define("L_AND", "AND");
define("L_OR", "OR");
define("L_SRCH_KW", "Search for");
define("L_SRCH_FROM", "From");
define("L_SRCH_TO", "To");
define("L_SRCH_SUBMIT", "Search");
define("L_NO_PS_EDIT", "Nemáte právo upravovat tento webík");
define("L_NO_PS_ADD", "Nemáte právo pøidávat webík");
define("L_NO_PS_COPMPACT", "Nemáte právo mìnit vzhled pøehledu zpráv");
define("L_NO_PS_FULLTEXT", "Nemáte právo mìnit vzhled výpisu zprávy");
define("L_NO_PS_CATEGORY", "Nemáte právo mìnit nastavení kategorií");
define("L_NO_PS_FEEDING", "Nemáte právo mìnit nastavení výmìny zpráv");
define("L_NO_PS_USERS", "Nemáte právo ke správì uživatelù");
define("L_NO_PS_FIELDS", "Nemáte právo mìnit nastavení položek");
define("L_NO_PS_SEARCH", "Nemáte právo mìnit nastavení vyhledávání");

define("L_BAD_RETYPED_PWD", "Vyplnìná hesla si neodpovídají");
define("L_ERR_USER_ADD", "Nepodaøilo se pøidat uživatele do systému - chyba LDAP");
define("L_NEWUSER_OK", "Uživatel byl úspìšnì pøidán do systému");
define("L_COMPACT_OK", "Vzhled pøehledu zpráv byl úspìšnì zmìnìm");
define("L_BAD_ITEM_ID", "Špatné èíslo zprávy");
define("L_ALL", " - vše - ");
define("L_CAT_LIST", "Kategorie zpráv");
define("L_CAT_SELECT", "Kategorie v tomto webíku");
define("L_NEW_SLICE", "Nový webík");
define("L_ASSIGN", "Pøiøadit");
define("L_CATBINDS_OK", "Nastavení kategorií bylo úspìšnì zmìnìno");
define("L_IMPORT_OK", "Nastavení výmìny zpráv úspìšnì zmìnìno");
define("L_FIELDS_OK", "Nasavení položek úspìšnì zmìnìno");
define("L_SEARCH_OK", "Nastavení vyhledávacího formuláøe úspìšnì zmìnìno");
define("L_NO_CATEGORY", "Kategorie nebyly definovány");
define("L_NO_IMPORTED_SLICE", "Není nastaven žádný webík, ze kterého se mají pøijímat zprávy");
define("L_NO_USERS", "Uživatel (skupina) nenalezena");

define("L_TOO_MUCH_USERS", "Nalezeno pøíliš mnoho uživatelù èi skupin.");
define("L_MORE_SPECIFIC", "Zkuste zadat pøesnìjší údaje.");
define("L_REMOVE", "Odstranit");
define("L_ID", "Id");
define("L_TYPE", "Typ");
define("L_SETTINGS", "Nastavení");
define("L_LOGO", "APC toolkit");
define("L_USER_MANAGEMENT", "Uživatelé");
define("L_ITEMS", "Správa pøíspìvkù");
define("L_NEW_SLICE_HEAD", "Nový webík");
define("L_ERR_USER_CHANGE", "Nelze zmìnit data uživatele - LDAP Error");
define("L_PUBLISHED", "Zveøejnìno");
define("L_EXPIRED", "Vypršeno");
define("L_NOT_PUBLISHED", "Dosud nepublikováno");
define("L_EDIT_USER", "Editace uživatele");
define("L_EDITUSER_HDR", L_EDIT_USER);
define("NO_PICTURE_URL", "http://web.ecn.cz/aauser/images/no_pict.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)
define("NO_SOURCE_URL", "javascript: window.alert('Nebylo zadáno url zdroje')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('Nebylo zadáno url odkazu ven')");

# editor interface constants
define("L_PUBLISHED_HEAD", "PUB");
define("L_HIGHLIGHTED_HEAD", "!");
define("L_FEEDED_HEAD", "cizí");
define("L_MORE_DETAILS", "Více podrobností");
define("L_LESS_DETAILS", "Ménì podrobností");
define("L_UNSELECT_ALL", "Zrušit výbìr");
define("L_SELECT_VISIBLE", "Vybrat zobrazené");
define("L_UNSELECT_VISIBLE", "Zrušit výbìr");

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

//new_constants
define("DEFAULT_SLICE_CONFIG", "<wddxPacket version='0.9'><header/><data><struct><var name='admin_fields'><struct><var name='chbox'><struct><var name='width'><number>24</number></var></struct></var><var name='post_date'><struct><var name='width'><number>70</number></var></struct></var><var name='headline'><struct><var name='width'><number>224</number></var></struct></var><var name='catname'><struct><var name='width'><number>70</number></var></struct></var><var name='published'><struct><var name='width'><number>24</number></var></struct></var><var name='highlight'><struct><var name='width'><number>24</number></var></struct></var><var name='feed'><struct><var name='width'><number>24</number></var></struct></var></struct></var></struct></data></wddxPacket>");
define("L_FEED", "Výmìna zpráv");
define("L_FEEDTO_TITLE", "Pøedat zprávu do webíku");
define("L_FEED_TO", "Pøedat vybrné zprávy do zvolených webíku");
define("L_NO_PERMISSION_TO_FEED", "Nelze");
define("L_NO_PS_CONFIG", "Nemáte právo nastavovat configuraèní parametry tohoto webíku");
define("L_SLICE_CONFIG", "Parametry");
define("L_CHBOX_HEAD", "&nbsp;");   // title of checkbox in admin interface
define("L_CHBOX", "Checkbox"); 
define("L_CATNAME", "Kategorie");
define("L_CATEGORY_ID", "ID kategorie");
define("L_EDITED_BY","Editováno");
define("L_MASTER_ID", "ID zdrojového webíku");
define("L_CHANGE_MARKED", "Zmìnit vybrané");
define("L_MOVE_TO_ACTIVE_BIN", "Vystavit");
define("L_MOVE_TO_HOLDING_BIN", "Poslat do zásobníku");
define("L_MOVE_TO_TRASH_BIN", "Poslat do koše");
define("L_OTHER_ARTICLES", "Ostatní zprávy");
define("L_MISC", "Pøíkazy");
define("L_HEADLINE_EDIT", "Nadpis (editace po kliknutí)");
define("L_HEADLINE_PREVIEW", "Nadpis (preview po kliknutí)");
define("L_EDIT_LINK", "&nbsp;");
define("L_PREVIEW_LINK", "&nbsp;");
define("L_ARTICLE_MANAGER", "Správa zpráv");
define("L_SWITCH_TO", "Webík:");
define("L_ADMIN", "Administrace");

//new_constants
define("L_NO_PS_NEW_USER", "Nemáte právo vytvoøit uživatele");
define("L_ALL_GROUPS", "Všechny skupiny");
define("L_USERS_GROUPS", "Uživatelovy skupiny");
define("L_REALY_DELETE_USER", "Opravdu chcete vymazat daného uživatele ze systému?");
define("L_REALY_DELETE_GROUP", "Opravdu chcete vymazat danou skupinu ze systému?");
define("L_ITEM_NOT_CHANGED", "Zpráva nezmìnìna");
define("L_NO_GROUPS", "Skupina nenalezena");
define("L_GROUP_NAME", "Jméno");
define("L_GROUP_DESCRIPTION", "Popis");
define("L_GROUP_SUPER", "Administrativní skupina");
define("L_ERR_GROUP_ADD", "Nelze pøidat skupinu do systému");
define("L_NEWGROUP_OK", "Skupina byla úspìšnì pøidána");
define("L_ERR_GROUP_CHANGE", "Nelze zmìnit skupinu");
define("L_A_UM_USERS_TIT", "Správa uživatelù - Uživalelé");
define("L_A_UM_GROUPS_TIT", "Správa uživatelù - Skupiny");
define("L_EDITGROUP_HDR", "Editace skupiny");
define("L_NEWGROUP_HDR", "Nová skupina");
define("L_GROUP_ID", "ID skupiny");
define("L_ALL_USERS", "Všichni uživatelé");
define("L_GROUPS_USERS", "Uživatelé ve skupinì");
define("L_POST", "Poslat");
define("L_POST_PREV", "Poslat a prohlédnout");
define("L_OK", "OK");
define("L_ACTIVE_BIN_EXPIRED", "Aktuální - Expirované");
define("L_ACTIVE_BIN_PENDING", "Aktuální - Pøipravené");
define("L_ACTIVE_BIN_EXPIRED_MENU", "... expirované");
define("L_ACTIVE_BIN_PENDING_MENU", "... pøipravené");

define("L_SOURCE_DESC", "Org Description");
define("L_SOURCE_ADDRESS", "Street Adress");
define("L_SOURCE_CITY", "City");
define("L_SOURCE_PROV", "Province/State");
define("L_SOURCE_COUNTRY", "Country");
define("L_TIME", "Time");
define("L_CON_NAME", "Contact Name");
define("L_CON_EMAIL", "Contact E-mail");
define("L_CON_PHONE", "Contact phone");
define("L_CON_FAX", "Contact FAX");
define("L_LOC_NAME", "Location Name");
define("L_LOC_ADDRESS", "Location Street Address");
define("L_LOC_CITY", "Location City");
define("L_LOC_PROV", "Location Province/State");
define("L_LOC_COUNTRY", "Location Country");
define("L_START_DATE", "Start Date");
define("L_END_DATE", "End Date");

define("L_HLP_SOURCE_DESC", "Alias for Org Description");
define("L_HLP_SOURCE_ADDRESS", "Alias for Street Adress");
define("L_HLP_SOURCE_CITY", "Alias for City");
define("L_HLP_SOURCE_PROV", "Alias for Province/State");
define("L_HLP_SOURCE_COUNTRY", "Alias for Country");
define("L_HLP_TIME", "Alias for Time");
define("L_HLP_CON_NAME", "Alias for Contact Name");
define("L_HLP_CON_EMAIL", "Alias for Contact E-mail");
define("L_HLP_CON_PHONE", "Alias for Contact phone");
define("L_HLP_CON_FAX", "Alias for Contact FAX");
define("L_HLP_LOC_NAME", "Alias for Location Name");
define("L_HLP_LOC_ADDRESS", "Alias for Location Street Address");
define("L_HLP_LOC_CITY", "Alias for Location City");
define("L_HLP_LOC_PROV", "Alias for Location Province/State");
define("L_HLP_LOC_COUNTRY", "Alias for Location Country");
define("L_HLP_START_DATE", "Alias for Start Date");
define("L_HLP_END_DATE", "Alias for End Date");

//----------------------------
//define("", "");   
//prepared for new constants
 
define("L_FIELD_PRIORITY", "Priorita");
define("L_FIELD_TYPE", "Typ");
define("L_CONSTANTS", "Hodnoty");
define("L_DEFAULT", "Default");
define("L_DELETE_FIELD", "Opravdu chcete vymazat toto pole z webíku?");
define("L_FEEDED", "Pøejato");
define("L_HTML_DEFAULT", "defaultnì použít HTML kód");
define("L_HTML_SHOW", "Zobrazit volbu 'HTML' / 'prostý text'");
define("L_NEW_OWNER", "Nový vlastník");
define("L_NEW_OWNER_EMAIL", "E-mail nového vlastníka");
define("L_NO_FIELDS", "V tomto webíku nejsou definována žádná pole (což je divné)");
define("L_NO_FIELD", "");
define("L_NO_PERM_TO_FEED", "Nemáte právo nastavit výmìnu zpráv s žádným webíkem");
define("L_NO_SLICES", "Žádný webík");
define("L_NO_TEMPLATES", "Žádná šablona");
define("L_OWNER", "Vlastník");
define("L_SLICES", "Webíky");
define("L_TEMPLATE", "Šablona");
define("L_VALIDATE", "Zkontrolovat");
define("", "");

define("L_FIELD_DELETE_OK", "Pole odstranìno");

define("L_WARNING_NOT_CHANGE","<p>POZOR: Tato nastavení by mìl mìnit jen ten, kdo ví co dìlá!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Funkce, která se použije pro zobrazení pole ve vstupním formuláøi. Pro nìkteré typu zobrazení lze použít parametrù, které následují.");
define("L_INPUT_SHOW_FUNC_C_HLP","Hodnoty, použité v pøípadì vstupních funkcí SELECT èi RADIO.");
define("L_INPUT_SHOW_FUNC_HLP","Parametr použitý pro vstupní funkce TEXT (<poèet øádkù>) èi DATE (<minus roky>'<plus roky>'<od teï?>).");
define("L_INPUT_DEFAULT_F_HLP","Funkce, která se použije pro generování defaultních hodnot pole:<BR>Now - aktuální datum<BR>User ID - identifikátor pøihlášeného uživatele<BR>Text - text uvedený v poli Parametr<br>Date - aktuální datum plus <Parametr> dní");
define("L_INPUT_DEFAULT_HLP","Parametr pro defaulní hodnoty Text a Date (viz výše)");

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
define("L_INPUT_BEFORE","HTML kód pøed tímto polem");
define("L_INPUT_BEFORE_HLP","HTML kód, který se zobrazí ve vstupním formuláøi pøed tímto polem");
define("L_INPUT_FUNC","Typ Vstupu");
define("L_INPUT_HELP","Nápovìda");
define("L_INPUT_HELP_HLP","Nápovìda zobrazená pro toto pole ve vstupním formuláøi");
define("L_INPUT_MOREHLP","Více informací");
define("L_INPUT_MOREHLP_HLP","Nápovìda, která se zobrazí po stisku '?' ve vstupním formuláøi");
define("L_INPUT_INSERT_HLP","Zpùsob uložení do databáze");
define("L_INPUT_VALIDATE_HLP","Funkce pro kontrolu vstupu (validace)");

define("L_CONSTANT_NAME", "Jméno");
define("L_CONSTANT_VALUE", "Hodnota");
define("L_CONSTANT_PRIORITY", "Priorita");
define("L_CONSTANT_PRI", "Priorita");
define("L_CONSTANT_GROUP", "Skupina hodnot");
define("L_CONSTANT_GROUP_EXIST", "Tato skupina hodnot již existuje");
define("L_CONSTANTS_OK", "Zmìno hodnot úspìšnì provedena");
define("L_A_CONSTANTS_TIT", "Správa webíku - Nastavení hodnot");
define("L_A_CONSTANTS_EDT", "Správa webíku - Nastavení hodnot");
define("L_CONSTANTS_HDR", "Hodnoty");
define("L_CONSTANT_NAME_HLP", "zobrazeno&nbsp;ve&nbsp;vstupním&nbsp;formuláøi");
define("L_CONSTANT_VALUE_HLP", "uloženo&nbsp;v&nbsp;databázi");
define("L_CONSTANT_PRI_HLP", "Poøadí&nbsp;hodnot");
define("L_CONSTANT_CLASS", "Nadkategorie");
define("L_CONSTANT_CLASS_HLP", "jen&nbsp;pro&nbsp;kategorie");
define("L_CONSTANT_DEL_HLP", "Pro odstranìní kartegorie vymažte její jméno");

$L_MONTH = array( 1 => 'Leden', 'Únor', 'Bøezen', 'Duben', 'Kvìten', 'Èerven', 
		'Èervenec', 'Srpen', 'Záøí', 'Øíjen', 'Listopad', 'Prosinec');

    
/*
$Log$
Revision 1.19  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.18  2000/12/05 14:01:58  honzam
Better help for upload image alias

Revision 1.17  2000/11/13 10:41:14  honzam
Fixed bad order for default setting of show fields and needed fields

Revision 1.16  2000/10/12 15:56:09  honzam
Updated language files with better defaults

Revision 1.15  2000/10/10 18:28:00  honzam
Support for Web.net's extended item table

Revision 1.14  2000/08/17 15:17:55  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.13  2000/08/15 08:58:31  kzajicek
Added missing L_HLP_CATEGORY_ID

Revision 1.12  2000/08/15 08:43:41  kzajicek
Fixed spelling error in constant name

Revision 1.11  2000/08/14 12:39:13  kzajicek
Language definitions required by setup.php3

Revision 1.10  2000/08/03 15:19:57  kzajicek
Language changes

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

Revision 1.1.1.1  2000/06/21 18:40:27  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:14  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.6  2000/06/12 19:58:34  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.5  2000/06/09 15:14:10  honzama
New configurable admin interface

Revision 1.4  2000/04/24 16:50:33  honzama
New usermanagement interface.

Revision 1.3  2000/03/29 15:54:46  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.2  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
