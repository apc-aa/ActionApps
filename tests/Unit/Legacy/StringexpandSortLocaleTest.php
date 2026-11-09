<?php

declare(strict_types=1);

/**
 * {sort:<values>:locale|rlocale} and {aggregate:order:<ids>:<expr>:locale|rlocale} sort by the locale the
 * language file set - with Collator, as PHP 8.6 deprecates SORT_LOCALE_STRING. Numbers stay text, as
 * SORT_LOCALE_STRING had them ("10" before "9"), and equal values keep their order in the reverse sort.
 */

namespace Tests\Unit\Legacy;

use AA\Util\Langs;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandSortLocaleTest extends TestCase
{
    private static string $locale = '';

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
        } finally {
            restore_error_handler();
        }
        self::$locale = (string) setlocale(LC_COLLATE, '0');
    }

    protected function setUp(): void
    {
        // as the Czech language file sets it; the collator takes it from there
        if (setlocale(LC_COLLATE, 'cs_CZ.utf8', 'cs_CZ.UTF-8', 'cs_CZ') === false) {
            self::markTestSkipped('no Czech locale');
        }
    }

    protected function tearDown(): void
    {
        setlocale(LC_COLLATE, self::$locale);
    }

    public function test_collator_followsTheLocale(): void
    {
        self::assertSame('cs', Langs::collator()->getLocale(\Locale::VALID_LOCALE));
    }

    public function test_sort_locale_putsChAfterH(): void
    {
        self::assertSame('10-9-cesta-čaj-hrad-chata', (new \AA_Stringexpand_Sort([]))->expand('chata-hrad-čaj-cesta-9-10', 'locale'));
    }

    public function test_sort_rlocale_isTheReverse(): void
    {
        self::assertSame('chata-hrad-čaj-cesta-9-10', (new \AA_Stringexpand_Sort([]))->expand('chata-hrad-čaj-cesta-9-10', 'rlocale'));
    }
}
