<?php

declare(strict_types=1);

/**
 * InstallRun: the record of one install or transfer on the target, stored as an AA_Object in TableDb -
 * its transitions, the id map and the token it keeps, the position a batch must expect and the
 * cleanup of forgotten previews.
 */

// The data provider names InstallRun constants before setUpBeforeClass(): loading AA_Object
// (include/statestore.php) needs the config constants already (as ObjectLoadTest).

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

namespace Tests\Unit\Central {

    use AA\Central\InstallRun;
    use AA\Central\Package\IdMap;
    use AA\IO\DB\DB_AA;
    use InvalidArgumentException;
    use LogicException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    final class InstallRunTest extends TestCase
    {
        private const NOW    = 1_790_000_000;
        private const USER   = 'admin-uid';
        private const TOKEN  = 'eyJhbGciOiJIUzI1NiJ9.source-superadmin-token';
        private const SLICE1 = '11111111111111111111111111111111';
        private const SLICE2 = '22222222222222222222222222222222';
        private const ITEM1  = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        private const ITEM2  = 'a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2';
        private const DEFINITIONS = '<aa-app>definitions</aa-app>';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/Package/fixtures/TableDb.php';
        }

        protected function setUp(): void
        {
            TableDb::reset();
            TableDb::$next       = ['object_text' => 1];
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
        }


        private static function preview(int $now = self::NOW): InstallRun
        {
            return InstallRun::preview(InstallRun::TRANSFER, false, '7', self::USER, self::TOKEN, $now);
        }

        private static function map(): IdMap
        {
            return (new IdMap(modules: [self::SLICE1 => str_repeat('b', 32)]))->withNumbers('view', [12 => 1000]);
        }

        /** $change on the run locked in its own transaction, as the callers do @return mixed what $change returns */
        private static function locked(string $id, callable $change): mixed
        {
            return DB_AA::transaction(static fn() => $change(InstallRun::lockForUpdate($id)));
        }

        /** the id of a run driven to $state through the allowed transitions */
        private static function runIn(string $state): string
        {
            $id = self::preview()->getId();
            if ($state !== InstallRun::PREVIEW) {
                self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1, self::SLICE2]));
            }
            self::locked($id, static fn(InstallRun $run) => match ($state) {
                InstallRun::ERROR => $run->fail('WRITE_FAILED', 'slice-module[' . self::SLICE1 . ']/item[3]'),
                InstallRun::DONE  => $run->finish(),
                InstallRun::ENDED => $run->end(),
                default           => null,
            });
            return $id;
        }

        /** @return array<string, string> property => value of the stored record */
        private static function stored(string $id): array
        {
            $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] === $id);
            return array_column($rows, 'value', 'property');
        }

        // ---------------------------------------------------------------- the record

        public function test_a_preview_is_stored_as_an_object_of_this_aa(): void
        {
            $run    = self::preview();
            $stored = self::stored($run->getId());

            $this->assertSame(InstallRun::class, $stored['aa_type']);
            $this->assertSame(AA_ID, $stored['aa_owner']);
            $this->assertSame(InstallRun::PREVIEW, $stored['state']);

            $back = InstallRun::get($run->getId());
            $this->assertSame(InstallRun::PREVIEW, $back->state());
            $this->assertSame(InstallRun::TRANSFER, $back->mode());
            $this->assertFalse($back->allBins());
            $this->assertSame('7', $back->source());
            $this->assertSame(self::USER, $back->user());
            $this->assertSame(self::NOW, $back->started());
            $this->assertSame([], TableDb::rows('object_integer'), 'one table holds the whole record');
        }

        public function test_the_source_is_local_or_a_central_conf_row_never_a_url(): void
        {
            $this->assertSame(InstallRun::LOCAL, InstallRun::preview(InstallRun::INSTALL, true, InstallRun::LOCAL, self::USER, '', self::NOW)->source());

            $this->expectException(InvalidArgumentException::class);
            InstallRun::preview(InstallRun::INSTALL, true, 'https://evil.example/aa/', self::USER, '', self::NOW);
        }

        public function test_get_of_another_class_or_no_record_is_null(): void
        {
            $note = str_repeat('c', 32);
            TableDb::$tables['object_text'][] = ['id' => 900, 'object_id' => $note, 'property' => 'aa_type', 'priority' => 0, 'value' => 'AA\Util\Note', 'flag' => null];

            $this->assertNull(InstallRun::get($note));
            $this->assertNull(InstallRun::get(str_repeat('d', 32)));
        }

        public function test_definitions_written_keep_the_map_the_fingerprint_and_the_slice_order(): void
        {
            $back = InstallRun::get(self::runIn(InstallRun::ITEMS));

            $this->assertSame(InstallRun::ITEMS, $back->state());
            $this->assertSame(self::map()->longIds(), $back->idMap()->longIds());
            $this->assertSame([12 => 1000], $back->idMap()->views());
            $this->assertSame(hash('sha256', self::DEFINITIONS), $back->fingerprint());
            $this->assertSame([self::SLICE1, self::SLICE2], $back->slices());
            $this->assertSame([self::SLICE1, ''], $back->position(), 'nothing written yet');
            $this->assertSame([self::SLICE1 => 0, self::SLICE2 => 0], $back->written());
            $this->assertSame([self::SLICE1 => 0, self::SLICE2 => 0], $back->onSource());
        }

        // ---------------------------------------------------------------- transitions

        /** @return array<string, array{string, string, ?string}> state, action, the state after it (null = LogicException) */
        public static function transitions(): array
        {
            $after = [
                InstallRun::PREVIEW => ['definitionsWritten' => InstallRun::ITEMS],
                InstallRun::ITEMS   => ['advance' => InstallRun::ITEMS, 'fail' => InstallRun::ERROR, 'finish' => InstallRun::DONE, 'end' => InstallRun::ENDED],
                InstallRun::ERROR   => ['resume' => InstallRun::ITEMS, 'end' => InstallRun::ENDED],
                InstallRun::DONE    => [],
                InstallRun::ENDED   => [],
            ];
            $cases = [];
            foreach ($after as $state => $allowed) {
                foreach (['definitionsWritten', 'advance', 'fail', 'resume', 'finish', 'end'] as $action) {
                    $cases["$state $action"] = [$state, $action, $allowed[$action] ?? null];
                }
            }
            return $cases;
        }

        private static function act(InstallRun $run, string $action): void
        {
            match ($action) {
                'definitionsWritten' => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1]),
                'advance'            => $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM1], 1, 10),
                'fail'               => $run->fail('WRITE_FAILED', 'item[1]'),
                'resume'             => $run->resume(),
                'finish'             => $run->finish(),
                'end'                => $run->end(),
            };
        }

        #[DataProvider('transitions')]
        public function test_only_the_allowed_transitions_happen(string $state, string $action, ?string $to): void
        {
            $id = self::runIn($state);
            if ($to === null) {
                $this->expectException(LogicException::class);
            }
            self::locked($id, static fn(InstallRun $run) => self::act($run, $action));
            $this->assertSame($to, InstallRun::get($id)->state());
        }

        #[DataProvider('transitions')]
        public function test_a_run_from_get_never_changes(string $state, string $action): void
        {
            $id     = self::runIn($state);
            $before = TableDb::rows('object_text');
            try {
                DB_AA::transaction(static fn() => self::act(InstallRun::get($id), $action));
                $this->fail('get() gives a read-only run');
            } catch (LogicException) {
            }
            $this->assertSame($before, TableDb::rows('object_text'));
        }

        public function test_a_locked_run_changes_only_inside_the_transaction(): void
        {
            $run = DB_AA::transaction(static fn() => InstallRun::lockForUpdate(self::runIn(InstallRun::ITEMS)));

            $this->expectException(LogicException::class);
            $run->finish();
        }

        public function test_a_second_confirm_of_the_preview_is_refused(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            $this->expectException(LogicException::class);
            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1]));
        }

        public function test_a_refused_transition_changes_nothing(): void
        {
            $id     = self::runIn(InstallRun::DONE);
            $before = TableDb::rows('object_text');
            try {
                self::locked($id, static fn(InstallRun $run) => $run->resume());
                $this->fail('done is final');
            } catch (LogicException) {
            }
            $this->assertSame($before, TableDb::rows('object_text'));
        }

        public function test_an_error_keeps_code_and_path(): void
        {
            $this->assertSame(['WRITE_FAILED', 'slice-module[' . self::SLICE1 . ']/item[3]'], InstallRun::get(self::runIn(InstallRun::ERROR))->error());

            $this->expectException(InvalidArgumentException::class);
            self::locked(self::runIn(InstallRun::ITEMS), static fn(InstallRun $run) => $run->fail("Duplicate entry 'x' for key 'PRIMARY'", ''));
        }

        // ---------------------------------------------------------------- the token

        public function test_the_token_is_stored_encrypted(): void
        {
            $run    = self::preview();
            $stored = self::stored($run->getId())['token'];

            $this->assertNotSame('', $stored);
            $this->assertStringNotContainsString(self::TOKEN, $stored);
            $this->assertStringNotContainsString('source-superadmin', $stored);
            $this->assertSame(self::TOKEN, InstallRun::get($run->getId())->token());
        }

        public function test_done_and_ended_clear_the_token(): void
        {
            foreach ([InstallRun::DONE, InstallRun::ENDED] as $state) {
                $id = self::runIn($state);

                $this->assertSame('', self::stored($id)['token'] ?? '', $state);
                $this->assertSame('', InstallRun::get($id)->token(), $state);
            }
        }

        // ---------------------------------------------------------------- batches

        public function test_advance_moves_the_position_and_counts(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM1], 500, 1200));
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, self::ITEM1], [self::SLICE2, ''], 700, 1200));   // the last batch of the first slice
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE2, ''], [self::SLICE2, self::ITEM2], 3, 3));

            $back = InstallRun::get($id);
            $this->assertSame([self::SLICE2, self::ITEM2], $back->position());
            $this->assertSame([self::SLICE1 => 1200, self::SLICE2 => 3], $back->written());
            $this->assertSame([self::SLICE1 => 1200, self::SLICE2 => 3], $back->onSource());
        }

        /** @return array<string, array{array{string, string}, int, int}> */
        public static function badAdvances(): array
        {
            return [
                'a slice outside the run'   => [[str_repeat('9', 32), ''], 1, 1],
                'an earlier slice'          => [[self::SLICE1, self::ITEM1], 1, 1],
                'a cursor that is no id'    => [[self::SLICE2, "x' OR 1"], 1, 1],
                'an upper-case cursor'      => [[self::SLICE2, strtoupper(self::ITEM1)], 1, 1],
                'negative written'          => [[self::SLICE2, self::ITEM1], -1, 1],
                'negative on the source'    => [[self::SLICE2, self::ITEM1], 1, -1],
            ];
        }

        /** @param array{string, string} $new */
        #[DataProvider('badAdvances')]
        public function test_advance_outside_the_run_is_refused(array $new, int $written, int $onSource): void
        {
            $id = self::runIn(InstallRun::ITEMS);
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, ''], [self::SLICE2, ''], 0, 0));
            $before = TableDb::rows('object_text');
            try {
                self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE2, ''], $new, $written, $onSource));
                $this->fail('refused');
            } catch (InvalidArgumentException) {
            }
            $this->assertSame($before, TableDb::rows('object_text'));
        }

        public function test_a_stale_batch_is_not_at_the_position_and_its_advance_rolls_back(): void
        {
            $id     = self::runIn(InstallRun::ITEMS);
            $failed = self::runIn(InstallRun::ERROR);
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM1], 500, 1200));
            $before = TableDb::rows('object_text');

            $this->assertTrue(self::locked($id, static fn(InstallRun $run) => $run->at([self::SLICE1, self::ITEM1])));
            $this->assertFalse(self::locked($id, static fn(InstallRun $run) => $run->at([self::SLICE1, ''])), 'a second tab');
            $this->assertFalse(self::locked($failed, static fn(InstallRun $run) => $run->at([self::SLICE1, ''])), 'no batch after an error');

            TableDb::$log = [];
            try {
                self::locked($id, static function (InstallRun $run): void {
                    TableDb::$tables['item'][] = ['id' => self::ITEM2];      // the batch's own writes
                    $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM2], 500, 1200);
                });
                $this->fail('a stale advance throws');
            } catch (LogicException) {
            }
            $this->assertContains('ROLLBACK', TableDb::$log);
            $this->assertSame([], TableDb::rows('item'), 'the batch is rolled back');
            $this->assertSame($before, TableDb::rows('object_text'));
        }

        public function test_fail_after_a_rolled_back_batch_keeps_the_position_and_counts(): void
        {
            $id = self::runIn(InstallRun::ITEMS);
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM1], 500, 1200));
            $run = null;
            try {
                self::locked($id, static function (InstallRun $locked) use (&$run): void {
                    $run = $locked;
                    $locked->advance([self::SLICE1, self::ITEM1], [self::SLICE1, self::ITEM2], 500, 1200);
                    throw new \RuntimeException('the item write failed');
                });
            } catch (\RuntimeException) {
            }
            self::locked($id, static fn(InstallRun $locked) => $locked->fail('WRITE_FAILED', 'item[2]'));

            $back = InstallRun::get($id);
            $this->assertSame(InstallRun::ERROR, $back->state());
            $this->assertSame([self::SLICE1, self::ITEM1], $back->position());
            $this->assertSame([self::SLICE1 => 500, self::SLICE2 => 0], $back->written());
            $this->assertSame([self::SLICE1, self::ITEM2], $run->position(), 'only the rolled-back instance moved');
        }

        public function test_lockForUpdate_reads_the_record_with_a_locking_read_in_the_transaction(): void
        {
            $id = self::runIn(InstallRun::ITEMS);
            TableDb::$log = [];

            $locked = DB_AA::transaction(static fn() => InstallRun::lockForUpdate($id));

            $this->assertSame([self::SLICE1, ''], $locked->position());
            $this->assertSame('BEGIN', TableDb::$log[0]);
            $this->assertStringStartsWith('SELECT ', TableDb::$log[1]);
            $this->assertStringContainsString('`object_text`', TableDb::$log[1]);
            $this->assertStringEndsWith(' FOR UPDATE', TableDb::$log[1]);
            $this->assertSame('COMMIT', TableDb::$log[2]);
        }

        // ---------------------------------------------------------------- cleanup

        public function test_a_new_preview_deletes_only_previews_older_than_a_day(): void
        {
            $old_preview = self::preview(self::NOW - 2 * 86400)->getId();
            $new_preview = self::preview(self::NOW - 3600)->getId();
            $old_items   = self::preview(self::NOW - 2 * 86400)->getId();
            self::locked($old_items, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1]));
            $other = str_repeat('e', 32);   // another class that has a state "preview"
            foreach (['aa_type' => 'AA\Util\Other', 'state' => InstallRun::PREVIEW, 'started' => '1'] as $property => $value) {
                TableDb::$tables['object_text'][] = ['id' => 900 + count(TableDb::$tables['object_text']), 'object_id' => $other, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
            }

            $fresh = self::preview();

            $this->assertSame([], self::stored($old_preview));
            $this->assertNotSame([], self::stored($new_preview));
            $this->assertNotSame([], self::stored($old_items));
            $this->assertNotSame([], self::stored($other));
            $this->assertNotSame([], self::stored($fresh->getId()));
        }
    }
}
