<?php

declare(strict_types=1);

namespace AA\Optimize;

/** One result of an Optimize check as State Check (admin/aa_statecheck.php)
 *  shows it: what is wrong, how often, since when, the rows that prove it and
 *  what to do about it. A legacy Optimize class yields one Finding from
 *  test() + report(); a checker returns as many as it has to say.
 */
final class Finding
{
    /** something does not run or data is being lost */
    public const ERROR = 'error';
    /** degradation or abuse */
    public const WARNING = 'warning';
    /** a recommendation */
    public const INFO = 'info';
    public const OK = 'ok';

    /** display order, worst first */
    private const ORDER = [self::ERROR => 0, self::WARNING => 1, self::INFO => 2, self::OK => 3];

    /** short class name of the check - CheckRunner fills it in */
    public string $check = '';
    /** category() of the check - CheckRunner fills it in */
    public string $category = '';
    /** how long the whole check took - CheckRunner fills it in */
    public float $seconds = 0.0;

    /**
     * @param string                      $severity   one of the constants
     * @param string                      $title      one plain-text sentence
     * @param int                         $count      occurrences, 0 when not countable
     * @param int                         $since      unixtime of the first occurrence, 0 when unknown
     * @param list<array<string,string>>  $evidence   rows for GetHtmlTable(), already HTML-escaped
     * @param string                      $advice     what to do, plain text
     * @param array<string,string>        $links      label => url
     * @param bool                        $repairable the class' repair() fixes this finding
     * @param string                      $key        stable identity of the finding inside its check
     *                                                (the fatal's file:line, the cron job, ...) - the same
     *                                                problem keeps the same key across runs; '' = the check itself
     * @param int                         $last       unixtime of the newest occurrence of an event finding (a fatal,
     *                                                a failed login); 0 for a state that simply holds now (cron dead)
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $title,
        public readonly int $count = 0,
        public readonly int $since = 0,
        public readonly array $evidence = [],
        public readonly string $advice = '',
        public readonly array $links = [],
        public readonly bool $repairable = false,
        public readonly string $key = '',
        public readonly int $last = 0,
    ) {}

    public function isProblem(): bool
    {
        return $this->severity !== self::OK;
    }

    /** worst severity first, then the most frequent */
    public static function compare(Finding $a, Finding $b): int
    {
        return (self::ORDER[$a->severity] <=> self::ORDER[$b->severity]) ?: ($b->count <=> $a->count);
    }

    /** @param Finding[] $findings */
    public static function hasError(array $findings): bool
    {
        foreach ($findings as $finding) {
            if ($finding->severity === self::ERROR) {
                return true;
            }
        }
        return false;
    }

    /** @param Finding[] $findings
     *  @return array<string,int> severity => count, every severity present */
    public static function summary(array $findings): array
    {
        $summary = array_fill_keys(array_keys(self::ORDER), 0);
        foreach ($findings as $finding) {
            ++$summary[$finding->severity];
        }
        return $summary;
    }
}
