<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What Importer::writeBatch() did with one batch of items. Findings quote package text - escape them. */
final readonly class BatchResult
{
    /** written, the run moved on */
    public const WRITTEN = 'written';
    /** the run was not at the batch's position (a second tab, a double click, a run in error) - nothing done */
    public const STALE = 'stale';
    /** rolled back; the run is in error at its position - Continue sends the batch again */
    public const FAILED = 'failed';

    /**
     * @param string                           $status   self::WRITTEN | STALE | FAILED
     * @param list<array{string, LintFinding}> $findings of the batch, by danger - the E that stopped it too
     * @param array{string, string}|null       $error    [code, path] the run keeps (InstallRun::error())
     */
    public function __construct(
        public string $status,
        public array $findings = [],
        public ?array $error = null,
    ) {}
}
