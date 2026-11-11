<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Condition;
use AA_Set;
use ItemContent;
use AA_Value;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AA_Condition::matchesValues() (include/searchlib.php) decides over values already loaded as the SQL
 * of AA_Dbcondition decides over the database: any value of any field of the condition; "<>" and ISNULL
 * also for an item without the value (LEFT JOIN); strings compare as the _ci collations do - case and
 * trailing spaces do not count; the empty value of a number field is 0; "=" reads words, "phrases",
 * OR, AND, NOT and parentheses, a syntax error matches everything.
 */
final class ConditionMatchesTest extends TestCase
{
    private const F = 'category.......1';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, array{string, string, list<string>, bool, bool}> operator, value, the field's values, number field, expected */
    public static function cases(): array
    {
        return [
            '= phrase, other case'         => ['=', '"News"', ['news'], false, true],
            '= phrase, trailing space'     => ['=', '"News"', ['News  '], false, true],
            '= phrase, longer value'       => ['=', '"News"', ['Newsy'], false, false],
            '= phrase, accent counts'      => ['=', '"Zpráva"', ['Zprava'], false, false],
            '= no value'                   => ['=', '"News"', [], false, false],
            '= OR, second'                 => ['=', '"News" OR "Events"', ['x', 'events'], false, true],
            '= OR, none'                   => ['=', '"News" OR "Events"', ['x'], false, false],
            '= phrase with special chars'  => ['=', '"Internet & ICT (2-3)"', ['Internet & ICT (2-3)'], false, true],
            '= apostrophes'                => ['=', "'Say \"hi\"'", ['say "hi"'], false, true],
            '= words are AND'              => ['=', 'News Events', ['News'], false, false],
            '= NOT'                        => ['=', 'NOT "News"', ['x'], false, true],
            '= NOT, the value'             => ['=', 'NOT "News"', ['News'], false, false],
            '= NOT, no value'              => ['=', 'NOT "News"', [], false, false],
            '= AND NOT with parentheses'   => ['=', '("A" OR "B") AND NOT "C"', ['b'], false, true],
            '= syntax error takes all'     => ['=', '"A" OR', [], false, true],
            '= empty takes all'            => ['=', '', ['x'], false, true],
            '= number field'               => ['=', '"1"', ['1.0'], true, true],
            '= number field, 0 is empty'   => ['=', '"0"', [''], true, true],
            '== exact, other case'         => ['==', 'News', ['NEWS'], false, true],
            '<> phrase, other case'        => ['<>', '"News"', ['news'], false, false],
            '<> no value'                  => ['<>', '"News"', [], false, true],
            '<> another value'             => ['<>', '"News"', ['x'], false, true],
            '<> one of the values'         => ['<>', '"News"', ['x', 'News'], false, false],
            '<> unquoted'                  => ['<>', 'News', ['News'], false, false],
            '<> keeps inner quotes'        => ['<>', '"a"b"', ['a"b'], false, false],
            'BEGIN other case'             => ['BEGIN', 'Pra', ['praha'], false, true],
            'BEGIN inside'                 => ['BEGIN', 'Pra', ['Opraha'], false, false],
            'BEGIN no value'               => ['BEGIN', 'Pra', [], false, false],
            'BEGIN takes % literally'      => ['BEGIN', '10%', ['10% off'], false, true],
            'CONTAIN other case'           => ['CONTAIN', 'aha', ['PrAHA'], false, true],
            'CONTAIN no match'             => ['CONTAIN', 'aha', ['Brno'], false, false],
            'LLIKE ends with'              => ['LLIKE', 'ha', ['Praha'], false, true],
            'LLIKE not at the end'         => ['LLIKE', 'ha', ['hapra'], false, false],
            'ISNULL no value'              => ['ISNULL', '1', [], false, true],
            'ISNULL empty string'          => ['ISNULL', '1', [''], false, true],
            'ISNULL spaces'                => ['ISNULL', '1', ['  '], false, true],
            'ISNULL one of the values'     => ['ISNULL', '1', ['x', ''], false, true],
            'ISNULL filled'                => ['ISNULL', '1', ['x'], false, false],
            'ISNULL text 0'                => ['ISNULL', '1', ['0'], false, false],
            'ISNULL number 0'              => ['ISNULL', '1', ['0'], true, true],
            'ISNULL number empty'          => ['ISNULL', '1', [''], true, true],
            'NOTNULL no value'             => ['NOTNULL', '1', [], false, false],
            'NOTNULL filled'               => ['NOTNULL', '1', ['x'], false, true],
            'NOTNULL text 0'               => ['NOTNULL', '1', ['0'], false, true],
            'NOTNULL number 0'             => ['NOTNULL', '1', ['0'], true, false],
            'NOTNULL number 5'             => ['NOTNULL', '1', ['5'], true, true],
            'BITSET set'                   => ['BITSET', '4', ['12'], true, true],
            'BITSET not set'               => ['BITSET', '2', ['12'], true, false],
            '& numbers'                    => ['&', '4', ['12'], true, true],
        ];
    }

    /** @param list<string> $values */
    #[DataProvider('cases')]
    public function test_matchesValues(string $operator, string $value, array $values, bool $numeric, bool $expected): void
    {
        $condition = new AA_Condition([self::F], $operator, $value);
        $this->assertSame($expected, $condition->matchesValues(fn(string $fid): array => ($fid === self::F) ? $values : [], $numeric ? [self::F] : []));
    }

    public function test_any_field_of_the_condition(): void
    {
        $condition = new AA_Condition(['a...............', self::F], '=', '"x"');
        $this->assertTrue($condition->matchesValues(fn(string $fid): array => ($fid === self::F) ? ['x'] : []));
        $this->assertFalse($condition->matchesValues(fn(string $fid): array => []));
    }

    public function test_set_matches_item_content(): void
    {
        $content = new ItemContent();
        $content->setAaValue(self::F, new AA_Value(['News', 'Akce']));
        $set = new AA_Set(null, 'd-' . self::F . '-=-"news"-' . self::F . '-<>-"Kurz"');
        $this->assertTrue($set->matches($content));
        $this->assertFalse((new AA_Set(null, 'd-' . self::F . '-ISNULL-1'))->matches($content));
    }

    /** @return array<string, array{string, ?list<string>}> */
    public static function alternatives(): array
    {
        return [
            'phrases'        => ['"News" OR "Events"', ['News', 'Events']],
            'words'          => ['News or Events', ['News', 'Events']],
            'one'            => ['"Internet & ICT"', ['Internet & ICT']],
            'apostrophes'    => ["'Say \"hi\"' OR \"it's\"", ['Say "hi"', "it's"]],
            'implicit AND'   => ['"News" "Events"', null],
            'NOT'            => ['NOT "News"', null],
            'parentheses'    => ['("News")', null],
            'empty'          => ['', null],
            'dangling OR'    => ['"News" OR', null],
        ];
    }

    /** @param ?list<string> $expected */
    #[DataProvider('alternatives')]
    public function test_alternatives(string $expression, ?array $expected): void
    {
        $this->assertSame($expected, AA_Condition::alternatives($expression));
    }

    public function test_orExpression_reads_back(): void
    {
        $values = ['News', 'Say "hi"', "it's", 'A-B', 'x OR y', '(z)', 'C:\\dir'];
        $expr   = AA_Condition::orExpression($values);
        $this->assertSame($values, (new AA_Condition([self::F], '=', $expr))->comparedValues());
        $this->assertNull(AA_Condition::orExpression(['both " and \'']));
        $this->assertNull(AA_Condition::orExpression([]));
    }

    public function test_d_string_round_trip(): void
    {
        $set = new AA_Set();
        $set->addCondition(new AA_Condition([self::F], '=', AA_Condition::orExpression(['A-B', 'C--D', 'E-'])));
        $set->addCondition(new AA_Condition([self::F], '<>', '"-x"'));
        $set->addCondition(new AA_Condition([self::F], 'ISNULL', '1'));
        $set->addCondition(new AA_Condition([self::F], 'BEGIN', '-5'));
        $set->addCondition(new AA_Condition([self::F], '=', AA_Condition::orExpression(['C:\\dir'])));
        $string = $set->getCondsAsString();

        $back = (new AA_Set(null, $string))->getConditions();
        $this->assertCount(5, $back);
        $this->assertSame(['A-B', 'C--D', 'E-'], $back[0]->comparedValues());
        $this->assertSame(['-x'], $back[1]->comparedValues());
        $this->assertSame('ISNULL', $back[2]->getOperator());
        $this->assertSame('-5', $back[3]->getValue(), 'no space before a leading -');
        $this->assertSame(['C:\\dir'], $back[4]->comparedValues());
    }

    public function test_getAsString_empty_value(): void
    {
        $this->assertSame(self::F . '-ISNULL-', (new AA_Condition([self::F], 'ISNULL', ''))->getAsString());
    }
}
