<?php

declare(strict_types=1);

namespace AA\Central\Package;

use RuntimeException;

/** A regular expression of the rewrite or lint failed (backtrack/JIT limit, …). The import must stop:
 *  a text passed on unchanged would keep the source ids and point at modules of the target AA. */
final class RewriteFailed extends RuntimeException
{
    /** Result of a preg_* call, or the exception when it failed (null/false).
     *  @template T
     *  @param T|null|false $result
     *  @return T */
    public static function check(mixed $result): mixed
    {
        if (($result === null) || ($result === false)) {
            throw new self('Reference rewrite failed: ' . preg_last_error_msg());
        }
        return $result;
    }
}
