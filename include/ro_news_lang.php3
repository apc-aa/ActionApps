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

# Translation 1.0  2002/03/08 15:44:20  Mihály Bakó, StrawberryNet Foundation

# config file identifier
# must correspond with this file name
define("LANG_FILE", "ro_news_lang.php3");

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
  <LINK rel=StyleSheet href="'.AA_INSTAL_URL.ADMIN_CSS.'" type="text/css"  title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-2">');
    
# aa toolkit specific labels
define("L_VIEW_SLICE", "Vizualizare sit");
define( "L_SLICE_HINT", '<br>Pentru a include secþiunea în pagina web, includeþi urmãtoarea linie 
                         în codul dvs. shtml: ');
define("L_ITEM_ID_ALIAS",'denumire pentru ID element');
define("L_EDITITEM_ALIAS",'denumire utilizatã în pagina admin index.php3 pentru url editare element');
define("L_LANG_FILE","Fiºier limbã utilizatã");
define("L_PARAMETERS","Parametrii");
define("L_SELECT_APP","Alege aplicaþia");
define("L_SELECT_OWNER","Alege proprietar");

define("L_CANT_UPLOAD","Nu pot încãrca imaginea"); 
define("L_MSG_PAGE", "Mesaj stiri toolkit");   // title of message page
define("L_EDITOR_TITLE", "Fereastrã editor - manager elemente");
define("L_FULLTEXT_FORMAT_TOP", "Cod HTML cap paginã");
define("L_FULLTEXT_FORMAT", "Cod HTML text întreg");
define("L_FULLTEXT_FORMAT_BOTTOM", "Cod HTML sfîrºit paginã");
define("L_A_FULLTEXT_TIT", "Admin - design vedere text întreg");
define("L_FULLTEXT_HDR", "Cod HTML pentru vedere text întreg");
define("L_COMPACT_HDR", "Cod HTML pentru vedere sumar");
define("L_ITEM_HDR", "Articol stiri");
define("L_A_ITEM_ADD", "Adãugare element");
define("L_A_ITEM_EDT", "Editare element");
define("L_IMP_EXPORT", "Admite export cãtre secþiunea:");
define("L_ADD_NEW_ITEM", "Adãugare element");
define("L_DELETE_TRASH", "Goleºte coº");
define("L_VIEW_FULLTEXT", "Vizualizare");
define("L_FULLTEXT", "Text întreg");
define("L_HIGHLIGHTED", "Evidenþiat");
define("L_A_FIELDS_EDT", "Admin - configurare Cîmpuri");
define("L_FIELDS_HDR", "Cîmpuri");
define("L_NO_PS_EDIT_ITEMS", "Nu aveþi dreptul sã editaþi elemente în aceastã secþiune");
define("L_NO_DELETE_ITEMS", "Nu aveþi dreptul sã ºtergeþi elemente");
define("L_NO_PS_MOVE_ITEMS", "Nu aveþi dreptul sã mutaþi elemente");
define("L_FULLTEXT_OK", "Actualizare format text întreg reuºit");
define("L_NO_ITEM", "Nici un element nu corespunde criteriilor de cãutare.");

# aa toolkit common labels
# can be the same for all toolkit aplications
define("L_ACTIVE_BIN", "Activ");
define("L_HOLDING_BIN", "Reþinut");
define("L_TRASH_BIN", "Coº");

define("L_CATEGORY","Categorie");
define("L_SLICE_NAME", "Titlu");          // slice
define("L_DELETED", "ªters");           // slice
define("L_D_LISTLEN", "Lungime text");  // slice
define("L_ERR_CANT_CHANGE", "Nu pot schimba setãrile secþiunii");
define("L_ODD_ROW_FORMAT", "Rînduri impare");
define("L_EVEN_ROW_FORMAT", "Rînduri pare");
define("L_EVEN_ODD_DIFFER", "Foloseºte cod HTML diferit pentru rînduri pare");
define("L_CATEGORY_TOP", "Cap HTML categorie");
define("L_CATEGORY_FORMAT", "Titlu categorie");
define("L_CATEGORY_BOTTOM", "Sfîrºit HTML categorie");
define("L_CATEGORY_SORT", "Sorteazã elementele pe categorii");
define("L_COMPACT_TOP", "Cap HTML");
define("L_COMPACT_BOTTOM", "Sfîrºit HTML");
define("L_A_COMPACT_TIT", "Admin - design vedere sumar");
define("L_A_FILTERS_TIT", "Admin - Transfer conþinut - Filtre");
define("L_FLT_SETTING", "Transfer conþinut - Configurare filtre");
define("L_FLT_FROM_SL", "Filtru pentru secþiune importatã");
define("L_FLT_FROM", "De la");
define("L_FLT_TO", "Cãtre");
define("L_FLT_APPROVED", "Activ");
define("L_FLT_CATEGORIES", "Categorii");
define("L_ALL_CATEGORIES", "Toate categoriile");
define("L_FLT_NONE", "Nu din categoria selectatã!");
define("L_THE_SAME", "-- Acelaºi --");
define("L_EXPORT_TO_ALL", "Admite export cãtre orice secþiune");

define("L_IMP_EXPORT_Y", "Export admis");
define("L_IMP_EXPORT_N", "Export blocat");
define("L_IMP_IMPORT", "Import din secþiunea:");
define("L_IMP_IMPORT_Y", "Importã");
define("L_IMP_IMPORT_N", "Nu importa");
define("L_CONSTANTS_HLP", "Folosiþi aceste denumiri pentru cîmpuri de bazã de date");

define("L_ERR_IN", "Eroare introducere");
define("L_ERR_NEED", "trebuie completat");
define("L_ERR_LOG", "folosiþi caracterele a-z, A-Z ºi 0-9");
define("L_ERR_LOGLEN", "trebuie sã fie de lungimea 5 - 32 caractere");
define("L_ERR_NO_SRCHFLDS", "Cîmp cãutare nespecificat!");

define("L_FIELDS", "Cîmpuri");
define("L_EDIT", "Editare");
define("L_DELETE", "ªtergere");
define("L_REVOKE", "Revocare");
define("L_UPDATE", "Actualizare");
define("L_RESET", "Resetare formular");
define("L_CANCEL", "Anulare");
define("L_ACTION", "Actiune");
define("L_INSERT", "Inserare");
define("L_NEW", "Nou");
define("L_GO", "Dute");
define("L_ADD", "Adaugã");
define("L_USERS", "Utilizatori");
define("L_GROUPS", "Grupuri");
define("L_SEARCH", "Cãutare");
define("L_DEFAULTS", "Implicit");
define("L_SLICE", "Sectiune");
define("L_DELETED_SLICE", "Nu am gãsit nici o secþiune");
define("L_A_NEWUSER", "Utilizator nou in permission system");
define("L_NEWUSER_HDR", "Utilizator nou");
define("L_USER_LOGIN", "Nume utilizator");
define("L_USER_PASSWORD1", "Parola");
define("L_USER_PASSWORD2", "Retastaþi parola");
define("L_USER_FIRSTNAME", "Prenumele");
define("L_USER_SURNAME", "Numele");
define("L_USER_MAIL", "E-mail");
define("L_USER_SUPER", "Cont de superadmin");
define("L_A_USERS_TIT", "Admin - Management utilizatori");
define("L_A_PERMISSIONS", "Admin - Permisiuni");
define("L_A_ADMIN", "Admin - design vedere manager elemente");
define("L_A_ADMIN_TIT", "Admin - design vedere manager elemente");
define("L_ADMIN_FORMAT", "Format element");
define("L_ADMIN_FORMAT_BOTTOM", "Sfîrºit HTML");
define("L_ADMIN_FORMAT_TOP", "Cap HTML");
define("L_ADMIN_HDR", "Lista elemente în interfaþa Admin");
define("L_ADMIN_OK", "Actualizare cîmpuri Admin reuºit");
define("L_ADMIN_REMOVE", "ªterge ºiruri");

define("L_ROLE_AUTHOR", "Autor");
define("L_ROLE_EDITOR", "Editor");
define("L_ROLE_ADMINISTRATOR", "Administrator");
define("L_ROLE_SUPER", "Super");

define("L_MAIN_SET", "Setãri principale");
define("L_PERMISSIONS", "Drepturi");
define("L_PERM_CHANGE", "Schimbã");
define("L_PERM_ASSIGN", "Asigneazã");
define("L_PERM_NEW", "Cautã utilizator sau grup");
define("L_PERM_SEARCH", "Asigneazã drepturi noi");
define("L_PERM_CURRENT", "Schimbã drepturi curente");
define("L_USER_NEW", "Utilizator nou");
define("L_DESIGN", "Design");
define("L_COMPACT", "Sumar");
define("L_COMPACT_REMOVE", "ªterge ºiruri");
define("L_FEEDING", "Transfer conþinut");
define("L_IMPORT", "Parteneri");
define("L_FILTERS", "Filtre");

define("L_A_SLICE_ADD", "Adãugare secþiune");
define("L_A_SLICE_EDT", "Admin - Setãri secþiune");
define("L_A_SLICE_CAT", "Admin - configurare Categorii");
define("L_A_SLICE_IMP", "Admin - configurare Transfer conþinut");
define("L_FIELD", "Cîmp");
define("L_FIELD_IN_EDIT", "Aratã");
define("L_NEEDED_FIELD", "Necesar");
define("L_A_SEARCH_TIT", "Admin - design Paginã cãutare");
define("L_SEARCH_HDR", "Criterii formular cãutare");
define("L_SEARCH_HDR2", "Cãutare în cîmpurile");
define("L_SEARCH_SHOW", "Aratã");
define("L_SEARCH_DEFAULT", "Setãri implicite");
define("L_SEARCH_SET", "Cãutare");
define("L_AND", "ªI");
define("L_OR", "SAU");
define("L_SRCH_KW", "Cautã");
define("L_SRCH_FROM", "De la");
define("L_SRCH_TO", "La");
define("L_SRCH_SUBMIT", "Cautã");
define("L_NO_PS_EDIT", "Nu aveþi dreptul sã editaþi aceastã secþiune");
define("L_NO_PS_ADD", "Nu aveþi dreptul sã adãugaþi secþiune");
define("L_NO_PS_COMPACT", "Nu aveþi dreptul sã schimbaþi formatarea vederii compact");
define("L_NO_PS_FULLTEXT", "Nu aveþi dreptul sã schimbaþi formatarea vederii text întreg");
define("L_NO_PS_CATEGORY", "Nu aveþi dreptul sã schimbaþi setãrile de categorie");
define("L_NO_PS_FEEDING", "Nu aveþi dreptul sã schimbaþi setãrile de transfer");
define("L_NO_PS_USERS", "Nu aveþi dreptul sã administraþi utilizatori");
define("L_NO_PS_FIELDS", "Nu aveþi dreptul sã schimbaþi setãrile de cîmpuri");
define("L_NO_PS_SEARCH", "Nu aveþi dreptul sã schimbaþi setãrile de cãutare");

define("L_BAD_RETYPED_PWD", "Parola reintrodusã este diferitã de prima");
define("L_ERR_USER_ADD", "Adãugarea de utilizatori la sistemul de permisiuni este imposibilã");
define("L_NEWUSER_OK", "Utilizator adãugat cu succes la sistemul de permisiuni");
define("L_COMPACT_OK", "Design al vederii compact schimbat cu succes");
define("L_BAD_ITEM_ID", "ID element greºit");
define("L_ALL", " - toate - ");
define("L_CAT_LIST", "Categorii secþiune");
define("L_CAT_SELECT", "Categorii în aceastã secþiune");
define("L_NEW_SLICE", "Adãugare secþiune");
define("L_ASSIGN", "Asignare");
define("L_CATBINDS_OK", "Actualizare categorie reuºit");
define("L_IMPORT_OK", "Actualizare Transfer Conþinut reuºit");
define("L_FIELDS_OK", "Actualizare cîmpuri reuºit");
define("L_SEARCH_OK", "Actualizare cîmpuri cãutare reuºit");
define("L_NO_CATEGORY", "Nici o categorie definitã");
define("L_NO_IMPORTED_SLICE", "Nu sunt secþiuni importate");
define("L_NO_USERS", "Nu am gãsit nici un utilizator (grup)");

define("L_TOO_MUCH_USERS", "Prea multi utilizatori sau grupuri gãsite.");
define("L_MORE_SPECIFIC", "Fiþi mai specific.");
define("L_REMOVE", "ªterge");
define("L_ID", "Id");
define("L_SETTINGS", "Admin");
define("L_LOGO", "APC Action Applications");
define("L_USER_MANAGEMENT", "Utilizatori");
define("L_ITEMS", "Pagina de administrare elemente");
define("L_NEW_SLICE_HEAD", "Secþiune nouã");
define("L_ERR_USER_CHANGE", "Nu pot schimba utilizatorul");
define("L_PUBLISHED", "Publicat");
define("L_EXPIRED", "Expirat");
define("L_NOT_PUBLISHED", "Nepublicat, încã");
define("L_EDIT_USER", "Editare utilizator");
define("L_EDITUSER_HDR", L_EDIT_USER);

define("NO_SOURCE_URL", "javascript: window.alert('url sursã nespecificat')"); 
define("NO_OUTER_LINK_URL", "javascript: window.alert('url extern nespecificat')");

# editors interface constants
define("L_PUBLISHED_HEAD", "Pub");
define("L_HIGHLIGHTED_HEAD", "&nbsp;!&nbsp;");
define("L_FEEDED_HEAD", "Fed");
define("L_MORE_DETAILS", "Mai multe detalii");
define("L_LESS_DETAILS", "Mai puþine detalii");
define("L_UNSELECT_ALL", "Deselecteazã tot");
define("L_SELECT_VISIBLE", "Selecteazã tot");
define("L_UNSELECT_VISIBLE", "Deselecteazã tot");

define("L_SLICE_ADM", "Administrare secþiune");
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

define("L_FEED", "Exportã");
define("L_FEEDTO_TITLE", "Exportã element cãtre secþiunea selectatã");
define("L_FEED_TO", "Exportã elementele selectate cãtre secþiunea selectatã");
define("L_NO_PERMISSION_TO_FEED", "Nu este permis");
define("L_NO_PS_CONFIG", "Nu aveþi dreptul sã setaþi parametrii de configuraþie pentru aceastã secþiune");
define("L_SLICE_CONFIG", "Administrator Elemente");
define("L_CHBOX_HEAD", "&nbsp;");   // title of checkbox in admin interface
define("L_CHBOX", "Cãsuþã"); 
define("L_CATNAME", "Nume categorie");
define("L_CATEGORY_ID", "ID categorie");
define("L_EDITED_BY","Editat de");
define("L_MASTER_ID", "Master id");
define("L_CHANGE_MARKED", "Elemente selectate");
define("L_MOVE_TO_ACTIVE_BIN", "Mutã în Activ");
define("L_MOVE_TO_HOLDING_BIN", "Mutã în Reþinut");
define("L_MOVE_TO_TRASH_BIN", "Mutã în Coº");
define("L_OTHER_ARTICLES", "Dosare");
define("L_MISC", "Misc");
define("L_HEADLINE_EDIT", "Titlu (click pentru editare)");
define("L_HEADLINE_PREVIEW", "Titlu (click pentru vizualizare)");
define("L_EDIT_LINK", "&nbsp;");
define("L_PREVIEW_LINK", "&nbsp;");
define("L_ARTICLE_MANAGER", "Administrator Elemente");
define("L_SWITCH_TO", "Schimbã la:");
define("L_ADMIN", "Admin");

define("L_NO_PS_NEW_USER", "Nu aveþi dreptul sã creaþi utilizator nou");
define("L_ALL_GROUPS", "Toate grupurile");
define("L_USERS_GROUPS", "Grupurile utilizatorului");
define("L_REALY_DELETE_USER", "Sunteþi sigur cã vreþi sã ºtergeþi utilizatorul selectat din întreg sistemul de permisiuni?");
define("L_REALY_DELETE_GROUP", "Sunteþi sigur cã vreþi sã ºtergeþi grupul selectat din întreg sistemul de permisiuni?");
define("L_TOO_MUCH_GROUPS", "Prea multe grupuri gãsite.");
define("L_NO_GROUPS", "Nici un grup gãsit");
define("L_GROUP_NAME", "Nume");
define("L_GROUP_DESCRIPTION", "Descriere");
define("L_GROUP_SUPER", "Grup superadmin");
define("L_ERR_GROUP_ADD", "Este imposibil de adãugat grupul la sistemul de permisiuni");
define("L_NEWGROUP_OK", "Grup adãugat cu succes la sistemul de permisiuni");
define("L_ERR_GROUP_CHANGE", "Nu pot schimba grupul");
define("L_A_UM_USERS_TIT", "Management utilizatori - Utilizatori");
define("L_A_UM_GROUPS_TIT", "Management utilizatori - Grupuri");
define("L_EDITGROUP_HDR", "Editare grup");
define("L_NEWGROUP_HDR", "Grup nou");
define("L_GROUP_ID", "Id Grup");
define("L_ALL_USERS", "Toþi utilizatorii");
define("L_GROUPS_USERS", "Utilizatorii grupului");
define("L_POST", "Trimite");
define("L_POST_PREV", "Trimite & Vizualizeazã");
define("L_OK", "OK");
define("L_ACTIVE_BIN_EXPIRED", "Expirat");
define("L_ACTIVE_BIN_PENDING", "De rezolvat");
define("L_ACTIVE_BIN_EXPIRED_MENU", "Expirat");
define("L_ACTIVE_BIN_PENDING_MENU", "De rezolvat");

define("L_FIELD_PRIORITY", "Prioritate");
define("L_FIELD_TYPE", "Id");
define("L_CONSTANTS", "Constante");
define("L_DEFAULT", "Implicit");
define("L_DELETE_FIELD", "Chiar vreþi sã ºtergeþi acest cîmp din aceastã secþiune?");
define("L_FEEDED", "Alimentat");
define("L_HTML_DEFAULT", "HTML codat ca implicit");
define("L_HTML_SHOW", "Aratã opþiunea 'HTML' / 'text simplu'");
define("L_NEW_OWNER", "Proprietar nou");
define("L_NEW_OWNER_EMAIL", "E-mail al proprietarului nou");
define("L_NO_FIELDS", "Nici un cîmp definit pentru aceastã secþiune");
define("L_NO_FIELD", "");
define("L_NO_PERM_TO_FEED", "Setarea alimentãrii altor secþiuni nepermis");
define("L_NO_SLICES", "Nu sunt secþiuni");
define("L_NO_TEMPLATES", "Nu sunt ºabloane");
define("L_OWNER", "Proprietar");
define("L_SLICES", "Secþiuni");
define("L_TEMPLATE", "ªabloane");
define("L_VALIDATE", "Valideazã");

define("L_FIELD_DELETE_OK", "ªtergere cîmp reuºit");

define("L_WARNING_NOT_CHANGE","<p>ATENÞIUNE: Nu schimbaþi aceste setãri dacã nu sunteþi siguri în ceea ce faceþi!</p>");
define("L_INPUT_SHOW_FUNC_F_HLP","Funcþii folosite pentru afiºare în formularul de introducere. Unele folosesc Constante, altele folosesc Parametrii. Pentru mai multe informaþii, folosiþi Vrãjitorul cu Ajutor.");
define("L_INPUT_SHOW_FUNC_C_HLP","Alegeþi un Grup de Constante sau o Secþiune.");
define("L_INPUT_SHOW_FUNC_HLP","Parametrii sunt împãrþiþi prin douã puncte (:) sau (în unele cazuri speciale) de apostrof (').");
define("L_INPUT_DEFAULT_F_HLP","Care funcþie sã fie folosit în mod implicit:<BR>Acum - implicit este data curentã<BR>ID utilizator - ID utilizator curent<BR>Text - implicit este text în cîmpul Parametru<br>Data - implicit se utilizeazã data curentã plus <Parametru> numãr de zile");
define("L_INPUT_DEFAULT_HLP","Dacã tipul implicit este Text, acesta seteazã implicit text.<BR>Dacã tipul implicit este Datã, aceasta seteazã data implicit la data curentã plus numãrul de zile setat aici.");

define("L_INPUT_DEFAULT_TXT", "Text");
define("L_INPUT_DEFAULT_DTE", "Datã");
define("L_INPUT_DEFAULT_UID", "ID utilizator");
define("L_INPUT_DEFAULT_NOW", "Acum");

define("L_INPUT_SHOW_TXT","Zonã text");
define("L_INPUT_SHOW_FLD","Cîmp text");
define("L_INPUT_SHOW_SEL","Selecþie");
define("L_INPUT_SHOW_RIO","Buton radio");
define("L_INPUT_SHOW_DTE","Datã");
define("L_INPUT_SHOW_CHB","Cãsuþã");
define("L_INPUT_SHOW_MCH", "Cãsuþe multiple");
define("L_INPUT_SHOW_MSE", "Selecþie multiplã");
define("L_INPUT_SHOW_FIL","Încãrcare fiºier");
define("L_INPUT_SHOW_ISI","Selecþie element înrudit");   # added 08/22/01
define("L_INPUT_SHOW_ISO","Fereastrã element înrudit");       # added 08/22/01
define("L_INPUT_SHOW_WI2","Douã selecþii");                 # added 08/22/01
define("L_INPUT_SHOW_PRE","Selecþie cu predefiniri");   # added 08/22/01
define("L_INPUT_SHOW_NUL","Nu afiºa");
                              
define("L_INPUT_VALIDATE_TEXT","Text");
define("L_INPUT_VALIDATE_URL","URL");
define("L_INPUT_VALIDATE_EMAIL","E-mail");
define("L_INPUT_VALIDATE_NUMBER","Numãr");
define("L_INPUT_VALIDATE_ID","Id");
define("L_INPUT_VALIDATE_DATE","Datã");
define("L_INPUT_VALIDATE_BOOL","Logic");

define("L_INPUT_INSERT_QTE","Text");
define("L_INPUT_INSERT_DTE","Datã");
define("L_INPUT_INSERT_CNS","Constantã");
define("L_INPUT_INSERT_NUM","Numãr");
define("L_INPUT_INSERT_IDS","ID elemente");
define("L_INPUT_INSERT_BOO","Logic");
define("L_INPUT_INSERT_UID","ID utilizator");
define("L_INPUT_INSERT_NOW","Acum");
define("L_INPUT_INSERT_FIL","Fiºier");
define("L_INPUT_INSERT_NUL","Nul");

define("L_INPUT_DEFAULT","Implicit");
define("L_INPUT_BEFORE","Înaintea codului HTML");
define("L_INPUT_BEFORE_HLP","Cod afiºat în formularul de introducere înaintea acestui cîmp");
define("L_INPUT_FUNC","Tip introducere");
define("L_INPUT_HELP","Ajutor pentru acest cîmp");
define("L_INPUT_HELP_HLP","Afiºeazã ajutor pentru acest cîmp");
define("L_INPUT_MOREHLP","Ajutor mai detaliat");
define("L_INPUT_MOREHLP_HLP","Text afiºat dupã ce utilizatorul face click pe '?' în formularul de introducere");
define("L_INPUT_INSERT_HLP","Acesta defineºte cum se stocheazã datele în baza de date.  În general, folosiþi 'Text'.<BR>Fiºier va stoca un fiºier încãrcat.<BR>Acum va insera data currentã, indiferent de ce seteazã utilizatorul.  Uid va insera identitatea utilizatorului curent, indiferent de ce seteazã utilizatorul.  Logic va stoca 1 sau 0. ");
define("L_INPUT_VALIDATE_HLP","Valideazã funcþia");

define("L_CONSTANT_NAME", "Nume");
define("L_CONSTANT_VALUE", "Valoare");
define("L_CONSTANT_PRIORITY", "Prioritate");
define("L_CONSTANT_PRI", "Prioritate");
define("L_CONSTANT_GROUP", "Grup constante");
define("L_CONSTANT_GROUP_EXIST", "Acest grup de constante deja existã");
define("L_CONSTANTS_OK", "Actualizare constante reuºit");
define("L_A_CONSTANTS_TIT", "Admin - Setare constante");
define("L_A_CONSTANTS_EDT", "Admin - Setare constante");
define("L_CONSTANTS_HDR", "Constante");
define("L_CONSTANT_NAME_HLP", "afiºat&nbsp;în&nbsp;pagina&nbsp;de&nbsp;introducere");
define("L_CONSTANT_VALUE_HLP", "stocat&nbsp;în&nbsp;baza&nbsp;de&nbsp;date");
define("L_CONSTANT_PRI_HLP", "ordine&nbsp;constante");
define("L_CONSTANT_CLASS", "Pãrinte");
define("L_CONSTANT_CLASS_HLP", "categorii&nbsp;numai");
define("L_CONSTANT_DEL_HLP", "Înlãturaþi numele constantei pentru a se ºterge");

$L_MONTH = array( 1 => 'Ianuarie', 'Februarie', 'Martie', 'Aprilie', 'Mai', 'Iunie', 
		'Iulie', 'August', 'Septembrie', 'Octombrie', 'Noiembrie', 'Decembrie');

define("L_NO_CATEGORY_FIELD","Nu sunt cîmpuri de categorie definite în aceastã secþiune.<br>Prima datã adãugaþi cîmpuri de categorie la aceastã secþiune (vezi pagina Cîmpuri).");
define("L_PERMIT_ANONYMOUS_POST","Permite trimiterea de elemente în mod anonim");
define("L_PERMIT_OFFLINE_FILL","Permite completarea elementelor în mod off-line");
define("L_SOME_CATEGORY", "<o categorie>");

define("L_ALIASES", "Cînd mergeþi la Admin-Design, folosiþi un Pseudonim pentru afiºarea acestui cîmp");
define("L_ALIAS1", "Pseudonim 1"); 
define("L_ALIAS_HLP", "Trebuie sa înceapã cu _#.<br>Pseudonimul trebuie sã fie exact de zece caractere incluzînd \"_#\".<br>Pseudonimul va conþine litere mari."); 
define("L_ALIAS_FUNC", "Funcþie"); 
define("L_ALIAS_FUNC_F_HLP", "Funcþie care gestioneazã cîmpul din baza de date ºi îl afiºeazã în paginã<BR>uzual, foloseºte 'print'.<BR>"); 
define("L_ALIAS_FUNC_HLP", "Parametru înaintat funcþiei care gestioneazã pseudonimele. Pentru detalii vezi fiºierul include/item.php3"); 
define("L_ALIAS_HELP", "Text de ajutor"); 
define("L_ALIAS_HELP_HLP", "Text de ajutor pentru pseudonime"); 
define("L_ALIAS2", "Pseudonim 2"); 
define("L_ALIAS3", "Pseudonim 3"); 

define("L_TOP_HLP", "Cod HTML care apare în partea de sus a ariei secþiunii");
define("L_FORMAT_HLP", "Puneþi aici codul HTML combinat cu pseudonime, pentru a forma partea de jos a paginii
                     <br>Pseudonimele vor fi înlocuite cu valori reale din baza de date cînd vor fi trimise cãtre paginã");
define("L_BOTTOM_HLP", "Cod HTML care apare în partea de jos a ariei secþiunii");
define("L_EVEN_ROW_HLP", "Puteþi defini cod diferit pentru rînduri pare sau impare
                         <br>primul roºu, al doilea negru, de exemplu");

define("L_SLICE_URL", "URL-ul paginii .shtml (de multe ori se lasã necompletat)");
define( "L_BRACKETS_ERR", "Parantezele nu corespund în formulã: ");
define("L_A_SLICE_ADD_HELP", "Pentru a crea secþiunea nouã, alegeþi un ºablon.
        Secþiunea nouã va moºteni cîmpurile implicite din ºablon.  
        puteþi alege ºi o secþiune non-ºablon ca bazã pentru noua secþiune, 
        dacã are cîmpurile de care aveþi nevoie."); 
define("L_REMOVE_HLP", "Înlãturã paranteze goale etc. Folosiþi ## ca delimitator.");

define("L_COMPACT_HELP", "Utilizaþi aceste spaþii ( cu expresiile afiºate mai jos ) pentru a controla ce apare în pagina sumar");
define("L_A_FULLTEXT_HELP", "Utilizaþi aceste spaþii ( cu expresiile afiºate mai jos ) pentru a controla ce apare în vederea de text întreg");
define("L_PROHIBITED", "Nepermis");
define("L_HTML", "HTML");
define("L_PLAIN_TEXT", "Text simplu");
define("L_A_DELSLICE", "Admin - ªtergere secþiune");
define("L_DELSLICE_TIT", L_A_DELSLICE);
define("L_DELSLICE_HDR", "Alegeþi secþiunea de ºters");
define("L_DEL_SLICE_HLP","<p>Puteþi ºterge numai secþiuni care sunt marcate ca &quot;<b>ºters</b>&quot; în pagina &quot;<b>". L_SLICE_SET ."</b>.</p>");
define("L_A_DELSLICE", L_DELSLICE_TIT);
define("L_DELETE_SLICE", "Doriþi cu adevãrat sã ºtergeþi aceastã secþiune cu toate cîmpurile ºi elementele?");
define("L_NO_SLICE_TO_DELETE", "Nici o secþiune marcatã pentru ºtergere");
define("L_NO_SUCH_SLICE", "id secþiune eronat");
define("L_NO_DELETED_SLICE", "Secþiunea nu este marcatã pentru ºtergere");
define("L_DELSLICE_OK", "Secþiune ºtearsã cu succes, tabelele sunt optimizate");
define("L_DEL_SLICE", "ªterge secþiune");
define("L_FEED_STATE", "Mod alimentare");
define("L_STATE_FEEDABLE", "Alimenteazã" );
define("L_STATE_UNFEEDABLE", "A nu se alimenta" );
define("L_STATE_FEEDNOCHANGE", "Alimentare blocatã" );
define("L_INPUT_FEED_MODES_HLP", "Se poate copia conþinutul acestui cîmp în altã secþiune dacã este alimentat?");
define("L_CANT_CREATE_IMG_DIR","Nu pot crea directorul pentru încãrcare imagini");

  # constants for View setting 
define('L_VIEWS','Vederi');
define('L_ASCENDING','Crescãtor');
define('L_DESCENDING','Descrescãtor');
define('L_NO_PS_VIEWS','Nu aveþi dreptul sã schimbaþi  vederi');
define('L_VIEW_OK','Vedere schimbatã cu succes');
define('L_A_VIEW_TIT','Admin - design Vedere');
define('L_A_VIEWS','Admin - design Vedere');
define('L_VIEWS_HDR','Vederi definite');
define('L_VIEW_DELETE_OK','Vedere ºtearsã cu succes');
define('L_DELETE_VIEW','Sunteþi sigur cã vreþi sã ºtergeþi vederea selectatã?');
define('L_V_BEFORE',L_COMPACT_TOP);
define('L_V_ODD',L_ODD_ROW_FORMAT);
define('L_V_EVENODDDIF',L_EVEN_ODD_DIFFER);
define('L_V_EVEN',L_EVEN_ROW_FORMAT);
define('L_V_AFTER',L_COMPACT_BOTTOM);
define('L_V_GROUP_BY1','Grupat în funcþie de');
define('L_V_GROUP1DIR',' ');
define('L_V_GROUP_BY2',L_V_GROUP_BY1);
define('L_V_GROUP2DIR',' ');
define('L_V_GROUP','Format titlu grup');
define('L_V_REMOVE_STRING',L_COMPACT_REMOVE);
define('L_V_MODIFICATION','Tip');
define('L_V_PARAMETER','Parametru');
define('L_V_IMG1','Aspect Vedere 1');
define('L_V_IMG2','Aspect Vedere 2');
define('L_V_IMG3','Aspect Vedere 3');
define('L_V_IMG4','Aspect Vedere 4');
define('L_V_ORDER1','Sortare primarã');
define('L_V_ORDER1DIR',' ');
define('L_V_ORDER2','Sortare secundarã');
define('L_V_ORDER2DIR',' ');
define('L_V_SELECTED','HTML pentru Selectat');
define('L_V_COND1FLD','Condiþie 1');
define('L_V_COND1OP',' ');
define('L_V_COND1COND',' ');
define('L_V_COND2FLD','Condiþie 2');
define('L_V_COND2OP',' ');
define('L_V_COND2COND',' ');
define('L_V_COND3FLD','Condiþie 3');
define('L_V_COND3OP',' ');
define('L_V_COND3COND',' ');
define('L_V_LISTLEN',L_D_LISTLEN);
define('L_V_FLAG','Semn');
define('L_V_SCROLLER','Afiºeazã scroller de paginã');
define('L_V_ADITIONAL','Adiþional');
define('L_COMPACT_VIEW','Afiºare element');
define('L_FULLTEXT_VIEW','Vedere text întreg');
define('L_DIGEST_VIEW','Extras elemente');
define('L_DISCUSSION_VIEW','Discuþie');
define('L_RELATED_VIEW','Element înrudit');
define('L_CONSTANT_VIEW','Vedere Constante');
define('L_RSS_VIEW','Schimb RSS');
define('L_STATIC_VIEW','Paginã staticã');
define('L_SCRIPT_VIEW','Schimb element Javascript');

define("L_MAP","Mapare");
define("L_MAP_TIT","Admin - Transfer conþinut - Mapare cîmpuri");
define("L_MAP_FIELDS","Mapare cîmpuri");
define("L_MAP_TABTIT","Transfer conþinut - Mapare cîmpuri");
define("L_MAP_FROM_SLICE","Mapare din secþiune");
define("L_MAP_FROM","Din");
define("L_MAP_TO","La");
define("L_MAP_DUP","Nu pot mapa cãtre acelaºi cîmp");
define("L_MAP_NOTMAP","-- Nemapat --");
define("L_MAP_OK","Actualizare mapare cîmpuri reuºit");

define("L_STATE_FEEDABLE_UPDATE", "Alimentare & actualizare" );
define("L_STATE_FEEDABLE_UPDATE_LOCKED", "Alimentare & actualizare & blocare" );

define("L_SITEM_ID_ALIAS",'pseudonim pentru ID scurt de element');
define("L_MAP_VALUE","-- Valoare --");
define("L_MAP_VALUE2","Valoare");
define("L_ORDER", "Ordine");
define("L_INSERT_AS_NEW","Insereazã ca nou");

// Constant view constants
define("L_CONST_NAME_ALIAS", "Nume constantã");
define("L_CONST_VALUE_ALIAS", "Valoare constantã");
define("L_CONST_PRIORITY_ALIAS", "Prioritate constantã");
define("L_CONST_GROUP_ALIAS", "grup id al constantei");
define("L_CONST_CLASS_ALIAS", "Clasã categorie (numai pentru categorii)");
define("L_CONST_COUNTER_ALIAS", "Numãr constante");
define("L_CONST_ID_ALIAS", "id unic al constantei");

define('L_V_CONSTANT_GROUP','Grup constante');
define("L_NO_CONSTANT", "Nu am gãsit constante");

// Discussion constants.
define("L_DISCUS_SEL","Afiºeazã discuþia");
define("L_DISCUS_EMPTY"," -- Vid -- ");
define("L_DISCUS_HTML_FORMAT","Folosiþi tag-uri HTML");
define("L_EDITDISC_ALIAS",'Pseudonim folosit în pagina admin index.php3 pentru editare url discuþii');

define("L_D_SUBJECT_ALIAS","Pseudonim pentru subiectul la comentariu discuþie");
define("L_D_BODY_ALIAS"," Pseudonim pentru textul comentariului la discuþie");
define("L_D_AUTHOR_ALIAS"," Pseudonim pentru scris de");
define("L_D_EMAIL_ALIAS","Pseudonim pentru adresa e-mail al autorului");
define("L_D_WWWURL_ALIAS","Pseudonim pentru adresa url al sitului autorului");
define("L_D_WWWDES_ALIAS","Pseudonim pentru descrierea sitului autorului");
define("L_D_DATE_ALIAS","Pseudonim pentru data publicãrii");
define("L_D_REMOTE_ADDR_ALIAS","Pseudonim al adresei IP a calculatorului autorului");
define("L_D_URLBODY_ALIAS","Pseudonim pentru link la textul comentariului la discuþie<br>
                             <i>Utilizare: </i>în codul HTML pentru vederea sumar al comentariului<br>
                             <i>Exemplu: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>");
define("L_D_CHECKBOX_ALIAS","Pseudonim pentru cãsuþa folositã la alegerea comentariului discuþiei");
define("L_D_TREEIMGS_ALIAS","Pseudonim pentru imagini");
define("L_D_ALL_COUNT_ALIAS","Pseudonim pentru numãrul tuturor comentariilor la element");
define("L_D_APPROVED_COUNT_ALIAS","Pseudonim pentru numãrul comentariilor aprobate la element");
define("L_D_URLREPLY_ALIAS","Pseudonim pentru link la formular<br>
                             <i>Utilizare: </i>în codul HTML pentru vederea de text întreg al comentariului<br>
                             <i>Exemplu: </i>&lt;a href=_#URLREPLY&gt;Rãspuns&lt;/a&gt;");
define("L_D_URL","Pseudonim pentru link la discuþie<br>
                             <i>Utilizare: </i>în codul formularului<br>
                             <i>Exemplu: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">");
define("L_D_ID_ALIAS"," Pseudonim pentru ID element<br>
                             <i>Utilizare: </i>în codul formularului<br>
                             <i>Exemplu: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">");
define("L_D_ITEM_ID_ALIAS"," Pseudonim pentru ID comentariu<br>
                             <i>Utilizare: </i>în codul formularului<br>
                             <i>Exemplu: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">");

define("L_D_BUTTONS","Pseudonim pentru butoane Afiºeazã tot, Afiºeazã selectat, Adaugã nou<br>
                             <i>Utilizare: </i> în codul HTML de la sfîrºit");

define("L_D_COMPACT" , "Cod HTML pentru vederea sumar al comentariului");
define("L_D_SHOWIMGS" , "Afiºeazã imaginile");
define("L_D_ORDER" , "Ordoneazã în funcþie de");
define("L_D_FULLTEXT" ,"Cod HTML pentru vederea text întreg al comentariului");

define("L_D_ADMIN","Management al comentariilor discuþiei");
define("L_D_NODISCUS","Fãrã comentarii discuþie");
define("L_D_TOPIC","Titlu");
define("L_D_AUTHOR","Autor");
define("L_D_DATE","Data");
define("L_D_ACTIONS","Acþiuni");
define("L_D_DELETE","ªterge");
define("L_D_EDIT","Editeazã");
define("L_D_HIDE","Ascunde");
define("L_D_APPROVE","Aprobã");

define("L_D_EDITDISC","Management elemente - Management comentarii discuþie - Editare comentariu");
define("L_D_EDITDISC_TABTIT","Editare comentariu");
define("L_D_SUBJECT","Subiect");
define("L_D_AUTHOR","Autor");
define("L_D_EMAIL","E-mail");
define("L_D_BODY","Text comentariu discuþie");
define("L_D_URL_ADDRESS","WWW - URL al autorilor");
define("L_D_URL_DES","WWW al autorilor - descriere");
define("L_D_HOSTNAME","Adresa IP a calculatorului autorilor");

define("L_D_SELECTED_NONE","Nu a fost selectat nici un comentariu");
define("L_D_DELETE_COMMENT","Sunteþi sigur cã vreþi sã ºtergeþi comentariul selectat?");

define("L_D_FORM","Codul HTML al formularului pentru trimitere comentarii");
define("L_D_ITEM","Element: ");

define("L_D_SHOW_SELECTED","Afiºeazã selectat");
define("L_D_SHOW_ALL","Afiºeazã tot");
define("L_D_ADD_NEW","Adaugã nou");

define("L_TOO_MUCH_RELATED","Sunt prea multe elemente înrudite. Numãrul elementelor înrudite este limitatã.");
define("L_SELECT_RELATED","Selectaþi elementele înrudite");
define("L_SELECT_RELATED_1WAY","Adaugã");
define("L_SELECT_RELATED_2WAY","Adaugã&nbsp;mutual");

define("L_D_BACK","Back");
define("L_D_ADMIN2","Management de comentarii discuþie");

define("L_INNER_IMPORT","Alimentare în cadrul nodului");
define("L_INTER_IMPORT","Import între noduri");
define("L_INTER_EXPORT","Export între noduri");

define("L_NODES_MANAGER","Noduri");
define("L_NO_PS_NODES_MANAGER","Nu aveþi dreptul sã administraþi noduri");
define("L_NODES_ADMIN_TIT","Administrare nod distant");
define("L_NODES_LIST","Noduri distante cunoscute");
define("L_NODES_ADD_NEW","Adãugare nod nou");
define("L_NODES_EDIT","Editare date nod");
define("L_NODES_NODE_NAME","Nume nod");
define("L_NODES_SERVER_URL","URL al getxml.php3");
define("L_NODES_PASWORD","Parola");
define("L_SUBMIT","Trimite");
define("L_NODES_SEL_NONE","Nu este nod selectat");
define("L_NODES_CONFIRM_DELETE","Sunteþi sigur cã vreþi sã ºtergeþi nodul?");
define("L_NODES_NODE_EMPTY","Numele nodului trebuie completat");

define("L_IMPORT_TIT","Setãri import între noduri");
define("L_IMPORT_LIST","Importuri distante existente cãtre secþiune ");
define("L_IMPORT_CONFIRM_DELETE","Sunteþi sigur cã vreþi sã ºtergeþi importul?");
define("L_IMPORT_SEL_NONE","Nu este import selectat");
define("L_IMPORT_NODES_LIST","Toate nodurile distante");
define("L_IMPORT_CREATE","Creeazã o nouã linie de alimentare de la nod");
define("L_IMPORT_NODE_SEL","Nu este nod selectat");
define("L_IMPORT_SLICES","Lista secþiunilor distante");
define("L_IMPORT_SLICES2","Lista secþiunilor accesibile de la nod ");
define("L_IMPORT_SUBMIT","Alege secþiunea");
define("L_IMPORT2_OK","Importul a fost creat cu succes");
define("L_IMPORT2_ERR","Importul a fost creat deja");

define("L_RSS_ERROR","Nu mã pot conecta ºi/sau prelua date de la nodul distant. Contactaþi administratorul nodului local.");
define("L_RSS_ERROR2","Parolã invalidã pentru numele de node:");
define("L_RSS_ERROR3","Contactaþi administratorul nodului local.");
define("L_RSS_ERROR4","Nu sunt secþiuni accesibile. Nu aveþi dreptul sã importaþi date de la acel nod. Contactaþi ".
          "administratorul secþiunii distante ºi verificaþi, dacã el a obþinut numele dvs. utilizator corect.");


define("L_EXPORT_TIT","Setãri export între noduri");
define("L_EXPORT_CONFIRM_DELETE","Sunteþi sigur cã vreþi sã ºtergeþi exportul?");
define("L_EXPORT_SEL_NONE","Nu este export selectat");
define("L_EXPORT_LIST","Exporturi existente al secþiunii ");
define("L_EXPORT_ADD","Insereazã element nou");
define("L_EXPORT_NAME","Nume utilizator");
define("L_EXPORT_NODES","Noduri distante");

define("L_RSS_TITL", "Titlul secþiunii pentru RSS");
define("L_RSS_LINK", "Link la secþiunea pentru RSS");
define("L_RSS_DESC", "Descriere scurtã (proprietar ºi nume) a secþiunii pentru RSS");
define("L_RSS_DATE", "Informaþia datã RSS este generat, în format datã RSS");

define("L_NO_PS_EXPORT_IMPORT", "Nu aveþi dreptul sã exportaþi / importaþi secþiuni");
define("L_EXPORT_SLICE", "Export");
define("L_IMPORT_SLICE", "Import");
define("L_EXPIMP_SET", "Structurã secþiune");

define("L_E_EXPORT_TITLE", "Export structurã secþiune");
define("L_E_EXPORT_MEMO", "Alegeþi una dintre cele douã tipuri de export:");
define("L_E_EXPORT_DESC", "Cînd exportaþi \"cãtre alt ActionApps\" numai secþiunea curentã va fi exportatã "
		."ºi dvs. alegeþi noul ei identificator.");
define("L_E_EXPORT_DESC_BACKUP", "Cînd exportaþi \"cãtre Backup\" puteþi alege mai multe secþiuni dintr-o datã.");
define("L_E_EXPORT_MEMO_ID","Alegeþi un identificator de secþiune nou, de exact 16 caractere: ");
define("L_E_EXPORT_SWITCH", "Exportã la Backup");
define("L_E_EXPORT_SWITCH_BACKUP", "Exportã la alt ActionApps");
define("L_E_EXPORT_IDLENGTH", "Identificatorul trebuie sã fie de lungimea 16 caractere, nu ");
define("L_E_EXPORT_TEXT_LABEL", "Salvaþi acest text. Îl ve-þi putea utiliza sã importaþi secþiunile în orice ActionApps:");
define("L_E_EXPORT_LIST", "Selectaþi secþiunile pe care VREÞI sã le exportaþi:");

define("L_PARAM_WIZARD_LINK", "Vrãjitor cu ajutor");

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