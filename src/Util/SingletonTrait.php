<?php
namespace AA\Util;

trait SingletonTrait {
    private static ?self $instance = null;

    private function __construct() {}
    private function __clone()     {}
    final public function __wakeup() {}
    final public function __sleep() { return []; }

    final public static function singleton(): static {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }
}