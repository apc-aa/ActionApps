<?php

namespace AA\Manageraction;

use ArrayAccess;
use Countable;
use Iterator;

class Manageractions implements Iterator, ArrayAccess, Countable {
    use \AA\Util\ArrTrait;

    /** @var \AA\Manageraction\AbstractManageraction[] */
    protected array $arr = [];

    function __construct() {
        $this->arr = [];
    }

    /**
     * @param $id
     * @return \AA\Manageraction\AbstractManageraction|null
     */
    function getAction($id) {
        return $this->arr[$id] ?: null;
    }

    function getManagerButtons(string $manager_url, string $id_alias, string $module_alias): array {
        $btns = [];
        foreach ($this->arr as $a) {
            $btns = array_merge($btns, $a->getButton($manager_url, $id_alias, $module_alias));
        }
        return $btns;
    }

    /**
     * @param \AA\Manageraction\AbstractManageraction $action
     * @return \AA\Manageraction\AbstractManageraction
     */
    function addAction($action) {
        return $this->arr[$action->getId()] = $action;
    }

    // ArrayAccess, Countable and Iterator interface implementation is in ArrTrait --------------------------
}