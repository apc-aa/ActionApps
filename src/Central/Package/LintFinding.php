<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** One finding of ReferenceLint. Where the text came from (table, column, row) adds the caller.
 *  `ref` is raw package text (at most ReferenceLint::MAX_REF bytes) - escape it (safe()) when shown. */
final readonly class LintFinding
{
    /** Levels of design balicek-v1.md § 8.4: I = info (report), W = warning (error of a published export) */
    public const INFO    = 'I';
    public const WARNING = 'W';

    /**
     * @param string $level  self::INFO | self::WARNING
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
