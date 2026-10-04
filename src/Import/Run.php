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
use AA_Log;
use AA_Property;
use JsonException;

/**
 * One run of an import definition, in queue steps: stream by stream, job by job, item by item
 * through ParamsGrabber and the Saver into the target slice. It works only with its snapshot of
 * the definition, so editing the definition changes the next run, not this one.
 *
 * The position is the cursor of the source: the stream (index in stream_keys), the job and how far
 * into it (offset and the fingerprint of its first items), plus what a stream carries from step to
 * step until it ends - pages and cur (jobs started and the last one), wrote, failed, vmax, restarts.
 * A step always returns the cursor after the last work it stored, also when the source fails.
 * Moving the cursor onto a job not yet started (the first one of a stream, or the next one) counts
 * as progress before that job stored anything, so a source failing at a new job costs at most one
 * extra step before its failures count.
 */
class Run extends BatchRun
{
    /** jobs of one stream (pages of a catalog) at most - a source may hand out its pages in a cycle */
    public const MAX_PAGES = 1000;

    /** where a run starts; a new stream starts with all but i of it */
    public const START = [
        'i' => 0, 'job' => '', 'offset' => 0, 'fp' => '', 'pages' => 0, 'cur' => '', 'cat' => '',
        'wrote' => 0, 'failed' => 0, 'vmax' => '', 'restarts' => 0,
    ];

    /** what a run counts, all of them in this order */
    private const COUNTS = [
        'read' => 0, 'new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
        'jobs' => 0, 'streams' => 0, 'unchanged' => 0, 'skipped_jobs' => 0,
    ];

    /** item errors a step hands over at most */
    private const ERRORS_KEPT = 20;

    private const MODES = ['run', 'changed'];

    /** what walkJobs() and runJob() return when the step stops at the cursor */
    private const STOP = 'stop';

    /** seconds onRetriesExhausted() gives check() and jobs(), ending 30 s before the lease at the latest */
    private const DECIDE_SECONDS = 15;

    /** run (every stream) | changed (streams probe() does not call unchanged) */
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

    /** @var array<string, int> what the step counted so far */
    private array $tally = self::COUNTS;
    /** @var array<string, int> warnings of the step: code => times */
    private array $notes = [];
    /** @var list<array{stream: string, job: string, code: string, msg: string}> */
    private array $itemErrors = [];
    /** @var array<string, true> slices the step stored items into */
    private array $touched = [];

    public static function getClassProperties(): array
    {
        $props = parent::getClassProperties();
        foreach (['mode', 'dry', 'limit', 'snapshot', 'stream_keys', 'trigger', 'errors'] as $id) {
            $props[$id] = new AA_Property($id, $id, 'text', false, true, 'text');
        }
        return $props;
    }

    protected static function states(): BatchStates
    {
        return new BatchStates('running', 'error', 'done', 'ended');
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
        $stored = json_decode((string) $this->counts, true);
        $counts = self::COUNTS;
        foreach ($counts as $name => $zero) {
            $counts[$name] = (int) (is_array($stored) ? ($stored[$name] ?? $zero) : $zero);
        }
        return $counts;
    }

    /** the source of the snapshot */
    protected function source(): SourceInterface
    {
        return Registry::create((string) ($this->snapshotData()['source'] ?? ''), $this->settings());
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
        return (string) ($this->snapshotData()['slice'] ?? '');
    }

    protected function work(array $from, BatchBudget $budget, mixed $fetched): BatchStepResult
    {
        $this->tally      = self::COUNTS;
        $this->notes      = [];
        $this->itemErrors = [];
        $this->touched    = [];
        try {
            return $this->walk(self::cursor($from), $budget);
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
     * Three steps without progress. A step that never fits a queue round ends in error - skipping
     * what does not fit would lose data quietly. A cycle skips the rest of its stream: skipping one
     * job would let the source hand out the cycle again. Any other failure skips the job (the next
     * one gets failed = 1, the last one closes the stream without its version) once check() says
     * the source is up; a stream whose jobs() fails then is closed. A source that cannot say it is
     * up, or is down, ends the run in error with the failure's code; one that refuses, with its reason.
     */
    protected function onRetriesExhausted(BatchStepResult $last): ?BatchStepResult
    {
        $pos  = self::cursor($last->position);
        $keys = $this->streamKeys();
        $ctx  = null;
        if ($last->retry === 'NO_PROGRESS') {
            return null;
        }
        try {
            if ($last->retry === 'SOURCE_CYCLE') {
                return $this->skipped($last, self::nextStream($pos), $keys);
            }
            $source = $this->source();
            if (!($source instanceof Checkable)) {
                return null;
            }
            // the step's budget may be spent by now; the lease must outlast the decision
            $deadline = min(static::microNow() + self::DECIDE_SECONDS, (float) $this->lease - 30);
            $budget   = new BatchBudget($deadline, 0, 0, static::microNow(...));
            $ctx      = $this->context($source, $budget);
            if (!$source->check($ctx)->ok) {
                return null;
            }
            try {
                // a stream failing before its first job has no job to skip
                $next = ($pos['job'] === '') ? self::nextStream($pos) : $this->jobAfter($source, $ctx, $budget, $keys[$pos['i']] ?? '', $pos);
            } catch (SourceRefused $e) {
                throw $e;
            } catch (SourceFailure|TransportException) {
                if ($budget->secondsLeft() < 1) {
                    // cut short by the hook's own deadline: nothing says the page is broken
                    return null;
                }
                // the source is up, a page of this stream is not: the rest of the stream cannot be reached
                $next = self::nextStream($pos);
            }
            return $this->skipped($last, $next, $keys);
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
        $mapping = $this->mapping();
        $keys    = $this->streamKeys();
        $pos     = $from;
        $ctx     = null;
        try {
            $source  = $this->source();
            $ctx     = $this->context($source, $budget);
            $streams = self::streamsOf($source);
            $changed = ($this->mode() === 'changed');
            $state   = $this->streamState();
            $first   = true;   // the first job of a step always starts
            while (true) {
                if (($pos['i'] >= count($keys)) || $this->limitReached()) {
                    return $this->result($pos, true, $keys);
                }
                $key    = $keys[$pos['i']];
                $stream = $streams[$key] ?? null;
                if ($stream === null) {
                    $this->note('STREAM_GONE');
                    $pos = self::nextStream($pos);
                    continue;
                }
                $known = $state?->versionOf($key);
                if (($pos['job'] === '') && ($pos['pages'] === 0) && $changed && ($source instanceof Probeable)) {
                    if (!$first && ($budget->secondsLeft() < $source->minJobSeconds())) {
                        return $this->stopped($pos, $from, $keys, '');
                    }
                    $first = false;
                    if ($source->probe($stream, $ctx, $known)->state === Probe::UNCHANGED) {
                        $this->tally['unchanged']++;
                        $pos = self::nextStream($pos);
                        continue;
                    }
                }
                $halt = $this->walkJobs($source, $ctx, $stream, $known, $mapping, $budget, $pos, $first);
                if ($halt === self::STOP) {
                    return $this->limitReached() ? $this->result($pos, true, $keys) : $this->stopped($pos, $from, $keys, '');
                }
                if ($halt !== '') {
                    // what got stored before counts; from here on the step makes no progress
                    return $this->stopped($pos, $from, $keys, $halt);
                }
                $this->tally['streams']++;
                $pos = self::nextStream($pos);
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
        $where = self::where($keys[$pos['i']] ?? '', $pos['job'], $ctx);
        if ($e instanceof SourceRefused) {
            // a parameter that makes two rows fill one field is a fault of the definition
            return new BatchFailed(($e->reason === 'DUPLICATE_TARGET') ? 'DEFINITION' : $e->reason, $where);
        }
        AA_Log::write(Toexecute::FAILED_EVENT, $this->selector(), Toexecute::failureParams($this->getId(), $e));
        return new BatchFailed('SOURCE', $where);
    }

    /** @return array<string, Stream> the streams the source has now, by key */
    private static function streamsOf(SourceInterface $source): array
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
                    if (++$passed >= self::MAX_PAGES) {
                        break;
                    }
                    continue;
                }
                $seek = '';
            } else {
                if (self::cycles($pos, $job->key)) {
                    return 'SOURCE_CYCLE';
                }
                $pos = self::started($pos, $job->key);
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
            // a job the cursor was on and that read nothing more was counted by the step that ended it
            if (($from['fp'] === '') || ($pos['offset'] > $from['offset']) || ($pos['restarts'] > $from['restarts'])) {
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
        $pos = ['job' => '', 'offset' => 0, 'fp' => '', 'restarts' => $pos['restarts'] + 1] + $pos;
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
            throw new BatchFailed('DEFINITION', self::where($stream->key, $pos['job'], $ctx));
        }
        $params  = $job->params($stream);
        // a stream that started over once already cannot be followed: refused before anything is stored
        $grabber = new ParamsGrabber($inner, $params, $pos['offset'], $pos['fp'], $stream->versionField, $pos['restarts'] < 1);
        $t13s    = $mapping->forJob($params, $this->targetSlice());
        $grabber->setHttp($ctx->http());
        $saver = $this->saver($grabber, $t13s, $mapping)->setImportMode()->setContext($params);
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
        $pos['restarts'] += $grabber->restarted() ? 1 : 0;
        $pos['wrote']     = (($pos['wrote'] === 1) || ($saver->changedIds() !== [])) ? 1 : 0;
        $pos['vmax']      = ParamsGrabber::laterVersion($pos['vmax'], $grabber->versionMax());
        $pos['offset']    = $grabber->offset();
        $pos['fp']        = $grabber->fingerprint();
    }

    /** the mapping of the snapshot, refused as a whole when it does not fit the slice - not item by item */
    private function mapping(): Mapping
    {
        try {
            $mapping = Definition::mappingOf($this->snapshotData());
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
     */
    private function jobAfter(SourceInterface $source, Context $ctx, BatchBudget $budget, string $key, array $pos): array
    {
        $stream = self::streamsOf($source)[$key] ?? null;
        if ($stream === null) {
            return self::nextStream($pos);
        }
        $found  = false;
        $passed = 0;
        foreach ($source->jobs($stream, $ctx, $this->streamState()?->versionOf($key)) as $job) {
            if ($found) {
                return self::cycles($pos, $job->key) ? self::nextStream($pos) : (['failed' => 1] + self::started($pos, $job->key));
            }
            if ($budget->spent()) {
                throw new BatchBudgetSpent('No time left to find the job after the failed one');
            }
            $found = ($job->key === $pos['job']);
            // as in walkJobs(): the cursor's job comes within MAX_PAGES jobs, or it is gone
            if (!$found && (++$passed >= self::MAX_PAGES)) {
                break;
            }
        }
        return self::nextStream($pos);
    }

    /** $last going on from $pos past a failed job, or the rest of a stream
     *  @param list<string> $keys */
    private function skipped(BatchStepResult $last, array $pos, array $keys): BatchStepResult
    {
        $counts   = ['skipped_jobs' => ($last->counts['skipped_jobs'] ?? 0) + 1] + $last->counts;
        $warnings = ['JOB_FAILED' => ($last->warnings['JOB_FAILED'] ?? 0) + 1] + $last->warnings;
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
            ['errors' => $this->itemErrors],
        );
    }

    /** a step cut short at $pos: progress, or no progress with a reason the core can retry
     *  @param list<string> $keys */
    private function stopped(array $pos, array $from, array $keys, string $retry): BatchStepResult
    {
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

    /** @return array<string, string> the snapshot; [] when it cannot be read */
    private function snapshotData(): array
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
        $json = $this->snapshotData()['settings'] ?? '';
        if ($json === '') {
            return [];
        }
        try {
            $settings = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $settings = null;
        }
        if (!is_array($settings)) {
            throw new BatchFailed('DEFINITION', 'settings');
        }
        return array_map('strval', array_filter($settings, 'is_scalar'));
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

    /** the stored position in the shape of START, the types of START */
    private static function cursor(array $position): array
    {
        $pos = [];
        foreach (self::START as $name => $default) {
            $value      = $position[$name] ?? $default;
            $pos[$name] = is_int($default) ? (int) $value : (string) $value;
        }
        return $pos;
    }

    private static function nextStream(array $pos): array
    {
        return ['i' => $pos['i'] + 1] + self::START;
    }

    /** a source handing out the cursor's job again, or more than MAX_PAGES jobs, goes round in a cycle */
    private static function cycles(array $pos, string $job): bool
    {
        return ($pos['pages'] >= self::MAX_PAGES) || (sha1($job) === $pos['cur']);
    }

    /** the cursor on $job, a job not started yet */
    private static function started(array $pos, string $job): array
    {
        return ['job' => $job, 'offset' => 0, 'fp' => '', 'pages' => $pos['pages'] + 1, 'cur' => sha1($job)] + $pos;
    }

    /** the stream and the job of the cursor, as BatchFailed takes them (no URL, no data) */
    private static function where(string $stream, string $job, ?Context $ctx): string
    {
        $where = (($job === $stream) || str_starts_with($job, "$stream:")) ? $job : trim("$stream $job");
        $where = $ctx?->redact($where) ?? $where;
        return mb_strcut((string) preg_replace('~[^\w .:/#-]~u', '_', $where), 0, 200);
    }
}
