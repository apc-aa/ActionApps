<?php

namespace AA\Util;

trait SingletonTrait
{
    private static ?self $instance = null;

    private function __construct() {}
    private function __clone()
    {
        throw new \LogicException('Cloning a singleton is not allowed.');
    }
    final public function __serialize(): array
    {
        throw new \LogicException('Serializing a singleton is not allowed.');
    }
    final public function __unserialize(array $data): void
    {
        throw new \LogicException("Singleton instance cannot be unserialized.");
    }
    final public static function singleton(): static
    {
        return static::$instance ??= new static();
    }
}
