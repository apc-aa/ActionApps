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

    /** @return array<string, array{array, array, bool}> */
    public static function rules(): array
    {
        return [
            'in, one matches'          => [['op' => 'in', 'values' => ['Akce', 'Kurz']], ['Akce'], true],
            'in, second value matches' => [['op' => 'in', 'values' => ['Akce', 'Kurz']], ['Novinka', 'Kurz'], true],
            'in, no match'             => [['op' => 'in', 'values' => ['Akce']], ['Novinka'], false],
            'in, no value'             => [['op' => 'in', 'values' => ['Akce']], [], false],
            'in, int value'            => [['op' => 'in', 'values' => ['1']], [1], true],
            'notin, no match'          => [['op' => 'notin', 'values' => ['Akce']], ['Novinka'], true],
            'notin, no value'          => [['op' => 'notin', 'values' => ['Akce']], [], true],
            'notin, one matches'       => [['op' => 'notin', 'values' => ['Akce']], ['Akce', 'x'], false],
            'filled, on'               => [['op' => 'filled', 'values' => []], ['on'], true],
            'filled, 1'                => [['op' => 'filled', 'values' => []], ['1'], true],
            'filled, empty and x'      => [['op' => 'filled', 'values' => []], ['', 'x'], true],
            'filled, 0'                => [['op' => 'filled', 'values' => []], ['0'], false],
            'filled, empty string'     => [['op' => 'filled', 'values' => []], [''], false],
            'filled, no value'         => [['op' => 'filled', 'values' => []], [], false],
            'empty, on'                => [['op' => 'empty', 'values' => []], ['on'], false],
            'empty, 1'                 => [['op' => 'empty', 'values' => []], ['1'], false],
            'empty, empty and x'       => [['op' => 'empty', 'values' => []], ['', 'x'], false],
            'empty, 0'                 => [['op' => 'empty', 'values' => []], ['0'], true],
            'empty, empty string'      => [['op' => 'empty', 'values' => []], [''], true],
            'empty, no value'          => [['op' => 'empty', 'values' => []], [], true],
        ];
    }

    #[DataProvider('rules')]
    public function test_ruleMatches(array $rule, array $values, bool $expected): void
    {
        $this->assertSame($expected, ShowConditions::ruleMatches($rule + ['field' => self::CAT], $values));
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
        $json = json_encode(['showif' => [
            'aaaaaaaa' => ['name' => '', 'all' => [], 'expr' => '1'],
            'nothex!!' => ['name' => 'Bad key', 'all' => [], 'expr' => '1'],
            'bbbbbbbb' => ['all' => [], 'expr' => '1'],
            'cccccccc' => ['name' => 'Kept', 'expr' => '', 'all' => [
                ['field' => self::CAT, 'op' => 'like', 'values' => ['a']],
                ['field' => 'short', 'op' => 'in', 'values' => ['a']],
                ['field' => self::CAT, 'op' => 'in', 'values' => []],
                ['field' => self::CAT, 'op' => 'notin'],
                ['field' => self::CAT, 'op' => 'in', 'values' => [1, 'Akce']],
                ['field' => self::START, 'op' => 'filled', 'values' => ['ignored']],
                'not an array',
            ]],
            'dddddddd' => ['name' => 'No clear', 'all' => [], 'expr' => 'x', 'clear' => false],
        ]]);
        $conds = ShowConditions::fromJson($json);

        $this->assertSame(['cccccccc', 'dddddddd'], array_keys($conds->all()));
        $this->assertSame([
            'name'  => 'Kept',
            'all'   => [
                ['field' => self::CAT, 'op' => 'in', 'values' => ['1', 'Akce']],
                ['field' => self::START, 'op' => 'filled', 'values' => []],
            ],
            'expr'  => '',
            'clear' => true,
        ], $conds->get('cccccccc'));
        $this->assertFalse($conds->get('dddddddd')['clear']);
        $this->assertNull($conds->get('aaaaaaaa'));
    }

    public function test_all_is_sorted_by_name(): void
    {
        $conds = ShowConditions::fromJson('');
        $conds->set('aaaaaaaa', ['name' => 'Zeta', 'all' => [], 'expr' => '1', 'clear' => true]);
        $conds->set('bbbbbbbb', ['name' => 'alfa', 'all' => [], 'expr' => '1', 'clear' => true]);
        $this->assertSame(['bbbbbbbb', 'aaaaaaaa'], array_keys($conds->all()));
    }

    public function test_numeric_key_stays_string(): void
    {
        $conds = ShowConditions::fromJson('{"showif":{"12345678":{"name":"N","all":[],"expr":"1"}}}');
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
        $cond  = ['name' => 'Akce', 'all' => [['field' => self::CAT, 'op' => 'in', 'values' => ['Akce']]], 'expr' => '', 'clear' => true];
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
    }

    private static function conds(array $list): ShowConditions
    {
        $conds = ShowConditions::fromJson('');
        foreach ($list as $key => $c) {
            $conds->set($key, $c + ['name' => $key, 'all' => [], 'expr' => '', 'clear' => true]);
        }
        return $conds;
    }

    private static function rule(string $field, string $op, array $values = []): array
    {
        return ['field' => $field, 'op' => $op, 'values' => $values];
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
        $conds = self::conds(['aaaaaaaa' => ['all' => [self::rule(self::CAT, 'in', ['Akce'])]]]);
        $this->assertSame([], $conds->hidden([self::CAT => '', self::START => ''], self::valuesOf([]), self::noExpand()));
    }

    public function test_hidden_unknown_key_shows_field(): void
    {
        $conds = self::conds(['aaaaaaaa' => ['all' => [self::rule(self::CAT, 'in', ['Akce'])]]]);
        $this->assertSame([], $conds->hidden([self::CAT => '', self::START => 'ffffffff'], self::valuesOf([]), self::noExpand()));
    }

    public function test_hidden_rule_fails_is_live(): void
    {
        $conds    = self::conds(['aaaaaaaa' => ['all' => [self::rule(self::CAT, 'in', ['Akce'])]]]);
        $assigned = [self::CAT => '', self::START => 'aaaaaaaa'];
        $this->assertSame([self::START => ShowConditions::LIVE], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Novinka']]), self::noExpand()));
        $this->assertSame([], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Akce']]), self::noExpand()));
    }

    public function test_hidden_all_rules_must_hold(): void
    {
        $conds = self::conds(['aaaaaaaa' => ['all' => [
            self::rule(self::CAT, 'in', ['Akce']),
            self::rule(self::PLACE, 'filled'),
        ]]]);
        $assigned = [self::CAT => '', self::PLACE => '', self::START => 'aaaaaaaa'];
        $this->assertSame([self::START => ShowConditions::LIVE], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Akce'], self::PLACE => ['']]), self::noExpand()));
        $this->assertSame([], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Akce'], self::PLACE => ['Praha']]), self::noExpand()));
    }

    public function test_hidden_rule_on_missing_field_passes(): void
    {
        $conds = self::conds(['aaaaaaaa' => ['all' => [self::rule(self::CAT, 'in', ['Akce'])]]]);
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
        $conds    = self::conds(['aaaaaaaa' => ['all' => [self::rule(self::CAT, 'in', ['Akce'])], 'expr' => 'x']]);
        $assigned = [self::CAT => '', self::START => 'aaaaaaaa'];
        $this->assertSame([self::START => ShowConditions::STATIC], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Novinka']]), fn() => '0'));
        $this->assertSame([self::START => ShowConditions::LIVE], $conds->hidden($assigned, self::valuesOf([self::CAT => ['Novinka']]), fn() => '1'));
    }

    public function test_hidden_chain(): void
    {
        // A = start (rule on category), B = place (rule on start, which holds)
        $conds = self::conds([
            'aaaaaaaa' => ['all' => [self::rule(self::CAT, 'in', ['Akce'])]],
            'bbbbbbbb' => ['all' => [self::rule(self::START, 'filled')]],
            'cccccccc' => ['all' => [self::rule(self::CAT, 'in', ['Akce'])], 'expr' => 'x'],
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
            'aaaaaaaa' => ['all' => [self::rule(self::PLACE, 'filled')]],
            'bbbbbbbb' => ['all' => [self::rule(self::START, 'filled')]],
        ]);
        $assigned = [self::START => 'aaaaaaaa', self::PLACE => 'bbbbbbbb'];

        $this->assertSame([], $conds->hidden($assigned, self::valuesOf([self::START => ['x'], self::PLACE => ['y']]), self::noExpand()));
        $hidden = $conds->hidden($assigned, self::valuesOf([self::START => ['x']]), self::noExpand());
        $this->assertSame(ShowConditions::LIVE, $hidden[self::START] ?? null);
    }
}
