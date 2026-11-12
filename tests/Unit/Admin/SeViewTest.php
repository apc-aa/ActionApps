<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** admin/se_view.php fills a new view from a template view named by its id in the URL; the page has no
 *  testable seam, so the test reads its source: the template must come from a module of the user. */
final class SeViewTest extends TestCase
{
    public function test_template_view_comes_from_a_module_of_the_user(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/se_view.php');
        $found  = preg_match(
            '~\$vw_data = DB_AA::select1\(\'\', \'SELECT \* FROM view\', \[\[\'id\', \$view_view, \'i\'\]\]\);\s*(?://[^\n]*\n\s*)*'
            . 'if \(\$vw_data && !IsSuperadmin\(\) && empty\(\$g_modules\[unpack_id\(\$vw_data\[\'slice_id\'\]\)\]\)\) \{\s*\$vw_data = \[\];~',
            $source,
        );
        $this->assertSame(1, $found, 'the template view is checked right after it is read');
    }
}
