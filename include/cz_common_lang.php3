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
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
define("L_SETUP_TITLE", "AA Setup");
define("L_SETUP_H1", "AA Setup");
define("L_SETUP_NO_ACTION", "Tento skript nemùže být použit na již nakonfigurovaném systému.");
define("L_SETUP_INFO1", "Vítejte! Pomocí tohoto skriptu vytvoøíte " .
                        "Váš administrativní úèet.<p>" .
      "Pokud instalujete novou kopii tohoto programu, zvolte <b>Inicializace</b>.<br>");
define("L_SETUP_INFO2", "Pokud jste omylem smazali Váš administrativní úèet, zvolte <b>Obnovit</b>.<br>");
define("L_SETUP_INIT", "Inicializace");  
define("L_SETUP_RECOVER", "Obnovit");
define("L_SETUP_TRY_RECOVER", "Nemohu pøidat primární objekt v databázi pøistupových práv.<br>" .
       "Prosím zkontrolujte pøístupová práva do databáze pøistupových práv.<br>" .
       "Pokud jste pouze smazali Váš administrativní úèet, použijte volbu <b>Obnovit</b>");
define("L_SETUP_USER", "Administrativní úèet");
define("L_SETUP_LOGIN", "Uživatelské jméno");
define("L_SETUP_PWD1", "Heslo");
define("L_SETUP_PWD2", "Potvrdit heslo");
define("L_SETUP_FNAME", "Jméno");
define("L_SETUP_LNAME", "Pøíjmení");
define("L_SETUP_EMAIL", "E-mail");
define("L_SETUP_CREATE", "Vytvoøit");
define("L_SETUP_DELPERM", "Nekonzistentní práva byla smazána (úèet/skupina neexistuje): ");
define("L_SETUP_ERR_ADDPERM", "Není možné pøiøadit administrativní práva.");
define("L_SETUP_ERR_DELPERM", "Není možné smazat nekonzistentní práva.");
define("L_SETUP_OK", "Gratulace! Úèet byl vytvoøen.");
define("L_SETUP_NEXT", "Zalogujte se tímto úètem a vytvoøte první webík:");
define("L_SETUP_SLICE", "Pøidat webík");

// loginform language constants
define("L_LOGIN", "Pøihlášení (Login) - <a href='http://www.ecn.cz'>Econnect</a> Toolkit 2.2");
define("L_LOGIN_TXT", "Vítejte! Pøihlašte se prosím Vaším jménem a heslem<br>(Welcome! Log in by your name and password): ");
define("L_LOGINNAME_TIP", "Uživatelské jméno èi e-mail<br>(User name or e-mail)");
define("L_SEARCH_TIP", "Seznam je omezen na 5 uživatelù.<br>Pokud v seznamu není požadovaný uživatel, upøesnìte svùj dotaz");
define("L_USERNAME", "Uživatelské jméno<br>(User name):");
define("L_PASSWORD", "Heslo<br>(Password):");
define("L_LOGINNOW", "Pøihlásit se - Log in");
define("L_BAD_LOGIN", "Uživatelské jméno èi heslo je neplatné. (User name or password is not valid.)");
define("L_TRY_AGAIN", "Zkuste to znovu! (Try it again!)");
define("L_BAD_HINT", "Pokud urèitì zadáváte správné heslo, kontaktujte <a href=mailto:". ERROR_REPORTING_EMAIL . ">" . ERROR_REPORTING_EMAIL . "</a>.
	(If you are sure you use the right password, contact ...)");
define("LOGIN_PAGE_BEGIN",
'<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'. ADMIN_CSS .'" 
        type="text/css" title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
		
// scroller language constants
define("L_NEXT", ">>>");
define("L_PREV", "<<<");
define("L_BACK", "Zpìt");
define("L_HOME", "Domù");

// permission language constants (perm_ldap.php3, perm_all.php3)
define("L_USER", "Uživatel");
define("L_GROUP", "Skupina");

// permission configuration constants um_uedit
define("L_NEW_USER", "Nový uživatel");
define("L_NEW_GROUP", "Nová skupina");
define("L_EDIT_GROUP", "Editace Skupiny");

// application not specific strings
define("NO_PICTURE_URL", AA_INSTAL_URL ."images/pixel_blank.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)

define("L_ALLCTGS", "Všechny kategorie");
define("L_BAD_INC", "Špatný parametr inc - soubor mùže být v tomtéž adresáøi, kde je .shtml soubor - smi obsahovat jen znaky a cisla");
define("L_NO_SUCH_FILE", "Soubor nenalezen");
define("L_SELECT_CATEGORY", "Zvol Kategorii ");
define("L_NO_ITEM", "Nenalezena žádná zpráva");
define("L_SLICE_INACCESSIBLE", "Špatné identifikaèní èíslo webíku, nebo byl webík vymazán");
define("L_APP_TYPE", "Typ webíku");
define("L_SELECT_APP", "Zvol typ webíku");
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
define( "L_NO_SLICE_ID","Nebylo zadáno èíslo webíku");
define( "L_NO_SUCH_SLICE","Špatné èíslo webíku");
define( "L_OFFLINE_ADMITED","Tento webík má zakázáno plnìní off-line");
define( "L_WDDX_DUPLICATED","Byl zaslán duplicitní èlánek - nevadí, pøeskoèen");
define( "L_WDDX_BAD_PACKET","Chba v datech (WDDX)");
define( "L_WDDX_OK","OK - èlánek vložen do databáze");
define( "L_CAN_DELETE_WDDX_FILE","Zdá se že <b>vše probìhlo v poøádku</b>, mùžete klidnì 
                                  smazat soubor z disku.");
define( "L_DELETE_WDDX"," Smazat ");

// copyright message for all screens
define( "L_COPYRIGHT", 'Copyright (C) 2001 the 
						<a href="http://www.apc.org">Association for Progressive  Communications (APC)</a>'); 
                   
define("DEFAULT_CODEPAGE","windows-1250");

?>
