<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** A written import (Importer): the complete map - the plan's with the view, e-mail and filter numbers
 *  AUTO_INCREMENT gave -, the journal of what was created and the report (§ 7.2 step 9) with the steps
 *  after the commit. Package text inside: safe(). */
final readonly class ImportResult
{
    public function __construct(
        public IdMap $map,
        public ImportJournal $journal,
        public ImportReport $report,
    ) {}
}
