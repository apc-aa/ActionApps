<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Client;
use AA\Isds\Lookup;
use AA\Isds\SentMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** What became of messages of unknown fate, by the list of sent messages - without ISDS, the list given page by page */
final class LookupTest extends TestCase
{
    private const NOW     = 1_900_000_000;
    private const SUBJECT = 'Pozvánka na setkání';
    /** message 2 of campaign 4711, to the box b000001, tried ten minutes ago */
    private const WANTED = [1 => ['ref' => '4711-2', 'box' => 'b000001', 'at' => self::NOW - 600]];
    /** how long after its attempt a message not listed is waited for */
    private const WAIT = Client::CREATE_TIMEOUT + Lookup::GRACE;
    /** where the second page starts: it repeats the end of the first */
    private const SECOND = Client::MAX_LIST - Lookup::OVERLAP + 1;

    public function test_resolve_finds_by_reference_and_box(): void
    {
        $sent = self::sent('9001', '4711-2', 'b000001', self::NOW - 590);
        $list = self::pages([[self::sent('9000', '4711-1', 'b000000', self::NOW - 595), $sent]]);

        $lookup = Lookup::resolve($list, self::WANTED, self::SUBJECT, self::NOW);

        self::assertEquals([1 => $sent], $lookup->found);
        self::assertSame([[], [], [], 0, []], [$lookup->missing, $lookup->unsure, $lookup->waiting, $lookup->until(), $lookup->scan]);
    }

    /**
     * Not the message of the sending: a reference is unique in one database only, and a test message
     * of the campaign has one of its own
     */
    #[DataProvider('others')]
    public function test_resolve_never_matches_another_message(SentMessage $other): void
    {
        $lookup = Lookup::resolve(self::pages([[$other]]), self::WANTED, self::SUBJECT, self::NOW - 600 + self::WAIT);

        self::assertSame([[], [1], []], [$lookup->found, $lookup->missing, $lookup->unsure]);
    }

    /** @return array<string, array{SentMessage}> */
    public static function others(): array
    {
        return [
            'a test message of the campaign' => [self::sent('9001', '4711-T2', 'b000001', self::NOW - 590)],
            'another box'                    => [self::sent('9001', '4711-2', 'b000002', self::NOW - 590)],
            'delivered before it was tried'  => [self::sent('9001', '4711-2', 'b000001', self::NOW - 600 - Lookup::SKEW - 1)],
            'another campaign'               => [self::sent('9001', '4712-2', 'b000001', self::NOW - 590)],
        ];
    }

    /**
     * Its reference, box and time with another subject - or none, as a record may list it: maybe the
     * message, with its subject read otherwise. Never taken for one that did not go, and not waited for.
     */
    #[DataProvider('nearly')]
    public function test_resolve_other_subject_alone_is_unsure(string $subject): void
    {
        foreach ([self::NOW, self::NOW - 600 + self::WAIT] as $now) {
            $lookup = Lookup::resolve(self::pages([[self::sent('9001', '4711-2', 'b000001', self::NOW - 590, $subject)]]), self::WANTED, self::SUBJECT, $now);

            self::assertSame([[], [], [1], []], [$lookup->found, $lookup->missing, $lookup->unsure, $lookup->waiting]);
        }
    }

    /** @return array<string, array{string}> */
    public static function nearly(): array
    {
        return ['another subject' => ['Pozvánka'], 'no subject listed' => ['']];
    }

    // the subject only tells candidates apart: the one of the sending is the message
    public function test_resolve_subject_chooses_between_candidates(): void
    {
        $sent = self::sent('9002', '4711-2', 'b000001', self::NOW - 580);
        $list = self::pages([[self::sent('9001', '4711-2', 'b000001', self::NOW - 590, 'Pozvánka'), $sent]]);

        self::assertEquals([1 => $sent], Lookup::resolve($list, self::WANTED, self::SUBJECT, self::NOW)->found);
    }

    // ISDS replaces or drops these (WS_manipulace_s_datovymi_zpravami.pdf 1.3.5); U+202F it lists both ways
    #[DataProvider('readings')]
    public function test_resolve_compares_subject_as_isds_keeps_it(string $sentWith, string $listed): void
    {
        $sent = self::sent('9001', '4711-2', 'b000001', self::NOW - 590, $listed);

        $lookup = Lookup::resolve(self::pages([[$sent]]), self::WANTED, $sentWith, self::NOW);

        self::assertEquals([1 => $sent], $lookup->found);
    }

    /** @return array<string, array{string, string}> */
    public static function readings(): array
    {
        return [
            'no-break space, soft hyphen' => ["Pozvánka\u{00A0}na set\u{00AD}kání", 'Pozvánka na setkání'],
            'U+202F made a space'         => ["Zastupitelstvo obce\u{202F}2026", 'Zastupitelstvo obce 2026'],
            'U+202F dropped'              => ["Zastupitelstvo obce\u{202F}2026", 'Zastupitelstvo obce2026'],
        ];
    }

    // the clocks of AA and ISDS differ a little: a message delivered within the skew of its attempt is still the one
    public function test_resolve_allows_for_clock_skew(): void
    {
        $sent = self::sent('9001', '4711-2', 'b000001', self::NOW - 600 - Lookup::SKEW);

        self::assertEquals([1 => $sent], Lookup::resolve(self::pages([[$sent]]), self::WANTED, self::SUBJECT, self::NOW)->found);
    }

    // the list is chosen by the time of delivery; one without it - refused by the antivirus check - is listed all the same
    #[DataProvider('undelivered')]
    public function test_resolve_takes_record_without_delivery_time(int $status): void
    {
        $sent = new SentMessage('9001', $status, '4711-2', 'b000001', null, null, self::SUBJECT);

        self::assertEquals([1 => $sent], Lookup::resolve(self::pages([[$sent]]), self::WANTED, self::SUBJECT, self::NOW)->found);
    }

    /** @return array<string, array{int}> */
    public static function undelivered(): array
    {
        return ['stamped' => [2], 'refused by the antivirus check' => [SentMessage::AV_REFUSED]];
    }

    // each page repeats the end of the one before; a record read twice there is one message, not two
    public function test_resolve_pages_overlap_and_count_a_record_once(): void
    {
        $sent  = self::sent('9001', '4711-2', 'b000001', self::NOW - 590);
        $full  = [...self::others1000(Client::MAX_LIST - 1), $sent];
        $calls = [];
        $list  = self::pages([$full, [...array_slice($full, -Lookup::OVERLAP), self::sent('9000', '4711-1', 'b000000', self::NOW - 650)]], $calls);
        $both  = self::WANTED + [0 => ['ref' => '4711-1', 'box' => 'b000000', 'at' => self::NOW - 700]];

        $lookup = Lookup::resolve($list, $both, self::SUBJECT, self::NOW);

        self::assertSame([0, 1], array_keys($lookup->found));
        self::assertSame([], $lookup->unsure);
        $from = self::NOW - 700 - Lookup::SKEW;
        self::assertSame([[$from, self::NOW, 1, 1000], [$from, self::NOW, self::SECOND, 1000]], $calls, 'from a minute before the first attempt till now');
    }

    // a page without the last record of the one before: the list moved, what lay between may be skipped - read again from the start
    public function test_resolve_reads_again_when_the_list_moved(): void
    {
        $calls = [];
        $sent  = self::sent('9001', '4711-2', 'b000001', self::NOW - 590);
        $list  = self::pages([self::others1000(Client::MAX_LIST), [self::sent('7000', '99-1', 'b000009', self::NOW - 300)], [$sent]], $calls);

        $lookup = Lookup::resolve($list, self::WANTED, self::SUBJECT, self::NOW - 600 + self::WAIT);

        self::assertEquals([[1 => $sent], []], [$lookup->found, $lookup->missing], 'never taken for one that did not go');
        self::assertSame([1, self::SECOND, 1], array_column($calls, 2));
    }

    // the list holds a message once it reached the box: the antivirus check takes up to 240 s, and ISDS lags at times
    public function test_resolve_waits_an_hour_after_the_longest_attempt(): void
    {
        $wanted = self::WANTED + [3 => ['ref' => '4711-4', 'box' => 'b000003', 'at' => self::NOW - 100]];

        $lookup = Lookup::resolve(self::pages([[]]), $wanted, self::SUBJECT, self::NOW);

        self::assertSame(3900, self::WAIT);
        self::assertSame([1 => self::NOW - 600 + self::WAIT, 3 => self::NOW - 100 + self::WAIT], $lookup->waiting);
        self::assertSame([[], [], self::NOW - 600 + self::WAIT], [$lookup->found, $lookup->missing, $lookup->until()], 'looked up again when the first may be decided');
    }

    public function test_resolve_missing_after_the_wait(): void
    {
        $lookup = Lookup::resolve(self::pages([[]]), self::WANTED, self::SUBJECT, self::NOW - 600 + self::WAIT);

        self::assertSame([[1], [], 0], [$lookup->missing, $lookup->waiting, $lookup->until()]);
    }

    // the list ends where the last message may have reached its box, however late the lookup
    public function test_resolve_window_ends_with_the_last_wait(): void
    {
        $calls = [];

        Lookup::resolve(self::pages([[]], $calls), self::WANTED, self::SUBJECT, self::NOW + 86400);

        self::assertSame([[self::NOW - 600 - Lookup::SKEW, self::NOW - 600 + self::WAIT, 1, 1000]], $calls);
    }

    // an attempt at no time, ahead of the clock, or older than ISDS keeps its list: never taken for a message that did not go
    #[DataProvider('noTimes')]
    public function test_resolve_attempt_at_no_sane_time_is_unsure(int $at): void
    {
        $calls  = [];
        $lookup = Lookup::resolve(self::pages([[]], $calls), [1 => ['at' => $at] + self::WANTED[1]], self::SUBJECT, self::NOW);

        self::assertSame([[], [], [1], [], []], [$lookup->found, $lookup->missing, $lookup->unsure, $lookup->waiting, $calls]);
    }

    /** @return array<string, array{int}> */
    public static function noTimes(): array
    {
        return [
            'none'                => [0],
            'a second after 1970' => [1],
            'ahead'               => [self::NOW + Lookup::SKEW + 1],
            'a month ago'         => [self::NOW - Lookup::KEPT],
            'a hundred days ago'  => [self::NOW - 100 * 86400],
        ];
    }

    // which one is the message is for a person to tell; a later list only adds to them
    public function test_resolve_two_matches_stay_unsure(): void
    {
        $list = self::pages([[self::sent('9001', '4711-2', 'b000001', self::NOW - 590), self::sent('9002', '4711-2', 'b000001', self::NOW - 580)]]);

        $lookup = Lookup::resolve($list, self::WANTED, self::SUBJECT, self::NOW);

        self::assertSame([[], [], [1], []], [$lookup->found, $lookup->missing, $lookup->unsure, $lookup->waiting]);
    }

    // a list that does not end tells neither a message nor its absence
    public function test_resolve_endless_list_is_unsure(): void
    {
        $sent  = self::sent('9001', '4711-2', 'b000001', self::NOW - 590);
        $page  = [$sent, ...self::others1000(Client::MAX_LIST - 1)];
        $calls = [];

        $lookup = Lookup::resolve(self::pages([$page], $calls), self::WANTED + [0 => ['ref' => '4711-1', 'box' => 'b000000', 'at' => self::NOW - 2000]], self::SUBJECT, self::NOW);

        self::assertSame([[], [], [0, 1], []], [$lookup->found, $lookup->missing, $lookup->unsure, $lookup->scan]);
        self::assertCount(Lookup::MAX_PAGES, $calls);
    }

    // the step has no time for another page: nothing is decided, the next step reads on from where this one stopped
    public function test_resolve_reads_on_in_the_next_step(): void
    {
        $sent  = self::sent('9001', '4711-2', 'b000001', self::NOW - 590);
        $full  = self::others1000(Client::MAX_LIST);
        $calls = [];
        $list  = self::pages([$full, [...array_slice($full, -Lookup::OVERLAP), $sent]], $calls);

        $first = Lookup::resolve($list, self::WANTED, self::SUBJECT, self::NOW - 600 + self::WAIT, [], static fn(): bool => false);

        self::assertSame([[], [], [], []], [$first->found, $first->missing, $first->unsure, $first->waiting], 'a part of the list decides nothing');
        self::assertSame([self::SECOND, 1], [$first->scan['o'], $first->scan['c']]);
        $scan = json_decode(json_encode($first->scan, JSON_THROW_ON_ERROR), true);

        $next = Lookup::resolve($list, self::WANTED, self::SUBJECT, self::NOW + 86400, $scan);

        self::assertSame([[1 => '9001'], [], []], [array_map(static fn(SentMessage $s): string => $s->dmId, $next->found), $next->missing, $next->scan]);
        self::assertSame([1, self::SECOND], array_column($calls, 2));
        self::assertSame([self::NOW - 600 + self::WAIT, self::NOW - 600 + self::WAIT], array_column($calls, 1), 'the window of the first step');
    }

    // the first page even without time for it: a step that reads none would make no progress, and a run of those ends in error
    public function test_resolve_reads_one_page_without_time(): void
    {
        $calls = [];

        $lookup = Lookup::resolve(self::pages([self::others1000(Client::MAX_LIST)], $calls), self::WANTED, self::SUBJECT, self::NOW, [], static fn(): bool => false);

        self::assertCount(1, $calls);
        self::assertSame([self::SECOND, 1, [1]], [$lookup->scan['o'], $lookup->scan['c'], $lookup->scan['k']]);
    }

    // a list that moves on every page is read again and again - but never for ever, not even a step at a time
    public function test_resolve_list_that_always_moves_is_unsure(): void
    {
        $calls = 0;
        $list  = static function (int $from, int $to, int $offset, int $limit) use (&$calls): array {
            if (++$calls > Lookup::MAX_PAGES + 5) {
                throw new \LogicException('Read for ever');
            }
            // records no page held before: the last one of the page before is never there
            return array_map(static fn(int $i): SentMessage => self::sent((string) ($calls * 10000 + $i), "99-$i", 'b000009', self::NOW - 300), range(1, Client::MAX_LIST));
        };

        $whole = Lookup::resolve($list, self::WANTED, self::SUBJECT, self::NOW - 600 + self::WAIT);

        self::assertSame([[], [1], Lookup::MAX_PAGES], [$whole->missing, $whole->unsure, $calls]);

        [$calls, $scan, $steps] = [0, [], 0];
        do {
            $step = Lookup::resolve($list, self::WANTED, self::SUBJECT, self::NOW - 600 + self::WAIT, $scan, static fn(): bool => false);
            $scan = json_decode(json_encode($step->scan, JSON_THROW_ON_ERROR), true);
        } while (($scan !== []) && (++$steps < 2 * Lookup::MAX_PAGES));

        self::assertSame([[], [1], Lookup::MAX_PAGES], [$step->missing, $step->unsure, $calls], 'a page a step');
    }

    /**
     * A reading of a step before that this class did not leave - a position edited by hand - is
     * started anew: it neither fails every step nor skips the list it never read
     * @param array<string, mixed> $edit
     */
    #[DataProvider('editedReadings')]
    public function test_resolve_reads_anew_what_it_did_not_leave(array $edit): void
    {
        $calls = [];
        $sent  = self::sent('9001', '4711-2', 'b000001', self::NOW - 590);
        $from  = self::NOW - 600 - Lookup::SKEW;
        $scan  = $edit + ['k' => [1], 'f' => $from, 't' => self::NOW - 600 + self::WAIT, 'o' => Client::MAX_LIST - Lookup::OVERLAP + 1, 'a' => '5000', 'c' => 1, 'x' => [], 'n' => []];

        $lookup = Lookup::resolve(self::pages([[$sent]], $calls), self::WANTED, self::SUBJECT, self::NOW - 600 + self::WAIT, $scan);

        self::assertEquals([[1 => $sent], []], [$lookup->found, $lookup->missing]);
        self::assertSame([[$from, self::NOW - 600 + self::WAIT, 1, 1000]], $calls);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function editedReadings(): array
    {
        return [
            'past the pages read'          => [['o' => 99999]],
            'a record past the pages read' => [['o' => 982]],
            'an offset without anchor'     => [['a' => '']],
            'pages and offset inflated'    => [['o' => 48021, 'c' => 49, 'a' => '']],
            'an anchor at the start'       => [['o' => 1]],
            'before its start'             => [['t' => self::NOW - 600 - Lookup::SKEW - 1]],
            'near matches no list'         => [['n' => 5]],
            'an offset as text'            => [['o' => '981']],
            'no start'                     => [['f' => null]],
            'another start'                => [['f' => self::NOW - 7200]],
            'a reading of more messages'   => [['k' => [1, 2]]],
            'past the window'              => [['t' => self::NOW + 86400]],
            'matches no list'              => [['x' => 'junk']],
            'a match of another kind'      => [['x' => [1 => ['9001' => ['9001', '4', '4711-2', 'b000001', null, null, self::SUBJECT]]]]],
            'a match of another message'   => [['x' => [0 => ['9001' => ['9001', 4, '4711-1', 'b000000', null, null, self::SUBJECT]]]]],
        ];
    }

    // a reading of other messages is no reading of these
    public function test_resolve_starts_anew_for_other_messages(): void
    {
        $calls = [];
        $scan  = ['k' => [0], 'f' => 1, 't' => 2, 'o' => 3001, 'a' => '5000', 'c' => 3, 'x' => [], 'n' => []];

        Lookup::resolve(self::pages([[]], $calls), self::WANTED, self::SUBJECT, self::NOW, $scan);

        self::assertSame([[self::NOW - 600 - Lookup::SKEW, self::NOW, 1, 1000]], $calls);
    }

    public function test_resolve_asks_nothing_for_nothing(): void
    {
        $calls  = [];
        $lookup = Lookup::resolve(self::pages([[]], $calls), [], self::SUBJECT, self::NOW);

        self::assertSame([[], [], [], [], [], []], [$calls, $lookup->found, $lookup->missing, $lookup->unsure, $lookup->waiting, $lookup->scan]);
    }

    private static function sent(string $dmId, string $ref, string $box, ?int $deliveredAt, string $subject = self::SUBJECT): SentMessage
    {
        return new SentMessage($dmId, 4, $ref, $box, $deliveredAt, null, $subject);
    }

    /** @return list<SentMessage> $n messages of another sending, each its own */
    private static function others1000(int $n): array
    {
        return array_map(static fn(int $i): SentMessage => self::sent((string) (5000 + $i), "99-$i", 'b000009', self::NOW - 300), range(1, $n));
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
            return (count($pages) > 1) ? array_shift($pages) : $pages[0];
        };
    }
}
