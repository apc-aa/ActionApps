<?php
# $Id$

# Application wide configuration options

# THIS FILE SHOULD NOT BE WORLD READABLE, BUT IT NEEDS TO BE READABLE BY
# YOUR WWW SERVER

# This is the Action Application main configuration file. In fact, this file
# is a php script which is included into every php program, thus, php
# syntax is used. This basically means that this file defines constants
# in the form
#    $name = "value";
# or in the form
#    define("name", "value);

# AA_INC_PATH defines the AA include directory. It must be an absolute
# path. Normally this is the path to the directory where this file
# (config.php3) is in. Remove the leading # sign in front of the line
# and fill in the correct value between the quotes. Make sure to terminate
# this path with a slash!
# Example:
# $AA_INC_PATH = "/home/httpd/html/aa/include/";
$AA_INC_PATH = ""; 

# Fill in your internet domain name here.
define("DEFAULT_ORG_ID", "ecn.cz");

# This is for accessing the database. You need to define the name of the host
# where the database server runs, the database name, a user name for logging
# in and a passowrd in clear text for the login.
define("DB_HOST", "localhost");
define("DB_NAME", "aadb");
define("DB_USER", "aadbuser");
define("DB_PASSWORD", "");

# ID of AA (any unique 32chars long hexadecimal number)
# Please change this value to be unique
define("AA_ID", "420224311780abcd420224311780abcd");

# Select permissions system (exactly one of "dummy", "ldap", "sql")
define("PERM_LIB", "sql");

# The following settings are needed for LDAP based permission system only.
define("LDAP_HOST", "ecn4.ecn.cz");
define("LDAP_BINDDN", "cn=aa,dc=ecn,dc=apc,dc=org");
define("LDAP_BINDPW", "");                           // password
define("LDAP_BASEDN", "dc=ecn,dc=apc,dc=org");
define("LDAP_PEOPLE", "dc=ecn,dc=apc,dc=org");
define("LDAP_GROUPS", "dc=ecn,dc=apc,dc=org");
define("LDAP_ACLS", "dc=ecn,dc=apc,dc=org");

# e-mail for bug reporting contact
define("ERROR_REPORTING_EMAIL", "root");

# set this directive to true, if you use MySQL 
# (uses LIMIT clause in SELECTs)
define("OPTIMIZE_FOR_MYSQL", false);

# set this directive to true, if your php already auto-includes phplib
# if it is 'false' and it should be true you'll get an error like:
# Fatal error: DB_Sql is already a function or class in
#/var/php/phplib/php/db_mysql.inc on line 12
define("PHPLIB_ALREADY_LOADED", false);

# The style sheet for administrative pages. Leave it as it is for now,
# you can later customize it if you want.
define("ADMIN_CSS","admin.css");
# The style sheet for slice preview.
define("ADM_SLICE_CSS","adm_slice.css");

# number of shown pages links in scroller's navigation bar
define("SCROLLER_LENGTH", 7);

# Would you like to display debug messagess?
define("DEBUG_FLAG", true);

# If you use Web.net's extended items table, uncomment this definition

# define("EXTENDED_ITEM_TABLE", "1");

if (!isset($AA_INC_PATH) || $AA_INC_PATH == ""){
  echo "you must set AA_INC_PATH and other variables in config.php3 !";
};

# Language: uncomment one language file
require ($AA_INC_PATH . "en_common_lang.php3");  # English
# require ($AA_INC_PATH . "cz_common_lang.php3");  # Czech

# The folloging items have to be changed or added if you create new
# action applications. For a first time installation, you don't need
# to care about them.

define("DEFAULT_LANG_INCLUDE", "en_news_lang.php3");

# index names must be less than 20 characters (as is en_news)
# "en_news" is slice type (each slice has its type stored in database)
#    we can say, type is the same as application
# "News (En)" is name of this slice type (application)
# "en_news_lang.php3" is the name of use language file for this slice type

$ActionAppConfig[en_news][name] = "News (En)";
$ActionAppConfig[en_news][file] = "en_news_lang.php3";

$ActionAppConfig[cz_news][name] = "News (Cz)";
$ActionAppConfig[cz_news][file] = "cz_news_lang.php3";

$ActionAppConfig[cz_media][name] = "Media monitoring (Cz)";
$ActionAppConfig[cz_media][file] = "cz_media_lang.php3";

$ActionAppConfig[en_all][name] = "Article publisher (En)";  // maximalistic version of inpu form
$ActionAppConfig[en_all][file] = "en_all_lang.php3";

$ActionAppConfig[en_press_rel][name] = "Press release publisher (En)";
$ActionAppConfig[en_press_rel][file] = "en_press_lang.php3";

$ActionAppConfig[en_jobs_rel][name] = "Jobs listing (En)";
$ActionAppConfig[en_jobs_rel][file] = "en_jobs_lang.php3";

$ActionAppConfig[en_events_rel][name] = "Events (En)";
$ActionAppConfig[en_events_rel][file] = "en_events_lang.php3";

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

?>
