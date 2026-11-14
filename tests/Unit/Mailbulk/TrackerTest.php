<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\Mailbulk\Tracker;
use AA\Mailbulk\TrackToken;
use ItemContent;
use PHPUnit\Framework\TestCase;

/** A Tracker over items in memory: what a store keeps, every write, every lock. */
final class MemoryTracker extends Tracker
{
    /** @var array<string, string> list => long id of its statistics slice */
    public array $statSlices = [TrackerTest::LIST => TrackerTest::STAT_SLICE];
    /** @var array<string, array{slice: string, fields: array<string, string>}> */
    public array $stored = [];
    /** @var list<array{mode: string, flags: array<mixed>, id: string}> */
    public array $writes = [];
    /** @var list<string> */
    public array $lockLog = [];
    public bool $lockedByOthers = false;

    protected function statSliceId(string $list): string
    {
        return $this->statSlices[$list] ?? '';
    }

    protected function lock(string $eventId): bool
    {
        $this->lockLog[] = "lock $eventId";
        return !$this->lockedByOthers;
    }

    protected function unlock(string $eventId): void
    {
        $this->lockLog[] = "unlock $eventId";
    }

    protected function store(ItemContent $content, string $mode, array $flags): bool
    {
        $id             = $content->getItemID();
        $this->writes[] = ['mode' => $mode, 'flags' => $flags, 'id' => $id];
        if (isset($this->stored[$id])) {
            return false;
        }
        $fields = [];
        foreach ($content->getContent() as $field => $values) {
            if (!in_array($field, ['id..............', 'unpacked_id.....', 'slice_id........'], true)) {
                $fields[$field] = (string) $values[0]['value'];
            }
        }
        $this->stored[$id] = ['slice' => $content->getSliceID(), 'fields' => $fields];
        return true;
    }

    /** @return list<string> category of each stored event */
    public function kinds(): array
    {
        return array_values(array_map(static fn(array $item): string => $item['fields']['category........'], $this->stored));
    }
}

/** Opens and clicks become items of the statistics slice of the list - each one once, never more. */
final class TrackerTest extends TestCase
{
    public const LIST       = 'aB3x9';
    public const STAT_SLICE = '5555aaaa5555aaaa5555aaaa5555aaaa';
    private const ITEM      = '0123456789abcdef0123456789abcdef';
    private const READER    = 'fedcba9876543210fedcba9876543210';
    private const URL       = 'https://example.org/news/?a=1';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // DB_PASSWORD, DB_NAME for Secret::key()
    }

    private static function token(string $kind, string $url = '', string $list = self::LIST): TrackToken
    {
        $token = TrackToken::parse(TrackToken::make($list, self::ITEM, self::READER, $kind, $url));
        self::assertNotNull($token);
        return $token;
    }

    public function test_record_open_storesOneItem_withTheFields(): void
    {
        $tracker = new MemoryTracker();
        $before  = time();

        $this->assertTrue($tracker->record(self::token(TrackToken::OPEN)));

        $this->assertCount(1, $tracker->stored);
        $id   = (string) array_key_first($tracker->stored);
        $item = $tracker->stored[$id];
        $this->assertSame(Tracker::eventId(self::LIST, self::ITEM, self::READER, TrackToken::OPEN, ''), $id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $id);
        $this->assertSame(self::STAT_SLICE, $item['slice']);
        $this->assertSame(self::ITEM, $item['fields']['relation........']);
        $this->assertSame(self::READER, $item['fields']['relation.......1']);
        $this->assertSame('open', $item['fields']['category........']);
        $this->assertArrayNotHasKey('source_href.....', $item['fields']);
        $this->assertGreaterThanOrEqual($before, (int) $item['fields']['publish_date....']);
        $this->assertLessThanOrEqual(time(), (int) $item['fields']['publish_date....']);
    }

    public function test_record_sameTokenTwice_storesOnce(): void
    {
        $tracker = new MemoryTracker();

        $this->assertTrue($tracker->record(self::token(TrackToken::OPEN)));
        $this->assertFalse($tracker->record(self::token(TrackToken::OPEN)));

        $this->assertCount(1, $tracker->stored);
    }

    public function test_record_click_storesClickAndOpen(): void
    {
        $tracker = new MemoryTracker();

        $this->assertTrue($tracker->record(self::token(TrackToken::CLICK, self::URL)));

        $this->assertSame(['open', 'click'], $tracker->kinds());
        $click = $tracker->stored[Tracker::eventId(self::LIST, self::ITEM, self::READER, TrackToken::CLICK, self::URL)];
        $this->assertSame(self::URL, $click['fields']['source_href.....']);
        $this->assertSame(self::ITEM, $click['fields']['relation........']);
        $this->assertSame(self::READER, $click['fields']['relation.......1']);
    }

    public function test_record_clickAfterOpen_storesOnlyClick(): void
    {
        $tracker = new MemoryTracker();
        $tracker->record(self::token(TrackToken::OPEN));
        $opened = $tracker->stored;

        $this->assertTrue($tracker->record(self::token(TrackToken::CLICK, self::URL)));

        $this->assertSame(['open', 'click'], $tracker->kinds());
        $this->assertSame($opened[array_key_first($opened)], $tracker->stored[array_key_first($opened)], 'the first open stays as it was');
        // another link of the same mail counts as its own click
        $this->assertTrue($tracker->record(self::token(TrackToken::CLICK, self::URL . '&b=2')));
        $this->assertSame(['open', 'click', 'click'], $tracker->kinds());
    }

    public function test_record_listWithoutStatSlice_storesNothing(): void
    {
        $tracker = new MemoryTracker();

        $this->assertFalse($tracker->record(self::token(TrackToken::CLICK, self::URL, 'zzzzz')));

        $this->assertSame([], $tracker->writes);
        $this->assertSame([], $tracker->lockLog);
    }

    public function test_record_lockNotAcquired_storesNothing(): void
    {
        $tracker                 = new MemoryTracker();
        $tracker->lockedByOthers = true;

        $this->assertFalse($tracker->record(self::token(TrackToken::CLICK, self::URL)));

        $this->assertSame([], $tracker->writes);
        $this->assertNotSame([], $tracker->lockLog);
        $this->assertStringNotContainsString('unlock', implode(' ', $tracker->lockLog), 'no unlock of a lock not got');
    }

    public function test_record_storesWithoutFeedingEventsAndCacheInvalidation(): void
    {
        $tracker = new MemoryTracker();

        $tracker->record(self::token(TrackToken::CLICK, self::URL));

        $this->assertCount(2, $tracker->writes);
        foreach ($tracker->writes as $write) {
            $this->assertSame('insert_if_new', $write['mode']);
            $this->assertSame([false, false, false, false], $write['flags']);
            // the write runs under the lock of its own event
            $this->assertContains('lock ' . $write['id'], $tracker->lockLog);
            $this->assertContains('unlock ' . $write['id'], $tracker->lockLog);
        }
    }
}
