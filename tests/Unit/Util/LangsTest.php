<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Langs;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LangsTest extends TestCase
{
    private string $collate = '';
    private ?string $lang = null;

    protected function setUp(): void
    {
        // root collation: needs no installed locale
        $this->collate = (string) setlocale(LC_COLLATE, '0');
        setlocale(LC_COLLATE, 'C');
        $this->lang = $GLOBALS['mgettext_lang'] ?? null;
    }

    protected function tearDown(): void
    {
        setlocale(LC_COLLATE, $this->collate);
        if ($this->lang === null) {
            unset($GLOBALS['mgettext_lang']);
        } else {
            $GLOBALS['mgettext_lang'] = $this->lang;
        }
    }

    /** @return array<string, array{array<int|string, string>, bool, list<int|string>}> values, descending, keys in the sorted order */
    public static function sorts(): array
    {
        return [
            'digits before letters, as text' => [['b', '10', '9', 'a'], false, [1, 2, 3, 0]],
            'spaces and punctuation ignored' => [['k' => '-y', 'l' => ' x', 'm' => '„a', 'n' => 'b'], false, ['m', 'n', 'l', 'k']],
            'descending keeps equal in order' => [[1 => 'a', 2 => 'x', 3 => 'a'], true, [2, 1, 3]],
        ];
    }

    /**
     * @param array<int|string, string> $values
     * @param list<int|string> $keys
     */
    #[DataProvider('sorts')]
    public function test_sortLocale_sortsByTheCollation(array $values, bool $desc, array $keys): void
    {
        Langs::sortLocale($values, $desc);
        self::assertSame($keys, array_keys($values));
    }

    // names that end in a number: s2 before s10
    public function test_sortLocale_numeric_comparesNumbersByValue(): void
    {
        $values = ['s10', 's2', 's1', 'a'];
        Langs::sortLocale($values, numeric: true);
        self::assertSame(['a', 's1', 's2', 's10'], array_values($values));
    }

    /** @return array<string, array{string, list<int>}> language, keys in the sorted order */
    public static function encodings(): array
    {
        return [
            'windows-1250 converted: čaj under c' => ['cz', [1, 2, 0]],
            'invalid UTF-8 replaced, still sorted' => ['en-utf8', [2, 1, 0]],
        ];
    }

    /** @param list<int> $keys */
    #[DataProvider('encodings')]
    public function test_sortLocale_sortsTextThatIsNotUtf8(string $lang, array $keys): void
    {
        $GLOBALS['mgettext_lang'] = $lang;
        $values = ['d', 'b', "\xE8aj"];
        Langs::sortLocale($values);
        self::assertSame($keys, array_keys($values));
    }

    /** @return array<string, array{string, string}> AA language name, standard code */
    public static function names(): array
    {
        return [
            'cz is cs'         => ['cz', 'cs'],
            'en stays'         => ['en', 'en'],
            'lang file prefix' => ['de-utf8', 'de'],
        ];
    }

    #[DataProvider('names')]
    public function test_getLangName2Code_givesTheCodeWithoutWarning(string $name, string $code): void
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new ErrorException($msg, 0, $no);
        });
        try {
            self::assertSame($code, Langs::getLangName2Code($name));
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, array{string, string}> standard code, AA language name */
    public static function codes(): array
    {
        return [
            'cs is cz' => ['cs', 'cz'],
            'en stays' => ['en', 'en'],
        ];
    }

    #[DataProvider('codes')]
    public function test_getLangCode2Name_givesTheNameWithoutWarning(string $code, string $name): void
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new ErrorException($msg, 0, $no);
        });
        try {
            self::assertSame($name, Langs::getLangCode2Name($code));
        } finally {
            restore_error_handler();
        }
    }
}
