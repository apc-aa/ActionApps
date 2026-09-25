<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What the compensation of a failed import (Compensation) did - one row per step, in its order: the
 *  step (module, table, group; package text - safe() it) and its errors. A step with errors needs a
 *  manual action: each error names the table and the condition of the rows left ("view: 2 rows left:
 *  WHERE id IN (1000,1001)") or why it could not tell. clean(): nothing of the import is left (as far
 *  as the checks see). */
final class CompensationReport extends StepReport {}
