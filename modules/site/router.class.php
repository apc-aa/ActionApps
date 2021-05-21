<?php
/**
 * Provides AA_Router and AA_Router_Seo
 *
 * @version $Id$
 * @author Honza Malik <honza.malik@ecn.cz>
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
*/
/*
Copyright (C) 1999, 2000 Association for Progressive Communications
https://www.apc.org/

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



/** AA_Router - base class for all routers
 *  Router is the class, which controls the url in AA site module. It should be
 *  used in "site control file". It provides several methods, which you can
 *  override in your "site control file".
 *
 *  The intended use is AA_Router -> AA_Router_Seo -> AA_Router_Mysite
 *    AA_Router - the generic class which defines the interface (API)
 *                and several common methods
 *
 *    AA_Router_Seo - is the class (below in this file), which implements
 *                    the AA_Router interface in some way. It is just like
 *                    "best practice" class. During the time we find, that
 *                    most sites, which uses the site module uses the same
 *                    url handling, the same variables, ... So, such setting
 *                    is in this class.
 *                    Of course, if your sites uses another configuration
 *                    of site variables, url strings, ... then it is good idea
 *                    to add another "best practice" class in this file.
 *
 *   AA_Router_Mysite - is extension of AA_Router_Seo for your site "Mysite".
 *                      It is not in this file - it is rather
 *                      in "site control file" and basically looks like:
 *
 *                      class AA_Router_Mysite extends AA_Router_Seo {
 *
 *                          function someOfYourMethodsHere() {
 *                              [...]
 *                          }
 *                      }
 *
 *                      You live without this class, if you do not need any
 *                      specific methods - you can instantiate the AA_Router_Seo
 *                      class directly.
 */
class AA_Router {

    protected $apc;
    protected $slices;
    protected $home;
    protected $web_languages;

    /** @var AA_Router $_instance
     * Store the single instance of Database
     */
    private static $_instance;

    function __construct($slices=null, $home='') {
        $this->slices = is_array($slices) ? $slices : [];
        $this->home   = $home;
        $this->web_languages = [];
    }

    /** @return AA_Router */
    public static function singleton($type, $slices=null, $home='', $web_languages=null) {
        if (!isset(self::$_instance)) {
            self::$_instance = new $type($slices, $home);
        }
        if (is_array($web_languages)) {
            self::$_instance->setWebLangs($web_languages);
        }
        return self::$_instance;
    }

    protected function setWebLangs(array $web_languages) {
        $this->web_languages = $web_languages;
    }

    /** view scroller function
     * @param $page - current page
     * @param $max - number of pages
     * @return mixed|string
     */
    function scroller($page, $max, $target=null) {
        if ($max<=1) {
            return $this->getParam('scroller_nopage');
        }
        if ($page<1)    { $page = 1; }
        if ($page>$max) { $page = $max; }

        $nav_arr = $this->_scrollerArray($page, $max);
        $add     = $this->getParam('scroller_add');
        $arr     = [];

        foreach ( $nav_arr as $k => $v) {
            if ( $v ) {
                if ($target) {
                   $arr[] = "<a href=\"javascript:void(0)\" onclick=\"AA_Ajax('$target','$v');return false;\" $add>$k</a>";
                } else {
                   $arr[] = "<a href=\"$v\" $add>$k</a>";
                }
            } else {
                $arr[] = ctype_digit((string)$k) ? "<span class=\"active\"> $k </span>" : "<span class=\"dots\"> $k </span>";
            }
        }

        return $this->getParam('scroller_begin'). join($this->getParam('scroller_delimiter'), $arr) . $this->getParam('scroller_end');
    }

    function getParam($param) {
        $params = [
                         'page_variable'      => 'xpage',
                         'scroller_delimiter' => '<span class="delimiter"> | </span>',
                         'scroller_begin'     => '',
                         'scroller_end'       => '',
                         'scroller_add'       => '',
                         'scroller_nopage'    => '',
                         'scroller_next'      => _m('&raquo;'),
                         'scroller_previous'  => _m('&laquo;'),
                         'scroller_length'    => SCROLLER_LENGTH
        ];
        if (!isset($params[$param])) {
            return '';
        }
        return $params[$param];
    }


    /** _scrollerArray function
     *  Return navigation bar as a hash
     *  labels as keys, query string fragments a values
     */
    function _scrollerArray($page, $max) {
        $variable = $this->getParam('page_variable');
        $scrl_len = $this->getParam('scroller_length');
        $mp       = floor(($page - 1) / $scrl_len);  // current means current page
        $from     = max(1, $mp * $scrl_len);                // SCROLLER_LENGTH - number of displayed pages in navbab
        $to       = min(($mp + 1) * $scrl_len + 1, $max);
        $arr      = [];

        if ($page > 1) {
            $arr[$this->getParam('scroller_previous')] = $this->go2url($variable.'='.($page-1));
        }
        if ($from > 1) {
            $arr["1"] = $this->go2url("$variable=1");
        }
        if ($from > 2) {
            $arr[".. "] = "";
        }
        for ($i=$from; $i <= $to; ++$i) {
            $arr[(string)$i] = ($i==$page ? "" : $this->go2url("$variable=$i"));
        }
        if ($to < $max - 1) {
            $arr[" .."] = "";
        }
        if ($to < $max) {
            $arr[(string)$max] = $this->go2url("$variable=$max");
        }
        if ($page < $max) {
            $arr[$this->getParam('scroller_next')] = $this->go2url($variable.'='.($page+1));
        }
        return $arr;
    }

    function go2url($query_string) {
        global $apc_state;
        return $this->getState($this->newState($apc_state, $query_string));
    }

    // should be refined in subclass
    function xid($param=null, $url=null)   { return ''; }

    // should be refined in subclass
    function xuser($param='') { return ''; }

    // should be refined in subclass
    function parse($url='') { return []; }


}


/** AA_Router_Seo implements the AA_Router interface in some way. It is just
 *  like "best practice" class. During the time we find, that most sites, which
 *  uses the site module uses the same url handling, the same variables, ...
 *  So, such setting is in this class.
 *
 *  You can instatniate the class directly in your own "site control file",
 *  or you can extend this class
 *
 *  Using this this class requires mod_rewrite with the rules set as follows:
 *
 *    RewriteRule ^$ /apc-aa/modules/site/site.php3?site_id=670d58c34e6671be2460dde59ab5aab1&apc=en/home [L,QSA]
 *    RewriteRule ^((en|cz|de).*$) /apc-aa/modules/site/site.php3?site_id=670d58c34e6671be2460dde59ab5aab1&apc=$1 [L,QSA]
 *
 *  The URL then looks like
 *
 *    www.example.org/en2test/about-us/projekts/eficiency
 *
 *  which is parsed into following variables
 *
 *    www.example.org/<xlang>[<xpage>][<xflag>][-<xcat>]/<xseo1>/<xseo2>/<xseo3>...  (<xseo> = <xseo3> - the last one)
 *
 *       xlang = en
 *       xpage = 2
 *       xflag = test
 *       xcat  = bio
 *       xseo1 = about-us
 *       xseo2 = projekts
 *       xseo3 = eficiency
 *       xseo  = eficiency
 *       xajax =               // empty or 1 (if called by ajax)
 *
 *  For URL construction yo can use go2url:
 *
 *    original url:                             cz/news/about-us
 *
 *    {go:xlang=de&xseo1=faq&xseo2=questions}   de/faq/questions
 *    {go:xseo1=faq&xseo2=questions}            cz/faq/questions
 *    {go:xseo1=faq}                            cz/faq
 *    {go:xseo2=projects}                       cz/news/projects
 *    {go:xseoadd=nika}                         cz/news/about-us/nika
 *    {go:xlang=de}                             de/
 *    {go:xpage=2}                              cz2/news/about-us
 *    {go:xqs=iid=_#N1_ID___}                   cz/news/about-us?iid=_#N1_ID___  (iid will contain the id of the new item, if this {go} command will be used in ok_url parameter of the form)
 *    {go:xqs=}                                 cz/news/about-us   (and removes all parameters in query string, if any)
 */
class AA_Router_Seo extends AA_Router {

    /** array of translations xseoX -> id */
    protected $_seocache;

    /**
     * @param $url
     * @return array
     */
    function parse($url='') {
        global $auth;

        $this->apc          = self::parseApc($url, $this->home, $this->web_languages);
        $this->apc['state'] = self::getState($this->apc);

        /** Login From Usage:
         *   <form action="{go:xqs=}" method="post">
         *     <fieldset>
         *       <legend>Login, please</legend>
         *       <div  style="margin-right:150px; text-align:right;">
         *         <label for="username">Username</label> <input type="text" size="20" name="username" id="username"><br>
         *         <label for="password">Password</label> <input type="password" size="20" name="password" id="password">
         *       </div>
         *     </fieldset>
         *
         *     <input type="submit" value="Login" style="margin-left:200px;">
         *   </form>
         */

        $this->apc['xuser'] = AuthGetUname();
        $this->apc['xuid']  = AuthGetUid();

        if (isset($_GET['logout'])) {
            $auth->logout();
            $GLOBALS['sess']->delete();
            $this->apc['xuser'] = '';
            $this->apc['xuid']  = '';
        }

        if ( $_REQUEST['username'] ) {
            // not necessary - we alredy logged in by pageOpen() in site.php3
            //$auth->relogin();
            $this->apc['xuser'] = AuthGetUname();
            $this->apc['xuid']  = AuthGetUid();

            if (AuthGetUname() AND $_REQUEST["ok_url"]) {
                go_url($_REQUEST["ok_url"]);
            } elseif (!AuthGetUname() AND $_REQUEST["err_url"]) {
                go_url($_REQUEST["err_url"]);
            }
        }


        //huhl($auth, $GLOBALS['sess']);

        //if ( $_COOKIE['AA_Sess'] OR $_REQUEST['username'] ) {
        //    $options = array(
        //        'aa_url'          => AA_INSTAL_URL,
        //        'cookie_lifetime' => 60*60*24*365  // one year
        //    );
        //    $client_auth = new AA_ClientAuth($options);
        //
        //    if (isset($_GET['logout'])) {
        //        $client_auth->logout();
        //        $this->apc['xuser'] = '';
        //        $this->apc['xuid']  = '';
        //    }
        //    elseif ( $usr = $client_auth->checkAuth()) {
        //        // $auth = $client_auth->getRemoteAuth();
        //
        //
        //        $this->apc['xuser'] = $usr;
        //        $this->apc['xuid']  = ???;
        //
        //        // Redirect to page. If not specified, then it continues to display
        //        // normal page as defined in "action" attribute of <form>
        //        if ($_REQUEST["ok_url"]) {
        //            go_url($_REQUEST["ok_url"]);
        //        }
        //    } elseif ($_REQUEST["err_url"]) {
        //        go_url($_REQUEST["err_url"]);
        //    }
        //}
        return $this->apc;
    }

    /** static function - caling from outside is not necessary, now */
    function parseApc($apc, $home='', $langs= []) {
        if (!$home) {
            $home = '/'.get_mgettext_lang();
        }
        // manage links like example.org/?filter=22
        if (substr($apc,0,2)=='/?') {  // maybe also "#"
            $apc = $home.$apc;
        } elseif ($apc=='/index.php') { // usefull for AA behind proxy (HTTP_X_FORWARDED_SERVER) - Appache adds index.php as default path so we have to re
            $apc = $home;
        }

        $parsed_url = parse_url(trim($apc,' \t/') ? $apc : $home);
        $arr        = explode('/', trim($parsed_url['path'],'/'));
        $langs      = array_filter($langs);   // sometimes there is [0] => '' for some reason...
        $re_lang    = count($langs) ? join('|',$langs) : '[a-z]{2}';

        $matches    = [];
        $ret        = [];
        if (!(preg_match("/^($re_lang)([0-9]*)([^-0-9]*)[-]?(.*)/", $arr[0], $matches))) {
            if (count($langs) AND trim($arr[0])) {
                // if we define web_languages in Site Module setting (so for newer sites), then we can use pages without
                // the first directory - so instead of /en/users we can use just /users (xlang will be filled form first web_language)
                array_unshift($arr,$langs[0]);
                $ret = ['xlang'=>$langs[0],'xpage'=>'','xflag'=>'','xcat'=>'','xnolang'=>1];
            } else {
                return [];
                // newer behavior (not helped on library4all, so commented out)
                // return array('xlang'=>'','xpage'=>'','xflag'=>'','xcat'=>'','xseo1'=>'','xseo2'=>'','xseo3'=>'','xseo4'=>'','xseo5'=>'','xseo6'=>'','xseo7'=>'','xseo8'=>'','xseo9'=>'');
            }
        } else {
            $ret = ['xlang' => $matches[1], 'xpage' => $matches[2], 'xflag' => $matches[3], 'xcat' => $matches[4], 'xnolang'=>''];

            // add xseoX from $home if only the {xlang} is provided -- /cz/   -> /cz/home
            // if (count($arr) < 2) {
            if (count($arr) < 2) {
                if ((substr(trim($apc), -1) == '/') OR ($arr[0] == $ret['xlang']) OR !count($langs)) {
                    // the last OR with $lang is used for backward compatibility - the slash rule will be applied
                    // only to newer sites, where you set web_languages (so for newer sites we are more strict - there must be the ending slash, if we want to use cz/).
                    // However - I think it is not necessary - the links like /cz will work, and /cz2 are not common
                    // (and should be rewritten to /cz2/ or better /cz2/page )
                    $arr = explode('/', trim(parse_url($home, PHP_URL_PATH), '/'));
                }
            }
        }

        $ret['xajax'] = IsAjaxCall() ? 1 : '';

        for ($i=1, $ino=count($arr); $i<$ino; ++$i) {
            $ret['xseo'.$i] = $arr[$i];
        }

        // add querystring
        if ($parsed_url['query']) {
            $ret['xqs'] = $parsed_url['query'];
        }

        // set default values - like xseo, xseo10, ...
        $ret = self::newState($ret, '');
        return $ret;
    }

    /** static function - caling from outside is not necessary, now */
    function getState($apc_state) {
        $ret = '/'.$apc_state['xlang'].$apc_state['xpage'].$apc_state['xflag'].($apc_state['xcat'] ? '-'. $apc_state['xcat'] : '').'/';
        $i=1;
        while (!empty($apc_state['xseo'.$i])) {
            $ret .= $apc_state['xseo'.$i]. '/';
            $i++;
        }
        // the state should be direcory-like: /cz/, /en2/, ... not /cz, /en2 - the reason is we want to report the /entropy url as 404, not as xlang=en, xflag=tropy
        // which could happen when site.php is called like:
        //   RewriteEngine on
        //   RewriteRule ^$ /apc-aa/modules/site/site.php3 [L,QSA]
        //   RewriteCond %{REQUEST_FILENAME} !-f
        //   RewriteCond %{REQUEST_FILENAME} !-d
        //   RewriteRule ^ /apc-aa/modules/site/site.php3 [L,QSA]
        if ($i>1) {
            $ret = rtrim($ret,"/");
        }

        // add querystring
        if ($apc_state['xqs']) {
            $ret .= '?'. $apc_state['xqs'];
        }
        return $ret;
    }

    /** ze stavajiciho $apc_state a naparsovaneho query-stringu
        aktualizuje hodnoty v $apc_state a vrati novy aktualni
        apc retezec */
    function newState($apc_state, $query_string) {
        $new_arr = [];
        parse_str($query_string, $new_arr);   // now we have $new_arr['x'], $new_arr['p'], etc.

        if (!empty($new_arr['xlang'])) { //change language
            $apc_state = self::parseApc($new_arr['xlang']);
        }

        // convert xseoX to array temporarily - it will be easier to work with it
        // $old_x for current state, $new_x for new state
        $new_x_max = self::_maxKey($new_arr, 'xseo');

        if ( $new_x_max > 0 ) {
            $old_x_max = self::_maxKey($apc_state, 'xseo');
            $max       = max($new_x_max, $old_x_max);
            $state     = 'COPY';
            for ( $i=1; $i <= $max; ++$i) {
                if ($state == 'CLEAR') {
                    unset($apc_state['xseo'. $i]);
                } elseif ($new_arr['xseo'. $i]) {
                    $apc_state['xseo'. $i] = $new_arr['xseo'.$i];
                    $state = 'REDEFINING';
                } elseif ($state == 'REDEFINING') {
                    unset($apc_state['xseo'. $i]);
                    $state = 'CLEAR';
                } elseif (!$apc_state['xseo'. $i]) {
                    unset($apc_state['xseo'. $i]);
                    $state = 'CLEAR';
                } else {// $state = 'COPY';
                    // $apc_state['xseo'. $i] = $apc_state['xseo'. $i];
                }
            }
            if ($state != 'COPY') {
                $apc_state['xpage'] = '';
            }
        }
        if (!empty($new_arr['xseoadd'])) {
            $x_max = self::_maxKey($apc_state, 'xseo');
            $apc_state['xseo'. ($x_max+1)] = $new_arr['xseoadd'];
        }
        // xseo changed - reset pager
        if (($new_x_max > 0) OR (!empty($new_arr['xseoadd']))) {
            $apc_state['xpage'] = '';
            $apc_state['xqs']   = '';
        }
        if (!empty($new_arr['xpage'])) { //'scroll' to other page
            $apc_state['xpage'] = ($new_arr['xpage'] < 2) ? '' : $new_arr['xpage'];
        }
        if (!empty($new_arr['xflag'])) { //change flag
            $apc_state['xflag'] = $new_arr['xflag'];
        }
        if (!empty($new_arr['xcat'])) { //change flag
            $apc_state['xcat'] = $new_arr['xcat'];
        }
        if (isset($new_arr['xqs'])) { //change xqs  (we can use also {go:xqs=} for current url without query string
            $apc_state['xqs'] = $new_arr['xqs'];
        }
        // {go:xqs_year=2019&xqs_type=enviro}  ==> example.org/...?year=2019&type=enviro&some_other_params_from_old_url
        $xqs_subst = [];
        foreach ($new_arr as $var => $v) {
            if (strpos($var,'xqs_')===0 AND (strlen($var)>4)) { // begins with 'xqs_'
               $xqs_subst[substr($var,4)] = $v;
            }
        }
        if ($xqs_subst) {
            parse_str($apc_state['xqs'], $current_xqs);
            $apc_state['xqs'] = http_build_query(array_merge($current_xqs, $xqs_subst));

        }
        $x_max = self::_maxKey($apc_state, 'xseo');
        // we clear all unused {xseoX} in order {xseoX} is allaways SEO string or empty string and not something like {xseo4}
        for ($i=$x_max+1; $i<10; ++$i) {
            $apc_state['xseo'.$i] = '';
        }
        $apc_state['xseo']   = ($x_max>0) ? $apc_state['xseo'. $x_max] : '';
        if (empty($apc_state['xseo']) AND ($x_max>1)) {
            $apc_state['xseo']   = $apc_state['xseo'. ($x_max-1)];
        }

        // workaround for the static::get_class()
        // returns real class name, not the AA_Router_Seo (if called staticaly)
        $backtrace           = debug_backtrace();
        $apc_state['router'] = $backtrace[0]['class'];

        return $apc_state;
    }

    /** returns: 1) ID of current item (if no param specified)
     *           2) ID of item on specified level (if param is number)
     *           3) IDs path of current item - like 2587(2877(3004)) as used
     *              in {item...} syntax (good for breadcrumbs)
     *              (if param = "path")
     * @param string $param specifies, which information you want to get (see above)
     * @param string $url if filled, returns the information about the specified
     *                url instead of the current item
     * @return string
     */
    function xid($param=null, $url=null) {

        $apc = empty($url) ? $this->apc : self::parseApc($url, $this->home, $this->web_languages);

        if (empty($param)) {
            // current item id
            return $this->_xseo2id($apc['xseo']);
        }
        if (ctype_digit((string)$param)) {
            // item on specified level
            return $this->_xseo2id($apc['xseo'.$param]);
        }
        if ($param == 'path') {
            // tree for breadcrumb - just like 7663(7434(7432))
            $i     = 1;
            $delim = '';
            $path  = '';
            while (!empty($apc['xseo'.$i])) {
                $path  .= $delim. $this->_xseo2id($apc['xseo'.$i]);
                $delim  = '(';
                $i++;
            }
            if ($i > 2) {
                $path .= str_repeat(')', $i-2);   // close all open brackets
            }
            return $path;
        }
        if (($param == 'list') OR is_long_id($param)) {
            // list of ids - just like 7663-7434-7432
            $i     = 1;
            $ids   = [];
            while (!empty($apc['xseo'.$i])) {
                $ids[] = $this->_xseo2id($apc['xseo'.$i++]);
            }
            if ($param == 'list') {
                return join('-',$ids);
            }
            for( $i=count($ids)-1; $i>=0; $i--) {
                if ( $ids[$i] AND is_object($item = AA_Items::getItem($ids[$i])) AND ($item->getSliceID()==$param) ) {
                    return $ids[$i];
                }
            }
            return '';
        }
    }

    // function _xseo2id($seo_string) {
    //     static $_seoslices = null;  // stores only slices with 'seo' field
    //     if (!isset($this->_seocache[$seo_string])) {
    //         if (is_null($_seoslices)) {
    //             $_seoslices = join('-',$this->_getSeoSlices());
    //         }
    //         $this->_seocache[$seo_string] = substr(StrExpand('AA_Stringexpand_Seo2ids', array($_seoslices, $seo_string)),0,32);
    //     }
    //     return $this->_seocache[$seo_string];
    // }
    //
    // function _getSeoSlices() {
    //     return array_map('unpack_id', DB_AA::select('slice_id', 'SELECT slice_id FROM field', array(array('slice_id', $this->slices, 'l'), array('id', 'seo.............'))));
    // }

    function _xseo2id($seo_string) {
        // Q: Is the cache needed, when AA_Stringexpand_Seo2ids is already cached? Honza 2015-07-16
        // A: Yes - based on measures it seams to be much quicker. Honza 2015-07-16
        if (!isset($this->_seocache[$seo_string])) {
            $this->_seocache[$seo_string] = substr(StrExpand('AA_Stringexpand_Seo2ids', [join('-',$this->slices), $seo_string]),0,32);
        }
        return $this->_seocache[$seo_string];
    }

    function _maxKey($arr, $prefix) {
        $max = 0;
        foreach($arr as $key => $val) {
            $prefix_len = strlen($prefix);
            if ( !empty($val) AND (substr($key,0,$prefix_len) == $prefix) ) {
                $max = max((int)substr($key,$prefix_len), $max);
            }
        }
        return $max;
    }
}

/** {go:<query-string>}
 *  @return string url based on current state (apc) and query-string paramater
 *  Usage: {go:xseo1=faq&xseo2=questions}
 *  (we used {go2url} custom function in previous versions of AA. This function
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
        if (strpos($query_string,'err=')===false) {
            $url = str_replace(['&err=1','?err=1&','?err=1'], ['','?',''], $url);

        }
        if (strpos($query_string,'logout=')===false) {
            $url = str_replace(['&logout=1','?logout=1&','?logout=1'], ['','?',''], $url);

        }
        return ($html_encode=='1') ? myspecialchars($url) : $url;
    }
}

/** {xid[:<level>]} - complement to {xseo1},.. variables of AA_Router_Seo
 *  @return string id of the current item on specifield level
 *  {xid}      - returns id of current item (the id of {xseo} item)
 *  {xid:1}    - returns id of item on first level (the id of {xseo1} item)
 *               for /cz/project/about-us returns id of "project" item
 *  {xid:path} - returns ids path of current item - like 2587(2877(3004)) as
 *               used in {item...} syntax (good for breadcrumbs:
 *               {item:{xid:path}: _#HEADLINE:: _#HEADLINK &gt;}
 *  {xid:list} - returns ids list from start to current item in the tree - like:
 *               2587-2877-3004
 *  {xid:<slice_id>} - returns the last item id in path, which is in the slice_id
 *  {xid:this is my _#NAME____} - {item:{xid}:this is my _#NAME____}
 *  @param string $param specifies, which information you want to get (see above)
 *  @param string $url   if filled, returns the information about the specified
 *                       url instead of the current item
 **/
class AA_Stringexpand_Xid extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    // Cached inside the router itself
    /** expand function */
    function expand($param='', $url='') {
        $router_class = $GLOBALS['apc_state']['router'];
        if (empty($router_class)) {
            return '<div class="aa-error">Err in {xid} - router not found - {xid} is designed for site modules</div>';
        }
        $router = AA_Router::singleton($router_class);
        if (empty($param) OR ctype_digit((string)$param) OR ($param=='path') OR ($param=='list') OR is_long_id($param)) {
            return $router->xid($param, $url);
        } else {
            return AA::Stringexpander()->unalias('{item:'.$router->xid().':'.$param.'}');
        }
    }
}


/** {xseo}     - returns last "directory" name in AA_Router_Seo
 *  {xseo:1}   - returns {xseo1} - first directory level in url
 *               for /cz/project/about-us returns "project"
 *  {xseo:3}   - returns {xseo1} - third directory level in url
 *  @param string $level specifies, which information you want to get (see above)
 **/
class AA_Stringexpand_Xseo extends AA_Stringexpand_Nevercache {
    // Never cached (extends AA_Stringexpand_Nevercache)
    /** expand function */
    function expand($level='') {
        return $GLOBALS['apc_state']['xseo'.(ctype_digit((string)$level) ? (int)$level : '' )];
    }
}

