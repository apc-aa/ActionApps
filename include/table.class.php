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

/** Classes for manipulating trees,
 *
 */
class AA_Table
{
    /** long item id of the tree root */
    public $id;
    public $rows = [];
    public $colindex = [];  // Array of column indexes
    public $attrs = [];     // Array of attribute names

    /** Constructor
     * @param $id
     * @param $relation_field
     */
    public function __construct($id)
    {
        $this->id       = $id;
    }

    public function set($r, $c, $value, $attribute = ''): void
    {
        if (!isset($this->rows[$r])) {
            $this->rows[$r] = [];
        }
        if (!isset($this->colindex[$c])) {
            $newindex = count($this->colindex);
            $this->colindex[$c] = $newindex;
        }
        $val = $this->rows[$r][$this->colindex[$c]];
        if (!is_array($val)) {
            $val = [];
        }
        $attribute = (string) $attribute;
        if (!strlen($attribute)) {
            $attribute = '1';
        }
        $val[$attribute] = $value;
        $this->rows[$r][$this->colindex[$c]] = $val;

        // mark the name of the attribute
        $this->attrs[$attribute] = true;
    }

    public function addset($r, $c, $value): void
    {
        $cur = $this->getOne($r, $c);
        $val = ((float) PhpFloat($cur)) + ((float) PhpFloat($value));
        $this->set($r, $c, $val);
    }

    public function joinset($r, $c, $value, $delimiter): void
    {
        $cur = $this->getOne($r, $c);
        $val = strlen($cur) ? $cur . $delimiter . $value : $value;
        $this->set($r, $c, $val);
    }

    public function getOne($r, $c, $att = '1')
    {
        if (isset($this->colindex[$c]) && isset($this->rows[$r])) {
            $val_arr = $this->rows[$r][$this->colindex[$c]];
            return is_array($val_arr) ? $val_arr[$att] : '';
        }
        return '';
    }

    public function get($r, $c, $exp = "_#1"): string
    {
        $cols = explode('-', $c);
        $ret  = '';

        // three variants - just for speedup
        if ($exp === '_#1') {
            // basic get element
            foreach ($cols as $col) {
                $ret .= $this->getOne($r, $col);
            }
        } elseif (substr_count($exp, '_#1') === substr_count($exp, '_#')) {
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

    public function sum($r, $c, $exp = "_#1")
    {
        // todo - sum also rows;
        $ret  = 0.0;
        foreach ($this->rows as $row => $cols) {
            $ret += (float) PhpFloat($this->getOne($row, $c));
        }
        return str_replace('_#1', $ret, $exp);
    }

    public function gethtml(): string
    {
        $ret = '';
        foreach ($this->rows as $row => $cols) {
            $ret .= '<tr>';
            foreach ($this->colindex as $col => $i) {
                $ret .= '<td>' . $this->getOne($row, $col) . '</td>';
            }
            $ret .= '</tr>';
        }
        if ($ret) {
            return '<table>' . $ret . '</table>';
        }
        return $ret;
    }
}


/** Classes for manipulating with arrays for AAscript,
 *
 */
class AA_Array
{
    private array $arr = [];

    public function __construct() {}

    public function set($i, $value): void
    {
        if ((string) $i === '') {
            $this->arr[] = $value;
        } else {
            $this->arr[(string) $i] = $value;
        }
    }

    public function addmulti($values, $delimiter): void
    {
        array_push($this->arr, ...AA_Stringexpand::explode2array($values, $delimiter));
    }

    public function addset($i, $value): void
    {
        $this->arr[(string) $i] = ((float) PhpFloat($this->arr[(string) $i])) + ((float) PhpFloat($value));
    }

    public function joinset($i, $value, $delimiter): void
    {
        $cur = $this->get($i);
        $this->arr[(string) $i] = strlen($cur) ? $cur . $delimiter . $value : $value;
    }

    public function get($i)
    {
        return $this->arr[(string) $i];
    }

    public function getAll($exp = "_#1", $join = '', $sort = ''): string
    {
        $work_arr = $this->arr;
        switch ($sort) {
            case 'key':  ksort($work_arr, SORT_NUMERIC);
                break;
            case 'rkey': krsort($work_arr, SORT_NUMERIC);
                break;
        }
        $ret = [];
        // speedup
        if (($exp === '_#1') || !strlen($exp)) {
            $ret = $work_arr;
        } else {
            foreach ($work_arr as $k => $v) {
                $ret[] = str_replace(['_#1','_#2'], [$v,$k], $exp);
            }
        }
        return implode($join, $ret);
    }
    public function getFiltered($filter, $delimiter): string
    {
        $ret = preg_grep('/' . preg_quote($filter, '/') . '/', $this->arr);
        return $ret ? AA_Stringexpand::joinWithDelimiter($ret, $delimiter) : '';
    }
}
