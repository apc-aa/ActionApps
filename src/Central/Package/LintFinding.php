<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** One lint finding. `ref` is raw package text - escape it when shown. */
final readonly class LintFinding
{
    /** I = info; W = warning (an error in a published export); E = error - the export fails, the import
     *  does not start; S = security - code that reads files, calls URLs or carries passwords: the import
     *  needs an explicit confirmation, but S blocks nothing by itself. */
    public const INFO     = 'I';
    public const WARNING  = 'W';
    public const ERROR    = 'E';
    public const SECURITY = 'S';

    /**
     * @param string $level  self::INFO | self::WARNING | self::ERROR | self::SECURITY
     * @param string $code   ReferenceLint::* code
     * @param string $ref    the reference as written in the text, cut to ReferenceLint::MAX_REF
     * @param int    $offset byte offset of $ref in the text
     */
    public function __construct(
        public string $level,
        public string $code,
        public string $ref,
        public int $offset,
    ) {}
}
