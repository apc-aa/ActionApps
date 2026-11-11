<?php

declare(strict_types=1);

namespace AA\Tests\Unit\Search;

use AA\Search\QueryExpression;
use AA_Dbcondition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value of a search condition read once into a tree: the SQL it gives for a corpus of values is pinned in
 * fixtures/query_sql_pins.php, and deciding over loaded values reads the same tree.
 */
final class QueryExpressionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    private static function pins(): array
    {
        return require __DIR__ . '/fixtures/query_sql_pins.php';
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function syntax(): array
    {
        $ret = [];
        foreach (self::pins()['syntax'] as $text => $by_op) {
            foreach ($by_op as $op => $sql) {
                $ret[json_encode([$text, $op])] = [(string) $text, $op, $sql];
            }
        }
        return $ret;
    }

    #[DataProvider('syntax')]
    public function test_toSql_as_recorded(string $text, string $operator, ?string $sql): void
    {
        $this->assertSame($sql, QueryExpression::tryParse($text)?->toSql('c0.text', $operator));
    }

    /** @return array<string, array{string, string, array{string, string}}> */
    public static function dbcondition(): array
    {
        $ret = [];
        foreach (self::pins()['dbcondition'] as $text => $by_op) {
            foreach ($by_op as $op => $expected) {
                $ret[json_encode([$text, $op])] = [(string) $text, $op, $expected];
            }
        }
        return $ret;
    }

    #[DataProvider('dbcondition')]
    public function test_dbcondition_as_recorded(string $text, string $operator, array $expected): void
    {
        $condition = new AA_Dbcondition('c0', 'text', $operator, $text, 'text', true);
        $this->assertSame($expected, [$condition->getJoinType(), $condition->getWhereExp()]);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function values(): array
    {
        return [
            'word'                  => ['a', 'a', true],
            'other word'            => ['a', 'b', false],
            'OR'                    => ['a OR b', 'b', true],
            'implicit AND'          => ['a b', 'a', false],
            'NOT'                   => ['-a', 'b', true],
            'NOT the value'         => ['NOT a', 'a', false],
            'parentheses'           => ['(a OR b) AND NOT c', 'b', true],
            'AND binds before OR'   => ['a OR b AND c', 'a', true],
            'phrase'                => ['"a b"', 'a b', true],
        ];
    }

    #[DataProvider('values')]
    public function test_matches(string $text, string $value, bool $expected): void
    {
        $this->assertSame($expected, QueryExpression::parse($text)->matches(static fn(string $word): bool => $word === $value));
    }

    /** @return array<string, array{string, ?list<string>}> */
    public static function alternatives(): array
    {
        return [
            'phrases'      => ['"News" OR "Events"', ['News', 'Events']],
            'words'        => ['News or Events', ['News', 'Events']],
            'one'          => ['"Internet & ICT"', ['Internet & ICT']],
            'apostrophes'  => ["'Say \"hi\"' OR \"it's\"", ['Say "hi"', "it's"]],
            'implicit AND' => ['"News" "Events"', null],
            'NOT'          => ['NOT "News"', null],
            'parentheses'  => ['("News")', null],
            'OR of a group' => ['"A" OR ("B")', null],
        ];
    }

    /** @param ?list<string> $expected */
    #[DataProvider('alternatives')]
    public function test_alternatives(string $text, ?array $expected): void
    {
        $this->assertSame($expected, QueryExpression::parse($text)->alternatives());
    }

    /** @return array<string, array{string, string, string, bool}> value, word, operator, expected */
    public static function likes(): array
    {
        return [
            'LIKE anywhere, other case' => ['Praha 5', 'AHA', 'LIKE', true],
            'RLIKE at the start'        => ['Praha', 'pra', 'RLIKE', true],
            'RLIKE not at the start'    => ['Opraha', 'pra', 'RLIKE', false],
            'LLIKE at the end'          => ['Praha', 'ha', 'LLIKE', true],
            'XLIKE whole'               => ['Praha', 'praha', 'XLIKE', true],
            'XLIKE part'                => ['Praha', 'pra', 'XLIKE', false],
            '% wildcard'                => ['Praha', 'p%a', 'XLIKE', true],
            '_ wildcard'                => ['Praha', 'pr_ha', 'XLIKE', true],
            'escaped %'                 => ['10% off', '10\\%', 'RLIKE', true],
            'escaped % is plain'        => ['100 off', '10\\%', 'RLIKE', false],
            'regex characters plain'    => ['a.b', 'a.b', 'XLIKE', true],
            'regex dot no wildcard'     => ['axb', 'a.b', 'XLIKE', false],
            'multibyte'                 => ['Žluťoučký', 'ŽLUŤ', 'RLIKE', true],
            'final newline counts'      => ["the end\n", 'end', 'LLIKE', false],
            'final newline, XLIKE'      => ["abc\n", 'abc', 'XLIKE', false],
        ];
    }

    #[DataProvider('likes')]
    public function test_likeMatches(string $value, string $word, string $operator, bool $expected): void
    {
        $this->assertSame($expected, QueryExpression::likeMatches($value, $word, $operator));
    }

    public function test_syntax_errors(): void
    {
        foreach (['', '"News" OR', '(a', 'a ) b', 'NOT NOT a', '"unclosed'] as $text) {
            $this->assertNull(QueryExpression::tryParse($text), $text);
        }
    }

    public function test_ofAlternatives_reads_back(): void
    {
        $values = ['News', 'Say "hi"', "it's", 'A-B', 'x OR y', '(z)'];
        $this->assertSame($values, QueryExpression::parse(QueryExpression::ofAlternatives($values))->alternatives());
        $this->assertSame('"C:\\\\dir"', QueryExpression::ofAlternatives(['C:\\dir']));
        $this->assertNull(QueryExpression::ofAlternatives(['both " and \'']));
        $this->assertNull(QueryExpression::ofAlternatives([]));
    }
}
