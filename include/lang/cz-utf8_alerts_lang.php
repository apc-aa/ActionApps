<?php
// $Id$
// Language: CZ-UTF8
// This file was created automatically by the Mini GetText environment
// on 5.9.2008 16:30

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
$_m["Define selections in slices from which you want to send Alerts, \n\n\n"
   ."        in views of type Alerts Selection Set"]
 = "Vytvořte výběry ve webícíh, z nichž chcete posílat novinky,\n\n\n"
   ."     v pohledech typu Zasílání - výběry";

$_m["First set the Alert Email in the Settings menu."]
 = "Nejprve nastavte Email se zprávou v Nastavení";

$_m["Alert Email for this Collection: "]
 = "Email se zprávou pro tento modul: ";

$_m["Edit"]
 = "Upravit";

$_m["Choose another"]
 = "Zvolte jiný";

$_m["Send now an example email to"]
 = "Poslat ukázkový email na";

$_m["%1 email sent"]
 = "%1 emailů posláno";

$_m["Wrong user ID"]
 = "Chybné ID uživatele";

$_m["Wrong password."]
 = "Špatné heslo";

$_m["Adds only fields the IDs of which don't yet exist in the slice.\n\n"
   ."    Refreshes the filters constant group if it already exists."]
 = "Přidá pouze políčka jejichž ID ještě neexistují ve webíku. \n"
   ."    Obnoví skupinu hodnot s výběry pokud tato již existuje.";

$_m["The two given passwords differ."]
 = "Zadané kopie hesla se liší.";

$_m["Define selections in slices from which you want to send Alerts, \n\n"
   ."        in views of type Alerts Selection Set"]
 = "Vytvořte výběry ve webícíh, z nichž chcete posílat novinky,\n"
   ."     v pohledech typu Zasílání - výběry";

$_m["Choose Filters"]
 = "Zvolte Výběry";

$_m["Please confirm your subscription"]
 = "Prosím potvrďte svou přihlášku";

$_m["<p>Hello,</p><p>you didn't yet confirm your subscription to AA Alerts. You can't receive any emails until you do so. \n"
   ."                Please click on URL:</p>%1<p>or copy the URL to your web browser. \n"
   ."             The confirmation is done in order that we can see you did not subscribe by mistake and your e-mail is working.</p><p>Yours<br>&nbsp; &nbsp; &nbsp;APC Alerts moderators</p>"]
 = "<p>Dobrý den,</p><p>nepotvrdil(a) jste své přihlášení do Zasílání zpráv. \n"
   ."     Nemůžete dostávat e-maily, dokud tak neučiníte. \n"
   ."                Klepněte prosím na URL:</p>%1<p>nebo URL zkopírujte do svého webového prohlížeče. \n"
   ."             Potvrzení se vyžaduje proto, abychom věděli, že nejste přihlášen(a) omylem a že Váš e-mail funguje.</p><p>Vaši<br>&nbsp; &nbsp; &nbsp;moderátoři Zasílání zpráv</p>";

$_m["Links"]
 = "Správa odkazů";

$_m["Polls"]
 = "Ankety";

$_m["Alerts"]
 = "Zasílání e-mailů";

$_m["Reader Management Slice"]
 = "Správa čtenářů";

$_m["Define selections in slices from which you want to send Alerts,\n\n"
   ."        in views of type Alerts Selection Set"]
 = "Vytvořte výběry ve webícíh, z nichž chcete posílat novinky,\n"
   ."     v pohledech typu Zasílání - výběry";

$_m["Collection Form Wizard"]
 = "Průvodce Formulářem pro Zasílání";

$_m["Add Users"]
 = "Přidat čtenáře";

$_m["Some errors occured: "]
 = "Nastaly nějaké chyby: ";

$_m["Enter email addresses one on a row, you may add first and last name separated by whitespace (spaces, tabs), e.g."]
 = "Vložte emailové adresy - každou na zvláštní řádek. Na řádek můžete vložit i křestní jméno a příjmení (oddělené mezerou či tabulátorem).";

$_m["Confirmation"]
 = "Potvrzení";

$_m["Send a confirmation email to users (recommended)."]
 = "Zaslat potvrzovací email uživatelům (doporučeno)";

$_m["Subscribe users immediately (use carefully)."]
 = "Zapsat (subscribe) uživatele okamžitě (opatrně s touto volbou)";

$_m["Go"]
 = "Proveď";

$_m["Example"]
 = "Příklad";

$_m["digest"]
 = "souhrn";

# End of unused messages
// modules/alerts/synchro2.php, row 42
// modules/alerts/cf_common.php, row 69
$_m["How often"]
 = "Jak často";

// modules/alerts/synchro2.php, row 44, 52
$_m["How often for {ALERNAME}"]
 = "Jak často pro {ALERNAME}";

// modules/alerts/synchro2.php, row 49
$_m["not subscribed"]
 = "neodebírat";

// modules/alerts/synchro2.php, row 66
// modules/alerts/send_emails.php, row 126
// modules/alerts/menu.php, row 107
// modules/alerts/tableviews.php, row 100
$_m["Selections"]
 = "Výběry";

// modules/alerts/synchro2.php, row 67
$_m["Selections for {ALERNAME}"]
 = "Výběry pro {ALERNAME}";

// modules/alerts/synchro2.php, row 74
$_m["Selecetion IDs for {ALERNAME}"]
 = "ID výběrů pro {ALERNAME}";

// modules/alerts/synchro2.php, row 194
$_m["%1 field(s) added"]
 = "%1 políček přidáno";

// modules/alerts/synchro2.php, row 220
$_m["%1 field(s) and %2 constant group(s) deleted"]
 = "%1 políček a %2 skupin hodnot smazáno";

// modules/alerts/synchro2.php, row 247
$_m["not set"]
 = "nenastaveno";

// modules/alerts/tabledit.php, row 68
$_m["You have not permissions to add slice"]
 = "Nemáte práva pro přidání webíku";

// modules/alerts/util.php, row 57
$_m["instant"]
 = "okamžitě";

// modules/alerts/util.php, row 59
$_m["daily"]
 = "denně";

// modules/alerts/util.php, row 60
$_m["weekly"]
 = "týdně";

// modules/alerts/util.php, row 61
$_m["monthly"]
 = "měsíčně";

// modules/alerts/util.php, row 67
$_m["Active"]
 = "Aktivní";

// modules/alerts/util.php, row 68
$_m["Holding bin"]
 = "Zásobník";

// modules/alerts/util.php, row 69
$_m["Trash bin"]
 = "Koš";

// modules/alerts/cf_common.php, row 44
$_m["Language"]
 = "Jazyk";

// modules/alerts/cf_common.php, row 49
// modules/alerts/send_emails.php, row 62
$_m["Email"]
 = "";

// modules/alerts/cf_common.php, row 54
$_m["Password"]
 = "Heslo";

// modules/alerts/cf_common.php, row 58
$_m["First name"]
 = "Křestní jméno";

// modules/alerts/cf_common.php, row 62
$_m["Last name"]
 = "Příjmení";

// modules/alerts/cf_common.php, row 75
$_m["Change password"]
 = "Změnit heslo";

// modules/alerts/cf_common.php, row 80
$_m["Retype new password"]
 = "Zopakujte nové heslo";

// modules/alerts/send_emails.php, row 53
$_m["Send now an example alert email to"]
 = "Poslat ukázkový email se zprávami na";

// modules/alerts/send_emails.php, row 63, 68
$_m["as if"]
 = "jako kdyby";

// modules/alerts/send_emails.php, row 64, 69, 84
$_m["Go!"]
 = "Proveď!";

// modules/alerts/send_emails.php, row 67
$_m["Reader"]
 = "";

// modules/alerts/send_emails.php, row 76
$_m["Send alerts"]
 = "Poslat novinky";

// modules/alerts/send_emails.php, row 83
$_m["Send now alerts to all users subscribed to "]
 = "Poslat novinky uživatelům zapsaným pro ";

// modules/alerts/send_emails.php, row 83
$_m["Warning: This is a real command!"]
 = "Pozor: Toto je skutečný příkaz!";

// modules/alerts/send_emails.php, row 87
$_m["Last time the alerts were sent on:"]
 = "Naposled byly novinky poslány:";

// modules/alerts/send_emails.php, row 97
$_m["%1 email(s) sent"]
 = "%1 emailů posláno";

// modules/alerts/send_emails.php, row 124
$_m["Slice"]
 = "Webík";

// modules/alerts/send_emails.php, row 125
$_m["View (Selection set)"]
 = "Pohled (Výběry)";

// modules/alerts/send_emails.php, row 131
$_m["Define selections in slices from which you want to send Alerts,\n"
   ."        in views of type Alerts Selection Set"]
 = "";

// modules/alerts/menu.php, row 52, 53
// modules/alerts/tableviews.php, row 148, 149
$_m["Alerts Settings"]
 = "Nastavení Zasílání";

// modules/alerts/menu.php, row 86
$_m["AA"]
 = "";

// modules/alerts/menu.php, row 87
$_m["AA Administration"]
 = "Nastavení AA";

// modules/alerts/menu.php, row 100
// modules/alerts/tableviews.php, row 253, 254
$_m["Alerts Admin"]
 = "Nastavení Zasílání";

// modules/alerts/menu.php, row 104
$_m["Settings"]
 = "Nastavení";

// modules/alerts/menu.php, row 111
$_m["Send emails"]
 = "Poslat emaily";

// modules/alerts/menu.php, row 114
$_m["Reader management"]
 = "Správa čtenářů";

// modules/alerts/menu.php, row 115
$_m["Documentation"]
 = "Dokumentace";

// modules/alerts/menu.php, row 117
$_m["Common"]
 = "Společné";

// modules/alerts/menu.php, row 119
$_m["Email templates"]
 = "Šablony emailů";

// modules/alerts/synchro.php, row 42
$_m["Slice Synchro"]
 = "Synchro s webíkem";

// modules/alerts/synchro.php, row 47
$_m["Synchronization with Reader Management Slice"]
 = "Synchronizace s webíkem spravujícím čtenáře";

// modules/alerts/synchro.php, row 72
$_m["Not Yet Set"]
 = "Ještě nenastaveno";

// modules/alerts/synchro.php, row 81
$_m["Choose Reader Management Slice"]
 = "Zvolte webík se Správou Čtenářů";

// modules/alerts/synchro.php, row 82
$_m["This Alerts Collection takes user data from the slice"]
 = "Toto Zasílání používá čtenářská data z webíku";

// modules/alerts/synchro.php, row 88
$_m["Change to: "]
 = "Změnit na: ";

// modules/alerts/synchro.php, row 94
$_m["and delete the %1-specific fields from %2"]
 = "a smazat políčka specifická pro %1 z %2";

// modules/alerts/synchro.php, row 98
$_m["Change"]
 = "Změnit";

// modules/alerts/synchro.php, row 108
$_m["Add %1-specific fields to %2"]
 = "Přidat políčka specifická pro %1 do %2";

// modules/alerts/synchro.php, row 111
$_m["Adds only fields the IDs of which don't yet exist in the slice.\n"
   ."    Refreshes the constant group containing selections if it already exists."]
 = "Přidá pouze políčka jejichž ID ještě neexistují ve webíku. \n"
   ."    Obnoví skupinu hodnot s výběry pokud tato již existuje.";

// modules/alerts/synchro.php, row 116
$_m["Field Name"]
 = "Název Políčka";

// modules/alerts/synchro.php, row 117
$_m["Field ID"]
 = "ID Políčka";

// modules/alerts/synchro.php, row 127
$_m["Add or refresh fields"]
 = "Přidat nebo obnovit políčka";

// modules/alerts/synchro.php, row 128
$_m["This command can not be used until you choose the Reader Management Slice."]
 = "Tento příkaz nemůžete použít dokud nezvolíte Webík se Správou Čtenářů.";

// modules/alerts/tableviews.php, row 61
$_m["No selections defined. You must define some."]
 = "Žádné výběry nejsou definované.";

// modules/alerts/tableviews.php, row 99
$_m["Alerts Selections"]
 = "Zasílání - Výběry";

// modules/alerts/tableviews.php, row 103
$_m["Choose selections which form the Alert email."]
 = "Zvolte výběry, které tvoří zasílaný email.";

// modules/alerts/tableviews.php, row 110
$_m["selection"]
 = "výběr";

// modules/alerts/tableviews.php, row 120
$_m["order"]
 = "pořadí";

// modules/alerts/tableviews.php, row 152
$_m["Core settings for the Alerts."]
 = "Základní nastavení Zasílání.";

// modules/alerts/tableviews.php, row 161
$_m["alerts ID"]
 = "ID zasílání";

// modules/alerts/tableviews.php, row 168
$_m["name"]
 = "název";

// modules/alerts/tableviews.php, row 170
$_m["form URL"]
 = "URL formuláře";

// modules/alerts/tableviews.php, row 172
$_m["language"]
 = "jazyk";

// modules/alerts/tableviews.php, row 175
$_m["deleted"]
 = "vymazán";

// modules/alerts/tableviews.php, row 176
$_m["Use AA Admin / Delete<br>to delete permanently"]
 = "Použijte AA / Smazat<br>k trvalému odstranění";

// modules/alerts/tableviews.php, row 180
$_m["welcome email"]
 = "uvítací email";

// modules/alerts/tableviews.php, row 187, 236
$_m["alert email"]
 = "email s novinkami";

// modules/alerts/tableviews.php, row 197
$_m["created at"]
 = "vytvořeno kdy";

// modules/alerts/tableviews.php, row 204
$_m["created by"]
 = "vytvořeno kým";

// modules/alerts/tableviews.php, row 212, 231
$_m["You don't have permissions to edit any collection or no collection exists."]
 = "Nemáte právo editovat žádné zasílání nebo žádné neexistuje.";

// modules/alerts/tableviews.php, row 225, 226
$_m["Send Emails"]
 = "Posílání Emailů";

// modules/alerts/tableviews.php, row 232
$_m["Here you send the Alert emails manually."]
 = "Zde můžete odeslat maily ze Zasílání ručně.";

// modules/alerts/tableviews.php, row 266
$_m["confirm mail"]
 = "potvrzovací mail";

// modules/alerts/tableviews.php, row 267, 274
$_m["number of days, 0 = off"]
 = "počet dní, 0 = vypnuto";

// modules/alerts/tableviews.php, row 273
$_m["delete not confirmed"]
 = "smazat nepotvrzené";

// modules/alerts/tableviews.php, row 280
$_m["last confirm mail"]
 = "";

// modules/alerts/tableviews.php, row 287
$_m["last delete not confirmed"]
 = "";

// modules/alerts/tableviews.php, row 303
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

// modules/alerts/tableviews.php, row 367
$_m["no"]
 = "ne";

// modules/alerts/tableviews.php, row 367
$_m["yes"]
 = "ano";


