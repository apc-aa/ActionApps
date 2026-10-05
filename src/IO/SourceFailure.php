<?php

declare(strict_types=1);

namespace AA\IO;

use AA\Http\TransportException;
use AA\Later\BatchFailed;
use InvalidArgumentException;
use RuntimeException;

/**
 * A data source failed; the subclass says whether asking again can help. The message is already
 * free of keys. $detail tells the failure apart in a closed set (DETAILS, or http:<status>) built
 * from typed facts - it is stored and shown, so it never carries a URL or a text of the source.
 */
abstract class SourceFailure extends RuntimeException
{
    public const TOO_LARGE = 'too_large';
    public const REDIRECTS = 'redirects';

    /** the details besides http:<status> */
    public const DETAILS = [TransportException::DNS, TransportException::TIMEOUT, TransportException::TLS, TransportException::REFUSED, TransportException::ADDRESS, self::TOO_LARGE, self::REDIRECTS];

    /**
     * @param string $reason error code the run stores, as BatchFailed::CODE
     * @param string $detail '' or one of isDetail()
     */
    public function __construct(public readonly string $reason, string $message = '', public readonly string $detail = '')
    {
        if (!BatchFailed::isCode($reason)) {
            throw new InvalidArgumentException('Reason must match ' . BatchFailed::CODE);
        }
        if (!self::isDetail($detail)) {
            throw new InvalidArgumentException('Detail must be one of SourceFailure::DETAILS or http:<status>');
        }
        parent::__construct($message === '' ? $reason : $message);
    }

    public static function isDetail(string $detail): bool
    {
        return ($detail === '') || in_array($detail, self::DETAILS, true) || (preg_match('/^http:[1-5][0-9]{2}$/D', $detail) === 1);
    }

    /** the detail of a failure on the way to the source; '' for anything else */
    public static function detailOf(\Throwable $e): string
    {
        return match (true) {
            $e instanceof self               => $e->detail,
            $e instanceof TransportException => $e->kind(),
            default                          => '',
        };
    }

    /**
     * What an HTTP status means for an import - one rule for grabbers and for sources, which use
     * it over Context::http() in check(), probe() or a login. null for 2xx.
     * @param string $what the message, already free of keys
     */
    public static function forStatus(int $status, string $what): ?self
    {
        $detail = (($status >= 100) && ($status < 600)) ? "http:$status" : '';
        return match (true) {
            ($status >= 200) && ($status < 300)       => null,
            // the service is down or throttling us - the same request may pass later
            ($status >= 500) || ($status === 429)     => new SourceUnavailable('TRANSPORT', $what, $detail),
            in_array($status, [401, 403], true)       => new SourceRefused('AUTH', $what, $detail),
            default                                   => new SourceRefused('SOURCE', $what, $detail),
        };
    }
}
