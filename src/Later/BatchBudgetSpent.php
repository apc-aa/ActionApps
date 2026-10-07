<?php

declare(strict_types=1);

namespace AA\Later;

/** A step asked for time its budget no longer has (BatchBudget::httpTimeout()). The core takes it for a
 *  step without progress (NO_PROGRESS) and goes on from the stored position - so a step that hits it
 *  after storing work redoes that work, which is why work() checks spent() and returns first. */
final class BatchBudgetSpent extends \RuntimeException {}
