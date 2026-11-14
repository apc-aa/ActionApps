<?php

declare(strict_types=1);

/**
 *
 * PHP version 8.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
 */

namespace AA\Mail;

/**
 * Base SMTP failure. Callers that don't care about retryability catch this.
 */
abstract class SmtpException extends \RuntimeException
{
    /** @param bool $afterBody the failure came while the message was on its way - the server may have it */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, private readonly bool $afterBody = false)
    {
        parent::__construct($message, $code, $previous);
    }

    /** A sender that repeats such a mail may deliver it twice. */
    public function afterBody(): bool
    {
        return $this->afterBody;
    }
}
