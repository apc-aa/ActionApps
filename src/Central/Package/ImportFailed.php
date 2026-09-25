<?php

declare(strict_types=1);

namespace AA\Central\Package;

use RuntimeException;
use Throwable;

/** The import did not complete (Importer) and nothing of it was written: it was refused before any
 *  statement, or its transaction was rolled back. getPrevious() is the cause: WriteError (DENIED,
 *  UNCONFIRMED, TAKEN, DB, ...), RewriteFailed, an InvalidArgumentException of sitetree::fromArray(), a DB
 *  exception, ... The message quotes package text: safe() it. */
final class ImportFailed extends RuntimeException
{
    public function __construct(Throwable $cause)
    {
        parent::__construct('The import failed, nothing was written: ' . $cause->getMessage(), 0, $cause);
    }
}
