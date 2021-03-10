<?php
/**
*
* PHP version 7.2+
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
* @link      https://www.apc.org/ APC
*
*/



/** This file is included to all scripts, so let's set there the main environment */

// set timezone - just for date() speedup
// date_default_timezone_set(date_default_timezone_get());

// supress PHP notices
use AA\Cache\PageCache;
use AA\IO\DB\DB_AA;

error_reporting(error_reporting() & ~(E_WARNING | E_NOTICE | E_DEPRECATED | E_STRICT));

// if AA used in proxy mode, we need to correct some internal variables
if ($_SERVER['HTTP_X_FORWARDED_SERVER']) {
    $_SERVER['HTTP_HOST']   = $_SERVER['HTTP_X_FORWARDED_HOST'];
    $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_X_FORWARDED_SERVER'];
    if ($_SERVER['HTTP_X_FORWARDED_FOR']) {
        $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
}

// should be set in config.php3
if (!defined(AA_INSTAL_PATH)) {
    $url_components = parse_url(AA_INSTAL_URL);
    define('AA_INSTAL_PATH', $url_components['path']);
}
// now it is not necessary to have this constant in config.php3 - we count it from current file path
if (!defined('AA_BASE_PATH')) {
    define('AA_BASE_PATH', substr(__DIR__, 0, -7));
}


/* Change this to match your database. */
require_once __DIR__."/phplib/phplib.php";
require_once __DIR__."/../vendor/autoload.php";

spl_autoload_register(function ($class_name) {
    $PAIRS = [
        'AA_Actionapps'                  => 'central/include/actionapps.class.php',
        'AA_Auth'                        => 'include/locauth.php3',
        'AA_Components'                  => 'include/statestore.php3',
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
    ];

    if ($PAIRS[$class_name]) {
        require (__DIR__ . '/../' . $PAIRS[$class_name]);
        return;
    }

    $matches = [];
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
//        case 'hitcounter':
        case 'manager':
        case 'profile':
        case 'request':
        case 'searchbar':
        case 'slice':
        case 'table':
//        case 'toexecute':
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
        case 'bookmarks':
            require_once __DIR__. '/searchbar.class.php3';
            return;
        case 'views':
            require_once __DIR__. '/view.class.php3';
            return;
        case 'plannedtask':
            require_once __DIR__. '/../src/AA_Plannedtask.php';
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

    $CUSTOM_INC_FILES = [
        'AA_Responder'    => 'responder.php'
    ];

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
    protected $_duration   = [];
    protected $_tracestack = [];
    protected $_calls      = [];
    protected $_repeated   = [];

    function __construct() {
        $this->_starttime = ['main' => microtime(true)];
    }

    // we return true, just to be able to write:
    //   AA::$debug&2 && AA::$dbg->info("OK") && exit;
    function log()      {$v=func_get_args(); $this->_do('log',     $v); return true;}
    function info()     {$v=func_get_args(); $this->_do('info',    $v); return true;}
    function warn()     {$v=func_get_args(); $this->tracepoint('warn',    $v[0]); $this->_do('warn',    $v); return true;}
    function error()    {$v=func_get_args(); $this->tracepoint('error',   $v[0]); $this->_do('error',   $v); return true;}

    function group(...$v)    {
        $group = array_shift($v);
        $this->_starttime[$group] = microtime(true);
        $this->_groupstart($group);
        $this->_do('log', $v);
        return true;
    }

    function groupend(...$v) {
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
        $this->_calls[]      = [count($this->_tracestack), $func, DB_AA::$_instances_no.' '.$txt, strlen($text),microtime(true)];
        $this->_tracestack[] = count($this->_calls)-1;
    }

    function traceend($func, $text='') {
        $time = microtime(true);
        $indx = array_pop($this->_tracestack);
        array_push($this->_calls[$indx], $time, substr($text,0,20), strlen($text));

        if (!is_array($this->_duration[$func])) {
            $this->_duration[$func] = [];
        }
        $this->_duration[$func][] = $time-$this->_calls[$indx][4];
    }

    function tracepoint($func, $text='') {
        $this->_calls[]      = [count($this->_tracestack), $func, DB_AA::$_instances_no.' '.substr($text,0,200), strlen($text),microtime(true), microtime(true),'',0];
    }

    function duration_stat() {

        $row    = [];
        $sumsum = 0;
        foreach($this->_duration as $func => $times) {
            $sumsum += ($sum = 1000*array_sum($times));
            $row['a'.sprintf('%f',$sum/1000.0).'-'.$func] .= '<tr><td>'.safe($func).'</td><td>'.count($times).'</td><td>'.sprintf('%f',$sum/count($times)).'</td><td>'.sprintf('%f',$sum).'</td><td>'.sprintf('%f',1000*max($times)).'</td><td>'.sprintf('%f',1000*min($times)).'</td></tr>';
        }
        krsort($row);
        echo '<table><tr><th>function</th><th>called</th><th>avg</th><th>sum</th><th>max</th><th>min</th></tr>'.join('',$row).'<tr><th>Sum</th><th></th><th></th><th>'.$sumsum.'</th><th></th><th></th></tr></table>';


        // repeated queries;
        $row    = [];
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
        $row    = [];
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
        ++$this->_repeated[$txt];
    }

    function _do($func, $params) {
        $time = microtime(true) - $this->_starttime['main'];
        echo "<small><em>$time</em></small><br>\n";

        foreach ($params as $a) {
            if (is_object($a) && is_callable([$a,"__toString"])) {
                print $a;
            } else {
                print_r($a);
            }
            echo "<br>\n";
        }
    }

    function _groupstart($group) {
        echo "\n<div style='border: 1px #AAA solid; margin: 6px 1px 6px 12px'>";
        $this->_do('log', [$group]);
    }

    function _groupend($group) {
        echo "\n</div>";
    }

    function _logtime($group) {
        $time = microtime(true) - $this->_starttime[$group];
        $this->_do(($time > 1.0) ? 'warn' : 'log', ["$group time: $time"]);
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
    public  static $headers = [];   // [type=>xml|html,status=>404,encoding=>utf-8|windows-1250|...] - sent headers
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
        $ret = [];
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
        if (isset(AA::$headers['XCTO'])) {
            $ret['XCTO'] = AA::$headers['XCTO'];
        }
        if (isset(AA::$headers['RP'])) {
            $ret['RP'] = AA::$headers['RP'];
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

AA::$debug = ($_GET['debug'] ?: $_COOKIE['aa_debug']);  // aa_debug used in admin interface - item manager - left menu
AA::$dbg   = (AA::$debug[0] == 'f') ? new AA_Debug_Firephp() : ((AA::$debug[0] == 'c') ? new AA_Debug_Console() : new AA_Debug_Console());

// This pair of functions remove the guessing about which of $db $db2
// to use
// Usage: $db = getDB(); ..do stuff with sql ... freeDB($db)
//
$spareDBs     = [];
/** getDB function
 *
 */
function getDB() {
    global $spareDBs;
    if (!($db = array_pop($spareDBs))) {
        $db = new DB_AA(['type' => DB_TYPE, 'host' => DB_HOST, 'database' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASSWORD]);
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
            $val = $db->record();
        } elseif ($values == 'aa_mark') {
            $val = true;
        } elseif (substr($values,0,7) == 'unpack:') {
            $val = unpack_id($db->f(substr($values,7)));
        } elseif (is_string($values) AND array_key_exists( $values, $db->record() )) {
            $val = $db->record($values);
        } else {  // true or 'aa_fields'
            $val = $db->record();
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
        $this->lifetime  = defined('AA_LOGIN_TIMEOUT') ? constant('AA_LOGIN_TIMEOUT') : 1440;   // day
        parent::__construct();
    }

    // add module_id=... to url. It is better to use StateUrl() directly, but we already use StateUrl() from older versions of $session management
    // @deprecated - no longer used in the code
    //    function url($url) {
    //        return StateUrl($url);
    //    }
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
