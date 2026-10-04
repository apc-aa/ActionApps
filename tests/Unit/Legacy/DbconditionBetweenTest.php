<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Dbcondition;
use AA\IO\DB\DB_AA;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The BETWEEN bounds of AA_Dbcondition (include/searchlib.php) are written into SQL by hand. Fields
 * stored as text - all item-table fields and text content fields - skip the number check, and the
 * value comes from public conds[] of slice.php. Only two numbers form a range; anything else never
 * worked (it was SQL) and matches nothing - dropping the condition would list more items.
 * On packed item columns (id, slice_id) a whole literal of 32 hex digits is rewritten to 0x... after
 * the SQL is built, which must never take an escaped apostrophe for the start of a literal.
 */
final class DbconditionBetweenTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    private static function item(string $value): string
    {
        return (new AA_Dbcondition('item', 'publish_date', 'BETWEEN', $value))->getWhereExp();
    }

    private static function content(string $value, string $store = 'text'): string
    {
        return (new AA_Dbcondition('c0', $store, 'BETWEEN', $value, $store, true))->getWhereExp();
    }

    private static function packed(string $operator, string $value): string
    {
        return (new AA_Dbcondition('item', 'id', $operator, $value))->getWhereExp();
    }

    /** the SQL with every string literal replaced by '' - shows what is left outside the literals */
    private static function residue(string $sql): string
    {
        return preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'/s', "''", $sql);
    }

    private static function range(string $column, string $from, string $to): string
    {
        $f = DB_AA::quoteIdentifier($column);
        return "(($f >= $from) AND ($f <= $to))";
    }

    public function test_numbers_form_the_range(): void
    {
        $this->assertSame(self::range('item.publish_date', '0', '9'), self::item('0,9'));
        $this->assertSame(self::range('c0.text', '-1.5', '.5'), self::content(' -1.5 , .5 '));
        $this->assertSame(self::range('c0.number', '+3', '1700000000'), self::content('+3,1700000000', 'number'));
        $this->assertSame(self::range('c0.text', '1e5', '2'), self::content('1e5,2'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noRange(): array
    {
        return [
            'one bound'          => ['5'],
            'three bounds'       => ['1,2,3'],
            'empty bound'        => ['1,'],
            'empty lower bound'  => [',5'],
            'text'               => ['2026-01-01,2026-12-31'],
            'hex'                => ['0x10,0x20'],
            'subquery'           => ['0,9 OR (SELECT SLEEP(5))'],
            'closing apostrophe' => ["0,x' OR SLEEP(5) -- "],
            'slashed apostrophe' => ["0,x\\' OR SLEEP(5) -- "],
        ];
    }

    #[DataProvider('noRange')]
    public function test_anything_but_two_numbers_matches_nothing(string $value): void
    {
        $this->assertSame('1=0', self::item($value), $value);
        $this->assertSame('1=0', self::content($value), $value);
        $this->assertSame('1=0', self::content($value, 'number'), $value);
    }

    public function test_packed_id_becomes_hex_literal(): void
    {
        $id = '0123456789abcdef0123456789abcdef';
        $this->assertSame('(' . DB_AA::quoteIdentifier('item.id') . " = 0x$id)", self::packed('==', $id));
    }

    public function test_escaped_apostrophe_before_id_stays_in_the_literal(): void
    {
        $id = '0123456789abcdef0123456789abcdef';
        $f  = DB_AA::quoteIdentifier('item.id');
        $this->assertSame("($f = 'x\\'$id')", self::packed('==', "x'$id"));
        $this->assertSame("($f <> 'x\\'$id')", self::packed('<>', "x'$id"));
        $this->assertSame("($f REGEXP 'x\\'$id')", self::packed('REGEXP', "x'$id"));
        $this->assertSame("($f= 'x\\'$id' or $f= 'y')", self::packed('=', "x'$id or y"));

        $this->assertSame("($f = '')", self::residue(self::packed('==', "x'$id")));
        $this->assertSame("($f= '' or $f= '')", self::residue(self::packed('=', "x'$id or y")));
    }

    /** why the 0x rewrite may skip a quote after a backslash: quote() leaves no other apostrophe */
    public function test_quote_escapes_every_apostrophe(): void
    {
        foreach (["'", "a'b", "''", "\\'", "\\\\'", "x\0'"] as $s) {
            $this->assertDoesNotMatchRegularExpression("/(?<!\\\\)'/", quote($s), $s);
        }
    }
}
