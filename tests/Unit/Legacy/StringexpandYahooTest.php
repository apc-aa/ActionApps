<?php

declare(strict_types=1);

/**
 * {yahoo:<name>[:<action>[:<style>]]} - the name usually comes from an item field, so it goes
 * into the link and the status image URL encoded; a plain name (letters, digits, "_", ".") reads
 * the same as before.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandYahooTest extends TestCase
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

    public function test_expand_plainName(): void
    {
        self::assertSame(
            '<a href="ymsgr:sendim?jane.doe_1"><img src="http://opi.yahoo.com/online?u=jane.doe_1&m=g&t=2"  style="border: none; width: 125px; height: 25px;" alt="My status" /></a>',
            self::expand('jane.doe_1'),
        );
    }

    public function test_expand_actionAndStyle(): void
    {
        $out = self::expand('jane', 'call', '0');
        self::assertStringContainsString('href="ymsgr:call?jane"', $out);
        self::assertStringContainsString('src="http://opi.yahoo.com/online?u=jane&m=g&t=0"', $out);
    }

    public function test_expand_emptyName_givesNothing(): void
    {
        self::assertSame('', self::expand(''));
    }

    public function test_expand_nameStaysInsideTheAttributes(): void
    {
        $out = self::expand('x" onmouseover="alert(1)<b>&y');
        self::assertStringNotContainsString('" onmouseover=', $out);
        self::assertStringNotContainsString('<b>', $out);
        self::assertStringContainsString('href="ymsgr:sendim?x%22%20onmouseover%3D%22alert%281%29%3Cb%3E%26y"', $out);
        self::assertStringContainsString('src="http://opi.yahoo.com/online?u=x%22%20onmouseover%3D%22alert%281%29%3Cb%3E%26y&m=g&t=2"', $out);
    }

    private static function expand(string $name, string $action = 'sendim', string $style = '2'): string
    {
        return (new \AA_Stringexpand_Yahoo([]))->expand($name, $action, $style);
    }
}
