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
* Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307  USA
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

use AA\Cache\PageCache;
use AA\IO\DB\DB_AA;
use AA\Util\Langs;

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

// should be set in config.php
if (!defined(AA_INSTAL_PATH)) {
    $url_components = parse_url(AA_INSTAL_URL);
    define('AA_INSTAL_PATH', $url_components['path']);
}
// now it is not necessary to have this constant in config.php - we count it from a current file path
if (!defined('AA_BASE_PATH')) {
    define('AA_BASE_PATH', substr(__DIR__, 0, -7));
}


/* Change this to match your database. */
require_once __DIR__."/phplib/phplib.php";
require_once __DIR__."/../vendor/autoload.php";

spl_autoload_register(static function ($class_name) {
    $PAIRS = [
        'AA_Actionapps'      => 'central/include/actionapps.class.php',
        'AA_Auth'            => 'include/locauth.php',
        'AA_Components'      => 'include/statestore.php',
        'AA_Fields'          => 'include/field.class.php',
        'AA\FormArray'       => 'include/widget.class.php',
        'AA_Formatters'      => 'include/widget.class.php',
        'AA_iEditable'       => 'include/statestore.php',
        'AA_Items'           => 'include/item.php',
        'AA_Mailman'         => 'include/mailman.php',
        'AA_Mysqlauth'       => 'include/auth.php',
        'AA_Object'          => 'include/statestore.php',
        'AA_Perm'            => 'include/perm_core.php',
        'AA_Permsystem_Ldap' => 'include/perm_ldap.php',
        'AA_Permsystem_Sql'  => 'include/perm_sql.php',
        'AA_Property'        => 'include/widget.class.php',
        'AA_Poll'            => 'modules/polls/include/poll.class.php',
        'AA_Polls'           => 'modules/polls/include/poll.class.php',
        'sitetree'           => 'modules/site/sitetree.php',
        'AA_Serializable'    => 'include/statestore.php',
        'AA_Storable'        => 'include/statestore.php',
        'Datovka'            => 'misc/datovka/datovka.php',
        'FormrowWidget'      => 'include/form.class.php',
        'ConvertCharset'     => 'include/convert_charset.class.php',
        'Files'              => 'include/files.class.php',
        'zids'               => 'include/zids.php'
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
            require_once __DIR__. '/'. $core. '.class.php';
            return;
        case 'module':
        case 'modulesettings':
            require_once __DIR__ . '/slice.class.php';
            return;
        case 'adminpageutil':
            require_once __DIR__ . '/adminpage.class.php';
            return;
        case 'response':
        case 'clientauth':
        case 'http':
            require_once __DIR__ . '/request.class.php';
            return;
        case 'scroller':
            require_once __DIR__ . '/scroller.php';
            return;
        case 'bookmarks':
            require_once __DIR__ . '/searchbar.class.php';
            return;
        case 'views':
            require_once __DIR__ . '/view.class.php';
            return;
        case 'plannedtask':
            require_once __DIR__. '/../src/AA_Plannedtask.php';
            return;
        case 'trees':
        case 'supertree':
            require_once __DIR__ . '/tree.class.php';
            return;
        case 'array':
            require_once __DIR__ . '/table.class.php';
            return;
        case 'validate':
            require_once __DIR__ . '/validate.php';
            return;
        case 'exportsetings':
            require_once __DIR__ . '/exporter.class.php';
            return;
        case 'formrow':
            require_once __DIR__ . '/form.class.php';
            return;
        case 'file':
        case 'directory':
            require_once __DIR__ . '/files.class.php';
            return;
      }

    $CUSTOM_INC_FILES = [
        'AA_Responder'    => 'responder.php'
    ];

    if (defined('AA_CUSTOM_DIR')) {
        foreach ($CUSTOM_INC_FILES as $inc_def => $inc_file) {
            if (str_starts_with($class_name, $inc_def)) {
                include_once(__DIR__. '/custom/'. AA_CUSTOM_DIR. '/'. $inc_file);
            }
        }
    }
});

class AA_Debug {
    protected array $_starttime = [];
    protected array $_duration   = [];
    protected array $_tracestack = [];
    /** @var array [<level>, <function>, <fnc_param>, <fnc_param_len>, <time_start>, <time_end>, <output>, <output_len>] */
    protected array $_calls      = [];
    protected array $_repeated   = [];

    function __construct() {
        $this->_starttime = ['main' => microtime(true)];
    }

    // we return true, just to be able to write:
    //   AA::$debug&2 && AA::$dbg->info("OK") && exit;
    function log(...$v): bool { $this->tracepoint('log', $v[0]); $this->_do('log',     $v); return true;}
    function info(...$v): bool { $this->tracepoint('info', $v[0]); $this->_do('info',    $v); return true;}
    function warn(...$v): bool { $this->tracepoint('warn', $v[0]); $this->_do('warn',    $v); return true;}
    function error(...$v): bool { $this->tracepoint('error', $v[0]); $this->_do('error',   $v); return true;}

    function group(...$v): bool {
        $group = array_shift($v);
        $this->_starttime[$group] = microtime(true);
        $this->_groupstart($group);
        $this->_do('log', $v);
        return true;
    }

    function groupend(...$v): bool {
        $group = array_shift($v);
        $this->_do('log', $v);
        $this->_logtime($group);
        //$this->duration($group,microtime(true) - $this->_starttime[$group]);
        $this->_groupend($group);
        return true;
    }

    function tracestart($func, $text='') {
        if ($func==='Query') {
            $this->_check_repeated($text);
        }
        $this->_calls[]      = [count($this->_tracestack), $func, $this->shorten_message($text), strlen($text),microtime(true)];
        $this->_tracestack[] = count($this->_calls)-1;
    }

    function traceend(string $func, string $text='') {
        $time = microtime(true);
        $indx = array_pop($this->_tracestack);
        array_push($this->_calls[$indx], $time, substr($text,0,20), strlen($text));

        if (!is_array($this->_duration[$func])) {
            $this->_duration[$func] = [];
        }
        $this->_duration[$func][] = $time-$this->_calls[$indx][4];
    }

    function tracepoint($func, $text='') {
        $text = IsStringable($text) ? (string)$text : print_r($text, true);
        $this->_calls[]      = [count($this->_tracestack), $func, $this->shorten_message($text), strlen($text),microtime(true), microtime(true),'',0];
    }

    function basic_stat(): string {
        global $spareDBs;
        $ret = '<table class=aa-stat>';

        $mem_usage = sprintf('%.3g',(memory_get_usage() / (1024*1024)));
        $mem_peak  = sprintf('%.3g',(memory_get_peak_usage() / (1024*1024)));

        $ret .= '<tr><th>Page generation time</th><td>'. sprintf('%.3g',timestartdiff()).'</td></tr>';
        $ret .= "<tr><th>Memory usage (actual)</th><td>$mem_usage MiB</td></tr>";
        $ret .= "<tr><th>Memory usage (peak)</th><td>$mem_peak MiB</td></tr>";
        $ret .= '<tr><th>Dababase instances</th><td>'. DB_AA::$_instances_no.' ('. DB_AA::getDbType() .')</td></tr>';
        $ret .= '<tr><th>(spareDBs)</th><td>'. count($spareDBs).'</td></tr>';
        return $ret.'</table>';
    }

    function duration_stat(): string {
        $ret = '
<style>
    table.aa-stat {
      width: 100%;
      margin-top: 1rem;
      border-collapse: collapse; 
      border: 1px solid #888; 
      th, td { padding: 6px 4px 2px; }
      th { background-color: #ddd; }
      td { background-color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 50vw; }
      td.num { text-align: right; }
      td.aa-dbgtoggle { display: flex; justify-content: space-between; }
      .aa-line { position: absolute; top: 0; transform: translateY(-50%); height: 4px;}
   }
</style>';

        $row    = [];
        $sumsum = 0;
        $i = 0;
        foreach($this->_duration as $func => $times) {
            $sumsum += ($sum = 1000*array_sum($times));
            $row['a'.sprintf('%f',$sum/100000.0).'-'.$func. ++$i] .= '<tr><td>'.safe($func).'</td><td class=num>'.count($times).'</td><td class=num>'.sprintf('%.4f',$sum/count($times)).'</td><td class=num>'.sprintf('%.4f',$sum).'</td><td class=num>'.sprintf('%.4f',1000*max($times)).'</td><td class=num>'.sprintf('%.4f',1000*min($times)).'</td></tr>';
        }
        krsort($row);
        $ret .= '<table class="aa-stat sortable"><thead><tr><th>function</th><th data-sort=integer>called</th><th data-sort=float>avg</th><th data-sort=float>sum</th><th data-sort=float>max</th><th data-sort=float>min</th></tr></thead><tbody>'.implode('',$row).'</tbody><tfoot><tr><th data-sort=float>Sum</th><th></th><th></th><th>'.$sumsum.'</th><th></th><th></th></tr></tfoot></table>';


        // repeated queries;
        $row    = [];
        $i = 0;
        foreach($this->_repeated as $query => $times) {
            if ($times>1) {
                $row['a' . sprintf('%.09d', $times). ++$i] = '<tr><td class=num>' . safe($times) . '</td><td>' . safe($query) . '</td></tr>';
            }
        }
        if ($row) {
            krsort($row);
            $ret .= '<table class="aa-stat sortable"><tr><th data-sort=integer>used x times</th><th>query</th></tr>' . implode('', $row) . '<tr></table>';
        }

        // longest queries;
        $row    = [];
        $i = 0;
        foreach($this->_calls as $call) {
            if ($call[1]==="Query") {
                $time = sprintf("%.4f", (1000*($call[4]-$_SERVER['REQUEST_TIME_FLOAT'])));
                $row['a' . sprintf('%012.5f',($call[5]-$call[4])*1000.0). ++$i] = '<tr><td class=num>'.$i.'</td><td class=num>'.$time. '</td><td class=num>'.sprintf('%.4f',($call[5]-$call[4])*1000.0) . '</td><td>' . safe($call[2]) . '</td><td class=num>' . safe($call[6]) . '</td></tr>';
            }
        }
        if ($row) {
            krsort($row);
            $ret .= '<table class="aa-stat sortable"><tr><th data-sort=integer>#</th><th data-sort=float>time</th><th data-sort=float>duration</th><th>query</th><th data-sort=integer>out</th></tr>' . implode('', $row) . '<tr></table>';
        }

        $ret .= '<table class="aa-stat sortable"><tr><th data-sort=float>time</th><th data-sort=float>duration</th><th>function</th><th>in</th><th data-sort=integer>out</th></tr>';

        $global_start = $this->_calls[0][4];
        $global_end   = $this->_calls[count($this->_calls)-1][5];
        $global_dur   = $global_end - $global_start;

        // get max of $this->_calls[0][1] (level)
        $max_level = 1;
        if ($this->_calls) {
            $max_level = max(array_column($this->_calls, 0)) ?: 1;
        }

        foreach($this->_calls as $key => $call) {
            $tr_style = 'position: relative;';
            $level = $call[0];
            $next_level = array_key_exists($key+1,$this->_calls) ? $this->_calls[$key+1][0] : 0;
            $style = $level>0 ? " style=\"display: none; $tr_style\"" : " style=\"$tr_style\"";
            $time = sprintf("%.4f", (1000*($call[4]-$_SERVER['REQUEST_TIME_FLOAT'])));
            $dur  = ($call[5]-$call[4]);
            $color = '#0080'. str_pad(dechex((255 * $level / $max_level) % 256), 2, '0', STR_PAD_LEFT);
            $rel_start = round(100*($call[4]-$global_start)/$global_dur,2);
            $rel_dur   = round(100*($dur)/$global_dur,2);
            $func    = str_repeat('.&nbsp;',$level).safe($call[1]);
            $dur = sprintf("%.4f", 1000*$dur);
            $expand = ($next_level>$level) ? '<button type=button onclick="AA_DbgToggleRows(this)">'.($level>0 ? '-':'+').'</button>' : '';
            $linediv = "<div class=aa-line style=\"left: $rel_start%; width: $rel_dur%; background-color: $color\"></div>";
            $ret .= "<tr title=\"{$func} ($dur)\" class=l".str_repeat('_', $level). $style."><td class=num>{$linediv}{$time}</td><td class=num>$dur</td><td class=aa-dbgtoggle>$func{$expand}</td><td>".safe($call[2]).( ($call[3]>200 AND !AA::$debug&128) ? '..+'.($call[3]-200) :'' ).'</td><td>'.safe($call[6]).($call[7]>20? '..+'.($call[7]-20) :'' ).'</td></tr>';
        }
        $ret .= '</table>';

        $ret .= <<<EOF
              <script>
                function AA_DbgToggleRows(button) {
                    let isVisible = (button.textContent === '-');
                    let nextRows = button.parentNode.parentNode.nextElementSibling;
                    while (nextRows && nextRows.className.startsWith(button.closest('tr').getAttribute('class')+'_')) {
                        nextRows.style.display = isVisible ? 'none' : '';
                        nextRows = nextRows.nextElementSibling;
                    }
                    button.textContent = isVisible ? '+' : '-';
                }
                // document.querySelectorAll("tr[class^='l_'] button").forEach(function(el) { el.click(); });
              </script>
EOF;

        $ret .= AA::Contentcache()->duration_stat();
        $ret .= StrExp('sortable');

        return $ret;
    }

    /** internal function to shorten log messages for debug output based on AA::$debug level */
    protected function shorten_message(string $txt): string
    {
        if (!(AA::$debug & 128)) {
            $txt = substr($txt, 0, 200);
        }
        if (AA::$debug&256) {
            $txt .= "\n<br>".AA_Log::backtrack();
            $txt .= "\n<br>DBs ".DB_AA::$_instances_no;
        }
        return $txt;
    }

    function _check_repeated($txt) {
        ++$this->_repeated[$txt];
    }

    function _do($func, $params) {
        $time = microtime(true) - $this->_starttime['main'];
        echo "<small><em>$time</em></small><br>\n";

        foreach ($params as $a) {
            if (false AND is_object($a) && is_callable([$a,"__toString"])) {
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
    public  static $slice_id;            // optional - filled by view.php when site_id is not available (so it is used as allpage main module to find {_:alias} aliases)
                                         //             or for Saver to pass validator the current slice of added item for slice unique
    public  static $encoding;            // inner module's/slice's encoding (utf-8, ...)
    public  static $lang;                // two letters small caps - cz / es / en / ...
    public  static $langnum;             // array of prefered language numbers - > 10000000
    public  static $headers = [];        // [type=>xml|html,status=>404,encoding=>utf-8|windows-1250|...] - sent headers
    public  static $module_id;           // for admin pages - replace of older $slice_id
    /** @var  AA_Stringexpander $stringexpander */
    private static $stringexpander = null;
    /** @var  AA_Contentcache $contentcache */
    private static $contentcache   = null;
    /** @var  PageCache $pagecache */
    private static $pagecache      = null;
    /** @var  AA_Metabase $metabase */
    private static $metabase       = null;

    static function getHeaders(): array {
        $ret = [];
        $ret['type'] = 'Content-Type: '. (AA::$headers['type'] ?: 'text/html') .'; charset='.(AA::$headers['encoding'] ?: AA::$encoding ?: Langs::getCharset());
        if (isset(AA::$headers['status'])) {
            $ret['status'] = AA::$headers['status'];
        }
        if (isset(AA::$headers['disposition'])) {
            $ret['disposition'] = 'Content-Disposition: '.AA::$headers['disposition'];
        }
        foreach (['CSP','CSP-RO','RT','NEL','XSS','XCTO','RP','CORS','CORSH'] as $possibleheader) {  // @todo CORS
            if (isset(AA::$headers[$possibleheader])) {
                $ret[$possibleheader] = AA::$headers[$possibleheader];
            }
        }
        return $ret;
    }

    /** @usage AA::setEncoding($module->getCharset());
     */
    static function setEncoding($encoding) {
        AA::$encoding = $encoding;
        mb_internal_encoding(Langs::isUTF($encoding) ? 'UTF-8' : '8bit');
    }


    /** @usage AA::Un();
     *  @return  AA_Stringexpander
     */
    static function Stringexpander(): AA_Stringexpander {
        return AA::$stringexpander ?: (AA::$stringexpander = new AA_Stringexpander());
    }

    /** shortcut for AA::Un()
     *  @usage AA::Un($text);
     */
    static function Un($text, $remove='', $item=null, $dequote=true, $itemview=null ): string  {
        return AA::Stringexpander()->unalias($text, $remove, $item, $dequote, $itemview);
    }

    /** @usage AA::Contentcache()->set();
     *  @return  AA_Contentcache
     */
    static function Contentcache(): AA_Contentcache {
        return AA::$contentcache ?: (AA::$contentcache = new AA_Contentcache());
    }

    /** @usage AA::Pagecache()->invalidateFor();
     *  @return  Pagecache
     */
    static function Pagecache(): PageCache {
        return AA::$pagecache ?: (AA::$pagecache = new PageCache(CACHE_TTL));
    }

    /** @usage AA::Metabase()->doInsert('log', ['time'=>time(),'user'=>$uid, 'type'=>$event, 'selector'=>$selector, 'params'=>$params]);
     *  @return  AA_Metabase
     */
    static function Metabase(): AA_Metabase {
        return AA::$metabase ?: (AA::$metabase = AA_Metabase::singleton());
    }

    /** @usage AA::Session()->register("tview");
     *  @deprecated 
     *  @return  AA_Session
     */
    static function Session() {
        return $GLOBALS['sess'];
        //return AA::$session ?: (AA::$session = ($GLOBALS['sess'] ?: new AA_Session));
    }

    // typically called as AA::sendHeaders(AA::getHeaders());
    static function sendHeaders(array $headers) {
        foreach ($headers as $header) { header($header); }
    }
}

AA::$debug = (int)($_REQUEST['debug'] ?: $_COOKIE['aa_debug']);  // aa_debug used in admin interface - item manager - left menu
AA::$dbg   = (AA::$debug[0] === 'f') ? new AA_Debug_Firephp() : ((AA::$debug[0] === 'c') ? new AA_Debug_Console() : new AA_Debug_Console());

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
        $type = DB_TYPE;
        //$type = ($_GET['mysqli']==1) ? 'db_mysgl': 'db_pdo:mysql'; //DB_TYPE; // : 'db_pdo:mysql';,
        //$type = ($_GET['mysqli']==1) ? 'db_mysgl': DB_TYPE;        //DB_TYPE; // : 'db_pdo:mysql';
        $connection = [
            'type'          => $type,
            'host'          => DB_HOST,
            'database'      => DB_NAME,
            'user'          => DB_USER,
            'password'      => DB_PASSWORD,
            'charset'       => (defined('DB_CHARACTER_SET') AND DB_CHARACTER_SET) ? DB_CHARACTER_SET : '',
            'collation'     => (defined('DB_COLLATION_CONNECTION') AND DB_COLLATION_CONNECTION) ? DB_COLLATION_CONNECTION : '',
            'persistent'    => (defined('AA_USE_NON_PERSISTENT_CONNECT') AND AA_USE_NON_PERSISTENT_CONNECT) ? false : true
        ];
        $db = new DB_AA($connection);
    }
    return $db;
}

/** freeDB function
 * @param $db
 */
function freeDB($db) {
    $GLOBALS['spareDBs'][] = $db;
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
        if ($values === 'aa_all') {
            $val = $db->record();
        } elseif ($values === 'aa_mark') {
            $val = true;
        } elseif (str_starts_with($values, 'unpack:')) {
            $val = unpack_id($db->f(substr($values,7)));
        } elseif (is_string($values) AND array_key_exists( $values, $db->record() )) {
            $val = $db->record($values);
        } else {  // true or 'aa_fields'
            $val = $db->record();
            // $val = DBFields($db);  // I changed the mysql_fetch_array($this->Query_ID, MYSQL_ASSOC) in db_mysql by adding MYSQL_ASSOC, so DBFields is no longer needed
        }

        if ($key === 'aa_first') {
            freeDB($db);
            return $val;
        }

        if (($key === "NoCoLuMn") OR !$key) {
            $arr[] = $val;
        } elseif (str_starts_with($key, 'unpack:')) {
            $arr[unpack_id($db->f(substr($key,7)))] = $val;
        } else {
            $arr[$db->f($key)] = $val;
        }
    }
    freeDB($db);
    return $arr ?? false;
}

function AuthGetUid(): string {
    /** @var $auth AA_Auth */
    global $auth;
    return is_object($auth) ? $auth->getUid() : '';
}

function AuthGetUname(): string {
    /** @var $auth AA_Auth */
    global $auth;
    return is_object($auth) ? $auth->getUname() : '';
}

class AA_Session extends Session {
    function __construct() {
        $this->lifetime  = defined('AA_LOGIN_TIMEOUT') ? constant('AA_LOGIN_TIMEOUT') : 1440;   // day
        parent::__construct();
        // in AA_Session we also start it
        $this->start();
    }
}

function pageOpen($type = '') {
    global $sess, $auth;

    $sess = new AA_Session;

    if ($type !== 'noauth') {
        if (!is_object($auth)) {
            $auth = new AA_Auth;
        }
        $auth->set_nobody($type==='nobody');
        $auth->start();
    }
}
