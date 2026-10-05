<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Stream;

/** A source that makes itself of a class declaring other keys - the run must keep to the keys of its type */
class ShiftySource extends AbstractSource
{
    public static function name(): string
    {
        return 'Shifty';
    }

    public static function description(): string
    {
        return 'Becomes another class';
    }

    public static function getClassProperties(): array
    {
        return [];
    }

    public static function credentials(): array
    {
        return [['vendor' => 'shifty', 'context' => '']];
    }

    public static function fromSettings(array $settings): static
    {
        return new class extends ShiftySource {
            public static function credentials(): array
            {
                return [['vendor' => 'shifty', 'context' => ''], ['vendor' => 'other', 'context' => '']];
            }
        };
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
