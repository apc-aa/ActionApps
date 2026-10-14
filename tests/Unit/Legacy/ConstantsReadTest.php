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

    /**
     * GetConstants() and GetConstantContent() - the reads behind {constants}, {options}, {constant} and the
     * category selects. The group comes from template arguments, so it must reach SQL as a value.
     */
    final class ConstantsReadTest extends TestCase
    {
        /** rows of the constant table as the DB returns them */
        private const ROWS = [
            ['id' => "\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11", 'group_id' => 'colors', 'name' => 'Red', 'value' => 'r', 'class' => 'c1', 'pri' => '100', 'ancestors' => '', 'description' => 'warm', 'short_id' => '7'],
            ['id' => "\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22\x22", 'group_id' => 'colors', 'name' => 'Rose', 'value' => 'r', 'class' => '', 'pri' => '200', 'ancestors' => '0123456789abcdef', 'description' => '', 'short_id' => '8'],
            ['id' => "\x33\x33\x33\x33\x33\x33\x33\x33\x33\x33\x33\x33\x33\x33\x33\x33", 'group_id' => 'colors', 'name' => 'Blue', 'value' => 'b', 'class' => '', 'pri' => '300', 'ancestors' => '', 'description' => '', 'short_id' => '9'],
        ];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/constants.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            \AA::Contentcache()->clear_soft();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM constant') ? self::ROWS : [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            \AA::Contentcache()->clear_soft();
        }

        public function test_values_to_names_with_unique_keys(): void
        {
            // a repeated value gets a space, so no name is lost
            $this->assertSame(['r' => 'Red', 'r ' => 'Rose', 'b' => 'Blue'], GetConstants('colors'));
        }

        public function test_other_columns_and_level(): void
        {
            $this->assertSame(['r' => '7', 'r ' => '8', 'b' => '9'], GetConstants('colors', 'pri', 'short_id'));
            $this->assertSame(['Red' => 0, 'Rose' => 1, 'Blue' => 0], GetConstants('colors', 'pri', 'level', 'name'));
            $this->assertSame(['11111111111111111111111111111111' => 'warm', '22222222222222222222222222222222' => '', '33333333333333333333333333333333' => ''], GetConstants('colors', '', 'description', 'id'));
            $this->assertSame(['r' => 'Red', 'r ' => 'Rose', 'b' => 'Blue'], GetConstants('colors', 'pri', 'unknown', 'unknown'), 'unknown columns fall back to value => name');
        }

        public function test_the_query_orders_by_a_known_column_only(): void
        {
            GetConstants('colors', 'const_pri');
            GetConstants('colors', 'name; DROP', 'value');

            [$ordered, $plain] = array_values(array_filter(RecordingDb::$log, static fn($sql) => str_contains($sql, 'FROM constant')));
            $this->assertStringEndsWith('ORDER BY pri', $ordered);
            $this->assertStringNotContainsString('ORDER BY', $plain);
            $this->assertStringNotContainsString('DROP', $plain);
        }

        public function test_the_group_reaches_sql_as_a_value(): void
        {
            GetConstants("x' UNION SELECT login, password FROM users -- ");

            $sql = implode("\n", RecordingDb::$log);
            $this->assertStringNotContainsString("'x' UNION", $sql);
            $this->assertStringContainsString("x\\' UNION", $sql);
        }

        public function test_one_read_per_request(): void
        {
            GetConstants('colors');
            GetConstants('colors');

            $this->assertCount(1, array_filter(RecordingDb::$log, static fn($sql) => str_contains($sql, 'FROM constant')));
        }

        public function test_constant_content(): void
        {
            $content = GetConstantContent(new \zids([7, 8], 's'));

            $this->assertSame([['value' => 'Red']], $content['7']['const_name']);
            $this->assertSame([['value' => 'r', 'flag' => FLAG_HTML]], $content['7']['const_value']);
            $this->assertSame([['value' => '11111111111111111111111111111111']], $content['7']['const_id']);
            $this->assertSame([['value' => 1]], $content['8']['const_level']);
            $this->assertSame([2], $content['8']['const_counter']);
            $this->assertSame([['value' => 'colors']], $content['9']['const_group']);
        }

        public function test_constant_content_asks_for_the_short_ids(): void
        {
            GetConstantContent(new \zids([7, 8], 's'));
            GetConstantContent(new \zids(['9'], 's'));

            $sqls = array_map(static fn($sql) => preg_replace('/\s+/', ' ', trim($sql)), RecordingDb::$log);
            $this->assertSame(["SELECT * FROM constant WHERE short_id IN ('7','8')", "SELECT * FROM constant WHERE short_id = '9'"], $sqls);
        }
    }
}
