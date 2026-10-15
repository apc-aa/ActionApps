<?php

declare(strict_types=1);

namespace AA\Security;

use AA\IO\DB\DB_AA;
use AA\Util\TrustedProxy;
use AA\Util\Txt;
use AA_Log;
use AA_Logentry;

/**
 * Slows down repeated failed logins - the gate of AA_Perm::authenticateUsername(). It counts the failed
 * LOGIN rows of the last 12 hours from the visitor's address: after the free failures each next attempt
 * waits since the last one, twice as long each time, up to a cap. Two counters: the address alone (one
 * address trying many names) and the address with the typed name (one name guessed). None counts the name
 * from every address, so nobody can slow down someone else's login. A superadmin can let a name in at once (allow()).
 * A refused attempt checks no password and writes no LOGIN row, so an attack does not grow what is counted;
 * a few requests sent at once may still pass over the limit.
 */
final class LoginThrottle
{
    /** log.type of the refused attempts - selector refused|allowed:address (ProtectionLog) */
    public const EVENT = 'LOGIN_THR';
    /** log.type of a failed check of credentials a template passed - it counts only for the name */
    public const TEMPLATE = 'LOGIN_TPL';
    /** log.type of a name a superadmin let in - the failures before it no longer hold that name back, from any address */
    public const CLEARED = 'LOGIN_CLR';

    private const WINDOW = 43200;
    /** [free failures, first wait, longest wait] in seconds */
    private const SOURCE  = [20, 60, 3600];
    private const ACCOUNT = [5, 30, 900];
    /** checks a template runs, from one address - stops a template that tries name after name, not a page with wrong example credentials */
    private const TEMPLATES = [100, 60, 3600];
    private const COUNTED   = ['LOGIN', self::TEMPLATE];
    /** a failed check answers this long after it started - above the costliest stored hash (bcrypt cost 14: 0.7 s) */
    private const FAILURE_SECONDS = 1.0;
    /** failed logins status() reads at most, and the addresses it works out the wait for (a query each) */
    private const STATUS_ROWS      = 10000;
    private const STATUS_ADDRESSES = 20;

    /** seconds the refused login of this request has to wait */
    private static int $wait = 0;

    /** false when the login must not be checked now - refuse() then answers; $template see failed() */
    public static function admit(string $username, bool $template = false): bool
    {
        self::$wait = 0;
        // a login sent by an image, frame or script of another site is no visitor's own - it would let any page
        // the visitor opens slow down their logins; refused unchecked, so a script faking it gains nothing
        if ((($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') && (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? 'document') !== 'document')) {
            return false;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (is_null($needle = self::needle($ip))) {
            return true;   // command line: nobody to count
        }
        $now = time();
        if (($wait = self::wait(self::rows($needle, $now), $username, $ip, $now, $template)) === 0) {
            return true;
        }
        $allowed = !Settings::isOn(Protection::LoginThrottle);
        // the address only - with the page from the request a prober could use up the daily rows of ProtectionLog
        ProtectionLog::write(Protection::LoginThrottle, $allowed, $ip, '');
        if ($allowed) {
            return true;
        }
        self::$wait = $wait;
        return false;
    }

    /**
     * The row admit() counts. Credentials a template passed ({jwtencode}) count for the name, and for the address
     * only against further template checks: wrong ones written in a template would otherwise slow down the logins
     * of every visitor who shows the page.
     */
    public static function failed(string $username, bool $template = false): void
    {
        AA_Log::write($template ? self::TEMPLATE : 'LOGIN', AA_Log::context('', false), self::stored($username));
    }

    /** The next login of $username is checked at once, from any address; $by_uid is the superadmin who let it in. */
    public static function allow(string $username, string $by_uid): void
    {
        AA_Log::write(self::CLEARED, AA_Log::context($by_uid), self::stored($username));
    }

    /**
     * The failed logins of $username in the last 12 hours by address (IPv6 by its network /64, as counted), newest
     * first, and how long its next login from there waits - for the superadmin helping the user. A login refused only
     * for the failures of other names from its address is not among them.
     * @return array{0: list<array{address: string, failures: int, last: int, wait: int}>, 1: int} the newest
     *         STATUS_ADDRESSES addresses, and how many there are
     */
    public static function status(string $username, int $now): array
    {
        $name   = self::nameKey($username);
        $groups = [];
        // log.params is binary - converted, it compares in any letter case; the index range on type_time comes first
        $where = [['type', self::COUNTED], ['time', $now - self::WINDOW, '>='], ['selector', ':', 'BEGIN'], ['CONVERT(`params` USING utf8mb4)', trim(self::stored($username))]];
        foreach (DB_AA::select([], 'SELECT `time`, `selector`, `params` FROM `log`', $where, ['time-'], null, self::STATUS_ROWS) as $row) {
            $address = self::address((string) $row['selector']);
            if ((self::nameKey((string) $row['params']) === $name) && !is_null($source = self::source($address))) {
                $groups[$source] ??= ['address' => self::shown($source), 'failures' => 0, 'last' => (int) $row['time'], 'sample' => $address];
                ++$groups[$source]['failures'];
            }
        }
        $status = [];
        foreach (array_slice($groups, 0, self::STATUS_ADDRESSES) as $group) {
            $wait     = self::wait(self::rows((string) self::needle($group['sample']), $now), $username, $group['sample'], $now);
            $status[] = ['address' => $group['address'], 'failures' => $group['failures'], 'last' => $group['last'], 'wait' => $wait];
        }
        return [$status, count($groups)];
    }

    /**
     * Waits until the failed check that started at $started (hrtime) has taken FAILURE_SECONDS. Not on the command
     * line - nobody times it there - and not inside a transaction, whose locks the sleep would hold.
     */
    public static function pad(int $started, string $sapi = PHP_SAPI): void
    {
        if (($sapi !== 'cli') && !DB_AA::inTransaction() && (($rest = self::rest($started, hrtime(true))) > 0)) {
            usleep((int) round($rest * 1000000));
        }
    }

    /** seconds a failed check that started at $started still has to wait at $now (both hrtime nanoseconds) */
    public static function rest(int $started, int $now): float
    {
        return max(0.0, self::FAILURE_SECONDS - ($now - $started) / 1e9);
    }

    /** minutes the login refused in this request has to wait; 0 when none was refused */
    public static function minutes(): int
    {
        return (int) ceil(self::$wait / 60);
    }

    /**
     * What the visitor of a login refused in this request reads; null when none was refused. The same for any
     * name, so it tells nothing about accounts. With $status the answer is 429 Too Many Requests, for scripts.
     */
    public static function refuse(bool $status = true): ?string
    {
        if (self::$wait === 0) {
            return null;
        }
        if (!headers_sent()) {
            if ($status) {
                http_response_code(429);
            }
            header('Retry-After: ' . self::$wait);
        }
        return _m('Too many failed login attempts. Try again in %1 min.', [self::minutes()]);
    }

    /**
     * Seconds the login of $username from $ip has to wait at $now; 0 = check it now.
     * @param list<array{time: int|string, type?: string, selector: string, params: ?string}> $rows LOGIN, TEMPLATE
     *        and CLEARED rows, oldest first; rows of other addresses are skipped
     * @param bool $template a check of credentials a template passed - the address counts its template checks too
     */
    public static function wait(array $rows, string $username, string $ip, int $now, bool $template = false): int
    {
        $source  = self::source($ip);
        $name    = self::nameKey($username);
        $by_source = $by_name = $by_template = [];
        foreach ($rows as $row) {
            $same_name = (self::nameKey((string) $row['params']) === $name);
            if (($row['type'] ?? '') === self::CLEARED) {
                if ($same_name) {
                    $by_source = $by_name = $by_template = [];   // let in by a superadmin - from wherever the failures came
                }
                continue;
            }
            [$uid, , $address] = array_pad(ParamExplode((string) $row['selector']), 3, '');
            // a row without a name is no guess (older AA logged even an empty login form)
            if ((self::source(str_replace('_', ':', (string) $address)) !== $source) || ((string) $row['params'] === '')) {
                continue;
            }
            if ($uid !== '') {
                if ($same_name) {
                    $by_name = [];   // the right password forgets the failures before it - of the name, not of the address
                }
                continue;
            }
            if (($row['type'] ?? 'LOGIN') !== self::TEMPLATE) {
                $by_source[] = (int) $row['time'];
            } else {
                $by_template[] = (int) $row['time'];
            }
            if ($same_name) {
                $by_name[] = (int) $row['time'];
            }
        }
        return max(self::delay($by_source, self::SOURCE, $now), self::delay($by_name, self::ACCOUNT, $now), $template ? self::delay($by_template, self::TEMPLATES, $now) : 0);
    }

    /**
     * @param int[]               $failures times, oldest first
     * @param array{int, int, int} $limits   see SOURCE
     */
    private static function delay(array $failures, array $limits, int $now): int
    {
        [$free, $first, $longest] = $limits;
        $over = count($failures) - $free;
        if ($over < 0) {
            return 0;
        }
        return max(0, end($failures) + min($longest, $first * 2 ** min($over, 20)) - $now);
    }

    /** the LOGIN and TEMPLATE rows whose selector contains $needle, and the names let in, of the window, oldest first */
    private static function rows(string $needle, int $now): array
    {
        $counted = [['type', self::COUNTED], ['selector', $needle, 'CONTAIN']];
        return DB_AA::select([], 'SELECT `time`, `type`, `selector`, `params` FROM `log`', [['time', $now - self::WINDOW, '>='], [null, [$counted, [['type', self::CLEARED]]], 'OR']], ['time', 'id']);
    }

    /** the visitor address of a LOGIN row, as REMOTE_ADDR had it (AA_Log::context() writes "_" for ":") */
    public static function address(string $selector): string
    {
        return str_replace('_', ':', (string) (ParamExplode($selector)[2] ?? ''));
    }

    /** an address as counted, for people: IPv4 as it is, IPv6 as its network /64 */
    private static function shown(string $source): string
    {
        return (strlen($source) === 4) ? (string) inet_ntop($source) : inet_ntop($source . str_repeat("\0", 8)) . '/64';
    }

    /** what an address counts as: an IPv4 address, an IPv6 /64 (one network - a wider prefix may hold other customers); null for none */
    private static function source(string $ip): ?string
    {
        $packed = TrustedProxy::pack($ip);
        return is_null($packed) ? null : substr($packed, 0, 8);
    }

    /**
     * Text the selector of a LOGIN row from the address contains - narrows the rows in SQL, wait() picks the exact
     * ones. AA_Log::context() writes the address with "_" for ":"; IPv4 may come mapped to IPv6 (__ffff_1.2.3.4).
     */
    private static function needle(string $ip): ?string
    {
        $packed = TrustedProxy::pack($ip);
        if (is_null($packed)) {
            return null;
        }
        return (strlen($packed) === 4) ? inet_ntop($packed) . ':' : ':' . strstr(inet_ntop($packed) . ':', ':', true) . '_';
    }

    /** the name as the LOGIN row stores it - cut to the column, an over-long value would fail the INSERT */
    public static function stored(string $username): string
    {
        return Txt::utf8Cut($username, AA_Logentry::PARAMS_MAX);
    }

    private static function nameKey(string $username): string
    {
        return mb_strtolower(trim(self::stored($username)), 'UTF-8');
    }
}
