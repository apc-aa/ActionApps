<?php

namespace AA\Util;

use AA;
use AA_Aliases;
use LazyloaderInterface;

abstract class AbstractAliasgenerator implements LazyloaderInterface {

    /**
     * @var AA_Aliases $add_aliases
     */
    protected $add_aliases = null;

    /** caches aliases for the same parameters */
    public function lazyLoad() {
        $gen = AA::Contentcache()->get_result([
            static::class,
            'getAliasesFromParams',
        ], [$this->getParamsArray()]);  // do not write static::getAliasesFromParams here - we need different parameters for cache
        if ($this->add_aliases) {
            $gen->add($this->add_aliases);
        }
        return $gen->getAliases();
    }

    /** generate params array for getAliasesFromParams from real generator object */
    protected function getParamsArray(): array {
        return [];
    }

    /** generates AA_Aliases - it is static in order we can cache it */
    abstract public static function getAliasesFromParams(array $params): AA_Aliases;  // must be public - called by cache in getAliases

    /** add nonstandard aliases
     * @usage (new AA_Aliasgenerator_Constant)->addAliases((new AA_Aliases)->addTextAlias('_#ITEM_ID_',$for_id))
     */
    public function addAliases(?AA_Aliases $aliases): AbstractAliasgenerator {
        $this->add_aliases = $aliases;
        return $this;
    }
}