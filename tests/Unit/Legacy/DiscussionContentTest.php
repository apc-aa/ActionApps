<?php

declare(strict_types=1);

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;
    use zids;

    /**
     * GetDiscussionContent() - a discussion view may print comment bodies as HTML; a comment posted by a
     * visitor through filldisc.php (DISCUS_COMMENT_TEXT) stays text there, older comments keep their HTML.
     */
    final class DiscussionContentTest extends TestCase
    {
        private const VISITOR = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const OLDER   = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
        private const ITEM    = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/discussion.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['apc_state'] = ['router' => ''];   // setDiscUrls() reads the site router from it
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM discussion') ? [
                self::row(self::VISITOR, DISCUS_COMMENT_TEXT),
                self::row(self::OLDER, 0),
            ] : [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['apc_state']);
            RecordingDb::reset();
        }

        private static function row(string $id, int $flag): array
        {
            return ['id' => pack_id($id), 'parent' => '', 'item_id' => pack_id(self::ITEM), 'subject' => 's', 'body' => '<b>x</b>', 'author' => '', 'e_mail' => '',
                'url_address' => '', 'url_description' => '', 'date' => '1790000000', 'remote_addr' => '', 'state' => '0', 'flag' => (string) $flag];
        }

        public function test_html_view_keeps_a_visitor_comment_as_text(): void
        {
            $content = GetDiscussionContent(new zids([self::VISITOR, self::OLDER], 'l'), true, true, 'http://example.org/');

            $this->assertSame(0, (int) ($content[self::VISITOR]['d_body..........'][0]['flag'] ?? 0), 'the visitor comment');
            $this->assertSame(FLAG_HTML, $content[self::OLDER]['d_body..........'][0]['flag'] ?? null, 'an older comment');
        }

        public function test_text_view_shows_every_body_as_text(): void
        {
            $content = GetDiscussionContent(new zids([self::VISITOR, self::OLDER], 'l'), true, false, 'http://example.org/');

            $this->assertArrayNotHasKey('flag', $content[self::OLDER]['d_body..........'][0]);
        }
    }
}
