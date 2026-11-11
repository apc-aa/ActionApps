<?php

declare(strict_types=1);

namespace AA\Tests\Unit\Form;

use AA\Form\ShowConditions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShowConditionsTest extends TestCase
{
    private const CAT   = 'category........';
    private const START = 'start_date......';
    private const PLACE = 'place...........';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';      // the constants AA_Condition (searchlib.php) needs
        require_once __DIR__ . '/../../../include/mgettext.php';   // _m() of the error texts
    }

    /** @return array<string, array{string, array, bool}> d- conds, values of the controlling fields, shown */
    public static function rules(): array
    {
        $cat = 'd-' . self::CAT;
        return [
            'is, one matches'         => [$cat . '-=-"Akce" OR "Kurz"', [self::CAT => ['akce']], true],
            'is, second value'        => [$cat . '-=-"Akce" OR "Kurz"', [self::CAT => ['Novinka', 'Kurz']], true],
            'is, no match'            => [$cat . '-=-"Akce"', [self::CAT => ['Novinka']], false],
            'is, no value'            => [$cat . '-=-"Akce"', [], false],
            'is not, none'            => [$cat . '-<>-"Akce"-' . self::CAT . '-<>-"Kurz"', [self::CAT => ['Novinka']], true],
            'is not, one matches'     => [$cat . '-<>-"Akce"-' . self::CAT . '-<>-"Kurz"', [self::CAT => ['x', 'kurz']], false],
            'is not, no value'        => [$cat . '-<>-"Akce"', [], true],
            'begins with'             => [$cat . '-BEGIN-ak', [self::CAT => ['Akce']], true],
            'contains'                => [$cat . '-CONTAIN-kc', [self::CAT => ['Akce']], true],
            'is filled'               => [$cat . '-NOTNULL-1', [self::CAT => ['0']], true],
            'is empty'                => [$cat . '-ISNULL-1', [self::CAT => ['']], true],
            'is empty, no value'      => [$cat . '-ISNULL-1', [], true],
            'both rules hold'         => [$cat . '-NOTNULL-1-' . self::PLACE . '-=-"Praha"', [self::CAT => ['x'], self::PLACE => ['praha']], true],
            'second rule fails'       => [$cat . '-NOTNULL-1-' . self::PLACE . '-=-"Praha"', [self::CAT => ['x'], self::PLACE => ['Brno']], false],
        ];
    }

    #[DataProvider('rules')]
    public function test_rules(string $conds, array $values, bool $shown): void
    {
        $assigned = [self::CAT => '', self::PLACE => '', self::START => 'aaaaaaaa'];
        $hidden   = self::conds(['aaaaaaaa' => ['conds' => $conds]])->hidden($assigned, self::valuesOf($values), self::noExpand());
        $this->assertSame($shown ? [] : [self::START => ShowConditions::LIVE], $hidden);
    }

    public function test_numeric_field_zero_is_empty(): void
    {
        $conds    = self::conds(['aaaaaaaa' => ['conds' => 'd-' . self::CAT . '-NOTNULL-1']]);
        $assigned = [self::CAT => '', self::START => 'aaaaaaaa'];
        $this->assertSame([], $conds->hidden($assigned, self::valuesOf([self::CAT => ['0']]), self::noExpand()));
        $this->assertSame([self::START => ShowConditions::LIVE], $conds->hidden($assigned, self::valuesOf([self::CAT => ['0']]), self::noExpand(), [self::CAT]));
    }

    /** @return array<string, array{?string}> */
    public static function garbage(): array
    {
        return [
            'null'          => [null],
            'empty'         => [''],
            'broken'        => ['{'],
            'list'          => ['[]'],
            'showif string' => ['{"showif":"x"}'],
            'scalar'        => ['5'],
        ];
    }

    #[DataProvider('garbage')]
    public function test_fromJson_garbage_is_empty(?string $json): void
    {
        $this->assertSame([], ShowConditions::fromJson($json)->all());
    }

    public function test_fromJson_normalizes(): void
    {
        $cat  = self::CAT;
        $json = json_encode(['showif' => [
            'aaaaaaaa' => ['name' => '', 'expr' => '1'],
            'nothex!!' => ['name' => 'Bad key', 'expr' => '1'],
            'bbbbbbbb' => ['expr' => '1'],
            'cccccccc' => ['name' => 'Kept', 'expr' => '', 'conds' => "d-$cat-LIKE-a-short-=-a-$cat,place...........-=-a-$cat-=-NOT \"a\"-$cat-=-\"1\" OR Akce-start_date......-NOTNULL-x"],
            'dddddddd' => ['name' => 'No clear', 'expr' => 'x', 'clear' => false, 'conds' => ['not a string']],
            'eeeeeeee' => ['name' => 'Not d-', 'expr' => 'x', 'conds' => "conds[0][$cat]=1"],
        ]]);
        $conds = ShowConditions::fromJson($json);

        $this->assertSame(['cccccccc', 'dddddddd', 'eeeeeeee'], array_keys($conds->all()));
        $this->assertSame([
            'name'  => 'Kept',
            'conds' => "d-$cat-=-\"1\" OR Akce-start_date......-NOTNULL-x",
            'expr'  => '',
            'clear' => true,
        ], $conds->get('cccccccc'));
        $this->assertSame([
            ['field' => $cat, 'op' => '=', 'values' => ['1', 'Akce']],
            ['field' => self::START, 'op' => 'NOTNULL', 'values' => []],
        ], $conds->rows('cccccccc'));
        $this->assertFalse($conds->get('dddddddd')['clear']);
        $this->assertSame('', $conds->get('dddddddd')['conds']);
        $this->assertSame('', $conds->get('eeeeeeee')['conds']);
        $this->assertNull($conds->get('aaaaaaaa'));
    }

    public function test_rows_join_is_not_of_one_field(): void
    {
        $cat   = self::CAT;
        $conds = self::conds(['aaaaaaaa' => ['conds' => "d-$cat-<>-\"A\"-$cat-<>-\"B\"-place...........-<>-\"C\"-$cat-<>-\"D\""]]);
        $this->assertSame([
            ['field' => $cat, 'op' => '<>', 'values' => ['A', 'B']],
            ['field' => self::PLACE, 'op' => '<>', 'values' => ['C']],
            ['field' => $cat, 'op' => '<>', 'values' => ['D']],
        ], $conds->rows('aaaaaaaa'));
    }

    public function test_all_is_sorted_by_name(): void
    {
        $conds = ShowConditions::fromJson('');
        $conds->set('aaaaaaaa', ['name' => 'Zeta', 'expr' => '1']);
        $conds->set('bbbbbbbb', ['name' => 'alfa', 'expr' => '1']);
        $this->assertSame(['bbbbbbbb', 'aaaaaaaa'], array_keys($conds->all()));
    }

    public function test_numeric_key_stays_string(): void
    {
        $conds = ShowConditions::fromJson('{"showif":{"12345678":{"name":"N","expr":"1"}}}');
        $this->assertSame(['12345678'], array_map('strval', array_keys($conds->all())));
        $this->assertNotNull($conds->get('12345678'));
        $this->assertSame([], $conds->hidden(['x...............' => '12345678'], fn() => [], fn() => '1'));
    }

    public function test_newKey_is_8_hex(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', ShowConditions::newKey());
    }

    public function test_toJson_keeps_foreign_keys(): void
    {
        $cond  = ['name' => 'Akce', 'conds' => 'd-' . self::CAT . '-=-"Akce"', 'expr' => '', 'clear' => true];
        $conds = ShowConditions::fromJson('');
        $conds->set('3fa94c1e', $cond);

        $json = $conds->toJson('{"other":1}');
        $data = json_decode($json, true);
        $this->assertSame(1, $data['other']);
        $this->assertSame($cond, $data['showif']['3fa94c1e']);

        $this->assertSame($conds->all(), ShowConditions::fromJson($conds->toJson(''))->all());

        $conds->remove('3fa94c1e');
        $this->assertSame('{"other":1}', $conds->toJson($json));
        $this->assertSame('', $conds->toJson(''));
        $this->assertSame('', $conds->toJson('{broken'));
    }

    public function test_fieldJson_and_keyOfField(): void
    {
        $json = ShowConditions::fieldJson('', '3fa94c1e');
        $this->assertSame('3fa94c1e', ShowConditions::keyOfField($json));
        $this->assertSame('', ShowConditions::fieldJson($json, ''));

        $other = ShowConditions::fieldJson('{"x":2}', '3fa94c1e');
        $this->assertSame(['x' => 2, 'showif' => '3fa94c1e'], json_decode($other, true));
        $this->assertSame('{"x":2}', ShowConditions::fieldJson($other, ''));

        $this->assertSame('', ShowConditions::keyOfField('nesmysl'));
        $this->assertSame('', ShowConditions::keyOfField('{"showif":"xyz"}'));
        $this->assertSame('', ShowConditions::keyOfField('{"showif":["3fa94c1e"]}'));
        $this->assertSame('', ShowConditions::keyOfField(null));
        $this->assertSame('', ShowConditions::keyOfField("{\"showif\":\"3fa94c1e\\n\"}"), 'a trailing newline is no key');
    }

    private static function conds(array $list): ShowConditions
    {
        $conds = ShowConditions::fromJson('');
        foreach ($list as $key => $c) {
            $conds->set($key, $c + ['name' => $key, 'conds' => '', 'expr' => '', 'clear' => true]);
        }
        return $conds;
    }

    private static function rule(string $field, string $op, array $values = []): string
    {
        $value = match ($op) {
            '='     => \AA_Condition::orExpression($values),
            '<>'    => '"' . $values[0] . '"',
            default => '1',
        };
        return (new \AA_Condition([$field], $op, $value))->getAsString();
    }

    private static function rules2(string ...$rules): string
    {
        return 'd-' . implode('-', $rules);
    }

    private static function valuesOf(array $values): \Closure
    {
        return fn(string $fid): array => $values[$fid] ?? [];
    }

    private static function noExpand(): \Closure
    {
        return function (string $expr): string {
            throw new \LogicException("unexpected expansion of '$expr'");
        };
    }

    public function test_hidden_field_without_condition_is_absent(): void
    {
        $conds = self::conds(['aaaaaaaa' => ['conds' => self::rules2(self::rule(self::CAT, '=', ['Akce']))]]);
        $this->assertSame([], $conds->hidden([self::CAT => '', self::START => ''], self::valuesOf([]), self::noExpand()));
    }

    public function test_hidden_unknown_key_shows_field(): void
    {
        $conds = self::conds(['aaaaaaaa' => ['conds' => self::rules2(self::rule(self::CAT, '=', ['Akce']))]]);
        $this->assertSame([], $conds->hidden([self::CAT => '', self::START => 'ffffffff'], self::valuesOf([]), self::noExpand()));
    }

    public function test_hidden_rule_fails_is_live(): void
    {
        $conds    = self::conds(['aaaaaaaa' => ['conds' => self::rules2(self::rule(self::CAT, '=', ['Akce']))]]);
        $assigned = [self::CAT => '', self::START => 'aaaaaaaa'];
        $this->assertSame([self::START => ShowConditions::LIVE], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Novinka']]), self::noExpand()));
        $this->assertSame([], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Akce']]), self::noExpand()));
    }

    public function test_hidden_all_rules_must_hold(): void
    {
        $conds = self::conds(['aaaaaaaa' => ['conds' => self::rules2(
            self::rule(self::CAT, '=', ['Akce']),
            self::rule(self::PLACE, 'NOTNULL'),
        )]]);
        $assigned = [self::CAT => '', self::PLACE => '', self::START => 'aaaaaaaa'];
        $this->assertSame([self::START => ShowConditions::LIVE], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Akce'], self::PLACE => ['']]), self::noExpand()));
        $this->assertSame([], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Akce'], self::PLACE => ['Praha']]), self::noExpand()));
    }

    public function test_hidden_rule_on_missing_field_passes(): void
    {
        $conds = self::conds(['aaaaaaaa' => ['conds' => self::rules2(self::rule(self::CAT, '=', ['Akce']))]]);
        $this->assertSame([], $conds->hidden([self::START => 'aaaaaaaa'], self::valuesOf([]), self::noExpand()));
    }

    public function test_hidden_expression(): void
    {
        $conds    = self::conds(['aaaaaaaa' => ['expr' => '{xuser:_#IS_ADMIN}']]);
        $assigned = [self::START => 'aaaaaaaa'];
        foreach (['', ' ', '0', " 0\n"] as $result) {
            $this->assertSame([self::START => ShowConditions::STATIC], $conds->hidden($assigned, self::valuesOf([]), fn() => $result), "result '$result'");
        }
        foreach (['1', 'x'] as $result) {
            $this->assertSame([], $conds->hidden($assigned, self::valuesOf([]), fn() => $result), "result '$result'");
        }
    }

    public function test_hidden_static_wins_over_live(): void
    {
        $conds    = self::conds(['aaaaaaaa' => ['conds' => self::rules2(self::rule(self::CAT, '=', ['Akce'])), 'expr' => 'x']]);
        $assigned = [self::CAT => '', self::START => 'aaaaaaaa'];
        $this->assertSame([self::START => ShowConditions::STATIC], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Novinka']]), fn() => '0'));
        $this->assertSame([self::START => ShowConditions::LIVE], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Novinka']]), fn() => '1'));
    }

    public function test_hidden_chain(): void
    {
        // A = start (rule on category), B = place (rule on start, which holds)
        $conds = self::conds([
            'aaaaaaaa' => ['conds' => self::rules2(self::rule(self::CAT, '=', ['Akce']))],
            'bbbbbbbb' => ['conds' => self::rules2(self::rule(self::START, 'NOTNULL'))],
            'cccccccc' => ['conds' => self::rules2(self::rule(self::CAT, '=', ['Akce'])), 'expr' => 'x'],
        ]);
        $values = self::valuesOf([self::CAT => ['Novinka'], self::START => ['2026']]);

        $live = $conds->hidden([self::CAT => '', self::START => 'aaaaaaaa', self::PLACE => 'bbbbbbbb'], $values, self::noExpand());
        $this->assertSame([self::START => ShowConditions::LIVE, self::PLACE => ShowConditions::LIVE], $live);

        $static = $conds->hidden([self::CAT => '', self::START => 'cccccccc', self::PLACE => 'bbbbbbbb'], $values, fn() => '0');
        $this->assertSame([self::START => ShowConditions::STATIC, self::PLACE => ShowConditions::STATIC], $static);
    }

    public function test_hidden_cycle_terminates(): void
    {
        $conds = self::conds([
            'aaaaaaaa' => ['conds' => self::rules2(self::rule(self::PLACE, 'NOTNULL'))],
            'bbbbbbbb' => ['conds' => self::rules2(self::rule(self::START, 'NOTNULL'))],
        ]);
        $assigned = [self::START => 'aaaaaaaa', self::PLACE => 'bbbbbbbb'];

        $this->assertSame([], $conds->hidden($assigned, self::valuesOf([self::START => ['x'], self::PLACE => ['y']]), self::noExpand()));
        $hidden = $conds->hidden($assigned, self::valuesOf([self::START => ['x']]), self::noExpand());
        $this->assertSame(ShowConditions::LIVE, $hidden[self::START] ?? null);
    }

    private const NAMES = [self::CAT => 'Typ', self::START => 'Začátek', self::PLACE => 'Místo'];

    /** a slice with the condition "Kurz" under key bbbbbbbb */
    private static function withKurz(): ShowConditions
    {
        return self::conds(['bbbbbbbb' => ['name' => 'Kurz', 'expr' => '1']]);
    }

    public function test_fromPost_valid(): void
    {
        [$cond, $errors] = self::withKurz()->fromPost([
            'name'  => ' Akce ',
            'rule'  => [
                ['field' => self::CAT, 'op' => '=', 'values' => ['Akce', 'Kurz'], 'text' => "Kurz\n  Seminář \n\n"],
                ['field' => '', 'op' => '=', 'text' => 'ignored'],
                ['field' => self::PLACE, 'op' => 'NOTNULL', 'values' => ['dropped'], 'text' => 'dropped'],
                ['field' => self::START, 'op' => '<>', 'values' => ['A-B', '"x"']],
                ['field' => self::PLACE, 'op' => 'BEGIN', 'text' => "Pra\nBrno"],
            ],
            'expr'  => '{xuser:_#IS_ADMIN}',
            'clear' => '1',
        ], self::NAMES, '');

        $this->assertSame([], $errors);
        $this->assertSame([
            'name'  => 'Akce',
            'conds' => 'd-' . self::CAT . '-=-"Akce" OR "Kurz" OR "Seminář"-' . self::PLACE . '-NOTNULL-1-' . self::START . '-<>-"A--B"-' . self::START . '-<>-""x""-' . self::PLACE . '-BEGIN-Pra',
            'expr'  => '{xuser:_#IS_ADMIN}',
            'clear' => true,
        ], $cond);

        // what the editor shows again
        $conds = self::withKurz();
        $conds->set('cccccccc', $cond);
        $this->assertSame([
            ['field' => self::CAT, 'op' => '=', 'values' => ['Akce', 'Kurz', 'Seminář']],
            ['field' => self::PLACE, 'op' => 'NOTNULL', 'values' => []],
            ['field' => self::START, 'op' => '<>', 'values' => ['A-B', '"x"']],
            ['field' => self::PLACE, 'op' => 'BEGIN', 'values' => ['Pra']],
        ], $conds->rows('cccccccc'));
    }

    public function test_fromPost_unchecked_clear_is_false(): void
    {
        [$cond] = self::withKurz()->fromPost(['name' => 'Akce', 'expr' => '1'], self::NAMES, '');
        $this->assertFalse($cond['clear']);
    }

    public function test_fromPost_own_name_on_update_is_fine(): void
    {
        [$cond, $errors] = self::withKurz()->fromPost(['name' => 'Kurz', 'expr' => '1'], self::NAMES, 'bbbbbbbb');
        $this->assertSame([], $errors);
        $this->assertSame('Kurz', $cond['name']);
    }

    /** @return array<string, array{array}> */
    public static function badPosts(): array
    {
        return [
            'empty name'              => [['name' => ' ', 'expr' => '1']],
            'name of other condition' => [['name' => 'kurz', 'expr' => '1']],
            'no rule, no expression'  => [['name' => 'Akce', 'expr' => '  ', 'rule' => [['field' => '']]]],
            'field not in slice'      => [['name' => 'Akce', 'rule' => [['field' => 'other...........', 'op' => 'NOTNULL']]]],
            'unknown op'              => [['name' => 'Akce', 'rule' => [['field' => self::CAT, 'op' => 'LIKE', 'text' => 'x']]]],
            'is without values'       => [['name' => 'Akce', 'rule' => [['field' => self::CAT, 'op' => '=', 'text' => " \n"]]]],
            'is not without values'   => [['name' => 'Akce', 'rule' => [['field' => self::CAT, 'op' => '<>']]]],
            'contains without value'  => [['name' => 'Akce', 'rule' => [['field' => self::CAT, 'op' => 'CONTAIN', 'text' => '']]]],
            'both kinds of quotes'    => [['name' => 'Akce', 'rule' => [['field' => self::CAT, 'op' => '=', 'values' => ['a"b\'c']]]]],
            'name not a string'       => [['name' => ['Akce'], 'expr' => '1']],
            'rules not an array'      => [['name' => 'Akce', 'rule' => 'x']],
        ];
    }

    #[DataProvider('badPosts')]
    public function test_fromPost_errors(array $post): void
    {
        [$cond, $errors] = self::withKurz()->fromPost($post, self::NAMES, '');
        $this->assertNotEmpty($errors);
        $this->assertNull($cond);
    }
}
