<?php

declare(strict_types=1);

namespace AA\Central\Package;

use RuntimeException;

/** Why the import writer stopped - the cause inside ImportFailed. $path says where (a finding path, package
 *  text: safe() it, as the message). */
final class WriteError extends RuntimeException
{
    /** a constant group name or an alerts collection id of the plan was taken on the target since the
     *  plan (ImportPlan: no reservation) - plan again */
    public const TAKEN = 'taken';
    /** a text does not convert to its target charset - the plan proved it does: a bug */
    public const CHARSET = 'charset';
    /** a value is longer than its column once the view / e-mail numbers are in (the plan's W MAY_BE_TOO_LONG) */
    public const TOO_LONG = 'too-long';
    /** a table the import writes is not InnoDB (any more - the plan's E Planner::NO_TRANSACTION) - nothing
     *  was written; convert it and plan again */
    public const ENGINE = 'engine';
    /** the user may not install packages (PS_INSTALL_APP) - nothing was written */
    public const DENIED = 'denied';
    /** the plan has S findings and no confirmation token of them came, or another one (ImportConfirmation) -
     *  nothing was written; show the preview again */
    public const UNCONFIRMED = 'unconfirmed';
    /** the install run is not where the write needs it - its definitions written already, ended, forgotten */
    public const RUN = 'run';
    /** a module the run wrote is not on the target any more (deleted since) */
    public const GONE = 'gone';
    /** an item of the id came to the target after the preview (another run of the same items) - the
     *  batch is rolled back; End the run, delete those items or let them be, and install again */
    public const ITEM_ID_EXISTS = 'item-id-exists';
    /** the database refused a statement (AA_Metabase::do*() returned false), or no transaction could be started */
    public const DB = 'db';

    public function __construct(public readonly string $reason, public readonly string $path, string $message)
    {
        parent::__construct("$reason at $path: $message");
    }
}
