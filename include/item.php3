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

if( file_exists( $GLOBALS["AA_INC_PATH"]."usr_aliasfnc.php3" ) ) {
  include( $GLOBALS["AA_INC_PATH"]."usr_aliasfnc.php3" );
}

require_once $GLOBALS["AA_INC_PATH"]."math.php3";
require_once $GLOBALS["AA_INC_PATH"]."stringexpand.php3";

function txt2html($txt) {          // converts plain text to html
  return nl2br(preg_replace('/&amp;#(\d+);/',"&#\\1;",htmlspecialchars($txt)));
                                   // preg allows text to be pasted from Word
                                   // displays qoutes instead of &8221;
}

function DeHtml($txt, $flag) {
  return ( ($flag & FLAG_HTML) ? $txt : txt2html($txt) );
}

function GetAliasesFromFields($fields, $additional="", $type='') {
  trace("+","GetAliasesFromFields");
  if( !( isset($fields) AND is_array($fields)) AND ($type != 'justids') ) {
    trace("-");
    return false;
  }
  #add additional aliases
  if( is_array( $additional ) ) {
      reset ($additional);
      while (list($k,$v) = each($additional))
          $aliases[$k] = $v;
  }

  #  Standard aliases
  $aliases["_#ID_COUNT"] = array("fce" => "f_e:itemcount",
                                 "param" => "id..............",
                                 "hlp" => _m("number of found items"));
  $aliases["_#ITEMINDX"] = array("fce" => "f_e:itemindex",
                                 "param" => "id..............",
                                 "hlp" => _m("index of item within view"));
  $aliases["_#ITEM_ID_"] = array("fce" => "f_n:id..............",
                                 "param" => "id..............",
                                 "hlp" => _m("alias for Item ID"));
  $aliases["_#SITEM_ID"] = array("fce" => "f_h",
                                 "param" => "short_id........",
                                 "hlp" => _m("alias for Short Item ID"));

  if( $type == 'justids') {  // it is enough for view of urls
      trace("-");
      return $aliases;
  }
                                 
  $aliases["_#EDITITEM"] = array("fce" => "f_e",
                                 "param" => "id..............",
                                 "hlp" => _m("alias used on admin page index.php3 for itemedit url"));
  $aliases["_#ADD_ITEM"] = array("fce" => "f_e:add",
                                 "param" => "id..............",
                                 "hlp" => _m("alias used on admin page index.php3 for itemedit url"));
  $aliases["_#EDITDISC"] = array("fce" => "f_e:disc",
                                 "param" => "id..............",
                                 "hlp" => _m("Alias used on admin page index.php3 for edit discussion url"));
  $aliases["_#RSS_TITL"] = array("fce" => "f_r",
                                 "param" => "SLICEtitle",
                                 "hlp" => _m("Title of Slice for RSS"));
  $aliases["_#RSS_LINK"] = array("fce" => "f_r",
                                 "param" => "SLICElink",
                                 "hlp" => _m("Link to the Slice for RSS"));
  $aliases["_#RSS_DESC"] = array("fce" => "f_r",
                                 "param" => "SLICEdesc",
                                 "hlp" => _m("Short description (owner and name) of slice for RSS"));
  $aliases["_#RSS_DATE"] = array("fce" => "f_r",
                                 "param" => "SLICEdate",
                                 "hlp" => _m("Date RSS information is generated, in RSS date format"));
  $aliases["_#SLI_NAME"] = array("fce" => "f_e:slice_info",
                                 "param" => "name",
                                 "hlp" => _m("Slice name"));

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
  trace("-");
  return($aliases);
}

function GetConstantAliases( $additional="" ) {
  #  Standard aliases
  $aliases["_#NAME###_"] = array("fce" => "f_h",
                                 "param" => "const_name......",
                                 "hlp" => _m("Constant name"));
  $aliases["_#VALUE##_"] = array("fce" => "f_h",
                                 "param" => "const_value.....",
                                 "hlp" => _m("Constant value"));
  $aliases["_#PRIORITY"] = array("fce" => "f_h",
                                 "param" => "const_priority..",
                                 "hlp" => _m("Constant priority"));
  $aliases["_#GROUP##_"] = array("fce" => "f_n",
                                 "param" => "const_group.....",
                                 "hlp" => _m("Constant group id"));
  $aliases["_#CLASS##_"]= array("fce" => "f_h",
                                 "param" => "const_class.....",
                                 "hlp" => _m("Category class (for categories only)"));
  $aliases["_#COUNTER_"] = array("fce" => "f_h",
                                 "param" => "const_counter...",
                                 "hlp" => _m("Constant number"));
  $aliases["_#CONST_ID"] = array("fce" => "f_n",
                                 "param" => "const_id........",
                                 "hlp" => _m("Constant unique id (32-haxadecimal characters)"));
  $aliases["_#SHORT_ID"] = array("fce" => "f_t",
                                 "param" => "const_short_id..",
                                 "hlp" => _m("Constant unique short id (autoincremented from '1' for each constant in the system)"));
  $aliases["_#DESCRIPT"] = array("fce" => "f_t",
                                 "param" => "const_descr.....",
                                 "hlp" => _m("Constant description"));
  $aliases["_#LEVEL##_"] = array("fce" => "f_t",
                                 "param" => "const_level.....",
                                 "hlp" => _m("Constant level (used for hierachical constants)"));

  # add additoinal aliases
  if( isset( $additional ) AND is_array( $additional ) ) {
    reset( $additional );
    while( list($k,$v) = each( $additional ) )
      $aliases["_#".$k] = array("fce"=>"f_s:$v", "param"=>"", "hlp"=>"");
  }
  return($aliases);
}

# helper function for f_e
# this is called from admin/index.php3 and include/usr_aliasfnc.php3 in some site
# added by setu@gwtech.org 2002-0211
//
// make_return_url
# global function to get return_url
# this function may replaced by extension of $sess as a method $sess->return_url().
function sess_return_url($url) {
  global $sess;
  global $return_url;

  if (!$return_url)   # return for standard APC-AA behavier
    return $sess->url($url);
  else                # decode and return $return_url
    return expand_return_url(1);
}


# helper function for f_e
# this is called from admin/index.php3 and include/usr_aliasfnc.php3 in some site
# added by setu@gwtech.org 2002-0211
//
// make_return_url
//function make_return_url($prifix="&return_url=")
function make_return_url($prifix,$r1="") {
  // prifix will be "&return_url=" or "?return_url=",
  // if null, it uses "&return_url="
  if (!$prifix) $prifix = "&return_url=";
 
  global $return_url, $REQUEST_URI, $sess;
  if ($r1) 
	return $prifix . urlencode($r1);
  elseif ($return_url)
	return $prifix . urlencode($return_url);
  elseif (!$sess) {   # If there is no $sess, then we need a return url, default to self, including parameters
			# but remove any left over AA_CP_Session, it will be re-added if needed
        if (ereg("(.*)([?&])AA_CP_Session=[0-9a-f]{32}(.*)",$REQUEST_URI,$parts))
          return $prifix . urlencode($parts[1].$parts[2].$parts[3]);
        return($prifix . urlencode($REQUEST_URI));
  }
  else 
	return "";
}

# helper function for f_e:link_*  (links module admin page link) - honzam
function Links_admin_url($script, $add) {
    global $sess, $AA_INSTAL_EDIT_PATH;
    return $sess->url($AA_INSTAL_EDIT_PATH. "modules/link/$script?$add");
}

class item {    
  var $item_content;   # Jakub: dummy, unused parameter??!!
  var $columns;        # ItemContent array for this Item (like from GetItemContent)
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

  function getval($column, $what='value') {
      if ( is_array($this->columns[$column]) )
           return $this->columns[$column][0][$what];
      else return false;
  }  
  
  # get item url - take in mind: item_id, external links and redirection
  function getitemurl($extern, $extern_url, $redirect, $condition=true, $no_sess=false) {
    if( $extern )       # link_only
      return ($extern_url ? $extern_url : NO_OUTER_LINK_URL);
    if( !$condition )
      return false;
      
    $url_param = ( $GLOBALS['USE_SHORT_URL'] ? 
            "x=".$this->getval('short_id........') :
            "sh_itm=".unpack_id128($this->getval('id..............')));

       # redirecting to another page 
    $url_base = ($redirect ? $redirect : $this->clean_url );

       # add state variable, if defined (apc - AA Pointer Cache)
    if( $GLOBALS['apc_state'] )
      $url_param .= '&apc='.$GLOBALS['apc_state']['state'];

    if( $no_sess ) {                     #remove session id
      $pos = strpos($url_base, '?');
      if($pos)
        $url_base = substr($url_base,0,$pos);
    }
    return con_url( $url_base, $url_param );
  }

  # get link from url and text
  function getahref($url, $txt, $add="", $html=false) {
    if( $url AND $txt ) {
      # repair url if user omits to write http://
      if( substr($url,4)=='www.' )
        $url = 'http://'.$url;
      return '<a href="'. $url ."\" $add>". DeHtml($txt, $html).'</a>';
    }
    return DeHtml($txt,$html);
  }

  function get_alias_subst( $alias ) {
    $ali_arr = $this->aliases[$alias];
      # is this realy alias?

    if( !is_array($ali_arr) ) {
      # try alternative alias (old form of _#ITEM_ID_ alias was _#ITEM_ID#. It was bad for
      # unaliasing with colon ':', so we change it, but for compatibility we have to test _#ITEM_ID# too)
      if( ! ((substr($alias,9,1)=='#') AND is_array($ali_arr = $this->aliases[substr($alias,0,9).'_'])))
        return $alias;
    }

    # get from "f_d:mm-hh" array fnc="f_d", param="mm-hh"
    $function = ParseFnc($ali_arr['fce']);
    $fce = $function['fnc'];

    # call function (called by function reference (pointer))
    # like f_d("start_date......", "mm-dd")
    return $this->$fce($ali_arr['param'], $function['param']);
  }

  function remove_strings( $text, $remove_arr ) {
    if( is_array($remove_arr) ) {
      reset($remove_arr);
      while( current($remove_arr) ) {
        $text = str_replace(current($remove_arr), "", $text); 
        next($remove_arr);
      }
    }
    return $text;
  }      

  # the function substitutes all _#... aliases and then aplies "remove strings"
  # it searches for removal just in parts where all aliases are expanded
  # to empty string
  function substitute_alias_and_remove( $text, $remove_arr=null ) {
    $piece = explode( "_#", $text );
    reset( $piece );
    $out = current($piece);   # initial sequence
    while( $vparam = next($piece) ) {
          #search for alias definition (fce,param,hlp)
      $substitution = $this->get_alias_subst( "_#".(substr($vparam,0,8)));
      if( $substitution != "" ) {   # alias produced some output, so we can remove
                              # strings in previous section and we can start new
                              # section
        $clear_output .= $this->remove_strings($out,$remove_arr).$substitution;
        $out = substr($vparam,8);         # start with clear string
      } else
        $out .= substr($vparam,8);
    }
    return $clear_output . $this->remove_strings($out,$remove_arr);
  }

  function unalias( &$text, $remove="" ) {
    trace("+","unalias",htmlentities($text));
    // just create variables and set initial values
    $maxlevel = 0;   
    $level = 0;
#   return $this->old_unalias_recurent( $text, $remove, $level, $maxlevel );
    $ret = new_unalias_recurent($text, $remove, $level, $maxlevel, $this ); # Note no itemview param
    trace("-");
    return $ret;
  }

  function subst_alias( $text ) {
	if (IsField($text)) 
		return $this->getval($text);
	else return  $this->unalias( $text );
  }  
  
  function subst_aliases( $var ) {
    if( !is_array( $var ) )
      return $this->subst_alias( $var );
    reset( $var );
    while( list($k,$v) = each($var) )
      $ret[$k] = $this->subst_alias( $v );
    return $ret;
  }

  # --------------- functions called for alias substitution -------------------

  # null function
  # param: 0
  function f_0($col, $param="") { return ""; }

  # print due to html flag set (escape html special characters or just print)
  # param: delimeter - used to separate values if the field is multi
  function f_h($col, $param="") {
    if( $param AND is_array($this->columns[$col])) {  # create list of values for multivalue fields
      $param = $this->subst_alias( $param );
      reset( $this->columns[$col] );
      while( list( ,$v) = each( $this->columns[$col] ) ) {
        $res .= $delim . DeHtml($v[value], $v[flag]);
        if( $res )
          $delim = $param;        # add value separator just if field is filled
      }  
      return $res;
    }
    return DeHtml($this->columns[$col][0][value], $this->columns[$col][0][flag]);
  }    

  # prints date in user defined format
  # param: date format like in PHP (like "m-d-Y")
  function f_d($col, $param="") {
    $param = $this->subst_alias( $param );
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

  # expands and prints a string, if parameters are blank then expands field
  function f_y($col, $param="") {
    global $debug;
#    $debug = 1;
    if ($param) {
        return($this->unalias($param));
    } else {
        return($this->unalias($this->columns[$col][0][value]));
    }
  }

  # prints height and width of image file or URL referenced in field
  # Could be special case if in uploads directory, so can read directly
  function i_s($col, $param="") {
    if (! isField($col)) 
        huhe("Warning: i_s: $col is not a field, don't wrap it in { } ");
    $f = $this->columns[$col][0][value];
    if (! $f) { return ""; }  # No picture, common don't warn (expanding inside switch)
    # Could speed up a little with a test for URLs in uploads directory here
#PHP>4.0.5 supports URLs so no need to skip URLs
if ($GLOBALS[debug]) huhl("Going for image",$f);
    $a = getimagesize($f);
if ($GLOBALS[debug]) huhl("Got for image",$a);
    if (! $a)
        huhe("Warning: getimagesize couldn't get width from '$f'");
    return($a[3]);  #height="xxx" width="yyy"
  }

  # prints unpacked id
  # param: 0
  function f_n($col, $param="") {
    return unpack_id( $this->getval($col) ); 
  }

  # prints image height atribut (<img height=...) or clears it
  # param: 0
  function f_g($col, $param="") {    # image height
    global $out;
    if( !$this->getval($col) ) {
      $out = ERegI_Replace( "height[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete height = x
      return false;
    }
    return htmlspecialchars($this->getval($col));
  }

  # prints image width atribut (<img width=...) or clears it
  # param: 0
  function f_w($col, $param="") {    # image width
    global $out;
    if( !$this->getval($col) ) {
      $out = ERegI_Replace( "width[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete width = x
      return false;
    }
    return htmlspecialchars($this->getval($col));
  }
  
  function mystripos ($haystack, $needle) {
    $sub = stristr ($haystack, $needle);
    if ($sub) 
      return strlen ($haystack) - strlen ($sub);
    else return strlen ($haystack);
  }
          
  # prints abstract or grabed fulltext text field
  # param: length:field_id
  #    length - number of characters taken from field_id (like "80:full_text.......")
  function f_a($col, $param="") {
    list( $plength, $pfield, $pparagraph ) = $this->subst_aliases( ParamExplode($param) );
    if ($this->getval($col))
      return DeHtml( $this->getval($col), $this->getval($col,'flag') );
    if ($pparagraph) {
      $paraend = min ($this->mystripos ($pfield,"<p>"),$this->mystripos($pfield,"</p>"),$this->mystripos($pfield,"<br>"), $plength);
    }
    else $paraend = $plength;
    return htmlspecialchars( substr($pfield, 0, $paraend) );
  }

  # prints link to fulltext (hedline url)
  # col: hl_href.........
  # param: link_only:redirect
  #    link_only field id (like "link_only.......")
  #    redirect - url of another page which shows the content of item 
  #             - this page should contain SSI include ../slice.php3 too
  #    no_sess  - if true, it does not add session id to url
  function f_f($col, $param="") { 
    list($plink, $predir, $psess) = $this->subst_aliases(ParamExplode($param));
    return $this->getitemurl($plink, $this->getval($col), $predir, 1, $psess);
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
    $p = ParamExplode($param);
    list ($plink_only, $purl_field, $predirect, $ptxt, $pcondition, $paddition, $pno_sess) = $this->subst_aliases($p);

    if (!$p[4])           # undefined condition parameter
      $pcondition = true;
    
    # last parameter - condition field
    $url = $this->getitemurl($plink_only, $purl_field, $predirect, $pcondition, $pno_sess);
    $flg = ( $this->columns[$p[3]] ? $this->getval($p[3],'flag') : true );
    return $this->getahref($url,$ptxt,$paddition,$flg);
  }    

  # prints 'blurb' (piece of text) based from another slice,
  # based on a simple condition.
  /*
    Blurb slice, has fields
    headline........  ; example: "Computer Basics - Technology"
    full_text.......  ; example: "What you need to know for this cateogry is .."
        OR 
    title.......     ; "Computer Basics - Overview"
    fulltext.....  ; "What you need to know for this cateogry is ...."
    
    In view (of other slices), these blurbs can be gotten by creating a 
      _#BLURB### alias, as a part of the field category........
      _#BLURB### uses function f_q  
  */


  # returns fulltext of the blurb
  function f_q($col, $param="") {
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

      $fieldToMatch    = quote(     $p[2] ? $p[2] : BLURB_FIELD_TO_MATCH  );
      $fieldToReturn   = quote(     $p[3] ? $p[3] : BLURB_FIELD_TO_RETURN );
     /*
     This SQL effectively narrows down through three sets:
    a) all the items from our blurb slice
       (all the item_ids from item where item.slice_id  = $blurb_sliceid_packed)
    b) take set a) and filter to find where the headline (category name)
       matches our category name.
    c) using the single item_id from b), find the content record that has the
       fulltext blurb
     */
      if (($fieldToReturn == "id..............")
        or ($fieldToReturn == "short_id........")) {
        print("f_q cannot yet return $fieldToReturn fields");
        return("");
      } elseif ($fieldToMatch == "id..............") {
	// Special case id... its not a real field
      	$fqsqlid = q_pack_id($stringToMatch);
      	$SQL = "SELECT c2.text AS text 
                FROM content c2 
                WHERE 
                     c2.field_id    = '$fieldToReturn' AND
                     c2.item_id     = '$fqsqlid'";

      } elseif ($fieldToMatch == "short_id........") {
      	$p_blurbSliceId  = q_pack_id( $p[1] ? $p[1] : BLURB_SLICE_ID  );
        $SQL = "SELECT c2.text AS text 
                FROM item LEFT JOIN content c2 ON item.id = c2.item_id
                WHERE slice_id  = '$p_blurbSliceId' AND
                     item.short_id = '$stringToMatch' AND
                     c2.field_id    = '$fieldToReturn'";
      } else {	
      	$p_blurbSliceId  = q_pack_id( $p[1] ? $p[1] : BLURB_SLICE_ID  );
        $SQL = "SELECT c2.text AS text 
                FROM item LEFT JOIN content c1 ON item.id = c1.item_id 
                          LEFT JOIN content c2 ON item.id = c2.item_id
                WHERE slice_id  = '$p_blurbSliceId' AND
                     c1.field_id    = '$fieldToMatch' AND
                     c2.field_id    = '$fieldToReturn' AND
                     c1.text        = '".addslashes($stringToMatch)."'";
      }
      $db = getDB();
      $db->tquery($SQL);
       $res =( $db->next_record() ? $db->f('text') : "" ); 
      freeDB($db);
      return $res;
    }


  function RSS_restrict($txt, $len) {
    return utf8_encode(htmlspecialchars(substr($txt,0,$len)));
  }  

  # standard aliases to generate RSS .91 compliant meta-information
  function f_r($col, $param="") { 
    static $title, $link, $description; 

    $p_slice_id = $this->getval('slice_id........');
    $slice_id = unpack_id( $p_slice_id );

    if (! $title) {
      if ($slice_id==""){ echo "Error: slice_id not defined"; exit; }

      // RSS chanel (= slice) info
      $SQL= "SELECT * FROM slice WHERE id='$p_slice_id'";
      $db = getDB(); $db->query($SQL);
      if (!$db->next_record()){ echo "Can't get slice info"; exit;  }

      $title           = $this->RSS_restrict( $db->f(name), 100);
      $link            = $this->RSS_restrict( $db->f(slice_url), 500);
      $name            = $db->f(name);
      $owner           = $db->f(owner);
      //$language        = RSS_restrict( strtolower($db->f(lang_file)), 2);

      $SQL = "SELECT name, email FROM slice_owner WHERE id='$owner'";
      $db->query($SQL);
      if (!$db->next_record()) {
        echo "Can't get slice info"; exit;
      }
      $description     = $this->RSS_restrict( $db->f(name).": $name", 500);
      freeDB($db);

    }
    //   return "tt: $col : $param<BR>";
    if ($col == 'SLICEdate')
      return $this->RSS_restrict( GMDate("D, d M Y H:i:s "). "GMT", 100);
    if ($col == 'SLICEtitle') return $title;
    if ($col == 'SLICElink') return $link;
    if ($col == 'SLICEdesc') return $description;

    $p = ParamExplode($param);

    if ($col == 'hl_href.........' && strlen($p[0] == 16)) {
      $redirect  = $p[1] ? $p[1] : 
            strtr(AA_INSTAL_URL, ':', '#:') .
                                  "slice.php3?slice_id=$slice_id&encap=false";
//      return strtr( $this->f_f($col, $p[0] . ':' . $redirect), '#/', ':/') ;
      return $this->RSS_restrict(strtr( $this->f_f($col, $p[0] . ':' . $redirect), '#/', ':/'),500) ;
    }

    if (strpos($p[0],"{")) // It can't be a field, must be expandable
      return $this->RSS_restrict(strtr( $this->unalias($p[0]), '#/', ':/'),500) ;
    if ( $foo = $this->getval($col))
      return $this->RSS_restrict( $foo, $p[0]);
  }

  # prints the field content and converts text to html or escape html (due to
  # html flag). If param is specified, it prints rather param (instead of field)
  # param: string to be printed (like <img src="{img_src........1}"></img>
  function f_t($col, $param="") {
    if($param)
      return $this->subst_alias( $param );
    return DeHtml($this->getval($col), $this->getval($col,'flag'));
  }

  # print database field or default value if empty
  # param: default (like "javascript: window.alert('No source url specified')")
  function f_s($col, $param="") { 
    return ( $this->getval($col) ? $this->getval($col) : $param);
  }

  # prints $col as link, if field_id in $param is defined, else prints just $col
  # param: field_id         - of possible link (like "source_href.....")
  #        additional atrib - for <a> tag
  function f_l($col, $param="") { 
    list($plink, $padditional) = $this->subst_aliases( ParamExplode($param) );
    return $this->getahref($plink, $this->getval($col),
                           $padditional,$this->getval($col,'flag'));
  }

  # If someone understands the parameter wizzard, it would be good to expand this to take a second parameter
  # i.e. return url.
  # _#EDITITEM used on admin page index.php3 for itemedit url
  # param: 0
  function f_e($col, $param="") { 
    global $sess, $slice_info;
    global $AA_INSTAL_EDIT_PATH,$AA_CP_Session;
 
    $p = ParamExplode($param);  # 0 = disc|itemcount|safe|slice_info  #2 = return_url
    // code to keep compatibility with older version
    // which was working without $AA_INSTAL_EDIT_PATH
    $admin_path = ($AA_INSTAL_EDIT_PATH ? $AA_INSTAL_EDIT_PATH . "admin/" : "");

    switch( $p[0]) {
      case "disc":
        # _#DISCEDIT used on admin page index.php3 for edit discussion comments
        return con_url($sess->url("discedit.php3"),
          "item_id=".unpack_id128( $this->getval('id..............')));
      # These next two return values from globals that would actually be better coming froom the item or itemview,
      # otherwise won't work if there are nested views.
      case "itemcount":
      	return $GLOBALS['QueryIDsCount'];
      case "itemindex";
      	return "".$GLOBALS['QueryIDsIndex'];   # Need to append to "" so doesn't return "false" on 0th item
      case "safe":
        return safe($this->getval($col));
      case "javascript":                       # In javascript we need escape apostroph 
        return str_replace( "'", "\'", safe($this->getval($col)) );
      case "urlencode":
        return urlencode($this->getval($col));
      case "slice_info":
        if( !is_array( $slice_info ) )
          $slice_info = GetSliceInfo(unpack_id128( $this->getval('slice_id........')));
        return $slice_info[$col];
      case "link_edit":
        return get_aa_url('modules/links/linkedit.php3?lid='. $this->getval('id'));
      case "link_go_categ":
        $cat_names       = $this->columns['cat_name'];
        $cat_ids         = $this->columns['cat_id'];
        $cat_highlight   = $this->columns['cat_state'];
        if( !is_array($cat_names) )
          return "";
        while ( list($k, $cat) = each($cat_names) ) {
          $print_cname =  ( ($cat_highlight[$k]['value']=='highlight') ? 
                            '<b>'.$cat['value'].'</b>' : $cat['value'] ); 
          $ret .= $delim. '<a href="javascript:SwitchToCat('. $cat_ids[$k]['value']. ")\">$print_cname</a>";
         //          $ret .= $delim. '<a href="'.  get_aa_url('modules/links/index.php3?GoCateg='. $cat_ids[$k]['value']). '">'.$cat['value'].'</a>';
          $delim = ', ';        
        }  
        return $ret;
      case "add":
    	$add="add=1";
	// drop through to default
      default:  {
	// If Session is set, then append session id, otherwise append slice_id and it will prompt userid
          return con_url(
                   isset($sess) ? 
                     $sess->url($admin_path ."itemedit.php3")	: 
                     ($admin_path . "itemedit.php3" . 
                         ((isset($AA_CP_Session)) ?
                             ("?AA_CP_Session=" . $AA_CP_Session) : "" )),
                   (isset($add) ? $add : "edit=1").
                   "&encap=false&id=". unpack_id128($this->getval('id..............')).
                   (isset($sess) ? 
                       "" : ("&change_id=". unpack_id128($this->getval('slice_id........')))).
                   make_return_url("&return_url=",$p[1]) );	// it return "" if return_url is not defined.
                }
    }
  }                 

  # prints "begin".$col."end" if $col="condition", else prints "none"
  # if no cond_col specified - $col is used
  # if pskip_col == 1, skips $col
  # param: condition:begin:end:none:cond_col
  # if pararam begins with "!", condition is negated
  function f_c($col, $param="") { 
    if( $param[0]=="!" ){
      $param = substr($param, 1);
      $negate=true;
    }  
    
    $p = ParamExplode($param);

    list ($pcond, $pbegin, $pend, $pnone, $pccol, $pskip_col) = $this->subst_aliases($p);

    $cond = ( $p[4] ? $pccol : $this->subst_alias($col) );
    if( $cond != $pcond )
      $negate = !$negate;
    if (!$pskip_col) 
        $coltxt = DeHtml($this->getval($col), $this->getval($col,'flag'));
    return  ($negate ? $pnone : $pbegin. $coltxt .$pend); 
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
      $param = "vid=".$this->getval($col); 

  # older aliases substitution ------------- (_#publish_date....) -----------
    # substitute aliases by real item content
    $part = $param;

// If you change this to support more items, please change FAQ item #1488
    while( $part = strstr( $part, "_#" )) {  # aliases for field content
      $fid = substr( $part, 2, 16 );         # looks like _#headline........
      
      if( substr( $fid, 0, 4 ) == "this" )   # special alias _#this
        $param = str_replace( "_#this", $this->f_h($col, "-"), $param );
	  elseif( substr( $fid, 0, 5) == "slice" )   # Another special alias _#slice 
	  	//Mitra says: looks like this mucks up _#slice_id........   
		$param = str_replace("_#slice", $this->getval('slice_id........'), $param);  
      elseif( $fid == 'unpacked_id.....' )
        $param = str_replace( "_#$fid", $this->f_n('id..............'), $param );
      elseif( IsField($fid) )
        $param = str_replace( "_#$fid", $this->f_h($fid, "-"), $param );
      $part = substr( $part, 6 );
    }
    
  # unalias new ------------ ({publish_date....}, _#ITEM_ID_, ...) -----------
    $param = $this->subst_alias($param);
    return GetView(ParseViewParameters($param));
  }    

  # mailto link
  # prints: "begin<a href="mailto:$col">field/text</a>"
  # if no $col is filled, prints "else_fileld/text"
  # param: begin:field/text:else_fileld/text
  # linktype: mailto/href (default is mailto)
  function f_m($col, $param="") { 
    $p = ParamExplode($param);
    list ($pbegin, $pfield, $pelse, $ptype, $padd) = $this->subst_aliases($p);

    if( !$this->getval($col) ) {
      	return $pelse ? $pbegin.$pelse : "";
    }  
    if( $this->columns[$p[1]] ) {
      $column = ($pfield ? $p[1] : $col);
      $txt = $this->getval($column);
      $flg = $this->getval($column,'flag');
    } else {
      $txt = ( $p[1] ? $pfield : $this->getval($col));
      $flg = ( $p[1] ? FLAG_HTML : $this->getval($col,'flag'));
    }  
    $linktype =  (($ptype && ($ptype!='mailto')) ? "" : "mailto:");
    return $pbegin.$this->getahref( $linktype.$this->getval($col), $txt, $padd, $flg);
  }

  # substring with case conversion
  
  function f_j($col, $param="") { 
    $p = ParamExplode($param);
    list ($start, $n, $case) = $this->subst_aliases($p);

	$text = $this->getval($col);
	if ($n <= 0) $n = strlen ($text);
	$text = substr($text,$start,$n);
	
	if ($case == "upper")		$text = strtoupper ($text);
	else if ($case == "lower")	$text = strtolower ($text);
	else if ($case == "first")  $text = ucwords (strtolower ($text));
	return $text;
  }

  # live checkbox -- updates database immediately on clicking without reloading the page
  function f_k ($col, $param = "")
  {
    global $AA_INSTAL_PATH;
    $short_id = $this->columns["short_id........"][0]["value"];
    $name = "live_checkbox[".$short_id."][$col]";
    $img = $this->getval($col) ? "on" : "off";
    return "<img width='16' height='16' name='$name' border='0'
                 onClick='javascript:CallLiveCheckbox (\"$name\");'
                 src='".$AA_INSTAL_PATH."images/cb_".$img.".gif' 
                 alt='".($this->getval($col) ? _m("on") : _m("off"))."'>";
  }
  
  # transformation function - transforms strings to another strings
  # Parameters: <from1>:<to1>:<from2>:<to2>:<default>
  #   if $col==<from1> the function returns <to1> 
  #   if $col==<from2> the function returns <to2> 
  #   else it returns <dafault>
  #   <to1>, <to2>, ... and <default> can be field ids
  function f_x ($col, $param="") {
    $p = $this->subst_aliases( ParamExplode($param) );
    $to = (int) floor(count($p)/2);
    $colvalue = $this->getval($col);
    for( $i=0; $i < $to; $i++ ) {
      $first = $i*2;
      $second = $first +1;
      if( ereg( $p[$first] , $colvalue ) )
        return $p[$second];
    }
      # the last option can be definned as default
    return $p[$second+1];
  } 
  
  # prints 'New' (or something similar) for new items
  # Parameters: <time_in_minutes>:<text_to_print_if_newer>:<text_to_print_if_older>
  #   (this alias will be probably used with expiry date field)
  function f_o($col, $param="") {
    $p = $this->subst_aliases( ParamExplode($param) );
    return ((time() - $this->getval($col)) < $p[0]*60) ? $p[1] : $p[2];  // time in minutes
  }
  
  # ----------------- alias function definition end --------------------------

  // function shows full text navigation (back, home)
  function show_navigation($home_url) {
    echo '<br><a href="javascript:history.back()">'. _m("Back") .'</a> &nbsp; ';
    echo "<a href=\"$home_url\">". _m("Home") .'</a><br>';
  }  

  // Get format string, unalias it and return (not clear why unaliased - Mitra)
  function get_item() {
  // format string
    $out = $this->format;
    $remove = $this->remove;
    $out = $this->unalias($out, $remove);
    return $out;
  }  
};

?>
