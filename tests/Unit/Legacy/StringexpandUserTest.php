<?php

declare(strict_types=1);

/**
 * {user} without a parameter prints the login name of the visitor. An anonymous visitor has none
 * (the "nobody" login leaves the name empty, only {user:id} says "nobody"), so the output is empty -
 * a template tells logged-in visitors apart by {ifset:{user}:...}.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA\Cache\PageCache;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[RunClassInSeparateProcess]
final class StringexpandUserTest extends TestCase
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
        unset($_SERVER['PHP_AUTH_USER'], $_SERVER['REMOTE_USER'], $GLOBALS['auth']);
    }

    public function test_expand_anonymousVisitor_isEmpty(): void
    {
        self::assertSame('', self::expand());
    }

    public function test_expand_nobodyLogin_isEmpty(): void
    {
        $GLOBALS['auth'] = self::login('');
        self::assertSame('', self::expand());
    }

    public function test_expand_loggedUser_givesTheLoginName(): void
    {
        $GLOBALS['auth'] = self::login('jane');
        self::assertSame('jane', self::expand());
    }

    public function test_expand_httpAuthentication_givesItsName(): void
    {
        $_SERVER['PHP_AUTH_USER'] = 'basic';
        self::assertSame('basic', self::expand());
        unset($_SERVER['PHP_AUTH_USER']);
        $_SERVER['REMOTE_USER'] = 'remote';
        self::assertSame('remote', self::expand());
    }

    private static function expand(): string
    {
        return (new \AA_Stringexpand_User([]))->expand();
    }

    private static function login(string $uname): object
    {
        return new class ($uname) {
            public function __construct(private string $uname) {}

            public function getUname(): string
            {
                return $this->uname;
            }
        };
    }
}
