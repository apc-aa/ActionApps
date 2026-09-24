<?php

//$Id$
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
// script for MySQL database update

// this script updates the database to last structure, create all tables, ...
// can be used for upgrade from apc-aa v. >= 1.5 or for create new database

use AA\Optimize\AbstractOptimize;
use AA\Optimize\Chain;
use AA\Optimize\RestoreBckTables;
use AA\Util\ReportTrait;

/** FILL SOME ACCESS CODE HERE !!!
 *  example:
 *     define('ACCESS_CODE','de46272*_!%@*dhje-rty362ddj');
 *  (but use your own code)
 *  Then fill the same code to the field Access Code field on the page to access
 *  this script. The code must be 10 character long at least.
 *
 *  It is recommended to delete the access code after you finish your setup, to
 *  disable access code guessing from attackers.
 */
const ACCESS_CODE = '';

/** INSTRUCTIONS BEFORE USE
 *  This script is written to be not destructive. It updates the database to
 *  the latest structure, create all tables, ...
 *  It can be used for upgrade from previous versions of apc-aa or for creating
 *  new database.
 *  It creates temporary tables first, then copies data from old tables to the
 *  temporary ones (tmp_*) and after successful copy it drops old tables and
 *  renames temporary ones to right names. Then it possibly updates common
 *  records (like default field definitions, module templates, constants and
 *  templates).</p>
 *
 *  However, it is strongly recommended backup your current database !!!
 *  Use something like:
 *     mysqldump --lock-tables -h DB_HOST -u DB_USER -p --opt DB_NAME > ./aadbbackup.sql
 *  (replace DB_HOST, DB_USER and B_NAME with values from your config.php file)
 */


ini_set('display_errors', 'On');
error_reporting(E_ERROR | E_WARNING | E_PARSE);

// sleep one second to make any brute force attacks harder
sleep(1);

$silent = $_GET['silent'] ?? false;

if (!$silent) {
    echo '
      <!DOCTYPE html>
      <html>
      <head>
        <title>APC-AA database update script</title>
      </head>
      <body>
      <h1>ActionApps database update</h1>
      <p>This script is written to be not destructive. It creates temporary tables
         first, then copies data from old tables to the temporary ones (tmp_*) and
         after successful copy it drops old tables and renames temporary ones to
         right names. Then it possibly updates common records (like default field
         definitions, module templates, constants and templates).</p>
      <p style="color:red;">However, it is strongly recommended backup your current
      database !!!</p>
      <p>See instructions directly in this file (sql_update.php) on the server.</p>';
}

if (!constant('ACCESS_CODE') || strlen(constant('ACCESS_CODE')) < 10) {
    echo '<p>For update or restore you need to edit this script on the server
          (sql_update.php) and fill there some Access Code which you then fill
          also here (it is for security reasons).</p>
          <p>Just refresh this page after you fill the Access code to the file.</p>
          </body>
          </html>';
    exit;
}

// need config.php to set db access, and phplib, and probably other stuff
require_once __DIR__ . "/../include/config.php";
require_once __DIR__ . "/../include/locsess.php";   // DB_AA definition
require_once __DIR__ . "/../include/util.php";
require_once __DIR__ . "/../include/zids.php";   // qquote, is_long_id, ...
require_once __DIR__ . "/../include/formutil.php";   // GetHtmlTable
// do not reorder those requires because of metabase and varset dependency
require_once __DIR__ . "/../include/metabase.class.php";
require_once __DIR__ . "/../include/varset.php";


if (!$_GET['silent']) {
    echo '<form name="f" action="' . $_SERVER['PHP_SELF'] . '">
            Access Code
            <input type="password" name="acccode" value="' . safe($_GET['acccode']) . '"><br>

            Write to database <input type="checkbox" name="fire" value="1"' . ($_GET['fire'] ? ' checked' : '') . '><br>
            <small>Check this for real work with writing to database</small>
            <br><br>

            <input type="submit" name="dotest" value="Test">
            <input type="submit" name="update" value="Install / Update">
            <input type="submit" name="restore" value="Restore">
          </form>';
}

if (strlen($_GET['acccode']) < 10 || $_GET['acccode'] != constant('ACCESS_CODE')) {
    echo '</body></html>';
    exit;
}

//require_once __DIR__."/update.optimize.class.php";

class AA_SQL_Updater
{
    use ReportTrait;

    public function test(): bool
    {
        [$result, $msg] = Chain::test($this->getOptimizers());
        $this->message($msg);
        return $result;
    }

    public function restore(): bool
    {
        $optimizer = new RestoreBckTables();
        $optimizer->repair();
        $this->message($optimizer->report());
        return true;
    }

    public function update(): bool
    {
        [$result, $msg] = Chain::repair($this->getOptimizers());
        $this->message($msg);
        return $result;
    }

    /** getOptimizers function
     *  Return instances of all sql_update optimizers, ordered by priority
     */
    public function getOptimizers(): array
    {
        return Chain::optimizers('sql_update');
    }
}

$updater = new AA_SQL_Updater();

if ($_GET['update']) {
    if (!$_GET['fire']) {
        AbstractOptimize::justPrint(true);
    }
    // the same lock as Central's Do_Optimize_Type - two updates at once would rebuild the same tables
    if (!($release = Chain::tryLock('sql_update', $holder))) {
        echo 'Err ' . Chain::runningMessage('sql_update', $holder);
    } else {
        try {
            $status = $updater->update();
        } finally {
            $release();
        }
        echo ($status ? 'OK ' : 'Err ') . $updater->report();
    }
} elseif ($_GET['restore']) {
    if (!$_GET['fire']) {
        AbstractOptimize::justPrint(true);
    }
    if (!($release = Chain::tryLock('sql_update', $holder))) {   // restores the tables an update rebuilds
        echo 'Err ' . Chain::runningMessage('sql_update', $holder);
    } else {
        try {
            $status = $updater->restore();
        } finally {
            $release();
        }
        echo ($status ? 'OK ' : 'Err ') . $updater->report();
    }
} elseif ($_GET['dotest']) {
    $status = $updater->test();
    echo ($status ? 'OK ' : 'Err ') . $updater->report();
}

if (!$_GET['silent']) {
    echo '</body></html>';
}
