<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** admin/discedit.php and discedit2.php show, hide, delete and rewrite comments named by ids in the URL;
 *  the pages have no testable seam, so the tests read their source: DiscussionInSlice() runs before them. */
final class DisceditTest extends TestCase
{
    private static function source(string $page): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . "/admin/$page");
    }

    /** offset of the first match of $regex in the page */
    private static function at(string $page, string $regex): int
    {
        $found = preg_match($regex, self::source($page), $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $found, "$page does not match $regex");
        return $m[0][1];
    }

    public function test_list_works_only_on_an_item_of_the_slice(): void
    {
        $item    = self::at('discedit.php', '~if \(!DiscussionInSlice\(\$item_id, \'\', AA::\$module_id\)\) \{[^}]*exit;~');
        $comment = self::at('discedit.php', '~if \(\(\$d_id !== \'\'\) && !DiscussionInSlice\(\$item_id, \$d_id, AA::\$module_id\)\) \{\s*\$mode = \'\';~');
        $this->assertGreaterThan(self::at('discedit.php', '~IfSlPerm\(PS_EDIT_ALL_ITEMS\)~'), $item);
        foreach (['QueryDiscussionZIDs\(', 'DB_AA::update\(', 'DeleteNode\(', 'DeleteDiscForItem\(', 'updateDiscussionCount\('] as $use) {
            $this->assertLessThan(self::at('discedit.php', "~$use~"), $item, "$use must not run before the check");
            $this->assertLessThan(self::at('discedit.php', "~$use~"), $comment, "$use must not run before the check");
        }
    }

    public function test_edit_works_only_on_a_comment_of_an_item_of_the_slice(): void
    {
        $check = self::at('discedit2.php', '~if \(!DiscussionInSlice\(\$item_id, \$d_id, AA::\$module_id\)\) \{[^}]*exit;~');
        $this->assertGreaterThan(self::at('discedit2.php', '~require_once __DIR__ \. "/\.\./include/discussion\.php";~'), $check);
        foreach (['DB_AA::update\(\'discussion\'', 'SELECT \* FROM discussion'] as $use) {
            $this->assertLessThan(self::at('discedit2.php', "~$use~"), $check, "$use must not run before the check");
        }
    }
}
