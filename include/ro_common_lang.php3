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

# common language file

// setup constats
define("L_SETUP_PAGE_BEGIN", 
 '<!DOCTYPE html public "-//W3C//DTD HTML 4.0 Transitional//EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-2">');
define("L_SETUP_TITLE", "Setare AA");
define("L_SETUP_H1", "Setare AA");
define("L_SETUP_NO_ACTION", "Acest script nu se poate aplica unui sistem configurat.");
define("L_SETUP_INFO1", "Bine aþi venit! Cu acest program veþi crea " .
                        "contul de superadmin.<p>" .
      "Dacã instalaþi o nouã copie al AA, apãsaþi <b>Iniþializare</b>.<br>");
define("L_SETUP_INFO2", "Dacã aþi ºters din greºealã contul de superadmin, apãsaþi <b>Refacere</b>.<br>");
define("L_SETUP_INIT", " Iniþializare ");  
define("L_SETUP_RECOVER", "Refacere");
define("L_SETUP_TRY_RECOVER", "Nu pot adãuga obiectul primar de permisiuni.<br>" .
       "Vã rog verificaþi setãrile de acces al sistemului dvs. de permisiuni.<br>" .
       "Dacã v-aþi ºters contul de superadmin, folosiþi <b>Refacere</b>");
define("L_SETUP_USER", "Cont de superadmin");
define("L_SETUP_LOGIN", "Nume utilizator");
define("L_SETUP_PWD1", "Parolã");
define("L_SETUP_PWD2", "Parola încã o datã");
define("L_SETUP_FNAME", "Prenumele");
define("L_SETUP_LNAME", "Numele");
define("L_SETUP_EMAIL", "E-mail");
define("L_SETUP_CREATE", "Creeazã");
define("L_SETUP_DELPERM", "Permisiune invalidã ºtearsã (utilizator/grup inexistent): ");
define("L_SETUP_ERR_ADDPERM", "Nu pot asigna permisiune de super acces.");
define("L_SETUP_ERR_DELPERM", "Nu pot ºterge permisiune invalidã.");
define("L_SETUP_OK", "Felicitãri! Contul a fost creat.");
define("L_SETUP_NEXT", "Folosiþi acest cont pentru intrare ºi adãugarea primei secþiuni:");
define("L_SETUP_SLICE", "Adãugare secþiune");

// loginform language constants
define("L_LOGIN", "Bine aþi venit!");
define("L_LOGIN_TXT", "Bine aþi venit! Vã rog sã vã identificaþi printr-un nume utilizator ºi o parolã:");
define("L_LOGINNAME_TIP", "Tastaþi-vã numele utilizator sau adresa de e-mail");
define("L_SEARCH_TIP", "Lista este limitatã la 5 utilizatori.<br>Dacã un utilizator nu apare în listã, folosiþi termeni de cãutare mai specifici");
define("L_USERNAME", "Nume utilizator:");
define("L_PASSWORD", "Parolã:");
define("L_LOGINNOW", "Intrã acum");
define("L_BAD_LOGIN", "Numele utilizator sau parola nu este validã.");
define("L_TRY_AGAIN", "Vã rog încercaþi din nou!");
define("L_BAD_HINT", "Dacã sunteþi siguri cã aþi introdus parola corectã, trimiteþi e-mail la <a href=mailto:". ERROR_REPORTING_EMAIL . ">" . ERROR_REPORTING_EMAIL . "</a>.");
define("LOGIN_PAGE_BEGIN",
 '<!DOCTYPE html public "-//W3C//DTD HTML 4.0 Transitional//EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="../'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-2">');
		
// scroller language constants
define("L_NEXT", "Urmãtor");
define("L_PREV", "Precedent");
define("L_BACK", "Înapoi");
define("L_HOME", "Prima");

// permission language constants (perm_ldap.php3, perm_all.php3)
define("L_USER", "Utilizator");
define("L_GROUP", "Grup");

// permission configuration constants um_uedit
define("L_NEW_USER", "Utilizator nou");
define("L_NEW_GROUP", "Grup nou");
define("L_EDIT_GROUP", "Editare grup");

// application not specific strings
define("NO_PICTURE_URL", AA_INSTAL_URL ."images/pixel_blank.gif");  // image used when 
  // there is img_source in html format string but no img_source is stored in database 
  // (you can use blank pixel for none picture)

define("L_ALLCTGS", "Toate categoriile");
define("L_NO_SUCH_FILE", "Fiºier inexistent");
define("L_BAD_INC", "Paramentru inc greºit - fiºierul inclus trebuie sã fie în acelaºi catalog cu acest fiºier .shtml ºi trebuie sã conþinã numai caractere alfanumerice");
define("L_SELECT_CATEGORY", "Selectaþi categoria ");
define("L_NO_ITEM", "Nu am gãsit nici o pozitie");
define("L_SLICE_INACCESSIBLE", "Numãr secþiune invalidã sau secþiunea a fost ºtearsã");
define("L_APP_TYPE", "Tip secþiune");
define("L_SELECT_APP", "Alegeþi tipul secþiunii");
define("L_APP_TYPE_HELP", "<br><br><br><br>");

// log texts
define( "LOG_EVENTS_UNDEFINED", "Nedefinit" );

// offline filling --------------
define( "L_OFFLINE_ERR_BEGIN",
 '<!DOCTYPE html public "-//W3C//DTD HTML 4.0 Transitional//EN">
  <HTML>
  <HEAD>
  <LINK rel=StyleSheet href="./'.ADMIN_CSS.'" type="text/css">
  <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-2">
  </HEAD>
  <BODY>');
define( "L_OFFLINE_OK_BEGIN",L_OFFLINE_ERR_BEGIN);
define( "L_OFFLINE_ERR_END","</body></html>");
define( "L_OFFLINE_OK_END",L_OFFLINE_ERR_END);
define( "L_NO_SLICE_ID","ID al secþiunii nedefinit");
define( "L_NO_SUCH_SLICE","ID secþiune greºit");
define( "L_OFFLINE_ADMITED","Nu aveþi permisiunea de a completa aceastã secþiune off-line");
define( "L_WDDX_DUPLICATED","Date duplicate - sãrit");
define( "L_WDDX_BAD_PACKET","Data eronatã (pachet WDDX)");
define( "L_WDDX_OK","Item OK - stocat în baza de date");
define( "L_CAN_DELETE_WDDX_FILE","Acum puteþi ºterge fiºierul local. ");
define( "L_DELETE_WDDX"," ªterge ");

// copyright message for all screens
define( "L_COPYRIGHT", 'Copyright (C) 2001 the 
						<a href="http://www.apc.org">Association for Progressive  Communications (APC)</a> 
						under the 
						<a href="http://www.gnu.org/copyleft/gpl.html">GNU General Public License</a>'); 

define("DEFAULT_CODEPAGE","iso-8859-2");

# ------------------- New constants (not in other lang files ------------------

/*
Translation 1.0  2002/03/08 15:44:20  Mihály Bakó, StrawberryNet Foundation
*/
?>

