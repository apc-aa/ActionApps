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
define("L_VIEW_SLICE", "Zobraz modul");
define( "L_SLICE_HINT", '<br>Modul zahrniete do vašej *.shtml stránky 
                         pridaním nasledujúceho riadku v HTML kóde: ');
define("L_ITEM_ID_ALIAS",'alias pre èíslo èlánku');
define("L_EDITITEM_ALIAS",'alias pouívanı v administratívnych stránkách index.php3 pre URL itemedit.php3');
define("L_LANG_FILE","Pouitı language súbor");
define("L_PARAMETERS","Parametre");
define("L_SELECT_APP","Vıber aplikácií");
define("L_SELECT_OWNER","Vıber vlastníka");

define("L_CANT_UPLOAD", "Súbor (obrázok) sa nedá uloi");
define("L_MSG_PAGE", "Nastavenia");   // title of message page
define("L_EDITOR_TITLE", "Správy");
define("L_FULLTEXT_FORMAT_TOP", "Hornı HTML kód");
define("L_FULLTEXT_FORMAT", "HTML kód textu správy");
define("L_FULLTEXT_FORMAT_BOTTOM", "Spodnı HTML kód");
define("L_A_FULLTEXT_TIT", "Správa modulu - Vzh¾ad jednej správy");
define("L_FULLTEXT_HDR", "HTML kód pre zobrazenie správy");
define("L_COMPACT_HDR", "HTML kód pre preh¾ad správ");
define("L_ITEM_HDR", "Vstupnı formulár správy");
define("L_A_ITEM_ADD", "Prida správu");
define("L_A_ITEM_EDT", "Upravi správu");
define("L_IMP_EXPORT", "Povoli zasielanie správ do modulu:");
define("L_ADD_NEW_ITEM", "Nová správa");
define("L_DELETE_TRASH", "Vysypa kôš");
define("L_VIEW_FULLTEXT", "Zobrazi správu");
define("L_FULLTEXT", "Celá správa");
define("L_HIGHLIGHTED", "Dôleitá správa");
define("L_A_FIELDS_EDT", "Nastavenia modulu - Nastavenia polí");
define("L_FIELDS_HDR", "Pole správ");
define("L_NO_PS_EDIT_ITEMS", "Nemáte právo upravova správy v tomto module");
define("L_NO_DELETE_ITEMS", "Nemáte právo maza správy");
define("L_NO_PS_MOVE_ITEMS", "Nemáte právo presúva správy");
define("L_NO_PS_COPMPACT", "Nemáte právo upravova vzh¾ad preh¾adu správ");
define("L_FULLTEXT_OK", "Vzh¾ad textu správy bol úspešne zmenenı");
define("L_NO_ITEM", "iadna správa nevyhovuje vášmu zadaniu.");



# aa toolkit common labels
# can be the same for all toolkit aplications
define("L_ACTIVE_BIN", "Aktuálne");
define("L_HOLDING_BIN", "Zásobník");
define("L_TRASH_BIN", "Kôš");

define("L_CATEGORY","Kategórie");
define("L_SLICE_NAME", "Meno");
define("L_DELETED", "Zmazanı");
define("L_D_LISTLEN", "Poèet vypisovanıch správ");  // slice
define("L_ERR_CANT_CHANGE", "Nepodarilo sa zmeni nastavenie modulu");
define("L_ODD_ROW_FORMAT", "Nepárny záznam");
define("L_EVEN_ROW_FORMAT", "Párny záznam");
define("L_EVEN_ODD_DIFFER", "Odlišnı HTML kód pre párne záznamy");
define("L_CATEGORY_TOP", "Hornı HTML kód pre kategóriu");
define("L_CATEGORY_FORMAT", "Nadpis kategórie");
define("L_CATEGORY_BOTTOM", "Spodnı HTML kód pre kategóriu");
define("L_CATEGORY_SORT", "Zoradi správy v preh¾ade pod¾a kategórie");
define("L_COMPACT_TOP", "Hornı HTML kód");
define("L_COMPACT_BOTTOM", "Spodnı HTML kód");
define("L_A_COMPACT_TIT", "Nastavenia modulu - Vzh¾ad preh¾adu správ");
define("L_A_FILTERS_TIT", "Nastavenia modulu - Filtre pre vımenu správ");
define("L_FLT_SETTING", "Nastavenie filtrov pre príjem správ");
define("L_FLT_FROM_SL", "Filter pre príjem správ z modulu");
define("L_FLT_FROM", "Z");
define("L_FLT_TO", "Do");
define("L_FLT_APPROVED", "Ako aktuálnu správu");
define("L_FLT_CATEGORIES", "Kategórie");
define("L_ALL_CATEGORIES", "Všetky kategórie");
define("L_FLT_NONE", "Nie je vybraná iadna vstupná kategória!");
define("L_THE_SAME", "-- rovnaká --");
define("L_EXPORT_TO_ALL", "Povoli exportova správy do všetkıch modulov");

define("L_IMP_EXPORT_Y", "Zasielanie povolené");
define("L_IMP_EXPORT_N", "Zasielanie zakázané");
define("L_IMP_IMPORT", "Príjma správy z:");
define("L_IMP_IMPORT_Y", "Príjma");
define("L_IMP_IMPORT_N", "Nepríjma");
define("L_CONSTANTS_HLP", "Poui následujúce aliasy databázovıch polí");

define("L_ERR_IN", "Chyba v");
define("L_ERR_NEED", "musí bıt vyplnené");
define("L_ERR_LOG", "pouite znaky a-z, A-Z a 0-9");
define("L_ERR_LOGLEN", "musí by dlhé 5 - 32 znakov");
define("L_ERR_NO_SRCHFLDS", "Nebolo zadané preh¾adávané pole!");

define("L_FIELDS", "Polia");
define("L_EDIT", "Editácia");
define("L_DELETE", "Vymaza");
define("L_REVOKE", "Odstráni");
define("L_UPDATE", "Zmeni");
define("L_RESET", "Vymaza formulár");
define("L_CANCEL", "Zruši");
define("L_ACTION", "Akcia");
define("L_INSERT", "Vloi");
define("L_NEW", "Novı");
define("L_GO", "Choï");
define("L_ADD", "Prida");
define("L_USERS", "Uivatelia");
define("L_GROUPS", "Skupiny");
define("L_SEARCH", "H¾adanie");
define("L_DEFAULTS", "Default");
define("L_SLICE", "Modul");
define("L_DELETED_SLICE", "Nebol nájdenı iaden modul, ku ktorému máte prístup");
define("L_A_NEWUSER", "Novı uivate¾ v systéme");
define("L_NEWUSER_HDR", "Novı uivate¾");
define("L_USER_LOGIN", "Uivate¾ské meno");
define("L_USER_PASSWORD1", "Heslo");
define("L_USER_PASSWORD2", "Potvrdi heslo");
define("L_USER_FIRSTNAME", "Meno");
define("L_USER_SURNAME", "Priezvisko");
define("L_USER_MAIL", "E-mail");
define("L_USER_SUPER", "Administratívny úèet");
define("L_A_USERS_TIT", "Nastavenia modulu - Uivatelia");
define("L_A_PERMISSIONS", "Nastavenia modulu - Prístupové práva");
define("L_A_ADMIN", "Nastavenia modulu - Vzh¾ad Administrácie");
define("L_A_ADMIN_TIT", L_A_ADMIN);
define("L_ADMIN_FORMAT", "HTML kód pre zobrazenie správy");
define("L_ADMIN_FORMAT_BOTTOM", "Spodnı HTML");
define("L_ADMIN_FORMAT_TOP", "Hornı HTML");
define("L_ADMIN_HDR", "Vıpis správ v administratívnych stránkách");
define("L_ADMIN_OK", "Vzh¾ad administratívnych stránok úspešne zmenenı");
define("L_ADMIN_REMOVE", "Odstraòované reazce");

define("L_ROLE_AUTHOR", "Autor");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administrátor");
define("L_ROLE_SUPER", "Super");

define("L_MAIN_SET", "Hlavné nastavenia");
define("L_PERMISSIONS", "Nastavenia práv");
define("L_PERM_CHANGE", "Zmena súèasnıch práv");
define("L_PERM_ASSIGN", "Pridelenie novıch práv");
define("L_PERM_NEW", "H¾adej uivate¾a alebo skupinu");
define("L_PERM_SEARCH", "Priradenie novıch práv");
define("L_PERM_CURRENT", "Zmena súèasnıch práv");
define("L_USER_NEW", "Novı uivate¾");
define("L_DESIGN", "Vzh¾ad");
define("L_COMPACT", "Preh¾ad správ");
define("L_COMPACT_REMOVE", "Odstraòované reazce");
define("L_FEEDING", "Vımena správ");
define("L_IMPORT", "Zasielanie & Príjem");
define("L_FILTERS", "Filtre");

define("L_A_SLICE_ADD", "Správa modulu - Pridanie modulu");
define("L_A_SLICE_EDT", "Správa modulu - Úprava modulu");
define("L_A_SLICE_CAT", "Správa modulu - Nastavenie kategórií");
define("L_A_SLICE_IMP", "Správa modulu - Vımena správ");
define("L_FIELD", "Poloka");
define("L_FIELD_IN_EDIT", "Zobrazi");
define("L_NEEDED_FIELD", "Povinná");
define("L_A_SEARCH_TIT", "Správa modulu - Vyh¾adávací formulár");
define("L_SEARCH_HDR", "Vyh¾adávacie kritériá");
define("L_SEARCH_HDR2", "Vyh¾adáva v polokách");
define("L_SEARCH_SHOW", "Zobrazi");
define("L_SEARCH_DEFAULT", "Štandardné nastavenia");
define("L_SEARCH_SET", "Vyh¾adávací formulár");
define("L_AND", "AND");
define("L_OR", "OR");
define("L_SRCH_KW", "Search for");
define("L_SRCH_FROM", "From");
define("L_SRCH_TO", "To");
define("L_SRCH_SUBMIT", "Search");
define("L_NO_PS_EDIT", "Nemáte právo upravova tento modul");
define("L_NO_PS_ADD", "Nemáte právo pridáva modul");
define("L_NO_PS_COPMPACT", "Nemáte právo meni vzh¾ad preh¾adu správ");
define("L_NO_PS_FULLTEXT", "Nemáte právo meni vzh¾ad vıpisu správy");
define("L_NO_PS_CATEGORY", "Nemáte právo meni nastavenia kategórií");
define("L_NO_PS_FEEDING", "Nemáte právo meni nastavenia vımeny správ");
define("L_NO_PS_USERS", "Nemáte právo ku správe uivate¾ov");
define("L_NO_PS_FIELDS", "Nemáte právo meni nastavenia poloiek");
define("L_NO_PS_SEARCH", "Nemáte právo meni nastavenia vyh¾adávánia");

define("L_BAD_RETYPED_PWD", "Vyplnené heslá nie sú rovnaké");
define("L_ERR_USER_ADD", "Nepodarilo se pridat uivate¾a do systému - chyba LDAP");
define("L_NEWUSER_OK", "Uivate¾ bol úspešne pridanı do systému");
define("L_COMPACT_OK", "Vzh¾ad preh¾adu správ bol úspešne zmenenı");
define("L_BAD_ITEM_ID", "Zlé èíslo správy");
define("L_ALL", " - všetko - ");
define("L_CAT_LIST", "Kategórie správ");
define("L_CAT_SELECT", "Kategórie v tomto module");
define("L_NEW_SLICE", "Novı modul");
define("L_ASSIGN", "Priradi");
define("L_CATBINDS_OK", "Nastavenia kategórií boli úspešne zmenené");
define("L_IMPORT_OK", "Nastavenia vımeny správ úspešne zmenené");
define("L_FIELDS_OK", "Nastavenia poloiek úspešne zmenené");
define("L_SEARCH_OK", "Nastavenía vyh¾adávacieho formulára úspešne zmenené");
define("L_NO_CATEGORY", "Kategórie neboli definované");
define("L_NO_IMPORTED_SLICE", "Nie je nastavenı iaden modul, z ktorého sa majú príjma správy");
define("L_NO_USERS", "Uivate¾ (skupina) nenájdená");

define("L_TOO_MUCH_USERS", "Nájdenıch príliš ve¾a uivate¾ov alebo skupín.");
define("L_MORE_SPECIFIC", "Skúste zada presnejšie údaje.");
define("L_REMOVE", "Odstráni");
define("L_ID", "Id");
define("L_SETTINGS", "Nastavenia");
define("L_LOGO", "APC toolkit");
define("L_USER_MANAGEMENT", "Uivatelia");
define("L_ITEMS", "Správa príspevkov");
define("L_NEW_SLICE_HEAD", "Novı modul");
define("L_ERR_USER_CHANGE", "Nie je moné zmeni údaje uivate¾a - LDAP Error");
define("L_PUBLISHED", "Zverejnené");
define("L_EXPIRED", "Expirované");
define("L_NOT_PUBLISHED", "Zatia¾ nepublikované");
define("L_EDIT_USER", "Editácia uivate¾a");
define("L_EDITUSER_HDR", L_EDIT_USER);

define("NO_SOURCE_URL", "javascript: window.alert('Nebolo zadané url zdroja')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('Nebolo zadané url externého odkazu')");

# editor interface constants
define("L_PUBLISHED_HEAD", "PUB");
define("L_HIGHLIGHTED_HEAD", "!");
define("L_FEEDED_HEAD", "cudzie");
define("L_MORE_DETAILS", "Viac podrobností");
define("L_LESS_DETAILS", "Menej podrobností");
define("L_UNSELECT_ALL", "Zruši vıber");
define("L_SELECT_VISIBLE", "Vybra zobrazené");
define("L_UNSELECT_VISIBLE", "Zruši vıber");

define("L_SLICE_ADM","Administrácia modulu - Menu");
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

define("L_FEED", "Vımena správ");
define("L_FEEDTO_TITLE", "Poskytnú správu do modulu");
define("L_FEED_TO", "Poskytnú vybrané správy do zvolenıch modulov");
define("L_NO_PERMISSION_TO_FEED", "Nedá sa");
define("L_NO_PS_CONFIG", "Nemáte právo nastavova konfiguraèné parametre tohoto modulu");
define("L_SLICE_CONFIG", "Administrácia");
define("L_CHBOX_HEAD", "&nbsp;");   // title of checkbox in admin interface
define("L_CHBOX", "Checkbox"); 
define("L_CATNAME", "Kategória");
define("L_CATEGORY_ID", "ID kategórie");
define("L_EDITED_BY","Editované");
define("L_MASTER_ID", "ID zdrojového modulu");
define("L_CHANGE_MARKED", "Zmeni vybrané");
define("L_MOVE_TO_ACTIVE_BIN", "Vystavi");
define("L_MOVE_TO_HOLDING_BIN", "Posla do zásobníku");
define("L_MOVE_TO_TRASH_BIN", "Posla do koša");
define("L_OTHER_ARTICLES", "Ostatné správy");
define("L_MISC", "Príkazy");
define("L_HEADLINE_EDIT", "Nadpis (editácia po kliknutí)");
define("L_HEADLINE_PREVIEW", "Nadpis (preview po kliknutí)");
define("L_EDIT_LINK", "&nbsp;");
define("L_PREVIEW_LINK", "&nbsp;");
define("L_ARTICLE_MANAGER", "Správa správ");
define("L_SWITCH_TO", "Modul:");
define("L_ADMIN", "Administrácia");

define("L_NO_PS_NEW_USER", "Nemáte právo vytvori uivate¾a");
define("L_ALL_GROUPS", "Všetky skupiny");
define("L_USERS_GROUPS", "Uivate¾ove skupiny");
define("L_REALY_DELETE_USER", "Naozaj chcete vymaza daného uivate¾a zo systému?");
define("L_REALY_DELETE_GROUP", "Naozaj chcete vymaza danú skupinu zo systému?");
define("L_TOO_MUCH_GROUPS", "Príliš vela skupín.");
define("L_NO_GROUPS", "Skupina nenájdená");
define("L_GROUP_NAME", "Meno");
define("L_GROUP_DESCRIPTION", "Popis");
define("L_GROUP_SUPER", "Administratívna skupina");
define("L_ERR_GROUP_ADD", "Nie je moné prida skupinu do systému");
define("L_NEWGROUP_OK", "Skupina bola úspešne pridaná");
define("L_ERR_GROUP_CHANGE", "Nie je moné zmenit skupinu");
define("L_A_UM_USERS_TIT", "Správa uivate¾ov - Uivatelia");
define("L_A_UM_GROUPS_TIT", "Správa uivate¾ov - Skupiny");
define("L_EDITGROUP_HDR", "Editácia skupiny");
define("L_NEWGROUP_HDR", "Nová skupina");
define("L_GROUP_ID", "ID skupiny");
define("L_ALL_USERS", "Všetci uivatelia");
define("L_GROUPS_USERS", "Uivatelia v skupine");
define("L_POST", "Posla");
define("L_INSERT_PREV", "Vloit a pozriet");
define("L_POST_PREV", "Posla a pozrie");
define("L_OK", "OK");
define("L_ACTIVE_BIN_EXPIRED", "Aktuálne - Expirované");
define("L_ACTIVE_BIN_PENDING", "Aktuálne - Pripravené");
define("L_ACTIVE_BIN_EXPIRED_MENU", "... expirované");
define("L_ACTIVE_BIN_PENDING_MENU", "... pripravené");

define("L_FIELD_PRIORITY", "Priorita");
define("L_FIELD_TYPE", "Typ");
define("L_CONSTANTS", "Hodnoty");
define("L_DEFAULT", "Default");
define("L_DELETE_FIELD", "Naozaj chcete vymaza toto pole z modulu?");
define("L_FEEDED", "Prebrané");
define("L_HTML_DEFAULT", "štandardne poui HTML kód");
define("L_HTML_SHOW", "Zobrazi vo¾bu 'HTML' / 'obyèajnı text'");
define("L_NEW_OWNER", "Novı vlastník");
define("L_NEW_OWNER_EMAIL", "E-mail nového vlastníka");
define("L_NO_FIELDS", "V tomto module nie sú definované iadne polia (èo je èudné)");
define("L_NO_FIELD", "");
define("L_NO_PERM_TO_FEED", "Nemáte právo nastavi vımenu správ so iadnym modulom");
define("L_NO_SLICES", "iaden modul");
define("L_NO_TEMPLATES", "iadna šablóna");
define("L_OWNER", "Vlastník");
define("L_SLICES", "Moduly");
define("L_TEMPLATE", "Šablóna");
define("L_VALIDATE", "Zkontrolova");

define("L_FIELD_DELETE_OK", "Pole odstránené");

define("L_WARNING_NOT_CHANGE","<p>POZOR: Tieto nastavenia by mal meni iba ten, kto vie èo robí!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Funkcia, ktorá sa pouije pre zobrazenie pola vo vstupnom formulári. Niektoré pouívajú Konštanty, niektoré pouívajú Parametre. Viac informácií nájdete v Sprievodcovi s Nápovedou.");
define("L_INPUT_SHOW_FUNC_C_HLP","Vyberte Skupinu Konštánt alebo Modul.");
define("L_INPUT_SHOW_FUNC_HLP","Parametre sú oddelené dvojbodkou (:) alebo (v špeciálnych prípadoch) apostrofom (').");
define("L_INPUT_DEFAULT_F_HLP","Funkcia, ktorá sa pouije pre generovanie defaultnıch hodnôt po¾a:<BR>Now - aktuálny dátum<BR>User ID - identifikátor prihláseného uivate¾a<BR>Text - text uvedenı v poli Parameter<br>Date - aktuálny dátum plus <Parametr> dní");
define("L_INPUT_DEFAULT_HLP","Parameter pre defaulné hodnoty Text a Date (viï vyššie)");

define("L_INPUT_DEFAULT_TXT", "Text");
define("L_INPUT_DEFAULT_DTE", "Dátum");
define("L_INPUT_DEFAULT_UID", "ID uívate¾a");
define("L_INPUT_DEFAULT_NOW", "Aktuálny dátum a èas*");
define("L_INPUT_DEFAULT_VAR", "Variable"); # Added by Ram on 5th March 2002

define("L_INPUT_SHOW_TXT","Text Area");
define("L_INPUT_SHOW_EDT","Rich Edit Text Area");
define("L_INPUT_SHOW_FLD","Text Field");
define("L_INPUT_SHOW_SEL","Select Box");
define("L_INPUT_SHOW_RIO","Radio Button");
define("L_INPUT_SHOW_DTE","Dátum");
define("L_INPUT_SHOW_CHB","Check Box");
define("L_INPUT_SHOW_MCH", "Viacero Checkboxov");
define("L_INPUT_SHOW_MSE", "Select Box - multi");
define("L_INPUT_SHOW_FIL","Upload súboru");
define("L_INPUT_SHOW_ISI","Relácia - Select Box");         # added 08/22/01
define("L_INPUT_SHOW_ISO","Relácia - Okno");               # added 08/22/01
define("L_INPUT_SHOW_WI2","Dva Select Boxy");              # added 08/22/01
define("L_INPUT_SHOW_PRE","Select Box s prednastavením");  # added 08/22/01
define("L_INPUT_SHOW_NUL","Nezobrazova");
                              
define("L_INPUT_VALIDATE_TEXT","Text");
define("L_INPUT_VALIDATE_URL","URL");
define("L_INPUT_VALIDATE_EMAIL","E-mail");
define("L_INPUT_VALIDATE_NUMBER","Èíslo");
define("L_INPUT_VALIDATE_ID","Id");
define("L_INPUT_VALIDATE_DATE","Dátum");
define("L_INPUT_VALIDATE_BOOL","Áno/Nie");

define("L_INPUT_INSERT_QTE","Text");
define("L_INPUT_INSERT_DTE","Dátum");
define("L_INPUT_INSERT_CNS","Konštanta");
define("L_INPUT_INSERT_NUM","Èíslo");
define("L_INPUT_INSERT_IDS","ID èlánku");
define("L_INPUT_INSERT_BOO","¡Ano/Nie");
define("L_INPUT_INSERT_UID","ID uívate¾a");
define("L_INPUT_INSERT_NOW","Aktuálny dátum a èas");
define("L_INPUT_INSERT_FIL","Súbor");
define("L_INPUT_INSERT_NUL","Prázdne");

define("L_INPUT_DEFAULT","Default");
define("L_INPUT_BEFORE","HTML kód pred tımto po¾om");
define("L_INPUT_BEFORE_HLP","HTML kód, ktorı sa zobrazí vo vstupnom formulári pred tımto po¾om");
define("L_INPUT_FUNC","Typ Vstupu");
define("L_INPUT_HELP","Nápoveda");
define("L_INPUT_HELP_HLP","Nápoveda zobrazená pre toto pole vo vstupnom formulári");
define("L_INPUT_MOREHLP","Viac informácií");
define("L_INPUT_MOREHLP_HLP","Nápoveda, ktorá sa zobrazí po stlaèení '?' vo vstupnom formulári");
define("L_INPUT_INSERT_HLP","Spôsob uloenia do databázy");
define("L_INPUT_VALIDATE_HLP","Funkcia pre kontrolu vstupu (validace)");

define("L_CONSTANT_NAME", "Meno");
define("L_CONSTANT_VALUE", "Hodnota");
define("L_CONSTANT_PRIORITY", "Priorita");
define("L_CONSTANT_PRI", "Priorita");
define("L_CONSTANT_GROUP", "Skupina hodnôt");
define("L_CONSTANT_GROUP_EXIST", "Táto skupina hodnôt u existuje");
define("L_CONSTANTS_OK", "Zmena hodnôt úspešne vykonaná");
define("L_A_CONSTANTS_TIT", "Správa modulu - Nastavenia hodnôt");
define("L_A_CONSTANTS_EDT", "Správa modulu - Nastavenia hodnôt");
define("L_CONSTANTS_HDR", "Hodnoty");
define("L_CONSTANT_NAME_HLP", "zobrazené&nbsp;vo&nbsp;vstupnom&nbsp;formulári");
define("L_CONSTANT_VALUE_HLP", "uloené&nbsp;v&nbsp;databáze");
define("L_CONSTANT_PRI_HLP", "Poradie&nbsp;hodnôt");
define("L_CONSTANT_CLASS", "Nadkategórie");
define("L_CONSTANT_CLASS_HLP", "len&nbsp;pre&nbsp;kategórie");
define("L_CONSTANT_DEL_HLP", "Pre odstránenie kartegórie vymate jej meno");

$L_MONTH = array( 1 => 'Január', 'Február', 'Marec', 'Apríl', 'Máj', 'Jún', 
		'Júl', 'August', 'September', 'Október', 'November', 'December');

define("L_NO_CATEGORY_FIELD","Pole kategórie nie je v tomto module definované.<br>  Pridajte pole kategórie do modulu na stránke Polia.");
define("L_PERMIT_ANONYMOUS_POST","Anonymné vkladanie");
define("L_PERMIT_OFFLINE_FILL","Off-line plnenie");
define("L_SOME_CATEGORY", "<kategória>");

define("L_ALIASES", "Aliasy pre polia v databáze");
define("L_ALIAS1", "Alias 1"); 
define("L_ALIAS_HLP", "Musí zaèína znakmi \"_#\".<br>Alias musí by presne 10 znakov dlhı vrátane \"_#\".<br>Mal by by kapitálkami."); 
define("L_ALIAS_FUNC", "Funkcia"); 
define("L_ALIAS_FUNC_F_HLP", "Funkcia, ktorá zabezpeèí zobrazenie polia na stránke"); 
define("L_ALIAS_FUNC_HLP", "Doplnkovı parameter odovzdávanı zobrazovacej funkcii. Podrobnosti viï include/item.php3 file"); 
define("L_ALIAS_HELP", "Nápoveda"); 
define("L_ALIAS_HELP_HLP", "Nápovednı text pre tento alias"); 
define("L_ALIAS2", "Alias 2"); 
define("L_ALIAS3", "Alias 3"); 

define("L_TOP_HLP", "HTML kód, ktorı sa zobrazí pred kódom modulu");
define("L_FORMAT_HLP", "Sem patrí HTML kód v kombinácii s aliasmi uvedenımi dole na stránke
                     <br>Aliasy budú v momente zobrazovania na web nahradené skutoènımi hodnotami z databázy");
define("L_BOTTOM_HLP", "HTML kód, ktorı sa zobrazí za vlastnım kódom modulu");
define("L_EVEN_ROW_HLP", "TIP: Rozlíšením párnych a nepárnych záznamov môete dosiahnú napríklad farebné odlíšenie riadkov");

define("L_SLICE_URL", "URL modulu");
define("L_BRACKETS_ERR", "Zátvorky v query nie sú spárované: ");
define("L_A_SLICE_ADD_HELP", "Novı modul môete vytvori na základe šablóny, alebo skopírova nastavenia z u existujúceho modulu (vytvorí sa presná kópia vrátane nastavení.");
define("L_REMOVE_HLP", "Odstráni prázdné zátvorky a pod. Pouite ## ako oddelovaè.");

define("L_COMPACT_HELP", "Na tejto stránke je moné nastavi, èo sa objaví na stránke preh¾adu správ");
define("L_A_FULLTEXT_HELP", "Na tejto stránke je moné nastavi, èo sa objaví na stránke pri prezeraní tela správy");
define("L_PROHIBITED", "Zakázané");
define("L_HTML", "HTML");
define("L_PLAIN_TEXT", "Obyèajnı text");
define("L_A_DELSLICE", "Správa modulu - Vymaza modul");
define("L_DELSLICE_TIT", L_A_DELSLICE);
define("L_DELSLICE_HDR", "Vyber modul pre vymazanie");
define("L_DEL_SLICE_HLP","<p>Je moné vymaza len moduly, ktoré boli oznaèené pre vymazanie na stránke &quot;<b>". L_SLICE_SET ."</b>&quot;</p>");
define("L_A_DELSLICE", L_DELSLICE_TIT);
define("L_DELETE_SLICE", "Naozaj chcete vymaza tento modul a všetky jeho správy?");
define("L_NO_SLICE_TO_DELETE", "iaden modul nebol oznaèenı za vymazanı");
define("L_NO_SUCH_SLICE", "Zlé èíslo modulu");
define("L_NO_DELETED_SLICE", "Modul nie je oznaèenı za vymazanı");
define("L_DELSLICE_OK", "Modul bol vymazanı, tabu¾ky boly optimalizované");
define("L_DEL_SLICE", "Zmaza modul");
define("L_FEED_STATE", "Zdie¾anie tohto po¾a");
define("L_STATE_FEEDABLE", "Kopírova obsah" );
define("L_STATE_UNFEEDABLE", "Nekopírova" );
define("L_STATE_FEEDNOCHANGE", "Kopírova nemenitelne" );
define("L_INPUT_FEED_MODES_HLP", "Má sa kopírova obsah tohoto po¾a do ïalších modulov pri vımene správ mezi modulmi?");
define("L_CANT_CREATE_IMG_DIR","Nie je moné vytvori adresár pre obrázky");
 
  # constants for View setting 
define('L_VIEWS','Poh¾ady');
define('L_ASCENDING','Vzostupne');
define('L_DESCENDING','Zostupne');
define('L_NO_PS_VIEWS','Nemáte právo meni poh¾ady');
define('L_VIEW_OK','Poh¾ad bol úspešne zmenenı');
define('L_A_VIEW_TIT','Správa modulu - definícia Poh¾adu');
define('L_A_VIEWS','Správa modulu - definícia Poh¾adu');
define('L_VIEWS_HDR','Definované poh¾ady');
define('L_VIEW_DELETE_OK','Poh¾ad bol úspešne zmazanı');
define('L_DELETE_VIEW','Naozaj chcete zmaza vybranı poh¾ad?');
define('L_V_BEFORE',L_COMPACT_TOP);
define('L_V_ODD',L_ODD_ROW_FORMAT);
define('L_V_EVENODDDIF',L_EVEN_ODD_DIFFER);
define('L_V_EVEN',L_EVEN_ROW_FORMAT);
define('L_V_AFTER',L_COMPACT_BOTTOM);
define('L_V_GROUP_BY1','Zoskupi pod¾a');
define('L_V_GROUP1DIR',' ');
define('L_V_GROUP_BY2',L_V_GROUP_BY1);
define('L_V_GROUP2DIR',' ');
define('L_V_GROUP','Nadpis skupiny');
define('L_V_REMOVE_STRING',L_COMPACT_REMOVE);
define('L_V_MODIFICATION','Typ');
define('L_V_PARAMETER','Parameter');
define('L_V_IMG1','Obrázok 1');
define('L_V_IMG2','Obrázok 2');
define('L_V_IMG3','Obrázok 3');
define('L_V_IMG4','Obrázok 4');
define('L_V_ORDER1','Zoradi');
define('L_V_ORDER1DIR',' ');
define('L_V_ORDER2','Zoradi druhotne');
define('L_V_ORDER2DIR',' ');
define('L_V_SELECTED','Poui vybranı èlánok');
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
define('L_V_SCROLLER','Zobrazi rolovanie stránok');
define('L_V_ADITIONAL','Ïalší');
define('L_COMPACT_VIEW','Preh¾ad');
define('L_FULLTEXT_VIEW','Èlánok');
define('L_DIGEST_VIEW','Obsah - súhrn');
define('L_DISCUSSION_VIEW','Diskusia');
define('L_RELATED_VIEW','Súvisiace èlánky');
define('L_CONSTANT_VIEW','Zobrazenie konštánt');
define('L_RSS_VIEW','Vımena správ RSS');
define('L_STATIC_VIEW','Statická stránka');
define('L_SCRIPT_VIEW','JavaScript');

define("L_MAP","Mapovanie");
define("L_MAP_TIT","Správa modulu - vımena správ - mapovanie polí");
define("L_MAP_FIELDS","Mapovanie polí");
define("L_MAP_TABTIT","Vımena správ - mapovanie polí");
define("L_MAP_FROM_SLICE","Mapovanie z modulu");
define("L_MAP_FROM","Z");
define("L_MAP_TO","Do");
define("L_MAP_DUP","Nedá sa mapova do rovnakého po¾a");
define("L_MAP_NOTMAP","-- Nemapova --");
define("L_MAP_OK","Nastavenie mapovania polí úspešne zmenené");
    
define("L_STATE_FEEDABLE_UPDATE", "Kopírova obsah a zmeny" );
define("L_STATE_FEEDABLE_UPDATE_LOCKED", "Kopírova obsah a zmeny nemenite¾ne");

define("L_SITEM_ID_ALIAS",'alias pre skrátené císlo clánku');
define("L_MAP_VALUE","-- Hodnota --");
define("L_MAP_VALUE2","Hodnota");
define("L_ORDER", "Zoradit");
define("L_INSERT_AS_NEW","Vloit ako novı");

// constnt view constants
define("L_CONST_NAME_ALIAS", "Meno");
define("L_CONST_VALUE_ALIAS", "Hodnota");
define("L_CONST_PRIORITY_ALIAS", "Priorita");
define("L_CONST_GROUP_ALIAS", "Skupina hodnôt");
define("L_CONST_CLASS_ALIAS", "Nadkategórie (pouitelné len pre kategórie)");
define("L_CONST_COUNTER_ALIAS", "Poradové císlo hodnoty");
define("L_CONST_ID_ALIAS", "Identifikacné císlo hodnoty");

define('L_V_CONSTANT_GROUP','Skupina hodnôt');
define("L_NO_CONSTANT", "Hodnota nenájdená");

// Discussion constants.
define("L_DISCUS_SEL","Zobrazit diskusiu");
define("L_DISCUS_EMPTY"," -- iadna -- ");
define("L_DISCUS_HTML_FORMAT","Diskusiu formátovat v HTML");
define("L_EDITDISC_ALIAS","Alias pouívanı v administratívnych stránkách index.php3 pre URL discedit.php3");

define("L_D_SUBJECT_ALIAS","Alias pre predmet príspevku");
define("L_D_BODY_ALIAS"," Alias pre text príspevku");
define("L_D_AUTHOR_ALIAS"," Alias pre autora príspevku");
define("L_D_EMAIL_ALIAS","Alias pre e-mail autora");
define("L_D_WWWURL_ALIAS","Alias pre adresu WWW stránok autora ");
define("L_D_WWWDES_ALIAS","Alias pre popis WWW stránok autora");
define("L_D_DATE_ALIAS","Alias pre dátum a cas odoslania príspevku");
define("L_D_REMOTE_ADDR_ALIAS","Alias pre IP adresu autorovho pocítaca");
define("L_D_URLBODY_ALIAS","Alias pre odkaz na text príspevku<br>
                             <i>Pouitie: </i>v kóde pre prehladové zobrazenie príspevkov<br>
                             <i>Príklad: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>");
define("L_D_CHECKBOX_ALIAS","Alias pre checkbox pre vybranie príspevku");
define("L_D_TREEIMGS_ALIAS","Alias pre obrázky");
define("L_D_ALL_COUNT_ALIAS","Alias pre pocet všetkıch príspevkov k danému clánku");
define("L_D_APPROVED_COUNT_ALIAS","Alias pre pocet schválenıch príspevkov k danému èlánku");
define("L_D_URLREPLY_ALIAS","Alias pre odkaz na formulár<br>
                             <i>Pouitie: </i>v kóde pre plné znenie príspevku<br>
                             <i>Príklad: </i>&lt;a href=_#URLREPLY&gt;Odpovedat&lt;/a&gt;");
define("L_D_URL","Alias pre odkaz na diskusiu<br>
                             <i>Poitie: </i>v kóde formulára<br>
                             <i>Príklad: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">");
define("L_D_ID_ALIAS"," Alias pre císlo príspevku<br>
                             <i>Poitie: </i>v kóde formulára<br>
                             <i>Príklad: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">");
define("L_D_ITEM_ID_ALIAS"," Alias pre èíslo èlánku<br>
                             <i>Poitie: </i>v kóde formulára<br>
                             <i>Príklad: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">");

define("L_D_BUTTONS","Alias pre tlacítka Zobrazit všetko, Zobrazit vybrané, Pridat novı<br>
                             <i>Pouitie: </i>v spodnom HTML kóde");

define("L_D_COMPACT" , "HTML kód pre prehladové zobrazenie príspevkov");
define("L_D_SHOWIMGS" , "Zobrazit obrázky");
define("L_D_ORDER" , "Zoradit");
define("L_D_FULLTEXT" ,"HTML kód pre plné znenie príspevku");

define("L_D_ADMIN","Správy - Správa diskusnıch príspevkov");
define("L_D_NODISCUS","iadne diskusné príspevky");
define("L_D_TOPIC","Titulok");
define("L_D_AUTHOR","Autor");
define("L_D_DATE","Dátum");
define("L_D_ACTIONS","Akcia");
define("L_D_DELETE","Zmazat");
define("L_D_EDIT","Editovat");
define("L_D_HIDE","Skryt");
define("L_D_APPROVE","Schválit");

define("L_D_EDITDISC","Správy - Správa diskusnıch príspevkov - Editácia príspevku");
define("L_D_EDITDISC_TABTIT","Editácia príspevku");
define("L_D_SUBJECT","Predmet");
define("L_D_AUTHOR","Autor");
define("L_D_EMAIL","E-mail");
define("L_D_BODY","Text príspevku");
define("L_D_URL_ADDRESS","WWW autora - URL");
define("L_D_URL_DES","WWW autora - popis");
define("L_D_REMOTE_ADDR","IP adresa pocítaca autora");

define('L_D_SELECTED_NONE',"Nebol vybranı iaden príspevok");
define("L_D_DELETE_COMMENT","Chcete zmazat príspevok?");

define("L_D_FORM","HTML kód formulára pre poslanie príspevku");
define("L_D_BACK","Spät");
define("L_D_ITEM","Clánok: ");
define("L_D_ADMIN2","Správa diskusnıch príspevkov");

define("L_D_SHOW_SELECTED","Zobraz vybrané");
define("L_D_SHOW_ALL","Zobraz všetko");
define("L_D_ADD_NEW","Pridaj novı");

define("L_TOO_MUCH_RELATED","Je vybránıch príliš vela súvisiacich clánkov.");
define("L_SELECT_RELATED","Vıber souvisiacich clánkov");
define("L_SELECT_RELATED_1WAY","Áno");
define("L_SELECT_RELATED_2WAY","Vzájomne");

// - Cross server networking --------------------------------------

define("L_INNER_IMPORT","Lokálna vımena");
define("L_INTER_IMPORT","Príjem z uzlov");
define("L_INTER_EXPORT","Zasielanie do uzlov");

define("L_NODES_MANAGER","Uzly");
define("L_NO_PS_NODES_MANAGER","Nemáte práva pre správu uzlov");
define("L_NODES_ADMIN_TIT","Správa uzlov");
define("L_NODES_LIST","Zoznam uzlov");
define("L_NODES_YOUR_NODE","Názov vášho uzlu");
define("L_NODES_YOUR_GETXML","Váš getxml je");
define("L_NODES_ADD_NEW","Pridanie uzlu");
define("L_NODES_EDIT","Editácia uzlu");
define("L_NODES_NODE_NAME","Meno uzlu ");
define("L_NODES_SERVER_URL","URL súboru getxml.php3");
define("L_NODES_PASWORD","Heslo");
define("L_SUBMIT","Poslat");
define("L_NODES_SEL_NONE","Nebol vybranı uzol");
define("L_NODES_CONFIRM_DELETE","Naozaj chcete zmazat uzol?");
define("L_NODES_NODE_EMPTY","Meno uzla musí byt vyplnené");

define("L_IMPORT_TIT","Správa prijmanıch modulov");
define("L_IMPORT_LIST","Zoznam príjmanıch modulov do modulu ");
define("L_IMPORT_CONFIRM_DELETE","Naozaj chcete zrušit príjem z tohto modulu?");
define("L_IMPORT_SEL_NONE","Nebol vybranı modul");
define("L_IMPORT_NODES_LIST","Zoznam uzlov");
define("L_IMPORT_CREATE","Príjmat moduly z tohto uzla");
define("L_IMPORT_NODE_SEL","Nebol vybranı uzol");
define("L_IMPORT_SLICES","Zoznam príjmanıch modulov");
define("L_IMPORT_SLICES2","Zoznam dostupnıch modulov z uzla ");
define("L_IMPORT_SUBMIT","Zvolte modul");
define("L_IMPORT2_OK","Príjem z modulu úspešne vytvorenı");
define("L_IMPORT2_ERR","Príjem z modulu bol u vytvorenı");

define("L_RSS_ERROR","Nepodarilo se nadviazat spojenie alebo prijat údaje. Kontaktuje administrátora");
define("L_RSS_ERROR2","Neplatné heslo pre uzol: ");
define("L_RSS_ERROR3","Kontaktujte administrátora lokálneho uzla.");
define("L_RSS_ERROR4","iadne dostupné moduly. Nemáte práva príjmat údaje z tohto uzla. ".
 "Kontaktujte administrátora vzdialeného modulu a skontrolujte, e dostal vaše správne uivatelské meno.");

define("L_EXPORT_TIT","Správa povolení zasielania modulov");
define("L_EXPORT_CONFIRM_DELETE","Naozaj chcete zrušit povolenie zasielania tohto modulu?");
define("L_EXPORT_SEL_NONE","Nebol vybranı uzol a uivatel");
define("L_EXPORT_LIST","Zoznam uzlov a uivatelov, kde bude zasielanı modul ");
define("L_EXPORT_ADD","Pridajte uzol a uivatela");
define("L_EXPORT_NAME","Meno uivatela");
define("L_EXPORT_NODES","Zoznam uzlov");

define("L_RSS_TITL", "Meno modulu pre RSS");
define("L_RSS_LINK", "Odkaz na modul pre RSS");
define("L_RSS_DESC", "Krátkı popis (vlastník a meno) modulu pre RSS");
define("L_RSS_DATE", "Dátum v RSS prehlade je generovanı vo formáte RSS");

define("L_NO_PS_EXPORT_IMPORT", "Nemáte právo exportovat / importovat moduly");
define("L_EXPORT_SLICE", "Export");
define("L_IMPORT_SLICE", "Import");
define("L_EXPIMP_SET", "Štruktúra modulu");

define("L_E_EXPORT_TITLE", "Export štruktúry modulu");
define("L_E_EXPORT_MEMO", "Vyberte si jeden z dvoch spôsobov exportu:");
define("L_E_EXPORT_DESC", "Pri exporte \"do iného Toolkitu\" sa bude exportovat len aktuálna šablóna "
		."a vy pre nu vyberiete novı identifikátor.");
define("L_E_EXPORT_DESC_BACKUP", "Pri exporte \"do Backupu\" si môete vybrat niekolko šablón naraz.");
define("L_E_EXPORT_MEMO_ID","Vyberte novı identifikátor šablóny dlhı presne 16 znakov: ");
define("L_E_EXPORT_SWITCH", "Export do Backupu");
define("L_E_EXPORT_SWITCH_BACKUP", "Export do iného Toolkitu");
define("L_E_EXPORT_IDLENGTH", "Dlka identifikátora musí byt 16 znakov, a nie ");
define("L_E_EXPORT_TEXT_LABEL", "Tento text si niekde ulote. Môete ho pouit pro import šablóny do Toolkitu:");
define("L_E_EXPORT_LIST", "Oznacte moduly, ktoré CHCETE exportovat:");

define("L_E_IMPORT_TITLE", "Import štruktúry modulov");
define("L_E_IMPORT_SEND", "Odoslat štruktúru modulov");
define("L_E_IMPORT_MEMO", "Import štruktúry modulu probehne takto:<br>"
			."Vlote exportovanı text do rámika a stlacte tlaèítko Odeslat.<br>"
			."Štruktúra modulu s definíciami polí sa nacíta a pridá do Toolkitu.");
define("L_E_IMPORT_OPEN_ERROR","Neznáma chyba pri otevávaní súboru.");
define("L_E_IMPORT_WRONG_FILE","CHYBA: Text nie je v poriadku. Skontrolujte, ci ste ho správne skopírovali z Exportu.");
define("L_E_IMPORT_WRONG_ID","CHYBA: ");
define("L_E_IMPORT_OVERWRITE", "Prepísat");
define("L_E_IMPORT_IDLENGTH", "Dlka identifikátora musí byt 32 znakov, a nie ");

define("L_E_IMPORT_IDCONFLICT", "Moduly s niektorımi ID u existujú. Zmente ID na pravej strane šípky.<br> "
			."Pouívajte len hexadecimálne znaky 0-9,a-f. "
			."Ak urobíte nieco chybne (chybnı pocet znakov, chybné znaky, alebo zmeníte ID vlavo od šípky), "
			."bude príslušné ID povaované za nezmenené.</p>"
			."Ak zvolíte PREPÍSAT, prepíšu sa všetky šablóny s nezmenenım ID a nové se pridajú. <br>"
			."Ak zvolíte ODOSLAT, šablóny s konfliktom ID sa budoú ignorovat a nové se pridajú.");
define ("L_E_IMPORT_COUNT", "Poèet importovanıch šablón: %d.");			
define ("L_E_IMPORT_ADDED", "Pridané boli:");
define("L_SHOW_RICH", "Zobraz toto pole v rich text editore (pouijte sa po nainštalování potrebnıch komponentov!)");
define("L_MAP_JOIN","-- Spojenie polí --");
define ("L_E_IMPORT_OVERWRITTEN", "Prepísané boli:");

define("L_PARAM_WIZARD_LINK", "Sprievodca s nápovedou");


// aliases used in se_notify.php3
define("L_NOTIFY_SUBJECT", "Predmet e-mailu (Subject)");
define("L_NOTIFY_BODY", "Text e-mailovej správy");
define("L_NOTIFY_EMAILS", "E-mail adresa (jedna na riadok)");
define("L_NOTIFY_HOLDING", "<h4>Nová správa v Zásobníku</h4> Ktokolvek môe byt informovanı o tom, e bola pridaná nová správa do zásobníku. Adresy príjemcov napíšte nišie, do nasledujúcích polí vyplnte, text správy, ktorı uivatelia dostanú emailom.");
define("L_NOTIFY_HOLDING_EDIT", "<h4>Správa v Zásobníku bola zmenená</h4> Ktokolvek môe byt informovanı o tom, e bola zmenená správa v zásobníku. Adresy príjemcov napíšte nišie, do nasledujúcích polí vyplnte, text správy, ktorı uivatelia dostanú emailom.");
define("L_NOTIFY_APPROVED", "<h4>Nová správa medzi Aktuálnymi</h4> Ktokolvek môe byt informovanı o tom, e pribudla nová vystavená správa. Adresy príjemcov napíšte nišie, do nasledujúcích polí vyplnte, text správy, ktorı uivatelia dostanú emailom.");
define("L_NOTIFY_APPROVED_EDIT", "<h4>Aktuálna správa zmenená</h4> Ktokolvek môe byt informovanı o tom, e bola zmenená vystavená správa. Adresy príjemcov napíšte nišie, do nasledujúcích polí vyplnte, text správy, ktorı uivatelia dostanú emailom.");
define("L_NOTIFY", "Upozornenie e-mailom");
define("L_A_NOTIFY_TIT", "E-mailové upozornenia na udalost");
define("L_NOITEM_MSG", "Hláška 'Nenájdená iadna správa'");
define("L_NOITEM_MSG_HLP", "správa, ktorá sa objaví pri nenájdení iadneho odpovedajúceho záznamu");

# ---------------- Users profiles -----------------------------------------
define('L_PROFILE','Profil');
define('L_DEFAULT_USER_PROFILE','Spoloènı profil');
define('L_PROFILE_DELETE_OK','Pravidlo úspešne vymazané');
define('L_PROFILE_ADD_OK','Pravidlo pridané');
define('L_PROFILE_ADD_ERR','Chyba pri pridávaní nového pravidla');
define('L_PROFILE_LISTLEN','Poèet správ');
define('L_PROFILE_ADMIN_SEARCH','Filter správ');
define('L_PROFILE_ADMIN_ORDER','Radenie');
define('L_PROFILE_HIDE','Skryt pole');
define('L_PROFILE_HIDEFILL','Skryt a vyplnit');
define('L_PROFILE_FILL','Vyplnit pole');
define('L_PROFILE_PREDEFINE','Prednastavit pole');
define('L_A_PROFILE_TIT','Správa modulu - Uivatelské profily');
define('L_PROFILE_HDR','Nastavené pravidlá');
define('L_NO_RULE_SET','iadne pravidlo nebolo definované');
define('L_PROFILE_ADD_HDR','Pridat pravidlo');
define('L_PROFILE_LISTLEN_DESC','poèet správ zobrazenıch v administrácii');
define('L_PROFILE_ADMIN_SEARCH_DESC','prednastavenie "Hladanie" v administrácii');
define('L_PROFILE_ADMIN_ORDER_DESC','prednastavenie "Zoradit" v administrácii');
define('L_PROFILE_HIDE_DESC','skryt pole vo vstupnom formulári');
define('L_PROFILE_HIDEFILL_DESC','skryt pole vo vstupnom formulári a vyplnit ho danou hodnotou');
define('L_PROFILE_FILL_DESC','vyplnit pole vo vstupnom formulári vdy danou hodnotou');
define('L_PROFILE_PREDEFINE_DESC','prednastavit hodnotu do pola vo vstupnom formulári');
define('L_VALUE',L_MAP_VALUE2);
define('L_FUNCTION',L_ALIAS_FUNC);
define('L_RULE','Pravidlo');

define('L_ID_COUNT_ALIAS','poèet nalezenıch èlánkù');
define('L_V_NO_ITEM','HTML code for "No item found" message');
define("L_NO_ITEM_FOUND", "Nenalezena ádná zpráva");
define('L_INPUT_SHOW_HCO','Hierachie konstant');

define("L_CONSTANT_HIERARCH_EDITOR","Editovat v Hierarchickém editoru (umoòuje urèit hierarchii hodnot)");
define("L_CONSTANT_PROPAGATE","Propagovat zmìny do stávajících èlánkù");
define("L_CONSTANT_OWNER","Vlastník skupiny - webík");
define("L_A_CONSTANTS_HIER_EDT","Správa webíku - Hiearchické nastavení hodnot");
define("L_CONSTANT_HIER_SORT","Zmìny nebudou uloeny do databáze, dokud nestisknete tlaèítko dole na stránce.<br>Konstanty jsou øazeny zaprvé podle Øazení a zadruhé podle Názvu.");
define("L_CONSTANT_DESC","Popis");
define("L_CONSTANT_HIER_SAVE","Ulo všechny zmìny do databáze");

// constants used in param wizard only:
require  $GLOBALS[AA_INC_PATH]."en_param_wizard_lang.php3";
// new constants to be translated are here. Leave this "require" always at the 
// end of this file
// If You want to translate the new texts (which is in new_news_lang.php3 file),
// just copy them from the new_news_lang.php3 file here and translate.
// IMPORTANT: Leave the require new_news_lang.php3 as the last line of this
// file - it will not redefine the constant You translated and helps You when 
// we add new texts !!!
require  $GLOBALS[AA_INC_PATH]."new_news_lang.php3"; ?>