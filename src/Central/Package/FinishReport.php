<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What the finishing of a committed import (ImportFinish, design balicek-v1.md § 7.2 step 8) did - one
 *  row per step, in its order: `log`, `permissions[<module>]`, `constants`, `pagecache[<module>]` (package
 *  text - safe() it), and its errors, none = done. A step with errors did not run to its end; the import
 *  stays - the report says what to do by hand (clear the caches, give the rights). */
final class FinishReport
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

    /** @return list<array{string, list<string>}> the steps with errors */
    public function failures(): array
    {
        return array_values(array_filter($this->steps, static fn(array $s): bool => (bool) $s[1]));
    }
}
