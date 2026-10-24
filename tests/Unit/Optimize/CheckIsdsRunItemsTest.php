<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Later\BatchRun;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsRunItems;
use AA\Optimize\CheckIsdsRuns;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Optimize\Fixtures\IsdsRunCheckCase;
use Tests\Unit\Optimize\Fixtures\TestIsdsRunItemsCheck;

require_once __DIR__ . '/fixtures/IsdsRunChecks.php';

/**
 * CheckIsdsRunItems: the message items against the fate the record of their run has for each message,
 * which a sending goes by - a write of an item that failed after the record's leaves it showing
 * something else. The items are read by the ids of the frozen recipients, with the message slice open,
 * of the runs CheckIsdsRuns reads whole - never while a step may still work on the run.
 */
final class CheckIsdsRunItemsTest extends TestCase
{
    use IsdsRunCheckCase;

    public function test_types_extended(): void
    {
        self::assertSame([AbstractOptimize::CHECK_EXTENDED], CheckIsdsRunItems::types());
    }

    public function test_findings_itemsShowTheRecord_ok(): void
    {
        $id = self::queued(6, ['dm_ids' => '{"0":"901"}', 'failed' => '{"1":"1233"}', 'uncertain' => '{"2":' . (self::NOW - 60) . '}', 'skips' => '{"3":"inactive"}', 'cursor' => '4'], reasons: [5 => Schema::BOX_DUPLICATE]);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_FAILED, Schema::MESSAGE_STATE_UNCERTAIN, Schema::MESSAGE_STATE_SKIPPED, Schema::MESSAGE_STATE_QUEUED, Schema::MESSAGE_STATE_SKIPPED]);

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunItemsCheck())->findings()));
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function mismatches(): iterable
    {
        yield 'left sending, never tried' => [[], Schema::MESSAGE_STATE_SENDING, 'sending → queued'];
        // the answer to CreateMessage, or a lookup that found it, reached the record only
        yield 'sent, item queued' => [['dm_ids' => '{"1":"902"}'], Schema::MESSAGE_STATE_QUEUED, 'queued → sent'];
        yield 'sent, item uncertain' => [['dm_ids' => '{"1":"902"}', 'uncertain' => '{"1":1899999000}'], Schema::MESSAGE_STATE_UNCERTAIN, 'uncertain → sent'];
        yield 'sent, item stopped' => [['dm_ids' => '{"1":"902"}'], Schema::MESSAGE_STATE_STOPPED, 'stopped → sent'];
        yield 'not found, item uncertain' => [['failed' => '{"1":"notfound"}', 'uncertain' => '{"1":1899999000}'], Schema::MESSAGE_STATE_UNCERTAIN, 'uncertain → failed'];
        yield 'refused by the antivirus check, item sent' => [['failed' => '{"1":"3"}'], Schema::MESSAGE_STATE_SENT, 'sent → failed'];
        // the box found inactive: skipInactive() wrote the record, not the item
        yield 'box inactive, item queued' => [['skips' => '{"1":"inactive"}'], Schema::MESSAGE_STATE_QUEUED, 'queued → skipped'];
    }

    /** @param array<string, string> $maps */
    #[DataProvider('mismatches')]
    public function test_findings_itemNotShowingTheRecord(array $maps, string $item, string $example): void
    {
        $id = self::queued(3, $maps + ['dm_ids' => '{"0":"901"}', 'cursor' => '2']);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, $item]);

        $finding = self::only((new TestIsdsRunItemsCheck())->findings());

        self::assertSame([Finding::WARNING, "items:$id", 1, 'Campaigns'], [$finding->severity, $finding->key, $finding->count, $finding->place]);
        self::assertSame('4711-2 (' . self::message($id, 1) . "): $example", $finding->evidence[0]['Examples']);
        self::assertArrayHasKey('Campaign item', $finding->links);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function unknownFates(): iterable
    {
        // the run ended without the uncertain state its end writes to them (afterClose())
        yield 'ended, item sending' => [SendRun::ENDED, Schema::MESSAGE_STATE_SENDING, 'sending → uncertain'];
        yield 'ended, item queued' => [SendRun::ENDED, Schema::MESSAGE_STATE_QUEUED, 'queued → uncertain'];
        yield 'done, item sending' => [SendRun::DONE, Schema::MESSAGE_STATE_SENDING, 'sending → uncertain'];
        yield 'ended, item uncertain' => [SendRun::ENDED, Schema::MESSAGE_STATE_UNCERTAIN, null];
        // a person looked it up in the ISDS portal, as the finding of unknown fate tells them to
        yield 'ended, set sent by hand' => [SendRun::ENDED, Schema::MESSAGE_STATE_SENT, null];
        yield 'ended, set failed by hand' => [SendRun::ENDED, Schema::MESSAGE_STATE_FAILED, null];
        // a running sending looks it up and writes the item itself
        yield 'running, item sending' => [SendRun::SENDING, Schema::MESSAGE_STATE_SENDING, null];
        yield 'running, item queued' => [SendRun::SENDING, Schema::MESSAGE_STATE_QUEUED, null];
        // Continue looks it up
        yield 'stopped by an error, item sending' => [SendRun::ERROR, Schema::MESSAGE_STATE_SENDING, null];
    }

    #[DataProvider('unknownFates')]
    public function test_findings_messageOfUnknownFate_wrongOnlyNotMarkedInAFinishedRun(string $state, string $item, ?string $example): void
    {
        $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":1899999000}', 'cursor' => '2']);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, $item]);
        if (in_array($state, [SendRun::DONE, SendRun::ENDED], true)) {
            self::finished($id, $state, 7200, Schema::CAMPAIGN_STATE_STOPPED);
        } else {
            self::storedSet($id, 'state', $state);
        }

        $findings = (new TestIsdsRunItemsCheck())->findings();

        if (is_null($example)) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        self::assertSame('4711-2 (' . self::message($id, 1) . "): $example", self::only($findings)->evidence[0]['Examples']);
    }

    // the record counts a message by the map that says most: sent before refused
    public function test_findings_sentAndFailed_countsAsSent(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'failed' => '{"1":"1233"}', 'cursor' => '2']);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT]);

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunItemsCheck())->findings()));
    }

    /** @return iterable<string, array{string, int, bool}> */
    public static function inFlight(): iterable
    {
        // the step sends it now, or died sending it: the next step settles it
        yield 'sending' => [SendRun::SENDING, 0, false];
        yield 'given up a minute ago' => [SendRun::ENDED, 60, false];
        // afterClose() would have marked it uncertain
        yield 'given up two hours ago' => [SendRun::ENDED, 7200, true];
    }

    #[DataProvider('inFlight')]
    public function test_findings_itemSendingInFlight(string $state, int $ago, bool $wrong): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'inflight' => '1', 'inflight_at' => (string) (self::NOW - 7300), 'cursor' => '1']);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENDING]);
        if ($state !== SendRun::SENDING) {
            self::finished($id, $state, $ago, Schema::CAMPAIGN_STATE_STOPPED);
        }

        self::assertSame($wrong ? ["items:$id"] : [''], self::keys((new TestIsdsRunItemsCheck())->findings()));
    }

    public function test_findings_examplesCapped(): void
    {
        $id = self::queued(8, ['dm_ids' => json_encode((object) array_fill(0, 8, '901')), 'cursor' => '8']);

        $finding = self::only((new TestIsdsRunItemsCheck())->findings());

        self::assertSame([8, 5], [$finding->count, substr_count($finding->evidence[0]['Examples'], '(')]);
        self::assertStringStartsWith('4711-1 (' . self::message($id, 0) . '): queued → sent', $finding->evidence[0]['Examples']);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function leases(): iterable
    {
        yield 'a step working' => [self::NOW + 100, false];
        // past its lease a step may still write for DEAD_GRACE: the record first, then the items
        yield 'a step past its lease' => [self::NOW - BatchRun::DEAD_GRACE + 1, false];
        yield 'a step that died' => [self::NOW - BatchRun::DEAD_GRACE, true];
    }

    #[DataProvider('leases')]
    public function test_findings_comparedOnlyWhenNoStepMayStillWork(int $lease, bool $compared): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']);
        self::storedSet($id, 'lease', (string) $lease);
        self::storedSet($id, 'lease_tok', 'a-step');
        $check = new TestIsdsRunItemsCheck();

        self::assertSame($compared ? ["items:$id"] : [''], self::keys($check->findings()));
        self::assertCount($compared ? 2 : 1, $check->reads, 'the campaign, then the messages - not while a step may still write them');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function stepsMeanwhile(): iterable
    {
        yield 'a step claimed the run' => [['lease' => (string) (self::NOW + 100), 'lease_tok' => 'a-step']];
        yield 'a step ended' => [['step' => '7']];
    }

    // what the record said may no longer hold for the items read after it
    /** @param array<string, string> $step */
    #[DataProvider('stepsMeanwhile')]
    public function test_findings_stepWhileItemsAreRead_notCompared(array $step): void
    {
        $id            = self::queued(2, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']);
        $check         = new TestIsdsRunItemsCheck();
        $messages      = array_column(self::rows($id), 'msg');
        $check->onRead = static function (array $ids) use ($id, $step, $messages): void {
            foreach (($ids === $messages) ? $step : [] as $property => $value) {
                self::storedSet($id, $property, $value);
            }
        };

        self::assertSame([Finding::OK], self::severities($check->findings()));
        self::assertSame([[self::CAMPAIGN_ITEM], $messages], array_column($check->reads, 'ids'));
    }

    // the ids of the frozen recipients, of the runs read whole, the message slice open and its reading password kept
    public function test_findings_readsTheItemsOfRecentRunsById(): void
    {
        self::itemsAre(self::finished(self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2']), SendRun::DONE, CheckIsdsRuns::RECENT_DAYS * 86400), [Schema::MESSAGE_STATE_QUEUED]);
        $new   = self::queued(501, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']);
        $check = new TestIsdsRunItemsCheck();

        self::assertSame(["items:$new"], self::keys($check->findings()), 'message 0 queued, though sent');
        self::assertSame(array_column(self::rows($new), 'msg'), array_merge(...array_column(array_slice($check->reads, 1), 'ids')));
        self::assertSame([500, 1], array_map('count', array_column(array_slice($check->reads, 1), 'ids')), 'a piece at a time');
        self::assertSame([Schema::MESSAGE_STATE], array_values(array_unique(array_column(array_slice($check->reads, 1), 'field'))));
        self::assertFalse(\AA_Credentials::singleton()->checkSlice(Sending::MESSAGE), 'closed again');
    }

    // GetItemContent() reads by id whatever the slice: an item elsewhere is not the message
    public function test_findings_itemOfAnotherSlice_leftOut(): void
    {
        $id = self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2']);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT]);
        self::items()->stored[self::message($id, 1)]['slice'] = Sending::CAMPAIGN;

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunItemsCheck())->findings()));
    }

    // only this check reads the frozen recipients
    public function test_findings_changedSnapshot_recordCannotBeRead(): void
    {
        $id = self::queued(2, ['cursor' => '0']);
        self::storedSet($id, 'manifest', '{}');

        $finding = self::only((new TestIsdsRunItemsCheck())->findings());

        self::assertSame([Finding::ERROR, "record:$id"], [$finding->severity, $finding->key]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ends(): iterable
    {
        yield 'done' => [SendRun::DONE, Schema::CAMPAIGN_STATE_DONE];
        // a stop leaves dm_dirty as it was: what afterClose() did not write stays wrong
        yield 'given up' => [SendRun::ENDED, Schema::CAMPAIGN_STATE_DONE];
    }

    // undeliverable since the item was written: the step that read it writes the item
    public function test_findings_skipsDirtyWhileWatching(): void
    {
        $id = self::following(2, ['dm_state' => '{"1":"8:1899990000:0:0"}', 'dm_dirty' => '{"1":1}']);

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunItemsCheck())->findings()));

        self::storedSet($id, 'dm_dirty', '');

        self::assertSame('4711-2 (' . self::message($id, 1) . '): sent → failed', self::only((new TestIsdsRunItemsCheck())->findings())->evidence[0]['Examples']);
    }

    #[DataProvider('ends')]
    public function test_findings_comparesDirtyAfterEnd(string $state, string $campaign): void
    {
        $id = self::finished(self::following(2, ['dm_state' => '{"1":"8:1899990000:0:0"}', 'dm_dirty' => '{"1":1}']), $state, 7200, $campaign);

        $finding = self::only((new TestIsdsRunItemsCheck())->findings());

        self::assertSame(["items:$id", '4711-2 (' . self::message($id, 1) . '): sent → failed'], [$finding->key, $finding->evidence[0]['Examples']]);
    }

    public function test_findings_undeliverableMatchesFailed(): void
    {
        $id = self::following(2, ['dm_state' => '{"0":"4:1899990000:1899990000:0","1":"8:1899990000:0:0"}']);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_FAILED]);
        self::itemStatusesAre($id, ['4', '8']);

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunItemsCheck())->findings()));
    }

    // the state is the same word from 4 on: only the status tells delivered from accepted
    public function test_findings_dmStatusMismatchFound(): void
    {
        $id = self::following(3, ['dm_state' => '{"0":"4:1899990000:1899990000:0","1":"6:1899990000:1899980000:1899990000"}']);
        self::itemStatusesAre($id, ['4', '4', '1']);
        $check = new TestIsdsRunItemsCheck();

        $finding = self::only($check->findings());

        self::assertSame([1, '4711-2 (' . self::message($id, 1) . '): dmMessageStatus 4 → 6'], [$finding->count, $finding->evidence[0]['Examples']]);
        self::assertSame(['ids' => array_slice(array_column(self::rows($id), 'msg'), 0, 2), 'field' => Schema::MESSAGE_DM_STATUS], $check->reads[2], 'only the messages the record has a delivery of');
    }

    // an item that does not show it at all
    public function test_findings_dmStatusEmpty_shownAsDash(): void
    {
        $id = self::following(2, ['dm_state' => '{"0":"4:1899990000:1899990000:0"}']);
        self::itemStatusesAre($id, ['']);

        self::assertSame('4711-1 (' . self::message($id, 0) . '): dmMessageStatus – → 4', self::only((new TestIsdsRunItemsCheck())->findings())->evidence[0]['Examples']);
    }

    // a sending that never followed delivery has none to compare: no read more
    public function test_findings_dmStatusNotReadWithoutWatch(): void
    {
        $id = self::finished(self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'dm_state' => '{"0":"4:1899990000:1899990000:0"}', 'cursor' => '2']), SendRun::DONE);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT]);
        self::itemStatusesAre($id, ['1', '1']);
        $check = new TestIsdsRunItemsCheck();

        self::assertSame([Finding::OK], self::severities($check->findings()));
        self::assertSame([Schema::CAMPAIGN_STATE, Schema::MESSAGE_STATE], array_column($check->reads, 'field'));
    }

    // a running sending looks it up; once following, nothing does
    public function test_findings_unknownFateWhileWatching_itemQueued(): void
    {
        $id = self::following(3, ['dm_ids' => '{"0":"901","1":"902"}', 'uncertain' => '{"2":1899990000}']);
        self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_QUEUED]);

        self::assertSame('4711-3 (' . self::message($id, 2) . '): queued → uncertain', self::only((new TestIsdsRunItemsCheck())->findings())->evidence[0]['Examples']);
    }

    // CheckIsdsRuns reports it - the two together on State Check would say it twice
    public function test_findings_corruptMap_leftToTheRunsCheck(): void
    {
        self::queued(2, ['failed' => '{"0":', 'cursor' => '1']);

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunItemsCheck())->findings()));
    }
}
