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

require_once AA_INC_PATH."constants.php3";
require_once AA_INC_PATH."mgettext.php3";
require_once AA_INC_PATH."zids.php3";
require_once AA_INC_PATH."logs.php3";
require_once AA_INC_PATH."go_url.php3";
require_once AA_INC_PATH."statestore.php3";

/** get_aa_url function
 * @param $href
 * @param $session
 */
function get_aa_url($href, $session=true) {
    global $sess;
    return ($session AND is_object($sess)) ? $sess->url(AA_INSTAL_PATH.$href) : AA_INSTAL_PATH.$href;
}

/** get_admin_url function
 * @param $href
 * @param $session
 */
function get_admin_url($href, $session=true) {
    return get_aa_url("admin/$href", $session);
}

/** get_help_url function
 *  returns url for $morehlp parameter in Frm* functions
 * @param $href
 * @param $anchor
 */
function get_help_url($href, $anchor) {
    return $href."#".$anchor;
}

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
    if (strlen ($s) && substr ($s,-1) != "/")
        $s .= "/";
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
    return (self_server(). ereg_replace("/[^/]*$", "", $_SERVER['PHP_SELF']) . "/");
}

/** document_uri function
 *  On some servers isn't defined DOCUMENT_URI
 *   (canaca.com 2003-09-19 - Apache/1.3.27 (Unix) (Red-Hat/Linux), Honza)
 */
function document_uri() {
    return get_if($_SERVER['DOCUMENT_URI'],$_SERVER['SCRIPT_URL']);
}

/** shtml_base function
 *  returns server name with protocol, port and current directory of shtml file
 */
function shtml_base() {
    return (self_server(). ereg_replace("/[^/]*$", "", document_uri()) . "/");
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
    $a = str_replace("#:", "__-__.", $param);    // dummy string
    $b = str_replace("://", "__-__2", $a);       // replace all <http>:// too
    $c = str_replace(":", "##Sx",$b);            // Separation string is //#Sx
    $d = str_replace("__-__.", ":", $c);         // change "#:" to ":"
    $e = str_replace("__-__2", "://", $d);       // change back "://"
    return explode("##Sx", $e);
}

/** ParseClassProperties function
 *  Parses class parameters from the string, which is stored in the database
 *  Typical use is for fields.input_show_func, where parameters are stored
 *  as string in the form: fnc:const:param
 * @param $param
 * @param $class_mask
 *  @return asociative array of parameters, the name of parameters is given
 *  by the class itself ($class_mask . fnc).
 */
function ParseClassProperties($param, $class_mask) {
    // we do not use ParamExplode() - I  do not like the http:// replacement there
    $a      = str_replace("#:", "__-__.", $param);    // dummy string
    $b      = str_replace(":", "##Sx", $a);            // Separation string is //#Sx
    $c      = str_replace("__-__.", ":", $b);         // change "#:" to ":"
    $params = explode("##Sx", $c);

    $ret['class'] = $class = $class_mask. ucwords(strtolower($params[0]));
    // ask class, which parameters uses
    // call AA_Widget_Txt::getClassProperties()), for example

    $class_parameters = call_user_func(array($class,'getClassProperties'));
    $i=0;
    foreach ( $class_parameters as $name =>$foo ) {
        if (isset($params[$i])) {
            $ret[$name] = $params[$i++];
        }
    }
    return $ret;
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
function add_vars($query_string="") {
    $varstring = ( $query_string ? $query_string : shtml_query_string() );

    if ( !$varstring ) {
        return;
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
    $aa_query_arr = NormalizeArrayIndex(magic_strip($aa_query_arr));
    array_merge_append($GLOBALS, $aa_query_arr);
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

/** dequote function
 * function to reverse effect of "magic quotes"
 * not needed in MySQL and get_magic_quotes_gpc()==1
 * @param $str
 */
function dequote($str) {
    return $str;
}

/** new_id function
 *  returns new unpacked md5 unique id, except these which can  force unexpected end of string
 * @param $mark
 */
function new_id($mark=0){
    do {
        $id = md5(uniqid('hugo'));
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
 *  @return a unique id from a string.
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
    global $errcheck;
    // Give up tracking this, too many errors in Honza's code!
    /*
    if ($errcheck && !preg_match("/^[0-9a-f]+$/", $unpacked_id)) // Note was + instead {32}
         huhe("Warning: trying to pack $unpacked_id.<br>\n");
    */
    return ((string)$unpacked_id == "0" ? "0" : pack("H*",trim($unpacked_id)));
}

/** unpack_id
 * @param $packed_id
 * @return unpacked md5 id
 */
function unpack_id($packed_id){
    if ((string)$packed_id == "0") {
        return "0";
    }
    $foo = bin2hex($packed_id);  // unpack("H*", $str) does not work in PHP 4.0.3 so bin2hex used
    return (string)$foo;
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


/** date2sec function
 * @param $dat
 * @return number of seconds since 1970 to date in MySQL format
 */
function date2sec($dat) {
    if ( Ereg("^([0-9]{4})-([0-9]{1,2})-([0-9]{1,2}) ([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2})", $dat, $d)) {
        return MkTime($d[4], $d[5], $d[6], $d[2], $d[3], $d[1]);
    }
    return 0;
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
function huhl($a, $b="", $c="",$d="",$e="",$f="",$g="",$h="",$i="",$j="") {
    global $debugtimes,$debugtimestart;
    if (isset($a)) {
        print("<listing>");
        if ($debugtimes) {
           if (! $debugtimestart) {
                $debugtimestart = get_microtime();
            }
            print("Time: ".(get_microtime() - $debugtimestart)."\n");
        }
        huhlo($a);
        huhlo($b);
        huhlo($c);
        huhlo($d);
        huhlo($e);
        huhlo($f);
        huhlo($g);
        huhlo($h);
        huhlo($i);
        huhlo($j);
        print("</listing>\n");
    }
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
        $ret['reading_password'] = md5($ret['reading_password']);
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
            $val = unpack_id128($db->f(substr($values,7)));
        } elseif (is_string($values) AND isset( $db->Record[$values] )) {
            $val = $db->Record[$values];
        } else {  // true or 'aa_fields'
            $val = DBFields($db);
        }

        if ( $key == 'aa_first' ) {
            freeDB($db);
            return $val;
        } elseif ( ($key == "NoCoLuMn") OR !$key ) {
            $arr[] = $val;
        } elseif ( substr($key,0,7) == 'unpack:' ) {
            $arr[unpack_id128($db->f(substr($key,7)))] = $val;
        } else {
            $arr[$db->f($key)] = $val;
        }
    }
    freeDB($db);
    return isset($arr) ? $arr : false;
}

/** Components (plugins) manipulation class */
class AA_Components extends AA_Object {

    /// Static ///

    /** Used parameter format (in fields.input_show_func table)
     *  @todo - specify the parameters better - value type, used widget, ... so
     *          we could generate Parameter wizard (and validation) from those
     *          informations
     */
     /** getClassProperties function
      *
      */
    function getClassProperties()  {
        // array of AA parameters (can't be object's data, since we need
        // to call it staticaly (as class method)
        return array();
    }

    /** getClassNames function
     *  Return names of all known AA classes, which begins with $mask
     *  static function
     * @param $mask
     */
    function getClassNames($mask) {
        $right_classes = array();

        // php4 returns classes all in lower case :-(
        $mask          = strtolower($mask);
        $mask_length   = strlen($mask);
        foreach (get_declared_classes() as $classname) {
            if ( substr(strtolower($classname),0,$mask_length) == $mask ) {
                $right_classes[] = $classname;
            }
        }
        return $right_classes;
    }
    /** getSelectionCode function
     * @param $mask
     * @param $input_id
     * @param $params
     */
    function getSelectionCode($mask, $input_id, &$params) {
        $options      = array('AA_Empty' => _m('select ...'));
        $html_options = array('AA_Empty' => '');
        foreach (AA_Components::getClassNames($mask) as $selection_class) {
            // call static class methods
            $options[$selection_class]      = call_user_func(array($selection_class, 'name'));
            $html_options[$selection_class] = call_user_func_array(array($selection_class, 'htmlSetting'), array($input_id, &$params));
        }
        return getSelectWithParam($input_id, $options, "", $html_options);
    }
}

// AA_Widget class should implement some interface (in php5), so it is possible
// to use AA_Components factory, ... methods
// used for easy ussage of factory, adding new user widgets, and selectbox
// AA_Widget should became abstract in php5
class AA_Widget extends AA_Components {

    /** array of possible values (for selectbox, two boxes, ...) */
    var $_const_arr = null;

    /** array(value => true) of all selected values - just for caching */
    var $_selected = null;

    /** $parameters - Array of AA_Property used for the widget
    *   inherited from AA_Components
    */
    /** name function
     *
     */
    function name()         {}
    //    function description()  {}


     /** Fills array used for list selection. Fill it from constant group or
      * slice.
      * It never refills the array (and we relly on this fact in the code)
      * This function is rewritten fill_const_arr().
      */
    function & getConstArr($aa_variable) {
        if ( isset($this->_const_arr) AND is_array($this->_const_arr) ) {  // already filled
            return $this->_const_arr;
        }

        // not filled, yet - so fill it
        $this->_const_arr = array();  // Initialize

        // commented out - used for Related Item Window values
        // $zids = $ids_arr ? new zids($ids_arr) : false;  // transforms content array to zids
        $zids    = false;
        $ids_arr = false;

        $constgroup   = $this->getProperty('const');
        $filter_conds = $this->getProperty('filter_conds');
        $sort_by      = $this->getProperty('sort_by');
        $slice_field  = $this->getProperty('slice_field');

        // if variable is for some item, then we can use _#ALIASES_ in conds
        // and sort
        $item_id = $aa_variable->getItemId();
        if ( $item_id ) {
            $item         = AA_Item::getItem($item_id);
            if ( $item ) {
                $filter_conds = $item->unalias($filter_conds);
                $sort_by      = $item->unalias($sort_by);
            }
        }

        if ( !$this->getProperty('const')) {  // no constants or slice defined
            return $this->_const_arr;         //  = array();
        }
        // "#sLiCe-" prefix indicates select from items
        elseif ( substr($constgroup,0,7) == "#sLiCe-" ) {

            $bin_filter       = $this->getProperty('bin_filter', AA_BIN_ACT_PEND);
            $tag_prefix       = $this->getProperty('tag_prefix');  // tag_prfix is deprecated - should not be used

            $sid              = substr($constgroup, 7);
            /** Get format for which represents the id
             *  Could be field_id (then it is grabbed from item and truncated to 50
             *  characters, or normal AA format string.
             *  Headline is default (if empty "$slice_field" is passed)
             */
            if (!$slice_field) {
                $slice_field = GetHeadlineFieldID($sid, "headline.");
                if (!$slice_field) {
                    return $this->_const_arr; //  = array();
                }
            }
            $format           = AA_Fields::isField($slice_field) ? '{substr:{'.$slice_field.'}:0:50}' : $slice_field;

            $this->_const_arr = GetFormatedItems( $sid, $format, $zids, $bin_filter, $filter_conds, $sort_by, $tag_prefix);
            return $this->_const_arr;
        }
        else {
            $this->_const_arr = GetFormatedConstants($constgroup, $slice_field, $ids_arr, $filter_conds, $sort_by);
        }
        if ( !isset($this->_const_arr) OR !is_array($this->_const_arr) ) {
            $this->_const_arr = array();
        }
        return $this->_const_arr;
    }


    /** returns $ret_val if given $option is selected for current field
     *  This method is rewritten if_selected() method form formutil.php3
     */
    function ifSelected($option, $ret_val) {
        return $this->_selected[(string)$option] ? $ret_val : '';
    }

    /**
     *  This method is rewritten _fillSelected() method form formutil.php3
     */
    function _fillSelected($aa_value) {
        if ( is_null($this->_selected) ) {  // not cached yet => create selected array
            for ($i=0 ; $i < $aa_value->valuesCount(); $i++) {
                $val = $aa_value->getValue($i);
                if ( $val ) {
                    $this->_selected[(string)$val] = true;
                }
            }
        }
    }

    /** returns options array with marked selected oprtions, missing options,...
     *  This method is rewritten get_options() method form formutil.php3
     */
    function getOptions( $aa_variable, $use_name=false, $testval=false, $add_empty=false) {
        $selectedused  = false;

        $already_selected = array();     // array where we mark selected values
        $pair_used        = array();     // array where we mark used pairs
        $this->_fillSelected($aa_variable->getAaValue()); // fill selected array by all values in order we can print invalid values later

        $ret = array();
        $arr = $this->getConstArr($aa_variable);
        if (is_array($arr)) {
            foreach ( $arr as $k => $v ) {
                if ($use_name) {
                    // special parameter to use values instead of keys
                    $k = $v;
                }

                // ignore pairs (key=>value) we already used
                if ($pair_used[$k."aa~$v"]) {
                    continue;
                }
                $pair_used[$k."aa~$v"] = true;   // mark this pair - do not use it again

                $select_val = $testval ? $v : $k;
                $selected   = $this->ifSelected($select_val, true);
                if ($selected) {
                    $selectedused = true;
                    $already_selected[(string)$select_val] = true;  // flag
                }
                $ret[] = array('k'=>$k, 'v'=>$v, 'selected' =>  ($selected ? true : false), 'mis' => false);
            }
        }

        // now add all values, which is not in the array, but field has this value
        // (this is slice inconsistence, which could go from feeding, ...)
        if ( isset( $this->_selected ) AND is_array( $this->_selected ) ) {
            foreach ( $this->_selected as $k =>$foo ) {
                if ( !$already_selected[$k] ) {
                    $ret[] = array('k'=>$k, 'v'=>$k, 'selected' => true, 'mis' => true);
                    $selectedused = true;
                }
            }
        }
        if ( $add_empty ) {
            // put empty oprion to the front
            array_unshift($ret, array('k'=>'', 'v'=>'', 'selected' => !$selectedused, 'mis' => false));
        }
        return $ret;
    }

    /** returns select options created from given array
     *  This method is rewritten get_options() method form formutil.php3
     */
    function getSelectOptions( &$options, $restrict='all', $do_not_select=false) {

        $select_string = ( $do_not_select ? ' class="sel_on"' : ' selected class="sel_on"');

        $ret = '';
        foreach ( $options as $option ) {
            if ( ($restrict == 'selected')   AND !$option['selected'] ) {
                continue;  // do not print this option
            }
            if ( ($restrict == 'unselected') AND $option['selected'] ) {
                continue;  // do not print this option
            }
            $selected = $option['selected'] ? $select_string : '';
            $missing  = $option['mis']      ? 'class="sel_missing"' : '';
            $ret     .= "<option value=\"". htmlspecialchars($option['k']) ."\" $selected $missing>".htmlspecialchars($option['v'])."</option>";
        }
        return $ret;
    }

    /**
    * Prints html tag <input type="radio" or ceckboxes .. to 2-column table
    * - for use internal use of FrmInputMultiChBox and FrmInputRadio
    */
    function getInMatrix($records, $ncols, $move_right) {
        if (is_array($records)) {
            if (! $ncols) {
                return implode('', $records);
            }
            $nrows = ceil (count ($records) / $ncols);
            $ret = '<table border="0" cellspacing="0">';
            for ($irow = 0; $irow < $nrows; $irow ++) {
                $ret .= '<tr>';
                for ($icol = 0; $icol < $ncols; $icol ++) {
                    $pos = ( $move_right ? $ncols*$irow+$icol : $nrows*$icol+$irow );
                    $ret .= '<td>'. get_if($records[$pos], "&nbsp;") .'</td>';
                }
                $ret .= '</tr>';
            }
            $ret .= '</table>';
        }
        return $ret;
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_variable - the variable holding the value to display
     *  @param  $repre_value - current code used for representation of the
     *                         variable
     */
    function getAjaxHtml($aa_variable, $repre_value) {

        $input_id    = $aa_variable->getId();
        $ret   = '';

        // This widget uses constants - show selectbox!
        if ($this->getProperty('const')) {
            $input_name   = $input_id ."[]";
            $use_name     = $this->getProperty('use_name', false);
            $multiple     = $this->multiple() ? ' multiple' : '';
            $required     = $aa_variable->isRequired();

            $ret      = "<select name=\"$input_name\" id=\"$input_name\"$multiple>";
            $options  = $this->getOptions($aa_variable, $use_name, false, !$required);
            $ret     .= $this->getSelectOptions( $options );
            $ret     .= "</select>";
        } else {
            $delim = '';
            for ( $i = 0; $i < $aa_variable->valuesCount(); $i++ ) {
                $input_name   = $input_id ."[$i]";
                $input_value  = htmlspecialchars($aa_variable->getValue($i));
                $ret         .= "$delim\n<input type=\"text\" size=\"80\" id=\"$input_name\" value=\"$input_value\">";
                $delim        = '<br />';
            }
            // no input was printed, we need to print one
            if ( !$ret ) {
                $ret         = "\n<input type=\"text\" size=\"80\" id=\"". $input_id ."[0]\" value=\"\">";
            }
        }

        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

    /* Creates all common ajax editing buttons to be used by different inputs */
    function _finalizaAjaxHtml($widget_html, $input_id, $repre_value) {
        $widget_html  .= "\n<input type=\"button\" value=\"". _m('SAVE CHANGE') ."\" onclick=\"DoChange('$input_id')\">"; //ULOŽIT ZMÌNU
        $widget_html  .= "\n<input type=\"button\" value=\"". _m('Cancel') ."\" onclick=\"$('ajaxv_$input_id').update(". '$F(\'ajaxh_'.$input_id.'\'))'."; $('ajaxv_$input_id').setAttribute('aaedit', '0');\">";
        $widget_html  .= "\n<input type=\"hidden\" id=\"ajaxh_$input_id\" value=\"".htmlspecialchars($repre_value)."\">";
        return $widget_html;
    }
}

/** Textarea widget */
class AA_Widget_Txt extends AA_Widget {
    /** AA_Widget_Txt function
     *
     */
    /** Constructor - use the default for AA_Object */
    function AA_Widget_Txt($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
     /** name function
      *
      */
    function name() {
        return _m('Text Area');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;// returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties() {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int', false, '', '', 20)
            );

    }

    function getAjaxHtml($aa_variable, $repre_value) {
        $input_id  = $aa_variable->getId();
        $row_count = $this->getProperty('row_count', 4);

        $ret   = '';
        $delim = '';
        for ( $i = 0; $i < $aa_variable->valuesCount(); $i++ ) {
            $input_name   = $input_id ."[$i]";
            $input_value  = htmlspecialchars($aa_variable->getValue($i));
            $ret         .= "$delim\n<textarea id=\"$input_name\" name=\"$input_name\" rows=\"$row_count\" style=\"width:100%\">$input_value</textarea>";
            $delim        = '<br />';
        }

        // no input was printed, we need to print one
        if ( !$ret ) {
            $input_name  = $input_id ."[0]";
            $ret         = "\n<textarea id=\"$input_name\" name=\"$input_name\" rows=\"$row_count\" style=\"width:100%\"></textarea>";
        }

        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

}

/** Textarea with Presets widget */
class AA_Widget_Tpr extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Tpr($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }


    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Textarea with Presets');   // widget name
    }
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     * Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'column_count'           => new AA_Property( 'column_count',           _m("Column count"),         'int',  false, true, 'int',  false, '', '', 70)
            );
    }
}

/** Rich Edit Text Area widget */
class AA_Widget_Edt extends AA_Widget {
    /** AA_Widget_Edt function
     *
     */
    /** Constructor - use the default for AA_Object */
    function AA_Widget_Edt($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Rich Edit Text Area');   // widget name
    }
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'column_count'           => new AA_Property( 'column_count',           _m("Column count"),         'int',  false, true, 'int',  false, '', '', 70),
            'area_type'              => new AA_Property( 'area_type',              _m("Type"),                 'text', false, true, array('enum',array('class'=>'class', 'iframe'=>'iframe')), false, _m("type: class (default) / iframe"), '', 'class')
            );
    }
}

/** Text Field widget */
class AA_Widget_Fld extends AA_Widget {
    /** AA_Widget_Fld function
     *
     */
    /** Constructor - use the default for AA_Object */
    function AA_Widget_Fld($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_variable - the variable holding the value to display
     *  @param  $repre_value - current code used for representation of the
     *                         variable
     */
    function getAjaxHtml($aa_variable, $repre_value) {
        $input_id    = $aa_variable->getId();

        $max_characters = $this->getProperty('max_characters', 254);
        $width          = $this->getProperty('width', 60);

        $ret   = '';
        $delim = '';
        for ( $i = 0; $i < $aa_variable->valuesCount(); $i++ ) {
            $input_name   = $input_id ."[$i]";
            $input_value  = htmlspecialchars($aa_variable->getValue($i));
            $ret         .= "$delim\n<input type=\"text\" size=\"$width\" maxlength=\"$max_characters\" id=\"$input_name\" value=\"$input_value\">";
            $delim        = '<br />';
        }
        // no input was printed, we need to print one
        if ( !$ret ) {
            $ret         = "\n<input type=\"text\" size=\"$width\" maxlength=\"$max_characters\" id=\"". $input_id ."[0]\" value=\"\">";
        }

        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Text Field');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;    // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'max_characters'         => new AA_Property( 'max_characters',         _m("Max characters"),       'int',  false, true, 'int',  false, _m("max count of characters entered (maxlength parameter)"), '', 254),
            'width'                  => new AA_Property( 'width',                  _m("Width"),                'int',  false, true, 'int',  false, _m("width of the field in characters (size parameter)"),     '',  30)
            );
    }
}

/** Multiple Text Field widget */
class AA_Widget_Mfl extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Mfl($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Multiple Text Field');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'show_buttons'           => new AA_Property( 'show_buttons',           _m("Buttons to show"),      'text', false, true, 'text', false, _m("Which action buttons to show:<br>M - Move (up and down)<br>D - Delete value,<br>A - Add new value<br>C - Change the value<br>Use 'MDAC' (default), 'DAC', just 'M' or any other combination. The order of letters M,D,A,C is not important."), '', 'MDAC'),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10)
            );
    }
}

/** Text Field with Presets widget */
class AA_Widget_Pre extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Pre($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Text Field with Presets');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     * Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'max_characters'         => new AA_Property( 'max_characters',         _m("max characters"),       'int',  false, true, 'int',  false, _m("max count of characters entered (maxlength parameter)"), '', 254),
            'width'                  => new AA_Property( 'width',                  _m("width"),                'int',  false, true, 'int',  false, _m("width of the field in characters (size parameter)"),     '',  30),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'use_name'               => new AA_Property( 'use_name',               _m("Use name"),             'bool', false, true, 'bool', false, _m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"), '', '0'),
            'adding'                 => new AA_Property( 'adding',                 _m("Adding"),               'bool', false, true, 'bool', false, _m("adding the selected items to input field comma separated"), '', '0'),
            'second_field'           => new AA_Property( 'second_field',           _m("Second Field"),         'text', false, true, 'text', false, _m("field_id of another text field, where value of this selectbox will be propagated too (in main text are will be text and there will be value)"), '', "source_href....."),
            'add2constant'           => new AA_Property( 'add2constant',           _m("Add to Constant"),      'bool', false, true, 'bool', false, _m("if set to 1, user typped value in inputform is stored into constants (only if the value is not already there)"), '', "0"),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            );
    }
}

/** Select Box widget */
class AA_Widget_Sel extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Sel($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Select Box');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'use_name'               => new AA_Property( 'use_name',               _m("Use name"),             'bool', false, true, 'bool', false, _m("if set (=1), then the name of selected constant is used, insted of the value. Default is 0"), '', '0'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            );
    }
}

/** Radio Button widget */
class AA_Widget_Rio extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Rio($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Radio Button');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'columns'                => new AA_Property( 'columns',                _m("Columns"),              'int',  false, true, 'int',  false, _m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."), '', 3),
            'move_right'             => new AA_Property( 'move_right',             _m("Move right"),           'bool', false, true, 'bool', false, _m("Should the function move right or down to the next value?"), '', "1"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d")
            );
    }
}

/** Date widget */
class AA_Widget_Dte extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Dte($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Date');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'start_year'             => new AA_Property( 'start_year',             _m("Starting Year"),        'int',  false, true, 'int',  false, _m("The (relative) start of the year interval"), '', "1"),
            'end_year'               => new AA_Property( 'end_year',               _m("Ending Year"),          'int',  false, true, 'int',  false, _m("The (relative) end of the year interval"), '', "10"),
            'relative'               => new AA_Property( 'relative',               _m("Relative"),             'bool', false, true, 'bool', false, _m("If this is 1, the starting and ending year will be taken as relative - the interval will start at (this year - starting year) and end at (this year + ending year). If this is 0, the starting and ending years will be taken as absolute."), '', "1"),
            'show_time'              => new AA_Property( 'show_time',              _m("Show time"),            'bool', false, true, 'bool', false, _m("show the time box? (1 means Yes, undefined means No)"), '', "1")
            );
    }
}

/** Check Box widget */
class AA_Widget_Chb extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Chb($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_variable - the variable holding the value to display
     *  @param  $repre_value - current code used for representation of the
     *                         variable
     */
    function getAjaxHtml($aa_variable, $repre_value) {
        $input_id    = $aa_variable->getId();

        $ret   = '';
        $delim = '';
        for ( $i = 0; $i < $aa_variable->valuesCount(); $i++ ) {
            $input_name   = $input_id ."[$i]";
            $input_value  = htmlspecialchars($aa_variable->getValue($i));
            $ret         .= "$delim\n<input type=\"checkbox\" name=\"$input_name\" id=\"$input_name\" value=\"1\"". ($input_value ? " checked" : '').">";
            $delim        = '<br />';
        }
        // no input was printed, we need to print one
        if ( !$ret ) {
            $input_name   = $input_id ."[0]";
            $ret         .= "$delim\n<input type=\"checkbox\" name=\"$input_name\" id=\"$input_name\" value=\"1\">";
        }

        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Check Box');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha')
                    );
    }
}

/** Multiple Checkboxes widget */
class AA_Widget_Mch extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Mch($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Multiple Checkboxes');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'columns'                => new AA_Property( 'columns',                _m("Columns"),              'int',  false, true, 'int',  false, _m("Number of columns. If unfilled, the checkboxes are all on one line. If filled, they are formatted in a table."), '', 3),
            'move_right'             => new AA_Property( 'move_right',             _m("Move right"),           'bool', false, true, 'bool', false, _m("Should the function move right or down to the next value?"), '', "1"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d")
            );
    }

    /** Returns one checkbox tag - Used in inputMultiChBox */
    function getOneChBoxTag($option, $name, $add='') {
        $ret = "\n<nobr><input type=\"checkbox\" name=\"$name\" id=\"$name\" value=\"".
                   htmlspecialchars($option['k']) ."\" $add";
        if ( $option['selected'] ) {
            $ret .= " checked";
        }
        $ret .= ">".htmlspecialchars($option['v'])."</nobr>";
        return $ret;
    }

    /** @return widget HTML for using as AJAX component
     *  @param  $aa_variable - the variable holding the value to display
     *  @param  $repre_value - current code used for representation of the
     *                         variable
     */
    function getAjaxHtml($aa_variable, $repre_value) {

        $input_id    = $aa_variable->getId();
        $ret   = '';

        $use_name     = $this->getProperty('use_name', false);
        $required     = $aa_variable->isRequired();

        $options      = $this->getOptions($aa_variable, $use_name);
        $htmlopt      = array();
        for ( $i=0 ; $i < count($options); $i++) {
            $htmlopt[]  = $this->getOneChBoxTag($options[$i], $input_id ."[$i]");
        }

        $ret = $this->getInMatrix($htmlopt, $this->getProperty('columns', 0), $this->getProperty('move_right', false));
        return $this->_finalizaAjaxHtml($ret, $input_id, $repre_value);
    }

}

/** Multiple Selectbox widget */
class AA_Widget_Mse extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Mse($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Multiple Selectbox');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d")
            );
    }
}

/** Two Boxes widget */
class AA_Widget_Wi2 extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Wi2($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Two Boxes');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count"),            'int',  false, true, 'int',  false, '', '', 10),
            'offer_label'            => new AA_Property( 'offer_label',            _m("Title of \"Offer\" selectbox"), 'text', false, true, 'text', false, '','', _m("Our offer")),
            'selected_label'         => new AA_Property( 'selected_label',         _m("Title of \"Selected\" selectbox"), 'text', false, true, 'text', false, '','', _m("Selected")),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d")
            );
    }
}

/** File Upload widget */
class AA_Widget_Fil extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Fil($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('File Upload');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'allowed_ftypes'         => new AA_Property( 'allowed_ftypes',         _m("Allowed file types"),   'text', false, true, 'text', false, '', '', "image/*"),
            'label'                  => new AA_Property( 'label',                  _m("Label"),                'text', false, true, 'text', false, _m("To be printed before the file upload field"), '', _m("File: ")),
            'hint'                   => new AA_Property( 'hint',                   _m("Hint"),                 'text', false, true, 'text', false, _m("appears beneath the file upload field"), '', _m("You can select a file ..."))
            );
    }
}

/** Related Item Window widget */
class AA_Widget_Iso extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Iso($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Related Item Window');   // widget name
    }
    function multiple() {
        return true;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'row_count'              => new AA_Property( 'row_count',              _m("Row count in the list"),'int',  false, true, 'int',  false, '', '', 15),
            'show_actions'           => new AA_Property( 'show_actions',           _m("Actions to show"),      'text', false, true, 'text', false, _m("Defines, which buttons to show in item selection:<br>A - Add<br>M - add Mutual<br>B - Backward<br> Use 'AMB' (default), 'MA', just 'A' or any other combination. The order of letters A,M,B is important."), '', 'AMB'),
            'admin_design'           => new AA_Property( 'admin_design',           _m("Admin design"),         'bool', false, true, 'bool', false, _m("If set (=1), the items in related selection window will be listed in the same design as in the Item manager - 'Design - Item Manager' settings will be used. Only the checkbox will be replaced by the buttons (see above). It is important that the checkbox must be defined as:<br> <i>&lt;input type=checkbox name=\"chb[x_#ITEM_ID#]\" value=\"1\"&gt;</i> (which is default).<br> If unset (=0), just headline is shown (default)."), '' , '0'),
            'tag_prefix'             => new AA_Property( 'tag_prefix',             _m("Tag Prefix"),           'text', false, true, 'text', false, _m("Deprecated: selects tag set ('AMB' / 'GYR'). Ask Mitra for more details."), '', 'AMB'),
            'show_buttons'           => new AA_Property( 'show_buttons',           _m("Buttons to show"),      'text', false, true, 'text', false, _m("Which action buttons to show:<br>M - Move (up and down)<br>D - Delete relation,<br>R - add Relation to existing item<br>N - insert new item in related slice and make it related<br>E - Edit related item<br>Use 'DR' (default), 'MDRNE', just 'N' or any other combination. The order of letters M,D,R,N,E is not important."), '', 'MDR'),
            'bin_filter'             => new AA_Property( 'bin_filter',             _m("Show items from bins"), 'int',  false, true, 'int',  false, _m("(for slices only) To show items from selected bins, use following values:<br>Active bin - '%1'<br>Pending bin - '%2'<br>Expired bin - '%3'<br>Holding bin - '%4'<br>Trash bin - '%5'<br>Value is created as follows: eg. You want show headlines from Active, Expired and Holding bins. Value for this combination is counted like %1+%3+%4&nbsp;=&nbsp;13"), '', '3'),
            'filter_conds'           => new AA_Property( 'filter_conds',           _m("Filtering conditions"), 'text', false, true, 'text', false, _m("(for slices only) Conditions for filtering items in selection. Use conds[] array."), '', "conds[0][category.......1]=Enviro&conds[1][switch.........2]=1"),
            'sort_by'                => new AA_Property( 'sort_by',                _m("Sort by"),              'text', false, true, 'text', false, _m("(for slices only) Sort the items in specified order. Use sort[] array"), '', "sort[0][headline........]=a&sort[1][publish_date....]=d"),
            'filter_conds_changeable'=> new AA_Property( 'filter_conds_changeable',_m("Filtering conditions - changeable"), 'text', false, true, 'text', false, _m("Conditions for filtering items in related items window. This conds user can change."), '', "conds[0][source..........]=Econnect"),
            'slice_field'            => new AA_Property( 'slice_field',            _m("slice field"),          'text', false, true, 'text', false, _m("field (or format string) that will be displayed in select box (from related slice). if not specified, in select box are displayed headlines. you can use also any AA formatstring here (like: _#HEADLINE - _#PUB_DATE). (only for constants input type: slice)"), '', 'category........')
            );
    }
}

/** Do not show widget */
class AA_Widget_Nul extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Nul($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Do not show');   // widget name
    }
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
                    );
    }
}

/** Hierachical constants widget */
class AA_Widget_Hco extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Hco($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Hierachical constants');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'const'                  => new AA_Property( 'const',                  _m("Constants or slice"),   'text', false, true, 'text', false, _m("Constants (or slice) which is used for value selection")),
            'level_count'            => new AA_Property( 'level_count',            _m("Level count"),          'int',  false, true, 'int',  false, _m("Count of level boxes"), '', "3"),
            'box_width'              => new AA_Property( 'box_width',              _m("Box width"),            'int',  false, true, 'int',  false, _m("Width in characters"), '', "60"),
            'target_size'            => new AA_Property( 'target_size',            _m("Size of target"),       'int',  false, true, 'int',  false, _m("Lines in the target select box"), '', '5'),
            'horizontal'             => new AA_Property( 'horizontal',             _m("Horizontal"),           'bool', false, true, 'bool', false, _m("Show levels horizontally"), '', '1'),
            'first_selectable_level' => new AA_Property( 'first_selectable_level', _m("First selectable"),     'int',  false, true, 'int',  false, _m("First level which will have a Select button"), '', '0'),
            'level_names'            => new AA_Property( 'level_names',            _m("Level names"),          'text', false, true, 'text', false, _m("Names of level boxes, separated by tilde (~). Replace the default Level 0, Level 1, ..."), '', _m("Top level~Second level~Keyword"))
            );
    }
}

/** Password and Change password widget */
class AA_Widget_Pwd extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Pwd($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Password and Change password');
    }   // widget name
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'width'                  => new AA_Property( 'width',                  _m("Width"),                         'int',  false, true, 'int',  false, _m("width of the three fields in characters (size parameter)"),     '',  60),
            'change_label'           => new AA_Property( 'change_label',           _m("Label for Change Password"),     'text', false, true, 'text', false, _m("Replaces the default 'Change Password'"), '', _m("Change your password")),
            'retype_label'           => new AA_Property( 'retype_label',           _m("Label for Retype New Password"), 'text', false, true, 'text', false, _m("Replaces the default \"Retype New Password\""), '', _m("Retype the new password")),
            'delete_label'           => new AA_Property( 'delete_label',           _m("Label for Delete Password"),     'text', false, true, 'text', false, _m("Replaces the default \"Delete Password\""), '', _m("Delete password (set to empty)")),
            'change_hint'            => new AA_Property( 'change_hint',            _m("Help for Change Password"),      'text', false, true, 'text', false, _m("Help text under the Change Password box (default: no text)"), '', _m("To change password, enter the new password here and below")),
            'retype_hint'            => new AA_Property( 'retype_hint',            _m("Help for Retype New Password"),  'text', false, true, 'text', false, _m("Help text under the Retype New Password box (default: no text)"), '', _m("Retype the new password exactly the same as you entered into \"Change Password\".")),
            );
    }
}

/** Hidden field widget */
class AA_Widget_Hid extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Hid($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Hidden field');
    }   // widget name
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
                    );
    }
}

/** Local URL Picker widget */
class AA_Widget_Lup extends AA_Widget {

    /** Constructor - use the default for AA_Object */
    function AA_Widget_Lup($params) {
        // assign all the properties (using parent constructor)
        parent::AA_Object($params);
    }

    /** - static member functions
     *  used as simulation of static class variables (not present in php4)
     */
    /** name function
     *
     */
    function name() {
        return _m('Local URL Picker');   // widget name
    }
    /** multiple function
     *
     */
    function multiple() {
        return false;   // returns multivalue or single value
    }

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
     function getClassProperties()  {
        return array (                      //           id                        name                        type    multi  persist validator, required, help, morehelp, example
            'fnc'                    => new AA_Property( 'fnc',                    _m("Widget"),               'text', false, true, 'alpha'),
            'url'                    => new AA_Property( 'url',                    _m("URL"),                  'text', false, true, 'text', false, _m("The URL of your local web server from where you want to start browsing for a particular URL."), '', _m("http#://www.ecn.cz/articles/solar.shtml"))
            );
    }
}


/** AA_Property
*  Used also for definition of components's parameters
*   Components are AA_Widgets, AA_Transofrmations, ...
*/
class AA_Property {

    /** Id of property - like: new_flag */
    var $id;

    /** Property name - like: _m('Mark as') */
    var $name;

    /** Property type - text | int | bool | float | <class_name>
     *  If the type is <class_name>, then it should support getAaValue() method.
     */
    var $type;

    /** Contain one or multiple values (numbered array) - bool (default is false)  */
    var $multi;

    /** should be stored, when we are storing the state of the object */
    var $persistent;

    /** validate - standard validators are
     *  text | bool | int | float | email | alpha | long_id | short_id | alias | filename | login | password | unique | e_unique | url | all | enum
     */
    var $validator;

    /** boolean - is it required? - like: true */
    var $required;

    /** Help text for the property */
    var $input_help;

    /** Url, where user can get more informations about the property */
    var $input_morehlp;

    /** Value example */
    var $example;

    /** show_content_type_switch is used instead of $html_show or $html_rb_show.
     *  It is more generalized, so we can use more formaters in the future (not
     *  only HTML / Plain text, but also Wiki, Texy or whatever.
     *  The value is flagged 0 - do not show, FLAG_HTML | FLAG_PLAIN (1+2=3)
     *  means HTML / Plain text switch. There is an idea to use constant like
     *  CONTENT_SWITCH_STANDARD = FLAG_HTML | FLAG_PLAIN | .... = 1+2+4+8+16+...
     *  = 65535, so first 16 formaters will be standard (displayed after we add
     *  it to AA) and the rest (above 16) will be used for special purposes.
     *  However, it is just an idea right now (we still have just HTML and
     *  plain text)
     */
    var $show_content_type_switch;

    /** Default value for content type switch
    *   (FLAG_HTML or FLAG_PLAIN at this moment)
    */
    var $content_type_switch_default;

    /** array of constants used for selections (selectbox, radio, ...) */
    var $const_arr;
    /** AA_Property function
     * @param $id
     * @param $name
     * @param $type
     * @param $multi
     * @param $persistent
     * @param $validator
     * @param $required
     * @param $input_help
     * @param $input_morehlp
     * @param $example
     * @param $show_content_type_switch
     * @param $content_type_switch_default
     */
    function AA_Property($id, $name='', $type, $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_PLAIN) {
        $this->id                          = $id;
        $this->name                        = $name;
        $this->type                        = $type;
        $this->multi                       = $multi;
        $this->persistent                  = $persistent;
        $this->validator                   = is_object($validator) ? $validator : AA_Validate::factory($validator ? $validator : $type);
        $this->required                    = $required;
        $this->input_help                  = $input_help;
        $this->input_morehlp               = $input_morehlp;
        $this->example                     = $example;
        $this->show_content_type_switch    = $show_content_type_switch;
        $this->content_type_switch_default = $content_type_switch_default;
        $this->const_arr                   = (is_array($validator) AND ($validator[0]=='enum')) ? $validator[1] : array();
    }

    /** getId function */
    function getId() {
        return $this->id;
    }

    /** getName function */
    function getName() {
        return $this->name;
    }

    /** getType function */
    function getType() {
        return $this->type;
    }

    /** isObject function */
    function isObject() {
        return !in_array($this->type, array('text', 'int', 'bool', 'float'));
    }

    /** isMulti function */
    function isMulti() {
        return $this->multi;
    }

    /** isRequired function */
    function isRequired() {
        return $this->required;
    }

    /** isPersistent function */
    function isPersistent() {
        return $this->persistent;
    }
}

/** AA_Variable class defines one variable in AA. It is describes the datatype,
 *  (numeric, date, string), constraints (range of values, length, if it is
 *  required, ...), name, and some description of the variable. It do not hold
 *  the information, how the value is presented to the user and how it could
 *  be entered. For displaying the AA_Variable we choose some AA_Widget.
 *
 *  This approach AA_Variable/AA_Widget/AA_Value should replace the old - all
 *  in one AA_Inputfield approach. It should be used not only for AA Fields,
 *  but also for parameters of functions/widgets...
 *
 */
class AA_Variable extends AA_Property {

    /** Current value of $type. The value must be convertable to AA_Value - @see type */
    var $value=null;

    /** id of item, for which is this variable (used for some unaliasing...) */
    var $item_id=null;

    /** setValue function
     * @param $value
     */
    function AA_Variable($id, $name='', $type, $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_PLAIN, $aa_value=null, $item_id=null) {
        $this->value   = $aa_value;
        $this->item_id = $item_id;
        parent::AA_Property($id, $name, $type, $multi, $persistent, $validator, $required, $input_help, $input_morehlp, $example, $show_content_type_switch, $content_type_switch_default);
    }

    function setValue($value) {
        if ( !is_null($this->validator) AND $this->validator->validate($value)) {
            $this->value = new AA_Value($value);
        }
    }

    function getValue($i) {
        return $this->value->getValue($i);
    }

    function getAaValue() {
        return $this->value;
    }

    function valuesCount() {
        return (is_null($this->value) ? 0 : $this->value->valuesCount());
    }

    function getItemId() {
        return $this->item_id;
    }
}


/** Base class for formatters (like HTML/Plain text/wiki/Texy/...)
*   Currently we use just HTML and Plain text
*/
class AA_Formatter {

    /// Static ///

    /** getStandardFormattersBitfield function
     * @param $html_show
     *  @return bit field representig, which formatters we want to show. 65535
     *   means "all standard formatters", which means all 16 standard formatters.
     *   We use just two, at this moment - HTML (=1) and PLAIN (=2)
     *   (we will continue on bit basis, so next formatter would be xxx (=4))
     */
    function getStandardFormattersBitfield($html_show) {
        // @todo move to const in php5
        return 65535;
    }

    /** no formaters */
    function getNoneFormattersBitfield() {
        return 0;
    }

    /** getFlag function
     *  @param $formatter_type
     *  @return (bit) id of the formatter_type (HTML or PLAIN, at this moment)
     */
    function getFlag($formatter_type) {
        return ($formatter_type == 'HTML') ? 1 : 2;
    }
}

class AA_Field {

    /** asociative array of field data as defined in field table
    *   (id, type, slice_id, name, input_pri, input_help, input_morehlp, input_default, required, feed, multiple, input_show_func, content_id, search_pri, search_type, search_help, search_before, search_more_help, search_show, search_ft_show, search_ft_default, alias1, alias1_func, alias1_help, alias2, alias2_func, alias2_help, alias3, alias3_func, alias3_help, input_before, aditional, content_edit, html_default, html_show, in_item_tbl, input_validate, input_insert_func, input_show, text_stored)
    */
    var $data;

    /** Default widget - as parsed from field data (input_show_func) */
    var $widget;

    /** AA_Field function
     *  @param $data
     */
    function AA_Field($data) {
        $this->data   = is_array($data) ? $data : array();
        $this->widget = null;
    }

    /** storageColumn function
     *  @return the table and column, where the field is stored
     */
    function storageColumn() {
        return $this->data['in_item_tbl'] ? $this->data['in_item_tbl'] :  ($this->data['text_stored'] ? 'text' : 'number');
    }

    /** storageTable function
     *  @return the table and column, where the field is stored
     */
    function storageTable() {
        return $this->data['in_item_tbl'] ? 'item' : 'content';
    }

    /** getProperty function
     *  @return field data
     */
    function getProperty($property) {
        return $this->data[$property];
    }

    /** getId
     * @return id of the field
     */
    function getId() {
        return $this->getProperty('id');
    }

    /** getName function
     * @return name of the field
     */
    function getName() {
        return $this->getProperty('name');
    }

    /** required function
     * @return boolean value if the field is requierd  (must be filled)
     */
    function required() {
        return (bool) $this->getProperty('required');
    }

    /** getWidget function
     *
     */
    function & getWidget() {
        if ( is_null($this->widget) ) {
   //        function setFromField(&$field) {
   //            if (isset($field) AND is_array($field)) {
   //                $this->id            = $field['id'];
   //                $this->varname       = varname4form($this->id);
   //                $this->name          = $field['name'];
   //                $this->input_before  = $field['input_before'];
   //                $this->required      = $field['required'];
   //                $this->input_help    = $field['input_help'];
   //                $this->input_morehlp = $field['input_morehlp'];
   //                $funct = ParamExplode($field["input_show_func"]);
   //                $this->input_type    = AA_Stringexpand::unalias($funct[0]);
   //                $this->param         = array_slice( $funct, 1 );
   //                $this->html_rb_show  = $field["html_show"];
   //                if ( isset($field["const_arr"]) ) {
   //                    $this->const_arr  = $field["const_arr"];
   //                }
   //            }
   //        }

            $this->widget = AA_Widget::factoryByString('AA_Widget_', $this->data['input_show_func']);
        }
        return $this->widget;
    }
    /** getAliases function
     *
     */
    function getAliases() {
        $ret = array();
        if ($this->data['alias1']) {
            // fld used in PrintAliasHelp to point to alias editing page
            $ret[$this->data['alias1']] = array("fce" => $this->data['alias1_func'], "param" => $this->data['id'], "hlp" => $this->data['alias1_help'], "fld" => $this->data['id']);
        }
        if ($this->data['alias2']) {
            $ret[$this->data['alias2']] = array("fce" => $this->data['alias2_func'], "param" => $this->data['id'], "hlp" => $this->data['alias2_help'], "fld" => $this->data['id']);
        }
        if ($this->data['alias3']) {
            $ret[$this->data['alias3']] = array("fce" => $this->data['alias3_func'], "param" => $this->data['id'], "hlp" => $this->data['alias3_help'], "fld" => $this->data['id']);
        }
        return $ret;
    }

    /** getConstantGroup function
     * function finds group_id in field.input_show_func parameter
     */
    function getConstantGroup() {
        $showfunc   = ParseClassProperties($this->data['input_show_func'], 'AA_Widget_');
        // does this field use constants? Isn't it slice?
        if ( $showfunc['const'] AND (substr($showfunc['const'],0,7) != "#sLiCe-")) {
            return $showfunc['const'];
        }
        return false;
    }

    /** getRecord function
     *  @deprecated - for backward compatibility only
     */
    function getRecord() {
        return $this->data;
    }

    /** getSearchType function
     * @return text | numeric | date | constants
     */
    function getSearchType() {
        $showfunc   = ParseClassProperties($this->data['input_show_func'], 'AA_Widget_');
        $field_type = 'numeric';
        if ($this->data['text_stored']) {
            $field_type = 'text';
        }
        if (substr($this->data['input_validate'],0,4)=='date') {
            $field_type = 'date';
        }
        if ($showfunc['const'] AND !$this->_areSliceConstants($showfunc['const'])) {
            $field_type = 'constants';
        }
        return $field_type;
    }

    /** getWidgetAjaxHtml function
    * @param $item_id
    * @param $aa_value
    *   @todo create validator on input_validate
    */
    function getWidgetAjaxHtml($item_id, $visual='') {
        $widget = $this->getWidget();
        // AA_Property($id, $name='', $type, $multi=false, $persistent=true, $validator=null, $required=false, $input_help='', $input_morehlp='', $example='', $show_content_type_switch=0, $content_type_switch_default=FLAG_PLAIN) {
        $item   = AA_Item::getItem($item_id);

        $aa_variable = new AA_Variable( AA_Field::getId4Form($item_id, $this->getId()),
                                        $this->getName(),
                                        $this->getProperty('text_stored') ? 'text' : 'int',
                                        $widget->multiple(),
                                        false,                   // persistent @todo
                                        null,              // $validator - @todo create validator
                                        $this->required(),
                                        $this->getProperty('input_help'),
                                        $this->getProperty('input_morehlp'),
                                        null,               // $example;
                                        $this->getProperty('html_show') ?  AA_Formatter::getStandardFormattersBitfield() : AA_Formatter::getNoneFormattersBitfield(),
                                        AA_Formatter::getFlag($this->getProperty('html_default') ? 'HTML' : 'PLAIN'),
                                        $item->getAaValue($this->getId()),
                                        $item_id);

        $repre_value = $item->subst_alias($visual ? $visual : $this->getId());
        return $widget->getAjaxHtml($aa_variable, get_if($repre_value, '--'));
    }

    /** _areSliceConstants function
     *  @return true if constants are from slice
     */
    function _areSliceConstants($name) {
        // prefix indicates select from items
        return ( substr($name,0,7) == "#sLiCe-" );
    }


    /// Static methods ///

    /** ID of the field input - used for name atribute of input tag (or so)
    *   Format is:
    *       aa[i<long_item_id>][modified_field_id][]
    *   Note:
    *      first brackets contain
    *          'i'+long_item_id when item is edited or
    *          'n<number>_long_slice_id' if you want to add the item to slice_id
    *                                    <number> is used to add more than one
    *                                    item at the time
    *      modified_field_id is field_id, where all dots are replaced by '_'
    *      we always add [] at the end, so it becames array at the end
    *   Example:
    *       aa[i63556a45e4e67b654a3a986a548e8bc9][headline_______1][]
    *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
    *   Format is:
    *       aa[i<long_item_id>][modified_field_id][]
    *   Note:
    *      first brackets contain
    *          'u'+long_item_id when item is edited (the field is rewriten, rest
    *                           of item is untouched)
    *          'i'+long_item_id when item is edited (the value is added to current
    *                           value of the field, rest of item is untouched)
    *          'n<number>_long_slice_id' if you want to add the item to slice_id
    *                                    <number> is used to add more than one
    *                                    item at the time
    *      modified_field_id is field_id, where all dots are replaced by '_'
    *      we always add [] at the end, so it becames array at the end
    *   Example:
    *       aa[u63556a45e4e67b654a3a986a548e8bc9][headline________][]
    *       aa[i63556a45e4e67b654a3a986a548e8bc9][relation_______1][]
    *       aa[n1_54343ea876898b6754e3578a8cc544e6][publish_date____][]
    */
    function getId4Form($item_id, $field_id) {
        return "aa[i$item_id][". str_replace('.','_', $field_id).']';
    }

    /** Converts field id as used in the AA form to real field id, like:
     *  post_date______1  ==>  post_date......1
     */
    function getFieldIdFromVar($dirty_field_id) {
        return str_replace('._', '..', str_replace('__', '..', $dirty_field_id));
    }

    /** returns array(item_id,field_id) from name of variable used on AA form */
    function parseId4Form($input_id) {
        // aa[i<item_id>][<field_id>][]
        $parsed   = explode(']', $input_id);
        $item_id  = substr($parsed[0],4);
        $field_id = AA_Field::getFieldIdFromVar(substr($parsed[1],1));
        return array($item_id,$field_id);
    }
}


class AA_Fields {

    /** array of object of AA_Field type */
    var $fields;

    /** id of slice/module ... for which the fields are used */
    var $master_id;

    /** collection - each id could have multiple fieldsets.
     *  In fact we do not use this feature yet, it is just abstraction for
     *  "slice fields" - slice has two field sets - normal fields and
     *  "slice (setting) fields", where id of those fields begins with '_'
     */
    var $collection;

    /** Array of field ids sorted by priority */
    var $prifields;

    /** Array of aliases - for caching purposes */
    var $aliases;
    /** AA_Fields function
     * @param $master_id
     * @param $collection
     */
    function AA_Fields($master_id, $collection = 0) {
        $this->master_id  = $master_id;
        $this->fields     = null;
        $this->collection = $collection;
        $this->prifields  = null;
        $this->aliases    = null;
    }

    /** Returns list of fields which belongs to the slice
     *  The result is in two arrays - $fields    (key is field_id)
     *                              - $prifields (just field_id sorted by priority)
     *  @param $slice_id       - id of slice for which you want to get fields array
     *  @param $slice_fields   - if true, the result contains only "slice fields"
     *                           which are not used for items, but rather for slice
     *                           setting
     *  @see sliceobj:slice->fields()
     */
     /** load function
      * @param $force
      */
    function load($force=false) {
        if ( !$force AND !is_null($this->fields) ) {
            return;
        }

        $this->fields    = array();
        $this->prifields = array();

        $p_master_id = q_pack_id($this->master_id);
        $db = getDB();

        // slice_fields are begins with underscore
        // slice fields are the fields, which we do not use for items in the slice,
        // but rather for setting parameters of the slice
        $fields_where = ($this->collection == 0) ? "AND id NOT LIKE '\_%'" : "AND id LIKE '\_%'";
        $SQL = "SELECT * FROM field WHERE slice_id='$p_master_id' $fields_where ORDER BY input_pri";
        $db->query($SQL);
        while ($db->next_record()) {
            $fid                = $db->f("id");
            $this->fields[$fid] = new AA_Field(DBFields($db));
            $this->prifields[]  = $fid;
        }
        freeDB($db);
    }

    /** getField function
     *  @return the field (copy - just because of syntax - it is not possible
     *  to return null in &function())
     * @param $field_id
     */
    function getField($field_id) {
        $this->load();
        return isset($this->fields[$field_id]) ? $this->fields[$field_id] : null;
    }
    /** getProperty function
     * @param $field_id
     * @param $property
     */
    function getProperty($field_id, $property) {
        $this->load();
        return isset($this->fields[$field_id]) ? $this->fields[$field_id]->getProperty($property) : null;
    }
    /** getWidgetAjaxHtml function
     * @param $field_id
     * @param $item_id
     * @param $aa_value
     */
    function getWidgetAjaxHtml($field_id, $item_id, $visual='') {
        $this->load();
        return isset($this->fields[$field_id]) ? $this->fields[$field_id]->getWidgetAjaxHtml($item_id, $visual) : '';
    }

    /** getAliases function
     * @param $additional
     * @param $type
     */
    function getAliases($additional='', $type='') {
        if ( !is_null($this->aliases) ) {
            return $this->aliases;
        }
        $this->load();

        $this->aliases = is_array($additional) ? $additional : array();

        //  Standard aliases
        $this->aliases["_#ITEMINDX"] = GetAliasDef( "f_e:itemindex",        "id..............", _m("index of item within whole listing (begins with 0)"));
        $this->aliases["_#PAGEINDX"] = GetAliasDef( "f_e:pageindex",        "id..............", _m("index of item within a page (it begins from 0 on each page listed by pagescroller)"));
        $this->aliases["_#ITEM_ID_"] = GetAliasDef( "f_n:id..............", "id..............", _m("alias for Item ID"));
        $this->aliases["_#SITEM_ID"] = GetAliasDef( "f_h",                  "short_id........", _m("alias for Short Item ID"));

        if ( $type == 'justids') {  // it is enough for view of urls
            return $this->aliases;
        }

        $this->aliases["_#EDITITEM"] = GetAliasDef(  "f_e",            "id..............", _m("alias used on admin page index.php3 for itemedit url"));
        $this->aliases["_#ADD_ITEM"] = GetAliasDef(  "f_e:add",        "id..............", _m("alias used on admin page index.php3 for itemedit url"));
        $this->aliases["_#EDITDISC"] = GetAliasDef(  "f_e:disc",       "id..............", _m("Alias used on admin page index.php3 for edit discussion url"));
        $this->aliases["_#RSS_TITL"] = GetAliasDef(  "f_r",            "SLICEtitle",       _m("Title of Slice for RSS"));
        $this->aliases["_#RSS_LINK"] = GetAliasDef(  "f_r",            "SLICElink",        _m("Link to the Slice for RSS"));
        $this->aliases["_#RSS_DESC"] = GetAliasDef(  "f_r",            "SLICEdesc",        _m("Short description (owner and name) of slice for RSS"));
        $this->aliases["_#RSS_DATE"] = GetAliasDef(  "f_r",            "SLICEdate",        _m("Date RSS information is generated, in RSS date format"));
        $this->aliases["_#SLI_NAME"] = GetAliasDef(  "f_e:slice_info", "name",             _m("Slice name"));

        $this->aliases["_#MLX_LANG"] = GetAliasDef(  "f_e:mlx_lang",   MLX_CTRLIDFIELD,             _m("Current MLX language"));
        $this->aliases["_#MLX_DIR_"] = GetAliasDef(  "f_e:mlx_dir",   MLX_CTRLIDFIELD,             _m("HTML markup direction tag (e.g. DIR=RTL)"));

        // database stored aliases
        foreach ($this->fields as $field) {
            $this->aliases = array_merge($this->aliases, $field->getAliases());
        }
        return($this->aliases);
    }

    /** getCategoryFieldId function
     *  returns field id of field which stores category (usually "category........")
     */
    function getCategoryFieldId() {
        $this->load();
        $no = 10000;
        foreach ($this->fields as  $fid => $foo ) {
            if ( substr($fid, 0, 8) != "category" ) {
                continue;
            }
            $last = AA_Fields::getFieldNo($fid);
            $no = min( $no, ( ($last=='') ? -1 : (integer)$last) );
        }
        if ($no==10000) {
            return false;
        }
        $no = ( ($no==-1) ? '.' : (string)$no);
        return AA_Fields::createFieldId("category", $no);
    }


    /** getRecordArray function
     *  deprecated - for backward compatibility only
     */
    function getRecordArray() {
        $this->load();
        $ret = array();
        foreach ( $this->fields as $fid => $fld ) { // in priority order
            $ret[$fid] = $fld->getRecord();
        }
        return $ret;
    }

    /** getPriorityArray function
     *
     */
    function getPriorityArray() {
        $this->load();
        return $this->prifields;
    }
    /** getSearchArray function
     *
     */
    function getSearchArray() {
        $this->load();
        $i = 0;
        foreach ( $this->fields as $field_id => $field ) { // in priority order
            $field_type = $field->getSearchType();
            // we can hide the field, if we put in fields.search_pri=0
            $search_pri = ($field->getProperty('search_pri') ? ++$i : 0 );
                               //             $name,        $field,   $operators, $table, $search_pri, $order_pri
            $ret[$field_id] = GetFieldDef( $field->getProperty('name'), $field_id, $field_type, false, $search_pri, $search_pri);
        }
        return $ret;
    }

    /** isSliceField function
     *  Returns true, if the passed field id looks like slice setting field
     *  "slice fields" are not used for items, but rather for slice setting.
     *  Such fields are destinguished by underscore on first letter of field_id
     *  - static class function
     * @param $field_id
     */
    function isSliceField($field_id) {
        return $field_id AND ($field_id{0} == '_');
    }

    /** isField function
     *  Returns true, if the passed field id looks like field id
     *  - static class function
     * @param $field_id
     *  @todo - pass also $module_id and look directly into module, if the field
     *          is really field in slecific slice/module
     */
    function isField($field_id) {
        if ( !isset($GLOBALS['LINKS_FIELDS']) ) {
             $GLOBALS['LINKS_FIELDS'] = GetLinkFields();
             $GLOBALS['CATEGORY_FIELDS'] = GetCategoryFields();
             $GLOBALS['CONSTANT_FIELDS'] = GetConstantFields();
        }
        // changed this from [a-z_]+\.+[0-9]*$ because of alerts[12]....abcde
        return( ((strlen($field_id)==16) AND preg_match('/^[a-z0-9_]+\.+[0-9A-Za-z]*$/',$field_id))
               OR $GLOBALS['LINKS_FIELDS'][$field_id]
               OR $GLOBALS['CATEGORY_FIELDS'][$field_id]
               OR $GLOBALS['CONSTANT_FIELDS'][$field_id] );
    }


    /** createFieldId function
     *  Create field id from type and number
     *  - static class function
     * @param $ftype
     * @param $no
     */
    function createFieldId($ftype, $no="0") {
        if ((string)$no == "0") {
            $no = "";    // id for 0 is "xxxxx..........."
        }
        return $ftype. substr("................$no", -(16-strlen($ftype)));
    }

    /** getFieldType function
     *  get field type from id (works also for AA_Core_Fields (without dots))
     *  - static class function
     * @param $id
     */
    function getFieldType($id) {
        $dot_pos = strpos($id, ".");
        return ($dot_pos === false) ? $id : substr($id, 0, $dot_pos);
    }

    /** getFieldNo function
     *  get field number from id ('.', '0', '1', '12', ... )
     *  - static class function
     * @param $id
     */
    function getFieldNo($id) {
        return (string)substr(strrchr($id,'.'), 1);
    }

    /** createSliceField function
     *  creates slice field
     *  - static class function
     * @param $type
     */
    function createSliceField($type) {
        $varset = new CVarset();

//todo

        // copy fields
                // use the same setting for new field as template in AA_Core_Fields..
                $varset->addArray( $FIELD_FIELDS_TEXT, $FIELD_FIELDS_NUM );
                $varset->setFromArray($field_types[$type]);   // from template for this field

                // in AA_Core_Fields.. are fields identified by 'switch' or 'text'
                // identifiers (without dots!) by default. However if user add new
                // "template" field to the AA_Core_Fields.. slice, then the identifier
                // is full (it contains dots). We need base identifier, for now.
                // Also we will add underscore for all "slice fields" - the ones
                // which are not set for items, but rather for slice (settings)
                $ftype_base = ($slice_fields ? '_' : '') . AA_Fields::getFieldType($type);

                // get new field id
                $SQL = "SELECT id FROM field
                        WHERE slice_id='$p_slice_id' AND id like '". $ftype_base ."%'";
                $max = -1;  // Was 0
                $db->query($SQL);   // get all fields with the same type in this slice
                while ( $db->next_record() ) {
                    $max = max( $max, AA_Fields::getFieldNo($db->f('id')), 0);
                }
                $max++;
                //create name like "time...........2"
                $fieldid = AA_Fields::createFieldId($ftype_base, $max);

                $varset->set("slice_id", $slice_id, "unpacked" );
                $varset->set("id", $fieldid, "quoted" );
                $varset->set("name",  $val, "quoted");
                $varset->set("input_pri", $pri[$key], "number");
                $varset->set("required", ($req[$key] ? 1 : 0), "number");
                $varset->set("input_show", ($shw[$key] ? 1 : 0), "number");
                if (!$varset->doInsert('field')) {
                    $err["DB"] .= MsgErr("Can't copy field");
                    break;
                }
    }

}


/** GetSliceFields function
 *  @return list of fields which belongs to the slice
 *  The result is in two arrays - $fields    (key is field_id)
 *                              - $prifields (just field_id sorted by priority)
 *  @param $slice_id       - id of slice for which you want to get fields array
 *  @param $slice_fields   - if true, the result contains only "slice fields"
 *                           which are not used for items, but rather for slice
 *                           setting
 *  @see sliceobj:slice->fields()
 */
function GetSliceFields($slice_id, $slice_fields = false) {
    $p_slice_id = q_pack_id($slice_id);
    $db = getDB();
    // slice_fields are begins with underscore
    // slice fields are the fields, which we do not use for items in the slice,
    // but rather for setting parameters of the slice
    $slice_fields_where = ($slice_fields) ? "AND id LIKE '\_%'" : "AND id NOT LIKE '\_%'";
    $SQL = "SELECT * FROM field WHERE slice_id='$p_slice_id' $slice_fields_where ORDER BY input_pri";
    $db->query($SQL);
    while ($db->next_record()) {
        $fid          = $db->f("id");
        $fields[$fid] = $db->Record;
        $prifields[]  = $fid;
    }
    freeDB($db);
    return array($fields, $prifields);
}
/** GetFields4Select function
 * @param $slice_id
 * @param $slice_fields
 * @param $order
 * @param $add_empty
 */
function GetFields4Select($slice_id, $slice_fields = false, $order = 'name', $add_empty = false) {
    $p_slice_id = q_pack_id($slice_id);
    $db = getDB();
    if ($slice_fields == 'all') {
        // all fields (item as well as slice fields)
        $slice_fields_where = '';
    } elseif (!$slice_fields) {
        // only item fields (not begins with underscore)
        $slice_fields_where = "AND id NOT LIKE '\_%'";
    } else {
        // only slice fields (begins with underscore)
        $slice_fields_where = "AND id LIKE '\_%'";
    }
    $db->query("SELECT id, name FROM field WHERE slice_id='$p_slice_id' $slice_fields_where ORDER BY $order");
    $lookup_fields = array();
    if ($add_empty) {
        $lookup_fields[''] = " ";  // default - none
    }
    while ($db->next_record()) {
        $lookup_fields[$db->f('id')] = $db->f(name);
    }
    return $lookup_fields;
}

// -------------------------------------------------------------------------------

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
    $sel_in = $zids->sqlin( false );
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
 *       (only content table fields are restricted (yet))
 */
function GetItemContent($zids, $use_short_ids=false, $ignore_reading_password=false, $fields2get=false) {
    // Fills array $content with current content of $sel_in items (comma separated ids).
    $db = getDB();

    // construct WHERE clause
    list($sel_in, $settags) = itemContent_getWhere($zids, $use_short_ids);
    if (!$sel_in) {
        freeDB($db);
        trace("-");
        return false;
    }

    // get content from item table
    $delim = "";

    if ( is_object($zids) ) {
        if ( $zids->onetype() == 's' ) {
            $use_short_ids = true;
        }
    }

    // if the output fields are restricted, restrict also item fields
    if ( $fields2get ) {
        $item_sql_fields = $fields2get;

        // we need slice_id for each item, if we have to count with slice permissions
        if ( !$ignore_reading_password AND !in_array('slice_id........', $fields2get) ) {
            $item_sql_fields[] = 'slice_id........';
        }
        $metabase       = new AA_Metabase();
        $item_fields    = $metabase->itemFields4Sql($item_sql_fields);
        $content_fields = $metabase->nonItemFields($fields2get);
    } else {
        $item_fields = 'item.*';
    }

    $id_column = ($use_short_ids ? "short_id" : "id");
    $SQL       = "SELECT $item_fields FROM item WHERE item.$id_column $sel_in";
    $db->tquery($SQL);

    $n_items = 0;
    while ( $db->next_record() ) {
        // proove permissions for password-read-protected slices

        if (!$ignore_reading_password) {
            $reading_password = AA_Slices::getSliceProperty(unpack_id128($db->f("slice_id")),'reading_password');
        }

        $reading_permitted            = ($ignore_reading_password OR !$reading_password OR ($reading_password == md5($GLOBALS["slice_pwd"])));
        $item_permitted[$db->f("id")] = $reading_permitted;

        $n_items = $n_items+1;
        reset( $db->Record );
        if ( $use_short_ids ) {
            $foo_id = $db->f("short_id");
            $translate[unpack_id128($db->f("id"))] = $db->f("short_id"); // id -> short_id
            // WHERE for query to content table
            $new_sel_in .= "$delim '". quote($db->f("id")) ."'";
            $delim = ",";
        } else {
            $foo_id = unpack_id128($db->f("id"));
        }
        // Note that it stores into the $content[] array based on the id being used which
        // could be either shortid or longid, but is NOT tagged id.
        while (list($key, $val) = each($db->Record)) {
            // we need only item fields
            if (!is_numeric($key)) {
                $content[$foo_id][AA_Fields::createFieldId($key)][] = array(
                     "value" => $reading_permitted ? $val : _m("Error: Missing Reading Password"));
            }
        }
    }

    // Skip the rest if no items found
    if ($n_items == 0) {
        freeDB($db);
        trace("-");
        return null;
    }

    // If its a tagged id, then set the "idtag..........." field
    if ($settags) {
        $tags = $zids->gettags();
        while ( list($k,$v) = each($tags)) {
            $content[$k]["idtag..........."][] = array("value" => $v);
        }
    }

    // construct WHERE query to content table if used short_ids
    if ( $use_short_ids) {
        $sel_in = (count($translate) > 1) ? " IN ( $new_sel_in ) " : " = $new_sel_in ";
    }

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

        $SQL = "SELECT * FROM content
                 WHERE item_id $sel_in $restrict_cond
                 ORDER BY content.number"; // usable just for constants

        $db->tquery($SQL);

        while ( $db->next_record() ) {

            $fooid = ($use_short_ids ? $translate[unpack_id128($db->f("item_id"))] :
                                       unpack_id128($db->f("item_id")) );

            if ( !$item_permitted[$db->f("item_id")] ) {
                $content[$fooid][$db->f("field_id")][0] = array( "value" => _m("Error: Missing Reading Password"));
                continue;
            }

            // which database field is used (from 05/15/2004 we have FLAG_TEXT_STORED set for text-field-stored values
            $db_field = ( ($db->f("text")!="") OR ($db->f("flag") & FLAG_TEXT_STORED) ) ? 'text' : 'number';
            $content[$fooid][$db->f("field_id")][] = array( "value" => $db->f($db_field),
                                                            "flag"  => $db->f("flag") );
        }
    }

    // add special fields to all items (zids)
    foreach ($content as $iid => $foo ) {
        // slice_id... and id... is packed  - add unpacked variant now
        $content[$iid]['u_slice_id......'][] =
            array('value' => unpack_id128($content[$iid]['slice_id........'][0]['value']));
        $content[$iid]['unpacked_id.....'][] =
            array('value' => unpack_id128($content[$iid]['id..............'][0]['value']));
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
          $foo_id = unpack_id128($db->f("id"));
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
        case "id":          return array( "value"=> unpack_id128($db->f("id") ));
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
    return ($db->next_record() ? unpack_id128($db->f("id")) : false);
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
      <link rel="StyleSheet" href="'.$stylesheet.'" type="text/css">';
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
    $number_db_types = array ("float","double","decimal","int", "timestamp");
    reset ($number_db_types);

    while (list (,$n_col) = each ($number_db_types))
        if (strstr ($field_type, $n_col)) {
            return true;
        }

    return false;
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
function gensalt($saltlen)
{
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
function aa_move_uploaded_file($varname, $destdir, $perms = 0, $filename = null)
{
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
    $dummy = "~#$?_";
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

    reset ($var_types);
    while (list (,$var_type) = each ($var_types)) {
        if (is_array($vars[$var_type])) {
            reset ($vars[$var_type]);
            while (list ($var, $value) = each ($vars[$var_type])) {
                global $$var;
                if ($debugfill) huhl("add_post2shtml_vars:$$var=",$value);
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

/** FetchSliceReadingPassword function
 * On many places in Admin panel, it is secure to read sensitive data => use this function
 */
function FetchSliceReadingPassword() {
    global $slice_id, $slice_pwd, $db;
    $db->query("SELECT reading_password FROM slice WHERE id='".q_pack_id($slice_id)."'");
    if ($db->next_record()) {
        $slice_pwd = $db->f("reading_password");
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
        $key = md5(serialize($function).serialize($params).$additional_params);
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



/** toexecute class - used for many short tasks, such as sending an e-mail for
 *  alerts. Instead of sending thounsands of e-mails in one php script run (bad
 *  eperienses with 1000+ emails), we store just store the task in the database.
 *  Then we call misc/toexecute.php3 script from AA cron (say each 2 minutes)
 *  and if there is any task in the quweue, it is executed. This way we spread
 *  sending of weekls alerts to thounsands users to several hours.
 *  Ussage:
 *    Instead of calling:
 *        $object->function_name(param1, param2);
 *    we will use
 *        $toexecute = new toexecute;
 *        $toexecute->later($object, array(param1, param2));
 *
 *        Then we create method $object->toexecutelater(param1, param2)
 *        in which we will call $object->function_name(param1, param2);
 *
 *  The name of 'toexecutelater' method is fixed - This is because of security.
 *  We do not want to allow users to execute any method of any object just
 *  by inserting some data in the database.
 */
class toexecute {

    /** global_instance function
     *  "class function" obviously called as toexecute::global_instance();
     *  This function makes sure, there is global instance of the class
     */
    function global_instance() {
        if ( !isset($GLOBALS['toexecute']) ) {
            $GLOBALS['toexecute'] = new toexecute;
        }
    }

    /** later function
     *  Stores the object and params to the database for later execution.
     *  Such task is called from cron (the order depends on priority)
     *  selector is used for identifying class of task - used for deletion
     *  of duplicated task
     * @param $object
     * @param $params
     * @param $seletor
     * @param $priority
     * @param $time
     *  @example: we need to recount all links in allcategories (Links module),
     *           so we need to cancel all older "recount" tasks, since it will
     *           be dubled in the queue (we call cancel_all() method for it)
     */
    function later( &$object, $params=array(), $selector='', $priority=100, $time=null ) {
        global $auth;
        $varset = new Cvarset(
            array( 'created'       => time(),
                   'execute_after' => ($time ? $time : time()),
                   'aa_user'       => $auth->auth['uid'],
                   'priority'      => $priority,
                   'selector'      => ($selector ? $selector : get_class($object)),
                   'object'        => serialize($object),
                   'params'        => serialize($params)
                  ));
         // store the task in the queue (toexecute table)
         if ( !$varset->doInsert('toexecute') ) {
             // if you can't store it in the queue (table not created?)
             // - execute it directly
             return $this->execute_one($object,$params);
         }
         return true;
    }

    /** before the task is planed, it check, if it is not already scheduled
     *  (from previous time). The task is considered as planed, if the SELECTORs
     *  are the same
     */
    function laterOnce( &$object, $params, $selector, $priority=100, $time=null ) {
        if ( !GetTable2Array("SELECT selector FROM toexecute WHERE selector='".quote($selector)."'", 'aa_first', 'aa_mark')) {
            $this->later($object, $params, $selector, $priority, $time);
        }
    }

    /** cancel_all function
     * @param $selector
     */
    function cancel_all($selector) {
        $varset = new Cvarset;
        $varset->doDeleteWhere('toexecute',"selector='".quote($selector)."'");
    }
    /** execute function
     * @param $allowed_time
     */
    function execute($allowed_time = 0) {  // standard run is 16 s

        if ( !$allowed_time ) {
            $allowed_time = (float) (defined('TOEXECUTE_ALLOWED_TIME' ) ? TOEXECUTE_ALLOWED_TIME : 16.0);
        }
        /** there we store the the time needed for last task of given type
         *  (selector) - this value we use in next round to determine, if we can
         *  run one more such task or if we left it for next time */
        $execute_times = array();

        // get just ids - the task itself we will grab later, since the objects
        // in the database could be pretty big, so we want to grab it one by one
        $tasks = GetTable2Array("SELECT id FROM toexecute WHERE execute_after < ".now()." ORDER by priority DESC", 'id', 'aa_mark');

        $execute_start = get_microtime();
        if (is_array($tasks)) {
            foreach ($tasks as $task_id => $foo) {
                $task = GetTable2Array("SELECT * FROM toexecute WHERE id='$task_id'", 'aa_first', 'aa_fields');

                $task_type     = get_if($tasks['selector'],'aa_unspecified');
                $expected_time = get_if($execute_times[$task_type], 1.0);  // default time expected for one task is 1 second
                $task_start    = get_microtime();

                // can we run next task? Does it (most probably) fit in allowed_time?
                if ( (($task_start + $expected_time) - $execute_start) > $allowed_time) {
                    break;
                }
                $varset = new Cvarset( array( 'priority' => max( $task['priority']-1, 0 )));
                $varset->addkey('id', 'number', $task['id']);
                // We lower the priority for this task before the execution, so
                // if the task is not able to finish, then other tasks with the same
                // priority is called before this one (next time)
                $varset->doUpdate('toexecute');

                $object = unserialize($task['object']);
                if ( $GLOBALS['debug'] ) {
                    huhl($object);
                }
                $retcode = $this->execute_one($object, unserialize($task['params']));

                // Task is done - remove it from queue
                $varset->doDelete('toexecute');
                $execute_times[$task_type] = get_microtime() - $task_start;
                AA_Log::write('TOEXECUTE', $execute_times[$task_type]. ":$retcode:".$task['params'], get_class($object));
            }
        }
    }
    /** execute_one function
     * @param $object
     * @param $params
     */
    function execute_one(&$object, $params) {
        if ( !is_object($object) ) {
            return 'No object'; // Error
        }
        set_time_limit( 30 );   // 30 seconds for each task
        return call_user_func_array(array($object, 'toexecutelater'), $params);
    }


// end of toexecute class
}

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
function aa_version() {
    return 'ActionApps 2.11.0 ($Date$, $Revision$)';
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

class AA_ChangeProposal {
    var $resource_id;
    var $selector;
    var $values;    // array of values
    /** AA_ChangeProposal function
     * @param $resource_id
     * @param $selector
     * @param $values
     */
    function AA_ChangeProposal($resource_id, $selector, $values) {
        $this->resource_id = $resource_id;
        $this->selector    = $selector;
        $this->values      = $values;
    }
    /** getResourceId function
     *
     */
    function getResourceId() {
        return($this->resource_id);
    }
    /** getSelector function
     *
     */
    function getSelector() {
        return($this->selector);
    }
    /** getValues function
     *
     */
    function getValues() {
        return($this->values);
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

class AA_ChangesMonitor {
    /** addProposal function
     * @param $change_proposal
     */
    function addProposal($change_proposal) {
        return $this->_add($change_proposal, 'proposal');
    }
    /** addHistory function
     * @param $change_proposal
     */
    function addHistory($change_proposal) {
        return $this->_add($change_proposal, 'history');
    }
    /** _add function
     * @param $change_proposal
     * @param $type
     */
    function _add($change_proposal, $type) {
        global $auth;

        $change_id = new_id();
        $varset = new CVarset;
        $varset->addkey("id",       "text",   $change_id);
        $varset->add("time",        "number", now());
        $varset->add("user",        "text",   is_object($auth) ? $auth->auth["uid"] : '');
        $varset->add("type",        "text",   $type);
        $varset->add("resource_id", 'text',   $change_proposal->getResourceId());
        $varset->doInsert('change');

        $priority = 0;
        foreach ( $change_proposal->getValues() as $value ) {
            $varset->clear();
            $varset->add("change_id", "text",   $change_id);
            $varset->add("selector",  "text",   $change_proposal->getSelector());
            $varset->add("priority",  "number", $priority++);
            $varset->add("type",      "text",   gettype($value));
            $varset->add("value",     "text",   $value);
            $varset->doInsert('change_record');
        }
        return true;
    }
    /** deleteProposal
     * @param $change_id
     */
    function deleteProposal($change_id) {
        $varset = new CVarset;
        $varset->doDeleteWhere('change_record', "change_id = '".quote($change_id). "'");
        $varset->clear();
        $varset->addkey("id", "text", $change_id);
        $varset->doDelete('change');
    }
    /** deleteProposalForSelector function
     * @param $resource_id
     * @param $selector
     */
    function deleteProposalForSelector($resource_id, $selector) {
        $changes_ids = GetTable2Array("SELECT DISTINCT change_id  FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                         WHERE `change`.resource_id = '".quote($resource_id)."' AND `change`.type = 'proposal' AND `change_record`.selector = '".quote($selector)."'", '', 'change_id');
        if ( is_array($changes_ids) ) {
            foreach( $changes_ids as $change_id ) {
                $this->deleteProposal($change_id);
            }
        }
    }

    /** getProposals function
     *  @return all proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     * @param $resource_ids
     */
    function getProposals($resource_ids) {
        return $this->_get($resource_ids, 'proposal');
    }
    /** getHistory function
     * @param $resource_ids
     */
    function getHistory($resource_ids) {
        return $this->_get($resource_ids, 'history');
    }

    /** _get function
     * @return all proposals for given resource (like item_id)
     *  return value is array ordered by time of proposal
     * @param $resource_ids
     * @param $type
     */
    function _get($resource_ids, $type) {
        $garr = new AA_GeneralizedArray();
        if ( !is_array($resource_ids) OR (count($resource_ids)<1) ) {
            return $garr;
        }

        $ids4sql = "'". implode("','", array_map( "quote", $resource_ids)). "'";

        $changes = GetTable2Array("SELECT `change_record`.*, `change`.resource_id
                                FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                WHERE `change`.resource_id IN ($ids4sql)
                                AND   `change`.type='$type'
                                ORDER BY `change`.resource_id, `change`.time, `change_record`.change_id, `change_record`.selector, `change_record`.priority", '', 'aa_fields');

        if ( is_array($changes) ) {
            foreach($changes as $change) {
                if ( $change['type'] ) {
                    $value = $change['value'];
                    settype($value, $change['type']);
                    $garr->add($value, array($change['resource_id'], $change['change_id'], $change['selector']));
                }
            }
        }
        return $garr;
    }
    /** getProposalByID function
     * @param $change_id
     */
    function getProposalByID($change_id) {
        $garr = new AA_GeneralizedArray();
        if ( !$change_id ) {
            return $garr;
        }
        $changes = GetTable2Array("SELECT `change_record`.*, `change`.resource_id
                                FROM `change` LEFT JOIN `change_record` ON `change`.id = `change_record`.change_id
                                WHERE `change`.id = '". quote($change_id)."'
                                ORDER BY `change_record`.selector, `change_record`.priority", '', 'aa_fields');

        if ( is_array($changes) ) {
            foreach($changes as $change) {
                if ( $change['type'] ) {
                    $value = $change['value'];
                    settype($value, $change['type']);
                    $garr->add($value, array($change['resource_id'], $change['selector']));
                }
            }
        }
        return $garr->getArray();
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

?>
