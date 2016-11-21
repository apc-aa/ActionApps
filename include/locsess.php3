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
require_once(AA_INC_PATH.'phplib/'. (defined("DB_TYPE") ? DB_TYPE .".inc" : "db_mysql.inc"));
require_once(AA_INC_PATH.'phplib/phplib.php');

spl_autoload_register(function ($class_name) {
    $PAIRS = array(
        'AA_Perm'            => 'include/perm_core.php3',
        'AA_Permsystem_Sql'  => 'include/perm_sql.php3',
        'AA_Permsystem_Ldap' => 'include/perm_ldap.php3',
        'AA_Array'           => 'include/table.class.php3',
        'ConvertCharset'     => 'include/convert_charset.class.php3',
        'AA_Items'           => 'include/item.php3',
        'AA_Form_Array'      => 'include/widget.class.php3',
        'AA_Mysqlauth'       => 'include/auth.php3',
        'AA_Scroller'        => 'include/scroller.php3',
        'AA_Actionapps'      => 'central/include/actionapps.class.php',
        'AA_Mailman'         => 'include/mailman.php3'
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
        case 'debug':
        case 'transformation':
        case 'grabber':
        case 'difftext':
        //  case 'widget':
        //  case 'field':
            require_once AA_INC_PATH. $core. '.class.php3';
            return;
        case 'plannedtask':
            require_once AA_INC_PATH. 'task.class.php3';
            return;
        case 'objectgrabber':
            require_once AA_INC_PATH. 'grabber.class.php3';
            return;
        case 'validate':
            require_once AA_INC_PATH. 'validate.php3';
            return;
        case 'exportsetings':
            require_once AA_INC_PATH. 'exporter.class.php3';
            return;
      }

    $CUSTOM_INC_FILES = array(
        'AA_Responder'    => 'responder.php'
        );

    if (defined('AA_CUSTOM_DIR')) {
        foreach ($CUSTOM_INC_FILES as $inc_def => $inc_file) {
            if (strpos($class_name, $inc_def) === 0 ) {
                include_once(AA_INC_PATH. 'custom/'. AA_CUSTOM_DIR. '/'. $inc_file);
            }
        }
    }
});

class AA_Debug {
    protected $_starttime;
    protected $_duration = array();
    protected $_tracestack = array();
    protected $_calls      = array();

    function __construct() {
        $this->_starttime = array('main' => microtime(true));
    }

    // we return true, just to be able to write:
    //   AA::$debug && AA::$dbg->info("OK") && exit;
    function log()      {$v=func_get_args(); $this->_do('log',     $v); return true;}
    function info()     {$v=func_get_args(); $this->_do('info',    $v); return true;}
    function warn()     {$v=func_get_args(); $this->tracepoint('warn',    $v[0]); $this->_do('warn',    $v); return true;}
    function error()    {$v=func_get_args(); $this->tracepoint('error',   $v[0]); $this->_do('error',   $v); return true;}

    function group()    {
        $v     = func_get_args();
        $group = array_shift($v);
        $this->_starttime[$group] = microtime(true);
        $this->_groupstart($group);
        $this->_do('log', $v);
        return true;
    }

    function groupend() {
        $v     = func_get_args();
        $group = array_shift($v);
        $this->_do('log', $v);
        $this->_logtime($group);
        //$this->duration($group,microtime(true) - $this->_starttime[$group]);
        $this->_groupend($group);
        return true;
    }

    function tracestart($func, $text='') {
        $this->_calls[]      = array(count($this->_tracestack), $func, DB_AA::$_instances_no.' '.substr($text,0,200), strlen($text),microtime(true));
        $this->_tracestack[] = count($this->_calls)-1;
    }

    function traceend($func, $text='') {
        $time = microtime(true);
        $indx = array_pop($this->_tracestack);
        array_push($this->_calls[$indx], $time, substr($text,0,20), strlen($text));

        if (!is_array($this->_duration[$func])) {
            $this->_duration[$func] = array();
        }
        $this->_duration[$func][] = $time-$this->_calls[$indx][4];
    }

    function tracepoint($func, $text='') {
        $this->_calls[]      = array(count($this->_tracestack), $func, DB_AA::$_instances_no.' '.substr($text,0,200), strlen($text),microtime(true), microtime(true),'',0);
    }

    function duration_stat() {
        $row    = array();
        $sumsum = 0;
        foreach($this->_duration as $func => $times) {
            $sumsum += ($sum = 1000*array_sum($times));
            $row['a'.sprintf('%f',$sum/1000.0).'-'.$func] .= '<tr><td>'.safe($func).'</td><td>'.count($times).'</td><td>'.sprintf('%f',$sum/count($times)).'</td><td>'.sprintf('%f',$sum).'</td><td>'.sprintf('%f',1000*max($times)).'</td><td>'.sprintf('%f',1000*min($times)).'</td></tr>';
        }
        krsort($row);

        echo '<table><tr><th>function</th><th>called</th><th>avg</th><th>sum</th><th>max</th><th>min</th></tr>'.join('',$row).'<tr><th>Sum</th><th></th><th></th><th>'.$sumsum.'</th><th></th><th></th></tr></table>';
        echo '<br><table><tr><th>time</th><th>duration</th><th>function</th><th>in</th><th>out</th></tr>';
        foreach($this->_calls as $call) {
           echo '<tr><td>'.(1000*($call[4]-$_SERVER['REQUEST_TIME_FLOAT'])).'</td><td>'.(1000*($call[5]-$call[4])).'</td><td>'.str_repeat('.&nbsp;',$call[0]).safe($call[1]).'</td><td>'.safe($call[2]).($call[3]>200? '..+'.($call[3]-200) :'' ).'</td><td>'.safe($call[6]).($call[7]>20? '..+'.($call[7]-20) :'' ).'</td></tr>';
        }
        echo '</table>';
        if ($GLOBALS['contentcache']) {
            $GLOBALS['contentcache']->duration_stat();
        }
    }


    function _do($func, $params) {
        $time = microtime(true) - $this->_starttime['main'];
        echo "<small><em>$time</em></small><br>\n";

        foreach ($params as $a) {
            if (is_object($a) && is_callable(array($a,"__toString"))) {
                print $a;
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

/** Page level (global) variables you can count with */
class AA {
    public static $dbg;
    public static $debug;
    public static $perm;
    public static $site_id;
    public static $slice_id;   // optional - filled by view.php3 when site_id is not available (so it is used as allpage main module to find {_:alias} aliases)
    public static $encoding;   // inner module's/slice's encoding (utf-8, ...)
    public static $lang;       // two letters small caps - cz / es / en / ...
    public static $langnum;    // array of prefered language numbers - > 10000000
    public static $headers;    // [type=>xml|html,status=>404,encoding=>utf-8|windows-1250|...] - sent headers
    public static $module_id;  // for admin pages - replace of older $slice_id

    static function getHeaders() {
        $ret = array();
        $ret['type'] = 'Content-Type: '. (AA::$headers['type'] ?: 'text/html') .'; charset='.(AA::$headers['encoding'] ?: AA::$encoding ?: $GLOBALS["LANGUAGE_CHARSETS"][get_mgettext_lang()]);
        if (isset(AA::$headers['status'])) {
            $ret['status'] = AA::$headers['status'];
        }
        return $ret;
    }
    // typicaly called as AA::sendHeaders(AA::getHeaders());
    static function sendHeaders(array $headers) {
        foreach ($headers as $header) { header($header); }
    }
}

AA::$debug = $_GET['debug'];
AA::$dbg   = (AA::$debug[0] == 'f') ? new AA_Debug_Firephp() : ((AA::$debug[0] == 'c') ? new AA_Debug_Console() : new AA_Debug_Console());

class DB_AA extends DB_Sql {
    var $Host      = DB_HOST;
    var $Database  = DB_NAME;
    var $User      = DB_USER;
    var $Password  = DB_PASSWORD;

    public static $_instances_no = 0;

    /* public: constructor */
    function __construct($query = "") {
        self::$_instances_no++;
        parent::__construct($query);
    }

    /**
     *  used as: $sdata = DB_AA::select1('SELECT * FROM `slice`', '', array(array('id',$long_id, 'l'))));
     *  used as: $chid  = DB_AA::select1("SELECT id FROM `change` WHERE ...", 'id');
     **/
    static function select1($query, $column=false, $where=null, $order=null) {
        $db = getDB();
        $query .= is_array($where) ? ' '.DB_AA::makeWhere($where) : '';
        $query .= is_array($order) ? ' '.DB_AA::makeOrder($order) : '';

        AA::$debug && AA::$dbg->log("$query LIMIT 1");

        $db->query("$query LIMIT 1");
        $ret = false;
        if ($db->next_record()) {
            if (!is_array($column)) {
                $ret = empty($column) ? $db->Record : $db->Record[$column];
            } else {
                $key = key($column);
                $val = empty($column) ? $db->Record : array_intersect_key($db->Record, array_flip($column));
                $ret = ctype_digit((string)$key) ? $val : array($db->Record[$key] => $val);
            }
        }
        freeDB($db);
        return $ret;
    }

    /**
     *  first parameter describes the desired output
     *  written with speed in mind - so all the loops are condition free
     *  used as: $chid = DB_AA::select('id', 'SELECT id FROM `change` WHERE ...');                   -> [id1, id2, ...]
     *                   DB_AA::select('',   'SELECT id, other FROM `change` WHERE ...');            -> [id1, id2, ...]
     *                   DB_AA::select(array(), 'SELECT id FROM `change`');                          -> [[id=>id1], [id=>id2], ...]
     *                   DB_AA::select(array(), 'SELECT id,other FROM `change`');                    -> [[id=>id1,other=>other1], [id=>id2,other=>other2], ...]
     *                   DB_AA::select(array('id'=>1), 'SELECT id FROM `change`');                   -> [[id1=>1], [id2=>1], ...]
     *                   DB_AA::select(array('id'=>'other'), 'SELECT id,other FROM `change`');       -> [[id1=>other1], [id2=>other2], ...]
     *   $slice_owners = DB_AA::select(array('unpackid'=>'name'), 'SELECT LOWER(HEX(`id`)) AS unpackid, `name` FROM `slice_owner` ORDER BY `name`');
     *                   DB_AA::select(array('id'=>'+other'), 'SELECT id,other FROM `change`');      -> [[id1=>[other1a,other1b], [id2=>[other2]], ...]  // good for multivalues
     *                   DB_AA::select(array('id'=>array()), 'SELECT id,other FROM `change`');       -> [[id1=>[id=>id1,other=>other1]], [id2=>[id=>id2,other=>other2]], ...]
     *                   DB_AA::select(array('id'=>array(other)), 'SELECT id,other FROM `change`');  -> [[id1=>[other=>other1]], [id2=>[other=>other2]], ...]
     *                   DB_AA::select('', 'SELECT source_id FROM relation', array(array('destination_id', $item_id, 'l'), array('flag', REL_FLAG_FEED, 'i'))));
     **/
    static function select($column, $query, $where=null, $order=null) {
        $db = getDB();
        $query .= is_array($where) ? ' '.DB_AA::makeWhere($where) : '';
        $query .= is_array($order) ? ' '.DB_AA::makeOrder($order) : '';

        $db->query($query);

        $ret = array();
        if (!is_array($column)) {
            if (empty($column)) {
                while ($db->next_record()) {
                    $ret[] = reset($db->Record);
                }
            } else {
                while ($db->next_record()) {
                    $ret[] = $db->Record[$column];
                }
            }
        } else {
            if (empty($column)) {
                while ($db->next_record()) {
                    $ret[] = $db->Record;
                }
            } else {
                $key      = key($column);
                $values   = reset($column);
                if (ctype_digit((string)$key) OR empty($key)) {
                    if (!is_array($values)) {
                        while ($db->next_record()) {
                             $ret[] = $db->Record[$values];
                        }
                    } else {
                        $col_keys = array_flip($values);
                        while ($db->next_record()) {
                            $ret[] = array_intersect_key($db->Record, $col_keys);
                        }
                    }
                } else {
                    if (empty($values)) {
                        while ($db->next_record()) {
                             $ret[$db->Record[$key]] = $db->Record;
                        }
                    } elseif (!is_array($values)) {
                        if (is_string($values)) {
                            if ($values[0] == '+') {                              // array('id'=>'+other')
                                $values = substr($values,1);
                                while ($db->next_record()) {
                                     $ret[$db->Record[$key]][] = $db->Record[$values];
                                }
                            } else {                                              // array('id'=>'other')  - we do not expect multivalues
                                while ($db->next_record()) {
                                     $ret[$db->Record[$key]] = $db->Record[$values];
                                }
                            }
                        } else {
                            while ($db->next_record()) {                          // array('id'=>1)
                                 $ret[$db->Record[$key]] = $values;
                            }
                        }
                    } else {
                        $col_keys = array_flip($values);
                        while ($db->next_record()) {
                            $ret[$db->Record[$key]] = array_intersect_key($db->Record, $col_keys);
                        }
                    }
                }
            }
        }
        freeDB($db);
        return $ret;
    }

    function quote($string) {
        $db = getDB();
        $ret = $db->quote($string);
        freeDB($db);
        return $ret;
    }

    /** static
     *  used as: DB_AA::sql("INSERT SELECT id FROM `change` WHERE ...");
     *  @return number of affected rows (useful for INSERT/UPDATE/DELETE) of false on problem
     **/
    static function sql($query, $where=null) {
        $db = getDB();
        $query .= is_array($where) ? ' '.DB_AA::makeWhere($where) : '';
        $ret = $db->query($query) ? $db->affected_rows() : false;
        freeDB($db);
        return $ret;
    }

    /** used as: DB_AA::delete('perms', array(array('object_type', $object_type), array('objectid', $objectID), array('flag', REL_FLAG_FEED, 'i'))); */
    static function delete($table, $where=null) {
        return DB_AA::sql("DELETE FROM `$table` ", $where);
    }

    /** LOW PRIORITY version of DB_AA::delete() */
    static function delete_low_priority($table, $where=null) {
        return DB_AA::sql("DELETE LOW_PRIORITY FROM `$table` ", $where);
    }

    /** used as: DB_AA::update('perms', array(array('object_type', $object_type), array('objectid', $objectID), array('flag', REL_FLAG_FEED, 'i'))); */
    static function update($table, $varlist, $where) {
        $delim = '';
        $cols = '';
        foreach ( $varlist as $vardef) {
            // $vardef is array(varname, type, value)
            list($name, $value, $type) = $vardef;
            switch ( $type ) {
                case "i": $part = (int)$value; break;
                case "l": $part = q_pack_id($value); break;
                case "q": $part = $value; break;
                //default:  $part = DB_AA::quote($value);
                default:  $part = addslashes($value);
            }
            $cols .= "$delim $name = '$part'";
            $delim = " ,";
        }
        $wh = DB_AA::makeWhere($where);
        $ret = false;
        if ($cols AND $wh) {
            $db = getDB();
            $db->query("UPDATE `$table` SET $cols $wh");
            $ret = $db->affected_rows();
            freeDB($db);
        }
        return $ret;
    }

    /** used as: DB_AA::test('perms', array(array('object_type', $object_type), array('objectid', $objectID), array('flag', REL_FLAG_FEED, 'i'))); */
    static function test($table, $where) {
        return (false !== DB_AA::select1("SELECT ".($where[0][0])." FROM `$table` ", '', $where));
    }

    /** makeWhere function
     *  [[field_name, value, type], ...]
     *     type                        used operator    value              example
     *     s       - string (default)  =                single or array    array('field_id',$id)
     *     i       - integer           =                single or array    array('id', $task_id, 'i')
     *     l       - longid            =                single or array    array('item_id', $ids_arr, 'l')
     *     set     - flag is set       fld & val = val  single             array('flag', REL_FLAG_FEED, 'set')
     *     j       - JOIN              =                single             array('slice.id', 'module.id', 'j') - for table join
     *     >       - integer           >                single             array('date', 1478547854, '>')
     *     <       - integer           <                single             array('date', 1478547854, '<')
     *     >=      - integer           >=               single             array('date', 1478547854, '>=')
     *     <=      - integer           <=               single             array('date', 1478547854, '<=')
     *     ISNULL  -                   IS NULL                             array('number', 'ISNULL')
     *     NOTNULL -                   IS NOT NULL                         array('number', 'NOTNULL')
     *
     * @param $tablename
     */
    static public function makeWhere($varlist) {
        $delim = '';
        $where = '';
        foreach ( $varlist as $vardef) {
            // $vardef is array(varname, type, value)
            list($name, $value, $type) = $vardef;
            if (in_array($type, array('>','<','<=','>='))) {
                $operator = $type;
                $type     = 'i';
            } elseif ( $value == 'ISNULL' ) {
                $where   .= "$delim $name IS NULL ";
                continue;
            } elseif ( $value == 'NOTNULL' ) {
                $where   .= "$delim $name IS NOT NULL ";
                continue;
            } else {
                $operator = '=';
            }

            if (!is_array($value)) {
                switch ( $type ) {
                    case "i":   $where .= "$delim $name $operator ". (int)$value; break;
                    case "l":   $where .= "$delim $name = ".         xpack_id($value); break;
                    case "j":   $where .= "$delim $name = ".         quote($value); break;
                    case "set": $value  = (int)$value;
                                $where .= "$delim (($name & $value) = $value)"; break;
                    //default:  $part = DB_AA::quote($value);
                    default:    $where .= "$delim $name = ". qquote($value);
                }
            } else {
                switch ( $type ) {
                    case "i": $arr = array_map('intval',   $value); break;
                    case "l": $arr = array_map('xpack_id', $value); break;
                    default:  $arr = array_map('qquote',   $value);
                }
                switch (count($arr)) {
                    case 0:  $where .= "$delim 2=1"; break;
                    case 1:  $where .= "$delim $name $operator ". reset($arr); break;
                    default: $where .= "$delim $name IN (". join(',', $arr) .")";
                }
            }
            $delim = " AND";
        }
        return $where ? "WHERE $where" : '';
    }

    static protected function makeOrder($orderarr) {
        $order = array();
        foreach ($orderarr as $sort) {
            switch ( substr($sort,-1) ) {    // last character
                case '-':  $order[] = substr($sort,0,-1). ' DESC'; break;
                case '+':  $order[] = substr($sort,0,-1); break;
                default:   $order[] = $sort;
            }
        }
        return $order ? 'ORDER BY '. join(',',$order) : '';
    }


    /** tquery function
     * @param $SQL
     */
    function query($SQL) {
        AA::$debug&16 && AA::$dbg->tracestart('Query', $SQL);
        $ret = parent::query($SQL);
        AA::$debug&16 && AA::$dbg->traceend('Query', (stripos($SQL, "SELECT") === 0) ? $this->num_rows() : $this->affected_rows());
        return $ret;
    }

    /** tquery function
     *  @deprecated - use query
     */
    function tquery($SQL) {
        return $this->query($SQL);
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

// This pair of functions remove the guessing about which of $db $db2
// to use
// Usage: $db = getDB(); ..do stuff with sql ... freeDB($db)
//
$spareDBs     = array();
/** getDB function
 *
 */
function getDB() {
    global $spareDBs;
    if (!($db = array_pop($spareDBs))) {
        $db = new DB_AA;
    }
    return $db;
}
/** freeDB function
 * @param $db
 */
function freeDB($db) {
    global $spareDBs;
    array_push($spareDBs,$db);
}

/** @deprecated GetTable2Array function
 *  function converts table from SQL query to array
 * @param $SQL
 * @param $key    - return array's key - 'NoCoLuMn' | '' | 'aa_first' | <database_column> | 'unpack:<database_column>'
 * @param $values - return array's val - 'aa_all' |
 *                                 'aa_mark' |
 *                                 'aa_fields' |
 *                                 <database_column> |
 *                                 'unpack:<database_column>' |
 *                                 true
 */
function GetTable2Array($SQL, $key="id", $values='aa_all') {
    $db = getDB();
    $db->query($SQL);

    while ($db->next_record()) {
        if ($values == 'aa_all') {
            $val = $db->Record;
        } elseif ($values == 'aa_mark') {
            $val = true;
        } elseif (substr($values,0,7) == 'unpack:') {
            $val = unpack_id($db->f(substr($values,7)));
        } elseif (is_string($values) AND array_key_exists( $values, $db->Record )) {
            $val = $db->Record[$values];
        } else {  // true or 'aa_fields'
            $val = $db->Record;
            // $val = DBFields($db);  // I changed the mysql_fetch_array($this->Query_ID, MYSQL_ASSOC) in db_mysql by adding MYSQL_ASSOC, so DBFields is no longer needed
        }

        if ( $key == 'aa_first' ) {
            freeDB($db);
            return $val;
        } elseif ( ($key == "NoCoLuMn") OR !$key ) {
            $arr[] = $val;
        } elseif ( substr($key,0,7) == 'unpack:' ) {
            $arr[unpack_id($db->f(substr($key,7)))] = $val;
        } else {
            $arr[$db->f($key)] = $val;
        }
    }
    freeDB($db);
    return isset($arr) ? $arr : false;
}

class AA_Session extends Session {

    function __construct() {
        $this->lifetime  = defined('AA_LOGIN_TIMEOUT') ? constant('AA_LOGIN_TIMEOUT') : 200;   // 200 minutes
        parent::__construct();
    }

    // add module_id=... to url. It is better to use StateUrl() directly, but we already use $sess->url() from older versions of $session management
    function url($url) {
        return StateUrl($url);
    }

    // get <input name="module_id"... . It is better to use StateHidden() directly, but we already use $sess->hidden_session() from older versions of $session management
    function get_hidden_session() {
        return StateHidden();
    }
}

function pageOpen($type = '') {
    global $sess, $auth;

    $sess = new AA_Session;
    $sess->start();

    if ($type != 'noauth') {
        if (!is_object($auth)) {
            $auth = new AA_Auth;
        }
        $auth->set_nobody($type=='nobody');
        $auth->start();
    }
}
?>