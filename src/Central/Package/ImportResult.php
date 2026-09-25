<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** A written import (Importer): the complete map - the plan's with the view, e-mail and filter numbers
 *  AUTO_INCREMENT gave - and the journal of what was created (the report, part 5). */
final readonly class ImportResult
{
    public function __construct(
        public IdMap $map,
        public ImportJournal $journal,
    ) {}
}
