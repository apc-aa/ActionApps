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
    use AA\IO\SourceRefused;
    use AA\IO\SourceUnavailable;
    use AA\Later\BatchBudget;
    use AA_Field;
    use AA_Value;
    use ItemContent;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\IO\Fixtures\ArrayGrabber;
    use Tests\Unit\IO\Fixtures\RecordingSaver;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
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

        private FakeSlice $slice;

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
            $this->slice = new FakeSlice(self::SLICE);
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

        /** @return list<ItemContent> items ITEM1, ITEM2, ... */
        private function items(int $count): array
        {
            return array_map(fn(string $id) => $this->item($id), array_slice([self::ITEM1, self::ITEM2, self::ITEM3], 0, $count));
        }

        /** a clock reading $time as the test advances it */
        private function clock(float &$time): \Closure
        {
            return static function () use (&$time): float {
                return $time;
            };
        }

        /** @return list<ItemContent> one item per key value, ids 000…1, 000…2, ... */
        private function keyed(array $relations): array
        {
            $items = [];
            foreach (array_values($relations) as $i => $relation) {
                $items[] = $this->item(sprintf('%032x', $i + 1), ['relation........' => $relation]);
            }
            return $items;
        }

        private function field(string $id, array $data = []): AA_Field
        {
            return new AA_Field($data + [
                'id'                => $id,
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld',
                'input_insert_func' => 'qte',
                'input_validate'    => '',
                'multiple'          => 0,
                'content_edit'      => 0,
                'slice_id'          => pack_id(self::SLICE),
            ]);
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

        /** @return array<string, array{string, list<int>}> validation output => [ok, err] of one item */
        public static function validationRows(): array
        {
            return [
                'one'                 => ['1', [1, 0]],
                'empty'               => ['', [1, 0]],
                'rows of one + blank' => ["  \n1 - x\n\n1", [1, 0]],
                'text'                => ['no', [0, 1]],
                'one bad row'         => ["1\nno", [0, 1]],
                'starts with 0'       => ['01', [0, 1]],
                'bare 0'              => ['0', [0, 1]],
            ];
        }

        #[DataProvider('validationRows')]
        public function test_addValidate_rowsOutsideImport(string $output, array $expected): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->items(1)), null, self::SLICE, 'update'))->addValidate($output);

            $this->assertSame($expected, $saver->run());
        }

        /** @return array<string, array{array<string, string>, string}> item values => the error text ('' passes) */
        public static function nszmValidations(): array
        {
            $good = ['relation........' => self::ITEM3, 'publish_date....' => '1700000000', 'number..........' => '0'];
            return [
                'all good, value 0' => [$good, ''],
                'no town'           => [['relation........' => 'Praha'] + $good, 'mesto nenalezno'],
                'no date'           => [['publish_date....' => 'x'] + $good, 'chyba datumu'],
                'no value'          => [['number..........' => ''] + $good, 'neni hodnota'],
                'hidden value'      => [['number..........' => 'Hidden'] + $good, 'hidden hodnota'],
            ];
        }

        #[DataProvider('nszmValidations')]
        public function test_addValidate_nszmLoadersFailWithText(array $values, string $error): void
        {
            // the expressions of the NSZM indicator loaders: on error they print text, never a bare 0
            $this->slice->setFields(new FakeFields(self::SLICE, array_map(fn(string $id) => $this->field($id), array_keys($values))));
            $item = $this->item(self::ITEM1);
            foreach ($values as $field_id => $value) {
                $item->setAaValue($field_id, new AA_Value($value));   // with its flag, as a grabber fills it
            }
            $saver = (new RecordingSaver(new ArrayGrabber([$item]), null, self::SLICE, 'update'))
                ->addValidate('{iftype:{relation........}:longid:1 - _#1:mesto nenalezno}')
                ->addValidate('{iftype:{publish_date....}:positive:1 - _#1:chyba datumu}')
                ->addValidate('{ifset:{number..........}:1 - _#1:neni hodnota}')
                ->addValidate('{ifin:{number..........}:idden:hidden hodnota:1 - _#1}');

            $this->assertSame(($error === '') ? [1, 0] : [0, 1], $saver->run());
            if ($error !== '') {
                $this->assertContains("AA\\IO\\Saver->run(): validate failed: $error", $this->texts($saver));
            }
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

        // ------------------------------------------------------------ deadline and limit

        public function test_run_stopsAtDeadline_reachedEndFalse(): void
        {
            $time    = 0.0;
            $grabber = new ArrayGrabber($this->items(3), null, static function () use (&$time): void {
                $time += 1.0;   // each item takes a second
            });
            $saver   = new RecordingSaver($grabber, null, self::SLICE);
            $budget  = new BatchBudget(1.5, 0, 0, $this->clock($time));

            $this->assertSame([2, 0], $saver->run(true, $budget));
            $this->assertSame([self::ITEM1, self::ITEM2], $saver->storedIds());
            $this->assertSame(2, $grabber->taken, 'the item after the deadline is not even taken');
            $this->assertFalse($saver->reachedEnd());
        }

        public function test_run_stopsAtLimit(): void
        {
            $grabber = new ArrayGrabber($this->items(3));
            $saver   = new RecordingSaver($grabber, null, self::SLICE);

            $this->assertSame([2, 0], $saver->run(true, null, 2));
            $this->assertSame([self::ITEM1, self::ITEM2], $saver->storedIds());
            $this->assertSame(2, $grabber->taken);
            $this->assertFalse($saver->reachedEnd());
        }

        public function test_run_limitCountsItemsTakenNotItemsStored(): void
        {
            $items   = $this->items(3);
            $items[0]->setValue('relation........', '');   // empty key - an error, not stored
            $items[1]->setValue('relation........', 'b');
            $items[2]->setValue('relation........', 'c');
            $grabber = new ArrayGrabber($items);
            $saver   = new RecordingSaver($grabber, null, self::SLICE, 'update/insert', '["relation........"]');

            $this->assertSame([1, 1], $saver->run(true, null, 2));
            $this->assertSame(2, $grabber->taken);
            $this->assertFalse($saver->reachedEnd());
        }

        public function test_run_withoutDeadline_reachedEndTrue(): void
        {
            $grabber = new ArrayGrabber($this->items(3));
            $saver   = new RecordingSaver($grabber, null, self::SLICE);

            $this->assertSame([3, 0], $saver->run());
            $this->assertTrue($saver->reachedEnd());

            // a deadline and a limit not reached leave it so
            $grabber = new ArrayGrabber($this->items(3));
            $saver   = new RecordingSaver($grabber, null, self::SLICE);
            $this->assertSame([3, 0], $saver->run(true, new BatchBudget(microtime(true) + 600), 4));
            $this->assertTrue($saver->reachedEnd());
        }

        public function test_run_secondRunResetsReachedEnd(): void
        {
            $saver = new RecordingSaver(new ArrayGrabber($this->items(2)), null, self::SLICE);

            $this->assertSame([2, 0], $saver->run());
            $this->assertTrue($saver->reachedEnd());

            $this->assertSame([1, 0], $saver->run(true, null, 1));
            $this->assertFalse($saver->reachedEnd());
        }

        public function test_run_limitHitOnLastItem_reachedEndFalse(): void
        {
            // the grabber is not asked once more past the limit, so the end is not known yet
            $saver = new RecordingSaver(new ArrayGrabber($this->items(2)), null, self::SLICE);

            $this->assertSame([2, 0], $saver->run(true, null, 2));
            $this->assertFalse($saver->reachedEnd());
        }

        public function test_run_finishCalledWhenStoppedEarly(): void
        {
            $time    = 0.0;
            $grabber = new ArrayGrabber($this->items(3), null, static function () use (&$time): void {
                $time += 1.0;
            });
            $saver   = new RecordingSaver($grabber, null, self::SLICE);

            $this->assertSame([1, 0], $saver->run(true, new BatchBudget(0.5, 0, 0, $this->clock($time))));
            $this->assertSame(1, $grabber->finished);
            $this->assertFalse($saver->reachedEnd());

            $grabber = new ArrayGrabber($this->items(3));
            (new RecordingSaver($grabber, null, self::SLICE))->run(true, null, 1);
            $this->assertSame(1, $grabber->finished);
        }

        public function test_run_budgetSpentBeforePrepare_fetchesNothing(): void
        {
            $grabber = new ArrayGrabber($this->items(2));
            $saver   = new RecordingSaver($grabber, null, self::SLICE);

            $this->assertSame([0, 0], $saver->run(true, new BatchBudget(microtime(true) - 1)));
            $this->assertSame(0, $grabber->prepared, 'nothing is downloaded');
            $this->assertSame(0, $grabber->taken);
            $this->assertSame(0, $grabber->finished);
            $this->assertFalse($saver->reachedEnd());
        }

        public function test_run_prepareFailed_reachedEndFalse(): void
        {
            $saver = new RecordingSaver(new ArrayGrabber($this->items(1), new \Exception('source down')), null, self::SLICE);

            $this->assertSame([0, 1], $saver->run());
            $this->assertFalse($saver->reachedEnd());
        }

        public function test_run_dryRunStoppedEarlyStillPrintsReport(): void
        {
            $saver = new RecordingSaver(new ArrayGrabber($this->items(3)), null, self::SLICE);

            $this->expectOutputRegex('/Saver->run\(\): finish - 1 OK, 0 Err\./');
            $this->assertSame([1, 0], $saver->run(false, null, 1));
            $this->assertSame([], $saver->stored);
        }

        // ------------------------------------------------------------ import mode

        public function test_importMode_prepareExceptionPropagates(): void
        {
            foreach ([new SourceUnavailable('TRANSPORT', 'down'), new SourceRefused('AUTH', 'no'), new \Exception('bad')] as $failure) {
                $saver = (new RecordingSaver(new ArrayGrabber($this->items(1), $failure), null, self::SLICE))->setImportMode();
                try {
                    $saver->run();
                    $this->fail('expected ' . $failure::class);
                } catch (\Exception $e) {
                    $this->assertSame($failure, $e);
                }
                $this->assertSame([], $saver->errors());
                $this->assertSame(0, $saver->counts()['errors']);
                $this->assertFalse($saver->reachedEnd());
            }
        }

        public function test_importMode_noReportPrinted(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->items(2)), null, self::SLICE))->setImportMode();

            $this->expectOutputString('');
            $this->assertSame([2, 0], $saver->run(false));
            $this->assertSame([], $saver->stored);
            $this->assertSame([], RecordingDb::$log);
        }

        public function test_nonImportMode_unchanged(): void
        {
            // a transport failure is swallowed with its text, the store gets no flags
            $saver = new RecordingSaver(new ArrayGrabber($this->items(1), new SourceUnavailable('TRANSPORT', 'down')), null, self::SLICE);
            $this->assertSame([0, 1], $saver->run());
            $this->assertSame(['grabber->prepare() Error: down'], $this->texts($saver));

            $saver = new RecordingSaver(new ArrayGrabber($this->keyed(['known', 'unknown'])), null, self::SLICE, 'update/insert', '["relation........"]');
            $saver->existing = ['known' => self::ITEM3];
            $this->assertSame([2, 0], $saver->run());
            $this->assertSame([[], []], array_column($saver->stored, 'flags'));
            $this->assertSame([], $saver->newIds());
            $this->assertCount(2, $saver->updatedIds());
        }

        public function test_importMode_finishMessageWithoutLastError(): void
        {
            // the last store error may quote values of the source
            ItemContent::lastErr(1, 'SECRET from the source');
            $import = (new RecordingSaver(new ArrayGrabber($this->items(1)), null, self::SLICE))->setImportMode();
            $import->run();
            $this->assertSame(['AA\IO\Saver->run(): finish - 1 OK, 0 Err.'], $this->texts($import));

            ItemContent::lastErr(1, 'SECRET from the source');
            $plain = new RecordingSaver(new ArrayGrabber($this->items(1)), null, self::SLICE);
            $plain->run();
            $this->assertStringContainsString('SECRET', implode('', $this->texts($plain)), 'outside an import as ever');
        }

        public function test_importMode_countsNewVsUpdatedByKey(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed(['known', 'unknown', ''])), null, self::SLICE, 'update/insert', '["relation........"]'))->setImportMode();
            $saver->existing = ['known' => self::ITEM3];

            $this->assertSame([2, 1], $saver->run());
            $this->assertSame(['new' => 1, 'updated' => 1, 'skipped' => 0, 'errors' => 1], $saver->counts());
            $this->assertSame([self::ITEM3], $saver->updatedIds());
            $this->assertCount(1, $saver->newIds());
            $this->assertNotSame(self::ITEM3, $saver->newIds()[0]);
        }

        public function test_importMode_dryRunCountsToo(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed(['known', 'unknown'])), null, self::SLICE, 'update/insert', '["relation........"]'))->setImportMode();
            $saver->existing = ['known' => self::ITEM3];

            $this->assertSame([2, 0], $saver->run(false));
            $this->assertSame(['new' => 1, 'updated' => 1, 'skipped' => 0, 'errors' => 0], $saver->counts());
            $this->assertSame([], $saver->changedIds(), 'nothing was stored');
        }

        public function test_counts_skippedByStoreMode(): void
        {
            // found, but the mode only inserts
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed(['known', 'unknown'])), null, self::SLICE, 'insert_if_new', '["relation........"]'))->setImportMode();
            $saver->existing = ['known' => self::ITEM3];
            $this->assertSame([1, 0], $saver->run());
            $this->assertSame(['new' => 1, 'updated' => 0, 'skipped' => 1, 'errors' => 0], $saver->counts());

            // not found, but the mode only updates
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed(['known', 'unknown'])), null, self::SLICE, 'update', '["relation........"]'))->setImportMode();
            $saver->existing = ['known' => self::ITEM3];
            $this->assertSame([1, 0], $saver->run());
            $this->assertSame(['new' => 0, 'updated' => 1, 'skipped' => 1, 'errors' => 0], $saver->counts());
        }

        public function test_clearIds_resetsCountsErrorsAndSamples(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed(['a', ''])), null, self::SLICE, 'update/insert', '["relation........"]'))->setImportMode();
            $saver->run(false);
            $this->assertNotSame([], $saver->errors());
            $this->assertNotSame([], $saver->samples());

            $saver->clear_ids();
            $this->assertSame(['new' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0], $saver->counts());
            $this->assertSame([], $saver->errors());
            $this->assertSame([], $saver->samples());
        }

        public function test_collisions_itemsRepeatingAKeyBeforeThem(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed(['a', 'b', 'a', '', 'a', ''])), null, self::SLICE, 'update/insert', '["relation........"]'))->setImportMode();

            $saver->run(false);

            $this->assertSame(2, $saver->collisions(), 'the second and the third a; an empty key is no key');
            $saver->clear_ids();
            $this->assertSame(0, $saver->collisions());
            $saver->run(false);
            $this->assertSame(2, $saver->collisions(), 'clear_ids() forgets the keys too - else all four would repeat');
        }

        public function test_collisions_keyOfTwoFieldsComparedAsAWhole(): void
        {
            $items = [
                $this->item(self::ITEM1, ['relation........' => 'a|b', 'text...........1' => 'c']),
                $this->item(self::ITEM2, ['relation........' => 'a', 'text...........1' => 'b|c']),
                $this->item(self::ITEM3, ['relation........' => 'a', 'text...........1' => 'b|c']),
                $this->item(sprintf('%032x', 4), ['relation........' => 'a', 'text...........1' => 'x']),
            ];
            $saver = (new RecordingSaver(new ArrayGrabber($items), null, self::SLICE, 'update/insert', '["relation........","text...........1"]'))->setImportMode();

            $saver->run(false);

            $this->assertSame(1, $saver->collisions());
        }

        public function test_collisions_onlyInImportMode(): void
        {
            $saver = new RecordingSaver(new ArrayGrabber($this->keyed(['a', 'a'])), null, self::SLICE, 'update/insert', '["relation........"]');

            $saver->run();

            $this->assertSame(0, $saver->collisions());
        }

        public function test_setContext_paramsVisibleInValidationOnly(): void
        {
            // 'update' stores the item as it is - nothing (unlike complete4Insert()) would drop the parameter
            $make = fn() => (new RecordingSaver(new ArrayGrabber($this->items(1)), null, self::SLICE, 'update'))
                ->setImportMode()
                ->addValidate('{ifeq:{index:geo}:CZ:1:0}');

            $saver = $make()->setContext(['geo' => 'CZ']);
            $this->assertSame([1, 0], $saver->run());
            $this->assertArrayNotHasKey('geo', $saver->stored[0]['content']->getContent(), 'the parameter is not stored');

            $saver = $make();
            $this->assertSame([0, 1], $saver->run());
            $this->assertSame(['VALIDATE'], array_column($saver->errors(), 'code'));
        }

        public function test_setContext_skipsFieldsOfTargetSlice(): void
        {
            $this->slice->setFields(new FakeFields(self::SLICE, [$this->field('headline........')]));
            $saver = (new RecordingSaver(new ArrayGrabber([$this->item(self::ITEM1, ['headline........' => 'stored'])]), null, self::SLICE, 'update'))
                ->setImportMode()
                ->addValidate('{ifeq:{index:headline........}:stored:1:no}')
                ->setContext(['headline........' => 'from the job', 'empty' => []]);

            $this->assertSame([1, 0], $saver->run(), 'the validation checks the value that is stored');
            $this->assertSame('stored', $saver->stored[0]['content']->getValue('headline........'));
        }

        public function test_errors_structuredAndCapped(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed(array_fill(0, 21, ''))), null, self::SLICE, 'update/insert', '["relation........"]'))->setImportMode();

            $this->assertSame([0, 21], $saver->run());
            $this->assertCount(Saver::ERRORS_KEPT, $saver->errors(100));
            $this->assertCount(3, $saver->errors(3));
            $this->assertSame(['code' => 'EMPTY_KEY', 'msg' => 'Item 1 (relation........=): key field relation........ is empty'], $saver->errors()[0]);
            $this->assertSame(21, $saver->counts()['errors']);
        }

        public function test_errors_keyValuesShortenedNoValidationOutput(): void
        {
            $key   = "k\x07" . str_repeat('é', 100);
            $items = [
                $this->item(self::ITEM1, ['relation........' => $key, 'headline........' => 'SECRET value from the source']),
                $this->item(self::ITEM2, ['relation........' => 'b']),
            ];
            $saver = (new RecordingSaver(new ArrayGrabber($items), null, self::SLICE, 'update/insert', '["relation........"]'))
                ->setImportMode()
                ->addValidate('{index:headline........}');   // the validation output is source data
            $saver->existing = ['b' => self::ITEM3];
            $saver->refuse   = [self::ITEM3];

            $this->assertSame([0, 2], $saver->run());
            $errors = $saver->errors();
            $this->assertSame(['VALIDATE', 'STORE'], array_column($errors, 'code'));
            $this->assertSame('Item 1 (relation........=k ' . str_repeat('é', 38) . '): the item failed the validation', $errors[0]['msg']);
            $this->assertSame('Item 2 (relation........=b): the item was not stored (failed, or a skipped duplicate)', $errors[1]['msg']);
            foreach ($errors as $error) {
                $this->assertSame(['code', 'msg'], array_keys($error));
                $this->assertStringNotContainsString('SECRET', $error['msg']);
            }
            $this->assertStringNotContainsString('SECRET', $saver->report(2, 'plain'), 'nor in the report of an import');
        }

        public function test_setRedact_masksTheKeyBeforeItIsCut(): void
        {
            // the secret ends past KEY_CHARS: cut first, only its start would be left, which no mask knows
            $key   = 'https://example.cz/data?apikey=sekret4242424242';
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed([$key])), null, self::SLICE, 'update/insert', '["relation........"]'))
                ->setImportMode()
                ->setRedact(static fn(string $text): string => str_replace('sekret4242424242', '***', $text))
                ->addValidate('no');

            $this->assertSame([0, 1], $saver->run());
            $this->assertSame('Item 1 (relation........=https://example.cz/data?apikey=***): the item failed the validation', $saver->errors()[0]['msg']);
        }

        public function test_setRedact_masksSamples(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber([$this->item(self::ITEM1, ['headline........' => 'token sekret42 inside'])]), null, self::SLICE, 'update'))
                ->setImportMode()
                ->setRedact(static fn(string $text): string => str_replace('sekret42', '***', $text));

            $saver->run(false);

            $this->assertSame(['token *** inside'], $saver->samples()[0]['headline........']);
        }

        public function test_setRedact_masksTheNamesInAMessage(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber([$this->item(self::ITEM1, ['headline........' => 'h'])]), null, self::SLICE, 'update/insert', '["sekret42........"]'))
                ->setImportMode()
                ->setRedact(static fn(string $text): string => str_replace('sekret42', '***', $text));

            $this->assertSame([0, 1], $saver->run());
            $this->assertSame('Item 1 (***........=): key field ***........ is empty', $saver->errors()[0]['msg']);
        }

        public function test_run_givesTheCallerItsSliceBack(): void
        {
            \AA::$slice_id = 'outer';
            $saver         = (new RecordingSaver(new ArrayGrabber($this->items(1)), null, self::SLICE, 'update'))
                ->setImportMode()
                ->addValidate('1');

            $this->assertSame([1, 0], $saver->run());
            $this->assertSame('outer', \AA::$slice_id, 'the validation ran in the slice of the item, the caller goes on in its own');
        }

        public function test_errors_keyInInvalidUtf8StaysValid(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->keyed(["a\xff\xfeb"])), null, self::SLICE, 'update/insert', '["relation........"]'))
                ->setImportMode()
                ->addValidate('no');

            $this->assertSame([0, 1], $saver->run());
            $this->assertTrue(mb_check_encoding($saver->errors()[0]['msg'], 'UTF-8'));
            $this->assertNotFalse(json_encode($saver->errors()));
        }

        public function test_setContext_emptyValueRaisesNoWarning(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->items(1)), null, self::SLICE, 'update'))
                ->setImportMode()
                ->addValidate('1')
                ->setContext(['empty' => [], 'blank' => '']);

            set_error_handler(static function (int $no, string $msg): never {
                throw new \ErrorException($msg, 0, $no);
            }, E_WARNING | E_NOTICE);
            try {
                $this->assertSame([1, 0], $saver->run());
            } finally {
                restore_error_handler();
            }
        }

        public function test_samples_capped(): void
        {
            $this->slice->setFields(new FakeFields(self::SLICE, [
                $this->field('text...........1', ['input_insert_func' => 'pwd:']),
                $this->field('text...........2', ['input_insert_func' => '2fa']),
                $this->field('secret.........1'),
            ]));
            $items = [];
            for ($i = 1; $i <= 6; $i++) {
                $items[] = $this->item(sprintf('%032x', $i), [
                    'headline........' => str_repeat('é', 500),
                    'abstract........' => 'short',
                    'password........' => 'pwd1',
                    'text...........1' => 'pwd2',
                    'text...........2' => '2fa secret',
                    'secret.........1' => 'api key',
                ]);
            }
            $items[0]->setAaValue('category........', new \AA_Value(range(1, 30)));
            for ($f = 0; $f < 60; $f++) {
                $items[1]->setValue(sprintf('extra%011d', $f), 'x');
            }
            $saver = (new RecordingSaver(new ArrayGrabber($items), null, self::SLICE, 'update'))->setImportMode();

            $this->assertSame([6, 0], $saver->run(false));
            $this->assertCount(Saver::SAMPLES_KEPT, $saver->samples(100));
            $this->assertCount(2, $saver->samples(2));
            $sample = $saver->samples()[0];
            $this->assertSame(['short'], $sample['abstract........']);
            $this->assertSame(Saver::SAMPLE_CHARS, mb_strlen($sample['headline........'][0]));
            $this->assertCount(Saver::SAMPLE_VALUES, $sample['category........']);
            $this->assertArrayNotHasKey('id..............', $sample, 'packed ids are binary');
            $this->assertArrayNotHasKey('password........', $sample);
            $this->assertArrayNotHasKey('text...........1', $sample, 'a field stored by the pwd inserter');
            $this->assertArrayNotHasKey('text...........2', $sample, 'a 2FA secret');
            $this->assertArrayNotHasKey('secret.........1', $sample, 'a secret field');
            $this->assertCount(Saver::SAMPLE_FIELDS, $saver->samples()[1]);
            $this->assertNotFalse(json_encode($saver->samples()));

            // a real run keeps none
            $saver = (new RecordingSaver(new ArrayGrabber($this->items(2)), null, self::SLICE, 'update'))->setImportMode();
            $saver->run();
            $this->assertSame([], $saver->samples());
        }

        public function test_samples_onlyInImportMode(): void
        {
            $saver = new RecordingSaver(new ArrayGrabber($this->items(2)), null, self::SLICE);

            $this->expectOutputRegex('/finish - 2 OK/');
            $saver->run(false);
            $this->assertSame([], $saver->samples());
        }

        public function test_importMode_storeItemWithoutCacheInvalidation(): void
        {
            $saver = (new RecordingSaver(new ArrayGrabber($this->items(2)), null, self::SLICE))->setImportMode();

            $this->assertSame([2, 0], $saver->run());
            $this->assertSame([[false], [false]], array_column($saver->stored, 'flags'));
        }
    }
}
