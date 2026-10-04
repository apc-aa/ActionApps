<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Later\BatchFailed;
use InvalidArgumentException;

/** Answer of Checkable::check(); a failure's code is stored as the run's error code */
final class CheckResult
{
    /**
     * @param string $code    required on a failure
     * @param string $message from the source: the caller redacts it before storing and escapes it on output
     */
    public function __construct(public readonly bool $ok, public readonly string $code, public readonly string $message)
    {
        if ((!$ok || ($code !== '')) && !BatchFailed::isCode($code)) {
            throw new InvalidArgumentException('Check code must match ' . BatchFailed::CODE);
        }
    }
}
