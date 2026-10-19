<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * The settings of misc/datovka/datovka_conf.php do not let ISDS work here. The message says what to
 * set there for the server's administrator, naming keys and never their values; it may quote slice
 * ids - escape it (safe()) when shown. Not an IsdsException: nothing was asked of ISDS.
 */
final class ConfigException extends \RuntimeException {}
