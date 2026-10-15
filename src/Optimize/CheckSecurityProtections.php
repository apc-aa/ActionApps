<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Security\Protection;
use AA\Security\Settings;
use AA\Security\ProtectionLog;
use AA\Util\LogOnce;
use AA;
use AA_Log;

/** Protections switched off on admin/aa_security.php, and what the measured ones met lately
 *  (refused, or run while the protection was off). Index-only. */
class CheckSecurityProtections extends AbstractOptimize
{
    /** how far back State Check and admin/aa_security.php look into the log */
    public const WINDOW_DAYS = 30;
    private const GROUPS = 50;

    public static function name(): string
    {
        return _m('Security protections');
    }

    public static function description(): string
    {
        return _m('Lists the security protections switched off on this install and what they refused in the last %1 days.', [self::WINDOW_DAYS]);
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
        $off    = [];
        $logged = [];
        foreach (Protection::cases() as $protection) {
            foreach (is_null($event = $protection->event()) ? [] : LogOnce::recent($event, now() - self::WINDOW_DAYS * 86400, self::GROUPS) as $row) {
                $logged[] = [$protection, $row];
            }
            if (!Settings::isOn($protection)) {
                $switch = Settings::lastSwitch($protection);
                if (isset($switch['user'])) {
                    $switch['user'] = AA::$perm->getUserName((string) $switch['user']);
                }
                $off[] = [$protection, $switch];
            }
        }
        return self::findingsFor($off, $logged);
    }

    /**
     * @param list<array{Protection, array<string, mixed>}> $off  switched-off protections with their last switch (Settings::lastSwitch(), user as a name)
     * @param list<array{Protection, array<string, ?string>}> $logged LogOnce::recent() rows of each protection's event
     * @return Finding[]
     */
    public static function findingsFor(array $off, array $logged): array
    {
        $page     = [_m('Security') => AA_INSTAL_PATH . 'admin/aa_security.php'];
        $findings = [];
        foreach ($off as [$protection, $switch]) {
            $findings[] = new Finding(
                Finding::WARNING,
                _m('Protection "%1" is switched off', [$protection->label()]),
                since: (int) ($switch['time'] ?? 0),
                evidence: [[
                    _m('Protection')   => safe($protection->label()),
                    _m('Switched off') => isset($switch['time']) ? date('Y-m-d H:i', (int) $switch['time']) : _m('unknown'),
                    _m('By')           => safe((string) ($switch['user'] ?? '')),
                ]],
                advice: _m('Switch it on again once the older forms are converted.') . ' ' . $protection->migration(),
                links: $page,
                key: 'off:' . $protection->value,
                kind: _m('Protection switched off'),
            );
        }
        foreach ($logged as [$protection, $row]) {
            $selector = (string) $row['selector'];
            [$refused, $param, $source] = ProtectionLog::parse($selector);
            $findings[] = new Finding(
                $protection->loggedNeedsAction($refused) ? Finding::WARNING : Finding::INFO,
                $protection->loggedTitle($refused, $source, $param),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    $protection->loggedParam() => safe($param),
                    _m('Page')      => safe($source),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: $protection->loggedAdvice(),
                links: [_m('Log rows') => AA_Log::getAdminUrl((string) $protection->event(), $selector)] + $page,
                key: $selector,
                last: (int) $row['last'],
                kind: $protection->loggedKind($refused),
                place: $source,
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('All protections are on and none of them refused anything in the last %1 days', [self::WINDOW_DAYS]))];
    }
}
