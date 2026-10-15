<?php

declare(strict_types=1);

/**
 * AddContentFieldIndex turns the text index into (text, field_id) and drops (field_id, text) once,
 * in one ALTER - on a big database it takes minutes, so a second run must not repeat it.
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

        /** the live content table: whether the text index has field_id and whether field_text exists */
        private function indexes(bool $text_has_field, bool $field_first): void
        {
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, "'field_id'")   => [['cnt' => (int) $text_has_field]],
                str_contains($sql, "'field_text'") => [['cnt' => (int) $field_first]],
                str_contains($sql, "'text'")       => [['cnt' => 1]],
                default                            => [],
            };
        }

        private static function alters(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'ALTER')));
        }

        public function test_repair_plainTextIndex_addsTheFieldInOneAlter(): void
        {
            $this->indexes(false, false);

            self::assertFalse((new AddContentFieldIndex())->test());
            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame(['ALTER TABLE `content` DROP INDEX `text`, ADD KEY text (`text`(12),`field_id`)'], self::alters());
        }

        public function test_repair_fieldFirstIndex_replacedByTheTextIndex(): void
        {
            $this->indexes(false, true);

            self::assertFalse((new AddContentFieldIndex())->test());
            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame(['ALTER TABLE `content` DROP INDEX `field_text`, DROP INDEX `text`, ADD KEY text (`text`(12),`field_id`)'], self::alters());
        }

        public function test_repair_done_altersNothing(): void
        {
            $this->indexes(true, false);

            self::assertTrue((new AddContentFieldIndex())->test());
            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame([], self::alters());
        }

        public function test_repair_justPrint_altersNothing(): void
        {
            // sql_update.php without "Write to database" only lists the SQL
            $this->indexes(false, true);
            AbstractOptimize::justPrint(true);

            self::assertTrue((new AddContentFieldIndex())->repair());
            self::assertSame([], self::alters());
        }

        public function test_revert_backToThePlainTextIndex(): void
        {
            $this->indexes(true, false);

            (new AddContentFieldIndex())->revert();
            self::assertSame(['ALTER TABLE `content` DROP INDEX `text`, ADD KEY `text` (`text`(12))'], self::alters());
        }

        public function test_priority_runsBeforeUpdateDbStructure(): void
        {
            // the structure update would otherwise copy the whole content table to add the index
            self::assertLessThan(UpdateDbStructure::priority(), AddContentFieldIndex::priority());
        }
    }
}
