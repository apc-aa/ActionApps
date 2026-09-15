<?php

namespace AA\Manageraction;

use AA\Util\ArrTrait;
use ArrayAccess;
use Countable;

class Manageractions implements \IteratorAggregate, ArrayAccess, Countable
{
    use ArrTrait;

    /** @var AbstractManageraction[] */
    protected array $arr = [];

    public function __construct() {}

    /**
     * @param $id
     *
     * @return AbstractManageraction|null
     */
    public function getAction($id): ?AbstractManageraction
    {
        return $this->arr[$id] ?: null;
    }

    public function getManagerButtons(string $manager_url, string $id_alias, string $module_alias): array
    {
        $btns = [];
        foreach ($this->arr as $a) {
            $btns = array_merge($btns, $a->getButton($manager_url, $id_alias, $module_alias));
        }
        return $btns;
    }

    /** Same as getManagerButtons() but split by isSecondaryButton().
     *  @return array{primary: array<string,string>, secondary: array<string,string>}
     */
    public function getManagerButtonsGrouped(string $manager_url, string $id_alias, string $module_alias): array
    {
        $primary = $secondary = [];
        foreach ($this->arr as $a) {
            $btn = $a->getButton($manager_url, $id_alias, $module_alias);
            if ($a->isSecondaryButton()) {
                $secondary = array_merge($secondary, $btn);
            } else {
                $primary   = array_merge($primary, $btn);
            }
        }
        return ['primary' => $primary, 'secondary' => $secondary];
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

    // ArrayAccess, Countable and IteratorAggregate interface implementation is in ArrTrait --------------------------
}
