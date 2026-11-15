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

    use AA\IO\DB\DB_AA;
    use AA\Mailbulk\Campaign;
    use AA\Mailbulk\Recipients;
    use AA\Mailbulk\Run;
    use AA\Mailbulk\Stats;
    use AA\Mailbulk\StatsView;
    use AA_Collection;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Later\Fixtures\BatchRunTables;
    use Tests\Unit\Mailbulk\Fixtures\TestCampaign;
    use Tests\Unit\Mailbulk\Fixtures\TestRun;

    require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
    require_once __DIR__ . '/Fixtures/FakeLetter.php';
    require_once __DIR__ . '/Fixtures/ScriptedMailer.php';
    require_once __DIR__ . '/Fixtures/TestRun.php';
    require_once __DIR__ . '/Fixtures/TestCampaign.php';
    require_once __DIR__ . '/../../../modules/alerts/alerts_sending.php';

    /** The figures the test says, and whether anyone asked. */
    final class FixedStats extends Stats
    {
        /** @var array{opened: int, clicked: int} */
        public static array $counts = ['opened' => 0, 'clicked' => 0];
        /** @var list<array{url: string, readers: int}> */
        public static array $links = [];
        /** @var list<string> slice and item of each question */
        public static array $asked = [];

        public static function reset(): void
        {
            self::$counts = ['opened' => 0, 'clicked' => 0];
            self::$links  = [];
            self::$asked  = [];
        }

        public function counts(string $statSliceId, string $list, string $itemId): array
        {
            self::$asked[] = "$statSliceId $list $itemId";
            return self::$counts;
        }

        public function links(string $statSliceId, string $itemId): array
        {
            return self::$links;
        }
    }

    /** A StatsView over TestRun runs and FixedStats. */
    final class TestStatsView extends StatsView
    {
        protected function campaign(string $list, string $itemId): Campaign
        {
            return TestCampaign::of($list, $itemId);
        }

        protected function stats(): Stats
        {
            return new FixedStats();
        }
    }

    /** One block of one sending: its runs, the sending as a whole and, with a statistics slice, opens and clicks by link. */
    final class StatsViewTest extends TestCase
    {
        use BatchRunTables {
            setUpBeforeClass as private tablesSetUpBeforeClass;
            setUp as private tablesSetUp;
            tearDown as private tablesTearDown;
        }

        private const ITEM       = 'cafe0000000000000000000000000001';
        private const LIST       = 'abcde';
        private const STAT_SLICE = '5555aaaa5555aaaa5555aaaa5555aaaa';

        public static function setUpBeforeClass(): void
        {
            self::tablesSetUpBeforeClass();
        }

        protected function setUp(): void
        {
            $this->tablesSetUp();
            TestRun::reset();
            TestCampaign::reset();
            FixedStats::reset();
            TestRun::$now      = self::NOW;
            TestRun::$microNow = (float) self::NOW;
        }

        protected function tearDown(): void
        {
            TestRun::reset();
            TestCampaign::reset();
            FixedStats::reset();
            $this->tablesTearDown();
        }

        /** a list read already - no database behind it */
        private static function list(string $statSlice = ''): AA_Collection
        {
            $list                  = new AA_Collection(self::LIST);
            $list->reader_slice_id = 'feed0000000000000000000000000002';
            $list->stat_slice_id   = $statSlice;
            return $list;
        }

        /** a run of the item to readers $readers, started at $at, as far as $props say; its id
         *  @param list<int> $readers */
        private static function sending(array $readers, string $state, array $props = [], int $at = self::NOW - 1000, string $item = self::ITEM): string
        {
            TestRun::$now = $at;
            $id           = DB_AA::transaction(static fn(): string => TestRun::start($props + [
                'subject'    => $item,
                'user'       => self::USER,
                'position'   => ['i' => 0],
                'list'       => self::LIST,
                'title'      => 'Newsletter',
                'recipients' => Recipients::freeze($item, array_map(static fn(int $i): string => sprintf('a%031x', $i), $readers)),
                'total'      => (string) count($readers),
                'cursor'     => '0',
            ])->getId());
            TestRun::$now = self::NOW;
            self::storedSet($id, 'state', $state);
            return $id;
        }

        /** @return list<list<string>> the text of the cells of each row of the table of $class */
        private static function rows(string $html, string $class): array
        {
            $doc = new \DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
            $rows = [];
            foreach ((new \DOMXPath($doc))->query("//table[contains(concat(' ', @class, ' '), ' $class ')]//tr") as $tr) {
                $cells = [];
                foreach ($tr->childNodes as $cell) {
                    if ($cell instanceof \DOMElement) {
                        $cells[] = trim($cell->textContent);
                    }
                }
                $rows[] = $cells;
            }
            return $rows;
        }

        public function test_mailing_sumsTheRunsAndListsEach(): void
        {
            $first = self::sending([0, 1, 2, 3], Run::DONE, ['cursor' => '4', 'outcomes' => '{"1":"f","2":"s","3":"u"}'], self::NOW - 7200);
            self::storedSet($first, 'finished', (string) (self::NOW - 7000));
            self::sending([1, 2, 4], Run::SENDING, ['cursor' => '2', 'outcomes' => '{"1":"f"}'], self::NOW - 600);

            $html = (new TestStatsView())->mailing(self::list(), strtoupper(self::ITEM));

            self::assertSame([
                ['Started', 'Finished', 'State', 'Recipients', 'Sent', 'Failed', 'Skipped', 'Uncertain'],
                [date('Y-m-d H:i', self::NOW - 7200), date('Y-m-d H:i', self::NOW - 7000), 'Done', '4', '1', '1', '1', '1'],
                [date('Y-m-d H:i', self::NOW - 600), '', 'Sending', '3', '1', '1', '0', '0'],
                ['Total', '5', '2', '1', '0', '1'],
            ], self::rows($html, 'aa-mailbulk-runs'), 'reader 1 got it in the second run, reader 2 failed there, reader 4 not tried yet counts in the total only');
            self::assertStringContainsString('<h3 class="aa-mailbulk-title">Newsletter</h3>', $html);
            self::assertStringContainsString('<table class="aa-table aa-mailbulk-runs">', $html, 'the look of the other admin tables');
        }

        public function test_mailing_oneRun_hasNoTotal_andAnotherListOrNoRun_showsNothing(): void
        {
            self::sending([0, 1], Run::ENDED, ['cursor' => '1']);

            self::assertSame([
                ['Started', 'Finished', 'State', 'Recipients', 'Sent', 'Failed', 'Skipped', 'Uncertain'],
                [date('Y-m-d H:i', self::NOW - 1000), '', 'Stopped', '2', '1', '0', '0', '0'],
            ], self::rows((new TestStatsView())->mailing(self::list(), self::ITEM), 'aa-mailbulk-runs'));

            $other = new AA_Collection('fghij');
            $other->reader_slice_id = 'feed0000000000000000000000000002';
            $other->stat_slice_id   = '';
            self::assertSame('', (new TestStatsView())->mailing($other, self::ITEM), 'the runs of another list are not shown');
            self::assertSame('', (new TestStatsView())->mailing(self::list(), 'cafe0000000000000000000000000002'));
            self::assertSame('', (new TestStatsView())->mailing(self::list(), 'not-an-id'));
        }

        public function test_mailing_escapesTitleAndUrls(): void
        {
            self::sending([0], Run::DONE, ['cursor' => '1', 'title' => '<b>News</b> & "more"']);
            FixedStats::$counts = ['opened' => 3, 'clicked' => 2];
            FixedStats::$links  = [
                ['url' => 'https://example.org/?a=1&b="><script>x</script>', 'readers' => 2],
                ['url' => 'javascript:alert(1)', 'readers' => 1],
            ];

            $html = (new TestStatsView())->mailing(self::list(self::STAT_SLICE), self::ITEM);

            self::assertStringContainsString('&lt;b&gt;News&lt;/b&gt; &amp; &quot;more&quot;', $html);
            self::assertStringNotContainsString('<script>', $html);
            self::assertStringNotContainsString('<b>', $html);
            self::assertStringContainsString('href="https://example.org/?a=1&amp;b=&quot;&gt;&lt;script&gt;x&lt;/script&gt;"', $html);
            self::assertStringNotContainsString('href="javascript', $html, 'only a web address becomes a link');
            self::assertSame([
                ['Link', 'Readers clicked'],
                ['https://example.org/?a=1&b="><script>x</script>', '2'],
                ['javascript:alert(1)', '1'],
            ], self::rows($html, 'aa-mailbulk-links'));
            self::assertStringContainsString('Opened 3, clicked 2', $html);
            self::assertSame([self::STAT_SLICE . ' ' . self::LIST . ' ' . self::ITEM], FixedStats::$asked);
        }

        public function test_mailing_withoutStatSlice_showsDeliveryOnly(): void
        {
            self::sending([0, 1], Run::DONE, ['cursor' => '2']);
            FixedStats::$links = [['url' => 'https://example.org/', 'readers' => 1]];

            $html = (new TestStatsView())->mailing(self::list(), self::ITEM);

            self::assertCount(2, self::rows($html, 'aa-mailbulk-runs'));
            self::assertSame([], self::rows($html, 'aa-mailbulk-links'));
            self::assertStringNotContainsString('Opened', $html);
            self::assertSame([], FixedStats::$asked, 'nothing is read from a slice the list has not');
        }
    }
}
