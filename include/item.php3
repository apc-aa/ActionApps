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

require_once $GLOBALS["AA_INC_PATH"]. "math.php3";
require_once $GLOBALS["AA_INC_PATH"]. "stringexpand.php3";
require_once $GLOBALS["AA_INC_PATH"]. "item_content.php3";
require_once $GLOBALS["AA_BASE_PATH"]."modules/links/constants.php3";

if ( !is_object($contentcache) ) {
    $contentcache = new contentcache;
}

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
  $aliases["_#ID_COUNT"] = GetAliasDef( "f_e:itemcount",        "id..............", _m("number of found items"));
  $aliases["_#ITEMINDX"] = GetAliasDef( "f_e:itemindex",        "id..............", _m("index of item within whole listing (begins with 0)"));
  $aliases["_#PAGEINDX"] = GetAliasDef( "f_e:pageindex",        "id..............", _m("index of item within a page (it begins from 0 on each page listed by pagescroller)"));
  $aliases["_#ITEM_ID_"] = GetAliasDef( "f_n:id..............", "id..............", _m("alias for Item ID"));
  $aliases["_#SITEM_ID"] = GetAliasDef( "f_h",                  "short_id........", _m("alias for Short Item ID"));

  if( $type == 'justids') {  // it is enough for view of urls
      trace("-");
      return $aliases;
  }

  $aliases["_#EDITITEM"] = GetAliasDef(  "f_e",            "id..............", _m("alias used on admin page index.php3 for itemedit url"));
  $aliases["_#ADD_ITEM"] = GetAliasDef(  "f_e:add",        "id..............", _m("alias used on admin page index.php3 for itemedit url"));
  $aliases["_#EDITDISC"] = GetAliasDef(  "f_e:disc",       "id..............", _m("Alias used on admin page index.php3 for edit discussion url"));
  $aliases["_#RSS_TITL"] = GetAliasDef(  "f_r",            "SLICEtitle",       _m("Title of Slice for RSS"));
  $aliases["_#RSS_LINK"] = GetAliasDef(  "f_r",            "SLICElink",        _m("Link to the Slice for RSS"));
  $aliases["_#RSS_DESC"] = GetAliasDef(  "f_r",            "SLICEdesc",        _m("Short description (owner and name) of slice for RSS"));
  $aliases["_#RSS_DATE"] = GetAliasDef(  "f_r",            "SLICEdate",        _m("Date RSS information is generated, in RSS date format"));
  $aliases["_#SLI_NAME"] = GetAliasDef(  "f_e:slice_info", "name",             _m("Slice name"));

  $aliases["_#MLX_LANG"] = GetAliasDef(  "f_e:mlx_lang",   MLX_CTRLIDFIELD,             _m("Current MLX language"));


  # database stored aliases
  while( list($k,$val) = each($fields) ) {
    if( $val[alias1] )
      $aliases[$val[alias1]] = array("fce" =>  $val[alias1_func],
                                     "param" => ( $val[id] ),
                                     "hlp" => $val[alias1_help],
                                     "fld" => $k);                 # fld used
                           # in PrintAliasHelp to point to alias editing page

    if( $val[alias2] )
      $aliases[$val[alias2]] = array("fce" =>  $val[alias2_func],
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

/** Returns aliases
 *  @param string type - 'const'/'links'/'categories' - just like *view* types
 */
function GetAliases4Type( $type, $additional="" ) {
    switch ( $type ) {
        case 'const':
            #  Standard aliases
            $aliases["_#NAME###_"] = GetAliasDef( "f_h", "const_name",        _m("Constant name"));
            $aliases["_#VALUE##_"] = GetAliasDef( "f_h", "const_value",       _m("Constant value"));
            $aliases["_#PRIORITY"] = GetAliasDef( "f_h", "const_pri",         _m("Constant priority"));
            $aliases["_#GROUP##_"] = GetAliasDef( "f_n", "const_group",       _m("Constant group id"));
            $aliases["_#CLASS##_"] = GetAliasDef( "f_h", "const_class",       _m("Category class (for categories only)"));
            $aliases["_#COUNTER_"] = GetAliasDef( "f_h", "const_counter",     _m("Constant number"));
            $aliases["_#CONST_ID"] = GetAliasDef( "f_n", "const_id",          _m("Constant unique id (32-haxadecimal characters)"));
            $aliases["_#SHORT_ID"] = GetAliasDef( "f_t", "const_short_id",    _m("Constant unique short id (autoincremented from '1' for each constant in the system)"));
            $aliases["_#DESCRIPT"] = GetAliasDef( "f_t", "const_description", _m("Constant description"));
            $aliases["_#LEVEL##_"] = GetAliasDef( "f_t", "const_level",       _m("Constant level (used for hierachical constants)"));
            break;
        case 'links':
            $aliases = GetLinkAliases();       // defined in modules/links/constant.php3
            break;
        case 'categories':
            $aliases = GetCategoryAliases();   // defined in modules/links/constant.php3
            break;
    }

    // add additoinal aliases
    if( isset( $additional ) AND is_array( $additional ) ) {
        $aliases = array_merge($aliases, $additional);
    }
    return($aliases);
}


/** Adds new $alias to $aliases array and creates fake field for it
 *  (to $content4id array). The vaule for the field is set to $value (and $flag)
 */
function FillFakeAlias(&$content4id, &$aliases, $alias, $value, $flag=FLAG_HTML) {
    // you can specify multiple value ($value is array then)
    if( !is_array($value) ) {   $value = array($value);  }

    do {
        $colname = CreateFieldId( strtolower( substr( $alias,2,12 )));
    } while ( isset($content4id[$colname]) );

    foreach( $value as $v ) {
        $content4id[$colname][] = array ("value" => $v, "flag" => $flag);
    }
    $aliases[$alias] = GetAliasDef("f_h", $colname, _m("Alias for %1",array($colname)));
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

/** helper function which create link to inputform from item manager
  * (_#EDITITEM, f_e:add, ...)  */
function Inputform_url($add, $iid, $sid, $ret_url, $vid='') {
    global $sess, $AA_INSTAL_EDIT_PATH, $AA_CP_Session;
    // code to keep compatibility with older version
    // which was working without $AA_INSTAL_EDIT_PATH
    $admin_path = ($AA_INSTAL_EDIT_PATH ? $AA_INSTAL_EDIT_PATH . "admin/itemedit.php3" : "itemedit.php3");
    // If Session is set, then append session id, otherwise append slice_id and it will prompt userid
    $url2go = isset($sess) ?
                     $sess->url($admin_path)	:
                     ($admin_path .(isset($AA_CP_Session) ? "?AA_CP_Session=$AA_CP_Session" : "" ));
    global $profile;
    $param = get_if($add, "edit=1").
             ($vid ? "&vid=$vid" : "").
             "&encap=false&id=$iid".
             (isset($sess) ? "" : "&change_id=$sid").
             ((isset($profile) AND $profile->getProperty('input_view')) ?
                  '&vid='.$profile->getProperty('input_view') : '').
             make_return_url("&return_url=",$ret_url);	// it return "" if return_url is not defined.
    return con_url($url2go,$param);
}

/** Creates item object just from item id and fills all necessary structures
 * @param         id        - an item id, unpacked or short
 *                          - could be also ZID - then $use_short_ids is ignored
 * @param boolean short_ids - indicating type of $ids (default is false => unpacked)
 */
function GetItemFromId($id, $use_short_ids='aa_guess') {
    if (isset($id) && ($id != "-")) {
        $content = new ItemContent($id);
        $slice   = new slice($content->getSliceID());
        return new item($content->getContent(),$slice->aliases());
    }
    return false;
}

class item {
  var $columns;        // ItemContent array for this Item (like from GetItemContent)
  var $clean_url;      //
  var $format;         // format string with aliases
  var $remove;         // remove string
  var $aliases;        // array of usable aliases
  var $parameters;     // optional additional parameters - copied from itemview->parameters

  /** Constructor
   *  Take a look at GetItemFromId() above, if you want to create item from id
   */
  function item($content4id='', $aliases='', $clean_url='', $format='', $remove='', $param=false){
    // there was three other options, but now it was never used so I it was
    // removed: $item_content, $top and $bottom (honzam 2003-08-19)
    $this->set_data($content4id);
    $this->aliases      = $aliases;
    $this->clean_url    = $clean_url;
    $this->format       = $format;
    $this->remove       = $remove;
    $this->parameters   = ( $param ? $param : array() );
  }

  function setformat( $format, $remove="") {
    $this->format = $format;
    $this->remove = $remove;
  }

  /** Optional asociative array of additional parameters
   *  Used for category_id (in Links module) ... */
  function set_parameters($parameters) {
      $this->parameters = $parameters;
  }

  /** sets content (ItemContent object or older - content4id array) */
  function set_data($content4id) {
      $this->columns = ((strtolower(get_class($content4id))=='itemcontent') ?
                       $content4id : new ItemContent($content4id));
  }

  /** sets for defined field it's new value  */
  function set_field_value($field, $value) {
      $this->columns->setValue($field, $value);
  }


  /** shortcut for ItemContent->getValue() */
  function getval($column, $what='value') {  return $this->columns->getValue($column, $what); }

  /** shortcut for ItemContent->getValues() */
  function getvalues($column) { return $this->columns->getValues($column); }

  function getContent()       { return $this->columns->getContent();  }
  function getItemID()        { return $this->columns->getItemID();  }
  function getSliceID()       { return $this->columns->getSliceID(); }

  function getbaseurl($redirect=false, $no_sess=false) {
      # redirecting to another page
      $url_base = ($redirect ? $redirect : $this->clean_url );

      if( $no_sess ) {                     #remove session id
          $pos = strpos($url_base, '?');
          if($pos) {
              $url_base = substr($url_base,0,$pos);
          }
      }
      # add state variable, if defined (apc - AA Pointer Cache)
      if( $GLOBALS['apc_state'] ) {
          $url_base = con_url( $url_base, 'apc='.$GLOBALS['apc_state']['state'] );
      }
      return $url_base;
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

    return con_url( $this->getbaseurl($redirect, $no_sess), $url_param );
  }

  # get link from url and text
  function getahref($url, $txt, $add="", $html=false, $hide=false) {
    if( $url AND $txt ) {
      # repair url if user omits to write http://
      if( substr($url,4)=='www.' )
        $url = 'http://'.$url;
      # hide email from spam robots
      if ($hide=='1') {
        $linkpart=explode('@',str_replace("'", "\'", $url.'@'.DeHtml($txt, $html)),4);
        $ret = "\n<script language=\"JavaScript\" type=\"text/javascript\">\n<!--\ndocument.write('<a href=\"".$linkpart[0]."'+'@'+'".$linkpart[1]."\" ".$add.">". $linkpart[2];
        if ($linkpart[3])
          $ret .= "'+'@'+'".$linkpart[3];
        $ret .= "</a>')\n// -->\n</script>\n";
        return $ret;
      } else {
        return '<a href="'. $url ."\" $add>". DeHtml($txt, $html).'</a>';
      }
    }
    return DeHtml($txt,$html);
  }

  function get_alias_subst( $alias, $use_field="" ) {
      // use_field is for expanding aliases with loop - prefix {@
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
    return $this->$fce(get_if($use_field, $ali_arr['param']), $function['param']);
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

  # the function substitutes all _#... aliases and then applies "remove strings"
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
    $level    = 0;
    $GLOBALS['g_formpart'] = 0;  // used for splited inputform into parts
#   return $this->old_unalias_recurent( $text, $remove, $level, $maxlevel );
    $ret = new_unalias_recurent($text, $remove, $level, $maxlevel, $this ); # Note no itemview param
    trace("-");
    return $ret;
  }

  function subst_alias( $text ) {
      return (IsField($text) ? $this->getval($text) : $this->unalias($text));
  }

  function subst_aliases( $var ) {
      if( !is_array( $var ) ) {
          return $this->subst_alias( $var );
      }
      foreach ( $var as $k => $v ) {
         $ret[$k] = $this->subst_alias( $v );
      }
      return $ret;
  }

  # --------------- functions called for alias substitution -------------------

  # null function
  # param: 0
  function f_0($col, $param="") { return ""; }

  # print due to html flag set (escape html special characters or just print)
  # param: delimeter - used to separate values if the field is multi
  function f_h($col, $param="") {
      $values = $this->getvalues($col);
      if ( $param AND $values) {  # create list of values for multivalue fields
          $param = $this->subst_alias( $param );
          foreach( $values as $v ) {
              $res .= ($res ? $param : ''). DeHtml($v['value'], $v['flag']); # add value separator just if field is filled
          }
          return $res;
      }
      return DeHtml($this->getval($col), $this->getval($col,'flag'));
  }

  # prints date in user defined format
  # param: date format like in PHP (like "m-d-Y")
  function f_d($col, $param="") {
      $param = $this->subst_alias( $param );
      if( $param=="" ) {
          $param = "m/d/Y";
      }
      $dstr = date($param, $this->getval($col));
      return (($param != "H:i") ? $dstr : ( ($dstr=="00:00") ? "" : $dstr ));
  }

  # prints image scr (<img src=...) - NO_PICTURE for none
  # param: 0
  function f_i($col, $param="") {
      return get_if( $this->getval($col), NO_PICTURE_URL);
  }

  # expands and prints a string, if parameters are blank then expands field
  function f_y($col, $param="") {
      $str2unalias = get_if( $param, $this->getval($col) );
      return $this->unalias( $str2unalias );
  }

  # prints height and width of image file or URL referenced in field
  # Could be special case if in uploads directory, so can read directly
  function i_s($col, $param="") {
    if (! isField($col))
        huhe("Warning: i_s: $col is not a field, don't wrap it in { } ");
    $f = $this->getval($col);
    if (! $f) { return ""; }  # No picture, common don't warn (expanding inside switch)
    # Could speed up a little with a test for URLs in uploads directory here
    # PHP>4.0.5 supports URLs so no need to skip URLs
    $a = getimagesize($f);
    // No warning required, will be generated by getimagesize
    //    if (! $a)
    //        huhe("Warning: getimagesize couldn't get width from '$f'");
    list( $ptype ) = $this->subst_aliases( ParamExplode($param) );
    switch ( $ptype ) {
        case 'width':   return $a[0];
        case 'height':  return $a[1];
        case 'imgtype': return $a[2]; // 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM
        case 'mime':    return image_type_to_mime_type($a[2]);
    }
    return $a[3];  #height="xxx" width="yyy"
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

  function mystripos($haystack, $needle) {
      $sub = stristr($haystack, $needle);
      return ($sub ? strlen($haystack)-strlen($sub) : strlen($haystack));
  }

  /** Prints abstract ($col) or grabed fulltext text from field_id
   *  param: length:field_id:paragraph
   *         length    - max number of characters taken from field_id
   *                     (like "80:full_text.......")
   *         field_id  - field from which we grab the paragraph, if $col is
   *                     empty. If we do not specify field_id or we specify
   *                     the same field as current $col, then the content is
   *                     grabbed from current $col
   *         paragraph - boolean - if true, it tries to identify return only
   *                     first paragraph or at least stop at the end of sentence
   */
  function f_a($col, $param="") {
      list( $plength, $pfield, $pparagraph ) = $this->subst_aliases( ParamExplode($param) );
      if ( !$pfield ) {
          $pfield = $col;                // content is grabbed from current $col
      }
      $value = $this->getval($col);
      if ($value AND ($col != $pfield)) {
          return DeHtml( $value, $this->getval($col,'flag') );
      }
      if ($pparagraph) {
          $paraend      = min(my_stripos($value,"<p>"),my_stripos($value,"</p>"),my_stripos($value,"<br>"),my_stripos($value,"\n"),my_stripos($value,"\r"), $plength);
          $shorted_text = substr($value, 0, $paraend);
          if ($paraend==$plength) {      // no <BR>, <P>, ... found
              // try to find dot (first from the end)
              $dot = strrpos( $shorted_text,".");
              if ( $dot > $paraend/3 ) { // take at least one third of text
                  $shorted_text = substr($shorted_text, 0, $dot+1);
              } elseif ( $space = strrpos($shorted_text," ") ) {   // assignment!
                  $shorted_text = substr($shorted_text, 0, $space);
              } // no dot, no space - leave the text plength long
          }
      } else {
          $shorted_text = substr($value, 0, $plength);
      }
      return strip_tags( $shorted_text );
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
    $flg = ( $this->columns->is_set($p[3]) ? $this->getval($p[3],'flag') : true );
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
      $stringToMatch = get_if( $this->getval($stringToMatch_Raw), $stringToMatch_Raw);

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


// This function used to do a UTF8 encode, but since characters
// are coming out of a DB which I believe is iso-8859-1, they shouldn't
// be utf8 encoded. Unfortunately the internationalisation of AA isn't
// documented anywhere and noone responded to email about it. So this is
// just a guess. If anyone who knows something about this is looking
// at this comment, then you can replace this code with something
// that intelligently looks at the database encoding before converting to
// iso-8859-1. OR possibly better, output the encoding in the head of the RSS
// via  a new alias - mitra@mitra.biz 30Oct03
function RSS_restrict($txt, $len) {
#    return utf8_encode(htmlspecialchars(substr($txt,0,$len)));
    return htmlspecialchars(substr($txt,0,$len));
  }

  # standard aliases to generate RSS .91 compliant meta-information
  function f_r($col, $param="") {
    static $title, $link, $description;

    $p_slice_id = addslashes($this->getval('slice_id........'));
    $slice_id = unpack_id( $p_slice_id );

    if (! $title) {
      if ($slice_id==""){ echo "Error: slice_id not defined"; exit; }

      // RSS chanel (= slice) info
      $SQL= "SELECT * FROM slice WHERE id='$p_slice_id'";

      $db = getDB(); $db->query($SQL);
      if (!$db->next_record()){ echo "Can't get slice info"; exit;  }

      $title           = $this->RSS_restrict( $db->f('name'), 100);
      $link            = $this->RSS_restrict( $db->f('slice_url'), 500);
      $name            = $db->f('name');
      $q_owner         = addslashes($db->f('owner'));
      //$language        = RSS_restrict( strtolower($db->f(lang_file)), 2);

      $SQL = "SELECT name, email FROM slice_owner WHERE id='$q_owner'";
      $db->query($SQL);
      if (!$db->next_record()) {
        echo "Can't get slice owner info"; exit;
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

    if ($col == 'hl_href.........' && strlen($p[0]) == 16) {
      $redirect  = $p[1] ? $p[1] :
            strtr(AA_INSTAL_URL, ':', '#:') .
                                  "slice.php3?slice_id=$slice_id&encap=false";
//      return strtr( $this->f_f($col, $p[0] . ':' . $redirect), '#/', ':/') ;
      return $this->RSS_restrict(strtr( $this->f_f($col, $p[0] . ':' . $redirect), '#/', ':/'),500) ;
    }

    if (strpos($p[0],"{") !== false) // It can't be a field, must be expandable
      return $this->RSS_restrict(strtr( $this->unalias($p[0]), '#/', ':/'),500) ;
    if ( $foo = $this->getval($col))
      return $this->RSS_restrict( $foo, $p[0]);
  }

  # prints the field content and converts text to html or escape html (due to
  # html flag). If param is specified, it prints rather param (instead of field)

  # f_t looks to see if param is a field, and if so gets the value, and
  #  if it doesn't look like a field, then it unaliases the param
  # If there is no param, then it converts the field to HTML (if text)
  # param: string to be printed (like <img src="{img_src........1}"></img>
  function f_t($col, $param="") {
      $p = ParamExplode($param);
      if ( isset($p[1]) ) {
          $text = get_if( $p[0], $this->getval($col) );
          switch ( $p[1] ) {
              case 'csv':         return !ereg("[,\"\n\r]", $text) ? $text :
                                         '"'.str_replace('"', '""', str_replace("\r\n", "\n", $text)).'"';
              case 'safe':        return htmlspecialchars($text);
                                  // In javascript we need escape apostroph
              case 'javascript':  return str_replace("'", "\'", safe($text));
              case 'urlencode':   return urlencode($text);
              case 'striptags':   return strip_tags($text);
              case 'asis':        return $text;    // do not DeHtml - good for search & replace in fields
              case '':            $param = $p[0];  // case 'some text:'
          }
      }
      return $param ? $this->subst_alias( $param ):
                      DeHtml($this->getval($col), $this->getval($col,'flag'));
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

    $p = ParamExplode($param);  # 0 = disc|itemcount|safe|slice_info  #2 = return_url
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
      case "pageindex";
        return "".$GLOBALS['QueryIDsPageIndex'];   # Need to append to "" so doesn't return "false" on 0th item
      case "safe":         // safe, javascript, urlencode, csv - for backward
      case "javascript":   // compatibility
      case "urlencode":
      case "csv":
        return $this->f_t($col,":".$p[0]);
      case "slice_info":
        if( !is_array( $slice_info ) )
          $slice_info = GetSliceInfo(unpack_id128( $this->getval('slice_id........')));
        return $slice_info[$col];
      case "link_edit":
        return (($p[1]=='anonym') ?
            get_aa_url('modules/links/linkedit.php3?free=anonym&freepwd=anonym&lid='. $this->getval('id')) :
            get_aa_url('modules/links/linkedit.php3?lid='. $this->getval('id')) );
      case "link_go_categ":
        $cat_names       = $this->getvalues('cat_name');
        $cat_ids         = $this->getvalues('cat_id');
        $cat_highlight   = $this->getvalues('cat_state');
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
      case 'link_valid':  //converts valid_rank (0-1000) to color (from green to red)
        $red = $this->getval('valid_rank')*0.255;
        return sprintf("%02X%02X%02X",$red,255-$red,0);
      case 'selected':  // returns true, if current item is the selected one
                        // (given by set[]=selected-454343 view.php3 parameter)
        return (( (integer)$p[1] == (integer)($this->getval('short_id........')) ) ? '1' : '0');
      case 'username':    // prints user name form its id
        return perm_username( $this->getval($col) );
      case 'mlx_lang':    // print the current mlx language (the desired one instead of the lang_code...)
        if(!$GLOBALS['mlxView']) {
	  if( !is_array( $slice_info ) )
            $slice_info = GetSliceInfo(unpack_id128( $this->getval('slice_id........')));
          if(!$slice_info['MLX_SLICEDB_COLUMN'])
            return _m("Not an MLX Slice -- no MLX Control Slice selected.");
          $mlxView = new MLXView($slice_info['MLX_SLICEDB_COLUMN']);
	} else 
	  $mlxView = $GLOBALS['mlxView'];
	if(!$mlxView)
	  return "";
        return $mlxView->getLangByIdx(0);		  
        break;
      case 'addform':   // show link to inputform with special design defined in view (id in p[1])
        $add = "add=1";
      // drop through to default
      case 'editform':
        return Inputform_url($add, unpack_id128($this->getval('id..............')),
                                   unpack_id128($this->getval('slice_id........')), $p[2], $p[1]);
      case "add":
        $add="add=1";
      // drop through to default
      default:
          return Inputform_url($add, unpack_id128($this->getval('id..............')),
                                     unpack_id128($this->getval('slice_id........')), $p[1]);
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
    if (!is_callable($fnc)) {
        huhl("Field $col is defined with f_u and function '$fnc', but '$fnc' is not defined in apc-aa/include/usr_aliasfnc.php3");
        return "";
    }
    return $fnc($this->columns->getContent(), $col, $param);
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
    list ($pbegin, $pfield, $pelse, $ptype, $padd, $phide) = $this->subst_aliases($p);

    if( !$this->getval($col) ) {
        return $pelse ? $pbegin.$pelse : "";
    }
    if ( $this->columns->is_set($p[1]) ) {
      $column = ($pfield ? $p[1] : $col);
      $txt = $this->getval($column);
      $flg = $this->getval($column,'flag');
    } else {
      $txt = ( $p[1] ? $pfield : $this->getval($col));
      $flg = ( $p[1] ? FLAG_HTML : $this->getval($col,'flag'));
    }
    $linktype =  (($ptype && ($ptype!='mailto')) ? "" : "mailto:");
    return $pbegin.$this->getahref( $linktype.$this->getval($col), $txt, $padd, $flg, $phide);
  }

  /** substring with case conversion
   * @param $start  - position, where to start the substring
   * @param $n      - number of characters (<=0 means all charasters to the end)
   * @param $case   - convert to upper/lower/first
   * @param $addstr - string to be added at the end of SHORTED string
   *                  (probably something like [...])
   */
  function f_j($col, $param="") {
    $p = ParamExplode($param);
    list ($start, $n, $case, $addstr) = $this->subst_aliases($p);

    $text = $this->getval($col);
    if ($n <= 0) $n = strlen ($text);
    $ret = substr($text,$start,$n);

    if ($case == "upper")		$ret = strtoupper ($ret);
    elseif ($case == "lower")	$ret = strtolower ($ret);
    elseif ($case == "first")   $ret = ucwords (strtolower ($ret));

    if( $addstr AND (strlen($ret) <> strlen($text)))  $ret .= $addstr;
    return $ret;
  }

  # live checkbox -- updates database immediately on clicking without reloading the page
  function f_k ($col, $param = "") {
    global $AA_INSTAL_PATH;
    $short_id = $this->getval("short_id........");

    if ($param == "") {
        $name = "live_checkbox[".$short_id."][$col]";
        $img = $this->getval($col) ? "on" : "off";
        return "<img width='16' height='16' name='$name' border='0'
                 onClick='javascript:CallLiveCheckbox (\"$name\");'
                 src='".$AA_INSTAL_PATH."images/cb_".$img.".gif'
                 alt='".($this->getval($col) ? _m("on") : _m("off"))."'>";
    } else {
        $params = ParamExplode($param);

        $fncname = "show_fnc_".$params[0];
        $param2 = substr($param, strpos($param, ":")+1);

        $content4id = $this->columns->getContent();

        $varname = "live_change[".$short_id."][$col]";
    }
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
      if( ($p[$first] != '') AND ereg( $p[$first] , $colvalue ) )
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

  /** Link module (category) function - prints @ (or first parameter) when
   *  category is crossreferenced
   *  @param <crossreference_character> - specify if you want another, than '@'
   */
  function l_b($col, $param="") {
    $p = $this->subst_aliases( ParamExplode($param) );
    $way = explode(',', $this->getval($col)); // to get parent category
    return ( $way[count($way)-2] == $this->parameters['category_id']) ?
           '' : ($p[0] ? $p[0] : '@' );
  }

  /** Link module (category) function - prints 1 when category is general one */
  function l_g($col, $param="") {
    return Links_IsGlobalCategory($this->getval($col)) ? '1' : '0';
  }

  /** Link module (category) function - prints category priority, if category
   *  is general one */
  function l_o($col, $param="") {
    return Links_GlobalCatPriority($this->getval($col));
  }

  /** Link module - print current path (or list of paths to categories specified
   *       in $col (when <categs delimeter> is present)
   *  @param <start_level>:<format>:<delimeter>:<categs delimeter>:<add url param>:<url_base>
   *         <start_level> - display path from level ... (0 is root)
   *         <format>      - category link modification (not used, yet)
   *         <delimeter>   - delimeter character (default is ' &gt; ')
   *         <categs delimeter>   - delimeter character (default is ' &gt; ')
   *         <add url param>      - url parameter added to cat=xxx (id=links)
   *         <url_base>    - file to go (like: kormidlo.shtml)
   */
  function l_p($col, $param="") {
    global $contentcache;

    $translate = $contentcache->get_result( 'GetTable2Array', array(
       "SELECT id, name FROM links_categories WHERE deleted='n'", 'id', true));

    list ($start, $format, $separator, $catseparator, $urlprm, $url_base) = $this->subst_aliases(ParamExplode($param));
    if ( !$separator ) {
        $separator = ' &gt; ';
    }
    // $url_base = ''; //$this->getbaseurl();
    $urlprm = ($urlprm ? '&'.$urlprm : '');

    $categs2print = $catseparator ? $this->getvalues($col) :
                                    array(0=>array('value'=>$this->parameters['category_id'])); // current category
    $linklast     = $catseparator ? true : false;


    if ( is_array($categs2print) ) {
        foreach ( $categs2print as $v ) {
            if ($ret ) $ret .= $catseparator;
            $way = explode(',', GetCategoryPath($v['value']));
            $start_count = $start;
            $delimeter = '';
            if( isset($way) AND is_array($way)) {
                $last = $linklast ? '' : end($way);
                foreach ( $way as $catid ) {
                    if($start_count-- > 0)  continue;
                    $cat_url = con_url( $url_base, 'cat='.$catid.$urlprm);
                    $ret .= ( ( $catid == $last ) ?  // do not make link for last category
                        $delimeter.$translate[$catid]['name'] :
                        $delimeter."<a href=\"$cat_url\">".$translate[$catid]['name']."</a>" );
                    $delimeter = $separator;
                }
            }
        }
    }
    return $ret;
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
