<?php

namespace AA\Util;

/**
 * This trait implements the ArrayAccess, Countable and IteratorAggregate interface for all classes which use $this->>data[]
 * as associative array property data storage
 * It also implements isEmpty() method
 * @see ArrTrait used for list of items in indexed array *
 */
trait DataArrayAccessTrait
{
    /** @return true, if the value do not contain any data */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /** implements IteratorAggregate */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->data);
    }

    // Array Access implementation --------------------------
    /** implements ArrayAccess */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            // support $obj[] = $value;
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    /** implements ArrayAccess */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    /** implements ArrayAccess */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    /** implements ArrayAccess */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    /** implements Countable interface */
    public function count(): int
    {
        return count($this->data);
    }
}
