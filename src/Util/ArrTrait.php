<?php

namespace AA\Util;

/**
 * This trait implements the ArrayAccess, Countable and Iterator interface for all classes which use
 * $this->>arr[] as any indexed array of data storage
 * @see DataArrayAccessTrait  used as associative array property data storage
 */
trait ArrTrait {

    /** implements Iterator interface */
    public function rewind()                   { reset($this->arr); }

    public function current()                  { return current($this->arr); }

    public function key()                      { return key($this->arr); }

    public function next()                     { next($this->arr); }

    public function valid()                    { return (current($this->arr) !== false); }

    /** implements Countable interface */
    public function count()                    { return count($this->arr); }

    /** implements ArrayAccess interface */
    public function offsetSet($offset, $value) { $this->arr[$offset] = $value; }

    public function offsetExists($offset)      { return isset($this->arr[$offset]); }

    public function offsetUnset($offset)       { unset($this->arr[$offset]); }

    public function offsetGet($offset)         { return $this->arr[$offset] ?? null; }
}