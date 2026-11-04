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

namespace Tests\Unit\Optimize {

    use AA\Optimize\AddPasskeyTable;
    use AA\Passkey\Store;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class AddPasskeyTableTest extends TestCase
    {
        private bool $table = false;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            Store::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->table = false;
            RecordingDb::$answer = fn(string $sql): array => (str_contains($sql, 'information_schema.tables') && $this->table) ? [['table_schema' => 'aa_test']] : [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            Store::reset();
        }

        public function test_repair_missingTable_created(): void
        {
            $optimizer = new AddPasskeyTable();
            $this->assertFalse($optimizer->test());

            $this->assertTrue($optimizer->repair());
            $this->assertSame(1, RecordingDb::count('CREATE TABLE'));
            $this->assertStringContainsString('`authenticator`', implode("\n", RecordingDb::$log));
        }

        public function test_repair_tableExists_nothingDone(): void
        {
            $this->table = true;
            $optimizer   = new AddPasskeyTable();

            $this->assertTrue($optimizer->test());
            $this->assertTrue($optimizer->repair());
            $this->assertSame(0, RecordingDb::count('CREATE'));
        }
    }
}
