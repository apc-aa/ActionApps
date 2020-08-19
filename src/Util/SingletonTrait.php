<?php


namespace AA\Util;

trait SingletonTrait {
    private static $instance;

    final private function __construct() {}
    final private function __clone()     {}
    final private function __wakeup()    {}
    final private function __sleep()     {}

    final public static function singleton() {
        if(!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}
