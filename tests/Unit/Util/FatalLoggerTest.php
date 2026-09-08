<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\FatalLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure parts of AA\Util\FatalLogger: which error types count as fatal and how a
 * fatal is squeezed into the `log` table columns (selector varbinary(255),
 * params varbinary(128)). register()/log() touch PHP handlers and AA_Log — not
 * covered here.
 */
final class FatalLoggerTest extends TestCase
{
    /** @return array<string, array{0:int, 1:bool}> */
    public static function errorTypes(): array
    {
        return [
            'E_ERROR'             => [E_ERROR, true],
            'E_PARSE'             => [E_PARSE, true],
            'E_COMPILE_ERROR'     => [E_COMPILE_ERROR, true],
            'E_RECOVERABLE_ERROR' => [E_RECOVERABLE_ERROR, true],
            'E_USER_ERROR'        => [E_USER_ERROR, true],
            'E_WARNING'           => [E_WARNING, false],
            'E_NOTICE'            => [E_NOTICE, false],
            'E_DEPRECATED'        => [E_DEPRECATED, false],
        ];
    }

    #[DataProvider('errorTypes')]
    public function testIsFatal(int $type, bool $expected): void
    {
        self::assertSame($expected, FatalLogger::isFatal($type));
    }

    public function testFormatStripsBasePathAndKeepsLine(): void
    {
        $r = FatalLogger::format('Uncaught TypeError: x', '/www/aa/include/util.php', 12, '/www/aa/');
        self::assertSame('include/util.php:12', $r['selector']);
        self::assertSame('Uncaught TypeError: x', $r['params']);
    }

    public function testFormatCollapsesWhitespaceInMessage(): void
    {
        $r = FatalLogger::format("a\n  b\t\tc", '/f.php', 1);
        self::assertSame('a b c', $r['params']);
    }

    public function testFormatFitsColumnsInBytesWithoutBreakingUtf8(): void
    {
        $msg  = str_repeat('ř', 100);                  // 200 bytes
        $file = '/' . str_repeat('dlouhá-cesta/', 40) . 'soubor.php'; // > 255 bytes
        $r    = FatalLogger::format($msg, $file, 77);
        self::assertLessThanOrEqual(128, strlen($r['params']));
        self::assertTrue(mb_check_encoding($r['params'], 'UTF-8'));
        self::assertLessThanOrEqual(255, strlen($r['selector']));
        self::assertTrue(mb_check_encoding($r['selector'], 'UTF-8'));
        self::assertStringEndsWith('soubor.php:77', $r['selector']);
    }
}
