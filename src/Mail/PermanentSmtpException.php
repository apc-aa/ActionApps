<?php

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
 * Unrecoverable SMTP failure (5xx, bad address, refused content).
 * Callers should log and drop -- a retry will fail again.
 */
class PermanentSmtpException extends SmtpException {}
