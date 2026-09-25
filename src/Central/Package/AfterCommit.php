<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;

/** What AA does after an import committed (design balicek-v1.md § 7.2 step 8) - the seam of ImportFinish
 *  to the AA routines that may take a DB handle of their own or clear caches, so none of them runs inside
 *  the transaction (§ 7.2 step 7). AaAfterCommit calls AA; tests use a fake. Each method throws when it
 *  did not do its job - ImportFinish makes that a row of its report. */
interface AfterCommit
{
    /** the rights to a new module: its ACL object - what AA does for a module it creates (AddPermObject()) -
     *  and, unless $user is a superadmin (who reaches every module), the ADMINISTRATOR role of $user on it */
    public function permissions(string $module, string $user): void;

    /** the constants changed - new constant groups (ConstantsChanged()) */
    public function constantsChanged(): void;

    /** the page cache of a new module (AA::Pagecache()->invalidateFor()) */
    public function invalidatePagecache(string $module): void;

    /** the log entry "app installed": the package's name, version and source, one row per new module
     *  (none: one row of AA_ID)
     *  @param list<string> $modules the new module ids */
    public function logInstall(Package $package, array $modules): void;
}
