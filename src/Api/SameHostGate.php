<?php

declare(strict_types=1);

/**
 * Origin/Referer host check shared by the AA API controllers.
 *
 * Blocks off-site browsers and casual curl. It is spoofable, so it is NOT a
 * security boundary - an endpoint that needs one also checks the logged-in user.
 *
 * @package Api
 */

namespace AA\Api;

trait SameHostGate
{
    /** Origin or Referer host must match this server's host. */
    protected function sameHostRequest(): bool
    {
        $own = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($own === '') {
            return false;
        }
        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $hdr) {
            $val = $_SERVER[$hdr] ?? '';
            if ($val === '') {
                continue;
            }
            $host = strtolower((string) parse_url((string) $val, PHP_URL_HOST));
            if ($host !== '' && $host === $own) {
                return true;
            }
        }
        return false;
    }
}
