<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Isds\SliceRules;
use AA\Optimize\CheckIsdsAccounts;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;

/** CheckIsdsAccounts over the constants in $constants, the slice rules in $rules and the log rows in
 *  $pages and $campaigns instead of include/config.php and the database, at the time $now, under the PHP
 *  SAPI $sapi, with /proc/self/fd listed as $kernelPaths says. */
final class ScriptedIsdsAccountsCheck extends CheckIsdsAccounts
{
    /** @var array<string, mixed> as Store::userConstants() */
    public array $constants = [];

    public int $now = 1790841600;

    public string $sapi = 'cgi-fcgi';

    public bool $kernelPaths = true;

    /** @var list<array<string, ?string>> LogOnce::recent() rows of the pages not ready */
    public array $pages = [];

    /** @var list<array<string, ?string>> LogOnce::recent() rows of the campaigns whose recipients were not told */
    public array $campaigns = [];

    public function __construct(public FakeSliceRules $rules) {}

    protected function constants(): array
    {
        return $this->constants;
    }

    protected function sliceRules(): SliceRules
    {
        return $this->rules;
    }

    protected function now(): int
    {
        return $this->now;
    }

    protected function sapi(): string
    {
        return $this->sapi;
    }

    protected function kernelPaths(): bool
    {
        return $this->kernelPaths;
    }

    protected function notReadyPages(): array
    {
        return $this->pages;
    }

    protected function unresolvedCampaigns(): array
    {
        return $this->campaigns;
    }
}
