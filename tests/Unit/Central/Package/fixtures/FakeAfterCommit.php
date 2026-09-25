<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\AfterCommit;
use AA\Central\Package\Model\Package;

/** AfterCommit for the writer tests: $calls logs "<method> <argument>" in order, with the number of
 *  statements of TableDb at the call ($at - after the commit?); $probe runs at each call (the lock still
 *  held?); a call named in $fail throws. */
final class FakeAfterCommit implements AfterCommit
{
    /** @var list<string> */
    public array $calls = [];
    /** @var list<int> count(TableDb::$log) at each call */
    public array $at = [];
    /** @var null|\Closure(): void */
    public ?\Closure $probe = null;

    /** @param list<string> $fail calls ("<method> <argument>") that throw */
    public function __construct(public array $fail = []) {}

    public function permissions(string $module, string $user): void
    {
        $this->call("permissions $module $user");
    }

    public function constantsChanged(): void
    {
        $this->call('constantsChanged');
    }

    public function invalidatePagecache(string $module): void
    {
        $this->call("invalidatePagecache $module");
    }

    public function logInstall(Package $package, array $modules): void
    {
        $this->call("logInstall $package->name " . implode(',', $modules));
    }

    private function call(string $call): void
    {
        $this->calls[] = $call;
        $this->at[]    = count(TableDb::$log);
        $this->probe && ($this->probe)();
        if (in_array($call, $this->fail, true)) {
            throw new \RuntimeException("$call failed");
        }
    }
}
