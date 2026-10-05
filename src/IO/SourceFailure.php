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

    /**
     * What an HTTP status means for an import - one rule for grabbers and for sources, which use
     * it over Context::http() in check(), probe() or a login. null for 2xx.
     * @param string $what the message, already free of keys
     */
    public static function forStatus(int $status, string $what): ?self
    {
        return match (true) {
            ($status >= 200) && ($status < 300)       => null,
            // the service is down or throttling us - the same request may pass later
            ($status >= 500) || ($status === 429)     => new SourceUnavailable('TRANSPORT', $what),
            in_array($status, [401, 403], true)       => new SourceRefused('AUTH', $what),
            default                                   => new SourceRefused('SOURCE', $what),
        };
    }
}
