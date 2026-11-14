<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\IO\DB\DB_AA;
use AA\Mailbulk\Run;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckMailbulkRuns;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Mailbulk\Fixtures\TestRun;

require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';
require_once __DIR__ . '/../Mailbulk/Fixtures/FakeLetter.php';
require_once __DIR__ . '/../Mailbulk/Fixtures/ScriptedMailer.php';
require_once __DIR__ . '/../Mailbulk/Fixtures/TestRun.php';

/** the check over the runs of TestRun */
final class TestMailbulkRunsCheck extends CheckMailbulkRuns
{
    protected function runClass(): string
    {
        return TestRun::class;
    }
}

/**
 * CheckMailbulkRuns: a mailing of an item to a list that hangs or stopped by an error, over the records
 * of TestRun in TableDb's object_text; finished, ended and moving runs are no finding.
 */
final class CheckMailbulkRunsTest extends TestCase
{
    use BatchRunTables {
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const ITEM = 'cafe0000000000000000000000000001';
    private const LIST = 'abcde';
    private const HOUR = 3600;
    /** a run hangs this long after its last progress: an hour and the retries (1 + 5 + 15 min) */
    private const HANGS_AFTER = 3600 + 60 + 300 + 900;

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
        $this->tablesTearDown();
    }

    /** a run started three hours ago, in $state since; its id */
    private static function sending(string $state, array $props = []): string
    {
        TestRun::$now = self::NOW - 3 * self::HOUR;
        $id           = DB_AA::transaction(static fn(): string => TestRun::start($props + [
            'subject'    => self::ITEM,
            'user'       => self::USER,
            'position'   => ['i' => 0],
            'list'       => self::LIST,
            'title'      => 'Newsletter <b>11</b>',
            'recipients' => 'r1',
            'total'      => '3',
            'cursor'     => '0',
        ])->getId());
        TestRun::$now = self::NOW;
        self::storedSet($id, 'state', $state);
        self::storedSet($id, 'next_at', '0');
        return $id;
    }

    /** @param Finding[] $findings */
    private static function only(array $findings): Finding
    {
        self::assertCount(1, $findings, implode(' | ', array_map(static fn(Finding $f): string => "$f->key: $f->title", $findings)));
        return $findings[0];
    }

    public function test_types_basicCheckOfMail(): void
    {
        self::assertSame([AbstractOptimize::CHECK_BASIC], CheckMailbulkRuns::types());
        self::assertSame('mail', CheckMailbulkRuns::category());
    }

    /** @return iterable<string, array{string, int}> */
    public static function noProblem(): iterable
    {
        yield 'no run' => ['', 0];
        yield 'sending, moved a minute ago' => [Run::SENDING, 60];
        yield 'sending, idle an hour and its retries' => [Run::SENDING, self::HANGS_AFTER];
        yield 'done long ago' => [Run::DONE, 10 * self::HOUR];
        yield 'stopped long ago' => [Run::ENDED, 10 * self::HOUR];
    }

    #[DataProvider('noProblem')]
    public function test_findings_none_isOk(string $state, int $idle): void
    {
        if ($state !== '') {
            self::storedSet(self::sending($state), 'moved', (string) (self::NOW - $idle));
        }

        self::assertSame(Finding::OK, self::only((new TestMailbulkRunsCheck())->findings())->severity);
    }

    public function test_findings_stuckRun_isWarning(): void
    {
        $id = self::sending(Run::SENDING, ['cursor' => '2']);
        self::storedSet($id, 'moved', (string) (self::NOW - self::HANGS_AFTER - 1));

        $finding = self::only((new TestMailbulkRunsCheck())->findings());

        self::assertSame([Finding::WARNING, "stuck:$id", self::NOW - self::HANGS_AFTER - 1], [$finding->severity, $finding->key, $finding->since]);
        self::assertSame(['Mailing "Newsletter <b>11</b>" has not moved on for 1 hours', 'Newsletter <b>11</b>'], [$finding->title, $finding->place], 'named by its title, plain text');
        $evidence = $finding->evidence[0];
        self::assertSame([self::ITEM, self::LIST, 'Newsletter &lt;b&gt;11&lt;/b&gt;', '2 / 3'], [$evidence['Item'], $evidence['List'], $evidence['Mailing'], $evidence['Tried']], 'the item and list named, the title escaped');
        self::assertArrayNotHasKey('Error', $evidence);
        self::assertArrayNotHasKey('Note', $evidence);
    }

    public function test_findings_stuckRunWaitingForARetry_showsItsCodeAndNote(): void
    {
        $id = self::sending(Run::SENDING, ['note' => '421 <busy>']);
        self::storedSet($id, 'retry_code', Run::SMTP);
        self::storedSet($id, 'moved', (string) (self::NOW - self::HANGS_AFTER - 1));

        $evidence = self::only((new TestMailbulkRunsCheck())->findings())->evidence[0];

        self::assertSame([Run::SMTP, '421 &lt;busy&gt;'], [$evidence['Error'], $evidence['Note']]);
    }

    public function test_findings_runInError_isError(): void
    {
        $id = self::sending(Run::ERROR, ['note' => 'The relay said <550>']);
        self::storedSet($id, 'error_code', Run::SMTP);
        self::storedSet($id, 'moved', (string) (self::NOW - 600));

        $finding = self::only((new TestMailbulkRunsCheck())->findings());

        self::assertSame([Finding::ERROR, "error:$id", self::NOW - 600], [$finding->severity, $finding->key, $finding->since]);
        self::assertSame(['Mailing "Newsletter <b>11</b>" stopped by an error', 'Newsletter <b>11</b>'], [$finding->title, $finding->place]);
        $evidence = $finding->evidence[0];
        self::assertSame([self::ITEM, self::LIST, Run::SMTP, 'The relay said &lt;550&gt;'], [$evidence['Item'], $evidence['List'], $evidence['Error'], $evidence['Note']], 'the code and the note, escaped');
        self::assertStringContainsString('mail server', $finding->advice, 'advice by the code');
    }

    public function test_findings_runInErrorOfTheLetter_adviceSaysTheNote(): void
    {
        $id = self::sending(Run::ERROR, ['note' => 'The item is not published']);
        self::storedSet($id, 'error_code', Run::LETTER);

        $finding = self::only((new TestMailbulkRunsCheck())->findings());

        self::assertSame(Finding::ERROR, $finding->severity);
        self::assertStringContainsString('Note', $finding->advice);
    }

    public function test_findings_runOfAnotherClass_notRead(): void
    {
        self::storedSet((string) self::start()->getId(), 'state', Run::ERROR);

        self::assertSame(Finding::OK, self::only((new TestMailbulkRunsCheck())->findings())->severity);
    }
}
