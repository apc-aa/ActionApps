<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;

/** What AA does after an import committed - routines that may take a DB handle of their own or clear
 *  caches, so none may run inside the transaction. Each method throws when it did not do its job. */
interface AfterCommit
{
    /** the ACL object of a new module (as AddPermObject()) and, unless $user is a superadmin, the
     *  ADMINISTRATOR role of $user on it */
    public function permissions(string $module, string $user): void;

    /** the constants changed - new constant groups (ConstantsChanged()) */
    public function constantsChanged(): void;

    /** the page cache of a new module (AA::Pagecache()->invalidateFor()) */
    public function invalidatePagecache(string $module): void;

    /** the log entry "app installed": one row per new module (none: one row of AA_ID)
     *  @param list<string> $modules the new module ids */
    public function logInstall(Package $package, array $modules): void;
}
