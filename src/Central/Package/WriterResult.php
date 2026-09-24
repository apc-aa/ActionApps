<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What Writer::toXml() made of a package */
final readonly class WriterResult
{
    /**
     * @param string                     $xml      the package, valid against the XSD
     * @param list<array{string, LintFinding}> $findings [value path (e.g. `slice-module[<id>]/view[12]/odd`),
     *                                                   finding]; `ref` holds raw bytes - escape them when shown
     */
    public function __construct(
        public string $xml,
        public array $findings = [],
    ) {}
}
