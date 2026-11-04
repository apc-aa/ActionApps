<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Passkey\Refused;
use AA\Passkey\Store;
use AA\Util\LogOnce;
use AA_Log;

/** Passkey logins refused in the last 30 days, by reason - and the right password of an account that logs in only
 *  by a passkey, which someone else then knows. Logged once a day per reason and detail. Index-only.
 */
class CheckPasskeys extends AbstractOptimize
{
    /** log.type of the rows - selector reason:detail */
    public const EVENT = 'PASSKEY_NO';
    /** the reason of a password that was right, but the account has a passkey - detail: the name typed */
    public const PASSWORD = 'password';
    /** the reason of a password refused because the directory could not tell whether the account has a passkey */
    public const DIRECTORY = 'directory';

    private const WINDOW_SECONDS = 30 * 86400;
    private const GROUPS = 50;

    /** @param string $reason Refused::* or PASSWORD */
    public static function record(string $reason, string $detail = ''): void
    {
        LogOnce::daily(self::EVENT, ParamImplode([$reason, $detail]));
    }

    public static function name(): string
    {
        return _m('Passkey logins');
    }

    public static function description(): string
    {
        return _m('Lists the passkey logins refused in the last 30 days and the right passwords of accounts that log in by a passkey.');
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
        if (!(new Store())->available()) {
            return [new Finding(Finding::INFO, _m('Passkeys cannot be used yet - the table authenticator is missing'), advice: _m('Run service/sql_update.php - it creates the table.'))];
        }
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
            [$reason, $detail] = array_pad(ParamExplode($selector), 2, '');
            [$severity, $kind, $title, $advice] = self::describe($reason, $detail);
            $findings[] = new Finding(
                $severity,
                $title,
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Reason')    => safe($reason),
                    _m('Detail')    => safe($detail),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: $advice,
                links: [_m('Log rows') => AA_Log::getAdminUrl(self::EVENT, $selector)],
                key: $selector,
                last: (int) $row['last'],
                kind: $kind,
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('No passkey login was refused in the last 30 days'))];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} severity, kind, title, advice */
    private static function describe(string $reason, string $detail): array
    {
        return match ($reason) {
            self::PASSWORD    => [Finding::WARNING, _m('Right password of a passkey account'), _m('The right password of an account that logs in by a passkey (%1)', [$detail]), _m('The account logs in only by its passkey, so the password did not let anybody in - but someone knows it. If it was not the owner, the password should be changed; it is used again once the passkeys are deleted.')],
            self::DIRECTORY   => [Finding::WARNING, _m('Passkeys unknown - the directory did not answer'), _m('The directory did not answer whether %1 has a passkey', [$detail]), _m('The password was refused, as the account may log in only by a passkey. Check the LDAP server and the bind account of AA.')],
            Refused::ORIGIN   => [Finding::WARNING, _m('Passkey answer from another address'), _m('A passkey answer from another address than the page (%1)', [$detail]), _m('AA sees the page under another host or scheme than the browser - usually a reverse proxy missing from AA_TRUSTED_PROXIES, or a site reached by http.')],
            Refused::COUNTER  => [Finding::WARNING, _m('Security key counter went back'), _m('A security key of %1 signed with a counter it had used before', [$detail]), _m('The key may have been copied. The login was let through, so its owner is not locked out; ask the owner and delete the passkey on the page of the account if in doubt.')],
            Refused::UNKNOWN  => [Finding::INFO, _m('Unknown passkey'), _m('A login by a passkey this AA does not keep'), _m('Usually a passkey deleted in AA but still in the browser or phone of its owner, or one of another AA on the same host. The owner can delete it in the device.')],
            default           => [Finding::INFO, _m('Passkey login refused'), _m('A passkey login refused: %1', [$reason]), _m('An expired or repeated login, or an answer no browser sends. Nothing to do unless it repeats.')],
        };
    }
}
