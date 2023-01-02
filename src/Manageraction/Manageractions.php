<?php

namespace AA\Manageraction;

use ArrayAccess;
use Countable;
use Iterator;

class Manageractions implements Iterator, ArrayAccess, Countable {
    /** @var \AA\Manageraction\AbstractManageraction[] */
    var $actions;

    function __construct() {
        $this->actions = [];
    }

    /**
     * @param $id
     * @return \AA\Manageraction\AbstractManageraction|null
     */
    function getAction($id) {
        return $this->actions[$id] ?: null;
    }

    function getManagerButtons(string $manager_url, string $id_alias, string $module_alias): array {
        $btns = [];
        foreach ($this->actions as $a) {
            $btns = array_merge($btns, $a->getButton($manager_url, $id_alias, $module_alias));
        }
        return $btns;
    }

    /**
     * @param \AA\Manageraction\AbstractManageraction $action
     * @return \AA\Manageraction\AbstractManageraction
     */
    function addAction($action) {
        return $this->actions[$action->getId()] = $action;
    }

    /** Iterator interface */
    public function rewind() { reset($this->actions); }

    public function current() { return current($this->actions); }

    public function key() { return key($this->actions); }

    public function next() { next($this->actions); }

    public function valid() { return (current($this->actions) !== false); }

    /** Countable interface */
    public function count() { return count($this->actions); }

    /** ArrayAccess interface */
    public function offsetSet($offset, $value) { $this->actions[$offset] = $value; }

    public function offsetExists($offset) { return isset($this->actions[$offset]); }

    public function offsetUnset($offset) { unset($this->actions[$offset]); }

    public function offsetGet($offset) { return $this->actions[$offset] ?? null; }
}