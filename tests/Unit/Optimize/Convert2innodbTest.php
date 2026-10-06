<?php

declare(strict_types=1);

/**
 * Convert2innodb on State Check: besides the engine finding, a seq field on a slice whose
 * slice/item/content tables are not InnoDB is named - its numbers may repeat.
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

    use AA\Optimize\Convert2innodb;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class Convert2innodbTest extends TestCase
    {
        private const SLICE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

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
            RecordingDb::reset();
        }

        /** @param array<string, string> $engines non-InnoDB table => engine
         *  @param array<string, string> $slices  slice id => name of the slices with a seq field */
        private function database(array $engines, array $slices): void
        {
            RecordingDb::$answer = static function (string $sql) use ($engines, $slices): array {
                if (str_contains($sql, 'information_schema.TABLES')) {
                    $asked = str_contains($sql, "TABLE_NAME IN ('slice','item','content')")
                        ? array_intersect_key($engines, array_flip(['slice', 'item', 'content'])) : $engines;
                    return array_map(static fn($t, $e) => ['TABLE_NAME' => $t, 'ENGINE' => $e], array_keys($asked), $asked);
                }
                if (str_contains($sql, 'FROM `field`, `slice`')) {
                    return array_map(static fn($id, $name) => ['id' => $id, 'name' => $name], array_keys($slices), $slices);
                }
                return [];
            };
        }

        /** @return Finding[] the findings keyed 'seq' */
        private function seqFindings(): array
        {
            return array_values(array_filter((new Convert2innodb())->findings(), static fn(Finding $f) => $f->key === 'seq'));
        }

        public function test_seq_slice_on_myisam_is_named(): void
        {
            $this->database(['content' => 'MyISAM'], [self::SLICE => 'Numbered <b>']);

            [$finding] = $this->seqFindings();
            $this->assertSame(Finding::ERROR, $finding->severity);
            $this->assertSame(1, $finding->count);
            $this->assertSame([['Slice' => 'Numbered &lt;b&gt;', 'ID' => self::SLICE]], $finding->evidence);
            $this->assertStringContainsString('content', $finding->advice);
            $this->assertStringContainsString("field.input_insert_func LIKE 'seq%'", implode("\n", RecordingDb::$log));
        }

        public function test_innodb_tables_ask_no_more(): void
        {
            $this->database(['some_other' => 'MyISAM'], [self::SLICE => 'Numbered']);

            $this->assertSame([], $this->seqFindings());
            $this->assertStringNotContainsString('FROM `field`', implode("\n", RecordingDb::$log));
        }

        public function test_myisam_without_seq_field_adds_nothing(): void
        {
            $this->database(['item' => 'MyISAM'], []);

            $this->assertSame([], $this->seqFindings());
        }
    }
}
