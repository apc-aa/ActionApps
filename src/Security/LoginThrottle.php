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
 * from every address, so nobody can slow down someone else's login.
 * A refused attempt checks no password and writes no LOGIN row, so an attack does not grow what is counted;
 * a few requests sent at once may still pass over the limit.
 */
final class LoginThrottle
{
    /** log.type of the refused attempts - selector refused|allowed:address (ProtectionLog) */
    public const EVENT = 'LOGIN_THR';
    /** log.type of a failed check of credentials a template passed - it counts only for the name */
    public const TEMPLATE = 'LOGIN_TPL';

    private const WINDOW = 43200;
    /** [free failures, first wait, longest wait] in seconds */
    private const SOURCE  = [20, 60, 3600];
    private const ACCOUNT = [5, 30, 900];

    /** seconds the refused login of this request has to wait */
    private static int $wait = 0;

    /** false when the login must not be checked now - refuse() then answers */
    public static function admit(string $username): bool
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
        $now  = time();
        $rows = DB_AA::select([], 'SELECT `time`, `type`, `selector`, `params` FROM `log`', [['type', ['LOGIN', self::TEMPLATE]], ['time', $now - self::WINDOW, '>='], ['selector', $needle, 'CONTAIN']], ['time', 'id']);
        if (($wait = self::wait($rows, $username, $ip, $now)) === 0) {
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
     * The row admit() counts. Credentials a template passed ({jwtencode}) count only for the name: wrong ones
     * written in a template would otherwise slow down every visitor who shows the page.
     */
    public static function failed(string $username, bool $template = false): void
    {
        AA_Log::write($template ? self::TEMPLATE : 'LOGIN', AA_Log::context(''), self::stored($username));
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
     * @param list<array{time: int|string, type?: string, selector: string, params: ?string}> $rows LOGIN and
     *        TEMPLATE rows, oldest first; rows of other addresses are skipped
     */
    public static function wait(array $rows, string $username, string $ip, int $now): int
    {
        $source  = self::source($ip);
        $name    = self::nameKey($username);
        $by_source = $by_name = [];
        foreach ($rows as $row) {
            [$uid, , $address] = array_pad(ParamExplode((string) $row['selector']), 3, '');
            // a row without a name is no guess (older AA logged even an empty login form)
            if ((self::source(str_replace('_', ':', (string) $address)) !== $source) || ((string) $row['params'] === '')) {
                continue;
            }
            $same_name = (self::nameKey((string) $row['params']) === $name);
            if ($uid !== '') {
                if ($same_name) {
                    $by_name = [];   // the right password forgets the failures before it - of the name, not of the address
                }
                continue;
            }
            if (($row['type'] ?? 'LOGIN') !== self::TEMPLATE) {
                $by_source[] = (int) $row['time'];
            }
            if ($same_name) {
                $by_name[] = (int) $row['time'];
            }
        }
        return max(self::delay($by_source, self::SOURCE, $now), self::delay($by_name, self::ACCOUNT, $now));
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
