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
    use AA\Central\Package\ImportChoices;
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

        /** the app store: its address comes from the code or the config, never from the request */
        public function test_the_app_store_is_a_source_of_installs_only(): void
        {
            $this->assertSame(InstallRun::STORE, InstallRun::preview(InstallRun::INSTALL, false, InstallRun::STORE, self::USER, self::TOKEN, self::NOW)->source());

            $this->expectException(InvalidArgumentException::class);
            InstallRun::preview(InstallRun::TRANSFER, false, InstallRun::STORE, self::USER, self::TOKEN, self::NOW);
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
        }

        public function test_the_filters_of_the_preview_stay_when_the_definitions_are_written(): void
        {
            $filters = [self::SLICE1 => 'd-headline........-=-Česko'];
            $id      = InstallRun::preview(InstallRun::TRANSFER, false, '7', self::USER, self::TOKEN, self::NOW, '', [], new ImportChoices(filters: $filters))->getId();

            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1]));

            $back = InstallRun::get($id);
            $this->assertSame($filters, $back->filters(), 'the batches, the progress page and the check read them');
            $this->assertSame([], $back->choices()->filters, 'the choices are cleared');
        }

        public function test_a_preview_of_another_aa_keeps_its_filters_and_one_without_clears_them(): void
        {
            $id = self::preview()->getId();

            self::locked($id, static fn(InstallRun $run) => $run->previewed(hash('sha256', self::DEFINITIONS), self::NOW, false, [], new ImportChoices(filters: [self::SLICE2 => 'd-a-=-1'])));
            $this->assertSame([self::SLICE2 => 'd-a-=-1'], InstallRun::get($id)->filters());

            self::locked($id, static fn(InstallRun $run) => $run->previewed(hash('sha256', self::DEFINITIONS), self::NOW, false));
            $this->assertSame([], InstallRun::get($id)->filters());
        }

        public function test_the_preview_keeps_the_fingerprint_it_showed(): void
        {
            $run = InstallRun::preview(InstallRun::INSTALL, false, InstallRun::LOCAL, self::USER, '', self::NOW, hash('sha256', self::DEFINITIONS));

            $this->assertSame(hash('sha256', self::DEFINITIONS), InstallRun::get($run->getId())->fingerprint());
        }

        public function test_definitions_with_no_items_to_follow_finish_the_run_and_clear_the_token(): void
        {
            $id = self::preview()->getId();

            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, []));

            $this->assertSame(InstallRun::DONE, InstallRun::get($id)->state());
            $this->assertSame('', InstallRun::get($id)->token());
        }

        public function test_the_finish_keeps_its_report(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            self::locked($id, static fn(InstallRun $run) => $run->finish(['checked' => 5, 'findings' => [['level' => 'W', 'code' => 'x', 'path' => 'p', 'ref' => '<b>']]]));

            $this->assertSame(['checked' => 5, 'findings' => [['level' => 'W', 'code' => 'x', 'path' => 'p', 'ref' => '<b>']]], InstallRun::get($id)->report());
        }

        public function test_moved_is_the_start_until_the_run_moves_on(): void
        {
            $id = self::preview()->getId();
            $this->assertSame(self::NOW, InstallRun::get($id)->moved());

            $before = time();
            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1]));

            $this->assertGreaterThanOrEqual($before, InstallRun::get($id)->moved());
            $this->assertSame([$id], array_map(static fn(InstallRun $run): string => $run->getId(), InstallRun::unfinished()));
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
                'advance'            => $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM1], 1, []),
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

        public function test_a_run_opened_at_the_login_takes_the_fingerprint_of_its_preview(): void
        {
            $id = InstallRun::preview(InstallRun::TRANSFER, true, '7', self::USER, self::TOKEN, self::NOW)->getId();
            $this->assertSame('', InstallRun::get($id)->fingerprint());

            $this->assertSame(self::NOW, InstallRun::get($id)->sourceStarted(), 'before the preview: its own start');

            self::locked($id, static fn(InstallRun $run) => $run->previewed(hash('sha256', self::DEFINITIONS), self::NOW - 3600, false));

            $run = InstallRun::get($id);
            $this->assertSame([InstallRun::PREVIEW, hash('sha256', self::DEFINITIONS), self::TOKEN], [$run->state(), $run->fingerprint(), $run->token()]);
            $this->assertSame(self::NOW - 3600, $run->sourceStarted(), 'the clock of the source');
        }

        public function test_the_same_aa_is_known_by_its_identity_not_by_the_transport(): void
        {
            $local  = InstallRun::preview(InstallRun::TRANSFER, false, InstallRun::LOCAL, self::USER, '', self::NOW)->getId();
            $remote = self::preview()->getId();
            $self   = self::preview()->getId();
            self::locked($remote, static fn(InstallRun $run) => $run->previewed(hash('sha256', 'a'), self::NOW, false));
            self::locked($self, static fn(InstallRun $run) => $run->previewed(hash('sha256', 'a'), self::NOW, true));

            $this->assertSame([true, false, true], [InstallRun::get($local)->sameAa(), InstallRun::get($remote)->sameAa(), InstallRun::get($self)->sameAa()]);
        }

        /** the write reads the source again: another package, or another answer to "is it this AA?", is another source */
        public function test_the_write_matches_the_preview_in_package_and_identity(): void
        {
            $id = self::preview()->getId();
            self::locked($id, static fn(InstallRun $run) => $run->previewed(hash('sha256', self::DEFINITIONS), self::NOW, false));
            $run = InstallRun::get($id);

            $this->assertTrue($run->matchesPreview(self::DEFINITIONS, false, []));
            $this->assertFalse($run->matchesPreview(self::DEFINITIONS . ' ', false, []), 'another package');
            $this->assertFalse($run->matchesPreview(self::DEFINITIONS, true, []), 'the Central row names this AA now');
        }

        public function test_only_a_preview_takes_a_fingerprint(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            $this->expectException(LogicException::class);
            self::locked($id, static fn(InstallRun $run) => $run->previewed(hash('sha256', 'other'), self::NOW, false));
        }

        public function test_continue_after_a_new_login_keeps_the_new_token_encrypted(): void
        {
            $id = self::runIn(InstallRun::ERROR);

            self::locked($id, static fn(InstallRun $run) => $run->resume('eyJhbGciOiJIUzI1NiJ9.renewed-token'));

            $this->assertSame(InstallRun::ITEMS, InstallRun::get($id)->state());
            $this->assertSame('eyJhbGciOiJIUzI1NiJ9.renewed-token', InstallRun::get($id)->token());
            $this->assertStringNotContainsString('renewed-token', self::stored($id)['token']);
        }

        public function test_a_token_older_than_a_day_is_forgotten_in_every_unfinished_state(): void
        {
            $preview = self::preview()->getId();
            $error   = self::runIn(InstallRun::ERROR);
            $items   = self::runIn(InstallRun::ITEMS);
            $fresh   = InstallRun::preview(InstallRun::TRANSFER, false, '7', self::USER, self::TOKEN, time() + 3600)->getId();

            InstallRun::forgetOldTokens(time() + 86400 + 1);       // the error run moved last now (its definitions)

            $this->assertSame('', self::stored($preview)['token'] ?? '');
            $this->assertSame('', self::stored($error)['token'] ?? '');
            $this->assertSame('', self::stored($items)['token'] ?? '', 'a live transfer moves on with every batch - a day without one is abandoned');
            $this->assertSame(InstallRun::ITEMS, InstallRun::get($items)->state());
            $this->assertSame(self::TOKEN, InstallRun::get($fresh)->token());
            $this->assertSame(InstallRun::ERROR, InstallRun::get($error)->state());
        }

        public function test_a_transfer_that_moved_on_today_keeps_its_token(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            InstallRun::forgetOldTokens(time() + 3600);

            $this->assertSame(self::TOKEN, InstallRun::get($id)->token());
        }

        public function test_a_renewed_token_is_kept_for_its_day(): void
        {
            $id = self::runIn(InstallRun::ERROR);
            self::locked($id, static fn(InstallRun $run) => $run->resume('eyJhbGciOiJIUzI1NiJ9.renewed-token'));
            self::locked($id, static fn(InstallRun $run) => $run->fail('SOURCE_LOGIN', 'source'));

            InstallRun::forgetOldTokens(time() + 3600);

            $this->assertSame('eyJhbGciOiJIUzI1NiJ9.renewed-token', InstallRun::get($id)->token(), 'renewed now, not at the start of the run');
        }

        public function test_a_long_error_path_is_cut(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            self::locked($id, static fn(InstallRun $run) => $run->fail('EXPORT_FAILED', str_repeat('š', 400)));

            $this->assertSame(254, strlen(InstallRun::get($id)->error()[1]), 'within 255 bytes, no character cut in half');
        }

        public function test_continue_without_a_login_keeps_the_token(): void
        {
            $id = self::runIn(InstallRun::ERROR);

            self::locked($id, static fn(InstallRun $run) => $run->resume());

            $this->assertSame(self::TOKEN, InstallRun::get($id)->token());
        }

        // ---------------------------------------------------------------- batches

        public function test_advance_moves_the_position_and_counts(): void
        {
            $id = self::runIn(InstallRun::ITEMS);

            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM1], 500, ['reader-taken' => 2]));
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, self::ITEM1], [self::SLICE2, ''], 700, ['reader-taken' => 1, 'reader-2fa' => 3]));   // the last batch of the first slice
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE2, ''], [self::SLICE2, self::ITEM2], 3, []));

            $back = InstallRun::get($id);
            $this->assertSame([self::SLICE2, self::ITEM2], $back->position());
            $this->assertSame([self::SLICE1 => 1200, self::SLICE2 => 3], $back->written());
            $this->assertSame([self::SLICE1 => ['reader-taken' => 3, 'reader-2fa' => 3]], $back->warnings(), 'summed per slice');
        }

        /** @return array<string, array{array{string, string}, int, array<mixed>}> */
        public static function badAdvances(): array
        {
            return [
                'a slice outside the run'   => [[str_repeat('9', 32), ''], 1, []],
                'an earlier slice'          => [[self::SLICE1, self::ITEM1], 1, []],
                'a cursor that is no id'    => [[self::SLICE2, "x' OR 1"], 1, []],
                'an upper-case cursor'      => [[self::SLICE2, strtoupper(self::ITEM1)], 1, []],
                'negative written'          => [[self::SLICE2, self::ITEM1], -1, []],
                'no warning counted'        => [[self::SLICE2, self::ITEM1], 1, ['reader-taken' => 0]],
                'a warning of no code'      => [[self::SLICE2, self::ITEM1], 1, ['<b>' => 1]],
            ];
        }

        /** @param array{string, string} $new @param array<mixed> $warnings */
        #[DataProvider('badAdvances')]
        public function test_advance_outside_the_run_is_refused(array $new, int $written, array $warnings): void
        {
            $id = self::runIn(InstallRun::ITEMS);
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, ''], [self::SLICE2, ''], 0, []));
            $before = TableDb::rows('object_text');
            try {
                self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE2, ''], $new, $written, $warnings));
                $this->fail('refused');
            } catch (InvalidArgumentException) {
            }
            $this->assertSame($before, TableDb::rows('object_text'));
        }

        public function test_a_stale_batch_is_not_at_the_position_and_its_advance_rolls_back(): void
        {
            $id     = self::runIn(InstallRun::ITEMS);
            $failed = self::runIn(InstallRun::ERROR);
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM1], 500, []));
            $before = TableDb::rows('object_text');

            $this->assertTrue(self::locked($id, static fn(InstallRun $run) => $run->at([self::SLICE1, self::ITEM1])));
            $this->assertFalse(self::locked($id, static fn(InstallRun $run) => $run->at([self::SLICE1, ''])), 'a second tab');
            $this->assertFalse(self::locked($failed, static fn(InstallRun $run) => $run->at([self::SLICE1, ''])), 'no batch after an error');

            TableDb::$log = [];
            try {
                self::locked($id, static function (InstallRun $run): void {
                    TableDb::$tables['item'][] = ['id' => self::ITEM2];      // the batch's own writes
                    $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM2], 500, []);
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
            self::locked($id, static fn(InstallRun $run) => $run->advance([self::SLICE1, ''], [self::SLICE1, self::ITEM1], 500, []));
            $run = null;
            try {
                self::locked($id, static function (InstallRun $locked) use (&$run): void {
                    $run = $locked;
                    $locked->advance([self::SLICE1, self::ITEM1], [self::SLICE1, self::ITEM2], 500, []);
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
