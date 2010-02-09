<?php
/**
 *
 * PHP versions 4 and 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/
if ( file_exists( AA_INC_PATH."usr_aliasfnc.php3" ) ) {
  require_once AA_INC_PATH."usr_aliasfnc.php3";
}

require_once AA_INC_PATH. "math.php3";
require_once AA_INC_PATH. "stringexpand.php3";
require_once AA_INC_PATH. "item_content.php3";
require_once AA_BASE_PATH."modules/links/constants.php3";

if ( !is_object($contentcache) ) {
    $contentcache = new contentcache;
}

/** txt2html function
 * @param $txt
 */
function txt2html($txt) {          // converts plain text to html
    return nl2br(preg_replace('/&amp;#(\d+);/',"&#\\1;",htmlspecialchars($txt)));
                                   // preg allows text to be pasted from Word
                                   // displays qoutes instead of &8221;
}

/** DeHtml function
 * @param $txt
 * @param $flag
 */
function DeHtml($txt, $flag) {
    return ( ($flag & FLAG_HTML) ? $txt : txt2html($txt) );
}

/** DefineBaseAliases function
 * @param $aliases
 * @param $module_id
 */
function DefineBaseAliases(&$aliases, $module_id) {
    if ( !isset($aliases["_#ITEM_ID_"]) ) {
        $aliases["_#ITEM_ID_"] = GetAliasDef( "f_n:id..............", "id..............");
    }
    if ( !isset($aliases["_#SITEM_ID"]) ) {
        $aliases["_#SITEM_ID"] = GetAliasDef( "f_h",                  "short_id........");
    }
    if ( !isset($aliases["_#HEADLINE"]) ) {
        $aliases["_#HEADLINE"] = GetAliasDef( "f_e:safe",             GetHeadlineFieldID($module_id));
    }
    if ( !isset($aliases["_#JS_HEAD_"]) ) {
        $aliases["_#JS_HEAD_"] = GetAliasDef( "f_e:javascript",       GetHeadlineFieldID($module_id));
    }
}


/** GetAliasesFromFields function
 * deprecated - use AA_Fields->getAliases()
 * @param $fields
 * @param $additional
 * @param $type
 */
function GetAliasesFromFields($fields, $additional="", $type='') {
  if ( !( isset($fields) AND is_array($fields)) AND ($type != 'justids') ) {
    return false;
  }
  //add additional aliases
  if ( is_array( $additional ) ) {
      foreach ( $additional as $k => $v) {
          $aliases[$k] = $v;
      }
  }

  //  Standard aliases
  $aliases["_#ID_COUNT"] = GetAliasDef( "f_e:itemcount",        "id..............", _m("number of found items"));
  $aliases["_#ITEMINDX"] = GetAliasDef( "f_e:itemindex",        "id..............", _m("index of item within whole listing (begins with 0)"));
  $aliases["_#PAGEINDX"] = GetAliasDef( "f_e:pageindex",        "id..............", _m("index of item within a page (it begins from 0 on each page listed by pagescroller)"));
  $aliases["_#GRP_INDX"] = GetAliasDef( "f_e:groupindex",       "id..............", _m("index of a group on page (it begins from 0 on each page)"));
  $aliases["_#IGRPINDX"] = GetAliasDef( "f_e:itemgroupindex",   "id..............", _m("index of item within a group on page (it begins from 0 on each group)"));
  $aliases["_#ITEM_ID_"] = GetAliasDef( "f_n:id..............", "id..............", _m("alias for Item ID"));
  $aliases["_#SITEM_ID"] = GetAliasDef( "f_h",                  "short_id........", _m("alias for Short Item ID"));

  if ( $type == 'justids') {  // it is enough for view of urls
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
  $aliases["_#MLX_DIR_"] = GetAliasDef(  "f_e:mlx_dir",   MLX_CTRLIDFIELD,             _m("HTML markup direction tag (e.g. DIR=RTL)"));

  // database stored aliases
  foreach ($fields as $k => $val) {
      if ($val['alias1']) {
          // fld used in PrintAliasHelp to point to alias editing page
          $aliases[$val['alias1']] = array("fce" =>  $val['alias1_func'], "param" => $val['id'], "hlp" => $val['alias1_help'], "fld" => $k);
      }
      if ($val['alias2']) {
          $aliases[$val['alias2']] = array("fce" =>  $val['alias2_func'], "param" => $val['id'], "hlp" => $val['alias2_help'], "fld" => $k);
      }
      if ($val['alias3']) {
          $aliases[$val['alias3']] = array("fce" =>  $val['alias3_func'], "param" => $val['id'], "hlp" => $val['alias3_help'], "fld" => $k);
      }
  }
  return($aliases);
}

/** GetAliases4Type function
 *  Returns aliases
 * @param string type - 'const'/'links'/'categories' - just like *view* types
 * @param $additional
 */
function GetAliases4Type( $type, $additional="" ) {
    switch ( $type ) {
        case 'const':
            //  Standard aliases
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
    if ( isset( $additional ) AND is_array( $additional ) ) {
        $aliases = array_merge($aliases, $additional);
    }
    return($aliases);
}


/** FillFakeAlias function
 * Adds new $alias to $aliases array and creates fake field for it
 *  (to $content4id array). The vaule for the field is set to $value (and $flag)
 * @param $content4id
 * @param $aliases
 * @param $alias
 * @param $value
 * @param $flag
 */
function FillFakeAlias(&$content4id, &$aliases, $alias, $value, $flag=FLAG_HTML) {
    // you can specify multiple value ($value is array then)
    if ( !is_array($value) ) {
        $value = array($value);
    }

    do {
        $colname = AA_Fields::createFieldId( strtolower( substr( $alias,2,12 )));
    } while ( isset($content4id[$colname]) );

    foreach ( $value as $v ) {
        $content4id[$colname][] = array ("value" => $v, "flag" => $flag);
    }
    $aliases[$alias] = GetAliasDef("f_h", $colname, _m("Alias for %1",array($colname)));
}

/** sess_return_url function
 * helper function for f_e
 * this is called from admin/index.php3 and include/usr_aliasfnc.php3 in some site
 * added by setu@gwtech.org 2002-0211
 *
 * make_return_url
 * global function to get return_url
 * this function may replaced by extension of $sess as a method $sess->return_url().
 * @param $url
 */
function sess_return_url($url) {
    global $sess, $return_url;

    // decode and return $return_url OR return for standard APC-AA behavier
    return $return_url ? expand_return_url(1) : $sess->url($url);
}


/** make_return_url function
 * helper function for f_e
 *  this is called from admin/index.php3 and include/usr_aliasfnc.php3 in some site
 *  added by setu@gwtech.org 2002-0211
 *
 *  make_return_url
 * function make_return_url($prifix="&return_url=")
 * @param $r1
 */
function make_return_url($r1="") {
    global $return_url, $sess;
    if ($r1) {
        return $r1;
    } elseif ($return_url) {
        return $return_url;
    } elseif (!$sess) {   // If there is no $sess, then we need a return url, default to self, including parameters
        // but remove any left over AA_CP_Session, it will be re-added if needed
        return preg_match("/(.*)([?&])AA_CP_Session=[0-9a-f]{32}(.*)/",$_SERVER['REQUEST_URI'],$parts) ? ($parts[1]. $parts[2]. $parts[3]) : $_SERVER['REQUEST_URI'];
    }
    return '';
}

/** Links_admin_url function
 * helper function for f_e:link_*  (links module admin page link) - honzam
 * @param $script
 * @param $add
 */
function Links_admin_url($script, $add) {
    global $sess;
    return $sess->url(AA_INSTAL_URL. "modules/link/$script?$add");
}

/** Inputform_url function
 *  helper function which create link to inputform from item manager
 * (_#EDITITEM, f_e:add, ...)
 * @param $add - bool - true|false
 * @param $iid
 * @param $sid - for edditing it is not necessary
 * @param $ret_url
 * @param $vid
 * @param $var
 */
function Inputform_url($add, $iid, $sid='', $ret_url='', $vid = null, $var = null) {
    global $sess, $AA_CP_Session, $profile;

    // $admin_path = AA_INSTAL_URL. "admin/itemedit.php3";
    // changed back to relative address - in order we stay within the same
    // domain and protocol (https...)
    $admin_path = "itemedit.php3";

    // If Session is set, then append session id, otherwise append slice_id and it will prompt userid
    $url2go = isset($sess) ?
                     $sess->url($admin_path)	:
                     ($admin_path .(isset($AA_CP_Session) ? "?AA_CP_Session=$AA_CP_Session" : "" ));
    // return_url is used for non AA Admin interface filling - writen by Settu
    // Not sure, if it is functional. Honza 2006-01-07
    $return_url = make_return_url($ret_url);

    if ($iid) {
        $param[] = "id=$iid";
    }
    $param[] = ($add ? 'add=1' : 'edit=1');
    $param[] = "encap=false";
    if ($sid) {
        $param[] = "slice_id=$sid";
    }
    if ($return_url) {
        $param[] = 'return_url='. urlencode($return_url);
    }
    if ($var) {
        $param[] = "openervar=$var";  // id of variable in parent window (used for popup inputform)
    }
    if ($vid) {
        $param[] = "vid=$vid";
    } elseif (isset($profile) AND $profile->getProperty('input_view')) {
        $param[] = 'vid='.$profile->getProperty('input_view');
    }
    return con_url($url2go,$param);
}

/** GetFormatedItems function
 *  Fills array - [unpacked_item_id] => unaliased string (headline) for item_id
 * @param $set       - where to search
 * @param $format    - normal AA alias string like {headline.......1} or more complicated
 * @param $restrict_zids
 * @param $crypted_additional_slice_pwd
 * @param $tagprefix - array as defined in itemfunc.php3
 */
function GetFormatedItems( $set, $format, $restrict_zids=false, $crypted_additional_slice_pwd=null, $tagprefix=null) {
    $ret = array();
    $zids  = QuerySet($set, $restrict_zids);
    if ( $zids->count() <= 0 ) {
        return $ret;
    }

    $items = AA_Items::getItems($zids, $crypted_additional_slice_pwd);
    foreach($items as $long_id=>$item) {
        $ret[$long_id] = AA_Stringexpand::unalias($format, '', $item);
    }

    if (!(isset($restrict_zids) AND is_object($restrict_zids) AND ($restrict_zids->onetype() == 't') AND isset($tagprefix))) {
        return $ret;
    }

    // following code is just for tagged ids
    // (I hope it works, but I can't test it, since I do not want to use it.)
    // I think tagged IDs is bad idea and will be removed in future

    // Honza 8/27/04

    // See if need to Put the tags back on the ids
    $tags = $restrict_zids->gettags() ;
    if ( !isset($tags) ) {
        return $ret;
    }

    while (list(,$v) = each($tagprefix)) {
        $t2p[$v["tag"]] = $v["prefix"];
    }

    // we want headlines in the same order than in zids
    foreach ( $ret as $u_id => $headline ) {
        $new_ret[$tags[$u_id] . $u_id] = $t2p[$tags[$u_id]]. $headline;
    }
    return $new_ret;
}

/** GetFormatedConstants function
 * @param $constgroup
 * @param $format
 * @param $restrict_zids
 * @param $conds
 * @param $sort
 *  @todo Teach itemview to return array (instead of whole listing) and convert
 *        those functions (GetFormatedConstants() and GetFormatedItems())
 *        to itemviwew
 */
function GetFormatedConstants($constgroup, $format, $restrict_zids, $conds, $sort) {
    $conds = String2Conds( $conds );
    $sort  = String2Sort( $sort ? $sort : 'sort[0][const_pri]=a');
    $zids  = QueryConstantZIDs($constgroup, $conds, $sort, $restrict_zids);
    if ( $zids->count() <= 0 ) {
        return false;
    }

    $content = GetConstantContent($zids);
    $item    = new AA_Item();
    $format = $format ? $format : 'const_name';
    for ( $i=0; $i<$zids->count(); $i++ ) {
        $iid = $zids->short_or_longids($i);
        $item->set_data($content[$iid]);
        $ret[$item->subst_alias('const_value')] = $item->subst_alias($format);
    }
    return $ret;
}

/** GetItemFromContent function
 * Creates item object just from item_content
 * @param $content  - itemcontent object
 */
function GetItemFromContent($content) {
    if ( !is_object($content) ) {
        return null;
    }
    // reuse slice, if possible
    $slice = AA_Slices::getSlice($content->getSliceID());
    return new AA_Item($content->getContent(),$slice->aliases());
}

class AA_Item {
    var $content4id;        // ItemContent array for this Item (like from GetItemContent)
    var $clean_url;      //
    var $format;         // format string with aliases
    var $remove;         // remove string
    var $aliases;        // array of usable aliases
    var $parameters;     // optional additional parameters - copied from itemview->parameters

    /** item function
     *  Constructor
     *  @param $content4id could be ItemContent as well as zids as well as old
     *                     contentent array
     *  @param $aliases
     *  @param $format
     *  @param $remove
     *  @param $param
     *
     *  Take a look at AA_Item::getItem() (non caching) or
     *  AA_Items::getItem() (caching), if you want to create item from id
     */
    function AA_Item($content4id='', $aliases='', $format='', $remove='', $param=false){
        // there was three other options, but now it was never used so I it was
        // removed: $item_content, $top and $bottom (honzam 2003-08-19)
        $this->set_data($content4id);
        $this->aliases      = $aliases;
        $this->format       = $format;
        $this->remove       = $remove;
        $this->parameters   = ( $param ? $param : array() );
    }

    /** setformat function
     * @param $format
     * @param $remove
     */
    function setformat( $format, $remove="") {
        $this->format = $format;
        $this->remove = $remove;
    }

    /** set_parameters function
     *  Optional asociative array of additional parameters
     *  Used for category_id (in Links module) ...
     * @param $parameters
     */
    function set_parameters($parameters) {
        $this->parameters = $parameters;
    }

    /** set_data function
     * sets content (ItemContent object or older - content4id array)
     * @param $content4id
     */
    function set_data($content4id) {
        $this->content4id = (is_object($content4id) ? $content4id : new ItemContent($content4id));
    }

    /** set_field_value function
     *  sets for defined field it's new value
     * @param $field
     * @param $value
     */
    function set_field_value($field, $value) {
        $this->content4id->setValue($field, $value);
    }


    /** getval function
     * shortcut for AA_Content->getValue()
     * @param $field_id
     * @param $what
     */
    function getval($field_id) {
        return $this->content4id->getValue($field_id);
    }

    /** getFlag function
     * shortcut for AA_Content->getFlag()
     * @param $field_id
     * @param $what
     */
    function getFlag($field_id) {
        return $this->content4id->getFlag($field_id);
    }

    /** getValues function
     * shortcut for ItemContent->getValues()
     * @param $field_id
     */
    function getValues($field_id) {
        return $this->content4id->getValues($field_id);
    }

    /** getValues function
     * shortcut for ItemContent->getValues()
     * @param $field_id
     */
    function getValuesArray($field_id) {
        return $this->content4id->getValuesArray($field_id);
    }

    /** getAaValue function
     * @param $field_id
     */
    function getAaValue($field_id){
        return $this->content4id->getAaValue($field_id);
    }

    /** checks, if the field looks like the field ID */
    function isField($text) {
        return $this->content4id->isField($text);
    }

    /** getContent function */
    function getContent() {
        return $this->content4id->getContent();
    }

    /** getItemContent function */
    function getItemContent() {
        return $this->content4id;
    }

    /** getItemID function */
    function getItemID() {
        return $this->content4id->getItemID();
    }

    /** getId */
    function getId() {
        return $this->content4id->getId();
    }

    /** getSliceID function
     *
     */
    function getSliceID() {
        return $this->content4id->getSliceID();
    }

    /** getWidgetAjaxHtml function
     *  returns HTML code for Ajaxian Html input for the field_id
     * @param $field_id
     */
    function getWidgetAjaxHtml($field_id) {
        $tmpobj = AA_Slices::getSlice($this->getSliceID());
        return $tmpobj->getWidgetAjaxHtml($field_id, $this->getItemID());
    }

    /** getbaseurl function
     * @param $redirect
     * @param $no_sess
     */
    function getbaseurl($redirect, $no_sess) {
        global $sess;
        /*  old version - it prints whole url (http://...). Current uses url as
        short as possible - like "?x=445445"

        $url_base = ($redirect ? $redirect : $this->clean_url );
        if ( $no_sess ) {                     //remove session id
            $pos = strpos($url_base, '?');
            if ($pos) {
                $url_base = substr($url_base,0,$pos);
            }
        }
        */

        // redirecting to another page?
        $url_base = ( $redirect ? $redirect :
        (($no_sess OR !is_object($sess))  ? '' : $sess->url('')) );

        // add state variable, if defined (apc - AA Pointer Cache)
        if ( $GLOBALS['apc_state'] AND $GLOBALS['apc_state']['state'] ) {
            $url_base = con_url( $url_base, 'apc='.$GLOBALS['apc_state']['state'] );
        }
        return $url_base;
    }

    /** getitemurl function
     * get item url - take in mind: item_id, external links and redirection
     * @param $extern
     * @param $extern_url
     * @param $redirect
     * @param $condition
     * @param $no_sess
     */
    function getitemurl($extern, $extern_url, $redirect, $condition=true, $no_sess=false) {
        if ( $extern ) {      // link_only
            return ($extern_url ? $extern_url : NO_OUTER_LINK_URL);
        }
        if ( !$condition ) {
            return false;
        }

        $url_param = ( $GLOBALS['USE_SHORT_URL'] ?
                                    "x=". $this->getval('short_id........') :
                                    "sh_itm=". $this->getItemID());

        return con_url( $this->getbaseurl($redirect, $no_sess), $url_param );
    }

    /** getahref function
     * get link from url and text
     * @param $url
     * @param $txt
     * @param $add
     * @param $html
     * @param $hide
     */
    function getahref($url, $txt, $add="", $html=false, $hide=false) {
        if ( $url AND $txt ) {
            // repair url if user omits to write http://
            if ( substr($url,4)=='www.' ) {
                $url = 'http://'.$url;
            }
            // hide email from spam robots
            if ($hide=='1') {
                $linkpart=explode('@',str_replace("'", "\'", $url.'@'.DeHtml($txt, $html)),4);
                $ret = "<script language=\"JavaScript\" type=\"text/javascript\">\n<!--\ndocument.write('<a href=\"".$linkpart[0]."'+'@'+'".$linkpart[1]."\" ".$add.">". $linkpart[2];
                if ($linkpart[3]) {
                    $ret .= "'+'@'+'".$linkpart[3];
                }
                $ret .= "</a>')\n// -->\n</script>";
                return $ret;
            } else {
                return '<a href="'. $url ."\" $add>". DeHtml($txt, $html).'</a>';
            }
        }
        return DeHtml($txt,$html);
    }

    /** get_alias_subst function
     * @param $alias
     * @param $use_field
     */
    function get_alias_subst( $alias, $use_field="" ) {


        // use_field is for expanding aliases with loop - prefix {@
        $ali_arr = $this->aliases[$alias];
        // is this realy alias?

        if ( !is_array($ali_arr) ) {
            // try alternative alias (old form of _#ITEM_ID_ alias was _#ITEM_ID#. It was bad for
            // unaliasing with colon ':', so we change it, but for compatibility we have to test _#ITEM_ID# too)
            if ( ! ((substr($alias,9,1)=='#') AND is_array($ali_arr = $this->aliases[substr($alias,0,9).'_']))) {
                return $alias;
            }
        }

        // get from "f_d:mm-hh" array fnc="f_d", param="mm-hh"
        $function = ParseFnc($ali_arr['fce']);
        $fce      = $function['fnc'];



        if (!is_callable(array($this,$fce))) {
            return _m('Error: wrong alias function %1 for %2', array($fce,$alias));
        }

        // call function (called by function reference (pointer))
        // like f_d("start_date......", "mm-dd")
        $field_id = get_if($use_field, $ali_arr['param']);
        $param    = str_replace('_#this', '{'.$field_id.'}', $function['param']);
        return call_user_func_array( array($this,$fce), array($field_id, $param));
    }

    /** remove_string function
     * @param $text
     * @param $remove_arr
     */
    function remove_strings( $text, $remove_arr ) {
        if ( is_array($remove_arr) ) {
            $text = str_replace($remove_arr, "", $text);
        }
        return $text;
    }

    /** substitute_alias_and_remove function
     * the function substitutes all _#... aliases and then applies "remove strings"
     * it searches for removal just in parts where all aliases are expanded
     * to empty string
     * @param $text
     * @param $remove_arr
     */
    function substitute_alias_and_remove( $text, $remove_arr=null ) {
        $piece = explode( "_#", $text );
        reset( $piece );
        $out = current($piece);   // initial sequence
        while ( $vparam = next($piece) ) {

            //search for alias definition (fce,param,hlp)
            $substitution = $this->get_alias_subst( "_#".(substr($vparam,0,8)));
            if ( $substitution != "" ) {   // alias produced some output, so we can remove
                // strings in previous section and we can start new
                // section
                $clear_output .= $this->remove_strings($out,$remove_arr).$substitution;
                $out = substr($vparam,8);         // start with clear string
            } else {
                $out .= substr($vparam,8);
            }
        }
        return $clear_output . $this->remove_strings($out,$remove_arr);
    }

    /** unalias function
     * @param $text
     * @param $remove
     */
    function unalias( &$text, $remove="" ) {
        return AA_Stringexpand::unalias($text, $remove, $this);
    }

    /** subst_alias function
     * @param $text
     */
    function subst_alias( $text ) {
        return ($this->isField($text) ? $this->getval($text) : $this->unalias($text));
    }
    /** subst_aliases function
     * @param $var
     */
    function subst_aliases( $var ) {
        if ( !is_array( $var ) ) {
            return $this->subst_alias( $var );
        }
        foreach ( $var as $k => $v ) {
            $ret[$k] = $this->subst_alias( $v );
        }
        return $ret;
    }

    // --------------- functions called for alias substitution -------------------

    /** f_0 function
     * null function
     * param: 0
     * @param $col
     * @param $param
     */
    function f_0($col, $param="") {
        return "";
    }

    /** f_h function
     * print due to html flag set (escape html special characters or just print)
     * @param $col
     * @param $param delimiter - used to separate values if the field is multi
     */
    function f_h($col, $param="") {
        if ( $param ) {
            $values = $this->getValues($col);
            if (empty($values)) {
                return '';
            }
            // create list of values for multivalue fields
            $param = $this->subst_alias( $param );
            foreach ( $values as $v ) {
                $res .= ($res ? $param : ''). DeHtml($v['value'], $v['flag']); // add value separator just if field is filled
            }
            return $res;
        }

        return DeHtml($this->getval($col), $this->getFlag($col));
    }

    /** f_d function
     * prints date in user defined format
     * @param $param date format like in PHP (like "m-d-Y")
     */
    function f_d($col, $param="") {
        $param = $this->subst_alias( $param );
        if ( $param=="" ) {
            $param = "m/d/Y";
        }
        // we check, if the value is not so big (becauce we solved problem, when
        // the date was entered as 230584301025887115 - which is too big and it
        // takes ages for PHP to evaluate the date() function then. (php 5.2.6))
        // it is perfectly possible to increase the max value, however
        $dstr = date($param, (int)max(-2147483647,min(2147483648,$this->getval($col))));
        return (($param != "H:i") ? $dstr : ( ($dstr=="00:00") ? "" : $dstr ));
    }

    /** f_i function
     * prints image scr (<img src=...) - NO_PICTURE for none
     * param: 0
     * @param $col
     * @param $param
     */
    function f_i($col, $param="") {
        return get_if( $this->getval($col), NO_PICTURE_URL);
    }

    /** f_y function
     *  expands and prints a string, if parameters are blank then expands field
     * @param $col
     * @param $param
     */
    function f_y($col, $param="") {
        $str2unalias = $param ? $param : DeHtml($this->getval($col), $this->getFlag($col));
        return $this->unalias( $str2unalias );
    }

    /** i_s function
     * prints height and width of image file or URL referenced in field
     * Could be special case if in uploads directory, so can read directly
     * @param $col
     * @param $param
     */
    function i_s($col, $param="") {
        // No warning required, will be generated by getimagesize
        list( $ptype ) = $this->subst_aliases( ParamExplode($param) );
        if ( !in_array($ptype, array('width', 'height', 'imgtype', 'mime', 'html', 'htmlb')) ) {
            $ptype = 'hw';
        }
        return AA_Stringexpand_Img::expand($this->getval($col), '', $ptype);
    }

    /** f_n function
     * prints unpacked id
     * param: 0
     * @param $col
     * @param $param
     */
    function f_n($col, $param="") {
        return unpack_id( $this->getval($col) );
    }

    /** f_g function
     * prints image height atribut (<img height=...) or clears it
     * param: 0
     * @param $col
     * @param $param
     */
    function f_g($col, $param="") {    // image height
        global $out;
        if ( !$this->getval($col) ) {
            $out = ERegI_Replace( "height[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete height = x
            return false;
        }
        return htmlspecialchars($this->getval($col));
    }

    /** f_w function
     * prints image width atribut (<img width=...) or clears it
     * param: 0
     * @param $col
     * @param $param
     */
    function f_w($col, $param="") {    // image width
        global $out;
        if ( !$this->getval($col) ) {
            $out = ERegI_Replace( "width[[:space:]]*=[[:space:]]*[\"]?^", "", $out );  // delete width = x
            return false;
        }
        return htmlspecialchars($this->getval($col));
    }

    /** f_a function
     *  Prints abstract ($col) or grabed fulltext text from field_id
     *  param: length:field_id:paragraph
     *         length    - max number of characters taken from field_id
     *                     (like "80:full_text.......")
     *         field_id  - field from which we grab the paragraph, if $col is
     *                     empty. If we do not specify field_id or we specify
     *                     the same field as current $col, then the content is
     *                     grabbed from current $col
     *         paragraph - boolean - if true, it tries to identify return only
     *                     first paragraph or at least stop at the end of sentence
     * @param $col
     * @param $param
     */
    function f_a($col, $param="") {
        list(         , $field )               = ParamExplode($param);  // we need $field unexpanded
        list( $plength, $pfield, $pparagraph ) = $this->subst_aliases( ParamExplode($param) );
        $value = $this->getval($col);
        if ($value AND !($col == $field)) {  // special case - return whole field
            return DeHtml( $value, $this->getFlag($col) );
        }

        if ($pparagraph) {
            $shorted_text = AA_Stringexpand_Shorten::expand(get_if($value, $pfield), $plength);
        } else {
            $shorted_text = substr($value, 0, $plength);
        }
        return strip_tags( $shorted_text );
    }

    /** f_f function
     * prints link to fulltext (hedline url)
     *  col: hl_href.........
     *  param: link_only:redirect
     *     link_only field id (like "link_only.......")
     *     redirect - url of another page which shows the content of item
     *              - this page should contain SSI include ../slice.php3 too
     *     no_sess  - if true, it does not add session id to url
     * @param $col
     * @param $param
     */
    function f_f($col, $param="") {
        list($plink, $predir, $psess) = $this->subst_aliases(ParamExplode($param));
        return $this->getitemurl($plink, $this->getval($col), $predir, 1, $psess);
    }

    /** f_b function
     * prints text with link to fulltext (hedline url)
     * param: link_only:url_field:redirect:txt:condition_fld
     *    link_only     - field id (like "link_only.......")
     *    url_field     - field id of external url for link_only
     *                  - (like hl_href.........)
     *    redirect      - url of another page which shows the content of item
     *                  - this page should contain SSI include ../slice.php3 too
     *    txt           - if txt is field_id content is shown as link, else txt
     *    condition_fld - field id - if no content of this field, no link
     *    addition      - additional parameter to <a tag (like target=_blank)
     *    no_sess  - if true, it does not add session id to url
     * @param $col
     * @param $param
     */
    function f_b($col, $param="") {
        $p = ParamExplode($param);
        list ($plink_only, $purl_field, $predirect, $ptxt, $pcondition, $paddition, $pno_sess) = $this->subst_aliases($p);

        if (!$p[4]) {          // undefined condition parameter
            $pcondition = true;
        }

        // last parameter - condition field
        $url = $this->getitemurl($plink_only, $purl_field, $predirect, $pcondition, $pno_sess);
        $flg = ( $this->content4id->is_set($p[3]) ? $this->getFlag($p[3]) : true );
        return $this->getahref($url,$ptxt,$paddition,$flg);
    }

    // prints 'blurb' (piece of text) based from another slice,
    // based on a simple condition.
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


    /** f_q function
     * returns fulltext of the blurb
     * @param $col
     * @param $param
     */
    function f_q($col, $param="") {
        /* Usually this is called with no parameters.
        Optional parameters for f_q are:
        [0] stringToMatch is by default $col
        It can be formatted either as the name of a field in self->content4id OR
        as static text.
        [1] blurbSliceId  is by default the non-packed id in BLURB_SLICE_ID
        [2] fieldToMatch  is by default BLURB_FIELD_TO_MATCH
        [3] fieldToReturn is by default BLURB_FIELD_TO_RETURN
        these constants should be defined in include/config.php3
        */

        $p = ParamExplode($param);
        $stringToMatch_Raw = $p[0] ? $p[0] : $col;
        // can use either the 'headline......' format or "You static text here"
        $stringToMatch   = get_if( $this->getval($stringToMatch_Raw), $stringToMatch_Raw);

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
        if (($fieldToReturn == "id..............") OR ($fieldToReturn == "short_id........")) {
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


    /** RSS_restrict function
     *  This function used to do a UTF8 encode, but since characters
     *  are coming out of a DB which I believe is iso-8859-1, they shouldn't
     *  be utf8 encoded. Unfortunately the internationalisation of AA isn't
     *  documented anywhere and noone responded to email about it. So this is
     *  just a guess. If anyone who knows something about this is looking
     *  at this comment, then you can replace this code with something
     *  that intelligently looks at the database encoding before converting to
     *  iso-8859-1. OR possibly better, output the encoding in the head of the RSS
     *  via  a new alias - mitra@mitra.biz 30Oct03
     * @param $txt
     * @param $len
     */
    function RSS_restrict($txt, $len) {
        //    return utf8_encode(htmlspecialchars(substr($txt,0,$len)));
        return htmlspecialchars(substr($txt,0,$len));
    }

    /** f_r function
     *  standard aliases to generate RSS .91 compliant meta-information
     * @param $col
     * @param $param
     */
    function f_r($col, $param="") {
        static $title, $link, $description, $q_owner;

        if ($col == 'SLICEdate') {
            return $this->RSS_restrict( GMDate("D, d M Y H:i:s "). "GMT", 100);
        }

        $slice_id   = $this->getSliceID();
        $p_slice_id = q_pack_id($slice_id);

        if (! $title) {
            if ($slice_id==""){ echo "Error: slice_id not defined"; exit; }

            // RSS chanel (= slice) info
            $SQL = "SELECT name, slice_url, owner FROM module WHERE id='$p_slice_id'";

            $db  = getDB(); $db->query($SQL);
            if (!$db->next_record()) {
                echo "Can't get slice info"; exit;
            }

            $title       = $this->RSS_restrict( $db->f('name'), 100);
            $link        = $this->RSS_restrict( $db->f('slice_url'), 500);
            $name        = $db->f('name');
            $q_owner     = addslashes($db->f('owner'));
            //$language  = RSS_restrict( strtolower($db->f(lang_file)), 2);
        }

        //   return "tt: $col : $param<BR>";
        if ($col == 'SLICEtitle') {
            return $title;
        }
        if ($col == 'SLICElink') {
            return $link;
        }
        if ($col == 'SLICEdesc') {
            if (!$description) {
                $owner_name  = GetTable2Array("SELECT name FROM slice_owner WHERE id='$q_owner'",'aa_first','name');
                $description = $this->RSS_restrict( $owner_name, 400 ).": $title";
            }
            return $description;
        }

        $p = ParamExplode($param);

        if ($col == 'hl_href.........' && strlen($p[0]) == 16) {
            $redirect  = $p[1] ? $p[1] :
                                        strtr(AA_INSTAL_URL, ':', '#:') .
                                        "slice.php3?slice_id=$slice_id&encap=false";
            //      return strtr( $this->f_f($col, $p[0] . ':' . $redirect), '#/', ':/') ;
            return $this->RSS_restrict(strtr( $this->f_f($col, $p[0] . ':' . $redirect), '#/', ':/'),500) ;
        }

        if (strpos($p[0],"{") !== false) {
            // It can't be a field, must be expandable
            return $this->RSS_restrict(strtr( $this->unalias($p[0]), '#/', ':/'),500) ;
        }
        if ( $foo = $this->getval($col)) {
            return $this->RSS_restrict( $foo, $p[0]);
        }
    }

    /** f_t function
     *  prints the field content and converts text to html or escape html (due to
     *  html flag). If param is specified, it prints rather param (instead of field)
     *
     *  f_t looks to see if param is a field, and if so gets the value, and
     *   if it doesn't look like a field, then it unaliases the param
     *  If there is no param, then it converts the field to HTML (if text)
     * @param $col
     * @param $param string to be printed (like <img src="{img_src........1}"></img>
     */
    function f_t($col, $param="") {
        $p = ParamExplode($param);
        if ( isset($p[1]) ) {
            $text  = get_if( $p[0], $this->getval($col) );
            $modif = $p[1];
            if (in_array($modif, array('csv', 'safe', 'javascript', 'urlencode', 'striptags', 'rss', 'conds', 'asis', 'substitute', 'debug'))) {
                return call_user_func( array( AA_Object::constructClassName('AA_Stringexpand_', $modif), 'expand'), $text);
            }
            if ($p[1]=='') {
                $param = $p[0];
            }
        }
        return $param ? $this->subst_alias( $param ): DeHtml($this->getval($col), $this->getFlag($col));
    }

    /** f_s function
     * print database field or default value if empty
     * @param $col
     * @param $param default (like "javascript: window.alert('No source url specified')")
     */
    function f_s($col, $param="") {
        return ( $this->getval($col) ? $this->getval($col) : $param);
    }

    /** f_l function
     * prints $col as link, if field_id in $param is defined, else prints just $col
     * @param $col
     * @param $param field_id         - of possible link (like "source_href.....")
     *               additional atrib - for <a> tag
     */
    function f_l($col, $param="") {
        list($plink, $padditional) = $this->subst_aliases( ParamExplode($param) );
        return $this->getahref($plink, $this->getval($col), $padditional,$this->getFlag($col));
    }

    /** f_e function
     * If someone understands the parameter wizzard, it would be good to expand this to take a second parameter
     * i.e. return url.
     * _#EDITITEM used on admin page index.php3 for itemedit url
     * param: 0
     * @param $col
     * @param $param
     */
    function f_e($col, $param="") {
        global $sess;

        $p = ParamExplode($param);  // 0 = disc|itemcount|safe|slice_info  //2 = return_url
        switch( $p[0]) {
            case "session":
                return $sess->id;
            case "disc":
                // _#DISCEDIT used on admin page index.php3 for edit discussion comments
                return con_url($sess->url("discedit.php3"), "item_id=". $this->getItemID());
                // These next two return values from globals that would actually be better coming froom the item or itemview,
                // otherwise won't work if there are nested views.
            case "itemcount":
                return (string)$GLOBALS['QueryIDsCount'];
            case "itemindex": /*mimo hack, this is now a stack*/
                return "".end($GLOBALS['QueryIDsIndex']);   // Need to append to "" so doesn't return "false" on 0th item
            case "pageindex": /*mimo hack, this is now a stack*/
                return "".end($GLOBALS['QueryIDsPageIndex']);   // Need to append to "" so doesn't return "false" on 0th item
            case "groupindex": /*mimo hack, this is now a stack*/
                return "".end($GLOBALS['QueryIDsGroupIndex']);   // Need to append to "" so doesn't return "false" on 0th item
            case "itemgroupindex": /*mimo hack, this is now a stack*/
                return "".end($GLOBALS['QueryIDsItemGroupIndex']);   // Need to append to "" so doesn't return "false" on 0th item
            case "safe":         // safe, javascript, urlencode, csv - for backward
            case "javascript":   // compatibility
            case "urlencode":
            case "csv":
                return $this->f_t($col,":".$p[0]);
            case "slice_info":
                return AA_Stringexpand_Modulefield::expand(get_if($this->getSliceID(),$GLOBALS['slice_id']), $col);
            case "link_edit":
                return (($p[1]=='anonym') ?
                get_aa_url('modules/links/linkedit.php3?free=anonym&freepwd=anonym&lid='. $this->getval('id')) :
                get_aa_url('modules/links/linkedit.php3?lid='. $this->getval('id')) );
            case "poll_edit":
                return get_aa_url('modules/polls/polledit.php3?poll_id='. $this->getval('id'));
            case "link_go_categ":
                $cat_names            = $this->getValues('cat_name');
                $cat_ids              = $this->getValues('cat_id');
                $cat_highlight        = $this->getValues('cat_state');
                $cat_proposal         = $this->getValues('cat_proposal');
                $cat_proposal_delete  = $this->getValues('cat_proposal_delete');
                if ( empty($cat_names) ) {
                    return "";
                }
                $delim = '';
                foreach($cat_names as $k => $cat) {
                    $print_cname = $cat['value'];
                    if ( $cat_highlight[$k]['value']=='highlight' ) {
                        $print_cname = "<b>$print_cname</b>";    // mark highlighted categories
                    }
                    if ( $cat_proposal[$k]['value']=='y' ) {
                        $print_cname = "$print_cname (+)";       // mark proposals
                    }
                    if ( $cat_proposal_delete[$k]['value']=='y' ) {
                        $print_cname = "$print_cname (-)";       // mark proposals for deletion
                    }
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
                // we can compare short_ids as well as long ones
                $data2compare = false;
                if (($guesstype = guesstype($p[1])) == 's') {
                    $data2compare = $this->getval('short_id........');
                } elseif ($guesstype == 'l') {
                    $data2compare = $this->getval('unpacked_id.....');
                }
                // no short_id or long_id - it must be a constant
                if ( !$data2compare ) {
                    $data2compare = $this->getval('const_value');
                }
                return (( (string)$p[1] == (string)$data2compare ) ? '1' : '0');
            case 'username':    // prints user name from its id
                return perm_username( $this->getval($col) );
            case 'mlx_lang':    // print the current mlx language (the desired or default one instead of the lang_code...)
                if (!$GLOBALS['mlxView']) {
                    return "MLX no global mlxView set: this shouldnt happen in:".__FILE__.",".__LINE__;
                }
                return $GLOBALS['mlxView']->getCurrentLang();
            case 'mlx_dir':    // print the current mlx language html markup dir tag
                //(the article's 'real' one!)
                $mlx_dir = $GLOBALS['mlxScriptsTable'][$this->getval('lang_code.......')];
                return ($mlx_dir ? " DIR=".$mlx_dir['DIR']." " : "");
            case 'addform':   // show link to inputform with special design defined in view (id in p[1])
                $add = true;
                // drop through to default
            case 'editform':
                return Inputform_url($add, $this->getItemID(), $this->getSliceID(), $p[2], $p[1]);
            case "add":
                $add = true;
                // drop through to default
            default:
                return Inputform_url($add, $this->getItemID(), $this->getSliceID(), $p[1]);
        }
    }

    /** f_c function
     *  prints "begin".$col."end" if $col="condition", else prints "none"
     *  if no cond_col specified - $col is used
     *  if pskip_col == 1, skips $col
     *  param: condition:begin:end:none:cond_col
     *  if pararam begins with "!", condition is negated
     * @param $col
     * @param $param
     */
    function f_c($col, $param="") {
        if ( $param[0]=="!" ){
            $param = substr($param, 1);
            $negate=true;
        }

        $p = ParamExplode($param);

        list ($pcond, $pbegin, $pend, $pnone, $pccol, $pskip_col) = $this->subst_aliases($p);

        $cond = ( $p[4] ? $pccol : $this->subst_alias($col) );
        if ( $cond != $pcond ) {
            $negate = !$negate;
        }
        if (!$pskip_col) {
            $coltxt = DeHtml($this->getval($col), $this->getFlag($col));
        }
        return  ($negate ? $pnone : $pbegin. $coltxt .$pend);
    }

    /** f_u function
     * Calls user defined function in file /include/usr_aliasfnc.php3
     * @param $col
     * @param $param
     */
    function f_u($col, $param="") {
        // first we substitute alias on whole param string
        // (it will be passed to usr_function already substituted
        $param = $this->subst_alias($param);
        $p = ParamExplode($param);
        $fnc = $p[0];
        if (!is_callable($fnc)) {
            huhl("Field $col is defined with f_u and function '$fnc', but '$fnc' is not defined in apc-aa/include/usr_aliasfnc.php3");
            return "";
        }
        return $fnc($this->content4id->getContent(), $col, $param);
    }

    /** f_v function
     * display specified view
     *  param:
     *     link_only     - field id (like "link_only.......")
     *     url_field     - field id of external url for link_only
     *                   - (like hl_href.........)
     *     redirect      - url of another page which shows the content of item
     *                   - this page should contain SSI include ../slice.php3 too
     *     txt           - if txt is field_id content is shown as link, else txt
     *     condition_fld - field id - if no content of this field, no link
     *     addition      - additional parameter to <a tag (like target=_blank)
     * @param $col
     * @param $param
     */

    function f_v($col, $param="") {
        global $vid, $als, $conds, $param_conds, $item_ids, $use_short_ids;

        // if no parameter specified, the content of this field specifies view id
        if ( !$param ) {
            $param = "vid=".$this->getval($col);
        }

        // older aliases substitution ------------- (_#publish_date....) -----------
        // substitute aliases by real item content
        $part = $param;

        // If you change this to support more items, please change FAQ item //1488
        while ( $part = strstr( $part, "_#" )) {  // aliases for field content
            $fid = substr( $part, 2, 16 );         // looks like _#headline........

            if ( substr( $fid, 0, 5) == "slice" ) {  // Another special alias _#slice
                //Mitra says: looks like this mucks up _#slice_id........
                $param = str_replace("_#slice", $this->getval('slice_id........'), $param);
            }
            elseif( $fid == 'unpacked_id.....' ) {
                $param = str_replace( "_#$fid", $this->f_n('id..............'), $param );
            }
            elseif( $this->isField($fid) ) {
                $param = str_replace( "_#$fid", $this->f_h($fid, "-"), $param );
            }
            $part = substr( $part, 6 );
        }

        // unalias new ------------ ({publish_date....}, _#ITEM_ID_, ...) -----------
        $param = $this->subst_alias($param);
        return GetView(ParseViewParameters($param));
    }

    /** f_m function
     *  mailto link
     * prints: "begin<a href="mailto:$col">field/text</a>"
     * if no $col is filled, prints "else_fileld/text"
     * param: begin:field/text:else_fileld/text
     * linktype: mailto/href (default is mailto)
     * @param $col
     * @param $param
     */
    function f_m($col, $param="") {
        $p = ParamExplode($param);
        list ($pbegin, $pfield, $pelse, $ptype, $padd, $phide) = $this->subst_aliases($p);

        if ( !$this->getval($col) ) {
            return $pelse ? $pbegin.$pelse : "";
        }
        if ( $this->content4id->is_set($p[1]) ) {
            $field_id = ($pfield ? $p[1] : $col);
            $txt = $this->getval($field_id);
            $flg = $this->getFlag($field_id);
        } else {
            $txt = ( $p[1] ? $pfield : $this->getval($col));
            $flg = ( $p[1] ? FLAG_HTML : $this->getFlag($col));
        }
        $linktype =  (($ptype && ($ptype!='mailto')) ? "" : "mailto:");
        return $pbegin.$this->getahref( $linktype.$this->getval($col), $txt, $padd, $flg, $phide);
    }

    /** f_j function
     *  substring with case conversion
     * param $start  - position, where to start the substring
     * param $n      - number of characters (<=0 means all characters to the end)
     * param $case   - convert to upper/lower/first
     * param $addstr - string to be added at the end of SHORTED string
     *                  (probably something like [...])
     * @param $col
     * @param $param
     */
    function f_j($col, $param="") {
        $p = ParamExplode($param);
        list ($start, $n, $case, $addstr) = $this->subst_aliases($p);

        $text = $this->getval($col);
        if ($n <= 0) {
            $n = strlen ($text);
        }
        $ret = substr($text,$start,$n);

        if ($case == "upper") {
            $ret = strtoupper ($ret);
        }
        elseif ($case == "lower") {
            $ret = strtolower ($ret);
        }
        elseif ($case == "first") {
            $ret = ucwords (strtolower ($ret));
        }

        if ( $addstr AND (strlen($ret) <> strlen($text))) {
            $ret .= $addstr;
        }
        return $ret;
    }

    /** f_k function
     *  live checkbox -- updates database immediately on clicking without reloading the page
     * @param $col
     * @param $param
     */
    function f_k($col, $param = "") {
        $short_id = $this->getval("short_id........");

        $name = "live_checkbox[".$short_id."][$col]";
        $img  = $this->getval($col) ? "on" : "off";
        return "<img width=\"16\" height=\"16\" name=\"$name\" border=\"0\" onClick='CallLiveCheckbox(\"$name\");'
                src=\"".AA_INSTAL_PATH."images/cb_".$img.".gif\" alt=\"".($this->getval($col) ? _m("on") : _m("off"))."\">";
    }

    /** f_x function
     * transformation function - transforms strings to another strings
     * Parameters: <from1>:<to1>:<from2>:<to2>:<default>
     *   if $col==<from1> the function returns <to1>
     *   if $col==<from2> the function returns <to2>
     *   else it returns <dafault>
     *   <to1>, <to2>, ... and <default> can be field ids
     * @param $col
     * @param $param
     */
    function f_x($col, $param="") {
        $p        = $this->subst_aliases( ParamExplode($param) );
        $to       = (int) floor(count($p)/2);
        $colvalue = $this->getval($col);

        for ( $i=0; $i < $to; $i++ ) {
            $first  = $i*2;
            $second = $first +1;
            if ( ($p[$first] != '') AND ereg( $p[$first] , $colvalue ) ) {
                return $p[$second];
            }
        }
        // the last option can be definned as default
        return $p[$second+1];
    }

    /** f_o function
     *  prints 'New' (or something similar) for new items
     * Parameters: <time_in_minutes>:<text_to_print_if_newer>:<text_to_print_if_older>
     *   (this alias will be probably used with expiry date field)
     * @param $col
     * @param $param
     */
    function f_o($col, $param="") {
        $p = $this->subst_aliases( ParamExplode($param) );
        return ((time() - $this->getval($col)) < $p[0]*60) ? $p[1] : $p[2];  // time in minutes
    }

    /** f_z function
     *  get the size or type of the file
     * @author Adam Sanchez
     * @param $col
     * @param $param
     */
    function f_z($col, $param="") {
        return AA_Stringexpand_Fileinfo::expand($this->getval($col),$param);
    }

    /** l_b function
     *  Link module (category) function - prints @ (or first parameter) when
     *  category is crossreferenced
     *  param <crossreference_character> - specify if you want another, than '@'
     * @param $col
     * @param $param
     */
    function l_b($col, $param="") {
        $p   = $this->subst_aliases( ParamExplode($param) );
        $way = explode(',', $this->getval($col)); // to get parent category
        return ( $way[count($way)-2] == $this->parameters['category_id']) ? '' : ($p[0] ? $p[0] : '@' );
    }

    /** l_g function
     * Link module (category) function - prints 1 when category is general one
     * @param $col
     * @param $param
     */
    function l_g($col, $param="") {
        return Links_IsGlobalCategory($this->getval($col)) ? '1' : '0';
    }

    /** l_o function
     * Link module (category) function - prints category priority, if category
     *  is general one
     * @param $col
     * @param $param
     */
    function l_o($col, $param="") {
        return Links_GlobalCatPriority($this->getval($col));
    }

    /** l_p function
     * Link module - print current path (or list of paths to categories specified
     *       in $col (when <categs delimiter> is present)
     *  param <start_level>:<format>:<delimiter>:<categs delimiter>:<add url param>:<url_base>:<restrict>
     *         <start_level> - display path from level ... (0 is root)
     *         <format>      - category link modification (not used, yet)
     *         <delimiter>   - delimiter character (default is ' &gt; ')
     *         <categs delimiter>   - delimiter character (<br> is good there)
     *         <add url param>      - url parameter added to cat=xxx (id=links)
     *         <url_base>    - file to go (like: kormidlo.shtml)
     *         <restrict>    - display only categories matching <restrict> path
     *                       - example: restict=1,23, - only categs under 23 shown
     * @param $col
     * @param $param
     */
    function l_p($col, $param="") {
        global $contentcache;

        $translate = $contentcache->get_result( 'GetTable2Array', array(
            "SELECT id, name FROM links_categories WHERE deleted='n'", 'id', true));

        list ($start, $format, $separator, $catseparator, $urlprm, $url_base, $restrict) = $this->subst_aliases(ParamExplode($param));
        if ( !$separator ) {
            $separator = ' &gt; ';
        }
        // $url_base = ''; //$this->getbaseurl();
        $urlprm = ($urlprm ? '&'.$urlprm : '');

        $categs2print = $catseparator ? $this->getValues($col) :
                                        array(0=>array('value'=>$this->parameters['category_id'])); // current category
        $linklast     = $catseparator ? true : false;

        $ret = '';
        foreach ( (array)$categs2print as $v ) {
            $path = GetCategoryPath($v['value']);
            if ($restrict AND (strpos($path,$restrict)!==0)) {
                continue;   // category is not on restriced branch
            }
            if ($ret) {
                $ret .= $catseparator;
            }
            $way = explode(',', $path);
            $start_count = $start;
            $delimiter = '';
            if ( isset($way) AND is_array($way)) {
                $last = $linklast ? '' : end($way);
                foreach ( $way as $catid ) {
                    if ($start_count-- > 0) {
                        continue;
                    }
                    $cat_url   = con_url( $url_base, 'cat='.$catid.$urlprm);
                    $ret      .= ( ( $catid == $last ) ?  // do not make link for last category
                        $delimiter.$translate[$catid]['name'] :
                        $delimiter."<a href=\"$cat_url\">".$translate[$catid]['name']."</a>" );
                    $delimiter = $separator;
                }
            }
        }
        return $ret;
    }

    // ----------------- alias function definition end --------------------------

    /** show_navigation function
     *  function shows full text navigation (back, home)
     * @param $home_url
     */
    function show_navigation($home_url) {
        echo '<br><a href="javascript:history.back()">'. _m("Back") .'</a> &nbsp; ';
        echo "<a href=\"$home_url\">". _m("Home") .'</a><br>';
    }

    /** get_item function
     * Get format string, unalias it and return (not clear why unaliased - Mitra)
     */
    function get_item() {
        // format string
        $out    = $this->format;
        $remove = $this->remove;
        $out    = $this->unalias($out, $remove);
        return $out;
    }

    /** Noncaching equivalent to AA_Items::getItem()
     *
     *  Static methods
     *  Creates item object just from item id and fills all necessary structures
     *
     *  @param  zid     - an item id - zid object, unpacked or short id
     *  @param  renew   - regenerate the item form database
     */
    function getItem($zid, $renew=false) {
        if (empty($zid)) {
            return false;
        }
        $zid   = (strtolower(get_class($zid))=='zids') ? $zid : new zids($zid);
        return GetItemFromContent(new ItemContent($zid));
    }
};

class AA_Items {
    /** Array of all items grabbed from database during rendering of the page
     *  Array short item id -> AA_Item object (short for smaller memmory ussage)
     */
    var $_i;

    /** translation table from long ids to short ones */
    var $_l2s;

    /** AA_Items function - constructor - called only from singleton() !
     */
    function AA_Items() {
        $this->_i = array();
    }

    /** singleton
     *  called from getSlice method
     *  This function makes sure, there is just ONE static instance if the class
     *  @todo  convert to static class variable (after migration to PHP5)
     */
    function singleton() {
        static $instance = null;
        if (is_null($instance)) {
            // Now create the AA_Items object
            $instance = new AA_Items;
        }
        return $instance;
    }

    /** Item caching function
     *
     *  This function is used for cahcing all the items which we need during
     *  page rendering. The main reason is, that we want to grab the item from
     *  database just once during the page creation.
     *
     *  Creates item object just from item id and fills all necessary structures
     *  @param  zid     - an item id - zid object, unpacked or short id
     *  @param  renew   - regenerate the item form database
     */
    function & getItem($zid, $crypted_additional_slice_pwd=null, $renew=false) {
        if (empty($zid)) {
            return false;
        }
        $items = AA_Items::singleton();

        $zid = (strtolower(get_class($zid))=='zids') ? $zid : new zids($zid);

        // Do we want to count with inner cache (probably yes)
        if (!$renew) {
            if ($item = $items->_getFromCache($zid)) {  // assignment
                return $item;
            }
        }

        $content = GetItemContent($zid, false, false, false, $crypted_additional_slice_pwd);
        return is_array($content) ? $items->_store(GetItemFromContent(new ItemContent(reset($content)))) : false;
    }

    /** @return array(long_id -> AA_Item) */
    function & getItems($zids, $crypted_additional_slice_pwd=null) {
        $items = AA_Items::singleton();

        // check for already cached items
        $zids2get = new zids(null, $zids->onetype());

        for ( $i=0; $i<$zids->count(); $i++ ) {
            if (!$items->_getFromCache($zids->zid($i))) {  // just check
                $zids2get->add($zids->id($i));
            }
        }

        // do we need some not cached items?
        if ($zids2get->count() > 0) {
            // yes we store it in the cache first
            $content = GetItemContent($zids2get, false, false, false, $crypted_additional_slice_pwd);
            for ( $i=0; $i<$zids2get->count(); $i++ ) {
                $items->_store(GetItemFromContent(new ItemContent($content[$zids2get->short_or_longids($i)])));
            }
        }

        // all is cached, we get all the items in RIGHT ORDER
        $ret = array();
        for ( $i=0; $i<$zids->count(); $i++ ) {
            $item = $items->_getFromCache($zids->zid($i));
            $ret[$item->getItemId()] = $item;       // long item id
        }
        return $ret;
    }

    /** returns AA_Item or false (if not cached) */
    function _getFromCache($zid) {
        if ( $zid->use_short_ids() ) {
            $id = $zid->id(0);
            // is it cached under its id (we expect short id here)
            return isset($this->_i[$id]) ? $this->_i[$id] : false;
        }

        // long or packed
        $id = $zid->longids(0);
        // long id, so we look for translation to short one
        if ( isset($this->_l2s[$id]) AND isset($this->_i[$this->_l2s[$id]]) ) {
            return $this->_i[$this->_l2s[$id]];
        }
        return false;
    }

    function _store($item) {
        $short_id                       = $item->getval('short_id........');
        $this->_i[$short_id]            = &$item;    // cache it
        $this->_l2s[$item->getItemId()] = $short_id;
        return $item;
    }
}
?>
