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

require $GLOBALS[AA_INC_PATH]."constants.php3";

function get_aa_url ($href) {
    global $AA_INSTAL_PATH;
    $res = $AA_INSTAL_PATH.$href;
    if (strstr ($href,"?")) $res .= "&"; else $res .= "?";
    $res .= "AA_CP_Session=".$GLOBALS[AA_CP_Session];
    return $res;
}    

function get_admin_url ($href) {
    global $AA_INSTAL_PATH;
    $res = $AA_INSTAL_PATH."admin/".$href;
    if (strstr ($href,"?")) $res .= "&"; else $res .= "?";
    $res .= "AA_CP_Session=".$GLOBALS[AA_CP_Session];
    return $res;
}

// adds slash at the end of a directory name
function endslash (&$s) {
    if (strlen ($s) && substr ($s,-1) != "/")
        $s .= "/";
}

function my_in_array ($needle, $array) {
    if (!is_array ($array)) return false;
    if (function_exists ("in_array"))
        return in_array ($needle, $array);
    else {
        reset ($array);
        while (list (,$val) = each ($array))
            if ($val == $needle) 
                return true;
        return false;
    }
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
  $netscape = (r=="") ? "r=1" : "r=".++$r;   // special parameter for Netscape to reload page
  header("Status: 302 Moved Temporarily");
	header("Location: ". con_url($url,$netscape));
 	exit;
}

function go_url_javascript ($to_go_url) {
	echo "
    <SCRIPT language=JavaScript>
    <!--\n
   		document.location = \"".$sess->url($to_go_url)."\";\n
    // -->\n
    </SCRIPT>";
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

function stripslashes_magic ($str) {
    if (get_magic_quotes_gpc()) 
        return stripslashes($str);
    else return $str;
}

function addslashes_magic ($str) {
    if( get_magic_quotes_gpc() )
        return $str;
    else return addslashes($str);
}

# function for processing posted or get variables
# adds quotes, if magic_quotes are switched off
function QuoteVars($method="get") {
  
  if( get_magic_quotes_gpc() )
    return;
    
  $transfer = ( ($method == "get") ? "HTTP_GET_VARS" : "HTTP_POST_VARS");
  if( !isset($GLOBALS[$transfer]) OR !is_array($GLOBALS[$transfer]))
    return;
  reset( $GLOBALS[$transfer] );
  while( list($varname,$value) = each( $GLOBALS[$transfer] ))
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

# returns packed md5 id, not quoted !!!
function pack_id ($unpacked_id){
  return ((string)$unpacked_id == "0" ? "0" : pack("H*",trim($unpacked_id)));
}

# returns packed and quoted md5 id
function q_pack_id ($unpacked_id){
  $foo = pack_id($unpacked_id);
  return (quote($foo));
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
    { $BName = "MSIE "; $BVersion=$match[2]; }
  elseif(eregi("(opera) ([0-9]{1,2}.[0-9]{1,3}){0,1}",$HTTP_USER_AGENT,$match) || eregi("(opera/)([0-9]{1,2}.[0-9]{1,3}){0,1}",$HTTP_USER_AGENT,$match)) 
    { $BName = "Opera"; $BVersion=$match[2]; }
  elseif(eregi("(konqueror)/([0-9]{1,2}.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Konqueror"; $BVersion=$match[2]; }
  elseif(eregi("(lynx)/([0-9]{1,2}.[0-9]{1,2}.[0-9]{1,2})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Lynx "; $BVersion=$match[2]; }
  elseif(eregi("(links) \(([0-9]{1,2}.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Links "; $BVersion=$match[2]; }
  elseif(eregi("(netscape6)/(6.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Netscape "; $BVersion=$match[2]; }
  elseif(eregi("mozilla/5",$HTTP_USER_AGENT)) 
    { $BName = "Netscape"; $BVersion="Unknown"; }
  elseif(eregi("(mozilla)/([0-9]{1,2}.[0-9]{1,3})",$HTTP_USER_AGENT,$match)) 
    { $BName = "Netscape "; $BVersion=$match[2]; }
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
/*   
  echo $HTTP_USER_AGENT; 
  echo $BName; 
  echo $BVersion; 
  echo $BPlatform; 
*/
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

# fills content arr with current content of $sel_in items (comma separated ids)
function GetItemContent($ids, $use_short_ids=false) {
  global $db;

  # construct WHERE clausule
  if( $ids and is_array($ids) ) {
    if( $use_short_ids )
      $sel_in = " IN (". implode( $ids, "," ). ")";
     else { 
      $sel_in = " IN (";
      $delim = "";
      reset($ids);
      while( list( ,$v) = each ($ids) ) {
        if( $v ) {
          $sel_in .= $delim. "'".q_pack_id($v)."'";
          $delim = ",";
        }  
      }  
      $sel_in .= ( ($delim=="") ? "'')" : ")");
    }  
  } elseif($ids) {
    if( $use_short_ids )
      $sel_in = "='$ids'";
     else
      $sel_in = "='".q_pack_id($ids)."'";
  } else 
    return false;

    # get content from item table
  $delim = "";
  $id_column = ($use_short_ids ? "short_id" : "id");   
  $SQL = "SELECT * FROM item WHERE $id_column $sel_in";
  if( $GLOBALS['debug'] )
    $db->dquery($SQL);
  else
    $db->query($SQL);
  while( $db->next_record() ) {
    reset( $db->Record );
    if( $use_short_ids ) {
      $foo_id = $db->f("short_id");
      $translate[unpack_id($db->f("id"))] = $db->f("short_id"); # id -> short_id
        # WHERE for query to content table
      $new_sel_in .= "$delim '". quote($db->f("id")) ."'"; 
      $delim = ",";
    } else 
      $foo_id = unpack_id($db->f("id"));
    while( list( $key, $val ) = each( $db->Record )) {
      if( EReg("^[0-9]*$", $key))
        continue;
      $content[$foo_id][substr($key."................",0,16)][] = 
                                                        array("value" => $val);
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
               
  if( $GLOBALS['debug'] )
    $db->dquery($SQL);
  else
    $db->query($SQL);

  while( $db->next_record() ) {
    $fooid = ( $use_short_ids ? $translate[unpack_id($db->f(item_id))] : 
                               unpack_id($db->f(item_id)));
    $content[$fooid][$db->f(field_id)][] = 
      array( "value"=>( ($db->f(text)=="") ? $db->f(number) : $db->f(text)),
             "flag"=> $db->f(flag) );
  }
  return $content;
}  

function GetHeadlineFieldID($sid, $db, $slice_field="headline.") {
  # get id of headline field  
  $SQL = "SELECT id FROM field 
           WHERE slice_id = '". q_pack_id( $sid ) ."'
             AND id LIKE '$slice_field%'
        ORDER BY id";
  $db->query( $SQL );
  return ( $db->next_record() ? $db->f(id) : false );
}

# fills array by headlines of items in specified slice (unpacked_id => headline)
function GetItemHeadlines( $db, $sid="", $slice_field="headline........", $ids="", $type="all") {
  $psid = q_pack_id( $sid );
  $time_now = time();
  if ($slice_field=="") $slice_field="headline.";

  if ( $sid ) {
    if ( !($headline_fld = GetHeadlineFieldID($sid, $db,$slice_field)) )
      return false;
  } else {
    $headline_fld = 'headline........';
  }  

  if( $type == "all" )                          # select all items from slice
    $cond = " AND item.slice_id = '". q_pack_id( $sid ) ."' ";
  elseif( !(isset($ids) && is_array($ids)) )
    return false;
  else {  
    $cond = ' AND id IN ( '; 
    reset( $ids );  
    while( list( , $v ) = each( $ids )) {
      $cond .= "$delim'". q_pack_id($v[value]) ."'";
      $delim=',';
    }
    $cond .= ' ) ';
  }
  if( $cond == " AND id IN ( '' ) ")
    return false;
  
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
    
  while($db->next_record())
    $arr[unpack_id($db->f(id))] = substr($db->f(text), 0, 50);  #truncate long headlines
        
  return $arr;
}

# fills content arr with specified constant data
function GetConstantContent( $group, $order='pri,name' ) {
  global $db;

  $db->query("SELECT * FROM constant 
               WHERE group_id='$group'
               ORDER BY $order");
  $i=1;               
  while($db->next_record()) {
    $foo_id = unpack_id($db->f(id));
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

# returns field id of field which stores category (obviously "category........")
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


# get id from item short id
function GetId4Sid($sid) {
  global $db;
  
  if (!$sid) 
    return false;
  $SQL = "SELECT id FROM item WHERE short_id='$sid'";
  $db->query( $SQL );
  if( $db->next_record() ) 
    return unpack_id($db->f("id"));
  return false;  
}

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

# in_array and compact is available since PHP4
if (substr(PHP_VERSION, 0, 1) < "4") {
  function in_array($needle,$haystack){
    for($i=0;$i<count($haystack) && $haystack[$i] !=$needle;$i++);
    return ($i!=count($haystack));
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
  global $BName, $BVersion; 
	global $showrich;
	detect_browser();
  return (($BName == "MSIE " && $BVersion >= "5.0") || $showrich > "");
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
       
# Prints HTML start page tags (html begin, encoding, style sheet, but no title)
function HtmlPageBegin() {
  echo HTML_PAGE_BEGIN;
}  

function HtmlPageEnd() {
  echo "
    </TD></TR></TABLE>
    </TD></TR></TABLE>
    </BODY></HTML>";
}

# Displays page with message and link to $url
#   url - where to go if user clicks on Back link on this message page
#   msg - displayed message
#   dummy - was used in past, now you should use MsgPageMenu from msgpage.php3 
function MsgPage($url, $msg, $dummy="standalone") {   
  HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
  ?>
  <title><?php echo L_MSG_PAGE ?></title>  
  </head>
  <body>

  <?php

  if( isset($msg) AND is_array($msg))
    PrintArray($msg);
   else 
    echo "<P>$msg</p><br><br>";
  echo "<a href=\"$url\">".L_BACK."</a>";
  echo "</body></html>";
  page_close();
  exit;
}

# Prints alias names as help for fulltext and compact format page
function PrintAliasHelp($aliases) {
  global $sess;
  ?>
  <tr><td class=tabtit><b>&nbsp;<?php echo L_CONSTANTS_HLP ?></b></td></tr>
  <tr><td>
  <table width="100%" border="0" cellspacing="0" cellpadding="4" bgcolor="<?php echo COLOR_TABBG ?>">
  <?php
  $count = 0;
  while ( list( $ali,$v ) = each( $aliases ) ) {
    # if it is possible point to alias editing page
    $aliasedit = ( !$v["fld"] ? L_EDIT :
      "<a href=\"". $sess->url(con_url("./se_inputform.php3", 
                    "fid=".urlencode($v["fld"]))) ."\">". L_EDIT . "</a>");
    echo "<tr><td nowrap>$ali</td><td>". $v[hlp] ."</td><td>$aliasedit</td></tr>";
  }  
  ?>  
  </table>
  </td></tr>
  <?php
}
  
/* creates a JavaScript variable modulesOptions, which allows to create another Module selectbox
    without reprinting all the options */

function PrintModuleSelection() {
  global $slice_id, $g_modules, $sess, $PHP_SELF;

  if( is_array($g_modules) AND (count($g_modules) > 1) ) {
  
    // create the modulesOptions content:
    echo "<SCRIPT language=JAVASCRIPT>\n
        <!--\n modulesOptions = ''\n";
    reset($g_modules);
    while(list($k, $v) = each($g_modules)) { 
      echo "\t+'<option value=\"". htmlspecialchars($k)."\"";
      if ( ($slice_id AND (string)$slice_id == (string)$k)) 
        echo " selected";
      echo ">". str_replace("'","`",safe($v['name'])) . "'\n";
    }
    if( !$slice_id )   // new slice
      echo "\t+'<option value=\"new\" selected>". L_NEW_SLICE_HEAD + "'";
    echo ";\n //-->\n </SCRIPT>";

    // print the select box
    echo "<form name=nbform enctype=\"multipart/form-data\" method=post 
                action=\"". $sess->url($PHP_SELF) ."\">
          <span class=nbdisable> &nbsp;". L_SWITCH_TO ."&nbsp; </span>
          <select name=slice_id onChange='document.location=\"" .con_url($sess->url($PHP_SELF),"change_id=").'"+this.options[this.selectedIndex].value\'>'."
          <SCRIPT language=javascript><!--\n
                  document.writeln (modulesOptions);\n
          //-->\n 
          </SCRIPT>
          </select></form>\n";
  } else
    echo "&nbsp;"; 
}  

# function returns true if $fld fits the field scheme (used in unaliasing)
function IsField($fld) {
  return( (strlen($fld)==16) && (ereg("^[a-z_]+\.+[0-9]*$",$fld)) );
}

# fulltext is viewed - count hit
function CountHit($id, $column='id') {
  global $db;
  $where = (( $column == "id" ) ? "id='".q_pack_id($id)."'" : "short_id=$id");
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
/*  function: CopyTableRows
    author:   Jakub Adámek
    purpose:  copies rows within a table changing only given columns and omitting given columns
	returns:  true if all additions succeed, false otherwise
    
    $table .. table name
    $where .. where condition (filter)
    $set_columns .. array ($column_name => $value, ...) - fields the value of which will be changed
    [optionally] $omit_columns .. array ($column_name, ...) - fields to be omitted
    [optionally] $id_columns .. array ($column_name, ...) - fields with the 16 byte ID to be generated for each row a new one
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

/* returns the suffix part of the filename (beginning with the last dot (.) in the filename) */

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

      if (! $conds[$k]['value']) 
        unset ($conds[$k]);      
    }    
  }
}

function GetTimeZone () {
    $d = getdate ();   
    return (mktime ($d[hours],$d[minutes],$d[seconds],$d[mon],$d[mday],$d[year]) 
    - gmmktime ($d[hours],$d[minutes],$d[seconds],$d[mon],$d[mday],$d[year])) / 3600;
}

/*  Function: gensalt
    Purpose:  generates random string of given length (useful as MD5 salt)
*/
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

/*  Function: html2test
    Purpose:  strips the HTML tags and lot more to get a plain text version
*/
function html2text ($html) {
    
    // reverse to htmlentities
    if (function_exists ("get_html_translation_table")) {
        $trans_tbl = get_html_translation_table (HTML_ENTITIES);
	    $trans_tbl = array_flip ($trans_tbl);
        $html = strtr ($html, $trans_tbl);
    }

    // strip HTML tags
    $search = array (
                 "'<br>'si",
                 "'</p>'si",
                 "'<script[^>]*?>.*?</script>'si",  // Strip out javascript
                 "'<[\/\!]*?[^<>]*?>'si",           // Strip out html tags
                 "'([\r\n])[\s]+'",                 // Strip out white space
                 "'&(quot|#34);'i",                 // Replace html entities
                 "'&(amp|#38);'i",
                 "'&(lt|#60);'i",
                 "'&(gt|#62);'i",
                 "'&(nbsp|#160);'i",
                 "'&#(\d+);'e");                    // evaluate as php

    $replace = array (
                  "\n",
                  "\n",
                  "",
                  "",
                  "\\1",
                  "\"",
                  "&",
                  "<",
                  ">",
                  " ",
                  "chr(\\1)");

    return preg_replace ($search, $replace, $html);
}

/*  Function:    mail_html_text
    Author:      Jakub Adámek
    Purpose:     sends safely HTML messages
    Parameters:  same as PHP mail()
                 $additional_headers - use \r\n at the end of each row!
                 $charset - e.g. iso-8859-1, iso-8859-2, windows-1250
                 $use_base64 - set to 0 if you want to pass the message 8 bit encoded
    Description: some e-mail clients don't understand HTML. This function creates a multipart message containing both the HTML and the plain-text version of the message (by leaving out the HTML tags). Each e-mail client displays what it understands better (and hides all the rest of the message). */

function mail_html_text ($to, $subject, $message, $additional_headers = "", $charset = "iso-8859-1", $use_base64 = 1) {
    $boundary = "-------AA-MULTI-".gensalt (20)."------";
    $encoding = $use_base64 ? "base64" : "8bit";
    $textmessage = html2text ($message);
        
    if ($use_base64) {
        $message = base64_encode ($message);
        $textmessage = base64_encode ($message);
    }
       
    $additional_headers .= 
        "MIME-Version: 1.0\r\n"
        ."Content-Type: multipart/alternative;\r\n"
        ." boundary=\"$boundary\"\r\n"
        ."Content-Transfer-Encoding: $encoding\r\n"
        ."\r\n"
        ."--$boundary\r\n"

        ."Content-Type: text/html; charset=\"$charset\"\r\n"
        ."Content-Transfer-Encoding: $encoding\r\n"
        ."\r\n"
        .$message."\r\n"
        ."--$boundary\r\n"

        ."Content-Type: text/plain; charset=\"$charset\"\r\n"
        ."Content-Transfer-Encoding: $encoding\r\n"
        ."\r\n"
        .$textmessage."\r\n"
        ."--$boundary--\r\n";
        
     mail ($to, $subject, "", $additional_headers);
}

/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

# Moves uploaded file to given directory and (optionally) changes permissions
# Returns: error description or empty string

function aa_move_uploaded_file ($varname, $destdir, $perms = 0, $filename = "") 
{   
    endslash ($destdir);    
    if (!$GLOBALS[$varname]) return "No $varname?";
    if ($filename == "") {
        # get filename and replace bad characters
        $filename = eregi_replace("[^a-z0-9_.~]","_",$GLOBALS[$varname."_name"]);
    }

    if( !is_dir( $destdir )) 
        return L_DIR_NOT_EXISTS;

    if( file_exists("$destdir$filename") )
        return L_FILE_NAME_EXISTS . " $destdir$filename";

    # copy the file from the temp directory to the upload directory, and test for success    

    # file uploads are handled differently in PHP >4.0.3
    list($va,$vb,$vc) = explode(".",phpversion());   # this check work with all possibilities (I hope) -
    if( ($va*10000 + $vb *100 + $vc) >= 40003 ) {    # '4.0.3', '4.1.2-dev', '4.1.14' or '5.23.1'
        if (is_uploaded_file($GLOBALS[$varname])) 
            if( !move_uploaded_file($GLOBALS[$varname], "$destdir$filename")) 
                return L_CANT_UPLOAD;
            else if ($perms)
                chmod ($destdir.$filename, $perms);
    } 
    else {   # for php 3.x and php <4.0.3
        if (!copy($GLOBALS[$varname],"$destdir$filename")) 
            return L_CANT_UPLOAD;
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
?>
