<?php

declare(strict_types=1);

/**
 * {randomstring:<length>} - the length is a number. Anything else gave a loop without end (PHP 8
 * compares a number with a non-numeric string as text, so "$i < 'abc'" never turns false) and a
 * huge number ate the memory, so the length is read as an integer and capped.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandRandomstringTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
        } finally {
            restore_error_handler();
        }
    }

    public function test_expand_givesTheLengthAskedFor(): void
    {
        self::assertMatchesRegularExpression('/^[a-zA-Z0-9]{5}$/', self::expand('5'));
    }

    /** @return array<string, array{string}> */
    public static function noLength(): array
    {
        return [
            'empty'    => [''],
            'text'     => ['abc'],
            'zero'     => ['0'],
            'negative' => ['-3'],
        ];
    }

    #[DataProvider('noLength')]
    public function test_expand_noUsableLength_givesNothing(string $len): void
    {
        self::assertSame('', self::expand($len));
    }

    public function test_expand_hugeLength_isCapped(): void
    {
        self::assertSame(1000, strlen(self::expand('100000000')));
    }

    private static function expand(string $len): string
    {
        return (new \AA_Stringexpand_Randomstring([]))->expand($len);
    }
}
