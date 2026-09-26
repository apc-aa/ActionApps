<?php

declare(strict_types=1);

namespace Tests\Unit\IO\DB;

use AA\IO\DB\DB_AA;
use PHPUnit\Framework\TestCase;

/**
 * Characterization of DB_AA::makeWhere() — the SQL WHERE builder. Pure string
 * building (no DB connection); relies on the global qquote()/quote()/xpack_id()
 * escaping helpers from include/zids.php (loaded in tests/bootstrap.php).
 *
 * The asserts double as a guard on the SQL-injection defenses: string values are
 * qquote()-escaped, int values cast, long-id values reduced to 0x.. / 0x00.
 * $varlist item = [name, value, type].
 */
final class DbAaMakeWhereTest extends TestCase
{
    public function test_string_value_is_quoted_and_escaped(): void
    {
        // single quote escaped + wrapped → injection can't break out of the literal
        $this->assertSame(
            "WHERE name = 'O\\'Brien'",
            DB_AA::makeWhere([['name', "O'Brien", 's']]),
        );
    }

    public function test_int_type_casts_and_neutralizes_injection(): void
    {
        $this->assertSame(
            'WHERE age = 5',
            DB_AA::makeWhere([['age', '5 OR 1=1', 'i']]),
        );
    }

    public function test_long_id_type_reduces_invalid_to_0x00(): void
    {
        $this->assertSame(
            'WHERE id = 0x00',
            DB_AA::makeWhere([['id', "x'; DROP TABLE t; --", 'l']]),
        );
        $valid = str_repeat('a', 32);
        $this->assertSame(
            "WHERE id = 0x{$valid}",
            DB_AA::makeWhere([['id', $valid, 'l']]),
        );
    }

    /** the cursor of an item batch: ids in order, packed as they are stored */
    public function test_long_id_after_the_cursor(): void
    {
        $valid = str_repeat('b', 32);
        $this->assertSame("WHERE id > 0x{$valid}", DB_AA::makeWhere([['id', $valid, 'l>']]));
        $this->assertSame('WHERE id > 0x00', DB_AA::makeWhere([['id', '0x0 OR 1=1', 'l>']]));
    }

    public function test_comparison_operator(): void
    {
        $this->assertSame('WHERE age > 18', DB_AA::makeWhere([['age', 18, '>']]));
    }

    public function test_array_value_becomes_escaped_in_list(): void
    {
        $this->assertSame(
            "WHERE c IN ('a\\'','b')",
            DB_AA::makeWhere([['c', ["a'", 'b'], 's']]),
        );
    }

    public function test_empty_array_is_always_false(): void
    {
        $this->assertSame('WHERE 2=1', DB_AA::makeWhere([['c', [], 's']]));
    }

    public function test_multiple_conditions_joined_by_and(): void
    {
        $this->assertSame(
            "WHERE a = 1 AND b = 'x'",
            DB_AA::makeWhere([['a', 1, 'i'], ['b', 'x', 's']]),
        );
    }

    public function test_empty_varlist_returns_empty_string(): void
    {
        $this->assertSame('', DB_AA::makeWhere([]));
    }

    public function test_isnull_and_notnull(): void
    {
        $this->assertSame('WHERE c IS NULL ', DB_AA::makeWhere([['c', null, 'ISNULL']]));
        $this->assertSame('WHERE c IS NOT NULL ', DB_AA::makeWhere([['c', null, 'NOTNULL']]));
    }
}
