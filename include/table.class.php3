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
class AA_Table {

    /** long item id of the tree root */
    var $id;
    var $rows;
    var $colindex;  // Array of column indexes
    var $attrs;     // Array of attribute names

    /** Constructor
     * @param $id
     * @param $relation_field
     */
    function __construct($id) {
        $this->id       = $id;
        $this->rows     = array();
        $this->colindex = array();
        $this->attrs    = array();
    }

    function set($r, $c, $value, $attribute='') {
        if (!isset($this->rows[$r])) {
            $this->rows[$r] = array();
        }
        if (!isset($this->colindex[$c])) {
            $newindex = count($this->colindex);
            $this->colindex[$c] = $newindex;
        }
        $val = $this->rows[$r][$this->colindex[$c]];
        if (!is_array($val)) {
            $val = array();
        }
        $attribute = (string)$attribute;
        if (strlen($attribute)==0) {
            $attribute = '1';
        }
        $val[$attribute] = $value;
        $this->rows[$r][$this->colindex[$c]] = $val;

        // mark the name of the attribute
        $this->attrs[$attribute] = true;
    }

    function addset($r, $c, $value) {
        $cur = $this->getOne($r, $c);
        $val = ((float) str_replace(',', '.', $cur)) + ((float) str_replace(',', '.', $value));
        $this->set($r, $c, $val);
    }

    function joinset($r, $c, $value, $delimiter) {
        $cur = $this->getOne($r, $c);
        $val = strlen($cur) ? $cur. $delimiter. $value : $value;
        $this->set($r, $c, $val);
    }

    function getOne($r, $c, $att='1') {
        if (isset($this->colindex[$c]) AND isset($this->rows[$r])) {
            $val_arr = $this->rows[$r][$this->colindex[$c]];
            return is_array($val_arr) ? $val_arr[$att] : '';
        }
        return '';
    }

    function get($r, $c, $exp="_#1") {
        $cols = explode('-',$c);
        $ret  = '';

        // three variants - just for speedup
        if ($exp == '_#1') {
            // basic get element
            foreach ($cols as $col) {
                $ret .= $this->getOne($r, $col);
            }
        } elseif (substr_count($exp,'_#') == substr_count($exp,'_#')) {
            // get element surrounded by some code
            foreach ($cols as $col) {
                $ret .= str_replace('_#1', $this->getOne($r, $col), $exp);
            }
        } else {
            // comlex replace with attributes
            foreach ($cols as $col) {
                $txt = $exp;
                foreach ($this->attrs as $attr => $foo) {
                    $txt = str_replace("_#$attr", $this->getOne($r, $col, $attr), $txt);
                }
                $ret .= $txt;
            }
        }
        return $ret;
    }

    function sum($r, $c, $exp="_#1") {
        // todo - sum also rows;
        $ret  = 0.0;
        foreach ($this->rows as $row => $cols) {
            $ret += (float) str_replace(',', '.', $this->getOne($row, $c));
        }
        return str_replace('_#1', $ret, $exp);
    }

    function gethtml() {
        $ret = '';
        foreach ($this->rows as $row => $cols) {
            $ret .= '<tr>';
            foreach ($this->colindex as $col => $i) {
                $ret .= '<td>'. $this->getOne($row,$col) .'</td>';
            }
            $ret .= '</tr>';
        }
        if ($ret) {
            $ret = '<table>'.$ret.'</table>';
        }
        return $ret;
    }
}


/** Classes for manipulating with arrays for AAscript,
 *
 */
class AA_Array {

    var $id;
    var $arr;

    /** Constructor
     * @param $id
     */
    function __construct($id) {
        $this->id      = $id;
        $this->arr     = array();
    }

    function set($i, $value) {
        if ((string)$i == '') {
            $this->arr[] = $value;
        } else {
            $this->arr[(string)$i] = $value;
        }
    }

    function addset($i, $value) {
        $this->arr[(string)$i] = ((float)str_replace(',', '.',$this->arr[(string)$i])) + ((float) str_replace(',', '.', $value));
    }
    
    function joinset($i, $value, $delimiter) {
        $cur = $this->get($i);
        $this->arr[(string)$i] = strlen($cur) ? $cur. $delimiter. $value : $value;
    }
    
    function get($i) {
        return $this->arr[(string)$i];
    }

    function getAll($exp="_#1", $join='', $sort='') {
        $work_arr = $this->arr;
        switch ($sort) {
            case 'key':  ksort($work_arr, SORT_NUMERIC);  break;
            case 'rkey': krsort($work_arr, SORT_NUMERIC); break;
        }
        $ret = array();
        // speedup
        if ( !strlen($exp) OR ($exp == '_#1')) {
            $ret = $work_arr;
        } else {
            foreach ($work_arr as $k => $v) {
                $ret[] = str_replace(array('_#1','_#2'), array($v,$k), $exp);
            }
        }
        return join($join, $ret);
    }
}



?>