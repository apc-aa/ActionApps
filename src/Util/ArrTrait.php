<?php

namespace AA\Util;

/**
 * This trait implements the ArrayAccess, Countable and IteratorAggregate interface for all classes which use
 * $this->>arr[] as any indexed array of data storage
 *
 * @see DataArrayAccessTrait  used as associative array property data storage
 */
trait ArrTrait {

    /** implements IteratorAggregate */
    public function getIterator(): \Traversable   {
        return new \ArrayIterator($this->arr);
    }

    /** implements Countable interface */
    public function count(): int { return count($this->arr); }

    /** implements ArrayAccess interface */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {        // support $arr[] = $value;
            $this->arr[] = $value;
        } else {
            $this->arr[$offset] = $value;
        }
    }

    public function offsetExists(mixed $offset): bool { return array_key_exists($offset, $this->arr); }

    public function offsetUnset(mixed $offset): void { unset($this->arr[$offset]); }

    public function offsetGet(mixed $offset): mixed { return $this->arr[$offset] ?? null; }
}