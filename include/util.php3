<?php 
//$Id$
/* 
Copyright (C) 1999, 2000 Association for Progressive Communications 
http://www.apc.org/

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

#
# Miscellaneous utility functions 
#

if (!defined("AA_UTIL_INCLUDED"))
     define ("AA_UTIL_INCLUDED",1);
else return;

require $GLOBALS[AA_INC_PATH]."constants.php3";
require $GLOBALS[AA_INC_PATH]."mgettext.php3";
require $GLOBALS[AA_INC_PATH]."zids.php3";

function get_aa_url ($href) {
    global $AA_INSTAL_PATH, $sess;
    return $sess->url ($AA_INSTAL_PATH.$href);
}    

function get_admin_url ($href) {
    global $AA_INSTAL_PATH, $sess;
    return $sess->url ($AA_INSTAL_PATH."admin/".$href);
}

/// Adds slash at the end of a directory name if it is not yet there.
function endslash (&$s) {
    if (strlen ($s) && substr ($s,-1) != "/")
        $s .= "/";
}

/// Wraps the in_array function, which was introduced only in PHP 4.
function my_in_array ($needle, $array) {
    return in_array ($needle, $array);
}

/** To use this function, the file "debuglog.txt" must exist and have writing permission for the www server */
function debuglog ($text) 
{
	$f = fopen ($GLOBALS[AA_INC_PATH]."logs.txt","a");
    if ($f) {
    	fwrite ($f, date( "h:i:s j-m-y ")  . $text . "\n");
	    fclose ($f);
    }
}

// Shift to another page (must be before any output from script)
function go_url($url, $add_param="") {
  global $sess;
  if( isset( $sess ) )
    page_close();
  if( $add_param != "" )
    $url = con_url( $url, rawurlencode($add_param));
  $netscape = (rXn=="") ? "rXn=1" : "rXn=".++$rXn;   // special parameter for Netscape to reload page
  header("Status: 302 Moved Temporarily");
	header("Location: ". con_url($url,$netscape));
 	exit;
}

// Note this doesn't appear to be used (mitra)
function go_url_javascript ($to_go_url) {
	echo "
    <SCRIPT language=JavaScript>
    <!--\n
   		document.location = \"".$sess->url($to_go_url)."\";\n
    // -->\n
    </SCRIPT>";
}

// Expand return_url, possibly adding a session to it
function expand_return_url ($addsess) {
	global $return_url, $sess;
	$r1 = $return_url;    # return_url is encoded in calling URL, but decoded by PHP before global is set
#	$r1 = urldecode($return_url);
	if ($addsess && isset($sess))
		return $sess->url($r1);
	else	return $r1;
}
// This function goes to either $return_url if set, or to $url
// if $usejs is set, then it will use inline Javascript, its not clear why this is done 
//    sometimes (item.php3) but not others.
// if $addsess is set, then any session variable will be added, to the return_url case to allow for quicker 2nd access
//    session is always added to the other case
// if $add_param are set, then they are added to the cases EXCEPT return_url
function go_return_or_url($url,$usejs,$addsess,$add_param="") {
	global $return_url,$sess;
      if ($return_url) {
	if ($usejs) {
		echo '<SCRIPT Language="JavaScript"><!--
              		document.location = "'. expand_return_url($addsess) .'";
	              // -->
        	      </SCRIPT>';
	} else {
		go_url(expand_return_url($addsess));
	}
      } else {
	if ($url) go_url($sess->url($url),$add_param);
	// Note if no $url or $return_url then drops through - this is used in index.php3
      }
}


// adds all items from source to target, but doesn't overwrite items
function array_add ($source, &$target)
{
    if (is_array ($source)) {
        reset ($source);
        while (list ($k,$v) = each ($source)) 
            if (!isset ($target[$k])) $target[$k] = $v;
            else $target[] = $v;
    }
}   

function self_complete_url() {
    return self_server().$GLOBALS[REQUEST_URI];
}

# returns server name with protocol and port
function self_server() {
  global $HTTP_HOST, $SERVER_NAME, $HTTPS, $SERVER_PORT;
  if( isset($HTTPS) && $HTTPS == 'on' ){
    $PROTOCOL='https';
    if($SERVER_PORT != "443")
      $port = ":$SERVER_PORT";
  } else {
    $PROTOCOL='http';
	  if($SERVER_PORT != "80")
      $port = ":$SERVER_PORT";
  }
  // better to use HTTP_HOST - if we use SERVER_NAME and we try to open window
  // by javascript, it is possible that the new window will be opened in other
  // location than window.opener. That's  bad because accessing window.opener
  // then leads to access denied javascript error (in IE at least)
  $sname = ($HTTP_HOST ? $HTTP_HOST : $SERVER_NAME);
  return("$PROTOCOL://$sname$port");
}

# returns server name with protocol, port and current directory of php script
function self_base () {
  global $PHP_SELF;
  return (self_server(). ereg_replace("/[^/]*$", "", $PHP_SELF) . "/");
}

# returns server name with protocol, port and current directory of shtml file
function shtml_base() {
  global $DOCUMENT_URI;
  return (self_server(). ereg_replace("/[^/]*$", "", $DOCUMENT_URI) . "/");
}

# returns url of current shtml file
function shtml_url() {
  global $DOCUMENT_URI;
  return (self_server(). $DOCUMENT_URI);
}

# returns url of current shtml file
function shtml_query_string() {
  global $QUERY_STRING_UNESCAPED, $REDIRECT_QUERY_STRING_UNESCAPED;
  return isset($REDIRECT_QUERY_STRING_UNESCAPED) ?
               $REDIRECT_QUERY_STRING_UNESCAPED : $QUERY_STRING_UNESCAPED;
}

# skips terminating backslashes
function DeBackslash($txt) {
	return str_replace('\\', "", $txt);        // better for two places
}   
 
# adds variables passed by QUERY_STRING_UNESCAPED (or user $query_string) 
# to GLOBALS 
function add_vars($query_string="", $debug="") {
    $varstring = ( $query_string ? $query_string : shtml_query_string() );

    $vars = explode("&",$varstring);
  
    while (list($temp,$var) = each ($vars)) {
        $var = urldecode (DeBackslash($var));
        $pos = strpos($var, "=");
        if(!$pos)
            continue;

        $lvalue = substr($var,0,$pos);
        $value  = substr($var,$pos+1);
        $arrindex = strstr ($lvalue,'[');
        if (!$arrindex) {
            $GLOBALS[$lvalue]= $value;   # normal variable
            continue;
        }    
        
        # array variable
        unset($indexes);
        $lindex = "";
        $lvalue = substr ($lvalue, 0, strpos ($lvalue,'['));
        // are we inside some [] brackets?
        
        while( strpos('x'.$arrindex, '[')==1 AND          # correct array index
               ($end = strpos($arrindex, ']'))) {         #  = no == !!
            $indexes[] = substr( $arrindex, 1, $end-1 );  # extract just index
            $arrindex = substr( $arrindex, $end+1 );      # next index
        }
        reset($indexes);
        while( list(,$v) = each($indexes) ) {
            # add apostrophs for textual indexed
            $first = substr($v,0,1);                      # first letter
            if( $first!='"' AND 
                $first!="'" AND 
                strlen($v) != strspn($v,'0123456789') )   # [] and [12] allowed
                $lindex .= "['$v']";
             else
                $lindex .= "[$v]";
        }
        $evalcode = '$'.$lvalue.$lindex."=\$value;";
        if ($in == 0 && ereg ("[A-Z0-9_.]*", $lvalue)) {
            global $$lvalue;
            eval ($evalcode);
        }
    }
    return count ($vars);
}

# function to double backslashes and apostrofs 
function quote($str) {
  return addslashes($str);  
} 
 

# function addslashes enhanced by array processing
function AddslashesArray($val) {
  if (!is_array($val)) {
    return addslashes($val);
  }  
  for (reset($val); list($k, $v) = each($val); )
    $ret[$k] = Myaddslashes($v);
  return $ret;
}    

# function for processing posted or get variables
# adds quotes, if magic_quotes are switched off
# except of variables in $skip array (usefull for 'encap' for example)
function QuoteVars($method="get", $skip='') {
  
  if( get_magic_quotes_gpc() )
    return;
    
  $transfer = ( ($method == "get") ? "HTTP_GET_VARS" : "HTTP_POST_VARS");
  if( !isset($GLOBALS[$transfer]) OR !is_array($GLOBALS[$transfer]))
    return;
  reset( $GLOBALS[$transfer] );
  while( list($varname,$value) = each( $GLOBALS[$transfer] ))
    if( !is_array($skip) || !isset($skip[$varname]) )
      $GLOBALS[$varname] = AddslashesArray($value);
}  

# function for extracting variables from $r_hidden session field
function GetHidden() {
  global $r_hidden;
  if( !isset($r_hidden) OR !is_array($r_hidden))
    return;
  reset( $r_hidden );
  while( list($varname,$value) = each( $r_hidden ))
    $GLOBALS[$varname] = ($value);
}  
 
# function to reverse effect of "magic quotes"
// not needed in MySQL and get_magic_quotes_gpc()==1
function dequote($str) {
		return $str;
}

# This function appends any number of QUERY_STRING (separated by &) parameters to given URL, using apropriate ? or &.
function con_url($Url,$Params){
  return ( strstr($Url, '?') ? $Url."&".$Params : $Url."?".$Params );
} 

# prints content of a (multidimensional) array
function p_arr_m ($arr, $level = 0) {
  if(! DEBUG_FLAG )
    return;
   if ( !isset($arr) OR !is_array($arr)) { 
     for ($i = 0; $i < $level; $i++) { echo "&nbsp;&nbsp;&nbsp;"; };
         echo ( isset($arr) ? " Not array: $arr <br>" : " (Empty Array) <br>");
     return;
   };
   while (list($key, $val) = each($arr)) {
      if ( is_array($val) ) { 
         for ($i = 0; $i < $level; $i++) { echo "&nbsp;&nbsp;&nbsp;"; };
         echo htmlspecialchars($key) . " (Array) <br>";
         p_arr_m ($val, $level + 1);
      } else {
         for ($i = 0; $i < $level; $i++) { echo "&nbsp;&nbsp;&nbsp;"; };
         echo htmlspecialchars($key) . " => " . htmlspecialchars($val) . "<br>";
      }
   }
}

# debug function, prints hash size,  keys and values of hash  
function p_arr($a,$name="given array") {
  p_arr_m($a);
}

# returns new unpacked md5 unique id, except these which can  force unexpected end of string  
function new_id ($seed="hugo"){
  do {
   $foo=md5(uniqid($seed));
  } while (ereg("(00|27)",$foo));  // 00 is end of string, 27 is '
  return $foo;
} 

# Returns a unique id from a string, note that it will always return the same id from the same string so 
# can be used to compare the hashes.
function string2id ($str) {
  $trialstr = $str;
  do {
   $foo=md5($trialstr);
   $trialstr = $trialstr . " ";
  } while (ereg("(00|27)",$foo));  // 00 is end of string, 27 is '
  return $foo;
}

# returns packed md5 id, not quoted !!!
# Note that pack_id is used in many places where it is NOT 128 bit ids.
function pack_id ($unpacked_id){
    global $errcheck;
    if ($errcheck && !preg_match("/^[0-9a-f]+$/", $unpacked_id)) # Note was + instead {32}
        huhe("Warning: trying to pack $unpacked_id.<br>\n");
  return ((string)$unpacked_id == "0" ? "0" : pack("H*",trim($unpacked_id)));
}

# returns unpacked md5 id
function unpack_id($packed_id){
  if( (string)$packed_id == "0" )
    return "0";
  $foo=bin2hex($packed_id);  // unpack("H*", $str) does not work in PHP 4.0.3 so bin2hex used
  return (string)$foo;
}


  
# returns current date/time as timestamp
 function now(){ 
   return time();
 }

# returns number of second since 1970 from date in MySQL format
function date2sec($dat) {
  if( Ereg("^([0-9]{4})-([0-9]{1,2})-([0-9]{1,2}) ([0-9]{1,2}):([0-9]{1,2}):([0-9]{1,2})", $dat, $d))
    return MkTime($d[4], $d[5], $d[6], $d[2], $d[3], $d[1]);
  return 0;  
}

# function which detects the browser
function detect_browser() { 
  global $HTTP_USER_AGENT, $BName, $BVersion, $BPlatform; 

  // Browser 
  if(eregi("(msie) ([0-9]{1,2}.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "MSIE"; $BVersion=$match[2]; }
  elseif(eregi("(opera) ([0-9]{1,2}.[0-9]{1,3}){0,1}",$HTTP_USER_AGENT,$match) || eregi("(opera/)([0-9]{1,2}.[0-9]{1,3}){0,1}",$HTTP_USER_AGENT,$match)) 
    { $BName = "Opera"; $BVersion=$match[2]; }
  elseif(eregi("(konqueror)/([0-9]{1,2}.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Konqueror"; $BVersion=$match[2]; }
  elseif(eregi("(lynx)/([0-9]{1,2}.[0-9]{1,2}.[0-9]{1,2})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Lynx"; $BVersion=$match[2]; }
  elseif(eregi("(links) \(([0-9]{1,2}.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Links"; $BVersion=$match[2]; }
  elseif(eregi("(netscape6)/(6.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Netscape"; $BVersion=$match[2]; }
  elseif(eregi("mozilla/5",$HTTP_USER_AGENT)) 
    { $BName = "Netscape"; $BVersion="Unknown"; }
  elseif(eregi("(mozilla)/([0-9]{1,2}.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Netscape"; $BVersion=$match[2]; }
  elseif(eregi("w3m",$HTTP_USER_AGENT)) 
    { $BName = "w3m"; $BVersion="Unknown"; }
  else{$BName = "Unknown"; $BVersion="Unknown";} 
  
  // System 
  if(eregi("win32",$HTTP_USER_AGENT)) 
    $BPlatform = "Windows"; 
  elseif((eregi("(win)([0-9]{2})",$HTTP_USER_AGENT,$match)) || (eregi("(windows) ([0-9]{2})",$HTTP_USER_AGENT,$match))) 
    $BPlatform = "Windows $match[2]"; 
  elseif(eregi("(winnt)([0-9]{1,2}.[0-9]{1,2}){0,1}",$HTTP_USER_AGENT,$match)) 
    $BPlatform = "Windows NT $match[2]"; 
  elseif(eregi("(windows nt)( ){0,1}([0-9]{1,2}.[0-9]{1,2}){0,1}",$HTTP_USER_AGENT,$match)) 
    $BPlatform = "Windows NT $match[3]"; 
  elseif(eregi("linux",$HTTP_USER_AGENT)) 
    $BPlatform = "Linux"; 
  elseif(eregi("mac",$HTTP_USER_AGENT)) 
    $BPlatform = "Macintosh"; 
  elseif(eregi("(sunos) ([0-9]{1,2}.[0-9]{1,2}){0,1}",$HTTP_USER_AGENT,$match)) 
    $BPlatform = "SunOS $match[2]"; 
  elseif(eregi("(beos) r([0-9]{1,2}.[0-9]{1,2}){0,1}",$HTTP_USER_AGENT,$match)) 
    $BPlatform = "BeOS $match[2]"; 
  elseif(eregi("freebsd",$HTTP_USER_AGENT)) 
    $BPlatform = "FreeBSD"; 
  elseif(eregi("openbsd",$HTTP_USER_AGENT)) 
    $BPlatform = "OpenBSD"; 
  elseif(eregi("irix",$HTTP_USER_AGENT)) 
    $BPlatform = "IRIX"; 
  elseif(eregi("os/2",$HTTP_USER_AGENT)) 
    $BPlatform = "OS/2"; 
  elseif(eregi("plan9",$HTTP_USER_AGENT)) 
    $BPlatform = "Plan9"; 
  elseif(eregi("unix",$HTTP_USER_AGENT) || eregi("hp-ux",$HTTP_USER_AGENT)) 
    $BPlatform = "Unix"; 
  elseif(eregi("osf",$HTTP_USER_AGENT)) 
    $BPlatform = "OSF"; 
  else{$BPlatform = "Unknown";} 

  if ($GLOBALS[debug]) huhl("$HTTP_USER_AGENT => $BName,$BVersion,$BPlatform");   
} 

 
# debug function for printing debug messages
function huh($msg) {
  if(! $GLOBALS['debug'] )
    return;
  echo "<br>\n$msg";
}  

# debug function for printing debug messages escaping HTML
function huhw($msg) {
  if(! $GLOBALS['debug'] )
    return;
  echo "<br>\n". HTMLspecialChars($msg);
}

# Report only if errcheck is set, this is used to test for errors to speed debugging
# Use to catch cases in the code which shouldn't exist, but are handled anyway.
function huhe ($a, $b="", $c="",$d="",$e="",$f="",$g="",$h="",$i="",$j="") {
    global $errcheck;
    if ($errcheck) {
        huhl($a, $b="", $c="",$d="",$e="",$f="",$g="",$h="",$i="",$j="");
    }
}
# Set a starting timestamp, if checking times, huhl can report 
# Debug function to print debug messages recursively - handles arrays
function huhl ($a, $b="", $c="",$d="",$e="",$f="",$g="",$h="",$i="",$j="") {
    global $debugtimes,$debugtimestart;
    if (isset($a)) {
        print("<listing>");
        if ($debugtimes) {
           if (! $debugtimestart) {
                list($usec, $sec) = explode(" ",microtime()); 
                $debugtimestart = ((float)$usec + (float)$sec); 
            }
            list($usec, $sec) = explode(" ",microtime()); 
            print("Time: ".(((float)$usec + (float)$sec) - $debugtimestart)."\n"); 
        }
        print_r($a);
        if (isset($b)) print_r($b);
        if (isset($c)) print_r($c);
        if (isset($d)) print_r($d);
        if (isset($e)) print_r($e);
        if (isset($f)) print_r($f);
        if (isset($g)) print_r($g);
        if (isset($h)) print_r($h);
        if (isset($i)) print_r($i);
        if (isset($j)) print_r($j);
        print("</listing>\n");
    }
}

function huhsess($msg="") {
	global $sess;
	foreach (array_keys($sess->pt) as $i) {
		$sessvars[$i]=$GLOBALS[$i];
	}
	huhl($msg,$sessvars);
}
#Prints all values from array
function PrintArray($a){
 if (is_array ($a))
   while ( list( $key, $val ) = each( $a ) )
     echo $val;
}

#Prepare OK Message
function MsgOK($txt){
  return "<div class=okmsg>$txt</div>";
}

#Prepare Err Message
function MsgERR($txt){
  return "<div class=err>$txt</div>";
}

# function for unpacking string in edit_fields and needed_fields in database to array
function UnpackFieldsToArray($packed, $fields) {
  reset($fields);
  $i=0;
  while( list($field,) = each($fields)) 
    $arr[$field] = (substr($packed,$i++,1)=="y" ? true : false);
  return $arr;
}  

# function fills the array from constants table
function GetConstants($group, $db, $order='pri', $column='name') {
  if( $order )
    $order_by = "ORDER BY $order";
  $db->query("SELECT name, value FROM constant 
               WHERE group_id='$group' $order_by");
  while($db->next_record())
    $arr[$db->f(value)] = $db->f($column);
  return $arr;
}     

# gets fields from main table of the module
function GetModuleInfo($module_id, $type) {
  global $db, $MODULES;
  $p_module_id = q_pack_id($module_id);
  
  $db->query("SELECT * FROM " . $MODULES[$type]['table'] ."
               WHERE id = '$p_module_id'");
  return  ($db->next_record() ? $db->Record : false);
}  

# gets slice fields
function GetSliceInfo($slice_id) {
  return  GetModuleInfo($slice_id,'S');
}  

# gets view fields
function GetViewInfo($vid) {
  global $db;
  $db->query("SELECT view.*, slice.deleted FROM view, slice
               WHERE slice.id=view.slice_id
                 AND view.id='$vid'");
  return  ($db->next_record() ? $db->Record : false);
}  

# function converts table from SQL query to array
# $idcol specifies key column for array or "NoCoLuMn" for none
function GetTable2Array($SQL, $db, $idcol="id") {
  $db->tquery($SQL);
  if( $idcol == "NoCoLuMn") {
    while($db->next_record())
      $arr[] = $db->Record;
  } else {
    while($db->next_record())
      $arr[$db->f($idcol)] = $db->Record;
  }    
  return $arr;
}

# function returns two arrays - SliceFields (key is field_id)
#                               Priorities  (field_id sorted by priority
function GetSliceFields($slice_id) {
  global $db;

  $p_slice_id = q_pack_id($slice_id);
  $SQL = "SELECT * FROM field WHERE slice_id='$p_slice_id' ORDER BY input_pri";
  $db->query($SQL);
  while($db->next_record()) {
    $fields[$db->f("id")] = $db->Record;
    $prifields[]=$db->f("id");
  }
  return array($fields, $prifields);
}    

# create field id from type and number
function CreateFieldId ($ftype, $no) {
  if( (string)$no == "0" )
    $no="";    # id for 0 is "xxxxx..........."
  return $ftype. substr("................$no", -(16-strlen($ftype)));
}

# get field type from id
function GetFieldType($id) {
  return substr($id, 0, strpos($id, "."));
}  

# get field number from id ('.', '0', '1', '12', ...)
function GetFieldNo($id) {
  return (string) substr( strrchr($id,'.'), 1 );
}

// -------------------------------------------------------------------------------
/** Basic function to get item content. Use this function, not direct SQL queries.
*/
function GetItemContent($zids, $use_short_ids=false) {
  // Fills array $content with current content of $sel_in items (comma separated ids). 
  global $db;

  if (!is_object ($db)) $db = new DB_AA;

  # construct WHERE clause
  if( $zids and is_array($zids) ) { # Backward compat. array plus flag
    if( $use_short_ids )
      $sel_in = " IN (". implode( ",", $zids). ")";
    else
      $sel_in = " IN (" . implode(",", array_map("qq_pack_id",$zids)). ")";
  } elseif ($zids and is_object($zids)) {
      $use_short_ids = $zids->use_short_ids();
      $sel_in = " IN ("
           . implode( ",", 
            ($use_short_ids ? $zids->shortids() : $zids->qq_packedids())) 
           .")";
      if ($zids->onetype() == "t") $settags = true;  # Used below
  } elseif($zids) {   # Its just one one id, look at the $use_short_ids flag
    if( $use_short_ids )
      $sel_in = "='$zids'";
     else
      $sel_in = "='".q_pack_id($zids)."'";
  } else 
    return false;

  # get content from item table
  $delim = "";
  $id_column = ($use_short_ids ? "short_id" : "id");   
  $SQL = "SELECT * FROM item WHERE $id_column $sel_in";
  $db->tquery($SQL);
  while( $db->next_record() ) {
    reset( $db->Record );
    if( $use_short_ids ) {
      $foo_id = $db->f("short_id");
      $translate[unpack_id128($db->f("id"))] = $db->f("short_id"); # id -> short_id
        # WHERE for query to content table
      $new_sel_in .= "$delim '". quote($db->f("id")) ."'"; 
      $delim = ",";
    } else 
      $foo_id = unpack_id128($db->f("id"));
    # Note that it stores into the $content[] array based on the id being used which 
    # could be either shortid or longid, but is NOT tagged id.
    while( list( $key, $val ) = each( $db->Record )) {
      if( EReg("^[0-9]*$", $key))
        continue;
      $content[$foo_id][substr($key."................",0,16)][] = 
                                                        array("value" => $val);
    }
  }


  # If its a tagged id, then set the "idtag..........." field
  if ($settags) {
    $tags = $zids->gettags();
    while ( list($k,$v) = each($tags)) {
        $content[$k]["idtag..........."][] = array("value" => $v);
    }
  }
  
    # construct WHERE query to content table if used short_ids
  if( $use_short_ids ) {
    if( count($translate)>1 )
      $sel_in = " IN ( $new_sel_in ) ";
     else 
      $sel_in = " = $new_sel_in ";
  }
  
   # get content from content table
   # feeding - don't worry about it - when fed item is updated, informations
   # in content table is updated too

  $SQL = "SELECT * FROM content 
           WHERE item_id $sel_in";  # usable just for constants
               
  $db->tquery($SQL);

  while( $db->next_record() ) {
    $fooid = ( $use_short_ids ? $translate[unpack_id128($db->f(item_id))] : 
                               unpack_id128($db->f(item_id)));
    $content[$fooid][$db->f(field_id)][] = 
      array( "value"=>( ($db->f(text)=="") ? $db->f(number) : $db->f(text)),
             "flag"=> $db->f(flag) );
  }
  return $content;
}  

// -------------------------------------------------------------------------------

function GetHeadlineFieldID($sid, $db, $slice_field="headline.") {
  # get id of headline field  
  $SQL = "SELECT id FROM field 
           WHERE slice_id = '". q_pack_id( $sid ) ."'
             AND id LIKE '$slice_field%'
        ORDER BY id";
  $db->query( $SQL );
  return ( $db->next_record() ? $db->f(id) : false );
}

// -------------------------------------------------------------------------------

# fills array by headlines of items in specified slice (unpacked_id => headline)
# $tagprefix is array as defined in itemfunc.php3
function GetItemHeadlines( $db, $sid="", $slice_field="headline........", 
    $zids="", $type="all", $tagprefix=null) {
    global $debug;
  $psid = q_pack_id( $sid );
  $time_now = time();
  if ($slice_field=="") $slice_field="headline.";

  if ( $sid ) {
    if ( !($headline_fld = GetHeadlineFieldID($sid, $db,$slice_field)) )
      return false;
  } else {
    $headline_fld = 'headline........';
  }  

  # Allow passing an array, this is how is used from show_fnc_freeze_iso and show_fnc_iso
  if (isset($zids) && is_array($zids))
    $zids = new zids($zids); # Don't guess the type, could be l or t

  if( $type == "all" )                          # select all items from slice
    $cond = " AND item.slice_id = '". q_pack_id( $sid ) ."' ";
  elseif (isset($zids) && is_object($zids) && ($zids->count() > 0)) {
    if ($debug) huhl("Getting sql from ",$zids);
    $cond .= ' AND ' . $zids->sqlin();
  }
  else 
    return false;

//  if( $cond == " AND id IN ( '' ) ")
//    return false;
  
  $SQL = "SELECT id, text FROM content, item 
           WHERE item.id=content.item_id
             $cond
             AND field_id = '$headline_fld'
             AND status_code='1'
             AND expiry_date > '$time_now'
             AND publish_date <= '$time_now'
        GROUP BY text
        ORDER BY text";

  $db->tquery($SQL);

  # See if need to Put the tags back on the ids
  if (isset($zids) && is_object($zids) && ($zids->onetype() == 't') && isset($tagprefix)) {
       $tags = $zids->gettags() ;
    while (list(,$v) = each($tagprefix)) {
        $t2p[$v["tag"]] = $v["prefix"];
    }
  } 
  
  while($db->next_record()) {
    $i = unpack_id128($db->f(id));
    $arr[(isset($tags) ? ($tags[$i] . $i) : $i)]
        = ((isset($tags) ? ($t2p[$tags[$i]]) : "")
            . substr($db->f(text), 0, 50));  #truncate long headlines
  }
  if ($debug)  huhl("GetItemHeadlines found ",$arr);
  return $arr;
}

// -------------------------------------------------------------------------------

# fills content arr with specified constant data
function GetConstantContent( $group, $order='pri,name' ) {
  global $db;

  $db->query("SELECT * FROM constant 
               WHERE group_id='$group'
               ORDER BY $order");
  $i=1;               
  while($db->next_record()) {
    $foo_id = unpack_id128($db->f(id));
    $content[$foo_id]["const_name......"][] = array( "value"=> $db->f("name") );
    $content[$foo_id]["const_value....."][] = array( "value"=> $db->f("value"),
                                                     "flag" => FLAG_HTML );
    $content[$foo_id]["const_priority.."][] = array( "value"=> $db->f("pri") );
    $content[$foo_id]["const_group....."][] = array( "value"=> $db->f("group_id") );
    $content[$foo_id]["const_class....."][] = array( "value"=> $db->f("class") );
    $content[$foo_id]["const_counter..."][] = array( "value"=> $i++ );
    $content[$foo_id]["const_id........"][] = array( "value"=> $db->f("id") );
  }  
  return $content;
}  

// -------------------------------------------------------------------------------

# find group_id for constants of the slice
function GetCategoryGroup($slice_id) {
  global $db;
  $SQL = "SELECT input_show_func FROM field
          WHERE slice_id='". q_pack_id($slice_id) ."'
            AND id LIKE 'category%'
          ORDER BY id";  # first should be category........, 
                          # then category.......1, etc.
  $db->query($SQL);
  if( $db->next_record() ){
    $arr = explode( ":", $db->f(input_show_func));
    return $arr[1];
  } else
    return false; 
}    

// -------------------------------------------------------------------------------

# returns field id of field which stores category (usually "category........")
function GetCategoryFieldId( $fields ) {
  $no = 10000;
  if( isset($fields) AND is_array($fields) ) {
    reset( $fields );
    while( list( $k,$val ) = each( $fields ) ) {
      if( substr($val[id], 0, 8) != "category" )
        continue;    
      $last = GetFieldNo($val[id]);
      $no = min( $no, ( ($last=='') ? -1 : (integer)$last) );
    }
  }
  if($no==10000)
    return false;
  $no = ( ($no==-1) ? '.' : (string)$no);
  return CreateFieldId("category", $no);
}  

// -------------------------------------------------------------------------------

# get id from item short id
function GetId4Sid($sid) {
  global $db;
  
  if (!$sid) 
    return false;
  $SQL = "SELECT id FROM item WHERE short_id='$sid'";
  $db->query( $SQL );
  if( $db->next_record() ) 
    return unpack_id128($db->f("id"));
  return false;  
}

// -------------------------------------------------------------------------------

# get short item id item short id
function GetSid4Id($iid) {
  global $db;
  
  if (!$iid) 
    return false;
  $SQL = "SELECT short_id FROM item WHERE id='". q_pack_id($iid) ."'";
  $db->query( $SQL );
  if( $db->next_record() ) 
    return $db->f("short_id");
  return false;  
}

// -------------------------------------------------------------------------------

# in_array and compact is available since PHP4
if (substr(PHP_VERSION, 0, 1) < "4") {
  function in_array($needle,$haystack){
    if (!is_array ($haystack)) return false;
    reset ($haystack);
    while (list (,$val) = each ($haystack))
        if ($val == $needle) 
            return true;
    return false;
  }
}

# Parses the string xxx:yyyy (database stored func) to arr[fce]=xxx [param]=yyyy 
function ParseFnc($s) {
  $pos = strpos($s,":");           
  if( $pos ) {
    $arr[fnc] = substr($s,0,$pos);
    $arr[param] = substr($s,$pos+1);
  } else
    $arr[fnc] = $s;
  return $arr;
}

# returns html safe code (used for preparing variable to print in form)
function safe( $var ) {
  return htmlspecialchars( stripslashes($var) );  // stripslashes function added because of quote varibles sended to form before
}  

// is the browser able to show rich edit box? (using triedit.dll)
function richEditShowable () {
  global $BName, $BVersion, $BPlatform; 
	global $showrich;
	detect_browser();
  # Note that Macintosh IE 5.2 does not support either richedit or current iframe
  # Mac Omniweb/4.1.1 detects as Netscape 4.5 and doesn't support either
  return (($BName == "MSIE" && $BVersion >= "5.0" && $BPlatform != "Macintosh") || $showrich > "");
  # Note that RawRichEditTextarea could force iframe for certain BPlatform
}

function clean_email($line) { 
  // consider using imap_rfc822_parse_adrlist 
  // file://localhost/usr/local/doc/php/function.imap-rfc822-parse-adrlist.html 
  //string ereg_replace (string pattern, string replacement, string string); 

  $patterns = array ('/^\s+/', '/\s+$/'); 
  $replace  = array ('', ''); 
  return preg_replace ($patterns, $replace, $line); 
  /*  $line = ereg_replace ('^\s+','', $line); 
    $line = ereg_replace ('\s+$','',$line); 
    return $line; 
  */ 
}

function GetProfileProperty($property, $id=0) {
  global $r_profile;

  if( isset($r_profile) AND isset($r_profile[$property]) )
    return $r_profile[$property][$id];
  return false;
}
       
/**
* Prints HTML start page tags (html begin, encoding, style sheet, but no title).
* Chooses the right encoding by get_mgettext_lang().
* @param string $stylesheet  if empty, no StyleSheet tag is printed
*/
function HtmlPageBegin ($stylesheet = "default") {
    if ($stylesheet == "default")
        $stylesheet = $GLOBALS["AA_INSTAL_PATH"].ADMIN_CSS;
    echo 
'<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" 
  "http://www.w3.org/TR/html4/loose.dtd">
  <HTML>
    <HEAD>';
    if ($stylesheet) echo '
      <LINK rel="StyleSheet" href="'.$stylesheet.'" type="text/css">';
    echo '
      <META http-equiv="Content-Type" content="text/html; charset='
        .$GLOBALS["LANGUAGE_CHARSETS"][get_mgettext_lang()].'">
';
}  

# Displays page with message and link to $url
#   url - where to go if user clicks on Back link on this message page
#   msg - displayed message
#   dummy - was used in past, now you should use MsgPageMenu from msgpage.php3 
function MsgPage($url, $msg, $dummy="standalone") {   
  HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
  
  echo "<title>"._m("Toolkit news message")."</title>
    </head>
  <body>";

  if( isset($msg) AND is_array($msg))
    PrintArray($msg);
   else 
    echo "<P>$msg</p><br><br>";
  echo "<a href=\"$url\">"._m("Back")."</a>";
  echo "</body></html>";
  page_close();
  exit;
}

# Prints alias names as help for fulltext and compact format page
function PrintAliasHelp($aliases) {
  global $sess;
  echo '
  <tr><td class=tabtit><b>&nbsp;'._m("Use these aliases for database fields").'</b></td></tr>
  <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="'.COLOR_TABBG.'">';
  
  $count = 0;
  while ( list( $ali,$v ) = each( $aliases ) ) {
    # if it is possible point to alias editing page
    $aliasedit = ( !$v["fld"] ? _m("Edit") :
      "<a href=\"". $sess->url(con_url("./se_inputform.php3", 
                    "fid=".urlencode($v["fld"]))) ."\">". _m("Edit") . "</a>");
    echo "<tr><td nowrap>$ali</td><td>". $v[hlp] ."</td><td>$aliasedit</td></tr>";
  }  
   
  echo ' 
  </table></td></tr>';
}
  
# function returns true if $fld fits the field scheme (used in unaliasing)
function IsField($fld) {
  return( (strlen($fld)==16) && (ereg("^[a-z_]+\.+[0-9]*$",$fld)) );
}

# fulltext is viewed - count hit
#TODO: Modify to use zid and then change in ParseViewCommand - mitra
function CountHit($id, $column='id') {
  global $db;
  $where = (( $column == "id" ) ? "id='".q_pack_id($id)."'" : "short_id='$id'");
  $SQL = "UPDATE item 
             SET display_count=(display_count+1) 
          WHERE $where";
  $db->query($SQL);
}  


function is_field_type_numerical ($field_type) {
    $number_db_types = array ("float","double","decimal","int", "timestamp");
    reset ($number_db_types);

    while (list (,$n_col) = each ($number_db_types)) 
        if (strstr ($field_type, $n_col)) 
            return true;
    
    return false;
}

// -----------------------------------------------------------------------------
/** Copies rows within a table changing only given columns and omitting given columns.
*   @author Jakub Adámek
*	@return bool  true if all additions succeed, false otherwise
*    
*   @param string $table    table name
*   @param string $where    where condition (filter)
*   @param array  $set_columns  array ($column_name => $value, ...) - fields the value of which will be changed
*   @param array  $omit_columns [optional] array ($column_name, ...) - fields to be omitted
*   @param array  $id_columns   [optional] array ($column_name, ...) - fields with the 16 byte ID to be generated for each row a new one
*/
function CopyTableRows ($table, $where, $set_columns, $omit_columns = array(), $id_columns = array()) {
    if ($GLOBALS[debug]) {
        echo "CopyTableRows: SELECT * FROM $table WHERE $where<br>
        set_columns = ";
        print_r ($set_columns);
        echo "<br>omit_columns = ";
        print_r ($omit_columns);
        echo "<br>";
    }

    $db = new DB_AA;
    $varset = new CVarset();

    $columns = $db->metadata ($table);

    if ($GLOBALS[debug]) $rows = 0;
        
    $data = GetTable2Array ("SELECT * FROM $table WHERE $where", $db, "NoCoLuMn");
    
    if ($GLOBALS[debug]) { echo "data: "; print_r ($data); echo "<br>"; }
    
    if (!is_array ($data))
        return true;
        
    reset ($data);
    while (list (,$datarow) = each ($data)) {
		$varset->Clear();
        reset ($columns);
        
        // create the varset
        while (list (,$col) = each ($columns)) {
            if (my_in_array ($col["name"], $omit_columns)) 
                continue;

            if (is_field_type_numerical ($col["type"]))
                 $type = "number";
            else $type = "text";
                
            // look into $set_columns
            if (isset ($set_columns[$col["name"]]))
                 $val = $set_columns[$col["name"]];
            else if (my_in_array ($col["name"], $id_columns))
                 $val = q_pack_id(new_id());
            else $val = $datarow[$col["name"]];
            
            $varset->set ($col["name"],$val,$type);
        }
        
        if ($GLOBALS[debug]) { echo "Row $rows<br>"; $rows ++; }
        
        if (!$db->tquery ("INSERT INTO $table ".$varset->makeINSERT()))
			return false;
    }
	return true;
}

// -----------------------------------------------------------------------------

function get_last_insert_id ($db, $table)
{
    $db->tquery ("SELECT LAST_INSERT_ID() AS lid FROM $table");
    $db->next_record();
    return $db->f("lid");
}

// -----------------------------------------------------------------------------

/** returns the suffix part of the filename (beginning with the last dot (.) in the filename) */
function filesuffix ($filename) {
    if (!strstr ($filename,".")) return "";
    $i = strlen($filename);
    while ($filename[$i] != ".") $i --;
    return substr ($filename,$i+1);
}

function filepath ($filename) {
    if (!strstr ($filename,"/")) return "./";
    $i = strlen($filename);
    while ($filename[$i] != "/") $i --;
    return substr ($filename,0,$i+1);
}

function ParseEasyConds (&$conds, $defaultCondsOperator = "LIKE")
{
  if(is_array($conds)) {
    reset($conds); 
    while( list( $k, $cond) = each( $conds )) {
      if( !is_array($cond) ) {
        unset ($conds[$k]);
        continue;             # bad condition - ignore
      }
      if( !isset($cond['value']) && count ($cond) == 1 ) {
        reset ($cond);
        $conds[$k]['value'] = current($cond);
      }
      if( !isset($cond['operator']) )
        $conds[$k]['operator'] = $defaultCondsOperator;

      if (!isset($conds[$k]['value']) OR ($conds[$k]['value']=="")) 
        unset ($conds[$k]);      
    }    
  }
}

function GetTimeZone () {
    $d = getdate ();   
    return (mktime ($d[hours],$d[minutes],$d[seconds],$d[mon],$d[mday],$d[year]) 
    - gmmktime ($d[hours],$d[minutes],$d[seconds],$d[mon],$d[mday],$d[year])) / 3600;
}

/** generates random string of given length (useful as MD5 salt) */
function gensalt($saltlen)
{    
 list($usec, $sec) = explode(' ', microtime());
 //srand ($sec + ((float) $usec * 100000));
 srand((double) microtime() * 1000000);
 $salt_chars = "abcdefghijklmnoprstuvwxBCDFGHJKLMNPQRSTVWXZ0123456589";
 $i=0;
 $salt=""; 
 while ($i<$saltlen) {
     $salt.= substr ($salt_chars, rand (0,strlen($salt_chars)-1), 1);
     $i++; 
 }
 return $salt;
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */
/** Moves uploaded file to given directory and (optionally) changes permissions
*   @return string  error description or empty string */
function aa_move_uploaded_file ($varname, $destdir, $perms = 0, $filename = "") 
{   
    endslash ($destdir);    
    if (!$GLOBALS[$varname]) return "No $varname?";
    if ($filename == "") {
        # get filename and replace bad characters
        $filename = eregi_replace("[^a-z0-9_.~]","_",$GLOBALS[$varname."_name"]);
    }

    if( !is_dir( $destdir )) 
        return _m("Internal error. File upload: Dir does not exist?!");

    if( file_exists("$destdir$filename") )
        return _m("File with this name already exists.") . " $destdir$filename";

    # copy the file from the temp directory to the upload directory, and test for success    

    # file uploads are handled differently in PHP >4.0.3
    list($va,$vb,$vc) = explode(".",phpversion());   # this check work with all possibilities (I hope) -
    if( ($va*10000 + $vb *100 + $vc) >= 40003 ) {    # '4.0.3', '4.1.2-dev', '4.1.14' or '5.23.1'
        if (is_uploaded_file($GLOBALS[$varname])) 
            if( !move_uploaded_file($GLOBALS[$varname], "$destdir$filename")) 
                return _m("Can't upload Image");
            else if ($perms)
                chmod ($destdir.$filename, $perms);
    } 
    else {   # for php 3.x and php <4.0.3
        if (!copy($GLOBALS[$varname],"$destdir$filename")) 
            return _m("Can't upload Image");
        else if ($perms)
            chmod ($destdir.$filename, $perms);
    }  
    return "";
}    

// ---------------------------------------------------------------------------------------------

// like PHP split, but additionally provides $escape_pattern to stand for occurences of $pattern,
// e.g. split_escaped (":", "a#:b:c", "#:") returns array ("a:b","c")

function split_escaped ($pattern, $string, $escape_pattern)
{
    $dummy = "~#$?_";
    if (strstr ($string,$dummy)) { echo "INTERNAL ERROR."; return "INTERNAL ERROR"; }
    $string = str_replace ($escape_pattern,$dummy,$string);
    $strings = split ($pattern, $string);
    reset ($strings);
    while (list ($key,$val) = each ($strings))
        $strings[$key] = str_replace ($dummy, $pattern, $val);
    return $strings;
}
    
function join_escaped ($pattern, $strings, $escape_pattern)
{
    if (!is_array ($strings))
        $strings = array ($strings);
    reset ($strings);
    while (list (,$val) = each ($strings)) {
        if ($retval) $retval .= $pattern;
        $retval .= str_replace ($pattern, $escape_pattern, $val);
    }
    return $retval;
}

function stripslashes_magic ($str)
{
  if( get_magic_quotes_gpc() )
    return stripslashes ($str);
  else return $str;
}    

function addslashes_magic ($str)
{
  if( get_magic_quotes_gpc() )
    return $str;
  else return addslashes ($str);
}    

function isdigit ($c) {
    return $c >= "0" && $c <= "9";
}
function isalpha ($c) {
    return ($c >= "a" && $c <= "z") || ($c >= "A" && $c <= "Z");
}
function isalnum ($c) {
    return ($c >= "0" && $c <= "9") || ($c >= "a" && $c <= "z") || ($c >= "A" && $c <= "Z");
}
function gfd_error ($x) {
    echo "Unrecognized date format charcacter $x";
    exit;
}
    
/*  Returns the Unix timestamp counted from the formatted date string.
    Does not check the date format, rather returns nonsence values for 
    wrong date strings.
    Uses non-format letters as separators only,
    i.e. "2.3.2002" is parsed the same as "2/3/2002" or even "2;3#2002". */
function get_formatted_date ($datestring, $format) {
    // don't work with empty string
    if (!$datestring) return "";
    
    // Split the date into parts consisting only of digits or only of letters
    for ($i = 0; $i < strlen ($datestring); $i++) {
        if (isalpha ($datestring[$i]) && ($s == "" || isalpha($datestring[$i-1])))
            $s .= $datestring[$i];
        else if (isdigit ($datestring[$i]) && ($s == "" || isdigit($datestring[$i-1])))
            $s .= $datestring[$i];
        else if ($s) {
            $dateparts[] = $s;
            $s = "";
        }
    }
    if ($s) $dateparts[] = $s;
    
    // Split the format into parts consisting of one letter
    for ($i = 0; $i < strlen ($format); $i++) {
        if (isalpha ($format[$i]))
            $formatparts[] = $format[$i];
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
 
    if ($use_pm && $pm) $hour += 12;
    
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

function setdefault (&$var, $default) {
    if (!isset ($var)) $var = $default;
}

/** Cooperates with the script post2shtml.php3, which allows to easily post variables
 * to PHP scripts SSI-included in a .shtml page. No parameters necessary. 
 *
 * @author Jakub Adamek, Econnect, December 2002
 */
function add_post2shtml_vars () {
    global $db, $debug, $post2shtml_id;
    
    add_vars();
    if (!$post2shtml_id) return;
    if (!is_object ($db)) $db = new DB_AA;
    $db->query("SELECT * FROM post2shtml WHERE id='$post2shtml_id'");
    $db->next_record();
    $vars = unserialize ($db->f("vars"));
    $var_types = array ("post","get","files","cookie");
    reset ($var_types);
    while (list (,$var_type) = each ($var_types)) {
        if (is_array ($vars[$var_type])) {
            reset ($vars[$var_type]);
            while (list ($var, $value) = each ($vars[$var_type])) {
                global $$var;
                $$var = $value;
                if ($debug) { echo "<b>$var</b> = "; print_r ($value); echo "<br>"; }
            }
        }
    }
}

function delete_post2shtml_vars ($post2sthml_id) {
    global $db;
    $db->query("DELETE FROM post2shtml WHERE id='$post2shtml_id'");
}

/** List of email types with translated description.
    You should never list email types directly, always call this function. */
function get_email_types () {
    return array (
        "alerts welcome" => _m("alerts welcome"),
        "alerts alert" => _m("alerts alert"),
        "alerts access" => _m("alerts single usage access"));
}

/// @return array month names
function monthNames ()
{
    return array( 1 => _m('January'), _m('February'), _m('March'), _m('April'), _m('May'), _m('June'), 
		_m('July'), _m('August'), _m('September'), _m('October'), _m('November'), _m('December'));
}

/** Creates values for a select box showing some param wizard section. */
function getSelectBoxFromParamWizard ($var)
{
    reset ($var["items"]);
    while (list ($value, $prop) = each ($var["items"]))
        $retval[$value] = $prop["name"];
    return $retval;
}
?>