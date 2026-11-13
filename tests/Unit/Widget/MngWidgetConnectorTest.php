<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\MngWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The manager widget puts the stored connector into a {manager:…} expression and so into its condition.
 * A value that cannot be a connector is not used - the widget then offers a new one, as for an empty field.
 */
final class MngWidgetConnectorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    private static function connector(string $value): string
    {
        return (new \ReflectionMethod(MngWidget::class, 'connector'))->invoke(null, $value);
    }

    /** @return array<string, array{string}> */
    public static function connectors(): array
    {
        return [
            'new_id()'    => ['3f2a9c0e4b5d6e7f8091a2b3c4d5e6f7'],
            'upper case'  => ['3F2A9C0E4B5D6E7F8091A2B3C4D5E6F7'],
            'plain token' => ['gallery_2024.1'],
        ];
    }

    #[DataProvider('connectors')]
    public function test_connector_keepsAConnector(string $value): void
    {
        self::assertSame($value, self::connector($value));
    }

    /** @return array<string, array{string}> */
    public static function notConnectors(): array
    {
        return [
            'empty'       => [''],
            'expression'  => ['x}{debug'],
            'colon'       => ['a:b'],
            'quote'       => ["a'b"],
            'html'        => ['"><b>'],
            'space'       => ['a b'],
            'newline'     => ["abc\n"],
            'dash'        => ['a-b'],
            'no condition' => ['AAnoCONDITION'],
        ];
    }

    #[DataProvider('notConnectors')]
    public function test_connector_refusesOtherValues(string $value): void
    {
        self::assertSame('', self::connector($value));
    }
}
