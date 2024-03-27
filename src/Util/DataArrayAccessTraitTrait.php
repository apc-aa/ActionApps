<?php

namespace AA\Util;

/**
 * This trait implements the ArrayAccess, Countable and Iterator interface for all classes which use $this->>data[]
 * as associative array property data storage
 * It also implements isEmpty() method
 * @see ArrTrait used for list of items in indexed array *
 */
trait DataArrayAccessTrait {

    /** @return true, if the value do not contain any data */
    public function isEmpty(): bool {
        return empty($this->data);
    }

    // Array Access implementation --------------------------
    /** implements ArrayAccess */
    public function offsetSet($offset, $value): void {
        if ($offset) {
            $this->data[$offset] = $value;
        }
    }

    /** implements ArrayAccess */
    public function offsetExists($offset): bool {
        return isset($this->data[$offset]);
    }

    /** implements ArrayAccess */
    public function offsetUnset($offset): void {
        unset($this->data[$offset]);
    }

    /** implements ArrayAccess */
    public function offsetGet($offset)/*: mixed (not in PHP7.4)*/ {
        return $this->data[$offset] ?? null;
    }

    /** implements Iterator interface */
    public function rewind()                   { reset($this->data); }

    public function current()                  { return current($this->data); }

    public function key()                      { return key($this->data); }

    public function next()                     { next($this->data); }

    public function valid()                    { return (current($this->data) !== false); }

    /** implements Countable interface */
    public function count()                    { return count($this->data); }
}