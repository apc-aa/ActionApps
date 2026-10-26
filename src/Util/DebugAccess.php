<?php

declare(strict_types=1);

namespace AA\Util;

/**
 * Who may switch on ?debug (AA::$debug). Debug output shows SQL, templates with slice ids, timings
 * and the request, so only a superadmin gets it, or a request whose X-AA-Debug-Key header carries
 * the install's AA_DEBUG_KEY; for anyone else the parameter is ignored and the page renders as usual.
 *
 * The key is in a header, not in the URL: a URL ends up in access logs, Referer headers, analytics
 * and the links and cache keys AA builds from it.
 *
 * The key is checked at the start of the request (locsess.php). The superadmin is known only once
 * the login is open, so the level asked for waits for grant() - see pageOpen() and pageOpenForDebug().
 */
final class DebugAccess
{
    /** X-AA-Debug-Key as PHP puts it into $_SERVER */
    public const KEY_HEADER = 'HTTP_X_AA_DEBUG_KEY';

    /** a shorter AA_DEBUG_KEY (a placeholder left in config.php) is ignored */
    public const MIN_KEY_LENGTH = 16;

    /** the level asked for and not granted yet - waits for grant() */
    private static int $pending = 0;

    /** the level this request was granted - {debug:N} in a template changes AA::$debug, not this */
    private static int $granted = 0;

    /** AA::$debug at the start of the request: the level asked for when the key matches, else 0
     *
     * @param mixed $debug    ?debug - also a cookie of that name, PHP puts cookies into $_REQUEST
     * @param mixed $aa_debug the aa_debug cookie (Set Debug ON in the admin menu)
     */
    public static function fromRequest(mixed $debug, mixed $aa_debug, string $given_key, string $configured_key): int
    {
        $level = self::requestedLevel($debug, $aa_debug);
        $allowed = self::keyMatches($given_key, $configured_key);
        self::$pending = $allowed ? 0 : $level;
        self::$granted = $allowed ? $level : 0;
        return self::$granted;
    }

    /** the level the request was granted, by the key or to a superadmin; 0 = no debug render */
    public static function granted(): int
    {
        return self::$granted;
    }

    /** the level asked for and not granted (yet) - 0 when nothing was asked for or it was granted */
    public static function pending(): int
    {
        return self::$pending;
    }

    /** The level waiting since fromRequest(), once the login shows a superadmin. $is_superadmin is
     *  called only when a level waits, so a request without ?debug never asks for permissions. */
    public static function grant(int $current, callable $is_superadmin): int
    {
        if ((self::$pending === 0) || !$is_superadmin()) {
            return $current;
        }
        self::$granted = self::$pending;
        self::$pending = 0;
        return self::$granted;
    }

    /** ?debug, else the aa_debug cookie - the bit mask as it always was read */
    public static function requestedLevel(mixed $debug, mixed $aa_debug): int
    {
        foreach ([$debug, $aa_debug] as $value) {
            if (is_scalar($value) && ($level = (int) $value)) {
                return $level;
            }
        }
        return 0;
    }

    /** cron.php ?debug is a dry run: its level when granted, 0 when not asked for, null when asked
     *  for without the right - then nothing may run, the caller expected nothing to run.
     *  Asked for = not empty, as cron.php always read it (?debug=abc too, ?debug=0 runs for real). */
    public static function dryRunLevel(mixed $debug, int $granted): ?int
    {
        if (empty($debug)) {
            return 0;
        }
        return ($granted !== 0) ? (self::requestedLevel($debug, null) ?: 1) : null;
    }

    /** Constant-time comparison; an empty or too short configured key never matches */
    public static function keyMatches(string $given_key, string $configured_key): bool
    {
        return (strlen($configured_key) >= self::MIN_KEY_LENGTH) && hash_equals($configured_key, $given_key);
    }

    /** the key the request sent in the X-AA-Debug-Key header, '' without one */
    public static function givenKey(array $server): string
    {
        $key = $server[self::KEY_HEADER] ?? '';
        return is_string($key) ? $key : '';
    }

    public static function configuredKey(): string
    {
        return defined('AA_DEBUG_KEY') ? (string) constant('AA_DEBUG_KEY') : '';
    }
}
