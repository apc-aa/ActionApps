<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Condition;
use AA_Dbcondition;
use AA_Operators;
use PHPUnit\Framework\TestCase;

/**
 * The operator select of a condition kept in settings (view form) offers every operator the search knows,
 * each once - a new operator in AA_Operators::getEnum() must be placed in the select or among those not offered.
 */
final class OperatorsForSelectTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, string> without the captions of the option groups */
    private static function options(): array
    {
        return array_filter(AA_Operators::forSelect(), static fn(string $key): bool => !str_starts_with($key, 'AA_OPTGROUP'), ARRAY_FILTER_USE_KEY);
    }

    public function test_forSelect_offersEveryOperatorOnce(): void
    {
        $offered = array_map('strval', array_keys(self::options()));
        $known   = array_map('strval', array_keys(AA_Operators::getEnum()[1]));

        self::assertEqualsCanonicalizing($known, array_merge($offered, AA_Operators::NOT_OFFERED));
        self::assertSame([], array_intersect($offered, AA_Operators::NOT_OFFERED));
    }

    public function test_forSelect_operatorFitsTheColumn(): void
    {
        // view.cond1op is varbinary(10)
        foreach (array_keys(self::options()) as $operator) {
            self::assertLessThanOrEqual(10, strlen((string) $operator), (string) $operator);
        }
    }

    /** getFrmSelectEasy() prints strip_tags() of a label longer than two characters */
    public function test_forSelect_labelsSurviveTagStripping(): void
    {
        foreach (self::options() as $operator => $label) {
            self::assertSame($label, (strlen($label) > 2) ? strip_tags($label) : $label, (string) $operator);
        }
    }

    /** an admin page may be in the charset of an older language file (iso-8859-1, windows-1250...) */
    public function test_forSelect_labelsAreAscii(): void
    {
        foreach (AA_Operators::forSelect() as $operator => $label) {
            self::assertSame(1, preg_match('/^[\x20-\x7E]+$/', $label), (string) $operator);
        }
    }

    /** "|" is true for every value, so a condition with it only looks like one */
    public function test_forSelect_offersNoOperatorThatIsAlwaysTrue(): void
    {
        self::assertArrayNotHasKey('|', self::options());
        self::assertArrayHasKey('&', self::options());
    }

    /** in a URL "&" would end the parameter: cmd[]=d-highlight.......-BITANY-9 */
    public function test_normalize_anyBitIsTheNameOfAmpersandForUrls(): void
    {
        self::assertSame('&', AA_Operators::normalize('BITANY'));
        self::assertSame('&', (new AA_Condition(['highlight.......'], 'BITANY', '9'))->getOperator());
        self::assertSame('&', AA_Dbcondition::normalizeOperator('BITANY'));
        self::assertSame('BITSET', AA_Dbcondition::normalizeOperator('BITSET'));
        self::assertStringEndsWith(' & 9)', (new AA_Dbcondition('c0', 'number', 'BITANY', '9', 'number'))->getWhereExp());
    }

    public function test_forSelect_groups(): void
    {
        self::assertSame(['AA_OPTGROUP text', 'AA_OPTGROUP number', 'AA_OPTGROUP date'], array_values(array_filter(array_keys(AA_Operators::forSelect()), static fn($key): bool => str_starts_with((string) $key, 'AA_OPTGROUP'))));
    }
}
