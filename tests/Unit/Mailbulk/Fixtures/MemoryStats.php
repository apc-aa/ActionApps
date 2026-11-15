<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk\Fixtures;

use AA\Mailbulk\Stats;
use AA\Mailbulk\Tracker;
use AA\Mailbulk\TrackToken;

/** Stats over events in memory, under the ids Tracker gives them: what AA_Set would find and what
 *  GetItemContent() would read. Static, as the request cache keys the call by the object too. */
final class MemoryStats extends Stats
{
    public const STAT_SLICE = '5555aaaa5555aaaa5555aaaa5555aaaa';
    public const LIST       = 'abcde';
    public const ITEM       = '0123456789abcdef0123456789abcdef';

    /** @var array<string, array{slice: string, item: string, kind: string, url: string, reader: string, time: int}> id => event */
    public static array $events = [];
    /** @var list<string> each search and each read */
    public static array $reads = [];

    public static function reset(): void
    {
        self::$events = [];
        self::$reads  = [];
    }

    /** an event as Tracker stores it: a click stores no open here - add() it when the test wants one */
    public static function add(string $kind, string $reader, string $url = '', string $item = self::ITEM, int $time = 0, string $list = self::LIST): void
    {
        $id                = Tracker::eventId(self::STAT_SLICE, $list, $item, $reader, ($kind === 'click') ? TrackToken::CLICK : TrackToken::OPEN, $url);
        self::$events[$id] = ['slice' => self::STAT_SLICE, 'item' => $item, 'kind' => $kind, 'url' => $url, 'reader' => $reader, 'time' => $time];
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
                'category........' => [['value' => self::$events[$id]['kind']]],
                'post_date.......' => [['value' => self::$events[$id]['time']]],
            ], array_flip($fields));
        }
        return $content;
    }

    protected function postDates(array $ids): array
    {
        self::$reads[] = 'dates ' . count($ids);
        return array_map(static fn(array $e): int => $e['time'], array_intersect_key(self::$events, array_flip($ids)));
    }
}
