<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Aliases;
use AA_View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The conditions a view searches by: those set in the view, each with the value the call gives for its
 * number (cmd[]=c-<number>-<value>).
 */
final class ViewCondsTest extends TestCase
{
    private const VID = 9321;

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/view.php';
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<string, string> $settings */
    private static function view(array $settings = []): AA_View
    {
        return new AA_View(self::VID, $settings + [
            'id' => (string) self::VID,
            'cond1field' => 'category........', 'cond1op' => '=', 'cond1cond' => 'News',
            'cond2field' => 'headline........', 'cond2op' => 'RLIKE', 'cond2cond' => '',
            'cond3field' => '', 'cond3op' => '<', 'cond3cond' => '',
        ]);
    }

    public function test_getConds_theConditionsOfTheView(): void
    {
        self::assertSame(
            [
                ['operator' => '=', 'value' => 'News', 'category........' => 1],
                ['operator' => 'RLIKE', 'value' => '', 'headline........' => 1],
            ],
            self::view()->getConds(),
        );
    }

    public function test_getConds_theCallGivesValuesByTheNumbers(): void
    {
        self::assertSame(
            [
                ['operator' => '=', 'value' => '0', 'category........' => 1],
                ['operator' => 'RLIKE', 'value' => 'Vod', 'headline........' => 1],
            ],
            self::view()->getConds([1 => '0', 2 => 'Vod', 3 => 'no such condition']),
        );
    }

    public function test_getConds_conditionsAfterTheThird(): void
    {
        $view = self::view(['conds' => "4\tsource..........,abstract........\tLIKE\tvoda\n7\thighlight.......\t&\t"]);

        self::assertSame(
            [
                ['operator' => '=', 'value' => 'News', 'category........' => 1],
                ['operator' => 'RLIKE', 'value' => 'Vod', 'headline........' => 1],
                ['operator' => 'LIKE', 'value' => 'voda', 'source..........' => 1, 'abstract........' => 1],
                ['operator' => '&', 'value' => '9', 'highlight.......' => 1],
            ],
            $view->getConds([2 => 'Vod', 7 => '9']),
        );
    }

    /** what the call names and the view has not - also the pairs the former c-OR-1-a-2-b now reads - changes nothing */
    public function test_getConds_unknownConditionsOfTheCall(): void
    {
        self::assertSame(self::view()->getConds([]), self::view()->getConds(['OR' => '1', 'a' => '2', 'b' => '', 9 => 'x']));
    }

    /** _#VIEW_C1... are numbered by the order of the conditions in use, not by their numbers */
    public function test_addViewAliases_byTheOrderOfTheConditions(): void
    {
        $aliases = new AA_Aliases();
        AA_View::addViewAliases($aliases, self::view()->getConds([1 => 'a', 2 => 'b']));

        self::assertSame(['_#VIEW_C1_', '_#VIEW_C2_'], array_values($aliases->getNames()));
    }

    /** @return array<string, array{string, array<int|string, string>}> the c command, the values by the numbers */
    public static function commands(): array
    {
        return [
            'one value'        => ['c-1-News', [1 => 'News']],
            'three values'     => ['c-1-News-2-Vod-3-x', [1 => 'News', 2 => 'Vod', 3 => 'x']],
            'any order'        => ['c-3-x-1-News', [3 => 'x', 1 => 'News']],
            'a value skipped'  => ['c-1-AAnoCONDITION-2-Vod', [2 => 'Vod']],
            'no OR form'       => ['c-OR-1-News-2-Vod', ['OR' => '1', 'News' => '2', 'Vod' => '']],
            'a dash in a value' => ['c-1-a--b', [1 => 'a-b']],
            'after the third'  => ['c-1-News-2-Vod-3-x-4-voda-12-9', [1 => 'News', 2 => 'Vod', 3 => 'x', 4 => 'voda', 12 => '9']],
            'only a later one' => ['c-5-voda', [5 => 'voda']],
            'a number alone'   => ['c-1-News-4', [1 => 'News', 4 => '']],
        ];
    }

    /** @param array<int|string, string> $values */
    #[DataProvider('commands')]
    public function test_parseViewParameters_cCommand(string $command, array $values): void
    {
        $params = AA_View::parseViewParameters('vid=' . self::VID . '&cmd[' . self::VID . ']=' . $command);

        self::assertSame($values, $params['param_conds']);
    }
}
