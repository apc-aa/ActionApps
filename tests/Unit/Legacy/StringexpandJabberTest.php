<?php

declare(strict_types=1);

/**
 * {jabber:<jid>[:<action>[:<style>]]} - the address usually comes from an item field, so it is
 * HTML-escaped in the link and the status image; a plain address (user@host/resource) reads the
 * same as before.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandJabberTest extends TestCase
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

    public function test_expand_plainAddress(): void
    {
        $out = self::expand('jane@jabber.org/home');
        self::assertStringContainsString('<a href="xmpp:jane@jabber.org/home">', $out);
        self::assertStringContainsString('src="http://www.the-server.net:8000/jabber/jane@jabber.org/home"', $out);
    }

    public function test_expand_emptyAddress_givesNothing(): void
    {
        self::assertSame('', self::expand(''));
    }

    public function test_expand_addressStaysInsideTheAttributes(): void
    {
        $out = self::expand('x" onmouseover="alert(1)<b>');
        self::assertStringNotContainsString('" onmouseover=', $out);
        self::assertStringNotContainsString('<b>', $out);
        self::assertStringContainsString('href="xmpp:x&quot; onmouseover=&quot;alert(1)&lt;b&gt;"', $out);
    }

    private static function expand(string $jid): string
    {
        return (new \AA_Stringexpand_Jabber([]))->expand($jid);
    }
}
