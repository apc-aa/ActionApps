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

// Shift to another page (must be before any output fro script)
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

# debug function, prints hash size,  keys and values of hash  
function p_arr($a,$name="given array") {
  if(! DEBUG_FLAG )
    return;
  echo "Values of $name . Size is ".sizeof($a)."<br>";
  while ( list( $key, $val ) = each( $a ) ) {
    echo htmlspecialchars($key). " => ".htmlspecialchars($val)."<br>";
  }
}

# prints content of a (multidimensional) array
function p_arr_m ($arr, $level = 0) {
  if(! DEBUG_FLAG )
    return;
   if (sizeof($arr) == 0) { 
     for ($i = 0; $i < $level; $i++) { echo "&nbsp;&nbsp;&nbsp;"; };
         echo htmlspecialchars($key) . " (Empty Array) <br>";
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

# returns new unpacked md5 unique id, except these which can  force unexpected end of string  
function new_id ($seed="hugo"){
  do {
   $foo=md5(uniqid($seed));
  } while (ereg("(00|27)",$foo));  // 00 is end of string, 27 is '
  return $foo;
} 

# returns packed md5 id, not quoted !!!
function pack_id ($unpacked_id){
  return ((string)$unpacked_id == "0" ? "0" : pack("H*",$unpacked_id));
}

# returns packed and quoted md5 id
function q_pack_id ($unpacked_id){
  $foo = pack_id($unpacked_id);
  return (quote($foo));
} 
  
# returns unpacked md5 id
function unpack_id ($packed_id){
  if( (string)$packed_id == "0" )
    return "0";
  $foo=unpack("H*",$packed_id);
  return $foo[""];
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
function MsgPage($url, $msg, $mode="items") {
  global $sess, $auth;

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

# get field type from id
function GetFieldType($id) {
  return substr($id, 0, strpos($id, "."));
}  

# in_array is available since PHP4
if (substr(PHP_VERSION, 0, 1) < "4") {
  function in_array($needle,$haystack)
  {
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
  ?>
  <tr><td class=tabtit><b>&nbsp;<?php echo L_CONSTANTS_HLP ?></b></td></tr>
  <tr><td>
  <table width="440" border="0" cellspacing="0" cellpadding="4" bgcolor="#EBDABE">
  <?php
  $count = 0;
  while ( list( $ali,$v ) = each( $aliases ) ) 
    echo "<tr><td nowrap>$ali</td><td>".$v[hlp]."</td></tr>";
//    echo "<tr><td nowrap>ali</td><td>"."v[hlp]"."</td></tr>";
  ?>  
  </table>
  </td></tr>
  <?php
}  


/*
$Log$
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
