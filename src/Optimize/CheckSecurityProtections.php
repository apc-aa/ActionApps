<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Security\Protection;
use AA\Security\Settings;
use AA\Security\SignedExpression;
use AA\Util\LogOnce;
use AA;
use AA_Log;

/** Protections switched off on admin/aa_security.php, and the unsigned expressions that forms sent
 *  lately (refused, or run while the protection was off). Index-only. */
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
        return _m('Lists the security protections switched off on this install and the unsigned expressions forms sent in the last %1 days.', [self::WINDOW_DAYS]);
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
        $off = [];
        foreach (Protection::cases() as $protection) {
            if (!Settings::isOn($protection)) {
                $switch = Settings::lastSwitch($protection);
                if (isset($switch['user'])) {
                    $switch['user'] = AA::$perm->getUserName((string) $switch['user']);
                }
                $off[] = [$protection, $switch];
            }
        }
        return self::findingsFor($off, LogOnce::recent(SignedExpression::EVENT, now() - self::WINDOW_DAYS * 86400, self::GROUPS));
    }

    /**
     * @param list<array{Protection, array<string, mixed>}> $off  switched-off protections with their last switch (Settings::lastSwitch(), user as a name)
     * @param list<array<string, ?string>>                  $rows LogOnce::recent() rows of SignedExpression::EVENT
     * @return Finding[]
     */
    public static function findingsFor(array $off, array $rows): array
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
                advice: $protection->risk() . ' ' . _m('Switch it on again once the templates that need it are fixed.'),
                links: $page,
                key: 'off:' . $protection->value,
            );
        }
        foreach ($rows as $row) {
            $selector = (string) $row['selector'];
            [$refused, $param, $source] = SignedExpression::logged($selector);
            $findings[] = new Finding(
                Finding::WARNING,
                $refused
                    ? _m('A form on %1 sent an unsigned expression in %2; it was refused', [$source ?: _m('an unknown page'), $param])
                    : _m('A form on %1 sent an unsigned expression in %2; it ran because the protection was off', [$source ?: _m('an unknown page'), $param]),
                count: (int) $row['days'],
                since: (int) $row['first'],
                evidence: [[
                    _m('Parameter') => safe($param),
                    _m('Page')      => safe($source),
                    _m('Days seen') => (string) (int) $row['days'],
                    _m('Last')      => date('Y-m-d', (int) $row['last']),
                ]],
                advice: Protection::SignedExpressions->effect() . ' ' . _m('A page you do not know is most likely a probe.'),
                links: [_m('Log rows') => AA_Log::getAdminUrl(SignedExpression::EVENT, $selector)] + $page,
                key: $selector,
                last: (int) $row['last'],
            );
        }
        return $findings ? CheckRunner::sort($findings) : [new Finding(Finding::OK, _m('All protections are on and no form sent an unsigned expression in the last %1 days', [self::WINDOW_DAYS]))];
    }
}
