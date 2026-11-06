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

    use AA\Optimize\ItemDiscussion;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** Repair of discussion comments whose item is gone - the report must say what it did */
    final class ItemDiscussionTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';    // the legacy boot, no DB
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [];
        }

        /** @return list<string> */
        private static function deletes(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => str_starts_with(ltrim($sql), 'DELETE')));
        }

        public function test_repair_nothingToRepairDeletesNothing(): void
        {
            RecordingDb::$answer = static fn(string $sql): array => [];
            $optimizer = new ItemDiscussion();

            self::assertTrue($optimizer->repair());
            self::assertSame([], self::deletes());
            self::assertStringContainsString('No problems found', implode("\n", array_column($optimizer->reportRows(1), 'txt')));
        }

        public function test_repair_deletesCommentsOfTheMissingItems(): void
        {
            $gone = [hex2bin(str_repeat('ab', 16)), hex2bin(str_repeat('cd', 16))];
            RecordingDb::$answer = static fn(string $sql): array => str_starts_with($sql, 'SELECT')
                ? array_map(static fn(string $id): array => ['disc_count' => '3', 'item_id' => $id], $gone)
                : [];
            $optimizer = new ItemDiscussion();

            self::assertTrue($optimizer->repair());
            $deletes = self::deletes();
            self::assertCount(2, $deletes);
            self::assertStringContainsString('0x' . str_repeat('ab', 16), $deletes[0]);
            self::assertStringContainsString('0x' . str_repeat('cd', 16), $deletes[0]);
            self::assertStringContainsString("item_id = ''", $deletes[1]);
            self::assertStringContainsString('2 problems solved', implode("\n", array_column($optimizer->reportRows(1), 'txt')));
        }
    }
}
