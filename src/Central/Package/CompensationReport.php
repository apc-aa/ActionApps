<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What the compensation of a failed import (Compensation) did - one row per step, in its order: the
 *  step (module, table, group; package text - safe() it) and its errors. A step with errors needs a
 *  manual action: each error names the table and the condition of the rows left ("view: 2 rows left:
 *  WHERE id IN (1000,1001)") or why it could not tell. */
final class CompensationReport
{
    /** @var list<array{string, list<string>}> [step, errors] */
    private array $steps = [];

    /** @param list<string> $errors */
    public function add(string $step, array $errors): void
    {
        $this->steps[] = [$step, $errors];
    }

    /** @return list<array{string, list<string>}> every step, [step, errors] */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return list<array{string, list<string>}> the steps with errors - for manual action */
    public function failures(): array
    {
        return array_values(array_filter($this->steps, static fn(array $s): bool => (bool) $s[1]));
    }

    /** nothing of the import is left (as far as the checks see) */
    public function clean(): bool
    {
        return !$this->failures();
    }

    /** the failed steps in ONE line, control characters replaced - for a log (package text inside: no forged
     *  lines); "step: error; error | step: error" */
    public function summary(): string
    {
        $line = implode(' | ', array_map(static fn(array $s): string => "$s[0]: " . implode('; ', $s[1]), $this->failures()));
        return (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $line);
    }
}
