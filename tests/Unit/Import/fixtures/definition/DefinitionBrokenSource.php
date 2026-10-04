<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Mapping;
use AA\Import\Stream;

/** A source that fails to give its default mapping */
final class DefinitionBrokenSource extends AbstractSource
{
    public static function name(): string
    {
        return 'Broken';
    }

    public static function description(): string
    {
        return 'A source failing without settings';
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

    public function defaultMapping(): Mapping
    {
        throw new RuntimeException('no settings, no mapping');
    }
}
