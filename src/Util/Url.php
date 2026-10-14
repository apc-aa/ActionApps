<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * Facts about the request AA is answering right now.
 *
 * These used to sit on AA_Http next to the outgoing client, which is the wrong neighbourhood:
 * they say nothing about calling out, only about how the visitor reached us.
 *
 * AA still decides "are we on https" in half a dozen other places by hand (util.php self_server(),
 * slice.php, logs.php, UiPrefs, central/setcookie.php, phplib.php). Point them here when you touch
 * them - one answer to one question is the goal, not a sweep for its own sake.
 */
final class Url
{
    /**
     * Behind a proxy TrustedProxy::apply() has already set HTTPS from the proxy's X-Forwarded-Proto;
     * the header itself is not read here - from anyone but a trusted proxy it means nothing.
     */
    public static function isHttps(): bool
    {
        return match (true) {
            !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off' => true,
            ($_SERVER['SERVER_PORT'] ?? null) == 443 => true,
            default => false,
        };
    }

    /** Converts $http url to https if current page is loaded by HTTPS for framesets to allow display... */
    public static function makeProtocolCompatible(string $url): string
    {
        return (str_starts_with($url, 'http://') && self::isHttps()) ? 'https://' . substr($url, 7) : $url;
    }
}
