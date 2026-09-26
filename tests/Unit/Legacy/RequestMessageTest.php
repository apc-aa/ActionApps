<?php

declare(strict_types=1);

// include/util.php loads locsess.php, which needs the constants include/config.php normally provides.

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

    use AA\Util\InputVars;
    use PHPUnit\Framework\TestCase;

    /**
     * The status message an admin page shows after a redirect comes in the URL (?Msg=...),
     * so anyone can craft it. Pages print it as HTML under the title, therefore it must
     * arrive as plain text and be escaped on the way in.
     */
    final class RequestMessageTest extends TestCase
    {
        private const ROOT = __DIR__ . '/../../..';

        /** directories holding admin pages and their helpers (vendor and tests excluded) */
        private const SCAN = ['admin', 'modules', 'central', 'include', 'src', 'misc'];

        public static function setUpBeforeClass(): void
        {
            require_once self::ROOT . '/include/constants.php';
            require_once self::ROOT . '/include/util.php';
        }

        protected function setUp(): void
        {
            $_GET  = [];
            $_POST = [];
        }

        protected function tearDown(): void
        {
            $_GET  = [];
            $_POST = [];
        }

        public function test_markup_in_the_message_is_escaped(): void
        {
            $_GET['Msg'] = '<script>alert(1)</script>';
            $this->assertSame('<div class="aa-ok">&lt;script&gt;alert(1)&lt;/script&gt;</div>', InputVars::messageGETPOST());
        }

        public function test_attribute_breakout_is_escaped(): void
        {
            $_GET['Msg'] = '"><img src=x onerror=alert(1)>';
            $this->assertStringNotContainsString('<img', InputVars::messageGETPOST());
            $this->assertStringNotContainsString('"', strip_tags(InputVars::messageGETPOST()));
        }

        public function test_plain_text_is_wrapped_as_ok_message(): void
        {
            $_GET['Msg'] = 'Slice successfully deleted, tables are optimized';
            $this->assertSame(MsgOk('Slice successfully deleted, tables are optimized'), InputVars::messageGETPOST());
        }

        public function test_posted_message_is_used_when_not_in_url(): void
        {
            $_POST['Msg'] = 'a < b';
            $this->assertSame(MsgOk('a &lt; b'), InputVars::messageGETPOST());
        }

        public function test_no_message_gives_empty_string(): void
        {
            $this->assertSame('', InputVars::messageGETPOST());
            $_GET['Msg'] = '';
            $this->assertSame('', InputVars::messageGETPOST());
            $_GET['Msg'] = '   ';
            $this->assertSame('', InputVars::messageGETPOST());
        }

        public function test_array_message_is_ignored(): void
        {
            $_GET['Msg'] = ['<b>x</b>'];
            $this->assertSame('', InputVars::messageGETPOST());
        }

        /** An old link still carrying HTML shows the markup as text instead of rendering it. */
        public function test_html_from_an_old_link_is_shown_as_text(): void
        {
            $_GET['Msg'] = MsgOk('Fields update successful');
            $this->assertSame(MsgOk('&lt;div class=&quot;aa-ok&quot;&gt;Fields update successful&lt;/div&gt;'), InputVars::messageGETPOST());
        }

        public function test_no_page_reads_the_raw_request_message(): void
        {
            $raw = [];
            foreach ($this->phpFiles() as $file) {
                if (str_ends_with($file, 'src/Util/InputVars.php')) {
                    continue;    // the helper itself
                }
                foreach (file($file) ?: [] as $i => $line) {
                    if (!$this->isComment($line) && preg_match('/InputVars::\w+\(\s*\\\\?[\'"]Msg\\\\?[\'"]|\$_(?:GET|POST|REQUEST)\[\s*[\'"]Msg[\'"]\s*\]/', $line)) {
                        $raw[] = substr($file, strlen(self::ROOT) + 1) . ':' . ($i + 1);
                    }
                }
            }
            $this->assertSame([], $raw, 'read the status message with InputVars::messageGETPOST(), which escapes it');
        }

        /**
         * Looks at the value put under a Msg/msg URL parameter; when it is a variable, at every
         * earlier assignment to it in the file, so HTML built on a line before the URL is caught too.
         */
        public function test_senders_pass_plain_text(): void
        {
            $html = [];
            foreach ($this->phpFiles() as $file) {
                $lines = file($file) ?: [];
                foreach ($lines as $i => $line) {
                    if ($this->isComment($line) || !preg_match('/(?:[\'"][Mm]sg[\'"]\s*=>|[&?][Mm]sg=)(.*)$/', $line, $m)) {
                        continue;
                    }
                    $value = $m[1];
                    if (!preg_match('/Msg(?:Ok|Err)\(/', $value) && preg_match('/\$(\w+)/', $value, $var)) {
                        $value = '';
                        for ($j = 0; $j < $i; ++$j) {
                            if (!$this->isComment($lines[$j]) && preg_match('/\$' . $var[1] . '\s*\.?=(?!=)(.*)$/', $lines[$j], $as)) {
                                $value .= $as[1];
                            }
                        }
                    }
                    if (preg_match('/Msg(?:Ok|Err)\(/', $value)) {
                        $html[] = substr($file, strlen(self::ROOT) + 1) . ':' . ($i + 1);
                    }
                }
            }
            $this->assertSame([], $html, 'a message in the URL is plain text; the reading page escapes and wraps it');
        }

        private function isComment(string $line): bool
        {
            return (bool) preg_match('#^\s*(?://|\*|/\*)#', $line);
        }

        /** @return list<string> */
        private function phpFiles(): array
        {
            $files = glob(self::ROOT . '/*.php') ?: [];
            foreach (self::SCAN as $dir) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->getExtension() === 'php' && !str_contains($f->getPathname(), '/include/lang/')) {
                        $files[] = $f->getPathname();
                    }
                }
            }
            return $files;
        }
    }
}
