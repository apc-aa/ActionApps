<?php

declare(strict_types=1);

/**
 * {ord:<string>} - the code of the first byte, as PHP ord() gave it. PHP 8.5 deprecates ord() of an
 * empty string or of more bytes than one (any accented letter in UTF-8), so only the first byte is given.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandOrdTest extends TestCase
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

    /** @return array<string, array{string, string}> */
    public static function strings(): array
    {
        return [
            'one letter'  => ['A', '65'],
            'a word'      => ['Bio', '66'],
            'empty'       => ['', '0'],
            'two bytes'   => ['Ž', '197'],
            'a digit'     => ['7', '55'],
        ];
    }

    #[DataProvider('strings')]
    public function test_expand_givesTheFirstByte(string $string, string $code): void
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new \ErrorException($msg, 0, $no);
        });
        try {
            self::assertSame($code, (new \AA_Stringexpand_Ord([]))->expand($string));
        } finally {
            restore_error_handler();
        }
    }
}
