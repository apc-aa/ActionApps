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

# ----------------------------------------------------------------------------
#                         stringexpand
#
# Note that this is NOT defined as a class, and is called within several other classes
# ----------------------------------------------------------------------------

# Code by Mitra based on code in existing other files

require_once $GLOBALS["AA_INC_PATH"]."easy_scroller.php3";
require_once $GLOBALS["AA_INC_PATH"]."sliceobj.php3";
require_once $GLOBALS["AA_INC_PATH"]."perm_core.php3";   // needed for GetAuthData();

function translateString( $string, $translation ) {
    $twos = ParamExplode( $translation );
    $i=0;
    while( $i < count($twos) ) {
      if( $i == (count($twos)-1)) {                # default option
        return $twos[$i];
      }
      $val = trim($twos[$i]);
      # Note you can't use !$val, since this will match a pattern of exactly "0"
      if( ($val=="") OR ereg($val, $string) ) {    # Note that $string, might be expanded {headline.......} or {m}
        return $twos[$i+1];
      }
      $i+=2;
    }
    return "";
}

function parseSwitch($text) {
    $variable = substr(strtok('_'.$text,")"),1);   # add and remove '_' - this
                                                   # is hack for empty variable
                                                   # (when $text begins with ')')
    $variable = DeQuoteColons($variable);	# If expanded, will be quoted ()
    return translateString( $variable, strtok("") );
}

/** Expands {user:xxxxxx} alias - auth user informations (of current user)
*   @param $field - field to show ('headline........', 'alerts1....BWaFs' ...).
*                   empty for username (of curent logged user)
*                   'password' for plain text password of current user
*                   'permission'
*                   'role'
*/
function stringexpand_user($field='') {
    global $auth_user_info, $cache_nostore, $auth, $slice_id, $perms_roles;
    // this GLOBAL :-( variable is message for pagecache to NOT store views (or
    // slices), where we use {user:xxx} alias, into cache (AUTH_USER is not in
    // cache's keyString.
    // $auth_user_info caches values about auth user
    $cache_nostore = true;             // GLOBAL!!!
    switch ($field = trim($field)) {
        case '':         return get_if($_SERVER['PHP_AUTH_USER'],$auth->auth["uname"]);
        case 'password': return $_SERVER['PHP_AUTH_PW'];
        case 'role' : // returns users permission to slice
        case 'permission' :
                if( IfSlPerm($perms_roles['SUPER']['perm']) ) {
                    return 'super';
                } elseif( IfSlPerm($perms_roles['ADMINISTRATOR']['perm'] ) ) {
                    return 'administrator';
                } elseif( IfSlPerm($perms_roles['EDITOR']['perm'] ) ) {
                    return 'editor';
                } elseif( IfSlPerm($perms_roles['AUTHOR']['perm'] ) ) {
                    return 'author';
                } else {
                    return 'undefined';
                }
            break;
        default:
            // $auth_user_info caches user's informations
            if ( !isset($auth_user_info[$auth_user]) ) {
                $auth_user_info[$auth_user] = GetAuthData();
            }
//            huhl($auth_user_info, $auth_user);
            return get_if($auth_user_info[$auth_user]->getValue($field), "");
    }
}

/** Replace inputform field alias with the real core for the field, which is
 *  stored already in the pagecache
 *  @param $parameters - field id and other modifiers to the field
 */
function stringexpand_inputvar($field) {
    global $contentcache;
    $arg_list = func_get_args();   // must be asssigned to the variable
    // replace inputform field
    return $contentcache->get('inputvar:'. join(':',$arg_list));
}


/** Expands {formbreak:xxxxxx} alias - split of inputform into parts
 *  @param $part_name - name of the part (like 'Related Articles').
 */
function stringexpand_formbreak($part_name='') {
    $GLOBALS['g_formpart']++;  // Nothing to print, it just increments part counter
    if ( $part_name ) { // remember part name
        $GLOBALS['g_formpart_names'][$GLOBALS['g_formpart']] = $part_name;
    }
}

/** Expands {formpart:} alias - prints number of current form part */
function stringexpand_formpart($part_name='') {
    return get_if($GLOBALS['g_formpart'],'0');  // Just print part counter
}


# text = [ decimals [ # dec_point [ thousands_sep ]]] )
function parseMath($text) {
    // get format string, need to add and remove # to
    // allow for empty string
    $variable = substr(strtok("#".$text,")"),1);
    $twos = ParamExplode( strtok("") );
    $i=0;
    $key=true;
    while( $i < count($twos) ) {
     $val = trim($twos[$i]);
      if ($key)
        {
            if ($val) $ret.=str_replace("#:","",$val); $key=false;
        }
        else {	#$val=str_replace ("{", "", $val);
            #$val=str_replace ("}", "", $val);
            $val = calculate ($val); // defined in math.php3
            if ($variable) {
                $format=explode("#",$variable);
                $val = number_format($val, $format[0], $format[1], $format[2]);
            }
            $ret.=$val;
            $key=true;
        }
     $i++;
    }
    return $ret;
}

/** parseLoop - in loop writes out values from field
 */
function parseLoop($out, &$item) {
    global $contentcache;

    // alternative syntax {@field...} or {list:field...}
    if ( (substr($out,0,5) == "list:") ) {
        $out = '@'. substr($out,5);
    }

    // @field........... - without parameters
    if (strpos($out, ":") == false) {
        $field = substr($out, 1);
        $separator = ", "; // default separator
    } else { // with parameters
        // get field name
        $field = substr($out, 1, strpos($out, ":") - strpos($out, "@")-1);
        // parameters - first is separator, second is format string
        list($separator,$format_str) = ParamExplode(substr($out,strpos($out,":")+1));

        if (strpos($field, "(") == true) { // if we have special parameters - in () after field name
            // get this special parameters
            $param = substr($field, strpos($field, "(")+1,strpos($field, ")")-strpos($field, "(")-1);
            $params = explode(",",$param);
            // field name
            $field    = substr($field, 0, strpos($field, "("));
            $group_id = getConstantsGroupID($item->getval("slice_id........"), $field);
        }
    }
    $val = $item->getvalues($field);
    if (!is_array($val)) {
        return '';
    }

    if (!$format_str) { // we don't have format string, so we return
                        // separated values by $separator (default is ", ")
        foreach($val as $value) {
            $ret_str = $ret_str . ($ret_str ? $separator : "") . $value['value'];
        }
    } else { // we have format string
        if( !is_array($params) ) {
            // case if we have only one parameter for substitution
            foreach($val as $value) {
                $dummy = str_replace("_#1", $value["value"], $format_str);
                $ret_str = $ret_str . ($ret_str ? $separator : "") . $dummy;
            }
        } else {
            // case with special parameters in ()
            foreach($val as $value) { // loop for all values
                $dummy = $format_str; // make work-copy of format string
                for ($i=0; $i<count($params); $i++) { // for every special parameter do:
                    if (substr($params[$i],0,6) == "const_") {
                        // what we need some constants parameters ( like name, short_id, value, ...)
                        $what = substr($params[$i], strpos($params[$i], "_")+1);
                        if ($what == 'value') {
                            $par = $value['value']; // value is in $item, no need to use db
                        } else {
                            // for something else we need use db
                            $par = getConstantValue($group_id, $what, $value['value']);
                        }
                    } elseif (substr($params[$i],0,2) == "_#") { // special parameter is alias
                        /** alias could be used as:
                         *       {list:relation........(_#GET_HEAD): ,:_#1}
                         *  where _#GET_HEAD is alias defined somewhere in
                         *  current slice using f_t (for example):
                         *       {item:{loop............}:headline}
                         *  this displays all the related headlines delimeted
                         *   by comma
                         */
                        // we need set some special field, which will be changed to actual
                        // constant value
                        $item->set_field_value("loop............", $value['value']);
                        // get for this alias his output
                        $par = $item->get_alias_subst($params[$i],"loop............");
                    }
                    $dummy = str_replace("_#".($i+1), $par, $dummy);
                }
                $ret_str = $ret_str . ($ret_str ? $separator : "") . $dummy;
            }
        }
    }
    return $ret_str;
}

/* getConstantsGroupGroupID returns group id for specified field */
function getConstantsGroupID($slice_id, $field) {
    global $contentcache;
    // get constant group_id from content cache or get it from db
    $zids = new zids($slice_id, "p");
    $long_id = $zids->longids();
    // GetCategoryGroup looks in database - there is a good chance, we will
    // expand {const_*} very soon (again), so we cache the result for future
    $group_id = $contentcache->get_result("GetCategoryGroup", array($long_id[0], $field));
    // get values from contentcache or use GetConstants function to get it from db
    // $val = $contentcache->get_result("GetConstants", array($group_id, "pri", "value"));
    return $group_id;
}

/* getConstantValue returns $what (name, value, short_id,...) of constants with
   group $group and name $field_name) */
function getConstantValue($group, $what, $field_name) {
    global $contentcache;
    switch ($what) { // this switch is for future changes in this code
            case "name" :
            case "value" :
            case "short_id":
            case "description" :
            case "pri" :
            case "group" :
            case "class" :
            case "id" :
                // get values from contentcache or use GetConstants function to get it from db
                $val = $contentcache->get_result("GetConstants", array($group, "pri", $what));
                return $val[$field_name];
                break;
            default :
                return false;
                break;
        }
}

# Do not change strings used, as they can be used to force an escaped character
# in something that would normally expand it
$QuoteArray = array(":" => "_AA_CoLoN_",
        "(" => "_AA_OpEnPaR_", ")" => "_AA_ClOsEpAr_",
        "{" => "_AA_OpEnBrAcE_", "}" => "_AA_ClOsEbRaCe_");
$UnQuoteArray = array_flip($QuoteArray);


/** Substitutes all colons with special AA string and back depending on unalias
 *  nesting. Used to mark characters :{}() which are content, not syntax
 *  elements
 */
function QuoteColons($level, $maxlevel, $text) {
    global $QuoteArray, $UnQuoteArray;  // Global so not built at each call
    if ( $level > 0 ) {                 // there is no need to substitute on level 1
        return strtr($text, $QuoteArray);
    }

    // level 0 - return from unalias - change all back to ':'
    if ( ($level == 0) AND ($maxlevel > 0) ) { // maxlevel - just for speed optimalization
        return strtr($text, $UnQuoteArray);
    }
    return $text;
}

/** Substitutes special AA 'colon' string back to colon ':' character
 *  Used for parameters, where is no need colons are not parameter separators
 */
function DeQuoteColons($text) {
    return strtr($text, $GLOBALS['UnQuoteArray']);
}

/*
// In this array are set functions from PHP or elsewhere that can usefully go in {xxx:yyy:zzz} syntax
$GLOBALS[eb_functions] = array (
    fmod => fmod,    # php > 4.2.0
    substr => substr
);
*/


// Alternative is to create functions starting with "stringexpand_"
function stringexpand_testexpfnctn($var) { return "Just testing it".$var; }
function stringexpand_fmod($x,$y)        { return fmod($x,$y); }
function stringexpand_cookie($name)      { return $_COOKIE[$name]; }

function stringexpand_substr($string,$start,$length=999999999) {
    return substr($string,$start,$length);
}

function stringexpand_str_replace($search, $replace, $subject) {
    return str_replace($search, $replace, $subject);
}

function stringexpand_item($item_id,$field) {
    $zid  = new zids($item_id);
    $item = GetItemFromId($zid);
    return ( $item ? $item->subst_alias($field) : '');
}

/** Expand URL by adding session,
 *  also handle special cases like {sessurl:hidden}
 */
function stringexpand_sessurl($url) {
    global $sess;
    switch ($url) {
        case "hidden":
            return "<input type=\"hidden\" name=\"".$sess->name."\" value=\"".$sess->id."\">";
            break;
        default:
            return $sess->url($url);
    }
}


/** Return array of substitution pairs for dictionary, based on given dictionary
 *  slice, format string which defines the format and possible slice codnitions.
 *   [biom] => <a href="http://biom.cz">_#KEYWORD_</a>, ...
 */
function getDictReplacePairs($dictionary, $format, &$delimiters, $conds='') {
    // return array of pairs: [biom] => <a href="http://biom.cz">_#KEYWORD_</a>
    $replace_pairs = array();

    // conds string could contain also sort[] - if so, use conds also as $sort
    // parameter (the sort is grabbed form the string then)
    $sort     = (strpos( $conds, 'sort') !== false ) ? $conds : '';

    /** 'keywords........' field could contain multiple values. In this case we
     *  have to create pair for each of the word. The _#KEYWORD_ alias is then
     *  used in format string
     */
    $kw_item  = GetFormatedItems( $dictionary, "{@keywords........:##}_AA_DeLiM_$format", false, AA_BIN_ACTIVE, $conds, $sort);
    // above is little hack - we need keyword pair, but we want to call
    // GetFormatedItems only once (for speedup), so we create one string with
    // delimeter:
    //   BIOM##Biom##biom_AA_DeLiM_<a href="http://biom.cz">_#KEYWORD_</a>
    foreach ( $kw_item as $kw_string ) {
        list($keywords, $link) = explode('_AA_DeLiM_', $kw_string,2);
        $kw_array              = explode('##', $keywords);
        foreach ( (array)$kw_array as $kw ) {
            /*
            $search_kw - Replace inner delimiters from collocations (we suppose
            that the single words, compound words and also collocations will
            beare replaced) and add special word boundary in order to recognize
            text as the whole word - not as part of any word
            added by haha
             */
            $search_kw = 'AA#@'. strtr($kw, $delimiters) .'AA#@';
            $replace_pairs[$search_kw] = str_replace('_#KEYWORD_', $kw, $link);
            if ( ($first_upper=strtoupper($kw{0})) != $kw{0} ) {
                // do the same for the word with first letter in uppercase
                $kw{0} = $first_upper;
                $search_kw = 'AA#@'. strtr($kw, $delimiters) .'AA#@';
                $replace_pairs[$search_kw] = str_replace('_#KEYWORD_', $kw, $link);
            }
        }
    }
    return $replace_pairs;
}

/** It's necessary to select characters used as standard word delimiters
 *  Check the value of the string variable $delimiter_chars and correct it.
 *  Associative array $delimiters contains frequently used delimiters and it's
 *  special replace_strings used as word boundaries
 *  @author haha
 */
function define_delimiters()
{
    $delimiter_chars = "()[] ,.;:?!\"&'\n";
    for ($i=0; $i<strlen($delimiter_chars); $i++) {
        $index              = $delimiter_chars[$i];
        $delimiters[$index] = 'AA#@'.$index.'AA#@';
    }
    // HTML tags are word delimiters, too
    $delimiters['<'] = 'AA#@<';
    $delimiters['>'] ='>AA#@';
    /*
    Some HTML tags in text will be replaced with special strings
    beginning with '_AA_' and ending with '_ShCut'
    (see function makeAsShortcut())
    these special strings are taken as delimiters
    */
    $delimiters['_ShCut']='_ShCutAA#@';
    $delimiters['_AA_']='AA#@_AA_';
    return $delimiters;
}

/** Store $text in the $html_subst_arr array - used for dictionary escaping html
 *  tags
 */
function makeAsShortcut($text) {
    static $count=0;
    $shortcut = '_AA_'.$count.'_ShCut';
    $GLOBALS['html_subst_arr'][$shortcut] = stripslashes($text);
    $count++;
    return $shortcut;
}

/** Uses one slice ($dictionary) and replace any word which matches a word
 *  in dictionary by the text specified in $format.
 *  It do not search in <script>, <a>, <h*> tags and HTML tags itself.
 *  It also searches only for whole word (not word substrings)
 *  It is writen as quick as possible, so we do not use preg_replace for the
 *  main replaces (it is extremly slow for bigger dictionaries) - strtr used
 *  instead
 *  @author Honza Malik, Hana Havelková
 */
function stringexpand_dictionary($dictionaries, $text, $format, $conds='') {
    global $pagecache;

    // sometimes this function last to much time - try to extend it
    if (($max_execution_time = ini_get('max_execution_time')) > 0) {
        set_time_limit($max_execution_time+20);
    }

    $delimiters = define_delimiters();
    // get pairs (like APC - <a href="http://apc.org">APC</a>' from dict. slice
    // (we call it through the pagecache in order it is called only once for
    // the same parameters)
    $replace_pairs = $pagecache->cacheMemDb("getDictReplacePairs", array($dictionaries, $format, $delimiters, $conds), new CacheStr2find($dictionaries));

    // we do not want to replace text in the html tags, so we substitute all
    // html with "shortcut" (like _AA_1_ShCuT) and the content is stored in the
    // $html_subst_arr. Then it is used with replace_pairs to return back
    $GLOBALS['html_subst_arr'] = array();
    $search = array ("'<script[^>]*?>.*?</script>'sie",  // Strip out javascript
                     "'<h[1-6][^>]*?>.*?</h[1-6]>'sie",  // Strip out titles
                                                                                                                             // can't be nested
                     "'<a[^>]*?>.*?</a>'sie",            // Strip out links
                     "'<[\/\!]*?[^<>]*?>'sie");          // Strip out HTML tags

    $replace = array ("makeAsShortcut('\\0')", "makeAsShortcut('\\0')",
                      "makeAsShortcut('\\0')", "makeAsShortcut('\\0')");

    // substitute html tags with shortcuts
    // (= remove the code where we do not want replace text)
    $text = preg_replace($search, $replace, $text);

    // Insert special string before the beginning and after the end of the text
    // Replacing all delimiters with special strings!!!
    $text = 'AA#@'.strtr($text, $delimiters).'AA#@';

    // add shortcuts also to the replace_pairs, so all is done in one step
    $replace_pairs = array_merge($replace_pairs, $GLOBALS['html_subst_arr']);
    // do both: process dictionary words and put back the shortcuted text
    $text = strtr($text, $replace_pairs);

    unset($GLOBALS['html_subst_arr']);         // just clean up

    // finally - removing additional vaste text 'AA#@' - recovering original
    // word delimiters
    $text = str_replace('AA#@', '', $text);
    return $text;
}

/** Expand a single, syntax element */
function expand_bracketed(&$out,$level,&$maxlevel,$item,$itemview,$aliases) {

    global $als,$debug,$errcheck;

    $maxlevel = max($maxlevel, $level); # stores maximum deep of nesting {}
                                        # used just for speed optimalization (QuoteColons)
    # See http://apc-aa.sourceforge.net/faq#aliases for details
    # bracket could look like:
    # {alias:[<field id>]:<f_* function>[:parameters]} - return result of f_*
    # {switch(testvalue)test:result:test2:result2:default}
    # {math(<format>)expression}
    # {include(file)}
    # {include:file} or {include:file:http}
    # {include:file:fileman|site}
    # {include:file:readfile[:str_replace:<search>[;<search1>;..]:<replace>[:<replace1>;..]:<trim-to-tag>:<trim-from-tag>[:filter_func]]}
    # {scroller.....}
    # {#comments}
    # {debug}
    # {inputvar:<field_id>:part:param}
    # {formbreak:part_name}
    # {formpart:}
    # {view.php3?vid=12&cmd[12]=x-12-34}
    # {dequote:already expanded and quoted string}
    # {fnctn:xxx:yyyy}   - expand $eb_functions[fnctn]
    # {unpacked_id.....}
    # {mlx_view:view format in html} mini view of translatiosn available for this article
    #                                does substitutions %lang, %itemid
    # {xxxx}
    #   - looks for a field xxxx
    #   - or in $GLOBALS[apc_state][xxxx]
    #   - als[xxxx]
    #   - aliases[xxxx]
    # {_#ABCDEFGH}
    # {const_<what>:<field_id>} - returns <what> column from constants for the value from <field_id>
    # {any text}                                       - return "any text"
    #
    # all parameters could contain aliases (like "{any _#HEADLINE text}"),
    # which are processed before expanding the function
    if ( isset($item) && (substr($out, 0, 5)=='alias') AND ereg("^alias:([^:]*):([a-zA-Z0-9_]{1,3}):?(.*)$", $out, $parts) ) {
      # call function (called by function reference (pointer))
      # like f_d("start_date......", "m-d")
      if ($parts[1] && ! isField($parts[1]))
        huhe("Warning: $out: $parts[1] is not a field, don't wrap it in { } ");
      $fce     = $parts[2];
      return QuoteColons($level, $maxlevel, $item->$fce($parts[1], $parts[3]));
      # QuoteColons used to mark colons, which is not parameter separators.
    }
    elseif( substr($out, 0, 7) == "switch(" ) {
      # replace switches
      return QuoteColons($level, $maxlevel, parseSwitch( substr($out,7) ));
      # QuoteColons used to mark colons, which is not parameter separators.
    }
    elseif( substr($out, 0, 5) == "math(" ) {
      # replace math
      return QuoteColons($level, $maxlevel,
        parseMath( # Need to unalias in case expression contains _#XXX or ( )
            new_unalias_recurent(substr($out,5),"",0,
                        $maxlevel,$item,$itemview,$aliases)) );

    }
    elseif( substr($out, 0, 8) == "include(" ) {
      # include file
      if( !($pos = strpos($out,')')) )
        return "";
        $fileout = expandFilenameWithHttp(substr($out, 8, $pos-8));
        return QuoteColons($level, $maxlevel, $fileout);
        # QuoteColons used to mark colons, which is not parameter separators.
    }
    elseif( substr($out, 0, 8) == "include:") {
        #include file, first parameter is filename, second is hints on where to find it
        $parts = ParamExplode(substr($out,8));
        if (! ($fn = $parts[0]))
            return "";
        # Could extend this to recognize | seperated alternatives
        if (! $parts[1]) $parts[1] = "http";  # Backward compatability
        switch ($parts[1]) {
          case "http":
            $fileout = expandFilenameWithHttp($parts[0]); break;
          case "fileman":
            # Note this won't work if called from a Static view because no slice_id available
            # This should be fixed.
            global $auth,$slice_id;
            #huhl($itemview->slice_info);
            if ($itemview->slice_info["id"]) $mysliceid = unpack_id128($itemview->slice_info['id']);
            elseif ($slice_id) $mysliceid = $slice_id;
            else {
                if ($errcheck) huhl("No slice_id defined when expanding fileman");
                return "";
            }
            $fileman_dir = sliceid2field($mysliceid,"fileman_dir");
          # Note dropthrough from case "fileman"
          case "site":
            if ($parts[1] == "site") {
                if (!($fileman_dir = $GLOBALS['site_fileman_dir'])) {
                    if ($errcheck) huhl("No site_fileman_dir defined in site file");
                    return "";
                }
            }
            $filename = FILEMAN_BASE_DIR . $fileman_dir . "/" . $parts[0];
            if ($filedes = @fopen ($filename, "r")) {
                $fileout = "";
                while (!feof ($filedes))
                    $fileout .= fgets($filedes, 4096);
                fclose($filedes);
            } else {
                if ($errcheck) huhl("Unable to read from file $filename");
                return "";
            }
            break;
          case "readfile": //simple support for reading static html (use at own risk)
            $filename = $_SERVER["DOCUMENT_ROOT"] . "/" . $parts[0];
            if ($filedes = @fopen ($filename, "r")) {
                $fileout = "";
                while (!feof ($filedes)) {
                    $fileout .= fgets($filedes, 4096);
                }
                fclose($filedes);
            } else {
                if ($errcheck) huhl("Unable to read from file $filename");
                return "";
            }
            break;
          default:
            if ($errcheck) huhl("Trying to expand include, but no valid hint in $out");
            return("");
        }
        return QuoteColons($level, $maxlevel, $fileout);
        # QuoteColons used to mark colons, which is not parameter separators.
    }
    elseif( ereg("^scroller:?([^}]*)$", $out, $parts)) {
        if (!isset($itemview) OR ($itemview->num_records<0) ) {   #negative is for n-th grou display
            return "Scroller not valid without a view, or for group display";
        }
        $viewScr = new view_scroller($itemview->slice_info['vid'],
                                     $itemview->clean_url,
                                     $itemview->num_records,
                                     $itemview->idcount(),
                                     $itemview->from_record);
        list( $begin, $end, $add, $nopage ) = ParamExplode($parts[1]);
        return $viewScr->get( $begin, $end, $add, $nopage );
    }
    // remove comments
    elseif ( substr($out, 0, 1) == "#" ) {
        return "";
    }
    elseif( substr($out, 0,5) == "debug" ) {
        // Note don't rely on the behavior of {debug} its changed by programmers for testing!
        if (isset($GLOBALS["apc_state"])) huhl("apc_state=",$GLOBALS["apc_state"]);
        if (isset($itemview))             huhl("itemview=",$itemview);
        if (isset($aliases))              huhl("aliases=",$aliases);
        if (isset($als))                  huhl("als=",$als);
        huhl("globals=",$GLOBALS);
        return "";
    }
    elseif ( substr($out, 0,10) == "view.php3?" ) {
        // view do not use colons as separators => dequote before callig
        return QuoteColons($level, $maxlevel, GetView(ParseViewParameters(DeQuoteColons(substr($out,10) ))));
    }
    // This is a little hack to enable a field to contain expandable { ... } functions
    // if you don't use this then the field will be quoted to protect syntactical characters
    elseif ( substr($out, 0, 8) == "dequote:" ) {
        return DeQuoteColons(substr($out,8));
    }
    // OK - its not a known fixed string, look in various places for the whole string
    if ( ereg("^([a-zA-Z_0-9]+):?([^}]*)$", $out, $parts) ) {
        if ( $GLOBALS['eb_functions'][$parts[1]] ) {          // eb functions - call allowed php functions directly
            $fnctn = $GLOBALS['eb_functions'][$parts[1]];
        } elseif ( is_callable("stringexpand_".$parts[1])) {  // custom stringexpand functions
            $fnctn = "stringexpand_".$parts[1];
        }
        // return result only if matches stringexpand_ or eb functions
        if ( $fnctn ) {
            if (!$parts[2]) {
                $ebres = $fnctn();
            } else {
                $param = array_map('DeQuoteColons',ParamExplode($parts[2]));
                $ebres = call_user_func_array($fnctn, (array)$param);
            }
            return QuoteColons($level, $maxlevel, $ebres);
        }
        // else - continue
    }
    if (isset($item) ) {
        if (($out == "unpacked_id.....") || ($out == "id..............")) {
            return QuoteColons($level, $maxlevel, $item->f_n('id..............'));
        } elseif ($out == "slice_id........") {
            return QuoteColons($level, $maxlevel, $item->f_n('slice_id........'));
        } elseif ( IsField($out) ) {
            return QuoteColons($level, $maxlevel, $item->f_h($out,"-"));
            # QuoteColons used to mark colons, which is not parameter separators.
        }
    }
    # Look and see if its in the state variable in module site
    # note, this is ignored if apc_state isn't set, i.e. not in that module
    # If found, unalias the value, then quote it, this expands
    # anything inside the value, and then makes sure any remaining quotes
    # don't interfere with caller
    if (isset($GLOBALS['apc_state'][$out])) {
        return QuoteColons($level, $maxlevel, new_unalias_recurent($GLOBALS['apc_state'][$out],"",$level+1,$maxlevel,$item,$itemview,$aliases));
    }
    # Pass these in URLs like als[foo]=bar,
    # Note that 8 char aliases like als[foo12345] will expand with _#foo12345
    elseif (isset($als[$out])) {
        return QuoteColons($level, $maxlevel,
            new_unalias_recurent($als[$out],"",$level+1,
                $maxlevel,$item,$itemview,$aliases));
    }
    elseif (isset($aliases[$out])) {   # look for an alias (this is used by mail)
        return QuoteColons($level, $maxlevel, $aliases[$out]);
    }
    // Look for {_#.........} and expand now, rather than wait till top
    elseif (isset($item) && (substr($out,0,2) == "_#")) {
        return QuoteColons($level, $maxlevel,$item->substitute_alias_and_remove($out));
    }
    // first char of alias is @ - make loop to view all values from field
    elseif ( (substr($out,0,1) == "@") OR (substr($out,0,5) == "list:")) {
        return QuoteColons($level, $maxlevel, parseLoop($out, $item));
    }
    // look for {const_*:} for changing viewing type of constants
    elseif (substr($out, 0, 6) == "const_") {
        // $what - name of column (eg. from const_name we get name)
        $what = substr($out, strpos($out, "_")+1, strpos($out, ":") - strpos($out, "_")-1);
        // parameters - first is field
        $parts = ParamExplode(substr($out,strpos($out,":")+1));
        // get group id
        $group_id = getConstantsGroupID($item->getval("slice_id........"), $parts[0]);
        /* get short_id/name/... of constant with specified value from constants category with
           group $group_id */
        $value = getConstantValue($group_id, $what, $item->getval($parts[0]));

        return QuoteColons($level, $maxlevel, $value);
    }elseif (substr($out,0,8) == "mlx_view") {
        if(!$GLOBALS['mlxView'])
           return "$out";
        //$param = array_map('DeQuoteColons',ParamExplode($parts[2]));
        return $GLOBALS['mlxView']->getTranslations($item->getval('id..............'),
          $item->getval('slice_id........'),array_map('DeQuoteColons',ParamExplode($parts[2])));
    }
     // Put the braces back around the text and quote them if we can't match
    else {
        // Don't warn if { followed by non alphabetic, e.g. in Javascript
        // Fix javascript to avoid this warning, typically add space after {
        if ($errcheck && ereg("^[a-zA-Z_]",$out)) {
            huhl("Couldn't expand: \"{$out}\"");
            #trace("p");
        }
        return QuoteColons($level, $maxlevel, "{" . $out . "}");
    }
}

# Expand any quotes in the parturl, and fetch via http
function expandFilenameWithHttp($parturl) {
    global $errcheck;
      $filename = str_replace( 'URL_PARAMETERS', DeBackslash(shtml_query_string()),
                               DeQuoteColons($parturl));
           # filename do not use colons as separators => dequote before callig
      if (!$filename || trim($filename)=="") {
          return "";
      }

      // if no http request - add server name
      if (!(substr($filename, 0, 7) == 'http://') AND !(substr($filename, 0, 8) == 'https://')) {
          $filename = self_server(). (($filename{0}=='/') ? '' : '/'). $filename;
      }

      if (! $fp = @fopen( $filename, 'r'))  {
        if ($errcheck) huhl("Unable to retrieve $filename");
        return "";
      }
      $fileout = "";
      do {   // this is needed since PHP 4.3.2 - remote files are read on PACKET
             // by PACKET BASIS !!! (maybe we can use new file_get_contents() for
             // php >= 4.3.0
          $data = fread( $fp, defined("INCLUDE_FILE_MAX_SIZE") ? INCLUDE_FILE_MAX_SIZE : 400000 );
          if (strlen($data) == 0) break;
          $fileout .= $data;
      } while(true);
      fclose( $fp );
      return $fileout;
}

# Return some strings to use in keystr for cache if could do a stringexpand
function stringexpand_keystring() {
    $ks = "";
    if (isset($GLOBALS["apc_state"])) $ks .= serialize($GLOBALS["apc_state"]);
    if (isset($GLOBALS["als"])) $ks .= serialize($GLOBALS["als"]);
    return $ks;
}

# This is based on the old unalias_recurent, it is intended to replace
# string substitution wherever its occurring.
# Differences ....
#   - remove is applied to the entire result, not the parts!
function new_unalias_recurent(&$text, $remove, $level, &$maxlevel, $item=null, $itemview=null, $aliases=null ) {
    global $debug;
    $maxlevel = max($maxlevel, $level); # stores maximum deep of nesting {}
                                        # used just for speed optimalization (QuoteColons)
# Note ereg was 15 seconds on one multi-line example cf .002 secs
#    while (ereg("^(.*)[{]([^{}]+)[}](.*)$",$text,$vars)) {

    while (preg_match("/^(.*)[{]([^{}]+)[}](.*)$/s",$text,$vars)) {
        $t1 = expand_bracketed($vars[2],$level+1,$maxlevel,$item,$itemview,$aliases);

        $text = $vars[1] . $t1 . $vars[3];
    }

    if (isset($item)) {
    return QuoteColons($level, $maxlevel, $item->substitute_alias_and_remove($text,explode ("##",$remove)));
    } else {
        return QuoteColons($level, $maxlevel, $text);
    }
}

// This isn't used yet, might be changed
// remove this comment if you use it!
function stringexpand_slice_comments($slice_id) {
    $SQL = "SELECT sum(disc_count) FROM item WHERE slice_id=\"$slice_id\"";
    $db = getDB();
    $res = $db->tquery($SQL);
    if ($db->next_record()) {
        $dc = $db->f("sum(disc_count)");
    } else {
        $dc = 0;
    }
    freeDB($db);
    return $dc;
}

// Use this inside URLs e.g. {urlencode:_#EDITITEM}
function stringexpand_urlencode($raw) {
    return urlencode($raw);
}
