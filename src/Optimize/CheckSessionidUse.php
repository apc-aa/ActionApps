<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Util\LogOnce;
use AA_Log;

/** Who asked central/responder.php for Get_Sessionid in the last 30 days - logged once a day per remote
 *  address and kind of login, to learn whether the command is still used. Index-only.
 */
class CheckSessionidUse extends AbstractOptimize
{
    /** log.type of the rows - selector remote address:kind of login */
    public const EVENT = 'SESSID_USE';

    /** kinds of login: free/freepwd (AA_ClientAuth), a username and password (a script logging in to the
     *  admin pages), the session of a cookie (AA_ClientAuth checking its AA_Sess on each page view) */
    public const READER   = 'reader';
    public const PASSWORD = 'password';
    public const SESSION  = 'session';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    /** Called by Get_Sessionid. Only a real login is recorded, and no login name or password - the
     *  address and the kind of login are enough to find the caller.
     *  @param array<string, mixed> $server  $_SERVER
     *  @param array<string, mixed> $request $_REQUEST
     *  @param string               $uid     AuthGetUid()
     *  @param string               $login   AA_Auth::login()
     *  @return bool a row was written */
    public static function record(array $server, array $request, string $uid, string $login): bool
    {
        if (($uid === '') || ($uid === 'nobody')) {
            return false;
        }
        $kind = !empty($request['free']) ? self::READER : (($login === 'password') ? self::PASSWORD : self::SESSION);
        return LogOnce::daily(self::EVENT, ParamImplode([(string) ($server['REMOTE_ADDR'] ?? ''), $kind]));
    }

    public static function name(): string
    {
        return _m('Get_Sessionid use');
    }

    public static function description(): string
    {
        return _m('Lists who asked for a session id by the Get_Sessionid remote command in the last 30 days - scripts logging in to the admin pages, or external sites using AA_ClientAuth.');
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

    /** what to do about a caller of the kind */
    public static function advice(string $kind): string
    {
        return match ($kind) {
            self::READER   => _m('An external site logs its readers in by AA_ClientAuth (free/freepwd).'),
            self::PASSWORD => _m('A username and password login - a script using the admin pages, or an ActionApps Central older than this AA.'),
            default        => _m('An external site resumes its reader sessions by AA_ClientAuth (the AA_Sess cookie, checked on each page view).'),
        };
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
            [$address, $kind] = array_pad(ParamExplode($selector), 2, '');
            $findings[] = new Finding(
                Finding::INFO,
                _m('Get_Sessionid called from %1 (%2 login)', [$address, $kind]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Address')   => safe($address),
                    _m('Login')     => safe($kind),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: self::advice($kind),
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('Nobody called Get_Sessionid in the last 30 days'))];
    }
}
