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
  var $remove;         # remove string
  var $aliases;        # array of usable aliases              
  
  
  function item($ic, $cols, $ali, $c, $ff, $gl, $fr="", $top="", $bottom=""){   #constructor 
    $this->item_content = $ic;
    $this->columns = $cols;
    $this->aliases = $ali;
    $this->clean_url = $c;
    $this->format = $ff;
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
  
  # --------------- functions called for alias substitution -------------------

  # null function
  # param: 0
  function f_0($col, $param="") { return ""; }

  # print due to html flag set (escape html special characters or just print)
  # param: 0
  function f_h($col, $param="") { 
    return ( ($this->columns[$col][0][flag] & 2) ? 
      $this->columns[$col][0][value] : 
      htmlspecialchars( $this->columns[$col][0][value] ) );
  }    

  # prints date in user defined format
  # param: date format like in PHP (like "m-d-Y")
  function f_d($col, $param="") {
    if( $param=="" )
      $param = "m/d/Y";
  	return date($param, $this->columns[$col][0][value]);
  }

  # prints image scr (<img src=...) - NO_PICTURE for none
  # param: 0
  function f_i($col, $param="") { 
    return ( $this->columns[$col][0][value] ?
      $this->columns[$col][0][value] : 
      NO_PICTURE_URL);
  }

  # prints unpacked id
  # param: 0
  function f_n($col, $param="") {
    return unpack_id( $this->columns[$col][0][value] ); 
  }

  # prints image height atribut (<img height=...) or clears it
  # param: 0
  function f_g($col, $param="") {    # image height
    global $out;
    if( !$this->columns[$col][0][value] ) {
      $out = ERegI_Replace( "height[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete height = x
      return false;
    }
    return htmlspecialchars($this->columns[$col][0][value]);
  }

  # prints image width atribut (<img width=...) or clears it
  # param: 0
  function f_w($col, $param="") {    # image width
    global $out;
    if( !$this->columns[$col][0][value] ) {
      $out = ERegI_Replace( "width[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete width = x
      return false;
    }
    return htmlspecialchars($this->columns[$col][0][value]);
  }

  # prints abstract or grabed fulltext text field
  # param: length:field_id
  #    length - number of characters taken from field_id (like "80:full_text.......")
  function f_a($col, $param="") {
    $p = explode(":",$param);
    if ($this->columns[$col][0][value])
      return htmlspecialchars($this->columns[$col][0][value]);
    return htmlspecialchars(substr($this->columns[ $p[1] ][0][value], 0, $p[0] ) );
  }

  # prints link to fulltext (hedline url)
  # col: hl_href.........
  # param: link_only:redirect
  #    link_only field id (like "link_only.......")
  #    redirect - url of another page which shows the content of item 
  #             - this page should contain SSI include ../slice.php3 too
  function f_f($col, $param="") { 
    $p = explode(":",$param);
    if( $p[0] AND $this->columns[ $p[0]][0][value] )       # link_only
      return ($this->columns[$col][0][value] ? 
                $this->columns[$col][0][value] :
                NO_OUTER_LINK_URL);
    if( $p[1] )      # redirecting to another page 
      return con_url( $p[1], "sh_itm=".unpack_id($this->columns["id"][0][value]));
     else 
      return con_url( $this->clean_url,          # show on this page
                      "sh_itm=".unpack_id($this->columns["id"][0][value]));
  }    

  # converts text to html or escape html (due to html flag)
  # param: 0
  function f_t($col, $param="") { 
    return ( ($this->columns[$col][0][flag] & 2) ? 
      $this->columns[$col][0][value] : txt2html($this->columns[$col][0][value]) );
  }

  # print database field or default value if empty
  # param: default (like "javascript: window.alert('No source url specified')")
  function f_s($col, $param="") { 
    return ( $this->columns[$col][0][value] ? 
             $this->columns[$col][0][value] : $param); }

  # prints $col as link, if field_id in $param is defined, else prints just $col
  # param: field_id of possible link (like "source_href.....")
  function f_l($col, $param="") { 
    if( $this->columns[$param][0][value] AND $this->columns[$col][0][value] )
      return '<a href="'. htmlspecialchars($this->columns[$param][0][value]) .'">'.
              htmlspecialchars($this->columns[$col][0][value]).'</a>';
    return htmlspecialchars($this->columns[$col][0][value]); 
  }

  # _#ITEMEDIT used on admin page index.php3 for itemedit url
  # param: 0
  function f_e($col, $param="") { 
    global $sess;
    return con_url($sess->url("itemedit.php3"),
                   "encap=false&edit=1&id=".
                   unpack_id( $this->columns["id"][0][value]));
  }                 

  # ----------------- alias function definition end --------------------------
  
  // function shows full text navigation (back, home)
  function show_navigation($home_url) {
    echo '<br><a href="javascript:history.back()">'. L_BACK .'</a> &nbsp; ';
    echo "<a href=\"$home_url\">". L_HOME .'</a><br>';
  }  

  function get_item() {
  // format string

    $out = $this->format;
    $remove = $this->remove;
    $out = $this->unalias($out, $remove);
    return $out;
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
        #search for alias definition (fce,param,hlp)
      $ali_arr = $this->aliases["_#".($als_name=substr(current($piece),0,8))];

        #is this realy alias?
      if( is_array($ali_arr)) {
          # get from "f_d:mm-hh" array fnc="f_d", param="mm-hh"
        $function = ParseFnc($ali_arr[fce]);
        $fce = $function[fnc];

          # call function (called by function reference (pointer))
          # like f_d("start_date......", "mm-dd")
        $contents[$als_name] = $this->$fce($ali_arr[param], $function[param]);

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
Revision 1.9  2001/02/20 13:25:16  honzam
Better search functions, bugfix on show on alias, constant definitions ...

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