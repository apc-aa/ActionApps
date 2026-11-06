<?php

declare(strict_types=1);

/**
 * aa_version() - the version of AA and of PHP, which {version} prints on a page. The full string
 * leaves out the visitor's address: a site page with {version} is stored in the page cache, and
 * the address of whoever rendered it would be shown to every later visitor ({server:REMOTE_ADDR}
 * is the way to print it, it keeps the page out of the cache).
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class AaVersionTest extends TestCase
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

    public function test_aaVersion_parts(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}\.\d{1,2}$/', aa_version('aa'));
        self::assertSame(PHP_VERSION, aa_version('php'));
        self::assertSame(aa_version('svn'), aa_version('svn2'));
        self::assertTrue(str_starts_with(aa_version('build'), aa_version('svn')), 'the build stamp starts with the commit (both empty in a source checkout)');
    }

    public function test_aaVersion_full_namesAaAndPhp(): void
    {
        $full = aa_version();
        self::assertStringStartsWith('ActionApps ' . aa_version('aa') . ' (', $full);
        self::assertStringContainsString('), PHP ' . PHP_VERSION, $full);
        self::assertSame($full, aa_version('full'));
        self::assertSame($full, (new \AA_Stringexpand_Version([]))->expand());
    }

    public function test_aaVersion_full_leavesOutTheVisitorsAddress(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        try {
            self::assertStringNotContainsString('203.0.113.7', aa_version());
            self::assertStringNotContainsString('203.0.113.7', (new \AA_Stringexpand_Version([]))->expand('full'));
        } finally {
            unset($_SERVER['REMOTE_ADDR']);
        }
    }
}
