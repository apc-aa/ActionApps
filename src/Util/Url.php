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
     * Behind a proxy the port and the scheme both lie, so the forwarded header is the last word.
     *
     * Careful before you lean on this for anything but display: X-Forwarded-Proto is taken from
     * whoever sent the request, with no trusted-proxy list, so any visitor can make it say true.
     * Moved here verbatim from AA_Http; gating it on a configured proxy is in AA_TODO.md.
     */
    public static function isHttps(): bool
    {
        return match (true) {
            !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off' => true,
            ($_SERVER['SERVER_PORT'] ?? null) == 443 => true,
            ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' => true,
            default => false,
        };
    }

    /** Converts $http url to https if current page is loaded by HTTPS for framesets to allow display... */
    public static function makeProtocolCompatible(string $url): string
    {
        return (str_starts_with($url, 'http://') && self::isHttps()) ? 'https://' . substr($url, 7) : $url;
    }
}
