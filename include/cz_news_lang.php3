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
define("CONFIG_FILE", "cz_news_lang.php3");

define("HTML_PAGE_BEGIN", 
'<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'. ADMIN_CSS .'" 
        type="text/css" title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
               
define("IMG_UPLOAD_MAX_SIZE", "400000");    // max size of file in picture uploading
define("IMG_UPLOAD_URL", "http://web.ecn.cz/aauser/img_upload/");
define("IMG_UPLOAD_PATH", "/usr/local/httpd/htdocs/aauser/img_upload/");
define("EDITOR_GRAB_LEN", 200);                 // not used, i think
define("EDIT_ITEM_COUNT", 20);                  // number of items in editor window

# Default values for database fields
define("DEFAULT_EDIT_FIELDS",    // shown fields (headline if allways shown)
 "y".  // abstract
 "y".  // html_formatted
 "y".  // full_text
 "y".  // highlight
 "y".  // hl_href
 "y".  // link_only
 "y".  // place
 "y".  // source
 "y".  // source_href
 "y".  // status_code
 "y".  // language_code
 "y".  // cp_code
 "y".  // category_id
 "y".  // img_src
 "y".  // img_width
 "y".  // img_height
 "y".  // posted_by
 "y".  // e_posted_by
 "y".  // publish_date
 "y".  // expiry_date
 "y".  // edit_note
 "y".  // reserved
 "y".  // reserved
 "y".  // reserved
 "y".  // reserved
 "y".  // reserved
 "y".  // reserved
 "y".  // reserved
 "y".  // reserved
 "y"); // reserved
define("DEFAULT_NEEDED_FIELDS", 
 "y".  // abstract
 "n".  // html_formatted
 "n".  // full_text
 "n".  // highlight
 "n".  // hl_href
 "n".  // link_only
 "n".  // place
 "n".  // source
 "n".  // source_href
 "n".  // status_code
 "n".  // language_code
 "n".  // cp_code
 "n".  // category_id
 "n".  // img_src
 "n".  // img_width
 "n".  // img_height
 "n".  // posted_by
 "n".  // e_posted_by
 "n".  // publish_date
 "n".  // expiry_date
 "n".  // edit_note
 "n".  // reserved
 "n".  // reserved
 "n".  // reserved
 "n".  // reserved
 "n".  // reserved
 "n".  // reserved
 "n".  // reserved
 "n".  // reserved
 "n"); // reserved

define("DEFAULT_SEARCH_SHOW", 
 "n".  // slice
 "y".  // category
 "y".  // author
 "y".  // language
 "y".  // from
 "y".  // to
 "y".  // headline
 "y".  // abstract
 "y".  // full_text
 "y".  // edit_note
 "y".  // reserve
 "y".  // reserve
 "y".  // reserve
 "y"); // reserve
define("DEFAULT_SEARCH_DEFAULT", 
 "y".  // headline
 "y".  // abstract
 "y".  // full_text
 "n".  // edit_note
 "n".  // reserve
 "n".  // reserve 
 "n"); // reserve 
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

     
# database fields labels
define("L_HEADLINE", "Titulek");
define("L_POSTDATE", "Zaøazeno");
define("L_POSTED_BY", "Autor");
define("L_PUBLISH_DATE", "Datum zveøejnìní");
define("L_EXPIRY_DATE", "Datum vypršení");
define("L_CATEGORY", "Kategorie");
define("L_FIELDS", "Položky");
define("L_ABSTRACT", "Abstrakt");
define("L_FULL_TEXT", "Text pøíspìvku");
define("L_STATUS_CODE", "Stav");
define("L_LANGUAGE_CODE", "Jazyk");
define("L_CP_CODE", "Kódování");
define("L_LINK_ONLY", "Odkaz ven");
define("L_HL_HREF", "URL pro odkaz");
define("L_FT_FORMATTING", "Formátování");
define("L_FT_FORMATTING_HTML", "HTML kód");
define("L_FT_FORMATTING_PLAIN", "Prostý text"); 
define("L_HTML_FORMATTED", "HTML kód");
define("L_HIGHLIGHT", "Dùležitá zpráva");
define("L_IMG_SRC","URL obrázku"); 
define("L_IMG_WIDTH","Šíøka obrázku"); 
define("L_IMG_HEIGHT","Výška obrázku");
define("L_E_POSTED_BY","E-mail"); 
define("L_PLACE","Místo");
define("L_SOURCE","Zdroj");
define("L_SOURCE_HREF","URL zdroje");
define("L_CREATED_BY","Autor");
define("L_LASTEDIT","Naposled editoval");
define("L_AT","dne");   
define("L_EDIT_NOTE","Poznámka editora"); 
define("L_IMG_UPLOAD","Uložení obrázku"); 
define("L_CANT_UPLOAD","Obrázek nelze uložit"); 

# toolkit aplication dependent labels
define("L_HLP_HEADLINE",'alias pro titulek');
define("L_HLP_CATEGORY",'alias pro jméno kategorie');
define("L_HLP_HDLN_URL",'alias pro URL zprávy<br>(bude nahrazeno buï "URL pro odkaz" (je-li zaškrtnut "odkaz ven") nebo odkazem na text pøíspìvku)<div class=example><em>Pøíklad: </em>&lt;a href=_#HDLN_URL&gt;_#HEADLINE&lt;/a&gt;</div>');
define("L_HLP_POSTDATE",'alias pro datum zaøazení pøíspìvku');
define("L_HLP_PUB_DATE",'alias pro datum zveøejnìní');
define("L_HLP_EXP_DATE",'alias pro datum vypršení platnosti pøíspìvku');
define("L_HLP_ABSTRACT",'alias pro abstrakt<br>(pokud abstrabt není v databázi vyplnìn, pak se zobrazi <i>Grab_length</i> znakù z textu pøíspìvku)');
define("L_HLP_FULLTEXT",'alias pro text pøíspìvku<br>(text mùže být HTML formátovaný èi nikoliv - vše se øídí zaškrtnutím políèka HTML kód)');
define("L_HLP_IMAGESRC",'alias pro URL obrázku<br>(pokud není odkaz na obrázek v databázi, použije se standardní obrázek (viz konstanta NO_PICTURE_URL v souboru cz_*_lang.php3))<div class=example><em>Pøíklad: </em>&lt;img src="_#IMAGESRC"&gt;</div>');
define("L_HLP_SOURCE",'alias pro zdroj<br>(viz také _#LINK_SRC)');
define("L_HLP_SRC_URL",'alias pro URL zdroje<br>(pokud URL zdroje neni zadano, pouzile se standardni URL (viz konstanta NO_SOURCE_URL v souboru cz_*_lang.php3))<br>Pouzijte _#LINK_SRC pro odkaz na zdroj vèetnì jména zdroje.<div class=example><em>Pøíklad: </em>&lt;a href"_#SRC_URL#"&gt;&lt;img src="source.gif"&gt;&lt;/a&gt;</div>');
define("L_HLP_LINK_SRC",'alias pro zdroj vèetnì odkazu.<br>(pokud URL zdroje je vyplneno, alias je nahrazen &lt;a href="_#SRC_URL#"&gt;_#SOURCE##&lt;/a&gt;, jinak se použije jen _#SOURCE##)');
define("L_HLP_PLACE",'alias pro místo');
define("L_HLP_POSTEDBY",'alias pro autora');
define("L_HLP_E_POSTED",'alias pro e-mail autora');
define("L_HLP_CREATED",'alias pro datum zaøazení');
define("L_HLP_EDITEDBY",'alias pro cloveka, ktery tento pøíspìvek naposledy editoval');
define("L_HLP_LASTEDIT",'alias pro datum poslední editace');
define("L_HLP_EDITNOTE","alias pro poznámku editora");
define("L_HLP_IMGWIDTH",'alias pro šíøku obrázku<br>(pokud není šíøka zadaná, program se pokusí odstranit <em>width=</em> atribut z formátovacího øetìzce<div class=example><em>Pøíklad: </em>&lt;img src="_#IMAGESRC" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>');
define("L_HLP_IMG_HGHT",'alias pro výšku obrázku<br>(pokud není výška zadaná, program se pokusí odstranit <em>height=</em> atribut z formátovacího øetìzce<div class=example><em>Pøíklad: </em>&lt;img src="_#IMAGESRC" width=_#IMGWIDTH height=_#IMG_HGHT&gt;</div>');
define("L_HLP_ITEM_ID",'alias pro èíslo pøíspìvku<br>(lze použít jako parametr sh_itm= pødávaný skriptu slice.php3 (nebo souboru .shtml, který tento do sebe vkládá (include)))');

define("L_GRAB_LEN", "Poèet znakù textu použitých jako abstrakt");
define("L_D_EXPIRY_LIMIT", "Limit vypršení [dny]");
define("L_D_LISTLEN", "Poèet vypisovaných zpráv");
define("L_MSG_PAGE", "Zpráva aplikace");   // title of message page
define("L_EDITOR_TITLE", "Správa zpráv");
define("L_FULLTEXT_FORMAT", "HTML kód textu zprávy");
define("L_A_FULLTEXT_TIT", "Správa webíku - Vzhled jedné zprávy");
define("L_FULLTEXT_HDR", "HTML kód pro zobrazení zprávy");
define("L_A_COMPACT_TIT", "Správa webíku - Vzhled pøehledu zpráv");
define("L_COMPACT_HDR", "HTML kód pro pøehled zpráv");
define("L_A_FILTERS_TIT", "Správa webíku - Filtry pro výmìnu zpráv");
define("L_FLT_SETTING", "Nastavení filtrù pro pøíjem zpráv");
define("L_FLT_FROM_SL", "Filtr pro pøíjem zpráv z webíku");
define("L_FLT_APPROVED", "Jako aktuální zprávu");
define("L_ITEM_HDR", "Vstupní formuláø zprávy");
define("L_A_ITEM_ADD", "Pøidat zprávu");
define("L_A_ITEM_EDT", "Upravit zprávu");
define("L_IMP_EXPORT", "Povolit zasílání zpráv do webíku:");
define("L_EXPORT_TO_ALL", "Povol zasílání zpráv do jakéhokoliv webíku");
define("L_IMP_IMPORT", "Pøijímat zprávu z webíku:");
define("L_ADD_NEW_ITEM", "Nová zpráva");
define("L_ERR_FEEDED_ITEMS", "V koši je zpráva, která byla zaslána jinému webíku - nemùže být odstranìna.");
define("L_EDIT_ITEMS", "Editace zprávy");
define("L_VIEW_FULLTEXT", "Zobraz zprávu");
define("L_FULLTEXT", "Text zprávy");
define("L_FEEDING", "Výmìna zpráv");
define("L_HIGHLIGHTED", "Dùležitá zpráva");
define("L_NO_HIGHLIGHTED", "Obyèejná zpráva");
define("L_A_SLICE_IMP", "Správa webíku - Výmìna zpráv");
define("L_NO_PS_EDIT_ITEMS", "Nemáte právo upravovat zprávy v tomto webíku");
define("L_NO_DELETE_ITEMS", "Nemáte právo mazat zprávy");
define("L_NO_PS_MOVE_ITEMS", "Nemáte právo pøesouvat zprávy");
define("L_NO_PS_COPMPACT", "Nemáte právo upravovat vzhled pøehledu zpráv");
define("L_NO_PS_FULLTEXT", "Nemáte právo upravovat vzhled textu zprávy");
define("L_NO_PS_FEEDING", "Nemáte právo mìnit nastavení výmìny zpráv");
define("L_COMPACT_OK", "Vzhled pøehledu zpráv byl úspìšnì zmìnìn");
define("L_IMPORT_OK", "Nastavení výmìny zpráv bylo úspìšnì zmìnìno");
define("L_FULLTEXT_OK", "Vzhled textu zprávy byl úspìšnì zmìnìn");




# toolkit aplication independent labels (should not be true)

define("L_ACTIVE_BIN", "Aktuální");
define("L_HOLDING_BIN", "Zásobník");
define("L_TRASH_BIN", "Koš");

define("L_SHORT_NAME", "Zkrácené jméno");
define("L_DELETED", "Vymazán");
define("L_SLICE_DEFAULTS", "Pøednastavené hodnoty položek");

define("L_ERR_CANT_CHANGE", "Nepodaøilo se zmìnit nastavení webíku");
define("L_KONSTANTS_HLP", "Použijte následující aliasy namísto položek databáze");

define("L_ODD_ROW_FORMAT", "Liché øádky");
define("L_EVEN_ROW_FORMAT", "Sudé øádky");
define("L_EVEN_ODD_DIFFER", "Použij odlišný HTML kód pro sudé øadky");
define("L_CATEGORY_FORMAT", "Nadpis kategorie");
define("L_CATEGORY_SORT", "Seøaï zprávy v pøehledu podle kategorie");
define("L_COMPACT_TOP", "Horní HTML kód");
define("L_COMPACT_BOTTOM", "Spodní HTML kód");
define("L_A_COMPACT", L_A_COMPACT_TIT);

define("L_FLT_FROM", "Z");
define("L_FLT_TO", "Do");
define("L_FLT_CATEGORIES", "Kategorie");
define("L_ALL_CATEGORIES", "Všechny kategorie");
define("L_THE_SAME", "-- stejná --");

define("L_IMP_EXPORT_Y", "Zasílání povoleno");
define("L_IMP_EXPORT_N", "Zasílání zakázáno");
define("L_IMP_IMPORT_Y", "Pøijímat");
define("L_IMP_IMPORT_N", "Nepøijímat");

//define("", "");

define("L_RELOGIN", "Pøihlásit se jako jiný uživatel");

define("L_ERR_IN", "Chyba v");
define("L_ERR_NEED", "musí být vyplnìno");
define("L_ERR_LOG", "použijte znaky a-z, A-Z a 0-9");
define("L_ERR_LOGLEN", "musí být dlouhé 5 - 32 znakù");
define("L_ERR_NO_SRCHFLDS", "Nebylo zadáno prohledávané pole!");
define("L_NO_PRMS_SLICE", "Nemáte právo na zmìnu nastavení webíku");

define("L_EDIT", "Editace");
define("L_EDIT_SLICE", "Editace webíku");
define("L_DELETE", "Vymazat");
define("L_UPDATE", "Zmìnit");
define("L_RESET", "Vymazat formuláø");
define("L_CANCEL", "Zrušit");
define("L_ACTION", "Akce");
define("L_INSERT", "Vložit");
define("L_VIEW", "Ukázat");
define("L_NEW", "Nový");
define("L_GO", "Jdi");
define("L_ADD", "Pøidat");
define("L_USERS", "Uživatelé");
define("L_GROUPS", "Skupiny");
define("L_ORGANIZATION", "Organizace");
define("L_SEARCH", "Hledání");
define("L_RENAME", "Pøejmenování");
define("L_DEFAULTS", "Default");
define("L_SLICE", "Webík");
define("L_DELETE_TRASH", "Vysypat koš");
define("L_DELETED_SLICE", "Nebyl nalezen žádný webík, ke kterému máte pøístup");
define("L_SLICE_URL", "URL webíku");
define("L_CURRENT_USERS", "Souèasní uživatelé");
define("L_A_NEWUSER", "Nový uživatel v systému");
define("L_NEWUSER_HDR", "Nový uživatel");
define("L_USER_LOGIN", "Uživatelské jméno");
define("L_USER_PASSWORD1", "Heslo");
define("L_USER_PASSWORD2", "Potvrdit helso");
define("L_USER_FIRSTNAME", "Jméno");
define("L_USER_SURNAME", "Pøíjmení");
define("L_USER_MAIL", "E-mail");
define("L_A_USERS_TIT", "Správa webíku - Správa uživatelù");

define("L_ROLE_AUTHOR", "Autor");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administrátor");
define("L_ROLE_SUPER", "Super");

define("L_SLICE_ADM", "Správa webíku");
define("L_MAIN_SET", "Hlavní nastavení");
define("L_SLICE_SET", L_SLICE);
define("L_PERMISSIONS", "Nastavení práv");
define("L_PERM_CHANGE", "Zmìna souèasných práv");
define("L_PERM_ASSIGN", "Nová osoba/skupina");
define("L_PERM_NEW", "Hledej uživatele nebo skupinu");
define("L_PERM_SEARCH", "Pøiøazení nových práv");
define("L_PERM_CURRENT", "Zmìna souèasných práv");
define("L_USER_NEW", "Nový uživatel");
define("L_DESIGN", "Vzhled");
define("L_COMPACT", "Pøehled zpráv");
define("L_COMPACT_REMOVE", "Odstraòované øetìzce");
define("L_IMPORT", "Zasílání & Pøíjem");
define("L_FILTERS", "Filtry");

define("L_A_SLICE_ADD", "Správa webíku - Pøidání webíku");
define("L_A_SLICE_EDT", "Správa webíku - Úprava webíku");
define("L_A_SLICE_CAT", "Správa webíku - Nastavení kategorií");
define("L_A_SLICE_USERS", "Správa webíku - Uživatelé");
define("L_A_FIELDS_EDT", "Správa webíku - Nastavení položek");
define("L_FIELD", "Položka");
define("L_FIELD_IN_EDIT", "Zobrazit");
define("L_NEEDED_FIELD", "Povinná");
define("L_FIELDS_HDR", "Položky zprávy");
define("L_A_SEARCH_TIT", "Správa webíku - Vyhledávací formuláø");

define("L_SEARCH_HDR", "Vyhledávací kritéria");
define("L_SEARCH_HDR2", "Vyhledávat v položkách");
define("L_SEARCH_SHOW", "Zobrazit");
define("L_SEARCH_DEFAULT", "Standardni nastavení");
define("L_SEARCH_SET", "Vyhledávací formuláø");

define("L_NO_PRMS_SLICE", "Nemáte právo pøidat/upavovat nastavení webíku");
define("L_NO_PS_EDIT", "Nemáte právo upravovat tento webík");
define("L_NO_PS_ADD", "Nemáte právo pøidávat webík");
define("L_NO_PS_CATEGORY", "Nemáte právo mìnit nastavení kategorií");
define("L_NO_PS_USERS", "Nemáte právo ke správì uživatelù");
define("L_NO_PS_FIELDS", "Nemáte právo mìnit nastavení položek");
define("L_NO_PS_SEARCH", "Nemáte právo mìnit nastavení vyhledávání");
define("L_PS_NO_NEW_USER", "Nemáte právo vytváøet nové uživatele");

define("L_BAD_RETYPED_PWD", "Vyplnìná hesla si neodpovídají");
define("L_ERR_USER_ADD", "Nepodaøilo se pøidat uživatele do systému - chyba LDAP");
define("L_NEWUSER_OK", "Uživatel byl úspìšnì pøidán do systému");
define("L_CATBINDS_OK", "Nastavení kategorií bylo úspìšnì zmìnìno");
define("L_FIELDS_OK", "Nasavení položek úspìšnì zmìnìno");
define("L_SEARCH_OK", "Nastavení vyhledávacího formuláøe úspìšnì zmìnìno");

define("L_NEEDED", "Musí být vyplnìno");

define("L_ALL", " - vše - ");
define("L_CAT_LIST", "Kategorie webíku");
define("L_CAT_SELECT", "Kategorie tohoto webíku");
define("L_NEW_CATEG", "Název nové kategorie");
define("L_NEW_SLICE", "Pøidat webík");
define("L_SLICE_NEW", "Nový webík");
define("L_RENAME_CATEG", "Nový název kategorie");
define("L_ASSIGN", "Pøiøadit");
define("L_ADMINPAGE", "Zpìt na hlavní nastavení");
define("L_NO_CATEGORY", "Nebyla definována kategorie");
define("L_NO_IMPORTED_SLICE", "Nebyl nastaven žádný webík pro pøíjem zpráv");
define("L_NO_USERS", "Nebyl nalezen žádný uživatel (skupina)");
define("L_AND", "A");
define("L_OR", "Nebo");
define("L_SRCH_KW", "Vyhledat");
define("L_SRCH_FROM", "Od");
define("L_SRCH_TO", "Do");
define("L_SRCH_SUBMIT", "Hledej");

define("L_TOO_MUCH_USERS", "Nalezeno pøíliš mnoho uživatelù èi skupin.");
define("L_MORE_SPECIFIC", "Zkuste zadat pøesnìjší údaje.");
define("L_REMOVE", "Odstranit");
define("L_ID", "Id");
define("L_TYPE", "Typ");
define("L_SETTINGS", "Nastavení");
define("L_LOGO", "Econnect");
define("L_USER_MANAGEMENT", "Uživatelé");
define("L_ITEMS", "Správa pøíspìvkù");
define("L_NEW_SLICE_HEAD", "Nový webík");
define("L_ERR_USER_CHANGE", "Nelze zmìnit data uživatele - LDAP Error");
define("L_PUBLISHED", "Zveøejnìno");
define("L_EXPIRED", "Vypršeno");
define("L_NOT_PUBLISHED", "Dosud nepublikováno");
define("L_EDIT_USER", "Editace uživatele");
define("L_EDITUSER_HDR", L_EDIT_USER);
define("L_USER_ID", "Id uživatele");
define("NO_PICTURE_URL", "http://web.ecn.cz/aauser/images/no_pict.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)
define("NO_SOURCE_URL", "javascript: window.alert('Nebylo zadáno url zdroje')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('Nebylo zadáno url odkazu ven')");

# editor interface constants
define("L_PUBLISHED_HEAD", "PUB");
define("L_HIGHLIGHTED_HEAD", "!");
define("L_FEEDED_HEAD", "cizí");
define("L_FEEDED_INTO_APP", "Aktuální v");
define("L_FEEDED_INTO_HOLD", "V zásobníku");
define("L_FEEDED_INTO_TRASH", "V koši");
define("L_MORE_DETAILS", "Více podrobností");
define("L_LESS_DETAILS", "Ménì podrobností");
define("L_ACTION", "Akce");
define("L_MOVE_TO", "Pøesunout");
define("L_SELECT_ALL", "Vybrat vše");
define("L_UNSELECT_ALL", "Zrušit výbìr");
define("L_SELECT_VISIBLE", "Vybrat zobrazené");
define("L_UNSELECT_VISIBLE", "Zrušit výbìr");

define("L_D_LANGUAGE_CODE", L_LANGUAGE_CODE);
define("L_D_CP_CODE", L_CP_CODE);
define("L_D_CATEGORY_ID", L_CATEGORY);
define("L_CATEGORY_ID", L_CATEGORY);
define("L_D_STATUS_CODE", L_STATUS_CODE);
define("L_D_HIGHLIGHT", L_HIGHLIGHT);
define("L_D_EXPIRY_DATE", L_EXPIRY_DATE);
define("L_D_HL_HREF", L_HL_HREF);
define("L_D_SOURCE", L_SOURCE);
define("L_D_SOURCE_HREF", L_SOURCE_HREF);
define("L_D_PLACE", L_PLACE);
define("L_D_HTML_FORMATTED", L_HTML_FORMATTED);
define("L_D_IMG_SRC", L_IMG_SRC);
define("L_D_IMG_WIDTH", L_IMG_WIDTH);
define("L_D_IMG_HEIGHT", L_IMG_HEIGHT);
define("L_D_POSTED_BY", L_POSTED_BY);
define("L_D_E_POSTED_BY", L_E_POSTED_BY);
define("L_D_LINK_ONLY", L_LINK_ONLY);
define("L_A_FULLTEXT", L_A_FULLTEXT_TIT);
define("L_A_FILTERS_FLT", L_A_FILTERS_TIT);
define("L_FULLTEXT_REMOVE", L_COMPACT_REMOVE);
define("L_A_FIELDS_TIT", L_A_FIELDS_EDT);
define("L_A_SLICE_TIT", L_SLICE_ADM);
define("L_A_SLICE_TIT", L_SLICE_ADM);
define("L_A_SEARCH_EDT", L_A_SEARCH_TIT);
define("L_SLICES_HDR", L_SLICE);
define("L_SRCH_ALL", L_ALL);
define("L_SRCH_SLICE", L_SLICE);
define("L_SRCH_CATEGORY", L_CATEGORY);
define("L_SRCH_AUTHOR", L_CREATED_BY);
define("L_SRCH_LANGUAGE", L_LANGUAGE_CODE);
define("L_SRCH_HEADLINE", L_HEADLINE);
define("L_SRCH_ABSTRACT", L_ABSTRACT);
define("L_SRCH_FULL_TEXT", L_FULL_TEXT);
define("L_SRCH_EDIT_NOTE", L_EDIT_NOTE);

//new_constants
define("L_FEEDED_FROM", "Pøebráno z");
define("DEFAULT_SLICE_CONFIG", "<wddxPacket version='0.9'><header/><data><struct><var name='admin_fields'><struct><var name='chbox'><struct><var name='width'><number>24</number></var></struct></var><var name='post_date'><struct><var name='width'><number>70</number></var></struct></var><var name='headline'><struct><var name='width'><number>224</number></var></struct></var><var name='catname'><struct><var name='width'><number>70</number></var></struct></var><var name='published'><struct><var name='width'><number>24</number></var></struct></var><var name='highlight'><struct><var name='width'><number>24</number></var></struct></var><var name='feed'><struct><var name='width'><number>24</number></var></struct></var></struct></var></struct></data></wddxPacket>");
define("L_FEED", "Výmìna zpráv");
define("L_FEEDTO_TITLE", "Pøedat zprávu do webíku");
define("L_FEED_TO", "Pøedat vybrné zprávy do zvolených webíku");
define("L_NO_PERMISSION_TO_FEED", "Nelze");
define("L_NO_PS_CONFIG", "Nemáte právo nastavovat configuraèní parametry tohoto webíku");
define("L_A_SLICE_CFG", "Správa webíku - Konfigurace rozhranní");
define("L_VISIBLE_ADMIN_FIELDS", "Zobrazené sloupce v administativním rozhranní");
define("L_FIELD_WIDTH", "Šíøka sloupce");
define("L_VISIBLE", "Zobrazené");
define("L_HIDDEN", "Skryté");
define("L_SLICE_CONFIG", "Parametry");
define("L_CHBOX_HEAD", "&nbsp;");   // title of checkbox in admin interface
define("L_CHBOX", "Checkbox"); 
define("L_CATNAME", "Kategorie");
define("L_CATEGORY_ID", "ID kategorie");
define("L_UP", "Nahoru");
define("L_DOWN", "Dolù");
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

define("L_NO_PS_NEW_USER", "Nemáte právo vytvoøit uživatele");
define("L_ALL_GROUPS", "Všechny skupiny");
define("L_USERS_GROUPS", "Uživatelovy skupiny");
define("L_REALY_DELETE_USER", "Opravdu chcete vymazat daného uživatele ze systému?");
define("L_REALY_DELETE_GROUP", "Opravdu chcete vymazat danou skupinu ze systému?");
define("L_ITEM_NOT_CHANGED", "Zpráva nezmìnìna");
define("L_CANT_ADD_ITEM", "Nelze pøidat zprávu");
define("L_TOO_MUCH_GROUPS", "Nalezeno pøíliš mnoho skupin.");
define("L_NO_GROUPS", "Skupina nenalezena");
define("L_GROUP_NAME", "Jméno");
define("L_GROUP_DESCRIPTION", "Popis");
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

define("L_FEED", "Zaslat");
define("L_FEEDTO_TITLE", "Pøedávání zpráv");
define("L_FEED_TO", "Zaslat vybrané zprávy vybraným webíkùm");
define("L_NO_PERMISSION_TO_FEED", "Nemáte právo zasílat zprávy");

define("L_ACTIVE_BIN_EXPIRED", "Aktuální - Expirované");
define("L_ACTIVE_BIN_PENDING", "Aktuální - Pøipravené");
define("L_ACTIVE_BIN_EXPIRED_MENU", "... expirované");
define("L_ACTIVE_BIN_PENDING_MENU", "... pøipravené");

//----------------------------
//define("", "");   //prepared for new constants
 
$l_month = array( 1 => 'Leden', 'Únor', 'Bøezen', 'Duben', 'Kvìten', 'Èerven', 
		'Èervenec', 'Srpen', 'Záøí', 'Øíjen', 'Listopad', 'Prosinec');

/*
$Log$
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
