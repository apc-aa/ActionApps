<?php

declare(strict_types=1);

namespace AA\Central;

use RuntimeException;

/** Another AA could not be asked: it refused the login, it cannot be reached safely, or it
 *  gave no usable answer. $reason is also the error code of the install run. The message never holds a
 *  token or a password. */
final class SourceFailed extends RuntimeException
{
    /** the source refused the login or its token - the admin logs in again (Continue) */
    public const LOGIN = 'SOURCE_LOGIN';
    /** no https, a redirect, an HTTP error, no connection, a timeout */
    public const UNREACHABLE = 'SOURCE_UNREACHABLE';
    /** an answer too big, of a foreign form, or an error of the source */
    public const FAILED = 'SOURCE_FAILED';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
