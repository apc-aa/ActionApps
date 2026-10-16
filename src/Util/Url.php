<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * Facts about the request AA is answering right now, and about web addresses.
 *
 * isHttps() and makeProtocolCompatible() used to sit on AA_Http next to the outgoing client, which
 * is the wrong neighbourhood: they say nothing about calling out, only about how the visitor
 * reached us.
 *
 * AA still decides "are we on https" in half a dozen other places by hand (util.php self_server(),
 * slice.php, logs.php, UiPrefs, central/setcookie.php, phplib.php). Point them here when you touch
 * them - one answer to one question is the goal, not a sweep for its own sake.
 */
final class Url
{
    /**
     * Query parameters that only say where the visitor came from - the page is the same without
     * them. Case-sensitive: trackers write them in this one spelling, and a site's own "Gclid" or
     * "UTM_x" keeps working. Ambiguous names (ref, source, referrer) stay out for the same reason.
     * PageCache leaves cookies of these names out of its keys too.
     */
    private const TRACKING_PARAMS = [
        // click identifiers
        'gclid' => 1, 'gbraid' => 1, 'wbraid' => 1, 'dclid' => 1,
        'fbclid' => 1, 'msclkid' => 1, 'yclid' => 1, 'ttclid' => 1,
        'twclid' => 1, 'li_fat_id' => 1, 'igshid' => 1,
        // Google adds it to links in its search results to measure them
        'srsltid' => 1,
        // Google Analytics carries the visitor across domains in these
        '_ga' => 1, '_gl' => 1,
        // email campaigns
        'mc_cid' => 1, 'mc_eid' => 1,
        // HubSpot
        '_hsenc' => 1, '_hsmi' => 1,
    ];

    /** Families of tracking parameters by prefix - only utm_* is in widespread use */
    private const TRACKING_PREFIXES = ['utm_'];

    /** Whether the query parameter $name only tracks the visit (utm_source, gclid, srsltid, ...) */
    public static function isTrackingParam(string $name): bool
    {
        if (isset(self::TRACKING_PARAMS[$name])) {
            return true;
        }
        foreach (self::TRACKING_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }
        return false;
    }

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
