<?php

declare(strict_types=1);

// The constants the legacy boot needs come with fixtures/object_form_fixtures.php.

namespace {
    // the UI language of the rich editor (Langs::currentLang())
    if (!defined('DEFAULT_LANG_INCLUDE')) {
        define('DEFAULT_LANG_INCLUDE', 'en-utf8_news_lang.php');
    }
}

namespace Tests\Unit\Legacy {

    use AA;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeStore;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * The body of a mail template (Alerts, Bulk e-mails) always opens in the code editor. Edit in Editor
     * (TinyMCE) is offered only where the editor keeps the body: an HTML mail without {...} commands and
     * without table rows or cells outside a table - TinyMCE drops a <tr>/<td> it finds outside <table>.
     */
    final class MailBodyEditorTest extends TestCase
    {
        private const MODULE = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/object_form_fixtures.php';
            require_once dirname(__DIR__, 3) . '/include/mail.php';
            require_once dirname(__DIR__, 3) . '/modules/alerts/alerts_sending.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            AA::$module_id             = self::MODULE;
            $GLOBALS['spareDBs']       = [new RecordingDb()];
            $GLOBALS['permission_uid'] = AuthGetUid();
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::MODULE => PS_FULLTEXT]];
        }

        protected function tearDown(): void
        {
            AA::$module_id       = null;
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        }

        /** @return array<string, array{0: int, 1: string, 2: string}> html, body, the element: aa-richtext = the code view with Edit in Editor, aa-code = the code editor alone */
        public static function bodies(): array
        {
            return [
                'HTML mail'                    => [1, '<p>Dear <b>reader</b>, the news:</p>', 'aa-richtext'],
                'HTML mail with a whole table' => [1, '<table><tr><td>news</td></tr></table>', 'aa-richtext'],
                'template commands'            => [1, '<p>{alerts:news}</p>', 'aa-code'],
                'plain text mail'              => [0, "Dear reader,\nthe news:", 'aa-code'],
                'a nested table'               => [1, '<table><tr><td><table><tr><td>news</td></tr></table></td></tr></table>', 'aa-richtext'],
                'table rows of a template'     => [1, "<tr><td>_#FILTERS_</td></tr>\n<tr><td>news</td></tr>", 'aa-code'],
                'table rows holding a table'   => [1, "  <tr>\r\n <td align=\"left\"><table><tr><td>news</td></tr></table></td></tr>", 'aa-code'],
                'the end of a cell'            => [1, '</td></tr><tr><td>news', 'aa-code'],
                'a cell after a table'         => [1, '<table><tr><td>news</td></tr></table><td>more</td>', 'aa-code'],
                'a cell without its table'     => [1, '<p>news</p><TD>more</TD>', 'aa-code'],
                'a header cell'                => [1, '<th>News</th>', 'aa-code'],
                'a caption'                    => [1, '<caption>News</caption>', 'aa-code'],
                'columns'                      => [1, '<colgroup><col width="50%"></colgroup>', 'aa-code'],
                'not a cell'                   => [1, '<p><track src="a.vtt"><thead-like><link-ish></p>', 'aa-richtext'],
                'an unclosed table'            => [1, '<table><tr><td>_#FILTERS_</td></tr>', 'aa-code'],
                'the end of a table'           => [1, '</table><p>footer</p>', 'aa-code'],
                'a table in a comment'         => [1, '<!-- <table> --><tr><td>news</td></tr>', 'aa-code'],
                'a list'                       => [1, '<ul><li>one</li><li>two<ol><li>a</li></ol></li></ul>', 'aa-richtext'],
                'list items'                   => [1, '<li>news one</li><li>news two</li>', 'aa-code'],
                'a whole page'                 => [1, '<!DOCTYPE html><html><body bgcolor="#eee"><p>news</p></body></html>', 'aa-code'],
                'a body'                       => [1, '<BODY><p>news</p></BODY>', 'aa-code'],
                'the end of a page'            => [1, '<p>footer</p></body></html>', 'aa-code'],
                'an empty comment'             => [1, '<!--><tr><td>news</td></tr><p>-->', 'aa-code'],
            ];
        }

        #[DataProvider('bodies')]
        public function test_the_body_opens_in_the_code_editor_with_the_rich_one_where_it_keeps_it(int $html, string $body, string $editor): void
        {
            self::store($html, $body);

            [$element, , $mode, $link] = self::bodyWidget('7');

            $this->assertSame($editor, $element);
            $this->assertSame(($editor === 'aa-richtext') ? 'code' : '', $mode);
            $this->assertSame($editor === 'aa-richtext', $link);
        }

        public function test_a_new_mail_opens_in_the_code_editor_with_the_rich_one(): void
        {
            self::store(1, '');

            [$element, , $mode, $link] = self::bodyWidget(null);

            $this->assertSame(['aa-richtext', 'code', true], [$element, $mode, $link]);
        }

        /** the rich editor gets the HTML of the body - escaped once for the textarea, not as plain text */
        public function test_the_rich_editor_gets_the_html_of_the_body(): void
        {
            self::store(1, "<p>Dear <b>reader</b> &amp; friend</p>\n<hr>");

            [, $textarea] = self::bodyWidget('7');

            $this->assertSame(safe("<p>Dear <b>reader</b> &amp; friend</p>\n<hr>"), $textarea);
        }

        private static function store(int $html, string $body): void
        {
            FakeStore::install([], [
                'email'  => [['id' => 7, 'owner_module_id' => self::MODULE, 'type' => 'alerts welcome', 'html' => $html, 'subject' => 'News', 'body' => $body, 'lang' => 'en_utf8']],
                'module' => [['id' => self::MODULE, 'type' => 'S', 'deleted' => 0, 'name' => 'Module']],
            ]);
        }

        /** @return array{0: string, 1: string, 2: string, 3: bool} the editor element around the body textarea, the
         *  textarea content, the view <aa-richtext> opens in ('' for <aa-code>) and whether Edit in Editor is there */
        private static function bodyWidget(?string $id): array
        {
            $html = GetMailManaged()->getForm($id, self::MODULE)->getObjectEditHtml();
            if (!preg_match('/<(aa-richtext|aa-code)\b([^>]*)>\s*<textarea\b[^>]*\bid="([^"]*)"[^>]*\bname="[^"]*\[body\]\[0\]"[^>]*>(.*?)<\/textarea>/s', $html, $m)) {
                self::fail("no body editor in the form:\n$html");
            }
            $mode = preg_match('/\bmode="([^"]*)"/', $m[2], $mm) ? $mm[1] : '';
            return [$m[1], $m[4], $mode, str_contains($html, 'data-aa-editor-for="' . $m[3] . '"')];
        }
    }
}
