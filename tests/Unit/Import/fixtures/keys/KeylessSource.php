<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Stream;

/** A source that needs no key */
final class KeylessSource extends AbstractSource
{
    public static function name(): string
    {
        return 'Keyless';
    }

    public static function description(): string
    {
        return 'Needs no key';
    }

    public static function getClassProperties(): array
    {
        return [];
    }

    public static function fromSettings(array $settings): static
    {
        return new static();
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
