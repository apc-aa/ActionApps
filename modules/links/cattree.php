<?php

/**
 * File contains definition of cattree class - handles tree of categories
 *
 * Should be included to other scripts
 *
 * @package Links
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

/** For Links_QueryZids() - called also from /misc/toexecute.php */

use AA\IO\DB\DB_AA;
use AA\Later\LaterInterface;
use AA\Later\Toexecute;
use AA\Util\SingletonTrait;

require_once __DIR__ . "/../../modules/links/linksearch.php";


/** Category assignments - stores which category is subcategory of another */
class catassignment
{
    public int $from;
    public int $to;
    public string $base;
    public string $state;

    /** just constructor - variable assignments */
    public function __construct($from, $to, $base, $state)
    {
        $this->from  = (int) $from;
        $this->to    = (int) $to;
        $this->base  = (string) $base;
        $this->state = (string) $state;
    }

    public function getFrom(): int
    {
        return $this->from;
    }
    public function getTo(): int
    {
        return $this->to;
    }
    public function getBase(): string
    {
        return $this->base;
    }
    public function getState(): string
    {
        return $this->state;
    }
}

/**
 * cattree class - handles tree of categories
 * @method static singleton()
 */
class cattree
{
    use SingletonTrait;

    public $treeStart;      // where to start - category root
    public $go_to_empty;    // boolean - should we go to the empty subcategories?
    public $path_delimiter; // string to show between categories in path

    public $catnames;       // asociative array with names of columns and values of current row
    public $catpaths;       // asociative array with paths to categories
    public $catnolinks;     // asociative array with 'nolinks' category paremeters
    /**
     * @var catassignment[]
     */
    public $assignments;    // category assignments - stores which category is subcategory of another
    public $ancesors_idx;   // for each category it defines array of ancessors - used just for speedup (walkTree)

    public const STATES_CODING = ['highlight' => '!', 'visible' => '-', 'hidden' => 'x'];

    // constructor
    public function __construct($treeStart = -1, $go_to_empty = false, $path_delimiter = ' > ')
    {
        $this->treeStart      = $treeStart;
        $this->go_to_empty    = $go_to_empty;
        $this->path_delimiter = $path_delimiter;
    }

    /** Compare function for sorting categories in subcategory
     *  The sort oder is by name, but general categories goes as last and in its
     *  own order
     * @param object $a ,$b - instances of catassignment class
     * @return int
     */
    public function cmp_assignments($a, $b): int
    {
        $aname = $this->catnames[$a->getTo()];
        $bname = $this->catnames[$b->getTo()];
        $apri  = (int) Links_GlobalCatPriority($aname);
        $bpri  = (int) Links_GlobalCatPriority($bname);

        if ($apri === $bpri) {
            return strcmp($aname, $bname);
        }
        return (($apri < $bpri) ? -1 : 1);
    }

    /** Sort categories assignments by name - general categories goes at the end*/
    public function sort_categories(): void
    {
        usort($this->assignments, [$this, "cmp_assignments"]);
    }

    public function update(): void
    {
        $db = getDB();
        unset($this->catnames);
        unset($this->catpaths);
        unset($this->catnolinks);
        unset($this->assignments);
        unset($this->ancesors_idx);
        $this->ancesors_idx = [];

        // lookup - all categories names
        $SQL = " SELECT id, name, path, nolinks FROM links_categories WHERE deleted='n'";
        $db->query($SQL);
        while ($db->next_record()) {
            $cid = $db->f('id');
            $this->catnames[$cid]   = safe($db->f('name'));
            $this->catpaths[$cid]   = $db->f('path');
            $this->catnolinks[$cid] = $db->f('nolinks');
        }

        // lookup - category tree
        $SQL = " SELECT category_id, what_id, base, state
                  FROM links_cat_cat, links_categories
                 WHERE links_cat_cat.what_id = links_categories.id
                 ORDER BY name";

        $db->query($SQL);
        while ($db->next_record()) {
            $this->assignments[] = new catassignment(
                $db->f('category_id'),
                $db->f('what_id'),
                $db->f('base') === 'n' ? '@' : ' ',
                self::STATES_CODING[$db->f('state')],
            );
        }

        freeDB($db);

        $this->sort_categories();
        foreach ($this->assignments as $idx => $assig) {
            $this->ancesors_idx[$assig->getFrom()][] = $idx;
        }
    }

    /** Not filled yet? ==> Fill it from database  */
    public function updateIfNeeded(): void
    {
        if (!isset($this->catnames) || !is_array($this->catnames)) {
            $this->update();
        }
    }


    /** Search category $parenid, if there exist subcategory of name $name
    *  @returns int|false - id of found category or false
    */
    public function subcatExist($parentid, $name): false|int
    {
        $this->updateIfNeeded();
        if (isset($this->ancesors_idx) && is_array($this->ancesors_idx[$parentid])) {
            foreach ($this->ancesors_idx[$parentid] as $idx) {
                $assig = $this->assignments[$idx];
                debug('<br>====? ', $this->catnames[$assig->getTo()], $name);
                if ($this->catnames[$assig->getTo()] == $name) {
                    return $assig->getTo();
                }
            }
        }
        return false;
    }

    /** Exist the categoery in the cattree? */
    public function exists($cid): bool
    {
        $this->updateIfNeeded();
        return !is_null($this->catnames[$cid]);
    }

    /** Ensures, that the category exists in $cid and if not, create it
    *  Returns category id of found (or created) category
    */
    public function ensureExists($cid, $name)
    {
        debug("ensureExists($cid, $name", $this->getName($cid));
        if ($this->getName($cid) == $name) {
            $sub_cat_id = $cid;   // do not create general into general (akce->akce)
        } else {
            $sub_cat_id = $this->subcatExist($cid, $name);
        }
        if (!$sub_cat_id) {
            $sub_cat_id = Links_AddCategory($name, $cid, $this->getPath($cid));
            Links_AssignCategory($cid, $sub_cat_id, Links_GlobalCatPriority($name));
            $this->update();
        }
        return $sub_cat_id;
    }


    /** Returs name of category given by its id */
    public function getName($cid)
    {
        $this->updateIfNeeded();
        return $this->catnames[$cid];
    }

    /** Returs name of category given by its id */
    public function getPath($cid)
    {
        $this->updateIfNeeded();
        return $this->catpaths[$cid];
    }

    /** Returs name of category given by its id */
    public function isNolinks($cid)
    {
        $this->updateIfNeeded();
        return $this->catnolinks[$cid];
    }

    /** Returs category id of parent category */
    public function getParent($cid): false|string
    {
        $this->updateIfNeeded();
        $ids = explode(",", $this->getPath($cid));
        if (count($ids) < 2) {
            return false;
        }
        return $ids[count($ids) - 2];
    }

    /** Get name of general category, if it is general category */
    public function isGeneral($cid)
    {
        $this->updateIfNeeded();
        return Links_IsGlobalCategory($this->getName($cid));
    }



    // Transforms path to named path with links ( <a href=...>Base</a> > <a ...)
    //   based on $translate array; skips first "skip" fields
    //   url: ""      - do not make links on categories
    //        url     - make links to categories except the last one
    //   whole - if set, make links to all categories
    public function getNamePath($cid, $skip = 0, $separator = " > ", $url = false, $whole = false, $target = ""): string
    {
        $this->updateIfNeeded();
        $path         = $this->getPath($cid);
        $target_atrib = (($target != "") ? " target=\"$target\" " : "");
        $ids          = explode(",", $path);
        $last         = end($ids);
        $delimiter    = '';
        if (isset($ids) && is_array($ids)) {
            if ($url) {
                foreach ($ids as $catid) {
                    if (--$skip >= 0) {
                        continue;
                    }
                    if ($catid !== $last || $whole) { // do not make link for last category
                        $name .= "$delimiter<a href=\"$url$catid\" $target_atrib>" . $this->getName($catid) . "</a>";
                    } else {
                        $name .= $delimiter . $this->getName($catid);
                    }
                    $delimiter = $separator;
                }
            } else {
                foreach ($ids as $catid) {
                    if (--$skip >= 0) {
                        continue;
                    }
                    $name .= $delimiter . $this->getName($catid);
                    $delimiter = $separator;
                }
            }
        }
        return $name;
    }

    /**
    * Prints javascript which defines necessary javascript variables for category
    * tree. There must be already included js_lib_links.min.js file on the page
    * in order ClearListbox(), GoCategory() and ChangeCategory() are defined
    *
    * @param string $fromId special string which in conjunction with $toId defines
    *                       the tree for javascript (see Links_GetTreeDefinition)
    * @param string $toId (see fromId, Links_GetTreeDefinition())
    * @param string special string identifying if category $base{n} is base categ.
    */
    public function printTreeData($treeStart = -1, $select_depth = 1, $print_general = false): void
    {
        $this->updateIfNeeded();
        if ($treeStart == -1) {
            $treeStart = $this->treeStart;
        }

        // generate strings for javascript
        $delim = '';
        foreach ((array) $this->assignments as $assig) {
            $to = $assig->getTo();
            if (!$print_general && $this->isGeneral($to)) {
                // do not display global categories in the tree. General
                // categories are automatic - user can't should not see it in
                // admin interface
                continue;
            }
            $fromString   .= $delim . $assig->getFrom();
            $toString     .= $delim . $assig->getTo();
            $baseString   .= $delim . "'" . $assig->getBase() . "'";
            $delim         = ',';
        }

        // make 00100110011... string matching global categories
        $general = new bitfield();
        foreach (array_keys((array) $this->catnames) as $cid) {
            $general->setbit($cid, $this->isGeneral($cid));
        }

        $js = '
        // data ----------------------------------------------
        // ALL GLOBAL (and we count with it - HM-2024)
        s=[' . $fromString . ']
        t=[' . $toString . ']
        b=[' . $baseString . ']
        general = "' . $general->getAsString() . '"

        var assignno = s.length    // number of category assignments
        var level = 0              // current depth of tree path
        var treeStart = ' . $this->treeStart . '
        var select_depth = ' . $select_depth . '
        var go_into_empty_cat = ' . ($this->go_to_empty ? 'true' : 'false') . '
        var path_delimiter    = "' . $this->path_delimiter . '"
        a=[]' . "\n";

        // This part is not cached - There were some problems in Firefox, when
        // treeStart was undefined, then

        FrmJavascript($js);
        $js = '';

        // create javascript category id->name translation table:
        // a[334]=a[324]="Organizations"
        // We collect more than one asignment to one row in order the resulting
        // js code is as short as possible (see example above)
        // It is not possible however to put ALL asignments for one name to one
        // row since Firefox (at least 1.0.6) say "too much recursion", so we
        // the asignments to group of max 100 categories
        $names_count = [];
        define('MAX_JS_NODES_AT_LINE', 100);
        $js_arr = [];
        foreach ($this->catnames as $allId => $allName) {
            if (!$names_count[$allName]) {
                $names_count[$allName] = 1;
            }
            $names_count[$allName]++;
            $index_name = (string) floor($names_count[$allName] / MAX_JS_NODES_AT_LINE) . "~$allName";
            $js_arr[$index_name] .= "a[$allId]=";
        }
        foreach ($js_arr as $allName => $allIds) {
            [,$name] = explode('~', $allName, 2);
            $js .= $allIds . "\"$name\"\n";
        }

        $js .=  '
        downcat = new Array()
        downcat[level] = treeStart // stores path form root to current category';
        echo getFrmJavascriptCached($js, 'cattree', '');  // no async, no deffer - we need it imediately on the page, otherways there will be "undefined" in the tree
    }


    /**
     * Prints javascript which changes tree to given category
     * There must be already included js_lib_links.min.js file on the page
     * in order ClearListbox(), GoCategory() and ChangeCategory() are defined
     *
     * @param int $cid id of category to switch to (this->treeStart must
     *                      be on the path
     * @param int $pathDiv <div> //id where the path should be displayed
     * @param int $cat_id_field hidden form field which stores selected category
     * @return string
     */
    public function goCategory($cid, $pathDiv = "", $cat_id_fld = "", $form = ""): string
    {
        return getFrmJavascript("GoToCategoryID('$cid', eval('document.$form.tree'), '$pathDiv', '$cat_id_fld')\n", true);
    }

    /**
     * Returns multiple selectbox which behaves like category tree
     * Links_PrintTreeData() function must be called first (to define javascript
     * variables.
     * @param $onWhat
     * @param int $cat2show
     * @param string $pathDiv
     * @param string $cat_id_fld
     * @param string $form
     * @param int $width
     * @param int $rows
     * @param string $in_form
     * @return string selectbox prepared to print
     */
    public function getFrmTree($onWhat, $cat2show = -1, $pathDiv = "", $cat_id_fld = "", $form = "", $width = 250, $rows = 8, $in_form = ""): string
    {
        $this->updateIfNeeded();
        if ($cat2show == -1) {
            $cat2show = $this->treeStart;
        }
        $on = (($onWhat === 'change')
            ? "onchange=\"GoToCategoryID('', this, '$pathDiv', '$cat_id_fld')\""
            : (($onWhat === 'dblclick')
            ? "ondblclick=\"GoToCategoryID('', this, '$pathDiv', '$cat_id_fld')\"
            onchange=\"ChangeSelectedCat('', this, '$pathDiv', '$cat_id_fld')\""
            : ''));


        $ret = ($form ? '<form name="' . $form . '">' : '');
        $ret .= $this->getFrmCatTree($on, $width, 'tree', $rows);
        $ret .= ($form ? '</form>' : '');

        $used_form = ($form ?: $in_form);
        $ret .= $this->goCategory($cat2show, $pathDiv, $cat_id_fld, $used_form);

        // for selectbox which use doubleclick we have to print also 'GO' button,
        // because Netscape 4 do not support dblclick event
        $js_form = "eval(document.$used_form.tree)";
        if ($onWhat === 'dblclick') {
            $ret .= '<div><br>
            <a class=aa-button href="javascript:GoToCategoryID(\'\', ' . $js_form . ', \'' . $pathDiv . '\', \'' . $cat_id_fld . '\')">' . _m('Switch to category') . '</a>
            </div>';
        }
        return $ret;
    }

    /**
    * Returns multiple selectbox with subcategory list
    * @return string selectbox prepared to print
    */
    public function getFrmCatTree($onWhat, $width = 250, $name = 'tree', $rows = 8): string
    {
        if (!$name) {
            $name = 'tree';
        }
        if (!$width) {
            $width = 250;
        }
        if (!$rows) {
            $rows = 8;
        }
        return "<select name=\"$name\" size=\"$rows\" $onWhat style=\"width:{$width}px\"></select>";
    }

    /**
    * Returns multiple selectbox with subcategory list
    * @return string selectbox prepared to print
    */
    public function getFrmSubCatList($withState, $onWhat, $cat2show, $width = 250, $name = 'tree', $rows = 8): string
    {
        if (!$name) {
            $name = 'tree';
        }
        if (!$width) {
            $width = 250;
        }
        if (!$rows) {
            $rows = 8;
        }
        $ret = "<select name=\"$name\" size=\"$rows\" $onWhat style=\"width:{$width}px\">";
        if (isset($this->ancesors_idx) and is_array($this->ancesors_idx[$cat2show])) {
            foreach ($this->ancesors_idx[$cat2show] as $idx) {  // start position
                $assig = $this->assignments[$idx];
                $ret .= '<option value="' . $assig->getTo() . '">';
                $ret .= ($withState ? '(' . $assig->getState() . ') ' : '');
                $ret .= $this->catnames[$assig->getTo()] . $assig->getBase();
                $ret .= '</option>';
            }
        }
        $ret .=  '</select>';
        return $ret;
    }

    /**
     * Walks category tree and calls specified function
     *
     * @param string $function - called function (could be also method - then
     *                           passed as array( $obj, method )
     * @return bool
     */
    public function walkTree($start_id, $function, $level = 0): bool
    {
        if (!isset($this->ancesors_idx) || !is_array($this->ancesors_idx[$start_id])) {
            return false;
        }

        foreach ($this->ancesors_idx[$start_id] as $idx) {
            $assig = $this->assignments[$idx];
            $function($assig->getTo(), $this->catnames[$assig->getTo()], $assig->getBase(), $assig->getState(), $assig->getFrom(), $level);

            // not crossreferenced and never ending cycles protection
            if ($assig->getBase() !== '@' && $level <= 100) {
                $this->walkTree($assig->getTo(), $function, $level + 1);
            }
        }
        return true;
    }

    /** Counts links in all categories */
    public function count_all_links(): void
    {
        $this->updateIfNeeded();
        $toexecute   = new Toexecute();
        $linkcounter = new linkcounter();

        /** if we have already scheduled recounting of categories,
         *  then reschedule it again
         */
        $toexecute->cancel_all('COUNT_LINKS');

        foreach ($this->catpaths as $cid => $cpath) {
            $toexecute->later($linkcounter, [$cpath], 'COUNT_LINKS', 50);
        }
    }
}

/** class used just for counting of links */
class linkcounter implements LaterInterface
{
    /** constructor */
    public function __construct() {}

    /** get current link count for whole category including subcategories */
    public function get_link_count($cpath, $update = false)
    {
        global $nocache;
        $g_nocache = $nocache;
        $nocache   = true;
        $zids      = Links_QueryZIDs($cpath, [], '', true);
        $nocache   = $g_nocache;

        $count = $zids->count();
        // now update database
        if ($update) {
            DB_AA::sql("UPDATE links_categories SET link_count='$count' WHERE path='$cpath'");
        }
        return $count;
    }

    /** special function called from AA\Later\Toexecute class - used for queued tasks (ran form cron)
     *  @param array $params - numeric array of additional parameters for the execution passed in time of call
     *  @return string - message about execution to be logged
     *  @see \AA\Later\LaterInterface
     */
    public function toexecutelater($params = []): string
    {
        [$cpath] = $params;
        return $this->get_link_count($cpath, true);
    }
}


/** Bitfield data type - you can set bit, get bit ... in bitfield of
 *  unlimited length
 */
class bitfield
{
    /** string which represents bitfield (for now are bits stored in the string
     *  - each character represents one bit - it is not ideal solution, but
     *  better than nothing - you can change it
     */
    public $field   = '';
    /** Current length of bitfield - count of bits from 0 to max index which
     *  is set */
    public $length = 0;

    /** Sets $pos-th bit to true or false. Positions are counted from 0 */
    public function setbit($pos, $val = true): void
    {
        if ($pos >= strlen($this->field)) {
            $this->field  .= str_repeat("0", 100);   // we add always 100 characters (it is quicker, than go with step of 1)
        }
        $this->field  = substr_replace($this->field, $val ? '1' : '0', $pos, 1);  // makes "00010010110 " string
        $this->length = max($this->length, $pos + 1);
    }

    /** returns as string - like "0001001011100100110" */
    public function getAsString(): string
    {
        return substr($this->field, 0, $this->length);
    }

}
