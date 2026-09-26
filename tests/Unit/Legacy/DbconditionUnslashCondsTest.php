<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Dbcondition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AA_Dbcondition::unslashLiteralConds() - slice.php, discussion.php and admin/getxml.php import
 * the request addslashed (register_globals emulation), so a conds[] value arrives as O\'Brien.
 * BEGIN and CONTAIN match their text literally, so the value must be unslashed before it gets
 * there; LIKE and the other operators unslash in AA_Dbcondition themselves and stay as they are.
 * A value imported by add_vars() from the SSI query string is not slashed and must survive.
 */
final class DbconditionUnslashCondsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/searchlib.php';
    }

    /** @return array<string, array{0:string, 1:string, 2:string}> operator, value as it arrives, expected */
    public static function condProvider(): array
    {
        return [
            'CONTAIN apostrophe'         => ['CONTAIN', "O\\'Brien", "O'Brien"],
            'BEGIN double quotes'        => ['BEGIN', 'say \\"hi\\"', 'say "hi"'],
            'CONTAIN backslash'          => ['CONTAIN', 'C:\\\\temp', 'C:\\temp'],
            'operator with spaces'       => [' CONTAIN ', "O\\'Brien", "O'Brien"],
            'operator with colon'        => ['CONTAIN:', "O\\'Brien", "O'Brien"],
            'plain text'                 => ['CONTAIN', 'honza', 'honza'],
            'unslashed from add_vars'    => ['CONTAIN', "O'Brien", "O'Brien"],
            'unslashed backslash'        => ['BEGIN', 'a\\b', 'a\\b'],
            'LIKE unslashes by itself'   => ['LIKE', "O\\'Brien", "O\\'Brien"],
            'RLIKE unslashes by itself'  => ['RLIKE', "O\\'Brien", "O\\'Brien"],
            '= unslashes by itself'      => ['=', "O\\'Brien", "O\\'Brien"],
            '== is not touched'          => ['==', "O\\'Brien", "O\\'Brien"],
        ];
    }

    #[DataProvider('condProvider')]
    public function test_only_begin_and_contain_values_are_unslashed(string $operator, string $value, string $expected): void
    {
        $conds = AA_Dbcondition::unslashLiteralConds([['headline........' => 1, 'operator' => $operator, 'value' => $value]]);

        $this->assertSame($expected, $conds[0]['value']);
        $this->assertSame($operator, $conds[0]['operator']);
        $this->assertSame(1, $conds[0]['headline........']);
    }

    public function test_slice_php_contain_cond_matches_the_typed_text(): void
    {
        // slice.php?conds[0][headline........]=O'Brien&conds[0][operator]=CONTAIN
        $conds = AA_Dbcondition::unslashLiteralConds([['headline........' => 1, 'operator' => 'CONTAIN', 'value' => addslashes("O'Brien")]]);

        $this->assertStringEndsWith(<<<'SQL'
             LIKE '%O\'Brien%')
            SQL, (new AA_Dbcondition('c0', 'text', $conds[0]['operator'], $conds[0]['value']))->getWhereExp());
    }

    public function test_conds_without_a_string_value_are_kept(): void
    {
        $conds = [
            'bad'   => 'conds[x]=y',
            'noval' => ['headline........' => 1, 'operator' => 'CONTAIN'],
            'noop'  => ['headline........' => "O\\'Brien"],
            'oparr' => ['headline........' => 1, 'operator' => ['CONTAIN'], 'value' => "O\\'Brien"],
            'multi' => ['headline........' => 1, 'operator' => 'CONTAIN', 'value' => ["O\\'Brien"]],
        ];

        $this->assertSame($conds, AA_Dbcondition::unslashLiteralConds($conds));
    }

    public function test_the_keys_and_the_other_conds_are_kept(): void
    {
        $conds = [
            5 => ['headline........' => 1, 'operator' => 'LIKE', 'value' => "O\\'Brien"],
            7 => ['abstract........' => 1, 'operator' => 'BEGIN', 'value' => "O\\'Brien"],
        ];

        $this->assertSame([
            5 => ['headline........' => 1, 'operator' => 'LIKE', 'value' => "O\\'Brien"],
            7 => ['abstract........' => 1, 'operator' => 'BEGIN', 'value' => "O'Brien"],
        ], AA_Dbcondition::unslashLiteralConds($conds));
    }
}
