<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Isds\SliceRules;
use AA\Optimize\CheckIsdsAccounts;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;

/** CheckIsdsAccounts over the constants in $constants, the slice rules in $rules and the log rows in
 *  $pages instead of include/config.php and the database, at the time $now, under the PHP SAPI $sapi. */
final class ScriptedIsdsAccountsCheck extends CheckIsdsAccounts
{
    /** @var array<string, mixed> as Store::userConstants() */
    public array $constants = [];

    public int $now = 1790841600;

    public string $sapi = 'cgi-fcgi';

    /** @var list<array<string, ?string>> LogOnce::recent() rows of the pages not ready */
    public array $pages = [];

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

    protected function notReadyPages(): array
    {
        return $this->pages;
    }
}
