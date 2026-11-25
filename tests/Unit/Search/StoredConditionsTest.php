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
            StoredConditions::fromForm(self::POSTED, 4)->toText(),
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
            'too long'                      => [['4' => 'authors'], [4]],
            'another character'             => [['4' => 'a-b'], [4]],
            'empty'                         => [['4' => ''], [4]],
            'a number of the first three'   => [['4' => '3'], [4]],
            'a number not written plainly'  => [['4' => '04'], [4]],
            'used twice - the lower row gets a number' => [['4' => 'tag', '5' => 'tag'], ['tag', 4]],
            'the lowest free number, also past a row below' => [['4' => 'x y', '5' => '4', '6' => '9'], [5, 4, 9]],
            'six digits are a name'         => [['4' => '999999', '5' => '', '6' => '1000000'], [999999, 4, 5]],
            'two rows without a name'       => [['r0' => '', 'r1' => '', 'r2' => '4'], [5, 6, 4]],
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
        $stored = StoredConditions::fromForm($posted, 4);

        self::assertSame($names, array_keys($stored->all()));
        self::assertSame(array_map('strval', array_keys($rows)), array_column($stored->all(), 'value'), 'every row kept, in the order of the form');
        self::assertSame($stored->all(), StoredConditions::fromText($stored->toText(), 4)->all());
    }

    public function test_fromForm_reservedName_isReplacedByANumber(): void
    {
        $row    = ['fields' => ['headline........'], 'operator' => '=', 'value' => 'x'];
        $stored = StoredConditions::fromForm([['name' => 'date'] + $row, ['name' => '4'] + $row, ['name' => 'tag'] + $row], 4, ['date', '4']);

        self::assertSame([5, 6, 'tag'], array_keys($stored->all()));
    }

    public function test_fromText_aNameNotAllowed_isNotRead(): void
    {
        $stored = "Author\theadline........\t=\ta\nauthors\theadline........\t=\tb\n04\theadline........\t=\tc\na b\theadline........\t=\td\ntag\theadline........\t=\te";

        self::assertSame(['tag'], array_keys(StoredConditions::fromText($stored, 4)->all()));
    }

    public function test_fromText_readsBackWhatWasStored(): void
    {
        $stored = StoredConditions::fromForm(self::POSTED, 4);

        self::assertSame($stored->all(), StoredConditions::fromText($stored->toText())->all());
        self::assertSame($stored->toText(), StoredConditions::fromText(str_replace("\n", "\r\n", $stored->toText()))->toText());
    }

    public function test_fromText_numberBelowTheFirst_isNotRead(): void
    {
        $stored = "2\theadline........\t=\tx\n4\theadline........\t=\ty";

        self::assertSame([4], array_keys(StoredConditions::fromText($stored, 4)->all()));
        self::assertSame([2, 4], array_keys(StoredConditions::fromText($stored)->all()));
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
        self::assertSame('', StoredConditions::fromForm($posted, 4)->toText());
    }

    public function test_fromForm_value_keepsItsBytesButNoLineBreak(): void
    {
        $latin2 = "Aktu\xE1ln\xED";   // windows-1250, as an older admin page posts it
        $long   = str_repeat("\xC5\xBE", 200);   // 400 bytes of UTF-8: no cut, least of all inside a character
        $posted = ['4' => ['fields' => ['category........'], 'operator' => '=', 'value' => $latin2 . "\t-- \"a\" OR 'b'\r\n" . $long]];

        $value = StoredConditions::fromText(StoredConditions::fromForm($posted, 4)->toText())->all()[4]['value'];

        self::assertSame($latin2 . " -- \"a\" OR 'b'  " . $long, $value);
    }

    public function test_fromForm_sameFieldTwice_isOnce(): void
    {
        $posted = ['7' => ['fields' => ['headline........', 'headline........'], 'operator' => '=', 'value' => 'x']];

        self::assertSame(['headline........'], StoredConditions::fromForm($posted, 4)->all()[7]['fields']);
    }

    public function test_toConds_fieldsOfOneConditionAreAlternatives(): void
    {
        self::assertSame(
            [
                ['operator' => 'LIKE', 'value' => 'voda', 'headline........' => 1, 'abstract........' => 1],
                ['operator' => 'RLIKE', 'value' => '', 'headline........@relation.......1' => 1],
                ['operator' => '&', 'value' => '9', 'highlight.......' => 1],
            ],
            StoredConditions::fromForm(self::POSTED, 4)->toConds(),
        );
    }

    public function test_toConds_valuesOfTheCallGoByTheNames(): void
    {
        $stored = StoredConditions::fromForm(self::POSTED + ['7' => ['name' => 'author', 'fields' => ['created_by......'], 'operator' => '=', 'value' => '']], 4);
        $given  = [1 => 'first', 4 => '', 5 => '0', 6 => 'les', 9 => 'none', 'author' => 'Novak'];

        self::assertSame(['voda', 'les', '0', 'Novak'], array_column($stored->toConds($given), 'value'));
        self::assertSame([4 => 'voda', 6 => 'les', 5 => '0', 'author' => 'Novak'], $stored->values($given));
        self::assertSame([4 => 'voda', 6 => '', 5 => '9', 'author' => ''], $stored->values());
        self::assertSame('Novak', $stored->values(['Author' => 'Novak'])['author'], 'a call may write the name in capitals, as its alias has it');
    }
}
