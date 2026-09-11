<?php

declare(strict_types=1);

// whenCells() renders through safe() and _m(), which live in include/util.php and
// pull in the locsess boot. Safe test values for the constants include/config.php
// normally provides, defined before the autoloader resolves the first legacy class
// (same block as ConfigTimelineTest).

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Util {


    use AA\Util\ProgressPanel;
    use PHPUnit\Framework\TestCase;

    /**
     * The claim both progress panels make, and the sign test they make it with.
     *
     * VersionProgress and TaskProgress each colour a percentage only once enough of
     * their cohort moved the same way, so where that line falls is the one piece of
     * arithmetic they must not disagree about.
     */
    final class ProgressPanelTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        public function testASplitIsCalledRealOnlyWhenEnoughItemsAgree(): void
        {
            self::assertTrue(ProgressPanel::isSignificant(['faster' => 13, 'total' => 17]));
            self::assertFalse(ProgressPanel::isSignificant(['faster' => 12, 'total' => 17]), 'twelve of seventeen is a coin flip');
            self::assertTrue(ProgressPanel::isSignificant(['faster' => 4, 'total' => 17]), 'lopsided the other way counts too');
            self::assertFalse(ProgressPanel::isSignificant(['faster' => 3, 'total' => 3]), 'three items can agree by chance');
            self::assertFalse(ProgressPanel::isSignificant(['faster' => 0, 'total' => 0]));
        }

        public function testAgreementCountsMembersThatMoved(): void
        {
            $values = [
                'a' => [0 => 2.0, 1 => 1.0],    // faster
                'b' => [0 => 2.0, 1 => 1.0],    // faster
                'c' => [0 => 1.0, 1 => 2.0],    // slower
                'd' => [0 => 1.0, 1 => 1.0],    // unchanged, so it does not vote
                'e' => [1 => 1.0],              // measurable in one window only
            ];

            self::assertSame(
                ['faster' => 2, 'total' => 3],
                ProgressPanel::agreement($values, ['a', 'b', 'c', 'd', 'e'], 1),
            );
        }

        public function testOnlyTheCohortVotes(): void
        {
            $values = [
                'a' => [0 => 2.0, 1 => 1.0],
                'b' => [0 => 1.0, 1 => 2.0],
            ];

            self::assertSame(['faster' => 1, 'total' => 1], ProgressPanel::agreement($values, ['a'], 1));
        }


        /** An interval as ConfigTimeline::buildIntervals() hands it over. */
        private static function interval(string $from, string $to, int $change_time = 0, bool $exact = false): array
        {
            $start = (int) strtotime($exact ? $from : $from . ' 00:00:00');
            $end   = (int) strtotime($exact ? $to : $to . ' 00:00:00');
            return [
                'from'         => $start,
                'to'           => $end,
                'span'         => $end - $start,
                'measure_from' => $start,
                'measure_to'   => $end,
                'days'         => ($end - $start) / 86400,
                'exact'        => $exact,
                'changes'      => $change_time ? [['time' => $change_time, 'aa' => '', 'tree' => '5348M', 'rev' => '', 'php' => '', 'db' => '', 'mem' => '', 'cpu' => '']] : [],
                'short'        => false,
            ];
        }

        public function testTheDateShownIsTheWindowStartNotTheMomentTheChangeWasLogged(): void
        {
            // The change was detected on the 7th at 10:14; the window it opens starts
            // on the 8th, because the change day belongs to no window. Showing the
            // log timestamp would contradict the day count and the tooltip beside it.
            $cells = ProgressPanel::whenCells(
                self::interval('2026-07-08', '2026-07-15', (int) strtotime('2026-07-07 10:14:00')),
                null,
                'tooltip',
            );

            self::assertStringContainsString('2026-07-08', $cells);
            self::assertStringNotContainsString('2026-07-07', $cells);
            self::assertStringContainsString('7 days', $cells);
        }

        public function testTheLeadingWindowIsDatedFromWhenMeasurementStarted(): void
        {
            // Whatever was already running started long before the measured period,
            // so its own start date says nothing about the row.
            $cells = ProgressPanel::whenCells(
                self::interval('2026-07-01', '2026-07-08', (int) strtotime('2026-05-02 08:00:00')),
                null,
                'tooltip',
            );

            self::assertStringContainsString('2026-07-01', $cells);
            self::assertStringNotContainsString('2026-05-02', $cells);
        }

        public function testAWindowCutAtTheChangeShowsTheTimeAndItsLengthInHours(): void
        {
            $cells = ProgressPanel::whenCells(
                self::interval('2026-09-09 15:58:14', '2026-09-10 06:36:08', (int) strtotime('2026-09-09 15:58:14'), true),
                null,
                'tooltip',
            );

            self::assertStringContainsString('2026-09-09 15:58', $cells);
            self::assertStringContainsString('15 h', $cells);
        }

        public function testALengthReadsInTheUnitThatFitsIt(): void
        {
            self::assertSame('40 min', ProgressPanel::spanLabel(40 * 60));
            self::assertSame('0 min', ProgressPanel::spanLabel(0));
            self::assertSame('15 h', ProgressPanel::spanLabel(52680));
            self::assertSame('40 h', ProgressPanel::spanLabel(40 * 3600));
            self::assertSame('6 days', ProgressPanel::spanLabel(6 * 86400 - 3600), 'a DST week is still six days');
        }

        public function testAWindowTooShortToMeasureSaysWhenItWillBe(): void
        {
            $cell = ProgressPanel::tooEarlyCell(4);

            self::assertStringContainsString('colspan="4"', $cell);
            self::assertStringContainsString('24 h', $cell);
        }
    }
}
