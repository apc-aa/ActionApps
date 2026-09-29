<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Central\Actionapps;

/** Central keeps a superadmin login token of each AA it manages (central_conf.AA_ADMIN_PWD), encrypted
 *  with a key of AA_SECRET and bound to the address of the AA. Warns about rows holding anything else - a
 *  password or a plain token lying in the database. Central never sends those; Get Token overwrites them. */
class CheckCentralCredentials extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Central logins');
    }

    public static function description(): string
    {
        return _m('Checks that ActionApps Central keeps the logins to its AAs only as encrypted tokens - no passwords or plain tokens in the database.');
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
        return self::assess(Actionapps::all());
    }

    /** @param Actionapps[] $aas the central_conf rows @return Finding[] */
    public static function assess(array $aas): array
    {
        $plain = [];
        foreach ($aas as $aa) {
            if ($aa->credentialState() === Actionapps::CREDENTIAL_PLAIN) {
                // the name and id only - never the stored value
                $plain[] = ['id' => (string) $aa->getId(), _m('AA') => safe($aa->getName())];
            }
        }
        if (!$plain) {
            return [new Finding(Finding::OK, _m('Central keeps no password and no plain token'))];
        }
        return [new Finding(
            Finding::WARNING,
            _m('Central keeps a password or a plain token of %1 AAs', [count($plain)]),
            count: count($plain),
            evidence: $plain,
            advice: _m('Central never sends them. Press Get Token for that AA on the ActionApps Central page (it overwrites the value).'),
            links: [_m('ActionApps Central') => AA_INSTAL_PATH . 'central/index.php'],
            key: 'plain',
        )];
    }
}
