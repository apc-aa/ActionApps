<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Http\ClientInterface;
use AA\Isds\Account;
use AA\Isds\Client;
use AA\Isds\LoginLatch;
use AA\Optimize\CheckIsds;
use Tests\Unit\Fixtures\FakeHttp;
use Tests\Unit\Isds\Fixtures\MemoryLatch;

/** CheckIsds over the constants in $constants, the answers of $http and the refusals in $latch
 *  instead of include/config.php, ISDS and the AA log. Unattended - the daily run - unless told. */
final class ScriptedIsdsCheck extends CheckIsds
{
    /** @var array<string, mixed> as Store::userConstants() */
    public array $constants = [];

    public ClientInterface $http;

    public MemoryLatch $latch;

    public bool $unattended = true;

    public int $now = 1790841600;

    public function __construct()
    {
        $this->http  = new FakeHttp();
        $this->latch = new MemoryLatch();
    }

    protected function constants(): array
    {
        return $this->constants;
    }

    protected function latch(): LoginLatch
    {
        return $this->latch;
    }

    protected function client(Account $account, LoginLatch $latch): Client
    {
        return new Client($account, $this->http, latch: $latch);
    }

    protected function unattended(): bool
    {
        return $this->unattended;
    }

    protected function now(): int
    {
        return $this->now;
    }
}
