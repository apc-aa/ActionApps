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

    private static function field(): AA_Inputfield
    {
        $input = new AA_Inputfield(self::PLANTED, false, 'normal', 'v', 'V');
        // a slice with languages sets it from the field definition
        (new ReflectionProperty(AA_Inputfield::class, 'translations'))->setValue($input, ['cz', 'en']);
        return $input;
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
        $input = self::field();
        $input->inputText();
        $html = $input->get_result();

        self::assertStringContainsString('value="&lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;&quot;&gt;&lt;b&gt;"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }
}
