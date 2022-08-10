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

$_m["new"]
 = "nov�";


# Unused messages
$_m["Cancel"]
 = "Storno";

$_m["Constant unique id"]
 = "Identifika�n� ��slo hodnoty";

$_m[" Alias for text of the discussion comment"]
 = " Alias pro text p��sp�vku";

$_m[" Alias for written by"]
 = " Alias pro autora p��sp�vku";

$_m[" Alias for comment ID<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">"]
 = " Alias pro ��slo �l�nku<br>\n"
   ."                             <i>U�it�: </i>v k�du formul��e<br>\n"
   ."                             <i>P��klad: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">";

$_m[" Alias for item ID<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">"]
 = " Alias pro ��slo p��sp�vku<br>\n"
   ."                             <i>U�it�: </i>v k�du formul��e<br>\n"
   ."                             <i>P��klad: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">";

$_m["Alias pro IP address of author's computer"]
 = "Alias pro IP adresu autorova po��ta�e";

# End of unused messages
// ./slice.php3, row 179
$_m["Bad inc parameter - included file must be in the same directory as this .shtml file and must contain only alphanumeric characters"]
 = "";

// ./slice.php3, row 184
$_m["No such file"]
 = "Soubor nenalezen";

// ./slice.php3, row 213
$_m["Invalid slice number or slice was deleted"]
 = "Web�k s dan�m ��slem nenalezen. Mo�n� �e byl vymaz�n.";

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
// include/view.php, row 593
$_m["No item found"]
 = "Nenalezena ��dn� zpr�va";

// include/slice.php3, row 78
$_m["Select Category "]
 = "Zvol kategorii";

// include/slice.php3, row 80
$_m["All categories"]
 = "V�echny kategorie";

// include/discussion.php, row 206, 253
$_m["Show selected"]
 = "Zobraz vybran�";

// include/discussion.php, row 207, 255
$_m["Show all"]
 = "Zobraz v�e";

// include/discussion.php, row 209, 257
$_m["Add new"]
 = "P�idej nov�";

// admin/se_inputform.php, row 411
// admin/sliceimp.php, row 484
// include/formutil.php, row 202, 2698
$_m["Insert"]
 = "Vlo�it";

// include/discussion.php, row 215
$_m["Alias for subject of the discussion comment"]
 = "Alias pro p�edm�t p��sp�vku";

// include/discussion.php, row 216
$_m["Alias for text of the discussion comment"]
 = "Alias pro text p��sp�vku";

// include/discussion.php, row 217
$_m["Alias for written by"]
 = "Alias pro autora";

// include/discussion.php, row 218
$_m["Alias for author's e-mail"]
 = "Alias pro e-mail autora";

// include/discussion.php, row 219
$_m["Alias for url address of author's www site"]
 = "Alias pro adresu WWW str�nek autora ";

// include/discussion.php, row 220
$_m["Alias for description of author's www site"]
 = "Alias for popis WWW str�nek autora";

// include/discussion.php, row 221
$_m["Alias for publish date"]
 = "Alias pro datum a �as posl�n� p��sp�vku";

// include/discussion.php, row 222
$_m["Alias for IP address of author's computer"]
 = "";

// include/discussion.php, row 223
$_m["Alias for checkbox used for choosing discussion comment"]
 = "Alias pro checkbox pro vybr�n� p��sp�vku";

// include/discussion.php, row 224
$_m["Alias for images"]
 = "Alias pro obr�zky";

// include/discussion.php, row 225
$_m["Alias for comment ID<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#DITEM_ID\">"]
 = "";

// include/discussion.php, row 226
$_m["Alias for comment ID (the same as _#DITEM_ID<br>)\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">"]
 = "";

// include/discussion.php, row 227
$_m["Alias for item ID<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">"]
 = "";

// include/discussion.php, row 228
$_m["Alias for link to text of the discussion comment<br>\n"
   ."                             <i>Usage: </i>in HTML code for index view of the comment<br>\n"
   ."                             <i>Example: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>"]
 = "Alias pro odkaz na text p��sp�vku<br>\n"
   ."                             <i>U�it�: </i>v k�du pro p�ehledov� zobrazen� p��sp�vku<br>\n"
   ."                             <i>P��klad: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>";

// include/discussion.php, row 229
$_m["Alias for link to a form<br>\n"
   ."                             <i>Usage: </i>in HTML code for fulltext view of the comment<br>\n"
   ."                             <i>Example: </i>&lt;a href=_#URLREPLY&gt;Reply&lt;/a&gt;"]
 = "Alias pro odkaz na formul��<br>\n"
   ."                             <i>U�it�: </i>v k�du pro pln� zn�n� p��sp�vku<br>\n"
   ."                             <i>P��klad: </i>&lt;a href=_#URLREPLY&gt;Odpov�d�t&lt;/a&gt;";

// include/discussion.php, row 230
$_m["Alias for link to discussion<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">"]
 = "Alias pro odkaz na diskusi<br>\n"
   ."                             <i>U�it�: </i>v k�du formul��e<br>\n"
   ."                             <i>P��klad: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">";

// include/discussion.php, row 231
$_m["Alias for buttons Show all, Show selected, Add new<br>\n"
   ."                             <i>Usage: </i> in the Bottom HTML code"]
 = "Alias pro tla��tka Zobraz v�e, Zobraz vybran�, P�idej nov�<br>\n"
   ."                             <i>U�it�: </i>ve spodn�m HTML k�du";

// include/discussion.php, row 413
$_m["3rd parameter filled in DiscussionMailList field"]
 = "";

// include/discussion.php, row 415
$_m["%1th parameter filled in DiscussionMailList field"]
 = "";

// include/item.php, row 92
// include/itemview.php, row 108
$_m["number of found items"]
 = "po�et nalezen�ch �l�nk�";

// include/item.php, row 93
$_m["index of item within whole listing (begins with 0)"]
 = "";

// include/item.php, row 94
$_m["index of item within a page (it begins from 0 on each page listed by pagescroller)"]
 = "";

// include/item.php, row 95
$_m["alias for Item ID"]
 = "alias pro ��slo �l�nku";

// include/item.php, row 96
$_m["alias for Short Item ID"]
 = "alias pro zkr�cen� ��slo �l�nku";

// include/item.php, row 102, 103
$_m["alias used on admin page index.php3 for itemedit url"]
 = "alias pou��van� v administrativn�ch str�nk�ch index.php3 pro URL itemedit.php3";

// include/item.php, row 104
$_m["Alias used on admin page index.php3 for edit discussion url"]
 = "Alias pou��van� v administrativn�ch str�nk�ch index.php3 pro URL discedit.php";

// include/item.php, row 105
$_m["Title of Slice for RSS"]
 = "Jm�no web�ku pro RSS";

// include/item.php, row 106
$_m["Link to the Slice for RSS"]
 = "Odkaz na web�k pro RSS";

// include/item.php, row 107
$_m["Short description (owner and name) of slice for RSS"]
 = "Kr�tk� popisek (vlastn�k a jm�no) web�ku pro RSS";

// include/item.php, row 108
$_m["Date RSS information is generated, in RSS date format"]
 = "Datum v RSS p�ehledu je generov�no v datov�m form�tu RSS";

// include/item.php, row 109
$_m["Slice name"]
 = "Jm�no web�ku";

// include/item.php, row 111
$_m["Current MLX language"]
 = "";

// include/item.php, row 112
$_m["HTML markup direction tag (e.g. DIR=RTL)"]
 = "";

// include/item.php, row 140
$_m["Constant name"]
 = "Jm�no";

// include/item.php, row 141
$_m["Constant value"]
 = "Hodnota";

// include/item.php, row 142
$_m["Constant priority"]
 = "�azen�";

// include/item.php, row 143
$_m["Constant group id"]
 = "Skupina hodnot";

// include/item.php, row 144
$_m["Category class (for categories only)"]
 = "Nadkategorie (pou�iteln� jen pro kategorie)";

// include/item.php, row 145
$_m["Constant number"]
 = "Po�adov� ��slo hodnoty";

// include/item.php, row 146
$_m["Constant unique id (32-haxadecimal characters)"]
 = "";

// include/item.php, row 147
$_m["Constant unique short id (autoincremented from '1' for each constant in the system)"]
 = "";

// include/item.php, row 148
$_m["Constant description"]
 = "";

// include/item.php, row 149
$_m["Constant level (used for hierachical constants)"]
 = "";

// include/item.php, row 189
$_m["Alias for %1"]
 = "";

// include/item.php, row 1415
$_m["on"]
 = "";

// include/item.php, row 1415
$_m["off"]
 = "";

// include/item.php, row 1583
$_m["Back"]
 = "Zp�t";

// include/item.php, row 1584
$_m["Home"]
 = "";

// include/scroller.php, row 78
$_m["Pgcnt"]
 = "";

// include/scroller.php, row 79
$_m["Current"]
 = "";

// include/scroller.php, row 80
$_m["Id"]
 = "";

// include/scroller.php, row 81
$_m["Visible"]
 = "";

// include/scroller.php, row 82
$_m["Sortdir"]
 = "";

// include/scroller.php, row 83
$_m["Sortcol"]
 = "";

// include/scroller.php, row 84
$_m["Filters"]
 = "";

// include/scroller.php, row 85
$_m["Itmcnt"]
 = "";

// include/scroller.php, row 86
$_m["Metapage"]
 = "";

// include/scroller.php, row 87
$_m["Urldefault"]
 = "";

// include/scroller.php, row 314
// include/easy_scroller.php3, row 167
$_m["All"]
 = "V�e";

// include/easy_scroller.php3, row 146, 272
$_m["Previous"]
 = "P�edchoz�";

// include/easy_scroller.php3, row 164, 290
$_m["Next"]
 = "Dal��";

// include/itemview.php, row 332
$_m["No comment was selected"]
 = "Nebyl vybr�n ��dn� p��sp�vek";


// include/util.php, row 1878
$_m["January"]
 = "Leden";

// include/util.php, row 1878
$_m["February"]
 = "�nor";

// include/util.php, row 1878
$_m["March"]
 = "B�ezen";

// include/util.php, row 1878
$_m["April"]
 = "Duben";

// include/util.php, row 1878
$_m["May"]
 = "Kv�ten";

// include/util.php, row 1878
$_m["June"]
 = "�erven";

// include/util.php, row 1879
$_m["July"]
 = "�ervenec";

// include/util.php, row 1879
$_m["August"]
 = "Srpen";

// include/util.php, row 1879
$_m["September"]
 = "Z���";

// include/util.php, row 1879
$_m["October"]
 = "��jen";

// include/util.php, row 1879
$_m["November"]
 = "Listopad";

// include/util.php, row 1879
$_m["December"]
 = "Prosinec";

 // include/widget.php3 ...
$_m["Upload"]
 = "Nahr�t";

$_m["SAVE CHANGE"]
 = "Ulo�it zm�nu";

$_m["EXIT WITHOUT CHANGE"]
 = "Zp�t beze zm�ny";

 $_m["Save"]
 = "Ulo�it";

$_m["Current password"]
 = "Aktu�ln� Heslo";

$_m["Password"]
 = "Heslo";

$_m["Retype New Password"]
 = "Nov� heslo (znovu)";

$_m["To save changes click here or outside the field."]
 = "Pro ulo�en� klikn�te sem, nebo kamkoliv vn� edita�n�ho pole";

$_m["Forgot your password? Fill in your email."]
 = "Zapomn�li jste heslo? Vypl�te v� e-mail.";

$_m["Send"]
 = "Odeslat";

$_m["Unable to find user - please check if it has been misspelled."]
 = "Nemohu naj�t u�ivatele - zkontrolujte pros�m, zda nedo�lo k p�eklepu.";

$_m["Password change"]
 = "Zm�na hesla";

$_m["To change the password, please visit the following address:<br>%1<br>Change will be possible for two hours - otherwise the key will expire and you will need to request a new one."]
 = "Pro zad�n� nov�ho hesla pros�m nav�tivte n�sleduj�c� adresu:<br>%1<br>Zm�na bude mo�n� po dobu dvou hodin - jinak tento kl�� vypr�� a budete si muset po��dat o nov�.";

$_m["E-mail with a key to change the password has just been sent to the e-mail address: %1"]
 = "E-mail s kl��em pro zad�n� nov�ho hesla byl pr�v� odesl�n na e-mail: %1";

$_m["Bad or expired key."]
 = "�patn�, �i expirovan� kl��.";

$_m["Fill in the new password"]
 = "Vypl�te nov� heslo";

$_m["New password"]
 = "Nov� heslo";

$_m["Passwords do not match - please try again."]
 = "Hesla si neodpov�daj� - zkuste pros�m je�t� jednou.";

$_m["The password must be at least 6 characters long."]
 = "Heslo mus� b�t nejm�n� 6 znak� dlouh�.";

$_m["Password changed."]
 = "Heslo bylo zm�n�no.";

$_m["An error occurred during password change - please contact: %1."]
 = "Do�lo k chyb� b�hem zm�ny hesla - pros�m kontaktujte %1.";

$_m["Selected"]
 = "Vybran�";

$_m["Delete"]
= " Odstranit ";

$_m["E-mail confirmed."]
= "E-mail potvrzen";

$_m["E-mail unsubscribed"]
= "E-mail odhl�en";

