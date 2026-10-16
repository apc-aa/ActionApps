<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\MflWidget;
use AA_Content;
use AA_Property;
use AA_Value;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * MflWidget with rows > 1 shows each value as a textarea in the code editor (<aa-code>); the
 * element upgrades itself, so a row added by the "new" link needs no init call either.
 */
final class MflWidgetTest extends TestCase
{
    private const OWNER = '5555555555555555aaaaaaaaaaaaaaaa';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_requireLibs_textareasAskForTheCodeEditor(): void
    {
        self::assertSame(['aa-code@1'], self::libs(new MflWidget(['rows' => 2])));
        self::assertSame([], self::libs(new MflWidget(['rows' => 1])));
    }

    public function test_requireRun_noInitScriptAfterRendering(): void
    {
        $widget = new MflWidget(['rows' => 2, 'row_count' => 1]);
        self::html($widget, false, ['a']);

        self::assertSame('', (new ReflectionMethod($widget, 'requireRun'))->invoke($widget, null, null));
    }

    public function test_getRawHtml_everyTextareaIsInTheCodeEditor(): void
    {
        $html = self::html(new MflWidget(['rows' => 2, 'row_count' => 1]), false, ['{# a}', 'b']);

        self::assertSame(2, substr_count($html, '<aa-code syntax="html"><textarea '));
        self::assertSame(2, substr_count($html, '</textarea></aa-code></div>'));
        self::assertStringContainsString('{# a}</textarea>', $html);
        self::assertStringContainsString('innerHTML=\'<br><aa-code syntax=&quot;html&quot;><textarea ', $html, 'the new row too');
        self::assertStringNotContainsString('CodeMirror', $html);
    }

    public function test_getRawHtml_requiredFirstRowIsInTheCodeEditorToo(): void
    {
        $html = self::html(new MflWidget(['rows' => 2, 'row_count' => 1]), true, ['a']);

        self::assertMatchesRegularExpression('~<aa-code syntax="html"><textarea [^>]*required~', $html);
    }

    public function test_getRawHtml_singleLineRowsStayInputs(): void
    {
        $html = self::html(new MflWidget(['rows' => 1, 'row_count' => 1]), false, ['a']);

        self::assertStringNotContainsString('<aa-code', $html);
        self::assertStringContainsString('<input ', $html);
    }

    /** @param string[] $values */
    private static function html(MflWidget $widget, bool $required, array $values): string
    {
        $property = new AA_Property('texts', 'Texts', 'text', true, true, null, $required);
        $content  = new AA_Content();
        $content->setOwnerId(self::OWNER);
        $content->setAaValue('texts', new AA_Value($values));
        return $widget->_getRawHtml($property, $content)['html'];
    }

    /** @return string[] */
    private static function libs(MflWidget $widget): array
    {
        return (new ReflectionMethod($widget, 'requireLibs'))->invoke($widget, null, null);
    }
}
