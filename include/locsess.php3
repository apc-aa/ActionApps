<?php
/**
*
* PHP versions 4 and 5
*
* LICENSE: This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program (LICENSE); if not, write to the Free Software
* Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*
* @package   Include
* @version   $Id$
* @author    Jiri Hejsek, Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      http://www.apc.org/ APC
*
*/
if (!defined('PHPLIB_LIBDIR')) {
    define ('PHPLIB_LIBDIR', '');
}

if (! PHPLIB_ALREADY_LOADED && ! defined ("PHPLIB_AA_LOADED")) {
    /* Change this to match your database. */
    $db_type_filename = (defined("DB_TYPE") ? DB_TYPE .".inc" : "db_mysql.inc");
    require_once(PHPLIB_LIBDIR. $db_type_filename);

    /* Change this to match your data storage container */
    require_once(PHPLIB_LIBDIR. "ct_sql.inc");

    /* Required for everything below.      */
    require_once(PHPLIB_LIBDIR. "session.inc");

    /* Disable this, if you are not using authentication. */
    require_once(PHPLIB_LIBDIR. "auth.inc");

    /* Required, contains the page management functions. */
    require_once(PHPLIB_LIBDIR. "page.inc");
}

/* Required, contains your local session management extension */
require_once(AA_INC_PATH . ($encap ? "extsessi.php3" : "extsess.php3"));

define ("PHPLIB_AA_LOADED", 1);
?>
