<?php

declare(strict_types=1);

namespace AA\Central\Package;

use RuntimeException;

/** Why the import writer stopped (C4.4 part 4) - the cause inside ImportFailed. $path says where
 *  (a finding path, package text: safe() it, as the message). */
final class WriteError extends RuntimeException
{
    /** a constant group name or an alerts collection id of the plan was taken on the target since the
     *  plan (ImportPlan: no reservation) - plan again */
    public const TAKEN = 'taken';
    /** a text does not convert to its target charset - the plan proved it does: a bug */
    public const CHARSET = 'charset';
    /** a value is longer than its column once the view / e-mail numbers are in (the plan's W MAY_BE_TOO_LONG) */
    public const TOO_LONG = 'too-long';
    /** another import holds the import lock (Importer, GET_LOCK) - nothing was written; try again later */
    public const LOCKED = 'locked';
    /** the database refused a statement (AA_Metabase::do*() returned false) */
    public const DB = 'db';

    public function __construct(public readonly string $reason, public readonly string $path, string $message)
    {
        parent::__construct("$reason at $path: $message");
    }
}
