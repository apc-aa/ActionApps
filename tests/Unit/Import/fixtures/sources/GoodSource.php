<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Stream;

/** A source the way an installation adds one: one file, class named after it, global namespace */
final class GoodSource extends AbstractSource
{
    /** @param array<string, string> $settings */
    public function __construct(public readonly array $settings) {}

    public static function name(): string
    {
        return 'Good';
    }

    public static function description(): string
    {
        return 'A source for the test';
    }

    public static function getClassProperties(): array
    {
        return [];
    }

    public static function fromSettings(array $settings): static
    {
        return new static($settings);
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
