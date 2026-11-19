<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy\Fixtures;

use AA_Module;
use AA_Module_Polls;

/**
 * A module type whose role letters are not slice rights: on it only the superadmin may open a page
 * that is not its own (AA_Module::mayRunPage()). Registered in AA_Module's module cache, so
 * AA_Module::getModule() finds it without a database.
 */
final class OwnPagesModule extends AA_Module_Polls
{
    public static function register(string $module_id): void
    {
        AA_Module::$_modules[$module_id] = new self($module_id);
    }

    public static function forget(string $module_id): void
    {
        unset(AA_Module::$_modules[$module_id]);
    }

    public static function mayRunPage(string $script, string $module_id): bool
    {
        return str_starts_with($script, 'modules/polls/') || IsSuperadmin();
    }
}
