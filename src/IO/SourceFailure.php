<?php

declare(strict_types=1);

namespace AA\IO;

use AA\Later\BatchFailed;
use InvalidArgumentException;
use RuntimeException;

/** A data source failed; the subclass says whether asking again can help. The message is already free of keys. */
abstract class SourceFailure extends RuntimeException
{
    /** @param string $reason error code the run stores, as BatchFailed::CODE */
    public function __construct(public readonly string $reason, string $message = '')
    {
        if (!BatchFailed::isCode($reason)) {
            throw new InvalidArgumentException('Reason must match ' . BatchFailed::CODE);
        }
        parent::__construct($message === '' ? $reason : $message);
    }
}
