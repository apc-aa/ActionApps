<?php

declare(strict_types=1);

namespace AA\Isds\Body;

/**
 * The server could not make the letter - no temporary directory, a limit of PCRE: nothing in the
 * campaign to fix, so the editor is not told to fix it there. The message is for a developer.
 */
final class ServerException extends \RuntimeException {}
