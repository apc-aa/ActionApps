<?php

declare(strict_types=1);

// The store path (item_content → locsess) needs the constants include/config.php
// normally provides. Safe test values, defined before the first legacy class loads.

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

namespace Tests\Unit\IO {

    use AA;
    use AA\IO\Saver;
    use ItemContent;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\IO\Fixtures\ArrayGrabber;
    use Tests\Unit\IO\Fixtures\RecordingSaver;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * Saver::run() over a grabber of fixed items into a fake slice. complete4Insert() and
     * validate4Update() run for real; the slice comes from FakeSlice (no fields), the
     * permission to fill the active bin from the permission cache globals. A RecordingDb
     * handle is the only database: a dry run must leave it untouched.
     *
     * Each test in its own process: ItemContent loads include/locsess.php, whose getDB()
     * collides with the stub tests/Unit/IO/DB loads when that directory runs first.
     */
    #[RunTestsInSeparateProcesses]
    final class SaverTest extends TestCase
    {
        private const SLICE = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';
        private const ITEM1 = '1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a';
        private const ITEM2 = '2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a';
        private const ITEM3 = '3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a';

        private ?string $slice_id_before = null;

        protected function setUp(): void
        {
            // not in setUpBeforeClass(): that one runs in the parent process as well; the
            // warnings are locsess.php missing the web request and config of a real install
            set_error_handler(static fn() => true, E_WARNING | E_DEPRECATED);
            try {
                require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            } finally {
                restore_error_handler();
            }
            require_once __DIR__ . '/fixtures/ArrayGrabber.php';
            require_once __DIR__ . '/fixtures/RecordingSaver.php';
            new FakeSlice(self::SLICE);
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            // anonymous user ('' - no $auth) allowed to edit all items, so complete4Insert()
            // puts the item into the active bin instead of refusing it
            $GLOBALS['permission_uid'] = '';
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => PS_EDIT_ALL_ITEMS]];
            $this->slice_id_before     = AA::$slice_id;
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
            AA::$slice_id = $this->slice_id_before;
            RecordingDb::reset();
        }

        // ------------------------------------------------------------ helpers

        private function item(string $id, array $values = []): ItemContent
        {
            $content = new ItemContent();
            $content->setItemID($id);
            foreach ($values as $field_id => $value) {
                $content->setValue($field_id, $value);
            }
            return $content;
        }

        /** the texts of the messages the saver recorded up to $verbosity */
        private function texts(Saver $saver, int $verbosity = 1): array
        {
            return array_map(static fn(array $row) => (string) ($row['txt'] ?? $row['err'] ?? ''), $saver->reportRows($verbosity));
        }

        // ------------------------------------------------------------ characterization

        public function test_run_prepareExceptionIsSwallowed(): void
        {
            $grabber = new ArrayGrabber([$this->item(self::ITEM1)], new \Exception('source down'));
            $saver   = new Saver($grabber, null, self::SLICE);

            $this->assertSame([0, 1], $saver->run());
            $this->assertSame(['grabber->prepare() Error: source down'], $this->texts($saver));
            $this->assertSame(0, $grabber->taken);
            // today a grabber that failed to prepare is not finished
            $this->assertSame(0, $grabber->finished);
        }

        public function test_run_dryRunPrintsReport(): void
        {
            $grabber = new ArrayGrabber([$this->item(self::ITEM1, ['headline........' => 'First'])]);
            $saver   = new Saver($grabber, null, self::SLICE);

            $this->expectOutputRegex('/<table .*Saver->run\(\): finish - 1 OK, 0 Err\./s');
            $this->assertSame([1, 0], $saver->run(false));
            $this->assertSame([], RecordingDb::$log, 'a dry run writes nothing');
            $this->assertSame([], $saver->changedIds());
            $this->assertSame(1, $grabber->finished);
        }

        public function test_run_defaultStoreModeOverwrite(): void
        {
            $saver = new Saver(new ArrayGrabber([$this->item(self::ITEM1)]), null, self::SLICE);

            ob_start();
            $saver->run(false);
            ob_end_clean();

            $this->assertContains('AA\IO\Saver->run(): id_mode: old, store_mode: overwrite, transformed item: ', $this->texts($saver, 2));
        }

        public function test_run_emptyKeyFieldCountsError(): void
        {
            $grabber = new ArrayGrabber([$this->item(self::ITEM1, ['relation........' => '  '])]);
            $saver   = new Saver($grabber, null, self::SLICE, 'update/insert', '["relation........"]');

            $this->assertSame([0, 1], $saver->run());
            $this->assertContains('AA\IO\Saver->run(): key condition empty for relation........', $this->texts($saver));
            $this->assertSame([], RecordingDb::$log);
            $this->assertSame(1, $grabber->finished);
        }

        // ------------------------------------------------------------ through the seams

        public function test_store_receivesDefaultModeAndNoFlags(): void
        {
            $saver = new RecordingSaver(new ArrayGrabber([$this->item(self::ITEM1)]), null, self::SLICE);

            $this->assertSame([1, 0], $saver->run());
            $this->assertCount(1, $saver->stored);
            $this->assertSame(['id' => self::ITEM1, 'mode' => 'overwrite', 'flags' => []], array_diff_key($saver->stored[0], ['content' => 1]));
            $this->assertSame([self::ITEM1], $saver->updatedIds());
            $this->assertSame([], $saver->newIds());
        }

        public function test_findByKey_foundItemUpdatedOtherInsertedWithNewId(): void
        {
            $grabber = new ArrayGrabber([
                $this->item(self::ITEM1, ['relation........' => 'known']),
                $this->item(self::ITEM2, ['relation........' => 'unknown']),
            ]);
            $saver   = new RecordingSaver($grabber, null, self::SLICE, 'update/insert', '["relation........"]');
            $saver->existing = ['known' => self::ITEM3];

            $this->assertSame([2, 0], $saver->run());
            $this->assertSame([['known'], ['unknown']], $saver->lookups);
            $ids = $saver->storedIds();
            $this->assertSame(self::ITEM3, $ids[0]);
            $this->assertTrue(is_long_id($ids[1]));
            $this->assertNotContains($ids[1], [self::ITEM1, self::ITEM2, self::ITEM3]);
            // outside the import both count as updated - the feed reads it so
            $this->assertSame($ids, $saver->updatedIds());
        }

        public function test_findByKey_foundItemSkippedWhenModeOnlyInserts(): void
        {
            $grabber = new ArrayGrabber([$this->item(self::ITEM1, ['relation........' => 'known'])]);
            $saver   = new RecordingSaver($grabber, null, self::SLICE, 'insert_if_new', '["relation........"]');
            $saver->existing = ['known' => self::ITEM3];

            $this->assertSame([0, 0], $saver->run());
            $this->assertSame([], $saver->stored);
        }

        public function test_store_refusedCountsError(): void
        {
            $saver = new RecordingSaver(new ArrayGrabber([$this->item(self::ITEM1), $this->item(self::ITEM2)]), null, self::SLICE);
            $saver->refuse = [self::ITEM1];

            $this->assertSame([1, 1], $saver->run());
            $this->assertSame([self::ITEM2], $saver->storedIds());
        }

        public function test_run_directFillOfPlainContentThrows(): void
        {
            $saver = new RecordingSaver(new ArrayGrabber([new \AA_Content()]), null, self::SLICE);

            $this->expectException(\UnexpectedValueException::class);
            $saver->run();
        }
    }
}
