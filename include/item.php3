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


define("ITEM_PHP3_INC",1);

# aliases used in fulltext and compact format in place of database fields
# change only text after _# !!!
# length of alias must! be!! 10 characters !!!
# if you change it, change compact and fulltext format strings in database, too !
# do not use _# inside alias !

/*
$aliases["_#HEADLINE"] = array("fce"=>"f_h", "param"=>"headline", "hlp" => L_HLP_HEADLINE);
$aliases["_#CATEGORY"] = array("fce"=>"f_h", "param"=>"category", "hlp" => L_HLP_CATEGORY);
$aliases["_#HDLN_URL"] = array("fce"=>"f_f", "param"=>"", "hlp" => L_HLP_HDLN_URL);
$aliases["_#POSTDATE"] = array("fce"=>"f_d", "param"=>"post_date", "hlp" => L_HLP_POSTDATE);
$aliases["_#PUB_DATE"] = array("fce"=>"f_d", "param"=>"publish_date", "hlp" => L_HLP_PUB_DATE);
$aliases["_#EXP_DATE"] = array("fce"=>"f_d", "param"=>"expiry_date", "hlp" => L_HLP_EXP_DATE);
$aliases["_#ABSTRACT"] = array("fce"=>"f_a", "param"=>"", "hlp" => L_HLP_ABSTRACT);
$aliases["_#FULLTEXT"] = array("fce"=>"f_t", "param"=>"", "hlp" => L_HLP_FULLTEXT);
$aliases["_#IMAGESRC"] = array("fce"=>"f_i", "param"=>"", "hlp" => L_HLP_IMAGESRC);
$aliases["_#SOURCE##"] = array("fce"=>"f_h", "param"=>"source", "hlp" => L_HLP_SOURCE);
$aliases["_#SRC_URL#"] = array("fce"=>"f_s", "param"=>"", "hlp" => L_HLP_SRC_URL);
$aliases["_#LINK_SRC"] = array("fce"=>"f_l", "param"=>"", "hlp" => L_HLP_LINK_SRC);
$aliases["_#PLACE###"] = array("fce"=>"f_h", "param"=>"place", "hlp" => L_HLP_PLACE);
$aliases["_#POSTEDBY"] = array("fce"=>"f_h", "param"=>"posted_by", "hlp" => L_HLP_POSTEDBY);
$aliases["_#E_POSTED"] = array("fce"=>"f_h", "param"=>"e_posted_by", "hlp" => L_HLP_E_POSTED);
$aliases["_#CREATED#"] = array("fce"=>"f_h", "param"=>"created_by", "hlp" => L_HLP_CREATED);
$aliases["_#EDITEDBY"] = array("fce"=>"f_h", "param"=>"edited_by", "hlp" => L_HLP_EDITEDBY);
$aliases["_#LASTEDIT"] = array("fce"=>"f_d", "param"=>"last_edit", "hlp" => L_HLP_LASTEDIT);
$aliases["_#EDITNOTE"] = array("fce"=>"f_h", "param"=>"edit_note", "hlp" => L_HLP_EDITNOTE);
$aliases["_#IMGWIDTH"] = array("fce"=>"f_w", "param"=>"", "hlp" => L_HLP_IMGWIDTH);
$aliases["_#IMG_HGHT"] = array("fce"=>"f_g", "param"=>"", "hlp" => L_HLP_IMG_HGHT);
$aliases["_#ITEM_ID#"] = array("fce"=>"f_n", "param"=>"id", "hlp" => L_HLP_ITEM_ID);
$aliases["_#CATEG_ID"] = array("fce"=>"f_n", "param"=>"category_id", "hlp" => L_HLP_CATEGORY_ID);

if( defined("EXTENDED_ITEM_TABLE") ) {
  $aliases["_#SRC_DEST"] = array("fce"=>"f_h", "param"=>"source_desc", "hlp" => L_HLP_SOURCE_DESC);
  $aliases["_#SRC_ADDR"] = array("fce"=>"f_h", "param"=>"source_address", "hlp" => L_HLP_SOURCE_ADDRESS);
  $aliases["_#SRC_CITY"] = array("fce"=>"f_h", "param"=>"source_city", "hlp" => L_HLP_SOURCE_CITY);
  $aliases["_#SRC_PROV"] = array("fce"=>"f_h", "param"=>"source_prov", "hlp" => L_HLP_SOURCE_PROV);
  $aliases["_#SRC_CNTR"] = array("fce"=>"f_h", "param"=>"source_country", "hlp" => L_HLP_SOURCE_COUNTRY);
  $aliases["_#STR_DATE"] = array("fce"=>"f_h", "param"=>"start_date", "hlp" => L_HLP_START_DATE);
  $aliases["_#END_DATE"] = array("fce"=>"f_h", "param"=>"end_date", "hlp" => L_HLP_END_DATE);
  $aliases["_#TIME####"] = array("fce"=>"f_h", "param"=>"time", "hlp" => L_HLP_TIME);
  $aliases["_#CON_NAME"] = array("fce"=>"f_h", "param"=>"con_name", "hlp" => L_HLP_CON_NAME);
  $aliases["_#CON_MAIL"] = array("fce"=>"f_h", "param"=>"con_email", "hlp" => L_HLP_CON_EMAIL);
  $aliases["_#CON_TEL#"] = array("fce"=>"f_h", "param"=>"con_phone", "hlp" => L_HLP_CON_PHONE);
  $aliases["_#CON_FAX#"] = array("fce"=>"f_h", "param"=>"con_fax", "hlp" => L_HLP_CON_FAX);
  $aliases["_#LOC_NAME"] = array("fce"=>"f_h", "param"=>"loc_name", "hlp" => L_HLP_LOC_NAME);
  $aliases["_#LOC_ADDR"] = array("fce"=>"f_h", "param"=>"loc_address", "hlp" => L_HLP_LOC_ADDRESS);
  $aliases["_#LOC_CITY"] = array("fce"=>"f_h", "param"=>"loc_city", "hlp" => L_HLP_LOC_CITY);
  $aliases["_#LOC_PROV"] = array("fce"=>"f_h", "param"=>"loc_prov", "hlp" => L_HLP_LOC_PROV);
  $aliases["_#LOC_CNTR"] = array("fce"=>"f_h", "param"=>"loc_country", "hlp" => L_HLP_LOC_COUNTRY);
}
*/

function txt2html($txt) {          #converts plain text to html
  $txt = nl2br(htmlspecialchars($txt));
//  $txt = ERegI_Replace('  ', ' &nbsp;', $txt);
  return $txt;
}  

function GetAliasesFromFields($fields) {
  if( !( isset($fields) AND is_array($fields)) )
    return false;

  #  Standard aliases
  $aliases["_#ITEM_ID#"] = array("fce" => "f_n:id",
                                 "param" => "id",
                                 "hlp" => L_ITEM_ID_ALIAS);
  $aliases["_#EDITITEM"] = array("fce" => "f_e",
                                 "param" => "id",
                                 "hlp" => L_EDITITEM_ALIAS);

  # database stored aliases
  while( list( ,$val) = each($fields) ) {
    if( $val[alias1] )
      $aliases[$val[alias1]] = array("fce" => $val[alias1_func],
                                     "param" => ( ($val[in_item_tbl] != "") ? 
                                                      $val[in_item_tbl] :
                                                      $val[id] ),
                                     "hlp" => $val[alias1_help]);
    if( $val[alias2] )
      $aliases[$val[alias2]] = array("fce" => $val[alias2_func],
                                     "param" => ( ($val[in_item_tbl] != "") ? 
                                                      $val[in_item_tbl] :
                                                      $val[id] ),
                                     "hlp" => $val[alias2_help]);
    if( $val[alias3] )
      $aliases[$val[alias3]] = array("fce" => $val[alias3_func],
                                     "param" => ( ($val[in_item_tbl] != "") ? 
                                                      $val[in_item_tbl] :
                                                      $val[id] ),
                                     "hlp" => $val[alias3_help]);
  }                                   
  return($aliases);
}  


class item {    
  var $item_content;   # asociative array with names of columns and values from item table
  var $columns;        # asociative array with names of columns and values of current row 
  var $clean_url;      # 
  var $top;
  var $format;         # format string with aliases 
  var $bottom;
  var $grab_len;              
  var $remove;         # remove string
  var $aliases;        # array of usable aliases              
  
  
  function item($ic, $cols, $ali, $c, $ff, $gl, $fr="", $top="", $bottom=""){   #constructor 
    $this->item_content = $ic;
    $this->columns = $cols;
    $this->aliases = $ali;
    $this->clean_url = $c;
    $this->format = $ff;
    $this->grab_len = $gl;
    $this->remove = $fr;
    $this->top = $top;
    $this->bottom = $bottom;
  }
  
  function setformat( $format, $remove="", $top="", $bottom="") {
    $this->format = $format;
    $this->remove = $remove;
    $this->top = $top;
    $this->bottom = $bottom;
  }
  
  // functions called for alias substitution
  function f_h($col) { return htmlspecialchars($this->columns[$col][0][value]); }
  function f_x($col) { return $this->columns[$col][0][value]; }
  function f_d($col) { return sec2userdate($this->columns[$col][0][value]); }  #can be used use $format in sec2userdate
  function f_i($col) { return ( $this->columns["img_src........."][0][value] ? $this->columns["img_src........."][0][value] : NO_PICTURE_URL); }
  function f_n($col) { return unpack_id( $this->columns[$col][0][value] ); }
  function f_g($col) { 
    global $out;
    if( !$this->columns["img_height......"][0][value] ) {
      $out = ERegI_Replace( "height[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete height = x
      return false;
    }
    return htmlspecialchars($this->columns["img_height......"][0][value]);
  }
  function f_w($col) { 
    global $out;
    if( !$this->columns["img_width......."][0][value] ) {
      $out = ERegI_Replace( "width[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete width = x
      return false;
    }
    return htmlspecialchars($this->columns["img_width......."][0][value]);
  }
  function f_a($col)     {            // returns abstract or grabed fulltext
    if ($this->columns["abstract........"][0][value])
      return htmlspecialchars($this->columns["abstract........"][0][value]);
    return htmlspecialchars(substr($this->columns["full_text......."][0][value],0,$this->grab_len));
  }
  function f_f($col) { 
    if( $this->columns["link_only......."][0][value] )
      return ($this->columns["hl_href........."][0][value] ? 
                $this->columns["hl_href........."][0][value] :
                NO_OUTER_LINK_URL);
    if( $this->columns["redirect........"][0][value] )  // redirecting to another page (should contain SSI include ../slice.php3 too)
      return con_url($this->columns["redirect........"][0][value],"sh_itm=".unpack_id($this->columns["id"][0][value]));
     else 
      return con_url($this->clean_url,"sh_itm=".unpack_id($this->columns["id"][0][value]));
  }    
  function f_t($col) { 
    return ( ($this->columns["full_text......."][0][flag] & 2) ? 
      $this->columns["full_text......."][0][value] : txt2html($this->columns["full_text......."][0][value]) );
  }
  function f_s($col) { return ( $this->columns["source_href....."][0][value] ? $this->columns["source_href....."][0][value] : NO_SOURCE_URL); }
  function f_l($col) { 
    if( $this->columns["source_href....."][0][value] AND $this->columns["source.........."][0][value] )
      return '<a href="'. htmlspecialchars($this->columns["source_href....."][0][value]) .'">'.
              htmlspecialchars($this->columns["source.........."][0][value]).'</a>';
    return htmlspecialchars($this->columns["source.........."][0][value]); 
  }
  function f_e($col) { // _#ITEMEDIT used on admin page index.php3 for itemedit url
    global $sess;
    return con_url($sess->url("itemedit.php3"),
                   "encap=false&edit=1&id=".
                   unpack_id( $this->columns["id"][0][value]));
  }                 
  
  // function shows full text navigation (back, home)
  function show_navigation($home_url) {
    echo '<br><a href="javascript:history.back()">'. L_BACK .'</a> &nbsp; ';
    echo "<a href=\"$home_url\">". L_HOME .'</a><br>';
  }  

  function print_item() {
  // format string

    $out = $this->format;
    $remove = $this->remove;
    $out = $this->unalias($out, $remove);
    echo $out;
  }  

  function unalias($out, $remove_string="") {
    $piece = explode("_#",$out);
    if( !is_array($piece))
      $piece = array($out);
      
    unset($out);
    reset($piece);
    if( substr(current($piece),0,2) != "_#" ) {   // skip to first alias
      $out = current($piece);
      next($piece);
    }
    while(current($piece)) {
      $ali_arr = $this->aliases["_#".($als_name=substr(current($piece),0,8))];
      if( is_array($ali_arr)) {
        $function = ParseFnc($ali_arr[fce]);   // fce as parameter (pointer to function)
        $fce = $function[fnc];
        $contents[$als_name] = $this->$fce($ali_arr[param]);
        if( $contents[$als_name] != "")  // remove empty aliases
          $out .= "_#".current($piece);
        else 
          $out .= substr(current($piece),8);
      }
      else
        $out .= current($piece);
      next($piece);
    }    
    
//huh("ooo$remove_string");
    
    $remove = explode("##", $remove_string); // huhw("nove<BR>$out");
    if( is_array($remove) ) {
      reset($remove);
      while( current($remove) ) {
        $out = str_replace(current($remove), "", $out); 
//huhw(current($remove).":..$out..");
        next($remove);
      }
    }    
   
// remove ()
    $piece = explode("_#",$out);
//p_arr($piece,"2. explode");

    unset($out);
    reset($piece);
    if( substr(current($piece),0,2) != "_#" ) {   // skip to first alias
      $out = current($piece);
      next($piece);
    }
    while(current($piece)) {
      $out .= $contents[substr(current($piece),0,8)];
      $out .= substr(current($piece),8);
      next($piece);
    }    

    return $out;
  }
};

/*
$Log$
Revision 1.6  2000/12/23 19:56:50  honzam
Multiple fulltext item view on one page, bugfixes from merge v1.2.3 to v1.5.2

Revision 1.5  2000/12/21 16:39:34  honzam
New data structure and many changes due to version 1.5.x

Revision 1.4  2000/10/10 18:28:00  honzam
Support for Web.net's extended item table

Revision 1.3  2000/08/17 15:17:55  honzam
new possibility to redirect item displaying (for database changes see CHANGES)

Revision 1.2  2000/07/03 15:00:14  honzam
Five table admin interface. 'New slice expiry date bug' fixed.

Revision 1.1.1.1  2000/06/21 18:40:40  madebeer
reimport tree , 2nd try - code works, tricky to install

Revision 1.1.1.1  2000/06/12 21:50:24  madebeer
Initial upload.  Code works, tricky to install. Copyright, GPL notice there.

Revision 1.15  2000/06/12 19:58:36  madebeer
Added copyright (APC) notice to all .inc and .php3 files that have an $Id

Revision 1.14  2000/06/09 15:14:11  honzama
New configurable admin interface

Revision 1.13  2000/05/30 09:11:39  honzama
MySQL permissions upadted and completed.

Revision 1.12  2000/04/24 16:50:34  honzama
New usermanagement interface.

Revision 1.11  2000/03/22 09:38:39  madebeer
perm_mysql improvements
Id and Log added to all .php3 and .inc files
system for config-ecn.inc and config-igc.inc both called from
config.inc

*/
?>