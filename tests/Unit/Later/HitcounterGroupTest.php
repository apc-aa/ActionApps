<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\HitcounterGroup;
use AA\Util\AA_Packtime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HitcounterGroupTest extends TestCase
{
    private string $tz;

    /** Prague has the clock changes the day tests are about. */
    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    public function test_groupedRows_storeTheMeanTime(): void
    {
        // 9 uncached hits at index 30 and one at 46: the row must stand for their total time
        $scale   = AA_Packtime::scale();
        $seconds = 9 * $scale->index2middle(30) + $scale->index2middle(46);
        $rows    = HitcounterGroup::groupedRows([['id' => 7, 'state' => 0, 'hits' => 10, 'seconds' => $seconds]], 1000);

        $this->assertCount(1, $rows);
        $this->assertSame(['id' => 7, 'hits' => 10, 'time' => 1000], array_diff_key($rows[0], ['duration' => 0]));
        $this->assertSame(0, $rows[0]['duration'] & 3);
        $this->assertEqualsWithDelta($seconds, 10 * AA_Packtime::seconds($rows[0]['duration']), $seconds * ($scale->calculateStep() - 1) / 2);
    }

    public function test_groupedRows_keepTheCacheStateInTheLowBits(): void
    {
        $scale = AA_Packtime::scale();
        $rows  = HitcounterGroup::groupedRows([
            ['id' => 7, 'state' => 1, 'hits' => 2, 'seconds' => 2 * $scale->index2middle(20)],
            ['id' => 7, 'state' => 2, 'hits' => 3, 'seconds' => 3 * $scale->index2middle(5)],
            ['id' => 7, 'state' => 3, 'hits' => 1, 'seconds' => $scale->index2middle(4)],
        ], 1000);

        $this->assertSame([20 * 4 + 1, 5 * 4 + 2, 4 * 4 + 3], array_column($rows, 'duration'));
        $this->assertSame([2, 3, 1], array_column($rows, 'hits'));
    }

    public function test_groupedRows_keepHitsWithoutATimeApart(): void
    {
        $scale = AA_Packtime::scale();
        $rows  = HitcounterGroup::groupedRows([
            ['id' => 7, 'state' => -1, 'hits' => 5, 'seconds' => 5 * $scale->index2middle(0)],
            ['id' => 7, 'state' => 0, 'hits' => 2, 'seconds' => 2 * $scale->index2middle(30)],
        ], 1000);

        $this->assertSame([0, 30 * 4], array_column($rows, 'duration'));
        $this->assertSame([5, 2], array_column($rows, 'hits'));
    }

    /**
     * @return array<string, array{0:string, 1:list<array{0:string,1:int}>}>
     */
    public static function dstDays(): array
    {
        return [
            // 2026-10-25 has 25 hours, 2026-03-29 has 23
            'autumn' => ['2026-11-03 12:00:00', [['2026-10-26', 86400], ['2026-10-25', 90000], ['2026-10-24', 86400]]],
            'spring' => ['2026-04-07 12:00:00', [['2026-03-30', 86400], ['2026-03-29', 82800], ['2026-03-28', 86400]]],
        ];
    }

    /** @param list<array{0:string,1:int}> $expected */
    #[DataProvider('dstDays')]
    public function test_days_followTheCalendarAcrossAClockChange(string $now, array $expected): void
    {
        $days = HitcounterGroup::days((int) strtotime($now), 1, 3);

        $this->assertSame(array_map(static fn(array $day) => [(int) strtotime($day[0]), $day[1]], $expected), $days);
    }

    public function test_days_reachTheDayOfTheOldestHitAndStop(): void
    {
        $days = HitcounterGroup::days((int) strtotime('2026-11-03 12:00:00'), (int) strtotime('2026-10-25 12:00:00'));

        $this->assertSame([(int) strtotime('2026-10-26'), (int) strtotime('2026-10-25')], array_column($days, 0));
    }
}
