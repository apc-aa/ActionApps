<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;

/** What Collector gathered: the package, the export lint (design balicek-v1.md § 8.4) and the modules
 *  whose permissions the caller must check. */
final readonly class CollectResult
{
    /**
     * @param list<array{string, LintFinding}> $findings [value path, finding] as WriterResult - the path
     *                                                   in Writer's form (`site-module[<id>]/site_spot[1]/content`),
     *                                                   a keyless row by its position in the source
     *                                                   (`profile[#0]`); `ref` is raw text - escape (safe()) it
     * @param array<string, list<string>>     $outside  modules the app refers to but does not contain -
     *                                                   the closure left to the author (§ 5.2): module id
     *                                                   => paths of the references
     * @param list<string>                    $checkPermissions every module the package takes data from:
     *                                                   the app modules (with the "uses" modules fromSite()
     *                                                   found) and the owners of foreign constant groups,
     *                                                   also when the package writes owner ''
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

    /** The findings of the export: these + what Writer found in the package, without Writer's
     *  invalid-bytes - the collector reported every value it converted already.
     *  @return list<array{string, LintFinding}> */
    public function withWriter(WriterResult $written): array
    {
        return [...$this->findings, ...array_values(array_filter($written->findings, static fn(array $f): bool => $f[1]->code !== ValueCodec::INVALID_BYTES))];
    }
}
