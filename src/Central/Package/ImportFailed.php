<?php

declare(strict_types=1);

namespace AA\Central\Package;

use RuntimeException;
use Throwable;

/** The import did not complete (Importer). The transaction was rolled back; $journal says what had been
 *  written before - on MyISAM tables (no transaction) it is still there until the compensation
 *  (C4.4 part 4b) removes it. getPrevious() is the cause: WriteError, RewriteFailed, an
 *  InvalidArgumentException of sitetree::fromArray(), a DB exception, ... The message quotes package
 *  text: safe() it. */
final class ImportFailed extends RuntimeException
{
    public function __construct(public readonly ImportJournal $journal, Throwable $cause)
    {
        parent::__construct('The import failed: ' . $cause->getMessage(), 0, $cause);
    }
}
