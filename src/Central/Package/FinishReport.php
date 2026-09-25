<?php

declare(strict_types=1);

namespace AA\Central\Package;

/** What the finishing of a committed import (ImportFinish, design balicek-v1.md § 7.2 step 8) did - one
 *  row per step: `log`, `permissions[<module>]`, `constants`, `pagecache[<module>]`, and its errors. A
 *  step with errors did not run to its end; the import stays - the report says what to do by hand
 *  (clear the caches, give the rights). */
final class FinishReport extends StepReport {}
