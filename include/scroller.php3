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
 * @package   Include
 * @version   $Id$
 * @author    Jiri Hejsek, Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/

/**	Class AA_Scroller
 *	Implements navigation bar for scrolling through long lists
 */

class AA_Scroller extends AA_Storable {
    var $pgcnt;			            // total page count
    var $current          = 1;		// current page
    var $id;			            // scroller id
    var $visible          = 1;
    var $sortdir          = 1;
    var $sortcol          = "";
    var $itmcnt;                    // total item count
    var $metapage         = 10;	    // "metapage" size
    var $urldefault;		        // cache self url

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    static function getClassProperties(): array {  //  id             name          type   multi  persistent - validator, required, help, morehelp, example
        return [
            'pgcnt'      => new AA_Property( 'pgcnt'     , _m('Pgcnt'     ), 'int',  false, true),
            'current'    => new AA_Property( 'current'   , _m('Current'   ), 'int',  false, true),
            'id'         => new AA_Property( 'id'        , _m('Id'        ), 'text', false, true),
            'visible'    => new AA_Property( 'visible'   , _m('Visible'   ), 'bool', false, true),
            'sortdir'    => new AA_Property( 'sortdir'   , _m('Sortdir'   ), 'int',  false, true),
            'sortcol'    => new AA_Property( 'sortcol'   , _m('Sortcol'   ), 'text', false, true),
            'itmcnt'     => new AA_Property( 'itmcnt'    , _m('Itmcnt'    ), 'int',  false, true),
            'metapage'   => new AA_Property( 'metapage'  , _m('Metapage'  ), 'int',  false, true),
            'urldefault' => new AA_Property( 'urldefault', _m('Urldefault'), 'text', false, true)
        ];
    }

    /** AA_Scroller function
     *  constructor
     * @param $id identifies scroller on a web page
     * @param $ulr
     * @param $pgcnt is the number of pages to scroll
     */
    function __construct($id = "", $url = "", $listlen = 10) {
        $this->id         = $id;
        $this->urldefault = $url;
        $this->metapage   = $listlen;
        $this->pgcnt      = 0;
        $this->current    = 1;
        $this->visible    = 1;
    }

    /** relative function
     *  return part of a query string for move of $pages relative of current position
     * @param $pages
     * @return string
     */
    function relative($pages) {
        return urlencode("scr_" . $this->id . "_Mv") . "=" . urlencode($pages);
    }

    /** absolute function
     *  return part of a query string for move to absolute position $page
     * @param $page
     * @return string
     */
    function absolute($page) {
        return urlencode("scr_" . $this->id . "_Go") . "=" . urlencode($page);
    }

    /** checkBounds function
     *  keep current page within bounds
     */
    function checkBounds() {
        if ($this->current < 1) {
            $this->current = 1;
        }
        if ($this->current > $this->pgcnt) {
            $this->current = max($this->pgcnt,1);
        }
    }

    /** getListlen function    */
    function getListlen() {
        return $this->metapage;
    }

    /** countPages function
     *  adjust number of pages depends on item count and metapage
     * @param $itmcnt
     */
    function countPages($itmcnt) {
        $this->pgcnt = floor(($itmcnt - 1) / $this->metapage) + 1;
        $this->checkBounds();
        $this->itmcnt = $itmcnt;
    }

    /** go2page function
     * @param $page
     */
    function go2page($page) {
        $this->current=$page;
        $this->checkBounds();
    }

    /** pageCount function
     *  returns number of pages
     */
    function pageCount() {
        return floor(($this->itmcnt - 1) / max(1,$this->metapage)) + 1;
    }

    /** updateScr function
     *  process query string and execute commands for this scroller
     *  query string is taken from global variables
     * @param $url
     */
    function updateScr($url = "") {

        if ($url) {
            $this->urldefault = $url;
        }
        if (isset($GLOBALS["scr_" . $this->id . "_Vi"])) {
            $this->visible = $GLOBALS["scr_" . $this->id . "_Vi"];
        }
        if ($GLOBALS["scr_" . $this->id . "_Go"]) {
            $this->current = $GLOBALS["scr_" . $this->id . "_Go"];
        }
        if ($GLOBALS["scr_" . $this->id . "_Mv"]) {
            $this->current += $GLOBALS["scr_" . $this->id . "_Mv"];
        }
        $this->checkBounds();
    }

    /** navarray function
     * @return array - navigation bar as an array
     * labels as keys, query string fragments a values
     */
    function navarray() {
        if (!$this->pgcnt) {
            return [];
        }
        $arr  = [];
        $mp   = floor(($this->current - 1) / SCROLLER_LENGTH);  // current means current page
        $from = max(1, $mp * SCROLLER_LENGTH);                // SCROLLER_LENGTH - number of displayed pages in navbab
        $to   = min(($mp + 1) * SCROLLER_LENGTH + 1, $this->pgcnt);
        if ($this->current > 1) {
            $arr["&laquo;"]  = $this->relative(-1);
        }
        if ($from > 1) {
            $arr["1"]   = $this->absolute(1);
        }
        if ($from > 2) {
            $arr[".."] = "";
        }
        for ($i = $from; $i <= $to; $i++) {
            $arr[(string)$i] = ($i == $this->current ? "" : $this->absolute($i));
        }
        if ($to < $this->pgcnt - 1) {
            $arr[" .. "] = "";
        }
        if ($to < $this->pgcnt) {
            $arr[(string) $this->pgcnt] = $this->absolute($this->pgcnt);
        }
        if ($this->current < $this->pgcnt) {
            $arr["&raquo;"] = $this->relative(1);
        }
        return $arr;
    }

    /** pnavbar function
     *  convert array provided by navarray into HTML code
     *  commands are added to $url
     */
    function pnavbar() {
        if (!$this->visible) {
            return;
        }
        $delimiter = '';
        $arr = $this->navarray();
        $url = $this->urldefault;

        $ret = '';

        if (count($arr) > 1) {
            foreach ($arr as $k => $v) {
                $ret .= $delimiter;
                $delimiter = " | ";
                if ($v) {
                    $ret .= "<a href=\"" . get_url($url, [$v]) . "\">$k</a>\n";
                } else {
                    $ret .= ($k == $this->current) ? "<span class=active>$k</span>\n" : "<span class=dots>..</span>\n";
                }
            }
            $ret .= "| <a href=\"" . get_url($url, ["listlen=99999"]) . "\">" . _m("All") . "</a> &nbsp; ";
        }
        echo "<div class=pager>$ret <span class=found>(". _m('found').' '. $this->itmcnt .")</span></div>";
    }
}

/**	Class easy scroller
 *	Implements navigation bar for scrolling through long lists.
 *  No SQL filters support (as in scroller.php3)
 */

class AA_Scroller_Easy {
    var $current = 1;	  /** current page     */
    var $id;			  /** scroller id - identifies scroller on a web page */
    var $itmcnt;          /** total item count */
    var $metapage = 10;	  /** "metapage" size  */
    var $urldefault;	  /** cache self url   */
    var $show_all;	      /** if true, scroller will show also 'All' option */

    /** easy_scroller function
     * Constructor
     * @param $id
     * @param $url
     * @param $metapage
     * @param $itmcnt
     */
    function __construct($id="", $url="", $metapage=10, $itmcnt=0) {
        $this->id         = $id;
        $this->metapage   = $metapage;
        $this->urldefault = $url;
        $this->itmcnt     = $itmcnt;
        $this->current    = 1;
    }
    /** setShowAll function
     * @param $val
     */
    function setShowAll($val)  { $this->show_all = $val; }
    /** setMetapage function
     * @param $val
     */
    function setMetapage($val) { $this->metapage = $val; }

    /** Relative function
     *  Return part of a query string for move of $pages relative
     *  of current position
     *  @param $pages
     */
    //  --- no longer used
    //function Relative($pages) {
    //    return urlencode("scr_{$this->id}_Mv") . "=" . urlencode($pages);
    //}

    /** Absolute function
     *  Return part of a query string for move to absolute position $page
     * @param $page
     * @return string
     */
    function Absolute($page) {
        return urlencode("scr_{$this->id}_Go") . "=" . urlencode($page);
    }
    /** pageCount function
     *
     */
    function pageCount() {
        return floor(($this->itmcnt - 1) / max(1,$this->metapage)) + 1;
    }

    /** checkBounds function
     * Keep current page within bounds
     */
    function checkBounds() {
        if ($this->current < 1) {
            $this->current = 1;
        }
        $pages = $this->pageCount();
        if ($this->current > $pages) {
            $this->current = $pages;
        }
    }

    /** countPages function
     *  Adjust number of pages depends on item count and metapage
     * @param $itmcnt
     */
    function countPages($itmcnt) {
        $this->itmcnt = $itmcnt;
        $this->checkBounds();
    }

    /** update function
     *  Process query string and execute commands for this scroller
     *  Query string is taken from global variables
     *  (based on $itmcnt)
     */
    function update() {
        if ($GLOBALS["scr_{$this->id}_Go"]) {
            $this->current = $GLOBALS["scr_{$this->id}_Go"];
        }
        if ($GLOBALS["scr_{$this->id}_Mv"]) {
            $this->current += $GLOBALS["scr_{$this->id}_Mv"];
        }
        $this->checkBounds();
    }

    /** navarray function
     *  Return navigation bar as a hash
     *  labels as keys, query string fragments a values
     */
    function navarray() {
        if (!$this->itmcnt) return [];
        $pgcnt = $this->pageCount();
        $mp    = floor(($this->current - 1) / SCROLLER_LENGTH);  // current means current page
        $from  = max(1, $mp * SCROLLER_LENGTH);                // SCROLLER_LENGTH - number of displayed pages in navbab
        $to    = min(($mp + 1) * SCROLLER_LENGTH + 1, $pgcnt);
        $arr   = [];
        if ($this->current > 1) {
            $arr[_m("Previous")]     = $this->Absolute($this->current-1);
        }
        if ($from > 1) {
            $arr["1"]   = $this->Absolute(1);
        }
        if ($from > 2) {
            $arr[".. "] = "";
        }
        for ($i = $from; $i <= $to; $i++) {
            $arr[(string)$i] = ($i==$this->current ? "" : $this->Absolute($i));
        }
        if ($to < $pgcnt - 1) {
            $arr[" .."] = "";
        }
        if ($to < $pgcnt) {
            $arr[(string)$pgcnt] = $this->Absolute($pgcnt);
        }
        if ($this->current < $pgcnt) {
            $arr[_m("Next")] = $this->Absolute($this->current+1);
        }
        if ($this->show_all) {
            $arr[_m("All")] = 'listlen=10000';
        }
        return $arr;
    }

    /** pnavbar function
     *  Convert array provided by navarray into HTML code
     *  Commands are added to $url
     */
    function pnavbar() {
        $url = con_url($this->urldefault, ['scrl'=>1]);
        $i   = 0;
        $arr = $this->navarray();
        if ( count($arr) > 0 ) {
            echo "\n<div class=\"enclose-scroller\" id=\"scroller-{$this->id}\">";
            foreach($arr as $k => $v) {
                if ($i++) {
                    echo " | ";
                }
                if ($v) {
                    echo "<a href=\"". $url. "&amp;". $v. "\" class=\"scroller\">$k</a>";
                } else {
                    echo "<span class=\"scroller_actual\">$k</span>";
                }
            }
            echo "\n<!--/scroller-{$this->id}--></div>";
        }
    }
}


// Used  just for {scroller} which should not be used in sitemodules (the {pager} is used there which use AA_Scroller_view of $router->scroller)*/
class AA_Scroller_Sitemodule {
    protected $current = 1;	  /** current page     */
    protected $id;			  /** scroller id - identifies scroller on a web page */
    protected $itmcnt;          /** total item count */
    protected $metapage = 10;	  /** "metapage" size  */

    /** view_scroller function
     * Constructor
     * @param $id
     * @param $metapage
     * @param $itmcnt
     * @param $curr
     */
    function __construct($id="", $metapage=10, $itmcnt=0, $curr=0) {
        $this->id         = $id;
        $this->metapage   = $metapage;
        $this->itmcnt     = $itmcnt;
        $this->current    = floor( $curr/$this->metapage ) + 1;
    }

    /** Absolute function
     *  Return part of a query string for move to absolute position $page
     * @param $page
     * @return string
     */
    function Absolute($page) {
        return urlencode("scr_{$this->id}_Go") . "=" . urlencode($page);
    }
    /** pageCount function
     *
     */
    function pageCount() {
        return floor(($this->itmcnt - 1) / max(1,$this->metapage)) + 1;
    }

    /** checkBounds function
     *  Keep current page within bounds
     */
    function checkBounds() {
        if ($this->current < 1)      { $this->current = 1; }
        $pages = $this->pageCount();
        if ($this->current > $pages) { $this->current = $pages; }
    }

    /** countPages function
     *  Adjust number of pages depends on item count and metapage
     * @param $itmcnt
     */
    function countPages($itmcnt) {
        $this->itmcnt = $itmcnt;
        $this->checkBounds();
    }

    /** navarray function
     *  Return navigation bar as a hash
     *  labels as keys, query string fragments a values
     */
    function navarray() {
        $this->checkBounds();
        if (!$this->itmcnt) {
            return [];
        }
        $pgcnt = $this->pageCount();
        $mp    = floor(($this->current - 1) / SCROLLER_LENGTH);  // current means current page
        $from  = max(1, $mp * SCROLLER_LENGTH);                // SCROLLER_LENGTH - number of displayed pages in navbab
        $to    = min(($mp + 1) * SCROLLER_LENGTH + 1, $pgcnt);
        if ($this->current > 1) {
            $arr[_m('Previous')] = $this->Absolute($this->current-1);
        }
        if ($from > 1) {
            $arr["1"] = $this->Absolute(1);
        }
        if ($from > 2) {
            $arr[".. "] = "";
        }
        for ($i=$from; $i <= $to; $i++) {
            $arr[(string)$i] = ($i==$this->current ? "" : $this->Absolute($i));
        }
        if ($to < $pgcnt - 1) {
            $arr[" .."] = "";
        }
        if ($to < $pgcnt) {
            $arr[(string)$pgcnt] = $this->Absolute($pgcnt);
        }
        if ($this->current < $pgcnt) {
            $arr[_m("Next")] = $this->Absolute($this->current+1);
        }
        return $arr;
    }

    /** get function
     *  Convert array provided by navarray into HTML code
     *  Commands are added to $url
     * @param $begin
     * @param $end
     * @param $add
     * @param $nopage
     * @return string
     */
    function get($begin='', $end='', $add='class="scroller"', $nopage='', $target=null) {
        // $url = con_url($this->urldefault,"scrl=".$this->id);
        $url = "?scrl=". $this->id;
        $out = '';

        if ($GLOBALS['apc_state']) {
            $url .= '&amp;apc='.$GLOBALS['apc_state']['state'];
        }
        $i   = 0;
        $arr = $this->navarray();

        if ( count($arr) <= 1 ) {
            return $nopage;
        }

        foreach($arr as $k => $v) {
            if ($i++) {
                $out .= " | ";
            }

            if (!$v) {
                $out .= "<a class=\"nolink". (($k == $this->current) ? " active" : 'dots') . "\" href=\"#\">$k</a>";
            } else {
                if ($target) {
                    $out .= "<a href=\"javascript:void(0)\" onclick=\"AA_Ajax('$target','$v');return false;\" $add>$k</a>";
                } else {
                    $out .= "<a href=\"$url&amp;$v\" $add>$k</a>";
                }
            }
        }

        return $begin.$out.$end;
    }
}

class AA_Scroller_View extends AA_Scroller_Sitemodule {

    /** Absolute function
     *  Return part of a query string for move to absolute position $page
     * @param $page
     * @return mixed|null|string|string[]
     */
    function Absolute($page) {
        // used for AJAX scroller in the SEO sitemodule, for example
        $url      = $_SERVER['REQUEST_URI'];
        $replaces = 0;

        $new_url  = preg_replace('/page-(\d+)/', "page-$page", $url, -1, $replaces);
        if ($replaces == 1) {
            return $new_url;
        }
        return get_url($url,'set['.$this->id."]=page-$page");
    }

    /** get function
     *  Convert array provided by navarray into HTML code
     *  Commands are added to $url
     * @param string $begin
     * @param string $end
     * @param string $add
     * @param string $nopage
     * @param string $target
     * @return string
     */
    function get($begin='', $end='', $add='class="scroller"', $nopage='', $target=null) {
        $i   = 0;
        $arr = $this->navarray();
        $out = '';

        if ( count($arr) <= 1 ) {
            return $nopage;
        }

        foreach($arr as $k => $v) {
            if ($i++) {
                $out .= '<span class="delimiter"> | </span>';
            }

            if (!$v) {
                $out .= ctype_digit((string)$k) ? "<span class=\"active\"> $k </span>" : "<span class=\"dots\"> $k </span>";
            } else {
                $v = safe($v);
                if ($target) {
                    $out .= "<a href=\"javascript:void(0)\" onclick=\"AA_Ajax('$target','$v');return false;\" $add>$k</a>";
                } else {
                    $out .= "<a href=\"$v\" $add>$k</a>";
                }
            }
        }

        return $begin.$out.$end;
    }
}
