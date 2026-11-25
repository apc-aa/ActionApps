<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_View;
use itemview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "Group by" of a listing view: a field is ordered by the database, an alias by the listing itself, once it
 * has the items - so the view must not ask the database to order by it.
 */
final class ViewGroupByAliasTest extends TestCase
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

    private static function view(string $group_by, int $direction): AA_View
    {
        return new AA_View(9310, [
            'id' => '9310', 'group_by1' => $group_by, 'g1_direction' => (string) $direction, 'gb_header' => '0',
            'order1' => 'headline........', 'o1_direction' => '0', 'order2' => '', 'o2_direction' => '0',
        ]);
    }

    public function test_getSort_groupField_isOrderedByTheDatabase(): void
    {
        self::assertSame(
            [['category........' => 'd'], ['headline........' => 'a']],
            self::view('category........', 1)->getSort(['group_limit' => '']),
        );
    }

    public function test_getSort_groupAlias_isLeftToTheListing(): void
    {
        self::assertSame([['headline........' => 'a']], self::view('_#CATEGORY', 1)->getSort(['group_limit' => '']));
    }

    public function test_getSort_groupAliasWithoutUnderscoreForm_isAnAliasToo(): void
    {
        self::assertSame([['headline........' => 'a']], self::view('CATEGORY', 0)->getSort(['group_limit' => '']));
    }

    /** the limit of items in a group is the database's, which does not know the groups of an alias */
    public function test_getSort_groupAlias_takesNoGroupLimit(): void
    {
        self::assertSame([['headline........' => 'a']], self::view('_#CATEGORY', 0)->getSort(['group_limit' => '2']));
        self::assertSame([['headline........' => 'a']], self::view('category........', 0)->getSort(['group_by' => '_#CATEGORY', 'group_limit' => '2']));
    }

    public function test_getSort_groupLimitWithNothingToOrderBy(): void
    {
        $view = new AA_View(9311, ['id' => '9311', 'group_by1' => '_#CATEGORY', 'g1_direction' => '0', 'order1' => '', 'order2' => '']);

        self::assertNull($view->getSort(['group_limit' => '2']));
    }

    public function test_getSort_groupField_takesTheGroupLimit(): void
    {
        self::assertSame(
            [['category........' => 'a', 'limit' => '2'], ['headline........' => 'a']],
            self::view('category........', 0)->getSort(['group_limit' => '2']),
        );
    }

    /** @return array<string, array{int, bool}> direction as the view stores it */
    public static function directions(): array
    {
        return [
            'ascending'              => [0, false],
            'descending'             => [1, true],
            'ascending by priority'  => [2, false],
            'descending by priority' => [3, true],
        ];
    }

    #[DataProvider('directions')]
    public function test_groupDescending(int $direction, bool $descending): void
    {
        self::assertSame($descending, self::view('_#CATEGORY', $direction)->groupDescending());
    }

    /** @return list<string> the groups in the order they would be printed */
    private static function groupsOf(array $names, int $header, bool $desc = false): array
    {
        $groups = [];
        foreach (itemview::groupOrder(array_combine(array_map(static fn(int $i): string => "id$i", array_keys($names)), $names), $header, $desc) as $id) {
            $key = itemview::groupKey($names[(int) substr($id, 2)], $header);
            if (($groups === []) || !itemview::sameGroup($key, end($groups))) {
                $groups[] = $key;
            }
        }
        return $groups;
    }

    /** the locale sorts "Ú" among "U" and "c" among "C" - ordered by the whole value each change would open a group */
    public function test_groupOrder_keepsALetterInOneGroup(): void
    {
        $names  = ['Ústí', 'Unhošť', 'cesta', 'Úhonice', 'Uherské Hradiště', 'Cvikov', 'Hradec', 'Cheb', 'Čáslav'];
        $groups = array_map('mb_strtolower', self::groupsOf($names, 1));

        self::assertSame(array_values(array_unique($groups)), $groups);
        self::assertEqualsCanonicalizing(['c', 'č', 'h', 'u', 'ú'], $groups);
        self::assertSame(array_reverse($groups), array_map('mb_strtolower', self::groupsOf($names, 1, true)));
    }

    public function test_groupOrder_byTheWholeValue_keepsTheOrderOfEqualValues(): void
    {
        $order = itemview::groupOrder(['a' => 'Praha', 'b' => 'Brno', 'c' => 'Praha', 'd' => 'Brno'], 0, false);

        self::assertSame(['b', 'd', 'a', 'c'], $order);
        self::assertSame(['a', 'c', 'b', 'd'], itemview::groupOrder(['a' => 'Praha', 'b' => 'Brno', 'c' => 'Praha', 'd' => 'Brno'], 0, true));
    }
}
