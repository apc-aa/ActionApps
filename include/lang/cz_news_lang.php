<?php
// $Id$
// Language: CZ
// This file was created automatically by the Mini GetText environment
// on 5.9.2008 16:13

// Do not change this file otherwise than by typing translations on the right of =

// Before each message there are links to program code where it was used.

$mgettext_lang = "cz";
setlocale(LC_ALL, 'cs_CZ');      // sort, date, uppercase, ..
setlocale(LC_NUMERIC, 'en_US');  // use numeric with dot - there is problem, when
// used Czech numeric comma for example in AA_Stringexpand_If:
//   $cmp  = create_function('$b', "return ($etalon $operator". ' $b);');
// float!! value $etalon is then with comma which leads to syntax error

# Unused messages
$_m["Add&nbsp;mutual"]
    = "Vz�jemn�";

$_m["Must begin with _#.<br>Alias must be exactly ten characters long including \"_#\".<br>Alias should be in upper case letters."]
    = "Mus� za��nat znaky \"_#\".<br>Alias mus� b�t p�esn� 10 znak� dlouh� v�etn� \"_#\".<br>M�l by b�t kapit�lkami.";

$_m["Function used for displaying in inputform. Some of them use the Constants,some of them use the Parameters. To get some more info, use the Wizard with Help."]
    = "Funkce, kter� se pou�ije pro zobrazen� pole ve vstupn�m formul��i. N�kter� pou��vaj� Konstanty, n�kter� pou��vaj� Parametry. V�ce informac� se dozv�te, kdy� pou�ijete Pr�vodce s N�pov�dou.";

$_m["Use&nbsp;as&nbsp;new"]
    = "Nov�&nbsp;dle&nbsp;vybran�ch";

$_m["Parameters are divided by double dot (:) or (in some special cases) by apostrophy (')."]
    = "Parametry jsou odd�leny dvojte�kou (:) nebo (ve speci�ln�ch p��padech) apostrofem (').";

$_m["Which function should be used as default:<BR>Now - default is current date<BR>User ID - current user ID<BR>Text - default is text in Parameter field<br>Date - as default is used current date plus <Parameter> number of days"]
    = "Funkce, kter� se pou�ije pro generov�n� defaultn�ch hodnot pole:<BR>Now - aktu�ln� datum<BR>User ID - identifik�tor p�ihl�en�ho u�ivatele<BR>Text - text uveden� v poli Parametr<br>Date - aktu�ln� datum plus <Parametr> dn�";

$_m["If default-type is Text, this sets the default text.<BR>If the default-type is Date, this sets the default date to the current date plus the number of days you set here."]
    = "Parametr pro defauln� hodnoty Text a Date (viz v��e)";

$_m["Validate function"]
    = "Funkce pro kontrolu vstupu (validace)";

$_m["This defines how the value is stored in the database.  Generally, use 'Text'.<BR>File will store an uploaded file.<BR>Now will insert the current time, no matter what the user sets.  Uid will insert the identity of the Current user, no matter what the user sets.  Boolean will store either 1 or 0.  "]
    = "Zp�sob ulo�en� do datab�ze";

$_m["HTML coded as default"]
    = "defaultn� pou��t HTML k�d";

$_m["When you go to Admin-Design, you use an Alias to show this field"]
    = "Aliasy pro pol��ka v datab�zi";

$_m["Function which handles the database field and displays it on page<BR>usually, use 'print'.<BR>"]
    = "Funkce, kter� zajist� zobrazen� pol��ka na str�nce";

$_m["Parameter passed to alias handling function. For detail see include/item.php file"]
    = "Dopl�kov� parametr p�ed�van� zobrazovac� funkci. Podrobnosti viz include/item.php file";

$_m["Help text"]
    = "N�pov�da";

$_m["Help text for the alias"]
    = "N�pov�dn� text�k pro tento alias";

$_m["Constant unique id"]
    = "Identifika�n� ��slo hodnoty";

$_m["alerts single usage access"]
    = "zas�l�n� zpr�v - jednor�zov� k�d";

$_m["URL where the form will be shown"]
    = "URL na kter�m bude formul�� zobrazen";

$_m["If you have just created the template, click on 'Step' and the template appears in the select box."]
    = "Pokud jste pr�v� vytvo�ili �ablonu, stiskn�te 'Krok' a �ablona se objev� v seznamu.";

$_m[" Alias for text of the discussion comment"]
    = " Alias pro text p��sp�vku";

$_m[" Alias for written by"]
    = " Alias pro autora p��sp�vku";

$_m[" Alias for comment ID<br>\n"
."                             <i>Usage: </i>in form code<br>\n"
."                             <i>Example: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">"]
    = " Alias pro ��slo p��sp�vku<br>\n"
    ."                             <i>U�it�: </i>v k�du formul��e<br>\n"
    ."                             <i>P��klad: </i>&lt;input type=hidden name=d_item_id value=\"_#ITEM_ID#\">";

$_m[" Alias for item ID<br>\n"
."                             <i>Usage: </i>in form code<br>\n"
."                             <i>Example: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">"]
    = " Alias pro ��slo �l�nku<br>\n"
    ."                             <i>U�it�: </i>v k�du formul��e<br>\n"
    ."                             <i>P��klad: </i>&lt;input type=hidden name=d_parent value=\"_#DISC_ID#\">";

$_m["Slice Admin - Slice Settings"]
    = "Nastaven� Web�ku";

$_m["URL of .shtml page (often left blank)"]
    = "URL .shtml str�nky (�asto ponech�no pr�zdn�)";

$_m["To include the slice in your webpage add the following to your shtml code:"]
    = "Pro zahrnut� web�ku do webu p�idejte do shtml k�du:";

$_m["Alias pro IP address of author's computer"]
    = "Alias pro IP adresu autorova po��ta�e";

$_m["No permission"]
    = "Neopr�vn�n�";

$_m["Pending"]
    = "Aktu�ln� - P�ipraven�";

$_m["Expired"]
    = "Vypr�eno";

$_m["in"]
    = "v";

$_m["Send email wizard"]
    = "Pr�vodce posl�n�m emailu";

$_m["Select related items"]
    = "V�b�r souvisej�c�ch �l�nk�";

$_m["Change current permissions"]
    = "Zm�na sou�asn�ch pr�v";

$_m["Module"]
    = "Modul";

$_m["If you are sure you have typed the correct password, please e-mail <a href=mailto:technical@ecn.cz>technical@ecn.cz</a>."]
    = "Pokud jste si jisti, �e zad�v�te spr�vn� jm�no a heslo, obra�te se pros�m \n\n"
    ."     na <a href=mailto:actionapps@ecn.cz>actionapps@ecn.cz</a>.\n\n"
    ."     <br>If you are sure you have typed the correct password, please e-mail \n\n"
    ."     <a href=mailto:actionapps@ecn.cz>actionapps@ecn.cz</a>.";

$_m["Show this field as a rich text editor (use only after having installed the necessary components!)"]
    = "Zobraz toto pole v rich text editoru (pou�ijte a� po nainstalov�n� pot�ebn�ch komponent!)";

$_m["Can't upload Image"]
    = "Soubor (obr�zek) nelze ulo�it";

$_m[" -- Empty -- "]
    = " -- ��dn� -- ";

$_m["User mail"]
    = "Testovac� e-mailov� adresa";

$_m["No user or template set"]
    = "Nebyl vybr�n ��dn� u�ivatel (nebo nebyla nalezena �ablova e-mailu, co� je divn�)";

$_m["Email sucessfully sent (Users: %1, Valid emails: %2, Emails sent: %3)"]
    = "E-mail byl odesl�n (u�ivatel�: %1, Platn�ch e-mailov�ch adres: %2, Odeslan�ch e-mail�: %3)";

$_m["Select readers<br><i>%1 reader(s) selected</i>"]
    = "Zvolte �ten��e<br><i>zvoleno %1 �ten���</i>";

$_m["Select readers"]
    = "Zvolte �ten��e";

$_m["You can not proceed until you select at least one reader!"]
    = "Nem��ete pokra�ovat dokud nezvol�te alespo� jednoho �ten��e!";

$_m["Find readers using the Search conditions in Item Manager."]
    = "Najd�te �ten��e pou�it�m Vyhledat ve Spr�v� zpr�v.";

$_m["Create or edit email template"]
    = "Vytvo�it nebo upravit �ablonu emailu";

$_m["Use Slice Admin / Email templates to create or edit an email template."]
    = "Pou�ijte Nastaven� / �ablony email� pro vytvo�en� nebo �pravu �ablony emailu.";

$_m["Choose email template"]
    = "Zvolte �ablonu emailu";

$_m["You can use all field aliases like in any view."]
    = "M��ete pou��t v�echny aliasy pol��ek jako v kter�mkoli pohledu.";

$_m["Send example email to"]
    = "Poslat vzor emailu na";

$_m["This will send emails to all readers selected in Step 1."]
    = "T�mto po�lete emaily v�em �ten���m zvolen�m v Kroku 1.";

$_m["Delete the email template"]
    = "Smazat �ablonu emailu";

$_m["If this was a one-off template, delete it."]
    = "Pokud jste vytvo�ili �ablonu pro jedno pou�it�, sma�te ji.";

$_m["Send Emails Wizard"]
    = "Pr�vodce Posl�n�m Emailu";

$_m["%1 email(s) were sent."]
    = "%1 email� posl�no.";

$_m["Find some more info in %1the doc."]
    = "Najd�te v�ce informac� v %1dokumentaci.";

$_m["Step"]
    = "Krok";

$_m["Close the wizard"]
    = "Zav��t pr�vodce";

$_m["index of item within view"]
    = "po�ad� �l�nku v r�mci pohledu";

$_m["ActionApps"]
    = "APC toolkit";

$_m["Retype New Password"]
    = "Zopakujte Nov� Heslo";

$_m["description"]
    = "popis";

$_m["email type"]
    = "typ emailu";

$_m["subject"]
    = "p�edm�t";

$_m["body"]
    = "t�lo";

$_m["from (email)"]
    = "od (email)";

$_m["language (charset)"]
    = "jazyk (znakov� sada)";

$_m["use HTML"]
    = "pou��t HTML";

$_m["owner"]
    = "vlastn�k";

$_m["from"]
    = "od";

$_m["Existing remote imports into the slice "]
    = "Seznam p�ij�man�ch web�k� do web�ku ";

$_m["Cannot open output file:"]
    = "Nelze otev��t v�stupn� soubor:";

$_m["Cannot open input file:"]
    = "Nelze otev��t vstupn� soubor:";

$_m["Cannot write to file"]
    = "Do souboru nelze zapisovat";

$_m["Settings"]
    = "Nastaven�";

$_m["You did not permit anonymous editing in slice settings. A form\n"
."        allowing only anonymous posting will be shown."]
    = "Nepovolili jste anonymn� editov�n� v nastaven� web�ku. Bude zobrazen\n"
    ."    formul�� povoluj�c� pouze anonymn� vkl�d�n�.";

$_m["Warning: You want to show password, but you did not set\n"
."                    'Authorized by a password field' in Settings - Anonymous editing."]
    = "POZOR: Chcete zobrazit heslo, ale nenastavili jste 'Autorizovan� heslem'\n"
    ."     v nastaven� web�ku - Anonymn� upravov�n�.";

$_m["Can't copy image  %s to %s"]
    = "Nelze zkop�rovat obr�zek %s na %s";

$_m["Bad item ID"]
    = "�patn� ��slo zpr�vy";

$_m["Where are these constants used?"]
    = "Kde jsou konstanty pou�ity?";

$_m["Are you sure you want to PERMANENTLY DELETE this group? Type yes or no."]
    = "Jste si jisti, �e chcete PERMANENTN� SMAZAT tuto skupinu? Napi�te ano �i ne.";

$_m["Admin - User Management"]
    = "Spr�va web�ku - U�ivatel�";

$_m["inserted"]
    = "vlo�ena";

$_m["updated"]
    = "obnovena";

$_m["not stored"]
    = "neulo�ena";

$_m["Ok: Item "]
    = "OK: Zpr�va ";

$_m["If the item id is already in the slice:"]
    = "Pokud zpr�va s dan�m ID ji� ve web�ku je:";

$_m["Update the item"]
    = "Obnov zpr�vu";

$_m["Cannot read input file"]
    = "Nelze p�e��st vstupn� soubor";

$_m["File for import does not exists:"]
    = "Vstupn� soubor neexistuje";

$_m["ALIASES used in views to print field content"]
    = "ALIASy pou�it� v pohledech k zobrazen� obsahu pol��ka";

$_m["preset \"Search\" in Itme Manager"]
    = "p�ednastaven� \"Hled�n�\" v administraci";

$_m["preset \"Order\" in Itme Manager"]
    = "p�ednastaven� \"Se�adit\" v administraci";

$_m["Ignore \"Copy field\""]
    = "Nic nekop�ruj";

$_m["<p>You can delete only slices which are marked as &quot;<b>deleted</b>&quot; on &quot;<b>Slice</b>&quot; page.</p>"]
    = "<p>Lze vymazat jen web�ky, kter� byly ozna�eny pro vymaz�n� na str�nce &quot;<b>Web�k</b>&quot;</p>";

$_m["Remove"]
    = "Odstranit";

$_m["you should use a-z, A-Z and 0-9 characters"]
    = "pou�ijte znaky a-z, A-Z a 0-9";

$_m["it must by 5 - 32 characters long"]
    = "mus� b�t dlouh� 5 - 32 znak�";

$_m["only 0-9 A-Z a-z . _ and - are allowed"]
    = "pouze 0-9 A-Z a-z . _ a - jsou povolen�";

$_m["Error in parameters for UNIQUE validation: field ID is not 16 but %1 chars long: "]
    = "Chyba v parametrech pro UNIK�TN� validaci: ID pol��ka je dlouh� %1 m�sto 16 znak�: ";

$_m["this value is already used, choose another one"]
    = "tato hodnota je u� pou�ita, zvolte jinou";

$_m["Switch to:"]
    = "Web�k:";

$_m["No module flagged for deletion."]
    = "��dn� modul nen� ozna�en pro smazan�.";

$_m["Bar image"]
    = "Obr�zek pro posuvn�k";

$_m["url of image for bar"]
    = "URL obr�zku pro posuvn�k";

$_m["Bar width"]
    = "���ka posuvn�ku";

$_m["width of poll bar"]
    = "���ka posuvn�ku";

$_m["Bar height"]
    = "V��ka posuvn�ku";

$_m["height of poll bar"]
    = "v��ka posuvn�ku";

$_m["Params"]
    = "Parametry";

# End of unused messages
// ./auth.php, row 50
// ./diff.diff, row 1323, 1382
// central/responder.php, row 150
// include/loginform.inc, row 70
// include/init_page.php, row 131
$_m["Either your username or your password is not valid."]
    = "Bu� jm�no nebo heslo nejsou v po��dku.";

// ./diff.diff, row 128, 153
// include/formutil.php, row 3109
$_m["Use these aliases for database fields"]
    = "Pou�ij n�sleduj�c� aliasy datab�zov�ch pol�";

// ./diff.diff, row 140, 159
// admin/um_uedit.php, row 240
// admin/discedit.php, row 175
// admin/um_gedit.php, row 187
// admin/se_fields.php, row 97
// admin/se_rssfeeds.php, row 186
// admin/se_views.php, row 76
// admin/prev_navigation.php, row 40
// admin/se_nodes.php, row 171
// include/um_gsrch.php, row 52
// include/formutil.php, row 1611, 1667, 3115
// include/mlx.php, row 404
// include/filedit.php, row 122
$_m["Edit"]
    = "Editace";

// ./diff.diff, row 715
$_m["Can't open url: %1"]
    = "";

// ./diff.diff, row 720
$_m["Problem reading data from url: %1"]
    = "";

// ./diff.diff, row 1092
// include/sliceadd.php, row 58
$_m["To create the new Slice, please choose a template.\n"
."        The new slice will inherit the template's default fields.  \n"
."        You can also choose a non-template slice to base the new slice on, \n"
."        if it has the fields you want."]
    = "Nov� web�k m��ete vytvo�it na z�klad� �ablony, nebo zkop�rovat nastaven� z ji� existuj�c�ho web�ku (vytvo�� se p�esn� kopie v�etn� nastaven� .";

// ./diff.diff, row 1099
// admin/slicedit.php, row 167
// include/sliceadd.php, row 65
$_m["Template"]
    = "�ablona";

// ./diff.diff, row 1102
// admin/um_uedit.php, row 333
// admin/um_gedit.php, row 237
// admin/se_newuser.php, row 127
// admin/se_rssfeeds.php, row 188
// admin/sliceadd.php, row 94
// admin/se_nodes.php, row 173
// include/formutil.php, row 62, 1605, 1678, 1711, 1992
// include/profile.php, row 156
// include/mlx.php, row 408
// include/sliceadd.php, row 75, 98
$_m["Add"]
    = "P�idat";

// ./diff.diff, row 1107
// include/sliceadd.php, row 79
$_m["No templates"]
    = "��dn� �ablona";

// ./filldisc.php3, row 89, 110
// ./filler.php, row 301
$_m["Not accepted, sorry. Looks like spam."]
    = "";

// ./filler.php, row 229
$_m["Not allowed to post comments"]
    = "";

// ./filler.php, row 250
// ./offline.php3, row 82
$_m["Slice ID not defined"]
    = "ID web�ku nen� definov�no";

// ./filler.php, row 257
// ./offline.php3, row 112
$_m["Bad slice ID"]
    = "Chybn� ID web�ku";

// ./filler.php, row 278
// admin/se_inputform.php, row 234
// include/formutil.php, row 289, 377
// include/itemfunc.php, row 678
$_m["No fields defined for this slice"]
    = "V tomto web�ku nejsou definov�na ��dn� pole (co� je divn�)";

// ./filler.php, row 322
$_m["Anonymous posting not admitted."]
    = "Anonymn� p�id�v�n� nen� povoleno.";

// ./filler.php, row 373
$_m["You are not allowed to update this item."]
    = "Nem�te pr�vo upravovat tuto zpr�vu.";

// ./filler.php, row 390
$_m["Some error in store item."]
    = "N�jak� chyba p�i ukl�d�n� zpr�vy.";

// ./offline.php3, row 116
$_m["You don't have permission to fill this slice off-line"]
    = "Nem�te pr�va pro off-line pln�n� web�ku";

// ./offline.php3, row 138
$_m["Duplicated item send - skipped"]
    = "Duplikovan� posl�n� zpr�vy - p�esko�eno";

// ./offline.php3, row 141
$_m["Wrong data (WDDX packet)"]
    = "Chybn� data (WDDX packet)";

// ./offline.php3, row 144
$_m["Item OK - stored in database"]
    = "Zpr�va ulo�ena v datab�zi";

// ./offline.php3, row 152
$_m["Now you can dalete local file. "]
    = "Nyn� m��ete odstranit lok�ln� soubor. ";

// ./offline.php3, row 153
$_m[" Delete "]
    = " Odstranit ";

// ./sql_update.php, row 1543
$_m["Database checkers"]
    = "";

// ./sql_update.php, row 1563
// admin/aa_optimize.php, row 65
// admin/se_rssfeeds.php, row 189
$_m["Test"]
    = "";

// ./sql_update.php, row 1566
// admin/aa_optimize.php, row 68
$_m["Repair"]
    = "";

// ./sql_update.php, row 1579
// admin/um_uedit.php, row 339
// admin/se_mapping.php, row 199
// admin/se_search.php3, row 180
// admin/um_gedit.php, row 241
// admin/discedit2.php, row 136
// admin/se_filters.php, row 256
// admin/se_import.php, row 126
// include/searchbar.class.php, row 642
// include/formutil.php, row 191, 2684
$_m["Update"]
    = "Zm�nit";

// ./sql_update.php, row 1626
$_m["Run Update"]
    = "";

// ./sql_update.php, row 1628
$_m["Restore Data from Backup Tables"]
    = "";

// admin/um_uedit.php, row 53
// admin/um_gedit.php, row 50
// admin/se_newuser.php, row 42
$_m["No permission to create new user"]
    = "Nem�te pr�vo vytvo�it u�ivatele";

// admin/um_uedit.php, row 71, 86, 109
// admin/um_gedit.php, row 75, 83
// admin/se_users_add.php, row 119
// include/um_gsrch.php, row 31
$_m["Too many users or groups found."]
    = "Nalezeno p��li� mnoho u�ivatel� �i skupin.";

// admin/um_uedit.php, row 71, 86
// admin/um_gedit.php, row 75
// admin/se_users_add.php, row 121
// include/um_gsrch.php, row 34
$_m["No user (group) found"]
    = "U�ivatel (skupina) nenalezena";

// admin/um_uedit.php, row 77, 80, 96
// admin/um_gedit.php, row 68
$_m["Too much groups found."]
    = "Nalezeno p��li� moc skupin.";

// admin/um_uedit.php, row 77, 80
// admin/um_gedit.php, row 68
$_m["No groups found"]
    = "Skupina nenalezena";

// admin/um_uedit.php, row 151
// admin/se_newuser.php, row 83
// include/um_util.php, row 358
$_m["User successfully added to permission system"]
    = "U�ivatel byl �sp�n� p�id�n do syst�mu";

// admin/um_uedit.php, row 151
// admin/um_passwd.php, row 65
$_m["User data modified"]
    = "�daje o u�ivateli byly zm�n�ny";

// admin/um_uedit.php, row 161
$_m["User management - Users"]
    = "Spr�va u�ivatel� - U�ivalel�";

// admin/um_uedit.php, row 171
$_m["Are you sure you want to delete selected user from whole permission system?"]
    = "Opravdu chcete vymazat u�ivatele ze syst�mu?";

// admin/um_uedit.php, row 208
// include/menu_aa.php3, row 48
$_m["New User"]
    = "Nov� u�ivatel";

// admin/um_uedit.php, row 208, 288
// admin/um_passwd.php, row 103
// include/menu_aa.php3, row 47
$_m["Edit User"]
    = "Editace u�ivatele";

// admin/um_uedit.php, row 215
// admin/um_gedit.php, row 261
// admin/se_users_add.php, row 68
// include/menu_aa.php3, row 46
$_m["Users"]
    = "U�ivatel�";

// admin/um_uedit.php, row 223, 314
// admin/um_gedit.php, row 171, 273
// admin/se_newuser.php, row 85
// admin/se_users_add.php, row 70, 75
// include/um_gsrch.php, row 47
// include/tabledit.php, row 574
// include/searchbar.class.php, row 553, 616
$_m["Search"]
    = "Vyhledat";

// admin/um_uedit.php, row 237
// admin/aarsstest.php, row 154
// admin/se_history.php, row 68, 88
// include/perm_sql.php, row 216, 689
$_m["User"]
    = "U�ivatel";

// admin/um_uedit.php, row 242
// admin/se_inter_export.php, row 130
// admin/discedit.php, row 173
// admin/se_inter_import.php, row 136
// admin/um_gedit.php, row 189
// admin/slicedel.php, row 59
// admin/se_fields.php, row 99, 101
// admin/se_rssfeeds.php, row 187
// admin/se_views.php, row 80
// admin/se_nodes.php, row 172
// include/um_gsrch.php, row 53
// include/searchbar.class.php, row 644
// include/formutil.php, row 1622, 1668, 1713, 1759
// include/profile.php, row 69
// include/menu_aa.php3, row 41
$_m["Delete"]
    = "Smazat";

// admin/um_uedit.php, row 289, 293
// admin/um_passwd.php, row 104
// admin/se_newuser.php, row 52, 113
// admin/slicewiz.php, row 82
// admin/setup.php, row 101, 237
// include/um_util.php, row 310
$_m["Login name"]
    = "U�ivatelsk� jm�no";

// admin/um_uedit.php, row 290
// admin/um_passwd.php, row 105
$_m["User Id"]
    = "Id u�ivatele";

// admin/um_uedit.php, row 292
// admin/se_newuser.php, row 107
$_m["New user"]
    = "Nov� u�ivatel";

// admin/um_uedit.php, row 295
// admin/aarsstest.php, row 151
// admin/um_passwd.php, row 107
// admin/se_newuser.php, row 53, 114
// admin/slicewiz.php, row 83
// admin/se_nodes.php, row 185
// admin/setup.php, row 102, 238
// include/um_util.php, row 315
$_m["Password"]
    = "Heslo";

// admin/um_uedit.php, row 296
// admin/um_passwd.php, row 108
// admin/se_newuser.php, row 54, 115
// admin/slicewiz.php, row 84
// include/um_util.php, row 316
$_m["Retype password"]
    = "Potvrdit heslo";

// admin/um_uedit.php, row 297
// admin/um_passwd.php, row 109
// admin/se_newuser.php, row 59, 116
// admin/slicewiz.php, row 85
// admin/setup.php, row 104, 242
// include/um_util.php, row 312
$_m["First name"]
    = "Jm�no";

// admin/um_uedit.php, row 298
// admin/um_passwd.php, row 110
// admin/se_newuser.php, row 58, 117
// admin/slicewiz.php, row 86
// include/um_util.php, row 311
$_m["Surname"]
    = "P��jmen�";

// admin/um_uedit.php, row 299
// admin/um_passwd.php, row 111
// admin/discedit2.php, row 55, 123
// admin/se_newuser.php, row 55, 118
// admin/slicewiz.php, row 87
// admin/setup.php, row 106, 244
// include/um_util.php, row 322, 323, 324
$_m["E-mail"]
    = "";

// admin/um_uedit.php, row 302
// admin/setup.php, row 96
$_m["Superadmin account"]
    = "Superadmin";

// admin/um_uedit.php, row 308
// admin/um_gedit.php, row 162
// admin/se_users_add.php, row 73
// include/um_gsrch.php, row 41
// include/menu_aa.php3, row 50
$_m["Groups"]
    = "Skupiny";

// admin/um_uedit.php, row 310
$_m["All Groups"]
    = "V�echny skupiny";

// admin/um_uedit.php, row 312
$_m["User's Groups"]
    = "U�ivatelovy skupiny";

// admin/se_inter_import2.php, row 34
// admin/se_mapping.php, row 36
// admin/se_inter_export.php, row 33
// admin/se_import2.php, row 36
// admin/se_inter_import3.php, row 36
// admin/se_inter_import.php, row 37
// admin/se_mapping2.php, row 39
// admin/se_rssfeeds.php, row 39
// admin/se_filters2.php, row 43
// admin/se_filters.php, row 44
// admin/se_import.php, row 39
$_m["You have not permissions to change feeding setting"]
    = "Nem�te pr�vo m�nit nastaven� v�m�ny zpr�v";

// admin/se_inter_import2.php, row 50, 67
$_m["Unable to connect and/or retrieve data from the remote node. Contact the administrator of the local node."]
    = "Nepoda�ilo se nav�zat spojen� nebo p�ijmout data. Kontaktuje administr�tora";

// admin/se_inter_import2.php, row 57
$_m["No slices available. You have not permissions to import any data of that node. Contact the administrator of the remote slice and check, that he obtained your correct username."]
    = "��dn� dostupn� web�ky. Nem�te pr�va p�ij�mat data z tohoto uzlu. Kontaktujte administr�tora vzd�len�ho web�ku a zkontrolujte, �e obdr�el va�e spr�vn� u�ivatelsk� jm�no.";

// admin/se_inter_import2.php, row 58
$_m["Invalid password for the node name:"]
    = "Neplatn� heslo pro uzel: ";

// admin/se_inter_import2.php, row 58
$_m["Contact the administrator of the local node."]
    = "Kontaktujte administr�tora lok�ln�ho uzlu.";

// admin/se_inter_import2.php, row 59
$_m["Remote server returns following error:"]
    = "Vzd�len� server hl�s� chybu:";

// admin/se_inter_import2.php, row 77, 85
// admin/se_inter_import.php, row 84, 126
$_m["Inter node import settings"]
    = "Spr�va p�ij�man�ch web�k�";

// admin/se_inter_import2.php, row 89
$_m["Choose slice"]
    = "Zvolte web�k";

// admin/se_inter_import2.php, row 98
$_m["List of available slices from the node "]
    = "Seznam dostupn�ch web�k� z uzlu ";

// admin/se_inter_import2.php, row 99
$_m["Slice to import"]
    = "Importovan� web�ky";

// admin/se_inter_import2.php, row 100
$_m["Exact copy"]
    = "P�esn� kopie";

// admin/se_inter_import2.php, row 101
$_m["The slice will be exact copy of the remote slice. All items will be copied including holdingbin and trash bin items. Also on anychange in the remote item, the content will be copied to local copy of the item. The items will have the same long ids (not the short ones!). It make no sence to change items in local copy - it will be overwriten from remote master."]
    = "Web�k bude p�esnou kopi� vzd�len�ho - kop�rovat se budou jak vystaven� �l�nky, tak i �l�nky v z�sobn�ku a ko�i. Kop�rovat se budou i ve�ker� zm�ny ve zdrojov�m web�ku. Jedin� zm�n�n� polo�ka je short_id. Ned�v� valn� smysl m�nit takto z�skan� �l�nky, proto�e je velk� pravd�podobnost, �e budou p�eps�ny ze vzd�len�ho web�ku.";

// admin/se_inputform.php, row 57
// admin/se_javascript.php, row 38
// admin/mailman_create_list.php3, row 55
// admin/se_fields.php, row 43
// admin/se_constant_import.php, row 46
// admin/se_constant.php, row 45
// admin/anonym_wizard.php, row 125
// admin/se_fieldid.php, row 134
$_m["You have not permissions to change fields settings"]
    = "Nem�te pr�vo m�nit nastaven� polo�ek";

// admin/se_inputform.php, row 72
$_m["Field delete OK"]
    = "Pole odstran�no";

// admin/se_inputform.php, row 116, 446
$_m["Before HTML code"]
    = "HTML k�d p�ed t�mto polem";

// admin/se_inputform.php, row 117, 434
$_m["Help for this field"]
    = "N�pov�da";

// admin/se_inputform.php, row 118, 440
$_m["More help"]
    = "V�ce informac�";

// admin/se_inputform.php, row 119, 379
// admin/se_admin.php, row 138
// admin/se_compact.php, row 189
// admin/se_fulltext.php, row 136
$_m["Default"]
    = "Implicitn�";

// admin/se_inputform.php, row 120
$_m["Input show function"]
    = "Zobrazovac� funkce";

// admin/se_inputform.php, row 122
$_m["Alias must be always _# + 8 UPPERCASE letters, e.g. _#SOMTHING."]
    = "Alias mus� b�t v�dy _# + 8 VELK�CH p�smen, nap�. _#MUJALIAS.";

// admin/se_inputform.php, row 128, 475
$_m["Alias"]
    = "";

// admin/se_inputform.php, row 132, 476
// admin/se_profile.php3, row 160
$_m["Function"]
    = "Funkce";

// admin/se_inputform.php, row 200
// admin/se_fields.php, row 188
$_m["Fields update successful"]
    = "Nastaven� polo�ek �sp�n� zm�n�no";

// admin/se_inputform.php, row 290, 323
// admin/se_fields.php, row 210, 226
$_m["Admin - configure Fields"]
    = "Spr�va web�ku - Nastaven� pol�";

// admin/se_inputform.php, row 296
$_m["You selected slice and not constant group. It is impossible to create slice from here."]
    = "Vybral jste web�k a ne konstanty. Odtud nelze nov� web�k vytvo�it.";

// admin/se_inputform.php, row 326
$_m["<p>WARNING: Do not change this setting if you are not sure what you're doing!</p>"]
    = "<p>POZOR: Tato nastaven� by m�l m�nit jen ten, kdo v� co d�l�!</p>";

// admin/se_inputform.php, row 336
$_m["Field properties"]
    = "Vlastnosti pol��ka";

// admin/se_inputform.php, row 348
$_m["Input type"]
    = "Typ Vstupu";

// admin/se_inputform.php, row 352
$_m["Input field type in Add / Edit item."]
    = "Typ pol��ka v P�idat / Upravit zpr�vu.";

// admin/se_inputform.php, row 355
// admin/se_constant_import.php, row 58, 113, 122
// admin/se_constant.php, row 340
$_m["Constants"]
    = "Hodnoty";

// admin/se_inputform.php, row 357
$_m["Edit|Use as new|New"]
    = "Upravit|Upravit kopii|Nov� skupina";

// admin/se_inputform.php, row 358
$_m["Choose a Constant Group or a Slice."]
    = "Vyberte Skupinu Konstant nebo Web�k.";

// admin/se_inputform.php, row 371, 388, 401, 417, 477
$_m["Parameters"]
    = "Parametry";

// admin/se_inputform.php, row 373, 390, 403, 419, 474
// admin/se_csv_import2.php, row 375
$_m["Help: Parameter Wizard"]
    = "N�pov�da: Pr�vodce Parametry";

// admin/se_inputform.php, row 386
$_m["How to generate the default value"]
    = "Jak vytvo�it implicitn� hodnotu";

// admin/se_inputform.php, row 396
$_m["Validate"]
    = "Zkontrolovat";

// admin/se_inputform.php, row 405
$_m["Stored as"]
    = "";

// admin/se_inputform.php, row 405
// admin/se_csv_import.php, row 216, 220
$_m["Text"]
    = "";

// admin/se_inputform.php, row 405
$_m["Number"]
    = "";

// admin/se_inputform.php, row 411
// admin/sliceimp.php, row 484
// include/formutil.php, row 202, 2698
$_m["Insert"]
    = "Vlo�it";

// admin/se_inputform.php, row 415
$_m["Defines how value is stored in database."]
    = "Ur�uje zp�sob ulo�en� hodnoty v datab�zi";

// admin/se_inputform.php, row 428
$_m["Show 'HTML' / 'plain text' option"]
    = "Zobrazit volbu 'HTML' / 'prost� text'";

// admin/se_inputform.php, row 430
$_m["'HTML' as default"]
    = "'HTML' implicitn�";

// admin/se_inputform.php, row 436
$_m["Shown help for this field"]
    = "N�pov�da zobrazen� pro toto pole ve vstupn�m formul��i";

// admin/se_inputform.php, row 442
$_m["Text shown after user click on '?' in input form"]
    = "N�pov�da, kter� se zobraz� po stisku '?' ve vstupn�m formul��i";

// admin/se_inputform.php, row 448
$_m["Code shown in input form before this field"]
    = "HTML k�d, kter� se zobraz� ve vstupn�m formul��i p�ed t�mto polem";

// admin/se_inputform.php, row 453
$_m["Feeding mode"]
    = "Sd�len� tohoto pole";

// admin/se_inputform.php, row 456
$_m["Should the content of this field be copied to another slice if it is fed?"]
    = "M� se kop�rovat obsah tohoto pol��ka do dal��ch web�k� p�i v�m�n� zpr�v mezi web�ky?";

// admin/se_inputform.php, row 459
$_m["ALIASES used in views to print field content (%1)"]
    = "";

// admin/se_inputform.php, row 475
$_m["8 UPPERCASE letters or _"]
    = "8 VELK�CH p�smen nebo _";

// admin/se_inputform.php, row 478
// admin/um_gedit.php, row 256
// admin/se_view.php, row 87
// include/constants.php, row 364
// include/constedit.php, row 84
// include/um_gedit.php, row 32
// include/tv_email.php, row 107, 166
$_m["Description"]
    = "Popis";

// admin/sliceexp.php, row 44
// admin/sliceimp.php, row 41
$_m["You are not allowed to export / import slices"]
    = "Nem�te pr�vo exportovat / importovat web�ky";

// admin/sliceexp.php, row 68, 145, 149
$_m["Export slice structure"]
    = "Export struktury web�ku";

// admin/sliceexp.php, row 99, 104
$_m["Date export error"]
    = "Chyba p�i exportov�n� data";

// admin/sliceexp.php, row 113
$_m["The identificator should be 16 characters long, not "]
    = "D�lka identifik�toru mus� b�t 16 znak�, a ne ";

// admin/sliceexp.php, row 129
$_m["You must select one or more slices to backup"]
    = "Mus�te vybrat n�jak� web�ky pro z�lohov�n�";

// admin/sliceexp.php, row 167
$_m["Choose, if you want export slices structure, data or both."]
    = "Zvolte, chcete-li exportovat strukturu web�ku, data nebo oboj�.";

// admin/sliceexp.php, row 168
$_m["Export structure"]
    = "Export struktury";

// admin/sliceexp.php, row 169
$_m["Export data"]
    = "Export dat";

// admin/sliceexp.php, row 170
$_m["Export views"]
    = "Export pohled�";

// admin/sliceexp.php, row 173
$_m["Use compression"]
    = "Komprimovat";

// admin/sliceexp.php, row 175
$_m["HEX output"]
    = "HEX v�stup";

// admin/sliceexp.php, row 176
$_m["Store exported data in file"]
    = "Ulo�it exportovan� data do souboru";

// admin/sliceexp.php, row 177
$_m["Convert to UTF"]
    = "";

// admin/sliceexp.php, row 180
$_m["Export data from specified dates: "]
    = "Export dat z ur�it�ch dn�: ";

// admin/sliceexp.php, row 181
$_m["From "]
    = "Od ";

// admin/sliceexp.php, row 182
// admin/se_fieldid.php, row 271
$_m["to"]
    = "do";

// admin/sliceexp.php, row 188
$_m["Choose one of two export kinds:"]
    = "Vyberte si jeden ze dvou zp�sob� exportu:";

// admin/sliceexp.php, row 194
$_m["When exporting \"to Backup\" you may choose more slices at once."]
    = "P�i exportu \"do Backupu\" si m��ete vybrat n�kolik �ablon najednou.";

// admin/sliceexp.php, row 195
$_m["Select slices which you WANT to export:"]
    = "Ozna�te web�ky, kter� CHCETE exportovat:";

// admin/sliceexp.php, row 211
$_m["When exporting \"to another ActionApps\" only the current slice will be exported and you choose its new identificator."]
    = "P�i exportu \"do jin�ho Toolkitu\" se bude exportovat pouze aktu�ln� �ablona a vy pro ni zvol�te nov� identifik�tor.";

// admin/sliceexp.php, row 212
$_m["Choose a new slice identificator exactly 16 characters long: "]
    = "Zvolte nov� identifik�tor �ablony o d�lce p�esn� 16 znak�: ";

// admin/sliceexp.php, row 218
// admin/sliceexp_text.php, row 106, 204, 211, 229, 239
$_m["Export to Backup"]
    = "Export do Backupu";

// admin/sliceexp.php, row 219
$_m["Export to another ActionApps"]
    = "Exportovat do jin� instalace ActionApps";

// admin/constants_sel.php, row 51
$_m["Editor window - item manager"]
    = "Spr�va zpr�v";

// admin/constants_sel.php, row 51
$_m["select constants window"]
    = "Filtrov�n� dle konstant";

// admin/constants_sel.php, row 128
$_m["Select constants"]
    = "Vyber konstanty";

// admin/constants_sel.php, row 135
// admin/prev_navigation.php, row 50
// include/formutil.php, row 67
$_m["OK"]
    = "";

// admin/se_admin.php, row 44
$_m["You have no permission to set configuration parameters of this slice"]
    = "Nem�te pr�vo nastavovat configura�n� parametry tohoto web�ku";

// admin/se_admin.php, row 56, 145
// admin/se_compact.php, row 57, 200
// include/constants.php, row 505, 547, 565, 614, 646, 673, 695, 735, 762, 786, 818
// include/tableviews.php, row 158
$_m["Top HTML"]
    = "Horn� HTML k�d";

// admin/se_admin.php, row 57, 148
$_m["Item format"]
    = "HTML k�d pro zobrazen� zpr�vy";

// admin/se_admin.php, row 58, 151
// admin/se_compact.php, row 58, 210
// include/constants.php, row 510, 549, 567, 618, 648, 675, 698, 740, 764, 791, 823
// include/tableviews.php, row 162
$_m["Bottom HTML"]
    = "Spodn� HTML k�d";

// admin/se_admin.php, row 59, 154
// admin/se_compact.php, row 59, 233
// admin/se_fulltext.php, row 58, 152
// include/constants.php, row 511, 550, 619, 699, 741, 765, 792, 824, 853
$_m["Remove strings"]
    = "Odstra�ovan� �et�zce";

// admin/se_admin.php, row 60, 156
// include/constants.php, row 540, 560, 640, 663, 690, 721, 752, 780, 812, 844
$_m["HTML code for \"No item found\" message"]
    = "HTML k�d m�sto \"Nenalezena ��dn� zpr�va\"";

// admin/se_admin.php, row 61
// admin/se_fulltext.php, row 59, 154
$_m["Show discussion"]
    = "Zobrazit diskusi";

// admin/se_admin.php, row 74
// admin/se_compact.php, row 92
// admin/se_fulltext.php, row 77
// admin/se_view.php, row 134, 259
$_m["Can't change slice settings"]
    = "Nepoda�ilo se zm�nit nastaven� web�ku";

// admin/se_admin.php, row 89
$_m["Admin fields update successful"]
    = "Vzheld administrativn�ch str�nek �sp�n� zm�n�n";

// admin/se_admin.php, row 115, 132
$_m["Admin - design Item Manager view"]
    = "Spr�va web�ku - Vzhled Administrace";

// admin/se_admin.php, row 143
$_m["Listing of items in Admin interface"]
    = "V�pis zpr�v v administrativn�ch str�nk�ch";

// admin/se_admin.php, row 146
// admin/se_compact.php, row 201, 225
// admin/se_fulltext.php, row 144
$_m["HTML code which appears at the top of slice area"]
    = "HTML k�d, kter� se zobraz� p�ed k�dem web�ku";

// admin/se_admin.php, row 149
// admin/se_compact.php, row 204, 228
// admin/se_fulltext.php, row 147
$_m["Put here the HTML code combined with aliases form bottom of this page\n"
."                     <br>The aliases will be substituted by real values from database when it will be posted to page"]
    = "Sem pat�� HTML k�d v kombinaci s aliasy uveden�mi dole na str�nce\n"
    ."                     <br>Aliasy budou v okam�iku zobrazov�n� na web nahrazeny skute�n�mi hodnotami z datab�ze";

// admin/se_admin.php, row 152
// admin/se_compact.php, row 211, 231
// admin/se_fulltext.php, row 150
$_m["HTML code which appears at the bottom of slice area"]
    = "HTML k�d, kter� se zobraz� za vlasn�m k�dem web�ku";

// admin/se_admin.php, row 155
// admin/se_compact.php, row 234
// admin/se_fulltext.php, row 153
$_m["Removes empty brackets etc. Use ## as delimiter."]
    = "Odstran� pr�zdn� z�vorky atd. Pou�ijte ## jako odd�lova�.";

// admin/se_admin.php, row 157
$_m["Code to be printed when no item is filled (or user have no permission to any item in the slice)"]
    = "Text zobrazen� nen�-li vlo�ena ��dn� zpr�va (nebo u�ivatel nem� pr�vo ��dnou zpr�vu editovat)";

// admin/se_admin.php, row 159
$_m["Use special view"]
    = "Pou��t speci�ln� pohled";

// admin/se_admin.php, row 160
$_m["You can set special view - template for the Inputform on \"Design\" -> \"View\" page (inputform view)"]
    = "Je mo�n� pou��s speci�ln�ho pohledu jako �ablony pro vstupn� formul�� - viz \"Design\" -> \"View\" - (inputform view)";

// admin/sliceexp_text.php, row 108
$_m["Wrong slice ID length: "]
    = "Chybn� d�lka ID web�ku: ";

// admin/sliceexp_text.php, row 200
$_m["Can't create temporary file."]
    = "";

// admin/sliceexp_text.php, row 231
$_m["Wrong slice ID length:"]
    = "Chybn� d�lka ID web�ku:";

// admin/sliceexp_text.php, row 308
$_m["Save this text. You may use it to import the slices into any ActionApps:"]
    = "Tento text si n�kde ulo�te. M��ete ho pou��t pro naimportov�n� �ablony do Toolkitu:";

// admin/se_mapping.php, row 93
// admin/se_filters.php, row 75
$_m["There are no imported slices"]
    = "Nen� nastaven ��dn� web�k, ze kter�ho se maj� p�ij�mat zpr�vy";

// admin/se_mapping.php, row 115, 115, 236
// admin/se_mapping2.php, row 64
$_m["-- Not map --"]
    = "-- Nemapovat --";

// admin/se_mapping.php, row 116, 116, 230
// admin/se_mapping2.php, row 67
$_m["-- Value --"]
    = "-- Hodnota --";

// admin/se_mapping.php, row 117, 117, 233
// admin/se_mapping2.php, row 70
$_m["-- Joined fields --"]
    = "-- Spojen� pol� --";

// admin/se_mapping.php, row 118, 118, 244
// admin/se_mapping2.php, row 73
$_m["-- RSS field or expr --"]
    = "-- v�raz pro RSS --";

// admin/se_mapping.php, row 139, 192
$_m["Admin - Content Pooling - Fields' Mapping"]
    = "Spr�va web�ku - v�m�na zpr�v - mapov�n� pol�";

// admin/se_mapping.php, row 206
$_m["Content Pooling - Fields' mapping"]
    = "V�m�na zpr�v - mapov�n� pol�";

// admin/se_mapping.php, row 209
$_m["Mapping from slice"]
    = "Mapov�n� z web�ku";

// admin/se_mapping.php, row 213
$_m["Fields' mapping"]
    = "Mapov�n� pol�";

// admin/se_mapping.php, row 218
// admin/se_csv_import2.php, row 353
// admin/se_filters.php, row 277
$_m["To"]
    = "Do";

// admin/se_mapping.php, row 219
// admin/se_csv_import2.php, row 354
// admin/se_filters.php, row 276
// include/tv_email.php, row 176
$_m["From"]
    = "Z";

// admin/se_mapping.php, row 220
// admin/se_profile.php3, row 161
// admin/se_history.php, row 72, 85
// admin/se_constant.php, row 182, 414
// include/constants.php, row 358
// include/constedit.php, row 76
// include/searchlib.php, row 127
$_m["Value"]
    = "Hodnota";

// admin/se_javascript.php, row 63, 70
// include/menu.php, row 166
$_m["Field Triggers"]
    = "Javascript pro pol��ka";

// admin/se_javascript.php, row 76
$_m["JavaScript for fields"]
    = "JavaScript pro pol��ka";

// admin/se_javascript.php, row 78
$_m["Enter code in the JavaScript language. It will be included in the Add / Edit item page (itemedit.php3)."]
    = "Vlo�te k�d v JavaScriptu. Bude zahrnut na str�nce P�idat / Upravit zpr�vu (itemedit.php3).";

// admin/se_javascript.php, row 83
$_m["Available fields and triggers"]
    = "Dostupn� pol��ka a triggery";

// admin/se_javascript.php, row 95
$_m["Field IDs"]
    = "ID pol��ek";

// admin/se_javascript.php, row 102
$_m["Triggers"]
    = "Triggery";

// admin/se_javascript.php, row 103
$_m["Write trigger functions like"]
    = "Pi�te triggery jako nap�.";

// admin/se_javascript.php, row 103
$_m["see FAQ</a> for more details and examples"]
    = "�t�te FAQ</a> s dal��mi detaily a p��klady";

// admin/se_javascript.php, row 105
$_m["Field Type"]
    = "Typ Pol��ka";

// admin/se_javascript.php, row 105
$_m["Triggers Available -- see some JavaScript help for when a trigger is run"]
    = "Dostupn� Triggery -- v dokumentaci JavaScriptu zjist�te, kdy je kter� spou�t�n";

// admin/se_profile.php3, row 42
// admin/se_users.php, row 38
$_m["You have not permissions to manage users"]
    = "Nem�te pr�vo ke spr�v� u�ivatel�";

// admin/se_profile.php3, row 57
// include/profile.class.php, row 303
$_m["Rule deleted"]
    = "Pravidlo �sp�n� vymaz�no";

// admin/se_profile.php3, row 62
$_m["Error: Can't add rule"]
    = "Chyba p�i p�id�v�n� nov�ho pravidla";

// admin/se_profile.php3, row 82
$_m["Item number"]
    = "Po�et zpr�v";

// admin/se_profile.php3, row 83
$_m["Input view ID"]
    = "Pohled pro vstup";

// admin/se_profile.php3, row 84
$_m["Item filter"]
    = "Filtr zpr�v";

// admin/se_profile.php3, row 85
$_m["Item order"]
    = "�azen�";

// admin/se_profile.php3, row 86
$_m["Item permissions"]
    = "";

// admin/se_profile.php3, row 87
$_m["Hide field"]
    = "Skr�t pol��ko";

// admin/se_profile.php3, row 88
$_m["Hide and Fill"]
    = "Skr�t a vyplnit";

// admin/se_profile.php3, row 89
// admin/search_replace.php, row 761
$_m["Fill field"]
    = "Vyplnit pol��ko";

// admin/se_profile.php3, row 90
$_m["Predefine field"]
    = "P�ednastavit pol���ko";

// admin/se_profile.php3, row 91
$_m["Stored query"]
    = "Ulo�en� filtr";

// admin/se_profile.php3, row 92
$_m["UI - manager"]
    = "";

// admin/se_profile.php3, row 93
$_m["UI - manager - hide"]
    = "";

// admin/se_profile.php3, row 94
$_m["UI - inputform"]
    = "";

// admin/se_profile.php3, row 95
$_m["UI - inputform - hide"]
    = "";

// admin/se_profile.php3, row 98
// admin/se_compact.php, row 219
// admin/se_view.php, row 154, 155
$_m["Ascending"]
    = "Vzestupn�";

// admin/se_profile.php3, row 98
// admin/se_compact.php, row 219
// admin/se_view.php, row 154, 155
// include/searchbar.class.php, row 606
$_m["Descending"]
    = "Sestupn�";

// admin/se_profile.php3, row 102, 128
$_m["Admin - user Profiles"]
    = "Spr�va web�ku - U�ivatelsk� profily";

// admin/se_profile.php3, row 135
$_m["Rules"]
    = "Nastaven� pravidla";

// admin/se_profile.php3, row 146
$_m["No rule is set"]
    = "��dn� pravidlo nebylo definov�no";

// admin/se_profile.php3, row 151
$_m["Add Rule"]
    = "P�idat pravidlo";

// admin/se_profile.php3, row 158
$_m["Rule"]
    = "Pravidlo";

// admin/se_profile.php3, row 159
// admin/se_search.php3, row 147, 164
// admin/se_fields.php, row 119, 240
// admin/search_replace.php, row 762
// admin/anonym_wizard.php, row 208
// admin/se_fieldid.php, row 305
$_m["Field"]
    = "Polo�ka";

// admin/se_profile.php3, row 162
// admin/search_replace.php, row 262, 335, 419, 570
// include/formutil.php, row 1151, 1168
$_m["HTML"]
    = "";

// admin/se_profile.php3, row 168
$_m["Logo (top)"]
    = "";

// admin/se_profile.php3, row 169
$_m["View site (top)"]
    = "";

// admin/se_profile.php3, row 170
$_m["Add Item link (top)"]
    = "";

// admin/se_profile.php3, row 171
$_m["Item Manager link (top)"]
    = "";

// admin/se_profile.php3, row 172
$_m["Slice Admin link (top)"]
    = "";

// admin/se_profile.php3, row 173
$_m["AA link (top)"]
    = "";

// admin/se_profile.php3, row 174
$_m["Central link (top)"]
    = "";

// admin/se_profile.php3, row 175
$_m["Title (top)"]
    = "";

// admin/se_profile.php3, row 176
$_m["Logout link (top)"]
    = "";

// admin/se_profile.php3, row 177
$_m["User Info link (top)"]
    = "";

// admin/se_profile.php3, row 178
$_m["Module Selectbox (top)"]
    = "";

// admin/se_profile.php3, row 179
$_m["Module Switch text (top)"]
    = "";

// admin/se_profile.php3, row 180
$_m["Item Manager Menu: Header (left)"]
    = "";

// admin/se_profile.php3, row 181
$_m["Item Manager Menu: Add Item (left)"]
    = "";

// admin/se_profile.php3, row 182
$_m["Item Manager Menu: Active (left)"]
    = "";

// admin/se_profile.php3, row 183
$_m["Item Manager Menu: Pending (left)"]
    = "";

// admin/se_profile.php3, row 184
$_m["Item Manager Menu: Expired (left)"]
    = "";

// admin/se_profile.php3, row 185
$_m["Item Manager Menu: Holding (left)"]
    = "";

// admin/se_profile.php3, row 186
$_m["Item Manager Menu: Trash (left)"]
    = "";

// admin/se_profile.php3, row 187
$_m["Item Manager Menu: Bookmarks show (left)"]
    = "";

// admin/se_profile.php3, row 188
$_m["Item Manager Menu: Additional 1 (left)"]
    = "";

// admin/se_profile.php3, row 189
$_m["Item Manager Menu: Header 2 (left)"]
    = "";

// admin/se_profile.php3, row 190
$_m["Item Manager Menu: Slice Setting (left)"]
    = "";

// admin/se_profile.php3, row 191
$_m["Item Manager Menu: Empty Trash (left)"]
    = "";

// admin/se_profile.php3, row 192
$_m["Item Manager Menu: CSV Import (left)"]
    = "";

// admin/se_profile.php3, row 193
$_m["Item Manager Menu: Debug (left)"]
    = "";

// admin/se_profile.php3, row 194
$_m["Item Manager Menu: Additional 2 (left)"]
    = "";

// admin/se_profile.php3, row 195
$_m["Add CSS file"]
    = "";

// admin/se_profile.php3, row 198
$_m["Manager Actions"]
    = "";

// admin/se_profile.php3, row 199
$_m["Searchbar - Search Rows"]
    = "";

// admin/se_profile.php3, row 200
$_m["Searchbar - Order Rows"]
    = "";

// admin/se_profile.php3, row 201
$_m["Searchbar - Boomarks"]
    = "";

// admin/se_profile.php3, row 205
$_m["Title (add form)"]
    = "";

// admin/se_profile.php3, row 206
$_m["Top HTML code (add form)"]
    = "";

// admin/se_profile.php3, row 207
$_m["Bottom HTML code (add form)"]
    = "";

// admin/se_profile.php3, row 208
$_m["Button \"Insert\" (add form)"]
    = "";

// admin/se_profile.php3, row 209
$_m["Button \"Insert & View\" (add form)"]
    = "";

// admin/se_profile.php3, row 210
$_m["Button \"Cancel\" (add form)"]
    = "";

// admin/se_profile.php3, row 211
$_m["Title (edit form)"]
    = "";

// admin/se_profile.php3, row 212
$_m["Top HTML code (edit form)"]
    = "";

// admin/se_profile.php3, row 213
$_m["Bottom HTML code (edit form)"]
    = "";

// admin/se_profile.php3, row 214
$_m["Button \"Update\" (edit form)"]
    = "";

// admin/se_profile.php3, row 215
$_m["Button \"Update & View\" (edit form)"]
    = "";

// admin/se_profile.php3, row 216
$_m["Button \"Insert as new\" (edit form)"]
    = "";

// admin/se_profile.php3, row 217
$_m["Button \"Reset form\" (edit form)"]
    = "";

// admin/se_profile.php3, row 218
$_m["Button \"Cancel\" (edit form)"]
    = "";

// admin/se_profile.php3, row 222
$_m["Author Role"]
    = "";

// admin/se_profile.php3, row 223
$_m["Editor Role"]
    = "";

// admin/se_profile.php3, row 224
$_m["Adminostrator Role"]
    = "";

// admin/se_profile.php3, row 225
$_m["Superadmin Role"]
    = "";

// admin/se_profile.php3, row 230
$_m["number of item displayed in Item Manager"]
    = "po�et zpr�v zobrazen�ch v administraci";

// admin/se_profile.php3, row 231
$_m["id of view used for item input"]
    = "id pohledu pou�it�ho pro vstupn� formul��";

// admin/se_profile.php3, row 232
$_m["preset \"Search\" in Item Manager"]
    = "";

// admin/se_profile.php3, row 233
$_m["preset \"Order\" in Item Manager"]
    = "";

// admin/se_profile.php3, row 234
$_m["ID of \"Item Set\" which defines the permissions for item - see \"Admin - Item Set\""]
    = "";

// admin/se_profile.php3, row 235
$_m["hide the field in inputform"]
    = "sk�t pol��ko ve vstupn�m forul��i";

// admin/se_profile.php3, row 236
$_m["hide the field in inputform and fill it by the value"]
    = "sk�t pol��ko ve vstupn�m forul��i a vyplnit je danou hodnotou";

// admin/se_profile.php3, row 237
$_m["fill the field in inputform by the value"]
    = "vyplnit pol��ko ve vstupn�m forul��i v�dy danou hodnotou";

// admin/se_profile.php3, row 238
$_m["predefine value of the field in inputform"]
    = "p�ednastavit hodnotu do pol��ka ve vstupn�m formul��i";

// admin/se_profile.php3, row 239
$_m["redefine manager UI - (empty values = do not show)"]
    = "";

// admin/se_profile.php3, row 240
$_m["hide this UI element"]
    = "";

// admin/se_profile.php3, row 241
$_m["redefine inputform UI - (empty values = do not show)"]
    = "";

// admin/se_users.php, row 99
// admin/discedit.php, row 133
// admin/discedit2.php, row 54, 122
// admin/se_users_add.php, row 48
$_m["Author"]
    = "Autor";

// admin/se_users.php, row 101
// admin/slicewiz.php, row 81
// admin/se_users_add.php, row 51
$_m["Editor"]
    = "";

// admin/se_users.php, row 103
// admin/se_users_add.php, row 54
$_m["Administrator"]
    = "Administr�tor";

// admin/se_users.php, row 105
// include/um_util.php, row 100, 162
$_m["Revoke"]
    = "Odstranit";

// admin/se_users.php, row 107, 173
$_m["Profile"]
    = "Profil";

// admin/se_users.php, row 117, 124
$_m["Admin - Permissions"]
    = "Spr�va web�ku - P��stupov� pr�va";

// admin/se_users.php, row 137
$_m["Change current permisions"]
    = "Zm�na pr�v u�ivatel�";

// admin/se_users.php, row 172
$_m["Default user profile"]
    = "Spole�n� profil";

// admin/tabledit.php, row 63
// admin/aarsstest.php, row 38
// admin/rsstest.php, row 39
// central/tabledit.php, row 46
$_m["You have not permissions to this page"]
    = "Nem�te pr�vo p�istupovat k t�to str�nce";

// admin/mailman_create_list.php3, row 61, 67
$_m["Admin - Create Mailman List"]
    = "Administrace - Vytvo�it Distribu�n� seznam pro Mailmana";

// admin/mailman_create_list.php3, row 74
$_m["First set Mailman Lists Field in Slice Settings."]
    = "Nejd��v nastavte Pol��ko se Seznamy Mailmana v Nastaven� Web�ku.";

// admin/mailman_create_list.php3, row 97
$_m["Error: This list name is already used."]
    = "Chyba: Tento n�zev seznamu je u� pou�it.";

// admin/mailman_create_list.php3, row 129
$_m["The list was successfully created."]
    = "Seznam byl �sp�n� vytvo�en.";

// admin/mailman_create_list.php3, row 150
$_m["List Settings"]
    = "Nastaven� Seznamu";

// admin/mailman_create_list.php3, row 152
$_m["The list will be added to mailman and also\n"
."    to the constant group for the field %1 selected as Mailman Lists Field in Slice Settings."]
    = "";

// admin/mailman_create_list.php3, row 153
$_m["All the fields are required."]
    = "V�echna pol��ka jsou povinn�.";

// admin/mailman_create_list.php3, row 154
$_m["List name"]
    = "N�zev seznamu";

// admin/mailman_create_list.php3, row 156
$_m["Admin email"]
    = "Email Administr�tora";

// admin/mailman_create_list.php3, row 158
$_m["Admin password"]
    = "Heslo Administr�tora";

// admin/mailman_create_list.php3, row 162
// admin/setup.php, row 111, 230
$_m["Create"]
    = "Vytvo�";

// admin/write_mail.php, row 56, 144
// admin/discedit2.php, row 53, 121
// include/tv_email.php, row 114, 168
$_m["Subject"]
    = "P�edm�t";

// admin/write_mail.php, row 57, 145
// include/tv_email.php, row 118, 170
$_m["Body"]
    = "Text";

// admin/write_mail.php, row 58, 146
// include/tv_email.php, row 122
$_m["From (email)"]
    = "";

// admin/write_mail.php, row 59, 147
// include/tv_email.php, row 124
$_m["Reply to (email)"]
    = "";

// admin/write_mail.php, row 60, 148
// include/tv_email.php, row 126
$_m["Errors to (email)"]
    = "";

// admin/write_mail.php, row 61, 149
// include/tv_email.php, row 128
$_m["Sender (email)"]
    = "Odes�latel (email)";

// admin/write_mail.php, row 62, 150
// include/tv_email.php, row 130
$_m["Language (charset)"]
    = "Jazyk (charset)";

// admin/write_mail.php, row 63, 151
// include/tv_email.php, row 134
$_m["Use HTML"]
    = "Pou��t HTML";

// admin/write_mail.php, row 79
$_m["No template set (which is strange - template was just written to the database"]
    = "Nen� nastavena �ablona (co� je divn� - �ablona pr�v� byla zaps�na do datab�ze)";

// admin/write_mail.php, row 95
$_m["Email sucessfully sent (Users: %1, Emails sent (valid e-mails...): %2)"]
    = "E-mail byl usp�n� posl�n (U�ivatel�: %1, Poslan�ch (validn�ch) e-mail�: %2)";

// admin/write_mail.php, row 110
$_m["Can't delete email template"]
    = "Nelze vymazat �ablonu e-mailu";

// admin/write_mail.php, row 121
$_m["Write email to users"]
    = "Hromadn� e-mail u�ivatel�m";

// admin/write_mail.php, row 126
$_m["Bulk Email Wizard"]
    = "Zasl�n� hromadn�ho e-mailu";

// admin/write_mail.php, row 134
$_m["Recipients"]
    = "P��jemci";

// admin/write_mail.php, row 134
// admin/search_replace.php, row 755
$_m["Stored searches for "]
    = "Ulo�en� filtry pro ";

// admin/write_mail.php, row 136
$_m["View Recipients"]
    = "Zobraz p��jemce";

// admin/write_mail.php, row 137
$_m["Selected users"]
    = "Vybran� u�ivatel�";

// admin/write_mail.php, row 138
$_m["Test email address"]
    = "E-mailov� adresa pro testov�n�";

// admin/write_mail.php, row 142
$_m["Write the email"]
    = "Napi�te e-mail";

// admin/write_mail.php, row 151
// admin/slicewiz.php, row 66
// include/tabledit_column.php, row 158, 161
// include/tableviews.php, row 176
$_m["no"]
    = "ne";

// admin/write_mail.php, row 151
// admin/slicewiz.php, row 66
// include/tabledit_column.php, row 158, 161
// include/tableviews.php, row 176
$_m["yes"]
    = "ano";

// admin/write_mail.php, row 153
// admin/se_rssfeeds.php, row 205
// admin/anonym_wizard.php, row 103
$_m["Send"]
    = "Po�li";

// admin/write_mail.php, row 154
// admin/search_replace.php, row 768
// admin/usershow.php, row 154
$_m["Close"]
    = "Zav��t";

// admin/se_inter_export.php, row 70, 102, 110
$_m["Inter node export settings"]
    = "Spr�va povolen� zas�l�n� web�k�";

// admin/se_inter_export.php, row 84
$_m["No selected export"]
    = "";

// admin/se_inter_export.php, row 87
$_m["Are you sure you want to delete the export?"]
    = "";

// admin/se_inter_export.php, row 112
$_m["Existing exports of the slice "]
    = "Seznam uzl� a u�ivatel�, kam bude zas�l�n web�k ";

// admin/se_inter_export.php, row 133
$_m["Insert new item"]
    = "P�idejte uzel a u�ivatele";

// admin/se_inter_export.php, row 135
$_m["Remote Nodes"]
    = "Seznam uzl�";

// admin/se_inter_export.php, row 146
$_m["User name"]
    = "Jm�no u�ivatele";

// admin/itemedit.php3, row 90
// admin/related_sel.php, row 181
$_m["There are too many related items. The number of related items is limited."]
    = "Je vybr�no p��li� mnoho souvisej�c�ch �l�nk�.";

// admin/itemedit.php3, row 232
// admin/slicefieldsedit.php, row 128
$_m["Error: no fields."]
    = "Chyba: ��dn� pol��ko.";

// admin/itemedit.php3, row 240
// admin/slicefieldsedit.php, row 136
$_m["Bad item ID id=%1"]
    = "�patn� ��slo �l�nku id=%1";

// admin/itemedit.php3, row 252
$_m["Error: You have no rights to edit item."]
    = "Nem�te pr�va m�nit �l�nek";

// admin/itemedit.php3, row 284
// include/menu.php, row 73
$_m["Add Item"]
    = "P�idat zpr�vu";

// admin/itemedit.php3, row 284
$_m["Edit Item"]
    = "Upravit zpr�vu";

// admin/se_sets.php, row 44
$_m["You have not permissions to change sets"]
    = "";

// admin/se_sets.php, row 52
$_m["Name 1"]
    = "";

// admin/se_sets.php, row 53
// include/constants.php, row 530, 551, 630, 653, 680, 711, 770, 802, 834
$_m["Condition 1"]
    = "Podm�nka 1";

// admin/se_sets.php, row 54
$_m["Object ID 1"]
    = "";

// admin/se_sets.php, row 56
$_m["Name 2"]
    = "";

// admin/se_sets.php, row 57
// include/constants.php, row 533, 554, 633, 656, 683, 714, 773, 805, 837
$_m["Condition 2"]
    = "Podm�nka 2";

// admin/se_sets.php, row 58
$_m["Object ID 2"]
    = "";

// admin/se_sets.php, row 60
$_m["Name 3"]
    = "";

// admin/se_sets.php, row 61
// include/constants.php, row 536, 557, 636, 659, 686, 717, 776, 808, 840
$_m["Condition 3"]
    = "Podm�nka 3";

// admin/se_sets.php, row 62
$_m["Object ID 3"]
    = "";

// admin/se_sets.php, row 64
$_m["Name 4"]
    = "";

// admin/se_sets.php, row 65
$_m["Condition 4"]
    = "";

// admin/se_sets.php, row 66
$_m["Object ID 4"]
    = "";

// admin/se_sets.php, row 117
$_m["Sets stored successfully"]
    = "";

// admin/se_sets.php, row 123, 130
$_m["Admin - Item Sets"]
    = "";

// admin/se_sets.php, row 141
$_m["Sets"]
    = "";

// admin/se_sets.php, row 142
$_m["Conditions are in \"d-...\" or \"conds[]\" form - just like:<br> &nbsp; d-headline........,category.......1-BEGIN-Bio (d-&lt;fields&gt;-&lt;operator&gt;-&lt;value&gt;-&lt;fields&gt;-&lt;op...)<br> &nbsp; conds[0][category........]=first&conds[1][switch.........1]=1 (default operator is RLIKE, here!)"]
    = "";

// admin/se_sets.php, row 153
$_m["ID"]
    = "";

// admin/se_sets.php, row 154, 159
$_m["Set name %1"]
    = "";

// admin/se_sets.php, row 154, 159
$_m["use alphanumeric characters only"]
    = "";

// admin/se_sets.php, row 155, 160
$_m["Conditions %1"]
    = "";

// admin/se_sets.php, row 155, 160
$_m["Use \"d-...\" or \"conds[]\" conditions"]
    = "";

// admin/feed_to.php, row 54
$_m["Export Item to Selected Slice"]
    = "P�edat zpr�vu do web�ku";

// admin/feed_to.php, row 59
$_m["Export selected items to selected slice"]
    = "P�edat vybran� zpr�vy do zvolen�ch web�ku";

// admin/feed_to.php, row 62
// admin/slicedit.php, row 148
// include/constants.php, row 152
// include/menu_util.php, row 70
// include/menu.php, row 136
// include/sliceadd.php, row 55, 84
$_m["Slice"]
    = "Web�k";

// admin/feed_to.php, row 63
$_m["Holding bin"]
    = "Z�sobn�k";

// admin/feed_to.php, row 64
// admin/slicedit.php, row 42
// admin/se_filters.php, row 278
// include/menu.php, row 187, 243
$_m["Active"]
    = "Aktu�ln�";

// admin/feed_to.php, row 65
$_m["Do not export to this slice"]
    = "Neexportovat do web�ku";

// admin/feed_to.php, row 81
$_m["No permission to set feeding for any slice"]
    = "Nem�te pr�vo nastavit v�m�nu zpr�v s ��dn�m web�kem";

// admin/feed_to.php, row 84
// include/actions.php, row 146
// include/menu_aa.php3, row 55
$_m["Export"]
    = "V�m�na zpr�v";

// admin/index.php3, row 94
$_m["You do not have permission to edit items in the slice:"]
    = "Nem�te pr�vo editovat �l�nky ve web�ku:";

// admin/index.php3, row 162
// admin/se_history.php, row 213
$_m["ActionApps - Reader Manager"]
    = "ActionApps - Spr�vce �ten���";

// admin/index.php3, row 163
$_m["ActionApps - Item Manager"]
    = "ActionApps - Spr�vce zpr�v";

// admin/sliceimp_xml.php, row 131
$_m["\n"
."ERROR: File doesn't contain SLICEEXPORT"]
    = "";

// admin/sliceimp_xml.php, row 146, 172, 210
$_m["ERROR: Text is not OK. Check whether you copied it well from the Export."]
    = "";

// admin/sliceimp_xml.php, row 222
$_m["ERROR: Unsupported version for import"]
    = "";

// admin/sliceimp_xml.php, row 281
// admin/sliceimp.php, row 93, 140, 483
$_m["Overwrite"]
    = "P�epsat";

// admin/sliceimp_xml.php, row 284
$_m["<br>Overwriting view %1"]
    = "";

// admin/sliceimp_xml.php, row 293
// admin/se_view.php, row 264
$_m["Can't insert into view."]
    = "Nemohu vlo�it do view.";

// admin/aarsstest.php, row 66
// admin/rsstest.php, row 67
$_m["feed"]
    = "";

// admin/aarsstest.php, row 67
// admin/rsstest.php, row 68
$_m["validate"]
    = "validovat";

// admin/aarsstest.php, row 68
// admin/rsstest.php, row 69
$_m["show"]
    = "zobrazit";

// admin/aarsstest.php, row 101, 102
$_m["ActionApps RSS Content Exchange"]
    = "";

// admin/aarsstest.php, row 103
// admin/rsstest.php, row 106
$_m["RSS feeds testing page."]
    = "";

// admin/aarsstest.php, row 105
$_m["No ActionApps RSS Exchange is set."]
    = "";

// admin/aarsstest.php, row 111
// admin/discedit.php, row 139
// admin/rsstest.php, row 114
// include/formutil.php, row 1659
// include/manager.class.php, row 198
$_m["Actions"]
    = "Akce";

// admin/aarsstest.php, row 114
$_m["Newest Item"]
    = "";

// admin/aarsstest.php, row 115
$_m["change this value if you want to get older items"]
    = "";

// admin/aarsstest.php, row 118
// admin/rsstest.php, row 117
$_m["Messages"]
    = "";

// admin/aarsstest.php, row 123
// admin/rsstest.php, row 122
$_m["Write"]
    = "";

// admin/aarsstest.php, row 124
// admin/rsstest.php, row 123
$_m["update database"]
    = "";

// admin/aarsstest.php, row 129
// admin/rsstest.php, row 131
$_m["Node"]
    = "Uzel";

// admin/aarsstest.php, row 132
$_m["Remote slice"]
    = "Vzd�len� web�k";

// admin/aarsstest.php, row 135
$_m["Remote slice ID"]
    = "ID vzd�len�ho web�ku";

// admin/aarsstest.php, row 138
$_m["Local slice ID"]
    = "ID lok�ln�ho web�ku";

// admin/aarsstest.php, row 141
$_m["Feed mode"]
    = "";

// admin/aarsstest.php, row 146
// admin/rsstest.php, row 128
$_m["Feed url"]
    = "";

// admin/se_import2.php, row 134, 136
// admin/se_filters2.php, row 163
$_m["Content Pooling update successful"]
    = "Nastaven� v�m�ny zpr�v �sp�n� zm�n�no";

// admin/aa_synchronize_remote.php3, row 31
// admin/aa_synchronize.php3, row 32
// admin/aa_synchronize2.php3, row 34
// central/synchronize.php, row 31
// central/synchronize2.php, row 30
// central/synchronize_remote.php, row 27
// central/copyslice.php, row 31
// central/copyslice2.php, row 30
// central/synchronize3.php, row 48
$_m["You don't have permissions to synchronize slices."]
    = "";

// admin/aa_synchronize_remote.php3, row 40
// central/synchronize_remote.php, row 36
$_m["No such command"]
    = "";

// admin/se_inter_import3.php, row 55
$_m["The import was already created"]
    = "P��jem z web�ku byl ji� vytvo�en";

// admin/se_inter_import3.php, row 116
$_m["The import was successfully created"]
    = "P��jem z web�ku �sp�n� vytvo�en";

// admin/slicefieldsedit.php, row 149
$_m["Slice Setting"]
    = "Nastaven� web�ku";

// admin/se_history.php, row 43
$_m["You have not permissions to view history"]
    = "";

// admin/se_history.php, row 65
// admin/se_fields.php, row 241
// admin/slicedit.php, row 150
// admin/se_view.php, row 391
// admin/anonym_wizard.php, row 209
// admin/se_fieldid.php, row 304
// include/constants.php, row 363
// include/scroller.php, row 80
// include/tableviews.php, row 130, 144
$_m["Id"]
    = "";

// admin/se_history.php, row 66
$_m["Resource ID"]
    = "";

// admin/se_history.php, row 67
// include/fileman.php, row 34
// include/widget.class.php, row 458
$_m["Type"]
    = "Typ";

// admin/se_history.php, row 69
$_m["Time"]
    = "";

// admin/se_history.php, row 70
$_m["Selector"]
    = "";

// admin/se_history.php, row 71
// admin/se_fields.php, row 120, 242
// admin/se_constant.php, row 183, 415
// include/constants.php, row 359
// include/constedit.php, row 82
$_m["Priority"]
    = "�azen�";

// admin/se_history.php, row 73
$_m["Value Type"]
    = "";

// admin/se_history.php, row 83
$_m["Change ID"]
    = "";

// admin/se_history.php, row 84
$_m["Field selector"]
    = "";

// admin/se_history.php, row 86
$_m["Type of value"]
    = "";

// admin/se_history.php, row 87
$_m["Time of change"]
    = "";

// admin/se_history.php, row 89
$_m["Item ID"]
    = "";

// admin/discedit.php, row 61
$_m["You don't have permissions to edit all items."]
    = "Nem�te pr�vo pro editaci v�ech zpr�v.";

// admin/discedit.php, row 99
$_m["Admin - Discussion comments management"]
    = "Administrace - Spr�va diskusn�ch p��sp�vk�";

// admin/discedit.php, row 104
$_m["Are you sure you want to delete selected comment?"]
    = "Opravu chcete smazat p��sp�vek?";

// admin/discedit.php, row 115
$_m["Discussion comments management"]
    = "Spr�va zpr�v - Spr�va diskusn�ch p��sp�vk�";

// admin/discedit.php, row 125
$_m["Item: "]
    = "�l�nek: ";

// admin/discedit.php, row 131
// admin/slicedit.php, row 151
// include/modutils.php, row 72
// include/slicedit.php, row 154
$_m["Title"]
    = "Titulek";

// admin/discedit.php, row 135
$_m["IP Address"]
    = "";

// admin/discedit.php, row 137
// admin/discedit2.php, row 125
// include/widget.class.php, row 706
$_m["Date"]
    = "Datum";

// admin/discedit.php, row 148
$_m["No discussion comments"]
    = "��dn� diskusn� p��sp�vky";

// admin/discedit.php, row 176
$_m["Hide"]
    = "Skr�t";

// admin/discedit.php, row 176
$_m["Approve"]
    = "Schv�lit";

// admin/discedit.php, row 186
// admin/related_sel.php, row 202
// admin/se_users_add.php, row 60
// include/util.php, row 1383
// include/item.php, row 1583
// include/msgpage.php, row 84
$_m["Back"]
    = "Zp�t";

// admin/se_search.php3, row 38
$_m["You have not permissions to change search settings"]
    = "Nem�te pr�vo m�nit nastaven� vyhled�v�n�";

// admin/se_search.php3, row 117
$_m["Search fields update successful"]
    = "Nastaven� vyhled�vac�ho formul��e �sp�n� zm�n�no";

// admin/se_search.php3, row 130, 136
$_m["Admin - design Search Page"]
    = "Spr�va web�ku - Vyhled�vac� formul��";

// admin/se_search.php3, row 142
$_m["Search form criteria"]
    = "Vyhled�vac� krit�ria";

// admin/se_search.php3, row 147, 165
// admin/se_fields.php, row 244
// admin/anonym_wizard.php, row 210
// admin/se_views.php, row 77
$_m["Show"]
    = "Zobrazit";

// admin/se_search.php3, row 159
$_m["Search in fields"]
    = "Vyhled�vat v polo�k�ch";

// admin/se_search.php3, row 166
$_m["Default settings"]
    = "Standardni nastaven�";

// admin/se_search.php3, row 181
// admin/sliceimp.php, row 486, 521
// admin/discedit2.php, row 138
// admin/se_newuser.php, row 128
// include/formutil.php, row 200, 204, 2720
// include/widget.class.php, row 285
$_m["Cancel"]
    = "Storno";

// admin/se_inter_import.php, row 73
$_m["Missing!!!"]
    = "Neexistuje!!!";

// admin/se_inter_import.php, row 98
$_m["No selected import"]
    = "";

// admin/se_inter_import.php, row 101
$_m["Are you sure you want to delete the import?"]
    = "";

// admin/se_inter_import.php, row 111
// admin/se_nodes.php, row 112
$_m["No selected node"]
    = "";

// admin/se_inter_import.php, row 129
$_m["Create new feed from node"]
    = "P�idej v�m�nu z uzlem ...";

// admin/se_inter_import.php, row 134
$_m["Existing remote imports into the slice"]
    = "Existuj�c� importy do tohoto web�ku";

// admin/se_inter_import.php, row 135
$_m["Imported slices"]
    = "Importovan� web�ky";

// admin/se_inter_import.php, row 135
$_m["feeds prefixed by (=) are \"exact copy\" feeds"]
    = "(=) ozna�uje v�m�nu, kde je nastavena \"p�esn� kopie\"";

// admin/se_inter_import.php, row 136
$_m["All remote nodes"]
    = "Seznam uzl�";

// admin/se_inter_import.php, row 137
$_m["Remote node"]
    = "Vzd�len� web�k";

// admin/um_gedit.php, row 102
// include/um_gedit.php, row 49
$_m["Group successfully added to permission system"]
    = "Skupina byla �sp�n� p�id�na";

// admin/um_gedit.php, row 111
$_m["User management - Groups"]
    = "Spr�va u�ivatel� - Skupiny";

// admin/um_gedit.php, row 121
$_m["Are you sure you want to delete selected group from whole permission system?"]
    = "Opravdu chcete vymazat celou skupinu ze syst�mu?";

// admin/um_gedit.php, row 158
// include/menu_aa.php3, row 52
$_m["New Group"]
    = "Nov� skupina";

// admin/um_gedit.php, row 158
// include/menu_aa.php3, row 51
$_m["Edit Group"]
    = "Editace skupiny";

// admin/um_gedit.php, row 184
// admin/setup.php, row 167
// include/um_gsrch.php, row 50
// include/constants.php, row 360
// include/perm_emailsql.php3, row 166, 523
// include/perm_sql.php, row 177
$_m["Group"]
    = "Skupina";

// admin/um_gedit.php, row 221
$_m["Edit group"]
    = "Editace skupiny";

// admin/um_gedit.php, row 223
$_m["New group"]
    = "Nov� skupina";

// admin/um_gedit.php, row 253
$_m["Group Id"]
    = "ID skupiny";

// admin/um_gedit.php, row 255
// admin/se_constant.php, row 181, 413
// include/constants.php, row 357
// include/constedit.php, row 73
// include/um_gedit.php, row 31
// include/fileman.php, row 32
// include/tableviews.php, row 131, 152
$_m["Name"]
    = "Jm�no";

// admin/um_gedit.php, row 257
$_m["Superadmin group"]
    = "Administrativn� skupina";

// admin/um_gedit.php, row 269
$_m["All Users"]
    = "V�ichni u�ivatel�";

// admin/um_gedit.php, row 271
$_m["Group's Users"]
    = "U�ivatel� ve skupin�";

// admin/aa_synchronize.php3, row 46
$_m["Authentification failed, try again"]
    = "";

// admin/aa_synchronize.php3, row 51
// admin/aa_synchronize2.php3, row 69
$_m["Admin - Synchronize ActionApps"]
    = "";

// admin/aa_synchronize.php3, row 63
// admin/aa_synchronize2.php3, row 81
$_m["Admin - Synchronize ActionApps (1/3) - Destination ActionApps"]
    = "";

// admin/aa_synchronize.php3, row 74
// admin/aa_synchronize2.php3, row 92
$_m["Template ActionApps (current)"]
    = "";

// admin/aa_synchronize.php3, row 76
$_m["Remote ActionApps URL"]
    = "";

// admin/aa_synchronize.php3, row 76
$_m["like https://example.org/apc-aa/"]
    = "";

// admin/aa_synchronize.php3, row 77
$_m["Remote Superadmin Username"]
    = "";

// admin/aa_synchronize.php3, row 78
$_m["Remote Superadmin Password"]
    = "";

// admin/um_passwd.php, row 43
$_m["You have not permissions to change user data"]
    = "Nem�te pr�vo m�nit �daje o u�ivateli";

// admin/um_passwd.php, row 54, 106
$_m["Current password"]
    = "Sou�asn� heslo";

// admin/um_passwd.php, row 56
$_m["Error in current password - pasword is not changed"]
    = "�patn� heslo - heslo nebylo zm�n�np";

// admin/um_passwd.php, row 85, 92
$_m["Change user data"]
    = "Zm�nit �daje o u�ivateli";

// admin/sliceimp.php, row 67
$_m["Slice_ID (%1) has wrong length (%2, should be 32)"]
    = "ID web�ku (%1) m� �patnou d�lku (%2, m� b�t 32)";

// admin/sliceimp.php, row 173, 178, 240, 246, 485
$_m["Insert with new ids"]
    = "Vlo�it s nov�mi ID";

// admin/sliceimp.php, row 305, 312
$_m["Can't upload Import file"]
    = "Nemohu nahr�t importovan� soubor";

// admin/sliceimp.php, row 360, 372
$_m["Import exported data (slice structure and content)"]
    = "Importovat exportovan� data (strukturu web�ku a �l�nky)";

// admin/sliceimp.php, row 374
$_m["Import exported data"]
    = "Importovat exportovan� data";

// admin/sliceimp.php, row 379
$_m["Count of imported slices: %d."]
    = "Po�et importovan�ch web�k�: %d.";

// admin/sliceimp.php, row 381, 398
$_m["Added were:"]
    = "P�id�n byl:";

// admin/sliceimp.php, row 388, 405
$_m["Overwritten were:"]
    = "P�eps�n byl:";

// admin/sliceimp.php, row 395
$_m["Count of imported stories: %d."]
    = "Po�et importovan�ch �l�nk�: %d.";

// admin/sliceimp.php, row 412
$_m["Failed were:"]
    = "Chybn�:";

// admin/sliceimp.php, row 426
$_m["Here you can import exported data to toolkit. You can use two types of import:"]
    = "";

// admin/sliceimp.php, row 431
$_m["Slices with some of the IDs exist already. Change the IDs on the right side of the arrow.<br> Use only hexadecimal characters 0-9,a-f. If you do something wrong (wrong characters count, wrong characters, or if you change the ID on the arrow's left side), that ID will be considered unchanged.</p>"]
    = "";

// admin/sliceimp.php, row 446
$_m["<p>Views with some of the same IDs exist already. Please edit on the right hands side of the arrow</p>"]
    = "";

// admin/sliceimp.php, row 461
$_m["<p>Slice content with some of the IDs exist already. Change the IDs on the right side of the arrow.<br> Use only hexadecimal characters 0-9,a-f. </p>"]
    = "";

// admin/sliceimp.php, row 475
$_m["<p>If you choose OVERWRITE, the slices and data with unchanged ID will be overwritten and the new ones added. <br>If you choose INSERT, the slices and data with ID conflict will be ignored and the new ones added.<br>And finally, if you choose \"Insert with new ids\", slice structures gets new ids and it's content too.</p>"]
    = "";

// admin/sliceimp.php, row 494
$_m["1) If you have exported data in file, insert it's name here (eg. D:\\data\\apc_aa_slice.aaxml):"]
    = "";

// admin/sliceimp.php, row 496
$_m["Send file with slice structure and data"]
    = "";

// admin/sliceimp.php, row 502
$_m["2) If you have exported data in browser's window, insert the exported text into the textarea below:"]
    = "";

// admin/sliceimp.php, row 515
$_m["Here specify, what do you want to import:"]
    = "";

// admin/sliceimp.php, row 516
$_m["Import slice definition"]
    = "";

// admin/sliceimp.php, row 517
$_m["Import slice items"]
    = "";

// admin/sliceimp.php, row 518
$_m["Import into this slice - whatever file says"]
    = "";

// admin/sliceimp.php, row 520
$_m["Send the slice structure and data"]
    = "";

// admin/rsstest.php, row 104, 105
$_m["RSS Feed import test"]
    = "";

// admin/rsstest.php, row 108
$_m["No RSS Feeds set."]
    = "";

// admin/rsstest.php, row 134
$_m["Local slice"]
    = "";

// admin/discedit2.php, row 40
$_m["You do not have permission to edit items in this slice"]
    = "Nem�te pr�vo upravovat zpr�vy v tomto web�ku";

// admin/discedit2.php, row 56, 124
$_m["Text of discussion comment"]
    = "Text p��sp�vku";

// admin/discedit2.php, row 57, 126
$_m["Authors's WWW  - URL"]
    = "WWW autora - URL";

// admin/discedit2.php, row 58, 127
$_m["Authors's WWW - description"]
    = "WWW autora - popis";

// admin/discedit2.php, row 59, 128
$_m["Remote address"]
    = "Vzd�len� adresa";

// admin/discedit2.php, row 60
$_m["Free1"]
    = "";

// admin/discedit2.php, row 100
$_m["Edit discussion"]
    = "Upravit diskusi";

// admin/discedit2.php, row 110
$_m["Items managment - Discussion comments managment - Edit comment"]
    = "Spr�va zpr�v - Spr�va diskusn�ch p��sp�vk� - Editace p��sp�vku";

// admin/discedit2.php, row 116
$_m["Edit comment"]
    = "Editace p��sp�vku";

// admin/discedit2.php, row 129
$_m["Free 1"]
    = "";

// admin/discedit2.php, row 137
// include/formutil.php, row 198, 2727
$_m["Reset form"]
    = "Vymazat formul��";

// admin/se_compact.php, row 42
$_m["You have not permissions to change compact view formatting"]
    = "Nem�te pr�vo m�nit vzhled p�ehledu zpr�v";

// admin/se_compact.php, row 56, 203
// include/constants.php, row 506, 548, 615, 647, 674, 736, 787, 819
$_m["Odd Rows"]
    = "Lich� z�znam";

// admin/se_compact.php, row 60, 235
$_m["'No item found' message"]
    = "Hl�ka 'Nenalezena ��dn� zpr�va'";

// admin/se_compact.php, row 62, 207
// include/constants.php, row 508, 616, 738, 789, 821
$_m["Even Rows"]
    = "Sud� z�znam";

// admin/se_compact.php, row 65, 224
$_m["Category top HTML"]
    = "Horn� HTML k�d pro kategorii";

// admin/se_compact.php, row 66, 227
$_m["Category Headline"]
    = "Nadpis kategorie";

// admin/se_compact.php, row 67, 230
$_m["Category bottom HTML"]
    = "Spodn� HTML k�d pro kategorii";

// admin/se_compact.php, row 99
$_m["Design of compact design successfully changed"]
    = "Vzhled p�ehledu zpr�v byl �sp�n� zm�n�m";

// admin/se_compact.php, row 142, 181
// admin/se_newuser.php, row 93
$_m["Admin - design Index view"]
    = "Spr�va web�ku - Vzhled p�ehledu zpr�v";

// admin/se_compact.php, row 181
$_m["Use these boxes ( and the tags listed below ) to control what appears on summary page"]
    = "Na t�to str�nce lze nastavit, co se objev� na str�nce p�ehledu zpr�v";

// admin/se_compact.php, row 195
$_m["HTML code for index view"]
    = "HTML k�d pro p�ehled zpr�v";

// admin/se_compact.php, row 206
// include/constants.php, row 507, 641, 737, 788, 820
$_m["Use different HTML code for even rows"]
    = "Odli�n� HTML k�d pro sud� z�znamy";

// admin/se_compact.php, row 208
$_m["You can define different code for odd and ever rows\n"
."                         <br>first red, second black, for example"]
    = "TIP: Rozli�en�m sud�ch a lich�ch z�znam� lze doc�lit nap��klad odli�en� ��dk� jin�mi barvami pozad�\n"
    ."                         - prvn� t�eba zelen�, druh� �lut�, atd.";

// admin/se_compact.php, row 213
// include/constants.php, row 522, 625, 746, 797, 829
$_m["Group by"]
    = "Seskupit dle";

// admin/se_compact.php, row 218
// admin/se_view.php, row 165
$_m["Whole text"]
    = "Cel� text";

// admin/se_compact.php, row 218
// admin/se_view.php, row 165
$_m["1st letter"]
    = "1. p�smeno";

// admin/se_compact.php, row 218, 218
// admin/se_view.php, row 165, 165
$_m["letters"]
    = "p�smena";

// admin/se_compact.php, row 219
// admin/se_view.php, row 155
$_m["Ascending by Priority"]
    = "Vzestupn� dle �azen�";

// admin/se_compact.php, row 219
// admin/se_view.php, row 155
$_m["Descending by Priority"]
    = "Sestupn� dle �azen�";

// admin/se_compact.php, row 221
$_m["'by Priority' is usable just for fields using constants (like category)"]
    = "'dle �azen�' lze pou��t jen pro pole pou��vaj�c� konstant (kategorie) - tam take najdete hodnoty pro '�azen�'";

// admin/se_compact.php, row 236
$_m["message to show in place of slice.php3, if no item matches the query"]
    = "zpr�va, kter� se objev� p�i nenalezen� ��dn�ho odpov�daj�c�ho �l�nku";

// admin/se_newuser.php, row 61
// admin/setup.php, row 247
// include/um_util.php, row 318
$_m["Retyped password is not the same as the first one"]
    = "Vypln�n� hesla si neodpov�daj�";

// admin/se_newuser.php, row 79
// admin/setup.php, row 273
// include/um_util.php, row 352
$_m["It is impossible to add user to permission system"]
    = "Nepoda�ilo se p�idat u�ivatele do syst�mu";

// admin/se_newuser.php, row 101
$_m["New user in permission system"]
    = "Nov� u�ivatel v syst�mu";

// admin/related_sel.php, row 134
$_m["Editor window - item manager, related selection window"]
    = "ActionApps - V�b�r souvisej�c�ch �l�nk�";

// admin/slicedel.php, row 40
// admin/slicedel2.php, row 40, 44
$_m["You don't have permissions to delete slice."]
    = "Nem�te pr�va k odstran�n� web�ku.";

// admin/slicedel.php, row 66, 84
$_m["Admin - Delete Slice"]
    = "Spr�va web�ku - Vymaz�n� web�ku";

// admin/slicedel.php, row 69
$_m["Do you really want to delete this slice and all its fields and all its items?"]
    = "Opravdu chcete smazat tento web�k v�etn� pol��ek a �l�nk�?";

// admin/slicedel.php, row 93
// admin/fileman.php, row 177
$_m["Delete selected"]
    = "Vymazat vybran�";

// admin/slicedel.php, row 99
$_m["Select slice to delete"]
    = "Vyber web�k pro smaz�n�";

// admin/slicedel.php, row 100
$_m["Slices to show"]
    = "";

// admin/slicedel.php, row 100
$_m["Marked as \"Deleted\""]
    = "";

// admin/slicedel.php, row 101
$_m["This option allows you to display all the slices and delete them, so be careful!"]
    = "";

// admin/slicedel.php, row 103
$_m["Slices to delete"]
    = "";

// admin/slicedel.php, row 118
$_m["No slice marked for deletion"]
    = "��dn� web�k nebyl ozna�en za vymazan�";

// admin/slicewiz.php, row 53, 56
$_m["Add Slice Wizard"]
    = "Pr�vodce P�id�n�m Web�ku";

// admin/slicewiz.php, row 66
$_m["Copy Views"]
    = "Kop�ruj Pohledy";

// admin/slicewiz.php, row 67
$_m["Categories/Constants"]
    = "Kategorie/Konstanty";

// admin/slicewiz.php, row 68
$_m["Share with Template"]
    = "Sd�let se �ablonou";

// admin/slicewiz.php, row 68
$_m["Copy from Template"]
    = "Kop�rovat ze �ablony";

// admin/slicewiz.php, row 77
$_m["[Optional] Create New User"]
    = "[Nepovinn�] Vyvo�it Nov�ho U�ivatele";

// admin/slicewiz.php, row 80
$_m["Level of Access"]
    = "�rove� p��stupu";

// admin/slicewiz.php, row 81
// include/constants.php, row 974
$_m["Slice Administrator"]
    = "Administr�tor Web�ku.";

// admin/slicewiz.php, row 92
$_m["Do Not Email Welcome"]
    = "Nepos�lej v�tac� email.";

// admin/slicewiz.php, row 94
$_m["Email Welcome"]
    = "Po�li v�tac� email.";

// admin/slicewiz.php, row 104
$_m["Go: Add Slice"]
    = "Je�: P�idej Web�k";

// admin/se_fields.php, row 213
$_m["Do you really want to delete this field from this slice?"]
    = "Opravdu chcete vymazat toto pol��ko z web�ku?";

// admin/se_fields.php, row 237
// admin/anonym_wizard.php, row 204
// admin/se_fieldid.php, row 290
// include/menu.php, row 138
// include/searchlib.php, row 123
$_m["Fields"]
    = "Pol��ka";

// admin/se_fields.php, row 243
$_m["Required"]
    = "Povinn�";

// admin/se_fields.php, row 246
$_m["Aliases"]
    = "Aliasy";

// admin/se_fulltext.php, row 43
$_m["You have not permissions to change fulltext formatting"]
    = "Nem�te pr�vo m�nit vzhled v�pisu zpr�vy";

// admin/se_fulltext.php, row 55, 143
$_m["Top HTML code"]
    = "Horn� HTML k�d";

// admin/se_fulltext.php, row 56, 146
$_m["Fulltext HTML code"]
    = "HTML k�d textu zpr�vy";

// admin/se_fulltext.php, row 57, 149
$_m["Bottom HTML code"]
    = "Spodn� HTML k�d";

// admin/se_fulltext.php, row 88
$_m["Fulltext format update successful"]
    = "Vzhled textu zpr�vy byl �sp�n� zm�n�n";

// admin/se_fulltext.php, row 112, 128
$_m["Admin - design Fulltext view"]
    = "Spr�va web�ku - Vzhled jedn� zpr�vy";

// admin/se_fulltext.php, row 128
$_m["Use these boxes ( with the tags listed below ) to control what appears on full text view of each item"]
    = "Na t�to str�nce lze nastavit, co se objev� na str�nce p�i prohl�en� t�la zpr�vy";

// admin/se_fulltext.php, row 142
$_m["HTML code for fulltext view"]
    = "HTML k�d pro zobrazen� zpr�vy";

// admin/se_fulltext.php, row 155
$_m["The template for dicsussion you can set on \"Design\" -> \"View\" page"]
    = "�ablonu pro diskusi nastav�te na str�nce \"Vzhled\" -> \"Pohledy\"";

// admin/se_fulltext.php, row 156
$_m["Use HTML tags"]
    = "Diskusi form�tovat v HTML";

// admin/se_notify.php, row 83
// include/slicedit.php, row 85
$_m["You have not permissions to edit this slice"]
    = "Nem�te pr�vo upravovat tento web�k";

// admin/se_notify.php, row 187, 191, 204
$_m["Email Notifications of Events"]
    = "E-mailov� upozorn�n� na ud�losti";

// admin/se_notify.php, row 217
$_m["<h4>New Item in Holding Bin</h4> People can be notified by email when an item is created and put into the Holding Bin.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."]
    = "<h4>Nov� zpr�va v Z�sobn�ku</h4> Kdokoliv m��e b�t informov�n o tom, �e p�ibyla nov� zpr�va do z�sobn�ku. Adresy p��jemc� napi�te n�e, do n�sleduj�c�ch pol��ek pak vypl�te, jak m� vypadat e-mail, kter� pak u�ivatel� dostanou.";

// admin/se_notify.php, row 218, 223, 228, 233
$_m["Email addresses, one per line"]
    = "E-mailov� adresa (jedna na ��dek)";

// admin/se_notify.php, row 219, 224, 229, 234
$_m["Subject of the Email message"]
    = "P�edm�t e-mailu (Subject)";

// admin/se_notify.php, row 220, 225, 230, 235
$_m["Body of the Email message"]
    = "Vlastn� e-mailov� zpr�va";

// admin/se_notify.php, row 222
$_m["<h4>Item Changed in Holding Bin</h4>  People can be notified by email when an item in the Holding Bin is modified.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."]
    = "<h4>Zpr�va v Z�sobn�ku byla zm�n�na</h4> Kdokoliv m��e b�t informov�n o tom, �e byla zm�n�na zpr�va v z�sobn�ku. Adresy p��jemc� napi�te n�e, do n�sleduj�c�ch pol��ek pak vypl�te, jak m� vypadat e-mail, kter� pak u�ivatel� dostanou.";

// admin/se_notify.php, row 227
$_m["<h4>New Item in Approved Bin</h4>  People can be notified by email when an item is created and put into the Approved Bin.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."]
    = "<h4>Nov� zpr�va mezi Aktu�ln�mi</h4> Kdokoliv m��e b�t informov�n o tom, �e p�ibyla nov� zpr�va na web. Adresy p��jemc� napi�te n�e, do n�sleduj�c�ch pol��ek pak vypl�te, jak m� vypadat e-mail, kter� pak u�ivatel� dostanou.";

// admin/se_notify.php, row 232
$_m["<h4>Item Changed in Approved Bin</h4>  People can be notified by email when an item in the Approved Bin is modified.  If you want to make use of this feature, enter the recipients email address below.  In the following fields, you can customize the format of the email they will receive."]
    = "<h4>Aktu�ln� zpr�va zm�n�na</h4> Kdokoliv m��e b�t informov�n o tom, �e byla zm�n�na vystaven� zpr�va. Adresy p��jemc� napi�te n�e, do n�sleduj�c�ch pol��ek pak vypl�te, jak m� vypadat e-mail, kter� pak u�ivatel� dostanou.";

// admin/slicedit.php, row 41, 46
$_m["Not allowed"]
    = "Zak�z�no";

// admin/slicedit.php, row 43
// include/menu.php, row 190, 244
$_m["Hold bin"]
    = "Z�sobn�k";

// admin/slicedit.php, row 47
// include/formutil.php, row 3014
$_m["All items"]
    = "V�echny �l�nky";

// admin/slicedit.php, row 48
$_m["Only items posted anonymously"]
    = "Pouze �l�nky poslan� anonymn�";

// admin/slicedit.php, row 49
$_m["-\"- and not edited in AA"]
    = "-\"- a neupraven� v AA";

// admin/slicedit.php, row 50
$_m["Authorized by a password field"]
    = "Autorizovan� heslem";

// admin/slicedit.php, row 51
$_m["Readers, authorized by HTTP auth"]
    = "�ten��i, autorizovan� pomoc� HTTP";

// admin/slicedit.php, row 115
// include/modutils.php, row 147
$_m["Select owner"]
    = "Vyber vlastn�ka";

// admin/slicedit.php, row 128
// admin/sliceadd.php, row 60
// admin/se_import.php, row 86
// include/menu.php, row 88
$_m["Slice Administration"]
    = "Administrace web�ku";

// admin/slicedit.php, row 134
// admin/setup.php, row 289
$_m["Add Slice"]
    = "Spr�va web�ku - P�id�n� web�ku";

// admin/slicedit.php, row 134
$_m["Admin - Slice settings"]
    = "Spr�va web�ku - �prava web�ku";

// admin/slicedit.php, row 152
// include/modutils.php, row 74
// include/slicedit.php, row 156
$_m["URL of .shtml page (often leave blank)"]
    = "URL web�ku";

// admin/slicedit.php, row 153
// include/slicedit.php, row 158
$_m["Priority (order in slice-menu)"]
    = "Priorita (po�ad� ve v�b�ru web�k�)";

// admin/slicedit.php, row 155
// admin/se_view.php, row 459
// admin/se_views.php, row 193
$_m["<br>To include slice in your webpage type next line \n"
."                         to your shtml code: "]
    = "<br>Web�k zahrnete do sv� *.shtml str�nky p�id�n�m \n"
    ."                             n�sleduj�c� ��dky v HTML k�du: ";

// admin/slicedit.php, row 160
// include/modutils.php, row 73
// include/tv_email.php, row 138
// include/slicedit.php, row 155
$_m["Owner"]
    = "Vlastn�k";

// admin/slicedit.php, row 162
// include/modutils.php, row 42
// include/slicedit.php, row 134
$_m["New Owner"]
    = "Nov� vlastn�k";

// admin/slicedit.php, row 163
// include/modutils.php, row 43
// include/slicedit.php, row 135
$_m["New Owner's E-mail"]
    = "E-mail nov�ho vlastn�ka";

// admin/slicedit.php, row 165
// include/constants.php, row 539, 639, 662, 689, 720, 779, 811, 843
// include/slicedit.php, row 159
$_m["Listing length"]
    = "Po�et vypisovan�ch zpr�v";

// admin/slicedit.php, row 168
$_m["Deleted"]
    = "Vymaz�n";

// admin/slicedit.php, row 170
// include/slicedit.php, row 160
$_m["Allow anonymous posting of items"]
    = "Anonymn� vkl�d�n�";

// admin/slicedit.php, row 171
// include/slicedit.php, row 161
$_m["Allow anonymous editing of items"]
    = "Anonymn� upravov�n�";

// admin/slicedit.php, row 172
// include/slicedit.php, row 162
$_m["Allow off-line item filling"]
    = "Off-line pln�n�";

// admin/slicedit.php, row 173
$_m["Language"]
    = "Jazyk";

// admin/slicedit.php, row 178
$_m["MLX Control Slice for"]
    = "MLX Control Slice pro";

// admin/slicedit.php, row 180
$_m["MLX: Language Control Slice"]
    = "MLX: Web�k pro spr�vu p�eklad�";

// admin/slicedit.php, row 185
// include/slicedit.php, row 167
$_m["File Manager Access"]
    = "P��stup ke Spr�vci Soubor�";

// admin/slicedit.php, row 186
// include/slicedit.php, row 168
$_m["File Manager Directory"]
    = "Adres�� pro Spr�vce Soubor�";

// admin/slicedit.php, row 195
$_m["Auth Group Field"]
    = "Pol��ko s Auth Skupinami";

// admin/slicedit.php, row 196
$_m["Mailman Lists Field"]
    = "Pol��ko se Seznamy Mailmana";

// admin/slicedit.php, row 199
$_m["Password for Reading"]
    = "Heslo pro �ten�";

// admin/se_view.php, row 66
$_m["Group by selections"]
    = "Seskupit dle v�b�r�";

// admin/se_view.php, row 69
$_m["Yes. Write sort[] to the conds[] field for each Selection."]
    = "Ano. Zpravy v jednotliv�ch v�b�rech se�ad�te p�id�n�m sort[]";

// admin/se_view.php, row 72
$_m["No. Use this sort[]:"]
    = "Ne. Pro se�azen� celkov�ho v�pisu pou�ijte n�sleduj�c� sort[]";

// admin/se_view.php, row 87
$_m["Alerts Selection"]
    = "Zas�l�n� - v�b�r";

// admin/se_view.php, row 92
$_m["If you need more selections, use 'Update' and on next Edit two empty boxes appear."]
    = "Dal�� dv� pr�zn� mo�nosti se v�m objev� po odesl�n� formul��e.";

// admin/se_view.php, row 219
// admin/se_views.php, row 40
$_m["You do not have permission to change views"]
    = "Nem�te pr�vo m�nit pohledy";

// admin/se_view.php, row 281
$_m["View successfully changed"]
    = "Pohled byl �sp�n� zm�n�n";

// admin/se_view.php, row 350, 376
// admin/se_views.php, row 91, 118
$_m["Admin - design View"]
    = "Spr�va web�ku - definice Pohledu";

// admin/se_view.php, row 388
// admin/se_views.php, row 124
$_m["Defined Views"]
    = "Definovan� pohledy";

// admin/se_view.php, row 391
// admin/se_views.php, row 77
$_m["show this view"]
    = "zobraz pohled";

// admin/aa_optimize.php, row 33
$_m["You don't have permissions to do optimize tests."]
    = "";

// admin/aa_optimize.php, row 79, 91
$_m["Admin - Optimize a Repair ActionApps"]
    = "";

// admin/aa_optimize.php, row 102
$_m["Optimalizations"]
    = "";

// admin/se_mapping2.php, row 112
$_m["Fields' mapping update succesful"]
    = "Nastaven� mapov�n� pol� �sp�n� zm�n�no";

// admin/se_rssfeeds.php, row 113, 165, 172
$_m["Remote RSS Feed administration"]
    = "Spr�va RSS kan�l�";

// admin/se_rssfeeds.php, row 133
$_m["No selected rssfeed"]
    = "";

// admin/se_rssfeeds.php, row 136
$_m["Are you sure you want to delete the rssfeed?"]
    = "";

// admin/se_rssfeeds.php, row 149
$_m["Error: RSS node empty"]
    = "";

// admin/se_rssfeeds.php, row 174
$_m["Current remote rssfeeds"]
    = "Nastaven� RSS kan�ly";

// admin/se_rssfeeds.php, row 190
$_m["Map"]
    = "";

// admin/se_rssfeeds.php, row 193
$_m["Add new rssfeed"]
    = "P�idat RSS kan�l";

// admin/se_rssfeeds.php, row 193
$_m["Edit rssfeed data"]
    = "Editovat RSS kan�l";

// admin/se_rssfeeds.php, row 195
$_m["RSS Feed name"]
    = "Jm�no RSS kan�lu";

// admin/se_rssfeeds.php, row 196
$_m["New rssfeed name"]
    = "Nov� RSS kan�l";

// admin/se_rssfeeds.php, row 197
$_m["URL of the feed"]
    = "URL RSS kan�lu";

// admin/se_rssfeeds.php, row 198
$_m["e.g. https://www.someplace.com/rss/index.xml"]
    = "nap�: https://www.someplace.com/rss/index.xml";

// admin/slicedel2.php, row 63
$_m["Slice successfully deleted, tables are optimized"]
    = "Web�k byl vymaz�n, tabulky byly optimalizov�ny";

// admin/sliceadd.php, row 63
$_m["Create New Slice / Module"]
    = "Vytvo�it nov� Web�k / Modul";

// admin/sliceadd.php, row 79
$_m["Modules"]
    = "Moduly";

// admin/aafinder.php, row 43
// admin/console.php, row 36
// include/slicedit.php, row 90
// include/sliceadd.php, row 35
$_m["You have not permissions to add slice"]
    = "Nem�te pr�vo p�id�vat web�k";

// admin/aafinder.php, row 49, 51, 186
// include/menu_aa.php3, row 71
$_m["AA finder"]
    = "AA vyhled�va�";

// admin/aafinder.php, row 128
$_m["Jump"]
    = "Skokan";

// admin/aafinder.php, row 189
$_m["Find all VIEWS containing in any field the string:"]
    = "Najdi v�echny POHLEDY obsahuj�c� v kter�mkoli poli text:";

// admin/aafinder.php, row 191, 197, 203, 209
$_m["Go!"]
    = "Je�!";

// admin/aafinder.php, row 195
$_m["Find all SLICES containing in any field the string:"]
    = "Najdi v�echny WEB�KY obsahuj�c� v kter�mkoli poli text:";

// admin/aafinder.php, row 201
$_m["Find all FIELDS containing in ites definition the string:"]
    = "";

// admin/aafinder.php, row 207
$_m["Get all informations about the item"]
    = "Zobrazit informace o �l�nku";

// admin/view.php, row 39
$_m["Administrative view"]
    = "";

// admin/se_constant_import.php, row 57, 114
// admin/se_constant.php, row 189, 348
// include/constants.php, row 620
$_m["Constant Group"]
    = "Skupina hodnot";

// admin/se_constant_import.php, row 65
// include/constedit_util.php, row 612
$_m["No constants specified"]
    = "Konstanty nezad�ny";

// admin/se_constant_import.php, row 86
// admin/se_constant.php, row 280
$_m["Constants update successful"]
    = "Zm�na hodnot �sp�n� provedena";

// admin/se_constant_import.php, row 95, 102
$_m["Admin - Constants Import"]
    = "Administrace - Import konstant";

// admin/se_constant_import.php, row 121
$_m["Name - Value delimiter"]
    = "Odd�lova� jm�no - hodnota";

// admin/se_constant_import.php, row 122
$_m["write each constant to new row in form <name><delimiter><value> (or just <name> if the values should be the same as names)"]
    = "zapi�te v�dy jednu konstantu na ��dek ve form�tu <jm�no><odd�lova�><hodnota> (nebo jen <jm�no>, pokud m� b�t hodnota a jm�no stejn�)";

// admin/fileman.php, row 56
$_m["No permissions for file manager."]
    = "Chyb� pr�va pro spr�vce soubor�.";

// admin/fileman.php, row 62
$_m["Unable to run File Manager"]
    = "Nelze spustit Spravce soubor�";

// admin/fileman.php, row 63
$_m["doesn't exist"]
    = "neexistuje";

// admin/fileman.php, row 71
$_m["Unable to mkdir"]
    = "Nelze vytvo�it adres��";

// admin/fileman.php, row 99, 105
// include/filedit.php, row 69, 75
// include/menu.php, row 167
$_m["File Manager"]
    = "Spr�vce Soubor�";

// admin/fileman.php, row 106
// include/fileman.php, row 111, 123
$_m["Directory"]
    = "Adres��";

// admin/fileman.php, row 176
$_m["Unselect all"]
    = "Zru�it v�b�r";

// admin/fileman.php, row 220
$_m["Create new file"]
    = "Vytvo� nov� soubor";

// admin/fileman.php, row 223
$_m["Upload file"]
    = "Po�li soubor";

// admin/fileman.php, row 232
$_m["Copy template dir"]
    = "Zkop�ruj adres�� �ablony";

// admin/fileman.php, row 235
$_m["Create new directory"]
    = "Vytvo� nov� adres��";

// admin/search_replace.php, row 230
$_m["Fill by value"]
    = "";

// admin/search_replace.php, row 236
$_m["Returns single value (not multivalue) which is created as result of AA expression specified in Expression. You can use any AA expressions like {ifset:{_#HEADLINE}:...}, ..."]
    = "";

// admin/search_replace.php, row 263, 336, 420, 571
// include/formutil.php, row 1153, 1170
$_m["Plain text"]
    = "Prost� text";

// admin/search_replace.php, row 264, 337, 421
$_m["As for other values of this field"]
    = "";

// admin/search_replace.php, row 272, 345, 430, 580
$_m["Mark as"]
    = "Nastavit jako";

// admin/search_replace.php, row 273, 346
$_m["New content"]
    = "Nov� obsah";

// admin/search_replace.php, row 274, 347, 432
$_m["You can use also aliases, so the content \"&lt;i&gt;{abstract........}&lt;/i&gt;&lt;br&gt;{full_text......1}\" is perfectly OK"]
    = "Zde m��ete t� pou��t alias� - t�eba takto \"&lt;i&gt;{abstract........}&lt;/i&gt;&lt;br&gt;{full_text......1}\".";

// admin/search_replace.php, row 304
$_m["Add value to field"]
    = "";

// admin/search_replace.php, row 310
$_m["Add new value to current content of field, so the field becames multivalue.<br>You can use any AA expressions like {ifset:{_#HEADLINE}:...}, ... for new value."]
    = "";

// admin/search_replace.php, row 376
$_m["Divide the text to multiple values"]
    = "";

// admin/search_replace.php, row 384
$_m["Parses the input text and looks for the delimiter. Separates the parts and store them as multiple values to destination field"]
    = "";

// admin/search_replace.php, row 431
$_m["Source text"]
    = "";

// admin/search_replace.php, row 433
$_m["Delimiter"]
    = "";

// admin/search_replace.php, row 500
$_m["Translate"]
    = "";

// admin/search_replace.php, row 506
$_m["Translates one value after other according to translation table. The result is multivalue, since each value of multivalue field is translated seperately."]
    = "";

// admin/search_replace.php, row 515
$_m["No translations specified."]
    = "";

// admin/search_replace.php, row 572
$_m["Unchanged"]
    = "Nezm�n�no";

// admin/search_replace.php, row 581
$_m["Translations"]
    = "";

// admin/search_replace.php, row 582
$_m["Each translation on new line, translations separated by colon : (escape character for colon is #:).<br>You can use also aliases in the translation. There is also special alias _#0, which contain matching text - following translation is perfectly OK:<br><code> Bio:&lt;img src=\"_#0.jpg\"&gt; ({publish_date....})</code><br>You can also use Regular Expressions - in such case the line would be \"<code>:regexp:<regular expression>:<output></code>\". You can use _#0 alias in <output>, which contains whole matching text.<br>Sometimes you want to remove specific value. In such case use <code>AA_NULL</code> text as translated text:<br> <code>Bio:AA_NULL</code><br>You may want also create more than one value from a value. Then separate the values by colon:<br> <code>Bio:Environment:Ecology</code> (\"Bio\" is replaced by two values). You can use any number of values here."]
    = "";

// admin/search_replace.php, row 605, 635
$_m["Copy field"]
    = "Zkop�rovat obsah pole";

// admin/search_replace.php, row 611, 636
$_m["If you select the field here, the \"New content\" text is not used. Selected field will be copied to the \"Field\" (including multivalues)"]
    = "";

// admin/search_replace.php, row 619
$_m["Source or destination field is not specified."]
    = "";

// admin/search_replace.php, row 699
$_m["Items selected: %1, Items sucessfully updated: %2"]
    = "Vybr�no �l�nk�: %1, �sp�n� zm�n�no: %2";

// admin/search_replace.php, row 716, 722
$_m["Modify items"]
    = "Hromadn� zm�na �l�nk�";

// admin/search_replace.php, row 739
$_m["Select field..."]
    = "vyber pol��ko...";

// admin/search_replace.php, row 755
$_m["Items"]
    = "�l�nky";

// admin/search_replace.php, row 757
$_m["View items"]
    = "Zobrazit �l�nky";

// admin/search_replace.php, row 758
// include/manager.class.php, row 590
$_m["Selected items"]
    = "Zm�nit vybran�";

// admin/search_replace.php, row 763
$_m["Be very carefull with this. Changes in some fields (Status Code, Publish Date, Slice ID, ...) could be very crucial for your item's data. There is no data validity check - what you will type will be written to the database.<br>You should also know there is no UNDO operation (at least now)."]
    = "POZOR na tuto funkci. Je velmi mocn� a dok�e v m�iku poni�it mnoho �l�nk�. Zm�ny v n�kter�ch pol��c�ch (tatus Code, Publish Date, Slice ID, ...) mohou zp�sobit ztr�tu dat. V�e je zaps�no ihned do datab�ze.<br>Je dobr� v�d�t, �e tato funkce nem� mo�nost n�vratu (UNDO).";

// admin/search_replace.php, row 765
// admin/se_csv_import2.php, row 355
$_m["Action"]
    = "Akce";

// admin/search_replace.php, row 767
$_m["Fill"]
    = "Zm�nit";

// admin/se_csv_import.php, row 70
$_m["You have not permissions to import files"]
    = "Nem�te pr�vo importovat soubory";

// admin/se_csv_import.php, row 75
$_m["Missing slice"]
    = "Web�k nenalezen";

// admin/se_csv_import.php, row 109
$_m["Cannot read input url"]
    = "Nezle na��st vstupn� url";

// admin/se_csv_import.php, row 142
// admin/se_csv_import2.php, row 476
$_m["Admin - Import .CSV file"]
    = "Spr�va web�ku - Na�ten� .csv souboru";

// admin/se_csv_import.php, row 154
$_m["Admin - Import CSV (1/2) - Source data"]
    = "Spr�va web�ku - Na�ten� .csv (1/2) - Vstupn� data";

// admin/se_csv_import.php, row 160
$_m["Cannot open a file for preview"]
    = "Nelze otev��t soubor pro n�hled";

// admin/se_csv_import.php, row 162
$_m["File preview"]
    = "N�hled souboru";

// admin/se_csv_import.php, row 195
$_m["CSV format settings"]
    = "Nastaveni CSV form�tu";

// admin/se_csv_import.php, row 214, 218
// include/filedit.php, row 75
$_m["File"]
    = "Soubor";

// admin/se_csv_import.php, row 215, 219
// include/widget.class.php, row 1281
$_m["URL"]
    = "";

// admin/se_csv_import.php, row 228
$_m["Source of CSV data"]
    = "Zdroj CSV dat";

// admin/se_csv_import.php, row 251
// admin/se_csv_import2.php, row 332
// include/actions.php, row 387
$_m["Preview"]
    = "Zobraz zpr�vu";

// admin/se_csv_import.php, row 252
// include/easy_scroller.php3, row 164, 290
$_m["Next"]
    = "Dal��";

// admin/usershow.php, row 93
$_m["Show selected users"]
    = "Zobraz vybran� u�ivatele";

// admin/se_constant.php, row 51
$_m["You have not permissions to change category settings"]
    = "Nem�te pr�vo m�nit nastaven� kategori�";

// admin/se_constant.php, row 79
$_m["You have not permissions to change fields settings for the slice owning this group"]
    = "Nem�te administr�torsk� pr�va k web�ku, kter� vlastn� tuto skupinu hodnot";

// admin/se_constant.php, row 163
$_m[" items changed to new value "]
    = " �l�nk� bylo zm�n�no na novou hodnotu ";

// admin/se_constant.php, row 196
// include/constedit_util.php, row 608
$_m["This constant group already exists"]
    = "Tato skupina hodnot ji� existuje";

// admin/se_constant.php, row 291
$_m["No category field defined in this slice.<br>Add category field to this slice first (see Field page)."]
    = "Pole kategorie nen� v tomto web�ku definov�no.<br>  P�idejte pole kategorie do web�ku na str�nce Pol��ka.";

// admin/se_constant.php, row 311, 318
$_m["Admin - Constants Setting"]
    = "Spr�va web�ku - Nastaven� hodnot";

// admin/se_constant.php, row 325
$_m["Delete whole group"]
    = "Smazat celou skupinu";

// admin/se_constant.php, row 356
$_m["Import Constants..."]
    = "Import konstant...";

// admin/se_constant.php, row 372
$_m["Constants used in slice"]
    = "Hodnoty pou�ity v";

// admin/se_constant.php, row 387
$_m["Constant group owner - slice"]
    = "Vlastn�k skupiny - web�k";

// admin/se_constant.php, row 391
$_m["Whoever first updates values becomes owner."]
    = "Vlastn�kem se stane prvn� web�k, kter� uprav� hodnoty.";

// admin/se_constant.php, row 403
$_m["Change owner"]
    = "Zm�nit vlastn�ka";

// admin/se_constant.php, row 409
$_m["Propagate changes into current items"]
    = "Propagovat zm�ny do st�vaj�c�ch �l�nk�";

// admin/se_constant.php, row 411
$_m["Edit in Hierarchical editor (allows to create constant hierarchy)"]
    = "Editovat v Hierarchick�m editoru (umo��uje ur�it hierarchii hodnot)";

// admin/se_constant.php, row 413
// include/constedit.php, row 73
$_m["shown&nbsp;on&nbsp;inputpage"]
    = "zobrazeno&nbsp;ve&nbsp;vstupn�m&nbsp;formul��i";

// admin/se_constant.php, row 414
// include/constedit.php, row 76
$_m["stored&nbsp;in&nbsp;database"]
    = "ulo�eno&nbsp;v&nbsp;datab�zi";

// admin/se_constant.php, row 415
// include/constedit.php, row 82
$_m["constant&nbsp;order"]
    = "Po�ad�&nbsp;hodnot";

// admin/se_constant.php, row 416
// include/fileman.php, row 112, 121
$_m["Parent"]
    = "Nadkategorie";

// admin/se_constant.php, row 416
$_m["categories&nbsp;only"]
    = "jen&nbsp;pro&nbsp;kategorie";

// admin/se_constant.php, row 448
$_m["Are you sure you want to PERMANENTLY DELETE this group?"]
    = "Opravdu chcete NEN�VRATN� smazat tyto skupinu konstant?";

// admin/se_filters2.php, row 89
// include/csn_util.php, row 95
$_m["Other categories"]
    = "";

// admin/anonym_wizard.php, row 64
$_m["ActionApps Anonymous form"]
    = "ActionApps Anonymn� formul��";

// admin/anonym_wizard.php, row 65
$_m["Note: If you are using HTMLArea editor in your form, you have to add: %1 to your page.  -->"]
    = "";

// admin/anonym_wizard.php, row 142
$_m["WARNING: You did not permit anonymous posting in slice settings."]
    = "POZOR: Nepovolili jste anonymn� zas�l�n� v nastaven� web�ku.";

// admin/anonym_wizard.php, row 145
$_m["WARNING: You did not permit anonymous editing in slice settings. A form allowing only anonymous posting will be shown."]
    = "";

// admin/anonym_wizard.php, row 153
$_m["WARNING: You want to show password, but you did not set 'Authorized by a password field' in Settings - Anonymous editing."]
    = "";

// admin/anonym_wizard.php, row 179, 185
$_m["Admin - Anonymous Form Wizard"]
    = "Spr�va web�ku - Pr�vodce Anonymn�m Formul��em";

// admin/anonym_wizard.php, row 191
$_m["Show Form"]
    = "Zobraz formul��";

// admin/anonym_wizard.php, row 198
$_m["Help"]
    = "N�pov�da";

// admin/anonym_wizard.php, row 198
$_m["Help - Documentation"]
    = "N�pov�da - Dokumentace";

// admin/anonym_wizard.php, row 199
$_m["URLs shown after the form was sent"]
    = "URL zobrazen� po odesl�n� formul��e";

// admin/anonym_wizard.php, row 200
$_m["OK page"]
    = "OK str�nka";

// admin/anonym_wizard.php, row 201
$_m["Error page"]
    = "Chybov� str�nka";

// admin/anonym_wizard.php, row 202
$_m["Use a PHP script to show the result on the OK and Error pages:"]
    = "Pou��t PHP skript k zobrazen� v�sledk� na OK a Chybov� str�nce:";

// admin/anonym_wizard.php, row 211
$_m["Field Id in Form"]
    = "Id pol��ka ve formul��i";

// admin/anonym_wizard.php, row 235
$_m["Only fields marked as \"Show\" on the \"Fields\" page\n"
."         are offered on this page."]
    = "Na t�to str�nce jsou nab�zena pouze pol��ka \n\n"
    ."    se zatrhnut�m \"Zobrazit\" na str�nce \"Pol��ka\"";

// admin/se_views.php, row 57
$_m["View successfully deleted"]
    = "Pohled by �sp�n� smaz�n";

// admin/se_views.php, row 80
$_m["Are you sure you want to delete selected view?"]
    = "V�te ur�it�, �e chcete smazat zvolen� pohled?";

// admin/se_views.php, row 144
$_m["Create new view"]
    = "Vytvo�it nov� pohled";

// admin/se_views.php, row 147
$_m["by&nbsp;type:"]
    = "dle&nbsp;typu:";

// admin/se_views.php, row 154, 174
// include/formutil.php, row 1608, 1615
$_m["New"]
    = "Nov�";

$_m["new"]
    = "nov�";

// admin/se_views.php, row 159
$_m["by&nbsp;template:"]
    = "dle&nbsp;�ablony:";

// admin/se_fieldid.php, row 215
$_m["This ID is reserved"]
    = "Toto ID je rezerov�no";

// admin/se_fieldid.php, row 221
$_m["This ID is already used"]
    = "Toto ID je ji� pou�ito";

// admin/se_fieldid.php, row 240, 247, 256
$_m["Admin - change Field IDs"]
    = "Spr�va web�ku - Zm�na ID pol��ka";

// admin/se_fieldid.php, row 251
$_m["field IDs were changed"]
    = "ID pol��ka bylo zm�n�no";

// admin/se_fieldid.php, row 258
$_m["This page allows to change field IDs. It is a bit dangerous operation and may last long.\n"
."    You need to do it only in special cases, like using search form for multiple slices. <br><br>\n"
."    Choose a field ID to be changed and the new name and number, the dots ..... will be\n"
."    added automatically.<br>"]
    = "Tato str�nka umo��uje zm�nit identifik�tory jednotliv�ch pol��ek. \n"
    ."     Je to pom�rn� nebezpe�n� operace a m��e trvat dlouho. Je dost \n"
    ."     pravd�podobn�, �e tuto operaci nikdy nevyu�ijete - pou��v� se jen \n"
    ."     ve v�jime�n�ch p��padech (nastaven� formul��e pro vyhled�v�n� ve v�ce \n"
    ."     web�c�ch.<br><br>\n"
    ."     Vyberte ID pol��ka, kter� chcete zm�nit a potom nov� ID a ��slo. Te�ky \n"
    ."     budou automaticky dopln�ny.<br>";

// admin/se_fieldid.php, row 259
$_m["Change from"]
    = "Zm�nit z";

// admin/aa_synchronize2.php3, row 93
$_m["Select destination ActionApps"]
    = "";

// admin/aa_synchronize2.php3, row 94
$_m["The list is taken from config.php file of ActionApps"]
    = "";

// admin/se_csv_import2.php, row 144
$_m["You have not permissions to setting "]
    = "Nem�te pr�vo pro nastaven�";

// admin/se_csv_import2.php, row 155
$_m["Invalid additional parameters for import"]
    = "Chybn� dodate�n� parametry pro import";

// admin/se_csv_import2.php, row 219
$_m["Item:"]
    = "Zpr�va:";

// admin/se_csv_import2.php, row 225
$_m["Cannot store item to DB"]
    = "Zpr�vu nelze ulo�it do datab�ze";

// admin/se_csv_import2.php, row 230
$_m["Transformation error:"]
    = "Chyba p�i transformaci:";

// admin/se_csv_import2.php, row 232
$_m["Ok: Item %1 stored"]
    = "OK: �l�nek %1 je ulo�en";

// admin/se_csv_import2.php, row 248
// include/files.class.php, row 282
$_m["Ok : file deleted "]
    = "OK: soubor vymaz�n ";

// admin/se_csv_import2.php, row 250
// include/files.class.php, row 284
$_m["Error: Cannot delete file"]
    = "Error: Nelze vymazat soubor";

// admin/se_csv_import2.php, row 254
$_m["Added to slice"]
    = "P�id�no do web�ku";

// admin/se_csv_import2.php, row 281
$_m["Mapping preview"]
    = "N�hled mapov�n�";

// admin/se_csv_import2.php, row 335
$_m["Finish"]
    = "Dokon�it";

// admin/se_csv_import2.php, row 338
$_m["Save"]
    = "Ulo�it";

// admin/se_csv_import2.php, row 341
$_m["Load"]
    = "Na��st";

// admin/se_csv_import2.php, row 350
$_m["Mapping settings"]
    = "Nastaven� mapov�n�";

// admin/se_csv_import2.php, row 356
$_m["Html"]
    = "";

// admin/se_csv_import2.php, row 357
$_m["Action parameters"]
    = "Parametry akce";

// admin/se_csv_import2.php, row 358
$_m["Parameter wizard"]
    = "Pr�vodce Parametry";

// admin/se_csv_import2.php, row 378
$_m["Import options"]
    = "Nastaven� importu";

// admin/se_csv_import2.php, row 389
$_m["Map item id from"]
    = "ID zpr�vy namapuj z";

// admin/se_csv_import2.php, row 392
$_m["unpacked long id (pack_id)"]
    = "";

// admin/se_csv_import2.php, row 393
$_m["packed long id (store)"]
    = "";

// admin/se_csv_import2.php, row 394
$_m["string to be converted (string2id) - with param:"]
    = "";

// admin/se_csv_import2.php, row 402
$_m["Select, how to store the items"]
    = "";

// admin/se_csv_import2.php, row 403
$_m["Do not store the item"]
    = "P�esko� danou zpr�vu (neukl�dat)";

// admin/se_csv_import2.php, row 404
$_m["Store the item with new id"]
    = "Ulo� zpr�vu pod nov�m ID";

// admin/se_csv_import2.php, row 405
$_m["Update the item (overwrite)"]
    = "";

// admin/se_csv_import2.php, row 406
$_m["Add the values in paralel to current values (the multivalues are stored, where possible)"]
    = "";

// admin/se_csv_import2.php, row 407
$_m["Rewrite only the fields, for which the action is defined"]
    = "";

// admin/se_csv_import2.php, row 409
$_m["If the item id is already in the slice"]
    = "";

// admin/se_csv_import2.php, row 410
$_m["Data source"]
    = "";

// admin/se_csv_import2.php, row 411
$_m["Source"]
    = "";

// admin/se_csv_import2.php, row 413
$_m["Store settings..."]
    = "";

// admin/se_csv_import2.php, row 419
$_m["Load setting"]
    = "";

// admin/se_csv_import2.php, row 421
$_m["Save setting as"]
    = "";

// admin/se_csv_import2.php, row 424
$_m["Upload periodicaly"]
    = "";

// admin/se_csv_import2.php, row 504
$_m["Admin - Import CSV (2/2) - Mapping and Actions"]
    = "Spr�va web�ku - Na�ten� .csv (2/2) - Mapov�n�";

// admin/se_nodes.php, row 39
$_m["You have not permissions to manage nodes"]
    = "Nem�te pr�va pro spr�vu uzl�";

// admin/se_nodes.php, row 93, 145, 156
$_m["Remote node administration"]
    = "Spr�va uzl�";

// admin/se_nodes.php, row 115
$_m["Are you sure you want to delete the node?"]
    = "";

// admin/se_nodes.php, row 126
$_m["Node empty"]
    = "";

// admin/se_nodes.php, row 158
$_m["Known remote nodes"]
    = "Seznam uzl�";

// admin/se_nodes.php, row 178
$_m["Add new node"]
    = "P�id�n� uzlu";

// admin/se_nodes.php, row 178
$_m["Edit node data"]
    = "Editace uzlu";

// admin/se_nodes.php, row 181
$_m["Node name"]
    = "Jm�no uzlu ";

// admin/se_nodes.php, row 182
$_m["Your node name"]
    = "Jm�no uzlu";

// admin/se_nodes.php, row 183
$_m["URL of the getxml.php3"]
    = "URL souboru getxml.php3";

// admin/se_nodes.php, row 184
$_m["Your getxml is"]
    = "Va�e getxml je";

// admin/se_filters.php, row 95
$_m["-- The same --"]
    = "-- stejn� --";

// admin/se_filters.php, row 152, 251
$_m["Admin - Content Pooling - Filters"]
    = "Spr�va web�ku - Filtry pro v�m�nu zpr�v";

// admin/se_filters.php, row 225
$_m["No From category selected!"]
    = "";

// admin/se_filters.php, row 264
$_m["Content Pooling - Configure Filters"]
    = "Nastaven� filtr� pro p��jem zpr�v";

// admin/se_filters.php, row 267
$_m["Filter for imported slice"]
    = "Filtr pro p��jem zpr�v z web�ku";

// admin/se_filters.php, row 271
$_m["Categories"]
    = "Kategorie";

// admin/se_filters.php, row 289
$_m["All Categories"]
    = "V�echny kategorie";

// admin/se_filters.php, row 296, 319
$_m["No category defined"]
    = "Kategorie nebyly definov�ny";

// admin/se_filters.php, row 355, 355
// include/manager.class.php, row 585
$_m["Select all"]
    = "Vybrat v�e";

// admin/setup.php, row 66, 69
$_m["AA Setup"]
    = "Instalace AA";

// admin/setup.php, row 75
$_m["This script can't be used on a configured system."]
    = "Skript nelze pou��t na nakonfigurovan�m syst�mu.";

// admin/setup.php, row 103, 240
// include/formutil.php, row 1944
$_m["Retype Password"]
    = "Zopakujte heslo";

// admin/setup.php, row 105, 243
$_m["Last name"]
    = "P��jmen�";

// admin/setup.php, row 122
$_m["Welcome! Use this script to create the superadmin account.<p>If you are installing a new copy of AA, press <b>Init</b>.<br>"]
    = "Dobr� den! Pou�ijte tento skript k vytvo�en� superu�ivatelsk�ho ��t�.<p>Pokud instalujete novou kopii AA, stiskn�te <strong>Init</strong>.</p>";

// admin/setup.php, row 123
$_m["If you deleted your superadmin account by mistake, press <b>Recover</b>.<br>"]
    = "Pokud jste smazali superu�ivatelsk� ��et omylem, stiskn�te <b>Obnovit</b>.<br>";

// admin/setup.php, row 127, 191
$_m[" Init "]
    = " Inicializuj ";

// admin/setup.php, row 128, 205
$_m["Recover"]
    = "Obnovit";

// admin/setup.php, row 154
$_m["Database is not configured correctly or the database is empty.<br>\n"
."             Check please the database credentials in <b>include/config.php</b>\n"
."             file <br>or run <a href=\"../sql_update.php\">sql_update.php</a> script,\n"
."             which creates AA tables for you."]
    = "";

// admin/setup.php, row 200
$_m["Can't add primary permission object.<br>Please check the access settings to your permission system.<br>If you just deleted your superadmin account, use <b>Recover</b>"]
    = "";

// admin/setup.php, row 215
$_m["Can't delete invalid permission."]
    = "Nemohu smazat chybn� nastaven�.";

// admin/setup.php, row 217
$_m["Invalid permission deleted (no such user/group): "]
    = "Chybn� nastaven� smaz�no (neexistuje tento u�ivatel/skupina): ";

// admin/setup.php, row 286
$_m["Congratulations! The account was created."]
    = "Blahop�ejeme! ��et byl vytvo�en.";

// admin/setup.php, row 288
$_m["Use this account to login and add your first slice:"]
    = "Pou�ijte tento ��et k p�ihl�en� a vytvo�te prvn� web�k:";

// admin/   setup.php, row 292
$_m["Can't assign super access permission."]
    = "Nemohu p�idat superu�ivatelsk� pr�vo.";

// admin/se_users_add.php, row 65
$_m["Search user or group"]
    = "Hledej u�ivatele nebo skupinu";

// admin/se_users_add.php, row 82
// include/um_util.php, row 118
$_m["Assign new permissions"]
    = "P�i�azen� nov�ch pr�v";

// admin/se_users_add.php, row 119
$_m["Try to be more specific."]
    = "Zkuste zadat p�esn�j�� �daje.";

// admin/se_users_add.php, row 140
$_m["List is limitted to %1 users.<br>If some user is not in list, try to be more specific in your query"]
    = "D�lka seznamu je max. %1 u�ivatel�.<br>Pokud n�jak� u�ivatel nen� v seznamu, zkuste p�esn�j�� dotaz";

// admin/se_import.php, row 122
$_m["Admin - configure Content Pooling"]
    = "Spr�va web�ku - V�m�na zpr�v";

// admin/se_import.php, row 133
$_m["Enable export to slice:"]
    = "Povolit zas�l�n� zpr�v do web�ku:";

// admin/se_import.php, row 136
$_m["Export disable"]
    = "Zas�l�n� zak�z�no";

// admin/se_import.php, row 138
$_m["Export enable"]
    = "Zas�l�n� povoleno";

// admin/se_import.php, row 172
$_m["Enable export to any slice"]
    = "Povol exportovat zpr�vy do v�ech web�k�";

// admin/se_import.php, row 174
$_m["Currently exported to"]
    = "Nyn� exportov�n do";

// admin/se_import.php, row 177
$_m["Wrong export (non existant slice!):"]
    = "";

// admin/se_import.php, row 182
$_m["Import from slice:"]
    = "P�ij�mat zpr�vy z:";

// admin/se_import.php, row 191
$_m["Do not import"]
    = "Nep�ij�mat";

// admin/se_import.php, row 193
// include/menu_aa.php3, row 56
$_m["Import"]
    = "P�ij�mat";

// admin/console.php, row 40
$_m["comment out following \"exit;\" line in admin/console.php"]
    = "";

// admin/console.php, row 45
$_m["ActionApps onsole"]
    = "";

// admin/console.php, row 48
$_m["ActionApps Cosole"]
    = "";

// admin/se_taskmanager.php, row 40
$_m["You do not have permission to manage ActionApps tasks"]
    = "";

// central/synchronize.php, row 39, 47
$_m["Central - Synchronize ActionApps (1/3) - Select ActionApps for Comparison"]
    = "";

// central/synchronize.php, row 61
// central/synchronize2.php, row 77
// central/copyslice.php, row 61
// central/copyslice2.php, row 109
// central/synchronize3.php, row 172
$_m["Template ActionApps"]
    = "";

// central/synchronize.php, row 61
// central/copyslice.php, row 61
$_m["ActionApps installation used as template"]
    = "";

// central/synchronize.php, row 62
$_m["AA to compare"]
    = "";

// central/synchronize.php, row 62
$_m["ActionApps installation to check for differences (just checking right now - noting is changed by this step)"]
    = "";

// central/synchronize2.php, row 41, 49
$_m["Central - Synchronize ActionApps (2/3) - Slices to Compare"]
    = "";

// central/synchronize2.php, row 63
$_m["do not compare"]
    = "";

// central/synchronize2.php, row 66
$_m["Compare"]
    = "";

// central/synchronize2.php, row 78
$_m["Slice Mapping"]
    = "";

// central/responder.php, row 169
$_m["No request sent for responder.php"]
    = "";

// central/responder.php, row 175
$_m["Bad request sent for responder.php - %1"]
    = "";

// central/responder.php, row 180
$_m["You don't have permissions to run %1."]
    = "";

// central/copyslice.php, row 39, 47
$_m["Central - Copy Slice (1/2) - Select Source ActionApps"]
    = "";

// central/index.php3, row 62
$_m["You do not have permission to manage ActionApps instalations"]
    = "";

// central/index.php3, row 135
$_m["sql_upadte NOW!"]
    = "";

// central/index.php3, row 149
$_m["ActionApps Central"]
    = "";

// central/tabledit.php, row 61, 63
$_m["ActionApps Central - Edit"]
    = "";

// central/copyslice2.php, row 65
$_m["%1 import actions planed. See"]
    = "";

// central/copyslice2.php, row 66
// central/synchronize3.php, row 93
// include/menu.php, row 170
$_m["Task Manager"]
    = "";

// central/copyslice2.php, row 76, 84
$_m["Central - Copy Slice (2/2) - Slices to Copy"]
    = "";

// central/copyslice2.php, row 99
$_m["Copy"]
    = "";

// central/copyslice2.php, row 110
$_m["Modules to Copy from %1"]
    = "";

// central/copyslice2.php, row 111
$_m["Slices to copy"]
    = "";

// central/copyslice2.php, row 112
$_m["Copy also items"]
    = "";

// central/copyslice2.php, row 112
$_m["Copy also item data (items, discussions, ...) of selected slices above"]
    = "";

// central/copyslice2.php, row 113
$_m["Site modules to copy"]
    = "";

// central/copyslice2.php, row 114
$_m["Destination AAs"]
    = "";

// central/copyslice2.php, row 114
$_m["ActionApps installation to update"]
    = "";

// central/synchronize3.php, row 37
$_m["Comparation slice (%1) does not exist"]
    = "";

// central/synchronize3.php, row 92
$_m["%1 synchronization actions planed. See"]
    = "";

// central/synchronize3.php, row 100, 108
$_m["Central - Synchronize ActionApps (3/3) - Synchronize Slices"]
    = "";

// central/synchronize3.php, row 122, 171
$_m["Synchronize"]
    = "";

// central/synchronize3.php, row 132
$_m["Slice Comparison - %1 x %2"]
    = "";

// central/synchronize3.php, row 162
$_m["Hide/show info values"]
    = "";

// central/synchronize3.php, row 163
$_m["Check/Uncheck"]
    = "";

// central/synchronize3.php, row 173
$_m["Compared and Updated ActionApps"]
    = "";

// include/easy_scroller.php3, row 146, 272
$_m["Previous"]
    = "P�edchoz�";

// include/easy_scroller.php3, row 167
// include/scroller.php, row 314
$_m["All"]
    = "V�e";

// include/scroller.php, row 314
$_m["found"]
    = "nalezeno";

// include/files.class.php, row 97
$_m["Can't create directory for image uploads"]
    = "Nelze vytvo�it adres�� pro obr�zky";

// include/files.class.php, row 146
$_m["Can't read the file %1"]
    = "Soubor %1 nelze p�e��st";

// include/files.class.php, row 175
$_m["No destination file specified"]
    = "Nebyl specifikov�n c�lov� soubor";

// include/files.class.php, row 189
$_m["type of uploaded file not allowed"]
    = "Nedovolen� typ souboru";

// include/files.class.php, row 211
$_m["Can't move image  %1 to %2"]
    = "Nelze p�esunout soubor %1 na %2";

// include/files.class.php, row 218
$_m["Can't change permissions on uploaded file: %1 - %2. See IMG_UPLOAD_FILE_MODE in your config.php"]
    = "Nelze nastavit pr�va pro uploadovan� soubor: %1 - %2. Kokn�te na IMG_UPLOAD_FILE_MODE v config.php";

// include/files.class.php, row 239
$_m["Can't open file for writing: %1"]
    = "Nelze ote��t soubor pro z�pis: %1";

// include/files.class.php, row 245
$_m["Can't write to file: %1"]
    = "Do souboru %1 nelze zapisovat";

// include/files.class.php, row 290
$_m["Error: Invalid directory"]
    = "Error: Neplatn� adres��";

// include/files.class.php, row 313
$_m["can't create backup of the file"]
    = "Nelze vytvo�it z�lohu souboru";

// include/files.class.php, row 1311
$_m[": No such directory"]
    = "";

// include/perm_core.php, row 614
$_m["Reader Slice"]
    = "";

// include/perm_core.php, row 621
$_m["Reader Set"]
    = "";

// include/constants.php, row 169
$_m["MySQL Auth"]
    = "";

// include/constants.php, row 174
$_m["Jump inside AA control panel"]
    = "Skok uvnit� administrace";

// include/constants.php, row 178
$_m["Polls for AA"]
    = "Anketa v AA";

// include/constants.php, row 192
// include/menu_util.php, row 64, 169
// include/menu.php, row 220
$_m["Alerts"]
    = "Zas�l�n�";

// include/constants.php, row 201
// include/menu_util.php, row 66
$_m["Links"]
    = "Spr�va odkaz�";

// include/constants.php, row 356
$_m["Short Id"]
    = "ID (kr�tk�)";

// include/constants.php, row 361
$_m["Class"]
    = "T��da";

// include/constants.php, row 365
// include/constedit_util.php, row 124
$_m["Level"]
    = "�rove�";

// include/constants.php, row 407
$_m["Feed"]
    = "Kop�rovat obsah";

// include/constants.php, row 408
$_m["Do not feed"]
    = "Nekopirovat";

// include/constants.php, row 409
$_m["Feed locked"]
    = "Kop�rovat nem�niteln�";

// include/constants.php, row 410
$_m["Feed & update"]
    = "Kop�rovat obsah a zm�ny";

// include/constants.php, row 411
$_m["Feed & update & lock"]
    = "Kop�rovat obsah a zm�ny nem�niteln�";

// include/constants.php, row 484
$_m["Month List"]
    = "M�s�c - seznam";

// include/constants.php, row 484
$_m["Month Table"]
    = "M�s�c - tabulka";

// include/constants.php, row 504
$_m["Item listing"]
    = "P�ehled";

// include/constants.php, row 509, 617, 739, 790, 822
$_m["Row Delimiter"]
    = "";

// include/constants.php, row 518, 621, 649, 676, 700, 742, 766, 793, 825
$_m["Sort primary"]
    = "Se�adit";

// include/constants.php, row 520, 623, 651, 678, 702, 744, 768, 795, 827
$_m["Sort secondary"]
    = "Se�adit druhotn�";

// include/constants.php, row 527, 628, 749, 800, 832
$_m["Group title format"]
    = "Nadpis skupiny";

// include/constants.php, row 528, 629, 750, 801, 833
$_m["Group bottom format"]
    = "Spodn� k�d skupiny";

// include/constants.php, row 541, 561, 584, 642, 668, 722, 753, 781, 813, 845, 855
$_m["Add view ID as HTML comment"]
    = "Vlo�it ��slo pohledu do v�sledn�ho HTML";

// include/constants.php, row 546
$_m["Fulltext view"]
    = "�l�nek";

// include/constants.php, row 564
$_m["Discussion"]
    = "Diskuse";

// include/constants.php, row 566
$_m["HTML code for index view of the comment"]
    = "HTML k�d pro p�ehledov� zobrazen� p��sp�vku";

// include/constants.php, row 568
$_m["HTML code for \"Show selected\" button"]
    = "HTML k�d pro tla��tko \"Show selected\"";

// include/constants.php, row 569
$_m["HTML code for \"Show all\" button"]
    = "HTML k�d pro tla��tko \"Show all\"";

// include/constants.php, row 570
$_m["HTML code for \"Add\" button"]
    = "HTML k�d pro tla��tko \"Add\"";

// include/constants.php, row 571
$_m["Show images"]
    = "Zobrazit obr�zky";

// include/constants.php, row 572
$_m["Order by"]
    = "Se�adit";

// include/constants.php, row 573
$_m["View image 1"]
    = "Obr�zek 1";

// include/constants.php, row 574
$_m["View image 2"]
    = "Obr�zek 2";

// include/constants.php, row 575
$_m["View image 3"]
    = "Obr�zek 3";

// include/constants.php, row 576
$_m["View image 4"]
    = "Obr�zek 4";

// include/constants.php, row 577
$_m["HTML code for fulltext view of the comment"]
    = "HTML k�d pro pln� zn�n� p��sp�vku";

// include/constants.php, row 578
$_m["HTML code for space before comment"]
    = "HTML k�d pro mezeru p�ed pozn�mkou";

// include/constants.php, row 579
$_m["HTML code of the form for posting comment"]
    = "HTML k�d formul��e pro posl�n� p��sp�vku";

// include/constants.php, row 581
$_m["E-mail template"]
    = "�ablona e-mailu";

// include/constants.php, row 583
$_m["Number of e-mail template used for posting new comments to users"]
    = "��slo e-mailov� �ablony pro zas�l�n� p��sp�vk� u�ivatel�m";

// include/constants.php, row 588
$_m["Discussion To Mail"]
    = "Diskuze Emailem";

// include/constants.php, row 589
$_m["From: (email header)"]
    = "From: (hlavi�ka emailu)";

// include/constants.php, row 590
$_m["Reply-To:"]
    = "";

// include/constants.php, row 591
$_m["Errors-To:"]
    = "";

// include/constants.php, row 592
$_m["Sender:"]
    = "";

// include/constants.php, row 593
$_m["Mail Subject:"]
    = "P�edm�t emailu:";

// include/constants.php, row 594
$_m["Mail Body:"]
    = "T�lo emailu:";

// include/constants.php, row 613
$_m["View of Constants"]
    = "Zobrazen� konstant";

// include/constants.php, row 645
$_m["RSS exchange"]
    = "V�m�na zpr�v RSS";

// include/constants.php, row 666
$_m["Static page"]
    = "Statick� str�nka";

// include/constants.php, row 667
$_m["HTML code"]
    = "HTML k�d";

// include/constants.php, row 672
$_m["Javascript item exchange"]
    = "Javscript";

// include/constants.php, row 693
$_m["Calendar"]
    = "Kalend��";

// include/constants.php, row 694
$_m["Calendar Type"]
    = "Typ kalend��e";

// include/constants.php, row 696
$_m["Additional attribs to the TD event tag"]
    = "Dal�� atributy do TD tagu pro ud�lost";

// include/constants.php, row 697
$_m["Event format"]
    = "K�d ud�losti";

// include/constants.php, row 704
$_m["Start date field"]
    = "Pol��ko za��tku ud�losti";

// include/constants.php, row 705
$_m["End date field"]
    = "Pol��ko konce ud�losti";

// include/constants.php, row 706
$_m["Day cell top format"]
    = "Horn� k�d bu�ky s datem";

// include/constants.php, row 707
$_m["Day cell bottom format"]
    = "Doln� k�d bu�ky s datem";

// include/constants.php, row 708
$_m["Use other header for empty cells"]
    = "Pou��t jin� nadpis pro pr�zdn� bu�ky";

// include/constants.php, row 709
$_m["Empty day cell top format"]
    = "Horn� k�d pro pr�zdn� datum";

// include/constants.php, row 710
$_m["Empty day cell bottom format"]
    = "Spodn� k�d pro pr�zdn� datum";

// include/constants.php, row 728
$_m["Alerts Selection Set"]
    = "Zas�l�n� - v�b�ry";

// include/constants.php, row 731
$_m["Fulltext URL"]
    = "URL fulltextu";

// include/constants.php, row 734
$_m["Link to the .shtml page used\n"
."                                 to create headline links."]
    = "Odkaz na .shtml str�nku, kter� bude pou�it pro odkazy na �l�nky";

// include/constants.php, row 751
$_m["Max number of items"]
    = "Max po�et �l�nk�";

// include/constants.php, row 761
$_m["URL listing"]
    = "P�ehled URL";

// include/constants.php, row 763
$_m["Row HTML"]
    = "HTML pro ��dek";

// include/constants.php, row 785
$_m["Link listing"]
    = "V�pis odkaz� (Kormidlo)";

// include/constants.php, row 817
$_m["Category listing"]
    = "V�pis kategori� (Kormidlo)";

// include/constants.php, row 848
$_m["Input Form"]
    = "Vstupn� formul��";

// include/constants.php, row 850
$_m["New item form template"]
    = "Nov� �ablona pro vstupn� formul��";

// include/constants.php, row 851
$_m["Use different template for editing"]
    = "Pou��t jin� formul�� pro editaci zpr�vy";

// include/constants.php, row 852
$_m["Edit item form template"]
    = "�ablona pro Edita�n� formul��";

// include/constants.php, row 884
// include/discussion.php, row 206, 253
$_m["Show selected"]
    = "Zobraz vybran�";

// include/constants.php, row 885
// include/discussion.php, row 207, 255
$_m["Show all"]
    = "Zobraz v�e";

// include/constants.php, row 886
// include/discussion.php, row 209, 257
// include/constedit_util.php, row 116
$_m["Add new"]
    = "P�idej nov�";

// include/constants.php, row 906
$_m["Calendar: Time stamp at 0:00 of processed cell"]
    = "Kalend��: Time stamp v 0:00 p��slu�n�ho data";

// include/constants.php, row 907
$_m["Calendar: Time stamp at 24:00 of processed cell"]
    = "Kalend��: Time stamp v 24:00 p��slu�n�ho data";

// include/constants.php, row 908
$_m["Calendar: Day in month of processed cell"]
    = "Kalend��: Den v m�s�ci p��slu�n�ho data";

// include/constants.php, row 909
$_m["Calendar: Month number of processed cell"]
    = "Kalend��: ��slo m�s�ce p��slu�n�ho data";

// include/constants.php, row 910
$_m["Calendar: Year number of processed cell"]
    = "Kalend��: Rok p��slu�n�ho data";

// include/constants.php, row 972
$_m["Superadmin"]
    = "";

// include/scroller.php, row 78
$_m["Pgcnt"]
    = "";

// include/scroller.php, row 79
$_m["Current"]
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
// include/menu.php, row 160
$_m["Filters"]
    = "Filtry";

// include/scroller.php, row 85
$_m["Itmcnt"]
    = "";

// include/scroller.php, row 86
$_m["Metapage"]
    = "";

// include/scroller.php, row 87
$_m["Urldefault"]
    = "";

// include/item_content.php, row 579
$_m["No Slice Id specified"]
    = "";

// include/item_content.php, row 764
$_m["No Id specified (%1 - %2)"]
    = "ID nezad�no (%1 - %2)";

// include/item_content.php, row 769
$_m["Duplicated ID - skiped (%1 - %2)"]
    = "Duplicitn� ID - p�esko�eno (%1 - %2)";

// include/item_content.php, row 783
$_m["StoreItem for slice %1 - failed parameter check for id = '%2'"]
    = "";

// include/tabledit.php, row 263
$_m["No record matches your search condition."]
    = "��dn� z�znam neodpov�d� vyhled�vac� podm�nce.";

// include/tabledit.php, row 396
$_m["order ascending"]
    = "se�adit vzestupn�";

// include/tabledit.php, row 397
$_m["order descending"]
    = "se�adit sestupn�";

// include/tabledit.php, row 478
$_m["Nothing to be shown."]
    = "Nen� co zobrazit";

// include/tabledit.php, row 580
$_m["search"]
    = "vyhledat";

// include/tabledit.php, row 699, 797
$_m["edit"]
    = "upravit";

// include/tabledit.php, row 803
$_m["add"]
    = "p�idat";

// include/tabledit.php, row 809, 817
$_m["delete"]
    = "smazat";

// include/tabledit.php, row 817, 830
$_m["insert"]
    = "vlo�it";

// include/tabledit.php, row 824
$_m["delete checked"]
    = "smazat ozna�en�";

// include/tabledit.php, row 830
$_m["update"]
    = "odeslat";

// include/tabledit.php, row 836
$_m["update all"]
    = "ulo�it v�e";

// include/tabledit.php, row 842
$_m["browse"]
    = "proj�t";

// include/tabledit.php, row 906
$_m["Are you sure you want to permanently DELETE all the checked records?"]
    = "Jste si jisti, �e chcete nav�dy SMAZAT v�echny ozna�en� z�znamy?";

// include/constedit.php, row 55
$_m["Constants - Hiearchical editor"]
    = "Konstanty - Hiearchick� editor";

// include/constedit.php, row 58
$_m["Changes are not saved into database until you click on the button at the bottom of this page.<br>Constants are sorted first by Priority, second by Name."]
    = "Zm�ny nebudou ulo�eny do datab�ze, dokud nestisknete tla��tko dole na str�nce.<br>Konstanty jsou �azeny zaprv� podle �azen� a zadruh� podle N�zvu.";

// include/constedit.php, row 79
$_m["Copy value from name"]
    = "Kop�rovat hodnotu z n�zvu";

// include/constedit.php, row 92
$_m["Check to confirm deleting"]
    = "Zatrhn�te pro potvrzen� maz�n�";

// include/constedit.php, row 95
$_m["Save all changes to database"]
    = "Ulo�it zm�ny do datab�ze";

// include/constedit.php, row 96
$_m["View settings"]
    = "Zobrazen�";

// include/constedit.php, row 96
$_m["Hierarchical"]
    = "Hierarchck�";

// include/constedit.php, row 97
$_m["Hide value"]
    = "Skryj hodnotu";

// include/constedit.php, row 98
$_m["Levels horizontal"]
    = "�rovn� horizont�ln�";

// include/constedit.php, row 99
// include/widget.class.php, row 1168
$_m["Level count"]
    = "Po�et �rovn�";

// include/statestore.php, row 669
// include/searchlib.php, row 101
$_m["select ..."]
    = "vyber ...";

// include/searchbar.class.php, row 82
$_m["Condition"]
    = "";

// include/searchbar.class.php, row 83
$_m["Readonly"]
    = "";

// include/searchbar.class.php, row 176
$_m["Search row"]
    = "";

// include/searchbar.class.php, row 177
$_m["Order row"]
    = "";

// include/searchbar.class.php, row 558
$_m["And"]
    = "a z�rove�";

// include/searchbar.class.php, row 567
// include/searchlib.php, row 39, 92, 101
$_m["contains"]
    = "obsahuje";

// include/searchbar.class.php, row 568
// include/searchlib.php, row 40, 92, 101
$_m["begins with"]
    = "za��n�";

// include/searchbar.class.php, row 569
// include/searchlib.php, row 92, 101
$_m["is"]
    = "je";

// include/searchbar.class.php, row 598
$_m["Order"]
    = "Se�adit";

// include/searchbar.class.php, row 617
// include/formutil.php, row 1993
$_m["Clear"]
    = "Vy�istit";

// include/searchbar.class.php, row 620
$_m["Stored search name"]
    = "Jm�no filtru";

// include/searchbar.class.php, row 621
$_m["You have the permission to add stored search globally. Do you want to add this query as global (common to all slice users)?"]
    = "Ulo�it glob�ln�? Filtr m��ete ulo�it tak, �e bude viditeln� pro v�echny u�ivatele tohoto web�ku - tedy glob�ln� [ OK ], �i pouze jako v� osobn� filtr [ Storno ]. M�-li b�t filtr glob�ln� - klikn�te na OK, m�-li b�t v� osobn� - klikn�te na Storno (pop�. Cancel).";

// include/searchbar.class.php, row 621
$_m["Store"]
    = "Ulo�it";

// include/searchbar.class.php, row 636
$_m["Stored searches"]
    = "Ulo�en� filtry";

// include/searchbar.class.php, row 639
$_m["View"]
    = "Filtruj";

// include/searchbar.class.php, row 642
$_m["Are you sure to refine current search?"]
    = "Opravdu chcete p�edefinovat vybran� filtr?";

// include/searchbar.class.php, row 643
$_m["Enter new name"]
    = "Zadej nov� jm�no";

// include/searchbar.class.php, row 643
$_m["Rename"]
    = "P�ejmenovat";

// include/searchbar.class.php, row 644
$_m["Are you sure to delete selected search?"]
    = "Opravdu smazat vybran� filtr?";

// include/searchbar.class.php, row 935
$_m["Select one..."]
    = "vyber ...";

// include/modutils.php, row 75
// include/slicedit.php, row 163
$_m["Used Language File"]
    = "Pou�it� language soubor";

// include/modutils.php, row 179
$_m["No such module."]
    = "Modul neexistuje.";

// include/formutil.php, row 63
$_m["Add&nbsp;Mutual"]
    = "P�idat&nbsp;Vz�jemn�";

// include/formutil.php, row 64
$_m["Backward"]
    = "Zp�tn�";

// include/formutil.php, row 66
$_m["Good"]
    = "Dobr�";

// include/formutil.php, row 68
$_m["Bad"]
    = "�patn�";

// include/formutil.php, row 193
$_m["Update & View"]
    = "Zm�nit a prohl�dnout";

// include/formutil.php, row 193
$_m["Update & Edit"]
    = "Zm�nit a editovat d�le";

// include/formutil.php, row 197
$_m["Insert as new"]
    = "Vlo�it jako nov�";

// include/formutil.php, row 203
$_m["Insert & View"]
    = "Vlo�it a prohl�dnout";

// include/formutil.php, row 203
$_m["Insert & Edit"]
    = "Vlo�it a editovat d�le";

// include/formutil.php, row 220
$_m["Part"]
    = "Strana";

// include/formutil.php, row 475
$_m["There are too many items."]
    = "P��li� mnoho �l�nk�";

// include/formutil.php, row 892
$_m["set"]
    = "zapnuto";

// include/formutil.php, row 892
$_m["unset"]
    = "vypnuto";

// include/formutil.php, row 1004
$_m["Unable to find tagprefix table %1"]
    = "";

// include/formutil.php, row 1127
$_m["import"]
    = "na��st";

// include/formutil.php, row 1144
$_m["Edit in HTMLArea"]
    = "Zobraz Editor";

// include/formutil.php, row 1615, 1619
$_m["Enter the value"]
    = "Vlo�te hodnotu";

// include/formutil.php, row 1619
// include/menu.php, row 145
$_m["Change"]
    = "Zm�nit";

// include/formutil.php, row 1658
$_m["Item"]
    = "�l�nek";

// include/formutil.php, row 1669
$_m["Move up"]
    = "Nahoru";

// include/formutil.php, row 1670
$_m["Move down"]
    = "Dol�";

// include/formutil.php, row 1759, 1893
// include/widget.class.php, row 977
$_m["Selected"]
    = "Vybran�";

// include/formutil.php, row 1892
$_m["Offer"]
    = "Nab�dnout";

// include/formutil.php, row 1943
$_m["Change Password"]
    = "Zm�na Hesla";

// include/formutil.php, row 1945
$_m["Delete Password"]
    = "Smazat Heslo";

// include/formutil.php, row 1953
// include/searchlib.php, row 44, 92, 95, 98, 101
$_m["not set"]
    = "nenastaveno";

// include/formutil.php, row 2734
$_m["Submit"]
    = "Poslat";

// include/formutil.php, row 2973
$_m["Not used, yet"]
    = "Dosud nepou�ito";

// include/formutil.php, row 3006
$_m["Group Name"]
    = "Jm�no skupiny";

// include/formutil.php, row 3006
$_m["Created by"]
    = "Vytvo�il";

// include/formutil.php, row 3007
$_m["Created on"]
    = "Vytvo�eno";

// include/formutil.php, row 3007
$_m["Last updated"]
    = "Naposledy aktualizov�no";

// include/formutil.php, row 3007
$_m["Last used"]
    = "Naposledy pou�ito";

// include/formutil.php, row 3013
$_m["All active items"]
    = "V�echy aktu�ln� �l�nky";

// include/formutil.php, row 3015
$_m["All pending items"]
    = "";

// include/formutil.php, row 3016
$_m["All expired items"]
    = "";

// include/formutil.php, row 3017
$_m["All items in holding bin"]
    = "";

// include/formutil.php, row 3018
$_m["All items in trash bin"]
    = "";

$_m["translate from"]
    = "p�elo�it z";

// include/stringexpand.php, row 417
$_m["Jabber Online Status Indicator"]
    = "";

// include/stringexpand.php, row 2450
$_m["Date \\ Item ID"]
    = "";

// include/util.php, row 981, 1038
// include/slice.class.php, row 247
$_m["Error: Missing Reading Password"]
    = "Chyba: Sch�z� Heslo pro �ten�";

// include/util.php, row 1375
// include/msgpage.php, row 63
$_m["Toolkit news message"]
    = "Zpr�va aplikace";

// include/util.php, row 1608
$_m["Internal error. File upload: Dir does not exist?!"]
    = "Intern� chyba p�i uploadu souboru: Adres�� neexistuje?!";

// include/util.php, row 1612
$_m["File with this name already exists."]
    = "Soubor s t�mto n�zvem u� existuje.";

// include/util.php, row 1619
$_m["Can't move image  %s to %s"]
    = "Nelze p�esunout obr�zek %s na %s";

// include/util.php, row 1867
$_m["alerts alert"]
    = "zas�l�n� zpr�v - zpr�va";

// include/util.php, row 1868
$_m["alerts welcome"]
    = "zas�l�n� zpr�v - v�tejte";

// include/util.php, row 1869
$_m["slice wizard welcome"]
    = "uv�t�n� z pr�vodce p�id�n�m web�ku";

// include/util.php, row 1870
$_m["other"]
    = "jin�";

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

// include/discussion.php, row 215
$_m["Alias for subject of the discussion comment"]
    = "Alias pro p�edm�t p��sp�vku";

// include/discussion.php, row 216
$_m["Alias for text of the discussion comment"]
    = "Alias pro diskusn� p��sp�vek";

// include/discussion.php, row 217
$_m["Alias for written by"]
    = "Alias pro autora diskusn�ho p��sp�vku";

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
    = "Alias pro IP adresu pisatele";

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
    = "3. parametr vypln�n� v pol��ku DiscussionMailList";

// include/discussion.php, row 415
$_m["%1th parameter filled in DiscussionMailList field"]
    = "%1. parametr vypln�n� v pol��ku DiscussionMailList";

// include/loginform.inc, row 39
$_m["Welcome!"]
    = "P�ihl�en� (Login) - <a href='http://www.ecn.cz'>Econnect</a> Toolkit 2.90";

// include/loginform.inc, row 41
$_m["Welcome! Please identify yourself with a username and a password:"]
    = "<br>\n\n"
    ."     <br><br>P�ihla�te se pros�m Va��m jm�nem a heslem\n\n"
    ."     <br>(Welcome! Log in by your name and password):";

// include/loginform.inc, row 50
$_m["Username:"]
    = "U�ivatelsk� jm�no<br>(User name):  ";

// include/loginform.inc, row 53
$_m["Type your username or mail"]
    = " ";

// include/loginform.inc, row 56
$_m["Password:"]
    = "Heslo<br>(Password):";

// include/loginform.inc, row 60
$_m["Login now"]
    = "P�ihl�sit";

// include/loginform.inc, row 73
$_m["Please try again!"]
    = "Zkuste to znovu, pros�m!<br>Please try again!";

// include/loginform.inc, row 76
$_m["If you are sure you have typed the correct password, please e-mail <a href=mailto:%1>%1</a>."]
    = "Pokud jste si jisti, �e zad�v�te spr�vn� jm�no a heslo, obra�te se pros�m \n\n"
    ."     na <a href=\"mailto:%1\">%1</a>.\n\n"
    ."     <br>If you are sure you have typed the correct password, please e-mail \n\n"
    ."     <a href=\"mailto:%1\">%1</a>.";

// include/um_gedit.php, row 43
$_m["It is impossible to add group to permission system"]
    = "Nelze p�idat skupinu do syst�mu";

// include/um_gedit.php, row 55
$_m["Can't change group"]
    = "Nelze zm�nit skupinu";

// include/mlx.php, row 418
$_m["view"]
    = "pohled";

// include/mlx.php, row 461
$_m["Bad item ID %1"]
    = "Chybn� ��slo �l�nku";

// include/mlx.php, row 466
$_m["No ID for MLX"]
    = "";

// include/item.php, row 92
// include/itemview.php, row 108
// include/field.class.php, row 394
$_m["number of found items"]
    = "po�et nalezen�ch �l�nk�";

// include/item.php, row 93
// include/field.class.php, row 395
$_m["index of item within whole listing (begins with 0)"]
    = "po�ad� �l�nku v cel�m v�pisu (��slov�no od 0)";

// include/item.php, row 94
// include/field.class.php, row 396
$_m["index of item within a page (it begins from 0 on each page listed by pagescroller)"]
    = "po�ad� �l�nku v na str�nce (��slov�no od 0 pro ka�dou str�nku)";

// include/item.php, row 95
// include/field.class.php, row 397
$_m["alias for Item ID"]
    = "alias pro ��slo �l�nku";

// include/item.php, row 96
// include/field.class.php, row 398
$_m["alias for Short Item ID"]
    = "alias pro zkr�cen� ��slo �l�nku";

// include/item.php, row 102, 103
// include/field.class.php, row 404, 405
$_m["alias used on admin page index.php3 for itemedit url"]
    = "alias pou��van� v administrativn�ch str�nk�ch index.php3 pro URL itemedit.php3";

// include/item.php, row 104
// include/field.class.php, row 406
$_m["Alias used on admin page index.php3 for edit discussion url"]
    = "Alias pou��van� v administrativn�ch str�nk�ch index.php3 pro URL discedit.php";

// include/item.php, row 105
// include/field.class.php, row 407
$_m["Title of Slice for RSS"]
    = "Jm�no web�ku pro RSS";

// include/item.php, row 106
// include/field.class.php, row 408
$_m["Link to the Slice for RSS"]
    = "Odkaz na web�k pro RSS";

// include/item.php, row 107
// include/field.class.php, row 409
$_m["Short description (owner and name) of slice for RSS"]
    = "Kr�tk� popisek (vlastn�k a jm�no) web�ku pro RSS";

// include/item.php, row 108
// include/field.class.php, row 410
$_m["Date RSS information is generated, in RSS date format"]
    = "Datum v RSS p�ehledu je generov�no v datov�m form�tu RSS";

// include/item.php, row 109
// include/tv_email.php, row 57
// include/field.class.php, row 411
$_m["Slice name"]
    = "N�zev web�ku";

// include/item.php, row 111
// include/field.class.php, row 413
$_m["Current MLX language"]
    = "Sou�asn� jazyk MLX";

// include/item.php, row 112
// include/field.class.php, row 414
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
$_m["Constant unique id (32-hexadecimal characters)"]
    = "Jedine�n� ID (32 hexa znak�)";

// include/item.php, row 147
$_m["Constant unique short id (autoincremented from '1' for each constant in the system)"]
    = "Kr�tk� jedine�n� ID (po��tadlo od '1' pro v�echny konstanty)";

// include/item.php, row 148
$_m["Constant description"]
    = "Popis konstanty";

// include/item.php, row 149
$_m["Constant level (used for hierarchical constants)"]
    = "�rove� konstanty (pou�ito v hierarchick�ch)";

// include/item.php, row 189
$_m["Alias for %1"]
    = "Alias pro %1";

// include/item.php, row 1415
$_m["on"]
    = "zap";

// include/item.php, row 1415
$_m["off"]
    = "vyp";

// include/item.php, row 1584
$_m["Home"]
    = "Dom�";

// include/actions.php, row 60
$_m["Move to Active"]
    = "Vystavit";

// include/actions.php, row 61
$_m["Move to Holding bin"]
    = "Poslat do z�sobn�ku";

// include/actions.php, row 62
$_m["Move to Trash"]
    = "Poslat do ko�e";

// include/actions.php, row 157, 250
$_m["No slice selected"]
    = "Nebyl vybr�n ��dn� web�k";

// include/actions.php, row 201
$_m["Move to another slice"]
    = "";

// include/actions.php, row 214
$_m["Slice ID"]
    = "";

// include/actions.php, row 236
$_m["Move to slice"]
    = "";

// include/actions.php, row 256
$_m["You have not permissions to move items"]
    = "Nem�te pr�vo p�esouvat zpr�vy";

// include/actions.php, row 311
$_m["Remove (delete from database)"]
    = "Odstranit (z datab�ze)";

// include/actions.php, row 325, 544
$_m["You have not permissions to remove items"]
    = "Nem�te pr�vo mazat zpr�vy";

// include/actions.php, row 401
$_m["Modify content"]
    = "Hromadn� zm�nit";

// include/actions.php, row 419
$_m["Send email"]
    = "Poslat e-mail";

// include/actions.php, row 483
$_m["Remove (cancel task)"]
    = "";

// include/actions.php, row 497
$_m["You have not permissions to remove tasks"]
    = "";

// include/actions.php, row 529
$_m["Execute"]
    = "";

// include/tv_email.php, row 37
$_m["Aliases for Alerts Alert"]
    = "Aliasy pro zpr�vu ze Zas�l�n�";

// include/tv_email.php, row 39
$_m["complete filter text"]
    = "�pln� text v�b�r�";

// include/tv_email.php, row 40, 48
$_m["howoften"]
    = "jak �asto";

// include/tv_email.php, row 41
$_m["Anonym Form URL (set in Alerts Admin - Settings)"]
    = "URL anonymn�ho formul��e (nastaveno v Nastaven� Zas�l�n�)";

// include/tv_email.php, row 42
$_m["Unsubscribe Form URL"]
    = "URL pro odhl�en� (unsubscribe)";

// include/tv_email.php, row 46
$_m["Aliases for Alerts Welcome"]
    = "Aliasy pro uv�t�n� v Zas�l�n�";

// include/tv_email.php, row 49
$_m["Collection Form URL (set in Alerts Admin - Settings)"]
    = "URL formul��e s nastaven�m Zas�l�n�";

// include/tv_email.php, row 50
$_m["email confirmed"]
    = "email potvrzen";

// include/tv_email.php, row 55
$_m["Aliases for Slice Wizard Welcome"]
    = "Aliasy pro uv�t�n� z Pr�vodce p�id�n�m web�ku";

// include/tv_email.php, row 58
$_m["New user name"]
    = "Jm�no nov�ho u�ivatele";

// include/tv_email.php, row 59
$_m["New user login name"]
    = "Login nov�ho u�ivatele";

// include/tv_email.php, row 60
$_m["New user role (editor / admin)"]
    = "Role nov�ho u�ivatele (editor / admin)";

// include/tv_email.php, row 61
$_m["My name"]
    = "Moje jm�no";

// include/tv_email.php, row 62
$_m["My email"]
    = "M�j email";

// include/tv_email.php, row 97
$_m["Email template"]
    = "�ablona emailu";

// include/tv_email.php, row 110
$_m["Email type"]
    = "Typ e-mailu";

// include/tv_email.php, row 158
// include/menu.php, row 169
$_m["Email templates"]
    = "�ablony email�";

// include/tv_email.php, row 178
$_m["Reply to"]
    = "";

// include/tv_email.php, row 180
$_m["Errors to"]
    = "";

// include/tv_email.php, row 182
$_m["Sender"]
    = "";

// include/menu_util.php, row 65
$_m["Jump inside control panel"]
    = "Skok uvnit� administrace";

// include/menu_util.php, row 67
$_m["MySQL Auth (old version)"]
    = "";

// include/menu_util.php, row 68
$_m["Polls"]
    = "Ankety";

// include/menu_util.php, row 69
$_m["Site"]
    = "";

// include/menu_util.php, row 71
$_m["Reader Management Slice"]
    = "Web�k Spr�va �ten���";

// include/menu_util.php, row 106, 182
$_m["New slice"]
    = "Nov� web�k";

// include/menu_util.php, row 173
$_m["Reader management"]
    = "Spr�va �ten���";

// include/menu_util.php, row 188
$_m["logout"]
    = "odhl�sit";

// include/menu_util.php, row 371
$_m["Copyright (C) 2001 the <a href=\"https://www.apc.org\">Association for Progressive Communications (APC)</a>"]
    = "";

// include/date.php, row 161
// include/validate.php, row 450, 505
$_m["Error in"]
    = "Chyba v";

// include/filedit.php, row 89
$_m["Back to file list"]
    = "Zp�t na seznam soubor�";

// include/filedit.php, row 90
$_m["Download (right-click)"]
    = "Download (pravou my��)";

// include/filedit.php, row 91
$_m["Rename to"]
    = "P�ejmenovat na";

// include/filedit.php, row 96
// include/fileman.php, row 110, 465
$_m["Text file"]
    = "Text";

// include/filedit.php, row 96
// include/fileman.php, row 108
$_m["Web file"]
    = "Web";

// include/filedit.php, row 96
// include/fileman.php, row 107, 465
$_m["HTML file"]
    = "HTML";

// include/filedit.php, row 135
$_m["Save changes"]
    = "Nahr�t zm�ny";

// include/filedit.php, row 136
$_m["Reset content"]
    = "Obnovit obsah";

// include/filedit.php, row 142
$_m["File content"]
    = "Obsah souboru";

// include/filedit.php, row 151
// include/fileman.php, row 109
$_m["Image file"]
    = "Obr�zek";

// include/filedit.php, row 154
$_m["This is a file of type"]
    = "Toto je soubor typu";

// include/filedit.php, row 154
$_m["I can't view it. If you want to view or edit it, change it's extension."]
    = "Nemohu zobrazit. Chcete-li zobrazit nebo upravit tento soubor, zm��te jeho p��ponu.";

// include/metabase.class.php, row 883
$_m["Manage %1"]
    = "";

// include/slice.php3, row 78
$_m["Select Category "]
    = "Zvolte Kategorii ";

// include/slice.php3, row 80
$_m["All categories"]
    = "V�echny kategorie";

// include/slicedit.php, row 48
$_m["User not found"]
    = "U�ivatel nenalezen";

// include/slicedit.php, row 65
$_m["Slice not found."]
    = "Web�k nenalezen";

// include/slicedit.php, row 79
$_m["Error mailing"]
    = "Chyba p�i mailov�n�";

// include/slicedit.php, row 157
$_m["Upload URL"]
    = "";

// include/slicedit.php, row 165
$_m["Language Control Slice"]
    = "Web�k spr�vy p�eklad�";

// include/slicedit.php, row 172
$_m["This File Manager Directory is already used by another slice."]
    = "Tento Adres�� pro spr�vu soubor� je ji� vyu��v�n v jin�m web�ku.";

// include/slicedit.php, row 306
$_m["Error when copying constants."]
    = "Chyba p�i kop�rov�n� konstant.";

// include/slicedit.php, row 315
$_m["Error when copying views."]
    = "Chyba p�i kop�rov�n� pohled�.";

// include/slicedit.php, row 322
$_m["Internal error when changing user role."]
    = "Intern� chyba p�i zm�n� role u�ivatele.";

// include/menu.php, row 64
$_m["View site"]
    = "Zobraz";

// include/menu.php, row 79, 80, 151
$_m["Item Manager"]
    = "Spr�va zpr�v";

// include/menu.php, row 87
$_m["Slice Admin"]
    = "Nastaven�";

// include/menu.php, row 95
$_m["AA"]
    = "";

// include/menu.php, row 96
$_m["AA Administration"]
    = "Administrace AA Toolkitu";

// include/menu.php, row 103
$_m["Central"]
    = "";

// include/menu.php, row 104
$_m["AA Central"]
    = "";

// include/menu.php, row 135
$_m["Main settings"]
    = "Hlavn� nastaven�";

// include/menu.php, row 137
$_m["Category"]
    = "Kategorie";

// include/menu.php, row 139
$_m["Slice Fields"]
    = "Pol��ka web�ku";

// include/menu.php, row 140
$_m["Email Notification"]
    = "Upozorn�n� e-mailem";

// include/menu.php, row 143
// include/um_util.php, row 90, 99
$_m["Permissions"]
    = "Nastaven� pr�v";

// include/menu.php, row 144
$_m["Assign"]
    = "P�idat";

// include/menu.php, row 147
$_m["Design"]
    = "Vzhled";

// include/menu.php, row 148
$_m["Index"]
    = "P�ehled zpr�v";

// include/menu.php, row 149
$_m["Fulltext"]
    = "Cel� zpr�va";

// include/menu.php, row 150
$_m["Views"]
    = "Pohledy";

// include/menu.php, row 152
$_m["Sets of Items"]
    = "";

// include/menu.php, row 154
$_m["Content Pooling"]
    = "V�m�na zpr�v";

// include/menu.php, row 155
$_m["Nodes"]
    = "Uzly";

// include/menu.php, row 156
$_m["Inner Node Feeding"]
    = "Lok�ln� v�m�na";

// include/menu.php, row 157
$_m["Inter Node Import"]
    = "P��jem z uzl�";

// include/menu.php, row 158
$_m["Inter Node Export"]
    = "Zas�l�n� do uzl�";

// include/menu.php, row 159
$_m["RSS Feeds"]
    = "RSS kan�ly";

// include/menu.php, row 161
$_m["Mapping"]
    = "Mapov�n�";

// include/menu.php, row 162, 205
$_m["Import CSV"]
    = "";

// include/menu.php, row 164, 202, 247
// include/menu_aa.php3, row 67
$_m["Misc"]
    = "R�zn�";

// include/menu.php, row 165
$_m["Change field IDs"]
    = "Zm�na ID pol��ka";

// include/menu.php, row 168
$_m["Anonymous Form Wizard"]
    = "Pr�vodce Anonymn�m Formul��em";

// include/menu.php, row 178
$_m["Mailman: create list"]
    = "Mailman: vytvo�it seznam";

// include/menu.php, row 185, 242
$_m["Folders"]
    = "Ostatn� zpr�vy";

// include/menu.php, row 188
$_m["... pending"]
    = "... p�ipraven�";

// include/menu.php, row 189
$_m["... expired"]
    = "... expirovan�";

// include/menu.php, row 191, 245
$_m["Trash bin"]
    = "Ko�";

// include/menu.php, row 194
$_m["Bookmarks"]
    = "";

// include/menu.php, row 203
$_m["Setting"]
    = "&nbsp;";

// include/menu.php, row 204, 251
$_m["Empty trash"]
    = "Vysypat ko�";

// include/menu.php, row 204, 251
$_m["Are You sure to empty trash?"]
    = "Opravdu chcete vymazat zpr�vy z ko�e?";

// include/menu.php, row 206, 252
$_m["Set Debug OFF"]
    = "";

// include/menu.php, row 206, 252
$_m["Set Debug ON"]
    = "";

// include/menu.php, row 221
$_m["List of Alerts modules using this slice as Reader Management."]
    = "Seznam modul� Zas�l�n�, kter� pou��vaj� tento web�k jako Spr�vu �ten���";

// include/menu.php, row 223
$_m["Bulk Emails"]
    = "Hromadn� e-mail";

// include/menu.php, row 223
$_m["Send bulk email to selected users or to users in Stored searches"]
    = "Zaslat hromadn� e-mail vybran�m u�ivatel�m";

// include/menu.php, row 226
$_m["Send emails"]
    = "Poslat emaily";

// include/menu.php, row 235
$_m["Alerts Sent"]
    = "Zas�l�no p�es";

// include/menu.php, row 235
$_m["List of Alerts modules sending items from this slice."]
    = "Seznam modul� Zas�l�n�, kter� pos�laj� �l�nky z tohoto web�ku.";

// include/menu.php, row 248
$_m["Add AA"]
    = "";

// include/menu.php, row 249
$_m["Synchronize..."]
    = "";

// include/menu.php, row 250
$_m["Copy Slice..."]
    = "";

// include/profile.class.php, row 300, 310, 319, 328, 337, 345
$_m["Rule added"]
    = "Pravidlo p�id�no";

// include/menu_aa.php3, row 38
$_m["Slices / Modules"]
    = "Web�ky / Moduly";

// include/menu_aa.php3, row 39
$_m["Create new"]
    = "Nov�";

// include/menu_aa.php3, row 40
$_m["Create new Wizard"]
    = "Pr�vodce P�id�n�m Web�ku";

// include/menu_aa.php3, row 42
$_m["Edit Jump"]
    = "Editovat Jump";

// include/menu_aa.php3, row 54
$_m["Slice structure"]
    = "Struktura web�ku";

// include/menu_aa.php3, row 58
$_m["Wizard"]
    = "Pr�vodce";

// include/menu_aa.php3, row 59
$_m["Welcomes"]
    = "V�tac� maily";

// include/menu_aa.php3, row 60
$_m["Templates"]
    = "�ablony";

// include/menu_aa.php3, row 62
$_m["Feeds"]
    = "";

// include/menu_aa.php3, row 63
$_m["RSS test"]
    = "";

// include/menu_aa.php3, row 64
$_m["AA RSS test"]
    = "";

// include/menu_aa.php3, row 65
$_m["Run feeding"]
    = "";

// include/menu_aa.php3, row 68
// include/tv_misc.php, row 129, 130
$_m["Cron"]
    = "";

// include/menu_aa.php3, row 69
$_m["View Log"]
    = "Zobrazit Log";

// include/menu_aa.php3, row 70
$_m["View SearchLog"]
    = "Zobrazit SearchLog";

// include/menu_aa.php3, row 72
$_m["Mgettext"]
    = "";

// include/menu_aa.php3, row 73
$_m["Optimize"]
    = "";

// include/menu_aa.php3, row 74
$_m["Summarize"]
    = "";

// include/menu_aa.php3, row 75
$_m["Synchronize AA"]
    = "";

// include/menu_aa.php3, row 76
$_m["History"]
    = "";

// include/grabber.class.php3, row 257
$_m["CSV"]
    = "";

// include/grabber.class.php3, row 265
$_m["Import data from CSV (Comma Separated Values) format"]
    = "";

// include/grabber.class.php3, row 330
$_m["AA RSS"]
    = "";

// include/grabber.class.php3, row 336
$_m["Grabs data from generic RSS or AA RSS (used for item exchange between different AA installations)"]
    = "";

// include/grabber.class.php3, row 672
$_m["Form"]
    = "";

// include/grabber.class.php3, row 677
$_m["Grabbs data POSTed by AA form"]
    = "";

// include/sliceadd.php, row 103
$_m["No slices"]
    = "��dn� web�k";

// include/fileman.php, row 33
$_m["Size"]
    = "Velikost";

// include/fileman.php, row 35
$_m["Last modified"]
    = "Naposled upraveno";

// include/fileman.php, row 113, 135
$_m["Other"]
    = "Jin�";

// include/fileman.php, row 297
$_m["Wrong file name."]
    = "Chybn� n�zev souboru.";

// include/fileman.php, row 305
$_m["File already exists"]
    = "Soubor ji� existuje.";

// include/fileman.php, row 309, 468
$_m["Unable to create file"]
    = "Nepoda�ilo se vytvo�it soubor";

// include/fileman.php, row 320
$_m["Wrong directory name."]
    = "Chybn� n�zev adres��e.";

// include/fileman.php, row 326
$_m["Unable to create directory"]
    = "Nepoda�ilo se vytvo�it adres��";

// include/fileman.php, row 337
$_m["First delete all files from directory"]
    = "Nejd��ve odstra�te v�echny soubory z adres��e";

// include/fileman.php, row 339
$_m["Unable to delete directory"]
    = "Nepoda�ilo se smazat adres��";

// include/fileman.php, row 344
$_m["Unable to delete file"]
    = "Nepoda�ilo se smazat soubor";

// include/fileman.php, row 363
$_m["Error: "]
    = "Chyba: ";

// include/fileman.php, row 375
$_m["Unable to open file for writing"]
    = "Nepoda�ilo se otev��t soubor pro psan�";

// include/fileman.php, row 383
$_m["Error writing to file"]
    = "Chyba p�i z�pisu do souboru";

// include/fileman.php, row 394
$_m["File with this name already exists"]
    = "Soubor s t�mto jm�nem ji� existuje";

// include/fileman.php, row 397
$_m["Unable to rename"]
    = "Nepoda�ilo se p�ejmenovat";

// include/fileman.php, row 450
$_m["Wrong directory name"]
    = "Chybn� n�zev adres��e";

// include/fileman.php, row 460
$_m["Files with the same names as some in the template already exist. Please change the file names first."]
    = "";

// include/fileman.php, row 515
$_m["Are you sure you want to delete the selected files and folders?"]
    = "Jste si jisti, �e chcete smazat zvolen� soubory a adres��e?";

// include/manager.class.php, row 83
$_m["Searchbar"]
    = "";

// include/manager.class.php, row 84
$_m["Scroller"]
    = "";

// include/manager.class.php, row 85
$_m["Msg"]
    = "";

// include/manager.class.php, row 86
$_m["Bin"]
    = "";

// include/manager.class.php, row 87
$_m["Module ID"]
    = "";

// include/manager.class.php, row 147
$_m["No item found"]
    = "��dn� zpr�va";

// include/manager.class.php, row 198
$_m["Publish date"]
    = "Datum vystaven�";

// include/manager.class.php, row 198
$_m["Headline"]
    = "Nadpis";

// include/manager.class.php, row 597
$_m["Go"]
    = "Je�";

// include/manager.class.php, row 614
$_m["Items Page"]
    = "Str�nka s �l�nky";

// include/manager.class.php, row 663
$_m["Action ID"]
    = "";

// include/manager.class.php, row 664
$_m["URL to open"]
    = "";

// include/manager.class.php, row 665
$_m["Additional URL parameters"]
    = "";

// include/um_util.php, row 98
$_m["Object"]
    = "Objekt";

// include/um_util.php, row 158, 179
$_m["ADMINISTRATOR"]
    = "";

// include/um_util.php, row 177
$_m["AUTHOR"]
    = "";

// include/um_util.php, row 178
$_m["EDITOR"]
    = "";

// include/um_util.php, row 374
$_m["Can't change user"]
    = "Nelze zm�nit data u�ivatele";

// include/tabledit_util.php, row 72, 453
$_m["Insert was successfull."]
    = "P�id�n� prob�ho �sp�n�.";

// include/tabledit_util.php, row 80, 107, 364
$_m["Update was successfull."]
    = "�prava prob�hla �sp�n�.";

// include/tabledit_util.php, row 122, 128
$_m["Delete was successfull."]
    = "Smaz�n� prob�hlo �sp�n�.";

// include/tabledit_util.php, row 537
$_m["Value of %1 should be between %2 and %3."]
    = "Hondota %1 mus� b�t mezi %2 a %3.";

// include/tabledit_util.php, row 576
$_m["Table do not have set primary key on single column. You can specify primary key by primary => array (field1, field2, ...) parameter for tableedit"]
    = "";

// include/tabledit_util.php, row 677
$_m["Wrong value: a number between %1 and %2 is expected."]
    = "�patn� hodnota: o�ek�v� se ��slo mezi %1 a %2.";

// include/tabledit_util.php, row 687
$_m["Are you sure you want to permanently DELETE this record?"]
    = "Chcete opravdu trvale SMAZAT tento z�znam?";

// include/constedit_util.php, row 117, 122
$_m["Select"]
    = "Zvolit";

// include/constedit_util.php, row 602
$_m["No group id specified"]
    = "Nebylo zad�no ID skupiny hodnot";

// include/itemview.php, row 332
$_m["No comment was selected"]
    = "Nebyl vybr�n ��dn� p��sp�vek";

// include/validate.php, row 89
$_m["Wrong characters - you should use a-z, A-Z, 0-9 . _ and - characters"]
    = "";

// include/validate.php, row 116
$_m["Bad validator type: %1"]
    = "";

// include/validate.php, row 187
$_m["No integer value"]
    = "";

// include/validate.php, row 191, 228
$_m["Out of range - too big"]
    = "";

// include/validate.php, row 194, 231
$_m["Out of range - too small"]
    = "";

// include/validate.php, row 224
$_m["No float value"]
    = "";

// include/validate.php, row 257
$_m["Wrong value"]
    = "";

// include/validate.php, row 308, 330
$_m["Too short"]
    = "";

// include/validate.php, row 311, 333
$_m["Too long"]
    = "";

// include/validate.php, row 313
$_m["Wrong characters - you should use a-z, A-Z and 0-9 characters"]
    = "";

// include/validate.php, row 363
$_m["Wrong parameter field_id for unique check"]
    = "";

// include/validate.php, row 369
$_m["Username is not unique"]
    = "";

// include/validate.php, row 382
$_m["Not unique - value already used"]
    = "";

// include/validate.php, row 450
$_m["it must be filled"]
    = "mus� b�t vypln�no";

// include/validate.php, row 540
$_m["This field is required."]
    = "Pol��ko je povinn�.";

// include/validate.php, row 541
$_m["This field is required (marked by *)."]
    = "Pol��ko je povinn� (ozna�eno *).";

// include/validate.php, row 550
$_m["Not a valid integer number."]
    = "Nen� platn� cel� ��slo.";

// include/validate.php, row 554
$_m["Not a valid file name."]
    = "Nen� platn� n�zev souboru.";

// include/validate.php, row 559
$_m["Not a valid email address."]
    = "Nen� platn� email.";

// include/validate.php, row 563
$_m["The two password copies differ."]
    = "Kopie hesla se li��.";

// include/view.class.php, row 164
$_m["Jump to view:"]
    = "Zobraz pohled:";

// include/searchlib.php, row 41
$_m["LLIKE"]
    = "";

// include/searchlib.php, row 42
$_m["XLIKE"]
    = "";

// include/searchlib.php, row 43
$_m["BETWEEN"]
    = "";

// include/searchlib.php, row 45, 92, 95, 98, 101
$_m["is set"]
    = "vypln�no";

// include/searchlib.php, row 46
$_m["=="]
    = "";

// include/searchlib.php, row 47
$_m["="]
    = "";

// include/searchlib.php, row 48
$_m["<"]
    = "";

// include/searchlib.php, row 49
$_m[">"]
    = "";

// include/searchlib.php, row 50, 51, 52, 53
$_m["<>"]
    = "";

// include/searchlib.php, row 54
$_m["d:<"]
    = "";

// include/searchlib.php, row 55
$_m["d:>"]
    = "";

// include/searchlib.php, row 56
$_m["d:<="]
    = "";

// include/searchlib.php, row 57
$_m["d:>="]
    = "";

// include/searchlib.php, row 58
$_m["d:="]
    = "";

// include/searchlib.php, row 59
$_m["d:!="]
    = "";

// include/searchlib.php, row 60
$_m["d:<>"]
    = "";

// include/searchlib.php, row 61
$_m["e:<"]
    = "";

// include/searchlib.php, row 62
$_m["e:>"]
    = "";

// include/searchlib.php, row 63
$_m["e:<="]
    = "";

// include/searchlib.php, row 64
$_m["e:>="]
    = "";

// include/searchlib.php, row 65
$_m["e:="]
    = "";

// include/searchlib.php, row 66
$_m["e:!="]
    = "";

// include/searchlib.php, row 67
$_m["e:<>"]
    = "";

// include/searchlib.php, row 68
$_m["m:<"]
    = "";

// include/searchlib.php, row 69
$_m["m:>"]
    = "";

// include/searchlib.php, row 70
$_m["m:<="]
    = "";

// include/searchlib.php, row 71
$_m["m:>="]
    = "";

// include/searchlib.php, row 72
$_m["m:="]
    = "";

// include/searchlib.php, row 73
$_m["m:!="]
    = "";

// include/searchlib.php, row 74
$_m["m:<>"]
    = "";

// include/searchlib.php, row 75
$_m["-:<"]
    = "";

// include/searchlib.php, row 76
$_m["-:>"]
    = "";

// include/searchlib.php, row 77
$_m["-:<="]
    = "";

// include/searchlib.php, row 78
$_m["-:>="]
    = "";

// include/searchlib.php, row 79
$_m["-:="]
    = "";

// include/searchlib.php, row 80
$_m["-:!="]
    = "";

// include/searchlib.php, row 81
$_m["-:<>"]
    = "";

// include/searchlib.php, row 125
$_m["Operator"]
    = "";

// include/searchlib.php, row 617
$_m["Conditions"]
    = "";

// include/searchlib.php, row 619
$_m["Sort"]
    = "";

// include/imagefunc.php, row 57
$_m["Cannot copy %1 to %2"]
    = "Nelze zkop�rovat %1 do %2";

// include/imagefunc.php, row 161
$_m["ResampleImage unable to %1"]
    = "Nelze zm�nit velikost obr�zku %1";

// include/imagefunc.php, row 180
$_m["Type not supported for resize"]
    = "Typ nen� podporov�n pro zm�nu velikosti";

// include/init_page.php, row 173
$_m["You do not have permission to edit items in the slice"]
    = "Nem�te pr�vo upravovat zpr�vy v tomto web�ku";

// include/init_page.php, row 198
$_m["No slice found for you"]
    = "Nebyl nalezen ��dn� web�k, ke kter�mu m�te p��stup";

// include/tv_misc.php, row 66, 67
$_m["Wizard Welcomes"]
    = "Uv�t�n� z Pr�vodce";

// include/tv_misc.php, row 73
$_m["mail body"]
    = "t�lo zpr�vy";

// include/tv_misc.php, row 75
$_m["From: mail header"]
    = "From: hlavi�ka mailu";

// include/tv_misc.php, row 99, 100
$_m["Wizard Templates"]
    = "�ablony Pr�vodce";

// include/tv_misc.php, row 125
$_m["For help see FAQ: "]
    = "N�pov�du najdete ve FAQu: ";

// include/tv_misc.php, row 152
$_m["COUNT_HIT events will be used for counting item hits. After a while it will be automaticaly deleted."]
    = "";

// include/tv_misc.php, row 160, 161
$_m["Log view"]
    = "";

// include/tv_misc.php, row 183
$_m["See searchlog=1 parameter for slice.php3 in FAQ: "]
    = "";

// include/tv_misc.php, row 191, 192
$_m["SearchLog view"]
    = "";

// include/tv_misc.php, row 196
$_m["items found"]
    = "";

// include/tv_misc.php, row 197
$_m["search time"]
    = "";

// include/tv_misc.php, row 198
$_m["addition"]
    = "";

// include/tv_misc.php, row 219, 220
$_m["Configure Fields"]
    = "";

// include/tableviews.php, row 59, 60
$_m["Alerts Admin"]
    = "Nastaven� Zas�l�n�";

// include/tableviews.php, row 72
$_m["confirm mail"]
    = "";

// include/tableviews.php, row 73, 80
$_m["number of days, 0 = off"]
    = "";

// include/tableviews.php, row 79
$_m["delete not confirmed"]
    = "";

// include/tableviews.php, row 86
$_m["last confirm mail"]
    = "";

// include/tableviews.php, row 93
$_m["last delete not confirmed"]
    = "";

// include/tableviews.php, row 109
$_m["This table sets handling of not confirmed users. It's accessible only\n"
."            to superadmins.\n"
."            You can delete not confirmed users after a number of days and / or send them an email\n"
."            demanding them to do confirmation\n"
."            after a smaller number of days. To switch either of the actions off,\n"
."            set number of days to 0. The two last fields are for your information only.<br>\n"
."            <br>\n"
."            To run the script, you must have cron set up with a row running\n"
."            misc/alerts/admin_mails.php3.<br>\n"
."            For more information, see <a href='http://apc-aa.sourceforge.net/faq/#1389'>the FAQ</a>."]
    = "";

// include/tableviews.php, row 125, 126
$_m["Polls Design"]
    = "Design Ankety";

// include/tableviews.php, row 132, 155
$_m["Comment"]
    = "Koment��";

// include/tableviews.php, row 148
$_m["Module Id"]
    = "";

// include/tableviews.php, row 157
$_m["design description (for administrators only)"]
    = "popis designu (pouze pro administr�tory)";

// include/tableviews.php, row 160
$_m["Answer HTML"]
    = "HTML odpov�di";

// AA\Optimize\...
$_m["Convert slice.category_sort to slice.group_by"]
    = "";

// AA\Optimize\... #xx81
$_m["In older version of AA we used just category fields for grouping items. Now it is universal, so boolean category_sort is not enough. We use newer group_by field for quite long time s most probably all your slices are already conevrted."]
    = "";

// AA\Optimize\... #xx91
$_m["%1 slices are not converted"]
    = "";

// AA\Optimize\... #xx94
$_m["All slices are already converted"]
    = "";

// AA\Optimize\... #xx137
$_m["Relation table duplicate records"]
    = "";

// AA\Optimize\... #xx144
$_m["Testing if relation table contain records, where values in both columns are identical (which was bug fixed in Jan 2006)"]
    = "";

// AA\Optimize\... #xx155
$_m["%1 duplicates found"]
    = "";

// AA\Optimize\... #xx158, 791, 806
$_m["No duplicates found"]
    = "";

// AA\Optimize\... #xx183
$_m["Feeds table inconsistent records"]
    = "";

// AA\Optimize\... #xx190
$_m["Testing if feeds table do not contain relations to non existant slices (after slice deletion)"]
    = "";

// AA\Optimize\... #xx206
$_m["Wrong destination slice id: %1 -> %2"]
    = "";

// AA\Optimize\... #xx217
$_m["Wrong source slice id: %1 -> %2"]
    = "";

// AA\Optimize\... #xx222
$_m["No wrong references found, hurray!"]
    = "";

// AA\Optimize\... #xx272
$_m["Fix user login problem, constants editiong problem, ..."]
    = "";

// AA\Optimize\... #xx279
$_m["Replaces binary fields by varbinary and removes trailing zeros. Needed for MySQL > 5.0.17"]
    = "";

// AA\Optimize\... #xx454
$_m["Convert Readers login to reader id"]
    = "";

// AA\Optimize\... #xx461
$_m["There was change in Reader management functionality in AA v2.8.1, so readers are not internally identified by its login, but by reader ID (item ID of reader in Reader slice). This is much more powerful - you can create relations just as in normal slice. It works well without any change. The only problem is, if you set any slice to be editable by users from Reader slice. In that case the fields edited_by........ and posted_by........ are filled by readers login instead of reader id. You can fix it by \"Repair\"."]
    = "";

// AA\Optimize\... #xx475
$_m["%1 login names from reader slice found as records in item.posted_by which is wrong (There should be reader ID from AA v2.8.1). \"Repair\" will correct it."]
    = "";

// AA\Optimize\... #xx480
$_m["%1 login names from reader slice found as records in item.edited_by which is wrong (There should be reader ID from AA v2.8.1). \"Repair\" will correct it."]
    = "";

// AA\Optimize\... #xx518, 585
$_m["Column item.posted_by updated for %1 (id: %2)."]
    = "";

// AA\Optimize\... #xx525, 592
$_m["Column item.edited_by updated for %1 (id: %2)."]
    = "";

// AA\Optimize\... #xx539
$_m["Checks if all tables have right columns and indexes"]
    = "";

// AA\Optimize\... #xx546
$_m["We are time to time add new table or collumn to existing table in order we can support new features. This option will update the datastructure to the last one. No data will be lost."]
    = "";

// AA\Optimize\... #xx607
$_m["Clear Pagecache"]
    = "";

// AA\Optimize\... #xx614
$_m["Whole pagecache will be invalidated and deleted"]
    = "";

// AA\Optimize\... #xx624, 723, 871, 1042, 1097
$_m["There is nothing to test."]
    = "";

// AA\Optimize\... #xx635
$_m["Table pagecache_new created"]
    = "";

// AA\Optimize\... #xx637
$_m["Table pagecache_str2find_new created"]
    = "";

// AA\Optimize\... #xx639
$_m["Renamed tables pagecache_* to pagecache_*_bak"]
    = "";

// AA\Optimize\... #xx641
$_m["Renamed tables pagecache_*_new to pagecache_*"]
    = "";

// AA\Optimize\... #xx643
$_m["Old pagecache_*_bak tables dropped"]
    = "";

// AA\Optimize\... #xx658
$_m["Fix inconcistency in pagecache"]
    = "";

// AA\Optimize\... #xx665
$_m["Delete not existant keys in pagecache_str2find table"]
    = "";

// AA\Optimize\... #xx679
$_m["id: %1, pagecache_id: %2, str2find: %3"]
    = "";

// AA\Optimize\... #xx681
$_m["We found %1 inconsistent rows from %2 in pagecache_str2find"]
    = "";

// AA\Optimize\... #xx693
$_m["Inconsistent rows in pagecache_str2find removed"]
    = "";

// AA\Optimize\... #xx706
$_m["Copy Content Table"]
    = "";

// AA\Optimize\... #xx713
$_m["Copy data for all items newer than short_id=1941629 from content table to content2 table. Used for recovery content table on Ecn server. Not usefull for any other users, I think."]
    = "";

// AA\Optimize\... #xx757
$_m["Coppied"]
    = "";

// AA\Optimize\... #xx771
$_m["Fix field definitions duplicates"]
    = "";

// AA\Optimize\... #xx778
$_m["There should be only one slice_id - field_id pair in all slices, but sometimes there are more than one (mainly because of error in former sql_update.php script, where more than one display_count... fields were added)."]
    = "";

// AA\Optimize\... #xx795
$_m["Duplicate in slice - field: %1 - %2"]
    = "";

// AA\Optimize\... #xx820
$_m["Field %2 in slice %1 fixed"]
    = "";

// AA\Optimize\... #xx854
$_m["Add Polls tables"]
    = "";

// AA\Optimize\... #xx861
$_m["Create tables for new Polls module and adds first - template polls. It removes all current polls!"]
    = "";

// AA\Optimize\... #xx905
$_m["Polls module created"]
    = "";

// AA\Optimize\... #xx925
$_m["Update database structure"]
    = "";

// AA\Optimize\... #xx932, 1029
$_m["[experimental] "]
    = "";

// AA\Optimize\... #xx932
$_m["Updates the database structure for AA. It checks all the tables in current system and compare it with the newest database structure. The new table is created as tmp_*, then the content from old table is copied and if everything is OK, then the old table is renamed to bck_* and tmp_* is renamed to new table. (new version based on the metabase structure)"]
    = "";

// AA\Optimize\... #xx950
$_m["Tables %1 are identical."]
    = "";

// AA\Optimize\... #xx952
$_m["Tables %1 are different: <br>Template:<br>%2<br>Current:<br>%3"]
    = "";

// AA\Optimize\... #xx971
$_m["Tables %1 are identical. Skipping."]
    = "";

// AA\Optimize\... #xx975
$_m["Deleting temporary table tmp_%1, if exist."]
    = "";

// AA\Optimize\... #xx977
$_m["Creating temporary table tmp_%1."]
    = "";

// AA\Optimize\... #xx982
$_m["Creating \"old\" data table %1 if not exists."]
    = "";

// AA\Optimize\... #xx987
$_m["copying old values to new table %1 -> tmp_%1"]
    = "";

// AA\Optimize\... #xx1001
$_m["backup old table %1 -> bck_%1 and use new tables instead tmp_%1 -> %1"]
    = "";

// AA\Optimize\... #xx1006
$_m["%1 done."]
    = "";

// AA\Optimize\... #xx1022
$_m["Restore data from backup tables"]
    = "";

// AA\Optimize\... #xx1029
$_m["Deletes all the current tables (slice, item, ...) where we have bck_table and renames all backup tables (bck_slice, bck_item, ...) to right names (slice, item, ...)."]
    = "";

// AA\Optimize\... #xx1061
$_m["There is no bck_%1 table - %1 not restored."]
    = "";

// AA\Optimize\... #xx1064
$_m["Replace table bck_%1 -> %1"]
    = "";

// AA\Optimize\... #xx1080
$_m["Create upload directory for current slice"]
    = "";

// AA\Optimize\... #xx1087
$_m["see IMG_UPLOAD_PATH parameter in config.php file"]
    = "";

// AA\Optimize\... #xx1106
$_m["OK, %1 created"]
    = "";

// include/widget.class.php, row 284
$_m["SAVE CHANGE"]
    = "";

// include/widget.class.php, row 349
$_m["Text Area"]
    = "";

// include/widget.class.php, row 363, 421, 456, 559, 935, 975
$_m["Row count"]
    = "";

// include/widget.class.php, row 409
$_m["Textarea with Presets"]
    = "";

// include/widget.class.php, row 420, 594, 639, 679, 858, 934, 974, 1092, 1167
$_m["Constants or slice"]
    = "";

// include/widget.class.php, row 420, 594, 639, 679, 858, 934, 974, 1092, 1167
$_m["Constants (or slice) which is used for value selection"]
    = "";

// include/widget.class.php, row 422, 457
$_m["Column count"]
    = "";

// include/widget.class.php, row 445
$_m["Rich Edit Text Area"]
    = "";

// include/widget.class.php, row 458
$_m["type: class (default) / iframe"]
    = "";

// include/widget.class.php, row 508
$_m["Text Field"]
    = "";

// include/widget.class.php, row 522
$_m["Max characters"]
    = "";

// include/widget.class.php, row 522, 595
$_m["max count of characters entered (maxlength parameter)"]
    = "";

// include/widget.class.php, row 523, 1208
$_m["Width"]
    = "";

// include/widget.class.php, row 523, 596
$_m["width of the field in characters (size parameter)"]
    = "";

// include/widget.class.php, row 544
$_m["Multiple Text Field"]
    = "";

// include/widget.class.php, row 558, 1097
$_m["Buttons to show"]
    = "";

// include/widget.class.php, row 558
$_m["Which action buttons to show:<br>M - Move (up and down)<br>D - Delete value,<br>A - Add new value<br>C - Change the value<br>Use 'MDAC' (default), 'DAC', just 'M' or any other combination. The order of letters M,D,A,C is not important."]
    = "";

// include/widget.class.php, row 580
$_m["Text Field with Presets"]
    = "";

// include/widget.class.php, row 595
$_m["max characters"]
    = "";

// include/widget.class.php, row 596
$_m["width"]
    = "";

// include/widget.class.php, row 597, 640, 682, 861, 936, 978, 1102
$_m["slice field"]
    = "";

// include/widget.class.php, row 597, 640, 682, 861, 936, 978, 1102
$_m["field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE, _#PUB_DATE (for slices) or _#NAME###_, _#TR_NAME_, _#VALUE##_, _#PRIORITY, _#GROUP##_, _#CLASS##_, _#COUNTER_, _#CONST_ID, _#SHORT_ID (for constants))."]
    = "";

// include/widget.class.php, row 598, 641
$_m["Use name"]
    = "";

// include/widget.class.php, row 598, 641
$_m["if set (=1), then the name of selected constant is used, insted of the value. Default is 0"]
    = "";

// include/widget.class.php, row 599
$_m["Adding"]
    = "";

// include/widget.class.php, row 599
$_m["adding the selected items to input field comma separated"]
    = "";

// include/widget.class.php, row 600
$_m["Second Field"]
    = "";

// include/widget.class.php, row 600
$_m["field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)"]
    = "";

// include/widget.class.php, row 601
$_m["Add to Constant"]
    = "";

// include/widget.class.php, row 601
$_m["if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)"]
    = "";

// include/widget.class.php, row 602, 642, 683, 862, 937, 979, 1098
$_m["Show items from bins"]
    = "";

// include/widget.class.php, row 602, 642, 683, 862, 937, 979, 1098
$_m["(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"]
    = "";

// include/widget.class.php, row 603, 643, 684, 863, 938, 980, 1099
$_m["Filtering conditions"]
    = "";

// include/widget.class.php, row 603, 643, 684, 863, 938, 980, 1099
$_m["(for slices only) Conditions for filtering items in selection. Use conds[] array."]
    = "";

// include/widget.class.php, row 604, 644, 685, 864, 939, 981, 1100
$_m["Sort by"]
    = "";

// include/widget.class.php, row 604, 644, 685, 864, 939, 981, 1100
$_m["(for slices only) Sort the items in specified order. Use sort[] array"]
    = "";

// include/widget.class.php, row 625
$_m["Select Box"]
    = "";

// include/widget.class.php, row 665
$_m["Radio Button"]
    = "";

// include/widget.class.php, row 680, 859
$_m["Columns"]
    = "";

// include/widget.class.php, row 680, 859
$_m["Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."]
    = "";

// include/widget.class.php, row 681, 860
$_m["Move right"]
    = "";

// include/widget.class.php, row 681, 860
$_m["Should the function move right or down to the next value?"]
    = "";

// include/widget.class.php, row 720
$_m["Starting Year"]
    = "";

// include/widget.class.php, row 720
$_m["The (relative) start of the year interval"]
    = "";

// include/widget.class.php, row 721
$_m["Ending Year"]
    = "";

// include/widget.class.php, row 721
$_m["The (relative) end of the year interval"]
    = "";

// include/widget.class.php, row 722
$_m["Relative"]
    = "";

// include/widget.class.php, row 722
$_m["If this is 1, the starting and ending year will be taken as relative - the interval will start at (this year - starting year) and end at (this year + ending year). If this is 0, the starting and ending years will be taken as absolute."]
    = "";

// include/widget.class.php, row 723
$_m["Show time"]
    = "";

// include/widget.class.php, row 723
$_m["show the time box? (1 means Yes, undefined means No)"]
    = "";

// include/widget.class.php, row 811
$_m["Check Box"]
    = "";

// include/widget.class.php, row 844
$_m["Multiple Checkboxes"]
    = "";

// include/widget.class.php, row 920
$_m["Multiple Selectbox"]
    = "";

// include/widget.class.php, row 960
$_m["Two Boxes"]
    = "";

// include/widget.class.php, row 976
$_m["Title of \"Offer\" selectbox"]
    = "";

// include/widget.class.php, row 976
$_m["Our offer"]
    = "";

// include/widget.class.php, row 977
$_m["Title of \"Selected\" selectbox"]
    = "";

// include/widget.class.php, row 1002
$_m["File Upload"]
    = "";

// include/widget.class.php, row 1016
$_m["Allowed file types"]
    = "";

// include/widget.class.php, row 1017
$_m["Label"]
    = "";

// include/widget.class.php, row 1017
$_m["To be printed before the file upload field"]
    = "";

// include/widget.class.php, row 1017
$_m["File: "]
    = "";

// include/widget.class.php, row 1018
$_m["Hint"]
    = "";

// include/widget.class.php, row 1018
$_m["appears beneath the file upload field"]
    = "";

// include/widget.class.php, row 1018
$_m["You can select a file ..."]
    = "";

// include/widget.class.php, row 1081
$_m["Related Item Window"]
    = "";

// include/widget.class.php, row 1093
$_m["Row count in the list"]
    = "";

// include/widget.class.php, row 1094
$_m["Actions to show"]
    = "";

// include/widget.class.php, row 1094
$_m["Defines, which buttons to show in item selection:<br>A - Add<br>M - add Mutual<br>B - Backward<br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."]
    = "";

// include/widget.class.php, row 1095
$_m["Admin design"]
    = "";

// include/widget.class.php, row 1095
$_m["If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default)."]
    = "";

// include/widget.class.php, row 1096
$_m["Tag Prefix"]
    = "";

// include/widget.class.php, row 1096
$_m["Deprecated: selects tag set ('AMB' / 'GYR'). Ask Mitra for more details."]
    = "";

// include/widget.class.php, row 1097
$_m["Which action buttons to show:<br>M - Move (up and down)<br>D - Delete relation,<br>R - add Relation to existing item<br>N - insert new item in related slice and make it related<br>E - Edit related item<br>Use 'DR' (default), 'MDRNE', just 'N' or any other combination. The order of letters M,D,R,N,E is not important."]
    = "";

// include/widget.class.php, row 1101
$_m["Filtering conditions - changeable"]
    = "";

// include/widget.class.php, row 1101
$_m["Conditions for filtering items in related items window. This conds user can change."]
    = "";

// include/widget.class.php, row 1123
$_m["Do not show"]
    = "Nezobrazuj";

// include/widget.class.php, row 1153
$_m["hierarchical constants"]
    = "Hierachie konstant";

// include/widget.class.php, row 1168
$_m["Count of level boxes"]
    = "";

// include/widget.class.php, row 1169
$_m["Box width"]
    = "";

// include/widget.class.php, row 1169
$_m["Width in characters"]
    = "";

// include/widget.class.php, row 1170
$_m["Size of target"]
    = "";

// include/widget.class.php, row 1170
$_m["Lines in the target select box"]
    = "";

// include/widget.class.php, row 1171
$_m["Horizontal"]
    = "";

// include/widget.class.php, row 1171
$_m["Show levels horizontally"]
    = "";

// include/widget.class.php, row 1172
$_m["First selectable"]
    = "";

// include/widget.class.php, row 1172
$_m["First level which will have a Select button"]
    = "";

// include/widget.class.php, row 1173
$_m["Level names"]
    = "";

// include/widget.class.php, row 1173
$_m["Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ..."]
    = "";

// include/widget.class.php, row 1173
$_m["Top level~Second level~Keyword"]
    = "";

// include/widget.class.php, row 1194
$_m["Password and Change password"]
    = "Heslo a Zm�nit heslo";

// include/widget.class.php, row 1208
$_m["width of the three fields in characters (size parameter)"]
    = "";

// include/widget.class.php, row 1209
$_m["Label for Change Password"]
    = "";

// include/widget.class.php, row 1209
$_m["Replaces the default 'Change Password'"]
    = "";

// include/widget.class.php, row 1209
$_m["Change your password"]
    = "";

// include/widget.class.php, row 1210
$_m["Label for Retype New Password"]
    = "";

// include/widget.class.php, row 1210
$_m["Replaces the default \"Retype New Password\""]
    = "";

// include/widget.class.php, row 1210
$_m["Retype the new password"]
    = "";

// include/widget.class.php, row 1211
$_m["Label for Delete Password"]
    = "";

// include/widget.class.php, row 1211
$_m["Replaces the default \"Delete Password\""]
    = "";

// include/widget.class.php, row 1211
$_m["Delete password (set to empty)"]
    = "";

// include/widget.class.php, row 1212
$_m["Help for Change Password"]
    = "";

// include/widget.class.php, row 1212
$_m["Help text under the Change Password box (default: no text)"]
    = "";

// include/widget.class.php, row 1212
$_m["To change password, enter the new password here and below"]
    = "";

// include/widget.class.php, row 1213
$_m["Help for Retype New Password"]
    = "";

// include/widget.class.php, row 1213
$_m["Help text under the Retype New Password box (default: no text)"]
    = "";

// include/widget.class.php, row 1213
$_m["Retype the new password exactly the same as you entered into \"Change Password\"."]
    = "";

// include/widget.class.php, row 1234
$_m["Hidden field"]
    = "Skryt� pole";

// include/widget.class.php, row 1267
$_m["Local URL Picker"]
    = "";

// include/widget.class.php, row 1281
$_m["The URL of your local web server from where you want to start browsing for a particular URL."]
    = "";

// include/widget.class.php, row 1281
$_m["http#://www.ecn.cz/articles/solar.shtml"]
    = "";

// include/slice.class.php, row 61
$_m["WARNING: slice: %s doesn't look like an unpacked id"]
    = "POZOR: web�k: %s nevypad� jako rozbalen� id";


