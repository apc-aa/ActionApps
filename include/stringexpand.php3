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
 * @author    Honza Malik <honza.malik@ecn.cz>, Mitra Ardron <mitra@mitra.biz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 */


// ----------------------------------------------------------------------------
//                         stringexpand
//
// Note that this is NOT defined as a class, and is called within several other classes
// ----------------------------------------------------------------------------

// Code by Mitra based on code in existing other files

use AA\FormArray;
use AA\Util\ChangesMonitor;

require_once AA_INC_PATH."perm_core.php3";    // needed for GetAuthData();

/**
 * Class AA_Aliasfunc for storing functions like {_:Message_box:Update successfull:green}
 */
class AA_Aliasfunc extends AA_Object {

    /**
     * @var string
     */
    var $alias;
    /**
     * @var string
     */
    var $code;
    /**
     * @var string
     */
    var $desc;
    /**
     * @var string
     */
    var $ussage;
    // var $params;

    /** AA_Aliasfunc function
     * @param $alias;
     * @param $code;
     * @param $desc;
     * @param $ussage;
     */
    function __construct($alias='', $code='', $desc='', $ussage='') {
        $this->alias  = $alias;
        $this->code   = $code;
        $this->desc   = $desc;
        $this->ussage = $ussage;
    }

    /** allows storing form in database
     *  AA_Object's method
     */
    static function getClassProperties() {
        return array (          //           id       name       type        multi   persist validator, required, help, morehelp, example
            'alias'  => new AA_Property( 'alias',  _m("Alias"),          'string',  false, true, '', true,  _m('Alias will be called as {_:&lt;Alias_name&gt;[:&lt;Possible parameters - colon separated&gt;]}'),'', 'Message_box'),
            'code'   => new AA_Property( 'code',   _m("Code"),           'text',    false, true, '', true,  _m('Code printed by the alias. Alias could have parameters and you can use it by _#P1, _#P2, ... variables'),'', '&lt;div class=mybox style="color:_#P2"&gt;_#P1&lt;/div&gt;'),
            'desc'   => new AA_Property( 'desc',   _m("Description"),    'text',    false, true, '', false),
            'ussage' => new AA_Property( 'ussage', _m("Ussage example"), 'string', true,  true, '', false, '', '', '{_:Message_box:Update successfull:green}')
        );
    }

    // static function factoryFromForm($oowner, $otype=null)        ... could be redefined here, but we use the standard one from AA_Object
    // static function getForm($oid=null, $owner=null, $otype=null) ... could be redefined here, but we use the standard one from AA_Object
}


if (defined('AA_CUSTOM_DIR')) {
    include_once(AA_INC_PATH. 'custom/'. AA_CUSTOM_DIR. '/stringexpand.php');
}

// we need it for preg_replace_callback when unalias sometimes gives empty results (empty spots in site, ...)
if (ini_get('pcre.backtrack_limit') < 10000000) {
    ini_set('pcre.backtrack_limit', 10000000);
}
if (ini_get('pcre.recursion_limit') < 10000000) {
    ini_set('pcre.recursion_limit', 10000000);
}

if (ini_get('pcre.jit') == 1) {
    ini_set('pcre.jit', 0);
}

/** creates array form JSON array or returns single value array if not valid json
 * @param $string
 * @param bool $do_not_filter
 * @return array|mixed
 */
function json2arr($string, $do_not_filter=false) {
    if ($string{0} != '[') {
        $values = array($string);
    } elseif (AA::$encoding AND (AA::$encoding != 'utf-8')) {
        if ( ($arr = json_decode(ConvertEncoding($string, AA::$encoding))) == null) {
            return array();
        }
        $values = ConvertEncodingDeep($arr,'utf-8', AA::$encoding);
    } else {
        $values = json_decode($string) ?: array();
    }
    return $do_not_filter ? $values : array_filter($values, 'strlen');  // strlen in order we do not remove "0"
}


/** creates array form JSON array or returns single value array if not valid json
 * @param $string
 * @return array
 */
function json2asoc($string) {
    if ( !strlen($string) ) {
        return array();
    }
    if ($string{0} != '{') {
        if ($string{0} == '[') {
            // returns "one" => "one", "two" => "two" for ["one","two"]
            $values = json2arr($string, true);
            return array_combine($values,$values);
        }
        return array();
    }

    if (AA::$encoding AND (AA::$encoding != 'utf-8')) {
        if ( ($arr = json_decode(ConvertEncoding($string, AA::$encoding),true)) == null) {
            return array();
        }
        $values = ConvertEncodingDeep($arr,'utf-8', AA::$encoding);
    } else {
        $values = json_decode($string,true) ?: array();
    }
    return $values;
}

/** @return string - JSON array form string where values are delimited by $delimiter.
 *  Input text must be in utf8
 */
class AA_Stringexpand_Jsonarray extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($delimiter could be space) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     * @param $delimiter ...
     * @return string
     */
    function expand($text='', $delimiter='') {
        return AA_Stringexpand_Item::itemJoin(explode(($delimiter ?: '-'), $text), 'json');  // also filters out empty values
    }
}

/** @return string - JSON object of key-value pairs {jsonasoc:key1:val1:key2:val2:...}
 *  usage:
 *    {jsonasoc:name:{headline........}:input_help:{subtitle........}}
 *    {input:82d37e966fcdc9d1cac49a7e49406601:text............:1:fld:{jsonasoc:name:Surname:input_help:just surname, please}}
 *  params text must be in utf8
 */
class AA_Stringexpand_Jsonasoc extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($delimiter could be space) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /**
     * @return string|void
     */
    function expand() {
        $arg_list = func_get_args();   // must be asssigned to the variable
        if (AA::$encoding AND (AA::$encoding != 'utf-8')) {
            $arg_list = ConvertEncodingDeep($arg_list, AA::$encoding, 'utf-8');
        }

        $i        = 0;
        $arr      = array();
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if ( !strlen($field_id = trim($arg_list[$i])) ) {
                $i += 2;
                continue;
            }
            $arr[$field_id] = $arg_list[$i+1];
            $i += 2;
        }
        return json_encode($arr);
    }
}

/** @return {colonescape:val1} - escapes colon as internaly used in AA. It is special case function - more internal one
 *  usage:
 *    txt:{colonescape:_#this}  - 16:45  -->  16#:45
 */
class AA_Stringexpand_Colonescape extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($delimiter could be space) */
    function doTrimParams() { return false; }

    /**
     * @param string $text
     * @return mixed|void
     */
    function expand($text='') {
        return str_replace(':', '#:', $text);
    }
}

/** include file, first parameter is filename, second is hints on where to find it **/
class AA_Stringexpand_Switch extends AA_Stringexpand_Nevercache {

    /** redefine parseParam - we can't use the standard function - there is problem with:
     *  {switch({text............}).:OK} if the text.. contain ')' - we do not know, where the parameters are separated
     */
    static function parseParam($params) {
        if (empty($params)) {
            return array();
        }
        list($condition,$rest) = explode(')', $params, 2);
        return array_map('DeQuoteColons', array_merge(array($condition), ParamExplode($rest)));
    }

    /** expand function
     * @params string - first parameter is filename, second is hints on where to find it
     */
    function expand() {
        $twos      = func_get_args();   // must be asssigned to the variable
        $condition = array_shift($twos);
        $i         = 0;
        $twoscount = count($twos);
        $ret       = '';

        while ( $i < $twoscount ) {
            if ( $i == ($twoscount-1)) {                // default option
                $ret = $twos[$i];
                break;
            }
            $val = trim($twos[$i]);
            // Note you can't use !$val, since this will match a pattern of exactly "0"
            if ( ($val=="") OR preg_match('`'.str_replace('`','\`',$val).'`', $condition) ) {    // Note that $string, might be expanded {headline.......} or {m}
                $ret = $twos[$i+1];
                break;
            }
            $i+=2;
        }
        return str_replace('_#1', $condition, $ret);
    }
}

/** Expands {user:xxxxxx} alias - auth user informations (of current user)
 *   @param $field - field to show ('headline........', 'alerts1....BWaFs' ...).
 *                   empty for username (of curent logged user)
 *                   'password' for plain text password of current user
 *                   'permission'
 *                   'role'  -> returns super|administrator|editor|author|undefined
 */
class AA_Stringexpand_User extends AA_Stringexpand {

    /** $auth_user_info caches user's informations */
    private static $auth_user_info = array();

    /** additionalCacheParam function
     */
    function additionalCacheParam() {
        return serialize(array(AA_Stringexpand_User::$auth_user_info, $GLOBALS['auth']));
    }

    /** expand function
     * @param $field
     * @return bool|mixed|null|string|string[]
     */
    function expand($field='') {
        global $cache_nostore, $auth, $perms_roles;
        // this GLOBAL :-( variable is message for pagecache to NOT store views (or
        // slices), where we use {user:xxx} alias, into cache (AUTH_USER is not in
        // cache's keyString.
        $cache_nostore = true;             // GLOBAL!!!
        $auth_user     = get_if($_SERVER['PHP_AUTH_USER'],$auth->auth["uname"],$_SERVER['REMOTE_USER']);
        switch ($field) {
            case '':         return $auth_user;
            case 'password': return $_SERVER['PHP_AUTH_PW'];
            case 'role' : // returns users permission to slice
            case 'permission' :
                if ( IfSlPerm($perms_roles['SUPER']['perm']) ) {
                    return 'super';
                } elseif ( IfSlPerm($perms_roles['ADMINISTRATOR']['perm'] ) ) {
                    return 'administrator';
                } elseif ( IfSlPerm($perms_roles['EDITOR']['perm'] ) ) {
                    return 'editor';
                } elseif ( IfSlPerm($perms_roles['AUTHOR']['perm'] ) ) {
                    return 'author';
                } else {
                    return 'undefined';
                }
                break;
            default:
                // $auth_user_info caches user's informations
                if ( !isset(AA_Stringexpand_User::$auth_user_info[$auth_user]) ) {
                    AA_Stringexpand_User::$auth_user_info[$auth_user] = GetAuthData();
                }
                $item_user = GetItemFromContent(AA_Stringexpand_User::$auth_user_info[$auth_user]);
                if ($field=='id') {
                    return $item_user->getItemID();
                }
                return $item_user->subst_alias($field);
        }
    }
}

/** Expands {xuser:xxxxxx} alias - auth user informations (of current user)
 *   @param $field - field to show ('headline........', '_#SURNAME_' ...).
 *                   empty for username (of curent logged user)
 *                   id - for long id
 *
 *   We do not use {user} in this case, since views with {user} are not cached,
 *   but the views with {xuser} could be (xuser is part of apc variable)
 */
class AA_Stringexpand_Xuser extends AA_Stringexpand {

    /** expand function
     * @param $field
     * @return bool|mixed|null|string|string[]
     */
    function expand($field='') {
        $xuser = $GLOBALS['apc_state']['xuser'];
        if (!$xuser) {
            return '';
        }
        switch ($field) {
            case '':     return $xuser;
            case 'id':   return AA_Reader::name2Id($xuser);
        }
        $item = AA_Items::getItem(new zids(AA_Reader::name2Id($xuser),'l'));
        return empty($item) ? '' : $item->subst_alias($field);
    }
}

/** experimental
 */
class AA_Stringexpand_Internal extends AA_Stringexpand_Nevercache {

    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     * @return mixed|string
     */
    function expand($class_name='',$info='') {
        //$params = func_get_args();
        $item = $this ? $this->item : null;
        if (!is_object($item) OR !is_a($item, 'AA_Item') OR !class_exists($class_name, false) OR !method_exists($class_name, 'internal_expand')) {
            return 'qqq';
        }
        $params = func_get_args();   // must be asssigned to the variable
        array_shift($params);        // remove class name
        array_unshift($params, $item->getItemContent());
        return call_user_func_array( array($class_name, 'internal_expand'), $params);
        //return ($this AND is_object($item) AND $item->isField($text)) ? $item->getval($text) : join(':', $params);
    }
}

/** Returns name or other info about user (usable for posted_by, edited_by, ...)
 *   {userinfo:<user>[:<property>]}
 *   @param $user - user as stored in posted_by....... or edited_by....... field
 *   @param $property - 'name'|logintime|logintimes at this moment only, which is default
 */
class AA_Stringexpand_Userinfo extends AA_Stringexpand {

    /** expand function
     * @param $user
     * @return name|string
     */
    function expand($user='', $property='') {
        if (!$user) {
            return '';
        }
        switch ($property) {
            case 'logintime':  return DB_AA::select1("SELECT time FROM `log`", 'time', array(array('type','LOGIN'),array('params',str_replace(':',':#',$user).':%','LIKE')), array('time-')) ?: '';
            case 'logintimes': return join('-',DB_AA::select('time', "SELECT time FROM `log`", array(array('type','LOGIN'),array('params',str_replace(':',':#',$user).':%','LIKE')), array('time-')));
        }
        return perm_username( $user );
    }
}

/** Replace inputform field alias with the real core for the field, which is
 *  stored already in the pagecache
 *  @param $parameters - field id and other modifiers to the field
 */
class AA_Stringexpand_Inputvar extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // cache is used by expand function itself
    /** expand function
     *
     */
    function expand() {
        $arg_list = func_get_args();   // must be asssigned to the variable
        // replace inputform field
        // destroy all aliases, since the content of the variables could contain
        // aliases, but we don't want to unalias them. The _AA_ReMoVe_ string
        // will be removed in dequteColons
        return str_replace('_#','__AA_ReMoVe#', AA::Contentcache()->get('inputvar:'. join(':',$arg_list)));
    }
}

/** Defines named expression. You can use it for creatin on-line alieases, you
 *  can use it for passing parameters between views, ...
 *  The {define:name:expr} must be processed before the {var:name} is processed
 *  when the page is generated
 *  You can use parameters with {var:name:param1:param2:...:...}
 *  the expression then will use _#1, _#2, ... for each parameter, just like:
 *  {define:username:My name is#: _#1} and ussage {var:username:Joseph}
 *  stored already in the pagecache
 *  @param $parameters - field id and other modifiers to the field
 */
class AA_Stringexpand_Define extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // cache is used by expand function itself

    /**
     * @param string $name
     * @param string $expression
     * @return string|void
     */
    function expand($name='', $expression='') {
        if (strlen($name = trim($name))) {
            AA::Contentcache()->set("define:$name", $expression);
        }
        return '';
    }
}

/** Prints defined named expression. Used with conjunction {define:..}
 *  @see AA_Stringexpand_Define for more info
 *  @param $parameters - field id and other modifiers to the field
 */
class AA_Stringexpand_Var extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // cache is used by expand function itself

    /**
     * @param string $name
     * @return false|void
     */
    function expand($name='') {
        // replace inputform field
        $expression = AA::Contentcache()->get("define:$name");
        // @todo - replace parameters
        return $expression;
    }
}


/** Expands {formbreak:xxxxxx:yyyy:....} alias - split of inputform into parts
 *  @param $part_name - name of the part (like 'Related Articles').
 */
class AA_Stringexpand_Formbreak extends AA_Stringexpand {
    /** additionalCacheParam function
     *
     */
    function additionalCacheParam() {
        return serialize(array($GLOBALS['g_formpart'], $GLOBALS['g_formpart_names'], $GLOBALS['g_formpart_pos']));
    }
    /** expand function
     *
     */
    function expand($part_names='') {
        $GLOBALS['g_formpart']++;  // Nothing to print, it just increments part counter

        if (empty($GLOBALS['g_formpart_pos'])) {
            $GLOBALS['g_formpart_pos'] = 3;  // position of the tabs - bottom and top
        }

        // You can specify also the names for next tabs (separated by ':'), which is
        // usefull mainly for last tab (for which you do not have formbrake, of course
        $part_names = func_get_args();
        $i = 0;
        foreach ($part_names as $name) {  // remember part name
            // the formparts are numbered backward
            $index = ($GLOBALS['g_formpart'] - $i++);
            if ($name != '' AND ($index >= 0)) {
                $GLOBALS['g_formpart_names'][$index] = $name;
            }
        }
    }
}

/** Expands {formbreakbottom:xxxxxx:yyyy:....} alias - split of inputform into parts
 *  @param $part_name - name of the part (like 'Related Articles').
 */
class AA_Stringexpand_Formbreakbottom extends AA_Stringexpand_Formbreak {
    /** expand function
     *
     */
    function expand($part_names='') {
        $GLOBALS['g_formpart_pos'] = 2;  // bottom
        StrExpand('AA_Stringexpand_Formbreak', array($part_names));
    }
}

/** Expands {formbreaktop:xxxxxx:yyyy:....} alias - split of inputform into parts
 *  @param $part_name - name of the part (like 'Related Articles').
 */
class AA_Stringexpand_Formbreaktop extends AA_Stringexpand_Formbreak {
    /** expand function
     *
     */
    function expand($part_names='') {
        $GLOBALS['g_formpart_pos'] = 1;  // top
        StrExpand('AA_Stringexpand_Formbreak', array($part_names));
    }
}

/** Expands {formpart:} alias - prints number of current form part */
class AA_Stringexpand_Formpart extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     *
     */
    function expand() {
        return get_if($GLOBALS['g_formpart'],'0');  // Just print part counter
    }
}


/** Expands {icq:<user_id>[:<action>[:<style>]]} and displays ICQ status for
 *  the user.
 *  @param $user_id  - ICQ ID of the user
 *  @param $action   - add | message
 *  @param $style    - 0-26 - displayed icon type
 *                   - see: http://www.icq.com/features/web/indicator.html
 */
class AA_Stringexpand_Icq extends AA_Stringexpand {
    /** expand function
     * @param $user_id
     * @param $action
     * @param $style
     * @return string
     */
    function expand($user_id='', $action='add', $style=1) {
        if ( !$user_id ) {
            return "";
        }
        $user_id = urlencode($user_id);
        $action  = urlencode($action);
        $style   = (int)$style;

        // set the url to the image and the stype of the image
        $image = '<img src="https://status.icq.com/online.gif?icq='.$user_id.'&img='.$style.'" border="0">' ;
        return '<a href="https://www.icq.com/people/cmd.php?uin='.$user_id.'&action='.$action.'">'.$image.'</a>';
    }
}

/** Expands {skype:<skype_name>[:<action>[:<style>[:<message>]]]} and displays
 *  SKYPE status for the user
 *  @param $user_skype_name  - skype name of the user
 *  @param $action           - add | call | chat | userinfo
 *  @param $style            - add | call | chat | smallicon |mediumicon | ballon | bigclassic | smallclassic
 *  @param $message          - a text to display
 *                   - @see: http://www.skype.com/share/buttons/advanced.html
 */
class AA_Stringexpand_Skype extends AA_Stringexpand {
    /** expand function
     * @param $user_id
     * @param $action
     * @param $style
     * @param $message
     * @return string
     */
    function expand($user_skype_name='', $action='userinfo', $style='smallicon', $message='Skype me') {
        if ( !$user_skype_name ) {
            return "";
        }
        $user_skype_name = urlencode($user_skype_name);
        $action          = urlencode($action);
        $style           = urlencode($style);
        $message         = safe($message);

        // start the rendering the html output
        $output  = '<!-- Skype "My status" button http://www.skype.com/go/skypebuttons -->';
        $output .= '<script src="https://download.skype.com/share/skypebuttons/js/skypeCheck.js"></script>';
        $output .= '<a href="skype:'.$user_skype_name.'?'.$action.'"><img src="https://mystatus.skype.com/'.$style.'/'.$user_skype_name.'" style="border: none;" alt="'.$message.'" title="'.$message.'" /></a>';
        $output .= '<!-- end of skype button -->';

        return $output;
    }
}


/** Expands {yahoo:<yahoo_name>[:<action>[:<style>]]} and displays YAHOO status for
 *  the user.
 *  @param $user_id  - yahoo name of the user
 *  @param $action           - addfriend | call | sendim
 *  @param $style            - 0-4 - dysplayed icon type
 *                   - see: https://messenger.yahoo.com/messenger/help/online.html
 */
class AA_Stringexpand_Yahoo extends AA_Stringexpand {
    /** expand function
     * @param $user_id
     * @param $action
     * @param $style
     * @return string
     */
    function expand($user_id='', $action='sendim', $style='2') {

        if ( !$user_id ) {
            return "";
        }

        // set your defaults for the style and action (addfriend, call or sendim) (0, 1, 2, 3 and 4)

        $action_default = "sendim" ;
        $style_default  = "2" ;
        $image          = '';

        // test to see if the optinal elements of the params are supported. if not set them to the defaults

        if ( !($style == "0" OR $style == "1" OR $style == "2" OR $style == "3" OR $style == "4" ) ) {
            $style = $style_default ;
        }

        if ( !($action == "addfriend" OR $action == "sendim" OR $action == "call") ) {
            $action = $action_default ;
        }

        // set the url to the image and the style of the image
        switch( $style ) {

            case "0":
                $image = '<img src="http://opi.yahoo.com/online?u='.$user_id.'&m=g&t=0" ' ;
                $image .= ' style="border: none; width: 12px; height: 12px;" alt="My status" />' ;
                break;

            case "1":
                $image = '<img src="http://opi.yahoo.com/online?u='.$user_id.'&m=g&t=1" ' ;
                $image .= ' style="border: none; width: 64px; height: 16px;" alt="My status" />' ;
                break;

            case "2":
                $image = '<img src="http://opi.yahoo.com/online?u='.$user_id.'&m=g&t=2" ' ;
                $image .= ' style="border: none; width: 125px; height: 25px;" alt="My status" />' ;
                break;

            case "3":
                $image = '<img src="http://opi.yahoo.com/online?u='.$user_id.'&m=g&t=3" ' ;
                $image .= ' style="border: none; width: 86px; height: 16px;" alt="My status" />' ;
                break;

            case "4":
                $image = '<img src="http://opi.yahoo.com/online?u='.$user_id.'&m=g&t=4" ' ;
                $image .= ' style="border: none; width: 12px; height: 12px;" alt="My status" />' ;
                break;
        }
        return '<a href="ymsgr:'.$action.'?'.$user_id.'">'.$image.'</a>';
    }
}

/** Expands {jabber:<user_id>[:<action>[:<style>]]} and displays Jabber status for
 *  the user.
 *  @param $user_id  - ICQ ID of the user
 *  @param $action   - call
 *  @param $style    - 0-3 - displayed icon type
 *                     @see: http://www.the-server.net:8000
 *                     @see: http://www.onlinestatus.org/
 */
class AA_Stringexpand_Jabber extends AA_Stringexpand {
    /** expand function
     * @param $user_id
     * @param $action
     * @param $style
     * @return string
     */
    function expand($user_id='', $action='call', $style=0) {
        if ( !$user_id ) {
            return "";
        }
        $port  = '800'.(int)$style;

        //  @see http://www.onlinestatus.org/
        $output = "<a href=\"xmpp:$user_id\"><img
          src=\"http://www.the-server.net:$port/jabber/$user_id\" align=\"absmiddle\" border=\"0\" alt=\""._m('Jabber Online Status Indicator') ."\"
          onerror=\"this.onerror=null;this.src='http://www.the-server.net:$port/image/jabberunknown.gif';\"></a>";

        return $output;
    }
}

/** {facebook:<url>} "I like" button
 *  {facebook:{_#SEO_URL_}}
 *  @param $url      - url of liked page
 */
class AA_Stringexpand_Facebook extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param string $url
     * @param string $type '+share' to add share button
     * @return string
     */
    function expand($url='', $type='') {
        if (!$url) {
            return '';
        }
        $share  = 'false';
        $width  = 120;
        $height = 21;
        if ($type ==  '+share') {
            $share  = 'true';
            $width  = 165;
            $height = 21;
        }
        return !$url ? '' : "<iframe src=\"https://www.facebook.com/plugins/like.php?href=".urlencode($url)."&amp;width=$width&amp;layout=button_count&amp;action=like&amp;size=small&amp;show_faces=false&amp;share=$share&amp;height=$height\" width=\"$width\" height=\"$height\" style=\"border:none;overflow:hidden\" scrolling=no frameborder=0 allowTransparency=true allow=\"encrypted-media\"></iframe>";
    }
}

/** {twitter} Tweet share button
 *  requires {generate:HEAD} in the sitemodule to load scripts
 */
class AA_Stringexpand_Twitter extends AA_Stringexpand_Nevercache {

    function expand() {
        AA::Stringexpander()->addRequire('https://platform.twitter.com/widgets.js async');  // for AA_AjaxSendForm
        return '<a href="https://twitter.com/share?ref_src=twsrc%5Etfw" class="twitter-share-button" data-show-count="true">Tweet</a>';
        // <a href="https://twitter.com/share?ref_src=twsrc%5Etfw" class="twitter-share-button" data-show-count="false">Tweet</a><script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>
    }
}



/** Expands {protectmail:<email>[:<text>]} - hides mail into javascript
 *  <a href="mailto:<email>"><text></a>   (but encocded in javascript)
 *  @param $email    - e-mail to protect
 *  @param $text     - text to be linked (if not specified, the $email is used)
 */
class AA_Stringexpand_Protectmail extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // cache is used by expand function itself

    /** expand function */
    function expand($email='', $text='') {
        if (!$email) {
            return $text;
        }
        $ahrefid = 'aapm'.gensalt(10);
        $linkpart    = explode('@', $email);
        $mailprotect = "'".$linkpart[0]."'+'@'+'".$linkpart[1]."'";
        $linktext    = ($text=='') ? $mailprotect : "'".escape4js($text)."'";
        $ret = "<a href=\"\" id=\"$ahrefid\"></a>";
        $ret .= "<script>var el=document.getElementById('$ahrefid');el.setAttribute('href','mai'+'lto:'+$mailprotect);el.insertAdjacentHTML('afterbegin',$linktext);</script>";
        return $ret;
    }
    // another option - no need the ID
    //static function documentWrite($html, $tag='span') {
    //    $ret = "<script>var el=document.createElement('$tag'); el.innerHTML='$html'; var scrs = document.getElementsByTagName ('script'); var scr=[scrs.length-1]; scr.parentNode.insertBefore(el, scr);</script>";
    //}
}

/** Expands {lastedit:[<date_format>:[<slice_id>]]} and displays the date of
 *  last modificaton of any item in the slice
 *  @param $date_format - the format in which you want to see the date
 *                        @see date() function of php http://php.net/date
 *                        like {lastedit:m/d/y H#:i}
 *  @param $slice_id    - the slice which should be checked for last
 *                        modification. If no slice is specified, then check
 *                        all the slices
 */
class AA_Stringexpand_Lastedit extends AA_Stringexpand {

    /** expand function
     * @param $format
     * @param $slice_id
     * @return false|string
     */
    function expand($format='', $slice_id='') {
        $format = $format ?: 'j.n.Y';
        $where = is_long_id($slice_id) ? array(array('slice_id', $slice_id, 'l')) : null;
        return date($format, DB_AA::select1("SELECT last_edit FROM `item`", 'last_edit', $where, array('last_edit-')) ?: 0);
    }
}


/** returns the time of last discussion comment on specified item.
 * @param $item_id            - the item id of the item, which we investigate
 * @param $count_item_itself  - bool 1, when we have to count also with the publish
 *                              date of the item itself
 *
 *  We use it for displaying the box with five most recently commented items:
 *    {item:{limit:{order:{ids:9887a8a0ca2ab74691a5b41a485453ac}:_#LASTDISC:rnumeric}:0:5}:
 *       {(
 *           <tr>
 *             <td><a href="_#SEO_URL_?all_ids=1">_#HEADLINE</a> </td>
 *             <td><a href="_#SEO_URL_?all_ids=1#disc">_#D_APPCNT</a></td>
 *             <td>{date:j.n.y:{_#LASTDISC}}</td>
 *           </tr>
 *       )}
 *    }
 *  The _#LASTDISC alias is in this case {lastdisc:{id..............}}.
 */
class AA_Stringexpand_Lastdisc extends AA_Stringexpand {
    /** expand function
     * @param $item_id
     * @param $count_item_itself
     * @return array|bool|string
     */
    function expand($item_id=null, $count_item_itself=null) {
        if (!$item_id) {
            return "0";
        }
        $zids      = new zids($item_id);
        //return 'SELECT date FROM discussion WHERE `state`=0 AND '. $zids->sqlin('item_id') .' ORDER BY date DESC LIMIT 1';
        $disc_time = GetTable2Array('SELECT date FROM discussion WHERE `state`=0 AND '. $zids->sqlin('item_id') .' ORDER BY date DESC LIMIT 1', 'aa_first', 'date');
        if ($disc_time) {
            return $disc_time;
        }

        if ($count_item_itself=='1') {
            return GetTable2Array('SELECT publish_date FROM item WHERE '. $zids->sqlin('id'), 'aa_first', 'publish_date');
        }

        return '0';
    }
}


/** Expands {htmltoggle:<toggle1>:<text1>:<toggle2>:<text2>[:<position>][:<persistent-id>]} like:
 *          {htmltoggle:more >>>:Econnect:less <<<:Econnect is ISP for NGOs...:bottom}
 *  It creates the link text1 (or text2) and two divs, where only one is visible
 *  at the time
 *  The /javscript/aajslib.php shoud be included to the page
 *  (by <script src="">)
 *  @param $switch_state_1 - default link text
 *  @param $code_1         - HTML code displayed as default (in div)
 *  @param $switch_state_2 - link text 2
 *  @param $code_2         - HTML code displayed as alternative after clicking
 *                           on the link
 *  @param $position       - position of the link - top|bottom (top is default)
 *  @param $persistent_id  - identifier [a-z-]* - if provided, the toggle state will be persistent between page loads
 */
class AA_Stringexpand_Htmltoggle extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    /**
     * @param string $switch_state_1
     * @param string $code_1
     * @param string $switch_state_2
     * @param string $code_2
     * @param string $position
     * @param string $persistent_id
     * @return string|void
     */
    function expand($switch_state_1='', $code_1='', $switch_state_2='', $code_2='', $position='', $persistent_id='') {

        // it is nonsense to show expandable trigger if both contents are empty
        if ($code_1.$code_2 == '') {
            return '';
        }

        if ($switch_state_1.$switch_state_2 == '') {
            $switch_state_1 = '[+]';
            $switch_state_2 = '[-]';
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switches    = str_replace(array('[+]','[-]'), array(GetAAImage('plus.gif',  _m('show'), 15, 9), GetAAImage('minus.gif', _m('hide'), 15, 9)), array($switch_state_1, $switch_state_2));
        $switches_js = str_replace(array("'", '"', "\n", "\r"), array("\'", "\'", ' ', ' '), $switches);

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $link   = '';
        $script = '';

        if ($code_1 == $code_2) {
            // no need to add toggle
            $ret = "<div class=\"toggleclass\" id=\"toggle_1_$uniqid\">$code_1</div>\n";
        } else {
            $func = "AA_HtmlToggle('toggle_link_$uniqid', '{$switches_js[0]}', 'toggle_1_$uniqid', '{$switches_js[1]}', 'toggle_2_$uniqid'".($persistent_id ? ", '$persistent_id')" : ")");
            $link = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"$func; return false;\">{$switches[0]}</a>\n";
            $ret  = "<div class=\"toggleclass\" id=\"toggle_1_$uniqid\">$code_1</div>\n";
            $ret .= "<div class=\"toggleclass\" id=\"toggle_2_$uniqid\" style=\"display:none;\">$code_2</div>\n";
            if ($persistent_id) {
                $script = "<script> if (localStorage['$persistent_id'] == '2') $func; </script>\n";
            }
        }
        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_HtmlToggle
        return (trim($position)=='bottom') ?  $ret. $link. $script: $link. $ret. $script;
    }
}

/** Expands {htmltogglecss:<toggle1>:<toggle2>:<css_rule>} like:
 *          {htmltogglecss:+:-:#id_#SITEM_ID}    (#id_#SITEM_ID should have style="display:none;" as default)
 *          {htmltogglecss:+:-:#id_#SITEM_ID:1}
 *  It creates the link text1 (or text2) +/- toggle which displays/hides all
 *  elements matching the css_rule (#id82422) in our example
 *  The /javscript/aajslib.php shoud be included to the page
 *  (by <script src="">)
 *  @param $switch_state_1 - default link text
 *  @param $switch_state_2 - link text 2
 *  @param $css_rule       - css rule matching the element(s) to show/hide
 *                         - '#id82422', '.details', '#my-page div.details'
 *  @param $is_on          - 1 if the code is on as default (default is 0)
 */
class AA_Stringexpand_Htmltogglecss extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    /**
     * @param string $switch_state_1
     * @param string $switch_state_2
     * @param string $css_rule
     * @param null $is_on
     * @return string|void
     */
    function expand($switch_state_1='', $switch_state_2='', $css_rule='', $is_on=null) {

        // it is nonsense to show expandable trigger if both contents are empty
        if ($css_rule == '') {
            return '';
        }

        if ($switch_state_1.$switch_state_2 == '') {
            $switch_state_1 = '[+]';
            $switch_state_2 = '[-]';
        }

        $class    = ($is_on == 1) ? ' is-on' : '';
        $selected = ($is_on == 1) ? 1 : 0;

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switches    = str_replace(array('[+]','[-]'), array(GetAAImage('plus.gif',  _m('show'), 15, 9), GetAAImage('minus.gif', _m('hide'), 15, 9)), array($switch_state_1, $switch_state_2));
        $switches_js = str_replace(array("'", '"', "\n", "\r"), array("\'", "\'", ' ', ' '), $switches);

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()

        $ret    = "<a class=\"togglelink$class\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlToggleCss('toggle_link_$uniqid', '{$switches_js[0]}', '{$switches_js[1]}', '$css_rule');return false;\">{$switches[$selected]}</a>\n";
        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_HtmlToggleCss
        return $ret;
    }
}

/** Expands {htmlajaxtogglecss:<toggle1>:<toggle2>:<css_rule_hide>:<url_of_text>[:<css_rule_update>]} like:
 *          {htmlajaxtogglecss:+:-:#id_#SITEM_ID:/apc-aa/view.php3?vid=33&cmd[33]=x-33-{_#SITEM_ID}}
 *  It creates the link text1 (or text2) +/- toggle which loads+displays/hides all
 *  elements matching the css_rule (#id82422) in our example
 *  The /javscript/aajslib.php shoud be included to the page
 *  (by <script src="">)
 *  @param $switch_state_1  - default link text
 *  @param $switch_state_2  - link text 2
 *  @param $css_rule_hide   - css rule matching the element(s) to show/hide (and possibly update)
 *                          - '#id82422', '.details', '#my-page div.details'
 *  @param $url_of_text     - url, which will be called by AJAX and displayed
 *                            on demand (click on the link)
 *  @param $css_rule_update - optional css rule matching the element(s) to update
 *                            if it is not the same as $css_rule_hide (good for updating table rows, where we want to show/hide tr, but update td)
 */
class AA_Stringexpand_Htmlajaxtogglecss extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    /**
     * @param string $switch_state_1
     * @param string $switch_state_2
     * @param string $css_rule_hide
     * @param string $url_of_text
     * @param string $css_rule_update
     * @return string|void
     */
    function expand($switch_state_1='', $switch_state_2='', $css_rule_hide='', $url_of_text='', $css_rule_update='') {

        // it is nonsense to show expandable trigger if both contents are empty
        if ($css_rule_hide == '') {
            return '';
        }

        if ($css_rule_update == '') {
            $css_rule_update = $css_rule_hide;
        }

        if ($switch_state_1.$switch_state_2 == '') {
            $switch_state_1 = '[+]';
            $switch_state_2 = '[-]';
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switches    = str_replace(array('[+]','[-]'), array(GetAAImage('plus.gif',  _m('show'), 15, 9), GetAAImage('minus.gif', _m('hide'), 15, 9)), array($switch_state_1, $switch_state_2));
        $switches_js = str_replace(array("'", '"', "\n", "\r"), array("\'", "\'", ' ', ' '), $switches);

        // automaticaly add conversion to utf-8 for AA view.php3 calls
        // -- removed - view.php now check for AJAX call automaticaly
        //if ((strpos($url_of_text,'/view.php3?') !== false) AND (strpos($url_of_text,'convert')===false)) {
        //    $url_of_text = get_url($url_of_text,array('convertto' => 'utf-8'));
        //}

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $ret   = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlAjaxToggleCss('toggle_link_$uniqid', '{$switches_js[0]}', '{$switches_js[1]}', '$css_rule_hide', '$url_of_text', '$css_rule_update');return false;\">{$switches[0]}</a>\n";
        return $ret;
    }
}


/** Displays live search (search field and the list of matching articles) on html page
 *    {livesearch:<view_param>[:<placeholder>[:<dafault_phrase>]]}
 *    {livesearch:3650}
 *    {livesearch:3650:search...:DoNotDisplayAnythingByDefault}
 *    {livesearch:3650&cmd[3650]=c-1-%22AA_LS_QUERY%22-2-publications:search for pubs...}
 *  It requires jQuery and aajslib-jquery.php
 *  @param $view_param      - just view ID or whole view parameters. If whole parameters used, then you have to use AA_LS_QUERY
 *                            constant on the place, where you want to put search phrase
 *                            example:
 *                              3560       // first condition used as in c-1-%22AA_LS_QUERY%22
 *                              3650&cmd[3650]=c-1-%22AA_LS_QUERY%22-2-publications
 *  @param $placeholder     - placeholder text to search field
 *  @param $default         - the phrase to search if the search field is empty.
 *                          - it is not necessary to fill it, if you want to see first the results by defaut
 *                          - you can put there some nonsence value, if you want to see the item list empty by default
 *
 */
class AA_Stringexpand_Livesearch extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    /**
     * @param string $view_param
     * @param string $placeholder
     * @param string $default
     * @return string|void
     */
    function expand($view_param='', $placeholder='', $default='') {
        $placeholder = safe($placeholder);
        $default     = safe($default);
        $id = 'aa-ls'.mt_rand(100000000,999999999);
        $view_param  = is_numeric($view_param) ? "$view_param&cmd[$view_param]=c-1-%22AA_LS_QUERY%22" : $view_param;
        $ret = "
            <article class=aa-widget id=$id>
              <header>
                <form action=? onsubmit=\"AA__liveSearch('$id', '$view_param', '$default'); return false;\">
                  <input type=search class=itemsearch name=itemsearch oninput=\"AA__liveSearch('$id', '$view_param', '$default')\" placeholder=\"$placeholder\"><i class=aa-utiltag></i>
                </form>
              </header>
              <section class=itemgroup>";
        $ret .= AA::Stringexpander()->unalias("{view.php3?vid=". str_replace('AA_LS_QUERY', $default, $view_param). "}");
        $ret .= "      </section>
            </article>";
        return $ret;
    }
}


/** Expands {shorten:<text>:<length>[:<mode>[:add]]} like:
 *          {shorten:{abstract.......1}:150}
 *  @return string - up to <length> characters from the <text>. If the <mode> is 1
 *  then it tries to identify only first paragraph or at least stop at the end
 *  of sentence. In all cases it strips HTML tags
 *  @param $text           - the shortened text
 *  @param $length         - max length
 *  @param $mode           - 0 - just cut on length
 *                         - 1 - try cut whole paragraph
 *                         - 2 - smart - use 0 for length < 50, 1 otherwise
 *                         - 3 - shorten in the middle with ...
 *  @param $add            - text added in case the text shorten
 *                           (so the resulting text will be at maximum length+add long)
 */
class AA_Stringexpand_Shorten extends AA_Stringexpand_Nevercache {
    // Never cache this code - it is most probably not repeating on the page
    /**
     * @param $text
     * @param $length
     * @param $text_add
     * @return string
     */
    public static function shorten_middle($text, $length)
    {
        $text = trim(strip_tags($text));
        $ret = aa_substr($text, 0, $length / 2 - 1) . '...';
        return $ret . aa_substr($text, aa_strlen($ret) - $length);
    }

    /** Do not trim all parameters (the $add parameter could contain space) */
    function doTrimParams() { return false; }

    /**
     * @param string $text
     * @param int $length
     * @param int $mode
     * @param string $add
     * @return string|void
     */
    function expand($text='', $length=49, $mode=2, $add='') {
        $length   = (int)$length;
        if (strlen($text) <= $length) {
            return $text;
        }
        if (strlen($strip_text = trim(strip_tags( $text ))) <= $length) {
            return $strip_text;
        }
        if ($mode==='') {
            $mode = 2;
        }
        $mode     = (int)$mode;
        if ($mode==2) {
            // do not try to find end of paragraph for short texts by default
            $mode = ($length >= 50) ? 1 : 0;
        }
        $text_add = $add;
        switch ($mode) {
            case 3: return self::shorten_middle($strip_text, $length);
            case 1:
                $shorted_modif = aa_substr(trim(strip_tags(str_ireplace(array('</p>', '<p>', '<br>', '<br />', '<br/>'), array('~@|^1', '~@|^1', '~@|^1', '~@|^1', '~@|^1'), $text))), 0, $length);
                if ( (($paraend = aa_strrpos($shorted_modif, '~@|^1')) > $length/2) ) {  // half is minimum
                    $shorted_text = str_replace('~@|^1',"\n",aa_substr($shorted_modif, 0, $paraend));
                    break;
                }

                $shorted_text = aa_substr($strip_text, 0, $length);
                $SEARCH_CRIT = array(array("\n", 2,0), array("\r", 2,0), array('.', 2,1), array("\n", 5,0), array("\r", 5,0), array('.', 5,1),array(' ', 10,0));
                foreach ($SEARCH_CRIT as $criterium) {
                    if ( (($paraend = aa_strrpos($shorted_text, $criterium[0])) > $length/$criterium[1])) {  // half is minimum
                        $shorted_text = aa_substr($shorted_text, 0, $paraend+$criterium[2]);
                        break 2;
                    }
                }
                break;

            default:
                $shorted_text = aa_substr($strip_text, 0, $length);
        }
        return strlen($shorted_text) ? $shorted_text . $text_add : '';
    }
}

/** Expands {expandable:<text>:<length>:<add>:<more>:<less>} like:
 *          {expandable:some long text:50:...:more >>>:less <<<}
 *  It creates the div and if the text is longer than <length> characters, then
 *  it adds <more> DHTML link in order user can see all the text
 *  The /javscript/aajslib.php shoud be included to the page
 *  (by <script src="">)
 *  @param $text           - default link text
 *  @param $length         - HTML code displayed as default (in div)
 *  @param $add            - add this to shortened text
 *  @param $more           - "see all text" link text
 *  @param $less           - "hide" link text
 */
class AA_Stringexpand_Expandable extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    /** Do not trim all parameters (the $add parameter could contain space) */
    function doTrimParams() { return false; }

    /**
     * @param string $text
     * @param int $length
     * @param string $add
     * @param string $switch_state_1
     * @param string $switch_state_2
     * @return string|void
     */
    function expand($text='', $length=49, $add='', $switch_state_1='', $switch_state_2='') {
        // it is nonsense to show expandable trigger if both contents are empty
        if (($text = trim($text)) == '') {
            return '';
        }

        $length = (int)$length;
        $switch_state_1 = trim($switch_state_1);
        $switch_state_2 = trim($switch_state_2);

        if ($switch_state_1 == '') {
            $switch_state_1 = '[+]';
            if (trim($switch_state_2) == '') {
                $switch_state_2 = '[-]';
            }
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switches    = str_replace(array('[+]','[-]'), array(GetAAImage('plus.gif',  _m('show'), 15, 9), GetAAImage('minus.gif', _m('hide'), 15, 9)), array($switch_state_1, $switch_state_2));
        $switches_js = str_replace(array("'", '"', "\n", "\r"), array("\'", "\'", ' ', ' '), $switches);

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()

        if (strlen(strip_tags($text))<=$length) {
            $ret = "<div class=\"expandableclass\" id=\"expandable_1_$uniqid\">$text</div>\n";
        } else {
            $text_2 = StrExpand('AA_Stringexpand_Shorten', array($text, $length));
            $link_1 = "<a class=\"expandablelink\" id=\"expandable_link1_$uniqid\" href=\"#\" onclick=\"event.stopPropagation();AA_HtmlToggle('expandable_link1_$uniqid', '', 'expandable_1_$uniqid', '{$switches_js[0]}', 'expandable_2_$uniqid');return false;\">{$switches[0]}</a>\n";
            $link_2 = !$switches[1] ? '' : "<a class=\"expandablelink\" id=\"expandable_link2_$uniqid\" href=\"#\" onclick=\"AA_HtmlToggle('expandable_link2_$uniqid', '', 'expandable_2_$uniqid', '{$switches_js[1]}', 'expandable_1_$uniqid');return false;\">{$switches[1]}</a>\n";
            $ret    = "<div class=\"expandableclass\" id=\"expandable_1_$uniqid\" style=\"cursor:pointer\" onclick=\"AA_HtmlToggle('expandable_link1_$uniqid', '', 'expandable_1_$uniqid', '{$switches_js[0]}', 'expandable_2_$uniqid');return false;\">$text_2".$add." $link_1</div>\n";
            $ret   .= "<div class=\"expandableclass\" id=\"expandable_2_$uniqid\" style=\"display:none;\">$text". " $link_2</div>\n";
        }
        return $ret;
    }
}

/** Expands {htmlajaxtoggle:<toggle1>:<text1>:<toggle2>:<url_of_text2>[:<position>]} like:
 *          {htmlajaxtoggle:more >>>:Econnect:less <<<:/about-ecn.html}
 *  It creates the link text1 (or text2) and two divs, where only one is visible
 *  at the time - first is displayed as defaut, the second is loaded by AJAX
 *  call on demand from specified url. The URL should be on the same server
 *  The /apc-aa/javascript/aajslib.php shoud be included to the page
 *  (by <script src="">)
 *  @param $switch_state_1 - default link text
 *  @param $code_1         - HTML code displayed as default (in div)
 *  @param $switch_state_2 - link text 2
 *  @param $url_of_text2   - url, which will be called by AJAX and displayed
 *                           on demand (click on the link)
 *  @param $position       - position of the link - top|bottom (top is default)
 */
class AA_Stringexpand_Htmlajaxtoggle extends AA_Stringexpand {

    /**
     * @param string $switch_state_1
     * @param string $code_1
     * @param string $switch_state_2
     * @param string $url
     * @param null $position
     * @return string|void
     */
    function expand($switch_state_1='', $code_1='', $switch_state_2='', $url='', $position=null) {

        if ($switch_state_1.$switch_state_2 == '') {
            $switch_state_1 = '[+]';
            $switch_state_2 = '[-]';
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switches    = str_replace(array('[+]','[-]'), array(GetAAImage('plus.gif',  _m('show'), 15, 9), GetAAImage('minus.gif', _m('hide'), 15, 9)), array($switch_state_1, $switch_state_2));
        $switches_js = str_replace(array("'", '"', "\n", "\r"), array("\'", "\'", ' ', ' '), $switches);

        // automaticaly add conversion to utf-8 for AA view.php3 calls
        // -- removed - view.php now check for AJAX call automaticaly
        //if ((strpos($url,'/view.php3?') !== false) AND (strpos($url,'convert')===false)) {
        //    $url = get_url($url,array('convertto' => 'utf-8'));
        //}

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $link   = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlAjaxToggle('toggle_link_$uniqid', '{$switches_js[0]}', 'toggle_1_$uniqid', '{$switches_js[1]}', 'toggle_2_$uniqid', '$url');return false;\">{$switches[0]}</a>\n";
        $ret    = "<div class=\"toggleclass\" id=\"toggle_1_$uniqid\">$code_1</div>\n";
        $ret   .= "<div class=\"toggleclass\" id=\"toggle_2_$uniqid\" style=\"display:none;\"></div>\n";
        return (trim($position)=='bottom') ?  $ret. $link : $link. $ret;
    }
}

/**
 * @param $exp
 * @return mixed|string
 */
function calculate($exp) {
    $replaces = 1;
    $exp = "($exp)";
    while ($replaces) {
        $exp = str_replace(array('{', '}', ' ',"\t", "\r", "\n", '&#8239;', ',', '(+', '(-', '(*', '(/', '(%', '(&', '(|', '+)', '-)', '*)', '/)', '%)', '&)', '|)', '()', '++', '--', '**') ,array('(', ')', '', '', '', '', '', '.', '(0+', '(0-', '(0*', '(0/', '(0%', '(0&', '(0|', '+0)', '-0)', '*0)', '/0)', '%0)', '&0)', '|0)', '0', '+', '-', '*'), "$exp", $replaces);
    }
    if (strspn($exp, '0123456789.+-*/%&|()E') != strlen($exp)) {
        return 'wrong characters';
    }
    try {
        $ret = @eval("return $exp;");
    } catch (ParseError $e) {
        return "Math Err: $exp";
    }
    return ($ret===false) ? "Math Err: $exp" : $ret;
}

// text = [ decimals [ # dec_point [ thousands_sep ]]] )
/** parseMath function
 * @param $text
 * @return string
 */
function parseMath($text) {
    // get format string, need to add and remove // to
    // allow for empty string

    $variable = aa_substr(strtok("#".$text,")"),1);
    $twos     = ParamExplode( strtok("") );
    $i        = 0;
    $key      = true;
    $ret      = '';

    while ( $i < count($twos) ) {
        $val = trim($twos[$i]);
        if ($key) {
            if ($val) {
                $ret.=str_replace("#:","",$val);
            }
            $key=false;
        } else {
            $val = calculate($val);
            if ($variable) {
                $format = explode("#",$variable);
                $val    = number_format((double)$val, (int)$format[0], $format[1], $format[2]);
            }
            $ret .= $val;
            $key  = true;
        }
        $i++;
    }
    return $ret;
}

/** parseLoop function - like AA_Stringexpand_List / AA_Stringexpand_@
 *  - in loop writes out values from field
 * @param $out
 * @param $item AA_Item
 * @return string
 */
function parseLoop($out, $item) {

    if ( !is_object($item) ) {
        return '';
    }

    // alternative syntax {@field...} or {list:field...}
    if ( (aa_substr($out,0,5) == "list:") ) {
        $out = '@'. aa_substr($out,5);
    }

    $params     = array();
    $format_str = '';
    // @field........... - without parameters
    if (aa_strpos($out, ":") === false) {
        $field = aa_substr($out, 1);
        $separator = ", "; // default separator
    } else { // with parameters
        // get field name
        $field = aa_substr($out, 1, aa_strpos($out, ":") - aa_strpos($out, "@")-1);
        // parameters - first is separator, second is format string
        list($separator,$format_str) = ParamExplode(aa_substr($out,aa_strpos($out,":")+1));

        if (aa_strpos($field, "(") !== false) { // if we have special parameters - in () after field name
            // get this special parameters
            $param  = aa_substr($field, aa_strpos($field, "(")+1,aa_strpos($field, ")")-aa_strpos($field, "(")-1);
            $params = explode(",",$param);
            // field name
            $field    = aa_substr($field, 0, aa_strpos($field, "("));
            $group_id = getConstantsGroupID($item->getSliceID(), $field);
        }
    }

    // get itemcontent object
    $itemcontent = $item->getItemContent();

    $val = array();
    // special - {@fieldlist...} - lists all the fields
    // (good for authomatic CSV generation, for example:
    //        Odd HTML is: {@fieldlist(_#CSV_FMTD):,:_#1}, where
    //        _#CSV_FMTD is defined as f_t and with parameter: {alias:{loop............}:f_t::csv}.
    if ( $field == 'fieldlist' ) {
        $item_slice  = AA_Slice::getModule($item->getSliceID());
        $val = $item_slice->fields('pri');
    } else {
        $val = $itemcontent->getValuesArray($field);
    }

    if ( empty($val) ) {
        return '';
    }

    $ret_str = '';
    if (!$format_str) {
        if ($separator=='json') {
            // we want JSON encoded array [value1,value2]
            $ret_str = json_encode($val);
        } else {
            // we don't have format string, so we return
            // separated values by $separator (default is ", ")
            $ret_str = join($separator, array_filter(array_map('trim',$val), 'strlen'));
        }
    } else { // we have format string
        if ( !$params ) {
            // case if we have only one parameter for substitution
            $ret_str = join($separator,  array_filter( array_map( function($value) use ($format_str) { return str_replace('_#1', $value, $format_str); }, $val), 'strlen'));

            // $val_delim = '';
            // foreach ($val as $value) {
            //     // nonsence check - $value is normal array - HM - 18-12-28
            //     // if (!strlen($value['value'])) {
            //     //     continue;
            //     // }
            //     $dummy     = str_replace("_#1", $value, $format_str);
            //     $ret_str   = $ret_str . $val_delim . $dummy;
            //     $val_delim = $separator;
            // }
        } else {
            $val_delim = '';
            // case with special parameters in ()
            foreach ($val as $value) { // loop for all values
                $dummy = $format_str; // make work-copy of format string
                for ($i=0, $forcount=count($params); $i<$forcount; $i++) { // for every special parameter do:
                    if (aa_substr($params[$i],0,6) == "const_") {
                        // what we need some constants parameters ( like name, short_id, value, ...)
                        $what = aa_substr($params[$i], aa_strpos($params[$i], "_")+1);
                        if ($what == 'value') {
                            $par = $value; // value is in $item, no need to use db
                        } else {
                            // for something else we need use db
                            $par = getConstantValue($group_id, $what, $value);
                        }
                    } elseif (aa_substr($params[$i],0,2) == "_#") { // special parameter is alias
                        /** alias could be used as:
                         *       {list:relation........(_#GET_HEAD): ,:_#1}
                         *  where _#GET_HEAD is alias defined somewhere in
                         *  current slice using f_t (for example):
                         *       {item:{loop............}:headline........}
                         *  this displays all the related headlines delimeted
                         *   by comma
                         */
                        // we need set some special field, which will be changed to actual
                        // constant value
                        $item->setAaValue('loop............', new AA_Value($value));
                        // get for this alias his output
                        $par = $item->get_alias_subst($params[$i], null, 'loop............');
                    }
                    $dummy = str_replace("_#".($i+1), $par, $dummy);
                }
                $ret_str   = $ret_str . $val_delim . $dummy;
                $val_delim = $separator;
            }
        }
    }
    return $ret_str;
}

/**  get constant group_id from content cache or get it from db
 *  @return string - group id for specified field
 * @param $slice_id
 * @param $field
 */
function getConstantsGroupID($slice_id, $field) {
    // GetCategoryGroup looks in database - there is a good chance, we will
    // expand {const_*} very soon (again), so we cache the result for future
    return AA::Contentcache()->get_result("GetCategoryGroup", array($slice_id, $field));
}

/** getConstantValue function
 * @param $group
 * @param $what
 * @param $field_name
 *  @return $what (name, value, short_id,...) of constants with
group $group and name $field_name)
 */
function getConstantValue($group, $what, $field_name) {
    switch ($what) { // this switch is for future changes in this code
        case "name" :
        case "value" :
        case "short_id":
        case "description" :
        case "pri" :
        case "group" :
        case "class" :
        case "id" :
        case "level" :
            // get values from contentcache or use GetConstants function to get it from db
            $val = AA::Contentcache()->get_result("GetConstants", array($group, 'pri', $what));
            return $val[$field_name];
            break;
        default :
            if (strlen($what)) {
                $val = AA::Contentcache()->get_result("GetConstants", array($group, 'pri', 'short_id'));
                $cid = $val[$field_name];
                if ($cid) {
                    $content = GetConstantContent(new zids($cid, 's'));
                    $item    = new AA_Item($content[$cid], GetAliases4Type('const'));
                    return $item->subst_alias($what);
                }
            }
            return false;
            break;
    }
}


/** How unaliasing and QuoteColons() works
 *
 *  The unaliasing works this way:
 *
 *    Ex: some text {ifset:{_#HEADLINE}:<h1>_#1</h1>} here
 *    //  say that healdline is "I'm headline (with {brackets})")
 *
 *  1) unalias innermost curly brackets.
 *     It is {_#HEADLINE}, so the by unaliasing this we get:
 *
 *    Ex:  I'm headline (with {brackets})
 *
 *     But we do not want to put such string instead of {_#HEADLINE}, since then
 *     would be the inner most curly brackets the {brackets} string. We do not
 *     want to unalias inside headline text, so we replace all the control
 *     characters by substitutes
 *     (@see QuoteColons():$QUOTED_ARRAY, $UNQUOTED_ARRAY)
 *
 *    Ex: some text {ifset:I'm headline _AA_OpEnPaR_with _AA_OpEnBrAcE_brackets_AA_ClOsEbRaCe__AA_ClOsEpAr_:<h1>_#1</h1>} here
 *
 *  2) Then we continue with standard unaliasing for inner most curly brackets,
 *     so we get:
 *
 *    Ex: some text <h1>I'm headline _AA_OpEnPaR_with _AA_OpEnBrAcE_brackets_AA_ClOsEbRaCe__AA_ClOsEpAr_</h1> here
 *
 *  3) after all we replace back all the substitutes:
 *
 *    Ex: some text <h1>I'm headline (with {brackets})</h1> here
 */

$UNQUOTED_ARRAY = array(":", "(", ")", "{", "}");
$QUOTED_ARRAY   = array("|~@_a", "|~@_b", "|~@_c", "|~@_d", "|~@_e", "_AA_ReMoVe");

/** QuoteColons function
 *  Substitutes all colons and othe special syntax characters with special AA
 *  string. Used to mark characters :{}() which are content, not syntax elements
 * @param $text
 * @return mixed
 */
function QuoteColons($text) {
    return str_replace($GLOBALS['UNQUOTED_ARRAY'], $GLOBALS['QUOTED_ARRAY'], $text);
}


/** DeQuoteColons function
 *  Substitutes special AA 'colon' string back to colon ':' character
 *  Used for parameters, where is no need colons are not parameter separators
 * @param $text
 * @return mixed
 */
function DeQuoteColons($text) {
    return str_replace($GLOBALS['QUOTED_ARRAY'], $GLOBALS['UNQUOTED_ARRAY'], $text);
}

/* just for speedup stringexpand parameter parsing */
/**
 * @param $text
 * @return mixed
 */
function DeQuoteColonsTrim($text) {
    return str_replace($GLOBALS['QUOTED_ARRAY'], $GLOBALS['UNQUOTED_ARRAY'], trim($text));
}

//$quot_arr    = array();
//$quot_ind    = array();
//$quot_hashes = array();
//
///** QuoteColons function
// *  Substitutes all colons with special AA string and back depending on unalias
// *  nesting. Used to mark characters :{}() which are content, not syntax
// *  elements
// * @param $text
// */
//function QuoteColons($text) {
//    global $quot_arr, $quot_ind, $quot_hashes;
//
//    if (!$text) {
//        return $text;
//    }
//    $hash  = hash('md5',$text);
//    if (!($index = $quot_hashes[$hash])) {
//        $index              = '~@@q'.count($quot_arr).'q';
//        $quot_hashes[$hash] = $index;
//        $quot_ind[]         = $index;
//        $quot_arr[]         = $text;
//    }
//    return $index;
//}
//
///** DeQuoteColons function
// *  Substitutes special AA 'colon' string back to colon ':' character
// *  Used for parameters, where is no need colons are not parameter separators
// * @param $text
// */
//function DeQuoteColons($text) {
//    global $quot_arr, $quot_ind, $quot_hashes;
//    return str_replace($quot_ind, $quot_arr, $text);
//}

/**
 * Class AA_Stringexpand_Cookie
 */
class AA_Stringexpand_Cookie extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $name
     * @return string
     */
    function expand($name='') {
        return $name ? $_COOKIE[$name] : json_encode($_COOKIE);
    }
}

/** Evaluates the expression
 *    {math:<expression>[:<decimals>[:<decimal point character>:<thousands separator>]]}
 *    {math:1+1-(2*6)}          - calculates the expression
 *    {math:1 421,823 }         - returns number usable for calculation - 1421.823
 *    {math:478778:1:,: }
 *    {math:478778:1:,:&#8239;} - correct French SI type of number format with thin space
 *    {math:478778:1:FR}        - correct French SI type of number format with thin space
 *    {math:478778:1:EN}        - correct English SI type of number format with thin space and decimal point
 */
class AA_Stringexpand_Math extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $add parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function  */
    function expand($expression='', $decimals='', $dec_point='', $thousands_sep = '') {
        $ret      = (double)calculate($expression);
        if ( !empty($dec_point) OR !empty($thousands_sep) ) {
            $decimals      = get_if($decimals,0);
            switch ($dec_point) {
                case 'FR':
                    $dec_point = ',';
                    $thousands_sep = '&#8239;';  // non breaking thin space
                    break;
                case 'EN':
                    $dec_point = '.';
                    $thousands_sep = '&#8239;';  // non breaking thin space
                    break;
                default:
                    $dec_point = get_if($dec_point, ',');
            }
            $ret = number_format($ret, (int)$decimals, $dec_point, $thousands_sep);
        } elseif ($decimals !== '') {
            $decimals = get_if($decimals,0);
            $ret      = number_format($ret, (int)$decimals);
        }
        return $ret;
    }
}

/** {intersect:<ids>:<ids>}
 *  @returns string - set of intersect of two set of ids (dash separated)
 */
class AA_Stringexpand_Specialstring extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($type='') {
        switch ($type) {
            case 'BOM': return chr(239) . chr(187) . chr(191);
        }
        return '';
    }
}


/** {intersect:<ids>:<ids>}
 *  @returns string - set of intersect of two set of ids (dash separated)
 */
class AA_Stringexpand_Intersect extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($set1='', $set2='') {
        return join('-', array_intersect(explode_ids($set1),explode_ids($set2)));
    }
}

/** {removeids:<ids1>:<ids2>}
 *  @returns string - ids1 where will be removed all the ids from ids2 (all dash separated)
 */
class AA_Stringexpand_Removeids extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($set1='', $set2='') {
        return join('-', array_diff(explode_ids($set1),explode_ids($set2)));
    }
}

/** (Current) date with specified date format {date:[<format>[:<timestamp>]]}
 *   {date:j.n.Y}                               displays 24.12.2008 on X-mas 2008
 *   {date:Y}                                   current year
 *   {date:m/d/Y:{math:{_#PUB_DATE}+(24*3600)}} day after publish date
 *   {date}                                     current timestamp
 *
 *   @param $format      - format - the same as PHP date() function
 *   @param $timestamp   - timestamp of the date (if not specified, current time
 *                         is used)
 *                         The parameter could be also in yyyy-mm-dd hh:mm:ss
 *                         format
 *   @param $no_date_text- text, displayed for the unset date
 *   @param $zone        - 'GMT' - if the time should be recounted to GMT
 *
 */
class AA_Stringexpand_Date extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($format='', $timestamp='', $no_date_text=null, $zone=null) {
        if ( empty($format) ) {
            $format = "U";
        } elseif ( (aa_strpos($format, 'DATE_') === 0) AND defined($format)) {
            $format = constant($format);
        }

        // no date (sometimes empty date is 3600 (based on timezone), so we
        // will use all the day 1.1.1970 as empty)
        if ( !is_null($no_date_text) AND ((trim($timestamp,'0')=='') OR (IsSigInt((string)$timestamp) AND abs($timestamp) < 86400))) {
            return $no_date_text;
        }
        if ( $timestamp=='' ) {
            $timestamp = time();
        } elseif ( !IsSigInt((string)$timestamp) ) {
            $timestamp = strtotime($timestamp);
        }
        return ($zone!='GMT') ? date($format, (int)$timestamp) : gmdate($format, (int)$timestamp);
    }
}


/** (Current) date with specified date format - alias for {date:...}
 *   {now:j.n.Y}                               displays 24.12.2008 on X-mas 2008
 *   {now:Y}                                   current year
 *   {now:m/d/Y:{math:{_#PUB_DATE}+(24*3600)}} day after publish date
 */
class AA_Stringexpand_Now extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($format='', $timestamp='') {
        return StrExpand('AA_Stringexpand_Date', array($format,$timestamp));
    }
}

/** return current timestamp based on the textual date provided
 *   {timestamp:2008-07-01}
 *   {timestamp:20080701t223807}
 *   {timestamp:next Monday}
 */
class AA_Stringexpand_Timestamp extends AA_Stringexpand_Nevercache {
    /**
     * @param string $datetime
     * @return false|int|void
     */
    function expand($datetime='') {
        return strtotime($datetime);
    }
}

/** return current timestamp based on the textual date provided
 *   {range:2008-2009-2010-2011}   - 2008-2011
 *   {range:1-4-5-6}               - 1, 4-6
 *   {range:{@year............:-}}
 *   {range:_#this}
 */
class AA_Stringexpand_Range extends AA_Stringexpand_Nevercache {
    /**
     * @param string $intvalues
     * @return string|void
     */
    function expand($intvalues='') {
        $ranges = array();
        $vals    = explode('-',$intvalues);
        $from    = null;
        $last    = null;
        foreach ($vals as $val) {
            if (is_null($from)) {
                $from = $val;
                $last = $val;
            } elseif ($val == $last+1) {
                $last = $val;
            } else {
                $ranges[] = ($from == $last) ? $from : $from.'-'.$last;
                $from = $val;
                $last = $val;
            }
        }
        if (!is_null($from)) {
            $ranges[] = ($from == $last) ? $from : $from.'-'.$last;
        }
        return join(', ',$ranges);
    }
}

/** Date range mostly for event calendar
 *   {daterange:<start_timestamp>:<end_timestamp>:<year_format>}
 *   {daterange:{start_date......}:{expiry_date.....}} - displays 24.12. - 28.12.2008
 *   @param $start_timestamp - timestamp of the start date
 *   @param $end_timestamp   - timestamp of the end date
 *   @param $year_format     - format - Y, y or empty for 2008, 08 or none
 *                             Y is default (2008)
 */
class AA_Stringexpand_Daterange extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($start_timestamp='', $end_timestamp='', $year_format='Y') {
        if ( date("j.n.$year_format", $start_timestamp) == date("j.n.$year_format", $end_timestamp) ) {
            $ret = date("j.n.$year_format", $start_timestamp);
        } elseif ( date("Y", $start_timestamp) == date("Y", $end_timestamp) ) {
            $ret = date("j.n.", $start_timestamp). '&nbsp;-&nbsp;'. date("j.n.$year_format", $end_timestamp);
        } else {
            $ret = date("j.n.$year_format", $start_timestamp). '&nbsp;-&nbsp;'. date("j.n.$year_format", $end_timestamp);
        }

        $starttime = date("G:i", $start_timestamp);
        if ( $starttime != "0:00") {
            $ret .= " $starttime";
        }

        $endtime = date("G:i", $end_timestamp);
        if( ($endtime != "0:00") AND ( $endtime != "23:59") AND ($endtime != $starttime)) {
            $ret .= "&nbsp;-&nbsp;$endtime";
        }

        return $ret;
    }
}

/**
 * Class AA_Stringexpand_Substr
 */
class AA_Stringexpand_Substr extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $add parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $string
     * @param $start
     * @param $length
     * @param $add
     * @return bool|string
     */
    function expand($string='', $start='', $length=999999999, $add='') {
        $ret = aa_substr($string,$start,$length);
        if ( $add AND (aa_strlen($ret) < aa_strlen($string)) ) {
            $ret .= $add;
        }
        return $ret;
    }
}

/** Allows you to get only the part of ids (first, last, ...) from the list
 *    {limit:<ids>:<offset>[:<length>[:<delimiter>]]}
 *    {limit:12324-353443-533443:0:1}   // returns 12324
 *    {limit:{ids:6353428288a923:d-category........-=-Dodo}:0:1}
 *  offset and length parameters follows the array_slice() PHP function
 *  @see http://php.net/array_slice
 */
class AA_Stringexpand_Limit extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids // parts separated by $delimiter
     * @param $offset // start index (first is 0). Could be negative.
     * @param $length // default is "to the end of the list". Colud be negative
     * @param $delimiter // default is '-'
     * @return string
     */
    function expand($ids='', $offset='', $length='', $delimiter='-') {
        // cut off spaces well as delimiters
        $arr = explode($delimiter, trim($ids, " \t\n\r\0\x0B\xA0" .((strlen($delimiter) == 1) ? $delimiter : '')));
        $arr = ($length === '') ? array_slice($arr, $offset) : array_slice($arr, $offset, $length);
        return join($delimiter, $arr);
    }
}

/** randomises the order of ids
 *    {shuffle:<ids>[:<limit>]}
 */
class AA_Stringexpand_Shuffle extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids // parts separated by '-'
     * @param $limit // number of returned shuffled ids
     * @return string
     */
    function expand($ids='', $limit=null) {
        $arr = explode_ids($ids);
        shuffle($arr);
        if ($limit) {
            $arr = array_slice($arr, 0, $limit);
        }
        return join('-', $arr);
    }
}

/** sorts the values
 *    {sort:<values>[:<order-type>[:<unique>[:<delimiter>[:<limit>]]]}
 */
class AA_Stringexpand_Sort extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /**
     */
    function expand($values='', $type=null, $unique='', $delimiter='',$limit='', $add='') {
        if (!strlen($delimiter)) {
            $delimiter = '-';
        }
        $arr = explode($delimiter, $values);
        switch ($type) {
            case 'locale':   sort($arr,  SORT_LOCALE_STRING); break;
            case 'string':   sort($arr,  SORT_STRING);        break;
            case 'rnumeric': rsort($arr, SORT_NUMERIC);       break;
            case 'rstring':  rsort($arr, SORT_STRING);        break;
            case 'rlocale':  rsort($arr, SORT_LOCALE_STRING); break;
            default:         sort($arr,  SORT_NUMERIC);       break;
        }
        if ($unique=='1')  {
            $arr = array_unique($arr);
        }
        if (ctype_digit((string)$limit) AND (count($arr)>$limit)) {
            $arr   = array_slice($arr,0,$limit);
            if (strlen($add)) {
                $arr[] = $add;
            }
        }
        return join($delimiter, $arr);
    }
}


/** Next item for the current item in the list
 *    {next:<ids>:<current_id>}
 *    {next:12324-353443-58921:353443}   // returns 58921
 *    {next:{ids:6353428288a923:d-category........-=-Dodo}:566a655e7787b564b8b6565b}
 */
class AA_Stringexpand_Next extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids // item ids separated by '-' (long or short)
     * @param $current_id // id of the item in question - id should be of the same type as in $ids
     * @return string
     */
    function expand($ids='', $current_id='') {
        if (!$ids OR !$current_id) {
            return '';
        }
        $arr = explode_ids($ids);
        $key = array_search($current_id, $arr);
        return (($key !== false) AND isset($arr[$key+1])) ? $arr[$key+1] : '';
    }
}

/** Unique - removes duplicate ids form the string
 */
class AA_Stringexpand_Unique extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids // item ids (or any other values) separated by '-'
     * @param $delimiter // separator of the parts - by default it is '-', but
     *                       you can use any one
     * @return string
     */
    function expand($ids='', $delimiter='') {
        if (!($ids = trim($ids))) {
            return '';
        }
        if (empty($delimiter)) {
            if ($ids[0] == '[') {
                return json_encode(array_values(array_unique(json2arr($ids))));
            }
            $delimiter = '-';
        }
        return join($delimiter, array_unique(array_filter(explode($delimiter, $ids),'trim')));
    }
}

/** Counts ids or other string parts separated by delimiter
 *  It is much quicker to use this function for counting of ids, than
 *  {aggregate:count..} since this one do not grab the item data from
 *  the database
 *    {count:<ids>[:<delimiter>]}
 *    {count:12324-353443-58921}   // returns 3
 *    {count:{ids:6353428288a923:d-category........-=-Dodo}}
 */
class AA_Stringexpand_Count extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids // item ids separated by '-' (long or short)
     * @param $delimiter // separator of the parts - by default it is '-', but
     *                       you can use any one
     * @return int
     */
    function expand($ids='', $delimiter='') {
        if (!($ids=trim($ids))) {
            return 0;
        }
        if (empty($delimiter)) {
            $delimiter = '-';
        }
        return count(array_filter(explode($delimiter, $ids),'trim'));  // count only not empty members
    }
}


/** Previous item for the current item in the list
 *    {previous:<ids>:<current_id>}
 *    {previous:12324-353443-58921:353443}   // returns 12324
 *    {previous:{ids:6353428288a923:d-category........-=-Dodo}:566a655e7787b564b8b6565b}
 */
class AA_Stringexpand_Previous extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids // item ids separated by '-' (long or short)
     * @param $current_id // id of the item in question - id should be of the same type as in $ids
     * @return string
     */
    function expand($ids='', $current_id='') {
        if (!$ids OR !$current_id) {
            return '';
        }
        $arr = explode_ids($ids);
        $key = array_search($current_id, $arr);
        return ($key AND isset($arr[$key-1])) ? $arr[$key-1] : '';
    }
}

/** Escapes the text for CSV export */
function Csv_escape($text) {
    return (strcspn($text,",\"\n\r") == strlen($text)) ? $text : '"'.str_replace('"', '""', str_replace("\r\n", "\n", $text)).'"';
}

/**
 * Class AA_Stringexpand_Csv
 */
class AA_Stringexpand_Csv extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     * @return string
     */
    function expand($text='') {
        return Csv_escape($text);
    }
}


/** Escapes the HTML special chars (>,<,&,...) and also prevents to double_encode
 *  already encoded entities (like &amp;quote;) - as oposite to {htmlspecialchars}
 */
class AA_Stringexpand_Safe extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text string - to escape
     * @return string
     */
    function expand($text='') {
        return myspecialchars($text,false);
    }
}

/** generates acsii only username or filename from the string */
class AA_Stringexpand_Asciiname extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     * @return string
     */
    function expand($string='',$encoding='') {
        return ConvertCharset::singleton()->escape($string, empty($encoding) ? 'utf-8' : $encoding);
    }
}

/** Encodes string for JSON - (quotes, newlines) ' => \', ... and converts to utf-8 if it is not */
class AA_Stringexpand_Jsonstring extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $string
     * @return string
     */
    function expand($string='') {
        if (AA::$encoding AND (AA::$encoding != 'utf-8')) {
            return json_encode(ConvertEncoding($string, AA::$encoding));
        }
        return json_encode($string);
    }
}

/** Returns Text as is.
 *  Looks funny, but it is usefull. If you write {abstract........}, then it
 *  is NOT the same as {asis:abstract........}, since {abstract........} counts
 *  with HTML/plaintext setting, so maybe the \n are replaced by <br> if
 *  "plaintext" is set for the field. The {asis:abstract........} returns the
 *  exact value as inserted in the database
 */
class AA_Stringexpand_Asis extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     * @return bool|string
     */
    function expand($text='') {
        $params = func_get_args();
        $item   = $this ? $this->item : null;
        return ($this AND is_object($item) AND $item->isField($text)) ? $item->getval($text) : join(':', $params);
    }
}

/** Escape apostrophs and convert HTML entities
 *  If you want to put the JS code in HTML: tag attribute, consider using onclick="{safe:{javascript:code...}}"
 */
class AA_Stringexpand_Javascript extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     * @return mixed
     */
    function expand($text='') {
        return str_replace(array("'","\r\n", "\r", "\n"), array("\'", " ", " ", " "), $text);
    }
}

/** Converts the content of the field to html better than standard {text............} comand would do
 *  (makes html links from urls...)Checks the text for urls and make it clickable
 */
class AA_Stringexpand_Field2html extends AA_Stringexpand_Text2html {

    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $field_id string
     * @return string
     */
    function expand($field_id='') {
        return ($this AND is_object($item = $this->item) AND $item->isField($field_id)) ? $item->col2Html($field_id) : '';
    }
}



/** Used for sending text e-mails by {mail...} function */
class AA_Stringexpand_Text2html extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     * @return string
     */
    function expand($text='') {
        return nl2br(str_replace('  ', ' &ensp;', self::htmlEscapeAndLinkUrls($text)));
    }

    /**
     *  UrlLinker - facilitates turning plain text URLs into HTML links.
     *  Author: Søren Løvborg (https://bitbucket.org/kwi/urllinker)
     *  http://creativecommons.org/publicdomain/zero/1.0/
     * @param      $text
     * @param bool $escape
     * @return string
     */
    public static function htmlEscapeAndLinkUrls($text, $escape=true) {
        /* Regular expression bits used by htmlEscapeAndLinkUrls() to match URLs.   */
        $rexScheme    = 'https?://';
        // $rexScheme    = "$rexScheme|ftp://"; // Uncomment this line to allow FTP addresses.
        $rexDomain    = '(?:[-a-zA-Z0-9\x7f-\xff]{1,63}\.)+[a-zA-Z\x7f-\xff][-a-zA-Z0-9\x7f-\xff]{1,62}';
        $rexIp        = '(?:[1-9][0-9]{0,2}\.|0\.){3}(?:[1-9][0-9]{0,2}|0)';
        $rexPort      = '(:[0-9]{1,5})?';
        $rexPath      = '(/[!$-/0-9:;=@_\':;!a-zA-Z\x7f-\xff]*?)?';
        $rexQuery     = '(\?[!$-/0-9:;=@_\':;!a-zA-Z\x7f-\xff]+?)?';
        $rexFragment  = '(#[!$-/0-9?:;=@_\':;!a-zA-Z\x7f-\xff]+?)?';
        $rexUsername  = '[^]\\\\\x00-\x20\"(),:-<>[\x7f-\xff]{1,64}';
        $rexPassword  = $rexUsername; // allow the same characters as in the username
        $rexUrl       = "($rexScheme)?(?:($rexUsername)(:$rexPassword)?@)?($rexDomain|$rexIp)($rexPort$rexPath$rexQuery$rexFragment)";
        $rexTrailPunct= "[)'?.!,;:]"; // valid URL characters which are not part of the URL if they appear at the very end
        $rexNonUrl    = "[^-_#$+.!*%'(),;/?:@=&a-zA-Z0-9\x7f-\xff]"; // characters that should never appear in a URL
        $rexUrlLinker = "{\\b$rexUrl(?=$rexTrailPunct*($rexNonUrl|$))}";
        $rexUrlLinker .= 'i'; // Uncomment this line to allow uppercase URL schemes (e.g. "HTTP://google.com").

        /** $validTlds is an associative array mapping valid TLDs to the value true.
         *  List source:  http://data.iana.org/TLD/tlds-alpha-by-domain.txt
         *  Last updated: 2016-05-04
         */
        $validTlds = array_fill_keys(explode(" ", '.aaa .aarp .abb .abbott .abbvie .abogado .abudhabi .ac .academy .accenture .accountant .accountants .aco .active .actor .ad .adac .ads .adult .ae .aeg .aero .af .afl .ag .agakhan .agency .ai .aig .airforce .airtel .akdn .al .alibaba .alipay .allfinanz .ally .alsace .am .amica .amsterdam .analytics .android .anquan .ao .apartments .app .apple .aq .aquarelle .ar .aramco .archi .army .arpa .arte .as .asia .associates .at .attorney .au .auction .audi .audio .author .auto .autos .avianca .aw .aws .ax .axa .az .azure .ba .baby .baidu .band .bank .bar .barcelona .barclaycard .barclays .barefoot .bargains .bauhaus .bayern .bb .bbc .bbva .bcg .bcn .bd .be .beats .beer .bentley .berlin .best .bet .bf .bg .bh .bharti .bi .bible .bid .bike .bing .bingo .bio .biz .bj .black .blackfriday .bloomberg .blue .bm .bms .bmw .bn .bnl .bnpparibas .bo .boats .boehringer .bom .bond .boo .book .boots .bosch .bostik .bot .boutique .br .bradesco .bridgestone .broadway .broker .brother .brussels .bs .bt .budapest .bugatti .build .builders .business .buy .buzz .bv .bw .by .bz .bzh .ca .cab .cafe .cal .call .camera .camp .cancerresearch .canon .capetown .capital .car .caravan .cards .care .career .careers .cars .cartier .casa .cash .casino .cat .catering .cba .cbn .cc .cd .ceb .center .ceo .cern .cf .cfa .cfd .cg .ch .chanel .channel .chase .chat .cheap .chloe .christmas .chrome .church .ci .cipriani .circle .cisco .citic .city .cityeats .ck .cl .claims .cleaning .click .clinic .clinique .clothing .cloud .club .clubmed .cm .cn .co .coach .codes .coffee .college .cologne .com .commbank .community .company .compare .computer .comsec .condos .construction .consulting .contact .contractors .cooking .cool .coop .corsica .country .coupon .coupons .courses .cr .credit .creditcard .creditunion .cricket .crown .crs .cruises .csc .cu .cuisinella .cv .cw .cx .cy .cymru .cyou .cz .dabur .dad .dance .date .dating .datsun .day .dclk .de .dealer .deals .degree .delivery .dell .deloitte .delta .democrat .dental .dentist .desi .design .dev .diamonds .diet .digital .direct .directory .discount .dj .dk .dm .dnp .do .docs .dog .doha .domains .download .drive .dubai .durban .dvag .dz .earth .eat .ec .edeka .edu .education .ee .eg .email .emerck .energy .engineer .engineering .enterprises .epson .equipment .er .erni .es .esq .estate .et .eu .eurovision .eus .events .everbank .exchange .expert .exposed .express .extraspace .fage .fail .fairwinds .faith .family .fan .fans .farm .fashion .fast .feedback .ferrero .fi .film .final .finance .financial .firestone .firmdale .fish .fishing .fit .fitness .fj .fk .flickr .flights .florist .flowers .flsmidth .fly .fm .fo .foo .football .ford .forex .forsale .forum .foundation .fox .fr .fresenius .frl .frogans .frontier .ftr .fund .furniture .futbol .fyi .ga .gal .gallery .gallo .gallup .game .garden .gb .gbiz .gd .gdn .ge .gea .gent .genting .gf .gg .ggee .gh .gi .gift .gifts .gives .giving .gl .glass .gle .global .globo .gm .gmail .gmbh .gmo .gmx .gn .gold .goldpoint .golf .goo .goog .google .gop .got .gov .gp .gq .gr .grainger .graphics .gratis .green .gripe .group .gs .gt .gu .gucci .guge .guide .guitars .guru .gw .gy .hamburg .hangout .haus .hdfcbank .health .healthcare .help .helsinki .here .hermes .hiphop .hitachi .hiv .hk .hm .hn .hockey .holdings .holiday .homedepot .homes .honda .horse .host .hosting .hoteles .hotmail .house .how .hr .hsbc .ht .htc .hu .hyundai .ibm .icbc .ice .icu .id .ie .ifm .iinet .il .im .imamat .immo .immobilien .in .industries .infiniti .info .ing .ink .institute .insurance .insure .int .international .investments .io .ipiranga .iq .ir .irish .is .iselect .ismaili .ist .istanbul .it .itau .iwc .jaguar .java .jcb .jcp .je .jetzt .jewelry .jlc .jll .jm .jmp .jnj .jo .jobs .joburg .jot .joy .jp .jpmorgan .jprs .juegos .kaufen .kddi .ke .kerryhotels .kerrylogistics .kerryproperties .kfh .kg .kh .ki .kia .kim .kinder .kitchen .kiwi .km .kn .koeln .komatsu .kp .kpmg .kpn .kr .krd .kred .kuokgroup .kw .ky .kyoto .kz .la .lacaixa .lamborghini .lamer .lancaster .land .landrover .lanxess .lasalle .lat .latrobe .law .lawyer .lb .lc .lds .lease .leclerc .legal .lexus .lgbt .li .liaison .lidl .life .lifeinsurance .lifestyle .lighting .like .limited .limo .lincoln .linde .link .lipsy .live .living .lixil .lk .loan .loans .locus .lol .london .lotte .lotto .love .lr .ls .lt .ltd .ltda .lu .lupin .luxe .luxury .lv .ly .ma .madrid .maif .maison .makeup .man .management .mango .market .marketing .markets .marriott .mba .mc .md .me .med .media .meet .melbourne .meme .memorial .men .menu .meo .mg .mh .miami .microsoft .mil .mini .mk .ml .mls .mm .mma .mn .mo .mobi .mobily .moda .moe .moi .mom .monash .money .montblanc .mormon .mortgage .moscow .motorcycles .mov .movie .movistar .mp .mq .mr .ms .mt .mtn .mtpc .mtr .mu .museum .mutual .mutuelle .mv .mw .mx .my .mz .na .nadex .nagoya .name .natura .navy .nc .ne .nec .net .netbank .network .neustar .new .news .next .nextdirect .nexus .nf .ng .ngo .nhk .ni .nico .nikon .ninja .nissan .nissay .nl .no .nokia .northwesternmutual .norton .nowruz .np .nr .nra .nrw .ntt .nu .nyc .nz .obi .office .okinawa .olayan .om .omega .one .ong .onl .online .ooo .oracle .orange .org .organic .origins .osaka .otsuka .ovh .pa .page .pamperedchef .panerai .paris .pars .partners .parts .party .passagens .pe .pet .pf .pg .ph .pharmacy .philips .photo .photography .photos .physio .piaget .pics .pictet .pictures .pid .pin .ping .pink .pizza .pk .pl .place .play .playstation .plumbing .plus .pm .pn .pohl .poker .porn .post .pr .praxi .press .pro .prod .productions .prof .progressive .promo .properties .property .protection .ps .pt .pub .pw .pwc .py .qa .qpon .quebec .quest .racing .re .read .realtor .realty .recipes .red .redstone .redumbrella .rehab .reise .reisen .reit .ren .rent .rentals .repair .report .republican .rest .restaurant .review .reviews .rexroth .rich .ricoh .rio .rip .ro .rocher .rocks .rodeo .room .rs .rsvp .ru .ruhr .run .rw .rwe .ryukyu .sa .saarland .safe .safety .sakura .sale .salon .samsung .sandvik .sandvikcoromant .sanofi .sap .sapo .sarl .sas .saxo .sb .sbi .sbs .sc .sca .scb .schaeffler .schmidt .scholarships .school .schule .schwarz .science .scor .scot .sd .se .seat .security .seek .select .sener .services .seven .sew .sex .sexy .sfr .sg .sh .sharp .shaw .shell .shia .shiksha .shoes .shouji .show .shriram .si .sina .singles .site .sj .sk .ski .skin .sky .skype .sl .sm .smile .sn .sncf .so .soccer .social .softbank .software .sohu .solar .solutions .song .sony .soy .space .spiegel .spot .spreadbetting .sr .srl .st .stada .star .starhub .statebank .statefarm .statoil .stc .stcgroup .stockholm .storage .store .stream .studio .study .style .su .sucks .supplies .supply .support .surf .surgery .suzuki .sv .swatch .swiss .sx .sy .sydney .symantec .systems .sz .tab .taipei .talk .taobao .tatamotors .tatar .tattoo .tax .taxi .tc .tci .td .team .tech .technology .tel .telecity .telefonica .temasek .tennis .teva .tf .tg .th .thd .theater .theatre .tickets .tienda .tiffany .tips .tires .tirol .tj .tk .tl .tm .tmall .tn .to .today .tokyo .tools .top .toray .toshiba .total .tours .town .toyota .toys .tr .trade .trading .training .travel .travelers .travelersinsurance .trust .trv .tt .tube .tui .tunes .tushu .tv .tvs .tw .tz .ua .ubs .ug .uk .unicom .university .uno .uol .us .uy .uz .va .vacations .vana .vc .ve .vegas .ventures .verisign .versicherung .vet .vg .vi .viajes .video .vig .viking .villas .vin .vip .virgin .vision .vista .vistaprint .viva .vlaanderen .vn .vodka .volkswagen .vote .voting .voto .voyage .vu .vuelos .wales .walter .wang .wanggou .warman .watch .watches .weather .weatherchannel .webcam .weber .website .wed .wedding .weibo .weir .wf .whoswho .wien .wiki .williamhill .win .windows .wine .wme .wolterskluwer .work .works .world .ws .wtc .wtf .xbox .xerox .xihuan .xin .xn--11b4c3d .xn--1ck2e1b .xn--1qqw23a .xn--30rr7y .xn--3bst00m .xn--3ds443g .xn--3e0b707e .xn--3pxu8k .xn--42c2d9a .xn--45brj9c .xn--45q11c .xn--4gbrim .xn--55qw42g .xn--55qx5d .xn--5tzm5g .xn--6frz82g .xn--6qq986b3xl .xn--80adxhks .xn--80ao21a .xn--80asehdb .xn--80aswg .xn--8y0a063a .xn--90a3ac .xn--90ais .xn--9dbq2a .xn--9et52u .xn--9krt00a .xn--b4w605ferd .xn--bck1b9a5dre4c .xn--c1avg .xn--c2br7g .xn--cck2b3b .xn--cg4bki .xn--clchc0ea0b2g2a9gcd .xn--czr694b .xn--czrs0t .xn--czru2d .xn--d1acj3b .xn--d1alf .xn--e1a4c .xn--eckvdtc9d .xn--efvy88h .xn--estv75g .xn--fct429k .xn--fhbei .xn--fiq228c5hs .xn--fiq64b .xn--fiqs8s .xn--fiqz9s .xn--fjq720a .xn--flw351e .xn--fpcrj9c3d .xn--fzc2c9e2c .xn--g2xx48c .xn--gckr3f0f .xn--gecrj9c .xn--h2brj9c .xn--hxt814e .xn--i1b6b1a6a2e .xn--imr513n .xn--io0a7i .xn--j1aef .xn--j1amh .xn--j6w193g .xn--jlq61u9w7b .xn--jvr189m .xn--kcrx77d1x4a .xn--kprw13d .xn--kpry57d .xn--kpu716f .xn--kput3i .xn--l1acc .xn--lgbbat1ad8j .xn--mgb9awbf .xn--mgba3a3ejt .xn--mgba3a4f16a .xn--mgba7c0bbn0a .xn--mgbaam7a8h .xn--mgbab2bd .xn--mgbayh7gpa .xn--mgbb9fbpob .xn--mgbbh1a71e .xn--mgbc0a9azcg .xn--mgbca7dzdo .xn--mgberp4a5d4ar .xn--mgbpl2fh .xn--mgbt3dhd .xn--mgbtx2b .xn--mgbx4cd0ab .xn--mix891f .xn--mk1bu44c .xn--mxtq1m .xn--ngbc5azd .xn--ngbe9e0a .xn--node .xn--nqv7f .xn--nqv7fs00ema .xn--nyqy26a .xn--o3cw4h .xn--ogbpf8fl .xn--p1acf .xn--p1ai .xn--pbt977c .xn--pgbs0dh .xn--pssy2u .xn--q9jyb4c .xn--qcka1pmc .xn--qxam .xn--rhqv96g .xn--rovu88b .xn--s9brj9c .xn--ses554g .xn--t60b56a .xn--tckwe .xn--unup4y .xn--vermgensberater-ctb .xn--vermgensberatung-pwb .xn--vhquv .xn--vuq861b .xn--w4r85el8fhu5dnra .xn--wgbh1c .xn--wgbl6a .xn--xhq521b .xn--xkc2al3hye2a .xn--xkc2dl3a5ee0h .xn--y9a3aq .xn--yfro4i67o .xn--ygbi2ammx .xn--zfr164b .xperia .xxx .xyz .yachts .yahoo .yamaxun .yandex .ye .yodobashi .yoga .yokohama .you .youtube .yt .yun .za .zara .zero .zip .zm .zone .zuerich .zw'), true);

        $html = '';
        $position = 0;
        $match = array();

        while (preg_match($rexUrlLinker, $text, $match, PREG_OFFSET_CAPTURE, $position)) {
            list($url, $urlPosition) = $match[0];

            // Add the text leading up to the URL.
            if ($escape) {
                $html .= myspecialchars(aa_substr($text, $position, $urlPosition - $position));
            } else {
                $html .= aa_substr($text, $position, $urlPosition - $position);
            }

            $scheme      = $match[1][0];
            $username    = $match[2][0];
            $password    = $match[3][0];
            $domain      = $match[4][0];
            $afterDomain = $match[5][0]; // everything following the domain
            $port        = $match[6][0];
            $path        = $match[7][0];

            // Check that the TLD is valid or that $domain is an IP address.
            $tld = strtolower(strrchr($domain, '.'));
            if (preg_match('{^\.[0-9]{1,3}$}', $tld) || isset($validTlds[$tld])) {
                // Do not permit implicit scheme if a password is specified, as
                // this causes too many errors (e.g. "my email:foo@example.org").
                if (!$scheme && $password) {
                    $html .= myspecialchars($username);

                    // Continue text parsing at the ':' following the "username".
                    $position = $urlPosition + aa_strlen($username);
                    continue;
                }

                if (!$scheme && $username && !$password && !$afterDomain) {
                    // Looks like an email address.
                    $completeUrl = "mailto:$url";
                    $linkText = $url;
                } else {
                    // Prepend http:// if no scheme is specified
                    $completeUrl = $scheme ? $url : "http://$url";
                    $linkText = "$domain$port$path";
                }
                // Cheap e-mail obfuscation to trick the dumbest mail harvesters.
                $html .= str_replace('@', '&#64;', '<a href="' . myspecialchars($completeUrl) . '">' . myspecialchars($linkText) . '</a>');
            } else {
                // Not a valid URL.
                $html .= myspecialchars($url);
            }

            // Continue text parsing from after the URL.
            $position = $urlPosition + strlen($url);
        }

        // Add the remainder of the text.
        $html .= myspecialchars(aa_substr($text, $position));
        return $html;
    }
}

/** converts html to text */
class AA_Stringexpand_Html2text extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $html string
     * @return null|string|string[]
     */
    function expand($html='') {
        return html2text($html);
    }
}


/** Base64url version of base64
 *  name={bin2url:Mojžíš}
 *  id={bin2url:{encrypt:{id..............}:password}}
 */
class AA_Stringexpand_Bin2url extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /**
     * @param string $data
     * @return string|void
     */
    function expand($data='') {
        return bin2url($data);
    }
}

/** reverse Base64url version of base64 */
class AA_Stringexpand_Url2bin extends AA_Stringexpand_Nevercache {
    /**
     * @param string $data
     * @return bool|string|void
     */
    function expand($data='') {
        return url2bin($data);
    }
}

/** Just escape apostrophs ' => \' */
class AA_Stringexpand_Quote extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     * @return mixed
     */
    function expand($text='') {
        return str_replace("'", "\'", str_replace('\\', '\\\\', $text));
    }
}

/**
 * Class AA_Stringexpand_Rss
 */
class AA_Stringexpand_Rss extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     * @return bool|string
     */
    function expand($text='', $maxlen='') {
        $entities_old = array('&nbsp;', '& ');
        $entities_new = array(' ', '&amp; ');
        $ret = trim(str_replace($entities_old, $entities_new, strip_tags($text)));
        return ctype_digit((string)$maxlen) ? aa_substr($ret, 0, $maxlen) : $ret;
    }
}


/** reads RSS chanel from remote url and converts it to HTML and displays
 *    {rss2html:<rss_url>[:max_number_of_items]}
 *    {rss2html:http#://www.ekobydleni.eu/feed/:5}
 *  or more advanced example with header and encoding change
 *     <h2><a href="http://www.ekobydleni.eu">www.ekobydleni.eu</a></h2>
 *     {convert:{rss2html:http#://www.ekobydleni.eu/feed/:5}:utf-8:windows-1250}
 *
 *  Used XSL extension of PHP5. PHP must be compiled with XSL support
 */
class AA_Stringexpand_Rss2html extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     * @return string
     */
    function expand($rss_url='', $number='') {

        $xsl_cond = ($number>0) ? '[position() &lt; '. ($number+1) .']' : '';

        // načtení dokumentu XML
        $xml = new DomDocument();
        $xml->load($rss_url);

        // načtení stylu XSLT
        $xsl = new DomDocument();
        $xsl->loadXML('<?xml version="1.0" encoding="utf-8"?>
            <xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">
            <xsl:output method="html" encoding="utf-8" doctype-public="-//W3C//DTD HTML 4.01//EN"/>
            <xsl:template match="channel">
                  <ul>
                    <xsl:for-each select="item'.$xsl_cond.'">
                      <li><a href="{link}"><xsl:value-of select="title"/></a></li>
                    </xsl:for-each>
                  </ul>
            </xsl:template>
            </xsl:stylesheet>
        ');

        // vytvoření procesoru XSLT
        $proc = new xsltprocessor();
        $proc->importStylesheet($xsl);

        // provedení transformace a vypsání výsledku
        return $proc->transformToXML($xml);
    }
}


/**
 * Converts the string to anoteher encoding. Wrong characters are transliterated.
 * Ussage:  {convert:{include:http://example.org/page.html}:windows-1250:utf-8}
 *          {convert:{full_text.......}:windows-1250:us-ascii}
 *
 */
class AA_Stringexpand_Convert extends AA_Stringexpand {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param string $text  text to convert
     * @param string $from  source      encoding utf-8|windows-1250|us-ascii|...  - utf-8 is default
     * @param string $to    destination encoding utf-8|windows-1250|us-ascii|...  - utf-8 is default
     * @return string
     */
    function expand($text='', $from='', $to='') {
        return ($from==$to) ? $text : ConvertEncoding($text, trim($from), trim($to));
    }
}


// class AA_Stringexpand_Increase extends AA_Stringexpand {
//     /** expand function
//      * @param $text
//      */
//     function expand($id='', $field_id='') {
//         echo "<script src=\"/aaa/javascript/increse.php?item_id=$id&field_id=$field_id\"></script>";
//     }
// }
/** Ussage:
 *    {view:57::page-{xpage}}
 *    {view:57:{ids:0497ac46076bf257d15f3e030170da92:d-category........-=-Env}}
 *    {view:45::group_by-}   // switches off grouping in the view
 *    {view:45::listlen-3,sort-headline........-,from-2}}
 */
class AA_Stringexpand_View extends AA_Stringexpand {
    /** expand function
     * @param $vid , $ids
     * @return string
     */
    function expand($vid=null, $ids=null, $settings=null) {
        if (!$vid) {
            return '';
        }
        $view_param = array('vid' => $vid);
        if (strlen($ids)) {
            $zids = new zids();
            $zids->addDirty(explode_ids($ids));
            $view_param['zids'] = $zids;
        }
        if (isset($settings)) {
            $view_param = array_merge($view_param, ParseSettings(array($settings)));
        }
        // do not pagecache the view
        return with(new AA_Showview($view_param))->getViewOutput();
    }
}

/** displays current poll from polls module specified by its pid
 *  {polls:5ad0d95a490b383ac7d23650e7aa49ee:design_id=4326b8bf6b64b2a216147d896b3860b2&listlen=50&conds[0][operator]=>&conds[0][value]=1&conds[0][expiry_date]=1}
 */
class AA_Stringexpand_Polls extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $pid
     * @return string
     */
    function expand($pid='', $params=null) {
        require_once AA_BASE_PATH."modules/polls/include/util.php3";
        require_once AA_BASE_PATH."modules/polls/include/stringexpand.php3";

        $request = array();
        if ($params) {
            parse_str($params, $request);
        }
        $request['pid']=$pid;

        return AA_Poll::processPoll($request);
    }
}



/** Allows you to call view with conds:
 *    {view.php3?vid=9&cmd[9]=c-1-{conds:{_#VALUE___}}}
 *  or
 *    {view.php3?vid=9&cmd[9]=c-1-{conds:category.......1}}
 *  or
 *    {ids:5367e68a88b82887baac311c30544a71:d-headline........-=-{conds:category.......3:1}}
 *  see the third parameter (1) in the last example!
 *    {ids:5367e68a88b82887baac311c30544a71:d-headline........-=-{conds:{qs:type}:1}}
 *  works also for multivalue variable (type[] $_GET variable in the last example)
 *
 *  The syntax is:
 *     {conds:<field or text>[:<do not url encode>]}
 *  <do not url encode> the conds are by default url encoded
 *  (%22My%20category%22) so it can be used as parameter to view. However - we
 *  do not need url encoding for {ids } construct, so for ussage with {ids}
 *  use the last parameter and set it to 1
 */
class AA_Stringexpand_Conds extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     * @return string
     */
    function expand($text='', $no_url_encode=false) {
        if ( !is_object($this->item) OR !$this->item->isField($text) ) {
            return AA_Stringexpand_Conds::_joinArray(json2arr($text), $no_url_encode, $no_url_encode ? 'AAnoCONDITION' : '');
        }

        return AA_Stringexpand_Conds::_joinArray($this->item->getValuesArray($text), $no_url_encode);
    }

    /** Static */
    function _joinArray($values, $no_url_encode, $default='AA_NeVeRtRuE') {
        if (empty($values)) {
            return $default;
        }
        $ret = $delim = '';
        foreach ( $values as $val ) {
            if ( empty($val) ) {
                continue;
            }

            $ret  .= $delim. ($no_url_encode ? ('"'. str_replace(array('-','"'), array('--','\"'), $val) .'"') :
                    ('%22'. str_replace(array('-',' '), array('--','%20'), $val) .'%22'));
            $delim = $no_url_encode ? ' OR ' : '%20OR%20';
        }
        return empty($ret) ? $default : $ret;
    }

}


/**
 * Display field/alias of given item(s)
 * {item:<ids>:<aa_expression>[:<delimiter>[:<nested_top>[:<nested_bottom>]]]}
 *
 * Main ussage is to display some field or alias of given item:
 *   {item:171055:_#HEADLINE}
 *
 * You can also display it for more than one item and use the delimeter
 *   {item:53443-54322-53553:_#HEADLINE:, }
 *
 * You can also use it to display item in tree. In fact, the {item} with
 * trees works exactly the same way, as {itree}, but {itree} has more logical
 * parameter order (due to backward compatibility), so you are encouraged to use
 * {itree} - @see {itree} for more info on tree string representation.
 **/
class AA_Stringexpand_Item extends AA_Stringexpand {

    /** Do not trim all parameters (at least the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $ids_string string - ids (long or short (or mixed) separated by dash '-')
     *                     - now you can also use tree representation like:
     *                     3234(3443-3678(3872-3664)-3223)-4045  (see above)
     * @param $content    string
     * @param $delimeter  string
     * @param $top        string
     * @param $bottom     string
     * @param $bin        string - allowed bins dash separated: ACTIVE-HOLDING-TRASH. Default is ACTIVE (pending and expired is not available, for now)
     * @return string
     */
    function expand($ids_string='', $content=null, $delim=null, $top=null, $bottom=null, $bin=null) {

        if (!($ids_string = trim(strtolower($ids_string)))) {
            return '';
        }

        $zids = new zids(explode_ids(preg_replace('/[^0-9a-f]/', '-', $ids_string)));

        // load all mentioned items in one step
        AA_Items::preload($zids);

        if (aa_strpos($ids_string,'(')===false) {
            if (strlen($bin)) {
                $bins        = explode('-',$bin);
                $numeric_bin = 0 | (in_array('ACTIVE',$bins) ?  AA_BIN_ACTIVE : 0) | (in_array('HOLDING',$bins) ?  AA_BIN_HOLDING : 0) | (in_array('TRASH',$bins) ?  AA_BIN_TRASH : 0);
            } else {
                $numeric_bin = AA_BIN_ACTIVE;
            }
            return AA_Stringexpand_Item::itemJoin(AA_Items::getFormatted($zids, $content, $numeric_bin), $delim);
        }

        // sanity input
        $ids_string = preg_replace('/[^0-9a-g()-]/', '', $ids_string);  // mention the g (used for generated subrtree cache)
        $ids_string = str_replace('()', '', $ids_string);

        $tree_cache = new AA_Treecache($content, $delim, $top, $bottom);

        // we are looking for subtrees 93938(73737-64635)
        while (preg_match('/[0-9a-f]+[(]([^()]+)[)]/s',$ids_string)) {
            $ids_string = preg_replace_callback('/([0-9a-f]+)[(]([^()]+)[)]/s', array($tree_cache,'cache_list'), $ids_string);
        }
        $ret = $tree_cache->get_concat($ids_string);

        return $ret;
    }

    /**
     * @param $arr
     * @param $delim
     * @return string
     */
    static function itemJoin($arr, $delim) {
        $arr = array_filter($arr,'strlen');
        switch ($delim) {
            case 'json':
                return json_encode($arr);
            case 'jsonasoc':
                $ret = array();
                foreach ($arr as $v) {
                    $foo = explode('->',$v);
                    $ret[$foo[0]] = $foo[1];
                }
                return json_encode($ret);
        }
        return join($delim,$arr);
    }
}

/**
 * Display field/alias of given item(s) in tree
 *   {itree:<tree-string>:<nested_top>[:<content>[:<delimiter>[:<nested_bottom>]]]}
 *
 * <tree-string> is generalized version of <ids> for {item} syntax, which is able
 * to hold also tree structure (and is returned by {treestring...} syntax)
 *
 *   {itree:{treestring:{_#ITEM_ID_}}:_#2:_#HEADLINE::}
 *
 * Tree representation string could be as simple as "4232" or "6523-6464-6641",
 * but it could be also more complicated tree - just like:
 *   3234(3443-3678(3872-3664)-3223)-4045
 *
 * The exmples of trees follows.
 *
 * Practical examle of ussage is for example breadcrumb navigation:
 *   {itree:{xseo1}({xseo2}({xseo3}({xseo4}))): <a href="_#ITEM_URL">_#HEADLINE</a> &gt;: _#HEADLINE}
 *
 * or better
 *   {itree:{xid:path}: <a href="_#ITEM_URL">_#HEADLINE</a> &gt;: _#HEADLINE}
 *   {itree:{xid:path}: _#HEADLINK &gt;: _#HEADLINE}
 *
 * However, you will be able to use it for discussions tree as well.
 *
 *
 * 1) Generic tree
 *
 *    -+-- 1 --+-- 2
 *     |       |
 *     |       +-- 3 --+-- 5
 *     |       |       |
 *     |       |       +-- 6
 *     |       +-- 4
 *     +-- 7
 *     |
 *     +-- 8
 *
 *   represented as: 1(2-3(5-6)-4)-7-8
 *
 *   printed as: 1 nested_top
 *               2 content
 *               3 nested_top
 *               5 content
 *               delimeter
 *               6 content
 *               3 nested_bottom
 *               4 content
 *               1 nested_bottom
 *               7 content
 *               delimeter
 *               8 content
 *
 * 2) SEO path (like for breadcrumbs - xseo1 --- xseo2 --- xseo3)
 *
 *    --- 1 --- 2 --- 3
 *
 *   represented as: 1(2(3))
 *
 *   printed as: 1 nested_top
 *               2 nested_top
 *               3 content
 *               2 nested_bottom
 *               1 nested_bottom
 *
 *
 * 3) Normal list of items
 *
 *    -+-- 1
 *     |
 *     +-- 2
 *     |
 *     +-- 3
 *
 *   represented as:  1-2-3
 *
 *   printed as: 1 content
 *               delimeter
 *               2 content
 *               delimeter
 *               3 content
 */
class AA_Stringexpand_Itree extends AA_Stringexpand_Nevercache {
    // cached in AA_Stringexpand_Item

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $ids_string  string - ids (long or short (or mixed) separated by dash '-')
     *                     - now you can also use tree representation like:
     *                     3234(3443-3678(3872-3664)-3223)-4045  (see above)
     * @param $top
     * @param $content
     * @param $delimeter
     * @param $bottom
     * @return string
     */
    function expand($ids_string='', $top=null, $content=null, $delim=null, $bottom=null) {
        $trans   = array('_#1'=>$top, '_#2'=>$content, '_#3'=>$delim, '_#4'=>$bottom);
        $top     = strtr($top,     $trans);
        $content = strtr($content, $trans);
        $delim   = strtr($delim,   $trans);
        $bottom  = strtr($bottom,  $trans);
        return StrExpand('AA_Stringexpand_Item', array($ids_string, $content, $delim, $top, $bottom));
    }
}

/** helper class for AA_Stringexpand_Item */
class AA_Treecache {
    /**
     * @var
     */
    var $content;
    /**
     * @var
     */
    var $delim;
    /**
     * @var
     */
    var $top;
    /**
     * @var
     */
    var $bottom;
    /**
     * @var
     */
    var $_cache;

    /**
     * AA_Treecache constructor.
     * @param $content
     * @param $delim
     * @param $top
     * @param $bottom
     */
    function __construct($content, $delim, $top, $bottom) {
        $this->content = $content;
        $this->delim   = $delim;
        $this->top     = $top;
        $this->bottom  = $bottom;
    }

    /**
     * @param $match
     * @return string
     */
    function cache_list($match) {
        $key = 'g'. hash('md5',$match[0]);

        if (!isset($this->cache[$key])) {
            $subtree  = $this->top     ? $this->_get_item($match[1], $this->top) : '';
            $subtree .= $this->content ? $this->get_concat($match[2]) : '';
            // $subtree .= $this->content ? str_replace('_#aa_parent', $match[1], $this->get_concat($match[2])) : '';
            $subtree .= $this->bottom  ? $this->_get_item($match[1], $this->bottom) : '';

            $this->_cache[$key] = $subtree;
        }

        return $key;
    }

    /**
     * @param $ids_string
     * @return string
     */
    function get_concat($ids_string) {
        if (!$this->content) {
            return '';
        }
        $ids     = explode_ids($ids_string);
        $results = array();
        if ( is_array($ids) ) {
            foreach ( $ids as $item_id ) {
                $results[] = $this->_get_item($item_id, $this->content);
            }
        }
        return AA_Stringexpand_Item::itemJoin($results, $this->delim);
    }

    /**
     * @param $item_id
     * @param $expression
     * @return mixed
     */
    function _get_item($item_id, $expression) {
        // cached subtree
        if ($item_id{0} == 'g') {
            return $this->_cache[$item_id];
        }

        return reset(AA_Items::getFormatted(new zids($item_id), $expression));
    }
}

/** Aggregate information from specified set of items. The function, which could
 *  be used for aggregated values are:
 * @param $function   string - sum | max | min | avg | concat | count | order | filter
 * @param $ids_string string - list of item ids, which we take into account
 * @param $expression string - the value, we are counting with (like _#NUMBER_E)
 * @param $parameter  string - posible additional parameter for the function (like delimiter for the "concat" function)
 *
 * {aggregate:max:{ids:3a0c44958b1c6ad697804cfdbccd8b09}:_#D_APPCNT}
 */
class AA_Stringexpand_Aggregate extends AA_Stringexpand {

    /** Do not trim all parameters (the $parameter could contain space - for concat...) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $function
     * @param $ids_string
     * @param $expression
     * @param $parameter
     * @return float|int|mixed|string
     */
    function expand($function='', $ids_string='', $expression=null, $parameter=null) {
        if ( !in_array($function, array('sum', 'max', 'min', 'avg', 'concat', 'count', 'order', 'filter', 'filter_contain')) ) {
            return '';
        }


        $results = AA_Items::getFormatted(new zids(explode_ids($ids_string)), $expression, AA_BIN_ALL);

        // $ids     = explode_ids($ids_string);
        // $results = array();
        // $count   = 0;
        // if ( is_array($ids) ) {
        //     foreach ( $ids as $item_id ) {
        //         // is it item id?
        //         $id_type = guesstype($item_id);
        //         if ( $item_id AND (($id_type == 's') OR ($id_type == 'l'))) {
        //             $item = AA_Items::getItem(new zids($item_id,$id_type));
        //             if ($item) {
        //                 $count++;
        //                 if ($expression) {
        //                     $results[$item_id] = $item->subst_alias($expression);
        //                 }
        //             }
        //         }
        //     }
        // }
        switch ($function) {
            case 'sum':
                $ret = array_sum(str_replace(',', '.', $results));
                break;
            case 'max':
                $ret = max(str_replace(',', '.', $results));
                break;
            case 'min':
                $ret = min(str_replace(',', '.', $results));
                break;
            case 'avg':
                array_walk($results, function($a) {return (float)str_replace(',', '.', $a);});
                $ret = (count($results) > 0) ? array_sum($results)/count($results) : '';
                break;
            case 'concat':
                $ret = join($parameter,$results);
                break;
            case 'count':
                $ret = count($results);
                break;
            case 'order':
                switch ($parameter) {
                    case 'rnumeric': arsort($results, SORT_NUMERIC);       break;
                    case 'rstring':  arsort($results, SORT_STRING);        break;
                    case 'rlocale':  arsort($results, SORT_LOCALE_STRING); break;
                    case 'string':   asort($results, SORT_STRING);         break;
                    case 'locale':   asort($results, SORT_LOCALE_STRING);  break;
                    default:         asort($results, SORT_NUMERIC);        break;
                }
                $ret = join('-', array_keys($results));
                break;
            case 'filter':
                $arr       = array();
                $parameter = (string)$parameter;
                foreach ($results as $k => $v) {
                    if ((string)$v == $parameter) {
                        $arr[] = $k;
                    }
                }
                $ret = join('-',$arr);
                break;
            case 'filter_contain':
                $arr       = array();
                $parameter = (string)$parameter;
                foreach ($results as $k => $v) {
                    if (aa_strpos((string)$v, $parameter) !== false) {
                        $arr[] = $k;
                    }
                }
                $ret = join('-',$arr);
                break;
        }
        return $ret;
    }
}


/** returns fultext of the item as defined in slice admin
 */
class AA_Stringexpand_Fulltext extends AA_Stringexpand {
    /**
     * @param string $item_ids
     * @return string|void
     */
    function expand($item_ids='') {
        $ret = '';
        $iids = explode_ids($item_ids);
        foreach ($iids as $item_id) {
            $id_type    = guesstype($item_id);
            if ( $item_id AND (($id_type == 's') OR ($id_type == 'l'))) {
                $item = AA_Items::getItem(new zids($item_id,$id_type));
                if ($item) {
                    $slice = AA_Slice::getModule($item->getSliceID());
                    $text  = $slice->getProperty('fulltext_format_top'). $slice->getProperty('fulltext_format'). $slice->getProperty('fulltext_format_bottom');
                    $ret  .= AA::Stringexpander()->unalias($text, $slice->getProperty('fulltext_remove'), $item);
                }
            }
        }
        return $ret;
    }
}


/** returns ids of items based on conds d-...
 *  {ids:<slices>:[<conds>[:<sort>[:<delimiter>[:<restrict_ids>[:<limit>]]]]]}
 *  {ids:6a435236626262738348478463536272:d-category.......1-RLIKE-Bio-switch.........1-=-1:headine........-}
 *  returns dash separated long ids of items in selected slice where category
 *  begins with Bio and switch is 1 ordered by headline - descending
 */
class AA_Stringexpand_Ids extends AA_Stringexpand {

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $slices
     * @param $conds
     * @param $sort
     * @param $delimeter
     * @param $ids
     * @param $limit - could be negative for last $limit ids
     * @return string
     */
    function expand($slices='', $conds=null, $sort=null, $delimiter=null, $ids=null, $limit=null) {
        $restrict_zids = $ids ? new zids(explode_ids($ids),'l') : false;
        $set           = new AA_Set(explode_ids($slices), $conds, $sort);
        if ($limit > 0) {
            $zids = $set->query($restrict_zids, $limit);
        } elseif ($limit < 0) {
            $zids = $set->query($restrict_zids)->slice($limit,-$limit);
        } else {
            $zids = $set->query($restrict_zids);
        }
        return join($zids->longids(), $delimiter ?: '-');
    }
}

/** returns ids of items based on Item Set as defined on Admin page
 *  {set:<set_id>}
 */
class AA_Stringexpand_Set extends AA_Stringexpand {
    /** expand function
     * @param $item_set string
     * @return string
     */
    function expand($set_id='') {
        /** @var AA_Set $set */
        $set = AA_Object::load($set_id, 'AA_Set');
        if (!is_object($set)) {
            return '';
        }
        $zids = $set->query();
        return join($zids->longids(), '-');
    }
}


/** returns ids of items which links the item
 *  {backlinks:<item_id>[:<slice_ids>[:<sort>]]}
 *  {backlinks:{id..............}}
 *    returns all active backlinks to the item in all slices in current site
 *    module sorted by slice and publish_date
 *  {backlinks:{id..............}:6a435236626262738348478463536272:category.......1-,headline........}
 *    returns all active backlinks from specified slice sorted by category and headline
 *  {backlinks:{id..............}::-}
 *    All active backlinks without ordering - the quickest way to get ids
 */
class AA_Stringexpand_Backlinks extends AA_Stringexpand {
    /** expand function
     * @param $item_id - item to find back links
     * @param $slice_ids - slices to look at (dash separated), default are all slices within site modules of item's slice
     * @param $sort - redefine sorting - like: category.......1-,headline........
     *                    - couldbe also
     * @return mixed|null|string|string[]
     */
    function expand($item_id=null, $slice_ids=null, $sort=null) {
        $item = AA_Items::getItem($item_id);
        if ($item) {
            $slice_ids = $slice_ids ?: '{site:{modulefield:{slice_id........}:site_ids}:modules}';
            $sort      = $sort      ? (($sort == '-') ? '': $sort) : 'slice_id........,publish_date....-';
            return AA::Stringexpander()->unalias("{ids:$slice_ids:d-all_fields-=-{id..............}:$sort}", '', $item);
        }

        return '';
    }
}

/** Sorts ids by the expression
 *  {order:<ids>:<expression>[:<sort-type>]}
 *  {order:4785-4478-5789:_#YEAR_____#CATEGORY}
 *  {order:4785-4478-5789:_#HEADLINE:string}
 *  Usualy it is much better to use sorting by database - like you do in {ids},
 *  but sometimes it is necessary to sort concrete ids, so we use this
 *  You can sort numericaly (default), as string or using current locale
 *  in both directions: numeric | rnumeric | string | rstring | locale | rlocale
 */
class AA_Stringexpand_Order extends AA_Stringexpand_Nevercache {
    // cached in AA_Stringexpand_Aggregate

    /** expand function
     * @param $ids - dash separated item ids
     * @param $expression - expression for ordering
     * @param $type - numeric | rnumeric | string | rstring | locale | rlocale
     * @return string
     */
    function expand($ids=null, $expression='publish_date....', $type=null) {
        return StrExpand('AA_Stringexpand_Aggregate', array('order', $ids, $expression, $type));
    }
}

/** Filter ids by the expression
 *  {filter:<ids>:<expression>:<equals-to>}
 *  {filter:4785-4478-5789:_#SLICE_ID:879e87a4546abe23879e87a4546abe23}
 *  {filter:4785-4478-5789:{({item:{relation........}:_#APPROVED})}:1}
 *  Usualy it is much better to use filtering by database - like you do in {ids},
 *  but sometimes it is necessary to filter concrete ids, so we use this
 *  Returns only ids, which <expression> equals to <equals-to>
 */
class AA_Stringexpand_Filter extends AA_Stringexpand_Nevercache {
    // cached in AA_Stringexpand_Aggregate

    /** expand function
     * @param $ids - dash separated item ids
     * @param $expression - expression for filtering
     * @param $equals - value to match expression
     * @param $operator - = | contain
     * @return string
     */
    function expand($ids=null, $expression=null, $equals=null, $operator=null) {
        if ($operator == 'contain' ) {
            return StrExpand('AA_Stringexpand_Aggregate', array('filter_contain', $ids, $expression, $equals));
        }
        return StrExpand('AA_Stringexpand_Aggregate', array('filter', $ids, $expression, $equals));
    }
}

/** returns string usefull for sorting the tree of items.
 *  The string is based on the short_ids by default, which is quickest and works well with discussions, ...
 *  If the branch of current item 35897 is 2458-15878-35897,
 *  then the string will be E2458F15878F35897, which works well for ordering
 *  the tree
 *  {sortstring:<shortitem_id>[:<relation_field>]}
 *  {sortstring:2a4352366262227383484784635362ab:relation.......1}
 *  {sortstring:2a4352366262227383484784635362ab:relation.......1:_#ORDER___}
 *    - use _#ORDER___ alias instead of short ID. _#ORDER___ could be number or any other string
 *  @return string - dash separated long ids of items from root to the item
 */
class AA_Stringexpand_Sortstring extends AA_Stringexpand {
    /** expand function
     * @param      $item_id         string - item id of the tree root (short or long)
     * @param      $relation_field  string - tree relation field (default relation........)
     * @param      $expression      string - expression to use for ordering. The default is short item id
     * @return string
     */
    function expand($item_id='', $relation_field=null, $expression=null) {
        $zids = new zids(array_reverse(AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field), 'l'));
        $orderstrings =  strlen($expression) ? AA_Items::getFormatted($zids, $expression, AA_BIN_ALL) : $zids->shortids();
        return join('', array_map( array('AA_Stringexpand_Sortid','expand') , $orderstrings));
    }
}

/** @return string usefull for sorting numbers in text mode. The string is based
 *  on number nad length, so it creates B1 from 1, C10 from 10, C89 from 89, and
 *  E2458 from 2458
 *  {sortid:<number>}
 */
class AA_Stringexpand_Sortid extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $number - number to be tranformed to string for sorting
     * @return string B1 for $id=1, F25487 for $id=25487, ...
     */
    function expand($number='') {
        return chr(65+strlen((string)$number)).$number;
    }
}


/** @return string representation of the tree (with long ids) under specifield
 *          item based on the relation field
 *  @see {itree: } for more info about the stringtree syntax
 *  {treestring:<item_id>[:<relation_field>[:<reverse>[:<sort_string>[:<slices>]]]]}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1:1}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1:1:sort[0][headline........]=a&sort[1][publish_date....]=d}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1:1:headline........:35615a6d5fdfeb23d36d1c94be3cd9b4}
 *  <nav><ul>{itree:{treestring:{ids:b485d20843c0afe29d3e9ce773195635:d-relation........-ISNULL-1-switch.........1-=-0:number..........}::1:number..........}:<li id="menu-_#SEO_____">_#MENULINK<ul>:<li id="menu-_#SEO_____">_#MENULINK</li>::</ul></li>}</ul></nav>
 */
class AA_Stringexpand_Treestring extends AA_Stringexpand {
    /** expand function
     * @param $item_ids - item id (or ids) of the tree root (short or long)
     * @param $relation_field - tree relation field (default relation........)
     * @param $reverse - 1 for reverse trees (= child->parent relations)
     * @param $sort_string - order of tree leaves (currently works only for reverse trees. @todo)
     * @param $slices - traverse only listed slices (some times usefull if your tree contain more than one slice and you want to count only with a subtree)
     * @return string
     */
    function expand($item_ids='', $relation_field=null, $reverse=null, $sort_string=null, $slices=null) {
        if (strpos( $item_ids, '-') === false ) {
            // just for speedup
            return AA_Stringexpand_Treestring::treefunc('getTreeString', $item_ids, $relation_field, $reverse, $sort_string, $slices);
        }
        $ret = array();
        $ids = explode_ids($item_ids);
        foreach ($ids as $id) {
            $ret[] = AA_Stringexpand_Treestring::treefunc('getTreeString', $id, $relation_field, $reverse, $sort_string, $slices);
        }
        return join('-', array_filter($ret,'strlen'));
    }

    /**
     * @param        $func
     * @param        $item_id
     * @param        $relation_field
     * @param string $reverse
     * @param string $sort_string
     * @param string $slices
     * @return string|array
     */
    static function treefunc($func, $item_id, $relation_field, $reverse='', $sort_string='', $slices='') {
        $zid     = new zids($item_id);
        $long_id = $zid->longids(0);
        if (empty($item_id)) {
            return '';
        }
        if (empty($sort_string) OR !is_array($sort = String2Sort($sort_string))) {
            $sort = null;
        }
        $s_arr = explode_ids($slices);

        return AA_Trees::$func($long_id, get_if($relation_field, 'relation........'), $reverse=='1', $sort, $s_arr);
    }
}

/** returns long ids of subitems items based on the relations between items
 *  {tree:<item_id>[:<relation_field>]}
 *  {tree:2a4352366262227383484784635362ab:relation.......1}
 *  @return string - dash separated long ids of items which belongs to the tree under
 *          specifield item based on the relation field
 */
class AA_Stringexpand_Tree extends AA_Stringexpand {
    /** expand function
     * @param $item_id - item id of the tree root (short or long)
     * @param $relation_field - tree relation field (default relation........)
     * @return string
     */
    function expand($item_id='', $relation_field=null, $reverse=null, $sort_string=null, $slices=null) {
        return join('-', AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field, $reverse, $sort_string, $slices));
    }
}

/** returns long ids of subitems items based on the relations between items
 *  {path:<item_id>[:<relation_field>]}
 *  {path:2a4352366262227383484784635362ab:relation.......1}
 *  @return string - dash separated long ids of items from root to the item
 */
class AA_Stringexpand_Path extends AA_Stringexpand {
    /** expand function
     * @param $item_id - item id of the tree root (short or long)
     * @param $relation_field - tree relation field (default relation........)
     * @return string
     */
    function expand($item_id='', $relation_field=null) {
        return join('-', array_reverse(AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field)));
    }
}

/** @return string - prints HTML menu
 *  - designed for SEO sitemodule with "Pages" slice with the relation field "Subpage of..."
 *  - the menu items with empty text is not printed (which you can use for not displaying some items)
 *
 *      {menu:<first-level-item-ids>:<menu-text>:[<relation-field>[:<sort-string>]]}
 *  submenu for current item:
 *      {menu:{id..............}:_#MENULINK}
 *  whole real menu for the Pages slice:
 *      {menu:{ids:18a352366ea922738348478463536ea5:d-relation........-ISNULL-1:number..........}:_#MENULINK:relation........:number..........}
 *
 *  The menu then looks like:   <ul>
 *                                <li> one
 *                                  <ul>
 *                                    <li> one.1 </li>
 *                                    <li> one.2 </li>
 *                                  </ul>
 *                                </li>
 *                                <li> two </li>
 *                              </ul>
 *
 *   Each li contains id="menu-<item_id>" and also class, which indicates,
 *   if the menu option is "active" or "inpath" to current "active" item
 */
class AA_Stringexpand_Menu extends AA_Stringexpand {
    /** expand function
     * @param $item_ids - item ids of the menu options on the first level
     * @param $code - alias or aa expression which will be printed inside <li></li>
     *                         - should be link to the item - _HEADLINK (for example)
     *                         - if the resulting code is empty, the menu option is not displayed
     *                           (not its submenu), which you can use for not displaying some items
     * @param $relation_field - tree relation field (default relation........)
     * @param $sort_string - order of tree leaves
     * @return string
     */
    function expand($item_ids=null, $code=null, $relation_field=null, $sort_string=null) {
        if (empty($code)) {
            return '';
        }
        $zids     = new zids(explode_ids($item_ids));
        $long_ids = $zids->longids();
        if (empty($long_ids)) {
            return '';
        }
        if (empty($sort_string) OR !is_array($sort = String2Sort($sort_string))) {
            $sort = null;
        }
        $current_ids = explode_ids(AA::Stringexpander()->unalias('{xid:list}'));

        $supertree = AA_Trees::getSupertree(get_if($relation_field, 'relation........'), 1, $sort);
        return $supertree->getMenu($long_ids, $current_ids, $code);
    }
}


/** Translates seo in current site module to item and prints fulltext of the item, or specified text for the item
 *  {siteitem:<seo-string>[:<text>]}
 *  {siteitem:about-us}   - prints fulltext
 *    but {siteitem:about-us:}  - prints empty string
 *  {siteitem:about-us:<h1>_#HEADLINE</h1>}  - headline of about-us item in current site module
 *  - searches only current (approved) items of current site
 *  - only the first item with the same seo is evaluated
 */
class AA_Stringexpand_Siteitem extends AA_Stringexpand
{
    /** expand function
     * @param $seo_string
     * @param $text
     * @return string
     */
    function expand($seo_string = '', $text = 'AA_FullText') {
        if (!strlen($seo_string)) {
            return '';
        }
        // get just first item id
        if (!strlen($item_id = substr(StrExpand('AA_Stringexpand_Seo2ids', array('',$seo_string,'1')),0,32))) {
            return '';
        }
        if ($text === 'AA_FullText') {
            return StrExpand('AA_Stringexpand_Fulltext', array($item_id));
        }
        return StrExpand('AA_Stringexpand_Item', array($item_id,$text));
    }
}



/** returns ids of items based on conds d-...
 *  {seo2ids:<slices>:<seo-string>}
 *  {seo2ids:6a435236626262738348478463536272:about-us}
 *  returns long id of item in selected slice (or dash separated slices) with
 *  the specified SEO string in seo............. field. If there are more such
 *  ids (which should not be), they are dash separated
 */
class AA_Stringexpand_Seo2ids extends AA_Stringexpand {
    /** expand function
     * @param string $slices
     * @param string $seo_string
     * @param string $bins
     * @return string
     */
    function expand($slices='', $seo_string='', $bins='') {
        if ($seo_string=='') {
            return '';
        }

        // this way we solve the problem mainly in sitemodules, with slices which do not have seo............. field
        static $_seoslices = array();  // stores only slices with 'seo' field

        $dirty_slices = array();
        if ( !is_array($sarr = $_seoslices[$hash = hash('md5', 'h'.$slices)])) {
            if (strpos($slices, '-') !== false) {
                $dirty_slices = explode_ids($slices);
            } elseif (is_long_id($slices)) {
                $dirty_slices = array($slices);
            } elseif (!strlen($slices) AND AA::$site_id) {
                $dirty_slices = AA_Module_Site::getModule(AA::$site_id)->getRelatedSlices();
            }

            $sarr = $dirty_slices ? self::_getSeoSlices($dirty_slices) : array();
            $_seoslices[$hash] = $sarr;
        }

        if (empty($sarr)) {
            return '';
        }

        $bins = $bins ? $bins : AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING;

        $set  = new AA_Set($sarr, new AA_Condition('seo.............', '=', '"'.$seo_string.'"'), null, $bins);
        $zids = $set->query();
        return join($zids->longids(), '-');
        // added expiry date in order we can get ids also for expired items
        // return StrExpand('AA_Stringexpand_Ids', array($slices, 'd-expiry_date.....->-0-seo.............-=-"'. str_replace('-', '--', $seo_string) .'"'));
    }

    /**
     * @param $slices
     * @return array
     */
    static function _getSeoSlices($slices) {
        return array_map('unpack_id', DB_AA::select('slice_id', 'SELECT slice_id FROM field', array(array('slice_id', $slices, 'l'), array('id', 'seo.............'))));
    }
}

/** returns seo name created from the string
 *  {seoname:<string>[:<unique_slices>[:<encoding>]]}
 *  {seoname:About Us:3aa35236626262738348478463536224:windows-1250}
 *  {seoname:{_#HEADLINE}:all}
 *  returns about-us
 *  If you specify the unique_slices parameter, then the id is created as unique
 *  for those slices. Slices are separated by dash
 *  Encoding parameter helps convert the name to acsii. You shoud write here
 *  the character encoding from the slice setting. The default is utf-8, but you
 *  can use any (windows-1250, iso-8859-2, iso-8859-1, ...)
 */
class AA_Stringexpand_Seoname extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $string
     * @param $unique_slices
     * @param $encoding
     * @return string
     */
    function expand($string='', $unique_slices='', $encoding='') {
        $base = ConvertCharset::singleton()->escape($string, empty($encoding) ? 'utf-8' : $encoding, true);
        // we do not want to have looooong urls
        if (aa_strlen($base) > 124) {
            $base = aa_substr($base, 0, 124);
            if (aa_strrpos($base, '-') > 80) {
                // do not split in middle of the word
                $base = aa_substr($base, 0, aa_strrpos($base, '-'));
            }
        }

        $add = '';

        if (($unique_slices == 'all') AND is_object($this->item)) {
            $unique_slices = AA::Stringexpander()->unalias("{site:{modulefield:{slice_id........}:site_ids}:modules}", '', $this->item);
        }
        if ( !empty($unique_slices) ) {
            // we do not want to create infinitive loop for wrong parameters
            for ($i=2; $i < 100000; $i++) {
                // following command if not cached - the recounting seo in loop of Modify Content did not work with cache, of course
                $ids = StrExpand('AA_Stringexpand_Seo2ids', array($unique_slices, $base.$add, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING), array(), false, true);
                if (empty($ids)) {
                    // we found unique seo-name
                    break;
                }
                $add = '-'.$i;
            }
        }
        return $base.$add;
    }
}



/** returns string unique for the slice(s) within the field. Numbers are added
 *  if the conflict is found
 */
class AA_Stringexpand_Finduniq extends AA_Stringexpand {
    /** expand function
     * @param $string
     * @param $field_id
     * @param $unique_slices
     * @param $ignore_item_id
     * @return mixed|string
     */
    function expand($string='', $field_id='', $unique_slices='', $ignore_item_id='') {
        if (!trim($string)) {
            return new_id();   // just random text
        }
        $slices = explode_ids($unique_slices);
        $add = '';
        if ( !empty($slices) ) {
            for ($i=2; $i < 100000; $i++) {
                $set  = new AA_Set($slices, new AA_Condition($field_id, '=', '"'.$string.$add.'"'), null, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING);
                $zids = $set->query();
                if (!$zids->count() OR in_array($ignore_item_id, $zids->longids())) {
                    break;   // we found unique seo-name
                }
                $add = $i;
            }

        }
        return $string.$add;
    }
}

/** @returns string - name (or other field) of the constant in $gropup_id with $value
 *  Example: {constant:AA Core Bins:1:name}
 *           {constant:biom__categories:{@category........:|}:name:|:, }  // for multiple constants
 *           {constant:ekolist-category:{@category.......1:|}:<a href="http#://ekolist.cz/zpravodajstvi/zpravy?kategorie=_#VALUE##_">_#NAME###_</a>:|:, }  // you can use also constant aliases and expressions
 *           {constant:molcz-rubriky:{constants:molcz-rubriky::-}:{(<label><input type='checkbox' name='type[]' value='_#VALUE##_' {ifin:{qs:type:-}:{_#VALUE##_}: checked}>_#NAME###_</label>)}:-: }
 */
class AA_Stringexpand_Constant extends AA_Stringexpand {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $group_id - constants ID
     * @param $value - constant value (or values delimited by $value_delimiter)
     * @param $what - name|value|short_id|description|pri|group|class|id|level
     *                            or any AA expression using constant aliases
     *                            _#NAME###_, _#VALUE##_, _#PRIORITY, _#GROUP##_, _#CLASS##_,
     *                            _#COUNTER_, _#CONST_ID, _#SHORT_ID, _#DESCRIPT, _#LEVEL##_
     * @param $value_delimiter - value delimiter - used just form translating multiple constants at once
     * @param $output_delimiter - resulting output delimiter - ', ' is default for multiple constants
     * @return bool|mixed|null|string|string[]
     */
    function expand($group_id='', $value='', $what='name', $value_delimiter='', $output_delimiter=', ') {
        if (!$value_delimiter) {
            return getConstantValue($group_id, $what, $value);
        }
        $arr = explode($value_delimiter, $value);
        $ret = array();
        foreach ($arr as $constant) {
            $val = getConstantValue($group_id, $what, $constant);
            if ($val) {
                $ret[] = $val;
            }
        }
        return join($output_delimiter, $ret);
    }
}

/** {constants:<group_id>:<format>:<delimiter>}
 *  {constants:ekolist-category}
 *  @return string - prints all constants delimited by <delimiter>. Only _#NAME###_ and _#VALUE##_ aliases could be used
 */
class AA_Stringexpand_Constants extends AA_Stringexpand {
    /** Do not trim all parameters (the $selected parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $group - group id or JSON list of values
     * @return string
     */
    function expand($group='', $format='', $delimiter='') {
        $ret      = array();
        $constants = GetConstants(trim($group));
        if (is_array($constants)) {
            switch (trim($format)) {
                case 'name':  $format = '_#NAME###_'; break;
                case '':
                case 'value': $format = '_#VALUE##_';
            }
            foreach ($constants as $k => $v) {
                if (strlen($res = str_replace(array('_#VALUE##_','_#NAME###_'), array($k, $v), $format))) {
                    $ret[] = $res;
                }
            }
        }
        return join($delimiter, $ret);
    }
}

/** {options:<group_id>:<selected>}
 *  {options:[1,2,5,7]:7}
 *  {options:[[1,"January"],[2,"Feb"],[3,"March"]]:7}
 *  {options:{sequence:num:1998:2012}:{date:Y}}
 *  @return string - html <option>s for given constant group with selected option
 */
class AA_Stringexpand_Options extends AA_Stringexpand {
    /** Do not trim all parameters (the $selected parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $group - group id or JSON list of values
     * @return string
     */
    function expand($group='', $selected='') {
        $ret      = '';
        $selected = (string)$selected;
        if ($group[0] == '[') {
            $constants = json2arr($group, true);
            foreach ($constants as $v) {
                if (is_array($v)) {
                    $k     = $v[0];
                    $khtml = "value=\"$k\"";
                    $v     = $v[1];
                } else {
                    $k     = $v;
                }

                $sel  = ((string)$k == $selected) ? ' selected' : '';
                $ret .= "\n  <option $khtml $sel>".safe($v)."</option>";
            }
        } else {
            $constants = GetConstants(trim($group));
            if (is_array($constants)) {
                foreach ($constants as $k => $v) {
                    $sel  = ((string)$k == $selected) ? ' selected' : '';
                    $ret .= "\n  <option value=\"".safe($k)."\"$sel>".safe($v)."</option>";
                }
            }
        }
        return $ret;
    }
}

/** Sequence - returns sequence of values in JSON Array (could be used with {options}, for example)
 *    {sequence:num:min:max:step:delimiter}
 *    {options:{sequence:num:1998:2012}:{date:Y}}
 *    {options:{sequence:num:2012:1998}:{date:Y}}   // could be descending
 *    {options:{sequence:string:A:H}:{date:Y}}
 */
class AA_Stringexpand_Sequence extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $group_id
     * @return string
     */
    function expand($type='', $min='', $max='', $step='', $delimiter='') {
        $arr = array();
        switch ($type) {
            case 'num':
                if (ctype_digit((string)$min) AND ctype_digit((string)$max)) {
                    $arr = strlen($step) ? range((int)$min, (int)$max, (int)$step) : range((int)$min, (int)$max);
                }
                break;
            case 'string':
                if (strlen($min) AND strlen($max)) {
                    $arr = range($min, $max);
                }
                break;
        }
        return empty($arr) ? '' : (strlen($delimiter) ? join($delimiter, $arr) : json_encode($arr));
    }
}

/** If $condition is filled by some text, then print $text. $text could contain
 *  _#1 alias for the condition, but you can use any {} AA expression.
 *  Example: {ifset:{img_height.....2}: height="_#1"}
 *  The $condition with undefined alias is considered as empty as well
 *    ($condition=_#.{8} (exactly) - like '_#HEADLINE')
 */
class AA_Stringexpand_Ifset extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $condition
     * @param $text
     * @param $else_text
     * @return mixed|string
     */
    function expand($condition='', $text='', $else_text='') {
        $trim_cond = trim($condition);
        return ((strlen($trim_cond)<1) OR IsAlias($trim_cond)) ? $else_text : str_replace('_#1', $condition, $text);
    }
}

/** If $etalon is equal to $option1, then print $text1, else print $else_text.
 *  $(else_)text could contain _#1 and _#2 aliases for $etalon and $option1, but you
 *  can use any {} AA expression.
 *  Example: {ifeq:{xseo1}:about: class="active"}
 *  Now you can use as many $options as you want
 *  Example: {ifeq:{xlang}:en:English:cz:Czech:Unknown language}
 */
class AA_Stringexpand_Ifeq extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (the $text parameter at least could contain space) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $etalon
     * @param $option1
     * @param $text1
     * (...)
     * @param $else_text
     * @return mixed
     */
    function expand() {
        $arg_list = func_get_args();   // must be asssigned to the variable
        $etalon   = trim(array_shift($arg_list));
        $ret      = false;
        $i        = 0;

        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if ($etalon == trim($arg_list[$i])) {
                $ret = $arg_list[$i+1];
                break;
            }
            $i += 2;
        }
        if ($ret === false) {
            // else text
            $ret = isset($arg_list[$i]) ? $arg_list[$i] : '';
        }
        // _#2 is not very usefull but we have it from the times the function was just for one option
        return str_replace(array('_#1','_#2'), array($etalon, $arg_list[0]), $ret);
    }
}

/** Numeric comparison with the operator specified by parameter You can as
 *  in {ifeq} use multiple conditions - the first matching is returned, then
 *  Example: {if:{_#IMGCOUNT}:>:10:big:6:medium:small}
 *  Comparison is allways numeric (also for security reasons)
 */
class AA_Stringexpand_If extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $etalon
     * @param $operator
     * @param $option1
     * @param $text1
     * (...)
     * @param $else_text
     * @return mixed
     */
    function expand() {
        $arg_list = func_get_args();   // must be asssigned to the variable
        $etalon   = (float)str_replace(',', '.', trim(array_shift($arg_list)));
        switch (array_shift($arg_list)) {
            case '=' :
            case '==':
            case 'eq':    $cmp = function( $a,  $b) { return $a == $b; };  break;
            // typehints doesn't work for float in php 5.6
            // $cmp = function(float $a, float $b) { return $a == $b; };  break;

            case '>':
            case 'gt':
            case '&gt;':  $cmp = function( $a,  $b) { return $a >  $b; };  break;

            case '<' :
            case 'lt':
            case '&lt;':  $cmp = function( $a,  $b) { return $a < $b; };   break;

            case '>=':
            case '&gt;=':
            case 'ge':    $cmp = function( $a,  $b) { return $a >= $b; };  break;

            case '<=':
            case '&lt;=':
            case 'le':    $cmp = function( $a,  $b) { return $a <=  $b; }; break;

            case '<>':
            case '&lt;&gt;':
            case '!=':    $cmp = function( $a,  $b) { return $a <> $b; }; break;

            default:      $cmp = function( $a,  $b) { return false; };
        }
        $ret      = false;
        $i        = 0;
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if ($cmp($etalon, (float)str_replace(',', '.', trim($arg_list[$i])))) {
                $ret = $arg_list[$i+1];
                break;
            }
            $i += 2;
        }
        if ($ret === false) {
            // else text
            $ret = isset($arg_list[$i]) ? $arg_list[$i] : '';
        }
        // _#2 is not very usefull but we have it from the times the function was just for one option
        return str_replace(array('_#1','_#2'), array(PhpFloat($etalon), $arg_list[0]), $ret);
    }

}


/** If any value of the (multivalue) $field is equal to $var, then print $text,
 *  else print $else_text.
 *  $(else_)text could contain _#1 aliases for $var, but you can use any {} AA
 *  expression.
 *  Ussage:  {ifeqfield:<item_id>:<field>:<var>:<text>:<else-text>}
 *  Example: {ifeqfield:{xid}:category.......1:Nature: class="green"}
 *  Example: {ifeqfield::category.......1:Nature: class="green"} // for current item
 */
class AA_Stringexpand_Ifeqfield extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $item_id
     * @param $field
     * @param $var
     * @param $text
     * @param $else_text
     * @return mixed
     */
    function expand($item_id='', $field='', $var='', $text='', $else_text='') {
        $ret = $else_text;
        $item = (!$item_id OR ($item_id=='current')) ? $this->item : AA_Items::getItem(new zids($item_id));
        if (!empty($item) AND $item->isField($field)) {
            $ret = in_array($var, $item->getValuesArray($field)) ? $text : $else_text;
        }
        return str_replace(array('_#1'), array($var), $ret);
    }
}

/** If $haystack contain $needle text, then print $text, else print $else_text.
 *  $(else_)text could contain _#1 for $haystack and _#2 for matched $needle
 *  Ussage:  {ifin:ActionApps CMS:CMS:yes:no}
 *  Now you can use as many $needles as you want - only the first matched wins
 *  Example: {Ifin:de,ru,cz,pl,en:en:English:cz:Czech:Unknown language}
 */
class AA_Stringexpand_Ifin extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $haystack
     * @param $needle
     * @param $text
     * @param $else_text
     * @return mixed
     */
    function expand() {
        return AA_Stringexpand_Ifin::_resolveCompare(func_get_args(), function($a,$b) {return !strlen($b) OR strpos($a, $b) !== false;} );
    }

    /**
     * @param $arg_list
     * @param $cmp_function
     * @return mixed
     */
    public function _resolveCompare($arg_list, $cmp_function) {
        $haystack = array_shift($arg_list);
        $ret      = false;
        $i        = 0;
        $matched  = '';
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if ($cmp_function($haystack,$arg_list[$i])) {
                $ret     = $arg_list[$i+1];
                $matched = $arg_list[$i];
                break;
            }
            $i += 2;
        }
        if ($ret === false) {
            // else text
            $ret     = isset($arg_list[$i]) ? $arg_list[$i] : '';
            $matched = $ret;
        }
        return str_replace(array('_#1','_#2'), array($haystack, $matched), $ret);
    }
}

/** Equivalent to {ifset:{intersect:SetMember1-SetMember2-SetMember3:SetMember2-SetMember4}:..}
 *  Example: {ifintersect:{_#P_RIGHTS}:{_#MY_ROLES}:Access granted:Access denied}
 */
class AA_Stringexpand_Ifintersect extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $haystack
     * @param $needle
     * @param $text
     * @param $else_text
     * @return mixed
     */
    function expand() {
        return AA_Stringexpand_Ifin::_resolveCompare(func_get_args(), function($a,$b) {return count(array_filter(array_intersect(array_map('trim', explode_ids($a)),array_map('trim',explode_ids($b))),'strlen'))>0;} );
    }
}

/** Tests type of the string
 *  Example: {iftype:{qs:id}:longid:_#1:shortid:warning - use long id:parameter is not id}
 */
class AA_Stringexpand_Iftype extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $haystack
     * @param $test
     * @param $text
     * ...
     * @param $else_text
     * @return mixed
     */
    function expand() {
        return AA_Stringexpand_Ifin::_resolveCompare(func_get_args(), array('AA_Stringexpand_Iftype','typeCompare'));
    }

    /** Compare function
     * @param $test_string string - test string
     * @param $test string - test type - one of longid|shortid|...   (we have just two, at this moment, but there will be more...)
     * @return bool     - if the string is of type $b
     */
    public function typeCompare($test_string, $test) {
        switch ($test) {
            case 'longid' : return is_long_id($test_string);
            case 'shortid': return is_short_id($test_string) AND ($test_string > 0);  // maybe we should update the is_short_id test
        }
        return false;
    }
}


/** Takes unlimited number of parameters and jioins the unempty ones into one
 *  string ussing first parameter as delimiter
 *  Example: {join:, :{_#YEAR____}:{_#SIZE____}:{_#SOURCE___}}
 */
class AA_Stringexpand_Join extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($delimiter could be space) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $delimiter
     * @param $strings ...
     * @return string
     */
    function expand() {
        $arg_list  = func_get_args();   // must be asssigned to the variable
        $delimiter = array_shift($arg_list);
        $ret       = array_filter($arg_list, function($str) {return strlen(trim($str))>0;});
        return ($delimiter=='json') ? json_encode($ret) : join($delimiter, $ret);
    }
}

/** Expand URL by adding session
 *  Example: {sessurl:<url>}
 *  Example: {sessurl}         - returns session_id
 *  Example: {sessurl:hidden}  - special case for <input hidden...
 *  Example: {sessurl:param}   - special case for AA_Session=6252412...
 */
class AA_Stringexpand_Sessurl extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $url
     * @return mixed|string
     */
    function expand($url='') {
        global $sess;

        if (!isset($sess)) {
            return '';
        }
        switch($url) {
            case '':       return $sess->id();
            case 'hidden': return "<input type=\"hidden\" name=\"".$sess->name()."\" value=\"".$sess->id()."\">";
            case 'param':  return $sess->name().'='.$sess->id();
        }
        return StateUrl($url);
    }
}

/** Compares two values -
 *  returns:  'L' if val1 is less than val2
 *            'G' if val1 is greater than val2
 *            'E' if they are equal
 *  ussage:  {ifeq:{compare:{publish_date....}:{now}}:G:greater:L:less:E:equal}
 */
class AA_Stringexpand_Compare extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $val1
     * @param $val2
     * @return string
     */
    function expand($val1='', $val2='') {
        return ( $val1 == $val2 ) ? 'E' : (($val1 > $val2) ? 'G' : 'L' );
    }
}

/** Fieldid -
 *  ussage:  {fieldid:text:1}    - returns text...........1
 *           {fieldid:text:1:_}  - returns text___________1
 */
class AA_Stringexpand_Fieldid extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $type
     * @param $num
     * @param $id_type
     * @return string
     */
    function expand($type='', $num=0, $id_type=null) {
        return AA_Fields::createFieldId($type, $num, strlen($id_type) ? $id_type : '.');
    }
}

/** Get field property (currently only 'name' and 'help' is supported
 *  {field:<field_id>:<property>:<slice_id>}
 *  {field:headline........:name:ebfbc0082a26365ef6cefd7c4a4ec253}
 *    - displayes the Name of the headline field in specified slice as defined by administrator of the slice ion the Fieds Admin page
 *  Allowed properties are name, help and alias1
 */
class AA_Stringexpand_Field extends AA_Stringexpand {

    /**
     * @var array
     */
    private static $ALLOWED_PROPERTIES = Array ('name'=>'name','help'=>'input_help', 'alias1'=>'alias1', 'alias2'=>'alias2', 'alias3'=>'alias3', 'widget_new'=>'widget_new' );

    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getSliceID();
    }

    /** expand function
     * @param $field_id
     * @param $property
     * @param $slice_id
     * @return string
     */
    function expand($field_id='', $property='name', $slice_id=null) {
        // we do not want to allow users to get all field setting
        // that's why we restict it to the properties, which makes sense
        // @todo - make it less restrictive
        $property = self::$ALLOWED_PROPERTIES[$property];
        if ($property == 'widget_new') {
            return StrExpand('AA_Stringexpand_Input', array($slice_id, $field_id));
        }

        $field = $this->_getField($slice_id, $field_id);
        if (!$field) {
            return '';
        }

        return (string) $field->getProperty($property ? $property : 'name');
    }

    /**
     * @param $slice_id
     * @param $field_id
     * @return AA_Field string
     */
    function _getField($slice_id, $field_id) {
        if (empty($slice_id)) {
            if ( empty($this->item) ) {
                return '';
            }
            $slice_id = $this->item->getSliceID();
        }
        return ($slice = AA_Slice::getModule($slice_id)) ? $slice->getField($field_id) : '';
    }
}

/** {fieldoptions:<slice_id>:<field_id>:<values>}
 *  displys html <options> as defined for the field. You can specify current
 *  values - as single value, or as multivalue in JSON format.
 */
class AA_Stringexpand_Fieldoptions extends AA_Stringexpand_Field {
    /** Do not trim all parameters ($values could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $slice_id
     * @param $field_id
     * @param $values (single string or array in JSON)
     * @return string
     */
    function expand($slice_id='', $field_id='', $values=null) {
        $field = $this->_getField($slice_id, $field_id);
        if (!$field) {
            return '';
        }

        $widget  = $field->getWidget();
        return $widget ?  $widget->getSelectOptions($widget->getOptions(AA_Value::factoryFromJson($values))) : '';
    }
}

/** Prints the widget for the field in the new item
 *    {input:36fd8c4501d7a8b9e9505dc323d24321:headline........}
 *    {input:36fd8c4501d7a8b9e9505dc323d24321:text..........23:::::2}
 *    {input:36fd8c4501d7a8b9e9505dc323d24321:headline........:::{"name":"Project name","input_help":"fill in the project name","row_count":"10"}}
 *    {input:36fd8c4501d7a8b9e9505dc323d24321:category........::sel:{"const_arr":{"0":"yes","1":"no"}}}          (1)
 *    {input:36fd8c4501d7a8b9e9505dc323d24321:category........::sel:{"const_arr":{jsonasoc:0:yes:1:no}}}         (2)
 */
class AA_Stringexpand_Input extends AA_Stringexpand_Field {
    /** expand function
     * @param $slice_id
     * @param $field_id
     * @param $required
     * @param $widget_type
     * @param $widget_properties - {"name":"My Category","input_help":"check the categories, please","columns":"1"}
     * @param $preset_value - not yet implemented
     * @param $item_index - used to identify number of the item - aa[n1_...], aa[n2_...]
     * @return string
     */
    function expand($slice_id='', $field_id='', $required=null, $widget_type=null, $widget_properties=null, $preset_value=null, $item_index=null) {
        if ( !($field = $this->_getField($slice_id, $field_id))) {
            if ( AA_Slice::getModuleProperty($slice_id,'autofields') AND ($field = $this->_getField($slice_id, 'text............'))) {
                $field = $field->cloneWithId($field_id);
            } else {
                return '';
            }
        }
        // const_arr in widget_properties could be specified as (1) JSON object, or as (2) string with JSON object - see examples(1) and (2) above
        $widget_prop = AA_Stringexpand_Edit::WidgetPropsString2Arr($widget_properties);

        return $field->getWidgetNewHtml($required==1, null, $widget_type, $widget_prop, $preset_value, $item_index);
    }
}

/** Allows on-line editing of field content
 *    {edit:{_#ITEM_ID_}:headline........}
 *    {edit:{_#ITEM_ID_}:text..........23:::::2}
 *    {edit:{_#ITEM_ID_}:headline........::::{"name":"Project name","input_help":"fill in the project name","row_count":"10"}}
 *    {edit:{_#ITEM_ID_}:category........:::sel:{"const_arr":{"0":"yes","1":"no"}}}          (1)
 *    {edit:{_#ITEM_ID_}:category........:::sel:{"const_arr":{jsonasoc:0:yes:1:no}}}         (2)
 */
class AA_Stringexpand_Edit extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it shoud always look in the database

    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getID();
    }

    /**
     * @param string $item_id
     * @param string $field_id
     * @param null $required
     * @param null $function
     * @param null $widget_type
     * @param $widget_properties
     * @return string|void
     */
    function expand($item_id='', $field_id='', $required=null, $function=null, $widget_type=null, $widget_properties=null) {
        return $this->_getWidget('getEditHtml',$item_id, $field_id, $required, $function, $widget_type, $widget_properties);
    }


    /** expand function
     * @param $item_id
     * @param $field_id
     * @param $required
     * @param $function
     * @param $widget_type
     * @param $widget_properties
     * @return string
     */
    protected function _getWidget($type, $item_id, $field_id, $required, $function, $widget_type, $widget_properties) {
        $ret = '';
        if ( $field_id) {
            $item = $item_id ? AA_Items::getItem(new zids($item_id)) : $this->item;
            if (!empty($item) AND !$item->is_empty() AND ($slice = AA_Slice::getModule($item->getSliceId()))) {

                // Use right language (from slice settings) - languages are used for button texts, ...
                mgettext_bind($slice->getLang(), 'output');

                if ( !($field = $slice->getField($field_id)) ) {
                    return '';
                }
                $widget_prop = AA_Stringexpand_Edit::WidgetPropsString2Arr($widget_properties);
                $widget      = $field->getWidget($widget_type, $widget_prop);
                $aa_property = $field->getAaProperty($widget->multiple(), ($required==1) ? true : null, $widget_prop['name'], $widget_prop['input_help']);
                $ret         = $widget->$type($aa_property, $item, $function);
                // $widget->registerRequires(); // now in _finalizeHtml
                AA::Stringexpander()->addRequire('aa-jslib');  // for {live...} - maybe we should distinguish
            }
        }
        return $ret;
    }

    static function WidgetPropsString2Arr($widget_properties) {
        // const_arr in widget_properties could be specified as (1) JSON object, or as (2) string with JSON object - see examples(1) and (2) above
        $widget_prop = json2asoc($widget_properties);
        if (isset($widget_prop['const_arr']) AND !is_array($widget_prop['const_arr']) AND (aa_substr($widget_prop['const_arr'],0,2) == '{"')) {
            $widget_prop['const_arr'] = json2asoc($widget_prop['const_arr']);
        }
        return $widget_prop;
    }
}

/** The same as {edit:...} but also with labels */
class AA_Stringexpand_Editfull extends AA_Stringexpand_Edit {
    /**
     * @param string $item_id
     * @param string $field_id
     * @param null $required
     * @param null $function
     * @param null $widget_type
     * @return string|void
     */
    function expand($item_id='', $field_id='', $required=null, $function=null, $widget_type=null, $widget_properties=null) {
        return $this->_getWidget('getEditfullHtml',$item_id, $field_id, $required, $function, $widget_type, $widget_properties);
    }
}

/** Allows on-line editing of field content
 *    {live:<item_id>:<field_id>:<required>:<function>:<widget_type>}
 *
 *   <required>    explicitly mark the live field as required (0|1)
 *   <function>    specify javascript function, which is executed after the widget
 *                  is sumbitted
 *   <widget_type> which widget to show
 *  {live::number..........:::sel:{"const_arr":{"0":"no","1":"yes"}}}
 */
class AA_Stringexpand_Live extends AA_Stringexpand_Edit {

    /**
     * @param string $item_id
     * @param string $field_id
     * @param null $required
     * @param null $function
     * @param null $widget_type
     * @return string|void
     */
    function expand($item_id='', $field_id='', $required=null, $function=null, $widget_type=null, $widget_properties=null) {
        return $this->_getWidget('getLiveHtml',$item_id, $field_id, $required, $function, $widget_type, $widget_properties);
    }
}


/** Allows on-line editing of field content
 *  {ajax:<item_id>:<field_id>[:<alias_or_any_code>[:<onsuccess>]]}
 *  {ajax:{_#ITEM_ID_}:category........}
 *  {ajax:{_#ITEM_ID_}:switch.........1:_#IS_CHECK}
 *  {ajax:{_#ITEM_ID_}:file............:<img src="/img/edit.gif" title="Upload new file"> :AA_Refresh('stickerdiv1')}
 *  {ajax:{_#ITEM_ID_}:file............:<img src="/img/edit.gif" title="Upload new file"> :AA_Refresh(this)}   // updates the first element with data-aa-url in DOM up
 *  {ajax::number..........::_#ANSWER__:sel:{"const_arr":{"0":"no","1":"yes"}}}
 **/
class AA_Stringexpand_Ajax extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it shoud always look in the database

    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getID();
    }

    /** expand function
     * @param $item_id
     * @param $field_id
     * @param $show_alias
     * @param $onsuccess
     * @param $widget_type - not yet implemented
     * @param $widget_properties - not yet implemented
     * @return bool|mixed|null|string|string[]
     * @throws Exception
     */
    function expand($item_id='', $field_id='', $show_alias='', $onsuccess='', $widget_type=null, $widget_properties=null) {
        $ret = '';
        if ( $field_id) {
            $item = $item_id ? AA_Items::getItem(new zids($item_id)) : $this->item;
            if (!empty($item)) {
                $show_alias  = ($show_alias == '') ? "{alias:$field_id:f_h:, }" : $show_alias;
                if (strlen($repre_value = $item->subst_alias($show_alias)) < 1) {
                    $repre_value = '--';
                }
                if (StrExpand('AA_Stringexpand_Var', array('AA_FLAG_NOEDIT')) == 1) { // the way, how to disable editing
                    $ret = $repre_value;
                } else {
                    $widget_properties = str_replace(array("'", '"',"\r\n", "\r", "\n"), array("\'", "&quot;", " ", " ", " "), $widget_properties);
                    $iid         = $item->getItemID();
                    $input_name  = FormArray::getName4Form($field_id, $item);
                    $input_id    = FormArray::formName2Id($input_name);
                    $ret .= "<div class=\"ajax_container\" id=\"ajaxc_$input_id\" onclick=\"displayInput('ajaxv_$input_id', '$iid', '$field_id', '$widget_type', '$widget_properties')\" style=\"display:inline-block;width:100%\">";
                    $data_onsuccess = $onsuccess ? 'data-aa-onsuccess="'.myspecialchars($onsuccess).'"' : '';
                    $ret .= "<div class=\"ajax_value\" id=\"ajaxv_$input_id\" data-aa-alias=\"".myspecialchars(base64_encode($show_alias))."\" $data_onsuccess style=\"display:inline\">$repre_value</div>";
                    $ret .= "<div class=\"ajax_changes\" id=\"ajaxch_$input_id\" style=\"display:inline\"></div>";
                    $ret .= "</div>";
                    AA::Stringexpander()->addRequire('css-aa-system');
                }
            }
        }
        return $ret;
    }
}

/** Allows on-line editing of field content in HTML Editor
 *    {editable:<item_id>:<field_id>:<placeholder-text>}
 */
class AA_Stringexpand_Editable extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it shoud always look in the database

    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getID();
    }

    /** expand function
     * @param $item_id
     * @param $field_id
     * @param $placeholder
     * @return mixed|null|string|string[]
     */
    function expand($item_id='', $field_id='', $placeholder='') {
        $ret = '';
        if ( $field_id) {
            $item = $item_id ? AA_Items::getItem(new zids($item_id)) : $this->item;
            if (!empty($item)) {
                $slice = AA_Slice::getModule($item->getSliceId());

                //mgettext_bind($slice->getLang(), 'output'); // Use right language (from slice settings) - languages are used for button texts, ...

                if ($field = $slice->getField($field_id)) {
                    $index   = (AA::$langnum[0] AND in_array(AA::$lang, $field->getTranslations())) ? AA::$langnum[0] : 0;
                    $placeholder = safe(strlen($placeholder) ? $placeholder : ($index ? '['.AA::$lang.']' : ''));
                    $pholder = strlen($placeholder) ? 'placeholder="'.$placeholder.'"' : '';
                    $iid     = $item->getItemID();
                    $tag     = $field->isMultiline() ? 'div' : 'span';
                    // do not change the string: contenteditable=true id=... we are using it in AA__SiteUpdateParts() (aajslib-jquery.php)
                    $ret     = AA::Stringexpander()->unalias("<$tag contenteditable=true id=\"". str_replace('.','_', "au-$iid-$field_id-$index")."\" $pholder data-aa-id=\"$iid\" data-aa-field=\"$field_id\" data-aa-index=\"$index\">{". $field_id ."}</$tag>", '', $item);

                    AA::Stringexpander()->addRequire('ckeditor');

                    // '_aa_url = "'.AA_INSTAL_PATH.'";' . "\n" .
                    $script2run = 'CKEDITOR.on( "instanceCreated", function( event ) {' . "\n" .   // The "instanceCreated" event is fired for every editor instance created.
                        '  var editor = event.editor;' . "\n" .                          // var toolbarcreated = false;
                        '  editor.on( "change", function() {' . "\n" .                   // if (!toolbarcreated) { toolbarcreated = true; }     // not necessary to run the code on each change
                        '    window.addEventListener("beforeunload", AA_WindowUnloadQ);' . "\n" .
                        '    AA_Toolbar(\'<input type="button" value="'. _m('save changes').'" onclick="AA_SaveEditors();">\');' . "\n" .
                        '  });' . "\n" .
                        '});' . "\n";
                    AA::Stringexpander()->addRequire($script2run, 'AA_Req_Run');
                }
            }
        }
        return $ret;
    }
}

/** Creates new item based on the template item
 *    {newitem:<template_long_item_id>[:<field_1>:<new_value_1>:<field_2>:<new_value_2>:...]}
 *
 * The new item is based on template item, which is normal item which you
 * can put in HoldingBin/TrashBin... and the default values for the new item is
 * taken from the item except seo............. and status_code...... fields
 * Then you can modify the field values for the new item:
 *
 * {newitem:c239cb267837a25b4efb5892ca4f4324:headline........:test item:category........:["enviro","social"]}
 *
 * c239cb267837a25b4efb5892ca4f4324 is ID of the template item
 * The template must be in related slices (to not allow to create item in foreign slice).
 *
 * The values could be single (for headline........), or multiple - written in JSON format.
 */
class AA_Stringexpand_Newitem extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it shoud always look in the database

    /** expand function
     * @param $template_item_id
     * @param $field_id1
     * @param $value1
     * @param ...
     * @return string
     */
    function expand() {
        $arg_list              = func_get_args();   // must be asssigned to the variable
        if (!is_long_id($template_long_item_id = array_shift($arg_list))) {
            return '';
        }

        $transformations = self::_getTransformationsFromArgs($arg_list);

        // move to active, clear seo
        if ( !isset($transformations['status_code.....']) ) {
            $transformations['status_code.....'] = new AA_Transformation_Exactvalues(array('new_content'=>array(1)));
        }
        if ( !isset($transformations['seo.............']) ) {
            $transformations['seo.............'] = new AA_Transformation_Exactvalues(array('new_content'=>array('')));
        }

        // return IfSlPerm(PS_EDIT_ALL_ITEMS, $manager->getModuleId());

        // the template must be in related slices (to not allow to create item in foreign slice)
        $slices = AA::$slice_id ? array(AA::$slice_id) : array();
        if (AA::$site_id) {
            $slices = array_merge($slices, AA_Module_Site::getModule(AA::$site_id)->getRelatedSlices());
        } elseif (AA::$slice_id) {
            $sites = explode_ids(StrExpand('AA_Stringexpand_Modulefield', array(AA::$slice_id, 'site_ids')));
            foreach($sites as $site) {
                $slices = array_merge($slices, AA_Module_Site::getModule($site)->getRelatedSlices());
            }
        }

        if (!count($slices)) {
            warn('No related slice found');
            return '';
        }

        $grabber = new AA\IO\Grabber\Slice(new AA_Set($slices,null,null,AA_BIN_ALL), new zids($template_long_item_id,'l'));
        // insert_if_new is the same as insert, (but just make sure the item is not in DB which is not important here)
        $saver   = new AA\IO\Saver($grabber, $transformations, null, 'insert_if_new', 'new');
        list($ok,$err) = $saver->run();
        $err && warn('Saver run err '. $err. ': '. $saver->report());
        return join('-',$saver->changedIds());
    }

    /**
     * @param $arg_list
     * @return array
     */
    static protected function _getTransformationsFromArgs($arg_list) {
        $i                     = 0;
        $transformations       = array();
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if ( (strlen($field_id = $arg_list[$i]) != 16) ) {
                $i += 2;
                continue;
            }
            if ( !count($value=json2arr($arg_list[$i+1])) ) {
                $value = array('');
            }
            $transformations[$field_id] = new AA_Transformation_Exactvalues(array('new_content'=>$value));
            $i += 2;
        }
        return $transformations;
    }
}

/** Updates item fields
 *    {updateitem:<long_item_id>[:<field_1>:<new_value_1>:<field_2>:<new_value_2>:...]}
 *
 *  It works only on slices where "Allow anonymous editing of items" is set to "All items", right now.
 *  The plan is to work with field permissions in future
 *
 *    {updateitem:c239cb267837a25b4efb5892ca4f4324:headline........:test item:category........:["enviro","social"]}
 *
 * c239cb267837a25b4efb5892ca4f4324 is ID of the updated item
 * The values could be single (for headline........), or multiple - written in JSON format.
 *
 * If you want to update the item conditionaly just do not fill <long_item_id> in cases you do not want update
 *
 * @return <long_item_id> if updated, nothing if item is not updated
 *
 */
class AA_Stringexpand_Updateitem extends AA_Stringexpand_Newitem {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it shoud always look in the database

    /** expand function
     * @param $item_id
     * @param $field_id1
     * @param $value1
     * @param ...
     * @return string
     */
    function expand() {
        $arg_list = func_get_args();   // must be asssigned to the variable
        $zid      = new zids(array_shift($arg_list), 'l');

        if ( !is_object($item = AA_Items::getItem($zid)) ) { warn(_m('No item ID')); return ''; }

        $transformations = self::_getTransformationsFromArgs($arg_list);

        if ( !count($restrict_fields  = array_filter(array_keys($transformations), function($fld) { return strlen($fld)==16; })) ) {
            warn(_m('No transformations'));
            return '';
        }

        $item_slice = $item->getSliceId();
        $slices = array();
        if (AA::$site_id) {
            if ($site = AA_Module_Site::getModule(AA::$site_id)) {
                $slices = $site->getRelatedSlices();
            }
        } elseif (AA::$module_id) {  // called inside AA admin interface
            $slices = array(AA::$module_id);
        } elseif (AA::$slice_id) {  // called in view
            $slices = array(AA::$slice_id);
        }

        if (!$item_slice)                    { warn(_m('No slice for item')); return ''; }
        if (!count($slices))                 { warn(_m('No current slice(s)')); return ''; }
        if (!in_array($item_slice, $slices)) { warn(_m('Item slice is not in list of allowed slices')); return ''; }

        $perm = (AA_Slice::getModuleProperty($item_slice,'permit_anonymous_edit')==ANONYMOUS_EDIT_ALL) OR (AA::$perm->getPermMode()=='cron');
        if (!$perm) { warn(_m('No permit_anonymous_edit permission for the slice')); return ''; }

        $grabber = new AA\IO\Grabber\Slice(new AA_Set($slices,null,null,AA_BIN_ALL), $zid, $restrict_fields);
        $saver   = new AA\IO\Saver($grabber, $transformations, $item_slice, 'update');
        $saver->run();
        return join('-',$saver->changedIds());
    }
}

/** Get module (slice, ...) property (currently only "module fileds"
 *  (beggining with underscore), 'url', 'name', 'charset' and 'site_ids' is supported
 *  replacing older {alias:_abstract.......:f_s:slice_info} syntax
 *  Ussage:
 *         {modulefield:2e637469436961532d65732e332e446a:charset}
 *         {modulefield:{_#SLICE_ID}:url}
 **/
class AA_Stringexpand_Modulefield extends AA_Stringexpand {
    /** expand function
     * @param $property
     * @return bool|mixed|null|string
     */
    function expand($slice_id='', $property='') {
        // we do not want to allow users to get all field setting
        // that's why we restict it to the properties, which makes sense
        // @todo - make it less restrictive

        switch ($property) {
            // site_id is older, but better is to use site_ids, since it could return more than one ids (dash separated)
            case 'site_ids':
            case 'site_id':
                return join('-', (array)GetTable2Array("SELECT source_id FROM relation WHERE destination_id='".q_pack_id($slice_id)."' AND flag='".REL_FLAG_MODULE_DEPEND."'", '', 'unpack:source_id'));
            case 'charset':
                return ($m = AA_Module::getModule($slice_id)) ? $m->getCharset() : '';
        }
        if (!AA_Fields::isSliceField($property)) {  // it is "slice field" (begins with underscore _)
            $property = ($property=='url') ? 'slice_url' : 'name';
        }
        return AA_Slice::getModuleProperty($slice_id, $property);
    }
}

/** Get site module property
 *  (currently only "modules" - dash separated list of slices in the site)
 *  I use it for example for computing of seo name:
 *  {ifset:{seo.............}:_#1:{seoname:{_#HEADLINE}:{site:{modulefield:{_#SLICE_ID}:site_ids}:modules}}}
 **/
class AA_Stringexpand_Site extends AA_Stringexpand {
    /** expand function
     * @param $property
     * @return string
     */
    function expand($site_ids='', $property='') {
        $arr = '';
        if ($property == 'modules') {
            $where = sqlin( 'source_id', array_map("pack_id", explode_ids($site_ids)) );
            $arr   = GetTable2Array("SELECT destination_id FROM relation WHERE $where AND flag='".REL_FLAG_MODULE_DEPEND."'", "", 'unpack:destination_id');
        }
        return is_array($arr) ? join('-' , array_values(array_unique($arr))) : '';
    }
}

/** Get html code of the site's spot with specified ID
 *  {sitespot:8}
 **/
class AA_Stringexpand_Sitespot extends AA_Stringexpand {

    /** expand function
     * @param $property
     * @return mixed|null|string|string[]
     */
    function expand($spot_id='')
    {
        $ret = '';
        if (AA::$site_id AND ctype_digit((string)$spot_id)) {
            if ($spot = DB_AA::select1('SELECT flag, content FROM site_spot', '', array(array('site_id', AA::$site_id, 'l'), array('spot_id', $spot_id, 'i')))) {
                $ret = ($spot['flag'] & MODW_FLAG_JUST_TEXT) ? $spot['content'] : AA::Stringexpander()->unalias($spot['content']);
            }
        }
        return $ret;
    }
}




/** @deprecated - use AA_Stringexpand_Modulefield instead
 *
 *  Get module (slice, ...) property (currently only 'name' is supported
 *
 *  Never cached because of grabbing the slice_id from item or globals
 *  however it never mind - underlaying AA_Stringexpand_Modulefield is cached
 */
class AA_Stringexpand_Slice extends AA_Stringexpand_Nevercache {

    /** additionalCacheParam function */
    function additionalCacheParam() {
        /** output is different for different slices - place item id into cache search */
        return is_object($this->item) ? $this->item->getSliceID() : $GLOBALS['slice_id'];
    }

    /** expand function
     * @param $property
     * @return string
     */
    function expand($property='name') {
        // get slice_id from item, but sometimes the item is not filled (like
        // on "Add Item" in itemedit.php3, so we use global slice_id here
        $item = $this->item;
        $slice_id  = $item ? $item->getSliceID() : $GLOBALS['slice_id'];
        if (!$slice_id ) {
            return "";
        }
        return StrExpand('AA_Stringexpand_Modulefield', array($slice_id, $property));
    }
}

/** do not use for site modules - use AA_Stringexpand_Pager instead
 *
 *  {scroller:<begin>:<end>:<add>:<nopage>}
 *  Displys page scroller for view
 */
class AA_Stringexpand_Scroller extends AA_Stringexpand {
    /** Do not trim all parameters ($add could contain space) */
    function doTrimParams() { return false; }

    /** additionalCacheParam function */
    function additionalCacheParam() {
        $itemview = $this->itemview;
        return !is_object($itemview) ? '' : $itemview->slice_info['vid'].':'.$itemview->clean_url.':'.$itemview->num_records.':'.$itemview->idcount().':'.$itemview->from_record;
    }

    /** expand function
     * @param $property
     * @return string
     */
    function expand($begin='', $end='', $add='', $nopage='') {
        $itemview = $this->itemview;
        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th grou display
            return "Scroller not valid without a view, or for group display";
        }
        $viewScr = new AA_Scroller_Sitemodule($itemview->slice_info['vid'], $itemview->num_records, $itemview->idcount(), $itemview->from_record);
        return $viewScr->get( $begin, $end, $add, $nopage);
    }
}

/** page scroller for site modules views - displys page scroller for view
 *
 *  It calls router methods, so it displays the right urls in the scroller
 *  @see AA_Router::scroller() method
 *
 *  Must be issued inside the view
 *
 *  Now it is possibile to use {pager} on views called by AJAX (for live searches, ...).
 *  Just put the parameter "div-id" to pager:
 *           {pager:resuts}
 *  where result is the div id, in which the view dispays the values
 *           <div id="results">..view output there</div>
 */
class AA_Stringexpand_Pager extends AA_Stringexpand_Nevercache {

    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        global $apc_state;
        $itemview = $this->itemview;
        return !is_object($itemview) ? '' : serialize($apc_state['router']).':'.$itemview->num_records.':'.$itemview->idcount().':'.$itemview->from_record;
    }

    /** expand function
     * @param $property
     * @return mixed|string
     */
    function expand($target=null) {
        global $apc_state;

        $itemview = $this->itemview;
        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th grou display
            return "Err in {pager} - pager not valid without a view, or for group display";
        }

        if ($target) {
            AA::Stringexpander()->addRequire('aa-jslib');  // for AA_Ajax
        }

        if (!isset($apc_state['router'])) {
            // used for AJAX scroller in the SEO sitemodule, for example
            $viewScr = new AA_Scroller_View($itemview->slice_info['vid'], $itemview->num_records, $itemview->idcount(), $itemview->from_record);
            return $viewScr->get( '', '', '', '', $target);
        }

        $class_name = $apc_state['router'];
//        $router = new $class_name;
        $router     = AA_Router::singleton($class_name);
        $page       = floor( $itemview->from_record/$itemview->num_records ) + 1;
        $max        = floor(($itemview->idcount() - 1) / max(1,$itemview->num_records)) + 1;

        return $router->scroller($page, $max, $target);
    }
}


/** page scroller for site modules views - displys page scroller for view
 *
 *  It calls router methods, so it displays the right urls in the scroller
 *  @see AA_Router::scroller() method
 *
 *  Must be issued inside the view
 *
 *  Now it is possibile to use {pager} on views called by AJAX (for live searches, ...).
 *  Just put the parameter "div-id" to pager:
 *           {pager:resuts}
 *  where result is the div id, in which the view dispays the values
 *           <div id="results">..view output there</div>
 */
class AA_Stringexpand_Pagermore extends AA_Stringexpand_Nevercache {
// @todo - experimental - not finished


    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        global $apc_state;
        $itemview = $this->itemview;
        return !is_object($itemview) ? '' : serialize($apc_state['router']).':'.$itemview->num_records.':'.$itemview->idcount().':'.$itemview->from_record;
    }

    /** expand function
     * @param $property
     * @return mixed|string
     */
    function expand() {
        global $apc_state;

        $itemview = $this->itemview;
        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th grou display
            return "Err in {pager} - pager not valid without a view, or for group display";
        }

        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_Ajax

        $target = '';

        $viewScr = new AA_Scroller_View($itemview->slice_info['vid'], $itemview->num_records, $itemview->idcount(), $itemview->from_record);
        return $viewScr->get( '', '', '', '', $target);
    }
}



/** debugging
 */
class AA_Stringexpand_Debug extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters  */
    function doTrimParams() { return false; }

    /** expand function
     * @param $property
     * @return string
     */
    function expand( $text='' ) {
        $ret = '';
        switch ($text) {
            case '0':  $GLOBALS['debug'] = 0; break;
            case '1':  $GLOBALS['debug'] = 1; break;

            // do not rely on this - could be changed. If you want specific format, then add any text parameter
            default:   $ret = "\nDababase instances: ". DB_AA::$_instances_no;
        }
        return $ret;
    }
}

/** Uses slice (or slices) ($dictionaries) and replace any word which matches a
 *  word in dictionary by the text specified in $format.
 *  It do not search in <script>, <a>, <h*> tags and HTML tags itself.
 *  It also searches only for whole word (not word substrings)
 *  It is writen as quick as possible, so we do not use preg_replace for the
 *  main replaces (it is extremly slow for bigger dictionaries) - strtr used
 *  instead
 *  @author Honza Malik, Hana Havelkova
 */
class AA_Stringexpand_Dictionary extends AA_Stringexpand {

    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $dictionaries
     * @param $text
     * @param $format
     * @param $conds
     * @return mixed|null|string|string[]
     */
    function expand($dictionaries='', $text='', $format='', $conds='') {
        // sometimes this function last to much time - try to extend it
        if (($max_execution_time = ini_get('max_execution_time')) > 0) {
            set_time_limit($max_execution_time+20);
        }

        $dictionaries = explode_ids($dictionaries);

        $delimiters = AA_Stringexpand_Dictionary::defineDelimiters();
        // get pairs (like APC - <a href="http://apc.org">APC</a>' from dict. slice
        // (we call it through the pagecache in order it is called only once for
        // the same parameters)
        $replace_pairs = AA::Pagecache()->cacheMemDb(array('AA_Stringexpand_Dictionary','getDictReplacePairs'), array($dictionaries, $format, $delimiters, $conds), new CacheStr2find($dictionaries));

        // we do not want to replace text in the html tags, so we substitute all
        // html with "shortcut" (like _AA_1_ShCuT) and the content is stored in the
        // $html_subst_arr. Then it is used with replace_pairs to return back

        $html_subst_arr = array();
        $search = array ("'<script[^>]*?>.*?</script>'si",  // Strip out javascript
            "'<h[1-6][^>]*?>.*?</h[1-6]>'si",  // Strip out titles
            // can't be nested
            "'<a[^>]*?>.*?</a>'si",            // Strip out links
            "'<[\/\!]*?[^<>]*?>'si");          // Strip out HTML tags

        // substitute html tags with shortcuts
        // (= remove the code where we do not want replace text)
        //
        // Store $text in the $html_subst_arr array - used for dictionary escaping html  tags.

        $text = preg_replace_callback (
            $search,
            function ($matches) use (&$html_subst_arr) {
                $shortcut = '_AA_'.hash('md5', $matches[0]).'_ShCut';
                $html_subst_arr[$shortcut] = stripslashes($matches[0]);
                return $shortcut;
            },
            $text);

        // Insert special string before the beginning and after the end of the text
        // Replacing all delimiters with special strings!!!
        $text = 'AA#@'.strtr($text, $delimiters).'AA#@';

        // add shortcuts also to the replace_pairs, so all is done in one step
        $replace_pairs = array_merge($replace_pairs, $html_subst_arr);
        // do both: process dictionary words and put back the shortcuted text

        $text = strtr($text, $replace_pairs);

        // finally - removing additional vaste text 'AA#@' - recovering original
        // word delimiters
        $text = str_replace('AA#@', '', $text);
        return $text;
    }

    /** getDictReplacePairs function
     *  Return array of substitution pairs for dictionary, based on given dictionary
     *  slice, format string which defines the format and possible slice codnitions.
     *   [biom] => <a href="http://biom.cz">_#KEYWORD_</a>, ...
     * @param $dictionaries (array)
     * @param $format
     * @param $delimeters
     * @param $conds
     * @return array
     */
    function getDictReplacePairs($dictionaries, $format, $delimiters, $conds='') {
        // return array of pairs: [biom] => <a href="http://biom.cz">_#KEYWORD_</a>
        $replace_pairs = array();

        // conds string could contain also sort[] - if so, use conds also as $sort
        // parameter (the sort is grabbed form the string then)
        $sort     = (strpos( $conds, 'sort') !== false ) ? $conds : '';

        /** 'keywords........' field could contain multiple values. In this case we
         *  have to create pair for each of the word. The _#KEYWORD_ alias is then
         *  used in format string
         */

        $format  = AA_Slice::getModule($dictionaries[0])->getField($format) ? '{substr:{'.$format.'}:0:50}' : $format;
        $format  = "{@keywords........:##}_AA_DeLiM_$format";

        // above is little hack - we need keyword pair, but we want to call
        // GetFormatedItems only once (for speedup), so we create one string with
        // delimiter:
        //   BIOM##Biom##biom_AA_DeLiM_<a href="http://biom.cz">_#KEYWORD_</a>

        $set     = new AA_Set($dictionaries, $conds, $sort);
        $kw_item = GetFormatedItems($set->query(), $format);

        foreach ( $kw_item as $kw_string ) {
            list($keywords, $link) = explode('_AA_DeLiM_', $kw_string,2);
            $kw_array              = explode('##', $keywords);
            foreach ( (array)$kw_array as $kw ) {
                if (!strlen($kw)) {
                    continue;
                }
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

    /** defineDelimiters function
     *  It's necessary to select characters used as standard word delimiters
     *  Check the value of the string variable $delimiter_chars and correct it.
     *  Associative array $delimiters contains frequently used delimiters and it's
     *  special replace_strings used as word boundaries
     *  @author haha
     */
    function defineDelimiters() {
        $delimiter_chars = "()[] ,.;:?!\"'\n\r";   // I removed & in order you can disable substitution by adding
        // &nbsp; or even better &zwnj; character to the word - like: gender&zwnj;
        $delimiters = array();
        for ($i=0, $len=strlen($delimiter_chars); $i<$len; $i++) {
            $index              = $delimiter_chars[$i];
            $delimiters[$index] = 'AA#@'.$index.'AA#@';
        }
        // HTML tags are word delimiters, too
        $delimiters['<'] = 'AA#@<';
        $delimiters['>'] = '>AA#@';
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
}

/** include file, first parameter is filename, second is hints on where to find it **/
class AA_Stringexpand_Include extends AA_Stringexpand {
    /** expand function
     * @param string $fn - filename
     * @param string $type - hints on where to find it
     * @return array|string
     */
    function expand($fn='', $type='') {
        if (!$fn) {
            return "";
        }
        // Could extend this to recognize | seperated alternatives
        if (!$type) {
            $type = "http";  // Backward compatability
        }
        switch ($type) {
            case "http":
                $fileout = expandFilenameWithHttp($fn);
                break;
            case "fileman":
                // Note this won't work if called from a Static view because no slice_id available
                // This should be fixed.
                if ($this->itemview->slice_info["id"]) {
                    $mysliceid = unpack_id($this->itemview->slice_info['id']);
                } elseif ($GLOBALS['slice_id']) {
                    $mysliceid = $GLOBALS['slice_id'];
                } else {
                    // if ($errcheck) huhl("No slice_id defined when expanding fileman");
                    return "";
                }
                $fileman_dir = AA_Slice::getModuleProperty($mysliceid,'fileman_dir');
            // Note dropthrough from case "fileman"
            case "site":
                if ($type == "site") {
                    if (!($fileman_dir = $GLOBALS['site_fileman_dir'])) {
                        // if ($errcheck) huhl("No site_fileman_dir defined in site file");
                        return "";
                    }
                }
                $filename = FILEMAN_BASE_DIR . $fileman_dir . "/" . $fn;
                $file = &AA_File_Wrapper::wrapper($filename);
                // $file->contents(); opens the stream, reads the data and close the stream
                $fileout = $file->contents();
                break;
            case "readfile": //simple support for reading static html (use at own risk)
                $filename = $_SERVER["DOCUMENT_ROOT"] . "/" . $fn;
                $file = &AA_File_Wrapper::wrapper($filename);
                // $file->contents(); opens the stream, reads the data and close the stream
                $fileout = $file->contents();
                break;
            default:
                // if ($errcheck) huhl("Trying to expand include, but no valid type: $type");
                return("");
        }
        return $fileout;
    }
}


/** expandFilenameWithHttp function
 *  Expand any quotes in the parturl, and fetch via http
 * @param $parturl
 * @return array|string
 */
function expandFilenameWithHttp($parturl) {
    $filename = str_replace( 'URL_PARAMETERS', DeBackslash(shtml_query_string()), $parturl);

    // filename do not use colons as separators => dequote before callig
    if (!$filename || trim($filename)=="") {
        return "";
    }

    $headers  = array();
    // if no http request - add server name
    if (!(aa_substr($filename, 0, 7) == 'http://') AND !(aa_substr($filename, 0, 8) == 'https://')) {
        $filename = self_server(). (($filename{0}=='/') ? '' : '/'). $filename;
        if (!empty($_SERVER["HTTP_COOKIE"])) {
            // we resend cookies only for local requests (It could be usefull for AA_Auth ...)
            $headers  = array('Cookie'=>$_SERVER["HTTP_COOKIE"]);
        }
    }

    return AA_Http::postRequest($filename, array(), $headers);
    // $file = &AA_File_Wrapper::wrapper($filename);
    // $file->contents(); opens the stream, reads the data and close the stream
    // return $file->contents();
}

/** Get $_SERVER[<variable>] value
 *   {server:REMOTE_ADDR}, {server:SERVER_NAME}, {server:HTTP_HOST}, {server:REFERER},...
 **/
class AA_Stringexpand_Server extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $variable
     * @return mixed
     */
    function expand($variable='') {
        return $_SERVER[$variable];
    }
}

/** helper class
 *  Its purpose is just tricky - we can't use preg_replace_callback where callback
 *  function has some more parameters. So we use this class as callback
 */
class AA_Unalias_Callback {
    /**
     * @var AA_Item
     */
    var $item;
    /**
     * @var itemview
     */
    var $itemview;

    // We use different AA_Unalias_Callback object for each item, so this cache
    // is usefull just in case we are using the same expresion inside the same
    // spot or view field
    // We use it just for easy expressions - like field, aliases, where we do not use $contentcache
    // var $_localcache;

    /** AA_Unalias_Callback function
     * @param $item
     * @param $itemview
     */
    function __construct( $item, $itemview ) {
        $this->item        = is_object($item) ? $item : null;
        $this->itemview    = $itemview;
        // $this->_localcache = array();
    }

    /**
     * @param $match
     * @return mixed|string
     */
    function expand_bracketed_timedebug($match) {
        $func = current(explode(':',aa_substr($match[1],0,18),2));
        AA::$dbg->tracestart($func, $match[1]);
        $ret  = $this->expand_bracketed($match);
        AA::$dbg->traceend($func, $ret);
        return $ret;
    }

    /** expand_bracketed function
     *  Expand a single, syntax element
     * @param $out
     * @param $level
     * @param $item
     * @param $itemview
     * @return mixed|string
     */
    function expand_bracketed($match) {
        global $als, $errcheck;
        $out = $match[1];

        // See http://apc-aa.sourceforge.net/faq#aliases for details
        // bracket could look like:
        // {alias:[<field id>]:<f_* function>[:parameters]} - return result of f_*
        // {switch(testvalue)test:result:test2:result2:default}
        // {math(<format>)expression}
        // {include(file)}
        // {include:file} or {include:file:http}
        // {include:file:fileman|site}
        // {include:file:readfile[:str_replace:<search>[;<search1>;..]:<replace>[:<replace1>;..]:<trim-to-tag>:<trim-from-tag>[:filter_func]]}
        // {scroller.....}
        // {pager:.....}
        // {#comments}
        // {debug}
        // {inputvar:<field_id>:part:param}
        // {formbreak:part_name}
        // {formpart:}
        // {view.php3?vid=12&cmd[12]=x-12-34}
        // {dequote:already expanded and quoted string}
        // {fnctn:xxx:yyyy}   - expand AA_Stringexpander::$php_functions[fnctn]
        // {unpacked_id.....}
        // {mlx_view:view format in html} mini view of translatiosn available for this article
        //                                does substitutions %lang, %itemid
        // {xxxx}
        //   - looks for a field xxxx
        //   - or in $GLOBALS[apc_state][xxxx]
        //   - als[xxxx]
        //   - aliases[xxxx]
        // {_#ABCDEFGH}
        // {const_<what>:<field_id>} - returns <what> column from constants for the value from <field_id>
        // {any text}                                       - return "any text"
        //
        // all parameters could contain aliases (like "{any _#HEADLINE text}"),
        // which are processed after expanding the function

        $outlen     = strlen($out);

        switch ($out[0]) {
            // remove comments
            case '#': return '';
            /** Wraps the text, so you can use the content without taking care about quoting
             *  of parameter delimiters ":"
             *
             *  Example: {-<a href="http://ecn.cz">ecn</a>}
             *           {ifset:{_#ABSTRACT}:{-<div style="color:red">_#1</div>}}
             */
            case '-': return QuoteColons(aa_substr($out,1));
            case '@': return QuoteColons(parseLoop($out, $this->item));
            case ' ':
            case "\n":
            case "\t":
                return QuoteColons("{" . $out . "}");
            case '_':         // Look for {_#.........} and expand now, rather than wait till top
                if ($out[1] == "#") {
                    if (isset($als[aa_substr($out,2)])) {
                        return QuoteColons(AA::Stringexpander()->unalias($als[aa_substr($out,2)], '', $this->item, false, $this->itemview));
                    } elseif (isset($this->item)) {
                        // just alias or not so common: {_#SOME_ALSand maybe some text}
                        return QuoteColons(($outlen == 10) ? $this->item->get_alias_subst($out) : $this->item->substitute_alias_and_remove($out));
                    }
                    //// This somehow did not work for {item:{xid:1}:{ifeq:{_#EVERMA21}:1::...}} so the concept of localcache is removed. Honza 2015-07-27
                    // if (isset($this->_localcache[$loccache_id = $out.$this->item->getItemID()])) {
                    //     return $this->_localcache[$loccache_id];
                    // }
                    // if (isset($als[substr($out,2)])) {
                    //     return ($this->_localcache[$loccache_id] = QuoteColons(AA::Stringexpander()->unalias($als[substr($out,2)], '', $this->item, false, $this->itemview)));
                    // } elseif (isset($this->item)) {
                    //     // just alias or not so common: {_#SOME_ALSand maybe some text}
                    //     return ($this->_localcache[$loccache_id] = QuoteColons(($outlen == 10) ? $this->item->get_alias_subst($out) : $this->item->substitute_alias_and_remove($out)));
                    // }
                }
                break;
        }

        if (($outlen == 16) AND isset($this->item)) {
            switch ($out) {
                case 'unpacked_id.....':
                case 'id..............':
                    return $this->item->getItemID();   // should be called in QuoteColons(), but we don't need it
                case 'slice_id........':
                    return $this->item->getSliceID();
                case 'short_id........':
                case 'status_code.....':
                case 'post_date.......':
                case 'publish_date....':
                case 'expiry_date.....':
                case 'highlight.......':
                case 'posted_by.......':
                case 'edited_by.......':
                case 'last_edit.......':
                case 'display_count...':
                    return $this->item->f_1($out);               // for speedup - we know it is not multivalue and not needed quoting
                case 'seo.............':
                    return QuoteColons($this->item->f_1($out));  // for speedup and safety - ignore, if it is multivalue
                default:
                    if ( $this->item->isField($out) ) {
                        return QuoteColons($this->item->f_h($out,'AA_DashOrLanG'));
                        // QuoteColons used to mark colons, which is not parameter separators.
                    }
            }
        }

        // if in_array - for speedup
        if (in_array(aa_substr($out, 0, 5), array('const', 'alias', 'math(', 'inclu', 'view.', 'dequo', 'list:'))) {
            // look for {const_*:} for changing viewing type of constants
            if ((aa_substr($out, 0, 6) == "const_") AND isset($this->item)) {
                // $what - name of column (eg. from const_name we get name)
                $what = aa_substr($out, aa_strpos($out, "_")+1, aa_strpos($out, ":") - aa_strpos($out, "_")-1);
                // parameters - first is field
                $parts = ParamExplode(aa_substr($out,aa_strpos($out,":")+1));
                // get group id
                $group_id = getConstantsGroupID($this->item->getSliceID(), $parts[0]);
                /* get short_id/name/... of constant with specified value from constants category with
                   group $group_id */

                $value = getConstantValue($group_id, $what, $this->item->getval($parts[0]));

                return QuoteColons($value);
            }
            // s - multiline - without it it does not work for expressions across multiple lines
            elseif ( (aa_substr($out, 0, 5)=='alias') AND isset($this->item) AND preg_match('/^alias:([^:]*):([a-zA-Z0-9_]{1,3}):?(.*)$/s', $out, $parts) ) {
                // call function (called by function reference (pointer))
                // like f_d("start_date......", "m-d")
                if ($parts[1] && !$this->item->isField($parts[1])) {
                    huhe("Warning: $out: $parts[1] is not a field, don't wrap it in { } ");
                }
                $fce     = $parts[2];
                return QuoteColons($this->item->$fce($parts[1], $parts[3]));
                // QuoteColons used to mark colons, which is not parameter separators.
            }
            elseif( aa_substr($out, 0, 5) == "math(" ) {
                // replace math
                return QuoteColons( parseMath(DeQuoteColons(AA::Stringexpander()->unalias(aa_substr($out,5), '', $this->item, false, $this->itemview))) ); // Need to unalias in case expression contains _#XXX or ( )
            }
            elseif( aa_substr($out, 0, 5) == "list:" ) {
                return QuoteColons(parseLoop($out, $this->item));
            }
            elseif( aa_substr($out, 0, 8) == "include(" ) {
                // include file
                if ( !($pos = aa_strpos($out,')')) ) {
                    return "";
                }
                $fileout = expandFilenameWithHttp(DeQuoteColons(aa_substr($out, 8, $pos-8)));
                return QuoteColons($fileout);
                // QuoteColons used to mark colons, which is not parameter separators.
            }
            elseif ( aa_substr($out, 0,10) == "view.php3?" ) {
                // Xinha editor replaces & with &amp; so we need to change it back
                $param      = str_replace(array('&amp;','-&lt;','-&gt;','&lt;-','&gt;-'), array('&','-<','->','<-','>-'), aa_substr($out,10));
                // do not store in the pagecache, but store into contentcache



                $showview   = new AA_Showview(ParseViewParameters(DeQuoteColons($param)));
                return QuoteColons(AA::Contentcache()->get_result(array($showview,'getViewOutput')));
            }
            // This is a little hack to enable a field to contain expandable { ... } functions
            // if you don't use this then the field will be quoted to protect syntactical characters
            elseif ( aa_substr($out, 0, 8) == "dequote:" ) {
                return DeQuoteColons(aa_substr($out,8));
            }
        }
        // OK - its not a known fixed string, look in various places for the whole string
        // if ( preg_match('/^([a-zA-Z_0-9]+):?([^}]*)$/', $out, $parts) ) {
        // the longest php function name in whole PHP is 38 chars - test 40 should be enough
        $initiallen = strspn(strtolower(aa_substr($out,0,40)), 'abcdefghijklmnopqrstuvwxyz0123456789_');
        if ( $outcmd   = aa_substr($out,0,$initiallen) ) {
            $outparam = aa_substr($out,$initiallen+1);  // skip one more char - delimiter

            if (class_exists($class_name = AA_Serializable::constructClassName($outcmd, 'AA_Stringexpand_'))) {
                return StrExpand($class_name, $class_name::parseParam($outparam), array('item'=>$this->item, 'itemview'=> $this->itemview), true);
            }
            elseif ( ($fnctn = AA_Stringexpander::$php_functions[$outcmd]) OR is_callable($fnctn = "stringexpand_$outcmd") ) {
                // eb functions - call allowed php functions directly

                if (!strlen($outparam)) {
                    $ebres = @$fnctn();
                } else {
                    $param = array_map('DeQuoteColons',ParamExplode($outparam));
                    $ebres = @call_user_func_array($fnctn, (array)$param);
                }
                return QuoteColons($ebres);
            }
            // else - continue
        }
        // Look and see if its in the state variable in module site
        // note, this is ignored if apc_state isn't set, i.e. not in that module
        // If found, unalias the value, then quote it, this expands
        // anything inside the value, and then makes sure any remaining quotes
        // don't interfere with caller
        if (isset($GLOBALS['apc_state'][$out])) {
            return QuoteColons(AA::Stringexpander()->unalias($GLOBALS['apc_state'][$out], '', $this->item, false, $this->itemview));
        }
        // Pass these in URLs like als[foo]=bar,
        // Note that 8 char aliases like als[foo12345] will expand with _#foo12345
        elseif (isset($als[$out])) {
            return QuoteColons(AA::Stringexpander()->unalias($als[$out], '', $this->item, false, $this->itemview));
        }
        elseif (aa_substr($out,0,8) == "mlx_view") {
            if(!$GLOBALS['mlxView']) {
                return "$out";
            }
            //$param = array_map('DeQuoteColons',ParamExplode($parts[2]));
            return $GLOBALS['mlxView']->getTranslations($this->item->getval('id..............'),
                $this->item->getval('slice_id........'),array_map('DeQuoteColons',ParamExplode($parts[2])));
        }
        // Put the braces back around the text and quote them if we can't match
        else {
            // Don't warn if { followed by non alphabetic, e.g. in Javascript
            // Fix javascript to avoid this warning, typically add space after {
            // if ($errcheck && preg_match("/^[a-zA-Z_]/",$out)) {
            //     huhl("Couldn't expand: \"{$out}\"");
            // }
            return QuoteColons("{" . $out . "}");
        }
    }
}

/**
 *  Ussage: StrExpand('AA_Stringexpand_Date', array($format,$timestamp));
 *          StrExpand($outcmd, array($format,$timestamp), array('item'=>$this->item, 'itemview'=> $this->itemview))
 */
function StrExpand($class_name, $params=array(), $context_arr=array(), $allow_quote_colons=false, $ignore_cache=false) {
    /** @var AA_Stringexpand $stringexpand */
    $stringexpand = new $class_name($context_arr);
    if ( $stringexpand->doCache() AND !$ignore_cache) {
        $res = AA::Contentcache()->get_result_4_object(array($stringexpand, 'expand'), $params, $class_name, $stringexpand->additionalCacheParam());
    } else {
        $res = call_user_func_array( array($stringexpand,'expand'), $params);
    }
    return (string)(($allow_quote_colons AND $stringexpand->doQuoteColons()) ? QuoteColons($res) : $res);
}

/**
 * @param $match
 * @return string
 */
function make_reference_callback($match) {
    $ref = 'R'. mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
    $txt = $match[1];          // for dereference
    AA::Contentcache()->set("define:$ref", $txt);
    return "{var:$ref}";
}

// This isn't used yet, might be changed
// remove this comment if you use it!

/**
 * Class AA_Stringexpand_Slice_Comments
 */
class AA_Stringexpand_Slice_Comments extends AA_Stringexpand {
    /** expand function
     * @param $slice_id
     * @return int
     */
    function expand($slice_id='') {
        $dc = DB_AA::select1('SELECT sum(disc_count) AS total FROM `item`', 'total', array(array('slice_id', $slice_id,'l')));
        return $dc ?: 0;
    }
}

/**
 * Class AA_Stringexpand_Preg_Match
 */
class AA_Stringexpand_Preg_Match extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    // No reason to cache this simple function

    /** expand function
     * @param $pattern
     * @param $subject
     * @return if|mixed
     */
    function expand($pattern='', $subject='') {
        // preg functions with pattern like '/pattern/e' allowed PHP code execution.
        // On the other hand - it worked only for preg_replace and it was removed in php 7.0
        // @todo remove the check for php >=7.0
        if (strpos($pattern,'e', strpos($pattern, $pattern{0}, 1))) {
            return _m('PHP patterns in Preg_Match are not allowed');
        }
        preg_match($pattern, $subject, $matches);
        return $matches[0];
    }
}

/**
 * Class AA_Stringexpander
 */
class AA_Stringexpander {
    /**
     * @var int
     */
    protected $recursion_count = 0;
    /**
     * @var array
     */
    protected $dependent_parts = array();
    /**
     * @var array
     */
    protected $postprocesess   = array();
    /**
     * @var AA_Requires
     */
    protected $requires;

    /**
     * AA_Stringexpander constructor.
     */
    function __construct() {
        $this->requires = new AA_Requires();
    }

    /**
     * @return string
     */
    public function __toString() {
        $ret  = "AA_Stringexpander->recursion_count:";
        $ret .= print_r($this->recursion_count, true);
        $ret .=  "AA_Stringexpander->dependent_parts:";
        $ret .= print_r($this->dependent_parts, true);
        $ret .=  "AA_Stringexpander->postprocesess:";
        $ret .= print_r($this->postprocesess, true);
        $ret .=  "AA_Stringexpander->requires:";
        $ret .= print_r($this->requires, true);
        return $ret;
    }

    /** In this array are set functions from PHP or elsewhere that can usefully go in {xxx:yyy:zzz} syntax */
    public static $php_functions = array (
        'strlen'           => 'strlen',             // old  AA_Stringexpand_Strlen
        'str_repeat'       => 'str_repeat',         // old  AA_Stringexpand_Str_repeat
        'strtoupper'       => 'strtoupper',         //      AA_Stringexpand_Strtoupper
        'strtolower'       => 'strtolower',         //      AA_Stringexpand_Strtolower
        'ucfirst'          => 'ucfirst',            //
        'ucwords'          => 'ucwords',            //
        'striptags'        => 'strip_tags',         // old  AA_Stringexpand_Striptags
        'htmlspecialchars' => 'myspecialchars',     // old  AA_Stringexpand_Htmlspecialchars - similar to {safe}, but without double_escape
        'urlencode'        => 'urlencode',          // old  AA_Stringexpand_Urlencode
        'ord'              => 'ord',                // old  AA_Stringexpand_Ord
        'rand'             => 'rand',               // old  AA_Stringexpand_Rand
        'fmod'             => 'fmod',               // old  AA_Stringexpand_Fmod
        /** math function log() */
        'log'              => 'log',                // old  AA_Stringexpand_Log
        'unpack'           => 'unpack_id',          // old  AA_Stringexpand_Unpack
        // 'packid'           => 'packid',             // see  AA_Stringexpand_Packid
        'string2id'        => 'string2id',          // old  AA_Stringexpand_String2id
        'base64'           => 'base64_encode',      // old  AA_Stringexpand_Base64

        /** Prints version of AA as fullstring, AA version (2.11.0), or svn revision (2368)
         *  {version[:aa|svn]}
         **/
        'version'          => 'aa_version'         // old AA_Stringexpand_Version'
    );


    /** replaces inline aliasses {(....)} with {var:...} references (which will be later expanded into {...}) and defines appropriate contentcache entries
     *  this alows to write expressions like
     *    {item:6625:{(some text {headline........}...etc.)}}
     */
    static function ApplyInlineAlias($text) {
        $last_replacements = 0;
        do {
            //   /{\(((?:.(?!{\())*)\)}/sU  - the expression is complicated because it
            //                                solves also nesting - like:
            //                                see {( some {( text )} which )} could {( be )} nested
            if (is_null($text = preg_replace_callback('/{\(((?:.(?!{\())*)\)}/sU', 'make_reference_callback', $text, -1, $last_replacements))) {  //s for newlines, U for nongreedy
                //huhl( "Error: preg_replace_callback", '/{\(((?:.(?!{\())*)\)}/sU', 'make_reference_callback', $text, -1, $last_replacements);
                echo "Error: preg_replace_callback - make_reference_callback";
                print_r($text);
                print_r($last_replacements);
            }
        } while($last_replacements);
        return $text;
    }

    /** called as AA::Stringexpander()->unalias();
     * @param $text
     * @param $item AA_Item
     * @param $remove string
     * @param $dequote bool
     * @param $itemview itemview
     * @return mixed|null|string|string[]
     */
    function unalias($text, $remove='', $item=null, $dequote=true, $itemview=null ) {

        if (++$this->recursion_count > 5000) {
            --$this->recursion_count;
            return "Error: recursion detected";
        }

        $GLOBALS['g_formpart'] = 0;  // used for splited inputform into parts

        // Note ereg was 15 seconds on one multi-line example cf .002 secs
        //    while (ereg("^(.*)[{]([^{}]+)[}](.*)$",$text,$vars)) {

        $quotecolons_partly = 0;
        // to speeedup the process we check, if we have to look for {} pairs
        if (strpos($text, '{') !== false) {
            // to speeedup the process we check, if we have to look for {( ... )} pairs
            if (strpos($text, '{(') !== false) {
                // replace all {(.....)} with {var:...}, which will be expanded into {...}
                // this alows to write expressions like
                //   {item:6625:{(some text {headline........}...etc.)}}
                $text = AA_Stringexpander::ApplyInlineAlias($text);
            }

            $last_replacements  = 0;
            AA::$debug&4 && AA::$dbg->tracestart('unalias', $text);
            $callback = array(new AA_Unalias_Callback($item, $itemview), AA::$debug&8 ? 'expand_bracketed_timedebug':'expand_bracketed');
            do {
                $oldtext = $text;
                if (is_null($text = preg_replace_callback('/[{]([^{}]+)[}]/s', $callback, $text, 1, $last_replacements))) {  //s for newlines, U for nongreedy
                    //huhl( "Error: preg_replace_callback", '/[{]([^{}]+)[}]/s', $callback, $text, -1, $last_replacements);
                    echo "Error ". preg_last_error().": preg_replace_callback - 2\n";
                    $pcre_const   = get_defined_constants(true);    // we can't use array dereference in php<=5.3: array_flip(get_defined_constants(true)['pcre']);
                    $pcre_err_arr = array_flip($pcre_const['pcre']);
                    echo $pcre_err_arr[preg_last_error()];
                    print_r($oldtext);
                    print_r($callback);
                    print_r($last_replacements);
                    echo ini_get('pcre.backtrack_limit');
                    echo "\n.\n";
                    echo ini_get('pcre.recursion_limit');
                    echo "\n.\n";
                    echo ini_get('pcre.jit');
                    $text = 'preg_replace_callback Error '.preg_last_error();
                }
                $quotecolons_partly += $last_replacements;
            } while($last_replacements);
            AA::$debug&4 && AA::$dbg->traceend('unalias', "$quotecolons_partly - $text");
        }

        if (is_object($item)) {
            $text = $item->substitute_alias_and_remove($text, strlen($remove) ? explode('##',$remove) : array());
        }

        // if ( !$dequote ) { }
        // there is no need to substitute on level 1

        --$this->recursion_count;

        // return from unalias - change all back to ':'
        if ( $dequote AND $quotecolons_partly ) {
            return DeQuoteColons($text); // = DequoteColons
        }

        return $text;
    }

    /** unaliasArray function
     * @param $arr
     * @param $remove
     * @param $item
     */
    function unaliasArray(&$arr) {
        if (is_array( $arr )) {
            foreach ( $arr as $k => $text ) {
                $arr[$k] = $this->unalias($text);
            }
        }
    }

    /** replacement functions / filters */
    function addPostpocess($key, $params='') {
        $this->postprocesess[$key] = $params;
    }

    /**
     * @param $require
     * @param string $type
     */
    function addRequire($require, $type='') {
        $this->requires->addRequire($require, $type);
    }

    /**
     * @return string
     */
    function getRequireHead() {
        return $this->requires->getHead();
    }

    /**
     * @return string
     */
    function getRequireFoot() {
        return $this->requires->getFoot();
    }

    /**
     * @param $out
     * @return mixed
     */
    function postprocess($out) {
        $search_arr  = array();
        $replace_arr = array();
        foreach ($this->postprocesess as $search => $replace) {
            switch ($search) {
                case '':
                    break;
                case '<!--AAGenerateHEAD-->':
                    $search_arr[]  = $search;
                    $replace_arr[] = "\n<!--AA-Generated-HEAD start-->\n".$this->getRequireHead(). "\n<!--/AA-Generated-HEAD end-->\n";
                    break;
                case '<!--AAGenerateFOOT-->':
                    $search_arr[]  = $search;
                    $replace_arr[] = "\n<!--AA-Generated-FOOT start-->\n".$this->getRequireFoot(). "\n<!--/AA-Generated-FOOT end-->\n";
                    break;
                default:
                    $search_arr[]  = $search;
                    $replace_arr[] = $replace;
            }
        }
        return count($search_arr) ? str_replace($search_arr, $replace_arr, $out) : $out;
    }

    /**
     * @param array $vars - slices or vars
     * @param $hash
     * @param $code
     */
    function setDependentParts($vars, $hash, $code) {
        foreach ($vars as $s) {
            if (isset($this->dependent_parts[$s])) {
                $this->dependent_parts[$s][] = array($hash,$code);
            } else {
                $this->dependent_parts[$s] = array(array($hash,$code));
            }
        }
    }

    /**
     * @param array $vars - slices or vars
     * @return array
     */
    function getDependentParts($vars) {
        $ret = array();
        foreach ($vars as $s) {
            if (is_array($this->dependent_parts[$s])) {
                foreach ($this->dependent_parts[$s] as $part) {
                    $ret[$part[0]] = $part[1];
                }
            }
        }
        return $ret;
    }
}

/** Base clas of requires. Could be useful for dependencies */
class AA_Req {
    /**
     *
     */
    const CODE_POSITION = 'HEAD-A';
    /**
     * @var array
     */
    protected $depend;
    /**
     * @var string
     */
    protected $integrity;

    /**
     * AA_Req constructor.
     * @param array $depend
     * @param string $integrity
     */
    function __construct($depend=array(), $integrity='') {
        $this->depend    = $depend;
        $this->integrity = $integrity;
    }

    /**
     * @return string
     */
    function getCodePosition()  { return static::CODE_POSITION; }

    /**
     * @return array
     */
    function getDependencies()  { return $this->depend; }

    /**
     * @return string
     */
    function getCode()          { return ''; }

    /**
     * @return string
     */
    function getIntegrity()     { return ($this->integrity ? " integrity=\"{$this->integrity}\" crossorigin=anonymous" : ''); }
}

/**
 * Class AA_Req_Lib
 */
class AA_Req_Lib extends AA_Req {
    /**
     *
     */
    const CODE_POSITION = 'HEAD-B';
    /**
     * @var
     */
    protected $url      = '';
    protected $loadtype = '';

    /**
     * AA_Req_Lib constructor.
     * @param $url
     * @param string $integrity
     * @param array $depend
     */
    function __construct($url, $integrity='', $depend=array()) {
        parent::__construct($depend, $integrity);
        if (strpos(trim($url),' ')) {
            $a = array_filter(explode(' ',$url, 2));
            $this->url      = $a[0];
            $this->loadtype = $a[1];
        } else {
            $this->url      = $url;
            $this->loadtype = '';
        }
    }

    /**
     * @return string
     */
    function getCode() {
        return "<script" .($this->loadtype ? " {$this->loadtype}" : '') ." src=\"{$this->url}\"" .$this->getIntegrity(). "></script>";
    }

}
/** {require:/css/myprintstyle.css print} */
class AA_Req_Css extends AA_Req {
    /**
     *
     */
    const CODE_POSITION = 'HEAD-A';
    /**
     * @var
     */
    protected $url;
    /**
     * @var string
     */
    protected $media;

    /**
     * AA_Req_Css constructor.
     * @param $url
     * @param string $integrity
     * @param array $depend
     */
    function __construct($url, $integrity='', $depend=array()) {
        parent::__construct($depend, $integrity);
        if (strpos(trim($url),' ')) {
            $a = array_filter(explode(' ',$url, 2));
            $this->url   = $a[0];
            $this->media = $a[1];
        } else {
            $this->url   = $url;
            $this->media = '';
        }
    }

    /**
     * @return string
     */
    function getCode() {
        return "<link rel=stylesheet type=\"text/css\"" .$this->getIntegrity(). " href=\"{$this->url}\"" .($this->media ? " media=\"{$this->media}\">" : '>');
    }
}

/**
 * Class AA_Req_Htm
 */
class AA_Req_Htm extends AA_Req {
    /**
     *
     */
    const CODE_POSITION = 'FOOT-A';
    /**
     * @var
     */
    protected $funct;

    /**
     * AA_Req_Htm constructor.
     * @param $funct
     * @param array $depend
     */
    function __construct($funct, $depend=array()) {
        $this->funct  = $funct;
        $this->depend = $depend;
    }

    /**
     * @return mixed|string
     */
    function getCode() {
        return call_user_func($this->funct);
    }
}




/**
 * Class AA_Req_Run
 */
class AA_Req_Run extends AA_Req {
    /**
     *
     */
    const CODE_POSITION = 'FOOT-B';
    /**
     * @var
     */
    protected $script;

    /**
     * AA_Req_Run constructor.
     * @param $script
     * @param array $depend
     */
    function __construct($script, $depend=array()) {
        $this->script  = $script;
        $this->depend  = $depend;
    }

    /**
     * @return string
     */
    function getCode() {
        return "<script> {$this->script} </script>";
    }
}

/**
 * Class AA_Req_Headcode
 */
class AA_Req_Headcode extends AA_Req_Run {
    /**
     *
     */
    const CODE_POSITION = 'HEAD-B';

    /**
     * @return string
     */
    function getCode() {
        return $this->script;
    }
}

/**
 * Class AA_Req_Footcode
 */
class AA_Req_Footcode extends AA_Req_Headcode {

    /**
     *
     */
    const CODE_POSITION = 'FOOT-B';
}

/**
 * Class AA_Req_Footcode
 */
class AA_Req_Footlib extends AA_Req_Lib {

    /**
     *
     */
    const CODE_POSITION = 'FOOT-A';
}

/**
 * Class AA_Req_Load
 */
class AA_Req_Load extends AA_Req_Run {
    /**
     * @return string
     */
    function getCode() {
        return "<script> document.addEventListener('DOMContentLoaded', function() { {$this->script} }); </script>";
    }
}

/**
 * Class AA_Requires
 */
class AA_Requires {
    /**
     * @var array
     */
    protected $reqs  = array();
    /**
     * @var array
     */
    protected $usrdefs  = array();
    /**
     * @var null
     */
    private   $_outputs = null;

    /**
     * @param $require
     * @param string $type
     */
    function addRequire($require, $type = '') {
        if ($type == 'AA_Req_Run') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Run');
        } elseif ($type == 'AA_Req_Load') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Load');
        } elseif ($type == 'AA_Req_Headcode') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Headcode');
        } elseif ($type == 'AA_Req_Footcode') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Footcode');
        } elseif ($type == 'AA_Req_Htm') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Htm');
        } elseif ((aa_substr($require,-4)=='.css') OR aa_strpos($require,'.css?') OR aa_strpos($require,'.css ')) {
            $hash = $this->addUsrRequire($require, 'AA_Req_Css');
        } elseif (aa_substr($require,-3)=='.js' OR (aa_strpos($require,'/')!==false)) {
            $hash = $this->addUsrRequire($require, 'AA_Req_Lib');
        } else {
            $hash = $require;   // must be system require
        }

        $this->_outputs    = null; // be sure it will be recomputed after adding
        $this->reqs[$hash] = 1;
    }

    /**
     * @param $require
     * @param $type
     * @return string
     */
    function addUsrRequire($require, $type) {
        $hash = get_hash($require);    // we expect it does not contain '|' character, which is used for alternatives in compute()
        $this->usrdefs = array($hash => new $type($require)) + $this->usrdefs;  // we want them in reverse order because of compute() function code
        return $hash;
    }

    /**
     * @param $position
     * @return string
     */
    protected function compute($position) {
        if (is_null($this->_outputs)) { // precomputed?
            $this->_outputs = array();
            $already_in     = array();

            $requires_definitions = $this->usrdefs + self::systemDefinitions();

            // we have to deal with different versions of library. Is some code needs specific version of the lib (jslib@1) we have to use it in all requires 'jslib'
            $exact_versions = array();
            foreach ($this->reqs as $code_id => $foo) {
                if (strpos($code_id,'@') !== false) {
                    $base_code_id = strtok($code_id,'@');
                    $exact_versions[$base_code_id] = $code_id;
                }
            }

            foreach ($requires_definitions as $code_id => $req) {
                $base_code_id = strtok($code_id,'@');
                // process if exact match or we do not care about version or this one is_a the right exact version
                if ($this->reqs[$code_id] OR ($this->reqs[$base_code_id] AND ( !isset($exact_versions[$base_code_id]) OR ($exact_versions[$base_code_id]==$code_id)))) {    // we are checking jquery@3 and then also jquery
                    if ($already_in[$base_code_id]) {
                        continue;
                    }
                    $already_in[$base_code_id] = true;
                    $this->_outputs[$req->getCodePosition()][] = $req->getCode();
                    // mark requires for this library
                    // the requires are ordered from particular script to global libraries in $requires_arr, so we can add depending libraries here without problem
                    $dep = $req->getDependencies();
                    foreach ($dep as $reqlib) {
                        if (strpos($reqlib,'|')!== false) {
                            $alternatives = explode('|',$reqlib);
                            foreach ($alternatives as $alt) {
                                if ($this->reqs[$alt]) {
                                    continue 2;
                                }
                            }
                            $reqlib = $alternatives[0];  // if not found in the already included, require the first
                        }
                        $this->reqs[$reqlib] = 1;
                    }
                }
            }
        }
        return $this->_outputs[$position] ? (join("\n", array_reverse($this->_outputs[$position]))."\n") : '';  // reverse - first should goes the libraries, then particular depending scripts
    }

    /**
     * @return string
     */
    function getHead() {
        return $this->compute('HEAD-A'). $this->compute('HEAD-B');
    }

    /**
     * @return string
     */
    function getFoot() {
        return $this->compute('FOOT-A'). $this->compute('FOOT-B');  // reverse - first should goes the libraries, then particular depending scripts
    }

    /**
     * @return array
     */
    static function systemDefinitions() {
        return array(
            //     <CDN URL>, <SRI hash - see srihash.org>, <requires - all of them must be below the line which do require in this list> example: array('jquery@3')
            // all versions updated 2018-08-07 HM
            'codemirror@5'               => new AA_Req(array('_codemirror_core@5', '_codemirror_htmlmixed@5', '_codemirror_xml@5', '_codemirror_css@5', '_codemirror_javascript@5', '_codemirror_matchbrackets@5', '_codemirror_matchtags@5', '_codemirror_xml-fold@5', '_css-codemirror@5','css-aa-system@1')),
            '_codemirror_htmlmixed@5'    => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/mode/htmlmixed/htmlmixed.js', 'sha256-YtBvhTDO4A27QPGmzVTybSqjmNNEi8Uc9KPvyytMoTA='),
            '_codemirror_xml@5'          => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/mode/xml/xml.js', 'sha256-Zz+CQg6Vpg4lnsAJtC7AqpIFO+OFRb0m/Zw7l9hx6nA='),
            '_codemirror_css@5'          => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/mode/css/css.js', 'sha256-A3dmy5MnEwoDZJmeJUoj98Al4uPPcF47typ3TSLhA0g='),
            '_codemirror_javascript@5'   => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/mode/javascript/javascript.js', 'sha256-mEBPfQj3tWr3WUpxoFSsaydJ/OPTvR8H/rW0X/VEcG0='),
            '_codemirror_matchbrackets@5'=> new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/addon/edit/matchbrackets.js', 'sha256-1ZBoXf1PnYzxJ7zY5+7ZAJbUh9xXYGUUXHn8r21LHWg='),
            '_codemirror_matchtags@5'    => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/addon/edit/matchtags.js', 'sha256-aKA2BMUxAIY3kNKzmYhxUFMCiNL/s/qF3zIxvROsnm8='),
            '_codemirror_xml-fold@5'     => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/addon/fold/xml-fold.js', 'sha256-0NR53Omilqa/gGNsbd6g0IjppcgdSabwMu8jOTk6vFE='),
            '_codemirror_core@5'         => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/lib/codemirror.js', 'sha256-dlU448ajDtrzPvUfqkvdw6yT52NgWy0UmxUgclvPEAc=', array('css-aa-system@1','_codemirror_htmlmixed@5', '_codemirror_xml@5', '_codemirror_css@5', '_codemirror_javascript@5', '_codemirror_matchbrackets@5', '_codemirror_matchtags@5', '_codemirror_xml-fold@5', '_css-codemirror@5')),
            'ace@1'                      => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/ace-builds@1.4.0/src-noconflict/ace.js', 'sha256-YpEMJafqo1GM1gdYZ8o7AlzowvKpgjxtm4ALyij/E04=',array('jquery@3')),
            'tableexport@5'              => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/tableexport@5.0.2/dist/js/tableexport.min.js', 'sha256-xqLIIDLeHPe6Mmnksf1sHPC+jxuS4wKCmLrj/4sKDTM=', array('_css-tableexport@5','filesaver@1','js-xlsx@0')),
            'tableexport@4'              => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/tableexport@4.0.11/dist/js/tableexport.min.js', 'sha256-3njWp77R/1aA2AeJg4mxgOHejH9S5YodN/b28OzA35c=', array('_css-tableexport@4','filesaver@1','js-xlsx@0')),
            'js-xlsx@0'                  => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/xlsx@0.13.3/dist/xlsx.core.min.js', 'sha256-9y2mRIiL7T2ufFN4OF1Q6kgDNk9Q1rtMRl1WUp/VQEk='),
            'filesaver@1'                => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/file-saver@1.3.8/FileSaver.min.js', 'sha256-FPJJt8nA+xL4RU6/gsriA8p8xAeLGatoyTjldvQKGdE='),
            'leaflet@1'                  => new AA_Req_Lib('https://unpkg.com/leaflet@1.3.3/dist/leaflet.js', 'sha512-tAGcCfR4Sc5ZP5ZoVz0quoZDYX5aCtEm/eu1KhSLj2c9eFrylXZknQYmxUssFaVJKvvc0dJQixhGjG2yXWiV9Q==', array('_css-leaflet@1')),
            'chartjs@2'                  => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/chart.js@2.7.2/dist/Chart.min.js', 'sha256-CfcERD4Ov4+lKbWbYqXD6aFM9M51gN4GUEtDhkWABMo='),
            'dropzone@5'                 => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/dropzone@5.5.1/dist/min/dropzone.min.js', 'sha256-cs4thShDfjkqFGk5s2Lxj35sgSRr4MRcyccmi0WKqCM=', array('_css-dropzone@5')),
            //'select2@4'                  => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/select2@4.0.5/dist/js/select2.min.js', 'sha256-FA14tBI8v+/1BtcH9XtJpcNbComBEpdawUZA6BPXRVw=', array('jquery@3','_css-select2@4')),
            'select2@3'                  => new AA_Req_Lib('https://cdnjs.cloudflare.com/ajax/libs/select2/3.5.4/select2.min.js', 'sha256-7A2MDY2eGSSUvgfbuH1IdzYk8qkEd3uzwiXADqPDdtY=', array('jquery@3','_css-select2@3')),
            'select2@4'                  => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/select2@4.0.5/dist/js/select2.full.min.js', 'sha256-FcVIknBiVRk5KLQeIBb9VQdtFRMqwffXyZ+D8q0gQro=', array('jquery@3','_css-select2@4')),
            'lightgallery@1'             => new AA_Req_Footlib('https://cdn.jsdelivr.net/lightgallery.js/1.0.1/js/lightgallery.min.js', 'sha384-HZ/iOnkYJdO5/U2ug7IPTgNZq61Jr+SMoJGCWsjVjNqwY/77YVNA/mZQ1tdfo2Xm', array('_css-lightgallery@1')), // v 1.1.1 do nt work
            'tippy@2'                    => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/tippy.js@2.5.4/dist/tippy.all.min.js', 'sha256-HdIQOX+k0mj4UNWUFtyyCW+QecRRwFwr5vMVIMReANU='),
            'aa-tools@1'                 => new AA_Req(array('css-aa-system@1','aa-jslib@3|aa-jslib@1','htm-aa-system@1')),
            'ckeditor@4'                 => new AA_Req_Footlib(get_aa_url('misc/htmleditor/ckeditor.js','',false),'',array('css-aa-system@1','aa-jslib@3|aa-jslib@2|aa-jslib@1')),
            'aa-constedit@1'             => new AA_Req_Lib(get_aa_url('javascript/constedit.min.js?v='.AA_JS_VERSION,'',false)),
            'aa-jslib@3'                 => new AA_Req_Lib(get_aa_url('javascript/aajslib-jquery.min.js?v='.AA_JS_VERSION,'',false),'',array('jquery@3')),
            'aa-jslib@2'                 => new AA_Req_Lib(get_aa_url('javascript/aajslib-jquery.php?v='.AA_JS_VERSION,'',false),'',array('jquery@3')),
            'aa-jslib@1'                 => new AA_Req_Lib(get_aa_url('javascript/aajslib.php?v='.AA_JS_VERSION,'',false)),
            'jquery-ui@1'                => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.12.1/jquery-ui.min.js', 'sha256-KM512VNnjElC30ehFwehXjx1YCHPiQkOPmqnrWtpccM=', array('jquery@3', '_css-jquery-ui@1')),
            'jquery@3'                   => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/jquery@3.3.1/dist/jquery.min.js', 'sha256-FgpCb/KJQlLNfOu91ta32o/NMZxltwRo8QtmkMRdAu8='),
            'vue@2'                      => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/vue@2.5.17/dist/vue.min.js', 'sha256-FtWfRI+thWlNz2sB3SJbwKx5PgMyKIVgwHCTwa3biXc='),
            'vue-test@2'                 => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/vue@2.5.17/dist/vue.js', 'sha256-3kjowa0CUPPK4W+yaGpNP1exjs8gbX2SSsToEMd06K8='),
            '_css-jquery-ui@1'           => new AA_Req_Css('https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.12.1/jquery-ui.min.css', 'sha256-rByPlHULObEjJ6XQxW/flG2r+22R5dKiAoef+aXWfik='),
            '_css-tableexport@5'         => new AA_Req_Css('https://cdn.jsdelivr.net/npm/tableexport@5.0.2/dist/css/tableexport.min.css', 'sha256-8Z/QCFUNM+0PNTogk3GFXDxdYOwGvpK4CQffKN4aJk0='),
            '_css-tableexport@4'         => new AA_Req_Css('https://cdn.jsdelivr.net/npm/tableexport@4.0.11/dist/css/tableexport.min.css', 'sha256-nopPKGGZlhhMl96BF6etnX8zuKX2NPJbPJqZKXLtLMk='),
            '_css-dropzone@5'            => new AA_Req_Css('https://cdn.jsdelivr.net/npm/dropzone@5.5.1/dist/min/dropzone.min.css', 'sha256-e47xOkXs1JXFbjjpoRr1/LhVcqSzRmGmPqsrUQeVs+g='),
            '_css-select2@3'             => new AA_Req_Css('https://cdnjs.cloudflare.com/ajax/libs/select2/3.5.4/select2.min.css', 'sha256-ijlUKKj3hJCiiT2HWo1kqkI79NTEYpzOsw5Rs3k42dI='),
            '_css-select2@4'             => new AA_Req_Css('https://cdn.jsdelivr.net/npm/select2@4.0.5/dist/css/select2.min.css', 'sha256-xJOZHfpxLR/uhh1BwYFS5fhmOAdIRQaiOul5F/b7v3s='),
            '_css-lightgallery@1'        => new AA_Req_Css('https://cdn.jsdelivr.net/lightgallery.js/1.0.1/css/lightgallery.css', 'sha384-25xBlknDxvI3NBoV1cKi/biQFCWkN5+E3fcRiLLKQIGSMp9IBDfQiwFiFsTYdosz'),
            '_css-leaflet@1'             => new AA_Req_Css('https://unpkg.com/leaflet@1.3.3/dist/leaflet.css', 'sha512-Rksm5RenBEKSKFjgI3a41vrjkw4EVPlJ3+OiI65vTjIdo9brlAacEuKOiQ5OFh7cOI1bkDwLqdLw3Zg0cRJAAQ=='),
            'css-aa-system@1'            => new AA_Req_Css(get_aa_url('css/aa-system.css?v='.AA_JS_VERSION,'',false))  ,
            'css-normalize@8'            => new AA_Req_Css('https://cdn.jsdelivr.net/npm/normalize.css@8.0.0/normalize.css', 'sha256-7GAtDQ79wTEOjhBKf70uBQG7A5yyb+8rigu07atXWDY='),
            'css-normalize@7'            => new AA_Req_Css('https://cdn.jsdelivr.net/normalize/7.0.0/normalize.css', 'sha384-Y+IEavQyTjgXgt7TL5nxVHI/DEuF6yoysy50puRyQDKmjfOZjY7+QvoRelSV4XvZ'),
            'css-fontawesome@4'          => new AA_Req_Css('https://cdn.jsdelivr.net/fontawesome/4.7.0/css/font-awesome.min.css', 'sha256-eZrrJcwDc/3uDhsdt61sL2oOBY362qM3lon1gyExkL0='),
            '_css-codemirror@5'          => new AA_Req_Css('https://cdn.jsdelivr.net/npm/codemirror@5.39.2/lib/codemirror.css', 'sha256-o/v/5fOniPHMAww4EAIfRBFV0SeoqksPrY0Yq5x+wSM='),
            'htm-aa-system@1'            => new AA_Req_Htm('AA_Tools::getBottomCode')
        );
    }

    static function getAvailableLibs() {
        $ret['head'] = array('require',_m('version'));
        $defs = self::systemDefinitions();
        foreach ($defs as $name => $def) {
            if ($name{0} !== '_') {
                $base = strtok($name, '@');
                if (!$ret[$base]) {
                    $ret[$base] = array($base, '');
                }
                $ret[$base][1] .= "<div>$name</div>";
            }
        }
        return $ret;
    }

}

/** @todo - move it to special file */
class AA_Tools {
    /**
     * @return string
     */
    static function getBottomCode() {
        if (AA::$site_id AND ($site = AA_Module_Site::getModule(AA::$site_id))) {
            $ret  = '<div class="aa-tools-switch" onclick="document.getElementsByTagName(\'body\')[0].classList.toggle(\'aa-page\')"></div><aside class="aa-tools">';
            $ret .= AA::Stringexpander()->unalias($site->getProperty('loginpage_code'));
            $ret .= '</aside>';
            return $ret;
        }
        return '';
    }
}


/**
 * Class AA_Stringexpand
 */
class AA_Stringexpand {

    /**
     * @var AA_Item - item, for which we are stringexpanding (Not used in many expand functions)
     */
    var $item;

    /**
     * @var itemview - view, in which we are stringexpanding (Not used in many expand functions)
     */
    var $itemview;

    /** AA_Stringexpand function
     * @param $item
     */
    function __construct($param) {
        $this->item     = $param['item'];
        $this->itemview = $param['itemview'];
    }

    /** @deprecated - use AA::Stringexpander()->unalias() directly
     * @param          $text
     * @param string   $remove
     * @param null     $item
     * @param bool     $dequote
     * @param itemview $itemview
     * @return mixed|null|string|string[]
     */
    function unalias($text, $remove='', $item=null, $dequote=true, $itemview=null ) {
        return AA::Stringexpander()->unalias($text, $remove, $item, $dequote, $itemview);
    }

    /** expand function
     * @return string
     */
    function expand() {
        return '';
    }

    /**
     * @param $params
     * @return array
     */
    static function parseParam($params) {
        if (empty($params)) {
            return array();
        }
        return array_map(static::doTrimParams() ? 'DeQuoteColonsTrim' : 'DeQuoteColons', ParamExplode($params));
    }

    /** additionalCacheParam function
     *  Some stringexpand functions uses global parameters, so it is not posible
     *  to use cache for results based just on expand() parameters. We need to
     *  add following parameters. In most cases you do not need to override this
     *  function
     */
    function additionalCacheParam() {
        return '';
    }

    /**
     * @return bool
     */
    function doCache() {
        return true;
    }

    /** Marks rare cases, when we do not want to qoute results - like for
     *  {_:...} shortcuts
     */
    function doQuoteColons() {
        return true;
    }

    /** Trim all parameters? */
    function doTrimParams() {
        return true;
    }

    /** replace parameters _#P1, _#P2, ... by the supplied ones */
    function replaceParams($text, $arg_list) {
        if (count($arg_list)>0) {
            $trans = array();
            foreach($arg_list as $key => $param) {
                // param is dequoted, but we need escape colons, here - the result is passed back to AA_Stringexpand
                $trans['_#P'.($key+1)] = QuoteColons($param);
            }
            $text = strtr($text, $trans);
        }
        return $text;
    }
}

/** Special parent class for all stringexpand functions, where no cache
 *  is needed (probably very easy functions)
 */
class AA_Stringexpand_Nevercache extends AA_Stringexpand {
    /**
     * @return bool
     */
    function doCache() {
        return false;
    }
}

/** unaliases the text - replaces {views} and other constructs */
class AA_Stringexpand_Expand extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $number
     * @return mixed|null|string|string[]
     */
    function expand($string='') {
        $item   = $this ? $this->item : null;
        return AA::Stringexpander()->unalias($string,'',$item);
    }
}


/** trims whitespaces form begin and end of the string */
class AA_Stringexpand_Trim extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($chars could contain space) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /**
     * @param string $string
     * @param string $chars
     * @return string|void
     */
    function expand($string='', $chars='') {
        if (empty($chars)) {
            $chars = " \t\n\r\0\x0B\xA0";  // standard + chr(160) - hard space
        }
        return trim($string, $chars);
    }
}

/** replaces string or strings - you can use single string replacement
 *  or array in JSON form:
 *   {str_replace:uno:one:text with uno inside}
 *   {str_replace:["�","�","�"]:["c","s","r"]:text �esky with accents}
 */
class AA_Stringexpand_Str_replace extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($search and $replace could be spaces) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /**
     * @param string $search
     * @param string $replace
     * @param string $text
     * @return mixed|void
     */
    function expand($search='', $replace='', $text='') {
        $search  = json2arr($search,true);
        $replace =  ($replace[0] == '[') ? json2arr($replace,true) : $replace; // the replace could be string (which then replaces all the occurences of $searches)
        return str_replace($search, $replace, $text);
    }
}

/** replaces string with REGEXP
 *      {replace:<!-- .* -->::{full_text.......}}
 *   The ussage is as PHP preg_replace, but we do not allow you to specify
 *   delimiters and modifiers because of dangerous /e modifier
 */
class AA_Stringexpand_Replace extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($search and $replace could be spaces) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /**
     * @param string $search
     * @param string $replace
     * @param string $text
     * @return null|string|string[]|void
     */
    function expand($search='', $replace='', $text='') {
        //$search  = json2arr($search,true);
        //$replace = json2arr($replace,true);
        return preg_replace('`'.str_replace('`','\`',$search).'`', $replace, $text);
    }
}

/** max value
 *  Accepts two forms of parameters:
 *     {max:12:45:8}
 *     {max:[12,45,8]} - JSON form
 */
class AA_Stringexpand_Max extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /**
     * @return mixed|string|void
     */
    function expand() {
        return self::_get_result('max', func_get_args());
    }

    /**
     * @param $func
     * @param $arg_list
     * @return mixed|string
     */
    function _get_result($func, $arg_list) {
        switch (count($arg_list)) {
            case 0: return '';
            case 1: $values = json2arr($arg_list[0]); // can't be inside empty()  - Honza, php 5.2
                return (count($values)<2) ? $values[0] : call_user_func_array($func, $values);
        }
        return call_user_func_array($func, $arg_list);
    }
}

/** min value
 *  Accepts two forms of parameters:
 *     {min:12:45:8}
 *     {min:[12,45,8]} - JSON form
 */
class AA_Stringexpand_Min extends AA_Stringexpand_Max {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /**
     * @return mixed|string|void
     */
    function expand() {
        return self::_get_result('min', func_get_args());
    }
}


/**
 * Class AA_Stringexpand_Packid
 */
class AA_Stringexpand_Packid extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $unpacked_id
     * @return string
     */
    function expand($unpacked_id='') {
        if ($unpacked_id) {// Note was + instead {32}
            return ((string)$unpacked_id == "0" ? "0" : pack("H*",$unpacked_id));
        }
    }
}

/** Displays hit statistics for the items */
class AA_Stringexpand_Hitcounter extends AA_Stringexpand {

    /** expand function
     * @param $type string type of statistics - currently only "days" statistics is implemented
     * @param $ids  string item ids (long or short) for which you want to display statistics
     *
     * Example: {hitcounter:days:24457-24474}
     * Example: {hitcounter:days:{ids:76f59b2023b8a4e8d6c57831ef8c8199:d-publish_date....->-1185919200}}
     * @return string
     */
    function expand($type='', $ids='') {
        $ret = '';
        switch ($type) {
            case 'days':      $group_string = 'Y-m-d'; break;
            case 'dayhours':  $group_string = 'H';     break;
            case 'weeks':     $group_string = 'o-W';   break;
            case 'weekdays':  $group_string = 'N-D';     break;
            case 'months':    $group_string = 'Y-m';   break;
            case 'years':     $group_string = 'Y';     break;
            default:          return '';
        }
        $zids   = new zids(explode_ids($ids));
        $s_zids = new zids($zids->shortids(), 's');
        $hits   = GetTable2Array('SELECT id, time, hits FROM hit_archive WHERE '. $s_zids->sqlin('id'), '');
        $stat   = array();
        foreach ($hits as $hit) {
            $day        = date($group_string, $hit['time']);
            if ( !isset($stat[$day]) ) {
                $stat[$day] = array();
            }
            $stat[$day][$hit['id']] = isset($stat[$day][$hit['id']]) ? $stat[$day][$hit['id']] + $hit['hits'] : $hit['hits'];
        }
        if (count($stat) > 0) {
            $s_ids =  $s_zids->shortids();
            // table header
            $ret   = "<table>\n  <tr>\n    <th>"._m('Date \ Item ID')."</th>";
            foreach ($s_ids as $sid) {
                $ret .= "\n    <th>$sid</th>";
            }
            $ret   .= "\n  </tr>";

            ksort($stat);
            foreach ( $stat as $day => $counts ) {
                $ret .= "\n  <tr>\n    <td>$day</td>";
                foreach ($s_ids as $sid) {
                    $ret .= "\n    <td>".(isset($counts[$sid]) ? $counts[$sid] : '0') ."</td>";
                }
                $ret .= "\n  </tr>";
            }
            $ret .= "\n</table>";
        }
        return $ret;
    }
}

/** @return string - avatar img or colored div with initials
 *  {avatar:<img_url>:[<person_name>]:[<avatar-size>]}
 *  Ussage:
 *     <div class="dis-avatar">{avatar:{img_url.........}:{_#HEADLINE}}</div>
 **/
class AA_Stringexpand_Avatar extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function
     * @param $image - image url
     * @param $phpthumb_params - parameters as you would put to url for phpThumb
     *                           see http://phpthumb.sourceforge.net/demo/demo/phpThumb.demo.demo.php
     * @return mixed|null|string|string[]
     */
    function expand($image='', $name='', $size='') {
        $size  = get_if($size, 48);
        $title = (strpos($name,'@')===false) ? $name : aa_substr($name,0,aa_strpos($name,'@'));

        if ($img = AA::Stringexpander()->unalias("{img:$image:w=$size&h=$size&iar=1:imgb:$title}")) {
            return $img;
        }
        $nplus    = $title . '--';
        $second   = strcspn($title,' -_.');
        $initials = $nplus[0].$nplus[strlen($title)==$second ? 1 : $second+1];
        $color    = (crc32($name) % 8) + 1;
        return "<div class=\"dis-color$color\" title=\"$title\">$initials</div>";
    }
}




/** Creates link to modified image using phpThub
 *  {img:<url>:[<phpthumb_params>]:[<info>]:[<param1>]:[<param2>]}
 *
 *  Ussage:
 *     {img:_#this:w=168&h=127&zc=1:im:_#HEADLINE:width=168 height=127}  // this is the quickest - exact size (zc=1), no need to size check => im
 *     <img src="{img:{img_url.........}:w=150&h=150}">  /"
 *     <img src="{img:{img_url.........}:w=150&h=150&iar=1}">  /"
 *     <div>{img:{img_url.........}::imgb:Logo {_#HEADLINE}}</div>
 *     <div>{img:{img_url.........}:w=300:imgb:Logo {_#HEADLINE}:class="big"}</div>
 *
 *  for phpThumb params see http://phpthumb.sourceforge.net/demo/demo/phpThumb.demo.demo.php
 *  (phpThumb library is the part of AA)
 **/
class AA_Stringexpand_Img extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function
     * @param $image - image url
     * @param $phpthumb_params - parameters as you would put to url for phpThumb
     *                           see http://phpthumb.sourceforge.net/demo/demo/phpThumb.demo.demo.php
     * @return mixed|string
     */
    function expand($image='', $phpthumb_params='', $info='', $param1='', $param2='') {

        //AA::$debug&2 && AA::$dbg->info('AA_Stringexpand_Img0', $image, $phpthumb_params, $info, $param1, $param2);

        if (!$image) {
            return '';
        }
        list($img_url,$img_short) = AA_Stringexpand_Img::_getUrl($image, $phpthumb_params);

        if (empty($info) OR ($info == 'url') OR empty($img_url)) {
            return $img_short;
        }
        if ($info == 'im') {
            return "<img src=\"$img_short\" $param2 border=0". (($param1 = safe(strip_tags($param1))) ? " title=\"$param1\" alt=\"$param1\" />" : ' />');
        }

        $a = @getimagesize(str_replace('&amp;', '&', $img_url));
        if (! $a) {
            return '';
        }

        // No warning required, will be generated by getimagesize
        switch ( $info ) {
            case 'width':   return $a[0];
            case 'height':  return $a[1];
            case 'imgtype': return $a[2]; // 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM
            case 'mime':    return image_type_to_mime_type($a[2]);
            case 'html':
            case 'hw':      return $a[3]; //height="xxx" width="yyy"
            //case 'im':      quick version - img without width and height - see above
            case 'imgb':    $param2 .= ' border="0"';  // no break!!!
            case 'img':     $param1 = safe(strip_tags($param1));
                $alt = $param1 ? " title=\"$param1\" alt=\"$param1\"" : '';
                return "<img src=\"$img_short\" ". $a[3] ." $alt $param2 />";
        }
        return '';
    }

    /**
     * @param $image
     * @param $phpthumb_params
     * @return array
     */
    function _getUrl($image, $phpthumb_params) {
        if (empty($phpthumb_params)) {
            return array($image,$image);
        }
        // separate parameters
        if (strpos($phpthumb_params, '&amp;') === false) {
            $phpthumb_params = str_replace('&', '&amp;', $phpthumb_params);
        }

        // it is much better for phpThumb to access the files as files relative
        // to the directory, than using http access
        if (AA_HTTP_DOMAIN !== "/") {
            $image = str_replace(AA_HTTP_DOMAIN, '', $image);
        }
        if (aa_substr($image,0,4)=="http") {
            $image = preg_replace("~http(s?)://(www\.)?(.+)\.([a-z]{1,6})/(.+)~", "\\5", $image);
        }

        return array( AA_INSTAL_URL. "img.php?src=/$image&amp;$phpthumb_params", AA_INSTAL_PATH. "img.php?src=/$image&amp;$phpthumb_params");
    }
}

/** Creates image with the specified text:
 *  {imgtext:<width>:<height>:<text>:<size>:<alignment>:<color>:<font>:<opacity>:<margin>:<angle>:<background>:<bg_opacity>}
 *
 *  Ussage:
 *    {imgtext:20:210:My picture text:3:TL:000000::::90}
 *    - returns white 20 x 210px big image with vertical, top-left positioned black text on it
 *
 *  for phpThumb params see http://phpthumb.sourceforge.net/demo/demo/phpThumb.demo.demo.php
 *  (phpThumb library is the part of AA)
 **/
class AA_Stringexpand_Imgtext extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($text coul contain spaces at the begin) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $phpthumb_params - parameters as you would put to url for phpThumb
     *                           see http://phpthumb.sourceforge.net/demo/demo/phpThumb.demo.demo.php
     *   <s> is the font size (1-5 for built-in font, or point size for TrueType fonts);
     *   <a> is the alignment (one of BR, BL, TR, TL, C, R, L,
     *       T, B, * where B=bottom, T=top, L=left, R=right,
     *       C=centre, *=tile);
     *       note: * does not work for built-in font "wmt"
     *       *or*
     *       an absolute position in pixels (from top-left
     *       corner of canvas to top-left corner of overlay)
     *       in format {xoffset}x{yoffset} (eg: "10x20")
     *   <c> is the hex color of the text;
     *   <f> is the filename of the TTF file (optional, if
     *       omitted a built-in font will be used);
     *   <o> is opacity from 0 (transparent) to 100 (opaque)
     *       (requires PHP v4.3.2, otherwise 100% opaque);
     *   <m> is the edge (and inter-tile) margin in percent;
     *   <n> is the angle
     *   <b> is the hex color of the background;
     *   <O> is background opacity from 0 (transparent) to
     *       100 (opaque)
     *       (requires PHP v4.3.2, otherwise 100% opaque);
     *   <x> is the direction(s) in which the background is
     *       extended (either 'x' or 'y' (or both, but both
     *       will obscure entire image))
     *       Note: works with TTF fonts only, not built-in
     * @return string
     */
    function expand($width='', $height="", $text="", $size='', $alignment='', $color='', $font='', $opacity='', $margin='', $angle='', $background='', $bg_opacity='') {
        if (!$width OR !$height OR !strlen(trim($text))) {
            return '';
        }
        $txt        = urlencode($text);
        $bg         = (strlen($background) ? $background : 'FFFFFF') .'|'. (strlen($bg_opacity) ? $bg_opacity : '0');
//        $color      = (strlen($color) ? $color : '000000');
        $param      = join('|',array($txt, $size, $alignment, $color, $font, $opacity, $margin, $angle, $background, $bg_opacity));
        $img_url    = AA_INSTAL_PATH. "img.php?new=$bg&amp;w=$width&amp;h=$height&amp;fltr[]=wmt|$param&amp;f=png";
        return "<img src=\"$img_url\" width=\"$width\" height=\"$height\" alt=\"$text\" border=\"0\"/>";
    }
}

/** get parameters (size or type) from the file
 *  {fileinfo:<url>:<info>}
 *
 *  Ussage:
 *     {fileinfo:{file............}:size}  - returns size of the file
 *
 *  @author Adam Sanchez
 **/
class AA_Stringexpand_Fileinfo extends AA_Stringexpand {

    /**
     * @param string $url
     * @param string $info
     * @return int|string|void
     */
    function expand($url='', $info='') {

        switch ( $info ) {
            case 'type':
                $url2array = explode(".",basename(parse_url($url, PHP_URL_PATH)));
                $part = count($url2array)-1;
                return ($part>0) ? $url2array[$part] : 'TXT';
            case 'name':
                return basename(parse_url($url, PHP_URL_PATH));
            case 'size':
                $filename = str_replace(IMG_UPLOAD_URL, IMG_UPLOAD_PATH, $url);
                if (is_file($filename)) {
                    $size    = @filesize($filename);
                    $size_kb = round($size/1024, 1);
                    $size_mb = round($size/1048576, 1);
                    $size    = ($size <= 1048576) ? $size_kb." kB" : $size_mb." MB";
                    return $size;
                }
                break;
            case 'link':
                return StrExpand('AA_Stringexpand_Filelink', array($url));
            case 'typesize':
                $fileinfo = join('&nbsp;-&nbsp;', array(StrExpand('AA_Stringexpand_Fileinfo', array($url,'type')), StrExpand('AA_Stringexpand_Fileinfo', array($url,'size'))));
                return $fileinfo ? "&nbsp;<span class=\"fileinfo\"> [$fileinfo]</span>" : '';
            case 'crc':
                $filename = str_replace(IMG_UPLOAD_URL, IMG_UPLOAD_PATH, $url);
                if (is_file($filename)) {
                    return  @hash_file('crc32',$filename);
                }
                break;
            case 'time':
                $filename = str_replace(IMG_UPLOAD_URL, IMG_UPLOAD_PATH, $url);
                if (is_file($filename)) {
                    return  @filemtime($filename);
                }
                break;
        }
        return '';
    }
}

/** get link to file for download (prints also file size and type)
 *  {filelink:<url>:<text>}
 *
 *  Ussage:
 *     {filelink:{file............}:{text............}:Download#: }
 *     returns: <a href="http://..." title="Document [PDF - 157 kB]">Document</a> [PDF - 157 kB]
 **/
class AA_Stringexpand_Filelink extends AA_Stringexpand {
    /** Do not trim all parameters ($text_before could have space at the end) */
    function doTrimParams() { return false; }

    /**
     * @param string $url
     * @param string $text
     * @param string $text_before
     * @return string|void
     */
    function expand($url='', $text='', $text_before='') {
        if (empty($url)) {
            return '';
        }
        $filename = $text ? $text : basename(parse_url($url, PHP_URL_PATH));
        $fileinfo = join(' - ', array(StrExpand('AA_Stringexpand_Fileinfo', array($url,'type')), StrExpand('AA_Stringexpand_Fileinfo', array($url,'size'))));
        $fileinfo = $fileinfo ? " [$fileinfo]" : '';
        $fielinfo_htm = $fileinfo ? "&nbsp;<span class=\"fileinfo\">". str_replace(' ','&nbsp;', $fileinfo).'</span>' : '';

        return "$text_before<a href=\"$url\" title=\"$filename$fileinfo\">$filename</a>$fielinfo_htm";
    }
}

/** manages alerts subscriptions
 *  The idea is, that this alias will manage all the alerts subscriptions on the
 *  page - you just put the {alerts:<alert_module_id>:<some other parameter>}
 *  construct on the page, and it displays the form for subscriptions, managing
 *  user profile, unsubscribe users and confirm e-mails.
 *  At this moment it is just start - it should unsubscribe users and confirm
 *  e-mails when added to the page
 */
class AA_Stringexpand_Alerts  extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $module_id - alerts module id
     * @return string
     */
    function expand($module_id='') {
        require_once AA_BASE_PATH."modules/alerts/util.php3";

        // we need just reader slice id
        $collectionprop = GetCollection($module_id);

        if (!$collectionprop) {
            return '';
        }
        $reader_slice_id = $collectionprop['slice_id'];
        if ($_GET["aw"]) {
            if (confirm_email($reader_slice_id, $_GET["aw"])) {
                return '<div class="aa-ok">'._m('E-mail confirmed.').'</div>';  // @todo get messages from alerts module
            }
        }
        if ($_GET["au"]) {
            if (unsubscribe_reader($reader_slice_id, $_GET["au"], $_GET["c"])) {
                return '<div class="aa-ok">'._m('E-mail unsubscribed').'</div>';  // @todo get messages from alerts module
            }
        }
        return '';
    }
}

/** Adds supplied slice password to the list of known passwords for the page,
 *  so you can display the content of the protected slice
 *  It is usefull for site module, when you need to display protected content
 *  Experimental
 *  Ussage: {credentials:ThisIsThePassword}
 */
class AA_Stringexpand_Credentials extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)

    /** expand function
     */
    function expand($slice_pwd='', $slice_id='') {
        $credentials = AA_Credentials::singleton();
        $credentials->register(AA_Credentials::encrypt($slice_pwd));
        return '';
    }
}

/** @return string - url GET parameter - {qs[:<varname>[:delimiter]]}
 *  Ussage:
 *   {qs:surname}    - returns Havel for http://example.org/cz/page?surname=Havel
 *   {qs}            - returns whole querystring (including GET and POST variables)
 *   {qs:aa[n1000_3130303132312d726d2d7361736f762d][con_email_______][]}
 *                   - returns the value of the variable - exactly as posted
 */
class AA_Stringexpand_Qs extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)

    /** Do not trim all parameters ($delimiter could be space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $ids_string
     * @param $expression
     * @param $delimeter
     * @return array|string
     */
    function expand($variable_name='', $delimiter=null) {
        if (empty($variable_name)) {
            return shtml_query_string();
        }
        $ret = '';
        if (strpos($variable_name,'[')!==false) {
            $qstring = urldecode(shtml_query_string());
            if (strpos($qstring, $variable_name) !== false) {
                $qarr = explode('&', $qstring);
                foreach ($qarr as $vardef) {
                    list($var,$val) = explode('=',$vardef);
                    if ($var == $variable_name) {
                        $ret = urldecode($val);
                        break;
                    }
                }
            }
        } elseif (isset($_REQUEST[$variable_name])) {
            $ret = $_REQUEST[$variable_name];
        } elseif ( $_SERVER['CONTENT_TYPE'] == 'application/json' ) {
            $req_body = json_decode(file_get_contents('php://input'), true);
            $ret      = $req_body[$variable_name];
        } else {
            $shtml_get = add_vars('', 'return');
            $ret = $shtml_get[$variable_name];
        }
        return !is_array($ret) ? $ret : ( is_null($delimiter) ? json_encode($ret) : join($delimiter, $ret));
    }
}


/** @return string - url GET parameter htlmencoded - {qss[:<varname>[:delimiter]]}
 *  equivalent to {qss:} {safe:{qs:...}}
 *  use it all the time you are printing user input (url parameters) to HTML page - it prevents XSS attacks
 *  Ussage:
 *   {qss:surname}    - returns &lt;Havel&gt; for http://example.org/cz/page?surname=<Havel>
 *   {qss}            - returns whole querystring (including GET and POST variables) html encoded
 *   {qss:aa[n1000_3130303132312d726d2d7361736f762d][con_email_______][]}
 *                   - returns the value of the variable - exactly as posted - html encoded
 */
class AA_Stringexpand_Qss extends AA_Stringexpand_Qs {

    function expand($variable_name='', $delimiter=null) {
        return myspecialchars(call_user_func_array('parent::' . __FUNCTION__, func_get_args()),false);
    }
}


/** Returns actual server load
 *  Ussage: {serverload}
 */
class AA_Stringexpand_Serverload extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    /** expand function
     */
    function expand() {
        $load = sys_getloadavg();
        return $load[0];
    }
}


/** @return string - random string of given length
 *  (for more advanced version see default_fnc_rnd)
 *  Ussage: {randomstring:5}
 */
class AA_Stringexpand_Randomstring extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    /**
     * @param string $len
     * @return string|void
     */
    function expand($len='') {
        $ret = '';
        $salt_chars = "abcdefghijklmnoprstuvwxABCDEFGHIJKLMNOPQRSTUVWX0123456789";
        for ($i=0; $i < $len; $i++) {
            $ret .= $salt_chars[mt_rand(0,56)];
        }
        return $ret;
    }
}

if (defined('STRINGEXPAND_INC')) {
    require_once AA_INC_PATH.'custom/'.STRINGEXPAND_INC. '/stringexpand.php';
}


/** This is start of new {_:Shortcut:param} syntax, which you will be able to
 *  define through admin interface, just like other aliases
 *  It allows you to pass parameters to such "aliases".
 *  It will also allow you to hide view ids in templates
 *  @todo:  - add field for permissions to check before evaluation
 *          - add the possibility to cache result (specify slices or grab it from execution)
 *          - add field "do not execute if..."
 */
class AA_Stringexpand__ extends AA_Stringexpand {

    /** Do not trim all parameters */
    function doTrimParams() { return false; }

    /** additionalCacheParam function
     *
     */
    function additionalCacheParam() {
        // is it necessary to have $this->item here?
        return serialize(array(!is_object($this->item) ? '' : $this->item->getId()));
    }

    /** Do not qoute results - it is just shortcut, so we need to expand
     *  the returned text
     */
    function doQuoteColons() {
        return false;
    }

    /**
     * @return string|void
     */
    function expand() {
        static $saliases = array();
        static $smodules = null;

        $arg_list = func_get_args();   // must be asssigned to the variable
        $name     = array_shift($arg_list);

        if ( isset($GLOBALS['STRINGEXPAND_SHORTCUTS'][$name]) ) { // old deprecated listing of aliases in custom file
            $text = $GLOBALS['STRINGEXPAND_SHORTCUTS'][$name];
        } else {
            if (is_null($saliases[$name])) {
                if (is_null($smodules)) {
                    if (AA::$site_id) {
                        $smodules = array(AA::$site_id);
                        if ($site = AA_Module_Site::getModule(AA::$site_id)) {
                            if (is_array($add_modules = $site->getProperty('add_aliases'))) {
                                $smodules = array_merge($smodules,array_filter($add_modules));
                            }
                        }
                    } elseif (AA::$slice_id) {
                        $smodules = explode_ids(StrExpand('AA_Stringexpand_Modulefield', array(AA::$slice_id, 'site_ids')));
                    } else {
                        $smodules = array();
                    }
                }
                $zids = AA_Object::querySet('AA_Aliasfunc', new AA_Set($smodules, new AA_Condition('alias', '==', $name)));
                $saliases[$name] = AA_Object::loadProperty($zids->longids(0),'code');
                // another approach read all at once - not used
                // huhl( AA_Object::loadProperties($zids->longids(), 'aa_name'));
            }
            $text = $saliases[$name];
        }

        $text = AA_Stringexpand::replaceParams($text, $arg_list);
        if (strpos($text, '{(') !== false) {
            // we want to use inline aliases also inside {_:aliases...}
            $text = AA_Stringexpander::ApplyInlineAlias($text);
        }
        return $text;
    }
}

/** Encrypt the text using $key as password (mcrypt PHP extension must be installed)
 *  $output_type |url (if not specified, binary is used. if "url", the data are base64url is used)
 */
class AA_Stringexpand_Encrypt extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /**
     * @param string $text
     * @param string $key
     * @param null $output_type - no longer used - previously used 'url' to encode result in url-friendly base64_url, now it is allways
     * @return string|void
     */
    function expand($text='', $key='', $output_type=null) {
        return AA\Util\Crypt::encryptAesGcm($text,$key);
    }

    /**
     * @param $data
     * @return string
     */
    static function get_time_token($data) {
        return AA\Util\Crypt::encryptAesGcm(serialize($data), 'aa-sul'.AA_ID.date('j.n.y H'));
    }

    /** Try to decode sent token - not older than $hours
     *  $hours must count with cache - the tokens on the form could be in the cache
     */
    static function decrypt_time_token($token, $hours=24) {
        for ($i=0; $i<$hours; ++$i) {
            if (strlen($serialized = AA\Util\Crypt::decryptAesGcm($token, 'aa-sul'.AA_ID.date('j.n.y H',strtotime("-$i hour"))))) {
                return unserialize($serialized);
            }
        }
        return '';
    }
}

/** Decrypts the text using $key as password (mcrypt PHP extension must be installed)
 */
class AA_Stringexpand_Decrypt extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /**
     * @param string $text
     * @param string $key
     * @param null $output_type
     * @return string|void
     */
    function expand($text='', $key='', $output_type=null) {
        if (false !== ($ret = AA\Util\Crypt::decryptAesGcm($text,$key))) {
            return $ret;
        }
        // if we fail with the decryption, we try, is the string wasn't encrypted in older AA version, which used mcrypt
        // However this approach will work just for PHP <= 7.1, so users will need to reencrypt the texts, if they need
        // to use old crypted texts
        return self::_encryptdecrypt_legacy(false,(($output_type=='url') ? url2bin($text) : $text), $key);
    }

    /**
     * Old legacy en/de-crypt function which uses mcrypt - the results are completely incompatible with other cryptolibs
     * @param $mode_encrypt
     * @param $text
     * @param $key
     * @return string
     */
    protected function _encryptdecrypt_legacy($mode_encrypt, $text, $key) {
        if (!function_exists('mcrypt_module_open')) {
            return '';
        }
        /* Open module, and create IV */
        $td      = mcrypt_module_open('des', '', 'ecb', '');
        $key     = substr($key, 0, mcrypt_enc_get_key_size($td));
        $iv_size = mcrypt_enc_get_iv_size($td);
        $iv      = mcrypt_create_iv($iv_size, MCRYPT_RAND);

        $ret = '';
        /* Initialize encryption handle */
        if (mcrypt_generic_init($td, $key, $iv) != -1) {
            $ret = $mode_encrypt ? mcrypt_generic($td, $text) : mdecrypt_generic($td, $text);
            mcrypt_generic_deinit($td);
            mcrypt_module_close($td);
        }
        return $ret;
    }
}

/** Generates JWT (JSON Web Token) for user identified by <username> and <password>
 *  Ussage: {jwtencode:secret-key:{qss:email}:{qss:password}:3600:_#EXITTIME}
 */
class AA_Stringexpand_Jwtencode extends AA_Stringexpand {

    /**
     * @param string $key          - secretkey for JWT sign
     * @param string $username     - username
     * @param string $password     - password
     * @param string $lifetime     - lifetime in seconds (default - 1 day)
     * @param string $add_validity - aditional check - alias or aa code evaluated for the user. When user then access the
     *                               the AA with token, the evaluated text for the user must be the same for success login
     *                             - could be used for invalidating of the token. Let's create end_date........ field
     *                               with _#EXITTIME alias passed as $add_validity. Then with user logout we just update
     *                               the end_date........ field of the user
     *                             - works only for Readermanagement users
     * @return string
     */
    function expand($key='', $username='', $password='', $lifetime='', $add_validity='') {
        if (!strlen($key) OR !strlen($username) OR !strlen($password) OR !($uid = AA::$perm->authenticateUsername($username, $password))) {
            return '';
        }

        if (strlen($add_validity) AND !is_long_id($uid)) {
            // if we are using $add_validity, then the user MUST be from Reader Management so we can compute the add_valid
            return '';
        }

        $token = array();
        $token['sub'] = $uid;
        $token['exp'] = time() + ((int)$lifetime ?: 24*3600);
        $token['iat'] = time();
        if (strlen($add_validity)) {
            $token['aaValid'] = StrExpand('AA_Stringexpand_Item', array($uid,$add_validity));
        }

        // IMPORTANT:
        // You must specify supported algorithms for your application. See
        // https://tools.ietf.org/html/draft-ietf-jose-json-web-algorithms-40
        // for a list of spec-compliant algorithms.
        $jwt = \Firebase\JWT\JWT::encode($token, $key);

        // This will now be an object instead of an associative array. To get
        // an associative array, you will need to cast it as such:
        // $decoded = (array)\Firebase\JWT\JWT::decode($jwt, $key, array('HS256'));

        return $jwt;
    }
}

/** {jwtdecode:<secret-key>[:<add-validity>]} - checks the validity of the JWS token in Authorization header an if OK,
 *  returns user id of authenticated user
 *   <add-validity> is the additional check which allows invalidate older tokens - see add-validity in {jwtencode:...}
 *  The Appache webserver sometimes do not pass the Authorization header. The solution could be in adding
 *       FcgidPassHeader Authorization
 *  in Apache configuration (for Apache/mod_fcgid)
 */
class AA_Stringexpand_Jwtdecode extends AA_Stringexpand {

    /**
     */
    function expand($key='', $add_validity='') {

        // Get header Authorization
        if (strlen($key) AND ($auth_header = get_authorization_header())) {
            list($type, $jwt) = explode(" ", $auth_header, 2);

            if (strcasecmp($type, "Bearer") == 0) {
                try {
                    $decoded = (array)\Firebase\JWT\JWT::decode($jwt, $key, array('HS256'));
                    $uid = $decoded['sub'];
                    if (is_long_id($uid) AND strlen($add_validity)) {
                        if ($decoded['aaValid'] == StrExpand('AA_Stringexpand_Item', array($uid,$add_validity))) {
                            return $uid;
                        }
                        return '';
                    }
                    return $uid;
                } catch ( Exception $e ) {
                }
            }
            // wrong Auth header
        }
        // no Auth header
        return '';
    }
}


/** computes MD5 hash */
class AA_Stringexpand_Md5 extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /**
     * @param string $text
     * @return string|void
     */
    function expand($text='') {
        return hash('md5', $text);
    }
}

/** crypt text as AA password */
class AA_Stringexpand_Pwdcrypt extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /**
     * @param string $text
     */
    function expand($text='') {
        // return crypt($text, 'xx');
        AA_Perm::cryptPwd($text);
    }
}

/** Table - experimental - do not use - will be probably replaced with Array
 *  (nevercached - because it caches also "set" and "addset" commands, so
 *  ignores the second same "set"/"addset" command )
 */
class AA_Stringexpand_Table extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($val and $param could begin with space) */
    function doTrimParams() { return false; }

    /**
     * @param string $id
     * @param string $cmd
     * @param string $r
     * @param string $c
     * @param string $val
     * @param string $param
     * @return string|void
     */
    function expand($id='', $cmd='', $r='', $c='', $val='', $param='') {
        static $tables = array();

        $id  = trim($id);
        $cmd = trim($cmd);
        $r   = trim($r);
        $c   = trim($c);

        if (!isset($tables[$id])) {
            $tables[$id] = new AA_Table($id);
        }
        $table = $tables[$id];
        $ret   = '';

        switch ($cmd) {
            case 'set':
                $table->set($r, $c, $val, $param);   // param as attribute
                break;
            case 'get':
                $ret = $table->get($r, $c, strlen($val) ? $val : '_#1');
                break;
            case 'addset':
                $table->addset($r, $c, $val);
                break;
            case 'joinset':
                $table->joinset($r, $c, $val, $param); // param as delimiter
                break;
            case 'sum':
                $ret = $table->sum($r, $c, strlen($val) ? $val : '_#1');
                break;
            case 'print':
                $ret = $table->gethtml();
                break;
        }

        return $ret;
    }
}

/** Array - experimental
 *  {array:mygrp:joinset:{_#GRP_INDX}:_#HEADLINE}
 *  {array:mygrp:get:{_#GRP_INDX}}
 */
class AA_Stringexpand_Array extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($val and $param could begin with space) */
    function doTrimParams() { return false; }

    /**
     * @param string $id
     * @param string $cmd
     * @param null $par1
     * @param null $par2
     * @param null $par3
     * @return string|void
     */
    function expand($id='', $cmd='', $par1=null, $par2=null, $par3=null) {
        static $arrays = array();
        if (!isset($arrays[$id])) {
            $arrays[$id] = new AA_Array($id);
        }
        $arr = $arrays[$id];
        $ret   = '';

        switch ($cmd) {
            case 'set':
                $arr->set($par1, $par2);
                break;
            case 'get':
                $ret = $arr->get($par1);
                break;
            case 'addset':
                $arr->addset($par1, $par2);
                break;
            case 'joinset':
                // $i, $value, $delimiter
                $arr->joinset($par1, $par2, $par3);
                break;
            case 'getall':
                // $expr with _#1, $delimiter, $sort (key|)
                $ret = $arr->getAll(strlen($par1) ? $par1 : '_#1', $par2, $par3);
                break;
            case 'sum':
//            $ret = $arr->sum($i, strlen($val) ? $val : '_#1');
                break;
        }

        return $ret;
    }
}

/** Go directly to another url
 *  use as:
 *    {redirect:http#://example.org/en/new-page}                 - mention the escaped colon in http
 *    {redirect:{ifset:{xid}::http#://example.org/en/new-page}}  - for conditional redirect
 */
class AA_Stringexpand_Redirect extends AA_Stringexpand {
    /**
     * @param string $url
     * @param string $code
     * @return string|void
     */
    function expand($url='', $code='') {
        if (!empty($url)) {
            go_url($url, '', false, $code ?: 301);  // 301 Moved Permanently
        }
        return '';
    }
}

/** List of fields changed during last edit - dash ('-') separated
 *  You can use it for example when you are sending e-mail notifications about
 *  the item change, and you want to know, what is changed:
 *    {ifin:{changed:{_#ITEM_ID_}}:category.......2:<em>Category changed to {category.......2}</em>}
 *  You can also use this feature if you want to send e-mail notification only if specific fields are changed:
 *    {ifset:{intersect:{changed:{_#ITEM_ID_}}:category.......2-expiry_date.....}: email text...}
 *  (we use the feature, that no mail is send, when the body of the mail is empty)
 *    {foreach:{changed:{_#ITEM_ID_}}:{( - {field:_#1:name:81294238c1ea645f7eb95ccb301063e4} <br>)}}
 */
class AA_Stringexpand_Changed extends AA_Stringexpand {
    /**
     * @param null $item_id
     * @return string|void
     */
    function expand($item_id=null) {
        return (guesstype($item_id) != 'l') ? '' : ChangesMonitor::singleton()->lastChanged($item_id);
    }
}

/**
 * Class AA_Stringexpand_Changedate
 */
class AA_Stringexpand_Changedate extends AA_Stringexpand {
    /**
     * @param null $item_id
     * @param null $field_id
     * @param null $format
     * @return string|void
     */
    function expand($item_id=null, $field_id=null, $format=null) {
        $time = ((guesstype($item_id) != 'l') OR !$field_id) ? '0' : (string)ChangesMonitor::singleton()->lastChangeDate($item_id,$field_id);
        return StrExpand('AA_Stringexpand_Date', array($format, $time, '--'));
    }
}

/**
 * Sends HTTP header
 * {header:404}
 * {header:{ifset:{xid}::404}}   - good example - never use something like {ifset:{xid}::{header:404}}!!!
 */
class AA_Stringexpand_Header extends AA_Stringexpand {
    /**
     * @param null $header
     * @param null $parameter
     * @return string|void
     */
    function expand($header=null, $parameter=null) {
        $parameter = strlen($parameter) ? str_replace(array("\r\n","\n", "\r"), ' ', $parameter) : '';       // remove line endings
        switch ($header) {
            case '200':       AA::$headers['status']      = 'HTTP/1.1 200 OK';                       break;
            case '201':       AA::$headers['status']      = 'HTTP/1.1 201 Created';                  break;
            case '304':       AA::$headers['status']      = 'HTTP/1.1 304 Not Modified';             break;
            case '400':       AA::$headers['status']      = 'HTTP/1.1 400 Bad Request';              break;
            case '401':       AA::$headers['status']      = 'HTTP/1.1 401 Unauthorized';             break;
            case '404':       AA::$headers['status']      = 'HTTP/1.0 404 Not Found';                break;
            case '409':       AA::$headers['status']      = 'HTTP/1.1 409 Conflict';                 break;
            case 'plain':     AA::$headers['type']        = 'text/plain';                            break;  // default header text content
            case 'css':       AA::$headers['type']        = 'text/css';                              break;
            case 'html':      AA::$headers['type']        = 'text/html';                             break;
            case 'bin':       AA::$headers['type']        = 'application/octet-stream';              break;  // default header for binary content
            case 'js':        AA::$headers['type']        = 'application/javascript';                break;
            case 'xml':       AA::$headers['type']        = 'text/xml';                              break;
            case 'gif':       AA::$headers['type']        = 'image/gif';                             break;
            case 'jpg':       AA::$headers['type']        = 'image/jpeg';                            break;
            case 'png':       AA::$headers['type']        = 'image/png';                             break;
            case 'ico':       AA::$headers['type']        = 'image/x-icon';                          break;
            case 'svg':       AA::$headers['type']        = 'image/svg+xml';                         break;
            case 'json':      AA::$headers['type']        = 'application/json';                      break;
            case 'csv':       AA::$headers['type']        = 'text/csv';                              break;
            case 'filename':  AA::$headers['disposition'] = 'attachment; filename="'.$parameter.'"'; break;  // $parameter is OK not escaped
            case 'utf-8':     AA::$headers['encoding']    = 'utf-8';                                 break;
            case 'CSP':       AA::$headers['CSP']         = 'Content-Security-Policy: '.$parameter;  break;
            case 'CSP-RO':    AA::$headers['CSP-RO']      = 'Content-Security-Policy-Report-Only: '.$parameter;  break;
            case 'RT':        AA::$headers['RT']          = 'Report-To: '.$parameter;                break;
            case 'NEL':       AA::$headers['NEL']         = 'NEL: '.$parameter;                      break;
            case 'XSS':       AA::$headers['XSS']         = 'X-Xss-Protection: '.$parameter;         break;
        }
        return '';
    }
}


/**
 * Class AA_Password_Manager_Reader
 */
class AA_Password_Manager_Reader {

    /**
     *
     */
    const KEY_TIMEOUT = 150;

    /**
     * @return string
     */
    function getFirstForm() {  // Type in either your username or e-mail
        return '<form id="pwdmanager-firstform" action="" method="post"><div class="aa-widget">
        <label for="aapwd1">' ._m('Forgot your password? Fill in your email.'). '</label>
        <div class="aa-input">
           <input size="30" maxlength="128" name="aapwd1" id="aapwd1" value="" placeholder="'._m('e-mail').'" required type="email">
        </div>
        <input type="hidden" name="nocache" value="1">
        <input type="submit" id="pwdmanager-send" name="pwdmanager-send" value="'. _m('Send').'">
        </div>
        </form>
        ';
    }

    /**
     * @param $user
     * @param $slice_id
     * @param $from_email
     * @return string
     */
    function askForMail($user, $slice_id, $from_email) {
        if ( !trim($user) ) {
            return self::_bad(_m("Unable to find user - please check if it has been misspelled."));
        }
        if (!($user_id = AA_Reader::name2Id($user, $slice_id))) {
            if (!($user_id = AA_Reader::email2Id($user, $slice_id))) {
                return self::_bad(_m("Unable to find user - please check if it has been misspelled."));
            }
        }
        $user_info = GetAuthData($user_id);

        // generate MD5 hash
        $email    = $user_info->getValue(FIELDID_EMAIL);
        $pwdkey   = md5($user_id.$email.AA_ID.round(now()/60));

        // send it via email
        $url      = shtml_url()."?aapwd2=$pwdkey-$user_id";
        $pwd_link = "<a href=\"$url\">$url</a>";

        if ($template_id = DB_AA::select1('SELECT id FROM `email`', 'id', array(array('owner_module_id',$slice_id, 'l'), array('type','password change')))) {
            $slice     = AA_Slice::getModule($slice_id);
            $user_item = new AA_Item($user_info, $slice->aliases( array("_#PWD_LINK" => GetAliasDef( "f_t:$pwd_link", "", _m('Password link')))));
            AA_Mail::sendTemplate($template_id, array($email), $user_item, false);
        } else {
            $mail     = new AA_Mail;
            $mail->setSubject(_m("Password change"));
            $body = _m("To change the password, please visit the following address:<br>%1<br>Change will be possible for two hours - otherwise the key will expire and you will need to request a new one.",array("<a href=\"$url\">$url</a>"));
            $mail->setHtml($body, html2text($body));
            $mail->setHeader("From", $from_email);
            $mail->setHeader("Reply-To", $from_email);
            $mail->setHeader("Errors-To", $from_email);
            $mail->setCharset(AA_Langs::getCharset());
            $mail->send(array($email));
        }

        return self::_ok(_m('E-mail with a key to change the password has just been sent to the e-mail address: %1', array($email)));
    }

    /**
     * @param $key
     * @param $user
     * @return string
     */
    function getChangeForm($key, $user) {
        if (!self::isValidKey($key, $user)) {
            return self::_bad(_m("Bad or expired key."));  // @todo get messages from somewhere
        }
        return '
        <form name="pwdmanagerchangeform" method="post" action="" class="aapwdmanagerchangeform">
          <fieldset>
            <legend>'. _m("Fill in the new password") .'</legend>
            <div style="display:inline-block; text-align:right;">
              <label>'._m('New password').': <input type="password" name="aapwd3"></label><br>
              <label>'._m('Retype New Password').': <input type="password" name="aapwd3b"></label><br>
              <input type="hidden" name="aauser"  value="'. $user .'">
              <input type="hidden" name="aakey"   value="'. $key .'">
              <input type="hidden" name="nocache" value="1">
              <input type="submit"  value="'. _m('Send').'">
            </div>
          </fieldset>
        </form>';
    }

    /**
     * @param $pwd1
     * @param $pwd2
     * @param $key
     * @param $user
     * @param $from_email
     * @return string
     */
    function changePassword($pwd1, $pwd2, $key, $user, $from_email) {
        if (!self::isValidKey($key, $user)) {
            return self::_bad(_m("Bad or expired key."));  // @todo get messages from somewhere
        }
        if ($pwd1 != $pwd2) {
            return self::_bad(_m("Passwords do not match - please try again."));  // @todo get messages from somewhere
        }
        if (strlen($pwd1) < 6) {
            return self::_bad(_m("The password must be at least 6 characters long."));  // @todo get messages from somewhere
        }

        if (UpdateField($user, 'password........', new AA_Value(ParamImplode(array('AA_PASSWD',$pwd1))))) {
            return self::_ok(_m("Password changed."));
        }
        return self::_ok(_m("An error occurred during password change - please contact: %1.", array($from_email)));
    }

    /**
     * @param $key
     * @param $user_id
     * @return bool
     */
    function isValidKey($key, $user_id) {
        if (!$key OR !$user_id) {
            return false;
        }
        if (!($user_info = GetAuthData($user_id))) {
            return false;
        }
        // Check the key
        $email    = $user_info->getValue(FIELDID_EMAIL);
        $key_base = $user_id.$email.AA_ID;
        for ($i=0; $i<AA_Password_Manager_Reader::KEY_TIMEOUT; $i++) {
            if (hash('md5', $key_base.round(round(now()/60)-$i)) == $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param $text
     * @return string
     */
    function _bad($text) {
        return '<div class="aa-err">'.$text.'</div>'.AA_Password_Manager_Reader::getFirstForm();
    }

    /**
     * @param $text
     * @return string
     */
    function _ok($text) {
        return '<div class="aa-ok">'.$text.'</div>';
    }

    /** we need to check, if the text answer means OK or Bad */
    public static function is_ok($text) {
        return strpos($text, '<div class="aa-ok">') === 0;
    }
}

/** manages forgotten password
 *  The idea is, that this alias will manage all tasks needed for change of pwd
 *  you just put the {changepwd:<reader_slice_id>:<from-email>}
 */
class AA_Stringexpand_Changepwd  extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $reader_slice_id - reader module id - required
     * @return string
     */
    function expand($reader_slice_id='', $from_email='') {
        if (!is_long_id($reader_slice_id)) {
            return '';
        }
        $from_email = $from_email ? $from_email : ERROR_REPORTING_EMAIL;

        if (isset($_POST['aapwd3'])) {    // CHange Password
            return AA_Password_Manager_Reader::changePassword($_POST['aapwd3'], $_POST['aapwd3b'], $_POST['aakey'], $_POST['aauser'],$from_email);
        } elseif (isset($_GET['aapwd2'])) {
            list($key, $user) = explode('-',$_GET['aapwd2']);
            return AA_Password_Manager_Reader::getChangeForm($key, $user);
        } elseif (isset($_REQUEST['aapwd1'])) {        // CHeck User
            return AA_Password_Manager_Reader::askForMail($_REQUEST['aapwd1'], $reader_slice_id, $from_email);
        }

        return AA_Password_Manager_Reader::getFirstForm();
    }
}

/** Sends mail to the user identified by username and readerslice to allow password change
 *    {changepwdsendmail:<reader_slice_id>:<from-email>:<username>}
 *  @return 1 if mail is sent, anything else in case of error
 */
class AA_Stringexpand_Changepwdsendmail  extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $reader_slice_id - reader module id - required
     * @return string
     */
    function expand($reader_slice_id='', $from_email='', $username='') {
        if (!is_long_id($reader_slice_id)) {
            return '';
        }
        $from_email = $from_email ? $from_email : ERROR_REPORTING_EMAIL;
        return AA_Password_Manager_Reader::is_ok(AA_Password_Manager_Reader::askForMail($username, $reader_slice_id, $from_email) ? '1' : 'err');
    }
}




/** returns part of the XML or HTML <string > based on <xpath> query
 *  Use as:
 *      {xpath:{include:http#://example.cz/list.html}://[@id="pict-width"]}
 *      {xpath:{include:http#://example.cz/photos/displayimage.php?pos=-47}:/html/body//div[@id="picinfo"]//td[text()="Datum"]/following-sibling#:#:*}
 *      {xpath:{include:http#://example.cz/list.html}://img[@id="bigpict"]:width}
 *      {xpath:{include:http#://example.cz/list.html}://h2[2]}  - second <h2>
 *      {xpath:{item:784557:full_text.......}://data/udaj[uze=//uzemi/element[kod="3026"]/@id][uka="u1"]/hod}  - xpath subqueries used for data from CSU (czso.cz)
 *      {xpath:{include:http#://example.cz/list.html}://div [@id="wantedTable"]//table:XML}
 */
class AA_Stringexpand_Xpath extends AA_Stringexpand {
    /** Do not trim all parameters ($delimiter could contain spaces at the begin) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $string - XML or HTML string (possibly loaded with {include:<url>})
     * @param $query - XPath query - @see XPath documentation
     * @param $attr - if empty, the <text> value of the matching element is returned
     *                     if specified, then the attribute is returned (like width attribute of image in third example above)
     *                     use 'XML' if you want to get the whole inner HTML
     * @param $delimiter - by default, it returns just first matching value.
     *                     If specified, then all matching texts are returned delimited by <delimiter>
     * @return string
     */
    function expand($string="", $query='', $attr='', $delimiter='AA_PrintJustFirst') {
        if (!$query) {
            return '';
        }
        $doc = new DOMDocument();
        if (!$doc->loadHTML(trim($string))) {   // I tried to contentcached this load to save time on parsing document, but it had absolutely no effect - PHP 7.0 - Honza 2017-01-25
            return 'Error parsing';
        }
        $xpath = new DOMXPath($doc);

        $entries = $xpath->query($query);
        $ret = '';
        foreach ($entries as $entry) {
            if ($attr=='XML') {
                $ret.= $entry->ownerDocument->saveHTML($entry);
            } elseif ($attr)  {
                $ret .= $entry->attributes->getNamedItem($attr)->nodeValue;
            } else {
                $ret .= $entry->nodeValue;
            }
            if ($delimiter == 'AA_PrintJustFirst') {
                break;
            }
            $ret .= $delimiter;
        }
        return $ret;
    }
}

/**
 *  Use as:
 *    {foreach:val1-val2:<p>_#1</p>:-:<br>}
 *    {foreach:val1-val2:<p>_#loop</p>:-:<br>}  // newer version - older _#1 may colide with others _#1 in others expressions
 *    {foreach:{qs:myfields:-}:{(<td>{_#1}</td>)}}  //fields[] = headline........-year...........1 - returns <td>Prague<td><td>2012</td>
 *    {foreach:{changed:{_#ITEM_ID_}}:{( - {field:_#1:name:81294238c1ea645f7eb95ccb301063e4} <br>)}}
 *    {foreach:2011-2012:{(<li><a href="?year=_#1" {ifeq:_##1:{qs:year}:class="active"}>_#1</a></li>)}}
 *    {foreach:2011-2012:{(<li><a href="?year=_#loop" {ifeq:_#loop:{qs:year}:class="active"}>_#1</a></li>)}}
 *    {foreach:{sequence:num:1999:2017:1:-}:<th>_#1</th>}
 *    {foreach:{ids:478598ab745a65f1478598ab745a65f1}:{({_:Editor_document:_#1:{id..............}})}}
 */
class AA_Stringexpand_Foreach extends AA_Stringexpand {

    /** Do not trim all parameters ($outputdelimiter could begin with space) */
    function doTrimParams() { return false; }

    // not needed right now for Nevercached functions, but who knows in the future

    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /**
     * @param string $values
     * @param string $text
     * @param string $valdelimiter
     * @param string $outputdelimiter
     * @return string|void
     */
    function expand($values='', $text='', $valdelimiter='', $outputdelimiter='') {
        // _##1 is the way, how to use parameter from the outside of if:
        // {foreach:2011-2012:{(<li><a href="?year=_#1" {ifeq:_##1:{qs:year}:class="active"}>_#1</a></li>)}}
        // or better
        // {foreach:2011-2012:{(<li><a href="?year=_#1" {ifeq:_#loop:{qs:year}:class="active"}>_#1</a></li>)}}
        $text = str_replace('_##1', '_#1', $text);

        $item   = $this ? $this->item : null;
        if (!strlen($valdelimiter)) {
            $valdelimiter = '-';
        }
        $arr = ($valdelimiter == 'json') ? json2arr(trim($values)) : explode($valdelimiter, $values);
        $arr = array_filter(array_map('trim', $arr), 'strlen');
        $ret= array();

        foreach($arr as $str) {
            $ret[] = AA::Stringexpander()->unalias(str_replace(array('_#1','_#loop'),$str,$text),'',$item);
        }
        return join($outputdelimiter, $ret);

        //$arr = array_filter(array_map('trim', $arr), 'strlen');
        //array_walk($arr, function(&$value, $key, $param) {
        //    $value = AA::Stringexpander()->unalias(str_replace('_#1',$value,$param[1]),'',$param[0]);
        //}, array($item, $text));
        //return join($outputdelimiter, $arr);
    }
}


/** Sends e-mail conditionaly
 *  Be careful - it can send mail on every page load!
 *  Use as:
 *    {mail:1:honza.malik@ecn.cz:test mail:{view:24}:utf-8:actionapps@ecn.cz}
 *    {mail:1:["test@ecn.cz,"info@ecn.cz"]:test mail:{view:24}:utf-8:actionapps@ecn.cz:actionapps@ecn.cz:actionapps@ecn.cz:actionapps@ecn.cz::https#://test.org/img/img1.png}
 *    {mail:1:["test@ecn.cz,"info@ecn.cz"]:test mail:{view:24}:utf-8:actionapps@ecn.cz:::::["https#://test.org/img1.png","https#://test.org/img2.png"]}
 *    {mail:1:test@ecn.cz:test mail:Hi, I am body of mail:utf-8:actionapps@ecn.cz:::::{@file............:json}}
 */
class AA_Stringexpand_Mail extends AA_Stringexpand_Nevercache {

    /**
     * @param string $condition
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param string $lang
     * @param string $from
     * @param string $reply_to
     * @param string $errors_to
     * @param string $sender
     * @param string $cc
     * @param string $bcc
     * @param string $attachments
     * @return int|string|void
     */
    function expand($condition='', $to='', $subject='', $body='', $lang='', $from='', $reply_to='', $errors_to='', $sender='', $cc='', $bcc='', $attachments='') {

        if (!strlen($condition) OR !strlen($body) OR ((string)$condition==='0')) {
            return '';
        }

        $to = json2arr($to); // can't be inside empty()  - Honza, php 5.2
        if (empty($to)) {
            return '';
        }

        $cc  = join(',',AA_Validate::filter(json2arr($cc), 'email'));
        $bcc = join(',',AA_Validate::filter(json2arr($bcc), 'email'));

        $attach = ParamImplode(json2arr($attachments));

        $mail_arr = array( 'subject'     => $subject,
            'body'        => $body,
            'header_from' => $from,
            'reply_to'    => $reply_to,
            'errors_to'   => $errors_to,
            'sender'      => $sender,
            'lang'        => $lang,
            'html'        => 1,
            'cc'          => $cc,
            'bcc'         => $bcc,
            'attachments' => $attach
        );

        $mail = new AA_Mail;
        $mail->setFromArray($mail_arr);
        return $mail->sendLater($to);
    }
}

/** Sends e-mail conditionaly
 *  Be careful - it can send mail on every page load!
 *  Use as:
 *    {mail:1:honza.malik@ecn.cz:test mail:{view:24}:utf-8:actionapps@ecn.cz}
 */
class AA_Stringexpand_Xxmail extends AA_Stringexpand_Nevercache {

    /**
     * @param string $condition
     * @param string $to
     * @param string $subject
     * @param string $body
     * @param string $lang
     * @param string $from
     * @param string $reply_to
     * @param string $errors_to
     * @param string $sender
     * @param string $cc
     * @param string $bcc
     * @return string|void
     */
    function expand($condition='', $to='', $subject='', $body='', $lang='', $from='', $reply_to='', $errors_to='', $sender='', $cc='', $bcc='') {

        if (!strlen($condition) OR !strlen($body) OR ((string)$condition==='0')) {
            return '';
        }

        $to = json2arr($to); // can't be inside empty()  - Honza, php 5.2
        if (empty($to)) {
            return '';
        }

        $cc  = join(',',AA_Validate::filter(json2arr($cc), 'email'));
        $bcc = join(',',AA_Validate::filter(json2arr($bcc), 'email'));

        $mail_arr = array( 'subject'     => $subject,
            'body'        => $body,
            'header_from' => $from,
            'reply_to'    => $reply_to,
            'errors_to'   => $errors_to,
            'sender'      => $sender,
            'lang'        => $lang,
            'html'        => 1,
            'cc'          => $cc,
            'bcc'         => $bcc
        );

        $mail = new AA_Mail;
        $mail->setFromArray($mail_arr);
        AA_Log::write('DEBUG', join('-',$to), aa_substr($subject, 0,16));
        return '';
    }
}

/** Sends e-mail conditionaly
 *  Be careful - it can send mail on every page load!
 *  Use as:
 *    {mailform:<to>:<subject>:<html-inputs>:<body>:<ok-text>:<lang>:<from>}
 *    {mailform:honza.malik@ecn.cz:test mail:Your note <input name="note">:User posted<br>_#1<br>Regards<br>ActionApps:sent OK:utf-8:actionapps@ecn.cz}
 */
class AA_Stringexpand_Mailform extends AA_Stringexpand_Nevercache {

    /**
     * @param string $to
     * @param string $subject
     * @param string $html
     * @param string $body
     * @param string $ok
     * @param string $lang
     * @param string $from
     * @return string
     */
    function expand($to='', $subject='', $html='', $body='', $ok='', $lang='', $from='') {

        $config_arr = array('to' => $to,
            'subject' => $subject,
            'body' => $body,
            'ok' => $ok,
            'lang' => $lang,
            'from' => $from );
        $form_id  = 'form'.new_id();
        $mailconf = AA_Stringexpand_Encrypt::get_time_token($config_arr);

        $ret = "<form id=\"$form_id\" onsubmit=\"AA_AjaxSendForm('$form_id', '".AA_INSTAL_PATH."/mail.php'); return false;\">
        <input type=hidden name=aa_mailconf value=\"$mailconf\">".
            StrExpand('AA_Stringexpand_Formantispam') .
            $html .
            "</form>
        ";
        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_AjaxSendForm
        return $ret;
    }
}

/** Generates html code (<input name=answer) to be inside <form> which protects the form from spammers by easy trick
 *  The code could be changed in future
 */
class AA_Stringexpand_Formantispam extends AA_Stringexpand_Nevercache {

    /**  */
    function expand() {
        return '<style type="text/css">
           div.aaskryto { display:none };
        </style>
        <div class="aaskryto"><input type="text" name="answer" value=""></div>';
    }
}

/** Rotates on one place in the page different contents (divs) with specified interval
 *  {rotator:<item-ids>:<html-code>:<interval>:<speed>:<effect>:<li-code>}
 *  {rotator:{ids:a24657bf895242c762607714dd91ed1e}:_#FOTO_S__<div>_#HEADLINE</div>}
 *  {rotator:{ids:a24657bf895242c762607714dd91ed1e}:_#FOTO_S__<div>_#HEADLINE</div>::::_#HEADLINE}
 *  @param string - speed:  '' | slow | fast
 *  @param string - effect: '' | fade
 */
class AA_Stringexpand_Rotator extends AA_Stringexpand_Nevercache {
    /**
     * @param string $ids
     * @param string $code
     * @param string $interval
     * @param string $speed
     * @param string $effect
     * @param string $li_code
     * @return string|void
     */
    function expand($ids='', $code='', $interval='', $speed='', $effect='', $li_code='') {
        $frames = array();
        $lis    = array();
        $zids = new zids(explode_ids($ids));
        if ( $zids->count() <= 0 ) {
            return '';
        }

        $interval   = (int)$interval ? (int)$interval : 3000;
        $extrastyle = ($effect == 'fade') ? 'position:absolute;' : '';
        $showfirst  = '';
        $div_id     = 'rot'.get_hash($ids, $code, $interval, $speed, $effect);

        $items = AA_Items::getItems($zids);
        $i = 0;
        foreach($items as $long_id=>$item) {
            $frame = trim(AA::Stringexpander()->unalias($code, '', $item));
            if ($frame) {
                $frames[]  = "<div class=rot-hide style=\"$showfirst $extrastyle\">$frame</div>";
                $showfirst = 'display:none;';
                if ($li_code) {
                    $active = $i ? '' : ' active';
                    $lis[]  = "<li class=\"rot-active$active\" onclick=\"AA_Rotator.rotators['$div_id'].index=$i;AA_Rotator('$div_id'); \" onmouseover=\"AA_Rotator.rotators['$div_id'].index=$i;AA_Rotator('$div_id');\">".trim(AA::Stringexpander()->unalias($li_code, '', $item))."</li>";
                }
            }
            ++$i;
        }
        if (!count($frames)) {
            return '';
        }

        $extrahightdiv = ($effect == 'fade') ? "<div class=rot-hight style=\"visibility:hidden\">$frame</div>" : '';
        $liswitcher    = $li_code ? "<ul class=\"rot-switcher\">".join("\n",$lis)."</ul>" : '';

        $framesdiv = '<div class="rot-frames">'.join("\n",$frames).'</div>';

        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_Rotator
        return "<div id=\"$div_id\" style=\"position:relative\">".$framesdiv.$extrahightdiv.$liswitcher."</div><script>AA_Rotator('$div_id', $interval, ".count($frames).", '$speed', '$effect');</script>";
    }
}

/** Recounts all (or specified) computed field in the specified item (or dash separated items)
 *    {recompute:<item_ids>[:<fields_ids>]}
 */
class AA_Stringexpand_Recompute extends AA_Stringexpand_Nevercache {

    /**
     * @param string $item_ids
     * @param string $fields_ids
     * @return string|void
     */
    function expand($item_ids='', $fields_ids='') {

        $item_arr  = explode_ids($item_ids);
        $field_arr = explode_ids($fields_ids);

        foreach ($item_arr as $iid) {
            if (!($iid = trim($iid))) {
                continue;
            }
            if (!with($item = new ItemContent($iid))->is_empty()) {
                $item->updateComputedFields(null, 'update', $field_arr);
            }
        }
        return '';
    }
}

/** Creates tag cloud from the items
 *  {tagcloud:<item_ids>[:<count>[:<alias>[:<count_field>]]]}
 *     <item_ids>    - dash separated list of ids of all keywords (tags)
 *     <count>       - maximum number of displayed keywords (all by default)
 *     <alias>       - The AA expression used for each keyword (_#HEADLINK used as default)
 *     <count_field> - The id of the field, where you already have the number
 *                     of ussage precounted. It is very good idea,to have such
 *                     field - in other case the counts must be countedon every
 *                     ussage. The count could be counted automaticaly
 *                     in the field by using "Comuted field for INSERT/UPDATE"
 *                     with the parameter "_#BACKLINK:_#BACKLINK::day", where
 *                     alias _#BACKLINK could be definned as
 *                       {count:{backlinks:{id..............}::-}}
 *                     or say
 *                       {count:{ids:1450a615da76cae02493aac79e129da9:d-relation........-=-{id..............}}}
 *
 *  Ussage:
 *    {tagcloud:{ids:02e34dc7f9da6473fc84ad662dfe53a}}
 *    {tagcloud:{ids:02e34dc7f9da6473fc84ad662dfe53a}:20}
 *    {tagcloud:{ids:02e34dc7f9da6473fc84ad662dfe53a::headline........}::<i>_#HEADLINK</i>}
 *    {tagcloud:{ids:02e34dc7f9da6473fc84ad662dfe53a::headline........}::headline........:computed_num....}
 *
 *
 *  The resulting HTML code is like:
 *    <ul class="tagcloud">
 *      <li class="tagcloud3">Curso</li>
 *      <li class="tagcloud1">Palabra</li>
 *      <li class="tagcloud6">Poetry</li>
 *    </ul>
 *
 *  The <li>s are marked in its class by the importance (number of use) so you
 *  can set style them. There are 8 classes tagcloud1 - tagcloud8.
 *  The styles could by:
 *
 *    <style type="text/css">
 *      ul.tagcloud li.tagcloud1 { font-size: 1.8em; font-weight: 800; }
 *      ul.tagcloud li.tagcloud2 { font-size: 1.6em; font-weight: 700; }
 *      ul.tagcloud li.tagcloud3 { font-size: 1.4em; font-weight: 600; }
 *      ul.tagcloud li.tagcloud4 { font-size: 1.2em; font-weight: 500; }
 *      ul.tagcloud li.tagcloud5 { font-size: 1.0em; font-weight: 400; }
 *      ul.tagcloud li.tagcloud6 { font-size: 0.9em; font-weight: 300; }
 *      ul.tagcloud li.tagcloud7 { font-size: 0.8em; font-weight: 200; }
 *      ul.tagcloud li.tagcloud8 { font-size: 0.7em; font-weight: 100; }
 *      ul.tagcloud              { padding: 2px; line-height: 3em; text-align: center; margin: 0; }
 *      ul.tagcloud li           { display: inline; padding: 0px; }
 *    </style>
 */
class AA_Stringexpand_Tagcloud extends AA_Stringexpand {
    /**
     * @param string $item_ids
     * @param string $count
     * @param string $alias
     * @param string $count_field
     * @return string|void
     */
    function expand($item_ids='', $count='', $alias='', $count_field='') {
        $alias       = get_if($alias, '_#HEADLINK');
        $count_field = get_if($count_field, '{count:{backlinks:{id..............}::-}}');
        $results     = array();

        $items = AA_Items::getItems(new zids(explode_ids($item_ids)));
        foreach($items as $long_id=>$item) {
            $results[$long_id] = $item->subst_alias($count_field);
        }
        arsort($results, SORT_NUMERIC);
        $ids   = array_keys($results);
        if (!($count = ($count ? min($count,count($ids)) : count($ids)))) {
            return '';
        }
        $weights = array();
        for ($i=0; $i<$count; ++$i) {
            $weights[$ids[$i]] = (int)(((float)$i / $count * 8)+1);
        }

        $ret   = '';
        foreach($items as $long_id=>$item) {
            if ($w = $weights[$long_id]) {
                $ret .= "\n<li class=\"tagcloud$w\">". $item->subst_alias($alias).'</li>' ;
            }
        }
        return '<ul class="tagcloud">'. $ret .'</ul>';
    }
}

/** Reads the content of the DOC, DOCX, PDF or ODT file in the string.
 *  You can use it for searching in the file content - store the content
 *  in the field by Computed field and the search in this field
 *    ussage:  {file2text:{file............}}
 *             {convert:{file2text:{file............}}:utf-8:windows-1250}
 */
class AA_Stringexpand_File2text extends AA_Stringexpand_Nevercache {
    /**
     * @param null $url
     * @return string|void
     */
    function expand($url=null) {
        $out = array();
        $file_name  = Files::getTmpFilename(FILE_PREFIX);
        if (preg_match('/.doc$/i',$url)) {
            if ( defined('CONV_TEXTFILTERS_DOC')) {
                $dest_file = Files::createFileFromString(expandFilenameWithHttp($url), Files::aadestinationDir(), $file_name);
                $safe_file_name=escapeshellcmd($dest_file);
                exec(str_replace('%1',$safe_file_name,CONV_TEXTFILTERS_DOC),$out);
                unlink($dest_file);
            }
        } elseif (preg_match('/.docx$/i',$url)){
            if ( defined('CONV_TEXTFILTERS_DOCX')) {
                $dest_file = Files::createFileFromString(expandFilenameWithHttp($url), Files::aadestinationDir(), $file_name);
                $safe_file_name=escapeshellcmd($dest_file);
                exec(str_replace('%1',$safe_file_name,CONV_TEXTFILTERS_DOCX),$out);
                unlink($dest_file);
            }
        } elseif (preg_match('/.odt$/i',$url)){
            if ( defined('CONV_TEXTFILTERS_ODT')) {
                $dest_file = Files::createFileFromString(expandFilenameWithHttp($url), Files::aadestinationDir(), $file_name);
                $safe_file_name=escapeshellcmd($dest_file);
                exec(str_replace('%1',$safe_file_name,CONV_TEXTFILTERS_ODT),$out);
                unlink($dest_file);
            }
        } elseif (preg_match('/.pdf$/i',$url)){
            if ( defined('CONV_TEXTFILTERS_PDF')) {
                $dest_file = Files::createFileFromString(expandFilenameWithHttp($url), Files::aadestinationDir(), $file_name);
                $safe_file_name=escapeshellcmd($dest_file);
                exec(str_replace('%1',$safe_file_name,CONV_TEXTFILTERS_PDF),$out);
                unlink($dest_file);
            }
        }
        return join("\n",$out);
    }
}

/** Returns the value at position <index> for multivalue fields
 *    {index:<field-id>[:<index>][:<item_id>][:<lang>]}
 *    {index:category........}      - return first value
 *    {index:headline........:::cz} - return first value in Czech
 *
 * @param string - field_id - id of the field in item
 * @param string - index    - integer index in multivalue array - default 0 (the first one)
 * @param string - item_id
 * @param string - lang     - if you want exact language for translated field, specify it - cz / es / en / ...
 *
 */
class AA_Stringexpand_Index extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    // not needed right now for Nevercached functions, but who knows in the future
    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     * @return bool|string
     */
    function expand($field_id='', $index=0, $item_id='', $lang='') {
        $item = $item_id ? AA_Items::getItem(new zids($item_id)) : ($this ? $this->item : null);
        return is_object($item) ? $item->getval($field_id, (int)$index + ($lang ? AA_Content::getLangNumber($lang) : 0)) : '';
    }
}

/**
 * Class AA_Stringexpand_Form
 */
class AA_Stringexpand_Form extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function
     * @param $text
     * @return string
     */
    function expand($form_id='', $ok_code='') {
        if (!$form_id OR !($form = AA_Object::load($form_id, 'AA_Form'))) {
            return '';
        }
        //return $form->getAjaxHtml($ret_code);
        AA::Stringexpander()->addRequire('aa-jslib');  // for {AA_AjaxSendForm}
        return $form->getAjaxHtml($ok_code, 'inplace');
    }
}

/**
 * Class AA_Stringexpand_Formedit
 */
class AA_Stringexpand_Formedit extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function
     * @param $text
     * @return string
     */
    function expand($form_id='', $item_id='') {
        if (!$form_id OR !($form = AA_Object::load($form_id, 'AA_Form'))) {
            return '';
        }
        if (!is_object($item = $item_id ? AA_Items::getItem(new zids($item_id)) : ($this ? $this->item : null))) {
            return '';
        }
        $form->setObject('AA_Item', $item_id, $item->getOwnerId());

        return $form->getEditFormHtml('ajax');
    }
}

/** Item manager in remote slice related to the item
 *    {manager:<slice>:<relation-field>:<item>:<code>[:<conds>[:<sort>[:<mode>[:<edit_code>]]]]}
 *        - manage a related items to the edited one - like:
 *  my_item <-- photos,
 *        where photos have relation........ field which points to item.
 *        Then you can manage the photos using
 *    {manager:45d43f4d567dd8a9f459aa51576a6ce2:relation........:{id..............}:_#ROW_EDIT}.
 *        The _#ROW_EDIT is then code in photos slice to be displayed for each
 *        photo - could contain {live} edits, ... It allows you also to add new
 *        photo with instant redraw.
 *  <mode> - which edit features present. default is all possible
 *           - N - new,
 *           - D - delete (with json you can specify also Dconfirm:<question> - see below)
 *           - S - setting (edit item in Toolbox)
 *           - E - edit - used with <edit_code> parameter
 *           - B - back - used with <edit_code> parameter
 *           - F - file upload to first file...........x or img_source.....x field
 *           - - - no manage (just view)
 *         - could be also defined as asoc array: {jsonasoc:N:Add item:D:Delete item:Dconfirm:Are you sure to delete item?}
 *  <edit_code> - code for edit - like _#EDIT_ITM - displays _#EDIT_ITM for item defined in {qss:aaedit}
 */
class AA_Stringexpand_Manager extends AA_Stringexpand_Nevercache {

    /**
     * @param string $slices
     * @param string $field
     * @param string $item_id
     * @param string $code
     * @param string $conds
     * @param string $sort
     * @param string $mode      - "SDNEF" as flags for Settings / Delete / New / Edit / FileUpload OR  {jsonasoc:N:Add item:D:Delete item} - with named buttons
     * @param string $edit_code - code for edit - like _#EDIT_ITM - displays _#EDIT_ITM for item defined in {qss:aaedit}
     * @return string
     */
    function expand($slices='', $field=null, $item_id=null, $code=null, $conds=null, $sort=null, $mode=null, $edit_code=null) {

        $hash       = get_short_hash('manager', $slices, $conds, $sort, $code, $edit_code);
        $slices_arr = explode_ids($slices);

        $mode = $mode ?: 'ND'.($edit_code ? 'EB' : ''); // add here all possible options, which is ND currently
        $mode_arr = array();

        if ($mode{0} == '{') { // we define buttons using asociative array - {jsonasoc:N:Add item:D:Delete item}
            $mode_arr = json2asoc($mode);
            if (array_key_exists('S', $mode_arr) AND !strlen($mode_arr['S'])) {
                $mode_arr['S'] = '&#x2261';
            }
            if (array_key_exists('D', $mode_arr) AND !strlen($mode_arr['D'])) {
                $mode_arr['D'] = '&#xd7;';
            }
            if (array_key_exists('Dconfirm', $mode_arr) AND !strlen($mode_arr['Dconfirm'])) {
                $mode_arr['Dconfirm'] = _m('Are you sure you want to permanently DELETE this record?');
            }
            if (array_key_exists('N', $mode_arr) AND !strlen($mode_arr['N'])) {
                $mode_arr['N'] = _m('new');
            }
            if (array_key_exists('E', $mode_arr) AND !strlen($mode_arr['E'])) {
                $mode_arr['E'] = _m('edit');
            }
            if (array_key_exists('B', $mode_arr) AND !strlen($mode_arr['B'])) {
                $mode_arr['B'] = _m('back');
            }
            if (array_key_exists('F', $mode_arr) AND !strlen($mode_arr['F'])) {
                $mode_arr['F'] = _m('drop files here or click to upload');
            }
        } else {
            if (strpos($mode, 'S') !== false) {
                $mode_arr['S'] = '&#x2261';
            }
            if (strpos($mode, 'D') !== false) {
                $mode_arr['D'] = '&#xd7;';
                $mode_arr['Dconfirm'] = _m('Are you sure you want to permanently DELETE this record?');
            }
            if (strpos($mode, 'N') !== false) {
                $mode_arr['N'] = _m('new');
            }
            if (strpos($mode, 'E') !== false) {
                $mode_arr['E'] = _m('edit');
            }
            if (strpos($mode, 'B') !== false) {
                $mode_arr['B'] = _m('back');
            }
            if (strpos($mode, 'F') !== false) {
                $mode_arr['F'] = _m('drop files here or click to upload');
            }
        }

        if ($edit_code AND is_long_id($_GET['aaedit'])) {
            // @todo check slice of edited item to match $slices
            $inner_code = StrExpand('AA_Stringexpand_Item', array($_GET['aaedit'], $edit_code));
            $footer     = $mode_arr['B'] ? '<footer><article><i class="ico back" onclick="AA_SiteEdit(\'\');">' . $mode_arr['B'] . '</i></article></footer>' : '';
        } else {

            $item_id = $item_id ?: (($this AND $this->item) ? $this->item->getId() : null);
            if ($field AND ($s = AA_Slice::getModule(reset($slices_arr))) AND $s->isField($field) AND strlen($item_id)) {
                $conds .= $conds ? "-$field-=-$item_id" : "d-$field-=-$item_id";
            }
            if (!$conds) {
                warn("No conditions");
                return '';
            }

            if (!$sort) {
                $sort = 'publish_date....';
            }

            // @todo check $slices to be from current site module
            $set = new AA_Set($slices_arr, $conds, $sort);
            $zids = $set->query();

            // load all mentioned items in one step
            AA_Items::preload($zids);

            $code_actions  = $mode_arr['E'] ? ('<article><i class="ico edit" onclick="AA_SiteEdit(\'{id..............}\');">' . $mode_arr['E'] . '</i></article>') : '';
            $code_actions .= $mode_arr['S'] ? ('<article><i class="ico setting" onclick="AA_ToolboxEdit(\'{id..............}\');">' . $mode_arr['S'] . '</i></article>') : '';
            $code_actions .= $mode_arr['D'] ? ('<article><i class="ico delete"  onclick="if (confirm(\'' . $mode_arr['Dconfirm'] . '\')) { document.querySelector(\'article[data-aa-part=&quot;' . $hash . '&quot;]\').classList.add(\'aa-updating\');  AA_SiteUpdate(\'{id..............}\',\'status_code.....\',3);}">' . $mode_arr['D'] . '</i></article>') : '';
            $footer  = $mode_arr['N'] ? ('<article><i class="ico new" onclick="document.querySelector(\'article[data-aa-part=&quot;' . $hash . '&quot;]\').classList.add(\'aa-updating\'); AA_SiteNewitem(\'' . $slices_arr[0] . '\',\'\',\'' . $field . '\',\'' . $item_id . '\');">' . $mode_arr['N'] . '</i></article>') : '';

            $list_code = '<article class="item">
                            <section class="iteminfo">
                              ' . $code . '
                            </section>
                            <section class="icogroup">
                              ' . $code_actions . '
                            </section>
                          </article>';

            // expired, ... are already filtered by query() above (if expiry_date is not in conds)
            $inner_code = AA_Stringexpand_Item::itemJoin(AA_Items::getFormatted($zids, $list_code, AA_BIN_ALL), '');

            if ($mode_arr['F']) {

                $input_name =  "aa[f_".reset($slices_arr)."][file][fil][var]";  // we are making it compatible with widget to easier file upload handling (rearangeAaFilesArray())
//              fallback would be nice, but it uses <form>, so we sometimes endup with <form> inside another <form>, which is not allowed. It is solveable (by dynamic form at thee and of page...)
//              $inner_code .= "<article class=\"item\" id=\"fileup$hash\"><form action=\".\"><input name=\"aa[all][$field][0]\" type=\"hidden\" value=\"$item_id\" /><div class=\"fallback\"><input name=\"".$input_name."[]\" type=\"file\" multiple /></div></form>".$mode_arr['F']."</article>";
                $inner_code .= "<article class=\"item\" id=\"fileup$hash\">".$mode_arr['F']."</article>";
                AA::Stringexpander()->addRequire('dropzone');

                $script2run = <<<EOT
    function AA_UploadInit(fu_id,field_id,item_id) {
        var prms = {};
        prms['aa[all]['+ field_id+'][0]'] = item_id;
        var aaDz = new Dropzone('#'+fu_id, { url: window.location.href, uploadMultiple: true, params: prms });
        aaDz.on("completemultiple", function(files) {
            var f = files[files.length-1];
            AA__SiteUpdateParts(JSON.parse(f.xhr.response));
        });
    };
EOT;
                AA::Stringexpander()->addRequire($script2run, 'AA_Req_Run');

                $dz_setup_code = "Dropzone.options.fileup$hash = { paramName: '$input_name' }; AA_UploadInit('fileup$hash','$field','$item_id');";
                if (!IsAjaxCall()) {
                    $dz_setup_code = "document.addEventListener('DOMContentLoaded', function() { $dz_setup_code });";
                }
            }

        }

        $ret  = '<article class="aa-rim relactionitem" data-aa-part="'.$hash.'">
                    <section class="itemgroup">'
            .$inner_code.'
                    </section>
                       '. ($footer ? "<footer>$footer</footer>" : '') .'
                 </article>';
        $ret .= "<script>$dz_setup_code</script>";


        AA::Stringexpander()->setDependentParts(array_merge($slices_arr, array('aaedit')), $hash, $ret);
        AA::Stringexpander()->addRequire('css-aa-system');
        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_SiteNewitem, ...
        return $ret;
    }
}


/**
 * Class AA_Stringexpand_Partdiv
 */
class AA_Stringexpand_Partdiv extends AA_Stringexpand {
    /**
     * @param string $name
     * @param string $vars
     * @param string $content
     * @return string|void
     */
    function expand($name='', $vars='', $content='') {
        if ( !$name ) {
            $ret = $content;
        } else {
            $hash = get_hash('partdiv', $name, $vars);
            $ret  = "<div class=\"aa-partdiv $name\" data-aa-part=\"$hash\">$content</div>";
            $vars = json2arr($vars);
            AA::Stringexpander()->setDependentParts($vars, $hash, $ret);
        }
        return $ret;
    }
}


/** Translation
 *  ussage: {tr:Send}
 *          {tr:found _#1 items:5} - you can use parameters _#1, _#2...
 */
class AA_Stringexpand_Tr extends AA_Stringexpand {

    /** expand function
     * @param $text
     * @return mixed|string
     */
    function expand() {
        $arg_list = func_get_args();   // must be asssigned to the variable
        $text = array_shift($arg_list);
        if (!is_null($text) AND strlen($text)) {
            if ($site = AA_Module_Site::getModule(AA::$site_id)) {
                if ($translate_slice = $site->getProperty('translate_slice')) {
                    $set  = new AA_Set(array($translate_slice), new AA_Condition('headline........', '==', $text));
                    $zids = $set->query();

                    if ($zids->count()) {
                        return AA_Items::getItem($zids[0])->f_2('headline........');
                    }
                    // if not present - translate to default language of the slice
                    $translations = AA_Slice::getModuleProperty($translate_slice,'translations');

                    $ic = new ItemContent();
                    $ic->setValue('headline........', $text, AA_Content::getLangNumber($translations[0]));
                    $ic->setSliceID($translate_slice);
                    //$ic->complete4Insert();

                    $ic->storeItem('insert');     // invalidatecache, feed
                }
            }
            // replace for {tr:found _#1 items:5}
            for ($i = count($arg_list); $i>0 ; $i--) { // backward - it solves conflict _#10 vs _#1
                $text = str_replace("_#$i", $arg_list[$i-1], $text);
            }
            return $text;
        }
        return '';
    }
}

/** better than {xlang} - returns allways valid lang code - not only in sitemodule */
class AA_Stringexpand_Aalang extends AA_Stringexpand_Nevercache {

    /** expand function */
    function expand() {
        return AA::$lang ?: strtolower(aa_substr(DEFAULT_LANG_INCLUDE,0,2));
    }
}

/** Validate */
class AA_Stringexpand_Validate extends AA_Stringexpand {

    /**
     * @return string
     */
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     * @return string
     */
    function expand() {
        // $item = $item_id ? AA_Items::getItem(new zids($item_id)) : ($this ? $this->item : null);
        if (!($item = ($this ? $this->item : null))) {
            return '';
        }
        $valid = $item->getItemContent()->validateReport();
        return json_encode($valid);
    }
}


/**
 * Class AA_Stringexpand_Aabox
 */
class AA_Stringexpand_Aabox extends AA_Stringexpand {

    /** expand function
     * @param $text
     * @return string
     */
    function expand() {
        global $sess;
        if ($sess) {
            return 'OK';
        }
        return '';
    }

}

/**
 * Class AA_Stringexpand_Generate
 */
class AA_Stringexpand_Generate extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $section - HEAD | FOOT | ???
     * @return string
     */
    function expand($section='') {
        $spotname = "<!--AAGenerate$section-->";
        AA::Stringexpander()->addPostpocess($spotname);
        return $spotname;
    }
}

/**
 * Class AA_Stringexpand_Require
 */
class AA_Stringexpand_Require extends AA_Stringexpand {
    /** expand function
     * @param $lib
     * @param $script
     * @return string
     */
    function expand($libs='', $script='') {
        $l = json2arr($libs);
        foreach($l as $lib) {
            AA::Stringexpander()->addRequire($lib);
        }
        if ($script) {
            AA::Stringexpander()->addRequire($script, 'AA_Req_Load');
        }
        return '';
    }
}

/**
 * Class AA_Stringexpand_Postreplace
 */
class AA_Stringexpand_Postreplace extends AA_Stringexpand {
    /** expand function
     * @param $search
     * @param $replace
     * @return string
     */
    function expand($search='', $replace='') {
        // not used yet - if you need it, just uncomment it and send it to SVN (I want to have free hands to adapt it for the first real ussage (REGEXP? ...) )
        // AA::Stringexpander()->addPostpocess($search, $replace);
        return '';
    }
}

/** Creates "Export to XLSX" button on the table specified by selector:
 *    {tableexport:.xlsexportable}
 *  All the JS libraries are automaticaly loaded
 *  You must using {generate:HEAD} and {generate:FOOT} in your site module!
 *  We may replace the JS by another with the same functionality in the future,
 *  so do not count on the exact library in the code. The {tableexport} code
 *  will remain functional, however
 */
class AA_Stringexpand_Tableexport extends AA_Stringexpand {
    /** expand function
     * @param $selector
     * @return string
     */
    function expand($selector='') {
        if ($selector) {
            AA::Stringexpander()->addRequire('tableexport');
            //'TableExport.prototype.charset = "charset=utf-8";' . "\n" .    // possible addition
            //'TableExport.prototype.defaultButton = "big-button";' . "\n" . // possible addition
            $script2run = "TableExport(document.querySelectorAll('$selector'),{ formats: ['xlsx'], position: 'bottom'}); \n";
            AA::Stringexpander()->addRequire($script2run, 'AA_Req_Load');
        }
        return '';
    }
}

/** Adds EU cookies message to the page
 *  needs {generate:HEAD} and {generate:FOOT} in the site module
 */
class AA_Stringexpand_Eucookies extends AA_Stringexpand {
    /** expand function
     * @param $selector
     * @return string
     */
    function expand($text='', $accept='', $more='', $link='') {
        $script2run = '';
        $conf       = array();
        if (strlen($text))   { $conf['text']   = $text;   }
        if (strlen($accept)) { $conf['accept'] = $accept; }
        if (strlen($more))   { $conf['more']   = $more;   }
        if (strlen($link))   { $conf['link']   = $link;   }

        if ($conf) {
            $json = json_encode(array('l18n'=>$conf)) ?: "''";
            $script2run .= "<script> var smart_eu_config = $json; </script>";
        }
        $script2run .= "\n<script async src=\"https://cdn.jsdelivr.net/npm/eu-cookie-law@1.0.0/dist/index.min.js\" integrity=\"sha384-Aqd1v6PXDXY8z7z5qprmEjjFMZhqAMJCovJg2HrDMhBxYpALNSDVy5htLWzMO3fJ\" crossorigin=anonymous></script>";
        AA::Stringexpander()->addRequire($script2run, 'AA_Req_Footcode');
        return '';
    }
}


/** Creates
 *    {tooltip:myinfoicon:<b>My tip</b><br>tip text}
 *  All the JS libraries are automaticaly loaded
 *  You must using {generate:HEAD} and {generate:FOOT} in your site module!
 *  We may replace the JS by another with the same functionality in the future,
 *  so do not count on the exact library in the code. The {tooltip} code
 *  will remain functional, however
 */
class AA_Stringexpand_Tooltip extends AA_Stringexpand {
    /** expand function
     * @param $selector
     * @return string
     */
    function expand($element='', $html='') {
        if ($element AND $html) {
            AA::Stringexpander()->addRequire('tippy');
            $script2run = "tippy('#".escape4js($element)."', {html: function() { el = document.createElement('div'); el.innerHTML='".escape4js($html)."'; return el; } }); \n";
            AA::Stringexpander()->addRequire($script2run, 'AA_Req_Run');
            AA::Stringexpander()->addRequire('AA_Stringexpand_Tooltip::getBottomCode', 'AA_Req_Htm');
        }
        return '';
    }

    /**
     * @return string
     */
    static function getBottomCode() {
        return '
<script>
  tippy.defaults.arrow = true;
  tippy.defaults.size = "small";
  tippy.defaults.duration = 50;
  tippy.defaults.theme = "aa";
</script>
<style>
  .tippy-popper[x-placement^=\'top\'] .tippy-tooltip.aa-theme .tippy-arrow {  border-top: 7px solid #FFFED8;  border-right: 7px solid transparent;  border-left: 7px solid transparent; }
  .tippy-popper[x-placement^=\'bottom\'] .tippy-tooltip.aa-theme .tippy-arrow {  border-bottom: 7px solid #FFFED8;  border-right: 7px solid transparent;  border-left: 7px solid transparent; }
  .tippy-popper[x-placement^=\'left\'] .tippy-tooltip.aa-theme .tippy-arrow {  border-left: 7px solid #FFFED8;  border-top: 7px solid transparent;  border-bottom: 7px solid transparent; }
  .tippy-popper[x-placement^=\'right\'] .tippy-tooltip.aa-theme .tippy-arrow {  border-right: 7px solid #FFFED8;  border-top: 7px solid transparent;  border-bottom: 7px solid transparent; }
  .tippy-tooltip.aa-theme {
    color: black;
    background-color: #FFFED8;
    box-shadow: 0 0 20px 4px rgba(154, 161, 177, 0.15), 0 4px 80px -8px rgba(36, 40, 47, 0.25), 0 4px 4px -2px rgba(91, 94, 105, 0.15);
    border: 1px solid rgba(80, 80, 80, 0.5);
  }
  .tippy-tooltip.aa-theme .tippy-backdrop { background-color: #FFFED8; }
  .tippy-tooltip.aa-theme .tippy-roundarrow { fill: #FFFED8; }
  .tippy-tooltip.aa-theme[data-animatefill] { background-color: transparent; }
  .tippy-popper > div {
     max-width: 50vw;
     text-align: left !important;
  }
</style>
';
    }
}

/** Detects mobile/tablet/desktop (and print one of the mentioned keywords)
 *    {detect}
 */
class AA_Stringexpand_Detect extends AA_Stringexpand {
    /** expand function
     * @param $test - test type - not used, yet
     * @return string  mobile|tablet|desktop|
     */
    function expand($test='') {
        $detect = new Mobile_Detect;

        //  Keep the value in $_SESSION for later use
        //    and for optimizing the speed of the code.
        // if(!$_SESSION['isMobile']){
        //     $_SESSION['isMobile'] = $detect->isMobile();
        // }

        // for future extension
        if (!$test) {
            if ($detect->isMobile()) return 'mobile';
            if ($detect->isTablet()) return 'tablet';
            return 'desktop';
        }
        return '';
    }
}


/** Detects mobile/tablet/desktop (and print one of the mentioned keywords)
 *    {detect}
 */
class AA_Stringexpand_Aadocumentation extends AA_Stringexpand {
// @todo - experimental - not finished

    /** expand function
     */
    function expand($stringexpand='') {
        if (class_exists($class_name = AA_Serializable::constructClassName($stringexpand, 'AA_Stringexpand_'))) {
            $comment_string= with(new ReflectionClass($class_name))->getDocComment();
            $explode_string= with(new ReflectionClass($class_name))->getMethod('expand')->getDocComment();
        }
        $pattern = "#(@[a-zA-Z]+\s*[a-zA-Z0-9, ()_].*)#";
        //perform the regular expression on the string provided
        preg_match_all($pattern, $comment_string, $matches1, PREG_PATTERN_ORDER);
        preg_match_all($pattern, $explode_string, $matches2, PREG_PATTERN_ORDER);

        $ret  = "<pre>". join("\n<br>", $matches1[0]). join("\n<br>", $matches1[1]). "\n<br>". $comment_string. '</pre>';
        $ret .= "<pre>". join("\n<br>", $matches2[0]). join("\n<br>", $matches2[1]). "\n<br>". $explode_string. '</pre>';
        return $ret;
    }
}


