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

// set timezone - just for date() speedup
// date_default_timezone_set(date_default_timezone_get());

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

function __autoload ($class_name) {
    $PAIRS = array(
        'AA_Array'         => 'include/table.class.php3',
        'ConvertCharset'   => 'include/convert_charset.class.php3',
        'AA_Slices'        => 'include/slice.class.php3',
        'AA_Items'         => 'include/item.php3',
        'PhpQuickProfiler' => 'misc/pqp/classes/PhpQuickProfiler.php',
        'Console'          => 'misc/pqp/classes/Console.php',
        'AA_Form_Array'    => 'include/widget.class.php3'
        );

    if ($PAIRS[$class_name]) {
        require AA_BASE_PATH. $PAIRS[$class_name];
        return;
    }

    $matches = array();
    preg_match('/^aa_([a-z0-9]+)/', strtolower($class_name), $matches);

    // the core name of the class (like "widget" for "AA_Widget_Fld", ...)
    $core = $matches[1];

    switch ($core) {
    case 'form':
    case 'table':
    //  case 'widget':
    //  case 'field':
        require AA_INC_PATH. $core. '.class.php3';
        return;
    case 'objectgrabber':
        require AA_INC_PATH. 'grabber.class.php3';
        return;
    case 'validate':
        require AA_INC_PATH. 'validate.php3';
        return;
    }

    $CUSTOM_INC_FILES = array(
        'AA_Stringexpand' => 'stringexpand.php',
        'AA_Responder'    => 'responder.php'
        );

    if (defined('AA_CUSTOM_DIR')) {
        foreach ($CUSTOM_INC_FILES as $inc_def => $inc_file) {
            if (strpos($class_name, $inc_def) === 0 ) {
                include_once(AA_INC_PATH. 'custom/'. AA_CUSTOM_DIR. '/'. $inc_file);
            }
        }
    }
}

if ($_GET['pqp']) {
   $profiler = new PhpQuickProfiler(microtime(true));
}

class AA_Debug {
    protected $_starttime;

    function __construct() {
        $this->_starttime = array('main' => microtime(true));
    }

    function log()      {$v=func_get_args(); $this->_do('log',     $v);}
    function info()     {$v=func_get_args(); $this->_do('info',    $v);}
    function warn()     {$v=func_get_args(); $this->_do('warn',    $v);}
    function error()    {$v=func_get_args(); $this->_do('error',   $v);}
    function group()    {$v=func_get_args(); $this->_do('group',   $v);}
    function groupend() {$v=func_get_args(); $this->_do('groupend',$v);}

    function _do($func, $params) {
        foreach ($params as $a) {
            if (is_object($a) && is_callable(array($a,"printobj"))) {
                $a->printobj();
            } else {
                print_r($a);
            }
            echo "<br>\n";
        }
    }
    function _logtime($group) {}
}

class AA_Degug_Firephp extends AA_Debug {
    private $_console;

    function __construct() {
        define('INSIGHT_IPS', '*');
        define('INSIGHT_AUTHKEYS', '*');
        define('INSIGHT_PATHS', dirname(__FILE__));
        define('INSIGHT_SERVER_PATH', '/aaa/test.php3');
        require_once(AA_BASE_PATH. 'misc/firephp/lib/FirePHP/Init.php');
        $inspector = FirePHP::to('page');
        $this->_console = $inspector->console();
        $this->_console->log('ActionApps - console initiated');
        parent::__construct();
    }

    function group()    {
        $v=func_get_args();
        $group = reset($v);
        $this->_console->group($group)->open();
        $this->_console->log($group);
        $this->_starttime[$group] = microtime(true);
    }

    function groupend() {
        $v=func_get_args();
        $group = reset($v);
        $this->_console->group($group)->close();
        $this->_logtime($group);
    }

    function _do($func, $params) {
        $this->_console->log(microtime(true) - $this->_starttime['main']);
        foreach ($params as $var) {
           call_user_func_array(array($this->_console, $func), array($var));
        }
    }

    function _logtime($group) {
        $time = microtime(true) - $this->_starttime[$group];
        $msg  = "$group time: $time";
        if ($time > 1.0) {
            $this->_console->warn($msg);
        } else {
            $this->_console->log($msg);
        }
    }
}

class AA {
    public static $dbg;
    public static $debug;
}
AA::$dbg   = (strpos($_GET['debug'],'f')!==false) ? new AA_Degug_Firephp() : new AA_Debug();
AA::$debug = $_GET['debug'];

class DB_AA extends DB_Sql {
    var $Host      = DB_HOST;
    var $Database  = DB_NAME;
    var $User      = DB_USER;
    var $Password  = DB_PASSWORD;
    var $Auto_Free = 'yes';

    public static $queries = array();

    /** allways open reusablr database connection for one time queries */
    private static $_db    = null;

    function select1($column, $query) {
        $db = is_null(DB_AA::$_db) ? (DB_AA::$_db = new DB_AA) : DB_AA::$_db;
        $db->query("$query LIMIT 1");
        return $db->next_record() ? $db->Record[$column] : false;
    }

    /** query function
     * @param $SQL
     */
    function query($SQL) {
        return ($GLOBALS['pqp'] ? $this->dquery($SQL) : parent::query($SQL));
    }

    /** tquery function
     * @param $SQL
     */
    function tquery($SQL) {
        return ($GLOBALS['pqp'] ? $this->dquery($SQL) : parent::query($SQL));
    }
    /** dquery function
     * @param $SQL
     */
    function dquery($SQL) {
        $type      = (stripos($SQL, "SELECT") === 0) ? 'S' : 'U';
        $starttime = microtime(true);

        $retval    = parent::query($SQL);

        Console::log("$SQL<br> $type-". $this->num_rows());

        // log it
        self::$queries[] = array(
                'sql'  => $SQL,
                'time' => (microtime(true) - $starttime)*1000,
                'type' => $type,
                'rows' => ($type == 'S') ? $this->num_rows() : $this->affected_rows()
            );

        return $retval;
    }

    /** query_nohalt function
     * @param $SQL
     */
    function query_nohalt($SQL) {
        $store_halt          = $this->Halt_On_Error;
        $this->Halt_On_Error = 'no';
        $retval              = $this->query($SQL);
        $this->Halt_On_Error = $store_halt;
        return $retval;
    }

    /** halt function
     * @param $msg
     */
    function halt($msg) {
        if ($this->Halt_On_Error == "no") {
            return;
        }

        // if you want to display special error page, then define DB_ERROR_PAGE
        // in config.php3 file. You can use following variables on that page
        // (in case you will use php page):
        // $_POST['Err'], $_POST['ErrMsg'] and $_POST['Msg'] variables
        // --- Disabled -- AA_Http::go() for POST works in the way, that the
        // page content is grabbed into variable and printed on current page.
        // It works pretty well, but if you link the external css on that page,
        // then it is not found, which is unexpected behavior. So, you can't use
        // the variables on that page. Honza, 2007-12-05
        if (defined('DB_ERROR_PAGE') AND ($this->Halt_On_Error == "yes")) {
            ob_end_clean();
            // AA_Http::go(DB_ERROR_PAGE, array('Err'=>$this->Errno, 'ErrMsg'=>$this->Error, 'Msg'=>$msg), 'POST', false);
            // sending variables disabled - see the comment above
            AA_Http::go(DB_ERROR_PAGE, null, 'GET', false);
            exit;
        }

        // If you do not want (for security reasons) display messages like:
        // "Database error: mysql_pconnect(mysqldbserver, aadbuser, $Password) failed."
        // then just define DB_ERROR_PAGE constant in your config.php3 file
        echo "\n<br><b>Database error:</b> $msg";
        echo "\n<br><b>Error Number:</b>: ". $this->Errno;
        echo "\n<br><b>Error Description:</b>: ". $this->Error;
        echo "\n<br>Please contact ". ERROR_REPORTING_EMAIL ." and report the exact error message.<br>\n";
        if ($this->Halt_On_Error == "yes") {
            die("Session halted.");
        }
    }
}

class AA_CT_Sql extends CT_Sql {	         // Container Type for Session is SQL DB
    var $database_class = "DB_AA";           // Which database to connect...
    var $database_table = "active_sessions"; // and find our session data in this table.
}

/* Required, contains your local session management extension */
require_once(AA_INC_PATH . ($encap ? "extsessi.php3" : "extsess.php3"));

define ("PHPLIB_AA_LOADED", 1);
?>
