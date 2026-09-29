<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\LintFinding;
use RuntimeException;

/** The source gives no package: the user may not export the app or it is not there (one reason, so the
 *  answer tells nothing about modules of others), or the export found errors (E). */
final class ExportRefused extends RuntimeException
{
    /** @param list<array{string, LintFinding}> $findings of the failed export; [] = denied */
    private function __construct(string $message, public readonly array $findings = [], public readonly string $module = '')
    {
        parent::__construct($message);
    }

    /** @param string $module the module the user may not export, when this AA knows it */
    public static function denied(string $module = ''): self
    {
        return new self('No such app, or the user may not export it', [], $module);
    }

    /** @param list<array{string, LintFinding}> $findings */
    public static function failed(array $findings): self
    {
        return new self('The export found errors', $findings);
    }

    public function isDenied(): bool
    {
        return !$this->findings;
    }

    /** @return list<array{string, LintFinding}> the findings of level E */
    public function errors(): array
    {
        return array_values(array_filter($this->findings, static fn(array $f): bool => $f[1]->level === LintFinding::ERROR));
    }
}
