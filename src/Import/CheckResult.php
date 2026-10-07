<?php

declare(strict_types=1);

namespace AA\Import;

use AA\IO\SourceFailure;
use AA\Later\BatchFailed;
use InvalidArgumentException;

/** Answer of Checkable::check(); a failure's code is stored as the run's error code */
final class CheckResult
{
    /**
     * @param string $code    required on a failure
     * @param string $message from the source: the caller redacts it before storing and escapes it on output
     * @param string $detail  of a failure, as SourceFailure::$detail
     */
    public function __construct(public readonly bool $ok, public readonly string $code, public readonly string $message, public readonly string $detail = '')
    {
        if ((!$ok || ($code !== '')) && !BatchFailed::isCode($code)) {
            throw new InvalidArgumentException('Check code must match ' . BatchFailed::CODE);
        }
        if (!SourceFailure::isDetail($detail)) {
            throw new InvalidArgumentException('Check detail must be one of SourceFailure::isDetail()');
        }
    }
}
