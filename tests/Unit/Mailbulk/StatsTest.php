<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA;
use AA\Mailbulk\Stats;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Mailbulk\Fixtures\MemoryStats;

require_once __DIR__ . '/Fixtures/MemoryStats.php';

/** Opened is the readers with an open or a click, clicked the readers with a click; the links by how many readers clicked them. */
final class StatsTest extends TestCase
{
    public const STAT_SLICE = MemoryStats::STAT_SLICE;
    public const ITEM       = MemoryStats::ITEM;
    private const LIST_ID   = MemoryStats::LIST;
    private const OTHER     = 'ffff456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // AA::Contentcache()
    }

    protected function setUp(): void
    {
        AA::Contentcache()->clear();
        MemoryStats::reset();
    }

    protected function tearDown(): void
    {
        AA::Contentcache()->clear();
    }

    private static function reader(int $i): string
    {
        return sprintf('a%031x', $i);
    }

    public function test_counts_openedIsEvents_clickedIsDistinctReaders(): void
    {
        $stats = new MemoryStats();
        foreach ([1, 2, 3] as $i) {
            MemoryStats::add('open', self::reader($i));
        }
        MemoryStats::add('click', self::reader(1), 'https://example.org/a');
        MemoryStats::add('click', self::reader(1), 'https://example.org/b');
        MemoryStats::add('click', self::reader(2), 'https://example.org/a');
        MemoryStats::add('open', self::reader(9), '', self::OTHER);
        MemoryStats::add('click', self::reader(9), 'https://example.org/a', self::OTHER);

        self::assertSame(['opened' => 3, 'clicked' => 2], $stats->counts(self::STAT_SLICE, self::LIST_ID, self::ITEM), 'three clicks of two readers');
        self::assertSame(['opened' => 3, 'clicked' => 2], $stats->counts(self::STAT_SLICE, self::LIST_ID, strtoupper(self::ITEM)));
        $stats->links(self::STAT_SLICE, self::ITEM);
        self::assertSame(['events open', 'events click', 'content source_href.....,relation.......1,post_date.......'], MemoryStats::$reads, 'one read of each per request');
    }

    /** a click proves the mail was read - opened counts it when its open was thrown out, as the table of the recipients does */
    public function test_counts_clickWithoutItsOpen_isOpenedToo(): void
    {
        MemoryStats::add('open', self::reader(1));
        MemoryStats::add('click', self::reader(1), 'https://example.org/a');
        MemoryStats::add('click', self::reader(2), 'https://example.org/a');

        self::assertSame(['opened' => 2, 'clicked' => 2], (new MemoryStats())->counts(self::STAT_SLICE, self::LIST_ID, self::ITEM));
        self::assertSame([self::ITEM => ['opened' => 2, 'clicked' => 2]], (new MemoryStats())->countsFor(self::STAT_SLICE, self::LIST_ID, [self::ITEM]));
    }

    /** a page of sendings: the opens by their ids per sending, only the clicks read - once, whatever the number of the sendings */
    public function test_countsFor_manyItemsInOneGo(): void
    {
        $stats = new MemoryStats();
        foreach ([1, 2, 3] as $i) {
            MemoryStats::add('open', self::reader($i));
        }
        MemoryStats::add('click', self::reader(1), 'https://example.org/a');
        MemoryStats::add('click', self::reader(1), 'https://example.org/b');
        MemoryStats::add('click', self::reader(2), 'https://example.org/a');
        MemoryStats::add('open', self::reader(9), '', self::OTHER);
        MemoryStats::add('click', self::reader(9), 'https://example.org/a', self::OTHER);
        MemoryStats::add('open', self::reader(7), '', '2222456789abcdef0123456789abcdef');

        $figures = $stats->countsFor(self::STAT_SLICE, self::LIST_ID, [self::ITEM, strtoupper(self::OTHER), '3333456789abcdef0123456789abcdef']);

        self::assertSame([
            self::ITEM                         => ['opened' => 3, 'clicked' => 2],
            self::OTHER                        => ['opened' => 1, 'clicked' => 1],
            '3333456789abcdef0123456789abcdef' => ['opened' => 0, 'clicked' => 0],
        ], $figures, 'the same figures as counts() of each');
        self::assertSame(['events click', 'content relation........,relation.......1', 'events open', 'events open', 'events open'], MemoryStats::$reads, 'no open read');
        MemoryStats::$reads = [];
        $stats->counts(self::STAT_SLICE, self::LIST_ID, self::ITEM);
        self::assertSame(['events click', 'content source_href.....,relation.......1,post_date.......'], MemoryStats::$reads, 'the opens of the chosen sending searched once per request');
        self::assertSame([], $stats->countsFor(self::STAT_SLICE, self::LIST_ID, []));
    }

    public function test_links_countsReadersPerUrl_sortedDesc(): void
    {
        $stats = new MemoryStats();
        MemoryStats::add('click', self::reader(1), 'https://example.org/a');
        MemoryStats::add('click', self::reader(1), 'https://example.org/b');
        MemoryStats::add('click', self::reader(2), 'https://example.org/b');
        MemoryStats::add('click', self::reader(3), 'https://example.org/b');
        MemoryStats::add('click', self::reader(2), 'https://example.org/c');
        MemoryStats::add('click', self::reader(3), 'https://example.org/c');
        MemoryStats::add('click', self::reader(4), 'https://example.org/a', self::OTHER);

        self::assertSame([
            ['url' => 'https://example.org/b', 'readers' => 3],
            ['url' => 'https://example.org/c', 'readers' => 2],
            ['url' => 'https://example.org/a', 'readers' => 1],
        ], $stats->links(self::STAT_SLICE, self::ITEM));
        self::assertSame([], $stats->links(self::STAT_SLICE, '1111456789abcdef0123456789abcdef'), 'no clicks, no rows');
    }

    /** the table of the recipients: the opens of its readers computed and only those read; the clicks with their time */
    public function test_openTimes_onlyTheEventsOfTheReaders_andClicksWithTime(): void
    {
        $stats = new MemoryStats();
        MemoryStats::add('open', self::reader(1), '', self::ITEM, 100);
        MemoryStats::add('open', self::reader(2), '', self::ITEM, 200, 'other');   // the same item through another list
        MemoryStats::add('open', self::reader(4), '', self::ITEM, 400);
        MemoryStats::add('click', self::reader(1), 'https://example.org/a', self::ITEM, 300);

        self::assertSame([self::reader(1) => 100], $stats->openTimes(self::STAT_SLICE, self::LIST_ID, strtoupper(self::ITEM), [self::reader(1), self::reader(2), self::reader(3)]));
        self::assertSame(['dates 3'], MemoryStats::$reads, 'no search of all the opens');
        self::assertSame([['url' => 'https://example.org/a', 'reader' => self::reader(1), 'time' => 300]], $stats->clicks(self::STAT_SLICE, self::ITEM));
    }

    public function test_links_capsAt50(): void
    {
        $stats = new MemoryStats();
        for ($i = 0; $i < 60; ++$i) {
            MemoryStats::add('click', self::reader($i), sprintf('https://example.org/%02d', $i));
        }
        MemoryStats::add('click', self::reader(100), 'https://example.org/59');

        $links = $stats->links(self::STAT_SLICE, self::ITEM);

        self::assertCount(Stats::MAX_LINKS, $links);
        self::assertSame(['url' => 'https://example.org/59', 'readers' => 2], $links[0], 'the most clicked first');
        self::assertSame('https://example.org/00', $links[1]['url'], 'a tie in the order of the address');
        self::assertSame('https://example.org/48', $links[49]['url']);
    }
}
