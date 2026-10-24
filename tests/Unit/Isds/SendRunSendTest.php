<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Http\Response;
use AA\Http\TransportException as HttpTransportException;
use AA\IO\DB\DB_AA;
use AA\Isds\Client;
use AA\Isds\IsdsException;
use AA\Isds\Outcome;
use AA\Isds\Schema;
use AA\Isds\SendPace;
use AA\Isds\SendRun;
use AA\Isds\Snapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Isds\Fixtures\ScriptedIsds;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;

require_once __DIR__ . '/fixtures/SendRunCase.php';

/**
 * The run of a mass sending sending its messages: one at a time, what may be on its way recorded
 * before it leaves, never twice - over TableDb's object_text and toexecute, items in memory, ISDS
 * answering each operation from a script.
 */
final class SendRunSendTest extends TestCase
{
    use SendRunCase;

    /** an attachment of a megabyte, what a step needs memory for */
    private const PDF = '%PDF';
    private const MB  = 1024 * 1024;
    /** a memory_limit no test comes near */
    private const HIGH = '4G';

    /** a part of the run's snapshot as another process damaged it */
    private static function damage(string $id, string $part): void
    {
        self::storedSet(self::stored($id)['snapshot'], $part, base64_encode('[]'));
    }

    /** SELECTs of the files of a snapshot since the log was emptied */
    private static function filesRead(): int
    {
        return count(array_filter(TableDb::$log, static fn(string $sql): bool => str_starts_with($sql, 'SELECT') && str_contains($sql, "'files'")));
    }

    // ------------------------------------------------------------ what ISDS answers

    /** CheckDataBox: ISDS refused it, or has no such box */
    private static function boxRefused(string $status): Response
    {
        return new Response(200, (string) preg_replace('~<p:dbState>1</p:dbState>(.*)0000~s', '${1}' . $status, self::fixture('check-data-box.xml')));
    }

    private static function refused(string $status = '1233'): Response
    {
        return new Response(200, str_replace('>1233<', ">$status<", self::fixture('create-message-1233.xml')));
    }

    /** HTTP 401 with the page of $fixture */
    private static function login(string $fixture = 'unauthorized.html'): Response
    {
        return new Response(401, self::fixture($fixture));
    }

    /** an answer that does not say what ISDS did */
    private static function unreadable(): Response
    {
        return new Response(500, 'Internal Server Error');
    }

    /** the request may have left, and no answer came */
    private static function noAnswer(): HttpTransportException
    {
        return new HttpTransportException('Operation timed out after 300000 milliseconds');
    }

    // ------------------------------------------------------------ one message at a time

    public function test_work_two_messages_in_one_step_keep_both_dm_ids(): void
    {
        $id   = self::queued(2);
        $isds = self::isds();

        self::assertSame('step 1', self::runQueued());

        self::assertSame([self::box(0), self::box(1)], $isds->sent());
        self::assertSame(['4711-1', '4711-2'], $isds->sent('dmSenderRefNumber'), 'the campaign and the order of the recipient');
        self::assertSame(['CheckDataBox', 'CreateMessage', 'CheckDataBox', 'CreateMessage'], $isds->ops(), 'each box checked right before its message');
        self::assertSame([0 => '1001', 1 => '1002'], self::map($id, 'dm_ids'));
        $stored = self::stored($id);
        self::assertSame(['2', '', ''], [$stored['cursor'], $stored['inflight'], $stored['inflight_at']]);
        foreach ([0 => '1001', 1 => '1002'] as $i => $dmId) {
            $msg = self::message($id, $i);
            self::assertSame([Schema::MESSAGE_STATE_SENT, $dmId, '4711-' . ($i + 1), (string) self::NOW], [self::state($id, $i), self::items()->field($msg, Schema::MESSAGE_DM_ID), self::items()->field($msg, Schema::MESSAGE_SENDER_REF), self::items()->field($msg, Schema::MESSAGE_SENT_AT)]);
            self::assertSame([Schema::EVENT_QUEUED, Schema::EVENT_SENDING, Schema::EVENT_SUBMITTED], self::kinds($id, $i));
        }
        self::assertSame(self::LOOK, self::position($id), 'every message tried');
        self::assertSame(1, TestSendRun::$invalidated);
    }

    // the item shows sending - and the record says the message may be on its way - before it leaves
    public function test_work_records_inflight_before_the_request(): void
    {
        $id   = self::queued(1);
        $seen = [];
        self::isds([static function () use ($id, &$seen): Response {
            $seen = [self::stored($id)['inflight'], self::stored($id)['inflight_at'], self::state($id, 0)];
            return self::created('1001');
        }]);

        self::runQueued();

        self::assertSame(['0', (string) self::NOW, Schema::MESSAGE_STATE_SENDING], $seen);
    }

    public function test_work_moves_to_look_when_all_tried(): void
    {
        $id   = self::queued(4, reasons: [2 => Schema::BOX_MISSING, 3 => Schema::BOX_DUPLICATE]);
        $isds = self::isds();

        self::assertSame('step 1', self::runQueued());

        self::assertSame([self::box(0), self::box(1)], $isds->sent());
        self::assertSame(['4', self::LOOK], [self::stored($id)['cursor'], self::position($id)], 'the last ones skipped by their reason too');

        // nothing goes out from here
        self::assertSame('watch', self::lookOver($id));
        self::assertCount(2, $isds->sent());
    }

    // ------------------------------------------------------------ never twice

    // a dmID edited into null by hand is still a message sent: never sent again
    public function test_work_never_sends_message_whose_dm_id_was_edited_to_null(): void
    {
        self::queued(2, ['dm_ids' => '{"0":null}'], self::SEND);
        $isds = self::isds();

        self::runQueued();

        self::assertNotContains(self::box(0), $isds->sent());
    }

    public function test_work_never_sends_twice_after_dying_mid_request(): void
    {
        // message 0 went before; the position is at message 1, which the step now sends
        $id   = self::queued(3, ['cursor' => '1', 'dm_ids' => '{"0":"999"}'], ['p' => 'send', 'i' => 1]);
        $died = null;
        self::isds([static function () use (&$died): Response {
            // ISDS takes the message, and the process dies before it hears so
            $died ??= [TableDb::$tables, self::items()->stored];
            return self::created('1002');
        }]);
        self::runQueued();
        [TableDb::$tables, self::items()->stored] = $died ?? throw new \LogicException('Not sent');
        self::assertSame(['1', '1', ['i' => 1, 'p' => 'send']], [self::stored($id)['inflight'], self::stored($id)['cursor'], self::position($id)], 'as the step died');
        TestSendRun::$now = (int) self::stored($id)['lease'] + 1;
        $isds             = self::isds();

        self::assertSame('step 1', self::runQueued(), 'the queue repeats the step');

        self::assertSame([self::box(2)], $isds->sent(), 'message 1 never again');
        self::assertSame([1 => self::NOW], self::map($id, 'uncertain'));
        self::assertSame([0 => '999', 2 => '1001'], self::map($id, 'dm_ids'));
        self::assertSame([Schema::MESSAGE_STATE_UNCERTAIN, Schema::MESSAGE_STATE_SENT], [self::state($id, 1), self::state($id, 2)]);
        self::assertSame(['3', ''], [self::stored($id)['cursor'], self::stored($id)['inflight']]);
    }

    // after a pause, after Continue, after its item was put back in the queue by hand
    #[DataProvider('afterUncertain')]
    public function test_work_never_resends_uncertain(bool $resumed, bool $requeued): void
    {
        $id   = self::queued(3);
        $isds = self::isds([self::created('1001'), self::noAnswer(), self::created('1003')]);
        self::runQueued();
        self::assertSame([1 => self::NOW], self::map($id, 'uncertain'));
        if ($resumed) {
            DB_AA::transaction(static fn() => TestSendRun::lockForUpdate($id)?->resume(Sending::USER));
        }
        if ($requeued) {
            self::items()->stored[self::message($id, 1)]['fields'][Schema::MESSAGE_STATE] = Schema::MESSAGE_STATE_QUEUED;
        }
        TestSendRun::$now = self::NOW + 30;

        self::runQueued();

        self::assertSame([self::box(0), self::box(1), self::box(2)], $isds->sent());
        self::assertSame([0 => '1001', 2 => '1003'], self::map($id, 'dm_ids'));
        self::assertSame(self::LOOK, self::position($id));
    }

    /** @return array<string, array{bool, bool}> */
    public static function afterUncertain(): array
    {
        return [
            'the pause over'            => [false, false],
            'Continue'                  => [true, false],
            'the item queued by hand'   => [false, true],
        ];
    }

    #[DataProvider('noAnswers')]
    public function test_work_marks_no_answer_uncertain_and_pauses(\Closure $answer): void
    {
        $id = self::queued(3);
        self::isds([self::created('1001'), $answer()]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame([[0 => '1001'], [1 => self::NOW]], [self::map($id, 'dm_ids'), self::map($id, 'uncertain')]);
        self::assertSame([Schema::MESSAGE_STATE_UNCERTAIN, [Schema::EVENT_QUEUED, Schema::EVENT_SENDING, Schema::EVENT_TIMEOUT]], [self::state($id, 1), self::kinds($id, 1)]);
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, 2), 'not tried while ISDS may be down');
        self::assertSame(['i' => 2, 'p' => 'send', 's' => self::NOW, 'u' => self::NOW + 30, 'w' => 1], self::position($id), 'past it, waiting');
        $stored = self::stored($id);
        self::assertSame(['2', '', 'ISDS_UNAVAILABLE', (string) (self::NOW + 30), '0'], [$stored['cursor'], $stored['inflight'], $stored['retry_code'], $stored['next_at'], $stored['failures']]);
    }

    /** @return array<string, array{\Closure(): (Response|\Throwable)}> */
    public static function noAnswers(): array
    {
        return [
            'no answer'               => [static fn() => self::noAnswer()],
            'come back later (503)'   => [static fn() => new Response(503, 'Service Unavailable')],
        ];
    }

    // a message that never left goes out after the pause, the same one
    public function test_work_sends_again_what_never_left(): void
    {
        $id   = self::queued(2);
        $isds = self::isds([new HttpTransportException('No connection', 0, new \RuntimeException('Could not resolve host: ws1.czebox.cz')), self::created('1001'), self::created('1002')]);

        self::runQueued();

        self::assertSame(['0', '', []], [self::stored($id)['cursor'], self::stored($id)['inflight'], self::map($id, 'uncertain')]);
        self::assertSame([Schema::MESSAGE_STATE_QUEUED, [Schema::EVENT_QUEUED, Schema::EVENT_SENDING, Schema::EVENT_QUEUED]], [self::state($id, 0), self::kinds($id, 0)]);
        self::assertSame(['i' => 0, 'p' => 'send', 's' => self::NOW, 'u' => self::NOW + 30, 'w' => 1], self::position($id));

        TestSendRun::$now = self::NOW + 30;
        self::runQueued();

        self::assertSame([self::box(0), self::box(0), self::box(1)], $isds->sent());
        self::assertSame([0 => '1001', 1 => '1002'], self::map($id, 'dm_ids'));
        self::assertSame(self::LOOK, self::position($id), 'the pause forgotten once ISDS answers');
    }

    public function test_work_sent_message_ends_the_pause(): void
    {
        $id = self::queued(3);
        self::isds([self::noAnswer(), self::created('1002'), self::noAnswer()]);
        self::runQueued();
        self::runQueued();
        TestSendRun::$now = self::NOW + 30;

        self::runQueued();

        self::assertSame(['i' => 3, 'p' => 'send', 's' => self::NOW + 30, 'u' => self::NOW + 60, 'w' => 1], self::position($id));
    }

    // Continue or the end between the check of the box and the record of the request
    public function test_work_sends_nothing_once_the_run_is_taken(): void
    {
        $id   = self::queued(2);
        $isds = self::isds(check: [static function () use ($id): Response {
            self::storedSet($id, 'state', 'error');
            return self::boxIs(1);
        }]);

        self::runQueued();

        self::assertSame(['CheckDataBox'], $isds->ops());
        self::assertSame(['', '0'], [self::stored($id)['inflight'], self::stored($id)['cursor']]);
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, 0));
    }

    // the fate recorded meanwhile by another writer, or a cursor lowered by hand under it
    #[DataProvider('fates')]
    public function test_work_sends_nothing_the_record_has_the_fate_of(string $property, string $value): void
    {
        $id   = self::queued(2);
        $isds = self::isds(check: [static function () use ($id, $property, $value): Response {
            self::storedSet($id, $property, $value);
            return self::boxIs(1);
        }]);

        self::runQueued();

        self::assertSame(['CheckDataBox'], $isds->ops());
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, 0));
    }

    /** @return array<string, array{string, string}> */
    public static function fates(): array
    {
        return [
            'a cursor past it'  => ['cursor', '1'],
            'sent'              => ['dm_ids', '{"0":"999"}'],
            'of unknown fate'   => ['uncertain', '{"0":5}'],
            'refused'           => ['failed', '{"0":"1233"}'],
            'its box inactive'  => ['skips', '{"0":"inactive"}'],
            'a map no JSON'     => ['failed', '{broken'],
            'one on its way'    => ['inflight', '1'],
        ];
    }

    // the lease would end before what came of the request is recorded: another step could claim the run meanwhile
    public function test_work_sends_nothing_without_lease_for_the_request(): void
    {
        $id   = self::queued(2);
        $isds = self::isds(check: [static function () use ($id): Response {
            self::storedSet($id, 'lease', (string) ((int) TestSendRun::$now + Client::CREATE_TIMEOUT + 1));
            return self::boxIs(1);
        }]);

        self::runQueued();

        self::assertSame(['CheckDataBox'], $isds->ops());
        self::assertSame(['', '0', Schema::MESSAGE_STATE_QUEUED], [self::stored($id)['inflight'], self::stored($id)['cursor'], self::state($id, 0)]);
    }

    // the last message a step starts, with MIN_SEND left and a check of its box that takes all its time, still has the lease for its request
    public function test_work_last_message_of_a_step_has_lease_for_its_request(): void
    {
        self::queued(2);
        TestSendRun::$microNow = (float) self::NOW;
        $checks                = 0;
        $isds                  = self::isds([static function (): Response {
            TestSendRun::$microNow += 24.0;
            TestSendRun::$now      += 24;
            return self::created((string) TestSendRun::$now);
        }], [static function () use (&$checks): Response {
            if ($checks++ === 1) {
                TestSendRun::$now += Client::TIMEOUT;
            }
            return self::boxIs(1);
        }]);

        self::runQueued();

        self::assertCount(2, $isds->sent());
    }

    // the run ended or failed while ISDS answered: what came of it is shown all the same, nothing more goes
    #[DataProvider('endedMeanwhile')]
    public function test_work_shows_what_came_when_the_run_ended_meanwhile(string $op, Response $answer, string $state): void
    {
        $id     = self::queued(2);
        $ending = static function () use ($id, $answer): Response {
            self::storedSet($id, 'state', 'error');
            return $answer;
        };
        $isds   = ($op === 'CheckDataBox') ? self::isds(check: [$ending]) : self::isds([$ending]);

        self::runQueued();

        self::assertSame([$state, Schema::MESSAGE_STATE_QUEUED], [self::state($id, 0), self::state($id, 1)]);
        self::assertSame(($op === 'CheckDataBox') ? ['CheckDataBox'] : ['CheckDataBox', 'CreateMessage'], $isds->ops());
        self::assertSame('1', self::stored($id)['cursor']);
    }

    /** @return array<string, array{string, Response, string}> */
    public static function endedMeanwhile(): array
    {
        return [
            'its message sent'  => ['CreateMessage', self::created('1001'), Schema::MESSAGE_STATE_SENT],
            'its box inactive'  => ['CheckDataBox', self::boxIs(4), Schema::MESSAGE_STATE_SKIPPED],
        ];
    }

    // another step took the run while the request was on its way: it settles the message, this one writes nothing more
    public function test_work_leaves_inflight_when_taken_while_sending(): void
    {
        $id = self::queued(2);
        self::isds([static function () use ($id): Response {
            self::storedSet($id, 'lease_tok', 'another-step');
            return self::created('1001');
        }]);

        self::runQueued();

        self::assertSame(['0', '0', []], [self::stored($id)['inflight'], self::stored($id)['cursor'], self::map($id, 'dm_ids')]);
        self::assertSame(Schema::MESSAGE_STATE_SENDING, self::state($id, 0));
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, 1));
    }

    // nothing left: the next step must not take the message for one of unknown fate
    public function test_work_sends_nothing_when_item_cannot_be_marked(): void
    {
        $id                    = self::queued(2);
        $isds                  = self::isds();
        self::items()->onWrite = static fn() => throw new \RuntimeException('not stored');

        self::assertSame('error INTERNAL', self::runQueued());

        self::assertSame(['CheckDataBox'], $isds->ops());
        self::assertSame(['', '', '0', []], [self::stored($id)['inflight'], self::stored($id)['inflight_at'], self::stored($id)['cursor'], self::map($id, 'uncertain')]);
    }

    // the record holds the dmID even when the item cannot show it
    public function test_work_records_result_before_the_item(): void
    {
        $id = self::queued(2);
        self::isds([static function (): Response {
            self::items()->onWrite = static fn() => throw new \RuntimeException('not stored');
            return self::created('1001');
        }]);

        self::assertSame('error INTERNAL', self::runQueued());

        self::assertSame([[0 => '1001'], '1', ''], [self::map($id, 'dm_ids'), self::stored($id)['cursor'], self::stored($id)['inflight']]);
    }

    // whatever AA does not know may come after the request left
    public function test_work_unknown_failure_is_uncertain(): void
    {
        $id = self::queued(2);
        self::isds([static function (): never {
            TestSendRun::$now += 300;
            throw new \Error('Something AA does not know');
        }]);

        self::assertSame('error INTERNAL', self::runQueued());

        self::assertSame([[0 => self::NOW], '1', ''], [self::map($id, 'uncertain'), self::stored($id)['cursor'], self::stored($id)['inflight']]);
        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::state($id, 0));
        self::assertStringNotContainsString('AA does not know', json_encode(self::stored($id), JSON_THROW_ON_ERROR));
    }

    // ------------------------------------------------------------ boxes

    #[DataProvider('inactive')]
    public function test_work_skips_inactive_box(Response $check): void
    {
        $id   = self::queued(3);
        $isds = self::isds(check: [self::boxIs(1), $check, self::boxIs(1)]);

        self::runQueued();

        self::assertSame([self::box(0), self::box(2)], $isds->sent());
        self::assertSame([1 => Schema::BOX_INACTIVE], self::map($id, 'skips'));
        $msg = self::message($id, 1);
        self::assertSame([Schema::MESSAGE_STATE_SKIPPED, Schema::BOX_INACTIVE], [self::state($id, 1), self::items()->field($msg, Schema::MESSAGE_ERROR_CODE)]);
        self::assertSame([0 => '1001', 2 => '1002'], self::map($id, 'dm_ids'));
        self::assertSame('3', self::stored($id)['cursor']);
    }

    /** @return array<string, array{Response}> */
    public static function inactive(): array
    {
        return [
            'made inaccessible for a while' => [self::boxIs(2)],
            'not yet accessible'            => [self::boxIs(3)],
            'inaccessible for good'         => [self::boxIs(4)],
            'deleted'                       => [self::boxIs(5)],
            'no such box'                   => [self::boxRefused('5001')],
        ];
    }

    // decided for good: after a pause the run goes on past it
    public function test_work_inactive_box_moves_the_cursor(): void
    {
        $id = self::queued(3);
        self::isds(check: [self::boxIs(1), self::boxIs(4), new Response(503, '')]);

        self::runQueued();

        self::assertSame('2', self::stored($id)['cursor']);
        self::assertSame(['i' => 2, 'p' => 'send', 's' => self::NOW, 'u' => self::NOW + 30, 'w' => 1], self::position($id));
    }

    public function test_work_skips_frozen_reasons_without_calls(): void
    {
        $id   = self::queued(4, reasons: [0 => Schema::BOX_FORMAT, 2 => Schema::BOX_MISSING]);
        $isds = self::isds();

        self::runQueued();

        self::assertSame([self::box(1), self::box(3)], $isds->sent());
        self::assertSame(['CheckDataBox', 'CreateMessage', 'CheckDataBox', 'CreateMessage'], $isds->ops());
        $skipped = [self::message($id, 0), self::message($id, 2)];
        self::assertSame([], array_intersect($skipped, array_column(self::items()->writes, 'id')), 'their items as prep made them');
        self::assertSame([Schema::MESSAGE_STATE_SKIPPED, Schema::MESSAGE_STATE_SKIPPED], [self::state($id, 0), self::state($id, 2)]);
    }

    // ISDS refused the check (1007, 2011: an id of a form it does not take) or did not say the state: the message gets an answer of its own
    #[DataProvider('unchecked')]
    public function test_work_sends_without_check_when_refused(Response $check): void
    {
        $id   = self::queued(2);
        $isds = self::isds(check: [$check]);

        self::runQueued();

        self::assertSame([self::box(0), self::box(1)], $isds->sent());
        self::assertSame([SendRun::UNCHECKED => 2], json_decode(self::stored($id)['warnings'], true));
        self::assertSame([], self::map($id, 'skips'));
    }

    /** @return array<string, array{Response}> */
    public static function unchecked(): array
    {
        return [
            'refused, 1007'      => [self::boxRefused('1007')],
            'refused, 2011'      => [self::boxRefused('2011')],
            'an error of ISDS'   => [self::boxIs(0)],
            'no state'           => [new Response(200, str_replace('<p:dbState>1</p:dbState>', '', self::fixture('check-data-box.xml')))],
            'a state unknown'    => [self::boxIs(6)],
        ];
    }

    // as Outcome::of(): a failure AA does not know is no refusal
    public function test_work_stops_on_unknown_failure_of_box_check(): void
    {
        $id   = self::queued(2);
        $isds = self::isds(check: [new class ('Something AA does not know') extends IsdsException {}]);

        self::assertSame('error INTERNAL', self::runQueued());

        self::assertSame(['CheckDataBox'], $isds->ops());
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, 0));
    }

    #[DataProvider('checkPauses')]
    public function test_work_pauses_when_box_check_gets_no_answer(Response|\Throwable $check): void
    {
        $id   = self::queued(2);
        $isds = self::isds(check: [$check]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame(['CheckDataBox'], $isds->ops());
        self::assertSame(['i' => 0, 'p' => 'send', 's' => self::NOW, 'u' => self::NOW + 30, 'w' => 1], self::position($id), 'the same message after the pause');
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, 0));
    }

    /** @return array<string, array{Response|\Throwable}> */
    public static function checkPauses(): array
    {
        return ['no answer' => [self::noAnswer()], 'come back later' => [new Response(503, '')], 'unreadable' => [self::unreadable()]];
    }

    // ------------------------------------------------------------ the snapshot, not the items

    public function test_work_sends_from_snapshot_when_items_changed(): void
    {
        $id = self::queued(3);
        foreach ([0, 1] as $i) {
            self::items()->stored[self::message($id, $i)]['fields'][Schema::MESSAGE_BOX] = 'zz99zzz';
        }
        self::items()->stored[self::message($id, 2)]['fields'][Schema::MESSAGE_STATE] = Schema::MESSAGE_STATE_SENT;
        unset(self::items()->stored[self::message($id, 1)]);
        $isds = self::isds();

        self::runQueued();

        self::assertSame([self::box(0), self::box(1), self::box(2)], $isds->sent());
        self::assertSame([0 => '1001', 1 => '1002', 2 => '1003'], self::map($id, 'dm_ids'), 'the record keeps what an item deleted by hand cannot');
        self::assertArrayNotHasKey(self::message($id, 1), self::items()->stored, 'not made again');
    }

    public function test_work_ignores_item_reset_to_queued(): void
    {
        $id                    = self::queued(3);
        TestSendRun::$microNow = (float) self::NOW;
        $isds                  = self::isds([static function (): Response {
            // the step has time for one message
            TestSendRun::$microNow += 25.0;
            return self::created('1001');
        }, self::created('1002'), self::created('1003')]);
        self::runQueued();
        self::assertSame(['i' => 1, 'p' => 'send'], self::position($id));
        $msg                                                          = self::message($id, 0);
        self::items()->stored[$msg]['fields'][Schema::MESSAGE_STATE] = Schema::MESSAGE_STATE_QUEUED;
        self::items()->stored[$msg]['fields'][Schema::MESSAGE_DM_ID] = '';

        self::runQueued();

        self::assertSame([self::box(0), self::box(1), self::box(2)], $isds->sent());
        self::assertSame([0 => '1001', 1 => '1002', 2 => '1003'], self::map($id, 'dm_ids'));
    }

    // ------------------------------------------------------------ pauses

    public function test_retryDelay_backs_off_to_10_min(): void
    {
        $id = self::queued(2);
        self::isds(check: [new Response(503, '')]);
        $waits = [];
        for ($step = 0; $step < 7; ++$step) {
            self::runQueued();
            $waits[]          = (int) self::stored($id)['next_at'] - (int) TestSendRun::$now;
            TestSendRun::$now = (int) self::stored($id)['next_at'];
        }

        self::assertSame([30, 60, 120, 240, 480, 600, 600], $waits);
        self::assertSame(['0', 'sending'], [self::stored($id)['failures'], self::stored($id)['state']], 'no retry spent');
    }

    public function test_positionOnResume_resets_pause(): void
    {
        $id = self::queued(3);
        self::isds([self::created('1001'), self::noAnswer()], [self::boxIs(1), self::boxIs(1), new Response(503, '')]);
        self::runQueued();
        TestSendRun::$now = self::NOW + 30;
        self::runQueued();
        self::assertSame(['i' => 2, 'p' => 'send', 's' => self::NOW, 'u' => self::NOW + 90, 'w' => 2], self::position($id));
        self::runQueued();
        self::assertSame(1, self::position($id)['e'], 'a step before the end of the pause');

        DB_AA::transaction(static fn() => TestSendRun::lockForUpdate($id)?->resume(Sending::USER));
        self::assertSame(['i' => 2, 'p' => 'send'], self::position($id));
        self::runQueued();

        self::assertSame(['i' => 2, 'p' => 'send', 's' => self::NOW + 30, 'u' => self::NOW + 60, 'w' => 1], self::position($id), 'a pause of the full time again');
    }

    // Execute in the queue manager runs a step before its pause is over: it asks ISDS nothing and spends no retry
    #[DataProvider('pausedBy')]
    public function test_work_early_step_waits_out_the_pause(string $start, array $check, string $code): void
    {
        TestSendRun::$now = self::prague($start);
        $id               = self::queued(2);
        $isds             = self::isds(check: $check);
        self::runQueued();
        $until = (int) self::position($id)['u'];
        $asked = $isds->ops();

        foreach (['step 2', 'step 3', 'step 4'] as $step) {
            self::assertSame($step, self::runQueued());
        }

        self::assertSame($asked, $isds->ops());
        $stored = self::stored($id);
        self::assertSame(['sending', '0', $code, (string) $until, 3], [$stored['state'], $stored['failures'], $stored['retry_code'], $stored['next_at'], self::position($id)['e']]);

        TestSendRun::$now = $until;
        self::runQueued();

        self::assertCount(2, self::map($id, 'dm_ids'));
    }

    // a pause ends at most the hour of maintenance and its lead ahead: one further - a record edited by
    // hand, a clock set back - would hold the run for ever without a word
    #[DataProvider('pauseEnds')]
    public function test_work_waits_no_longer_than_any_pause(int $ahead, int $sent): void
    {
        $id   = self::queued(2, position: ['i' => 0, 'p' => 'send', 's' => self::NOW, 'u' => self::NOW + $ahead, 'w' => 1]);
        $isds = self::isds();

        self::runQueued();

        self::assertCount($sent, $isds->sent());
    }

    /** @return array<string, array{int, int}> */
    public static function pauseEnds(): array
    {
        return [
            'as far as the maintenance' => [3600 + SendPace::MAINTENANCE_LEAD, 0],
            'further'                   => [3600 + SendPace::MAINTENANCE_LEAD + 1, 2],
        ];
    }

    /** @return array<string, array{string, list<Response>, string}> on a Wednesday, on a Friday */
    public static function pausedBy(): array
    {
        return [
            'ISDS not answering' => ['2030-03-20 10:00:00', [new Response(503, ''), self::boxIs(1)], Outcome::UNAVAILABLE],
            'its maintenance'    => ['2030-03-22 00:30:00', [self::boxIs(1)], SendRun::MAINTENANCE],
        ];
    }

    public function test_work_waits_out_friday_maintenance(): void
    {
        TestSendRun::$now = self::prague('2030-03-22 00:30:00');
        // ISDS is not asked anything, not even whose the login is
        // a pause before it does not shorten the wait
        $id   = self::queued(2, ['owner_at' => ''], ['p' => 'send', 'i' => 0, 'u' => (int) TestSendRun::$now - 5]);
        $isds = TestSendRun::$http = new ScriptedIsds([
            'GetOwnerInfoFromLogin2' => [new Response(200, self::fixture('owner-info.xml'))],
            'CheckDataBox'           => [self::boxIs(1)],
            'CreateMessage'          => [self::created('1001'), self::created('1002')],
        ]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame([], $isds->ops());
        $end = self::prague('2030-03-22 01:00:00');
        self::assertSame(['i' => 0, 'p' => 'send', 'u' => $end], self::position($id));
        self::assertSame([SendRun::MAINTENANCE, (string) $end, '0'], [self::stored($id)['retry_code'], self::stored($id)['next_at'], self::stored($id)['failures']]);

        TestSendRun::$now = $end;
        self::runQueued();

        self::assertSame(['GetOwnerInfoFromLogin2', 'CheckDataBox', 'CreateMessage', 'CheckDataBox', 'CreateMessage'], $isds->ops());
        self::assertSame([0 => '1001', 1 => '1002'], self::map($id, 'dm_ids'));
    }

    // a message started in the last minutes before the maintenance may still be on its way in it
    #[DataProvider('beforeMidnight')]
    public function test_work_starts_no_send_before_midnight_friday(string $start, int $sent): void
    {
        TestSendRun::$now      = self::prague($start);
        // the step's budget on a clock of its own: its time is not what runs out here
        TestSendRun::$microNow = (float) TestSendRun::$now;
        $id                    = self::queued(3);
        $isds                  = self::isds([static function (): Response {
            TestSendRun::$now += 40;
            return self::created((string) TestSendRun::$now);
        }]);

        self::runQueued();

        self::assertSame(array_merge(...array_fill(0, $sent, ['CheckDataBox', 'CreateMessage'])), $isds->ops(), 'not even a box checked');
        self::assertSame(['i' => $sent, 'p' => 'send', 'u' => self::prague('2030-03-22 01:00:00')], self::position($id));
        self::assertSame(SendRun::MAINTENANCE, self::stored($id)['retry_code']);
    }

    /** @return array<string, array{string, int}> */
    public static function beforeMidnight(): array
    {
        return ['seven minutes before' => ['2030-03-21 23:52:50', 2], 'six minutes before' => ['2030-03-21 23:54:00', 0]];
    }

    private static function prague(string $time): int
    {
        return (new \DateTimeImmutable($time, new \DateTimeZone('Europe/Prague')))->getTimestamp();
    }

    // ------------------------------------------------------------ breakers

    #[DataProvider('refusalStreaks')]
    public function test_work_trips_breaker_on_same_refusal(string $streak, int $tried): void
    {
        $id   = self::queued(7, ['streak' => $streak]);
        $isds = self::isds([self::refused('1233')]);

        self::assertSame('error ' . SendRun::REFUSED, self::runQueued());

        self::assertCount($tried, $isds->sent());
        self::assertSame(['status:1233', SendRun::REFUSED], [self::stored($id)['error_detail'], self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
        self::assertSame(array_fill(0, $tried, '1233'), array_values(self::map($id, 'failed')));
        self::assertSame([Schema::MESSAGE_STATE_FAILED, '1233'], [self::state($id, $tried - 1), self::items()->field(self::message($id, $tried - 1), Schema::MESSAGE_ERROR_CODE)]);
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, $tried), 'nothing tried after it');
    }

    /** @return array<string, array{string, int}> */
    public static function refusalStreaks(): array
    {
        return ['five in a row' => ['', 5], 'four before this step' => ['4:F1233', 1]];
    }

    #[DataProvider('brokenStreaks')]
    public function test_work_keeps_sending_when_refusals_differ(array $codes): void
    {
        $id   = self::queued(7);
        $isds = self::isds(array_map(static fn(string $c): Response => ($c === 'ok') ? self::created('1001') : self::refused($c), $codes));

        self::assertSame('step 1', self::runQueued());

        self::assertCount(7, $isds->sent());
        self::assertSame(self::LOOK, self::position($id));
    }

    /** @return array<string, array{list<string>}> */
    public static function brokenStreaks(): array
    {
        return [
            'four, then another' => [['1233', '1233', '1233', '1233', '1234', '1233']],
            'four, then one sent' => [['1233', '1233', '1233', '1233', 'ok', '1233']],
        ];
    }


    public function test_work_pauses_after_two_unreadable(): void
    {
        $id   = self::queued(4);
        $isds = self::isds([self::unreadable(), self::unreadable(), self::created('1003'), self::created('1004')]);

        self::assertSame('step 1', self::runQueued());

        self::assertSame([self::box(0), self::box(1)], $isds->sent(), 'the first one does not stop it');
        self::assertSame([0 => self::NOW, 1 => self::NOW], self::map($id, 'uncertain'));
        self::assertSame(['i' => 2, 'p' => 'send', 's' => self::NOW, 'u' => self::NOW + 30, 'w' => 1], self::position($id));
        self::assertSame('2:U', self::stored($id)['streak']);

        TestSendRun::$now = self::NOW + 30;
        self::runQueued();

        self::assertSame([2 => '1003', 3 => '1004'], self::map($id, 'dm_ids'));
        self::assertSame(['', self::LOOK], [self::stored($id)['streak'], self::position($id)]);
    }

    // ------------------------------------------------------------ what stops the run

    #[DataProvider('loginRefusals')]
    public function test_work_stops_on_login_refusal(string $op, string $page, string $code): void
    {
        $id   = self::queued(2);
        $isds = ($op === 'CheckDataBox') ? self::isds(check: [self::login($page)]) : self::isds([self::login($page)]);

        self::assertSame("error $code", self::runQueued());

        self::assertSame(($op === 'CheckDataBox') ? ['CheckDataBox'] : ['CheckDataBox', 'CreateMessage'], $isds->ops(), 'nothing more asked');
        self::assertSame(['', '0', [], []], [self::stored($id)['inflight'], self::stored($id)['cursor'], self::map($id, 'uncertain'), self::map($id, 'failed')], 'to be sent after Continue');
        self::assertSame(Schema::MESSAGE_STATE_QUEUED, self::state($id, 0));
        self::assertSame([Schema::CAMPAIGN_STATE_ERROR, $code], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
    }

    // the run errs, the campaign must not go on showing it sending - by the code, never the text of the failure
    #[DataProvider('unknownFailures')]
    public function test_work_shows_any_failure_on_the_campaign(string $where): void
    {
        $id      = self::queued(2);
        $unknown = new class ('Something AA does not know') extends IsdsException {};
        match ($where) {
            'request' => self::isds([new \Error('Something AA does not know')]),
            'check'   => self::isds(check: [$unknown]),
            'record'  => self::isds(check: [static function () use ($id): Response {
                self::storedSet($id, 'failed', '{broken');
                return self::boxIs(1);
            }]),
        };

        self::assertSame('error INTERNAL', self::runQueued());

        self::assertSame([Schema::CAMPAIGN_STATE_ERROR, 'INTERNAL'], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
    }

    // a campaign item that cannot be written does not hide why the run stopped
    public function test_work_keeps_its_error_when_the_campaign_cannot_take_it(): void
    {
        self::queued(2);
        self::isds(check: [self::login('unauthorized.html')]);
        self::items()->onWrite = static function (): never {
            throw new \RuntimeException('The campaign slice is gone');
        };

        self::assertSame('error ISDS_LOGIN', self::runQueued());
    }

    // a step that died left a message in flight, and its record cannot take it: the campaign shows the error too
    public function test_work_shows_failure_settling_the_message_in_flight(): void
    {
        $id   = self::queued(2, ['inflight' => '0', 'inflight_at' => (string) self::NOW, 'uncertain' => '{broken']);
        $isds = self::isds();

        self::assertSame('error INTERNAL', self::runQueued());

        self::assertSame([Schema::CAMPAIGN_STATE_ERROR, 'INTERNAL'], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
        self::assertSame([[], '0'], [$isds->ops(), self::stored($id)['inflight']], 'nothing asked, the message still in flight for the next step');
    }

    /** @return array<string, array{string}> */
    public static function unknownFailures(): array
    {
        return ['the request' => ['request'], 'the check of a box' => ['check'], 'the record' => ['record']];
    }

    /** @return array<string, array{string, string, string}> */
    public static function loginRefusals(): array
    {
        return [
            'sending, login refused'   => ['CreateMessage', 'unauthorized.html', 'ISDS_LOGIN'],
            'sending, address blocked' => ['CreateMessage', 'address-blocked.html', 'ISDS_BLOCKED'],
            'checking the box'         => ['CheckDataBox', 'unauthorized.html', 'ISDS_LOGIN'],
        ];
    }

    public function test_work_stops_on_changed_content(): void
    {
        $id = self::queued(2);
        self::damage($id, 'files');
        $isds = self::isds();

        self::assertSame('error ' . Snapshot::CHANGED, self::runQueued());

        self::assertSame([], $isds->ops());
        self::assertSame([self::CAMPAIGN_ITEM], array_column(self::items()->writes, 'id'), 'the error of the campaign only');
    }

    // a heap of its own: in one many tests used, freed pages share chunks with live ones and give nothing back
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[DataProvider('memoryLimits')]
    public function test_work_raises_memory_limit_for_the_files(int $limit, bool $raised): void
    {
        self::queued(1, letter: self::letter(str_repeat(self::PDF, 250_000)));
        $isds                     = self::isds();
        TestSendRun::$memoryLimit = $limit;
        TestSendRun::$mayRaise    = true;
        TableDb::$log             = [];
        // what PHP holds counts, not only what it uses - but not what it keeps freed
        $held = self::fragmentedHeap();
        gc_mem_caches();
        $base = memory_get_usage(true);
        self::freeSomeMemory();

        self::assertSame('step 1', self::runQueued());
        unset($held);

        self::assertSame([1, 1], [count($isds->sent()), self::filesRead()]);
        self::assertSame($raised, !is_null(TestSendRun::$raisedTo));
        self::assertGreaterThanOrEqual($raised ? $base + self::need() - self::MB : 0, (int) TestSendRun::$raisedTo);
        self::assertLessThanOrEqual($raised ? $base + self::need() + 8 * self::MB : 0, (int) TestSendRun::$raisedTo);
        self::assertSame($raised ? (string) $limit : null, TestSendRun::$restoredTo, 'the next task of the process gets the limit it had');
    }

    /** @return array<string, array{int, bool}> */
    public static function memoryLimits(): array
    {
        return ['no limit' => [-1, false], 'enough' => [PHP_INT_MAX, false], 'too low' => [self::MB, true]];
    }

    public function test_work_refuses_without_memory(): void
    {
        $id                       = self::queued(1, letter: self::letter(str_repeat(self::PDF, 250_000)));
        $isds                     = self::isds();
        TestSendRun::$memoryLimit = self::MB;
        TableDb::$log             = [];

        self::assertSame('error ' . SendRun::MEMORY, self::runQueued());

        self::assertSame([[], 0, null], [$isds->ops(), self::filesRead(), TestSendRun::$restoredTo], 'the files only when they fit');
        self::assertMatchesRegularExpression('/^mb:\d+\z/', self::stored($id)['error_detail']);
        self::assertGreaterThanOrEqual(intdiv(self::need(), self::MB), (int) substr(self::stored($id)['error_detail'], 3));
    }

    public function test_work_gives_back_memory_limit_when_failing(): void
    {
        $id = self::queued(1, letter: self::letter(str_repeat(self::PDF, 250_000)));
        self::damage($id, 'files');
        self::isds();
        TestSendRun::$memoryLimit = self::MB;
        TestSendRun::$mayRaise    = true;

        self::assertSame('error ' . Snapshot::CHANGED, self::runQueued());

        self::assertSame((string) self::MB, TestSendRun::$restoredTo);
    }

    // invalidate() may fail too: the next task of the process gets its memory_limit all the same
    public function test_work_gives_back_memory_limit_when_invalidate_fails(): void
    {
        self::queued(1, letter: self::letter(str_repeat(self::PDF, 250_000)));
        self::isds();
        TestSendRun::$memoryLimit     = self::MB;
        TestSendRun::$mayRaise        = true;
        TestSendRun::$invalidateFails = new \RuntimeException('No pages');

        self::assertSame('error INTERNAL', self::runQueued());

        self::assertSame((string) self::MB, TestSendRun::$restoredTo);
        self::assertSame([Schema::CAMPAIGN_STATE_ERROR, 'INTERNAL'], [self::campaign(), self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT)]);
        $campaign = array_column(array_filter(self::items()->writes, static fn(array $write): bool => $write['id'] === strtolower(self::CAMPAIGN_ITEM)), 'open');
        self::assertSame([Sending::CAMPAIGN => true, Sending::MESSAGE => true], end($campaign), 'its error written with the slices open');
    }

    // the failure of the step is what the run and the campaign show, not one of invalidate() after it
    public function test_work_shows_its_own_failure_over_that_of_invalidate(): void
    {
        self::queued(2);
        self::isds(check: [self::login('unauthorized.html')]);
        TestSendRun::$invalidateFails = new \RuntimeException('No pages');

        self::assertSame('error ISDS_LOGIN', self::runQueued());

        self::assertSame('ISDS_LOGIN', self::items()->field(self::CAMPAIGN_ITEM, Schema::CAMPAIGN_ERROR_TEXT));
    }

    // PHP refuses, with a warning, a limit below the memory it holds - of a fragmented heap far more than it uses
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_restoreMemoryLimit_keeps_a_limit_below_the_held_memory(): void
    {
        $held = self::fragmentedHeap();
        gc_mem_caches();
        self::assertGreaterThan(memory_get_usage() + 4 * self::MB, memory_get_usage(true), 'a fragmented heap');

        self::assertSame([self::HIGH, []], self::restoring(intdiv(memory_get_usage() + memory_get_usage(true), 2)));
        unset($held);
    }

    // memory freed but kept by PHP for later is given back before the limit is judged
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_restoreMemoryLimit_gives_back_freed_memory_first(): void
    {
        self::freeSomeMemory();
        self::assertGreaterThan(memory_get_usage() + 4 * self::MB, memory_get_usage(true), 'freed memory PHP keeps');
        $limit = (string) (memory_get_usage(true) - 2 * self::MB);

        self::assertSame([$limit, []], self::restoring($limit));
    }

    public function test_restoreMemoryLimit_takes_no_limit(): void
    {
        self::assertSame(['-1', []], self::restoring('-1'));
    }

    /**
     * memory_limit after the run gave back $limit in place of a high one, and the warnings of it
     * @return array{string, list<string>}
     */
    private static function restoring(int|string $limit): array
    {
        $before   = (string) ini_get('memory_limit');
        $warnings = [];
        ini_set('memory_limit', self::HIGH);
        set_error_handler(static function (int $level, string $text) use (&$warnings): bool {
            $warnings[] = $text;
            return true;
        });
        try {
            (new TestSendRun())->restoreMemoryLimitOf((string) $limit);
            return [(string) ini_get('memory_limit'), $warnings];
        } finally {
            restore_error_handler();
            ini_set('memory_limit', $before);
        }
    }

    /**
     * Small strings, every other one freed: PHP holds their pages, half empty, and uses half of them
     * @return array<int, string>
     */
    private static function fragmentedHeap(): array
    {
        $strings = [];
        for ($i = 0; $i < 120_000; ++$i) {
            $strings[] = str_repeat('x', 100 + $i % 7);
        }
        for ($i = 0; $i < 120_000; $i += 2) {
            unset($strings[$i]);
        }
        return $strings;
    }

    /** memory PHP keeps once freed: pages of small strings of a size of their own, none left in them */
    private static function freeSomeMemory(): void
    {
        $strings = [];
        for ($i = 0; $i < 40_000; ++$i) {
            $strings[] = str_repeat('y', 600);
        }
    }

    /** bytes of memory a message of the letter with its megabyte attachment needs beyond what the process holds */
    private static function need(): int
    {
        return 8 * (1_000_000 + strlen('<p>Vážení</p>')) + 16 * self::MB;
    }


    #[DataProvider('minSend')]
    public function test_work_keeps_to_min_send(float $each, int $sent): void
    {
        $id                    = self::queued(6);
        TestSendRun::$microNow = (float) self::NOW;
        $isds                  = self::isds([static function () use ($each): Response {
            TestSendRun::$microNow += $each;
            return self::created('1001');
        }]);

        self::runQueued();

        self::assertCount($sent, $isds->sent());
        self::assertSame(['i' => $sent, 'p' => 'send'], self::position($id));
    }

    /** @return array<string, array{float, int}> a step has 27 s */
    public static function minSend(): array
    {
        return ['three seconds left' => [8.0, 4], 'less' => [8.1, 3]];
    }

    public function test_work_loads_files_once_per_step(): void
    {
        self::queued(3, letter: self::letter('%PDF-1.4'));
        $isds         = self::isds();
        TableDb::$log = [];

        self::runQueued();

        self::assertCount(3, $isds->sent());
        self::assertSame(1, self::filesRead());
    }
}
