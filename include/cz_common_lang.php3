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
define("L_ALLCTGS", "Všechny kategorie");
define("L_SELECT_CATEGORY", "Zvol Kategorii ");
define("L_NO_ITEM", "Nenalezena žádná zpráva");
define("L_VIEW_SLICE", "Zobraz webík");
define("L_SLICE_INACCESSIBLE", "Špatné identifikaèní èíslo webíku, nebo byl webík vymazán");
define("L_APP_TYPE", "Typ webíku");
define("L_SELECT_APP", "Zvol typ webíku");
define("L_APP_TYPE_HELP", "<small><br><br><br><br> Vytvoøení nového typu webíku je jednoduché:<br><ul>
                           <li>vytvoøte nový jazykový soubor (see en_news_lang.php3)
                           <li>pøidejte do config.php3 následující øádky (samozøejmì upravené):<br>
                            &nbsp; \$ActionAppConfig[en_news][name] = \"Novinky\";<br>
                            &nbsp; \$ActionAppConfig[en_news][file] = \"en_news_lang.php3\";
                           </ul></small>");

define( "L_ICON_LEGEND", '
                  <br>
                  <i>Legenda</i>
                  <TABLE BORDER=1>
                  <TR>
                  <TD><img src="../images/notpubl.gif" width=24 height=24 border=0 alt=""> Dosud nepublikováno</TD>
                  <TD><img src="../images/publish.gif" width=24 height=24 border=0 alt=""> Publikovaná zpráva</TD>
                  <TD><img src="../images/expired.gif" width=24 height=24 border=0 alt=""> Expirovaná zpráva</TD>
                  </TR>
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
                  </TABLE>
                  Kategorie zobrazené èervenì nejsou souèástí tohoto webíku (èlánek byl zøejmì importován z jiného webíku)<br><br>
                  Webík zahrnete do své *.shtml stránky pøidáním následujícího pøíkazu do HTML kódu:<br><code>&lt;!--#include virtual=&quot;/aa/slice.php3?slice_id='. $slice_id .'&quot;--&gt;
                  </code>');

                   
function datetime2date ($dttm) {
	return ereg_replace("^([[:digit:]]{4})-([[:digit:]]{2})-([[:digit:]]{2}).*", 
		"\\2/\\3/\\1", $dttm);
}

// tranformation from english style datum (3/16/1999 or 3/16/99) to mySQL date
// break year for short year description is 1950
function date2datetime ($dttm) {
  if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $dttm, $part))
    if( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $dttm, $part))
      return "";
     else
      $part[3] = ($part[3]<50 ? "20".$part[3] : "19".$part[3]);
	return $part[3] . "-" . $part[1] . "-" . $part[2];
}

function dateExample() {
	return "mm/dd/yyyy";
}

/*
$Log$
Revision 1.2  2000/07/01 07:03:13  kzajicek
fixed typo

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
