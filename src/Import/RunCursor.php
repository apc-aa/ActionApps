<?php

declare(strict_types=1);

namespace AA\Import;

/**
 * The cursor of an import run over its source, as the position of the run keeps it between queue
 * steps: a plain array in the shape of START. Plain, because BatchRun stores and compares the
 * position as an array; these functions only build and read it, they ask nothing of the source.
 */
final class RunCursor
{
    /** jobs of one stream (pages of a catalog) at most - a source may hand out its pages in a cycle */
    public const MAX_PAGES = 1000;

    /** failed jobs in a row that skip the rest of their stream: each costs three steps, ~1 h */
    public const SKIPS_IN_A_ROW = 3;

    /**
     * Where a run starts; a new stream starts with all but i, checked, rkeyed and after of it.
     * - failed: the stream skipped a job or lost items to errors - its version stays the old one;
     * - skips: jobs of the stream skipped in a row, since the last one done;
     * - keyed, nokey: an item of the cursor's job had its key; items of it skipped without one - none
     *   keyed at the job's end means a broken mapping, also when the job spans several steps;
     * - rkeyed: an item of the run had its key - a job cut by the run's limit says nothing of the mapping then;
     * - probed, pv: the changed or run mode asked probe() at the stream's start, and the version it gave;
     * - after: the key of a closed stream whose expression is due;
     * - checked: a check, a baseline or a live run asked check(); ccode: what ends the run in its next step.
     */
    public const START = [
        'i' => 0, 'job' => '', 'offset' => 0, 'fp' => '', 'pages' => 0, 'cur' => '', 'cat' => '',
        'wrote' => 0, 'failed' => 0, 'vmax' => '', 'restarts' => 0, 'probed' => 0, 'pv' => '',
        'after' => '', 'checked' => 0, 'ccode' => '', 'skips' => 0, 'keyed' => 0, 'nokey' => 0, 'rkeyed' => 0,
    ];

    /** the stored position in the shape of START, the types of START */
    public static function fromPosition(array $position): array
    {
        $pos = [];
        foreach (self::START as $name => $default) {
            $value      = $position[$name] ?? $default;
            $pos[$name] = is_int($default) ? (int) $value : (string) $value;
        }
        return $pos;
    }

    public static function nextStream(array $pos): array
    {
        return ['i' => $pos['i'] + 1, 'checked' => $pos['checked'], 'rkeyed' => $pos['rkeyed']] + self::START;
    }

    /** a source handing out the cursor's job again, or more than MAX_PAGES jobs, goes round in a cycle */
    public static function cycles(array $pos, string $job): bool
    {
        return ($pos['pages'] >= self::MAX_PAGES) || (sha1($job) === $pos['cur']);
    }

    /** the cursor on $job, a job not started yet */
    public static function started(array $pos, string $job): array
    {
        return ['job' => $job, 'offset' => 0, 'fp' => '', 'pages' => $pos['pages'] + 1, 'cur' => sha1($job), 'keyed' => 0, 'nokey' => 0] + $pos;
    }

    /** the failed job at $pos is the SKIPS_IN_A_ROW-th in a row: the rest of its stream is skipped */
    public static function endsTheStream(array $pos): bool
    {
        return ($pos['job'] !== '') && ($pos['skips'] + 1 >= self::SKIPS_IN_A_ROW);
    }

    /** the cursor past the failed job at $pos, on $next - the job after it in the same stream */
    public static function skippedJob(array $pos, array $next): array
    {
        return ['skips' => $pos['skips'] + 1] + $next;
    }

    /** the cursor's job gone from the source: the stream starts over, its stored items stay */
    public static function restarted(array $pos): array
    {
        return ['job' => '', 'offset' => 0, 'fp' => '', 'restarts' => $pos['restarts'] + 1, 'keyed' => 0, 'nokey' => 0] + $pos;
    }

    /** whether this step counts the job: one that read nothing more than the cursor $from had was
     *  counted by the step that ended it */
    public static function jobCounts(array $from, array $pos): bool
    {
        return ($from['fp'] === '') || ($pos['offset'] > $from['offset']) || ($pos['restarts'] > $from['restarts']);
    }

    /** the catalog page at the cursor read, $next the cursor of the page after it */
    public static function pageRead(array $pos, string $next): array
    {
        return ['cat' => $next, 'pages' => $pos['pages'] + 1, 'cur' => sha1($pos['cat'])] + $pos;
    }

    /** the stream and the job of the cursor, as BatchFailed takes them (no URL, no data) */
    public static function where(string $stream, string $job, ?Context $ctx): string
    {
        $where = (($job === $stream) || str_starts_with($job, "$stream:")) ? $job : trim("$stream $job");
        $where = $ctx?->redact($where) ?? $where;
        return mb_strcut((string) preg_replace('~[^\w .:/#-]~u', '_', $where), 0, 200);
    }
}
