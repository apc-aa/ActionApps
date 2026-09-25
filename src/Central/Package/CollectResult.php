<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;

/** What Collector gathered: the package, the export lint and the modules whose permissions the caller
 *  must check. */
final readonly class CollectResult
{
    /**
     * @param list<array{string, LintFinding}> $findings [value path, finding] as WriterResult; `ref` is raw
     *                                                   text - escape it
     * @param array<string, list<string>>     $outside  modules the app refers to but does not contain:
     *                                                   module id => paths of the references
     * @param list<string>                    $checkPermissions every module the package takes data from, also
     *                                                   the owners of foreign constant groups
     */
    public function __construct(
        public Package $package,
        public array $findings = [],
        public array $outside = [],
        public array $checkPermissions = [],
    ) {}

    /** true = the export failed (a finding of level E): the package must not be written or offered */
    public function failed(): bool
    {
        foreach ($this->findings as [, $finding]) {
            if ($finding->level === LintFinding::ERROR) {
                return true;
            }
        }
        return false;
    }

    /** these + Writer's findings, without Writer's invalid-bytes - the collector reported them already
     *  @return list<array{string, LintFinding}> */
    public function withWriter(WriterResult $written): array
    {
        return [...$this->findings, ...array_values(array_filter($written->findings, static fn(array $f): bool => $f[1]->code !== ValueCodec::INVALID_BYTES))];
    }
}
