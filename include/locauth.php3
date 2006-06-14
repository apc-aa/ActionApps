<?php
//$Id$
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
http://www.apc.org/

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program (LICENSE); if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if (!defined('PHPLIB_LIBDIR')) {
    define ('PHPLIB_LIBDIR', '');
}

if (! PHPLIB_ALREADY_LOADED && ! defined ("PHPLIB_AA_LOADED")) {
  /* Change this to match your database. */
  require_once(PHPLIB_LIBDIR. "db_mysql.inc");

  /* Change this to match your data storage container */
  require_once(PHPLIB_LIBDIR. "ct_sql.inc");

  /* Required for everything below.      */
  require_once(PHPLIB_LIBDIR. "session.inc");

  /* Disable this, if you are not using authentication. */
  require_once(PHPLIB_LIBDIR. "auth.inc");
}

/* Required, contains your local session management extension */
if ($encap) {
  require_once(AA_INC_PATH . "extsessi.php3");   // if encapsulated (itemedit.php3) we can't send more Header
 } else {
  require_once(AA_INC_PATH . "extsess.php3");
}

/* Required, contains your local authentication extension. */
if ($nobody) {  // used in itemedit.php3 for anonymoous news posting
  require_once(AA_INC_PATH . "extauthnobody.php3");
 } else {
  require_once(AA_INC_PATH . "extauth.php3");
}

/* Required, contains the page management functions. */
if (! PHPLIB_ALREADY_LOADED && ! defined ("PHPLIB_AA_LOADED")) {
   require_once(PHPLIB_LIBDIR. "page.inc");
}

define ("PHPLIB_AA_LOADED", 1);
?>