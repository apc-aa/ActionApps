<?php

declare(strict_types=1);

namespace AA\Import;

use InvalidArgumentException;

/** Whether a stream has new data; unknown means the stream is imported */
final class Probe
{
    public const CHANGED = 'changed';
    public const UNCHANGED = 'unchanged';
    public const UNKNOWN = 'unknown';

    /** @param string $version data version to store when the stream is done ('' = none); from the
     *                         source - redacted before storing, escaped on output */
    public function __construct(public readonly string $state, public readonly string $version = '')
    {
        if (!in_array($state, [self::CHANGED, self::UNCHANGED, self::UNKNOWN], true)) {
            throw new InvalidArgumentException("Unknown probe state $state");
        }
    }
}
