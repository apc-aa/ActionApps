<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\VersionProgress;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind the Version progress panel.
 *
 * summarize() is deliberately pure, because the claims it makes are the kind
 * that have to be checked rather than eyeballed on a live page: which items are
 * allowed to speak, and how a duration index becomes a percentage. Every case
 * here comes from something the live data does. When a move counts as real is
 * ProgressPanelTest's, because both panels decide it the same way.
 */
final class VersionProgressTest extends TestCase
{
    /** One index step is 18.728%: the resolution of the stored duration byte. */
    private const STEP = 1.18728;

    /** @param list<array{string,string}> $days [first day, day after the last] */
    private static function intervals(array $days): array
    {
        $out = [];
        foreach ($days as $i => [$from, $to]) {
            $out[] = [
                'from'    => (int) strtotime($from . ' 00:00:00'),
                'to'      => (int) strtotime($to . ' 00:00:00'),
                'days'    => (int) round(((int) strtotime($to) - (int) strtotime($from)) / 86400),
                'changes' => [],
                'short'   => false,
            ];
        }
        return $out;
    }

    /**
     * One item-day: how many hits in each cache state, and what index they averaged.
     * Uncached and cached are the only two states the panel measures.
     */
    private static function day(int $uncached_hits, float $uncached_index, int $cached_hits = 0, float $cached_index = 0.0): array
    {
        return [
            'h' => [$uncached_hits, 0, $cached_hits, 0],
            'w' => [0 => (int) round($uncached_hits * $uncached_index), 2 => (int) round($cached_hits * $cached_index)],
        ];
    }

    /** An item with the same traffic every day across both windows. */
    private static function steady(float $index_before, float $index_after, int $hits = 100, int $cached_hits = 0): array
    {
        return [
            '2026-07-01' => self::day($hits, $index_before, $cached_hits, $index_before - 18),
            '2026-07-02' => self::day($hits, $index_before, $cached_hits, $index_before - 18),
            '2026-07-05' => self::day($hits, $index_after, $cached_hits, $index_after - 18),
            '2026-07-06' => self::day($hits, $index_after, $cached_hits, $index_after - 18),
        ];
    }

    private static function twoWindows(): array
    {
        return self::intervals([['2026-07-01', '2026-07-03'], ['2026-07-05', '2026-07-07']]);
    }

    public function testAnItemMissingFromAWindowCannotSpeakForTheComparison(): void
    {
        $summary = VersionProgress::summarize([
            'a' => self::steady(24, 24),
            'b' => self::steady(24, 24),
            'c' => ['2026-07-05' => self::day(100, 24), '2026-07-06' => self::day(100, 24)],   // arrived late
        ], self::twoWindows());

        self::assertSame(['a', 'b'], $summary['cohort']);
    }

    public function testAnItemTooQuietInOneWindowCannotSpeakEither(): void
    {
        $summary = VersionProgress::summarize([
            'a' => self::steady(24, 24),
            'b' => [
                '2026-07-01' => self::day(2, 24),   // below VersionProgress::MIN_HITS
                '2026-07-05' => self::day(100, 24),
            ],
        ], self::twoWindows());

        self::assertSame(['a'], $summary['cohort']);
    }

    public function testTheCachedColumnRestsOnItemsThatActuallyHaveCachedTraffic(): void
    {
        $summary = VersionProgress::summarize([
            'a' => self::steady(24, 24, 100, 80),
            'b' => self::steady(24, 24),   // never served from cache
        ], self::twoWindows());

        self::assertSame(['a', 'b'], $summary['cohort']);
        self::assertSame(['a'], $summary['cached_cohort']);
    }

    public function testOneIndexStepReadsAsEighteenPercent(): void
    {
        $summary = VersionProgress::summarize([
            'a' => self::steady(24, 25),
            'b' => self::steady(24, 25),
        ], self::twoWindows());

        self::assertEqualsWithDelta(18.7, $summary['rows'][1]['d_nc'], 0.1);
        self::assertEqualsWithDelta(
            self::STEP,
            $summary['rows'][1]['ms_nc'] / $summary['rows'][0]['ms_nc'],
            0.001,
            'milliseconds move by the same ratio as the index',
        );
    }

    public function testGettingFasterIsANegativePercentage(): void
    {
        $summary = VersionProgress::summarize([
            'a' => self::steady(25, 24),
            'b' => self::steady(25, 24),
        ], self::twoWindows());

        self::assertEqualsWithDelta(-15.8, $summary['rows'][1]['d_nc'], 0.1);
        self::assertNull($summary['rows'][0]['d_nc'] ?? null, 'the oldest window has nothing to compare against');
    }

    public function testTheFirstWindowCarriesNoComparison(): void
    {
        $summary = VersionProgress::summarize(['a' => self::steady(24, 24), 'b' => self::steady(24, 24)], self::twoWindows());

        self::assertArrayNotHasKey('agree_nc', $summary['rows'][0]);
        self::assertArrayHasKey('agree_nc', $summary['rows'][1]);
    }

    public function testAgreementCountsItemsNotHits(): void
    {
        $summary = VersionProgress::summarize([
            'a' => self::steady(25, 24),                 // faster
            'b' => self::steady(25, 24),                 // faster
            'c' => self::steady(24, 25, 100000),         // slower, and by far the busiest
            'd' => self::steady(24, 24),                 // unchanged, so it does not vote
        ], self::twoWindows());

        self::assertSame(['faster' => 2, 'total' => 3], $summary['rows'][1]['agree_nc']);
    }

    public function testDaysOutsideEveryWindowAreLeftOut(): void
    {
        // 2026-07-03 is the day a configuration change landed on, so it belongs to
        // neither window and must not reach either average.
        $items = [
            'a' => self::steady(24, 24) + ['2026-07-03' => self::day(1000, 60)],
            'b' => self::steady(24, 24) + ['2026-07-03' => self::day(1000, 60)],
        ];

        $summary = VersionProgress::summarize($items, self::twoWindows());

        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_nc'], 0.001, 'the excluded day moved nothing');
        self::assertSame(400, $summary['rows'][0]['hits'], 'and it is not counted as measured either');
    }

    public function testCachePercentIsAveragedPerItemSoOneBusyPageCannotCarryIt(): void
    {
        $summary = VersionProgress::summarize([
            'a' => ['2026-07-01' => self::day(100, 24, 100, 6), '2026-07-05' => self::day(100, 24, 100, 6)],
            'b' => ['2026-07-01' => self::day(100, 24), '2026-07-05' => self::day(100, 24)],
        ], self::twoWindows());

        // One item at 50% cached, one at 0%: the mean is 25%, whatever their sizes.
        self::assertEqualsWithDelta(25.0, $summary['rows'][0]['cache_pct'], 0.01);
        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_cache'], 0.01);
    }

    public function testCachePercentMovesInPercentagePoints(): void
    {
        $summary = VersionProgress::summarize([
            'a' => ['2026-07-01' => self::day(100, 24), '2026-07-05' => self::day(100, 24, 100, 6)],
            'b' => ['2026-07-01' => self::day(100, 24), '2026-07-05' => self::day(100, 24, 100, 6)],
        ], self::twoWindows());

        self::assertEqualsWithDelta(50.0, $summary['rows'][1]['d_cache'], 0.01);
    }

    public function testTheDailyCurveIsBlindToAnItemMissingForADay(): void
    {
        // Two items of very different speed. Without per-item normalisation, the day
        // the slow one is missing would read as a large speed-up that never happened.
        $items = [
            'a' => self::steady(24, 24),
            'b' => self::steady(40, 40),
        ];
        unset($items['b']['2026-07-02']);

        $daily = VersionProgress::summarize($items, self::twoWindows())['daily'];
        $day2  = array_search('2026-07-02', $daily['labels'], true);

        self::assertEqualsWithDelta(100.0, $daily['nc'][$day2], 0.01);
        self::assertSame([100.0, 100.0, 100.0, 100.0], $daily['nc']);
    }

    public function testADayWithAlmostNoTrafficDoesNotMoveTheDailyCurve(): void
    {
        // A couple of hits carry one coarse quantised index each, which on a quiet
        // install swings the curve by more than any optimisation ever will.
        $items = [
            'a' => self::steady(24, 24),
            'b' => self::steady(24, 24),
        ];
        $items['b']['2026-07-02'] = self::day(2, 60);

        $daily = VersionProgress::summarize($items, self::twoWindows())['daily'];
        $day2  = array_search('2026-07-02', $daily['labels'], true);

        self::assertEqualsWithDelta(100.0, $daily['nc'][$day2], 0.01);
    }

    public function testTheDailyCurveReportsRelativeSpeed(): void
    {
        $daily = VersionProgress::summarize([
            'a' => self::steady(24, 25),
            'b' => self::steady(24, 25),
        ], self::twoWindows())['daily'];

        // Each item averages 24.5 over the period, so its slow days sit one half
        // step above that and its fast days one half step below.
        self::assertSame(['2026-07-01', '2026-07-02', '2026-07-05', '2026-07-06'], $daily['labels']);
        self::assertEqualsWithDelta(100 / sqrt(self::STEP), $daily['nc'][0], 0.05);
        self::assertEqualsWithDelta(100 * sqrt(self::STEP), $daily['nc'][3], 0.05);
    }

    public function testAWindowWithNoCachedTrafficReportsNothingRatherThanZero(): void
    {
        $summary = VersionProgress::summarize([
            'a' => self::steady(24, 24),
            'b' => self::steady(24, 24),
        ], self::twoWindows());

        self::assertNull($summary['rows'][0]['ms_c']);
        self::assertNull($summary['rows'][1]['d_c']);
        self::assertNotNull($summary['rows'][0]['ms_nc']);
    }
}
