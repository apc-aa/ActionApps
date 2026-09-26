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

    /** a backslash of the value must not escape the closing apostrophe */
    public function test_like_escapes_backslash_and_apostrophe(): void
    {
        $this->assertSame(<<<'SQL'
            WHERE c LIKE 'x\\\'y'
            SQL, DB_AA::makeWhere([['c', "x\\'y", 'LIKE']]));
        $this->assertSame(<<<'SQL'
            WHERE c NOT LIKE 'x\\\' OR 1=1 #'
            SQL, DB_AA::makeWhere([['c', "x\\' OR 1=1 #", 'NOT LIKE']]));
        $this->assertSame("WHERE c LIKE 'x\\'y'", DB_AA::makeWhere([['c', "x'y", 'LIKE']]));
    }

    /** '%' and '_' stay wildcards; an escaped '\_' still reaches LIKE as '\_' */
    public function test_like_keeps_wildcards(): void
    {
        $this->assertSame("WHERE c LIKE 'a%b_'", DB_AA::makeWhere([['c', 'a%b_', 'LIKE']]));
        $this->assertSame(<<<'SQL'
            WHERE id NOT LIKE '\\_%'
            SQL, DB_AA::makeWhere([['id', '\_%', 'NOT LIKE']]));
    }

    /** a substring search for the text itself, as aafinder does */
    public function test_like_literal_matches_the_text_itself(): void
    {
        $this->assertSame(<<<'SQL'
            a'b\\c\%d\_e
            SQL, DB_AA::likeLiteral("a'b\\c%d_e"));
        $this->assertSame(<<<'SQL'
            WHERE content.text LIKE '%a\'b\\\\c\\%d\\_e%'
            SQL, DB_AA::makeWhere([['content.text', '%' . DB_AA::likeLiteral("a'b\\c%d_e") . '%', 'LIKE']]));
    }

    /** BEGIN matches the value itself as a prefix - a backslash, '%' and '_' included */
    public function test_begin_matches_the_value_literally(): void
    {
        $this->assertSame(<<<'SQL'
            WHERE c LIKE 'a\\\\b\\%c\\_%'
            SQL, DB_AA::makeWhere([['c', 'a\b%c_', 'BEGIN']]));
        $this->assertSame(<<<'SQL'
            WHERE (c LIKE 'x\\_%' OR c LIKE 'y\'%')
            SQL, DB_AA::makeWhere([['c', ['x_', "y'"], 'BEGIN']]));
        $this->assertSame(<<<'SQL'
            WHERE (c NOT LIKE 'x\\_%' AND c NOT LIKE 'y\\\\%')
            SQL, DB_AA::makeWhere([['c', ['x_', 'y\\'], 'NOT BEGIN']]));
        $this->assertSame(<<<'SQL'
            WHERE c NOT LIKE '\\_%'
            SQL, DB_AA::makeWhere([['c', '_', 'NOT BEGIN']]));
    }

    /** a trailing backslash must neither escape the closing '%' nor the apostrophe */
    public function test_contain_matches_the_value_literally(): void
    {
        $this->assertSame(<<<'SQL'
            WHERE c LIKE '%a\\\\b\\%c\\_%'
            SQL, DB_AA::makeWhere([['c', 'a\b%c_', 'CONTAIN']]));
        $this->assertSame(<<<'SQL'
            WHERE c LIKE '%x\\\\%'
            SQL, DB_AA::makeWhere([['c', 'x\\', 'CONTAIN']]));
        $this->assertSame(<<<'SQL'
            WHERE c LIKE '%\' OR 1=1 #%'
            SQL, DB_AA::makeWhere([['c', "' OR 1=1 #", 'CONTAIN']]));
    }

    /** for raw SQL inside '...': a LIKE pattern matching the group id itself */
    public function test_constant_group_like_matches_the_id_literally(): void
    {
        $this->assertSame(<<<'SQL'
            a\\\\b\\_c\\%\'
            SQL, constant_group_like("a\\b_c%'"));
    }
}
