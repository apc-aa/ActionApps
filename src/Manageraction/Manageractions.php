<?php

namespace AA\Manageraction;

use AA\Util\ArrTrait;
use ArrayAccess;
use Countable;
use Iterator;

class Manageractions implements Iterator, ArrayAccess, Countable {
    use ArrTrait;

    /** @var AbstractManageraction[] */
    protected array $arr = [];

    public function __construct()    {}

    /**
     * @param $id
     *
     * @return AbstractManageraction|null
     */
    public function getAction($id): ?AbstractManageraction
    {
        return $this->arr[$id] ?: null;
    }

    public function getManagerButtons(string $manager_url, string $id_alias, string $module_alias): array {
        $btns = [];
        foreach ($this->arr as $a) {
            $btns = array_merge($btns, $a->getButton($manager_url, $id_alias, $module_alias));
        }
        return $btns;
    }

    /**
     * @param  AbstractManageraction  $action
     *
     * @return AbstractManageraction
     */
    public function addAction($action): AbstractManageraction
    {
        return $this->arr[$action->getId()] = $action;
    }

    // ArrayAccess, Countable and Iterator interface implementation is in ArrTrait --------------------------
}