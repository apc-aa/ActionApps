<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** admin/search_replace.php rewrites a field in many items at once; the page has no testable seam,
 *  so the tests read its source: what it changes must be limited before the change runs. */
final class SearchReplaceTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/admin/search_replace.php');
    }

    /** offset of the first match of $regex in the page */
    private static function at(string $regex): int
    {
        $found = preg_match($regex, self::source(), $m, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $found, "search_replace.php does not match $regex");
        return $m[0][1];
    }

    public function test_picked_items_are_limited_to_the_slice(): void
    {
        $picked = self::at('~getZidsFromGroupSelect\(\$group, \$items, \$searchbar\)~');
        $filter = self::at('~if \(\(string\) \$group === \'sel_item\'\) \{\s*(?://[^\n]*\n\s*)*\$zids = \$zids->itemFilter\(\[AA::\$module_id\]\);~');
        $this->assertGreaterThan($picked, $filter);
        $this->assertLessThan(self::at('~->setZids\(~'), $filter, 'the ids must be filtered before the transformator gets them');
    }
}
