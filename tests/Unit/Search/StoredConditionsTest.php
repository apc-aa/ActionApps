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

    public function test_fromForm_toText_keepsTheNumbersInOrder(): void
    {
        self::assertSame(
            "4\theadline........,abstract........\tLIKE\tvoda\n5\thighlight.......\t&\t9\n6\theadline........@relation.......1\tRLIKE\t",
            StoredConditions::fromForm(self::POSTED, 4)->toText(),
        );
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
            'number of the first three' => [['3' => ['fields' => ['headline........'], 'operator' => 'LIKE', 'value' => 'x']]],
            'no number'              => [['x' => ['fields' => ['headline........'], 'operator' => 'LIKE', 'value' => 'x']]],
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
        $posted = ['4' => ['fields' => ['category........'], 'operator' => '=', 'value' => $latin2 . "\t-- \"a\" OR 'b'\r\n" . str_repeat('x', 300)]];

        $value = StoredConditions::fromText(StoredConditions::fromForm($posted, 4)->toText())->all()[4]['value'];

        self::assertStringStartsWith($latin2 . " -- \"a\" OR 'b'  xxx", $value);
        self::assertSame(StoredConditions::VALUE_MAX, strlen($value));
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
                ['operator' => '&', 'value' => '9', 'highlight.......' => 1],
                ['operator' => 'RLIKE', 'value' => '', 'headline........@relation.......1' => 1],
            ],
            StoredConditions::fromForm(self::POSTED, 4)->toConds(),
        );
    }

    public function test_toConds_valuesOfTheCallGoByTheNumbers(): void
    {
        $conds = StoredConditions::fromForm(self::POSTED, 4)->toConds([1 => 'first', 4 => '', 5 => '0', 6 => 'les', 9 => 'none']);

        self::assertSame(['voda', '0', 'les'], array_column($conds, 'value'));
    }
}
