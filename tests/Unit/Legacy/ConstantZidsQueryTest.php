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
     * QueryConstantZIDs() - the constant view (view.php, the group is the view parameter) and the
     * constant selects of widgets. The group must reach SQL as a value.
     */
    final class ConstantZidsQueryTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/searchlib.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            \AA::Contentcache()->clear_soft();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM constant') ? [['short_id' => '7']] : [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            \AA::Contentcache()->clear_soft();
        }

        public function test_group_is_a_value(): void
        {
            $zids = QueryConstantZIDs("x' OR '1'='1", []);

            $selects = array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_contains($sql, 'FROM constant')));
            $this->assertCount(1, $selects);
            $this->assertStringContainsString("group_id = 'x\\' OR \\'1\\'=\\'1'", $selects[0]);
            $this->assertStringContainsString('ORDER BY', $selects[0], 'the default sort stays');
            $this->assertSame(['7'], $zids->shortids());
        }
    }
}
