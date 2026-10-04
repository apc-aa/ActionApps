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
    }
}
