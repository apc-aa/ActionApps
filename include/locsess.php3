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
/** This file is included to all scripts, so let's set there the main environment */

// set timezone - just for date() speedup
// date_default_timezone_set(date_default_timezone_get());

// supress PHP notices
error_reporting(error_reporting() & ~(E_WARNING | E_NOTICE | E_DEPRECATED | E_STRICT));

// if AA used in proxy mode, we need to correct some internal variables
if ($_SERVER['HTTP_X_FORWARDED_SERVER']) {
    $_SERVER['HTTP_HOST']   = $_SERVER['HTTP_X_FORWARDED_HOST'];
    $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_X_FORWARDED_SERVER'];
    if ($_SERVER['HTTP_X_FORWARDED_FOR']) {
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
}

/* Change this to match your database. */
require_once(AA_INC_PATH.'phplib/'. ( (defined("DB_TYPE") AND (DB_TYPE != 'db_mysqli')) ? DB_TYPE .".inc" : "db_mysql.inc"));
require_once(AA_INC_PATH.'phplib/phplib.php');
require_once __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(function ($class_name) {
    $PAIRS = array(
        'AA_Actionapps'                  => 'central/include/actionapps.class.php',
        'AA_Auth'                        => 'include/locauth.php3',
        'AA_Components'                  => 'include/statestore.php3',
        'AA_Computedfields_Updater'      => 'include/hitcounter.class.php3',
        'AA_ComputedfieldsItem_Updater'  => 'include/hitcounter.class.php3',
        'AA_Fields'                      => 'include/field.class.php3',
        'AA\FormArray'                   => 'include/widget.class.php3',
        'AA_Formatters'                  => 'include/widget.class.php3',
        'AA_iEditable'                   => 'include/statestore.php3',
        'AA_Items'                       => 'include/item.php3',
        'AA_Mailman'                     => 'include/mailman.php3',
        'AA_Mysqlauth'                   => 'include/auth.php3',
        'AA_Object'                      => 'include/statestore.php3',
        'AA_Perm'                        => 'include/perm_core.php3',
        'AA_Permsystem_Ldap'             => 'include/perm_ldap.php3',
        'AA_Permsystem_Sql'              => 'include/perm_sql.php3',
        'AA_Property'                    => 'include/widget.class.php3',
        'AA_Poll'                        => 'modules/polls/include/poll.class.php3',
        'AA_Polls'                       => 'modules/polls/include/poll.class.php3',
        'AA_Serializable'                => 'include/statestore.php3',
        'AA_Storable'                    => 'include/statestore.php3',
        'FormrowWidget'                  => 'include/form.class.php3',
        'AA_Jstriggers'                  => 'include/javascript.php3',
        'ConvertCharset'                 => 'include/convert_charset.class.php3',
        'Files'                          => 'include/files.class.php3',
        'zids'                           => 'include/zids.php3'
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
        case 'adminpage':
        case 'debug':
        case 'difftext':
        case 'field':
        case 'form':
//        case 'grabber':
        case 'hitcounter':
        case 'manager':
        case 'profile':
        case 'request':
        case 'searchbar':
        case 'slice':
        case 'table':
        case 'toexecute':
        case 'transformation':
        case 'widget':
        case 'view':
            require_once __DIR__. '/'. $core. '.class.php3';
            return;
        case 'module':
        case 'modulesettings':
            require_once __DIR__. '/slice.class.php3';
            return;
        case 'adminpageutil':
            require_once __DIR__. '/adminpage.class.php3';
            return;
        case 'response':
        case 'clientauth':
        case 'http':
            require_once __DIR__. '/request.class.php3';
            return;
        case 'scroller':
            require_once __DIR__. '/scroller.php3';
            return;
        case 'plannedtask':
            require_once __DIR__. '/task.class.php3';
            return;
        case 'bookmarks':
            require_once __DIR__. '/searchbar.class.php3';
            return;
        case 'views':
            require_once __DIR__. '/view.class.php3';
            return;



        case 'trees':
        case 'supertree':
            require_once __DIR__. '/tree.class.php3';
            return;
        case 'array':
            require_once __DIR__. '/table.class.php3';
            return;
        case 'manageraction':
        case 'manageractions':
            require_once __DIR__. '/manager.class.php3';
            return;
        case 'validate':
            require_once __DIR__. '/validate.php3';
            return;
        case 'exportsetings':
            require_once __DIR__. '/exporter.class.php3';
            return;
        case 'formrow':
            require_once __DIR__. '/form.class.php3';
            return;
        case 'file':
        case 'directory':
            require_once __DIR__. '/files.class.php3';
            return;
      }

    $CUSTOM_INC_FILES = array(
        'AA_Responder'    => 'responder.php'
        );

    if (defined('AA_CUSTOM_DIR')) {
        foreach ($CUSTOM_INC_FILES as $inc_def => $inc_file) {
            if (strpos($class_name, $inc_def) === 0 ) {
                include_once(__DIR__. '/custom/'. AA_CUSTOM_DIR. '/'. $inc_file);
            }
        }
    }
});

class AA_Debug {
    protected $_starttime;
    protected $_duration   = array();
    protected $_tracestack = array();
    protected $_calls      = array();
    protected $_repeated   = array();

    function __construct() {
        $this->_starttime = array('main' => microtime(true));
    }

    // we return true, just to be able to write:
    //   AA::$debug&2 && AA::$dbg->info("OK") && exit;
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
        $txt = AA::$debug&128 ? $text : substr($text,0,200);
        if ($func=='Query') {
            $this->_check_repeted($text);
        }
        $this->_calls[]      = array(count($this->_tracestack), $func, DB_AA::$_instances_no.' '.$txt, strlen($text),microtime(true));
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


        // repeated queries;
        $row    = array();
        foreach($this->_repeated as $query => $times) {
            if ($times>1) {
                $row['a' . sprintf('%09d', $times)] = '<tr><td>' . safe($times) . '</td><td>' . safe($query) . '</td></tr>';
            }
        }
        if ($row) {
            krsort($row);
            echo '<br><table><tr><th>used x times</th><th>query</th></tr>' . join('', $row) . '<tr></table>';
        }


        // longest queries;
        $row    = array();
        foreach($this->_calls as $call) {
            if ($call[1]=="Query") {
                $row['a' . sprintf('%012.5f',($call[5]-$call[4])*1000.0)] = '<tr><td>'.safe(($call[5]-$call[4])*1000.0) . '</td><td>' . safe($call[2]) . '</td><td>' . safe($call[6]) . '</td></tr>';
            }
        }
        if ($row) {
            krsort($row);
            echo '<br><table><tr><th>time</th><th>query</th><th>out</th></tr>' . join('', $row) . '<tr></table>';
        }


        echo '<br><table><tr><th>time</th><th>duration</th><th>function</th><th>in</th><th>out</th></tr>';
        foreach($this->_calls as $call) {
           echo '<tr><td>'.(1000*($call[4]-$_SERVER['REQUEST_TIME_FLOAT'])).'</td><td>'.(1000*($call[5]-$call[4])).'</td><td>'.str_repeat('.&nbsp;',$call[0]).safe($call[1]).'</td><td>'.safe($call[2]).( ($call[3]>200 AND !AA::$debug&128) ? '..+'.($call[3]-200) :'' ).'</td><td>'.safe($call[6]).($call[7]>20? '..+'.($call[7]-20) :'' ).'</td></tr>';
        }
        echo '</table>';
        AA::Contentcache()->duration_stat();
    }

    function _check_repeted($txt) {
        $this->_repeated[$txt] += 1;
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
    /** @var AA_Debug $dbg  */
    public  static $dbg;
    public  static $debug;
    /** @var  AA_Perm $perm */
    public  static $perm;
    public  static $site_id;
    public  static $slice_id;            // optional - filled by view.php3 when site_id is not available (so it is used as allpage main module to find {_:alias} aliases)
                                         //             or for Saver to pass validator the current slice of added item for slice unique
    public  static $encoding;            // inner module's/slice's encoding (utf-8, ...)
    public  static $lang;                // two letters small caps - cz / es / en / ...
    public  static $langnum;             // array of prefered language numbers - > 10000000
    public  static $headers = array();   // [type=>xml|html,status=>404,encoding=>utf-8|windows-1250|...] - sent headers
    public  static $module_id;           // for admin pages - replace of older $slice_id
    /** @var  AA_Stringexpander $stringexpander */
    private static $stringexpander = null;
    /** @var  AA_Contentcache $contentcache */
    private static $contentcache   = null;
    /** @var  PageCache $pagecache */
    private static $pagecache      = null;
    /** @var  AA_Metabase $metabase */
    private static $metabase       = null;

    static function getHeaders() {
        $ret = array();
        $ret['type'] = 'Content-Type: '. (AA::$headers['type'] ?: 'text/html') .'; charset='.(AA::$headers['encoding'] ?: AA::$encoding ?: AA_Langs::getCharset());
        if (isset(AA::$headers['status'])) {
            $ret['status'] = AA::$headers['status'];
        }
        if (isset(AA::$headers['disposition'])) {
            $ret['disposition'] = 'Content-Disposition: '.AA::$headers['disposition'];
        }
        if (isset(AA::$headers['CSP'])) {
            $ret['CSP'] = AA::$headers['CSP'];
        }
        if (isset(AA::$headers['CSP-RO'])) {
            $ret['CSP-RO'] = AA::$headers['CSP-RO'];
        }
        if (isset(AA::$headers['RT'])) {
            $ret['RT'] = AA::$headers['RT'];
        }
        if (isset(AA::$headers['NEL'])) {
            $ret['NEL'] = AA::$headers['NEL'];
        }
        if (isset(AA::$headers['XSS'])) {
            $ret['XSS'] = AA::$headers['XSS'];
        }
        return $ret;
    }

    /** @usage AA::Stringexpander()->unalias();
     *  @return  AA_Stringexpander
     */
    static function setEncoding($encoding) {
        AA::$encoding = $encoding;
        mb_internal_encoding( (stripos($encoding, 'utf-8') === 0) ? 'UTF-8' : '8bit');
    }


    /** @usage AA::Stringexpander()->unalias();
     *  @return  AA_Stringexpander
     */
    static function Stringexpander() {
        return AA::$stringexpander ?: (AA::$stringexpander = new AA_Stringexpander());
    }

    /** @usage AA::Contentcache()->set();
     *  @return  AA_Contentcache
     */
    static function Contentcache() {
        return AA::$contentcache ?: (AA::$contentcache = new AA_Contentcache());
    }

    /** @usage AA::Pagecache()->invalidateFor();
     *  @return  Pagecache
     */
    static function Pagecache() {
        return AA::$pagecache ?: (AA::$pagecache = new PageCache(CACHE_TTL));
    }

    /** @usage AA::Metabase()->doInsert('log', ['time'=>time(),'user'=>$uid, 'type'=>$event, 'selector'=>$selector, 'params'=>$params]);
     *  @return  AA_Metabase
     */
    static function Metabase() {
        return AA::$metabase ?: (AA::$metabase = AA_Metabase::singleton());
    }

    // typicaly called as AA::sendHeaders(AA::getHeaders());
    static function sendHeaders(array $headers) {
        foreach ($headers as $header) { header($header); }
    }
}

AA::$debug = ($_GET['debug'] OR $_COOKIE['aa_debug']);  // aa_debug used in admin interface - item manager - left menu
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
     *           $chid  = DB_AA::select1("SELECT id FROM `change` WHERE ...", 'id');
     *                    DB_AA::select1("SELECT last_edit FROM `item`", 'last_edit', $where, array('last_edit-'))
     **/
    static function select1($query, $column=false, $where=null, $order=null) {
        $db = getDB();
        $query .= is_array($where) ? ' '.DB_AA::makeWhere($where) : '';
        $query .= is_array($order) ? ' '.DB_AA::makeOrder($order) : '';

        AA::$debug&8 && AA::$dbg->log("$query LIMIT 1");

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
     * @param        $column
     * @param        $query
     * @param array? $where
     * @param array? $order -  array of order fields - ['id','time-']
     * @return array
     */
    static function select($column, $query, $where=null, $order=null) {
        $db = getDB();
        $query .= is_array($where) ? ' '.DB_AA::makeWhere($where) : '';
        $query .= is_array($order) ? ' '.DB_AA::makeOrder($order) : '';

        AA::$debug&2 && AA::$dbg->log("$query");

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
     *  @return number of affected rows (useful for INSERT/UPDATE/DELETE) or false on problem
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

    /** check if Table exists */
    static function exists_table($table) {
        return (false !== DB_AA::select1('SELECT table_schema FROM information_schema.tables', '', array(array('table_schema', DB_NAME), array('table_name', $table))));
    }

    /** used as: DB_AA::update('slice', array(array('javascript',$_POST['javascript'])), array(array('id', $slice_id, 'l')));  */
    static function update($table, $varlist, $where) {
        $delim = '';
        $cols = '';
        foreach ( $varlist as $vardef) {
            // $vardef is array(varname, type, value)
            list($name, $value, $type) = $vardef;
            switch ( $type ) {
                case "i": $part = (int)$value; break;
                case "l": $part = xpack_id($value); break;
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

    /** used as: DB_AA::test('perms', array(array('object_type', $object_type), array('objectid', $objectID), array('flag', REL_FLAG_FEED, 'i')));
     *           DB_AA::test(['content','item'], [['content.item_id', 'item.id', 'j'],['item.slice_id', $slice_id, 'l']]);
     */
    static function test($table, $where) {
        $tbl = is_array($table) ? '`'.join('`,`',$table).'`' : "`$table`";
        return (false !== DB_AA::select1("SELECT ".($where[0][0])." FROM $tbl ", '', $where));
    }

    /** makeWhere function
     *  [[field_name, value, type], ...]
     *     type                         used operator    value              example
     *     s        - string (default)  =                single or array    array('field_id',$id)
     *     i        - integer           =                single or array    array('id', $task_id, 'i')
     *     l        - longid            =                single or array    array('item_id', $ids_arr, 'l')
     *     set      - flag is set       fld & val = val  single             array('flag', REL_FLAG_FEED, 'set') - good for bitfields
     *     unset    - flag is not set   fld & val = 0    single             array('flag', 64, 'unset') - good for bitfields
     *     j        - JOIN              =                single             array('slice.id', 'module.id', 'j') - for table join  (SELECT ... FROM `slice`, `module`', '', array(array('slice.id', 'module.id', 'j'), a..
     *     >        - integer           >                single             array('date', 1478547854, '>')
     *     <        - integer           <                single             array('date', 1478547854, '<')
     *     >=       - integer           >=               single             array('date', 1478547854, '>=')
     *     <=       - integer           <=               single             array('date', 1478547854, '<=')
     *     <>       - integer           <>               single             array('date', 1478547854, '<>')
     *     !=       - integer           <>               single             array('date', 1478547854, '!=')   // the same as above - "<>"
     *     ISNULL   -                   IS NULL                             array('number', 'ISNULL')
     *     NOTNULL  -                   IS NOT NULL                         array('number', 'NOTNULL')
     *     LIKE     -                   LIKE             single             array('id', '\_%', 'LIKE')
     *     NOT LIKE -                   NOT LIKE         single             array('id', '\_%', 'NOT LIKE')
     *     FILLED   -                   > ''             single             array('field3', 'FILLED')         // not null, not empty, not any whitespace character(s)
     *
     * @param $tablename
     * @return string
     */
    static public function makeWhere($varlist) {
        $where = array();
        foreach ( $varlist as $vardef) {
            // $vardef is array(varname, type, value)
            list($name, $value, $type) = $vardef;
            $type = (string)$type;
            if (in_array($type, array('>','<','<=','>=', '<>', '!='))) {
                $operator = $type;
                $type     = 'i';
            } elseif ( ($type == 'LIKE') OR ($type == 'NOT LIKE') ) {
                $where[]  = "$name $type ". qquote($value);
                continue;
            } elseif ( ($type == 's<>') OR ($type == 's!=') ) {
                $where[]  = "$name <> ". qquote($value);
                continue;
            } elseif ( ($type == 'l<>') OR ($type == 'l!=') ) {
                $where[]  = "$name <> ". xpack_id($value);
                continue;
            } elseif ( (string)$value == 'ISNULL' ) {
                $where[]  = "$name IS NULL ";
                continue;
            } elseif ( (string)$value == 'NOTNULL' ) {
                $where[]  = "$name IS NOT NULL ";
                continue;
            } elseif ( (string)$value == 'FILLED' ) {
                $where[]  = "$name > '' ";             // this is kind of trick for - not null, not empty, not any whitespace character(s) - thanks: https://stackoverflow.com/questions/1869264#42723975
                continue;
            } else {
                $operator = '=';
            }

            if (!is_array($value)) {
                switch ( $type ) {
                    case "i":     $where[]  = "$name $operator ". (int)$value; break;
                    case "l":     $where[]  = "$name = ".         xpack_id($value); break;
                    case "j":     $where[]  = "$name = ".         quote($value); break;
                    case "set":   $value  = (int)$value;
                        $where[]  = "(($name & $value) = $value)"; break;
                    case "unset":   $value  = (int)$value;
                        $where[]  = "(($name & $value) = 0)"; break;
                    //default:  $part = DB_AA::quote($value);
                    default:    $where[]  = "$name = ". qquote($value);
                }
            } else {
                switch ( $type ) {
                    case "i": $arr = array_map('intval',   $value); break;
                    case "l": $arr = array_map('xpack_id', $value); break;
                    default:  $arr = array_map('qquote',   $value);
                }
                switch (count($arr)) {
                    case 0:  $where    = array("2=1"); break;
                    case 1:  $where[]  = "$name $operator ". reset($arr); break;
                    default: $where[]  = "$name IN (". join(',', $arr) .")";
                }
            }
        }
        return count($where) ? "WHERE ".join(' AND ',$where) : '';
    }

    /** generate order phrase
     * @param $orderarr  array of order fields - ['id','time-']
     * @return string
     */
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


    /** query function
     * @param $SQL
     * @return int
     */
    function query($SQL) {
        AA::$debug&16 && AA::$dbg->tracestart('Query', $SQL);
        $ret = parent::query($SQL);
        AA::$debug&16 && AA::$dbg->traceend('Query', (stripos($SQL, "SELECT") === 0) ? $this->num_rows() : $this->affected_rows());
        return $ret;
    }

    /** query_nohalt function - do not halt on database error
     * @param $SQL
     * @return int
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
 * @param $key - return array's key - 'NoCoLuMn' | '' | 'aa_first' | <database_column> | 'unpack:<database_column>'
 * @param $values - return array's val - 'aa_all' |
 *                                 'aa_mark' |
 *                                 'aa_fields' |
 *                                 <database_column> |
 *                                 'unpack:<database_column>' |
 *                                 true
 * @return array|bool|string
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

    // add module_id=... to url. It is better to use StateUrl() directly, but we already use StateUrl() from older versions of $session management
    // @deprecated - no longer used in the code
    function url($url) {
        return StateUrl($url);
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
