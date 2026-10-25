<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\SliceRules;
use AA\Isds\Snapshot;
use AA\Isds\StatusSync;
use AA\Later\Toexecute;
use AA_Perm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\SendRunCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;

require_once __DIR__ . '/fixtures/SendRunCase.php';

/**
 * A person confirms a message of unknown fate did not go: the record fails it as verified, then its
 * item - only once nothing looks the message up or marks it any more, and only a message that is
 * still of unknown fate.
 */
final class SendRunVerifyTest extends TestCase
{
    use SendRunCase;

    /** when the message of recipient 1 was tried */
    private const TRIED = self::NOW - 7200;

    public function test_verify_movesUncertainToFailed(): void
    {
        $id = self::watching();
        $at = [];
        self::items()->onWrite = static function () use (&$at): void {
            $at[] = TestSendRun::$lockLog;
        };

        self::assertSame('', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, strtoupper(self::message($id, 1))));

        self::assertSame([[0 => '1001', 2 => '1003'], [1 => Schema::VERIFIED]], [self::map($id, 'dm_ids'), self::map($id, 'failed')]);
        self::assertSame(['failed' => 1, 'uncertain' => 0], array_intersect_key(self::tally($id), ['failed' => 1, 'uncertain' => 1]));
        self::assertSame([Schema::MESSAGE_STATE_FAILED, Schema::VERIFIED], [self::state($id, 1), self::items()->field(self::message($id, 1), Schema::MESSAGE_ERROR_CODE)]);
        $last = json_decode(self::items()->field(self::message($id, 1), Schema::MESSAGE_EVENTS), true)[2];
        self::assertSame(['at' => self::NOW, 'kind' => Schema::EVENT_VERIFIED, 'code' => Schema::VERIFIED, 'by' => Sending::USER], $last);
        self::assertSame([Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT], [self::state($id, 0), self::state($id, 2)], 'no other message');
        self::assertSame([false], array_column(self::items()->writes, 'tx'), 'one item, after the commit');
        self::assertSame([['lock ' . self::CAMPAIGN_ITEM]], $at, 'while the campaign is still locked');
        self::assertSame(['lock ' . self::CAMPAIGN_ITEM, 'unlock ' . self::CAMPAIGN_ITEM], TestSendRun::$lockLog);
    }

    /**
     * Runs a message of unknown fate may still be looked up in, or sent from
     * @return array<string, array{\Closure(): string}> makes the run, gives its id
     */
    public static function stillSending(): array
    {
        $uncertain = ['dm_ids' => '{"0":"1001"}', 'uncertain' => '{"1":' . self::TRIED . '}', 'cursor' => '2'];

        return [
            'sending'                => [static fn(): string => self::queued(3, $uncertain)],
            'stopped, looking it up' => [static fn(): string => self::queued(3, $uncertain + ['stop_by' => Sending::USER], self::LOOK)],
            'all tried, looking'     => [static fn(): string => self::queued(3, ['cursor' => '3'] + $uncertain, self::LOOK)],
            'error'                  => [static fn(): string => self::leftInError(self::queued(3, $uncertain))],
            'error, stopped'         => [static fn(): string => self::leftInError(self::queued(3, $uncertain + ['stop_by' => Sending::USER]))],
            // Continue ran the look of a run that followed delivery again: it may still find the message
            'watching, its look repeated'    => [static fn(): string => self::watching(position: self::LOOK)],
            'watch_error, its look repeated' => [static fn(): string => self::leftInError(self::watching(position: self::LOOK))],
        ];
    }

    /** @param \Closure(): string $run */
    #[DataProvider('stillSending')]
    public function test_verify_onlyAfterSending(\Closure $run): void
    {
        $id     = $run();
        $before = self::stored($id);

        self::assertSame('WRONG_STATE', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));

        self::assertSame($before, self::stored($id));
        self::assertSame([], self::items()->writes);
    }

    /**
     * Runs nothing looks a message up in any more
     * @return array<string, array{\Closure(): string}> makes the run, gives its id
     */
    public static function overRuns(): array
    {
        return [
            'watching'    => [static fn(): string => self::watching()],
            'watch_error' => [static fn(): string => self::leftInError(self::watching())],
            'done'        => [static fn(): string => self::leftInError(self::queued(3, ['uncertain' => '{"1":' . self::TRIED . '}', 'cursor' => '3']), state: SendRun::DONE)],
        ];
    }

    /** @param \Closure(): string $run */
    #[DataProvider('overRuns')]
    public function test_verify_afterSending(\Closure $run): void
    {
        $id = $run();

        self::assertSame('', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));
        self::assertSame([1 => Schema::VERIFIED], self::map($id, 'failed'));
    }

    // till the end of a run given up is written, it would mark the message uncertain over the verification
    public function test_verify_abandonedVerifiedOnlyAfterClose(): void
    {
        $id = self::abandoned(['uncertain' => '{"1":' . self::TRIED . '}']);
        self::assertSame('WRONG_STATE', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)), 'its end still queued');

        self::assertSame('closed', self::runQueued());
        self::assertSame(Schema::CAMPAIGN_STATE_STOPPED, self::campaign());

        self::assertSame('', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));
        self::assertSame([Schema::MESSAGE_STATE_FAILED, Schema::MESSAGE_STATE_STOPPED], [self::state($id, 1), self::state($id, 2)]);
    }

    // following delivery ended the sending for the campaign already: only the end of the run tells
    public function test_verify_abandonedWatchOnlyAfterClose(): void
    {
        $id = self::watching();
        self::leftInError($id);
        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
        self::assertSame(Schema::CAMPAIGN_STATE_DONE, self::campaign());

        self::assertSame('WRONG_STATE', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));

        self::assertSame('closed', self::runQueued());
        self::assertSame('', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));
        self::assertSame(Schema::MESSAGE_STATE_FAILED, self::state($id, 1));
    }

    /**
     * The close row of a run given up, as the queue may leave it
     * @return array<string, array{\Closure(array<string, mixed>): ?array<string, mixed>, string}> the row made of the queued one (null = gone), what Verify says
     */
    public static function closeRows(): array
    {
        return [
            'lost'                          => [static fn(array $row): ?array => null, ''],
            'given up by the queue'         => [static fn(array $row): ?array => ['priority' => 0, 'execute_after' => self::NOW - 60] + $row, ''],
            'its last try running now'      => [static fn(array $row): ?array => ['priority' => 0, 'execute_after' => self::NOW + 600] + $row, 'WRONG_STATE'],
            'waiting for a try after one failed' => [static fn(array $row): ?array => ['priority' => 2, 'execute_after' => self::NOW - 60] + $row, 'WRONG_STATE'],
        ];
    }

    /** @param \Closure(array<string, mixed>): ?array<string, mixed> $left */
    #[DataProvider('closeRows')]
    public function test_verify_abandonedByItsCloseRow(\Closure $left, string $said): void
    {
        $id  = self::abandoned(['uncertain' => '{"1":' . self::TRIED . '}']);
        $row = $left(self::queue()[0]);
        TableDb::$tables['toexecute'] = is_null($row) ? [] : [$row];

        self::assertSame($said, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));
    }

    /**
     * What the page of a sending offers Verify by, and counts uncertain messages for a person by
     * (Gate::progress()) is what verify() takes
     * @param \Closure(): string $run
     */
    #[DataProvider('stillSending')]
    public function test_verifiable_notWhileLookedUp(\Closure $run): void
    {
        self::assertFalse((TestSendRun::get($run()) ?? throw new \LogicException('No run'))->verifiable());
    }

    /** @param \Closure(): string $run */
    #[DataProvider('overRuns')]
    public function test_verifiable_afterSending(\Closure $run): void
    {
        self::assertTrue((TestSendRun::get($run()) ?? throw new \LogicException('No run'))->verifiable());
    }

    /** @param \Closure(array<string, mixed>): ?array<string, mixed> $left */
    #[DataProvider('closeRows')]
    public function test_verifiable_abandonedByItsCloseRow(\Closure $left, string $said): void
    {
        $id  = self::abandoned(['uncertain' => '{"1":' . self::TRIED . '}']);
        $row = $left(self::queue()[0]);
        TableDb::$tables['toexecute'] = is_null($row) ? [] : [$row];

        self::assertSame($said === '', (TestSendRun::get($id) ?? throw new \LogicException('No run'))->verifiable());
    }

    // an end that failed may never write the campaign: the person must not wait for it for ever
    public function test_verify_abandonedAfterFailedCloseVerifies(): void
    {
        $id = self::abandoned(['uncertain' => '{"1":' . self::TRIED . '}']);
        self::storedSet($id, 'progress', json_encode(['warning' => 'after_close'] + json_decode(self::stored($id)['progress'], true)));

        self::assertSame('', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));
        self::assertSame(Schema::CAMPAIGN_STATE_ERROR, self::campaign());
    }

    // a step that died sending it left the message in flight: given up, it is of unknown fate as well
    public function test_verify_inflightCountsAsUncertain(): void
    {
        $id = self::abandoned(['inflight' => '1', 'inflight_at' => (string) self::TRIED]);
        self::runQueued();

        self::assertSame('', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));

        self::assertSame([1 => Schema::VERIFIED], self::map($id, 'failed'));
        self::assertSame([], (TestSendRun::get($id) ?? throw new \LogicException('No run'))->maps()->unsettled());
        self::assertSame(Schema::MESSAGE_STATE_FAILED, self::state($id, 1));
    }

    public function test_verify_againWritesNothing(): void
    {
        $id = self::watching();
        TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1));
        $before               = self::stored($id);
        self::items()->writes = [];

        self::assertSame(SendRun::VERIFIED, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));

        self::assertSame($before, self::stored($id));
        self::assertSame([], self::items()->writes);
    }

    // the record says it already: the item a failure left uncertain is written by the same verification again
    public function test_verify_againWritesTheItemAFailureLeft(): void
    {
        $id                    = self::watching();
        self::items()->onWrite = static fn() => throw new \RuntimeException('Message item not stored');
        self::assertSame('', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));
        self::items()->onWrite = null;

        self::assertSame(SendRun::VERIFIED, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));

        self::assertSame(Schema::MESSAGE_STATE_FAILED, self::state($id, 1));
    }

    // a fate the record was edited into since is not written over by a repeat
    public function test_verify_againOfAnotherFateNotUncertain(): void
    {
        $id = self::watching();
        TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1));
        self::storedSet($id, 'dm_ids', '{"0":"1001","1":"1002","2":"1003"}');
        self::items()->writes = [];

        self::assertSame(SendRun::NOT_UNCERTAIN, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));
        self::assertSame([], self::items()->writes);
    }

    // who verified it, by the name the login has
    public function test_verify_byUidAndName(): void
    {
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]])->names = [Sending::USER => 'Jana Nováková'];
        $id = self::watching();

        TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1));

        self::assertSame(Sending::USER . ' Jana Nováková', json_decode(self::items()->field(self::message($id, 1), Schema::MESSAGE_EVENTS), true)[2]['by']);
    }

    // slices that break a rule since get nothing: not the record, not the item
    public function test_verify_sliceRulesBrokenSettings(): void
    {
        $id     = self::watching();
        $before = self::stored($id);
        TestSendRun::$rules = new class extends SliceRules {
            public function sliceProblems(string $campaignSlice, string $messageSlice): array
            {
                return ['broken'];
            }
        };

        self::assertSame(SendRun::SETTINGS, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));

        self::assertSame([$before, [], []], [self::stored($id), self::items()->writes, TestSendRun::$lockLog], 'refused before the locks');
    }

    // frozen recipients that do not read back as frozen tell nothing of the message
    public function test_verify_snapshotChangedRefused(): void
    {
        $id  = self::watching();
        $msg = self::message($id, 1);
        self::storedSet($id, 'manifest', 'x' . self::stored($id)['manifest']);

        self::assertSame(Snapshot::CHANGED, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, $msg));

        self::assertSame([[], []], [self::map($id, 'failed'), self::items()->writes]);
    }

    /** @return array<string, array{string}> */
    public static function otherMessages(): array
    {
        return [
            'of another campaign' => ['a0000000000000000000000000000099'],
            'no id'               => ['1'],
        ];
    }

    #[DataProvider('otherMessages')]
    public function test_verify_messageOfOtherCampaignUnknownMessage(string $msg): void
    {
        $id     = self::watching();
        $before = self::stored($id);

        self::assertSame(SendRun::UNKNOWN_MESSAGE, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, $msg));

        self::assertSame([$before, []], [self::stored($id), self::items()->writes]);
    }

    /**
     * Recipients of a run following delivery whose fate is known, or was never tried
     * @return array<string, array{int}> the index
     */
    public static function notUncertain(): array
    {
        return ['sent' => [0], 'refused' => [3], 'skipped for its box' => [4], 'skipped for its reason' => [5]];
    }

    #[DataProvider('notUncertain')]
    public function test_verify_notUncertainRefused(int $k): void
    {
        $id     = self::watching(6, ['failed' => '{"3":"1214"}', 'skips' => '{"4":"inactive"}', 'cursor' => '6'], [5 => Schema::BOX_DUPLICATE]);
        $before = self::stored($id);

        self::assertSame(SendRun::NOT_UNCERTAIN, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, $k)));

        self::assertSame([$before, []], [self::stored($id), self::items()->writes]);
    }

    // an item that fails after the commit leaves the record failing the message: never looked up nor sent
    // again; the verification is done, the failure logged without its text
    public function test_verify_recordBeforeItem(): void
    {
        $id                    = self::watching();
        self::items()->onWrite = static function () use ($id): void {
            self::assertSame([1 => Schema::VERIFIED], self::map($id, 'failed'), 'committed before the item');
            throw new \RuntimeException('secret text');
        };

        self::assertSame('', TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));

        self::assertSame(Schema::MESSAGE_STATE_UNCERTAIN, self::state($id, 1));
        self::assertSame('unlock ' . self::CAMPAIGN_ITEM, TestSendRun::$lockLog[array_key_last(TestSendRun::$lockLog)], 'the lock released');
        $logged = array_values(array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Toexecute::FAILED_EVENT));
        self::assertCount(1, $logged);
        self::assertStringNotContainsString('secret', (string) json_encode($logged));
    }

    // asked before the locks and under them, as of a stop
    public function test_verify_foreignUserForbidden(): void
    {
        $id     = self::watching();
        $before = self::stored($id);

        self::assertSame(SendRun::FORBIDDEN, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::READER, self::message($id, 1)));

        self::assertSame([$before, [], []], [self::stored($id), self::items()->writes, TestSendRun::$lockLog]);
    }

    public function test_verify_lockedCampaignBusy(): void
    {
        $id                          = self::watching();
        TestSendRun::$lockedByOthers = [self::CAMPAIGN_ITEM];

        self::assertSame(SendRun::BUSY, TestSendRun::verify(self::CAMPAIGN_ITEM, Sending::USER, self::message($id, 1)));

        self::assertSame([[], []], [self::map($id, 'failed'), self::items()->writes]);
    }

    /**
     * A run following delivery of its messages but that of recipient 1, which is of unknown fate - its
     * item shows it
     * @param array<string, string> $props  over those
     * @param array<int, string>    $reasons as queued()
     * @param array<string, int|string> $position
     */
    private static function watching(int $n = 3, array $props = [], array $reasons = [], array $position = self::WATCH): string
    {
        $from = self::NOW - 3600;
        $sent = array_diff_key(array_map(static fn(int $k): string => (string) (1001 + $k), range(0, $n - 1)), [1 => 1, 3 => 1, 4 => 1, 5 => 1]);
        $id   = self::queued($n, $props + [
            'dm_ids'     => json_encode($sent, JSON_FORCE_OBJECT),
            'uncertain'  => '{"1":' . self::TRIED . '}',
            'cursor'     => (string) $n,
            'watch_from' => (string) $from,
            'watch_day'  => StatusSync::firstWatchDay($from),
        ], $position, $reasons);
        foreach (array_keys($sent) as $k) {
            self::items()->mark(self::message($id, $k), Schema::MESSAGE_STATE_SENT, Schema::EVENT_SUBMITTED, self::NOW - 7000, (string) (1001 + $k));
        }
        self::items()->mark(self::message($id, 1), Schema::MESSAGE_STATE_UNCERTAIN, Schema::EVENT_TIMEOUT, self::TRIED);
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = Schema::CAMPAIGN_STATE_DONE;
        self::items()->writes = [];

        return $id;
    }

    /**
     * A run of three recipients given up in error after a stop: recipient 0 sent, 2 never tried, the
     * campaign shows the error; its end queued, not run
     * @param array<string, string> $props
     */
    private static function abandoned(array $props): string
    {
        $id = self::leftInError(self::queued(3, $props + ['dm_ids' => '{"0":"1001"}', 'cursor' => '2', 'stop_by' => Sending::USER]));
        self::items()->stored[self::CAMPAIGN_ITEM]['fields'][Schema::CAMPAIGN_STATE] = Schema::CAMPAIGN_STATE_ERROR;
        self::assertSame(SendRun::ABANDONED, TestSendRun::stop(self::CAMPAIGN_ITEM, Sending::USER, true));
        self::items()->writes   = [];
        TestSendRun::$lockLog = [];

        return $id;
    }
}
