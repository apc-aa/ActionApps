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
https://www.apc.org/

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
use AA\IO\DB\DB_AA;

require_once __DIR__."/../include/config.php3";
require_once __DIR__."/../include/locsess.php3";   // DB_AA definition
require_once __DIR__."/../include/util.php3";

set_time_limit(160);

if ( substr( DB_PASSWORD, 0, 5 ) != $_GET['key'] ) {
    exit;                 // We need first five characters of database password
}                         // Noone then can run the script icidentaly (or with
                          // bad thoughts)

$template_metabase = AA::Metabase();
$template_tables = $template_metabase->getTableNames();

$this_metabase = new AA_Metabase;
$this_metabase->loadFromDb();
$this_tables = $this_metabase->getTableNames();

// stert with different table each time to minimize impact of corrupted/bigb/... tables
shuffle($template_tables);
    
// optimize only current AA tables (not bck_* tables ...    )
foreach ( $template_tables as $table ) {
    if ( in_array($table, $this_tables) ) {
        DB_AA::sql("OPTIMIZE TABLE `$table`");
        echo "<br> $table optimized";
    } else {
        echo "<br> $table not exist";
    }
}





