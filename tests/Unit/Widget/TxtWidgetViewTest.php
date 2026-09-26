<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\EdtWidget;
use AA\Widget\TxtWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TxtWidgetViewTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('AA_INSTAL_URL')) {
            define('AA_INSTAL_URL', 'http://localhost/');   // widget autoload pulls include/constants.php
        }
    }

    /** @return array<string, array{0: bool, 1: bool, 2: int, 3: bool, 4: string}> */
    public static function views(): array
    {
        return [
            'edt'                            => [true, false, 0, true, 'rich'],
            'edt, field with a switch'       => [true, false, 1, false, 'rich'],
            'cod'                            => [false, true, 0, false, 'code'],
            'txt with switch, HTML'          => [false, false, 1, true, 'switch-code'],
            'txt with switch, plain text'    => [false, false, 1, false, 'switch-plain'],
            'txt, HTML without a switch'     => [false, false, 0, true, 'code'],
            'txt, plain text only'           => [false, false, 0, false, ''],
        ];
    }

    #[DataProvider('views')]
    public function test_view(bool $edt, bool $cod, int $switches, bool $html, string $expected): void
    {
        $this->assertSame($expected, TxtWidget::view($edt, $cod, $switches, $html));
    }

    public function test_txt_asks_for_aa_richtext_with_a_switch_and_aa_code_for_default_html(): void
    {
        $this->assertContains('aa-richtext@1', $this->libs(new TxtWidget(), 1, 0));
        $this->assertNotContains('ckeditor@4', $this->libs(new TxtWidget(), 1, 0));
        $this->assertSame(['aa-code@1'], $this->libs(new TxtWidget(), 0, 1));
        $this->assertSame([], $this->libs(new TxtWidget(), 0, 0));
    }

    public function test_a_stored_html_value_gets_codemirror_even_when_the_default_is_plain_text(): void
    {
        $this->assertSame(['aa-code@1'], $this->libs(new TxtWidget(), 0, 0, ['view' => 'code']));
        $this->assertSame([], $this->libs(new TxtWidget(), 0, 1, ['view' => '']));
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function editors(): array
    {
        return [
            'edt, live'                   => ['rich', 'live', true],
            'switch, normal form'         => ['switch-plain', 'normal', true],
            // ajax and live carry the format hidden and only for HTML - the editor could not set it
            'switch, ajax'                => ['switch-plain', 'ajax', false],
            'switch, live'                => ['switch-code', 'live', false],
        ];
    }

    #[DataProvider('editors')]
    public function test_editor_link_only_where_the_format_can_follow(string $view, string $type, bool $expected): void
    {
        $this->assertSame($expected, TxtWidget::editorAllowed($view, $type));
    }

    public function test_edt_asks_for_aa_richtext_and_runs_no_init_code(): void
    {
        $this->assertContains('aa-richtext@1', $this->libs(new EdtWidget(), 0, 1));
        $this->assertNotContains('ckeditor', $this->libs(new EdtWidget(), 0, 1));
        $this->assertSame(TxtWidget::class, (new ReflectionMethod(EdtWidget::class, 'requireRun'))->getDeclaringClass()->getName());
    }

    /** @return string[] */
    private function libs(TxtWidget $widget, int $switches, int $default, ?array $winfo = null): array
    {
        $property = new class ($switches, $default) {
            public function __construct(private int $switches, private int $default) {}
            public function getTranslations(): array
            {
                return [];
            }
            public function getContentTypeSwitches(): int
            {
                return $this->switches;
            }
            public function getContentTypeDefault(): int
            {
                return $this->default;
            }
        };
        return (new ReflectionMethod($widget, 'requireLibs'))->invoke($widget, $property, $winfo);
    }
}
