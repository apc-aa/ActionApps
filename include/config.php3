<?php
//$Id$
# Application wide configuration options

// ------------------------------------------------------------------
// developer SITE_CONFIG

# Note: developers can put their site-specific config in SITE_CONFIG 
# If you add an configuration option, please put in at 
# //add new CONSTANTS here, even if you also put it in your SITE_CONFIG

# Only the first define() has any effect.
# Therefore, if constants are defined in SITE_CONFIG and also defined
# in the //add new CONSTANTS section, the second definitions do not take hold.

# we could use nested if-else if we test on something more than HTTP_HOST.
# IGC uses virtual hosts, and ServerName sets the HTTP_HOST,
# so I hope that HTTP_HOST will be enough.  It might be that we will
# also need to test the path.

switch ($HTTP_HOST) {
  case "xaa.igc.apc.org": 
#    echo "1 path";
     $AA_INC_PATH = "/usr/local/http/xaa/include/";
     // IGC tries things out in xaa.igc.apc.org,
     // and this only modifies the database xaadb (sandbox)
     // when we're ready, we copy all the files over to aa.igc.apc.org
     // we use the same config-site.inc except for DB_NAME
     define("DB_NAME", "xaadb");
     define (SITE_CONFIG, "config-igc.inc"); break;
  case "aa.igc.apc.org": 
#    echo "2 path";
     $AA_INC_PATH = "/usr/local/http/aa/include/";
     define (SITE_CONFIG, "config-igc.inc"); break;
  case "web.ecn.cz": 
#    echo "3 path";
     $AA_INC_PATH = "/usr/local/httpd/htdocs/aa/include/";
#    $AA_INC_PATH = "/home/madebeer/public_html/include/";
     define (SITE_CONFIG, "config-ecn.inc"); break;
  // maybe ecn could make a name-based virtual host for honza malik ?
  case "honzam.ecn.cz": 
#    echo "4 path";
     $AA_INC_PATH = "/home/honzama/public_html/aa/include/";
     define (SITE_CONFIG, "config-ecn.inc"); break;
  }

if (defined ("SITE_CONFIG")) {
  // require does not work as expected inside control structures!
  include ($AA_INC_PATH . SITE_CONFIG);
}
// end SITE_CONFIG
// ------------------------------------------------------------------

# //add new CONSTANTS HERE

// new INSTALL, set this variable
#  $AA_INC_PATH = ""; 
if (!isset($AA_INC_PATH)){
  echo "you must set AA_INC_PATH and other variables in config.inc !";
};

# Your Internet Domain
define("DEFAULT_ORG_ID", "ecn.cz");

# DB Access Configuration
define("DB_HOST", "localhost");
define("DB_NAME", "aadb");
define("DB_USER", "toolkit");
define("DB_PASSWORD", "");

# ID of AA (any unique 32chars long hexadecimal number)
# Please change this value to be unique
define("AA_ID", "420224311780abcd420224311780abcd");

# Select permissions system (exactly one of "dummy", "ldap", "sql")
define("PERM_LIB", "ldap");

define("LDAP_HOST", "ecn4.ecn.cz");
define("LDAP_BINDDN", "cn=aa,dc=ecn,dc=apc,dc=org");
define("LDAP_BINDPW", "");                           // password
define("LDAP_BASEDN", "dc=ecn,dc=apc,dc=org");
define("LDAP_PEOPLE", "dc=ecn,dc=apc,dc=org");
define("LDAP_GROUPS", "dc=ecn,dc=apc,dc=org");
define("LDAP_ACLS", "dc=ecn,dc=apc,dc=org");

# e-mail for bug reporting contact
define("ERROR_REPORTING_EMAIL", "technical@ecn.cz");

# set this directive to true, if you use MySQL 
# (uses LIMIT clause in SELECTs)
define("OPTIMIZE_FOR_MYSQL", false);

# set this directive to true, if your php already auto-includes phplib

# if it is 'false' and it should be true you'll get an error like:
# Fatal error: DB_Sql is already a function or class in
#/var/php/phplib/php/db_mysql.inc on line 12

# IGC does some weird things.  These are different at IGC.
define("PHPLIB_ALREADY_LOADED", false);
define("ADM_SLICE_CSS","adm_slice.css");
define("ADMIN_CSS","admin.css");

# number of shown pages links in scroller's navigation bar
define("SCROLLER_LENGTH", 7);

# Would you like to display debug messagess?
define("DEBUG_FLAG", true);

# Language: uncomment one language file
#echo "AA is $AA_INC_PATH \n";
require ($AA_INC_PATH . "en_common_lang.php3");  # English
# require ($AA_INC_PATH . "cz_common_lang.php3");  # Czech

define("DEFAULT_LANG_INCLUDE", "en_news_lang.php3");

# index names must be less than 20 characters (as is en_news)
$ActionAppConfig[en_news][name] = "News";
$ActionAppConfig[en_news][file] = "en_news_lang.php3";
# $ActionAppConfig[news][file] = "cz_language.php3";    # Czech language news

$ActionAppConfig[en_action_alerts][name] = "Action alerts";
$ActionAppConfig[en_action_alerts][file] = "en_news_lang.php3";

$ActionAppConfig[en_events][name] = "Events listings";
$ActionAppConfig[en_events][file] = "en_news_lang.php3";

$ActionAppConfig[en_press_rel][name] = "Press release publisher";
$ActionAppConfig[en_press_rel][file] = "en_pr_lang.php3";

$ActionAppConfig[en_jobs][name] = "Job listing";
$ActionAppConfig[en_jobs][file] = "en_news_lang.php3";

$ActionAppConfig[en_addresses][name] = "Address list";
$ActionAppConfig[en_addresses][file] = "en_news_lang.php3";

$ActionAppConfig[en_media_mon][name] = "Media monitoring";
$ActionAppConfig[en_media_mon][file] = "en_news_lang.php3";

/*
$Log$
Revision 1.1  2000/07/11 09:31:54  kzajicek
Renamed config.inc to config.php3

Revision 1.3  2000/07/07 21:41:42  honzam
SCROLLER_LENGTH missing bug fixed

Revision 1.2  2000/07/05 14:41:30  kzajicek
Replaced "require" by "include" (include does not work inside if - then) and
changed the test to use "defined".

Revision 1.1.1.1  2000/06/21 18:40:23  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:12  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.9  2000/06/12 21:41:24  madebeer
removed whitespace from config-ecn.inc
added $Id $Log and $Copyright to some stray files

Revision 1.8  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
