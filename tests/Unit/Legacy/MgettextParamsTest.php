<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * _m() substitutes %1, %2, ... with values that are often user data - a page address, a module
 * name, a file name. Those values may themselves contain a percent sign, so the substitution has
 * to happen in one pass: a "%20" in the first value must not be read as the %2 placeholder.
 */
final class MgettextParamsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../include/mgettext.php';
    }

    public function test_aParameterIsNotScannedForFurtherPlaceholders(): void
    {
        self::assertSame(
            'Open /produkt%20a/ in the administration (https://admin.example.org).',
            _m('Open %1 in the administration (%2).', ['/produkt%20a/', 'https://admin.example.org']),
        );
    }

    public function test_placeholdersAreFilledInOrder(): void
    {
        self::assertSame('a-b-c', _m('%1-%2-%3', ['a', 'b', 'c']));
        self::assertSame('2 of 3', _m('%1 of %2', [2, 3]), 'numbers are values too');
    }

    public function test_aBackslashKeepsAPercentLiteral(): void
    {
        self::assertSame('100% of x', _m('100\% of %1', ['x']));
    }

    public function test_aMessageWithoutParametersComesBackUntouched(): void
    {
        self::assertSame('50% off %1', _m('50% off %1'));
    }

    public function test_tenParametersDoNotCollideWithTheFirst(): void
    {
        $params = ['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten'];

        self::assertSame('ten and one', _m('%10 and %1', $params));
    }
}
