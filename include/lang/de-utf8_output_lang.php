<?php
// $Id$
// Language: DE
// This file was created automatically by the Mini GetText environment
// on 5.9.2008 16:31

// Do not change this file otherwise than by typing translations on the right of =

// Before each message there are links to program code where it was used.

$mgettext_lang = "de-utef8";
define("DB_COLLATION", "utf8mb4_german2_ci");
setlocale(LC_ALL, 'de_DE.utf8', 'de_DE', 'de', 'ge');  // sort, date, uppercase, ..
setlocale(LC_NUMERIC, 'en_US');            // use numeric with dot - there is problem, when
                                           // used Czech numeric comma for example in AA_Stringexpand_If:
                                           //   $cmp  = create_function('$b', "return ($etalon $operator". ' $b);');
                                           // float!! value $etalon is then with comma which leads to syntax error

# Unused messages
# End of unused messages
// ./slice.php3, row 179
$_m["Bad inc parameter - included file must be in the same directory as this .shtml file and must contain only alphanumeric characters"]
 = "";

// ./slice.php3, row 184
$_m["No such file"]
 = "";

// ./slice.php3, row 213
$_m["Invalid slice number or slice was deleted"]
 = "";

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
 = "";

// include/slice.php3, row 78
$_m["Select Category "]
 = "";

// include/slice.php3, row 80
$_m["All categories"]
 = "";

// include/discussion.php, row 206, 253
$_m["Show selected"]
 = "Zeige ausgewählte";

// include/discussion.php, row 207, 255
$_m["Show all"]
 = "Zeige alle";

// include/discussion.php, row 209, 257
$_m["Add new"]
 = "Hinzufügen";

// include/discussion.php, row 215
$_m["Alias for subject of the discussion comment"]
 = "";

// include/discussion.php, row 216
$_m["Alias for text of the discussion comment"]
 = "";

// include/discussion.php, row 217
$_m["Alias for written by"]
 = "";

// include/discussion.php, row 218
$_m["Alias for author's e-mail"]
 = "";

// include/discussion.php, row 219
$_m["Alias for url address of author's www site"]
 = "";

// include/discussion.php, row 220
$_m["Alias for description of author's www site"]
 = "";

// include/discussion.php, row 221
$_m["Alias for publish date"]
 = "";

// include/discussion.php, row 222
$_m["Alias for IP address of author's computer"]
 = "";

// include/discussion.php, row 223
$_m["Alias for checkbox used for choosing discussion comment"]
 = "";

// include/discussion.php, row 224
$_m["Alias for images"]
 = "";

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
 = "Alias for link to text of the discussion comment<br>\n"
   ."                             <i>Usage: </i>in HTML-Code for index view of the comment<br>\n"
   ."                             <i>Example: </i>&lt;a href=_#URL_BODY>_#SUBJECT#&lt;/a>";

// include/discussion.php, row 229
$_m["Alias for link to a form<br>\n"
   ."                             <i>Usage: </i>in HTML code for fulltext view of the comment<br>\n"
   ."                             <i>Example: </i>&lt;a href=_#URLREPLY&gt;Reply&lt;/a&gt;"]
 = "Alias for link to a form<br>\n"
   ."                             <i>Usage: </i>in HTML-Code for fulltext view of the comment<br>\n"
   ."                             <i>Example: </i>&lt;a href=_#URLREPLY&gt;Reply&lt;/a&gt;";

// include/discussion.php, row 230
$_m["Alias for link to discussion<br>\n"
   ."                             <i>Usage: </i>in form code<br>\n"
   ."                             <i>Example: </i>&lt;input type=hidden name=url value=\"_#DISC_URL\">"]
 = "";

// include/discussion.php, row 231
$_m["Alias for buttons Show all, Show selected, Add new<br>\n"
   ."                             <i>Usage: </i> in the Bottom HTML code"]
 = "Alias for buttons Show all, Show selected, Add new<br>\n"
   ."                             <i>Usage: </i> in the Bottom HTML-Code";

// include/discussion.php, row 413
$_m["3rd parameter filled in DiscussionMailList field"]
 = "";

// include/discussion.php, row 415
$_m["%1th parameter filled in DiscussionMailList field"]
 = "";

// include/item.php, row 92
// include/itemview.php, row 108
$_m["number of found items"]
 = "";

// include/item.php, row 93
$_m["index of item within whole listing (begins with 0)"]
 = "";

// include/item.php, row 94
$_m["index of item within a page (it begins from 0 on each page listed by pagescroller)"]
 = "";

// include/item.php, row 95
$_m["alias for Item ID"]
 = "";

// include/item.php, row 96
$_m["alias for Short Item ID"]
 = "Alias für kurze Artikel-ID";

// include/item.php, row 102, 103
$_m["alias used on admin page index.php for itemedit url"]
 = "";

// include/item.php, row 104
$_m["Alias used on admin page index.php for edit discussion url"]
 = "";

// include/item.php, row 105
$_m["Title of Slice for RSS"]
 = "";

// include/item.php, row 106
$_m["Link to the Slice for RSS"]
 = "";

// include/item.php, row 107
$_m["Short description (owner and name) of slice for RSS"]
 = "";

// include/item.php, row 108
$_m["Date RSS information is generated, in RSS date format"]
 = "";

// include/item.php, row 109
$_m["Slice name"]
 = "";

// include/item.php, row 111
$_m["Current MLX language"]
 = "";

// include/item.php, row 112
$_m["HTML markup direction tag (e.g. DIR=RTL)"]
 = "";

// include/item.php, row 140
$_m["Constant name"]
 = "";

// include/item.php, row 141
$_m["Constant value"]
 = "";

// include/item.php, row 142
$_m["Constant priority"]
 = "";

// include/item.php, row 143
$_m["Constant group id"]
 = "";

// include/item.php, row 144
$_m["Category class (for categories only)"]
 = "";

// include/item.php, row 145
$_m["Constant number"]
 = "";

// include/item.php, row 146
$_m["Constant unique id (32-hexadecimal characters)"]
 = "";

// include/item.php, row 147
$_m["Constant unique short id (autoincremented from '1' for each constant in the system)"]
 = "";

// include/item.php, row 148
$_m["Constant description"]
 = "";

// include/item.php, row 149
$_m["Constant level (used for hierarchical constants)"]
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
 = "Zurück";

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
 = "";

// include/easy_scroller.php3, row 146, 272
$_m["Previous"]
 = "Zurück";

// include/easy_scroller.php3, row 164, 290
$_m["Next"]
 = "Weiter";

// include/itemview.php, row 332
$_m["No comment was selected"]
 = "";

 // include/util.php, row 1878
$_m["January"]
 = "Januar";

// include/util.php, row 1878
$_m["February"]
 = "Februar";

// include/util.php, row 1878
$_m["March"]
 = "März";

// include/util.php, row 1878
$_m["April"]
 = "";

// include/util.php, row 1878
$_m["May"]
 = "Mai";

// include/util.php, row 1878
$_m["June"]
 = "Juni";

// include/util.php, row 1879
$_m["July"]
 = "Juli";

// include/util.php, row 1879
$_m["August"]
 = "";

// include/util.php, row 1879
$_m["September"]
 = "";

// include/util.php, row 1879
$_m["October"]
 = "Oktober";

// include/util.php, row 1879
$_m["November"]
 = "";

// include/util.php, row 1879
$_m["December"]
 = "Dezember";


