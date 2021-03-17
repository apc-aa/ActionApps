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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/
//
// Miscellaneous utility functions
//

use AA\IO\DB\DB_AA;
use AA\Util\SingletonTrait;

require_once __DIR__. '/locsess.php3';
require_once __DIR__. '/constants.php3';
require_once __DIR__. '/mgettext.php3';
require_once __DIR__. '/zids.php3';
require_once __DIR__. '/logs.php3';
require_once __DIR__. '/go_url.php3';
require_once __DIR__. '/formutil.php3';

/** identity function - used for chaining with constructor
 *  Usage: (new AA_Some_Object())->set(something);
 * @param object $object
 * @return object
 * @deprecated no longer needed - in PHP 5.4 we have (new My_Object())->method() call
 */
function with(object $object): object {
    return $object;
}

/** a_href function
 *  Get <a href> tag
 * @param        $url
 * @param        $txt
 * @param string $class
 * @param array  $attr    ['onclick' => "return confirm('Are you sure?')"]
 * @return string
 */
function a_href($url, $txt, $class='', array $attr=[]): string {
    $add  = $class ? " class=\"$class\"" : '';
    $add .= array_reduce( array_keys($attr), function ($str, $key) use ($attr) { return $str . ' ' . $key . '="' . myspecialchars( $attr[$key] ) . '"'; });
    return '<a href="'.myspecialchars($url) ."\" $add>$txt</a>";
}

/** expand_return_url function
 * Expand return_url, possibly adding a session to it
 * @param $session
 * @return mixed|string
 */
function expand_return_url($session=true): string {
    global $return_url, $sess;
    return ($session AND is_object($sess)) ? StateUrl($return_url) : $return_url;
}

/** go_return_or_url function
 *  This function goes to either $return_url if set, or to $url
 * if $usejs is set, then it will use inline Javascript, its not clear why this is done
 *    sometimes (item.php3) but not others.
 *    session is always added to the other case
 * if $add_param are set, then they are added to the cases EXCEPT return_url
 * @param $url
 * @param $usejs
 * @param $session
 * @param $add_param
 */
function go_return_or_url($url, $usejs=false, $session=false) {
    global $return_url;

    if ($return_url) {
        go_url(expand_return_url($session), '', $usejs);
    } elseif ($url) {
        go_url(StateUrl($url));
    }
    // Note if no $url or $return_url then drops through - this is used in index.php3
}

/** endslash function
 *  Adds slash at the end of a directory name if it is not yet there.
 * @param $s
 */
function endslash(&$s) {
    if (strlen($s) AND substr($s,-1) != "/") {
        $s .= "/";
    }
}

/** backslash quotes, remove newlines, escape </script, which will make the code broken
 *  use as: echo "el.insertAdjacentHTML('afterbegin',".escape4js($code).");";
 */
function escape4js($code) {
    return str_replace( ["\\", "'","\r\n","\n","\r",'<script','</script'], ["\\\\", "\\'",'\n','\n','\n','\x3Cscript','\x3C/script'], $code );   // remove newlines ...
}

/** array_add function
 *  adds all items from source to target, but doesn't overwrite items
 * @param $source
 * @param $target
 */
function array_add($source, &$target) {
    foreach ( (array)$source as $k => $v) {
        if (!isset($target[$k])) {
            $target[$k] = $v;
        } else {
            $target[]   = $v;
        }
    }
}

/** self_server function
 *  returns server name with protocol and port
 */
function self_server() {
    if ( isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ) {
        $PROTOCOL='https';
        if ($_SERVER['SERVER_PORT'] != "443") {
            $port = ':'. $_SERVER['SERVER_PORT'];
        }
    } else {
        $PROTOCOL='http';
        if ($_SERVER['SERVER_PORT'] != "80") {
            $port = ':'. $_SERVER['SERVER_PORT'];
        }
    }
    // better to use HTTP_HOST - if we use SERVER_NAME and we try to open window
    // by javascript, it is possible that the new window will be opened in other
    // location than window.opener. That's  bad because accessing window.opener
    // then leads to access denied javascript error (in IE at least)
    $sname = $_SERVER['HTTP_HOST'] ?: $_SERVER['SERVER_NAME'];
    return("$PROTOCOL://$sname$port");
}

/** self_base function
 * returns server name with protocol, port and current directory of php script
 */
function self_base() {
    return (self_server(). preg_replace('~/[^/]*$~', '', $_SERVER['PHP_SELF']) . '/');
}

/** document_uri function
 *  On some servers isn't defined DOCUMENT_URI
 *   Ecn - when rewrite is applied - http://privatizacepraha2.cz/cz/aktuality/2084368
 *   and somwhere nor REDIRECT_URL
 *   (canaca.com 2003-09-19 - Apache/1.3.27 (Unix) (Red-Hat/Linux), Honza)
 */
function document_uri() {
    return get_if($_SERVER['DOCUMENT_URI'],$_SERVER['REDIRECT_URL'],$_SERVER['SCRIPT_URL']);
}

/** shtml_base function
 *  returns server name with protocol, port and current directory of shtml file
 */
function shtml_base() {
    return (self_server(). preg_replace('~/[^/]*$~', '', document_uri()) . '/');
}

/** shtml_url function
 * returns url of current shtml file
 */
function shtml_url() {
    return (self_server(). document_uri());
}

/** shtml_query_string function
 *  returns query string passed to shtml file (variables are not quoted)
 */
function shtml_query_string() {
    // there is problem (at least with QUERY_STRING_UNESCAPED), when
    // param=a%26a&second=2 is returned as param=a\\&a\\&second=2 - we can't
    // expode it! - that's why we use $REQUEST_URI, if possible

    // get off magic quotes

    return ($_SERVER['REQUEST_URI'] AND strpos($_SERVER['REQUEST_URI'],'?')) ?
                        substr($_SERVER['REQUEST_URI'],strpos($_SERVER['REQUEST_URI'], '?')+1) :
                  ( isset($_SERVER['REDIRECT_QUERY_STRING_UNESCAPED'])    ?
                        stripslashes($_SERVER['REDIRECT_QUERY_STRING_UNESCAPED']) :
                        stripslashes($_SERVER['QUERY_STRING_UNESCAPED']) );
}

/** check, if the script was called as ajax call */
function IsAjaxCall() {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) AND strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
}

/** DeBackslash function
 *  skips terminating backslashes
 * @param $txt
 * @return mixed
 */
function DeBackslash($txt) {
    return str_replace('\\', "", $txt);        // better for two places
}

/** ParamImplode function
 * @param $param
 * @return string
 */
function ParamImplode($param) {
    if (!is_array($param)) {
        return (string)$param;
    }
    array_walk($param, function (&$v) {$v = str_replace(':', '#:', $v);});
    return implode(":", $param);
}

/** Converts float value into php form of float */
function PhpFloat($value) {
    // internaly uses PHP float always with decimal point so we have to convert
    // twice, since (float) typecasting is unfortunatelly LOCALE dependent. Grr
    return str_replace(',', '.',(float)str_replace(',', '.', trim($value)));
}

function ConvertEncodingDeep($value, $from=null, $to=null) {
    $from = $from ?: 'UTF-8';
    $to   = $to   ?: 'UTF-8';
    array_walk_recursive(
        $value,
        function (&$entry) use ($from, $to) {
            $entry = iconv($from, "$to//TRANSLIT", $entry);
        }
    );

    // mb_convert_variables($to, $from, $value);   // does not work with windows-1250
    return $value;
}

function ConvertEncoding($str, $from, $to='UTF-8') {
    $from = $from ?: 'UTF-8';
    $to   = $to   ?: 'UTF-8';
    //return function_exists('mb_convert_encoding') ? mb_convert_encoding($str, $to, $from) : iconv($from, "$to//TRANSLIT",$str);  // does not work with windows-1250
    return iconv($from, "$to//TRANSLIT", $str);
}

/** add_vars function
 *  Adds variables passed by QUERY_STRING_UNESCAPED (or user $query_string)
 *   to GLOBALS.
 * @param $query_string
 * @return array|mixed
 */
function add_vars($query_string="", $where='GLOBALS') {
    $varstring = ( $query_string ? $query_string : shtml_query_string() );

    if ( !$varstring ) {
        return [];
    }
    if ( ($pos = strpos('#', $varstring)) === true ) {  // remove 'fragment' part
        $varstring = substr($varstring,0,$pos);
    }

    parse_str($varstring, $aa_query_arr);
    // we need PHP to think a['key'] is the same as a[key], that's why we
    // call NormalizeArrayIndex()

    // we do not want to replace sess variable, since we use it for sessions
    unset($aa_query_arr['sess']);
    $aa_query_arr = NormalizeArrayIndex($aa_query_arr);
    if (is_array($aa_query_arr) ) {
        // use of $$where do not work for some reason
        switch ($where) {
            case '_REQUEST': array_merge_append($_REQUEST, $aa_query_arr);
                             break;
            case 'return':   break;
            default:         array_merge_append($GLOBALS, $aa_query_arr);
        }
        return $aa_query_arr;
    }
    return [];
}


/** NormalizeArrayIndex function
 *  Removes starting and closing quotes from array index
 * @param $arr ["key"]=...   transforms to arr[key]=...
 * @return mixed
 */
function NormalizeArrayIndex($arr) {
    if (!is_array($arr)) {
        return $arr;
    }
    $ret = [];
    foreach ($arr as $k => $v) {
        if ( (($k{0}=='"') AND (substr($k,-1)=='"')) ||
             (($k{0}=="'") AND (substr($k,-1)=="'")) ) {
            $k = substr($k, 1, -1);
        }
        $ret[$k] = NormalizeArrayIndex($v);
    }
    return $ret;
}

/** array_merge_append function
 *  Adds second array to the first one - values are appended to the array, if
 *  uses the same key (regardless if string or numeric!)
 * @param $array
 * @param $newValues
 * @example:
 *    array_merge_append( $conds[0]['value']=x, $conds[0][operator]=LIKE )
 *    results in $conds[0] = array( 'value'=>'x', 'operator'=>'LIKE' )
 *  no PHP function do it ($a+$b nor array_merge()  array_merge_recursive())
 * @return mixed
 */
function array_merge_append(&$array, $newValues) {
    foreach ($newValues as $key => $value ) {
        if ( !isset($array[$key]) || !is_array($array[$key]) || !is_array($value)) {
            $array[$key] = $value;
        } else {
            $array[$key] = array_merge_append($array[$key], $value);
        }
    }
    return $array;
}


function recursive_array_replace($find, $replace, $array) {
    if (!is_array($array)) {
        return str_replace($find, $replace, $array);
    }
    $newArray = [];
    foreach ($array as $key => $value) {
        $newArray[$key] = recursive_array_replace($find, $replace, $value);
    }
    return $newArray;
}

/** AddslashesArray function
 * function addslashes enhanced by array processing
 * @param $value
 * @return array|string
 */
function AddslashesArray($value) {
    return is_array($value) ? array_map('AddslashesArray', $value) : addslashes($value);
}

function StripslashesArray($value) {
    return is_array($value) ? array_map('StripslashesArray', $value) : stripslashes($value);
}

/** QuoteVars function
 * function for processing posted or get variables
 * adds quotes, if magic_quotes are switched off
 * except of variables in $skip array (usefull for 'encap' for example)
 * @param $method
 * @param $skip
 */
function QuoteVars($method="get", $skip='') {
    $arr = ($method == "get") ? $_GET : $_POST;
    foreach ($arr as $k => $v) {
        if ( !is_array($skip) OR !isset($skip[$k]) ) {
            $GLOBALS[$k] = AddslashesArray($v);
        }
    }
}

/** new_id function
 *  returns new unpacked md5 unique id, except these which can  force unexpected end of string
 * @param $mark
 * @return mixed|string
 */
function new_id($mark=0){
    do {
        $id = hash('md5', uniqid('',true));
    } while ((strpos($id, '00')!==false) OR (strpos($id, '27')!==false) OR (substr($id,30,2)=='20'));
      // '00' is end of string, '27' is ' and packed '20' is space,
      // which is removed by MySQL

    // the condition above is too restrictive, since it do not allow also ids
    // like 30049391... (00 on odd position), which makes no problem in packing
    // That allow us to "mark" some ids, so we can distinguish, that belongs to ...
    // We have 3*15=45 marks, first 15 are implemented

    // mark 1 used for AA_Set used in groups of readers - permission related
    if ($mark>0) {
        // 27 is first, since it can't create any secondary problems like '00' and '20' could (123056 => 100056...)
        return substr_replace($id, '27', $mark*2-1, 2);
    }
    return $id;
}

/** is_marked_by funcion
 * @param $id
 * @param $mark
 *  @return true, if the $id is marked by $mark - @see new_id()
 */
function is_marked_by($id, $mark) {
    // now only supports mark 1-15
    return (substr($id, $mark*2-1, 2) == '27');
}


/** string2id function
 * @param $str
 *  @return string - unique (long - unpacked) id from a string.
 *  Note that it will always return the same id from the same string so it
 *  can be used to compare the hashes as well as create new item id (combining
 *  item id of fed item and slice_id, for example - @see xml_fetch.php3)
 */
function string2id($str) {
    do {
        $id  = hash('md5',$str);
        $str .= " ";
    } while ((strpos($id, '00')!==false) OR (strpos($id, '27')!==false) OR (substr($id,30,2)=='20'));
      // '00' is end of string, '27' is ' and packed '20' is space,
      // which is removed by MySQL
    return $id;
}

/** now function
 *  returns current date/time as timestamp;
 * @param $step - time could be returned in steps (good for database query speedup)
 * @return float|int
 */
function now($step=false) {
    return (($step!='step') ? time() : ((int)(time()/QUERY_DATE_STEP)+1)*QUERY_DATE_STEP);     // round up
}

/** debug function
 *  variable count of variables
 * @param mixed ...$messages
 */
function debug(...$messages) {
    // could be toggled from Item Manager left menu 'debug' (by Superadmins!)
    if ( $_COOKIE['aa_debug'] != 1 ) {
        return;
    }
    foreach ( $messages as $msg ) {
        AA::$dbg->log($msg);
    }
}

/** Debug warn function - when something is not OK
 * @param string $msg
 */
function warn($msg) {
    if (AA::$debug) {
        $trace = debug_backtrace();
        AA::$dbg->warn( join('->', [$trace[1]['class'],$trace[1]['function']]).': '.$msg );
    }
}

/** huhe function
 * Report only if errcheck is set, this is used to test for errors to speed debugging
 * Use to catch cases in the code which shouldn't exist, but are handled anyway.
 * @param mixed ...$arg_list
 */
function huhe(...$arg_list) {
    global $errcheck;
    if ($errcheck) {
        call_user_func_array('huhl', $arg_list);
    }
}

/** return time from start of the script (page start) in seconds (float)
 * @return float
 */
function timestartdiff() {
    $start_time = $_SERVER['REQUEST_TIME_FLOAT'] ?: (float)$_SERVER['REQUEST_TIME'];  // FLOAT version is not in PHP <5.4
    return microtime(true) - $start_time;
}

// Set a starting timestamp, if checking times, huhl can report
/** huhl function
 * Debug function to print debug messages recursively - handles arrays
 */
function huhl(...$vars) {
    AA::$dbg->log(AA_Log::backtrack());
    foreach ($vars as $var) {
        AA::$dbg->log($var);
    }
}

function GetPrintArray($a) {
    $ret = '';
    if (is_array($a)) {
        foreach ( $a as $val) {
            $ret .=  is_array($val) ? GetPrintArray($val) : "<div>$val</div>";
        }
    }
    return $ret;
}

/** PrintArray function
 * @param $a
 * Prints all values from array
 */
function PrintArray($a) {
    echo GetPrintArray($a);
}

/** MsgOK function
 * Prepare OK Message
 * @param $txt
 * @return string
 */
function MsgOk($txt){
    return "<div class=\"okmsg\">$txt</div>";
}

/** MsgERR function
 * Prepare Err Message
 * @param $txt
 * @return string
 */
function MsgErr($txt){
    return "<div class=\"err\">$txt</div>";
}

/** GetConstants function
 *  Function fills the array from constants table
 * @param        $group
 * @param string $order
 * @param string $column - column used as values. We can use 'name' as well as
 *                       'const_name' for name of fields
 * @param string $keycolumn
 * @return array
 */
function GetConstants($group, $order='pri', $column='name', $keycolumn='value'): array {
    // we can use 'const_name' instedad of real name of the column 'name' => translate
    $order     = str_replace( 'const_', '', $order);
    $column    = str_replace( 'const_', '', $column);
    $keycolumn = str_replace( 'const_', '', $keycolumn);

    $db_order     = $order;
    $db_column    = ($column    == 'level') ? 'ancestors' : $column;
    $db_keycolumn = ($keycolumn == 'level') ? 'ancestors' : $keycolumn;

    $const_fields = ['id'=>1,'group_id'=>1,'name'=>1,'value'=>1,'class'=>1,'pri'=>1,'ancestors'=>1,'description'=>1,'short_id'=>1];

    $db = getDB();
    if (  $const_fields[$db_order] ) {
        $order_by  = "ORDER BY $db_order";
    }
    if ( !$const_fields[$db_column] ) {
        $db_column    = 'name';  $column    = 'name';
    }
    if ( !$const_fields[$db_keycolumn] ) {
        $db_keycolumn = 'value'; $keycolumn = 'value';
    }
    $fields = ($db_column==$db_keycolumn ? $db_column : "$db_keycolumn, $db_column");

    $SQL = "SELECT $fields FROM constant WHERE group_id='$group' $order_by";
    $db->query($SQL);

    $already_key = [];
    $arr = [];
    while ($db->next_record()) {
        $key = GrabConstantColumn($db, $keycolumn);
        $key = $key['value'];
        // generate unique keys by adding space
        while ( $already_key[$key] ) {
            $key .= ' ';                   // add space in order we get unique keys
        }
        $already_key[$key] = true;       // mark the $key
        $val               = GrabConstantColumn($db, $column);
        $arr[$key]         = $val['value'];
    }
    freeDB($db);
    return $arr;
}

/** GetModuleInfo function
 * gets fields from main table of the module
 * @param $module_id
 * @param $type
 * @deprecated - use AA_Slice::getModule($slice_id) and silmilar
 * @return array|bool|string
 */
function GetModuleInfo($module_id, $type) {
    global $MODULES;
    if (!$module_id) {
        return false;
    }

    // $SQL = "SELECT * FROM " .$MODULES[$type]['table']. " WHERE id = '$p_module_id'";
    // $ret = GetTable2Array($SQL, 'aa_first', 'aa_fields');
    $ret = DB_AA::select1([], "SELECT * FROM " . $MODULES[$type]['table'], [['id', $module_id, 'l']]);

    if ( $ret AND $ret['reading_password'] ) {
        // do it more secure and do not store it plain
        // (we should be carefull - mainly with debug outputs)
        $ret['reading_password'] = AA_Credentials::encrypt($ret['reading_password']);
    }
    return $ret;
}

/** GetSliceInfo function
 *  gets slice fields
 * @param $slice_id
 * @deprecated - use AA_Slice::getModule($slice_id) and silmilar
 * @return array|bool|string
 */
function GetSliceInfo($slice_id) {
    return GetModuleInfo($slice_id,'S');
}

// -------------------------------------------------------------------------------

/** CreateBinCondition function
 *  Returns part of SQL command used in where related to bins
 * @param        $bin
 * @param string $table table name
 * @param bool   $ignore_expiry_date
 * @return string
 */
function CreateBinCondition($bin, $table, $ignore_expiry_date=false) {
    // now is rounded in order the time is in steps - it is better for search
    // caching - SQL is THE SAME during one time step
    $now = now('step');            // round up

    /* new version of bin selecting, now we use type of bin from constants.php3 */
    if (ctype_digit((string)$bin)) {
        /* $bin is numeric constant */
        $numeric_bin = max(1,$bin);
    } elseif (is_string($bin)) { /* for backward compatibility */
        switch ($bin) {
            /* assign to string type it's numeric constant */
            case 'ACTIVE'  : $numeric_bin = AA_BIN_ACTIVE;  break;  // 1
            case 'PENDING' : $numeric_bin = AA_BIN_PENDING; break;  // 2
            case 'EXPIRED' : $numeric_bin = AA_BIN_EXPIRED; break;  // 4
            case 'HOLDING' : $numeric_bin = AA_BIN_HOLDING; break;  // 8
            case 'TRASH'   : $numeric_bin = AA_BIN_TRASH;   break;  // 16
            case 'ALL'     : $numeric_bin = (AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH); break;
            default        : $numeric_bin = AA_BIN_ACTIVE;  break;  // 1
        }
    } else {
        /* strange case, I think never possible :) */
        $numeric_bin = AA_BIN_ACTIVE;
    }

    /* create SQL query for different types of numeric constants */
    switch ($numeric_bin) {
        case AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH :
            return ' 1=1 ';
        case AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING:
            return " $table.status_code=1 ";
        case AA_BIN_ACTIVE | AA_BIN_EXPIRED:
            return " $table.status_code=1 AND ($table.publish_date <= '$now') ";
        case AA_BIN_ACTIVE | AA_BIN_PENDING:
            return " $table.status_code=1 AND ($table.expiry_date > '$now') ";
    }
    $or_conds = [];
    if (($numeric_bin & (AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING)) == (AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING)) {
        $or_conds[] = " $table.status_code=1 ";
    } else {
        if ($numeric_bin & AA_BIN_ACTIVE) {
            $SQL = " $table.status_code=1 AND $table.publish_date <= '$now' ";
            /* condition can specify expiry date (good for archives) */
            if ( !( $ignore_expiry_date && defined("ALLOW_DISPLAY_EXPIRED_ITEMS") && ALLOW_DISPLAY_EXPIRED_ITEMS) ) {
                //              $SQL2 .= " AND ($table.expiry_date > '$now' OR $table.expiry_date IS NULL) ";
                $SQL .= " AND $table.expiry_date > '$now' ";
            }
            $or_conds[] = $SQL;
        }
        if ($numeric_bin & AA_BIN_EXPIRED) {
           $or_conds[] = " $table.status_code=1 AND $table.expiry_date <= '$now' ";
        }
        if ($numeric_bin & AA_BIN_PENDING) {
           $or_conds[] = " $table.status_code=1 AND $table.publish_date > '$now' AND expiry_date > '$now'";
        }
    }
    if ($numeric_bin & AA_BIN_HOLDING) {
        $or_conds[] = " $table.status_code=2 ";
    }
    if ($numeric_bin & AA_BIN_TRASH) {
        $or_conds[] = " $table.status_code=3 ";
    }
    switch (count($or_conds)) {
        case 0:  return ' 1=1 ';
        case 1:  return ' '. $or_conds[0] .' ';
    }
    return ' (('. join(') OR (', $or_conds) .')) ';
}


/** GetItemContent function
 * Basic function to get item content. Use this function, not direct SQL queries.
 * @param       $zids
 * @param       $unused_use_short_ids // no longer used
 * @param bool  $ignore_reading_password
 *       Use carefully only when you are sure the data is used safely and not viewed
 *       to unauthorized persons.
 * @param array $fields2get
 *       restrict return fields only to listed fields (so the content4id array
 *       is not so big)
 *       like: array('headline........', 'category.......1')
 * @return array
 */
function GetItemContent($zids, $unused_use_short_ids=false, $ignore_reading_password=false, $fields2get=false, $crypted_additional_slice_pwd=null, $bin=null) {
    // Fills array $content with current content of $sel_in items (comma separated ids).

    // construct WHERE clause
    if ( !is_object($zids) ) {
        $zids = new zids( $zids, 'l' );
    }

    if (!$zids->count()) {
        return null;
    }

    AA::$debug&32 && AA::$dbg->tracestart('GetItemContent', $zids->count());

    // get content from item table

    $use_short_ids = ($zids->onetype() == 's');
    $metabase      = AA::Metabase();

    // if the output fields are restricted, restrict also item fields
    if ( $fields2get ) {

        $content_fields = [];
        $item_fields    = [];
        foreach ( (array)$fields2get as $field_name ) {
            //convert publish_date.... to publish_date
            $clean_name = AA_Fields::getFieldType($field_name);

            if ( $metabase->isColumn('item', $clean_name) ) {
                $item_fields[]    = $clean_name;
            } else {
                $content_fields[] = $field_name;
            }
        }

        // save it (without possible new fields 'id' and 'slice_id' - see below)
        $real_item_fields2get = $item_fields;

        // we need item id for $content index
        if ( !in_array('id', $item_fields) ) {
            $item_fields[] = 'id';
        }
        if ( $use_short_ids AND !in_array('short_id', $item_fields) ) {
            $item_fields[] = 'short_id';
        }

        // we need slice_id for each item, if we have to count with slice permissions
        if ( !$ignore_reading_password AND !in_array('slice_id', $item_fields) ) {
            $item_fields[] = 'slice_id';
        }

        $item_fields_sql = join(',', $item_fields);
    } else {
        $item_fields_sql = '*';
        $real_item_fields2get = $metabase->getColumnNames('item');
    }

    $SQL = "SELECT $item_fields_sql FROM item WHERE ". $zids->sqlin();

    // when we contruct tree, we want to use only current item, for example
    if (!is_null($bin)) {
        $SQL .= ' AND '. CreateBinCondition($bin, 'item');
    }
    $db = getDB();
    $db->query($SQL);

    // returned ids (possibly removed items in trash, ...)
    $long_ids    = [];
    $unpermitted = [];
    $slices      = [];
    $content     = [];

    while ( $db->next_record() ) {

        $row = $db->record();

        // proove permissions for password-read-protected slices
        $unpack_id                  = unpack_id($row['id']);
        $unpack_slice_id            = unpack_id($row['slice_id']);
        $long_ids[]                 = $unpack_id;
        $slices[$unpack_slice_id]   = true;   // mark slice for perm check

        // add special fields to all items (zids)
        // slice_id... and id... is packed  - add unpacked variant now
        $content[$unpack_id]['u_slice_id......'] = [['value' => $unpack_slice_id]];
        $content[$unpack_id]['unpacked_id.....'] = [['value' => $unpack_id]];

        // if ($ignore_reading_password OR $credentials->checkSlice($unpack_slice_id, $crypted_additional_slice_pwd)) {
           // this row leads to creation second database connection db2 - for the first_child item in slice @todo - do something about it, Honza 2015-12-28
           //   (we should test it after this while cycle - once for slice)
           // Solved 2016-08-24 Honza
        foreach ($real_item_fields2get as $item_fid) {
            // FLAG_HTML do not means in fact, that the content is in HTML, but it rather means, that we should not call txt2html function on the content
            // we do not need to call txt2html() to any of the item table fields
            $content[$unpack_id][AA_Fields::createFieldId($item_fid)][] = ["value" => $row[$item_fid], "flag"  => FLAG_HTML];
        }
    }
    freeDB($db);

    if (!$ignore_reading_password) {
        $credentials = AA_Credentials::singleton();
        foreach ($slices as $unpack_slice_id => $foo) {
            if (!$credentials->checkSlice($unpack_slice_id, $crypted_additional_slice_pwd)) {
                // it should be rare

                // mark all items from this slice as unpermitted
                $ERR_VALUE = [["value" => _m("Error: Missing Reading Password"), "flag"  => FLAG_HTML]];
                foreach ($content as $unpack_id => $c4id) {
                    $unpermitted[$unpack_id] = true;
                    foreach ($c4id as $fid) {
                        // at least id and slice_id should be correct.............. for AA_Items::getItems()
                        if (($fid != 'id..............') OR ($fid != 'slice_id........')) {
                            $content[$unpack_id][$fid] = $ERR_VALUE;
                        }
                    }
                }
            }
        }
    }

    // Skip the rest if no items found
    if (empty($content)) {
        AA::$debug&32 && AA::$dbg->traceend('GetItemContent', '');
        return null;
    }

    // If its a tagged id, then set the "idtag..........." field
    if ( $zids->onetype() == 't') {
        $tags = $zids->gettags();
        foreach ($tags as $k => $v) {
            $content[$k]["idtag..........."][] = ["value" => $v];
        }
    }

    // construct WHERE query to content table
    $new_sel_in    = sqlin('item_id', $long_ids, true);

    $restrict_cond = '';

    if ( is_array( $fields2get ) ) {
        switch ( count($content_fields) ) {
            case 0:  // we want just some item fields
                     $restrict_cond = '1=0';
                     break;
            case 1:
                     $restrict_cond = " AND field_id = '". reset($content_fields) ."' ";
                     break;
            default:
                     $restrict_cond = " AND field_id IN ( '". join( "','", $content_fields ) ."' ) ";
        }
    }

    // get content from content table

    // feeding - don't worry about it - when fed item is updated, informations
    // in content table is updated too

    // do we want any content field?
    if ( $restrict_cond != '1=0' ) {
        $db = getDB();
        $SQL = "SELECT * FROM content WHERE $new_sel_in $restrict_cond ORDER BY item_id, number"; // usable just for multivalues
        $db->query($SQL);

        while ( $db->next_record() ) {

            $row       = $db->record();

            // secret.........* fields should not be part of the $content array
            if (strpos($row['field_id'],'secret')===0) {
                continue;
            }

            $unpack_id = unpack_id($row['item_id']);

            if ( $unpermitted[$unpack_id] ) {
                $content[$unpack_id][$row['field_id']] = $ERR_VALUE;
                continue;
            }

            // which database field is used (from 05/15/2004 we have FLAG_TEXT_STORED set for text-field-stored values
            if ( ($row['flag'] & FLAG_TEXT_STORED) OR (strlen($row['text'])>0)) {
                if (is_array($content[$unpack_id][$row['field_id']][0]) AND ($content[$unpack_id][$row['field_id']][0]['value'] == $row['text'])) {
                    // ignore content duplicates (there could be more that two values for field
                    // with the same number (=NULL) - the ones which comes from "add value to field" operation)
                    continue;
                }
                if ($row['number'] > 999999) {  // translations
                    $content[$unpack_id][$row['field_id']][$row['number']] = ["value" => $row['text'], "flag"  => $row['flag']];
                } else {
                    $content[$unpack_id][$row['field_id']][] = ["value" => $row['text'], "flag"  => $row['flag']];
                }
            } else {
                // we can set FLAG_HTML, because the text2html gives the same result as the number itself
                // if speeds the item->f_h() function a bit
                $content[$unpack_id][$row['field_id']][] = ["value" => $row['number'], "flag"  => ($row['flag']|FLAG_HTML)];
            }
        }
        freeDB($db);
    }

    if ($use_short_ids) {
        // if $zids->onetype() == 's' we should return $content constructed using short_id (to be backward compatible)
        // it is deprecated, so maybe rather update calling code to accept $content with unpacked_id keys
        // (there is problem with calling after zids->short_or_longids - say: view.php3?vid=26&cmd[26]=x-26-23074  ) Honza 2016-09-30
        $content_long = $content;
        $content = [];
        foreach ($content_long as $c4id) {
            $content[$c4id['short_id........'][0]['value']] = $c4id;
        }
    }


    AA::$debug&32 && AA::$dbg->traceend('GetItemContent', $content);

    // $use_short_ids && AA_Log::warn('use_short_ids');

    return $content;   // Note null returned above if no items found
}

/** GetItemContentMinimal function
 *  The same as GetItemContent function, but it returns just id and short_id
 *  (or other fields form item table - specified in $fields2get) for the item
 *  (used in URL listing view @see view_type['urls']).
 *  If $fields2get is specified, it MUST contain at least 'id'.
 * @param $zids
 * @param $fields2get
 * @return array|null
 */
function GetItemContentMinimal($zids, $fields2get=false) {
    if ( !$fields2get ) {
        $fields2get = ['id', 'short_id'];
    }
    $columns = join(',',$fields2get);

    if ( !is_object($zids) ) {
        $zids = new zids( $zids, 'l');
    }
    $sel_in = $zids->sqlin( '' );
    $content = [];
    $n_items = 0;

    if ($sel_in) {
        // get content from item table
        $db    = getDB();
        $SQL   = "SELECT $columns FROM item WHERE id $sel_in";
        $db->query($SQL);
        while ( $db->next_record() ) {
            $n_items++;
            $foo_id = unpack_id($db->f("id"));
            foreach ( $fields2get as $fld ) {
                $content[$foo_id][AA_Fields::createFieldId($fld)][] = ["value" => $db->f($fld)];
            }
        }
        freeDB($db);
    }

    return ($n_items == 0) ? null : $content;   // null returned if no items found
}

/** GrabConstantColumn function
 * @param $db
 * @param $column
 * @return array
 */
function GrabConstantColumn($db, $column) {
    switch ($column) {
        case "name":        return ["value"=> $db->f("name")];
        case "value":       return ["value"=> $db->f("value"), "flag" => FLAG_HTML];
        case "pri":         return ["value"=> $db->f("pri")];
        case "group":       return ["value"=> $db->f("group_id")];
        case "class":       return ["value"=> $db->f("class")];
        // case "counter":     return array( "value"=> $i++ );
        case "id":          return ["value"=> unpack_id($db->f("id") )];
        case "description": return ["value"=> $db->f("description"), "flag" => FLAG_HTML];
        case "short_id":    return ["value"=> $db->f("short_id")];
        case "level":       return ["value"=> strlen($db->f("ancestors"))/16];
    }
    return [];
}

/** GetConstantContent function
 *  Fills Abstract data srtructure for Constants
 * @param $zids
 * @return array|bool
 */
function GetConstantContent( $zids ) {
    if ( !$zids ) {
        return false;
    }
    $db = getDB();

    $SQL = 'SELECT * FROM constant WHERE short_id '. $zids->sqlin(false);
    $db->query( $SQL );
    $content = [];
    $i=1;
    while ($db->next_record()) {
        $coid = $db->f('short_id');
        $content[$coid]["const_name"][]        = GrabConstantColumn($db, "name");
        $content[$coid]["const_value"][]       = GrabConstantColumn($db, "value");
        $content[$coid]["const_pri"][]         = GrabConstantColumn($db, "pri");
        $content[$coid]["const_group"][]       = GrabConstantColumn($db, "group");
        $content[$coid]["const_class"][]       = GrabConstantColumn($db, "class");
        $content[$coid]["const_counter"][]     = $i++;
        $content[$coid]["const_id"][]          = GrabConstantColumn($db, "id");
        $content[$coid]["const_description"][] = GrabConstantColumn($db, "description");
        $content[$coid]["const_short_id"][]    = GrabConstantColumn($db, "short_id");
        $content[$coid]["const_level"][]       = GrabConstantColumn($db, "level");
    }
    freeDB($db);

    return $content;
}

/** StoreTable2Content function
 *  Just helper function for storing data from database to Abstract Data Structure
 * @param $content
 * @param $SQL
 * @param $prefix
 * @param $id_field
 */
function StoreTable2Content(&$content, $SQL, $prefix, $id_field) {
    $data = GetTable2Array($SQL, 'NoCoLuMn', 'aa_fields');
    if ( is_array($data) ) {
        foreach ( $data as $row ) {
            $foo_id = $row[$id_field];
            foreach($row as $key => $val) {
                $content[$foo_id][$prefix . $key][] = ['value' => $val];
            }
        }
    }
}

// -------------------------------------------------------------------------------
/** GetHeadlineFieldID function
 * @param $sid
 * @param $slice_field
 * @return array|bool
 */
function GetHeadlineFieldID($sid, $slice_field="headline.") {
    // first should be headline........, then headline.......1, etc.
    return DB_AA::select1('id', "SELECT id FROM field", [['slice_id', $sid, 'l'], ['id', "$slice_field%", 'LIKE']], ['id']);  // false if not found
}

/** GetCategoryGroup function
 * find group_id for constants of the slice
 * @param $slice_id
 * @param $field
 * @return
 */
function GetCategoryGroup($slice_id, $field='') {
    // first should be category........, then category.......1, etc.
    $order   = [];
    $conds   = [['slice_id',$slice_id, 'l']];
    if ($field) {
        $conds[] = ['id', $field];
    } else {
        $conds[] = ['id', 'category%', 'LIKE'];
        $order   = ['id'];
    }
    $arr = explode( ":", DB_AA::select1('input_show_func', "SELECT input_show_func FROM field", $conds, $order));  // false if not found
    return $arr[1];
}

// -------------------------------------------------------------------------------

/** ParseFnc function
 * Parses the string xxx:yyyy (database stored func) to arr[fce]=xxx [param]=yyyy
 */
function ParseFnc($s) {
    $pos = strpos($s,":");
    if ( $pos ) {
        $arr['fnc']   = substr($s, 0, $pos);
        $arr['param'] = substr($s, $pos+1);
    } else {
        $arr['fnc']   = $s;
    }
    return $arr;
}

/** replaces htmlspecialchars because of changes in php 5.4
 */
function myspecialchars( $var='', $double_encode=true) {
    return htmlspecialchars( $var, ENT_QUOTES | ENT_HTML5, 'ISO-8859-1', $double_encode);
}


/** safe function
 * @param string $var
 * @return string - html safe code (used for preparing variable to print in form)
 */
function safe( $var ) {
    return htmlspecialchars( $var, ENT_QUOTES | ENT_HTML5, 'ISO-8859-1');  // stripslashes function added because of quote varibles sended to form before
}

/** Base64url version of base64
 * @param string $data
 * @return string
 */
function bin2url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** reverse Base64url version of base64
 * @param string $data
 * @return bool|string
 */
function url2bin($data) {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

/** HtmlPageBegin function
 * Prints HTML start page tags (html begin, encoding, style sheet, but no title).
 * Chooses the right encoding by get_mgettext_lang().
 * @param bool   $js_lib      if true, includes js_lib.min.js javascript
 * @param $lang
 */
function HtmlPageBegin($js_lib=false, $lang=null) {
    AA::setEncoding(AA_Langs::getCharset($lang));
    $headers = AA::getHeaders();
    // fix for Chrome 57 which do not allow to send HTML in POST request sometimes (ERR_BLOCKED_BY_XSS_AUDITOR) - HM 2017-04-24
    $headers['x-xss-protection'] = 'X-XSS-Protection: 0';
    AA::sendHeaders($headers);
    echo
'<!DOCTYPE html>
<html>
  <head>
    <link rel="SHORTCUT ICON" href="'. AA_INSTAL_PATH .'images/favicon.ico">
    <link rel="stylesheet" href="'.(AA_INSTAL_PATH .ADMIN_CSS).'">
    <link rel="stylesheet" href="'.AA_INSTAL_PATH. 'css/aa-system.css">
    <meta charset="'.AA::$encoding.'">
';

    if ($js_lib) {
        FrmJavascriptFile( 'javascript/js_lib.min.js' );
        FrmJavascriptFile( 'javascript/jquery.min.js' );
        FrmJavascriptFile( 'javascript/aajslib-jquery.min.js' );
    }
}


// use instead of </body></html> on pages which show menu
function HtmlPageEnd() {
    global $spareDBs, $slices4cache;
    AA::$debug&2 && AA::$dbg->info('time: '. timestartdiff());
    if (AA::$debug&16) {
        echo '<br>Page generation time: '. timestartdiff();
        echo '<br>Dababase instances: '. DB_AA::$_instances_no;
        echo '<br>  (spareDBs): '. count($spareDBs);
        echo '<br>UsedModules:<br> - '. join('<br> - ', array_map(function($mid) {return AA_Module::getModuleName($mid);}, $slices4cache));
        AA::$dbg->duration_stat();
    }

  echo "
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>";
}

/**
 * @param $code
 * @return string
 */
function getHtmlPage($code) {
    return "<!DOCTYPE html>\n<html>\n<head>\n</head>\n<body>\n  $code \n</body>\n</html>";
}

/** MsgPage function
 * Displays page with message and link to $url
 * @param $url - where to go if user clicks on Back link on this message page
 * @param $msg - displayed message
 * @param $dummy - was used in past, now you should use MsgPageMenu from msgpage.php3
 */
function MsgPage($url, $msg) {
    HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
    echo "<title>" . _m("Toolkit news message") . "</title>
      </head>
    <body>";

    if (isset($msg) AND is_array($msg)) {
        PrintArray($msg);
    } else {
        echo "<p>$msg</p><br><br>";
    }
    echo "<a href=\"$url\">"._m("Back")."</a>";
    echo "</body></html>";
    page_close();
    exit;
}

/**  Low level save data to the content table
 *   Should not be used by "user" scripts
 * @param string $item_id long item id
 * @param string $field_id field id
 * @param bool   $text_stored AA_Field['text_stored']
 * @param array  $value ['value'=>.., 'flag'=>..]
 * @param array  $additional
 * @param bool   $delete    - erease the old values
 */
function StoreToContent($item_id, $field_id, $text_stored, $value, $additional= [], $delete=false) {
    if (!is_long_id($item_id) OR (strlen($field_id)!=16)) {
        return;
    }
    $varset = new Cvarset();
    $varset->clear();
    if ($text_stored) {
        // do not store empty values in content table for text_stored fields
        // if ( !$value['value'] ) { return false; }    // can't do it, conditions do not work then (ecn joblist)
        $varset->add("text", "text", $value['value']);
        // set "TEXT stored" flag
        $varset->add("flag", "number", (int)$value['flag'] | FLAG_TEXT_STORED );
        if (ctype_digit((string)$additional["order"])) {
            $varset->add("number", "number", $additional["order"]);
        } else {
            $varset->add("number","null", "");
        }
    } else {
        $varset->add("number", "number", (int)$value['value']);
        // clear "TEXT stored" flag
        $varset->add("flag",   "number", (int)$value['flag'] & ~FLAG_TEXT_STORED );
    }

    // insert item but new field
    $varset->addkey("item_id", "unpacked", $item_id);
    $varset->addkey("field_id", "text", $field_id);

    // not used yet
    // if ($history) {
    //     $varset->saveHistory('content', $item_id);
    // }
    if ($delete) {
        $varset->doDelete('content');
    }
    $varset->doInsert('content');
}


// -----------------------------------------------------------------------------

/** gensalt function
 * generates random string of given length
 * @param $saltlen
 * @return string
 */
function gensalt($saltlen) {
    $salt_chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456589";
    $more_sec = '';
    $salt = '';

    do {
        $more_sec .= hash('md5', microtime().'xy');
    } while ($saltlen > strlen($more_sec));

    for ($i = 0; $i < $saltlen; $i++) {
        $salt .= $salt_chars[(mt_rand(0,61)+ord($more_sec[$i])) % 62];
    }
    return $salt;
}

// ---------------------------------------------------------------------------------------------

/** split_escaped function
 *  like PHP split, but additionally provides $escape_pattern to stand for occurences of $pattern,
 *  e.g. split_escaped (":", "a#:b:c", "#:") returns array ("a:b","c")
 * @param $pattern
 * @param $string
 * @param $escape_pattern
 * @return mixed
 */
function split_escaped($pattern, $string, $escape_pattern) {
    $dummy = chr(0x1E);  // record separator ASCII character - we can use any other, however
    while (strpos($string, $dummy) !== false) {
        $dummy .= '^';   // add another strange character to the
    }
    return str_replace($dummy, $pattern, explode($pattern, str_replace($escape_pattern, $dummy, $string)));
   // foreach ($strings as $key => $val) {
   //     $strings[$key] = (string)str_replace($dummy, $pattern, $val);
   // }
   // return $strings;
}

/** join_escaped function
 * @param $pattern
 * @param $strings
 * @param $escape_pattern
 * @return string
 */
function join_escaped($pattern, $strings, $escape_pattern) {
    $retval = '';
    foreach ((array)$strings as $val) {
        if ($retval) {
            $retval .= $pattern;
        }
        $retval .= str_replace($pattern, $escape_pattern, $val);
    }
    return $retval;
}

/** join_and_quote function
 * @param $pattern
 * @param $strings
 * @return string
 */
function join_and_quote( $pattern, $strings ) {
    $retval = '';
    foreach ((array)$strings as $string) {
        if ($retval) {
            $retval .= $pattern;
        }
        $retval .= addslashes($string);
    }
    return $retval;
}

function ReadFileSafe($url) {
    if ( ((stripos($url, 'http://')===0) OR (stripos($url, 'https://')===0)) AND (stripos($url, '..')===false) ) {
        readfile($url);
    }
}

/** add_post2shtml_vars function
 * Cooperates with the script post2shtml.php3 (see more doc there),
 * which allows to easily post variables
 * to PHP scripts SSI-included in a .shtml page.
 *
 * @param bool $delete Should delete the vars from database after recalling them?
 *                     If you use the vars in several scripts included in one
 *                     shtml page, delete them in the last script.
 *
 * @author Jakub Adamek, Econnect, December 2002
 */
function add_post2shtml_vars($delete = true) {
    global $post2shtml_id;
    add_vars();
    if (!$post2shtml_id) {
        return;
    }
    $db = getDB();
    $db->query("SELECT * FROM post2shtml WHERE id='$post2shtml_id'");
    $db->next_record();
    $vars = unserialize ($db->f("vars"));
    if ($delete) {
        $db->query("DELETE FROM post2shtml WHERE id='$post2shtml_id'");
    }
    freeDB($db);
    $var_types = ["post","get","files","cookie"];

    foreach ($var_types as $var_type) {
        if (is_array($vars[$var_type])) {
            foreach ($vars[$var_type] as $var => $value) {
                global $$var;
                $$var = $value;
            }
        }
    }
}

/** getSelectBoxFromParamWizard function
 *  Creates values for a select box showing some param wizard section.
 * @param $var
 * @return mixed
 */
function getSelectBoxFromParamWizard($var) {
    foreach ($var["items"] as $value => $prop) {
        $retval[$value] = $prop["name"];
    }
    return $retval;
}

/** GetAAImage function
 * @param        $filename
 * @param string $alt
 * @param int    $width
 * @param int    $height
 * @param string $add
 * @param string $add_path
 * @return string
 */
function GetAAImage($filename, $alt, $width, $height, $add='', $add_path='') {
    $image_path = AA_BASE_PATH.   $add_path. "images/$filename";
    $image_url  = AA_INSTAL_PATH. $add_path. "images/$filename";
    $title      = ($alt ? "title=\"$alt\"" : '');
    if ( $width ) {
        $size = "width=\"$width\" height=\"$height\"";
    } else {
        // this should not be used - the width should be always provided
        $im_size = @getimagesize($image_path);
        $size = $im_size[3];
    }
    return "<img border=0 src=\"$image_url\" alt=\"$alt\" $title $size $add>";
}

/** GetModuleImage function
 * @param $module
 * @param $filename
 * @param $alt
 * @param $width
 * @param $height
 * @param $add
 * @return string
 */
function GetModuleImage($module, $filename, $alt='', $width=0, $height=0, $add='') {
    return GetAAImage($filename, $alt, $width, $height, $add, "modules/$module/");
}

/** returns $object's class name without namespace
 * @param $object
 * @return false|string
 */
function get_class_name($object) {
    $classname = get_class($object);
    return ($pos = strrpos($classname, '\\')) ? substr($classname, $pos + 1) : $classname;
}


/**
 * Use as
 *   $credentials = AA_Credentials::singleton();
 *   $credentials->loadFromSlice($slice_id);
 * @method  static AA_Credentials singleton()
 *
 */
class AA_Credentials {

    use SingletonTrait;

    protected $_pwd = [];     // Array of all known slice passwords (md5 for better security)

    /** main method for checking the slice_pwd */
    function checkSlice($slice_id, $crypted_additional_slice_pwd=null) {
        return $this->checkCryptedPassword(AA_Slice::getModuleProperty($slice_id,'reading_password'), $crypted_additional_slice_pwd);
    }

    function checkCryptedPassword($crypted_slice_pwd, $crypted_additional_slice_pwd=null) {
        if (!$crypted_slice_pwd OR $this->_pwd[$crypted_slice_pwd] OR $crypted_slice_pwd == $crypted_additional_slice_pwd) {
            return true;
        }
        if ($GLOBALS['slice_pwd']) {
            $this->register(AA_Credentials::encrypt($GLOBALS['slice_pwd']));
        }
        return $this->_pwd[$crypted_slice_pwd] ? true : false;
    }

    /** Load reading_password from slice
     * @param $slice_id
     */
    function loadFromSlice($slice_id) {
        $this->register(AA_Slice::getModuleProperty($slice_id,'reading_password'));
    }

    function register($crypted_slice_pwd) {
        if (!empty($crypted_slice_pwd)) {
            $this->_pwd[$crypted_slice_pwd] = true;
        }
    }

    /** wrapper function
     *  called as AA_Credentials::encrypt($reading_password) */
    static function encrypt($pwd) {
        return hash('md5', $pwd);
    }
}

/** AA_Contentcache class - prevents from executing the same - time consuming code
 *  twice in one run of the script.
 *  Usage:
 *    Instead of calling:
 *        $result = function_name(param1, param2);
 *    we will use
 *        $result = AA::Contentcache()->get_result("function_name", array(param1, param2));
 *    For the first time call the function_name is called, for second, third,...
 *    time calling, the result is returned from cache (for the same parameters)
 *
 *    The best to use this class for time-consuming functions with small results
 */
class AA_Contentcache {
    // used for global cache of contents
    /** @var $content string[] - array of cache entry - 'key'=>[result, generation_time, uses, identification] */
    protected $content;
    /** @var $content_stable string[] - the same as $content, but for special content, which is not deleted by clear_soft() */
    protected $content_stable;

    /** get_result function
     *  Calls $function with $params and returns its return value. The result
     *  value is then stored into cache, so next call of the $function with the
     *  same parameters is returned from cache - function is not performed.
     *  Use this feature mainly for repeating, time consuming functions!
     *
     * @param $function - name of function or you could use also object methods
     *                     then the $function parameter should be array
     *                     (see http://php.net/manual/en/function.call-user-func.php)
     *                     For static class methods:
     *                        $result = AA::Contentcache()->get_result(array('Classname', 'function_name'), array(param1, param2));
     *                     For instance methods:
     *                        $result = AA::Contentcache()->get_result(array($this, 'function_name'), array(param1, param2));
     * @param $params - array of function's parameters
     * @param $additional_params - string
     *                   - special param for cache - it is not passed to the
     *                     function but the cache counts with it (useful, if you
     *                     know, that the result of the $function depends not
     *                     only on its parameters, but also on some (global?) variable
     * @return mixed
     */
    function get_result( $function, $params= [], $additional_params='' ) {
        return $this->_get_result(get_hash(func_get_args()), $function, $params, $function);
    }

    ///** sometimes it is quicker to not count the key automaticaly (in case of object call) */
    //function get_result_by_id($key, $function, $params) {
    //    if ( isset( $this->content[$key]) ) {
    //        $this->content[$key][2]++;
    //        return $this->content[$key][0];
    //    }
    //    $time = microtime(true);
    //    $val = call_user_func_array($function, (array)$params);
    //    $this->content[$key] = array($val, microtime(true)-$time, 0);
    //    return $val;
    //}


    /** sometimes it is quicker to not count the key automatically (in case of object call)
     * @param $key
     * @param $function
     * @param $params
     * @param $ident
     * @return mixed
     */
    function _get_result($key, $function, $params, $ident) {
        if ( isset( $this->content[$key]) ) {
            $this->content[$key][2]++;
            return $this->content[$key][0];
        }
        $time = microtime(true);
        $val = call_user_func_array($function, (array)$params);
        $this->content[$key] = [$val, microtime(true)-$time, 0, $ident];
        return $val;
    }


    /** sometimes it is quicker to not count the key automaticaly (in case of object call)
     * @param $function
     * @param $params
     * @param $classname_hint
     * @param $add
     * @return mixed
     */
    function get_result_4_object($function, $params, $classname_hint, $add) {
        return $this->_get_result(get_hash($classname_hint, $params, $add), $function, $params, $classname_hint);
    }


    /** Decides if the content should go to stable or soft chache - $content_stable - not deleted by clear_soft()
     *  (we don want to invalidate the variables {(  )} when {newitem::} (so StoreItem()) is called  )
     * @param $access_code string  - access code to check
     * @return bool
     */
    function is_soft($access_code) {
        return strpos($access_code, 'define') !== 0;
    }

    /** set function
     *  set new value for key $key
     * @param $access_code
     * @param $val
     */
    function set($access_code, $val) {
        if ($this->is_soft($access_code)) {
            $this->content[hash('md5', $access_code)] = [$val, 0, 0];
        } else {
            $this->content_stable[hash('md5', $access_code)] = [$val, 0, 0];
        }
    }

    /** get function
     *  Get value for $access_code.
     * @param $access_code
     *  @return false if the value is not cached for the $access_code (use ===)
     */
    function get($access_code) {
        $key = hash('md5', $access_code);
        if ($this->is_soft($access_code)) {
            if ( isset($this->content[$key]) ) {
                return $this->content[$key][0];
            }
        } elseif ( isset($this->content_stable[$key]) ) {
            return $this->content_stable[$key][0];
        }
        return false;
    }

    /** exists function
     *  returns true or false if the key exists
     * @param $access_code
     * @return bool
     */
    function exists($access_code) {
        return isset($this->content[hash('md5', $access_code)]);
    }


    /** clear function
     * clear key or all content from contentcache
     * @param $key
     */
    function clear($key=null) {
        if (!is_null($key)) {
            unset($this->content[$key]);
            unset($this->content_stable[$key]);
        } else {
            unset($this->content);
            unset($this->content_stable);
        }
    }

    /** clear_soft function
     */
    function clear_soft() {
        unset($this->content);
    }


    /** push function
     *  stores value for access_code to cache and backups the old values
     *  each push, should have pop counterpart call, which restores previous value
     *  used with push_arr for storing view qs parameters when entering view, for example
     * @param $access_code
     * @param $arr
     */
    function push($access_code, $val) {
        if ( $this->exists($access_code) ) {
            // backup access code - arr underscore before (with possible recursion)
            $this->push('_'.$access_code, $this->get($access_code));
        }
        $this->set($access_code, $val);
    }

    /** push function
     *  stores value for access_code to cache and backups the old values
     *  each push, should have pop counterpart call, which restores previous value
     *  used with push_arr for storing view qs parameters when entering view, for example
     * @param $access_code
     * @param $arr
     * @return false
     */
    function pop($access_code) {
        $ret = $this->get($access_code);
        if ( $this->exists('_'.$access_code) ) {
            $this->set($access_code, $this->pop('_'.$access_code)); // with recurcsion
        }
        return $ret;
    }

    /** push_arr function
     *  stores all values form array to cache and backups the old values
     *  each push, should have pop_arr counterpart call, which restores previous values
     *  used for storin view qs parameters when entering view, for example
     * @param $access_code
     * @param $arr
     */
 //  function push_arr($access_code, array $arr) {
 //      foreach ($arr as $k => $v) {
 //          $key = hash('md5', $k);
 //          if ( isset($this->content[$key]) ) {
 //              $this->set('_'.$k, $this->content[$key]);
 //          }
 //          $this->set($k, $v);
 //      }
 //      $this->set($access_code, array_keys($arr));
 //  }
 //
 //  /** pop_arr function
 //   *  restores previous values pushed by push_arr
 //   * @param $access_code
 //   */
 //  function pop_arr($access_code) {
 //      if (is_array($keys = $this->get($access_code))) {
 //          foreach ($keys as $k => $v) {
 //              $key = hash('md5', $k);
 //              if ( isset($this->content[$key]) ) {
 //                  $this->set('_'.$k, $this->content[$key]);
 //              }
 //              $this->set($k, $v);
 //          }
 //          $this->set($access_code, array_keys($arr));
 //  }




    function duration_stat() {
        echo "<br><b>contentcache</b> (".count($this->content)." rows)<br>";
        echo '<br><table><tr><th>id</th><th>generation time</th><th>content(length)</th><th>used</th></tr>';
        foreach($this->content as $c) {
           echo '<tr><td>'.safe($c[3]).'</td><td>'.(1000*$c[1]).'</td><td>'.safe(substr($c[0],0,20)).' ('.strlen($c[0]).')</td><td>'.$c[2].'</td></tr>';
        }
        echo '</table>';

        $stat = [];
        foreach($this->content as $c) {
            if ( !isset($stat[$c[3]]) ) {
                $stat[$c[3]] = [];
            }
            $stat[$c[3]]['cases']++;
            $stat[$c[3]]['unused']+=($c[2] ? 0 : 1);
            $stat[$c[3]]['gentime']+=1000*$c[1];
            $stat[$c[3]]['length']+=strlen($c[0]);
            $stat[$c[3]]['hits']+=$c[2];
            $stat[$c[3]]['saved']+=$c[2]*1000*$c[1];
        }
        echo '<br><table><tr><th>id</th><th>cases</th><th>unused</th><th>sum gentime</th><th>sum length</th><th>sum hits</th><th>saved</th></tr>';
        foreach($stat as $key=>$c) {
           echo '<tr><td>'.safe($key).'</td><td>'.$c['cases'].'</td><td>'.$c['unused'].'</td><td>'.$c['gentime'].'</td><td>'.$c['length'].'</td><td>'.$c['hits'].'</td><td>'.$c['saved'].'</td></tr>';
        }
        echo '</table>';
    }
// end of contentcache class
}


/** get_if function
 *  If $value is set, returns $value - else $else
 * @param $value
 * @param $else
 * @param $else2
 * @return string
 */
function get_if($value, $else, $else2='aa_NoNe') {
    return $value ?: ($else ?: (($else2=='aa_NoNe') ? $else : $else2));
}

/** aa_version function
 *  Version of AA - automaticaly included also date and revision of util.php3
 *  file, for better version informations
 */
function aa_version($format='full') {
    $version = '2.90.0';
    $full    = 'ActionApps '.$version.' ($Date$, $Revision$), PHP '.phpversion().', REMOTE_ADDR: '.$_SERVER['REMOTE_ADDR'];
    switch ($format) {
        case 'svn': return (int) substr($full, strpos($full, 'Revision')+10);
        case 'svn2':
            $rev = file_get_contents(AA_BASE_PATH. ".svn/entries", FILE_TEXT, null, 0, 20);
            $rev = explode("\n", $rev);
            return $rev[3];
        case 'aa':  return $version;
    }
    return $full;
}

class CookieManager {
    //  we are adding prefix AA_ - at least it prevents conflicts between GET
    //  and COOKIES variables of the same name
    /** set function
     * @param $name
     * @param $value
     * @param $time int seconds - like 60*60*24*2
     */
    function set($name, $value, $time=0, $path='/') {
        setcookie('AA_'.$name, $value, $time ? time() + $time : 0, $path, $_SERVER['HTTP_HOST']);
    }

    /** get function
     * @param $name
     * @return
     */
    function get($name) {
        return $_COOKIE['AA_'.$name];
    }
}

class AA_GeneralizedArray {
    protected $arr;

    /** AA_GeneralizedArray function
     */
    function __construct() {
        $this->arr = [];
    }

    /** add function
     * @param $value
     * @param $coordinates
     */
    function add($value, $coordinates) {
        $arr =& $this->arr;
        // make sure the position exist
        foreach ( $coordinates as $key ) {
            if (!isset($arr[$key])) {
                $arr[$key] = [];
            }
            // go down - more deep in the structure
            $arr = &$arr[$key];
        }
        $arr = array_merge($arr, [$value]);
    }

    /** getValues function
     * @param $coordinates
     * @return array|mixed|null
     */
    function getValues($coordinates) {
        $arr =& $this->arr;
        // make sure the position exist
        foreach ( $coordinates as $key ) {
            if (!isset($arr[$key])) {
                return null;
            }
            // go down - more deep in the structure
            $arr = &$arr[$key];
        }
        $ret = $arr;   // do not return reference
        return $ret;
    }

    /** getArray function
     */
    function getArray(): array {
        return $this->arr;
    }
}

/** IsSpamText function
 * @param $text
 * @return bool
 */
function IsSpamText($text, $tolerance=4) {
    // we do not accept any text using something like:
    //     [url=https://example.net]Example.net[/url]
    if (substr_count(strtolower($text), '[/url]')) {
        return true;
    }

    $link_count  = substr_count(strtoupper($text), 'HTTP');
    $text_length = strlen($text);


    // four links are OK always
    if ( $link_count < ($tolerance+1) ) {
        return false;
    }

    // link density - text of 250 characters could contain one link (in average)
    if ( ($text_length/$link_count)>250 ) {
        return false;
    }
    return true;
}

/** checks, if the identifier looks like alias. Used in {ifset:{_#HEADLINE}:...}
 *  to check the string - for example - accepts _#HEADLINE and also _#P3 (for {_:...} functions)
 * @param string $identifier
 * @return bool
 */
function IsAlias($identifier): bool
{
    return  ((substr($identifier,0,2)=='_#') AND ((strlen($identifier)==10) OR ((substr($identifier,0,3)=='_#P') AND ctype_digit((string)substr($identifier,3)))));
}

/** is signed int (ctype_digit do not allow signed numbers)
 * @param string $var
 * @return bool
 */
function IsSigInt($var): bool
{
    if (is_int(filter_var($var, FILTER_VALIDATE_INT))) {
        return true;
    }
    return (strlen($var) AND is_int(filter_var(ltrim($var,'0'), FILTER_VALIDATE_INT)));  // the 04 is int
}

/** RestoreVariables to global context - kind of @deprecated */
function RestoreVariables(array $r_state_vars) {
    foreach ($r_state_vars as $k => $v) {
        $GLOBALS[$k] = $v;
    }
}

/** susbstr with utf in mind */
function aa_substr($text, $start, $len=null) {
    return mb_substr($text, $start, $len);
}

/** strlen with utf in mind */
function aa_strlen($text) {
    return mb_strlen($text);
}

/** strpos with utf in mind */
function aa_strpos($haystack , $needle , $offset = 0) {
    return mb_strpos($haystack , $needle , $offset);
}

/** strrpos with utf in mind */
function aa_strrpos($haystack , $needle , $offset = 0) {
    return mb_strrpos($haystack , $needle , $offset);
}


/** Get Authorization Header
 * @return string
 */
function get_authorization_header() {
    $ret='';
    if (isset($_SERVER['Authorization'])) {
        $ret = $_SERVER['Authorization'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $ret = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif ( function_exists('apache_request_headers')) {
        $apache_auth_tmp = apache_request_headers();
        if ( strlen($apache_auth_tmp['Authorization']) ) {
            // $ret = 'Bearer '.$apache_auth_tmp['Authorization'];  // no need to add Bearer - already included
            $ret = $apache_auth_tmp['Authorization'];
        }
    }
    return trim($ret);
}

