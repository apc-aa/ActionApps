<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA;
use AA\Central\Package\Model\Package;
use AA_Log;
use AA_Logentry_App;
use AA_Perm;
use LogicException;
use RuntimeException;

/** AfterCommit on this AA.
 *
 *  Rights: AA gives a module it creates (AA_Module / modutils.php, slicedit.php) its ACL object -
 *  AddPermObject($id, 'slice'), "no special permission added - only superuser can access"; the import does
 *  the same. A superadmin (SUPER on the `aa` object) reaches every module (AA_Perm::joinSliceAndAAPerm()).
 *  An importer below SUPER - PS_INSTALL_APP ("U") granted directly - gets the ADMINISTRATOR role on each
 *  new module (AddPerm(), as admin/se_users.php), or (s)he could not open what (s)he installed. Whether
 *  (s)he is a superadmin tells IsSuperadmin() - of the logged-in user only; another user (CLI) gets the
 *  role. With perm_sql.php AddPermObject() is a no-op, with LDAP it creates the object. None of it is ever
 *  compensated: it runs after the commit only. AddPerm() writes through CVarset without nohalt - a DB error
 *  there ends the request (the import stays; the later steps are missed).
 *
 *  The log entry: the rows AA_Log::write() makes (time, user = AuthGetUid() - the logged-in user, type,
 *  selector, params), inserted here with nohalt - AA_Log::write() halts (die()) on a DB error and reports
 *  true otherwise, so it cannot tell a failed insert; here that is an exception (a row of the report). */
final class AaAfterCommit implements AfterCommit
{
    /** log.type of the entry (AA_Log::TYPE_MAX) */
    public const EVENT = 'APP_INST';

    public function permissions(string $module, string $user): void
    {
        if (!function_exists('AddPermObject') || !function_exists('AddPerm')) {
            throw new LogicException('No permission library loaded - AddPermObject() did not run');
        }
        if (!\AddPermObject($module, 'slice')) {
            throw new RuntimeException("AddPermObject($module) failed");
        }
        if (($user === '') || (($user === \AuthGetUid()) && \IsSuperadmin())) {
            return;
        }
        if (!\AddPerm($user, $module, 'slice', AA_Perm::ROLES['ADMINISTRATOR']['id'])) {
            throw new RuntimeException("AddPerm($user, $module, ADMINISTRATOR) failed");
        }
        AA::$perm->invalidateCache();           // the new role in the session's cached perms, as se_users.php
    }

    public function constantsChanged(): void
    {
        if (!function_exists('ConstantsChanged')) {
            throw new LogicException('include/util.php not loaded - ConstantsChanged() did not run');
        }
        \ConstantsChanged();
    }

    public function invalidatePagecache(string $module): void
    {
        AA::Pagecache()->invalidateFor($module);
    }

    public function logInstall(Package $package, array $modules): void
    {
        if (!AA_Log::isLogable(self::EVENT)) {
            throw new RuntimeException(self::EVENT . ' is not logged on this AA ($DO_NOT_LOG)');
        }
        $params = AA_Logentry_App::make($package->name, (string) $package->version, (string) ($package->source['url'] ?? ''))->serializeToString();
        foreach ($modules ?: [(string) constant('AA_ID')] as $selector) {
            $row = ['time' => (string) time(), 'user' => \AuthGetUid(), 'type' => self::EVENT, 'selector' => $selector, 'params' => $params];
            if (AA::Metabase()->doInsert('log', $row, 'nohalt') === false) {
                throw new RuntimeException("INSERT INTO log failed ($selector)");
            }
        }
    }
}
