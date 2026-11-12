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

    /** the select leaves out the fields that place the item (slice_id, id, short_id) */
    public function test_only_an_offered_field_is_changed(): void
    {
        $offered = self::at('~\$field_select\[\$fld_id\] = ~');
        $check   = self::at('~if \(!isset\(\$field_select\[\$field_id\]\)\) \{[^}]*break;~');
        $this->assertLessThan($check, $offered, 'the offered fields must be known before the check');
        $this->assertLessThan(self::at('~new AA_Transformator\(~'), $check);
    }

    /** the first POST from the Item Manager only opens the page; the change runs from its own form */
    public function test_change_needs_the_form_token(): void
    {
        $gate = self::at('~if \(ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\) \|\| !FormToken::checkPost\(SEARCH_REPLACE_TOKEN, \$token, \$_SERVER\)\) \{[^}]*break;~');
        $this->assertGreaterThan(self::at('~\} else \{\s*\$items\s*= \$r_sr_state\[\'items\'\];~'), $gate, 'the gate belongs to the fill branch');
        $this->assertLessThan(self::at('~new AA_Transformator\(~'), $gate);
        $this->assertLessThan(self::at('~AA_Log::write\(~'), $gate);
        $form = self::at('~<form name="modifyitemsform" method="post">\';\s*echo FormToken::field\(SEARCH_REPLACE_TOKEN\);~');
        $this->assertGreaterThan($gate, $form);
    }
}
