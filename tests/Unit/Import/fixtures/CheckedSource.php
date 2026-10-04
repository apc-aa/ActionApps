<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA\Import\Checkable;
use AA\Import\CheckResult;
use AA\Import\Context;
use AA\IO\DB\DB_AA;

require_once __DIR__ . '/ScriptedSource.php';

/** A ScriptedSource that answers check() as the test sets it, and records whether a transaction was
 *  open and the seconds left in the budget of the newest Context */
final class CheckedSource extends ScriptedSource implements Checkable
{
    /** the answer, or what check() throws */
    public CheckResult|\Exception $check;

    /** @var list<array{tx: bool, left: ?float}> the check() calls */
    public array $checkCalls = [];

    public function __construct()
    {
        $this->check = new CheckResult(true, '', '');
    }

    public function check(Context $ctx): CheckResult
    {
        $budget             = end(TestRun::$contextBudgets);
        $this->checkCalls[] = ['tx' => DB_AA::inTransaction(), 'left' => $budget ? $budget->secondsLeft() : null];
        if ($this->check instanceof \Exception) {
            throw $this->check;
        }
        return $this->check;
    }
}
