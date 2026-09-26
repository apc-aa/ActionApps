<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use Cvariable;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../include/varset.php';

/**
 * Pins the SQL-value escaping contract that filldisc.php's public discussion INSERT
 * relies on after the unauthenticated SQL-injection fix (finding N1, 2026-09).
 *
 *  - Cvarset/Cvariable type "text" runs the value through quote()=addslashes, so RAW
 *    user input (InputVars::stringPOST for subject/author/e_mail/body, $_SERVER for
 *    remote_addr) cannot break out of the '...' string literal.
 *  - Type "quoted" emits the value verbatim inside the quotes — the footgun that made
 *    filldisc.php injectable once those fields were read RAW via InputVars. Documented
 *    here so a future edit that reintroduces "quoted" for raw input is caught.
 */
final class CvariableSqlEscapingTest extends TestCase
{
    /** an apostrophe closes the SQL string literal; the rest is the injected tail */
    private const PAYLOAD = "O'Brien'), ((SELECT 1))-- -";

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // huhe(), which reports a rejected "unpacked" value
    }

    public function testTextTypeEscapesTheApostrophe(): void
    {
        $sql = (new Cvariable('subject', 'text', self::PAYLOAD))->getSQLValue();

        // quote()=addslashes turns ' into \' → the literal cannot be broken out of
        self::assertStringContainsString("O\\'Brien", $sql, 'type "text" must escape');
        self::assertStringStartsWith("'", $sql);
        self::assertStringEndsWith("'", $sql);
    }

    public function testQuotedTypeDoesNotEscape_documentsTheN1Footgun(): void
    {
        $sql = (new Cvariable('subject', 'quoted', self::PAYLOAD))->getSQLValue();

        // "quoted" emits the value as-is: the apostrophe is NOT escaped → SQL injection.
        // This is exactly why filldisc.php must feed RAW input through "text", not "quoted".
        self::assertStringNotContainsString("O\\'Brien", $sql, 'type "quoted" does not escape (by design)');
        self::assertStringContainsString(self::PAYLOAD, $sql);
    }

    public function testTextTypeIsANoOpForABareIpAddress(): void
    {
        // remote_addr moved "quoted"->"text": a legitimate IP has no special chars,
        // so the stored value is byte-identical — no behaviour change for real traffic.
        $sql = (new Cvariable('remote_addr', 'text', '192.168.1.9'))->getSQLValue();
        self::assertSame("'192.168.1.9'", $sql);
    }

    /** "unpacked" ids go out as a 0x… literal; constant.ancestors holds several ids concatenated */
    public function testUnpackedTypeWritesWholeIdsAsHexLiteral(): void
    {
        $id = '0123456789abcdef0123456789abcdef';
        self::assertSame("0x$id", (new Cvariable('id', 'unpacked', $id))->getSQLValue());
        self::assertSame("0x$id$id", (new Cvariable('ancestors', 'unpacked', "$id$id"))->getSQLValue());
    }

    public function testUnpackedTypeRejectsAnythingElse(): void
    {
        $id = '0123456789abcdef0123456789abcdef';
        foreach (['', substr($id, 0, 16), $id . '0123', 'x' . substr($id, 1), "$id' OR 1 -- " . substr($id, 0, 18)] as $value) {
            self::assertSame("''", (new Cvariable('id', 'unpacked', $value))->getSQLValue(), $value);
        }
    }
}
