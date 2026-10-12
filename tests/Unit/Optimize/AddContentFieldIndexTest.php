<?php

declare(strict_types=1);

/**
 * AddContentFieldIndex adds the (field_id, text) index once and only when it is missing - on a
 * big database the ALTER takes minutes, so a second run must not repeat it.
 */

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

    use AA\Optimize\AbstractOptimize;
    use AA\Optimize\AddContentFieldIndex;
    use AA\Optimize\UpdateDbStructure;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class AddContentFieldIndexTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            AbstractOptimize::justPrint(false);
            RecordingDb::reset();
        }

        private function indexExists(bool $exists): void
        {
            RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'information_schema.statistics') && str_contains($sql, "'field_text'")
                ? [['cnt' => (int) $exists]] : [];
        }

        private static function alters(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'ALTER')));
        }

        public function test_repair_missingIndex_addsItOnce(): void
        {
            $this->indexExists(false);

            self::assertFalse((new AddContentFieldIndex())->test());
            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame(['ALTER TABLE `content` ADD KEY field_text (`field_id`,`text`(12))'], self::alters());
        }

        public function test_repair_existingIndex_altersNothing(): void
        {
            $this->indexExists(true);

            self::assertTrue((new AddContentFieldIndex())->test());
            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame([], self::alters());
        }

        public function test_repair_justPrint_altersNothing(): void
        {
            // sql_update.php without "Write to database" only lists the SQL
            $this->indexExists(false);
            AbstractOptimize::justPrint(true);

            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame([], self::alters());
        }

        public function test_revert_dropsTheIndex(): void
        {
            $this->indexExists(true);

            (new AddContentFieldIndex())->revert();
            self::assertSame(['ALTER TABLE `content` DROP INDEX `field_text`'], self::alters());
        }

        public function test_priority_runsBeforeUpdateDbStructure(): void
        {
            // the structure update would otherwise copy the whole content table to add the index
            self::assertLessThan(UpdateDbStructure::priority(), AddContentFieldIndex::priority());
        }
    }
}
