<?php
// $Id$
// Language: CZ
// This file was created automatically by the Mini GetText environment
// on 5.9.2008 16:31

// Do not change this file otherwise than by typing translations on the right of =

// Before each message there are links to program code where it was used.

$mgettext_lang = "cz";
setlocale(LC_ALL, 'cs_CZ');      // sort, date, uppercase, ..
setlocale(LC_NUMERIC, 'en_US');  // use numeric with dot - there is problem, when
                                 // used Czech numeric comma for example in AA_Stringexpand_If:
                                 //   $cmp  = create_function('$b', "return ($etalon $operator". ' $b);');
                                 // float!! value $etalon is then with comma which leads to syntax error

# Unused messages
$_m["Cancel"]
 = "Storno";

$_m["Constant unique id"]
 = "Identifikaèní èíslo hodnoty";

$_m[" Alias for text of the discussion comment"]
 = " Alias pro text pøíspìvku";

$_m[" Alias for written by"]
 = " Alias pro autora pøíspìvku";

$_m[" Alias for comment ID<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">"]
 = " Alias pro èíslo èlánku<br>\n"
   ."                             <i>Užití: </i>v kódu formuláøe<br>\n"
   ."                             <i>Pøíklad: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">";

$_m[" Alias for item ID<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">"]
 = " Alias pro èíslo pøíspìvku<br>\n"
   ."                             <i>Užití: </i>v kódu formuláøe<br>\n"
   ."                             <i>Pøíklad: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">";

$_m["Alias pro IP address of author's computer"]
 = "Alias pro IP adresu autorova poèítaèe";

# End of unused messages
// ./slice.php3, row 179
$_m["Bad inc parameter - included file must be in the same directory as this .shtml file and must contain only alphanumeric characters"]
 = "";

// ./slice.php3, row 184
$_m["No such file"]
 = "Soubor nenalezen";

// ./slice.php3, row 213
$_m["Invalid slice number or slice was deleted"]
 = "Webík s daným èíslem nenalezen. Možná že byl vymazán.";

// ./slice.php3, row 271
$_m["session id"]
 = "";

// ./slice.php3, row 378
$_m["number of current page (on pagescroller)"]
 = "";

// ./slice.php3, row 379
$_m["page length (number of items)"]
 = "";

// ./slice.php3, row 569
// include/view.php3, row 593
$_m["No item found"]
 = "Nenalezena žádná zpráva";

// include/slice.php3, row 78
$_m["Select Category "]
 = "Zvol kategorii";

// include/slice.php3, row 80
$_m["All categories"]
 = "Všechny kategorie";

// include/discussion.php3, row 206, 253
$_m["Show selected"]
 = "Zobraz vybrané";

// include/discussion.php3, row 207, 255
$_m["Show all"]
 = "Zobraz vše";

// include/discussion.php3, row 209, 257
$_m["Add new"]
 = "Pøidej nový";

// include/discussion.php3, row 215
$_m["Alias for subject of the discussion comment"]
 = "Alias pro pøedmìt pøíspìvku";

// include/discussion.php3, row 216
$_m["Alias for text of the discussion comment"]
 = "Alias pro text pøíspìvku";

// include/discussion.php3, row 217
$_m["Alias for written by"]
 = "Alias pro autora";

// include/discussion.php3, row 218
$_m["Alias for author's e-mail"]
 = "Alias pro e-mail autora";

// include/discussion.php3, row 219
$_m["Alias for url address of author's www site"]
 = "Alias pro adresu WWW stránek autora ";

// include/discussion.php3, row 220
$_m["Alias for description of author's www site"]
 = "Alias for popis WWW stránek autora";

// include/discussion.php3, row 221
$_m["Alias for publish date"]
 = "Alias pro datum a èas poslání pøíspìvku";

// include/discussion.php3, row 222
$_m["Alias for IP address of author's computer"]
 = "";

// include/discussion.php3, row 223
$_m["Alias for checkbox used for choosing discussion comment"]
 = "Alias pro checkbox pro vybrání pøíspìvku";

// include/discussion.php3, row 224
$_m["Alias for images"]
 = "Alias pro obrázky";

// include/discussion.php3, row 225
$_m["Alias for comment ID<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#DITEM_ID\">"]
 = "";

// include/discussion.php3, row 226
$_m["Alias for comment ID (the same as _#DITEM_ID<br>)\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">"]
 = "";

// include/discussion.php3, row 227
$_m["Alias for item ID<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">"]
 = "";

// include/discussion.php3, row 228
$_m["Alias for link to text of the discussion comment<br>\n"
   ."                             <i>Usage: </i>in HTML code for index view of the comment<br>\n"
   ."                             <i>Example: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>"]
 = "Alias pro odkaz na text pøíspìvku<br>\n"
   ."                             <i>Užití: </i>v kódu pro pøehledové zobrazení pøíspìvku<br>\n"
   ."                             <i>Pøíklad: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>";

// include/discussion.php3, row 229
$_m["Alias for link to a form<br>\n"
   ."                             <i>Usage: </i>in HTML code for fulltext view of the comment<br>\n"
   ."                             <i>Example: </i>&lt;a href=_#URLREPLY&gt;Reply&lt;/a&gt;"]
 = "Alias pro odkaz na formuláø<br>\n"
   ."                             <i>Užití: </i>v kódu pro plné znìní pøíspìvku<br>\n"
   ."                             <i>Pøíklad: </i>&lt;a href=_#URLREPLY&gt;Odpovìdìt&lt;/a&gt;";

// include/discussion.php3, row 230
$_m["Alias for link to discussion<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">"]
 = "Alias pro odkaz na diskusi<br>\n"
   ."                             <i>Užití: </i>v kódu formuláøe<br>\n"
   ."                             <i>Pøíklad: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">";

// include/discussion.php3, row 231
$_m["Alias for buttons Show all, Show selected, Add new<br>\n"
   ."                             <i>Usage: </i> in the Bottom HTML code"]
 = "Alias pro tlaèítka Zobraz vše, Zobraz vybrané, Pøidej nový<br>\n"
   ."                             <i>Užití: </i>ve spodním HTML kódu";

// include/discussion.php3, row 413
$_m["3rd parameter filled in DiscussionMailList field"]
 = "";

// include/discussion.php3, row 415
$_m["%1th parameter filled in DiscussionMailList field"]
 = "";

// include/item.php3, row 92
// include/itemview.php3, row 108
$_m["number of found items"]
 = "poèet nalezených èlánkù";

// include/item.php3, row 93
$_m["index of item within whole listing (begins with 0)"]
 = "";

// include/item.php3, row 94
$_m["index of item within a page (it begins from 0 on each page listed by pagescroller)"]
 = "";

// include/item.php3, row 95
$_m["alias for Item ID"]
 = "alias pro èíslo èlánku";

// include/item.php3, row 96
$_m["alias for Short Item ID"]
 = "alias pro zkrácené èíslo èlánku";

// include/item.php3, row 102, 103
$_m["alias used on admin page index.php3 for itemedit url"]
 = "alias používaný v administrativních stránkách index.php3 pro URL itemedit.php3";

// include/item.php3, row 104
$_m["Alias used on admin page index.php3 for edit discussion url"]
 = "Alias používaný v administrativních stránkách index.php3 pro URL discedit.php3";

// include/item.php3, row 105
$_m["Title of Slice for RSS"]
 = "Jméno webíku pro RSS";

// include/item.php3, row 106
$_m["Link to the Slice for RSS"]
 = "Odkaz na webík pro RSS";

// include/item.php3, row 107
$_m["Short description (owner and name) of slice for RSS"]
 = "Krátký popisek (vlastník a jméno) webíku pro RSS";

// include/item.php3, row 108
$_m["Date RSS information is generated, in RSS date format"]
 = "Datum v RSS pøehledu je generováno v datovém formátu RSS";

// include/item.php3, row 109
$_m["Slice name"]
 = "Jméno webíku";

// include/item.php3, row 111
$_m["Current MLX language"]
 = "";

// include/item.php3, row 112
$_m["HTML markup direction tag (e.g. DIR=RTL)"]
 = "";

// include/item.php3, row 140
$_m["Constant name"]
 = "Jméno";

// include/item.php3, row 141
$_m["Constant value"]
 = "Hodnota";

// include/item.php3, row 142
$_m["Constant priority"]
 = "Øazení";

// include/item.php3, row 143
$_m["Constant group id"]
 = "Skupina hodnot";

// include/item.php3, row 144
$_m["Category class (for categories only)"]
 = "Nadkategorie (použitelné jen pro kategorie)";

// include/item.php3, row 145
$_m["Constant number"]
 = "Poøadové èíslo hodnoty";

// include/item.php3, row 146
$_m["Constant unique id (32-haxadecimal characters)"]
 = "";

// include/item.php3, row 147
$_m["Constant unique short id (autoincremented from '1' for each constant in the system)"]
 = "";

// include/item.php3, row 148
$_m["Constant description"]
 = "";

// include/item.php3, row 149
$_m["Constant level (used for hierachical constants)"]
 = "";

// include/item.php3, row 189
$_m["Alias for %1"]
 = "";

// include/item.php3, row 1415
$_m["on"]
 = "";

// include/item.php3, row 1415
$_m["off"]
 = "";

// include/item.php3, row 1583
$_m["Back"]
 = "Zpìt";

// include/item.php3, row 1584
$_m["Home"]
 = "";

// include/scroller.php3, row 78
$_m["Pgcnt"]
 = "";

// include/scroller.php3, row 79
$_m["Current"]
 = "";

// include/scroller.php3, row 80
$_m["Id"]
 = "";

// include/scroller.php3, row 81
$_m["Visible"]
 = "";

// include/scroller.php3, row 82
$_m["Sortdir"]
 = "";

// include/scroller.php3, row 83
$_m["Sortcol"]
 = "";

// include/scroller.php3, row 84
$_m["Filters"]
 = "";

// include/scroller.php3, row 85
$_m["Itmcnt"]
 = "";

// include/scroller.php3, row 86
$_m["Metapage"]
 = "";

// include/scroller.php3, row 87
$_m["Urldefault"]
 = "";

// include/scroller.php3, row 314
// include/easy_scroller.php3, row 167
$_m["All"]
 = "Vše";

// include/easy_scroller.php3, row 146, 272
$_m["Previous"]
 = "Pøedchozí";

// include/easy_scroller.php3, row 164, 290
$_m["Next"]
 = "Další";

// include/itemview.php3, row 332
$_m["No comment was selected"]
 = "Nebyl vybrán žádný pøíspìvek";


// include/util.php3, row 1878
$_m["January"]
 = "Leden";

// include/util.php3, row 1878
$_m["February"]
 = "Únor";

// include/util.php3, row 1878
$_m["March"]
 = "Bøezen";

// include/util.php3, row 1878
$_m["April"]
 = "Duben";

// include/util.php3, row 1878
$_m["May"]
 = "Kvìten";

// include/util.php3, row 1878
$_m["June"]
 = "Èerven";

// include/util.php3, row 1879
$_m["July"]
 = "Èervenec";

// include/util.php3, row 1879
$_m["August"]
 = "Srpen";

// include/util.php3, row 1879
$_m["September"]
 = "Záøí";

// include/util.php3, row 1879
$_m["October"]
 = "Øíjen";

// include/util.php3, row 1879
$_m["November"]
 = "Listopad";

// include/util.php3, row 1879
$_m["December"]
 = "Prosinec";

 // include/widget.php3 ...
$_m["Upload"]
 = "Nahrát";

$_m["SAVE CHANGE"]
 = "Uložit zmìnu";

$_m["EXIT WITHOUT CHANGE"]
 = "Zpìt beze zmìny";

 $_m["Save"]
 = "Uložit";

$_m["Current password"]
 = "Aktuální Heslo";

$_m["Password"]
 = "Heslo";

$_m["Retype New Password"]
 = "Nové heslo (znovu)";

$_m["To save changes click here or outside the field."]
 = "Pro uložení kliknìte sem, nebo kamkoliv vnì editaèního pole";

$_m["Forgot your password? Fill in your email."]
 = "Zapomnìli jste heslo? Vyplòte váš e-mail.";

$_m["Send"]
 = "Odeslat";

$_m["Unable to find user - please check if it has been misspelled."]
 = "Nemohu najít uživatele - zkontrolujte prosím, zda nedošlo k pøeklepu.";

$_m["Password change"]
 = "Zmìna hesla";

$_m["To change the password, please visit the following address:<br>%1<br>Change will be possible for two hours - otherwise the key will expire and you will need to request a new one."]
 = "Pro zmenu hesla prosim navstivte nasledujici adresu:<br>%1<br>Zmena bude mozna po dobu dvou hodin - jinak tento klic vyprsi a budete si muset pozadat o novy.";

$_m["E-mail with a key to change the password has just been sent to the e-mail address: %1"]
 = "E-mail s klíèem pro zmìnu hesla byl právì odeslán na e-mail: %1";

$_m["Bad or expired key."]
 = "Špatný, èi expirovaný klíè.";

$_m["Fill in the new password:"]
 = "Vyplòte nové heslo:";

$_m["New password"]
 = "Nové heslo";

$_m["Passwords do not match - please try again."]
 = "Hesla si neodpovídají - zkuste prosím ještì jednou.";

$_m["The password must be at least 6 characters long."]
 = "Heslo musí být nejménì 6 znakù dlouhé.";

$_m["Password changed."]
 = "Heslo bylo zmìnìno.";

$_m["An error occurred during password change - please contact: %1."]
 = "Došlo k chybì bìhem zmìny hesla - prosím kontaktujte %1.";

?>
