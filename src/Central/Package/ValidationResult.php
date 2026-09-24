<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What Validator::validate() found in a package (design balicek-v1.md § 3.1 step 9, § 8.4) */
final readonly class ValidationResult
{
    /** @param list<array{string, LintFinding}> $findings [path, finding]; paths and refs quote package
     *                                                   text - escape them (safe()) when shown */
    public function __construct(public array $findings) {}

    /** no E: the package may be imported - after the S findings were confirmed in the preview */
    public function importable(): bool
    {
        return !$this->ofLevel(LintFinding::ERROR);
    }

    /** @return list<array{string, LintFinding}> */
    public function ofLevel(string $level): array
    {
        return array_values(array_filter($this->findings, static fn(array $f): bool => $f[1]->level === $level));
    }
}
