<?php
/**
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
 * @package   UserInput
 * @version   $Id$
 * @author    Honza Malik <honzam.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
*/

/** Classes for manipulating trees,
 *
 */
class AA_Tree {

    /** long item id of the tree root */
    var $id;

    var $relation_field;

    var $_i;  // Array of items

    /** Used modules (slices) - used for cache purge keystring as well as
     *  for compression of tree structure (as module shortcut) */
    var $_modules; // Array of fields

    /** Constructor
     * @param $id
     * @param $relation_field
     */
    function AA_Tree($id, $relation_field) {
        $this->id             = $id;
        $this->relation_field = $relation_field;
        $this->_i             = null;
        $this->_modules       = array();
    }


    /** load function
     * @param $force
     */
    function load( $force=false ) {
        if (isset($this->_i) AND !$force) {
            return;
        }

        $this->_i      = array();
        $new_subitems  = array($this->id);

        while (1) {
            $content4ids   = GetItemContent($new_subitems, false, false, array('id..............','slice_id........', $this->relation_field));

            $new = array();
            if (is_array($content4ids) ) {
                foreach ($content4ids as $item_id => $columns) {
                    // mark module_id
                    $this->_modules[$columns['u_slice_id......'][0]['value']] = true;
                    $next  = $this->_compactValue($columns[$this->relation_field]);
                    $new   = array_merge($new,$next);
                    $this->_i[$item_id] = $next;
                }
            }

            $new_subitems = array_diff($new, array_keys($this->_i));
            if ( count($new_subitems) < 1 ) {
                break;
            }
        }
        return;
    }

    function _compactValue($value_arr) {
        $ret = array();
        if (is_array($value_arr)) {
            foreach ( $value_arr as $fld_content ) {
                if ( !empty($fld_content['value']) ) {
                    $ret[] = $fld_content['value'];
                }
            }
        }
        return $ret;
    }

    /** returns ids in array - ids are in tree order (walked into deep) */
    function getIds() {
        $this->load();
        $ids = $this->addSubIds($this->id, 1);
        return $ids;
    }

    function addSubIds($id, $level) {
        if ($level > 100) {
            // @todo throw error
            return array();
        }
        $sub = array($id);
        foreach($this->_i[$id] as $down_id) {
            $sub = array_merge($sub, $this->addSubIds($down_id, $level+1));
        }
        return $sub;
    }

    /** returns ids in array - ids are in tree order (walked into deep) */
    function getTreeString() {
        $this->load();
        $ids = $this->subTreeString($this->id, 1);
        return $ids;
    }

    function subTreeString($id, $level) {
        if ($level > 100) {
            // @todo throw error
            return '';
        }
        $treestring = '';
        $delim      = '';
        foreach($this->_i[$id] as $down_id) {
            $treestring .= $delim. $this->subTreeString($down_id, $level+1);
            $delim       = '-';
        }
        return $id. (empty($treestring) ? '' : "($treestring)");
    }
}

class AA_Trees {
    var $a = array();

    /** Constructor  */
    function AA_Trees() {
        $this->a = array();
    }

    /** singleton function
     *  called as AA_Trees::singleton() from getTree() method;
     *  This function makes sure, there is global instance of the class
     *  @todo  convert to static class variable (after migration to PHP5)
     */
    function & singleton() {
        static $instance = null;
        if (is_null($instance)) {
            // Now create the AA_Trees object
            $instance = new AA_Trees;
        }
        return $instance;
    }

    /** getTree function
     *  main factory static method
     * @param $id
     */
    function & getTree($id, $relation_field) {
        $trees = AA_Trees::singleton();
        return $trees->_getTree($id, $relation_field);
    }

    /** _getTree function
     * @param $id
     */
    function & _getTree($id, $relation_field) {
        if (!isset($this->a[$id])) {
            $this->a[$id] = new AA_Tree($id, $relation_field);
        }
        return $this->a[$id];
    }
}

?>