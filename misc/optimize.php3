<?php
/**
 * Database maintainance script. It optimizes database tables.
 * This script could be called from cron.php3 - see AA -> Cron and set there
 * someting like:
 *                  32  2  *  *  2    misc/optimize.php3   key=passw
 *
 * The script must be called with key=passw parameter, where passw is first five
 * chracters of database password (see DB_PASSWORD variable in config.php3).
 * This is security check - noone then can run the script icidentaly (or with
 * bad thoughts). The setting above runs the script each Monday 2:38 AM
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
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

// need config.php3 to set db access, and phplib, and probably other stuff
define ('AA_INC_PATH', "../include/");

require_once AA_INC_PATH."config.php3";
require_once AA_INC_PATH."locsess.php3";   // DB_AA definition
require_once AA_INC_PATH."util.php3";

set_time_limit(160);

if ( substr( DB_PASSWORD, 0, 5 ) != $key ) {
    exit;                 // We need first five characters of database password
}                         // Noone then can run the script icidentaly (or with
                          // bad thoughts)
// init used objects
is_object( $db ) || ($db = getDB());
$err["Init"] = "";          // error array (Init - just for initializing variable

// optimize slice tables ------------------------------------------------------
$db->query("OPTIMIZE TABLE module");
$db->query("OPTIMIZE TABLE slice");
$db->query("OPTIMIZE TABLE field");
$db->query("OPTIMIZE TABLE content");
$db->query("OPTIMIZE TABLE offline");
$db->query("OPTIMIZE TABLE item");
$db->query("OPTIMIZE TABLE feedmap");
$db->query("OPTIMIZE TABLE feedperms");
$db->query("OPTIMIZE TABLE email_notify");
$db->query("OPTIMIZE TABLE relation");

// optimize tables for polls module -------------------------------------------
$db->query("OPTIMIZE TABLE polls");
$db->query("OPTIMIZE TABLE polls_ip_lock");
$db->query("OPTIMIZE TABLE polls_data");
$db->query("OPTIMIZE TABLE polls_log");
$db->query("OPTIMIZE TABLE polls_designs");

// optimize tables for site module --------------------------------------------
$db->query("OPTIMIZE TABLE site");
$db->query("OPTIMIZE TABLE site_spot");

?>
