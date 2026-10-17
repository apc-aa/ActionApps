<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize\Fixtures;

use AA\Isds\SliceRules;
use AA\Optimize\CheckIsdsAccounts;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;

/** CheckIsdsAccounts over the constants in $constants and the slice rules in $rules instead of
 *  include/config.php and the database. */
final class ScriptedIsdsAccountsCheck extends CheckIsdsAccounts
{
    /** @var array<string, mixed> as Store::userConstants() */
    public array $constants = [];

    public function __construct(public FakeSliceRules $rules) {}

    protected function constants(): array
    {
        return $this->constants;
    }

    protected function sliceRules(): SliceRules
    {
        return $this->rules;
    }
}
