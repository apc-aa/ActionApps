<?php

declare(strict_types=1);

namespace AA\Later;

use AA\IO\DB\DB_AA;
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

    /** what one step may work: 30 s less a margin for the end of the step */
    private const STEP_SECONDS = 27;
    /** how much longer than its budget a step may take before it counts as dead */
    private const LEASE_OVERRUN = 60;
    /** under the AA maintenance and feeds (10), which a run of weeks must not hold up */
    private const STEP_PRIORITY = 5;
    /** JSON nesting written - what is stored can always be read back */
    private const JSON_DEPTH = 16;

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
    /** unixtime of the next try after a step without progress */
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

    /** the core's roles (active, error, done, ended) under the user's names, and its own states */
    abstract protected static function states(): BatchStates;

    /** the queue selector of the run's steps */
    abstract public function selector(): string;

    /** may the user see and control the run */
    abstract public function mayControl(string $uid): bool;

    /** The work of one step from $from. Returns where the next step continues - never before $from,
     *  and after the last work stored, even when the step fails. */
    abstract protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult;

    /** the user's own writes at the end of a step, under the lock, after the token was checked */
    protected function commitStep(BatchStepResult $r): void {}

    /** the final phase, outside any transaction (it may ask the source); returns the report */
    protected function finalCheck(): array
    {
        return [];
    }

    /** after the commit of done or ended; an exception becomes a warning, the state stays */
    protected function afterClose(string $state): void {}

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
     *  @throws BatchFailed NO_TRANSACTION when object_text or toexecute is not InnoDB */
    final public static function start(array $props, ?string $state = null): static
    {
        if (!DB_AA::inTransaction()) {
            throw new \LogicException('A batch run starts inside the caller\'s transaction');
        }
        self::requireTransactional();
        $states = static::states();
        if (($state !== null) && !isset($states->extra[$state])) {
            throw new \InvalidArgumentException('A batch run starts active or in an own state');
        }
        $allowed = ['subject', 'user', ...self::ownProperties()];
        $run     = new static();
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
        if (!$run || !$run->isAt($expected)) {
            return 'stale';
        }
        $budget = $class::budget();
        $run    = DB_AA::transaction(static fn(): ?BatchRun => $class::lockForUpdate($id)?->claim($expected, $budget));
        if (!$run) {
            return 'stale';
        }
        $run->locked = false;           // the claim committed - work() and the hooks get no lock
        return ($run->closing === '1') ? $run->finalPhase() : $run->stepWork($budget);
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

    /** object_text for the lock; toexecute because a step row on MyISAM is visible to another runner
     *  before the run's record commits - that runner finds the record stale, drops the row, and the
     *  run then waits for a step that never comes */
    private static function requireTransactional(): void
    {
        $tables  = ['object_text', 'toexecute'];
        $engines = DB_AA::select(['TABLE_NAME' => 'ENGINE'], 'SELECT TABLE_NAME, ENGINE FROM information_schema.tables', [['TABLE_SCHEMA', (string) constant('DB_NAME')], ['TABLE_NAME', $tables]]);
        $engines = array_change_key_case($engines);         // lower_case_table_names
        foreach ($tables as $table) {
            if (strtolower((string) ($engines[$table] ?? '')) !== 'innodb') {
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

    /** what one step may spend - counted from now */
    private static function budget(): BatchBudget
    {
        return new BatchBudget(static::microNow() + self::STEP_SECONDS, 0, 0, static::microNow(...));
    }

    /** a lease covers the budget and the overrun - a shorter one would take a live step for a dead one */
    private static function leaseUntil(int $now, BatchBudget $budget): int
    {
        return $now + (int) ceil($budget->secondsLeft()) + self::LEASE_OVERRUN;
    }

    /** the claim: a lease long enough for the step and a token only this step knows */
    private function claim(string $expected, BatchBudget $budget): ?static
    {
        if (!$this->isAt($expected)) {
            return null;
        }
        $this->lease     = (string) self::leaseUntil(static::now(), $budget);
        $this->lease_tok = bin2hex(random_bytes(16));
        $this->update(['lease', 'lease_tok']);
        return $this;
    }

    /** the work outside any transaction - it may take the whole budget */
    private function stepWork(BatchBudget $budget): string
    {
        $from   = $this->position();
        $result = BatchStep::during($this, fn(): BatchStepResult => $this->work($from, $budget, null));
        $run    = $this->whileHolding((string) $this->lease_tok, static function (self $run) use ($from, $result): void {
            $run->applyStep($from, $result);
        });
        if (!$run) {
            return $this->lostText();
        }
        // the final phase may ask the source - only after the end of the step is committed
        return ($run->closing === '1') ? $run->finalPhase() : ('step ' . $run->step());
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
        if ($moved || $r->finished) {
            $this->failures = '0';
        }
        $this->position = self::positionJson($r->position);
        $this->step     = (string) ($this->step() + 1);
        $this->next_at  = '';
        $this->counts   = self::encode(self::add($this->json('counts'), $r->counts));
        $this->warnings = self::encode(self::add($this->json('warnings'), $r->warnings));
        $this->progress = self::encode(['label' => $r->label, 'done' => $r->done, 'total' => $r->total]);
        $this->commitStep($r);
        if ($r->finished) {
            // the lease stays for the final phase; if this process dies in it, the safety row repeats it
            $this->closing = '1';
            $this->lease   = (string) self::leaseUntil($now, self::budget());
            $this->enqueue((int) $this->lease + 1);
        } else {
            $this->lease     = '';
            $this->lease_tok = '';
            $this->enqueue($now);
        }
        $this->update(['moved', 'failures', 'position', 'step', 'next_at', 'counts', 'warnings', 'progress', 'closing', 'lease', 'lease_tok']);
    }

    /** finalCheck() outside any transaction, then done - only while the lease is still this step's */
    private function finalPhase(): string
    {
        $report = BatchStep::during($this, fn(): array => $this->finalCheck());
        $run    = $this->whileHolding((string) $this->lease_tok, static function (self $run) use ($report): void {
            $run->moveTo($run::states()->done);
            $run->report       = $report ? json_encode($report, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE, self::JSON_DEPTH) : '';
            $run->finished     = (string) $run::now();
            $run->sealed       = '';
            $run->sealed_until = '';
            $run->lease        = '';
            $run->lease_tok    = '';
            $run->update(['state', 'report', 'finished', 'sealed', 'sealed_until', 'lease', 'lease_tok']);
        });
        if (!$run) {
            return $this->lostText();
        }
        $run->closed();
        return $run->state();
    }

    /** afterClose() after the commit: its failure only marks the progress - never its text, which may carry a secret */
    private function closed(): void
    {
        try {
            $this->afterClose($this->state());
        } catch (\Throwable) {
            $this->whileLocked(static function (self $run): bool {
                $run->progress = self::encode(['warning' => 'AFTER_CLOSE'] + $run->json('progress'));
                $run->update(['progress']);
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
    private function whileHolding(string $token, \Closure $write): ?self
    {
        return $this->whileLocked(static function (self $run) use ($token, $write): bool {
            if (!$run->holds($token)) {
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
    private function enqueue(int $time): void
    {
        $step = new BatchStep(static::class, $this->getId(), BatchPosition::fingerprint($this->step(), $this->position()));
        if ((new Toexecute())->later($step, [], $this->selector(), self::STEP_PRIORITY, $time) !== true) {
            throw new \RuntimeException('The queue did not take the step of a batch run');
        }
    }

    /** the queue row of $expected may work: the run is active and still at that step and position */
    private function isAt(string $expected): bool
    {
        return ($this->state === static::states()->active) && hash_equals(BatchPosition::fingerprint($this->step(), $this->position()), $expected);
    }

    /** the step with $token still owns the run */
    private function holds(string $token): bool
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
        $rows = DB_AA::select(['property' => 'value'], 'SELECT property, value FROM `object_text` ' . DB_AA::makeWhere([['object_id', $id]]) . ($lock ? ' FOR UPDATE' : ''));
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
