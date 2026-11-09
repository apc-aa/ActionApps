<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\HitArchiveDstStamps;
use PHPUnit\Framework\TestCase;

/**
 * The stamps are the ones found in every AA database with hit statistics: two
 * per clock change, the same pattern every year since 2009.
 */
class HitArchiveDstStampsTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    private static function t(string $when): int
    {
        return (int) strtotime($when);
    }

    public function test_candidates_holdTheStampsTheOldStepLeft(): void
    {
        $this->assertSame(
            array_map(self::t(...), ['2026-03-27 23:00', '2026-03-28 23:00', '2026-10-24 01:00', '2026-10-25 01:00']),
            HitArchiveDstStamps::candidates(self::t('2026-01-01'), self::t('2026-12-31')),
        );
    }

    public function test_candidates_noneWithoutAClockChange(): void
    {
        $this->assertSame([], HitArchiveDstStamps::candidates(self::t('2026-04-01'), self::t('2026-10-01')));

        date_default_timezone_set('UTC');
        $this->assertSame([], HitArchiveDstStamps::candidates(self::t('2020-01-01'), self::t('2026-12-31')));
    }

    /**
     * Autumn: the window from 01:00 the day before took in the switch day, grouped
     * already; the one from 01:00 the switch day holds its last hour. Spring: each
     * window from 23:00 took in the next day.
     */
    public function test_dayOf_isTheDayTheRowsCameFrom(): void
    {
        $this->assertSame(self::t('2026-10-25'), HitArchiveDstStamps::dayOf(self::t('2026-10-24 01:00')));
        $this->assertSame(self::t('2026-10-25'), HitArchiveDstStamps::dayOf(self::t('2026-10-25 01:00')));
        $this->assertSame(self::t('2026-03-28'), HitArchiveDstStamps::dayOf(self::t('2026-03-27 23:00')));
        $this->assertSame(self::t('2026-03-29'), HitArchiveDstStamps::dayOf(self::t('2026-03-28 23:00')));
    }
}
