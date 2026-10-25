<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Http\Response;
use AA\Http\TransportException as HttpTransportException;
use AA\Isds\Client;
use AA\Isds\Lookup;
use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\SentMessage;
use AA\Isds\Snapshot;
use AA\Later\BatchRun;
use AA\Later\Toexecute;
use AA_Credentials;
use AA_Perm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\ScriptedIsds;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;

require_once __DIR__ . '/fixtures/SendRunCase.php';

/**
 * How a mass sending ends: messages of unknown fate looked up in the list of sent ones, the campaign
 * done; a stop that cancels what was never tried and goes on looking up the rest; Continue, and
 * giving up a run - over TableDb's object_text and toexecute, items in memory, ISDS from scripts.
 */
final class SendRunEndTest extends TestCase
{
    use SendRunCase;

    // ------------------------------------------------------------ look

    public function test_look_finds_uncertain_message_and_marks_it_sent(): void
    {
        $id   = self::queued(3, ['dm_ids' => '{"0":"901","2":"903"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '3'], self::LOOK);
        $isds = self::isds(list: [self::listed([['9002', '4711-2', self::box(1), self::NOW - 150]])]);

        self::assertSame('watch', self::lookOver($id));

        self::assertSame([0 => '901', 1 => '9002', 2 => '903'], self::map($id, 'dm_ids'));
        self::assertSame([1 => self::NOW - 200], self::map($id, 'uncertain'), 'the time it was tried stays');
        $msg = self::message($id, 1);
        self::assertSame(
            [Schema::MESSAGE_STATE_SENT, '9002', (string) (self::NOW - 200), (string) (self::NOW - 150), '4'],
            [self::state($id, 1), self::items()->field($msg, Schema::MESSAGE_DM_ID), self::items()->field($msg, Schema::MESSAGE_SENT_AT), self::items()->field($msg, Schema::MESSAGE_DELIVERED_AT), self::items()->field($msg, Schema::MESSAGE_DM_STATUS)],
            'sent when it was tried, delivered as the list says',
        );
        self::assertSame(Schema::EVENT_LOOKUP_FOUND, array_slice(self::kinds($id, 1), -1)[0]);
        self::assertSame(['GetListOfSentMessages'], $isds->ops());
        self::assertSame(gmdate('Y-m-d\TH:i:s', self::NOW - 260), substr(self::element($isds, 'dmFromTime'), 0, 19), 'from a minute before the attempt');
        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());
    }

    // a message is listed once it reached its box: the antivirus check takes up to 240 s, and ISDS lags at times
    public function test_look_waits_till_the_list_may_hold_it(): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901","2":"903"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '3'], self::LOOK);
        self::isds(list: [self::listed([])]);

        self::assertSame('step 1', self::runQueued());

        $until = self::NOW - 200 + Client::CREATE_TIMEOUT + Lookup::GRACE;
        self::assertSame(['i' => 0, 'p' => 'look', 'u' => $until], self::position($id));
        $stored = self::stored($id);
        self::assertSame([SendRun::LOOKING, (string) $until, '0'], [$stored['retry_code'], $stored['next_at'], $stored['failures']]);
        self::assertSame([[], [1 => self::NOW - 200]], [self::map($id, 'failed'), self::map($id, 'uncertain')]);
        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::state($id, 1));
    }

    public function test_look_fails_message_not_found_after_the_wait(): void
    {
        TestSendRun::$now = self::NOW - 200 + Client::CREATE_TIMEOUT + Lookup::GRACE;
        $id               = self::queued(3, ['dm_ids' => '{"0":"901","2":"903"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '3'], self::LOOK);
        self::isds(list: [self::listed([['9002', '4711-T2', self::box(1), self::NOW - 150]])]);

        self::assertSame('watch', self::lookOver($id));

        self::assertSame([1 => Schema::NOT_FOUND], self::map($id, 'failed'));
        $msg = self::message($id, 1);
        self::assertSame([Schema::MESSAGE_STATE_FAILED, Schema::NOT_FOUND], [self::state($id, 1), self::items()->field($msg, Schema::MESSAGE_ERROR_CODE)], 'not delivered, to be sent again by hand');
        self::assertSame(Schema::EVENT_LOOKUP_MISSING, array_slice(self::kinds($id, 1), -1)[0]);
        self::assertSame(['sent' => 2, 'failed' => 1], array_intersect_key(self::tally($id), ['sent' => 0, 'failed' => 0]));
        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());
    }

    // a reference is unique in one database only: which one it is is for a person to tell
    public function test_look_keeps_two_matches_uncertain(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        self::isds(list: [self::listed([['9002', '4711-2', self::box(1), self::NOW - 150], ['7002', '4711-2', self::box(1), self::NOW - 100]])]);

        self::assertSame('watch', self::lookOver($id));

        self::assertSame([[0 => '901'], [], [1 => self::NOW - 200]], [self::map($id, 'dm_ids'), self::map($id, 'failed'), self::map($id, 'uncertain')]);
        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::state($id, 1));
        self::assertSame(1, self::tally($id)['uncertain']);
    }

    // the record says uncertain; its item stayed sending when the step failed after recording it
    public function test_look_marks_item_left_sending_uncertain(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        self::items()->mark(self::message($id, 1), Schema::MESSAGE_STATE_SENDING, Schema::EVENT_SENDING, self::NOW - 200);
        self::isds(list: [self::listed([])]);

        self::runQueued();
        self::runQueued();

        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::state($id, 1));
        self::assertSame([Schema::EVENT_QUEUED, Schema::EVENT_SENDING, Schema::EVENT_TIMEOUT], self::kinds($id, 1), 'once');
    }

    // a step that died with a message in flight left it uncertain - looked up as any other
    public function test_look_takes_inflight_as_uncertain(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'inflight' => '1', 'inflight_at' => (string) (self::NOW - 200), 'cursor' => '1'], self::LOOK);
        self::isds(list: [self::listed([['9002', '4711-2', self::box(1), self::NOW - 150]])]);

        self::assertSame('watch', self::lookOver($id));

        self::assertSame([[0 => '901', 1 => '9002'], ''], [self::map($id, 'dm_ids'), self::stored($id)['inflight']]);
        self::assertSame(Schema::MESSAGE_STATE_SENT, self::state($id, 1));
    }

    public function test_look_checks_the_account(): void
    {
        $id                = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        $isds              = self::isds();
        TestSendRun::$conf = null;

        self::assertSame('error ' . SendRun::ACCOUNT_CHANGED, self::runQueued());

        self::assertSame([], $isds->ops());
        self::assertSame([1 => self::NOW - 200], self::map($id, 'uncertain'));
    }

    // nothing to look up: no account, no ISDS
    public function test_look_ends_without_isds_when_nothing_uncertain(): void
    {
        $id                = self::queued(2, ['dm_ids' => '{"0":"901"}', 'failed' => '{"1":"1233"}', 'cursor' => '2'], self::LOOK);
        TestSendRun::$conf = null;

        self::assertSame('watch', self::lookOver($id));

        self::assertSame((string) self::NOW, self::stored($id)['watch_from']);
        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());
    }

    public function test_look_pauses_while_isds_does_not_answer(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        self::isds(list: [new HttpTransportException('Connection timed out after 30000 milliseconds')]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame(['i' => 0, 'p' => 'look', 's' => self::NOW, 'u' => self::NOW + 30, 'w' => 1], self::position($id));
        self::assertSame('ISDS_UNAVAILABLE', self::stored($id)['retry_code']);
        self::assertSame([1 => self::NOW - 200], self::map($id, 'uncertain'));
    }

    // a file PHP cannot read now and then: the look up waits as for ISDS, and asks ISDS nothing
    public function test_look_pauses_while_the_file_cannot_be_read(): void
    {
        $id                = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        $isds              = self::isds();
        TestSendRun::$conf = Sending::fileConf(broken: false);

        self::assertSame('step 1', self::runQueued());

        self::assertSame(['i' => 0, 'p' => 'look', 's' => self::NOW, 'u' => self::NOW + 30, 'w' => 1], self::position($id));
        self::assertSame('ISDS_CONFIG', self::stored($id)['retry_code']);
        self::assertSame([], $isds->ops());
        self::assertSame([1 => self::NOW - 200], self::map($id, 'uncertain'));
    }

    public function test_look_fails_after_six_hours_without_the_file(): void
    {
        self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], ['i' => 0, 'p' => 'look', 'w' => 9, 'u' => self::NOW, 's' => self::NOW - 6 * 3600 - 1]);
        $isds              = self::isds();
        TestSendRun::$conf = Sending::fileConf(broken: false);

        self::assertSame('error ISDS_CONFIG', self::runQueued());

        self::assertSame([], $isds->ops());
    }

    // a step run before its wait is over - Execute in the queue manager - asks ISDS nothing
    public function test_look_asks_nothing_before_its_wait_ends(): void
    {
        $id   = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], ['i' => 0, 'p' => 'look', 'u' => self::NOW + 300]);
        $isds = self::isds();
        self::storedSet($id, 'retry_code', SendRun::LOOKING);

        self::runQueued();

        self::assertSame([], $isds->ops());
        self::assertSame(['e' => 1, 'i' => 0, 'p' => 'look', 'u' => self::NOW + 300], self::position($id));
    }

    // the last page, started as the budget ends, and the items written after it: cut short, the step is taken for dead
    public function test_overrun_of_look_covers_the_lookup(): void
    {
        $id = self::queued(2, [], self::LOOK);

        self::assertGreaterThan(3 * Client::TIMEOUT, (int) TestSendRun::get($id)?->overrunOf());
    }

    // the antivirus check refused it, or took over 240 s: never delivered, to be sent again by hand
    public function test_look_fails_message_refused_by_antivirus(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        self::isds(list: [self::listed([['9002', '4711-2', self::box(1), null, SentMessage::AV_REFUSED]])]);

        self::assertSame('watch', self::lookOver($id));

        self::assertSame([[0 => '901'], [1 => '3']], [self::map($id, 'dm_ids'), self::map($id, 'failed')]);
        self::assertSame([Schema::MESSAGE_STATE_FAILED, '9002'], [self::state($id, 1), self::items()->field(self::message($id, 1), Schema::MESSAGE_DM_ID)]);
        self::assertSame(Schema::EVENT_AV, array_slice(self::kinds($id, 1), -1)[0]);
        self::assertSame(['sent' => 1, 'failed' => 1], array_intersect_key(self::tally($id), ['sent' => 0, 'failed' => 0]));
    }

    // a page starts only while the budget lasts: a part of the list decides nothing, the next step reads on
    public function test_look_reads_a_long_list_over_steps(): void
    {
        $id                    = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        $others                = array_map(static fn(int $i): array => [(string) (5000 + $i), "99-$i", 'b000009', self::NOW - 100], range(1, Client::MAX_LIST));
        TestSendRun::$microNow = (float) self::NOW;
        $isds                  = self::isds(list: [
            static function () use ($others): Response {
                // the page took the rest of the budget
                TestSendRun::$microNow += 30.0;
                return self::listed($others);
            },
            self::listed([...array_slice($others, -Lookup::OVERLAP), ['9002', '4711-2', self::box(1), self::NOW - 150]]),
        ]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame(['GetListOfSentMessages'], $isds->ops());
        self::assertSame([Client::MAX_LIST - Lookup::OVERLAP + 1, 1], [self::position($id)['l']['o'], self::position($id)['l']['c']]);
        self::assertSame(['0', [], Schema::MESSAGE_STATE_QUEUED], [self::stored($id)['failures'], self::map($id, 'failed'), self::state($id, 1)], 'nothing decided, and no failure');
        self::assertSame('watch', self::lookOver($id));
        self::assertSame([[0 => '901', 1 => '9002'], (string) (Client::MAX_LIST - Lookup::OVERLAP + 1)], [self::map($id, 'dm_ids'), self::element($isds, 'dmOffset')]);
    }

    // an answer of ISDS ends a pause: the next one waits from the start again
    public function test_look_clears_pause_after_an_answer(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], ['e' => 2, 'i' => 0, 'p' => 'look', 's' => self::NOW - 600, 'u' => self::NOW - 1, 'w' => 3]);
        self::isds(list: [self::listed([])]);

        self::runQueued();

        self::assertSame(['i' => 0, 'p' => 'look', 'u' => self::NOW - 200 + Client::CREATE_TIMEOUT + Lookup::GRACE], self::position($id));
    }

    // a reading over steps that ends waiting is done with: the next look reads from the start, its window now
    public function test_look_reads_anew_after_waiting(): void
    {
        $id     = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        $others = array_map(static fn(int $i): array => [(string) (5000 + $i), "99-$i", 'b000009', self::NOW - 100], range(1, Client::MAX_LIST));
        $until  = self::NOW - 200 + Client::CREATE_TIMEOUT + Lookup::GRACE;
        TestSendRun::$microNow = (float) self::NOW;
        $isds   = self::isds(list: [
            static function () use ($others): Response {
                TestSendRun::$microNow += 30.0;
                return self::listed($others);
            },
            self::listed(array_slice($others, -Lookup::OVERLAP)),
            self::listed([]),
        ]);
        self::runQueued();
        self::runQueued();
        self::assertSame(['i' => 0, 'p' => 'look', 'u' => $until], self::position($id), 'waiting, the reading done with');

        TestSendRun::$now = $until;
        self::storedSet($id, 'owner_at', (string) $until);
        self::assertSame('watch', self::lookOver($id));

        self::assertSame([1 => Schema::NOT_FOUND], self::map($id, 'failed'));
        self::assertSame(['1', gmdate('Y-m-d\TH:i:s', $until)], [self::element($isds, 'dmOffset'), substr(self::element($isds, 'dmToTime'), 0, 19)]);
    }

    // another step took the run while this one read the list: nothing it found is written, not even an item
    public function test_look_writes_no_item_when_taken_while_reading(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        self::isds(list: [static function () use ($id): Response {
            self::storedSet($id, 'lease_tok', 'another-step');
            return self::listed([['9002', '4711-2', self::box(1), self::NOW - 150]]);
        }]);

        self::runQueued();

        self::assertSame([[0 => '901'], Schema::MESSAGE_STATE_QUEUED], [self::map($id, 'dm_ids'), self::state($id, 1)]);
    }

    // ------------------------------------------------------------ the end

    // a sending that sent: the campaign shows the end before following delivery, and tries again till it does
    public function test_look_end_marks_campaign_done_and_retries(): void
    {
        $id     = self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2'], self::LOOK);
        $failed = false;
        self::items()->onWrite = function () use (&$failed): void {
            $last = array_slice(self::items()->writes, -1)[0];
            if (!$failed && ($last['id'] === self::CAMPAIGN_ITEM)) {
                $failed = true;
                throw new \RuntimeException('Campaign item not stored');
            }
        };

        self::assertSame('step 1', self::runQueued());
        $stored = self::stored($id);
        self::assertSame(['sending', 'FINAL_CHECK', '1', (string) (self::NOW + 60)], [$stored['state'], $stored['retry_code'], $stored['failures'], $stored['next_at']], 'tried again as the end of a run');
        self::assertSame([self::LOOK, '', Schema::CAMPAIGN_STATE_QUEUED], [self::position($id), $stored['watch_from'] ?? '', self::campaign()]);
        $logged = array_values(array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Toexecute::FAILED_EVENT));
        self::assertCount(1, $logged, 'the failure is in the log, as the core logs one');
        self::assertStringContainsString('RuntimeException', $logged[0]['params']);

        TestSendRun::$now = self::NOW + 61;
        self::assertSame('watch', self::lookOver($id));

        self::assertSame([Schema::CAMPAIGN_STATE_DONE, (string) (self::NOW + 61)], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT)]);
        self::assertSame((string) (self::NOW + 61), self::stored($id)['watch_from']);
    }

    // the end of look keeps failing: the run errs as one whose end keeps failing
    public function test_look_end_campaign_failing_errs(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2'], self::LOOK);
        self::items()->onWrite = static function (): void {
            if (array_slice(self::items()->writes, -1)[0]['id'] === self::CAMPAIGN_ITEM) {
                throw new \RuntimeException('Campaign item not stored');
            }
        };

        for ($try = 1; $try <= 3; ++$try) {
            $said             = self::runQueued();
            TestSendRun::$now = (int) self::stored($id)['next_at'];
        }

        self::assertSame(['error FINAL_CHECK', 'error', 'FINAL_CHECK', ''], [$said, self::stored($id)['state'], self::stored($id)['error_code'], self::stored($id)['watch_from'] ?? '']);
    }

    public function test_finalCheck_marks_campaign_done_and_retries(): void
    {
        // nothing sent, so nothing to follow: the campaign ends in the final phase
        $id     = self::queued(2, ['failed' => '{"0":"1233","1":"1233"}', 'cursor' => '2'], self::LOOK);
        $failed = false;
        self::items()->onWrite = function () use (&$failed): void {
            $last = array_slice(self::items()->writes, -1)[0];
            if (!$failed && ($last['id'] === self::CAMPAIGN_ITEM)) {
                $failed = true;
                throw new \RuntimeException('Campaign item not stored');
            }
        };

        self::assertNotSame('done', self::runQueued());
        $stored = self::stored($id);
        self::assertSame(['sending', '1', 'FINAL_CHECK'], [$stored['state'], $stored['closing'], $stored['retry_code']], 'the core tries the final phase again');
        self::assertSame(Schema::CAMPAIGN_STATE_QUEUED, self::campaign());

        TestSendRun::$now = self::NOW + 61;
        self::assertSame('done', self::finish());

        self::assertSame([Schema::CAMPAIGN_STATE_DONE, (string) (self::NOW + 61)], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT)]);
        self::assertSame(['sent' => 0, 'failed' => 2, 'uncertain' => 0, 'skipped' => 0, 'stopped' => 0, 'delivered' => 0, 'accepted' => 0], json_decode(self::stored($id)['report'], true));
    }

    // an end of look repeated after its campaign was written keeps the time the sending finished
    public function test_look_end_keeps_campaign_it_ended(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2'], self::LOOK);
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'] = [Schema::CAMPAIGN_STATE => Schema::CAMPAIGN_STATE_DONE, Schema::CAMPAIGN_FINISHED_AT => (string) (self::NOW - 60)];

        self::assertSame('watch', self::lookOver($id));

        self::assertSame([[], (string) (self::NOW - 60)], [self::items()->written('update'), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT)]);
    }

    public function test_look_end_counts_skipped_as_done(): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'skips' => '{"2":"inactive"}', 'cursor' => '3'], self::LOOK, [1 => Schema::BOX_DUPLICATE]);
        self::assertSame('watch', self::lookOver($id));
        self::assertSame(['sent' => 1, 'failed' => 0, 'uncertain' => 0, 'skipped' => 2, 'stopped' => 0], self::tally($id));
        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());
    }

    // ------------------------------------------------------------ stop

    // what was never tried comes from the record, whatever an item says; what was tried stays as it is
    public function test_stop_cancels_never_attempted_from_record(): void
    {
        $id = self::queued(8, [
            'dm_ids'    => '{"0":"901"}',
            'uncertain' => '{"1":' . (self::NOW - 200) . '}',
            'failed'    => '{"2":"1233"}',
            'skips'     => '{"3":"inactive"}',
            'cursor'    => '4',
        ], ['i' => 4, 'p' => 'send'], [5 => Schema::BOX_DUPLICATE]);
        foreach ([0 => Schema::MESSAGE_STATE_QUEUED, 1 => Schema::MESSAGE_STATE_UNCERTAIN, 2 => Schema::MESSAGE_STATE_FAILED, 4 => Schema::MESSAGE_STATE_SENT] as $i => $state) {
            self::items()->stored[self::message($id, $i)]['fields'][Schema::MESSAGE_STATE] = $state;
        }

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::assertSame(Sending::USER, self::stored($id)['stop_by']);
        self::runQueued();

        self::assertSame('4', self::stored($id)['cursor'], 'nothing moves it after the stop');
        self::assertSame([
            Schema::MESSAGE_STATE_QUEUED, Schema::MESSAGE_STATE_UNCERTAIN, Schema::MESSAGE_STATE_FAILED, Schema::MESSAGE_STATE_QUEUED,
            Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_SKIPPED, Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_STOPPED,
        ], self::states($id));
        self::assertSame(array_map(static fn(int $i): string => self::message($id, $i), [4, 6, 7]), array_values(array_diff(self::items()->written('update'), [self::CAMPAIGN_ITEM])), 'only those');
        self::assertSame(self::LOOK, self::position($id));
    }

    // a cursor lowered by hand: what the record has the fate of stays as it is
    public function test_stop_below_lowered_cursor_leaves_decided_alone(): void
    {
        $id = self::queued(4, ['dm_ids' => '{"0":"901","2":"903"}', 'uncertain' => '{"3":' . (self::NOW - 100) . '}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        foreach ([0 => Schema::MESSAGE_STATE_SENT, 2 => Schema::MESSAGE_STATE_SENT, 3 => Schema::MESSAGE_STATE_UNCERTAIN] as $i => $state) {
            self::items()->stored[self::message($id, $i)]['fields'][Schema::MESSAGE_STATE] = $state;
        }

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::runQueued();

        self::assertSame([Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_UNCERTAIN], self::states($id));
    }

    // a dmID edited into null by hand is still a message sent: the stop leaves its item be
    public function test_stop_leaves_message_whose_dm_id_was_edited_to_null(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":null}', 'cursor' => '0'], self::SEND);

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::runQueued();

        self::assertSame([Schema::MESSAGE_STATE_QUEUED, Schema::MESSAGE_STATE_STOPPED], self::states($id));
    }

    // a stop meets a step that has no time for all items: the next one goes on from there
    public function test_stop_goes_on_where_time_ran_out(): void
    {
        $id = self::queued(120);
        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        TestSendRun::$microNow = (float) self::NOW;
        self::items()->onWrite = static function (): void {
            TestSendRun::$microNow += 1.0;
        };
        self::runQueued();
        self::assertSame(['i' => 50, 'p' => 'stop'], self::position($id));
        self::runQueued();
        self::assertSame(['i' => 100, 'p' => 'stop'], self::position($id), 'the next step goes on from there');
    }

    // stopping needs no ISDS: it works while ISDS does not answer
    public function test_stop_cancels_items_while_isds_down(): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send', 's' => self::NOW - 200, 'u' => self::NOW + 120, 'w' => 3]);
        self::storedSet($id, 'next_at', (string) (self::NOW + 120));
        self::storedSet($id, 'retry_code', 'ISDS_UNAVAILABLE');

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false), 'no need to wait for the pause');
        self::assertSame('watch', self::lookOver($id));

        self::assertSame([Schema::MESSAGE_STATE_QUEUED, Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_STOPPED], self::states($id));
        self::assertSame(Schema::CAMPAIGN_STATE_STOPPED, self::campaign());
    }

    public function test_stop_of_run_failed_on_account_cancels_items(): void
    {
        $id                = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        TestSendRun::$conf = null;
        self::assertSame('error ' . SendRun::ACCOUNT_CHANGED, self::runQueued());

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::assertSame('sending', self::stored($id)['state'], 'Continue at once');
        self::assertSame('watch', self::lookOver($id));

        self::assertSame([Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_STOPPED], [self::state($id, 1), self::state($id, 2)]);
        self::assertSame([Schema::CAMPAIGN_STATE_STOPPED, (string) self::NOW], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT)]);
        self::assertSame(['sent' => 1, 'failed' => 0, 'uncertain' => 0, 'skipped' => 0, 'stopped' => 2], self::tally($id));
    }

    // prep had made one item: the others are made stopped, or skipped for their reason
    public function test_stop_during_prep_covers_all_recipients(): void
    {
        $id = self::queued(4, ['cursor' => ''], ['i' => 1, 'p' => 'prep'], [2 => Schema::BOX_DUPLICATE], 1);

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::runQueued();

        self::assertSame([Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_SKIPPED, Schema::MESSAGE_STATE_STOPPED], self::states($id));
        self::assertSame([[Schema::EVENT_STOPPED], [Schema::EVENT_SKIPPED]], [self::kinds($id, 3), self::kinds($id, 2)]);
        self::assertSame([true], array_values(array_unique(array_column(self::items()->writes, 'tx'))), 'every item in a transaction');
        self::assertSame(self::LOOK, self::position($id));
        self::assertSame('done', self::runQueued());
        self::assertSame(Schema::CAMPAIGN_STATE_STOPPED, self::campaign());
    }

    // the box of the next message checked, then the stop: its message does not go
    public function test_work_sends_nothing_when_stopped_after_check(): void
    {
        $id      = self::queued(3);
        $stopped = null;
        $isds    = self::isds(check: [static function () use (&$stopped): Response {
            $stopped ??= TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false);
            return self::boxIs(1);
        }]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame(['', [], ['CheckDataBox']], [$stopped, $isds->sent(), $isds->ops()]);
        self::assertSame(['i' => 0, 'p' => 'stop'], self::position($id));
        self::assertSame(['', '0', Sending::USER], [self::stored($id)['inflight'], self::stored($id)['cursor'], self::stored($id)['stop_by']]);
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, 0));
        self::runQueued();
        self::assertSame(Schema::MESSAGE_STATE_STOPPED, self::state($id, 0));
    }

    // the message on its way is recorded and shown sent; the next one does not go
    public function test_stop_while_sending_records_dm_id(): void
    {
        $id   = self::queued(3);
        $isds = self::isds(create: [static function (): Response {
            TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false);
            return self::created('1001');
        }]);

        self::runQueued();
        self::assertSame('watch', self::lookOver($id));

        self::assertSame([self::box(0)], $isds->sent());
        self::assertSame([0 => '1001'], self::map($id, 'dm_ids'));
        self::assertSame([Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_STOPPED], self::states($id));
        self::assertSame(['1', Schema::CAMPAIGN_STATE_STOPPED], [self::stored($id)['cursor'], self::campaign()]);
    }

    // the stop came while a message was on its way: after it no box is checked, none skipped
    public function test_stop_while_sending_checks_no_further_box(): void
    {
        $id   = self::queued(5);
        $isds = self::isds(create: [static function (): Response {
            TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false);
            return self::created('1001');
        }], check: [self::boxIs(1), self::boxIs(3)]);

        self::runQueued();

        self::assertSame(['CheckDataBox', 'CreateMessage'], $isds->ops());
        self::assertSame([[], ['i' => 0, 'p' => 'stop']], [self::map($id, 'skips'), self::position($id)]);
    }

    // the message on its way got no answer: the stop does not wait out the pause, it needs no ISDS
    public function test_stop_while_sending_skips_the_pause(): void
    {
        $id = self::queued(3);
        self::isds(create: [static function (): Response {
            TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false);
            throw new HttpTransportException('Operation timed out after 300000 milliseconds');
        }]);

        self::runQueued();

        self::assertSame([['i' => 0, 'p' => 'stop'], '', [0 => self::NOW]], [self::position($id), self::stored($id)['retry_code'], self::map($id, 'uncertain')]);
    }

    // the stop came with the last message on its way: nothing was left unsent, the sending is done
    public function test_stop_during_last_message_keeps_done(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        self::isds(create: [static function (): Response {
            TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false);
            return self::created('1002');
        }]);
        self::assertSame('watch', self::lookOver($id));
        self::assertSame([Sending::USER, Schema::CAMPAIGN_STATE_DONE], [self::stored($id)['stop_by'], self::campaign()]);
    }

    // a second click: the run goes on stopping, it is not given up
    public function test_second_stop_keeps_a_going_run(): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        self::assertSame(['', ''], [TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false), TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false)]);
        self::assertSame('sending', self::stored($id)['state']);
    }

    // the one who stopped it first stays: a second stop by someone else changes nothing
    public function test_second_stop_by_another_keeps_the_first(): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        $stopped = self::stored($id);
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']], 'uid-other' => [Sending::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]]);

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, 'uid-other', false));

        self::assertSame($stopped, self::stored($id));
        self::assertSame(Sending::USER, $stopped['stop_by']);
    }

    // stopped already, every message tried meanwhile: the stop goes on, it did not come after all had gone
    public function test_second_stop_after_all_tried_keeps_stopping(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2', 'stop_by' => Sending::USER], ['i' => 2, 'p' => 'send']);
        $before = self::stored($id);

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame($before, self::stored($id));
    }

    // a stop step that failed is repeated: the items it stopped get no second entry
    public function test_stop_repeated_adds_no_second_event(): void
    {
        $id = self::queued(2);
        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        TestSendRun::$invalidateFails = new \RuntimeException('Pages not invalidated');
        self::assertSame('error INTERNAL', self::runQueued());
        TestSendRun::$invalidateFails = null;
        self::assertSame('', TestSendRun::resumeFor(self::CAMPAIGN_ITEM, Sending::USER));
        self::assertSame('done', self::finish());
        self::assertSame([Schema::EVENT_QUEUED, Schema::EVENT_STOPPED], self::kinds($id, 1));
    }

    // a run that would not go on by itself goes on at once, so the stop takes effect
    #[DataProvider('notGoingOn')]
    public function test_stop_resumes_dead_or_stuck_run(\Closure $leave): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        $leave($id);
        $step = (int) self::stored($id)['step'];

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        $stored = self::stored($id);
        self::assertSame(['sending', (string) ($step + 1), Sending::USER], [$stored['state'], $stored['step'], $stored['stop_by']]);
        self::assertCount(1, self::queue());
        self::runQueued();
        self::assertSame([Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_STOPPED], [self::state($id, 1), self::state($id, 2)]);
    }

    /** @return array<string, array{\Closure(string): void}> */
    public static function notGoingOn(): array
    {
        return [
            'in error'      => [static function (string $id): void {
                self::storedSet($id, 'state', 'error');
                self::storedSet($id, 'error_code', 'ISDS_LOGIN');
            }],
            'waiting'       => [static function (string $id): void {
                self::storedSet($id, 'next_at', (string) (self::NOW + 600));
            }],
            'its step dead' => [static function (string $id): void {
                self::storedSet($id, 'lease', (string) (self::NOW - BatchRun::DEAD_GRACE - 1));
                self::storedSet($id, 'lease_tok', 'dead-step');
            }],
            'hanging'       => [static function (string $id): void {
                self::storedSet($id, 'started', (string) (self::NOW - 86400));
                TableDb::$tables['toexecute'] = [];
            }],
        ];
    }

    // everything has gone: nothing to stop, the sending ends done
    #[DataProvider('allSent')]
    public function test_stop_after_all_sent_keeps_done(array $props, array $position, string $state, string $code): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}'] + $props, $position);
        self::storedSet($id, 'state', $state);
        $before = self::stored($id);

        self::assertSame($code, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame($before, self::stored($id), 'nothing written');
        if ($state !== 'done') {
            self::assertSame('watch', self::lookOver($id));
            self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());
        }
    }

    /** @return array<string, array{array<string, string>, array<string, int|string>, string, string}> */
    public static function allSent(): array
    {
        return [
            'looking up'          => [['cursor' => '2'], self::LOOK, 'sending', SendRun::ALL_SENT],
            'every message tried' => [['cursor' => '2'], ['i' => 2, 'p' => 'send'], 'sending', SendRun::ALL_SENT],
            'done'                => [['cursor' => '2'], self::LOOK, 'done', 'WRONG_STATE'],
        ];
    }

    // stopped, what was sent is still looked up
    public function test_stop_then_look_tracks_sent(): void
    {
        $id   = self::queued(3, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 100) . '}', 'cursor' => '2'], ['i' => 2, 'p' => 'send']);
        $isds = self::isds(list: [self::listed([['9002', '4711-2', self::box(1), self::NOW - 50]])]);

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::assertSame('watch', self::lookOver($id));

        self::assertSame(['GetListOfSentMessages'], $isds->ops());
        self::assertSame([0 => '901', 1 => '9002'], self::map($id, 'dm_ids'));
        self::assertSame([Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_STOPPED], [self::state($id, 1), self::state($id, 2)]);
        self::assertSame(Schema::CAMPAIGN_STATE_STOPPED, self::campaign());
    }

    // the account went away: the run cannot look up what may have gone - a person gives it up
    public function test_abandon_gives_up_run_failed_after_stop(): void
    {
        $id                = self::queued(3, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 100) . '}', 'cursor' => '2'], ['i' => 2, 'p' => 'send']);
        TestSendRun::$conf = null;
        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::runQueued();
        self::assertSame('error ' . SendRun::ACCOUNT_CHANGED, self::runQueued());

        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));

        self::assertSame(['stopped', SendRun::ABANDONED], [self::stored($id)['state'], json_decode(self::stored($id)['progress'], true)['note']]);
        self::assertSame('closed', self::runQueued());
        self::assertSame([], self::queue());
        self::assertSame([Schema::MESSAGE_STATE_QUEUED, Schema::MESSAGE_STATE_UNCERTAIN, Schema::MESSAGE_STATE_STOPPED], self::states($id), 'left for a person to verify');
        self::assertSame(Schema::CAMPAIGN_STATE_STOPPED, self::campaign());
        self::assertSame('WRONG_STATE', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false), 'given up for good');
    }

    // looking up failed for good - the account went away, ISDS refuses the list: nothing is left to stop, it can only be given up
    #[DataProvider('lookupFailures')]
    public function test_abandon_gives_up_run_failed_looking_up(\Closure $fail, string $error): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], self::LOOK);
        $fail();
        self::assertSame("error $error", self::runQueued());

        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
        self::assertSame('closed', self::runQueued());

        self::assertSame([Schema::MESSAGE_STATE_QUEUED, Schema::MESSAGE_STATE_UNCERTAIN], self::states($id), 'left for a person to verify');
        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign(), 'nothing was stopped');
    }

    /** @return array<string, array{\Closure(): void, string}> */
    public static function lookupFailures(): array
    {
        return [
            'the account gone' => [static function (): void {
                TestSendRun::$conf = null;
            }, SendRun::ACCOUNT_CHANGED],
            'the list refused' => [static function (): void {
                self::isds(list: [new Response(200, str_replace('<q:dmStatusCode>0000<', '<q:dmStatusCode>1214<', self::fixture('sent-messages.xml')))]);
            }, SendRun::REFUSED],
        ];
    }

    // the stop left nothing unsent - the last recipient was skipped for its reason: given up, the sending shows done
    public function test_abandon_with_nothing_stopped_shows_done(): void
    {
        self::queued(3, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 100) . '}', 'cursor' => '2'], ['i' => 2, 'p' => 'send'], [2 => Schema::BOX_DUPLICATE]);
        TestSendRun::$conf = null;
        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::runQueued();
        self::assertSame('error ' . SendRun::ACCOUNT_CHANGED, self::runQueued());

        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
        self::assertSame('closed', self::runQueued());

        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());
    }

    // a stop ends any run: one whose manifest no step can read is stopped, fails on it, and can then be given up
    public function test_stop_ends_run_with_broken_manifest(): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        self::storedSet($id, 'manifest', '{"rcpt":"lost"}');
        self::leftInError($id, Snapshot::CHANGED);

        self::assertSame('', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));
        self::assertSame('error ' . Snapshot::CHANGED, self::runQueued());
        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
        self::runQueued();

        self::assertSame(['stopped', Sending::USER], [self::stored($id)['state'], self::stored($id)['stop_by']]);
    }

    // the queue may close a run twice: nothing is written again, the campaign keeps the time it finished
    public function test_abandon_closed_twice_writes_nothing_again(): void
    {
        self::abandoned();
        $row = self::queue()[0];
        self::assertSame('closed', self::runQueued());
        $stored = self::items()->stored;

        TestSendRun::$now               = self::NOW + 100;
        TableDb::$tables['toexecute'][] = $row;
        self::assertSame('closed', self::runQueued());

        self::assertSame($stored, self::items()->stored);
        self::assertSame((string) self::NOW, self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT));
    }

    // a newer run of the campaign owns what the campaign shows
    public function test_abandon_leaves_campaign_to_newer_run(): void
    {
        self::abandoned();
        TestSendRun::$now = self::NOW + 10;
        self::queued(1);

        self::assertSame('closed', self::runQueued());

        self::assertNotContains(self::CAMPAIGN_ITEM, self::items()->written('update'));
        self::assertSame(Schema::CAMPAIGN_STATE_QUEUED, self::campaign());
    }

    // the slices have reading passwords: an item read masked would be stored masked
    public function test_abandon_writes_items_with_slices_open(): void
    {
        $id = self::abandoned();

        self::assertSame('closed', self::runQueued());

        self::assertSame([Schema::MESSAGE_STATE_UNCERTAIN, Schema::MESSAGE_STATE_STOPPED], [self::state($id, 2), self::state($id, 3)], 'in flight, never tried');
        self::assertNotSame([], self::items()->writes);
        foreach (self::items()->writes as $write) {
            self::assertSame([Sending::CAMPAIGN => true, Sending::MESSAGE => true], $write['open']);
        }
        $credentials = AA_Credentials::singleton();
        self::assertSame([false, false], [$credentials->checkSlice(Sending::CAMPAIGN), $credentials->checkSlice(Sending::MESSAGE)], 'and closed again');
        self::assertSame(Schema::CAMPAIGN_STATE_STOPPED, self::campaign());
    }

    // giving up writes what it can: one item that fails does not keep the others as they were
    public function test_abandon_goes_on_past_a_failing_item(): void
    {
        $id     = self::abandoned();
        $failed = false;
        self::items()->onWrite = static function () use (&$failed): void {
            if (!$failed) {
                $failed = true;
                throw new \RuntimeException('Message item not stored');
            }
        };

        self::assertSame('closed', self::runQueued());

        self::assertSame([Schema::MESSAGE_STATE_STOPPED, Schema::CAMPAIGN_STATE_STOPPED], [self::state($id, 3), self::campaign()]);
        self::assertSame('after_close', json_decode(self::stored($id)['progress'], true)['warning']);
    }

    // asked before the locks: a stranger holds up no one, not even one who holds the lock of the campaign
    public function test_stop_refuses_foreign_user(): void
    {
        $id                          = self::queued(2);
        TestSendRun::$lockedByOthers = [self::CAMPAIGN_ITEM];

        self::assertSame(['FORBIDDEN', 'FORBIDDEN'], [TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::READER, false), TestSendRun::resumeFor(self::CAMPAIGN_ITEM, Sending::READER)]);

        self::assertSame('', self::stored($id)['stop_by']);
    }

    // and again under them: the right may have gone while the request waited for the lock
    public function test_stop_asks_control_again_under_the_lock(): void
    {
        $id                  = self::queued(2);
        TestSendRun::$onLock = static function (): void {
            FakePerms::install([]);
        };

        self::assertSame('FORBIDDEN', TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame('', self::stored($id)['stop_by']);
    }

    // two clicks, two people: one at a time
    public function test_stop_takes_campaign_lock_outside_transaction(): void
    {
        $id = self::queued(2);

        self::assertSame('', TestSendRun::stop(strtoupper(self::CAMPAIGN_ITEM), Sending::USER, false));

        self::assertSame(['lock ' . self::CAMPAIGN_ITEM, 'unlock ' . self::CAMPAIGN_ITEM], TestSendRun::$lockLog, 'released after the commit');
        self::assertSame(Sending::USER, self::stored($id)['stop_by']);
    }

    // queueing a campaign takes the same lock, whatever the letters of its id
    public function test_lockName_oneForEachCampaign(): void
    {
        self::assertSame('isds-campaign:' . self::CAMPAIGN_ITEM, SendRun::lockName(strtoupper(self::CAMPAIGN_ITEM)));
        self::assertNotSame(SendRun::lockName(self::CAMPAIGN_ITEM), SendRun::lockName('ca000000000000000000000000000c02'));
    }

    public function test_stop_busy_while_campaign_locked(): void
    {
        $id                          = self::queued(2);
        TestSendRun::$lockedByOthers = [self::CAMPAIGN_ITEM];

        self::assertSame(SendRun::BUSY, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame('', self::stored($id)['stop_by']);
    }

    public function test_stop_without_run_is_wrong_state(): void
    {
        self::assertSame('WRONG_STATE', TestSendRun::stop('ca000000000000000000000000000c02', Sending::USER, false));
        self::assertSame('WRONG_STATE', TestSendRun::stop('not a campaign', Sending::USER, false));
    }

    // ------------------------------------------------------------ Continue

    public function test_resumeFor_continues_run_in_error(): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        self::leftInError($id, SendRun::ACCOUNT_CHANGED);

        self::assertSame('', TestSendRun::resumeFor(self::CAMPAIGN_ITEM, Sending::USER));

        self::assertSame(['sending', ''], [self::stored($id)['state'], self::stored($id)['error_code']]);
        self::assertCount(1, self::queue());
    }

    // a reading of the list a step failed on would fail again: Continue reads from the start
    public function test_resumeFor_reads_the_list_anew(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 200) . '}', 'cursor' => '2'], ['i' => 0, 'l' => ['o' => '981'], 'p' => 'look']);
        self::storedSet($id, 'state', 'error');
        TableDb::$tables['toexecute'] = [];

        self::assertSame('', TestSendRun::resumeFor(self::CAMPAIGN_ITEM, Sending::USER));

        self::assertSame(self::LOOK, self::position($id));
    }

    #[DataProvider('notResumed')]
    public function test_resumeFor_refuses(\Closure $leave, string $uid, string $code): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1'], ['i' => 1, 'p' => 'send']);
        $leave($id);
        $before = self::stored($id);

        self::assertSame($code, TestSendRun::resumeFor(self::CAMPAIGN_ITEM, $uid));

        self::assertSame($before, self::stored($id));
    }

    /** @return array<string, array{\Closure(string): void, string, string}> */
    public static function notResumed(): array
    {
        $error = static function (string $id): void {
            self::storedSet($id, 'state', 'error');
        };
        return [
            'its step still working' => [static function (string $id): void {
                self::storedSet($id, 'lease', (string) (self::NOW + 100));
                self::storedSet($id, 'lease_tok', 'working-step');
            }, Sending::USER, 'LEASE'],
            'done'                   => [static function (string $id): void {
                self::storedSet($id, 'state', 'done');
            }, Sending::USER, 'WRONG_STATE'],
            'its step due now'       => [static function (string $id): void {}, Sending::USER, 'WRONG_STATE'],
            'a stranger'             => [$error, Sending::READER, 'FORBIDDEN'],
            'the campaign locked'    => [static function (string $id) use ($error): void {
                $error($id);
                TestSendRun::$lockedByOthers = [self::CAMPAIGN_ITEM];
            }, Sending::USER, SendRun::BUSY],
        ];
    }

    // ------------------------------------------------------------ following delivery

    // every message went: a stop has nothing to stop, whatever stop_by and the cursor say - following ends by itself
    /** @param array<string, string> $props */
    #[DataProvider('followedRuns')]
    public function test_stop_in_watch_all_sent(array $props): void
    {
        $id     = self::followed($props, ['n' => 2, 'p' => 'watch', 'u' => self::NOW + 1800]);
        $before = self::stored($id);
        $queue  = self::queue();

        self::assertSame(SendRun::ALL_SENT, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, false));

        self::assertSame([$before, $queue], [self::stored($id), self::queue()], 'nothing written, the next round as it was');
        self::assertSame([], self::items()->writes);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function followedRuns(): array
    {
        return [
            'stopped while sending'   => [['stop_by' => Sending::USER]],
            // stopped before message 1 went: the cursor stays below the last recipient
            'not every message tried' => [['dm_ids' => '{"0":"1001"}', 'cursor' => '1', 'stop_by' => Sending::USER]],
        ];
    }

    // a run that cannot follow delivery without a person is given up; the campaign keeps the end of the sending
    public function test_abandon_of_watch_in_error(): void
    {
        $id = self::followed();
        self::leftInError($id, SendRun::ACCOUNT_CHANGED);

        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
        self::assertSame('closed', self::runQueued());

        self::assertSame(['stopped', ''], [self::stored($id)['state'], self::stored($id)['stop_by'] ?? '']);
        self::assertSame([Schema::CAMPAIGN_STATE_DONE, (string) (self::NOW - 3600)], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT)]);
        self::assertSame([Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT], self::states($id));
    }

    // given up: the items show the delivery the record kept, the record of the ended run stays as it was;
    // a campaign an older tree put into error shows the end of the sending again, at the time it ended
    public function test_afterClose_writes_dirty_items_record_untouched(): void
    {
        $delivered = self::NOW - 5000;
        $id        = self::followed(['dm_state' => "{\"0\":\"4:$delivered:$delivered:0\"}", 'dm_dirty' => '{"0":1}']);
        self::storedSet($id, 'state', 'error');
        TableDb::$tables['toexecute'] = [];
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE]      = Schema::CAMPAIGN_STATE_ERROR;
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_ERROR_TEXT] = 'INTERNAL';
        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
        $record = array_intersect_key(self::stored($id), array_flip(['dm_state', 'dm_dirty', 'watch_last', 'watch_from', 'camp_state']));

        self::assertSame('closed', self::runQueued());

        $msg = self::message($id, 0);
        self::assertSame(['4', (string) $delivered], [self::items()->field($msg, Schema::MESSAGE_DM_STATUS), self::items()->field($msg, Schema::MESSAGE_DELIVERED_AT)]);
        self::assertSame('', self::items()->field(self::message($id, 1), Schema::MESSAGE_DM_STATUS), 'nothing known of the other');
        self::assertSame($record, array_intersect_key(self::stored($id), $record));
        self::assertSame([Schema::CAMPAIGN_STATE_DONE, '', (string) (self::NOW - 3600)], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_FINISHED_AT)]);
    }

    // a map edited into nested JSON: its items are not written, every other write of the end still is
    #[DataProvider('nestedMaps')]
    public function test_afterClose_with_broken_map_writes_the_rest(string $map): void
    {
        $id = self::followed([$map => '{"0":{"s":4}}', 'uncertain' => '{"1":' . (self::NOW - 9000) . '}', 'dm_ids' => '{"0":"1001"}']);
        self::storedSet($id, 'state', 'error');
        TableDb::$tables['toexecute'] = [];
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = Schema::CAMPAIGN_STATE_ERROR;
        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));

        self::assertSame('closed', self::runQueued());

        self::assertSame([Schema::CAMPAIGN_STATE_DONE, Schema::MESSAGE_STATE_UNCERTAIN], [self::campaign(), self::state($id, 1)]);
        self::assertSame('after_close', json_decode(self::stored($id)['progress'], true)['warning'] ?? '');
    }

    /** @return array<string, array{string}> */
    public static function nestedMaps(): array
    {
        return ['dm_state' => ['dm_state'], 'dm_dirty' => ['dm_dirty']];
    }

    // Continue runs the round now: no wait, the full span of calls, the list of sent messages from its start
    public function test_resume_in_watch_runs_round_now(): void
    {
        $id = self::followed([], ['l' => ['o' => '981'], 'n' => 3, 'p' => 'watch', 'u' => self::NOW + 1800, 'z' => 3600]);
        self::leftInError($id, SendRun::ACCOUNT_CHANGED);
        $isds = new ScriptedIsds(['GetMessageStateChanges' => [new Response(200, self::fixture('state-changes.xml'))]]);
        TestSendRun::$http = $isds;

        self::assertSame('', TestSendRun::resumeFor(self::CAMPAIGN_ITEM, Sending::USER));

        self::assertSame([['n' => 3, 'p' => 'watch'], 'sending', (string) self::NOW], [self::position($id), self::stored($id)['state'], self::stored($id)['next_at']]);
        self::assertStringStartsWith('step ', self::runQueued());
        self::assertSame(['GetMessageStateChanges'], $isds->ops());
    }

    // ------------------------------------------------------------ the run and its items

    /**
     * A run that sent its two messages as 1001 and 1002 two hours ago and follows their delivery since
     * an hour ago: the items sent, the campaign done then, ISDS asked whose the login is now
     * @param array<string, string>     $props
     * @param array<string, int|string> $position
     */
    private static function followed(array $props = [], array $position = self::WATCH): string
    {
        $id = self::queued(2, $props + self::watched(2) + [
            'camp_state' => Schema::CAMPAIGN_STATE_DONE,
            'watch_last' => (string) (self::NOW - 7200),
        ], $position);
        foreach (self::rows($id) as $k => $row) {
            self::items()->mark($row['msg'], Schema::MESSAGE_STATE_SENT, Schema::EVENT_SUBMITTED, self::NOW - 7200, (string) (1001 + $k), senderRef: self::SHORT . '-' . ($k + 1));
        }
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE]       = Schema::CAMPAIGN_STATE_DONE;
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_FINISHED_AT] = (string) (self::NOW - 3600);
        self::items()->writes = [];
        return $id;
    }

    /**
     * A run given up after its stop failed: message 1 sent, 2 in flight when its step died, 3 never
     * tried - its close row queued
     */
    private static function abandoned(): string
    {
        $id = self::queued(4, ['dm_ids' => '{"0":"901","1":"902"}', 'inflight' => '2', 'inflight_at' => (string) (self::NOW - 50), 'cursor' => '2', 'stop_by' => Sending::USER], ['i' => 2, 'p' => 'send']);
        self::leftInError($id, 'STEP_DIED');
        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
        return $id;
    }

    // ------------------------------------------------------------ what ISDS answers

    /** the queued steps run, the stale rows of steps gone by skipped, till the run is done
     *  @return string what the last step said */
    private static function finish(): string
    {
        $said = '';
        for ($rows = 0; ($said !== 'done') && self::queue() && ($rows < 10); ++$rows) {
            $said = self::runQueued();
        }
        return $said;
    }

    /** the text of $element in the last request to ISDS */
    private static function element(ScriptedIsds $isds, string $element): string
    {
        $body = (string) (array_slice($isds->calls, -1)[0]['body'] ?? '');
        return (preg_match("~<\\w+:$element>([^<]*)<~", $body, $m) === 1) ? $m[1] : '';
    }
}
