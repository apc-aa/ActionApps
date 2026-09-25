<?php

declare(strict_types=1);

namespace AA\Central\Package;

use RuntimeException;
use Throwable;

/** The import did not complete (Importer). The transaction was rolled back and the compensation removed
 *  what $journal says had been written (MyISAM tables have no transaction); $cleanup is its report - a
 *  step with errors needs a manual action (the report of the import shows it). getPrevious() is the
 *  cause: WriteError (LOCKED: another import runs - nothing was written), RewriteFailed, an
 *  InvalidArgumentException of sitetree::fromArray(), a DB exception, ... The message quotes package
 *  text: safe() it. */
final class ImportFailed extends RuntimeException
{
    public readonly CompensationReport $cleanup;

    public function __construct(public readonly ImportJournal $journal, Throwable $cause, ?CompensationReport $cleanup = null)
    {
        $this->cleanup = $cleanup ?? new CompensationReport();
        $left          = count($this->cleanup->failures());
        parent::__construct('The import failed: ' . $cause->getMessage() . ($left ? " - the cleanup left $left steps for a manual action" : ''), 0, $cause);
    }
}
