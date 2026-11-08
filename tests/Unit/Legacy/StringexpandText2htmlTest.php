<?php

declare(strict_types=1);

/**
 * {text2html:<text>:CZHTML} - Czech typography: a non-breaking space keeps a one-letter
 * preposition with the next word and a unit with its number, the unit itself unchanged.
 */

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class StringexpandText2htmlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
        } finally {
            restore_error_handler();
        }
    }

    public static function unitProvider(): array
    {
        return [
            'm2'  => ['5 m²', '5&nbsp;m²'],
            'cm2' => ['5 cm²', '5&nbsp;cm²'],
            'dm2' => ['5 dm²', '5&nbsp;dm²'],
            'mm2' => ['5 mm²', '5&nbsp;mm²'],
            'm3'  => ['5 m³', '5&nbsp;m³'],
            'dm3' => ['5 dm³', '5&nbsp;dm³'],
            'mm3' => ['5 mm³', '5&nbsp;mm³'],
            'kg'  => ['5 kg', '5&nbsp;kg'],
        ];
    }

    #[DataProvider('unitProvider')]
    public function test_expand_czhtml_keepsUnit(string $text, string $expected): void
    {
        self::assertSame($expected, self::expand($text));
    }

    public function test_expand_czhtml_prepositionInTextButNotInTag(): void
    {
        self::assertSame(
            '<b title="les a v lese">jde</b> v&nbsp;lese',
            self::expand('<b title="les a v lese">jde</b> v lese'),
        );
    }

    private static function expand(string $text): string
    {
        return (new \AA_Stringexpand_Text2html([]))->expand($text, 'CZHTML');
    }
}
