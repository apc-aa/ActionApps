### List of removed, no longer maintained files in ActionApps

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
