<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Http\Response;
use AA\Http\ResponseTooLargeException;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\Client;
use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\StatusSync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Isds\Fixtures\ScriptedIsds;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;

require_once __DIR__ . '/fixtures/SendRunCase.php';

/**
 * Following the delivery of what a sending sent: the step from look, the rounds over the state changes
 * of ISDS and the days read whole in the morning, the record before the items, the end - over TableDb's
 * object_text and toexecute, items in memory, ISDS from scripts. NOW is Sunday 18:46:40 CET.
 */
final class SendRunWatchTest extends TestCase
{
    use SendRunCase;

    /** the run started and sent its messages three hours before NOW */
    private const SENT = self::NOW - 3 * 3600;
    /** the last whole hour a round at NOW reads to, 18:00 CET, and when the round after it starts */
    private const H    = 1_899_997_200;
    private const NEXT = self::H + StatusSync::ROUND + StatusSync::LAG + 60;
    /** the local midnight that starts the day of NOW, 17 March 2030 */
    private const MIDNIGHT = 1_899_932_400;
    /** a message reached its box, and was accepted by a login */
    private const DELIVERED = self::SENT + 1800;
    private const LOGIN     = self::SENT + 7200;

    // ------------------------------------------------------------ from look

    public function test_look_goes_to_watch_campaign_first(): void
    {
        $id     = self::queued(2, ['dm_ids' => '{"0":"1001","1":"1002"}', 'cursor' => '2'], self::LOOK);
        $isds   = self::isds();
        $before = null;
        self::items()->onWrite = static function () use ($id, &$before): void {
            if (array_slice(self::items()->writes, -1)[0]['id'] === self::CAMPAIGN_ITEM) {
                $before ??= self::stored($id)['watch_from'] ?? '';
            }
        };

        self::assertSame('step 1', self::runQueued());

        self::assertSame('', $before, 'the campaign shows the end before the record follows delivery');
        self::assertSame([Schema::CAMPAIGN_STATE_DONE, (string) self::NOW], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT)]);
        $stored = self::stored($id);
        self::assertSame([(string) self::NOW, $stored['started'], '2030-03-16', ''], [$stored['watch_from'], $stored['watch_last'], $stored['watch_day'], $stored['report'] ?? '']);
        self::assertSame(self::WATCH, self::position($id));
        self::assertSame(['sending', '', (string) self::NOW], [$stored['state'], $stored['retry_code'], $stored['next_at']], 'the first round at once');
        self::assertTrue(TestSendRun::get($id)?->watching());
        self::assertSame([], $isds->ops());
    }

    /** @param array<string, string> $props */
    #[DataProvider('noneSent')]
    public function test_look_without_sent_finishes(array $props): void
    {
        $id = self::queued(2, $props + ['cursor' => '2'], self::LOOK);

        self::assertSame('done', self::runQueued());

        self::assertSame(['done', ''], [self::stored($id)['state'], self::stored($id)['watch_from'] ?? '']);
        self::assertFalse(TestSendRun::get($id)?->watching());
        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());
    }

    /** @return array<string, array{array<string, string>}> */
    public static function noneSent(): array
    {
        return [
            'all failed'            => [['failed' => '{"0":"1233","1":"1233"}']],
            // edited by hand: an index without a dmID is no message to follow
            'dm_ids without a dmID' => [['dm_ids' => '{"0":null}', 'failed' => '{"1":"1233"}']],
        ];
    }

    // before 6:00 ISDS may not have recorded the acceptances after 10 days of yesterday yet
    #[DataProvider('dawns')]
    public function test_look_before_six_watch_day_day_before_yesterday(int $at, string $day): void
    {
        TestSendRun::$now = $at;
        $id               = self::queued(1, ['dm_ids' => '{"0":"1001"}', 'cursor' => '1'], self::LOOK);

        self::runQueued();

        self::assertSame($day, self::stored($id)['watch_day']);
    }

    /** @return array<string, array{int, string}> */
    public static function dawns(): array
    {
        $monday = self::MIDNIGHT + 86400;
        return [
            '5:59:59' => [$monday + 6 * 3600 - 1, '2030-03-16'],
            '6:00'    => [$monday + 6 * 3600, '2030-03-17'],
        ];
    }

    // ------------------------------------------------------------ the record and the items

    public function test_watch_records_before_items(): void
    {
        $id   = self::watching();
        self::watchIsds([self::changes([['1001', self::DELIVERED, 4]])]);
        $seen = null;
        self::items()->onWrite = static function () use ($id, &$seen): void {
            $seen ??= [self::map($id, 'dm_state'), self::map($id, 'dm_dirty')];
        };

        self::runQueued();

        $entry = self::entry(4, self::DELIVERED, self::DELIVERED, 0);
        self::assertSame([[0 => $entry], [0 => 1]], $seen, 'the record says it before an item shows it');
        self::assertSame([[0 => $entry], []], [self::map($id, 'dm_state'), self::map($id, 'dm_dirty')], 'the item shows it: nothing left to write');
        $msg = self::message($id, 0);
        self::assertSame(['4', (string) self::DELIVERED], [self::items()->field($msg, Schema::MESSAGE_DM_STATUS), self::items()->field($msg, Schema::MESSAGE_DELIVERED_AT)]);
        self::assertSame(Schema::EVENT_DELIVERED, array_slice(self::kinds($id, 0), -1)[0]);
        self::assertSame([self::message($id, 0)], self::items()->written('update'), 'the other message was told nothing');
    }

    // another sending, someone else sending from the box: nothing written, nothing logged
    public function test_watch_ignores_foreign_dm_ids(): void
    {
        $id   = self::watching();
        self::watchIsds([self::changes([['5555', self::DELIVERED, 4], ['7777', self::LOGIN, 6]])]);
        $from = count(TableDb::$log);

        self::runQueued();

        self::assertSame(['', ''], [self::stored($id)['dm_state'] ?? '', self::stored($id)['dm_dirty'] ?? '']);
        self::assertSame([], self::items()->writes);
        self::assertSame([], preg_grep('/\b(INTO|UPDATE)\s+`?log`?\b/i', array_slice(TableDb::$log, $from)));
        self::assertSame((string) self::H, self::stored($id)['watch_last'], 'the window is read all the same');
    }

    // a change told again - the windows overlap - writes nothing
    public function test_watch_round_twice_writes_nothing(): void
    {
        $id   = self::watching();
        $isds = self::watchIsds([self::changes([['1001', self::DELIVERED, 4], ['1001', self::LOGIN, 6]])]);
        self::runQueued();
        $state = self::map($id, 'dm_state');
        self::items()->writes = [];
        TestSendRun::$now     = self::NOW + 3600;

        self::runQueued();

        self::assertSame([self::H - StatusSync::OVERLAP, self::H + 3600], array_slice($isds->windows(), -1)[0]);
        self::assertSame([[0 => self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN)], []], [$state, self::map($id, 'dm_dirty')]);
        self::assertSame([$state, []], [self::map($id, 'dm_state'), self::items()->writes]);
    }

    // the record is written, the step dies before its items: the next step writes them from dm_dirty
    public function test_watch_died_after_record_items_next_step(): void
    {
        $id   = self::watching();
        $died = null;
        self::watchIsds([self::changes([['1001', self::DELIVERED, 4]])]);
        self::items()->onWrite = static function () use (&$died): void {
            $died ??= [TableDb::$tables, self::items()->stored];
        };
        self::runQueued();
        [TableDb::$tables, self::items()->stored] = $died ?? throw new \LogicException('No item written');
        self::items()->onWrite = null;
        $msg                   = self::message($id, 0);
        self::assertSame([[0 => 1], ''], [self::map($id, 'dm_dirty'), self::items()->field($msg, Schema::MESSAGE_DELIVERED_AT)], 'as the step died');
        TestSendRun::$now = (int) self::stored($id)['lease'] + 1;
        $isds             = self::watchIsds();

        self::runQueued();

        self::assertSame((string) self::DELIVERED, self::items()->field($msg, Schema::MESSAGE_DELIVERED_AT));
        self::assertSame([], self::map($id, 'dm_dirty'));
        self::assertSame([], $isds->ops(), 'what was read is in the record');
    }

    // written as dm_state was, then the reading changed it: the item stays to be written again
    public function test_watch_item_rewritten_when_changed_after_write(): void
    {
        $id = self::watching(2, ['dm_state' => '{"0":"' . self::entry(4, self::DELIVERED, self::DELIVERED, 0) . '"}', 'dm_dirty' => '{"0":1}']);
        TestSendRun::$microNow = (float) self::NOW;
        self::watchIsds([static function (): Response {
            // the answer takes what was left of the step
            TestSendRun::$microNow += 30.0;
            return self::changes([['1001', self::LOGIN, 6]]);
        }]);

        self::assertSame('step 1', self::runQueued());

        $msg = self::message($id, 0);
        self::assertSame('4', self::items()->field($msg, Schema::MESSAGE_DM_STATUS), 'written before the change');
        self::assertSame([[0 => self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN)], [0 => 1]], [self::map($id, 'dm_state'), self::map($id, 'dm_dirty')]);
        self::assertSame([['n' => 1, 'p' => 'watch'], ''], [self::position($id), self::stored($id)['retry_code']], 'goes on at once');

        TestSendRun::$microNow = null;
        self::runQueued();

        self::assertSame(['6', (string) self::LOGIN], [self::items()->field($msg, Schema::MESSAGE_DM_STATUS), self::items()->field($msg, Schema::MESSAGE_ACCEPTED_AT)]);
        self::assertSame([], self::map($id, 'dm_dirty'));
    }

    // a time edited into no time, a start of following edited away, items of no message sent: set right
    // and written, or every step would go on at once
    public function test_watch_record_out_of_shape_set_right(): void
    {
        $id   = self::watching(2, [
            'watch_last' => 'soon',
            'watch_from' => '-5',
            'dm_state'   => '{"0":"' . self::entry(4, self::DELIVERED, self::DELIVERED, 0) . '"}',
            'dm_dirty'   => '{"0":1,"7":1,"x":1}',
        ]);
        $isds = self::watchIsds();

        self::assertSame('step 1', self::runQueued());

        self::assertSame([[self::SENT - StatusSync::OVERLAP, self::H]], $isds->windows(), 'read from the start of the run');
        $stored = self::stored($id);
        self::assertSame([(string) self::H, (string) self::NOW, ''], [$stored['watch_last'], $stored['watch_from'], $stored['dm_dirty']]);
        self::assertSame((string) self::DELIVERED, self::items()->field(self::message($id, 0), Schema::MESSAGE_DELIVERED_AT));
        self::assertSame(SendRun::WATCHING, $stored['retry_code']);
    }

    // a time to come - edited by hand, or written by a server whose clock is ahead -: no deadline that never
    // comes, no window that is never read
    #[DataProvider('timesToCome')]
    public function test_watch_times_to_come_set_right(string $from, string $last, string $since): void
    {
        $id   = self::watching(2, ['watch_from' => $from, 'watch_last' => $last]);
        $isds = self::watchIsds();

        self::assertSame('step 1', self::runQueued());

        self::assertSame([[self::SENT - StatusSync::OVERLAP, self::H]], $isds->windows());
        self::assertSame([$since, (string) self::H], [self::stored($id)['watch_from'], self::stored($id)['watch_last']]);
    }

    /** @return array<string, array{string, string, string}> watch_from, watch_last, watch_from as set right */
    public static function timesToCome(): array
    {
        $from = (string) (self::SENT + 600);
        return [
            'watch_from to come'         => [(string) (self::NOW + 3600), (string) self::SENT, (string) self::NOW],
            'watch_last to come'         => [$from, (string) (self::NOW + 3600), $from],
            'both far ahead'             => ['9999999999', '9999999999', (string) self::NOW],
            'a server a minute ahead'    => [(string) (self::NOW + 60), (string) self::SENT, (string) (self::NOW + 60)],
        ];
    }

    // a start of the run that is no time: what ISDS keeps is read, and no time it tells is too early
    public function test_watch_started_out_of_shape_reads_what_isds_keeps(): void
    {
        $id = self::watching(2, ['watch_last' => 'x']);
        self::storedSet($id, 'started', (string) (self::NOW + 86400));
        $isds = self::watchIsds([self::changes([['1001', self::DELIVERED, 4]])]);

        self::runQueued();

        self::assertSame([self::NOW - StatusSync::CHANGES_KEPT, (string) self::H], [$isds->windows()[0][0], self::stored($id)['watch_last']]);
        self::assertSame([0 => self::entry(4, self::DELIVERED, self::DELIVERED, 0)], self::map($id, 'dm_state'));
    }

    // an entry edited out of shape has nothing to show: passed over, not left in dm_dirty
    public function test_watch_passes_over_entry_out_of_shape(): void
    {
        $id = self::watching(2, ['dm_state' => '{"0":"4:soon:0:0","1":5}', 'dm_dirty' => '{"0":1,"1":1}', 'watch_last' => (string) self::H]);
        self::watchIsds();

        self::runQueued();

        self::assertSame([], self::items()->writes);
        self::assertSame(['', SendRun::WATCHING], [self::stored($id)['dm_dirty'], self::stored($id)['retry_code']]);
    }

    // an index of dm_ids with no recipient frozen - edited by hand -: its entry is kept, there is no item to show it
    public function test_watch_index_without_row_passed_over(): void
    {
        $id = self::watching(2, ['dm_ids' => '{"0":"1001","1":"1002","7":"1008"}']);
        self::watchIsds([self::changes([['1008', self::DELIVERED, 4]])]);

        self::runQueued();

        self::assertSame([[7 => self::entry(4, self::DELIVERED, self::DELIVERED, 0)], [], []], [self::map($id, 'dm_state'), self::map($id, 'dm_dirty'), self::items()->writes]);
    }

    public function test_watch_gone_item_leaves_dirty(): void
    {
        $id = self::watching(2, ['dm_state' => '{"0":"' . self::entry(4, self::DELIVERED, self::DELIVERED, 0) . '"}', 'dm_dirty' => '{"0":1}', 'watch_last' => (string) self::H]);
        unset(self::items()->stored[self::message($id, 0)]);
        self::watchIsds();

        self::runQueued();

        self::assertSame(['', SendRun::WATCHING], [self::stored($id)['dm_dirty'], self::stored($id)['retry_code']]);
    }

    // look found the message, delivered: the first round adds the entry of the delivery, the second nothing
    public function test_watch_found_message_first_round_adds_delivered(): void
    {
        TestSendRun::$now = self::NOW - 600;
        $id               = self::queued(2, ['dm_ids' => '{"0":"1001"}', 'uncertain' => '{"1":' . (self::NOW - 500) . '}', 'cursor' => '2'], self::LOOK);
        TestSendRun::$now = self::NOW;
        TestSendRun::$http = new ScriptedIsds([
            'GetOwnerInfoFromLogin2' => [new Response(200, self::fixture('owner-info.xml'))],
            'GetListOfSentMessages'  => [self::listed([['1002', '4711-2', self::box(1), self::NOW - 450]])],
            'GetMessageStateChanges' => [self::changes([['1002', self::NOW - 450, 4]])],
        ]);
        self::assertSame('watch', self::lookOver($id));
        self::assertSame([Schema::EVENT_QUEUED, Schema::EVENT_LOOKUP_FOUND], self::kinds($id, 1));
        TestSendRun::$now = self::NOW + 3600;

        self::runQueued();

        self::assertSame([Schema::EVENT_QUEUED, Schema::EVENT_LOOKUP_FOUND, Schema::EVENT_DELIVERED], self::kinds($id, 1));
        self::items()->writes = [];
        TestSendRun::$now     = self::NOW + 2 * 3600;
        self::runQueued();
        self::assertSame([], self::items()->writes);
    }

    // ------------------------------------------------------------ when the next round comes

    // the step ran out of time with windows left: the next one goes on at once
    public function test_watch_pending_work_goes_on_at_once(): void
    {
        $last = self::MIDNIGHT - 2 * 86400 + 3600;
        $id   = self::watching(2, ['watch_last' => (string) $last]);
        TestSendRun::$microNow = (float) self::NOW;
        $isds = self::watchIsds([static function (): Response {
            TestSendRun::$microNow += 30.0;
            return self::changes();
        }]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame([[$last - StatusSync::OVERLAP, self::MIDNIGHT - 86400]], $isds->windows(), 'a call ends at a local midnight');
        self::assertSame(['n' => 1, 'p' => 'watch'], self::position($id));
        $stored = self::stored($id);
        self::assertSame(['', (string) self::NOW, (string) (self::MIDNIGHT - 86400)], [$stored['retry_code'], $stored['next_at'], $stored['watch_last']]);
    }

    // watch_last at a local midnight, time for one call a step: each step reads on, none asks for the same window again
    public function test_watch_one_call_a_step_moves_on_over_midnight(): void
    {
        $id   = self::watching(2, ['watch_last' => (string) self::MIDNIGHT], ['n' => 0, 'p' => 'watch', 'z' => 3600]);
        $isds = self::watchIsds([static function (): Response {
            TestSendRun::$microNow += 30.0;
            return self::changes();
        }]);

        for ($step = 0; $step < 3; ++$step) {
            TestSendRun::$microNow = (float) self::NOW;
            self::assertSame('step ' . ($step + 1), self::runQueued());
        }

        $on = self::MIDNIGHT - StatusSync::OVERLAP;
        self::assertSame([[$on, self::MIDNIGHT + 3600], [$on + 3600, self::MIDNIGHT + 7200], [$on + 7200, self::MIDNIGHT + 10800]], $isds->windows());
        self::assertSame([(string) (self::MIDNIGHT + 10800), '', '0'], [self::stored($id)['watch_last'], self::stored($id)['retry_code'], self::stored($id)['failures']]);
    }

    // the budget went before the step asked or wrote anything: a failure the core counts, not a step that
    // goes round at once for ever
    public function test_watch_step_without_progress_fails(): void
    {
        $id   = self::watching();
        $isds = self::watchIsds();
        TestSendRun::$beforeWork = static function (): void {
            TestSendRun::$microNow = (float) TestSendRun::$now + 1000.0;
        };

        $said = [];
        for ($step = 0; $step < 3; ++$step) {
            TestSendRun::$microNow = (float) self::NOW;
            $said[] = [self::runQueued(), self::position($id), self::stored($id)['failures']];
        }

        self::assertSame([['step 1', self::WATCH, '1'], ['step 2', self::WATCH, '2'], ['error NO_PROGRESS', self::WATCH, '3']], $said);
        self::assertSame([[], Schema::CAMPAIGN_STATE_DONE], [$isds->ops(), self::campaign()]);
    }

    // an answer tells of every message of the box: those of others are dropped after each call, so what a
    // step holds does not grow with what else the box sends
    public function test_watch_drops_foreign_changes_after_each_call(): void
    {
        self::watching(2, ['watch_last' => (string) (self::H - StatusSync::MAX_CALLS * 3600)], ['n' => 0, 'p' => 'watch', 'z' => 3600]);
        $foreign = self::changes(array_map(static fn(int $i): array => [(string) (900000 + $i), self::SENT, 4], range(1, 3000)));
        $used    = [];
        self::watchIsds([static function () use ($foreign, &$used): Response {
            $used[] = memory_get_usage();
            return $foreign;
        }]);

        self::runQueued();

        self::assertCount(StatusSync::MAX_CALLS, $used);
        self::assertLessThan(512 * 1024, max($used) - $used[1], 'the answers before are not held: ' . json_encode($used));
    }

    // in the night yesterday cannot be read whole yet: the round waits for the next hour, it does not go round
    public function test_night_round_waits_for_next_hour(): void
    {
        $night            = self::MIDNIGHT + 86400 + 9000;
        $h                = $night - 1800;
        TestSendRun::$now = $night;
        $id               = self::watching(2, ['watch_last' => (string) ($h - 3600)]);
        $isds             = self::watchIsds();

        self::assertSame('step 1', self::runQueued());

        self::assertSame([[$h - 3600 - StatusSync::OVERLAP, $h]], $isds->windows(), 'no day read');
        self::assertSame(['n' => 1, 'p' => 'watch', 'u' => $h + 3960], self::position($id));
        self::assertSame([SendRun::WATCHING, '2030-03-16'], [self::stored($id)['retry_code'], self::stored($id)['watch_day']]);
    }

    public function test_watch_waits_for_next_hour(): void
    {
        $id   = self::watching();
        $isds = self::watchIsds();

        self::assertSame('step 1', self::runQueued());

        self::assertSame([[self::SENT - StatusSync::OVERLAP, self::H]], $isds->windows());
        self::assertSame(['n' => 1, 'p' => 'watch', 'u' => self::NEXT], self::position($id));
        $stored = self::stored($id);
        self::assertSame([SendRun::WATCHING, (string) self::NEXT, '0', (string) self::H], [$stored['retry_code'], $stored['next_at'], $stored['failures'], $stored['watch_last']]);

        // run before its time - Execute in the queue manager - it asks ISDS nothing
        self::runQueued();
        self::assertCount(1, $isds->ops());
    }

    public function test_watch_isds_down_waits(): void
    {
        $id = self::watching();
        self::watchIsds([new HttpTransportException('Connection timed out after 30000 milliseconds')]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame(['n' => 1, 'p' => 'watch', 'u' => self::NEXT], self::position($id));
        $stored = self::stored($id);
        self::assertSame(['ISDS_UNAVAILABLE', (string) self::NEXT, '0', (string) self::SENT], [$stored['retry_code'], $stored['next_at'], $stored['failures'], $stored['watch_last']]);

        // run before its time, with a window unread: it asks ISDS nothing and says why it waits
        $isds = self::watchIsds();
        self::runQueued();
        self::assertSame([[], ['e' => 1, 'n' => 1, 'p' => 'watch', 'u' => self::NEXT], 'ISDS_UNAVAILABLE'], [$isds->ops(), self::position($id), self::stored($id)['retry_code']]);
    }

    // the weekly maintenance of ISDS: the items are written, ISDS is not asked till it is over
    public function test_watch_waits_out_maintenance(): void
    {
        $friday           = self::MIDNIGHT + 5 * 86400 + 1800;
        TestSendRun::$now = $friday;
        $id               = self::watching(2, ['dm_state' => '{"0":"' . self::entry(4, $friday - 7200, $friday - 7200, 0) . '"}', 'dm_dirty' => '{"0":1}'], sent: $friday - 3 * 3600);
        $isds             = self::watchIsds();

        self::assertSame('step 1', self::runQueued());

        self::assertSame([[], [], '4'], [$isds->ops(), self::map($id, 'dm_dirty'), self::items()->field(self::message($id, 0), Schema::MESSAGE_DM_STATUS)]);
        self::assertSame([['n' => 1, 'p' => 'watch', 'u' => $friday + 1800], SendRun::MAINTENANCE], [self::position($id), self::stored($id)['retry_code']]);
    }

    // ------------------------------------------------------------ windows and days

    // too large for one answer: the call that was, not the span asked for, is halved, till the round ends
    public function test_watch_too_large_halves_window(): void
    {
        $last = self::H - 16 * 3600;
        $id   = self::watching(2, ['watch_last' => (string) $last]);
        TestSendRun::$microNow = (float) self::NOW;
        $isds = self::watchIsds([
            new ResponseTooLargeException('Response body exceeds 8388608 bytes'),
            static function (): Response {
                TestSendRun::$microNow += 30.0;
                return self::changes();
            },
            self::changes(),
        ]);
        $from = $last - StatusSync::OVERLAP;
        $half = intdiv(self::H - $from, 2);

        self::runQueued();

        self::assertSame([[$from, self::H], [$from, $last + $half]], $isds->windows());
        self::assertSame(['n' => 1, 'p' => 'watch', 'z' => $half], self::position($id));
        self::assertSame((string) ($last + $half), self::stored($id)['watch_last']);

        TestSendRun::$microNow = null;
        self::runQueued();

        self::assertSame([[$last + $half - StatusSync::OVERLAP, self::H]], array_slice($isds->windows(), 2), 'a step reads on from where the one before got, in the shorter span');
        self::assertSame(['n' => 2, 'p' => 'watch', 'u' => self::NEXT], self::position($id), 'the next round asks for the full span again');
    }

    // an hour too large is all ISDS gives: the round waits as for ISDS that fails
    public function test_watch_too_large_hour_waits(): void
    {
        $id = self::watching(2, ['watch_last' => (string) (self::H - 3600)], ['n' => 0, 'p' => 'watch', 'z' => 3600]);
        self::watchIsds([new ResponseTooLargeException('Response body exceeds 8388608 bytes')]);

        self::runQueued();

        self::assertSame(['n' => 1, 'p' => 'watch', 'u' => self::NEXT, 'z' => 3600], self::position($id));
        self::assertSame(['ISDS_UNAVAILABLE', (string) (self::H - 3600)], [self::stored($id)['retry_code'], self::stored($id)['watch_last']]);
    }

    // in the morning the days not read whole are read, each in one call - an acceptance after 10 days is dated 23:59:59
    public function test_watch_morning_reads_missed_days(): void
    {
        $fiction = self::MIDNIGHT - 86400 - 1;
        $id      = self::watching(2, ['watch_last' => (string) self::H, 'watch_day' => '2030-03-14'], sent: self::MIDNIGHT - 12 * 86400);
        $isds    = self::watchIsds([self::changes([['1001', $fiction, 5]]), self::changes()]);

        self::runQueued();

        self::assertSame([[self::MIDNIGHT - 2 * 86400, self::MIDNIGHT - 86400], [self::MIDNIGHT - 86400, self::MIDNIGHT]], $isds->windows());
        self::assertSame(['2030-03-16', [0 => self::entry(5, $fiction, 0, $fiction)]], [self::stored($id)['watch_day'], self::map($id, 'dm_state')]);
        self::assertSame((string) $fiction, self::items()->field(self::message($id, 0), Schema::MESSAGE_ACCEPTED_AT));
        self::assertSame(SendRun::WATCHING, self::stored($id)['retry_code']);
    }

    // in calls of an hour a day takes more than a step makes: the day is read on where the step got, and
    // counts as read only after its last call
    public function test_watch_reads_day_over_steps(): void
    {
        $day  = self::MIDNIGHT - 86400;
        $id   = self::watching(2, ['watch_last' => (string) self::H, 'watch_day' => '2030-03-15'], ['n' => 0, 'p' => 'watch', 'z' => 3600]);
        $isds = self::watchIsds();

        self::runQueued();

        $calls = $isds->windows();
        self::assertSame([StatusSync::MAX_CALLS, [$day, $day + 3600], [$day + 19 * 3600, $day + 20 * 3600]], [count($calls), $calls[0], $calls[19]]);
        self::assertSame(['d' => $day + 20 * 3600, 'n' => 1, 'p' => 'watch', 'z' => 3600], self::position($id));
        self::assertSame(['2030-03-15', ''], [self::stored($id)['watch_day'], self::stored($id)['retry_code']]);

        self::runQueued();

        self::assertSame([[$day + 20 * 3600, $day + 21 * 3600], [$day + 23 * 3600, self::MIDNIGHT]], [$isds->windows()[20], array_slice($isds->windows(), -1)[0]]);
        self::assertCount(24, $isds->windows());
        self::assertSame(['2030-03-16', ['n' => 2, 'p' => 'watch', 'u' => self::NEXT]], [self::stored($id)['watch_day'], self::position($id)]);
    }

    // how far the day is read, edited by hand out of the day: it is read from its start
    #[DataProvider('dayCursors')]
    public function test_watch_day_cursor_out_of_day_reads_day_from_start(mixed $at): void
    {
        $id   = self::watching(2, ['watch_last' => (string) self::H, 'watch_day' => '2030-03-15'], ['d' => $at, 'n' => 0, 'p' => 'watch']);
        $isds = self::watchIsds();

        self::runQueued();

        self::assertSame([[self::MIDNIGHT - 86400, self::MIDNIGHT]], $isds->windows());
        self::assertSame(['2030-03-16', ['n' => 1, 'p' => 'watch', 'u' => self::NEXT]], [self::stored($id)['watch_day'], self::position($id)]);
    }

    /** @return array<string, array{mixed}> */
    public static function dayCursors(): array
    {
        return [
            'small'          => [5],
            'the day before' => [self::MIDNIGHT - 2 * 86400 + 3600],
            'past its end'   => [self::MIDNIGHT + 3600],
            'no number'      => ['x'],
            'a list'         => [[1]],
        ];
    }

    // the span of calls, edited by hand out of range: the full span
    #[DataProvider('spansOutOfRange')]
    public function test_watch_span_out_of_range_reads_full_span(mixed $span): void
    {
        $last = self::H - 5 * 3600;
        $id   = self::watching(2, ['watch_last' => (string) $last], ['n' => 0, 'p' => 'watch', 'z' => $span]);
        $isds = self::watchIsds();

        self::runQueued();

        self::assertSame([[$last - StatusSync::OVERLAP, self::H]], $isds->windows());
        self::assertSame(['n' => 1, 'p' => 'watch', 'u' => self::NEXT], self::position($id));
    }

    /** @return array<string, array{mixed}> */
    public static function spansOutOfRange(): array
    {
        return [
            'none'              => [0],
            'a minute'          => [60],
            'negative'          => [-3600],
            'longer than a day' => [StatusSync::MAX_SPAN + 3600],
            'no number'         => ['3600'],
            'a list'            => [[3600]],
        ];
    }

    // Continue calls ISDS over the full span again: a call a step failed on would fail again
    public function test_positionOnResume_drops_span(): void
    {
        $id = self::watching(2, [], ['d' => self::MIDNIGHT - 3600, 'n' => 4, 'p' => 'watch', 'u' => self::NEXT, 'z' => 3600]);
        self::storedSet($id, 'state', 'error');
        TableDb::$tables['toexecute'] = [];

        self::assertSame('', TestSendRun::resumeFor(self::CAMPAIGN_ITEM, Sending::USER));

        self::assertSame(['d' => self::MIDNIGHT - 3600, 'n' => 4, 'p' => 'watch'], self::position($id), 'how far the day is read stays');
    }

    // ISDS keeps the changes of 15 days: no call starts sooner than CHANGES_KEPT before now
    public function test_watch_reads_nothing_older_than_changes_kept(): void
    {
        $id   = self::watching(2, ['watch_last' => (string) (self::NOW - 20 * 86400)]);
        $isds = self::watchIsds();

        self::runQueued();

        $calls = $isds->windows();
        self::assertSame([self::NOW - StatusSync::CHANGES_KEPT, self::H], [$calls[0][0], array_slice($calls, -1)[0][1]]);
        self::assertSame((string) self::H, self::stored($id)['watch_last']);
    }

    // ------------------------------------------------------------ the end

    public function test_watch_ends_when_all_final(): void
    {
        $id = self::watching();
        self::watchIsds([self::changes([['1001', self::DELIVERED, 4], ['1001', self::LOGIN, 6], ['1002', self::DELIVERED, 4], ['1002', self::LOGIN, 8]])]);

        self::assertSame('done', self::runQueued());

        self::assertSame([Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_FAILED], self::states($id));
        self::assertSame('8', self::items()->field(self::message($id, 1), Schema::MESSAGE_ERROR_CODE));
        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());
    }

    // 16 days on, yesterday read whole: in the night not yet, in the morning it ends
    public function test_watch_ends_after_16_days_after_reading(): void
    {
        $night            = self::MIDNIGHT + 86400 + 9000;
        $h                = $night - 1800;
        TestSendRun::$now = $night;
        $since            = $night - StatusSync::WATCH_DAYS * 86400 - 3600;
        $id               = self::watching(2, ['watch_from' => (string) $since, 'watch_last' => (string) $h, 'watch_day' => '2030-03-16'], sent: $since - 600);
        $isds             = self::watchIsds();

        self::assertSame('step 1', self::runQueued());
        self::assertSame([[], SendRun::WATCHING], [$isds->windows(), self::stored($id)['retry_code']], 'yesterday is read only from 6:00');

        TestSendRun::$now = $h + 4 * 3600 + 600;
        self::assertSame('done', self::runQueued());

        self::assertSame([[$h - StatusSync::OVERLAP, $h + 4 * 3600], [self::MIDNIGHT, self::MIDNIGHT + 86400]], $isds->windows());
        self::assertSame('2030-03-17', self::stored($id)['watch_day']);
    }

    // every message final, but the step had no time left for an item: the run waits for it
    public function test_watch_does_not_end_with_dirty(): void
    {
        $delivered = self::entry(4, self::DELIVERED, self::DELIVERED, 0);
        $id        = self::watching(2, ['dm_state' => '{"0":"' . $delivered . '","1":"' . $delivered . '"}']);
        TestSendRun::$microNow = (float) self::NOW;
        self::watchIsds([static function (): Response {
            TestSendRun::$microNow += 30.0;
            return self::changes([['1001', self::LOGIN, 6], ['1002', self::LOGIN, 6]]);
        }]);

        self::assertSame('step 1', self::runQueued());
        self::assertSame([[0 => 1, 1 => 1], ['n' => 1, 'p' => 'watch']], [self::map($id, 'dm_dirty'), self::position($id)]);

        TestSendRun::$microNow = null;
        self::assertSame('done', self::runQueued());

        self::assertSame('6', self::items()->field(self::message($id, 1), Schema::MESSAGE_DM_STATUS));
    }

    // every message final in the night: the run waits for the morning, whose reading of yesterday tells the
    // time of an acceptance after 10 days the windows missed
    public function test_watch_all_final_in_the_night_waits_for_morning(): void
    {
        $h                = self::MIDNIGHT + 86400 + 3 * 3600;
        $fiction          = self::MIDNIGHT + 86400 - 1;
        TestSendRun::$now = $h + 600;
        $id               = self::watching(1, ['watch_last' => (string) ($h - 3600), 'watch_day' => '2030-03-16'], sent: $fiction - 11 * 86400);
        $isds             = self::watchIsds([self::changes([['1001', $h - 1800, 8]]), self::changes(), self::changes([['1001', $fiction, 5]])]);

        self::assertSame('step 1', self::runQueued());
        self::assertSame([SendRun::WATCHING, '8', ''], [self::stored($id)['retry_code'], self::items()->field(self::message($id, 0), Schema::MESSAGE_DM_STATUS), self::items()->field(self::message($id, 0), Schema::MESSAGE_ACCEPTED_AT)]);

        TestSendRun::$now = $h + 3 * 3600 + 600;
        self::assertSame('done', self::runQueued());

        self::assertSame([[$h - 3600 - StatusSync::OVERLAP, $h], [$h - StatusSync::OVERLAP, $h + 3 * 3600], [self::MIDNIGHT, self::MIDNIGHT + 86400]], $isds->windows());
        self::assertSame(['8', (string) $fiction], [self::items()->field(self::message($id, 0), Schema::MESSAGE_DM_STATUS), self::items()->field(self::message($id, 0), Schema::MESSAGE_ACCEPTED_AT)]);
    }

    // the end of following leaves the campaign as the end of the sending showed it
    public function test_watch_end_keeps_finished_at_of_sending(): void
    {
        self::watching();
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_FINISHED_AT] = (string) (self::SENT + 600);
        self::watchIsds([self::changes([['1001', self::LOGIN, 6], ['1002', self::LOGIN, 6]])]);

        self::assertSame('done', self::runQueued());

        self::assertSame([Schema::CAMPAIGN_STATE_DONE, (string) (self::SENT + 600)], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT)]);
        self::assertNotContains(self::CAMPAIGN_ITEM, self::items()->written('update'));
    }

    public function test_finalCheck_reports_delivery(): void
    {
        $id = self::watching(3, ['dm_state' => '{"2":"' . self::entry(6, self::LOGIN, self::DELIVERED, self::LOGIN) . '"}']);
        self::watchIsds([self::changes([['1001', self::LOGIN, 6], ['1002', self::DELIVERED, 4], ['1002', self::LOGIN, 8]])]);

        self::assertSame('done', self::runQueued());

        self::assertSame(['sent' => 2, 'failed' => 1, 'uncertain' => 0, 'skipped' => 0, 'stopped' => 0, 'delivered' => 2, 'accepted' => 2], json_decode(self::stored($id)['report'], true));
    }

    // ------------------------------------------------------------ what it asks, what it reads

    // never CreateMessage, nothing about received messages: the operations of Client only
    public function test_watch_calls_only_allowed_ops(): void
    {
        self::watching(2, ['owner_at' => (string) (self::NOW - 7200)]);
        $isds = self::watchIsds([self::changes([['1001', self::DELIVERED, 4]])]);

        self::runQueued();

        self::assertSame(['GetOwnerInfoFromLogin2', 'GetMessageStateChanges'], $isds->ops());
        self::assertSame([], array_diff($isds->ops(), array_keys(Client::OPERATIONS)));
    }

    // a round that learns nothing new reads no frozen recipients and leaves the pages be; one that does reads them once
    public function test_watch_quiet_round_reads_no_snapshot(): void
    {
        self::watching();
        self::watchIsds([self::changes(), self::changes([['1001', self::DELIVERED, 4], ['1002', self::DELIVERED, 4]])]);
        $from = count(TableDb::$log);

        self::runQueued();

        self::assertSame([[], 0, []], [preg_grep("/'rcpt'/", array_slice(TableDb::$log, $from)), TestSendRun::$invalidated, self::items()->writes]);

        TestSendRun::$now = self::NOW + 3600;
        $from             = count(TableDb::$log);
        self::runQueued();

        self::assertCount(1, preg_grep("/'rcpt'/", array_slice(TableDb::$log, $from)));
        self::assertSame([1, 2], [TestSendRun::$invalidated, count(self::items()->writes)]);
    }

    // ------------------------------------------------------------ the run, what ISDS answers

    /**
     * A run of $n recipients that sent them all as 1001, 1002, ... at $sent - its items sent, the campaign
     * done - and follows their delivery from 10 minutes later; ISDS was asked whose the login is now
     * @param array<string, string>     $props    own properties over those
     * @param array<string, int|string> $position
     */
    private static function watching(int $n = 2, array $props = [], array $position = self::WATCH, int $sent = self::SENT): string
    {
        $now              = (int) TestSendRun::$now;
        $dmIds            = array_map(static fn(int $k): string => (string) (1001 + $k), range(0, $n - 1));
        TestSendRun::$now = $sent;
        $id               = self::queued($n, $props + [
            'dm_ids'     => (string) json_encode($dmIds, JSON_FORCE_OBJECT),
            'cursor'     => (string) $n,
            'camp_state' => Schema::CAMPAIGN_STATE_DONE,
            'owner_at'   => (string) $now,
            'watch_from' => (string) ($sent + 600),
            'watch_last' => (string) $sent,
            'watch_day'  => StatusSync::firstWatchDay($sent + 600),
        ], $position);
        TestSendRun::$now = $now;
        foreach (self::rows($id) as $k => $row) {
            self::items()->mark($row['msg'], Schema::MESSAGE_STATE_SENT, Schema::EVENT_SUBMITTED, $sent, $dmIds[$k], senderRef: self::SHORT . '-' . ($k + 1));
        }
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = Schema::CAMPAIGN_STATE_DONE;
        self::items()->writes = [];
        return $id;
    }

    /**
     * ISDS for a round: the state changes as scripted, none when not; whose the login is when asked
     * @param list<Response|\Throwable|\Closure(): Response> $changes
     */
    private static function watchIsds(array $changes = []): ScriptedIsds
    {
        return TestSendRun::$http = new ScriptedIsds([
            'GetMessageStateChanges' => $changes ?: [self::changes()],
            'GetOwnerInfoFromLogin2' => [new Response(200, self::fixture('owner-info.xml'))],
        ]);
    }

    /**
     * GetMessageStateChanges with these changes
     * @param list<array{0: string, 1: int, 2: int}> $changes dmID, the time of the change, dmMessageStatus
     */
    private static function changes(array $changes = []): Response
    {
        $xml = '';
        foreach ($changes as [$dmId, $time, $status]) {
            $xml .= "<q:dmRecord><q:dmID>$dmId</q:dmID><q:dmEventTime>" . gmdate('Y-m-d\TH:i:s', $time) . '.999Z</q:dmEventTime>'
                . "<q:dmMessageStatus>$status</q:dmMessageStatus></q:dmRecord>";
        }
        return new Response(200, (string) preg_replace('~<q:dmRecords>.*</q:dmRecords>~s', "<q:dmRecords>$xml</q:dmRecords>", self::fixture('state-changes.xml')));
    }

    /** an entry of dm_state */
    private static function entry(int $s, int $t, int $d, int $a): string
    {
        return "$s:$t:$d:$a";
    }
}
