<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\SourceInterface;
use AA\Import\Stream;
use PHPUnit\Framework\TestCase;

final class AbstractSourceTest extends TestCase
{
    public function test_abstractSource_defaults(): void
    {
        $source = new class extends AbstractSource {
            public static function name(): string
            {
                return 'Test';
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
        };

        self::assertInstanceOf(SourceInterface::class, $source);
        self::assertSame(15, $source->minJobSeconds());
        self::assertSame([], $source->credentials());
        // a source of an installation written before defaultMapping() still loads, with no rows
        self::assertSame([], $source->defaultMapping()->rows);
        self::assertSame('update/insert', $source->defaultMapping()->storeMode);
    }
}
