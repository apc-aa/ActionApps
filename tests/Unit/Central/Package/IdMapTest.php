<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package;

use AA\Central\Package\IdMap;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdMapTest extends TestCase
{
    private const SLICE_OLD = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const SLICE_NEW = 'abcdef0123456789abcdef0123456789';
    private const CONST_OLD = '0497ac46076bf257d15f3e030170da92';
    private const CONST_NEW = 'fedcba9876543210fedcba9876543210';

    private function map(): IdMap
    {
        return new IdMap(
            modules: [self::SLICE_OLD => self::SLICE_NEW],
            longIds: [self::CONST_OLD => self::CONST_NEW],
            views: [12 => 13],
            emails: [874 => 900],
            groups: ['biom__categories' => 'biom__categori_1'],
            collections: ['AbC12' => 'XyZ98'],
        );
    }

    public function test_lookups_return_the_new_id_or_null(): void
    {
        $map = $this->map();

        $this->assertSame(self::SLICE_NEW, $map->module(self::SLICE_OLD));
        $this->assertNull($map->module(self::CONST_OLD), 'a constant id is no module');
        $this->assertSame(self::SLICE_NEW, $map->longId(self::SLICE_OLD), 'modules are long ids too');
        $this->assertSame(self::CONST_NEW, $map->longId(self::CONST_OLD));
        $this->assertSame(13, $map->view(12));
        $this->assertNull($map->view(874), 'views and e-mails are separate maps');
        $this->assertSame(900, $map->email(874));
        $this->assertSame('biom__categori_1', $map->group('biom__categories'));
        $this->assertNull($map->group('lt_languages'));
        $this->assertSame('XyZ98', $map->collection('AbC12'));
        $this->assertNull($map->collection('abc12'), 'collection ids are case-sensitive');
    }

    public function test_longIds_are_case_insensitive_and_stored_lower_case(): void
    {
        $map = new IdMap(modules: [strtoupper(self::SLICE_OLD) => strtoupper(self::SLICE_NEW)]);

        $this->assertSame(self::SLICE_NEW, $map->module(self::SLICE_OLD));
        $this->assertSame(self::SLICE_NEW, $map->longId(strtoupper(self::SLICE_OLD)));
        $this->assertSame([self::SLICE_OLD => self::SLICE_NEW], $map->longIds());
    }

    public function test_longIds_merges_modules_and_other_long_ids(): void
    {
        $this->assertSame(
            [self::SLICE_OLD => self::SLICE_NEW, self::CONST_OLD => self::CONST_NEW],
            $this->map()->longIds(),
        );
        $this->assertSame([self::SLICE_OLD => self::SLICE_NEW], $this->map()->modules());
    }

    public function test_withNumbers_of_views_and_emails_keeps_the_original_unchanged(): void
    {
        $plan = new IdMap(modules: [self::SLICE_OLD => self::SLICE_NEW]);
        $full = $plan->withNumbers('view', [12 => 13])->withNumbers('email', [5 => 7]);

        $this->assertSame([], $plan->views());
        $this->assertSame([], $plan->emails());
        $this->assertSame([12 => 13], $full->views());
        $this->assertSame([5 => 7], $full->emails());
        $this->assertSame(self::SLICE_NEW, $full->module(self::SLICE_OLD));
    }

    public function test_withNumbers_of_filters_keeps_the_original_unchanged(): void
    {
        $plan = new IdMap(collections: ['AbC12' => 'XyZ98']);
        $full = $plan->withNumbers('filter', [3 => 41]);

        $this->assertSame([], $plan->filters());
        $this->assertSame(41, $full->filter(3));
        $this->assertNull($full->view(3), 'filters are a map of their own');
        $this->assertSame('XyZ98', $full->collection('AbC12'));
    }

    public function test_withNumbers_rejects_invalid_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new IdMap())->withNumbers('filter', [3 => 0]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidMaps(): array
    {
        return [
            'short long id'               => [['modules' => ['0f1e' => self::SLICE_NEW]]],
            'new long id not hex'         => [['longIds' => [self::CONST_OLD => str_repeat('g', 32)]]],
            'longer new id breaks s:32:'  => [['longIds' => [self::CONST_OLD => self::CONST_NEW . '0']]],
            'module mapped twice'         => [['modules' => [self::SLICE_OLD => self::SLICE_NEW], 'longIds' => [self::SLICE_OLD => self::CONST_NEW]]],
            'view zero'                   => [['views' => [0 => 13]]],
            'view new id not int'         => [['views' => [12 => '13']]],
            'email negative'              => [['emails' => [5 => -1]]],
            'group with colon'            => [['groups' => ['a:b' => 'ab']]],
            'group longer than 16 bytes'  => [['groups' => ['biom__categories' => 'biom__categories_1']]],
            'empty group'                 => [['groups' => ['biom' => '']]],
            'collection with a dot'       => [['collections' => ['AbC12' => 'Xy.98']]],
            'collection too long for 16B' => [['collections' => ['AbC12' => 'abcdefghij']]],
            // new group names are spliced into templates and input_show_func - strict names only
            'new group closes a command'  => [['groups' => ['cat' => 'a}{user']]],
            'new group with # escape'     => [['groups' => ['cat' => 'grp#']]],
            'new group with spaces'       => [['groups' => ['cat' => ' cat_1']]],
            'core group renamed'          => [['groups' => ['lt_languages' => 'lt_languages_1']]],
            'lt_groupNames renamed'       => [['groups' => ['lt_groupNames' => 'x']]],
            'group renamed to a core one' => [['groups' => ['cat' => 'lt_languages']]],
            // AAAA… and aaaa… are the same id in AA (pack_id/hex2bin)
            'one id in two cases'         => [['longIds' => [str_repeat('a', 32) => str_repeat('b', 32), str_repeat('A', 32) => str_repeat('c', 32)]]],
        ];
    }

    /** @param array<string, mixed> $args */
    #[DataProvider('invalidMaps')]
    public function test_constructor_rejects_invalid_ids(array $args): void
    {
        $this->expectException(InvalidArgumentException::class);
        new IdMap(...$args);
    }

    public function test_group_names_allowed_by_the_db_rule_may_stay(): void
    {
        $map = new IdMap(groups: ['AA Core Bins' => 'aa_core_bins', 'AA_Core_Bins....' => 'AA_Core_Bins....', 'my grp' => 'my grp']);

        $this->assertSame('aa_core_bins', $map->group('AA Core Bins'));
        $this->assertSame('AA_Core_Bins....', $map->group('AA_Core_Bins....'), 'a core group may map to itself');
        $this->assertSame('my grp', $map->group('my grp'), 'identity (export) keeps any valid name');
    }

    public function test_one_id_in_two_cases_with_the_same_target_is_allowed(): void
    {
        $map = new IdMap(longIds: [str_repeat('a', 32) => str_repeat('b', 32), str_repeat('A', 32) => str_repeat('B', 32)]);

        $this->assertSame([str_repeat('a', 32) => str_repeat('b', 32)], $map->longIds());
    }

    public function test_same_target_in_modules_and_long_ids_is_allowed(): void
    {
        $map = new IdMap(modules: [self::SLICE_OLD => self::SLICE_NEW], longIds: [self::SLICE_OLD => self::SLICE_NEW]);

        $this->assertSame([self::SLICE_OLD => self::SLICE_NEW], $map->longIds());
    }
}
