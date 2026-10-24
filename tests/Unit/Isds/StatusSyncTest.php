<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Lookup;
use AA\Isds\SentList;
use AA\Isds\SentMessage;
use AA\Isds\Soap;
use AA\Isds\StateChange;
use AA\Isds\StatusSync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The rules of following delivery - without ISDS and without a clock, the time given */
final class StatusSyncTest extends TestCase
{
    private const STARTED = 1_900_000_000;
    private const NOW     = self::STARTED + 20 * 86400;
    /** the messages the sending sent: dmID => index */
    private const INDEX_OF = ['5001' => 0, '5002' => 1, '5003' => 2];
    /** the statuses from the lowest rank to the highest */
    private const RANKED = [1, 2, 4, 5, 6, 7, 9, 10, 3, 8];

    private static function prague(string $time): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('Europe/Prague')))->getTimestamp();
    }

    /** an entry of the record, times after STARTED, 0 = not known */
    private static function entry(int $s, int $t, int $d, int $a): string
    {
        return implode(':', [$s, ...array_map(static fn(int $time): int => ($time === 0) ? 0 : self::STARTED + $time, [$t, $d, $a])]);
    }

    private static function change(string $dmId, int $status, int $after): StateChange
    {
        return new StateChange($dmId, $status, self::STARTED + $after);
    }

    /** a record of the list of sent messages, times after STARTED, null = none */
    private static function listed(string $dmId, int $status, ?int $delivered, ?int $accepted): SentMessage
    {
        return new SentMessage(
            $dmId,
            $status,
            '4711-1',
            'b000001',
            is_null($delivered) ? null : self::STARTED + $delivered,
            is_null($accepted) ? null : self::STARTED + $accepted,
            'Pozvánka',
        );
    }

    /**
     * @param array<array-key, mixed>           $state
     * @param iterable<StateChange|SentMessage> $events
     * @return array{state: array<array-key, mixed>, changed: list<int>}
     */
    private static function merge(array $state, iterable $events): array
    {
        return StatusSync::merge($state, $events, self::INDEX_OF, self::STARTED, self::NOW);
    }

    /**
     * The calls nextCall() gives one after another from $read till nothing is left to read
     * @param array{last: int, day: string, at: int, span: int} $read
     * @return list<array{int, int}>
     */
    private static function calls(array $read, int $now): array
    {
        $calls = [];
        for ($call = StatusSync::nextCall($read, $now); !is_null($call) && (count($calls) < 100); $call = StatusSync::nextCall($call['read'], $now)) {
            $calls[] = [$call['from'], $call['to']];
        }
        return $calls;
    }

    // ------------------------------------------------------------ the record

    // a map edited by hand: an id twice, of another type, not of digits, under no index
    public function test_indexOf_first_wins_digits_only(): void
    {
        $dmIds = json_decode('{"0":"1001","1":"1002","2":"1001","3":1003,"4":"10a4","5":"","6":"123456789012345678901",'
            . '"x":"1005","7":["1006"],"8":null,"9":"00042","10":"12345678901234567890","11":"1007\n","-1":"1008"}', true);

        self::assertSame(['1001' => 0, '1002' => 1, '00042' => 9, '12345678901234567890' => 10], StatusSync::indexOf($dmIds));
    }

    public function test_entry_round_trip(): void
    {
        foreach (['4:1900000000:1900000000:0', '10:0:0:0', '6:9999999999:1:9999999999'] as $s) {
            $entry = StatusSync::entry($s);
            self::assertNotNull($entry);
            self::assertSame($s, StatusSync::encode($entry));
        }
        self::assertSame(['s' => 5, 't' => 1900864799, 'd' => 1900000000, 'a' => 1900864799], StatusSync::entry('5:1900864799:1900000000:1900864799'));
        $ranked = array_keys(StatusSync::ORDER);
        sort($ranked);
        self::assertSame(range(1, 10), $ranked, 'every status of an entry has a rank');
    }

    #[DataProvider('badEntries')]
    public function test_entry_rejects(string $s): void
    {
        self::assertNull(StatusSync::entry($s));
    }

    /** @return array<string, array{string}> */
    public static function badEntries(): array
    {
        return [
            'a negative time'          => ['4:-1900000000:0:0'],
            'a negative status'        => ['-4:0:0:0'],
            'status 0'                 => ['0:1900000000:0:0'],
            'status 11'                => ['11:1900000000:0:0'],
            'a leading zero'           => ['04:1900000000:0:0'],
            // an entry is written one way only: zeros before a time would make a string another than its value
            'a leading zero of a time' => ['4:0190000000:0:0'],
            '... of the delivery'      => ['4:1900000000:0190000000:0'],
            '... of the acceptance'    => ['5:1900000000:0:0190000000'],
            'zeros for zero'           => ['1:00:0:0'],
            'an 11-digit time'         => ['4:19000000000:0:0'],
            'a part missing'           => ['4:1900000000:1900000000'],
            'a part too many'          => ['4:1900000000:1900000000:0:0'],
            'a part empty'             => ['4::1900000000:0'],
            'a line after'             => ["4:1900000000:1900000000:0\n"],
            'a decimal'                => ['4.0:1900000000:1900000000:0'],
            'text'                     => ['delivered'],
            'empty'                    => [''],
        ];
    }

    // ------------------------------------------------------------ merging

    public function test_merge_ignores_duplicate_events(): void
    {
        $events = [self::change('5001', 4, 100), self::change('5001', 6, 900), self::listed('5002', 4, 200, null)];
        $once   = self::merge([], $events);

        self::assertSame(['state' => [0 => self::entry(6, 900, 100, 900), 1 => self::entry(4, 200, 200, 0)], 'changed' => [0, 1]], $once);
        self::assertSame($once, self::merge([], [...$events, ...$events]));
        self::assertSame(['state' => $once['state'], 'changed' => []], self::merge($once['state'], $events), 'merged again');
    }

    // windows overlap, a day is read again, the list tells what the changes told: the order never matters
    public function test_merge_any_order_same_state(): void
    {
        $events = [
            self::change('5001', 4, 100),
            self::change('5001', 5, 864799),
            self::listed('5001', 6, 100, 864799),
            self::change('5001', 6, 900000),
            self::change('5002', 4, 200),
            self::listed('5002', 8, 200, null),
        ];
        $expected = [0 => self::entry(6, 900000, 100, 864799), 1 => self::entry(8, 0, 200, 0)];

        foreach (self::permutations($events) as $order) {
            $whole = self::merge([], $order)['state'];
            $parts = self::merge(self::merge([], array_slice($order, 0, 3))['state'], array_slice($order, 3))['state'];
            ksort($whole);
            ksort($parts);
            self::assertSame($expected, $whole);
            self::assertSame($expected, $parts);
        }
    }

    // every two statuses, both ways round: the one of the higher rank, whichever comes first
    public function test_merge_order_of_statuses(): void
    {
        foreach (self::RANKED as $i => $lower) {
            foreach (array_slice(self::RANKED, $i + 1) as $higher) {
                foreach ([[$lower, $higher], [$higher, $lower]] as [$first, $second]) {
                    $state = self::merge([], [self::change('5001', $first, 100), self::change('5001', $second, 200)])['state'];

                    self::assertSame((string) $higher, strstr((string) $state[0], ':', true), "$first, then $second");
                }
            }
        }
    }

    // sent from the box by someone else, or by another sending: nothing to do with this one
    public function test_merge_ignores_unknown_dm_id(): void
    {
        $state = [0 => self::entry(4, 100, 100, 0)];

        $merged = self::merge($state, [self::change('9999', 6, 900), self::listed('5004', 3, null, null), self::change('', 4, 100)]);

        self::assertSame(['state' => $state, 'changed' => []], $merged);
    }

    // the status counts, a time no message of the sending can have does not
    #[DataProvider('times')]
    public function test_merge_ignores_time_out_of_range(int $time, int $kept): void
    {
        $changed = self::merge([], [new StateChange('5001', 4, $time)])['state'];
        $listed  = self::merge([], [new SentMessage('5002', 5, '4711-2', 'b000002', $time, $time, 'Pozvánka')])['state'];

        self::assertSame([0 => "4:$kept:$kept:0"], $changed);
        self::assertSame([1 => "5:$kept:$kept:$kept"], $listed);
    }

    /** @return array<string, array{int, int}> */
    public static function times(): array
    {
        $first = self::STARTED - Lookup::SKEW;
        $last  = self::NOW + Lookup::SKEW;
        return [
            'before the run started' => [$first - 1, 0],
            'as the run started'     => [$first, $first],
            'now'                    => [$last, $last],
            'in the future'          => [$last + 1, 0],
            'negative'               => [-5, 0],
            'zero'                   => [0, 0],
        ];
    }

    // a run started in the first minute of 1970, or a clock past the year 2286: still no time an entry cannot hold
    public function test_merge_times_fit_entry(): void
    {
        $early = StatusSync::merge([], [new StateChange('5001', 4, -30), new StateChange('5002', 4, 0)], self::INDEX_OF, 30, 1000)['state'];
        $late  = StatusSync::merge([], [new StateChange('5001', 4, 10_000_000_000)], self::INDEX_OF, 9_999_000_000, 9_999_999_990)['state'];

        self::assertSame([0 => '4:0:0:0', 1 => '4:0:0:0'], $early);
        self::assertSame([0 => '4:0:0:0'], $late);
    }

    public function test_merge_keeps_earliest_times(): void
    {
        $state = [0 => self::entry(4, 300, 300, 0), 1 => self::entry(6, 900000, 300, 900000)];

        $merged = self::merge($state, [
            self::change('5001', 4, 200),
            self::change('5001', 4, 400),
            self::listed('5002', 6, 200, 864799),
            // a time not known never wins the earliest
            new StateChange('5002', 6, self::NOW + Lookup::SKEW + 1),
        ]);

        self::assertSame(['state' => [0 => self::entry(4, 200, 200, 0), 1 => self::entry(6, 900000, 200, 864799)], 'changed' => [0, 1]], $merged);
    }

    /** @param list<StateChange|SentMessage> $events */
    #[DataProvider('failures')]
    public function test_merge_failure_is_sticky(string $was, array $events, string $is): void
    {
        self::assertSame([0 => $is], self::merge([0 => $was], $events)['state']);
    }

    /** @return array<string, array{string, list<StateChange|SentMessage>, string}> */
    public static function failures(): array
    {
        $accepted = self::entry(6, 900000, 100, 864799);
        return [
            // the acceptance after 10 days stays, and still the message is not delivered
            'undeliverable after acceptance'    => [$accepted, [self::change('5001', 8, 950000)], self::entry(8, 950000, 100, 864799)],
            'refused by the antivirus after it' => [$accepted, [self::listed('5001', 3, null, null)], self::entry(3, 0, 100, 864799)],
            'a login after undeliverable'       => [self::entry(8, 950000, 100, 864799), [self::change('5001', 6, 960000)], self::entry(8, 950000, 100, 864799)],
            'in the vault after undeliverable'  => [self::entry(8, 950000, 100, 864799), [self::listed('5001', 10, 100, 864799)], self::entry(8, 950000, 100, 864799)],
            'stamped after refused'             => [self::entry(3, 0, 0, 0), [self::listed('5001', 2, null, null)], self::entry(3, 0, 0, 0)],
            'in the vault after refused'        => [self::entry(3, 0, 0, 0), [self::listed('5001', 10, null, null)], self::entry(3, 0, 0, 0)],
            // they never come both; when they do, undeliverable, whichever comes first
            'refused, then undeliverable'       => [self::entry(3, 0, 0, 0), [self::change('5001', 8, 950000)], self::entry(8, 950000, 0, 0)],
            'undeliverable, then refused'       => [self::entry(8, 950000, 0, 0), [self::listed('5001', 3, null, null)], self::entry(8, 950000, 0, 0)],
        ];
    }

    // a state change is dated by when it came: the delivery for 4, the acceptance for 5 and 6
    #[DataProvider('changes')]
    public function test_merge_takes_change_times(string $was, int $status, string $is): void
    {
        self::assertSame([0 => $is], self::merge(($was === '') ? [] : [0 => $was], [self::change('5001', $status, 950000)])['state']);
    }

    /** @return array<string, array{string, int, string}> */
    public static function changes(): array
    {
        return [
            'delivered'                        => ['', 4, self::entry(4, 950000, 950000, 0)],
            'accepted after 10 days'           => ['', 5, self::entry(5, 950000, 0, 950000)],
            'accepted by a login'              => ['', 6, self::entry(6, 950000, 0, 950000)],
            'undeliverable'                    => ['', 8, self::entry(8, 950000, 0, 0)],
            // the delivery and the acceptance after 10 days it had stay
            'undeliverable after 10 days'      => [self::entry(5, 864799, 100, 864799), 8, self::entry(8, 950000, 100, 864799)],
            'a login after delivery'           => [self::entry(4, 100, 100, 0), 6, self::entry(6, 950000, 100, 950000)],
        ];
    }

    // the list dates 4 by its delivery and 5 by its acceptance; for 6 it may give the acceptance after 10 days, not the login
    #[DataProvider('records')]
    public function test_merge_takes_list_record_times(int $status, ?int $delivered, ?int $accepted, string $entry): void
    {
        self::assertSame([0 => $entry], self::merge([], [self::listed('5001', $status, $delivered, $accepted)])['state']);
    }

    /** @return array<string, array{int, ?int, ?int, string}> */
    public static function records(): array
    {
        return [
            'submitted'                => [1, null, null, self::entry(1, 0, 0, 0)],
            'stamped'                  => [2, null, null, self::entry(2, 0, 0, 0)],
            'refused by the antivirus' => [3, null, null, self::entry(3, 0, 0, 0)],
            'delivered'                => [4, 100, null, self::entry(4, 100, 100, 0)],
            'accepted after 10 days'   => [5, 100, 864799, self::entry(5, 864799, 100, 864799)],
            'accepted by a login'      => [6, 100, 5000, self::entry(6, 0, 100, 5000)],
            'read'                     => [7, 100, 5000, self::entry(7, 0, 100, 5000)],
            'undeliverable'            => [8, 100, 864799, self::entry(8, 0, 100, 864799)],
            'deleted'                  => [9, 100, 5000, self::entry(9, 0, 100, 5000)],
            'in the vault'             => [10, 100, 5000, self::entry(10, 0, 100, 5000)],
        ];
    }

    // a message accepted after 10 days may stay in status 4: only the list tells, by the time of acceptance
    public function test_merge_fiction_without_status(): void
    {
        $merged = self::merge([0 => self::entry(4, 100, 100, 0)], [self::listed('5001', 4, 100, 864799)]);

        self::assertSame(['state' => [0 => self::entry(4, 100, 100, 864799)], 'changed' => [0]], $merged);
    }

    #[DataProvider('unknownStatuses')]
    public function test_merge_drops_unknown_status(int $status): void
    {
        $state = [0 => self::entry(4, 100, 100, 0)];

        $merged = self::merge($state, [self::change('5001', $status, 900), self::listed('5002', $status, 100, 900)]);

        self::assertSame(['state' => $state, 'changed' => []], $merged);
    }

    /** @return array<string, array{int}> */
    public static function unknownStatuses(): array
    {
        return ['status 0' => [0], 'status 11' => [11], 'negative' => [-6], 'far' => [99]];
    }

    // what is not an entry - edited by hand - is not known, and is written anew without a word
    public function test_merge_changed_lists_only_real_changes(): void
    {
        $state = [
            0 => self::entry(4, 100, 100, 0),
            1 => self::entry(6, 900, 100, 900),
            2 => '1:0:0:0',
            4 => 'delivered',
            5 => 7,
            6 => ['4', '1900000300'],
            7 => '1:00:0:0',
        ];
        $events = [
            self::change('5006', 4, 300),
            self::change('5001', 6, 900),
            self::change('5002', 4, 100),
            self::change('5002', 6, 950),
            self::listed('5003', 1, null, null),
            self::change('5004', 4, 300),
            self::change('5005', 4, 300),
            self::change('5007', 4, 300),
            self::listed('5008', 1, null, null),
            self::change('9999', 6, 300),
        ];
        $indexOf  = ['5001' => 0, '5002' => 1, '5003' => 2, '5004' => 3, '5005' => 4, '5006' => 5, '5007' => 6, '5008' => 7];
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $merged = StatusSync::merge($state, $events, $indexOf, self::STARTED, self::NOW);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertSame([0, 3, 4, 5, 6, 7], $merged['changed']);
        self::assertSame([
            0 => self::entry(6, 900, 100, 900),
            1 => self::entry(6, 900, 100, 900),
            2 => '1:0:0:0',
            4 => self::entry(4, 300, 300, 0),
            5 => self::entry(4, 300, 300, 0),
            6 => self::entry(4, 300, 300, 0),
            7 => '1:0:0:0',
            3 => self::entry(4, 300, 300, 0),
        ], $merged['state']);
    }

    // ------------------------------------------------------------ windows of state changes

    public function test_windows_aligned_lagged_overlapping(): void
    {
        $now = self::prague('2030-03-21 10:37');

        self::assertSame([[self::prague('2030-03-21 08:58'), self::prague('2030-03-21 10:00')]], StatusSync::windows(self::prague('2030-03-21 09:00'), $now, StatusSync::MAX_SPAN));
        self::assertSame(self::prague('2030-03-21 10:00'), StatusSync::boundary(self::prague('2030-03-21 10:05:00')));
        self::assertSame(self::prague('2030-03-21 09:00'), StatusSync::boundary(self::prague('2030-03-21 10:04:59')), 'a change may be recorded 5 minutes late');
    }

    public function test_windows_none_before_next_hour(): void
    {
        $last = self::prague('2030-03-21 10:00');

        foreach (['10:00:00', '10:59:59', '11:04:59'] as $time) {
            self::assertSame([], StatusSync::windows($last, self::prague("2030-03-21 $time"), StatusSync::MAX_SPAN), $time);
        }
        self::assertSame([[self::prague('2030-03-21 09:58'), self::prague('2030-03-21 11:00')]], StatusSync::windows($last, self::prague('2030-03-21 11:05'), StatusSync::MAX_SPAN));
        self::assertSame([], StatusSync::windows(self::prague('2030-03-22 10:00'), self::prague('2030-03-21 12:00'), StatusSync::MAX_SPAN), 'a clock set back');
    }

    // by the local day, not by UTC: in winter the midnight of UTC is 1:00
    public function test_windows_cut_at_local_midnight(): void
    {
        self::assertSame(
            [[self::prague('2030-03-21 21:58'), self::prague('2030-03-22 00:00')], [self::prague('2030-03-22 00:00'), self::prague('2030-03-22 02:00')]],
            StatusSync::windows(self::prague('2030-03-21 22:00'), self::prague('2030-03-22 02:10'), StatusSync::MAX_SPAN),
        );
        self::assertSame(
            [[self::prague('2030-03-21 23:58'), self::prague('2030-03-22 01:00')]],
            StatusSync::windows(self::prague('2030-03-22 00:00'), self::prague('2030-03-22 01:10'), StatusSync::MAX_SPAN),
            'the overlap before midnight goes with the first call, which ends past watch_last',
        );
    }

    // a step that makes one call only moves on: no call ends at watch_last, whatever midnight is near
    #[DataProvider('lasts')]
    public function test_windows_each_call_ends_past_watch_last(string $last): void
    {
        $at = self::prague($last);
        foreach ([StatusSync::ROUND, 7200, StatusSync::MAX_SPAN] as $span) {
            $windows = StatusSync::windows($at, $at + 3 * 86400, $span);

            self::assertSame($at - StatusSync::OVERLAP, $windows[0][0], "$span: the overlap first");
            self::assertSame([], array_filter($windows, static fn(array $w): bool => $w[1] <= $at), "$span: each past watch_last");
            self::assertLessThanOrEqual($span, $windows[0][1] - $windows[0][0], "$span: the first call no longer than the span, the overlap included");
        }
    }

    /** @return array<string, array{string}> */
    public static function lasts(): array
    {
        return [
            'local midnight'                    => ['2030-03-22 00:00'],
            'a minute after midnight'           => ['2030-03-22 00:01'],
            'two minutes after midnight'        => ['2030-03-22 00:02'],
            'midnight of the 25-hour day'       => ['2030-10-27 00:00'],
            'midnight after the 25-hour day'    => ['2030-10-28 00:00'],
            'midnight of the 23-hour day'       => ['2030-03-31 00:00'],
            'an hour'                           => ['2030-03-22 13:00'],
            'a minute before midnight'          => ['2030-03-21 23:59'],
        ];
    }

    // a step with time for one call: each reads on from where the one before got
    public function test_windows_one_call_a_step_moves_on(): void
    {
        $last = self::prague('2030-03-22 00:00');
        $now  = self::prague('2030-03-22 03:10');

        $calls = [];
        for ($window = StatusSync::windows($last, $now, StatusSync::ROUND, 1); $window !== []; $window = StatusSync::windows($last, $now, StatusSync::ROUND, 1)) {
            $calls[] = $window[0];
            $last    = $window[0][1];
        }

        self::assertSame([
            [self::prague('2030-03-21 23:58'), self::prague('2030-03-22 00:58')],
            [self::prague('2030-03-22 00:56'), self::prague('2030-03-22 01:56')],
            [self::prague('2030-03-22 01:54'), self::prague('2030-03-22 02:54')],
            [self::prague('2030-03-22 02:52'), self::prague('2030-03-22 03:00')],
        ], $calls);
    }

    // summer time ends on 27 October 2030 and starts on 31 March: days of 25 and 23 hours
    #[DataProvider('longDays')]
    public function test_windows_25h_day_one_call(string $day, string $next, int $hours): void
    {
        $windows = StatusSync::windows(self::prague("$day 00:00") + StatusSync::OVERLAP, self::prague("$next 00:05"), StatusSync::MAX_SPAN);

        self::assertSame([[self::prague("$day 00:00"), self::prague("$next 00:00")]], $windows);
        self::assertSame($hours * 3600, $windows[0][1] - $windows[0][0]);
    }

    /** @return array<string, array{string, string, int}> */
    public static function longDays(): array
    {
        return ['25 hours' => ['2030-10-27', '2030-10-28', 25], '23 hours' => ['2030-03-31', '2030-04-01', 23]];
    }

    /** @param list<array{string, string}> $windows */
    #[DataProvider('spans')]
    public function test_windows_split_by_span(int $span, array $windows): void
    {
        $expected = array_map(static fn(array $w): array => [self::prague("2030-03-21 $w[0]"), self::prague("2030-03-21 $w[1]")], $windows);

        self::assertSame($expected, StatusSync::windows(self::prague('2030-03-21 10:00'), self::prague('2030-03-21 15:05'), $span));
    }

    /** @return array<string, array{int, list<array{string, string}>}> */
    public static function spans(): array
    {
        // the overlap counts in the first call: an hour ISDS answered as too large is tried as an hour
        $hourly = [['09:58', '10:58'], ['10:58', '11:58'], ['11:58', '12:58'], ['12:58', '13:58'], ['13:58', '14:58'], ['14:58', '15:00']];
        return [
            'two hours'             => [7200, [['09:58', '11:58'], ['11:58', '13:58'], ['13:58', '15:00']]],
            'an hour'               => [3600, $hourly],
            // a position edited by hand: a span that would never end
            'none'                  => [0, $hourly],
            'negative'              => [-3600, $hourly],
            'longer than a day'     => [PHP_INT_MAX, [['09:58', '15:00']]],
        ];
    }

    // a watch_last long past: the oldest calls ISDS still answers, no more than a step reads
    public function test_windows_at_most_max_oldest_first(): void
    {
        $last = self::prague('2030-03-11 10:00');
        $now  = self::prague('2030-03-21 10:37');

        $windows = StatusSync::windows($last, $now, StatusSync::ROUND);

        self::assertCount(StatusSync::MAX_CALLS, $windows);
        self::assertSame([$last - StatusSync::OVERLAP, $last - StatusSync::OVERLAP + 3600], $windows[0]);
        foreach (array_slice($windows, 1) as $i => $window) {
            self::assertSame($windows[$i][1], $window[0], 'one after another');
        }
        self::assertSame(array_slice($windows, 0, 3), StatusSync::windows($last, $now, StatusSync::ROUND, 3));
        self::assertSame([], StatusSync::windows($last, $now, StatusSync::ROUND, 0));
    }

    // ISDS keeps the changes of 15 days: a window that starts sooner, its overlap too, is not read - the list of
    // sent messages tells what it would have
    #[DataProvider('pastKept')]
    public function test_windows_too_old_goes_to_list(int $before): void
    {
        $now    = self::prague('2030-03-21 10:37');
        $oldest = $now - StatusSync::CHANGES_KEPT;
        $last   = $oldest + StatusSync::OVERLAP - $before;
        $w      = ['watch_from' => $oldest, 'watch_last' => $last, 'watch_rec' => $oldest, 'dm_state' => [], 'indexOf' => self::INDEX_OF];

        self::assertSame([], StatusSync::windows($last, $now, StatusSync::ROUND));
        self::assertSame('b', StatusSync::due($w, $now));
        self::assertTrue(StatusSync::pending($w, $now));
        self::assertSame([$oldest, $oldest + 3600], StatusSync::windows($oldest + StatusSync::OVERLAP, $now, StatusSync::ROUND)[0], 'the overlap still kept');
    }

    /** @return array<string, array{int}> */
    public static function pastKept(): array
    {
        return ['a second of the overlap' => [1], 'a day before' => [86400], 'a year before' => [365 * 86400], 'from 1970' => [self::prague('2030-03-07 10:37')]];
    }

    public function test_halve_stops_at_hour(): void
    {
        $spans = [];
        for ($span = StatusSync::MAX_SPAN; !is_null($span); $span = StatusSync::halve($span)) {
            $spans[] = $span;
        }

        self::assertSame([90000, 45000, 22500, 11250, 5625, 3600], $spans);
        self::assertSame([3600, 3600, 3600, null, null], [StatusSync::halve(7200), StatusSync::halve(7199), StatusSync::halve(3720), StatusSync::halve(3600), StatusSync::halve(0)]);
    }

    // ------------------------------------------------------------ days read whole in the morning

    /** @param list<string> $days */
    #[DataProvider('watchDays')]
    public function test_days_from_watch_day_to_yesterday(string $watchDay, array $days): void
    {
        self::assertSame($days, StatusSync::days($watchDay, self::prague('2030-04-01 07:00')));
    }

    /** @return array<string, array{string, list<string>}> */
    public static function watchDays(): array
    {
        return [
            'over the end of a month' => ['2030-03-28', ['2030-03-29', '2030-03-30', '2030-03-31']],
            'one'                     => ['2030-03-30', ['2030-03-31']],
            'yesterday read'          => ['2030-03-31', []],
            // a day not before today was edited by hand or comes of a clock set back: yesterday, so that the record has a day again
            'today'                   => ['2030-04-01', ['2030-03-31']],
            'tomorrow'                => ['2030-04-02', ['2030-03-31']],
            'far ahead'               => ['2099-12-31', ['2030-03-31']],
            'no day'                  => ['yesterday', ['2030-03-31']],
            'no such day'             => ['2030-02-30', ['2030-03-31']],
            'another format'          => ['2030-3-30', ['2030-03-31']],
            'none'                    => ['', ['2030-03-31']],
        ];
    }

    // ISDS gives state changes of 15 days: a day that starts sooner than 14 days ago is not read, and the
    // list stays short whatever the record says
    #[DataProvider('oldDays')]
    public function test_days_too_old_skipped(string $watchDay, string $first): void
    {
        $days = StatusSync::days($watchDay, self::prague('2030-04-01 07:00'));

        self::assertSame([$first, '2030-03-31'], [$days[0], $days[count($days) - 1]]);
        self::assertCount(13, $days);
    }

    /** @return array<string, array{string, string}> */
    public static function oldDays(): array
    {
        return [
            'thirteen days, all read' => ['2030-03-18', '2030-03-19'],
            'fourteen days'           => ['2030-03-17', '2030-03-19'],
            'a month'                 => ['2030-03-01', '2030-03-19'],
            'the epoch'               => ['1970-01-01', '2030-03-19'],
            'the first year'          => ['0001-01-01', '2030-03-19'],
        ];
    }

    // late in the evening, and in the days after summer time ends, a day reaches furthest back: still a day of reserve
    #[DataProvider('lateNows')]
    public function test_days_never_start_before_kept(string $now, string $yesterday): void
    {
        $at   = self::prague($now);
        $days = StatusSync::days('2000-01-01', $at);

        $start = StatusSync::nextCall(['last' => $at, 'day' => '2000-01-01', 'at' => 0, 'span' => StatusSync::MAX_SPAN], $at)['from'] ?? 0;
        self::assertSame(self::prague("$days[0] 00:00"), $start);
        self::assertGreaterThanOrEqual($at - StatusSync::CHANGES_KEPT, $start);
        self::assertGreaterThanOrEqual(23 * 3600, 15 * 86400 - ($at - $start), 'a reserve before the 15 days of ISDS');
        self::assertSame($yesterday, $days[count($days) - 1]);
    }

    /** @return array<string, array{string, string}> summer time ended on 25 October 2026 and started on 29 March 2026 */
    public static function lateNows(): array
    {
        return [
            'the day after it ended'             => ['2026-10-26 23:59:59', '2026-10-25'],
            'thirteen days after'                => ['2026-11-07 23:30', '2026-11-06'],
            'fourteen days after'                => ['2026-11-08 23:30', '2026-11-07'],
            'fifteen days after, in the morning' => ['2026-11-09 06:00', '2026-11-08'],
            'the day after it started'           => ['2026-03-30 23:59:59', '2026-03-29'],
            'fourteen days after it started'     => ['2026-04-12 23:30', '2026-04-11'],
            'a winter evening'                   => ['2030-01-15 23:00', '2030-01-14'],
        ];
    }

    // by the local hour: in winter 6:00 is 5:00 by UTC, in summer 7:30 is 5:30
    public function test_days_none_before_six(): void
    {
        self::assertSame([], StatusSync::days('2030-03-29', self::prague('2030-04-01 00:00')));
        self::assertSame([], StatusSync::days('2030-03-29', self::prague('2030-04-01 05:59:59')));
        self::assertSame(['2030-03-30', '2030-03-31'], StatusSync::days('2030-03-29', self::prague('2030-04-01 06:00')));
        self::assertSame([], StatusSync::days('2030-03-12', self::prague('2030-03-14 05:59:59')));
        self::assertSame(['2030-03-13'], StatusSync::days('2030-03-12', self::prague('2030-03-14 06:00')));
        self::assertSame([], StatusSync::days('2030-07-17', self::prague('2030-07-19 05:59:59')));
        self::assertSame(['2030-07-18'], StatusSync::days('2030-07-17', self::prague('2030-07-19 07:30')));
    }

    /**
     * ISDS records an acceptance after 10 days after midnight and dates it 23:59:59.999 of its day,
     * with or without a zone: the morning after reads that day whole, and the call of the day holds it
     */
    #[DataProvider('fictions')]
    public function test_days_hold_fiction_at_235959(string $isds, string $watchDay, string $day, string $morning): void
    {
        $fiction = Soap::timestamp($isds);

        $read  = ['last' => self::prague($morning), 'day' => $watchDay, 'at' => 0, 'span' => StatusSync::MAX_SPAN];
        $calls = self::calls($read, self::prague($morning));

        self::assertSame([$day], StatusSync::days($watchDay, self::prague($morning)));
        self::assertSame([[self::prague("$day 00:00"), self::prague("$day 00:00 +1 day")]], $calls);
        self::assertTrue(($calls[0][0] <= $fiction) && ($fiction < $calls[0][1]), 'inside the call of its day');
        $next = self::prague("$morning +1 day");
        self::assertSame($calls[0][1], self::calls(array_replace($read, ['last' => $next, 'day' => $day]), $next)[0][0] ?? null, 'the next day goes on where it ends');
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function fictions(): array
    {
        return [
            'winter, no zone'   => ['2030-03-24T23:59:59.999', '2030-03-23', '2030-03-24', '2030-03-25 06:00'],
            'winter, zone'      => ['2030-03-24T23:59:59.999+01:00', '2030-03-23', '2030-03-24', '2030-03-25 06:00'],
            'summer, zone'      => ['2030-07-18T23:59:59.999+02:00', '2030-07-17', '2030-07-18', '2030-07-19 06:00'],
            'the 23-hour day'   => ['2030-03-31T23:59:59.999', '2030-03-30', '2030-03-31', '2030-04-01 06:00'],
            'the 25-hour day'   => ['2030-10-27T23:59:59.999', '2030-10-26', '2030-10-27', '2030-10-28 06:00'],
        ];
    }

    // more changes in a day than one answer holds: the day is read in pieces, all of it
    #[DataProvider('daySpans')]
    public function test_nextCall_reads_day_in_pieces(int $span, int $calls, int $last): void
    {
        $now     = self::prague('2030-10-28 06:30');
        $windows = self::calls(['last' => $now, 'day' => '2030-10-26', 'at' => 0, 'span' => $span], $now);

        self::assertCount($calls, $windows);
        self::assertSame([self::prague('2030-10-27 00:00'), self::prague('2030-10-28 00:00')], [$windows[0][0], $windows[$calls - 1][1]]);
        self::assertSame($last, $windows[$calls - 1][1] - $windows[$calls - 1][0]);
        foreach (array_slice($windows, 1) as $i => $window) {
            self::assertSame($windows[$i][1], $window[0], 'one after another');
        }
    }

    /** @return array<string, array{int, int, int}> */
    public static function daySpans(): array
    {
        return ['the whole day' => [StatusSync::MAX_SPAN, 1, 25 * 3600], 'two hours' => [7200, 13, 3600], 'an hour' => [3600, 25, 3600], 'none' => [0, 25, 3600]];
    }

    // ------------------------------------------------------------ the next call

    // the window of changes first, then the days not read whole, each from where the reading got
    public function test_nextCall_window_then_days(): void
    {
        $now  = self::prague('2030-03-25 07:30');
        $read = ['last' => self::prague('2030-03-25 05:00'), 'day' => '2030-03-22', 'at' => 0, 'span' => StatusSync::MAX_SPAN];

        $calls = [];
        $reads = [];
        for ($call = StatusSync::nextCall($read, $now); !is_null($call); $call = StatusSync::nextCall($call['read'], $now)) {
            $calls[] = [$call['from'], $call['to']];
            $reads[] = $call['read'];
        }

        self::assertSame([
            [self::prague('2030-03-25 04:58'), self::prague('2030-03-25 07:00')],
            [self::prague('2030-03-23 00:00'), self::prague('2030-03-24 00:00')],
            [self::prague('2030-03-24 00:00'), self::prague('2030-03-25 00:00')],
        ], $calls);
        self::assertSame([
            array_replace($read, ['last' => self::prague('2030-03-25 07:00')]),
            array_replace($read, ['last' => self::prague('2030-03-25 07:00'), 'day' => '2030-03-23']),
            array_replace($read, ['last' => self::prague('2030-03-25 07:00'), 'day' => '2030-03-24']),
        ], $reads);
    }

    // a call within the day keeps how far it got; the last one moves watch_day and starts the next day anew
    public function test_nextCall_day_over_steps(): void
    {
        $now  = self::prague('2030-03-25 07:30');
        $read = ['last' => self::prague('2030-03-25 07:00'), 'day' => '2030-03-23', 'at' => self::prague('2030-03-24 22:00'), 'span' => StatusSync::ROUND];

        $first = StatusSync::nextCall($read, $now);
        $last  = StatusSync::nextCall($first['read'] ?? [], $now);

        self::assertSame(['from' => self::prague('2030-03-24 22:00'), 'to' => self::prague('2030-03-24 23:00'), 'read' => array_replace($read, ['at' => self::prague('2030-03-24 23:00')])], $first);
        self::assertSame(['from' => self::prague('2030-03-24 23:00'), 'to' => self::prague('2030-03-25 00:00'), 'read' => array_replace($read, ['day' => '2030-03-24', 'at' => 0])], $last);
        self::assertNull(StatusSync::nextCall($last['read'] ?? [], $now));
    }

    // a position edited by hand to a time out of the day being read reads it from its start
    #[DataProvider('outOfDay')]
    public function test_nextCall_day_at_out_of_day_from_start(int $at): void
    {
        $now  = self::prague('2030-03-25 07:30');
        $read = ['last' => self::prague('2030-03-25 07:00'), 'day' => '2030-03-23', 'at' => $at, 'span' => StatusSync::ROUND];

        self::assertSame(['from' => self::prague('2030-03-24 00:00'), 'to' => self::prague('2030-03-24 01:00')], array_slice(StatusSync::nextCall($read, $now) ?? [], 0, 2));
    }

    /** @return array<string, array{int}> */
    public static function outOfDay(): array
    {
        return [
            'small'          => [5],
            'negative'       => [-3600],
            'the day before' => [self::prague('2030-03-23 12:00')],
            'its start'      => [self::prague('2030-03-24 00:00')],
            'its end'        => [self::prague('2030-03-25 00:00')],
            'far ahead'      => [9_999_999_999],
        ];
    }

    // nothing to read: the window read, yesterday read or the night before six
    public function test_nextCall_none(): void
    {
        $read = ['last' => self::prague('2030-03-25 07:00'), 'day' => '2030-03-24', 'at' => 0, 'span' => StatusSync::MAX_SPAN];

        self::assertNull(StatusSync::nextCall($read, self::prague('2030-03-25 08:04:59')));
        self::assertNull(StatusSync::nextCall(['last' => self::prague('2030-03-25 05:00'), 'day' => '2030-03-23'] + $read, self::prague('2030-03-25 05:30')));
        self::assertSame(self::prague('2030-03-25 07:00'), StatusSync::nextCall($read, self::prague('2030-03-25 08:05'))['from'] + StatusSync::OVERLAP);
    }

    // an answer too large shortened the span: the next call reads in it
    public function test_nextCall_in_span(): void
    {
        $read = ['last' => self::prague('2030-03-25 01:00'), 'day' => '2030-03-24', 'at' => 0, 'span' => 7200];

        self::assertSame(['from' => self::prague('2030-03-25 00:58'), 'to' => self::prague('2030-03-25 02:58'), 'read' => array_replace($read, ['last' => self::prague('2030-03-25 02:58')])], StatusSync::nextCall($read, self::prague('2030-03-25 07:30')));
    }

    public function test_firstWatchDay_yesterday_after_six(): void
    {
        self::assertSame('2030-03-24', StatusSync::firstWatchDay(self::prague('2030-03-25 06:00')));
        self::assertSame('2030-03-24', StatusSync::firstWatchDay(self::prague('2030-03-25 23:59:59')));
        self::assertSame('2030-07-18', StatusSync::firstWatchDay(self::prague('2030-07-19 06:30')), '4:30 by UTC');
    }

    // yesterday's acceptances may be unrecorded yet: yesterday is read at six
    public function test_firstWatchDay_day_before_yesterday_before_six(): void
    {
        self::assertSame('2030-03-23', StatusSync::firstWatchDay(self::prague('2030-03-25 05:59:59')));
        self::assertSame('2030-03-23', StatusSync::firstWatchDay(self::prague('2030-03-25 00:30')), '23:30 of the 24th by UTC');
        self::assertSame(['2030-03-24'], StatusSync::days(StatusSync::firstWatchDay(self::prague('2030-03-25 05:00')), self::prague('2030-03-25 06:00')));
    }

    // ------------------------------------------------------------ work to do now, the end

    /**
     * What the record holds at 7:30 when everything is read and written
     * @return array<string, mixed>
     */
    private static function allRead(): array
    {
        return ['watch_last' => (string) self::prague('2030-03-25 07:00'), 'watch_day' => '2030-03-24', 'dm_dirty' => '{}'];
    }

    /** @param array<string, mixed> $w a null value leaves its property out */
    #[DataProvider('work')]
    public function test_pending_true(array $w): void
    {
        $w = array_filter($w + self::allRead(), static fn(mixed $value): bool => !is_null($value));

        self::assertTrue(StatusSync::pending($w, self::prague('2030-03-25 07:30')));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function work(): array
    {
        return [
            'items to write'          => [['dm_dirty' => '{"3":1}']],
            'items to write, a list'  => [['dm_dirty' => '[1]']],
            'items to write, decoded' => [['dm_dirty' => [3 => 1]]],
            'the list read in part'   => [['l' => ['f' => self::STARTED, 't' => self::STARTED + 3600, 'o' => 981, 'a' => '5001', 'c' => 1, 'h' => self::STARTED]]],
            'a window unread'         => [['watch_last' => (string) self::prague('2030-03-25 06:00')]],
            'a day unread after six'  => [['watch_day' => '2030-03-23']],
            // windows from the start: the step finds out what the record lacks
            'no watch_last'           => [['watch_last' => null]],
            'watch_last no number'    => [['watch_last' => 'x']],
            'watch_last of 11 digits' => [['watch_last' => '10000000000']],
            'watch_last negative'     => [['watch_last' => '-5']],
            'watch_last an array'     => [['watch_last' => [1]]],
            'watch_day an array'      => [['watch_day' => ['2030-03-24']]],
        ];
    }

    // the step would only go round: the night waits for the next hour, and at six it reads
    public function test_pending_false_before_six_with_yesterday_unread(): void
    {
        $w = ['watch_last' => (string) self::prague('2030-03-25 05:00'), 'watch_day' => '2030-03-23', 'dm_dirty' => ''];

        self::assertFalse(StatusSync::pending($w, self::prague('2030-03-25 05:30')));
        self::assertTrue(StatusSync::pending($w, self::prague('2030-03-25 06:00')));
    }

    public function test_pending_false_when_all_read(): void
    {
        self::assertFalse(StatusSync::pending(self::allRead(), self::prague('2030-03-25 07:30')));
        self::assertFalse(StatusSync::pending(['dm_dirty' => '', 'l' => [], 'z' => 3600] + self::allRead(), self::prague('2030-03-25 08:04:59')));
        self::assertFalse(StatusSync::pending(['watch_last' => self::prague('2030-03-25 07:00')] + self::allRead(), self::prague('2030-03-25 07:30')), 'as a number');
    }

    // edited by hand into what lists no item: no step that goes on at once for ever
    #[DataProvider('noItems')]
    public function test_pending_dirty_scalar_is_none(mixed $dirty): void
    {
        self::assertFalse(StatusSync::pending(['dm_dirty' => $dirty] + self::allRead(), self::prague('2030-03-25 07:30')));
    }

    /** @return array<string, array{mixed}> */
    public static function noItems(): array
    {
        return [
            'a string'             => ['"x"'],
            'a number'             => ['1'],
            'false'                => ['false'],
            'null'                 => ['null'],
            'an empty list'        => ['[]'],
            'no index'             => ['{"x":1}'],
            'a negative index'     => ['{"-1":1}'],
            'zeros before'         => ['{"007":1}'],
            'an empty map'         => [[]],
            'a map without index'  => [['x' => 1, '007' => 1, -1 => 1]],
            'a number, not a text' => [3],
        ];
    }

    public function test_pending_broken_dirty_throws(): void
    {
        $this->expectException(\JsonException::class);

        StatusSync::pending(['dm_dirty' => '{"3":'] + self::allRead(), self::prague('2030-03-25 07:30'));
    }

    #[DataProvider('finals')]
    public function test_allFinal(array $state, bool $final): void
    {
        self::assertSame($final, StatusSync::allFinal($state, self::INDEX_OF));
    }

    /** @return array<string, array{array<array-key, mixed>, bool}> */
    public static function finals(): array
    {
        $final = [0 => self::entry(6, 10, 5, 10), 1 => self::entry(8, 20, 0, 0), 2 => self::entry(3, 1, 0, 0)];
        return [
            'all final'                  => [$final, true],
            'one delivered'              => [[1 => self::entry(4, 20, 20, 0)] + $final, false],
            'one by fiction'             => [[1 => self::entry(5, 20, 10, 20)] + $final, false],
            'one not known'              => [array_diff_key($final, [2 => true]), false],
            'one out of shape'           => [[2 => '6:x:0:0'] + $final, false],
            'one no text'                => [[2 => 6] + $final, false],
            // a message of no index of the sending says nothing
            'final, and another not'     => [$final + [7 => self::entry(4, 20, 20, 0)], true],
        ];
    }

    public function test_allFinal_nothing_sent(): void
    {
        self::assertTrue(StatusSync::allFinal([], []));
    }

    // all final in the night: the morning reading of yesterday may still tell the time of an acceptance after 10 days
    public function test_ends_only_in_the_morning(): void
    {
        $state = [0 => self::entry(8, 10, 0, 0), 1 => self::entry(6, 10, 5, 10), 2 => self::entry(6, 10, 5, 10)];
        $w     = ['watch_last' => (string) self::prague('2030-03-25 04:00'), 'watch_day' => '2030-03-23', 'dm_dirty' => ''];

        self::assertFalse(StatusSync::ends($w, $state, self::INDEX_OF, self::STARTED, self::prague('2030-03-25 05:30')));
        self::assertFalse(StatusSync::ends($w, $state, self::INDEX_OF, self::STARTED, self::prague('2030-03-25 06:30')), 'yesterday unread');
        self::assertTrue(StatusSync::ends(['watch_last' => (string) self::prague('2030-03-25 06:00'), 'watch_day' => '2030-03-24'] + $w, $state, self::INDEX_OF, self::STARTED, self::prague('2030-03-25 06:30')));
    }

    public function test_ends_after_deadline_in_the_morning(): void
    {
        $since = self::prague('2030-03-09 07:00');
        $state = [0 => self::entry(4, 10, 10, 0)];

        // the list of sent messages read at the deadline
        $read  = ['watch_rec' => self::prague('2030-03-25 07:10')] + self::allRead();

        self::assertFalse(StatusSync::ends($read, $state, self::INDEX_OF, $since + 3600, self::prague('2030-03-25 07:30')), 'not yet');
        self::assertTrue(StatusSync::ends($read, $state, self::INDEX_OF, $since, self::prague('2030-03-25 07:30')));
        self::assertFalse(StatusSync::ends(self::allRead(), $state, self::INDEX_OF, $since, self::prague('2030-03-25 07:30')), 'the list not read since the deadline');
        self::assertFalse(StatusSync::ends(['dm_dirty' => '{"0":1}'] + $read, $state, self::INDEX_OF, $since, self::prague('2030-03-25 07:30')), 'an item to write');
        self::assertFalse(StatusSync::ends(['watch_last' => (string) self::prague('2030-03-25 06:00')] + $read, $state, self::INDEX_OF, $since, self::prague('2030-03-25 07:30')), 'a window unread');
    }

    // ------------------------------------------------------------ the list of sent messages

    /**
     * What the record holds when following started at 10:00 and read up to $last, the list not read yet;
     * one message delivered, two not known
     * @return array<string, mixed>
     */
    private static function following(string $last = '2030-03-21 11:00'): array
    {
        return [
            'started'    => self::prague('2030-03-21 09:00'),
            'watch_from' => self::prague('2030-03-21 10:00'),
            'watch_last' => self::prague($last),
            'watch_rec'  => null,
            'dm_state'   => [0 => self::entry(4, 10, 10, 0)],
            'indexOf'    => self::INDEX_OF,
        ];
    }

    // the antivirus check may refuse a message after its dmID came back, and no state change says so
    public function test_due_av_after_grace(): void
    {
        self::assertSame('a', StatusSync::due(self::following(), self::prague('2030-03-21 11:05')));
        self::assertSame('a', StatusSync::due(['dm_state' => [0 => '2:5:0:0', 1 => self::entry(6, 9, 8, 9), 2 => self::entry(3, 0, 0, 0)]] + self::following(), self::prague('2030-03-21 11:05')), 'timestamped only');
    }

    public function test_due_av_not_before_h_reaches_grace(): void
    {
        self::assertSame('', StatusSync::due(self::following(), self::prague('2030-03-21 11:04:59')));
        self::assertSame('', StatusSync::due(['watch_from' => self::prague('2030-03-21 10:01')] + self::following(), self::prague('2030-03-21 11:30')));
    }

    public function test_due_av_once(): void
    {
        $now = self::prague('2030-03-21 11:05');

        self::assertSame('', StatusSync::due(['watch_rec' => self::prague('2030-03-21 11:05')] + self::following(), $now), 'read');
        self::assertSame('', StatusSync::due(['watch_rec' => (string) self::prague('2030-03-21 11:05')] + self::following(), $now), 'read, as the record keeps it');
        self::assertSame('', StatusSync::due(['dm_state' => array_fill(0, 3, self::entry(4, 10, 10, 0))] + self::following(), $now), 'each past the check');
    }

    // ISDS keeps the changes of 15 days: older ones the list tells, whatever was read before
    public function test_due_after_14_days(): void
    {
        $now  = self::prague('2030-03-21 11:05');
        $kept = $now - StatusSync::CHANGES_KEPT + StatusSync::OVERLAP;
        $read = ['watch_rec' => self::prague('2030-03-21 11:00'), 'dm_state' => array_fill(0, 3, self::entry(4, 10, 10, 0))];

        self::assertSame('b', StatusSync::due(['watch_last' => $kept - 1] + $read + self::following(), $now));
        self::assertSame('', StatusSync::due(['watch_last' => $kept] + $read + self::following(), $now));
    }

    // at the deadline, once: a message not final yet may have been accepted or made undeliverable unseen
    #[DataProvider('nonFinals')]
    public function test_due_final_sweep_for_any_nonfinal(array $state): void
    {
        $end = StatusSync::deadline(self::prague('2030-03-21 10:00'));
        $w   = ['dm_state' => $state, 'watch_rec' => self::prague('2030-03-21 11:10'), 'watch_last' => $end - 3600] + self::following();

        self::assertSame('', StatusSync::due($w, $end - 1));
        self::assertSame('c', StatusSync::due($w, $end));
        self::assertSame('', StatusSync::due(['watch_rec' => $end] + $w, $end + 3600), 'once');
    }

    /** @return array<string, array{array<int, string>}> */
    public static function nonFinals(): array
    {
        $final = [0 => self::entry(6, 10, 5, 10), 1 => self::entry(8, 20, 0, 0), 2 => self::entry(3, 0, 0, 0)];
        return [
            'delivered'     => [[1 => self::entry(4, 20, 20, 0)] + $final],
            'by fiction'    => [[1 => self::entry(5, 20, 10, 20)] + $final],
            'not known'     => [array_diff_key($final, [2 => true])],
        ];
    }

    public function test_due_none_when_all_final(): void
    {
        $w   = ['dm_state' => [0 => self::entry(6, 10, 5, 10), 1 => self::entry(8, 20, 0, 0), 2 => self::entry(3, 0, 0, 0)]] + self::following();
        $end = StatusSync::deadline(self::prague('2030-03-21 10:00'));

        self::assertSame('', StatusSync::due($w, self::prague('2030-03-21 11:05')));
        self::assertSame('', StatusSync::due(['watch_last' => $end - 3600] + $w, $end));
    }

    // 14 days past the deadline ISDS keeps none of its changes: nothing is read, whatever is due
    public function test_due_none_past_over(): void
    {
        $over = StatusSync::deadline(self::prague('2030-03-21 10:00')) + StatusSync::CHANGES_KEPT;

        self::assertSame('b', StatusSync::due(self::following(), $over - 1));
        self::assertFalse(StatusSync::over(self::prague('2030-03-21 10:00'), $over - 1));
        self::assertSame('', StatusSync::due(self::following(), $over));
        self::assertTrue(StatusSync::over(self::prague('2030-03-21 10:00'), $over));
    }

    // what is due is work to do now
    public function test_pending_true_when_due(): void
    {
        $w = ['watch_day' => '2030-03-20', 'dm_dirty' => ''] + self::following();

        self::assertTrue(StatusSync::pending($w, self::prague('2030-03-21 11:05')));
        self::assertFalse(StatusSync::pending(['watch_rec' => self::prague('2030-03-21 11:05')] + $w, self::prague('2030-03-21 11:05')));
    }

    // every message was delivered before GRACE after following started; the first round may read sooner
    public function test_listWindow_ends_at_h_in_first_round(): void
    {
        $started = self::prague('2030-03-21 09:00');
        $since   = self::prague('2030-03-21 10:00');

        self::assertSame([$started - Lookup::SKEW, $since - 3600], StatusSync::listWindow($started, $since, $since - 3600));
        self::assertSame([$started - Lookup::SKEW, $since + Lookup::GRACE], StatusSync::listWindow($started, $since, $since + 86400));
        self::assertSame([0, 0], StatusSync::listWindow(0, 0, 0), 'a start that is no time: from 1970, never ending before it starts');
    }

    // a reading is started when due, with the boundary it started at
    public function test_listCursor_fresh_when_due(): void
    {
        $now = self::prague('2030-03-21 11:05');
        $h   = self::prague('2030-03-21 11:00');

        $fresh = ['f' => self::prague('2030-03-21 08:59'), 't' => $h, 'o' => 1, 'a' => '', 'c' => 0, 'h' => $h];
        self::assertSame($fresh, StatusSync::listCursor(null, self::following(), $now));
        self::assertNull(StatusSync::listCursor(null, ['watch_rec' => $h] + self::following(), $now), 'none due');
    }

    // a reading a step left goes on, also once nothing is due and after JSON
    public function test_listCursor_takes_own_after_json(): void
    {
        $now = self::prague('2030-03-21 11:05');
        $l   = ['f' => self::prague('2030-03-21 08:59'), 't' => self::prague('2030-03-21 11:00'), 'o' => 981, 'a' => '5001', 'c' => 1, 'h' => self::prague('2030-03-21 11:00')];
        $l   = json_decode((string) json_encode($l), true);

        self::assertSame($l, StatusSync::listCursor($l, ['watch_rec' => $now] + self::following(), $now));
        self::assertSame($l, StatusSync::listCursor($l, self::following(), self::prague('2030-03-21 12:30')), 'the window it started with');
    }

    /** @param array<string, mixed> $edit */
    #[DataProvider('editedCursors')]
    public function test_listCursor_refuses_edited(array $edit): void
    {
        $now = self::prague('2030-03-21 11:05');
        $l   = array_replace(['f' => self::prague('2030-03-21 08:59'), 't' => self::prague('2030-03-21 11:00'), 'o' => 981, 'a' => '5001', 'c' => 1, 'h' => self::prague('2030-03-21 11:00')], $edit);

        self::assertSame(($edit === []) ? 981 : 1, StatusSync::listCursor($l, self::following(), $now)['o'] ?? null);
        self::assertSame(($edit === []) ? 981 : null, StatusSync::listCursor($l, ['watch_rec' => $now] + self::following(), $now)['o'] ?? null, 'started anew only when due');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function editedCursors(): array
    {
        return [
            'as read() left it'    => [[]],
            'h past H'             => [['h' => self::prague('2030-03-21 12:00')]],
            'h before GRACE'       => [['h' => self::prague('2030-03-21 10:59'), 't' => self::prague('2030-03-21 10:59')]],
            'h no number'          => [['h' => (string) self::prague('2030-03-21 11:00')]],
            'no h'                 => [['h' => null]],
            'another start'        => [['f' => self::prague('2030-03-21 08:58')]],
            'another end'          => [['t' => self::prague('2030-03-21 10:59')]],
            'no list'              => [['o' => 'x']],
            'all pages read'       => [['c' => SentList::MAX_PAGES, 'o' => 1, 'a' => '']],
        ];
    }

    // the windows a step reads move watch_last past h: the reading goes on, also after ISDS broke it off
    // hours ago; past changes ISDS no longer keeps watch_last stands, and an h before it was edited in
    public function test_listCursor_h_against_watch_last_only_past_kept_changes(): void
    {
        $now = self::prague('2030-03-21 15:05');
        $l   = ['f' => self::prague('2030-03-21 08:59'), 't' => self::prague('2030-03-21 11:00'), 'o' => 981, 'a' => '5001', 'c' => 1, 'h' => self::prague('2030-03-21 11:00')];

        self::assertSame($l, StatusSync::listCursor($l, self::following('2030-03-21 15:00'), $now));

        $last = $now - 15 * 86400;
        $lost = ['watch_from' => $now - 15 * 86400 + 600, 'started' => $last, 'watch_last' => $last];
        $l    = ['f' => $last - Lookup::SKEW, 't' => $last + 4200, 'o' => 981, 'a' => '5001', 'c' => 1];
        self::assertSame(981, StatusSync::listCursor($l + ['h' => self::prague('2030-03-21 14:00')], $lost + self::following(), $now)['o'] ?? null);
        self::assertSame(1, StatusSync::listCursor($l + ['h' => $last - 3600, 't' => $last - 3600] + $l, $lost + self::following(), $now)['o'] ?? null);
    }

    // a reading done past changes ISDS no longer keeps reads the changes on from where it started; else as they were
    public function test_afterList_from_h_only_past_kept_changes(): void
    {
        $now = self::prague('2030-03-21 11:05');
        $h   = self::prague('2030-03-21 11:00');

        self::assertSame($h, StatusSync::afterList($now - 15 * 86400, $h, $now));
        self::assertSame($h - 7200, StatusSync::afterList($h - 7200, $h, $now));
        self::assertSame($h, StatusSync::afterList($h, $h - 3600, $now));
    }

    // past over() the run ends once its items are written: no reading, no morning, nothing due waits for it
    public function test_ends_past_over_without_reading(): void
    {
        $since = self::prague('2030-02-01 10:00');
        $now   = StatusSync::deadline($since) + StatusSync::CHANGES_KEPT;
        $w     = ['watch_last' => $since, 'watch_day' => '2030-02-01', 'dm_dirty' => '', 'l' => ['f' => 1]];

        self::assertFalse(StatusSync::ends($w, [], self::INDEX_OF, $since, $now - 1));
        self::assertTrue(StatusSync::ends($w, [], self::INDEX_OF, $since, $now));
        self::assertFalse(StatusSync::ends(['dm_dirty' => '{"1":1}'] + $w, [], self::INDEX_OF, $since, $now), 'an item to write');
    }

    public function test_deadline_16_days(): void
    {
        self::assertSame(self::STARTED + 16 * 86400, StatusSync::deadline(self::STARTED));
    }

    public function test_isFinal_3_and_6_to_10(): void
    {
        self::assertSame([3, 6, 7, 8, 9, 10], array_values(array_filter(range(-1, 11), StatusSync::isFinal(...))));
    }

    /**
     * @param list<StateChange|SentMessage> $items
     * @return \Generator<list<StateChange|SentMessage>>
     */
    private static function permutations(array $items): \Generator
    {
        if (count($items) <= 1) {
            yield $items;
            return;
        }
        foreach ($items as $i => $item) {
            $rest = $items;
            unset($rest[$i]);
            foreach (self::permutations(array_values($rest)) as $permutation) {
                yield [$item, ...$permutation];
            }
        }
    }
}
