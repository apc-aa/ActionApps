<?php

declare(strict_types=1);

/**
 * The parameter form (javascript/widgets/aa-params-core.js) is tested on tests/js/aa-params-cases.json.
 * Here PHP reads the same cases, so the form cannot drift from how AA reads the stored string.
 */

namespace Tests\Unit\Util;

use AA\Widget\DteWidget;
use AA\Widget\FldWidget;
use AA\Widget\RioWidget;
use AA\Widget\SelWidget;
use AA\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParamwizardCasesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, mixed> */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../../js/aa-params-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_getClassProperties_isTheOrderOfTheCases(): void
    {
        $types = self::cases()['types'];
        foreach (['fld' => FldWidget::class, 'sel' => SelWidget::class, 'rio' => RioWidget::class, 'dte' => DteWidget::class] as $code => $class) {
            $this->assertSame(array_keys($class::getClassProperties()), $types[$code], $code);
        }
    }

    /** @return array<string, array{string, string, array<string, string>}> */
    public static function readCases(): array
    {
        $ret = [];
        foreach (self::cases()['read'] as $case) {
            if (($case['php'] ?? true) !== false) {
                $ret[$case['type'] . ':' . $case['text']] = [$case['type'], $case['text'], $case['values']];
            }
        }
        return $ret;
    }

    /** @param array<string, string> $values */
    #[DataProvider('readCases')]
    public function test_parseClassProperties_readsTheCasesAsTheForm(string $type, string $text, array $values): void
    {
        $props = Widget::parseClassProperties("$type:$text");
        unset($props['class']);

        $this->assertSame($values, $props);
    }
}
