<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Http\TransportException;
use AA\Util\LogOnce;
use AA_Log;

/** Addresses AA refused to fetch in the last 30 days because they lead to the local or private
 *  network (Client::publicOnly()) - logged once a day per purpose and client address. Index-only. */
class CheckBlockedRequests extends AbstractOptimize
{
    /** log.type of the rows - selector purpose:client address, params the refused host. Not keyed by the
     *  host: an anonymous caller chooses it freely and would get a row for every name they invent. */
    public const EVENT = 'HTTP_BLOCK';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    /**
     * Logs the failure if the guard refused the address. The guard reports a host name it could not
     * look up the same way - that is a typo or a dead domain, not the local network, so it is left out.
     * @param string $purpose what fetched it - the request parameter or template command
     * @return bool a row was written
     */
    public static function record(TransportException $e, string $purpose, string $client): bool
    {
        $host = $e->blockedHost();
        return !is_null($host) && self::exists($host) && LogOnce::daily(self::EVENT, ParamImplode([$purpose, $client]), $host);
    }

    /** an address, or a name that resolves */
    private static function exists(string $host): bool
    {
        return (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) || gethostbynamel($host) || @dns_get_record($host, DNS_AAAA);
    }

    public static function name(): string
    {
        return _m('Refused requests to the local network');
    }

    public static function description(): string
    {
        return _m('Lists the addresses AA refused to fetch in the last 30 days because they lead to the local or private network of the server.');
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
        return self::findingsFor(LogOnce::recent(self::EVENT, now() - self::WINDOW_SECONDS, self::GROUPS));
    }

    /**
     * @param list<array<string, ?string>> $rows LogOnce::recent() rows
     * @return Finding[]
     */
    public static function findingsFor(array $rows): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$purpose, $client] = array_pad(ParamExplode($selector), 2, '');
            $host = (string) $row['params'];
            $findings[] = new Finding(
                Finding::WARNING,
                _m('%1 from %2 asked for %3, which was refused', [$purpose, $client, $host]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Asked by')  => safe($purpose),
                    _m('Client')    => safe($client),
                    _m('Host')      => safe($host),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('AA fetches only public addresses for forms and templates - otherwise whoever chooses the address reads the internal services of the server. Most often this is a probe. If it is a site of yours, use its public domain name.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No address was refused in the last 30 days'))];
    }
}
