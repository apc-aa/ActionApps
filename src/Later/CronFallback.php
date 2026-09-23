<?php

/**
 *
 * PHP version 8.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Later;

/**
 * Self-healing scheduled-tasks fallback for installs without OS cron.
 *
 * Lock file mtime is both the "last-run" timestamp (gate) and (with flock)
 * the inter-worker mutex. cron.php / misc/toexecute.php touch the same
 * file (Toexecute::runQueue()), so configured OS cron suppresses the
 * fallback automatically.
 * The shot stamp (SHOT_FILENAME) only throttles how often requests fire the
 * runner; it never gates the run itself, so it needs no cleanup.
 *
 * @package AA\Later
 */
class CronFallback
{
    private const DEFAULT_INTERVAL = 120;
    private const LOCK_FILENAME    = 'cron-fallback.lock';
    private const SHOT_FILENAME    = 'cron-fallback.shot';
    private const CONNECT_TIMEOUT  = 0.3;

    public static function lockFile(): string
    {
        return constant('IMG_UPLOAD_PATH') . self::LOCK_FILENAME;
    }

    private static function shotFile(): string
    {
        return constant('IMG_UPLOAD_PATH') . self::SHOT_FILENAME;
    }

    public static function isDisabled(): bool
    {
        return self::constBool('DISABLE_CRON_FALLBACK');
    }

    public static function maybeTrigger(): void
    {
        if (self::isDisabled() || self::constBool('AA_NO_CRON_FALLBACK_THIS_REQUEST')) {
            return;
        }

        $lock_file = self::lockFile();
        $mtime     = @filemtime($lock_file);
        // REQUEST_TIME avoids a per-request time() syscall on this hot path -
        // request-handling drift (<1s) is negligible against the 120s gate.
        $now      = $_SERVER['REQUEST_TIME'] ?? time();
        $interval = self::interval();
        if ($mtime !== false && ($now - $mtime) < $interval) {
            return;
        }

        // Skip in CLI: there is no web server to talk back to.
        if (PHP_SAPI === 'cli') {
            return;
        }
        // A run longer than the interval (HitcounterStats took 853 s on aadb) holds
        // the lock and leaves its mtime old - without the shot stamp every request
        // would fire a runner that only loses Trylock(). A few concurrent requests
        // may still fire at once; runDeferred() coalesces them to one run.
        $shot_file = self::shotFile();
        if (!self::isShotDue($now, @filemtime($shot_file), $interval)) {
            return;
        }
        // explicit $now: the stamp is compared with REQUEST_TIME, not with the file server's clock;
        // a failed touch just means we fire every time, as before the stamp
        @touch($shot_file, $now);
        self::fireAsyncRunner();
    }

    /** May a request fire the runner, given the mtime of the last shot?
     *  Fails open: a missing/unreadable stamp or one more than an interval in the
     *  future (a manual touch) fires, so the stamp can delay the fallback by one
     *  interval at most, never stop it. A few seconds ahead (clock skew between
     *  the GlusterFS nodes) counts as fresh - firing on it would fire on every
     *  request of the slower node.
     */
    public static function isShotDue(int $now, int|false $shot_mtime, int $interval): bool
    {
        if ($shot_mtime === false) {
            return true;
        }
        $age = $now - $shot_mtime;
        return ($age >= $interval) || ($age < -$interval);
    }

    /** Fire-and-forget HTTP request to the runner endpoint. Silent on any
     *  failure - cron will catch up next cycle. Same pattern as WP-Cron;
     *  AA_Http::request() blocks until response and so cannot be used here.
     */
    private static function fireAsyncRunner(): void
    {
        if (!defined('AA_INSTAL_URL')) {
            return;
        }
        $url   = constant('AA_INSTAL_URL') . 'misc/cron-fallback.php';
        $parts = @parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return;
        }
        $is_https = (($parts['scheme'] ?? 'http') === 'https');
        $host     = $parts['host'];
        $port     = $parts['port'] ?? ($is_https ? 443 : 80);
        $path     = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $target   = $is_https ? "ssl://$host" : $host;

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($target, $port, $errno, $errstr, self::CONNECT_TIMEOUT);
        if (!$fp) {
            return;
        }

        $request  = "GET $path HTTP/1.0\r\n";
        $request .= "Host: $host\r\n";
        $request .= "Connection: close\r\n";
        $request .= "User-Agent: APC-AA-CronFallback\r\n";
        $request .= "\r\n";

        @stream_set_timeout($fp, 1);
        @fwrite($fp, $request);
        @fclose($fp);
    }

    public static function runDeferred(): void
    {
        @ignore_user_abort(true);

        $lock_file = self::lockFile();
        try {
            // Anti-DoS: the gate is re-checked under runQueue()'s lock - covers concurrent callers in the open window.
            Toexecute::runQueue(null, static function () use ($lock_file): bool {
                clearstatcache(true, $lock_file);
                return (time() - (int) filemtime($lock_file)) >= self::interval();
            });
        } catch (\Throwable $e) {
            if (class_exists('AA_Log')) {
                \AA_Log::write('CRON_FALLB', 'CronFallback', $e->getMessage());   // log.type is 10 bytes, longer names get cut
            }
        }
    }

    private static function constBool(string $name): bool
    {
        return defined($name) && (bool) constant($name);
    }

    private static function interval(): int
    {
        return defined('CRON_FALLBACK_INTERVAL')
            ? (int) constant('CRON_FALLBACK_INTERVAL')
            : self::DEFAULT_INTERVAL;
    }
}
