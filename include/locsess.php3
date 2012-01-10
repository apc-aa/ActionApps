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
// set timezone - just for date() speedup
// date_default_timezone_set(date_default_timezone_get());

/* Change this to match your database. */
$db_type_filename = (defined("DB_TYPE") ? DB_TYPE .".inc" : "db_mysql.inc");
require_once(AA_INC_PATH.'phplib/'. $db_type_filename);
require_once(AA_INC_PATH.'phplib/phplib.php');

function __autoload ($class_name) {
    $PAIRS = array(
        'AA_Perm'            => 'include/perm_core.php3',
        'AA_Permsystem_Sql'  => 'include/perm_sql.php3',
        'AA_Permsystem_Ldap' => 'include/perm_ldap.php3',
        'AA_Array'           => 'include/table.class.php3',
        'ConvertCharset'     => 'include/convert_charset.class.php3',
        'AA_Slices'          => 'include/slice.class.php3',
        'AA_Items'           => 'include/item.php3',
        'PhpQuickProfiler'   => 'misc/pqp/classes/PhpQuickProfiler.php',
        'Console'            => 'misc/pqp/classes/Console.php',
        'AA_Form_Array'      => 'include/widget.class.php3',
        'AA_Mysqlauth'       => 'include/auth.php3',
        'AA_Mailman'         => 'include/mailman.php3'
        );

    if ($PAIRS[$class_name]) {
#echo AA_BASE_PATH. $PAIRS[$class_name];
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
        case 'debug':
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

    function group()    {
        $v     = func_get_args();
        $group = array_shift($v);
        $this->_starttime[$group] = microtime(true);
        $this->_groupstart($group);
        $this->_do('log', $v);
    }

    function groupend() {
        $v     = func_get_args();
        $group = array_shift($v);
        $this->_do('log', $v);
        $this->_logtime($group);
        $this->_groupend($group);
    }

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

    function _groupstart($group) {
        echo "\n<div style='border: 1px #AAA solid; margin: 6px 1px 6px 12px'>";
        $this->_do('log', array($group));
    }

    function _groupend($group) {
        echo "\n</div>";
    }

    function _logtime($group) {
        $time = microtime(true) - $this->_starttime[$group];
        $this->_do(($time > 1.0) ? 'warn' : 'log', array("$group time: $time"));
    }
}

class AA {
    public static $dbg;
    public static $debug;
    public static $perm;
}
AA::$debug = $_GET['debug'];
AA::$dbg   = (AA::$debug[0] == 'f') ? new AA_Debug_Firephp() : ((AA::$debug[0] == 'c') ? new AA_Debug_PhpConsole() : new AA_Debug());

class DB_AA extends DB_Sql {
    var $Host      = DB_HOST;
    var $Database  = DB_NAME;
    var $User      = DB_USER;
    var $Password  = DB_PASSWORD;
    var $Auto_Free = 'yes';

    public static $queries = array();

    /** allways open, reusable database connection for one time queries */
    private static $_db    = null;

    /** static
     *  used as: $chid = DB_AA::select1("SELECT id FROM `change` WHERE ...", 'id');
     **/
    function select1($query, $column=false, $where=null) {
        $db = is_null(DB_AA::$_db) ? (DB_AA::$_db = new DB_AA) : DB_AA::$_db;
        $sqlwhere = is_null($where) ? '' : DB_AA::makeWhere($where);
        $db->query("$query $sqlwhere LIMIT 1");
        if (!$db->next_record()) {
            return false;
        }
        if (!is_array($column)) {
            return empty($column) ? $db->Record : $db->Record[$column];
        }
        $key = key($column);
        $val = empty($column) ? $db->Record : array_intersect_key($db->Record, array_flip($column));
        return is_numeric($key) ? $val : array($db->Record[$key] => $val);
    }

    /** static
     *  used as: $chid = DB_AA::select1('id', "SELECT id FROM `change` WHERE ...");
     **/
    function select($column, $query, $where=null) {
        $db = is_null(DB_AA::$_db) ? (DB_AA::$_db = new DB_AA) : DB_AA::$_db;
        $sqlwhere = is_null($where) ? '' : DB_AA::makeWhere($where);

        huhl("$query $sqlwhere");
        $db->query("$query $sqlwhere");
        $ret = array();
        if (is_array($column)) {
            $key      = key($column);
            $col_keys = array_flip($column);
        }
        while ($db->next_record()) {
            if (!is_array($column)) {
                $ret[] = empty($column) ? reset($db->Record) : $db->Record[$column];
            }
            $val = empty($column) ? $db->Record : array_intersect_key($db->Record, $col_keys);
            if (is_numeric($key)) {
                $ret[] = $val;
            } else {
                $ret[$db->Record[$key]] = $val;
            }
        }
        return $ret;
    }

    /** static
     *  used as: DB_AA::sql("INSERT SELECT id FROM `change` WHERE ...");
     **/
    function sql($query) {
        $db = is_null(DB_AA::$_db) ? (DB_AA::$_db = new DB_AA) : DB_AA::$_db;
        return $db->query($query);
    }


    /** makeWHERE function
     * @param $tablename
     */
    function makeWHERE($varlist) {
        $delim = '';
        $where = '';
        foreach ( $varlist as $vardef) {
            // $vardef is array(varname, type, value)
            list($name, $value, $type) = $vardef;
            $part = '';
            switch ( $type ) {
                case "i": $part = (int)$value; break;
                case "l": $part = q_pack_id($value); break;
                case "q": $part = $value; break;
                default:  $part = addslashes($value);
            }
            $where .= "$delim $name = '$part'";
            $delim = " AND";
        }
        return $where ? "WHERE $where" : '';
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
}

/* Required, contains your local session management extension */
require_once(AA_INC_PATH . ($encap ? "extsessi.php3" : "extsess.php3"));
?>
