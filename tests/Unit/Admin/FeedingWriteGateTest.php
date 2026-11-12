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

    /** a link only shows the Run (or Test) button, which posts the token to the same address; only Source reads */
    public function test_feed_run_is_queued_only_with_the_token(): void
    {
        $src = self::source('admin/xmlclient.php');
        $this->assertToken($src, 'FEED_RUN_TOKEN');
        $this->assertSame(1, preg_match('~if \(\(\$fire !== \'display\'\) && ' . self::refused('FEED_RUN_TOKEN') . '\) \{\s*MsgPage\(\'<form method="post">\' \. FormToken::field\(FEED_RUN_TOKEN\)~', $src, $m, PREG_OFFSET_CAPTURE), 'no FEED_RUN_TOKEN gate');
        $this->assertGreaterThan($m[0][1], (int) strpos($src, '->feed($fire)'));
    }

    public function test_inter_import_is_created_only_with_the_token(): void
    {
        $src = self::source('admin/se_inter_import3.php');
        $this->assertToken($src, 'INTER_IMPORT_TOKEN');
        $this->assertRefusedBeforeWrites($src, 'INTER_IMPORT_TOKEN', ['doInsert(', 'doReplace(', 'doInsertMulti(']);
        $this->assertSent('admin/se_inter_import2.php', $src, 'INTER_IMPORT_TOKEN');
    }

    /** Preview and Load only show the form; Save and Finish write */
    public function test_csv_import_saves_and_stores_only_with_the_token(): void
    {
        $src = self::source('admin/se_csv_import2.php');
        $this->assertToken($src, 'CSV_IMPORT_TOKEN');
        $this->assertSame(1, substr_count($src, 'FormToken::field(CSV_IMPORT_TOKEN)'));
        $this->assertStringContainsString('$may_write = !ResponderPolicy::isForeignBrowserRequest($_SERVER) && FormToken::checkPost(CSV_IMPORT_TOKEN, $token, $_SERVER);', $src);
        $this->assertSame(1, substr_count($src, 'doInsertMulti('));
        $this->assertMatchesRegularExpression('~if \(\$save && \$may_write\) \{\s*\$obj_id = new_id\(\);\s*AA::Metabase\(\)->doInsertMulti\(~', $src);
        $this->assertSame(1, substr_count($src, '$importer->upload()'));
        $this->assertMatchesRegularExpression('~if \(\$upload && \$may_write\) \{\s*\$importer->upload\(\);~', $src);
    }

    public function test_imports_and_exports_change_only_with_the_token(): void
    {
        $src = self::source('admin/se_import2.php');
        $this->assertToken($src, 'FEEDING_TOKEN');
        $this->assertRefusedBeforeWrites($src, 'FEEDING_TOKEN', ['doUpdate(', 'doInsertMulti(', '->query(']);
        $this->assertSent('admin/se_import.php', $src, 'FEEDING_TOKEN');
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
