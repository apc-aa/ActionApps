<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Stream;

/** A source that needs a key; the test can make its code fail */
final class KeyedSource extends AbstractSource
{
    /** thrown by fromSettings() */
    public static ?\Throwable $refuse = null;
    /** thrown by credentials() */
    public static ?\Throwable $failKeys = null;
    /** null = the key below */
    public static mixed $keys = null;

    public static function name(): string
    {
        return 'Keyed';
    }

    public static function description(): string
    {
        return 'Needs a key';
    }

    public static function getClassProperties(): array
    {
        return [];
    }

    public static function fromSettings(array $settings): static
    {
        if (self::$refuse) {
            throw self::$refuse;
        }
        return new static();
    }

    public static function credentials(): array
    {
        if (self::$failKeys) {
            throw self::$failKeys;
        }
        return self::$keys ?? [['vendor' => 'keyed', 'context' => 'login']];
    }

    public function streams(): iterable
    {
        return [];
    }

    public function jobs(Stream $stream, Context $ctx, ?string $known): iterable
    {
        return [];
    }
}
