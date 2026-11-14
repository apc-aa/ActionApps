<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\IO\DB\DB_AA;
use AA\Mailbulk\Campaign;
use AA\Mailbulk\Overview;
use AA\Mailbulk\Recipients;
use AA\Mailbulk\Run;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Mailbulk\Fixtures\TestCampaign;
use Tests\Unit\Mailbulk\Fixtures\TestRun;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
require_once __DIR__ . '/Fixtures/FakeLetter.php';
require_once __DIR__ . '/Fixtures/ScriptedMailer.php';
require_once __DIR__ . '/Fixtures/TestRun.php';
require_once __DIR__ . '/Fixtures/TestCampaign.php';

/** An overview over TestRun runs; it counts how many sendings it read. */
final class TestOverview extends Overview
{
    public static int $campaigns = 0;

    protected static function runClass(): string
    {
        return TestRun::class;
    }

    protected function newCampaign(string $itemId): Campaign
    {
        ++self::$campaigns;
        return TestCampaign::of(OverviewTest::LIST, $itemId);
    }
}

/** The mailings of one Alerts list: one row for all runs of an item, the newest first. */
final class OverviewTest extends TestCase
{
    use BatchRunTables {
        setUpBeforeClass as private tablesSetUpBeforeClass;
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    public const LIST    = 'abcde';
    private const ITEM   = 'cafe0000000000000000000000000001';
    private const ITEM2  = 'cafe0000000000000000000000000002';

    public static function setUpBeforeClass(): void
    {
        self::tablesSetUpBeforeClass();
    }

    protected function setUp(): void
    {
        $this->tablesSetUp();
        TestRun::reset();
        TestOverview::$campaigns = 0;
    }

    protected function tearDown(): void
    {
        TestRun::reset();
        $this->tablesTearDown();
    }

    /** a run of $item to $list started at $at, to the readers $readers; its id
     *  @param list<string> $readers */
    private static function sending(string $item, int $at, string $title = 'Newsletter', string $list = self::LIST, array $readers = ['a0000000000000000000000000000001']): string
    {
        TestRun::$now = $at;
        return DB_AA::transaction(static fn(): string => TestRun::start([
            'subject'    => $item,
            'user'       => self::USER,
            'position'   => ['i' => 0],
            'list'       => $list,
            'title'      => $title,
            'recipients' => Recipients::freeze($item, $readers),
            'total'      => (string) count($readers),
            'cursor'     => '0',
        ])->getId());
    }

    private static function overview(): TestOverview
    {
        return new TestOverview(self::LIST);
    }

    private const NONE = ['total' => 1, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'uncertain' => 0];

    public function test_page_runsOfOneItemAreOneRow(): void
    {
        self::sending(self::ITEM, self::NOW - 100);
        self::sending(self::ITEM, self::NOW - 50);
        self::sending(self::ITEM2, self::NOW - 70);

        $overview = self::overview();

        self::assertSame(2, $overview->count());
        self::assertSame([self::ITEM, self::ITEM2], array_column($overview->page(1), 'item'));
    }

    public function test_page_newestFirst_titleAndStateOfTheNewestRun(): void
    {
        self::sending(self::ITEM, self::NOW - 100, 'First name');
        self::sending(self::ITEM2, self::NOW - 50, 'Other');
        $newest = self::sending(self::ITEM, self::NOW, 'Second name');
        self::storedSet($newest, 'state', Run::DONE);

        $page = self::overview()->page(1);

        self::assertSame([
            ['item' => self::ITEM, 'title' => 'Second name', 'date' => self::NOW, 'state' => Run::DONE, 'counts' => ['total' => 1] + self::NONE],
            ['item' => self::ITEM2, 'title' => 'Other', 'date' => self::NOW - 50, 'state' => Run::SENDING, 'counts' => self::NONE],
        ], $page);
        self::assertSame([self::ITEM2], array_column(self::overview()->page(2, 1), 'item'), 'the second page of one row');
        self::assertSame([], self::overview()->page(3, 1));
    }

    /** an item id from the request shows nothing unless the list sent it */
    public function test_has_itemWithoutARunOfTheList_isFalse(): void
    {
        self::sending(self::ITEM, self::NOW);
        self::sending(self::ITEM2, self::NOW, 'Other list', 'other');

        $overview = self::overview();

        self::assertTrue($overview->has(self::ITEM));
        self::assertTrue($overview->has(strtoupper(self::ITEM)), 'ids come in either case');
        self::assertFalse($overview->has(self::ITEM2));
        self::assertFalse($overview->has('not-an-id'));
        self::assertSame(1, $overview->count());
    }

    /** a run started with no title takes the newest title given before - as the block of the sending names it */
    public function test_page_titleIsTheNewestGiven(): void
    {
        self::sending(self::ITEM, self::NOW - 100, 'First name');
        self::sending(self::ITEM, self::NOW, '  ');
        self::sending(self::ITEM2, self::NOW - 50, '');

        self::assertSame(['First name', self::ITEM2], array_column(self::overview()->page(1), 'title'));
    }

    /** a row of one run is its record; only an item of more runs reads them, each reader counted once */
    public function test_page_figures_readOnlyForMoreRuns(): void
    {
        $reader = 'a0000000000000000000000000000001';
        $first  = self::sending(self::ITEM, self::NOW - 100, readers: [$reader, 'a0000000000000000000000000000002']);
        self::storedSet($first, 'state', Run::ENDED);
        self::storedSet($first, 'cursor', '1');
        self::storedSet($first, 'mark', '1');
        $second = self::sending(self::ITEM, self::NOW - 50, readers: ['a0000000000000000000000000000002']);
        self::storedSet($second, 'state', Run::DONE);
        self::storedSet($second, 'cursor', '1');
        $single = self::sending(self::ITEM2, self::NOW - 70, readers: [$reader, 'a0000000000000000000000000000002', 'a0000000000000000000000000000003']);
        self::storedSet($single, 'state', Run::ENDED);
        self::storedSet($single, 'cursor', '2');
        self::storedSet($single, 'mark', '2');
        self::storedSet($single, 'outcomes', '{"1":"f"}');

        $overview = self::overview();
        $page     = $overview->page(1);

        self::assertSame(['total' => 2, 'sent' => 2, 'failed' => 0, 'skipped' => 0, 'uncertain' => 0], $page[0]['counts'], 'the uncertain reader got it in the second run');
        self::assertSame(['total' => 3, 'sent' => 1, 'failed' => 1, 'skipped' => 0, 'uncertain' => 1], $page[1]['counts'], 'the mark of a stopped run is uncertain');
        self::assertSame(1, TestOverview::$campaigns, 'only the item of two runs');
        self::assertSame(array_diff_key(TestCampaign::of(self::LIST, self::ITEM2)->counts(), ['date' => 1]), $page[1]['counts'], 'one run counts as its sending');
        self::assertSame($overview->campaign(self::ITEM), $overview->campaign(strtoupper(self::ITEM)), 'the chosen mailing reuses what its row read');
        self::assertSame(1, TestOverview::$campaigns);
    }
}
