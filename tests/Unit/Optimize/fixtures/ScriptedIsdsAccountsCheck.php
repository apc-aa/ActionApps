<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Isds\SliceRules;
use AA\Optimize\CheckIsdsAccounts;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;

/** CheckIsdsAccounts over the constants in $constants and the slice rules in $rules instead of
 *  include/config.php and the database, at the time $now, under the PHP SAPI $sapi. */
final class ScriptedIsdsAccountsCheck extends CheckIsdsAccounts
{
    /** @var array<string, mixed> as Store::userConstants() */
    public array $constants = [];

    public int $now = 1790841600;

    public string $sapi = 'cgi-fcgi';

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
}
