<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/** post2shtml.php stores what any visitor sends, for the page it redirects to. The cookies hold the
 *  visitor's session and that page gets them anyway, so they are not stored. The script runs on
 *  include and needs the database, so the test reads its source. */
final class Post2shtmlStoreTest extends TestCase
{
    public function test_cookies_are_not_stored(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/post2shtml.php');
        $this->assertSame(1, preg_match('~function store_vars\(\): void\s*\{(.*?)\n\}~s', $src, $m), 'no store_vars()');
        $this->assertStringNotContainsString('$_COOKIE', $m[1]);
        $this->assertMatchesRegularExpression('~\$vars = \[\s*"post"\s*=> &\$_POST,\s*"get"\s*=> &\$_GET,\s*"files"\s*=> &\$_FILES,\s*\];~', $m[1]);
    }

    /** a row without some part (no cookies in the newer ones) is read without a warning */
    public function test_reading_skips_a_missing_part(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/include/util.php');
        $this->assertStringContainsString('if (is_array($vars[$var_type] ?? null)) {', $src);
    }
}
