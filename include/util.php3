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
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
//
// Miscellaneous utility functions
//

require_once AA_INC_PATH."locsess.php3";
require_once AA_INC_PATH."constants.php3";
require_once AA_INC_PATH."mgettext.php3";
require_once AA_INC_PATH."zids.php3";
require_once AA_INC_PATH."logs.php3";
require_once AA_INC_PATH."go_url.php3";
require_once AA_INC_PATH."statestore.php3";
require_once AA_INC_PATH."widget.class.php3";
require_once AA_INC_PATH."field.class.php3";

/** a_href function
 *  Get <a href> tag
 * @param $url
 * @param $txt
 */
function a_href($url, $txt) {
    return "<a href=\"$url\">$txt</a>";
}

/** expand_return_url function
 * Expand return_url, possibly adding a session to it
 * @param $session
 */
function expand_return_url($session=true) {
    global $return_url, $sess;
    return ($session AND is_object($sess)) ? $sess->url($return_url) : $return_url;
}

/** go_return_or_url function
 *  This function goes to either $return_url if set, or to $url
 * if $usejs is set, then it will use inline Javascript, its not clear why this is done
 *    sometimes (item.php3) but not others.
 * if $session is set, then any session variable will be added, to the return_url case to allow for quicker 2nd access
 *    session is always added to the other case
 * if $add_param are set, then they are added to the cases EXCEPT return_url
 * @param $url
 * @param $usejs
 * @param $session
 * @param $add_param
 */
function go_return_or_url($url, $usejs, $session, $add_param="") {
    global $return_url,$sess;
    if ($return_url) {
        go_url(expand_return_url($session), $add_param, $usejs);
    } elseif ($url) {
        go_url($sess->url($url), $add_param);
    }
    // Note if no $url or $return_url then drops through - this is used in index.php3
}

/** endslash function
 *  Adds slash at the end of a directory name if it is not yet there.
 * @param $s
 */
function endslash(&$s) {
    if (strlen ($s) AND substr ($s,-1) != "/") {
        $s .= "/";
    }
}

/** debuglog function
 *  To use this function, the file "debuglog.txt" must exist and have writing permission for the www server
 * @param $text
 */
function debuglog($text) {
    require_once AA_INC_PATH."files.class.php3";  // file wrapper
    $file = &AA_File_Wrapper::wrapper(AA_INC_PATH."logs.txt");
    if ($file->open('a')) {
        $file->write(date( "h:i:s j-m-y "). $text. "\n");
        $file->close();
    }
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
/** self_complete_url function
 *
 */
function self_complete_url() {
    return self_server().$GLOBALS['REQUEST_URI'];
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
    $sname = ($_SERVER['HTTP_HOST'] ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']);
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

    $ret_string = ($_SERVER['REQUEST_URI'] AND strpos($_SERVER['REQUEST_URI'],'?')) ?
                        substr($_SERVER['REQUEST_URI'],strpos($_SERVER['REQUEST_URI'], '?')+1) :
                  ( isset($_SERVER['REDIRECT_QUERY_STRING_UNESCAPED'])    ?
                        stripslashes($_SERVER['REDIRECT_QUERY_STRING_UNESCAPED']) :
                        stripslashes($_SERVER['QUERY_STRING_UNESCAPED']) );
    // get off magic quotes
    return magic_strip($ret_string);
}

/** DeBackslash function
 *  skips terminating backslashes
 * @param $txt
 */
function DeBackslash($txt) {
    return str_replace('\\', "", $txt);        // better for two places
}

/** ParamExplode function
 * explodes $param by ":". The "#:" means true ":" - don't separate
 * @param $param
 * @return array
 */
function ParamExplode($param) {
    // replace all "#:" and <http>"://" with dumy string,
    // convert separators to ##Sx
    // change "#:" to ":" and change back "://" - then split by separation string
    // replaces in order
    return explode('##Sx', str_replace(array('#:', '://', ':', '~@|_'), array('~@|_', '~@|_//', '##Sx', ':'), $param));
}

/** ParamImplode function
 * @param $param
 */
function ParamImplode($param) {
    array_walk($param, create_function('&$v,$k', '$v = str_replace(":", "#:", $v);'));
    return implode(":", $param);
}

/** add_vars function
 *  Adds variables passed by QUERY_STRING_UNESCAPED (or user $query_string)
 *   to GLOBALS.
 * @param $query_string
 */
function add_vars($query_string="", $where='GLOBALS') {
    $varstring = ( $query_string ? $query_string : shtml_query_string() );

    if ( !$varstring ) {
        return array();
    }
    if ( ($pos = strpos('#', $varstring)) === true ) {  // remove 'fragment' part
        $varstring = substr($varstring,0,$pos);
    }

    // parse_str function is quite unusable, if used with magic_quotes_gpc ON
    // - it adds slashes not only to values, but ALSO to ARRAY KEYS!
    // we have to call magic_strip() to repair it
    parse_str($varstring, $aa_query_arr);
    // we also need PHP to think a['key'] is the same as a[key], that's why we
    // call NormalizeArrayIndex()

    // we do not want to replace sess variable, since we use it for sessions
    unset($aa_query_arr['sess']);
    $aa_query_arr = NormalizeArrayIndex(magic_strip($aa_query_arr));
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
    return array();
}


/** NormalizeArrayIndex function
 *  Removes starting and closing quotes from array index
 *  @param $arr["key"]=...   transforms to arr[key]=...
 */
function NormalizeArrayIndex($arr) {
    if (!is_array($arr)) {
        return $arr;
    }
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
 *  @example:
 *    array_merge_append( $conds[0]['value']=x, $conds[0][operator]=LIKE )
 *    results in $conds[0] = array( 'value'=>'x', 'operator'=>'LIKE' )
 *  no PHP function do it ($a+$b nor array_merge()  array_merge_recursive())
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

/** quote function
 * function to double backslashes and apostrofs
 * @param $str
 */
function quote($str) {
    return addslashes($str);
}


/** AddslashesArray function
 * function addslashes enhanced by array processing
 * @param $val
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

    if ( !get_magic_quotes_gpc() ) {
        $arr = ($method == "get") ? $_GET : $_POST;
        foreach ($arr as $k => $v) {
            if ( !is_array($skip) OR !isset($skip[$k]) ) {
                $GLOBALS[$k] = AddslashesArray($v);
            }
        }
    }
}

/** new_id function
 *  returns new unpacked md5 unique id, except these which can  force unexpected end of string
 * @param $mark
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
 *  @return a unique (long - unpacked) id from a string.
 *  Note that it will always return the same id from the same string so it
 *  can be used to compare the hashes as well as create new item id (combining
 *  item id of fed item and slice_id, for example - @see xml_fetch.php3)
 */
function string2id($str) {
    do {
        $id  = md5($str);
        $str = $str . " ";
    } while ((strpos($id, '00')!==false) OR (strpos($id, '27')!==false) OR (substr($id,30,2)=='20'));
      // '00' is end of string, '27' is ' and packed '20' is space,
      // which is removed by MySQL
    return $id;
}

/** pack_id function
 * @param $unpacked_id
 * @return packed md5 id, not quoted !!!
 * Note that pack_id is used in many places where it is NOT 128 bit ids.
 */
function pack_id($unpacked_id) {
    /*
    global $errcheck;
    if ($errcheck && !preg_match("/^[0-9a-f]+$/", $unpacked_id)) // Note was + instead {32}
         huhe("Warning: trying to pack $unpacked_id.<br>\n");
    */
    return ((string)$unpacked_id == "0" ? "0" : @pack("H*",trim($unpacked_id)));
}

/** unpack_id
 * @param $packed_id
 * @return unpacked md5 id
 */
function unpack_id($packed_id){
    return ((string)$packed_id != '0') ? bin2hex($packed_id) : '0';   // unpack("H*", $str) does not work in PHP 4.0.3 so bin2hex used
}

/** now function
 *  returns current date/time as timestamp;
 *  @param $step - time could be returned in steps (good for database query speedup)
 */
function now($step=false) {
    return (($step!='step') ?
        time() :
        ((int)(time()/QUERY_DATE_STEP)+1)*QUERY_DATE_STEP);     // round up
}

/** detect_browser function
 * function which detects the browser
 */
function detect_browser() {
  global $BName, $BVersion, $BPlatform;

  // Browser
  if (eregi("(msie) ([0-9]{1,2}.[0-9]{1,3})",$_SERVER['HTTP_USER_AGENT'],$match))
    { $BName = "MSIE"; $BVersion=$match[2]; }
  elseif (eregi("(opera) ([0-9]{1,2}.[0-9]{1,3}){0,1}",$_SERVER['HTTP_USER_AGENT'],$match) || eregi("(opera/)([0-9]{1,2}.[0-9]{1,3}){0,1}",$_SERVER['HTTP_USER_AGENT'],$match))
    { $BName = "Opera"; $BVersion=$match[2]; }
  elseif (eregi("(konqueror)/([0-9]{1,2}.[0-9]{1,3})",$_SERVER['HTTP_USER_AGENT'],$match))
    { $BName = "Konqueror"; $BVersion=$match[2]; }
  elseif (eregi("(lynx)/([0-9]{1,2}.[0-9]{1,2}.[0-9]{1,2})",$_SERVER['HTTP_USER_AGENT'],$match))
    { $BName = "Lynx"; $BVersion=$match[2]; }
  elseif (eregi("(links) \(([0-9]{1,2}.[0-9]{1,3})",$_SERVER['HTTP_USER_AGENT'],$match))
    { $BName = "Links"; $BVersion=$match[2]; }
  elseif (eregi("(netscape6)/(6.[0-9]{1,3})",$_SERVER['HTTP_USER_AGENT'],$match))
    { $BName = "Netscape"; $BVersion=$match[2]; }
  elseif (eregi("Gecko/",$_SERVER['HTTP_USER_AGENT']))
    { $BName = "Mozilla"; $BVersion="6";}
  elseif (eregi("mozilla/5",$_SERVER['HTTP_USER_AGENT']))
    { $BName = "Netscape"; $BVersion="Unknown"; }
  elseif (eregi("(mozilla)/([0-9]{1,2}.[0-9]{1,3})",$_SERVER['HTTP_USER_AGENT'],$match))
    { $BName = "Netscape"; $BVersion=$match[2]; }
  elseif (eregi("w3m",$_SERVER['HTTP_USER_AGENT']))
    { $BName = "w3m"; $BVersion="Unknown"; }
  else{$BName = "Unknown"; $BVersion="Unknown";}

  // System
  if (eregi("win32",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "Windows";
  }
  elseif ((eregi("(win)([0-9]{2})",$_SERVER['HTTP_USER_AGENT'],$match)) || (eregi("(windows) ([0-9]{2})",$_SERVER['HTTP_USER_AGENT'],$match))) {
    $BPlatform = "Windows $match[2]";
  }
  elseif (eregi("(winnt)([0-9]{1,2}.[0-9]{1,2}){0,1}",$_SERVER['HTTP_USER_AGENT'],$match)) {
    $BPlatform = "Windows NT $match[2]";
  }
  elseif (eregi("(windows nt)( ){0,1}([0-9]{1,2}.[0-9]{1,2}){0,1}",$_SERVER['HTTP_USER_AGENT'],$match)) {
    $BPlatform = "Windows NT $match[3]";
  }
  elseif (eregi("linux",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "Linux";
  }
  elseif (eregi("mac",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "Macintosh";
  }
  elseif (eregi("(sunos) ([0-9]{1,2}.[0-9]{1,2}){0,1}",$_SERVER['HTTP_USER_AGENT'],$match)) {
    $BPlatform = "SunOS $match[2]";
  }
  elseif (eregi("(beos) r([0-9]{1,2}.[0-9]{1,2}){0,1}",$_SERVER['HTTP_USER_AGENT'],$match)) {
    $BPlatform = "BeOS $match[2]";
  }
  elseif (eregi("freebsd",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "FreeBSD";
  }
  elseif (eregi("openbsd",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "OpenBSD";
  }
  elseif (eregi("irix",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "IRIX";
  }
  elseif (eregi("os/2",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "OS/2";
  }
  elseif (eregi("plan9",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "Plan9";
  }
  elseif (eregi("unix",$_SERVER['HTTP_USER_AGENT']) || eregi("hp-ux",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "Unix";
  }
  elseif (eregi("osf",$_SERVER['HTTP_USER_AGENT'])) {
    $BPlatform = "OSF";
  }
  else{$BPlatform = "Unknown";}

  if ($GLOBALS['debug']) {
      huhl($_SERVER['HTTP_USER_AGENT']. " => $BName,$BVersion,$BPlatform");
  }
}

/** debug function
 *  variable count of variables
 */
function debug() {
    // could be toggled from Item Manager left menu 'debug' (by Superadmins!)
    if ( $_COOKIE['aa_debug'] != 1 ) {
        return;
    }
    echo "<pre>\n";
    $messages = func_get_args();
    foreach ( $messages as $msg ) {
        huhlo( $msg );
        echo "<br>\n";
    }
    echo "</pre>\n";
}

/** huh function
 * debug function for printing debug messages
 * @param $msg
 * @param $name
 */
function huh($msg, $name="") {
    global $debugtimes,$debugtimestart;
    if ($debugtimes) {
        if (! $debugtimestart) {
            $debugtimestart = get_microtime();
        }
        print("Time: ".(get_microtime() - $debugtimestart)."\n");
    }
    if ( @is_array($msg) OR @is_object($msg) ) {
        echo "<br>\n$name";
        print_r($msg);
    } else {
        echo "<br>\n$name$msg";
    }
}

/** huhw function
 * debug function for printing debug messages escaping HTML
 * @param $msg
 */
function huhw($msg) {
    if (!$GLOBALS['debug'] ) {
        return;
    }
    echo "<br>\n". HTMLspecialChars($msg);
}

/** huhe function
 * Report only if errcheck is set, this is used to test for errors to speed debugging
 * Use to catch cases in the code which shouldn't exist, but are handled anyway.
 * @param $a
 * @param $b
 * @param $c
 * @param $d
 * @param $e
 * @param $f
 * @param $g
 * @param $h
 * @param $i
 * @param $j
 */
function huhe($a, $b="", $c="",$d="",$e="",$f="",$g="",$h="",$i="",$j="") {
    global $errcheck;
    if ($errcheck) {
        huhl($a, $b, $c,$d,$e,$f,$g,$h,$i,$j);
        if ($GLOBALS["trace"] || $GLOBALS["debug"]) { trace("p"); }
    }
}
/** huhlo function
 *  Only called from within huhl
 * @param $a
 */
function huhlo($a) {
    if (isset($a)) {
        if (is_object($a) && is_callable(array($a,"printobj"))) {
            $a->printobj();
        } else {
            print_r($a);
        }
        echo "<br>\n";
    }
}

if ( !$timestart ) {
    $timestart = get_microtime();
}
/** get_microtime function
 *
 */
function get_microtime() {
    list($usec, $sec) = explode(" ",microtime());
    return ((float)$usec + (float)$sec);
}

// Set a starting timestamp, if checking times, huhl can report
/** huhl function
 * Debug function to print debug messages recursively - handles arrays
 */
function huhl() {
    global $debugtimes,$debugtimestart;
    print("<listing>");
    if ($debugtimes) {
       if (! $debugtimestart) {
            $debugtimestart = get_microtime();
        }
        print("Time: ".(get_microtime() - $debugtimestart)."\n");
    }
    $vars = func_get_args();
    foreach ($vars as $var) {
        huhlo($var);
    }
    print("</listing>\n");
}
/** huhsess function
 * @param $msg
 */
function huhsess($msg="") {
    global $sess;
    foreach (array_keys($sess->pt) as $i) {
        $sessvars[$i]=$GLOBALS[$i];
    }
    huhl($msg,$sessvars);
}

/** PrintArray function
 * @param $a
 * Prints all values from array
 */
function PrintArray($a) {
    if (is_array($a)) {
        while ( list( $key, $val ) = each( $a ) ) {
            if (is_array($val)) {
               PrintArray($val);
            } else {
               echo $val;
            }
        }
    }
}

/** MsgOK function
 * Prepare OK Message
 * @param $txt
 */
function MsgOK($txt){
    return "<div class=\"okmsg\">$txt</div>";
}

/** MsgERR function
 * Prepare Err Message
 * @param $txt
 */
function MsgERR($txt){
    return "<div class=\"err\">$txt</div>";
}

/** UnpackFieldsToArray function
 * function for unpacking string in edit_fields and needed_fields in database to array
 * @param $packed
 * @param $fields
 */
function UnpackFieldsToArray($packed, $fields) {
    $i=0;
    $arr = array();
    foreach ($fields as $field => $foo) {
        $arr[$field] = (substr($packed,$i++,1)=="y" ? true : false);
    }
    return $arr;
}

/** GetConstants function
 *  Function fills the array from constants table
 * @param $group
 * @param $order
 * @param $column - column used as values. We can use 'name' as well as
 *                   'const_name' for name of fields
 * @param $keycolumn
 */
function GetConstants($group, $order='pri', $column='name', $keycolumn='value') {
    // we can use 'const_name' instedad of real name of the column 'name' => translate
    $order     = str_replace( 'const_', '', $order);
    $column    = str_replace( 'const_', '', $column);
    $keycolumn = str_replace( 'const_', '', $keycolumn);

    $db_order     = $order;
    $db_column    = ($column    == 'level') ? 'ancestors' : $column;
    $db_keycolumn = ($keycolumn == 'level') ? 'ancestors' : $keycolumn;

    $const_fields = array('id'=>1,'group_id'=>1,'name'=>1,'value'=>1,'class'=>1,'pri'=>1,'ancestors'=>1,'description'=>1,'short_id'=>1);

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
    $db->tquery($SQL);

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
 */
function GetModuleInfo($module_id, $type) {
    global $MODULES;
    if (!$module_id) {
        return false;
    }
    $p_module_id = q_pack_id($module_id);

    $SQL = "SELECT * FROM " .$MODULES[$type]['table']. " WHERE id = '$p_module_id'";
    $ret = GetTable2Array($SQL, 'aa_first', 'aa_fields');
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
 */
function GetSliceInfo($slice_id) {
    return GetModuleInfo($slice_id,'S');
}

/** GetTable2Array function
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
    $db->tquery($SQL);

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

// -------------------------------------------------------------------------------

/** CreateBinCondition function
 *  Returns part of SQL command used in where related to bins
 * @param $bin
 * @param $ignore_expiry_date
 */
function CreateBinCondition($bin, $table, $ignore_expiry_date=false) {
    // now is rounded in order the time is in steps - it is better for search
    // caching - SQL is THE SAME during one time step
    $now = now('step');            // round up

    /* new version of bin selecting, now we use type of bin from constants.php3 */
    if (is_numeric($bin)) {
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
    if ($numeric_bin == (AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING | AA_BIN_TRASH)) {
        return ' 1=1 ';
    } elseif ($numeric_bin == (AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING)) {
        return " $table.status_code=1 ";
    } elseif ($numeric_bin == (AA_BIN_ACTIVE | AA_BIN_PENDING)) {
        return " $table.status_code=1 AND ($table.expiry_date > '$now') ";
    } else {
        $or_conds = array();
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
        if ($numeric_bin & AA_BIN_HOLDING) {
            $or_conds[] = " $table.status_code=2 ";
        }
        if ($numeric_bin & AA_BIN_TRASH) {
            $or_conds[] = " $table.status_code=3 ";
        }
        switch (count($or_conds)) {
            case 0:  return ' 1=1 ';
            case 1:  return ' '. $or_conds[0] .' ';
            default: return ' (('. join(') OR (', $or_conds) .')) ';
        }
    }

    return ' 1=1 ';
}


/** itemContent_getWhere function
 *  helper function for GetItemContent and such functions
 * @param $zids
 * @param $use_short_ids
 */
function itemContent_getWhere($zids, $use_short_ids=false) {
    // convert array or single value to zids
    if ( !is_object($zids) ) {
        $zids = new zids( $zids, $use_short_ids ? 's' : 'l' );
    }
    $sel_in = $zids->sqlin( '' );
    if ($zids->onetype() == "t") {
        $settags = true;  // Used below
    }
    return array( $sel_in, $settags );
}

/** GetItemContent function
 * Basic function to get item content. Use this function, not direct SQL queries.
 * @param $zids
 * @param $use_short_ids
 *   @param bool  $ignore_reading_password
 *       Use carefully only when you are sure the data is used safely and not viewed
 *       to unauthorized persons.
 *   @param array $fields2get
 *       restrict return fields only to listed fields (so the content4id array
 *       is not so big)
 *       like: array('headline........', 'category.......1')
 */
function GetItemContent($zids, $use_short_ids=false, $ignore_reading_password=false, $fields2get=false, $crypted_additional_slice_pwd=null, $bin=null) {
    // Fills array $content with current content of $sel_in items (comma separated ids).
    $db = getDB();

    // construct WHERE clause
    list($sel_in, $settags) = itemContent_getWhere($zids, $use_short_ids);
    if (!$sel_in) {
        freeDB($db);
        return false;
    }

    // get content from item table
    $delim = "";

    if ( is_object($zids) ) {
        if ( $zids->onetype() == 's' ) {
            $use_short_ids = true;
        }
    }

    $metabase       = AA_Metabase::singleton();

    // if the output fields are restricted, restrict also item fields
    if ( $fields2get ) {
        $item_sql_fields = $fields2get;

        $content_fields = array();
        $item_fields    = array();
        foreach ( (array)$fields2get as $field_name ) {
            //convert publish_date.... to publish_date
            $clean_name = AA_Fields::getFieldType($field_name);

            if ( $metabase->isColumn('item', $clean_name) ) {
                $item_fields[]    = $clean_name;
            } else {
                $content_fields[] = $field_name;
            }
        }

        // save it (without possibel new fields 'id' and 'slice_id' - see below)
        $real_item_fields2get = $item_fields;

        // we need item id for $content index
        if ( !in_array('id', $item_fields) ) {
            $item_fields[] = 'id';
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

    $id_column = ($use_short_ids ? "short_id" : "id");
    $SQL       = "SELECT $item_fields_sql FROM item WHERE $id_column $sel_in";

    // when we contruct tree, we want to use only current item, for example
    if (!is_null($bin)) {
        $SQL .= ' AND '. CreateBinCondition($bin, 'item');
    }
    $db->tquery($SQL);

    $n_items = 0;

    $credentials = AA_Credentials::singleton();
    // returned ids (possibly removed items in trash, ...)
    $ids         = array();
    while ( $db->next_record() ) {

        // proove permissions for password-read-protected slices
        $reading_permitted = $ignore_reading_password ? true : $credentials->checkSlice(unpack_id($db->f("slice_id")), $crypted_additional_slice_pwd);
        $item_permitted[$db->f("id")] = $reading_permitted;

        $unpack_id = unpack_id($db->f("id"));
        $ids[]     = $db->f("id");
        $n_items = $n_items+1;

        // reset( $db->Record );
        if ( $use_short_ids ) {
            $foo_id = $db->f("short_id");
            $translate[$unpack_id] = $foo_id; // id -> short_id
        } else {
            $foo_id = $unpack_id;
        }

        // Note that it stores into the $content[] array based on the id being used which
        // could be either shortid or longid, but is NOT tagged id.
        if ($reading_permitted) {
            foreach ($real_item_fields2get as $item_fid) {
                // FLAG_HTML do not means in fact, that the content is in HTML, but it rather means, that we should not call txt2html function on the content
                // we do not need to call txt2html() to any of the item table fields
                $content[$foo_id][AA_Fields::createFieldId($item_fid)][] = array("value" => $db->f($item_fid), "flag"  => FLAG_HTML);
            }
        } else {
            $error_msg = _m("Error: Missing Reading Password");
            foreach ($real_item_fields2get as $item_fid) {
                $content[$foo_id][AA_Fields::createFieldId($item_fid)][] = array("value" => $error_msg, "flag"  => FLAG_HTML);
            }
            // fill at least following fields with correct values - we need id.............. for AA_Items::getItems()
            $content[$foo_id]['id..............'][0]['value'] =  $db->f('id');
            $content[$foo_id]['slice_id........'][0]['value'] =  $db->f('slice_id');
        }
    }

    // Skip the rest if no items found
    if ($n_items == 0) {
        freeDB($db);
        return null;
    }

    // If its a tagged id, then set the "idtag..........." field
    if ($settags) {
        $tags = $zids->gettags();
        while ( list($k,$v) = each($tags)) {
            $content[$k]["idtag..........."][] = array("value" => $v);
        }
    }

    // construct WHERE query to content table
    $new_sel_in = sqlin('item_id', $ids);

    if ( isset( $fields2get ) AND is_array( $fields2get ) ) {
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

        $SQL = "SELECT * FROM content WHERE $new_sel_in $restrict_cond ORDER BY content.number"; // usable just for constants

        $db->tquery($SQL);

        while ( $db->next_record() ) {
            $item_id = $db->f("item_id");
            $fooid   = ($use_short_ids ? $translate[unpack_id($item_id)] : unpack_id($item_id) );

            if ( !$item_permitted[$item_id] ) {
                $content[$fooid][$db->f("field_id")][0] = array( "value" => _m("Error: Missing Reading Password"));
                continue;
            }

            // which database field is used (from 05/15/2004 we have FLAG_TEXT_STORED set for text-field-stored values
            $flag     = $db->f("flag");
            if ( (strlen($db->f("text"))>0) OR ($flag & FLAG_TEXT_STORED) ) {
                $content[$fooid][$db->f("field_id")][] = array( "value" => $db->f('text'), "flag"  => $flag);
            } else {
                // we can set FLAG_HTML, because the text2html gives the same result as the number itself
                // if speeds the item->f_h() function a bit
                $content[$fooid][$db->f("field_id")][] = array( "value" => $db->f('number'), "flag"  => ($flag|FLAG_HTML));
            }
        }
    }

    // add special fields to all items (zids)
    foreach ($content as $iid => $foo ) {
        // slice_id... and id... is packed  - add unpacked variant now
        $content[$iid]['u_slice_id......'][] =
            array('value' => unpack_id($content[$iid]['slice_id........'][0]['value']));
        $content[$iid]['unpacked_id.....'][] =
            array('value' => unpack_id($content[$iid]['id..............'][0]['value']));
    }

    freeDB($db);
    return $content;   // Note null returned above if no items found
}

/** GetItemContent_Short function
 *  fills content arr with current content of $sel_in items (comma separated short ids)
 * @param $ids
 */
function GetItemContent_Short($ids) {
    GetItemContent($ids, true);
}

/** GetItemContentMinimal function
 *  The same as GetItemContent function, but it returns just id and short_id
 *  (or other fields form item table - specified in $fields2get) for the item
 *  (used in URL listing view @see view_type['urls']).
 *  If $fields2get is specified, it MUST contain at least 'id'.
 * @param $zids
 * @param $fields2get
 */
function GetItemContentMinimal($zids, $fields2get=false) {
  if ( !$fields2get ) {
      $fields2get = array( 'id', 'short_id' );
  }
  $db      = getDB();
  $columns = join(',',$fields2get);

  // construct WHERE clause
  list($sel_in, $settags) = itemContent_getWhere($zids);
  if ($sel_in) {
      // get content from item table
      $delim = "";
      $SQL   = "SELECT $columns FROM item WHERE id $sel_in";
      $db->tquery($SQL);
      $n_items = 0;
      while ( $db->next_record() ) {
          $n_items++;
          $foo_id = unpack_id($db->f("id"));
          foreach ( $fields2get as $fld ) {
              $content[$foo_id][AA_Fields::createFieldId($fld)][] = array("value" => $db->f($fld));
          }
      }
  }

  freeDB($db);
  return ($n_items == 0) ? null : $content;   // null returned if no items found
}

/** GrabConstantColumn function
 * @param $db
 * @param $column
 */
function GrabConstantColumn(&$db, $column) {
    switch ($column) {
        case "name":        return array( "value"=> $db->f("name") );
        case "value":       return array( "value"=> $db->f("value"), "flag" => FLAG_HTML );
        case "pri":         return array( "value"=> $db->f("pri") );
        case "group":       return array( "value"=> $db->f("group_id") );
        case "class":       return array( "value"=> $db->f("class") );
        // case "counter":     return array( "value"=> $i++ );
        case "id":          return array( "value"=> unpack_id($db->f("id") ));
        case "description": return array( "value"=> $db->f("description"), "flag" => FLAG_HTML);
        case "short_id":    return array( "value"=> $db->f("short_id") );
        case "level":       return array( "value"=> strlen($db->f("ancestors"))/16);
    }
    return array();
}

/** GetConstantContent function
 *  Fills Abstract data srtructure for Constants
 * @param $zids
 */
function GetConstantContent( $zids ) {
    if ( !$zids ) {
        return false;
    }
  $db = getDB();

  $SQL = 'SELECT * FROM constant WHERE short_id '. $zids->sqlin(false);
  $db->tquery( $SQL );
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
                $content[$foo_id][$prefix . $key][] = array('value' => $val);
            }
        }
    }
}

// -------------------------------------------------------------------------------
/** GetHeadlineFieldID function
 * @param $sid
 * @param $slice_field
 */
function GetHeadlineFieldID($sid, $slice_field="headline.") {
  $db = getDB();

  // get id of headline field
  $SQL = "SELECT id FROM field
           WHERE slice_id = '". q_pack_id( $sid ) ."'
             AND id LIKE '$slice_field%'
        ORDER BY id";
  $db->query( $SQL );
  $ret = ( $db->next_record() ? $db->f(id) : false );
  freeDB($db);
  return $ret;
}

// -------------------------------------------------------------------------------
/** GetCategoryGroupId function
 * returns group_id from $show_input_func string
 * @param $input_show_func
 */
function GetCategoryGroupId($input_show_func) {
    $arr = explode( ":", $input_show_func);
    return $arr[1];
}

/** GetCategoryGroup function
 * find group_id for constants of the slice
 * @param $slice_id
 * @param $field
 */
function GetCategoryGroup($slice_id, $field='') {
    global $db;

    $condition = $field ? "id = '$field'" : "id LIKE 'category%'";
    $SQL       = "SELECT input_show_func FROM field
              WHERE slice_id='". q_pack_id($slice_id) ."'
                AND $condition
                ORDER BY id";  // first should be category........,
                               // then category.......1, etc.
    $db->tquery($SQL);
    if ( $db->next_record() ){
        return GetCategoryGroupId($db->f('input_show_func'));
    } else {
        return false;
    }
}

// -------------------------------------------------------------------------------

/** GetId4Sid function
 * get id from item short id
 * @param $sid
 */
function GetId4Sid($sid) {
    global $db;

    if (!$sid) {
        return false;
    }
    $SQL = "SELECT id FROM item WHERE short_id='$sid'";
    $db->query( $SQL );
    return ($db->next_record() ? unpack_id($db->f("id")) : false);
}

// -------------------------------------------------------------------------------

/** GetSid4Id function
 * get short item id item short id
 * @param $iid
 */
function GetSid4Id($iid) {
    global $db;

    if (!$iid) {
        return false;
    }
    $SQL = "SELECT short_id FROM item WHERE id='". q_pack_id($iid) ."'";
    $db->query( $SQL );
    return ($db->next_record() ? $db->f("short_id") : false);
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

/** safe function
 * @return html safe code (used for preparing variable to print in form)
 */
function safe( $var ) {
    return htmlspecialchars( magic_strip($var) );  // stripslashes function added because of quote varibles sended to form before
}

/** richEditShowable function
 * is the browser able to show rich edit box? (using triedit.dll)
 */
function richEditShowable() {
    global $BName, $BVersion, $BPlatform;
    global $showrich;
    detect_browser();
    // Note that Macintosh IE 5.2 does not support either richedit or current iframe
    // Mac Omniweb/4.1.1 detects as Netscape 4.5 and doesn't support either
    return (($BName == "MSIE" && $BVersion >= "5.0" && $BPlatform != "Macintosh") || $showrich > "");
    // Note that RawRichEditTextarea could force iframe for certain BPlatform
}

/** HtmlPageBegin function
 * Prints HTML start page tags (html begin, encoding, style sheet, but no title).
 * Chooses the right encoding by get_mgettext_lang().
 * @param string $stylesheet  if empty, no StyleSheet tag is printed
 * @param bool   $js_lib      if true, includes js_lib.js javascript
 * @param $lang
 */
function HtmlPageBegin($stylesheet='default', $js_lib=false, $lang=null) {
    if ($stylesheet == "default") {
        $stylesheet = AA_INSTAL_PATH .ADMIN_CSS;
    }
    echo
'<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
  "http://www.w3.org/TR/html4/loose.dtd">
  <html>
    <head>
      <link rel="SHORTCUT ICON" href="'. AA_INSTAL_PATH .'images/favicon.ico">';
    if ($stylesheet) {
        echo '
      <link rel="stylesheet" href="'.$stylesheet.'" type="text/css" media="all">
      <style type="text/css" media="print">
        .noprint, .aa_manager_actions { display: none; }
        body, #hlavnitbl { background-color: #FFFFFF;  }
      </style>
      ';
    }

    $charset = $GLOBALS["LANGUAGE_CHARSETS"][$lang ? $lang : get_mgettext_lang()];
    echo "<!--$lang-->";
    echo "\n     <meta http-equiv=\"Content-Type\" content=\"text/html; charset=$charset\">\n";
    if ($js_lib) {
        FrmJavascriptFile( 'javascript/js_lib.js' );
    }
}

// use instead of </body></html> on pages which show menu
function HtmlPageEnd() {
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
/** getHtmlPage function
 * @param $param
 */
function getHtmlPage($param='') {
    $ret  = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">';
    $ret .= "\n<html>\n<head>";
    if ($param['title']) {
        $ret .= "\n    <title>".$param['title']."</title>";
    }
    foreach ( (array)$param['css'] as $css ) {
        $ret .= "\n    <link rel=\"StyleSheet\" href=\"".$css."\" type=\"text/css\">";
    }
    foreach ( (array)$param['js'] as $js ) {
        $ret .= "\n    ". getJavascriptFile( $js );
    }
    $ret .= "\n</head>\n<body>\n";
    $ret .= $param['body'];
    $ret .= "\n</body>\n</html>";
    return $ret;
}
/** FrmHtmlPage function
 * @param $param
 */
function FrmHtmlPage($param='') {
    echo getHtmlPage($param);
}

/** MsgPage function
 * Displays page with message and link to $url
 * @param $url - where to go if user clicks on Back link on this message page
 * @param $msg - displayed message
 * @param $dummy - was used in past, now you should use MsgPageMenu from msgpage.php3
 */
function MsgPage($url, $msg, $dummy="standalone") {
  HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)

  echo "<title>"._m("Toolkit news message")."</title>
    </head>
  <body>";

  if ( isset($msg) AND is_array($msg))
    PrintArray($msg);
   else
    echo "<p>$msg</p><br><br>";
  echo "<a href=\"$url\">"._m("Back")."</a>";
  echo "</body></html>";
  page_close();
  exit;
}

function StoreToContent($item_id, $field, $value, $additional='') {
    $varset = new Cvarset();
    $varset->clear();
    if ($field["text_stored"]) {
        // do not store empty values in content table for text_stored fields
        // if ( !$value['value'] ) { return false; }    // can't do it, conditions do not work then (ecn joblist)
        $varset->add("text", "text", $value['value']);
        // set "TEXT stored" flag
        $varset->add("flag", "number", (int)$value['flag'] | FLAG_TEXT_STORED );
        if (is_numeric($additional["order"])) {
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
    $varset->add("item_id", "unpacked", $item_id);
    $varset->add("field_id", "text", $field["id"]);
    $varset->doInsert('content');
}

/** is_field_type_numerical function
 * @param $field_type
 */
function is_field_type_numerical($field_type) {
    return in_array($field_type, array("float","double","decimal","int","timestamp"));
}

// -----------------------------------------------------------------------------
/** CopyTableRows function
 *  Copies rows within a table changing only given columns and omitting given columns.
 *   @author Jakub Adámek
 *   @return bool  true if all additions succeed, false otherwise
 *
 *   @param string $table    table name
 *   @param string $where    where condition (filter)
 *   @param array  $set_columns  array ($column_name => $value, ...) - fields the value of which will be changed
 *   @param array  $omit_columns [optional] array ($column_name, ...) - fields to be omitted
 *   @param array  $id_columns   [optional] array ($column_name, ...) - fields with the 16 byte ID to be generated for each row a new one
 */
function CopyTableRows($table, $where, $set_columns, $omit_columns = "", $id_columns = "") {
    if (!$omit_columns) {
        $omit_columns = array();
    }
    if (!$id_columns) {
        $id_columns = array();
    }

    if ($GLOBALS['debug']) {
        echo "CopyTableRows: SELECT * FROM $table WHERE $where<br>
        set_columns = ";
        print_r ($set_columns);
        echo "<br>omit_columns = ";
        print_r ($omit_columns);
        echo "<br>";
    }

    $db     = getDB();
    $varset = new CVarset();

    $columns = $db->metadata($table);
    freeDB($db);

    if ($GLOBALS['debug']) {
        $rows = 0;
    }

    $data = GetTable2Array("SELECT * FROM $table WHERE $where", "NoCoLuMn");

    if ($GLOBALS['debug']) {
        echo "data: "; print_r ($data); echo "<br>";
    }

    if (!is_array($data)) {
        return true;
    }

    reset ($data);
    while (list (,$datarow) = each ($data)) {
        $varset->Clear();
        reset ($columns);

        // create the varset
        while (list (,$col) = each ($columns)) {
            if (in_array($col["name"], $omit_columns))
                continue;

            if (is_field_type_numerical($col["type"]))
                 $type = "number";
            else $type = "text";

            // look into $set_columns
            if (isset ($set_columns[$col["name"]]))
                 $val = $set_columns[$col["name"]];
            else if (in_array($col["name"], $id_columns))
                 $val = q_pack_id(new_id());
            else $val = $datarow[$col["name"]];

            $varset->set($col["name"],$val,$type);
        }

        if ($GLOBALS['debug']) {
            echo "Row $rows<br>"; $rows ++;
        }

        if (!tryQuery("INSERT INTO $table ".$varset->makeINSERT())) {
            return false;
        }
    }
    return true;
}

// -----------------------------------------------------------------------------
/** get_last_insert_id function
 * @param $db
 * @param $table
 */
function get_last_insert_id($db, $table) {
    $db->tquery("SELECT LAST_INSERT_ID() AS lid FROM $table");
    $db->next_record();
    return $db->f("lid");
}

// -----------------------------------------------------------------------------

/** filesuffix function
 *  returns the suffix part of the filename (beginning with the last dot (.) in the filename)
 * @param $filename
 */
function filesuffix($filename) {
    if (!strstr ($filename,".")) {
        return "";
    }
    $i = strlen($filename);
    while ($filename[$i] != ".") {
        $i --;
    }
    return substr ($filename,$i+1);
}
/** filepath function
 *
 */
function filepath($filename) {
    if (!strstr ($filename,"/")) {
        return "./";
    }
    $i = strlen($filename);
    while ($filename[$i] != "/") $i --;
    return substr ($filename,0,$i+1);
}
/** filename function
 * @param $filename
 */
function filename($filename) {
    if (!strstr ($filename,"/")) {
        return "./";
    }
    $i = strlen($filename);
    while ($filename[$i] != "/") {
        $i --;
    }
    return substr ($filename,$i+1);
}
/** GetTimeZone function
 *
 */
function GetTimeZone() {
    $d = getdate();
    return (mktime ($d['hours'],$d['minutes'],$d['seconds'],$d['mon'],$d['mday'],$d['year'])
        - gmmktime ($d['hours'],$d['minutes'],$d['seconds'],$d['mon'],$d['mday'],$d['year'])) / 3600;
}

/** gensalt function
 * generates random string of given length (useful as MD5 salt)
 * @param $saltlen
 */
function gensalt($saltlen) {
    srand((double) microtime() * 1000000);
    $salt_chars = "abcdefghijklmnoprstuvwxBCDFGHJKLMNPQRSTVWXZ0123456589";
    for ($i = 0; $i < $saltlen; $i++) {
        $salt .= $salt_chars[rand(0,strlen($salt_chars)-1)];
    }
    return $salt;
}

/** aa_move_uploaded_file function
 *  Moves uploaded file to given directory and (optionally) changes permissions
 * @param $varname
 * @param $destdir
 * @param $perms
 * @param $filename
 *   @return string  error description or empty string
 */
function aa_move_uploaded_file($varname, $destdir, $perms = 0, $filename = null) {
    endslash($destdir);
    if (!$GLOBALS[$varname]) {
        return "No $varname?";
    }
    if ($filename == "") {
        // get filename and replace bad characters
        $filename = eregi_replace("[^a-z0-9_.~]","_",$GLOBALS[$varname."_name"]);
    }

    if (!is_dir($destdir)) {
        return _m("Internal error. File upload: Dir does not exist?!");
    }

    if (file_exists("$destdir$filename")) {
        return _m("File with this name already exists."). " $destdir$filename";
    }

    // copy the file from the temp directory to the upload directory, and test for success

    if (is_uploaded_file($GLOBALS[$varname])) {
        if (!move_uploaded_file($GLOBALS[$varname], "$destdir$filename")) {
            return sprintf(_m("Can't move image  %s to %s"), $GLOBALS[$varname],"$destdir$filename");
        } elseif ($perms) {
            chmod ($destdir.$filename, $perms);
        }
    }
    return "";
}

// ---------------------------------------------------------------------------------------------

/** split_escaped function
 *  like PHP split, but additionally provides $escape_pattern to stand for occurences of $pattern,
 *  e.g. split_escaped (":", "a#:b:c", "#:") returns array ("a:b","c")
 * @param $pattern
 * @param $string
 * @param $escape_pattern
 */
function split_escaped($pattern, $string, $escape_pattern) {
    $dummy = '~#$?_';
    while (strpos($string, $dummy) !== false) {
        $dummy .= '^';   // add another strange character to the
    }
    $string  = str_replace($escape_pattern, $dummy, $string);
    $strings = explode($pattern, $string);
    foreach ($strings as $key => $val) {
        $strings[$key] = (string)str_replace($dummy, $pattern, $val);
    }
    return $strings;
}
/** join_escaped function
 * @param $pattern
 * @param $strings
 * @param $escape_pattern
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
 */
function join_and_quote( $pattern, $strings ) {
    foreach ((array)$strings as $string) {
        if ($retval) {
            $retval .= $pattern;
        }
        $retval .= addslashes($string);
    }
    return $retval;
}

/** magic_strip function
 *  stripslashes if magic quotes are set
 * @param $val
 */
function magic_strip($val) {
    return get_magic_quotes_gpc() ? StripslashesArray($val) : $val;
}
/** magic_add function
 * @param $str
 */
function magic_add($str) {
    return (get_magic_quotes_gpc() ? $str : addslashes($str));
}
/** isdigit function
 * @param $c
 */
function isdigit($c) {
    return $c >= "0" && $c <= "9";
}
/** isalpha function
 * @param $c
 */
function isalpha($c) {
    return ($c >= "a" && $c <= "z") || ($c >= "A" && $c <= "Z");
}
/** isalnum function
 * @param $c
 */
function isalnum($c) {
    return ($c >= "0" && $c <= "9") || ($c >= "a" && $c <= "z") || ($c >= "A" && $c <= "Z");
}
/** gdf_error function
 * @param $x
 */
function gfd_error($x) {
    echo "Unrecognized date format charcacter $x";
    exit;
}

/**  get_formatted_date function
 * @param $datestring
 * @param $format
 *   @return the Unix timestamp counted from the formatted date string.
 *   Does not check the date format, rather returns nonsence values for
 *   wrong date strings.
 *   Uses non-format letters as separators only,
 *   i.e. "2.3.2002" is parsed the same as "2/3/2002" or even "2;3#2002".
 */
function get_formatted_date($datestring, $format) {
    // don't work with empty string
    if (!$datestring) {
        return "";
    }

    // Split the date into parts consisting only of digits or only of letters
    for ($i = 0; $i < strlen ($datestring); $i++) {
        if (isalpha($datestring[$i]) && ($s == "" || isalpha($datestring[$i-1]))) {
            $s .= $datestring[$i];
        } elseif (isdigit($datestring[$i]) && ($s == "" || isdigit($datestring[$i-1]))) {
            $s .= $datestring[$i];
        } elseif ($s) {
            $dateparts[] = $s;
            $s = "";
        }
    }
    if ($s) {
        $dateparts[] = $s;
    }

    // Split the format into parts consisting of one letter
    for ($i = 0; $i < strlen ($format); $i++) {
        if (isalpha($format[$i])) {
            $formatparts[] = $format[$i];
        }
    }

    $month_names = array ("January"=>1,"February"=>2,"March"=>3,"April"=>4,"May"=>5,"June"=>6,
                          "July"=>7,"August"=>8,"September"=>9,"October"=>10,"November"=>11,"December"=>12);
    $month3_names = array ("Jan"=>1,"Feb"=>2,"Mar"=>3,"Apr"=>4,"May"=>5,"Jun"=>6,"Jul"=>7,"Aug"=>8,"Sep"=>9,"Oct"=>10,"Nov"=>11,"Dec"=>12);

    // assing date parts to format parts
    for ($i = 0; $i < count ($dateparts); $i ++) {
        $d = $dateparts[$i];
        switch ($formatparts[$i]) {
            case 'a': $pm = $d == "pm"; break;
            case 'A': $pm = $d == "PM"; break;
            case 'B': gfd_error ('B'); break;
            case 'd': $day = $d; break;
            case 'D': break;
            case 'F': $month = $month_names[$d]; break;
            case 'g':
            case 'h': $hour = $d; $use_pm = true; break;
            case 'H':
            case 'G': $hour = $d; $use_pm = false; break;
            case 'i': $minute = $d; break;
            case 'I': break;
            case 'j': $day = $d; break;
            case 'l': break;
            case 'L': break;
            case 'n':
            case 'm': $month = $d; break;
            case 'M': $month = $month3_names[$d]; break;
            case 'O': break;
            case 'r': gfd_error ('r'); break;
            case 's': $second = $d; break;
            case 'S': break;
            case 't': break;
            case 'T': break;
            case 'U': return $d; break;
            case 'w': break;
            case 'W': gfd_error ('W'); break;
            case 'Y': $year = $d; break;
            case 'y': $year = $d; break; // mktime works with 2-digit year
            case 'z': $day = $d; break;
            case 'Z': break;
        }
    }

    //echo "hour $hour minute $minute second $second month $month day $day year $year pm $pm";

    if ($use_pm && $pm) {
        $hour += 12;
    }

    // mktime replaces missing values by today's values
    if (!isset ($year)) {
        if (!isset ($day)) {
            if (!isset ($month)) {
                return mktime ( $hour, $minute, $second);
            }
            else return mktime ( $hour, $minute, $second, $month);
        }
        else return mktime ( $hour, $minute, $second, $month, $day);
    }
    else return mktime ( $hour, $minute, $second, $month, $day, $year);
}
/** setdefault function
 * @param $var
 * @param $default
 */
function setdefault(&$var, $default) {
    if (!isset ($var)) {
        $var = $default;
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
    global $debugfill;
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
    $var_types = array ("post","get","files","cookie");

    foreach ($var_types as $var_type) {
        if (is_array($vars[$var_type])) {
            foreach ($vars[$var_type] as $var => $value) {
                global $$var;
                $$var = $value;
            }
        }
    }
}

/** get_email_types function
 *  List of email types with translated description.
 *  You should never list email types directly, always call this function.
 */
function get_email_types() {
    return array (
        "alerts alert" => _m("alerts alert"),
        "alerts welcome" => _m("alerts welcome"),
        "slice wizard welcome" => _m("slice wizard welcome"),
        "other" => _m("other"),
    );
}

/** monthnames function
 *  @return array month names
 */
function monthNames() {
    return array( 1 => _m('January'), _m('February'), _m('March'), _m('April'), _m('May'), _m('June'),
        _m('July'), _m('August'), _m('September'), _m('October'), _m('November'), _m('December'));
}

/** getSelectBoxFromParamWizard function
 *  Creates values for a select box showing some param wizard section.
 * @param $var
 */
function getSelectBoxFromParamWizard($var) {
    foreach ($var["items"] as $value => $prop) {
        $retval[$value] = $prop["name"];
    }
    return $retval;
}

// This pair of functions remove the guessing about which of $db $db2
// to use
// Usage: $db = getDB(); ..do stuff with sql ... freeDB($db)
//
$spareDBs = array();
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

/** tryQuery function
 *  Try a query, displaying debugging if $debug, return true on success, false on failure
 * @param $SQL
 */
function tryQuery($SQL) {
    $db  = getDB();
    $res = $db->tquery($SQL);
    freeDB($db);
    return $res;
}

/** DBFields function
 * @param $db
 * @return an array of fields, skipping numeric ones
 * @see also GetTable2Array
 */
function DBFields(&$db) {
    $a = array();
    foreach ( $db->Record as $key => $val ) {
        if ( !is_numeric($key) ) {
            $a[$key] = $val;
        }
    }
    return $a;
}
/** ShowWizardFrames function
 * @param $aa_url
 * @param $wizard_url
 * @param $title
 * @param $noframes_html
 */
function ShowWizardFrames($aa_url, $wizard_url, $title, $noframes_html="") {
    require_once AA_BASE_PATH."post2shtml.php3";
    global $post2shtml_id;
    echo
'<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Frameset//EN" "http://www.w3.org/TR/html4/frameset.dtd">
<html>
<head>
    <title>'.$title.'</title>
    <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-2">
</head>

<frameset cols="*,300" frameborder="yes" border="1" framespacing="0">
    <frame src="'.$aa_url.'&called_from_wizard=1" name="aaFrame">
    <frame src="'.con_url($wizard_url,"post2shtml_id=$post2shtml_id").'" name="wizardFrame">
</frameset>
<noframes><body>
'.$noframes_html.'
</body></noframes>
</html>';
}

/** ShowRefreshWizardJavaScript function
 *  Shows JavaScript which updates the Wizard frame, if it exists.
 */
function ShowRefreshWizardJavaScript() {
    FrmJavascript( 'if (top.wizardFrame != null) top.wizardFrame.wizard_form.submit();' );
}
/** GetAAImage function
 * @param $filename
 * @param $alt
 * @param $width
 * @param $height
 * @param $add
 * @param $add_path
 */
function GetAAImage($filename, $alt='', $width=0, $height=0, $add='', $add_path='') {
    $image_path = AA_BASE_PATH.   $add_path. "images/$filename";
    $image_url  = AA_INSTAL_PATH. $add_path. "images/$filename";
    $title      = ($alt ? "title=\"$alt\"" : '');
    if ( $width ) {
        $size = "width=\"$width\" height=\"$height\"";
    } else {
        $im_size = @GetImageSize($image_path);
        $size = $im_size[3];
    }
    return "<img border=\"0\" src=\"$image_url\" alt=\"$alt\" $title $size $add>";
}
/** GetModuleImage function
 * @param $module
 * @param $filename
 * @param $alt
 * @param $width
 * @param $height
 * @param $add
 */
function GetModuleImage($module, $filename, $alt='', $width=0, $height=0, $add='') {
    return GetAAImage($filename, $alt, $width, $height, $add, "modules/$module/");
}

/**
* Use as
*   $credentials = AA_Credentials::singleton();
*   $credentials->loadFromSlice($slice_id);
*/

class AA_Credentials {
    var $_pwd = array();     // Array of all known slice passwords (md5 for better security)

    /** AA_Credentials function
     */
    function AA_Credentials() {
        $this->_pwd = array();
    }

    /** singleton
     *  called from getSlice method
     *  This function makes sure, there is just ONE static instance if the class
     *  @todo  convert to static class variable (after migration to PHP5)
     */
    function singleton() {
        static $instance = null;
        if (is_null($instance)) {
            // Now create the AA_Credentials object
            $instance = new AA_Credentials;
        }
        return $instance;
    }

    /** main method for checking the slice_pwd */
    function checkSlice($slice_id, $crypted_additional_slice_pwd=null) {
        return $this->checkCryptedPassword(AA_Slices::getSliceProperty($slice_id,'reading_password'), $crypted_additional_slice_pwd);
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
        $this->register(AA_Slices::getSliceProperty($slice_id,'reading_password'));
    }

    function register($crypted_slice_pwd) {
        if (!empty($crypted_slice_pwd)) {
            $this->_pwd[$crypted_slice_pwd] = true;
        }
    }

    /** wrapper function
     *  static function called as AA_Credentials::encrypt($reading_password) */
    function encrypt($pwd) {
        return md5($pwd);
    }
}


$tracearr = array();
/** trace function
 * Support function for debugging, because of the lack of a stacktrace in PHP
 * @param $d = + for entering a function - for leaving = for a checkpoint.
 * @param $v
 * @param $c
 */
function trace($d,$v="NONE",$c="") {
    global $tracearr,$traceall;
    if ($traceall) {
        huhl("TRACE: $d:",$v," ",$c);
    }
// Below here you can put variables you want traced
    if ($traceall) {
        huhl("TRACE:slice_id=",$slice_id);
    }
// end variables
    switch ($d) {
    case "+": array_push($tracearr,$v,$c); break;
    case "-": array_pop($tracearr); array_pop($tracearr); break;
    case "=": array_pop($tracearr); array_push($tracearr,$c); break ;
    case "p": huhl("TRACE: ",$tracearr); break;
    default: echo "Illegal argument to trace:$d"; break;
    }
}

/** contentcache class - prevents from executing the same - time consuming code
 *  twice in one run of the script.
 *  Ussage:
 *    Instead of calling:
 *        $result = function_name(param1, param2);
 *    we will use
 *        $result = $contentcache->get_result("function_name", array(param1, param2));
 *    For the first time call the function_name is called, for second, third,...
 *    time calling, the result is returned from cache (for the same parameters)
 *
 *    The best to use this class for time-consuming functions with small results
 */
class contentcache {
    // used for global cache of contents
    var $content;

    /** global_instance function
     *  "class function" obviously called as contentcache::global_instance();
     *  This function makes sure, there is global instance of the class
     */
    function global_instance() {
        if ( !isset($GLOBALS['contentcache']) ) {
            $GLOBALS['contentcache'] = new contentcache;
        }
    }

    /** get_result function
     *  Calls $function with $params and returns its return value. The result
     *  value is then stored into cache, so next call of the $function with the
     *  same parameters is returned from cache - function is not performed.
     *  Use this feature mainly for repeating, time consuming functions!
     *
     *  @param $function - name of function or you could use also object methods
     *                     then the $function parameter should be array
     *                     (see http://php.net/manual/en/function.call-user-func.php)
     *                     For static class methods:
     *                        $result = $contentcache->get_result(array('Classname', 'function_name'), array(param1, param2));
     *                     For instance methods:
     *                        $result = $contentcache->get_result(array($this, 'function_name'), array(param1, param2));
     *  @param $params   - array of function's parameters
     *  @param $additional_params - string
     *                   - special param for cache - it is not passed to the
     *                     function but the cache counts with it (useful, if you
     *                     know, that the result of the $function depends not
     *                     only on its parameters, but also on some (global?) variable
     */
    function get_result( $function, $params=array(), $additional_params='' ) {
        $key = get_hash(func_get_args());
        return $this->get_result_by_id($key, $function, $params);
    }

    /** sometimes it is quicker to not count the key automaticaly (in case of object call) */
    function get_result_by_id($key, $function, $params) {
        if ( isset( $this->content[$key]) ) {
            return $this->content[$key];
        }
        $val = call_user_func_array($function, $params);
        $this->content[$key] = $val;
        return $val;
    }

    /** set function
     *  set new value for key $key
     * @param $access_code
     * @param $val
     */
    function set($access_code, &$val) {
        $this->content[md5($access_code)] = $val;
    }

    /** get function
     *  Get value for $access_code.
     * @param $access_code
     *  @return false if the value is not cached for the $access_code (use ===)
     */
    function get($access_code) {
        $key = md5($access_code);
        if ( isset($this->content[$key]) ) {
            return $this->content[$key];
        }
        return false;
    }

    /** clear function
     * clear key or all content from contentcache
     * @param $key
     */
    function clear($key="") {
        if ($key) {
            unset($this->content[$key]);
        } else {
            unset($this->content);
        }
    }

// end of contentcache class
}


function get_hash() {
    $arg_list = func_get_args();   // must be asssigned to the variable
    // return md5(json_encode($arg_list));
    // return md5(var_export($arg_list, true));
    // return md5(serialize($arg_list));
    return hash('md5', serialize($arg_list));  // quicker than md5()
}

/*
function get_hash() {
    global $md5serial;
    $arg_list = func_get_args();   // must be asssigned to the variable
    // return md5(json_encode($arg_list));
    //$md5serial = '';
    array_walk_recursive($arg_list, 'test_print');
    return md5($md5serial);
}

function test_print($item, $key) {
    global $md5serial;
    $md5serial .= md5("$item:$key");
}
*/

/** get_if function
 *  If $value is set, returns $value - else $else
 * @param $value
 * @param $else
 * @param $else2
 */
function get_if($value, $else, $else2='aa_NoNe') {
    return $value ? $value :
           ($else ? $else :
           (($else2=='aa_NoNe') ? $else : $else2));
}

/** aa_version function
 *  Version of AA - automaticaly included also date and revision of util.php3
 *  file, for better version informations
 */
function aa_version($format='full') {
    $version = '2.51.0';
    $full    = 'ActionApps '.$version.' ($Date$, $Revision$)';
    switch ($format) {
        case 'svn': return (int) substr($full, strpos($full, 'Revision')+10);
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
     * @param $time
     */
    function set($name, $value, $time=null) {
        setcookie('AA_'.$name, $value, $time ? time() + $time : 0, '/', $_SERVER['HTTP_HOST']);
    }
    /** get function
     * @param $name
     */
    function get($name) {
        return $_COOKIE['AA_'.$name];
    }
}

class AA_GeneralizedArray {
    var $arr;
    /** AA_GeneralizedArray function
     *
     */
    function AA_GeneralizedArray() {
        $this->arr = array();
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
                $arr[$key] = array();
            }
            // go down - more deep in the structure
            $arr = &$arr[$key];
        }
        $arr = array_merge($arr, array($value));
    }
    /** getValues function
     * @param $coordinates
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
     *
     */
    function getArray() {
        return $this->arr;
    }
}

/** IsSpamText function
 * @param $text
 */
function IsSpamText($text, $tolerance=4) {
    // we do not accept any text using something like:
    //     [url=http://example.net]Example.net[/url]
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
 *  to check the string - for example
 */
function IsAlias($identifier) {
    return  ((strlen($identifier)==10) AND (substr($identifier,0,2)=='_#'));
}

?>
