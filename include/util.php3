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

if( defined("EXTENDED_ITEM_TABLE") ) {
  $item_fields_text = array("id", "master_id", "slice_id", "category_id", "language_code", "cp_code", "headline", "hl_href", "post_date", "publish_date", "expiry_date", "abstract", "img_src", "source", "source_href", "place", "posted_by", "e_posted_by", "created_by", "edited_by", "last_edit", "contact1", "contact2", "contact3", "edit_note", "img_width", "img_height", "redirect", "source_desc", "source_address", "source_city", "source_prov", "source_country", "start_date", "end_date", "time", "con_name", "con_email", "con_phone", "con_fax", "loc_name", "loc_address", "loc_city", "loc_prov", "loc_country");
  $item_fields_num  = array("status_code", "link_only", "html_formatted", "highlight" );
    // list of fields in packed array of shown fields in itemedit.php3
  $itemedit_fields = array( abstract=>L_ABSTRACT, html_formatted=>L_HTML_FORMATTED, full_text=>L_FULL_TEXT, highlight=>L_HIGHLIGHT, hl_href=>L_HL_HREF, link_only=>L_LINK_ONLY, place=>L_PLACE, source=>L_SOURCE, source_href=>L_SOURCE_HREF, status_code=>L_STATUS_CODE, language_code=>L_LANGUAGE_CODE, cp_code=>L_CP_CODE, category_id=>L_CATEGORY_ID, img_src=>L_IMG_SRC, img_width=>L_IMG_WIDTH, img_height=>L_IMG_HEIGHT, posted_by=>L_POSTED_BY, e_posted_by=>L_E_POSTED_BY, publish_date=>L_PUBLISH_DATE, expiry_date=>L_EXPIRY_DATE, edit_note=>L_EDIT_NOTE, img_upload=>L_IMG_UPLOAD, redirect=>L_REDIRECT, con_name=>L_CON_NAME, con_email=>L_CON_EMAIL, con_phone=>L_CON_PHONE, con_fax=>L_CON_FAX, source_desc=>L_SOURCE_DESC, source_address=>L_SOURCE_ADDRESS, source_city=>L_SOURCE_CITY, source_prov=>L_SOURCE_PROV, source_country=>L_SOURCE_COUNTRY, start_date=>L_START_DATE, end_date=>L_END_DATE, time=>L_TIME, loc_name=>L_LOC_NAME, loc_address=>L_LOC_ADDRESS, loc_city=>L_LOC_CITY, loc_prov=>L_LOC_PROV, loc_country=>L_LOC_COUNTRY );
} else {
    // list of text fields in items table (used in feeding.php3 for inserting into database)
  $item_fields_text = array("id", "master_id", "slice_id", "category_id", "language_code", "cp_code", "headline", "hl_href", "post_date", "publish_date", "expiry_date", "abstract", "img_src", "source", "source_href", "place", "posted_by", "e_posted_by", "created_by", "edited_by", "last_edit", "contact1", "contact2", "contact3", "edit_note", "img_width", "img_height", "redirect");
  $item_fields_num  = array("status_code", "link_only", "html_formatted", "highlight" );
    // list of fields in packed array of shown fields in itemedit.php3
  $itemedit_fields = array( abstract=>L_ABSTRACT, html_formatted=>L_HTML_FORMATTED, full_text=>L_FULL_TEXT, highlight=>L_HIGHLIGHT, hl_href=>L_HL_HREF, link_only=>L_LINK_ONLY, place=>L_PLACE, source=>L_SOURCE, source_href=>L_SOURCE_HREF, status_code=>L_STATUS_CODE, language_code=>L_LANGUAGE_CODE, cp_code=>L_CP_CODE, category_id=>L_CATEGORY_ID, img_src=>L_IMG_SRC, img_width=>L_IMG_WIDTH, img_height=>L_IMG_HEIGHT, posted_by=>L_POSTED_BY, e_posted_by=>L_E_POSTED_BY, publish_date=>L_PUBLISH_DATE, expiry_date=>L_EXPIRY_DATE, edit_note=>L_EDIT_NOTE, img_upload=>L_IMG_UPLOAD, redirect=>L_REDIRECT );
}

  // list of fields in packed array of shown fields in big_srch.php3
$shown_search_fields = array( slice=>L_SRCH_SLICE, category=>L_SRCH_CATEGORY, author=>L_SRCH_AUTHOR, language=>L_SRCH_LANGUAGE, from=>L_SRCH_FROM, to=>L_SRCH_TO, headline=>L_SRCH_HEADLINE, abstract=>L_SRCH_ABSTRACT, full_text=>L_SRCH_FULL_TEXT, edit_note=>L_SRCH_EDIT_NOTE);
  // list of fields in packed array of default values in big_srch.php3
$default_search_in = array( headline=>L_SRCH_HEADLINE, abstract=>L_SRCH_ABSTRACT, full_text=>L_SRCH_FULL_TEXT, edit_note=>L_SRCH_EDIT_NOTE);

  // array of fields displayable in admin interface (index.php3)
  //   field - database field
  //   type - type of database field
  //   title - name of column shown as header to table in admin interface (index.php3)
  //   width - default width of column
  //   name - optional - description of this column in slice setting - parameters page (in not specified title is used)
$af_columns = array ( "id" => array( "field"=>"id", "type"=>"md5", "title"=>L_ID, "width"=>70),
                      "master_id" => array( "field"=>"master_id", "type"=>"md5", "title"=>L_MASTER_ID, "width"=>70),
                      "category_id" => array( "field"=>"category_id", "type"=>"md5", "title"=>L_CATEGORY_ID, "width"=>70),
                      "status_code" => array( "field"=>"status_code", "type"=>"int", "title"=>L_STATUS_CODE, "width"=>24),
                      "language_code" => array( "field"=>"language_code", "type"=>"char", "title"=>L_LANGUAGE_CODE, "width"=>30),
                      "cp_code" => array( "field"=>"cp_code", "type"=>"char", "title"=>L_CP_CODE, "width"=>60),
                      "headline" => array( "field"=>"headline", "type"=>"char", "title"=>L_HEADLINE, "width"=>224, "name"=>L_HEADLINE_EDIT),
                      "headlinepreview" => array( "field"=>"headline", "type"=>"char", "title"=>L_HEADLINE, "width"=>224, "name"=>L_HEADLINE_PREVIEW),
                      "hl_href" => array( "field"=>"hl_href", "type"=>"char", "title"=>L_HL_HREF, "width"=>100),
                      "link_only" => array( "field"=>"link_only", "type"=>"int", "title"=>L_LINK_ONLY, "width"=>24),
                      "post_date" => array( "field"=>"post_date", "type"=>"date", "title"=>L_POSTDATE, "width"=>70),
                      "publish_date" => array( "field"=>"publish_date", "type"=>"date", "title"=>L_PUBLISH_DATE, "width"=>70),
                      "expiry_date" => array( "field"=>"expiry_date", "type"=>"date", "title"=>L_EXPIRY_DATE, "width"=>70),
                      "abstract" => array( "field"=>"abstract", "type"=>"char", "title"=>L_ABSTRACT, "width"=>400),
                      "img_src" => array( "field"=>"img_src", "type"=>"char", "title"=>L_IMG_SRC, "width"=>100),
                      "img_width" => array( "field"=>"img_width", "type"=>"char", "title"=>L_IMG_WIDTH, "width"=>30),
                      "img_height" => array( "field"=>"img_height", "type"=>"char", "title"=>L_IMG_HEIGHT, "width"=>30),
                      "html_formatted" => array( "field"=>"html_formatted", "type"=>"int", "title"=>L_HTML_FORMATTED, "width"=>24),
                      "source" => array( "field"=>"source", "type"=>"char", "title"=>L_SOURCE, "width"=>70),
                      "source_href" => array( "field"=>"source_href", "type"=>"char", "title"=>L_SOURCE_HREF, "width"=>100),
                      "redirect" => array( "field"=>"redirect", "type"=>"char", "title"=>L_REDIRECT, "width"=>100),
                      "place" => array( "field"=>"place", "type"=>"char", "title"=>L_PLACE, "width"=>70),
                      "highlight" => array( "field"=>"highlight", "type"=>"int", "title"=>L_HIGHLIGHTED_HEAD, "width"=>24, "name"=>L_HIGHLIGHT),
                      "posted_by" => array( "field"=>"posted_by", "type"=>"char", "title"=>L_POSTED_BY, "width"=>70),
                      "e_posted_by" => array( "field"=>"e_posted_by", "type"=>"char", "title"=>L_E_POSTED_BY, "width"=>70),
                      "created_by" => array( "field"=>"created_by", "type"=>"char", "title"=>L_CREATED_BY, "width"=>70),
                      "edited_by" => array( "field"=>"edited_by", "type"=>"char", "title"=>L_EDITED_BY, "width"=>70),
                      "last_edit" => array( "field"=>"last_edit", "type"=>"date", "title"=>L_LASTEDIT, "width"=>70),
                      "edit_note" => array( "field"=>"edit_note", "type"=>"char", "title"=>L_EDIT_NOTE, "width"=>70),
                      "catname" => array( "field"=>"categories.name", "type"=>"char", "title"=>L_CATNAME, "width"=>70),
                      "feed" => array( "field"=>false, "type"=>false, "title"=>L_FEEDED_HEAD, "width"=>24),
                      "published" => array( "field"=>false, "type"=>false, "title"=>L_PUBLISHED_HEAD, "width"=>24, "name"=>L_PUBLISHED),
                      "edit" => array( "field"=>false, "type"=>false, "title"=>L_EDIT_LINK, "width"=>30, "name"=>L_EDIT),
                      "preview" => array( "field"=>false, "type"=>false, "title"=>L_PREVIEW_LINK, "width"=>40, "name"=>L_VIEW_FULLTEXT),
                      "chbox" => array( "field"=>false, "type"=>false, "title"=>L_CHBOX_HEAD, "width"=>24, "name"=>L_CHBOX));



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

# returns current date/time in mysql format 
 function now(){ 
  return date ("Y-m-d H:i:s");
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

// in_array is available since PHP4
if (substr(PHP_VERSION, 0, 1) < "4") {
  function in_array($needle,$haystack)
  {
    for($i=0;$i<count($haystack) && $haystack[$i] !=$needle;$i++);
    return ($i!=count($haystack));
  }
}

/*
$Log$
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
