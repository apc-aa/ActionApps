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
define("L_LOGIN", "Pøihlášení");
define("L_LOGIN_TXT", "Vítejte! Pøihlašte se prosím Vaším jménem a heslem:");
define("L_LOGINNAME_TIP", "Uživatelské jméno èi e-mail");
define("L_LOGINORG_TIP", "napø. ecn.cz (prázdné pro pøihlášení uživatelským jménem)");
define("L_SEARCH_TIP", "Seznam je omezen na 5 uživatelù.<br>Pokud v seznamu není požadovaný uživatel, upøesnìte svùj dotaz");
define("L_USERNAME", "Uživatelské jméno:");
define("L_PASSWORD", "Heslo:");
define("L_LOGINNOW", "Pøihlásit se");
define("L_BAD_LOGIN", "Uživatelské jméno èi heslo je neplatné.");
define("L_TRY_AGAIN", "Zkuste to znovu!");
define("L_BAD_HINT", "Pokud urèitì zadáváte správné heslo, kontaktujte <a href=mailto:". ERROR_REPORTING_EMAIL . ">" . ERROR_REPORTING_EMAIL . "</a>.");
define("LOGIN_PAGE_BEGIN",
'<!DOCTYPE html public "-/W3C/DTD HTML 4.0 Transitional/EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'. ADMIN_CSS .'" 
        type="text/css" title="CPAdminCSS">
  <meta http-equiv="Content-Type" content="text/html; charset=windows-1250">');
		
// scroller language constants
define("L_NEXT", "Další");
define("L_PREV", "Pøedchozí");
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
define("L_VIEW_SLICE", "Zobraz webík");
define("L_SLICE_INACCESSIBLE", "Špatné identifikaèní èíslo webíku, nebo byl webík vymazán");
define("L_APP_TYPE", "Typ webíku");
define("L_SELECT_APP", "Zvol typ webíku");
define("L_APP_TYPE_HELP", "<br><br><br><br>");

define( "L_ICON_LEGEND", '
                  <br>
                  <i>Legenda</i>
                  <TABLE BORDER=1>
                  <TR bgcolor="#FFCC66">
                  <TD><img src="../images/hlight.gif" border=0 alt=""> Dùležité</TD>
                  <TD COLSPAN=2><img src="../images/feed.gif" border=0 alt=""> Importováno</TD></TR>
                  <TR>
                  <TD><img src="../images/app.gif" border=0 alt=""> Aktuální</TD><TD>
                  <img src="../images/hold.gif" border=0 alt=""> Zásobník</TD><TD>
                  <img src="../images/trsh.gif" border=0 alt=""> Koš</TD></TR>
                  <TR bgcolor="#FFCC66">
                  <TD><img src="../images/less.gif" border=0 alt=""> Ménì detailù</TD>
                  <TD COLSPAN=2><img src="../images/more.gif" border=0 alt=""> Více detailù</TD></TR>
                  </TABLE>');
                           
define( "L_SLICE_HINT", '
                  <br>
                  Webík zahrnete do své *.shtml stránky pøidáním následující øádky v HTML kódu:
                  ');
                   
// tranformation from english style datum (3/16/1999 or 3/16/99) to timestamp
// break year for short year description is 1970
function userdate2sec ($dttm, $time="") {
  if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $dttm, $part))
    if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $dttm, $part))
      return "";
  if( !ereg("^ *([[:digit:]]{1,2}) *: *([[:digit:]]{1,2}) *: *([[:digit:]]{1,2}) *$", $time, $tpart))
    return mktime(0,0,0,$part[1],$part[2],$part[3]);
   else
    return mktime($tpart[1],$tpart[2],$tpart[3],$part[1],$part[2],$part[3]);
}

function dateExample() {
	return "mm/dd/yyyy";
}

/*
$Log$
Revision 1.13  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.11  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.8  2000/08/23 12:29:57  honzam
fixed security problem with inc parameter to slice.php3

Revision 1.7  2000/08/17 15:17:55  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.6  2000/08/14 12:39:13  kzajicek
Language definitions required by setup.php3

Revision 1.5  2000/07/26 16:01:48  kzajicek
More descriptive message for "login failed"

Revision 1.4  2000/07/12 14:26:40  kzajicek
Poor printing of the SSI statement fixed

Revision 1.3  2000/07/03 15:00:14  honzam
Five table admin interface. 'New slice expiry date bug' fixed.

Revision 1.1.1.1  2000/06/21 18:40:23  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:12  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.6  2000/06/12 19:58:34  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.5  2000/05/30 09:11:39  honzama
MySQL permissions upadted and completed.

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
