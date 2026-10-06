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
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One run of an import definition, in queue steps: stream by stream, job by job, item by item
 * through ParamsGrabber and the Saver into the target slice. It works only with its snapshot of
 * the definition, so editing the definition changes the next run, not this one.
 *
 * The position is the cursor of the source (RunCursor): the stream (index in stream_keys), the
 * job and how far into it (offset and the fingerprint of its first items), plus what a stream
 * carries from step to step until it ends - pages and cur (jobs started and the last one), wrote,
 * failed, vmax, restarts, skips.
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

    /** what a run counts, all of them in this order; filtered = left out by the filter of the definition,
     *  apart from skipped (empty key, store mode), so a filter that keeps nothing shows */
    private const COUNTS = [
        'read' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'filtered' => 0, 'errors' => 0,
        'jobs' => 0, 'streams' => 0, 'unchanged' => 0, 'skipped_jobs' => 0, 'unversioned' => 0,
    ];

    /** item errors a step hands over, and the run keeps, at most */
    private const ERRORS_KEPT = 20;

    /** items a dry run shows */
    private const SAMPLES_KEPT = 5;

    /** jobs whose key collisions the run lists; the rest it only counts */
    private const COLLISIONS_KEPT = 20;

    /** items skipped for an empty key the run shows */
    private const EMPTY_KEYS_KEPT = 20;

    /** remarks of the source the run shows */
    private const REMARKS_KEPT = 20;

    private const MODES = ['run', 'changed', 'check', 'baseline'];

    /** the warning of a job the filter kept no item of */
    public const FILTER_KEPT_NONE = 'FILTER_KEPT_NONE';

    /** the warnings of the remarks of the source, by their code: {REMARK: {code: count}} */
    public const REMARK = 'REMARK';

    /** the warning of a stream skipped as the source went round; a remark of the same code names the stream -
     *  one of Context::RUN_REMARK_CODES, so no source can make it up */
    public const SOURCE_CYCLE = 'SOURCE_CYCLE';

    /** the modes that store nothing in the slice: they ask the source and record what it says */
    public const SOURCE_ONLY_MODES = ['check', 'baseline'];

    /** what walkJobs() and runJob() return when the step stops at the cursor */
    private const STOP = 'stop';

    /** seconds onRetriesExhausted() gives check() and jobs(), ending 30 s before the lease at the latest */
    private const DECIDE_SECONDS = 15;

    /** run (every stream) | changed (streams probe() does not call unchanged) | check (check(), probe(), catalog())
     *  | baseline (check(), then each stream recorded at the version probe() gives, without importing it) */
    protected $mode = '';
    /** '1' = a dry run: nothing stored */
    protected $dry = '';
    /** source items the run reads at most; '' = all */
    protected $limit = '';
    /** JSON of Definition::snapshot() at the start */
    protected $snapshot = '';
    /** JSON list of the keys of the streams the run goes through */
    protected $stream_keys = '';
    /** '1' = stream_keys were chosen at the start, not all the source has - one key alone cannot tell */
    protected $streams_chosen = '';
    /** plan:<task> | event:<task> | manual */
    protected $trigger = '';
    /** JSON list of the first item errors {stream, job, code, msg} */
    protected $errors = '';
    /** JSON list of the first items a dry run would store, for its report */
    protected $samples = '';
    /** JSON {ok, code, msg} of the run's check(), for the report of a check or a baseline */
    protected $check = '';
    /** JSON list of the runs a planned start ended to make way for this one, for the report */
    protected $replaced = '';
    /** JSON {jobs: [{stream, job, count}] of the first jobs whose items repeat a key, more: the repeats
     *  in jobs not listed}, for the report. Saver::collisions() of each step's part of a job - a lower
     *  bound; a job read again after JOB_GONE may count twice. */
    protected $collisions = '';
    /** JSON {count, samples: [{stream, job, values: {source field: value}}]} of the items skipped as
     *  their key came out empty (Saver::emptyKeys()), for the report */
    protected $empty_keys = '';
    /** JSON {list: [{code, text}] of the first remarks of the source (Context::remark()), seen: hashes
     *  of all it told apart} - each remark counts once in the warnings, also when a later step repeats it */
    protected $remarks = '';
    /** Milliseconds the steps worked, summed at the end of each - the run's time without its waits in
     *  the queue; '' for a run from before it was counted. A lower bound: a step that died, rolled back
     *  or threw adds nothing, nor do onRetriesExhausted() and the final phase. */
    protected $work_ms = '';

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
    /** @var array{count: int, samples: list<array{stream: string, job: string, values: array<string, string>}>} */
    private array $stepEmptyKeys = ['count' => 0, 'samples' => []];
    /** the check() of the step, its message redacted */
    private ?CheckResult $checkResult = null;
    /** the catalog with the pages of the step, stored at its end */
    private ?Catalog $catalog = null;
    /** the Context of the step - the remarks of the source are in it */
    private ?Context $stepContext = null;
    /** @var list<array{code: string, text: string}> what the run itself remarked in the step, under Context::RUN_REMARK_CODES */
    private array $runRemarks = [];
    /** when work() of the step began */
    private ?float $workFrom = null;

    public static function getClassProperties(): array
    {
        $props = parent::getClassProperties();
        foreach (['mode', 'dry', 'limit', 'snapshot', 'stream_keys', 'streams_chosen', 'trigger', 'errors', 'samples', 'check', 'collisions', 'replaced', 'empty_keys', 'remarks', 'work_ms'] as $id) {
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

    /** @return ?list<string> the streams the user chose for the run; null = all the source has */
    public function chosenStreams(): ?array
    {
        if ((string) $this->streams_chosen !== '1') {
            return null;
        }
        $keys = json_decode((string) $this->stream_keys, true);
        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /** an import: not a check, a baseline or a dry run */
    public function storesItems(): bool
    {
        return self::stores($this->mode(), $this->isDry());
    }

    private static function stores(string $mode, bool $dry): bool
    {
        return !$dry && !in_array($mode, self::SOURCE_ONLY_MODES, true);
    }

    /** The newest run of definition $id and its newest import (storesItems()), the same run when the
     *  newest is one; null = none. Found among all its runs, however many stored nothing since - at
     *  most two runs are read.
     *  @return array{0: ?self, 1: ?self} */
    public static function newestOf(string $id): array
    {
        [$newest, $import] = self::newestIds($id);
        $run = ($newest === null) ? null : self::get($newest);
        return [$run, match ($import) {
            null    => null,
            $newest => $run,
            default => self::get($import),
        }];
    }

    /** the id of the newest import of definition $id - as newestOf(), without reading the run */
    public static function newestImportId(string $id): ?string
    {
        return self::newestIds($id)[1];
    }

    /** @return array{0: ?string, 1: ?string} the ids of the newest run and the newest import */
    private static function newestIds(string $id): array
    {
        $records = self::recordsOfSubject($id, ['mode', 'dry']);
        foreach ($records as $runId => $record) {
            if (self::stores($record['mode'] ?? '', ($record['dry'] ?? '') === '1')) {
                return [(string) array_key_first($records), (string) $runId];
            }
        }
        return [($records === []) ? null : (string) array_key_first($records), null];
    }

    /** a check or a baseline: nothing goes into the slice */
    private function sourceOnly(): bool
    {
        return in_array($this->mode(), self::SOURCE_ONLY_MODES, true);
    }

    /** seconds the steps of the run worked, at least ($work_ms); null when not counted (a run from before) */
    public function workedSeconds(): ?int
    {
        return ((string) $this->work_ms === '') ? null : intdiv((int) $this->work_ms, 1000);
    }

    /** @return array{read: int, new: int, updated: int, skipped: int, filtered: int, errors: int, jobs: int, streams: int, unchanged: int, skipped_jobs: int, unversioned: int} */
    public function counts(): array
    {
        return self::countsOf((string) $this->counts);
    }

    /** the counts a stored `counts` value holds - State Check reads them without loading the run
     *  @return array{read: int, new: int, updated: int, skipped: int, filtered: int, errors: int, jobs: int, streams: int, unchanged: int, skipped_jobs: int, unversioned: int} */
    public static function countsOf(string $json): array
    {
        $stored = json_decode($json, true);
        $counts = self::COUNTS;
        foreach ($counts as $name => $zero) {
            $counts[$name] = (int) (is_array($stored) ? ($stored[$name] ?? $zero) : $zero);
        }
        return $counts;
    }

    /** The first item errors. Text of the source - escape it, never expand it.
     *  @return list<array{stream: string, job: string, code: string, msg: string}> */
    public function errors(): array
    {
        $errors = [];
        foreach (self::listOf((string) $this->errors) as $e) {
            if (is_array($e)) {
                $errors[] = ['stream' => self::text($e['stream'] ?? ''), 'job' => self::text($e['job'] ?? ''), 'code' => self::text($e['code'] ?? ''), 'msg' => self::text($e['msg'] ?? '')];
            }
        }
        return $errors;
    }

    /** What a dry run would store, item by item. Data of the source - escape it, never expand it.
     *  @return list<array<string, list<string>>> field id => values */
    public function samples(): array
    {
        $samples = [];
        foreach (self::listOf((string) $this->samples) as $item) {
            if (is_array($item)) {
                $fields = [];
                foreach ($item as $field => $values) {
                    $fields[(string) $field] = array_values(array_map(self::text(...), is_array($values) ? $values : [$values]));
                }
                $samples[] = $fields;
            }
        }
        return $samples;
    }

    /** Items skipped as their key came out empty, and the source values of the first ones. Data of
     *  the source - escape it, never expand it.
     *  @return array{count: int, samples: list<array{stream: string, job: string, values: array<string, string>}>} */
    public function emptyKeys(): array
    {
        $stored  = json_decode((string) $this->empty_keys, true);
        $samples = [];
        foreach (is_array($stored['samples'] ?? null) ? $stored['samples'] : [] as $s) {
            if (is_array($s)) {
                $values = [];
                foreach (is_array($s['values'] ?? null) ? $s['values'] : [] as $field => $value) {
                    $values[(string) $field] = self::text($value);
                }
                $samples[] = ['stream' => self::text($s['stream'] ?? ''), 'job' => self::text($s['job'] ?? ''), 'values' => $values];
            }
        }
        return ['count' => (int) (is_array($stored) ? ($stored['count'] ?? 0) : 0), 'samples' => $samples];
    }

    /** The remarks of the source (Context::remark()), each code and text once. Text of the source -
     *  escape it, never expand it.
     *  @return list<array{code: string, text: string}> */
    public function remarks(): array
    {
        $remarks = [];
        $stored  = json_decode((string) $this->remarks, true);
        foreach (is_array($stored['list'] ?? null) ? $stored['list'] : [] as $r) {
            $code = is_array($r) ? self::text($r['code'] ?? '') : '';
            if ((preg_match(Context::REMARK_PATTERN, $code) === 1) && (count($remarks) < self::REMARKS_KEPT)) {
                $remarks[] = ['code' => $code, 'text' => mb_substr(self::text($r['text'] ?? ''), 0, Context::REMARK_CHARS)];
            }
        }
        return $remarks;
    }

    /** @return list<string> the hashes of the remarks the run told apart so far */
    private function remarksSeen(): array
    {
        $seen = json_decode((string) $this->remarks, true)['seen'] ?? null;
        return is_array($seen) ? array_values(array_filter($seen, is_string(...))) : [];
    }

    /** @param array{code: string, text: string} $remark */
    private static function remarkHash(array $remark): string
    {
        return hash('xxh64', $remark['code'] . "\0" . $remark['text']);
    }

    /** a stored JSON value as text - what is no scalar shows empty */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
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
    private function context(SourceInterface $source, BatchBudget $budget): Context
    {
        return Context::forSource($source, $this->keys(), $budget, ...$this->connection($budget));
    }

    /**
     * The connection under the guard of Context and the resolver it asks; null = the real ones
     * @param BatchBudget $budget of the Context they serve
     * @return array{0: ?HttpClientInterface, 1: ?\Closure(string): list<string>}
     */
    protected function connection(BatchBudget $budget): array
    {
        return [null, null];
    }

    /** @return list<array{vendor: string, context: string}> the keys of the source type of the snapshot */
    protected function keys(): array
    {
        return Definition::sourceCredentials((string) ($this->snapshot()['source'] ?? ''));
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
        $this->stepEmptyKeys  = ['count' => 0, 'samples' => []];
        $this->checkResult    = null;
        $this->catalog        = null;
        // an instance may work more than one step; remarks of the one before must not count again
        $this->stepContext    = null;
        $this->runRemarks     = [];
        $this->workFrom       = static::microNow();
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
        if (is_int($extra['work_ms'] ?? null)) {
            $this->work_ms = (string) ((int) $this->work_ms + $extra['work_ms']);
            $write[]       = 'work_ms';
        }
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
        if (($extra['empty_keys']['count'] ?? 0) > 0) {
            $kept             = $this->emptyKeys();
            $this->empty_keys = json_encode([
                'count'   => $kept['count'] + $extra['empty_keys']['count'],
                'samples' => array_slice([...$kept['samples'], ...$extra['empty_keys']['samples']], 0, self::EMPTY_KEYS_KEPT),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $write[]          = 'empty_keys';
        }
        if ($extra['remarks'] ?? []) {
            $list = $this->remarks();
            $seen = $this->remarksSeen();
            foreach ($extra['remarks'] as $r) {
                $seen[] = self::remarkHash($r);
                if (count($list) < self::REMARKS_KEPT) {
                    $list[] = $r;
                }
            }
            $this->remarks = json_encode(['list' => $list, 'seen' => $seen], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $write[]       = 'remarks';
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

    /** the report of a done run: its check() and probes of a check, what a baseline recorded, the
     *  samples of a dry run, the jobs whose items repeat a key, the remarks of the source, the runs its
     *  planned start ended */
    protected function finalCheck(): array
    {
        $report     = [];
        if ((string) $this->replaced !== '') {
            $report['replaced'] = self::listOf((string) $this->replaced);
        }
        $collisions = self::collisionsOf((string) $this->collisions);
        if ($collisions['jobs']) {
            $report['collisions'] = $collisions;
        }
        $empty_keys = $this->emptyKeys();
        if ($empty_keys['count'] > 0) {
            $report['empty_keys'] = $empty_keys;
        }
        $remarks = $this->remarks();
        if ($remarks) {
            $report['remarks'] = $remarks;
        }
        if ($this->sourceOnly()) {
            $report['check'] = json_decode((string) $this->check, true);
        }
        if ($this->mode() === 'check') {
            $report['probe'] = ['probed' => $this->counts()['streams'], 'unchanged' => $this->counts()['unchanged']];
        }
        if ($this->mode() === 'baseline') {
            $report['baseline'] = ['streams' => $this->counts()['streams'], 'unversioned' => $this->counts()['unversioned']];
        }
        if ($this->isDry()) {
            $report['samples'] = self::listOf((string) $this->samples);
        }
        return $report;
    }

    /**
     * Three steps without progress. A step that never fits a queue round ends in error - skipping
     * what does not fit would lose data quietly. Any other failure skips the job (the next
     * one gets failed = 1, the last one closes the stream without its version) once check() says
     * the source is up; the third job skipped in a row (RunCursor::SKIPS_IN_A_ROW) skips the rest
     * of its stream (JOBS_FAILING); a stream whose jobs() fails then is closed. A source that cannot
     * say it is up, or is down, ends the run in error with the failure's code; one whose check()
     * fails, with the check's code; one that refuses, with its reason.
     */
    protected function onRetriesExhausted(BatchStepResult $last): ?BatchStepResult
    {
        $pos  = RunCursor::fromPosition($last->position);
        $keys = $this->streamKeys();
        $ctx  = null;
        // a check or a baseline has nothing to skip: its cycle, its failed check() end it
        if (($last->retry === 'NO_PROGRESS') || $this->sourceOnly()) {
            return null;
        }
        try {
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
            $check    = $source->check($ctx);
            if (!$check->ok) {
                // its code tells the cause (an expired token: AUTH), the last try only how it failed
                throw new BatchFailed($check->code, 'check', $check->detail);
            }
            if (RunCursor::endsTheStream($pos)) {
                // a source that answers check() while its jobs fail would hold the run for days:
                // the job counts as skipped, then the rest of the stream goes
                $job = $this->skipped($last, $pos, $pos, $keys);
                return $this->skipped($job, $pos, RunCursor::nextStream($pos), $keys, 'JOBS_FAILING');
            }
            // streams the source cannot list are the whole source failing, not this stream
            $streams = self::streamsOf($source);
            $gone    = false;
            try {
                // a stream failing before its first job has no job to skip
                $next = ($pos['job'] === '') ? RunCursor::nextStream($pos) : $this->jobAfter($source, $ctx, $budget, $streams[$keys[$pos['i']] ?? ''] ?? null, $pos);
                $gone = ($next === null);
                $next ??= RunCursor::nextStream($pos);
                if ($next['i'] === $pos['i']) {
                    $next = RunCursor::skippedJob($pos, $next);
                }
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

    /** the page tells 404 from DNS, also while the run waits to try again */
    protected function progressError(): ?array
    {
        return $this->lastFailure();
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
                throw new BatchFailed($pos['ccode'], 'check', $pos['cdetail']);
            }
            $source  = $this->source();
            $ctx     = $this->context($source, $budget);
            $this->stepContext = $ctx;
            if ($this->sourceOnly()) {
                if ($this->isDry()) {
                    // it stores nothing in the slice anyway; dry it would not store what it is for
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
                    if (!$first && (($budget->secondsLeft() < $source->minJobSeconds()) || !$this->holdsLease())) {
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
                if (($pos['job'] === '') && ($pos['pages'] === 0) && ($pos['probed'] === 0) && ($source instanceof Probeable) && $this->probesAtStart($stream, $known)) {
                    if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                        return $this->stopped($pos, $from, $keys, '');
                    }
                    $first = false;
                    try {
                        $probe = $source->probe($stream, $ctx, $known);
                        $this->streamRecords[$key] = ['probed' => static::now()];
                    } catch (SourceUnavailable|TransportException $e) {
                        if ($changed || ($budget->secondsLeft() < 1)) {
                            throw $e;
                        }
                        // run imports the stream anyway; without a version the changed mode imports it again
                        $this->note('VERSION_UNKNOWN');
                        $probe = new Probe(Probe::UNKNOWN);
                    }
                    if ($changed && ($probe->state === Probe::UNCHANGED)) {
                        $this->tally['unchanged']++;
                        $pos = RunCursor::nextStream($pos);
                        continue;
                    }
                    $pos['probed'] = 1;
                    $pos['pv']     = self::sourceVersion($ctx, $probe->version);
                }
                $halt = $this->walkJobs($source, $ctx, $stream, $known, $mapping, $budget, $pos, $first);
                if ($halt === self::STOP) {
                    return $this->limitReached() ? $this->result($pos, true, $keys) : $this->stopped($pos, $from, $keys, '');
                }
                if ($halt === self::SOURCE_CYCLE) {
                    // a source may see its cycle only among the pages it fetched in one step: a retry from
                    // the cursor would creep on by a page a step, so the rest of the stream goes now
                    $this->note(self::SOURCE_CYCLE);
                    // not through remark(): a source cannot use the code, so the run keeps its own
                    $this->runRemarks[] = ['code' => self::SOURCE_CYCLE, 'text' => $ctx->remarkText(self::cycleRemark($stream, $pos, RunCursor::jobLimit($source)))];
                    $this->tally['skipped_jobs']++;
                    $this->tally['streams']++;
                    $pos = ['after' => $this->afterDue($pos, $stream->key)] + RunCursor::nextStream($pos);
                    continue;
                }
                if ($halt !== '') {
                    // what got stored before counts; from here on the step makes no progress
                    return $this->stopped($pos, $from, $keys, $halt);
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
            return $this->stopped($pos, $from, $keys, $retry, ($retry === '') ? '' : SourceFailure::detailOf($e));
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
            return new BatchFailed(($e->reason === 'DUPLICATE_TARGET') ? 'DEFINITION' : $e->reason, $where, $e->detail);
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
     * The check mode from the cursor on: check() once, probe() stream by stream - not of one read whole
     * anyway (readWhole()), its answer could not change that - then the catalog
     * page by page - its cursor guarded as the jobs of a stream are. Nothing goes into the slice; the
     * end of the step stores what it found. What ends a check (a failed check(), a refusal, a cycle,
     * CATALOG_TOO_BIG) goes into the cursor and ends the run in the next step - after this one stored
     * the check() and the probes. A catalog that failed keeps the pages it got, unfinished (no time),
     * until a check fills it again.
     * The baseline mode goes the same way to the end of the streams (baselineStream()); the catalog
     * is the check's to fill.
     * @param list<string> $keys
     */
    private function walkCheck(SourceInterface $source, Context $ctx, BatchBudget $budget, array $from, array &$pos, array $keys): BatchStepResult
    {
        $first = true;   // the first call of a step always goes
        $baseline = ($this->mode() === 'baseline');
        try {
            if (($pos['checked'] === 0) && !$this->checkSource($source, $ctx, $pos, $first)) {
                return $this->result($pos, false, $keys);
            }
            $streams = ($source instanceof Probeable) ? self::streamsOf($source) : [];
            // the versions imported so far tell probe() what is new
            $state   = $baseline ? null : StreamState::forDefinition($this->definitionId());
            while (($source instanceof Probeable) && ($pos['i'] < count($keys))) {
                $key    = $keys[$pos['i']];
                $stream = $streams[$key] ?? null;
                $known  = $state?->versionOf($key);
                $whole  = !$baseline && ($stream !== null) && self::readWhole($stream, $known);
                // no request, nothing of the step spent
                if (!$whole) {
                    if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                        return $this->stopped($pos, $from, $keys, '');
                    }
                    $first = false;
                }
                if ($stream === null) {
                    $this->note('STREAM_GONE');
                } elseif ($baseline) {
                    $this->baselineStream($source, $ctx, $stream);
                } else {
                    // checked all the same: the detail lists it as seen, to be imported
                    $unchanged = !$whole && ($source->probe($stream, $ctx, $known)->state === Probe::UNCHANGED);
                    $this->streamRecords[$key] = ['probed' => static::now()];
                    $this->tally['streams']++;
                    $this->tally['unchanged'] += $unchanged ? 1 : 0;
                }
                $pos = RunCursor::nextStream($pos);
            }
            $pos['i'] = count($keys);
            while (!$baseline && ($source instanceof Cataloged) && (($pos['pages'] === 0) || ($pos['cat'] !== ''))) {
                if (RunCursor::cycles($pos, $pos['cat'])) {
                    throw new SourceRefused('SOURCE_CYCLE', 'The catalog cursor goes round');
                }
                if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                    return $this->stopped($pos, $from, $keys, '');
                }
                $first = false;
                $page  = $source->catalog($ctx, $pos['cat']);
                // a fill starts anew on its first page, with the streams it is compared with; later
                // pages add to what the steps before stored
                if (($this->catalog === null) && ($pos['pages'] === 0)) {
                    $this->catalog = Catalog::create($this->definitionId());
                    $this->catalog->listStreams(self::redactedStreams($ctx, $streams ?: self::streamsOf($source)));
                }
                $this->catalog ??= Catalog::forDefinition($this->definitionId()) ?? Catalog::create($this->definitionId());
                $this->catalog->addPage(self::redactedPage($ctx, $page), static::now());
                $pos = RunCursor::pageRead($pos, $page->next);
            }
        } catch (SourceRefused $e) {
            $pos['ccode']   = $e->reason;
            $pos['cdetail'] = $e->detail;
            return $this->result($pos, false, $keys);
        }
        return $this->result($pos, true, $keys);
    }

    /**
     * A stream recorded at the version the source has now, as an import of it would record it, but
     * as a baseline: nothing of it was fetched. The version is what probe() says, asked as the run
     * mode asks it at the end of a stream. A stream versioned by its versionField gets none - the
     * maximum of that field cannot be had without its rows - unless the source vouches for it
     * (FieldMaxProbeable); neither does one probe() tells no version of: its stored version goes,
     * so the next changed run imports it.
     */
    private function baselineStream(Probeable $source, Context $ctx, Stream $stream): void
    {
        $version = '';
        $record  = ['baseline' => static::now(), 'run' => (string) $this->getId()];
        if (($stream->versionField === '') || ($source instanceof FieldMaxProbeable)) {
            $version          = self::sourceVersion($ctx, $source->probe($stream, $ctx, null)->version);
            $record['probed'] = static::now();
        }
        $this->streamRecords[$stream->key] = ['v' => ($version === '') ? null : $version] + $record;
        $this->tally['streams']++;
        $this->tally['unversioned'] += ($version === '') ? 1 : 0;
    }

    /**
     * check() once, at the start of a check, a baseline or a live run; $first is false once it asked. A
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
                $check = new CheckResult(false, $e->reason, '', $e->detail);
            }
            $this->checkResult = new CheckResult($check->ok, $check->code, $ctx->redact($check->message), $check->detail);
            $pos['ccode']      = $check->ok ? '' : $check->code;
            $pos['cdetail']    = $check->ok ? '' : $check->detail;
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
        return (($position['ccode'] ?? '') === '') ? $position : (['ccode' => '', 'cdetail' => '', 'checked' => 0] + $position);
    }

    /**
     * The changed mode asks probe() whether to import the stream, unless it is read whole anyway
     * (readWhole()) - the answer could not spare it; the run mode, for a stream versioned
     * by probe(), for the version of what it imports. Asked at the start: asked at the end, data
     * changed meanwhile would be recorded as imported.
     */
    private function probesAtStart(Stream $stream, ?string $known): bool
    {
        return (($this->mode() === 'changed') && !self::readWhole($stream, $known)) || (($this->mode() === 'run') && !$this->isDry() && ($stream->versionField === ''));
    }

    /** A stream versioned by its field with no version known is read whole whatever probe() says, and
     *  its version comes from the rows: asking would be a request for nothing */
    private static function readWhole(Stream $stream, ?string $known): bool
    {
        return ($stream->versionField !== '') && (($known ?? '') === '');
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
     * below the one known, the jobs got only what is newer - else what probe() said at its start; else none.
     * @return array{string, ?int} the version, and when probe() was asked for it
     */
    private function versionOf(SourceInterface $source, Context $ctx, Stream $stream, array $pos, ?string $known): array
    {
        if ($stream->versionField !== '') {
            return [($this->mode() === 'changed') ? ParamsGrabber::laterVersion($known ?? '', $pos['vmax']) : $pos['vmax'], null];
        }
        return [($source instanceof Probeable) ? $pos['pv'] : '', null];
    }

    /** A catalog page with the source's keys masked in its texts - the import detail shows them. Its
     *  catalog keys are masked too, the same way as the streams' (redactedStreams()), so they still
     *  match; a key with a secret in it then reaches adopt() masked, as no source should build one. */
    private static function redactedPage(Context $ctx, CatalogPage $page): CatalogPage
    {
        $entries = [];
        foreach ($page->entries as $e) {
            $meta = [];
            foreach ($e->meta as $name => $value) {
                // anything but a plain value is refused by the catalog as it is
                $meta[$ctx->redact((string) $name)] = is_scalar($value) ? $ctx->redact((string) $value) : $value;
            }
            $entries[] = new CatalogEntry($ctx->redact($e->key), $ctx->redact($e->name), $meta, $ctx->redact($e->updated), $ctx->redact($e->refusal));
        }
        return new CatalogPage($entries, $page->next);
    }

    /** @param iterable<Stream> $streams  @return list<Stream> the streams with their labels and catalog keys masked as redactedPage() masks entries */
    private static function redactedStreams(Context $ctx, iterable $streams): array
    {
        $out = [];
        foreach ($streams as $s) {
            $out[] = new Stream($s->key, $ctx->redact($s->label), $s->params, $ctx->redact($s->catalogKey), $s->versionField);
        }
        return $out;
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

    /** what the report says of a stream skipped by walkJobs() at $pos: which one, and how the source went round */
    private static function cycleRemark(Stream $stream, array $pos, int $limit): string
    {
        $name = (($stream->label !== '') && ($stream->label !== $stream->key)) ? "{$stream->label} ({$stream->key})" : $stream->key;
        $how  = ($pos['pages'] >= $limit) ? "handed out more than $limit jobs" : 'handed out the same job twice in a row';
        return "Stream $name: the source $how; the rest of the stream was skipped";
    }

    /**
     * The jobs of one stream from the cursor on. A job gone from the source (its parameters changed)
     * restarts the stream once - storing by the key is idempotent; a source that keeps changing
     * under the run cannot be followed; one that repeats the key of the job before or hands out more
     * than its maxJobsPerStream() goes round, and the run skips the rest of the stream - warned as
     * SOURCE_CYCLE, with a remark naming the stream.
     * @return string '' = the stream is done, STOP = the step stops at $pos, SOURCE_UNSTABLE = it stops
     *                there and cannot go on, SOURCE_CYCLE = the stream cannot go on
     */
    private function walkJobs(SourceInterface $source, Context $ctx, Stream $stream, ?string $known, Mapping $mapping, BatchBudget $budget, array &$pos, bool &$first): string
    {
        $seek   = $pos['job'];
        $passed = 0;
        $limit  = RunCursor::jobLimit($source);
        foreach ($source->jobs($stream, $ctx, $known) as $job) {
            if ($seek !== '') {
                if ($job->key !== $seek) {
                    // the cursor's job was the pages-th at most: a source that has not handed it out by now lost it
                    if (++$passed >= $limit) {
                        break;
                    }
                    continue;
                }
                $seek = '';
            } else {
                if (RunCursor::cycles($pos, $job->key, $limit)) {
                    return self::SOURCE_CYCLE;
                }
                $pos = RunCursor::started($pos, $job->key);
                // a step past its lease starts no further job: Continue may give the run to another one
                if (!$first && (($budget->secondsLeft() < $source->minJobSeconds()) || !$this->holdsLease())) {
                    return self::STOP;
                }
            }
            $first = false;
            $from  = $pos;
            $halt  = $this->runJob($job, $stream, $mapping, $budget, $ctx, $pos);
            if ($halt !== '') {
                return $halt;
            }
            $pos['skips'] = 0;
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
        $grabber = new ParamsGrabber($inner, $params, $pos['offset'], $pos['fp'], $stream->versionField, $pos['restarts'] < 1, $mapping->filter, $budget);
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
        $ended = $saver->reachedEnd();
        // the limit counts items read, so it may cut a job after a few items that have no key by chance
        $cut = !$ended && ($pos['rkeyed'] === 0) && $this->limitReached();
        if (($ended || $cut) && ($pos['keyed'] === 0) && ($pos['nokey'] > 0)) {
            $this->keylessJob($stream->key, $job->key, $ctx, $ended, $pos);
        }
        if ($ended && ($pos['fout'] > 0) && ($pos['fout'] === $pos['offset'])) {
            // may be just what the filter is for (a job of districts only) - a remark, not an error
            $this->note(self::FILTER_KEPT_NONE);
        }
        return $ended ? '' : self::STOP;
    }

    /**
     * No item of the job had its key, read to its end or up to the run's limit: the key of the
     * mapping is broken, not the data. One error for the job, and its stream keeps the old version;
     * the items stay skipped, their samples show what the key came from.
     */
    private function keylessJob(string $stream, string $job, Context $ctx, bool $ended, array &$pos): void
    {
        $this->tally['errors']++;
        $pos['failed'] = 1;
        if (count($this->itemErrors) < self::ERRORS_KEPT) {
            $msg = $ended
                ? "No item of the job had its key ({$pos['nokey']} without it) - the key of the mapping is broken"
                : "None of the {$pos['nokey']} items read had its key - the key of the mapping is broken";
            $this->itemErrors[] = ['stream' => $stream, 'job' => $ctx->redact($job), 'code' => 'EMPTY_KEY', 'msg' => $msg];
        }
    }

    /** what the Saver did in the job counts, and the cursor moves past it */
    private function took(ParamsGrabber $grabber, Saver $saver, string $stream, string $job, Context $ctx, int $offset, array &$pos): void
    {
        $base = $grabber->restarted() ? 0 : $offset;
        $this->tally['read'] += $grabber->offset() - $base;
        foreach ($saver->counts() as $name => $count) {
            $this->tally[$name] += $count;
        }
        // left out on purpose: no error, the stream keeps its version
        $this->tally['filtered'] += $grabber->filtered();
        foreach ($saver->errors() as $error) {
            $this->itemError($stream, $job, $error, $base, $ctx);
        }
        if ($saver->collisions() > 0) {
            $this->stepCollisions[] = ['stream' => $stream, 'job' => $ctx->redact($job), 'count' => $saver->collisions()];
        }
        $this->stepEmptyKeys['count'] += $saver->emptyKeys();
        foreach ($saver->emptyKeySamples(self::EMPTY_KEYS_KEPT - count($this->stepEmptyKeys['samples'])) as $values) {
            $this->stepEmptyKeys['samples'][] = ['stream' => $stream, 'job' => mb_substr($ctx->redact($job), 0, Saver::KEY_CHARS), 'values' => $values];
        }
        if ($this->isDry()) {
            $this->stepSamples = array_slice(array_merge($this->stepSamples, $saver->samples()), 0, self::SAMPLES_KEPT);
        }
        $pos['restarts'] += $grabber->restarted() ? 1 : 0;
        // a job read again from its first item counts its keys anew
        $pos['keyed']     = ((($pos['keyed'] === 1) && !$grabber->restarted()) || $saver->keyed()) ? 1 : 0;
        $pos['nokey']     = ($grabber->restarted() ? 0 : $pos['nokey']) + $saver->emptyKeys();
        $pos['fout']      = ($grabber->restarted() ? 0 : $pos['fout']) + $grabber->filtered();
        $pos['rkeyed']    = (($pos['rkeyed'] === 1) || $saver->keyed()) ? 1 : 0;
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
        $limit  = RunCursor::jobLimit($source);
        foreach ($source->jobs($stream, $ctx, $this->streamState()?->versionOf($stream->key)) as $job) {
            if ($found) {
                return RunCursor::cycles($pos, $job->key, $limit) ? RunCursor::nextStream($pos) : (['failed' => 1] + RunCursor::started($pos, $job->key));
            }
            if ($budget->spent()) {
                throw new BatchBudgetSpent('No time left to find the job after the failed one');
            }
            $found = ($job->key === $pos['job']);
            // as in walkJobs(): the cursor's job comes within the source's limit, or it is gone
            if (!$found && (++$passed >= $limit)) {
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

    /**
     * The remarks of the step the run has not seen - its own first - and their count by code: jobs()
     * of a stream runs again in each step that goes on with it and says the same. Past Context::REMARKS_COUNTED
     * distinct ones the run drops new remarks, so the count is a lower bound then.
     * @return array{list<array{code: string, text: string}>, array<string, int>}
     */
    private function newRemarks(): array
    {
        $seen   = array_flip($this->remarksSeen());
        $new    = [];
        $counts = [];
        foreach ([...$this->runRemarks, ...($this->stepContext?->remarks() ?? [])] as $r) {
            $hash = self::remarkHash($r);
            if (!isset($seen[$hash]) && (count($seen) < Context::REMARKS_COUNTED)) {
                $seen[$hash]        = true;
                $new[]              = $r;
                $counts[$r['code']] = ($counts[$r['code']] ?? 0) + 1;
            }
        }
        return [$new, $counts];
    }

    /** @param list<string> $keys */
    private function result(array $pos, bool $finished, array $keys, string $retry = '', string $retryDetail = ''): BatchStepResult
    {
        [$remarks, $remarked] = $this->newRemarks();
        return new BatchStepResult(
            $pos,
            $finished,
            $this->tally,
            $this->notes + ($remarked ? [self::REMARK => $remarked] : []),
            $keys[$pos['i']] ?? '',
            min($pos['i'], count($keys)),
            count($keys),
            $retry,
            [
                'errors' => $this->itemErrors, 'streams' => $this->streamRecords, 'samples' => $this->stepSamples, 'check' => $this->checkResult, 'catalog' => $this->catalog,
                'collisions' => $this->stepCollisions, 'empty_keys' => $this->stepEmptyKeys, 'remarks' => $remarks,
                // the step ends on a fresh instance of the run, which never saw work() begin
                'work_ms' => (int) round(max(0.0, static::microNow() - ($this->workFrom ?? static::microNow())) * 1000),
            ],
            $retryDetail,
        );
    }

    /** a step cut short at $pos: progress, or no progress with a reason the core can retry
     *  @param list<string> $keys
     *  @param string $detail SourceFailure::$detail of the failure behind $retry */
    private function stopped(array $pos, array $from, array $keys, string $retry, string $detail = ''): BatchStepResult
    {
        if (($retry !== '') && !$this->sourceOnly() && BatchPosition::same($from, ['checked' => $from['checked']] + $pos)) {
            // a check() passed is no progress of a step that failed before any job started (streams(),
            // jobs(), probe()): those get their three tries; the next step asks again
            $pos = $from;
        }
        if (($retry === '') && BatchPosition::same($from, $pos)) {
            $retry = 'NO_PROGRESS';
        }
        return $this->result($pos, false, $keys, $retry, $detail);
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

    /** what the Saver may still take; 0 = no limit. It takes only what the filter keeps, so it may read
     *  more than that in its job - limitReached() counts all it read and stops the run after the job */
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
