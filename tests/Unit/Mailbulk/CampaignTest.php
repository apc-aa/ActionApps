<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk;

use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA\Mailbulk\Campaign;
use AA\Mailbulk\Message;
use AA\Mailbulk\Recipients;
use AA\Mailbulk\Run;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Mailbulk\Fixtures\FakeLetter;
use Tests\Unit\Mailbulk\Fixtures\TestCampaign;
use Tests\Unit\Mailbulk\Fixtures\TestRun;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
require_once __DIR__ . '/Fixtures/FakeLetter.php';
require_once __DIR__ . '/Fixtures/ScriptedMailer.php';
require_once __DIR__ . '/Fixtures/TestRun.php';
require_once __DIR__ . '/Fixtures/TestCampaign.php';

/** The runs of one item to one list, over TableDb's object_text: they count as one sending, and a new run goes only to whom none has reached. */
final class CampaignTest extends TestCase
{
    use BatchRunTables {
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const ITEM  = 'cafe0000000000000000000000000001';
    private const LIST  = 'abcde';
    private const OTHER = 'fghij';

    protected function setUp(): void
    {
        $this->tablesSetUp();
        TestRun::reset();
        TestCampaign::reset();
        TestRun::$now      = self::NOW;
        TestRun::$microNow = (float) self::NOW;
    }

    protected function tearDown(): void
    {
        TestRun::reset();
        TestCampaign::reset();
        $this->tablesTearDown();
    }

    private static function reader(int $i): string
    {
        return sprintf('a%031x', $i);
    }

    /** @return list<string> */
    private static function readers(int ...$indexes): array
    {
        return array_map(self::reader(...), $indexes);
    }

    /** a run of the pair that went as far as $props say, in $state, started at $at; its id */
    private static function sending(int $n, string $state, array $props = [], int $at = self::NOW - 1000, string $list = self::LIST, string $item = self::ITEM): string
    {
        TestRun::$now = $at;
        $id           = DB_AA::transaction(static fn(): string => TestRun::start($props + [
            'subject'    => $item,
            'user'       => self::USER,
            'position'   => ['i' => 0],
            'list'       => $list,
            'title'      => 'Newsletter',
            'recipients' => Recipients::freeze($item, self::readers(...range(0, $n - 1))),
            'total'      => (string) $n,
            'cursor'     => '0',
        ])->getId());
        TestRun::$now = self::NOW;
        self::storedSet($id, 'state', $state);
        return $id;
    }

    private static function letter(int ...$selection): FakeLetter
    {
        $letter            = new FakeLetter();
        $letter->selection = self::readers(...$selection);
        return $letter;
    }

    private static function campaign(): Campaign
    {
        return TestCampaign::of(self::LIST, self::ITEM);
    }

    /** the code start() refused with */
    private static function refusal(FakeLetter $letter): string
    {
        try {
            self::campaign()->start($letter, self::USER);
        } catch (BatchFailed $e) {
            return $e->reason;
        }
        self::fail('started');
    }

    /** @return list<string> */
    private static function runIds(): array
    {
        return array_map(static fn(Run $r): string => (string) $r->getId(), self::campaign()->runs());
    }

    public function test_start_freezesPendingOnly(): void
    {
        self::sending(5, Run::DONE, ['cursor' => '5', 'failed' => '[1]', 'skipped' => '[2]', 'uncertain' => '[3]']);

        $run    = self::campaign()->start(self::letter(0, 1, 2, 3, 4, 5), self::USER, 'https://example.org/');
        $stored = self::stored((string) $run->getId());

        self::assertSame(self::readers(1, 2, 5), Recipients::ids($stored['recipients']), 'sent and uncertain are left out, failed and skipped go again');
        self::assertSame(['3', '0', self::LIST, 'https://example.org/', Run::SENDING, self::USER, self::ITEM], [
            $stored['total'], $stored['cursor'], $stored['list'], $stored['base'], $stored['state'], $stored['user'], $stored['subject'],
        ]);
        self::assertSame(['lock', 'unlock'], TestCampaign::$lockLog, 'the lock is let go after the commit');
        self::assertSame((string) $run->getId(), (string) self::campaign()->active()?->getId());
    }

    public function test_start_whileActive_isRefused(): void
    {
        $id = self::sending(2, Run::SENDING);

        self::assertSame(Campaign::ACTIVE, self::refusal(self::letter(0, 1, 2)));

        self::storedSet($id, 'state', Run::ERROR);
        self::assertSame(Campaign::ACTIVE, self::refusal(self::letter(0, 1, 2)), 'Continue resumes a run in error');
        self::assertSame([$id], self::runIds());
    }

    public function test_start_whileAStepOfAStoppedRunHoldsItsLease_isRefused(): void
    {
        $id = self::sending(2, Run::ENDED, ['cursor' => '1']);
        self::storedSet($id, 'lease', (string) (self::NOW + 100));
        self::storedSet($id, 'lease_tok', 'working-step');

        self::assertSame(Campaign::ACTIVE, self::refusal(self::letter(0, 1, 2)), 'its reader may be getting the mail right now');

        TestRun::$now = self::NOW + 101;
        $run          = self::campaign()->start(self::letter(0, 1, 2), self::USER);
        self::assertSame(self::readers(1, 2), Recipients::ids(self::stored((string) $run->getId())['recipients']));
    }

    public function test_start_lockHeld_isBusy(): void
    {
        TestCampaign::$lockedByOthers = true;

        self::assertSame(Campaign::BUSY, self::refusal(self::letter(0, 1)));
        self::assertSame([], self::runIds());
    }

    public function test_start_nobody_isRefused(): void
    {
        self::assertSame(Campaign::NOBODY, self::refusal(self::letter()));

        self::sending(1, Run::DONE, ['cursor' => '1']);
        self::assertSame(Campaign::NOBODY, self::refusal(self::letter(0)), 'everyone has it');
        self::assertCount(1, self::runIds());
    }

    public function test_start_emptySubjectOrBody_isRefused(): void
    {
        foreach ([['', '<p>Hello</p>'], [' ', '<p>Hello</p>'], ['News', ''], ['News', "<html><body>\n<p> </p></body></html>"]] as [$subject, $html]) {
            $letter                                = self::letter(0, 1);
            $letter->messages[self::reader(0)]    = new Message('a@example.org', $subject, $html, 'news@example.org');
            self::assertSame(Campaign::EMPTY, self::refusal($letter), "subject '$subject', body '$html'");
        }
        self::assertSame([], self::runIds());

        $letter                             = self::letter(0, 1);
        $letter->messages[self::reader(0)] = new Message('a@example.org', 'News', '<img src="https://example.org/a.png">', 'news@example.org');
        self::campaign()->start($letter, self::USER);
        self::assertSame([self::reader(0)], $letter->asked, 'rendered for the first only');
    }

    public function test_start_problemOfTheLetter_isRefused(): void
    {
        $letter          = self::letter(0);
        $letter->problem = 'The item is in the trash';

        self::assertSame(Campaign::PROBLEM, self::refusal($letter));
        self::assertSame([], TestCampaign::$lockLog);
    }

    public function test_counts_sumOverRuns(): void
    {
        self::assertSame(['total' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'uncertain' => 0, 'date' => 0], self::campaign()->counts());
        self::assertSame('', self::campaign()->state());

        $first = self::sending(5, Run::DONE, ['cursor' => '5', 'failed' => '[1]', 'skipped' => '[2]', 'uncertain' => '[3]'], self::NOW - 3000);
        self::storedSet($first, 'finished', (string) (self::NOW - 2900));
        $second = self::sending(3, Run::DONE, ['cursor' => '3'], self::NOW - 2000);
        self::storedSet($second, 'finished', (string) (self::NOW - 1900));
        $third = self::sending(4, Run::SENDING, ['cursor' => '2', 'failed' => '[0]'], self::NOW - 1000);

        self::assertSame(['total' => 12, 'sent' => 6, 'failed' => 2, 'skipped' => 1, 'uncertain' => 1, 'date' => self::NOW - 1900], self::campaign()->counts());
        self::assertSame([$first, $second, $third], self::runIds(), 'the oldest first');
        self::assertSame(Run::SENDING, self::campaign()->state());
    }

    public function test_of_ignoresRunsOfAnotherList(): void
    {
        $mine = self::sending(2, Run::DONE, ['cursor' => '2']);
        self::sending(3, Run::SENDING, [], self::NOW - 500, self::OTHER);
        self::sending(3, Run::SENDING, [], self::NOW - 500, self::LIST, 'cafe0000000000000000000000000002');

        self::assertSame([$mine], self::runIds());
        self::assertSame(Run::DONE, self::campaign()->state());
        self::assertNull(self::campaign()->active());
        self::assertSame(self::readers(2), self::campaign()->pending(self::letter(0, 1, 2)));
        self::assertSame(2, self::campaign()->counts()['total']);
    }

    public function test_start_storesTheTitleOfTheLetter(): void
    {
        $letter        = self::letter(0);
        $letter->title = 'Spring news';

        $run = self::campaign()->start($letter, self::USER);

        self::assertSame('Spring news', self::stored((string) $run->getId())['title']);
    }
}
