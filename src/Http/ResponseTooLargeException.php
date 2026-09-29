<?php

declare(strict_types=1);

namespace AA\Http;

use RuntimeException;

/** The answer was bigger than the caller allowed. Not a transport failure: asking again gets the same */
final class ResponseTooLargeException extends RuntimeException {}
