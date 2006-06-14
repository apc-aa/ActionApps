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

// Aren't we nice? We are prepending this everywhere
// we require_once or include something so you can fake
// include_path  when hosted at provider that sucks.
if (!defined('PHPLIB_LIBDIR')) {
    define ('PHPLIB_LIBDIR', '');
}

require_once(PHPLIB_LIBDIR. "db_mysql.inc");  /* Change this to match your database. */
require_once(PHPLIB_LIBDIR. "ct_sql.inc");    /* Change this to match your data storage container */
require_once(PHPLIB_LIBDIR. "session.inc");   /* Required for everything below.      */
require_once(PHPLIB_LIBDIR. "auth.inc");      /* Disable this, if you are not using authentication. */
require_once(PHPLIB_LIBDIR. "perm.inc");      /* Disable this, if you are not using permission checks. */
require_once(PHPLIB_LIBDIR. "user.inc");      /* Disable this, if you are not using per-user variables. */

/* Additional require_once statements go below this line */
// require_once(PHPLIB_LIBDIR . "menu.inc");      /* Enable to use Menu */

/* Additional require_once statements go before this line */

require_once(PHPLIB_LIBDIR. "local.inc");     /* Required, contains your local configuration. */
require_once(PHPLIB_LIBDIR. "page.inc");      /* Required, contains the page management functions. */

?>
