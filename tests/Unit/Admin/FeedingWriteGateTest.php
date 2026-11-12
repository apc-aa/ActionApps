<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

/** The feeding and import settings change what flows into a slice, so each change may run only from
 *  the page's own form: a POST carrying its token, not sent by a page of another origin. The pages need
 *  a session and the database, so the tests read their source. */
final class FeedingWriteGateTest extends TestCase
{
    private static function source(string $file): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $file);
    }

    /** the condition refusing a request without the token or from another origin */
    private static function refused(string $const): string
    {
        return '\(ResponderPolicy::isForeignBrowserRequest\(\$_SERVER\) \|\| !FormToken::checkPost\(' . $const . ', \$token, \$_SERVER\)\)';
    }

    private function assertToken(string $src, string $const): void
    {
        $this->assertMatchesRegularExpression('~^const ' . $const . ' = \'aa-[a-z-]+\';$~m', $src, "no $const");
    }

    public function test_inter_import_deletes_only_an_own_feed_with_the_token(): void
    {
        $src = self::source('admin/se_inter_import.php');
        $this->assertToken($src, 'INTER_IMPORT_TOKEN');
        $this->assertStringContainsString('FormToken::get(INTER_IMPORT_TOKEN)', $src);
        $this->assertStringNotContainsString('->query(', $src);
        $this->assertSame(3, substr_count($src, 'DB_AA::delete('));
        $this->assertMatchesRegularExpression(
            '~if ' . self::refused('INTER_IMPORT_TOKEN') . ' \{\s*\$Msg = MsgErr\([^\n]*\);\s*'
            . '\} elseif \(\$feed = DB_AA::select1\(\[\], \'SELECT remote_slice_id FROM external_feeds\', \[\[\'feed_id\', \$feed_id, \'i\'\], \[\'slice_id\', AA::\$module_id, \'l\'\]\]\)\) \{\s*'
            . 'DB_AA::transaction\(static function \(\) use \(\$feed, \$feed_id\): void \{(\s*DB_AA::delete\([^\n]*\);){3}\s*\}\);~',
            $src,
        );
    }
}
