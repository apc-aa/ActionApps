<?php

declare(strict_types=1);

namespace AA\Later;

/** Permanent failure of a batch run: the run goes to its error state with a code and a place.
 *  Only these two are stored and shown - never an exception text, which may carry source
 *  data or a secret. $reason/$where are own properties: Exception::$code cannot be readonly. */
final class BatchFailed extends \RuntimeException
{
    /** shape of every error code a run stores (also BatchStepResult::$retry) */
    public const CODE = '/^[A-Z][A-Z0-9_]{0,63}$/D';

    public function __construct(public readonly string $reason, public readonly string $where = '')
    {
        if (!self::isCode($reason)) {
            throw new \InvalidArgumentException('Batch error code must match ' . self::CODE);
        }
        parent::__construct($reason);
    }

    public static function isCode(string $code): bool
    {
        return preg_match(self::CODE, $code) === 1;
    }
}
