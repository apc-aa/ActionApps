<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA\Import\AbstractSource;
use AA\Import\Context;
use AA\Import\Job;
use AA\Import\RunCursor;
use AA\Import\Stream;
use AA\IO\Grabber\AbstractGrabber;
use AA\IO\SourceUnavailable;
use AA_Content;
use AA_Value;

require_once __DIR__ . '/ScriptedGrabber.php';

/**
 * A source whose streams, jobs and items the test sets. jobs() builds new grabbers on every call,
 * as a real source does in every step.
 */
class ScriptedSource extends AbstractSource
{
    /** @var list<Stream> */
    public array $streams = [];

    /**
     * stream key => its jobs, or a closure giving them anew at each jobs() call: key, items (field =>
     * value), params, and optionally a grabber of its own, an exception prepare() throws, a grabber
     * that does not fetch via loadData(), fills items directly or waits between requests (ms), a
     * closure run first in prepare(), the item getItem() fails at and with what, a closure called with the
     * number of each item read (ArrayGrabber's on_get)
     * @var array<string, list<array<string, mixed>>|\Closure(): iterable<array<string, mixed>>>
     */
    public array $jobs = [];

    /** how many streams() calls in a row fail as the source being down */
    public int $failStreams = 0;

    /** @var ?\Closure(Stream, Context): void runs first in each jobs() call */
    public ?\Closure $onJobs = null;

    /** what each jobs() call spends of the step before its first job */
    public float $jobsSeconds = 0.0;

    /** @var list<array{vendor: string, context: string}> */
    public static array $credentials = [];

    public int $minJob = 15;

    public int $maxBytes = Context::DEFAULT_MAX_BYTES;

    public int $maxJobs = RunCursor::MAX_PAGES;

    /** @var list<array{stream: string, known: ?string}> the jobs() calls */
    public array $jobCalls = [];

    /** @var list<ScriptedGrabber> the grabbers handed out, in order */
    public array $grabbers = [];

    public static function name(): string
    {
        return 'Scripted';
    }

    public static function description(): string
    {
        return 'Streams and jobs a test sets';
    }

    public static function getClassProperties(): array
    {
        return [];
    }

    public static function fromSettings(array $settings): static
    {
        return new static();
    }

    public function streams(): iterable
    {
        if ($this->failStreams > 0) {
            $this->failStreams--;
            throw new SourceUnavailable('TRANSPORT', 'No answer');
        }
        return $this->streams;
    }

    public function jobs(Stream $stream, Context $ctx, ?string $known): iterable
    {
        $this->jobCalls[] = ['stream' => $stream->key, 'known' => $known];
        if ($this->onJobs) {
            ($this->onJobs)($stream, $ctx);
        }
        TestRun::$microNow = (TestRun::$microNow ?? 0.0) + $this->jobsSeconds;
        $jobs = $this->jobs[$stream->key] ?? [];
        foreach (($jobs instanceof \Closure) ? $jobs() : $jobs as $spec) {
            $grabber = $spec['grabber'] ?? $this->grabber($spec);
            yield new Job($spec['key'], $grabber, $spec['params'] ?? []);
        }
    }

    public function minJobSeconds(): int
    {
        return $this->minJob;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function maxJobsPerStream(): int
    {
        return $this->maxJobs;
    }

    public static function credentials(): array
    {
        return static::$credentials;
    }

    /** @param array<string, mixed> $spec */
    private function grabber(array $spec): ScriptedGrabber
    {
        $items = array_map(static function (array $fields): AA_Content {
            $item = new AA_Content();
            foreach ($fields as $field => $value) {
                $item->setAaValue($field, new AA_Value($value));
            }
            return $item;
        }, $spec['items'] ?? []);
        $failAt  = $spec['failAt'] ?? [0, null];
        $grabber = new ScriptedGrabber($items, $spec['error'] ?? null, $spec['via'] ?? true, $spec['direct'] ?? false, $spec['onPrepare'] ?? null, $failAt[0], $failAt[1], $spec['onGet'] ?? null);
        if (isset($spec['wait'])) {
            $grabber->setLoadFrequency($spec['wait']);
        }
        $this->grabbers[] = $grabber;
        return $grabber;
    }
}
