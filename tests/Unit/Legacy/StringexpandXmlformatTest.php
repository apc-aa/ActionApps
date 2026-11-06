<?php

declare(strict_types=1);

/**
 * {xmlformat:<xml>} indents the XML. An empty value (a field left blank) gives nothing instead of
 * stopping the page - DOMDocument refuses an empty string by an exception. XML it cannot parse
 * still gives the bare XML declaration with a warning, as it always did.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandXmlformatTest extends TestCase
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

    public function test_expand_indentsTheXml(): void
    {
        self::assertSame("<?xml version=\"1.0\"?>\n<a b=\"1\">\n  <c>x</c>\n</a>\n", self::expand('<a b="1"><c>x</c></a>'));
    }

    public function test_expand_emptyValue_givesNothing(): void
    {
        self::assertSame('', self::expand(''));
        self::assertSame('', self::expand("  \n"));
    }

    public function test_expand_brokenXml_givesTheDeclarationAndWarns(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $out = self::expand('<a>');
        } finally {
            restore_error_handler();
        }
        self::assertSame("<?xml version=\"1.0\"?>\n", $out);
        self::assertNotEmpty($warnings);
    }

    private static function expand(string $xml): string
    {
        return (new \AA_Stringexpand_Xmlformat([]))->expand($xml);
    }
}
