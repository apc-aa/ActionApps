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


/**	Scroller - Implements navigation bar for scrolling through long lists.
 */
class AA_Scroller extends AA_Storable {
    /** null values just for upgrade from older AA and scrollers in sessions */
    protected ?int    $current = 1;	   /** current page     */
    protected ?string $id;			   /** scroller id - identifies scroller on a web page */
    protected ?int    $itmcnt;         /** total item count */
    protected ?int    $listlen = 10;   /** page size  */
    protected ?string $urldefault;	   /** cache self url   */
    protected ?bool   $show_all;	   /** if true, scroller will show also 'All' option */

    /** getClassProperties function of AA_Serializable
     *  Used parameter format (in fields.input_show_func table)
     * @return array
     */
    public static function getClassProperties(): array {  //  id             name          type   multi  persistent - validator, required, help, morehelp, example
        return [
            'current'    => new AA_Property( 'current'   , '', 'int',  false, true),
            'id'         => new AA_Property( 'id'        , '', 'text', false, true),
            'itmcnt'     => new AA_Property( 'itmcnt'    , '', 'int',  false, true),
            'listlen'    => new AA_Property( 'listlen'   , '', 'int',  false, true),
            'urldefault' => new AA_Property( 'urldefault', '', 'text', false, true),
            'show_all'   => new AA_Property( 'show_all'  , '', 'bool', false, true)
        ];
    }

    /** easy_scroller function
     * Constructor
     *
     * @param  string  $id
     * @param $url
     * @param $listlen
     * @param $itmcnt
     */
    function __construct(string $id="", $url = "", $listlen=10, $itmcnt=0, bool $show_all=false) {
        $this->id         = $id;
        $this->listlen    = (int)$listlen;
        $this->urldefault = (string)$url;
        $this->itmcnt     = (int)$itmcnt;
        $this->current    = 1;
        $this->show_all   = $show_all;
    }

    /** current_id could be undefined when we deserialize older object */
    public function __wakeup() {
        $this->itmcnt  ??= 0;
        $this->listlen ??= 0;
        $this->current ??= 1;
    }


    /** setShowAll function
     * @param $val
     */
    function setShowAll($val): static
    {
        $this->show_all = $val;
        return $this;
    }

    /**  */
    function setListlen($val): static
    {
        $this->listlen = (int)$val;
        return $this;
    }

    /** getListlen function    */
    function getListlen() {
        return $this->listlen;
    }

    /** get current number of items  */
    public function getItemCount(): int {
        return $this->itmcnt;
    }

    public function getCurrent(): int
    {
        return $this->current;
    }

    /** go2page function
     * @param $page
     */
    function go2page($page): static
    {
        $this->current = (int)$page;
        $this->checkBounds();
        return $this;
    }

    /** Return part of a query string for move to absolute position $page
     * @param $page
     * @return string
     */
    function absolute($page): string
    {
        return rawurlencode("scr_{$this->id}_Go") . "=" . rawurlencode($page);
    }

    /** pageCount function */
    function pageCount(): int
    {
        return (int)floor(($this->itmcnt - 1) / max(1,$this->listlen)) + 1;
    }

    /** checkBounds function
     * Keep current page within bounds
     */
    function checkBounds() {
        $this->current = max(1, min($this->current, $this->pageCount()));
    }

    /** Adjust number of pages depends on item count and metapage
     * @param $itmcnt
     */
    function countPages($itmcnt): static
    {
        $this->itmcnt = (int)$itmcnt;
        $this->checkBounds();
        return $this;
    }

    /** update function
     *  Process query string and execute commands for this scroller
     *  (based on $itmcnt)
     */
    function update() {
        if ($_GET["scr_{$this->id}_Go"]) {
            $this->go2page((int)$_GET["scr_{$this->id}_Go"]);
        }
        if ($_GET["scr_{$this->id}_Mv"]) {  // old Relative links - not used anymore
            $this->go2page($this->current + (int)$_GET["scr_{$this->id}_Mv"]);
        }
    }

    /** navarray function
     *  Return navigation bar as a hash
     *  labels as keys, query string fragments a values
     */
    function navarray() {
        if (!$this->itmcnt) {
            return [];
        }
        $pgcnt = $this->pageCount();
        $mp    = floor(($this->current - 1) / SCROLLER_LENGTH);  // current means current page
        $from  = max(1, $mp * SCROLLER_LENGTH);                // SCROLLER_LENGTH - number of displayed pages in navbab
        $to    = min(($mp + 1) * SCROLLER_LENGTH + 1, $pgcnt);
        $arr   = [];
        if ($this->current > 1) {
            $arr[_m("Previous")]     = $this->absolute($this->current-1);
        }
        if ($from > 1) {
            $arr["1"]   = $this->absolute(1);
        }
        if ($from > 2) {
            $arr[".. "] = "";
        }
        for ($i = $from; $i <= $to; $i++) {
            $arr[(string)$i] = ($i==$this->current ? "" : $this->absolute($i));
        }
        if ($to < $pgcnt - 1) {
            $arr[" .."] = "";
        }
        if ($to < $pgcnt) {
            $arr[(string)$pgcnt] = $this->absolute($pgcnt);
        }
        if ($this->current < $pgcnt) {
            $arr[_m("Next")] = $this->absolute($this->current+1);
        }
        if ($this->show_all) {
            $arr[_m("All")] = 'listlen=99999';
        }
        return $arr;
    }

    /**
     *  convert array provided by navarray into HTML code
     *  commands are added to $url
     */
    function getNavbar(): string {
        $arr = $this->navarray();
        $url = $this->urldefault;

        $ret = '';

        if ($this->pageCount() > 1) {
            foreach ($arr as $k => $v) {
                $ret .= " ";
                if ($v) {
                    if ($k == $this->current+1 || $k == _m("Next")) {
                        $add = ['rel' => 'next'];
                    } elseif (($k && ($k == $this->current - 1)) || $k == _m("Previous")) {
                        $add = ['rel' => 'prev'];
                    } else {
                        $add = [];
                    }
                    $ret .= a_href(get_url($url, [$v]), $k, $add) . "\n";
                } else {
                    $ret .= ($k == $this->current) ? "<span class=active aria-current=page>$k</span>\n" : "<span class=dots>..</span>\n";
                }
            }
        }
        return "<nav class=pager role=navigation>$ret <span class=found>(". _m('found').' '. $this->itmcnt .")</span></nav>";
    }
}

/**	Class AA_Scroller_Easy
 *	Implements navigation bar for scrolling through long lists - for old slice.php
 */
class AA_Scroller_Easy extends AA_Scroller {

    /**
     *  Convert array provided by navarray into HTML code
     *  Commands are added to $url
     */
    function getNavbar(): string {
        $ret = '';
        $url = con_url($this->urldefault, ['scrl'=>1]);
        $i   = 0;
        $arr = $this->navarray();
        if ( count($arr) > 1 ) {
            $ret .= "\n<div class=\"enclose-scroller\" role=\"navigation\" id=\"scroller-{$this->id}\">";
            foreach($arr as $k => $v) {
                if ($i++) {
                    $ret .=  " | ";
                }
                if ($v) {
                    $ret .= a_href(get_url($url, [$v]), $k, ['class'=>'scroller']) . "\n";
                    //$ret .=  "<a href=\"". $url. "&amp;". $v. "\" class=\"scroller\">$k</a>";
                } else {
                    $ret .=  "<span class=\"scroller_actual\" aria-current=\"page\">$k</span>";
                }
            }
            $ret .= "</div>";
        }
        return $ret;
    }
}

// Used  just for {scroller} which should not be used in sitemodules (the {pager} is used there which use AA_Scroller_view of $router->scroller)*/
class AA_Scroller_Sitemodule extends AA_Scroller {

    /**Constructor
     *
     * @param $id
     * @param $listlen
     * @param $itmcnt
     * @param $curr
     */
    function __construct($id = "", $listlen=10, $itmcnt=0, $curr=0) {
        parent::__construct($id, "", $listlen, $itmcnt);
        $this->go2page(floor( $curr/$this->listlen ) + 1);
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
            } elseif ($target) {
                $out .= "<a href=\"javascript:void(0)\" onclick=\"AA_Ajax('$target','$v');return false;\" $add>$k</a>";
            } else {
                $out .= "<a href=\"$url&amp;$v\" $add>$k</a>";
            }
        }

        return $begin.$out.$end;
    }
}

class AA_Scroller_View extends AA_Scroller_Sitemodule {

    /** Return part of a query string for move to absolute position $page
     * @param $page
     * @return mixed|null|string|string[]
     */
    function absolute($page): string
    {
        // used for AJAX scroller in the SEO sitemodule, for example
        $url      = $_SERVER['REQUEST_URI'];
        $replaces = 0;

        $new_url  = preg_replace('/page-(\d+)/', "page-$page", $url, -1, $replaces);
        if ($replaces == 1) {
            return $new_url;
        }
        return get_url($url,'set['.$this->id."]=page-$page");
    }

    /**
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
            } elseif ($target) {
                $out .= "<a href=\"javascript:void(0)\" onclick=\"AA_Ajax('$target','$v');return false;\" $add>$k</a>";
            } else {
                $out .= "<a href=\"$url&amp;$v\" $add>$k</a>";
            }
        }

        return $begin.$out.$end;
    }
}
