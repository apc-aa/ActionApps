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
define("CONFIG_FILE", "sk_news_lang.php3");

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
  <LINK rel=StyleSheet href="../'. ADMIN_CSS .'" 
        type="text/css" title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
               
# aa toolkit specific labels
define("L_ITEM_ID_ALIAS",'alias pre ËÌslo Ël·nku');
define("L_EDITITEM_ALIAS",'alias pouûÌvan˝ v administratÌvnych str·nk·ch index.php3 pre URL itemedit.php3');
define("L_LANG_FILE","Pouûit˝ language s˙bor");
define("L_PARAMETERS","Parametre");
define("L_SELECT_APP","V˝ber aplik·ciÌ");
define("L_SELECT_OWNER","V˝ber vlastnÌka");
     
# toolkit aplication dependent labels
define("L_CANT_UPLOAD", "S˙bor (obr·zok) sa ned· uloûiù");
define("L_MSG_PAGE", "Nastavenia");   // title of message page
define("L_EDITOR_TITLE", "Spr·vy");
define("L_FULLTEXT_FORMAT_TOP", "Horn˝ HTML kÛd");
define("L_FULLTEXT_FORMAT", "HTML kÛd textu spr·vy");
define("L_FULLTEXT_FORMAT_BOTTOM", "Spodn˝ HTML kÛd");
define("L_A_FULLTEXT_TIT", "Spr·va modulu - Vzhæad jednej spr·vy");
define("L_FULLTEXT_HDR", "HTML kÛd pre zobrazenie spr·vy");
define("L_COMPACT_HDR", "HTML kÛd pre prehæad spr·v");
define("L_ITEM_HDR", "Vstupn˝ formul·r spr·vy");
define("L_A_ITEM_ADD", "Pridaù spr·vu");
define("L_A_ITEM_EDT", "Upraviù spr·vu");
define("L_IMP_EXPORT", "Povoliù zasielanie spr·v do modulu:");
define("L_ADD_NEW_ITEM", "Nov· spr·va");
define("L_DELETE_TRASH", "Vysypaù kÙö");
define("L_VIEW_FULLTEXT", "Zobraziù spr·vu");
define("L_FULLTEXT", "Cel· spr·va");
define("L_HIGHLIGHTED", "DÙleûit· spr·va");
define("L_A_FIELDS_EDT", "Nastavenia modulu - Nastavenia polÌ");
define("L_FIELDS_HDR", "Pole spr·v");
define("L_NO_PS_EDIT_ITEMS", "Nem·te pr·vo upravovaù spr·vy v tomto module");
define("L_NO_DELETE_ITEMS", "Nem·te pr·vo mazaù spr·vy");
define("L_NO_PS_MOVE_ITEMS", "Nem·te pr·vo pres˙vaù spr·vy");
define("L_NO_PS_COPMPACT", "Nem·te pr·vo upravovaù vzhæad prehæadu spr·v");
define("L_FULLTEXT_OK", "Vzhæad textu spr·vy bol ˙speöne zmenen˝");
define("L_NO_ITEM", "éiadna spr·va nevyhovuje v·ömu zadaniu.");



# aa toolkit common labels
# can be the same for all toolkit aplications
define("L_ACTIVE_BIN", "Aktu·lne");
define("L_HOLDING_BIN", "Z·sobnÌk");
define("L_TRASH_BIN", "KÙö");

define("L_CATEGORY","KategÛrie");
define("L_SLICE_NAME", "Meno");
define("L_DELETED", "Zmazan˝");
define("L_D_LISTLEN", "PoËet vypisovan˝ch spr·v");  // slice
define("L_ERR_CANT_CHANGE", "Nepodarilo sa zmeniù nastavenie modulu");
define("L_ODD_ROW_FORMAT", "Nep·rny z·znam");
define("L_EVEN_ROW_FORMAT", "P·rny z·znam");
define("L_EVEN_ODD_DIFFER", "Odliön˝ HTML kÛd pre p·rne z·znamy");
define("L_CATEGORY_TOP", "Horn˝ HTML kÛd pre kategÛriu");
define("L_CATEGORY_FORMAT", "Nadpis kategÛrie");
define("L_CATEGORY_BOTTOM", "Spodn˝ HTML kÛd pre kategÛriu");
define("L_CATEGORY_SORT", "Zoradiù spr·vy v prehæade podæa kategÛrie");
define("L_COMPACT_TOP", "Horn˝ HTML kÛd");
define("L_COMPACT_BOTTOM", "Spodn˝ HTML kÛd");
define("L_A_COMPACT_TIT", "Nastavenia modulu - Vzhæad prehæadu spr·v");
define("L_A_FILTERS_TIT", "Nastavenia modulu - Filtre pre v˝menu spr·v");
define("L_FLT_SETTING", "Nastavenie filtrov pre prÌjem spr·v");
define("L_FLT_FROM_SL", "Filter pre prÌjem spr·v z modulu");
define("L_FLT_FROM", "Z");
define("L_FLT_TO", "Do");
define("L_FLT_APPROVED", "Ako aktu·lnu spr·vu");
define("L_FLT_CATEGORIES", "KategÛrie");
define("L_ALL_CATEGORIES", "Vöetky kategÛrie");
define("L_FLT_NONE", "Nie je vybran· ûiadna vstupn· kategÛria!");
define("L_THE_SAME", "-- rovnak· --");
define("L_EXPORT_TO_ALL", "Povoliù exportovaù spr·vy do vöetk˝ch modulov");

define("L_IMP_EXPORT_Y", "Zasielanie povolenÈ");
define("L_IMP_EXPORT_N", "Zasielanie zak·zanÈ");
define("L_IMP_IMPORT", "PrÌjmaù spr·vy z:");
define("L_IMP_IMPORT_Y", "PrÌjmaù");
define("L_IMP_IMPORT_N", "NeprÌjmaù");
define("L_CONSTANTS_HLP", "Pouûi n·sleduj˙ce aliasy datab·zov˝ch polÌ");

define("L_ERR_IN", "Chyba v");
define("L_ERR_NEED", "musÌ b˝t vyplnenÈ");
define("L_ERR_LOG", "pouûite znaky a-z, A-Z a 0-9");
define("L_ERR_LOGLEN", "musÌ byù dlhÈ 5 - 32 znakov");
define("L_ERR_NO_SRCHFLDS", "Nebolo zadanÈ prehæad·vanÈ pole!");

define("L_FIELDS", "Polia");
define("L_EDIT", "Edit·cia");
define("L_DELETE", "Vymazaù");
define("L_REVOKE", "Odstr·niù");
define("L_UPDATE", "Zmeniù");
define("L_RESET", "Vymazaù formul·r");
define("L_CANCEL", "Zruöiù");
define("L_ACTION", "Akcia");
define("L_INSERT", "Vloûiù");
define("L_NEW", "Nov˝");
define("L_GO", "ChoÔ");
define("L_ADD", "Pridaù");
define("L_USERS", "Uûivatelia");
define("L_GROUPS", "Skupiny");
define("L_SEARCH", "Hæadanie");
define("L_DEFAULTS", "Default");
define("L_SLICE", "Modul");
define("L_DELETED_SLICE", "Nebol n·jden˝ ûiaden modul, ku ktorÈmu m·te prÌstup");
define("L_SLICE_URL", "URL modulu");
define("L_A_NEWUSER", "Nov˝ uûivateæ v systÈme");
define("L_NEWUSER_HDR", "Nov˝ uûivateæ");
define("L_USER_LOGIN", "UûivateæskÈ meno");
define("L_USER_PASSWORD1", "Heslo");
define("L_USER_PASSWORD2", "Potvrdiù heslo");
define("L_USER_FIRSTNAME", "MÈno");
define("L_USER_SURNAME", "Priezvisko");
define("L_USER_MAIL", "E-mail");
define("L_USER_SUPER", "AdministratÌvny ˙Ëet");
define("L_A_USERS_TIT", "Nastavenia modulu - Uûivatelia");
define("L_A_PERMISSIONS", "Nastavenia modulu - PrÌstupovÈ pr·va");
define("L_A_ADMIN", "Nastavenia modulu - Vzhæad Administr·cie");
define("L_A_ADMIN_TIT", L_A_ADMIN);
define("L_ADMIN_FORMAT", "HTML kÛd pre zobrazenie spr·vy");
define("L_ADMIN_FORMAT_BOTTOM", "Spodn˝ HTML");
define("L_ADMIN_FORMAT_TOP", "Horn˝ HTML");
define("L_ADMIN_HDR", "V˝pis spr·v v administratÌvnych str·nk·ch");
define("L_ADMIN_OK", "Vzhæad administratÌvnych str·nok ˙speöne zmenen˝");
define("L_ADMIN_REMOVE", "OdstraÚovanÈ reùazce");

define("L_ROLE_AUTHOR", "Autor");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administr·tor");
define("L_ROLE_SUPER", "Super");

define("L_MAIN_SET", "HlavnÈ nastavenia");
define("L_PERMISSIONS", "Nastavenia pr·v");
define("L_PERM_CHANGE", "Zmena s˙Ëasn˝ch pr·v");
define("L_PERM_ASSIGN", "Pridelenie nov˝ch pr·v");
define("L_PERM_NEW", "Hæadej uûivateæa alebo skupinu");
define("L_PERM_SEARCH", "Priradenie nov˝ch pr·v");
define("L_PERM_CURRENT", "Zmena s˙Ëasn˝ch pr·v");
define("L_USER_NEW", "Nov˝ uûivateæ");
define("L_DESIGN", "Vzhæad");
define("L_COMPACT", "Prehæad spr·v");
define("L_COMPACT_REMOVE", "OdstraÚovanÈ reùazce");
define("L_FEEDING", "V˝mena spr·v");
define("L_IMPORT", "Zasielanie & PrÌjem");
define("L_FILTERS", "Filtre");

define("L_A_SLICE_ADD", "Spr·va modulu - Prid·nie modulu");
define("L_A_SLICE_EDT", "Spr·va modulu - ⁄prava modulu");
define("L_A_SLICE_CAT", "Spr·va modulu - Nastavenie kategÛriÌ");
define("L_A_SLICE_IMP", "Spr·va modulu - V˝mena spr·v");
define("L_FIELD", "Poloûka");
define("L_FIELD_IN_EDIT", "Zobraziù");
define("L_NEEDED_FIELD", "Povinn·");
define("L_A_SEARCH_TIT", "Spr·va modulu - Vyhæad·vacÌ formul·r");
define("L_SEARCH_HDR", "Vyhæad·vacie kritÈri·");
define("L_SEARCH_HDR2", "Vyhæad·vaù v poloûk·ch");
define("L_SEARCH_SHOW", "Zobraziù");
define("L_SEARCH_DEFAULT", "ätandardnÈ nastavenia");
define("L_SEARCH_SET", "Vyhæad·vacÌ formul·r");
define("L_AND", "AND");
define("L_OR", "OR");
define("L_SRCH_KW", "Search for");
define("L_SRCH_FROM", "From");
define("L_SRCH_TO", "To");
define("L_SRCH_SUBMIT", "Search");
define("L_NO_PS_EDIT", "Nem·te pr·vo upravovaù tento modul");
define("L_NO_PS_ADD", "Nem·te pr·vo prid·vaù modul");
define("L_NO_PS_COPMPACT", "Nem·te pr·vo meniù vzhæad prehæadu spr·v");
define("L_NO_PS_FULLTEXT", "Nem·te pr·vo meniù vzhæad v˝pisu spr·vy");
define("L_NO_PS_CATEGORY", "Nem·te pr·vo meniù nastavenia kategÛriÌ");
define("L_NO_PS_FEEDING", "Nem·te pr·vo meniù nastavenia v˝meny spr·v");
define("L_NO_PS_USERS", "Nem·te pr·vo ku spr·ve uûivateæov");
define("L_NO_PS_FIELDS", "Nem·te pr·vo meniù nastavenia poloûiek");
define("L_NO_PS_SEARCH", "Nem·te pr·vo meniù nastavenia vyhæad·v·nia");

define("L_BAD_RETYPED_PWD", "VyplnenÈ hesl· nie s˙ rovnakÈ");
define("L_ERR_USER_ADD", "Nepodarilo se pridat uûivateæa do systÈmu - chyba LDAP");
define("L_NEWUSER_OK", "Uûivateæ bol ˙speöne pridan˝ do systÈmu");
define("L_COMPACT_OK", "Vzhæad prehæadu spr·v bol ˙speöne zmenen˝");
define("L_BAD_ITEM_ID", "ZlÈ ËÌslo spr·vy");
define("L_ALL", " - vöetko - ");
define("L_CAT_LIST", "KategÛrie spr·v");
define("L_CAT_SELECT", "KategÛrie v tomto module");
define("L_NEW_SLICE", "Nov˝ modul");
define("L_ASSIGN", "Priradiù");
define("L_CATBINDS_OK", "Nastavenia kategÛriÌ boli ˙speöne zmenenÈ");
define("L_IMPORT_OK", "Nastavenia v˝meny spr·v ˙speöne zmenenÈ");
define("L_FIELDS_OK", "Nastavenia poloûiek ˙speöne zmenenÈ");
define("L_SEARCH_OK", "NastavenÌa vyhæad·vacieho formul·ra ˙speöne zmenenÈ");
define("L_NO_CATEGORY", "KategÛrie neboli definovanÈ");
define("L_NO_IMPORTED_SLICE", "Nie je nastaven˝ ûiaden modul, z ktorÈho sa maj˙ prÌjmaù spr·vy");
define("L_NO_USERS", "Uûivateæ (skupina) nen·jden·");

define("L_TOO_MUCH_USERS", "N·jden˝ch prÌliö veæa uûivateæov alebo skupÌn.");
define("L_MORE_SPECIFIC", "Sk˙ste zadaù presnejöie ˙daje.");
define("L_REMOVE", "Odstr·niù");
define("L_ID", "Id");
define("L_TYPE", "Typ");
define("L_SETTINGS", "Nastavenia");
define("L_LOGO", "APC toolkit");
define("L_USER_MANAGEMENT", "Uûivatelia");
define("L_ITEMS", "Spr·va prÌspevkov");
define("L_NEW_SLICE_HEAD", "Nov˝ modul");
define("L_ERR_USER_CHANGE", "Nie je moûnÈ zmeniù ˙daje uûivateæa - LDAP Error");
define("L_PUBLISHED", "ZverejnenÈ");
define("L_EXPIRED", "ExpirovanÈ");
define("L_NOT_PUBLISHED", "Zatiaæ nepublikovanÈ");
define("L_EDIT_USER", "Edit·cia uûivateæa");
define("L_EDITUSER_HDR", L_EDIT_USER);

define("NO_SOURCE_URL", "javascript: window.alert('Nebolo zadanÈ url zdroja')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('Nebolo zadanÈ url externÈho odkazu')");

# editor interface constants
define("L_PUBLISHED_HEAD", "PUB");
define("L_HIGHLIGHTED_HEAD", "!");
define("L_FEEDED_HEAD", "cudzie");
define("L_MORE_DETAILS", "Viac podrobnostÌ");
define("L_LESS_DETAILS", "Menej podrobnostÌ");
define("L_UNSELECT_ALL", "Zruöiù v˝ber");
define("L_SELECT_VISIBLE", "Vybraù zobrazenÈ");
define("L_UNSELECT_VISIBLE", "Zruöiù v˝ber");

define("L_SLICE_ADM","Administr·cia modulu - Menu");
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

define("L_FEED", "V˝mena spr·v");
define("L_FEEDTO_TITLE", "Poskytn˙ù spr·vu do modulu");
define("L_FEED_TO", "Poskytn˙ù vybranÈ spr·vy do zvolen˝ch modulov");
define("L_NO_PERMISSION_TO_FEED", "Ned· sa");
define("L_NO_PS_CONFIG", "Nem·te pr·vo nastavovaù konfiguraËnÈ parametre tohoto modulu");
define("L_SLICE_CONFIG", "Administr·cia");
define("L_CHBOX_HEAD", "&nbsp;");   // title of checkbox in admin interface
define("L_CHBOX", "Checkbox"); 
define("L_CATNAME", "KategÛria");
define("L_CATEGORY_ID", "ID kategÛrie");
define("L_EDITED_BY","EditovanÈ");
define("L_MASTER_ID", "ID zdrojovÈho modulu");
define("L_CHANGE_MARKED", "Zmeniù vybranÈ");
define("L_MOVE_TO_ACTIVE_BIN", "Vystaviù");
define("L_MOVE_TO_HOLDING_BIN", "Poslaù do z·sobnÌku");
define("L_MOVE_TO_TRASH_BIN", "Poslaù do koöa");
define("L_OTHER_ARTICLES", "OstatnÈ spr·vy");
define("L_MISC", "PrÌkazy");
define("L_HEADLINE_EDIT", "Nadpis (edit·cia po kliknutÌ)");
define("L_HEADLINE_PREVIEW", "Nadpis (preview po kliknutÌ)");
define("L_EDIT_LINK", "&nbsp;");
define("L_PREVIEW_LINK", "&nbsp;");
define("L_ARTICLE_MANAGER", "Spr·va spr·v");
define("L_SWITCH_TO", "Modul:");
define("L_ADMIN", "Administr·cia");

define("L_NO_PS_NEW_USER", "Nem·te pr·vo vytvoriù uûivateæa");
define("L_ALL_GROUPS", "Vöetky skupiny");
define("L_USERS_GROUPS", "Uûivateæove skupiny");
define("L_REALY_DELETE_USER", "Naozaj chcete vymazaù danÈho uûivateæa zo systÈmu?");
define("L_REALY_DELETE_GROUP", "Naozaj chcete vymazaù dan˙ skupinu zo systÈmu?");
define("L_ITEM_NOT_CHANGED", "Spr·va nezmenen·");
define("L_NO_GROUPS", "Skupina nen·jden·");
define("L_GROUP_NAME", "MÈno");
define("L_GROUP_DESCRIPTION", "Popis");
define("L_GROUP_SUPER", "AdministratÌvna skupina");
define("L_ERR_GROUP_ADD", "Nie je moûnÈ pridaù skupinu do systÈmu");
define("L_NEWGROUP_OK", "Skupina bola ˙speöne pridan·");
define("L_ERR_GROUP_CHANGE", "Nie je moûnÈ zmenit skupinu");
define("L_A_UM_USERS_TIT", "Spr·va uûivateæov - Uûivatelia");
define("L_A_UM_GROUPS_TIT", "Spr·va uûivateæov - Skupiny");
define("L_EDITGROUP_HDR", "Edit·cai skupiny");
define("L_NEWGROUP_HDR", "Nov· skupina");
define("L_GROUP_ID", "ID skupiny");
define("L_ALL_USERS", "Vöetci uûivatelia");
define("L_GROUPS_USERS", "Uûivatelia v skupine");
define("L_POST", "Poslaù");
define("L_POST_PREV", "Poslaù a pozrieù");
define("L_OK", "OK");
define("L_ACTIVE_BIN_EXPIRED", "Aktu·lne - ExpirovanÈ");
define("L_ACTIVE_BIN_PENDING", "Aktu·lne - PripravenÈ");
define("L_ACTIVE_BIN_EXPIRED_MENU", "... expirovanÈ");
define("L_ACTIVE_BIN_PENDING_MENU", "... pripravenÈ");

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
define("L_DELETE_FIELD", "Naozaj chcete vymazaù toto pole z modulu?");
define("L_FEEDED", "PrebranÈ");
define("L_HTML_DEFAULT", "ötandardne pouûiù HTML kÛd");
define("L_HTML_SHOW", "Zobraziù voæbu 'HTML' / 'obyËajn˝ text'");
define("L_NEW_OWNER", "Nov˝ vlastnÌk");
define("L_NEW_OWNER_EMAIL", "E-mail novÈho vlastnÌka");
define("L_NO_FIELDS", "V tomto module nie s˙ definovanÈ ûiadne polia (Ëo je ËudnÈ)");
define("L_NO_FIELD", "");
define("L_NO_PERM_TO_FEED", "Nem·te pr·vo nastaviù v˝menu spr·v so ûiadnym modulom");
define("L_NO_SLICES", "éiaden modul");
define("L_NO_TEMPLATES", "éiadna öablÛna");
define("L_OWNER", "VlastnÌk");
define("L_SLICES", "Moduly");
define("L_TEMPLATE", "äablÛna");
define("L_VALIDATE", "Zkontrolovaù");

define("L_FIELD_DELETE_OK", "Pole odstr·nenÈ");

define("L_WARNING_NOT_CHANGE","<p>POZOR: Tieto nastavenia by mal meniù iba ten, kto vie Ëo robÌ!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Funkcia, ktor· sa pouûije pre zobrazenie poæa vo vstupnom formul·ri. Pre niektorÈ typy zobrazenia je moûnÈ pouûiù parametre, ktorÈ nasleduj˙.");
define("L_INPUT_SHOW_FUNC_C_HLP","Hodnoty, pouûitÈ v prÌpadÏ vstupn˝ch funkciÌ SELECT alebo RADIO.");
define("L_INPUT_SHOW_FUNC_HLP","Parameter pouûit˝ pre vstupnÈ funkcie TEXT (<poËet riadkov>) alebo DATE (<mÌnus roky>'<plus roky>'<od teraz?>).");
define("L_INPUT_DEFAULT_F_HLP","Funkcia, ktor· sa pouûije pre generovanie defaultn˝ch hodnÙt poæa:<BR>Now - aktu·lny d·tum<BR>User ID - identifik·tor prihl·senÈho uûivateæa<BR>Text - text uveden˝ v poli Parameter<br>Date - aktu·lny d·tum plus <Parametr> dnÌ");
define("L_INPUT_DEFAULT_HLP","Parameter pre defaulnÈ hodnoty Text a Date (viÔ vyööie)");

define("L_INPUT_DEFAULT_TXT", "Text");
define("L_INPUT_DEFAULT_DTE", "D·tum");
define("L_INPUT_DEFAULT_UID", "ID uûÌvateæa");
define("L_INPUT_DEFAULT_NOW", "Aktu·lny d·tum a Ëas*");

define("L_INPUT_SHOW_TXT","Text Area");
define("L_INPUT_SHOW_FLD","Text Field");
define("L_INPUT_SHOW_SEL","Select Box");
define("L_INPUT_SHOW_RIO","Radio Button");
define("L_INPUT_SHOW_DTE","D·tum");
define("L_INPUT_SHOW_CHB","Check Box");
define("L_INPUT_SHOW_MCH", "Viacero Checkboxov");
define("L_INPUT_SHOW_MSE", "Select Box - multi");
define("L_INPUT_SHOW_FIL","Upload s˙boru");
define("L_INPUT_SHOW_NUL","Nezobrazovaù");
                              
define("L_INPUT_VALIDATE_TEXT","Text");
define("L_INPUT_VALIDATE_URL","URL");
define("L_INPUT_VALIDATE_EMAIL","E-mail");
define("L_INPUT_VALIDATE_NUMBER","»Ìslo");
define("L_INPUT_VALIDATE_ID","Id");
define("L_INPUT_VALIDATE_DATE","D·tum");
define("L_INPUT_VALIDATE_BOOL","¡no/Nie");

define("L_INPUT_INSERT_QTE","Text");
define("L_INPUT_INSERT_DTE","D·tum");
define("L_INPUT_INSERT_CNS","Konötanta");
define("L_INPUT_INSERT_NUM","»Ìslo");
define("L_INPUT_INSERT_BOO","°Ano/Nie");
define("L_INPUT_INSERT_UID","ID uûÌvateæa");
define("L_INPUT_INSERT_NOW","Aktu·lny d·tum a Ëas");
define("L_INPUT_INSERT_FIL","S˙bor");
define("L_INPUT_INSERT_NUL","Pr·zdne");

define("L_INPUT_DEFAULT","Default");
define("L_INPUT_BEFORE","HTML kÛd pred t˝mto poæom");
define("L_INPUT_BEFORE_HLP","HTML kÛd, ktor˝ sa zobrazÌ vo vstupnom formul·ri pred t˝mto poæom");
define("L_INPUT_FUNC","Typ Vstupu");
define("L_INPUT_HELP","N·poveda");
define("L_INPUT_HELP_HLP","N·poveda zobrazen· pre toto pole vo vstupnom formul·ri");
define("L_INPUT_MOREHLP","Viac inform·ciÌ");
define("L_INPUT_MOREHLP_HLP","N·poveda, ktor· sa zobrazÌ po stlaËenÌ '?' vo vstupnom formul·ri");
define("L_INPUT_INSERT_HLP","SpÙsob uloûenia do datab·zy");
define("L_INPUT_VALIDATE_HLP","Funkcia pre kontrolu vstupu (validace)");

define("L_CONSTANT_NAME", "Meno");
define("L_CONSTANT_VALUE", "Hodnota");
define("L_CONSTANT_PRIORITY", "Priorita");
define("L_CONSTANT_PRI", "Priorita");
define("L_CONSTANT_GROUP", "Skupina hodnÙt");
define("L_CONSTANT_GROUP_EXIST", "T·to skupina hodnÙt uû existuje");
define("L_CONSTANTS_OK", "Zmena hodnÙt ˙speöne vykonan·");
define("L_A_CONSTANTS_TIT", "Spr·va modulu - Nastavenia hodnÙt");
define("L_A_CONSTANTS_EDT", "Spr·va modulu - Nastavenia hodnÙt");
define("L_CONSTANTS_HDR", "Hodnoty");
define("L_CONSTANT_NAME_HLP", "zobrazenÈ&nbsp;vo&nbsp;vstupnom&nbsp;formul·ri");
define("L_CONSTANT_VALUE_HLP", "uloûenÈ&nbsp;v&nbsp;datab·ze");
define("L_CONSTANT_PRI_HLP", "Poradie&nbsp;hodnÙt");
define("L_CONSTANT_CLASS", "NadkategÛrie");
define("L_CONSTANT_CLASS_HLP", "len&nbsp;pre&nbsp;kategÛrie");
define("L_CONSTANT_DEL_HLP", "Pre odstr·nenie kartegÛrie vymaûte jej meno");

$L_MONTH = array( 1 => 'Janu·r', 'Febru·r', 'Marec', 'AprÌln', 'M·j', 'J˙n', 
		'J˙l', 'August', 'September', 'OktÛber', 'November', 'December');

define("L_NO_CATEGORY_FIELD","Pole kategÛrie nie je v tomto module definovanÈ.<br>  Pridajte pole kategÛrie do modulu na str·nke Polia.");
define("L_PERMIT_ANONYMOUS_POST","AnonymnÈ vkladanie");
define("L_PERMIT_OFFLINE_FILL","Off-line plnenie");
define("L_SOME_CATEGORY", "<kategÛria>");

define( "L_BRACKETS_ERR", "Brackets doesn't match in query: ");
define("L_SLICE_ADM","Administr·cia modulu - Menu");
define("L_A_SLICE_TIT", L_SLICE_ADM);
define("L_A_SLICE_ADD_HELP", "Nov˝ modul mÙûete vytvoriù na z·klade öablÛny, alebo skopÌrovaù nastavenia z uû existuj˙ceho modulu (vytvorÌ sa presn· kÛpia vr·tane nastavenÌ.");

define("L_ALIAS_FUNC_A", "abstrakt");
define("L_ALIAS_FUNC_B", "fulltext odkaz");
define("L_ALIAS_FUNC_C", "podmienka");
define("L_ALIAS_FUNC_D", "d·tum");
define("L_ALIAS_FUNC_E", "edit·cia Ël·nku");
define("L_ALIAS_FUNC_F", "odkaz na pln˝ text");
define("L_ALIAS_FUNC_G", "v˝öka obr·zku");
define("L_ALIAS_FUNC_H", "zobraziù");
define("L_ALIAS_FUNC_I", "zdroj obr·zku");
define("L_ALIAS_FUNC_L", "pole s odkazom");
define("L_ALIAS_FUNC_N", "id");
define("L_ALIAS_FUNC_S", "url");
define("L_ALIAS_FUNC_T", "pln˝ text");
define("L_ALIAS_FUNC_W", "öÌ¯ka obr·zku");
define("L_ALIAS_FUNC_0", "- ûiadna -");

define("L_ALIASES", "Aliasy pre polia v datab·ze");
define("L_ALIAS1", "Alias 1"); 
define("L_ALIAS_HLP", "MusÌ zaËÌnaù znakmi \"_#\".<br>Alias musÌ byù presne 10 znakov dlh˝ vr·tane \"_#\".<br>Mal by byù kapit·lkami."); 
define("L_ALIAS_FUNC", "Funkcia"); 
define("L_ALIAS_FUNC_F_HLP", "Funkcia, ktor· zabezpeËÌ zobrazenie polia na str·nke"); 
define("L_ALIAS_FUNC_HLP", "Doplnkov˝ parameter odovzd·van˝ zobrazovacej funkcii. Podrobnosti viÔ include/item.php3 file"); 
define("L_ALIAS_HELP", "N·poveda"); 
define("L_ALIAS_HELP_HLP", "N·povedn˝ text pre tento alias"); 
define("L_ALIAS2", "Alias 2"); 
define("L_ALIAS3", "Alias 3"); 

define("L_TOP_HLP", "HTML kÛd, ktor˝ sa zobrazÌ pred kÛdom modulu");
define("L_FORMAT_HLP", "Sem patrÌ HTML kÛd v kombin·cii s aliasmi uveden˝mi dole na str·nke
                     <br>Aliasy bud˙ v momente zobrazovania na web nahradenÈ skutoËn˝mi hodnotami z datab·zy");
define("L_BOTTOM_HLP", "HTML kÛd, ktor˝ sa zobrazÌ za vlastn˝m kÛdom modulu");
define("L_EVEN_ROW_HLP", "TIP: RozlÌöenÌm p·rnych a nep·rnych z·znamov mÙûete dosiahn˙ù naprÌklad farebnÈ odlÌöenie riadkov");

define("L_REMOVE_HLP", "Odstr·ni pr·zdnÈ z·tvorky a pod. Pouûite ## ako oddelovaË.");

define("L_COMPACT_HELP", "Na tejto str·nke je moûnÈ nastaviù, Ëo sa objavÌ na str·nke prehæadu spr·v");
define("L_A_FULLTEXT_HELP", "Na tejto str·nke je moûnÈ nastaviù, Ëo sa objavÌ na str·nke pri prezeranÌ tela spr·vy");
define("L_PROHIBITED", "Zak·zanÈ");
define("L_HTML", "HTML");
define("L_PLAIN_TEXT", "ObyËajn˝ text");
define("L_A_DELSLICE", "Spr·va modulu - Vymazaù modulu");
define("L_DELSLICE_TIT", L_A_DELSLICE);
define("L_DELSLICE_HDR", "Vyber modul pre vymazanie");
define("L_DEL_SLICE_HLP","<p>Je moûnÈ vymazaù len moduly, ktorÈ boli oznaËenÈ pre vymazanie na str·nke &quot;<b>". L_SLICE_SET ."</b>&quot;</p>");
define("L_A_DELSLICE", L_DELSLICE_TIT);
define("L_DELETE_SLICE", "Naozaj chcete vymazaù tento modul a vöetky jeho spr·vy?");
define("L_NO_SLICE_TO_DELETE", "éiaden modul nebol oznaËen˝ za vymazan˝");
define("L_NO_SUCH_SLICE", "ZlÈ ËÌslo modulu");
define("L_NO_DELETED_SLICE", "Modul nie je oznaËen˝ za vymazan˝");
define("L_DELSLICE_OK", "Modul bol vymazan˝, tabuæky boly optimalizovanÈ");
define("L_DEL_SLICE", "Zmazaù modul");
define("L_FEED_STATE", "Zdieæanie tohto poæa");
define( "L_STATE_FEEDABLE", "KopÌrovaù obsah" );
define( "L_STATE_UNFEEDABLE", "NekopÌrovaù" );
define( "L_STATE_FEEDNOCHANGE", "KopÌrovaù nemenitelne" );
define( "L_INPUT_FEED_MODES_HLP", "M· sa kopÌrovaù obsah tohoto poæa do ÔalöÌch modulov pri v˝mene spr·v mezi modulmi?");
define("L_CANT_CREATE_IMG_DIR","Nie je moûnÈ vytvoriù adres·r pre obr·zky");
    

  # constants for View setting 
define('L_VIEWS','Pohæady');
define('L_ASCENDING','Vzostupne');
define('L_DESCENDING','Zostupne');
define('L_NO_PS_VIEWS','Nem·te pr·vo meniù pohæady');
define('L_VIEW_OK','Pohæad bol ˙speöne zmenen˝');
define('L_A_VIEW_TIT','Spr·va modulu - definÌcia Pohæadu');
define('L_A_VIEWS','Spr·va modulu - definÌcia Pohæadu');
define('L_VIEWS_HDR','DefinovanÈ pohæady');
define('L_VIEW_DELETE_OK','Pohæad bol ˙speöne zmazan˝');
define('L_DELETE_VIEW','Naozaj chcete zmazaù vybran˝ pohæad?');
define('L_V_BEFORE',L_COMPACT_TOP);
define('L_V_ODD',L_ODD_ROW_FORMAT);
define('L_V_EVENODDDIF',L_EVEN_ODD_DIFFER);
define('L_V_EVEN',L_EVEN_ROW_FORMAT);
define('L_V_AFTER',L_COMPACT_BOTTOM);
define('L_V_GROUP_BY1','Zoskupiù podæa');
define('L_V_GROUP1DIR',' ');
define('L_V_GROUP_BY2',L_V_GROUP_BY1);
define('L_V_GROUP2DIR',' ');
define('L_V_GROUP','Nadpis skupiny');
define('L_V_REMOVE_STRING',L_COMPACT_REMOVE);
define('L_V_MODIFICATION','Typ');
define('L_V_PARAMETER','Parameter');
define('L_V_IMG1','Obr·zok 1');
define('L_V_IMG2','Obr·zok 2');
define('L_V_IMG3','Obr·zok 3');
define('L_V_IMG4','Obr·zok 4');
define('L_V_ORDER1','Zoradiù');
define('L_V_ORDER1DIR',' ');
define('L_V_ORDER2','Zoradiù druhotne');
define('L_V_ORDER2DIR',' ');
define('L_V_SELECTED','Pouûiù vybran˝ Ël·nok');
define('L_V_COND1FLD','Podmienka 1');
define('L_V_COND1OP',' ');
define('L_V_COND1COND',' ');
define('L_V_COND2FLD','Podmienka 2');
define('L_V_COND2OP',' ');
define('L_V_COND2COND',' ');
define('L_V_COND3FLD','Podmienka 3');
define('L_V_COND3OP',' ');
define('L_V_COND3COND',' ');
define('L_V_LISTLEN',L_D_LISTLEN);
define('L_V_FLAG','Flag');
define('L_V_SCROLLER','Zobraziù rolovanie str·nok');
define('L_V_ADITIONAL','œalöÌ');
define('L_COMPACT_VIEW','Prehæad');
define('L_FULLTEXT_VIEW','»l·nok');
define('L_DIGEST_VIEW','Obsah - s˙hrn');
define('L_DISCUSSION_VIEW','Diskusia');
define('L_RELATED_VIEW','S˙visiace Ël·nky');
define('L_CONSTANT_VIEW','Zobrazenie konöt·nt');
define('L_RSS_VIEW','V˝mena spr·v RSS');
define('L_STATIC_VIEW','Statick· str·nka');
define('L_SCRIPT_VIEW','JavaScript');

define("L_MAP","Mapovanie");
define("L_MAP_TIT","Spr·va modulu - v˝mena spr·v - mapovanie polÌ");
define("L_MAP_FIELDS","Mapovanie polÌ");
define("L_MAP_TABTIT","V˝mena spr·v - mapovanie polÌ");
define("L_MAP_FROM_SLICE","Mapovanie z modulu");
define("L_MAP_FROM","Z");
define("L_MAP_TO","Do");
define("L_MAP_DUP","Ned· sa mapovaù do rovnakÈho poæa");
define("L_MAP_NOTMAP","-- Nemapovaù --");
define("L_MAP_OK","Nastavenie mapovania polÌ ˙speöne zmenenÈ");
    
define("L_STATE_FEEDABLE_UPDATE", "KopÌrovaù obsah a zmeny" );
define("L_STATE_FEEDABLE_UPDATE_LOCKED", "KopÌrovaù obsah a zmeny nemeniteæne");

define("L_SITEM_ID_ALIAS",'alias pro skr·tenÈ ËÌslo Ël·nku');
define("L_MAP_VALUE","-- Hodnota --");
define("L_MAP_VALUE2","Hodnota");


/*
$Log$
Revision 1.2  2001/06/21 14:15:44  honzam
feeding improved - field value redefine possibility in se_mapping.php3

Revision 1.1  2001/06/12 16:07:22  honzam
new feeding modes -  "Feed & update" and "Feed & update & lock"

Revision 1.28  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.27  2001/04/17 21:32:08  honzam
New conditional alias. Fixed bug of not displayed top/bottom HTML code in fulltext and category

Revision 1.26  2001/03/30 11:54:35  honzam
offline filling bug and others small bugs fixed

Revision 1.25  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.24  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

Revision 1.21  2001/01/08 13:31:58  honzam
Small bugfixes

Revision 1.20  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

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

