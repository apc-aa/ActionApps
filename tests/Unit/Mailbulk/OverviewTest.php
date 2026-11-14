<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\IO\DB\DB_AA;
use AA\Mailbulk\Overview;
use AA\Mailbulk\Recipients;
use AA\Mailbulk\Run;
use AA_Module;
use AA_Module_Alerts;
use AA_Moduleconfig;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Mailbulk\Fixtures\TestRun;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
require_once __DIR__ . '/Fixtures/FakeLetter.php';
require_once __DIR__ . '/Fixtures/ScriptedMailer.php';
require_once __DIR__ . '/Fixtures/TestRun.php';

/** An overview over TestRun runs; the list of a module is what the test says. */
final class TestOverview extends Overview
{
    /** @var array<string, string> module id => list id */
    public static array $lists = [];

    protected static function runClass(): string
    {
        return TestRun::class;
    }

    protected static function listOf(string $moduleId): string
    {
        return self::$lists[$moduleId] ?? '';
    }
}

/** An Alerts module in the module cache, no database behind it. */
final class CachedAlertsModule extends AA_Module_Alerts
{
    public static function register(string $moduleId): void
    {
        AA_Module::$_modules[$moduleId] = new self($moduleId);
    }

    public static function forget(string $moduleId): void
    {
        unset(AA_Module::$_modules[$moduleId]);
    }
}

/** A module of another type in the module cache. */
final class CachedSliceLikeModule extends AA_Module
{
    public static function register(string $moduleId): void
    {
        AA_Module::$_modules[$moduleId] = new self($moduleId);
    }

    public static function getModuleconfig(): AA_Moduleconfig
    {
        return new AA_Moduleconfig('X', 'Fake', self::class, 'fake/', 'fake/menu.php', [], false);
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

    private const MODULE = '7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a7a';
    private const OTHER  = '7b7b7b7b7b7b7b7b7b7b7b7b7b7b7b7b';
    private const LIST   = 'abcde';
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
        TestOverview::$lists = [self::MODULE => self::LIST, self::OTHER => 'other'];
        CachedAlertsModule::register(self::MODULE);
    }

    protected function tearDown(): void
    {
        TestRun::reset();
        TestOverview::$lists = [];
        CachedAlertsModule::forget(self::MODULE);
        CachedAlertsModule::forget(self::OTHER);
        $this->tablesTearDown();
    }

    /** a run of $item to $list started at $at; its id */
    private static function sending(string $item, int $at, string $title = 'Newsletter', string $list = self::LIST): string
    {
        TestRun::$now = $at;
        return DB_AA::transaction(static fn(): string => TestRun::start([
            'subject'    => $item,
            'user'       => self::USER,
            'position'   => ['i' => 0],
            'list'       => $list,
            'title'      => $title,
            'recipients' => Recipients::freeze($item, ['a0000000000000000000000000000001']),
            'total'      => '1',
            'cursor'     => '0',
        ])->getId());
    }

    private static function overview(): TestOverview
    {
        return TestOverview::forModule(self::MODULE) ?? throw new \LogicException('No overview');
    }

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
            ['item' => self::ITEM, 'title' => 'Second name', 'date' => self::NOW, 'state' => Run::DONE],
            ['item' => self::ITEM2, 'title' => 'Other', 'date' => self::NOW - 50, 'state' => Run::SENDING],
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

    public function test_forModule_notAnAlertsModule_isNull(): void
    {
        CachedSliceLikeModule::register(self::OTHER);

        self::assertNull(TestOverview::forModule(self::OTHER), 'another type: a letter of its own means something else');
        self::assertNull(TestOverview::forModule('nonsense'));
        self::assertInstanceOf(TestOverview::class, TestOverview::forModule(self::MODULE));
    }

    public function test_forModule_alertsModuleWithoutAList_isNull(): void
    {
        TestOverview::$lists = [];

        self::assertNull(TestOverview::forModule(self::MODULE));
    }
}
