<?php


namespace AA\Util;

trait SingletonTrait {
    private static $instance;

    private function __construct() {}
    private function __clone()     {}
    private function __wakeup()    {}
    private function __sleep()     {}

    final public static function singleton() {
        if(!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}
