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

// Shift to another page (must be before any output from script)
function go_url($url, $add_param="") {
  if( $add_param != "" )
    $url = con_url( $url, rawurlencode($add_param));
  $netscape = (r=="") ? "r=1" : "r=".++$r;   // special parameter for Natscape to reload page
  header("Status: 302 Moved Temporarily");
	header("Location: ". con_url($url,$netscape));
 	exit;
}

# returns server name with protocol and port
function self_server() {
  global $SERVER_NAME, $HTTPS, $SERVER_PORT;
  if( isset($HTTPS) && $HTTPS == 'on' ){
    $PROTOCOL='https';
    if($SERVER_PORT != "443")
      $port = ":$SERVER_PORT";
  } else {
    $PROTOCOL='http';
	  if($SERVER_PORT != "80")
      $port = ":$SERVER_PORT";
  }
  return("$PROTOCOL://$SERVER_NAME$port");
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

# skips terminating backslashes
function DeBackslash($txt) {
	return str_replace('\\', "", $txt);        // better for two places
}   
 
// adds variables passesd by QUERY_STRING_UNESCAPED to GLOBALS 
function add_vars($debug="") {
  global $QUERY_STRING_UNESCAPED, $REDIRECT_QUERY_STRING_UNESCAPED;
  if (isset($REDIRECT_QUERY_STRING_UNESCAPED)) {
    $varstring = $REDIRECT_QUERY_STRING_UNESCAPED;
  } else {  
    $varstring = $QUERY_STRING_UNESCAPED;
  }  

  $a = explode("&",$varstring);
  $i = 0;

  while ($i < count ($a)) {
    $b = explode ('=', $a [$i]);
    if (ERegI("^(.+)\[(.*)\]", $b[0], $c)) {  // for array variable[]
      $index1 = urldecode (DeBackslash($c[2]));
      $value  = urldecode (DeBackslash($b[1]));
      if (ERegI("^(.+)\[(.*)\]", $c[1], $d)) { // for double array variable[][]
        $index2  = urldecode (DeBackslash($d[2]));
        $varname = urldecode (DeBackslash($d[1]));  
      } else 
        $varname  = urldecode (DeBackslash($c[1]));  
      if( isset($index2) ) 
        $GLOBALS[$varname][$index2][$index1] = $value;
       else 
        $GLOBALS[$varname][$index1] = $value;
    } else {
      $b[0] = DeBackslash($b[0]);
      $b[1] = DeBackslash($b[1]);
      if($b[2])
        $b[2] = "=". DeBackslash($b[2]);       // for cases variable contains "="
      if($b[3])
        $b[3] = "=". DeBackslash($b[3]);       // for cases variable contains "="
      $GLOBALS[urldecode ($b [0])]= urldecode ($b [1].$b[2].$b[3]);
    }
    $i++;
  }
  return $i;
}


# function to double backslashes and apostrofs 
function quote($str) {
  return addslashes($str);  
} 
 
# function for processing posted or getteg variables
# adds quotes, if magic_quotes are switched off
function QuoteVars($method="get") {
  
  if( get_magic_quotes_gpc() )
    return;
    
  $transfer = ( ($method == "get") ? "HTTP_GET_VARS" 
                                  : "HTTP_POST_VARS");
  if( !isset($GLOBALS[$transfer]) OR !is_array($GLOBALS[$transfer]))
    return;
  reset( $GLOBALS[$transfer] );
  while( list($varname,$value) = each( $GLOBALS[$transfer] ))
    $GLOBALS[$varname] = addslashes($value);
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
 if (ereg("\?",$Url,$Regs))return $Url."&".$Params;
 else return $Url."?".$Params;
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
 
# debug function for printing debug messages
function huh($msg) {
  if(! DEBUG_FLAG )
    return;
  echo "<br>\n$msg";
}  

# debug function for printing debug messages escaping HTML
function huhw($msg) {
  if(! DEBUG_FLAG )
    return;
  echo "<br>\n". HTMLspecialChars($msg);
}  

#Prints all values from array
function PrintArray($a){
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

# Prints HTML start page tags (html begin, encoding, style sheet, but no title)
function HtmlPageBegin() {
  echo HTML_PAGE_BEGIN;
}  

# Displays page with message and link to $url
#   url - where to go if user clicks on Back link on this message page
#   msg - displayed message
#   mode - items/admin/standalone for surrounding of message
function MsgPage($url, $msg, $mode="standalone") {
  global $sess, $auth, $slice_id;

  if( !isset($sess) AND ($mode!="standalone")) {
    require $GLOBALS[AA_INC_PATH] . "locauth.php3";
    page_open(array("sess" => "AA_CP_Session", "auth" => "AA_CP_Auth"));
  }
    
  HtmlPageBegin();   // Print HTML start page tags (html begin, encoding, style sheet, but no title)
  ?>
  <title><?php echo L_MSG_PAGE ?></title>  
  </head>
  <body>

  <?php

  switch( $mode ) {
    case "items":    // Message page on main page (index.php3) or such page
      include $GLOBALS[AA_INC_PATH] . "navbar.php3";
      include $GLOBALS[AA_INC_PATH] . "leftbar.php3";
      break;
    case "admin":    // Message page on admin pages (se_*.php3) or such page
      include $GLOBALS[AA_INC_PATH] . "navbar.php3";
      include $GLOBALS[AA_INC_PATH] . "leftbar_se.php3";
      break;
  }    

  if( isset($msg) AND is_array($msg))
    PrintArray($msg);
   else 
    echo "<P>$msg</p><br><br>";
  echo "<a href=\"$url\">".L_BACK."</a>";
  echo "</body></html>";
  page_close();
  exit;
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
function GetConstants($group, $db) {
  $db->query("SELECT name, value FROM constant 
               WHERE group_id='$group'
               ORDER BY pri");
  while($db->next_record())
    $arr[$db->f(value)] = $db->f(name);
  return $arr;
}     

# gets slice fields
function GetSliceInfo($slice_id) {
  global $db;
  $p_slice_id = q_pack_id($slice_id);
  $db->query("SELECT * FROM slice WHERE id='$p_slice_id'");
  return  ($db->next_record() ? $db->Record : false);
}  

# function converts table from SQL query to array
# $idcol specifies key column for array or "NoCoLuMn" for none
function GetTable2Array($SQL, $db, $idcol="id") {
  $db->query($SQL);
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
function GetItemContent($ids) {
  global $db;

  if( $ids and is_array($ids) ) {
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
  } elseif($ids) {
    $sel_in = "='".q_pack_id($ids)."'";
  } else 
    return false;

    # get content from item table
  $SQL = "SELECT * FROM item WHERE id $sel_in";
  $db->query($SQL);
  while( $db->next_record() ) {
    reset( $db->Record );
    $foo_id = unpack_id($db->f(id));
    while( list( $key, $val ) = each( $db->Record )) {
      if( EReg("^[0-9]*$", $key))
        continue;
      $content[$foo_id][substr($key."................",0,16)][] = 
                                                        array("value" => $val);
    }  
  }  

   # get content from content table
   # feeding - don't worry about it - when fed item is updated, informations
   # in content table is updated too

  $db->query("SELECT * FROM content 
               WHERE item_id $sel_in");  # usable just for constants
  while( $db->next_record() ) {
    $content[unpack_id($db->f(item_id))][$db->f(field_id)][] = 
      array( "value"=>( ($db->f(text)=="") ? $db->f(number) : $db->f(text)),
             "flag"=> $db->f(flag) );
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
  if( $db->next_record() )
    return substr( strchr($db->f(input_show_func),':'),1);
   else
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

# returns html safe code (used for preparing variable to print in form)
function safe( $var ) {
  return htmlspecialchars( stripslashes($var) );  // stripslashes function added because of quote varibles sended to form before
}  


/*
$Log$
Revision 1.21  2001/05/23 23:08:24  honzam
Arrays passed to SSIed script by URL can be two-dimensional, now

Revision 1.20  2001/05/18 13:55:04  honzam
New View feature, new and improved search function (QueryIDs)

Revision 1.19  2001/03/30 11:54:35  honzam
offline filling bug and others small bugs fixed

Revision 1.18  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

Revision 1.17  2001/03/06 00:15:14  honzam
Feeding support, color profiles, radiobutton bug fixed, ...

Revision 1.16  2001/02/26 17:22:30  honzam
color profiles, itemmanager interface changes

Revision 1.15  2001/01/23 23:58:03  honzam
Aliases setings support, bug in permissions fixed (can't login not super user), help texts for aliases page

Revision 1.14  2001/01/22 17:32:49  honzam
pagecache, logs, bugfixes (see CHANGES from v1.5.2 to v1.5.3)

Revision 1.13  2001/01/10 15:49:16  honzam
Fixed problem with unpack_id (No content Error on index.php3)

Revision 1.12  2001/01/08 13:31:58  honzam
Small bugfixes

Revision 1.11  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.10  2000/11/13 10:36:07  honzam
Fixed problem with bad minutes in date() function

Revision 1.9  2000/10/10 18:28:00  honzam
Support for Web.net's extended item table

Revision 1.7  2000/08/23 12:29:58  honzam
fixed security problem with inc parameter to slice.php3

Revision 1.6  2000/08/17 15:17:55  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.5  2000/08/07 15:52:13  kzajicek
in_array moved to util.php3 and defined optionally

Revision 1.4  2000/08/03 12:31:19  honzam
Session variable r_hidden used instead of HIDDEN html tag. Magic quoting of posted variables if magic_quotes_gpc is off.

Revision 1.3  2000/07/12 11:06:26  kzajicek
names of image upload variables were a bit confusing

Revision 1.2  2000/07/07 21:36:04  honzam
Redirection to the same page now support Netscape (in go_url)

Revision 1.1.1.1  2000/06/21 18:40:50  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:27  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.11  2000/06/12 19:58:37  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.10  2000/06/09 15:14:12  honzama
New configurable admin interface

Revision 1.9  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.8  2000/03/29 15:54:47  honzama
Better Netscape Navigator javascript support, new direct feeding support, minor changes in texts and look.

Revision 1.7  2000/03/22 09:38:40  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>
