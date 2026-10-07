<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\HitcounterDisplay;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HitcounterDisplayTest extends TestCase
{
    public function test_bucketMs_overOneSecond_isNotTruncatedToThousands(): void
    {
        // index 46 = 4377.6 ms; a formatted "4 377.6" cast to float used to give 4
        $this->assertEqualsWithDelta(4377.6, HitcounterDisplay::bucketMs(3, 46), 0.05);
    }

    public function test_bucketMs_averagedIndex_decodesFraction(): void
    {
        $this->assertGreaterThan(HitcounterDisplay::bucketMs(1, 46), HitcounterDisplay::bucketMs(1, 46.5));
        $this->assertLessThan(HitcounterDisplay::bucketMs(1, 47), HitcounterDisplay::bucketMs(1, 46.5));
    }

    public function test_bucketMs_noHits_isZero(): void
    {
        $this->assertSame(0.0, HitcounterDisplay::bucketMs(0, 46));
    }

    #[DataProvider('formatMsProvider')]
    public function test_formatMs_formatsWithThinSpace(float $ms, string $expected): void
    {
        $this->assertSame($expected, HitcounterDisplay::formatMs($ms));
    }

    public static function formatMsProvider(): array
    {
        return [
            'below a second' => [143.4, '143.4'],
            'thousands'      => [4377.6, "4\u{202F}377.6"],
            'zero'           => [0.0, '0.0'],
        ];
    }
}
