<?php
/*
 * Session Management for PHP3
 *
 * Copyright (c) 1998-2000 NetUSE AG
 *                    Boris Erdmann, Kristian Koehntopp
 *
 * $Id$
 *
 */ 

if (!isset($_PHPLIB) or !is_array($_PHPLIB)) {
// Aren't we nice? We are prepending this everywhere 
// we require_once or include something so you can fake
// include_path  when hosted at provider that sucks.
  $_PHPLIB["libdir"] = ""; 
}

require_once($_PHPLIB["libdir"] . "db_mysql.inc");  /* Change this to match your database. */
require_once($_PHPLIB["libdir"] . "ct_sql.inc");    /* Change this to match your data storage container */
require_once($_PHPLIB["libdir"] . "session.inc");   /* Required for everything below.      */
require_once($_PHPLIB["libdir"] . "auth.inc");      /* Disable this, if you are not using authentication. */
require_once($_PHPLIB["libdir"] . "perm.inc");      /* Disable this, if you are not using permission checks. */
require_once($_PHPLIB["libdir"] . "user.inc");      /* Disable this, if you are not using per-user variables. */

/* Additional require_once statements go below this line */
// require_once($_PHPLIB["libdir"] . "menu.inc");      /* Enable to use Menu */

/* Additional require_once statements go before this line */

require_once($_PHPLIB["libdir"] . "local.inc");     /* Required, contains your local configuration. */

require_once($_PHPLIB["libdir"] . "page.inc");      /* Required, contains the page management functions. */

?>
