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

# common lanmguage file - comm

// setup constats
define("L_SETUP_PAGE_BEGIN", 
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
define("L_SETUP_TITLE", "AA Setup");
define("L_SETUP_H1", "AA Setup");
define("L_SETUP_NO_ACTION", "Tento skript nemôe by pouitı na u nakonfigurovanom systéme.");
define("L_SETUP_INFO1", "Vitajte! Pomocou tohoto skriptu vytvoríte " .
                        "Váš administratívny úèet.<p>" .
      "Ak inštalujete novú kópiu tohoto programu, zvolte <b>Inicializácia</b>.<br>");
define("L_SETUP_INFO2", "Ak ste omylom zmazali Váš administratívny úèet, zvolte <b>Obnovi</b>.<br>");
define("L_SETUP_INIT", "Inicializácia");  
define("L_SETUP_RECOVER", "Obnovi");
define("L_SETUP_TRY_RECOVER", "Nemôem prida primárnı objekt v databáze pristupovıch práv.<br>" .
       "Prosím skontrolujte prístupové práva do databázy prístupovıch práv.<br>" .
       "Ak ste len zmazali Váš administratívny úèet, pouite vo¾bu <b>Obnovi</b>");
define("L_SETUP_USER", "Administratívny úèet");
define("L_SETUP_LOGIN", "Uivate¾ské meno");
define("L_SETUP_PWD1", "Heslo");
define("L_SETUP_PWD2", "Potvrdi heslo");
define("L_SETUP_FNAME", "Meno");
define("L_SETUP_LNAME", "Priezvisko");
define("L_SETUP_EMAIL", "E-mail");
define("L_SETUP_CREATE", "Vytvori");
define("L_SETUP_DELPERM", "Nekonzistentné práva boli zmazané (úèet/skupina neexistuje): ");
define("L_SETUP_ERR_ADDPERM", "Nie je moné priradi administratívne práva.");
define("L_SETUP_ERR_DELPERM", "Nie je moné zmaza nekonzistentné práva.");
define("L_SETUP_OK", "Blahoeláme! Úèet bol vytvorenı.");
define("L_SETUP_NEXT", "prihláste se tımto úètem a vytvorte prvı modul:");
define("L_SETUP_SLICE", "Prida modul");

// loginform language constants
define("L_LOGIN", "Prihlásenie");
define("L_LOGIN_TXT", "Vitajte! Prihláste se prosím Vašim menom a heslem:");
define("L_LOGINNAME_TIP", "Uivate¾ské meno alebo e-mail");
define("L_SEARCH_TIP", "Zoznam je obmedzenı na 5 uivate¾ov.<br>Ak v zozname nie je poadovanı uivate¾, upresnite váše zadanie");
define("L_USERNAME", "Uivate¾ské meno:");
define("L_PASSWORD", "Heslo:");
define("L_LOGINNOW", "Prihlási sa");
define("L_BAD_LOGIN", "Uivate¾ské meno alebo heslo je neplatné.");
define("L_TRY_AGAIN", "Zkúste to opä!");
define("L_BAD_HINT", "Ak urèite zadávate správné heslo, kontaktujte <a href=mailto:". ERROR_REPORTING_EMAIL . ">" . ERROR_REPORTING_EMAIL . "</a>.");
define("LOGIN_PAGE_BEGIN",
'<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'. ADMIN_CSS .'" 
        type="text/css" title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
		
// scroller language constants
define("L_NEXT", "Ïalší");
define("L_PREV", "Predchádzajúci");
define("L_BACK", "Spä");
define("L_HOME", "Domov");

// permission language constants (perm_ldap.php3, perm_all.php3)
define("L_USER", "Uivate¾");
define("L_GROUP", "Skupina");

// permission configuration constants um_uedit
define("L_NEW_USER", "Novı uivate¾");
define("L_NEW_GROUP", "Nová skupina");
define("L_EDIT_GROUP", "Editácia skupiny");

// application not specific strings
define("NO_PICTURE_URL", AA_INSTAL_URL ."images/pixel_blank.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)

define("L_ALLCTGS", "Všetky kategórie");
define("L_BAD_INC", "Zlı parameter inc - soubor môe by v rovnakom adresári, kde je .shtml súbor - smie obsahova iba znaky a èísla");
define("L_NO_SUCH_FILE", "Súbor nenájdenı");
define("L_SELECT_CATEGORY", "Zvo¾ kategóriu ");
define("L_NO_ITEM", "iadna správa");
define("L_SLICE_INACCESSIBLE", "Zlé identifikaèné èíslo modulu, alebo bol modul vymazanı");
define("L_APP_TYPE", "Typ modulu");
define("L_SELECT_APP", "Vyber typ modulu");
define("L_APP_TYPE_HELP", "<br><br><br><br>");

// log texts
define( "LOG_EVENTS_UNDEFINED", "Undefined" );

// offline filling --------------
define( "L_OFFLINE_ERR_BEGIN",
 '<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="./'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">
  </HEAD>
  <BODY>');
define( "L_OFFLINE_OK_BEGIN",L_OFFLINE_ERR_BEGIN);
define( "L_OFFLINE_ERR_END","</body></html>");
define( "L_OFFLINE_OK_END",L_OFFLINE_ERR_END);
define( "L_NO_SLICE_ID","Nebolo zadané èíslo modulu");
define( "L_NO_SUCH_SLICE","Zlé èíslo modulu");
define( "L_OFFLINE_ADMITED","Tento modul má zakázané plnenie off-line");
define( "L_WDDX_DUPLICATED","Bol zaslanı duplicitnı èlánok - nevadí, preskoèenı");
define( "L_WDDX_BAD_PACKET","Chyba v údajoch (WDDX)");
define( "L_WDDX_OK","OK - èlánok vloenı do databázy");
define( "L_CAN_DELETE_WDDX_FILE","Zdá sa, e <b>všetko prebehlo v poriadku</b>, môete 
                                  zmaza súbor z disku.");
define( "L_DELETE_WDDX"," Zmaza ");
                   
// copyright message for all screens
define( "L_COPYRIGHT", 'Copyright (C) 2001 the <a href="http://www.apc.org">Association for Progressive  Communications (APC)</a>'); 

?>
