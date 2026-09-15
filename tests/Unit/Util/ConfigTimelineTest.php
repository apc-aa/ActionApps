<?php

declare(strict_types=1);

// ConfigTimeline parses SYS_STAT rows through AA_Logentry, whose property objects
// live in include/widget.class.php and pull in the locsess boot. Safe test values
// for the constants include/config.php normally provides, defined before the
// autoloader resolves the first legacy class (same block as PropertyLazyEnumTest).

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

    use AA\Util\ConfigTimeline;
    use PHPUnit\Framework\TestCase;

    /**
     * ConfigTimeline turns the SYS_STAT log into measurement windows.
     *
     * The two behaviours worth pinning are the ones that came out of real data:
     * installs whose `svnversion` fails report an empty tree version every other
     * run (37 rows a day on one install), and hit_archive rows older than a week
     * carry their whole day's traffic at midnight, so the day a deployment landed
     * on cannot be attributed to either side of it. Within the last week it can,
     * and a week of daily deployments has to come out as a row a day.
     */
    final class ConfigTimelineTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        /** A SYS_STAT params string as LogSystemstatus writes it. No trailing field
         *  without $server, which is how a row from before that field looks. */
        private static function sys(string $tree, string $rev, string $php = '8.2.33', string $db = '10.11.19-MariaDB-log/DB_PDO', string $mem = '128318 MB', string $server = ''): string
        {
            $row = "Sys:2026.6:$tree ($rev):$php:$db:$mem:AMD Ryzen 9 5950X 16-Core Processor";
            return $server === '' ? $row : "$row:$server";
        }

        /** @param list<array{int,string}> $rows [time, params] */
        private static function fold(array $rows): array
        {
            return ConfigTimeline::foldChanges(array_map(
                static fn(array $r) => ['time' => $r[0], 'params' => $r[1]],
                $rows,
            ));
        }

        private static function day(string $date): int
        {
            return (int) strtotime($date . ' 00:00:00');
        }

        public function testReadsAGitBuildStampAsTheTreeVersion(): void
        {
            // rows written by a dist checkout after the git migration: the main commit is tree and revision alike
            $changes = self::fold([[1000, self::sys('5382M', '5365')], [2000, self::sys('a1b2c3d', 'a1b2c3d')], [3000, self::sys('a1b2c3d', 'a1b2c3d')]]);

            self::assertSame(['5382M', 'a1b2c3d'], array_column($changes, 'tree'), 'the first git build is one deployment, the repeat row none');
            self::assertSame('a1b2c3d', $changes[1]['rev']);
        }

        public function testReadsTheFieldsOfASysStatRow(): void
        {
            $changes = self::fold([[1000, self::sys('5348M', '5345')]]);

            self::assertCount(1, $changes);
            self::assertSame(1000, $changes[0]['time']);
            self::assertSame('2026.6', $changes[0]['aa']);
            self::assertSame('5348M', $changes[0]['tree']);
            self::assertSame('5345', $changes[0]['rev']);
            self::assertSame('8.2.33', $changes[0]['php']);
            self::assertSame('10.11.19-MariaDB-log/DB_PDO', $changes[0]['db']);
        }

        public function testTheSameConfigurationLoggedAgainIsNotAChange(): void
        {
            $changes = self::fold([
                [1000, self::sys('5348M', '5345')],
                [2000, self::sys('5348M', '5345')],
                [3000, self::sys('5348M', '5345')],
            ]);

            self::assertCount(1, $changes);
        }

        public function testAnUnreadableTreeVersionIsNotAChange(): void
        {
            // The real flapping seen on rsync-synced installs: svnversion answers,
            // then fails, then answers again, several times an hour.
            $changes = self::fold([
                [1000, self::sys('5348M', '5345')],
                [2000, 'Sys:2026.6: (5345):8.2.33:10.11.19-MariaDB-log/DB_PDO:128318 MB:AMD Ryzen 9 5950X 16-Core Processor'],
                [3000, 'Sys:2026.6:Unversioned directory (5345):8.2.33:10.11.19-MariaDB-log/DB_PDO:128318 MB:AMD Ryzen 9 5950X 16-Core Processor'],
                [4000, self::sys('5348M', '5345')],
            ]);

            self::assertCount(1, $changes, 'one deployment, logged four times');
            self::assertSame('5348M', $changes[0]['tree']);
        }

        public function testTwoInstallsWritingToOneDatabaseDoNotReadAsDeployments(): void
        {
            // The aadev pattern: one tree reports its svnversion, the other cannot read
            // .svn and also runs a different util.php, so the two series disagree on
            // both halves and interleave every few minutes.
            $rows = [];
            $time = 1788404000;
            for ($i = 0; $i < 6; $i++) {
                $rows[] = [$time += 900, self::sys('5321M', '5314')];
                $rows[] = [$time += 900, 'Sys:2026.6:Unversioned directory (5332):8.2.33:10.11.19-MariaDB-log/DB_PDO:128318 MB:AMD Ryzen 9 5950X 16-Core Processor'];
            }
            $rows[] = [$time + 900, self::sys('5338M', '5332')];   // a real deployment lands

            $changes = self::fold($rows);

            self::assertLessThanOrEqual(3, count($changes), 'thirteen interleaved rows are not thirteen deployments');
            self::assertSame('5338M', $changes[count($changes) - 1]['tree'], 'the real deployment is the newest configuration');

            // What the reader ends up seeing: one measurable window, named after the
            // deployment. The interleaved rows are minutes apart, so every window they
            // open is dropped as unmeasurable and their labels fold into this one.
            $intervals = ConfigTimeline::buildIntervals($changes, $rows[0][0], $rows[0][0] + (10 * 86400));
            self::assertCount(1, $intervals);
            self::assertSame(['r5321M, r5338M'], ConfigTimeline::describeDiff($intervals[0]['changes'], null));
        }

        public function testAConfigurationThatStoodForADayIsNotTreatedAsFlapping(): void
        {
            $day = 86400;
            $changes = self::fold([
                [1000, self::sys('5321M', '5314')],
                [1000 + (2 * $day), self::sys('5338M', '5332')],
                [1000 + (4 * $day), self::sys('5321M', '5314')],   // a genuine rollback
            ]);

            self::assertSame(['5321M', '5338M', '5321M'], array_column($changes, 'tree'));
        }

        public function testDeploymentsSharingAUtilPhpRevisionStayApart(): void
        {
            // Three deployments in a row where only the tree moved; util.php was
            // untouched, so the parenthesised revision is identical in all three.
            $changes = self::fold([
                [1000, self::sys('5300M', '5297')],
                [2000, self::sys('5308M', '5297')],
                [3000, self::sys('5313M', '5297')],
            ]);

            self::assertSame(['5300M', '5308M', '5313M'], array_column($changes, 'tree'));
        }

        public function testAPhpOrDatabaseUpgradeIsAChangeOfItsOwn(): void
        {
            $changes = self::fold([
                [1000, self::sys('5313M', '5297', '8.2.32', '10.11.16-MariaDB-log/DB_PDO')],
                [2000, self::sys('5313M', '5297', '8.2.32', '10.11.18-MariaDB-log/DB_PDO')],
                [3000, self::sys('5313M', '5297', '8.2.33', '10.11.18-MariaDB-log/DB_PDO')],
            ]);

            self::assertCount(3, $changes);
        }

        public function testAnUnparsableRowIsSkipped(): void
        {
            $changes = self::fold([
                [1000, 'X:0.9:4.4:7.09:37'],
                [2000, self::sys('5348M', '5345')],
            ]);

            self::assertCount(1, $changes);
            self::assertSame('5348M', $changes[0]['tree']);
        }

        public function testTheChangeDayBelongsToNeitherWindow(): void
        {
            $changes = self::fold([[(int) strtotime('2026-07-07 10:14:00'), self::sys('5313M', '5297')]]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-07-01'), self::day('2026-07-15'));

            self::assertCount(2, $intervals);
            self::assertSame(self::day('2026-07-01'), $intervals[0]['from']);
            self::assertSame(self::day('2026-07-07'), $intervals[0]['to'], 'the window before ends at the change day');
            self::assertSame(self::day('2026-07-08'), $intervals[1]['from'], 'the window after starts the day behind it');
            self::assertSame(6.0, $intervals[0]['days']);
            self::assertSame(7.0, $intervals[1]['days']);
            self::assertFalse($intervals[1]['exact']);
        }

        public function testTheLeadingWindowIsLabelledByWhatWasAlreadyRunning(): void
        {
            $changes = self::fold([
                [(int) strtotime('2026-06-01 08:00:00'), self::sys('5300M', '5297')],
                [(int) strtotime('2026-07-07 10:14:00'), self::sys('5313M', '5297')],
            ]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-07-01'), self::day('2026-07-15'));

            self::assertSame('5300M', $intervals[0]['changes'][0]['tree']);
            self::assertSame('5313M', $intervals[1]['changes'][0]['tree']);
        }

        public function testWindowsTooShortToMeasureAreDroppedAndTheirDeploymentsCarriedOn(): void
        {
            // Four deployments inside one afternoon, then a week of quiet. The
            // afternoon cannot be measured; the week that follows reports all of them.
            $changes = self::fold([
                [(int) strtotime('2026-05-27 00:46:00'), self::sys('5242M', '5242')],
                [(int) strtotime('2026-05-27 12:58:00'), self::sys('5245M', '5242')],
                [(int) strtotime('2026-05-28 16:12:00'), self::sys('5258M', '5254')],
            ]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-05-20'), self::day('2026-06-05'));

            self::assertCount(2, $intervals, 'the one-day window between the 27th and the 28th is not measurable');
            self::assertSame(self::day('2026-05-29'), $intervals[1]['from']);
            self::assertSame(['5242M', '5245M', '5258M'], array_column($intervals[1]['changes'], 'tree'));
            self::assertNull($intervals[1]['running'], 'what the row before it ran: nothing, the dropped windows are this row');
        }

        public function testTheNewestWindowIsKeptEvenWhenItIsStillShort(): void
        {
            $changes = self::fold([[(int) strtotime('2026-09-07 23:46:00'), self::sys('5348M', '5345')]]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-09-01'), (int) strtotime('2026-09-08 05:00:00'));

            self::assertCount(2, $intervals);
            self::assertTrue($intervals[1]['short'], 'a release from last night is reported, and flagged as not yet judgeable');
            self::assertFalse($intervals[0]['short']);
        }

        public function testAReleaseDetectedTodayIsListedEvenOnWholeDays(): void
        {
            // On the whole-day grid its window would open tomorrow. Dropping it would drop
            // its label too, and the reader would take the release before for the one running.
            $changes = self::fold([
                [(int) strtotime('2026-09-01 10:00:00'), self::sys('5338M', '5332')],
                [(int) strtotime('2026-09-11 09:24:00'), self::sys('5379M', '5365')],
            ]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-08-20'), (int) strtotime('2026-09-11 12:00:00'));

            $newest = $intervals[count($intervals) - 1];
            self::assertTrue($newest['short']);
            self::assertSame((int) strtotime('2026-09-11 09:24:00'), $newest['from'], 'running since the change, not since tomorrow');
            self::assertTrue($newest['exact']);
            self::assertSame('5379M', $newest['changes'][0]['tree']);
        }

        public function testADayOfItsOwnIsEnoughForARow(): void
        {
            $changes = self::fold([
                [(int) strtotime('2026-07-07 10:14:00'), self::sys('5313M', '5297')],
                [(int) strtotime('2026-07-09 16:00:00'), self::sys('5320M', '5297')],
            ]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-07-01'), self::day('2026-07-15'));

            self::assertCount(3, $intervals, 'the 8th ran r5313M alone, so it is measured on its own');
            self::assertSame(self::day('2026-07-08'), $intervals[1]['from']);
            self::assertSame(self::day('2026-07-09'), $intervals[1]['to']);
        }

        public function testWithinTheExactWeekAWindowStartsAtTheChangeItself(): void
        {
            $t = static fn(string $when) => (int) strtotime($when);
            $changes = self::fold([
                [$t('2026-09-01 10:00:00'), self::sys('5338M', '5332')],
                [$t('2026-09-05 15:58:14'), self::sys('5348M', '5345')],
                [$t('2026-09-07 06:36:08'), self::sys('5363M', '5345')],
                [$t('2026-09-08 09:00:00'), self::sys('5364M', '5345')],
                [$t('2026-09-09 09:24:07'), self::sys('5379M', '5365')],
                [$t('2026-09-09 11:48:08'), self::sys('5382M', '5365')],
            ]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-08-28'), $t('2026-09-09 12:00:00'), ConfigTimeline::MIN_SPAN, self::day('2026-09-04'));

            $trees = array_map(static fn(array $i) => array_column($i['changes'], 'tree'), $intervals);
            self::assertSame([['5348M'], ['5363M'], ['5364M'], ['5379M', '5382M']], array_slice($trees, -4));

            [$fri, $mon, $tue, $today] = array_slice($intervals, -4);
            self::assertSame($t('2026-09-05 15:58:14'), $fri['from'], 'cut at the change, not at the next midnight');
            self::assertSame($t('2026-09-07 06:36:08'), $fri['to']);
            self::assertTrue($fri['exact']);
            self::assertSame($t('2026-09-06 06:00:00'), $fri['measure_from'], 'measured over its freshest whole day, to the hour');
            self::assertSame($t('2026-09-07 06:00:00'), $fri['measure_to']);
            self::assertSame($t('2026-09-07 09:00:00'), $mon['measure_from'], 'the hour the change cut through is not measured');
            self::assertSame(1.0, $tue['days'], 'a full day of 24 hours');
            self::assertSame($t('2026-09-09 09:24:07'), $today['from'], 'the morning release opens the newest window; the noon one is listed inside it');
            self::assertTrue($today['short']);
        }

        public function testABurstOfReleasesStillGetsARowADay(): void
        {
            // Five releases in 44 hours on a live install. Read strictly, only the first
            // of them has a day of its own, and the other four would fold into the window
            // after them: two days of development shown as one row naming five revisions.
            $t = static fn(string $when) => (int) strtotime($when);
            $changes = self::fold([
                [$t('2026-09-07 23:46:06'), self::sys('5348M', '5345')],
                [$t('2026-09-09 15:46:02'), self::sys('5348#:5363M', '5345')],   // a mixed-revision tree; the log escapes its colon
                [$t('2026-09-10 06:44:12'), self::sys('5364M', '5345')],
                [$t('2026-09-11 00:44:29'), self::sys('5378M', '5365')],
                [$t('2026-09-11 09:26:48'), self::sys('5379M', '5365')],
                [$t('2026-09-11 11:52:02'), self::sys('5382M', '5365')],
            ]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-09-01'), $t('2026-09-14 09:35:00'), ConfigTimeline::MIN_SPAN, self::day('2026-09-07'));

            $trees = array_map(static fn(array $i) => array_column($i['changes'], 'tree'), $intervals);
            self::assertSame([['5348M'], ['5348:5363M', '5364M'], ['5378M', '5379M', '5382M'], []], array_slice($trees, -4));

            [, $second, $third, $rest] = array_slice($intervals, -4);
            self::assertSame($t('2026-09-09 15:46:02'), $second['from'], 'the day opens at the release that could not have a day of its own');
            self::assertSame($t('2026-09-10 15:46:02'), $second['to'], 'and runs for exactly a day, the release inside it included');
            self::assertSame('5348M', $second['running']['tree']);
            self::assertSame($t('2026-09-10 15:46:02'), $third['from'], 'the next day opens at the cut, not at a release');
            self::assertSame($t('2026-09-11 15:46:02'), $third['to']);
            self::assertFalse($third['short']);
            self::assertSame('5364M', $third['running']['tree'], 'in force when it opened: the last release of the day before');
            self::assertSame($t('2026-09-11 15:46:02'), $rest['from'], 'what is left runs the last release, cleanly');
            self::assertSame('5382M', $rest['running']['tree']);
        }

        public function testDiffFallsBackToTheUtilPhpRevisionWhenTheTreeDidNotMove(): void
        {
            // An rsync-synced checkout: svnversion has said 3919:5208M for a year,
            // while util.php moved with every release.
            $before = self::fold([[1000, self::sys('3919#:5208M', '5314')]])[0];
            $after  = self::fold([[2000, self::sys('3919#:5208M', '5332')]])[0];

            self::assertSame(['util.php r5332'], ConfigTimeline::describeDiff([$after], $before));
        }

        public function testDiffNamesAMemoryChange(): void
        {
            $before = self::fold([[1000, self::sys('5348M', '5345')]])[0];
            $after  = self::fold([[2000, self::sys('5348M', '5345', mem: '256000 MB')]])[0];

            self::assertSame(['memory 256000 MB'], ConfigTimeline::describeDiff([$after], $before));
        }

        public function testServerSettingsReadShortAndRoundTrip(): void
        {
            $variables = ['INNODB_FLUSH_LOG_AT_TRX_COMMIT' => '1', 'SYNC_BINLOG' => '1', 'LOG_BIN_COMPRESS' => 'OFF', 'INNODB_DOUBLEWRITE' => 'OFF', 'INNODB_BUFFER_POOL_SIZE' => '42949672960', 'max_connections' => '350'];

            self::assertSame('f1 s1 c0 d0 b40G', ConfigTimeline::encodeServer($variables));
            self::assertSame('b1536M', ConfigTimeline::encodeServer(['innodb_buffer_pool_size' => '1610612736']));
            self::assertSame('', ConfigTimeline::encodeServer([]));
            self::assertSame(
                ['innodb_flush_log_at_trx_commit' => '1', 'sync_binlog' => '1', 'log_bin_compress' => '0', 'innodb_doublewrite' => '0', 'innodb_buffer_pool_size' => '40G'],
                ConfigTimeline::decodeServer('f1 s1 c0 d0 b40G'),
            );
        }

        public function testAChangedServerSettingIsAChangeAndIsNamed(): void
        {
            $changes = self::fold([
                [1000, self::sys('5348M', '5345')],   // from before the field existed
                [2000, self::sys('5348M', '5345', server: 'f1 s1 c0 d0 b40G')],
                [3000, self::sys('5348M', '5345', server: 'f2 s0 c1 d0 b40G')],
                [4000, self::sys('5348M', '5345', server: 'f2 s0 c1 d0 b40G')],
            ]);

            // The first row carrying settings is not a deployment: it only says what the
            // configuration already running had been on, and is folded into it.
            self::assertCount(2, $changes);
            self::assertSame(1000, $changes[0]['time']);
            self::assertSame('f1 s1 c0 d0 b40G', $changes[0]['server']);
            self::assertSame(
                ['innodb_flush_log_at_trx_commit 1 → 2', 'sync_binlog 1 → 0', 'log_bin_compress 0 → 1'],
                ConfigTimeline::describeDiff([$changes[1]], $changes[0]),
            );
        }

        public function testARowWithoutServerSettingsDoesNotUnsetThem(): void
        {
            // Two trees on one database, one of them an older AA that writes the row
            // without the field: the rows alternate, the configuration does not.
            $changes = self::fold([
                [1000, self::sys('5348M', '5345', server: 'f1 s1 c0 d0 b40G')],
                [2000, self::sys('5348M', '5345')],
                [3000, self::sys('5348M', '5345', server: 'f1 s1 c0 d0 b40G')],
                [4000, self::sys('5348M', '5345')],
            ]);

            self::assertCount(1, $changes);
            self::assertSame('f1 s1 c0 d0 b40G', $changes[0]['server']);
        }

        public function testAWindowOfSeveralDaysIsMeasuredOverItsFreshestWholeDays(): void
        {
            $t = static fn(string $when) => (int) strtotime($when);
            $changes = self::fold([
                [$t('2026-09-04 01:38:00'), self::sys('5338M', '5332')],
                [$t('2026-09-07 23:36:00'), self::sys('5348M', '5345')],
            ]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-08-28'), $t('2026-09-09 12:00:00'), ConfigTimeline::MIN_SPAN, self::day('2026-09-04'));

            // 94 hours: three whole days, counted back from 23:00, the last full hour.
            self::assertSame($t('2026-09-04 23:00:00'), $intervals[1]['measure_from']);
            self::assertSame($t('2026-09-07 23:00:00'), $intervals[1]['measure_to']);
            self::assertSame(3.0, $intervals[1]['days']);
            // The leading window lies on whole-day data and ends inside the exact week:
            // it is measured up to its last midnight.
            self::assertSame(self::day('2026-08-28'), $intervals[0]['measure_from']);
            self::assertSame(self::day('2026-09-04'), $intervals[0]['measure_to']);
        }

        public function testAChangeBeforeTheExactWeekStillCostsItsWholeDay(): void
        {
            $changes = self::fold([
                [(int) strtotime('2026-09-02 15:00:00'), self::sys('5338M', '5332')],
                [(int) strtotime('2026-09-06 15:00:00'), self::sys('5348M', '5345')],
            ]);

            $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-08-28'), self::day('2026-09-10'), ConfigTimeline::MIN_SPAN, self::day('2026-09-04'));

            self::assertSame(self::day('2026-09-02'), $intervals[0]['to']);
            self::assertSame(self::day('2026-09-03'), $intervals[1]['from']);
            self::assertSame((int) strtotime('2026-09-06 15:00:00'), $intervals[1]['to'], 'the window runs into the exact week up to the change');
        }

        public function testTheDayAfterAChangeStartsAtMidnightAcrossADstSwitch(): void
        {
            $tz = date_default_timezone_get();
            date_default_timezone_set('Europe/Prague');
            try {
                // 2026-10-25 is 25 hours long in Prague: dayStart() + 86400 is 23:00.
                $changes   = self::fold([[(int) strtotime('2026-10-25 10:00:00'), self::sys('5400M', '5400')]]);
                $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-10-20'), self::day('2026-10-30'));

                self::assertSame(self::day('2026-10-26'), $intervals[1]['from']);
            } finally {
                date_default_timezone_set($tz);
            }
        }

        public function testAWindowWithoutAWholeDayInItIsNotMeasured(): void
        {
            $tz = date_default_timezone_get();
            date_default_timezone_set('Europe/Prague');
            try {
                // 2026-10-25 has 25 hours: at 23:30 the window has run 24.5 hours, yet
                // none of its days has ended, so there is nothing whole to measure.
                $changes   = self::fold([[(int) strtotime('2026-10-24 10:00:00'), self::sys('5400M', '5400')]]);
                $intervals = ConfigTimeline::buildIntervals($changes, self::day('2026-10-20'), (int) strtotime('2026-10-25 23:30:00'));

                $newest = $intervals[count($intervals) - 1];
                self::assertGreaterThanOrEqual(ConfigTimeline::MIN_SPAN, $newest['span']);
                self::assertTrue($newest['short']);
                self::assertSame(0.0, $newest['days']);
            } finally {
                date_default_timezone_set($tz);
            }
        }

        public function testAnHourACutGoesThroughBelongsToNeitherSide(): void
        {
            $cut       = (int) strtotime('2026-09-09 15:58:14');
            $intervals = [
                ['measure_from' => $cut - 86400, 'measure_to' => $cut],
                ['measure_from' => $cut, 'measure_to' => $cut + 86400],
            ];
            $hour = static fn(string $when) => (int) strtotime($when);

            self::assertSame(0, ConfigTimeline::windowOf($hour('2026-09-09 14:00:00'), $intervals, 3600));
            self::assertNull(ConfigTimeline::windowOf($hour('2026-09-09 15:00:00'), $intervals, 3600));
            self::assertSame(1, ConfigTimeline::windowOf($hour('2026-09-09 16:00:00'), $intervals, 3600));
            self::assertSame(1, ConfigTimeline::windowOf($cut, $intervals), 'a single moment is simply on one side');
        }

        public function testAWindowWithNoChangesAtAllIsOneWindow(): void
        {
            $intervals = ConfigTimeline::buildIntervals([], self::day('2026-07-01'), self::day('2026-07-15'));

            self::assertCount(1, $intervals);
            self::assertSame([], $intervals[0]['changes']);
            self::assertSame(14.0, $intervals[0]['days']);
        }

        public function testDiffNamesOnlyWhatActuallyChanged(): void
        {
            $before = self::fold([[1000, self::sys('5313M', '5297', '8.2.32', '10.11.16-MariaDB-log/DB_PDO')]])[0];
            $after  = self::fold([[2000, self::sys('5313M', '5297', '8.2.32', '10.11.18-MariaDB-log/DB_PDO')]])[0];

            self::assertSame(['MariaDB 10.11.18'], ConfigTimeline::describeDiff([$after], $before));
        }

        public function testDiffNamesEveryRevisionAWindowSwallowed(): void
        {
            $before = self::fold([[1000, self::sys('5240M', '5240')]])[0];
            $rows   = self::fold([
                [2000, self::sys('5242M', '5242')],
                [3000, self::sys('5245M', '5242')],
            ]);

            self::assertSame(['r5242M, r5245M'], ConfigTimeline::describeDiff($rows, $before));
        }

        public function testDiffOfTheFirstWindowHasNothingToCompareAgainst(): void
        {
            $change = self::fold([[1000, self::sys('5348M', '5345')]])[0];

            self::assertSame(['r5348M'], ConfigTimeline::describeDiff([$change], null));
            self::assertSame([], ConfigTimeline::describeDiff([], null));
        }

        public function testDatabaseVersionReadsAsAName(): void
        {
            self::assertSame('MariaDB 10.11.19', ConfigTimeline::dbLabel('10.11.19-MariaDB-log/DB_PDO'));
            self::assertSame('8.0.36', ConfigTimeline::dbLabel('8.0.36/DB_PDO'));
            self::assertSame('whatever', ConfigTimeline::dbLabel('whatever'));
        }
    }
}
