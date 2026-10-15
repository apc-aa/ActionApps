<?php

declare(strict_types=1);

// RecordingDb (store_fixtures) loads the real locsess.php, which needs the constants
// include/config.php normally provides.

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

    /**
     * PutSearchLog() - slice.php and discussion.php with ?searchlog=<label>. The label and the
     * Basic-auth user name come from the request, so they must reach SQL as values.
     */
    final class SearchLogTest extends TestCase
    {
        private array $server = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/slice.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->server = $_SERVER;
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->server;
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        public function test_label_and_user_are_values(): void
        {
            $_SERVER['PHP_AUTH_USER'] = "x',0,0,(SELECT password FROM users LIMIT 1))-- ";
            PutSearchLog(addslashes("a'b"), 3);

            $insert = self::insert();
            self::assertStringContainsString("'x\\',0,0,(SELECT password FROM users LIMIT 1))-- '", $insert);
            self::assertStringContainsString("'a\\'b'", $insert, 'the addslashes()ed global is stored as typed');
            self::assertStringContainsString(',3,', $insert);
        }

        public function test_array_label_is_dropped(): void
        {
            unset($_SERVER['PHP_AUTH_USER']);
            PutSearchLog(['x'], 0);

            self::insert();
            self::assertSame('', RecordingDb::inserts('searchlog')[0]['additional1'] ?? null);
        }

        private static function insert(): string
        {
            $rows = array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'INSERT')));
            self::assertCount(1, $rows);

            return $rows[0];
        }
    }
}
