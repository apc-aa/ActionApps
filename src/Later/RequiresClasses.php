<?php

declare(strict_types=1);

namespace AA\Later;

/** A queued task that loads classes beyond its own when it runs. While one of them is missing here,
 *  Toexecute leaves the task queued for a runner that has it, as for a task of a missing class -
 *  another AA tree over the same database may be newer. */
interface RequiresClasses
{
    /** @return list<string> the class names the task needs */
    public function requiredClasses(): array;
}
