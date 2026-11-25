<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The limit of items in a group, set in the view (view.group_limit) or given to the call (group_limit-N):
 * the database counts it for the first order, so it is used only where that order is the group.
 */
final class ViewGroupLimitTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<string, string> $settings other settings of the view than the defaults here */
    private static function view(string $group_by, ?string $stored_limit, array $settings = []): AA_View
    {
        $limit = ($stored_limit === null) ? [] : ['group_limit' => $stored_limit];   // null: a database without the column
        return new AA_View(9320, $settings + $limit + [
            'id' => '9320', 'group_by1' => $group_by, 'g1_direction' => '0', 'gb_header' => '0',
            'order1' => 'headline........', 'o1_direction' => '0', 'order2' => '', 'o2_direction' => '0',
        ]);
    }

    /** @return array<string, array{string, ?string, array<string, string>, ?int|string}> group, stored limit, call parameters, limit of the group */
    public static function limits(): array
    {
        return [
            'set in the view'                         => ['category........', '3', [], 3],
            'not set'                                 => ['category........', '0', [], null],
            'database without the column'             => ['category........', null, [], null],
            'the call brings its own'                 => ['category........', '3', ['group_limit' => '5'], '5'],
            'the call switches it off'                => ['category........', '3', ['group_limit' => '0'], null],
        ];
    }

    /** @param array<string, string> $params */
    #[DataProvider('limits')]
    public function test_getSort_limitOfTheOwnGroup(string $group_by, ?string $stored, array $params, int|string|null $limit): void
    {
        $group = ['category........' => 'a'] + (($limit === null) ? [] : ['limit' => $limit]);

        self::assertSame([$group, ['headline........' => 'a']], self::view($group_by, $stored)->getSort($params));
    }

    /** @return array<string, array{string, array<string, string>, array<int, array<string, mixed>>}> */
    public static function otherOrders(): array
    {
        return [
            // without a group the first order is the sort - three items of each headline would be no listing
            'view without a group'   => ['', [], [['headline........' => 'a']]],
            'grouped by an alias'    => ['_#CATEGORY', [], [['headline........' => 'a']]],
            'the call groups itself' => ['category........', ['group_by' => 'source..........'], [['source..........' => 'a', 'limit' => 100000], ['headline........' => 'a']]],
            'the call sorts itself'  => ['category........', ['sort' => 'publish_date....-'], [['publish_date....' => 'd']]],
        ];
    }

    /**
     * @param array<string, string>             $params
     * @param array<int, array<string, mixed>>  $sort
     */
    #[DataProvider('otherOrders')]
    public function test_getSort_storedLimitStaysWithTheOwnGroup(string $group_by, array $params, array $sort): void
    {
        self::assertSame($sort, self::view($group_by, '3')->getSort($params));
    }

    /** @return array<string, array{array<string, string>, string}> settings of the view, the order of its group */
    public static function groupsTheDatabaseCountsAnotherWay(): array
    {
        return [
            // the database would count three items of each whole headline, not of each letter
            'groups by the first letter' => [['gb_header' => '1'], 'a'],
            'groups by the text before ~' => [['gb_header' => '127'], 'a'],
            // ordered by the priority of the constant, the database would count three items of each priority
            'ascending by priority'      => [['g1_direction' => '2'], '1'],
            'descending by priority'     => [['g1_direction' => '3'], '9'],
        ];
    }

    /** @param array<string, string> $settings */
    #[DataProvider('groupsTheDatabaseCountsAnotherWay')]
    public function test_getSort_storedLimitOnlyForGroupsByTheWholeValue(array $settings, string $direction): void
    {
        self::assertSame(
            [['category........' => $direction], ['headline........' => 'a']],
            self::view('category........', '3', $settings)->getSort(),
        );
    }

    public function test_getSort_descendingGroupTakesTheLimit(): void
    {
        self::assertSame(
            [['category........' => 'd', 'limit' => 3], ['headline........' => 'a']],
            self::view('category........', '3', ['g1_direction' => '1'])->getSort(),
        );
    }
}
