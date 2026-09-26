### List of removed, no longer maintained files in ActionApps

/**INSTALL**  
/doc/**install.html**, **install-1.html** … **install-8.html**, **install-3-old.html**  
Installation guide from 2000–2003 (RedHat 7, PHP 4, register_globals, SSI, LDAP setup) and a short plain-text pointer to it.  
_Outdated. Replaced by INSTALL.md (install from the git dist branch) and UPDATE.md (update, SVN → git). Removed 2026-09-19 - honzam_

/**ac1.php3**  
Shortcut for alerts confirmation to make the URL shorter (Location: $self/misc/alerts/unsubscribe.php3?id=$id)   
_No longer maintained. Removed 2025-08-13 - honzam

/**hiercons.php3**  
Show hierarchical constants  
_No longer maintained. It was unfunctional for some time already. Hierarchy now should be solved on slice level - withe related items. Removed 2022-08-19 - honzam (see older apc-aa branches to get the code)_

/**localurlpick.php**
/**localurlpick_navigator.php**
/**localurlpick_picker.php**
Local URL picker field tyupe - a tool for picking a URL from site - frames used
_Now implemented by LupWidget_


/misc/**forgotten_pwd.php**
/misc/**forgotten_pwd.php3**
Standalone "forgot your password" page - emailed an MD5 reset link.
_Orphan: nothing linked to it, and it relied on register_globals so it was already non-functional under PHP 8.2. The live forgotten-password flow is implemented as a template expansion in include/stringexpand.php (AA_Stringexpand\_\*::askForMail). Removed 2026-05-28 - honzam (see older apc-aa branches to get the code)_


/admin/**sumarize.php3**  
Generates a human readable output page that summarizes the slices on a system, compare slices and sync its settings  
_No longer maintained. Removed 2022-02-25 - honzam (see older apc-aa branches to get the code)_

/include/  
 .../extsess.php3                    - session management extension for AA  
 .../extsessi.php3                   - session management extension for AA  
  _No longer needed. Removed 2022-07-26 - honzam (see older apc-aa branches to get the code)_

  .../slicewiz.php  
  _No longer needed. Removed 2006-06-06 - honzam (see older apc-aa branches to get the code)_


/misc/**txt2fields**  
 .../txt2fields.pl                   - Replaces fields for a slice, based on a text file  
 .../fields2txt.pl                   - Exports field structure to text file, in a format that txt2fields.pl can use  
 .../list_fields.apc_member_survey  
 .../README  
  _No longer maintained. Removed 2021-01-19 - honzam (see older apc-aa branches to get the code)_

/misc/**email2slice**  
  .../email2slice.pl               - Parse an email message store it in a slice   
  .../readmail.pl  
  _No longer maintained. Removed 2021-01-19 - honzam (see older apc-aa branches to get the code)_

/misc/**mlx**  
  .../import.php                   - Import for MLX MultiLingual eXtension for ActionApps http://mimo.gn.apc.org/mlx
  .../notes.txt  
  _No longer maintained. Removed 2021-01-19 - honzam (see older apc-aa branches to get the code)_


/misc/**export_by_sql**   
  .../fill_export_tables.php3     - Allows to export a slice with all its content by using SQL   
  .../create_export_tables.sql    
  _No longer maintained. Removed 2021-01-19 - honzam (see older apc-aa branches to get the code)_     


/misc/**offline2slice**
  .../fillform.html                  
  .../Base64.class   
  .../delete_file.html   
  .../OffLine2Slice.class    
  .../OffLine2Slice.java  
  /offline.php3  
  Java based tool for sending prepared WDDX files from a local computer to ActionApps - AA/offline.php3
  Developed in the time of expensive dialup connection when it was too expensive to fill and edit the item form on the internet
  Files in  /misc/offline2slice was ment to be copied to user's computer  
  _No longer maintained. Removed 2021-01-29 - honzam (see older apc-aa branches to get the code)_     

/src/IO/DB/
  .../DB_MSSQL.php
  .../DB_ODBC.php
  .../DB_Oracle.php
  .../DB_PgSQL.php
  .../DB_mSQL.php
  .../DB_Sybase.php
  .../DB_OCI8.php
  PHPLib based database drivers for different databases - now replaced by PDO, which suppoerts prepared statements, transactions...
  _No longer maintained. Removed 2026-03-11 - honzam (see older apc-aa branches to get the code)_     

/misc/**mgettext**
  .../index.php                    - mgettext web UI (2003) for extracting _m() messages and generating lang files
  .../xmgettext.php                - extraction engine of the web UI
  .../translate.php                - web translation form
  .../createlogs.php               - helper of the web UI
  Replaced by misc/mgettext/cli.php + src/Lang/MsgExtractor.php + src/Lang/LangFile.php (UTF-8 masters, derived one-byte variants, "// ai" markers);
  _No longer maintained. Removed 2026-06-10 - honzam (see older apc-aa branches to get the code)_

/include/**lang**
  .../ru-utf8_*_lang.php (7 files)  - Russian UTF-8 language masters (alerts, links, news, output, param_wizard, polls, site)
  .../ru_*_lang.php (7 files)       - Russian windows-1251 one-byte variants
  Russian UI translation dropped (was 99% untranslated, no known install uses it); 'ru'/'ru-utf8' removed from AA\Util\Langs registry and AA\Lang\LangFile::HEADERS. Ukrainian (uk-utf8), Portuguese (pt-utf8) and Polish (pl-utf8) masters added instead.
  _No longer maintained. Removed 2026-06-12 - honzam (see older apc-aa branches to get the code)_

/admin/**tabledit.php**             - generic router that showed a TableView (GetTableView / GetMiscTableView) in the legacy tabledit CRUD editor
/include/**tableviews.php**         - GetTableView(): the polls_design / polls_design_edit table views
/include/**tv_misc.php**            - GetMiscTableView(): the email / wt / log / searchlog / fields table views
  Part of removing the legacy `tabledit` table editor. These entry points/configs were already superseded by AA\Manager pages and had no live menu links: polls designs -> modules/polls/designs.php (Poll menu, src/Module/Poll/Menu.php repointed); emails -> admin/aa_emails.php; wizard templates -> admin/aa_wizardtemplate.php; log -> admin/aa_log.php; searchlog -> admin/aa_searchlog.php. The `fields` view (raw `field` table editor) was an orphan superadmin tool - slice fields are edited via admin/se_fields.php. admin/rsstest.php was converted to AA\Manager (GetFeedManaged) in the same change. The tabledit CORE (include/tabledit.php, tabledit_util.php, tabledit_column.php, tabledit.css, tv_email.php) and doc/tabledit*.html stay until the Alerts module (modules/alerts/modedit.php) is migrated.
  _No longer maintained. Removed 2026-06-18 - honzam (see older apc-aa branches to get the code)_

/include/**tabledit.php**           - the tabledit CRUD engine (class tabledit)
/include/**tabledit_util.php**      - tabledit form processing: ProcessFormData(), TableInsert/Update/Delete(), triggers
/include/**tabledit_column.php**    - tabledit column rendering / value formatting
/**tabledit.css**                   - tabledit-only styles (.te_* + a redundant .scroller; .scroller_actual was already dead code)
/modules/alerts/**tableviews.php**  - GetAlertsTableView(): the Alerts modedit/acf/send_emails/email table views (+ dead FindAlertsUserPermissions, now-redundant AlertsModeditAfterInsert)
/include/**tv_email.php**           - GetEmailTableView(): email/email_edit views for the Alerts emails (alerts-only)
/include/**tv_common.php**          - SelectModule() + tabledit form attr/format globals (used only by tv_email)
/doc/**tabledit.html**
/doc/**tabledit_developer.html**    - tabledit user/developer documentation
  Final removal of the legacy `tabledit` table editor. The last consumer was the Alerts module, now migrated to the per-module admin convention: modules/alerts/modedit.php (rewritten, mirrors polls/links modedit - module ⋈ alerts_collection via include/modutils.php helpers), modules/alerts/emails.php (NEW - AA\Manager over `email`, scoped to the module - NOT the superadmin-global admin/aa_emails.php), modules/alerts/acf.php (NEW - alerts_collection_filter list/add/delete/reorder), modules/alerts/send.php (NEW - ShowCollectionAddOns sending UI). The two stray tabledit.css includes were dropped from admin/fileman.php and admin/search_replace.php (they used nothing from it; .scroller comes from the admin theme). composer dump-autoload regenerated to drop the stale 'tabledit' classmap entry.
  _No longer maintained. Removed 2026-06-23 - honzam (see older apc-aa branches to get the code)_

/javascript/**aajslib-src/**
Build sources of the aajslib JavaScript library (aajslib-common.js, the three
*-src.js variants, merge_common_js.sh).
_Not removed - moved to /dev/aajslib/ 2026-09-10 - honzam. `dev/` holds only build
inputs and can be deleted on a server (see INSTALL); the built files stay in
/javascript/. merge_common_js.sh now writes there explicitly._

/src/Optimize/**DatabaseStructure.php** - Optimize entry "Checks if all tables have right columns and indexes"
  A stub: test() ran SHOW CREATE TABLE over every table and always returned true, repair() did nothing, so the Optimize page (and the planned State Check page) showed it green whatever the schema looked like. Fully covered by src/Optimize/UpdateDbStructure.php, which really diffs every table against include/metabase_schema.php and rebuilds the differing ones.
  _No longer maintained. Removed 2026-09-11 - honzam (see older apc-aa branches to get the code)_

/misc/**optimize.php** - OPTIMIZE TABLE over the AA tables, fetched weekly over HTTP by the AA cron with `key=<first five characters of DB_PASSWORD>`
  The key was stored in `cron.params`, shown on admin/aa_cron.php and written into the web server's access log on every run. Replaced by the queue task src/Later/OptimizeTables.php (planned weekly from src/Later/ScheduleAaTasks.php, no HTTP, no key, work split into 30 s runs so it never blocks the queue). The cron row is removed by src/Optimize/RemoveAaCronEntries.php (2026-09-15; before that by RemoveOptimizeCronEntry.php, renamed from AddOptimizeCronEntry.php, which used to install it).
  _No longer maintained. Removed 2026-09-11 - honzam (see older apc-aa branches to get the code)_

/modules/links/**linkcheck.php** - hourly link validation, fetched over HTTP by the AA cron
  Had been failing on every run since AA\Util\Linkcheck moved to src/ (it still required the removed include/linkcheck.class.php). Replaced by the queue task src/Later/LinkcheckRun.php (planned hourly from src/Later/ScheduleAaTasks.php); Linkcheck::checking() itself is fixed in the same change (it read the row list instead of the row).
  _No longer maintained. Removed 2026-09-15 - honzam (see older apc-aa branches to get the code)_

/src/Optimize/**AddAlertsCronEntry.php**
/src/Optimize/**AddFeedingCronEntry.php**
/src/Optimize/**AddLinkcheckCronEntry.php**
/src/Optimize/**AddToexecuteCronEntry.php**
/src/Optimize/**RemoveOptimizeCronEntry.php** - sql_update optimizers that installed (or, the last one, removed) AA's own rows of the `cron` table
  All AA periodic jobs are queue tasks since 2026-09 (AA\Later\AlertsSweep, FeedsRun, LinkcheckRun, OptimizeTables, AA_Mysqlauth_Maintain; cron.php runs the queue itself through Toexecute::runQueue()), so nothing installs cron rows any more. The one class src/Optimize/RemoveAaCronEntries.php deletes whatever of those rows is left (.php3 and hand-renamed variants included); the `cron` table stays for the admin's own scripts. AddEmailTemplatesCronEntry.php stays - despite the name it installs the alerts e-mail templates, not a cron row.
  _No longer maintained. Removed 2026-09-15 - honzam (see older apc-aa branches to get the code)_

/images/**ico-user-xs.png**
/images/**ico-users-xs.png** - 16px person / people icons for the searchbar bookmark filters
  Dead since the searchbar redesign: the only rules loading them (`input[type="text"].aa-fiter-user`, `.aa-fiter-users` in admin-ecn.css) selected an input, while the class has always been rendered on the bookmark `<button class="aa-bm-name aa-fiter-user">` (include/searchbar.class.php). The button draws both icons as inline SVG data URIs, so nothing referenced the files.
  _No longer maintained. Removed 2026-09-15 - honzam (see older apc-aa branches to get the code)_

/misc/**phpThumb/** - phpthumb.class.php, phpthumb.functions.php, phpthumb.filters.php, phpthumb.bmp.php, phpthumb.gif.php, phpthumb.ico.php, phpthumb.unsharp.php, phpthumb.config.php, phpThumb.config.php.default, cache/index.php, cache/source/index.php, fonts/readme.txt, images/readme.txt - the phpThumb library copied into AA in 2007
  Left behind when phpThumb became a composer package in 2023 (honzito/phpthumb, installed to vendor/honzito/phpthumb): img.php loads it from there (AA_PHPTHUMB__DIR__), composer autoloads its class, and nothing included the copy any more - it only travelled with every install as dead, web-reachable code.
  _No longer maintained. Removed 2026-09-17 - honzam (see older apc-aa branches to get the code)_

/tests/Unit/Legacy/fixtures/**central_shims.php** - test stand-in for FrmChBoxEasy() from include/formutil.php, used by CentralDifferenceTest and CentralSynchronizeTest
  tests/Unit/fixtures/util_shims.php now loads the real include/util.php, which pulls in formutil.php, so the stand-in could never be declared any more. The shims clashed with the real files: whichever test loaded first made the other die with "Cannot redeclare function".
  _No longer maintained. Removed 2026-09-23 - honzam_

/central/include/**config.php** - example list of Central installs (`$ACTIONAPPS`) with sample superuser logins ('superuser1' / 'superpassword1' ...)
  Central reads its installs from the `central_conf` table since 2007, when synchronize.php and synchronize2.php switched to AA_Actionapps::getArray(); nothing included the file after that (every config.php include under central/ goes to the main include/config.php). It only shipped a credentials-shaped file in the web tree of every install.
  _No longer maintained. Removed 2026-09-23 - honzam_

/src/Manageraction/**Central_Sqlupdate.php** - Central → Manager actions "Update DB (sql_update) - dotest/update"
  Fetched `<AA>/service/sql_update.php?dbpw5=<first 5 characters of the DB password>&silent=1&fire=1&dotest|update=1`, so it never worked (the script needs the hand-set ACCESS_CODE, nobody read `dbpw5`) and it left a piece of the DB password in the target's access log. Replaced by src/Manageraction/Central_OptimizeType.php (same action ids Sqlupdate_Test / Sqlupdate_Update), which runs the target's own sql_update chain through the responder command Do_Optimize_Type with the stored Central login; the chain itself moved from service/sql_update.php to src/Optimize/Chain.php, which both use.
  _No longer maintained. Removed 2026-09-23 - honzam_

/misc/**filebrowser/** - KCFinder 3.12 (2014), the file browser and uploader of CKEditor 4 (browse.php, upload.php, integration/actionapps.php, js/, themes/, …)
  It checked no login (CheckAuthentication() returned true for everybody), so anyone could upload into IMG_UPLOAD_PATH, list, rename and move files there and make the server fetch any URL (act_dragUrl). Replaced by api/files.php (AA\Api\Controller\FilesController; who may upload what: AA\Upload\UploadPolicy) and the picker javascript/widgets/aa-filepicker.js, which TinyMCE (javascript/widgets/aa-richtext.js) uses. The session variable r_last_module_id, which told KCFinder the slice, went with it.
  _No longer maintained. Removed 2026-09-26 - honzam_
