<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\LogOnce;
use AA_Log;

/** Values the server has but {server:...} refused in the last 30 days - the template got an empty string, because
 *  AA hands templates only the request values it lists (host, URI, address, browser, referer, ...).
 *  Logged once a day per name with one page that asked (LogOnce). Index-only, hence basic.
 */
class CheckServerRefusals extends AbstractOptimize
{
    /** log.type of the rows - selector the name, params the host and URI of a page that asked */
    public const EVENT = 'SERVER_DNY';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Values refused to {server:...}');
    }

    public static function description(): string
    {
        return _m('Lists the names that templates asked for by {server:...} in the last 30 days and got an empty string, because AA does not hand that value to templates.');
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
            $name = (string) $row['selector'];
            $findings[] = new Finding(
                Finding::WARNING,
                _m('A template asked for {server:%1} and got an empty string', [$name]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Name')      => safe($name),
                    _m('Page')      => safe((string) $row['params']),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: _m('Templates get the host, the URI and query string, the scheme and port, the visitor\'s address, browser, referer and Accept headers, and a few more values of the request; cookies, passwords, authorization and other headers or variables of the web server are not for templates. For a URL parameter use {qs:...}, for a cookie {cookie:...}, for the logged-in user {user}. If the page needs a value AA does not hand out, ask the administrator of AA.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $name)],
                key: $name,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No template asked {server:...} for a value AA does not hand out in the last 30 days'))];
    }
}
