<?php
// $Id$
// Language: CZ-UTF8
// This file was created automatically by the Mini GetText environment
// on 5.9.2008 16:31

// Do not change this file otherwise than by typing translations on the right of =

// Before each message there are links to program code where it was used.

$mgettext_lang = "cz-utf8";
define("DB_COLLATION", "utf8mb4_czech_ci");
setlocale(LC_ALL, 'cs_CZ.utf8', 'cs_CZ');  // sort, date, uppercase, ..
setlocale(LC_NUMERIC, 'en_US');            // use numeric with dot - there is problem, when
                                           // used Czech numeric comma for example in AA_Stringexpand_If:
                                           //   $cmp  = create_function('$b', "return ($etalon $operator". ' $b);');
                                           // float!! value $etalon is then with comma which leads to syntax error

# Unused messages
$_m["ActionApps - Link Edit"]
 = "Editace odkazu";

$_m["Changed"]
 = "Změněno";

$_m["Bookmark"]
 = "Přidej k oblíbeným";

$_m["Type"]
 = "Obecná kategorie";

$_m["Trash bin"]
 = "Koš";

$_m["Misc"]
 = "Různé";

$_m["Up"]
 = "Posun vybrané kategorie v seznamu o jeden stupeň výše";

$_m["Down"]
 = "Posun vybrané kategorie v seznamu o jeden stupeň níže";

$_m["Rating"]
 = "Výchozí hodnocení odkazu";

$_m["Polls"]
 = "Ankety";

$_m["Alerts"]
 = "Zasílání e-mailů";

$_m["Reader Management Slice"]
 = "Správa čtenářů";

$_m["HTML Template"]
 = "HTML šablona";

$_m["Cetegory editor's note"]
 = "Poznámka editora kategorie";

$_m["Page is already in database"]
 = "Odkaz je v katalogu již zařazen.";

$_m["Page is already in database, but it is not displayed in any category, now"]
 = "Odkaz je v katalogu již zařazen, nemá však přiřazenu žádnou kategorii (nachází se ve schránce <em>Nezařazené odkazy</em>).";

$_m["Submit"]
 = "Poslat";

$_m["Cancel"]
 = "Storno";

$_m["The same url as "]
 = "Přidaný odkaz má stejné URL jako";

$_m["Link changed"]
 = "Odkaz byl změněn";

$_m["Link assigned to category"]
 = "Odkaz byl zařazen do kategorie";

$_m["select ..."]
 = "vyber ...";

# End of unused messages
// modules/links/catselect.php, row 25
$_m["ActionApps - Select Category"]
 = "Výběr kategorie";

// modules/links/catselect.php, row 44
$_m["Select Category"]
 = "Vyber kategorii";

// modules/links/catselect.php, row 56
// modules/links/catedit.php, row 130
$_m["OK"]
 = "";

// modules/links/index.php3, row 45
$_m["No permissions to edit links"]
 = "Nemáte právo editovat tento odkaz";

// modules/links/index.php3, row 238
$_m["HINT: \"social ecology\" AND environment"]
 = "TIP: (právo AND informace) OR \"občanská práva\"";

// modules/links/index.php3, row 265
$_m["Perform action on selected items"]
 = "Vyber akci, která se provede s vybranými odkazy";

// modules/links/index.php3, row 269
$_m["Check Link"]
 = "Kontrola odkazu provedena";

// modules/links/index.php3, row 272
$_m["Highlight Link"]
 = "Doporučit odkaz";

// modules/links/index.php3, row 275
$_m["Dehighlight Link"]
 = "Zrušit doporučení odkazu";

// modules/links/index.php3, row 278
$_m["Remove from category"]
 = "Vyřadit z této kategorie";

// modules/links/index.php3, row 281
$_m["Move to Active"]
 = "Přesunout mezi Aktivní";

// modules/links/index.php3, row 285
$_m["Move to Holding bin"]
 = "Přesunout do Zásobníku";

// modules/links/index.php3, row 289
$_m["Move to Trash"]
 = "Přesunout do Koše";

// modules/links/index.php3, row 292
$_m["Add to category"]
 = "Přidat do kategorie";

// modules/links/index.php3, row 296
$_m["Move to category"]
 = "Přesunout do kategorie";

// modules/links/index.php3, row 325
$_m["ActionApps - Links Manager"]
 = "Správa odkazů";

// modules/links/index.php3, row 382
$_m["All my links"]
 = "Všechny mé odkazy";

// modules/links/index.php3, row 383
$_m["Links to check"]
 = "Ke kontrole";

// modules/links/index.php3, row 384
$_m["Last edited"]
 = "Naposledy editované";

// modules/links/index.php3, row 412
$_m["Show Links"]
 = "Zobraz odkazy";

// modules/links/index.php3, row 416
$_m["Edit Category"]
 = "Edituj kategorii";

// modules/links/index.php3, row 425
$_m["Show subtree links"]
 = "Zobrazuj odkazy i ze všech podkategorií";

// modules/links/getcat.php, row 45
$_m["Category Tree"]
 = "Strom kategorií";

// modules/links/getcat.php, row 50
$_m["Select category"]
 = "Vyber kategorii";

// modules/links/constants.php, row 53, 156
// modules/links/catedit.php, row 92
// modules/links/linkedit.php, row 409
$_m["Id"]
 = "ID";

// modules/links/constants.php, row 54, 157
$_m["Name"]
 = "Název odkazu";

// modules/links/constants.php, row 55
$_m["Original name"]
 = "Překlad názevu odkazu";

// modules/links/constants.php, row 56, 160
// modules/links/link.class.php, row 641
// modules/links/linkedit.php, row 435
$_m["Description"]
 = "Popis odkazu";

// modules/links/constants.php, row 57, 110
// modules/links/link.class.php, row 645
// modules/links/linkedit.php, row 442
$_m["Link type"]
 = "Obecná kategorie";

// modules/links/constants.php, row 58
$_m["Rate"]
 = "Hodnocení";

// modules/links/constants.php, row 59
$_m["Votes"]
 = "Počet hlasů";

// modules/links/constants.php, row 60
// modules/links/link.class.php, row 642
$_m["Author"]
 = "Vkladatel";

// modules/links/constants.php, row 61
$_m["Insert date"]
 = "Datum vložení";

// modules/links/constants.php, row 62
$_m["Editor"]
 = "Naposledy upravoval";

// modules/links/constants.php, row 63
$_m["Last edit date"]
 = "Datum poslední úpravy";

// modules/links/constants.php, row 64
$_m["Revised by"]
 = "Kontrolu provedl";

// modules/links/constants.php, row 65
$_m["Revision date"]
 = "Datum poslední kontroly";

// modules/links/constants.php, row 66
// modules/links/link.class.php, row 651
// modules/links/linkedit.php, row 528
$_m["E-mail"]
 = "";

// modules/links/constants.php, row 67
// modules/links/link.class.php, row 643
// modules/links/linkedit.php, row 414
$_m["Url"]
 = "URL";

// modules/links/constants.php, row 68
$_m["Last vote time"]
 = "Poslední hlasování";

// modules/links/constants.php, row 69
$_m["Flag"]
 = "";

// modules/links/constants.php, row 70
// modules/links/catedit.php, row 96
// modules/links/catedit2.php, row 210
// modules/links/link.class.php, row 652
// modules/links/linkedit.php, row 455
$_m["Editor's note"]
 = "Poznámka";

// modules/links/constants.php, row 71
$_m["Organization city"]
 = "Organizace - Město ";

// modules/links/constants.php, row 72
$_m["Organization street"]
 = "Organizace - ulice";

// modules/links/constants.php, row 73
$_m["Organization post code"]
 = "Organizace - PSČ";

// modules/links/constants.php, row 74
$_m["Organization phone"]
 = "Organizace - telefon";

// modules/links/constants.php, row 75
$_m["Organization fax"]
 = "Organizace - fax";

// modules/links/constants.php, row 76
$_m["Organization e-mail"]
 = "Organizace - e-mail";

// modules/links/constants.php, row 77
// modules/links/linkedit.php, row 466
$_m["Folder"]
 = "Schránka";

// modules/links/constants.php, row 78
$_m["Last validation date"]
 = "Datum kontroly dostupnosti";

// modules/links/constants.php, row 79
$_m["Validation codes"]
 = "";

// modules/links/constants.php, row 80
$_m["Validity Rank"]
 = "Dostupnost stránky";

// modules/links/constants.php, row 81
$_m["Region id"]
 = "";

// modules/links/constants.php, row 82
$_m["Region name"]
 = "Region";

// modules/links/constants.php, row 83
$_m["Region level"]
 = "";

// modules/links/constants.php, row 84
$_m["Language id"]
 = "";

// modules/links/constants.php, row 85
// modules/links/linkedit.php, row 537
$_m["Language"]
 = "Jazyk";

// modules/links/constants.php, row 86
$_m["Language short name"]
 = "Zkratka jazyka";

// modules/links/constants.php, row 87, 170
$_m["Category id"]
 = "";

// modules/links/constants.php, row 88, 171
// modules/links/catedit.php, row 93
// modules/links/catedit2.php, row 205
$_m["Category name"]
 = "Název kategorie";

// modules/links/constants.php, row 89
$_m["Category deleted"]
 = "";

// modules/links/constants.php, row 90
$_m["Category path"]
 = "";

// modules/links/constants.php, row 91
$_m["Category link count"]
 = "Počet odkazů v kategorii";

// modules/links/constants.php, row 92, 173
// modules/links/catedit.php, row 94
// modules/links/catedit2.php, row 206
$_m["Category description"]
 = "Popis kategorie";

// modules/links/constants.php, row 93, 174
$_m["Category editor's note"]
 = "";

// modules/links/constants.php, row 94
$_m["Base"]
 = "Primární";

// modules/links/constants.php, row 95, 163
$_m["State"]
 = "";

// modules/links/constants.php, row 96
$_m["Change proposal"]
 = "Návrh na změnu";

// modules/links/constants.php, row 97
$_m["To be deleted"]
 = "";

// modules/links/constants.php, row 98, 162
$_m["Priority"]
 = "";

// modules/links/constants.php, row 99
// modules/links/linkedit.php, row 39
$_m["Change"]
 = "Změna";

// modules/links/constants.php, row 106
$_m["Link id"]
 = "ID odkazu";

// modules/links/constants.php, row 107
$_m["Link name"]
 = "Název odkazu";

// modules/links/constants.php, row 108
$_m["Link original name"]
 = "Překlad názvu odkazu";

// modules/links/constants.php, row 109
$_m["Link description"]
 = "Popis odkazu";

// modules/links/constants.php, row 111
$_m["Link rate"]
 = "Hodnocení odkazu";

// modules/links/constants.php, row 112
$_m["Link votes"]
 = "Hlasování o odkazu";

// modules/links/constants.php, row 113
$_m["Link - last vote date"]
 = "Datum posledního hlasování o odkazu";

// modules/links/constants.php, row 114
$_m["Link - created by"]
 = "Vkladatele odkazu";

// modules/links/constants.php, row 115
$_m["Link creation date"]
 = "Data vložení odkazu";

// modules/links/constants.php, row 116
$_m["Link - last edited by"]
 = "Původce poslední úpravy odkazu";

// modules/links/constants.php, row 117
$_m["Link - last edit date"]
 = "Data poslední úpravy odkazu";

// modules/links/constants.php, row 118
$_m["Link - checked by"]
 = "Původce poslední kontroly odkazu";

// modules/links/constants.php, row 119
$_m["Link - last checked date"]
 = "Data poslední kontroly odkazu";

// modules/links/constants.php, row 120
$_m["Link author's e-mail"]
 = "E-mail vkladatele odkazu";

// modules/links/constants.php, row 121
$_m["Link url"]
 = "URL odkazu";

// modules/links/constants.php, row 122
$_m["Link link"]
 = "Odkaz";

// modules/links/constants.php, row 123
$_m["Link flag"]
 = "";

// modules/links/constants.php, row 124
$_m["Link - validity rank"]
 = "Dostupnost odkazu";

// modules/links/constants.php, row 125
$_m["Link - last validation date"]
 = "Odkaz - poslední kontrola dostupnosti";

// modules/links/constants.php, row 126
$_m["Link editor's note"]
 = "Odkaz - poznámka editora";

// modules/links/constants.php, row 127
$_m["Link organization city"]
 = "Odkaz - Organizace - město";

// modules/links/constants.php, row 128
$_m["Link organization street"]
 = "Odkaz - Organizace - ulice";

// modules/links/constants.php, row 129
$_m["Link organization post code"]
 = "Odkaz - Organizace - PSČ";

// modules/links/constants.php, row 130
$_m["Link organization phone"]
 = "Odkaz - Organizace - telefon";

// modules/links/constants.php, row 131
$_m["Link organization fax"]
 = "Odkaz - Organizace - fax";

// modules/links/constants.php, row 132
$_m["Link organization e-mail"]
 = "Odkaz - Organizace - e-mail";

// modules/links/constants.php, row 133
$_m["Link folder (status code) - 1~Active, 2~Holding, 3~Trash"]
 = "Odkaz - Schránka (1~Aktivní, 2~Zásobník, 3~Koš)";

// modules/links/constants.php, row 134
$_m["Link - ids of regions (comma separated)"]
 = "Odkaz - Id regionů (oddělené čárkou)";

// modules/links/constants.php, row 135
$_m["Link - names of regions (comma separated)"]
 = "Odkaz - Jména regionů (oddělené čárkou)";

// modules/links/constants.php, row 136
$_m["Link - ids of languages (comma separated)"]
 = "Odkaz - Id jazyků (oddělené čárkou)";

// modules/links/constants.php, row 137
$_m["Link - names of languages (comma separated)"]
 = "Odkaz - jména jazyků (oddělené čárkou)";

// modules/links/constants.php, row 138
$_m["Link - short names of languages (comma separated)"]
 = "Odkaz - krátká jména jazyků (oddělené čárkou)";

// modules/links/constants.php, row 139
$_m["Category ids (comma separated)"]
 = "Id kategorií (oddělené čárkou)";

// modules/links/constants.php, row 140
$_m["Category names (comma separated)"]
 = "Jména kategorií (oddělené čárkou)";

// modules/links/constants.php, row 141
$_m["State of link in this category (visible/highlighted)"]
 = "";

// modules/links/constants.php, row 142
$_m["Link - validity color"]
 = "";

// modules/links/constants.php, row 143
$_m["Paths to the categories"]
 = "";

// modules/links/constants.php, row 144
$_m["Number of found Links"]
 = "Počet nalezených odkazů";

// modules/links/constants.php, row 145
$_m["Link to link editing page (for admin interface only)"]
 = "Odkaz na editaci odkazu (jen pro admin stránky)";

// modules/links/constants.php, row 146
$_m["Category listing with links (for admin interface only)"]
 = "Přehled kategorií s odkazy (jen pro admin stránky)";

// modules/links/constants.php, row 158
$_m["Path"]
 = "Cesta";

// modules/links/constants.php, row 159
$_m["Link Count"]
 = "Počet odkazů";

// modules/links/constants.php, row 161
$_m["Note"]
 = "Poznámka";

// modules/links/constants.php, row 172
$_m["Number of links in category"]
 = "Počet odkazů v kategorii";

// modules/links/constants.php, row 175
$_m["Crossreferenced category"]
 = "Krosreferencovaná kategorie";

// modules/links/constants.php, row 176
$_m["Path to current category"]
 = "";

// modules/links/constants.php, row 177
$_m["Is this category general one? (1/0)"]
 = "";

// modules/links/constants.php, row 178
$_m["Print category priority, if category is general one."]
 = "";

// modules/links/catedit.php, row 38
// modules/links/catedit2.php, row 196
$_m["No permission to edit category"]
 = "Nemáte právo editovat tuto kategorii.";

// modules/links/catedit.php, row 61
$_m["ActionApps - Category Edit"]
 = "Editace kategorie";

// modules/links/catedit.php, row 84
$_m["Category Edit"]
 = "Editace kategorie";

// modules/links/catedit.php, row 90
$_m["Category"]
 = "Kategorie";

// modules/links/catedit.php, row 92
$_m["Links in subtree"]
 = "Počet odkazů v kategorii - včetně všech podkategorií";

// modules/links/catedit.php, row 95
$_m["No links"]
 = "Bez odkazů";

// modules/links/catedit.php, row 95
$_m["Disalow storing of the links to this category?"]
 = "Tato kategorie nesmí obsahovat odkazy?";

// modules/links/catedit.php, row 98
$_m["Subcategories"]
 = "Podkategorie";

// modules/links/catedit.php, row 102
// modules/links/linkedit.php, row 477
$_m["Category tree"]
 = "Strom kategorií celého katalogu";

// modules/links/catedit.php, row 102
$_m["select the category for crossreference"]
 = "Vyberte kategorii, kterou chcete crossrefernecovat";

// modules/links/catedit.php, row 104
$_m["Selected subcategories"]
 = "Podkategorie";

// modules/links/catedit.php, row 104
$_m["subcategories of this category"]
 = "Přidejte či zrušte podkategorie této kategorie.<br>Z levého okna můžete též crossreferencovat kategorii z jiné části katalogu.";

// modules/links/catedit.php, row 124
$_m["New subcategory"]
 = "Jméno nové podkategorie";

// modules/links/catedit.php, row 124
$_m["Add"]
 = "Vytvoř novou";

// modules/links/catedit.php, row 126
$_m["Remove selected subcategory?"]
 = "Zrušit vybranou podkategorii?";

// modules/links/catedit.php, row 126
$_m["Del"]
 = "Zruš vybranou";

// modules/links/catedit.php, row 127
$_m["Change state"]
 = "Změň stav podkategorie";

// modules/links/linkedit2.php, row 30
$_m["Not accepted, sorry. Looks like spam."]
 = "";

// modules/links/linkedit2.php, row 59
$_m["Link change proposal inserted"]
 = "Vložen návrh na změnu";

// modules/links/linkedit2.php, row 59, 63
$_m["Link inserted"]
 = "Odkaz byl vložen";

// modules/links/linkedit2.php, row 69
$_m["Continue"]
 = "";

// modules/links/modedit.php, row 57
$_m["No permission to add module"]
 = "";

// modules/links/modedit.php, row 62
$_m["No permission to edit module"]
 = "";

// modules/links/modedit.php, row 80, 219, 222
$_m["Start category id"]
 = "";

// modules/links/modedit.php, row 81, 225, 228
$_m["Tree start id"]
 = "";

// modules/links/modedit.php, row 82, 226, 229
$_m["Select start id"]
 = "";

// modules/links/modedit.php, row 189
$_m["ActionApps - Links Admin"]
 = "Nastavení modulu odkazů";

// modules/links/modedit.php, row 195
$_m["Add Links module"]
 = "Přidat modul odkazů";

// modules/links/modedit.php, row 195
$_m["Edit Links module"]
 = "Editovat modul odkazů";

// modules/links/modedit.php, row 201
$_m["Module Links data"]
 = "Data modulu odkazů";

// modules/links/modedit.php, row 203
$_m["ID"]
 = "";

// modules/links/modedit.php, row 204
$_m["Title"]
 = "Jméno";

// modules/links/modedit.php, row 205
$_m["URL of .shtml page"]
 = "";

// modules/links/modedit.php, row 206
$_m["Use following SSI command to include links to the page: "]
 = "";

// modules/links/modedit.php, row 207
$_m["Owner"]
 = "";

// modules/links/modedit.php, row 209
$_m["New Owner"]
 = "";

// modules/links/modedit.php, row 210
$_m["New Owner's E-mail"]
 = "";

// modules/links/modedit.php, row 213
$_m["Deleted"]
 = "";

// modules/links/modedit.php, row 215
$_m["Used Language File"]
 = "";

// modules/links/cattree.php, row 424
$_m["Switch to category"]
 = "Jdi";

// modules/links/check_url.php, row 17
$_m["ActionApps - URL Check"]
 = "Hledání URL v katalogu";

// modules/links/check_url.php, row 28
$_m["URL"]
 = "";

// modules/links/check_url.php, row 29
$_m["In category"]
 = "Zařazeno v kategorii";

// modules/links/check_url.php, row 29
$_m["Link is not assigned to any category"]
 = "Odkaz není zařazen do žádné kategorie";

// modules/links/check_url.php, row 29
$_m["Edit"]
 = "Edituj";

// modules/links/check_url.php, row 29
$_m["Rewrites link in bottom form"]
 = "Přepíše odkaz ve formuláři";

// modules/links/check_url.php, row 34
$_m["Page with the specified Url is not in database, yet"]
 = "Tento odkaz v katalogu ještě není zařazen.";

// modules/links/check_url.php, row 38
// include/util.php, row 1383
$_m["Back"]
 = "Zpět";

// modules/links/catedit2.php, row 146
$_m["Category reassigned"]
 = "Kategorie byla přesunuta";

// modules/links/catedit2.php, row 155
$_m["Subcategory deleted"]
 = "Podkategorie byla zrušena";

// modules/links/catedit2.php, row 157
$_m["Can't delete category which contains links"]
 = "Nelze zrušit kategorii, která obsahuje odkazy.";

// modules/links/catedit2.php, row 209
$_m["Additional information"]
 = "Další informace";

// modules/links/catedit2.php, row 219
$_m["Category data changed"]
 = "Údaje pro kategorii byly změněny";

// modules/links/catedit2.php, row 279
$_m["New category created"]
 = "Byla vytovřena nová kategorie";

// modules/links/catedit2.php, row 285
$_m["Foreign category assigned"]
 = "Byla přiřazena crossreferencovaná kategorie";

// modules/links/actions.php, row 63
$_m["Can't get link data"]
 = "Údaje o odkazu nelze zobrazit.";

// modules/links/actions.php, row 66, 88
$_m["No permission to change state of the link"]
 = "Nemáte právo měnit údaje odkazu.";

// modules/links/actions.php, row 84, 111, 156, 181
$_m["Can't find link in given category"]
 = "Odkaz v zadané kategorii nelze najít.";

// modules/links/actions.php, row 117, 187
$_m["No permission to delete link from the category - link set as PROPOSAL to DELETE from given category"]
 = "Nemáte oprávnění zrušit zařazení odkazu do kategorie - odkaz byl navržen na vymazání z této kategorie příslušnému správci.";

// modules/links/actions.php, row 124, 194
$_m["Can't get asociation informations"]
 = "Nelze zjistit údaje o zařazení odkazu do kategorie";

// modules/links/actions.php, row 159
$_m["No permission to approve link to given category"]
 = "Nemáte oprávnění pro potvrzení odkazu ve vybrané kategorii.";

// modules/links/actions.php, row 226
$_m["Base category not found"]
 = "Nenalezena primární (base) kategorie";

// modules/links/actions.php, row 230
$_m["No permission to move link"]
 = "Nemáte práva přesunout odkaz";

// modules/links/link.class.php, row 639
// modules/links/linkedit.php, row 429
$_m["Page name"]
 = "Název odkazu";

// modules/links/link.class.php, row 640
// modules/links/linkedit.php, row 432
$_m["Original page name"]
 = "Překlad názvu";

// modules/links/link.class.php, row 646
// modules/links/linkedit.php, row 518
$_m["City"]
 = "Město / Obec";

// modules/links/link.class.php, row 647
// modules/links/linkedit.php, row 520
$_m["Street"]
 = "Ulice + cislo";

// modules/links/link.class.php, row 648
// modules/links/linkedit.php, row 522
$_m["Post code"]
 = "PSČ";

// modules/links/link.class.php, row 649
// modules/links/linkedit.php, row 524
$_m["Phone"]
 = "Telefon";

// modules/links/link.class.php, row 650
// modules/links/linkedit.php, row 526
$_m["Fax"]
 = "";

// modules/links/moddelete.php, row 34
$_m["You don't have permissions to delete a links module."]
 = "";

// modules/links/moddelete.php, row 38
$_m["Module not found."]
 = "Modul nenalezen";

// modules/links/moddelete.php, row 60
$_m["Links module successfully deleted"]
 = "Modul správz odkazů bzl vzmazán";

// modules/links/linkedit.php, row 36
$_m["Accept"]
 = "Přijmout";

// modules/links/linkedit.php, row 38
$_m["unfilled"]
 = "prázdná hodnota";

// modules/links/linkedit.php, row 74
// modules/links/menu.php, row 71, 72
$_m["Edit Link"]
 = "Editace odkazu";

// modules/links/linkedit.php, row 81
// modules/links/menu.php, row 71
$_m["Add Link"]
 = "Nový odkaz";

// modules/links/linkedit.php, row 86
$_m["Link do not exist or the base category is not set"]
 = "Adresa (odkaz) neexistuje nebo nemá přiřazenu základní kategorii";

// modules/links/linkedit.php, row 248, 266
$_m["Proposal to uncheck"]
 = "Návrh na vyřazení";

// modules/links/linkedit.php, row 249, 267
$_m["Proposal to check"]
 = "Návrh na zařazení";

// modules/links/linkedit.php, row 297
$_m["Proposal to add"]
 = "Návrh na přidání";

// modules/links/linkedit.php, row 299
$_m["Proposal to del"]
 = "Návrh na zrušení";

// modules/links/linkedit.php, row 365
$_m["ActionApps"]
 = "ActionApps (Toolkit)";

// modules/links/linkedit.php, row 387
$_m["<!--NewPublicLinkText-->"]
 = "V tomto formuláři pro vložení nového odkazu do katalogu KORMIDLO\n"
   ."můžete ale nemusíte vyplňovat všechna pole. Pro snadné zařazení odkazu\n"
   ."do katalogu však prosíme o vyplnění především <b>URL</b> odkazu, <b>NÁZVU</b> a\n"
   ."zařazení odkazu do některé <b>KATEGORIE</b>. Máte-li se stránkami zkušenosti,\n"
   ."můžete vyplnit také popis odkazu.<br>\n"
   ."<b>Nevkládejte prosím do katalogu \"komerční\" odkazy.</b> Tyto odkazy nebudou v katalogu vystaveny.<br>\n"
   ." Děkujeme.";

// modules/links/linkedit.php, row 403
$_m["Link"]
 = "Odkaz";

// modules/links/linkedit.php, row 417
$_m["Check url"]
 = "Hledej URL v Kormidle";

// modules/links/linkedit.php, row 418
// modules/links/menu.php, row 65
// include/searchbar.class.php, row 639
$_m["View"]
 = "Zobraz";

// modules/links/linkedit.php, row 419
$_m["You can check, if the page is not in database already"]
 = "Internetová adresa odkazu. Pozor na překlepy!";

// modules/links/linkedit.php, row 424
$_m["Do not fill, please:"]
 = "";

// modules/links/linkedit.php, row 430
$_m["English name of the page"]
 = "Originální název odkazu (český nebo cizojazyčný)";

// modules/links/linkedit.php, row 433
$_m["Name of the page in original language"]
 = "Překlad originálního názvu odkazu je-li cizojazyčný. Slovenský název se nepřekládá.";

// modules/links/linkedit.php, row 436
$_m["Do not use HTML tags and do not write words like \"best page\", ... The maximum length of the description should be about 250 characters."]
 = "Popis = anotace odkazu. Maximální délka je 300 znaků - cca 2 věty. Používejte 3. osobu. Nepoužívejte slova typu nejlepší, nejúplnější, tyto stránky; neuvádějte kontaktní údaje.";

// modules/links/linkedit.php, row 443
$_m["Select the type, if the link belongs to some special category"]
 = "Je-li to relevantní, vyberte pro odkaz obecnou kategorii.";

// modules/links/linkedit.php, row 452
$_m["Author's e-mail"]
 = "E-mail vkladatele";

// modules/links/linkedit.php, row 456
$_m["You can type any message here - it is never shown on the website"]
 = "Poznámka pro správce - na webu se nikde neobjeví";

// modules/links/linkedit.php, row 461
$_m["Last checked"]
 = "Poslední kontrola:";

// modules/links/linkedit.php, row 461, 462, 463
$_m["n/j/Y"]
 = "j. n. Y";

// modules/links/linkedit.php, row 462
$_m["Last changed"]
 = "Poslední změna:";

// modules/links/linkedit.php, row 463
$_m["Inserted"]
 = "Vloženo:";

// modules/links/linkedit.php, row 470
$_m["Show in category"]
 = "Zařazení odkazu do kategorií";

// modules/links/linkedit.php, row 477
$_m["select the category"]
 = "Zde vybírejte kategorie.<br> Po nalistování kategorie ve stromu ji můžete přidat k odkazu kliknutím na šipku vpravo";

// modules/links/linkedit.php, row 480
$_m["Selected categories"]
 = "Vybrané kategorie, kde je odkaz zařazen";

// modules/links/linkedit.php, row 480
$_m["go to the category you want to select and click on the right arrow button to select"]
 = "Vyberte alespoň jednu kategorii, jinak se odkaz v kalalogu neobjeví!";

// modules/links/linkedit.php, row 516
$_m["Organization"]
 = "Kontaktní údaje (jde-li o organizaci)";

// modules/links/linkedit.php, row 533
$_m["Regions and languages"]
 = "Regiony a jazyky";

// modules/links/linkedit.php, row 536
$_m["Region"]
 = "";

// modules/links/linkedit.php, row 536
$_m["select up to 4 regions"]
 = "Region, kterého se stránky týkají. <br> Vyberte nejvýše 4 regiony. V případě, že pokrývá více, zařaďte odkaz do nadřazené kategorie.";

// modules/links/linkedit.php, row 537
$_m["select pege's languages"]
 = "Jazyk(y), ve kterém jsou stránky psány. Uveďte všechny základní jazyky.";

// modules/links/menu.php, row 72
$_m["Add new link"]
 = "Přidej nový odkaz";

// modules/links/menu.php, row 78, 79
$_m["Link Manager"]
 = "Správa odkazů a kategorií";

// modules/links/menu.php, row 85, 86
$_m["Link Admin"]
 = "Nastavení";

// modules/links/menu.php, row 93, 94
$_m["AA"]
 = "";

// modules/links/menu.php, row 104
$_m["Folders"]
 = "Schránky odkazů";

// modules/links/menu.php, row 104
$_m["Folders with links, sorted by their status (active, changed, new, ...)"]
 = "Odkazy rozdělené podle jejích stavu - aktivní, změněné, nové,...";

// modules/links/menu.php, row 107
$_m["Active"]
 = "Aktivní";

// modules/links/menu.php, row 110
$_m["Changed Links"]
 = "Návrhy na změnu";

// modules/links/menu.php, row 113
$_m["New Links"]
 = "Nové odkazy";

// modules/links/menu.php, row 116
$_m["Holding bin"]
 = "Zásobník";

// modules/links/menu.php, row 119
$_m["Trash"]
 = "Koš";

// modules/links/menu.php, row 122
$_m["Unasigned"]
 = "Nezařazené odkazy";

// modules/links/menu.php, row 126
$_m["Set Debug OFF"]
 = "";

// modules/links/menu.php, row 126
$_m["Set Debug ON"]
 = "";

// modules/links/menu.php, row 127
$_m["Bookmarks"]
 = "Záložky odkazů";

// modules/links/menu.php, row 127
$_m["My own links"]
 = "";

// modules/links/menu.php, row 148
$_m["Main settings"]
 = "Hlavní nastavení";

// modules/links/menu.php, row 151
$_m["Links"]
 = "Správa odkazů";

// modules/links/menu.php, row 152
$_m["Design"]
 = "Vzhled";

// modules/links/menu.php, row 157
$_m["New Category View"]
 = "Nový přehled kategorií";

// modules/links/menu.php, row 160
$_m["New Link View"]
 = "Nový přehled odkazů";

// src/Manager.php, row 83
$_m["Searchbar"]
 = "";

// src/Manager.php, row 84
$_m["Scroller"]
 = "";

// src/Manager.php, row 85
$_m["Msg"]
 = "";

// src/Manager.php, row 86
$_m["Bin"]
 = "";

// src/Manager.php, row 87
$_m["Module ID"]
 = "";

// src/Manager.php, row 147
$_m["No item found"]
 = "Žádný odkaz nenalezen.";

// src/Manager.php, row 198
$_m["Publish date"]
 = "";

// src/Manager.php, row 198
$_m["Headline"]
 = "";

// src/Manager.php, row 198
$_m["Actions"]
 = "";

// src/Manager.php, row 585
$_m["Select all"]
 = "Vybrat vše";

// src/Manager.php, row 590
$_m["Selected items"]
 = "Vybrané odkazy";

// src/Manager.php, row 597
$_m["Go"]
 = "Proveď";

// src/Manager.php, row 614
$_m["Items Page"]
 = "Stránka odkazů";

// src/Manager.php, row 663
$_m["Action ID"]
 = "";

// src/Manager.php, row 664
$_m["URL to open"]
 = "";

// src/Manager.php, row 665
$_m["Additional URL parameters"]
 = "";

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

// include/searchbar.class.php, row 553, 616
$_m["Search"]
 = "Vyhledat";

// include/searchbar.class.php, row 558
$_m["And"]
 = "a zároveň";

// include/searchbar.class.php, row 567
$_m["contains"]
 = "obsahuje";

// include/searchbar.class.php, row 568
$_m["begins with"]
 = "začíná";

// include/searchbar.class.php, row 569
$_m["is"]
 = "je";

// include/searchbar.class.php, row 598
$_m["Order"]
 = "Seřadit";

// include/searchbar.class.php, row 606
$_m["Descending"]
 = "Sestupně";

// include/searchbar.class.php, row 617
$_m["Clear"]
 = "Vyčistit";

// include/searchbar.class.php, row 620
$_m["Stored search name"]
 = "Jméno filtru";

// include/searchbar.class.php, row 621
$_m["You have the permission to add stored search globally. Do you want to add this query as global (common to all slice users)?"]
 = "Uložit globálně? Filtr můžete uložit tak, že bude viditelný pro všechny uživatele tohoto webíku - tedy globálně [ OK ], či pouze jako váš osobní filtr [ Storno ]. Má-li být filtr globální - klikněte na OK, má-li být váš osobní - klikněte na Storno (popř. Cancel).";

// include/searchbar.class.php, row 621
$_m["Store"]
 = "Uložit";

// include/searchbar.class.php, row 636
$_m["Stored searches"]
 = "Uložené filtry";

// include/searchbar.class.php, row 642
$_m["Are you sure to refine current search?"]
 = "Opravdu chcete předefinovat vybraný filtr?";

// include/searchbar.class.php, row 642
$_m["Update"]
 = "Změň";

// include/searchbar.class.php, row 643
$_m["Enter new name"]
 = "Zadej nové jméno";

// include/searchbar.class.php, row 643
$_m["Rename"]
 = "Přejmenovat";

// include/searchbar.class.php, row 644
$_m["Are you sure to delete selected search?"]
 = "Opravdu smazat vybraný filtr?";

// include/searchbar.class.php, row 644
$_m["Delete"]
 = "Odstranit";

// include/searchbar.class.php, row 935
$_m["Select one..."]
 = "vyber ...";

// include/util.php, row 981, 1038
$_m["Error: Missing Reading Password"]
 = "";

// include/util.php, row 1375
$_m["Toolkit news message"]
 = "Vzkaz z Toolkitu";

// include/util.php, row 1608
$_m["Internal error. File upload: Dir does not exist?!"]
 = "";

// include/util.php, row 1612
$_m["File with this name already exists."]
 = "";

// include/util.php, row 1619
$_m["Can't move image  %s to %s"]
 = "";

// include/util.php, row 1867
$_m["alerts alert"]
 = "";

// include/util.php, row 1868
$_m["alerts welcome"]
 = "";

// include/util.php, row 1869
$_m["slice wizard welcome"]
 = "";

// include/util.php, row 1870
$_m["other"]
 = "";

// include/util.php, row 1878
$_m["January"]
 = "leden";

// include/util.php, row 1878
$_m["February"]
 = "únor";

// include/util.php, row 1878
$_m["March"]
 = "březen";

// include/util.php, row 1878
$_m["April"]
 = "duben";

// include/util.php, row 1878
$_m["May"]
 = "květen";

// include/util.php, row 1878
$_m["June"]
 = "červen";

// include/util.php, row 1879
$_m["July"]
 = "červenec";

// include/util.php, row 1879
$_m["August"]
 = "srpen";

// include/util.php, row 1879
$_m["September"]
 = "září";

// include/util.php, row 1879
$_m["October"]
 = "říjen";

// include/util.php, row 1879
$_m["November"]
 = "listopad";

// include/util.php, row 1879
$_m["December"]
 = "prosinec";


