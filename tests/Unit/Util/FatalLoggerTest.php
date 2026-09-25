<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\IO\DB\DB_AA;
use AA\IO\DB\DB_PDO;
use AA\Util\FatalLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    /** a fatal inside DB_AA::transaction(): its `log` row would go to the transaction's handle and be rolled
     *  back with it (the request died there) - error_log() instead. A booted AA played in its own process. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNoDbRowInsideATransaction(): void
    {
        require_once __DIR__ . '/fixtures/booted_aa_stub.php';
        require_once __DIR__ . '/../IO/DB/fixtures/locsess_pool_stub.php';
        $this->assertTrue(FatalLogger::toDb(), 'booted, no transaction: the log table');
        $GLOBALS['spareDBs'] = [new class (['type' => 'db_pdo:sqlite', 'database' => ':memory:']) extends DB_PDO {
            public function beginTransaction(): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }
        }];
        try {
            $inside = DB_AA::transaction(static fn(): bool => FatalLogger::toDb());
        } finally {
            $GLOBALS['spareDBs'] = [];
        }
        $this->assertFalse($inside);
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
