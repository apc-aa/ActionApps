<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\Passkey\Store;

/** Creates the table of passkeys (authenticator) - until it exists, AA works without passkeys. */
class AddPasskeyTable extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Table of passkeys');
    }

    public static function description(): string
    {
        return _m('Creates the table authenticator, where the passkeys of the accounts are kept. Nothing else changes; without it, nobody can add a passkey.');
    }

    public static function types(): array
    {
        return ['sql_update'];
    }

    /** before UpdateDbStructure (50), which would otherwise create it as one of the missing tables */
    public static function priority(): int
    {
        return 45;
    }

    public static function actions(): array
    {
        return ['test', 'repair'];
    }

    public function test(): bool
    {
        Store::reset();
        if (!(new Store())->available()) {
            $this->message(_m('The table %1 is missing', [Store::TABLE]));
            return false;
        }
        $this->message(_m('OK - the table %1 exists', [Store::TABLE]));
        return true;
    }

    public function repair(): bool
    {
        Store::reset();
        if ((new Store())->available()) {
            $this->message(_m('The table %1 exists already', [Store::TABLE]));
            return true;
        }
        $done = $this->query(AA::Metabase()->getCreateSql(Store::TABLE));
        Store::reset();
        $this->message($done ? _m('The table %1 is created', [Store::TABLE]) : _m('The table %1 could not be created', [Store::TABLE]));
        return $done;
    }
}
