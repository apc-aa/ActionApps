<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * ISDS refused the login (HTTP 401) before doing anything: a wrong name or password, an expired
 * password, or a login blocked for a while after failed attempts - or the address of the server,
 * AddressBlockedException. Asking again only extends the block.
 */
class AuthException extends IsdsException {}
