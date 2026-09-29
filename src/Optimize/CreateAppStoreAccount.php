<?php

namespace AA\Optimize;

use AA;
use AA\Central\AppStore;

/** The public account of the app store (AA\Central\AppStore): an AA user of the public login and password,
 *  without any rights. Run it on the AA that should be a store - other AAs log in to it with the account. */
class CreateAppStoreAccount extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('App store account');
    }

    public static function description(): string
    {
        return _m('Creates the public account other AAs read the published apps of this AA with (user %1, no rights). Its password is public; the account cannot log in to the administration.', [AppStore::PUBLIC_USER]);
    }

    public static function types(): array
    {
        return ['opt_feature'];
    }

    public function test(): bool
    {
        if (!AppStore::sqlBackend()) {
            $this->message(self::noSql());
            return false;
        }
        if (AA::$perm->isUsernameFree(AppStore::PUBLIC_USER)) {
            $this->message(_m('No app store account - this AA publishes no apps'));
            return false;
        }
        $uid = AA::$perm->authenticateUsername(AppStore::PUBLIC_USER, AppStore::PUBLIC_PASSWORD);
        if (!$uid || !AppStore::isPublic((string) $uid)) {
            $this->message(_m('The user %1 exists, but not with the public password of the app store - rename it, then repair', [AppStore::PUBLIC_USER]));
            return false;
        }
        // rights would make the public password a key to more than the published apps
        if (AA::$perm->getUserPerms($uid, 'aa') || AA::$perm->getUserPerms($uid, 'slice') || AA::$perm->getMembership($uid)) {
            $this->message(_m('The app store account %1 has rights or groups - remove them', [AppStore::PUBLIC_USER]));
            return false;
        }
        $this->message(_m('The app store account is ready'));
        return true;
    }

    /** a superadmin only - the account opens this AA's published apps to anyone */
    public function repair(): bool
    {
        if (!IsSuperadmin()) {
            $this->message(_m('Only a superadmin may create the app store account'));
            return false;
        }
        if (!AppStore::sqlBackend()) {
            $this->message(self::noSql());
            return false;
        }
        if (!AA::$perm->isUsernameFree(AppStore::PUBLIC_USER)) {
            return $this->test();
        }
        $uid = AddUser(['uid' => AppStore::PUBLIC_USER, 'userpassword' => AppStore::PUBLIC_PASSWORD, 'givenname' => 'App', 'sn' => 'store', 'mail' => '']);
        if (!$uid) {
            $this->message(_m('The app store account could not be created'));
            return false;
        }
        $this->message(_m('The app store account %1 is created', [AppStore::PUBLIC_USER]));
        return true;
    }

    /** in LDAP the name may be anyone's and the password is not this AA's to set */
    private static function noSql(): string
    {
        return _m('The app store account needs the users of AA itself (PERM_LIB sql); this AA uses %1 - it can install from a store, not be one', [defined('PERM_LIB') ? (string) constant('PERM_LIB') : '?']);
    }
}
