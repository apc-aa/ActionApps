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

if( file_exists( $GLOBALS[AA_INC_PATH]."usr_aliasfnc.php3" ) ) {
  include( $GLOBALS[AA_INC_PATH]."usr_aliasfnc.php3" );
}  

function txt2html($txt) {          #converts plain text to html
  $txt = nl2br(htmlspecialchars($txt));
//  $txt = ERegI_Replace('  ', ' &nbsp;', $txt);
  return $txt;
}  

function DeHtml($txt, $flag) {
  return ( ($flag & FLAG_HTML) ? $txt : htmlspecialchars( $txt ) );
}  

function GetAliasesFromFields($fields, $additional="") {
  if( !( isset($fields) AND is_array($fields)) )
    return false;

  #  Standard aliases
  $aliases["_#ITEM_ID#"] = array("fce" => "f_n:id..............",
                                 "param" => "id..............",
                                 "hlp" => L_ITEM_ID_ALIAS);
  $aliases["_#SITEM_ID"] = array("fce" => "f_h",
                                 "param" => "short_id........",
                                 "hlp" => L_SITEM_ID_ALIAS);
  $aliases["_#EDITITEM"] = array("fce" => "f_e",
                                 "param" => "id..............",
                                 "hlp" => L_EDITITEM_ALIAS);
  $aliases["_#EDITDISC"] = array("fce" => "f_e:disc",
                                 "param" => "id..............",
                                 "hlp" => L_EDITDISC_ALIAS);
  $aliases["_#RSS_TITL"] = array("fce" => "f_r",
                                 "param" => "SLICEtitle",
                                 "hlp" => L_RSS_TITL);
  $aliases["_#RSS_LINK"] = array("fce" => "f_r",
                                 "param" => "SLICElink",
                                 "hlp" => L_RSS_LINK);
  $aliases["_#RSS_DESC"] = array("fce" => "f_r",
                                 "param" => "SLICEdesc",
                                 "hlp" => L_RSS_DESC);
  $aliases["_#RSS_DATE"] = array("fce" => "f_r",
                                 "param" => "SLICEdate",
                                 "hlp" => L_RSS_DATE);

  # database stored aliases
  while( list($k,$val) = each($fields) ) {
    if( $val[alias1] )
      $aliases[$val[alias1]] = array("fce" => $val[alias1_func],
                                     "param" => ( $val[id] ),
                                     "hlp" => $val[alias1_help],
                                     "fld" => $k);                 # fld used
                           # in PrintAliasHelp to point to alias editing page

    if( $val[alias2] )
      $aliases[$val[alias2]] = array("fce" => $val[alias2_func],
                                     "param" => ( $val[id] ),
                                     "hlp" => $val[alias2_help],
                                     "fld" => $k);
    if( $val[alias3] )
      $aliases[$val[alias3]] = array("fce" => $val[alias3_func],
                                     "param" => ( $val[id] ),
                                     "hlp" => $val[alias3_help],
                                     "fld" => $k);
  }

  #add additional aliases
  if( isset( $additional ) AND is_array( $additional ) ) 
    $aliases += $additional;
  
  return($aliases);
}  

function GetConstantAliases( $additional="" ) {
  #  Standard aliases
  $aliases["_#NAME####"] = array("fce" => "f_h",
                                 "param" => "const_name......",
                                 "hlp" => L_CONST_NAME_ALIAS);
  $aliases["_#VALUE###"] = array("fce" => "f_h",
                                 "param" => "const_value.....",
                                 "hlp" => L_CONST_VALUE_ALIAS);
  $aliases["_#PRIORITY"] = array("fce" => "f_h",
                                 "param" => "const_priority..",
                                 "hlp" => L_CONST_PRIORITY_ALIAS);
  $aliases["_#GROUP###"] = array("fce" => "f_n",
                                 "param" => "const_group.....",
                                 "hlp" => L_CONST_GROUP_ALIAS);
  $aliases["_#CLASS###"] = array("fce" => "f_h",
                                 "param" => "const_class.....",
                                 "hlp" => L_CONST_CLASS_ALIAS);
  $aliases["_#COUNTER#"] = array("fce" => "f_h",
                                 "param" => "const_counter...",
                                 "hlp" => L_CONST_COUNTER_ALIAS);
  $aliases["_#CONST_ID"] = array("fce" => "f_n",
                                 "param" => "const_id........",
                                 "hlp" => L_CONST_ID_ALIAS);

  # add additoinal aliases
  if( isset( $additional ) AND is_array( $additional ) ) {
    reset( $additional );
    while( list($k,$v) = each( $additional ) )
      $aliases["_#".$k] = array("fce"=>"f_s:$v", "param"=>"", "hlp"=>"");
  }
  return($aliases);
}  

#explodes $param by ":". The "#:" means true ":" - dont separate
function ParamExplode($param) {
  $a = str_replace ("#:", "__-__.", $param);    # dummy string
  $b = str_replace (":", "##Sx",$a);            # Separation string is ##Sx
  $c = str_replace ("__-__.", ":", $b);         # change "#:" to ":"
  return explode( "##Sx", $c );
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
  
  # get item url - take in mind: item_id, external links and redirection
  function getitemurl($extern, $extern_url, $redirect,$condition=true, $no_sess=false) {
    if( $extern AND $this->columns[$extern][0][value] )       # link_only
      return ($this->columns[$extern_url][0][value] ? 
                $this->columns[$extern_url][0][value] :
                NO_OUTER_LINK_URL);
    if( !$condition )
      return false;
      
    $url_param = ( $GLOBALS['USE_SHORT_URL'] ? 
            "x=".$this->columns["short_id........"][0][value] :
            "sh_itm=".unpack_id($this->columns["id.............."][0][value]));

       # redirecting to another page 
    $url_base = ($redirect ? $redirect : $this->clean_url );

    if( $no_sess ) {                     #remove session id
      $pos = strpos($url_base, '?');
      if($pos)
        $url_base = substr($url_base,0,$pos);
    }    
    return con_url( $url_base, $url_param );
  }    

  # get link from url and text
  function getahref($url, $txt, $add="") { 
    if( $url AND $txt )
      return '<a href="'. $url ."\" $add>". 
                          htmlspecialchars($txt).'</a>';
    return htmlspecialchars($txt); 
  }

  # --------------- functions called for alias substitution -------------------

  # null function
  # param: 0
  function f_0($col, $param="") { return ""; }

  # print due to html flag set (escape html special characters or just print)
  # param: 0
  function f_h($col, $param="") {
    if( $param AND is_array($this->columns[$col])) {  # create list of values for multivalue fields
      reset( $this->columns[$col] );
      while( list( ,$v) = each( $this->columns[$col] ) ) {
        $res .= $delim . DeHtml($v[value], $v[flag]);
        $delim = $param;
      }  
      return $res;
    }    
    return DeHtml($this->columns[$col][0][value], $this->columns[$col][0][flag]);
  }    

  # prints date in user defined format
  # param: date format like in PHP (like "m-d-Y")
  function f_d($col, $param="") {
    if( $param=="" )
      $param = "m/d/Y";
    $dstr = date($param, $this->columns[$col][0][value]);
  	return (($param != "H:i") ? $dstr : ( ($dstr=="00:00") ? "" : $dstr ));
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
    $p = ParamExplode($param);
    if ($this->columns[$col][0][value])
      return DeHtml( $this->columns[$col][0][value], $this->columns[$col][0][flag]);
    return htmlspecialchars(substr($this->columns[ $p[1] ][0][value], 0, $p[0] ) );
  }

  # prints link to fulltext (hedline url)
  # col: hl_href.........
  # param: link_only:redirect
  #    link_only field id (like "link_only.......")
  #    redirect - url of another page which shows the content of item 
  #             - this page should contain SSI include ../slice.php3 too
  #    no_sess  - if true, it does not add session id to url
  function f_f($col, $param="") { 
    $p = ParamExplode($param);
    return $this->getitemurl($p[0], $col, $p[1], 1, $p[2]);
  }    

  # prints text with link to fulltext (hedline url)
  # param: link_only:url_field:redirect:txt:condition_fld
  #    link_only     - field id (like "link_only.......")
  #    url_field     - field id of external url for link_only 
  #                  - (like hl_href.........)
  #    redirect      - url of another page which shows the content of item 
  #                  - this page should contain SSI include ../slice.php3 too
  #    txt           - if txt is field_id content is shown as link, else txt
  #    condition_fld - field id - if no content of this field, no link
  #    addition      - additional parameter to <a tag (like target=_blank)
  #    no_sess  - if true, it does not add session id to url
  function f_b($col, $param="") { 
    list ($link_only, $url_field, $redirect, $txt, $condition, $addition, $no_sess) = ParamExplode($param);
//p_arr_m(  $this->columns );

    # last parameter - condition field
    $url = $this->getitemurl($link_only, $url_field, $redirect, $this->columns[$condition][0][value], $no_sess);
      
    if ( $this->columns[$txt] ) 
  		$txt = $this->columns[$txt][0][value];
               
    return $this->getahref($url,$txt,$addition);
  }    

  # prints 'blurb' (piece of text) based from another slice, 
  # based on a simple condition.

  /*
Blurb slice, has fields
headline........  ; example: "Computer Basics - Technology"
full_text.......  ; example: "What you need to know for this cateogry is ...."
    OR 
title.......     ; "Computer Basics - Overview"
fulltext.....  ; "What you need to know for this cateogry is ...."

In view (of other slices), these blurbs can be gotten by creating a 
  _#BLURB### alias, as a part of the field category........
  _#BLURB### uses function f_q  
*/


# returns fulltext of the blurb
  function f_q($col, $param="")
    {
      /* Usually this is called with no parameters.
	 Optional parameters for f_q are:
	 [0] stringToMatch is by default $col
	 It can be formatted either as the name of a field in self->columns OR 
	 as static text.
	 [1] blurbSliceId  is by default the non-packed id in BLURB_SLICE_ID
	 [2] fieldToMatch  is by default BLURB_FIELD_TO_MATCH
	 [3] fieldToReturn is by default BLURB_FIELD_TO_RETURN
      these constants should be defined in include/config.php3
      */

      $p = ParamExplode($param);
      $stringToMatch_Raw = $p[0] ? $p[0] : $col;
      // can use either the 'headline......' format or "You static text here"
      $stringToMatch = $this->columns[$stringToMatch_Raw][0][value] ? 
	$this->columns[$stringToMatch_Raw][0][value] : $stringToMatch_Raw;

      $p_blurbSliceId  = q_pack_id( $p[1] ? $p[1] : BLURB_SLICE_ID  );
      $fieldToMatch  =   quote(     $p[2] ? $p[2] : BLURB_FIELD_TO_MATCH  );
      $fieldToReturn =   quote(     $p[3] ? $p[3] : BLURB_FIELD_TO_RETURN );
  /*
 This SQL effectively narrows down through three sets:
    a) all the items from our blurb slice 
    (all the item_ids from item where item.slice_id  = $blurb_sliceid_packed)
    b) take set a) and filter to find where the headline (category name)
       matches our category name.
    c) using the single item_id from b), find the content record that has the
       fulltext blurb
*/
  $SQL = "
    SELECT c2.text AS text 
      FROM item LEFT JOIN content c1 ON item.id = c1.item_id 
                LEFT JOIN content c2 ON item.id = c2.item_id
      WHERE slice_id  = '$p_blurbSliceId' AND
           c1.field_id    = '$fieldToMatch' AND
           c2.field_id    = '$fieldToReturn' AND
           c1.text        = '$stringToMatch'

  ";


  //  return $SQL;
  global $db3;
  $db3->query($SQL);
  if ( !$db3->next_record()){ 
    //    return "<br>NO RECORDS!!!!\n\n" . $SQL . "<br>\n\n" ;
    return ""; 
  } else { 
    return $db3->f(text) ;
  };

}


function RSS_restrict($txt, $len) {
    return utf8_encode(htmlspecialchars(substr($txt,0,$len)));
  }  

  # standard aliases to generate RSS .91 compliant meta-information
  function f_r($col, $param="") { 
    global $slice_id, $p_slice_id, $db2;
    static $title, $link, $description; 

    if (! $title) {
      if ($slice_id==""){ echo "Error: slice_id not defined"; exit; }

      // RSS chanel (= slice) info
      $SQL= "SELECT * FROM slice WHERE id='$p_slice_id'";
      $db2->query($SQL);
      if (!$db2->next_record()){ echo "Can't get slice info"; exit;  }

      $title           = $this->RSS_restrict( $db2->f(name), 100);
      $link            = $this->RSS_restrict( $db2->f(slice_url), 500);
      $name            = $db2->f(name);
      $owner           = $db2->f(owner);
      //$language        = RSS_restrict( strtolower($db2->f(lang_file)), 2);

      $SQL= "SELECT name, email FROM slice_owner WHERE id='$owner'";
      $db2->query($SQL);
      if (!$db2->next_record()){ echo "Can't get slice info"; exit;  }
      $description     = $this->RSS_restrict( $db2->f(name).": $name", 500);

    }
    //   return "tt: $col : $param<BR>"; 
    if ($col == 'SLICEdate') 
      return $this->RSS_restrict( GMDate("D, d M Y H:i:s "). "GMT", 100);
    if ($col == 'SLICEtitle') return $title;
    if ($col == 'SLICElink') return $link;
    if ($col == 'SLICEdesc') return $description;

    $p = ParamExplode($param);

    if ($col == 'hl_href.........') {
      if (! $p[1] ) 
   
        $redirect = strtr(AA_INSTAL_URL, ':', '#:') . 
	  "slice.php3?slice_id=$slice_id&encap=false";
      return strtr( $this->f_f($col, $p[0] . ':' . $redirect), '#/', ':/') ;
      }

    if ($this->columns[$col][0][value])
      return $this->RSS_restrict( $this->columns[$col][0][value], $p[0]);
  }

  # converts text to html or escape html (due to html flag)
  # param: 0
  function f_t($col, $param="") { 
    return ( ($this->columns[$col][0][flag] & FLAG_HTML) ? 
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
    $p = ParamExplode($param);
    return $this->getahref($this->columns[$p[0]][0][value], 
                           $this->columns[$col][0][value],$p[1]);
  }

  # _#ITEMEDIT used on admin page index.php3 for itemedit url
  # param: 0
  function f_e($col, $param="") { 
    global $sess;
    if( $param == "disc" )
      # _#DISCEDIT used on admin page index.php3 for edit discussion comments
      return con_url($sess->url("discedit.php3"),
        "item_id=".unpack_id( $this->columns["id.............."][0][value]));
    return con_url($sess->url("itemedit.php3"),
                   "encap=false&edit=1&id=".
                   unpack_id( $this->columns["id.............."][0][value]));
  }                 

  # prints "begin".$col."end" if $col="condition", else prints "none"
  # if no cond_col specified - $col is used
  # param: condition:begin:end:none:cond_col
  # if pararam begins with "!", condition is negated
  function f_c($col, $param="") { 
    if( $param[0]=="!" ){
      $param = substr($param, 1);
      $negate=true;
    }  
    
    $p = ParamExplode($param);
    $cond = ( $p[4] ? $p[4] : $col );
    if( $this->columns[$cond][0][value] != $p[0] )
      $negate = !$negate;
    return  ($negate ? $p[3] : $p[1]. DeHtml($this->columns[$col][0][value], $this->columns[$col][0][flag]) .$p[2]); 
  }
  
  # calls user defined function in file /include/usr_aliasfnc.php3
  function f_u($col, $param="") { 
    $p = ParamExplode($param);
    $fnc = $p[0];
    return $fnc($this->columns, $col, $param);
  }  

  # display specified view
  # param: 
  #    link_only     - field id (like "link_only.......")
  #    url_field     - field id of external url for link_only 
  #                  - (like hl_href.........)
  #    redirect      - url of another page which shows the content of item 
  #                  - this page should contain SSI include ../slice.php3 too
  #    txt           - if txt is field_id content is shown as link, else txt
  #    condition_fld - field id - if no content of this field, no link
  #    addition      - additional parameter to <a tag (like target=_blank)


  function f_v($col, $param="") { 
    global $vid, $als, $conds, $param_conds, $item_ids, $use_short_ids;

    # if no parameter specified, the content of this field specifies view id
    if( !$param )
      $param = "vid=".$this->columns[$col][0][value]; 
    
    # substitute aliases by real item content
    $part = $param;
    while( $part = strstr( $part, "_#" )) {  # aliases for field content
      $fid = substr( $part, 2, 16 );         # looks like _#headline........
      
      if( substr( $fid, 0, 4 ) == "this" )   # special alias _#this
        $param = str_replace( "_#this", $this->f_h($col, "-"), $param );
      else
        $param = str_replace( "_#$fid", $this->f_h($col, "-"), $param );
      $part = substr( $part, 6 );
    }  
    
    return GetView(ParseViewParameters($param));
  }    

  # mailto link
  # prints: "begin<a href="mailto:$col">field/text</a>"
  # if no $col is filled, prints "else_fileld/text"
  # param: begin:field/text:else_fileld/text
  # linktype: mailto/href (default is mailto)
  function f_m($col, $param="") { 
    $p = ParamExplode($param);
    if( !$this->columns[$col][0][value] ) {
      $txt = ($this->columns[$p[2]] ? $this->columns[$p[2]][0][value] : $p[2]);
      return ( $txt ? $p[0].$txt : "" );
    }  
    if( $this->columns[$p[1]] )
      $txt = ($this->columns[$p[1]][0][value] ? 
             $this->columns[$p[1]][0][value] : $this->columns[$col][0][value]);
     else 
      $txt = ( $p[1] ? $p[1] : $this->columns[$col][0][value]);
    $linktype =  ($p[3] ? "" : "mailto:");
    return $p[0].$this->getahref( $linktype.$this->columns[$col][0][value], $txt);
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

        if( $contents[$als_name] != "")   # remove empty aliases
          $out .= "_##".current($piece);  # one cross more to not replace 
        else                           # strings with "_#", which is not aliases
          $out .= substr(current($piece),8);
      }
      else
        $out .= "_#".current($piece);
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
    $piece = explode("_##",$out);
//p_arr($piece,"2. explode");

    unset($out);
    reset($piece);
    if( substr(current($piece),0,3) != "_##" ) {   // skip to first alias
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
Revision 1.29  2001/11/26 11:07:30  honzam
No session add option for itemlink in alias

Revision 1.28  2001/10/24 18:44:10  honzam
new parameter wizard for function aliases and input type parameters

Revision 1.27  2001/10/24 16:46:24  honzam
fixed bug with fourth parameter to f_c

Revision 1.26  2001/10/17 21:53:46  honzam
fixed bug in url passed aliases

Revision 1.25  2001/10/08 16:42:52  honzam
f_m alias function now works with normal links too

Revision 1.24  2001/09/27 15:59:33  honzam
New discussion support, New constant view, Aliases for view and mail

Revision 1.23  2001/09/12 06:19:00  madebeer
Added ability to generate RSS views.
Added f_q to item.php3, to grab 'blurbs' from another slice using aliases

Revision 1.22  2001/07/09 17:46:40  honzam
user alias function - better parameters parsing

Revision 1.21  2001/07/09 09:28:44  honzam
New supported User defined alias functions in include/usr_aliasfnc.php3 file

Revision 1.20  2001/06/21 14:15:44  honzam
feeding improved - field value redefine possibility in se_mapping.php3

Revision 1.19  2001/06/15 21:17:40  honzam
fixed bug in manual feeding, fulltext f_b alias function improved

Revision 1.18  2001/06/15 20:05:16  honzam
little search imrovements and bugfixes

Revision 1.17  2001/06/15 15:27:58  honzam
improved f_h alias function for displaying multiple values

Revision 1.16  2001/06/14 13:03:12  honzam
better time handling in inputform and view

Revision 1.15  2001/06/13 11:31:28  honzam
added negation in condition alias function f_c (and fixed bug of reverse meaning of condition)

Revision 1.14  2001/06/03 16:00:49  honzam
multiple categories (multiple values at all) for item now works

Revision 1.13  2001/05/18 13:55:04  honzam
New View feature, new and improved search function (QueryIDs)

Revision 1.12  2001/05/10 10:01:43  honzam
New spanish language files, removed <form enctype parameter where not needed, better number validation

Revision 1.11  2001/04/17 21:32:08  honzam
New conditional alias. Fixed bug of not displayed top/bottom HTML code in fulltext and category

Revision 1.10  2001/03/20 16:10:37  honzam
Standardized content management for items - filler, itemedit, offline, feeding
Better feeding support

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
