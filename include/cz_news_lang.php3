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

if (!defined ("CZ_NEWS_LANG_INCLUDED"))
   	  define ("CZ_NEWS_LANG_INCLUDED",1);
else return;

# config file identifier
# must correspond with this file name
define("LANG_FILE", "cz_news_lang.php3");
define("CHARSET", "windows-1250");

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
'<!DOCTYPE html public "-//W3C//DTD HTML 4.0 Transitional//EN">
  <HTML XMLNS:XS>
  <HEAD>
  <LINK rel=StyleSheet href="'.$AA_INSTAL_PATH.ADMIN_CSS.'" type="text/css"  title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
               
# aa toolkit specific labels
define("L_VIEW_SLICE", "Zobraz");
define( "L_SLICE_HINT", '<br>Webík zahrnete do své *.shtml stránky pøidáním 
                             následující øádky v HTML kódu: ');
define("L_ITEM_ID_ALIAS","alias pro èíslo èlánku");
define("L_EDITITEM_ALIAS","alias pouívanı v administrativních stránkách index.php3 pro URL itemedit.php3");
define("L_LANG_FILE","Pouitı language soubor");
define("L_PARAMETERS","Parametry");
define("L_SELECT_APP","Vyber aplikaci");
define("L_SELECT_OWNER","Vyber vlastníka");

define("L_CANT_UPLOAD", "Soubor (obrázek) nelze uloit");
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
define("L_FULLTEXT", "Celá zpráva");
define("L_HIGHLIGHTED", "Dùleitá zpráva");
define("L_A_FIELDS_EDT", "Správa webíku - Nastavení polí");
define("L_FIELDS_HDR", "Pole zpráv");
define("L_NO_PS_EDIT_ITEMS", "Nemáte právo upravovat zprávy v tomto webíku");
define("L_NO_DELETE_ITEMS", "Nemáte právo mazat zprávy");
define("L_NO_PS_MOVE_ITEMS", "Nemáte právo pøesouvat zprávy");
define("L_FULLTEXT_OK", "Vzhled textu zprávy byl úspìšnì zmìnìn");

# aa toolkit common labels
# can be the same for all toolkit aplications
define("L_ACTIVE_BIN", "Aktuální");
define("L_HOLDING_BIN", "Zásobník");
define("L_TRASH_BIN", "Koš");

define("L_CATEGORY","Kategorie");
define("L_SLICE_NAME", "Jméno");
define("L_DELETED", "Vymazán");
define("L_D_LISTLEN", "Poèet vypisovanıch zpráv");  // slice
define("L_ERR_CANT_CHANGE", "Nepodaøilo se zmìnit nastavení webíku");
define("L_ODD_ROW_FORMAT", "Lichı záznam");
define("L_EVEN_ROW_FORMAT", "Sudı záznam");
define("L_EVEN_ODD_DIFFER", "Odlišnı HTML kód pro sudé záznamy");
define("L_CATEGORY_TOP", "Horní HTML kód pro kategorii");
define("L_CATEGORY_FORMAT", "Nadpis kategorie");
define("L_CATEGORY_BOTTOM", "Spodní HTML kód pro kategorii");
define("L_CATEGORY_SORT", "Seøaï zprávy v pøehledu podle kategorie");
define("L_COMPACT_TOP", "Horní HTML kód");
define("L_COMPACT_BOTTOM", "Spodní HTML kód");
define("L_A_COMPACT_TIT", "Správa webíku - Vzhled pøehledu zpráv");
define("L_A_FILTERS_TIT", "Správa webíku - Filtry pro vımìnu zpráv");
define("L_FLT_SETTING", "Nastavení filtrù pro pøíjem zpráv");
define("L_FLT_FROM_SL", "Filtr pro pøíjem zpráv z webíku");
define("L_FLT_FROM", "Z");
define("L_FLT_TO", "Do");
define("L_FLT_APPROVED", "Jako aktuální zprávu");
define("L_FLT_CATEGORIES", "Kategorie");
define("L_ALL_CATEGORIES", "Všechny kategorie");
define("L_FLT_NONE", "Není vybrána ádná vstupní kategorie!");
define("L_THE_SAME", "-- stejná --");
define("L_EXPORT_TO_ALL", "Povol exportovat zprávy do všech webíkù");

define("L_IMP_EXPORT_Y", "Zasílání povoleno");
define("L_IMP_EXPORT_N", "Zasílání zakázáno");
define("L_IMP_IMPORT", "Pøijímat zprávy z:");
define("L_IMP_IMPORT_Y", "Pøijímat");
define("L_IMP_IMPORT_N", "Nepøijímat");
define("L_CONSTANTS_HLP", "Pouij následujítí aliasy databázovıch polí");

define("L_ERR_IN", "Chyba v");
define("L_ERR_NEED", "musí bıt vyplnìno");
define("L_ERR_LOG", "pouijte znaky a-z, A-Z a 0-9");
define("L_ERR_LOGLEN", "musí bıt dlouhé 5 - 32 znakù");
define("L_ERR_NO_SRCHFLDS", "Nebylo zadáno prohledávané pole!");

define("L_FIELDS", "Políèka");
define("L_EDIT", "Editace");
define("L_DELETE", "Vymazat");
define("L_REVOKE", "Odstranit");
define("L_UPDATE", "Zmìnit");
define("L_RESET", "Vymazat formuláø");
define("L_CANCEL", "Zrušit");
define("L_ACTION", "Akce");
define("L_INSERT", "Vloit");
define("L_NEW", "Novı");
define("L_GO", "OK");
define("L_ADD", "Pøidat");
define("L_USERS", "Uivatelé");
define("L_GROUPS", "Skupiny");
define("L_SEARCH", "Hledání");
define("L_DEFAULTS", "Default");
define("L_SLICE", "Webík");
define("L_DELETED_SLICE", "Nebyl nalezen ádnı webík, ke kterému máte pøístup");
define("L_A_NEWUSER", "Novı uivatel v systému");
define("L_NEWUSER_HDR", "Novı uivatel");
define("L_USER_LOGIN", "Uivatelské jméno");
define("L_USER_PASSWORD1", "Heslo");
define("L_USER_PASSWORD2", "Potvrdit heslo");
define("L_USER_FIRSTNAME", "Jméno");
define("L_USER_SURNAME", "Pøíjmení");
define("L_USER_MAIL", "E-mail");
define("L_USER_SUPER", "Superadmin");
define("L_A_USERS_TIT", "Správa webíku - Uivatelé");
define("L_A_PERMISSIONS", "Správa webíku - Pøístupová práva");
define("L_A_ADMIN", "Správa webíku - Vzhled Administrace");
define("L_A_ADMIN_TIT", L_A_ADMIN);
define("L_ADMIN_FORMAT", "HTML kód pro zobrazení zprávy");
define("L_ADMIN_FORMAT_BOTTOM", "Spodní HTML");
define("L_ADMIN_FORMAT_TOP", "Horní HTML");
define("L_ADMIN_HDR", "Vıpis zpráv v administrativních stránkách");
define("L_ADMIN_OK", "Vzheld administrativních stánek úspìšnì zmìnìn");
define("L_ADMIN_REMOVE", "Odstraòované øetìzce");

define("L_ROLE_AUTHOR", "Autor");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administrátor");
define("L_ROLE_SUPER", "Super");

define("L_MAIN_SET", "Hlavní nastavení");
define("L_PERMISSIONS", "Nastavení práv");
define("L_PERM_CHANGE", "Zmìnit");
define("L_PERM_ASSIGN", "Pøidat");
define("L_PERM_NEW", "Hledej uivatele nebo skupinu");
define("L_PERM_SEARCH", "Pøiøazení novıch práv");
define("L_PERM_CURRENT", "Zmìna souèasnıch práv");
define("L_USER_NEW", "Novı uivatel");
define("L_DESIGN", "Vzhled");
define("L_COMPACT", "Pøehled zpráv");
define("L_COMPACT_REMOVE", "Odstraòované øetìzce");
define("L_FEEDING", "Vımìna zpráv");
define("L_IMPORT", "Zasílání & Pøíjem");
define("L_FILTERS", "Filtry");

define("L_A_SLICE_ADD", "Správa webíku - Pøidání webíku");
define("L_A_SLICE_EDT", "Správa webíku - Úprava webíku");
define("L_A_SLICE_CAT", "Správa webíku - Nastavení kategorií");
define("L_A_SLICE_IMP", "Správa webíku - Vımìna zpráv");
define("L_FIELD", "Poloka");
define("L_FIELD_IN_EDIT", "Zobrazit");
define("L_NEEDED_FIELD", "Povinná");
define("L_A_SEARCH_TIT", "Správa webíku - Vyhledávací formuláø");
define("L_SEARCH_HDR", "Vyhledávací kritéria");
define("L_SEARCH_HDR2", "Vyhledávat v polokách");
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
define("L_NO_PS_COMPACT", "Nemáte právo mìnit vzhled pøehledu zpráv");
define("L_NO_PS_FULLTEXT", "Nemáte právo mìnit vzhled vıpisu zprávy");
define("L_NO_PS_CATEGORY", "Nemáte právo mìnit nastavení kategorií");
define("L_NO_PS_FEEDING", "Nemáte právo mìnit nastavení vımìny zpráv");
define("L_NO_PS_USERS", "Nemáte právo ke správì uivatelù");
define("L_NO_PS_FIELDS", "Nemáte právo mìnit nastavení poloek");
define("L_NO_PS_SEARCH", "Nemáte právo mìnit nastavení vyhledávání");

define("L_BAD_RETYPED_PWD", "Vyplnìná hesla si neodpovídají");
define("L_ERR_USER_ADD", "Nepodaøilo se pøidat uivatele do systému - chyba LDAP");
define("L_NEWUSER_OK", "Uivatel byl úspìšnì pøidán do systému");
define("L_COMPACT_OK", "Vzhled pøehledu zpráv byl úspìšnì zmìnìm");
define("L_BAD_ITEM_ID", "Špatné èíslo zprávy");
define("L_ALL", " - vše - ");
define("L_CAT_LIST", "Kategorie zpráv");
define("L_CAT_SELECT", "Kategorie v tomto webíku");
define("L_NEW_SLICE", "Novı webík");
define("L_ASSIGN", "Pøiøadit");
define("L_CATBINDS_OK", "Nastavení kategorií bylo úspìšnì zmìnìno");
define("L_IMPORT_OK", "Nastavení vımìny zpráv úspìšnì zmìnìno");
define("L_FIELDS_OK", "Nasavení poloek úspìšnì zmìnìno");
define("L_SEARCH_OK", "Nastavení vyhledávacího formuláøe úspìšnì zmìnìno");
define("L_NO_CATEGORY", "Kategorie nebyly definovány");
define("L_NO_IMPORTED_SLICE", "Není nastaven ádnı webík, ze kterého se mají pøijímat zprávy");
define("L_NO_USERS", "Uivatel (skupina) nenalezena");

define("L_TOO_MUCH_USERS", "Nalezeno pøíliš mnoho uivatelù èi skupin.");
define("L_MORE_SPECIFIC", "Zkuste zadat pøesnìjší údaje.");
define("L_REMOVE", "Odstranit");
define("L_ID", "Id");
define("L_SETTINGS", "Nastavení");
define("L_LOGO", "APC toolkit");
define("L_USER_MANAGEMENT", "Uivatelé");
define("L_ITEMS", "Správa pøíspìvkù");
define("L_NEW_SLICE_HEAD", "Novı webík");
define("L_ERR_USER_CHANGE", "Nelze zmìnit data uivatele - LDAP Error");
define("L_PUBLISHED", "Zveøejnìno");
define("L_EXPIRED", "Vypršeno");
define("L_NOT_PUBLISHED", "Dosud nepublikováno");
define("L_EDIT_USER", "Editace uivatele");
define("L_EDITUSER_HDR", L_EDIT_USER);

define("NO_SOURCE_URL", "javascript: window.alert('Nebylo zadáno url zdroje')"); 
define("NO_OUTER_LINK_URL", "#top");

# editor interface constants
define("L_PUBLISHED_HEAD", "PUB");
define("L_HIGHLIGHTED_HEAD", "!");
define("L_FEEDED_HEAD", "cizí");
define("L_MORE_DETAILS", "Více podrobností");
define("L_LESS_DETAILS", "Ménì podrobností");
define("L_UNSELECT_ALL", "Zrušit vıbìr");
define("L_SELECT_VISIBLE", "Vybrat zobrazené");
define("L_UNSELECT_VISIBLE", "Zrušit vıbìr");

define("L_SLICE_ADM","Administrace webíku - Menu");
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

define("L_FEED", "Vımìna zpráv");
define("L_FEEDTO_TITLE", "Pøedat zprávu do webíku");
define("L_FEED_TO", "Pøedat vybrané zprávy do zvolenıch webíku");
define("L_NO_PERMISSION_TO_FEED", "Nelze");
define("L_NO_PS_CONFIG", "Nemáte právo nastavovat configuraèní parametry tohoto webíku");
define("L_SLICE_CONFIG", "Administrace");
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

define("L_NO_PS_NEW_USER", "Nemáte právo vytvoøit uivatele");
define("L_ALL_GROUPS", "Všechny skupiny");
define("L_USERS_GROUPS", "Uivatelovy skupiny");
define("L_REALY_DELETE_USER", "Opravdu chcete vymazat daného uivatele ze systému?");
define("L_REALY_DELETE_GROUP", "Opravdu chcete vymazat danou skupinu ze systému?");
define("L_TOO_MUCH_GROUPS", "Too much groups found.");
define("L_NO_GROUPS", "Skupina nenalezena");
define("L_GROUP_NAME", "Jméno");
define("L_GROUP_DESCRIPTION", "Popis");
define("L_GROUP_SUPER", "Administrativní skupina");
define("L_ERR_GROUP_ADD", "Nelze pøidat skupinu do systému");
define("L_NEWGROUP_OK", "Skupina byla úspìšnì pøidána");
define("L_ERR_GROUP_CHANGE", "Nelze zmìnit skupinu");
define("L_A_UM_USERS_TIT", "Správa uivatelù - Uivalelé");
define("L_A_UM_GROUPS_TIT", "Správa uivatelù - Skupiny");
define("L_EDITGROUP_HDR", "Editace skupiny");
define("L_NEWGROUP_HDR", "Nová skupina");
define("L_GROUP_ID", "ID skupiny");
define("L_ALL_USERS", "Všichni uivatelé");
define("L_GROUPS_USERS", "Uivatelé ve skupinì");
define("L_POST", "Poslat");
define("L_POST_PREV", "Poslat a prohlédnout");
define("L_INSERT_PREV", "Vloit & Prohlédnout");
define("L_OK", "OK");
define("L_ACTIVE_BIN_EXPIRED", "Aktuální - Expirované");
define("L_ACTIVE_BIN_PENDING", "Aktuální - Pøipravené");
define("L_ACTIVE_BIN_EXPIRED_MENU", "... expirované");
define("L_ACTIVE_BIN_PENDING_MENU", "... pøipravené");
 
define("L_FIELD_PRIORITY", "Øazení");
define("L_FIELD_TYPE", "Typ");
define("L_CONSTANTS", "Hodnoty");
define("L_DEFAULT", "Default");
define("L_DELETE_FIELD", "Opravdu chcete vymazat toto pole z webíku?");
define("L_FEEDED", "Pøejato");
define("L_HTML_DEFAULT", "defaultnì pouít HTML kód");
define("L_HTML_SHOW", "Zobrazit volbu 'HTML' / 'prostı text'");
define("L_NEW_OWNER", "Novı vlastník");
define("L_NEW_OWNER_EMAIL", "E-mail nového vlastníka");
define("L_NO_FIELDS", "V tomto webíku nejsou definována ádná pole (co je divné)");
define("L_NO_FIELD", "");
define("L_NO_PERM_TO_FEED", "Nemáte právo nastavit vımìnu zpráv s ádnım webíkem");
define("L_NO_SLICES", "ádnı webík");
define("L_NO_TEMPLATES", "ádná šablona");
define("L_OWNER", "Vlastník");
define("L_SLICES", "Webíky");
define("L_TEMPLATE", "Šablona");
define("L_VALIDATE", "Zkontrolovat");

define("L_FIELD_DELETE_OK", "Pole odstranìno");

define("L_WARNING_NOT_CHANGE","<p>POZOR: Tato nastavení by mìl mìnit jen ten, kdo ví co dìlá!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Funkce, která se pouije pro zobrazení pole ve vstupním formuláøi. Nìkteré pouívají Konstanty, nìkteré pouívají Parametry. Více informací se dozvíte, kdy pouijete Prùvodce s Nápovìdou.");
define("L_INPUT_SHOW_FUNC_C_HLP","Vyberte Skupinu Konstant nebo Webík.");
define("L_INPUT_SHOW_FUNC_HLP","Parametry jsou oddìleny dvojteèkou (:) nebo (ve speciálních pøípadech) apostrofem (').");
define("L_INPUT_DEFAULT_F_HLP","Funkce, která se pouije pro generování defaultních hodnot pole:<BR>Now - aktuální datum<BR>User ID - identifikátor pøihlášeného uivatele<BR>Text - text uvedenı v poli Parametr<br>Date - aktuální datum plus <Parametr> dní");
define("L_INPUT_DEFAULT_HLP","Parametr pro defaulní hodnoty Text a Date (viz vıše)");

define("L_INPUT_DEFAULT_TXT", "Text");
define("L_INPUT_DEFAULT_DTE", "Date");
define("L_INPUT_DEFAULT_UID", "User ID");
define("L_INPUT_DEFAULT_NOW", "Now");
define("L_INPUT_DEFAULT_VAR", "Variable"); # Added by Ram on 5th March 2002

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
define("L_INPUT_SHOW_ISI","Related Item Select Box");
define("L_INPUT_SHOW_ISO","Related Item Window");
define("L_INPUT_SHOW_WI2","Two Boxes");
define("L_INPUT_SHOW_PRE","Select Box with Presets");
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
define("L_INPUT_INSERT_IDS","Item IDs");
define("L_INPUT_INSERT_BOO","Boolean");
define("L_INPUT_INSERT_UID","User ID");
define("L_INPUT_INSERT_NOW","Now");
define("L_INPUT_INSERT_FIL","File");
define("L_INPUT_INSERT_NUL","None");

define("L_INPUT_DEFAULT","Default");
define("L_INPUT_BEFORE","HTML kód pøed tímto polem");
define("L_INPUT_BEFORE_HLP","HTML kód, kterı se zobrazí ve vstupním formuláøi pøed tímto polem");
define("L_INPUT_FUNC","Typ Vstupu");
define("L_INPUT_HELP","Nápovìda");
define("L_INPUT_HELP_HLP","Nápovìda zobrazená pro toto pole ve vstupním formuláøi");
define("L_INPUT_MOREHLP","Více informací");
define("L_INPUT_MOREHLP_HLP","Nápovìda, která se zobrazí po stisku '?' ve vstupním formuláøi");
define("L_INPUT_INSERT_HLP","Zpùsob uloení do databáze");
define("L_INPUT_VALIDATE_HLP","Funkce pro kontrolu vstupu (validace)");

define("L_CONSTANT_NAME", "Jméno");
define("L_CONSTANT_VALUE", "Hodnota");
define("L_CONSTANT_PRIORITY", "Øazení");
define("L_CONSTANT_PRI", "Øazení");
define("L_CONSTANT_GROUP", "Skupina hodnot");
define("L_CONSTANT_GROUP_EXIST", "Tato skupina hodnot ji existuje");
define("L_CONSTANTS_OK", "Zmìna hodnot úspìšnì provedena");
define("L_A_CONSTANTS_TIT", "Správa webíku - Nastavení hodnot");
define("L_A_CONSTANTS_EDT", "Správa webíku - Nastavení hodnot");
define("L_CONSTANTS_HDR", "Hodnoty");
define("L_CONSTANT_NAME_HLP", "zobrazeno&nbsp;ve&nbsp;vstupním&nbsp;formuláøi");
define("L_CONSTANT_VALUE_HLP", "uloeno&nbsp;v&nbsp;databázi");
define("L_CONSTANT_PRI_HLP", "Poøadí&nbsp;hodnot");
define("L_CONSTANT_CLASS", "Nadkategorie");
define("L_CONSTANT_CLASS_HLP", "jen&nbsp;pro&nbsp;kategorie");
define("L_CONSTANT_DEL_HLP", "Pro odstranìní kartegorie vymate její jméno");

$L_MONTH = array( 1 => 'Leden', 'Únor', 'Bøezen', 'Duben', 'Kvìten', 'Èerven', 
		'Èervenec', 'Srpen', 'Záøí', 'Øíjen', 'Listopad', 'Prosinec');

define("L_NO_CATEGORY_FIELD","Pole kategorie není v tomto webíku definováno.<br>  Pøidejte pole kategorie do webíku na stránce Políèka.");
define("L_PERMIT_ANONYMOUS_POST","Anonymní vkládání");
define("L_PERMIT_OFFLINE_FILL","Off-line plnìní");
define("L_SOME_CATEGORY", "<kategorie>");

define("L_ALIASES", "Aliasy pro políèka v databázi");
define("L_ALIAS1", "Alias 1"); 
define("L_ALIAS_HLP", "Musí zaèínat znaky \"_#\".<br>Alias musí bıt pøesnì 10 znakù dlouhı vèetnì \"_#\".<br>Mìl by bıt kapitálkami."); 
define("L_ALIAS_FUNC", "Funkce"); 
define("L_ALIAS_FUNC_F_HLP", "Funkce, která zajistí zobrazení políèka na stránce"); 
define("L_ALIAS_FUNC_HLP", "Doplòkovı parametr pøedávanı zobrazovací funkci. Podrobnosti viz include/item.php3 file"); 
define("L_ALIAS_HELP", "Nápovìda"); 
define("L_ALIAS_HELP_HLP", "Nápovìdnı textík pro tento alias"); 
define("L_ALIAS2", "Alias 2"); 
define("L_ALIAS3", "Alias 3"); 

define("L_TOP_HLP", "HTML kód, kterı se zobrazí pøed kódem webíku");
define("L_FORMAT_HLP", "Sem patøí HTML kód v kombinaci s aliasy uvedenımi dole na stránce
                     <br>Aliasy budou v okamiku zobrazování na web nahrazeny skuteènımi hodnotami z databáze");
define("L_BOTTOM_HLP", "HTML kód, kterı se zobrazí za vlasním kódem webíku");
define("L_EVEN_ROW_HLP", "TIP: Rozlišením sudıch a lichıch záznamù lze docílit napøíklad odlišení øádkù jinımi barvami pozadí
                         - první tøeba zelenı, druhı lutı, atd.");

define("L_SLICE_URL", "URL webíku");
define( "L_BRACKETS_ERR", "Brackets doesn't match in query: ");
define("L_A_SLICE_ADD_HELP", "Novı webík mùete vytvoøit na základì šablony, nebo zkopírovat nastavení z ji existujícího webíku (vytvoøí se pøesná kopie vèetnì nastavení .");
define("L_REMOVE_HLP", "Odstraní prázdné závorky atd. Pouijte ## jako oddìlovaè.");

define("L_COMPACT_HELP", "Na této stránce lze nastavit, co se objeví na stránce pøehledu zpráv");
define("L_A_FULLTEXT_HELP", "Na této stránce lze nastavit, co se objeví na stránce pøi prohlíení tìla zprávy");
define("L_PROHIBITED", "Zakázáno");
define("L_HTML", "HTML");
define("L_PLAIN_TEXT", "Prostı text");
define("L_A_DELSLICE", "Správa webíku - Vymazání webíku");
define("L_DELSLICE_TIT", L_A_DELSLICE);
define("L_DELSLICE_HDR", "Vyber webík pro smazání");
define("L_DEL_SLICE_HLP","<p>Lze vymazat jen webíky, které byly oznaèeny pro vymazání na stránce &quot;<b>". L_SLICE_SET ."</b>&quot;</p>");
define("L_A_DELSLICE", L_DELSLICE_TIT);
define("L_DELETE_SLICE", "Opravdu chcete vymazat tento webík a všechny jeho zprávy?");
define("L_NO_SLICE_TO_DELETE", "ádnı webík nebyl oznaèen za vymazanı");
define("L_NO_SUCH_SLICE", "Špatné èíslo webíku");
define("L_NO_DELETED_SLICE", "Webík není oznaèen za vymazanı");
define("L_DELSLICE_OK", "Webík byl vymazán, tabulky byly optimalizovány");
define("L_DEL_SLICE", "Smazat Webík");
define("L_FEED_STATE", "Sdílení tohoto pole");
define("L_STATE_FEEDABLE", "Kopírovat obsah" );
define("L_STATE_UNFEEDABLE", "Nekopirovat" );
define("L_STATE_FEEDNOCHANGE", "Kopírovat nemìnitelnì" );
define("L_INPUT_FEED_MODES_HLP", "Má se kopírovat obsah tohoto políèka do dalších webíkù pøi vımìnì zpráv mezi webíky?");
define("L_CANT_CREATE_IMG_DIR","Nelze vytvoøit adresáø pro obrázky");

  # constants for View setting 
define('L_VIEWS','Pohledy');
define('L_ASCENDING','Vzestupnì');
define('L_DESCENDING','Sestupnì');
define('L_NO_PS_VIEWS','Nemáte právo mìnit pohledy');
define('L_VIEW_OK','Pohled byl úspìšnì zmìnìn');
define('L_A_VIEW_TIT','Správa webíku - definice Pohledu');
define('L_A_VIEWS','Správa webíku - definice Pohledu');
define('L_VIEWS_HDR','Definované pohledy');
define('L_VIEW_DELETE_OK','Pohled by úspìšnì smazán');
define('L_DELETE_VIEW','Opravdu chcete smazat vybranı pohled?');
define('L_V_BEFORE',L_COMPACT_TOP);
define('L_V_ODD',L_ODD_ROW_FORMAT);
define('L_V_EVENODDDIF',L_EVEN_ODD_DIFFER);
define('L_V_EVEN',L_EVEN_ROW_FORMAT);
define('L_V_AFTER',L_COMPACT_BOTTOM);
define('L_V_GROUP_BY1','Seskupit dle');
define('L_V_GROUP1DIR',' ');
define('L_V_GROUP_BY2',L_V_GROUP_BY1);
define('L_V_GROUP2DIR',' ');
define('L_V_GROUP','Nadpis skupiny');
define('L_V_REMOVE_STRING',L_COMPACT_REMOVE);
define('L_V_MODIFICATION','Typ');
define('L_V_PARAMETER','Parametr');
define('L_V_IMG1','Obrázek 1');
define('L_V_IMG2','Obrázek 2');
define('L_V_IMG3','Obrázek 3');
define('L_V_IMG4','Obrázek 4');
define('L_V_ORDER1','Seøadit');
define('L_V_ORDER1DIR',' ');
define('L_V_ORDER2','Seøadit druhotnì');
define('L_V_ORDER2DIR',' ');
define('L_V_SELECTED','Pouít vybranı èlánek');
define('L_V_COND1FLD','Podmínka 1');
define('L_V_COND1OP',' ');
define('L_V_COND1COND',' ');
define('L_V_COND2FLD','Podmínka 2');
define('L_V_COND2OP',' ');
define('L_V_COND2COND',' ');
define('L_V_COND3FLD','Podmínka 3');
define('L_V_COND3OP',' ');
define('L_V_COND3COND',' ');
define('L_V_LISTLEN',L_D_LISTLEN);
define('L_V_FLAG','Flag');
define('L_V_SCROLLER','Zobrazit rolování stránek');
define('L_V_ADITIONAL','Další');
define('L_COMPACT_VIEW','Pøehled');
define('L_FULLTEXT_VIEW','Èlánek');
define('L_DIGEST_VIEW','Obsah - souhrn');
define('L_DISCUSSION_VIEW','Diskuse');
define('L_RELATED_VIEW','Související zprávy');
define('L_CONSTANT_VIEW','Zobrazení konstant');
define('L_RSS_VIEW','Vımìna zpráv RSS');
define('L_STATIC_VIEW','Statická stránka');
define('L_SCRIPT_VIEW','Javscript');

define("L_MAP","Mapování");
define("L_MAP_TIT","Správa webíku - vımìna zpráv - mapování polí");
define("L_MAP_FIELDS","Mapování polí");
define("L_MAP_TABTIT","Vımìna zpráv - mapování polí");
define("L_MAP_FROM_SLICE","Mapování z webíku");
define("L_MAP_FROM","Z");
define("L_MAP_TO","Do");
define("L_MAP_DUP","Nelze mapovat do stejného pole");
define("L_MAP_NOTMAP","-- Nemapovat --");
define("L_MAP_OK","Nastavení mapování polí úspì¹nì zmìnìno");
    
define("L_STATE_FEEDABLE_UPDATE", "Kopírovat obsah a zmìny" );
define("L_STATE_FEEDABLE_UPDATE_LOCKED", "Kopírovat obsah a zmìny nemìnitelnì");

define("L_SITEM_ID_ALIAS",'alias pro zkrácené èíslo èlánku');
define("L_MAP_VALUE","-- Hodnota --");
define("L_MAP_VALUE2","Hodnota");
define("L_ORDER", "Seøadit");
define("L_INSERT_AS_NEW","Vloit jako novı");

// constnt view constants
define("L_CONST_NAME_ALIAS", "Jméno");
define("L_CONST_VALUE_ALIAS", "Hodnota");
define("L_CONST_PRIORITY_ALIAS", "Øazení");
define("L_CONST_GROUP_ALIAS", "Skupina hodnot");
define("L_CONST_CLASS_ALIAS", "Nadkategorie (pouitelné jen pro kategorie)");
define("L_CONST_COUNTER_ALIAS", "Poøadové èíslo hodnoty");
define("L_CONST_ID_ALIAS", "Identifikaèní èíslo hodnoty");

define('L_V_CONSTANT_GROUP','Skupina hodnot');
define("L_NO_CONSTANT", "Hodnota nenalezena");

// Discussion constants.
define("L_DISCUS_SEL","Zobrazit diskusi");
define("L_DISCUS_EMPTY"," -- ádná -- ");
define("L_DISCUS_HTML_FORMAT","Diskusi formátovat v HTML");
define("L_EDITDISC_ALIAS","Alias pouívanı v administrativních stránkách index.php3 pro URL discedit.php3");

define("L_D_SUBJECT_ALIAS","Alias pro pøedmìt pøíspìvku");
define("L_D_BODY_ALIAS"," Alias pro text pøíspìvku");
define("L_D_AUTHOR_ALIAS"," Alias pro autora pøíspìvku");
define("L_D_EMAIL_ALIAS","Alias pro e-mail autora");
define("L_D_WWWURL_ALIAS","Alias pro adresu WWW stránek autora ");
define("L_D_WWWDES_ALIAS","Alias for popis WWW stránek autora");
define("L_D_DATE_ALIAS","Alias pro datum a èas poslání pøíspìvku");
define("L_D_REMOTE_ADDR_ALIAS","Alias pro IP adresu autorova poèítaèe");
define("L_D_URLBODY_ALIAS","Alias pro odkaz na text pøíspìvku<br>
                             <i>Uití: </i>v kódu pro pøehledové zobrazení pøíspìvku<br>
                             <i>Pøíklad: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>");
define("L_D_CHECKBOX_ALIAS","Alias pro checkbox pro vybrání pøíspìvku");
define("L_D_TREEIMGS_ALIAS","Alias pro obrázky");
define("L_D_ALL_COUNT_ALIAS","Alias pro poèet všech pøíspìvkù k danému èlánku");
define("L_D_APPROVED_COUNT_ALIAS","Alias pro poèet schválenıch pøíspìvkù k danému èlánku");
define("L_D_URLREPLY_ALIAS","Alias pro odkaz na formuláø<br>
                             <i>Uití: </i>v kódu pro plné znìní pøíspìvku<br>
                             <i>Pøíklad: </i>&lt;a href=_#URLREPLY&gt;Odpovìdìt&lt;/a&gt;");
define("L_D_URL","Alias pro odkaz na diskusi<br>
                             <i>Uití: </i>v kódu formuláøe<br>
                             <i>Pøíklad: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">");
define("L_D_ID_ALIAS"," Alias pro èíslo pøíspìvku<br>
                             <i>Uití: </i>v kódu formuláøe<br>
                             <i>Pøíklad: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">");
define("L_D_ITEM_ID_ALIAS"," Alias pro èíslo èlánku<br>
                             <i>Uití: </i>v kódu formuláøe<br>
                             <i>Pøíklad: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">");

define("L_D_BUTTONS","Alias pro tlaèítka Zobraz vše, Zobraz vybrané, Pøidej novı<br>
                             <i>Uití: </i>ve spodním HTML kódu");

define("L_D_COMPACT" , "HTML kód pro pøehledové zobrazení pøíspìvku");
define("L_D_SHOWIMGS" , "Zobrazit obrázky");
define("L_D_ORDER" , "Seøadit");
define("L_D_FULLTEXT" ,"HTML kód pro plné znìní pøíspìvku");

define("L_D_ADMIN","Správa zpráv - Správa diskusních pøíspìvkù");
define("L_D_NODISCUS","ádné diskusní pøíspìvky");
define("L_D_TOPIC","Titulek");
define("L_D_AUTHOR","Autor");
define("L_D_DATE","Datum");
define("L_D_ACTIONS","Akce");
define("L_D_DELETE","Smazat");
define("L_D_EDIT","Editovat");
define("L_D_HIDE","Skrıt");
define("L_D_APPROVE","Schválit");

define("L_D_EDITDISC","Správa zpráv - Správa diskusních pøíspìvkù - Editace pøíspìvku");
define("L_D_EDITDISC_TABTIT","Editace pøíspìvku");
define("L_D_SUBJECT","Pøedmìt");
define("L_D_AUTHOR","Autor");
define("L_D_EMAIL","E-mail");
define("L_D_BODY","Text pøíspìvku");
define("L_D_URL_ADDRESS","WWW autora - URL");
define("L_D_URL_DES","WWW autora - popis");
define("L_D_REMOTE_ADDR","IP adresa poèítaèe autora");

define('L_D_SELECTED_NONE',"Nebyl vybrán ádnı pøíspìvek");
define("L_D_DELETE_COMMENT","Pøejete si smazat pøíspìvek?");

define("L_D_FORM","HTML kód formuláøe pro poslání pøíspìvku");
define("L_D_BACK","Zpìt");
define("L_D_ITEM","Èlánek: ");
define("L_D_ADMIN2","Správa diskusních pøíspìvkù");

define("L_D_SHOW_SELECTED","Zobraz vybrané");
define("L_D_SHOW_ALL","Zobraz vše");
define("L_D_ADD_NEW","Pøidej novı");

define("L_TOO_MUCH_RELATED","Je vybráno pøíliš mnoho souvisejících èlánkù.");
define("L_SELECT_RELATED","Vıbìr souvisejících èlánkù");
define("L_SELECT_RELATED_1WAY","Ano");
define("L_SELECT_RELATED_2WAY","Vzájemnì");
define("L_SELECT_RELATED_BACK", "Zpìtnì");

// - Cross server networking --------------------------------------

define("L_INNER_IMPORT","Lokální vımìna");
define("L_INTER_IMPORT","Pøíjem z uzlù");
define("L_INTER_EXPORT","Zasílání do uzlù");

define("L_NODES_MANAGER","Uzly");
define("L_NO_PS_NODES_MANAGER","Nemáte práva pro správu uzlù");
define("L_NODES_ADMIN_TIT","Správa uzlù");
define("L_NODES_LIST","Seznam uzlù");
define("L_NODES_ADD_NEW","Pøidání uzlu");
define("L_NODES_EDIT","Editace uzlu");
define("L_NODES_NODE_NAME","Jméno uzlu ");
define("L_NODES_YOUR_NODE","Your node name");
define("L_NODES_SERVER_URL","URL souboru getxml.php3");
define("L_NODES_YOUR_GETXML","Your getxml is");
define("L_NODES_PASWORD","Heslo");
define("L_SUBMIT","Poslat");
define("L_NODES_SEL_NONE","Nebyl vybrán uzel");
define("L_NODES_CONFIRM_DELETE","Opravdu chcete smazat uzel?");
define("L_NODES_NODE_EMPTY","Jméno uzlu musí bıt vyplnìno");

define("L_IMPORT_TIT","Správa pøijímanıch webíkù");
define("L_IMPORT_LIST","Seznam pøijímanıch webíkù do webíku ");
define("L_IMPORT_CONFIRM_DELETE","Opravdu chcete zrušit pøíjem z tohoto webíku?");
define("L_IMPORT_SEL_NONE","Nebyl zvolen webík");
define("L_IMPORT_NODES_LIST","Seznam uzlù");
define("L_IMPORT_CREATE","Pøijímat webíky z tohoto uzlu");
define("L_IMPORT_NODE_SEL","Nebyl vybrán uzel");
define("L_IMPORT_SLICES","Seznam pøijímanıch webíkù");
define("L_IMPORT_SLICES2","Seznam dostupnıch webíkù z uzlu ");
define("L_IMPORT_SUBMIT","Zvolte webík");
define("L_IMPORT2_OK","Pøíjem z webíku úspìšnì vytvoøen");
define("L_IMPORT2_ERR","Pøíjem z webíku byl ji vytvoøen");

define("L_RSS_ERROR","Nepodaøilo se navázat spojení nebo pøijmout data. Kontaktuje administrátora");
define("L_RSS_ERROR2","Neplatné heslo pro uzel: ");
define("L_RSS_ERROR3","Kontaktujte administrátora lokálního uzlu.");
define("L_RSS_ERROR4","ádné dostupné webíky. Nemáte práva pøijímat data z tohoto uzlu. ".
 "Kontaktujte administrátora vzdáleného webíku a zkontrolujte, e obdrel vaše správné uivatelské jméno.");

define("L_EXPORT_TIT","Správa povolení zasílání webíkù");
define("L_EXPORT_CONFIRM_DELETE","Opravdu chcete zrušit povolení zasílání tohoto webíku?");
define("L_EXPORT_SEL_NONE","Nebyl zvolen uzel a uivatel");
define("L_EXPORT_LIST","Seznam uzlù a uivatelù, kam bude zasílán webík ");
define("L_EXPORT_ADD","Pøidejte uzel a uivatele");
define("L_EXPORT_NAME","Jméno uivatele");
define("L_EXPORT_NODES","Seznam uzlù");

define("L_RSS_TITL", "Jméno webíku pro RSS");
define("L_RSS_LINK", "Odkaz na webík pro RSS");
define("L_RSS_DESC", "Krátkı popisek (vlastník a jméno) webíku pro RSS");
define("L_RSS_DATE", "Datum v RSS pøehledu je generováno v datovém formátu RSS");

define("L_NO_PS_EXPORT_IMPORT", "Nemáte právo exportovat / importovat webíky");
define("L_EXPORT_SLICE", "Export");
define("L_IMPORT_SLICE", "Import");
define("L_EXPIMP_SET", "Struktura webíku");

define("L_E_EXPORT_TITLE", "Export struktury webíku");
define("L_E_EXPORT_MEMO", "Vyberte si jeden ze dvou zpùsobù exportu:");
define("L_E_EXPORT_DESC", "Pøi exportu \"do jiného Toolkitu\" se bude exportovat pouze aktuální šablona "
		."a vy pro ni zvolíte novı identifikátor.");
define("L_E_EXPORT_DESC_BACKUP", "Pøi exportu \"do Backupu\" si mùete vybrat nìkolik šablon najednou.");
define("L_E_EXPORT_MEMO_ID","Zvolte novı identifikátor šablony o délce pøesnì 16 znakù: ");
define("L_E_EXPORT_SWITCH", "Export do Backupu");
define("L_E_EXPORT_SWITCH_BACKUP", "Export do jiného Toolkitu");
define("L_E_EXPORT_IDLENGTH", "Délka identifikátoru musí bıt 16 znakù, a ne ");
define("L_E_EXPORT_TEXT_LABEL", "Tento text si nìkde ulote. Mùete ho pouít pro naimportování šablony do Toolkitu:");
define("L_E_EXPORT_LIST", "Oznaète webíky, které CHCETE exportovat:");

define("L_PARAM_WIZARD_LINK", "Prùvodce s nápovìdou");
define("L_SHOW_RICH", "Zobraz toto pole v rich text editoru (pouijte a po nainstalování potøebnıch komponent!)");
define("L_MAP_JOIN","-- Spojení polí --");

// aliases used in se_notify.php3 
define("L_NOTIFY_SUBJECT", "Pøedmìt e-mailu (Subject)"); 
define("L_NOTIFY_BODY", "Vlastní e-mailová zpráva"); 
define("L_NOTIFY_EMAILS", "E-mailová adresa (jedna na øádek)");
define("L_NOTIFY_HOLDING", "<h4>Nová zpráva v Zásobníku</h4> Kdokoliv mùe bıt informován o tom, e pøibyla nová zpráva do zásobníku. Adresy pøíjemcù napište níe, do následujících políèek pak vyplòte, jak má vypadat e-mail, kterı pak uivatelé dostanou."); 
define("L_NOTIFY_HOLDING_EDIT", "<h4>Zpráva v Zásobníku byla zmìnìna</h4> Kdokoliv mùe bıt informován o tom, e byla zmìnìna zpráva v zásobníku. Adresy pøíjemcù napište níe, do následujících políèek pak vyplòte, jak má vypadat e-mail, kterı pak uivatelé dostanou."); 
define("L_NOTIFY_APPROVED", "<h4>Nová zpráva mezi Aktuálními</h4> Kdokoliv mùe bıt informován o tom, e pøibyla nová zpráva na web. Adresy pøíjemcù napište níe, do následujících políèek pak vyplòte, jak má vypadat e-mail, kterı pak uivatelé dostanou."); 
define("L_NOTIFY_APPROVED_EDIT", "<h4>Aktuální zpráva zmìnìna</h4> Kdokoliv mùe bıt informován o tom, e byla zmìnìna vystavená zpráva. Adresy pøíjemcù napište níe, do následujících políèek pak vyplòte, jak má vypadat e-mail, kterı pak uivatelé dostanou.");
define("L_NOTIFY", "Upozornìní e-mailem"); 
define("L_A_NOTIFY_TIT", "E-mailová upozornìní na události");

define("L_NOITEM_MSG", "Hláška 'Nenalezena ádná zpráva'");
define("L_NOITEM_MSG_HLP", "zpráva, která se objeví pøi nenalezení ádného odpovídajícího èlánku");

# ---------------- Users profiles -----------------------------------------
define('L_PROFILE','Profil');
define('L_DEFAULT_USER_PROFILE','Spoleènı profil');
define('L_PROFILE_DELETE_OK','Pravidlo úspìšnì vymazáno');
define('L_PROFILE_ADD_OK','Pravidlo pøidáno');
define('L_PROFILE_ADD_ERR','Chyba pøi pøidávání nového pravidla');
define('L_PROFILE_LISTLEN','Poèet zpráv');
define('L_PROFILE_ADMIN_SEARCH','Filtr zpráv');
define('L_PROFILE_ADMIN_ORDER','Øazení');
define('L_PROFILE_HIDE','Skrıt políèko');
define('L_PROFILE_HIDEFILL','Skrıt a vyplnit');
define('L_PROFILE_FILL','Vyplnit políèko');
define('L_PROFILE_PREDEFINE','Pøednastavit políèíko');
define('L_A_PROFILE_TIT','Správa webíku - Uivatelské profily');
define('L_PROFILE_HDR','Nastavená pravidla');
define('L_NO_RULE_SET','ádné pravidlo nebylo definováno');
define('L_PROFILE_ADD_HDR','Pøidat pravidlo');
define('L_PROFILE_LISTLEN_DESC','poèet zpráv zobrazenıch v administraci');
define('L_PROFILE_ADMIN_SEARCH_DESC','pøednastavení "Hledání" v administraci');
define('L_PROFILE_ADMIN_ORDER_DESC','pøednastavení "Seøadit" v administraci');
define('L_PROFILE_HIDE_DESC','skıt políèko ve vstupním foruláøi');
define('L_PROFILE_HIDEFILL_DESC','skıt políèko ve vstupním foruláøi a vyplnit je danou hodnotou');
define('L_PROFILE_FILL_DESC','vyplnit políèko ve vstupním foruláøi vdy danou hodnotou');
define('L_PROFILE_PREDEFINE_DESC','pøednastavit hodnotu do políèka ve vstupním formuláøi');
define('L_VALUE',L_MAP_VALUE2);
define('L_FUNCTION',L_ALIAS_FUNC);
define('L_RULE','Pravidlo');

define('L_ID_COUNT_ALIAS','poèet nalezenıch èlánkù');
define('L_V_NO_ITEM','HTML kod namísto zprávy "Nenalezena ádná zpráva"');
define("L_NO_ITEM_FOUND", "Nenalezena ádná zpráva");
define('L_INPUT_SHOW_HCO','Hierachie konstant');

define("L_CONSTANT_HIERARCH_EDITOR","Editovat v Hierarchickém editoru (umoòuje urèit hierarchii hodnot)");
define("L_CONSTANT_PROPAGATE","Propagovat zmìny do stávajících èlánkù");
define("L_CONSTANT_OWNER","Vlastník skupiny - webík");
define("L_CONSTANT_HIER_SORT","Zmìny nebudou uloeny do databáze, dokud nestisknete tlaèítko dole na stránce.<br>Konstanty jsou øazeny zaprvé podle Øazení a zadruhé podle Názvu.");
define("L_CONSTANT_DESC","Popis");
define("L_CONSTANT_HIER_SAVE","Ulo všechny zmìny do databáze");
define("L_CONSTANT_CHOWN", "Zmìnit vlastníka");
define("L_CONSTANT_OWNER_HELP", "Vlastníkem se stane první webík, kterı upraví hodnoty.");
define("L_NO_PS_FIELDS_GROUP","Nemáte administrátorská práva k webíku, kterı vlastní tuto skupinu hodnot");
define("L_CONSTANTS_HIER_EDT","Konstanty - Hiearchickı editor");

define ("L_CONSTANT_LEVELS_HORIZONTAL", "Úrovnì horizontálnì");
define ("L_CONSTANT_VIEW_SETTINGS", "Zobrazení");
define ("L_CONSTANT_HIERARCHICAL", "Hierarchcké");
define ("L_CONSTANT_HIDE_VALUE", "Skryj hodnotu");
define ("L_CONSTANT_CONFIRM_DELETE","Zatrhnìte pro potvrzení mazání");
define ("L_CONSTANT_COPY_VALUE","Kopírovat hodnotu z názvu");
define ("L_CONSTANT_LEVEL_COUNT","Poèet úrovní");
define ("L_CONSTANT_LEVEL", "Úroveò");
define ("L_SELECT","Zvolit");
define ("L_ADD_NEW","Pøidat");

define("L_GROUP_BY", "Seskupit dle");
define("L_GROUP_BY_HLP", "");
define("L_GROUP_HEADER", "Nadpis skupiny");
define ("L_WHOLE_TEXT", "Celı text");
define ("L_FIRST_LETTER", "1. písmeno");
define ("L_LETTERS", "písmena");
define ("L_CASE_NONE", "Nemìnit");
define ("L_CASE_UPPER","VELKİMI");
define ("L_CASE_LOWER","malımi");
define ("L_CASE_FIRST","První Velké");

define("L_INPUT_VALIDATE_USER","Uivatel");			# added 03/01/02, setu@gwtech.org
define("L_INPUT_DEFAULT_VAR", "Promìnná"); # Added by Ram on 5th March 2002 (Only for English)

define("L_E_EXPORT_DESC_EXPORT","Zvolte, chcete-li exportovat strukturu webíku, data nebo obojí.");
define("L_E_EXPORT_EXPORT_DATA","Export dat");
define("L_E_EXPORT_EXPORT_STRUCT","Export struktury");
define("L_E_EXPORT_EXPORT_GZIP","Komprimovat");
define("L_E_EXPORT_EXPORT_TO_FILE","Uloit exportovaná data do souboru");
define("L_E_EXPORT_MUST_SELECT","Musíte vybrat nìjaké webíky pro zálohování");
define("L_E_EXPORT_SPEC_DATE","Export dat z urèitıch dnù: ");
define("L_E_EXPORT_FROM_DATE","Od ");
define("L_E_EXPORT_TO_DATE","do");
define("L_E_EXPORT_DATE_ERROR","Toto není platné datum");
define("L_E_EXPORT_DATE_TYPE_ERROR","Musíte pouít formát: DD.MM.YYYY");

define ("L_CALENDAR_VIEW", "Kalendáø");
define ("L_V_FROM_DATE", "Políèko zaèátku události");
define ("L_V_TO_DATE", "Políèko konce události");
define ("L_V_GROUP_BOTTOM", "Spodní kód skupiny");
define ("L_V_DAY", "Horní kód buòky s datem");
define ("L_V_DAY_BOTTOM", "Dolní kód buòky s datem");
define ("L_V_EVENT", "Kód události");
define ("L_V_EMPTY_DIFFER", "Pouít jinı nadpis pro prázdné buòky");
define ("L_V_DAY_EMPTY", "Horní kód pro prázdné datum");
define ("L_V_DAY_EMPTY_BOTTOM", "Spodní kód pro prázdné datum");
define ("L_MONTH", "Mìsíc - seznam");
define ("L_MONTH_TABLE", "Mìsíc - tabulka");
define ("L_V_CALENDAR_TYPE", "Typ kalendáøe");
define ("L_CONST_DELETE", "Smazat celou skupinu");
define ("L_CONST_DELETE_PROMPT","Jste si jisti, e chcete PERMANENTNÌ SMAZAT tuto skupinu? Napište ano èi ne.");
define ("L_NO", "ne");
define ("L_YES", "ano");
define ("L_V_EVENT_TD", "Další atributy do TD tagu pro událost");
define('L_C_TIMESTAMP1','Kalendáø: Time stamp v 0:00 pøíslušného data');
define('L_C_TIMESTAMP2', 'Kalendáø: Time stamp v 24:00 pøíslušného data');
define('L_C_NUMD','Kalendáø: Den v mìsíci pøíslušného data');
define('L_C_NUMM','Kalendáø: Èíslo mìsíce pøíslušného data');
define('L_C_NUMY','Kalendáø: Rok pøíslušného data');

define('L_CONSTANT_WHERE_USED', 'Kde jsou konstanty pouity?');
define('L_CONSTANT_USED','Hodnoty pouity v');

define('L_VIEW_CREATE_NEW', 'Vytvoøit novı pohled');    
define('L_VIEW_CREATE_TYPE', 'dle&nbsp;typu:');    
define('L_VIEW_CREATE_TEMPL', 'dle&nbsp;šablony:');
define('L_USE_AS_NEW', 'Nové&nbsp;dle&nbsp;vybranıch');
define('L_SLICE_NOT_CONST', 'V poli -Hodnoty- máte zvolen webík. Webík nelze takto mìnit. Vyberte nìjakou skupinu hodnot (vıše v seznamu).');


define ("L_E_IMPORT_DATA_COUNT", "Poèet importovanıch èlánkù: %d.");			
define ("L_E_IMPORT_ADDED", "Pøidán byl:");
define ("L_E_IMPORT_OVERWRITTEN", "Pøepsán byl:");
define ("L_CHOOSE_JUMP", "Zvolte modul, kterı chcete editovat");

define ("L_A_FIELD_IDS_TIT", "Správa webíku - Zmìna ID políèka");
define ("L_FIELD_IDS", "Zmìna ID políèka");
define ("L_FIELD_IDS_CHANGED", "ID políèka bylo zmìnìno");
define ("L_V_MONTH_LIST", "Month list (separated by ,)");
define ("L_F_JAVASCRIPT", "Javascript pro políèka");
define ("L_FIELD_ALIASES", "Aliasy");

define('L_CHANGE_FROM','Zmìnit z');
define('L_TO', 'na');
define('L_FIELD_ID_HELP',
    'Tato stránka umoòuje zmìnit identifikátory jednotlivıch políèek. 
     Je to pomìrnì nebezpeèná operace a mùe trvat dlouho. Je dost 
     pravdìpodobné, e tuto operaci nikdy nevyuijete - pouívá se jen 
     ve vıjimeènıch pøípadech (nastavení formuláøe pro vyhledávání ve více 
     webících.<br><br>
     Vyberte ID políèka, které chcete zmìnit a potom nové ID a èíslo. Teèky 
     budou automaticky doplnìny.<br>');

define('L_AA_ADMIN','Administrace AA Toolkitu');
define('L_SLICE_ADMIN','Administrace webíku');
define('L_AA_ADMIN2','AA');
define('L_SLICE_ADMIN2','Nastavení');
define('L_ARTICLE_MANAGER2','Správa zpráv');
define('L_MODULES', 'Webíky / Moduly');
define ('L_ADD_MODULE', "Novı");
define ('L_DELETE_MODULE', "Vymazat");
define ('L_A_MODULE_ADD', 'Vytvoøit novı Webík / Modul');
define ('L_A_SLICE', 'Webík');
define ('L_A_MODULE', 'Modul');

define('L_MODULE_NAME','Jméno modulu');
define('L_JUMP_TO','Pøejít na');
define('L_AA_RELATIVE','Zadej cestu relativnì k adresáøi AA - t.j.');
define('L_JUMP_SLICE','Pøejít do webíku');
define('L_A_JUMP_EDT','Editovat modul Jump');
define('L_A_JUMP_ADD','Vytvoøit novı Jump modul');
define('L_EDIT_JUMP','Editovat Jump');
define('L_MODULE_ID','ID modulu');
define('L_UPDATE','Zmìò');
define('L_CREATE','Vytvoø');

define("L_ASCENDING_PRI","Vzestupnì dle Øazení");
define("L_DESCENDING_PRI","Sestupnì dle Øazení");
define("L_SORT_DIRECTION_HLP","'dle Øazení' lze pouít jen pro pole pouívající konstant (kategorie) - tam take najdete hodnoty pro 'Øazení'");

define("L_ALERTS","Zasílání zpráv");
define("L_ALERTS_COLLECTIONS", "Kolekce");
define("L_ALERTS_USERS","Uivatelé");
define("L_ALERTS_UI","Uivatelské rozhraní.");
     
// constants used in param wizard only:
require  $GLOBALS[AA_INC_PATH]."en_param_wizard_lang.php3";

// new constants to be translated are here. Leave this "require" always at the 
// end of this file
// If You want to translate the new texts (which is in new_news_lang.php3 file),
// just copy them from the new_news_lang.php3 file here and translate.
// IMPORTANT: Leave the require new_news_lang.php3 as the last line of this
// file - it will not redefine the constant You translated and helps You when
// we add new texts !!!
require  $GLOBALS[AA_INC_PATH]."new_news_lang.php3";
?>