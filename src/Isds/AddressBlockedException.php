<?php

declare(strict_types=1);

namespace AA\Isds;

/**
 * ISDS refused the login because it blocked the address of the server on suspicion of an attack
 * (HTTP 401 "Bezpecnostni mechanismus ISDS neumoznil prihlaseni", WS_souvisejici_s_pristupem_do_ISDS.pdf
 * 1.3). Not the fault of the login: every account and every installation on the server is blocked,
 * and each attempt from it extends the block.
 */
final class AddressBlockedException extends AuthException {}
