<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The settings page of a module type edits only a module of that type - the permission letters mean
 *  other things in other types. The pages need a session, so the tests read their source. */
final class ModeditTypeCheckTest extends TestCase
{
    /** @return array<string, array{string, string}>  page, module class */
    public static function pages(): array
    {
        return [
            'alerts' => ['modules/alerts/modedit.php', 'AA_Module_Alerts'],
            'polls'  => ['modules/polls/modedit.php', 'AA_Module_Polls'],
            'links'  => ['modules/links/modedit.php', 'AA_Module_Links'],
            'site'   => ['modules/site/modedit.php', 'AA_Module_Site'],
        ];
    }

    #[DataProvider('pages')]
    public function test_edit_checks_the_module_type_before_the_permission(string $file, string $class): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        $this->assertSame(1, preg_match(
            '~\} elseif \(\$module_id && !' . $class . '::getOwnModule\(\$module_id\)\) \{[^\n]*\n\s*MsgPage\(_m\("Module not found\."\)\);\s*exit;\s*\} elseif \(!(?:IfSlPerm|CheckPerms)\(~',
            $src,
        ), "$file: the edit branch does not check the module type");
    }
}
