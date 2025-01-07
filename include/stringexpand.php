<?php
/**
 *
 * PHP version 7.2+
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
 * @link      https://www.apc.org/ APC
 */


// ----------------------------------------------------------------------------
//                         stringexpand
//
// Note that this is NOT defined as a class, and is called within several other classes
// ----------------------------------------------------------------------------

// Code by Mitra based on code in existing other files

use AA\Cache\CacheStr2find;
use AA\FormArray;
use AA\IO\DB\DB_AA;
use AA\Optimize\AddDurationLog;
use AA\Util\Langs;
use AA\Util\ChangesMonitor;
use AA\Util\LogarithmIndex;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Defr\QRPlatba\QRPlatba;

require_once __DIR__ . "/perm_core.php";    // needed for GetAuthData();

/**
 * Class AA_Aliasfunc for storing functions like {_:Message_box:Update successful:green}
 */
class AA_Aliasfunc extends AA_Object implements \AA\Util\NamedInterface {

    /** @var string  */
    protected $alias;

    /** @var string */
    protected $code;

    /** @var string  */
    protected $desc;

    /** @var array  */
    protected $ussage;

    /** AA_Aliasfunc function
     * @param $alias;
     * @param $code;
     * @param $desc;
     * @param $ussage;
     */
    function __construct($alias='', $code='', $desc='', $ussage=[]) {
        $this->alias  = $alias;
        $this->code   = $code;
        $this->desc   = $desc;
        $this->ussage = $ussage;
    }

    /** allows storing form in database
     *  AA_Object's method
     * @return array
     */
    public static function getClassProperties(): array {
        return [          //           id       name       type        multi   persist validator, required, help, morehelp, example
            'alias'  => new AA_Property( 'alias',  _m("Alias"),          'string', false, true, '', true,  _m('Alias will be called as {_:&lt;Alias_name&gt;[:&lt;Possible parameters - colon separated&gt;]}'),'', 'Message_box'),
            'code'   => new AA_Property( 'code',   _m("Code"),           'text',   false, true, '', false,  _m('Code printed by the alias. Alias could have parameters and you can use it by _#P1, _#P2, ... variables'),'', '&lt;div class=mybox style="color:_#P2"&gt;_#P1&lt;/div&gt;'),
            'desc'   => new AA_Property( 'desc',   _m("Description"),    'text',   false, true, '', false),
            'ussage' => new AA_Property( 'ussage', _m("Usage example"), 'string',  true,  true, '', false, '', '', '{_:Message_box:Update successful:green}'),
        ];
    }

    // static function factoryFromForm($oowner, $otype=null)        ... could be redefined here, but we use the standard one from AA_Object
    // static function getForm($oid=null, $owner=null, $otype=null) ... could be redefined here, but we use the standard one from AA_Object

    /**
     * @inheritDoc
     * needed as title to Alias manager page /modules/site/aliases.php
     */
    public static function name(): string {
        return _m('Aliases');
    }

    /**
     * @inheritDoc
     */
    public static function description(): string {
        return '';
    }

    /** @inheritDoc */
    protected static function getAdminUrlTemplate(): string {
        return get_aa_url('modules/site/aliases.php', ['id=_#AA_ID___','module_id=_#AA_OWNER']);
    }
}


if (defined('AA_CUSTOM_DIR')) {
    include_once ("custom/". AA_CUSTOM_DIR. '/stringexpand.php');
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

/** creates array from JSON array or returns single value array if not valid json
 * @param $string
 * @param bool $do_not_filter
 * @return array|mixed
 */
function json2arr($string, $do_not_filter=false) {
    if ($string[0] !== '[') {
        $values = [$string];
    } else {
        $values = aa_json_decode($string);
    }
    return $do_not_filter ? $values : array_filter($values, 'strlen');  // strlen in order we do not remove "0"
}

/** creates array form JSON array or returns single value array if not valid json
 * @param $string
 * @return array
 */
function json2asoc($string) {
    if (!strlen($string)) {
        return [];
    }
    if ($string[0] === '{') {
        return aa_json_decode($string, true);
    }

    if ($string[0] === '[') {
        // returns "one" => "one", "two" => "two" for ["one","two"]
        $values = json2arr($string, true);
        return array_combine($values, $values);
    }
    return [];
}

/** JSON decode for UTF as well as non UTF strings
 * @param string $string to decode
 * @param bool $asoc
 * @return array|mixed
 */
function aa_json_decode($string, $asoc=false) {
    if (AA::$encoding AND (AA::$encoding !== 'utf-8')) {
        if ( ($arr = json_decode(ConvertEncoding($string, AA::$encoding),$asoc)) == null) {
            return [];
        }
        $values = ConvertEncodingDeep($arr,'utf-8', AA::$encoding);
    } else {
        $values = json_decode($string,$asoc) ?: [];
    }
    return $values;
}


/** @return string - JSON array form string where values are delimited by $delimiter.
 *  Input text must be in utf8
 */
class AA_Stringexpand_Jsonarray extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters ($delimiter could be space) */

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
 *    {jsonasoc:
 *       name:{headline........}:
 *       input_help:{subtitle........}}    // keys are trimmed, values not
 *    {input:82d37e966fcdc9d1cac49a7e49406601:text............:1:fld:{jsonasoc:name:Surname:input_help:just surname, please}}
 *  params text must be in utf8
 */
class AA_Stringexpand_Jsonasoc extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters ($delimiter could be space) */

    /**
     * @return string|void
     */
    function expand(...$arg_list) {
        if (AA::$encoding AND (AA::$encoding !== 'utf-8')) {
            $arg_list = ConvertEncodingDeep($arg_list, AA::$encoding, 'utf-8');
        }

        $i        = 0;
        $arr      = [];
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

/** @return {colonescape:val1} - escapes colon as internally used in AA. It is special case function - more internal one
 *  usage:
 *    txt:{colonescape:_#this}  - 16:45  -->  16#:45
 */
class AA_Stringexpand_Colonescape extends AA_Stringexpand_Nevercachenotrim {
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
            return [];
        }
        [$condition,$rest] = explode(')', $params, 2);
        return array_map('DeQuoteColons', array_merge([$condition], ParamExplode($rest)));
    }

    /** expand function
     * @params string - first parameter is filename, second is hints on where to find it
     */
    function expand(...$twos) {
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

/** Expands {user:xxxxxx} alias - auth user information (of current user)
 *   @param $field - field to show ('headline........', 'alerts1....BWaFs' ...).
 *                   empty for username (of current logged user)
 *                   'password' for plain text password of current user
 *                   'permission'
 *                   'role'  -> returns super|administrator|editor|author|undefined
 */
class AA_Stringexpand_User extends AA_Stringexpand {

    /** $auth_user_info caches user's information */
    private static $auth_user_info = [];

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        return serialize([self::$auth_user_info, $GLOBALS['auth']]);
    }

    /** expand function
     * @param $field
     * @return bool|mixed|null|string|string[]
     */
    function expand($field='') {
        // this is message for pagecache to NOT store views (or slices), where we use {user:xxx} alias,
        // into cache (AUTH_USER is not in cache's keyString).
        AA::Pagecache()->switchCacheNostore();
        $auth_user     = get_if($_SERVER['PHP_AUTH_USER'],AuthGetUname(),$_SERVER['REMOTE_USER']);
        switch ($field) {
            case '':         return $auth_user;
            case 'password': return $_SERVER['PHP_AUTH_PW'];
            case 'role' : // returns users permission to slice
            case 'permission' :
                if ( IfSlPerm(AA_Perm::ROLES['SUPER']['perm']) ) {
                    return 'super';
                } elseif ( IfSlPerm(AA_Perm::ROLES['ADMINISTRATOR']['perm'] ) ) {
                    return 'administrator';
                } elseif ( IfSlPerm(AA_Perm::ROLES['EDITOR']['perm'] ) ) {
                    return 'editor';
                } elseif ( IfSlPerm(AA_Perm::ROLES['AUTHOR']['perm'] ) ) {
                    return 'author';
                }
                return 'undefined';
            default:
                $user_id = (!$_SERVER['PHP_AUTH_USER'] AND !$_SERVER['REMOTE_USER']) ? AuthGetUid() : AA_Reader::name2Id(get_if($_SERVER['PHP_AUTH_USER'],$_SERVER['REMOTE_USER']));
                if ($field === 'id') {
                    return $user_id;
                }

                if ( !isset(self::$auth_user_info[$auth_user]) ) {
                    self::$auth_user_info[$auth_user] = AA_Reader::getReaderItemContent($user_id);
                }
                return GetItemFromContent(self::$auth_user_info[$auth_user])?->subst_alias($field);
        }
    }
}

/** Expands {xuser:xxxxxx} alias - auth user information (of current user)
 *   @param $field - field to show ('headline........', '_#SURNAME_' ...).
 *                   empty for username (of current logged user)
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
        $xuid  = $GLOBALS['apc_state']['xuid'];   // xuid now sholud be considered as primary
        $xuser = $GLOBALS['apc_state']['xuser'];  // there could be more than one user with the same xuser name from different slices
        if (!$xuser) {
            return '';
        }
        switch ($field) {
            case '':     return $xuser;
            case 'id':   return $xuid;
        }
        $item = AA_Items::getItem(new zids($xuid,'l'));
        return empty($item) ? '' : $item->subst_alias($field);
    }
}

/** experimental
 */
class AA_Stringexpand_Internal extends AA_Stringexpand_Nevercache {

    // not needed right now for Nevercached functions, but who knows in the future
    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param string $class_name
     * @return mixed|string
     */
    function expand($class_name='') {
        //$params = func_get_args();
        $item = $this?->item;
        if (!($item instanceof \AA_Item) OR !class_exists($class_name, false) OR !method_exists($class_name, 'internal_expand')) {
            return 'qqq';
        }
        $params = func_get_args();   // must be asssigned to the variable
        array_shift($params);        // remove class name
        array_unshift($params, $item->getItemContent());
        return call_user_func_array( [$class_name, 'internal_expand'], $params);
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
     * @param $user - usedID - user as stored in posted_by....... or edited_by....... field
     * @return string name|logintime|logintimes
     */
    function expand($user='', $property='') {
        if (!$user) {
            return '';
        }
        return match ($property) {
            'logintime' => DB_AA::select1('time', "SELECT time FROM `log`", [
                ['type', 'LOGIN'],
                ['selector', [$user, ":$user"], 'BEGIN'],   // ":$user" is for older log format - could be omitted later in 2024
            ], ['time-']) ?: '',
            'logintimes' => implode(
                '-',
                DB_AA::select('time', "SELECT time FROM `log`", [
                    ['type', 'LOGIN'],
                    ['selector', [$user, ":$user"], 'BEGIN'],   // ":$user" is for older log format - could be omitted later in 2024
                ], ['time-']),
            ),
            default => perm_username($user),
        };
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
    function expand(...$arg_list) {
        // replace inputform field
        // destroy all aliases, since the content of the variables could contain
        // aliases, but we don't want to unalias them. The _AA_ReMoVe_ string
        // will be removed in dequteColons
        return str_replace('_#','__AA_ReMoVe#', AA::Contentcache()->get('inputvar:'. implode(':',$arg_list)));
    }
}

/** Defines named expression. You can use it for creating on-line aliases, you
 *  can use it for passing parameters between views, ...
 *  The {define:name:expr} must be processed before the {var:name} is processed
 *  when the page is generated
 *  // Not implemented:
 *  //   You can use parameters with {var:name:param1:param2:...:...}
 *  //   the expression then will use _#1, _#2, ... for each parameter, just like:*
 *  //   {define:username:My name is#: _#1} and usage {var:username:Joseph}
 *  stored already in the pagecache
 *  @param $parameters - field id and other modifiers to the field
 */
class AA_Stringexpand_Define extends AA_Stringexpand_Nevercachenotrim {
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
 *  Could be also used for defining and printing the variable
 *  @usage  {var:myvar}           - prints variable defined by {define:myvar:some text} or {var:myvar:some text}
 *  @usage  {var:myvar:some text} - the same as {define:myvar:some text} but the content is printed
 *  @usage  {ifset:{var:my-items:{ids:535343732367227239}}:{({foreach:{var:my-items}:...)}} - expression, where we define the variable and the content we directly use
 *  @see AA_Stringexpand_Define for more info
 *  @param $parameters - field id and other modifiers to the field
 */
class AA_Stringexpand_Var extends AA_Stringexpand_Nevercachenotrim {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // cache is used by expand function itself

    /**
     * @param string $name
     * @return string
     */
    function expand($name='', $expression='') {
        if (!strlen($name = trim($name))) {
            return '';
        }
        if ( count(func_get_args())==2 ) {
            // going to define var

            AA::Contentcache()->set("define:$name", $expression);
        } else {
            // going to print var

            // replace inputform field
            $expression = AA::Contentcache()->get("define:$name");
            // @todo - replace parameters
        }
        return $expression;
    }
}


/** Expands {formbreak:xxxxxx:yyyy:....} alias - split of inputform into parts
 *  @param $part_name - name of the part (like 'Related Articles').
 */
class AA_Stringexpand_Formbreak extends AA_Stringexpand {

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        return serialize([$GLOBALS['g_formpart'], $GLOBALS['g_formpart_names'], $GLOBALS['g_formpart_pos']]);
    }
    /** expand function
     *
     */
    function expand(...$part_names) {
        $GLOBALS['g_formpart']++;  // Nothing to print, it just increments part counter

        if (empty($GLOBALS['g_formpart_pos'])) {
            $GLOBALS['g_formpart_pos'] = 3;  // position of the tabs - bottom and top
        }

        // You can specify also the names for next tabs (separated by ':'), which is
        // useful mainly for last tab (for which you do not have formbrake, of course
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
    function expand(...$part_names) {
        $GLOBALS['g_formpart_pos'] = 2;  // bottom
        StrExp('formbreak', $part_names);
    }
}

/** Expands {formbreaktop:xxxxxx:yyyy:....} alias - split of inputform into parts
 *  @param $part_name - name of the part (like 'Related Articles').
 */
class AA_Stringexpand_Formbreaktop extends AA_Stringexpand_Formbreak {
    /** expand function
     *
     */
    function expand(...$part_names) {
        $GLOBALS['g_formpart_pos'] = 1;  // top
        StrExp('formbreak', $part_names);
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
        $user_id = rawurlencode($user_id);
        $action  = rawurlencode($action);
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
        $user_skype_name = rawurlencode($user_skype_name);
        $action          = rawurlencode($action);
        $style           = rawurlencode($style);
        $message         = safe($message);

        // start the rendering the html output
        $output  = '<!-- Skype "My status" button https://www.skype.com/go/skypebuttons -->';
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

        // test to see if the optional elements of the params are supported. if not set them to the defaults

        if ( !($style == "0" OR $style == "1" OR $style == "2" OR $style == "3" OR $style == "4" ) ) {
            $style = $style_default ;
        }

        if ( !($action === "addfriend" OR $action === "sendim" OR $action === "call") ) {
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
        if ($type === '+share') {
            $share  = 'true';
            $width  = 165;
        }
        return "<iframe src=\"https://www.facebook.com/plugins/like.php?href=".rawurlencode($url)."&amp;width=$width&amp;layout=button_count&amp;action=like&amp;size=small&amp;show_faces=false&amp;share=$share&amp;height=$height\" width=\"$width\" height=\"$height\" style=\"border:none;overflow:hidden\" scrolling=no frameborder=0 allowTransparency=true allow=\"encrypted-media\"></iframe>";
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

/** Expands {protectmail:<email>[:<text>]} - hides mail into javascript to protect against spammers
 *  <a href="mailto:<email>"><text></a>   (but encoded in javascript)
 *
 *  @param string $email e-mail to protect
 *  @param string $text  text to be linked (if not specified, the $email is used)
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
        $ret = "<a href=\"\" id=\"$ahrefid\"></a><!--AA script generated, AA script removed-->";
        // we delete the script because when the content is redisplayed again (by {htmlajaxtoggle} for example, the script is executed again, which leads to wrong content)
        $ret .= "<script>var el=document.getElementById('$ahrefid');el.setAttribute('href','mai'+'lto:'+$mailprotect);el.insertAdjacentHTML('afterbegin',$linktext);var cs=document.currentScript; if (cs) {el.parentNode.removeChild(cs);}</script>";
        return $ret;
    }
    // another option - no need the ID
    //static function documentWrite($html, $tag='span') {
    //    $ret = "<script>var el=document.createElement('$tag'); el.innerHTML='$html'; var scrs = document.getElementsByTagName ('script'); var scr=[scrs.length-1]; scr.parentNode.insertBefore(el, scr);</script>";
    //}
}

/** Expands {lastedit:[<date_format>:[<slice_id>]]} and displays the date of last modification of any item in the slice
 *
 * @example  {lastedit:m/d/y H#:i}  Date in format 12/24/2010 14:57
 * @param string $date_format The format in which you want to see the date (see date() function of php http://php.net/date
 * @param string $slice_id    The slice which should be checked for last modification. If no slice is specified, then check all the slices
 */
class AA_Stringexpand_Lastedit extends AA_Stringexpand {

    /** expand function
     * @param string $format
     * @param string $slice_id
     * @return false|string
     */
    function expand($format='', $slice_id='') {
        $format = $format ?: 'j.n.Y';
        $where = is_long_id($slice_id) ? [['slice_id', $slice_id, 'l']] : null;
        return date($format, DB_AA::select1('last_edit', "SELECT last_edit FROM `item`", $where, ['last_edit-']) ?: 0);
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
 *
 * @example {htmltoggle:more >>>:Econnect:less <<<:Econnect is ISP for NGOs...:bottom}  It creates the link text1 (or text2) and two divs, where only one is visible at the time
 *
 *  The /javscript/aajslib.php should be included to the page
 *  (by <script src="">)
 *  @param string $switch_state_1 Default link text
 *  @param string $code_1         HTML code displayed as default (in div)
 *  @param string $switch_state_2 Link text 2
 *  @param string $code_2         HTML code displayed as alternative after clicking on the link
 *  @param string $position       Position of the link - top|bottom (top is default)
 *  @param string $persistent_id  Identifier [a-z-]* - if provided, the toggle state will be persistent between page loads
 */
class AA_Stringexpand_Htmltoggle extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    /**
     * @param string $switch_state_1
     * @param string $code_1
     * @param string $switch_state_2
     * @param string $code_2    - HTML code displayed as alternative after clicking on the link
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

        $switches = AA_Stringexpand::replaceIcons([$switch_state_1, $switch_state_2]);  // replace icons [+] [-]
        $switches_js = str_replace(["'", '"', "\n", "\r"], ["\'", "\'", ' ', ' '], $switches);

        $uniqid = gensalt(6); // gensalt is shorter than mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
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
        return (trim($position)==='bottom') ?  $ret. $link. $script: $link. $ret. $script;
    }
}

/** Expands {htmltogglecss:<toggle1>:<toggle2>:<css_rule>} like:
 *          {htmltogglecss:+:-:#id_#SITEM_ID}    (#id_#SITEM_ID should have style="display:none;" as default)
 *          {htmltogglecss:+:-:#id_#SITEM_ID:1}
 *  It creates the link text1 (or text2) +/- toggle which displays/hides all
 *  elements matching the css_rule (#id82422) in our example
 *  The /javscript/aajslib.php should be included to the page
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

        $switches = AA_Stringexpand::replaceIcons([$switch_state_1, $switch_state_2]);  // replace icons [+] [-]
        $switches_js = str_replace(["'", '"', "\n", "\r"], ["\'", "\'", ' ', ' '], $switches);

        $uniqid = gensalt(6);  // mt_rand is quicker than uniqid()

        $ret    = "<a class=\"togglelink$class\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlToggleCss('toggle_link_$uniqid', '{$switches_js[0]}', '{$switches_js[1]}', '$css_rule');return false;\">{$switches[$selected]}</a>\n";
        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_HtmlToggleCss
        return $ret;
    }
}

/** Expands {htmlajaxtogglecss:<toggle1>:<toggle2>:<css_rule_hide>:<url_of_text>[:<css_rule_update>]} like:
 *          {htmlajaxtogglecss:+:-:#id_#SITEM_ID:/apc-aa/view.php?vid=33&cmd[33]=x-33-{_#SITEM_ID}}
 *  It creates the link text1 (or text2) +/- toggle which loads+displays/hides all
 *  elements matching the css_rule (#id82422) in our example
 *  The /javscript/aajslib.php should be included to the page
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

        $switches = AA_Stringexpand::replaceIcons([$switch_state_1, $switch_state_2]);  // replace icons [+] [-]
        $switches_js = str_replace(["'", '"', "\n", "\r"], ["\'", "\'", ' ', ' '], $switches);

        // automatically add conversion to utf-8 for AA view.php calls
        // -- removed - view.php now check for AJAX call automatically
        //if ((strpos($url_of_text,'/view.php?') !== false) AND (strpos($url_of_text,'convert')===false)) {
        //    $url_of_text = get_url($url_of_text,array('convertto' => 'utf-8'));
        //}

        $uniqid = gensalt(6); // gensalt is shorter than mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $ret   = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlAjaxToggleCss('toggle_link_$uniqid', '{$switches_js[0]}', '{$switches_js[1]}', '$css_rule_hide', '$url_of_text', '$css_rule_update');return false;\">{$switches[0]}</a>\n";
        return $ret;
    }
}


/** Displays live search (search field and the list of matching articles) on html page
 *    {livesearch:<view_param>[:<placeholder>[:<default_phrase>]]}
 *    {livesearch:3650}
 *    {livesearch:3650:search...:-}
 *    {livesearch:3650&cmd[3650]=c-1-%22AA_LS_QUERY%22-2-publications:search for pubs...}
 *  It requires jQuery and aajslib-jquery.php
 *  @param $view_param      - just view ID or whole view parameters. If whole parameters used, then you have to use AA_LS_QUERY
 *                            constant on the place, where you want to put search phrase
 *                            example:
 *                              3560       // first condition used as in c-1-%22AA_LS_QUERY%22
 *                              3650&cmd[3650]=c-1-%22AA_LS_QUERY%22-2-publications
 *  @param $placeholder     - placeholder text to search field
 *  @param $default         - the phrase to search if the search field is empty.
 *                          - it is not necessary to fill it, if you want to see first the results by default
 *                          - put '-' there for empty list by default (or you can put there some nonsence value, but it is then searched)
 *
 */
class   AA_Stringexpand_Livesearch extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    /**
     * @param string $view_param
     * @param string $placeholder
     * @param string $default
     * @return string|void
     */
    function expand($view_param='', $placeholder='', $default='') {
        $placeholder = safe($placeholder);
        $def         = ($default === '-') ? 'AADoNotFindAnything' : safe($default);
        $id = 'aa-ls'.gensalt(6); // gensalt is shorter than mt_rand(100000000,999999999);
        $view_param  = is_numeric($view_param) ? "$view_param&cmd[$view_param]=c-1-%22AA_LS_QUERY%22" : $view_param;
        $ret = "
            <article class=aa-widget id=$id>
              <header>
                <form action=? onsubmit=\"AA__liveSearch('$id', '$view_param', '$def'); return false;\">
                  <input type=search class=itemsearch name=itemsearch oninput=\"AA__liveSearch('$id', '$view_param', '$def')\" placeholder=\"$placeholder\"><i class=aa-utiltag></i>
                </form>
              </header>
              <section class=itemgroup onkeydown=AA__Keynavigate>";
        $ret .= ($default === '-') ? '' : AA::Un("{view.php?vid=". str_replace('AA_LS_QUERY', $def, $view_param). "}");
        $ret .= "      </section>
            </article>";
        return $ret;
    }
}


/** Creates <div> with clickable options corresponding previously filled form values by the user
 *   - max 5 saved searches are stored (using localStore)
 *   - must be inside the form
 *  {formsaver[:<container>][:<label>]}
 *       {formsaver}               - whole form is saved (the one in which is the {formsaver})
 *       {formsaver:.my_subform}   - only the subform is saved
 *       {formsaver::Fill&nbsp;by} - prefix the searches buttons with the label (HTML could be used)
 *  @param  string $container      - css rule matching the element containing the inputs, textareas, selects
 *  @param  string $label          - the label to show, when there are some stored form
 */
class AA_Stringexpand_Formsaver extends AA_Stringexpand_Nevercache {

    /**
     * @param string $container
     * @param string $label
     * @return string
     */
    function expand($container='', $label='') {

        $uniqid = get_short_hash(shtml_url(), $container);

        AA::Stringexpander()->addRequire('form-saver');  // for form-saver.js, jquery, deserialize
        AA::Stringexpander()->addRequire('css-aa-system');
        $script2run = "FormSaver('$uniqid','$container','".escape4js($label)."'); \n";
        AA::Stringexpander()->addRequire($script2run, 'AA_Req_Run');
        return "<div class=aa-form-saver id=aa-fs-$uniqid></div>";
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
class AA_Stringexpand_Shorten extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $add parameter could contain space) */

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
        $ret = aa_substr($text, 0, $length / 2 - 1) . '…';
        return $ret . aa_substr($text, aa_strlen($ret) - $length);
    }

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
                $shorted_modif = aa_substr(trim(strip_tags(str_ireplace(['</p>', '<p>', '<br>', '<br />', '<br/>'], ['~@|^1', '~@|^1', '~@|^1', '~@|^1', '~@|^1'], $text))), 0, $length);
                if ( (($paraend = aa_strrpos($shorted_modif, '~@|^1')) > $length/2) ) {  // half is minimum
                    $shorted_text = str_replace('~@|^1',"\n",aa_substr($shorted_modif, 0, $paraend));
                    break;
                }

                $shorted_text = aa_substr($strip_text, 0, $length);
                $SEARCH_CRIT = [
                    ["\n", 2,0], ["\r", 2,0], ['.', 2,1], ["\n", 5,0], ["\r", 5,0], ['.', 5,1],
                    [' ', 10,0],
                ];
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
 *  The /javscript/aajslib.php should be included to the page
 *  (by <script src="">)
 *  @param $text           - default link text
 *  @param $length         - HTML code displayed as default (in div)
 *  @param $add            - add this to shortened text
 *  @param $more           - "see all text" link text
 *  @param $less           - "hide" link text
 */
class AA_Stringexpand_Expandable extends AA_Stringexpand_Nevercachenotrim {
    // Never cache this code, since we need unique divs with uniqid()
    /** Do not trim all parameters (the $add parameter could contain space) */

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

        $switches = AA_Stringexpand::replaceIcons([$switch_state_1, $switch_state_2]);  // replace icons [+] [-]
        $switches_js = str_replace(["'", '"', "\n", "\r"], ["\'", "\'", ' ', ' '], $switches);

        $uniqid = gensalt(6); // gensalt is shorter than mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()

        if (strlen(strip_tags($text))<=$length) {
            $ret = "<div class=\"expandableclass\" id=\"expandable_1_$uniqid\">$text</div>\n";
        } else {
            $text_2 = StrExp('shorten', [$text, $length]);
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
 *  The /apc-aa/javascript/aajslib.php should be included to the page
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

        $switches = AA_Stringexpand::replaceIcons([$switch_state_1, $switch_state_2]);  // replace icons [+] [-]
        $switches_js = str_replace(["'", '"', "\n", "\r"], ["\'", "\'", ' ', ' '], $switches);

        // automatically add conversion to utf-8 for AA view.php calls
        // -- removed - view.php now check for AJAX call automatically
        //if ((strpos($url,'/view.php?') !== false) AND (strpos($url,'convert')===false)) {
        //    $url = get_url($url,array('convertto' => 'utf-8'));
        //}

        $uniqid = gensalt(6); // gensalt is shorter than mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $link   = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlAjaxToggle('toggle_link_$uniqid', '{$switches_js[0]}', 'toggle_1_$uniqid', '{$switches_js[1]}', 'toggle_2_$uniqid', '$url');return false;\">{$switches[0]}</a>\n";
        $ret    = "<div class=\"toggleclass\" id=\"toggle_1_$uniqid\">$code_1</div>\n";
        $ret   .= "<div class=\"toggleclass\" id=\"toggle_2_$uniqid\" style=\"display:none;\"></div>\n";
        return (trim($position)==='bottom') ?  $ret. $link : $link. $ret;
    }
}


/** Expands {lazy:<url_of_text2>[:<placeholder>]} like:
 *          {lazy:/apc-aa/view.php?vid=22}
 *  displays <placeholder>, but when user scroll the placeholder to the screen, it loads view by ajax
 *  @param $url            - url, which will be called by AJAX and displayed
 *                           on demand (click on the link)
 * @param $placeholder       - the HTML code for the div before load
 * @param $mode              - 'nolazy' for direct load - {lazy:/apc-aa/view.php?vid=15::nolazy}, empty for lazy load (could be more modes in the future)
 */
class AA_Stringexpand_Lazy extends AA_Stringexpand_Nevercache { // do not cache - we use another unique id

    /**
     * @param string $url
     * @param string $placeholder
     * @param string $mode - 'nolazy' for direct load - {lazy:/apc-aa/view.php?vid=15::nolazy}
     *                     - 'now'    for lazy load with immediate start (no Intersection observer)
     * @return string
     */
    function expand($url='', $placeholder='', $mode='') {
        if (!strlen($url)) {
            return '';
        }
        $uniqid = gensalt(8); // gensalt is shorter than mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()

        if ($mode === 'nolazy') {
            return  "<div class=aa-lazydiv-nolazy id=lazy_$uniqid data-aa-url=\"$url\">". StrExp('include',[$url,'ajax']) ."</div>\n";   // ajax for the same parameters as lazy variant
        }

        $ret = "<div class=".(($mode === 'now') ? 'aa-lazydiv-now' : 'aa-lazydiv' ). " id=lazy_$uniqid data-aa-url=\"$url\">";

        $placeholder = $placeholder ?: '[*]';
        [$placeholder] = AA_Stringexpand::replaceIcons([$placeholder],['*']);  // replace icon [*]

        $ret    .= "$placeholder</div>\n";

        $script2run = /** @lang JavaScript */
            <<<EOT
            function AA__LoadLazyCallback(ens, ob) {
                ens.forEach(en => {
                    if ( en.isIntersecting) {
                        const target = en.target;
                        AA__LoadLazyDiv(target);
                        ob.unobserve(target);
                    }
                });
            }
            function AA__LoadLazyDiv(el) {
                el.classList.add('aa-updating');
                AA_Load(el, el.dataset.aaUrl, {method: "POST", headers: {"X-Requested-With": "XMLHttpRequest"}})
                  .catch(error => console.error(error))
                  .finally(() => el.classList.remove('aa-updating'));
            }
            AA_$$('.aa-lazydiv-now').forEach(el => AA__LoadLazyDiv(el));
            const observer = new IntersectionObserver(AA__LoadLazyCallback, { rootMargin: "0px 0px 200px 0px"});
            AA_$$('.aa-lazydiv').forEach(el => observer.observe(el));
            EOT;


        AA::Stringexpander()->addRequire('aa-jslib');  // for {live...} and now also for AA_$, AA_$$, AA_Load
        AA::Stringexpander()->addRequire($script2run, 'AA_Req_Run');
        AA::Stringexpander()->addRequire('css-aa-system');
        return $ret;
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
        $exp = str_replace(['{', '}', ' ',"\t", "\r", "\n", '&#8239;', ',', '(+', '(-', '(*', '(/', '(%', '(&', '(|', '+)', '-)', '*)', '/)', '%)', '&)', '|)', '()', '++', '--', '**'], ['(', ')', '', '', '', '', '', '.', '(0+', '(0-', '(0*', '(0/', '(0%', '(0&', '(0|', '+0)', '-0)', '*0)', '/0)', '%0)', '&0)', '|0)', '0', '+', '-', '*'], $exp, $replaces);
    }
    if (strspn($exp, '0123456789.+-*/%&|()E') !== strlen($exp)) {
        return 'wrong characters';
    }
    try {
        $ret = @eval("return $exp;");
    } catch (ParseError $e) {
        return "Math Parse Err: $exp";
    } catch (DivisionByZeroError $e) {
        return "Math Division by zero Err: $exp";
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
    if ( (aa_substr($out,0,5) === "list:") ) {
        $out = '@'. aa_substr($out,5);
    }

    $params     = [];
    $format_str = '';
    // @field........... - without parameters
    if (aa_strpos($out, ":") === false) {
        $field = aa_substr($out, 1);
        $separator = ", "; // default separator
    } else { // with parameters
        // get field name
        $field = aa_substr($out, 1, aa_strpos($out, ":") - aa_strpos($out, "@")-1);
        // parameters - first is separator, second is format string
        [$separator,$format_str] = ParamExplode(aa_substr($out,aa_strpos($out,":")+1));

        if (aa_strpos($field, "(") !== false) { // if we have special parameters - in () after field name
            // get this special parameters
            $param  = aa_substr($field, aa_strpos($field, "(")+1,aa_strpos($field, ")")-aa_strpos($field, "(")-1);
            $params = explode(",",$param);
            // field name
            $field    = aa_substr($field, 0, aa_strpos($field, "("));
            $group_id = AA_Slice::getModule($item->getSliceID())?->getFields()->getCategoryGroup($field);
        }
    }

    // get itemcontent object
    $itemcontent = $item->getItemContent();

    $val = [];
    // special - {@fieldlist...} - lists all the fields
    // (good for automatic CSV generation, for example:
    //        Odd HTML is: {@fieldlist(_#CSV_FMTD):,:_#1}, where
    //        _#CSV_FMTD is defined as f_t and with parameter: {alias:{loop............}:f_t::csv}.
    if ( $field === 'fieldlist' ) {
        $val = AA_Slice::getModule($item->getSliceID())->getFields()->getPriorityArray();
    } else {
        $val = $itemcontent->getValuesArray($field);
    }

    if ( empty($val) ) {
        return '';
    }

    $ret_str = '';
    if (!$format_str) {
        if ($separator==='json') {
            // we want JSON encoded array [value1,value2]
            $ret_str = json_encode($val);
        } else {
            // we don't have format string, so we return
            // separated values by $separator (default is ", ")
            $ret_str = implode($separator, array_filter(array_map('trim',$val), 'strlen'));
        }
    } else { // we have format string
        if ( !$params ) {
            // case if we have only one parameter for substitution
            $ret_str = implode($separator,  array_filter( array_map( static fn ($value) => str_replace('_#1', $value, $format_str), $val), 'strlen'));

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
                foreach ($params as $i => $iValue) { // for every special parameter do:
                    $par = '';
                    if (aa_substr($iValue,0,6) === "const_") {
                        // what we need some constants parameters ( like name, short_id, value, ...)
                        $what = aa_substr($iValue, aa_strpos($iValue, "_")+1);
                        if ($what === 'value') {
                            $par = $value; // value is in $item, no need to use db
                        } else {
                            // for something else we need use db
                            $par = getConstantValue($group_id, $what, $value);
                        }
                    } elseif (aa_substr($iValue,0,2) === "_#") { // special parameter is alias
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
                        $par = $item->get_alias_subst($iValue, null, 'loop............');
                    }
                    $dummy = str_replace("_#".($i+1), $par, $dummy);
                }
                $ret_str  .= $val_delim.$dummy;
                $val_delim = $separator;
            }
        }
    }
    return $ret_str;
}

/**
 * @param $group
 * @param $what
 * @param $field_name
 *  @return $what (name, value, short_id,...) of constants with group $group and name $field_name)
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
            $val = AA::Contentcache()->get_result("GetConstants", [$group, 'pri', $what]);
            return $val[$field_name];
        default :
            if (strlen($what)) {
                $val = AA::Contentcache()->get_result("GetConstants", [$group, 'pri', 'short_id']);
                $cid = $val[$field_name];
                if ($cid) {
                    $content = GetConstantContent(new zids($cid, 's'));
                    return (new AA_Item($content[$cid], new AA_Aliases(new AA_Aliasgenerator_Constant())))->subst_alias($what);
                }
            }
            return false;
    }
}

/** How unaliasing and QuoteColons() works
 *
 *  The unaliasing works this way:
 *
 *    Ex: some text {ifset:{_#HEADLINE}:<h1>_#1</h1>} here
 *    //  say that headline is "I'm headline (with {brackets})")
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

$UNQUOTED_ARRAY = [":", "(", ")", "{", "}"];
$QUOTED_ARRAY   = ["|~@_a", "|~@_b", "|~@_c", "|~@_d", "|~@_e", "_AA_ReMoVe"];

/** QuoteColons function
 *  Substitutes all colons and other special syntax characters with special AA
 *  string. Used to mark characters :{}() which are content, not syntax elements
 * @param $text string
 */
function QuoteColons($text): string {
    return str_replace($GLOBALS['UNQUOTED_ARRAY'], $GLOBALS['QUOTED_ARRAY'], $text);
}


/** DeQuoteColons function
 *  Substitutes special AA 'colon' string back to colon ':' character
 *  Used for parameters, where is no need colons are not parameter separators
 * @param $text string
 */
function DeQuoteColons($text): string {
    return str_replace($GLOBALS['QUOTED_ARRAY'], $GLOBALS['UNQUOTED_ARRAY'], $text);
}

/* just for speedup stringexpand parameter parsing */
/**
 * @param $text string
 */
function DeQuoteColonsTrim($text): string {
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
 *    {math:<expression>[:<decimals>[:<decimal point character>:<thousands separator>[:<emptytext>]]]}
 *    {math:1+1-(2*6)}          - calculates the expression
 *    {math:1 421,823 }         - returns number usable for calculation - 1421.823
 *    {math:478778:1:,: }
 *    {math:478778:bool}        - returns Yes or No (translated by language file)
 *    {math:{number..........}:1:,: :--}  - show -- if the number.......... is not filled (or is filled with spaces)
 *    {math:478778:1:,:&#8239;} - correct French SI type of number format with thin space
 *    {math:478778:1:FR}        - correct French SI type of number format with thin space
 *    {math:478778:1:EN}        - correct English SI type of number format with thin space and decimal point
 *    {math:1234567.89:2:US}    - English type of number format with coma and decimal point (1,234,567.89)
 */
class AA_Stringexpand_Math extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $add parameter could contain space) */

    /** expand function  */
    function expand($expression='', $decimals='', $dec_point='', $thousands_sep = '', $emptytext='') {
        if (strlen($emptytext) AND !strlen(trim($expression))) {
            return $emptytext;
        }
        $ret = (double)calculate($expression);
        if ($decimals === 'bool') {
            return $ret ? _m('Yes') : _m('No');
        }
        if ( !empty($dec_point) OR !empty($thousands_sep) ) {
            $decimals      = get_if($decimals,0);
            switch ($dec_point) {
                case 'FR':
                    $dec_point = ',';
                    $thousands_sep = '&#8239;';  // non-breaking thin space
                    break;
                case 'EN':
                    $dec_point = '.';
                    $thousands_sep = '&#8239;';  // non-breaking thin space
                    break;
                case 'US':
                    $dec_point = '.';
                    $thousands_sep = ',';
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

/** {specialstring:BOM}
 *  @returns string - special string - currently only utf-8 BOM (Byte order mark) character is supported
 */
class AA_Stringexpand_Specialstring extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($type='') {
        return $type === 'BOM' ? chr(239).chr(187).chr(191) : '';
    }
}


/** {intersect:<ids>:<ids>}
 * @returns string - set of intersect of two set of ids (dash separated).
 *                   The order of the return strings is stable - it follows the first set - $set1
 */
class AA_Stringexpand_Intersect extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($set1='', $set2='') {
        return implode('-', array_intersect(explode_ids($set1),explode_ids($set2)));
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
        return implode('-', array_diff(explode_ids($set1),explode_ids($set2)));
    }
}

/** (Current) date with specified date format {date:[<format>[:<timestamp>]]}
 *   {date:j.n.Y}                               displays 24.12.2008 on X-mas 2008
 *   {date:Y}                                   current year
 *   {date:m/d/Y:{math:{_#PUB_DATE}+(24*3600)}} day after publish date
 *   {date}                                     current timestamp
 *   {date:j.n.Y:_#this:--}
 *
 *   @param $format       - format - the same as PHP date() function
 *   @param $timestamp    - timestamp of the date (if not specified, current time
 *                         is used)
 *                         The parameter could be also in yyyy-mm-dd hh:mm:ss
 *                         format
 *   @param $no_date_text - text, displayed for the unset date
 *   @param $zone         - 'GMT' - if the time should be recounted to GMT
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
        if ( !is_null($no_date_text) AND self::isEmptyDate($timestamp)) {
            return $no_date_text;
        }
        if ( $timestamp=='' ) {
            $timestamp = time();
        } elseif ( !IsSigInt((string)$timestamp) ) {
            $timestamp = strtotime($timestamp);
        }
        return ($zone!=='GMT') ? date($format, (int)$timestamp) : gmdate($format, (int)$timestamp);
    }

    /**
     * @param $timestamp
     * @return bool
     */
    static function isEmptyDate($timestamp): bool {
        return ((trim($timestamp,'0')=='') OR (IsSigInt((string)$timestamp) AND abs($timestamp) < 86400));
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
        return StrExp('date', [$format,$timestamp]);
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

/** @return string rewrites list of values to range
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
        $ranges = [];
        $vals    = explode('-',$intvalues);
        natsort($vals);
        $from    = null;
        $last    = null;
        foreach ($vals as $val) {
            if (is_null($from)) {
                $from = $val;
            } elseif ($val != $last+1) {
                $ranges[] = ($from == $last) ? $from : $from.'-'.$last;
                $from = $val;
            }
            $last = $val;
        }
        if (!is_null($from)) {
            $ranges[] = ($from == $last) ? $from : $from.'-'.$last;
        }
        return implode(', ',$ranges);
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
        $start_timestamp = (int)$start_timestamp;
        $end_timestamp   = (int)$end_timestamp;

        $starttime = (($time = date("G:i", $start_timestamp)) != "0:00") ? " $time" : '';
        $endtime   = ( (($time = date("G:i", $end_timestamp)) != "0:00") AND ($time != "23:59") ) ? $time : '';


        if ( date("j.n.$year_format", $start_timestamp) == date("j.n.$year_format", $end_timestamp) ) {
            $ret = date("j. n. $year_format", $start_timestamp). $starttime;
            if ($endtime AND ($starttime !== " $endtime")) {
                $ret .= $starttime ? "-$endtime" : " 0:00-$endtime";
            }
        } elseif ( date("Y", $start_timestamp) == date("Y", $end_timestamp) ) {
            $ret = date("j. n.", $start_timestamp). $starttime. '-'. date("j. n. $year_format", $end_timestamp). " $endtime";
        } else {
            $ret = date("j. n. $year_format", $start_timestamp). $starttime. '-'. date("j. n. $year_format", $end_timestamp). " $endtime";
        }

        return str_replace([' ','-'], ["\u{202F}","\u{2013}"], trim($ret));  // use &nnbsp; (narrow non-breaking space) and @ndash; UTF characters - possible also use &#8239;
    }
}

/** Calculates date difference - like age
 *   {datediff:<start_timestamp>[:<end_timestamp>[:<format>]]}
 *   {datediff:{start_date......}} - displays "42" (years)
 *   {datediff:{start_date......},{expiry_date.....},%R%a} - displays "+897" (days) - @see
 *
 *   @param $start_timestamp - timestamp of the start date
 *   @param $end_timestamp   - timestamp of the end date - {now} is default
 *   @param $format          - format - %y (for years) is default - other values @see PHP date_diff()->format()
 */
class AA_Stringexpand_Datediff extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($start_timestamp='', $end_timestamp='', $format='%y') {
        $date1 = (new DateTime())->setTimestamp((int)$start_timestamp);
        $date2 = (new DateTime())->setTimestamp(($end_timestamp == '') ? time() : (int)$end_timestamp);
        return date_diff($date1, $date2)->format(($format == '') ? '%y' : $format);
    }
}

/** Distance between two Latitude/Longitude coordinates on Earth in meters
 *   {distance:<lat1>:<lon1>:<lat2>:<lon2>}
 *   {distance:50.0898689:14.4000936:48.8940964:13.8241719}
 *   @param $lat1 - Latitude of first coordinate
 *   @param $lon1 - Longitude of first coordinate
 *   @param $lat2 - Latitude of second coordinate
 *   @param $lon2 - Longitude of second coordinate
 */
class AA_Stringexpand_Distance extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($lat1='', $lon1='', $lat2='', $lon2='') {
        $EARTHRADIUS = 6371000;
        // convert from degrees to radians
        $latFrom = deg2rad(calculate($lat1));
        $lonFrom = deg2rad(calculate($lon1));
        $latTo   = deg2rad(calculate($lat2));
        $lonTo   = deg2rad(calculate($lon2));
        //huhl($lat1, $latFrom, $lon1, $lonFrom, $lat2, $latTo, $lon2, $lonTo);
        return (int)($EARTHRADIUS * 2 * asin(sqrt((sin(($latTo - $latFrom) / 2) ** 2) + cos($latFrom) * cos($latTo) * (sin(($lonTo - $lonFrom) / 2) ** 2))));
    }
}

/** Converts GPS coordinates to decimal Latitude / Longitude
 *   {gps:<any-gps>[:<format>]}
 *   @example {gps: 49°58'4.508"N, 12°30'10.790"E}        --> 49.967918888889,12.502997222222
 *   @example {gps: 49°58'4.508"N, 12°30'10.790"E:_#lat}  --> 49.967918888889
 *   @example {gps:-50.09697 14.42332:_#lon}              --> 14.42332
 *   @param string $gps    - gps coords in different formats - 49.9679189N, 12.5029972E; N 49°58.07513', E 12°30.17983'; 49°58'4.508"N, 12°30'10.790"E; 41.40338, 2.17403; 41°24'12.2"N 2°10'26.5"E; later maybe 3CHX+F6 Praha,...
 *   @param string $format - any string containing _#lat and/or _#lon strings, which will be replaced by Latitude and Longitude
 */
class AA_Stringexpand_Gps extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($gps='', $format='') {
        $lat = $lon = '';
        // -50.09697 14.423321
        // 50.090802N,14.400522E
        // 14.400522E 50.090802N
        if (preg_match('/^([-+])?\s?(\d*[.,]?\d+)\s?([NWSE]?)[,\s]+([-+])?\s?(\d*[.,]?\d+)\s?([NWSE]?)$/', aa_trim($gps), $p)) {
            $num1 = (($p[1] === '-' OR strtoupper($p[3]) === 'S' OR strtoupper($p[3]) === 'W') ? '-' : '') .(float)$p[2];
            $num2 = (($p[4] === '-' OR strtoupper($p[6]) === 'S' OR strtoupper($p[6]) === 'W') ? '-' : '') .(float)$p[5];
            [$lat, $lon] = (in_array(strtoupper($p[3]), ['E','W'])) ? [$num2, $num1] : [$num1, $num2];
        }
        // 49°58'4.508"N, 12°30'10.790"E
        // 12° 30' 10.790'' E 49° 58' 4.508'' N
        // 49°58'4.508"N, 12°30'10.790"E
        // -49°58'4.508"N, -12°30'10.790"E
        // 49°58'4.508"S, 12°30'10.790"W
        elseif ( preg_match('/^([-+])?(\d*[.,]?\d+)°(?:(\d*[.,]?\d+)\')?(?:(\d*[.,]?\d+)(?:"|\'\'))?([NWSE]?),?([-+])?(\d*[.,]?\d+)°(?:(\d*[.,]?\d+)\')?(?:(\d*[.,]?\d+)(?:"|\'\'))?([NWSE]?)$/u', aa_html_entity_decode(inner_trim($gps)), $p) ) {
            $num1 = (($p[1] === '-' OR strtoupper($p[5]) === 'S' OR strtoupper($p[5]) === 'W') ? '-' : '') .((float)$p[2] + ((float)$p[3]/60) + ((float)$p[4]/3600));
            $num2 = (($p[6] === '-' OR strtoupper($p[10]) === 'S' OR strtoupper($p[10]) === 'W') ? '-' : '') .((float)$p[7] + ((float)$p[8]/60) + ((float)$p[9]/3600));
            [$lat, $lon] = (in_array(strtoupper($p[5]), ['E','W'])) ? [$num2, $num1] : [$num1, $num2];
        }

        if ($format == '') {
            $arr = [];
            if ($lat !== '') {
                $arr[] = '_#lat';
            }
            if ($lon !== '') {
                $arr[] = '_#lon';
            }
            $format = implode(',',$arr);
        }
        return str_ireplace(['_#lat','_#lon'],[$lat,$lon],$format);
    }
}

/**
 * Class AA_Stringexpand_Substr
 */
class AA_Stringexpand_Substr extends AA_Stringexpand_Nevercachenotrim {

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
 *    {limit:{ids:6353428288a923:d-category........-==-Dodo}:0:1}
 *  offset and length parameters follows the array_slice() PHP function
 *  @see http://php.net/array_slice
 */
class AA_Stringexpand_Limit extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids // parts separated by $delimiter
     * @param $offset // start index (first is 0). Could be negative.
     * @param $length // default is "to the end of the list". Could be negative
     * @param $delimiter // default is '-'
     * @return string
     */
    function expand($ids='', $offset='', $length='', $delimiter='-') {
        // cut off spaces well as delimiters
        $arr = explode($delimiter, trim($ids, " \t\n\r\0\x0B\xA0" .((strlen($delimiter) == 1) ? $delimiter : '')));
        $offset = (int)$offset;
        $arr = ($length === '') ? array_slice($arr, $offset) : array_slice($arr, $offset, (int)$length);
        return implode($delimiter, $arr);
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
        $arr = $this->do_change_array($arr);
        if ($limit) {
            $arr = array_slice($arr, 0, (int)$limit);
        }
        return implode('-', $arr);
    }

    protected function do_change_array(array $arr): array {
        shuffle($arr);
        return $arr;
    }
}

/** reverse the order of ids
 *    {reverse:<ids>[:<limit>]}
 */
class AA_Stringexpand_Reverse extends AA_Stringexpand_Shuffle {

    /** uses the same code as shuffle */

    protected function do_change_array(array $arr): array {
        return array_reverse($arr);
    }
}

/** sorts the values
 *    {sort:<values>[:<order-type>[:<unique>[:<delimiter>[:<limit>]]]}
 */
class AA_Stringexpand_Sort extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */

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
            $arr   = array_slice($arr,0,(int)$limit);
            if (strlen($add)) {
                $arr[] = $add;
            }
        }
        $s = array_slice([2,3],'1','1');

        return implode($delimiter, $arr);
    }
}


/** Next item for the current item in the list
 *    {next:<ids>:<current_id>}
 *    {next:12324-353443-58921:353443}   // returns 58921
 *    {next:{ids:6353428288a923:d-category........-==-Dodo}:566a655e7787b564b8b6565b}
 */
class AA_Stringexpand_Next extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /**
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
class AA_Stringexpand_Unique extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */

    /**
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
            if ($ids[0] === '[') {
                return json_encode(array_values(array_unique(json2arr($ids))));
            }
            $delimiter = '-';
        }
        return implode($delimiter, array_unique(array_filter(explode($delimiter, $ids),'trim')));
    }
}


/** Lookup -
 *    {lookup:<value>:<values>:<delimiter>:<text>}
 *    {lookup:10:5-8-10-12-15} - returns 3 (_#pos)
 *    {lookup:10:5-8-10-12-15:-:_#pos / _#count}  - returns 3 / 5 (_#pos)
 */
class AA_Stringexpand_Lookup extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */

    /**
     * @param $value  // value to look for
     * @param $values // values to be searched
     * @param $delimiter // separator of the values - by default it is '-' or json (if it starts with '[') but you can use any one
     * @param $text // returning text containing _#pos (position found in values 1-count), _#key (the same as _#pos starting with 0), _#count (number of values)
     * @return string
     */
    function expand($value='', $values='', $delimiter='', $text='_#pos') {
        if (!($values = trim($values))) {
            return '';
        }
        $values = AA_Stringexpand::explode2array($values, $delimiter);
        if (false === ($key = array_search($value, $values))) {
            return '';
        }
        return str_replace(['_#pos', '_#key', '_#count'], [$key+1, $key, count($values)], $text ?: '_#pos');
    }
}


/** Counts ids or other string parts separated by delimiter
 *  It is much quicker to use this function for counting of ids, than
 *  {aggregate:count..} since this one do not grab the item data from
 *  the database
 *    {count:<ids>[:<delimiter>]}
 *    {count:12324-353443-58921}   // returns 3
 *    {count:{ids:6353428288a923:d-category........-==-Dodo}}
 */
class AA_Stringexpand_Count extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */

    /**
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
 *    {previous:{ids:6353428288a923:d-category........-==-Dodo}:566a655e7787b564b8b6565b}
 */
class AA_Stringexpand_Previous extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /**
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
function Csv_escape($text, $char=',') {
    return (strcspn($text, $char."\"\n\r") === strlen($text)) ? $text : '"'.str_replace('"', '""', str_replace("\r\n", "\n", $text)).'"';
}

/**
 * Class AA_Stringexpand_Csv
 * @usage {csv:<text>[:<char>]}
 *        {csv:{abstract........}:;};{csv...
 */
class AA_Stringexpand_Csv extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param string $text - text to escape
     * @param string $char - separator character (default is ',')
     * @return string
     */
    function expand($text='', $char='') {
        return Csv_escape($text, (strlen($char)==1) ? $char : ',');
    }
}

/** Escapes the XML special chars (>,<,&,",') which could be used in XML attribute as well as in text
 */
class AA_Stringexpand_Safexml extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text string - string in UTF-8 to escape
     * @return string
     */
    function expand($text='') {
        // @todo it probably should escape also vertical tab \xB ...
        return safexml($text);
    }
}

/** Pretty format XML
 * @usage {xmlformat:xml}
 */
class AA_Stringexpand_Xmlformat extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $xml string
     * @return string
     */
    function expand($xml = '') {
        $dom = new DOMDocument();
        // must be before load xml string
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput       = true;

        $dom->loadXML($xml);
        return $dom->saveXML();
    }
}

/** @usage {xmlfield:name:headline........}               -> <name>John</name>
 *         {xmlfield:name:headline........}               -> <name lang="cs" format="plain">Jan</name><name lang="en" format="plain">John</name>  - for translated field and displayed formatters plain|html
 *         {xmlfield:category:category........}           -> <category>white</category> <category>black</category>
 *         {xmlfield:categories value:category........}   -> <categories><value>white</value> <value>black</value><categories>
 *         {xmlfield:name:category........}               -> <categories><value>white</value> <value>black</value><categories>
 */
class AA_Stringexpand_Xmlfield extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text string - string in UTF-8 to escape
     * @return string
     */
    function expand($tags='', $field_id='') {
        $ret = '';
        [$around_tag, $tag] = strpos($tags, ' ') ? explode(' ', $tags) : ['',$tags];

        $item = $this?->item;
        $atrs = [];
        if (is_object($item)) {
            if ($slice = AA_Slice::getModule($item->getSliceID())) {
                if ($field = $slice->getField($field_id)) {
                    $values = $item->getAaValue($field_id);
                    if ($field->getFormatters()) {  // if a user have choice
                        $atrs['format'] = AA_Formatters::getType($values->getFormatter());
                    }
                    // $multival = $field->isMultiple();
                    if ( $field->isMultilingual() ) {
                        $values->fixTranslations($slice->getTranslations());
                        foreach ($values as $ind => $val) {
                            if (strlen($val) OR ($field->getType()==='date' AND !AA_Stringexpand_Date::isEmptyDate($val))) {
                                $ret .= xmltag($tag, $val, $atrs + ['lang' => Langs::getLangNum2Code($ind)]);
                            }
                        }
                    } else {
                        foreach ($values as $val) {
                            if (strlen($val)) {
                                $ret .= xmltag($tag, $val, $atrs);
                            }
                        }
                    }
                }
            }
        }
        return ($around_tag AND $ret) ? "<$around_tag>$ret</$around_tag>" : $ret;
    }
}

/** Escapes the HTML special chars (>,<,&,...) and also prevents to double_encode
 *  already encoded entities (like &amp;quote;) - as opposite to {htmlspecialchars}
 */
class AA_Stringexpand_Safe extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text string - to escape
     * @return string
     */
    function expand($text='') {
        return safe($text);
    }
}

/** AA counterpart of PHP htmlspecialchars()
 * @deprecated - use {safe:...} instead
 */
class AA_Stringexpand_Htmlspecialchars extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return safe($string);
    }
}


/** generates ascii only username or filename from the string */
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

/** Encodes string for JSON - (quotes, newlines) ' => \', ... and converts to utf-8 if it is not
 * could be used also for <div onclick="SetText( {safe:{jsonstring:{_#FULLTEXT}}} );">
 **/
class AA_Stringexpand_Jsonstring extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $string
     * @return string
     */
    function expand($string='') {
        if (AA::$encoding && (AA::$encoding !== 'utf-8')) {
            return json_encode(ConvertEncoding($string, AA::$encoding));
        }
        return json_encode($string);
    }
}

/** Returns Text as is.
 *  Looks funny, but it is useful. If you write {abstract........}, then it
 *  is NOT the same as {asis:abstract........}, since {abstract........} counts
 *  with HTML/plaintext setting, so maybe the \n are replaced by <br> if
 *  "plaintext" is set for the field. The {asis:abstract........} returns the
 *  exact value as inserted in the database
 */
class AA_Stringexpand_Asis extends AA_Stringexpand_Nevercachenotrim {

    // not needed right now for Nevercached functions, but who knows in the future
    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     * @return bool|string
     */
    function expand($text='') {
        $params = func_get_args();
        $item   = $this?->item;
        return ($this AND is_object($item) AND $item->isField($text)) ? $item->getval($text) : implode(':', $params);
    }
}

/** Escape apostrophes and convert HTML entities
 *  If you want to put the JS code in HTML: tag attribute, consider using onclick="{safe:{javascript:code...}}"
 *  Maybe better is  <div onclick="SetText( {safe:{jsonstring:{_#FULLTEXT}}} );">
 *  @see AA_Stringexpand_Jsonstring
 */
class AA_Stringexpand_Javascript extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (maybe we can?) */

    /** expand function
     * @param $text
     * @return mixed
     */
    function expand($text='') {
        return str_replace(["'","\r\n", "\r", "\n"], ["\'", " ", " ", " "], $text);
    }
}

/** Normalizes utf-8 string - replaces combined characters 'a' + accent to 'á'...
 */
class AA_Stringexpand_Utfnormalize extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $text
     * @return mixed
     */
    function expand($text='') {
        return Normalizer::normalize($text);
    }
}

/** Used for sending text e-mails by {mail...} function */
class AA_Stringexpand_Text2html extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (maybe we can?) */

    const TYPOGRAPH_NO_BREAK_LINE = [
        //one-letter prepositions
        ' a ' => ' a&nbsp;',
        '(a ' => '(a&nbsp;',
        ' A ' => ' A&nbsp;',
        'A ' => 'A&nbsp;',
        '(A ' => '(A&nbsp;',
        ' i ' => ' i&nbsp;',
        '(i ' => '(i&nbsp;',
        ' I ' => ' I&nbsp;',
        'I ' => 'I&nbsp;',
        '(I ' => '(I&nbsp;',
        ' o ' => ' o&nbsp;',
        '(o ' => '(o&nbsp;',
        ' O ' => ' O&nbsp;',
        'O ' => 'O&nbsp;',
        '(O ' => '(O&nbsp;',
        ' u ' => ' u&nbsp;',
        '(u ' => '(u&nbsp;',
        ' U ' => ' U&nbsp;',
        'U ' => 'U&nbsp;',
        '(U ' => '(U&nbsp;',
        ' s ' => ' s&nbsp;',
        '(s ' => '(s&nbsp;',
        ' S ' => ' S&nbsp;',
        'S ' => 'S&nbsp;',
        '(S ' => '(S&nbsp;',
        ' z ' => ' z&nbsp;',
        '(z ' => '(z&nbsp;',
        ' Z ' => ' Z&nbsp;',
        'Z ' => 'Z&nbsp;',
        '(Z ' => '(Z&nbsp;',
        ' k ' => ' k&nbsp;',
        '(k ' => '(k&nbsp;',
        ' K ' => ' K&nbsp;',
        'K ' => 'K&nbsp;',
        '(K ' => '(K&nbsp;',
        ' v ' => ' v&nbsp;',
        '(v ' => '(v&nbsp;',
        '&nbsp;v ' => '&nbsp;v&nbsp;', //two prepositions can happen here
        ' V ' => ' V&nbsp;',
        'V ' => 'V&nbsp;',
        '(V ' => '(V&nbsp;',
        //shortenings
        ' str. ' => ' str.&nbsp;',
        ' hod.' => '&nbsp;hod.',
        ' obr. ' => ' obr.&nbsp;',
        ' tab. ' => ' tab.&nbsp;',
        ' s. ' => ' s.&nbsp;',
        ' č. ' => ' č.&nbsp;',
        '§ ' =>'§&nbsp;',
        '# ' =>'#&nbsp;',
        ' * ' =>' *&nbsp;',
        ' † ' =>' †&nbsp;',
        //units
        ' m²' =>'&nbsp;m²',
        ' cm²' =>'&nbsp;cm²',
        ' dm²' =>'&nbsp;dm²',
        ' mm²' =>'&nbsp;dm²',
        ' m³' =>'&nbsp;m³',
        ' cm³' =>'&nbsp;cm³',
        ' dm³' =>'&nbsp;dm³',
        ' mm³' =>'&nbsp;dm³',
        ' kg' =>'&nbsp;kg',
        ' h' =>'&nbsp;h',
        ' %' =>'&nbsp;%',
        ' °C' =>'&nbsp;°C',
        ' Kč' =>'&nbsp;Kč',
        ' €' =>'&nbsp;€',
        //abbreviations
        ' a. ' => ' a.&nbsp;', //for a. s.
        ' o. ' => '&nbsp;o. ',
        ' mn. ' => ' mn.&nbsp;', //for mn. č.
        ' př. ' => ' př.&nbsp;', //for př. n. l
        ' l. ' => '&nbsp;l. ', //for př. n. l
        'tj. ' =>'tj.&nbsp;',
        'tzv. ' =>'tzv.&nbsp;',
        'tzn. ' =>'tzn.&nbsp;',
        //degrees
        ' Bc. ' =>' Bc.&nbsp;',
        ' BcA. ' =>' BcA.&nbsp;',
        ' Ing. ' =>' Ing.&nbsp;',
        ' Ing. arch. ' =>' Ing.&nbsp;arch.&nbsp;',
        ' ICDr. ' =>' ICDr.&nbsp;',
        ' JUDr. ' =>' JUDr.&nbsp;',
        ' MDDr. ' =>' MDDr.&nbsp;',
        ' Mgr. ' =>' Mgr.&nbsp;',
        ' MgA. ' =>' MgA.&nbsp;',
        ' MSDr. ' =>' MSDr.&nbsp;',
        ' MUDr. ' =>' MUDr.&nbsp;',
        ' MVDr. ' =>' MVDr.&nbsp;',
        ' PaedDr. ' =>' PaedDr.&nbsp;',
        ' PhDr. ' =>' PhDr.&nbsp;',
        ' PhMr. ' =>' PhMr.&nbsp;',
        ' RCDr. ' =>' RCDr.&nbsp;',
        ' RNDr. ' =>' RNDr.&nbsp;',
        ' RSDr. ' =>' RSDr.&nbsp;',
        ' RTDr. ' =>' RTDr.&nbsp;',
        ' ThDr. ' =>' ThDr.&nbsp;',
        ' ThLic. ' =>' ThLic.&nbsp;',
        ' ThMgr. ' =>' ThMgr.&nbsp;',
        ' akad. ' =>' akad.&nbsp;',
        ' ArtD. ' =>' ArtD.&nbsp;',
        ' CSc. ' =>' CSc.&nbsp;',
        ' Dr. ' =>' Dr.&nbsp;',
        ' DrSc. ' =>' DrSc.&nbsp;',
        ' DSc. ' =>' DSc.&nbsp;',
        ' Ph.Dd. ' =>' Ph.D.&nbsp;',
        ' Th.Dd. ' =>' Th.D.&nbsp;',
        ' as. ' =>' as.&nbsp;',
        ' odb. as. ' =>' odb.&nbsp;as.&nbsp;',
        ' doc. ' =>' doc.&nbsp;',
        ' prof. ' =>' prof.&nbsp;',
    ];

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
        $match = [];

        while (preg_match($rexUrlLinker, $text, $match, PREG_OFFSET_CAPTURE, $position)) {
            [$url, $urlPosition] = $match[0];

            // Add the text leading up to the URL.
            if ($escape) {
                $html .= safe(substr($text, $position, $urlPosition - $position));     // no use aa_substr! - the  $urlPosition is in BYTES, not characters
            } else {
                $html .= substr($text, $position, $urlPosition - $position);
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
            if (preg_match('{^\.[0-9]{1,3}$}', $tld) || isset($validTlds[$tld])) {    // works with BYTES, not characters!
                // Do not permit implicit scheme if a password is specified, as
                // this causes too many errors (e.g. "my email:foo@example.org").
                if (!$scheme && $password) {
                    $html .= safe($username);

                    // Continue text parsing at the ':' following the "username".
                    $position = $urlPosition + strlen($username);        // no use aa_substr! - the  $urlPosition is in BYTES, not characters
                    continue;
                }

                if (!$scheme && $username && !$password && !$afterDomain) {
                    // Looks like an email address.
                    $completeUrl = "mailto:$url";
                    $linkText = $url;
                } else {
                    // Prepend http:// if no scheme is specified
                    $completeUrl = $scheme ? $url : "https://$url";
                    $linkText = "$domain$port$path";
                }
                // Cheap e-mail obfuscation to trick the dumbest mail harvesters.
                $html .= str_replace('@', '&#64;', '<a href="' . safe($completeUrl) . '">' . safe($linkText) . '</a>');
            } else {
                // Not a valid URL.
                $html .= safe($url);
            }

            // Continue text parsing from after the URL.
            $position = $urlPosition + strlen($url);            // no use aa_substr! - the  $urlPosition is in BYTES, not characters
        }

        // Add the remainder of the text.
        $html .= safe(substr($text, $position));      // no use aa_substr! - the  $urlPosition is in BYTES, not characters
        return $html;
    }
}

/** Converts the content of the field to html better than standard {text............} command would do
 *  (makes html links from urls...)Checks the text for urls and make it clickable
 */
class AA_Stringexpand_Field2html extends AA_Stringexpand_Text2html {

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
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

/** converts html to text */
class AA_Stringexpand_Html2text extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (maybe we can?) */

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
class AA_Stringexpand_Bin2url extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters */

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
class AA_Stringexpand_Quote extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */

    /** expand function
     * @param $text
     * @return mixed
     */
    function expand($text='') {
        return str_replace(['\\', "'"], ['\\\\', "\'"], $text);
    }
}

/**
 * Class AA_Stringexpand_Rss
 */
class AA_Stringexpand_Rss extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */

    /** expand function
     * @param $text
     * @return bool|string
     */
    function expand($text='', $maxlen='') {
        $entities_old = ['&nbsp;', '& '];
        $entities_new = [' ', '&amp; '];
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
 *  Used libxml which is enabled by default in PHP 7.4+
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

        // load XML document
        $xml = new DomDocument();
        $xml->load($rss_url);

        // load of XSLT styles
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

        // create XSLT processor
        $proc = new xsltprocessor();
        $proc->importStylesheet($xsl);

        // perform transformation and display result
        return $proc->transformToXML($xml);
    }
}


/**
 * Converts the string to anoteher encoding. Wrong characters are transliterated.
 * Usage:  {convert:{include:https://example.org/page.html}:windows-1250:utf-8}
 *          {convert:{full_text.......}:windows-1250:us-ascii}
 *
 */
class AA_Stringexpand_Convert extends AA_Stringexpand {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    static function doTrimParams() { return false; }

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

/** Converts text with characters as html entities like &yacute; to text with accented characters
 *  {convert2chars:P&rcaron;&iacute;li&scaron; &zcaron;lu&tcaron;ou&ccaron;k&yacute; k&uring;&ncaron; &uacute;p&ecaron;l &dcaron;&aacute;belsk&eacute; &oacute;dy.}
 */
class AA_Stringexpand_Convert2chars extends AA_Stringexpand_Nevercache {

    const ACCENTED_ENTITIES = [
        '&Aacute;' => 'Á', '&aacute;' => 'á', '&Acirc;'  => 'Â', '&acirc;'  => 'â', '&Agrave;' => 'À', '&agrave;' => 'à', '&Aring;'  => 'Å', '&aring;'  => 'å', '&Atilde;' => 'Ã', '&atilde;' => 'ã',
        '&Auml;'   => 'Ä', '&auml;'   => 'ä', '&Ccedil;' => 'Ç', '&ccedil;' => 'ç', '&Eacute;' => 'É', '&eacute;' => 'é', '&Ecirc;'  => 'Ê', '&ecirc;'  => 'ê', '&Egrave;' => 'È', '&egrave;' => 'è',
        '&Euml;'   => 'Ë', '&euml;'   => 'ë', '&Iacute;' => 'Í', '&iacute;' => 'í', '&Icirc;'  => 'Î', '&icirc;'  => 'î', '&Igrave;' => 'Ì', '&igrave;' => 'ì', '&Iuml;'   => 'Ï', '&iuml;'   => 'ï',
        '&Ntilde;' => 'Ñ', '&ntilde;' => 'ñ', '&Oacute;' => 'Ó', '&oacute;' => 'ó', '&Ocirc;'  => 'Ô', '&ocirc;'  => 'ô', '&Ograve;' => 'Ò', '&ograve;' => 'ò', '&Oslash;' => 'Ø', '&oslash;' => 'ø',
        '&Otilde;' => 'Õ', '&otilde;' => 'õ', '&Ouml;'   => 'Ö', '&ouml;'   => 'ö', '&Uacute;' => 'Ú', '&uacute;' => 'ú', '&Ucirc;'  => 'Û', '&ucirc;'  => 'û', '&Ugrave;' => 'Ù', '&ugrave;' => 'ù', '&uring;' => 'ů', '&Uring;' => 'Ů',
        '&Uuml;'   => 'Ü', '&uuml;'   => 'ü', '&Yacute;' => 'Ý', '&yacute;' => 'ý', '&Yuml;'   => 'Ÿ', '&yuml;'   => 'ÿ', '&Ccaron;' => 'Č', '&ccaron;' => 'č', '&Dcaron;' => 'Ď', '&dcaron;' => 'ď',
        '&Ecaron;' => 'Ě', '&ecaron;' => 'ě', '&Ncaron;' => 'Ň', '&ncaron;' => 'ň', '&Rcaron;' => 'Ř', '&rcaron;' => 'ř', '&Scaron;' => 'Š', '&scaron;' => 'š', '&Tcaron;' => 'Ť', '&tcaron;' => 'ť',
        '&Zcaron;' => 'Ž', '&zcaron;' => 'ž', '&Lacute;' => 'Ĺ', '&lacute;' => 'ĺ', '&Lcaron;' => 'Ľ', '&lcaron;' => 'ľ', '&Racute;' => 'Ŕ', '&racute;' => 'ŕ'];

    /**
     * @param $text string text with html entities for characters - like &yacute;
     * @return string
     */
    function expand($text='') {
        return strtr($text, self::ACCENTED_ENTITIES);
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
/** Usage:
 *    {view:57::page-{xpage}}
 *    {view:57:{ids:0497ac46076bf257d15f3e030170da92:d-category........-==-Env}}
 *    {view:45::group_by-}   // switches off grouping in the view
 *    {view:45:{ids:0497ac46076bf257d15f3e030170da92:d-category........-==-Env}:sort-} or
 *    {view:45:{ids:0497ac46076bf257d15f3e030170da92:d-category........-==          -Env}:sort-AAnoSORT}  // switches off view sorting - the items stays in the same order as ids
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
        $view_param = ['vid' => $vid];
        if (strlen($ids)) {
            $zids = new zids();
            $zids->addDirty(explode_ids($ids));
            $view_param['zids'] = $zids;
        }
        if (isset($settings)) {
            $view_param = array_merge($view_param, AA_View::parseSettings([$settings]));
        }
        // do not pagecache the view
        return (new AA_Showview($view_param))->getViewOutput();
    }
}

/** displays current poll from polls module specified by its pid
 *  {polls:5ad0d95a490b383ac7d23650e7aa49ee:design_id=4326b8bf6b64b2a216147d896b3860b2&listlen=50&conds[0][operator]=>&conds[0][value]=1&conds[0][expiry_date]=1}
 *  {polls:24ad0d95a490b383ac7d23650e7aa49ee:poll_id=c148d685bfdc21e9f8731037539e8b90}  - display concrete poll
 */
class AA_Stringexpand_Polls extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $pid
     * @return string
     */
    function expand($pid='', $params=null) {
        require_once __DIR__ . "/../modules/polls/include/util.php";
        require_once __DIR__ . "/../modules/polls/include/stringexpand.php";

        $request = [];
        if ($params) {
            parse_str($params, $request);
        }
        $request['pid']=$pid;

        return AA_Poll::processPoll($request);
    }
}



/** Allows you to call view with conds:
 *    {view.php?vid=9&cmd[9]=c-1-{conds:{_#VALUE___}}}
 *  or
 *    {view.php?vid=9&cmd[9]=c-1-{conds:category.......1}}
 *  or
 *    {ids:5367e68a88b82887baac311c30544a71:d-headline........-=-{conds:category.......3:1}}  //  see the third parameter (1) in the last example!
 *    {ids:5367e68a88b82887baac311c30544a71:d-headline........-=-{conds:{qs:type}:1}}
 *  or
 *    {ids:5367e68a88b82887baac311c30544a71:d-headline........-==-{conds:category.......3:==}}  // see the third parameter (==) in the last example
 *  works also for multivalue variable (type[] $_GET variable in the last example)
 *
 *  The syntax is:
 *     {conds:<field or text>[:<mode>]}
 *  <mode> = '' (empty) - the conds are by default url encoded (good for SSI includes with calling /aa/view.php...)
 *                        (%22My%20category%22) so it can be used as parameter to view.
 *         = "1"        - we do not need url encoding for {ids } construct, so for usage with {ids} use the last parameter and set it to 1
 *         = "=="       - the same as "1" but for usage with '==' operator (d-headline........-==-{conds:category.......3:==})
 *     in most cases you want to use mode 1 or ==
 */
class AA_Stringexpand_Conds extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    // not needed right now for Nevercached functions, but who knows in the future
    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param string $text
     * @param string 1 - no url encode (good for  inline d-conditions, == - for use with d- conds and == operator)
     * @return string
     */
    function expand($text='', $mode='') {
        if ( !is_object($this->item) OR !$this->item->isField($text) ) {
            $default = ($mode==='==') ? '' : ($mode ? 'AAnoCONDITION' : '');
            return self::_joinArray(json2arr($text), $mode, $default);
        }
        return self::_joinArray($this->item->getValuesArray($text), $mode);
    }

    static function _joinArray($values, $mode, string $default = 'AA_NeVeRtRuE'): string
    {
        if (empty($values)) {
            return $default;
        }
        $ret = $delim = '';
        foreach ($values as $val) {
            if (empty($val)) {
                continue;
            }
            if ($mode === '==') { //  makes no sense to join multivalues with '==' operator
                $ret .= $delim.str_replace('-', '--', $val);
            } elseif ($mode) {
                $ret .= $delim.('"'.str_replace(['-', '"'], ['--', '\"'], $val).'"');
            } else {
                $ret .= $delim.('%22'.str_replace(['-', ' '], ['--', '%20'], $val).'%22');
            }
            $delim = $mode ? ' OR ' : '%20OR%20';
        }
        return empty($ret) ? $default : $ret;
    }

}


/**
 * Display field/alias of given item(s)
 * {item:<ids>:<aa_expression>[:<delimiter>[:<nested_top>[:<nested_bottom>]]]}
 *
 * Main usage is to display some field or alias of given item:
 *   {item:171055:_#HEADLINE}
 *
 * You can also display it for more than one item and use the delimiter
 *   {item:53443-54322-53553:_#HEADLINE:, }
 *   {item:{@relation........:-}:_#KEY_____->_#VALUE___:jsonasoc}
 *
 * You can also use it to display item in tree. In fact, the {item} with
 * trees works exactly the same way, as {itree}, but {itree} has more logical
 * parameter order (due to backward compatibility), so you are encouraged to use
 * {itree} - @see {itree} for more info on tree string representation.
 **/
class AA_Stringexpand_Item extends AA_Stringexpand {

    /** Do not trim all parameters (at least the $delimiter parameter could contain space) */
    static function doTrimParams() { return false; }

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        // if we use {var} in the code, we can't cache the result based on the parameters only
        return str_contains($params[1], '{var:') ? mt_rand() : '';
    }

    /** expand function
     * @param $ids_string string - ids (long or short (or mixed) separated by dash '-')
     *                     - now you can also use tree representation like:
     *                     3234(3443-3678(3872-3664)-3223)-4045  (see above)
     * @param $content    string
     * @param $delimiter  string - could be also 'json' or jsonasoc -  {item:{@relation........:-}:_#KEY_____->_#VALUE___:jsonasoc}
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
            return self::itemJoin(AA_Items::getFormatted($zids, $content, $numeric_bin), $delim);
        }

        // sanity input
        $ids_string = preg_replace('/[^0-9a-g()-]/', '', $ids_string);  // mention the g (used for generated subrtree cache)
        $ids_string = str_replace('()', '', $ids_string);

        $tree_cache = new AA_Treecache($content, $delim, $top, $bottom);

        // we are looking for subtrees 93938(73737-64635)
        while (preg_match('/[0-9a-f]+[(]([^()]+)[)]/s',$ids_string)) {
            $ids_string = preg_replace_callback('/([0-9a-f]+)[(]([^()]+)[)]/s', [$tree_cache,'cache_list'], $ids_string);
        }
        return $tree_cache->get_concat($ids_string);
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
                $ret = [];
                foreach ($arr as $v) {
                    $foo = explode('->',$v);
                    $ret[$foo[0]] = $foo[1];
                }
                return json_encode($ret);
        }
        return implode($delim,$arr);
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
 * The examples of trees follows.
 *
 * Practical example of usage is for example breadcrumb navigation:
 *   {itree:{xseo1}({xseo2}({xseo3}({xseo4}))): <a href="_#ITEM_URL">_#HEADLINE</a> &gt;: _#HEADLINE}
 *
 * or better
 *   {itree:{xid:path}: <a href="_#ITEM_URL">_#HEADLINE</a> &gt;: _#HEADLINE}
 *   {itree:{xid:path}: _#HEADLINK &gt;: _#HEADLINE}
 *
 * However, you will be able to use it for discussions tree as well.
 *
 * {itree:{treestring:{id..............}}
 *   :_#2<div style="margin-left#:20px">
 *   :{(<div>_#HEADLINE ... </div>)}
 *   ::</div>}
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
 *               delimiter
 *               6 content
 *               3 nested_bottom
 *               4 content
 *               1 nested_bottom
 *               7 content
 *               delimiter
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
 *               delimiter
 *               2 content
 *               delimiter
 *               3 content
 */
class AA_Stringexpand_Itree extends AA_Stringexpand_Nevercachenotrim {
    // cached in AA_Stringexpand_Item
    /** Do not trim all parameters (the $delimiter parameter could contain space) */

    /** expand function
     * @param $ids_string  string - ids (long or short (or mixed) separated by dash '-')
     *                     - now you can also use tree representation like:
     *                     3234(3443-3678(3872-3664)-3223)-4045  (see above)
     * @param $top
     * @param $content
     * @param $delimiter
     * @param $bottom
     * @return string
     */
    function expand($ids_string='', $top=null, $content=null, $delim=null, $bottom=null) {
        $trans   = ['_#1'=>$top, '_#2'=>$content, '_#3'=>$delim, '_#4'=>$bottom];
        $top     = strtr($top,     $trans);
        $content = strtr($content, $trans);
        $delim   = strtr($delim,   $trans);
        $bottom  = strtr($bottom,  $trans);
        return StrExp('item', [$ids_string, $content, $delim, $top, $bottom]);
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
        $results = [];
        foreach ( $ids as $item_id ) {
            $results[] = $this->_get_item($item_id, $this->content);
        }
        return AA_Stringexpand_Item::itemJoin($results, $this->delim);
    }

    /**
     * @param string $item_id
     * @param string $expression
     * @return string
     */
    function _get_item($item_id, $expression) {
        // cached subtree
        if ($item_id[0] === 'g') {
            return $this->_cache[$item_id];
        }

        $array = AA_Items::getFormatted(new zids($item_id), $expression);
        return reset($array);
    }
}

/** Aggregate information from specified set of items. The function, which could
 *  be used for aggregated values are:
 * @param $function   string - sum | max | min | avg | concat | count | order | filter | filter_contain | filter_intersect
 * @param $ids_string string - list of item ids, which we take into account
 * @param $expression string - the value, we are counting with (like _#NUMBER_E)
 * @param $parameter  string - possible additional parameter for the function (like delimiter for the "concat" function)
 *
 * {aggregate:max:{ids:3a0c44958b1c6ad697804cfdbccd8b09}:_#D_APPCNT}
 */
class AA_Stringexpand_Aggregate extends AA_Stringexpand {

    /** Do not trim all parameters (the $parameter could contain space - for concat...) */
    static function doTrimParams() { return false; }

    /** expand function
     * @param $function
     * @param $ids_string
     * @param $expression
     * @param $parameter
     * @return float|int|mixed|string
     */
    function expand($function='', $ids_string='', $expression=null, $parameter='') {
        if ( !in_array($function, ['sum', 'max', 'min', 'avg', 'concat', 'count', 'order', 'filter', 'filter_not', 'filter_contain', 'filter_notcontain', 'filter_isset', 'filter_notset', 'filter_intersect']) ) {
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
        //         if ( $item_id AND (($id_type === 's') OR ($id_type === 'l'))) {
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
        $parameter = (string)$parameter;
        $ret = '';
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
                array_walk($results, static fn($a) => (float)str_replace(',', '.', $a));
                $ret = (count($results) > 0) ? array_sum($results)/count($results) : '';
                break;
            case 'concat':
                $ret = implode($parameter,$results);
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
                $ret = implode('-', array_keys($results));
                break;
            case 'filter':
                $ret = implode('-', array_keys(array_filter($results, static function($v) use ($parameter) { return (string)$v == $parameter; })));
                break;
            case 'filter_not':
                $ret = implode('-', array_keys(array_filter($results, static function($v) use ($parameter) { return (string)$v !== $parameter; })));  // or != is enough?
                break;
            case 'filter_contain':
                $ret = implode('-', array_keys(array_filter($results, static function($v) use ($parameter) { return aa_strpos((string)$v, $parameter) !== false; })));
                break;
            case 'filter_notcontain':
                $ret = implode('-', array_keys(array_filter($results, static function($v) use ($parameter) { return aa_strpos((string)$v, $parameter) === false; })));
                break;
            case 'filter_isset':
                $ret = implode('-', array_keys(array_filter($results, static function($v) { return (bool)strlen(trim((string)$v)); })));
                break;
            case 'filter_notset':
                $ret = implode('-', array_keys(array_filter($results, static function($v) { return trim((string)$v) === ''; })));
                break;
            case 'filter_intersect':
                $ret = implode('-', array_keys(array_filter($results, static function($v) use ($parameter) { return (bool)strlen(StrExp('intersect', [(string)$v, $parameter])); })));
                break;
        }
        return $ret;
    }
}


/** returns fulltext of the item as defined in slice admin
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
            if ( $item_id AND (($id_type === 's') OR ($id_type === 'l'))) {
                $item = AA_Items::getItem(new zids($item_id,$id_type));
                if ($item AND $item->isActive()) {
                    $slice = AA_Slice::getModule($item->getSliceID());
                    $text  = $slice->getProperty('fulltext_format_top'). $slice->getProperty('fulltext_format'). $slice->getProperty('fulltext_format_bottom');
                    $ret  .= AA::Un($text, $slice->getProperty('fulltext_remove'), $item);
                }
            }
        }
        return $ret;
    }
}


/** returns ids of items based on conds d-...
 *  {ids:<slices>:[<conds>[:<sort>[:<delimiter>[:<restrict_ids>[:<limit>]]]]]}
 *  {ids:6a435236626262738348478463536272:d-category.......1-BEGIN-Bio-switch.........1-==-1:headline........-}
 *  returns dash separated long ids of items in selected slice where category
 *  begins with Bio and switch is 1 ordered by headline - descending
 */
class AA_Stringexpand_Ids extends AA_Stringexpand {

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    static function doTrimParams() { return false; }

    /** expand function
     * @param $slices
     * @param $conds
     * @param $sort  - headline........
     *               - category.......1,publish_date....-,headline........
     *               - category.......1+publish_date....-headline........
     *               - _#HEADLINE
     *               - _#HEADLINE-
     * @param $delimiter
     * @param $ids
     * @param $limit - could be negative for last $limit ids
     * @return string
     */
    function expand($slices='', $conds=null, $sort=null, $delimiter=null, $ids=null, $limit=null) {
        $conds = ltrim($conds);
        $sort  = ltrim($sort);
        $ids   = trim($ids);
        $limit = (int)trim($limit);

        $restrict_zids = $ids ? new zids(explode_ids($ids),'l') : false;
        $set = new AA_Set(explode_ids($slices), $conds, $sort);
        if ($limit > 0) {
            $zids = $set->query($restrict_zids, $limit);
        } elseif ($limit < 0) {
            $zids = $set->query($restrict_zids)->slice($limit,-$limit);
        } else {
            $zids = $set->query($restrict_zids);
        }
        $delimiter = $delimiter ?: '-';
        return AA_Stringexpand::joinWithDelimiter($zids->longids(), $delimiter);
    }
}

/** returns ids of objects based on conds d-...
 *  {oids:<type>:<slices>:[<conds>[:<sort>[:<delimiter>[:<restrict_ids>[:<limit>]]]]]}
 *  {oids:datovka:6a435236626262738348478463536272:d-state-&-16384:state-}
 *  returns dash separated long ids of datovka objects - probably to be used in {oitem:...}
 */
class AA_Stringexpand_Oids extends AA_Stringexpand
{
    const ALLOWED_OBJECTS = ['datovka'=>'Datovka'];

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    static function doTrimParams() { return false; }

    /**
     *
     * @param  ?string  $type    - object type - like datovka
     * @param  string   $modules - could be empty and then we look for objects which are in all related slices of current site
     * @param  null     $conds
     * @param  null     $sort   - headline........
     *                          - category.......1,publish_date....-,headline........
     *                          - category.......1+publish_date....-headline........
     *                          - _#HEADLINE
     *                          - _#HEADLINE-
     * @param  null     $delimiter
     * @param  null     $restrict_ids
     * @param  null     $limit  - could be negative for last $limit ids
     *
     * @return string
     */
    function expand($type = null, $modules = '', $conds = null, $sort = null, $delimiter = null, $restrict_ids = null, $limit = null)
    {
        if ($type = self::getClassForType($type)) {                  // list of allowed object to search
            return $this->object_query_set($type, $modules, $conds, $sort, $delimiter, $restrict_ids, $limit);
        }
        return '';
    }

    public static function getClassForType(string $type): string {
        return self::ALLOWED_OBJECTS[strtolower(trim($type))] ?? '';
    }

    /** Universal object search function - syntax inspired by {ids...}   */
    protected function object_query_set($type = null, $modules = '', $conds = null, $sort = null, $delimiter = null, $restrict_ids = null, $limit = null)
    {
        $modules       = $this->getRelatedModules($modules);
        $conds         = trim($conds);
        $sort          = trim($sort);
        $limit         = (int)trim($limit);
        $restrict_zids = $restrict_ids ? new zids(explode_ids($restrict_ids), 'l') : null;
        $delimiter     = $delimiter ?: '-';

        $zids = AA_Object::querySet($type, new AA_Set($modules, $conds, $sort), $restrict_zids);
        if ($limit > 0) {
            $zids = $zids->slice(0, $limit);
        } elseif ($limit < 0) {
            $zids = $zids->slice($limit, -$limit);
        }
        return AA_Stringexpand::joinWithDelimiter($zids->longids(), $delimiter);
    }
}


/**
 * Display formatted output using fields/aliases of object (like datovka or others in stored in object_* table)
 *   {oitem:<ids>:<aa_expression>[:<delimiter>]}
 *
 * Main usage is to display some field or alias of given object:
 *   {oitem:{oids:datovka}:_#STATE___ {stateTxt}:<br>}
 *
 * possible aliases / fields for datovka object:
 *     - _#AA_NAME_ - {aa_name}
 *     - _#DATOVKA_ - {datovka_id}
 *     - _#RETURN_U - {return_url}
 *     - _#ATTACHME - {attachments}
 *     - _#CONF____ - {conf}
 *     - _#STATE___ - {state}
 *     - _#STATETXT - {stateTxt}
 *     - _#RELATED_ - {related_to}
 *     - _#DBIDRECI - {dbIDRecipient}
 *     - _#DMANNOTA - {dmAnnotation}
 *     - _#DMTOHAND - {dmToHands}
 *     - _#DMRECIPI - {dmRecipientOrgUnitNum}
 *     - _#DMSENDER - {dmSenderOrgUnitNum}
 *     - _#DMLEGALT - {dmLegalTitlePoint}
 *     - _#DMPERSON - {dmPersonalDelivery}
 *     - _#DMALLOWS - {dmAllowSubstDelivery}
 *     - _#AA_ID___ - {aa_id}
 *     - _#AA_OWNER - {aa_owner}
 *     - _#AA_OW_NM - {modulefield:{_#AA_OWNER}:name}
 **/
class AA_Stringexpand_Oitem extends AA_Stringexpand {

    /** Do not trim all parameters (at least the $delimiter parameter could contain space) */
    static function doTrimParams() { return false; }

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        // if we use {var} in the code, we can't cache the result based on the parameters only
        return str_contains($params[1], '{var:') ? mt_rand() : '';
    }

    /**
     * @param ?string $type   - object type - like datovka
     * @param string  $ids    - ids of object (probably from {oids:...}) separated by dash '-'
     * @param string  $format -
     * @param string  $delim  - delimiter
     *
     * @return string
     */
    function expand($type=null, $ids='', $format=null, $delim=null) {
        if ($type = AA_Stringexpand_Oids::getClassForType($type)) {                  // list of allowed object to search
            return $this->object_format($type, $ids, $format, $delim);
        }
        return '';
    }

    protected function object_format($type=null, $ids=null, $format=null, $delimiter=null) {
        $zids      = new zids(explode_ids($ids));
        $aliases   = new AA_Aliases(new AA_Aliasgenerator_Object($type));
            $fmt       = ['odd_row_format' => trim($format),
                      'row_delimiter'  => $delimiter ?: '' ];
        return (new itemview($fmt, $aliases, $zids))->setContentFunct([['AA_Object', 'getContent'], ['class'=>$type]])->get_output();
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
        return implode($zids->longids(), '-');
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
     *                    - could be also
     * @return mixed|null|string|string[]
     */
    function expand($item_id=null, $slice_ids=null, $sort=null) {
        $item = AA_Items::getItem($item_id);
        if ($item) {
            $slice_ids = $slice_ids ?: '{site:{modulefield:{slice_id........}:site_ids}:modules}';
            $sort      = $sort      ? (($sort === '-') ? '': $sort) : 'slice_id........,publish_date....-';
            return AA::Un("{ids:$slice_ids:d-all_fields-==-{id..............}:$sort}", '', $item);
        }

        return '';
    }
}

/** Sorts ids by the expression
 *  {order:<ids>:<expression>[:<sort-type>]}
 *  {order:4785-4478-5789:_#YEAR_____#CATEGORY}
 *  {order:4785-4478-5789:_#HEADLINE:string}
 *  Usually it is much better to use sorting by database - like you do in {ids},
 *  but sometimes it is necessary to sort concrete ids, so we use this
 *  You can sort numerically (default), as string or using current locale
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
        return StrExp('aggregate', ['order', $ids, $expression, $type]);
    }
}

/** Filter ids by the expression
 *  {filter:<ids>:<expression>:<equals-to>:<operator>}
 *  {filter:4785-4478-5789:_#SLICE_ID:879e87a4546abe23879e87a4546abe23}
 *  {filter:4785-4478-5789:{({item:{relation........}:_#APPROVED})}:1}
 *  {filter:{ids:{_#SLICE_ID}}:_#CATEGORY:blue:!contain}
 *  Usually it is much better to use filtering by database - like you do in {ids},
 *  but sometimes it is necessary to filter concrete ids, so we use this
 *  Returns only ids, which <expression> equals to <equals-to>
 */
class AA_Stringexpand_Filter extends AA_Stringexpand_Nevercache {
    // cached in AA_Stringexpand_Aggregate

    /** expand function
     * @param $ids - dash separated item ids
     * @param $expression - expression for filtering
     * @param $equals - value to match expression
     * @param $operator - = | != | contain | !contain | isset | notset | intersect
     * @return string
     */
    function expand($ids=null, $expression=null, $equals=null, $operator=null) {
        $OPERATORS = ['contain' => 'filter_contain', '!contain' => 'filter_notcontain', '!=' => 'filter_not', 'isset' => 'filter_isset', 'notset' => 'filter_notset', 'intersect'=>'filter_intersect'];
        $fnc = ($operator AND $OPERATORS[$operator]) ? $OPERATORS[$operator] : 'filter';
        return StrExp('aggregate', [$fnc, $ids, $expression, $equals]);
    }
}

/** returns string useful for sorting the tree of items.
 *  The string is based on the short_ids by default, which is quickest and works well with discussions, ...
 *  If the branch of current item 35897 is 2458-15878-35897,
 *  then the string will be E2458F15878F35897, which works well for ordering
 *  the tree
 *  {sortstring:<shortitem_id>[:<relation_field>]}
 *  {sortstring:2a4352366262227383484784635362ab:relation.......1}
 *  {sortstring:2a4352366262227383484784635362ab:relation.......1:_#ORDER___}
 *    - use _#ORDER___ alias instead of short ID. _#ORDER___ could be number or any other string
 *  @return string useful for sorting the tree of items
 */
class AA_Stringexpand_Sortstring extends AA_Stringexpand {
    /** expand function
     * @param      $item_id         string - item id of the tree root (short or long)
     * @param      $relation_field  string - tree relation field (default relation........)
     * @param      $expression      string - expression to use for ordering (on the same tree level). The default is short item id
     * @return string
     */
    function expand($item_id='', $relation_field=null, $expression=null) {
        $zids = new zids(array_reverse(AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field), 'l'));
        $orderstrings =  strlen($expression) ? AA_Items::getFormatted($zids, $expression, AA_BIN_ALL) : $zids->shortids();
        return implode('', array_map( ['AA_Stringexpand_Sortid', 'sortid'], $orderstrings));
    }
}

/** @return string useful for sorting numbers in text mode. The string is based
 *  on number nad length, so it creates B1 from 1, C10 from 10, C89 from 89, and
 *  E2458 from 2458
 *  {sortid:<number>}
 */
class AA_Stringexpand_Sortid extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $number - number to be transformed to string for sorting
     * @return string B1 for $id=1, F25487 for $id=25487, ...
     */
    function expand($number='') {
        return chr(65+strlen((string)$number)).$number;
    }

    /** the same as expand, but callable staticaly */
    public static function sortid($number='') {
        return chr(65+strlen((string)$number)).$number;
    }
}


/** @return string representation of the tree (with long ids) under specified
 *          item based on the relation field
 *  @see {itree: } for more info about the stringtree syntax
 *  {treestring:<item_id>[:<relation_field>[:<reverse>[:<sort_string>[:<slices>]]]]}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1:1}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1:1:sort[0][headline........]=a&sort[1][publish_date....]=d}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1:1:headline........:35615a6d5fdfeb23d36d1c94be3cd9b4}
 *  <nav><ul>{itree:{treestring:{ids:b485d20843c0afe29d3e9ce773195635:d-relation........-ISNULL-1-switch.........1-==-0:number..........}::1:number..........}:<li id="menu-_#SEO_____">_#MENULINK<ul>:<li id="menu-_#SEO_____">_#MENULINK</li>::</ul></li>}</ul></nav>
 */
class AA_Stringexpand_Treestring extends AA_Stringexpand {
    /** expand function
     * @param $item_ids - item id (or ids) of the tree root (short or long)
     * @param $relation_field - tree relation field (default relation........)
     * @param $reverse - 1 for reverse trees (= child->parent relations)
     * @param $sort_string - order of tree leaves (currently works only for reverse trees. @todo)
     * @param $slices - traverse only listed slices (sometimes useful if your tree contain more than one slice and you want to count only with a subtree)
     * @return string
     */
    function expand($item_ids='', $relation_field=null, $reverse=null, $sort_string=null, $slices=null) {
        if (!str_contains($item_ids, '-')) {
            // just for speedup
            return self::treefunc('getTreeString', $item_ids, $relation_field, $reverse, $sort_string, $slices);
        }
        $ret = [];
        $ids = explode_ids($item_ids);
        foreach ($ids as $id) {
            $ret[] = self::treefunc('getTreeString', $id, $relation_field, $reverse, $sort_string, $slices);
        }
        return implode('-', array_filter($ret,'strlen'));
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
 *  {tree:<item_ids>[:<relation_field>]}
 *  {tree:2a4352366262227383484784635362ab:relation.......1}
 *  @return string - dash separated long ids of items which belongs to the tree under
 *          specified item based on the relation field
 */
class AA_Stringexpand_Tree extends AA_Stringexpand {
    /** expand function
     * @param string $item_ids - item id(s) of the tree root (short or long)
     * @param        $relation_field - tree relation field (default relation........)
     * @param null   $reverse
     * @param null   $sort_string
     * @param null   $slices
     * @return string
     */
    function expand($item_ids='', $relation_field=null, $reverse=null, $sort_string=null, $slices=null) {
        $in_ids  = explode_ids($item_ids);
        $ret = [];
        foreach ($in_ids as $item_id) {
            array_push( $ret, ...AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field, $reverse, $sort_string, $slices));
        }
        return implode('-', array_unique(array_filter($ret,'strlen')));
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
        return implode('-', array_reverse(AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field)));
    }
}

/** @return string - prints HTML menu
 *  - designed for SEO sitemodule with "Pages" slice with the relation field "Subpage of..."
 *  - the menu items with empty text is not printed (which you can use for not displaying some items)
 *
 *      {menu:<first-level-item-ids>:<menu-text>:[<relation-field>[:<sort-string>[:<type>]]]}
 *  submenu for current item:
 *      {menu:{id..............}:_#MENULINK}
 *  whole real menu for the Pages slice:
 *      {menu:{ids:18a352366ea922738348478463536ea5:d-relation........-ISNULL-1:number..........}:_#MENULINK:relation........:number..........}
 *  expanded menu tree for css dynamic menu
 *      {menu:{ids:18a352366ea922738348478463536ea5:d-relation........-ISNULL-1:number..........}:_#MENULINK:relation........:number..........:all}
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
     *                         - should be a link to the item - _#HEADLINK (for example)
     *                         - if the resulting code is empty, the menu option is not displayed
     *                           (not its submenu), which you can use for not displaying some items
     * @param $relation_field - tree relation field (default relation........)
     * @param $sort_string - order of tree leaves
     * @param $type - <nothing>|all|all+ - if "all", the menu is expanded to all leaves, if "all+", the expand button is added
     * @return string
     */
    function expand($item_ids=null, $code=null, $relation_field=null, $sort_string=null, $type=null) {
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
        $current_ids = explode_ids(AA::Un('{xid:list}'));

        $supertree = AA_Trees::getSupertree(get_if($relation_field, 'relation........'), 1, $sort);
        return $supertree->getMenu($long_ids, $current_ids, $code, false, str_contains($type, 'all'), str_contains($type, '+'));
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
        if (!strlen($item_id = substr(StrExp('seo2ids', ['',$seo_string,'1']),0,32))) {
            return '';
        }
        if ($text === 'AA_FullText') {
            return StrExp('fulltext', [$item_id]);
        }
        return StrExp('item', [$item_id,$text]);
    }
}



/** returns ids of items based on seo............. field
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
        static $_seoslices = [];  // stores only slices with 'seo' field

        if ( !is_array($sarr = $_seoslices[$hash = hash('md5', 'h'.$slices)])) {
            $dirty_slices = $this->getRelatedModules($slices);
            $sarr = $dirty_slices ? self::_getSeoSlices($dirty_slices) : [];
            $_seoslices[$hash] = $sarr;
        }

        if (empty($sarr)) {
            return '';
        }

        $bins = $bins ?: AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING;

        $set  = new AA_Set($sarr, new AA_Condition('seo.............', '==', $seo_string), null, $bins);
        $zids = $set->query();
        return implode('-', $zids->longids());
        // added expiry date in order we can get ids also for expired items
        // return StrExp('ids', [$slices, 'd-expiry_date.....->-0-seo.............-=-"'. str_replace('-', '--', $seo_string) .'"']);
    }

    /**
     * @param $slices
     * @return array
     */
    static function _getSeoSlices($slices) {
        return DB_AA::select('unpackid', 'SELECT LOWER(HEX(`slice_id`)) AS unpackid FROM field', [['slice_id', $slices, 'l'], ['id', 'seo.............']]);
    }
}

/** returns seo name created from the string
 *  {seoname:<string>[:<unique_slices>[:<encoding>]]}
 *  {seoname:About Us:3aa35236626262738348478463536224:windows-1250}
 *  {seoname:{_#HEADLINE}:all}
 *  returns about-us
 *  If you specify the unique_slices parameter, then the id is created as unique
 *  for those slices. Slices are separated by dash
 *  Encoding parameter helps convert the name to ASCII. You should write here
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
        $unique_slices = implode('-',$this->getRelatedModules($unique_slices));
        if ( !empty($unique_slices) ) {
            // we do not want to create infinitive loop for wrong parameters
            for ($i=2; $i < 100000; $i++) {
                // following command if not cached - the recounting seo in loop of Modify Content did not work with cache, of course
                $ids = StrExpand('AA_Stringexpand_Seo2ids', [$unique_slices, $base.$add, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING], [], false, true);
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
                $set  = new AA_Set($slices, new AA_Condition($field_id, '==', $string.$add), null, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING);
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
    static function doTrimParams() { return false; }

    /** expand function
     * @param $group_id - constants ID
     * @param $value - constant value (or values delimited by $value_delimiter)
     * @param $what - name|value|short_id|description|pri|group|class|id|level
     *                            or any AA expression using constant aliases
     *                            _#NAME###_, _#VALUE##_, _#PRIORITY, _#GROUP##_, _#CLASS##_,
     *                            _#COUNTER_, _#CONST_ID, _#SHORT_ID, _#DESCRIPT, _#LEVEL##_
     * @param $value_delimiter - value delimiter - used just for translating multiple constants at once
     * @param $output_delimiter - resulting output delimiter - ', ' is default for multiple constants
     * @return bool|mixed|null|string|string[]
     */
    function expand($group_id='', $value='', $what='name', $value_delimiter='', $output_delimiter=', ') {
        if (!$value_delimiter) {
            return getConstantValue($group_id, $what, $value);
        }
        $arr = explode($value_delimiter, $value);
        $ret = [];
        foreach ($arr as $constant) {
            $val = getConstantValue($group_id, $what, $constant);
            if ($val) {
                $ret[] = $val;
            }
        }
        return implode($output_delimiter, $ret);
    }
}

/** {constants:<group_id>:<format>:<delimiter>}
 *  @example {constants:ekolist-category}
 *  @example {constants:lt_languages:_#NAME###_->_#VALUE##_:jsonasoc}
 *  @example {constants:lt_languages:_#VALUE##_:json}
 *  @return string - prints all constants delimited by <delimiter>. Only _#NAME###_ and _#VALUE##_ aliases could be used
 */
class AA_Stringexpand_Constants extends AA_Stringexpand {
    /** Do not trim all parameters (the $selected parameter could contain space) */
    static function doTrimParams() { return false; }

    /** expand function
     * @param $group - group id or JSON list of values
     * @return string
     */
    function expand($group='', $format='', $delimiter='') {
        $ret      = [];
        $constants = GetConstants(trim($group));
        if ($constants) {
            switch (trim($format)) {
                case 'name':  $format = '_#NAME###_'; break;
                case '':
                case 'value': $format = '_#VALUE##_';
            }
            foreach ($constants as $k => $v) {
                if (strlen($res = str_replace(['_#VALUE##_','_#NAME###_'], [$k, $v], $format))) {
                    $ret[] = $res;
                }
            }
        }
        return AA_Stringexpand_Item::itemJoin($ret, $delimiter);
    }
}

/** {options:<group_id>:<selected>}
 *  {options:My_Constants_grp:{qs:theme}}
 *  <select name="theme">{options:My_Constants_grp:{qs:theme}}</select>
 *  {options:[1,2,5,7]:7}
 *  {options:[[1,"January"],[2,"Feb"],[3,"March"]]:7}
 *  {options:[[1,"January"],[2,"Feb"],[3,"March"]]:["7","8"]}     - use multiple selected options
 *  {options:[[1,"January"],[2,"Feb"],[3,"March"]]:{qs:months}}   - works also with multivalues - <select name="months[]" multiple>
 *  {options:{sequence:num:1998:2012}:{date:Y}}
 *  @return string - html <option>s for given constant group with selected option
 */
class AA_Stringexpand_Options extends AA_Stringexpand {
    /** Do not trim all parameters (the $selected parameter could contain space) */
    static function doTrimParams() { return false; }

    /** expand function
     * @param $group - group id or JSON list of values
     * @return string
     */
    function expand($group='', $selected='') {
        $ret      = '';
        $selected = json2arr((string)$selected);
        if ($group[0] === '[') {
            $constants = json2arr($group, true);
            foreach ($constants as $v) {
                if (is_array($v)) {
                    $k     = $v[0];
                    $khtml = "value=\"$k\"";
                    $v     = $v[1];
                } else {
                    $k     = $v;
                }

                $sel  = in_array((string)$k,$selected) ? ' selected' : '';
                $ret .= "\n  <option $khtml $sel>".safe($v)."</option>";
            }
        } else {
            $constants = GetConstants(trim($group));
            if ($constants) {
                foreach ($constants as $k => $v) {
                    $sel  = in_array((string)$k,$selected) ? ' selected' : '';
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
 *    {foreach:{sequence:num::2020:{date:Y}::-}:<a href="?rok=_#loop">_#loop</a>}
 *    {options:{sequence:string:A:H}:D}
 *    {sequence:day:{now}:{timestamp:2022-06-30}}  // times could be as timestamp (when numeric)...
 *    {sequence:day:2022-06-30:today}              // ... or as string
 */
class AA_Stringexpand_Sequence extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $group_id
     * @return string
     */
    function expand($type='', $min='', $max='', $step='', $delimiter='') {
        $arr = [];
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
            case 'day':
                if (strlen($min) AND strlen($max)) {
                    $from = strtotime('today', IsSigInt((string)$min) ? (int)$min : strtotime($min));
                    $to   = strtotime('today', IsSigInt((string)$max) ? (int)$max : strtotime($max));
                    $add = ($from <= $to) ? '+1 day' : '-1 day';
                    if ($from <= $to) {
                        while ($from <= $to) {
                            $arr[] = $from;
                            $from = strtotime($add, $from);
                        }
                    } else {
                        while ($from >= $to) {
                            $arr[] = $from;
                            $from = strtotime($add, $from);
                        }
                    }
                }
                break;
        }
        if (!strlen($delimiter)) {
             $delimiter = 'json';
        }
        return empty($arr) ? '' : (($delimiter === 'json') ? json_encode($arr) : implode($delimiter, $arr));
    }
}

/** If $condition is filled by some text, then print $text. $text could contain
 *  _#1 alias for the condition, but you can use any {} AA expression.
 *  Example: {ifset:{img_height.....2}: height="_#1"}
 *  The $condition with undefined alias is considered as empty as well
 *    ($condition=_#.{8} (exactly) - like '_#HEADLINE')
 */
class AA_Stringexpand_Ifset extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (maybe we can?) */

    /** expand function
     * @param $condition
     * @param $text
     * @param $else_text
     * @return mixed|string
     */
    function expand(...$arg_list) {
        return AA_Stringexpand_Ifin::_resolveCompare(array_merge([''], $arg_list), static function($a,$b) { $v = trim($b); return !(($v === '') OR IsAlias($v));}, true);
    }
}

/** The same as {ifset:..} but it ignores all the HTML comments in the condition */
class AA_Stringexpand_Iftext extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (maybe we can?) */

    /** expand function
     * @param $condition
     * @param $text
     * @param $else_text
     * @return mixed|string
     */
    function expand(...$arg_list) {
        return AA_Stringexpand_Ifin::_resolveCompare(array_merge([''], $arg_list), static function($a,$b) { $v = trim(preg_replace('/<!--(.*?)-->/s', '', $b)); return !(($v === '') OR IsAlias($v));}, true);
    }
}

/** Boolean comparison to exact value: 1
 *  Example: {if1:{_#IS_PUBLI}:_#HEADLINE:no public yet}
 */
class AA_Stringexpand_If1 extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $condition
     * @param $text
     * @param $else_text
     * @return string
     */
    function expand($condition='', $text='', $else_text='') {
        return ((string)$condition=='1') ? $text : $else_text;
    }
}

/** Flag / Bitset / Binary comparison checks, if bit is set
 * Example: {ifbit:{bitset..........}:8:1}    // the same as {ifeq:{math:{bitset..........}&8}:8:1}
 * Example: {ifbit:{bitset..........}:31:1+2+4+8+16 set (_#2):15:1+2+4+8 set (_#2):12:4+8 set (_#2):0}  // multiple conditions - returns the first matching - "4+8 set (12)" for number 13 in {bitset..........}
 */
class AA_Stringexpand_Ifbit extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $condition
     * @param $text
     * @param $else_text
     * @return string
     */
    function expand(...$arg_list) {
        return AA_Stringexpand_Ifin::_resolveCompare($arg_list, static function($a,$b) {return !strlen($b) OR ((int)$a & (int)$b) == (int)$b;} );
    }
}

/** If $etalon is equal to $option1, then print $text1, else print $else_text.
 *  $(else_)text could contain _#1 and _#2 aliases for $etalon and $option1, but you
 *  can use any {} AA expression.
 *  Example: {ifeq:{xseo1}:about: class="active"}
 *  Now you can use as many $options as you want
 *  Example: {ifeq:{xlang}:en:English:cz:Czech:Unknown language}
 */
class AA_Stringexpand_Ifeq extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (the $text parameter at least could contain space) */

    /** expand function
     * @param $etalon
     * @param $option1
     * @param $text1
     * (...)
     * @param $else_text
     * @return mixed
     */
    function expand(...$arg_list) {
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
            $ret = $arg_list[$i] ?? '';
        }
        // _#2 is not very useful, but we have it from the times the function was just for one option
        return str_replace(['_#1','_#2'], [$etalon, $arg_list[0]], $ret);
    }
}

/** Numeric comparison with the operator specified by parameter You can as
 *  in {ifeq} use multiple conditions - the first matching is returned, then
 *  Example: {if:{_#IMGCOUNT}:>:10:big:6:medium:small}
 *  Comparison is always numeric (also for security reasons)
 */
class AA_Stringexpand_If extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (maybe we can?) */

    /** expand function
     * @param $etalon
     * @param $operator
     * @param $option1
     * @param $text1
     * (...)
     * @param $else_text
     * @return mixed
     */
    function expand(...$arg_list) {
        $etalon   = AA_Stringexpand::clearFloat(array_shift($arg_list));
        $cmp      = match (array_shift($arg_list)) {
            '=',  '==',       'eq'       => static fn($a, $b) => $a == $b,  // typehints doesn't work for float in php 5.6
                                                                     // function(float $a, float $b) { return $a == $b; };  break;
            '>',  'gt',       '&gt;'     => static fn($a, $b) => $a > $b,
            '<',  'lt',       '&lt;'     => static fn($a, $b) => $a < $b,
            '>=', '&gt;=',    'ge'       => static fn($a, $b) => $a >= $b,
            '<=', '&lt;=',    'le'       => static fn($a, $b) => $a <= $b,
            '<>', '&lt;&gt;', 'ne', '!=' => static fn($a, $b) => $a <> $b,
            default                      => static fn($a, $b) => false,
        };
        $ret      = false;
        $i        = 0;
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if ($cmp($etalon, AA_Stringexpand::clearFloat($arg_list[$i]))) {
                $ret = $arg_list[$i+1];
                break;
            }
            $i += 2;
        }
        if ($ret === false) {
            // else text
            $ret = $arg_list[$i] ?? '';
        }
        // _#2 is not very useful, but we have it from the times the function was just for one option
        return str_replace(['_#1','_#2'], [PhpFloat($etalon), $arg_list[0]], $ret);
    }

}


/** If any value of the (multivalue) $field is equal to $var, then print $text,
 *  else print $else_text.
 *  $(else_)text could contain _#1 aliases for $var, but you can use any {} AA
 *  expression.
 *  Usage:  {ifeqfield:<item_id>:<field>:<var>:<text>:<else-text>}
 *  Example: {ifeqfield:{xid}:category.......1:Nature: class="green"}
 *  Example: {ifeqfield::category.......1:Nature: class="green"} // for current item
 */
class AA_Stringexpand_Ifeqfield extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    static function doTrimParams() { return false; }

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
        $item = (!$item_id OR ($item_id==='current')) ? $this->item : AA_Items::getItem(new zids($item_id));
        if (!empty($item) AND $item->isField($field)) {
            $ret = in_array($var, $item->getValuesArray($field)) ? $text : $else_text;
        }
        return str_replace(['_#1'], [$var], $ret);
    }
}

/** If $haystack contain $needle text, then print $text, else print $else_text.
 *  $(else_)text could contain _#1 for $haystack and _#2 for matched $needle
 *  Usage:  {ifin:ActionApps CMS:CMS:yes:no}
 *  Now you can use as many $needles as you want - only the first matched wins
 *  Example: {Ifin:de,ru,cz,pl,en:en:English:cz:Czech:Unknown language}
 */
class AA_Stringexpand_Ifin extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (maybe we can?) */

    /** expand function
     * @param $haystack
     * @param $needle
     * @param $text
     * @param $else_text
     * @return mixed
     */
    function expand(...$arg_list) {
        return self::_resolveCompare($arg_list, static function($a,$b) {return ($b == '') OR str_contains($a, $b);} );
    }

    /**
     * @param $arg_list
     * @param $cmp_function
     * @return mixed
     */
    public static function _resolveCompare($arg_list, $cmp_function, bool $replace_just_matched=false) {
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
            $ret     = $arg_list[$i] ?? '';
            $matched = $replace_just_matched ? '' : $ret;
        }
        return $replace_just_matched ? strtr($ret, ['_#1'=>$matched]) : str_replace(['_#1','_#2'], [$haystack, $matched], $ret);
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
    function expand(...$arg_list) {
        return AA_Stringexpand_Ifin::_resolveCompare($arg_list, static function($a,$b) {return count(array_filter(array_intersect(array_map('trim', explode_ids($a)),array_map('trim',explode_ids($b))),'strlen'))>0;} );
    }
}

/** Tests type of the string
 *  {iftype:<haystack>:<test1>:<text1>[:...]}
 *  Example: {iftype:{qs:id}:longid:_#1:shortid:warning - use long id:parameter is not id}
 *   tests:  longid | shortid | mail | signed | number | positive
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
    function expand(...$arg_list) {
        return AA_Stringexpand_Ifin::_resolveCompare($arg_list, ['AA_Stringexpand_Iftype','typeCompare']);
    }

    /** Compare function
     * @param $test_string string - test string
     * @param $test string - test type - one of longid|shortid|mail...   (we have just those, at this moment, but there will be more...)
     * @return bool     - if the string is of type $b
     */
    public static function typeCompare($test_string, $test): bool {
        return match ($test) {
            'longid'   => is_long_id($test_string),
            'shortid'  => is_short_id($test_string) and ($test_string > 0),                // maybe we should update the is_short_id test
            'mail'     => (bool)filter_var($test_string, FILTER_VALIDATE_EMAIL),
            'signed'   => IsSigInt($test_string),                                          // any integer
            'number'   => ctype_digit((string)$test_string),                               // integer >= 0
            'positive' => (ctype_digit((string)$test_string) and ((int)$test_string > 0)), // integer > 0
            default    => false,
        };
    }
}


/** Takes unlimited number of parameters and joins the non-empty ones into one
 *  string using first parameter as delimiter
 *  Example: {join:, :{_#YEAR____}:{_#SIZE____}:{_#SOURCE___}}
 */
class AA_Stringexpand_Join extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters ($delimiter could be space) */

    /** expand function
     * @param $delimiter
     * @param $strings ...
     * @return string
     */
    function expand(...$arg_list) {
        $delimiter = array_shift($arg_list);
        $ret       = array_filter($arg_list, static fn($str) => trim($str) !== '');
        return ($delimiter === 'json') ? json_encode(array_values($ret)) : implode($delimiter, $ret);
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
        return match ($url) {
            ''       => $sess->id(),
            'hidden' => "<input type=\"hidden\" name=\"".$sess->name()."\" value=\"".$sess->id()."\">",
            'param'  => $sess->name().'='.$sess->id(),
            default  => StateUrl($url),
        };
    }
}

/** Compares two values -
 *  returns:  'L' if val1 is less than val2
 *            'G' if val1 is greater than val2
 *            'E' if they are equal
 *  usage:  {ifeq:{compare:{publish_date....}:{now}}:G:greater:L:less:E:equal}
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
 *  usage:  {fieldid:text:1}    - returns text...........1
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

/** Get field property (currently only name | help |  alias1 | alias2 | alias3 | widget_new is supported)
 *  {field:<field_id>:<property>:<slice_id>}
 *  {field:headline........:name:ebfbc0082a26365ef6cefd7c4a4ec253}
 *    - displays the Name of the headline field in specified slice as defined by administrator of the slice on the Fields Admin page
 *  Allowed properties are name, help and alias1
 */
class AA_Stringexpand_Field extends AA_Stringexpand {

    /**
     * @var array
     */
    private static $ALLOWED_PROPERTIES = ['name'=>'name','help'=>'input_help', 'alias1'=>'alias1', 'alias2'=>'alias2', 'alias3'=>'alias3', 'widget_new'=>'widget_new', 'is_multi'=>'is_multi', 'is_multilang'=>'is_multilang', 'is_formatable'=>'is_formatable', 'type'=>'type', 'valuetable'=>'valuetable'];

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
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
        // that's why we restrict it to the properties, which makes sense
        // @todo - make it less restrictive
        $prop = self::$ALLOWED_PROPERTIES[$property];

        if (!($field = $this->_getField($slice_id, $field_id))) {
            return '';
        }

        switch ($prop) {
            case 'widget_new':
                return StrExp('input', [$slice_id, $field_id]);
            case 'is_multi':
                return $field->isMultiple() ? '1' : '';
            case 'is_multilang':
                return $field->isMultilingual() ? '1' : '';
            case 'is_formatable':
                return $field->getFormatters() ? '1' : '';
            case 'type':
                return $field->getType();          //  pwd | date | bool | text | int | id
            case 'valuetable':
                if ($w = $field->getWidget() AND is_array($arr = $w->getFormattedOptions())) {
                    // generate html table key -> value
                    return array_reduce(array_keys($arr), static fn($html, $key) => $html . "<tr><td>" . htmlspecialchars($key) . "</td><td>" . htmlspecialchars($arr[$key]) . "</td></tr>", '');
                }
                return '';
        }

        return (string) $field->getProperty($prop ?: 'name');
    }

    /**
     * @param $slice_id
     * @param $field_id
     */
    function _getField($slice_id, $field_id): ?AA_Field {
        if (empty($slice_id)) {
            if ( empty($this->item) ) {
                return null;
            }
            $slice_id = $this->item->getSliceID();
        }
        return ($slice = AA_Slice::getModule($slice_id)) ? $slice->getField($field_id) : null;
    }
}

/** {fieldoptions:<slice_id>:<field_id>:<values>}
 *  displays html <options> as defined for the field. You can specify current
 *  values - as single value, or as multivalue in JSON format.
 */
class AA_Stringexpand_Fieldoptions extends AA_Stringexpand_Field {
    /** Do not trim all parameters ($values could contain space) */
    static function doTrimParams() { return false; }

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
 *    {input:36fd8c4501d7a8b9e9505dc323d24321:e_posted_by.....::fld:{"input_type":"email"}}                      (3)
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
        return $this->inputCommon($slice_id, $field_id, $required, $widget_type, $widget_properties, $preset_value, $item_index);
    }

    function inputCommon($slice_id, $field_id, $required, $widget_type, $widget_properties, $preset_value, $item_index, $widget_only=false) {
        if ( !($field = $this->_getField($slice_id, $field_id))) {
            if ( AA_Slice::getModuleProperty($slice_id,'autofields') AND ($field = $this->_getField($slice_id, 'text............'))) {
                $field = $field->cloneWithId($field_id);
            } else {
                return '';
            }
        }
        // const_arr in widget_properties could be specified as (1) JSON object, or as (2) string with JSON object - see examples(1) and (2) above
        $widget_prop = AA_Stringexpand_Edit::WidgetPropsString2Arr($widget_properties);
        $preset = AA_Value::factoryFromJson($preset_value); // could be json as well as single value string

        return $field->getWidgetNewHtml($required==1, null, $widget_type, $widget_prop, $preset, $item_index, $widget_only);
    }
}

/** the same as {input} but prints widget without labels and help
 * @see  AA_Stringexpand_Input
 */
class AA_Stringexpand_Inputonly extends AA_Stringexpand_Input {
    function expand($slice_id='', $field_id='', $required=null, $widget_type=null, $widget_properties=null, $preset_value=null, $item_index=null) {
        return $this->inputCommon($slice_id, $field_id, $required, $widget_type, $widget_properties, $preset_value, $item_index, true);
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
    // It works with database, so it should always look in the database

    // not needed right now for Nevercached functions, but who knows in the future
    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
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
        return $this->_getWidget('getOnlyHtml',$item_id, $field_id, $required, $function, $widget_type, $widget_properties);
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
            $itemcontent = $item_id ? new ItemContent($item_id) : ($this->item ? $this->item->getItemContent() : null );
            if (!empty($itemcontent) AND !$itemcontent->isEmpty() AND ($slice = AA_Slice::getModule($itemcontent->getSliceId()))) {

                // Use right language (from slice settings) - languages are used for button texts, ...
                //mgettext_bind($slice->getLang(), 'output');
                mgettext_bind(\AA\Util\Langs::currentLang(), 'output');    // the lang itself should be set in AA::$lang and AA::$encoding (maybe from current site language)

                if ( !($field = $slice->getField($field_id)) ) {
                    return '';
                }
                $widget_prop = self::WidgetPropsString2Arr($widget_properties);
                $widget      = $field->getWidget($widget_type, $widget_prop);

                $widget_prop['multiple'] =  $widget::multiple();
                if ($required==1) {
                    $widget_prop['required'] =  $required;
                }
                //$widget_properties['name'], $widget_properties['input_help'], ... could be set already in the $widget_properties array
                $aa_property = $field->getAaProperty($widget_prop);
                $ret         = $widget?->$type($aa_property, $itemcontent, $function) ?? '';
                // $widget->registerRequires(); // now in _finalizeHtml
                AA::Stringexpander()->addRequire('aa-jslib');  // for {live...} - maybe we should distinguish
            }
        }
        return $ret;
    }

    static function WidgetPropsString2Arr($widget_properties) {
        // const_arr in widget_properties could be specified as (1) JSON object, or as (2) string with JSON object - see examples(1) and (2) above
        $widget_prop = json2asoc($widget_properties);
        if (isset($widget_prop['const_arr']) AND !is_array($widget_prop['const_arr']) AND (str_starts_with($widget_prop['const_arr'], '{"'))) {
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
        return $this->_getWidget('getHtml',$item_id, $field_id, $required, $function, $widget_type, $widget_properties);
    }
}

/** Allows on-line editing of field content
 *    {live:<item_id>:<field_id>:<required>:<function>:<widget_type>}
 *
 *   <required>    explicitly mark the live field as required (0|1)
 *   <function>    specify javascript function, which is executed after the widget
 *                  is submitted
 *   <widget_type> which widget to show
 *  {live::start_date......:::dte:{"show_time":"1"}}
 *  {live::number..........:::sel:{"const_arr":{"0":"no","1":"yes"}}}
 *  {live:{_#ITEM_ID_}:relation........::function() { location.reload(); } }
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
 *  {ajax:{_#ITEM_ID_}:switch.........1:_#IS_CHECK<button _#AA_AJAX_>Change it</button>}
 *  {ajax:{_#ITEM_ID_}:file............:<img src="/img/edit.gif" title="Upload new file"> :AA_Refresh('stickerdiv1')}
 *  {ajax:{_#ITEM_ID_}:file............:<img src="/img/edit.gif" title="Upload new file"> :AA_Refresh(this)}   // updates the first element with data-aa-url in DOM up
 *  {ajax::number..........:_#ANSWER__::sel:{"const_arr":{"0":"no","1":"yes"}}}
 *
 *  Editing could be switched off by defining the constant AA_FLAG_NOEDIT: {define:AA_FLAG_NOEDIT:1}
 **/
class AA_Stringexpand_Ajax extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it should always look in the database

    // not needed right now for Nevercached functions, but who knows in the future
    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
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
            if (!empty($item) AND !$item->isEmpty()) {
                $show_alias  = ($show_alias == '') ? "{alias:$field_id:f_h:, }" : $show_alias;
                if (strlen(trim($repre_value = $item->subst_alias($show_alias))) < 1) {
                    $repre_value = '--';
                }
                if (StrExp('var', ['AA_FLAG_NOEDIT']) == 1) { // the way, how to disable editing
                    $ret = $repre_value;
                } else {

                    // all this code is for class we put on the ajax container in order we can style it
                    $slice  = AA_Slice::getModule($item->getSliceID());
                    $field  = $slice->getField($field_id);

                    $container_class = ' aa-ajax-fld-'.AA_Fields::getVarFromFieldId($field_id);
                    if ($widget = $field?->getWidget($widget_type, json2asoc($widget_properties))) {
                        if ($class_type = strtolower(AA_Components::getClassType(get_class($widget), 'Widget'))) {
                            $container_class .= ' aa-ajax-widget-'.$class_type;
                        }
                    }

                    $widget_properties = str_replace(["'", '"',"\r\n", "\r", "\n"], ["\'", "&quot;", " ", " ", " "], $widget_properties);
                    $iid         = $item->getItemID();
                    $input_name  = FormArray::getName4Form($field_id, $item);
                    $input_id    = FormArray::formName2Id($input_name);

                    $enc_credentials = AA_Credentials::singleton()->getAsToken();

                    // the whole content works as button by default. You can redefine it using _#AA_AJAX_ alias like:   The text which works with value <button _#AA_AJAX_>Change it</button>
                    $ajax_on     = "onclick=\"displayInput('ajaxv_$input_id', '$iid', '$field_id', '$widget_type', '$widget_properties', '$enc_credentials')\"";
                    if (str_contains($repre_value, '_#AA_AJAX_')) {
                        $repre_value = str_replace('_#AA_AJAX_', $ajax_on, $repre_value);
                        $show_alias  = str_replace('_#AA_AJAX_', $ajax_on, $show_alias);
                        $ret .= "<div class=\"ajax_container $container_class\" id=\"ajaxc_$input_id\" style=\"display:inline-block;width:100%\">";
                    } else {
                        $ret .= "<div class=\"ajax_container $container_class\" id=\"ajaxc_$input_id\" style=\"display:inline-block;width:100%\" $ajax_on>";
                    }

                    //$ret .= "<div class=\"ajax_container\" id=\"ajaxc_$input_id\" onclick=\"displayInput('ajaxv_$input_id', '$iid', '$field_id', '$widget_type', '$widget_properties')\" style=\"display:inline-block;width:100%\">";
                    $data_onsuccess = $onsuccess ? 'data-aa-onsuccess="'.safe($onsuccess).'"' : '';
                    $ret .= "<div class=\"ajax_value\" id=\"ajaxv_$input_id\" data-aa-alias=\"".safe(base64_encode($show_alias))."\" $data_onsuccess style=\"display:inline\">$repre_value</div>";
                    $ret .= "<div class=\"ajax_changes\" id=\"ajaxch_$input_id\" style=\"display:inline\"></div>";
                    $ret .= "</div>";
                    AA::Stringexpander()->addRequire('aa-jslib');  // for DisplayInput()
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
    // It works with database, so it should always look in the database

    // not needed right now for Nevercached functions, but who knows in the future
    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
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
                    $ret     = AA::Un("<$tag contenteditable=true id=\"". str_replace('.','_', "au-$iid-$field_id-$index")."\" $pholder data-aa-id=\"$iid\" data-aa-field=\"$field_id\" data-aa-index=\"$index\">{". $field_id ."}</$tag>", '', $item);

                    AA::Stringexpander()->addRequire('ckeditor');

                    // '_aa_url = "'.AA_INSTAL_PATH.'";' . "\n" .
                    $script2run = 'CKEDITOR.on( "instanceCreated", function( event ) {' . "\n" .   // The "instanceCreated" event is fired for every editor instance created.
                        '  var editor = event.editor;' . "\n" .                          // var toolbarcreated = false;
                        '  editor.on( "change", function() {' . "\n" .                   // if (!toolbarcreated) { toolbarcreated = true; }     // not necessary to run the code on each change
                        '    window.addEventListener("beforeunload", AA_WindowUnloadQ);' . "\n" .
                        '    AA_Toolbar(\'<input type="button" value="'. _m('save changes').'" onclick="AA_SaveEditors();">\');' . "\n" .
                        '  });' . "\n" .
                        '});' . "\n";
                    AA::Stringexpander()->addRequire('aa-jslib');  // for AA_Toolbar(),...
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
 *
 * {newitem:c239cb267837a25b4efb5892ca4f4324:7735375488a65e7735375488a65eab2a/relation.......1:copy:category........:["enviro","social"]}
 *   - item in related slice 773.. through field relation.......1 will be copied and associated with new item (probably some items related data)
 *   - works 7735375488a65e7735375488a65eab2a/relation.......1:copy,
 *   - not yet implemented: 7735375488a65e7735375488a65eab2a/*:copy, and @relation.......2:copy for all related items in slice respectively all pointed items by relation.......2 field
 */
class AA_Stringexpand_Newitem extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it should always look in the database

    /** expand function
     * @param $template_item_id
     * @param $field_id1
     * @param $value1
     * @param ...
     * @return string
     */
    function expand(...$arg_list) {
        if (!is_long_id($template_long_item_id = array_shift($arg_list))) {
            warn('No template id');
            return '';
        }

        [$transformations,$copies] = self::_getTransformationsFromArgs($arg_list);

        // move to active, clear seo
        if ( !isset($transformations['status_code.....']) ) {
            $transformations['status_code.....'] = new AA_Transformation_Exactvalues(['new_content'=> [1]]); // It is rewritten be the setting on Slice Admin - bin for Anonymous Insert
        }
        if ( !isset($transformations['seo.............']) ) {
            $transformations['seo.............'] = new AA_Transformation_Exactvalues(['new_content'=> ['']]);
        }

        // return IfSlPerm(PS_EDIT_ALL_ITEMS, $manager->getModuleId());

        // the template must be in related slices (to not allow to create item in foreign slice)
        $slices = AA::$slice_id ? [AA::$slice_id] : [];
        $slices = array_merge($slices, $this->getRelatedModules());

        if (!count($slices)) {
            warn('No related slice found');
            return '';
        }

        $grabber = new AA\IO\Grabber\Slice(new AA_Set($slices,null,null,AA_BIN_ALL), new zids($template_long_item_id,'l'));
        // insert_if_new is the same as insert, (but just make sure the item is not in DB which is not important here)
        $saver   = new AA\IO\Saver($grabber, $transformations, null, 'insert_if_new', 'new');
        [$ok,$err] = $saver->run();
        $err && warn('Saver run err '. $err. ': '. $saver->report());

        $item_inserted = $saver->changedIds()[0];

        // now solve copies
        foreach( $copies as [$sl,$fi] ) {
            if (!in_array($sl, $slices, true)) {
                warn("newitem - itemcopy of $sl/$fi - Slice is not in related slices");
                continue;
            }
            if (!AA_Slice::getModule($sl)?->isField($fi)) {
                warn("newitem - itemcopy of $sl/$fi - Field is not in slice");
                continue;
            }
            $transformations = [$fi => new AA_Transformation_Exactvalues(['new_content'=> [$item_inserted]])];

            // $fi = $fi === '*' ? 'all_fields' : $fi;  // not yet implemented
            $grabber = new AA\IO\Grabber\Slice(new AA_Set([$sl], new AA_Condition($fi,'==',$template_long_item_id)));
            $saver2   = new AA\IO\Saver($grabber, $transformations, null, 'insert_if_new', 'new');
            [$ok,$err] = $saver2->run();
            $err && warn("Saver run err for related $sl/$fi ". $err. ': '. $saver2->report());
        }
        return implode('-',$saver->changedIds());
    }

    /**
     * @param $arg_list
     * @return array [$transformations[], $copies[]]
     */
    protected static function _getTransformationsFromArgs($arg_list): array
    {
        $i                     = 0;
        $transformations       = [];
        $copies                = [];
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            // accept field id 16 chars long or 17 if ends with +
            $field_id = $arg_list[$i];
            $to_val   = $arg_list[$i + 1];
            $transformation = '';
            switch (strlen($field_id)) {
                case 16:
                    $transformation = 'AA_Transformation_Exactvalues';
                    break;
                case 17:
                    if (str_ends_with($field_id, '+')) {
                        $field_id       = substr($field_id, 0, 16);
                        $transformation = 'AA_Transformation_AddValue';
                    }
                    elseif ($to_val==='copy' && str_starts_with($field_id, '@')) {   // @relation........:copy - make copy of related items pointed to by relation........ field
                        // not yet implemented
                    }
                    break;
                case 49:  // 7735375488a65e7735375488a65eab2a/relation.......1:copy - make copy of related items in slice 77.. on field relation.......1

                    [$sl,$fi] = explode('/', $field_id, 2);
                    if ($to_val==='copy' && strlen($fi)===16 && is_long_id($sl)) {
                        $copies[] = [$sl,$fi];
                    }
                    break;
                case 34:  // 7735375488a65e7735375488a65eab2a/* - make copy of related items in slice 77.. on all fields
                    // not yet implemented in {newitem...}
                    [$sl,$fi] = explode('/',$field_id, 2);
                    if ($to_val==='copy' && $fi==='*' && is_long_id($sl)) {
                        $copies[] = [$sl,$fi];
                    }
                    break;
            }
            if ($transformation) {
                if (!count($value = json2arr($to_val))) {
                    $value = [''];
                }
                $transformations[$field_id] = new $transformation(['new_content' => $value]);
            }
            $i += 2;
        }
        return [$transformations,$copies];
    }
}



/** Returns ID of existing item or creates new one and returns its ID
 *    {ensureitem:<existing_id>:<template_long_item_id>[:<field_1>:<new_value_1>:<field_2>:<new_value_2>:...]}
 *    {ensureitem:{ids:5b4efb5892ca4f4329cb4c23267837a2:d-relation........-==-{id..............}}:c239cb267837a25b4efb5892ca4f4324:headline........:test item:category........:["enviro","social"]}
 * Similar to {newitem}, but it allows to search for already existing item
 * @see  AA_Stringexpand_Newitem
 */
class AA_Stringexpand_Ensureitem extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it should always look in the database

    /** expand function
     * @param $existing_id
     * @param $template_item_id
     * @param $field_id1
     * @param $value1
     * @param ...
     * @return string
     */
    function expand(...$arg_list) {
        if (is_long_id($existing_id = array_shift($arg_list))) {
            return $existing_id;
        }
        return StrExp('newitem', $arg_list);
    }
}

/** Updates item fields
 *    {updateitem:<long_item_id>[:<field_1>[+]:<new_value_1>:<field_2>[+]:<new_value_2>:...]}
 *
 *  It works only on slices where "Allow anonymous editing of items" is set to "All items" or "Only by Planned tasks - cron|toexecute", right now.
 *  The plan is to work with field permissions in future
 *
 *    {updateitem:c239cb267837a25b4efb5892ca4f4324:headline........:test item:category........:["enviro","social"]}
 *    {updateitem:c239cb267837a25b4efb5892ca4f4324:relation........+:{ensureitem:...}}
 *
 *  - c239cb267837a25b4efb5892ca4f4324 is ID of the updated item
 *  - The values could be single (for headline........), or multiple - written in JSON format.
 *  - If you want to add new value to the field, just append + to the field id
 *  - If you want to update the item conditionally just do not fill <long_item_id> in cases you do not want update
 *
 * @return string <long_item_id> if updated, nothing if item is not updated
 *
 */
class AA_Stringexpand_Updateitem extends AA_Stringexpand_Newitem {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it should always look in the database

    /** expand function
     * @param $item_id
     * @param $field_id1
     * @param $value1
     * @param ...
     * @return string
     */
    function expand(...$arg_list) {
        $zid      = new zids(array_shift($arg_list), 'l');

        if ( !is_object($item = AA_Items::getItem($zid)) ) {
            warn(_m('No item ID'));
            return '';
        }

        [$transformations,$copies] = self::_getTransformationsFromArgs($arg_list);

        if ( !count($restrict_fields  = array_filter(array_keys($transformations), static function($fld) { return strlen($fld)===16; })) ) {
            warn(_m('No transformations'));
            return '';
        }

        $item_slice = $item->getSliceId();
        $slices = [];
        if (AA::$site_id) {
            $slices = AA_Module_Site::getRelatedModules([AA::$site_id]);
        } elseif (AA::$module_id) {  // called inside AA admin interface
            $slices = [AA::$module_id];
        } elseif (AA::$slice_id) {  // called in view
            $slices = [AA::$slice_id];
        }

        if (!$item_slice)                    { warn(_m('No slice for item')); return ''; }
        if (!count($slices))                 { warn(_m('No current slice(s)')); return ''; }
        if (!in_array($item_slice, $slices)) { warn(_m('Item slice is not in list of allowed slices')); return ''; }

        $slice_perm = AA_Slice::getModuleProperty($item_slice,'permit_anonymous_edit');
        $perm = (($slice_perm==ANONYMOUS_EDIT_ALL) OR (($slice_perm==ANONYMOUS_EDIT_CRON) AND (AA::$perm->getPermMode()==='cron')));
        if (!$perm) {
            warn(_m('No permit_anonymous_edit permission for the slice'));
            return '';
        }

        $grabber = new AA\IO\Grabber\Slice(new AA_Set($slices,null,null,AA_BIN_ALL), $zid, $restrict_fields);
        $saver   = new AA\IO\Saver($grabber, $transformations, $item_slice, 'update');
        $saver->run();
        return implode('-',$saver->changedIds());
    }
}

/** Get module (slice, ...) property (currently only "module fileds"
 *  (beggining with underscore), 'url', 'name', 'charset' and 'site_ids' is supported
 *  replacing older {alias:_abstract.......:f_s:slice_info} syntax
 *  Usage:
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
        // that's why we restrict it to the properties, which makes sense
        // @todo - make it less restrictive

        switch ($property) {
            // site_id is older, but better is to use site_ids, since it could return more than one ids (dash separated)
            case 'site_ids':
            case 'site_id':
                return implode('-', AA_Module_Site::belongsToSites($slice_id));
            case 'charset':
                return ($m = AA_Module::getModule($slice_id)) ? $m->getCharset() : '';
        }
        if (!AA_Fields::isSliceField($property)) {  // it is "slice field" (begins with underscore _)
            $property = ($property==='url') ? 'slice_url' : 'name';
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
        if ($property === 'modules' AND ($site_ids_arr = explode_ids($site_ids))) {
            return implode('-' , AA_Module_Site::getRelatedModules($site_ids_arr));
        }
        return '';
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
        return (AA::$site_id AND ctype_digit((string)$spot_id)) ? AA_Module_Site::spotsOutput(AA::$site_id, [$spot_id]) : '';
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

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        /** output is different for different slices - place item id into cache search */
        return is_object($this->item) ? $this->item->getSliceID() : $GLOBALS['slice_id'];
    }

    /** expand function
     * @param $property
     * @return string
     */
    function expand($property='name') {
        // get slice_id from item, but sometimes the item is not filled (like
        // on "Add Item" in itemedit.php, so we use global slice_id here
        $item = $this->item;
        $slice_id  = $item ? $item->getSliceID() : $GLOBALS['slice_id'];
        if (!$slice_id ) {
            return "";
        }
        return StrExp('modulefield', [$slice_id, $property]);
    }
}

/** do not use for site modules - use AA_Stringexpand_Pager instead
 *
 *  {scroller:<begin>:<end>:<add>:<nopage>}
 *  Displays page scroller for view
 */
class AA_Stringexpand_Scroller extends AA_Stringexpand {
    /** Do not trim all parameters ($add could contain space) */
    static function doTrimParams() { return false; }

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        $itemview = $this->itemview;
        return !is_object($itemview) ? '' : $itemview->slice_info['vid'].':'.$itemview->clean_url.':'.$itemview->num_records.':'.$itemview->idcount().':'.$itemview->from_record;
    }

    /** expand function
     * @param $property
     * @return string
     */
    function expand($begin='', $end='', $add='', $nopage='') {
        $itemview = $this->itemview;
        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th group display
            return "Scroller not valid without a view, or for group display";
        }
        return AA_Scroller_Sitemodule::fromItemview($itemview)->get( $begin, $end, $add, $nopage);
    }
}

/** page scroller for site modules views - displays page scroller for view
 *
 *  It calls router methods, so it displays the right urls in the scroller
 *  @see AA_Router::scroller() method
 *
 *  Must be issued inside the view
 *
 *  Now it is possible to use {pager} on views called by AJAX (for live searches, ...).
 *  Just put the parameter "div-id" to pager:
 *           {pager:results}
 *  where result is the div id, in which the view displays the values
 *           <div id="results">..view output there</div>
 */
class AA_Stringexpand_Pager extends AA_Stringexpand_Nevercache {

    // not needed right now for Nevercached functions, but who knows in the future
    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
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

        $target = (string)$target;

        $itemview = $this->itemview;
        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th group display
            return "Err in {pager} - pager not valid without a view, or for group display";
        }

        if ($target) {
            AA::Stringexpander()->addRequire('aa-jslib');  // for AA_Ajax
        }

        if (!isset($apc_state['router'])) {
            // used for AJAX scroller in the SEO sitemodule, for example
            return AA_Scroller_View::fromItemview($itemview)->get( '', '', '', '', $target);
        }

        return AA_Router::singleton($apc_state['router'])->getScroller($itemview)->scroller($target);
    }
}


/** page scroller for site modules views - displays page scroller for view
 *
 *  It calls router methods, so it displays the right urls in the scroller
 *  @see AA_Router::scroller() method
 *
 *  Must be issued inside the view
 *
 *  Now it is possible to use {pager} on views called by AJAX (for live searches, ...).
 *  Just put the parameter "div-id" to pager:
 *           {pager:resuts}
 *  where result is the div id, in which the view dispays the values
 *           <div id="results">..view output there</div>
 */
//class AA_Stringexpand_Pagermore extends AA_Stringexpand_Nevercache {
//// @todo - experimental - not finished
//
//
//    // not needed right now for Nevercached functions, but who knows in the future
//    /** additionalCacheParam function - the same as for AA_Stringexpand_Pager @todo - check if need change
//     * @param array $params parameters passed to expand (caching could be parameter sensitive).
//     * @return string - for not cache, return random value
//     */
//    function additionalCacheParam(array $params= []) {
//        global $apc_state;
//        $itemview = $this->itemview;
//        return !is_object($itemview) ? '' : serialize($apc_state['router']).':'.$itemview->num_records.':'.$itemview->idcount().':'.$itemview->from_record;
//    }
//
//
//    /** expand function
//     * @param $property
//     * @return mixed|string
//     */
//    function expand($name="") {
//        $hash   = get_short_hash('pagermore', $name);
//        $cmdvar = 'aapg_'.$hash;
//
//        $itemview = $this->itemview;
//        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th group display
//            return "Err in {pager} - pager not valid without a view, or for group display";
//        }
//
//        $code = gensalt(8);
//
//        $ret  = '<div data-aa-part="'.$hash.'">'.$code.'</div>';
//
//        AA::Stringexpander()->setDependentParts([$cmdvar], $hash, $ret);
//        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_SiteNewitem, ...
//
//        return $ret.'<article><i class="ico edit" onclick="AA_SiteEdit(\'2\',\''.$cmdvar.'\');">' . 2 . '</i></article>';
//    }


////    function expand() {
////        global $apc_state;
////
////        $itemview = $this->itemview;
////        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th group display
////            return "Err in {pager} - pager not valid without a view, or for group display";
////        }
////
////        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_Ajax
////
////        $target = '';
////
////        $viewScr = new AA_Scroller_View($itemview->slice_info['vid'], $itemview->num_records, $itemview->idcount(), $itemview->from_record);
////        return $viewScr->get( '', '', '', '', $target);
////    }
//}

/** switches off internal stringexpand cache or pagecache
 *  {nocache} or {nocache:2} switches off internal stringexpand cache for all {aa-expressions} (we use it in email templates, where the parameters are sent by {define})
 *  {nocache:1} switches off pagecache for the current page
 *  {nocache:3} switches off both - pagecache and stringexpand cache
 */
class AA_Stringexpand_Nocache extends AA_Stringexpand_Nevercache {

    function expand($level='') {
        $level = strlen($level) ? (int)$level : 2; // as default, we stop internal caching
        if ($level & 1) {
            AA::Pagecache()->switchCacheNostore();
        }
        if ($level & 2) {
            AA_Stringexpand::switchCacheOff();
        }
        return '';
    }
}


/** start/stop debugging {debug:<level>[:<store>]}    <level> - 0 - no debug, 1 - 511 set debug level (as bitfield)
 * Usage:
 *        {debug:3} - set debug level to 3
 *        {debug:0} - stop debugging
 *        {debug}   - stop debugging
 *        {debug:3:temp}  - set debug level to 3 and store the current debug level to internal debug temp variable
 *        {debug::temp}   - restore debug level from internal debug temp variable (stored above)
 */
class AA_Stringexpand_Debug extends AA_Stringexpand_Nevercache {

    /**
     * @param string $level
     * @return string
     */
    function expand( $level='', $store='') {
        static $levels = [];
        $ret = '';
        if ( !$level ) {
            AA::$debug = strlen($store) ? ($levels[$store] ?? 0) : 0;
        } elseif (is_numeric($level)) {
            if (strlen($store)) { $levels[$store] = AA::$debug; }
            AA::$debug = (int)$level;
        } else {
            // do not rely on this - could be changed. If you want specific format, then add any text parameter
            $ret = "\nDatabase instances: ". DB_AA::$_instances_no;
        }
        return $ret;
    }
}

/** Writes message to AA Log 
 */
class AA_Stringexpand_Debuglog extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $text
     * @return string
     */
    function expand( $text='' ) {
        AA_Log::write('DEBUG', AA_Log::context(), $text);
        return '';
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
    static function doTrimParams() { return false; }

    /** expand function
     *
     * @param $dictoinaries
     * @param $text
     * @param $format
     * @param $conds
     *
     * @return mixed|null|string|string[]
     */
    function expand($dictoinaries='', $text='', $format='', $conds='') {
        // sometimes this function last to much time - try to extend it
        if (($max_execution_time = (int)ini_get('max_execution_time')) > 0) {
            MoreTimeLimit($max_execution_time+20);
        }

        $dict_arr = explode_ids($dictoinaries);

        $delimiters = self::defineDelimiters();
        // get pairs (like APC - <a href="https://apc.org">APC</a>' from dict. slice
        // (we call it through the pagecache in order it is called only once for
        // the same parameters)
        $replace_pairs = AA::Pagecache()->cacheMemDb(['AA_Stringexpand_Dictionary','getDictReplacePairs'], [$dict_arr, $format, $delimiters, $conds], new CacheStr2find($dict_arr));

        // we do not want to replace text in the html tags, so we substitute all
        // html with "shortcut" (like _AA_1_ShCuT) and the content is stored in the
        // $html_subst_arr. Then it is used with replace_pairs to return back

        $html_subst_arr = [];
        $search = [
            "'<script[^>]*?>.*?</script>'si",  // Strip out javascript
            "'<h[1-6][^>]*?>.*?</h[1-6]>'si",  // Strip out titles
            // can't be nested
            "'<a[^>]*?>.*?</a>'si",            // Strip out links
            "'<[/!]*?[^<>]*?>'si",
        ];          // Strip out HTML tags

        // substitute html tags with shortcuts
        // (= remove the code where we do not want to replace text)
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

        // finally - removing additional vaste text 'AA#@' - recovering original word delimiters
        return str_replace('AA#@', '', $text);
    }

    /** getDictReplacePairs function
     *  Return array of substitution pairs for dictionary, based on given dictionary
     *  slice, format string which defines the format and possible slice codnitions.
     *   [biom] => <a href="http://biom.cz">_#KEYWORD_</a>, ...
     * @param        $dictionaries (array)
     * @param        $format
     * @param        $delimiters
     * @param string $conds
     * @return array
     */
    static function getDictReplacePairs($dictionaries, $format, $delimiters, $conds = '') {
        // return array of pairs: [biom] => <a href="http://biom.cz">_#KEYWORD_</a>
        $replace_pairs = [];

        // conds string could contain also sort[] - if so, use conds also as $sort
        // parameter (the sort is grabbed form the string then)
        $sort = (str_contains($conds, 'sort')) ? $conds : '';

        /** 'keywords........' field could contain multiple values. In this case we
         *  have to create pair for each of the word. The _#KEYWORD_ alias is then
         *  used in format string
         */

        $format = AA_Slice::getModule($dictionaries[0])->getField($format) ? '{substr:{' . $format . '}:0:50}' : $format;
        $format = "{@keywords........:##}_AA_DeLiM_$format";

        // above is little hack - we need keyword pair, but we want to call
        // GetFormatedItems only once (for speedup), so we create one string with
        // delimiter:
        //   BIOM##Biom##biom_AA_DeLiM_<a href="http://biom.cz">_#KEYWORD_</a>

        $set     = new AA_Set($dictionaries, $conds, $sort);
        $kw_item = GetFormattedItems($set->query(), $format);

        foreach ($kw_item as $kw_string) {
            [$keywords, $link] = explode('_AA_DeLiM_', $kw_string, 2);
            $kw_array = explode('##', $keywords);
            foreach ((array)$kw_array as $kw) {
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
                $search_kw                 = 'AA#@' . strtr($kw, $delimiters) . 'AA#@';
                $replace_pairs[$search_kw] = str_replace('_#KEYWORD_', $kw, $link);
                if (($first_upper = strtoupper($kw[0])) != $kw[0]) {
                    // do the same for the word with first letter in uppercase
                    $kw[0]                     = $first_upper;
                    $search_kw                 = 'AA#@' . strtr($kw, $delimiters) . 'AA#@';
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
    static function defineDelimiters(): array
    {
        $delimiter_chars = "()[] ,.;:?!\"'\n\r";   // I removed & in order you can disable substitution by adding
        // &nbsp; or even better &zwnj; character to the word - like: gender&zwnj;
        $delimiters = [];
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
            case "ajax":
                $fileout = expandFilenameWithHttp($fn, 'ajax');
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
                if ($type === "site") {
                    if (!($fileman_dir = $GLOBALS['site_fileman_dir'])) {
                        // if ($errcheck) huhl("No site_fileman_dir defined in site file");
                        return "";
                    }
                }
                $filename = FILEMAN_BASE_DIR . $fileman_dir . "/" . $fn;
                $file = AA_File_Wrapper::wrapper($filename);
                // $file->contents(); opens the stream, reads the data and close the stream
                $fileout = $file->contents();
                break;
            case "readfile": //simple support for reading static html (use at own risk)
                $filename = $_SERVER["DOCUMENT_ROOT"] . "/" . $fn;
                $file = AA_File_Wrapper::wrapper($filename);
                // $file->contents(); opens the stream, reads the data and close the stream
                $fileout = $file->contents();
                break;
            default:
                // if ($errcheck) huhl("Trying to expand include, but no valid type: $type");
                return '';
        }
        return $fileout;
    }
}


/** expandFilenameWithHttp function
 *  Expand any quotes in the parturl, and fetch via http
 * @param string $parturl
 * @param string $type
 * @return false|string
 */
function expandFilenameWithHttp($parturl, string $type='') {
    $filename = str_replace( 'URL_PARAMETERS', DeBackslash(shtml_query_string()), $parturl);

    // filename do not use colons as separators => dequote before callig
    if (!$filename || trim($filename)=="") {
        return "";
    }

    $headers  = ($type === 'ajax') ? ['X-Requested-With'=>'XMLHttpRequest'] : [];
    // if no http request - add server name
    if (!(aa_substr($filename, 0, 7) === 'http://') AND !(aa_substr($filename, 0, 8) === 'https://')) {
        $filename = self_server(). (($filename[0] === '/') ? '' : '/'). $filename;
        if ($_COOKIE) {
            // we resend cookies only for local requests (It could be useful for AA_Auth ...)
            $headers['Cookie'] = http_build_query($_COOKIE, '', '; ');
        }
    }

    return AA_Http::postRequest($filename, [], $headers);
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
    // is useful just in case we are using the same expresion inside the same
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

        // See https://actionapps.org/faq/#aliases for details
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
        // {view.php?vid=12&cmd[12]=x-12-34}
        // {dequote:already expanded and quoted string}
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
                if ($out[1] === "#") {
                    if (isset($als[aa_substr($out,2)])) {
                        return QuoteColons(AA::Un($als[aa_substr($out,2)], '', $this->item, false, $this->itemview));
                    }

                    if (isset($this->item)) {
                        // just alias or not so common: {_#SOME_ALSand maybe some text}
                        return QuoteColons(($outlen === 10) ? $this->item->get_alias_subst($out) : $this->item->substitute_alias_and_remove($out));
                    }
                    //// This somehow did not work for {item:{xid:1}:{ifeq:{_#EVERMA21}:1::...}} so the concept of localcache is removed. Honza 2015-07-27
                    // if (isset($this->_localcache[$loccache_id = $out.$this->item->getItemID()])) {
                    //     return $this->_localcache[$loccache_id];
                    // }
                    // if (isset($als[substr($out,2)])) {
                    //     return ($this->_localcache[$loccache_id] = QuoteColons(AA::Un($als[substr($out,2)], '', $this->item, false, $this->itemview)));
                    // } elseif (isset($this->item)) {
                    //     // just alias or not so common: {_#SOME_ALSand maybe some text}
                    //     return ($this->_localcache[$loccache_id] = QuoteColons(($outlen == 10) ? $this->item->get_alias_subst($out) : $this->item->substitute_alias_and_remove($out)));
                    // }
                }
                break;
        }

        if (is_object($this->item)) {
            if ($outlen === 16) {
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
                        if ($this->item->isField($out)) {
                            return QuoteColons($this->item->f_h($out, 'AA_DashOrLanG'));
                            // QuoteColons used to mark colons, which is not parameter separators.
                        }
                }
              // this in not AA Item - it is probably AA_Content of table, ...
            } elseif ($this->item->isField($out)) {
                return QuoteColons($this->item->f_1($out)); // we use f_1 because for not AA Items we probably do not use multivalues, ...
                // QuoteColons used to mark colons, which is not parameter separators.
            }
        }

        // if in_array - for speedup
        if (in_array(aa_substr($out, 0, 5), ['const', 'alias', 'math(', 'inclu', 'view.', 'dequo', 'list:'])) {
            // look for {const_*:} for changing viewing type of constants
            if ((aa_substr($out, 0, 6) === "const_") AND isset($this->item)) {
                // $what - name of column (e.g. from const_name we get name)
                $what = aa_substr($out, aa_strpos($out, "_")+1, aa_strpos($out, ":") - aa_strpos($out, "_")-1);
                // parameters - first is field
                $parts = ParamExplode(aa_substr($out,aa_strpos($out,":")+1));
                // get group id
                $group_id = AA_Slice::getModule($this->item->getSliceID())?->getFields()->getCategoryGroup($parts[0]);
                /* get short_id/name/... of constant with specified value from constants category with
                   group $group_id */

                $value = getConstantValue($group_id, $what, $this->item->getval($parts[0]));

                return QuoteColons($value);
            }
            // s - multiline - without it, it does not work for expressions across multiple lines
            elseif ( (aa_substr($out, 0, 5)==='alias') AND isset($this->item) AND preg_match('/^alias:([^:]*):([a-zA-Z0-9_]{1,3}):?(.*)$/s', $out, $parts) ) {
                // call function (called by function reference (pointer))
                // like f_d("start_date......", "m-d")
                if ($parts[1] && !$this->item->isField($parts[1])) {
                    huhe("Warning: $out: $parts[1] is not a field, don't wrap it in { } ");
                }
                $fce     = $parts[2];
                return QuoteColons($this->item->$fce($parts[1], $parts[3]));
                // QuoteColons used to mark colons, which is not parameter separators.
            }
            elseif ( aa_substr($out, 0, 5) === "math(" ) {
                // replace math
                return QuoteColons( parseMath(DeQuoteColons(AA::Un(aa_substr($out,5), '', $this->item, false, $this->itemview))) ); // Need to unalias in case expression contains _#XXX or ( )
            }
            elseif ( aa_substr($out, 0, 5) === "list:" ) {       // like AA_Stringexpand_List
                return QuoteColons(parseLoop($out, $this->item));
            }
            elseif ( aa_substr($out, 0, 8) === "include(" ) {
                // include file
                if ( !($pos = aa_strpos($out,')')) ) {
                    return "";
                }
                $fileout = expandFilenameWithHttp(DeQuoteColons(aa_substr($out, 8, $pos-8)));
                return QuoteColons($fileout);
                // QuoteColons used to mark colons, which is not parameter separators.
            }
            elseif ( (aa_substr($out,0,9) === 'view.php?') OR (aa_substr($out,0,10) === 'view.php3?') ) {
                // CKEditor editor replaces & with &amp; so we need to change it back
                $param      = str_replace(['&amp;','-&lt;','-&gt;','&lt;-','&gt;-'], ['&','-<','->','<-','>-'], aa_substr($out, (aa_substr($out,0,9) === 'view.php?') ? 9 : 10));
                // do not store in the pagecache, but store into contentcache

                $showview   = new AA_Showview(AA_View::parseViewParameters(DeQuoteColons($param)));

                return QuoteColons(AA::Contentcache()->get_result([$showview,'getViewOutput']));
            }
            // This is a little hack to enable a field to contain expandable { ... } functions
            // if you don't use this then the field will be quoted to protect syntactical characters
            elseif ( aa_substr($out, 0, 8) === "dequote:" ) {   // like AA_Stringexpand_Dequote
                return DeQuoteColons(aa_substr($out,8));
            }
        }
        // OK - it's not a known fixed string, look in various places for the whole string
        // if ( preg_match('/^([a-zA-Z_0-9]+):?([^}]*)$/', $out, $parts) ) {
        // the longest php function name in whole PHP is 38 chars - test 40 should be enough

        $initiallen = strspn(strtolower(aa_substr($out,0,40)), 'abcdefghijklmnopqrstuvwxyz0123456789_');
        if ( $outcmd   = aa_substr($out,0,$initiallen) AND (($initiallen === strlen($out)) OR ($out[$initiallen] === ':')  OR ($out[$initiallen] === '(') ) ) {
        // if ( $outcmd   = aa_substr($out,0,$initiallen) ) {   // older - it also runs {math on {Math.parse(...
            $outparam = aa_substr($out,$initiallen+1);  // skip one more char - delimiter

            /** @var class-string<AA_Stringexpand> $class_name */
            if (class_exists($class_name = AA_Serializable::constructClassName($outcmd, 'AA_Stringexpand_'))) {
                return StrExpand($class_name, $class_name::parseParam($outparam), ['item'=>$this->item, 'itemview'=> $this->itemview], true);
            }

            if (is_callable($fnctn = "stringexpand_$outcmd")) {  // old version of stringexpand_ functions (could be in old custom aa setups)
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
            return QuoteColons(AA::Un($GLOBALS['apc_state'][$out], '', $this->item, false, $this->itemview));
        }

        if (isset($als[$out])) {
            return QuoteColons(AA::Un($als[$out], '', $this->item, false, $this->itemview));
        }

        if (aa_substr($out,0,8) === "mlx_view") {
            if(!$GLOBALS['mlxView']) {
                return (string)$out;
            }
            //$param = array_map('DeQuoteColons',ParamExplode($parts[2]));
            return $GLOBALS['mlxView']->getTranslations($this->item->getval('id..............'), $this->item->getval('slice_id........'),array_map('DeQuoteColons',ParamExplode($parts[2])));
        }
        // Pass these in URLs like als[foo]=bar,
        // Note that 8 char aliases like als[foo12345] will expand with _#foo12345
        // Put the braces back around the text and quote them if we can't match

        // Don't warn if { followed by non-alphabetic, e.g. in Javascript
        // Fix javascript to avoid this warning, typically add space after {
        // if ($errcheck && preg_match("/^[a-zA-Z_]/",$out)) {
        //     huhl("Couldn't expand: \"{$out}\"");
        // }
        return QuoteColons("{" . $out . "}");
    }
}

/**
 *  Usage: StrExpand('AA_Stringexpand_Date', [$format,$timestamp]);
 *          StrExpand($outcmd, [$format,$timestamp], ['item'=>$this->item, 'itemview'=> $this->itemview])
 * @param string $class_name
 * @param array $params
 * @param array $context_arr
 * @param bool  $allow_quote_colons
 * @param bool  $ignore_cache
 * @deprecated use StrExp() or AA::Un();
 * @return string
 */
function StrExpand($class_name, $params= [], $context_arr= [], $allow_quote_colons=false, $ignore_cache=false): string {
    /** @var AA_Stringexpand $stringexpand */
    $stringexpand = new $class_name($context_arr);
    if ( $stringexpand->doCache() AND !$ignore_cache) {
        $res = AA::Contentcache()->get_result_4_object([$stringexpand, 'expand'], $params, $class_name, $stringexpand->additionalCacheParam($params));
    } else {
        $res = call_user_func_array( [$stringexpand,'expand'], $params);
    }
    return (string)(($allow_quote_colons AND $stringexpand->doQuoteColons()) ? QuoteColons($res) : $res);
}

/**
 * Shorter version of StrExpand
 * @param string $class_name
 * @param array  $params
 * @return string
 */
function StrExp($class_name, $params= []): string {
    return StrExpand(AA_Serializable::constructClassName($class_name, 'AA_Stringexpand'), $params);
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
        $dc = DB_AA::select1('total', 'SELECT sum(disc_count) AS total FROM `item`', [['slice_id', $slice_id, 'l']]);
        return $dc ?: 0;
    }
}

/**
 * Class AA_Stringexpand_Preg_Match
 *  @usage {preg_match:/[0-9]{4}/:{headline........}} - extracts year from headline
 *  @usage {preg_match:/\D(\d{4})[\D]/:{headline........}x:1} - extracts year from headline - more accurate
 *
 */
class AA_Stringexpand_Preg_Match extends AA_Stringexpand_Nevercachenotrim {
    /** Do not trim all parameters (maybe we can?) */

    /** expand function
     * @param string $pattern
     * @param string $subject
     * @param string $matched (number - which match to take)
     * @return string
     */
    function expand($pattern='', $subject='', $matched='') {
        $matched = ctype_digit((string)$matched) ? (int)$matched : 0;
        preg_match($pattern, $subject, $matches);
        return $matches[$matched];
    }
}

/**
 * Class AA_Stringexpander
 */
class AA_Stringexpander implements \AA\Later\LaterInterface {
    /**
     * @var int
     */
    protected $recursion_count = 0;
    /**
     * @var array
     */
    protected $dependent_parts = [];
    /**
     * @var array
     */
    protected $postprocesess   = [];
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

    /** replaces inline aliasses {(....)} with {var:...} references (which will be later expanded into {...}) and defines appropriate contentcache entries
     *  this allows to write expressions like
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

    /** called as AA::Un();
     * @param $text string
     * @param $remove string
     * @param $item AA_Item
     * @param $dequote bool
     * @param $itemview itemview
     * @return string
     */
    function unalias($text, $remove='', $item=null, $dequote=true, $itemview=null ): string {
        
        $text = (string)$text;

        if (++$this->recursion_count > 5000) {
            --$this->recursion_count;
            return "Error: recursion detected";
        }

        $GLOBALS['g_formpart'] = 0;  // used for splited inputform into parts

        // Note ereg was 15 seconds on one multi-line example cf .002 secs
        //    while (ereg("^(.*)[{]([^{}]+)[}](.*)$",$text,$vars)) {

        $quotecolons_partly = 0;
        // to speeedup the process we check, if we have to look for {} pairs
        if (str_contains($text, '{')) {
            // to speeedup the process we check, if we have to look for {( ... )} pairs
            if (str_contains($text, '{(')) {
                // replace all {(.....)} with {var:...}, which will be expanded into {...}
                // this alows to write expressions like
                //   {item:6625:{(some text {headline........}...etc.)}}
                $text = (string)self::ApplyInlineAlias($text);
            }

            $last_replacements  = 0;
            // we store it in the variable -  AA::$debug could change in do-while loop {debug...} which thel leads to error
            ($do_trace = AA::$debug&4) && AA::$dbg->tracestart('unalias', $text);
            $callback = [new AA_Unalias_Callback($item, $itemview), AA::$debug&8 ? 'expand_bracketed_timedebug':'expand_bracketed'];
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
            $do_trace && AA::$dbg->traceend('unalias', "$quotecolons_partly - $text");
        }

        if (is_object($item)) {
            $text = $item->substitute_alias_and_remove($text, strlen($remove) ? explode('##',$remove) : []);
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
                if (is_string($text)) {
                    $arr[$k] = $this->unalias($text);
                }
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

    /** generates tags for <HEAD> in <!--AA-Generated-HEAD.... >
     * @return string
     */
    function getRequireHead() {
        return $this->requires->getHead();
    }

    /** generates tags for html page footer in <!--AA-Generated-FOOT.... >
     * @return string
     */
    function getRequireFoot() {
        return $this->requires->getFoot();
    }

    /** getRequireScript are counterparts to getRequireHead and getRequireFoot, but used in AJAX calls, where CSS and JS libs have to be loaded by script
     * @return string
     */
    function getRequireScript() {
        return $this->requires->getAsScript();
    }

    /**
     * @param $out
     * @return mixed
     */
    function postprocess($out) {
        $search_arr  = [];
        $replace_arr = [];

        // second round
        $search_arr2  = [];
        $replace_arr2 = [];
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
                case '// AAGenerateGACONSENT //':
                    $search_arr2[]  = $search;
                    if (str_contains($this->getRequireFoot(), 'smart-eu-cookies')) {
                        $rep = "// AA-Generated-GACONSENT - start";
                        $rep .= "\nvar aa_ga_storage = (document.cookie.indexOf('smart-eu-cookies') !== -1 && document.cookie.includes('smart-eu-cookies=1')) ? 'granted' : 'denied';";
                        $rep .= "\ngtag('consent', 'default', {'ad_storage': aa_ga_storage,'analytics_storage': aa_ga_storage,'personalization_storage': aa_ga_storage,'ad_personalization': aa_ga_storage,'ad_user_data': aa_ga_storage});";  // updated to consent mode v2
                        $rep .= "\n// AA-Generated-GACONSENT - end\n";
                        $replace_arr2[] = $rep;
                    } else {
                        $replace_arr2[] = "// AA-Generated-GACONSENT - no consent";
                    }
                    break;
                default:
                    $search_arr[]  = $search;
                    $replace_arr[] = $replace;
            }
        }
        if (count($search_arr))  {
            $out = str_replace($search_arr, $replace_arr, $out);
        }
        if (count($search_arr2)) {
            $out = str_replace($search_arr2, $replace_arr2, $out);
        }
        return $out;
    }

    /**
     * @param array $vars - slices or vars
     * @param $hash
     * @param $code
     */
    function setDependentParts($vars, $hash, $code) {
        foreach ($vars as $s) {
            if (isset($this->dependent_parts[$s])) {
                $this->dependent_parts[$s][] = [$hash,$code];
            } else {
                $this->dependent_parts[$s] = [[$hash,$code]];
            }
        }
    }

    /**
     * @param array $vars - slices or vars
     * @return array
     */
    function getDependentParts($vars) {
        $ret = [];
        foreach ($vars as $s) {
            if (is_array($this->dependent_parts[$s])) {
                foreach ($this->dependent_parts[$s] as $part) {
                    $ret[$part[0]] = $part[1];
                }
            }
        }
        if ($ret AND ($script = AA::Stringexpander()->getRequireScript())) {
            end($ret);
            $ret[key($ret)] .= '<script>'.$script.'</script>';
        }
        return $ret;
    }


    /** @inheritDoc
     * @param array $params [$text] to unalias
     * @usage as $toexecute->laterOnce($this, ["{recompute:85984589745662157859845897456621:text............}"], "Stringexp-Recompute-85..", 40, time() + 60 * 60 * 24); // once a day
     */
    public function toexecutelater($params = []) {
        [$text] = $params;
        return $this->unalias($text);
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
    function __construct($depend= [], $integrity='') {
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
    function getCode(): string  { return ''; }

    /** getExternal and getScript is counterpart to getCode, but used in AJAX calls - external for libs to load before the script is called
     * @return string
     */
    function getExternal(): string { return ''; }

    /**  getExternal and getScript is counterpart to getCode, but used in AJAX calls - external for libs to load before the script is called
     * @return string
     */
    function getScript()        { return ''; }


    /**
     * @return string
     */
    function getIntegrity()     { return ($this->integrity ? " integrity=\"{$this->integrity}\" crossorigin=anonymous" : ''); }

    /**
     * @return string
     */
    function getUrl()           { return ''; }

    /** Generate instance constructor as string - to be used for updates of js libraries in php code
     * @return string
     */
    function getConstructor() {
        $sri = $this->integrity ? ",'".$this->integrity."'" : '';
        return static::class.'(['.implode(',', array_map(static fn ($dep) => "\"$dep\"", $this->depend)). ']'. $sri;
    }
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

    /** @var $loadtype string "defer" | "async" | "module"    or "print" for css*/
    protected $loadtype = '';

    /**
     * AA_Req_Lib constructor.
     * @param $url - just url, or <url> defer, <url> async, <url> module (module for type=module)
     * @param string $integrity
     * @param array $depend
     */
    function __construct($url, $integrity='', $depend= []) {
        parent::__construct($depend, $integrity);
        if (strpos(trim($url),' ')) {
            $a = array_filter(array_map('trim',explode(' ',$url)));
            $this->url = array_shift($a);
            foreach ($a as $param) {
                if (str_starts_with($param, 'sha')) {  // found on position 0
                    $this->integrity = $param;
                } else {
                    $this->loadtype = $param;
                }
            }
        } else {
            $this->url      = $url;
            $this->loadtype = '';
        }
    }

    /**
     * @return string
     */
    function getCode(): string {
        if (!$this->url) {
            return '';
        }
        $defer = '';
        $type  = '';
        if ($this->loadtype) {
            if ($this->loadtype === 'module') {
                $type = ' type=module';
            } else {
                $defer = ' '.$this->loadtype;
            }
        }
        return "<script".$defer.$type ." src=\"{$this->url}\"" .$this->getIntegrity(). "></script>";
    }

    
    /**  getExternal and getScript is counterpart to getCode, but used in AJAX calls - external for libs to load before the script is called
     * @return string
     */
    function getExternal(): string {
        if (trim($this->url)) {
            return "AA_LoadJs('load_once',AA_IsLoaded,'{$this->url}',true);";  // we use script.async=false - codemirror in {ajax} did not load plugins withou it
        }
        return '';
    }

    /**
     * @return string
     */
    function getUrl() {
        return $this->url;
    }

    function parseUrl() {
        $ret = [];
        if (preg_match('`^(.*)(npm/)([a-zA-Z0-9._-]+)@([^/]+)/(.*)`', $this->url, $matches)) {
            $ret['base']    = $matches[1];
            $ret['path']    = $matches[2];
            $ret['package'] = $matches[3];
            $ret['version'] = $matches[4];
            $ret['major']   = (int)$matches[4];
            $ret['file']    = $matches[5];
        } elseif (preg_match('`^(.*)(gh/[a-zA-Z0-9._-]+/)([a-zA-Z0-9._-]+)@([^/]+)/(.*)`', $this->url, $matches)) {
            $ret['base']    = $matches[1];
            $ret['path']    = $matches[2];
            $ret['package'] = $matches[3];
            $ret['version'] = $matches[4];
            $ret['major']   = (int)$matches[4];
            $ret['file']    = $matches[5];
        }
        return $ret;
    }

    function tryUpdate($version) {
        $reqinfo = $this->parseUrl();
        $json    = json_decode(file_get_contents(JS_UPDATE_API_URL.$reqinfo['path'].$reqinfo['package'].'@'.$version), true);
        $files   = $json;
        $dirs = explode('/',$reqinfo['file']);
        foreach ($dirs as $dir) {
            $found = false;
            foreach ($files['files'] as $file) {
                if ($dir == $file['name']) {
                    $files = $file;
                    $found = $file;
                    break;
                }
            }
        }
        if ($found) {
            $this->url       = $reqinfo['base'].$reqinfo['path']. $reqinfo['package'].'@'. $version.'/'. $reqinfo['file'];
            $this->integrity = 'sha256-'. $found['hash'];
        }
    }

    /** Generate instance constructor as string - to be used for updates of js libraries in php code
     * @return string
     */
    function getConstructor() {
        $param[] = "'". $this->url. ($this->loadtype ? ' '.$this->loadtype : '') ."'";
        $param[] = $this->integrity ? "'".$this->integrity."'" : "''";
        if ($this->depend) {
            $param[] = "[" . implode(',', array_map(static fn ($dep) => "'$dep'", $this->depend)) . ']';
        }
        return static::class."(". implode(', ', $param). ")";
    }
}

/** {require:/css/myprintstyle.css print}
 * {require:/css/myprintstyle.css async}   - loaded asynchronously
 */
class AA_Req_Css extends AA_Req_Lib {
    /**
     *
     */
    const CODE_POSITION = 'HEAD-A';

    /**
     * @return string
     */
    function getCode(): string {
        $loadtype = (($this->loadtype === 'async') ? "media=print onload=\"this.media='all'\"" : ( $this->loadtype ? "media=\"{$this->loadtype}\"" : ''));  // onload - trick to load css asynchronously - https://stackoverflow.com/questions/32759272/how-to-load-css-asynchronously
        return "<link rel=stylesheet" .$this->getIntegrity(). " href=\"{$this->url}\" $loadtype >";
    }

    /**  getExternal and getScript is counterpart to getCode, but used in AJAX calls - external for libs to load before the script is called
     * @return string
     */
    function getExternal(): string {
        return ($this->loadtype==='print') ? '' : "AA_LoadCss('{$this->url}');";
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
    function __construct($funct, $depend= []) {
        $this->funct  = $funct;
        $this->depend = $depend;
    }

    /**
     * @return mixed|string
     */
    function getCode(): string {
        return call_user_func($this->funct);
    }

    /** Generate instance constructor as string - to be used for updates of js libraries in php code
     * @return string
     */
    function getConstructor() {
        $param[] = "'". $this->funct ."'";
        if ($this->depend) {
            $param[] = "array(" . implode(',', array_map(static fn ($dep) => "'$dep'", $this->depend)) . ')';
        }
        return static::class."(". implode(', ', $param). ")";
    }
}

/**
 * Class AA_Req_Htm
 */
class AA_Req_CSScode extends AA_Req {

    const CODE_POSITION = 'HEAD-B';

    protected $css;

    /**
     * AA_Req_Run constructor.
     * @param $script
     * @param array $depend
     */
    function __construct($css, $depend= []) {
        $this->css     = $css;
        $this->depend  = $depend;
    }

    /**
     * @return string
     */
    function getCode(): string {
        return strlen($this->css) ? "<style> {$this->css} </style>" : '';
    }

    /** @todo may be to implement getScript()? */
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
    function __construct($script, $depend= []) {
        $this->script  = $script;
        $this->depend  = $depend;
    }

    /**
     * @return string
     */
    function getCode(): string {
        return strlen($this->script) ? "<script> {$this->script} </script>" : '';
    }

    /**  getExternal and getScript is counterpart to getCode, but used in AJAX calls - external for libs to load before the script is called
     * @return string
     */
    function getScript() {
        return $this->script;
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
    function getCode(): string {
        return $this->script;
    }

    /** the code is not script. Also, we should not need it for ajax widgets not manager */
    function getScript() {}
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
    function getCode(): string {
        return "<script> document.addEventListener('DOMContentLoaded', function() { {$this->script} }); </script>";
    }

    /** getExternal and getScript is counterpart to getCode, but used in AJAX calls - external for libs to load before the script is called
     *  @return string
     */
    function getScript() {
        /** @todo there should be some delay/Promise to wait for all the lib scripts are loaded */
        return $this->script;
    }
}

/**
 * Class AA_Requires
 */
class AA_Requires {
    /**
     * @var array
     */
    protected $reqs  = [];
    /**
     * @var array
     */
    protected $usrdefs  = [];
    /**
     * @var null
     */
    private   $_outputs = null;

    /**
     * @param $require
     * @param string $type
     */
    function addRequire($require, $type = '') {
        if ($type === 'AA_Req_Run') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Run');
        } elseif ($type === 'AA_Req_Load') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Load');
        } elseif ($type === 'AA_Req_Headcode') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Headcode');
        } elseif ($type === 'AA_Req_Footcode') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Footcode');
        } elseif ($type === 'AA_Req_Footlib') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Footlib');
        } elseif ($type === 'AA_Req_Htm') {
            $hash = $this->addUsrRequire($require, 'AA_Req_Htm');
        } elseif ($type === 'AA_Req_CSScode') {
            $hash = $this->addUsrRequire($require, 'AA_Req_CSScode');
        } elseif ((aa_substr($require,-4) === '.css') OR aa_strpos($require,'.css?') OR aa_strpos($require,'.css ') OR aa_strpos($require,'/css?')) {  //  /css? for google fonts
            $hash = $this->addUsrRequire($require, 'AA_Req_Css');
        } elseif (aa_substr($require,-3) === '.js' OR aa_strpos($require,'.js?') OR (aa_strpos($require,'/')!==false)) {
            $hash = $this->addUsrRequire($require, 'AA_Req_Lib');
        } else {
            $hash = $require;   // must be system require
        }

        $this->_outputs    = null; // be sure it will be recomputed after adding
        $this->reqs[$hash] = 1;
    }

    /**
     * @param string $require
     * @param string $type
     * @return string
     */
    function addUsrRequire($require, $type) {
        $hash = get_hash($require);    // we expect it does not contain '|' character, which is used for alternatives in compute()
        $this->usrdefs = [$hash => new $type($require)] + $this->usrdefs;  // we want them in reverse order because of compute() function code
        return $hash;
    }

    /**
     * @param $position
     * @return AA_Req[]
     */
    protected function computeRequires($position) {
        if (is_null($this->_outputs)) { // precomputed?
            $this->_outputs = [];
            $already_in     = [];

            $requires_definitions = $this->usrdefs + self::systemDefinitions();

            // we have to deal with different versions of library. Is some code needs specific version of the lib (jslib@1) we have to use it in all requires 'jslib'
            $exact_versions = [];
            foreach ($this->reqs as $code_id => $foo) {
                if (str_contains($code_id, '@')) {
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
                    $this->_outputs[$req->getCodePosition()][] = $req;
                    // mark requires for this library
                    // the requires are ordered from particular script to global libraries in $requires_arr, so we can add depending libraries here without problem
                    $dep = $req->getDependencies();
                    foreach ($dep as $reqlib) {
                        if (str_contains($reqlib, '|')) {
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
        return $this->_outputs[$position] ? array_reverse($this->_outputs[$position]) : [];    // reverse - first should goes the libraries, then particular depending scripts
    }

    /**
     * @return string
     */
    function getHead() {
        $req_arr = array_merge($this->computeRequires('HEAD-A'),$this->computeRequires('HEAD-B'));
        return implode("\n", array_map( static fn ($req) => $req->getCode(), $req_arr));
    }

    /**
     * @return string
     */
    function getFoot() {
        $req_arr = array_merge($this->computeRequires('FOOT-A'),$this->computeRequires('FOOT-B'));
        return implode("\n", array_map( static fn ($req) => $req->getCode(), $req_arr));
    }

    /** getAsScript is counterparts to getHead and getFoot, but used in AJAX calls, where CSS and JS libs have to be loaded by script
     * @return string
     */
    function getAsScript() {
        $req_arr  = array_merge($this->computeRequires('HEAD-A'),$this->computeRequires('HEAD-B'),$this->computeRequires('FOOT-A'),$this->computeRequires('FOOT-B'));
        $external = array_filter(array_map( static fn ($req) => $req->getExternal(), $req_arr));
        $js_count = array_reduce($external, static function($count, $a) { $count += (str_starts_with(trim($a), 'AA_LoadJs(')) ? 1 : 0; return $count; },0);

        $scripts  = implode("\n", array_map( static fn ($req) => $req->getScript(), $req_arr));
        //huhl($req_arr, $external, $scripts);

        /** @todo could be moved to Promise.all approach */
        $scripts  = "\nAA_IsLoaded.callback = function() {\n$scripts\n}\nAA_IsLoaded.cnt=$js_count\n";
        return  $scripts. implode("\n",$external);
    }

    /**
     * @param $string
     * @return string
     */
    static function getUrl4Lib($string) {
        $def = self::systemDefinitions();
        if ($req = $def[$string]) {
            return $req->getUrl();
        }
        return '';
    }

    /**
     * @return AA_Req[]
     */
    static function systemDefinitions() {
        return [
            //     <CDN URL>, <SRI hash - see srihash.org>, <requires - all of them must be below the line which do require in this list> example: array('jquery@3')
            // all versions updated 2021-10-14 by AA - Optimize - Generate JS Library definition array. HM
            'codemirror@5'                => new AA_Req_Lib('', '', ['_codemirror_core@5','_codemirror_htmlmixed@5','_codemirror_xml@5','_codemirror_css@5','_codemirror_javascript@5','_codemirror_matchbrackets@5','_codemirror_matchtags@5','_codemirror_xml-fold@5','_codemirror_search@5','_codemirror_searchcursor@5','_css-codemirror@5','css-aa-system@1']),
            '_codemirror_htmlmixed@5'     => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/mode/htmlmixed/htmlmixed.js', 'sha256-km7gfgjhuaEXSSLiRlVpFdE5a4N0ESY9t/mxMg1rOIY='),
            '_codemirror_xml@5'           => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/mode/xml/xml.js', 'sha256-vgkykov7BQhdTmNiJQUj8FoTSSCR6deymTCal6MhLdM='),
            '_codemirror_css@5'           => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/mode/css/css.js', 'sha256-0r8zQjE9N5uJZTlfpum3Spc23CakDb4EGrJ9BU3Riss='),
            '_codemirror_javascript@5'    => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/mode/javascript/javascript.js', 'sha256-gl81JlOuvk5SHO45NPFA/vOkYypKLJDaw0QpQAORim0='),
            '_codemirror_matchbrackets@5' => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/addon/edit/matchbrackets.js', 'sha256-RoNSBt4o9mmbhuzIv6XJzAatiSCEZAvu7VU9KVM4m1w='),
            '_codemirror_matchtags@5'     => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/addon/edit/matchtags.js', 'sha256-oCAwj6P1/BzATEuHMQxLOWONXkQHh4FLz8JFcIH/+hQ='),
            '_codemirror_xml-fold@5'      => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/addon/fold/xml-fold.js', 'sha256-6qrza98BMjZqcPGsh7xpweWNL52WV7KmoCz6NUkA1qo='),
            '_codemirror_search@5'        => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/addon/search/search.js', 'sha256-/lgo+zWOIJB1iutVqlqWhvbMfKYNSa5VkUxL92/EOpM='),
            '_codemirror_searchcursor@5'  => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/addon/search/searchcursor.js', 'sha256-JnDe2asI8M4h2oJpD81hT3Y9WoM3xMkFy9TwKKp/AFo='),
            '_codemirror_core@5'          => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/lib/codemirror.js', 'sha256-2d2VvG2rLaHwEzKLKZpLbkWESQAiguRjtK9hPYCuXRk=', ['css-aa-system@1','_codemirror_htmlmixed@5','_codemirror_xml@5','_codemirror_css@5','_codemirror_javascript@5','_codemirror_matchbrackets@5','_codemirror_matchtags@5','_codemirror_xml-fold@5','_codemirror_search@5','_codemirror_searchcursor@5','_css-codemirror@5']),
            'ace@1'                       => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/ace-builds@1.4.13/src-noconflict/ace.js', 'sha256-7RCmobi0Q1Znn12ri7WluU11BgfqKFo+gzLC8xoG/4Q=', ['jquery@3']),
            'tableexport@5'               => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/tableexport@5.2.0/dist/js/tableexport.min.js', 'sha256-2mlJMabqiyPb1w0ZdzOuuyOWeHkngxrYTowNETowwtI=', ['_css-tableexport@5','filesaver@1','js-xlsx@0']),
            'tableexport@4'               => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/tableexport@4.0.11/dist/js/tableexport.min.js', 'sha256-3njWp77R/1aA2AeJg4mxgOHejH9S5YodN/b28OzA35c=', ['_css-tableexport@4','filesaver@1','js-xlsx@0']),
            'js-xlsx@0'                   => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/xlsx@0.17.3/dist/xlsx.core.min.js', 'sha256-em3RDuTKTX0nyubrZvtj25roaSbGmOt083ZbuxlVRdU='),
            'filesaver@1'                 => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/file-saver@1.3.8/FileSaver.min.js', 'sha256-FPJJt8nA+xL4RU6/gsriA8p8xAeLGatoyTjldvQKGdE='),
            'leaflet-draw@1'              => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/leaflet-draw@1.0.4/dist/leaflet.draw.js', 'sha256-siofc4Uwjlra3YWkwthOn8Uj69cNN4aMug/iOHNiRgs=', ['_css-leaflet-draw@1']),
            'leaflet@1'                   => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js', 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=', ['_css-leaflet@1']),
            'chartjs@4'                   => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/chart.js@4.4.5/dist/chart.umd.js', 'sha256-PO6GSOht0cfQd0geBq94qbrE4Ic+JQjOY4aOPVy0Bvc='),
            'chartjs@2'                   => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js', 'sha256-t9UJPrESBeG2ojKTIcFLPGF7nHi2vEc7f5A2KpH/UBU='),
            'dropzone@5'                  => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/dropzone@5.9.3/dist/min/dropzone.min.js', 'sha256-uCdT6FLkY6+68Xzm4f9O0usHnJPdKv0Q0rsoXvLchP4=', ['_css-dropzone@5']),
            'select2@4'                   => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js', 'sha256-gtZlnMWqbrBdDWvmCQCgfiA3kq8J4FMqQ4a8Tvmgopk=', ['jquery@3','_css-select2@4']),
            'select2@3'                   => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/Select2@3.5.7/select2.min.js', 'sha256-xd6tWPqy/mxhxXcvyda/fncm94RXOR6918/lDx6Ckb8=', ['jquery@3','_css-select2@3']),
            'eucookies@1'                 => new AA_Req_Footlib('https://cdn.jsdelivr.net/gh/honzito/smart-eu-cookies@0.9.2/src/smart-eu-cookies.min.js async', 'sha384-zoVmFcp060tXD33v8RW5gAyVr8JV6p42dOucvqkNWbXZuJvwQAGhu1Ld9/dSbFXk'),
            'sortable@1'                  => new AA_Req_Footlib('https://cdn.jsdelivr.net/gh/honzito/plain.sortable@1.3.0/sortable.min.js', 'sha384-Ykq5gOjnWMRB6SAvIl0EnfHZLxYXGM0NIVT6GKOfpIidUn1nhK0EIAHxDqhIAoTF', ['_css-sortable@1']),  // no async - script needs to be loaded to run Sortable.find()... // see orderable for sortablejs
            'orderable@1'                 => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js', 'sha256-bQqDH8GbS66FF5etM5MVfoYa+3hiRZwRImNZsn4sQzc='),  // another sortable for drag&drop ordering
            'datatable@1'                 => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/datatables.net@1.11.3/js/jquery.dataTables.min.js', 'sha256-gOJ/T3VMQ0Brwz7VYrLoVAQ+NE0a5vHoj1vXI1HUIzY=', ['jquery@3']),
            'form-saver@1'                => new AA_Req_Footlib(get_aa_url('javascript/form-saver/form-saver.min.js', ['ver'=>AA_JS_VERSION],false), '', ['deserialize@2']),
            'deserialize@2'               => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/jquery-deserialize@2.0.0/src/jquery.deserialize.js', 'sha256-iQpPGsHQZs7cuArBkNIa6QOJlnnf8A1RBfMLxp/MLVI=', ['jquery@3']),
            //'lightgallery@1'              => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/lightgallery.js@1.2.0/lib/js/lightgallery.js', 'sha256-LwSUsCCIlbq0+vHbSFFbyO+BeMLnJ8gQecxLfgieuKM=', array('_css-lightgallery@1')),
            'lightgallery@2'              => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/lightgallery@2.2.1/lightgallery.min.js', 'sha256-Dawo6shovc6mR+OrpnCxjnHYYp0655VrB0LShTAPM34=', ['_css-lightgallery@2']),  // async do not work for foot lib - it is used before it is loaded
            'lightgallery@1'              => new AA_Req_Footlib('https://cdn.jsdelivr.net/lightgallery.js/1.0.1/js/lightgallery.min.js', 'sha384-HZ/iOnkYJdO5/U2ug7IPTgNZq61Jr+SMoJGCWsjVjNqwY/77YVNA/mZQ1tdfo2Xm', ['_css-lightgallery@1']), // v 1.1.1 do not work
            'tippy@6'                     => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/tippy.js@6.3.7/dist/tippy-bundle.umd.min.js', 'sha256-Pw/nDrJszyj2iHoZLinTjdfvfC8HmnMwStQt3HvtN94=', ['popper@2']),
            'tippy@2'                     => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/tippy.js@2.6.0/dist/tippy.all.min.js', 'sha256-H8z9OcYtNQgDyDRKu2H1XY/nq06+bY3cMyB3fSFEytk='),
            'popper@2'                    => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.5/dist/umd/popper.min.js', 'sha256-2neWyvk1kBWvTs34xsy9U3BupGE5Mqm2+B5ELknV9iY='),
            'aa-tools@1'                  => new AA_Req_Lib('', '', ['css-aa-system@1','aa-jslib@3|aa-jslib@1','htm-aa-system@1']),
            'ckeditor@4'                  => new AA_Req_Footlib(get_aa_url('misc/htmleditor/ckeditor.js', '', false),'', ['css-aa-system@1','aa-jslib@3|aa-jslib@2|aa-jslib@1']),
            'aa-fillform@1'               => new AA_Req_Lib(get_aa_url('javascript/fillform.min.js', ['v'=>AA_JS_VERSION],false)),
            'aa-constedit@1'              => new AA_Req_Lib(get_aa_url('javascript/constedit.min.js', ['v'=>AA_JS_VERSION],false)),
            'aa-jslib@3'                  => new AA_Req_Lib(get_aa_url('javascript/aajslib-jquery.min.js', ['v'=>AA_JS_VERSION],false), '', ['jquery@3']),
            'aa-jslib@2'                  => new AA_Req_Lib(get_aa_url('javascript/aajslib-jquery.php', ['v'=>AA_JS_VERSION],false), '', ['jquery@3']),
            'aa-jslib@1'                  => new AA_Req_Lib(get_aa_url('javascript/aajslib-legacy.min.js', ['v'=>AA_JS_VERSION],false)),
            'jquery-ui@1'                 => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.12.1/jquery-ui.min.js', 'sha256-KM512VNnjElC30ehFwehXjx1YCHPiQkOPmqnrWtpccM=', ['jquery@3','_css-jquery-ui@1']),
            'jquery@3'                    => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js', 'sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4='),
            'vue@2'                       => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.min.js', 'sha256-kXTEJcRFN330VirZFl6gj9+UM6gIKW195fYZeR3xDhc='),
            'vue-test@2'                  => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/vue@2.6.14/dist/vue.js', 'sha256-abdUg7JwQh4aiUJt1ZOHugkHcjE1YcPp+kFTlqeOiTY='),
            '_css-jquery-ui@1'            => new AA_Req_Css('https://cdn.jsdelivr.net/npm/jquery-ui-dist@1.12.1/jquery-ui.min.css', 'sha256-rByPlHULObEjJ6XQxW/flG2r+22R5dKiAoef+aXWfik='),
            '_css-tableexport@5'          => new AA_Req_Css('https://cdn.jsdelivr.net/npm/tableexport@5.2.0/dist/css/tableexport.min.css', 'sha256-ljRB06PkvKJLniqOfPxxNfnIGgtF3gSWnLqYuFhWoDY='),
            '_css-tableexport@4'          => new AA_Req_Css('https://cdn.jsdelivr.net/npm/tableexport@4.0.11/dist/css/tableexport.min.css', 'sha256-nopPKGGZlhhMl96BF6etnX8zuKX2NPJbPJqZKXLtLMk='),
            '_css-dropzone@5'             => new AA_Req_Css('https://cdn.jsdelivr.net/npm/dropzone@5.9.3/dist/min/dropzone.min.css', 'sha256-n/Cuyrm+v15Nim0mJ2ZrElHlCk8raJs/57WeCsIzDr4='),
            '_css-select2@4'              => new AA_Req_Css('https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', 'sha256-zaSoHBhwFdle0scfGEFUCwggPN7F+ip9XRglo8IWb4w='),
            '_css-select2@3'              => new AA_Req_Css('https://cdn.jsdelivr.net/npm/Select2@3.5.7/select2.css', 'sha256-YxoY/Ov8dU2zQTW3WNFp25v+U1SBgVrOkiB8w3rupb4='),
            '_css-lightgallery@2'         => new AA_Req_Css('https://cdn.jsdelivr.net/npm/lightgallery@2.2.1/css/lightgallery-bundle.min.css async', 'sha256-Uxm/PH2he1eJjDjL9GpZSqxO3+ibyFsbhGupVTc9qLg='),
            '_css-lightgallery@1'         => new AA_Req_Css('https://cdn.jsdelivr.net/npm/lightgallery.js@1.2.0/dist/css/lightgallery.min.css', 'sha256-gU66VAEd73/e6tBq5c+WSiRcNH0PSXLnHMPeFIKxtHM='),
            '_css-leaflet@1'              => new AA_Req_Css('https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css', 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY='),
            '_css-leaflet-draw@1'         => new AA_Req_Css('https://cdn.jsdelivr.net/npm/leaflet-draw@1.0.4/dist/leaflet.draw.css', 'sha256-XzD3RpaHPv7lzX9qt+2n1j5cWj48O24KsgaGYpKN8x8='),
            '_css-sortable@1'             => new AA_Req_Css('https://cdn.jsdelivr.net/gh/honzito/plain.sortable@1.2.1/sortable.css', 'sha256-TNKOtdeH1MmRdsujDukp9lYyndNf5qPvBHbWfKWGoX8='),
            'css-aa-system@1'             => new AA_Req_Css(get_aa_url('css/aa-system.css', ['v'=>AA_JS_VERSION],false)),
            'css-normalize@7'             => new AA_Req_Css('https://cdn.jsdelivr.net/npm/normalize.css@7.0.0/normalize.css', 'sha384-Y+IEavQyTjgXgt7TL5nxVHI/DEuF6yoysy50puRyQDKmjfOZjY7+QvoRelSV4XvZ'),
            'css-normalize@8'             => new AA_Req_Css('https://cdn.jsdelivr.net/npm/normalize.css@8.0.1/normalize.css', 'sha256-WAgYcAck1C1/zEl5sBl5cfyhxtLgKGdpI3oKyJffVRI='),
            'css-fontawesome@4'           => new AA_Req_Css('https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css', 'sha256-eZrrJcwDc/3uDhsdt61sL2oOBY362qM3lon1gyExkL0='),
            '_css-codemirror@5'           => new AA_Req_Css('https://cdn.jsdelivr.net/npm/codemirror@5.63.3/lib/codemirror.css', 'sha256-utW7LD90DZW8tsURpPq7cabUGkha4Bf++sK4e7NC0+8='),
            'htm-aa-system@1'             => new AA_Req_Htm('AA_Tools::getBottomCode'),

            //'select2@4'                => new AA_Req_Footlib('https://cdn.jsdelivr.net/npm/select2@4.0.5/dist/js/select2.min.js', 'sha256-FA14tBI8v+/1BtcH9XtJpcNbComBEpdawUZA6BPXRVw=', array('jquery@3','_css-select2@4')),
            //'lightgallery@1'           => new AA_Req_Footlib('https://cdn.jsdelivr.net/lightgallery.js/1.0.1/js/lightgallery.min.js', 'sha384-HZ/iOnkYJdO5/U2ug7IPTgNZq61Jr+SMoJGCWsjVjNqwY/77YVNA/mZQ1tdfo2Xm', array('_css-lightgallery@1')), // v 1.1.1 do not work
            //'jspdf@1'                  => new AA_Req_Lib('https://cdn.jsdelivr.net/npm/jspdf@1.5.3/dist/jspdf.min.js', 'sha256-gJWdmuCRBovJMD9D/TVdo4TIK8u5Sti11764sZT1DhI=', array('jquery@3')),
        ];
    }
    static function getAvailableLibs() {
        $ret['head'] = ['require',_m('version')];
        $defs = self::systemDefinitions();
        foreach ($defs as $name => $def) {
            if ($name[0] !== '_') {
                $base = strtok($name, '@');
                if (!$ret[$base]) {
                    $ret[$base] = [$base, ''];
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
            $ret .= AA::Un($site->getProperty('loginpage_code'));
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

    /** @var bool switches caching ON and OFF for cacheable stringexpands - default is true - Cache  */
    protected static $doCache = true;

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

    public static function switchCacheOff() {
        self::$doCache = false;
    }

    /** @deprecated - use AA::Un() directly
     * @param          $text
     * @param string   $remove
     * @param null     $item
     * @param bool     $dequote
     * @param itemview $itemview
     * @return mixed|null|string|string[]
     */
    function unalias($text, $remove='', $item=null, $dequote=true, $itemview=null ) {
        return AA::Un($text, $remove, $item, $dequote, $itemview);
    }

    /** main function for stringexpanding - overriden in subclasses
     *  @param string $params - variable for each stringexpand function
     *  @return string
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
            return [];
        }
        return array_map(static::doTrimParams() ? 'DeQuoteColonsTrim' : 'DeQuoteColons', ParamExplode($params));
    }

    /** additionalCacheParam function
     *  Some stringexpand functions uses global parameters, so it is not possible
     *  to use cache for results based just on expand() parameters. We need to
     *  add following parameters. In most cases you do not need to override this
     *  function
     *  @param array $params parameters passed to expand (caching could be parameter sensitive).
     *  @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        return '';
    }

    /**
     * @return bool
     */
    function doCache() {
        return self::$doCache;     // true in most cases, but could be switched OFF (by ?nocache=1 parameter);
    }

    /** Marks rare cases, when we do not want to qoute results - like for
     *  {_:...} shortcuts
     */
    function doQuoteColons() {
        return true;
    }

    /** Trim all parameters? */
    static function doTrimParams() {
        return true;
    }

    /** replace parameters _#P1, _#P2, ... by the supplied ones */
    static function replaceParams($text, $arg_list)
    {
        if (count($arg_list) > 0) {
            $trans = [];
            foreach ($arg_list as $key => $param) {
                // param is dequoted, but we need escape colons, here - the result is passed back to AA_Stringexpand
                $trans['_#P'.($key + 1)] = QuoteColons($param);
            }
            $text = strtr($text, $trans);
        }
        return $text;
    }

    /** replace icons
     * @param array $texts
     * @param array $icons - ['+', '-', '*'] - default to ['+', '-']
     * @return array replaced texts
     */
    protected static function replaceIcons(array $texts, array $icons=['+', '-']): array {
        $search = [];
        $replace = [];
        if (in_array('+', $icons)) { $search[]  = '[+]'; $replace[] = '<span class="aa-img-plus">'.  GetAAImage('plus.gif',  _m('show'), 15, 9) .'</span>';   }
        if (in_array('-', $icons)) { $search[]  = '[-]'; $replace[] = '<span class="aa-img-minus">'.  GetAAImage('minus.gif',  _m('hide'), 15, 9) .'</span>'; }
        if (in_array('*', $icons)) { $search[]  = '[*]'; $replace[] = '<span class="aa-defaultloader">'.  GetAAImage('loader.gif',  _m('loading'), 16, 16) .'</span>'; }

        return str_replace($search, $replace, $texts);
    }

    /** get all related slices (provided or counted from current item, site od slice)
     * @param string $slices_str  '' | 'all' | '<slice_id>-<slice_id>-...'
     * @return string[]
     */
    protected function getRelatedModules($slices_str=''): array {
        $slices = [];
        if (!strlen($slices_str) OR ($slices_str==='all')) {
            if (is_object($this->item)) {
                $slices = explode_ids(AA::Un("{unique:{slice_id........}-{modulefield:{slice_id........}:site_ids}-{site:{modulefield:{slice_id........}:site_ids}:modules}}", '', $this->item));
            } elseif (AA::$site_id) {
                $slices = AA_Module_Site::getRelatedModules([AA::$site_id], true);
            } elseif (AA::$slice_id) {
                $slices = explode_ids(AA::Un("{unique:".AA::$slice_id."-{site:{modulefield:".AA::$slice_id.":site_ids}:modules}}"));
            }
        } else {
            $zids = new zids(explode_ids($slices_str), 'l');
            $slices = $zids->longids();
        }
        return $slices;
    }

    /** get all related slices (provided or counted from current item, site od slice)
     * @param string[] $arr
     * @param string   $delimiter
     * @return string
     */
    public static function joinWithDelimiter($arr, $delimiter): string
    {
        return empty($arr) ? '' : (($delimiter==='json') ? json_encode($arr) : implode($delimiter, $arr));
    }

    /**
     * @param string $values
     * @param string $delimiter
     * @return array
     */
    public static function explode2array(string $values, string $delimiter=''): array {
        if (empty($delimiter)) {
            if (str_starts_with($values, '[') and str_ends_with($values, ']')) {
                $ret = json2arr($values);
            } else {
                $ret = explode('-', $values);
            }
        } else {
            $ret = explode($delimiter, $values);
        }
        return $ret;
    }

    /**
     * @param string $numebr
     * @return float
     */
    public static function clearFloat($number): float {
        return (float)str_replace([' ','&#8239;',','], ['', '','.'], trim($number));
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

/** Special parent class for all stringexpand functions, where no cache
 *  is needed and no Trim - mainly for PHP based functions - formerly
 *      public static $php_functions = [ 'strlen'           => 'strlen', ...
 */
class AA_Stringexpand_Nevercachenotrim extends AA_Stringexpand {
    /** @return bool */
    function doCache() {  return false;  }

    /** Do not trim all parameters (maybe we can?) */
    static function doTrimParams() { return false; }
}



/** unaliases the text - replaces {views} and other constructs */
class AA_Stringexpand_Expand extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    static function doTrimParams() { return false; }

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
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
        $item   = $this?->item;
        return AA::Un($string,'',$item);
    }
}


/** trims whitespaces form begin and end of the string */
class AA_Stringexpand_Trim extends AA_Stringexpand_Nevercachenotrim {

    /**
     * @param string $string
     * @param string $chars
     * @return string|void
     */
    function expand($string='', $chars='') {
        return aa_trim($string, $chars);
    }
}

/** trims whitespaces form begin of the string */
class AA_Stringexpand_Ltrim extends AA_Stringexpand_Nevercachenotrim {

    /**
     * @param string $string
     * @param string $chars
     * @return string|void
     */
    function expand($string='', $chars='') {
        return ltrim($string, $chars);
    }
}

/** trims whitespaces form end of the string */
class AA_Stringexpand_Rtrim extends AA_Stringexpand_Nevercachenotrim {

    /**
     * @param string $string
     * @param string $chars
     * @return string|void
     */
    function expand($string='', $chars='') {
        return rtrim($string, $chars);
    }
}

/** replaces string or strings - you can use single string replacement
 *  or array in JSON form:
 *   {str_replace:uno:one:text with uno inside}
 *   {str_replace:["č","š","ř"]:["c","s","r"]:text česky with accents}
 */
class AA_Stringexpand_Str_replace extends AA_Stringexpand_Nevercachenotrim {
    /**
     * @param string $search
     * @param string $replace
     * @param string $text
     * @return mixed|void
     */
    function expand($search='', $replace='', $text='') {
        $search  = json2arr($search,true);
        $replace =  ($replace[0] === '[') ? json2arr($replace,true) : $replace; // the replacement could be string (which then replaces all the occurrences of $searches)
        return str_replace($search, $replace, $text);
    }
}


/** replaces string with REGEXP
 *      {replace:<!-- .* -->::{full_text.......}}
 *   The usage is as PHP preg_replace, but we do not allow you to specify
 *   delimiters and modifiers because of dangerous /e modifier
 */
class AA_Stringexpand_Replace extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($search and $replace could be spaces) */
    static function doTrimParams() { return false; }

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

/** AA counterpart of PHP strlen() */
class AA_Stringexpand_Strlen extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return (string)strlen($string);
    }
}

/** AA counterpart of PHP strtoupper() */
class AA_Stringexpand_Strtoupper extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return aa_strtoupper($string);
    }
}

/** AA counterpart of PHP strtolower() */
class AA_Stringexpand_Strtolower extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return aa_strtolower($string);
    }
}

/** AA counterpart of PHP lcfirst() */
class AA_Stringexpand_Lcfirst extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return aa_lcfirst($string);
    }
}

/** AA counterpart of PHP ucfirst() */
class AA_Stringexpand_Ucfirst extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return aa_ucfirst($string);
    }
}

/** AA counterpart of PHP ucwords() */
class AA_Stringexpand_Ucwords extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return ucwords($string);
    }
}

/** AA counterpart of PHP urlencode()
 * @deprecated - use RFC 3986 compliant {rawurlencode}
 */
class AA_Stringexpand_Urlencode extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return urlencode($string);
    }
}

/** AA counterpart of PHP rawurlencode()
 */
class AA_Stringexpand_Rawurlencode extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return rawurlencode($string);
    }
}

/** AA counterpart of PHP urldecode()
 * @see AA_Stringexpand_Rawurldecode (RFC compliant version)  */
class AA_Stringexpand_Urldecode extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return urldecode($string);
    }
}

/** AA counterpart of PHP rawurldecode() */
class AA_Stringexpand_Rawurldecode extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return rawurldecode($string);
    }
}

/** AA counterpart of PHP ord() */
class AA_Stringexpand_Ord extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return ord($string);
    }
}

/** AA counterpart of AA unpack_id() */
class AA_Stringexpand_Unpack extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return unpack_id($string);
    }
}

/** AA counterpart of AA string2id() */
class AA_Stringexpand_String2id extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return string2id($string);
    }
}

/** AA counterpart of PHP base64_encode() */
class AA_Stringexpand_Base64 extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='') {
        return base64_encode($string);
    }
}

/** AA counterpart of PHP str_repeat() */
class AA_Stringexpand_Str_repeat extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='', $times='') {
        return str_repeat($string, (int)$times);
    }
}

/** AA counterpart of PHP rand() */
class AA_Stringexpand_Rand extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($min='', $max='') {
        return random_int((int)$min, (int)$max);
    }
}

/** AA counterpart of PHP fmod() */
class AA_Stringexpand_Fmod extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($num1='', $num2='') {
        return fmod($num1, $num2);
    }
}

/** AA counterpart of PHP log() */
class AA_Stringexpand_Log extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($num='', $base='') {
        return log($num, $base);
    }
}

/** AA counterpart of PHP strip_tags() */
class AA_Stringexpand_striptags extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string  */
    function expand($string='', $allowed_tags='') {
        return strip_tags($string, $allowed_tags);
    }
}

/** AA counterpart of PHP gethostbyaddr()
 *  {gethostbyaddr:{server:REMOTE_ADDR}}
 */
class AA_Stringexpand_Gethostbyaddr extends AA_Stringexpand {

    /** @param string $ip  */
    function expand($ip='') {
        return !strlen($ip) ? '' : ((($ret = gethostbyaddr($ip)) === false) ? '' : $ret);
    }
}

/** Prints version of AA as full-string, AA version (2.11.0), or svn revision (2368)
 *  {version[:aa|svn|svn2]}
 **/
class AA_Stringexpand_Version extends AA_Stringexpand_Nevercachenotrim {

    /** @param string $string full|svn|svn2|aa */
    function expand($type='') {
        return aa_version($type);
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
        return (count($arr = self::_get_array(func_get_args())) < 2) ? $arr[0] : max(...$arr);
    }

    /**
     * @param $func
     * @param $arg_list
     */
    static function _get_array($arg_list): array {
        switch (count($arg_list)) {
            case 0:  return [];
            case 1:  $arr = json2arr($arg_list[0]); break;
            default: $arr = $arg_list;
        }
        return str_replace(',','.',$arr);
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
        return (count($arr = self::_get_array(func_get_args())) < 2) ? $arr[0] : min(...$arr);
    }
}

/** average value
 *  Accepts two forms of parameters:
 *     {avg:12:45:8}
 *     {avg:[12,45,8]} - JSON form
 */
class AA_Stringexpand_Avg extends AA_Stringexpand_Max {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /**
     * @return mixed|string|void
     */
    function expand() {
        return count($arr = self::_get_array(func_get_args())) ? array_sum($arr)/count($arr) : '';
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
        return $unpacked_id ? (((string)$unpacked_id == "0" ? "0" : pack("H*", $unpacked_id))) : '';
    }
}

/** Displays hit statistics for the items */
class AA_Stringexpand_Hitcounter extends AA_Stringexpand {

    /** expand function
     *
     * @param $type string type of statistics - currently only "days" statistics is implemented
     * @param $ids  string item ids (long or short) for which you want to display statistics
     * @param $from string timestamp from which you want to display statistics
     *
     * Example: {hitcounter:days:24457-24474}
     * Example: {hitcounter:days:{ids:76f59b2023b8a4e8d6c57831ef8c8199:d-publish_date....->-1185919200}}
     * Example: {hitcounter:days:{ids:76f59b2023b8a4e8d6c57831ef8c8199:d-publish_date....->-1185919200}:1729634400}
     *
     * @return string
     */
    function expand($type='', $ids='', $from='') {
        $ret = '';
        switch ($type) {
            case 'minutes':   $group_string = 'Y-m-d H:i';  break;
            case 'hours':     $group_string = 'Y-m-d H:00'; break;
            case 'days':      $group_string = 'Y-m-d';      break;
            case 'dayhours':  $group_string = 'H';          break;
            case 'weeks':     $group_string = 'o-W';        break;    // o - like Y but respects week numbering
            case 'weekdays':  $group_string = 'N-D';        break;
            case 'months':    $group_string = 'Y-m';        break;
            case 'years':     $group_string = 'Y';          break;
            default:          return '';
        }
        $zids   = new zids(explode_ids($ids));
        $s_zids = new zids($zids->shortids(), 's');

        $reports = ['HITS', 'DURATION', 'SUM'];
        $stat = $this->countStats($s_zids, $group_string, $reports, (int)$from);  // array of statistics [hits, dur_0, count_0, dur_1, count_1, dur_2, count_2, dur_3, count_3]

        if (count($stat) > 0) {
            $s_ids =  $s_zids->shortids();
            if (in_array('SUM', $reports, true)) {
                $s_ids[] = 'all';
            }
            // table header
            $ret   = "<table>\n  <tr>\n    <th rowspan=3>"._m('Date \ Item ID')."</th>";
            foreach ($s_ids as $sid) {
                $ret .= "\n". AA::Un("<th title=\"{safe:{item:$sid:_#HEADLINE}}\" colspan=9>$sid<br><small>{shorten:{item:$sid:_#HEADLINE}:10:3}</small></th>");
            }
            $ret   .= "\n  </tr>";
            $ret .= '<tr>'.str_repeat("<th rowspan=2>Hits</th><th colspan=2>NC+M</th><th colspan=2>NC+NM</th><th colspan=2>C+M</th><th colspan=2>C+NM</th>", count($s_ids));
            $ret   .= "\n  </tr>";
            $ret .= '<tr>'.str_repeat("<th>[ms]</th><th>#</th>", 4*count($s_ids));
            // 0 - NOT cached + modified,
            // 1 - NOT cached + not modified,
            // 2 - cached + modified,
            // 3 - cached + not modified
            $ret   .= "\n  </tr>";

            $timex = new LogarithmIndex(.002,80,63);
            ksort($stat);
            $cols = [];
            $labels = [];
            foreach ( $stat as $day => $counts ) {
                $ret .= "\n  <tr>\n    <td>$day</td>";
                $labels[] = $day;
                foreach ($s_ids as $sid) {
                    //$cols[$sid] = ['H'=>[], 'ncmH'=>[], 'ncmD'=>[], 'ncnmH'=>[], 'ncnmD'=>[], 'cmH'=>[], 'cmD'=>[], 'cnmH'=>[], 'cnmD'=>[]];

                    $cnt = ($counts[$sid][0] ?? '0');
                    $ret .= "\n    <td>$cnt</td>";
                    $cols[$sid]['H'][] = $cnt;   // hits

                    $cnt = ($counts[$sid][2] ?? '0');
                    $dur = $cnt ? StrExp('math', [1000 * $timex->index2float($counts[$sid][1] ?? '0'),1,'EN'])  : 0;
                    $cols[$sid]['ncmH'][] = $cnt;
                    $cols[$sid]['ncmD'][] = $dur;
                    $ret .= "\n    <td>$dur</td>";
                    $ret .= "\n    <td>$cnt</td>";

                    $cnt = ($counts[$sid][4] ?? '0');
                    $dur = $cnt ? StrExp('math', [1000 * $timex->index2float($counts[$sid][3] ?? '0'),1,'EN'])  : 0;
                    $cols[$sid]['ncnmH'][] = $cnt;
                    $cols[$sid]['ncnmD'][] = $dur;
                    $ret .= "\n    <td>$dur</td>";
                    $ret .= "\n    <td>$cnt</td>";

                    $cnt = ($counts[$sid][6] ?? '0');
                    $dur = $cnt ? StrExp('math', [1000 * $timex->index2float($counts[$sid][5] ?? '0'),1,'EN'])  : 0;
                    $cols[$sid]['cmH'][] = $cnt;
                    $cols[$sid]['cmD'][] = $dur;
                    $ret .= "\n    <td>$dur</td>";
                    $ret .= "\n    <td>$cnt</td>";

                    $cnt = ($counts[$sid][8] ?? '0');
                    $dur = $cnt ? StrExp('math', [1000 * $timex->index2float($counts[$sid][7] ?? '0'),1,'EN'])  : 0;
                    $cols[$sid]['cnmH'][] = $cnt;
                    $cols[$sid]['cnmD'][] = $dur;
                    $ret .= "\n    <td>$dur</td>";
                    $ret .= "\n    <td>$cnt</td>";
                }
                $ret .= "\n  </tr>";
            }
            $ret .= "\n</table>";

            $ret .= "<h3>Hits</h3>";

            $datasets = [];
            $i = 0;
            foreach ($cols as $sid => $sidcols ) {
                $color = $this->generateSaturatedColor($i++);
                $color1 = $sid === 'all' ? $this->hslToHex($color, 0, 10) : $this->hslToHex($color, 100, 50);
                $color2 = $sid === 'all' ? $this->hslToHex($color, 0, 50) : $this->hslToHex($color, 100, 80);
                if (array_filter($sidcols['H'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid Hits", 'data' => $sidcols['H'],      'color' => $color1, 'dash' => [], 'width' => 1];
                }
                if (array_filter($sidcols['ncmH'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid NC+M", 'data' => $sidcols['ncmH'],   'color' => $color1, 'dash' => [7, 3]];
                }
                if (array_filter($sidcols['ncnmH'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid NC+NM", 'data' => $sidcols['ncnmH'], 'color' => $color1, 'dash' => [2, 2]];
                }
                if (array_filter($sidcols['cmH'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid C+M", 'data' => $sidcols['cmH'],     'color' => $color2, 'dash' => [7,3]];
                }
                if (array_filter($sidcols['cnmH'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid C+NM", 'data' => $sidcols['cnmH'],   'color' => $color2, 'dash' => [2,2]];
                }
            }
            $ret .= $this->getGraph(1200, 800, $datasets, $labels);


            $ret .= "<h3>All items stat</h3>";
            $datasets = [];

            $sid = 'all';
            $sidcols = $cols[$sid];

            foreach (['ncmH', 'ncnmH', 'cmH', 'cnmH'] as $t) {
                if (isset($sidcols[$t], $sidcols['H']) && count($sidcols[$t]) === count($sidcols['H'])) {
                    array_walk($sidcols[$t], fn(&$v, $index) => $v = ($sidcols['H'][$index] ?? 0) != 0 ? $v*100 / $sidcols['H'][$index] : 0);
                }
            }

                if (array_filter($sidcols['ncmH'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid NC+M", 'data' => $sidcols['ncmH'], 'color' => '#ff0000', 'dash' => [7, 3]];
                }
                if (array_filter($sidcols['ncnmH'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid NC+NM", 'data' => $sidcols['ncnmH'], 'color' => '#ff6666', 'dash' => [2, 2]];
                }
                if (array_filter($sidcols['cmH'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid C+M", 'data' => $sidcols['cmH'], 'color' => '#66FF66', 'dash' => [7,3]];
                }
                if (array_filter($sidcols['cnmH'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid C+NM", 'data' => $sidcols['cnmH'], 'color' => '#00FF00', 'dash' => [2,2]];
                }
            $ret .= $this->getGraph(1200, 800, $datasets, $labels, 'stacked');


            $ret .= "<h3>Average Duration</h3>";
            $datasets = [];
            $i = 0;
            foreach ($cols as $sid => $sidcols ) {
                $color = $this->generateSaturatedColor($i++);
                $color1 = $sid === 'all' ? $this->hslToHex($color, 0, 10) : $this->hslToHex($color, 100, 50);
                $color2 = $sid === 'all' ? $this->hslToHex($color, 0, 50) : $this->hslToHex($color, 100, 80);

                if (array_filter($sidcols['ncmD'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid NC+M", 'data' => $sidcols['ncmD'], 'color' => $color1, 'dash' => [7, 3]];
                }
                if (array_filter($sidcols['ncnmD'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid NC+NM", 'data' => $sidcols['ncnmD'], 'color' => $color1, 'dash' => [2, 2]];
                }
                if (array_filter($sidcols['cmD'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid C+M", 'data' => $sidcols['cmD'], 'color' => $color2, 'dash' => [7,3]];
                }
                if (array_filter($sidcols['cnmD'], static fn($v) => $v !== 0)) {
                    $datasets[] = ['label' => "$sid C+NM", 'data' => $sidcols['cnmD'], 'color' => $color2, 'dash' => [2, 2]];
                }
            }

            $ret .= $this->getGraph(1200, 800, $datasets, $labels);
            AA::Stringexpander()->addRequire('chartjs@4');
        }

        return $ret;
    }

    /**
     * @param  zids    $s_zids  short zids
     * @param  string  $group_string
     * @param  array   $columns         HITS, DURATION
     *
     * @return array statistics
     */
    public function countStats(zids $s_zids, string $group_string, array $columns, int $from=0): array
    {
        $duration_helper = new AddDurationLog();
        $do_duration     = in_array('DURATION', $columns, true) && $duration_helper->test();
        $do_sum          = $do_duration && in_array('SUM', $columns, true);
        $do_hits     = in_array('HITS', $columns, true);

        $duration_from   =  $do_duration ? $duration_helper->getFromTime() : 0;

        $conds = [['id', $s_zids->getArray(), 'i']];
        if ($from) {
            $conds[] = ['time', $from, '>='];
        }
        $hits = DB_AA::select([], 'SELECT id, time, hits'.($do_duration ? ', duration': '') .' FROM hit_archive', $conds);

        $stat = [];
        $empty = $do_duration ? [0,0.0,0,0.0,0,0.0,0,0.0,0] : [0];  // hits, dur_0, count_0, dur_1, count_1, dur_2, count_2, dur_3, count_3
        foreach ($hits as $hit) {
            ['id'=>$hit_id, 'time'=>$hit_time, 'hits'=>$hit_hits] = $hit;
            $day = date($group_string, $hit_time);
            if (!isset($stat[$day])) {
                $stat[$day] = [];
            }
            $stat[$day][$hit_id]   ??= $empty;
            $stat[$day][$hit_id][0] += $hit_hits;
            if ($do_sum) {
                $stat[$day]['all']   ??= $empty;
                $stat[$day]['all'][0] += $hit_hits;
            }

            if ($do_duration && ($hit_time >= $duration_from)) {
                $index    = 1 + (2 * ($hit['duration'] & 3));   // position in array  2, 4, 6, 8
                $duration = $hit['duration'] >> 2;
                // weighted average: (old_duration * old_count  + new_duration * new_count) / (old_count + new)
                $stat[$day][$hit_id][ $index ]   =  ($stat[$day][$hit_id][ $index ] * $stat[$day][$hit_id][ $index + 1 ] + $duration * $hit_hits) / ($stat[$day][$hit_id][ $index+1 ] +  $hit_hits);
                $stat[$day][$hit_id][ $index+1 ] += $hit_hits;
                if ($do_sum) {
                    $stat[$day]['all'][ $index ]   = ($stat[$day]['all'][ $index ] * $stat[$day]['all'][ $index + 1 ] + $duration * $hit_hits) / ($stat[$day]['all'][ $index+1 ] +  $hit_hits);
                    $stat[$day]['all'][ $index+1 ] += $hit_hits;
                }
            }
        }
        return $stat;
    }


    protected function getGraph(int $width=0, int $height=0, array $data=[], array $labels=[], string $type='line'): string {
        $datasets = [];
        foreach ($data as $set) {
            $datasets[] = ['label' => $set['label'],'data' => $set['data'],'borderColor' => $set['color'], 'borderDash' => $set['dash'] ?? [], 'tension'=> 0.2, 'borderWidth'=> $set['width'] ?? 2];
        }

        $conf = [
            'type' => $type,
            'data' => [
                'labels' => $labels,
                'datasets' => $datasets
            ],
            'options' => [
                'responsive' => true,
                'plugins' => [
                    'legend' => [
                        'position' => 'top',
                    ],
                    'title' => [
                        'display' => false,
                        // 'text' => 'Chart.js Line Chart'
                    ]
                ]
            ]
        ];

        if ($type==='stacked') {
            $conf['type'] = 'bar';
            $conf['options']['scales'] = [
                'y' => ['stacked' => true],
                'x' => ['stacked' => true]
            ];
        }

        $height = $height ?: 300;
        $width  = $width  ?: 600;
        $chart_id = gensalt(8);

        $ret = "<div class=\"aa-chart-container\" style=\"height: {$height}px; position: relative;\">";
        $ret .= "<canvas id=\"$chart_id\" height=\"$height\" width=\"$width\"></canvas>";
        $ret .= "</div>";
        $ret .= "<script> new Chart(document.getElementById('$chart_id'), ".json_encode($conf)."); </script>";
        return $ret;
    }

    /** generate 360 diferent saturated colours for different indexes */
    function generateSaturatedColor($index) {
        // Generate hues that are sufficiently spaced out
        $hue = ($index * 36) % 360; // For the first 10 colors, hues are 0, 36, 72, ..., 324

        // For next 340 colors, ensure hue is not repeated for at least 300 iterations
        if ($index >= 10) {
            $hue = (($index - 10) * 1.06) % 360; // Create more diverse hues for next 340 colors
        }

        // Saturation is set to 100% for a saturated color, and lightness is 50%
        return $hue;
    }

    function hslToHex($h, $s, $l) {
        $s /= 100;
        $l /= 100;

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - ($c / 2);

        if ($h < 60) {
            $r = $c; $g = $x; $b = 0;
        } elseif ($h < 120) {
            $r = $x; $g = $c; $b = 0;
        } elseif ($h < 180) {
            $r = 0; $g = $c; $b = $x;
        } elseif ($h < 240) {
            $r = 0; $g = $x; $b = $c;
        } elseif ($h < 300) {
            $r = $x; $g = 0; $b = $c;
        } else {
            $r = $c; $g = 0; $b = $x;
        }

        $r = ($r + $m) * 255;
        $g = ($g + $m) * 255;
        $b = ($b + $m) * 255;

        return sprintf("#%02X%02X%02X", round($r), round($g), round($b));
    }

    function generateColorShades($baseColor) {
        $shades = [];

        // Generate 4 lighter shades by increasing the lightness
        for ($i = 1; $i <= 4; $i++) {
            $lightness = $baseColor['l'] + $i * 10;
            if ($lightness > 100) {
                $lightness = 100;
            }
            $shades[] = hslToHex($baseColor['h'], $baseColor['s'], $lightness);
        }

        return $shades;
    }

}

/** @return string - avatar img or colored div with initials
 *  {avatar:<img_url>:[<person_name>]:[<avatar-size>]}
 *  Usage:
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
        $title = !str_contains($name, '@') ? $name : aa_substr($name,0,aa_strpos($name,'@'));

        if ($img = AA::Un("{img:$image:w=$size&h=$size&iar=1:imgb:$title}")) {
            return $img;
        }
        $nplus    = $title . '--';
        $match    = [];
        $initials = aa_substr($nplus,0,1). (preg_match('/.[ ._-]+([^ ._-])/u', $title, $match) ? $match[1] : aa_substr($nplus,1,1))  ;
        $color    = (crc32($name) % 8) + 1;
        return "<div class=\"dis-color$color\" title=\"$title\">$initials</div>";
    }
}




/** Creates link to modified image using phpThub
 *  {img:<url>:[<phpthumb_params>]:[<info>]:[<param1>]:[<param2>]}
 *
 *  Usage:
 *     {img:_#this:w=168&h=127&zc=1:im:_#HEADLINE:width=168 height=127}  // this is the quickest - exact size (zc=1), no need to size check => im
 *     {img:_#this:w=168&h=127&zc=1:picture:_#HEADLINE}                  // preferred - create lazy loaded <picture> with quick WEBP picture alternative for current browsers
 *     <img src="{img:{img_url.........}:w=150&h=150}">  /"
 *     <img src="{img:{img_url.........}:w=150&h=150&iar=1}">  /"        // iar - ignore aspect ratio - deform the image to exact size
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
        [$img_url,$img_short] = self::_getUrl($image, $phpthumb_params);

        if (empty($img_url)) {
            return $img_short;
        }

        switch ( $info ) {
            case '':
            case 'url':
                return $img_short;
            case 'rawurl':
                return str_replace('&amp;', '&', $img_url);
            case 'im':      //  quick version - img without width and height
                return "<img src=\"$img_short\" $param2 loading=lazy". (($param1 = safe(strip_tags($param1))) ? " title=\"$param1\" alt=\"$param1\" />" : ' />');
            case 'picture':
                $thumb_params_arr = [];
                parse_str($phpthumb_params, $thumb_params_arr);
                return self::getPicture($img_short, (int)$thumb_params_arr['w'], (int)$thumb_params_arr['h'], $param1, $param2);
        }

        $a = @getimagesize(str_replace('&amp;', '&', $img_url));
        if (! $a) {
            return '';
        }

        // No warning required, will be generated by getimagesize
        switch ( $info ) {
            case 'width':   return $a[0];
            case 'height':  return $a[1];
            case 'imgtype': return $a[2]; // 1 = GIF, 2 = JPG, 3 = PNG, 4 = SWF, 5 = PSD, 6 = BMP, 7 = TIFF(intel byte order), 8 = TIFF(motorola byte order), 9 = JPC, 10 = JP2, 11 = JPX, 12 = JB2, 13 = SWC, 14 = IFF, 15 = WBMP, 16 = XBM,   17 = ICO, 18 = WEBP
            case 'mime':    return image_type_to_mime_type($a[2]);
            case 'html':
            case 'hw':      return $a[3]; //height="xxx" width="yyy"
            //case 'im':      - see above
            //case 'picture': - see above
            //case 'url':     - see above
            //case '':        - see above
            case 'imgb':    $param2 .= ' border=0';  // no break!!!
            case 'img':     $param1 = safe(strip_tags($param1));
                $alt = $param1 ? " title=\"$param1\" alt=\"$param1\"" : '';
                return "<img src=\"$img_short\" loading=lazy ". $a[3] ." $alt $param2 />";
        }
        return '';
    }

    protected static function getPicture(string $src, int $w, int $h, string $title='', string $add='') {
        if (!$w OR !$h) {
            warn('width (w) or height (h) parameter is not provided in params - you should use something like w=150&h=150&zc=1');
            return '';
        }

        $title = safe(strip_tags($title));
        $alt_title = $title ? " title=\"$title\" alt=\"$title\"" : '';
        $webp_src = $src. '&amp;f=webp';

        $ret =  "<picture class=aa-picture>";
        $ret .= "\n  <source srcset=\"$webp_src\" type=image/webp>";
        $ret .= "\n  <img src=\"$src\" width=$w height=$h $add loading=lazy $alt_title>";  // lazy is enough on img for <picture> element
        $ret .= "\n</picture>";
        
        return $ret;
    }


    /**
     * @param $image
     * @param $phpthumb_params
     * @return array
     */
    protected static function _getUrl($image, $phpthumb_params) {
        if (empty($phpthumb_params)) {
            return [$image,$image];
        }
        // separate parameters
        if (!str_contains($phpthumb_params, '&amp;')) {
            $phpthumb_params = str_replace('&', '&amp;', $phpthumb_params);
        }

        // it is much better for phpThumb to access the files as files relative
        // to the directory, than using http access
        if (AA_HTTP_DOMAIN !== "/") {
            $image = str_replace(AA_HTTP_DOMAIN, '', $image);
        }
        if ((aa_substr($image,0,4)==="http") OR (aa_substr($image,0,2) === "//")) {
            $image = preg_replace("~^(http[s]?:)?//(www\.)?(.+)\.([a-z]{1,6})/(.+)~", "\\5", $image);
        }

        return [AA_INSTAL_URL. "img.php?src=/$image&amp;$phpthumb_params", AA_INSTAL_PATH. "img.php?src=/$image&amp;$phpthumb_params"];
    }
}

/** Creates image with the specified text:
 *  {imgtext:<width>:<height>:<text>:<size>:<alignment>:<color>:<font>:<opacity>:<margin>:<angle>:<background>:<bg_opacity>}
 *
 *  Usage:
 *    {imgtext:20:210:My picture text:3:TL:000000::::90}
 *    - returns white 20 x 210px big image with vertical, top-left positioned black text on it
 *
 *  for phpThumb params see http://phpthumb.sourceforge.net/demo/demo/phpThumb.demo.demo.php
 *  (phpThumb library is the part of AA)
 **/
class AA_Stringexpand_Imgtext extends AA_Stringexpand_Nevercachenotrim {

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
        $txt        = rawurlencode($text);
        $bg         = (strlen($background) ? $background : 'FFFFFF') .'|'. (strlen($bg_opacity) ? $bg_opacity : '0');
//        $color      = (strlen($color) ? $color : '000000');
        $param      = implode('|', [$txt, $size, $alignment, $color, $font, $opacity, $margin, $angle, $background, $bg_opacity]);
        $img_url    = AA_INSTAL_PATH. "img.php?new=$bg&amp;w=$width&amp;h=$height&amp;fltr[]=wmt|$param&amp;f=png";
        return "<img src=\"$img_url\" width=\"$width\" height=\"$height\" alt=\"$text\"/>";
    }
}

/** Creates B/W QR code image
 *  {qrcode:<text>[:<size>:<margin>]}
 *
 *  @usage <img src="{qrcode:https#://actionapps.org:600}" alt="AA website" width=200 height=200>
 *  @param $text - text to encode (utf)
 *  @param $size - size of the image in pixels (default 300)
 *  @param $margin - margin (default 2)
 *  @returns string base64 encoded image (empty one on error))
 **/
class AA_Stringexpand_Qrcode extends AA_Stringexpand {
    function expand($text='', $size='', $margin='') {
        if (!trim($text)) {
            return NO_PICTURE_DATA;
        }
        $size     = ctype_digit((string)$size) ? (int)$size : 300;
        $margin   = ctype_digit((string)$margin) ? (int)$margin : 2;
        $renderer = new GDLibRenderer( $size, $margin);
        $writer   = new Writer($renderer);
        try {
            $qr_image = base64_encode($writer->writeString($text, 'UTF-8', ErrorCorrectionLevel::M()));
        } catch (Exception $e) {
            return NO_PICTURE_DATA;
        }
        return "data:image/png;base64,$qr_image";
    }
}

/** Creates B/W QR image for payment
 *  {qrpay:<account>:<amount>[:<currency>:<reference-number>:<message>:<due-date>:<specific-symbol>:<recipient-name>]}
 *
 *  @usage <img src="{qrpay:12-3456789012/0100:1234.56:CZK:2023000123:Honza for Ecn Services 2023:2023-01-01:1234567890:Ecn Studio ltd.}" alt="QR payment" width=200 height=200>
 *  @param $account - IBAN or account number (automatically converted to IBAN)
 *  @param $amount - amount to pay
 *  @param $currency - currency (default EUR) - 3 letterers currency code like EUR, CZK, USD, GBP, COP ...
 *  @param $reference_number - reference number (variable symbol) - 1-10 digits
 *  @param $message - message - 1-60 alphanumeric characters
 *  @param $due_date - due date - YYYY-MM-DD
 *  @param $specific_symbol - specific symbol - 1-10 digits
 *  @param $recipient_name - recipient name - 1-35 alphanumeric characters
 **/
class AA_Stringexpand_Qrpay extends AA_Stringexpand {
    function expand($account='', $amount='', $currency='', $reference_number='', $message='', $due_date='', $specific_symbol='', $recipient_name='') {
        if (!$account OR !$amount) {
            return '';
        }
        $amount = (float)PhpFloat($amount);
        $qr = new QRPlatba($account, $amount, $reference_number, $currency ?: 'EUR');
        try {
            if ($message)         { $qr->setMessage($message); }
            if ($due_date)        { $qr->setDueDate(new DateTime($due_date)); }
            if ($specific_symbol) { $qr->setSpecificSymbol($specific_symbol); }
            if ($recipient_name)  { $qr->setRecipientName($recipient_name); }
        } catch (Exception) {}
        return $qr->getQRCodeImage(false, 800, 2);
    }
}

/** get parameters (size or type) from the file
 *  {fileinfo:<url>:<info>}
 *
 *  Usage:
 *     {fileinfo:{file............}:size}  - returns size of the file
 *
 *  @author Adam Sanchez, Honza Malík
 **/
class AA_Stringexpand_Fileinfo extends AA_Stringexpand {

    /**
     * @param string $url
     * @param string $info = type     - like TXT, ...
     *                       name     filename
     *                       size     - like 12.1 kB
     *                       bytesize - like 12145
     *                       link
     *                       typesize
     *                       crc
     *                       time
     *                       mime     - mime like image/jpeg
     *                       ifwebimg - url of the img if it is web image displayable by <img src=""> tag; empty if not
     *
     * @return int|string|void
     */
    function expand($url='', $info='') {

        if (!strlen($url)) {
            return '';
        }

        switch ( $info ) {
            case 'type':
                $url2array = explode(".",basename(parse_url($url, PHP_URL_PATH)));
                $part = count($url2array)-1;
                return ($part>0) ? $url2array[$part] : 'TXT';
            case 'name':
                return basename(parse_url($url, PHP_URL_PATH));
            case 'bytesize':
            case 'size':
                $filename = str_replace(IMG_UPLOAD_URL, IMG_UPLOAD_PATH, $url);
                if (is_file($filename)) {
                    $size = @filesize($filename);
                } else {
                    $fullurl = $url[0] === '/' ? 'https://'.$_SERVER['HTTP_HOST'].$url : $url;
                    $size = @strlen(file_get_contents($fullurl));
                }

                if ($size) {
                    if ($info==='bytesize') {
                        return $size;
                    }
                    $size_kb = round($size/1024, 1);
                    $size_mb = round($size/1048576, 1);
                    return ($size <= 1048576) ? $size_kb." kB" : $size_mb." MB";
                }
                break;
            case 'link':
                return StrExp('filelink', [$url]);
            case 'typesize':
                $fileinfo = implode('&nbsp;-&nbsp;', [StrExp('fileinfo', [$url, 'type']), StrExp('fileinfo', [$url, 'size'])]);
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
            case 'mime':
                return $this->getMimeType($url);
            case 'ifwebimg': // returns url if it is web image displayable by <img src=""> tag
                return in_array($this->getMimeType($url), ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'image/bmp'], true) ? $url : '';
        }
        return '';
    }

    protected function getMimeType($url): string {
        $filename = str_replace(IMG_UPLOAD_URL, IMG_UPLOAD_PATH, $url);
        $mimeType = '';
        if (is_file($filename)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filename);
            finfo_close($finfo);
        }
        return $mimeType ?: '';
    }
}

/** get link to file for download (prints also file size and type)
 *  {filelink:<url>:<text>:<text-before>}
 *
 *  Usage:
 *     {filelink:{file............}:{text............}:Download#: }
 *     returns: <a href="http://..." title="Document [PDF - 157 kB]">Document</a> [PDF - 157 kB]
 **/
class AA_Stringexpand_Filelink extends AA_Stringexpand {
    /** Do not trim all parameters ($text_before could have space at the end) */
    static function doTrimParams() { return false; }

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
        $filename = $text ?: basename(parse_url($url, PHP_URL_PATH));
        $fileinfo = implode(' - ', [StrExp('fileinfo', [$url, 'type']), StrExp('fileinfo', [$url, 'size'])]);
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
        require_once __DIR__ . "/../modules/alerts/util.php";

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
 *  It is useful for site module, when you need to display protected content
 *  Experimental
 *  Usage: {credentials:ThisIsThePassword}
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
 *  Usage:
 *   {qs:surname}    - returns Havel for https://example.org/cz/page?surname=Havel
 *   {qs}            - returns whole querystring (including GET and POST variables)
 *   {qs:aa[n1000_3130303132312d726d2d7361736f762d][con_email_______][]}
 *                   - returns the value of the variable - exactly as posted
 */
class AA_Stringexpand_Qs extends AA_Stringexpand_Nevercachenotrim {

    /** expand function
     * @param $ids_string
     * @param $expression
     * @param $delimiter
     * @return array|string
     */
    function expand($variable_name='', $delimiter=null) {
        if (empty($variable_name)) {
            return shtml_query_string();
        }
        $ret = '';
        if (str_contains($variable_name, '[')) {
            $qstring = rawurldecode(shtml_query_string());
            if (str_contains($qstring, $variable_name)) {
                $qarr = explode('&', $qstring);
                foreach ($qarr as $vardef) {
                    [$var,$val] = explode('=',$vardef);
                    if ($var == $variable_name) {
                        $ret = rawurldecode($val);
                        break;
                    }
                }
            }
        } elseif (isset($_REQUEST[$variable_name])) {
            $ret = $_REQUEST[$variable_name];
        } elseif ( $_SERVER['CONTENT_TYPE'] === 'application/json' ) {
            $req_body = json_decode(file_get_contents('php://input'), true);
            $ret      = $req_body[$variable_name];
        } else {
            $shtml_get = add_vars('', 'return');
            $ret = $shtml_get[$variable_name];
        }
        return !is_array($ret) ? $ret : ( is_null($delimiter) ? json_encode($ret) : implode($delimiter, $ret));
    }
}


/** @return string - url GET parameter htlmencoded - {qss[:<varname>[:delimiter]]}
 *  equivalent to {qss:} {safe:{qs:...}}
 *  use it all the time you are printing user input (url parameters) to HTML page - it prevents XSS attacks
 *  Usage:
 *   {qss:surname}    - returns &lt;Havel&gt; for https://example.org/cz/page?surname=<Havel>
 *   {qss}            - returns whole querystring (including GET and POST variables) html encoded
 *   {qss:aa[n1000_3130303132312d726d2d7361736f762d][con_email_______][]}
 *                   - returns the value of the variable - exactly as posted - html encoded
 */
class AA_Stringexpand_Qss extends AA_Stringexpand_Qs {

    //  expand($variable_name='', $delimiter=null) {
    function expand($variable_name = '', $delimiter = null) {
        return safe(parent::expand($variable_name, $delimiter));
    }
}


/** Returns actual server load
 *  Usage: {serverload}
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
 *  (for more advanced version see AA_Generator_Rnd)
 *  Usage: {randomstring:5}
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
    require_once __DIR__."/custom/".STRINGEXPAND_INC. '/stringexpand.php';
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
    static function doTrimParams() { return false; }

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        // is it necessary to have $this->item here?
        return serialize([!is_object($this->item) ? '' : $this->item->getId()]);
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
    function expand(...$arg_list) {
        static $saliases = [];
        static $smodules = null;

        $name = trim(array_shift($arg_list));
        if (!strlen($name)) {
            return '';
        }

        AA::$debug&32 && AA::$dbg->tracestart('{_:.. load}', $name);

        if ( isset($GLOBALS['STRINGEXPAND_SHORTCUTS'][$name]) ) { // old deprecated listing of aliases in custom file
            $text = $GLOBALS['STRINGEXPAND_SHORTCUTS'][$name];
        } else {
            if (is_null($saliases[$name])) {
                if (is_null($smodules)) {
                    if (AA::$site_id) {
                        $smodules = self::getAliasSites(AA::$site_id);
                    } elseif (AA::$slice_id) {
                        $smodules = explode_ids(StrExp('modulefield', [AA::$slice_id, 'site_ids']));
                    } else {
                        $smodules = [];
                    }
                }
                $zids = AA_Object::querySet('AA_Aliasfunc', new AA_Set($smodules, new AA_Condition('alias', '==', $name)));
                $saliases[$name] = AA_Object::loadProperty($zids->longids(0),'code');

                // another approach read all at once - not used
                // huhl( AA_Object::loadProperties($zids->longids(), 'aa_name'));
            }
            $text = $saliases[$name];
        }
        AA::$debug&32 && AA::$dbg->traceend('{_:.. load}', $text);

        AA::$debug&8 && AA::$dbg->tracestart("_:$name", implode(',',$arg_list));
        $text = AA_Stringexpand::replaceParams($text, $arg_list);
        if (str_contains($text, '{(')) {
            // we want to use inline aliases also inside {_:aliases...}
            $text = AA_Stringexpander::ApplyInlineAlias($text);
        }
        AA::$debug&8 && AA::$dbg->traceend("_:$name", $text);

        return $text;
    }

    /** Get all sites which uses this _Aliases
     * @param string $site_id
     * @return array
     */
    protected static function getAliasSites(string $site_id): array {
        $ret = [$site_id];
        if ($site = AA_Module_Site::getModule($site_id)) {
            if (is_array($add_modules = $site->getProperty('add_aliases'))) {
                $ret = array_merge($ret, array_filter($add_modules));
            }
        }
        return $ret;
    }
}

/** Encrypt the text using $key as password (mcrypt PHP extension must be installed)
 *  $output_type |url (if not specified, binary is used. if "url", the data are base64url is used)
 */
class AA_Stringexpand_Encrypt extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    static function doTrimParams() { return false; }

    /**
     * @param string $text
     * @param string $key
     * @param null $output_type - no longer used - previously used 'url' to encode result in url-friendly base64_url, now it is always
     * @return string|void
     */
    function expand($text='', $key='', $output_type=null) {
        return AA\Util\Crypt::encryptAesGcm($text,$key);
    }

    /**
     * @param $data
     * @return string
     */
    static function get_time_token($data): string {
        /** @todo use other constant form config.php - like AA_ENCRYPT_CREDENTIALS if defined instead of AA_ID */
        return AA\Util\Crypt::encryptAesGcm(serialize($data), 'aa-sul'.AA_ID.date('j.n.y H'));
    }

    /** Try to decode sent token - not older than $hours
     *  $hours must count with cache - the tokens on the form could be in the cache
     * @param string $token
     * @param int    $hours
     * @return mixed
     * @throws Exception
     */
    static function decrypt_time_token(string $token, int $hours=24) {
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
    static function doTrimParams() { return false; }

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
        return '';

        // if we fail with the decryption, we try, is the string wasn't encrypted in older AA version, which used mcrypt
        // However this approach will work just for PHP <= 7.1, so users will need to reencrypt the texts, if they need
        // to use old crypted texts

        // return self::_encryptdecrypt_legacy(false,(($output_type==='url') ? url2bin($text) : $text), $key);
    }

    //    /**
    //     * Old legacy en/de-crypt function which uses mcrypt - the results are completely incompatible with other cryptolibs
    //     * @param $mode_encrypt
    //     * @param $text
    //     * @param $key
    //     * @return string
    //     */
    //    protected function _encryptdecrypt_legacy($mode_encrypt, $text, $key) {
    //        if (!function_exists('mcrypt_module_open')) {
    //            return '';
    //        }
    //        /* Open module, and create IV */
    //        $td      = mcrypt_module_open('des', '', 'ecb', '');
    //        $key     = substr($key, 0, mcrypt_enc_get_key_size($td));
    //        $iv_size = mcrypt_enc_get_iv_size($td);
    //        $iv      = mcrypt_create_iv($iv_size, MCRYPT_RAND);
    //
    //        $ret = '';
    //        /* Initialize encryption handle */
    //        if (mcrypt_generic_init($td, $key, $iv) != -1) {
    //            $ret = $mode_encrypt ? mcrypt_generic($td, $text) : mdecrypt_generic($td, $text);
    //            mcrypt_generic_deinit($td);
    //            mcrypt_module_close($td);
    //        }
    //        return $ret;
    //    }
}

/** Generates JWT (JSON Web Token) for user identified by <username> and <password>
 *  Usage: {jwtencode:secret-key:{qss:email}:{qss:password}:3600:_#EXITTIME}
 */
class AA_Stringexpand_Jwtencode extends AA_Stringexpand {

    /**
     * @param string $key          - secretkey for JWT sign
     * @param string $username     - username
     * @param string $password     - password
     * @param string $lifetime     - lifetime in seconds (default - 1 day)
     * @param string $add_validity - additional check - alias or aa code evaluated for the user. When user then access the
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
            // if we are using $add_validity, then the user MUST be from Reader Management, so we can compute the add_valid
            return '';
        }

        $token = [];
        $token['sub'] = $uid;
        $token['exp'] = time() + ((int)$lifetime ?: 24*3600);
        $token['iat'] = time();
        if (strlen($add_validity)) {
            $token['aaValid'] = StrExp('item', [$uid,$add_validity]);
        }

        // IMPORTANT:
        // You must specify supported algorithms for your application. See
        // https://tools.ietf.org/html/draft-ietf-jose-json-web-algorithms-40
        // for a list of spec-compliant algorithms.
        $jwt = \Firebase\JWT\JWT::encode($token, $key, 'HS256');

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
            [$type, $jwt] = explode(" ", $auth_header, 2);

            if (strcasecmp($type, "Bearer") == 0) {
                try {
                    $decoded = (array)\Firebase\JWT\JWT::decode($jwt, new Firebase\JWT\Key($key, 'HS256'));  // new (v6.0.0)
                    // old v5.0.0
                    // $decoded = (array)\Firebase\JWT\JWT::decode($jwt, $key, ['HS256']);
                    $uid = $decoded['sub'];
                    if (is_long_id($uid) AND strlen($add_validity)) {
                        if ($decoded['aaValid'] == StrExp('item', [$uid,$add_validity])) {
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

/**  */
class AA_Stringexpand_Setsecret extends AA_Stringexpand_Nevercache {
    /**
     * @param string $text
     * @return string|void
     */
    function expand($username='', $password='', $code2fa='') {
        if (!strlen($username) OR !strlen($password) OR !($uid = AA::$perm->authenticateUsername($username, $password, $code2fa)) OR ($uid != AuthGetUid())) {
            return '';
        }
        $ga = new PHPGangsta_GoogleAuthenticator();
        $secret = $ga->createSecret();


        $qrCodeUrl = $ga->getQRCodeGoogleUrl('Blog', $secret);
        echo "Google Charts URL for the QR-Code: ".$qrCodeUrl."\n\n";

        $oneCode = $ga->getCode($secret);
        echo "Checking Code '$oneCode' and Secret '$secret':\n";

        $checkResult = $ga->verifyCode($secret, $oneCode, 2);    // 2 = 2*30sec clock tolerance
        if ($checkResult) {
            echo 'OK';
        } else {
            echo 'FAILED';
        }

    }

}

/** computes MD5 hash */
class AA_Stringexpand_Md5 extends AA_Stringexpand_Nevercachenotrim {

    /**
     * @param string $text
     * @return string|void
     */
    function expand($text='') {
        return hash('md5', $text);
    }
}

/** crypt text as AA password */
class AA_Stringexpand_Pwdcrypt extends AA_Stringexpand_Nevercachenotrim {

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
class AA_Stringexpand_Table extends AA_Stringexpand_Nevercachenotrim {

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
        static $tables = [];

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
class AA_Stringexpand_Array extends AA_Stringexpand_Nevercachenotrim {

    /**
     * @param string $id
     * @param string $cmd
     * @param null $par1
     * @param null $par2
     * @param null $par3
     * @return string|void
     */
    function expand($id='', $cmd='', $par1=null, $par2=null, $par3=null) {
        static $arrays = [];
        if (!strlen($id)) {
            return '';
        }
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
            case 'addmulti':
                $arr->addmulti($par1, $par2); // $values, $delimiter
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
            case 'getfiltered':
                // filter, $delimiter
                $ret = $arr->getFiltered($par1, $par2);
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
     * @param string $item_id
     * @return string|void
     */
    function expand($item_id=null) {
        return is_long_id($item_id) ? ChangesMonitor::singleton()->lastChanged($item_id) : '';
    }
}

/**
 * Class AA_Stringexpand_Changedate
 */
class AA_Stringexpand_Changedate extends AA_Stringexpand {
    /**
     * @param string $item_id
     * @param string $field_id
     * @param string $format
     * @return string
     */
    function expand($item_id=null, $field_id=null, $format=null): string {
        $time = ( !is_long_id($item_id) OR !$field_id) ? '0' : (string)ChangesMonitor::singleton()->lastChangeDate($item_id,$field_id);
        return StrExp('date', [$format, $time, '--']);
    }
}

/**
 * Class AA_Stringexpand_History
 */
class AA_Stringexpand_History extends AA_Stringexpand {
    /**
     * @param string $item_id   item to get history record
     * @param string $field_id  field in item to get history record
     * @param string $time      timestamp (positive value) - get the value for that time,
     *                          -x (negative value) - the x-th last value (-1 is default - last value before current)
     *                          string - like "|" - all values delimited by |
     * @param string $format    any string with aliases _#val, _#date, _#user, _#timestamp - default _#val
     * @example {history:f6d805868794d003f112d37d1723a5d9:year............}
     * @example {history:f6d805868794d003f112d37d1723a5d9:year............:-2:_#date - _#val}
     * @example {history:f6d805868794d003f112d37d1723a5d9:year............:<br>:_#date - _#val (_#user)}
     * @return string
     */
    function expand($item_id=null, $field_id=null, $time=null, $format=''): string {
        if (strlen($time) and !IsSigInt($time)) {
            return $this->formatChange(ChangesMonitor::singleton()->changesAll($item_id, $field_id), $format, $time);
        }
        $time = (int)$time ?: -1;
        if ($time < 0) {
            return $this->formatChange([ChangesMonitor::singleton()->changeByStep($item_id, $field_id, $time)], $format);
        }
        // timestamp based
        return  $this->formatChange([ChangesMonitor::singleton()->changeByTime($item_id, $field_id, $time)], $format);
    }

    protected function formatChange(array $changes, string $format, string $delimiter=''): string {
        $retarr = [];
        $format = $format ?: '_#val';
        foreach ($changes as $ch) {
            if ($ch) {
                $retarr[] = str_replace(['_#val', '_#date', '_#user', '_#timestamp'], [$ch['value'], date('Y-m-d H:i:s', ChangesMonitor::toTimestamp((int)$ch['time'])), $ch['user'], date('U', ChangesMonitor::toTimestamp($ch['time']))], $format);
            }
        }
        return implode($delimiter, $retarr);
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
        $parameter = strlen($parameter) ? str_replace(["\r\n","\n", "\r"], ' ', $parameter) : '';       // remove line endings
        switch ($header) {
            case '200':       AA::$headers['status']      = 'HTTP/1.1 200 OK';                       break;
            case '201':       AA::$headers['status']      = 'HTTP/1.1 201 Created';                  break;
            case '304':       AA::$headers['status']      = 'HTTP/1.1 304 Not Modified';             break;
            case '400':       AA::$headers['status']      = 'HTTP/1.1 400 Bad Request';              break;
            case '401':       AA::$headers['status']      = 'HTTP/1.1 401 Unauthorized';             break;
            case '404':       AA::$headers['status']      = 'HTTP/1.0 404 Not Found';                break;
            case '409':       AA::$headers['status']      = 'HTTP/1.1 409 Conflict';                 break;
            case '410':       AA::$headers['status']      = 'HTTP/1.1 410 Gone';                     break;
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
            case 'ical':      AA::$headers['type']        = 'text/calendar';                         break;
            case 'filename':  AA::$headers['disposition'] = 'attachment; filename="'.$parameter.'"'; break;  // $parameter is OK not escaped
            case 'utf-8':     AA::$headers['encoding']    = 'utf-8';                                 break;
            case 'CSP':       AA::$headers['CSP']         = 'Content-Security-Policy: '.$parameter;  break;
            case 'CSP-RO':    AA::$headers['CSP-RO']      = 'Content-Security-Policy-Report-Only: '.$parameter;  break;
            case 'RT':        AA::$headers['RT']          = 'Report-To: '.$parameter;                break;
            case 'NEL':       AA::$headers['NEL']         = 'NEL: '.$parameter;                      break;
            case 'XSS':       AA::$headers['XSS']         = 'X-Xss-Protection: '.$parameter;         break;
            case 'XCTO':      AA::$headers['XCTO']        = 'X-Content-Type-Options: '.$parameter;   break;
            case 'RP':        AA::$headers['RP']          = 'Referrer-Policy: '.$parameter;          break;
            case 'CORS':      AA::$headers['CORS']        = 'Access-Control-Allow-Origin: '.$parameter;
                              AA::$headers['CORSH']       = 'Access-Control-Allow-Headers: *';              // we need at least X-Requested-With header allowed - see {lazy ...}
                                                                                                     break;
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
    static function getFirstForm() {  // Type in either your username or e-mail
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
    static function askForMail($user, $slice_id, $from_email) {
        if ( !trim($user) ) {
            return self::bad(_m("Unable to find user - please check if it has been misspelled."));
        }
        if (!($user_id = AA_Reader::name2Id($user, [$slice_id]))) {
            if (!($user_id = AA_Reader::email2Id($user, [$slice_id]))) {
                return self::bad(_m("Unable to find user - please check if it has been misspelled."));
            }
        }
        $user_info = AA_Reader::getReaderItemContent($user_id);

        // generate MD5 hash
        $email     = $user_info->getValue(FIELDID_EMAIL);
        $pwdkey    = md5($user_id.$email.AA_ID.round(now()/60));
        $pwd_param = "aapwd2=$pwdkey-$user_id";

        // send it via email
        $url      = shtml_url()."?$pwd_param";
        $pwd_link = "<a href=\"$url\">$url</a>";

        $add_alias = [
            "_#PWD_LINK" => GetAliasDef( "f_t:$pwd_link",  "", _m('Password link')),
            "_#PWD_URLP" => GetAliasDef( "f_t:$pwd_param", "", _m('Password change url parameter')),
        ];

        if ($template_id = DB_AA::select1('id', 'SELECT id FROM `email`', [
            ['owner_module_id', $slice_id, 'l'],
            ['type', 'password change'],
        ])) {
            $slice     = AA_Slice::getModule($slice_id);
            $user_item = new AA_Item($user_info, $slice->aliases( $add_alias ));
            AA_Mail::sendTemplate($template_id, [$email], $user_item, false);
        } else {
            $mail     = new AA_Mail;
            $mail->setSubject(_m("Password change"));
            $body = _m("To change the password, please visit the following address:<br>%1<br>Change will be possible for two hours - otherwise the key will expire and you will need to request a new one.", ["<a href=\"$url\">$url</a>"]);
            $mail->setHtml($body, html2text($body));
            $mail->setHeader("From", $from_email);
            $mail->setHeader("Reply-To", $from_email);
            $mail->setHeader("Errors-To", $from_email);
            $mail->setCharset(Langs::getCharset());
            $mail->send([$email]);
        }

        return self::ok(_m('E-mail with a key to change the password has just been sent to the e-mail address: %1', [$email]));
    }

    /**
     * @param $key
     * @param $user
     * @return string
     */
    static function getChangeForm($key, $user) {
        if (!self::isValidKey($key, $user)) {
            return self::bad(_m("Bad or expired key."));  // @todo get messages from somewhere
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
    static function changePassword($pwd1, $pwd2, $key, $user, $from_email) {
        if (!self::isValidKey($key, $user)) {
            return self::bad(_m("Bad or expired key."));  // @todo get messages from somewhere
        }
        if ($pwd1 != $pwd2) {
            return self::bad(_m("Passwords do not match - please try again."));  // @todo get messages from somewhere
        }
        if (strlen($pwd1) < 6) {
            return self::bad(_m("The password must be at least 6 characters long."));  // @todo get messages from somewhere
        }

        if (UpdateField($user, FIELDID_PASSWORD, new AA_Value(ParamImplode(['AA_PASSWD',$pwd1])))) {
            // if we are updating password, we know, the user is verified (the Change pwd feature can be used for confirmation of user, if the initial validation mail is lost)
            UpdateField($user, FIELDID_MAIL_CONFIRMED, new AA_Value('1'));
            return self::ok(_m("Password changed."));
        }
        return self::ok(_m("An error occurred during password change - please contact: %1.", [$from_email]));
    }

    private static function isValidKey($key, $user_id) : bool {
        if (!$key OR !$user_id) {
            return false;
        }
        if (($user_info = AA_Reader::getReaderItemContent($user_id))->isEmpty()) {
            return false;
        }
        // Check the key
        $email    = $user_info->getValue(FIELDID_EMAIL);
        $key_base = $user_id.$email.AA_ID;
        for ($i=0; $i< self::KEY_TIMEOUT; $i++) {
            if (hash('md5', $key_base.round(round(now()/60)-$i)) == $key) {
                return true;
            }
        }
        return false;
    }

    private static function bad($text) : string {
        return '<div class="aa-err">'.$text.'</div>'.self::getFirstForm();
    }

    /**
     * @param $text
     * @return string
     */
    private static function ok($text) :string {
        return '<div class="aa-ok">'.$text.'</div>';
    }

    /** we need to check, if the text answer means OK or Bad */
    public static function is_ok($text) : bool {
        return str_starts_with($text, '<div class="aa-ok">');
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
        $from_email = $from_email ?: ERROR_REPORTING_EMAIL;

        if (isset($_POST['aapwd3'])) {    // Change Password
            return AA_Password_Manager_Reader::changePassword($_POST['aapwd3'], $_POST['aapwd3b'], $_POST['aakey'], $_POST['aauser'],$from_email);
        } elseif (isset($_GET['aapwd2'])) {
            [$key, $user] = explode('-',$_GET['aapwd2']);
            return AA_Password_Manager_Reader::getChangeForm($key, $user);
        } elseif (isset($_REQUEST['aapwd1'])) {        // Check User
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
        $from_email = $from_email ?: ERROR_REPORTING_EMAIL;
        return AA_Password_Manager_Reader::is_ok(AA_Password_Manager_Reader::askForMail($username, $reader_slice_id, $from_email)) ? '1' : 'err';
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
    static function doTrimParams() { return false; }

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
        // We first try with loadXML - it is less tolerant but more correct.
        // The loadHTML is too HTML specific (<link>xxx</link> tag in XML is parsed as always empty since in HTML is always empty)
        $doc = new DOMDocument();
        if (!$doc->loadXML( ($string = trim($string)), LIBXML_PARSEHUGE)) {
            warn('loadXML() failed - trying to use loadHTML()'. var_export(libxml_get_last_error(),true));
            if (!$doc->loadHTML($string)) {   // I tried to contentcached this load to save time on parsing document, but it had absolutely no effect - PHP 7.0 - Honza 2017-01-25
                warn('Not possible to parse XML - loadXML() and loadHTML failed.'. var_export(libxml_get_last_error(),true));
                return 'Error XML parsing: '. var_export(libxml_get_last_error(),true);
            }
        }
        $xpath = new DOMXPath($doc);

        $entries = $xpath->query($query);
        $ret = '';
        foreach ($entries as $entry) {
            if ($attr==='XML') {
                $ret.= $entry->ownerDocument->saveHTML($entry);
            } elseif ($attr)  {
                $ret .= $entry->attributes->getNamedItem($attr)->nodeValue;
            } else {
                $ret .= $entry->nodeValue;
            }
            if ($delimiter === 'AA_PrintJustFirst') {
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
 *   you can use named variables if you use json:
 *    {foreach:{"x":["val1","val2","val3"]}: * _#x :json:<br>}  // the alias _#x then reflects the key name "x" in the JSON
 *   the multilevel foreach is easier with named variables
 *    {foreach:{"x":["X1","X2","X3"]}:{(
 *      {foreach:{"y":["Y1","Y2"]}: * _#x, _#y :json:<br>}
 *    )}:json:}
 *   there is even easier way to write multilevel foreach - all arrays are in one JSON:
 *    {foreach:[["X1","X2","X3"],["Y1","Y2"]]: * _#loop0, _#loop1:json:<br>}   // _#loop0 _#loop1, ... is used as aliases if named variables are not used
 *   or with named variables
 *    {foreach:{"x":["X1","X2","X3"],"y":["Y1","Y2"],"zet":["ZET1","ZET2"]}: _#x - _#y - _#zet:json:, }
 */
class AA_Stringexpand_Foreach extends AA_Stringexpand {

    /** Do not trim all parameters ($outputdelimiter could begin with space) */
    static function doTrimParams() { return false; }

    // not needed right now for Nevercached functions, but who knows in the future

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
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

        $item   = $this?->item;
        if (!strlen($valdelimiter)) {
            $valdelimiter = '-';
        }
        $arr = ($valdelimiter === 'json') ? aa_json_decode(trim($values), true) : explode($valdelimiter, $values);
        $ret= [];
        // multidimensional array
        if (is_array($arr) AND is_array(current($arr))) {
            $combinations = $this->combine_arrays($arr);
            $aliases = array_map( static fn ($v) => '_#'.(is_numeric($v) ? "loop$v" : $v), array_keys($arr));
            foreach ($combinations as $addr) {
                $ret[] = AA::Un(str_replace($aliases,$addr,$text),'',$item);
            }
        } else {
            // single array
            $arr = array_filter(array_map('trim', $arr), 'strlen');
            $aliases = is_numeric(key($arr)) ? ['_#1', '_#loop'] : ['_#'.key($arr)];
            foreach ($arr as $str) {
                $ret[] = AA::Un(str_replace($aliases, $str, $text), '', $item);
            }
        }
        return implode($outputdelimiter, $ret);

        //$arr = array_filter(array_map('trim', $arr), 'strlen');
        //array_walk($arr, function(&$value, $key, $param) {
        //    $value = AA::Un(str_replace('_#1',$value,$param[1]),'',$param[0]);
        //}, array($item, $text));
        //return join($outputdelimiter, $arr);
    }

    /** Creates from [[a1,a2,a3],[b1,b2],...] list of all combinations -> [[a1,b1],[a1,b2],[a2,b1],[a2,b2],[a3,b1],[a3,b2]]
     * @param $arr
     * @return array
     */
    function combine_arrays($arr) {
        $addr =  array_filter(array_map('trim', array_shift($arr)), 'strlen');
        array_walk($addr, static function(&$val) { $val = [$val]; });

        foreach ($arr as $values) {
            $values = array_filter(array_map('trim', $values), 'strlen');
            $newaddr = [];
            foreach ($addr as $a) {
                foreach ( $values as $v ) {
                    $newaddr[] = array_merge($a, [$v]);
                }
            }
            $addr = $newaddr;
        }
        return $addr;
    }
}


/** Sends e-mail conditionally
 *  Be careful - it can send mail on every page load!
 *  Use as:
 *    {mail:1:honza.malik@ecn.cz:test mail:{view:24}:utf-8:actionapps@ecn.cz}
 *    {mail:1:7847785-435636-244355-434524:test mail:{view:24}:utf-8:ActionApps <actionapps@ecn.cz>}
 *          - Reader ids from related slices to current site (using queue and silently created templates) - Reader aliases could be used
 *    {mail:1:["test@ecn.cz,"info@ecn.cz"]:test mail:{view:24}:utf-8:actionapps@ecn.cz:actionapps@ecn.cz:actionapps@ecn.cz:actionapps@ecn.cz::https#://test.org/img/img1.png}
 *    {mail:1:test@ecn.cz:test mail:{view:24}:utf-8:actionapps@ecn.cz:::::["https#://test.org/img1.png","https#://test.org/img2.png"]}
 *    {mail:1:test@ecn.cz:test mail:{view:24}:utf-8:actionapps@ecn.cz:::::{"First.png":"https://test.org/img1.png","Second.png":"https://test.org/img2.png"}}  - renamed attachements
 *    {mail:1:test@ecn.cz:test mail:Hi, I am body of mail:utf-8:actionapps@ecn.cz:::::{@file............:json}}
 */
class AA_Stringexpand_Mail extends AA_Stringexpand_Nevercache {

    /**
     * @param string $condition
     * @param string $to           email, ["email1","email2"] or 36363..e2-451f23e..3a (Reader ids from related slices to current site - email in con_email....... field - FIELDID_EMAIL)
     * @param string $subject
     * @param string $body
     * @param string $lang
     * @param string $from        - sender e-mail actionapps@ecn.cz or ActionApps <actionapps@ecn.cz
     * @param string $reply_to
     * @param string $errors_to
     * @param string $sender
     * @param string $cc
     * @param string $bcc
     * @param string $attachments - single as string: "url1", multiple as json: ["url1",..], or as json with names {"name1":"url1",...}
     *
     * @return string
     */
    function expand($condition='', $to='', $subject='', $body='', $lang='', $from='', $reply_to='', $errors_to='', $sender='', $cc='', $bcc='', $attachments='') {

        if (!strlen($condition) OR !strlen($body) OR ((string)$condition==='0')) {
            return '';
        }

        // "to" could be list of e-mails or Reader ids
        if (str_contains($to, '@')) {
            // list of emails like honza.malik@ecn.cz or ["test@ecn.cz,"info@ecn.cz"]
            if (!($to = json2arr($to))) {
                warn('Empty "To:" in mail');
                return '';
            }
        } else {
            // "to" as item ids - filter ids by related modules
            $to = (new zids(explode_ids($to)))->itemFilter($this->getRelatedModules());
            if ($to->isEmpty()) {
                warn('No Readers to send mail - is the slice in the same site module?');
                return '';
            }
        }

        $cc  = implode(',',AA_Validate::filter(json2arr($cc), 'email'));
        $bcc = implode(',',AA_Validate::filter(json2arr($bcc), 'email'));

        $mail_arr = [
            'subject'     => $subject,
            'body'        => $body,
            'header_from' => $from,
            'reply_to'    => $reply_to,
            'errors_to'   => $errors_to,
            'sender'      => $sender,
            'lang'        => $lang,
            'html'        => 1,
            'cc'          => $cc,
            'bcc'         => $bcc,
            'attachments' => $attachments,
        ];

        // the path is different for Readers and direct mails
        if ($to instanceof zids) {  // Readers - we go for template
            $type  = (new AA_Logentry_MR(['type' =>'pg', 'page' =>AA::Un('{item:{xid}:short_id........}')]))->serializeToString();
            $descr = (new AA_Logentry_Mail2reader(['time' =>date("Y-m-d H.i.s"), 'readers' =>$to->count()]))->serializeToString();
            $owner = $this->getRelatedModules()[0] ?? '';
            return AA_Mail::sendToReader(AA_Mailtemplate::createFromMailArray($mail_arr, $type, $descr, $owner)->getId(), $to, $to->getFirstSlice());
        }

        // else - to definned as mails
        $mail = new AA_Mail;
        $mail->setFromArray($mail_arr);
        // AA_Log::write('DEBUG', aa_substr($subject, 0, 16), join('-', $to));
        return $mail->sendLater($to);
    }
}

/** Sends e-mail from template (of the same slice/site) - could be used inside mail notify (updated item is passed, so you can use aliases like _#ITEM_ID_...)
 *  Be careful - it can send mail on every page load!
 *  Use as:
 *    {mailtemplate:874:test@ecn.cz}
 *    {mailtemplate:874:["test@ecn.cz,"info@ecn.cz"]}
 *    {mailtemplate:{if1:{qss:send}:874}:test@ecn.cz}   // conditional sending
 */
class AA_Stringexpand_Mailtemplate extends AA_Stringexpand_Nevercache {

    /**
     * @param string $template e-mail template - must be from the same site. If empty, mail is not sent (use it as condition, if you want to send mail conditionally)
     * @param string $to
     * @return int|string|void
     */
    function expand($template='', $to='') {
        $template = ctype_digit((string)$template) ? (int)$template : 0;

        if (!$template) { return ''; }
        if (!($template_id = DB_AA::select1('id', 'SELECT `id` FROM `email`', [['id',$template,'i'], ['owner_module_id', $this->getRelatedModules(), 'l']]))) {
            warn("Mail template '$template' do not belongs to related slices");
            return '';
        }
        if (!($to = json2arr($to))) { return ''; }

        return AA_Mail::sendTemplate($template_id, $to, $this->item) ? '1' : '';
    }
}


/** Sends e-mail conditionally
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

        $config_arr = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'ok' => $ok,
            'lang' => $lang,
            'from' => $from,
        ];
        $form_id  = 'form'.new_id();
        $mailconf = AA_Stringexpand_Encrypt::get_time_token($config_arr);

        $ret = "<form id=\"$form_id\" onsubmit=\"AA_AjaxSendForm('$form_id', '".AA_INSTAL_PATH."/mail.php'); return false;\">
        <input type=hidden name=aa_mailconf value=\"$mailconf\">".
            StrExp('formantispam') .
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
        return '<style>
           div.aaskryto { display:none };
        </style>
        <div class="aaskryto"><input type="text" name="answer" value=""></div>';
    }
}

/** Sends Datovka message (CZ state massaging system)
 *  Be careful - it can send datovka message on every page load!
 *  Use as:
 *    {datovka:1:bkfj8cu:test massage:https#://test.org/doc/report.pdf}   - sends real message to user bkfj8cu on pdf named report.pdf
 *    {datovka:test:bkfj8cu:test massage:["https#://test.org/img1.png","https#://test.org/img2.png"]}  - sends test message ... with two attachments
 *    {datovka::bkfj8cu:test massage:["https#://test.org/img1.png","https#://test.org/img2.png"]}      - empty conf means do not send anything
 *    {datovka:1:bkfj8cu:test massage:{"Report 1":"https://test.org/doc/report.pdf","Second report":"https://test.org/rep2.docx"}} - with named attachments
 *    {datovka:1:bkfj8cu:test massage:{"Report 1":"https://test.org/doc/report.pdf"},{"dmToHands":"Honza Malik","dmRecipientRefNumber":"vcj. 253"}} - with other possible envelope parameters:
 *       dmToHands, dmRecipientRefNumber, dmRecipientIdent, dmRecipientOrgUnit, dmRecipientOrgUnitNum, dmSenderRefNumber, dmSenderIdent, dmSenderOrgUnit, dmSenderOrgUnitNum,
 *       dmLegalTitleLaw, dmLegalTitleYear, dmLegalTitleSect, dmLegalTitlePar, dmLegalTitlePoint, dmPersonalDelivery, dmAllowSubstDelivery
 *       description of the values dm* - https://info.mojedatovaschranka.cz/info/cs/80.html - WS_manipulace_s_datovymi_zpravami.pdf 2.1
 *  To see result of datovka in AA you can use {oitem:datovka:{oids:datovka:...}} - it will show you all datovka messages sent from this site (datovka object owner is current site module)
 */
class AA_Stringexpand_Datovka extends AA_Stringexpand_Nevercache {

    /**
     * @param  string  $conf         "" (- no send) | 1 (real send) | test (send to testing server) - required
     * @param  string  $to           - required
     * @param  string  $annotation   - required
     * @param  string  $attachments  - required
     * @param  string  $params
     * @param  string  $ret_url      - url - the parameter
     * @param  string  $related_to   - may be ItemId to identify the message
     * @return string
     * @throws Exception
     */
    function expand($conf='', $to='', $annotation='', $attachments='', $params='', $ret_url='', $related_to='') {
        // the massage is already sent and the return url is to this page - we just print result
        if (array_key_exists('datRes', $_GET)) {
            return $_GET['datRes'];
        }
        // datovka start
        if (!in_array($conf, ['1', 'test', 'standard']) or !strlen($to) or !strlen($annotation) or !strlen($attachments)) {
            return '';
        }
        $conf = $conf === 'test' ? 'test' : 'standard';
        $params = json2asoc($params);
        $ret_url = $ret_url ?: shtml_url(); // if not defined, use current page
        // huhl($params);
        $datovka = new Datovka($conf, $to, $annotation, $attachments, $params, $ret_url, AA::$module_id ?: AA::$site_id, $related_to);
        $datovka->save();
        $datovka->startWithLogin();
        return ''; // this should be reached
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
        $frames = [];
        $lis    = [];
        $zids = new zids(explode_ids($ids));
        if ( $zids->count() <= 0 ) {
            return '';
        }

        $interval   = (int)$interval ?: 3000;
        $extrastyle = ($effect === 'fade') ? 'position:absolute;' : '';
        $showfirst  = '';
        $div_id     = 'rot'.get_hash($ids, $code, $interval, $speed, $effect);

        $items = AA_Items::getItems($zids);
        $i = 0;
        foreach($items as $long_id=>$item) {
            $frame = trim(AA::Un($code, '', $item));
            if ($frame) {
                $frames[]  = "<div class=rot-hide style=\"$showfirst $extrastyle\">$frame</div>";
                $showfirst = 'display:none;';
                if ($li_code) {
                    $active = $i ? '' : ' active';
                    $lis[]  = "<li class=\"rot-active$active\" onclick=\"AA_Rotator.rotators['$div_id'].index=$i;AA_Rotator('$div_id'); \" onmouseover=\"AA_Rotator.rotators['$div_id'].index=$i;AA_Rotator('$div_id');\">".trim(AA::Un($li_code, '', $item))."</li>";
                }
            }
            ++$i;
        }
        if (!count($frames)) {
            return '';
        }

        $extrahightdiv = ($effect === 'fade') ? "<div class=rot-hight style=\"visibility:hidden\">$frame</div>" : '';
        $liswitcher    = $li_code ? "<ul class=\"rot-switcher\">".implode("\n",$lis)."</ul>" : '';

        $framesdiv = '<div class="rot-frames">'.implode("\n",$frames).'</div>';

        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_Rotator
        return "<div id=\"$div_id\" style=\"position:relative\">".$framesdiv.$extrahightdiv.$liswitcher."</div><script>AA_Rotator('$div_id', $interval, ".count($frames). ", '$speed', '$effect');</script>";
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
            if (!($item = new ItemContent($iid))->isEmpty()) {
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
 *                     of usage precounted. It is very good idea,to have such
 *                     field - in other case the counts must be countedon every
 *                     usage. The count could be counted automatically
 *                     in the field by using "Comuted field for INSERT/UPDATE"
 *                     with the parameter "_#BACKLINK:_#BACKLINK::day", where
 *                     alias _#BACKLINK could be definned as
 *                       {count:{backlinks:{id..............}::-}}
 *                     or say
 *                       {count:{ids:1450a615da76cae02493aac79e129da9:d-relation........-==-{id..............}}}
 *
 *  Usage:
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
 *    <style>
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
        $results     = [];

        $items = AA_Items::getItems(new zids(explode_ids($item_ids)));
        foreach($items as $long_id=>$item) {
            $results[$long_id] = $item->subst_alias($count_field);
        }
        arsort($results, SORT_NUMERIC);
        $ids   = array_keys($results);
        if (!($count = ($count ? min($count,count($ids)) : count($ids)))) {
            return '';
        }
        $weights = [];
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
 *    usage:  {file2text:{file............}}
 *             {convert:{file2text:{file............}}:utf-8:windows-1250}
 */
class AA_Stringexpand_File2text extends AA_Stringexpand_Nevercache {
    /**
     * @param null $url
     * @return string|void
     */
    function expand($url=null) {
        $out = [];
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
        return implode("\n",$out);
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
    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     * @return bool|string
     */
    function expand($field_id='', $index=0, $item_id='', $lang='') {
        $item = $item_id ? AA_Items::getItem(new zids($item_id)) : $this?->item;
        return is_object($item) ? $item->getval($field_id, (int)$index + ($lang ? Langs::getLangName2Num($lang) : 0)) : '';
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
        /** @var $form AA_Form */
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
        if (!is_object($item = $item_id ? AA_Items::getItem(new zids($item_id)) : $this?->item)) {
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
 *           - X - eXternal edit - the same as Edit, but it reloads all page for edit form
 *                     - used with <edit_code> parameter
 *           - B - back - used with <edit_code> parameter
 *           - F - file upload to first file...........x or img_upload.....x or img_src........x field
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
        $hash       = get_short_hash('manager', $slices, $field, $item_id, $code, $conds, $sort, $mode, $edit_code);
        $cmdvar     = 'aamng_'.$hash;
        
        $slices_arr = explode_ids($slices);

        $mode = $mode ?: 'ND'.($edit_code ? 'EB' : ''); // add here all possible options, which is ND currently
        $mode_arr = [];

        if ($mode[0] === '{') { // we define buttons using asociative array - {jsonasoc:N:Add item:D:Delete item}
            $mode_arr = array_trim_all(json2asoc($mode));
            if (array_key_exists('S', $mode_arr) AND !strlen($mode_arr['S'])) {
                $mode_arr['S'] = '&#x2261';
            }
            if (array_key_exists('D', $mode_arr) AND !strlen($mode_arr['D'])) {
                $mode_arr['D'] = '&#10005;';
            }
            if (array_key_exists('Dconfirm', $mode_arr) AND !strlen($mode_arr['Dconfirm'])) {
                $mode_arr['Dconfirm'] = _m('Are you sure you want to permanently DELETE this record?');
            }
            if (array_key_exists('N', $mode_arr) AND !strlen($mode_arr['N'])) {
                $mode_arr['N'] = '+ '. _m('new');
            }
            if (array_key_exists('E', $mode_arr) AND !strlen($mode_arr['E'])) {
                $mode_arr['E'] = _m('edit');
            }
            if (array_key_exists('X', $mode_arr) AND !strlen($mode_arr['X'])) {
                $mode_arr['X'] = _m('edit');
            }
            if (array_key_exists('B', $mode_arr) AND !strlen($mode_arr['B'])) {
                $mode_arr['B'] = _m('back');
            }
            if (array_key_exists('F', $mode_arr) AND !strlen($mode_arr['F'])) {
                $mode_arr['F'] = _m('drop files here or click to upload');
            }
        } else {
            if (str_contains($mode, 'S')) {
                $mode_arr['S'] = '&#x2261';
            }
            if (str_contains($mode, 'D')) {
                $mode_arr['D'] = '&#10005;';
                $mode_arr['Dconfirm'] = _m('Are you sure you want to permanently DELETE this record?');
            }
            if (str_contains($mode, 'N')) {
                $mode_arr['N'] = '+ '.  _m('new');
            }
            if (str_contains($mode, 'E')) {
                $mode_arr['E'] = _m('edit');
            }
            if (str_contains($mode, 'X')) {
                $mode_arr['X'] = _m('edit');
            }
            if (str_contains($mode, 'B')) {
                $mode_arr['B'] = _m('back');
            }
            if (str_contains($mode, 'F')) {
                $mode_arr['F'] = _m('drop files here or click to upload');
            }
        }

        $inner_code = '';
        $standard_mgr = true;
//      if ($edit_code AND (is_long_id($_GET['aaedit']) OR (is_long_id($_GET[$cmdvar])) )) {
        if ($edit_code AND is_long_id($_GET[$cmdvar])) {

            // @todo check slice of edited item to match $slices
            $inner_code = StrExp('item', [$_GET[$cmdvar], $edit_code]);
            $footer     = '';
            if ( $mode_arr['B'] ) {
                $footer .= $mode_arr['X'] ? '<footer><article><i class="ico back" onclick="history.back();">' . $mode_arr['B'] . '</i></article></footer>' 
                                          : '<footer><article><i class="ico back" onclick="AA_SiteEdit(\'\',\''.$cmdvar.'\');">' . $mode_arr['B'] . '</i></article></footer>';
            }
        } else {

            $item_id = strlen($item_id) ? $item_id : (($this AND $this->item) ? $this->item->getId() : null);

            if (!$sort) {
                $sort = 'publish_date....';
            }

            if ($field AND ($s = AA_Slice::getModule(reset($slices_arr))) AND $s->isField($field) AND strlen($item_id)) {
                $conds .= $conds ? "-$field-=-$item_id" : "d-$field-=-$item_id";
            }
            if (!$conds) {
                warn("No conditions");
                $inner_code .= "<!-- Err: manager - $field [first slice of: $slices,item: $item_id] -  No conditions -->";
                $zids = new zids();
            } else {
                // @todo check $slices to be from current site module
                $zids = (new AA_Set($slices_arr, $conds, $sort))->query();
            }
                
            // load all mentioned items in one step
            AA_Items::preload($zids);

            $code_actions  = $mode_arr['E'] ? ('<article><i class="ico edit" onclick="AA_SiteEdit(\'{id..............}\',\''.$cmdvar.'\');">' . $mode_arr['E'] . '</i></article>') : '';
            $code_actions .= $mode_arr['X'] ? ('<article><i class="ico edit" onclick="window.location=\'{go:xqs='.$cmdvar.'={id..............}}\';">' . $mode_arr['X'] . '</i></article>') : '';
            $code_actions .= $mode_arr['S'] ? ('<article><i class="ico setting" onclick="AA_ToolboxEdit(\'{id..............}\');">' . $mode_arr['S'] . '</i></article>') : '';
            $code_actions .= $mode_arr['D'] ? ('<article><i class="ico delete"  onclick="if (confirm(\'' . $mode_arr['Dconfirm'] . '\')) { document.querySelector(\'article[data-aa-part=&quot;' . $hash . '&quot;]\').classList.add(\'aa-updating\');  AA_SiteUpdate(\'{id..............}\',\'status_code.....\',3);}">' . $mode_arr['D'] . '</i></article>') : '';
            $footer  = $mode_arr['N'] ? ('<article><i class="ico new" onclick="document.querySelector(\'article[data-aa-part=&quot;' . $hash . '&quot;]\').classList.add(\'aa-updating\'); AA_SiteNewitem(\'' . $slices_arr[0] . '\',\'\',\'' . $field . '\',\'' . $item_id . '\');">' . $mode_arr['N'] . '</i></article>') : '';

            $code_delim = '_AA_C_DeLiM_'.gensalt(6);

            // expired, ... are already filtered by query() above (if expiry_date is not in conds)
            $tmp_code_arr = AA_Items::getFormatted($zids, "{$code}{$code_delim}{$code_actions}", AA_BIN_ALL);

            $standard_mgr = (!str_contains(reset($tmp_code_arr), '_#actions'));
            if ($standard_mgr) { // standard manager
                $inner_code = array_reduce($tmp_code_arr, static function ($ret, $tmp_code) use ($code_delim) {
                    [$code, $code_actions] = explode($code_delim, $tmp_code, 2);
                    return "{$ret}\n<article class=\"item aa-rim-items\">\n  <section class=iteminfo>\n    $code \n  </section>\n  <section class=icogroup>\n    $code_actions \n  </section>\n</article>";
                }, '');
            } else {
                // you can use _#actions in the CODE, and then your html template is used instead of predefined one - like
                $inner_code = array_reduce($tmp_code_arr, static function ($ret, $tmp_code) use ($code_delim) {
                    [$code, $code_actions] = explode($code_delim, $tmp_code, 2);
                    return "{$ret}\n<article class=\"item aa-rim-items\">". str_replace('_#actions', $code_actions, $code). "\n</article>";
                }, '');
            }

            if ($mode_arr['F']) {

                $input_name =  "aa[f_".reset($slices_arr)."][file][fil][var]";  // we are making it compatible with widget to easier file upload handling (rearangeAaFilesArray())
//              fallback would be nice, but it uses <form>, so we sometimes endup with <form> inside another <form>, which is not allowed. It is solveable (by dynamic form at the and of page...)
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

        $ret  = '<article class="aa-rim relactionitem'.($standard_mgr ? '' : ' aa-rim-tbl').'" data-aa-part="'.$hash.'">
                    <section class="itemgroup">'
            .$inner_code.'
                    </section>
                       '. ($footer ? "<footer>$footer</footer>" : '') .'
                 </article>';
        $ret .= "<script>$dz_setup_code</script>";

        AA::Stringexpander()->setDependentParts(array_merge($slices_arr, [$cmdvar]), $hash, $ret);  // array_merge($slices_arr, ['aaedit']), $hash, $ret
        AA::Stringexpander()->addRequire('css-aa-system');
        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_SiteNewitem, ...
        return $ret;
    }
}

/** Class AA_Stringexpand_Partdiv
 *  usage as:  {partdiv:mysite{id..............}:["aaedit","{_#SLICE_ID}"]:<article> ... </article>}
 */
class AA_Stringexpand_Partdiv extends AA_Stringexpand {
    /**
     * @param string $name unique name of the part on the page (if not provided, the partdiv feature is not used)
     * @param string $vars slices and QS variables on which the part depends (we need to repaint the part if the content of the slice or QS variable is changed)
     * @param string $content printer content
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
 *  usage: {tr:Send}
 *         {tr:found _#1 items:5} - you can use parameters _#1, _#2...
 */
class AA_Stringexpand_Tr extends AA_Stringexpand {

    /** expand function
     * @param $text
     * @return mixed|string
     */
    function expand(...$arg_list) {
        $text = array_shift($arg_list);
        if (!is_null($text) AND strlen($text)) {
            if ($site = AA_Module_Site::getModule(AA::$site_id)) {
                if ($translate_slice_id = $site->getProperty('translate_slice')) {
                    $set  = new AA_Set([$translate_slice_id], new AA_Condition('headline........', '==', $text));
                    $zids = $set->query();

                    if ($zids->count()) {
                        return AA_Items::getItem($zids[0])->f_2('headline........');
                    }
                    // if not present - translate to default language of the slice

                    $translate_slice = AA_Slice::getModule($translate_slice_id);
                    if ($translate_slice->isValid()) {  // could be already deleted slice
                        $translations = $translate_slice->getProperty('translations');
                        $html_flag    = $translate_slice->getField('headline........')->getDefaultFormatterFlag(); // get the flag

                        $ic = new ItemContent();
                        $ic->setAaValue('headline........', new AA_Value([Langs::getLangName2Num($translations[0]) => $text], $html_flag));
                        $ic->setSliceID($translate_slice_id);
                        //$ic->complete4Insert();

                        $ic->storeItem('insert');     // invalidatecache, feed
                    }
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

/** better than {xlang} - returns always valid lang code - not only in sitemodule */
class AA_Stringexpand_Aalang extends AA_Stringexpand_Nevercache {

    /** expand function */
    function expand() {
        return AA::$lang ?: strtolower(aa_substr(DEFAULT_LANG_INCLUDE,0,2));
    }
}

/** Validate */
class AA_Stringexpand_Validate extends AA_Stringexpand {

    /** additionalCacheParam function
     * @param array $params parameters passed to expand (caching could be parameter sensitive).
     * @return string - for not cache, return random value
     */
    function additionalCacheParam(array $params= []) {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     * @return string
     */
    function expand() {
        // $item = $item_id ? AA_Items::getItem(new zids($item_id)) : ($this ? $this->item : null);
        if (!($item = $this?->item)) {
            return '';
        }
        $valid = $item->getItemContent()->validateReport('all');
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
 * usage:
 * {require:aa-jslib}
 * {require:aa-jslib@2}
 * {require:tippy:tippy('footer', {content: 'call <b>+420 111 222 333</b>.'})}  - run script after load of library
 * {require:/css/style.css}
 * {require:/css/style.css print} - media print css
 * {require:["/css/common.css", "https://www.example.org/flex.css"]}
 * {require:https#://cdn.jsdelivr.net/npm/@iconfu/svg-inject@1.2.3/dist/svg-inject.min.js sha256-ri1AEoNtgONXOIJ0k7p9HoQHGq6MEDsjPPYZh7NWpu0=} - js with SRI integrity
 * {require:/js/myscript.js defer} - js with defer
 * {require:/js/myscript.js defer sha256-ri1AEoNtgONXOIJ0k7p9HoQHGq6MEDsjPPYZh7NWpu0=} - js with defer and SRI integrity
 * {require::document.querySelector('#mydiv').innerHTML='Hi';}
 */
class AA_Stringexpand_Require extends AA_Stringexpand {
    /** expand function
     * @param string $libs
     * @param string $script
     * @param string $position   '' | 'FOOT'  - require as foot library
     * @return string
     */
    function expand($libs='', $script='', $position='') {
        $l = json2arr($libs);
        $pos = ($position==="FOOT") ? 'AA_Req_Footlib' : '';
        foreach($l as $lib) {
            AA::Stringexpander()->addRequire($lib, $pos);
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
//class AA_Stringexpand_Post_Replace extends AA_Stringexpand_Nevercache {      // regexp - like {replace}
class AA_Stringexpand_Post_Str_Replace extends AA_Stringexpand_Nevercachenotrim {  // stringreplace - like {str_replace}
//class AA_Stringexpand_Post_Insert extends AA_Stringexpand_Nevercache {  // {post_insert:needle in output:code to insert if needle is found in the output}
//class AA_Stringexpand_Post_Insert extends AA_Stringexpand_Nevercache {  // {post_insert:/needle in output/:code to insert if REGEXP needle is found in the output}

    // no reason to cache it - it is quick processing function
    /** Do not trim all parameters ($search and $replace could be spaces) */

    /** expand function
     * @param $search
     * @param $replace
     * @return string
     */
    function expand($search='', $replace='') {
        // not used yet - if you need it, just uncomment it and send it to SVN (I want to have free hands to adapt it for the first real usage (REGEXP? ...) )
        // AA::Stringexpander()->addPostpocess($search, $replace);
        return '';
    }
}

/** Creates "Export to XLSX" button on the table specified by selector:
 *    {tableexport:.xlsexportable}
 *  All the JS libraries are automatically loaded
 *  You must be using {generate:HEAD} and {generate:FOOT} in your site module!
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
            $script2run = "TableExport.prototype.typeConfig.date.assert = function(value){return false;}; \n";  // do not convert cells to date (there are many mistakes in the date cells
            $script2run .= "TableExport(document.querySelectorAll('$selector'),{ formats: ['xlsx'], position: 'bottom'}); \n";
            AA::Stringexpander()->addRequire($script2run, 'AA_Req_Load');
        }
        return '';
    }
}

/** Creates Datatable form tables matching the $selector (.datatable) by default
 *    {datatable:<selector>:<options>}
 *    {datatable}, {datatable:#mytable}, {datatable:.datatable:{ paging:false, info:false }}
 *  All the JS libraries are automatically loaded
 *  You must be using {generate:HEAD} and {generate:FOOT} in your site module!
 *  Experimental - not able to sort czech and other characters by default (v 1.10.20)
 */
class AA_Stringexpand_Datatable extends AA_Stringexpand {
    /** expand function
     * @param $selector
     * @param $options string JSON - see  
     * @return string
     */
    function expand($selector='', $options='') {
        if (!strlen($selector)) {
            $selector = '.datatable';
        }
        AA::Stringexpander()->addRequire('datatable');
        $script2run = "$('$selector').DataTable($options);\n";
        AA::Stringexpander()->addRequire($script2run, 'AA_Req_Load');
        return '';
    }
}

/** Creates Sortable table matching the $selector (table.sortable by default)
 *    {sortable}, {sortable:#mytable}, {sortable:table.sortable} (default)
 *  All the JS libraries are automatically loaded
 *  You must be using {generate:HEAD} and {generate:FOOT} in your site module
 */
class AA_Stringexpand_Sortable extends AA_Stringexpand {
    /** expand function
     * @param $selector
     * @param $options string JSON - see
     * @return string
     */
    function expand($selector='', $options='') {
        if (!strlen($selector)) {
            $selector = 'table.sortable';
        }
        AA::Stringexpander()->addRequire('sortable');
        $script2run = "SortableTable.find('$selector');\n";
        AA::Stringexpander()->addRequire($script2run, 'AA_Req_Load');
        return '';
    }
}

/** Adds EU cookies message to the page
 *  needs {generate:HEAD} and {generate:FOOT} in the site module
 *  doc: https://github.com/honzito/smart-eu-cookies/tree/master#readme
 *  {eucookies:This website uses cookies:OK:more://www.example.cz/cz/cookies-strategy::No:top}
 *  {eucookies:::::showMyAdds}
 *    <script> function loadMyAdds(action, label) { if (action==='hide') { console.log(action, label);} } </script>
 *  <type> = top | block | topblock
 */
class AA_Stringexpand_Eucookies extends AA_Stringexpand {
    /** expand function
     * @param $selector
     * @return string
     */
    function expand($text='', $accept='', $more='', $link='', $callback='', $disagree='', $type='') {
        $script2run = '';
        $conf       = [];
        if (strlen($text))     { $conf['text']     = $text;   }
        if (strlen($accept))   { $conf['accept']   = $accept; }
        if (strlen($disagree)) { $conf['disagree'] = $disagree; }
        if (strlen($more))     { $conf['more']     = $more;   }
        if (strlen($link))     { $conf['link']     = $link;   }

        $confstr = $conf ? '"l18n":'.json_encode($conf) : '';
        if (strlen($callback)) {
            $confstr .= ($confstr ? ',':''). '"options":{"callback":'.$callback.'}';
        }
        if ($confstr) {
            $script2run .= '<script> var smart_eu_config = {'.$confstr.'}; </script>';
        }
        $css_add  = str_contains($type, 'top') ? '.smart-eu-cookies {position:relative}' : '';
        $css_add .= str_contains($type, 'block') ? '.smart-eu-cookies span {flex: 1 0 100%}' : '';
        if ($css_add) {
            $script2run .= "<style>$css_add</style>";
        }
        //$script2run .= "\n<script async src=\"https://cdn.jsdelivr.net/npm/eu-cookie-law@1.0.0/dist/index.min.js\" integrity=\"sha384-Aqd1v6PXDXY8z7z5qprmEjjFMZhqAMJCovJg2HrDMhBxYpALNSDVy5htLWzMO3fJ\" crossorigin=anonymous></script>";
        //$script2run .= "\n<script async src=\"https://cdn.jsdelivr.net/gh/honzito/smart-eu-cookies@0.9.0/src/smart-eu-cookies.min.js\" integrity=\"sha384-UduDV9uWMKOfKT56AR+4zzHYTkpPT7c801TAJ7DWXsU6QzwNxMCPq+cE1H0QNDDc\" crossorigin=anonymous></script>";
        AA::Stringexpander()->addRequire($script2run, 'AA_Req_Footcode');
        AA::Stringexpander()->addRequire('eucookies');
        return '';
    }
}

/** Creates
 *    {tooltip:myinfoicon:<b>My tip</b><br>tip text}     // myinfoicon is ID of element
 *    {tooltip:#myinfoicon:<b>My tip</b><br>tip text}    // the same as above
 *    {tooltip:.myelements:<b>My tip</b><br>tip text}    // myelements is any selector
 *  All the JS libraries are automatically loaded
 *  You have to use {generate:HEAD} and {generate:FOOT} in your site module!
 *  We may replace the JS by another with the same functionality in the future,
 *  so do not count on the exact library in the code. The {tooltip} code
 *  will remain functional, however
 */
class AA_Stringexpand_Tooltip extends AA_Stringexpand {
    /** expand function
     * @param string $element
     * @param string $html
     * @return string
     */
    function expand($element='', $html='') {
        if ($element AND $html) {
            AA::Stringexpander()->addRequire('tippy');
            $selector = preg_match('/[^A-Za-z0-9_-]/', $element) ? $element : '#'.$element;
            $script2run = "tippy('".escape4js($selector)."', {content: '".escape4js($html)."', allowHTML:true, delay: 50, size:\"small\", duration: 50, theme: \"aa\", interactive: true}); \n";
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
  tippy.setDefaultProps({allowHTML:true,duration:50, theme: "aa", interactive: true, maxWidth:"50vw"});
</script>
<style>
    .tippy-box[data-theme~=\'aa\'] {
        color: black;
        background-color: #FFFED8;
        box-shadow: 0 0 20px 4px rgba(154, 161, 177, 0.15), 0 4px 80px -8px rgba(36, 40, 47, 0.25), 0 4px 4px -2px rgba(91, 94, 105, 0.15);
        border: 1px solid rgba(80, 80, 80, 0.5);
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
            if ($detect->isTablet()) return 'tablet';
            if ($detect->isMobile()) return 'mobile';  // must be after teblet test - isMobile() is true for mobile and tablets
            return 'desktop';
        }
        return '';
    }
}


/**
 *
 */
class AA_Stringexpand_Aadocumentation extends AA_Stringexpand {
// @todo - experimental - not finished

    /** expand function
     */
    function expand($stringexpand='') {
        if (class_exists($class_name = AA_Serializable::constructClassName($stringexpand, 'AA_Stringexpand_'))) {
            $comment_string= (new ReflectionClass($class_name))->getDocComment();
            $explode_string= (new ReflectionClass($class_name))->getMethod('expand')->getDocComment();
        }
        $pattern = "#@([a-zA-Z]+)\s*(.*)#";

        $doc = array_merge(self::parseDoc($comment_string), self::parseDoc($explode_string));

        $xml = new SimpleXMLElement("<cmd name=\"$stringexpand\"></cmd>");
        foreach  ($doc as $docpart) {
            $xml->addChild($docpart[0], $docpart[1]);
        }
        $xml_with_header = $xml->asXML();
        return substr($xml_with_header, strpos($xml_with_header, '?'.'>') + 2);
    }

    static function parseDoc($string) {
        $ret = [];
        // remove initial /**, *, */
        preg_match_all("#\n\s*/?[*]+/?\s*(.*)#", "\n".$string, $matches);
        $string = implode("\n", array_map( static fn ($s) => ltrim($s,'* \t'), $matches[1]));
        $blocks = explode("\n@", $string);
        $blocks[0] = 'text '.$blocks[0];
        foreach ($blocks as $block) {
            preg_match("#^([a-zA-Z]+)\s*(.*)#sm", $block, $matches);
            $tag  = trim($matches[1]);
            $text = trim($matches[2]);
            if ($tag AND $text) {
                $ret[] = [$tag, $text];
            }
        }
        return $ret;
    }

}

/** {go:<query-string>}
 *  @return string url based on current state (apc) and query-string paramater
 *  Usage:
 *     {go:xseo1=faq&xseo2=questions}
 *     {go:xqs=}?year=2023     // remove query string part and add year=2013
 *     {go:xqs_year=2023}      // replace query string year variable with new value (other qs variables are preserved)
 *
 *  (we used to use {go2url} custom function in previous versions of AA. This function
 *  is however core function)
 */
class AA_Stringexpand_Go extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    /** expand function */
    function expand($query_string='',$html_encode='') {
        $router_class = $GLOBALS['apc_state']['router'];
        if (empty($router_class)) {
            return '<div class="aa-error">Err in {go} - router not found - {go} is designed for site modules</div>';
        }
        $router = AA_Router::singleton($router_class);
        $url = $router->go2url($query_string);
        // remove all internal system AA links
        if (!str_contains($query_string, 'err=')) {
            $url = str_replace(['&err=1','?err=1&','?err=1'], ['','?',''], $url);

        }
        if (!str_contains($query_string, 'logout=')) {
            $url = str_replace(['&logout=1','?logout=1&','?logout=1'], ['','?',''], $url);

        }
        return ($html_encode=='1') ? safe($url) : $url;
    }
}
