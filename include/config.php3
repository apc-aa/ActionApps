<?php
# $Id$
 
# Application wide configuration options

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
# $AA_INC_PATH = "/home/httpd/html/apc-aa/include/";

# URL of aa instalation (where are include, admin, images etc. subdirectories)
# (there must be the slash at the end of string)
define("AA_INSTAL_URL", "http://my.domain.org/apc-aa/");

# URL of index of help files for AA
#OLD define("DOCUMENTATION_URL", "http://aa.ecn.cz/aaa/doc/index.html");
define("DOCUMENTATION_URL", "http://apc-aa.sourceforge.net/apc-aa/doc");

# Your Internet Domain
define("DEFAULT_ORG_ID", "my.domain.org");

# DB Access Configuration
define("DB_HOST", "my.domain.org");
#define("DB_HOST", "localhost");
define("DB_NAME", "aadb");
define("DB_USER", "aadbuser");
define("DB_PASSWORD", "test.pw");

# ID of AA (any unique 32chars long hexadecimal number)
# Please change this value to be unique
define("ORG_NAME","An APC Member");
define("AA_ID", "000111222333444555666777888999A9");

# Select permissions system (exactly one of "dummy", "ldap", "sql")
define("PERM_LIB", "sql");

# LDAP Configuration
define("LDAP_HOST", "localhost");
define("LDAP_BINDDN", "cn=aauser,ou=AA");
define("LDAP_BINDPW", "somepasswd");  // password
define("LDAP_BASEDN", "ou=AA");
define("LDAP_PEOPLE", "ou=People,ou=AA");
define("LDAP_GROUPS", "ou=AA");
define("LDAP_ACLS", "ou=ACLs,ou=AA");
define("LDAP_PORT", 389);            // standard LDAP port: 389

# e-mail for bug reporting contact
define("ERROR_REPORTING_EMAIL", "webmaster@my.domain.org");

# set this directive to true, if you use MySQL 
# (uses LIMIT clause in SELECTs)
define("OPTIMIZE_FOR_MYSQL", true);

# set this directive to 1 if you want to data-entry forms to have only
# one column
define("SINGLE_COLUMN_FORM", "0");

# set this directive to true, if your php already auto-includes phplib
# if it is 'false' and it should be true you'll get an error like:
# Fatal error: DB_Sql is already a function or class in
#/var/php/phplib/php/db_mysql.inc on line 12

define("PHPLIB_ALREADY_LOADED", false);

# number of shown pages links in scroller's navigation bar
define("SCROLLER_LENGTH", 3);

# Since v1.8 you can use short id for item identification (x instead of sh_itm)
$USE_SHORT_URL = true;

# Would you like to display debug messagess?
define("DEBUG_FLAG", true);
                                                        
# pages with items are cached - the caching system is quite smart - it caches
# only unchanged pages. However, You can switch caching off.
define( "ENABLE_PAGE_CACHE", true );

# CACHE_TTL defines the time in seconds the page will be stored in cache
# (Time To Live) - in fact it can be infinity because of automatic cache 
# flushing on page change
define("CACHE_TTL", 600 );

# The frequency in which the cache is checked for old values (in seconds)
define("CACHE_PURGE_FREQ", 1000);

# If true, the expired items could be displayed by in specific query (good 
# for archive display). If false, expired items are never shown 
define("ALLOW_DISPLAY_EXPIRED_ITEMS", true);

# If you use Web.net's extended items table, uncomment this definition
define("EXTENDED_ITEM_TABLE", "1");

if (!isset($AA_INC_PATH) || $AA_INC_PATH == ""){
  echo "you must set AA_INC_PATH and other variables in config.php3 !";
};

# Maximum number of items, which can be related to some item
define( "MAX_RELATED_COUNT", 50 );

define("DEFAULT_LANG_INCLUDE", "en_news_lang.php3");

# settings for file uploads
define("IMG_UPLOAD_MAX_SIZE", "400000");    // max size of file in picture uploading
define("IMG_UPLOAD_URL", "http://aa.ecn.cz/img_upload/");
define("IMG_UPLOAD_PATH", "/usr/local/httpd/htdocs/img_upload/");
define("IMG_UPLOAD_TYPE", "image/*");
define("IMG_UPLOAD_DIR_MODE", 508);    # mkdir perms (508 = 0774 in octal, but
                                       # octal value in constant don't work)

# Select color profile for administation pages

  # -- WebNetworks profile
    define("COLOR_TABBG",     "#A8C8B0");          # background of tables
    define("COLOR_TABTITBG",  "#589868");          # background of table titles
    define("COLOR_BACKGROUND","#F5F0E7");          # admin pages background

      # You can redefine the colors in styles too
    define("ADMIN_CSS","admin.css");               # style for admin interface
    define("ADM_SLICE_CSS","adm_slice.css");       # style for public view of 
                                                   # not encapsulated slices

  # -- IGC profile --- 
  /*
    define("COLOR_TABBG",   "#A8C8B0");            # background of tables
    define("COLOR_TABTITBG","#589868");            # background of table titles
    define("COLOR_BACKGROUND","#F5F0E7");          # admin pages background
      # You can redefine the colors in styles too
    define("ADMIN_CSS","admin-igc.css");           # style for admin interface
    define("ADM_SLICE_CSS","adm_slice-igc.css");   # style for public view of 
                                                   # not encapsulated slices
  */

  # -- Comlink profile --- 
  /*
    define("COLOR_TABBG",     "#A8C8B0");          # background of tables
    define("COLOR_TABTITBG",  "#589868");          # background of table titles
    define("COLOR_BACKGROUND","#F5F0E7");          # admin pages background

      # You can redefine the colors in styles too
    define("ADMIN_CSS","admin-cml.css");               # style for admin interface
    define("ADM_SLICE_CSS","adm_slice-cml.css");       # style for public view of 
                                                   # not encapsulated slices
  */

  # -- Econnects profile --- 
  /*
    define("COLOR_TABBG",   "#EBDABE");            # background of tables
    define("COLOR_TABTITBG","#584011");            # background of table titles
    define("COLOR_BACKGROUND","#F5F0E7");          # admin pages background
      # You can redefine the colors in styles too
    define("ADMIN_CSS","admin-ecn.css");           # style for admin interface
    define("ADM_SLICE_CSS","adm_slice.css");       # style for public view of 
                                                   # not encapsulated slices
  */

# Language: uncomment one language  file
require ($GLOBALS[AA_INC_PATH] . "en_common_lang.php3");  # English
# require ($GLOBALS[AA_INC_PATH] . "cz_common_lang.php3");  # Czech
# require ($GLOBALS[AA_INC_PATH] . "es_common_lang.php3");  # Spanish
# require ($GLOBALS[AA_INC_PATH] . "sk_common_lang.php3");  # Slovak
# require ($GLOBALS[AA_INC_PATH] . "de_common_lang.php3");  # Deutsch

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

// Switches here are based on SERVER_ADDR so that all virtual hosts
// can be configured in one place
switch ($SERVER_ADDR) {
  case "209.220.30.175";
  case "209.220.30.171";
     #$AA_INC_PATH = "/home/httpd/html/apc-aa/include/";
     define (SITE_CONFIG, "config-cyborganic.inc"); break;
  }
/*
*/

if (defined ("SITE_CONFIG")) {
  // require does not work as expected inside control structures!
  include ($AA_INC_PATH . SITE_CONFIG);
}
// end SITE_CONFIG
// ------------------------------------------------------------------

?>
