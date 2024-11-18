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

    protected string $scroller_delimiter;
    protected string $scroller_begin;
    protected string $scroller_end;
    protected string $scroller_add;
    protected string $scroller_nopage;
    protected string $scroller_next;
    protected string $scroller_previous;
    protected string $scroller_all;
    protected string $scroller_length;



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
            'show_all'   => new AA_Property( 'show_all'  , '', 'bool', false, true),
        ];
    }

    /** easy_scroller function
     * Constructor
     *
     * @param  string  $id
     * @param $listlen
     * @param $itmcnt
     * @param $url
     * @param          $current
     */
    function __construct(string $id="", $listlen=10, $itmcnt=0, $url = "", $current=1, bool $show_all=false) {
        $this->id         = $id;
        $this->listlen    = max(1,(int)$listlen);
        $this->urldefault = (string)$url;
        $this->itmcnt     = (int)$itmcnt;
        $this->go2page(floor( $current/$this->listlen ) + 1); // sets $this->current
        $this->show_all   = $show_all;

        $this->scroller_delimiter = ' ';
        $this->scroller_begin     = '';
        $this->scroller_end       = '';
        $this->scroller_add       = '';
        $this->scroller_nopage    = '';
        $this->scroller_next      = _m("Previous");
        $this->scroller_previous  = _m("Next");
        $this->scroller_all       = _m("All");
        $this->scroller_length    = SCROLLER_LENGTH;
    }

    /** factory scroller from itemview object */
    public static function fromItemview(itemview $itemview): static {
        return new static($itemview->slice_info['vid'], $itemview->num_records, $itemview->idcount(), '', $itemview->from_record);
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

    /** Return navigation bar as a hash
     *  labels as keys, query string fragments a values
     * @deprecated - use link() and nav() instead
     */
    protected function navarray(): array
    {
        if (!$this->itmcnt) {
            return [];
        }
        $pgcnt = $this->pageCount();
        $mp    = floor(($this->current - 1) / $this->scroller_length);  // current means current page
        $from  = max(1, $mp * $this->scroller_length);                // $this->scroller_length - number of displayed pages in navbab
        $to    = min(($mp + 1) * $this->scroller_length + 1, $pgcnt);
        $arr   = [];
        if ($this->current > 1) {
            $arr[$this->scroller_previous] = $this->absolute($this->current-1);
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
            $arr[$this->scroller_next] = $this->absolute($this->current+1);
        }
        if ($this->show_all) {
            $arr[$this->scroller_all] = 'listlen=99999';
        }
        return $arr;
    }

    /** Return part of a query string for move to absolute position $page
     * @param $page
     * @deprecated - use link() and nav() instead
     * @return string
     */
    protected function absolute($page): string
    {
        return rawurlencode("scr_{$this->id}_Go") . "=" . rawurlencode($page);
    }

    protected function link(int $page, string $text, array $add_a=[], array $add_url=[], string $target=''): string {
        return a_href(get_url($this->urldefault, ["scr_{$this->id}_Go"=>$page] + $add_url), $text, $add_a) . "\n";
    }

    protected function nav(string $target='', bool $all=false): string
    {
        if (!$this->itmcnt or (($pgcnt = $this->pageCount()) < 2)) {
            return '';
        }

        if ($all) {
            $from = 1;
            $to   = $pgcnt;
        } else {
            $mp   = (int)floor(($this->current - 1) / $this->scroller_length);  // current means current page
            $from = (int)max(1, $mp * $this->scroller_length);                // $this->scroller_length - number of displayed pages in navbab
            $to   = (int)min(($mp + 1) * $this->scroller_length + 1, $pgcnt);
        }
        $arr   = [];

        if (!$all AND $this->current > 1) {
            $arr[] = $this->link($this->current-1, $this->scroller_previous, ['rel'=>'prev'], [], $target);
        }
        if ($from > 1) {
            $arr[] = $this->link(1, '1', [], [], $target);
        }
        if ($from > 2) {
            $arr[] = "<span class=dots>..</span>";
        }
        for ($i = $from; $i <= $to; $i++) {
            if ($i === $this->current+1) {
                $arr[] = $this->link($i, (string)$i, ['rel'=>'next'], [], $target);
            } elseif ($i === $this->current - 1) {
                $arr[] = $this->link($i, (string)$i, ['rel'=>'prev'], [], $target);
            } elseif ($i === $this->current) {
                $arr[] = "<span class=active aria-current=page>$i</span>\n";
            } else {
                $arr[] = $this->link($i, (string)$i, [], [], $target);
            }
        }
        if ($to < $pgcnt - 1) {
            $arr[] = "<span class=dots>..</span>";
        }
        if ($to < $pgcnt) {
            $arr[] = $this->link($pgcnt, (string)$pgcnt, [], [], $target);
        }
        if (!$all AND $this->current < $pgcnt) {
            $arr[] = $this->link($this->current+1, $this->scroller_next, ['rel'=>'next'], [], $target);
        }
        if (!$all AND $this->show_all) {
            $arr[] = $this->link($this->current, (string)$this->current, [], ['listlen'=>99999], $target);
        }
        return implode($this->scroller_delimiter, $arr);
    }

    /**
     *  convert array provided by navarray into HTML code
     *  commands are added to $url
     */
    public function getNavbar(): string {
        $nav = $this->nav();
        return "<nav class=pager role=navigation>$nav <span class=found>(". _m('found').' '. $this->itmcnt .")</span></nav>";
    }

//    /**
//     *  convert array provided by navarray into HTML code
//     *  commands are added to $url
//     */
//    public function getNavbar(): string {
//        $ret = '';
//
//        if ($this->pageCount() > 1) {
//            $arr = $this->navarray();
//            $url = $this->urldefault;
//            foreach ($arr as $k => $v) {
//                $ret .= $this->scroller_delimiter;
//                if ($v) {
//                    if ($k == $this->current+1 || $k == $this->scroller_next) {
//                        $add = ['rel' => 'next'];
//                    } elseif (($k && ($k == $this->current - 1)) || $k == $this->scroller_previous) {
//                        $add = ['rel' => 'prev'];
//                    } else {
//                        $add = [];
//                    }
//                    $ret .= a_href(get_url($url, [$v]), $k, $add) . "\n";
//                } else {
//                    $ret .= ($k == $this->current) ? "<span class=active aria-current=page>$k</span>\n" : "<span class=dots>..</span>\n";
//                }
//            }
//        }
//        return "<nav class=pager role=navigation>$ret <span class=found>(". _m('found').' '. $this->itmcnt .")</span></nav>";
//    }
}

/**	Class AA_Scroller_Easy
 *	Implements navigation bar for scrolling through long lists - for old slice.php
 */
class AA_Scroller_Easy extends AA_Scroller {

    protected function link(int $page, string $text, array $add_a=[], array $add_url=[], string $target=''): string {
        $add_url += ['scrl'=>1];
        $add_a   += ['class'=>'scroller'];
        return a_href(get_url($this->urldefault, ["scr_{$this->id}_Go"=>$page] + $add_url), $text, $add_a) . "\n";
    }

    public function getNavbar(): string {
        $this->scroller_delimiter = ' | ';
        $nav = $this->nav();
        return $nav ? "\n<div class=\"enclose-scroller\" role=\"navigation\" id=\"scroller-{$this->id}\">$nav</div>" : '';
    }

    // /**
    //  *  Convert array provided by navarray into HTML code
    //  *  Commands are added to $url
    //  */
    // public function getNavbar(): string {
    //     $ret = '';
    //     $url = con_url($this->urldefault, ['scrl'=>1]);
    //     $i   = 0;
    //     $arr = $this->navarray();
    //     if ( count($arr) > 1 ) {
    //         $ret .= "\n<div class=\"enclose-scroller\" role=\"navigation\" id=\"scroller-{$this->id}\">";
    //         foreach($arr as $k => $v) {
    //             if ($i++) {
    //                 $ret .=  " | ";
    //             }
    //             if ($v) {
    //                 $ret .= a_href(get_url($url, [$v]), $k, ['class'=>'scroller']) . "\n";
    //                 //$ret .=  "<a href=\"". $url. "&amp;". $v. "\" class=\"scroller\">$k</a>";
    //             } else {
    //                 $ret .=  "<span class=\"scroller_actual\" aria-current=\"page\">$k</span>";
    //             }
    //         }
    //         $ret .= "</div>";
    //     }
    //     return $ret;
    // }
}

/**  Used  just for {scroller} which should not be used in sitemodules (the {pager} is used there which use AA_Scroller_view of $router->scroller)
 */
class AA_Scroller_Sitemodule extends AA_Scroller {

    /** get function
     *  Convert array provided by navarray into HTML code
     *  Commands are added to $url
     *
     * @param $begin
     * @param $end
     * @param $add
     * @param $nopage
     *
     * @return string
     */
    public function get($begin='', $end='', $add='class="scroller"', $nopage='', $target=null): string
    {
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

class AA_Scroller_View extends AA_Scroller {

    /** Return part of a query string for move to absolute position $page
     *  used by navarray
     * @param $page
     * @return mixed|null|string|string[]
     */
    protected function absolute($page): string
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
    public function get($begin='', $end='', $add='class="scroller"', $nopage='', $target=null): string
    {
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

class AA_Scroller_Router extends AA_Scroller {
    protected AA_Router $router;

    /** redefine constructor - just change scroller strings */
    function __construct($id="", $listlen=10, $itmcnt=0, $url = "", $current=1, bool $show_all=false) {
        parent::__construct($id, $listlen, $itmcnt, $url, $current, $show_all);
        $this->scroller_delimiter = '<span class="delimiter"> | </span>';
        $this->scroller_next      = _m("&raquo;");
        $this->scroller_previous  = _m("&laquo;");
    }

    public function setRouter(AA_Router $param): static {
        $this->router = $param;
        return $this;
    }

    protected function link(int $page, string $text, array $add_a=[], array $add_url=[], string $target=''): string {
        $url = $this->router->go2url("xpage={$page}");
        if ($target) {
            // $add_url ignored - used for listlen - not used here
            $add_a   += ['onclick'=>"AA_Ajax('$target','$url');return false;"];
            return a_href('javascript:void(0)', $text, $add_a) . "\n";
        }
        return a_href(get_url($url, $add_url), $text, $add_a) . "\n";
    }

    /** view scroller function
     */
    public function scroller(string $target=''): string {
        $nav = $this->nav($target);
        return $nav ? $this->scroller_begin. $nav . $this->scroller_end : $this->scroller_nopage;

//        //$page       = floor( $itemview->from_record/$itemview->num_records ) + 1;
//        //$max        = floor(($itemview->idcount() - 1) / max(1,$itemview->num_records)) + 1;
//
//        $page       = $this->current;
//        $max        = $this->pageCount();
//
//        if ($max<=1) {
//            return $this->scroller_nopage;
//        }
//        if ($page<1)    { $page = 1; }
//        if ($page>$max) { $page = $max; }
//
//        $nav_arr = $this->navarray();
//        $add     = $this->scroller_add;
//        $arr     = [];
//
//        foreach ( $nav_arr as $k => $v) {
//            if ( $v ) {
//                if ($target) {
//                    $arr[] = "<a href=\"javascript:void(0)\" onclick=\"AA_Ajax('$target','$v');return false;\" $add>$k</a>";
//                } else {
//                    $arr[] = "<a href=\"$v\" $add>$k</a>";
//                }
//            } else {
//                $arr[] = ctype_digit((string)$k) ? "<span class=\"active\"> $k </span>" : "<span class=\"dots\"> $k </span>";
//            }
//        }
//
//        return $this->scroller_begin. implode($this->scroller_delimiter, $arr) . $this->scroller_end;
    }

    /** decorates listing $html and adds infinite scroller */
    public function infinite(string $html): string {
        $this->scroller_delimiter = '';
        $hash   = get_short_hash('pg_inf', $this->id);
        $cmdvar = 'aapg_'.$hash;
        $nav = $this->nav('', true);

        // data-aa-part-update=add - for AA_SiteUpdateParts to append the content; hidden nav is used for infinite loading
        $ret  = "<div data-aa-part=\"$hash\" data-aa-part-update=add>$html</div><nav id='aapg-nav-$hash' style='visibility: hidden'>$nav</nav>";

        AA::Stringexpander()->setDependentParts([$cmdvar], $hash, $html);
        AA::Stringexpander()->addRequire('aa-jslib');  // for AA_SiteEdit, ...

        $js = <<<JS
            document.addEventListener('DOMContentLoaded', () => {
                const loadPageContent = async (url) => {
                    const headers = {'X-Requested-With': 'xmlhttprequest'};
                    url += '?$cmdvar=1&aaedit=$cmdvar';
                    fetch(url, { method: 'GET', credentials: 'same-origin', headers: headers })
                        .then(response => response.json())
                        .then(data => AA__SiteUpdateParts(data));
                };
                // loading of previous page on demand
                // const loadPreviousButton = document.getElementById('load-previous');
                // loadPreviousButton.addEventListener('click', async () => {
                //     if (currentPage > 1) {
                //         currentPage--;
                //         await loadPageContent(currentPage);
                //         window.history.pushState({}, '', `/cz${currentPage}/aktualne`);
                //     }
                //     if (currentPage <= 1) {
                //         loadPreviousButton.style.display = 'none';
                //     }
                // });
                const loadMoreObserver = new IntersectionObserver(entries => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            const nav = entry.target;
                            // const nav = document.getElementById('aapg-nav-$hash');
                            const current = nav.querySelector('.active'); 
                            const next = current.nextElementSibling;
                            // move class=active and aria-current=page to the next 
                            if (next) {
                                current.classList.remove('active');
                                current.removeAttribute('aria-current');
                                next.classList.add('active');
                                next.setAttribute('aria-current', 'page');
                                const nextUrl = next.getAttribute('href');
                                loadPageContent(nextUrl);
                                window.history.pushState({}, '', nextUrl);  // plus bonus
                            }
                        }
                      });                    
                                        
                }, { rootMargin: "0px 0px 200px 0px" });
                loadMoreObserver.observe(document.getElementById('aapg-nav-$hash'));
            });
            JS;
        AA::Stringexpander()->addRequire($js, 'AA_Req_Run');

        return $ret;
    }
}

