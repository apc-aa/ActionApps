<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** The CSV import may take item ids from a column. Storing a row whose id is an item of another slice
 *  would move that item into the imported slice, so such a row is refused. The page needs a session
 *  and the database, so the test reads its source. */
final class CsvImportForeignIdTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/admin/se_csv_import2.php');
    }

    public function test_row_is_checked_right_before_it_is_stored(): void
    {
        $src = self::source();
        $this->assertSame(1, substr_count($src, '->storeItem('), 'another store of the rows');
        $this->assertMatchesRegularExpression(
            '~if \(!\$err\) \{\s*\$err = \$this->foreignItemError\(\$itemContent\);\s*\}\s*if \(!\$err\) \{\s*\$itemContent->setSliceID\(\$this->slice_id\);\s*\$added_to_db = \$itemContent->storeItem\(~',
            $src,
        );
    }

    public function test_check_looks_up_the_slice_of_the_id(): void
    {
        $this->assertSame(1, preg_match('~private function foreignItemError\(ItemContent \$content\): string\s*\{(.*?)\n    \}\n~s', self::source(), $m), 'no foreignItemError()');
        $body = $m[1];
        // only the modes that never write into an item with the id skip the check
        $this->assertStringContainsString("if (in_array(\$this->actionIfItemExists, ['insert_new', 'insert_if_new', 'insert_as_new'], true)) {\n            return '';", $body);
        $this->assertStringContainsString('if (!is_long_id($id)) {', $body);
        $this->assertStringContainsString("ViewSlices::itemSlicesFromDb('l', [\$id])", $body);
        $this->assertStringContainsString('strcasecmp((string) reset($slices), (string) $this->slice_id)', $body);
    }
}
