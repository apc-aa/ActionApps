<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\Central\Actionapps;
use AA\IO\DB\DB_AA;
use AA_MbC;
use AA_MbT;

/** Central keeps a superadmin login token of each AA it manages (central_conf.AA_ADMIN_PWD), encrypted with
 *  a key of AA_SECRET and bound to the address of the AA. Warns about rows that keep a password, a plain or
 *  a cut token, or a token it cannot use, and about a column too narrow for the encrypted token. Repair
 *  widens the column, encrypts the plain tokens and clears the cut ones. */
class CheckCentralCredentials extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Central logins');
    }

    public static function description(): string
    {
        return _m('Checks that ActionApps Central keeps the logins to its AAs as encrypted tokens - no passwords, no plain tokens - and that their column is wide enough. Repair widens the column, encrypts the plain tokens and clears the cut ones.');
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
        return ['test', 'repair'];
    }

    public function findings(): array
    {
        return self::assess(Actionapps::all(), Actionapps::canEncrypt(), Actionapps::storedWidth(), Actionapps::schemaWidth());
    }

    public function repair(): bool
    {
        // the encrypted token is longer than the plain one - a narrow column would cut it
        $width = Actionapps::storedWidth();
        if (($width !== null) && ($width < Actionapps::schemaWidth()) && !$this->widenColumn()) {
            return false;
        }
        foreach (Actionapps::all() as $aa) {
            if ($aa->upgradeLegacyCredential()) {
                $this->message(_m('Encrypted the token of %1', [safe($aa->getName())]));
            } elseif ($aa->clearCutCredential()) {
                $this->message(_m('Cleared the cut token of %1 - authenticate it again', [safe($aa->getName())]));
            }
        }
        return true;
    }

    /** AA_ADMIN_PWD as wide as the AA schema says. In place when only the width differs - a rebuild of
     *  the table keeps a copy of it, plain tokens and all, in bck_central_conf. */
    protected function widenColumn(): bool
    {
        [$actual, $schema] = $this->passwordColumns();
        if (($actual !== null) && self::sameButWidth($actual, $schema)) {
            return $this->query('ALTER TABLE `central_conf` MODIFY ' . $schema->getCreateSql());
        }
        if (!$this->rebuildTable()) {
            return false;
        }
        $backup = DB_AA::select1('n', "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bck_central_conf'");
        return !$backup || $this->query("UPDATE `bck_central_conf` SET `AA_ADMIN_PWD` = ''");
    }

    /** @return array{?AA_MbC, AA_MbC} the column in the database (null: none) and in the AA schema */
    protected function passwordColumns(): array
    {
        $table = AA_MbT::factoryFromDb('central_conf');
        return [$table->isColumn('AA_ADMIN_PWD') ? $table->getColumn('AA_ADMIN_PWD') : null, AA::Metabase()->tables['central_conf']->getColumn('AA_ADMIN_PWD')];
    }

    /** central_conf as the AA schema says - the way Update database structure does it */
    protected function rebuildTable(): bool
    {
        $update = new UpdateDbStructure();
        $done   = $update->repairTable('central_conf');
        $this->message($update->report());
        return $done;
    }

    private static function sameButWidth(AA_MbC $actual, AA_MbC $schema): bool
    {
        $plain = static fn(AA_MbC $c): array => [strtolower((string) preg_replace('/\(\d+\)/', '', (string) $c->c[1])), (bool) $c->c[2], (string) $c->c[3], (string) $c->c[4], (string) $c->c[5], (string) $c->c[6]];
        return $plain($actual) === $plain($schema);
    }

    /** @param Actionapps[] $aas the central_conf rows @param bool $aesGcm this PHP can encrypt
     *  @param ?int $width of central_conf.AA_ADMIN_PWD, null = unknown @param int $schemaWidth what the AA schema asks for
     *  @return Finding[] */
    public static function assess(array $aas, bool $aesGcm, ?int $width, int $schemaWidth): array
    {
        $rows = [];
        foreach ($aas as $aa) {
            // the name and id only - never the stored value
            $rows[$aa->credentialState()][] = ['id' => (string) $aa->getId(), _m('AA') => safe($aa->getName())];
        }
        $findings = [];
        if ($aas && ($width !== null) && ($width < $schemaWidth)) {
            $findings[] = new Finding(Finding::ERROR, _m('The column central_conf.AA_ADMIN_PWD holds %1 bytes, the encrypted login tokens need %2', [$width, $schemaWidth]), advice: _m('Run Repair - it widens the column. Until then Central stores no login token.'), repairable: true, key: 'column');
        }
        if (!$aesGcm && (count($aas) > count($rows[Actionapps::CREDENTIAL_NONE] ?? []))) {
            $findings[] = new Finding(Finding::ERROR, _m('This PHP has no AES-GCM (sodium) - Central cannot store the logins to its AAs encrypted'), advice: _m('Run PHP with the sodium extension on a CPU with AES instructions.'), key: 'aes-gcm');
        }
        $count    = static fn(string $state): int => count($rows[$state] ?? []);
        $again    = _m('Authenticate the AA again on the ActionApps Central page (Get Token).');
        $problems = [
            'legacy'     => [Actionapps::CREDENTIAL_LEGACY, _m('Central keeps the login tokens of %1 AAs in plain text', [$count(Actionapps::CREDENTIAL_LEGACY)]), _m('Run Repair - it encrypts them (each one is also encrypted the first time Central uses it). A token of older Central is bound to no address: authenticate the AA again to bind its token to its address.'), $aesGcm],
            'cut'        => [Actionapps::CREDENTIAL_CUT, _m('The login tokens of %1 AAs are cut short - the column was too narrow', [$count(Actionapps::CREDENTIAL_CUT)]), _m('Run Repair - it clears them. Then authenticate each AA again on the ActionApps Central page (Get Token).'), true],
            'password'   => [Actionapps::CREDENTIAL_PASSWORD, _m('Central keeps a password of %1 AAs', [$count(Actionapps::CREDENTIAL_PASSWORD)]), _m('Central logs in by a token only. Authenticate each AA on the ActionApps Central page (Get Token) - the token replaces the password.'), false],
            'unreadable' => [Actionapps::CREDENTIAL_UNREADABLE, _m('Central cannot use the login tokens of %1 AAs', [$count(Actionapps::CREDENTIAL_UNREADABLE)]), _m('AA_SECRET changed (without AA_SECRET: DB_PASSWORD changed), or the address of the AA was changed. Check the address. ') . $again, false],
        ];
        foreach ($problems as $key => [$state, $title, $advice, $repairable]) {
            if ($count($state) > 0) {
                $findings[] = new Finding(
                    Finding::WARNING,
                    $title,
                    count: $count($state),
                    evidence: $rows[$state],
                    advice: $advice,
                    links: [_m('ActionApps Central') => AA_INSTAL_PATH . 'central/index.php'],
                    repairable: $repairable,
                    key: $key,
                );
            }
        }
        return $findings ?: [new Finding(Finding::OK, _m('Central keeps no password and no plain token'))];
    }
}
