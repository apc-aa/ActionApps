<?php

declare(strict_types=1);

namespace AA\Http;

use RuntimeException;

/** No answer came back at all - a timeout, DNS, a refused connection. Worth trying again later */
final class TransportException extends RuntimeException {}
