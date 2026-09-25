<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;

/** What Reader::read() made of a package */
final readonly class ReadResult
{
    /**
     * @param Package                          $package  in the canonical form of Reader
     * @param list<array{string, LintFinding}> $findings [path (e.g. `slice-module[<id>]/view[12]/colour`),
     *                                                   finding] - what was pruned; `ref` is package
     *                                                   text, escape it when shown
     * @param string                           $format   format version of the package (aa-app/@format), e.g. `1.1`
     */
    public function __construct(
        public Package $package,
        public array $findings,
        public string $format,
    ) {}
}
