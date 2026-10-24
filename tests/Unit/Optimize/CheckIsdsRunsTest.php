<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\Outcome;
use AA\Isds\Schema;
use AA\Isds\SendRun;
use AA\Isds\StatusSync;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsRuns;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Isds\Fixtures\Sending;
use Tests\Unit\Isds\Fixtures\TestSendRun;
use Tests\Unit\Optimize\Fixtures\IsdsRunCheckCase;
use Tests\Unit\Optimize\Fixtures\TestIsdsRunItemsCheck;
use Tests\Unit\Optimize\Fixtures\TestIsdsRunsCheck;

require_once __DIR__ . '/fixtures/IsdsRunChecks.php';

/**
 * CheckIsdsRuns: a mass sending that hangs or stopped by an error, a finished one that left messages
 * of unknown fate, too many failed, a campaign item that does not show how its sending went or is gone -
 * over the records of TestSendRun in TableDb and its items in memory, both slices with a reading
 * password. A finished run is read whole only within RECENT_DAYS; the campaign items only by id.
 */
final class CheckIsdsRunsTest extends TestCase
{
    use IsdsRunCheckCase;

    private const HOUR = 3600;
    /** an hour and the retries of a run that did not pause (SendRun::retryDelay()): 1 + 60 + 120 s */
    private const HANGS_AFTER = 3600 + 1 + 60 + 120;
    /** following that read nothing longer has stalled: three hours past a round and its lag */
    private const LATE = 3 * 3600 + StatusSync::ROUND + StatusSync::LAG;
    /** a delivery of the record: delivered, then accepted by a login */
    private const ACCEPTED = '6:1899990000:1899980000:1899990000';

    public function test_types_basicCheckOfMail(): void
    {
        self::assertSame([AbstractOptimize::CHECK_BASIC], CheckIsdsRuns::types());
        self::assertSame('mail', CheckIsdsRuns::category());
    }

    public function test_findings_noRun_ok(): void
    {
        self::assertSame([Finding::OK], self::severities((new TestIsdsRunsCheck())->findings()));
    }

    /** @return iterable<string, array{\Closure(): void}> */
    public static function goingWell(): iterable
    {
        yield 'sending' => [static function (): void {
            self::itemsAre(self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']), [Schema::MESSAGE_STATE_SENT]);
        }];
        yield 'prep, one item made' => [static function (): void {
            self::queued(3, [], ['i' => 1, 'p' => 'prep'], [], 1);
        }];
        // a running sending looks up its messages of unknown fate itself
        yield 'look waits for the list' => [static function (): void {
            TestSendRun::$now = self::NOW - 3 * self::HOUR;
            $id               = self::queued(3, ['dm_ids' => '{"0":"901","1":"902"}', 'uncertain' => '{"2":' . (self::NOW - 4000) . '}', 'cursor' => '3'], ['i' => 0, 'p' => 'look', 'u' => self::NOW + 600]);
            TestSendRun::$now = self::NOW;
            self::storedSet($id, 'next_at', (string) (self::NOW + 600));
            self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_UNCERTAIN]);
        }];
        // a pause waits for ISDS till u: the step is due then, so the run does not hang meanwhile
        yield 'paused for hours' => [static function (): void {
            TestSendRun::$now = self::NOW - 3 * self::HOUR;
            $id               = self::queued(3, [], ['i' => 1, 'p' => 'send', 'u' => self::NOW + 600, 'w' => 6, 's' => self::NOW - 3 * self::HOUR]);
            TestSendRun::$now = self::NOW;
            self::storedSet($id, 'next_at', (string) (self::NOW + 600));
        }];
        yield 'stop' => [static function (): void {
            $id = self::queued(3, ['dm_ids' => '{"0":"901"}', 'cursor' => '1', 'stop_by' => Sending::USER], ['i' => 1, 'p' => 'stop']);
            self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_STOPPED, Schema::MESSAGE_STATE_QUEUED]);
        }];
        // one refusal among the first three is no trend
        yield 'one of three tried failed' => [static function (): void {
            self::itemsAre(self::queued(20, ['dm_ids' => '{"0":"901","1":"902"}', 'failed' => '{"2":"1233"}', 'cursor' => '3']), [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_FAILED]);
        }];
        // the items of dm_dirty a step of following writes yet; waiting for the next round is no failure
        yield 'following, an item not showing its delivery yet' => [static function (): void {
            self::following(2, ['dm_state' => '{"0":"' . self::ACCEPTED . '"}', 'dm_dirty' => '{"0":1}']);
        }];
        yield 'following ended, every message accepted' => [static function (): void {
            $id = self::finished(self::following(2, ['dm_state' => '{"0":"' . self::ACCEPTED . '","1":"' . self::ACCEPTED . '"}']), SendRun::DONE);
            self::itemStatusesAre($id, ['6', '6']);
        }];
        // the campaign is written from the queue once the end committed and the lease of the last step ran out
        yield 'given up a minute ago' => [static function (): void {
            $id = self::finished(self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2']), SendRun::ENDED, 60, Schema::CAMPAIGN_STATE_ERROR);
            self::itemsAre($id, [Schema::MESSAGE_STATE_SENT, Schema::MESSAGE_STATE_SENT]);
        }];
    }

    #[DataProvider('goingWell')]
    public function test_findings_sendingGoingWell_ok(\Closure $make): void
    {
        $make();

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunsCheck())->findings()), 'runs');
        self::assertSame([Finding::OK], self::severities((new TestIsdsRunItemsCheck())->findings()), 'items');
    }

    /** @return iterable<string, array{int, bool}> */
    public static function idle(): iterable
    {
        yield 'an hour and the retries' => [self::HANGS_AFTER, false];
        yield 'a second more' => [self::HANGS_AFTER + 1, true];
    }

    // no step for an hour past the time one was due: the queue does not run, or a row was lost
    #[DataProvider('idle')]
    public function test_findings_runWithoutStep_hangsAfterAnHourAndItsRetries(int $idle, bool $hangs): void
    {
        TestSendRun::$now = self::NOW - 3 * self::HOUR;
        $id               = self::queued(3, ['uncertain' => '{"1":' . (self::NOW - 3 * self::HOUR) . '}', 'inflight' => '2', 'inflight_at' => (string) (self::NOW - 3 * self::HOUR), 'cursor' => '2']);
        TestSendRun::$now = self::NOW;
        self::storedSet($id, 'moved', (string) (self::NOW - $idle));

        $findings = (new TestIsdsRunsCheck())->findings();

        if (!$hangs) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        $finding = self::only($findings);
        self::assertSame([Finding::WARNING, "stuck:$id", self::NOW - $idle, 'Campaigns'], [$finding->severity, $finding->key, $finding->since, $finding->place], 'since the last progress');
        self::assertSame('A mass sending to data boxes has not moved on for 1 hours', $finding->title);
        self::assertSame(['send', '2'], [$finding->evidence[0]['Phase'], $finding->evidence[0]['Messages of unknown fate']], 'the message in flight too');
    }

    public function test_findings_hangingRun_phaseOnlyAsWord(): void
    {
        TestSendRun::$now = self::NOW - 3 * self::HOUR;
        $id               = self::queued(3);
        TestSendRun::$now = self::NOW;
        self::storedSet($id, 'position', '{"p":["send"],"i":0}');

        self::assertSame('', self::only((new TestIsdsRunsCheck())->findings())->evidence[0]['Phase']);
    }

    public function test_findings_runInError_withItsCodeAndCampaign(): void
    {
        $id = self::failedRun(Schema::CAMPAIGN_STATE_ERROR);

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame([Finding::ERROR, "error:$id", self::NOW - 600], [$finding->severity, $finding->key, $finding->since], 'since the last progress');
        $evidence = $finding->evidence[0];
        self::assertSame(['ISDS_REFUSED status:1233', '2', Schema::CAMPAIGN_STATE_ERROR], [$evidence['Error'], $evidence['Messages of unknown fate'], $evidence['Campaign state']]);
        self::assertSame(['Campaign item' => '/aa/admin/itemedit.php?id=' . self::CAMPAIGN_ITEM . '&change_id=' . Sending::CAMPAIGN . '&edit=1'], $finding->links);
    }

    // a run that stopped before it could write the campaign (SETTINGS) leaves it sending: one finding says both
    public function test_findings_runInError_campaignStillSending(): void
    {
        $id = self::failedRun(Schema::CAMPAIGN_STATE_SENDING);

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame(["error:$id", Schema::CAMPAIGN_STATE_SENDING], [$finding->key, $finding->evidence[0]['Campaign state']]);
    }

    public function test_findings_runInError_campaignGone_noLink(): void
    {
        $id = self::failedRun(Schema::CAMPAIGN_STATE_ERROR);
        unset(self::items()->stored[self::CAMPAIGN_ITEM]);

        $findings = (new TestIsdsRunsCheck())->findings();

        self::assertSame(['gone', "error:$id"], self::keys($findings));
        self::assertSame([], $findings[1]->links);
        self::assertArrayNotHasKey('Campaign state', $findings[1]->evidence[0]);
    }

    // given up with a message in flight and six uncertain: all wait for a person
    public function test_findings_finishedRunLeftMessagesOfUnknownFate(): void
    {
        $uncertain = json_encode((object) array_fill(1, 6, self::NOW - 900));
        $id        = self::finished(self::queued(9, ['dm_ids' => '{"0":"901"}', 'uncertain' => $uncertain, 'inflight' => '7', 'inflight_at' => (string) (self::NOW - 600), 'cursor' => '8']), SendRun::ENDED, 7200, Schema::CAMPAIGN_STATE_STOPPED);

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame([Finding::WARNING, "unknown:$id", 7, self::NOW - 900], [$finding->severity, $finding->key, $finding->count, $finding->since]);
        self::assertSame('4711-2, 4711-3, 4711-4, 4711-5, 4711-6', $finding->evidence[0]['Examples'], 'by the sender references a person finds them in ISDS');
    }

    /** @return iterable<string, array{int, int, array<string, string|int>, string, ?string}> */
    public static function failedShares(): iterable
    {
        yield '2 of 20' => [20, 18, ['failed' => '{"18":"1233","19":"notfound"}'], SendRun::SENDING, '2 of 20|1233 ×1, notfound ×1'];
        yield '1 of 20 is 5 %' => [20, 19, ['failed' => '{"19":"1233"}'], SendRun::SENDING, null];
        yield '2 of 39' => [39, 37, ['failed' => '{"37":"1233","38":"1233"}'], SendRun::SENDING, '2 of 39|1233 ×2'];
        yield '4 of 20, most frequent first' => [20, 16, ['failed' => '{"16":"notfound","17":"1233","18":"1233","19":"1233"}'], SendRun::SENDING, '4 of 20|1233 ×3, notfound ×1'];
        // a running sending warns only once 20 were tried
        yield '1 of 19, running' => [19, 18, ['failed' => '{"18":"1233"}'], SendRun::SENDING, null];
        // the record counts a message by the map that says most: sent before refused
        yield 'refused, then found sent' => [20, 18, ['failed' => '{"0":"1233","1":"1233"}'], SendRun::SENDING, null];
        yield 'uncertain ones were tried' => [20, 17, ['uncertain' => '{"17":' . (self::NOW - 60) . '}', 'failed' => '{"18":"1233","19":"1233"}'], SendRun::SENDING, '2 of 20|1233 ×2'];
        yield 'one of three, running' => [3, 2, ['failed' => '{"2":"1233"}'], SendRun::SENDING, null];
        yield 'one of three, done' => [3, 2, ['failed' => '{"2":"1233"}'], SendRun::DONE, '1 of 3|1233 ×1'];
        yield 'a box skipped was not tried' => [20, 18, ['failed' => '{"18":"1233"}', 'skips' => '{"19":"inactive"}'], SendRun::DONE, '1 of 19|1233 ×1'];
    }

    /** @param array<string, string> $maps */
    #[DataProvider('failedShares')]
    public function test_findings_failedShareOver5Percent(int $n, int $sent, array $maps, string $state, ?string $expected): void
    {
        $id = self::queued($n, $maps + ['dm_ids' => json_encode((object) array_fill(0, $sent, '901')), 'cursor' => (string) $n]);
        if ($state === SendRun::DONE) {
            self::finished($id, $state);
        }

        $findings = (new TestIsdsRunsCheck())->findings();

        if (is_null($expected)) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        $finding = self::only($findings);
        [$title, $codes] = explode('|', $expected);
        self::assertSame([Finding::WARNING, "failed:$id", "$title messages of a mass sending to data boxes failed", $codes], [$finding->severity, $finding->key, $finding->title, $finding->evidence[0]['Error']]);
    }

    /** @return iterable<string, array{string, int, string, bool}> */
    public static function campaignEnds(): iterable
    {
        yield 'done, campaign done' => [SendRun::DONE, 7200, Schema::CAMPAIGN_STATE_DONE, false];
        yield 'done, campaign stopped' => [SendRun::DONE, 7200, Schema::CAMPAIGN_STATE_STOPPED, false];
        yield 'given up, campaign stopped' => [SendRun::ENDED, 7200, Schema::CAMPAIGN_STATE_STOPPED, false];
        yield 'given up an hour ago, campaign in error' => [SendRun::ENDED, self::HOUR, Schema::CAMPAIGN_STATE_ERROR, false];
        yield 'given up over an hour ago, campaign in error' => [SendRun::ENDED, self::HOUR + 1, Schema::CAMPAIGN_STATE_ERROR, true];
        yield 'done, campaign sending' => [SendRun::DONE, 7200, Schema::CAMPAIGN_STATE_SENDING, true];
        yield 'done, campaign reset to draft' => [SendRun::DONE, 7200, Schema::CAMPAIGN_STATE_DRAFT, true];
    }

    // nothing writes the campaign again once afterClose() or finalCheck() failed to
    #[DataProvider('campaignEnds')]
    public function test_findings_finishedRun_campaignNotShowingItsEnd(string $run, int $ago, string $campaign, bool $warns): void
    {
        $id = self::finished(self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2']), $run, $ago, $campaign);

        $findings = (new TestIsdsRunsCheck())->findings();

        if (!$warns) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        $finding = self::only($findings);
        self::assertSame([Finding::WARNING, "end:$id", $campaign, $run], [$finding->severity, $finding->key, $finding->evidence[0]['Campaign state'], $finding->evidence[0]['State']]);
    }

    // its record and frozen letter stay as the evidence of what was sent
    public function test_findings_campaignGone(): void
    {
        TestSendRun::$now = self::NOW - 2 * self::HOUR;
        $done             = self::queued(1, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']);
        TestSendRun::$now = self::NOW;
        self::finished($done, SendRun::DONE);
        unset(self::items()->stored[self::CAMPAIGN_ITEM]);

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame([Finding::INFO, 'gone', 1, []], [$finding->severity, $finding->key, $finding->count, $finding->links]);
        self::assertSame(self::NOW - 2 * self::HOUR, $finding->since, 'since the oldest start');
        self::assertSame([$done, self::CAMPAIGN_ITEM], [$finding->evidence[0]['Run'], $finding->evidence[0]['Campaign item']]);

        $going = [];
        for ($i = 0; $i < 5; ++$i) {
            $going[] = self::queued(1);
        }

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame([Finding::WARNING, 6, self::NOW - 2 * self::HOUR], [$finding->severity, $finding->count, $finding->since], 'sendings going on without their campaign');
        self::assertEqualsCanonicalizing($going, array_column($finding->evidence, 'Run'), 'the ones going on first, at most five');
    }

    // GetItemContent() reads by id whatever the slice: a campaign item elsewhere is not the campaign -
    // and its closed slice masks none of the campaigns read with it
    public function test_findings_campaignItemInAnotherSlice_gone(): void
    {
        self::finished(self::queued(1, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']), SendRun::DONE);
        $elsewhere = 'ca000000000000000000000000000c02';
        self::storedSet(self::queued(1), 'subject', $elsewhere);
        self::items()->stored[$elsewhere] = ['slice' => Sending::MESSAGE, 'fields' => [Schema::CAMPAIGN_STATE => Schema::CAMPAIGN_STATE_SENDING]];

        self::assertSame(['gone'], self::keys((new TestIsdsRunsCheck())->findings()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function notIds(): iterable
    {
        yield 'campaign' => ['subject', 'x" onclick="a()'];
        yield 'its slice' => ['camp_slice', 'x'];
    }

    // a record edited by hand: nothing read by it, no link made of it
    #[DataProvider('notIds')]
    public function test_findings_campaignNotAnId_goneUnread(string $property, string $value): void
    {
        self::storedSet(self::queued(1), $property, $value);
        $check = new TestIsdsRunsCheck();

        $findings = $check->findings();

        self::assertSame(['gone'], self::keys($findings));
        self::assertSame([[], []], [$check->reads, $findings[0]->links]);
    }

    /** @return iterable<string, array{string}> */
    public static function inCapitals(): iterable
    {
        yield 'campaign' => ['subject'];
        yield 'its slice' => ['camp_slice'];
    }

    // GetItemContent() answers in lowercase: a record edited to capitals still finds its campaign
    #[DataProvider('inCapitals')]
    public function test_findings_campaignIdInCapitals_found(string $property): void
    {
        $id = self::finished(self::queued(1, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']), SendRun::DONE, 7200, Schema::CAMPAIGN_STATE_SENDING);
        self::storedSet($id, $property, strtoupper(self::stored($id)[$property]));
        $check = new TestIsdsRunsCheck();

        self::assertSame(["end:$id"], self::keys($check->findings()));
        self::assertSame([self::CAMPAIGN_ITEM], $check->reads[0]['ids']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function campaignSlices(): iterable
    {
        yield 'another slice' => [Sending::MESSAGE, 2];
        yield 'the same in capitals' => [strtoupper(Sending::CAMPAIGN), 1];
    }

    // the campaigns of each slice read once, those of every slice kept
    #[DataProvider('campaignSlices')]
    public function test_findings_campaignsOfTwoSlices_bothFound(string $slice, int $reads): void
    {
        self::finished(self::queued(1, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']), SendRun::DONE);
        $other = self::finished(self::queued(1, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']), SendRun::DONE);
        $item  = 'ca000000000000000000000000000c02';
        self::storedSet($other, 'subject', $item);
        self::storedSet($other, 'camp_slice', $slice);
        self::items()->stored[$item] = ['slice' => strtolower($slice), 'fields' => [Schema::CAMPAIGN_STATE => Schema::CAMPAIGN_STATE_DONE]];
        $check = new TestIsdsRunsCheck();

        self::assertSame([Finding::OK], self::severities($check->findings()));
        self::assertCount($reads, $check->reads);
    }

    // one campaign item answers for every spelling of its id
    public function test_findings_twoRunsOfACampaign_oneInCapitals_bothFound(): void
    {
        $upper = self::finished(self::queued(1, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']), SendRun::DONE);
        self::storedSet($upper, 'subject', strtoupper(self::CAMPAIGN_ITEM));
        self::finished(self::queued(1, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']), SendRun::DONE);

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunsCheck())->findings()));
    }

    // a map or the position edited by hand is a finding of its own, never a fatal of State Check
    public function test_findings_corruptRecord_cannotBeRead_othersStillChecked(): void
    {
        $map      = self::queued(2, ['dm_ids' => '{"0":"901"', 'cursor' => '1']);
        $position = self::queued(2, ['cursor' => '0']);
        self::storedSet($position, 'position', '{"p":');
        $failed   = self::failedRun(Schema::CAMPAIGN_STATE_ERROR);
        $key      = self::finished(self::queued(2, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"a":5}', 'cursor' => '2']), SendRun::DONE);
        // the skips count in no number: read only to be sure the record can be
        $skips    = self::finished(self::queued(2, ['dm_ids' => '{"0":"901"}', 'skips' => '{"1":', 'cursor' => '2']), SendRun::DONE);

        $findings = (new TestIsdsRunsCheck())->findings();

        self::assertEqualsCanonicalizing(["record:$map", "record:$position", "record:$key", "record:$skips", "error:$failed"], self::keys($findings));
        self::assertSame(array_fill(0, 5, Finding::ERROR), self::severities($findings));
    }

    public function test_findings_finishedRunReadWholeOnlyWithinRecentDays(): void
    {
        $id = self::finished(self::queued(2, ['dm_ids' => '{"0":"901"', 'cursor' => '2']), SendRun::DONE, CheckIsdsRuns::RECENT_DAYS * 86400);

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunsCheck())->findings()), 'its record is not read');

        self::storedSet($id, 'finished', (string) (self::NOW - CheckIsdsRuns::RECENT_DAYS * 86400 + 1));

        self::assertSame(["record:$id"], self::keys((new TestIsdsRunsCheck())->findings()));
    }

    /**
     * CHECK_BASIC runs on every load of State Check: object_text only through its (property, value) and
     * object_id indexes, a run finished long ago by its small properties only - three of them cost the
     * reads of one. Nothing written.
     */
    public function test_findings_readsThroughIndexes_oldRunsCostNoMoreReads(): void
    {
        self::queued(2, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']);
        $selects = [];
        foreach ([1, 2] as $add) {
            for ($i = 0; $i < $add; ++$i) {
                self::finished(self::queued(2, ['dm_ids' => '{"0":"901","1":"902"}', 'cursor' => '2']), SendRun::DONE, 400 * 86400);
            }
            $from = count(TableDb::$log);

            self::assertSame([Finding::OK], self::severities((new TestIsdsRunsCheck())->findings()));

            $log       = array_slice(TableDb::$log, $from);
            $selects[] = array_values(array_filter($log, static fn(string $sql): bool => str_starts_with($sql, 'SELECT')));
            self::assertSame([], array_values(array_filter($log, static fn(string $sql): bool => (bool) preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/', $sql))), 'a check writes nothing');
        }
        self::assertSame(count($selects[0]), count($selects[1]), 'one old run, then three');
        foreach ($selects[1] as $sql) {
            self::assertMatchesRegularExpression('/ FROM `object_text` WHERE (property = \'aa_type\' AND value = |object_id IN \(.*\) AND property IN \(|object_id = \'[0-9a-f]{32}\'$)/', $sql);
        }
    }

    // an old run's campaign is still checked - by id, with its slice open: the reading password would mask its state
    public function test_findings_readsCampaignItemsById_sliceOpen(): void
    {
        $old = self::finished(self::queued(1, ['dm_ids' => '{"0":"901"}', 'cursor' => '1']), SendRun::DONE, 400 * 86400, Schema::CAMPAIGN_STATE_SENDING);
        $check = new TestIsdsRunsCheck();

        $finding = self::only($check->findings());

        self::assertSame(["end:$old", Schema::CAMPAIGN_STATE_SENDING], [$finding->key, $finding->evidence[0]['Campaign state']]);
        self::assertSame([['ids' => [self::CAMPAIGN_ITEM], 'field' => Schema::CAMPAIGN_STATE]], $check->reads);
        self::assertFalse(\AA_Credentials::singleton()->checkSlice(Sending::CAMPAIGN), 'closed again');
    }

    // a campaign state, a code and an id from the database go into the evidence escaped: State Check prints it as HTML
    public function test_findings_evidenceEscaped(): void
    {
        $id = self::finished(self::queued(3, ['dm_ids' => '{"0":"901"}', 'failed' => '{"1":"<i>x</i>","2":"<i>x</i>"}', 'cursor' => '3']), SendRun::DONE, 7200, '<b>done</b>');

        $findings = (new TestIsdsRunsCheck())->findings();

        self::assertSame(["end:$id", "failed:$id"], self::keys($findings));
        self::assertSame(['&lt;b&gt;done&lt;/b&gt;', '&lt;i&gt;x&lt;/i&gt; ×2'], [$findings[0]->evidence[0]['Campaign state'], $findings[1]->evidence[0]['Error']]);
    }

    /** @return iterable<string, array{string, string, int, string, ?string}> */
    public static function watchWaits(): iterable
    {
        $long = self::NOW - self::LATE - 1;
        yield 'ISDS does not answer' => [Outcome::UNAVAILABLE, '', $long, 'ISDS did not answer', 'ISDS_UNAVAILABLE'];
        yield 'the address blocked' => [Outcome::UNAVAILABLE, 'blocked', $long, 'blocked the address of this server. AA tries again after 24 hours. See the check "ISDS login"', 'ISDS_UNAVAILABLE blocked'];
        yield 'datovka_conf.php unreadable' => [Outcome::UNAVAILABLE, 'config', $long, 'PHP cannot read misc/datovka/datovka_conf.php', 'ISDS_UNAVAILABLE config'];
        yield 'an hour of changes too large' => [Outcome::UNAVAILABLE, 'too_large', $long, 'so many changes in an hour', 'ISDS_UNAVAILABLE too_large'];
        // waiting for the next round is no failure to show
        yield 'waiting for the next round' => [SendRun::WATCHING, '', $long, 'AA tries again by itself', null];
        yield 'read up to a time in the future' => [SendRun::WATCHING, '', self::NOW + StatusSync::ROUND + 1, 'a time in the future', null];
    }

    // following that reads nothing says why from its last wait, and that it catches up by itself
    #[DataProvider('watchWaits')]
    public function test_findings_watchNotRead3h_warnsWithDetail(string $code, string $detail, int $last, string $advice, ?string $wait): void
    {
        $from = self::NOW - 10 * self::HOUR;
        $id   = self::following(2, [], $from, $last);
        self::storedSet($id, 'retry_code', $code);
        self::storedSet($id, 'error_detail', $detail);

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame([Finding::WARNING, "watch:$id", ($last > self::NOW) ? $from : $last], [$finding->severity, $finding->key, $finding->since]);
        self::assertSame('Following the delivery of a mass sending to data boxes has stalled', $finding->title);
        self::assertStringContainsString($advice, $finding->advice);
        self::assertStringContainsString('sends no message again', $finding->advice);
        self::assertSame($wait, $finding->evidence[0]['Last wait'] ?? null);
        self::assertSame(date('Y-m-d H:i', $last), $finding->evidence[0]['Read up to']);
    }

    // a run no step works on hangs; that it reads nothing follows from that
    public function test_findings_watchNotWhenStuck(): void
    {
        $id = self::following(2, [], self::NOW - 10 * self::HOUR);
        self::storedSet($id, 'next_at', (string) (self::NOW - 5 * self::HOUR));
        self::storedSet($id, 'moved', (string) (self::NOW - 5 * self::HOUR));

        self::assertSame(["stuck:$id"], self::keys((new TestIsdsRunsCheck())->findings()));
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function watchStarts(): iterable
    {
        // watch_last starts at the start of the run: following counts from watch_from
        yield 'following for the hours of a wait' => [self::NOW - self::LATE, self::NOW - 2 * 86400, false];
        yield 'a second more' => [self::NOW - self::LATE - 1, self::NOW - 2 * 86400, true];
        yield 'read up to the hours of a wait ago' => [self::NOW - 2 * 86400, self::NOW - self::LATE, false];
        yield 'read up to a round to come' => [self::NOW - 2 * self::HOUR, self::NOW + StatusSync::ROUND, false];
    }

    #[DataProvider('watchStarts')]
    public function test_findings_watchNotInFirstHours(int $from, int $last, bool $warns): void
    {
        $id = self::following(2, [], $from, $last);

        self::assertSame($warns ? ["watch:$id"] : [''], self::keys((new TestIsdsRunsCheck())->findings()));
    }

    // every message went: the campaign shows its end, only the delivery is not read
    public function test_findings_errorInWatch_isWarningWithOwnAdvice(): void
    {
        $id = self::following(2, [], self::NOW - 5 * self::HOUR);
        foreach (['state' => SendRun::ERROR, 'error_code' => 'ISDS_LOGIN', 'moved' => (string) (self::NOW - 600)] as $property => $value) {
            self::storedSet($id, $property, $value);
        }

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame([Finding::WARNING, "error:$id", 'ISDS_LOGIN'], [$finding->severity, $finding->key, $finding->evidence[0]['Error']]);
        self::assertSame('Following the delivery of a mass sending to data boxes stopped by an error', $finding->title);
        self::assertStringContainsString('only finding out whether they were delivered has stopped', $finding->advice);
    }

    // looked up in vain before following: nothing looks them up any more, the office need not wait for the end
    public function test_findings_unknownWhileWatching_ownTitle(): void
    {
        $id = self::following(3, ['dm_ids' => '{"0":"901","1":"902"}', 'uncertain' => '{"2":' . (self::NOW - 2 * self::HOUR) . '}']);

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame([Finding::WARNING, "unknown:$id", 1, '4711-3'], [$finding->severity, $finding->key, $finding->count, $finding->evidence[0]['Examples']]);
        self::assertSame('A mass sending being followed has messages of unknown fate: 1', $finding->title);
    }

    // refused by the antivirus check or undeliverable after it was sent: failed, though in dm_ids, counted once all were tried
    public function test_findings_failedCounts3And8WithAdvice(): void
    {
        $id = self::following(3, ['dm_state' => '{"0":"' . self::ACCEPTED . '","1":"3:1899990000:0:0","2":"8:1899990000:1899900000:1899950000"}']);

        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame([Finding::WARNING, "failed:$id", '2 of 3 messages of a mass sending to data boxes failed', '3 ×1, 8 ×1'], [$finding->severity, $finding->key, $finding->title, $finding->evidence[0]['Error']]);
        self::assertStringContainsString('Code 3: an attachment did not pass the antivirus check', $finding->advice);
        self::assertStringContainsString('Code 8: the data box of the recipient was made inaccessible', $finding->advice);
    }

    /** @return iterable<string, array{string}> */
    public static function followingMaps(): iterable
    {
        // SendMaps reads such a map as no delivery - which would hide every failure in it
        yield 'dm_state' => ['dm_state'];
        // CheckIsdsRunItems would skip no item it names, and a step fails on it
        yield 'dm_dirty' => ['dm_dirty'];
    }

    #[DataProvider('followingMaps')]
    public function test_findings_followingMapNotJson_cannotBeRead(string $map): void
    {
        $id = self::following(2, [$map => '{"0":']);

        self::assertSame(["record:$id"], self::keys((new TestIsdsRunsCheck())->findings()));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function watchCampaigns(): iterable
    {
        yield 'an hour after following started' => [self::HOUR, false];
        yield 'a second more' => [self::HOUR + 1, true];
    }

    // following starts after the campaign shows the end: a campaign that does not is wrong once the end may have been written
    #[DataProvider('watchCampaigns')]
    public function test_findings_endInWatch_afterCloseGrace(int $ago, bool $warns): void
    {
        $id = self::following(2, [], self::NOW - $ago);
        self::campaignShows(Schema::CAMPAIGN_STATE_ERROR);

        $findings = (new TestIsdsRunsCheck())->findings();

        if (!$warns) {
            self::assertSame([Finding::OK], self::severities($findings));
            return;
        }
        $finding = self::only($findings);
        self::assertSame([Finding::WARNING, "end:$id", self::NOW - $ago], [$finding->severity, $finding->key, $finding->since]);
    }

    // the error finding says what to do; Continue restores the campaign
    public function test_findings_endNotForWatchInError(): void
    {
        $id = self::following(2, [], self::NOW - 5 * self::HOUR);
        self::storedSet($id, 'state', SendRun::ERROR);
        self::storedSet($id, 'error_code', 'INTERNAL');
        self::campaignShows(Schema::CAMPAIGN_STATE_ERROR);

        self::assertSame(["error:$id"], self::keys((new TestIsdsRunsCheck())->findings()));
    }

    // no entry, or one before delivery or refusal: ISDS never said what became of it - of one refused by
    // the antivirus check it did
    public function test_findings_undeliveredAfterEnd(): void
    {
        $id = self::finished(self::following(30, ['dm_state' => '{"0":"' . self::ACCEPTED . '","2":"2:1899990000:0:0","3":"x","4":"3:1899990000:0:0"}'], self::NOW - 10 * 86400), SendRun::DONE);

        // one of 30 failed is no share to warn of
        $finding = self::only((new TestIsdsRunsCheck())->findings());

        self::assertSame(["undelivered:$id", 28], [$finding->key, $finding->count]);

        self::assertSame([Finding::WARNING, self::NOW - 10 * 86400], [$finding->severity, $finding->since]);
        self::assertSame('4711-2, 4711-3, 4711-4, 4711-6, 4711-7', $finding->evidence[0]['Examples']);
    }

    public function test_findings_noUndeliveredWhileWatching(): void
    {
        self::following(4, ['dm_state' => '{"0":"' . self::ACCEPTED . '","2":"2:1899990000:0:0"}']);

        self::assertSame([Finding::OK], self::severities((new TestIsdsRunsCheck())->findings()));
    }

    // ------------------------------------------------------------ helpers

    /** a run stopped by a refusal with two messages of unknown fate - one in flight -, its campaign showing $campaign */
    private static function failedRun(string $campaign): string
    {
        $id = self::queued(4, ['dm_ids' => '{"0":"901"}', 'uncertain' => '{"1":' . (self::NOW - 900) . '}', 'inflight' => '2', 'inflight_at' => (string) (self::NOW - 600), 'cursor' => '2']);
        foreach (['state' => SendRun::ERROR, 'error_code' => 'ISDS_REFUSED', 'error_detail' => 'status:1233', 'moved' => (string) (self::NOW - 600)] as $property => $value) {
            self::storedSet($id, $property, $value);
        }
        self::campaignShows($campaign);
        return $id;
    }
}
