<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA;
use AA\IO\DB\DB_AA;
use AA\Mailbulk\ItemLetter;
use AA\Mailbulk\Outcome;
use AA\Mailbulk\Recipients;
use AA\Later\Toexecute;
use AA\Mailbulk\Run;
use AA_Credentials;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Mailbulk\Fixtures\FakeLetter;
use Tests\Unit\Mailbulk\Fixtures\ScriptedMailer;
use Tests\Unit\Mailbulk\Fixtures\TestRun;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
require_once __DIR__ . '/Fixtures/FakeLetter.php';
require_once __DIR__ . '/Fixtures/ScriptedMailer.php';
require_once __DIR__ . '/Fixtures/TestRun.php';

/**
 * The run of a sending over TableDb's object_text and toexecute: each frozen reader gets the mail once,
 * the record says whom the step is sending to before it sends and what came of it right after.
 */
final class RunTest extends TestCase
{
    use BatchRunTables {
        setUpBeforeClass as private tablesSetUpBeforeClass;
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const ITEM = 'cafe0000000000000000000000000001';
    private const LIST = 'abcde';

    public static function setUpBeforeClass(): void
    {
        self::tablesSetUpBeforeClass();
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);      // AA::Pagecache(), which opening slices keeps the page out of
        }
    }

    protected function setUp(): void
    {
        $this->tablesSetUp();
        TestRun::reset();
        TestRun::$now      = self::NOW;
        TestRun::$microNow = (float) self::NOW;
    }

    protected function tearDown(): void
    {
        TestRun::reset();
        (new \ReflectionProperty(AA_Credentials::class, 'instance'))->setValue(null, null);
        (new \ReflectionProperty(AA::class, 'pagecache'))->setValue(null, null);
        $this->tablesTearDown();
    }

    /** a run to send to $n readers, its first step queued; its id */
    private static function queued(int $n, array $props = []): string
    {
        return DB_AA::transaction(static fn(): string => TestRun::start($props + [
            'subject'    => self::ITEM,
            'user'       => self::USER,
            'position'   => ['i' => 0],
            'list'       => self::LIST,
            'title'      => 'Newsletter',
            'recipients' => Recipients::freeze(self::ITEM, array_map(self::reader(...), range(0, $n - 1))),
            'total'      => (string) $n,
            'cursor'     => '0',
        ])->getId());
    }

    private static function reader(int $i): string
    {
        return sprintf('a%031x', $i);
    }

    private static function address(int $i): string
    {
        return self::reader($i) . '@example.org';
    }

    /** @return list<string> */
    private static function addresses(int ...$indexes): array
    {
        return array_map(self::address(...), $indexes);
    }

    private static function letter(): FakeLetter
    {
        return TestRun::$letter ?? throw new \LogicException('No letter');
    }

    private static function mailer(): ScriptedMailer
    {
        return TestRun::$mailer ?? throw new \LogicException('No mailer');
    }

    private static function runOf(string $id): TestRun
    {
        return TestRun::get($id) ?? throw new \LogicException('No run');
    }

    /** @return list<int> the indexes of the readers with $outcome (failed, skipped, uncertain) */
    private static function list(string $id, string $outcome): array
    {
        $map = json_decode(self::stored($id)['outcomes'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        return array_keys(array_filter($map, static fn(string $o): bool => $o === $outcome[0]));
    }

    /** @return array<int, string> */
    private static function notes(string $id): array
    {
        return json_decode(self::stored($id)['notes'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    }

    /** the done and total progress() shows */
    private static function progressOf(string $id): string
    {
        $progress = json_decode(self::stored($id)['progress'], true, 512, JSON_THROW_ON_ERROR);
        return json_encode(['done' => $progress['done'], 'total' => $progress['total']], JSON_THROW_ON_ERROR);
    }

    /** the record of the run as it is at the moment the process dies, then the queue past its lease */
    private static function dead(string $id, string $failures = '0'): void
    {
        self::storedSet($id, 'lease', (string) (self::NOW - 1));
        self::storedSet($id, 'lease_tok', 'dead-step');
        self::storedSet($id, 'failures', $failures);
    }

    public function test_work_sendsEachRecipientOnce_inOrder(): void
    {
        $id = self::queued(3);

        self::assertSame('done', self::runQueued());

        self::assertSame(self::addresses(0, 1, 2), self::mailer()->sent());
        self::assertSame(Run::DONE, self::stored($id)['state']);
        self::assertSame(['sent' => 3, 'failed' => 0, 'skipped' => 0, 'uncertain' => 0], self::runOf($id)->outcomes());
    }

    public function test_work_stepDiedAfterTwoResults_goesOnFromTheCursor(): void
    {
        $id = self::queued(4, ['cursor' => '2']);

        self::runQueued();

        self::assertSame(self::addresses(2, 3), self::mailer()->sent());
        self::assertSame('4', self::stored($id)['cursor']);
    }

    public function test_work_deadBetweenMarkAndResult_isUncertainAndNotResent(): void
    {
        $id = self::queued(3, ['cursor' => '1', 'mark' => '1']);

        self::assertSame('done', self::runQueued());

        self::assertSame(self::addresses(2), self::mailer()->sent());
        self::assertSame([1], self::list($id, 'uncertain'));
        self::assertSame(['', '3'], [self::stored($id)['mark'], self::stored($id)['cursor']]);
        self::assertSame(['sent' => 2, 'failed' => 0, 'skipped' => 0, 'uncertain' => 1], self::runOf($id)->outcomes());
    }

    public function test_work_prepareRunsBeforeTheMark(): void
    {
        $id   = self::queued(1);
        $seen = [];
        self::mailer()->onCall = static function (string $call) use ($id, &$seen): void {
            $seen[] = [$call, self::stored($id)['mark']];
        };

        self::runQueued();

        self::assertSame([['prepare', ''], ['send', '0']], $seen, 'the mark is written between them');
        self::assertSame('', self::stored($id)['mark']);
    }

    public function test_work_retry_staysOnTheSameRecipient_markCleared(): void
    {
        $id                       = self::queued(3);
        self::mailer()->outcomes = [Outcome::Sent, Outcome::Retry];
        self::mailer()->detail   = '421 try later';

        self::assertSame('step 1', self::runQueued());

        $stored = self::stored($id);
        self::assertSame(['1', '', 'SMTP', '421 try later', 'retry'], [$stored['cursor'], $stored['mark'], $stored['retry_code'], $stored['note'], $stored['retrying']]);
        self::assertSame((string) (self::NOW + 60), $stored['next_at'], 'it waits before trying again');
        self::assertSame([], self::list($id, 'uncertain'));

        TestRun::$now = self::NOW + 60;
        self::assertSame('done', self::runQueued());
        self::assertSame(self::addresses(0, 1, 1, 2), self::mailer()->sent());
        self::assertSame(['', ''], [self::stored($id)['note'], self::stored($id)['retrying']], 'a mail sent since: the old reason goes');
    }

    public function test_work_deferred_staysOnTheSameRecipient(): void
    {
        $id                       = self::queued(2);
        self::mailer()->outcomes = [Outcome::Deferred];
        self::mailer()->detail   = '450 mailbox busy';

        self::assertSame('step 1', self::runQueued());

        $stored = self::stored($id);
        self::assertSame(['0', 'SMTP', 'deferred', '450 mailbox busy'], [$stored['cursor'], $stored['retry_code'], $stored['retrying'], $stored['note']]);
    }

    public function test_work_halt_endsInErrorSMTP_atOnce_markCleared(): void
    {
        $id                       = self::queued(3);
        self::mailer()->outcomes = [Outcome::Sent, Outcome::Halt];
        self::mailer()->detail   = '535 authentication failed';

        self::assertSame('error SMTP', self::runQueued());

        $stored = self::stored($id);
        self::assertSame([Run::ERROR, '1', '', '535 authentication failed'], [$stored['state'], $stored['cursor'], $stored['mark'], $stored['note']]);
        self::assertSame([[], [], []], [self::list($id, 'failed'), self::list($id, 'skipped'), self::list($id, 'uncertain')], 'no reader is marked');
    }

    public function test_work_uncertainOutcome_movesOn(): void
    {
        $id                       = self::queued(2);
        self::mailer()->outcomes = [Outcome::Uncertain];
        self::mailer()->detail   = 'timeout after DATA';

        self::assertSame('done', self::runQueued());

        self::assertSame(self::addresses(0, 1), self::mailer()->sent());
        self::assertSame([0], self::list($id, 'uncertain'));
        self::assertSame([0 => 'timeout after DATA'], self::notes($id));
    }

    public function test_work_failedOutcome_movesOn_andIsNoted(): void
    {
        $id                       = self::queued(2);
        self::mailer()->outcomes = [Outcome::Failed];
        self::mailer()->detail   = '550 no such user';

        self::assertSame('done', self::runQueued());

        self::assertSame(self::addresses(0, 1), self::mailer()->sent());
        self::assertSame([0], self::list($id, 'failed'));
        self::assertSame([0 => '550 no such user'], self::notes($id));
        self::assertSame(['sent' => 1, 'failed' => 1, 'skipped' => 0, 'uncertain' => 0], self::runOf($id)->outcomes());
    }

    public function test_work_messageForNull_isSkipped(): void
    {
        $id                                       = self::queued(3);
        self::letter()->messages[self::reader(1)] = null;

        self::assertSame('done', self::runQueued());

        self::assertSame(self::addresses(0, 2), self::mailer()->sent());
        self::assertSame([1], self::list($id, 'skipped'));
        self::assertSame(['sent' => 2, 'failed' => 0, 'skipped' => 1, 'uncertain' => 0], self::runOf($id)->outcomes());
    }

    public function test_work_renderOrPrepareThrows_isFailed_theTextOnlyInTheLog(): void
    {
        $id                                              = self::queued(3);
        self::letter()->messages[self::reader(0)]        = new \RuntimeException('Unknown alias _#NAME secret');
        self::mailer()->prepareThrows[self::address(1)] = new \RuntimeException('Image not found');

        self::assertSame('done', self::runQueued());

        self::assertSame(self::addresses(2), self::mailer()->sent());
        self::assertSame([0, 1], self::list($id, 'failed'));
        self::assertSame([0 => 'The mail could not be rendered.', 1 => 'The mail could not be rendered.'], self::notes($id));
        self::assertSame(['', '0'], [self::stored($id)['mark'], self::stored($id)['unmade']], 'no mark for a mail never sent; a mail sent since resets the count');
        $logged = array_values(array_filter(TableDb::rows('log'), static fn(array $r): bool => $r['type'] === Toexecute::FAILED_EVENT));
        self::assertCount(2, $logged);
        self::assertStringContainsString('RuntimeException', $logged[0]['params']);
        self::assertStringNotContainsString('secret', implode(' ', array_column($logged, 'params')));
    }

    public function test_work_mailsNotMadeTenTimesInARow_endInErrorLETTER(): void
    {
        $id = self::queued(12);
        foreach (range(1, 10) as $i) {
            self::letter()->messages[self::reader($i)] = new \RuntimeException('broken template');
        }

        self::assertSame('error ' . Run::LETTER, self::runQueued());

        self::assertSame(self::addresses(0), self::mailer()->sent());
        $stored = self::stored($id);
        self::assertSame(range(1, 10), self::list($id, 'failed'));
        self::assertSame(['11', '0'], [$stored['cursor'], $stored['unmade']], 'Continue counts anew');
        self::assertStringContainsString('10 readers in a row', $stored['note']);
    }

    public function test_work_skipAndFailureBeforeTheMail_writtenWithTheNextMark(): void
    {
        $id                                       = self::queued(3);
        self::letter()->messages[self::reader(0)] = null;
        self::letter()->messages[self::reader(1)] = new \RuntimeException('broken');
        $seen                                     = [];
        self::mailer()->onCall                   = static function (string $call) use ($id, &$seen): void {
            $seen[] = [$call, self::stored($id)['cursor'], self::stored($id)['mark']];
        };

        self::assertSame('done', self::runQueued());

        self::assertSame([['prepare', '0', ''], ['send', '2', '2']], $seen, 'no write of their own');
        self::assertSame([[1], [0]], [self::list($id, 'failed'), self::list($id, 'skipped')]);
    }

    public function test_work_skipsAtTheEndOfTheStep_areWritten(): void
    {
        $id                                       = self::queued(2);
        self::letter()->messages[self::reader(0)] = null;
        self::letter()->messages[self::reader(1)] = null;

        self::assertSame('done', self::runQueued());

        self::assertSame([], self::mailer()->calls);
        self::assertSame(['2', [0, 1]], [self::stored($id)['cursor'], self::list($id, 'skipped')]);
    }

    public function test_work_recipientsMissingOrNotAsMany_endInErrorRECIPIENTS(): void
    {
        foreach ([['total' => '4'], ['recipients' => 'f00d0000000000000000000000000001'], ['total' => '0']] as $props) {
            $id = self::queued(3, $props);

            self::assertSame('error ' . Run::RECIPIENTS, self::runQueued(), json_encode($props));

            self::assertSame([], self::mailer()->calls);
            self::storedSet($id, 'state', Run::ENDED);
        }
    }

    public function test_work_leaseOutlastsTheLongestSendAndItsRecords(): void
    {
        $id    = self::queued(1);
        $lease = 0;
        self::mailer()->onCall = static function (string $call) use ($id, &$lease): void {
            $lease = (int) self::stored($id)['lease'];
        };

        self::runQueued();

        self::assertGreaterThan(self::NOW + self::mailer()->maxSeconds() + 30, $lease);
    }

    public function test_work_budgetSpent_endsTheStepAtTheNextRecipient(): void
    {
        $id                     = self::queued(3);
        self::mailer()->onCall = static function (string $call): void {
            if ($call === 'send') {
                TestRun::$microNow += 100;
            }
        };

        self::assertSame('step 1', self::runQueued());

        self::assertSame(self::addresses(0), self::mailer()->sent());
        $stored = self::stored($id);
        self::assertSame(['1', '{"i":1}', Run::SENDING, ''], [$stored['cursor'], $stored['position'], $stored['state'], $stored['retry_code']]);
    }

    public function test_work_beforeActionRefused_sendsNothing(): void
    {
        $id                     = self::queued(2);
        self::mailer()->onCall = static function (string $call) use ($id): void {
            if ($call === 'prepare') {
                self::storedSet($id, 'state', Run::ENDED);         // stopped meanwhile
            }
        };

        self::runQueued();

        self::assertSame([], self::mailer()->sent());
        self::assertSame(['', '0'], [self::stored($id)['mark'], self::stored($id)['cursor']]);
    }

    public function test_work_problemOfTheLetter_endsInErrorLETTER_withTheNote(): void
    {
        $id                      = self::queued(2);
        self::letter()->problem = 'The item is in the trash';

        self::assertSame('error ' . Run::LETTER, self::runQueued());

        self::assertSame([], self::mailer()->calls);
        self::assertSame([Run::ERROR, 'The item is in the trash'], [self::stored($id)['state'], self::stored($id)['note']]);
    }

    public function test_onRetriesExhausted_diedStep_failsTheRecipientAndGoesOn(): void
    {
        $id = self::queued(3, ['cursor' => '1']);
        self::dead($id, '2');

        self::runQueued();

        self::assertSame([], self::mailer()->calls, 'the step that keeps dying works no more');
        self::assertSame([1], self::list($id, 'failed'));
        self::assertSame(['2', '{"i":2}', Run::SENDING], [self::stored($id)['cursor'], self::stored($id)['position'], self::stored($id)['state']]);
        self::assertArrayHasKey(1, self::notes($id));

        self::assertSame('done', self::runQueued());
        self::assertSame(self::addresses(2), self::mailer()->sent());
    }

    public function test_onRetriesExhausted_diedStepAfterAServerRetry_failsTheRecipientAndGoesOn(): void
    {
        // a retry of the server left from before Continue must not stop the run on this reader for good
        $id = self::queued(3, ['cursor' => '1', 'retrying' => 'retry']);
        self::dead($id, '2');

        self::runQueued();

        self::assertSame([[1], '2', ''], [self::list($id, 'failed'), self::stored($id)['cursor'], self::stored($id)['retrying']]);
    }

    public function test_onRetriesExhausted_diedStepWithAllRecorded_finishes(): void
    {
        $id = self::queued(3, ['cursor' => '3']);
        self::dead($id, '2');

        self::assertSame('step 1', self::runQueued());

        self::assertSame(['{"i":3}', '{"done":3,"total":3}'], [self::stored($id)['position'], self::progressOf($id)]);
        self::assertSame('done', self::runQueued());
        self::assertSame([], self::mailer()->calls);
    }

    public function test_onRetriesExhausted_deferred_failsTheRecipientAndGoesOn(): void
    {
        $id = self::queued(3);
        self::storedSet($id, 'failures', '2');
        self::mailer()->outcomes = [Outcome::Deferred];
        self::mailer()->detail   = '452 mailbox full';

        self::assertSame('step 1', self::runQueued());

        $stored = self::stored($id);
        self::assertSame([[0], '1', '{"i":1}', '', Run::SENDING], [self::list($id, 'failed'), $stored['cursor'], $stored['position'], $stored['retrying'], $stored['state']]);
        self::assertSame([0 => 'Temporarily refused: 452 mailbox full'], self::notes($id));
        self::assertSame('{"done":1,"total":3}', self::progressOf($id));

        self::assertSame('done', self::runQueued());
        self::assertSame(self::addresses(0, 1, 2), self::mailer()->sent());
    }

    public function test_outcomes_damagedMap_throws(): void
    {
        $id = self::queued(2, ['cursor' => '2', 'outcomes' => '{"1":"f"']);

        $this->expectException(\JsonException::class);
        self::runOf($id)->outcomes();
    }

    public function test_stillValid_itemGone_endsTheRun(): void
    {
        $id               = self::queued(2);
        TestRun::$itemSlice = null;

        self::assertSame(Run::ENDED, self::runQueued());

        self::assertSame([], self::mailer()->calls);
        self::assertSame(Run::ENDED, self::stored($id)['state']);
    }

    public function test_mayControl_editorOfAllItems_evenOfAListNoLongerRelated(): void
    {
        class_exists(\AA_Perm::class);              // its first load sets AA::$perm - not over the stub
        $perm     = AA::$perm;
        AA::$perm = new class {
            public function isSuperadminUser(string $uid): bool
            {
                return $uid === 'admin';
            }

            public function hasSlicePerm(string $uid, string $slice, string $perm): bool
            {
                return ($uid === 'editor') && ($slice === TestRun::SLICE) && ($perm === PS_EDIT_ALL_ITEMS);
            }
        };
        try {
            $run = self::runOf(self::queued(1));

            self::assertSame([true, false, false, false], [$run->mayControl('editor'), $run->mayControl('admin'), $run->mayControl(''), $run->mayControl('nobody')]);
            self::assertTrue(TestRun::mayControlPair('editor', 'abcde', TestRun::SLICE, true));
            TestRun::$relates = false;
            self::assertTrue($run->mayControl('editor'), 'the list no longer belongs to the slice: the sending can still be stopped');
            self::assertFalse(TestRun::mayControlPair('editor', 'abcde', TestRun::SLICE, true), 'but not sent again');
            TestRun::$itemSlice = null;
            self::assertSame([false, true], [$run->mayControl('editor'), $run->mayControl('admin')], 'the item gone: a superadmin ends it');
            self::assertFalse(TestRun::mayControlPair('admin', 'abcde', null, true), 'a gone item is never sent');
        } finally {
            AA::$perm = $perm;
        }
    }

    public function test_onRetriesExhausted_smtp_endsInError_recipientKept(): void
    {
        $id = self::queued(3);
        self::storedSet($id, 'failures', '2');
        self::mailer()->outcomes = [Outcome::Retry];

        self::assertSame('error SMTP', self::runQueued());

        self::assertSame(self::addresses(0), self::mailer()->sent());
        $stored = self::stored($id);
        self::assertSame(['0', '', Run::ERROR], [$stored['cursor'], $stored['mark'], $stored['state']]);
        self::assertSame([[], [], []], [self::list($id, 'failed'), self::list($id, 'skipped'), self::list($id, 'uncertain')]);
    }

    /** a step sends the run's item to the run's list, the tracked addresses on the run's base */
    public function test_letter_isTheItemOfTheRun(): void
    {
        $run = self::runOf(self::queued(1, ['base' => 'https://example.org/aa/']));

        $letter = (new \ReflectionMethod(Run::class, 'letter'))->invoke($run);

        self::assertInstanceOf(ItemLetter::class, $letter);
        $get = static fn(string $property): mixed => (new \ReflectionProperty(ItemLetter::class, $property))->getValue($letter);
        self::assertSame([self::ITEM, 'https://example.org/aa/', self::LIST], [$get('itemId'), $get('trackBase'), $get('list')->getId()]);
    }

    /** an overview of a list reads its runs as records, the newest first, the runs of other lists left out */
    public function test_recordsOfList_onlyRunsOfTheList_newestFirst(): void
    {
        TestRun::$now = self::NOW - 100;
        $old          = self::queued(1, ['title' => 'Old']);
        TestRun::$now = self::NOW - 50;
        self::queued(1, ['list' => 'other', 'subject' => 'cafe0000000000000000000000000002']);
        TestRun::$now = self::NOW;
        $new          = self::queued(1, ['subject' => 'cafe0000000000000000000000000003', 'title' => 'New']);
        $same         = self::queued(1, ['subject' => 'cafe0000000000000000000000000004']);

        $records = TestRun::recordsOfList(self::LIST);

        // one second: the greater id first, as everywhere the runs are listed
        $newest = (strcmp($new, $same) > 0) ? [$new, $same] : [$same, $new];
        self::assertSame([...$newest, $old], array_keys($records));
        self::assertSame(
            ['subject' => 'cafe0000000000000000000000000003', 'title' => 'New', 'started' => (string) self::NOW, 'finished' => '', 'state' => Run::SENDING, 'total' => '1'],
            array_intersect_key($records[$new], array_flip(['subject', 'title', 'started', 'finished', 'state', 'total'])),
        );
        self::assertSame(self::ITEM, $records[$old]['subject']);
    }

    /** the id of a list tells case apart - the database need not */
    public function test_recordsOfList_idDifferingOnlyInCase_isLeftOut(): void
    {
        $mine = self::queued(1);
        self::queued(1, ['list' => strtoupper(self::LIST), 'subject' => 'cafe0000000000000000000000000002']);

        self::assertSame([$mine], array_keys(TestRun::recordsOfList(self::LIST)));
    }

    public function test_delivered_isBelowCursorWithoutFailedAndSkipped_plusTheMark(): void
    {
        $id = self::queued(7, ['cursor' => '5', 'mark' => '5', 'outcomes' => '{"1":"f","3":"s","2":"u"}']);

        self::assertSame([self::reader(0), self::reader(2), self::reader(4), self::reader(5)], self::runOf($id)->delivered());
    }

    public function test_notes_over500_indexListsStayComplete(): void
    {
        $id                       = self::queued(502);
        self::mailer()->outcomes = array_fill(0, 502, Outcome::Failed);

        self::assertSame('done', self::runQueued());

        self::assertSame(range(0, 501), self::list($id, 'failed'));
        self::assertCount(500, self::notes($id));
        self::assertSame(['sent' => 0, 'failed' => 502, 'skipped' => 0, 'uncertain' => 0], self::runOf($id)->outcomes());
    }
}
