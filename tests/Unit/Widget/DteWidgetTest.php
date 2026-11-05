<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\DteWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The date widget with show_time 1 or 3 shows the time as "10:30" - without seconds - and the editor
 * may type just "14". A missing part of the time is 0, never the minute or second of the save.
 */
final class DteWidgetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** @return array<string, array{0: string, 1: array{int, int, int}}> */
    public static function times(): array
    {
        return [
            'hours, minutes, seconds' => ['10:30:15', [10, 30, 15]],
            'hours and minutes'       => ['10:30', [10, 30, 0]],
            'hours only'              => ['14', [14, 0, 0]],
            'empty is midnight'       => ['', [0, 0, 0]],
            'a typed letter'          => ['10h', [10, 0, 0]],
            'a trailing colon'        => ['10:', [10, 0, 0]],
        ];
    }

    /** @param array{int, int, int} $hms */
    #[DataProvider('times')]
    public function test_getWidgetValue_time(string $time, array $hms): void
    {
        $value = DteWidget::getWidgetValue(['dte' => ['i' => [], 'y' => ['2026'], 'm' => ['11'], 'd' => ['4'], 't' => [$time]]], null);

        self::assertSame([mktime($hms[0], $hms[1], $hms[2], 11, 4, 2026)], $value->getValues());
    }
}
