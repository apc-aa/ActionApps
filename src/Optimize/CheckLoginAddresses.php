<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Security\LoginThrottle;
use AA\Security\ProtectionLog;
use AA\Util\LogOnce;
use AA_Log;

/** Whether the visitor addresses of the logins look real. LoginThrottle counts failed logins per address, so when
 *  AA sees many people under one address - a proxy missing in AA_TRUSTED_PROXIES, a shared office network - the
 *  failures of anyone slow down the login of all of them. Index-only (log type_time). */
class CheckLoginAddresses extends AbstractOptimize
{
    private const WINDOW_SECONDS = 30 * 86400;
    /** names logged in or tried from one address before "every login comes from one address" means something */
    private const NAMES_MIN = 3;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Login addresses');
    }

    public static function description(): string
    {
        return _m('Warns when the logins of the last 30 days come from one address, or when an address that was slowed down after failed logins is shared by several users - failed logins are counted per address.');
    }

    public static function types(): array
    {
        return [self::CHECK_BASIC];
    }

    public static function category(): string
    {
        return 'security';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    public function findings(): array
    {
        $since = now() - self::WINDOW_SECONDS;
        $login = [['type', 'LOGIN'], ['time', $since, '>='], ['params', '', 'FILLED']];
        // the address is the third part of the selector (AA_Log::context())
        $spread = DB_AA::select1('', "SELECT COUNT(DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(`selector`, ':', 3), ':', -1)) AS addresses, COUNT(DISTINCT `params`) AS names, MIN(`selector`) AS one FROM `log`", $login) ?: [];

        $slowed = [];
        foreach (LogOnce::recent(LoginThrottle::EVENT, $since, self::GROUPS) as $row) {
            [$refused, $address] = ProtectionLog::parse((string) $row['selector']);
            if ($refused && ($address !== '')) {
                $slowed[$address] = [];
            }
        }
        if ($slowed) {
            $needles = array_map(static fn(string $address): string => ':' . str_replace(':', '_', $address) . ':', array_keys($slowed));
            $logins  = DB_AA::select([], 'SELECT `selector`, `params` FROM `log`', array_merge($login, [['selector', ':', 'NOT BEGIN'], ['selector', $needles, 'CONTAIN']]), null, null, 10000);
            foreach ($logins as $row) {
                if (isset($slowed[$address = LoginThrottle::address((string) $row['selector'])])) {
                    $slowed[$address][mb_strtolower((string) $row['params'], 'UTF-8')] = true;
                }
            }
        }
        return self::assess((int) ($spread['addresses'] ?? 0), (int) ($spread['names'] ?? 0), LoginThrottle::address((string) ($spread['one'] ?? '')), array_map('count', $slowed));
    }

    /**
     * @param int                $addresses addresses of the LOGIN rows with a name in the window
     * @param int                $names     names in them
     * @param string             $one       one of the addresses
     * @param array<string, int> $slowed    addresses slowed down by LoginThrottle => users who logged in from them
     * @return Finding[]
     */
    public static function assess(int $addresses, int $names, string $one, array $slowed): array
    {
        $findings = [];
        if (($addresses === 1) && ($names >= self::NAMES_MIN)) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Every login of the last 30 days came from one address, %1', [$one]),
                evidence: [[_m('Address') => safe($one), _m('Names') => (string) $names]],
                advice: self::proxyAdvice($one) . ' ' . _m('Until then AA sees all visitors as one, and 20 failed logins of anyone, a scanner included, slow down the login of everybody. If it is the address of the only office that uses this AA, nothing needs to change.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl('LOGIN')],
                key: 'one:' . $one,
            );
        }
        foreach ($slowed as $address => $users) {
            if ($users < 2) {
                continue;
            }
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Logins from %1 were slowed down after failed attempts, and %2 users log in from that address', [$address, $users]),
                evidence: [[_m('Address') => safe($address), _m('Users') => (string) $users]],
                advice: _m('Their failed logins add up, so all of them wait.') . ' ' . self::proxyAdvice($address) . ' ' . _m('If it is the shared address of an office, find the failing logins in the Log (event LOGIN) - often a script or a browser keeps an old password.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl('LOGIN')],
                key: 'shared:' . $address,
            );
        }
        return $findings ?: [new Finding(Finding::OK, _m('Logins come from %1 addresses; no address slowed down after failed logins is shared by several users', [$addresses]))];
    }

    private static function proxyAdvice(string $address): string
    {
        return _m('If %1 is a proxy in front of AA, add it to AA_TRUSTED_PROXIES in include/config.php: %2 - AA then counts each visitor by their own address.', [$address, "define('AA_TRUSTED_PROXIES', ['127.0.0.1', '::1', '" . $address . "']);"]);
    }
}
