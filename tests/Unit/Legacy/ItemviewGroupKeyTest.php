<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use itemview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The groups of a listing: made by the whole group value, by its first letters or by the text before "~". */
final class ItemviewGroupKeyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('DEFAULT_LANG_INCLUDE')) {   // the letters are compared and sorted by the language
            define('DEFAULT_LANG_INCLUDE', 'en-utf8_news_lang.php');
        }
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, array{string, int, string}> group value, "letters" setting of the view, the group it makes */
    public static function groupKeys(): array
    {
        return [
            'whole text'                   => ['Čapek Karel', 0, 'Čapek Karel'],
            'first letter'                 => ['Adam', 1, 'A'],
            'three letters'                => ['Adam', 3, 'Ada'],
            'more letters than the text'   => ['Ad', 9, 'Ad'],
            // by bytes "Č" and "Ď" gave the same half of a character, so the two letters made one group
            'first letter with a diacritic' => ['Čapek', 1, 'Č'],
            'another one'                  => ['Ďáblice', 1, 'Ď'],
            'letters with diacritics'      => ['Řeřicha', 3, 'Řeř'],
            'text before ~'                => ['Praha~10', 127, 'Praha'],
            'no ~ in the text'             => ['Praha', 127, 'Praha'],
            'value starting with ~'        => ['~10', 127, ''],
            'empty value before ~'         => ['', 127, ''],
            'empty value, letters'         => ['', 1, ''],
        ];
    }

    #[DataProvider('groupKeys')]
    public function test_groupKey_cutsByLetters(string $value, int $header, string $group): void
    {
        self::assertSame($group, itemview::groupKey($value, $header));
    }

    /** @return array<string, array{string, string, bool}> */
    public static function groupPairs(): array
    {
        return [
            'same text'                    => ['Brno', 'Brno', true],
            'other case'                   => ['brno', 'BRNO', true],
            'other case with a diacritic'  => ['č', 'Č', true],
            'two letters sharing a byte'   => ['Č', 'Ď', false],
            'letter and its plain form'    => ['C', 'Č', false],
            'the start of every listing'   => ['', '_No CaTeg', false],
        ];
    }

    #[DataProvider('groupPairs')]
    public function test_sameGroup_ignoresCaseOfAnyLetter(string $a, string $b, bool $same): void
    {
        self::assertSame($same, itemview::sameGroup($a, $b));
    }
}
