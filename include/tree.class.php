<?php
/**
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
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honzam.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
*/


class AA_Supertree {
    protected $_i;        // Array of items
    protected $_relation_field;  //
    protected $_sort;     // sort array().
                          // Currently wors only for Reverse trees. @todo
    protected $_modules;  // Array of modules

    protected $_restrict_slices;  // Array of allowed slices

    function __construct($relation_field, $sort=null, $slices= []) {
        $this->_relation_field  = $relation_field;
        $this->_sort            = $sort;
        $this->_i               = [];
        $this->_modules         = [];
        $this->_restrict_slices = $slices;
    }

    /** load function
     * @param $force
     */
    function load( $id ) {
        if (isset($this->_i[$id])) {
            return;
        }

        /** items, which are already in trash, or expired, ... */
        $invalid       = [];

        $new_subitems  = [$id];
        while (1) {
            $content4ids   = GetItemContent($new_subitems, false, false, ['id..............','slice_id........', $this->_relation_field], null, AA_BIN_ACTIVE);

            $invalid = array_merge($invalid, array_diff($new_subitems, array_keys($content4ids)));

            $new = [];
            if (is_array($content4ids) ) {
                foreach ($content4ids as $item_id => $columns) {
                    $sid = $columns['u_slice_id......'][0]['value'];
                    if (count($this->_restrict_slices) AND !in_array($sid, $this->_restrict_slices)) {
                        $invalid = array_merge($invalid, [$item_id]);
                        continue;
                    }
                    // mark module_id
                    $this->_modules[$sid] = true;
                    $next  = $this->_compactValue($columns[$this->_relation_field]);
                    $new   = array_merge($new,$next);
                    $this->_i[$item_id] = $next;
                }
            }

            $new_subitems = array_diff($new, array_keys($this->_i));
            if ( count($new_subitems) < 1 ) {
                break;
            }
        }

        // remove all links to invalid items
        if (count($invalid) > 0) {
            foreach ($this->_i as $item_id => $next) {
                $this->_i[$item_id] = array_diff($next, $invalid);
            }
        }
        return;
    }

    /**  returns ids in array - ids are in tree order (walked into deep)
     * @param $id
     * @return array
     */
    function getIds($id) {
        $this->load($id);
        $ids = $this->_subIds($id, 1);
        return $ids;
    }

    /**
     * @param     $id
     * @param int $level
     * @return array
     */
    function _subIds($id, $level=1) {
        if ($level > 100) {
            // @todo throw error
            return [];
        }
        $sub = [$id];
        foreach($this->_i[$id] as $down_id) {
            $sub = array_merge($sub, $this->_subIds($down_id, $level+1));
        }
        return $sub;
    }

    function getMenu($ids, $current_ids, $code, $inner=false, $expanded=false) {
        $ret = '';
        $xid = end($current_ids);

        foreach($ids as $mid) {
            if ($item = AA_Items::getItem(new zids($mid, 'l'))) {
                if ($menu_txt = trim($item->subst_alias($code))) {
                    $inpath = in_array($mid, $current_ids) ? 'inpath' : '';
                    if ($expanded OR $inpath) {
                        $this->load($mid);
                        $sub  = empty($this->_i[$mid]) ? '' : $this->getMenu($this->_i[$mid], $current_ids, $code, true, $expanded);
                        $ret .= '  <li id="menu-'.$mid.'" class="'.$inpath.($mid==$xid  ? ' xidmatch':'').'">';
                        $ret .= $menu_txt. $sub;
                        $ret .= "</li>\n";
                    } else {
                        $ret .= "  <li id=\"menu-$mid\">$menu_txt</li>\n";
                    }
                }
            }
        }
        if (!$inner AND $ret AND (false !== ($pos = strrpos($ret, 'inpath')))) {
            // add active to the closest active menu entry on final recursive return
            $ret = substr_replace($ret, 'inpath active', $pos, 6);
        }

        return ($ret ? "\n<ul>\n$ret</ul>\n" : '');
    }

    /**
     * @param $id
     * @return string
     */
    function getTreeString($id) {
        $this->load($id);
        $ids = $this->_subTreeString($id, 1);
        return $ids;
    }

    /**
     * @param $id
     * @param $level
     * @return string
     */
    function _subTreeString($id, $level) {
        if ($level > 100) {
            // @todo throw error
            return '';
        }
        $treestring = '';
        $delim      = '';
        foreach($this->_i[$id] as $down_id) {
            $treestring .= $delim. $this->_subTreeString($down_id, $level+1);
            $delim       = '-';
        }
        return $id. (empty($treestring) ? '' : "($treestring)");
    }

    function _compactValue($value_arr) {
        $ret = [];
        if (is_array($value_arr)) {
            foreach ( $value_arr as $fld_content ) {
                if ( !empty($fld_content['value']) ) {
                    $ret[] = $fld_content['value'];
                }
            }
        }
        return $ret;
    }
}

/** The same as AA_Supertree, but it holds reversed tree - tree construced not
 *  as parent->childrens but children->parent. The diferrence is the direction,
 *  the relation field points.
 *  !! The Reverse Tree is limitted to one relation slice only !! - @todo - fix
 *  It is the same - we just change the way, how to construct the tree.
 */
class AA_Supertree_Reverse extends AA_Supertree {

    /** load function
     * @param $force
     */
    function load( $id ) {
        if (isset($this->_i[$id])) {
            return;
        }

        if (!count($this->_restrict_slices)) {
            $zid = new zids($id,'l');
            $sid = $zid->getFirstSlice();
            //huhl($this->_restrict_slices,$zid,$sid,$this);
            if (!$sid) {
                return;
            }
            $this->_restrict_slices = [$sid];
        }
        $this->_modules = array_fill_keys($this->_restrict_slices, true);

        /** items, which are already in trash, or expired, ... */
        $queue    = [$id];

        // prepare cond in order we can be as quick as possible
        $cond[$this->_relation_field] = 1;
        $cond['operator'] = '=';

        while (count($queue)) {

            $item_id  = array_pop($queue);
            if (isset($this->_i[$item_id])) {
                continue;
            }

            $cond['value'] = $item_id;
            $zids          = QueryZids($this->_restrict_slices, [$cond], $this->_sort);

            $next    = $zids->longids();
            $this->_i[$item_id] = $next;

            $queue   = array_merge($queue,$next);
        }
        return;
    }
}


/**
 * Class AA_Trees
 * @method  static AA_Trees singleton()
 */
class AA_Trees {

    use \AA\Util\SingletonTrait;

    /** parent->child trees */
    protected $a   = [];

    /** reverse - child->parent trees */
    protected $rev = [];

    /** getTree function
     *  main factory static method
     * @param $id
     * @return
     * @noinspection PhpUnused
     */
    static function getTreeString($id, $relation_field, $reverse=false, $sort=null, $slices=null) {
        $supertree = AA_Trees::getSupertree($relation_field, $reverse, $sort, $slices);
        return $supertree->getTreeString($id);
    }

    /** getTree function
     *  main factory static method
     * @param $id
     * @return mixed
     */
    static function getIds($id, $relation_field, $reverse=false, $sort=null, $slices=null) {
        $supertree = AA_Trees::getSupertree($relation_field, $reverse, $sort, $slices);
        return $supertree->getIds($id);
    }

    /**
     * @param       $relation_field
     * @param       $reverse
     * @param       $sort
     * @param array $slices
     * @return AA_Supertree|AA_Supertree_Reverse
     */
    static function getSupertree($relation_field, $reverse, $sort, $slices= []) {
        $trees = AA_Trees::singleton();
        $key   = get_hash($relation_field, $reverse, $sort, $slices);
        if (!isset($trees->a[$key])) {
            $trees->a[$key] = $reverse ? new AA_Supertree_Reverse($relation_field, $sort, $slices) : new AA_Supertree($relation_field, $sort, $slices);
        }
        return $trees->a[$key];
    }
}

