<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * ISDS refused the login (HTTP 401) before doing anything: a wrong name or password, an expired
 * password, or a login or address blocked for a while after failed attempts. Asking again only
 * extends the block.
 */
final class AuthException extends IsdsException {}
