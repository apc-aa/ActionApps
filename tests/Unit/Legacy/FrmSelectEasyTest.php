<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * getFrmSelectEasy() (include/formutil.php) - one chosen value, or a list of them for <select multiple>;
 * a chosen value the options lack is still offered, so saving the form does not lose it.
 */
final class FrmSelectEasyTest extends TestCase
{
    private const OPTIONS = ['AA_OPTGROUP 1' => 'Group <1>', 'a' => 'Alpha', 'b' => 'Beta & co', '0' => 'Zero'];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/formutil.php';
    }

    /** @return list<string> the values of the selected options */
    private static function selected(string $html): array
    {
        preg_match_all('~<option value="([^"]*)" selected~', $html, $m);
        return $m[1];
    }

    public function test_getFrmSelectEasy_oneValue_selectsItsOption(): void
    {
        $html = getFrmSelectEasy('task', self::OPTIONS, 'b', ['id' => 'x']);

        self::assertMatchesRegularExpression('~^\s*<select name="task"\s+id="x">~', $html);
        self::assertStringContainsString('<optgroup label="Group &lt;1&gt;">', $html);
        self::assertStringContainsString('<option value="b" selected class="sel_on">Beta &amp; co', $html);
        self::assertSame(['b'], self::selected($html));
    }

    public static function nothingChosen(): array
    {
        return ['empty string' => [''], 'null' => [null], 'empty list' => [[]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nothingChosen')]
    public function test_getFrmSelectEasy_nothingChosen_selectsNothing(mixed $selected): void
    {
        self::assertSame([], self::selected(getFrmSelectEasy('task', self::OPTIONS, $selected)));
    }

    public function test_getFrmSelectEasy_zero_selectsItsOption(): void
    {
        self::assertSame(['0'], self::selected(getFrmSelectEasy('task', self::OPTIONS, '0')));
    }

    public function test_getFrmSelectEasy_unknownValue_isOfferedAsMissing(): void
    {
        $html = getFrmSelectEasy('task', self::OPTIONS, 'c<');

        self::assertStringEndsWith("\n <option value=\"c&lt;\" selected class=\"sel_missing\">c&lt;</select>\n", $html);
    }

    public function test_getFrmSelectEasy_list_selectsEachOptionAndOffersTheMissingOnes(): void
    {
        $html = getFrmSelectEasy('task[]', self::OPTIONS, ['a', 'c', 'b', '0'], ['multiple' => 'multiple']);

        self::assertMatchesRegularExpression('~^\s*<select name="task\[\]"\s+multiple="multiple">~', $html);
        self::assertSame(['a', 'b', '0', 'c'], self::selected($html));
        self::assertStringContainsString('<option value="c" selected class="sel_missing">c', $html);
        self::assertSame(1, substr_count($html, 'sel_missing'));
    }

    public function test_getFrmSelectEasy_noOptions_isEmpty(): void
    {
        self::assertSame('', getFrmSelectEasy('task', [], 'a'));
    }
}
