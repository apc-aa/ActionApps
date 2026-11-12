<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** admin/itemedit.php has no testable seam, so the test reads its source */
final class ItemeditTest extends TestCase
{
    /** an "add" link names a slice by an item id; the form of the new item - the AJAX calls too - gets none of that item */
    public function test_add_formIsEvaluatedOverAnEmptyItem(): void
    {
        $src     = (string) file_get_contents(dirname(__DIR__, 3) . '/admin/itemedit.php');
        $slice   = strpos($src, '$slice_id   = $content4id->getSliceID();');
        $emptied = preg_match('~if \(\$add\) \{\s*\$content4id = new ItemContent\(\);~', $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        $ajax    = strpos($src, '$content_function = static function () use (&$content4id');

        $this->assertNotFalse($slice);
        $this->assertNotFalse($emptied, 'the add mode empties the content');
        $this->assertNotFalse($ajax);
        $this->assertGreaterThan($slice, $emptied, 'the item names the slice first');
        $this->assertLessThan($ajax, $emptied, 'emptied before the AJAX calls evaluate the form');
    }
}
