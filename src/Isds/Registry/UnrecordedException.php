<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

/**
 * The register is downloaded, but its record could not be written: nothing vouches for the file in
 * place, so it cannot be used until a download is recorded. The message may name the database's
 * error - escape it (safe()) when shown.
 */
final class UnrecordedException extends \RuntimeException {}
