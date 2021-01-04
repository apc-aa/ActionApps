<?php
/**
 * Created by PhpStorm.
 * User: honzama
 * Date: 10.4.19
 * Time: 13:47
 */

namespace AA\Util;


class Searchfields implements \Iterator, \ArrayAccess, \Countable {

    protected $fields = [];

    /**  Valid searchfield properties  */
    private static function FLDPROPS() {
        return  ['name', 'field', 'operators', 'table', 'search_pri', 'order_pri', 'opt_group'];
    }

    /**  add search field to Searchfields
     * @param string $id
     * @param string $name
     * @param string $field
     * @param string $operators
     * @param string $table
     * @param int    $search_pri searchbar priority (0 = "do not show in searchbar")
     * @param int    $order_pri orderbar  priority (0 = "do not show in orderbar")
     * @param string $opt_group
     */
    public function add( $id, $name, $field, $operators='text', $table='', $search_pri=0, $order_pri=0, $opt_group='') {
        if (!$id) {
            return;
        }
        $this->fields[$id] = ['name'=>$name, 'field'=>$field, 'operators'=>$operators, 'table'=>$table, 'search_pri'=>$search_pri, 'order_pri'=>$order_pri, 'opt_group'=>$opt_group];
    }

    public function addArray( $id, array $fld) {
        if (!$id) {
            return;
        }
        // copy just existing properties
        $this->fields[$id] = array_intersect_key($fld, array_flip(self::FLDPROPS()));
    }

    /**
     * @param bool $add_alltext
     * @param bool $add_allnum
     * @return array
     */
    public function getSearchArray($add_alltext=true, $add_allnum=true) {
        return $this->generateArray('search', $add_alltext, $add_allnum);
    }

    /**
     * @param bool $add_alltext
     * @param bool $add_allnum
     * @return array
     */
    public function getOperatorsArray($add_alltext=true, $add_allnum=true) {
        return $this->generateArray('operators', $add_alltext, $add_allnum);
    }

    /**
     * @return array
     */
    public function getOrderArray() {
        $order_fields     = [];
        $fields           = $this->fields;

        // sort
        uasort ($fields, "orderfields_cmp");
        $last_pri = 0;
        foreach ( $fields as $fid => $v) {
            if ($v['order_pri'] > 0 ) {
                // orderfields could be splited into groups
                // one group is always with order_pri 0-999, 1000-1999, ...
                if ( $last_pri AND (floor($last_pri/1000) != floor($v['order_pri']/1000)) ) {
                    $order_fields['AA_OPTGROUP '.$last_pri] = ($v['opt_group'] ?: '---------------');
                }
                $last_pri = $v['order_pri'];
                $order_fields[$fid]     = $v['name'];
            }
        }
        return $order_fields;
    }

    /** Return array of field ids
     * @return array
     */
    public function getFieldidsArray() {
        return array_keys($this->fields);
    }

    /** Return array of field names
     * @return array
     */
    public function getFieldnamesArray() {
        return array_column($this->fields,'name');
    }


    /**
     * @param string $type      search | operators
     * @param bool $add_alltext
     * @param bool $add_allnum
     * @return array
     */
    private function generateArray($type, $add_alltext=true, $add_allnum=true) {
        $search_fields    = [];
        $search_operators = [];
        $fields           = $this->fields;

        // add "all fields" search
        if ($add_alltext) {
            $search_fields['all_fields']            = _m('-- any text field --');
            $search_operators['all_fields']         = 'text';
        }
        if ($add_allnum) {
            $search_fields['all_fields_numeric']    = _m('-- any numeric field --');
            $search_operators['all_fields_numeric'] = 'numeric';
        }

        uasort ($fields, "searchfields_cmp");
        $last_pri = 0;
        foreach ( $fields as $fid => $v) {
            if ($v['search_pri'] > 0 ) {           // not searchable fields
                // searchfields could be splited into groups
                // one group is always with search_pri 0-999, 1000-1999, ...
                if ( $last_pri AND (floor($last_pri/1000) != floor($v['search_pri']/1000)) ) {
                    $search_fields['AA_OPTGROUP '.$last_pri] = ($v['opt_group'] ?: '---------------');
                    $search_operators[$fid] = 'text';
                }
                $last_pri = $v['search_pri'];
                $search_fields[$fid]    = $v['name'];
                $search_operators[$fid] = $v['operators'];
            }
        }

        return ($type == 'search') ? $search_fields : $search_operators;
    }

    /**
     * @deprecated
     * @todo - replace all calls with Searfield object
     * @return array - old searchfield array for backward compatibility in some functions
     */
    public function getArrayDeprecated() {
        return $this->fields;
    }



    /** Iterator interface */
    public function rewind()  { reset($this->fields);                        }
    public function current() { return current($this->fields);               }
    public function key()     { return key($this->fields);                   }
    public function next()    { next($this->fields);                         }
    public function valid()   { return (current($this->fields) !== false);   }

    /** Countable interface */
    public function count()   { return count($this->fields);                 }

    /** ArrayAccess interface */
    public function offsetSet($offset, $value) { $this->fields[$offset] = $value;      }
    public function offsetExists($offset)      { return isset($this->fields[$offset]); }
    public function offsetUnset($offset)       { unset($this->fields[$offset]);        }
    public function offsetGet($offset)         { return isset($this->a[$offset]) ? $this->fields[$offset] : null; }
}