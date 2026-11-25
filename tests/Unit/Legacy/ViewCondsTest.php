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

    public function test_condValues_byTheNumbersAndNames(): void
    {
        $view = self::view(['conds' => "4\tsource..........\tLIKE\tvoda\nauthor\tcreated_by......\t=\t\ny2020\tpublish_date....\t>\t0"]);

        self::assertSame([1 => 'News', 2 => '', 4 => 'voda', 'author' => '', 'y2020' => '0'], $view->condValues());
        self::assertSame(
            [1 => 'News', 2 => 'Vod', 4 => 'voda', 'author' => 'Novak', 'y2020' => '5'],
            $view->condValues([2 => 'Vod', 3 => 'no such condition', 4 => '', 'author' => 'Novak', 'y2020' => '5', 'other' => 'x']),
        );
    }

    /** a condition 1 - 3 of more fields does not fit its columns: it is in view.conds under its number,
     *  and takes its place among the first three */
    public function test_getConds_conditionOfMoreFieldsUnderANumberOfTheColumns(): void
    {
        $view = self::view(['cond2field' => '', 'cond2op' => '', 'conds' => "tag\tcategory........\t=\tt\n2\theadline........,abstract........\tLIKE\t\n1\tsource..........\t=\tnot used"]);

        self::assertSame(
            [
                ['operator' => '=', 'value' => 'News', 'category........' => 1],
                ['operator' => 'LIKE', 'value' => 'voda', 'headline........' => 1, 'abstract........' => 1],
                ['operator' => '=', 'value' => 't', 'category........' => 1],
            ],
            $view->getConds([2 => 'voda']),
        );
        self::assertSame([1 => 'News', 2 => 'voda', 'tag' => 't'], $view->condValues([2 => 'voda']));
    }

    public function test_getConds_namedConditionOfTheCall(): void
    {
        $view = self::view(['conds' => "author\tcreated_by......\t=\t"]);

        self::assertSame(['operator' => '=', 'value' => 'Novak', 'created_by......' => 1], $view->getConds(['author' => 'Novak'])[2]);
    }

    /** a template takes the value of a condition by its number or name, wherever the condition stands */
    public function test_addViewAliases_byTheNamesOfTheConditions(): void
    {
        $view    = self::view(['conds' => "4\tsource..........\tLIKE\tvoda\nauthor\tcreated_by......\t=\t"]);
        $aliases = new AA_Aliases();
        AA_View::addViewAliases($aliases, [], $view->condValues(['author' => 'Novak']));

        self::assertSame(['_#C_1_____', '_#C_2_____', '_#C_4_____', '_#C_AUTHOR'], array_values($aliases->getNames()));
        self::assertSame('_#C_Y2020_', AA_View::condAlias('y2020'));
    }

    /** a condition named as a field alias of the slice would take its place in the templates of the view */
    public function test_condNamesTaken_areThoseWhoseAliasTheSliceHas(): void
    {
        self::assertSame(
            ['date', 'author'],
            AA_View::condNamesTaken(['_#HEADLINE', '_#C_DATE__', '_#C_4_____', '_#C_EMAIL#', '_#C_AUTHOR', '_#C_______', '_#C_Date__', '_#CATEGORY']),
        );
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
            'by a name'        => ['c-author-Novak-4-voda', ['author' => 'Novak', 4 => 'voda']],
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
