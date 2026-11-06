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

    public function test_expand_anonymousVisitor_idWithoutWarnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            // first: "global $auth" in AuthGetUid() would create the variable it checks
            self::assertSame('', StrExpand('AA_Stringexpand_User', ['']), 'the cached path of a page without a login');
            self::assertSame('', self::expand('id'));
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $warnings);
    }

    public function test_expand_nobodyLogin_idIsNobody(): void
    {
        $GLOBALS['auth'] = self::login('', 'nobody');
        self::assertSame('nobody', self::expand('id'));
    }

    private static function expand(string $field = ''): string
    {
        return (new \AA_Stringexpand_User([]))->expand($field);
    }

    private static function login(string $uname, string $uid = ''): object
    {
        return new class ($uname, $uid) {
            public function __construct(private string $uname, private string $uid) {}

            public function getUname(): string
            {
                return $this->uname;
            }

            public function getUid(): string
            {
                return $this->uid;
            }
        };
    }
}
