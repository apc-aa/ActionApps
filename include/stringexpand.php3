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

/** translateString function
 * @param $string
 * @param $translation
 */
function translateString( $string, $translation ) {
    $twos      = ParamExplode( $translation );
    $i         = 0;
    $twoscount = count($twos);

    while ( $i < $twoscount ) {
        if ( $i == ($twoscount-1)) {                // default option
            return $twos[$i];
        }
        $val = trim($twos[$i]);
        // Note you can't use !$val, since this will match a pattern of exactly "0"
        if ( ($val=="") OR ereg($val, $string) ) {    // Note that $string, might be expanded {headline.......} or {m}
            return $twos[$i+1];
        }
        $i+=2;
    }
    return "";
}
/** parseSwitch function
 * @param $text
 */
function parseSwitch($text) {
    $condition = substr(strtok('_'.$text,")"),1); // add and remove '_' - this
    // is hack for empty condition
    // (when $text begins with ')')
    $condition = DeQuoteColons($condition);	// If expanded, will be quoted ()
    $ret       = translateString( $condition, strtok("") );
    return str_replace('_#1', $condition, $ret);
}

/** Expands {user:xxxxxx} alias - auth user informations (of current user)
 *   @param $field - field to show ('headline........', 'alerts1....BWaFs' ...).
 *                   empty for username (of curent logged user)
 *                   'password' for plain text password of current user
 *                   'permission'
 *                   'role'
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
        switch ($field = trim($field)) {
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
    // Never cached (extends AA_Stringexpand_Nevercache)
    // cache is used by expand function itself

    function expand($name, $expression) {
        global $contentcache;
        $contentcache->set("define:$name", $expression);
        return "";
    }
}

/** Prints defined named expression. Used with conjunction {define:..}
 *  @see AA_Stringexpand_Define for more info
 *  @param $parameters - field id and other modifiers to the field
 */
class AA_Stringexpand_Var extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // cache is used by expand function itself

    function expand() {
        global $contentcache;
        $arg_list = func_get_args();   // must be asssigned to the variable
        // replace inputform field
        $expression = $contentcache->get('define:'.$arg_list[0]);
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
        $linkpart    = explode('@', $email);
        $mailprotect = "'".$linkpart[0]."'+'@'+'".$linkpart[1]."'";
        $linktext    = ($text=='') ? $mailprotect : "'".str_replace("'", "\'", $text)."'";
        $ret = "<script type=\"text/javascript\">document.write('<a href=\"mai'+'lto:'+$mailprotect+'\">'+$linktext+'<\/a>')</script>";
        return $ret;
    }
}

/** Expands {lastedit:[<date_format>:[<slice_id>]]} and displays the date of
 *  last modificaton of any item in the slice
 *  the user.
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


/** Expands {htmltoggle:<toggle1>:<text1>:<toggle2>:<text2>} like:
 *          {htmltoggle:more >>>:Econnect:less <<<:Econnect is ISP for NGOs...}
 *  It creates the link text1 (or text2) and two divs, where only one is visible
 *  at the time
 *  The /javscript/aajslib.php3 shoud be included to the page
 *  (by <script src="">)
 *  @param $switch_state_1 - default link text
 *  @param $code_1         - HTML code displayed as default (in div)
 *  @param $switch_state_2 - link text 2
 *  @param $code_2         - HTML code displayed as alternative after clicking
 *                           on the link
 */
class AA_Stringexpand_Htmltoggle extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    function expand($switch_state_1, $code_1, $switch_state_2, $code_2) {

        // it is nonsense to show expandable trigger if both contents are empty
        if (trim($code_1.$code_2) == '') {
            return '';
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switch_state_1_js = str_replace(array("'", '"'), array("\'", "\'"), $switch_state_1);
        $switch_state_2_js = str_replace(array("'", '"'), array("\'", "\'"), $switch_state_2);

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()

        if ($code_1 == $code_2) {
            // no need to addf toggle
            $ret = "<div class=\"toggleclass\" id=\"toggle_1_$uniqid\">$code_1</div>\n";
        } else {
            $ret    = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlToggle('toggle_link_$uniqid', '$switch_state_1_js', 'toggle_1_$uniqid', '$switch_state_2_js', 'toggle_2_$uniqid');return false;\">$switch_state_1</a>\n";
            $ret   .= "<div class=\"toggleclass\" id=\"toggle_1_$uniqid\">$code_1</div>\n";
            $ret   .= "<div class=\"toggleclass\" id=\"toggle_2_$uniqid\" style=\"display:none;\">$code_2</div>\n";
        }
        return $ret;
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
        if (trim($css_rule) == '') {
            return '';
        }

        $class = '';
        $switch_state_1_js = str_replace(array("'", '"'), array("\'", "\'"), $switch_state_1);
        $switch_state_2_js = str_replace(array("'", '"'), array("\'", "\'"), $switch_state_2);

        // swap var
        if ($is_on == 1) {
            $class = ' is-on';
            $switch_state_1    = $switch_state_2;
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()

        $ret    = "<a class=\"togglelink$class\" id=\"toggle_link_$uniqid\" $class href=\"#\" onclick=\"AA_HtmlToggleCss('toggle_link_$uniqid', '$switch_state_1_js', '$switch_state_2_js', '$css_rule');return false;\">$switch_state_1</a>\n";
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
        if (trim($css_rule_hide) == '') {
            return '';
        }

        if (trim($css_rule_update) == '') {
            $css_rule_update = $css_rule_hide;
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switch_state_1_js = str_replace(array("'", '"'), array("\'", "\'"), $switch_state_1);
        $switch_state_2_js = str_replace(array("'", '"'), array("\'", "\'"), $switch_state_2);

        // automaticaly add conversion to utf-8 for AA view.php3 calls
        if ((strpos($url_of_text,'/view.php3?') !== false) AND (strpos($url_of_text,'convert')===false)) {
            $url_of_text = get_url($url_of_text,array('convertto' => 'utf-8'));
        }

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $ret   = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlAjaxToggleCss('toggle_link_$uniqid', '$switch_state_1_js', '$switch_state_2_js', '$css_rule_hide', '$url_of_text', '$css_rule_update');return false;\">$switch_state_1</a>\n";
        return $ret;
    }
}

/** Expands {shorten:<text>:<length>[:<mode>]} like:
 *          {shorten:{abstract.......1}:150}
 *  @return up to <length> characters from the <text>. If the <mode> is 1
 *  then it tries to identify only first paragraph or at least stop at the end
 *  of sentence. In all cases it strips HTML tags
 *  @param $text           - the shortened text
 *  @param $length         - max length
 *  @param $mode           - 1 - try cut whole paragraph
 *                         - 0 - just cut on length
 */
class AA_Stringexpand_Shorten extends AA_Stringexpand_Nevercache {
    // Never cache this code, since we need unique divs with uniqid()

    function expand($text, $length, $mode=1) {
        $shorted_text = substr($text, 0, $length);
        $shorted_len  = strlen($shorted_text);

        // search the text for following ocurrences in the order!
        $PARAGRAPH_ENDS = array( '</p>','<p>');
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
        return strip_tags( $shorted_text );
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

    function expand($text, $length, $add='', $more='', $less='') {
        // it is nonsense to show expandable trigger if both contents are empty
        if (trim($text) == '') {
            return '';
        }

        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $more_js = str_replace(array("'", '"'), array("\'", "\'"), $more);
        $less_js = str_replace(array("'", '"'), array("\'", "\'"), $less);

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $length = (int)$length;

        if (strlen($text)<=$length) {
            $ret = "<div class=\"expandableclass\" id=\"expandable_1_$uniqid\">$text</div>\n";
        } else {
            $text_2 = AA_Stringexpand_Shorten::expand($text, $length);
            $link_1 = "<a class=\"expandablelink\" id=\"expandable_link1_$uniqid\" href=\"#\" onclick=\"AA_HtmlToggle('expandable_link1_$uniqid', '', 'expandable_1_$uniqid', '$more_js', 'expandable_2_$uniqid');return false;\">$more_js</a>\n";
            $link_2 = !$less_js ? '' : "<a class=\"expandablelink\" id=\"expandable_link2_$uniqid\" href=\"#\" onclick=\"AA_HtmlToggle('expandable_link2_$uniqid', '', 'expandable_2_$uniqid', '$less_js', 'expandable_1_$uniqid');return false;\">$less_js</a>\n";
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
        // we can't use apostrophes and quotes in href="javacript:..." attribute
        $switch_state_1_js = str_replace(array("'", '"'), array("\'", "\'"), $switch_state_1);
        $switch_state_2_js = str_replace(array("'", '"'), array("\'", "\'"), $switch_state_2);

        // automaticaly add conversion to utf-8 for AA view.php3 calls
        if ((strpos($url,'/view.php3?') !== false) AND (strpos($url,'convert')===false)) {
            $url = get_url($url,array('convertto' => 'utf-8'));
        }

        $uniqid = mt_rand(100000000,999999999);  // mt_rand is quicker than uniqid()
        $link   = "<a class=\"togglelink\" id=\"toggle_link_$uniqid\" href=\"#\" onclick=\"AA_HtmlAjaxToggle('toggle_link_$uniqid', '$switch_state_1_js', 'toggle_1_$uniqid', '$switch_state_2_js', 'toggle_2_$uniqid', '$url');return false;\">$switch_state_1</a>\n";
        $ret    = "<div class=\"toggleclass\" id=\"toggle_1_$uniqid\">$code_1</div>\n";
        $ret   .= "<div class=\"toggleclass\" id=\"toggle_2_$uniqid\" style=\"display:none;\"></div>\n";
        return ($position=='bottom') ?  $ret. $link : $link. $ret;
    }
}

function calculate($exp) {
    $exp = str_replace(array(' ',"\t", ',', '(+', '(-', '(*', '(/', '+)', '-)', '*)', '/)', '()') ,array('', '', '.', '(0+', '(0-', '(0*', '(0/', '+0)', '-0)', '*0)', '/0)', '0'), "($exp)");
    if (strspn($exp, '0123456789.+-*/()') != strlen($exp)) {
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
                $val    = number_format($val, $format[0], $format[1], $format[2]);
            }
            $ret .= $val;
            $key  = true;
        }
        $i++;
    }
    return $ret;
}

/** parseLoop function
 *  - in loop writes out values from field
 * @param $out
 * @param $item
 */
function parseLoop($out, &$item) {
    global $contentcache;

    if ( !is_object($item) ) {
        return '';
    }


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
            $param  = substr($field, strpos($field, "(")+1,strpos($field, ")")-strpos($field, "(")-1);
            $params = explode(",",$param);
            // field name
            $field    = substr($field, 0, strpos($field, "("));
            $group_id = getConstantsGroupID($item->getval("slice_id........"), $field);
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
        $val = $itemcontent->getValues($field);
    }

    if ( empty($val) ) {
        return '';
    }

    if (!$format_str) { // we don't have format string, so we return
        // separated values by $separator (default is ", ")
        foreach ($val as $value) {
            $ret_str = $ret_str . ($ret_str ? $separator : "") . $value['value'];
        }
    } else { // we have format string
        if ( !is_array($params) ) {
            // case if we have only one parameter for substitution
            $val_delim = '';
            foreach ($val as $value) {
                $dummy     = str_replace("_#1", $value['value'], $format_str);
                $ret_str   = $ret_str . $val_delim . $dummy;
                $val_delim = $separator;
            }
        } else {
            $val_delim = '';
            // case with special parameters in ()
            foreach ($val as $value) { // loop for all values
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
                $ret_str   = $ret_str . $val_delim . $dummy;
                $val_delim = $separator;
            }
        }
    }
    return $ret_str;
}

/** getConstantsGroupID function
 *  @return group id for specified field
 * @param $slice_id
 * @param $field
 */
function getConstantsGroupID($slice_id, $field) {
    global $contentcache;
    // get constant group_id from content cache or get it from db
    $zids    = new zids($slice_id, "p");
    $long_id = $zids->longids();
    // GetCategoryGroup looks in database - there is a good chance, we will
    // expand {const_*} very soon (again), so we cache the result for future
    $group_id = $contentcache->get_result("GetCategoryGroup", array($long_id[0], $field));
    // get values from contentcache or use GetConstants function to get it from db
    // $val = $contentcache->get_result("GetConstants", array($group_id, "pri", "value"));
    return $group_id;
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
            $val = $contentcache->get_result("GetConstants", array($group, "pri", $what));
            return $val[$field_name];
            break;
        default :
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
    global $UNQUOTED_ARRAY, $QUOTED_ARRAY;
    return str_replace($GLOBALS['UNQUOTED_ARRAY'], $GLOBALS['QUOTED_ARRAY'], $text);
}


/** DeQuoteColons function
 *  Substitutes special AA 'colon' string back to colon ':' character
 *  Used for parameters, where is no need colons are not parameter separators
 * @param $text
 */
function DeQuoteColons($text) {
    global $UNQUOTED_ARRAY, $QUOTED_ARRAY;
    return str_replace($GLOBALS['QUOTED_ARRAY'], $GLOBALS['UNQUOTED_ARRAY'], $text);
}

/*
// In this array are set functions from PHP or elsewhere that can usefully go in {xxx:yyy:zzz} syntax
$GLOBALS[eb_functions] = array (
    fmod => fmod,    // php > 4.2.0
    substr => substr
);
*/

class AA_Stringexpand_Fmod extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $x
     * @param $y
     */
    function expand($x,$y) {
        return fmod($x,$y);
    }
}

class AA_Stringexpand_Cookie extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $name
     */
    function expand($name) {
        return $_COOKIE[$name];
    }
}

/** Evaluates the expression
 *    {math:<expression>[:<decimals>[:<decimal point character>:<thousands separator>]]}
 *    {math:1+1-(2*6)}
 *    {math:478778:0:,: }
 */
class AA_Stringexpand_Math extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($expression='', $decimals='', $dec_point='', $thousands_sep = '') {
        $ret      = calculate($expression);
//        huhl($expression, $decimals, $ret);
        if ( !empty($dec_point) OR !empty($thousands_sep) ) {
            $decimals      = get_if($decimals,0);
            $dec_point     = get_if($dec_point, ',');
            $ret = number_format($ret, $decimals, $dec_point, $thousands_sep);
        } elseif ($decimals !== '') {
            $decimals = get_if($decimals,0);
            $ret      = number_format($ret, $decimals);
        }
        return $ret;
    }
}

/** Maximum number from list - number of values is unlimited */
class AA_Stringexpand_Max extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $values - unlimited number of compared values
     */
    function expand() {
        $arg_list  = func_get_args();   // must be asssigned to the variable
        return max($arg_list);
    }
}

/** Maximum number from list - number of values is unlimited */
class AA_Stringexpand_Min extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $values - unlimited number of compared values
     */
    function expand() {
        $arg_list  = func_get_args();   // must be asssigned to the variable
        return max($arg_list);
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
        return join(array_intersect(explode('-',$set1),explode('-',$set2)),'-');
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
 *
 *   @param $format      - format - the same as PHP date() function
 *   @param $timestamp   - timestamp of the date (if not specified, current time
 *                         is used)
 */
class AA_Stringexpand_Date extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function  */
    function expand($format='', $timestamp='') {
        if ( empty($format) ) {
            $format = "U";
        }
        if ( empty($timestamp) ) {
            $timestamp = time();
        }
        return date($format, (int)$timestamp);
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


class AA_Stringexpand_Rand extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $min
     * @param $max
     */
    function expand($min,$max) {
        return rand($min, $max);
    }
}


class AA_Stringexpand_Substr extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $string
     * @param $start
     * @param $length
     * @param $add
     */
    function expand($string,$start,$length=999999999,$add='') {
        $ret = substr($string,$start,$length);
        if ( $add AND (strlen($ret) < strlen($string)) ) {
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

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids        // parts separated by $delimiter
     * @param $offset     // start index (first is 0). Could be negative.
     * @param $length     // default is "to the end of the list". Colud be negative
     * @param $delimiter  // default is '-'
     */
    function expand($ids, $offset, $length='', $delimiter='-') {
        $arr = explode($delimiter, $ids);
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
        if (!trim($ids) OR !trim($current_id)) {
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

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids        // item ids separated by '-' (long or short)
     * @param $delimiter  // separator of the parts - by default it is '-', but
     *                       you can use any one
     */
    function expand($ids='', $delimiter='') {
        if (!trim($ids)) {
            return '';
        }
        if (empty($delimiter)) {
            $delimeter = '-';
        }
        return join($delimeter, array_unique(explode($delimeter, $ids)));
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

    /** for offset and length parameters see PHP function array_slice()
     * @param $ids        // item ids separated by '-' (long or short)
     * @param $delimiter  // separator of the parts - by default it is '-', but
     *                       you can use any one
     */
    function expand($ids='', $delimiter='') {
        if (!trim($ids)) {
            return 0;
        }
        if (empty($delimiter)) {
            $delimeter = '-';
        }
        return count(explode($delimeter, $ids));
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
        if (!trim($ids) OR !trim($current_id)) {
            return '';
        }
        $arr = explode('-', $ids);
        $key = array_search($current_id, $arr);
        return ($key AND isset($arr[$key-1])) ? $arr[$key-1] : '';
    }
}

class AA_Stringexpand_Strlen extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $string
     */
    function expand($string) {
        return strlen($string);
    }
}

class AA_Stringexpand_Trim extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $string
     */
    function expand($string='') {
        return trim($string);
    }
}

/** Repeats the $string n-$times  */
class AA_Stringexpand_Str_Repeat extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $string
     * @param $times
     */
    function expand($string='', $times='') {
        return str_repeat($string, $times);
    }
}

class AA_Stringexpand_Str_Replace extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $search
     * @param $replace
     * @param $subject
     */
    function expand($search, $replace, $subject) {
        return str_replace($search, $replace, $subject);
    }
}

// Use this inside URLs e.g. {urlencode:_#EDITITEM}
class AA_Stringexpand_Urlencode extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     */
    function expand($text='') {
        return urlencode($text);
    }
}

class AA_Stringexpand_Csv extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     */
    function expand($text='') {
        return (strcspn($text,",\"\n\r") == strlen($text)) ? $text : '"'.str_replace('"', '""', str_replace("\r\n", "\n", $text)).'"';
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

    /** expand function
     * @param $text
     */
    function expand($text='') {
        $params = func_get_args();
        $item   = $this ? $this->item : null;
        return ($this AND is_object($item) AND $item->isField($text)) ? $item->getval($text) : join(':', $params);
    }
}

class AA_Stringexpand_Safe extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     */
    function expand($text='') {
        return htmlspecialchars($text);
    }
}

/** Escape apostrophs and convert HTML entities */
class AA_Stringexpand_Javascript extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     */
    function expand($text='') {
        return str_replace("'", "\'", safe($text));
    }
}

/** Just escape apostrophs ' => \' */
class AA_Stringexpand_Quote extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     */
    function expand($text='') {
        return str_replace("'", "\'", str_replace('\\', '\\\\', $text));
    }
}

/** Encodes string for JSON - apostrophs ' => \', ... */
class AA_Stringexpand_Jsonstring extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     */
    function expand($text='') {
        return json_encode($text);
    }
}

class AA_Stringexpand_Striptags extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     */
    function expand($text='') {
        return strip_tags($text);
    }
}

class AA_Stringexpand_Rss extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $text
     */
    function expand($text='') {
        $entities_old = array('&nbsp;', '& ');
        $entities_new = array(' ', '&amp; ');
        return str_replace($entities_old, $entities_new, strip_tags($text));
    }
}

class AA_Stringexpand_Convert extends AA_Stringexpand {
    /** expand function
     * @param $text
     */
    function expand($text, $from, $to) {
        require_once AA_INC_PATH."convert_charset.class.php3";
        $encoder = new ConvertCharset;
        return $encoder->Convert($text, $from, $to);
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

class AA_Stringexpand_View extends AA_Stringexpand {
    /** expand function
     * @param $vid, $ids
     */
    function expand($vid, $ids=null, $settings=null) {
        $view_param['vid'] = $vid;
        if (isset($ids)) {
            $zids = new zids();
            $zids->addDirty(explode('-',$ids));
            if ($zids->count()>0) {
                $view_param['zids'] = $zids;
            }
        }
        if (isset($settings)) {
            $view_param = array_merge($view_param, ParseSettings($settings));
        }
        // do not pagecache the view
        $foo = '';
        return GetViewFromDB($view_param, $foo);
    }
}

/** displays current poll from polls module specified by its pid */
class AA_Stringexpand_Polls extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $pid
     */
    function expand($pid) {
        require_once AA_BASE_PATH."modules/polls/include/util.php3";
        require_once AA_BASE_PATH."modules/polls/include/stringexpand.php3";
        require_once AA_BASE_PATH."modules/polls/include/poll.class.php3";

        $set       = AA_Poll::generateSet($pid);
        $poll_zids = AA_Metabase::queryZids(array('table'=>'polls'), $set);

        $poll      = AA_Polls::getPoll($poll_zids->id(0));
        return $poll ? $poll->getOutput('beforevote') : '';
    }
}



/** Allows you to call view with conds:
 *    {view.php3?vid=9&cmd[9]=c-1-{conds:{_#VALUE___}}}
 *  or
 *    {view.php3?vid=9&cmd[9]=c-1-{conds:category.......1}}
 *  or
 *    {ids:5367e68a88b82887baac311c30544a71:d-headline........-=-{conds:category.......3:1}}
 *    see the third parameter (1) in the last example!
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
            return AA_Stringexpand_Conds::_textToConds($text, $no_url_encode);
        }

        $values = $this->item->getValuesArray($text);
        if (empty($values)) {
            return 'AA_NeVeRtRuE';
        }
        $ret = $delim = '';
        foreach ( $values as $val ) {
            if ( empty($val) ) {
                continue;
            }
            $ret  .= $delim. AA_Stringexpand_Conds::_textToConds($val, $no_url_encode);
            $delim = $no_url_encode ? ' OR ' : '%20OR%20';
        }
        return empty($ret) ? 'AA_NeVeRtRuE' : $ret;
    }

    /** Static */
    function _textToConds($text, $no_url_encode) {
        if ($no_url_encode) {
            return '"'. str_replace(array('-','"'), array('--','\"'), $text) .'"';
        }
        return '%22'. str_replace(array('-',' '), array('--','%20'), $text) .'%22';
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
class AA_Stringexpand_Item extends AA_Stringexpand {

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

        // sanity input
        $ids_string = preg_replace('/[^0-9a-g()-]/', '', strtolower($ids_string));  // mention the g (used for generated subrtree cache)
        $ids_string = str_replace('()', '', $ids_string);

        $tree_cache = new AA_Treecache($content, $delim, $top, $bottom);

        // we are looking for subtrees 93938(73737-64635)
        while (preg_match('/[0-9a-f]+[(]([^()]+)[)]/s',$ids_string)) {
            $ids_string = preg_replace_callback('/([0-9a-f]+)[(]([^()]+)[)]/s', array($tree_cache,'cache_list'), $ids_string);
        }

        return $tree_cache->get_concat($ids_string);
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
        $key = 'g'. md5($match[0]);

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
        return join($this->delim,$results);
    }

    function _get_item($item_id, $expression) {
        // cached subtree
        if ($item_id{0} == 'g') {
            return $this->_cache[$item_id];
        }

        $id_type = guesstype($item_id);
        if ( $item_id AND (($id_type == 's') OR ($id_type == 'l'))) {
            $item = AA_Items::getItem(new zids($item_id,$id_type));
            if ($item) {
                return $item->subst_alias($expression);
            }
        }
        return '';
    }
}

/** ids_string - ids (long or short (or mixed) separated by dash '-') */
class AA_Stringexpand_Aggregate extends AA_Stringexpand {
    /** expand function
     * @param $function
     * @param $ids_string
     * @param $expression
     * @param $parameter
     */
    function expand($function, $ids_string, $expression=null, $parameter=null) {
        if ( !in_array($function, array('sum', 'avg', 'concat', 'count')) ) {
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
                $ret = array_sum($results);
                break;
            case 'avg':
                array_walk($results, create_function('$a', 'return (float)$a;'));
                $ret = (count($results) > 0) ? array_sum($results)/count($results) : '';
                break;
            case 'concat':
                $ret = join($parameter,$results);
                break;
            case 'count':
                $ret = $count;
                break;
        }
        return $ret;
    }
}

/** returns ids of items based on conds d-...
 *  {ids:<slice>:<conds>[:<sort>[:<delimiter>[:<restrict_ids>[:<limit>]]]]}
 *  {ids:6a435236626262738348478463536272:d-category.......1-RLIKE-Bio-switch.........1-=-1:headine........-}
 *  returns dash separated long ids of items in selected slice where category
 *  begins with Bio and switch is 1 ordered by headline - descending
 */
class AA_Stringexpand_Ids extends AA_Stringexpand {
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
    function expand($item_id, $relation_field=null) {
        $zid     = new zids($item_id);
        $long_id = $zid->longids(0);
        if (empty($item_id)) {
            return '';
        }
        return join(AA_Trees::getIds($long_id, get_if($relation_field, 'relation........')), '-');
    }
}


/** @return string representation of the tree (with long ids) under specifield
 *          item based on the relation field
 *  @see {itree: } for more info about the stringtree syntax
 *  {treestring:<item_id>[:<relation_field>]}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1:1}
 *  {treestring:2a4352366262227383484784635362ab:relation.......1:1:sort[0][headline........]=a&sort[1][publish_date....]=d}
 */
class AA_Stringexpand_Treestring extends AA_Stringexpand {
    /** expand function
     * @param $item_id          - item id of the tree root (short or long)
     * @param $relation_field   - tree relation field (default relation........)
     * @param $reverse          - 1 for reverse trees (= child->parent relations)
     * @param $sort_string      - order of tree leaves (currently wors only for reverse trees. @todo)
     */
    function expand($item_id, $relation_field=null, $reverse=null, $sort_string=null) {
        $zid     = new zids($item_id);
        $long_id = $zid->longids(0);
        if (empty($item_id)) {
            return '';
        }
        if (empty($sort_string) OR !is_array($sort = String2Sort($sort_string))) {
            $sort = null;
        }

        return AA_Trees::getTreeString($long_id, get_if($relation_field, 'relation........'), $reverse=='1', $sort);
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
    function expand($slices, $seo_string) {
        if (trim($seo_string)=='') {
            return '';
        }
        $set  = new AA_Set(explode('-', $slices), new AA_Condition('seo.............', '=', '"'.$seo_string.'"'), null, AA_BIN_ACTIVE | AA_BIN_EXPIRED | AA_BIN_PENDING | AA_BIN_HOLDING);
        $zids = $set->query();
        return join($zids->longids(), '-');
        // added expiry date in order we can get ids also for expired items
        // return AA_Stringexpand_Ids::expand($slices, 'd-expiry_date.....->-0-seo.............-=-"'. str_replace('-', '--', $seo_string) .'"');
    }
}

/** returns seo name created from the string
 *  {seoname:<string>[:<unique_slices>[:<encoding>]]}
 *  {seoname:About Us:3aa35236626262738348478463536224:windows-1250}
 *  returns about-us
 *  If you specify the unique_slices parameter, then the id is created as unique
 *  for those slices. Slices are separated by dash
 *  Encoding parameter helps convert the name to acsii. You shoud write here
 *  the character encoding from the slice setting. The default is utf-8, but you
 *  can use any (windows-1250, iso-8859-2, iso-8859-1, ...)
 */
class AA_Stringexpand_Seoname extends AA_Stringexpand {
    /** expand function
     * @param $string
     * @param $unique_slices
     * @param $encoding
     */
    function expand($string, $unique_slices='', $encoding='') {
        require_once AA_INC_PATH."convert_charset.class.php3";
        $encoder = new ConvertCharset;
        $base = $encoder->Convert($string, empty($encoding) ? 'utf-8' : $encoding, 'us-ascii');
        $base = str_replace(' ','-',$base);
        $base = preg_replace('/[^\w-]/', '', $base);
        while ( strpos($base, '--') !== false ) {
            $base = str_replace('--', '-', $base);
        }
        // remove starting and ending dash
        $base = trim(strtolower($base), '-');
        // we do not want to have looooong urls
        if (strlen($base) > 124) {
            $base = substr($base, 0, 124);
            if (strrpos($base, '-') > 80) {
                // do not split in middle of the word
                $base = substr($base, 0, strrpos($base, '-'));
            }
        }

        $add = '';
        if ( !empty($unique_slices) ) {
            $i = 1;
            // we do not want to create infinitive loop for wrong parameters
            for ($i=2; $i < 100000; $i++) {
                $ids = AA_Stringexpand_Seo2ids::expand($unique_slices, $base.$add);
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

/** @returns name (or other field) of the constant in $gropup_id with $value
 *  Example: {constant:AA Core Bins:1:name}
 *           {constant:biom__categories:{@category........:|}:name:|:, }  // for multiple constants
 */
class AA_Stringexpand_Constant extends AA_Stringexpand {
    /** expand function
     * @param $group_id         - constants ID
     * @param $value            - constant value (or values delimited by $value_delimiter)
     * @param $what             - name|value|short_id|description|pri|group|class|id|level
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
            $ret[] = getConstantValue($group_id, $what, $constant);
        }
        return join($output_delimiter, $ret);
    }
}

/** {options:<group_id>:<selected>}
 *  @return html <option>s for given constant group with selected option
 */
class AA_Stringexpand_Options extends AA_Stringexpand {
    /** expand function
     * @param $group_id
     */
    function expand($group_id, $selected='') {
        $ret = '';
        $constants = GetConstants($group_id);
        if (is_array($constants)) {
            foreach ($constants as $k => $v) {
                $sel  = ((string)$k == (string)$selected) ? ' selected' : '';
                $ret .= "\n  <option value=\"".safe($k)."\"$sel>".safe($v)."</option>";
            }
        }
        return $ret;
    }
}


/** If $condition is filled by some text, then print $text. $text could contain
 *  _#1 alias for the condition, but you can use any {} AA expression.
 *  Example: {ifset:{img_height.....2}: height="_#1"}
 *  The $condition with undefined alias is considered as empty as well
 *    ($condition=_#.{8} (exactly) - like '_#HEADLINE')
 */
class AA_Stringexpand_Ifset extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $condition
     * @param $text
     * @param $else_text
     */
    function expand($condition, $text='', $else_text='') {
        return ((strlen($condition)<1) OR IsAlias($condition)) ? $else_text : str_replace('_#1', $condition, $text);
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
        $etalon   = array_shift($arg_list);
        $ret      = false;
        $i        = 0;
        while (isset($arg_list[$i]) AND isset($arg_list[$i+1])) {  // regular option-text pair
            if ($etalon == $arg_list[$i]) {
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
        $item = ($item_id=='current') ? $this->item : AA_Items::getItem(new zids($item_id));
        if (!empty($item) AND $item->isField($field)) {
            $ret = in_array($var, $item->getValuesArray($field)) ? $text : $else_text;
        }
        return str_replace(array('_#1'), array($var), $ret);
    }
}

/** If $haystack contain $needle text, then print $text, else print $else_text.
 *  $(else_)text could contain _#1 and _#2 aliases for $haystack and $needle
 *  Ussage:  {ifin:ActionApps CMS:CMS:yes:no}
 */
class AA_Stringexpand_Ifin extends AA_Stringexpand_Nevercache {

    /** expand function
     * @param $haystack
     * @param $needle
     * @param $text
     * @param $else_text
     */
    function expand($haystack, $needle, $text='', $else_text='') {
        $ret = (strpos($haystack, $needle) !== false) ? $text : $else_text;
        return str_replace(array('_#1','_#2'), array($haystack, $needle), $ret);
    }
}

/** Takes unlimited number of parameters and jioins the unempty ones into one
 *  string ussing first parameter as delimiter
 *  Example: {join:, :{_#YEAR____}:{_#SIZE____}:{_#SOURCE___}}
 */
class AA_Stringexpand_Join extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $condition
     * @param $text
     * @param $else_text
     */
    function expand() {
        $arg_list  = func_get_args();   // must be asssigned to the variable
        $delimiter = array_shift($arg_list);
        return join($delimiter, array_filter($arg_list, create_function('$str', 'return strlen(trim($str))>0;')));
    }
}


/** Expand URL by adding session
 *  Example: {sessurl:<url>}
 *  Example: {sessurl}           - returns session_id
 *  also handle special cases like {sessurl:hidden}
 *
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
        }
        return $sess->url($url);
    }
}

/** Compares two values -
 *  returns:  'L' if val1 is less than val2
 *            'G' if val1 is greater than val2
 *            'E' if they are equal
 *  ussage:  {switch({compare:{publish_date....}:{now}})G:greater:L:less:E:equal}
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

/** Get field property (currently only 'name' is supported */
class AA_Stringexpand_Field extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)

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
        if (empty($slice_id)) {
            if ( empty($this->item) ) {
                return '';
            }
            $slice_id = $this->item->getSliceID();
        }
        $slice = AA_Slices::getSlice($slice_id);
        if (!$slice OR !$slice->isField($field_id)) {
            return '';
        }

        $fields = $slice->getFields();
        // we do not want to allow users to get all field setting
        // that's why we restict it to the properties, which makes sense
        // @todo - make it less restrictive
        $property = 'name';
        return (string) $fields->getProperty($field_id, $property);
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
        if ($property == 'site_id') {
            return GetTable2Array("SELECT source_id FROM relation WHERE destination_id='".q_pack_id($slice_id)."' AND flag='".REL_FLAG_MODULE_DEPEND."'", "aa_first", 'unpack:source_id');
        }
        if (!AA_Fields::isSliceField($property)) {  // it is "slice field" (begins with underscore _)
            $property = 'name';
        }
        return $slice->getProperty($property);
    }
}

/** Get site module property
 *  (currently only "modules" - dash separated list of slices in the site)
 *  I use it for example for computing of seo name:
 *  {ifset:{seo.............}:_#1:{seoname:{_#HEADLINE}:{site:{modulefield:{_#SLICE_ID}:site_id}:modules}}}
 **/
class AA_Stringexpand_Site extends AA_Stringexpand {
    /** expand function
     * @param $property
     */
    function expand($site_id, $property='') {
        if ($property == 'modules') {
            return join('-' , GetTable2Array("SELECT destination_id FROM relation WHERE source_id='".q_pack_id($site_id)."' AND flag='".REL_FLAG_MODULE_DEPEND."'", "", 'unpack:destination_id'));
        }
        return '';
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
        return is_object($this->item) ? $item->getSliceID() : $GLOBALS['slice_id'];
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
        $viewScr = new view_scroller($itemview->slice_info['vid'],
                $itemview->clean_url,
                $itemview->num_records,
                $itemview->idcount(),
                $itemview->from_record);
        return $viewScr->get( $begin, $end, $add, $nopage );
    }
}

/** page scroller for site modules views - displys page scroller for view
 *
 *  It calls router methods, so it displays the right urls in the scroller
 *  @see AA_Router::scroller() method
 *
 *  Must be issued inside the view
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
    function expand() {
        global $apc_state;
        if (!isset($apc_state['router'])) {
            return '<div class="aa-error">Err in {pager} - router not found - {pager} is designed for site modules</div>';
        }

        $itemview = $this->itemview;
        if (!isset($itemview) OR ($itemview->num_records < 0) ) {   //negative is for n-th grou display
            return "Err in {pager} - pager not valid without a view, or for group display";
        }

        $class_name = $apc_state['router'];
//        $router = new $class_name;
        $router     = AA_Router::singleton($class_name);
        $page       = floor( $itemview->from_record/$itemview->num_records ) + 1;
        $max        = floor(($itemview->idcount() - 1) / max(1,$itemview->num_records)) + 1;

        return $router->scroller($page,$max);
    }
}

/** debugging
 */
class AA_Stringexpand_Debug extends AA_Stringexpand_Nevercache {
    /** expand function
     * @param $property
     */
    function expand( $text='' ) {
        if ($text=='0') {
            $GLOBALS['debug']=0;
        }
        if ($text=='1') {
            $GLOBALS['debug']=1;
        }

        return "";
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


/** Uses one slice ($dictionary) and replace any word which matches a word
 *  in dictionary by the text specified in $format.
 *  It do not search in <script>, <a>, <h*> tags and HTML tags itself.
 *  It also searches only for whole word (not word substrings)
 *  It is writen as quick as possible, so we do not use preg_replace for the
 *  main replaces (it is extremly slow for bigger dictionaries) - strtr used
 *  instead
 *  @author Honza Malik, Hana Havelkova
 */
class AA_Stringexpand_Dictionary extends AA_Stringexpand {
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
     * @param $dictionary
     * @param $format
     * @param $delimeters
     * @param $conds
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

        $format  = AA_Slices::isSliceProperty($dictionary, $format) ? '{substr:{'.$format.'}:0:50}' : $format;
        $format  = "{@keywords........:##}_AA_DeLiM_$format";

        // above is little hack - we need keyword pair, but we want to call
        // GetFormatedItems only once (for speedup), so we create one string with
        // delimiter:
        //   BIOM##Biom##biom_AA_DeLiM_<a href="http://biom.cz">_#KEYWORD_</a>

        $set     = new AA_Set($dictionary, String2Conds( $conds ), String2Sort( $sort ));
        $kw_item = GetFormatedItems($set, $format);

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

    /** defineDelimiters function
     *  It's necessary to select characters used as standard word delimiters
     *  Check the value of the string variable $delimiter_chars and correct it.
     *  Associative array $delimiters contains frequently used delimiters and it's
     *  special replace_strings used as word boundaries
     *  @author haha
     */
    function defineDelimiters() {
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
            //huhl($itemview->slice_info);
                if ($itemview->slice_info["id"]) {
                    $mysliceid = unpack_id($itemview->slice_info['id']);
                } elseif ($GLOBALS['slice_id']) {
                    $mysliceid = $GLOBALS['slice_id'];
                } else {
                    if ($errcheck) huhl("No slice_id defined when expanding fileman");
                    return "";
                }
                $fileman_dir = AA_Slices::getSliceProperty($mysliceid,"fileman_dir");
            // Note dropthrough from case "fileman"
            case "site":
                if ($type == "site") {
                    if (!($fileman_dir = $GLOBALS['site_fileman_dir'])) {
                        if ($errcheck) huhl("No site_fileman_dir defined in site file");
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
                if ($errcheck) huhl("Trying to expand include, but no valid hint in $out");
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
    global $errcheck;
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

    return AA_Http::PostRequest($filename, array(), $headers);
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
        return $slice = $_SERVER[$variable];
    }
}

// Return some strings to use in keystr for cache if could do a stringexpand
class AA_Stringexpand_Keystring extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     */
    function expand() {
        $ks = "";
        if (isset($GLOBALS["apc_state"])) {
            $ks .= serialize($GLOBALS["apc_state"]);
        }
        if (isset($GLOBALS["als"])) {
            $ks .= serialize($GLOBALS["als"]);
        }
        if (isset($GLOBALS["slice_pwd"])) {
            $ks .= serialize($GLOBALS["slice_pwd"]);
        }

        if (isset($_COOKIE)) {
            $work = array();
            // do not count with cookie names starting with underscore
            // (Google urchin uses cookies like __utmz which varies very often)
            foreach( $_COOKIE as $key => $val ) {
                if (!(substr((string)$key,0,1)=='_')) {
                    $work[$key] = $val;
                }
            }
            if (count($work)>0) {
                $ks .= serialize($work);
            }
        }
        return $ks;
    }
}

/** helper class
 *  Its purpose is just tricky - we can't use preg_replace_callback where callback
 *  function has some more parameters. So we use this class as callback
 */
class AA_Unalias_Callback {
    var $item;
    var $itemview;
    /** AA_Unalias_Callback function
     * @param $item
     * @param $itemview
     */
    function AA_Unalias_Callback( $item, $itemview ) {
        $this->item     = $item;
        $this->itemview = $itemview;
    }

    /** expand_bracketed function
     *  Expand a single, syntax element
     * @param $out
     * @param $level
     * @param $item
     * @param $itemview
     */
    function expand_bracketed($match) {
        global $contentcache, $als, $debug, $errcheck;

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
        // {fnctn:xxx:yyyy}   - expand $eb_functions[fnctn]
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

        $outlen = strlen($out);
        switch ($out[0]) {
                      // remove comments
            case '#': return '';
                      /** Wraps the text, so you can use the content without taing care about quoting
                       *  of parameter delimiters ":"
                       *
                       *  Example: {-<a href="http://ecn.cz">ecn</a>}
                       *           {ifset:{_#ABSTRACT}:{-<div style="color:red">_#1</div>}}
                       */
            case '-': return QuoteColons($level, 1, substr($out,1));
            case '_':         // Look for {_#.........} and expand now, rather than wait till top
                      if ($out[1] == "#") {
                          if (isset($als[substr($out,2)])) {
                              return QuoteColons(AA_Stringexpand::unalias($als[substr($out,2)], '', $this->item, false, $this->itemview));
                          } elseif (isset($this->item)) {
                              // just alias or not so common: {_#SOME_ALSand maybe some text}
                              return QuoteColons(($outlen == 10) ? $this->item->get_alias_subst($out) : $this->item->substitute_alias_and_remove($out));
                          }
                      }
        }

        if (isset($this->item)) {
            if ($outlen == 16) {
                switch ($out) {
                    case "unpacked_id.....":
                    case "id..............":
                        return $this->item->getItemID();   // should be called in QuoteColons(), but we don't need it
                    case "slice_id........":
                        return $this->item->getSliceID();
                    default:
                        if ( $this->item->isField($out) ) {
                            return QuoteColons($this->item->f_h($out,"-"));
                            // QuoteColons used to mark colons, which is not parameter separators.
                        }
                }
            }
            // look for {const_*:} for changing viewing type of constants
            if (substr($out, 0, 6) == "const_") {
                // $what - name of column (eg. from const_name we get name)
                $what = substr($out, strpos($out, "_")+1, strpos($out, ":") - strpos($out, "_")-1);
                // parameters - first is field
                $parts = ParamExplode(substr($out,strpos($out,":")+1));
                // get group id
                $group_id = getConstantsGroupID($this->item->getval("slice_id........"), $parts[0]);
                /* get short_id/name/... of constant with specified value from constants category with
                   group $group_id */
                $value = getConstantValue($group_id, $what, $this->item->getval($parts[0]));

                return QuoteColons($value);
            }
            // tried to change to preg_match, but there was problem with multiple lines
            //   used: '/^alias:([^:]*):([a-zA-Z0-9_]{1,3}):?(.*)$/'
            elseif ( (substr($out, 0, 5)=='alias') AND ereg('^alias:([^:]*):([a-zA-Z0-9_]{1,3}):?(.*)$', $out, $parts) ) {
                // call function (called by function reference (pointer))
                // like f_d("start_date......", "m-d")
                if ($parts[1] && !$this->item->isField($parts[1])) {
                    huhe("Warning: $out: $parts[1] is not a field, don't wrap it in { } ");
                }
                $fce     = $parts[2];
                return QuoteColons($this->item->$fce($parts[1], $parts[3]));
                // QuoteColons used to mark colons, which is not parameter separators.
            }
        }
        if( substr($out, 0, 7) == "switch(" ) {
            // replace switches
            return QuoteColons(parseSwitch( substr($out,7) ));
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
        // remove comments
        elseif ( substr($out, 0, 1) == "#" ) {
            return "";
        }
        elseif ( substr($out, 0,10) == "view.php3?" ) {
            // Xinha editor replaces & with &amp; so we need to change it back
            $param      = str_replace('&amp;', '&', substr($out,10));
            $view_param = ParseViewParameters(DeQuoteColons($param));
            $foo        = '';

            // do not store in the pagecache, but store into contentcache
            return QuoteColons($contentcache->get_result_by_id(get_hash($view_param), 'GetViewFromDB', array($view_param, $foo)));
        }
        // This is a little hack to enable a field to contain expandable { ... } functions
        // if you don't use this then the field will be quoted to protect syntactical characters
        elseif ( substr($out, 0, 8) == "dequote:" ) {
            return DeQuoteColons(substr($out,8));
        }
        // OK - its not a known fixed string, look in various places for the whole string
        if ( preg_match('/^([a-zA-Z_0-9]+):?([^}]*)$/', $out, $parts) ) {

            // main stringexpand functions.
            // @todo switch most of above constructs to standard AA_Stringexpand...
            // class
            if ( !is_null($stringexpand = AA_Components::factoryByName('AA_Stringexpand_', $parts[1], array('item'=>$this->item, 'itemview'=> $this->itemview)))) {
                if ( $stringexpand->doCache() ) {
                    $key = md5($out.$stringexpand->additionalCacheParam());
                    $res = $contentcache->get_result_by_id($key, array($stringexpand, 'parsexpand'), $parts[2]);
                } else {
                    $res = call_user_func_array( array($stringexpand,'parsexpand'), $parts[2]);
                }
                return $stringexpand->doQuoteColons() ? QuoteColons($res) : $res;
            }

            // eb functions - call allowed php functions directly
            if ( $GLOBALS['eb_functions'][$parts[1]] ) {
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
                //trace("p");
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
        $SQL = "SELECT sum(disc_count) FROM item WHERE slice_id=\"$slice_id\"";
        $db  = getDB();
        $res = $db->tquery($SQL);
        $dc  = $db->next_record() ? $db->f("sum(disc_count)") : 0;
        freeDB($db);
        return $dc;
    }
}

class AA_Stringexpand_Preg_Match extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
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

/** Allows on-line editing of field content */
class AA_Stringexpand_Ajax extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // It works with database, so it shoud always look in the database
    /** expand function
     * @param $item_id
     * @param $field_id
     * @param $show_alias
     */
    function expand($item_id, $field_id, $show_alias='') {
        $ret = '';
        $alias_name = base64_encode(($show_alias == '') ? '{'.$field_id.'}' : $show_alias);
        if ( $item_id AND $field_id) {
            $item        = AA_Items::getItem(new zids($item_id));
            $repre_value = ($show_alias == '') ? $item->f_h($field_id) : $item->subst_alias($show_alias);
            $repre_value = (strlen($repre_value) < 1) ? '--' : $repre_value;
            $iid         = $item->getItemID();
            $input_name  = AA_Widget::getName4Form($field_id, $item);
            $input_id    = AA_Widget::formName2Id($input_name);
            $ret .= "<div class=\"ajax_container\" id=\"ajaxc_$input_id\" onclick=\"displayInput('ajaxv_$input_id', '$iid', '$field_id')\">\n";
            $ret .= " <div class=\"ajax_value\" id=\"ajaxv_$input_id\" aaalias=\"".htmlspecialchars($alias_name)."\">$repre_value</div>\n";
            $ret .= " <div class=\"ajax_changes\" id=\"ajaxch_$input_id\"></div>\n";
            $ret .= "</div>\n\n";
        }
        return $ret;
    }
}

/** Allows on-line editing of field content */
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
     */
    function expand($item_id, $field_id) {
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

            $ret   = $slice->getWidgetLiveHtml($field_id, $iid);
        }
        return $ret;
    }
}



/** @todo - convert whole stringexpand to the new class approach
 *        - this is just begin
 */
class AA_Stringexpand {

    /** item, for which we are stringexpanding
     *  Not used in many expand functions
     */

    var $item;

    /** view, in which we are stringexpanding
     *  Not used in many expand functions
     */
    var $itemview;

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
        $param = empty($params) ? array() : array_map('DeQuoteColons',ParamExplode($params));
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
        global $debug;

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
            $text = preg_replace_callback('/\{\((.*)\)\}/sU', 'make_reference_callback', $text);  //s for newlines, U for nongreedy
        }

        $quotecolons_partly = false;
        $callback = new AA_Unalias_Callback($item, $itemview);
        while (preg_match('/[{]([^{}]+)[}]/s',$text)) {
            // well it is not exactly maxlevel - it just means, we need to unquote colons
            $quotecolons_partly = true;
            $text = preg_replace_callback('/[{]([^{}]+)[}]/s', array($callback,'expand_bracketed'), $text);
        }

        if (is_object($item)) {
            $text = $item->substitute_alias_and_remove($text, explode("##",$remove));
        }

        if ( !$dequote ) {                 // there is no need to substitute on level 1
            return QuoteColons($text);
        }

        // return from unalias - change all back to ':'
        if ( $dequote AND $quotecolons_partly ) { // maxlevel - just for speed optimalization
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


class AA_Stringexpand_Log extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $number
     * @param $base
     */
    function expand($number='', $base='') {
        return log($number, $base);
    }
}

/** unaliases the text - replaces {views} and other constructs */
class AA_Stringexpand_Expand extends AA_Stringexpand {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $number
     */
    function expand($string='') {
        return AA_Stringexpand::unalias($string);
    }
}

class AA_Stringexpand_Unpack extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function
    /** expand function
     * @param $number
     */
    function expand($number='') {
        return unpack_id($number);
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
            return ((string)$unpacked_id == "0" ? "0" : pack("H*",trim($unpacked_id)));
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

/** Creates link to modified image using phpThub
 *  {img:<url>:[<phpthumb_params>]:[<info>]:[<param>]}
 *
 *  Ussage:
 *     <img src="{img:{img_url.........}:w=150&h=150}">
 *     <div>{img:{img_url.........}::imgb:alt="Logo {_#HEADLINE}"}</div>
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

        $img_url = AA_Stringexpand_Img::_getUrl($image, $phpthumb_params);
        if (empty($info) OR ($info == 'url') OR empty($img_url)) {
            return $img_url;
        }

        $a = @getimagesize($img_url);
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
            case 'img':     $alt = $param1 ? ' title="'.safe($param1).'" alt="'.safe($param1).'"' : '';
                            return "<img src=\"$img_url\" ". $a[3] ." $alt $param2 />";
        }
        return '';
    }

    function _getUrl($image, $phpthumb_params) {
        if (empty($phpthumb_params)) {
            return $image;
        }

        // it is much better for phpThumb to access the files as files reletive
        // to the directory, than using http access
        if (AA_HTTP_DOMAIN !== "/") {
            $image = str_replace(AA_HTTP_DOMAIN, '', $image);
        }
        if (substr($image,0,4)=="http") {
            $image = ereg_replace("http://(www\.)?(.+)\.([a-z]{1,6})/(.+)", "\\4", $image);
        }

        return AA_INSTAL_URL. "img.php?src=/$image&$phpthumb_params";
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
                $url2array = explode(".",$url);
                return $url2array[count($url2array)-1];
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
        }
        return '';
    }
}

/** get link to file for download (prints also file size and type)
 *  {filelink:<url>:<text>}
 *
 *  Ussage:
 *     {filelink:{file............}:{text............}}
 *     returns: <a href="http://..." title="Document [PDF - 157 kB]">Document</a> [PDF - 157 kB]
 **/
class AA_Stringexpand_Filelink extends AA_Stringexpand {

    function expand($url, $text='', $text_before='') {
        if (empty($url)) {
            return '';
        }
        $filename = $text ? $text : basename(parse_url($url, PHP_URL_PATH));
        $fileinfo = join(' - ', array(AA_Stringexpand_Fileinfo::expand($url,'type'), AA_Stringexpand_Fileinfo::expand($url,'size')));
        $fileinfo = $fileinfo ? " [$fileinfo]" : '';

        return "$text_before<a href=\"$url\" title=\"$filename$fileinfo\">$filename</a>&nbsp;". str_replace(' ','&nbsp;', $fileinfo);
    }
}

/** Prints version of AA as fullstring, AA version (2.11.0), or svn revision (2368)
 *  {version[:aa|svn]}
 **/
class AA_Stringexpand_Version extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // No reason to cache this simple function

    /** expand function
     * @param $format - 'full' (default) - string with AA version, date, revision, ...
     *                  'aa'             - AA version like "2.11.0"
     *                  'svn'            - Subversion revision like "2314"
     */
    function expand($format='') {
        return aa_version($format);
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

/** @return url GET parameter - {qs:<varname>}
 *  Ussage: {qs:surname}
 *          - returns Havel for http://example.org/cz/page?surname=Havel
 */
class AA_Stringexpand_Qs extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)

    /** expand function
     * @param $ids_string
     * @param $expression
     * @param $delimeter
     */
    function expand($variable_name='') {
        if (isset($_GET[$variable_name])) {
            return $_GET[$variable_name];
        }
        $shtml_get = add_vars('', 'return');
        return $shtml_get[$variable_name];
    }
}

/** Returns actual server load
 *  Ussage: {serverload}
 */
class AA_Stringexpand_Serverload extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // The caching is made by AA_Stringexpand_Aggregate, which is enough
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
            $ret .= $salt_chars[rand(0,56)];
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

    /** additionalCacheParam function
     *
     */
    function additionalCacheParam() {
        return serialize(array($GLOBALS['STRINGEXPAND_SHORTCUTS'], !is_object($this->item) ? '' : $this->item->getId()));
    }


    /** Do not qoute results - it is just shortcut, so we need to expand
     *  the returned text
     */
    function doQuoteColons() {
        return false;
    }

    function expand($param) {

        $arg_list = func_get_args();   // must be asssigned to the variable
        $name     = array_shift($arg_list);

        // @todo - use db lookup for shortcuts
        $text     = $GLOBALS['STRINGEXPAND_SHORTCUTS'][$name];

        return AA_Stringexpand::replaceParams($text, $arg_list);
    }
}


/** Encrypt the text using $key as password (mcrypt PHP extension must be installed)
 */
class AA_Stringexpand_Encrypt extends AA_Stringexpand {

    function expand($text, $key) {
        return AA_Stringexpand_Encrypt::_encryptdecrypt(true, $text, $key);
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
    function expand($text, $key) {
        return AA_Stringexpand_Encrypt::_encryptdecrypt(false, $text, $key);
    }
}


/** Table - experimental - do not use - will be probably replaced with Array
 */
class AA_Stringexpand_Table extends AA_Stringexpand {

    function expand($id, $cmd, $r, $c, $val) {
        static $tables = array();
        if (!isset($tables[$id])) {
            $tables[$id] = new AA_Table($id);
        }
        $table = $tables[$id];
        $ret   = '';

        switch ($cmd) {
        case 'set':
            $table->set($r, $c, $val);
            break;
        case 'get':
            $ret = $table->get($r, $c, strlen($val) ? $val : '_#1');
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

/** Go directly to another url
 *  use as:
 *    {redirect:http#://example.org/en/new-page}                 - mention the escaped colon in http
 *    {redirect:{ifset:{xid}::http#://example.org/en/new-page}}  - for conditional redirect
 */
class AA_Stringexpand_Redirect extends AA_Stringexpand {
    function expand($url=null) {
        if (!empty($url)) {
            go_url($url);
        }
        return '';
    }
}

?>
