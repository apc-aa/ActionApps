<?php

declare(strict_types=1);

namespace AA\Later;

/** What one work() step reports. $position is always where the next step continues
 *  (never before the step's start); a step that did not move it says why in $retry
 *  (TRANSPORT, SOURCE_UNSTABLE, SOURCE_CYCLE, NO_PROGRESS; STEP_DIED is set by the core). */
final class BatchStepResult
{
    /**
     * @param array $counts   deltas added to the run's counts (JSON, two levels at most)
     * @param array $warnings deltas added to the run's warnings
     * @param array $extra    the run user's own data for commitStep()
     * @param string $label   what the step did, for the progress page (which prints it through safe()) -
     *                        never an exception text, nor source data that may hold a secret
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
    ) {
        // retry becomes the run's error code once the retries are spent
        if (($retry !== '') && !BatchFailed::isCode($retry)) {
            throw new \InvalidArgumentException('Batch retry reason must match ' . BatchFailed::CODE);
        }
        // stored as JSON at the end of the step - invalid UTF-8 must fail here, in work(), not in that transaction
        if (!mb_check_encoding($label, 'UTF-8') || !self::utf8Keys($counts) || !self::utf8Keys($warnings)) {
            throw new \InvalidArgumentException('Batch step label and count names must be UTF-8');
        }
    }

    private static function utf8Keys(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (!mb_check_encoding((string) $key, 'UTF-8') || (is_array($value) && !self::utf8Keys($value))) {
                return false;
            }
        }
        return true;
    }
}
