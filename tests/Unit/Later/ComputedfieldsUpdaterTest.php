<?php

declare(strict_types=1);

// include/item_content.php needs the constants include/config.php normally provides

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
        'DEFAULT_LANG_INCLUDE' => 'en-utf8_news_lang.php',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Later {

    use AA\Later\ComputedfieldsUpdater;
    use AA_Field;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** A changed slice setting field plans the recompute of the items of the slice. */
    final class ComputedfieldsUpdaterTest extends TestCase
    {
        private const SLICE = '33333333333333333333333333333333';

        private static FakeSlice $slice;

        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__) . '/Legacy/fixtures/store_fixtures.php';
            self::$slice = new FakeSlice(self::SLICE);
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['err']      = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
        }

        private function field(string $insert): AA_Field
        {
            return new AA_Field([
                'id'                => 'text...........1',
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld',
                'input_insert_func' => $insert,
            ]);
        }

        public function test_planItems_recomputes_only_a_slice_with_computed_fields(): void
        {
            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field('qte')]));
            ComputedfieldsUpdater::planItems(self::SLICE);
            $this->assertSame(0, RecordingDb::count('INSERT'), 'nothing to recompute');

            self::$slice->setFields(new FakeFields(self::SLICE, [$this->field('co2:x{headline........}:x{headline........}::')]));
            ComputedfieldsUpdater::planItems(self::SLICE);
            $inserts = array_values(array_filter(RecordingDb::$log, static fn($sql) => str_starts_with($sql, 'INSERT')));
            $this->assertCount(1, $inserts, implode("\n", RecordingDb::$log));
            $this->assertStringContainsString('ComputedfieldsUpdater_now_' . self::SLICE, $inserts[0]);
        }

        public function test_toexecutelater_replaces_the_waiting_chunks_of_the_slice(): void
        {
            $ids = ['44444444444444444444444444444444', '55555555555555555555555555555555'];
            // the item ids of the slice; the queue has no task yet
            RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'FROM item')
                ? array_map(static fn($id) => ['id' => pack_id($id)], $ids)
                : [];

            foreach (['slice_info', 'vid', 'view_info'] as $global) {   // the item query still reads them
                $GLOBALS[$global] ??= null;
            }

            (new ComputedfieldsUpdater())->toexecutelater([self::SLICE]);

            $deletes = array_values(array_filter(RecordingDb::$log, static fn($sql) => str_starts_with($sql, 'DELETE')));
            $this->assertCount(1, $deletes, implode("\n", RecordingDb::$log));
            $this->assertStringContainsString("selector LIKE 'AA\\_ComputedfieldsItem\\_Updater\\_" . self::SLICE . "\\_%'", $deletes[0]);
            $this->assertSame(1, RecordingDb::count('INSERT'), 'one chunk of both items');
        }
    }
}
