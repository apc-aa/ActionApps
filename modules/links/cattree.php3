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

/** For Links_QueryZids() - called also from /misc/toexecute.php3 */
require_once AA_BASE_PATH. "modules/links/linksearch.php3";
require_once AA_INC_PATH.  "toexecute.class.php3";


/** Category assignments - stores which category is subcategory of another */
class catassignment {
    var $from;
    var $to;
    var $base;
    var $state;

    /** just constructor - variable assignments */
    function  catassignment($from, $to, $base, $state) {
        $this->from  = $from;
        $this->to    = $to;
        $this->base  = $base;
        $this->state = $state;
    }

    function  getFrom() { return $this->from; }
    function  getTo()   { return $this->to; }
    function  getBase() { return $this->base; }
    function  getState(){ return $this->state; }
}

/**
 * cattree class - handles tree of categories
 */
class cattree {
    var $db;             // database handler
    var $treeStart;      // where to start - category root
    var $go_to_empty;    // boolean - should we go to the empty subcategories?
    var $path_delimiter; // string to show between categories in path

    var $catnames;       // asociative array with names of columns and values of current row
    var $catpaths;       // asociative array with paths to categories
    var $catnolinks;     // asociative array with 'nolinks' category paremeters
    var $assignments;    // category assignments - stores which category is subcategory of another
    var $ancesors_idx;   // for each category it defines array of ancessors - used just for speedup (walkTree)

    var $STATES_CODING = array('highlight'=>'!', 'visible'=>'-', 'hidden'=>'x');

    // constructor
    function cattree($db="", $treeStart=-1, $go_to_empty=false, $path_delimiter=' > ') {
        $this->db             = get_if( $db, getDB());  // not necessary to use global $db, now
        $this->treeStart      = $treeStart;
        $this->go_to_empty    = $go_to_empty;
        $this->path_delimiter = $path_delimiter;
    }

    /** "class function" obviously called as cattree::global_instance();
    *  This function makes sure, there is global instance of the class
    */
    function global_instance() {
        if ( !isset($GLOBALS['cattree']) ) {
            $GLOBALS['cattree'] = new cattree();
        }
    }

    /** Compare function for sorting categories in subcategory
    *  The sort oder is by name, but general categories goes as last and in its
    *  own order
    *  @param object $a,$b - instances of catassignment class
    */
    function cmp_assignments( $a, $b ) {
        $aname = $this->catnames[$a->getTo()];
        $bname = $this->catnames[$b->getTo()];
        $apri  = (integer) Links_GlobalCatPriority($aname);
        $bpri  = (integer) Links_GlobalCatPriority($bname);

        if ( $apri == $bpri ) {
            return strcmp($aname, $bname);
        }
        return (( $apri < $bpri ) ? -1 : 1);
    }

    /** Sort categories assignments by name - general categories goes at the end*/
    function sort_categories() {
        usort($this->assignments, array($this, "cmp_assignments"));
    }

    function update() {
        $db = $this->db;
        unset( $this->catnames );
        unset( $this->catpaths );
        unset( $this->catnolinks );
        unset( $this->assignments );
        unset( $this->ancesors_idx );
        $this->ancesors_idx = array();

        // lookup - all categories names
        $SQL= " SELECT id, name, path, nolinks FROM links_categories WHERE deleted='n'";
        $db->query($SQL);
        while ($db->next_record()) {
            $cid = $db->f('id');
            $this->catnames[$cid]   = myspecialchars($db->f('name'));
            $this->catpaths[$cid]   = $db->f('path');
            $this->catnolinks[$cid] = $db->f('nolinks');
        }

        // lookup - category tree
        $SQL= " SELECT category_id, what_id, base, state
                  FROM links_cat_cat, links_categories
                 WHERE links_cat_cat.what_id = links_categories.id
                 ORDER BY name";

        $db->query($SQL);
        while ($db->next_record()) {
            $this->assignments[] = new catassignment (
                                        $db->f('category_id'),
                                        $db->f('what_id'),
                                        $db->f('base')=='n' ? '@' : ' ',
                                        $this->STATES_CODING[$db->f('state')]);
        }
        $this->sort_categories();
        foreach ($this->assignments as $idx => $assig ) {
            $this->ancesors_idx[$assig->getFrom()][] = $idx;
        }
    }

    /** Not filled yet? ==> Fill it from database  */
    function updateIfNeeded() {
        if (!isset($this->catnames) OR !is_array($this->catnames)) {
            $this->update();
        }
    }


    /** Search category $parenid, if there exist subcategory of name $name
    *  @returns id of found category or false
    */
    function subcatExist($parentid, $name) {
        $this->updateIfNeeded();
        if ( isset($this->ancesors_idx) AND is_array($this->ancesors_idx[$parentid]) ) {
            foreach ( $this->ancesors_idx[$parentid] as $idx ) {
                $assig = $this->assignments[$idx];
                debug('<br>====? ',$this->catnames[$assig->getTo()],$name);
                if ( $this->catnames[$assig->getTo()]==$name ) {
                    return $assig->getTo();
                }
            }
        }
        return false;
    }

    /** Exist the categoery in the cattree? */
    function exists($cid) {
        $this->updateIfNeeded();
        return !is_null($this->catnames[$cid]);
    }

    /** Ensures, that the category exists in $cid and if not, create it
    *  Returns category id of found (or created) category
    */
    function ensureExists($cid, $name) {
        debug("ensureExists($cid, $name", $this->getName($cid));
        if ( $this->getName($cid) == $name ) {
            $sub_cat_id = $cid;   // do not create general into general (akce->akce)
        } else {
            $sub_cat_id = $this->subcatExist($cid, $name);
        }
        if ( !$sub_cat_id ) {
            $sub_cat_id = Links_AddCategory($name, $cid, $this->getPath($cid));
            Links_AssignCategory($cid, $sub_cat_id, Links_GlobalCatPriority($name));
            $this->update();
        }
        return $sub_cat_id;
    }


    /** Returs name of category given by its id */
    function getName($cid) {
        $this->updateIfNeeded();
        return $this->catnames[$cid];
    }

    /** Returs name of category given by its id */
    function getPath($cid) {
        $this->updateIfNeeded();
        return $this->catpaths[$cid];
    }

    /** Returs name of category given by its id */
    function isNolinks($cid) {
        $this->updateIfNeeded();
        return $this->catnolinks[$cid];
    }

    /** Returs category id of parent category */
    function getParent($cid) {
        $this->updateIfNeeded();
        $ids = explode(",", $this->getPath($cid));
        if ( count($ids) < 2 ) {
            return false;
        }
        return $ids[count($ids)-2];
    }

    /** Get name of general category, if it is general category */
    function isGeneral($cid) {
        $this->updateIfNeeded();
        return Links_IsGlobalCategory($this->getName($cid));
    }



    // Transforms path to named path with links ( <a href=...>Base</a> > <a ...)
    //   based on $translate array; skips first "skip" fields
    //   url: ""      - do not make links on categories
    //        url     - make links to categories except the last one
    //   whole - if set, make links to all categories
    function getNamePath($cid, $skip=0, $separator = " > ", $url=false, $whole=false, $target="") {
        $this->updateIfNeeded();
        $path = $this->getPath($cid);
        $target_atrib = (($target != "") ? " target=\"$target\" " : "");
        $ids = explode(",",$path);
        $last=end($ids);
        if ( isset($ids) AND is_array($ids)) {
            if ( $url ) {
                foreach ( $ids as $catid ) {
                    if (--$skip >= 0) {
                        continue;
                    }
                    if ( ($catid != $last) OR $whole ) { // do not make link for last category
                        $name .= $delimiter."<a href=\"$url$catid\" $target_atrib>".$this->getName($catid)."</a>";
                    } else {
                        $name .= $delimiter.$this->getName($catid);
                    }
                    $delimiter = $separator;
                }
            } else {
                foreach ($ids as $catid) {
                    if (--$skip >= 0) {
                        continue;
                    }
                    $name .= $delimiter.$this->getName($catid);
                    $delimiter = $separator;
                }
            }
        }
        return $name;
    }

    /**
    * Prints javascript which defines necessary javascript variables for category
    * tree. There must be already includede js_lib_links.js file on the page
    * in order ClearListbox(), GoCategory() and ChangeCategory() are defined
    *
    * @param string $fromId special string which in conjunction with $toId defines
    *                       the tree for javascript (see Links_GetTreeDefinition)
    * @param string $toId (see fromId, Links_GetTreeDefinition())
    * @param string special string identifying if category $base{n} is base categ.
    */
    function printTreeData($treeStart=-1, $select_depth=1, $print_general=false) {
        $this->updateIfNeeded();
        if ( $treeStart == -1 ) {
            $treeStart = $this->treeStart;
        }

        // generate strings for javascript
        foreach ((array)$this->assignments as $assig) {
            $to = $assig->getTo();
            if (!$print_general AND $this->isGeneral($to)) {
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
        $general = new bitfield;
        foreach ((array)$this->catnames as $cid => $cname) {
            $general->setbit( $cid, $this->isGeneral($cid) );
        }

        $js = '
        // data ----------------------------------------------
        s=new Array('. $fromString .')
        t=new Array('. $toString .')
        b=new Array('. $baseString .')
        general = "'.$general->getAsString().'"

        var assignno = s.length    // number of category assignments
        var level = 0              // current depth of tree path
        var treeStart = '. $this->treeStart .'
        var select_depth = '. $select_depth .'
        var go_into_empty_cat = '. ($this->go_to_empty ? 'true' : 'false') .'
        var path_delimiter    = "'. $this->path_delimiter .'"
        a=new Array()'."\n";

        // This part is not cached - There was some problems in Firefox, when
        // treeStart was undefined, then

        FrmJavascript( $js );
        $js = '';

        // create javascript category id->name translation table:
        // a[334]=a[324]="Organizations"
        // We collect more than one asignment to one row in order the resulting
        // js code is as short as possible (see example above)
        // It is not possible however to put ALL asignments for one name to one
        // row since Firefox (at least 1.0.6) say "too much recursion", so we
        // the asignments to group of max 100 categories
        $names_count = array();
        define('MAX_JS_NODES_AT_LINE',100);
        foreach ( $this->catnames as $allId => $allName) {
            if (!$names_count[$allName]) {
                $names_count[$allName] =1;
            } else {
                $names_count[$allName];
            }

            $names_count[$allName]++;
            $index_name = (string)floor($names_count[$allName] / MAX_JS_NODES_AT_LINE) . "~$allName";
            $js_arr[$index_name] .= "a[$allId]=";
        }
        foreach ( $js_arr as $allName => $allIds) {
            list(,$name) = explode('~', $allName, 2);
            $js .= $allIds."\"$name\"\n";
        }

        $js .=  '
        downcat = new Array()
        downcat[level] = treeStart // stores path form root to current category';
        FrmJavascriptCached( $js, 'cattree' );
    }


    /**
    * Prints javascript which changes tree to given category
    * There must be already includede js_lib_links.js file on the page
    * in order ClearListbox(), GoCategory() and ChangeCategory() are defined
    *
    * @param int $cat_path path of category to switch to (this->treeStart must
    *                      be on the path
    * @param int $pathDiv  <div> //id where the path should be displayed
    * @param int $cat_id_field hidden form field which stores selected category
    */
    function goCategory($cid, $pathDiv="", $cat_id_fld="", $form="") {
        return getFrmJavascript("GoToCategoryID('$cid', eval('document.$form.tree'), '$pathDiv', '$cat_id_fld')\n");
    }

    /**
    * Returns multiple selectbox which behaves like category tree
    * Links_PrintTreeData() function must be called first (to define javascript
    * variables.
    * @param bool   withState  Have we show also category state?
    * @param string onWhat     Event to react (onchange/ondblclick)
    * @param int    cat2show   Which category to show as default
    * @param string pathdiv    id of an html element (<div id='...'>) where to
    *                          display currently selected category path
    * @param string cat_id_fld (probably hidden) form field, where the current
    *                          selected category id is written
    * @param string form       if specified, the cattree selectbox is enclosed
    *                          by the form
    * @param string in_form    the name of form, in which the tree selectbox is
    *                          (it must be specified for $onWhat='dblclick' where
    *                          $form is not specified)
    * @return string selectbox prepared to print
    */
    function getFrmTree($withState, $onWhat, $cat2show=-1, $pathDiv="",
                        $cat_id_fld="", $form="", $width=250, $rows=8, $in_form="") {
        $this->updateIfNeeded();
        if ( $cat2show == -1 ) {
            $cat2show = $this->treeStart;
        }
        $on = ( ($onWhat == 'change')   ?
            "onchange=\"GoToCategoryID('', this, '$pathDiv', '$cat_id_fld')\"" :
            (($onWhat == 'dblclick') ?
            "ondblclick=\"GoToCategoryID('', this, '$pathDiv', '$cat_id_fld')\"
            onchange=\"ChangeSelectedCat('', this, '$pathDiv', '$cat_id_fld')\""
            :''));


        $ret = ($form ? '<form name="'. $form. '">' : '' );
        $ret .= $this->getFrmCatTree($on, $width, 'tree', $rows);
        $ret .= ($form ? '</form>' : '' );

        $used_form = ($form ? $form : $in_form);
        $ret .= $this->goCategory($cat2show, $pathDiv, $cat_id_fld, $used_form);

        // for selectbox which use doubleclick we have to print also 'GO' button,
        // because Netscape 4 do not support dblclick event
        $js_form = "eval(document.$used_form.tree)";
        if ( $onWhat == 'dblclick' ) {
            $ret .= '<div align="center"><br>
            <a href="javascript:GoToCategoryID(\'\', '.$js_form.', \''.$pathDiv.'\', \''.
            $cat_id_fld .'\')">'. _m('Switch to category') .'</a>
            </div>';
        }
        return $ret;
    }

    /**
    * Returns multiple selectbox with subcategory list
    * @return string selectbox prepared to print
    */
    function getFrmCatTree($onWhat, $width=250, $name='tree', $rows=8 ) {
        if (!$name)   $name='tree';
        if (!$width)  $width=250;
        if (!$rows)   $rows=8;
        $ret  = "<select name=\"$name\" size=\"$rows\" $onWhat style=\"width:${width}px\"></select>";
        return $ret;
    }

    /**
    * Returns multiple selectbox with subcategory list
    * @return string selectbox prepared to print
    */
    function getFrmSubCatList($withState, $onWhat, $cat2show, $width=250, $name='tree', $rows=8 ) {
        if (!$name)   $name='tree';
        if (!$width)  $width=250;
        if (!$rows)   $rows=8;
        $ret = "<select name=\"$name\" size=\"$rows\" $onWhat style=\"width:${width}px\">";
        if (isset($this->ancesors_idx) AND is_array($this->ancesors_idx[$cat2show])) {
            foreach ($this->ancesors_idx[$cat2show] as $idx) {  // start position
                $assig = $this->assignments[$idx];
                $ret .= '<option value="'. $assig->getTo() .'">';
                $ret .= ($withState ? '('. $assig->getState(). ') ' : '');
                $ret .= $this->catnames[$assig->getTo()]. $assig->getBase();
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
    */
    function walkTree($start_id, $function, $level=0) {
        if (!isset($this->ancesors_idx) OR !is_array($this->ancesors_idx[$start_id])) {
            return false;
        }

        foreach( $this->ancesors_idx[$start_id] as $idx ) {
            $assig = $this->assignments[$idx];
            call_user_func_array($function, array( $assig->getTo(),
                        $this->catnames[$assig->getTo()], $assig->getBase(),
                        $assig->getState(), $assig->getFrom(), $level));

            // not crossreferenced and never ending cycles protection
            if (($assig->getBase() != '@') AND ($level <= 100)) {
                $this->walkTree($assig->getTo(), $function, $level+1);
            }
        }
    }


    function count_all_links() {
        $this->updateIfNeeded();
        $toexecute   = new AA_Toexecute;
        $linkcounter = new linkcounter;

        /** if we have already scheduled recounting of categories,
         *  then reschedule it again
         */
        $toexecute->cancel_all('COUNT_LINKS');

        foreach ($this->catpaths as $cid => $cpath) {
            $toexecute->later($linkcounter, array($cpath), 'COUNT_LINKS', 50);
        }
    }
}

/** class used just for counting of links */
class linkcounter {

    /** constructor */
    function linkcounter() {}

     /** get current link count for whole category including subcategories */
    function get_link_count($cpath, $update=false) {
        global $nocache;
        $g_nocache = $nocache;
        $nocache   = true;
        $zids      = Links_QueryZIDs($cpath, '', '', true);
        $nocache   = $g_nocache;

        $count = $zids->count();
        // now update database
        if ($update) {
            $db = getDB();
            $db->tquery("UPDATE links_categories SET link_count='$count' WHERE path='$cpath'");
            freeDB($db);
        }
        return $count;
    }

    /** Toexecutelater - special function called from AA_Toexecute class
    *  - used for queued tasks (runed form cron)
    */
    function toexecutelater($cpath) {
        return $this->get_link_count($cpath, true);
    }
}


/** Bitfiled data type - you can set bit, get bit ... in bitfield of
 *  unlimited length
 */
class bitfield {
    /** string which represents bitfield (for now are bits stored in the string
     *  - each character represents one bit - itis not ideal solution, but
     *  better than nothing - you can change it
     */
    var $field;
    /** Current length of bitfield - count of bits from 0 to max index which
     *  is set */
    var $length;

    /** default values for all bits are "not set" (=false) */
    function bitfield() {
        $field = '';
        $length = 0;
    }

    /** Sets $pos-th bit to true or false. Positions are counted from 0 */
    function setbit($pos, $val=true) {
        if ( $pos >= strlen($this->field) ) {
            $this->field  .= str_repeat("0",100);   // we add always 100 characters (it is quicker, than go with step of 1)
        }
        $this->field  = substr_replace($this->field, $val ? '1' : '0', $pos, 1);  // makes "00010010110 " string
        $this->length = max( $this->length, $pos+1 );
    }

    /** returns as string - like "0001001011100100110" */
    function getAsString() {
        return substr($this->field,0,$this->length);
    }

}

?>