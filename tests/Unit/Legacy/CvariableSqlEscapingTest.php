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

    /** "number" and "integer" go inside the quotes unescaped - only a number may get there */
    public function testNumberTypesTurnNonNumericValuesIntoAPureNumber(): void
    {
        foreach (['number', 'integer'] as $type) {
            foreach (["x'y" => "'0'", 'a\\b' => "'0'", '1 OR 1=1' => "'1'", "1', x='y" => "'1'", "12\\' OR '1" => "'12'", 'abc' => "'0'", '' => "'0'"] as $value => $sql) {
                self::assertSame($sql, (new Cvariable('n', $type, (string) $value))->getSQLValue(), "$type: $value");
            }
        }
    }

    /** a cast would change them: MySQL rounds '1.9', PHP truncates it */
    public function testNumberTypesKeepNumericValuesAsTheyAre(): void
    {
        foreach (['1.9', '-3', '1e3', '007', ' 5', '0', 42, 1.5, -7] as $value) {
            self::assertSame("'$value'", (new Cvariable('n', 'number', $value))->getSQLValue(), var_export($value, true));
        }
    }

    public function testNumberTypesCastNullBooleansAndArrays(): void
    {
        foreach ([[null, "'0'"], [false, "'0'"], [true, "'1'"], [[], "'0'"], [['x'], "'1'"]] as [$value, $sql]) {
            self::assertSame($sql, (new Cvariable('n', 'number', $value))->getSQLValue(), var_export($value, true));
        }
    }

    /** a typed column still casts, the value never reaches it as text */
    public function testNumberTypeWithAnIntColumnCasts(): void
    {
        $column = new class {
            public function getFieldType(): string
            {
                return 'int';
            }
        };
        self::assertSame(1, (new Cvariable('n', 'number', "1' OR '1"))->getSQLValue($column));
        self::assertSame(1, (new Cvariable('n', 'number', '1.9'))->getSQLValue($column));
    }
}
