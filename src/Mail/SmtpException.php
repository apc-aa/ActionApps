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
 * Base SMTP failure. Callers that don't care about retryability catch this.
 */
abstract class SmtpException extends \RuntimeException {

}
