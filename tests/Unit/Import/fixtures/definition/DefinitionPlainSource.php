<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Mapping;
use AA\Import\Stream;

/** A second source, to change a definition to */
final class DefinitionPlainSource extends AbstractSource
{
    public static function name(): string
    {
        return 'Plain';
    }

    public static function description(): string
    {
        return 'A source inserting new items only';
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
        return new Mapping([['to' => 'headline........', 't' => 'value', 'p' => ['new_content' => '{index:name}', 'new_flag' => 't']]], ['headline........']);
    }
}
