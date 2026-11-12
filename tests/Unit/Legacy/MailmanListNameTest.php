<?php

declare(strict_types=1);

namespace {
    foreach ([
        'AA_INSTAL_URL'        => 'http://localhost/aa/',
        'AA_INSTAL_PATH'       => '/aa/',
        'IMG_UPLOAD_PATH'      => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'         => dirname(__DIR__, 3) . '/',
        'PERM_LIB'             => 'sql',
        'DB_HOST'              => 'localhost',
        'DB_NAME'              => 'aa_test',
        'DB_USER'              => 'aa',
        'DB_PASSWORD'          => '',
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy {

    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /** a list name becomes a file name in $MAILMAN_SYNCHRO_DIR and an argument of the Mailman command file */
    final class MailmanListNameTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/mailman.php';
        }

        public static function names(): array
        {
            return [
                'parent dir'  => ['../x', false],
                'space'       => ['a b', false],
                'newline'     => ["a\nb", false],
                'trailing nl' => ["news\n", false],
                'own file'    => ['.mailman', false],
                'dot first'   => ['.x', false],
                'dash first'  => ['-x', false],
                'slash'       => ['a/b', false],
                'empty'       => ['', false],
                'plain'       => ['news-cz.2', true],
                'plus'        => ['my+list', true],
                'capitals'    => ['News', true],
                'equals'      => ['a=b_c', true],
            ];
        }

        #[DataProvider('names')]
        public function test_isListName(string $name, bool $valid): void
        {
            $this->assertSame($valid, \AA_Mailman::isListName($name));
        }

        /** a constant of the list group with a bad name never becomes a file outside the directory */
        public function test_createOneFile_skipsBadName(): void
        {
            $dir = sys_get_temp_dir() . '/aa-mailman-' . bin2hex(random_bytes(4));
            mkdir("$dir/lists", 0o777, true);
            $old = $GLOBALS['MAILMAN_SYNCHRO_DIR'] ?? null;
            $GLOBALS['MAILMAN_SYNCHRO_DIR'] = "$dir/lists/";
            try {
                (new \ReflectionMethod(\AA_Mailman::class, '_createOneFile'))->invoke(null, str_repeat('a', 32), 'category.......1', '../outside');
                $this->assertFileDoesNotExist("$dir/outside");
            } finally {
                $GLOBALS['MAILMAN_SYNCHRO_DIR'] = $old;
                @unlink("$dir/outside");
                @rmdir("$dir/lists");
                @rmdir($dir);
            }
        }
    }
}
