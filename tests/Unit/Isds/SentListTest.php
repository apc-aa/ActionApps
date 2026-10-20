<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Client;
use AA\Isds\SentList;
use AA\Isds\SentMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The one reading of the list of sent messages - without ISDS, the list given page by page */
final class SentListTest extends TestCase
{
    private const F = 1_900_000_000;
    private const T = self::F + 3600;
    /** how far each page moves on: it repeats the end of the one before */
    private const STEP = Client::MAX_LIST - SentList::OVERLAP;
    /** a cursor after its first page, whose last record is 6000 */
    private const AFTER_FIRST = ['f' => self::F, 't' => self::T, 'o' => 1 + self::STEP, 'a' => '6000', 'c' => 1];

    // a page the list moved under still lists true records: the consumer gets it before the list is read again
    public function test_read_hands_page_before_anchor_check(): void
    {
        $calls  = [];
        $moved  = [self::sent('7000')];
        $handed = [];
        $page   = static function (array $records, array $cursor) use (&$handed): array {
            $handed[] = [$records, $cursor['o'], $cursor['a'], $cursor['c']];
            return $cursor;
        };

        [$cursor, $end] = SentList::read(self::pages([self::records(5001, Client::MAX_LIST), $moved, []], $calls), SentList::fresh(self::F, self::T), $page);

        self::assertSame([$moved, 1 + self::STEP, '6000', 2], $handed[1], 'with the anchor it lacks, as the page was read');
        self::assertSame([[], 1, '', 3], $handed[2]);
        self::assertSame([[1, 1 + self::STEP, 1], 'ended'], [array_column($calls, 2), $end]);
        self::assertSame(3, $cursor['c']);
    }

    /**
     * @param list<list<SentMessage>> $pages
     * @param list<int>               $offsets
     */
    #[DataProvider('shortPages')]
    public function test_read_ends_on_short_page(array $pages, array $offsets): void
    {
        $calls = [];

        [$cursor, $end] = SentList::read(self::pages([...$pages, self::records(9001, Client::MAX_LIST)], $calls), SentList::fresh(self::F, self::T), self::keep());

        self::assertSame(['ended', count($offsets)], [$end, $cursor['c']]);
        self::assertSame($offsets, array_column($calls, 2));
        self::assertSame([self::F, self::T, 1, Client::MAX_LIST], $calls[0]);
    }

    /** @return array<string, array{list<list<SentMessage>>, list<int>}> */
    public static function shortPages(): array
    {
        $full = self::records(5001, Client::MAX_LIST);
        return [
            'an empty list'           => [[[]], [1]],
            'a record short'          => [[self::records(5001, Client::MAX_LIST - 1)], [1]],
            'a full page, then short' => [[$full, [...array_slice($full, -SentList::OVERLAP), ...self::records(7001, Client::MAX_LIST - SentList::OVERLAP - 1)]], [1, 1 + self::STEP]],
        ];
    }

    // what lay between two pages may have been skipped: the list is read again from its start, even in a later call
    public function test_read_restarts_when_anchor_gone(): void
    {
        $calls = [];
        $list  = self::pages([self::records(5001, Client::MAX_LIST), self::records(7001, Client::MAX_LIST), []], $calls);
        $once  = true;

        [$cursor, $end] = SentList::read($list, SentList::fresh(self::F, self::T), self::keep(), static function () use (&$once): bool {
            [$more, $once] = [$once, false];
            return $more;
        });

        self::assertSame([['f' => self::F, 't' => self::T, 'o' => 1, 'a' => '', 'c' => 2], ''], [$cursor, $end]);

        [$cursor, $end] = SentList::read($list, self::json($cursor), self::keep());

        self::assertSame([[1, 1 + self::STEP, 1], 'ended', 3], [array_column($calls, 2), $end, $cursor['c']]);

        $calls    = [];
        [$cursor] = SentList::read(self::pages([self::records(5001, Client::MAX_LIST), [], []], $calls), SentList::fresh(self::F, self::T), self::keep());
        self::assertSame([[1, 1 + self::STEP, 1], 3], [array_column($calls, 2), $cursor['c']], 'a list that shrank to nothing under the next page');

        $calls = [];
        SentList::read(self::pages([self::records(5001, Client::MAX_LIST), [self::sent('06000')], []], $calls), SentList::fresh(self::F, self::T), self::keep());
        self::assertSame([1, 1 + self::STEP, 1], array_column($calls, 2), 'an id only alike as a number is another record');
    }

    // a list that does not end tells nothing - after MAX_PAGES, whether read at once or a page a call
    public function test_read_gives_up_after_max_pages(): void
    {
        $calls = [];
        $list  = self::pages([self::records(5001, Client::MAX_LIST)], $calls);

        [$cursor, $end] = SentList::read($list, SentList::fresh(self::F, self::T), self::keep());

        self::assertSame(['gave_up', SentList::MAX_PAGES, 1 + SentList::MAX_PAGES * self::STEP], [$end, $cursor['c'], $cursor['o']]);
        self::assertCount(SentList::MAX_PAGES, $calls);

        [$calls, $cursor, $steps] = [[], SentList::fresh(self::F, self::T), 0];
        do {
            [$cursor, $end] = SentList::read($list, self::json($cursor), self::keep(), static fn(): bool => false);
        } while (($end === '') && (++$steps < 2 * SentList::MAX_PAGES));

        self::assertSame(['gave_up', SentList::MAX_PAGES], [$end, count($calls)], 'a page a call');

        $calls = [];
        self::assertSame('gave_up', SentList::read($list, $cursor, self::keep())[1]);
        self::assertSame([], $calls, 'a reading that used up its pages reads no more');

        $full = self::records(5001, Client::MAX_LIST);
        $last = SentList::read(self::pages([...array_fill(0, SentList::MAX_PAGES - 1, $full), array_slice($full, -SentList::OVERLAP)]), SentList::fresh(self::F, self::T), self::keep());
        self::assertSame(['ended', SentList::MAX_PAGES], [$last[1], $last[0]['c']], 'the last page it may read can still end it');
    }

    // a call that reads no page makes no progress, and a run of those ends in error
    public function test_read_reads_first_page_without_budget(): void
    {
        $calls = [];
        $asked = 0;

        [$cursor, $end] = SentList::read(self::pages([self::records(5001, Client::MAX_LIST)], $calls), SentList::fresh(self::F, self::T), self::keep(), static function () use (&$asked): bool {
            ++$asked;
            return false;
        });

        self::assertSame([self::AFTER_FIRST, '', 1, 1], [$cursor, $end, count($calls), $asked]);
    }

    public function test_read_stops_when_more_says_no(): void
    {
        $calls = [];
        $full  = self::records(5001, Client::MAX_LIST);
        $list  = self::pages([$full, $full, $full, array_slice($full, -SentList::OVERLAP)], $calls);
        $left  = 2;

        [$cursor, $end] = SentList::read($list, SentList::fresh(self::F, self::T), self::keep(), static function () use (&$left): bool {
            return $left-- > 0;
        });

        self::assertSame(['', 3, 1 + 3 * self::STEP, '6000'], [$end, $cursor['c'], $cursor['o'], $cursor['a']]);

        [$cursor, $end] = SentList::read($list, self::json($cursor), self::keep());

        self::assertSame(['ended', 4], [$end, $cursor['c']]);
        self::assertSame([1, 1 + self::STEP, 1 + 2 * self::STEP, 1 + 3 * self::STEP], array_column($calls, 2), 'the next call reads on where this one stopped');
    }

    // the consumer's keys are its own; the reading's are the reading's, whatever the consumer returns
    public function test_read_keeps_consumer_keys(): void
    {
        // read back from JSON, a numeric key of the consumer is an integer one
        $from = self::json(['k' => [1, 2]] + SentList::fresh(self::F, self::T) + ['h' => self::T + 60, 'x' => [], '5' => 'five']);
        $page = static function (array $records, array $cursor): array {
            $cursor['x'][] = count($records);
            [$cursor['o'], $cursor['a'], $cursor['c'], $cursor['f'], $cursor['t']] = [99, 'junk', 0, 0, 0];
            return $cursor;
        };
        $list = self::pages([self::records(5001, Client::MAX_LIST), self::records(7001, Client::MAX_LIST), self::records(7001, 10)]);

        [$cursor, $end] = SentList::read($list, $from, $page);

        self::assertSame('ended', $end);
        self::assertSame(['k' => [1, 2], 'f' => self::F, 't' => self::T, 'o' => 1, 'a' => '', 'c' => 3, 'h' => self::T + 60, 'x' => [1000, 1000, 10], 5 => 'five'], $cursor);
    }

    public function test_fits_accepts_own_cursor_after_json(): void
    {
        $restarted = ['o' => 1, 'a' => '', 'c' => 2] + self::AFTER_FIRST;

        self::assertSame(['f' => self::F, 't' => self::T, 'o' => 1, 'a' => '', 'c' => 0], SentList::fresh(self::F, self::T));
        foreach ([SentList::fresh(self::F, self::T), self::AFTER_FIRST, $restarted, ['h' => 5, 'x' => [1 => 'y']] + self::AFTER_FIRST] as $cursor) {
            self::assertTrue(SentList::fits(self::json($cursor), self::F, self::T), json_encode($cursor, JSON_THROW_ON_ERROR));
            self::assertTrue(SentList::fits(self::json($cursor), self::F, self::T + 86400), 'a window that may end later');
        }
        self::assertTrue(SentList::fits(['t' => self::F] + self::AFTER_FIRST, self::F, self::T), 'a window that ends where it starts');
        self::assertTrue(SentList::fits(['o' => 1 + 3 * self::STEP, 'c' => 3] + self::AFTER_FIRST, self::F, self::T), 'as far as its pages took it');
    }

    /**
     * A cursor read() did not leave - edited by hand - is refused: it would fail every call, or skip
     * the list it never read
     * @param array<string, mixed> $edit
     */
    #[DataProvider('editedCursors')]
    public function test_fits_refuses_edited_cursor(array $edit): void
    {
        self::assertFalse(SentList::fits($edit + self::AFTER_FIRST, self::F, self::T));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function editedCursors(): array
    {
        return [
            'an offset before the list'     => [['o' => 0]],
            'an offset past the pages read' => [['o' => 2 + self::STEP]],
            'an offset far past'            => [['o' => 99999]],
            'pages below zero'              => [['o' => 1, 'a' => '', 'c' => -1]],
            'an anchor at the start'        => [['o' => 1]],
            'an offset without anchor'      => [['a' => '']],
            'past the window'               => [['t' => self::T + 1]],
            'an end before its start'       => [['t' => self::F - 1]],
            'another start'                 => [['f' => self::F - 1]],
            'no start'                      => [['f' => null]],
            'no anchor'                     => [['a' => null]],
            'a start as text'               => [['f' => (string) self::F]],
            'an end as text'                => [['t' => (string) self::T]],
            'an offset as text'             => [['o' => '981']],
            'pages as text'                 => [['c' => '1']],
            'pages as a fraction'           => [['c' => 1.0]],
            'an anchor as a number'         => [['a' => 6000]],
        ];
    }

    private static function sent(string $dmId): SentMessage
    {
        return new SentMessage($dmId, 4, '99-1', 'b000009', self::F + 60, null, 'Pozvánka');
    }

    /** @return list<SentMessage> $n records with ids from $first on */
    private static function records(int $first, int $n): array
    {
        return array_map(static fn(int $i): SentMessage => self::sent((string) $i), range($first, $first + $n - 1));
    }

    /**
     * Client::sentMessages() answering $pages in order, the last one again and again
     * @param list<list<SentMessage>> $pages
     * @param list<list<int>>         $calls the from, to, offset and limit of each call
     * @return \Closure(int, int, int, int): list<SentMessage>
     */
    private static function pages(array $pages, array &$calls = []): \Closure
    {
        return static function (int $from, int $to, int $offset, int $limit) use (&$pages, &$calls): array {
            $calls[] = [$from, $to, $offset, $limit];
            // a reading that never ends fails the test instead of hanging it
            if (count($calls) > 2 * SentList::MAX_PAGES) {
                throw new \LogicException('Read for ever');
            }
            return (count($pages) > 1) ? array_shift($pages) : $pages[0];
        };
    }

    /** @return \Closure(list<SentMessage>, array<string, mixed>): array<string, mixed> a consumer that takes nothing */
    private static function keep(): \Closure
    {
        return static fn(array $records, array $cursor): array => $cursor;
    }

    /**
     * $cursor as a step stores it in its position
     * @param array<mixed> $cursor
     * @return array<string, mixed>
     */
    private static function json(array $cursor): array
    {
        return json_decode(json_encode($cursor, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
