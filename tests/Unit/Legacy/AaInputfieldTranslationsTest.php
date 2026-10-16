<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Inputfield;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A translated field (include/formutil.php AA_Inputfield) shows, while its first language is empty,
 * the value stored before the field got translations - escaped like every other value: it may come
 * from an anonymous form and is printed in the editor's admin session.
 */
final class AaInputfieldTranslationsTest extends TestCase
{
    private const PLANTED = '</textarea><script>alert(1)</script>"><b>';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/formutil.php';
        $GLOBALS['mlxFormControlExtra'] ??= '';     // set by the MLX form code on a real page
    }

    /** @param string|array<int, array{value: string}> $value */
    private static function field(string|array $value = self::PLANTED, bool $translated = true): AA_Inputfield
    {
        $input = new AA_Inputfield($value, false, 'normal', 'v', 'V');
        if ($translated) {
            // a slice with languages sets it from the field definition
            (new ReflectionProperty(AA_Inputfield::class, 'translations'))->setValue($input, ['cz', 'en']);
        }
        return $input;
    }

    private static function inputText(AA_Inputfield $input): string
    {
        $input->inputText();
        return $input->get_result();
    }

    public function test_textarea_untranslatedValueIsEscaped(): void
    {
        $input = self::field();
        $input->textarea(4, 60, false, false);
        $html = $input->get_result();

        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_inputText_untranslatedValueIsEscaped(): void
    {
        $html = self::inputText(self::field());

        self::assertStringContainsString('value="&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;&quot;&gt;&lt;b&gt;"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function test_inputText_onlyTheFirstLanguageFallsBack(): void
    {
        $html = self::inputText(self::field('old'));

        self::assertStringContainsString('id="v-78000000" name="v[78000000]"', $html);
        self::assertSame(1, substr_count($html, 'value="old"'), 'en stays empty');
    }

    public function test_inputText_aSetTranslationWins(): void
    {
        $html = self::inputText(self::field([0 => ['value' => 'old'], 78000000 => ['value' => 'nový']]));

        self::assertStringContainsString('value="nový"', $html);
        self::assertStringNotContainsString('value="old"', $html);
    }

    /** the link next to a URL field opens the URL typed, & and all */
    public function test_inputText_previewLinkIsEscapedOnce(): void
    {
        $url = 'https://example.org/?a=1&b=2';
        foreach ([true, false] as $translated) {
            $html = self::inputText(self::field($url, $translated));
            self::assertStringContainsString('value="https://example.org/?a=1&amp;b=2"', $html);
            self::assertStringContainsString('<a href="https://example.org/?a=1&amp;b=2"', $html);
        }
    }
}
