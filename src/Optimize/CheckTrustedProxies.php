<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\LogOnce;
use AA\Util\TrustedProxy;
use AA_Log;

/** AA_TRUSTED_PROXIES in config.php. A proxy missing from the list shows only in odd ways - every visitor
 *  with the proxy's address, links to the backend host name, the APIs refusing pages of the real domain -
 *  so TrustedProxy logs such a proxy once a day and this check lists it. Index-only.
 */
class CheckTrustedProxies extends AbstractOptimize
{
    /** log.type of the rows - selector the address of the proxy */
    public const EVENT = 'PROXY_UNTR';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    /** Called at the end of a request whose proxy headers came from TrustedProxy::untrustedProxy(). */
    public static function record(string $address): bool
    {
        return LogOnce::daily(self::EVENT, $address);
    }

    public static function name(): string
    {
        return _m('Reverse proxy');
    }

    public static function description(): string
    {
        return _m('Checks AA_TRUSTED_PROXIES in config.php and lists proxies outside it that sent requests in the last 30 days - AA takes the visitor address, the domain and https only from a trusted proxy.');
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
        return self::assess(TrustedProxy::configured(), defined('AA_TRUSTED_PROXIES'), LogOnce::recent(self::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS), $_SERVER);
    }

    /**
     * @param list<string>                 $proxies    TrustedProxy::configured()
     * @param bool                         $configured AA_TRUSTED_PROXIES is defined
     * @param list<array<string, ?string>> $rows       LogOnce::recent() rows
     * @param array<string, mixed>         $server     $_SERVER of this page, after TrustedProxy::apply()
     * @return Finding[]
     */
    public static function assess(array $proxies, bool $configured, array $rows, array $server): array
    {
        $findings = [];
        $list     = safe($proxies ? implode(', ', $proxies) : _m('nobody')) . ($configured ? '' : ' (' . _m('default') . ')');
        $evidence = [['AA_TRUSTED_PROXIES' => $list]];
        $example  = "define('AA_TRUSTED_PROXIES', ['127.0.0.1', '::1', '10.0.0.0/8']);";

        if ($invalid = TrustedProxy::invalidEntries($proxies)) {
            $findings[] = new Finding(
                Finding::ERROR,
                _m('AA_TRUSTED_PROXIES in config.php has entries that are neither an address nor a range: %1', [implode(', ', $invalid)]),
                evidence: $evidence,
                advice: _m('Write addresses and CIDR ranges, e.g. %1', [$example]),
                key: 'invalid',
            );
        }
        if ($broad = TrustedProxy::tooBroad($proxies)) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('AA_TRUSTED_PROXIES in config.php trusts a range wider than any private network: %1', [implode(', ', $broad)]),
                evidence: $evidence,
                advice: _m('List only the addresses or the network of your proxies, e.g. %1', [$example]),
                key: 'broad',
            );
        }

        foreach ($rows as $row) {
            $address = (string) $row['selector'];
            if (TrustedProxy::isTrusted($address, $proxies)) {
                continue;   // added to the list since
            }
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Proxy headers came from %1, which is not in AA_TRUSTED_PROXIES, so AA ignored them', [$address]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Address')   => safe($address),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                    'AA_TRUSTED_PROXIES' => $list,
                ]],
                advice: _m("If %1 is your proxy, add it to include/config.php: define('AA_TRUSTED_PROXIES', ['%1']); Until then AA sees every visitor with the address of the proxy and builds links with the host name the proxy uses for AA.", [$address]),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $address)],
                key: $address,
                last: (int) $row['last'],
            );
        }

        $proxy = (string) ($server[TrustedProxy::PROXY_KEY] ?? '');
        if ($proxy !== '') {
            $findings[] = new Finding(Finding::OK, _m('This page came through the trusted proxy %1', [$proxy]), evidence: $evidence);
            if (TrustedProxy::isTrusted((string) ($server['REMOTE_ADDR'] ?? ''), $proxies)) {
                $findings[] = new Finding(
                    Finding::INFO,
                    _m('The proxy %1 does not tell AA the address of the visitor', [$proxy]),
                    advice: _m('Let the proxy send the X-Forwarded-For header with the address of the visitor. Until then AA sees every visitor with one address.'),
                    key: 'for',
                );
            }
            if (($server['HTTP_X_FORWARDED_PROTO'] ?? '') === '' && (($server['HTTPS'] ?? '') === '' || strtolower((string) $server['HTTPS']) === 'off')) {
                $findings[] = new Finding(
                    Finding::INFO,
                    _m('The proxy %1 does not tell AA whether the visitor uses https', [$proxy]),
                    advice: _m('Let the proxy set the X-Forwarded-Proto header - in Apache RequestHeader set X-Forwarded-Proto "https" in the https virtual host and "http" in the plain one. AA then marks its cookies secure and builds https links.'),
                    key: 'proto',
                );
            }
        } elseif (!$findings) {
            $findings[] = new Finding(Finding::OK, _m('No proxy outside AA_TRUSTED_PROXIES sent requests in the last 30 days'), evidence: $evidence);
        }
        return $findings;
    }
}
