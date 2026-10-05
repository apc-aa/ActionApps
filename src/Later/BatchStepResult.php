<?php

declare(strict_types=1);

namespace AA\Later;

/** What one work() step reports. $position is always where the next step continues
 *  (never before the step's start); a step that did not move it says why in $retry
 *  (TRANSPORT, SOURCE_UNSTABLE, SOURCE_CYCLE, NO_PROGRESS; STEP_DIED is set by the core). */
final class BatchStepResult
{
    /** arrays nested in a position at most - the run stores it as JSON of this depth */
    public const MAX_DEPTH = 16;

    /**
     * @param array $counts   deltas added to the run's counts (JSON, two levels at most)
     * @param array $warnings deltas added to the run's warnings
     * @param array $extra    the run user's own data for commitStep()
     * @param string $label   what the step did, for the progress page (which prints it through safe()) -
     *                        never an exception text, nor source data that may hold a secret
     * @param string $retryDetail what tells the failure of $retry apart, as BatchFailed::$detail
     */
    public function __construct(
        public readonly array $position,
        public readonly bool $finished,
        public readonly array $counts = [],
        public readonly array $warnings = [],
        public readonly string $label = '',
        public readonly int $done = 0,
        public readonly int $total = 0,
        public readonly string $retry = '',
        public readonly array $extra = [],
        public readonly string $retryDetail = '',
    ) {
        // retry becomes the run's error code once the retries are spent
        if (($retry !== '') && !BatchFailed::isCode($retry)) {
            throw new \InvalidArgumentException('Batch retry reason must match ' . BatchFailed::CODE);
        }
        if (!BatchFailed::isDetail($retryDetail)) {
            throw new \InvalidArgumentException('Batch retry detail must match ' . BatchFailed::DETAIL);
        }
        // added and stored as JSON at the end of the step - a bad value must fail here, in work(), not in that transaction
        if (!mb_check_encoding($label, 'UTF-8') || !self::tally($counts) || !self::tally($warnings)) {
            throw new \InvalidArgumentException('Batch step label must be UTF-8, counts whole numbers two levels deep under UTF-8 names');
        }
        try {
            BatchPosition::canonical($position);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Batch position must be UTF-8', 0, $e);
        }
        if (self::depth($position) > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Batch position is nested deeper than ' . self::MAX_DEPTH);
        }
    }

    /** the same result going on from $position instead - the way on of onRetriesExhausted() */
    public function withPosition(array $position): self
    {
        return new self($position, $this->finished, $this->counts, $this->warnings, $this->label, $this->done, $this->total, '', $this->extra);
    }

    /** whole numbers, at most two levels, under UTF-8 names */
    private static function tally(array $values, int $levels = 2): bool
    {
        foreach ($values as $key => $value) {
            $ok = is_int($value) || (is_array($value) && ($levels > 1) && self::tally($value, $levels - 1));
            if (!$ok || !mb_check_encoding((string) $key, 'UTF-8')) {
                return false;
            }
        }
        return true;
    }

    private static function depth(array $value): int
    {
        $inner = 0;
        foreach ($value as $item) {
            if (is_array($item)) {
                $inner = max($inner, self::depth($item));
            }
        }
        return $inner + 1;
    }
}
