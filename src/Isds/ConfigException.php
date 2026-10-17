<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * include/config.php does not let ISDS work here. The message says what to set there for the
 * server's administrator, naming constants and never their values; it may quote slice ids -
 * escape it (safe()) when shown. Not an IsdsException: nothing was asked of ISDS.
 */
final class ConfigException extends \RuntimeException {}
