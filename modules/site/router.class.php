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

    /** view scroller function
     * @param $page   - current page
     * @param $max    - number of pages
     */
    function scroller($page, $max) {
        if ($max<=1) {
            return $this->getParam('scroller_nopage');
        }
        if ($page<1)    { $page = 1; }
        if ($page>$max) { $page = $max; }

        $nav_arr = $this->_scrollerArray($page, $max);
        $add     = $this->getParam('scroller_add');

        foreach ( $nav_arr as $k => $v) {
            $arr[] = ( $v ? "<a href=\"$v\" $add>$k</a>" : $k);
        }

        return $this->getParam('scroller_begin'). join($this->getParam('scroller_delimiter'), $arr) . $this->getParam('scroller_end');
    }

    function getParam($param) {
        $params = array(
                         'page_variable'      => 'xpage',
                         'scroller_delimiter' => ' | ',
                         'scroller_begin'     => '',
                         'scroller_end'       => '',
                         'scroller_add'       => '',
                         'scroller_nopage'    => '',
                         'scroller_next'      => _m('Next'),
                         'scroller_previous'  => _m('Previous'),
                         'scroller_length'    => SCROLLER_LENGTH
                       );
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
        if ($page > 1) {
            $arr[$this->getParam('scroller_previous')] = $this->go2url($variable.'='.($page-1));
        }
        if ($from > 1) {
            $arr["1"] = $this->go2url("$variable=1");
        }
        if ($from > 2) {
            $arr[".. "] = "";
        }
        for ($i=$from; $i <= $to; $i++) {
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
 *    www.example.org/<xlang>[<xpage>][<xflag>]/<xseo1>/<xseo2>/<xseo3>...  (<xseo> = <xseo3> - the last one)
 *
 *       xlang = en
 *       xpage = 2
 *       xflag = test
 *       xseo1 = about-us
 *       xseo2 = projekts
 *       xseo3 = eficiency
 *       xseo  = eficiency
 *
 *  For URL construction yo can use go2url:
 *
 *    original url:                                 cz/news/about-us
 *
 *    {go2url:xlang=de&xseo1=faq&xseo2=questions}   de/faq/questions
 *    {go2url:xseo1=faq&xseo2=questions}            cz/faq/questions
 *    {go2url:xseo1=faq}                            cz/faq
 *    {go2url:xseo2=projects}                       cz/news/projects
 *    {go2url:xseoadd=nika}                         cz/news/about-us/nika
 *    {go2url:xlang=de}                             de/
 *    {go2url:xpage=2}                              cz2/news/about-us
 */
class AA_Router_Seo extends AA_Router {

    function parseApc($apc) {
        
        $parsed_url = parse_url($apc);
        $arr = explode('/', ltrim($parsed_url['path'],'/'));
        $ret = AA_Router_Seo::_parseRegexp(array('xlang','xpage','xflag'), '/(cz|en|de)([0-9]*)([^0-9]*)/',$arr[0]);

        for ($i=1; $i < count($arr); $i++) {
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

    function getState($apc_state) {
        $ret = '/'.$apc_state['xlang'].$apc_state['xpage'].$apc_state['xflag'].'/';
        $i=1;
        while (!empty($apc_state['xseo'.$i])) {
            $ret .= $apc_state['xseo'.$i]. '/';
            $i++;
        }
        $ret = rtrim($ret,"/");
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
            for ( $i=1; $i <= $max; $i++) {
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
                    $apc_state['xseo'. $i] = $apc_state['xseo'. $i];
                }
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
        $x_max = self::_maxKey($apc_state, 'xseo');
        // we clear all unused {xseoX} in order {xseoX} is allaways SEO string or empty string and not something like {xseo4}
        for ($i=$x_max+1; $i<10; $i++) {
            $apc_state['xseo'.$i] = '';
        }
        $apc_state['xseo']   = ($x_max>0) ? $apc_state['xseo'. $x_max] : '';
        if (empty($apc_state['xseo']) AND ($x_max>1)) {
            $apc_state['xseo']   = $apc_state['xseo'. ($x_max-1)];
        }
        $apc_state['router'] = get_class();
        return $apc_state;
    }

    /** $varnames - array()! of varnames */
    function _parseRegexp($varnames, $regexp, $str, $strdef='') {
        if (!$str) { $str = $strdef; }
        $ret     = array();
        $matches = array();
        if (!(preg_match($regexp, $str, $matches))) {
            print("Error initial string $strdef doesn't match regexp $regexp\n<br>");
        }
        foreach ($varnames as $key => $varname) {
            $ret[$varname] = $matches[$key+1];
        }
        return $ret;
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

/** Add this function to your "site control file" */
// function stringexpand_go2url($query_string) {
//     global $apc_state;
//     return AA_Router_Seo::getState(AA_Router_Seo::newState($apc_state, $query_string));
// }


?>
