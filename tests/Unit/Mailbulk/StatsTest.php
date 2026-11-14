<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA;
use AA\Mailbulk\Stats;
use PHPUnit\Framework\TestCase;

/** Stats over events in memory: what AA_Set would find and what GetItemContent() would read.
 *  Static, as the request cache keys the call by the object too. */
final class MemoryStats extends Stats
{
    /** @var array<string, array{slice: string, item: string, kind: string, url: string, reader: string}> id => event */
    public static array $events = [];
    /** @var list<string> each search and each read */
    public static array $reads = [];

    public static function reset(): void
    {
        self::$events = [];
        self::$reads  = [];
    }

    public static function add(string $kind, string $reader, string $url = '', string $item = StatsTest::ITEM): void
    {
        $id                = md5(implode("\n", [$item, $reader, $kind, $url]));
        self::$events[$id] = ['slice' => StatsTest::STAT_SLICE, 'item' => $item, 'kind' => $kind, 'url' => $url, 'reader' => $reader];
    }

    protected function events(string $statSliceId, array $itemIds, string $kind): array
    {
        self::$reads[] = "events $kind";
        return array_keys(array_filter(
            self::$events,
            static fn(array $e): bool => ($e['slice'] === $statSliceId) && in_array($e['item'], $itemIds, true) && ($e['kind'] === $kind),
        ));
    }

    protected function content(array $ids, array $fields): array
    {
        self::$reads[] = 'content ' . implode(',', $fields);
        $content       = [];
        foreach ($ids as $id) {
            $content[$id] = array_intersect_key([
                'relation........' => [['value' => self::$events[$id]['item']]],
                'source_href.....' => [['value' => self::$events[$id]['url']]],
                'relation.......1' => [['value' => self::$events[$id]['reader']]],
            ], array_flip($fields));
        }
        return $content;
    }
}

/** Opened is the open events, clicked the readers with a click; the links by how many readers clicked them. */
final class StatsTest extends TestCase
{
    public const STAT_SLICE = '5555aaaa5555aaaa5555aaaa5555aaaa';
    public const ITEM       = '0123456789abcdef0123456789abcdef';
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

        self::assertSame(['opened' => 3, 'clicked' => 2], $stats->counts(self::STAT_SLICE, self::ITEM), 'three clicks of two readers');
        self::assertSame(['opened' => 3, 'clicked' => 2], $stats->counts(self::STAT_SLICE, strtoupper(self::ITEM)));
        $stats->links(self::STAT_SLICE, self::ITEM);
        self::assertSame(['events open', 'events click', 'content source_href.....,relation.......1'], MemoryStats::$reads, 'one read of each per request');
    }

    /** a page of sendings: one search per kind and one read of the events, whatever the number of the sendings */
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

        $figures = $stats->countsFor(self::STAT_SLICE, [self::ITEM, strtoupper(self::OTHER), '3333456789abcdef0123456789abcdef']);

        self::assertSame([
            self::ITEM                         => ['opened' => 3, 'clicked' => 2],
            self::OTHER                        => ['opened' => 1, 'clicked' => 1],
            '3333456789abcdef0123456789abcdef' => ['opened' => 0, 'clicked' => 0],
        ], $figures, 'the same figures as counts() of each');
        self::assertSame(['events open', 'events click', 'content relation........,relation.......1'], MemoryStats::$reads);
        self::assertSame([], $stats->countsFor(self::STAT_SLICE, []));
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
