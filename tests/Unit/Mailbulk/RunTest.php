<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA;
use AA\IO\DB\DB_AA;
use AA\Mailbulk\Outcome;
use AA\Mailbulk\Recipients;
use AA\Mailbulk\Run;
use AA_Credentials;
use PHPUnit\Framework\TestCase;
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

    /** @return list<int> a JSON list of indexes of the record */
    private static function list(string $id, string $property): array
    {
        return json_decode(self::stored($id)[$property] ?: '[]', true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<int, string> */
    private static function notes(string $id): array
    {
        return json_decode(self::stored($id)['notes'] ?: '{}', true, 512, JSON_THROW_ON_ERROR);
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
        self::assertSame(['1', '', 'SMTP', '421 try later'], [$stored['cursor'], $stored['mark'], $stored['retry_code'], $stored['note']]);
        self::assertSame((string) (self::NOW + 60), $stored['next_at'], 'it waits before trying again');
        self::assertSame([], self::list($id, 'uncertain'));

        TestRun::$now = self::NOW + 60;
        self::assertSame('done', self::runQueued());
        self::assertSame(self::addresses(0, 1, 1, 2), self::mailer()->sent());
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

    public function test_work_renderOrPrepareThrows_isFailedWithTheMessage(): void
    {
        $id                                              = self::queued(3);
        self::letter()->messages[self::reader(0)]        = new \RuntimeException('Unknown alias _#NAME');
        self::mailer()->prepareThrows[self::address(1)] = new \RuntimeException('Image not found');

        self::assertSame('done', self::runQueued());

        self::assertSame(self::addresses(2), self::mailer()->sent());
        self::assertSame([0, 1], self::list($id, 'failed'));
        self::assertSame([0 => 'Unknown alias _#NAME', 1 => 'Image not found'], self::notes($id));
        self::assertSame('', self::stored($id)['mark'], 'no mark for a mail never sent');
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

    public function test_delivered_isBelowCursorWithoutFailedAndSkipped_plusTheMark(): void
    {
        $id = self::queued(7, ['cursor' => '5', 'mark' => '5', 'failed' => '[1]', 'skipped' => '[3]', 'uncertain' => '[2]']);

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
