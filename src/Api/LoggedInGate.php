<?php

declare(strict_types=1);

/**
 * Logged-in user check shared by the AA API controllers that serve admin work.
 *
 * @package Api
 */

namespace AA\Api;

trait LoggedInGate
{
    /** Override point for tests; production reads AA's session auth. */
    protected function isLoggedIn(): bool
    {
        $auth = $GLOBALS['auth'] ?? null;
        return is_object($auth) && method_exists($auth, 'is_user') && $auth->is_user();
    }
}
