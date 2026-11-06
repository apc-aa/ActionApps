<?php

declare(strict_types=1);

/**
 * {_#ALIAS} expands an alias at once; a bare {_} is just text in braces and must not warn.
 */

namespace Tests\Unit\Legacy;

use AA;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

#[RunClassInSeparateProcess]
final class StringexpandUnderscoreTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        } finally {
            restore_error_handler();
        }
        $GLOBALS['spareDBs'] = [new RecordingDb()];
    }

    public static function tearDownAfterClass(): void
    {
        $GLOBALS['spareDBs'] = [];
    }

    public function test_unalias_bareUnderscore_noWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg, string $f = "", int $l = 0) use (&$warnings): bool {
            $warnings[] = "$msg @$f:$l";
            return true;
        });
        try {
            $out = AA::Un('a{_}b');
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $warnings);
        self::assertSame('ab', $out);
    }
}
