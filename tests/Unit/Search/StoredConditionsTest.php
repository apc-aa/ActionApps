<?php

declare(strict_types=1);

namespace Tests\Unit\Search;

use AA\Search\StoredConditions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The conditions of a view after its third, kept with their numbers in one text column: what the form posts
 * is stored, read back the same, and given to the search with the values of the call.
 */
final class StoredConditionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m() of the operator names
    }

    private const POSTED = [
        '4' => ['fields' => ['headline........', 'abstract........'], 'operator' => 'LIKE', 'value' => 'voda'],
        '6' => ['fields' => ['headline........@relation.......1'], 'operator' => 'RLIKE', 'value' => ''],
        '5' => ['fields' => ['', 'highlight.......'], 'operator' => '&', 'value' => '9'],
    ];

    public function test_fromForm_toText_keepsTheOrderOfTheForm(): void
    {
        self::assertSame(
            "4\theadline........,abstract........\tLIKE\tvoda\n6\theadline........@relation.......1\tRLIKE\t\n5\thighlight.......\t&\t9",
            StoredConditions::fromForm(self::POSTED)->toText(),
        );
    }

    /** @return array<string, array{array<int|string, mixed>, list<int|string>}> rows of the form (key => name), the names stored */
    public static function names(): array
    {
        return [
            'a name of letters'             => [['4' => 'author'], ['author']],
            'letters and digits'            => [['4' => 'y2020', '5' => '7'], ['y2020', 7]],
            'capitals are made small'       => [['4' => ' Author '], ['author']],
            'no name posted - the row key'  => [['8' => null], [8]],
            'too long'                      => [['4' => 'authors'], [1]],
            'another character'             => [['4' => 'a-b'], [1]],
            'empty'                         => [['4' => ''], [1]],
            'zero'                          => [['4' => '0'], [1]],
            'a number not written plainly'  => [['4' => '04'], [1]],
            'used twice - the lower row gets a number' => [['4' => 'tag', '5' => 'tag'], ['tag', 1]],
            'the number after the highest, as a new row of the form' => [['4' => 'x y', '5' => '1', '6' => '3'], [4, 1, 3]],
            'six digits are a name'         => [['4' => '999999', '5' => '', '6' => '1000000'], [999999, 1, 2]],
            'two rows without a name'       => [['r0' => '', 'r1' => '', 'r2' => '1'], [2, 3, 1]],
        ];
    }

    /**
     * @param array<int|string, mixed> $rows
     * @param list<int|string>         $names
     */
    #[DataProvider('names')]
    public function test_fromForm_names(array $rows, array $names): void
    {
        $posted = [];
        foreach ($rows as $key => $name) {
            $posted[$key] = ['fields' => ['headline........'], 'operator' => '=', 'value' => (string) $key] + (($name === null) ? [] : ['name' => $name]);
        }
        $stored = StoredConditions::fromForm($posted);

        self::assertSame($names, array_keys($stored->all()));
        self::assertSame(array_map('strval', array_keys($rows)), array_column($stored->all(), 'value'), 'every row kept, in the order of the form');
        self::assertSame($stored->all(), StoredConditions::fromText($stored->toText())->all());
    }

    /** a row the form showed and the reader refuses must not vanish without a word */
    public function test_fromForm_unread_countsTheRowsWithAFieldThatAreNoCondition(): void
    {
        $ok = ['fields' => ['headline........'], 'operator' => '=', 'value' => 'x'];

        self::assertSame(0, StoredConditions::fromForm([$ok, ['fields' => ['', ''], 'operator' => 'LIKE', 'value' => 'emptied']])->unread());
        self::assertSame(0, StoredConditions::fromForm('no rows')->unread());
        $form = StoredConditions::fromForm([$ok, ['operator' => 'like'] + $ok, ['fields' => ['headline,abstract']] + $ok, 'no row']);
        self::assertSame(2, $form->unread());
        self::assertCount(1, $form->all());
        self::assertSame(0, StoredConditions::fromText("1\theadline........\tlike\tx")->unread());
    }

    public function test_fromForm_reservedName_isReplacedByANumber(): void
    {
        $row    = ['fields' => ['headline........'], 'operator' => '=', 'value' => 'x'];
        $stored = StoredConditions::fromForm([['name' => 'date'] + $row, ['name' => '1'] + $row, ['name' => 'tag'] + $row], ['date', '1']);

        self::assertSame([2, 3, 'tag'], array_keys($stored->all()));
    }

    public function test_fromText_aNameNotAllowed_isNotRead(): void
    {
        $stored = "Author\theadline........\t=\ta\nauthors\theadline........\t=\tb\n04\theadline........\t=\tc\na b\theadline........\t=\td\ntag\theadline........\t=\te";

        self::assertSame(['tag'], array_keys(StoredConditions::fromText($stored)->all()));
    }

    public function test_fromText_readsBackWhatWasStored(): void
    {
        $stored = StoredConditions::fromForm(self::POSTED);

        self::assertSame($stored->all(), StoredConditions::fromText($stored->toText())->all());
        self::assertSame($stored->toText(), StoredConditions::fromText(str_replace("\n", "\r\n", $stored->toText()))->toText());
    }

    private const LIMITS = ['field' => 16, 'operator' => 10, 'value' => 255];

    /** a record that keeps its conditions 1 - 3 in columns: what the columns hold stays there, the rest is text */
    public function test_toColumns_oneFieldGoesToTheColumnsOfItsNumber(): void
    {
        $row    = ['operator' => '=', 'value' => 'x'];
        $posted = [
            ['name' => '2', 'fields' => ['headline........']] + $row,
            ['name' => '1', 'fields' => ['headline........', 'abstract........']] + $row,
            ['name' => '3', 'fields' => ['headline........@relation.......1']] + $row,
            ['name' => '4', 'fields' => ['category........']] + $row,
            ['name' => 'tag', 'fields' => ['category........']] + $row,
        ];
        [$columns, $rest] = StoredConditions::fromForm($posted)->toColumns(3, self::LIMITS);

        self::assertSame([1 => ['', '', ''], 2 => ['headline........', '=', 'x'], 3 => ['', '', '']], $columns);
        self::assertSame([1, 3, 4, 'tag'], array_keys($rest->all()), 'more fields, a field too long for the column, the numbers without columns, the names');

        [$columns] = StoredConditions::fromForm($posted)->toColumns(3, ['field' => 100] + self::LIMITS);
        self::assertSame(['headline........@relation.......1', '=', 'x'], $columns[3]);
    }

    public function test_toColumns_valueOrOperatorTooLong_staysInTheText(): void
    {
        $posted = [
            ['name' => '1', 'fields' => ['headline........'], 'operator' => '=', 'value' => str_repeat('x', 256)],
            ['name' => '2', 'fields' => ['headline........'], 'operator' => '=', 'value' => str_repeat('x', 255)],
        ];
        [$columns, $rest] = StoredConditions::fromForm($posted)->toColumns(3, self::LIMITS);

        self::assertSame(['', '', ''], $columns[1]);
        self::assertSame([1], array_keys($rest->all()));
        self::assertSame('headline........', $columns[2][0]);
    }

    public function test_fromColumns_theColumnsComeFirstAndWinOverTheText(): void
    {
        $text = "tag\tcategory........\t=\tt\n1\theadline........,abstract........\tLIKE\ta\n2\theadline........\t=\tfrom the text\n9\tsource..........\t=\ts";
        $all  = StoredConditions::fromColumns([1 => ['', 'LIKE', ''], 2 => ['category........', 'old-op', 'News'], 3 => ['', '', '']], $text);

        self::assertSame([1, 2, 'tag', 9], array_keys($all->all()));
        self::assertSame(['headline........', 'abstract........'], $all->all()[1]['fields'], 'empty columns: the condition of the text');
        self::assertSame(['fields' => ['category........'], 'operator' => 'old-op', 'value' => 'News'], $all->all()[2], 'filled columns as they are, the text aside');
    }

    public function test_fromColumns_toColumns_areTheSameRecord(): void
    {
        $columns = [1 => ['category........', '=', 'News'], 2 => ['', '', ''], 3 => ['headline........', 'RLIKE', '']];
        $text    = "2\theadline........,abstract........\tLIKE\ta\ntag\tcategory........\t=\tt";
        [$back, $rest] = StoredConditions::fromColumns($columns, $text)->toColumns(3, self::LIMITS);

        self::assertSame($columns, $back);
        self::assertSame($text, $rest->toText());
    }

    public function test_fromText_empty_isNoCondition(): void
    {
        self::assertSame([], StoredConditions::fromText('')->all());
        self::assertSame('', StoredConditions::fromText('')->toText());
        self::assertSame([], StoredConditions::fromText('')->toConds([4 => 'x']));
    }

    /** @return array<string, array{mixed}> */
    public static function noConditions(): array
    {
        return [
            'not an array'           => ['4'],
            'a row that is no array' => [['4' => 'headline........']],
            'no field chosen'        => [['4' => ['fields' => [''], 'operator' => 'LIKE', 'value' => 'x']]],
            'no fields at all'       => [['4' => ['operator' => 'LIKE', 'value' => 'x']]],
            'unknown operator'       => [['4' => ['fields' => ['headline........'], 'operator' => 'DROP', 'value' => 'x']]],
            'no operator'            => [['4' => ['fields' => ['headline........'], 'value' => 'x']]],
            'a field with a comma'   => [['4' => ['fields' => ['headline........,x'], 'operator' => 'LIKE', 'value' => 'x']]],
            'a field with a quote'   => [['4' => ['fields' => ["headline'"], 'operator' => 'LIKE', 'value' => 'x']]],
            'a field that is an array' => [['4' => ['fields' => [['headline........']], 'operator' => 'LIKE', 'value' => 'x']]],
        ];
    }

    #[DataProvider('noConditions')]
    public function test_fromForm_whatIsNoCondition_isLeftOut(mixed $posted): void
    {
        self::assertSame('', StoredConditions::fromForm($posted)->toText());
    }

    public function test_fromForm_value_keepsItsBytesButNoLineBreak(): void
    {
        $latin2 = "Aktu\xE1ln\xED";   // windows-1250, as an older admin page posts it
        $long   = str_repeat("\xC5\xBE", 200);   // 400 bytes of UTF-8: no cut, least of all inside a character
        $posted = ['4' => ['fields' => ['category........'], 'operator' => '=', 'value' => $latin2 . "\t-- \"a\" OR 'b'\r\n" . $long]];

        $value = StoredConditions::fromText(StoredConditions::fromForm($posted)->toText())->all()[4]['value'];

        self::assertSame($latin2 . " -- \"a\" OR 'b'  " . $long, $value);
    }

    public function test_fromForm_sameFieldTwice_isOnce(): void
    {
        $posted = ['7' => ['fields' => ['headline........', 'headline........'], 'operator' => '=', 'value' => 'x']];

        self::assertSame(['headline........'], StoredConditions::fromForm($posted)->all()[7]['fields']);
    }

    public function test_toConds_fieldsOfOneConditionAreAlternatives(): void
    {
        self::assertSame(
            [
                ['operator' => 'LIKE', 'value' => 'voda', 'headline........' => 1, 'abstract........' => 1],
                ['operator' => 'RLIKE', 'value' => '', 'headline........@relation.......1' => 1],
                ['operator' => '&', 'value' => '9', 'highlight.......' => 1],
            ],
            StoredConditions::fromForm(self::POSTED)->toConds(),
        );
    }

    public function test_toConds_valuesOfTheCallGoByTheNames(): void
    {
        $stored = StoredConditions::fromForm(self::POSTED + ['7' => ['name' => 'author', 'fields' => ['created_by......'], 'operator' => '=', 'value' => '']]);
        $given  = [1 => 'first', 4 => '', 5 => '0', 6 => 'les', 9 => 'none', 'author' => 'Novak'];

        self::assertSame(['voda', 'les', '0', 'Novak'], array_column($stored->toConds($given), 'value'));
        self::assertSame([4 => 'voda', 6 => 'les', 5 => '0', 'author' => 'Novak'], $stored->values($given));
        self::assertSame([4 => 'voda', 6 => '', 5 => '9', 'author' => ''], $stored->values());
        self::assertSame('', $stored->values(['Author' => 'Novak', 'AUTHOR' => 'Novak'])['author'], 'the name of a call is taken as written, as a field id is');
    }
}
