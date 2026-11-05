<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\GpsWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The GPS widget draws lines, polygons and rectangles with Leaflet.draw: its options are an object
 * literal written into the page script. A leading comma there is a syntax error, and then no map
 * starts and the field cannot be filled.
 */
final class GpsWidgetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function shapes(): array
    {
        return [
            'polygons only'        => ['P', 'marker: false, polyline: false, polygon: true, rectangle: false'],
            'lines and rectangles' => ['LR', 'marker: false, polyline: true, polygon: false, rectangle: true'],
            'all, with markers'    => ['MLPR', 'marker: true, polyline: true, polygon: true, rectangle: true'],
        ];
    }

    #[DataProvider('shapes')]
    public function test_requireRun_drawOptionsAreAValidObjectLiteral(string $map_shapes, string $options): void
    {
        $widget = new GpsWidget(['map_shapes' => $map_shapes]);
        $js     = (new ReflectionMethod($widget, 'requireRun'))->invoke($widget, null, ['last_input_name' => 'aa[n1_x][gps_____________][gps][]']);

        self::assertStringContainsString("draw: { $options, circle: false, circlemarker: false}", $js);
        self::assertDoesNotMatchRegularExpression('/\{\s*,/', $js, 'no object literal starts with a comma');
    }
}
