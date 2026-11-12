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

    /** the page refuses (MsgPage* never returns) before the first of its writes
     *  @param list<string> $writes */
    private function assertRefusedBeforeWrites(string $src, string $const, array $writes): void
    {
        $this->assertSame(1, preg_match('~if ' . self::refused($const) . ' \{\s*(?:AA_Adminpageutil::MsgPageMenu|MsgPage)\(~', $src, $m, PREG_OFFSET_CAPTURE), "no $const gate");
        foreach ($writes as $write) {
            $this->assertGreaterThan($m[0][1], (int) strpos($src, $write), "$write before the gate");
        }
    }

    /** the page that sends the form holds the same token */
    private function assertSent(string $file, string $src, string $const): void
    {
        $this->assertSame(1, preg_match('~^const ' . $const . ' = (\'[^\']+\');$~m', $src, $m));
        $sender = self::source($file);
        $this->assertStringContainsString("const $const = {$m[1]};", $sender, "$file has another $const");
        $this->assertMatchesRegularExpression('~FormToken::(?:get|field)\(' . $const . '\)~', $sender, "$file does not send $const");
    }

    public function test_filters_change_only_with_the_token_and_for_an_own_feed(): void
    {
        $src = self::source('admin/se_filters2.php');
        $this->assertToken($src, 'FEEDING_FILTER_TOKEN');
        $this->assertRefusedBeforeWrites($src, 'FEEDING_FILTER_TOKEN', ['DB_AA::delete(', 'doInsertMulti(']);
        $this->assertSent('admin/se_filters.php', $src, 'FEEDING_FILTER_TOKEN');
        // the closing PHP tag eats the line break after it - without the semicolon the next JS statement joins this line
        $this->assertStringContainsString("var url = <?php echo json_encode(StateUrl('se_filters2.php')) ?>;\n", self::source('admin/se_filters.php'));
        $this->assertStringContainsString("if (DB_AA::test('external_feeds', [['feed_id', \$feed_id, 'i'], ['slice_id', AA::\$module_id, 'l']]) && (\$ext_categs = FeedUtil::GetExternalCategories(\$feed_id))) {", $src);
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
