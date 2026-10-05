<?php

declare(strict_types=1);

/**
 * BatchRun from outside its steps: End, Continue, the sealed secret, the progress page, a run that
 * hangs, and the lists and cleanup of the runs of a subject.
 */

namespace Tests\Unit\Later;

use AA\IO\DB\DB_AA;
use AA\Later\BatchFailed;
use AA\Later\BatchPosition;
use AA\Later\BatchRun;
use AA\Later\BatchStep;
use AA\Later\BatchStepResult;
use AA\Later\Toexecute;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Later\Fixtures\QueueRefusingDb;
use Tests\Unit\Later\Fixtures\ScriptedBatchRun;

require_once __DIR__ . '/Fixtures/BatchRunTables.php';

final class BatchRunControlTest extends TestCase
{
    use BatchRunTables;

    private const SECRET = 'tok-Zq8vW2pLx9Rb4Kc7';

    /** $do on the run locked in a transaction, as the users of the core change it */
    private static function locked(string $id, \Closure $do): mixed
    {
        return DB_AA::transaction(static fn() => $do(ScriptedBatchRun::lockForUpdate($id) ?? throw new LogicException('No run')));
    }

    private static function fresh(string $id): ScriptedBatchRun
    {
        return ScriptedBatchRun::get($id) ?? throw new LogicException('No run');
    }

    /** @return list<string> what the queued rows expect */
    private static function queued(): array
    {
        return array_map(static fn(array $row): string => self::stepOf($row)->expected, self::queue());
    }

    /** a run whose step works right now: a live lease and its token */
    private static function leased(string $id): void
    {
        self::storedSet($id, 'lease', (string) (self::NOW + 100));
        self::storedSet($id, 'lease_tok', 'working-step');
    }

    /** the arguments in the trace of $e, but of PHPUnit's own frames - they hold the whole test run */
    private static function traceArgs(\Throwable $e): string
    {
        $frames = array_filter($e->getTrace(), static fn(array $f): bool => !str_starts_with($f['class'] ?? '', 'PHPUnit\\'));
        return print_r(array_column($frames, 'args'), true);
    }

    /** $do with the arguments of every frame in the trace of an exception */
    private static function withTraceArgs(\Closure $do): void
    {
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            $do();
        } finally {
            ini_set('zend.exception_ignore_args', (string) $old);
        }
    }

    /** every property of the record is one row - update() of a name given twice writes it twice */
    private static function assertOneRowEach(string $id): void
    {
        $props = array_column(array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] === $id), 'property');
        self::assertSame([], array_keys(array_filter(array_count_values($props), static fn(int $n): bool => $n > 1)), 'properties stored twice');
    }

    // ---------------------------------------------------------------- End

    public function test_end_from_active_keeps_lease_cancels_queue_deletes_secret(): void
    {
        $id = self::start()->getId();
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 3600));
        self::leased($id);
        (new Toexecute())->later(new BatchStep(ScriptedBatchRun::class, $id, 'older'), [], 'Scripted_' . $id, 5, self::NOW + 999);

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));

        $stored = self::stored($id);
        $this->assertSame('ended', $stored['state']);
        $this->assertSame((string) self::NOW, $stored['finished']);
        $this->assertSame(['working-step', (string) (self::NOW + 100)], [$stored['lease_tok'], $stored['lease']], 'the lease stays until it runs out');
        $this->assertSame(['', ''], [$stored['sealed'], $stored['sealed_until']], 'the secret goes with the run');
        $this->assertSame([BatchRun::CLOSE_ROW], self::queued(), 'no step of the run is left, only the row that closes it');
        $this->assertSame('BatchClose_' . $id, self::queue()[0]['selector']);
        self::assertOneRowEach($id);
        $this->assertSame([], ScriptedBatchRun::hooks(), 'afterClose() runs after the commit, not inside');

        $this->assertSame('closed', self::runQueued());
        $this->assertSame(['afterClose'], ScriptedBatchRun::hooks());
        $this->assertSame('ended', ScriptedBatchRun::call('afterClose')['arg']);
        $this->assertFalse(ScriptedBatchRun::call('afterClose')['tx']);
    }

    /** @return array<string, array{string}> */
    public static function endableStates(): array
    {
        return ['error' => ['error'], 'an own state' => ['preview']];
    }

    #[DataProvider('endableStates')]
    public function test_end_from_error_and_own_state(string $state): void
    {
        $id = ($state === 'preview') ? self::start('preview')->getId() : self::start()->getId();
        self::storedSet($id, 'state', $state);

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));

        $this->assertSame('ended', self::stored($id)['state']);
    }

    public function test_end_denied_without_may_control(): void
    {
        $id = self::start()->getId();
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->end('uid-2'));
            $this->fail('Another user ended the run');
        } catch (BatchFailed $e) {
            $this->assertSame('FORBIDDEN', $e->reason);
        }
        $this->assertSame('running', self::stored($id)['state']);
        $this->assertCount(1, self::queue());
    }

    #[DataProvider('finishedStates')]
    public function test_end_of_finished_run_refused(string $state): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'state', $state);
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));
            $this->fail("A $state run was ended");
        } catch (BatchFailed $e) {
            $this->assertSame('WRONG_STATE', $e->reason);
        }
        $this->assertSame($state, self::stored($id)['state']);
        $this->assertSame([], ScriptedBatchRun::hooks());
    }

    public function test_end_clears_next_at(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'next_at', (string) (self::NOW + 1800));

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));

        $this->assertSame('', self::stored($id)['next_at']);
        $this->assertSame(0, self::fresh($id)->progress(self::USER)['next_at']);
    }

    public function test_end_close_row_waits_for_a_live_lease(): void
    {
        $id = self::start()->getId();
        self::leased($id);

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));

        $this->assertSame([BatchRun::CLOSE_ROW], self::queued());
        $this->assertSame(self::NOW + 101, self::queue()[0]['execute_after'], 'afterClose() not before the step may have written its last');
    }

    public function test_end_queues_its_close_row_before_writing_the_state(): void
    {
        $id                  = self::start()->getId();
        $GLOBALS['spareDBs'] = [new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb(), new QueueRefusingDb()];

        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));
            $this->fail('The run ended without its close row');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('queue did not take', $e->getMessage());
        }
        $this->assertSame(1, QueueRefusingDb::$refused, 'the queue ran the row at once');
        $this->assertSame([], ScriptedBatchRun::hooks(), 'it found the run not ended - no afterClose() in a transaction that rolls back');
    }

    public function test_end_outside_lock_throws(): void
    {
        $run = self::start();
        $this->expectException(LogicException::class);
        self::fresh($run->getId())->end(self::USER);
    }

    public function test_end_note_is_kept_for_the_progress_page(): void
    {
        $id = self::start()->getId();

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER, 'SETTINGS_CHANGED'));

        $this->assertSame('SETTINGS_CHANGED', self::fresh($id)->progress(self::USER)['note']);
    }

    public function test_end_note_is_a_code(): void
    {
        $id = self::start()->getId();
        $this->expectException(InvalidArgumentException::class);
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER, 'changed by <b>me</b>'));
    }

    public function test_end_during_work_step_writes_nothing(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [static function () use ($id): BatchStepResult {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));
            return new BatchStepResult(['n' => 1], false, ['read' => 5], extra: ['state' => 'of the step']);
        }];

        $this->assertSame('run ended', self::runQueued());

        $stored = self::stored($id);
        $this->assertSame('ended', $stored['state']);
        $this->assertSame(['{"n":0}', '0', ''], [$stored['position'], $stored['step'], $stored['counts']], 'the end of the step wrote nothing');
        $this->assertNotContains('commitStep', ScriptedBatchRun::hooks());
        $this->assertSame([BatchRun::CLOSE_ROW], self::queued(), 'no next step');

        self::runQueued();
        $this->assertSame(['work', 'afterClose'], ScriptedBatchRun::hooks(), 'afterClose(ended) once');
    }

    public function test_end_during_final_phase_never_done(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script       = [new BatchStepResult(['n' => 1], true)];
        ScriptedBatchRun::$onFinalCheck = static function () use ($id): void {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));
        };

        self::runQueued();
        self::runQueued();      // the close row, the safety row of the final phase is gone with the run's rows

        $this->assertSame('ended', self::stored($id)['state']);
        $this->assertSame([], self::queue());
        $this->assertSame(['work', 'commitStep', 'finalCheck', 'afterClose'], ScriptedBatchRun::hooks());
        $this->assertSame('ended', ScriptedBatchRun::call('afterClose')['arg']);
    }

    public function test_close_row_of_a_run_not_ended_does_nothing(): void
    {
        $run = self::start();
        TableDb::$tables['toexecute'] = [];
        (new Toexecute())->later(new BatchStep(ScriptedBatchRun::class, $run->getId(), BatchRun::CLOSE_ROW), [], 'x', 5, self::NOW);

        $this->assertSame('stale', self::runQueued());
        $this->assertSame([], ScriptedBatchRun::hooks());
    }

    // ---------------------------------------------------------------- Continue

    public function test_resume_from_error_queues_now_resets_failures(): void
    {
        $id = self::start()->getId();
        TableDb::$tables['toexecute'] = [];
        foreach (['state' => 'error', 'error_code' => 'TRANSPORT', 'error_path' => 'stream 3', 'error_detail' => 'dns', 'failures' => '3'] as $prop => $value) {
            self::storedSet($id, $prop, $value);
        }

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $stored = self::stored($id);
        $this->assertSame(['running', '0', '', '', '', '1'], [$stored['state'], $stored['failures'], $stored['error_code'], $stored['error_path'], $stored['error_detail'], $stored['step']]);
        self::assertOneRowEach($id);
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 0])], self::queued());
        $this->assertSame(self::NOW, self::queue()[0]['execute_after']);

        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false)];
        $this->assertSame('step 2', self::runQueued());
    }

    public function test_resume_goes_on_from_the_position_of_the_hook(): void
    {
        $id = self::start()->getId();
        TableDb::$tables['toexecute'] = [];
        foreach (['state' => 'error', 'error_code' => 'AUTH', 'failures' => '1'] as $prop => $value) {
            self::storedSet($id, $prop, $value);
        }
        ScriptedBatchRun::$onResume = static fn(array $position): array => ['n' => 7] + $position;

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $call = ScriptedBatchRun::call('positionOnResume');
        $this->assertSame([['n' => 0], true], [$call['arg'], $call['locked']], 'the stored position, under the lock');
        $this->assertSame(['n' => 7], self::fresh($id)->position());
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 7])], self::queued(), 'the step queued for it');
    }

    public function test_resume_from_final_check_error_repeats_final_phase(): void
    {
        $id = self::start()->getId();
        TableDb::$tables['toexecute'] = [];
        foreach (['state' => 'error', 'error_code' => 'FINAL_CHECK', 'closing' => '1', 'failures' => '3'] as $prop => $value) {
            self::storedSet($id, $prop, $value);
        }

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $this->assertSame((string) self::NOW, self::stored($id)['next_at'], 'its step is due');
        $this->assertSame('done', self::runQueued());
        $this->assertSame(['finalCheck', 'afterClose'], ScriptedBatchRun::hooks());
        $this->assertSame('', self::stored($id)['next_at'], 'a done run waits for nothing');
    }

    public function test_done_run_has_no_next_at(): void
    {
        $id                       = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false), new BatchStepResult(['n' => 2], true)];
        self::runQueued();
        $this->assertSame((string) self::NOW, self::stored($id)['next_at'], 'the next step is due now');

        $this->assertSame('done', self::runQueued());

        $this->assertSame('', self::stored($id)['next_at']);
        self::assertOneRowEach($id);
    }

    public function test_resume_while_waiting_for_retry_try_now(): void
    {
        $id                       = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT', retryDetail: 'dns')];
        self::runQueued();
        $this->assertSame((string) (self::NOW + 1800), self::stored($id)['next_at'], 'waits for the retry');

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $stored = self::stored($id);
        $this->assertSame(['running', '0', (string) self::NOW, '2'], [$stored['state'], $stored['failures'], $stored['next_at'], $stored['step']], 'due now');
        $this->assertSame(['', ''], [$stored['retry_code'], $stored['error_detail']], 'the failed try is forgotten');
        $this->assertNull(self::fresh($id)->progress(self::USER)['error']);
        self::assertOneRowEach($id);
        $this->assertSame([BatchPosition::fingerprint(2, ['n' => 0])], self::queued(), 'the waiting row is gone');
        $this->assertSame(self::NOW, self::queue()[0]['execute_after']);
    }

    public function test_resume_of_stuck(): void
    {
        $id = self::start()->getId();
        TableDb::$tables['toexecute'] = [];          // its row got lost
        self::storedSet($id, 'lease', (string) (self::NOW - 5000));
        self::storedSet($id, 'lease_tok', 'died-long-ago');
        ScriptedBatchRun::$now = self::NOW + 9001;
        $this->assertTrue(self::fresh($id)->isStuck(ScriptedBatchRun::$now));

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $stored = self::stored($id);
        $this->assertSame('', $stored['lease'], 'a dead step\'s lease is no failure once the user goes on');
        $this->assertNotSame('died-long-ago', $stored['lease_tok'], 'nor may that step write any more');
        $this->assertFalse(self::fresh($id)->hasLiveLease(ScriptedBatchRun::$now));
        $this->assertCount(1, self::queue());
        $this->assertFalse(self::fresh($id)->isStuck(ScriptedBatchRun::$now), 'its step is due now');
        $this->assertSame([], ScriptedBatchRun::stuck(ScriptedBatchRun::$now));
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
            $this->fail('A second Continue doubled the step');
        } catch (BatchFailed $e) {
            $this->assertSame('WRONG_STATE', $e->reason);
        }
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false)];
        $this->assertSame('step 2', self::runQueued());
        $this->assertSame('0', self::stored($id)['failures']);
    }

    /** a step that died while holding its lease: the queue repeats its row only after its own lease */
    private static function deadStep(string $id, int $failures): void
    {
        self::storedSet($id, 'lease', (string) (self::NOW - BatchRun::DEAD_GRACE));
        self::storedSet($id, 'lease_tok', 'dead-step');
        self::storedSet($id, 'failures', (string) $failures);
        self::storedSet($id, 'next_at', (string) (self::NOW - 100));
        TableDb::$tables['toexecute'][0]['execute_after'] = self::NOW + Toexecute::CLAIM_LEASE - 100;
    }

    /** Continue does at once what the queue would do half an hour later - the dead step still counts */
    public function test_resume_of_a_dead_step_tries_now_keeping_failures(): void
    {
        $id = self::start()->getId();
        self::deadStep($id, 1);
        $this->assertTrue(self::fresh($id)->mayResume(self::NOW));

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $stored = self::stored($id);
        $this->assertSame(['running', '1', '1', (string) self::NOW], [$stored['state'], $stored['failures'], $stored['step'], $stored['next_at']], 'the failures stay');
        $this->assertNotSame('dead-step', $stored['lease_tok'], 'that step may not write any more');
        $this->assertFalse(self::fresh($id)->hasLiveLease(self::NOW));
        self::assertOneRowEach($id);
        $this->assertSame([BatchPosition::fingerprint(1, ['n' => 0])], self::queued(), 'the dead step\'s row is gone');
        $this->assertSame(self::NOW, self::queue()[0]['execute_after']);

        $seen = [];
        ScriptedBatchRun::$script = [static function () use ($id, &$seen): BatchStepResult {
            $seen = self::stored($id);
            return new BatchStepResult(['n' => 1], false);
        }];
        $this->assertSame('step 2', self::runQueued());
        $this->assertSame('2', $seen['failures'], 'the claim counts the dead step');
        $this->assertSame('0', self::stored($id)['failures'], 'progress clears them');
    }

    public function test_resume_of_a_dead_step_spends_the_last_try(): void
    {
        $id = self::start()->getId();
        self::deadStep($id, 2);

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $this->assertSame('error STEP_DIED', self::runQueued(), 'no endless retry by clicking Continue');
        $this->assertSame(['onRetriesExhausted', 'commitStep'], ScriptedBatchRun::hooks(), 'commitStep() gets the made-up result of the dead step');
    }

    /** once Continue queued the step, a second one would only double it - until that step dies too */
    public function test_resume_of_a_dead_step_once(): void
    {
        $id = self::start()->getId();
        self::deadStep($id, 0);
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $this->assertFalse(self::fresh($id)->mayResume(self::NOW));
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
            $this->fail('A second Continue doubled the step');
        } catch (BatchFailed $e) {
            $this->assertSame('WRONG_STATE', $e->reason);
        }
        $this->assertCount(1, self::queue());
    }

    /** a step may still work a while past its lease - a new step beside it would store its items twice */
    public function test_resume_of_a_step_within_its_grace_refused(): void
    {
        $id = self::start()->getId();
        self::deadStep($id, 1);
        foreach ([self::NOW - BatchRun::DEAD_GRACE + 1, self::NOW + 1] as $lease) {
            self::storedSet($id, 'lease', (string) $lease);
            $this->assertFalse(self::fresh($id)->hasDeadStep(self::NOW));
            $this->assertTrue(self::fresh($id)->stepMayStillWork(self::NOW));
            $this->assertFalse(self::fresh($id)->mayResume(self::NOW));
            try {
                self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
                $this->fail('Continue doubled a step that may still work');
            } catch (BatchFailed $e) {
                $this->assertSame('LEASE', $e->reason);
            }
        }
        $this->assertSame('dead-step', self::stored($id)['lease_tok']);
        $this->assertSame([self::NOW + Toexecute::CLAIM_LEASE - 100], array_column(self::queue(), 'execute_after'));
    }

    /** in error or waiting for its retry, but a step claimed it a moment ago: Continue waits for the grace too */
    #[DataProvider('errorOrWaiting')]
    public function test_resume_while_a_claimed_step_may_still_work_refused(string $state, string $nextAt): void
    {
        $id = self::start()->getId();
        self::deadStep($id, 1);
        self::storedSet($id, 'state', $state);
        self::storedSet($id, 'next_at', $nextAt);
        self::storedSet($id, 'lease', (string) (self::NOW - BatchRun::DEAD_GRACE + 1));
        $before = self::stored($id);
        $this->assertFalse(self::fresh($id)->mayResume(self::NOW));

        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
            $this->fail('Continue started a step beside one that may still work');
        } catch (BatchFailed $e) {
            $this->assertSame('LEASE', $e->reason);
        }
        $this->assertSame($before, self::stored($id));

        self::storedSet($id, 'lease', (string) (self::NOW - BatchRun::DEAD_GRACE));
        $this->assertTrue(self::fresh($id)->mayResume(self::NOW));
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
        $this->assertSame('running', self::stored($id)['state']);
    }

    /** @return array<string, array{string, string}> */
    public static function errorOrWaiting(): array
    {
        return ['error' => ['error', ''], 'waiting' => ['running', (string) (self::NOW + 600)]];
    }

    /** the token of a dead step in a run that is no longer active: nothing to try again */
    #[DataProvider('notActive')]
    public function test_resume_of_a_dead_token_in_a_run_not_active_refused(string $state): void
    {
        $id = self::start()->getId();
        self::deadStep($id, 1);
        self::storedSet($id, 'state', $state);
        $before = self::stored($id);
        $this->assertFalse(self::fresh($id)->hasDeadStep(self::NOW));
        $this->assertFalse(self::fresh($id)->mayResume(self::NOW));

        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
            $this->fail("A $state run was resumed");
        } catch (BatchFailed $e) {
            $this->assertSame('WRONG_STATE', $e->reason);
        }
        $this->assertSame($before, self::stored($id));
    }

    /** @return array<string, array{string}> */
    public static function notActive(): array
    {
        return ['ended' => ['ended'], 'done' => ['done'], 'own state' => ['preview']];
    }

    /** a hand-run Execute may claim the step before its retry is due; when that one dies, it counts too */
    public function test_resume_of_a_dead_step_claimed_before_its_retry_counts(): void
    {
        $id = self::start()->getId();
        self::deadStep($id, 1);
        self::storedSet($id, 'next_at', (string) (self::NOW + 600));
        $this->assertTrue(self::fresh($id)->isWaiting(self::NOW));
        $this->assertTrue(self::fresh($id)->continueKeepsCount(self::NOW));

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));

        $this->assertSame('1', self::stored($id)['failures']);
        $seen = [];
        ScriptedBatchRun::$script = [static function () use ($id, &$seen): BatchStepResult {
            $seen = self::stored($id);
            return new BatchStepResult(['n' => 1], false);
        }];
        self::runQueued();
        $this->assertSame('2', $seen['failures'], 'the claim counts the dead step');
    }

    /** a hanging run's dead step is forgiven: hours passed, the user starts afresh */
    public function test_continueKeepsCount_not_when_stuck(): void
    {
        $id = self::start()->getId();
        self::deadStep($id, 2);
        self::storedSet($id, 'next_at', '');
        TableDb::$tables['toexecute'] = [];
        $at = self::NOW + 9001;
        $this->assertTrue(self::fresh($id)->isStuck($at));
        $this->assertTrue(self::fresh($id)->hasDeadStep($at));
        $this->assertFalse(self::fresh($id)->continueKeepsCount($at));
    }

    public function test_resume_of_a_run_on_its_way_refused(): void
    {
        $id = self::start()->getId();
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
            $this->fail('A queued run was resumed');
        } catch (BatchFailed $e) {
            $this->assertSame('WRONG_STATE', $e->reason);
        }
        self::leased($id);
        self::storedSet($id, 'next_at', (string) (self::NOW + 600));
        $this->expectException(BatchFailed::class);
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
    }

    public function test_resume_reseals_secret(): void
    {
        $id = self::start()->getId();
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal('old-token', self::NOW + 60));
        self::storedSet($id, 'state', 'error');
        self::storedSet($id, 'error_code', 'SOURCE_LOGIN');

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER, self::SECRET, self::NOW + 7200));

        $this->assertSame(self::SECRET, self::fresh($id)->secret());
        $this->assertSame((string) (self::NOW + 7200), self::stored($id)['sealed_until']);
    }

    public function test_resume_secret_needs_its_time(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'state', 'error');
        $this->expectException(InvalidArgumentException::class);
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER, self::SECRET));
    }

    /** @return array<string, array{string}> */
    public static function finishedStates(): array
    {
        return ['done' => ['done'], 'ended' => ['ended']];
    }

    #[DataProvider('finishedStates')]
    public function test_resume_denied_from_done(string $state): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'state', $state);
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
            $this->fail("A $state run was resumed");
        } catch (BatchFailed $e) {
            $this->assertSame('WRONG_STATE', $e->reason);
        }
        $this->assertSame($state, self::stored($id)['state']);
    }

    public function test_resume_denied_without_may_control(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'state', 'error');
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume('uid-2'));
            $this->fail('Another user resumed the run');
        } catch (BatchFailed $e) {
            $this->assertSame('FORBIDDEN', $e->reason);
        }
        $this->assertSame('error', self::stored($id)['state']);
    }

    // ---------------------------------------------------------------- the sealed secret

    public function test_seal_unseal_round_trip(): void
    {
        $id = self::start()->getId();

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));

        $this->assertSame(self::SECRET, self::fresh($id)->secret());
        $this->assertSame((string) (self::NOW + 60), self::stored($id)['sealed_until']);
        ScriptedBatchRun::$now = self::NOW + 60;
        $this->assertNull(self::fresh($id)->secret(), 'not past its time');
    }

    public function test_seal_secret_never_stored_in_plain(): void
    {
        $id = self::start()->getId();

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));

        $this->assertNotSame('', self::stored($id)['sealed']);
        $this->assertStringNotContainsString(self::SECRET, serialize(TableDb::$tables), 'no row holds it');
        $this->assertStringNotContainsString(self::SECRET, implode("\n", TableDb::$log), 'no statement carried it');
    }

    public function test_unseal_of_a_sealed_value_moved_to_another_run_fails(): void
    {
        $a = self::start()->getId();
        $b = self::start()->getId();
        self::locked($a, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));
        self::storedSet($b, 'sealed', self::stored($a)['sealed']);
        self::storedSet($b, 'sealed_until', self::stored($a)['sealed_until']);

        $this->assertNull(self::fresh($b)->secret());
    }

    public function test_unseal_with_its_time_moved_fails(): void
    {
        $id = self::start()->getId();
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));
        self::storedSet($id, 'sealed_until', (string) (self::NOW + 99999));

        $this->assertNull(self::fresh($id)->secret());
    }

    public function test_seal_outside_lock_throws(): void
    {
        $run = self::start();
        $this->expectException(LogicException::class);
        self::fresh($run->getId())->seal(self::SECRET, self::NOW + 60);
    }

    public function test_seal_of_a_finished_run_throws(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'state', 'done');
        $this->expectException(LogicException::class);
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));
    }

    public function test_seal_longer_than_max_refused(): void
    {
        $id = self::start()->getId();
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 86400));
        $this->expectException(InvalidArgumentException::class);
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 86401));
    }

    public function test_resume_secret_longer_than_max_refused(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'state', 'error');
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER, self::SECRET, self::NOW + 86401));
            $this->fail('A secret was sealed for longer than allowed');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(['error', ''], [self::stored($id)['state'], self::stored($id)['sealed']]);
    }

    public function test_unseal_of_a_flipped_bit_fails(): void
    {
        $id = self::start()->getId();
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));
        $sealed = self::stored($id)['sealed'];
        $last   = strlen($sealed) - 1;
        // a base64url character other than the last one changes the bytes the tag covers
        $sealed[$last - 4] = ($sealed[$last - 4] === 'A') ? 'B' : 'A';
        self::storedSet($id, 'sealed', $sealed);

        $this->assertNull(self::fresh($id)->secret());
    }

    public function test_unseal_with_a_failing_key_is_null(): void
    {
        $id = self::start()->getId();
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));

        ScriptedBatchRun::$hookValues['sealPurpose'] = new \RuntimeException('no key');
        $this->assertNull(self::fresh($id)->secret(), 'the key cannot be made');
        ScriptedBatchRun::$hookValues['sealPurpose'] = 'another-purpose';
        $this->assertNull(self::fresh($id)->secret(), 'another key');
    }

    public function test_resume_keeps_the_secret_out_of_the_trace(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'state', 'error');
        self::withTraceArgs(function () use ($id): void {
            try {
                self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER, self::SECRET, self::NOW + 86401));
                $this->fail('A secret was sealed for longer than allowed');
            } catch (InvalidArgumentException $e) {
                $resume = array_values(array_filter($e->getTrace(), static fn(array $f): bool => ($f['function'] ?? '') === 'resume'))[0] ?? [];
                $this->assertInstanceOf(\SensitiveParameterValue::class, $resume['args'][1] ?? null, 'the trace keeps arguments, the secret one is hidden');
                $this->assertStringNotContainsString(self::SECRET, self::traceArgs($e));
            }
        });
    }

    public function test_seal_failure_drops_the_cause_and_keeps_the_secret_out_of_the_trace(): void
    {
        $id                                          = self::start()->getId();
        ScriptedBatchRun::$hookValues['sealPurpose'] = new \RuntimeException('cause with ' . self::SECRET);
        $old                                         = ini_set('zend.exception_ignore_args', '0');
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));
            $this->fail('A secret was sealed without its key');
        } catch (\RuntimeException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage() . self::traceArgs($e));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $old);
        }
        $this->assertSame('', self::stored($id)['sealed']);
    }

    public function test_seal_refusal_keeps_the_secret_out_of_the_trace(): void
    {
        $id  = self::start()->getId();
        $old = ini_set('zend.exception_ignore_args', '0');
        try {
            self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW - 1));
            $this->fail('A secret was sealed past its time');
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString(self::SECRET, self::traceArgs($e));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $old);
        }
    }

    public function test_cleanup_forgets_secret_past_sealed_until(): void
    {
        $old  = self::start()->getId();
        $live = self::start()->getId();
        self::locked($old, static fn(ScriptedBatchRun $r) => $r->seal('old-token', self::NOW + 60));
        self::locked($live, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 6000));
        ScriptedBatchRun::$now = self::NOW + 61;

        ScriptedBatchRun::cleanup(str_repeat('b', 32), 20);

        $this->assertSame(['', ''], [self::stored($old)['sealed'], self::stored($old)['sealed_until']], 'of any subject');
        $this->assertSame(self::SECRET, self::fresh($live)->secret());
        self::assertOneRowEach($old);
    }

    public function test_cleanup_checks_the_secret_again_under_the_lock(): void
    {
        $id = self::start()->getId();
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));
        ScriptedBatchRun::$now = self::NOW + 61;
        // Continue sealed a new secret between the read and the lock
        TableDb::$answer = static function (string $sql) use ($id): ?array {
            if (str_contains($sql, 'FOR UPDATE')) {
                TableDb::$answer = null;
                self::storedSet($id, 'sealed_until', (string) (self::NOW + 3600));
            }
            return null;
        };

        ScriptedBatchRun::cleanup(self::SUBJECT, 20);

        $this->assertNotSame('', self::stored($id)['sealed']);
    }

    public function test_start_leaves_other_runs_alone(): void
    {
        $id = self::start()->getId();
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->seal(self::SECRET, self::NOW + 60));
        ScriptedBatchRun::$now = self::NOW + 61;
        $log                   = count(TableDb::$log);

        self::start();

        $this->assertNotSame('', self::stored($id)['sealed'], 'its secret waits for cleanup()');
        $this->assertSame([], array_filter(array_slice(TableDb::$log, $log), static fn(string $sql): bool => str_contains($sql, 'FOR UPDATE')), 'no lock on another run inside the caller\'s transaction');
    }

    public function test_leasedOf_lists_runs_whose_step_may_still_work(): void
    {
        $working = self::start()->getId();
        $ended   = self::start()->getId();
        $out     = self::start()->getId();
        $never   = self::start()->getId();
        self::leased($working);
        self::leased($ended);
        self::storedSet($ended, 'state', 'ended');
        self::leased($out);
        self::storedSet($out, 'lease', (string) self::NOW);   // runs out this second
        self::storedSet($never, 'lease', (string) (self::NOW + 100));   // no token: no step holds it
        $foreign = DB_AA::transaction(static fn() => ScriptedBatchRun::start(['subject' => '6a6a6a6a6a6a6a6a6a6a6a6a6a6a6a6a', 'user' => self::USER, 'position' => []]))->getId();
        self::leased($foreign);
        $log = count(TableDb::$log);

        $this->assertEqualsCanonicalizing([$working, $ended], ScriptedBatchRun::leasedOf(self::SUBJECT));
        $this->assertCount(2, array_slice(TableDb::$log, $log), 'the ids, then the leases of all runs at once - no run is loaded whole');
    }

    public function test_cleanup_inside_a_transaction_throws(): void
    {
        $this->expectException(LogicException::class);
        DB_AA::transaction(static fn() => ScriptedBatchRun::cleanup(self::SUBJECT, 20));
    }

    public function test_start_calls_expire_extra(): void
    {
        ScriptedBatchRun::$now = self::NOW + 5;

        self::start();

        $this->assertSame([self::NOW + 5], ScriptedBatchRun::$expiredAt);
    }

    // ---------------------------------------------------------------- the progress page

    public function test_progress_is_plain_jsonable(): void
    {
        $id                             = self::start()->getId();
        ScriptedBatchRun::$progressExtra = ['found' => ['a', 'b']];
        ScriptedBatchRun::$script        = [new BatchStepResult(['n' => 1], false, ['read' => 7], ['JOB_FAILED' => 1], label: 'stream 1 of 3', done: 1, total: 3)];
        self::runQueued();

        $progress = self::fresh($id)->progress(self::USER);

        $this->assertIsString(json_encode($progress, JSON_THROW_ON_ERROR));
        $this->assertSame([
            'role'     => 'active',
            'state'    => 'running',
            'label'    => 'stream 1 of 3',
            'done'     => 1,
            'total'    => 3,
            'moved'    => self::NOW,
            'started'  => self::NOW,
            'running'  => false,
            'next_at'  => self::NOW,
            'failures' => 0,
            'counts'   => ['read' => 7],
            'warnings' => ['JOB_FAILED' => 1],
            'error'    => null,
            'note'     => '',
            'warning'  => '',
            'extra'    => ['found' => ['a', 'b']],
        ], $progress);
    }

    public function test_progress_shows_a_working_step_and_a_wait(): void
    {
        $id = self::start()->getId();
        self::leased($id);
        $this->assertTrue(self::fresh($id)->progress(self::USER)['running']);

        self::storedSet($id, 'lease_tok', '');
        self::storedSet($id, 'next_at', (string) (self::NOW + 1800));
        self::storedSet($id, 'failures', '2');
        $progress = self::fresh($id)->progress(self::USER);
        $this->assertSame([false, self::NOW + 1800, 2], [$progress['running'], $progress['next_at'], $progress['failures']]);
    }

    public function test_progress_shows_the_error(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchFailed('SOURCE_LOGIN', 'stream 2')];
        self::runQueued();

        $progress = self::fresh($id)->progress(self::USER);

        $this->assertSame(['error', 'error'], [$progress['role'], $progress['state']]);
        $this->assertSame(['code' => 'SOURCE_LOGIN', 'where' => 'stream 2', 'detail' => ''], $progress['error']);
    }

    // a source down shows why also between the tries, not only once they are spent
    public function test_progress_shows_the_failed_try_while_it_waits(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT', retryDetail: 'dns')];
        self::runQueued();

        $progress = self::fresh($id)->progress(self::USER);

        $this->assertSame(['active', 1], [$progress['role'], $progress['failures']]);
        $this->assertSame(['code' => 'TRANSPORT', 'where' => '', 'detail' => 'dns'], $progress['error']);
    }

    // an ended run did not stop by an error: the try that failed before the end is no error of it
    public function test_progress_of_a_run_ended_while_it_waited(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT', retryDetail: 'dns')];
        self::runQueued();

        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));

        $this->assertSame('TRANSPORT', self::stored($id)['retry_code']);
        $this->assertNull(self::fresh($id)->progress(self::USER)['error']);
    }

    /** what is read back is shown: a value of no allowed shape - a damaged or forged record - is dropped */
    public function test_progress_drops_a_stored_code_or_detail_of_no_allowed_shape(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: 'TRANSPORT', retryDetail: 'dns')];
        self::runQueued();

        self::storedSet($id, 'error_detail', '<img src=x onerror=alert(1)>');
        $this->assertSame(['code' => 'TRANSPORT', 'where' => '', 'detail' => ''], self::fresh($id)->progress(self::USER)['error']);

        self::storedSet($id, 'retry_code', 'https://x.example/?token=t');
        $this->assertNull(self::fresh($id)->progress(self::USER)['error']);
    }

    public function test_progress_shows_the_detail_of_the_error(): void
    {
        $id = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchFailed('SOURCE', 'stream 2', 'http:404')];
        self::runQueued();

        $this->assertSame(['code' => 'SOURCE', 'where' => 'stream 2', 'detail' => 'http:404'], self::fresh($id)->progress(self::USER)['error']);
    }

    // a run stored before the detail existed has none
    public function test_progress_of_an_old_error_without_detail(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'state', 'error');
        self::storedSet($id, 'error_code', 'TRANSPORT');
        $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => !(($r['object_id'] === $id) && in_array($r['property'], ['error_detail', 'retry_code'], true)));
        TableDb::$tables['object_text'] = array_values($rows);

        $this->assertSame(['code' => 'TRANSPORT', 'where' => '', 'detail' => ''], self::fresh($id)->progress(self::USER)['error']);
    }

    public function test_progress_denied_without_may_control(): void
    {
        $id = self::start()->getId();
        $this->expectException(BatchFailed::class);
        self::fresh($id)->progress('uid-2');
    }

    public function test_progress_shows_after_close_failure(): void
    {
        $id                                = self::start()->getId();
        ScriptedBatchRun::$afterCloseThrows = new \RuntimeException('hook failed');
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));

        self::runQueued();

        $this->assertSame('after_close', self::fresh($id)->progress(self::USER)['warning']);
    }

    // ---------------------------------------------------------------- a run that hangs

    public function test_is_stuck_counts_from_the_last_progress_or_step_due(): void
    {
        $id                       = self::start()->getId();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], false)];
        self::runQueued();                              // progress at NOW, the next step due at once
        TableDb::$tables['toexecute'] = [];             // and its row got lost
        self::storedSet($id, 'next_at', '');

        $run = self::fresh($id);
        $this->assertFalse($run->isStuck(self::NOW + 9000), 'three retries and an hour');
        $this->assertTrue($run->isStuck(self::NOW + 9001));
    }

    /** State Check says how long by the very time isStuck() counts from */
    public function test_idleSince_isWhatIsStuckCountsFrom(): void
    {
        $id = self::start()->getId();
        self::assertSame(self::NOW, self::fresh($id)->idleSince(), 'the start, before any progress');
        self::storedSet($id, 'moved', (string) (self::NOW + 100));
        self::assertSame(self::NOW + 100, self::fresh($id)->idleSince());
        self::storedSet($id, 'next_at', (string) (self::NOW + 2000));
        $run = self::fresh($id);
        self::assertSame(self::NOW + 2000, $run->idleSince(), 'a retry due later');
        $this->assertFalse($run->isStuck($run->idleSince() + 9000));
        $this->assertTrue($run->isStuck($run->idleSince() + 9001));
    }

    public function test_no_progress_right_after_resume_is_no_hang(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'started', (string) (self::NOW - 20000));
        TableDb::$tables['toexecute'] = [];
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->resume(self::USER));
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 0], false, retry: 'NO_PROGRESS')];

        self::runQueued();

        $this->assertSame('1', self::stored($id)['failures']);
        $this->assertFalse(self::fresh($id)->isStuck(self::NOW + 1), 'its next step is due now');
        $this->assertSame([], ScriptedBatchRun::stuck(self::NOW + 1));
    }

    /** a step claimed after hours of waiting did happen: until its lease ran out the run is not idle */
    public function test_is_stuck_counts_from_the_lease_of_a_claimed_step(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'started', (string) (self::NOW - 20000));
        self::storedSet($id, 'lease', (string) (self::NOW + 90));
        self::storedSet($id, 'lease_tok', 'claimed-late');

        $this->assertSame(self::NOW + 90, self::fresh($id)->idleSince());
        $this->assertFalse(self::fresh($id)->isStuck(self::NOW + 100), 'its step died a moment ago');
        $this->assertFalse(self::fresh($id)->isStuck(self::NOW + 90 + 9000));
        $this->assertTrue(self::fresh($id)->isStuck(self::NOW + 90 + 9001));
    }

    public function test_is_stuck_counts_from_the_start_without_progress(): void
    {
        $id = self::start()->getId();
        $this->assertFalse(self::fresh($id)->isStuck(self::NOW + 9000));
        $this->assertTrue(self::fresh($id)->isStuck(self::NOW + 9001));
    }

    public function test_is_stuck_not_while_waiting_for_retry(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'next_at', (string) (self::NOW + 20000));

        $this->assertFalse(self::fresh($id)->isStuck(self::NOW + 10000));
        self::storedSet($id, 'next_at', '');
        $this->assertTrue(self::fresh($id)->isStuck(self::NOW + 10000));
    }

    /** waiting = active, no step works, the next one due later - what Continue may hurry */
    public function test_isWaiting_onlyActiveWithoutWorkingStepAndDueLater(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'next_at', (string) (self::NOW + 60));
        $this->assertTrue(self::fresh($id)->isWaiting(self::NOW));
        $this->assertFalse(self::fresh($id)->isWaiting(self::NOW + 60), 'due now');

        self::storedSet($id, 'lease', (string) (self::NOW + 30));
        self::storedSet($id, 'lease_tok', 'working-step');
        $this->assertFalse(self::fresh($id)->isWaiting(self::NOW), 'a step works');

        self::storedSet($id, 'lease_tok', '');
        self::storedSet($id, 'state', 'error');
        $this->assertFalse(self::fresh($id)->isWaiting(self::NOW), 'not active');
    }

    public function test_is_stuck_not_while_a_step_works_nor_when_finished(): void
    {
        $id = self::start()->getId();
        self::storedSet($id, 'lease', (string) (self::NOW + 20000));
        self::storedSet($id, 'lease_tok', 'working-step');
        $this->assertFalse(self::fresh($id)->isStuck(self::NOW + 10000));

        self::storedSet($id, 'lease_tok', '');
        self::storedSet($id, 'state', 'error');
        $this->assertFalse(self::fresh($id)->isStuck(self::NOW + 10000), 'an error is no hang - it says why');
    }

    public function test_stuck_lists_only_stuck_runs(): void
    {
        $stuck = self::start()->getId();
        ScriptedBatchRun::$now = self::NOW + 5000;
        $fresh = self::start()->getId();
        self::start(null, ['subject' => str_repeat('b', 32)]);

        $found = array_map(static fn(ScriptedBatchRun $r): string => $r->getId(), ScriptedBatchRun::stuck(self::NOW + 9001));

        $this->assertSame([$stuck], $found);
        $this->assertNotContains($fresh, $found);
    }

    public function test_has_live_lease_also_after_end(): void
    {
        $id = self::start()->getId();
        $this->assertFalse(self::fresh($id)->hasLiveLease(self::NOW));
        self::leased($id);
        self::locked($id, static fn(ScriptedBatchRun $r) => $r->end(self::USER));

        $this->assertTrue(self::fresh($id)->hasLiveLease(self::NOW + 100 - 1), 'a step may still write - a new run waits');
        $this->assertFalse(self::fresh($id)->hasLiveLease(self::NOW + 100));
    }

    // ---------------------------------------------------------------- the runs of a subject

    /** a run of SUBJECT finished at $finished in $state */
    private static function finished(int $finished, string $state = 'done', string $subject = self::SUBJECT): string
    {
        ScriptedBatchRun::$now = $finished - 10;
        $id                    = self::start(null, ['subject' => $subject])->getId();
        self::storedSet($id, 'state', $state);
        self::storedSet($id, 'finished', (string) $finished);
        return $id;
    }

    public function test_unfinished_by_subject(): void
    {
        $other    = self::start(null, ['subject' => str_repeat('b', 32)])->getId();
        $running  = self::start()->getId();
        $errored  = self::start()->getId();
        $previewd = self::start('preview')->getId();
        self::storedSet($errored, 'state', 'error');
        self::finished(self::NOW + 100);

        $ids = static fn(array $runs): array => array_map(static fn(ScriptedBatchRun $r): string => $r->getId(), $runs);
        $this->assertEqualsCanonicalizing([$running, $errored, $previewd], $ids(ScriptedBatchRun::unfinished(self::SUBJECT)));
        $this->assertEqualsCanonicalizing([$other, $running, $errored, $previewd], $ids(ScriptedBatchRun::unfinished()));
    }

    public function test_cleanup_keeps_last_n_finished_all_unfinished_and_those_named(): void
    {
        $oldest  = self::finished(self::NOW + 100);
        $old     = self::finished(self::NOW + 200, 'ended');
        $newer   = self::finished(self::NOW + 300);
        $newest  = self::finished(self::NOW + 400, 'ended');
        $writing = self::finished(self::NOW + 150, 'ended');
        self::storedSet($writing, 'lease', (string) (self::NOW + 10000));
        self::storedSet($writing, 'lease_tok', 'still-writing');
        $other   = self::finished(self::NOW + 50, 'done', str_repeat('b', 32));
        $running = self::start()->getId();
        $errored = self::start()->getId();
        self::storedSet($errored, 'state', 'error');

        $foreign = str_repeat('f', 32);
        foreach (['aa_type' => 'AA_Plannedtask', 'subject' => self::SUBJECT, 'state' => 'done', 'finished' => '1'] as $prop => $value) {
            self::storedSet($foreign, $prop, $value);
        }
        $closing = self::finished(self::NOW + 120, 'running');
        self::locked($closing, static fn(ScriptedBatchRun $r) => $r->end(self::USER));
        self::storedSet($closing, 'finished', (string) (self::NOW + 120));

        $stale = self::finished(self::NOW + 110, 'ended');
        (new Toexecute())->later(new BatchStep(ScriptedBatchRun::class, $stale, BatchRun::CLOSE_ROW), [], 'BatchClose_' . $stale, 0, self::NOW);

        ScriptedBatchRun::cleanup(self::SUBJECT, 2, [$old]);

        $this->assertNull(ScriptedBatchRun::get($stale), 'a close row of priority 0 never runs - it holds nothing');
        $this->assertCount(4, self::stored($foreign), 'another object with the same subject');
        foreach ([$newer, $newest, $writing, $closing, $running, $errored, $other, $old] as $kept) {
            $this->assertNotNull(ScriptedBatchRun::get($kept), "$kept was kept");
        }
        foreach ([$oldest] as $gone) {
            $this->assertNull(ScriptedBatchRun::get($gone));
            $this->assertSame([], self::stored($gone), 'every row of it');
        }
    }

    public function test_of_subject_newest_first_limited(): void
    {
        $first  = self::finished(self::NOW + 100);
        $second = self::finished(self::NOW + 200);
        ScriptedBatchRun::$now = self::NOW + 300;
        $third  = self::start()->getId();
        self::finished(self::NOW + 400, 'done', str_repeat('b', 32));

        $ids = array_map(static fn(ScriptedBatchRun $r): string => $r->getId(), ScriptedBatchRun::ofSubject(self::SUBJECT, 2));

        $this->assertSame([$third, $second], $ids);
        $this->assertNotContains($first, $ids);
    }

    /** Runs started in one second: the later end is the newer, an unfinished run the newest, and on a
     *  full tie the id decides - in the list and in cleanup() alike. Ids are random, so they are set
     *  here: the highest one goes to the run that ended first. */
    public function test_of_subject_and_cleanup_break_ties_alike(): void
    {
        $run = static function (string $id, ?int $finished): string {
            $started = self::start()->getId();
            if ($finished !== null) {
                self::storedSet($started, 'state', 'done');
                self::storedSet($started, 'finished', (string) $finished);
            }
            foreach (TableDb::$tables['object_text'] as $i => $row) {
                if ($row['object_id'] === $started) {
                    TableDb::$tables['object_text'][$i]['object_id'] = $id;
                }
            }
            return $id;
        };
        $first   = $run(str_repeat('f', 32), self::NOW);
        $twin    = $run(str_repeat('a', 32), self::NOW + 5);
        $second  = $run(str_repeat('e', 32), self::NOW + 5);
        $running = $run(str_repeat('1', 32), null);
        TableDb::$tables['toexecute'] = [];

        $ids = static fn(): array => array_map(static fn(ScriptedBatchRun $r): string => $r->getId(), ScriptedBatchRun::ofSubject(self::SUBJECT, 10));
        $this->assertSame([$running, $second, $twin, $first], $ids());

        ScriptedBatchRun::cleanup(self::SUBJECT, 1);

        $this->assertSame([$running, $second], $ids(), 'the newest finished run of the list stays');
    }

    // ---------------------------------------------------------------- the queue has no session

    /** A queue step has no session: it neither starts one nor touches $_SESSION - here an object that
     *  throws on any access (also isset() and ??), and the step would end in error. */
    public function test_step_without_session_works(): void
    {
        $this->assertSame(PHP_SESSION_NONE, session_status(), 'the queue runs without a session');
        $had      = array_key_exists('_SESSION', $GLOBALS);
        $saved    = $GLOBALS['_SESSION'] ?? null;
        $_SESSION = new NoSession();
        self::start();
        ScriptedBatchRun::$script = [new BatchStepResult(['n' => 1], true)];
        try {
            $this->assertSame('done', self::runQueued(), 'the step and its final phase');
            $this->assertSame(PHP_SESSION_NONE, session_status(), 'nor started one');
        } finally {
            if ($had) {
                $_SESSION = $saved;
            } else {
                unset($_SESSION);
            }
        }
    }
}

/** $_SESSION of a step - any use of it is an error */
final class NoSession implements \ArrayAccess, \IteratorAggregate, \Countable
{
    public function offsetExists(mixed $offset): bool
    {
        throw new LogicException('A step read the session');
    }

    public function offsetGet(mixed $offset): mixed
    {
        throw new LogicException('A step read the session');
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('A step wrote the session');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('A step wrote the session');
    }

    public function getIterator(): \Iterator
    {
        throw new LogicException('A step read the session');
    }

    public function count(): int
    {
        throw new LogicException('A step read the session');
    }
}
