<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\ConfigTimeline;
use AA\Util\AA_Packtime;
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
    /** One index step, 18.6 %: the resolution of the stored duration byte. */
    private static function step(): float
    {
        return AA_Packtime::scale()->calculateStep();
    }

    /** @param list<array{string,string}> $days [first day, day after the last] */
    private static function intervals(array $days): array
    {
        $out = [];
        foreach ($days as [$from, $to]) {
            $out[] = self::window((int) strtotime($from . ' 00:00:00'), (int) strtotime($to . ' 00:00:00'));
        }
        return $out;
    }

    /** A window as ConfigTimeline::buildIntervals() hands it over. */
    private static function window(int $from, int $to, bool $short = false): array
    {
        return [
            'from'         => $from,
            'to'           => $to,
            'span'         => $to - $from,
            'measure_from' => $from,
            'measure_to'   => $short ? $from : $to,
            'days'         => ($to - $from) / 86400,
            'exact'        => false,
            'changes'      => [],
            'short'        => $short,
        ];
    }

    /**
     * summarize() over items written per day, the way the older, grouped data
     * comes back from hit_archive: one row per day, stamped at its midnight.
     *
     * @param array<string,array<string,array>> $items  item => Y-m-d => counts
     */
    private static function summarize(array $items, array $intervals): array
    {
        $hours = [];
        foreach ($items as $sid => $days) {
            foreach ($days as $day => $counts) {
                $hours[$sid][(int) strtotime($day . ' 00:00:00')] = $counts;
            }
        }
        return VersionProgress::summarize($hours, $intervals);
    }

    /**
     * One item-day: how many hits in each cache state, and the index of their mean time.
     * Uncached and cached are the only two states the panel measures.
     */
    private static function day(int $uncached_hits, float $uncached_index, int $cached_hits = 0, float $cached_index = 0.0): array
    {
        $scale = AA_Packtime::scale();
        return [
            'h' => [$uncached_hits, 0, $cached_hits, 0],
            'w' => [0 => $uncached_hits * $scale->index2float($uncached_index), 2 => $cached_hits * $scale->index2float($cached_index)],
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
        $summary = self::summarize([
            'a' => self::steady(24, 24),
            'b' => self::steady(24, 24),
            'c' => ['2026-07-05' => self::day(100, 24), '2026-07-06' => self::day(100, 24)],   // arrived late
        ], self::twoWindows());

        self::assertSame(['a', 'b'], $summary['cohort']);
    }

    public function testAnItemTooQuietInOneWindowCannotSpeakEither(): void
    {
        $summary = self::summarize([
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
        $summary = self::summarize([
            'a' => self::steady(24, 24, 100, 80),
            'b' => self::steady(24, 24),   // never served from cache
        ], self::twoWindows());

        self::assertSame(['a', 'b'], $summary['cohort']);
        self::assertSame(['a'], $summary['cached_cohort']);
    }

    public function testOneIndexStepReadsAsEighteenPercent(): void
    {
        $summary = self::summarize([
            'a' => self::steady(24, 25),
            'b' => self::steady(24, 25),
        ], self::twoWindows());

        self::assertEqualsWithDelta(100 * (self::step() - 1), $summary['rows'][1]['d_nc'], 0.05);
        self::assertEqualsWithDelta(
            self::step(),
            $summary['rows'][1]['ms_nc'] / $summary['rows'][0]['ms_nc'],
            0.001,
            'milliseconds move by the same ratio as the index',
        );
    }

    public function testGettingFasterIsANegativePercentage(): void
    {
        $summary = self::summarize([
            'a' => self::steady(25, 24),
            'b' => self::steady(25, 24),
        ], self::twoWindows());

        self::assertEqualsWithDelta(100 * (1 / self::step() - 1), $summary['rows'][1]['d_nc'], 0.05);
        self::assertNull($summary['rows'][0]['d_nc'] ?? null, 'the oldest window has nothing to compare against');
    }

    public function testAnItemsSlowHitsCountWithTheirWholeTime(): void
    {
        // a tenth of the hits 16 steps (15×) slower: the item spends 2.44× the time,
        // which averaging the indexes would report as only 1.6 steps (+31 %)
        $scale = AA_Packtime::scale();
        $mixed = ['h' => [100, 0, 0, 0], 'w' => [0 => 90 * $scale->index2float(24) + 10 * $scale->index2float(40), 2 => 0.0]];
        $item  = self::steady(24, 24);
        $item['2026-07-05'] = $item['2026-07-06'] = $mixed;

        $summary = self::summarize(['a' => $item, 'b' => $item], self::twoWindows());

        self::assertEqualsWithDelta(0.9 + 0.1 * self::step() ** 16, $summary['rows'][1]['ms_nc'] / $summary['rows'][0]['ms_nc'], 0.001);
    }

    public function testTheFirstWindowCarriesNoComparison(): void
    {
        $summary = self::summarize(['a' => self::steady(24, 24), 'b' => self::steady(24, 24)], self::twoWindows());

        self::assertArrayNotHasKey('agree_nc', $summary['rows'][0]);
        self::assertArrayHasKey('agree_nc', $summary['rows'][1]);
    }

    public function testAgreementCountsItemsNotHits(): void
    {
        $summary = self::summarize([
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

        $summary = self::summarize($items, self::twoWindows());

        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_nc'], 0.001, 'the excluded day moved nothing');
        self::assertSame(400, $summary['rows'][0]['hits_nc'], 'and it is not counted as measured either');
    }

    public function testCachePercentIsAveragedPerItemSoOneBusyPageCannotCarryIt(): void
    {
        $summary = self::summarize([
            'a' => ['2026-07-01' => self::day(100, 24, 100, 6), '2026-07-05' => self::day(100, 24, 100, 6)],
            'b' => ['2026-07-01' => self::day(100, 24), '2026-07-05' => self::day(100, 24)],
        ], self::twoWindows());

        // One item at 50% cached, one at 0%: the mean is 25%, whatever their sizes.
        self::assertEqualsWithDelta(25.0, $summary['rows'][0]['cache_pct'], 0.01);
        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_cache'], 0.01);
    }

    public function testCachePercentMovesInPercentagePoints(): void
    {
        $summary = self::summarize([
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

        $daily = self::summarize($items, self::twoWindows())['daily'];
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

        $daily = self::summarize($items, self::twoWindows())['daily'];
        $day2  = array_search('2026-07-02', $daily['labels'], true);

        self::assertEqualsWithDelta(100.0, $daily['nc'][$day2], 0.01);
    }

    public function testTheDailyCurveReportsRelativeSpeed(): void
    {
        $daily = self::summarize([
            'a' => self::steady(24, 25),
            'b' => self::steady(24, 25),
        ], self::twoWindows())['daily'];

        // Each item averages 24.5 over the period, so its slow days sit one half
        // step above that and its fast days one half step below.
        self::assertSame(['2026-07-01', '2026-07-02', '2026-07-05', '2026-07-06'], $daily['labels']);
        self::assertEqualsWithDelta(100 / sqrt(self::step()), $daily['nc'][0], 0.05);
        self::assertEqualsWithDelta(100 * sqrt(self::step()), $daily['nc'][3], 0.05);
    }

    public function testAWindowWithNoCachedTrafficReportsNothingRatherThanZero(): void
    {
        $summary = self::summarize([
            'a' => self::steady(24, 24),
            'b' => self::steady(24, 24),
        ], self::twoWindows());

        self::assertNull($summary['rows'][0]['ms_c']);
        self::assertNull($summary['rows'][1]['d_c']);
        self::assertNotNull($summary['rows'][0]['ms_nc']);
    }

    public function testAJustStartedWindowIsListedButNeitherMeasuredNorAllowedToThinTheCohort(): void
    {
        $intervals   = self::twoWindows();
        $intervals[] = self::window((int) strtotime('2026-07-07 09:00:00'), (int) strtotime('2026-07-07 10:00:00'), true);

        $summary = self::summarize([
            'a' => self::steady(24, 24) + ['2026-07-07' => self::day(3, 60)],
            'b' => self::steady(24, 24),   // no hit since the release at all
        ], $intervals);

        self::assertSame(['a', 'b'], $summary['cohort']);
        self::assertCount(3, $summary['rows']);
        self::assertNull($summary['rows'][2]['ms_nc']);
        self::assertArrayNotHasKey('d_nc', $summary['rows'][2]);
        self::assertSame(0, $summary['rows'][2]['hits_nc']);
    }

    /**
     * Two items built at the same speed every day, except that every page takes one
     * index step longer from noon to midnight than before noon - the evening load a
     * production install shows.
     */
    private static function diurnalHours(int $first_day, int $days): array
    {
        $items = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $sid) {
            for ($d = 0; $d < $days; $d++) {
                for ($hour = 0; $hour < 24; $hour++) {
                    $start = (int) mktime($hour, 0, 0, (int) date('n', $first_day), (int) date('j', $first_day) + $d, (int) date('Y', $first_day));
                    $items[$sid][$start] = self::day(1000, $hour < 12 ? 24 : 25);
                }
            }
        }
        return $items;
    }

    public function testTheTimeOfDayDoesNotReadAsASpeedChange(): void
    {
        // A release at noon that changed nothing, then another 42 hours later. Measured
        // whole, the second window would hold 24 afternoon hours to 18 morning ones and
        // read 1.2% slower; measured over its freshest day it reads exactly the same.
        $day     = (int) strtotime('2026-09-07 00:00:00');
        $changes = [
            ['time' => $day + 12 * 3600, 'tree' => 'r1'],
            ['time' => $day + 54 * 3600, 'tree' => 'r2'],
        ];
        $first     = $day - 2 * 86400;
        $intervals = ConfigTimeline::buildIntervals($changes, $first, $day + 60 * 3600, ConfigTimeline::MIN_SPAN, $first);

        $summary = VersionProgress::summarize(self::diurnalHours($first, 5), $intervals);

        self::assertSame(86400, $summary['rows'][1]['interval']['measure_to'] - $summary['rows'][1]['interval']['measure_from']);
        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_nc'], 0.001);
    }

    /**
     * Releases on the given days, afternoon each, as ConfigTimeline::getChanges()
     * returns them. The tree version is the date, so a window says which it holds.
     *
     * @param list<string> $days
     */
    private static function releases(array $days): array
    {
        return array_map(static fn(string $d) => [
            'time' => (int) strtotime($d . ' 15:00:00'),
            'aa'   => '2026.6',
            'tree' => substr(str_replace('-', '', $d), 4),
            'rev'  => '',
            'php'  => '8.2.33',
            'db'   => '',
            'mem'  => '',
            'cpu'  => '',
        ], $days);
    }

    public function testABusyPeriodIsComparedOverTheUsualSpan(): void
    {
        $now     = (int) strtotime('2026-09-11 12:00:00');
        $changes = array_map(static fn(string $d) => ['time' => (int) strtotime($d)], ['2026-08-20', '2026-09-01', '2026-09-05', '2026-09-09', '2026-09-10']);

        self::assertSame((int) strtotime('2026-07-13 00:00:00'), VersionProgress::periodStart($changes, $now, 60));
    }

    public function testAQuietInstallReachesBackForItsLastFewReleases(): void
    {
        $now     = (int) strtotime('2026-09-11 12:00:00');
        $changes = self::releases(['2026-03-02', '2026-06-01', '2026-06-20', '2026-07-01', '2026-08-25']);
        $from    = VersionProgress::periodStart($changes, $now, 60);

        self::assertSame((int) strtotime('2026-06-02 00:00:00'), $from);

        // What that start gives the reader: four rows, the oldest one the release of
        // 1 June alone - the baseline the three newer ones are compared to.
        $intervals = ConfigTimeline::buildIntervals($changes, $from, $now);
        $trees     = array_map(static fn(array $i) => array_column($i['changes'], 'tree'), $intervals);
        self::assertSame([['0601'], ['0620'], ['0701'], ['0825']], $trees);
    }

    public function testReachingBackStopsAtMaxDays(): void
    {
        $now     = (int) strtotime('2026-09-11 12:00:00');
        $changes = [['time' => (int) strtotime('2025-01-01 10:00:00')]];

        self::assertSame(
            (int) strtotime('2026-05-14 00:00:00'),   // MAX_DAYS before 2026-09-11
            VersionProgress::periodStart($changes, $now, 60),
        );
    }

    /** Five items as busy in every window as steady(), keyed by the days given. */
    private static function busy(array $days, int $hits = 100): array
    {
        $items = [];
        foreach (['a', 'b', 'c', 'd', 'e'] as $sid) {
            foreach ($days as $day) {
                $items[$sid][$day] = self::day($hits, 24);
            }
        }
        return $items;
    }

    /** Three day-long windows, each labelled by the release that opened it. */
    private static function threeWindows(): array
    {
        $intervals = self::intervals([['2026-07-01', '2026-07-02'], ['2026-07-03', '2026-07-04'], ['2026-07-05', '2026-07-06']]);
        foreach (['r1', 'r2', 'r3'] as $i => $tree) {
            $intervals[$i]['changes'] = [['time' => $intervals[$i]['from'], 'tree' => $tree]];
        }
        return $intervals;
    }

    public function testAWindowTooThinForTheTrafficIsFoldedIntoTheNextRow(): void
    {
        // The middle release ran through a quiet day: two hits per item, too few for
        // any of them to be measured, which would leave the whole table without a cohort.
        $items = self::busy(['2026-07-01', '2026-07-05']);
        foreach (array_keys($items) as $sid) {
            $items[$sid]['2026-07-03'] = self::day(2, 30);
        }

        $summary = self::summarize($items, self::threeWindows());

        self::assertCount(5, $summary['cohort']);
        self::assertCount(2, $summary['rows']);
        self::assertSame(['r2', 'r3'], array_column($summary['rows'][1]['interval']['changes'], 'tree'), 'the release is named in the next row');
        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_nc'], 0.001, 'and its hits are not in it');
    }

    public function testATooThinNewestWindowStaysListed(): void
    {
        $items = self::busy(['2026-07-01', '2026-07-03']);
        foreach (array_keys($items) as $sid) {
            $items[$sid]['2026-07-05'] = self::day(2, 30);
        }

        $summary = self::summarize($items, self::threeWindows());

        self::assertCount(3, $summary['rows']);
        self::assertTrue($summary['rows'][2]['interval']['thin']);
        self::assertTrue($summary['rows'][2]['interval']['short'], 'listed, not measured');
        self::assertNull($summary['rows'][2]['ms_nc']);
        self::assertCount(5, $summary['cohort']);
    }

    public function testABusyInstallKeepsEveryWindow(): void
    {
        $summary = self::summarize(self::busy(['2026-07-01', '2026-07-03', '2026-07-05']), self::threeWindows());

        self::assertCount(3, $summary['rows']);
        self::assertFalse($summary['rows'][2]['interval']['thin']);
    }

    public function testEachItemsHitsPerWindowAreReported(): void
    {
        $summary = self::summarize([
            'a' => self::steady(24, 24, 100, 30),
            'b' => self::steady(24, 24, 50),
        ], self::twoWindows());

        self::assertSame([0 => [200, 60], 1 => [200, 60]], $summary['items']['a']);
        self::assertSame([0 => [100, 0], 1 => [100, 0]], $summary['items']['b']);
        self::assertSame(300, $summary['rows'][1]['hits_nc']);
        self::assertSame(60, $summary['rows'][1]['hits_c'], 'cached hits of the items the cached column rests on');
    }
}
