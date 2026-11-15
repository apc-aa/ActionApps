<?php

declare(strict_types=1);

// AA_Collection and _m() need the constants include/config.php normally provides

namespace {
    foreach ([
        'AA_INSTAL_URL'        => 'http://localhost/aa/',
        'AA_INSTAL_PATH'       => '/aa/',
        'IMG_UPLOAD_PATH'      => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'         => dirname(__DIR__, 3) . '/',
        'PERM_LIB'             => 'sql',
        'DB_HOST'              => 'localhost',
        'DB_NAME'              => 'aa_test',
        'DB_USER'              => 'aa',
        'DB_PASSWORD'          => '',
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Mailbulk {

    use AA;
    use AA\IO\DB\DB_AA;
    use AA\Mailbulk\Recipients;
    use AA\Mailbulk\RecipientReport;
    use AA\Mailbulk\Run;
    use AA\Mailbulk\Stats;
    use AA\Later\LogCleanup;
    use AA_Collection;
    use AA_Logentry_Mailid;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Later\Fixtures\BatchRunTables;
    use Tests\Unit\Mailbulk\Fixtures\MemoryStats;
    use Tests\Unit\Mailbulk\Fixtures\TestCampaign;
    use Tests\Unit\Mailbulk\Fixtures\TestRun;

    require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
    require_once __DIR__ . '/Fixtures/FakeLetter.php';
    require_once __DIR__ . '/Fixtures/MemoryStats.php';
    require_once __DIR__ . '/Fixtures/ScriptedMailer.php';
    require_once __DIR__ . '/Fixtures/TestRun.php';
    require_once __DIR__ . '/Fixtures/TestCampaign.php';
    require_once __DIR__ . '/../../../modules/alerts/alerts_sending.php';
    require_once __DIR__ . '/../../../include/mail.php';

    /** The opens and clicks the test says, and whether anyone asked. */
    final class ReaderStats extends Stats
    {
        /** @var array<string, int> reader => the time of the open */
        public static array $opens = [];
        /** @var list<array{url: string, reader: string, time: int}> */
        public static array $clicks = [];
        /** @var list<string> */
        public static array $asked = [];

        public function openTimes(string $statSliceId, string $list, string $itemId, array $readerIds): array
        {
            self::$asked[] = "opens $statSliceId $list $itemId " . implode(',', $readerIds);
            return array_intersect_key(self::$opens, array_flip($readerIds));
        }

        public function clicks(string $statSliceId, string $itemId): array
        {
            self::$asked[] = "clicks $statSliceId $itemId";
            return self::$clicks;
        }
    }

    /** A report over the test's addresses, log and events. */
    final class TestRecipientReport extends RecipientReport
    {
        /** @var array<string, string> reader => address */
        public static array $emails = [];
        /** @var list<array{time: int, selector: string}> */
        public static array $log = [];
        /** @var list<string> the run of each read of the log */
        public static array $logReads = [];
        /** @var list<int> how many readers each read of the addresses asked for */
        public static array $emailReads = [];
        /** the events from MemoryStats - the figures above the table read them too */
        public static bool $memory = false;

        public static function reset(): void
        {
            self::$emails     = [];
            self::$log        = [];
            self::$logReads   = [];
            self::$emailReads = [];
            ReaderStats::$opens  = [];
            ReaderStats::$clicks = [];
            ReaderStats::$asked  = [];
            self::$memory        = false;
        }

        protected function emails(array $ids): array
        {
            self::$emailReads[] = count($ids);
            return array_intersect_key(self::$emails, array_flip($ids));
        }

        protected function sendLog(string $runId): array
        {
            self::$logReads[] = $runId;
            $start            = \AA_Mail::logSelectorStart($runId);
            return array_values(array_filter(self::$log, static fn(array $row): bool => str_starts_with($row['selector'], $start)));
        }

        protected function stats(): Stats
        {
            return self::$memory ? new MemoryStats() : new ReaderStats();
        }
    }

    /** Who a sending went to: each reader once, the outcome and time of the last run, opens and clicks. */
    final class RecipientReportTest extends TestCase
    {
        use BatchRunTables {
            setUpBeforeClass as private tablesSetUpBeforeClass;
            setUp as private tablesSetUp;
            tearDown as private tablesTearDown;
        }

        private const ITEM       = 'cafe0000000000000000000000000001';
        private const LIST       = 'abcde';
        private const STAT_SLICE = '5555aaaa5555aaaa5555aaaa5555aaaa';
        private const FIRST      = self::NOW - 7200;
        private const SECOND     = self::NOW - 600;
        private const BASE       = 'https://example.org/aa/';

        public static function setUpBeforeClass(): void
        {
            self::tablesSetUpBeforeClass();
        }

        protected function setUp(): void
        {
            $this->tablesSetUp();
            TestRun::reset();
            TestCampaign::reset();
            TestRecipientReport::reset();
            MemoryStats::reset();
            AA::Contentcache()->clear();
            TestRun::$now      = self::NOW;
            TestRun::$microNow = (float) self::NOW;
        }

        protected function tearDown(): void
        {
            TestRun::reset();
            TestCampaign::reset();
            TestRecipientReport::reset();
            MemoryStats::reset();
            AA::Contentcache()->clear();
            $this->tablesTearDown();
        }

        private static function reader(int $i): string
        {
            return sprintf('a%031x', $i);
        }

        /** a list read already - no database behind it */
        private static function list(string $statSlice = ''): AA_Collection
        {
            $list                  = new AA_Collection(self::LIST);
            $list->reader_slice_id = 'feed0000000000000000000000000002';
            $list->stat_slice_id   = $statSlice;
            return $list;
        }

        private static function report(string $statSlice = ''): TestRecipientReport
        {
            return new TestRecipientReport(self::list($statSlice), TestCampaign::of(self::LIST, self::ITEM));
        }

        /** a run of the item to readers $readers, started at $at and finished 100 s later, its mails tracked
         *  unless $props say base ''; its id
         *  @param list<int> $readers */
        private static function sending(array $readers, string $state, array $props, int $at): string
        {
            TestRun::$now = $at;
            $id           = DB_AA::transaction(static fn(): string => TestRun::start($props + [
                'subject'    => self::ITEM,
                'user'       => self::USER,
                'position'   => ['i' => 0],
                'list'       => self::LIST,
                'title'      => 'Newsletter',
                'recipients' => Recipients::freeze(self::ITEM, array_map(self::reader(...), $readers)),
                'total'      => (string) count($readers),
                'cursor'     => '0',
                'base'       => self::BASE,
            ])->getId());
            TestRun::$now = self::NOW;
            self::storedSet($id, 'state', $state);
            self::storedSet($id, 'finished', (string) ($at + 100));
            return $id;
        }

        /** the first run: 0 sent, 1 failed, 2 skipped, 3 uncertain; the second: 1 sent, 2 failed
         *  @return array{string, string} the ids of the runs */
        private static function twoRuns(): array
        {
            $first  = self::sending([0, 1, 2, 3], Run::DONE, ['cursor' => '4', 'outcomes' => '{"1":"f","2":"s","3":"u"}'], self::FIRST);
            $second = self::sending([1, 2], Run::DONE, ['cursor' => '2', 'outcomes' => '{"1":"f"}'], self::SECOND);
            foreach ([0, 1, 2, 3, 4] as $i) {
                TestRecipientReport::$emails[self::reader($i)] = "r$i@example.org";
            }
            return [$first, $second];
        }

        /** the MAIL_SEND row of a mail of run $run to reader $i, as AaMailer names it */
        private static function logged(int $time, string $run, int $i): array
        {
            $selector = new AA_Logentry_Mailid(['cls' => 'AA_Mail', 'template' => $run, 'reader' => self::reader($i), 'origin' => 'toexecute.php>Run.php:400']);
            return ['time' => $time, 'selector' => $selector->serializeToString(true)];
        }

        /** opens and clicks per reader, as the table shows them
         *  @param array<string, array{opened: int, clicks: list<array{url: string, time: int}>}> $byReader */
        private static function events(array $byReader): void
        {
            foreach ($byReader as $reader => $events) {
                if ($events['opened'] > 0) {
                    ReaderStats::$opens[$reader] = $events['opened'];
                }
                foreach ($events['clicks'] as $click) {
                    ReaderStats::$clicks[] = $click + ['reader' => $reader];
                }
            }
        }

        /** @return list<list<string>> the text of the cells of each row of the table of $class */
        private static function cells(string $html, string $class = 'aa-mailbulk-recipients'): array
        {
            $doc = new \DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
            $rows = [];
            $xpath = new \DOMXPath($doc);
            foreach ($xpath->query("//table[contains(concat(' ', @class, ' '), ' $class ')]//tr") as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $cell) {
                    if ($cell instanceof \DOMElement) {
                        // the text of each element apart: the clicks are items of a list
                        $texts   = array_map(static fn(\DOMNode $t): string => $t->textContent, iterator_to_array($xpath->query('.//text()', $cell)));
                        $cells[] = trim(preg_replace('/\s+/u', ' ', implode(' ', $texts)));
                    }
                }
                $rows[] = $cells;
            }
            return $rows;
        }

        /** @param list<array<string, mixed>> $rows
         *  @return array<string, string> e-mail => outcome */
        private static function outcomes(array $rows): array
        {
            return array_column($rows, 'outcome', 'email');
        }

        public function test_rows_readerOfTwoRuns_onceWithTheOutcomeOfTheLastRun(): void
        {
            self::twoRuns();

            $rows = self::report()->rows();

            self::assertSame([
                'r0@example.org' => 'sent',
                'r1@example.org' => 'sent',
                'r2@example.org' => 'failed',
                'r3@example.org' => 'uncertain',
            ], self::outcomes($rows), 'r1 failed first, got it in the second run; r2 skipped first, failed then; r4 never in a run');
            self::assertSame([4], TestRecipientReport::$emailReads, 'the addresses of the four readers in one read');
        }

        public function test_rows_timeFromTheLogRowsOfTheRun_elseTheRunStart(): void
        {
            [$first, $second] = self::twoRuns();
            TestRecipientReport::$log = [
                self::logged(self::FIRST + 10, $first, 0),               // a try before - the later one counts
                self::logged(self::FIRST + 20, $first, 0),
                self::logged(self::SECOND + 5, $second, 2),
                self::logged(self::FIRST + 30, $first, 1),               // the first run: not the last of r1
                self::logged(self::FIRST + 40, 'feedfeedfeedfeedfeedfeedfeedfeed', 3),   // another run
            ];

            $rows = array_column(self::report()->rows(), null, 'email');

            self::assertSame([self::FIRST + 20, true], [$rows['r0@example.org']['sent'], $rows['r0@example.org']['logged']], 'the last attempt');
            self::assertSame([self::SECOND, false], [$rows['r1@example.org']['sent'], $rows['r1@example.org']['logged']], 'no row of the second run: its start');
            self::assertSame([self::SECOND + 5, true], [$rows['r2@example.org']['sent'], $rows['r2@example.org']['logged']], 'a failed mail at its attempt');
            self::assertSame([self::FIRST, false], [$rows['r3@example.org']['sent'], $rows['r3@example.org']['logged']]);
            self::assertSame([$first, $second], TestRecipientReport::$logReads, 'one read for each run a mail went in');
        }

        /** a mail that could not be made was never sent - no time, not even of the run */
        public function test_rows_mailNotMade_hasNoTime(): void
        {
            self::sending([0, 1], Run::DONE, ['cursor' => '2', 'outcomes' => '{"1":"f"}', 'notes' => '{"1":"The mail could not be rendered."}'], self::FIRST);
            TestRecipientReport::$emails = [self::reader(0) => 'r0@example.org', self::reader(1) => 'r1@example.org'];

            $rows = array_column(self::report()->rows(), null, 'email');

            self::assertSame(['failed', 0], [$rows['r1@example.org']['outcome'], $rows['r1@example.org']['sent']]);
            self::assertSame(self::FIRST, $rows['r0@example.org']['sent']);
        }

        public function test_rows_skippedOrNotTriedYet_haveNoTime(): void
        {
            self::sending([0, 1, 2], Run::SENDING, ['cursor' => '2', 'outcomes' => '{"1":"s"}'], self::SECOND);
            TestRecipientReport::$emails = [self::reader(0) => 'r0@example.org', self::reader(1) => 'r1@example.org', self::reader(2) => 'r2@example.org'];

            $rows = array_column(self::report()->rows(), null, 'email');

            self::assertSame(['skipped', 0], [$rows['r1@example.org']['outcome'], $rows['r1@example.org']['sent']], 'no mail went');
            self::assertSame(['', 0], [$rows['r2@example.org']['outcome'], $rows['r2@example.org']['sent']], 'not tried yet');
            self::assertSame(self::SECOND, $rows['r0@example.org']['sent']);
        }

        public function test_rows_eachFilter(): void
        {
            self::twoRuns();
            self::sending([4], Run::DONE, ['cursor' => '1'], self::NOW - 300);
            self::events([
                self::reader(0) => ['opened' => self::FIRST + 500, 'clicks' => [['url' => 'https://example.org/a', 'time' => self::FIRST + 600]]],
                self::reader(1) => ['opened' => self::SECOND + 500, 'clicks' => []],
                self::reader(4) => ['opened' => 0, 'clicks' => [['url' => 'https://example.org/a', 'time' => self::NOW]]],
                'ffff0000000000000000000000000009' => ['opened' => 0, 'clicks' => [['url' => 'https://example.org/a', 'time' => self::NOW]]],
            ]);
            $report = self::report(self::STAT_SLICE);

            self::assertSame(['all', 'opened', 'unopened', 'clicked', 'failed'], $report->filters());
            $emails = static fn(string $filter): array => array_column($report->rows($filter), 'email');
            self::assertSame(['r0@example.org', 'r1@example.org', 'r2@example.org', 'r3@example.org', 'r4@example.org'], $emails('all'));
            self::assertSame(['r0@example.org', 'r1@example.org', 'r4@example.org'], $emails('opened'), 'r4 has no open, but a click: the mail was read');
            self::assertSame(self::NOW, array_column($report->rows(), 'opened', 'email')['r4@example.org'], 'opened by the click');
            self::assertSame([], $emails('unopened'));
            self::assertSame(['r0@example.org', 'r4@example.org'], $emails('clicked'), 'the click of a reader of another sending left out');
            self::assertSame(['r2@example.org', 'r3@example.org'], $emails('failed'), 'with the uncertain');
            self::assertSame([
                'opens ' . self::STAT_SLICE . ' ' . self::LIST . ' ' . self::ITEM . ' ' . implode(',', array_map(self::reader(...), [0, 1, 2, 3, 4])),
                'clicks ' . self::STAT_SLICE . ' ' . self::ITEM,
            ], ReaderStats::$asked, 'the events read once for all filters');

            ReaderStats::$opens  = [];
            ReaderStats::$clicks = [];
            $report = self::report(self::STAT_SLICE);
            self::assertSame(['r0@example.org', 'r1@example.org', 'r4@example.org'], array_column($report->rows('unopened'), 'email'), 'only the sent ones');
        }

        public function test_filter_unknownOrUnmeasured_isAll(): void
        {
            self::sending([0], Run::DONE, ['cursor' => '1'], self::FIRST);
            self::assertSame('all', self::report(self::STAT_SLICE)->filter('<script>'));
            self::assertSame('clicked', self::report(self::STAT_SLICE)->filter('clicked'));
            self::assertSame(['all', 'failed'], self::report()->filters());
            self::assertSame('all', self::report()->filter('opened'), 'without a statistics slice nothing is measured');
            self::assertSame('failed', self::report()->filter('failed'));
        }

        public function test_table_clicksOnTwoLinks_withTimes(): void
        {
            self::twoRuns();
            self::events([
                self::reader(0) => ['opened' => self::FIRST + 500, 'clicks' => [
                    ['url' => 'https://example.org/a', 'time' => self::FIRST + 600],
                    ['url' => 'https://example.org/b', 'time' => self::FIRST + 700],
                    ['url' => 'https://example.org/0', 'time' => self::FIRST + 600],
                ]],
            ]);
            $report = self::report(self::STAT_SLICE);

            $cells = self::cells($report->table($report->rows('clicked')));

            self::assertSame([
                ['E-mail', 'Result', 'Time sent', 'First opened', 'Clicked links'],
                ['r0@example.org', 'Sent', '≈ ' . date('Y-m-d H:i', self::FIRST), date('Y-m-d H:i', self::FIRST + 500),
                    date('Y-m-d H:i', self::FIRST + 600) . ' https://example.org/0 ' . date('Y-m-d H:i', self::FIRST + 600) . ' https://example.org/a ' . date('Y-m-d H:i', self::FIRST + 700) . ' https://example.org/b'],
            ], $cells, 'a time from the start of the run is marked; the clicks by time, then address');
            self::assertStringContainsString('title="When the run started: the log keeps the time of each mail for ' . LogCleanup::KEEP_DAYS . ' days"', $report->table($report->rows('clicked')));
        }

        public function test_table_withoutStatSlice_deliveryOnly(): void
        {
            self::twoRuns();
            $report = self::report();

            $cells = self::cells($report->table($report->rows()));

            self::assertSame(['E-mail', 'Result', 'Time sent'], $cells[0]);
            self::assertSame(['r2@example.org', 'Failed', '≈ ' . date('Y-m-d H:i', self::SECOND)], $cells[3]);
            self::assertSame([], ReaderStats::$asked, 'nothing is read from a slice the list has not');
            self::assertStringNotContainsString('image proxies', $report->note(), 'no word on the measuring');
            self::assertStringContainsString('personal data', $report->note());
        }

        public function test_table_aPageOfTheRows(): void
        {
            $readers = range(0, RecipientReport::PER_PAGE * 2 + 4);
            self::sending($readers, Run::DONE, ['cursor' => (string) count($readers)], self::FIRST);
            foreach ($readers as $i) {
                TestRecipientReport::$emails[self::reader($i)] = sprintf('r%03d@example.org', $i);
            }
            $report = self::report();
            $rows   = $report->rows();

            self::assertCount(205, $rows, 'the filter needs the whole set');
            self::assertCount(RecipientReport::PER_PAGE + 1, self::cells($report->table($rows, 1)));
            $last = self::cells($report->table($rows, 3));
            self::assertCount(6, $last);
            self::assertSame('r200@example.org', $last[1][0]);
        }

        public function test_table_escapesTheAddressAndTheLinks(): void
        {
            self::sending([0, 1], Run::DONE, ['cursor' => '2'], self::FIRST);
            TestRecipientReport::$emails = [self::reader(0) => '"><script>alert(1)</script>@example.org'];
            self::events([self::reader(0) => ['opened' => 0, 'clicks' => [
                ['url' => 'https://example.org/?a=1&b="><script>x</script>', 'time' => self::FIRST + 10],
                ['url' => 'javascript:alert(1)', 'time' => self::FIRST + 20],
            ]]]);
            $report = self::report(self::STAT_SLICE);

            $html = $report->table($report->rows());

            self::assertStringNotContainsString('<script>', $html);
            self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;@example.org', $html);
            self::assertStringContainsString('href="https://example.org/?a=1&amp;b=&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $html);
            self::assertStringNotContainsString('href="javascript', $html, 'only a web address becomes a link');
            self::assertSame('Not in the list any more', self::cells($html)[2][0], 'a reader deleted since');
        }

        /** a reader whose address was emptied is still in the list - only a deleted one is not */
        public function test_table_readerWithoutAddress_isNoGoneReader(): void
        {
            self::sending([0, 1, 2], Run::DONE, ['cursor' => '3'], self::FIRST);
            TestRecipientReport::$emails = [self::reader(0) => 'r0@example.org', self::reader(1) => ''];
            $report = self::report();

            $rows = $report->rows();

            self::assertSame([[self::reader(1), '', false], [self::reader(0), 'r0@example.org', false], [self::reader(2), '', true]], array_map(static fn(array $r): array => [$r['reader'], $r['email'], $r['gone']], $rows), 'the gone ones last');
            $cells = self::cells($report->table($rows));
            self::assertSame(['', 'r0@example.org', 'Not in the list any more'], array_column(array_slice($cells, 1), 0));
        }

        public function test_filterBar_linksTheOtherFilters_andTheCsv(): void
        {
            self::sending([0], Run::DONE, ['cursor' => '1'], self::FIRST);
            $html = self::report(self::STAT_SLICE)->filterBar('opened', '/aa/modules/alerts/mailings.php?mailing=' . self::ITEM);

            self::assertStringContainsString('<strong aria-current="true">Opened</strong>', $html);
            self::assertStringContainsString('href="/aa/modules/alerts/mailings.php?rcpt=clicked&amp;mailing=' . self::ITEM . '#aa-recipients"', $html, 'back to the table');
            self::assertStringContainsString('href="/aa/modules/alerts/mailings.php?rcpt=opened&amp;csv=1&amp;mailing=' . self::ITEM . '#aa-recipients"', $html, 'the CSV of the same filter');
            self::assertStringNotContainsString('rcpt=opened&amp;mailing', $html, 'the current filter is no link');
        }

        public function test_csv_headerRowsAndCellsThatWouldRunAsFormulas(): void
        {
            self::sending([0, 1, 2], Run::DONE, ['cursor' => '3', 'outcomes' => '{"2":"f"}'], self::FIRST);
            TestRecipientReport::$emails = [
                self::reader(0) => '=HYPERLINK("https://evil.example")@example.org',
                self::reader(1) => '@SUM(1+1)@example.org',
                self::reader(2) => "\t-2+3@example.org",
            ];
            TestRecipientReport::$log = [self::logged(self::FIRST + 10, TestRun::ofPair(self::ITEM, self::LIST)[0]->getId(), 1)];
            self::events([self::reader(1) => ['opened' => self::FIRST + 50, 'clicks' => [
                ['url' => '+https://example.org/a', 'time' => self::FIRST + 60],
                ['url' => 'https://example.org/b', 'time' => self::FIRST + 70],
            ]]]);
            $report = self::report(self::STAT_SLICE);

            $out = fopen('php://memory', 'w+');
            $report->csv('all', $out);
            rewind($out);
            self::assertSame("\u{FEFF}", fread($out, 3), 'a BOM: spreadsheets read the file as UTF-8 then');
            $lines = [];
            while (($line = fgetcsv($out, null, ',', '"', '')) !== false) {
                $lines[] = $line;
            }
            $byEmail = array_column(array_slice($lines, 1), null, 0);

            self::assertSame(['E-mail', 'Result', 'Time sent', 'First opened', 'Clicked links'], $lines[0]);
            self::assertCount(4, $lines);
            self::assertSame(
                ["'@SUM(1+1)@example.org", 'Sent', date('Y-m-d H:i', self::FIRST + 10), date('Y-m-d H:i', self::FIRST + 50),
                    date('Y-m-d H:i', self::FIRST + 60) . " +https://example.org/a\n" . date('Y-m-d H:i', self::FIRST + 70) . ' https://example.org/b'],
                $byEmail["'@SUM(1+1)@example.org"],
                'one line per click in one cell',
            );
            self::assertSame(
                ["'=HYPERLINK(\"https://evil.example\")@example.org", 'Sent', '≈ ' . date('Y-m-d H:i', self::FIRST), '', ''],
                $byEmail["'=HYPERLINK(\"https://evil.example\")@example.org"],
                'a formula is only text; a time from the run is marked as in the table',
            );
            self::assertSame("'\t-2+3@example.org", $lines[1][0], 'a tab in front is neutralised too');
            self::assertSame('Failed', $lines[1][1]);
        }

        /** a run started without tracking (no statistics slice then) measured nothing: its readers are neither
         *  opened nor not opened */
        public function test_rows_runThatTrackedNothing_outOfTheFiltersOfTheEvents(): void
        {
            self::sending([0, 1], Run::DONE, ['cursor' => '2'], self::FIRST);
            self::sending([2, 3], Run::DONE, ['cursor' => '2', 'base' => ''], self::SECOND);
            TestRecipientReport::$emails = [self::reader(0) => 'r0@example.org', self::reader(1) => 'r1@example.org', self::reader(2) => 'r2@example.org', self::reader(3) => 'r3@example.org'];
            self::events([
                self::reader(0) => ['opened' => self::FIRST + 50, 'clicks' => []],
                self::reader(3) => ['opened' => self::SECOND + 50, 'clicks' => [['url' => 'https://example.org/a', 'time' => self::SECOND + 60]]],
            ]);
            $report = self::report(self::STAT_SLICE);

            $rows = array_column($report->rows(), null, 'email');

            self::assertSame(['all', 'opened', 'unopened', 'clicked', 'failed'], $report->filters(), 'one run tracked');
            self::assertSame([false, 0, []], [$rows['r3@example.org']['tracked'], $rows['r3@example.org']['opened'], $rows['r3@example.org']['clicks']]);
            self::assertSame(['r0@example.org'], array_column($report->rows('opened'), 'email'));
            self::assertSame(['r1@example.org'], array_column($report->rows('unopened'), 'email'), 'r2 and r3: nothing was measured');
            self::assertSame([], array_column($report->rows('clicked'), 'email'));
            self::assertStringEndsWith(' ' . self::reader(0) . ',' . self::reader(1), ReaderStats::$asked[0], 'the opens of the tracked readers only');
        }

        public function test_filters_noRunTracked_deliveryOnly(): void
        {
            self::sending([0, 1], Run::DONE, ['cursor' => '2', 'base' => ''], self::FIRST);
            $report = self::report(self::STAT_SLICE);

            self::assertSame(['all', 'failed'], $report->filters());
            self::assertSame(['E-mail', 'Result', 'Time sent'], self::cells($report->table($report->rows()))[0]);
            self::assertSame([], ReaderStats::$asked);
        }

        /** "opened" and "clicked" above the table and the filters of the table count the same readers */
        public function test_rows_agreeWithTheFiguresAbove(): void
        {
            self::sending([0, 1, 2, 3, 4], Run::DONE, ['cursor' => '5'], self::FIRST);
            TestRecipientReport::$memory = true;
            MemoryStats::add('open', self::reader(0), '', self::ITEM, self::FIRST + 10);
            MemoryStats::add('open', self::reader(1), '', self::ITEM, self::FIRST + 20);
            MemoryStats::add('click', self::reader(1), 'https://example.org/a', self::ITEM, self::FIRST + 30);
            MemoryStats::add('click', self::reader(2), 'https://example.org/a', self::ITEM, self::FIRST + 40);   // its open thrown out
            MemoryStats::add('open', self::reader(7), '', 'cafe0000000000000000000000000002', self::FIRST);     // another sending

            $report  = self::report(self::STAT_SLICE);
            $figures = (new MemoryStats())->counts(self::STAT_SLICE, self::LIST, self::ITEM);

            self::assertSame(['opened' => 3, 'clicked' => 2], $figures);
            self::assertSame([$figures['opened'], $figures['clicked']], [count($report->rows('opened')), count($report->rows('clicked'))]);
            self::assertSame([self::FIRST + 10, self::FIRST + 20, self::FIRST + 40], array_slice(array_column($report->rows(), 'opened'), 0, 3), 'r2 opened by its click');
            self::assertSame(1, count(array_keys(MemoryStats::$reads, 'events click')), 'the clicks searched once for the figures and the table');
        }

        public function test_rows_noRun_noRows(): void
        {
            self::assertSame([], self::report(self::STAT_SLICE)->rows());
            self::assertSame([], TestRecipientReport::$logReads, 'nothing read');
            self::assertSame([], ReaderStats::$asked);
        }
    }
}
