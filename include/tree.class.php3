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


class AA_Supertree {
    private $_i;      // Array of items
    private $_relation_field;  // 
    private $_modules;  // Array of modules

    function __construct($relation_field) {
        $this->_relation_field = $relation_field;
        $this->_i              = array();
    }
    
    /** load function
     * @param $force
     */
    function load( $id ) {
        if (isset($this->_i[$id])) {
            return;
        }

        $new_subitems  = array($id);
        while (1) {
            $content4ids   = GetItemContent($new_subitems, false, false, array('id..............','slice_id........', $this->_relation_field));

            $new = array();
            if (is_array($content4ids) ) {
                foreach ($content4ids as $item_id => $columns) {
                    // mark module_id
                    $this->_modules[$columns['u_slice_id......'][0]['value']] = true;
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
        return;
    }

    /** returns ids in array - ids are in tree order (walked into deep) */
    function getIds($id) {
        $this->load($id);
        $ids = $this->_subIds($id, 1);
        return $ids;
    }

    function _subIds($id, $level=1) {
        if ($level > 100) {
            // @todo throw error
            return array();
        }
        $sub = array($id);
        foreach($this->_i[$id] as $down_id) {
            $sub = array_merge($sub, $this->_subIds($down_id, $level+1));
        }
        return $sub;
    }

    /** returns ids in array - ids are in tree order (walked into deep) */
    function getTreeString($id) {
        $this->load($id);
        $ids = $this->_subTreeString($id, 1);
        return $ids;
    }

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
    function singleton() {
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
    function getTreeString($id, $relation_field) {
        $supertree = AA_Trees::getSupertree($relation_field);
        return $supertree->getTreeString($id);
    }

    /** getTree function
     *  main factory static method
     * @param $id
     */
    function getIds($id, $relation_field) {
        $supertree = AA_Trees::getSupertree($relation_field);
        return $supertree->getIds($id);
    }

   
    function getSupertree($relation_field) {
        $trees = AA_Trees::singleton();
        if (!isset($trees->a[$relation_field])) {
            $trees->a[$relation_field] = new AA_Supertree($relation_field);
        }
        return $trees->a[$relation_field];
    }
}

?>