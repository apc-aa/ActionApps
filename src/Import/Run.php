<?php

declare(strict_types=1);

namespace AA\Import;

use AA;
use AA\Http\TransportException;
use AA\IO\Grabber\AbstractGrabber;
use AA\IO\Saver;
use AA\IO\SourceFailure;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA\Later\BatchBudget;
use AA\Later\BatchBudgetSpent;
use AA\Later\BatchFailed;
use AA\Later\BatchPosition;
use AA\Later\BatchRun;
use AA\Later\BatchStates;
use AA\Later\BatchStepResult;
use AA\Later\Toexecute;
use AA_Item;
use AA_Log;
use AA_Property;
use AA_Value;
use JsonException;

/**
 * One run of an import definition, in queue steps: stream by stream, job by job, item by item
 * through ParamsGrabber and the Saver into the target slice. It works only with its snapshot of
 * the definition, so editing the definition changes the next run, not this one.
 *
 * The position is the cursor of the source (RunCursor): the stream (index in stream_keys), the
 * job and how far into it (offset and the fingerprint of its first items), plus what a stream
 * carries from step to step until it ends - pages and cur (jobs started and the last one), wrote,
 * failed, vmax, restarts.
 * A step always returns the cursor after the last work it stored, also when the source fails.
 * Moving the cursor onto a job not yet started (the first one of a stream, or the next one) counts
 * as progress before that job stored anything, so a source failing at a new job costs at most one
 * extra step before its failures count.
 */
class Run extends BatchRun
{
    /** the states of a run: queued or working, stopped by an error, finished, ended by a user */
    public const RUNNING = 'running';
    public const ERROR   = 'error';
    public const DONE    = 'done';
    public const ENDED   = 'ended';

    /** what a run counts, all of them in this order */
    private const COUNTS = [
        'read' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'jobs' => 0, 'streams' => 0, 'unchanged' => 0, 'skipped_jobs' => 0,
    ];

    /** item errors a step hands over, and the run keeps, at most */
    private const ERRORS_KEPT = 20;

    /** items a dry run shows */
    private const SAMPLES_KEPT = 5;

    /** jobs whose key collisions the run lists; the rest it only counts */
    private const COLLISIONS_KEPT = 20;

    private const MODES = ['run', 'changed', 'check'];

    /** what walkJobs() and runJob() return when the step stops at the cursor */
    private const STOP = 'stop';

    /** seconds onRetriesExhausted() gives check() and jobs(), ending 30 s before the lease at the latest */
    private const DECIDE_SECONDS = 15;

    /** run (every stream) | changed (streams probe() does not call unchanged) | check (check(), probe(), catalog()) */
    protected $mode = '';
    /** '1' = a dry run: nothing stored */
    protected $dry = '';
    /** source items the run reads at most; '' = all */
    protected $limit = '';
    /** JSON of Definition::snapshot() at the start */
    protected $snapshot = '';
    /** JSON list of the keys of the streams the run goes through */
    protected $stream_keys = '';
    /** plan:<task> | event:<task> | manual */
    protected $trigger = '';
    /** JSON list of the first item errors {stream, job, code, msg} */
    protected $errors = '';
    /** JSON list of the first items a dry run would store, for its report */
    protected $samples = '';
    /** JSON {ok, code, msg} of the run's check(), for the report of a check */
    protected $check = '';
    /** JSON {jobs: [{stream, job, count}] of the first jobs whose items repeat a key, more: the repeats
     *  in jobs not listed}, for the report. Saver::collisions() of each step's part of a job - a lower
     *  bound; a job read again after JOB_GONE may count twice. */
    protected $collisions = '';

    /** @var array<string, int> what the step counted so far */
    private array $tally = self::COUNTS;
    /** @var array<string, int> warnings of the step: code => times */
    private array $notes = [];
    /** @var list<array{stream: string, job: string, code: string, msg: string}> */
    private array $itemErrors = [];
    /** @var array<string, true> slices the step stored items into */
    private array $touched = [];
    /** @var array<string, array<string, string|int>> stream key => what StreamState records of it */
    private array $streamRecords = [];
    /** @var list<array<string, list<string>>> what the dry run of the step would store */
    private array $stepSamples = [];
    /** @var list<array{stream: string, job: string, count: int}> the key collisions of the step */
    private array $stepCollisions = [];
    /** the check() of the step, its message redacted */
    private ?CheckResult $checkResult = null;
    /** the catalog with the pages of the step, stored at its end */
    private ?Catalog $catalog = null;

    public static function getClassProperties(): array
    {
        $props = parent::getClassProperties();
        foreach (['mode', 'dry', 'limit', 'snapshot', 'stream_keys', 'trigger', 'errors', 'samples', 'check', 'collisions'] as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    protected static function states(): BatchStates
    {
        return new BatchStates(self::RUNNING, self::ERROR, self::DONE, self::ENDED);
    }

    /** one unfinished run per definition, so the selector of the definition is the run's */
    public function selector(): string
    {
        return 'Import_' . $this->definitionId();
    }

    /** the right to import into the target slice - the slice of the snapshot, never of the request */
    public function mayControl(string $uid): bool
    {
        return Definition::mayImportInto($uid, $this->targetSlice());
    }

    /** a deleted slice deletes its definitions, not their runs - those would go on writing into it */
    protected function stillValid(): bool
    {
        return Definition::fresh($this->definitionId()) !== null;
    }

    public function definitionId(): string
    {
        return (string) $this->subject;
    }

    public function mode(): string
    {
        return (string) $this->mode;
    }

    public function isDry(): bool
    {
        return (string) $this->dry === '1';
    }

    /** @return array{read: int, new: int, updated: int, skipped: int, errors: int, jobs: int, streams: int, unchanged: int, skipped_jobs: int} */
    public function counts(): array
    {
        return self::countsOf((string) $this->counts);
    }

    /** the counts a stored `counts` value holds - State Check reads them without loading the run
     *  @return array{read: int, new: int, updated: int, skipped: int, errors: int, jobs: int, streams: int, unchanged: int, skipped_jobs: int} */
    public static function countsOf(string $json): array
    {
        $stored = json_decode($json, true);
        $counts = self::COUNTS;
        foreach ($counts as $name => $zero) {
            $counts[$name] = (int) (is_array($stored) ? ($stored[$name] ?? $zero) : $zero);
        }
        return $counts;
    }

    /** the source of the snapshot */
    protected function source(): SourceInterface
    {
        return Registry::create((string) ($this->snapshot()['source'] ?? ''), $this->settings());
    }

    /** the Saver of one job; the run sets the import mode, the job parameters and the validation */
    protected function saver(AbstractGrabber $g, array $transformations, Mapping $mapping): Saver
    {
        return new Saver($g, $transformations, $this->targetSlice(), $mapping->storeMode, $mapping->idMode());
    }

    /** the way out of the step, with its keys and its budget */
    protected function context(SourceInterface $source, BatchBudget $budget): Context
    {
        return new Context($source->credentials(), $budget);
    }

    protected function targetSlice(): string
    {
        return (string) ($this->snapshot()['slice'] ?? '');
    }

    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        $this->tally          = self::COUNTS;
        $this->notes          = [];
        $this->itemErrors     = [];
        $this->touched        = [];
        $this->streamRecords  = [];
        $this->stepSamples    = [];
        $this->stepCollisions = [];
        $this->checkResult    = null;
        $this->catalog        = null;
        try {
            return $this->walk(RunCursor::fromPosition($from), $budget);
        } finally {
            // the Saver of an import leaves the page cache alone; also items stored before a failure count
            $this->invalidatePages(array_keys($this->touched));
        }
    }

    /** the dead step may have stored items and never got to invalidate their pages */
    protected function afterDeadStep(): void
    {
        $this->invalidatePages([$this->targetSlice()]);
    }

    /** an end while a step works: that step finds the run ended after storing, and may die before its finally */
    protected function afterClose(string $state): void
    {
        if ($state === static::states()->ended) {
            $this->invalidatePages([$this->targetSlice()]);
        }
    }

    /**
     * What the step leaves besides its items, under the lock of the end of the step: item errors,
     * key collisions and samples into the run; versions, probe times, check() and catalog pages, not
     * in a dry run. A run ended meanwhile never gets here - the core does not end a step it no longer
     * holds.
     */
    protected function commitStep(BatchStepResult $r): void
    {
        $extra = $r->extra;
        $write = [];
        if ($extra['errors'] ?? []) {
            $this->errors = self::addUpTo((string) $this->errors, $extra['errors'], self::ERRORS_KEPT);
            $write[]      = 'errors';
        }
        if ($extra['samples'] ?? []) {
            $this->samples = self::addUpTo((string) $this->samples, $extra['samples'], self::SAMPLES_KEPT);
            $write[]       = 'samples';
        }
        if ($extra['collisions'] ?? []) {
            $this->collisions = self::addCollisions((string) $this->collisions, $extra['collisions']);
            $write[]          = 'collisions';
        }
        if ($write) {
            $this->update($write);
        }
        if ($this->isDry()) {
            return;
        }
        if (($extra['streams'] ?? []) || (($extra['check'] ?? null) instanceof CheckResult)) {
            $state = StreamState::forDefinition($this->definitionId()) ?? StreamState::create($this->definitionId());
            foreach ($extra['streams'] ?? [] as $key => $values) {
                $state->record((string) $key, $values);
            }
            if (($extra['check'] ?? null) instanceof CheckResult) {
                $state->recordCheck($extra['check'], static::now());
                // the report shows the very form the state keeps: cleaned and cut
                $this->check = (string) json_encode(array_diff_key((array) $state->lastCheck(), ['time' => 0]), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
                $this->update(['check']);
            }
            $state->store();
        }
        if (($extra['catalog'] ?? null) instanceof Catalog) {
            $extra['catalog']->store();
        }
    }

    /** the report of a done run: its check() and probes of a check, the samples of a dry run, the
     *  jobs whose items repeat a key */
    protected function finalCheck(): array
    {
        $report     = [];
        $collisions = self::collisionsOf((string) $this->collisions);
        if ($collisions['jobs']) {
            $report['collisions'] = $collisions;
        }
        if ($this->mode() === 'check') {
            $report['check'] = json_decode((string) $this->check, true);
            $report['probe'] = ['probed' => $this->counts()['streams'], 'unchanged' => $this->counts()['unchanged']];
        }
        if ($this->isDry()) {
            $report['samples'] = self::listOf((string) $this->samples);
        }
        return $report;
    }

    /**
     * Three steps without progress. A step that never fits a queue round ends in error - skipping
     * what does not fit would lose data quietly. A cycle skips the rest of its stream: skipping one
     * job would let the source hand out the cycle again. Any other failure skips the job (the next
     * one gets failed = 1, the last one closes the stream without its version) once check() says
     * the source is up; a stream whose jobs() fails then is closed. A source that cannot say it is
     * up, or is down, ends the run in error with the failure's code; one that refuses, with its reason.
     */
    protected function onRetriesExhausted(BatchStepResult $last): ?BatchStepResult
    {
        $pos  = RunCursor::fromPosition($last->position);
        $keys = $this->streamKeys();
        $ctx  = null;
        // a check has nothing to skip: its cycle, its failed check() end it
        if (($last->retry === 'NO_PROGRESS') || ($this->mode() === 'check')) {
            return null;
        }
        try {
            if ($last->retry === 'SOURCE_CYCLE') {
                return $this->skipped($last, $pos, RunCursor::nextStream($pos), $keys);
            }
            if ($pos['end'] === 1) {
                // the jobs went well, only the version could not be had: the stream closes without it
                return $this->skipped($last, $pos, RunCursor::nextStream($pos), $keys, 'VERSION_UNKNOWN');
            }
            $source = $this->source();
            // a check() at the start that never answered leaves no job to skip; a step that passed it
            // (its cursor stayed, see stopped()) or died may have got past it
            $passed = (($last->extra['check'] ?? null) instanceof CheckResult);
            if (!($source instanceof Checkable) || ($this->checksAtStart($source, $pos) && !$passed && ($last->retry !== 'STEP_DIED'))) {
                return null;
            }
            // the step's budget may be spent by now; the lease must outlast the decision
            $deadline = min(static::microNow() + self::DECIDE_SECONDS, (float) $this->lease - 30);
            $budget   = new BatchBudget($deadline, 0, 0, static::microNow(...));
            $ctx      = $this->context($source, $budget);
            if (!$source->check($ctx)->ok) {
                return null;
            }
            // streams the source cannot list are the whole source failing, not this stream
            $streams = self::streamsOf($source);
            $gone    = false;
            try {
                // a stream failing before its first job has no job to skip
                $next = ($pos['job'] === '') ? RunCursor::nextStream($pos) : $this->jobAfter($source, $ctx, $budget, $streams[$keys[$pos['i']] ?? ''] ?? null, $pos);
                $gone = ($next === null);
                $next ??= RunCursor::nextStream($pos);
            } catch (SourceRefused $e) {
                throw $e;
            } catch (SourceFailure|TransportException) {
                if ($budget->secondsLeft() < 1) {
                    // cut short by the hook's own deadline: nothing says the page is broken
                    return null;
                }
                // the source is up, a page of this stream is not: the rest of the stream cannot be reached
                $next = RunCursor::nextStream($pos);
            }
            return $this->skipped($last, $pos, $next, $keys, gone: $gone);
        } catch (BatchBudgetSpent|SourceUnavailable|TransportException) {
            return null;
        } catch (\Throwable $e) {
            throw $this->failure($e, $keys, $pos, $ctx);
        }
    }

    /** a run that never got a step through: the queue round is shorter than a step needs */
    protected function progressExtra(): array
    {
        return ((string) $this->error_code === 'NO_PROGRESS') ? ['hint' => 'TOEXECUTE_ALLOWED_TIME'] : [];
    }

    /** From the cursor $from on, as far as the budget goes. */
    private function walk(array $from, BatchBudget $budget): BatchStepResult
    {
        if (!in_array($this->mode(), self::MODES, true) || ($this->targetSlice() === '')) {
            throw new BatchFailed('DEFINITION', 'run');
        }
        $keys    = $this->streamKeys();
        $pos     = $from;
        $ctx     = null;
        try {
            if ($pos['ccode'] !== '') {
                throw new BatchFailed($pos['ccode'], 'check');
            }
            $source  = $this->source();
            $ctx     = $this->context($source, $budget);
            if ($this->mode() === 'check') {
                if ($this->isDry()) {
                    // a check stores nothing in the slice anyway; dry it would not store what it is for
                    throw new BatchFailed('DEFINITION', 'run');
                }
                return $this->walkCheck($source, $ctx, $budget, $from, $pos, $keys);
            }
            $mapping = $this->mapping();
            $first   = true;   // the first call of a step always goes
            if ($this->checksAtStart($source, $pos) && !$this->checkSource($source, $ctx, $pos, $first)) {
                return $this->result($pos, false, $keys);
            }
            $streams = self::streamsOf($source);
            $changed = ($this->mode() === 'changed');
            $state   = $this->streamState();
            while (true) {
                if ($pos['after'] !== '') {
                    if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                        return $this->stopped($pos, $from, $keys, '');
                    }
                    $first  = false;
                    $closed = $streams[$pos['after']] ?? null;
                    $pos['after'] = '';
                    if ($closed !== null) {
                        $this->afterStream($closed);
                    }
                    continue;
                }
                if (($pos['i'] >= count($keys)) || $this->limitReached()) {
                    return $this->result($pos, true, $keys);
                }
                $key    = $keys[$pos['i']];
                $stream = $streams[$key] ?? null;
                if ($stream === null) {
                    $this->note('STREAM_GONE');
                    $pos = RunCursor::nextStream($pos);
                    continue;
                }
                $known = $state?->versionOf($key);
                // probed once: a stream whose jobs keep failing must not count a new probe as progress
                if (($pos['job'] === '') && ($pos['pages'] === 0) && ($pos['probed'] === 0) && $changed && ($source instanceof Probeable)) {
                    if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                        return $this->stopped($pos, $from, $keys, '');
                    }
                    $first = false;
                    $probe = $source->probe($stream, $ctx, $known);
                    $this->streamRecords[$key] = ['probed' => static::now()];
                    if ($probe->state === Probe::UNCHANGED) {
                        $this->tally['unchanged']++;
                        $pos = RunCursor::nextStream($pos);
                        continue;
                    }
                    $pos['probed'] = 1;
                    $pos['pv']     = self::sourceVersion($ctx, $probe->version);
                }
                if ($pos['end'] === 0) {
                    $halt = $this->walkJobs($source, $ctx, $stream, $known, $mapping, $budget, $pos, $first);
                    if ($halt === self::STOP) {
                        return $this->limitReached() ? $this->result($pos, true, $keys) : $this->stopped($pos, $from, $keys, '');
                    }
                    if ($halt !== '') {
                        // what got stored before counts; from here on the step makes no progress
                        return $this->stopped($pos, $from, $keys, $halt);
                    }
                    $pos['end'] = 1;
                }
                if ($this->probesAtEnd($source, $stream, $pos)) {
                    // the jobs are done and stay done: a probe that does not fit or fails is all that is repeated
                    if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                        return $this->stopped($pos, $from, $keys, '');
                    }
                    $first = false;
                }
                $pos = $this->streamDone($source, $ctx, $stream, $pos, $known);
            }
        } catch (BatchBudgetSpent) {
            return $this->stopped($pos, $from, $keys, '');
        } catch (SourceUnavailable|TransportException $e) {
            // a request cut short by the step's own end is no failure of the source
            $retry = match (true) {
                ($e instanceof SourceUnavailable) && ($e->reason === 'SOURCE_UNSTABLE') => $e->reason,
                $budget->secondsLeft() < 1                                             => '',
                default => ($e instanceof SourceUnavailable) ? $e->reason : 'TRANSPORT',
            };
            return $this->stopped($pos, $from, $keys, $retry);
        } catch (\Throwable $e) {
            throw $this->failure($e, $keys, $pos, $ctx);
        }
    }

    /**
     * What ends the run for good: a BatchFailed as it is, a refusal of the source under its reason,
     * anything else as SOURCE, logged by its class only - its text may carry data or a key.
     * @param list<string> $keys
     */
    private function failure(\Throwable $e, array $keys, array $pos, ?Context $ctx): BatchFailed
    {
        if ($e instanceof BatchFailed) {
            return $e;
        }
        $where = RunCursor::where($keys[$pos['i']] ?? '', $pos['job'], $ctx);
        if ($e instanceof SourceRefused) {
            // a parameter that makes two rows fill one field is a fault of the definition
            return new BatchFailed(($e->reason === 'DUPLICATE_TARGET') ? 'DEFINITION' : $e->reason, $where);
        }
        AA_Log::write(Toexecute::FAILED_EVENT, $this->selector(), Toexecute::failureParams($this->getId(), $e));
        return new BatchFailed('SOURCE', $where);
    }

    /** @return array<string, Stream> the streams the source has now, by key - each key once */
    public static function streamsOf(SourceInterface $source): array
    {
        $streams = [];
        foreach ($source->streams() as $stream) {
            $streams[$stream->key] = $stream;
        }
        return $streams;
    }

    /** the versions jobs() gets in the changed mode; null = the run imports everything */
    private function streamState(): ?StreamState
    {
        return ($this->mode() === 'changed') ? StreamState::forDefinition($this->definitionId()) : null;
    }

    /**
     * The check mode from the cursor on: check() once, probe() stream by stream, then the catalog
     * page by page - its cursor guarded as the jobs of a stream are. Nothing goes into the slice; the
     * end of the step stores what it found. What ends a check (a failed check(), a refusal, a cycle,
     * CATALOG_TOO_BIG) goes into the cursor and ends the run in the next step - after this one stored
     * the check() and the probes. A catalog that failed keeps the pages it got, unfinished (no time),
     * until a check fills it again.
     * @param list<string> $keys
     */
    private function walkCheck(SourceInterface $source, Context $ctx, BatchBudget $budget, array $from, array &$pos, array $keys): BatchStepResult
    {
        $first = true;   // the first call of a step always goes
        try {
            if (($pos['checked'] === 0) && !$this->checkSource($source, $ctx, $pos, $first)) {
                return $this->result($pos, false, $keys);
            }
            $streams = ($source instanceof Probeable) ? self::streamsOf($source) : [];
            // the versions imported so far tell probe() what is new
            $state   = StreamState::forDefinition($this->definitionId());
            while (($source instanceof Probeable) && ($pos['i'] < count($keys))) {
                if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                    return $this->stopped($pos, $from, $keys, '');
                }
                $first  = false;
                $key    = $keys[$pos['i']];
                $stream = $streams[$key] ?? null;
                if ($stream === null) {
                    $this->note('STREAM_GONE');
                } else {
                    $unchanged = ($source->probe($stream, $ctx, $state?->versionOf($key))->state === Probe::UNCHANGED);
                    $this->streamRecords[$key] = ['probed' => static::now()];
                    $this->tally['streams']++;
                    $this->tally['unchanged'] += $unchanged ? 1 : 0;
                }
                $pos = RunCursor::nextStream($pos);
            }
            $pos['i'] = count($keys);
            while (($source instanceof Cataloged) && (($pos['pages'] === 0) || ($pos['cat'] !== ''))) {
                if (RunCursor::cycles($pos, $pos['cat'])) {
                    throw new SourceRefused('SOURCE_CYCLE', 'The catalog cursor goes round');
                }
                if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                    return $this->stopped($pos, $from, $keys, '');
                }
                $first = false;
                $page  = $source->catalog($ctx, $pos['cat']);
                // a fill starts anew on its first page; later pages add to what the steps before stored
                $this->catalog ??= (($pos['pages'] === 0) ? null : Catalog::forDefinition($this->definitionId())) ?? Catalog::create($this->definitionId());
                $this->catalog->addPage($page, static::now());
                $pos = RunCursor::pageRead($pos, $page->next);
            }
        } catch (SourceRefused $e) {
            $pos['ccode'] = $e->reason;
            return $this->result($pos, false, $keys);
        }
        return $this->result($pos, true, $keys);
    }

    /**
     * check() once, at the start of a check or of a live run; $first is false once it asked. A
     * failure goes into the cursor and ends the run in the next step, after this one stored the
     * check(); a refusal thrown is kept by its reason only - its text may carry a URL with a token
     * redact() does not know. An outage is thrown and retried as any failure to reach the source.
     * @return bool whether the run goes on
     */
    private function checkSource(SourceInterface $source, Context $ctx, array &$pos, bool &$first): bool
    {
        if ($source instanceof Checkable) {
            $first = false;
            try {
                $check = $source->check($ctx);
            } catch (SourceRefused $e) {
                $check = new CheckResult(false, $e->reason, '');
            }
            $this->checkResult = new CheckResult($check->ok, $check->code, $ctx->redact($check->message));
            $pos['ccode']      = $check->ok ? '' : $check->code;
        }
        $pos['checked'] = 1;
        return $pos['ccode'] === '';
    }

    /** a live run checks the source before its first job; a dry one stores nothing a failure would spoil */
    private function checksAtStart(SourceInterface $source, array $pos): bool
    {
        return !$this->isDry() && ($pos['checked'] === 0) && ($source instanceof Checkable);
    }

    /** Continue asks check() again: the user may have fixed what it refused */
    protected function positionOnResume(array $position): array
    {
        return (($position['ccode'] ?? '') === '') ? $position : (['ccode' => '', 'checked' => 0] + $position);
    }

    /** run mode, a stream versioned by probe(): it is asked when the stream is done */
    private function probesAtEnd(SourceInterface $source, Stream $stream, array $pos): bool
    {
        return !$this->isDry() && ($pos['failed'] === 0) && ($stream->versionField === '') && ($this->mode() === 'run') && ($source instanceof Probeable);
    }

    /** A stream went through its last job: its version when nothing of it failed, its expression due
     *  when it stored something - both only in a live run.
     *  @return array the cursor on the next stream */
    private function streamDone(SourceInterface $source, Context $ctx, Stream $stream, array $pos, ?string $known): array
    {
        if (!$this->isDry() && ($pos['failed'] === 0)) {
            [$version, $probed] = $this->versionOf($source, $ctx, $stream, $pos, $known);
            $record = ['imported' => static::now(), 'run' => (string) $this->getId()];
            // '' says nothing of the data: the version stored stays
            $record += ($version !== '') ? ['v' => $version] : [];
            $record += ($probed !== null) ? ['probed' => $probed] : [];
            $this->streamRecords[$stream->key] = $record + ($this->streamRecords[$stream->key] ?? []);
        }
        $this->tally['streams']++;
        return ['after' => $this->afterDue($pos, $stream->key)] + RunCursor::nextStream($pos);
    }

    /** the key of a stream just closed when its expression is due: a live run that stored something */
    private function afterDue(array $pos, string $key): string
    {
        return (!$this->isDry() && ($pos['wrote'] === 1) && (($this->snapshot()['after_stream'] ?? '') !== '')) ? $key : '';
    }

    /**
     * The data version of a stream done: the maximum of its versionField - in the changed mode not
     * below the one known, the jobs got only what is newer - else what probe() says, at its start in
     * the changed mode, now in the run mode; else none.
     * @return array{string, ?int} the version, and when probe() was asked for it
     */
    private function versionOf(SourceInterface $source, Context $ctx, Stream $stream, array $pos, ?string $known): array
    {
        if ($stream->versionField !== '') {
            return [($this->mode() === 'changed') ? ParamsGrabber::laterVersion($known ?? '', $pos['vmax']) : $pos['vmax'], null];
        }
        if (!($source instanceof Probeable)) {
            return ['', null];
        }
        if ($this->mode() === 'changed') {
            return [$pos['pv'], null];
        }
        return [self::sourceVersion($ctx, $source->probe($stream, $ctx, null)->version), static::now()];
    }

    /** a version from the source as the cursor and StreamState keep it - its keys masked, clean, cut */
    private static function sourceVersion(Context $ctx, string $version): string
    {
        return StreamState::cleanVersion($ctx->redact($version));
    }

    /**
     * The expression after a stream over the stream's parameters, in the target slice; its output is
     * dropped. It runs in the step, so its time is the step's; a failure is an error of the run
     * without its text, which may carry data of the source.
     */
    private function afterStream(Stream $stream): void
    {
        $expression = $this->snapshot()['after_stream'] ?? '';
        if ($expression === '') {
            return;
        }
        $item = new AA_Item();
        foreach ($stream->params as $name => $value) {
            // the flag given, AA_Value does not look into the value
            $item->setAaValue((string) $name, new AA_Value($value, 0));
        }
        try {
            $this->evaluate($expression, $item);
        } catch (\Throwable $e) {
            AA_Log::write(Toexecute::FAILED_EVENT, $this->selector(), Toexecute::failureParams($this->getId(), $e));
            $this->tally['errors']++;
            if (count($this->itemErrors) < self::ERRORS_KEPT) {
                $this->itemErrors[] = ['stream' => $stream->key, 'job' => '', 'code' => 'AFTER_STREAM', 'msg' => ''];
            }
        }
    }

    /** $expression over $item as in the target slice */
    private function evaluate(string $expression, AA_Item $item): void
    {
        $slice        = AA::$slice_id;
        AA::$slice_id = $this->targetSlice();
        try {
            $this->unalias($expression, $item);
        } finally {
            AA::$slice_id = $slice;
        }
    }

    /** the template engine of AA; a test puts a recorder here */
    protected function unalias(string $expression, AA_Item $item): void
    {
        AA::Un($expression, '', $item);
    }

    /**
     * The jobs of one stream from the cursor on. A job gone from the source (its parameters changed)
     * restarts the stream once - storing by the key is idempotent; a source that keeps changing
     * under the run, repeats the key of the job before or hands out more than MAX_PAGES jobs,
     * cannot be followed.
     * @return string '' = the stream is done, STOP = the step stops at $pos, else why it stops there
     *                and cannot go on (SOURCE_UNSTABLE, SOURCE_CYCLE)
     */
    private function walkJobs(SourceInterface $source, Context $ctx, Stream $stream, ?string $known, Mapping $mapping, BatchBudget $budget, array &$pos, bool &$first): string
    {
        $seek   = $pos['job'];
        $passed = 0;
        foreach ($source->jobs($stream, $ctx, $known) as $job) {
            if ($seek !== '') {
                if ($job->key !== $seek) {
                    // the cursor's job was the pages-th at most: a source that has not handed it out by now lost it
                    if (++$passed >= RunCursor::MAX_PAGES) {
                        break;
                    }
                    continue;
                }
                $seek = '';
            } else {
                if (RunCursor::cycles($pos, $job->key)) {
                    return 'SOURCE_CYCLE';
                }
                $pos = RunCursor::started($pos, $job->key);
                if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                    return self::STOP;
                }
            }
            $first = false;
            $from  = $pos;
            $halt  = $this->runJob($job, $stream, $mapping, $budget, $ctx, $pos);
            if ($halt !== '') {
                return $halt;
            }
            if (RunCursor::jobCounts($from, $pos)) {
                $this->tally['jobs']++;
            }
        }
        if ($seek === '') {
            return '';
        }
        if ($pos['restarts'] > 0) {
            return 'SOURCE_UNSTABLE';
        }
        $this->note('JOB_GONE');
        $pos = RunCursor::restarted($pos);
        return $this->walkJobs($source, $ctx, $stream, $known, $mapping, $budget, $pos, $first);
    }

    /**
     * One job from the cursor's offset until it ends or the budget does; the second restart of a
     * stream (its first items changed again) fails prepare() with SOURCE_UNSTABLE.
     * @return string '' = the job is done, STOP = the step stops at $pos (budget or limit)
     */
    private function runJob(Job $job, Stream $stream, Mapping $mapping, BatchBudget $budget, Context $ctx, array &$pos): string
    {
        $inner = $job->grabber;
        // past the guard of Context::http(), items stored as the source gives them, a wait the budget does not know
        if (!$inner->fetchesViaLoadData() || $inner->isDirectfill() || (($inner->loadFrequency() ?? 0.0) > 0)) {
            throw new BatchFailed('DEFINITION', RunCursor::where($stream->key, $pos['job'], $ctx));
        }
        $params  = $job->params($stream);
        // a stream that started over once already cannot be followed: refused before anything is stored
        $grabber = new ParamsGrabber($inner, $params, $pos['offset'], $pos['fp'], $stream->versionField, $pos['restarts'] < 1);
        $t13s    = $mapping->forJob($params, $this->targetSlice());
        $grabber->setHttp($ctx->http());
        $saver = $this->saver($grabber, $t13s, $mapping)->setImportMode()->setContext($params)->setRedact($ctx->redact(...));
        foreach ($mapping->validate as $expression) {
            $saver->addValidate($expression);
        }
        $offset = $pos['offset'];
        try {
            $saver->run(!$this->isDry(), $budget, $this->limitLeft());
        } catch (SourceFailure|TransportException|BatchBudgetSpent $e) {
            // what got stored before the source failed stays stored - the cursor goes past it
            if ($grabber->fingerprint() !== '') {
                $this->took($grabber, $saver, $stream->key, $job->key, $ctx, $offset, $pos);
            }
            throw $e;
        } finally {
            if (!$this->isDry()) {
                $this->touched += array_fill_keys($saver->changedModules(), true);
            }
        }
        if ($grabber->fingerprint() === '') {
            // the budget ran out before the job was fetched
            return self::STOP;
        }
        $this->took($grabber, $saver, $stream->key, $job->key, $ctx, $offset, $pos);
        return $saver->reachedEnd() ? '' : self::STOP;
    }

    /** what the Saver did in the job counts, and the cursor moves past it */
    private function took(ParamsGrabber $grabber, Saver $saver, string $stream, string $job, Context $ctx, int $offset, array &$pos): void
    {
        $base = $grabber->restarted() ? 0 : $offset;
        $this->tally['read'] += $grabber->offset() - $base;
        foreach ($saver->counts() as $name => $count) {
            $this->tally[$name] += $count;
        }
        foreach ($saver->errors() as $error) {
            $this->itemError($stream, $job, $error, $base, $ctx);
        }
        if ($saver->collisions() > 0) {
            $this->stepCollisions[] = ['stream' => $stream, 'job' => $ctx->redact($job), 'count' => $saver->collisions()];
        }
        if ($this->isDry()) {
            $this->stepSamples = array_slice(array_merge($this->stepSamples, $saver->samples()), 0, self::SAMPLES_KEPT);
        }
        $pos['restarts'] += $grabber->restarted() ? 1 : 0;
        $pos['wrote']     = (($pos['wrote'] === 1) || ($saver->changedIds() !== [])) ? 1 : 0;
        // items lost to errors: changed must not skip them next time
        $pos['failed']    = (($pos['failed'] === 1) || ($saver->counts()['errors'] > 0)) ? 1 : 0;
        $pos['vmax']      = ParamsGrabber::laterVersion($pos['vmax'], self::sourceVersion($ctx, $grabber->versionMax()));
        $pos['offset']    = $grabber->offset();
        $pos['fp']        = $grabber->fingerprint();
    }

    /** the mapping of the snapshot, refused as a whole when it does not fit the slice - not item by item */
    private function mapping(): Mapping
    {
        try {
            $mapping = Definition::mappingOf($this->snapshot());
        } catch (SourceRefused) {
            throw new BatchFailed('DEFINITION', 'mapping');
        }
        $problems = $mapping->problems($this->targetSlice());
        if ($problems) {
            throw new BatchFailed('DEFINITION', implode(' ', $problems));
        }
        return $mapping;
    }

    /**
     * The cursor on the job after the one at $pos, a transition as in walkJobs(); the rest of the
     * stream is skipped when there is no such job (the last one, or one gone) or it would cycle -
     * else a source down for good with a cyclic generator would skip A, B, A, .. for ever.
     * null = the stream is gone.
     */
    private function jobAfter(SourceInterface $source, Context $ctx, BatchBudget $budget, ?Stream $stream, array $pos): ?array
    {
        if ($stream === null) {
            return null;
        }
        $found  = false;
        $passed = 0;
        foreach ($source->jobs($stream, $ctx, $this->streamState()?->versionOf($stream->key)) as $job) {
            if ($found) {
                return RunCursor::cycles($pos, $job->key) ? RunCursor::nextStream($pos) : (['failed' => 1] + RunCursor::started($pos, $job->key));
            }
            if ($budget->spent()) {
                throw new BatchBudgetSpent('No time left to find the job after the failed one');
            }
            $found = ($job->key === $pos['job']);
            // as in walkJobs(): the cursor's job comes within MAX_PAGES jobs, or it is gone
            if (!$found && (++$passed >= RunCursor::MAX_PAGES)) {
                break;
            }
        }
        return RunCursor::nextStream($pos);
    }

    /**
     * $last going on from $pos past a failed job (JOB_FAILED), or the rest of a stream. A stream
     * closed here gets its expression due as if walk() closed it - unless it is gone.
     * @param list<string> $keys
     */
    private function skipped(BatchStepResult $last, array $from, array $pos, array $keys, string $warning = 'JOB_FAILED', bool $gone = false): BatchStepResult
    {
        $counts             = $last->counts;
        $warnings           = $last->warnings;
        $warnings[$warning] = ($warnings[$warning] ?? 0) + 1;
        if ($warning === 'JOB_FAILED') {
            $counts['skipped_jobs'] = ($counts['skipped_jobs'] ?? 0) + 1;
        }
        if ($pos['i'] > $from['i']) {
            // an expression still due from the stream before must not get lost with the cursor
            $pos['after'] = $from['after'];
            if ($gone) {
                $warnings['STREAM_GONE'] = ($warnings['STREAM_GONE'] ?? 0) + 1;
            } else {
                $counts['streams'] = ($counts['streams'] ?? 0) + 1;
                $pos['after']      = $this->afterDue($from, (string) ($keys[$from['i']] ?? '')) ?: $from['after'];
            }
        }
        return new BatchStepResult($pos, false, $counts, $warnings, $keys[$pos['i']] ?? '', min($pos['i'], count($keys)), count($keys), '', $last->extra);
    }

    /** @param array{code: string, msg: string} $error the Saver numbers items within its run - in the job they come after $base */
    private function itemError(string $stream, string $job, array $error, int $base, Context $ctx): void
    {
        if (count($this->itemErrors) >= self::ERRORS_KEPT) {
            return;
        }
        $msg = (string) preg_replace_callback('/^Item (\d+)/', static fn(array $m): string => 'Item ' . ((int) $m[1] + $base), $error['msg']);
        $this->itemErrors[] = ['stream' => $stream, 'job' => $ctx->redact($job), 'code' => $error['code'], 'msg' => $ctx->redact($msg)];
    }

    private function note(string $code): void
    {
        $this->notes[$code] = ($this->notes[$code] ?? 0) + 1;
    }

    /** @param list<string> $keys */
    private function result(array $pos, bool $finished, array $keys, string $retry = ''): BatchStepResult
    {
        return new BatchStepResult(
            $pos,
            $finished,
            $this->tally,
            $this->notes,
            $keys[$pos['i']] ?? '',
            min($pos['i'], count($keys)),
            count($keys),
            $retry,
            ['errors' => $this->itemErrors, 'streams' => $this->streamRecords, 'samples' => $this->stepSamples, 'check' => $this->checkResult, 'catalog' => $this->catalog, 'collisions' => $this->stepCollisions],
        );
    }

    /** a step cut short at $pos: progress, or no progress with a reason the core can retry
     *  @param list<string> $keys */
    private function stopped(array $pos, array $from, array $keys, string $retry): BatchStepResult
    {
        if (($retry !== '') && ($this->mode() !== 'check') && BatchPosition::same($from, ['checked' => $from['checked']] + $pos)) {
            // a check() passed is no progress of a step that failed before any job started (streams(),
            // jobs(), probe()): those get their three tries; the next step asks again
            $pos = $from;
        }
        if (($retry === '') && BatchPosition::same($from, $pos)) {
            $retry = 'NO_PROGRESS';
        }
        return $this->result($pos, false, $keys, $retry);
    }

    /** source items the run read in the steps before and in this one */
    private function readSoFar(): int
    {
        return $this->counts()['read'] + $this->tally['read'];
    }

    /** @phpstan-impure the step's tally grows between calls */
    private function limitReached(): bool
    {
        $limit = (int) $this->limit;
        return ($limit > 0) && ($this->readSoFar() >= $limit);
    }

    /** what the Saver may still read; 0 = no limit */
    private function limitLeft(): int
    {
        $limit = (int) $this->limit;
        return ($limit > 0) ? max(1, $limit - $this->readSoFar()) : 0;
    }

    /** a dry run stored nothing @param list<string> $slices */
    private function invalidatePages(array $slices): void
    {
        $slices = array_values(array_unique(array_filter($slices, is_long_id(...))));
        if (!$this->isDry() && $slices) {
            AA::Pagecache()->invalidateFor($slices);
        }
    }

    /** the JSON list $json with $more added, $max items at most */
    private static function addUpTo(string $json, array $more, int $max): string
    {
        return json_encode(array_slice(array_merge(self::listOf($json), array_values($more)), 0, $max), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * The key collisions $json with $more added: a job split over steps adds up in its entry, a new
     * job gets one while fewer than COLLISIONS_KEPT are listed, else it counts in more.
     * @param list<array{stream: string, job: string, count: int}> $more
     */
    private static function addCollisions(string $json, array $more): string
    {
        $collisions = self::collisionsOf($json);
        foreach ($more as $entry) {
            foreach ($collisions['jobs'] as $i => $kept) {
                if (is_array($kept) && (($kept['stream'] ?? null) === $entry['stream']) && (($kept['job'] ?? null) === $entry['job'])) {
                    $collisions['jobs'][$i]['count'] = (int) ($kept['count'] ?? 0) + $entry['count'];
                    continue 2;
                }
            }
            if (count($collisions['jobs']) < self::COLLISIONS_KEPT) {
                $collisions['jobs'][] = $entry;
            } else {
                $collisions['more'] += $entry['count'];
            }
        }
        return json_encode($collisions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return array{jobs: list<mixed>, more: int} the key collisions $json; none when it cannot be read */
    private static function collisionsOf(string $json): array
    {
        $collisions = json_decode($json, true);
        $jobs       = $collisions['jobs'] ?? null;
        return [
            'jobs' => (is_array($jobs) && array_is_list($jobs)) ? $jobs : [],
            'more' => (int) ($collisions['more'] ?? 0),
        ];
    }

    /** @return list<mixed> the JSON list $json; [] when it cannot be read */
    private static function listOf(string $json): array
    {
        $list = json_decode($json, true);
        return (is_array($list) && array_is_list($list)) ? $list : [];
    }

    /** @return array<string, string> the copy of the definition the run works with; [] when it cannot be read */
    public function snapshot(): array
    {
        try {
            $data = json_decode((string) $this->snapshot, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($data) ? array_map('strval', array_filter($data, 'is_string')) : [];
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        try {
            return Definition::settingsOf($this->snapshot());
        } catch (SourceRefused) {
            throw new BatchFailed('DEFINITION', 'settings');
        }
    }

    /** @return list<string> */
    private function streamKeys(): array
    {
        try {
            $keys = json_decode((string) $this->stream_keys, true, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $keys = null;
        }
        if (!is_array($keys) || !array_is_list($keys) || (count(array_filter($keys, 'is_string')) !== count($keys))) {
            throw new BatchFailed('DEFINITION', 'stream_keys');
        }
        return $keys;
    }
}
