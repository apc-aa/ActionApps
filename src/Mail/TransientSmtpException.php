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
 * Recoverable SMTP failure (4xx, network blip, TLS hiccup).
 * Callers should re-queue the message rather than drop it - unless afterBody().
 */
class TransientSmtpException extends SmtpException
{
    /** @param bool $afterBody the message was on its way and no answer came - the server may have it */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, bool $sendingWide = false, private readonly bool $afterBody = false)
    {
        parent::__construct($message, $code, $previous, $sendingWide);
    }

    /** A sender that repeats such a mail may deliver it twice. */
    public function afterBody(): bool
    {
        return $this->afterBody;
    }
}
