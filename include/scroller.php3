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
 * @package   Include
 * @version   $Id$
 * @author    Jiri Hejsek, Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

/**	Class AA_Scroller
 *	Implements navigation bar for scrolling through long lists
 */


/** userdate2sec function
 *  tranformation from english style datum (3/16/1999 or 3/16/99) to mySQL date
 * break year for short year description is 1950
 * @param $dttm
 * @param $time
 */
function userdate2sec($dttm, $time="") {
    if ( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{4}) *$", $dttm, $part))
        if ( !ereg("^ *([[:digit:]]{1,2}) */ *([[:digit:]]{1,2}) */ *([[:digit:]]{2}) *$", $dttm, $part))
            return "";
    if ( !ereg("^ *([[:digit:]]{1,2}) *: *([[:digit:]]{1,2}) *: *([[:digit:]]{1,2}) *$", $time, $tpart))
        return mktime(0,0,0,$part[1],$part[2],$part[3]);
    else
        return mktime($tpart[1],$tpart[2],$tpart[3],$part[1],$part[2],$part[3]);
}

require_once AA_INC_PATH . "statestore.php3";

class AA_Scroller extends AA_Storable {
    var $pgcnt;			            // total page count
    var $current          = 1;		// current page
    var $id;			            // scroller id
    var $visible          = 1;
    var $sortdir          = 1;
    var $sortcol          = "";
    var $filters;
    var $itmcnt;                    // total item count
    var $metapage         = 10;	    // "metapage" size
    var $urldefault;		        // cache self url

    // needed for PHPlib's session storing.
    // @todo rewrite PHPlib's sessions to AA_Storable approach (since there is
    // problem that you need to have class already defined before you try to
    // get data from session. It is not so good, since you need to include all the
    // class definition files which could be potentialy stored in the session
    var $classname        = "AA_Scroller";
    var $persistent_slots = array("pgcnt", "current", "id", "visible", "sortdir",
                                  "sortcol", "filters", "itmcnt", "metapage", "urldefault");

    /** getClassProperties function
     *  Used parameter format (in fields.input_show_func table)
     */
    function getClassProperties() {  //  id             name          type   multi  persistent - validator, required, help, morehelp, example
        return array (
            'pgcnt'      => new AA_Property( 'pgcnt'     , _m('Pgcnt'     ), 'int',  false, true),
            'current'    => new AA_Property( 'current'   , _m('Current'   ), 'int',  false, true),
            'id'         => new AA_Property( 'id'        , _m('Id'        ), 'text', false, true),
            'visible'    => new AA_Property( 'visible'   , _m('Visible'   ), 'bool', false, true),
            'sortdir'    => new AA_Property( 'sortdir'   , _m('Sortdir'   ), 'int',  false, true),
            'sortcol'    => new AA_Property( 'sortcol'   , _m('Sortcol'   ), 'text', false, true),
            'filters'    => new AA_Property( 'filters'   , _m('Filters'   ), 'text', true,  true),  // @todo - should be better specified, since it is in fact array of arrays
            'itmcnt'     => new AA_Property( 'itmcnt'    , _m('Itmcnt'    ), 'int',  false, true),
            'metapage'   => new AA_Property( 'metapage'  , _m('Metapage'  ), 'int',  false, true),
            'urldefault' => new AA_Property( 'urldefault', _m('Urldefault'), 'text', false, true)
            );
    }

    /** AA_Scroller function
     *  constructor
     * @param $id identifies scroller on a web page
     * @param $ulr
     * @param $pgcnt is the number of pages to scroll
     */
    function AA_Scroller($id = "", $url = "", $pgcnt = 0) {
        $this->id         = $id;
        $this->pgcnt      = $pgcnt;
        $this->urldefault = $url;
        $this->filters    = array();
        $this->current    = 1;
        $this->metapage   = 10;
        $this->visible    = 1;
    }

    /** relative function
     *  return part of a query string for move of $pages relative of current position
     * @param $pages
     */
    function relative($pages) {
        return urlencode("scr_" . $this->id . "_Mv") . "=" . urlencode($pages);
    }

    /** absolute function
     *  return part of a query string for move to absolute position $page
     * @param $page
     */
    function absolute($page) {
        return urlencode("scr_" . $this->id . "_Go") . "=" . urlencode($page);
    }

    /** pVisButton function
     *  print Toggle Visibility button
     * @param $url
     * @param $show
     * @param $hide
     */
    function pVisButton($url = "",
                        $show = "<img src=\"../images/expand.gif\" border=\"0\" align=\"left\" alt=\"Expand\">",
                        $hide = "<img src=\"../images/collapse.gif\" border=\"0\" align=\"left\" alt=\"Collapse\">") {
        if (!$url) {
            $url = $this->urldefault;
        }
        echo "<a href=\"$url". $this->ToggleVis(). "\">". ($this->visible ? $hide : $show) . "</a>";
    }

    /** toggleVis function
     *  return part of a query string for move to toggle visibility
     */
    function toggleVis() {
        return urlencode("scr_" . $this->id . "_Vi") . "=" . ($this->visible ? "0" : "1");
    }

    /** sort function
     * @param $sortcol
     *  @return part of a query string for move to toggle visibility
     */
    function sort($sortcol) {
        return urlencode("scr_" . $this->id . "_Sort") . "=" . urlencode($sortcol);
    }

    /** pSort function
     * print sort label
     * @param $sortcol
     * @param $show
     * @param $url
     */
    function pSort($sortcol, $show, $url = "") {
        if (!$url) {
            $url = $this->urldefault;
        }
        echo "<a href=\"$url" . $this->Sort($sortcol) . "\">$show";
        if ($this->sortcol == $sortcol && $this->sortdir) {
            echo "<img src=\"../images/sort" . $this->sortdir . ".gif\" border=\"0\">";
        }
        echo "</a>";
    }

    /** sortSql function
     *  @return "order by" sql clause
     */
    function sortSql() {
        return ($this->sortcol && $this->sortdir) ? " ORDER BY $this->sortcol ". ($this->sortdir == 2 ? "desc" : "") : '';
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

    /** adjustSize function
     *  adjust number of pages
     * deprecated - use coutPages instead
     * @param $pgcnt
     */
    function adjustSize($pgcnt) {
        $this->pgcnt = $pgcnt;
        $this->checkBounds();
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
    /** setSort function
     * @param $column
     * @param $desc
     */
    function setSort($column, $desc="") {
        $this->sortcol = $column;
        $this->sortdir = ( $desc ? 2 : 1 );
    }

    /** updateScr function
     *  process query string and execute commands for this scroller
     *  query string is taken from global variables
     * @param $url
     */
    function updateScr($url = "") {

        $this->updateFilters();
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
        if ($GLOBALS["scr_" . $this->id . "_Sort"]) {
            $sortcol = $GLOBALS["scr_" . $this->id . "_Sort"];
            $this->sortdir = ($sortcol == $this->sortcol) ? (($this->sortdir + 1) % 3) : 1;
            $this->sortcol = $sortcol;
        }
        $this->checkBounds();
    }

    /** navarray function
     * @return navigation bar as a hash
     * labels as keys, query string fragments a values
     */
    function navarray() {
        if (!$this->pgcnt) {
            return array();
        }
        $mp   = floor(($this->current - 1) / SCROLLER_LENGTH);  // current means current page
        $from = max(1, $mp * SCROLLER_LENGTH);                // SCROLLER_LENGTH - number of displayed pages in navbab
        $to   = min(($mp + 1) * SCROLLER_LENGTH + 1, $this->pgcnt);
        if ($this->current > 1) {
            $arr["<<"]  = $this->relative(-1);
        }
        if ($from > 1) {
            $arr["1"]   = $this->absolute(1);
        }
        if ($from > 2) {
            $arr[".. "] = "";
        }
        for ($i = $from; $i <= $to; $i++) {
            $arr[(string)$i] = ($i == $this->current ? "" : $this->absolute($i));
        }
        if ($to < $this->pgcnt - 1) {
            $arr[" .."] = "";
        }
        if ($to < $this->pgcnt) {
            $arr[(string) $this->pgcnt] = $this->absolute($this->pgcnt);
        }
        if ($this->current < $this->pgcnt) {
            $arr[">>"] = $this->relative(1);
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
        $arr       = $this->navarray();
        $url       = $this->urldefault;

        while (list($k, $v) = each($arr)) {
            echo $delimiter;
            $delimiter = " | ";
            if ($v) {
                echo "<a href=\"".get_url($url,array($v))."\" class=\"scroller\">$k</a>\n";
            } else {
                echo "<span class=\"scroller_actual\">$k</span>\n";
            }
        }
        echo "| <a href=\"" . get_url($url,array("listlen=99999")) ."\" class=\"scroller\">" . _m("All") . "</a>";
        echo " &nbsp; (". $this->itmcnt .")";
    }

    /** addFilter function
     *  add filter
     * @param $name
     * @param $type: "char", "date", "int","md5" (other can be added)
     * @param $value
     * @param $truename
     */
    function addFilter($name, $type, $value = "", $truename = "") {
        $this->filters[$name]['value']    = $value;
        $this->filters[$name]['type']     = $type;
        $this->filters[$name]['truename'] = $truename;   // truename is for storing names like "categories.name" which cannot by real names for php variables
    }

    /** updateFilters function
     *  process query string, execute commands for filters
     */
    function updateFilters() {
        foreach ($this->filters as $name => $flt) {
            if (isset($GLOBALS["flt_" . $this->id . "_${name}_val"])){
                $this->filters[$name]['value'] = $GLOBALS["flt_" . $this->id . "_${name}_val"];
            }
        }
    }

    /** sqlCondFilter function
     *  return sql "where" clause generated by filters
     */
    function sqlCondFilter() {
        foreach ($this->filters as $name => $flt) {
            if (!$flt['value']) {
                continue;
            }
            if (ereg("^ *(>|<|=|>=|<=) *(.*)", $flt['value'], $regs)) {
                $op    = $regs[1];
                $value = $regs[2];
            } else {
                $op    = ($flt['type'] == "char" ? "like" : "=");
                $value = $flt['value'];
            }
            if ( $flt['truename'] != "" ) {
                $name = $flt['truename'];
            }
            switch($flt['type']) {
                case "char":
                    if ($op == "like") {
                        $value = "%$value%";
                    }
                    $cond[] = "$name $op '$value'";
                    break;
                case "int":
                    $cond[] = "$name $op " . $value;
                    break;
                case "date":
                    $cond[] = "$name $op '". userdate2sec($value) . "'";
                    break;
                case "md5":
                    $cond[]= "$name $op '". quote(pack("H*",$value))."'";
            }
        }
        return is_array($cond) ? join(" AND ", $cond) : "1 = 1";
    }
}

?>