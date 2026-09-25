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
 *  Rights: as AA does for a module it creates (modutils.php, slicedit.php), AddPermObject($id, 'slice') -
 *  a superadmin reaches every module. An importer below SUPER (PS_INSTALL_APP granted directly) also gets
 *  the ADMINISTRATOR role on each new module, or could not open what (s)he installed; IsSuperadmin() knows
 *  the logged-in user only, so another user (CLI) gets the role. Never undone: it runs after the commit.
 *  AddPerm() writes without nohalt - a DB error there ends the request (the import stays).
 *
 *  The log entry: the rows AA_Log::write() makes, inserted with nohalt - AA_Log::write() die()s on a DB
 *  error, here a failed insert is an exception (a row of the report). */
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
