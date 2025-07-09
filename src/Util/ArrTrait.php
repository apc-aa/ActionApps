<?php

namespace AA\Util;

/**
 * This trait implements the ArrayAccess, Countable and Iterator interface for all classes which use
 * $this->>arr[] as any indexed array of data storage
 * @see DataArrayAccessTrait  used as associative array property data storage
 */
trait ArrTrait {

    /** implements Iterator interface */
    public function rewind(): void { reset($this->arr); }

    public function current(): mixed { return current($this->arr); }

    public function key(): mixed                      { return key($this->arr); }

    public function next(): void { next($this->arr); }

    public function valid(): bool { return (current($this->arr) !== false); }

    /** implements Countable interface */
    public function count(): int { return count($this->arr); }

    /** implements ArrayAccess interface */
    public function offsetSet($offset, $value): void { $this->arr[$offset] = $value; }

    public function offsetExists($offset): bool { return isset($this->arr[$offset]); }

    public function offsetUnset($offset): void { unset($this->arr[$offset]); }

    public function offsetGet($offset): mixed { return $this->arr[$offset] ?? null; }
}