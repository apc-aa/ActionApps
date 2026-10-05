<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Mapping;
use AA\Import\Stream;

/** A source without settings whose mapping has no key - nothing could ever give it one */
final class DefinitionUnkeyedSource extends AbstractSource
{
    public static function name(): string
    {
        return 'Unkeyed';
    }

    public static function description(): string
    {
        return 'A source without settings and without a key';
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
        return new Mapping([['to' => 'headline........', 't' => 'value', 'p' => ['new_content' => '{index:name}', 'new_flag' => 't']]]);
    }
}
