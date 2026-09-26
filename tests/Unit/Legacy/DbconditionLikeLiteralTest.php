<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\IO\DB\DB_AA;
use AA_Dbcondition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The BEGIN and CONTAIN operators of AA_Dbcondition (include/searchlib.php) - the searchbar,
 * {ids:...} conditions, AA_Object::querySet(). They are documented to treat every character
 * literally, as the postfilter AA_Condition::_compare() does with str_contains(), so the SQL
 * must match backslashes, % and _ literally too - with the same pattern DB_AA::makeWhere() writes.
 */
final class DbconditionLikeLiteralTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/searchlib.php';
    }

    /** @return array<string, array{0:string}> */
    public static function textProvider(): array
    {
        return [
            'plain'            => ['honza'],
            'backslash'        => ['C:\\temp\\new'],
            'trailing slash'   => ['end\\'],
            'wildcards'        => ['50%_off'],
            'escaped wildcard' => ['\\%'],
            'apostrophe'       => ["O'Brien"],
            'quotes'           => ['say "hi"'],
        ];
    }

    #[DataProvider('textProvider')]
    public function test_contain_matches_the_text_literally_as_makeWhere_does(string $text): void
    {
        $this->assertSame(
            '(' . DB_AA::quoteIdentifier('c0.text') . ' LIKE ' . qquote('%' . DB_AA::likeLiteral($text) . '%') . ')',
            (new AA_Dbcondition('c0', 'text', 'CONTAIN', $text))->getWhereExp(),
        );
        // the same pattern as makeWhere(); the column is quoted differently there
        $this->assertSame(
            strstr(DB_AA::makeWhere([['c0.text', $text, 'CONTAIN']]), ' LIKE ') . ')',
            strstr((new AA_Dbcondition('c0', 'text', 'CONTAIN', $text))->getWhereExp(), ' LIKE '),
        );
    }

    #[DataProvider('textProvider')]
    public function test_begin_matches_the_text_literally_as_makeWhere_does(string $text): void
    {
        $this->assertSame(
            '(' . DB_AA::quoteIdentifier('c0.text') . ' LIKE ' . qquote(DB_AA::likeLiteral($text) . '%') . ')',
            (new AA_Dbcondition('c0', 'text', 'BEGIN', $text))->getWhereExp(),
        );
        $this->assertSame(
            strstr(DB_AA::makeWhere([['c0.text', $text, 'BEGIN']]), ' LIKE ') . ')',
            strstr((new AA_Dbcondition('c0', 'text', 'BEGIN', $text))->getWhereExp(), ' LIKE '),
        );
    }

    public function test_backslash_is_doubled_for_like_and_again_for_the_string_literal(): void
    {
        // SQL '%a\\\\b%' is the string %a\\b%, the LIKE pattern for a literal a\b
        $this->assertStringEndsWith(<<<'SQL'
             LIKE '%a\\\\b%')
            SQL, (new AA_Dbcondition('c0', 'text', 'CONTAIN', 'a\\b'))->getWhereExp());

        $this->assertStringEndsWith(<<<'SQL'
             LIKE 'C:\\\\temp\\\\new%')
            SQL, (new AA_Dbcondition('c0', 'text', 'BEGIN', 'C:\\temp\\new'))->getWhereExp());

        $this->assertStringEndsWith(<<<'SQL'
             LIKE '%end\\\\%')
            SQL, (new AA_Dbcondition('c0', 'text', 'CONTAIN', 'end\\'))->getWhereExp());

        $this->assertStringEndsWith(<<<'SQL'
             LIKE '50\\%\\_\'%')
            SQL, (new AA_Dbcondition('c0', 'text', 'BEGIN', "50%_'"))->getWhereExp());
    }
}
