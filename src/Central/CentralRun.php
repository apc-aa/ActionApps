<?php

declare(strict_types=1);

namespace AA\Central;

use AA;
use AA\Later\BatchFailed;
use AA\Later\BatchRun;

/** A batch run of Central (InstallRun, OptimizeRun): a run of this AA (its subject is AA_ID), started by a
 *  superadmin and stopped (DENIED) once the user is none any more; its steps ask other AAs, which get
 *  RETRY seconds to recover after a failure. */
abstract class CentralRun extends BatchRun
{
    /** the user who started the run is no superadmin any more - Continue once the rights are back */
    public const DENIED = 'DENIED';

    /** seconds before a failed AA is asked again: after a step that still moved on, after the first and
     *  the second failure in a row (the third goes to onRetriesExhausted()) - each run sets its own */
    protected const RETRY = [60, 300, 900];

    /** the user who started the run */
    public function user(): string
    {
        return (string) $this->user;
    }

    /** the runs of this AA */
    protected static function subject(): string
    {
        return strtolower((string) constant('AA_ID'));
    }

    /** read from the perm storage, not by IsSuperadmin(): a step runs in the queue or in a request of another user */
    protected static function isSuperadmin(string $uid): bool
    {
        class_exists(\AA_Perm::class);           // include/perm_core.php sets AA::$perm - a queue step may not have loaded it
        return ($uid !== '') && AA::$perm->isSuperadminUser($uid);
    }

    /** @throws BatchFailed DENIED when the user who started the run is no superadmin any more */
    protected function requireSuperadmin(): void
    {
        if (!static::isSuperadmin($this->user())) {
            throw new BatchFailed(self::DENIED, 'user');
        }
    }
}
