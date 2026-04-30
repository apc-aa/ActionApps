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
 * Class CronFallback - runs scheduled tasks (toexecute queue) after sending the
 *   response to a normal page request, when OS-level cron has been silent too
 *   long. Acts as a self-healing safety net for installs that lack a configured
 *   cron job.
 *
 *  Two execution paths, picked automatically per backend:
 *   - PHP-FPM (fastcgi_finish_request available): in-process shutdown handler.
 *     Zero extra HTTP requests. Fastest path.
 *   - mod_php / CGI / anything else: fire-and-forget async HTTP via fsockopen
 *     to misc/cron-fallback.php. The web server picks it up in a separate
 *     worker; the original request returns normally. This is the same pattern
 *     WordPress uses for WP-Cron - works on every PHP backend.
 *
 *  Both paths converge on runDeferred(), which:
 *   - takes a non-blocking flock() on the lock file (only one runner at a time);
 *   - re-checks the mtime gate AFTER acquiring the lock (prevents wasted runs
 *     and limits anonymous DoS through the runner endpoint to one effective
 *     run per CRON_FALLBACK_INTERVAL);
 *   - touches the lock file (advances mtime so the gate closes immediately);
 *   - runs the same work as misc/toexecute.php.
 *
 *  The lock file at IMG_UPLOAD_PATH . 'cron-fallback.lock' doubles as both
 *  timestamp store (mtime) and inter-worker mutex (flock). misc/toexecute.php
 *  and cron.php also touch this file, so a properly configured OS cron
 *  suppresses the fallback automatically.
 *
 * @package AA\Later
 */
class CronFallback
{
    private const DEFAULT_INTERVAL = 300;

    public static function lockFile(): string
    {
        // IMG_UPLOAD_PATH is defined in config.php (runtime); use constant() so
        // static analysis recognises the indirection.
        return constant('IMG_UPLOAD_PATH') . 'cron-fallback.lock';
    }

    /** Hot-path gate. Called from the end of locsess.php on every request that
     *  reaches it. Returns immediately in the common case (gate closed).
     *  Registers a shutdown function only when a fallback run is due.
     */
    public static function maybeTrigger(): void
    {
        if (defined('DISABLE_CRON_FALLBACK') && constant('DISABLE_CRON_FALLBACK')) {
            return;
        }
        if (defined('AA_NO_CRON_FALLBACK_THIS_REQUEST') && constant('AA_NO_CRON_FALLBACK_THIS_REQUEST')) {
            return;
        }

        $interval = defined('CRON_FALLBACK_INTERVAL') ? (int)constant('CRON_FALLBACK_INTERVAL') : self::DEFAULT_INTERVAL;
        $lock_file = self::lockFile();
        if (is_file($lock_file) && (time() - (int)filemtime($lock_file)) < $interval) {
            // Gate closed: cron (real or fallback) ran recently enough.
            return;
        }

        // Path A: PHP-FPM - run in-process after response is flushed.
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function([self::class, 'runDeferred']);
            return;
        }

        // Path B: mod_php / CGI / anything else - fire async HTTP request to
        // a runner endpoint and let the web server handle it in a separate
        // worker. The runner's flock+gate-recheck protects against duplicate
        // execution if multiple concurrent requests fire here.
        // Skip in CLI: there is no web server to talk back to.
        if (PHP_SAPI === 'cli') {
            return;
        }
        self::fireAsyncRunner();
    }

    /** Fire-and-forget HTTP request to misc/cron-fallback.php. Returns
     *  immediately; we do not wait for the response. Same pattern as WP-Cron.
     *  Silent on any failure - cron will catch up next cycle.
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

        $errno = 0; $errstr = '';
        $fp = @fsockopen($target, $port, $errno, $errstr, 1.0);
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

    /** Runner. Called either by the FPM shutdown handler or by misc/cron-fallback.php
     *  serving the async HTTP path. Flushes the response if possible, takes the
     *  lock, re-checks the gate, runs the queue. Silent on contention or any
     *  error - this is best-effort work.
     */
    public static function runDeferred(): void
    {
        // 1. Flush response to the user if we are the FPM in-process path.
        //    No-op for the async HTTP runner (no client waiting for output).
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        // Don't let a disconnected client kill us mid-run.
        @ignore_user_abort(true);

        // 2. Open the lock file ('c' = create if missing, do not truncate) and
        //    take a non-blocking exclusive advisory lock. If another worker has
        //    it, give up silently - they'll do the work.
        $lock_file = self::lockFile();
        $fp = @fopen($lock_file, 'c');
        if (!$fp) {
            return;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return;
        }

        try {
            // 3. Re-check the gate AFTER taking the lock. This catches two cases:
            //    a) the previous lock holder just finished and bumped mtime
            //       (no point running again);
            //    b) the runner endpoint was hit by an unrelated/anonymous caller
            //       within the cooldown window (limits anonymous DoS).
            $interval = defined('CRON_FALLBACK_INTERVAL') ? (int)constant('CRON_FALLBACK_INTERVAL') : self::DEFAULT_INTERVAL;
            clearstatcache(true, $lock_file);
            if ((time() - (int)filemtime($lock_file)) < $interval) {
                return;
            }

            // 4. Bump mtime BEFORE running, so the gate stays closed for the
            //    full interval starting from when this run began. Even if the
            //    run takes minutes or crashes mid-way, parallel requests won't
            //    queue another fallback.
            @touch($lock_file);

            // 5. Mirror misc/toexecute.php: cron permission mode, then schedule
            //    maintenance tasks, then drain the queue.
            if (isset(\AA::$perm) && is_object(\AA::$perm)) {
                \AA::$perm->setPermMode('cron');
            }
            (new ScheduleAaTasks())->planAaTasks();
            (new Toexecute())->execute();
        } catch (\Throwable $e) {
            // Best-effort: log and continue. The user response has already
            // been sent, so we must never propagate exceptions out of here.
            if (class_exists('AA_Log')) {
                \AA_Log::write('CRON_FALLBACK_ERR', 'CronFallback', $e->getMessage());
            }
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }
}
