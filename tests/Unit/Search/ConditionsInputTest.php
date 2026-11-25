<?php

declare(strict_types=1);

namespace Tests\Unit\Search;

use AA\Search\ConditionsInput;
use AA\Search\StoredConditions;
use PHPUnit\Framework\TestCase;

/**
 * The form control of numbered conditions posts what StoredConditions::fromForm() reads: plain fields named
 * by the number of their condition, and a template row for the script to add.
 */
final class ConditionsInputTest extends TestCase
{
    private const FIELDS    = ['headline........' => 'Headline', 'AA_OPTGROUP related' => 'Related', 'headline........@relation.......1' => 'Headline of related'];
    private const OPERATORS = ['=' => '=', 'LIKE' => 'contains (LIKE)', 'AA_OPTGROUP number' => 'Number', '&' => 'any of the bits is set (&)'];

    public static function setUpBeforeClass(): void
    {
        // loading formutil.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/../../../include/formutil.php';   // getFrmSelectEasy()
        } finally {
            restore_error_handler();
        }
    }

    private static function html(string $stored): string
    {
        return ConditionsInput::html('conds', StoredConditions::fromText($stored), self::FIELDS, self::OPERATORS, 4);
    }

    public function test_html_noConditions_isTheTemplateRowAndTheAddButton(): void
    {
        $html = self::html('');

        self::assertStringStartsWith('<aa-conditions first="4"><template>', $html);
        self::assertSame(0, substr_count(explode('<template>', $html)[0], 'data-number'));
        self::assertStringContainsString('name="conds[__N__][fields][]"', $html);
        self::assertStringContainsString('name="conds[__N__][operator]"', $html);
        self::assertStringContainsString('name="conds[__N__][value]"', $html);
        self::assertStringContainsString('data-add>', $html);
    }

    public function test_html_storedConditions_areRowsUnderTheirNumbers(): void
    {
        $rows = explode('<template>', self::html("4\theadline........,headline........@relation.......1\tLIKE\tvoda\n7\theadline........\t&\t9"))[0];

        self::assertSame(2, substr_count($rows, 'class="aa-conditions-row"'));
        self::assertSame(2, substr_count($rows, 'name="conds[4][fields][]"'), 'a select for each field of the condition');
        self::assertSame(1, substr_count($rows, 'name="conds[7][fields][]"'));
        self::assertMatchesRegularExpression('~name="conds\[4\]\[value\]"[^>]* value="voda"~', $rows);
        self::assertMatchesRegularExpression('~<option value="headline\.{8}@relation\.{7}1" selected~', $rows);
        self::assertMatchesRegularExpression('~<option value="&amp;" selected~', $rows);
    }

    public function test_html_value_isEscaped(): void
    {
        $rows = explode('<template>', self::html("4\theadline........\t=\t\"><script>x</script>"))[0];

        self::assertStringNotContainsString('<script>', $rows);
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $rows);
    }

    public function test_html_fieldNoLongerOffered_staysChosen(): void
    {
        $rows = explode('<template>', self::html("4\tgone............\t=\tx"))[0];

        self::assertMatchesRegularExpression('~<option value="gone\.{12}" selected~', $rows);
    }

    public function test_html_whatItShowsIsReadBackTheSame(): void
    {
        $stored = "4\theadline........,headline........@relation.......1\tLIKE\tvoda\n7\theadline........\t&\t";
        $html   = explode('<template>', self::html($stored))[0];
        // the fields a browser would post: the selected options and the values of the inputs
        $posted = [];
        preg_match_all('~<select name="conds\[(\d+)\]\[(fields|operator)\](\[\])?"[^>]*>(.*?)</select>~s', $html, $selects, PREG_SET_ORDER);
        foreach ($selects as [, $number, $part, $multi, $options]) {
            preg_match('~<option value="([^"]*)" selected~', $options, $selected);
            $value = html_entity_decode($selected[1] ?? '', ENT_QUOTES | ENT_HTML5);
            if ($multi) {
                $posted[$number][$part][] = $value;
            } else {
                $posted[$number][$part] = $value;
            }
        }
        preg_match_all('~name="conds\[(\d+)\]\[value\]"[^>]* value="([^"]*)"~', $html, $inputs, PREG_SET_ORDER);
        foreach ($inputs as [, $number, $value]) {
            $posted[$number]['value'] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
        }

        self::assertSame($stored, StoredConditions::fromForm($posted, 4)->toText());
    }
}
