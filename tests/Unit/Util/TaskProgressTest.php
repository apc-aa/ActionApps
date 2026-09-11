<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\TaskProgress;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind the Queue progress panel.
 *
 * Queue durations are far rougher material than page hits: an import takes as
 * long as the work that arrived, so the same task can run in a tenth of a
 * second one hour and a minute and a half the next. Every rule checked here
 * exists to keep that from being read as a speed change.
 */
final class TaskProgressTest extends TestCase
{
    /** @param list<array{string,string,float}> $runs [day, task, seconds] */
    private static function runs(array $runs): array
    {
        $out = [];
        foreach ($runs as [$day, $task, $secs]) {
            $out[$task][] = ['time' => (int) strtotime($day . ' 12:00:00'), 'secs' => $secs];
        }
        return $out;
    }

    /** The same run repeated, so a task clears the minimum-runs bar. */
    private static function repeat(string $day, string $task, float $secs, int $times): array
    {
        $out = [];
        for ($i = 0; $i < $times; $i++) {
            $out[] = [$day, $task, $secs];
        }
        return $out;
    }

    private static function twoWindows(): array
    {
        return [self::window('2026-07-01', '2026-07-03'), self::window('2026-07-05', '2026-07-07')];
    }

    /** A window as ConfigTimeline::buildIntervals() hands it over. */
    private static function window(string $from, string $to, bool $short = false): array
    {
        $start = (int) strtotime($from . ' 00:00:00');
        $end   = (int) strtotime($to . ' 00:00:00');
        return [
            'from'         => $start,
            'to'           => $end,
            'span'         => $end - $start,
            'measure_from' => $start,
            'measure_to'   => $end,
            'days'         => ($end - $start) / 86400,
            'exact'        => false,
            'changes'      => [],
            'short'        => $short,
        ];
    }

    /** Two tasks that both ran often enough in both windows, at the given durations. */
    private static function steadyPair(float $a_before, float $a_after, float $b_before, float $b_after): array
    {
        return self::runs(array_merge(
            self::repeat('2026-07-01', 'alpha', $a_before, 25),
            self::repeat('2026-07-05', 'alpha', $a_after, 25),
            self::repeat('2026-07-01', 'beta', $b_before, 25),
            self::repeat('2026-07-05', 'beta', $b_after, 25),
        ));
    }

    public function testTheSameTaskLoggedByTwoAaVersionsIsOneTask(): void
    {
        // Real data from a live install: the selector gained a ::Obj suffix mid-period
        // when AA started writing it through AA_Logentry_Obj. Read literally, every
        // task splits in two exactly at the upgrade being measured.
        self::assertSame('HitcounterUpdate', TaskProgress::taskName('HitcounterUpdate::Obj'));
        self::assertSame('HitcounterUpdate', TaskProgress::taskName('HitcounterUpdate'));
        self::assertSame('PlannedtaskSchedule', TaskProgress::taskName('PlannedtaskSchedule::Obj'));

        // Everything that is not that suffix is left exactly as the operator sees it
        // in the log, including a task whose own name ends in a colon.
        self::assertSame('Importer:feed&id=56&type=RSS', TaskProgress::taskName('Importer:feed&id=56&type=RSS'));
        self::assertSame('AA_Mail::', TaskProgress::taskName('AA_Mail::'));
        self::assertSame('', TaskProgress::taskName('  '));
    }

    public function testABusyQueueIsSampledRatherThanTruncated(): void
    {
        // A production install logged 142 000 runs in a month. Reading them all costs
        // tens of megabytes for numbers a sample answers just as well; reading the
        // first N instead would drop whole windows.
        self::assertSame(1, TaskProgress::sampleEvery(0));
        self::assertSame(1, TaskProgress::sampleEvery(20000), 'a queue that fits is read whole');
        self::assertSame(2, TaskProgress::sampleEvery(20001));
        self::assertSame(8, TaskProgress::sampleEvery(142202));
    }

    public function testATaskThatDidNotRunInEveryWindowCannotSpeak(): void
    {
        $runs = self::runs(array_merge(
            self::repeat('2026-07-01', 'alpha', 1.0, 25),
            self::repeat('2026-07-05', 'alpha', 1.0, 25),
            self::repeat('2026-07-05', 'newcomer', 1.0, 25),
        ));

        $summary = TaskProgress::summarize($runs, self::twoWindows());

        self::assertSame(['alpha'], $summary['cohort']);
    }

    public function testATaskThatBarelyRanCannotSpeakEither(): void
    {
        $runs = self::runs(array_merge(
            self::repeat('2026-07-01', 'alpha', 1.0, 25),
            self::repeat('2026-07-05', 'alpha', 1.0, 25),
            self::repeat('2026-07-01', 'rare', 1.0, 3),      // below TaskProgress::MIN_RUNS
            self::repeat('2026-07-05', 'rare', 1.0, 25),
        ));

        $summary = TaskProgress::summarize($runs, self::twoWindows());

        self::assertSame(['alpha'], $summary['cohort']);
    }

    public function testOneEnormousRunDoesNotMoveTheTypicalTask(): void
    {
        // A single import that took ten minutes is the queue doing its job, not the
        // queue getting slower. The median must not notice it.
        $runs = self::steadyPair(1.0, 1.0, 1.0, 1.0);
        $runs['alpha'][] = ['time' => (int) strtotime('2026-07-05 13:00:00'), 'secs' => 600.0];

        $summary = TaskProgress::summarize($runs, self::twoWindows());

        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_typical'], 0.01);
    }

    public function testAFastTaskAndASlowOneCountTheSame(): void
    {
        // alpha halves, beta doubles. Their geometric mean is unchanged, which is the
        // point: a 0.1 s task improving is worth as much as a 60 s task improving.
        $summary = TaskProgress::summarize(self::steadyPair(0.2, 0.1, 60.0, 120.0), self::twoWindows());

        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_typical'], 0.01);
        self::assertSame(['faster' => 1, 'total' => 2], $summary['rows'][1]['agree']);
    }

    public function testHalvingEveryTaskReadsAsHalfTheTime(): void
    {
        $summary = TaskProgress::summarize(self::steadyPair(2.0, 1.0, 8.0, 4.0), self::twoWindows());

        self::assertEqualsWithDelta(-50.0, $summary['rows'][1]['d_typical'], 0.01);
        self::assertSame(['faster' => 2, 'total' => 2], $summary['rows'][1]['agree']);
    }

    public function testQueueTimePerDayIsRealSecondsOverRealDays(): void
    {
        // 25 runs of 2 s plus 25 of 8 s = 250 s, over a two-day window.
        $summary = TaskProgress::summarize(self::steadyPair(2.0, 1.0, 8.0, 4.0), self::twoWindows());

        self::assertEqualsWithDelta(125.0, $summary['rows'][0]['seconds'], 0.01);
        self::assertEqualsWithDelta(62.5, $summary['rows'][1]['seconds'], 0.01);
        self::assertEqualsWithDelta(-50.0, $summary['rows'][1]['d_seconds'], 0.01);
    }

    public function testRunsPerDayCountsTheCohortOverTheWindow(): void
    {
        $summary = TaskProgress::summarize(self::steadyPair(1.0, 1.0, 1.0, 1.0), self::twoWindows());

        self::assertSame(50, $summary['rows'][0]['run_total']);
        self::assertEqualsWithDelta(25.0, $summary['rows'][0]['runs'], 0.01, '50 runs over two days');
    }

    public function testRunsBetweenTheWindowsAreLeftOut(): void
    {
        // 2026-07-04 is a configuration-change day: it belongs to no window.
        $runs = self::steadyPair(1.0, 1.0, 1.0, 1.0);
        foreach (self::repeat('2026-07-04', 'alpha', 900.0, 5) as [$day, $task, $secs]) {
            $runs[$task][] = ['time' => (int) strtotime($day . ' 12:00:00'), 'secs' => $secs];
        }

        $summary = TaskProgress::summarize($runs, self::twoWindows());

        // 50 runs of 1 s over two days. The five 900 s runs on the excluded day would
        // have made it 2275 s per day had they counted.
        self::assertEqualsWithDelta(25.0, $summary['rows'][0]['seconds'], 0.01);
        self::assertEqualsWithDelta(0.0, $summary['rows'][1]['d_typical'], 0.01);
    }

    public function testTheFirstWindowCarriesNoComparison(): void
    {
        $summary = TaskProgress::summarize(self::steadyPair(1.0, 1.0, 1.0, 1.0), self::twoWindows());

        self::assertArrayNotHasKey('d_typical', $summary['rows'][0]);
        self::assertArrayNotHasKey('agree', $summary['rows'][0]);
        self::assertNotNull($summary['rows'][0]['typical']);
    }

    public function testAQueueWithNothingComparableReportsNoCohort(): void
    {
        $summary = TaskProgress::summarize(self::runs(self::repeat('2026-07-01', 'alpha', 1.0, 25)), self::twoWindows());

        self::assertSame([], $summary['cohort']);
        self::assertNull($summary['rows'][1]['typical']);
        self::assertSame(0, $summary['rows'][1]['run_total']);
    }

    public function testAJustStartedWindowDoesNotThinTheCohort(): void
    {
        // A release detected today: listed, but no task has run under it enough to
        // say anything, and none of them must drop out of the comparison for that.
        $intervals   = self::twoWindows();
        $intervals[] = self::window('2026-07-07', '2026-07-07', true);

        $summary = TaskProgress::summarize(self::steadyPair(2.0, 1.0, 8.0, 4.0), $intervals);

        self::assertSame(['alpha', 'beta'], $summary['cohort']);
        self::assertNull($summary['rows'][2]['typical']);
        self::assertArrayNotHasKey('d_seconds', $summary['rows'][2], 'no "-100%" against a window with no runs yet');
    }

    public function testAWindowShorterThanADayIsNotCountedAsAWholeOne(): void
    {
        // The spring DST day has 23 hours; 23 runs in it are 24 a day, not 23.
        $intervals            = self::twoWindows();
        $intervals[1]['days'] = 23 / 24;

        $summary = TaskProgress::summarize(self::runs(array_merge(
            self::repeat('2026-07-01', 'alpha', 1.0, 46),
            self::repeat('2026-07-05', 'alpha', 1.0, 23),
        )), $intervals);

        self::assertEqualsWithDelta(24.0, $summary['rows'][1]['runs'], 0.001);
    }
}
