<?php
# $Id$
# Language: HR
# This file was created automatically by the Mini GetText environment
# on 22.11.2005 17:38

# Do not change this file otherwise than by typing translations on the right of =

# Before each message there are links to program code where it was used.

$mgettext_lang = "hr";

# Unused messages
$_m["field will be displayed in select box. if not specified, in select box are displayed headlines. (only for constants input type: slice)"]
 = "Polje æe biti prikazano u kuæici izbora";

$_m["show all"]
 = "Prikaži sve";

$_m["used only for slices - if set (=1), then all items are shown (including expired and pending ones)"]
 = "Koristi se samo za stranice - ako je postavljeno (=1), prikazane su sve stavke";

$_m["Defines, which buttons to show in item selection:<br>A - 'Add'<br>M - 'Add Mutual<br>B - 'Backward'.<br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."]
 = "Odreðuje koje æe gumbe prikazati u selektoru stavki";

$_m["Show move buttons"]
 = "Prikazuje još kontrola";

$_m["Show buttons for moving items up and down"]
 = "Pokazuje gumbe za micanje stavki gore i dolje";

$_m["number of characters from the <b>fulltext</b> field"]
 = "Broj znakova iz polja Puni tekst";

$_m["field id of fulltext field (like full_text.......)"]
 = "ID polja punog teksta";

$_m["take first paragraph (text until \\<BR\\> or \\<P\\> or \\</P\\) if shorter then <b>length</b>"]
 = "Uzmite prvi paragraf";

$_m["prints <i>the field</i> as image width (height='xxx' width='yyy') empty string if cant work out, does not special case URLs from uploads directory, might do later! "]
 = "ispisuje <i>polje</i> kao širinu slikovne datoteke(visina='xxx' širina='yyy')";

$_m["print HTML"]
 = "ispisuje HTML";

$_m["prints <i>the field</i> content (or <i>unalias string</i>) depending on the html flag (if html flag is not set, it converts the content to html. In difference to f_h function, it converts to html line-breaks, too. Obviously this function is used for fultexts.)"]
 = "ispisuje <i>sadržaj</i> polja (ili <i>unalias string</i>) ovisno o html oznaci.)";

# End of unused messages
# admin/param_wizard.php3, row 81
$_m["Wizard"]
 = "èarobnjak";

# admin/param_wizard.php3, row 166
$_m["This is an undocumented %s. We don't recommend to use it."]
 = "Ovo je nedokumentiran %s. Ne preporuèamo korištenje!";

# admin/param_wizard.php3, row 167
$_m["Close the wizard"]
 = "Zatvorite èarobnjaka";

# admin/param_wizard.php3, row 181
$_m["Available parameters: "]
 = "Dostupni parametri";

# admin/param_wizard.php3, row 194
$_m["integer&nbsp;number"]
 = "cjelobrojni broj";

# admin/param_wizard.php3, row 195
$_m["any&nbsp;text"]
 = "bilo koji tekst";

# admin/param_wizard.php3, row 196
$_m["field&nbsp;id"]
 = "oznaka datoteke";

# admin/param_wizard.php3, row 197
$_m["boolean:&nbsp;0=false,1=true"]
 = "Booleova algebra:&nbsp; 0=neistinito,1=istinito";

# admin/param_wizard.php3, row 210
$_m["This %s has no parameters."]
 = "Ovaj %s nema parametara.";

# admin/param_wizard.php3, row 220
$_m["Have a look at these examples of parameters sets:"]
 = "Pogledajte primjere postavki parametara:";

# admin/param_wizard.php3, row 227
$_m["Show"]
 = "Prikaži";

# admin/param_wizard.php3, row 237
$_m["OK - Save"]
 = "U redu - spremi";

# admin/param_wizard.php3, row 238
$_m["Cancel"]
 = "Poništi";

# admin/param_wizard.php3, row 239
$_m["Show example params"]
 = "Pokažite parametre primjera";

# admin/param_wizard.php3, row 241
$_m["OK"]
 = "U redu ";

# include/constants_param_wizard.php3, row 54
$_m["Insert Function"]
 = "Umetnite funkciju";

# include/constants_param_wizard.php3, row 56
$_m["Text = don't modify"]
 = "Tekst = nemojte mijenjati";

# include/constants_param_wizard.php3, row 57
$_m["Does not modify the value."]
 = "Nemojte mijenjati vrijednost";

# include/constants_param_wizard.php3, row 59
$_m["Boolean = store 0 or 1"]
 = "Booleova algebra = sprema 0 ili 1";

# include/constants_param_wizard.php3, row 61
$_m["File = uploaded file"]
 = "Datoteka = poslana datoteka";

# include/constants_param_wizard.php3, row 62
$_m["Stores the uploaded file and a link to it, parameters only apply if type is image/something."]
 = "Sprema poslanu datoteku i vezu na nju, parametri se primjennjuju samo kao je datoteka tipa slika/nešto.";

# include/constants_param_wizard.php3, row 64
$_m["Mime types accepted"]
 = "Prihvaæa myme tip";

# include/constants_param_wizard.php3, row 65
$_m["Only files of matching mime types will be accepted"]
 = "Samo datoteke sa sukladnim myme tipovima æe biti prihvaæene";

# include/constants_param_wizard.php3, row 68
$_m["Maximum image width"]
 = "Najveæa dozvoljena širina slikovne datoteke";

# include/constants_param_wizard.php3, row 71
$_m["Maximum image height"]
 = "Najveæa dozvoljena visina slikovne datoteke";

# include/constants_param_wizard.php3, row 72
$_m["The image will be resampled to be within these limits, while retaining aspect ratio."]
 = "Slika æe biti promijenjena unutar tih granica zadržavajuæi razmjere";

# include/constants_param_wizard.php3, row 75
$_m["Other fields"]
 = "Ostala polja";

# include/constants_param_wizard.php3, row 76
$_m["List of other fields to receive this image, separated by ##"]
 = "Lista ostalih polja za primiti tu slikovnu datoteku, razdvojena sa ##";

# include/constants_param_wizard.php3, row 79
$_m["Upload policy"]
 = "";

# include/constants_param_wizard.php3, row 84
$_m["new | overwrite | backup<br>This parameter controls what to do if uploaded file alredy exists:\n"
   ."                       <br>new - AA creates new filename (by adding _x postfix) and store it with this new name (default)\n"
   ."                       <br>overwrite - the old file of the same name is overwritten\n"
   ."                       <br>backup - the old file is copied to new (non-existing) file and current file is stored with current name.\n"
   ."                       <br>In all cases the filename is escaped, so any non-word characters will be replaced by an underscore."]
 = "";

# include/constants_param_wizard.php3, row 87
$_m["Exact dimensions"]
 = "";

# include/constants_param_wizard.php3, row 89
$_m["If set to 1 the image will be downsized exactly to the specified dimensions (and croped if needed).\n"
   ."                       Default is 0 or empty: Maintain aspect ratio while resizing the image."]
 = "";

# include/constants_param_wizard.php3, row 94
$_m["User ID = always store current user ID"]
 = "Korisnièki ID = uvijek sprema trenutni korisnièki ID";

# include/constants_param_wizard.php3, row 97, 129
$_m["Login name"]
 = "Korisnièko ime";

# include/constants_param_wizard.php3, row 99
$_m["Item IDs"]
 = "Oznake stavki";

# include/constants_param_wizard.php3, row 101
$_m["Now = always store current time"]
 = "Sada = uvijek sprema trenutno vrijeme";

# include/constants_param_wizard.php3, row 102
$_m["Inserts the current time, no matter what the user sets."]
 = "Umeæe stvarno trenutno vrijeme";

# include/constants_param_wizard.php3, row 104, 206, 649
$_m["Password and Change Password"]
 = "Lozinka i promjena lozinke";

# include/constants_param_wizard.php3, row 107
$_m["Stores value from a 'Password and Change Password' field type.\n"
   ."           First prooves the new password matches the retyped new password,\n"
   ."           and if so, MD5-encrypts the new password and stores it."]
 = "Sprema vrijednosti iz 'Lozinka i promjena lozinke' tipa polja.";

# include/constants_param_wizard.php3, row 109
$_m["Computed field"]
 = "";

# include/constants_param_wizard.php3, row 110
$_m["The field is the result of expression written in \"Code for unaliasing\". It is good solution for all values, which could be precomputed, since its computation on item-show-time would be slow. Yes, you can use {view...}, {include...}, {switch...} here"]
 = "";

# include/constants_param_wizard.php3, row 112
$_m["Code for unaliasing"]
 = "";

# include/constants_param_wizard.php3, row 113
$_m["There you can write any string. The string will be unaliased on item store, so you can use any {...} construct as well as field aliases here"]
 = "";

# include/constants_param_wizard.php3, row 119
$_m["Default Value Type"]
 = "Pretpostavljena vrijednost";

# include/constants_param_wizard.php3, row 121
$_m["Text from 'Parameter'"]
 = "Tekst iz 'Parameter'";

# include/constants_param_wizard.php3, row 122
$_m["Text"]
 = "Tekst";

# include/constants_param_wizard.php3, row 124
$_m["Date + 'Parameter' days"]
 = "Datum + 'Parameter' dani";

# include/constants_param_wizard.php3, row 125
$_m["Number of days"]
 = "Broj dana";

# include/constants_param_wizard.php3, row 127
$_m["User ID"]
 = "Korisnièki ID";

# include/constants_param_wizard.php3, row 131
$_m["Now, i.e. current date"]
 = "Sada, sadašnji datum";

# include/constants_param_wizard.php3, row 133
$_m["Variable"]
 = "Varijabla";

# include/constants_param_wizard.php3, row 134
$_m["A dangerous function. Do not use."]
 = "Potencijalno opasna funkcija. Nemojte ju koristiti";

# include/constants_param_wizard.php3, row 136
$_m["Random string"]
 = "Sluèajni odabir";

# include/constants_param_wizard.php3, row 137
$_m["Random alphanumeric [A-Z0-9] string."]
 = "Brojèano slovèani niz [A-Z0-9] sluèajnog odabira";

# include/constants_param_wizard.php3, row 139
$_m["String length"]
 = "";

# include/constants_param_wizard.php3, row 142
$_m["Field to check"]
 = "Polje za provjeru";

# include/constants_param_wizard.php3, row 144
$_m["If you need a unique code, you must send the field ID,\n"
   ."                  the function will then look into this field to ensure uniqueness."]
 = "Ako želite jedinstveni kod, morate poslati ID polja";

# include/constants_param_wizard.php3, row 147, 199
$_m["Slice only"]
 = "Samo stranica";

# include/constants_param_wizard.php3, row 149, 201
$_m["Do you want to check for uniqueness this slice only\n"
   ."                  or all slices?"]
 = "Da li želite provjeriti jedinstvenost samo za ovu ili za sve stranice";

# include/constants_param_wizard.php3, row 156
$_m["Input Validate Type"]
 = "Unesite tip provjere";

# include/constants_param_wizard.php3, row 158
$_m["No validation"]
 = "Nema provjere";

# include/constants_param_wizard.php3, row 160
$_m["URL"]
 = "Web adresa";

# include/constants_param_wizard.php3, row 162
$_m["E-mail"]
 = "";

# include/constants_param_wizard.php3, row 164
$_m["Number = positive integer number"]
 = "Broj - pozitivni cjeli broj";

# include/constants_param_wizard.php3, row 166
$_m["Id = 1-32 hexadecimal digits [0-9a-f]"]
 = "Id = 1-32 hexadecimalni brojevi  [0-9a-f]";

# include/constants_param_wizard.php3, row 168
$_m["Date = store as date"]
 = "Datum - sprema kao datum";

# include/constants_param_wizard.php3, row 170
$_m["Bool = store as bool"]
 = "Bool = sprema kao Booleov upit";

# include/constants_param_wizard.php3, row 172
$_m["User = does nothing ???"]
 = "Korisnik/ica = nema promjena ???";

# include/constants_param_wizard.php3, row 174
$_m["Unique = proove uniqueness"]
 = "Jedinstven = provjerite jedinstvenost zapisa";

# include/constants_param_wizard.php3, row 176
$_m["Validates only if the value is not yet used. Useful e.g.\n"
   ."        for emails or user names."]
 = "Provjerava samo da li je ve vrijednost veæ korištena";

# include/constants_param_wizard.php3, row 178, 195
$_m["Field ID"]
 = "ID polja";

# include/constants_param_wizard.php3, row 179, 196
$_m["Field in which to look for matching values."]
 = "Polje u kojem možete potražiti zadani vrijednost";

# include/constants_param_wizard.php3, row 182
$_m["Scope"]
 = "";

# include/constants_param_wizard.php3, row 186
$_m["<b>1</b> = This slice only.\n"
   ."                <b>2</b> = All slices.<br>\n"
   ."                <b>0</b> = Username, special: Checks uniqueness in reader management\n"
   ."                slices and in the permission system. Always uses field ID %1"]
 = "Samo ta stranica";

# include/constants_param_wizard.php3, row 192
$_m["Unique e-mail"]
 = "Jedinstveni e-mail";

# include/constants_param_wizard.php3, row 193
$_m["Combines the e-mail and unique validations. Validates only if the value is a valid email address and not yet used."]
 = "Kombinira provjeru e-mail adrese i provjeru jedinstvenosti";

# include/constants_param_wizard.php3, row 210
$_m["Validates the passwords do not differ when changing password.\n"
   ."        <i>The validation is provided only by JavaScript and not by ValidateInput()\n"
   ."        because the insert\n"
   ."        function does the validation again before inserting the new password.</i>"]
 = "Provjerava da li su obje lozinke iste pri promjeni";

# include/constants_param_wizard.php3, row 217
$_m["Input Type"]
 = "Tip unosa";

# include/constants_param_wizard.php3, row 219
$_m["Hierarchical constants"]
 = "Konstante prioriteta";

# include/constants_param_wizard.php3, row 220
$_m["A view with level boxes allows to choose constants."]
 = "Prozor sa kuæicama nivoa omoguæava vam da izaberete konstante";

# include/constants_param_wizard.php3, row 222
$_m["Level count"]
 = "";

# include/constants_param_wizard.php3, row 223
$_m["Count of level boxes"]
 = "Broj kuæica razina";

# include/constants_param_wizard.php3, row 226
$_m["Box width"]
 = "Širina prozora";

# include/constants_param_wizard.php3, row 227
$_m["Width in characters"]
 = "Širina u znakovima";

# include/constants_param_wizard.php3, row 230
$_m["Size of target"]
 = "Odredišna velièina";

# include/constants_param_wizard.php3, row 231
$_m["Lines in the target select box"]
 = "Broj linija u udredišnom prozoru";

# include/constants_param_wizard.php3, row 234
$_m["Horizontal"]
 = "Vodoravno";

# include/constants_param_wizard.php3, row 235
$_m["Show levels horizontally"]
 = "Pokažite razine vodoravno";

# include/constants_param_wizard.php3, row 238
$_m["First selectable"]
 = "Prvi moguæi odabir";

# include/constants_param_wizard.php3, row 239
$_m["First level which will have a Select button"]
 = "Prva razina koja æe imati gumb: Odaberite";

# include/constants_param_wizard.php3, row 242
$_m["Level names"]
 = "Imena razina";

# include/constants_param_wizard.php3, row 243
$_m["Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ..."]
 = "Imena prozora razina";

# include/constants_param_wizard.php3, row 245
$_m["Top level~Second level~Keyword"]
 = "Prva razina~Druga razina~Kljuèna rijeè";

# include/constants_param_wizard.php3, row 247
$_m["Text Area"]
 = "Tekstualno podruèje";

# include/constants_param_wizard.php3, row 248
$_m["Text area with 60 columns"]
 = "Podruèje za tekst sa 60 redova";

# include/constants_param_wizard.php3, row 250, 258
$_m["row count"]
 = "Broj stupaca";

# include/constants_param_wizard.php3, row 255
$_m["Rich Edit Area"]
 = "Prostor za oblikovanje teksta";

# include/constants_param_wizard.php3, row 256
$_m["Rich edit text area. This operates the same way as Text Area in browsers which don't support the Microsoft TriEdit library. In IE 5.0 and higher and in Netscape 4.76 and higher (after installing the necessary features) it uses the TriEdit to provide an incredibly powerful HTML editor.<br><br>\n"
   ."Another possibility is to use the <b>iframe</b> version which should work in IE on Windows and Mac (set the 3rd parameter to \"iframe\").<br><br>\n"
   ."The code for this editor is taken from the Wysiwyg open project (http://www.unica.edu/uicfreesoft/) and changed to fullfill our needs. See http://www.unica.edu/uicfreesoft/wysiwyg_web_edit/Readme_english.txt on details how to prepare Netscape.<br><br>\n"
   ."The javascript code needed to provide the editor is saved in two HTML files, so that the user doesn't have to load it every time she reloads the Itemedit web page."]
 = "Prostor za oblikovanje teksta";

# include/constants_param_wizard.php3, row 262
$_m["column count"]
 = "Broj redova";

# include/constants_param_wizard.php3, row 266, 784
# doc/param_wizard_list.php3, row 96
$_m["type"]
 = "Tip";

# include/constants_param_wizard.php3, row 267
$_m["type: class (default) / iframe"]
 = "upišite: class (default) / iframe";

# include/constants_param_wizard.php3, row 269
$_m["class"]
 = "Kategorija";

# include/constants_param_wizard.php3, row 271
$_m["Text Field"]
 = "Tekstualno polje";

# include/constants_param_wizard.php3, row 272
$_m["A text field."]
 = "Tekstualno polje";

# include/constants_param_wizard.php3, row 274, 338
$_m["max characters"]
 = "Najveæi dozvoljeni broj znakova";

# include/constants_param_wizard.php3, row 275
$_m["max count of characters entered (maxlength parameter)"]
 = "Najveæi dozvoljeni broj unešenih znakova";

# include/constants_param_wizard.php3, row 278, 342
$_m["width"]
 = "Širina";

# include/constants_param_wizard.php3, row 279
$_m["width of the field in characters (size parameter)"]
 = "Širina polja u znakovima";

# include/constants_param_wizard.php3, row 284
$_m["Multiple Text Field"]
 = "";

# include/constants_param_wizard.php3, row 285
$_m["Text field input type which allows you to enter more than one (multiple) values into one field (just like if you select multiple values from Multiple Selectbox). The new values are filled by popup box."]
 = "";

# include/constants_param_wizard.php3, row 287, 567
$_m["Show Actions"]
 = "";

# include/constants_param_wizard.php3, row 293
$_m["Which action buttons to show:\n"
   ."                       <br>M - Move (up and down)\n"
   ."                       <br>D - Delete value,\n"
   ."                       <br>A - Add new value\n"
   ."                       <br>C - Change the value\n"
   ."                       <br>Use 'MDAC' (default), 'DAC', just 'M' or any other combination. The order of letters M,D,A,C is not important."]
 = "";

# include/constants_param_wizard.php3, row 296, 498, 547, 607
$_m["Row count"]
 = "Broj redova";

# include/constants_param_wizard.php3, row 297
$_m["Number of rows (values) displayed at once"]
 = "";

# include/constants_param_wizard.php3, row 303
$_m["Select Box"]
 = "Odabir";

# include/constants_param_wizard.php3, row 304
$_m["A selectbox field with a values list.<br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice, usually with the f_v alias function)"]
 = "Polje za izbor sa listom vrijednosti";

# include/constants_param_wizard.php3, row 306, 346
$_m["slice field"]
 = "Polje stranice";

# include/constants_param_wizard.php3, row 307, 347, 503
$_m["field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"]
 = "";

# include/constants_param_wizard.php3, row 309, 349, 413, 472, 505, 622, 910
$_m["category........"]
 = "Kategorija";

# include/constants_param_wizard.php3, row 310, 350
$_m["use name"]
 = "Koristite ime";

# include/constants_param_wizard.php3, row 311, 351
$_m["if set (=1), then the name of selected constant is used, insted of the value. Default is 0"]
 = "Ako je postavljeno (=1), koriti se ime odabrane konstante, umjesto vrijednosti. Pretpostavljeno je 0";

# include/constants_param_wizard.php3, row 314, 366, 414, 473, 506, 623
$_m["Show items from bins"]
 = "";

# include/constants_param_wizard.php3, row 322, 374, 422, 481, 514, 631
$_m["(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"]
 = "";

# include/constants_param_wizard.php3, row 325, 377, 425, 484, 517, 634
$_m["Filtering conditions"]
 = "";

# include/constants_param_wizard.php3, row 326, 378, 426, 485, 518, 635
$_m["(for slices only) Conditions for filtering items in selection. Use conds[] array."]
 = "";

# include/constants_param_wizard.php3, row 329, 381, 429, 488, 521, 638
$_m["Sort by"]
 = "";

# include/constants_param_wizard.php3, row 330, 382, 430, 489, 522, 639
$_m["(for slices only) Sort the items in specified order. Use sort[] array"]
 = "";

# include/constants_param_wizard.php3, row 335
$_m["Text Field with Presets"]
 = "Tekstualno polje sa pretpostavljenim postavkama";

# include/constants_param_wizard.php3, row 336
$_m["Text field with values names list. When you choose a name from the list, the appropriate value is printed in the text field"]
 = "Tekstualno polje sa listom imena vrijednosti. Kada izaberete ime iz liste, bit æe prikazana odgovarajuæa vrijednost u tekstualnom polju ";

# include/constants_param_wizard.php3, row 339
$_m["max count of characteres entered in the text field (maxlength parameter)"]
 = "Najveæi dozvoljeni broj znakova koji se unosi u tekstualno polje";

# include/constants_param_wizard.php3, row 343
$_m["width of the text field in characters (size parameter)"]
 = "Širina tekstualnog polja u znakovaima (parametar velièine)";

# include/constants_param_wizard.php3, row 354
$_m["adding"]
 = "Dodaje";

# include/constants_param_wizard.php3, row 355
$_m["adding the selected items to input field comma separated"]
 = "Dodaje odabranu stavku u polje za unos odvojeno zarezom";

# include/constants_param_wizard.php3, row 358
$_m["secondfield"]
 = "Drugo polje";

# include/constants_param_wizard.php3, row 359
$_m["field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)"]
 = "ID polja drugog tekstualnog polja, gdje æe biti prikazana vrijednost polja za izbor ";

# include/constants_param_wizard.php3, row 361, 859
$_m["source_href....."]
 = "Izvorni tekst";

# include/constants_param_wizard.php3, row 362
$_m["add2constant"]
 = "Dodajte konstantu";

# include/constants_param_wizard.php3, row 363
$_m["if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)"]
 = "Ako je postavljeno na 1, korisnièki upis vrijednosti je spremljenu konstante(Samo ako vrijednost veæ nije upisana)";

# include/constants_param_wizard.php3, row 387
$_m["Text Area with Presets"]
 = "Podruèje za tekst sa pretpostavljenim vrijednostima";

# include/constants_param_wizard.php3, row 388
$_m["Text area with values names list. When you choose a name from the list, the appropriate value is printed in the text area"]
 = "Tekstualno podruèje sa listom vrijednosti. Kad izaberete ime iz liste, odgovarajuæa vrijednost je prikazana u tekstualnom polju";

# include/constants_param_wizard.php3, row 390
$_m["rows"]
 = "Redovi";

# include/constants_param_wizard.php3, row 391
$_m["Textarea rows"]
 = "Redovi tekstualnog polja";

# include/constants_param_wizard.php3, row 394
$_m["cols"]
 = "stupci";

# include/constants_param_wizard.php3, row 395
$_m["Text area columns"]
 = "Stupci tekstualnog polja";

# include/constants_param_wizard.php3, row 399
$_m["Radio Button"]
 = "Gumb izbora";

# include/constants_param_wizard.php3, row 400
$_m["Radio button group - the user may choose one value of the list. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"]
 = "Gumb izbora - možete izabrati samo jednu vrijednost";

# include/constants_param_wizard.php3, row 402, 461
$_m["Columns"]
 = "Stupci";

# include/constants_param_wizard.php3, row 403, 462
$_m["Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."]
 = "Broj stupaca. Ako je prazno, sve je na istoj liniji. Ako je popunjeno, oblikuje tablicu";

# include/constants_param_wizard.php3, row 406, 465
$_m["Move right"]
 = "Pomaknite desno";

# include/constants_param_wizard.php3, row 407, 466
$_m["Should the function move right or down to the next value?"]
 = "Da li da se funkcija pomakne desno ili dolje na slijedeæu vrijednost";

# include/constants_param_wizard.php3, row 410, 469, 502, 597, 619
$_m["Slice field"]
 = "";

# include/constants_param_wizard.php3, row 411
$_m["Field (or format string) that will be displayed as radiobuton's option (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"]
 = "";

# include/constants_param_wizard.php3, row 435
$_m["Date"]
 = "Datum";

# include/constants_param_wizard.php3, row 436
$_m["you can choose an interval from which the year will be offered"]
 = "Možete izabrati interval iz kojeg æe biti odabrana godina";

# include/constants_param_wizard.php3, row 438
$_m["Starting Year"]
 = "Poèetna godina";

# include/constants_param_wizard.php3, row 439
$_m["The (relative) start of the year interval"]
 = "Poèetak godišnjeg intervala";

# include/constants_param_wizard.php3, row 442
$_m["Ending Year"]
 = "Završna godina";

# include/constants_param_wizard.php3, row 443
$_m["The (relative) end of the year interval"]
 = "Kraj godišnjeg intervala";

# include/constants_param_wizard.php3, row 446
$_m["Relative"]
 = "Relativno";

# include/constants_param_wizard.php3, row 447
$_m["If this is 1, the starting and ending year will be taken as relative - the interval will start at (this year - starting year) and end at (this year + ending year). If this is 0, the starting and ending years will be taken as absolute."]
 = "";

# include/constants_param_wizard.php3, row 450
$_m["Show time"]
 = "Pokaži vrijeme";

# include/constants_param_wizard.php3, row 451
$_m["show the time box? (1 means Yes, undefined means No)"]
 = "Pokaži kuæicu sa vremenom (1 je Da, nedefinirano Ne)";

# include/constants_param_wizard.php3, row 455
$_m["Checkbox"]
 = "Kuæica za odabir";

# include/constants_param_wizard.php3, row 456
$_m["The field value will be represented by a checkbox."]
 = "Vrijednost polja æe biti prikazana u kuæici za izbor";

# include/constants_param_wizard.php3, row 458
$_m["Multiple Checkboxes"]
 = "Višestruke kuæice izbora";

# include/constants_param_wizard.php3, row 459
$_m["Multiple choice checkbox group. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"]
 = "Kuæice za višestruki izbor";

# include/constants_param_wizard.php3, row 470
$_m["Field (or format string) that will be displayed as checbox options (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"]
 = "";

# include/constants_param_wizard.php3, row 495
$_m["Multiple Selectbox"]
 = "Višestruka kuæica izbora";

# include/constants_param_wizard.php3, row 496
$_m["Multiple choice select box. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"]
 = "Kuæice za višestruki izbor iz ponuðenog";

# include/constants_param_wizard.php3, row 528
$_m["File"]
 = "Datoteka";

# include/constants_param_wizard.php3, row 529
$_m["File upload - a text field with the file find button"]
 = "Slanje datoteka - tekstualno polje sa gumbom za pronaði datoteku";

# include/constants_param_wizard.php3, row 531
$_m["Allowed file types"]
 = "Dozvoljeni tipovi datoteka";

# include/constants_param_wizard.php3, row 534
$_m["image/*"]
 = "Slikovna datoteka";

# include/constants_param_wizard.php3, row 535
$_m["Label"]
 = "Ime";

# include/constants_param_wizard.php3, row 536
$_m["To be printed before the file upload field"]
 = "Bite æe prikazano prije polja za slanje datoteka";

# include/constants_param_wizard.php3, row 538
$_m["File: "]
 = "Datoteka";

# include/constants_param_wizard.php3, row 539
$_m["Hint"]
 = "Savjet";

# include/constants_param_wizard.php3, row 540
$_m["appears beneath the file upload field"]
 = "Pojavljuje se ored polja za slanje datoteke";

# include/constants_param_wizard.php3, row 542
$_m["You can select a file ..."]
 = "Možete izabrati datoteku ...";

# include/constants_param_wizard.php3, row 544
$_m["Related Item Window"]
 = "Prozor vezane stavke";

# include/constants_param_wizard.php3, row 545
$_m["List of items connected with the active one - by using the buttons Add and Delete you show a window, where you can search in the items list"]
 = "Lista stavki povezanih sa aktivnom stavkom - koristeæi Dodajte i Obrišite prikazat æe se prozor gdje možete pretraživati listu stavki";

# include/constants_param_wizard.php3, row 548
$_m["Row count in the list"]
 = "Broj redova u listi";

# include/constants_param_wizard.php3, row 551
$_m["Buttons to show"]
 = "Gumbi za prikaz";

# include/constants_param_wizard.php3, row 556
$_m["Defines, which buttons to show in item selection:\n"
   ."                       <br>A - Add\n"
   ."                       <br>M - add Mutual\n"
   ."                       <br>B - Backward\n"
   ."                       <br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."]
 = "";

# include/constants_param_wizard.php3, row 558, 566
$_m["AMB"]
 = "";

# include/constants_param_wizard.php3, row 559
$_m["Admin design"]
 = "Admin dizajn";

# include/constants_param_wizard.php3, row 560
$_m["If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default)."]
 = "";

# include/constants_param_wizard.php3, row 563
$_m["Tag Prefix"]
 = "Kod prefiksa";

# include/constants_param_wizard.php3, row 564
$_m["Selects tag set ('AMB' / 'GYR'). Ask Mitra for more details."]
 = "Odabire set kodova ('AMB' / 'GYR')";

# include/constants_param_wizard.php3, row 574
$_m["Which action buttons to show:\n"
   ."                       <br>M - Move (up and down)\n"
   ."                       <br>D - Delete relation,\n"
   ."                       <br>R - add Relation to existing item\n"
   ."                       <br>N - insert new item in related slice and make it related\n"
   ."                       <br>E - Edit related item\n"
   ."                       <br>Use 'DR' (default), 'MDRNE', just 'N' or any other combination. The order of letters M,D,R,N,E is not important."]
 = "";

# include/constants_param_wizard.php3, row 577
$_m["Show headlines from selected bins"]
 = "";

# include/constants_param_wizard.php3, row 586
$_m["To show headlines in related window from selected bins.<br>Use this values for bins:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"]
 = "";

# include/constants_param_wizard.php3, row 589
$_m["Filtering conditions - unchangeable"]
 = "";

# include/constants_param_wizard.php3, row 590
$_m["Conditions for filtering items in related items window. This conds user can't change."]
 = "";

# include/constants_param_wizard.php3, row 593
$_m["Filtering conditions - changeable"]
 = "";

# include/constants_param_wizard.php3, row 594
$_m["Conditions for filtering items in related items window. This conds user can change."]
 = "";

# include/constants_param_wizard.php3, row 598
$_m["field (or format string) that will be displayed in the boxes (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - {publish_date....})."]
 = "";

# include/constants_param_wizard.php3, row 600
$_m["publish_date...."]
 = "";

# include/constants_param_wizard.php3, row 604
$_m["Two Windows"]
 = "Dva prozora";

# include/constants_param_wizard.php3, row 605
$_m["Two Windows. <br><br>It uses the Constants select box - if you choose a constant group there, the constants of this group will be printed, if you choose a slice name, the headlines of all items will be printed (used for related stories or for setting relation to another slice - it is obviously used with f_v alias function then)"]
 = "";

# include/constants_param_wizard.php3, row 611
$_m["Title of \"Offer\" selectbox"]
 = "Naslov od \"Ponuda\" kuæica odabira";

# include/constants_param_wizard.php3, row 614
$_m["Our offer"]
 = "Naša ponuda";

# include/constants_param_wizard.php3, row 615
$_m["Title of \"Selected\" selectbox"]
 = "Naslov od \"Odabrano\" kuæica odabira";

# include/constants_param_wizard.php3, row 618
$_m["Selected"]
 = "Odabrano";

# include/constants_param_wizard.php3, row 620
$_m["field (or format string) that will be displayed in the boxes (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"]
 = "";

# include/constants_param_wizard.php3, row 645
$_m["Hidden field"]
 = "Skriveno polje";

# include/constants_param_wizard.php3, row 646
$_m["The field value will be shown as &lt;input type='hidden'. You will probably set this filed by javascript trigger used on any other field."]
 = "Vrijednost polja bit æe prikazana kao  &lt;input type='hidden'. Vjerojatno æete postaviti to polje sa java skript okidaèem korištenim na nekom drugom polju.";

# include/constants_param_wizard.php3, row 654
$_m["Password input boxes allowing to send password (for password-protected items)\n"
   ."        and to change password (including the \"Retype password\" box).<br><br>\n"
   ."        When a user fills new password, it is checked against the retyped password,\n"
   ."        MD5-encrypted so that nobody may learn it and stored in the database.<br><br>\n"
   ."        If the field is not Required, shows a 'Delete Password' checkbox."]
 = "";

# include/constants_param_wizard.php3, row 656
$_m["Field size"]
 = "Velièina polja";

# include/constants_param_wizard.php3, row 657
$_m["Size of the three fields"]
 = "Velièina tri polja";

# include/constants_param_wizard.php3, row 660
$_m["Label for Change Password"]
 = "Oznaka za Promijenite lozinku";

# include/constants_param_wizard.php3, row 661
$_m["Replaces the default 'Change Password'"]
 = "Mijenja pretpostavljeno 'Promijenite lozinku'";

# include/constants_param_wizard.php3, row 663
$_m["Change your password"]
 = "Promijenite svoju lozinku";

# include/constants_param_wizard.php3, row 664
$_m["Label for Retype New Password"]
 = "Oznaka za utipkati novu lozinku";

# include/constants_param_wizard.php3, row 665
$_m["Replaces the default \"Retype New Password\""]
 = "Zamjenjuje pretpostavljeno \"Upišite novu lozinku\"";

# include/constants_param_wizard.php3, row 667
$_m["Retype the new password"]
 = "Upišite ponovo novu lozinku";

# include/constants_param_wizard.php3, row 668
$_m["Label for Delete Password"]
 = "Oznaka za Obrišite lozinku";

# include/constants_param_wizard.php3, row 669
$_m["Replaces the default \"Delete Password\""]
 = "Zamjenjuje pretpostavljeno \"Upišite novu lozinku\"";

# include/constants_param_wizard.php3, row 671
$_m["Delete password (set to empty)"]
 = "Briše lozinku (postavlja na 0)";

# include/constants_param_wizard.php3, row 672
$_m["Help for Change Password"]
 = "Pomoæ za promjenu lozinke";

# include/constants_param_wizard.php3, row 673
$_m["Help text under the Change Password box (default: no text)"]
 = "Tekst pomoæi unutar Polja za promjenu lozinke (pretpostavljeno: nema teksta)";

# include/constants_param_wizard.php3, row 675
$_m["To change password, enter the new password here and below"]
 = "Za promjenu lozinke, upipšite novu lozinku ovdje i ispod";

# include/constants_param_wizard.php3, row 676
$_m["Help for Retype New Password"]
 = "Pomoæ za Upišite novu lozinku";

# include/constants_param_wizard.php3, row 677
$_m["Help text under the Retype New Password box (default: no text)"]
 = "Tekst pomoæi ispod kuæice za unos nove lozinke (pretpostavljeno: nema teksta)";

# include/constants_param_wizard.php3, row 680
$_m["Retype the new password exactly the same as you entered into \"Change Password\"."]
 = "Upišite istu lozinku kao u \"Promjenite lozinku\".";

# include/constants_param_wizard.php3, row 684
$_m["Do not show"]
 = "Ne prikazuje";

# include/constants_param_wizard.php3, row 685
$_m["This option hides the input field"]
 = "Ova opcija sakriva polje za unos";

# include/constants_param_wizard.php3, row 689
$_m["Function"]
 = "Funkcija";

# include/constants_param_wizard.php3, row 690
$_m["How the formatting in the text on this page is used:<br><i>the field</i> in italics stands for the field edited in the \"configure Fields\" window,<br><b>parameter name</b> in bold stands for a parameter on this screen."]
 = "Kako se koristi oblikovanje teksta na ovoj stranici:";

# include/constants_param_wizard.php3, row 692
$_m["null function"]
 = "Funkcija nul";

# include/constants_param_wizard.php3, row 693
$_m["prints nothing"]
 = "Ne ispisuje ništa";

# include/constants_param_wizard.php3, row 694
$_m["abstract"]
 = "Skraæeni tekst";

# include/constants_param_wizard.php3, row 695
$_m["prints abstract (if exists) or the beginning of the <b>fulltext</b>"]
 = "Printa skraæeni tekst (ako postoji) na poèetku Punog teksta";

# include/constants_param_wizard.php3, row 697
$_m["length"]
 = "Dužina";

# include/constants_param_wizard.php3, row 698
$_m["max number of characters grabbed from the <b>fulltext</b> field"]
 = "";

# include/constants_param_wizard.php3, row 701
$_m["fulltext"]
 = "Puni tekst";

# include/constants_param_wizard.php3, row 702
$_m["field id of fulltext field (like full_text.......), from which the text is grabbed. If empty, the text is grabbed from <i>the field</i> itself."]
 = "";

# include/constants_param_wizard.php3, row 704, 731, 922
$_m["full_text......."]
 = "Puni tekst";

# include/constants_param_wizard.php3, row 705
$_m["paragraph"]
 = "Paragraf";

# include/constants_param_wizard.php3, row 706
$_m["take first paragraph (text until \\<BR\\> or \\<P\\> or \\</P\\> or at least '.' (dot)) if shorter then <b>length</b>"]
 = "";

# include/constants_param_wizard.php3, row 709
$_m["extended fulltext link"]
 = "Veza na prošireni puni tekst";

# include/constants_param_wizard.php3, row 710
$_m["Prints some <b>text</b> (or field content) with a link to the fulltext. A more general version of the f_f function. This function doesn't use <i>the field</i>."]
 = "Prikazuje tekst ili sadržaj polja sa vezom na puni tekst.";

# include/constants_param_wizard.php3, row 712, 795
$_m["link only"]
 = "Samo veza";

# include/constants_param_wizard.php3, row 713
$_m["field id (like 'link_only.......') where switch between external and internal item is stored.  (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."]
 = "ID polja";

# include/constants_param_wizard.php3, row 715, 798
$_m["link_only......."]
 = "Samo veza";

# include/constants_param_wizard.php3, row 716
$_m["url_field"]
 = "POlje za web adresu";

# include/constants_param_wizard.php3, row 717
$_m["field id if field, where external URL is stored (like hl_href.........)"]
 = "ID polja ako je polje gdje je spremljena vanjska veza (kao href... ";

# include/constants_param_wizard.php3, row 719
$_m["hl_href........."]
 = "veza";

# include/constants_param_wizard.php3, row 720, 799
$_m["redirect"]
 = "preusmjeravanje";

# include/constants_param_wizard.php3, row 721, 800
$_m["The URL of another page which shows the content of the item. (That page should contain SSI include ../slice.php3.). If unfilled, the same page as for item index is used for fulltext (which is obvious behavior)."]
 = "Web adresa druge stranice koja pokazuje sadržaj stavke.";

# include/constants_param_wizard.php3, row 723, 802
$_m["http#://www.ecn.cz/articles/solar.shtml"]
 = "";

# include/constants_param_wizard.php3, row 724
$_m["text"]
 = "Tekst";

# include/constants_param_wizard.php3, row 725
$_m["The text to be surrounded by the link. If this parameter is a field id, the field's content is used, else it is used verbatim"]
 = "Tekst popraæen vezom.";

# include/constants_param_wizard.php3, row 728
$_m["condition field"]
 = "Polje uvjeta";

# include/constants_param_wizard.php3, row 729
$_m["when the specified field hasn't any content, no link is printed, but only the <b>text</b>"]
 = "Kada je odabrano polje prazno, nije prikazana veza, nego samo tekst";

# include/constants_param_wizard.php3, row 732, 879
$_m["tag addition"]
 = "Dodavanje HTML koda";

# include/constants_param_wizard.php3, row 733, 880
$_m["additional text to the \"\\<a\\>\" tag"]
 = "Dodatni tekst za kod veze";

# include/constants_param_wizard.php3, row 735, 882
$_m["target=_blank"]
 = "Otvara se u praznom prozoru";

# include/constants_param_wizard.php3, row 736, 803
$_m["no session id"]
 = "Nema ID-a sesije";

# include/constants_param_wizard.php3, row 737, 804
$_m["If 1, the session id (AA_SL_Session=...) is not added to url"]
 = "Ako je 1, ID sesije nije dodan u vezu";

# include/constants_param_wizard.php3, row 740, 743
$_m["condition"]
 = "Uvjet";

# include/constants_param_wizard.php3, row 741
$_m["This is a very powerful function. It may be used as a better replace of some previous functions. If <b>cond_field</b> = <b>condition</b>, prints <b>begin</b> <i>field</i> <b>end</b>, else prints <b>else</b>. If <b>cond_field</b> is not specified, <i>the field</i> is used. Condition may be reversed (negated) by the \"!\" character at the beginning of it."]
 = "Vrlo snažna funkcija za razne promjene";

# include/constants_param_wizard.php3, row 744
$_m["you may use \"!\" to reverse (negate) the condition"]
 = "Možete koristiti \"!\" za poništavanje uvjeta";

# include/constants_param_wizard.php3, row 747, 863
$_m["begin"]
 = "poèetak";

# include/constants_param_wizard.php3, row 748
$_m["text to print before <i>field</i>, if condition is true"]
 = "tekst koi se prikazuje prije <i>field</i>, ako je ispunjen uvjet";

# include/constants_param_wizard.php3, row 750
$_m["Yes"]
 = "Da";

# include/constants_param_wizard.php3, row 751
$_m["end"]
 = "kraj";

# include/constants_param_wizard.php3, row 752
$_m["text to print after <i>field</i>, if condition is true"]
 = "tekst koji se ispisuje nakon <i>field</i>, ako je uvjet ispunjen";

# include/constants_param_wizard.php3, row 755
$_m["else"]
 = "inaèe";

# include/constants_param_wizard.php3, row 756
$_m["text to print when condition is not satisfied"]
 = "tekst koji se ispisuje ako uvjet nije ispunjen";

# include/constants_param_wizard.php3, row 758
$_m["No"]
 = "Ne";

# include/constants_param_wizard.php3, row 759
$_m["cond_field"]
 = "Polje uvjeta";

# include/constants_param_wizard.php3, row 760
$_m["field to compare with the <b>condition</b> - if not filled, <i>field</i> is used"]
 = "polje za usporedbu sa <b>condition</b> - ako nije ispunjeno, <i>field</i> se koristi";

# include/constants_param_wizard.php3, row 763
$_m["skip_the_field"]
 = "Preskoèite polje";

# include/constants_param_wizard.php3, row 764
$_m["if set to 1, skip <i>the field</i> (print only <b>begin end</b> or <b>else</b>)"]
 = "Ako je postavljeno na 1, skip <i>the field</i> (print only <b>begin end</b> or <b>else</b>)";

# include/constants_param_wizard.php3, row 768
$_m["This example is usable e.g. for Highlight field - it shows Yes or No depending on the field content"]
 = "Primjer je upotrebljiv na primjer za isticanje polja - pokazuje Da ili Ne ovisno o sadržaju polja";

# include/constants_param_wizard.php3, row 769
$_m["1:Yes::No::1"]
 = "1:Da::Ne::1";

# include/constants_param_wizard.php3, row 770
$_m["When e-mail field is filled, prints something like \"Email: email@apc.org\", when it is empty, prints nothing"]
 = "Kada je e-mail polje ispunjeno, ispisuje nešto kao \"Email: email@apc.org\", kada je prazno, ne ispisuje ništa";

# include/constants_param_wizard.php3, row 771
$_m["!:Email#:&nbsp;"]
 = "!:E-mail#:&nbsp;";

# include/constants_param_wizard.php3, row 772
$_m["Print image height attribute, if <i>the field</i> is filled, nothing otherwise."]
 = "Ispisuje visinu tekstualne datoteke, ako je <i>polje </i> popunjeno, inaèe ništa";

# include/constants_param_wizard.php3, row 773
$_m["!:height="]
 = "!:visina=";

# include/constants_param_wizard.php3, row 774
$_m["date"]
 = "datum";

# include/constants_param_wizard.php3, row 775
$_m["prints date in a user defined format"]
 = "Prikazuje datum u korisnièki definiranom formatu";

# include/constants_param_wizard.php3, row 777
$_m["format"]
 = "";

# include/constants_param_wizard.php3, row 778
$_m["PHP-like format - see <a href=\"http://php.net/manual/en/function.date.php\" target=_blank>PHP manual</a>"]
 = "PHP format";

# include/constants_param_wizard.php3, row 780
$_m["m-d-Y"]
 = "mjesec-dan-godina";

# include/constants_param_wizard.php3, row 781
$_m["edit item"]
 = "Uredite stavku";

# include/constants_param_wizard.php3, row 782
$_m["_#EDITITEM used on admin page index.php3 for itemedit url"]
 = "Uredite stavku na admin stranici";

# include/constants_param_wizard.php3, row 785
$_m["disc - for editing a discussion<br>itemcount - to output an item count<br>safe - for safe html<br>slice_info - select a field from the slice info<br>edit - URL to edit the item<br>add - URL to add a new item"]
 = "Ureðivanje diskusija";

# include/constants_param_wizard.php3, row 787
$_m["edit"]
 = "Uredite";

# include/constants_param_wizard.php3, row 788
$_m["return url"]
 = "Povratna web adresa";

# include/constants_param_wizard.php3, row 789
$_m["Return url being called from, usually leave blank and allow default"]
 = "Povratna web adresa je pozvana sa, obièno ostavlja prazno i dozvoljava pretpostavljeno";

# include/constants_param_wizard.php3, row 791
$_m["/mysite.shtml"]
 = "Vaša stranica";

# include/constants_param_wizard.php3, row 792
$_m["fulltext link"]
 = "Veza na puni tekst";

# include/constants_param_wizard.php3, row 793
$_m["Prints the URL name inside a link to the fulltext - enables using external items. To be used immediately after \"\\<a href=\""]
 = "Ispisuje Ime web adres unutar veze na puni tekst - dozvoljava korištenje vanjskih stavki. Koristi se odmah nakon\"\\<a href=\"";

# include/constants_param_wizard.php3, row 796
$_m["field id (like 'link_only.......') where switch between external and internal item is stored. Usually this field is represented as checkbox. If the checkbox is checked, <i>the field</i> is printed, if unchecked, link to fulltext is printed (it depends on <b>redirect</b> parameter, too)."]
 = "ID polja (kao 'samo veza') gdje je spremljen preklopnik izmeðu vanjskih i unutarnjih funkcija. Obièno je to polje prikazano sa kuæicom za oznaèavanje. Ako je oznaèeno, <i>polje</i> se prikazuje, ako nije, prikazuje se veza na puni tekst(ovisi i o <b>preusmjeravanju</b>).";

# include/constants_param_wizard.php3, row 807
$_m["image height"]
 = "Visina slikovne datoteke";

# include/constants_param_wizard.php3, row 808
$_m["An old-style function. Prints <i>the field</i> as image height value (\\<img height=...\\>) or erases the height tag. To be used immediately after \"height=\".The f_c function provides a better way of doing this with parameters \":height=\". "]
 = "Stari tip funkcije. Ispisuje<i>polje</i> kao vrijednost visine tekstualne datoteke(\\<img height=...\\>) ili briše html kod visine. Koristi se odmah nakon  \"height=\".f_c funkcija omoguèuje bolji naèin za to sa parametrima \":height=\". ";

# include/constants_param_wizard.php3, row 809
$_m["print HTML multiple"]
 = "Višestruki prikaz HTML-a";

# include/constants_param_wizard.php3, row 810
$_m["prints <i>the field</i> content depending on the html flag (escape html special characters or just print)"]
 = "Prikazuje <i>sadržaj</i>  polja ovisno o oznaci html (izbjegava posebne znakove ili samo prikaz)";

# include/constants_param_wizard.php3, row 812
$_m["delimiter"]
 = "razdjelnik";

# include/constants_param_wizard.php3, row 813
$_m["if specified, a field with multiple values is displayed with the values delimited by it"]
 = "Ako je oznaèeno, polje sa višestrukim vrijednostima se prikazuje sa razdvojenim vrijednostima";

# include/constants_param_wizard.php3, row 816
$_m["image src"]
 = "Izvorišna slikovna datoteka";

# include/constants_param_wizard.php3, row 817
$_m["prints <i>the field</i> as image source (\\<img src=...\\>) - NO_PICTURE for none. The same could be done by the f_c function with parameters :::NO_PICTURE. "]
 = "prikazuje <i>polje</i> kao izvorišnu slikovnu datoteku (\\<img src=...\\>) - NEMA SLIKOVNE DATOTEKE za prazno. Isto možete napraviti sa f_c funkcijom sa parametrima  :::NO_PICTURE. ";

# include/constants_param_wizard.php3, row 818
$_m["image size"]
 = "Velièina slikovne datoteke";

# include/constants_param_wizard.php3, row 819
$_m["prints <i>the field</i> as image size (height='xxx' width='yyy') (or other image information) or empty string if cant work out, does not special case URLs from uploads directory, might do later! "]
 = "";

# include/constants_param_wizard.php3, row 821
$_m["information"]
 = "";

# include/constants_param_wizard.php3, row 822
$_m["specifies returned information: <br> - <i>html</i> - (default) - returns image size as HTML atributes (height='xxx' width='yyy')<br> - <i>width</i> - returns width of image in pixels<br> - <i>height</i> - returns height of image in pixels<br> - <i>imgtype</i> - returns flag indicating the type of the image: 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM<br> - <i>mime</i> - returns mimetype of the image (like 'image/gif', 'application/x-shockwave-flash', ...)"]
 = "";

# include/constants_param_wizard.php3, row 826
$_m["expanded string"]
 = "prošireni kod";

# include/constants_param_wizard.php3, row 827
$_m["expands the string in the parameter"]
 = "proširuje kod u parametar";

# include/constants_param_wizard.php3, row 829
$_m["string to expand"]
 = "kod za proširiti";

# include/constants_param_wizard.php3, row 830
$_m["if specified then this string is expanded, if not specified then expands the contents of the field"]
 = "ako je oznaèeno, proširuje se tekst, ako nije, onda se proširuje sadržaj polja";

# include/constants_param_wizard.php3, row 833
$_m["substring with case change"]
 = "subkod sa promjenom malih u velika slova i obrnuto";

# include/constants_param_wizard.php3, row 834
$_m["prints a part of <i>the field</i>"]
 = "ispisuje dio polja";

# include/constants_param_wizard.php3, row 836
$_m["start"]
 = "poèetak";

# include/constants_param_wizard.php3, row 837
$_m["position of substring start (0=first, 1=second, -1=last,-2=two from end)"]
 = "mjesto poèetka subkoda (0=prvi, 1=drugi, -1=zadnji,-2=dva od kraja)";

# include/constants_param_wizard.php3, row 840
$_m["count"]
 = "izbroji";

# include/constants_param_wizard.php3, row 841
$_m["count of characters (0=until the end)"]
 = "broj znakova(0=do kraja)";

# include/constants_param_wizard.php3, row 844
$_m["case"]
 = "velika -  mala slova";

# include/constants_param_wizard.php3, row 845
$_m["upper - convert to UPPERCASE, lower - convert to lowercase, first - convert to First Upper; default is don't change"]
 = "gore - pretvara u velika slova, dolje - pretvara u mala slova, pretpostavljeno je nema promjene";

# include/constants_param_wizard.php3, row 848
$_m["add string"]
 = "dodajte kod";

# include/constants_param_wizard.php3, row 849
$_m["if string is shorted, <i>add string</i> is appended to the string (probably something like [...])"]
 = "ako je kod skraæen, <i>dodajte kod</i> se dodaje kodu (vjerojatno nešto kao [...])";

# include/constants_param_wizard.php3, row 852
$_m["Auto Update Checkbox"]
 = "kuæica za odabir automatskog obnavljanja";

# include/constants_param_wizard.php3, row 853
$_m["linked field"]
 = "vezano polje";

# include/constants_param_wizard.php3, row 854
$_m["prints <i>the field</i> as a link if the <b>link URL</b> is not NULL, otherwise prints just <i>the field</i>"]
 = "ispisuje <i>polje</i> kao vezu ako<b>web adresa veze</b> nije NULA, inaèe ispisuje samo <i>polje</i>";

# include/constants_param_wizard.php3, row 856
$_m["link URL"]
 = "web adresa veze";

# include/constants_param_wizard.php3, row 860
$_m["e-mail or link"]
 = "e-mail ili veza";

# include/constants_param_wizard.php3, row 861
$_m["mailto link - prints: <br>\"<b>begin</b>\\<a href=\"(mailto:)<i>the field</i>\" <b>tag adition</b>\\><b>field/text</b>\\</a\\>. If <i>the field</i> is not filled, prints <b>else_field/text</b>."]
 = "mailto ispisuje:  <br>\"<b>begin</b>\\<a href=\"(mailto:)<i>the field</i>\" <b>tag adition</b>\\><b>field/text</b>\\</a\\>. Ako  <i>polje</i> nije ispunjeno, ispisuje <b>else_field/tekst</b>.";

# include/constants_param_wizard.php3, row 864
$_m["text before the link"]
 = "tekst prije veze";

# include/constants_param_wizard.php3, row 866
$_m["e-mail"]
 = "";

# include/constants_param_wizard.php3, row 867
$_m["field/text"]
 = "polje/tekst";

# include/constants_param_wizard.php3, row 868
$_m["if this parameter is a field id, the field's content is used, else it is used verbatim"]
 = "ako je parametar ID polja, koristi se sadržaj polja, inaæe se koristi verbatim";

# include/constants_param_wizard.php3, row 871
$_m["else_field/text"]
 = "polje drugaèije vrijednosti/tekst";

# include/constants_param_wizard.php3, row 872
$_m["if <i>the field</i> is empty, only this text (or field content) is printed"]
 = "ako je <i>polje</i>prazno, samo ovaj tekst (ili sadržaj polja) se prikazuje";

# include/constants_param_wizard.php3, row 875
$_m["linktype"]
 = "tip veze";

# include/constants_param_wizard.php3, row 876
$_m["mailto / href (default is mailto) - it is possible to use f_m function for links, too - just type 'href' as this parameter"]
 = "mailto / href (pretpostavljeno je mailto) - moguæe je koristiti f_m funkciju za linkove takoðer - samo upišite 'href' kao parametar";

# include/constants_param_wizard.php3, row 878
$_m["href"]
 = "";

# include/constants_param_wizard.php3, row 883
$_m["hide email"]
 = "skriva e-mail";

# include/constants_param_wizard.php3, row 884
$_m["if 1 then hide email from spam robots. Default is 0."]
 = "Ako je 1 onda skriva e-mail od spam robota. Pretpostavljeno je 0";

# include/constants_param_wizard.php3, row 887
$_m["'New' sign"]
 = "'Novo' znak";

# include/constants_param_wizard.php3, row 888
$_m["prints 'New' or 'Old' or any other text in <b>newer text</b> or <b>older text</b> depending on <b>time</b>. Time is specified in minutes from current time."]
 = "ispisuje 'Novo' ili 'Staro' ili bilo koji tekst u <b>noviji tekst</b> ili <b>stariji tekst</b> ovisno o <b>vremenu</b>. Vrijeme je izraženo u minutama od trenutnog vremena";

# include/constants_param_wizard.php3, row 890
$_m["time"]
 = "vrijeme";

# include/constants_param_wizard.php3, row 891
$_m["Time in minutes from current time."]
 = "Vrijeme u minutama od trenutnog vremena";

# include/constants_param_wizard.php3, row 893
$_m["1440"]
 = "";

# include/constants_param_wizard.php3, row 894
$_m["newer text"]
 = "noviji tekst";

# include/constants_param_wizard.php3, row 895
$_m["Text to be printed, if the date in <i>the filed</i> is newer than <i>current_time</i> - <b>time</b>."]
 = "Tekst za ispis, ako je datum u <i>polju</i> noviji od <i>trenutno vrijeme</i> - <b>vrijeme</b>.";

# include/constants_param_wizard.php3, row 897
$_m["NEW"]
 = "Novo";

# include/constants_param_wizard.php3, row 898
$_m["older text"]
 = "Stariji tekst";

# include/constants_param_wizard.php3, row 899
$_m["Text to be printed, if the date in <i>the filed</i> is older than <i>current_time</i> - <b>time</b>"]
 = "Tekst za ispis, ako je datum u <i>polju</i> stariji od <i>trenutno vrijeme</i> - <b>vrijeme</b>.";

# include/constants_param_wizard.php3, row 901, 950
$_m[""]
 = "";

# include/constants_param_wizard.php3, row 902
$_m["id"]
 = "ID";

# include/constants_param_wizard.php3, row 903
$_m["prints unpacked id (use it, if you watn to show 'item id' or 'slice id')"]
 = "prikazuje raspakirani ID (koristite ga, ako želite prikazati'ID stavke' ili 'ID stranice')";

# include/constants_param_wizard.php3, row 904
$_m["text (blurb) from another slice"]
 = "tekst (blurb) sa druge stranice";

# include/constants_param_wizard.php3, row 905
$_m["prints 'blurb' (piece of text) from another slice, based on a simple condition.<br>If <i>the field</i> (or the field specifield by <b>stringToMatch</b>) in current slice matches the content of <b>fieldToMatch</b> in <b>blurbSliceId</b>, it returns the content of <b>fieldToReturn</b> in <b>blurbSliceId</b>."]
 = "ispisuje 'blurb' (dio teksta) sa druge stranice, baziran najednostavnom uvjetu.<br>Ako je <i>polje</i> (ili polje oznaèeno kao <b>traženi tekst</b>) u trenutnoj stranici se poklapa sa sadržajem <b>traženog polja</b> u <b>blurbSliceId</b>, vraæa sadržaj <b>fieldToReturn</b> u<b>blurbSliceID</b>.";

# include/constants_param_wizard.php3, row 907
$_m["stringToMatch"]
 = "Traženi tekst";

# include/constants_param_wizard.php3, row 908
$_m["By default it is <i>the field</i>.  It can be formatted either as the id of a field (headline........) OR as static text."]
 = "Pretpostavljeno je <i>polje</i>. Može biti oblikovano ili kao ID polja(zaglavlje..........) ILI kao statièki tekst ";

# include/constants_param_wizard.php3, row 911
$_m["blurbSliceId"]
 = "raspakirani ID stranice gdje je spremljen blurb tekst";

# include/constants_param_wizard.php3, row 912
$_m["unpacked slice id of the slice where the blurb text is stored"]
 = "";

# include/constants_param_wizard.php3, row 914
$_m["41415f436f72655f4669656c64732e2e"]
 = "";

# include/constants_param_wizard.php3, row 915
$_m["fieldToMatch"]
 = "traženo polje";

# include/constants_param_wizard.php3, row 916
$_m["field id of the field in <b>blurbSliceId</b> where to search for <b>stringToMatch</b>"]
 = "ID polja od polja u <b>ID blurb stranice</b> gdje æete tražiti <b>odgovarajuæi podatak</b>";

# include/constants_param_wizard.php3, row 918
$_m["headline........"]
 = "zaglavlje............";

# include/constants_param_wizard.php3, row 919
$_m["fieldToReturn"]
 = "polje za povratak";

# include/constants_param_wizard.php3, row 920
$_m["field id of the field in <b>blurbSliceId</b> where the blurb text is stored (what to print)"]
 = "ID polja u polju <b>blurbSliceId</b> gdje je spremljen blurb tekst (što se ispisuje)";

# include/constants_param_wizard.php3, row 923
$_m["RSS tag"]
 = "RSS kod";

# include/constants_param_wizard.php3, row 924
$_m["serves for internal purposes of the predefined RSS aliases (e.g. _#RSS_TITL). Adds the RSS 0.91 compliant tags."]
 = "služi za internu uporabu predefiniranih RSS sinonima (npr. _#RSS_TITL). Dodaje RSS 0.91 kompatibilan kod";

# include/constants_param_wizard.php3, row 925, 928, 1034
$_m["default"]
 = "pretpostavljeno";

# include/constants_param_wizard.php3, row 926
$_m["prints <i>the field</i> or a default value if <i>the field</i> is empty. The same could be done by the f_c function with parameters :::<b>default</b>."]
 = "ispisuje<i>polje</i> ili pretpostavljenui vrijednost ako je <i>polje</i> prazno. Isto možete napraviti sa  f_c funkcijom  sa parametrima :::<b>pretpostavljeno</b>.";

# include/constants_param_wizard.php3, row 929
$_m["default value"]
 = "pretpostavljena vrijednost";

# include/constants_param_wizard.php3, row 931
$_m["javascript: window.alert('No source url specified')"]
 = "javascript: window.alert('Nije odreðen izvor datoteke')";

# include/constants_param_wizard.php3, row 932
$_m["print fied"]
 = "";

# include/constants_param_wizard.php3, row 933
$_m["prints <i>the field</i> content (or <i>unalias string</i>) depending on the html flag (if html flag is not set, it converts the content to html. In difference to f_h function, it converts to html line-breaks, too (in its basic variant)"]
 = "";

# include/constants_param_wizard.php3, row 935
$_m["unalias string"]
 = "";

# include/constants_param_wizard.php3, row 936
$_m["if the <i>unalias string</i> is defined, then the function ignores <i>the field</i> and it rather prints the <i>unalias string</i>. You can of course use any aliases (or fields like {headline.........}) in this string"]
 = "";

# include/constants_param_wizard.php3, row 938
$_m["<img src={img_src.........1} _#IMG_WDTH _#IMG_HGHT>"]
 = "";

# include/constants_param_wizard.php3, row 939
$_m["output modify"]
 = "";

# include/constants_param_wizard.php3, row 948
$_m["You can use some output modifications:<br>\n"
   ."                   &nbsp; - [<i>empty</i>] - no modification<br>\n"
   ."                   &nbsp; - <i>csv</i>  - prints the field for CSV file (Comma Separated Values) export<br>\n"
   ."                   &nbsp; - <i>urlencode</i> - URL-encodes string (see <a href=\"http://php.net/urlencode\">urlencode<a> PHP function)<br>\n"
   ."                   &nbsp; - <i>safe</i> - converts special characters to HTML entities (see <a href=\"http://php.net/htmlspecialchars\">htmlspecialchars<a> PHP function)<br>\n"
   ."                   &nbsp; - <i>javascript</i> - escape ' (replace ' with \\')<br>\n"
   ."                   &nbsp; - <i>striptags</i>  - strip HTML and PHP tags from the string<br>\n"
   ."                   &nbsp; - <i>asis</i>  - prints field content 'as is' - it do not add &lt;br&gt; at line ends even if field is marked as 'Plain text'. 'asis' parameter is good for Item Manager's 'Modify content...' feature, for example<br>\n"
   ."                   "]
 = "";

# include/constants_param_wizard.php3, row 951
$_m["transformation"]
 = "pretvorba";

# include/constants_param_wizard.php3, row 952
$_m["Allows to transform the field value to another value.<br>Usage: <b>content_1</b>:<b>return_value_1</b>:<b>content_1</b>:<b>return_value_1</b>:<b>default</b><br>If the content <i>the field</i> is equal to <b>content_1</b> the <b>return_value_1</b> is returned. If the content <i>the field</i> is equal to <b>content_2</b> the <b>return_value_2</b> is returned. If <i>the field is not equal to any <b>content_x</b>, <b>default</b> is returned</i>."]
 = "Dozvoljava pretvorbu vrijednosti polja u drugu vrijednost.";

# include/constants_param_wizard.php3, row 954, 962, 970, 978, 986, 994, 1002, 1010, 1018, 1026
$_m["content"]
 = "sadržaj";

# include/constants_param_wizard.php3, row 955, 963, 971, 979, 987, 995, 1003, 1011, 1019, 1027
$_m["string for comparison with <i>the field</i> for following return value"]
 = "tekst za usporedbu sa <i>poljem</i> za ovu povratnu vrijednost";

# include/constants_param_wizard.php3, row 958, 966, 974, 982, 990, 998, 1006, 1014, 1022, 1030
$_m["return value"]
 = "povratna vrijednost";

# include/constants_param_wizard.php3, row 959, 967, 975, 983, 991, 999, 1007, 1015, 1023, 1031
$_m["string to return if previous content matches - You can use field_id too"]
 = "tekst koji se ispisuje ako se prethodni sadržaj poklapa s upitom - možete koristiti i ID polja takoðer";

# include/constants_param_wizard.php3, row 961, 969, 977, 985, 993, 1001, 1009, 1017, 1025, 1033
$_m["Environment"]
 = "Okružje";

# include/constants_param_wizard.php3, row 1035
$_m["if no content matches, use this string as return value"]
 = "Ako nema sadržaja koji odgovara upitu, koristi ovaj redak kao povratnu vrijednost";

# include/constants_param_wizard.php3, row 1038
$_m["user function"]
 = "korisnièka funkcija";

# include/constants_param_wizard.php3, row 1039
$_m["calls a user defined function (see How to create new aliases in <a href='http://apc-aa.sourceforge.net/faq/#aliases'>FAQ</a>)"]
 = "korisnièki defainirana funkcija (pogledajte kakao kreirati nove sinonime na <a href='http://apc-aa.sourceforge.net/faq/#aliases'>FAQ</a>)";

# include/constants_param_wizard.php3, row 1041
$_m["function"]
 = "funkcija";

# include/constants_param_wizard.php3, row 1042
$_m["name of the function in the include/usr_aliasfnc.php3 file"]
 = "ime funkcije u include/usr_aliasfnc.php3 datoteci";

# include/constants_param_wizard.php3, row 1044
$_m["usr_start_end_date_cz"]
 = "";

# include/constants_param_wizard.php3, row 1045
$_m["parameter"]
 = "parametar poslan funkciji";

# include/constants_param_wizard.php3, row 1046
$_m["a parameter passed to the function"]
 = "";

# include/constants_param_wizard.php3, row 1049
$_m["view"]
 = "pogled";

# include/constants_param_wizard.php3, row 1050
$_m["allows to manipulate the views. This is a complicated and powerful function, described in <a href=\"../doc/FAQ.html#viewparam\" target=_blank>FAQ</a>, which allows to display any view in place of the alias. It can be used for 'related stories' table or for dislaying content of related slice."]
 = "";

# include/constants_param_wizard.php3, row 1052
$_m["complex parameter"]
 = "Složeni parametar";

# include/constants_param_wizard.php3, row 1053
$_m["this parameter is the same as we use in view.php3 url parameter - see the FAQ"]
 = "ovaj parametar je isti kao onaj koji se koristi u view.php3 parametru- vidi ÈPP";

# include/constants_param_wizard.php3, row 1055
$_m["vid=4&amp;cmd[23]=v-25"]
 = "";

# include/constants_param_wizard.php3, row 1056
$_m["image width"]
 = "Širina slikovne datoteke";

# include/constants_param_wizard.php3, row 1057
$_m["An old-style function. Prints <i>the field</i> as image width value (\\<img width=...\\>) or erases the width tag. To be used immediately after \"width=\".The f_c function provides a better way of doing this with parameters \":width=\". "]
 = "Stari tip funkcije. Ispisuje<i>polje</i> kao vrijednost visine tekstualne datoteke(\\<img height=...\\>) ili briše html kod visine. Koristi se odmah nakon  \"height=\".f_c funkcija omoguèuje bolji naèin za to sa parametrima \":height=\". ";

# include/constants_param_wizard.php3, row 1062
$_m["Transformation action"]
 = "Akcija pretvorbe";

# include/constants_param_wizard.php3, row 1064
$_m["Store"]
 = "Sprema";

# include/constants_param_wizard.php3, row 1065
$_m["Simply store a value from the input field"]
 = "Sprema vrijednost iz polja za unos";

# include/constants_param_wizard.php3, row 1069
$_m["Remove string"]
 = "Briše kod";

# include/constants_param_wizard.php3, row 1070
$_m["Remove all occurences of a string from the input field."]
 = "Briše sva pojavljivanja tog koda iz polja za unos";

# include/constants_param_wizard.php3, row 1072, 1090, 1107
$_m["string parameter"]
 = "Prametra koda";

# include/constants_param_wizard.php3, row 1073
$_m["Removed string"]
 = "Obrisani kod";

# include/constants_param_wizard.php3, row 1077
$_m["Format date"]
 = "Format datuma";

# include/constants_param_wizard.php3, row 1078
$_m["Parse the date in the input field expected to be in English date format. In case of error, the transformation fails"]
 = "";

# include/constants_param_wizard.php3, row 1082
$_m["Add http prefix"]
 = "Dodaje http prefiks";

# include/constants_param_wizard.php3, row 1083
$_m["Adds 'http://' prefix to the field if not beginning with 'http://' and not empty."]
 = "Dodaje 'http://' prefiks polu ako ne poèinje sa 'http://' i nije prazno.";

# include/constants_param_wizard.php3, row 1087
$_m["Store parameter"]
 = "Sprema parametar";

# include/constants_param_wizard.php3, row 1088
$_m["Store parameter instead of the input field"]
 = "Sprema parametar umjesto polja za unos";

# include/constants_param_wizard.php3, row 1094
$_m["Store as long id"]
 = "";

# include/constants_param_wizard.php3, row 1095
$_m["Creates long id from the string. The string is combined with the parameter!! or with slice_id (if the parameter is not provided. From the same string (and the same parameter) we create allways the same id."]
 = "";

# include/constants_param_wizard.php3, row 1097
$_m["string to add"]
 = "";

# include/constants_param_wizard.php3, row 1098
$_m["this parameter will be added to the string before conversion (the reason is to aviod empty strings and also in order we do not generate allways the same id for common strings (in different imports). If this param is not specified, slice_id is used istead."]
 = "";

# include/constants_param_wizard.php3, row 1104
$_m["Split input field by string"]
 = "Razdvaja polje unosa po kodu";

# include/constants_param_wizard.php3, row 1105
$_m["Split input field by string parameter and store the result as multi-value."]
 = "Razdvaja polje unosa po parametrima koda i sprema rezultat kao višestruku vrijednost";

# include/constants_param_wizard.php3, row 1108
$_m["string which separates the values of the input field"]
 = "Dodaje http prefiks kod koji odvaja vrijednosti polja za unos";

# include/constants_param_wizard.php3, row 1113
$_m["Store default value"]
 = "Sprema pretpostavljenu vrijednost";

# include/constants_param_wizard.php3, row 1126
$_m["Store these default values for the following output fields. The other output fields will filled form <i>From</i> field (if specified). Else it is filled by <i>Action parameters</i> string.\n"
   ."    <table>\n"
   ."        <tr><td><b>Output field</b></td><td><b>Value</b></td><td><b>Description</b></td></tr></b>\n"
   ."    <tr><td>Status code</td><td>1</td><td>The item will be stored in Active bin (Hint: set it to 2 for Holding bin)</td></tr>\n"
   ."    <tr><td>Display count</td><td>0</td><td></td></tr>\n"
   ."        <tr><td>Publish date</td><td>Current date</td><td></td></tr>\n"
   ."    <tr><td>Post date</td><td>Current date</td><td></td></tr>\n"
   ."    <tr><td>Last edit</td><td>Current date</td><td></td></tr>\n"
   ."    <tr><td>Expiry date</td><td>Current date + 10 years</td><td></td></tr>\n"
   ."    <tr><td>Posted by</td><td>Active user</td><td></td></tr>\n"
   ."    <tr><td>Edited by</td><td>Active user</td><td></td></tr>\n"
   ."      </table>\n"
   ."    "]
 = "Sprema pretpostavljene vrijednosti za slijedeæa izlazna polja. Ostala izlazna polja bit æe ispunjena <i>Sa</i> polja (ako je izabrano). Inaæe se ispunjava sa <i>Parametri akcije</i> kodom.\n"
   ."    <table>\n"
   ."        <tr><td><b>Izlazno polje</b></td><td><b>Vrijednost</b></td><td><b>Opis</b></td></tr></b>\n"
   ."    <tr><td>Kod statusa</td><td>1</td><td>Stavka æe biti spremljena u Aktivnim stavkama(Savjet: postavite na 2 za stavke na èekanju)</td></tr>\n"
   ."    <tr><td>Broj prikaza</td><td>0</td><td></td></tr>\n"
   ."        <tr><td>Datum objave</td><td>Današnji datum</td><td></td></tr>\n"
   ."    <tr><td>Poslano dana</td><td>Dnašnji datum</td><td></td></tr>\n"
   ."    <tr><td>Datum zadnjeg ureðivanja</td><td>Današnji datum</td><td></td></tr>\n"
   ."    <tr><td>Datum istjeka</td><td>Današnji datum + 10 godina</td><td></td></tr>\n"
   ."    <tr><td>Poslao/la</td><td>Aktivni korisnik/ica</td><td></td></tr>\n"
   ."    <tr><td>Uredio/la</td><td>Aktivni korisnik/ica</td><td></td></tr>\n"
   ."      </table>\n"
   ."    ";

# doc/param_wizard_list.php3, row 36
$_m["Param Wizard Summary"]
 = "Opis - èarobnjak parametara";

# doc/param_wizard_list.php3, row 45
$_m["Choose a Parameter Wizard"]
 = "Odaberite èarobnjak parametara";

# doc/param_wizard_list.php3, row 54, 71
$_m["Go"]
 = "Krenite";

# doc/param_wizard_list.php3, row 63
$_m["Change to: "]
 = "Promijenite u";

# doc/param_wizard_list.php3, row 78
$_m["TOP"]
 = "VRH";

# doc/param_wizard_list.php3, row 92
$_m["Parameters:"]
 = "Parametri";

# doc/param_wizard_list.php3, row 95
$_m["name"]
 = "ime";

# doc/param_wizard_list.php3, row 97
$_m["description"]
 = "objašnjenje";

# doc/param_wizard_list.php3, row 98
$_m["example"]
 = "primjer";

# doc/param_wizard_list.php3, row 104
$_m["integer number"]
 = "cjelobrojni broj";

# doc/param_wizard_list.php3, row 105
$_m["any text"]
 = "bilo koji tekst";

# doc/param_wizard_list.php3, row 106
$_m["field id"]
 = "oznaka polja";

# doc/param_wizard_list.php3, row 107
$_m["boolean: 0=false,1=true"]
 = "booleova algebra: 0=neistinito,1=istinito";

?>
