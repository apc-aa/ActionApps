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

require_once AA_INC_PATH."easy_scroller.php3";
require_once AA_INC_PATH."slice.class.php3";
require_once AA_INC_PATH."perm_core.php3";    // needed for GetAuthData();
require_once AA_INC_PATH."files.class.php3";  // file wrapper for {include};
require_once AA_INC_PATH."tree.class.php3";   // for {tree:...};

class AA_Aliasfunc extends AA_Object {

    var $alias;
    var $code;
    var $desc;
    var $ussage;
    // var $params;

    /** AA_Aliasfunc function
     * @param $alias;
     * @param $code;
     * @param $desc;
     * @param $ussage;
     */
    function AA_Aliasfunc($alias, $code, $desc, $ussage) {
        $this->alias  = $alias;
        $this->code   = $code;
        $this->desc   = $desc;
        $this->ussage = $ussage;
    }

    /** allows storing form in database
     *  AA_Object's method
     */
    static function getClassProperties() {
        return array (          //           id       name       type        multi  persist validator, required, help, morehelp, example
            'alias'  => new AA_Property( 'alias',   _m("Alias"),         'string', false, true, '', true,  _m('Alias will be called as {_:&lt;Alias_name&gt;[:&lt;Possible parameters - colon separated&gt;]}'),'', 'Message_box'),
            'code'   => new AA_Property( 'code',   _m("Code"),           'text',   false, true, '', true,  _m('Code printed by the alias. Alias could have parameters and you can use it by _#P1, _#P2, ... variables'),'', '&lt;div class=mybox style="color:_#P2"&gt;_#P1&lt;/div&gt;'),
            'desc'   => new AA_Property( 'desc',   _m("Description"),    'text',   false, true, '', false),
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
if (ini_get('pcre.backtrack_limit') < 1000000) {
    ini_set('pcre.backtrack_limit', 1000000);
}

/** creates array form JSON array or returns single value array if not valid json */
function json2arr($string, $do_not_filter=false) {
    if ($string[0] == '[') {
        if ( ($values = json_decode($string)) == null) {
            if (substr($string, -1)==']' AND (json_last_error() == JSON_ERROR_UTF8)) {
                // kind of hack - decode JSON for non UTF-8 charsets
                // the JSON must be in this exact form: ["val1","val2",...]
                // could be solved also by iconv...
                $values = explode('","', trim($string,'"[]'));
            } else {
                $values = ($string=='[]') ? array() : array($string);
            }
        }
    } else {
        $values = array($string);
    }
    return $do_not_filter ? $values : array_filter($values, 'strlen');  // strlen in order we do not remove "0"
}


/** include file, first parameter is filename, second is hints on where to find it **/
class AA_Stringexpand_Switch extends AA_Stringexpand_Nevercache {

    /** redefine parsexpand - we can't use the standard function - there is problem with:
    *  {switch({text............}).:OK} if the text.. contain ')' - we do not know, where the parameters are separated
    */
    function parsexpand($params) {
        if (empty($params)) {
            $param = array();
        } else {
            list($condition,$rest) = explode(')', $params, 2);
            $param = array_map('DeQuoteColons', array_merge(array($condition), ParamExplode($rest)));
        }
        return call_user_func_array( array($this,'expand'), $param);
    }

    /** expand function
     * @param $fn first parameter is filename, second is hints on where to find it
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
            if ( ($val=="") OR ereg($val, $condition) ) {    // Note that $string, might be expanded {headline.......} or {m}
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
    /** additionalCacheParam function
     *
     */
    function additionalCacheParam() {
        return serialize(array($GLOBALS['auth_user_info'], $GLOBALS['auth']));
    }
    /** expand function
     * @param $field
     */
    function expand($field='') {
        global $auth_user_info, $cache_nostore, $auth, $perms_roles;
        // this GLOBAL :-( variable is message for pagecache to NOT store views (or
        // slices), where we use {user:xxx} alias, into cache (AUTH_USER is not in
        // cache's keyString.
        // $auth_user_info caches values about auth user
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
                if ( !isset($auth_user_info[$auth_user]) ) {
                    $auth_user_info[$auth_user] = GetAuthData();
                }
                $item_user = GetItemFromContent($auth_user_info[$auth_user]);
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

/** Returns name or other info about user (usable for posted_by, edited_by, ...)
 *   {userinfo:<user>[:<property>]}
 *   @param $user - user as stored in posted_by....... or edited_by....... field
 *   @param $property - 'name' at this moment only, which is default
 */
class AA_Stringexpand_Userinfo extends AA_Stringexpand {

    /** expand function
     * @param $user
     */
    function expand($user='') {
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
        global $contentcache;
        $arg_list = func_get_args();   // must be asssigned to the variable
        // replace inputform field
        // destroy all aliases, since the content of the variables could contain
        // aliases, but we don't want to unalias them. The _AA_ReMoVe_ string
        // will be removed in dequteColons
        return str_replace('_#','__AA_ReMoVe#', $contentcache->get('inputvar:'. join(':',$arg_list)));
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

    function expand($name='', $expression='') {

        if (!($name = trim($name))) {
            return '';
        }
        global $contentcache;
        $contentcache->set("define:$name", $expression);
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

    function expand($name='') {
        global $contentcache;
        // replace inputform field
        $expression = $contentcache->get("define:$name");
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
        parent::expand($part_names);
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
        parent::expand($part_names);
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
     */
    function expand($user_id='', $action='add', $style=1) {
        if ( !$user_id ) {
            return "";
        }
        $user_id = urlencode($user_id);
        $action  = urlencode($action);
        $style   = (int)$style;

        // set the url to the image and the stype of the image
        $image = '<img src="http://status.icq.com/online.gif?icq='.$user_id.'&img='.$style.'" border="0">' ;
        // start the rendering the html outupt
        $output .= '<a href="http://www.icq.com/people/cmd.php?uin='.$user_id.'&action='.$action.'">'.$image.'</a>';
        // send the output to MediaWiki
        return $output;
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
        $output .= '<script type="text/javascript" src="http://download.skype.com/share/skypebuttons/js/skypeCheck.js"></script>';
        $output .= '<a href="skype:'.$user_skype_name.'?'.$action.'"><img src="http://mystatus.skype.com/'.$style.'/'.$user_skype_name.'" style="border: none;" alt="'.$message.'" title="'.$message.'" /></a>';
        $output .= '<!-- end of skype button -->';

        return $output;
    }
}


/** Expands {yahoo:<yahoo_name>[:<action>[:<style>]]} and displays YAHOO status for
 *  the user.
 *  @param $user_id  - yahoo name of the user
 *  @param $action           - addfriend | call | sendim
 *  @param $style            - 0-4 - dysplayed icon type
 *                   - see: http://messenger.yahoo.com/messenger/help/online.html
 */
class AA_Stringexpand_Yahoo extends AA_Stringexpand {
    /** expand function
     * @param $user_id
     * @param $action
     * @param $style
     */
    function expand($user_id='', $action='sendim', $style='2') {

        if ( !$user_id ) {
            return "";
        }

        // set your defaults for the style and action (addfriend, call or sendim) (0, 1, 2, 3 and 4)

        $action_default = "sendim" ;
        $style_default = "2" ;

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

        // start the rendering the html outupt
        $output .= '<a href="ymsgr:'.$action.'?'.$user_id.'">'.$image.'</a>';

        // send the output to MediaWiki
        return $output;
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
     * @param $url
     */
    function expand($url='') {
        return !$url ? '' : '<iframe src="http://www.facebook.com/plugins/like.php?href='.urlencode($url).'&amp;send=false&amp;layout=button_count&amp;width=120&amp;show_faces=false&amp;action=like&amp;colorscheme=light&amp;font&amp;height=21" style="border: medium none; overflow: hidden; width: 120px; height: 21px;" allowtransparency="true" frameborder="0" scrolling="no"></iframe>';
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
        $linkpart    = explode('@', $email);
        $mailprotect = "'".$linkpart[0]."'+'@'+'".$linkpart[1]."'";
        $linktext    = ($text=='') ? $mailprotect : "'".str_replace("'", "\'", $text)."'";
        $ret = "<script type=\"text/javascript\">document.write('<a href=\"mai'+'lto:'+$mailprotect+'\">'+$linktext+'</a>')</script>";
        return $ret;
    }
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
     */
    function expand($format='j. n. Y', $slice_id='') {
        $where    = '';
        if ($slice_id AND (guesstype($slice_id) == 'l')) {
            $where = "WHERE slice_id='".q_pack_id($slice_id)."'";
        }
        $db  = getDB();
        $SQL = "SELECT last_edit FROM item $where ORDER BY last_edit DESC LIMIT 0,1";
        $db->tquery($SQL);
        // timestamp
        $lastedit = $db->next_record() ? $db->f("last_edit") : 0;
        freeDB($db);
        return date($format,$lastedit);
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
 *  The /javscript/aajslib.php3 shoud be included to the page
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

    function expand($switch_state_1, $code_1, $switch_state_2, $code_2, $position='', $persistent_id='') {

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
        return (trim($position)=='bottom') ?  $ret. $link. $script: $link. $ret. $script;
    }
}

/** Expands {htmltogglecss:<toggle1>:<toggle2>:<css_rule>} like:
 *          {htmltogglecss:+:-:#id_#SITEM_ID}    (#id_#SITEM_ID should have style="display:none;" as default)
 *          {htmltogglecss:+:-:#id_#SITEM_ID:1}
 *  It creates the link text1 (or text2) +/- toggle which displays/hides all
 *  elements matching the css_rule (#id82422) in our example
 *  The /javscript/aajslib.php3 shoud be included to the page
 *  (by <script src="">)
 *  @param $switch_state_1 - default link text
 *  @param $switch_state_2 - link text 2
 *  @param $css_rule       - css rule matching the element(s) to show/hide
 *                         - '#id82422', '.details', '#my-page div.details'
 *  @param $is_on          - 1 if the code is on as default (default is 0)
 */
class AA_Stringexpand_Htmltogglecss extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    function expand($switch_state_1, $switch_state_2, $css_rule, $is_on=null) {

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
        return $ret;
    }
}

/** Expands {htmlajaxtogglecss:<toggle1>:<toggle2>:<css_rule_hide>:<url_of_text>[:<css_rule_update>]} like:
 *          {htmlajaxtogglecss:+:-:#id_#SITEM_ID:/apc-aa/view.php3?vid=33&cmd[33]=x-33-{_#SITEM_ID}}
 *  It creates the link text1 (or text2) +/- toggle which loads+displays/hides all
 *  elements matching the css_rule (#id82422) in our example
 *  The /javscript/aajslib.php3 shoud be included to the page
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

    function expand($switch_state_1, $switch_state_2, $css_rule_hide, $url_of_text, $css_rule_update='') {

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
        if ((strpos($url_of_text,'/view.php3?') !== false) AND (strpos($url_of_text,'convert')===false)) {
            $url_of_text = get_url($url_of_text,array('convertto' => 'utf-8'));
        }

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $ret   = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlAjaxToggleCss('toggle_link_$uniqid', '{$switches_js[0]}', '{$switches_js[1]}', '$css_rule_hide', '$url_of_text', '$css_rule_update');return false;\">{$switches[0]}</a>\n";
        return $ret;
    }
}

/** Expands {shorten:<text>:<length>[:<mode>[:add]]} like:
 *          {shorten:{abstract.......1}:150}
 *  @return up to <length> characters from the <text>. If the <mode> is 1
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

    /** Do not trim all parameters (the $add parameter could contain space) */
    function doTrimParams() { return false; }

    function expand($text, $length, $mode=2, $add='') {
        $mode   = (int)$mode;
        $length = (int)$length;
        if (strlen($text) <= $length) {
            return $text;
        }
        $shorted_text = substr($text, 0, $length);
        $shorted_len  = strlen($shorted_text);
        $text_add     = $add;
        if ($mode==2) {
            // do not try to find end of paragraph for short texts by default
            $mode = ($length >= 50) ? 1 : 0;
        }

        // search the text for following ocurrences in the order!
        $PARAGRAPH_ENDS = array( '</p>','<p>');
        if ($mode == 3) {
            $text = strip_tags($text);
            $ret  = substr($text, 0, $length/2-1).'...';
            return $ret. substr($text, strlen($ret)-$length). $text_add;
        }

        if ($mode == 1) {
            foreach ( $PARAGRAPH_ENDS as $end_str ) {
                $paraend = strpos(strtolower($shorted_text), $end_str, min($shorted_len,10));  // we do not want to
                if ( $paraend !== false ) {   // end_str found
                    $shorted_text = substr($shorted_text, 0, $paraend);
                    break;
                }
            }
            if ($paraend===false) {      // no <BR>, <P>, ... found
                // try to find dot (first from the end)
                $PARAGRAPH_ENDS = array( '<br>', '.', "\n", "\r", ' ');
                foreach ( $PARAGRAPH_ENDS as $end_str ) {
                    if ( ($dot = strrpos( $shorted_text, $end_str)) > ($shorted_len/2) ) { // take at least one half of text
                        $shorted_text = substr($shorted_text, 0, $dot+1);
                        break;
                    }
                } // no dot, no space - leave the text length long
            }
        }
        return strip_tags( $shorted_text ) . $text_add;
    }
}

/** Expands {expandable:<text>:<length>:<add>:<more>:<less>} like:
 *          {expandable:some long text:50:...:more >>>:less <<<}
 *  It creates the div and if the text is longer than <length> characters, then
 *  it adds <more> DHTML link in order user can see all the text
 *  The /javscript/aajslib.php3 shoud be included to the page
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

    function expand($text, $length=49, $add='', $switch_state_1='', $switch_state_2='') {
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
            $text_2 = AA_Stringexpand_Shorten::expand($text, $length);
            $link_1 = "<a class=\"expandablelink\" id=\"expandable_link1_$uniqid\" href=\"#\" onclick=\"AA_HtmlToggle('expandable_link1_$uniqid', '', 'expandable_1_$uniqid', '{$switches_js[0]}', 'expandable_2_$uniqid');return false;\">{$switches[0]}</a>\n";
            $link_2 = !$switches[1] ? '' : "<a class=\"expandablelink\" id=\"expandable_link2_$uniqid\" href=\"#\" onclick=\"AA_HtmlToggle('expandable_link2_$uniqid', '', 'expandable_2_$uniqid', '{$switches_js[1]}', 'expandable_1_$uniqid');return false;\">{$switches[1]}</a>\n";
            $ret    = "<div class=\"expandableclass\" id=\"expandable_1_$uniqid\">$text_2".$add." $link_1</div>\n";
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
 *  The /apc-aa/javascript/aajslib.php3 shoud be included to the page
 *  (by <script src="">)
 *  @param $switch_state_1 - default link text
 *  @param $code_1         - HTML code displayed as default (in div)
 *  @param $switch_state_2 - link text 2
 *  @param $url_of_text2   - url, which will be called by AJAX and displayed
 *                           on demand (click on the link)
 *  @param $position       - position of the link - top|bottom (top is default)
 */
class AA_Stringexpand_Htmlajaxtoggle extends AA_Stringexpand {

    function expand($switch_state_1, $code_1, $switch_state_2, $url, $position=null) {

        if ($switch_state_1.$switch_state_2 == '') {
            $switch_state_1 = '[+]';
            $switch_state_2 = '[-]';
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switches    = str_replace(array('[+]','[-]'), array(GetAAImage('plus.gif',  _m('show'), 15, 9), GetAAImage('minus.gif', _m('hide'), 15, 9)), array($switch_state_1, $switch_state_2));
        $switches_js = str_replace(array("'", '"', "\n", "\r"), array("\'", "\'", ' ', ' '), $switches);

        // automaticaly add conversion to utf-8 for AA view.php3 calls
        if ((strpos($url,'/view.php3?') !== false) AND (strpos($url,'convert')===false)) {
            $url = get_url($url,array('convertto' => 'utf-8'));
        }

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $link   = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlAjaxToggle('toggle_link_$uniqid', '{$switches_js[0]}', 'toggle_1_$uniqid', '{$switches_js[1]}', 'toggle_2_$uniqid', '$url');return false;\">{$switches[0]}</a>\n";
        $ret    = "<div class=\"toggleclass\" id=\"toggle_1_$uniqid\">$code_1</div>\n";
        $ret   .= "<div class=\"toggleclass\" id=\"toggle_2_$uniqid\" style=\"display:none;\"></div>\n";
        return (trim($position)=='bottom') ?  $ret. $link : $link. $ret;
    }
}

function calculate($exp) {
    $exp = str_replace(array(' ',"\t", "\r", "\n", ',', '(+', '(-', '(*', '(/', '(%', '+)', '-)', '*)', '/)', '%)', '()') ,array('', '', '', '', '.', '(0+', '(0-', '(0*', '(0/', '(0%', '+0)', '-0)', '*0)', '/0)', '%0)', '0'), "($exp)");
    if (strspn($exp, '0123456789.+-*/%()') != strlen($exp)) {
        return 'wrong characters';
    }
    $ret = @eval("return $exp;");
    return ($ret===false) ? "Math Err: $exp" : $ret;
}

// text = [ decimals [ # dec_point [ thousands_sep ]]] )
/** parseMath function
 * @param $text
 */
function parseMath($text) {
    // get format string, need to add and remove // to
    // allow for empty string

    $variable = substr(strtok("#".$text,")"),1);
    $twos     = ParamExplode( strtok("") );
    $i        = 0;
    $key      = true;

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
 * @param $item
 */
function parseLoop($out, &$item) {

    if ( !is_object($item) ) {
        return '';
    }

    // alternative syntax {@field...} or {list:field...}
    if ( (substr($out,0,5) == "list:") ) {
        $out = '@'. substr($out,5);
    }

    // @field........... - without parameters
    if (strpos($out, ":") === false) {
        $field = substr($out, 1);
        $separator = ", "; // default separator
    } else { // with parameters
        // get field name
        $field = substr($out, 1, strpos($out, ":") - strpos($out, "@")-1);
        // parameters - first is separator, second is format string
        list($separator,$format_str) = ParamExplode(substr($out,strpos($out,":")+1));

        if (strpos($field, "(") == true) { // if we have special parameters - in () after field name
            // get this special parameters
            $param  = substr($field, strpos($field, "(")+1,strpos($field, ")")-strpos($field, "(")-1);
            $params = explode(",",$param);
            // field name
            $field    = substr($field, 0, strpos($field, "("));
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
        $item_slice  = AA_Slices::getSlice($item->getSliceID());
        $item_fields = $item_slice->getFields();
        $fields_arr  = $item_fields->getPriorityArray();

        foreach ($fields_arr as $fld) {
            // make the array of fields compatible with content array in order
            // we can use the same syntax ...
            $val[] = array('value' => $fld);
        }
    } else {
        $val = $itemcontent->getValuesArray($field);
    }

    if ( empty($val) ) {
        return '';
    }

    if (!$format_str) {
        if ($separator=='json') {
            // we want JSON encoded array [value1,value2]
            $ret_str = json_encode($val);
        } else {
            // we don't have format string, so we return
            // separated values by $separator (default is ", ")
            foreach ($val as $value) {
                $ret_str = $ret_str . ($ret_str ? $separator : "") . $value;
            }
        }
    } else { // we have format string
        if ( !is_array($params) ) {
            // case if we have only one parameter for substitution
            $val_delim = '';
            foreach ($val as $value) {
                if (!strlen($value['value'])) {
                    continue;
                }
                $dummy     = str_replace("_#1", $value, $format_str);
                $ret_str   = $ret_str . $val_delim . $dummy;
                $val_delim = $separator;
            }
        } else {
            $val_delim = '';
            // case with special parameters in ()
            foreach ($val as $value) { // loop for all values
                $dummy = $format_str; // make work-copy of format string
                for ($i=0, $forcount=count($params); $i<$forcount; $i++) { // for every special parameter do:
                    if (substr($params[$i],0,6) == "const_") {
                        // what we need some constants parameters ( like name, short_id, value, ...)
                        $what = substr($params[$i], strpos($params[$i], "_")+1);
                        if ($what == 'value') {
                            $par = $value; // value is in $item, no need to use db
                        } else {
                            // for something else we need use db
                            $par = getConstantValue($group_id, $what, $value);
                        }
                    } elseif (substr($params[$i],0,2) == "_#") { // special parameter is alias
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
                        $par = $item->get_alias_subst($params[$i],"loop............");
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
 *  @return group id for specified field
 * @param $slice_id
 * @param $field
 */
function getConstantsGroupID($slice_id, $field) {
    global $contentcache;
    // GetCategoryGroup looks in database - there is a good chance, we will
    // expand {const_*} very soon (again), so we cache the result for future
    return $contentcache->get_result("GetCategoryGroup", array($slice_id, $field));
}

/** getConstantValue function
 * @param $group
 * @param $what
 * @param $field_name
 *  @return $what (name, value, short_id,...) of constants with
 group $group and name $field_name)
 */
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
        case "level" :
        // get values from contentcache or use GetConstants function to get it from db
            $val = $contentcache->get_result("GetConstants", array($group, 'pri', $what));
            return $val[$field_name];
            break;
        default :
            if (strlen($what)) {
                $val = $contentcache->get_result("GetConstants", array($group, 'pri', 'short_id'));
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
 */
function QuoteColons($text) {
    return str_replace($GLOBALS['UNQUOTED_ARRAY'], $GLOBALS['QUOTED_ARRAY'], $text);
}


/** DeQuoteColons function
 *  Substitutes special AA 'colon' string back to colon ':' character
 *  Used for parameters, where is no need colons are not parameter separators
 * @param $text
 */
function DeQuoteColons($text) {
    return str_replace($GLOBALS['QUOTED_ARRAY'], $GLOBALS['UNQUOTED_ARRAY'], $text);
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

class AA_Stringexpand_Cookie extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $name
     */
    function expand($name='') {
        return $name ? $_COOKIE[$name] : json_encode($_COOKIE);
    }
}

/** Evaluates the expression
 *    {math:<expression>[:<decimals>[:<decimal point character>:<thousands separator>]]}
 *    {math:1+1-(2*6)}
 *    {math:478778:1:,: }
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
            $dec_point     = get_if($dec_point, ',');
            $ret = number_format($ret, (int)$decimals, $dec_point, $thousands_sep);
        } elseif ($decimals !== '') {
            $decimals = get_if($decimals,0);
            $ret      = number_format($ret, (int)$decimals);
        }
        return $ret;
    }
}

/** {intersect:<ids>:<ids>}
 *  @returns set of intersect of two set of ids (dash separated)
 */
class AA_Stringexpand_Intersect extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($set1, $set2) {
        return join('-', array_intersect(explode('-',$set1),explode('-',$set2)));
    }
}

/** {removeids:<ids1>:<ids2>}
 *  @returns ids1 where will be removed all the ids from ids2 (all dash separated)
 */
class AA_Stringexpand_Removeids extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($set1, $set2) {
        return join(array_diff(explode('-',$set1),explode('-',$set2)),'-');
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
        } elseif ( (strpos($format, 'DATE_') === 0) AND defined($format)) {
            $format = constant($format);
        }
        if ( $timestamp=='' ) {
            $timestamp = time();
        } elseif ( !is_numeric($timestamp) ) {
            $timestamp = strtotime($timestamp);
        // no date (sometimes empty date is 3600 (based on timezone), so we
        // will use all the day 1.1.1970 as empty)
        } elseif (($timestamp < 86400) AND !is_null($no_date_text)) {
            return $no_date_text;
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
        return AA_Stringexpand_Date::expand($format,$timestamp);
    }
}

/** return current timestamp based on the textual date provided
 *   {timestamp:2008-07-01}
 *   {timestamp:20080701t223807}
 *   {timestamp:next Monday}
 */
class AA_Stringexpand_Timestamp extends AA_Stringexpand_Nevercache {
    function expand($datetime='') {
        return strtotime($datetime);
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
     */
    function expand($string,$start,$length=999999999,$add='') {
        if (AA::$encoding == 'utf-8') {
            $ret = mb_substr($string,$start,$length,'utf-8');
            if ( $add AND (mb_strlen($ret) < mb_strlen($string)) ) {
                $ret .= $add;
            }
        } else {
            $ret = substr($string,$start,$length);
            if ( $add AND (strlen($ret) < strlen($string)) ) {
                $ret .= $add;
            }
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
     * @param $ids        // parts separated by $delimiter
     * @param $offset     // start index (first is 0). Could be negative.
     * @param $length     // default is "to the end of the list". Colud be negative
     * @param $delimiter  // default is '-'
     */
    function expand($ids, $offset, $length='', $delimiter='-') {
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
     * @param $ids        // parts separated by '-'
     * @param $limit      // number of returned shuffled ids
     */
    function expand($ids, $limit=null) {
        $arr = explode('-', $ids);
        shuffle($arr);
        if ($limit) {
            $arr = array_slice($arr, 0, $limit);
        }
        return join('-', $arr);
    }
}

/** sorts the values
 *    {sort:<values>[:<order-type>[:<unique>[:<delimiter>]]]}
 */
class AA_Stringexpand_Sort extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids        // parts separated by '-'
     * @param $limit      // number of returned shuffled ids
     */
    function expand($values, $type=null, $unique='', $delimiter='') {
        if (!strlen($delimiter)) {
           $delimiter = '-';
        }
        $arr = explode($delimiter, $values);
        switch ($type) {
            case 'rnumeric': rsort($arr, SORT_NUMERIC);       break;
            case 'rstring':  rsort($arr, SORT_STRING);        break;
            case 'rlocale':  rsort($arr, SORT_LOCALE_STRING); break;
            case 'string':   sort($arr,  SORT_STRING);        break;
            case 'locale':   sort($arr,  SORT_LOCALE_STRING); break;
            default:         sort($arr,  SORT_NUMERIC);       break;
        }
        return join($delimiter, ($unique=='1') ? array_unique($arr) : $arr);
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
     * @param $ids         // item ids separated by '-' (long or short)
     * @param $current_id  // id of the item in question - id should be of the same type as in $ids
     */
    function expand($ids, $current_id) {
        if (!$ids OR !$current_id) {
            return '';
        }
        $arr = explode('-', $ids);
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
     * @param $ids        // item ids (or any other values) separated by '-'
     * @param $delimiter  // separator of the parts - by default it is '-', but
     *                       you can use any one
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
     * @param $ids        // item ids separated by '-' (long or short)
     * @param $delimiter  // separator of the parts - by default it is '-', but
     *                       you can use any one
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
     * @param $ids         // item ids separated by '-' (long or short)
     * @param $current_id  // id of the item in question - id should be of the same type as in $ids
     */
    function expand($ids, $current_id) {
        if (!$ids OR !$current_id) {
            return '';
        }
        $arr = explode('-', $ids);
        $key = array_search($current_id, $arr);
        return ($key AND isset($arr[$key-1])) ? $arr[$key-1] : '';
    }
}

/** Escapes the text for CSV export */
function Csv_escape($text) {
    return (strcspn($text,",\"\n\r") == strlen($text)) ? $text : '"'.str_replace('"', '""', str_replace("\r\n", "\n", $text)).'"';
}

class AA_Stringexpand_Csv extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
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
     * @param $text to escape
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
     */
    function expand($string='',$encoding='') {
        return ConvertCharset::singleton()->escape($string, empty($encoding) ? 'utf-8' : $encoding);
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
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     */
    function expand($text='') {
        $params = func_get_args();
        $item   = $this ? $this->item : null;
        return ($this AND is_object($item) AND $item->isField($text)) ? $item->getval($text) : join(':', $params);
    }
}

/** Escape apostrophs and convert HTML entities */
class AA_Stringexpand_Javascript extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     */
    function expand($text='') {
        return str_replace(array("'","\r\n", "\r", "\n"), array("\'", " ", " ", " "), $text);
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
     */
    function expand($text='') {
        return nl2br(str_replace('  ', ' &ensp;', $this->htmlEscapeAndLinkUrls($text)));
    }

    /**
     *  UrlLinker - facilitates turning plain text URLs into HTML links.
     *  Author: Søren Løvborg (https://bitbucket.org/kwi/urllinker)
     *  http://creativecommons.org/publicdomain/zero/1.0/
     */
    function htmlEscapeAndLinkUrls($text) {
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
        $rexNonUrl    = "[^-_#$+.!*'(),;/?:@=&a-zA-Z0-9\x7f-\xff]"; // characters that should never appear in a URL
        $rexUrlLinker = "{\\b$rexUrl(?=$rexTrailPunct*($rexNonUrl|$))}";
        $rexUrlLinker .= 'i'; // Uncomment this line to allow uppercase URL schemes (e.g. "HTTP://google.com").

        /** $validTlds is an associative array mapping valid TLDs to the value true.
         *  List source:  http://data.iana.org/TLD/tlds-alpha-by-domain.txt
         *  Last updated: 2014-03-19
         */
        $validTlds = array_fill_keys(explode(" ", ".ac .academy .actor .ad .ae .aero .af .ag .agency .ai .al .am .an .ao .aq .ar .arpa .as .asia .at .au .aw .ax .axa .az .ba .bar .bargains .bb .bd .be .berlin .best .bf .bg .bh .bi .bid .bike .biz .bj .blue .bm .bn .bo .boutique .br .bs .bt .build .builders .buzz .bv .bw .by .bz .ca .cab .camera .camp .cards .careers .cat .catering .cc .cd .center .ceo .cf .cg .ch .cheap .christmas .ci .ck .cl .cleaning .clothing .club .cm .cn .co .codes .coffee .cologne .com .community .company .computer .condos .construction .contractors .cool .coop .cr .cruises .cu .cv .cw .cx .cy .cz .dance .dating .de .democrat .diamonds .directory .dj .dk .dm .dnp .do .domains .dz .ec .edu .education .ee .eg .email .enterprises .equipment .er .es .estate .et .eu .events .expert .exposed .farm .fi .fish .fj .fk .flights .florist .fm .fo .foundation .fr .futbol .ga .gallery .gb .gd .ge .gf .gg .gh .gi .gift .gl .glass .gm .gn .gov .gp .gq .gr .graphics .gs .gt .gu .guitars .guru .gw .gy .hk .hm .hn .holdings .holiday .house .hr .ht .hu .id .ie .il .im .immobilien .in .industries .info .ink .institute .int .international .io .iq .ir .is .it .je .jetzt .jm .jo .jobs .jp .kaufen .ke .kg .kh .ki .kim .kitchen .kiwi .km .kn .koeln .kp .kr .kred .kw .ky .kz .la .land .lb .lc .li .lighting .limo .link .lk .lr .ls .lt .lu .luxury .lv .ly .ma .maison .management .mango .marketing .mc .md .me .menu .mg .mh .mil .mk .ml .mm .mn .mo .mobi .moda .monash .mp .mq .mr .ms .mt .mu .museum .mv .mw .mx .my .mz .na .nagoya .name .nc .ne .net .neustar .nf .ng .ni .ninja .nl .no .np .nr .nu .nz .okinawa .om .onl .org .pa .partners .parts .pe .pf .pg .ph .photo .photography .photos .pics .pink .pk .pl .plumbing .pm .pn .post .pr .pro .productions .properties .ps .pt .pub .pw .py .qa .qpon .re .recipes .red .rentals .repair .report .reviews .rich .ro .rs .ru .ruhr .rw .sa .sb .sc .sd .se .sexy .sg .sh .shiksha .shoes .si .singles .sj .sk .sl .sm .sn .so .social .solar .solutions .sr .st .su .supplies .supply .support .sv .sx .sy .systems .sz .tattoo .tc .td .technology .tel .tf .tg .th .tienda .tips .tj .tk .tl .tm .tn .to .today .tokyo .tools .tp .tr .trade .training .travel .tt .tv .tw .tz .ua .ug .uk .uno .us .uy .uz .va .vacations .vc .ve .ventures .vg .vi .viajes .villas .vision .vn .vote .voting .voto .voyage .vu .wang .watch .webcam .wed .wf .wien .wiki .works .ws .xn--3bst00m .xn--3ds443g .xn--3e0b707e .xn--45brj9c .xn--55qw42g .xn--55qx5d .xn--6frz82g .xn--6qq986b3xl .xn--80ao21a .xn--80asehdb .xn--80aswg .xn--90a3ac .xn--c1avg .xn--cg4bki .xn--clchc0ea0b2g2a9gcd .xn--d1acj3b .xn--fiq228c5hs .xn--fiq64b .xn--fiqs8s .xn--fiqz9s .xn--fpcrj9c3d .xn--fzc2c9e2c .xn--gecrj9c .xn--h2brj9c .xn--i1b6b1a6a2e .xn--io0a7i .xn--j1amh .xn--j6w193g .xn--kprw13d .xn--kpry57d .xn--l1acc .xn--lgbbat1ad8j .xn--mgb9awbf .xn--mgba3a4f16a .xn--mgbaam7a8h .xn--mgbab2bd .xn--mgbayh7gpa .xn--mgbbh1a71e .xn--mgbc0a9azcg .xn--mgberp4a5d4ar .xn--mgbx4cd0ab .xn--ngbc5azd .xn--nqv7f .xn--nqv7fs00ema .xn--o3cw4h .xn--ogbpf8fl .xn--p1ai .xn--pgbs0dh .xn--q9jyb4c .xn--rhqv96g .xn--s9brj9c .xn--unup4y .xn--wgbh1c .xn--wgbl6a .xn--xkc2al3hye2a .xn--xkc2dl3a5ee0h .xn--yfro4i67o .xn--ygbi2ammx .xn--zfr164b .xxx .xyz .ye .yt .za .zm .zone .zw"), true);

        $html = '';
        $position = 0;
        while (preg_match($rexUrlLinker, $text, $match, PREG_OFFSET_CAPTURE, $position)) {
            list($url, $urlPosition) = $match[0];

            // Add the text leading up to the URL.
            $html .= myspecialchars(substr($text, $position, $urlPosition - $position));

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
                    $position = $urlPosition + strlen($username);
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
        $html .= myspecialchars(substr($text, $position));
        return $html;
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
     */
    function expand($text='') {
        return str_replace("'", "\'", str_replace('\\', '\\\\', $text));
    }
}

class AA_Stringexpand_Rss extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     */
    function expand($text='') {
        $entities_old = array('&nbsp;', '& ');
        $entities_new = array(' ', '&amp; ');
        return str_replace($entities_old, $entities_new, strip_tags($text));
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



class AA_Stringexpand_Convert extends AA_Stringexpand {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $text
     */
    function expand($text, $from, $to='') {
        require_once AA_INC_PATH."convert_charset.class.php3";
        $encoder = new ConvertCharset;
        return $encoder->Convert($text, trim($from), trim($to));
    }
}


// class AA_Stringexpand_Increase extends AA_Stringexpand {
//     /** expand function
//      * @param $text
//      */
//     function expand($id, $field_id) {
//         echo "<script language=\"JavaScript\" src=\"/aaa/javascript/increse.php?item_id=$id&field_id=$field_id\" type=\"text/javascript\"></script>";
//     }
// }
/** Ussage:
 *    {view:57::page-{xpage}}
 *    {view:57:{ids:0497ac46076bf257d15f3e030170da92:d-category........-=-Env}}
 *    {view:45::group_by-}   // switches off grouping in the view
 */
class AA_Stringexpand_View extends AA_Stringexpand {
    /** expand function
     * @param $vid, $ids
     */
    function expand($vid=null, $ids=null, $settings=null) {
        if (!$vid) {
            return '';
        }
        $view_param['vid'] = $vid;
        if (strlen($ids)) {
            $zids = new zids();
            $zids->addDirty(explode('-',$ids));
            $view_param['zids'] = $zids;
        }
        if (isset($settings)) {
            $view_param = array_merge($view_param, ParseSettings($settings));
        }
        // do not pagecache the view
        return GetViewFromDB($view_param);
    }
}

/** displays current poll from polls module specified by its pid */
class AA_Stringexpand_Polls extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $pid
     */
    function expand($pid, $params=null) {
        require_once AA_BASE_PATH."modules/polls/include/util.php3";
        require_once AA_BASE_PATH."modules/polls/include/stringexpand.php3";
        require_once AA_BASE_PATH."modules/polls/include/poll.class.php3";

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
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     */
    function expand($text='', $no_url_encode=false) {
        if ( !is_object($this->item) OR !$this->item->isField($text) ) {
            return AA_Stringexpand_Conds::_joinArray(json2arr($text), $no_url_encode, '');
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
 *   {item:53443-54322-53553:_HEADLINE:, }
 *
 * You can also use it to display item in tree. In fact, the {item} with
 * trees works exactly the same way, as {itree}, but {itree} has more logical
 * parameter order (due to backward compatibility), so you are encouraged to use
 * {itree} - @see {itree} for more info on tree string representation.
 **/
class AA_Stringexpand_Item extends AA_Stringexpand_Nevercache {

    /** Do not trim all parameters (at least the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $ids_string  ids (long or short (or mixed) separated by dash '-')
     *                     - now you can also use tree representation like:
     *                     3234(3443-3678(3872-3664)-3223)-4045  (see above)
     * @param $content
     * @param $delimeter
     * @param $top
     * @param $bottom
     */
    function expand($ids_string, $content=null, $delim=null, $top=null, $bottom=null) {

        $ids_string = trim(strtolower($ids_string));
        $id_type    = guesstype($ids_string);

        // for speedup - single items evaluate here
        if ( $ids_string AND (($id_type == 's') OR ($id_type == 'l'))) {
            $item = AA_Items::getItem(new zids($ids_string,$id_type));
            if ($item) {
                return $item->subst_alias($content);
            }
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
     * @param $ids_string  ids (long or short (or mixed) separated by dash '-')
     *                     - now you can also use tree representation like:
     *                     3234(3443-3678(3872-3664)-3223)-4045  (see above)
     * @param $top
     * @param $content
     * @param $delimeter
     * @param $bottom
     */
    function expand($ids_string, $top=null, $content=null, $delim=null, $bottom=null) {
        $trans   = array('_#1'=>$top, '_#2'=>$content, '_#3'=>$delim, '_#4'=>$bottom);
        $top     = strtr($top,     $trans);
        $content = strtr($content, $trans);
        $delim   = strtr($delim,   $trans);
        $bottom  = strtr($bottom,  $trans);
        return AA_Stringexpand_Item::expand($ids_string, $content, $delim, $top, $bottom);
    }
}

/** helper class for AA_Stringexpand_Item */
class AA_Treecache {
    var $content;
    var $delim;
    var $top;
    var $bottom;
    var $_cache;

    function AA_Treecache($content, $delim, $top, $bottom) {
        $this->content = $content;
        $this->delim   = $delim;
        $this->top     = $top;
        $this->bottom  = $bottom;
    }

    function cache_list($match) {
        $key = 'g'. hash('md5',$match[0]);

        if (!isset($this->cache[$key])) {
            $subtree  = $this->top     ? $this->_get_item($match[1], $this->top) : '';
            $subtree .= $this->content ? $this->get_concat($match[2]) : '';
            $subtree .= $this->bottom  ? $this->_get_item($match[1], $this->bottom) : '';

            $this->_cache[$key] = $subtree;
        }

        return $key;
    }

    function get_concat($ids_string) {
        if (!$this->content) {
            return '';
        }
        $ids     = explode('-', $ids_string);
        $results = array();
        if ( is_array($ids) ) {
            foreach ( $ids as $item_id ) {
                $c = $this->_get_item($item_id, $this->content);
                if (strlen($c) > 0) {  // assignment
                    $results[] = $c;
                }
            }
        }
        return ($this->delim == 'json') ? json_encode($results) : join($this->delim,$results);
    }

    function _get_item($item_id, $expression) {
        // cached subtree
        if ($item_id{0} == 'g') {
            return $this->_cache[$item_id];
        }

        $id_type = guesstype($item_id);
        if ( $item_id AND (($id_type == 's') OR ($id_type == 'l'))) {
            $item = AA_Items::getItem(new zids($item_id,$id_type));
            // do not show trashed/expired/... items
            if ($item AND $item->isActive()) {
                return $item->subst_alias($expression);
            }
        }
        return '';
    }
}

/** Aggregate information from specified set of items. The function, which could
 *  be used for aggregated values are:
 * @param $function   sum | max | min | avg | concat | count | order | filter
 * @param $ids_string list of item ids, khich we take into account
 * @param $expression the value, we are counting with (like _#NUMBER_E)
 * @param $parameter  posible additional parameter for the function (like delimiter for the "concat" function)
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
     */
    function expand($function, $ids_string, $expression=null, $parameter=null) {
        if ( !in_array($function, array('sum', 'max', 'min', 'avg', 'concat', 'count', 'order', 'filter')) ) {
            return '';
        }
        $ids     = explode('-', $ids_string);
        $results = array();
        $count   = 0;
        if ( is_array($ids) ) {
            foreach ( $ids as $item_id ) {
                // is it item id?
                $id_type = guesstype($item_id);
                if ( $item_id AND (($id_type == 's') OR ($id_type == 'l'))) {
                    $item = AA_Items::getItem(new zids($item_id,$id_type));
                    if ($item) {
                        $count++;
                        if ($expression) {
                            $results[$item_id] = $item->subst_alias($expression);
                        }
                    }
                }
            }
        }
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
                array_walk($results, create_function('$a', 'return (float)str_replace(",", ".", $a);'));
                $ret = (count($results) > 0) ? array_sum($results)/count($results) : '';
                break;
            case 'concat':
                $ret = join($parameter,$results);
                break;
            case 'count':
                $ret = $count;
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
        }
        return $ret;
    }
}


/** returns fultext of the item as defined in slice admin
 */
class AA_Stringexpand_Fulltext extends AA_Stringexpand {
    function expand($item_ids='') {
        $ret = '';
        $iids = explode('-',$item_ids);
        foreach ($iids as $item_id) {
            $id_type    = guesstype($item_id);
            if ( $item_id AND (($id_type == 's') OR ($id_type == 'l'))) {
                $item = AA_Items::getItem(new zids($item_id,$id_type));
                if ($item) {
                    $slice = AA_Slices::getSlice($item->getSliceID());
                    $text  = $slice->getProperty('fulltext_format_top'). $slice->getProperty('fulltext_format'). $slice->getProperty('fulltext_format_bottom');
                        $ret  .= AA_Stringexpand::unalias($text, $slice->getProperty('fulltext_remove'), $item);
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
     * @param $limit  - could be negative for last $limit ids
     */
    function expand($slices, $conds=null, $sort=null, $delimiter=null, $ids=null, $limit=null) {
        $restrict_zids = $ids ? new zids(explode('-',$ids),'l') : false;
        $set           = new AA_Set(explode('-', $slices), $conds, $sort);
        $zids          = $set->query($restrict_zids);
        if ( $limit ) {
            $zids = ($limit<0) ? $zids->slice($limit,-$limit) : $zids->slice(0,$limit);
        }
        return join($zids->longids(), $delimiter ? $delimiter : '-');
    }
}

/** returns ids of items based on Item Set as defined on Admin page
 *  {set:<set_id>}
 */
class AA_Stringexpand_Set extends AA_Stringexpand {
    /** expand function
     * @param $item_set
     */
    function expand($set_id='') {
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
     * @param $item_id    - item to find back links
     * @param $slice_ids  - slices to look at (dash separated), default are all slices within site modules of item's slice
     * @param $sort       - redefine sorting - like: category.......1-,headline........
     *                    - couldbe also
     */
    function expand($item_id=null, $slice_ids=null, $sort=null) {
        $item = AA_Items::getItem($item_id);
        if ($item) {
            $slice_ids = $slice_ids ? $slice_ids : '{site:{modulefield:{slice_id........}:site_ids}:modules}';
            $sort      = $sort      ? (($sort == '-') ? '': $sort) : 'slice_id........,publish_date....-';
            return AA_Stringexpand::unalias("{ids:$slice_ids:d-all_fields-=-{id..............}:$sort}", '', $item);
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
     * @param $ids    - dash separated item ids
     * @param $expression - expression for ordering
     * @param $type   - numeric | rnumeric | string | rstring | locale | rlocale
     */
    function expand($ids=null, $expression=null, $type=null) {
        return AA_Stringexpand_Aggregate::expand('order', $ids, $expression, $type);
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
     * @param $ids    - dash separated item ids
     * @param $expression - expression for ordering
     * @param $equals     - numeric | rnumeric | string | rstring | locale | rlocale
     */
    function expand($ids=null, $expression=null, $equals=null) {
        return AA_Stringexpand_Aggregate::expand('filter', $ids, $expression, $equals);
    }
}

/** returns long ids of subitems items based on the relations between items
 *  {tree:<item_id>[:<relation_field>]}
 *  {tree:2a4352366262227383484784635362ab:relation.......1}
 *  @return dash separated long ids of items which belongs to the tree under
 *          specifield item based on the relation field
 */
class AA_Stringexpand_Tree extends AA_Stringexpand {
    /** expand function
     * @param $item_id          - item id of the tree root (short or long)
     * @param $relation_field   - tree relation field (default relation........)
     */
    function expand($item_id, $relation_field=null, $reverse=null, $sort_string=null, $slices=null) {
        return join('-', AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field, $reverse, $sort_string, $slices));
    }
}

/** returns long ids of subitems items based on the relations between items
 *  {path:<item_id>[:<relation_field>]}
 *  {path:2a4352366262227383484784635362ab:relation.......1}
 *  @return dash separated long ids of items from root to the item
 */
class AA_Stringexpand_Path extends AA_Stringexpand {
    /** expand function
     * @param $item_id          - item id of the tree root (short or long)
     * @param $relation_field   - tree relation field (default relation........)
     */
    function expand($item_id, $relation_field=null) {
        return join('-', array_reverse(AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field)));
    }
}

/** returns string usefull for sorting the tree of items. The string is based on
 *  the short_ids, so if the branch of current item 35897 is 2458-15878-35897,
 *  then the string will be E2458F15878F35897, which works well for ordering
 *  the tree
 *  {sortstring:<shortitem_id>[:<relation_field>]}
 *  {path:2a4352366262227383484784635362ab:relation.......1}
 *  @return dash separated long ids of items from root to the item
 */
class AA_Stringexpand_Sortstring extends AA_Stringexpand {
    /** expand function
     * @param $item_id          - item id of the tree root (short or long)
     * @param $relation_field   - tree relation field (default relation........)
     */
    function expand($item_id, $relation_field=null) {
        $zids = new zids(array_reverse(AA_Stringexpand_Treestring::treefunc('getIds', $item_id, $relation_field), 'l'));
        return join('', array_map( array('AA_Stringexpand_Sortid','expand') , $zids->shortids()));
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
     * @return B1 for $id=1, F25487 for $id=25487, ...
     */
    function expand($number) {
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
 */
class AA_Stringexpand_Treestring extends AA_Stringexpand {
    /** expand function
     * @param $item_id          - item id of the tree root (short or long)
     * @param $relation_field   - tree relation field (default relation........)
     * @param $reverse          - 1 for reverse trees (= child->parent relations)
     * @param $sort_string      - order of tree leaves (currently works only for reverse trees. @todo)
     * @param $slices           - traverse only listed slices (some times usefull if your tree contain more than on slice and you want to count only with a subtree)
     */
    function expand($item_id, $relation_field=null, $reverse=null, $sort_string=null, $slices=null) {
        return AA_Stringexpand_Treestring::treefunc('getTreeString', $item_id, $relation_field, $reverse, $sort_string, $slices);
    }

    function treefunc($func, $item_id, $relation_field, $reverse, $sort_string, $slices) {
        $zid     = new zids($item_id);
        $long_id = $zid->longids(0);
        if (empty($item_id)) {
            return '';
        }
        if (empty($sort_string) OR !is_array($sort = String2Sort($sort_string))) {
            $sort = null;
        }
        $s_arr = (strlen($slices)==0) ? array() : explode('-', $slices);

        return AA_Trees::$func($long_id, get_if($relation_field, 'relation........'), $reverse=='1', $sort, $s_arr);
    }
}

/** @return prints HTML menu
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
     * @param $item_ids        - item ids of the menu options on the first level
     * @param $code            - alias or aa expression which will be printed inside <li></li>
     *                         - should be link to the item - _HEADLINK (for example)
     *                         - if the resulting code is empty, the menu option is not displayed
     *                           (not its submenu), which you can use for not displaying some items
     * @param $relation_field  - tree relation field (default relation........)
     * @param $sort_string     - order of tree leaves
     */
    function expand($item_ids=null, $code=null, $relation_field=null, $sort_string=null) {
        if (empty($code)) {
            return '';
        }
        $zids     = new zids(explode('-',$item_ids));
        $long_ids = $zids->longids();
        if (empty($long_ids)) {
            return '';
        }
        if (empty($sort_string) OR !is_array($sort = String2Sort($sort_string))) {
            $sort = null;
        }
        $current_ids = explode('-', AA_Stringexpand::unalias('{xid:list}'));

        $supertree = AA_Trees::getSupertree(get_if($relation_field, 'relation........'), 1, $sort);
        return $supertree->getMenu($long_ids, $current_ids, $code);
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
     * @param $slices
     * @param $seo_string
     */
    function expand($slices, $seo_string, $bins='') {
        if ($seo_string=='') {
            return '';
        }

        $bins = $bins ? $bins : AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING;

        $set  = new AA_Set(explode('-', $slices), new AA_Condition('seo.............', '=', '"'.$seo_string.'"'), null, $bins);
        $zids = $set->query();
        return join($zids->longids(), '-');
        // added expiry date in order we can get ids also for expired items
        // return AA_Stringexpand_Ids::expand($slices, 'd-expiry_date.....->-0-seo.............-=-"'. str_replace('-', '--', $seo_string) .'"');
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
     */
    function expand($string, $unique_slices='', $encoding='') {
        $base = ConvertCharset::singleton()->escape($string, empty($encoding) ? 'utf-8' : $encoding);
        // we do not want to have looooong urls
        if (strlen($base) > 124) {
            $base = substr($base, 0, 124);
            if (strrpos($base, '-') > 80) {
                // do not split in middle of the word
                $base = substr($base, 0, strrpos($base, '-'));
            }
        }

        $add = '';

        if (($unique_slices == 'all') AND is_object($this->item)) {
            $unique_slices = AA_Stringexpand::unalias("{site:{modulefield:{slice_id........}:site_ids}:modules}", '', $this->item);
        }
        if ( !empty($unique_slices) ) {
            // we do not want to create infinitive loop for wrong parameters
            for ($i=2; $i < 100000; $i++) {
                $ids = AA_Stringexpand_Seo2ids::expand($unique_slices, $base.$add, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING);
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
     */
    function expand($string, $field_id, $unique_slices='', $ignore_item_id='') {
        if (!trim($string)) {
            return new_id();   // just random text
        }
        $slices = explode('-', $unique_slices);
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

/** @returns name (or other field) of the constant in $gropup_id with $value
 *  Example: {constant:AA Core Bins:1:name}
 *           {constant:biom__categories:{@category........:|}:name:|:, }  // for multiple constants
 *           {constant:ekolist-category:{@category.......1:|}:<a href="http#://ekolist.cz/zpravodajstvi/zpravy?kategorie=_#VALUE##_">_#NAME###_</a>:|:, }  // you can use also constant aliases and expressions
 *           {constant:molcz-rubriky:{constants:molcz-rubriky::-}:{(<label><input type='checkbox' name='type[]' value='_#VALUE##_' {ifin:{qs:type:-}:{_#VALUE##_}: checked}>_#NAME###_</label>)}:-: }
 */
class AA_Stringexpand_Constant extends AA_Stringexpand {
    /** Do not trim all parameters (the $delimiter parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $group_id         - constants ID
     * @param $value            - constant value (or values delimited by $value_delimiter)
     * @param $what             - name|value|short_id|description|pri|group|class|id|level
     *                            or any AA expression using constant aliases
     *                            _#NAME###_, _#VALUE##_, _#PRIORITY, _#GROUP##_, _#CLASS##_,
     *                            _#COUNTER_, _#CONST_ID, _#SHORT_ID, _#DESCRIPT, _#LEVEL##_
     * @param $value_delimiter  - value delimiter - used just form translating multiple constants at once
     * @param $output_delimiter - resulting output delimiter - ', ' is default for multiple constants
     */
    function expand($group_id, $value, $what='name', $value_delimiter='', $output_delimiter=', ') {
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
 *  @return prints all constants delimited by <delimiter>. Only _#NAME###_ and _#VALUE##_ aliases could be used
 */
class AA_Stringexpand_Constants extends AA_Stringexpand {
    /** Do not trim all parameters (the $selected parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $group  - group id or JSON list of values
     */
    function expand($group, $format='', $delimiter='') {
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
 *  @return html <option>s for given constant group with selected option
 */
class AA_Stringexpand_Options extends AA_Stringexpand {
    /** Do not trim all parameters (the $selected parameter could contain space) */
    function doTrimParams() { return false; }

    /** expand function
     * @param $group  - group id or JSON list of values
     */
    function expand($group, $selected='') {
        $ret      = '';
        $selected = (string)$selected;
        if ($group[0] == '[') {
            $constants = json_decode($group, true);
            if (is_array($constants)) {
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
 *    {seqence:num:min:limit:step}
 */
class AA_Stringexpand_Sequence extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $group_id
     */
    function expand($type, $min='', $max='', $step='') {
        $arr = array();
        switch ($type) {
        case 'num':
            if (is_numeric($min) AND is_numeric($max)) {
                $arr = strlen($step) ? range((int)$min, (int)$max, (int)$step) : range((int)$min, (int)$max);
            }
            break;
        case 'string':
            if (strlen($min) AND strlen($max)) {
                $arr = range($min, $max);
            }
            break;
        }
        return empty($arr) ? '' : json_encode($arr);
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
     */
    function expand($condition, $text='', $else_text='') {
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
     */
    function expand() {
        $OPERATORS = array('>'=>'>', '>='=>'>=', 'gt'=>'>', 'ge'=>'>=', '='=>'==', '=='=>'==', 'eq'=>'==', '<'=>'<', '<='=>'<=', 'lt'=>'<', 'le'=>'<=', '<>'=>'<>', '!='=>'<>');

        $arg_list = func_get_args();   // must be asssigned to the variable
        $etalon   = PhpFloat(array_shift($arg_list));
        $operator = $OPERATORS[str_replace(array('&gt;','&lt;'), array('>','<'),array_shift($arg_list))];
        if ($operator) {
            $cmp  = create_function('$b', "return ($etalon $operator". ' $b);');
        } else {
            $cmp  = create_function('return false;');
        }

        $ret      = false;
        $i        = 0;
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if ($cmp((float)str_replace(',', '.', trim($arg_list[$i])))) {
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
     */
    function expand($item_id, $field, $var, $text='', $else_text='') {
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
     */
    function expand() {
        $arg_list = func_get_args();   // must be asssigned to the variable
        $haystack = array_shift($arg_list);
        $ret      = false;
        $i        = 0;
        $matched  = '';
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if (!strlen($arg_list[$i]) OR strpos($haystack, $arg_list[$i]) !== false) {
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
        // _#2 is not very usefull but we have it from the times the function was just for one option
        return str_replace(array('_#1','_#2'), array($haystack, $matched), $ret);
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
     * @param $strings...
     */
    function expand() {
        $arg_list  = func_get_args();   // must be asssigned to the variable
        $delimiter = array_shift($arg_list);
        return join($delimiter, array_filter($arg_list, create_function('$str', 'return strlen(trim($str))>0;')));
    }
}


/** Expand URL by adding session
 *  Example: {sessurl:<url>}
 *  Example: {sessurl}         - returns session_id
 *  Example: {sessurl:hidden}  - special case for <input hidden...
 *  Example: {sessurl:param}   - special case for AA_CP_Session=6252412...
 */
class AA_Stringexpand_Sessurl extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $url
     */
    function expand($url='') {
        global $sess;

        if (!isset($sess)) {
            return '';
        }
        switch($url) {
            case '':       return $sess->id;
            case 'hidden': return "<input type=\"hidden\" name=\"".$sess->name."\" value=\"".$sess->id."\">";
            case 'param':  return $sess->name.'='.$sess->id;
        }
        return $sess->url($url);
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
     */
    function expand($val1, $val2) {
        return ( $val1 == $val2 ) ? 'E' : (($val1 > $val2) ? 'G' : 'L' );
    }
}

/** Fieldid -
 *  ussage:  {fieldid:text:1}  - returns text...........1
 */
class AA_Stringexpand_Fieldid extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $type
     * @param $num
     * @param $id_type
     */
    function expand($type, $num=0, $id_type=null) {
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

    private static $ALLOWED_PROPERTIES = Array ('name'=>'name','help'=>'input_help', 'alias1'=>'alias1', 'alias2'=>'alias2', 'alias3'=>'alias3', 'widget_new'=>'widget_new' );

    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getSliceID();
    }

    /** expand function
     * @param $field_id
     * @param $property
     * @param $slice_id
     */
    function expand($field_id, $property='name', $slice_id=null) {
        // we do not want to allow users to get all field setting
        // that's why we restict it to the properties, which makes sense
        // @todo - make it less restrictive
        $property = self::$ALLOWED_PROPERTIES[$property];
        if ($property == 'widget_new') {
            return AA_Stringexpand_Input::expand($slice_id, $field_id);
        }

        $field = $this->_getField($slice_id, $field_id);
        if (!$field) {
            return '';
        }

        return (string) $field->getProperty($property ? $property : 'name');
    }

    function _getField($slice_id, $field_id) {
        if (empty($slice_id)) {
            if ( empty($this->item) ) {
                return '';
            }
            $slice_id = $this->item->getSliceID();
        }
        return AA_Slices::getField($slice_id, $field_id);
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
     * @param $values  (single string or array in JSON)
     */
    function expand($slice_id, $field_id, $values=null) {
        $field = $this->_getField($slice_id, $field_id);
        if (!$field) {
            return '';
        }

        $widget  = $field->getWidget();
        return $widget ?  $widget->getSelectOptions($widget->getOptions(AA_Value::factoryFromJson($values))) : '';
    }
}

/** Allows on-line editing of field content */
class AA_Stringexpand_Input extends AA_Stringexpand_Field {
    /** expand function
     * @param $item_id
     * @param $field_id
     * @param $required
     * @param $widget_type
     */
     function expand($slice_id, $field_id, $required=null, $widget_type=null) {
         if ( !($field = $this->_getField($slice_id, $field_id))) {
             return '';
         }
         return $field->getWidgetNewHtml($required==1, $widget_type);
     }
}

/** Allows on-line editing of field content
 *  {ajax:<item_id>:<field_id>[:<alias_or_any_code>[:<onsuccess>]]}
 *  {ajax:{_#ITEM_ID_}:category........}
 *  {ajax:{_#ITEM_ID_}:switch.........1:_#IS_CHECK}
 *  {ajax:{_#ITEM_ID_}:file............:<img src="/img/edit.gif" title="Upload new file"> :AA_Refresh('stickerdiv1')}
 *  {ajax:{_#ITEM_ID_}:file............:<img src="/img/edit.gif" title="Upload new file"> :AA_Refresh(this)}   // updates the first element with data-aa-url in DOM up
 **/
class AA_Stringexpand_Ajax extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it shoud always look in the database

    // not needed right now for Nevercached functions, but who knows in the future
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getID();
    }

    /** expand function
     * @param $item_id
     * @param $field_id
     * @param $show_alias
     * @param $onsuccess
     */
    function expand($item_id, $field_id, $show_alias='', $onsuccess='') {
        $ret = '';
        if ( $field_id) {
            $item = $item_id ? AA_Items::getItem(new zids($item_id)) : $this->item;
            if (!empty($item)) {
                $alias_name  = base64_encode(($show_alias == '') ? '{'.$field_id.'}' : $show_alias);
                $repre_value = ($show_alias == '') ? $item->f_h($field_id, ', ') : $item->subst_alias($show_alias);
                $repre_value = (strlen($repre_value) < 1) ? '--' : $repre_value;
                $iid         = $item->getItemID();
                $input_name  = AA_Form_Array::getName4Form($field_id, $item);
                $input_id    = AA_Form_Array::formName2Id($input_name);
                $ret .= "<div class=\"ajax_container\" id=\"ajaxc_$input_id\" onclick=\"displayInput('ajaxv_$input_id', '$iid', '$field_id')\" style=\"display:inline\">";
                $data_onsuccess = $onsuccess ? 'data-aa-onsuccess="'.myspecialchars($onsuccess).'"' : '';
                $ret .= "<div class=\"ajax_value\" id=\"ajaxv_$input_id\" data-aa-alias=\"".myspecialchars($alias_name)."\" $data_onsuccess style=\"display:inline\">$repre_value</div>";
                $ret .= "<div class=\"ajax_changes\" id=\"ajaxch_$input_id\" style=\"display:inline\"></div>";
                $ret .= "</div>";
            }
        }
        return $ret;
    }
}


/** Allows on-line editing of field content
 *    {live:<item_id>:<field_id>:<required>:<function>:<widget_type>}
 *
 *   <required>    explicitly mark the live field as required (0|1)
 *   <function>    specify javascript function, which is executed after the widget
 *                  is sumbitted
 *   <widget_type> which widget to show
 */
class AA_Stringexpand_Live extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it shoud always look in the database

    // not needed right now for Nevercached functions, but who knows in the future
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getID();
    }

    /** expand function
     * @param $item_id
     * @param $field_id
     * @param $required
     * @param $function
     * @param $widget_type
     */
    function expand($item_id, $field_id, $required=null, $function=null, $widget_type=null) {
        $ret = '';

        if (!$field_id) {
            return '';
        }

        $item = $item_id ? AA_Items::getItem(new zids($item_id)) : $this->item;
        if (!empty($item)) {

            $iid   = $item->getItemID();
            $slice = AA_Slices::getSlice($item->getSliceId());

            // Use right language (from slice settings) - languages are used for button texts, ...
            $lang  = $slice->getLang();
            //$charset = $GLOBALS["LANGUAGE_CHARSETS"][$lang];   // like 'windows-1250'
            mgettext_bind($lang, 'output');

            $field = $slice->getField($field_id);
            $ret   = $field ? $field->getWidgetLiveHtml($iid, ($required==1) ? true : null, $function, $widget_type) : '';
        }
        return $ret;
    }
}

/** Get module (slice, ...) property (currently only "module fileds"
 *  (beggining with underscore) and 'name' is supported
 *  replacing older {alias:_abstract.......:f_s:slice_info} syntax
 **/
class AA_Stringexpand_Modulefield extends AA_Stringexpand {
    /** expand function
     * @param $property
     */
    function expand($slice_id, $property='') {
        $slice = AA_Slices::getSlice($slice_id);
        // we do not want to allow users to get all field setting
        // that's why we restict it to the properties, which makes sense
        // @todo - make it less restrictive

        // site_id is older, but better is to use site_ids, since it could return more than one ids (dash separated)
        if (($property == 'site_ids') OR ($property == 'site_id')) {
            return join('-', (array)GetTable2Array("SELECT source_id FROM relation WHERE destination_id='".q_pack_id($slice_id)."' AND flag='".REL_FLAG_MODULE_DEPEND."'", '', 'unpack:source_id'));
        }
        if (!AA_Fields::isSliceField($property)) {  // it is "slice field" (begins with underscore _)
            $property = ($property=='url') ? 'slice_url' : 'name';
        }
        return $slice->getProperty($property);
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
     */
    function expand($site_ids, $property='') {
        $arr = '';
        if ($property == 'modules') {
            $where = sqlin( 'source_id', array_map("pack_id", explode('-',$site_ids)) );
            $arr   = GetTable2Array("SELECT destination_id FROM relation WHERE $where AND flag='".REL_FLAG_MODULE_DEPEND."'", "", 'unpack:destination_id');
        }
        return is_array($arr) ? join('-' , array_values(array_unique($arr))) : '';
    }
}

/** Deprecated - use AA_Stringexpand_Modulefield instead
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
     */
    function expand($property='name') {
        // get slice_id from item, but sometimes the item is not filled (like
        // on "Add Item" in itemedit.php3, so we use global slice_id here
        $item = $this->item;
        $slice_id  = $item ? $item->getSliceID() : $GLOBALS['slice_id'];
        if (!$slice_id ) {
            return "";
        }
        return AA_Stringexpand_Modulefield::expand($slice_id, $property);
    }
}

/** Deprecated for site modules - use AA_Stringexpand_Pager instead
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
     */
    function expand($begin='', $end='', $add='', $nopage='') {
        $itemview = $this->itemview;
        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th grou display
            return "Scroller not valid without a view, or for group display";
        }
        $viewScr = new AA_Sitemodule_Scroller($itemview->slice_info['vid'], $itemview->num_records, $itemview->idcount(), $itemview->from_record);
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
    function additionalCacheParam() {
        global $apc_state;
        $itemview = $this->itemview;
        return !is_object($itemview) ? '' : serialize($apc_state['router']).':'.$itemview->num_records.':'.$itemview->idcount().':'.$itemview->from_record;
    }

    /** expand function
     * @param $property
     */
    function expand($target=null) {
        global $apc_state;

        $itemview = $this->itemview;
        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th grou display
            return "Err in {pager} - pager not valid without a view, or for group display";
        }

        if (!isset($apc_state['router'])) {
            // used for AJAX scroller in the SEO sitemodule, for example
            $viewScr = new AA_View_Scroller($itemview->slice_info['vid'], $itemview->num_records, $itemview->idcount(), $itemview->from_record);
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

/** debugging
 */
class AA_Stringexpand_Debug extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters  */
    function doTrimParams() { return false; }

    /** expand function
     * @param $property
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


/** makeAsShortcut function
 *  Store $text in the $html_subst_arr array - used for dictionary escaping html
 *  tags.
 *  This function belongs to AA_Stringexpand_Dictionary class, but I don't know,
 *  how to call class method from preg_replace function.
 *  So it remains as separate function.
 * @param $text
 */
function makeAsShortcut($text) {
    static $count=0;
    $shortcut = '_AA_'.$count.'_ShCut';
    $GLOBALS['html_subst_arr'][$shortcut] = stripslashes($text);
    $count++;
    return $shortcut;
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
     */
    function expand($dictionaries, $text, $format, $conds='') {
        global $pagecache;

        // sometimes this function last to much time - try to extend it
        if (($max_execution_time = ini_get('max_execution_time')) > 0) {
            set_time_limit($max_execution_time+20);
        }

        $dictionaries = explode('-',$dictionaries);

        $delimiters = AA_Stringexpand_Dictionary::defineDelimiters();
        // get pairs (like APC - <a href="http://apc.org">APC</a>' from dict. slice
        // (we call it through the pagecache in order it is called only once for
        // the same parameters)
        $replace_pairs = $pagecache->cacheMemDb(array('AA_Stringexpand_Dictionary','getDictReplacePairs'), array($dictionaries, $format, $delimiters, $conds), new CacheStr2find($dictionaries));

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


    /** getDictReplacePairs function
     *  Return array of substitution pairs for dictionary, based on given dictionary
     *  slice, format string which defines the format and possible slice codnitions.
     *   [biom] => <a href="http://biom.cz">_#KEYWORD_</a>, ...
     * @param $dictionaries (array)
     * @param $format
     * @param $delimeters
     * @param $conds
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

        $format  = AA_Slices::getField($dictionaries[0], $format) ? '{substr:{'.$format.'}:0:50}' : $format;
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
        for ($i=0, $len=strlen($delimiter_chars); $i<$len; $i++) {
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
}

/** include file, first parameter is filename, second is hints on where to find it **/
class AA_Stringexpand_Include extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $fn first parameter is filename, second is hints on where to find it
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
                $fileman_dir = AA_Slices::getSliceProperty($mysliceid,"fileman_dir");
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
 */
function expandFilenameWithHttp($parturl) {
    $filename = str_replace( 'URL_PARAMETERS', DeBackslash(shtml_query_string()), $parturl);

    // filename do not use colons as separators => dequote before callig
    if (!$filename || trim($filename)=="") {
        return "";
    }

    $headers  = array();
    // if no http request - add server name
    if (!(substr($filename, 0, 7) == 'http://') AND !(substr($filename, 0, 8) == 'https://')) {
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

/** Get $_SERVER[<variable>] value **/
class AA_Stringexpand_Server extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $variable
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
    var $item;
    var $itemview;

    // We use different AA_Unalias_Callback object for each item, so this cache
    // is usefull just in case we are using the same expresion inside the same
    // spot or view field
    // We use it just for easy expressions - like field, aliases, where we do not use $contentcache
    var $_localcache;

    /** AA_Unalias_Callback function
     * @param $item
     * @param $itemview
     */
    function AA_Unalias_Callback( $item, $itemview ) {
        $this->item        = is_object($item) ? $item : null;
        $this->itemview    = $itemview;
        $this->_localcache = array();
    }

    function expand_bracketed_timedebug($match) {
        $func = current(explode(':',substr($match[1],0,16),2));
        $time = microtime(true);
        $ret  = $this->expand_bracketed($match);
        AA::$dbg->duration($func, microtime(true)-$time);
        return $ret;
    }

    /** expand_bracketed function
     *  Expand a single, syntax element
     * @param $out
     * @param $level
     * @param $item
     * @param $itemview
     */
    function expand_bracketed($match) {
        global $contentcache, $als, $errcheck;
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
        // {fnctn:xxx:yyyy}   - expand $AA_Stringexpand::$php_functions[fnctn]
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
            case '-': return QuoteColons(substr($out,1));
            case '_':         // Look for {_#.........} and expand now, rather than wait till top
                      if ($out[1] == "#") {
                          if (isset($this->_localcache[$out])) {
                              return $this->_localcache[$out];
                          }
                          if (isset($als[substr($out,2)])) {
                              return ($this->_localcache[$out] = QuoteColons(AA_Stringexpand::unalias($als[substr($out,2)], '', $this->item, false, $this->itemview)));
                          } elseif (isset($this->item)) {
                              // just alias or not so common: {_#SOME_ALSand maybe some text}
                              return ($this->_localcache[$out] = QuoteColons(($outlen == 10) ? $this->item->get_alias_subst($out) : $this->item->substitute_alias_and_remove($out)));
                          }
                      }
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
        if (in_array(substr($out, 0, 5), array('const', 'alias', 'math(', 'inclu', 'view.', 'dequo'))) {
            // look for {const_*:} for changing viewing type of constants
            if ((substr($out, 0, 6) == "const_") AND isset($this->item)) {
                // $what - name of column (eg. from const_name we get name)
                $what = substr($out, strpos($out, "_")+1, strpos($out, ":") - strpos($out, "_")-1);
                // parameters - first is field
                $parts = ParamExplode(substr($out,strpos($out,":")+1));
                // get group id
                $group_id = getConstantsGroupID($this->item->getSliceID(), $parts[0]);
                /* get short_id/name/... of constant with specified value from constants category with
                   group $group_id */

                $value = getConstantValue($group_id, $what, $this->item->getval($parts[0]));

                return QuoteColons($value);
            }
            // tried to change to preg_match, but there was problem with multiple lines
            //   used: '/^alias:([^:]*):([a-zA-Z0-9_]{1,3}):?(.*)$/'
            elseif ( (substr($out, 0, 5)=='alias') AND isset($this->item) AND ereg('^alias:([^:]*):([a-zA-Z0-9_]{1,3}):?(.*)$', $out, $parts) ) {
                // call function (called by function reference (pointer))
                // like f_d("start_date......", "m-d")
                if ($parts[1] && !$this->item->isField($parts[1])) {
                    huhe("Warning: $out: $parts[1] is not a field, don't wrap it in { } ");
                }
                $fce     = $parts[2];
                return QuoteColons($this->item->$fce($parts[1], $parts[3]));
                // QuoteColons used to mark colons, which is not parameter separators.
            }
            elseif( substr($out, 0, 5) == "math(" ) {
                // replace math
                return QuoteColons( parseMath(DeQuoteColons(AA_Stringexpand::unalias(substr($out,5), '', $this->item, false, $this->itemview))) ); // Need to unalias in case expression contains _#XXX or ( )
            }
            elseif( substr($out, 0, 8) == "include(" ) {
                // include file
                if ( !($pos = strpos($out,')')) ) {
                    return "";
                }
                $fileout = expandFilenameWithHttp(DeQuoteColons(substr($out, 8, $pos-8)));
                return QuoteColons($fileout);
                // QuoteColons used to mark colons, which is not parameter separators.
            }
            elseif ( substr($out, 0,10) == "view.php3?" ) {
                // Xinha editor replaces & with &amp; so we need to change it back
                $param      = str_replace(array('&amp;','-&lt;','-&gt;','&lt;-','&gt;-'), array('&','-<','->','<-','>-'), substr($out,10));
                $view_param = ParseViewParameters(DeQuoteColons($param));
                // do not store in the pagecache, but store into contentcache
                return QuoteColons($contentcache->get_result_by_id(get_hash($view_param), 'GetViewFromDB', array($view_param)));
            }
            // This is a little hack to enable a field to contain expandable { ... } functions
            // if you don't use this then the field will be quoted to protect syntactical characters
            elseif ( substr($out, 0, 8) == "dequote:" ) {
                return DeQuoteColons(substr($out,8));
            }
        }
        // OK - its not a known fixed string, look in various places for the whole string
        // if ( preg_match('/^([a-zA-Z_0-9]+):?([^}]*)$/', $out, $parts) ) {
        $initiallen = strspn(strtolower(substr($out,0,64)), 'abcdefghijklmnopqrstuvwxyz0123456789_');
        $outcmd     = substr($out,0,$initiallen);
        if ( $outcmd ) {

            $outparam   = substr($out,$initiallen+1);  // skip one more char - delimiter

            // main stringexpand functions.
            // @todo switch most of above constructs to standard AA_Stringexpand...
            // class
            if ( !is_null($stringexpand = AA_Serializable::factoryByName($outcmd, array('item'=>$this->item, 'itemview'=> $this->itemview), 'AA_Stringexpand_'))) {
                if ( $stringexpand->doCache() ) {
                    $key = hash('md5',$out.$stringexpand->additionalCacheParam());
                    $res = $contentcache->get_result_by_id($key, array($stringexpand, 'parsexpand'), $outparam);
                } else {
                    $res = $stringexpand->parsexpand($outparam);
                }
                return $stringexpand->doQuoteColons() ? QuoteColons($res) : $res;
            }

            // eb functions - call allowed php functions directly
            if ( AA_Stringexpand::$php_functions[$outcmd] ) {
                $fnctn = AA_Stringexpand::$php_functions[$outcmd];
            } elseif ( is_callable("stringexpand_$outcmd")) {  // custom stringexpand functions
                $fnctn = "stringexpand_$outcmd";
            }
            // return result only if matches stringexpand_ or eb functions
            if ( $fnctn ) {
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
            return QuoteColons(AA_Stringexpand::unalias($GLOBALS['apc_state'][$out], '', $this->item, false, $this->itemview));
        }
        // Pass these in URLs like als[foo]=bar,
        // Note that 8 char aliases like als[foo12345] will expand with _#foo12345
        elseif (isset($als[$out])) {
            return QuoteColons(AA_Stringexpand::unalias($als[$out], '', $this->item, false, $this->itemview));
        }
        //    elseif (isset($aliases[$out])) {   // look for an alias (this is used by mail)
        //        return QuoteColons($aliases[$out]);
        //    }
        // first char of alias is @ - make loop to view all values from field
        elseif ( (substr($out,0,1) == "@") OR (substr($out,0,5) == "list:")) {
            return QuoteColons(parseLoop($out, $this->item));
        }
        elseif (substr($out,0,8) == "mlx_view") {
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
            if ($errcheck && ereg("^[a-zA-Z_]",$out)) {
                huhl("Couldn't expand: \"{$out}\"");
            }
            return QuoteColons("{" . $out . "}");
        }
    }
}


function make_reference_callback($match) {
    global $contentcache;

    $ref = 'R'. mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
    $txt = $match[1];          // for dereference
    $contentcache->set("define:$ref", $txt);
    return "{var:$ref}";
}

// This isn't used yet, might be changed
// remove this comment if you use it!
class AA_Stringexpand_Slice_Comments extends AA_Stringexpand {
    /** expand function
     * @param $slice_id
     */
    function expand($slice_id) {
        $dc = DB_AA::select1('SELECT sum(disc_count) AS total FROM `item`', 'total', array(array('slice_id', $slice_id,'l')));
        return $dc ?: 0;
    }
}

class AA_Stringexpand_Preg_Match extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    // No reason to cache this simple function
    /** expand function
     * @param $pattern
     * @param $subject
     */
    function expand($pattern, $subject) {
        // preg_match unfortunately allow users to run their own PHP code
        // (using '/pattern/e'), which is dangerous. We do not want to allow
        // designers to run custom scripts inside AA
        // @todo better check for e modifier
        if (strpos($pattern,'e', strpos($pattern, $pattern{0}, 1))) {
            return _m('PHP patterns in Preg_Match are not allowed');
        }
        preg_match($pattern, $subject, $matches);
        return $matches[0];
    }
}

class AA_Stringexpand {

    public static $recursion_count = 0;

    /** item, for which we are stringexpanding
     *  Not used in many expand functions
     */

    var $item;

    /** view, in which we are stringexpanding
     *  Not used in many expand functions
     */
    var $itemview;

    /** In this array are set functions from PHP or elsewhere that can usefully go in {xxx:yyy:zzz} syntax */
    public static $php_functions = array (
        'strlen'           => 'strlen',             // old  AA_Stringexpand_Strlen
        'str_repeat'       => 'str_repeat',         // old  AA_Stringexpand_Str_repeat
        'strtoupper'       => 'strtoupper',         //      AA_Stringexpand_Strtoupper
        'strtolower'       => 'strtolower',         //      AA_Stringexpand_Strtolower
        'striptags'        => 'strip_tags',         // old  AA_Stringexpand_Striptags
        'htmlspecialchars' => 'myspecialchars',     // old  AA_Stringexpand_Htmlspecialchars - similar to {safe}, but without double_escape
        'urlencode'        => 'urlencode',          // old  AA_Stringexpand_Urlencode
        'ord'              => 'ord',                // old  AA_Stringexpand_Ord
        'rand'             => 'rand',               // old  AA_Stringexpand_Rand
        'fmod'             => 'fmod',               // old  AA_Stringexpand_Fmod
        /** math function log() */
        'log'              => 'log',                // old  AA_Stringexpand_Log
        'unpack'           => 'unpack_id',          // old  AA_Stringexpand_Unpack
        'string2id'        => 'string2id',          // old  AA_Stringexpand_String2id

        /** Prints version of AA as fullstring, AA version (2.11.0), or svn revision (2368)
         *  {version[:aa|svn]}
         **/
        'version'          => 'aa_version',         // old AA_Stringexpand_Version'

        /** Encodes string for JSON - apostrophs ' => \', ... */
        'jsonstring'       => 'json_encode'         // old AA_Stringexpand_Jsonstring'
    );


    /** AA_Stringexpand function
     * @param $item
     */
    function AA_Stringexpand($param) {
        $this->item     = $param['item'];
        $this->itemview = $param['itemview'];
    }

    /** expand function
     */
    function expand() {
    }

    function parsexpand($params) {
        if (empty($params)) {
            return $this->expand();
        }
        if ($this->doTrimParams()) {
            $param = array_map('DeQuoteColons', array_map('trim', ParamExplode($params)));
        } else {
            $param = array_map('DeQuoteColons', ParamExplode($params));
        }
        return call_user_func_array( array($this,'expand'), $param);
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

    /** unalias function
     *  static function
     *  This is based on the old unalias_recurent, it is intended to replace
     *  string substitution wherever its occurring.
     *  Differences ....
     *    - remove is applied to the entire result, not the parts!
     * @param $text
     * @param $item
     * @param $remove
     * @param $dequote
     * @param $itemview
     */
    function unalias($text, $remove='', $item=null, $dequote=true, $itemview=null ) {
        global $debugtime;

        if (++AA_Stringexpand::$recursion_count > 5000) {
            --AA_Stringexpand::$recursion_count;
            return "Error: recursion detected";
        }

        $GLOBALS['g_formpart'] = 0;  // used for splited inputform into parts

        // make sure, that $contentcache is defined - we will use it in expand_bracketed()
        contentcache::global_instance();

        // Note ereg was 15 seconds on one multi-line example cf .002 secs
        //    while (ereg("^(.*)[{]([^{}]+)[}](.*)$",$text,$vars)) {

        // to speeedup the process we check, if we have to look for {( ... )} pairs
        if (strpos($text, '{(') !== false) {
            // replace all {(.....)} with {var:...}, which will be expanded into {...}
            // this alows to write expressions like
            //   {item:6625:{(some text {headline........}...etc.)}}
            // /{\(((?:.(?!{\())*)\)}/sU  - the expression is complicated because it
            //                              solves also nesting - like:
            //                              see {( some {( text )} which )} could {( be )} nested
            $last_replacements = 1;
            do {
                if (is_null($text = preg_replace_callback('/{\(((?:.(?!{\())*)\)}/sU', 'make_reference_callback', $text, -1, $last_replacements))) {  //s for newlines, U for nongreedy
                    echo "Error: preg_replace_callback";
                }
            } while($last_replacements);
        }

        $quotecolons_partly = false;
        $callback = new AA_Unalias_Callback($item, $itemview);
        while (preg_match('/[{]([^{}]+)[}]/s',$text)) {
            // it just means, we need to unquote colons
            $quotecolons_partly = true;
            if (is_null($text = preg_replace_callback('/[{]([^{}]+)[}]/s', array($callback, ($debugtime>2) ? 'expand_bracketed_timedebug' : 'expand_bracketed'), $text))) {  //s for newlines, U for nongreedy
                echo "Error: preg_replace_callback";
            }
        }

        if (is_object($item)) {
            $text = $item->substitute_alias_and_remove($text, strlen($remove) ? explode("##",$remove): array());
        }

        // if ( !$dequote ) { }
        // there is no need to substitute on level 1

        --AA_Stringexpand::$recursion_count;

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
    function unaliasArray(&$arr, $remove="", $item=null) {
        if (is_array( $arr )) {
            foreach ( $arr as $k => $text ) {
                $arr[$k] = AA_Stringexpand::unalias($text, $remove, $item);
            }
        }
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
    function doCache() {
        return false;
    }
}

/** unaliases the text - replaces {views} and other constructs */
class AA_Stringexpand_Expand extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $number
     */
    function expand($string='') {
        $item   = $this ? $this->item : null;
        return AA_Stringexpand::unalias($string,'',$item);
    }
}


/** trims whitespaces form begin and end of the string */
class AA_Stringexpand_Trim extends AA_Stringexpand {
    /** Do not trim all parameters ($chars could contain space) */
    function doTrimParams() { return false; }

    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
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
    function expand($search='', $replace='', $text='') {
        $search  = json2arr($search,true);
        $replace = json2arr($replace,true);
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
    function expand() {
        return self::_get_result('max', func_get_args());
    }

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
    function expand() {
        return self::_get_result('min', func_get_args());
    }
}


class AA_Stringexpand_Packid extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $unpacked_id
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
     * @param $type type of statistics - currently only "days" statistics is implemented
     * @param $ids  item ids (long or short) for which you want to display statistics
     *
     * Example: {hitcounter:days:24457-24474}
     * Example: {hitcounter:days:{ids:76f59b2023b8a4e8d6c57831ef8c8199:d-publish_date....->-1185919200}}
     */
    function expand($type, $ids) {
        $ret = '';
        if ( $type == 'days' ) {
            $zids   = new zids(explode('-',$ids));
            $s_zids = new zids($zids->shortids(), 's');
            $hits   = GetTable2Array('SELECT id, time, hits FROM hit_archive WHERE '. $s_zids->sqlin('id'), '');
            $stat   = array();
            foreach ($hits as $hit) {
                $day        = date('Y-m-d', $hit['time']);
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
        }
        return $ret;
    }
}


//error_reporting(E_ALL ^ E_NOTICE);
//ini_set('display_errors', 1);

/** @return avatar img or colored div with initials
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
     */
    function expand($image='', $name='', $size='') {
        $size  = get_if($size, 48);
        $title = (strpos($name,'@')===false) ? $name : substr($name,0,strpos($name,'@'));

        if ($img = AA_Stringexpand::unalias("{img:$image:w=$size&h=$size&iar=1:imgb:$title}")) {
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
 *     <img src="{img:{img_url.........}:w=150&h=150}">
 *     <img src="{img:{img_url.........}:w=150&h=150&iar=1}">
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
     */
    function expand($image='', $phpthumb_params='', $info='', $param1='', $param2='') {

        //AA::$debug && AA::$dbg->info('AA_Stringexpand_Img0', $image, $phpthumb_params, $info, $param1, $param2);

        if (!$image) {
            return '';
        }
        list($img_url,$img_short) = AA_Stringexpand_Img::_getUrl($image, $phpthumb_params);

        if (empty($info) OR ($info == 'url') OR empty($img_url)) {
            return $img_short;
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
            case 'imgb':    $param2 .= ' border="0"';  // no break!!!
            case 'img':     $param1 = safe(strip_tags($param1));
                            $alt = $param1 ? " title=\"$param1\" alt=\"$param1\"" : '';
                            return "<img src=\"$img_short\" ". $a[3] ." $alt $param2 />";
        }
        return '';
    }

    function _getUrl($image, $phpthumb_params) {
        if (empty($phpthumb_params)) {
            return array($image,$image);
        }
        // separate parameters
        if (strpos($phpthumb_params, '&amp;') === false) {
            $phpthumb_params = str_replace('&', '&amp;', $phpthumb_params);
        }

        // it is much better for phpThumb to access the files as files reletive
        // to the directory, than using http access
        if (AA_HTTP_DOMAIN !== "/") {
            $image = str_replace(AA_HTTP_DOMAIN, '', $image);
        }
        if (substr($image,0,4)=="http") {
            $image = ereg_replace("http://(www\.)?(.+)\.([a-z]{1,6})/(.+)", "\\4", $image);
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

    function expand($url, $info) {

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
                return AA_Stringexpand_Filelink::expand($url);
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

    function expand($url, $text='', $text_before='') {
        if (empty($url)) {
            return '';
        }
        $filename = $text ? $text : basename(parse_url($url, PHP_URL_PATH));
        $fileinfo = join(' - ', array(AA_Stringexpand_Fileinfo::expand($url,'type'), AA_Stringexpand_Fileinfo::expand($url,'size')));
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
     */
    function expand($module_id) {
        require_once AA_BASE_PATH."modules/alerts/util.php3";

        // we need just reader slice id
        $collectionprop = GetCollection($module_id);

        if (!$collectionprop) {
            return '';
        }
        $reader_slice_id = $collectionprop['slice_id'];
        if ($_GET["aw"]) {
            if (confirm_email($reader_slice_id, $_GET["aw"])) {
                return '<div class="aa-ok">E-mail confirmed</div>';  // @todo get messages from alerts module
            }
        }
        if ($_GET["au"]) {
            if (unsubscribe_reader($reader_slice_id, $_GET["au"], $_GET["c"])) {
                return '<div class="aa-ok">E-mail unsubscribed</div>';  // @todo get messages from alerts module
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
    function expand($slice_pwd, $slice_id='') {
        $credentials = AA_Credentials::singleton();
        $credentials->register(AA_Credentials::encrypt($slice_pwd));
        return '';
    }
}

/** @return url GET parameter - {qs[:<varname>[:delimiter]]}
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
        } else {
            $shtml_get = add_vars('', 'return');
            $ret = $shtml_get[$variable_name];
        }
        return !is_array($ret) ? $ret : ( is_null($delimiter) ? json_encode($ret) : join($delimiter, $ret));
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


/** @return returns random string of given length
 *  (for more advanced version see default_fnc_rnd)
 *  Ussage: {randomstring:5}
 */
class AA_Stringexpand_Randomstring extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
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

    function expand() {
        static $sc = null;

        $arg_list = func_get_args();   // must be asssigned to the variable
        $name     = array_shift($arg_list);

        if ( isset($GLOBALS['STRINGEXPAND_SHORTCUTS'][$name]) ) {
            $text = $GLOBALS['STRINGEXPAND_SHORTCUTS'][$name];
        } else {
            // read the shortcuts from the database
            if (is_null($sc) OR is_null($sc[AA::$site_id])) {
                $sc[AA::$site_id] = array();
            }
            if (is_null($sc[AA::$site_id][$name])) {
                // look for additional aliases
                $modules = array(AA::$site_id);
                if ($site = AA_Module_Site::getModule(AA::$site_id)) {
                    if (is_array($add_modules = $site->getProperty('add_aliases'))) {
                        $modules = array_merge($modules,array_filter($add_modules));
                    }
                }
                $zids = AA_Object::querySet('AA_Aliasfunc', new AA_Set($modules, new AA_Condition('alias', '==', $name)));
                $sc[AA::$site_id][$name] = AA_Object::loadProperty($zids->longids(0),'code');
                // another approach read all at once - not used
                // huhl( AA_Object::loadProperties($zids->longids(), 'aa_name'));
            }
            $text = $sc[AA::$site_id][$name];
        }

        return AA_Stringexpand::replaceParams($text, $arg_list);
    }
}

/** Encrypt the text using $key as password (mcrypt PHP extension must be installed)
 */
class AA_Stringexpand_Encrypt extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    function expand($text, $key) {
        return AA_Stringexpand_Encrypt::_encryptdecrypt(true, $text, $key);
    }

    function get_time_token($data) {
        return base64_encode(AA_Stringexpand_Encrypt::_encryptdecrypt(true, serialize($data), 'aa-sul'.AA_ID.date('j.n.y H')));
    }

    /** Try to decode sent token - not older than $hours
     *  $hours must count with cache - the tokens on the form could be in the cache
     */
    function decrypt_time_token($token, $hours=24) {
        $token = base64_decode($token);
        for ($i=0; $i<$hours; ++$i) {
            if (strlen($serialized = AA_Stringexpand_Encrypt::_encryptdecrypt(false, $token, 'aa-sul'.AA_ID.date('j.n.y H',strtotime("-$i hour"))))) {
                return unserialize($serialized);
            }
        }
        return '';
    }

    function _encryptdecrypt( $mode_encrypt, $text, $key) {
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

/** Decrypts the text using $key as password (mcrypt PHP extension must be installed)
 */
class AA_Stringexpand_Decrypt extends AA_Stringexpand {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    function expand($text, $key) {
        return AA_Stringexpand_Encrypt::_encryptdecrypt(false, $text, $key);
    }
}

/** computes MD5 hash */
class AA_Stringexpand_Md5 extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    function expand($text='') {
        return hash('md5', $text);
    }
}

/** crypt text as AA password */
class AA_Stringexpand_Pwdcrypt extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters (maybe we can?) */
    function doTrimParams() { return false; }

    function expand($text) {
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

    function expand($id, $cmd, $r, $c, $val='', $param='') {
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
 */
class AA_Stringexpand_Array extends AA_Stringexpand_Nevercache {
    /** Do not trim all parameters ($val and $param could begin with space) */
    function doTrimParams() { return false; }

    function expand($id, $cmd, $par1=null, $par2=null, $par3=null) {
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
    function expand($url='') {
        if (!empty($url)) {
            go_url($url, '', false, 301);  // 301 Moved Permanently
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
    function expand($item_id=null) {
        return (guesstype($item_id) != 'l') ? '' : AA_ChangesMonitor::singleton()->lastChanged($item_id);
    }
}

class AA_Stringexpand_Changedate extends AA_Stringexpand {
    function expand($item_id=null, $field_id=null, $format=null) {
        $time = ((guesstype($item_id) != 'l') OR !$field_id) ? '0' : (string)AA_ChangesMonitor::singleton()->lastChangeDate($item_id,$field_id);
        return AA_Stringexpand_Date::expand($format, $time, '--');
    }
}

/**
 * {header}
 */
class AA_Stringexpand_Header extends AA_Stringexpand {
    function expand($header=null) {
        switch ($header) {
        case '404': header('HTTP/1.0 404 Not Found');
                    break;
        case 'xml': header('Content-Type: text/xml');
                    break;
        }
        return '';
    }
}


class AA_Password_Manager_Reader {

    const KEY_TIMEOUT = 150;

    function getFirstForm() {  // Type in either your username or e-mail
        return '<form id="pwdmanager-firstform" action="" method="post"><div class="aa-widget">
        <label for="pwdmanager-user">' ._m('Forgot your password? Fill in your email.'). '</label>
        <div class="aa-input">
           <input size="30" maxlength="128" name="aapwd1" id="aapwd1" value="" placeholder="'._m('e-mail').'" required type="email">
        </div>
        <input type="hidden" name="nocache" value="1">
        <input type="submit" id="pwdmanager-send" name="pwdmanager-send" value="'. _m('Send').'">
        </div>
        </form>
        ';
    }

    function askForMail($user, $slice_id,$from_email) {
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

        //$GLOBALS['debug']=1;
        //AA::$debug = true;

        if ($template_id = DB_AA::select1('SELECT id FROM `email`', 'id', array(array('owner_module_id',$slice_id, 'l'), array('type','password change')))) {
            $slice     = AA_Slices::getSlice($slice_id);
            $user_item = new AA_Item($user_info, $slice->aliases( array("_#PWD_LINK" => GetAliasDef( "f_t:$pwd_link", "", _m('Password link')))));

            //huhl($user_item);

            AA_Mail::sendTemplate($template_id, array($email), $user_item, false);
        } else {
            $mail     = new AA_Mail;
            $mail->setSubject(_m("Password change"));
            $body = _m("To change the password, please visit the following address:<br>%1<br>Change will be possible for two hours - otherwise the key will expire and you will need to request a new one.",array("<a href=\"$url\">$url</a>"));
            $mail->setHtml($body, html2text($body));
            $mail->setHeader("From", $from_email);
            $mail->setHeader("Reply-To", $from_email);
            $mail->setHeader("Errors-To", $from_email);
            //$mail->setCharset ($GLOBALS ["LANGUAGE_CHARSETS"][substr ($db->f("lang_file"),0,2)]);
            $mail->send(array($email));
        }

        //huhl($template_id, ',', $slice_id );

        return self::_ok(_m('E-mail with a key to change the password has just been sent to the e-mail address: %1', array($email)));
    }

    function getChangeForm($key, $user) {
        if (!self::isValidKey($key, $user)) {
            return self::_bad(_m("La clave ha caducado."));  // @todo get messages from somewhere
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

    function changePassword( $pwd1, $pwd2, $key, $user, $from_email) {
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

    function _bad($text) {
        return '<div class="aa-err">'.$text.'</div>'.AA_Password_Manager_Reader::getFirstForm();
    }
    function _ok($text) {
        return '<div class="aa-ok">'.$text.'</div>';
    }
}

/** manages forgotten password
 *  The idea is, that this alias will manage all tasks needed for change of pwd
 *  you just put the {changepwd:<reader_slice_id>:<some other parameter>}
 */
class AA_Stringexpand_Changepwd  extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $reader_slice_id - reader module id
     */
    function expand($reader_slice_id, $from_email='') {
        $from_email = $from_email ? $from_email : ERROR_REPORTING_EMAIL;

        if (isset($_POST['aapwd3'])) {    // CHange Password
            return AA_Password_Manager_Reader::changePassword($_POST['aapwd3'], $_POST['aapwd3b'], $_POST['aakey'], $_POST['aauser'],$from_email);
        } elseif (isset($_GET['aapwd2'])) {
            list($key, $user) = explode('-',$_GET['aapwd2']);
            return AA_Password_Manager_Reader::getChangeForm($key, $user);
        } elseif (isset($_REQUEST['aapwd1'])) {        // CHeck User
            return AA_Password_Manager_Reader::askForMail($_REQUEST['aapwd1'], $reader_slice_id, $from_email);
        } else {
            return AA_Password_Manager_Reader::getFirstForm();
        }
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
     * @param $string    - XML or HTML string (possibly loaded with {include:<url>})
     * @param $query     - XPath query - @see XPath documentation
     * @param $attr      - if empty, the <text> value of the matching element is returned
     *                     if specified, then the attribute is returned (like width attribute of image in third example above)
     *                     use 'XML' if you want to get the whole inner HTML
     * @param $delimiter - by default, it returns just first matching value.
     *                     If specified, then all matching texts are returned delimited by <delimiter>
     */
    function expand($string="", $query='', $attr='', $delimiter='AA_PrintJustFirst') {
        $doc = new DOMDocument();
        if (!$doc->loadHTML($string) OR !$query) {
            return 'Error parsing';
        }
        $xpath = new DOMXPath($doc);

        $entries = $xpath->query($query);
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
 *    {foreach:{qs:myfields:-}:{(<td>{_#1}</td>)}}  //fields[] = headline........-year...........1 - returns <td>Prague<td><td>2012</td>
 *    {foreach:{changed:{_#ITEM_ID_}}:{( - {field:_#1:name:81294238c1ea645f7eb95ccb301063e4} <br>)}}
 *    {foreach:2011-2012:{(<li><a href="?year=_#1" {ifeq:_##1:{qs:year}:class="active"}>_#1</a></li>)}}
 */
class AA_Stringexpand_Foreach extends AA_Stringexpand_Nevercache {

    /** Do not trim all parameters ($outputdelimiter could begin with space) */
    function doTrimParams() { return false; }

    // not needed right now for Nevercached functions, but who knows in the future
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    function expand($values='', $text='', $valdelimiter='', $outputdelimiter='') {
        // _##1 is the way, how to use parameter from the outside of if:
        // {foreach:2011-2012:<li><a href="?year=_#1" {ifeq:_##1:{qs:year}:class="active"}>_#1</a></li>}
        $text = str_replace('_##1', '_#1', $text);

        $item   = $this ? $this->item : null;
        if (!strlen($valdelimiter)) {
           $valdelimiter = '-';
        }
        $arr = ($valdelimiter == 'json') ? json2arr(trim($values)) : explode($valdelimiter, trim($values));
        $ret= array();
        foreach($arr as $str) {
            if (strlen(trim($str))) {
                $ret[] = AA_Stringexpand::unalias(str_replace('_#1',$str,$text),'',$item);
            }
        }
        return join($outputdelimiter, $ret);
    }
}


/** Sends e-mail conditionaly
 *  Be careful - it can send mail on every page load!
 *  Use as:
 *    {mail:1:honza.malik@ecn.cz:test mail:{view:24}:utf-8:actionapps@ecn.cz}
 */
class AA_Stringexpand_Mail extends AA_Stringexpand_Nevercache {

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
        return $mail->sendLater($to);
    }
}

/** Sends e-mail conditionaly
 *  Be careful - it can send mail on every page load!
 *  Use as:
 *    {mailform:<to>:<subject>:<html-inputs>:<ok-text>:<body>:<lang>:<from>}
 *    {mailform:honza.malik@ecn.cz:test mail:Your note <input name="note">:You posted<br>_#1<br>Regards<br>ActionApps:utf-8:actionapps@ecn.cz}
 */
class AA_Stringexpand_Mailform extends AA_Stringexpand_Nevercache {

    function expand($to='', $subject='', $html='', $body='', $ok='', $lang='', $from='') {

        $config_arr = array('to' => $to,
                            'subject' => $subject,
                            'body' => $body,
                            'ok' => $ok,
                            'lang' => $lang,
                            'from' => $from );
        $form_id  = 'form'.new_id();
        $mailconf = AA_Stringexpand_Encrypt::get_time_token($config_arr);

        $ret = "<form id=\"$form_id\" onsubmit=\"AA_AjaxSendForm('$form_id', '/mail.php'); return false;\">
        <input type=hidden name=aa_mailconf value=\"$mailconf\">
        <style type=\"text/css\">
           div.skryto { display:none };
        </style>
        <div class=\"skryto\"><input type=\"text\" name=\"answer\" value=\"\"></div>
        $html
        </form>
        ";
        return $ret;
    }
}

/** Rotates on one place in the page different contents (divs) with specified interval
 *  {rotator:<item-ids>:<html-code>:<interval>}
 *  {rotator:{ids:a24657bf895242c762607714dd91ed1e}:_#FOTO_S__<div>_#HEADLINE</div>}
 *  @param speed:  '' | slow | fast
 *  @param effect: '' | fade
 */
class AA_Stringexpand_Rotator extends AA_Stringexpand_Nevercache {
    function expand($ids='', $code='', $interval='', $speed='', $effect='') {
        $frames = array();
        $zids = new zids(explode('-', $ids));
        if ( $zids->count() <= 0 ) {
            return '';
        }

        $interval   = (int)$interval ? (int)$interval : 3000;
        $extrastyle = ($effect == 'fade') ? 'position:absolute;' : '';
        $showfirst  = '';

        $items = AA_Items::getItems($zids);
        foreach($items as $long_id=>$item) {
            $frame = trim(AA_Stringexpand::unalias($code, '', $item));
            if ($frame) {
                $frames[]  = "<div class=rot-hide style=\"$showfirst $extrastyle\">$frame</div>";
                $showfirst = 'display:none;';
            }
        }
        if (!count($frames)) {
            return '';
        }

        $extrahightdiv = ($effect == 'fade') ? "<div class=rot-hight style=\"visibility:hidden\">$frame</div>" : '';

        $div_id = 'rot'.get_hash($ids, $code, $interval, $speed, $effect);
        return "<div id=\"$div_id\" style=\"position:relative\"'>".join("\n",$frames).$extrahightdiv."</div><script>AA_Rotator('$div_id', $interval, ".count($frames).", '$speed', '$effect');</script>";
    }
}

/** Recounts all computed field in the specified item (or dash separated items)
 *    {recompute:<item_ids>}
 */
class AA_Stringexpand_Recompute extends AA_Stringexpand_Nevercache {

    function expand($item_ids='', $fields_ids='') {

        $item_arr  = explode('-',$item_ids);
        $field_arr = strlen($fields_ids) ? explode('-', $fields_ids) : array();

        foreach ($item_arr as $iid) {
            if (!($iid = trim($iid))) {
                continue;
            }
            $item = new ItemContent($iid);
            $item->updateComputedFields($iid, null, 'update', $field_arr);
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
    function expand($item_ids='', $count='', $alias='', $count_field='') {
        $alias       = get_if($alias, '_#HEADLINK');
        $count_field = get_if($count_field, '{count:{backlinks:{id..............}::-}}');
        $results     = array();

        $items = AA_Items::getItems(new zids(explode('-',$item_ids)));
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
 * @param field_id - id of the field in item
 * @param index    - integer index in multivalue array - default 0 (the first one)
 * @param item_id
 * @param lang     - if you want exact language for translated field, specify it - cz / es / en / ...
 *
 */
class AA_Stringexpand_Index extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    // not needed right now for Nevercached functions, but who knows in the future
    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
     */
    function expand($field_id='', $index=0, $item_id='', $lang='') {
        $item = $item_id ? AA_Items::getItem(new zids($item_id)) : ($this ? $this->item : null);
        return (is_object($item) AND $item->isField($field_id)) ? $item->getval($field_id, (int)$index + ($lang ? AA_Content::getLangNumber($lang) : 0)) : '';
    }
}

class AA_Stringexpand_Form extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function
     * @param $text
     */
    function expand($form_id='') {
        if (!$form_id OR !($form = AA_Object::load($form_id, 'AA_Form'))) {
            return '';
        }
        //return $form->getAjaxHtml($ret_code);
        return $form->getAjaxHtml();
    }
}

/** Translation */
class AA_Stringexpand_Tr extends AA_Stringexpand {

    /** expand function
     * @param $text
     */
    function expand($text='') {
        if ($site = AA_Module_Site::getModule(AA::$site_id)) {
            if ($translate_slice = $site->getProperty('translate_slice')) {
                $set  = new AA_Set(array($translate_slice), new AA_Condition('headline........', '==', $text));
                $zids = $set->query();

                if ($zids->count()) {
                    return AA_Items::getItem($zids[0])->f_2('headline........');
                }
                // if not present - translate to default language of the slice
                $translations = AA_Slices::getSliceProperty($translate_slice, 'translations');

                $ic = new ItemContent();
                $ic->setValue('headline........', $text, AA_Content::getLangNumber($translations[0]));
                $ic->setSliceID($translate_slice);
                //$ic->complete4Insert();

                $ic->storeItem('insert');     // invalidatecache, feed
            }
        }

        return $text;
    }
}

/** Validate */
class AA_Stringexpand_Validate extends AA_Stringexpand {

    function additionalCacheParam() {
        /** output is different for different items - place item id into cache search */
        return !is_object($this->item) ? '' : $this->item->getId();
    }

    /** expand function
     * @param $text
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

?>
