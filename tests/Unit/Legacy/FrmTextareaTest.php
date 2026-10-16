<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * FrmTextarea() (include/formutil.php) - the admin's template fields ask for the code editor with
 * code: true; every other caller keeps the bare textarea it always had.
 */
final class FrmTextareaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../../../include/formutil.php';
        $GLOBALS['mlxFormControlExtra'] ??= '';     // set by the MLX form code on a real page
    }

    private static function render(callable $frm): string
    {
        ob_start();
        try {
            $frm();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    public function test_frmTextarea_plain_isABareTextarea(): void
    {
        $html = self::render(static fn() => FrmTextarea('odd_row_format', 'Odd Rows', '<b>{# x}</b>', 6, 50));
        self::assertStringContainsString('<textarea id="odd_row_format" name="odd_row_format"', $html);
        self::assertStringContainsString('&lt;b&gt;{# x}&lt;/b&gt;</textarea>', $html);
        self::assertStringNotContainsString('<aa-code', $html);
    }

    public function test_frmTextarea_code_wrapsTheSameTextareaInTheCodeEditor(): void
    {
        $html = self::render(static fn() => FrmTextarea('odd_row_format', 'Odd Rows', '<b>{# x}</b>', 6, 50, code: true));
        self::assertMatchesRegularExpression('~<aa-code syntax="html"><textarea id="odd_row_format" name="odd_row_format"[^>]*>&lt;b&gt;\{# x\}&lt;/b&gt;</textarea>\s*</aa-code>~', $html);
        self::assertStringNotContainsString('type="radio"', $html, 'no HTML / plain text switch - a template is HTML');
    }

    public function test_frmTextarea_codeLanguage_isTheEditorsSyntax(): void
    {
        $html = self::render(static fn() => FrmTextarea('javascript', 'Code', 'if (a < b) {}', 4, 60, code: 'javascript'));
        self::assertStringContainsString('<aa-code syntax="javascript"><textarea id="javascript" name="javascript"', $html);
        self::assertStringContainsString('if (a &lt; b) {}</textarea>', $html);
    }
}
