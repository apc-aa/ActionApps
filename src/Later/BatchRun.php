<?php

declare(strict_types=1);

namespace AA\Later;

use AA;
use AA\IO\DB\DB_AA;
use AA\Util\Crypt;
use AA\Util\Secret;
use AA_Log;
use AA_Object;
use AA_Property;

/** A long run done in queue steps of at most ~30 s, so it goes on without the page that started it.
 *
 *  The record is an AA_Object whose every property is text, so it is rows of object_text: one locking
 *  read of them (lockForUpdate()) serializes everything that changes the run - which is why start()
 *  refuses a non-transactional object_text (and toexecute, see requireTransactional()). A queued step (BatchStep) carries the fingerprint of the
 *  step number and position it was queued for; a row that no longer matches is stale and does nothing.
 *  A claimed step holds a lease and a random token, and only the holder of the token writes the end
 *  of the step - a step of a run ended, resumed or claimed again meanwhile writes nothing.
 *  work() runs outside any transaction (import), or with stepInTransaction() inside the one that ends
 *  the step (Central), so what it writes commits with the cursor or not at all.
 *
 *  @phpstan-consistent-constructor
 */
abstract class BatchRun extends AA_Object
{
    /** the core's properties; a user adds its own in getClassProperties() */
    public const STATE_PROPS = [
        'state', 'subject', 'user', 'started', 'moved', 'finished', 'position', 'step', 'closing', 'lease',
        'lease_tok', 'failures', 'next_at', 'error_code', 'error_path', 'counts', 'warnings', 'progress',
        'report', 'sealed', 'sealed_until',
    ];

    /** steps in a row without progress after which onRetriesExhausted() decides - nothing goes round for ever */
    public const MAX_FAILURES = 3;

    /** what the queue row of an ended run expects instead of a fingerprint: it runs afterClose() after
     *  the end has committed - late, and more than once when the queue repeats a row whose process died */
    public const CLOSE_ROW = 'close';

    /** a run without progress this long past its retries hangs (isStuck()) */
    private const STUCK_GRACE = 3600;

    /** the longest step: a cron round lasts about 30 s */
    private const STEP_SECONDS = 30;
    /** left of the budget for the end of the step */
    private const STEP_MARGIN = 3;
    /** JSON nesting written - what is stored can always be read back */
    private const JSON_DEPTH = BatchStepResult::MAX_DEPTH;
    /** what toError() changes */
    private const ERROR_PROPS = ['state', 'error_code', 'error_path', 'next_at'];
    private const LEASE_PROPS = ['lease', 'lease_tok'];
    /** the token of a dead step Continue forgave: no step holds it, the next claim still cleans up after it */
    private const FORGIVEN = 'forgiven';

    /** a state name of states(): its active role means queued or working */
    protected $state = '';
    /** what the run belongs to (a long id) - also the owner of the object */
    protected $subject = '';
    protected $user = '';
    protected $started = '';
    /** unixtime of the last progress - a step that did not move the position does not count */
    protected $moved = '';
    protected $finished = '';
    /** JSON - where the next step continues */
    protected $position = '';
    /** steps ended so far; with the position it makes the fingerprint of a queued step */
    protected $step = '';
    /** '1' = the work is done, only the final phase is left */
    protected $closing = '';
    /** unixtime until which the claimed step may work, and that step's token */
    protected $lease = '';
    protected $lease_tok = '';
    /** steps in a row without progress */
    protected $failures = '';
    /** unixtime the next step is due - a retry, or the step after Continue; it waits only while > now */
    protected $next_at = '';
    protected $error_code = '';
    protected $error_path = '';
    /** JSON of two levels of whole numbers - each step adds its deltas */
    protected $counts = '';
    protected $warnings = '';
    /** JSON of the last step for the progress page: label, done, total */
    protected $progress = '';
    /** JSON of finalCheck() */
    protected $report = '';
    /** an encrypted secret of the run and the unixtime it is good till */
    protected $sealed = '';
    protected $sealed_until = '';

    /** read by lockForUpdate() - such an instance may change the run, but only inside the transaction
     *  it was read in: the lock ends with that transaction, this flag does not */
    private bool $locked = false;

    /** set while a step queues its own row again: when the queue refuses the row, later() runs the step
     *  at once, and that step would queue it again, without end */
    private static bool $requeueing = false;

    /** the core's roles (active, error, done, ended) under the user's names, and its own states */
    abstract protected static function states(): BatchStates;

    /** The queue selector of the run's steps. Unique among unfinished runs (import: Import_<definition>,
     *  one unfinished run per definition) - ending a run removes every queued row of it. Never shared
     *  with another subject: deleteOfSubject() cancels it. */
    abstract public function selector(): string;

    /** May the user see and control the run - decided by the stored subject, never by the context of
     *  the request (its module, its parameters), which the user chooses. */
    abstract public function mayControl(string $uid): bool;

    /** The work of one step from $from over what fetch() got. Returns where the next step continues -
     *  never before $from, and after the last work stored, even when the step fails. It keeps to $budget:
     *  it returns once spent(), and takes every request timeout from httpTimeout() only - a longer step
     *  holds up the whole queue and outlives its lease. Once it has stored work, it catches the
     *  BatchBudgetSpent of httpTimeout() itself and returns the position after that work: left to the
     *  core, the step goes on from $from and the work is done again. With stepInTransaction() it runs on the instance
     *  of lockForUpdate(), and a result without progress ends in error once the retries are spent:
     *  onRetriesExhausted() is not asked then, it may ask the source. */
    abstract protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult;

    /** True = work() and the end of the step in one transaction, under the run's lock - for a batch that
     *  must commit with its cursor or not at all. Then work():
     *  - waits for no source (fetch() does that before) and ends fast even with its budget spent: it holds
     *    the run's lock, and whoever waits for it gives up after innodb_lock_wait_timeout;
     *  - runs no DDL - it commits the transaction implicitly, half a batch with it;
     *  - lets a failed write throw: an exception it catches leaves the half batch to commit with the
     *    cursor (a nested DB_AA::transaction() joins this one, there is no savepoint);
     *  - writes only tables of transactionalTables() and the run's own;
     *  - knows that what it does outside the database (events and mails of storeItem(), caches, files)
     *    stays when the transaction rolls back. */
    protected function stepInTransaction(): bool
    {
        return false;
    }

    /** the tables work() writes with stepInTransaction(); start() refuses them when not InnoDB, where a
     *  rollback would leave half a batch without its cursor
     *  @return list<string> */
    protected function transactionalTables(): array
    {
        return [];
    }

    /** The data of the step, after the claim and outside any transaction; work() gets it. Any
     *  BatchStepResult it returns is the step's result and work() is not called - a source that failed
     *  says retry, but progress and finished count too. A permanent failure throws BatchFailed, its
     *  where masked (AA\Import\Context::redact()) - it is shown. Keeps to $budget as work() does,
     *  timeouts from httpTimeout() only. */
    protected function fetch(array $from, BatchBudget $budget): mixed
    {
        return null;
    }

    /** The user's own writes at the end of a step, under the lock, after the token was checked. Also
     *  called with the made-up result of a step that kept dying (retry STEP_DIED): work() did not run
     *  then, and extra is empty. */
    protected function commitStep(BatchStepResult $r): void {}

    /** the final phase, outside any transaction (it may ask the source); returns the report */
    protected function finalCheck(): array
    {
        return [];
    }

    /** After the commit of done or ended; an exception becomes a warning, the state stays. Must be
     *  idempotent: after an end it comes from the queue (CLOSE_ROW), late, and maybe more than once. */
    protected function afterClose(string $state): void {}

    /** After a step took over the lease of a step that died (also one Continue forgave), before its
     *  own work, called outside any transaction of its own: what the dead step left half done outside
     *  the database (import: the page cache of the items it stored). An exception is counted in
     *  warnings as AFTER_DEAD_STEP, the step goes on. */
    protected function afterDeadStep(): void {}

    /** MAX_FAILURES steps without progress; outside any transaction, so it may ask the source. A result
     *  at a position other than $last->position goes on from there - $last->withPosition(), or with
     *  warnings and counts of its own (import: skips the task, warns JOB_FAILED), never finished nor
     *  with a retry; null ends the run in error with the code of $last->retry. Never called in the
     *  final phase, nor for a result work() returns with stepInTransaction() - that one ends in error
     *  at once. */
    protected function onRetriesExhausted(BatchStepResult $last): ?BatchStepResult
    {
        return null;
    }

    /** seconds before the next try after a step the source failed; $failures after that step - 0 = it
     *  moved on even so, 1-2 = it did not. A final phase without its report waits the same way. */
    protected function retryDelay(int $failures): int
    {
        return 1800;
    }

    /** How much longer than its budget a step may take - a shorter lease would take a live step for a dead
     *  one. Not a licence to overrun: work() keeps to its budget, this covers the end of the step. */
    protected function overrun(): int
    {
        return 60;
    }

    /** the shortest rest of a queue round a step is worth starting in */
    protected function minStepSeconds(): int
    {
        return 10;
    }

    /** false = what the run stands on is gone (import: its definition); the run ends for good */
    protected function stillValid(): bool
    {
        return true;
    }

    /** what the progress page shows besides the core's data - plain data, it goes out as JSON */
    protected function progressExtra(): array
    {
        return [];
    }

    /** cleanup of the user's own states (Central: old previews); start() calls it */
    protected static function expireExtra(int $now): void {}

    /** names the key of the sealed secret: another purpose, another key */
    protected static function sealPurpose(): string
    {
        return 'batch-run';
    }

    /** the longest a secret may stay sealed - a stolen record opens nothing older */
    protected static function maxSealSeconds(): int
    {
        return 86400;
    }

    /** under the AA maintenance and feeds (10), which a run of weeks must not hold up */
    protected function stepPriority(): int
    {
        return 5;
    }

    /** @return array{items: int, bytes: int} what one step may write (0 = no limit) */
    protected function budgetLimits(): array
    {
        return ['items' => 0, 'bytes' => 0];
    }

    protected static function now(): int
    {
        return time();
    }

    protected static function microNow(): float
    {
        return microtime(true);
    }

    public static function getClassProperties(): array
    {
        $props = [];
        foreach (self::STATE_PROPS as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    /** A new run, in the caller's transaction: active with its first step queued, or in an own state
     *  ($state) with no step until enqueueFrom(). It checks no rights - the caller has made sure the
     *  user may control the subject (mayControl()) before.
     *  @param array<string, mixed> $props subject (a long id), user, position (array) and the user's own properties
     *  @throws BatchFailed NO_TRANSACTION when object_text, toexecute or (with stepInTransaction()) a table
     *                      of transactionalTables() is not InnoDB */
    final public static function start(array $props, ?string $state = null): static
    {
        if (!DB_AA::inTransaction()) {
            throw new \LogicException('A batch run starts inside the caller\'s transaction');
        }
        $run = new static();
        self::requireTransactional($run->stepInTransaction() ? $run->transactionalTables() : []);
        static::expireExtra(static::now());
        $states = static::states();
        if (($state !== null) && !isset($states->extra[$state])) {
            throw new \InvalidArgumentException('A batch run starts active or in an own state');
        }
        $allowed = ['subject', 'user', ...self::ownProperties()];
        foreach ($props as $name => $value) {
            if ($name === 'position') {
                continue;
            }
            if (!in_array($name, $allowed, true) || !(is_string($value) || is_int($value))) {
                throw new \InvalidArgumentException("A batch run does not start with $name");
            }
            $run->$name = (string) $value;
        }
        if (!preg_match('/^[0-9a-f]{32}\z/', $run->subject)) {
            throw new \InvalidArgumentException('The subject of a batch run is a long id');
        }
        $position = $props['position'] ?? [];
        if (!is_array($position)) {
            throw new \InvalidArgumentException('The position of a batch run is an array');
        }
        $run->setOwnerId($run->subject);
        $run->state    = $state ?? $states->active;
        $run->started  = (string) static::now();
        $run->position = self::positionJson($position);
        $run->step     = '0';
        $run->failures = '0';
        if ($state === null) {
            $run->enqueue(static::now());
        }
        $run->save();
        return $run;
    }

    /** the run as stored now, read-only; null when $id is no run of this class */
    final public static function get(string $id): ?static
    {
        return self::read($id, false);
    }

    /** get() with SELECT ... FOR UPDATE, inside the caller's transaction. The instance may change the
     *  run only inside that very transaction - its commit ends the lock. */
    final public static function lockForUpdate(string $id): ?static
    {
        if (!DB_AA::inTransaction()) {
            throw new \LogicException('A batch run is locked only inside a transaction');
        }
        $run = self::read($id, true);
        if ($run) {
            $run->locked = true;
        }
        return $run;
    }

    /** The only way into a step - from its queue row (BatchStep).
     *  @return string a short line for the queue log */
    final public static function runStep(string $class, string $id, string $expected): string
    {
        // the row names the class - nothing but a batch run is loaded or driven from it
        if (!is_subclass_of($class, self::class)) {
            return 'not a batch run: ' . mb_strcut($class, 0, 100);
        }
        $run = $class::get($id);
        if ($expected === self::CLOSE_ROW) {
            return $run?->closeEnded() ?? 'stale';
        }
        if (!$run || !$run->isAt($expected)) {
            return 'stale';
        }
        return $run->stepAt($expected);
    }

    /** From the own state $state to active, its step queued in the caller's transaction (it commits
     *  the run's move with its own writes); $closing = the work is done, the step runs the final phase.
     *  Only on an instance of lockForUpdate(). A run in error goes on by resume(), not this way. */
    final public function enqueueFrom(string $state, array $position, bool $closing = false): void
    {
        if (!isset(static::states()->extra[$state])) {
            throw new \LogicException("$state is no own state of the batch run");
        }
        if ($this->state !== $state) {
            throw new \LogicException("The batch run is $this->state, not $state");
        }
        $this->position = self::positionJson($position);
        $this->moveTo(static::states()->active);
        $this->step     = (string) ($this->step() + 1);
        $this->closing  = $closing ? '1' : '';
        $this->failures = '0';
        $this->moved    = (string) static::now();
        $this->enqueue(static::now());
        $this->update(['state', 'position', 'step', 'closing', 'failures', 'moved']);
    }

    public function state(): string
    {
        return (string) $this->state;
    }

    public function position(): array
    {
        return $this->json('position');
    }

    public function step(): int
    {
        return (int) $this->step;
    }

    /** End: the run stops for good, what it wrote stays. Only on an instance of lockForUpdate(), so it
     *  commits with the caller's other writes. The lease stays till it runs out: a step still working
     *  may write items till then, so a new run of the subject waits (hasLiveLease()); that step then
     *  finds the run ended and writes nothing of its own. afterClose('ended') runs from the queue
     *  (CLOSE_ROW) once the caller's transaction has committed.
     *  @param string $note why, as a code (SETTINGS_CHANGED) - the progress page shows it
     *  @throws BatchFailed FORBIDDEN when $uid may not control the run, WRONG_STATE when it is finished */
    final public function end(string $uid, string $note = ''): void
    {
        $this->requireLock();
        $this->requireControl($uid);
        if (($note !== '') && !BatchFailed::isCode($note)) {
            throw new \InvalidArgumentException('The note of an ended batch run must match ' . BatchFailed::CODE);
        }
        if ($this->isFinished()) {
            throw new BatchFailed('WRONG_STATE');
        }
        // before the record is written: a queue that refuses the row runs it at once, and it must find the run not ended
        // not while a step with a live lease may still write
        $at = $this->hasLiveLease(static::now()) ? (int) $this->lease + 1 : static::now();
        $this->queueRow(self::CLOSE_ROW, self::closeSelector($this->getId()), $at, $this->stepPriority());
        if ($note !== '') {
            $this->progress = self::encode(['note' => $note] + $this->json('progress'));
        }
        $this->becomeEnded(['progress']);
    }

    /** Continue: a run in error goes on from its position, a run waiting for its retry or hanging
     *  (isStuck()) tries now. Only on an instance of lockForUpdate(). The step counter moves, so every
     *  row queued before is stale.
     *  @param ?string $secret a new secret to seal (the source refused the old one), good till $until
     *  @throws BatchFailed FORBIDDEN when $uid may not control the run, WRONG_STATE when it is finished
     *                      or its step is on its way */
    final public function resume(string $uid, #[\SensitiveParameter] ?string $secret = null, ?int $until = null): void
    {
        $this->requireLock();
        $this->requireControl($uid);
        $now     = static::now();
        $states  = static::states();
        $error   = ($this->state === $states->error);
        $waiting = ($this->state === $states->active) && ((int) $this->next_at > $now) && !$this->hasLiveLease($now);
        // a step that works or is queued to run now would only be doubled
        if (!$error && !$waiting && !$this->isStuck($now)) {
            throw new BatchFailed('WRONG_STATE');
        }
        if ($secret !== null) {
            $this->seal($secret, $until ?? throw new \InvalidArgumentException('A new secret of a batch run needs its time'));
        }
        (new Toexecute())->cancel_all($this->selector());
        if ($error) {
            $this->moveTo($states->active);
        }
        if (!$this->hasLiveLease($now)) {
            // a lease that ran out belongs to a dead step - the user goes on, so it counts no failure,
            // yet the next claim cleans up after it (afterDeadStep())
            $this->lease     = '';
            $this->lease_tok = ((string) $this->lease_tok === '') ? '' : self::FORGIVEN;
        }
        $this->failures   = '0';
        $this->error_code = '';
        $this->error_path = '';
        // when the step is due: until it has had its chance the run does not hang (isStuck())
        $this->next_at    = (string) $now;
        $this->step       = (string) ($this->step() + 1);
        $this->enqueue($now);
        $this->update(['failures', 'step', ...self::ERROR_PROPS, ...self::LEASE_PROPS]);
    }

    /** Keeps a secret the steps need (Central: the source's login token) encrypted with the server's
     *  key, bound to this run and to $until, till which unseal() gives it - at most maxSealSeconds()
     *  ahead. Only on an instance of lockForUpdate(); done and ended forget it, cleanup() forgets it
     *  past $until. */
    final public function seal(#[\SensitiveParameter] string $secret, int $until): void
    {
        $this->requireLock();
        if ($this->isFinished()) {
            throw new \LogicException('A finished batch run keeps no secret');
        }
        $now = static::now();
        if (($secret === '') || ($until <= $now) || ($until > $now + static::maxSealSeconds())) {
            throw new \InvalidArgumentException('A sealed secret is not empty and is good till a time to come, within maxSealSeconds()');
        }
        try {
            $sealed = Crypt::encryptAesGcm($secret, Secret::key(static::sealPurpose()), $this->sealContext($until));
        } catch (\Throwable) {
            // dropped, not chained: the trace of that exception may hold the secret and the key
            throw new \RuntimeException('The secret of a batch run could not be sealed');
        }
        $this->sealed       = $sealed;
        $this->sealed_until = (string) $until;
        $this->update(['sealed', 'sealed_until']);
    }

    /** The sealed secret, for the user's own code (its steps; Central: the page of a preview) - never
     *  handed to a request as it is. Null when there is none, it is past its time, or it cannot be
     *  opened (the key changed, the record was altered) - the user then asks for a new one. */
    final protected function unseal(): ?string
    {
        $until = (int) $this->sealed_until;
        if (((string) $this->sealed === '') || ($until <= static::now())) {
            return null;
        }
        try {
            $secret = Crypt::decryptAesGcm((string) $this->sealed, Secret::key(static::sealPurpose()), $this->sealContext($until));
        } catch (\Throwable) {
            return null;
        }
        return is_string($secret) ? $secret : null;
    }

    /** What the progress page shows, as plain data for JSON. running = a step holds a live lease;
     *  next_at = when the next step is due (a retry, or the step after Continue) - it waits only while
     *  next_at > now, failures tells how many tries failed; warning after_close = afterClose() failed.
     *  label, note and error.where are codes or texts the user masked (AA\Import\Context::redact()),
     *  still text to the page: it puts them in by textContent or safe(), never as HTML.
     *  @return array{role: string, state: string, label: string, done: int, total: int, moved: int, started: int,
     *                running: bool, next_at: int, failures: int, counts: array, warnings: array,
     *                error: ?array{code: string, where: string}, note: string, warning: string, extra: array}
     *  @throws BatchFailed FORBIDDEN when $uid may not see the run */
    final public function progress(string $uid): array
    {
        $this->requireControl($uid);
        $last = $this->json('progress');
        return [
            'role'     => $this->role(),
            'state'    => $this->state(),
            'label'    => (string) ($last['label'] ?? ''),
            'done'     => (int) ($last['done'] ?? 0),
            'total'    => (int) ($last['total'] ?? 0),
            'moved'    => (int) $this->moved,
            'started'  => (int) $this->started,
            'running'  => $this->hasLiveLease(static::now()),
            'next_at'  => (int) $this->next_at,
            'failures' => $this->failures(),
            'counts'   => $this->json('counts'),
            'warnings' => $this->json('warnings'),
            'error'    => ((string) $this->error_code === '') ? null : ['code' => (string) $this->error_code, 'where' => (string) $this->error_path],
            'note'     => (string) ($last['note'] ?? ''),
            'warning'  => (string) ($last['warning'] ?? ''),
            'extra'    => $this->progressExtra(),
        ];
    }

    /** Active, yet no step works and none has been due (next_at: each queued step, a retry, a Continue)
     *  nor moved the position (since the start, when nothing did) for longer than the retries take and
     *  an hour: a lost queue row, a queue that does not run, a step that never fits a round. */
    final public function isStuck(int $now): bool
    {
        if (($this->state !== static::states()->active) || $this->hasLiveLease($now)) {
            return false;
        }
        $wait = self::STUCK_GRACE;
        for ($failures = 0; $failures < self::MAX_FAILURES; ++$failures) {
            $wait += $this->retryDelay($failures);
        }
        $since = max((int) (((string) $this->moved !== '') ? $this->moved : $this->started), (int) $this->next_at);
        return $now - $since > $wait;
    }

    /** a step may still be working - also in an ended run, so a new run of the subject waits for it */
    final public function hasLiveLease(int $now): bool
    {
        return ((string) $this->lease_tok !== '') && ((int) $this->lease > $now);
    }

    /** The runs neither done nor ended, of $subject or all, read-only. No rights checked - the caller
     *  shows a user only those mayControl() allows.
     *  @return list<static> */
    final public static function unfinished(?string $subject = null): array
    {
        $open    = self::unfinishedStates();
        $records = ($subject === null) ? self::records('state', $open, ['state']) : self::records('subject', $subject, ['state']);
        $ids     = array_keys(array_filter($records, static fn(array $r): bool => in_array($r['state'] ?? '', $open, true)));
        return self::getAll($ids);
    }

    /** the runs that hang (isStuck()), read-only - for the State Check, no rights checked
     *  @return list<static> */
    final public static function stuck(int $now): array
    {
        $ids = array_keys(self::records('state', static::states()->active, []));
        return array_values(array_filter(self::getAll($ids), static fn(self $run): bool => $run->isStuck($now)));
    }

    /** Deletes the finished runs of $subject but the $keep last ones, and forgets the secrets past their
     *  time of all runs. Kept: unfinished runs, an ended run whose step may still be writing (its lease
     *  keeps a new run of the subject waiting), and one whose afterClose() still waits in the queue.
     *  Outside any transaction: each secret is forgotten under its run's own short lock, never while the
     *  caller holds other locks a step's end may wait for. No rights checked - the caller has. */
    final public static function cleanup(string $subject, int $keep): void
    {
        if (DB_AA::inTransaction()) {
            throw new \LogicException('Batch runs are cleaned up outside any transaction');
        }
        $states = static::states();
        $now    = static::now();
        self::forgetExpiredSecrets($now);
        $finished = array_filter(
            self::records('subject', $subject, ['state', 'started', 'finished', 'lease', 'lease_tok']),
            static fn(array $r): bool => in_array($r['state'] ?? '', [$states->done, $states->ended], true) && ((($r['lease_tok'] ?? '') === '') || ((int) ($r['lease'] ?? 0) <= $now)),
        );
        uasort($finished, static fn(array $a, array $b): int => [(int) ($b['finished'] ?? 0), (int) ($b['started'] ?? 0)] <=> [(int) ($a['finished'] ?? 0), (int) ($a['started'] ?? 0)]);
        $old = array_map('strval', array_slice(array_keys($finished), max(0, $keep)));
        if ($old) {
            // a row of priority 0 never runs again - it must not hold the run for ever
            $rows    = DB_AA::select([], 'SELECT selector, priority FROM `toexecute`', [['selector', array_map(self::closeSelector(...), $old)]]);
            $closing = array_map(static fn(array $r): string => (string) $r['selector'], array_filter($rows, static fn(array $r): bool => (int) $r['priority'] > 0));
            $old     = array_values(array_filter($old, static fn(string $id): bool => !in_array(self::closeSelector($id), $closing, true)));
        }
        if ($old) {
            self::deleteObjects($old);
        }
    }

    /** Deletes every run of $subject whatever its state, with the queued steps of the unfinished ones
     *  and the close rows of all - for a subject that goes away. In the caller's transaction, with the
     *  subject's own records. A step working meanwhile finds no run at its end and writes nothing back;
     *  afterClose() does not run. No rights checked - the caller has.
     *  @return int the runs deleted */
    final public static function deleteOfSubject(string $subject): int
    {
        if (!DB_AA::inTransaction()) {
            throw new \LogicException('The runs of a subject are deleted inside the caller\'s transaction');
        }
        $records = self::records('subject', $subject, ['state']);
        if (!$records) {
            return 0;
        }
        $open      = self::unfinishedStates();
        $selectors = [];
        foreach ($records as $id => $record) {
            $selectors[] = self::closeSelector((string) $id);
            // the selector may depend on the run's own properties - read only for the few that have steps
            if (in_array($record['state'] ?? '', $open, true) && ($run = static::get((string) $id))) {
                $selectors[] = $run->selector();
            }
        }
        $ids = array_map('strval', array_keys($records));
        self::deleteObjects($ids);
        $queue = new Toexecute();
        foreach (array_unique($selectors) as $selector) {
            $queue->cancel_all($selector);
        }
        return count($ids);
    }

    /** The last $limit runs of $subject, the newest first, read-only. No rights checked - the caller
     *  shows a user only those mayControl() allows.
     *  @return list<static> */
    final public static function ofSubject(string $subject, int $limit): array
    {
        $records = self::records('subject', $subject, ['started']);
        uksort($records, static fn($a, $b): int => [(int) ($records[$b]['started'] ?? 0), (string) $b] <=> [(int) ($records[$a]['started'] ?? 0), (string) $a]);
        return self::getAll(array_slice(array_keys($records), 0, max(0, $limit)));
    }

    /** the run as get() reads it, for each of $ids that is one
     *  @return list<static> */
    private static function getAll(array $ids): array
    {
        return array_values(array_filter(array_map(static fn($id): ?static => static::get((string) $id), $ids)));
    }

    /** The runs of this class whose $property is $value (or one of them), with the values of $props:
     *  one query for the ids, one for the values - not a read per run.
     *  @param string|list<string> $value
     *  @param list<string> $props
     *  @return array<string, array<string, string>> id => property => value */
    private static function records(string $property, string|array $value, array $props): array
    {
        $ids = array_values(array_unique(array_map('strval', DB_AA::select('object_id', 'SELECT object_id FROM `object_text`', [['property', $property], ['value', $value]]))));
        if (!$ids) {
            return [];
        }
        $records = [];
        foreach (DB_AA::select([], 'SELECT object_id, property, value FROM `object_text`', [['object_id', $ids], ['property', ['aa_type', ...$props]]]) as $row) {
            $records[(string) $row['object_id']][(string) $row['property']] = (string) $row['value'];
        }
        // other objects may have a property of that name too
        return array_filter($records, static fn(array $r): bool => ($r['aa_type'] ?? '') === static::class);
    }

    /** @return list<string> */
    private static function unfinishedStates(): array
    {
        $states = static::states();
        return array_values(array_diff($states->all(), [$states->done, $states->ended]));
    }

    /** a secret past its time is of no use - and only an unfinished run keeps one; each run in a short
     *  transaction of its own, checked again under the lock: Continue may have sealed a new one since */
    private static function forgetExpiredSecrets(int $now): void
    {
        $sealed = array_filter(self::records('state', self::unfinishedStates(), ['sealed_until']), static fn(array $r): bool => (($r['sealed_until'] ?? '') !== '') && ((int) $r['sealed_until'] <= $now));
        foreach (array_keys($sealed) as $id) {
            DB_AA::transaction(static function () use ($id, $now): void {
                $run = self::lockForUpdate((string) $id);
                if ($run && ((string) $run->sealed_until !== '') && ((int) $run->sealed_until <= $now)) {
                    $run->sealed       = '';
                    $run->sealed_until = '';
                    $run->update(['sealed', 'sealed_until']);
                }
            });
        }
    }

    /** the queue selector of an ended run's close row - of the run, so cleanup() sees it waiting */
    private static function closeSelector(string $id): string
    {
        return 'BatchClose_' . $id;
    }

    /** what the sealed secret is bound to: moved to another run or given more time, it does not open */
    private function sealContext(int $until): string
    {
        return static::class . "\n" . $this->getId() . "\n" . $until;
    }

    /** @throws BatchFailed FORBIDDEN */
    private function requireControl(string $uid): void
    {
        if (!$this->mayControl($uid)) {
            throw new BatchFailed('FORBIDDEN');
        }
    }

    private function isFinished(): bool
    {
        return !in_array($this->state, self::unfinishedStates(), true);
    }

    /** ended, its queued steps with it - the lease and its step stay; the caller has the lock
     *  @param list<string> $also the caller's own properties to write with it */
    private function becomeEnded(array $also = []): void
    {
        $this->moveTo(static::states()->ended);
        $this->finished     = (string) static::now();
        $this->next_at      = '';
        $this->sealed       = '';
        $this->sealed_until = '';
        $this->update(['state', 'finished', 'next_at', 'sealed', 'sealed_until', ...$also]);
        (new Toexecute())->cancel_all($this->selector());
    }

    /** the core's role of the state: active, error, done, ended - or own */
    private function role(): string
    {
        $states = static::states();
        return match ($this->state()) {
            $states->active => 'active',
            $states->error  => 'error',
            $states->done   => 'done',
            $states->ended  => 'ended',
            default         => 'own',
        };
    }

    /** the close row of an ended run: afterClose() after the commit of the end */
    private function closeEnded(): string
    {
        if ($this->state() !== static::states()->ended) {
            return 'stale';
        }
        $this->closed();
        return 'closed';
    }

    /** object_text for the lock; toexecute because a step row on MyISAM is visible to another runner
     *  before the run's record commits - that runner finds the record stale, drops the row, and the
     *  run then waits for a step that never comes. Public, so a subject can refuse early what start() would.
     *  @throws BatchFailed NO_TRANSACTION */
    final public static function requireTransactional(array $more): void
    {
        $tables  = array_values(array_unique(['object_text', 'toexecute', ...$more]));
        $engines = DB_AA::select(['TABLE_NAME' => 'ENGINE'], 'SELECT TABLE_NAME, ENGINE FROM information_schema.tables', [['TABLE_SCHEMA', (string) constant('DB_NAME')], ['TABLE_NAME', $tables]]);
        $engines = array_change_key_case($engines);         // lower_case_table_names
        foreach ($tables as $table) {
            if (strtolower((string) ($engines[strtolower($table)] ?? '')) !== 'innodb') {
                throw new BatchFailed('NO_TRANSACTION', $table);
            }
        }
    }

    /** @return list<string> the user's own properties - read() reads object_text only, and an aa_*
     *                       or locked property would overwrite the object's own */
    private static function ownProperties(): array
    {
        $own = [];
        foreach (static::getClassProperties() as $id => $property) {
            $id = (string) $id;
            if (in_array($id, self::STATE_PROPS, true)) {
                continue;
            }
            // object_text.property is varbinary(16)
            if (str_starts_with($id, 'aa_') || ($id === 'locked') || (strlen($id) > 16) || (AA_Property::storageType($property->getType()) !== 'object_text')) {
                throw new \LogicException("Batch run property $id must be text, at most 16 bytes and named neither aa_* nor locked");
            }
            $own[] = $id;
        }
        return $own;
    }

    /** what one step may spend from now: a cron round's 30 s at most, never past the round running now
     *  ($left), less a margin for the end of the step */
    private static function stepSeconds(?float $left): float
    {
        return max(0.0, min((float) self::STEP_SECONDS, $left ?? (float) self::STEP_SECONDS) - self::STEP_MARGIN);
    }

    /** a lease covers the budget and the overrun - a shorter one would take a live step for a dead one */
    private function leaseUntil(int $now, float $seconds): int
    {
        return $now + (int) ceil($seconds) + $this->overrun();
    }

    /** A queued step that is not stale: the checks that need no lock, the claim, then the work or the
     *  final phase. */
    private function stepAt(string $expected): string
    {
        try {
            $valid    = $this->stillValid();
            $inTx     = $this->stepInTransaction();
            $min      = $this->minStepSeconds();
            $limits   = $this->budgetLimits();
            $overrun  = $this->overrun();
            $priority = $this->stepPriority();
        } catch (\Throwable $e) {
            // the queue drops the row after the step - the run must show why it stopped
            $this->logFailure($e);
            return $this->errorAt($expected, ...self::failure($e));
        }
        if (!$valid) {
            return $this->endInvalid($expected);
        }
        $round = Toexecute::roundSeconds();
        if (($round !== null) && ($round < $min)) {
            // no later round is longer until the config changes - waiting would go round for ever
            return $this->errorAt($expected, 'ROUND_TOO_SHORT', '');
        }
        $left = Toexecute::timeLeft();
        if (($left !== null) && ($left < $min)) {
            // at the round's end, not now - the queue would take the row again every second
            $this->requeue(static::now() + (int) ceil($left) + 1, $priority);
            return 'round end';
        }
        $budget = new BatchBudget(static::microNow() + self::stepSeconds($left), $limits['items'], $limits['bytes'], static::microNow(...));
        $claim  = 'stale';
        $run    = $this->whileLocked(static function (self $run) use ($expected, $budget, $overrun, $priority, &$claim): bool {
            $claim = $run->claim($expected, $budget, $overrun, $priority);
            return $claim !== 'stale';
        });
        if (!$run || ($claim === 'leased')) {
            return $claim;
        }
        // work() would join that transaction, its batch committed or rolled back by someone else
        if ($inTx && DB_AA::inTransaction()) {
            throw new \LogicException('A batch step that works in a transaction does not start inside another one');
        }
        if (in_array($claim, ['revived', 'died'], true)) {
            $run->deadStepLeft();
        }
        if ($run->closing === '1') {
            return ($claim === 'died') ? $run->finalFailed() : $run->finalPhase();
        }
        return $run->stepWork($budget, $claim === 'died', $inTx, $priority);
    }

    /** The claim: a lease long enough for the step and a token only this step knows.
     *  @return string stale, leased (another step holds a live lease), claimed, revived (the step before
     *                 died holding its lease) or died (that, and it spends the retries) */
    private function claim(string $expected, BatchBudget $budget, int $overrun, int $priority): string
    {
        if (!$this->isAt($expected)) {
            return 'stale';
        }
        $now     = static::now();
        $died    = false;
        $revived = false;
        if ((string) $this->lease_tok !== '') {
            if ((int) $this->lease > $now) {
                // a step works (a hand-run Execute, the queue's own retry); should it die, this row finds it
                $this->requeue((int) $this->lease + 1, $priority);
                return 'leased';
            }
            // a step died holding the lease: this row works in its place, and unless Continue forgave
            // that step, it counts as a step without progress
            $revived = true;
            if ($this->lease_tok !== self::FORGIVEN) {
                $died = ($this->failures() + 1 >= self::MAX_FAILURES);
                if (!$died) {
                    $this->failures = (string) ($this->failures() + 1);
                }
            }
        }
        // as leaseUntil(), with the overrun read before the lock, where its failure can still be shown
        $this->lease     = (string) ($now + (int) ceil($budget->secondsLeft()) + $overrun);
        $this->lease_tok = bin2hex(random_bytes(16));
        $this->update(['lease', 'lease_tok', 'failures']);
        return $died ? 'died' : ($revived ? 'revived' : 'claimed');
    }

    /** fetch() and work() - outside any transaction, or work() in the one of the end of the step */
    private function stepWork(BatchBudget $budget, bool $died, bool $inTx, int $priority): string
    {
        $from    = $this->position();
        $fetched = null;
        $run     = null;
        try {
            if ($died) {
                $result = new BatchStepResult($from, false, retry: 'STEP_DIED');
            } elseif ($budget->spent()) {
                // the claim took what was left - a request now would get no timeout
                $result = self::spentResult($from);
            } else {
                $fetched = $this->spending($from, fn(): mixed => $this->fetch($from, $budget));
                $result  = ($fetched instanceof BatchStepResult) ? $fetched : null;
            }
            if (($result === null) && !$inTx) {
                // ended, resumed or claimed again while fetch() waited: its data must not be written
                $held = self::get($this->getId());
                if (!$held || !$held->holds((string) $this->lease_tok)) {
                    return $this->lostText();
                }
                if ((int) $held->lease <= static::now()) {
                    // no one has claimed it yet, but may any moment; this row claims it again - as a step that died
                    $this->requeue(static::now(), $priority);
                    return 'lease out';
                }
                $result = $this->spending($from, fn(): BatchStepResult => $this->work($from, $budget, $fetched));
            }
            if ($result === null) {
                // a step that lost its lease drops what it fetched unwritten
                [$run, $result] = $this->workInTransaction($from, $budget, $fetched);
            }
            if ($result !== null) {
                if (!$result->finished && BatchPosition::same($from, $result->position) && ($this->failures() + 1 >= self::MAX_FAILURES)) {
                    $next   = BatchStep::during($this, fn(): ?BatchStepResult => $this->onRetriesExhausted($result));
                    if (($next !== null) && ($next->finished || ($next->retry !== ''))) {
                        // the way on is a position to go on from, not an end nor another failure
                        throw new \LogicException('onRetriesExhausted() returned a finished result or one to retry');
                    }
                    $result = (($next === null) || BatchPosition::same($result->position, $next->position)) ? $result : $next;
                }
                $run = $this->whileHolding((string) $this->lease_tok, static fn(self $run) => $run->applyStep($from, $result));
            }
        } catch (\Throwable $e) {
            // the end of the step rolled back with its next step - left active, the run would wait for ever
            return $this->failStep($e);
        }
        if (!$run) {
            return $this->lostText();
        }
        if ($run->state() === static::states()->error) {
            return 'error ' . $run->error_code;
        }
        // the final phase may ask the source - only after the end of the step is committed
        return ($run->closing === '1') ? $run->finalPhase() : ('step ' . $run->step());
    }

    /** work() and the end of the step in one transaction while the step holds its lease.
     *  @return array{?self, ?BatchStepResult} the run when written; else a step without progress to write
     *                                        after the rollback, as work() asked for time it had not */
    private function workInTransaction(array $from, BatchBudget $budget, mixed $fetched): array
    {
        try {
            return [$this->whileHolding((string) $this->lease_tok, static function (self $run) use ($from, $budget, $fetched): void {
                $run->applyStep($from, BatchStep::during($run, static fn(): BatchStepResult => $run->work($from, $budget, $fetched)));
            }), null];
        } catch (\Throwable $e) {
            // what the request has cached in memory may come from the writes just rolled back
            AA::Contentcache()->clear_soft();
            if ($e instanceof BatchBudgetSpent) {
                return [null, self::spentResult($from)];
            }
            throw $e;
        }
    }

    /** $do with the run as running(); a request the budget had no time for makes a step without progress
     *  @template T
     *  @param \Closure(): T $do
     *  @return T|BatchStepResult */
    private function spending(array $from, \Closure $do): mixed
    {
        try {
            return BatchStep::during($this, $do);
        } catch (BatchBudgetSpent) {
            return self::spentResult($from);
        }
    }

    private static function spentResult(array $from): BatchStepResult
    {
        return new BatchStepResult($from, false, retry: 'NO_PROGRESS');
    }

    /** The end of a step, on the run locked in the caller's transaction while the step holds its lease. */
    private function applyStep(array $from, BatchStepResult $r): void
    {
        $this->requireLock();
        $now   = static::now();
        $moved = !BatchPosition::same($from, $r->position);
        if ($moved) {
            $this->moved = (string) $now;
        }
        $this->position = self::positionJson($r->position);
        $this->step     = (string) ($this->step() + 1);
        $this->counts   = self::encode(self::add($this->json('counts'), $r->counts));
        $this->warnings = self::encode(self::add($this->json('warnings'), $r->warnings));
        $this->progress = self::encode(['label' => $r->label, 'done' => $r->done, 'total' => $r->total]);
        $this->commitStep($r);
        if ($r->finished) {
            // the lease stays for the final phase; if this process dies in it, the safety row repeats it
            $this->failures = '0';
            $this->closing  = '1';
            $this->next_at  = '';
            $this->lease    = (string) $this->leaseUntil($now, self::stepSeconds(null));
            $this->enqueue((int) $this->lease + 1);
        } else {
            // a step that only ran out of time goes on at once; a failed source gets time to recover
            $this->retryOrError($moved ? 0 : $this->failures() + 1, !in_array($r->retry, ['', 'NO_PROGRESS'], true), ($r->retry !== '') ? $r->retry : 'NO_PROGRESS');
        }
        $this->update(['moved', 'failures', 'position', 'step', 'counts', 'warnings', 'progress', 'closing', ...self::ERROR_PROPS, ...self::LEASE_PROPS]);
    }

    /** The lease released, the next step queued - after retryDelay() when $wait - or, once $failures
     *  reach MAX_FAILURES, error $code. */
    private function retryOrError(int $failures, bool $wait, string $code): void
    {
        $now             = static::now();
        $this->failures  = (string) $failures;
        $this->lease     = '';
        $this->lease_tok = '';
        if ($failures >= self::MAX_FAILURES) {
            $this->toError($code, '');
            return;
        }
        $at            = $now + ($wait ? $this->retryDelay($failures) : 0);
        // also a step due now: a run that just got its chance does not hang (isStuck())
        $this->next_at = (string) $at;
        $this->enqueue($at);
    }

    /** A permanent failure of the step: error with a code and a place, only while the step owns the run. */
    private function failStep(\Throwable $e): string
    {
        $this->logFailure($e);
        [$code, $where] = self::failure($e);
        $run = $this->whileHolding((string) $this->lease_tok, static function (self $run) use ($code, $where): void {
            $run->toError($code, $where);
            $run->step      = (string) ($run->step() + 1);
            $run->lease     = '';
            $run->lease_tok = '';
            $run->update(['step', ...self::ERROR_PROPS, ...self::LEASE_PROPS]);
        });
        return $run ? "error $code" : $this->lostText();
    }

    /** a final phase that brought no report - a step without progress; with the retries spent, the run
     *  errs without asking onRetriesExhausted(): there is nothing to skip */
    private function finalFailed(): string
    {
        $run = $this->whileHolding((string) $this->lease_tok, static function (self $run): void {
            $run->step = (string) ($run->step() + 1);
            $run->retryOrError($run->failures() + 1, true, 'FINAL_CHECK');
            $run->update(['failures', 'step', ...self::ERROR_PROPS, ...self::LEASE_PROPS]);
        });
        if (!$run) {
            return $this->lostText();
        }
        return ($run->state() === static::states()->error) ? 'error FINAL_CHECK' : ('step ' . $run->step());
    }

    /** error $code without the lease - a step working elsewhere keeps it, and finds the run not active */
    private function errorAt(string $expected, string $code, string $where): string
    {
        $run = $this->whileAt($expected, static function (self $run) use ($code, $where): void {
            $run->toError($code, $where);
            $run->update(self::ERROR_PROPS);
        });
        return $run ? "error $code" : 'stale';
    }

    /** what the run stands on is gone: ended for good, its queued steps with it */
    private function endInvalid(string $expected): string
    {
        $run = $this->whileAt($expected, static fn(self $run) => $run->becomeEnded());
        if (!$run) {
            return 'stale';
        }
        $run->closed();
        return $run->state();
    }

    /** finalCheck() outside any transaction, then done - only while the lease is still this step's */
    private function finalPhase(): string
    {
        try {
            $report = BatchStep::during($this, fn(): array => $this->finalCheck());
        } catch (\Throwable $e) {
            // a final phase without its report is a step without progress, retried
            $this->logFailure($e);
            return $this->finalFailed();
        }
        try {
            $run = $this->whileHolding((string) $this->lease_tok, static function (self $run) use ($report): void {
                $run->moveTo($run::states()->done);
                $run->report       = $report ? json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE, self::JSON_DEPTH) : '';
                $run->finished     = (string) $run::now();
                $run->next_at      = '';
                $run->sealed       = '';
                $run->sealed_until = '';
                $run->lease        = '';
                $run->lease_tok    = '';
                $run->update(['state', 'report', 'finished', 'next_at', 'sealed', 'sealed_until', 'lease', 'lease_tok']);
            });
        } catch (\Throwable $e) {
            // it would fail the same way again (a report too deep, a broken state) - no retry
            return $this->failStep($e);
        }
        if (!$run) {
            return $this->lostText();
        }
        $run->closed();
        return $run->state();
    }

    /** @return array{string, string} the error code and place of $e - its own for a BatchFailed */
    private static function failure(\Throwable $e): array
    {
        return ($e instanceof BatchFailed) ? [$e->reason, $e->where] : ['INTERNAL', get_debug_type($e)];
    }

    /** the class and the line in the queue's failure log, as the queue logs a task that threw - never the
     *  text, which may carry source data or a secret; a BatchFailed is no fault, its code says it all */
    private function logFailure(\Throwable $e): void
    {
        if (!($e instanceof BatchFailed)) {
            AA_Log::write(Toexecute::FAILED_EVENT, $this->selector(), Toexecute::failureParams($this->getId(), $e));
        }
    }

    /** afterClose() after the commit: its failure only marks the progress - never its text, which may carry a secret */
    private function closed(): void
    {
        try {
            $this->afterClose($this->state());
        } catch (\Throwable) {
            $this->whileLocked(static function (self $run): bool {
                $run->progress = self::encode(['warning' => 'after_close'] + $run->json('progress'));
                $run->update(['progress']);
                return true;
            });
        }
    }

    /** afterDeadStep(); its failure counted in warnings, which the end of the step adds to - never its text */
    private function deadStepLeft(): void
    {
        try {
            $this->afterDeadStep();
        } catch (\Throwable) {
            $this->whileLocked(static function (self $run): bool {
                $run->warnings = self::encode(self::add($run->json('warnings'), ['AFTER_DEAD_STEP' => 1]));
                $run->update(['warnings']);
                return true;
            });
        }
    }

    /** $write on the run locked in a transaction of its own
     *  @param \Closure(self): bool $write false = nothing to write
     *  @return ?self the locked run when $write wrote */
    private function whileLocked(\Closure $write): ?self
    {
        $run = DB_AA::transaction(function () use ($write): ?self {
            $run = self::lockForUpdate($this->getId());
            return ($run && $write($run)) ? $run : null;
        });
        if ($run) {
            $run->locked = false;       // committed - the lock is gone
        }
        return $run;
    }

    /** whileLocked() only while the step with $token still owns the run
     *  @param \Closure(self): void $write */
    private function whileHolding(#[\SensitiveParameter] string $token, \Closure $write): ?self
    {
        return $this->whileLocked(static function (self $run) use ($token, $write): bool {
            if (!$run->holds($token)) {
                return false;
            }
            $write($run);
            return true;
        });
    }

    /** whileLocked() only while the run is still at the step the queue row $expected was made for
     *  @param \Closure(self): void $write */
    private function whileAt(string $expected, \Closure $write): ?self
    {
        return $this->whileLocked(static function (self $run) use ($expected, $write): bool {
            if (!$run->isAt($expected)) {
                return false;
            }
            $write($run);
            return true;
        });
    }

    /** why a step wrote nothing: another step holds the lease, or the run is no longer active */
    private function lostText(): string
    {
        $state = self::get($this->getId())?->state();
        return ($state === $this->state()) ? 'lease lost' : ('run ' . ($state ?? 'gone'));
    }

    /** Queues the step for the current step number and position. Call it before the record is written:
     *  later() runs a task it cannot queue at once, and such a step must find its row stale. */
    private function enqueue(int $time, ?int $priority = null): void
    {
        $this->queueRow(BatchPosition::fingerprint($this->step(), $this->position()), $this->selector(), $time, $priority ?? $this->stepPriority());
    }

    private function queueRow(string $expected, string $selector, int $time, int $priority): void
    {
        if ((new Toexecute())->later(new BatchStep(static::class, $this->getId(), $expected), [], $selector, $priority, $time) !== true) {
            throw new \RuntimeException('The queue did not take the step of a batch run');
        }
    }

    /** queues the row being run once more, unchanged - such a step is not stale, so it must not do it again */
    private function requeue(int $time, int $priority): void
    {
        if (self::$requeueing) {
            return;
        }
        self::$requeueing = true;
        try {
            $this->enqueue($time, $priority);
        } finally {
            self::$requeueing = false;
        }
    }

    /** the run in error, the lease untouched; the caller writes ERROR_PROPS */
    private function toError(string $code, string $where): void
    {
        $this->moveTo(static::states()->error);
        $this->error_code = $code;
        $this->error_path = mb_strcut($where, 0, 255);
        $this->next_at    = '';
    }

    private function failures(): int
    {
        return (int) $this->failures;
    }

    /** the queue row of $expected may work: the run is active and still at that step and position */
    private function isAt(string $expected): bool
    {
        return ($this->state === static::states()->active) && hash_equals(BatchPosition::fingerprint($this->step(), $this->position()), $expected);
    }

    /** The step with $token still owns the run. Not the lease's time: under the lock, a step past its
     *  lease may still write as long as no other step claimed the run - that one would have a new token. */
    private function holds(#[\SensitiveParameter] string $token): bool
    {
        return ($this->state === static::states()->active) && ($token !== '') && hash_equals((string) $this->lease_tok, $token);
    }

    private function moveTo(string $to): void
    {
        $this->requireLock();
        if (!static::states()->mayGo($this->state(), $to)) {
            throw new \LogicException("A batch run cannot go from $this->state to $to");
        }
        $this->state = $to;
    }

    private function requireLock(): void
    {
        if (!$this->locked || !DB_AA::inTransaction()) {
            throw new \LogicException('A batch run changes only after lockForUpdate() inside a transaction');
        }
    }

    /** stored by plain JSON; checked first, so a float never gets in (BatchPosition) */
    private static function positionJson(array $position): string
    {
        BatchPosition::canonical($position);
        return self::encode($position);
    }

    private function json(string $property): array
    {
        $value = (string) $this->$property;
        // json_decode() needs one level more than json_encode() for the same data
        return ($value === '') ? [] : (array) json_decode($value, true, self::JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR, self::JSON_DEPTH);
    }

    /** the step's deltas added to the run's sums: whole numbers, two levels at most */
    private static function add(array $sum, array $delta, int $depth = 2): array
    {
        foreach ($delta as $key => $value) {
            if (is_array($value) && ($depth > 1)) {
                $sum[$key] = self::add(is_array($sum[$key] ?? null) ? $sum[$key] : [], $value, $depth - 1);
            } elseif (is_int($value)) {
                $sum[$key] = (is_int($sum[$key] ?? null) ? $sum[$key] : 0) + $value;
            } else {
                throw new \InvalidArgumentException('Batch counts are whole numbers, two levels at most');
            }
        }
        return $sum;
    }

    /** AA_Object::load() caches per request - a step must see the record as it is now */
    private static function read(string $id, bool $lock): ?static
    {
        if (!preg_match('/^[0-9a-f]{32}\z/', $id)) {
            return null;
        }
        $rows = self::textRows($id, $lock);
        if (($rows['aa_type'] ?? '') !== static::class) {
            return null;
        }
        $run = new static();
        $run->setId($id);
        $run->setOwnerId($rows['aa_owner'] ?? '');
        foreach (array_keys(static::getClassProperties()) as $property) {
            $run->$property = (string) ($rows[$property] ?? '');
        }
        return $run;
    }
}
