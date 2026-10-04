<?php

declare(strict_types=1);

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Mapping;
use AA\Import\Stream;

/** A source whose default mapping sets every mapping property of a definition */
final class DefinitionMappedSource extends AbstractSource
{
    public static function name(): string
    {
        return 'Mapped';
    }

    public static function description(): string
    {
        return 'A source with a default mapping';
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
        return new Mapping(
            [
                ['to' => 'relation........', 't' => 'value', 'p' => ['new_content' => '{index:id}', 'new_flag' => 't']],
                ['to' => 'headline........', 't' => 'value', 'p' => ['new_content' => '{index:název}', 'new_flag' => 't']],
            ],
            ['relation........'],
            'update',
            ['{ifeq:{index:id}::0:1}'],
            '{recompute:{index:id}}',
        );
    }
}
