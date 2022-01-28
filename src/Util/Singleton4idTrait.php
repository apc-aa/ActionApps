<?php


namespace AA\Util;

trait Singleton4idTrait {
    private static $instances = [];

    final public static function singleton4id($id) {
        if(!self::$instances[$id]) {
            self::$instances[$id] = new self($id);
        }

        return self::$instances[$id];
    }
}
