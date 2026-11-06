<?php

declare(strict_types=1);

/**
 * {cookie:<name>} prints the value of one cookie. A browser may send a cookie named like
 * "name[key]", which PHP hands over as an array - there is no text to print, so the output is
 * empty instead of an error that stops the page.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA\Cache\PageCache;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[RunClassInSeparateProcess]
final class StringexpandCookieTest extends TestCase
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

    protected function setUp(): void
    {
        (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, new PageCache(600));
        (new ReflectionProperty(PageCache::class, 'vary_reads'))->setValue(null, []);
        $_COOKIE = [];
    }

    public function test_expand_givesTheValue(): void
    {
        $_COOKIE['lang'] = 'cz';
        self::assertSame('cz', self::expand('lang'));
    }

    public function test_expand_missingCookie_isEmpty(): void
    {
        self::assertSame('', self::expand('lang'));
    }

    public function test_expand_cookieSentAsArray_isEmpty(): void
    {
        $_COOKIE['auth_email'] = ['x' => '1'];
        self::assertSame('', self::expand('auth_email'));
    }

    private static function expand(string $name): string
    {
        return (new \AA_Stringexpand_Cookie([]))->expand($name);
    }
}
