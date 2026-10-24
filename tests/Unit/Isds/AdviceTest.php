<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Actions;
use AA\Isds\Advice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Advice: what a person on the page of a mass sending reads of a code */
final class AdviceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, array{int, string}> post_max_size in bytes, as the text names it */
    public static function limits(): array
    {
        return [
            'megabytes'     => [8 * 1024 * 1024, '8 MB'],
            'a part of one' => [1536 * 1024, '1.5 MB'],
            'under one'     => [512 * 1024, '512 kB'],
            'a few bytes'   => [100, '1 kB'],
        ];
    }

    #[DataProvider('limits')]
    public function test_text_tooLargeNamesTheLimit(int $bytes, string $limit): void
    {
        self::assertStringContainsString("than the server takes ($limit).", Advice::text(Actions::TOO_LARGE, [$bytes]));
    }

    public function test_text_unknownIsEmpty(): void
    {
        self::assertSame('', Advice::text('NO_SUCH_CODE'));
    }
}
