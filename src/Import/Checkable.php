<?php

declare(strict_types=1);

namespace AA\Import;

/** A source that can tell whether it is reachable and the keys work, without importing anything */
interface Checkable
{
    public function check(Context $ctx): CheckResult;
}
