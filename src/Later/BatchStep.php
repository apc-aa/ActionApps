<?php

declare(strict_types=1);

namespace AA\Later;

use AA_Logentry;
use AA_Logentry_Obj;

/** One queued step of a batch run. The row holds only the run's class, its id and the fingerprint of
 *  the step it was queued for - never the run or its source, so a stale row cannot carry old state
 *  (or a secret) into the run, and the run's record stays the one truth. */
final class BatchStep implements LaterInterface
{
    /** the run whose step is working now; plain request state, never serialized */
    private static ?BatchRun $running = null;

    public function __construct(
        public readonly string $class,
        public readonly string $run,
        public readonly string $expected,
    ) {}

    public function toexecutelater($params = []): string
    {
        return BatchRun::runStep($this->class, $this->run, $this->expected);
    }

    public function logSelector(): AA_Logentry
    {
        $run = is_subclass_of($this->class, BatchRun::class) ? $this->class::get($this->run) : null;
        return AA_Logentry_Obj::make($run ? $run->selector() : 'BatchStep', $this->run);
    }

    /** The run whose step is working right now - code reached from the step (an expression in a
     *  plan task, an item event) may refuse to start another run inside it. */
    public static function running(): ?BatchRun
    {
        return self::$running;
    }

    /** runs $work with $run as running() - only BatchRun calls it
     *  @internal
     *  @template T
     *  @param \Closure(): T $work
     *  @return T */
    public static function during(BatchRun $run, \Closure $work): mixed
    {
        $outer         = self::$running;
        self::$running = $run;
        try {
            return $work();
        } finally {
            self::$running = $outer;
        }
    }
}
