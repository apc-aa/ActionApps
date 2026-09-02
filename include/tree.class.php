<?php

use AA\Util\SingletonTrait;

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


class AA_Supertree
{
    protected array $_i = [];        // Array of items
    protected $_relation_field;  //
    protected $_sort;     // sort array().
    // Currently works only for Reverse trees. @todo
    protected array $_modules = [];  // Array of modules

    protected $_restrict_slices;  // Array of allowed slices

    public function __construct($relation_field, $sort = null, $slices = [])
    {
        $this->_relation_field  = $relation_field;
        $this->_sort            = $sort;
        $this->_restrict_slices = $slices;
    }

    /** load function
     * @param $force
     */
    public function load($id): void
    {
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
            foreach ($content4ids as $item_id => $columns) {
                $sid = $columns['u_slice_id......'][0]['value'];
                if (count($this->_restrict_slices) && !in_array($sid, $this->_restrict_slices)) {
                    $invalid = array_merge($invalid, [$item_id]);
                    continue;
                }
                // mark module_id
                $this->_modules[$sid] = true;
                $next  = $this->_compactValue($columns[$this->_relation_field]);
                $new   = array_merge($new, $next);
                $this->_i[$item_id] = $next;
            }

            $new_subitems = array_diff($new, array_keys($this->_i));
            if (count($new_subitems) < 1) {
                break;
            }
        }

        // remove all links to invalid items
        if (count($invalid)) {
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
    public function getIds($id): array
    {
        $this->load($id);
        return $this->_subIds($id, 1);
    }

    /**
     * @param     $id
     * @param int $level
     * @return array
     */
    public function _subIds($id, $level = 1): array
    {
        if ($level > 100) {
            // @todo throw error
            return [];
        }
        $sub = [$id];
        foreach ($this->_i[$id] as $down_id) {
            $sub = array_merge($sub, $this->_subIds($down_id, $level + 1));
        }
        return $sub;
    }

    public function getMenu($ids, $current_ids, $code, $inner = false, $expanded = false, $exp_button = false): string
    {
        $ret = '';
        $xid = end($current_ids);

        foreach ($ids as $mid) {
            if (!($item = AA_Items::getItem(new zids($mid, 'l')))) {
                continue;
            }
            if (!($menu_txt = trim($item->subst_alias($code)))) {
                continue;
            }
            $inpath = in_array($mid, $current_ids) ? 'inpath' : '';
            if ($expanded || $inpath) {
                $this->load($mid);
                $sub  = empty($this->_i[$mid]) ? '' : $this->getMenu($this->_i[$mid], $current_ids, $code, true, $expanded, $exp_button);
                $ret .= '  <li id="menu-' . $mid . '" class="' . $inpath . ($mid == $xid ? ' xidmatch' : '') . '">';
                $ret .= $menu_txt;
                $ret .= ($exp_button && strlen($sub)) ? '<i class=aa-ico-expand onclick="this.closest(\'li\').classList.toggle(\'inpath\');"></i>' : '' ;
                $ret .= $sub;
                $ret .= "</li>\n";
            } else {
                $ret .= "  <li id=\"menu-$mid\">$menu_txt</li>\n";
            }
        }
        if (!$inner && $ret && false !== ($pos = strrpos($ret, 'class="inpath'))) {
            // add "active" to the closest active menu entry on final recursive return
            $ret = substr_replace($ret, 'class="inpath active', $pos, 13);
        }

        return ($ret ? "\n<ul>\n$ret</ul>\n" : '');
    }

    /**
     * @param $id
     * @return string
     */
    public function getTreeString($id): string
    {
        $this->load($id);
        return $this->_subTreeString($id, 1);
    }

    /**
     * @param $id
     * @param $level
     * @return string
     */
    public function _subTreeString($id, $level): string
    {
        if ($level > 100) {
            // @todo throw error
            return '';
        }
        $treestring = '';
        $delim      = '';
        foreach ($this->_i[$id] as $down_id) {
            $treestring .= $delim . $this->_subTreeString($down_id, $level + 1);
            $delim       = '-';
        }
        return $id . (empty($treestring) ? '' : "($treestring)");
    }

    public function _compactValue($value_arr): array
    {
        $ret = [];
        if (is_array($value_arr)) {
            foreach ($value_arr as $fld_content) {
                if (!empty($fld_content['value'])) {
                    $ret[] = $fld_content['value'];
                }
            }
        }
        return $ret;
    }
}

/** The same as AA_Supertree, but it holds reversed tree - tree constructed not
 *  as parent->child but child->parent. The difference is the direction,
 *  the relation field points.
 *  !! The Reverse Tree is limited to one relation slice only !! - @todo - fix
 *  It is the same - we just change the way, how to construct the tree.
 */
class AA_Supertree_Reverse extends AA_Supertree
{
    /** how many parents at most go into one query */
    private const LEVEL_CHUNK = 200;

    /** load function
     *  Walks the reverse tree by levels: one query for the whole level, not one
     *  per node. The parent class batches the same way (it can just read the
     *  relation field of ids it already has); here the direction forces a second
     *  query per level to learn which parent each child belongs to.
     * @param string $id long_item_id
     */
    public function load($id): void
    {
        if (isset($this->_i[$id])) {
            return;
        }

        if (!count($this->_restrict_slices)) {
            $zid = new zids($id, 'l');
            $sid = $zid->getFirstSlice();
            //huhl($this->_restrict_slices,$zid,$sid,$this);
            if (!$sid) {
                return;
            }
            $this->_restrict_slices = [$sid];
        }
        $this->_modules = array_fill_keys($this->_restrict_slices, true);

        $level = [$id];
        while ($level) {
            $next_level = [];
            // chunked so that a level with thousands of nodes cannot build one
            // enormous OR expression; each chunk is bucketed on its own, so the
            // order of a parent's children always comes from a single query
            foreach (array_chunk($level, self::LEVEL_CHUNK) as $chunk) {
                $children     = $this->_queryChildren($chunk);
                $next_level[] = $children;      // merged once below - array_merge() in a loop is O(n^2)

                // every visited node needs an entry, leaves included - _subIds() and
                // _subTreeString() foreach over $this->_i[$id] without a guard
                foreach ($chunk as $parent_id) {
                    $this->_i[$parent_id] = [];
                }

                if (count($chunk) === 1) {
                    // one parent asked for - every returned child is its own, so there
                    // is nothing to look up (keeps narrow deep trees at one query per level)
                    $this->_i[$chunk[0]] = $children;
                    continue;
                }

                $chunk_set = array_flip($chunk);
                foreach ($this->_loadParents($children) as $child_id => $parents) {
                    foreach (array_unique($parents) as $parent_id) {
                        // only parents of THIS chunk - a child pointing at an already
                        // finished parent was handed to it when that parent was asked for
                        if (isset($chunk_set[$parent_id])) {
                            $this->_i[$parent_id][] = $child_id;
                        }
                    }
                }
            }

            // items already visited end the walk here - this is the cycle guard
            $found = $next_level ? array_merge(...$next_level) : [];
            $level = array_values(array_diff(array_unique($found), array_keys($this->_i)));
        }
    }

    /** long ids of all items whose relation field points at any of $parent_ids,
     *  in the order $this->_sort asks for
     * @param  string[] $parent_ids
     * @return string[]
     */
    protected function _queryChildren(array $parent_ids): array
    {
        $cond = [
            $this->_relation_field => 1,
            // '=' parses the value with Syntax/SQL_Lex, so an array becomes
            // (field="id1" OR field="id2" OR ...). '==' cannot be used here - it
            // compares against the raw string and never parses an OR expression.
            'operator'             => '=',
            'value'                => $parent_ids,   // AA_Set quotes each value and joins them with OR
        ];
        return (new AA_Set($this->_restrict_slices, [$cond], $this->_sort))->query()->longids();
    }

    /** child long id => the long ids its relation field points at
     * @param  string[] $child_ids
     * @return array<string, string[]>
     */
    protected function _loadParents(array $child_ids): array
    {
        if (!$child_ids) {
            return [];
        }
        $ret     = [];
        $content = GetItemContent($child_ids, false, false, ['id..............', $this->_relation_field], null, AA_BIN_ACTIVE);
        // walked in $child_ids order - the sort order _queryChildren() returned them in
        foreach ($child_ids as $child_id) {
            if (isset($content[$child_id])) {
                $ret[$child_id] = $this->_compactValue($content[$child_id][$this->_relation_field]);
            }
        }
        return $ret;
    }
}


/**
 * Class AA_Trees
 * @method  static AA_Trees singleton()
 */
class AA_Trees
{
    use SingletonTrait;

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
    public static function getTreeString($id, $relation_field, $reverse = false, $sort = null, $slices = null): string
    {
        return self::getSupertree($relation_field, $reverse, $sort, $slices)->getTreeString($id);
    }

    /** getTree function
     *  main factory static method
     * @param $id
     * @return array
     */
    public static function getIds($id, $relation_field, $reverse = false, $sort = null, $slices = null): array
    {
        return self::getSupertree($relation_field, $reverse, $sort, $slices)->getIds($id);
    }

    /**
     * @param       $relation_field
     * @param       $reverse
     * @param       $sort
     * @param array  $slices
     *
     * @return AA_Supertree
     */
    public static function getSupertree($relation_field, $reverse, $sort, $slices = []): AA_Supertree
    {
        $trees = self::singleton();
        $key   = get_hash($relation_field, $reverse, $sort, $slices);
        if (!isset($trees->a[$key])) {
            $trees->a[$key] = $reverse ? new AA_Supertree_Reverse($relation_field, $sort, $slices) : new AA_Supertree($relation_field, $sort, $slices);
        }
        return $trees->a[$key];
    }
}
