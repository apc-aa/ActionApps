<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** modules/site/modedit.php decides adding or editing once - the right it checks has to match what it
 *  writes, or the right to add a site would overwrite an existing one. The page needs a session, so the
 *  test reads its source. */
final class SiteModeditTest extends TestCase
{
    public function test_add_or_edit_is_decided_once(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/site/modedit.php');
        $this->assertStringContainsString('$is_add      = (bool) $template[\'W\'];', $src);
        foreach ([
            'if ($is_add) {',                                            // the permission check
            'if (!$is_add && ($settings_error = AA_Module_Site::processModuleObject($module_id)))',
            'if ($is_add ? $add : $update) {',                            // the processing gate
            'WriteModuleFields($is_add ? null : ($module_id ?: null),',  // the write target
            'if (!$is_add) {',                                           // site row: update or insert
        ] as $code) {
            $this->assertStringContainsString($code, $src);
        }
        $this->assertDoesNotMatchRegularExpression('~\$add \|\| \$update|\(\$update && \$module_id\) \? \$module_id~', $src);
    }
}
