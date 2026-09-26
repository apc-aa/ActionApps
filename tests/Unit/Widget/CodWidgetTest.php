<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\CodWidget;
use AA\Widget\TxtWidget;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * CodWidget renders a textarea wrapped in <aa-code>, which turns it into a
 * CodeMirror 6 editor client-side. The wrapper only enhances: the textarea has
 * to survive, because form submission and the jQuery-based live/ajax
 * serialization both read it.
 */
final class CodWidgetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('AA_INSTAL_URL')) {
            define('AA_INSTAL_URL', 'http://localhost/');
        }
    }

    public function test_requireLibs_asks_for_aa_code(): void
    {
        self::assertContains('aa-code@1', $this->callRequireLibs());
    }

    public function test_requireLibs_no_longer_asks_for_codemirror5(): void
    {
        self::assertNotContains('codemirror@5', $this->callRequireLibs());
    }

    /** The element upgrades itself via customElements.define(), including in
     *  AJAX-rendered forms, so CodWidget needs no per-instance init code at all.
     *  Asserted structurally: invoking the old method with no rendered textarea
     *  returned "" anyway, so that would have proved nothing. */
    public function test_requireRun_is_not_overridden(): void
    {
        $method = new ReflectionMethod(CodWidget::class, 'requireRun');

        self::assertSame(
            TxtWidget::class,
            $method->getDeclaringClass()->getName(),
            'CodWidget should not carry its own requireRun - <aa-code> upgrades itself',
        );
    }

    /** @return string[] */
    private function callRequireLibs(): array
    {
        $widget = new CodWidget();
        $method = new ReflectionMethod($widget, 'requireLibs');
        $method->setAccessible(true);

        return $method->invoke($widget, $this->propertyStub(), null);
    }

    /** requireLibs only reads these two off the property. */
    private function propertyStub(): object
    {
        return new class {
            public function getTranslations(): array
            {
                return [];
            }

            public function getContentTypeSwitches(): bool
            {
                return false;
            }

            public function getContentTypeDefault(): int
            {
                return 0;
            }
        };
    }
}
