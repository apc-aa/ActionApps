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

    /** a link to the page only shows the comments; its buttons post the page's token */
    public function test_change_needs_the_form_token(): void
    {
        $gate = self::at('discedit.php', '~if \(in_array\(\$mode, \[\'hide\', \'delete\', \'deleteall\', \'deleteshown\'\], true\)\s*'
            . '&& \(ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\) \|\| !FormToken::checkPost\(DISCEDIT_TOKEN, \$token, \$_SERVER\)\)\) \{[^}]*\$mode = \'\';~');
        foreach (['DB_AA::update\(', 'DeleteNode\(', 'DeleteDiscForItem\(', 'updateDiscussionCount\('] as $use) {
            $this->assertLessThan(self::at('discedit.php', "~$use~"), $gate, "$use must not run before the token check");
        }
        $src = self::source('discedit.php');
        $this->assertStringContainsString('input.value = "\' . FormToken::get(DISCEDIT_TOKEN) . \'";', $src);
        $this->assertStringNotContainsString('window.location', $src);
        $this->assertSame(0, preg_match('~con_url\([^;]*["\']mode["\']~', $src), 'no action link');
        $this->assertSame(4, substr_count($src, 'PostDiscAction("\' . get_url("./discedit.php", [\'mode\' => '), 'each action posts');
    }

    /** the comment is rewritten only from the page's own form; an expired one shows the edited values again */
    public function test_edit_needs_the_form_token(): void
    {
        $gate = self::at('discedit2.php', '~\$foreign = ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\);\s*\$expired = \$update && \(\$foreign \|\| !FormToken::checkPost\(DISCEDIT2_TOKEN, \$token, \$_SERVER\)\);~');
        $this->assertLessThan(self::at('discedit2.php', '~if \(!count\(\$err\) && !\$expired\) \{\s*DB_AA::update\(\'discussion\'~'), $gate);
        // values posted from another site never fill the form - the page would add its token to them
        $this->assertGreaterThan($gate, self::at('discedit2.php', '~if \(\(!\$expired \|\| \$foreign\) && \(\$row = DB_AA::select1\(\[\], \'SELECT \* FROM discussion\'~'));
        $this->assertSame(1, preg_match('~"token"\s*=> \[\'type\' => \'hidden\', \'value\' => FormToken::get\(DISCEDIT2_TOKEN\)\]~', self::source('discedit2.php')), 'the form sends the token');
        // the form shown again sends the item id it came with - $item_id of the expired form is not the packed one of the row
        $this->assertGreaterThan(self::at('discedit2.php', '~DiscussionInSlice\(\$item_id, \$d_id~'), self::at('discedit2.php', '~\$item_id_hex = \$item_id;~'), 'the id is the checked one');
        $this->assertSame(1, preg_match('~"item_id" => \[\'type\' => \'hidden\', \'value\' => \$item_id_hex\]~', self::source('discedit2.php')));
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
