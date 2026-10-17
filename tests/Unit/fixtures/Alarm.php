<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

/** Turns a call that blocks - an open of a FIFO - into a failed test instead of a hung run. */
final class Alarm
{
    /**
     * $run, interrupted after $seconds; without pcntl it simply runs
     * @template T
     * @param \Closure(): T $run
     * @return T
     * @throws \LogicException it blocked
     */
    public static function within(int $seconds, \Closure $run): mixed
    {
        if (!function_exists('pcntl_alarm')) {
            return $run();
        }
        $async    = pcntl_async_signals(true);
        $previous = pcntl_signal_get_handler(SIGALRM);
        // no restart: the blocked system call returns, and the handler throws
        pcntl_signal(SIGALRM, static function (): never {
            throw new \LogicException('It blocked');
        }, false);
        $started = time();
        // an alarm already set - PHPUnit's time limit uses one - comes back afterwards
        $pending = pcntl_alarm($seconds);
        try {
            return $run();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);
            pcntl_alarm(($pending > 0) ? max(1, $pending - (time() - $started)) : 0);
            pcntl_async_signals($async);
        }
    }
}
