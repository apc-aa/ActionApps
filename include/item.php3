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

function txt2html($txt) {          #converts plain text to html
  $txt = nl2br(htmlspecialchars($txt));
//  $txt = ERegI_Replace('  ', ' &nbsp;', $txt);
  return $txt;
}  

class item {    
  var $columns;               # asociative array with names of columns and values of  current row 
  var $full;                  # fulltext view/compact view    
  var $odd;
  var $clean_url;
  var $fulltext_format;
  var $odd_row_format;
  var $even_row_format;
  var $category_format;
  var $grab_len;
  
  function item($cols,$f,$o,$c,$ff,$orf,$erf,$cf,$gl,$cr,$fr){                   #constructor 
   $this->columns = $cols;
   $this->full = $f;
   $this->odd = $o;
   $this->clean_url = $c;
   $this->fulltext_format = $ff;
   $this->even_row_format = $erf;
   $this->odd_row_format = $orf;
   $this->category_format = $cf;
   $this->grab_len = $gl;
   $this->compact_remove = $cr;
   $this->fulltext_remove = $fr;
  }
  
  // functions called for alias substitution
  function f_h($col) { return htmlspecialchars($this->columns[$col]); }
  function f_x($col) { return $this->columns[$col]; }
  function f_d($col) { return datetime2date($this->columns[$col]); }
  function f_i($col) { return ( $this->columns["img_src"] ? $this->columns["img_src"] : NO_PICTURE_URL); }
  function f_n($col) { return unpack_id( $this->columns[$col] ); }
  function f_g($col) { 
    global $out;
    if( !$this->columns["img_height"] ) {
      $out = ERegI_Replace( "height[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete height = x
      return false;
    }
    return htmlspecialchars($this->columns["img_height"]);
  }
  function f_w($col) { 
    global $out;
    if( !$this->columns["img_width"] ) {
      $out = ERegI_Replace( "width[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete width = x
      return false;
    }
    return htmlspecialchars($this->columns["img_width"]);
  }
  function f_a($col)     {            // returns abstract or grabed fulltext
    if ($this->columns["abstract"])
      return htmlspecialchars($this->columns["abstract"]);
    return htmlspecialchars(substr($this->columns["full_text"],0,$this->grab_len));
  }
  function f_f($col) { 
    if( $this->columns["link_only"] )
      return ($this->columns["hl_href"] ? $this->columns["hl_href"] : NO_OUTER_LINK_URL);
    return con_url($this->clean_url,"sh_itm=".unpack_id($this->columns["id"]));
  }    
  function f_t($col) { 
    return ( ($this->columns["html_formatted"]==0) ? 
      txt2html($this->columns["full_text"]) : $this->columns["full_text"]);
  }    
  function f_s($col) { return ( $this->columns["source_href"] ? $this->columns["source_href"] : NO_SOURCE_URL); }
  function f_l($col) { 
    if( $this->columns["source_href"] AND $this->columns["source"] )
      return '<a href="'. htmlspecialchars($this->columns["source_href"]) .'">'.
              htmlspecialchars($this->columns["source"]).'</a>';
    return htmlspecialchars($this->columns["source"]); 
  }
                         
  // function shows full text navigation (back, home)
  function show_navigation($home_url) {
    echo '<br><a href="javascript:history.back()">'. L_BACK .'</a> &nbsp; ';
    echo "<a href=\"$home_url\">". L_HOME .'</a><br>';
  }  

  function print_item() {
  // format string
    if( $this->full) {
      $out = $this->fulltext_format;
      $remove = $this->fulltext_remove;
     } else {
      $out = ((!$this->odd AND $this->even_row_format) ? $this->even_row_format : $this->odd_row_format);
      $remove = $this->compact_remove;
    }  
    $out = $this->unalias($out, $remove);
    echo $out;
  }  

  function print_category() {
    $out = $this->unalias($this->category_format);
    echo $out;
  }  

  function unalias($out, $remove_string="") {
    global $alias2column, $aliases, $debugtimes;

//$debugtimes[] = "In: unalias".microtime();

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
      $ali_arr = $aliases["_#".($als_name=substr(current($piece),0,8))];
      if( is_array($ali_arr)) {
        $fce = $ali_arr[fce];   // fce as parameter (pointer to function)
//huh($ali_arr[param]);
        $contents[$als_name] = $this->$fce($ali_arr[param]);
//p_arr_m($contents);
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


# Prints alias names as help for fulltext and compact format page
function PrintAliasHelp() {
  global $aliases;
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