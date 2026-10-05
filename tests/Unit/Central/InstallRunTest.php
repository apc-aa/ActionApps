<?php

declare(strict_types=1);

/**
 * InstallRun: the record of one install or transfer on the target, a batch run stored as an AA_Object in
 * TableDb - its states, the id map, the source's token it keeps sealed, the step the definitions queue,
 * what a step that cannot read its batch leaves in the run, and the cleanup of forgotten previews.
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

    use AA\Central\AppSource;
    use AA\Central\ExportRefused;
    use AA\Central\InstallRun;
    use AA\Central\Package\IdMap;
    use AA\Central\Package\ImportChoices;
    use AA\Central\Package\LintFinding;
    use AA\Central\RemoteSource;
    use AA\Central\SourceFailed;
    use AA\IO\DB\DB_AA;
    use AA\Later\BatchFailed;
    use AA\Later\BatchPosition;
    use AA\Later\BatchRun;
    use AA\Later\BatchStep;
    use AA_Property;
    use Closure;
    use InvalidArgumentException;
    use LogicException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\FakeSuperadmins;
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

        private FakeSuperadmins $superadmins;
        private mixed $perm = null;
        private string $errorLog = '';
        /** @var list<array{string, string, int, int}> the batches the steps asked the source for: slice, cursor, items, timeout */
        private array $asked = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/Package/fixtures/TableDb.php';
            require_once __DIR__ . '/Package/fixtures/FakeSuperadmins.php';
        }

        protected function setUp(): void
        {
            TableDb::reset();
            TableDb::$next       = ['object_text' => 1];
            TableDb::$engines    = ['object_text' => 'InnoDB', 'toexecute' => 'InnoDB', 'item' => 'InnoDB', 'content' => 'InnoDB'];
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
            class_exists(\AA_Perm::class);              // its first load sets AA::$perm - not over the stub
            $this->perm          = \AA::$perm;
            $this->superadmins   = new FakeSuperadmins([self::USER]);
            \AA::$perm           = $this->superadmins;
            InstallRun::setTestDoubles(null);
            $this->errorLog      = (string) ini_get('error_log');
            ini_set('error_log', '/dev/null');          // a failed source is logged - not into the test output
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
            \AA::$perm = $this->perm;
            InstallRun::setTestDoubles(null);
            ini_set('error_log', $this->errorLog);
            set_time_limit(0);   // a task later() runs at once sets a 30 s limit - it must not outlive the test
        }

        private static function preview(string $mode = InstallRun::TRANSFER, string $token = self::TOKEN): InstallRun
        {
            return InstallRun::preview($mode, false, '7', self::USER, $token, self::NOW);
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

        /** the steps read their batch from a source that answers by $batch(slice, cursor) - every question logged */
        private function source(Closure $batch): void
        {
            $asked = &$this->asked;
            InstallRun::setTestDoubles(static function (InstallRun $run, int $timeout) use ($batch, &$asked): AppSource {
                return new class ($batch, $timeout, $asked) implements AppSource {
                    public function __construct(private Closure $batch, private int $timeout, private array &$asked) {}

                    public function sameAa(): bool
                    {
                        throw new LogicException('not asked by a step');
                    }

                    public function itemIds(string $slice, bool $allBins, string $after, array $filters = []): array
                    {
                        throw new LogicException('not asked by a step');
                    }

                    public function definitions(array $modules, bool $transfer, bool $allBins, int $started): array
                    {
                        throw new LogicException('not asked by a step');
                    }

                    public function batch(string $slice, string $after, bool $allBins, int $started, array $filters = [], int $limit = 500): array
                    {
                        $this->asked[] = [$slice, $after, $limit, $this->timeout];
                        return ($this->batch)($slice, $after);
                    }

                    public function logins(array $slices, bool $allBins, array $filters = []): array
                    {
                        throw new LogicException('not asked by a step');
                    }

                    public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0, array $filters = []): array
                    {
                        throw new LogicException('not asked by a step');
                    }

                    public function leftOut(array $filters, array $slices, bool $allBins): array
                    {
                        throw new LogicException('not asked by a step');
                    }
                };
            });
        }

        /** the id of a run in $state: a transfer of two slices, an install for done - it finishes in the queue */
        private function runIn(string $state): string
        {
            if ($state === InstallRun::DONE) {
                $id = self::preview(InstallRun::INSTALL)->getId();
                self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, []));
                self::assertSame(InstallRun::DONE, self::runQueued($id));
                return $id;
            }
            $id = self::preview()->getId();
            if ($state !== InstallRun::PREVIEW) {
                self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1, self::SLICE2]));
            }
            if ($state === InstallRun::ERROR) {
                $this->source(static fn() => throw ExportRefused::failed([['slice-module[' . self::SLICE1 . ']/item[' . self::ITEM1 . ']', new LintFinding(LintFinding::ERROR, 'item-too-big', self::ITEM1, 0)]]));
                self::assertSame('error ITEM_TOO_BIG', self::runQueued($id));
            }
            if ($state === InstallRun::ENDED) {
                self::locked($id, static fn(InstallRun $run) => $run->end(self::USER));
            }
            return $id;
        }

        /** @return array<string, string> property => value of the stored record */
        private static function stored(string $id): array
        {
            $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] === $id);
            return array_map('strval', array_column($rows, 'value', 'property'));
        }

        /** changes a stored property behind the run's back, as another process or time would */
        private static function storedSet(string $id, string $property, string $value): void
        {
            foreach (TableDb::$tables['object_text'] ?? [] as $i => $row) {
                if (($row['object_id'] === $id) && ($row['property'] === $property)) {
                    TableDb::$tables['object_text'][$i]['value'] = $value;
                    return;
                }
            }
            TableDb::$tables['object_text'][] = ['id' => TableDb::$next['object_text']++, 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
        }

        /** @return list<array<string, mixed>> the queue rows */
        private static function queue(): array
        {
            return TableDb::rows('toexecute');
        }

        /** runs the oldest queued step (of run $id) as the queue does - the row goes away after it */
        private static function runQueued(string $id = ''): string
        {
            $rows = array_values(array_filter(self::queue(), static fn(array $r): bool => ($id === '') || ($r['selector'] === "Install_$id")));
            $row  = $rows[0] ?? throw new LogicException('Nothing queued');
            $step = unserialize((string) $row['object']);
            self::assertInstanceOf(BatchStep::class, $step);
            $ret = $step->toexecutelater();
            TableDb::$tables['toexecute'] = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['id'] !== $row['id']));
            return $ret;
        }

        /** a JWT claiming $claims - the target reads them, it cannot check the signature */
        private static function jwt(array $claims): string
        {
            return 'eyJhbGciOiJIUzI1NiJ9.' . rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=') . '.sig';
        }

        /** the token as RemoteSource::seal() keeps it */
        private static function sealed(string $token): string
        {
            return (new RemoteSource('https://source.example/central/responder.php'))->seal($token);
        }

        // ---------------------------------------------------------------- the record

        public function test_a_preview_is_stored_as_a_batch_run_of_this_aa(): void
        {
            $before = time();
            $run    = self::preview();
            $stored = self::stored($run->getId());

            $this->assertSame(InstallRun::class, $stored['aa_type']);
            $this->assertSame(strtolower(AA_ID), $stored['aa_owner']);
            $this->assertSame(strtolower(AA_ID), $stored['subject']);
            $this->assertSame(InstallRun::PREVIEW, $stored['state']);
            $this->assertSame((string) self::NOW, $stored['exported']);

            $back = InstallRun::get($run->getId());
            $this->assertSame([InstallRun::PREVIEW, InstallRun::TRANSFER, false, '7', self::USER], [$back->state(), $back->mode(), $back->allBins(), $back->source(), $back->user()]);
            $this->assertSame(self::NOW, $back->started(), 'the time of the export, not of the record');
            $this->assertGreaterThanOrEqual($before, $back->moved(), 'nothing moved yet: the start of the record');
            $this->assertSame([], self::queue(), 'a preview has no step');
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

        /** @return array<string, array{string}> */
        public static function tables(): array
        {
            return ['object_text' => ['object_text'], 'toexecute' => ['toexecute'], 'item' => ['item'], 'content' => ['content']];
        }

        /** the run and its batches roll back as one - on a table without transactions they could not */
        #[DataProvider('tables')]
        public function test_no_preview_starts_on_a_table_without_transactions(string $table): void
        {
            TableDb::$engines[$table] = 'MyISAM';
            try {
                self::preview();
                $this->fail('a run on MyISAM');
            } catch (BatchFailed $e) {
                $this->assertSame(['NO_TRANSACTION', $table], [$e->reason, $e->where]);
            }
            $this->assertSame([], TableDb::rows('object_text'));
        }

        public function test_definitions_written_keep_the_map_the_fingerprint_and_the_slice_order(): void
        {
            $back = InstallRun::get($this->runIn(InstallRun::ITEMS));

            $this->assertSame(InstallRun::ITEMS, $back->state());
            $this->assertSame(self::map()->longIds(), $back->idMap()->longIds());
            $this->assertSame([12 => 1000], $back->idMap()->views());
            $this->assertSame(hash('sha256', self::DEFINITIONS), $back->fingerprint());
            $this->assertSame([self::SLICE1, self::SLICE2], $back->slices());
            $this->assertSame(InstallRun::at(self::SLICE1, ''), $back->position(), 'nothing written yet');
            $this->assertSame([], $back->written());
        }

        public function test_definitions_written_queue_the_first_batch_with_them(): void
        {
            $id = $this->runIn(InstallRun::ITEMS);

            $queue = self::queue();
            $this->assertCount(1, $queue);
            $this->assertSame(['Install_' . $id, 5], [$queue[0]['selector'], (int) $queue[0]['priority']]);
            $step = unserialize((string) $queue[0]['object']);
            $this->assertEquals(new BatchStep(InstallRun::class, $id, BatchPosition::fingerprint(1, InstallRun::at(self::SLICE1, ''))), $step);
            $this->assertSame('', self::stored($id)['closing'], 'items follow');
        }

        public function test_definitions_with_no_items_to_follow_queue_only_the_finish(): void
        {
            $id = self::preview()->getId();

            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, []));

            $this->assertSame([InstallRun::ITEMS, '1'], [InstallRun::get($id)->state(), self::stored($id)['closing']]);
            $this->assertCount(1, self::queue());
        }

        /** no items, nothing to compare with the source */
        public function test_a_transfer_of_no_slice_finishes_without_a_check(): void
        {
            $id = self::preview()->getId();
            InstallRun::setTestDoubles(static fn(): never => throw new LogicException('the source was asked'));
            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, []));

            $this->assertSame(InstallRun::DONE, self::runQueued());

            $this->assertSame([InstallRun::DONE, []], [InstallRun::get($id)->state(), InstallRun::get($id)->report()]);
        }

        public function test_a_written_install_forgets_the_token_and_finishes_in_the_queue(): void
        {
            $id = self::preview(InstallRun::INSTALL)->getId();
            $this->assertSame(self::TOKEN, InstallRun::get($id)->token());
            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, []));
            // nothing asks the source any more - a queue that does not run must not keep it
            $this->assertSame(['', '', ''], [InstallRun::get($id)->token(), self::stored($id)['sealed'], self::stored($id)['sealed_until']]);

            $this->assertSame(InstallRun::DONE, self::runQueued());

            $run = InstallRun::get($id);
            $this->assertSame([InstallRun::DONE, '', []], [$run->state(), $run->token(), $run->report()]);
            $this->assertSame('', self::stored($id)['sealed']);
            $this->assertSame([], InstallRun::unfinished());
        }

        public function test_a_slice_of_no_id_is_refused_and_the_preview_stays(): void
        {
            $id     = self::preview()->getId();
            $before = TableDb::rows('object_text');

            try {
                self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [strtoupper(self::SLICE1 . 'a')]));
                $this->fail('refused');
            } catch (InvalidArgumentException) {
            }
            $this->assertSame($before, TableDb::rows('object_text'));
            $this->assertSame([], self::queue());
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

        /** the progress page shows the items on the source as the preview counted them */
        public function test_the_preview_keeps_the_counts_of_the_source(): void
        {
            $id = InstallRun::preview(InstallRun::TRANSFER, false, InstallRun::LOCAL, self::USER, '', self::NOW, '', [], null, [self::SLICE1 => 3, self::SLICE2 => 0])->getId();
            $this->assertSame([self::SLICE1 => 3, self::SLICE2 => 0], InstallRun::get($id)->sourceCounts());

            $other = self::preview()->getId();
            $this->assertSame([], InstallRun::get($other)->sourceCounts());
            self::locked($other, static fn(InstallRun $run) => $run->previewed(hash('sha256', 'a'), self::NOW, false, [], null, [self::SLICE2 => 7]));
            $this->assertSame([self::SLICE2 => 7], InstallRun::get($other)->sourceCounts());
            self::locked($other, static fn(InstallRun $run) => $run->previewed(hash('sha256', 'a'), self::NOW, false));
            $this->assertSame([], InstallRun::get($other)->sourceCounts(), 'a preview again counts again');
        }

        public function test_moved_is_the_start_until_the_run_moves_on(): void
        {
            $id = self::preview()->getId();
            $this->assertSame((int) self::stored($id)['started'], InstallRun::get($id)->moved());

            self::storedSet($id, 'started', '5');
            $before = time();
            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1]));

            $this->assertGreaterThanOrEqual($before, InstallRun::get($id)->moved());
            $this->assertSame([$id], array_map(static fn(InstallRun $run): string => $run->getId(), InstallRun::unfinished()));
        }

        // ---------------------------------------------------------------- transitions

        /** @return array<string, array{string, string, string}> state, action, the state after it - or the exception class */
        public static function transitions(): array
        {
            $after = [
                InstallRun::PREVIEW => ['definitionsWritten' => InstallRun::ITEMS, 'previewed' => InstallRun::PREVIEW, 'end' => InstallRun::ENDED],
                // queued, not waiting: Continue would only double the step
                InstallRun::ITEMS   => ['end' => InstallRun::ENDED],
                InstallRun::ERROR   => ['resume' => InstallRun::ITEMS, 'end' => InstallRun::ENDED],
                InstallRun::DONE    => [],
                InstallRun::ENDED   => [],
            ];
            $cases = [];
            foreach ($after as $state => $allowed) {
                foreach (['definitionsWritten', 'previewed', 'resume', 'end'] as $action) {
                    $refused = in_array($action, ['resume', 'end'], true) ? BatchFailed::class : LogicException::class;
                    $cases["$state $action"] = [$state, $action, $allowed[$action] ?? $refused];
                }
            }
            return $cases;
        }

        private static function act(InstallRun $run, string $action): void
        {
            match ($action) {
                'definitionsWritten' => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1]),
                'previewed'          => $run->previewed(hash('sha256', self::DEFINITIONS), self::NOW, false),
                'resume'             => $run->resume(self::USER),
                'end'                => $run->end(self::USER),
            };
        }

        #[DataProvider('transitions')]
        public function test_only_the_allowed_transitions_happen(string $state, string $action, string $to): void
        {
            $id     = $this->runIn($state);
            $before = [TableDb::rows('object_text'), self::queue()];
            try {
                self::locked($id, static fn(InstallRun $run) => self::act($run, $action));
            } catch (LogicException|BatchFailed $e) {
                $this->assertInstanceOf($to, $e, $e->getMessage());
                $this->assertSame($before, [TableDb::rows('object_text'), self::queue()], 'a refused transition changes nothing');
                return;
            }
            $this->assertSame($to, InstallRun::get($id)->state());
        }

        /** previewed() is left out: it asks for no lock, its callers lock the run
         *  @return array<string, array{string, string, string}> */
        public static function lockedTransitions(): array
        {
            return array_filter(self::transitions(), static fn(array $case): bool => $case[1] !== 'previewed');
        }

        #[DataProvider('lockedTransitions')]
        public function test_a_run_from_get_never_changes(string $state, string $action): void
        {
            $id     = $this->runIn($state);
            $before = TableDb::rows('object_text');
            try {
                DB_AA::transaction(static fn() => self::act(InstallRun::get($id), $action));
                $this->fail('get() gives a read-only run');
            } catch (LogicException|BatchFailed) {
            }
            $this->assertSame($before, TableDb::rows('object_text'));
        }

        public function test_a_locked_run_changes_only_inside_the_transaction(): void
        {
            $id  = $this->runIn(InstallRun::ITEMS);
            $run = DB_AA::transaction(static fn() => InstallRun::lockForUpdate($id));

            $this->expectException(LogicException::class);
            $run->end(self::USER);
        }

        public function test_a_second_confirm_of_the_preview_is_refused(): void
        {
            $id = $this->runIn(InstallRun::ITEMS);

            $this->expectException(LogicException::class);
            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(self::map(), self::DEFINITIONS, [self::SLICE1]));
        }

        public function test_only_a_preview_takes_a_fingerprint(): void
        {
            $id = $this->runIn(InstallRun::ITEMS);

            $this->expectException(LogicException::class);
            self::locked($id, static fn(InstallRun $run) => $run->previewed(hash('sha256', 'other'), self::NOW, false));
        }

        public function test_a_preview_outside_a_transaction_is_refused(): void
        {
            $run = InstallRun::get(self::preview()->getId());

            $this->expectException(LogicException::class);
            $run->previewed(hash('sha256', 'other'), self::NOW, false);
        }

        // ---------------------------------------------------------------- who controls it

        public function test_the_user_who_started_it_and_any_superadmin_control_the_run(): void
        {
            $this->superadmins->superadmins = ['boss'];             // the user is no superadmin any more
            $run                            = InstallRun::get(self::preview()->getId());

            $this->assertSame([true, true, false, false], [$run->mayControl(self::USER), $run->mayControl('boss'), $run->mayControl('other'), $run->mayControl('')]);
            $this->assertNotContains('', $this->superadmins->asked, 'no one is no user');

            $this->expectException(BatchFailed::class);
            $run->progress('other');
        }

        // ---------------------------------------------------------------- the token

        public function test_the_token_is_kept_only_sealed(): void
        {
            $before = time();
            $id     = self::preview()->getId();
            $stored = self::stored($id);

            $this->assertArrayNotHasKey('token', $stored);
            $this->assertNotSame('', $stored['sealed']);
            $this->assertStringNotContainsString(self::TOKEN, $stored['sealed']);
            $this->assertStringNotContainsString('source-superadmin', $stored['sealed']);
            $this->assertGreaterThanOrEqual($before + RemoteSource::TOKEN_TTL, (int) $stored['sealed_until'], 'a token that claims nothing: a day');
            $this->assertLessThanOrEqual(time() + RemoteSource::TOKEN_TTL, (int) $stored['sealed_until']);
            $this->assertSame(self::TOKEN, InstallRun::get($id)->token());
        }

        /** the page of the login asks the source for its apps with the run it got */
        public function test_the_new_preview_holds_its_token(): void
        {
            $this->assertSame(self::TOKEN, self::preview()->token());
        }

        public function test_a_preview_without_a_token_keeps_none(): void
        {
            $id = InstallRun::preview(InstallRun::TRANSFER, false, InstallRun::LOCAL, self::USER, '', self::NOW)->getId();

            $this->assertSame('', self::stored($id)['sealed'] ?? '');
            $this->assertSame('', InstallRun::get($id)->token());
        }

        /** the token is good till the expiry it claims, a day at most */
        public function test_the_token_is_kept_till_it_expires(): void
        {
            $soon    = self::sealed(self::jwt(['exp' => time() + 3600]));
            $later   = self::sealed(self::jwt(['exp' => time() + 5 * 86400]));
            $expired = self::sealed(self::jwt(['exp' => time() - 1]));

            $this->assertSame(time() + 3600, InstallRun::sealedUntil($soon));
            $this->assertSame(time() + RemoteSource::TOKEN_TTL, InstallRun::sealedUntil($later));
            $this->assertSame(time() + RemoteSource::TOKEN_TTL, InstallRun::sealedUntil(self::sealed('no.claims')));

            $id = self::preview(token: $soon)->getId();
            $this->assertSame([(string) (time() + 3600), $soon], [self::stored($id)['sealed_until'], InstallRun::get($id)->token()]);

            $gone = self::preview(token: $expired)->getId();
            $this->assertSame(['', ''], [self::stored($gone)['sealed'] ?? '', InstallRun::get($gone)->token()], 'a token past its time is of no use');
        }

        public function test_a_sealed_token_opens_only_in_its_run_and_till_its_time(): void
        {
            $id     = self::preview()->getId();
            $other  = self::preview()->getId();
            $stored = self::stored($id);

            self::storedSet($other, 'sealed', $stored['sealed']);
            self::storedSet($other, 'sealed_until', $stored['sealed_until']);
            $this->assertSame('', InstallRun::get($other)->token(), 'moved to another run');

            self::storedSet($id, 'sealed_until', (string) ((int) $stored['sealed_until'] + 60));
            $this->assertSame('', InstallRun::get($id)->token(), 'given more time');

            self::storedSet($id, 'sealed_until', $stored['sealed_until']);
            $this->assertSame(self::TOKEN, InstallRun::get($id)->token());

            self::storedSet($id, 'sealed', 'v1:not-sealed-by-this-aa');
            $this->assertSame('', InstallRun::get($id)->token(), 'unreadable: the source refuses, Continue logs in again');
        }

        public function test_done_and_ended_forget_the_token(): void
        {
            foreach ([InstallRun::DONE, InstallRun::ENDED] as $state) {
                $id = $this->runIn($state);

                $this->assertSame(['', ''], [self::stored($id)['sealed'], self::stored($id)['sealed_until']], $state);
                $this->assertSame('', InstallRun::get($id)->token(), $state);
            }
        }

        public function test_a_token_past_its_time_is_forgotten_by_the_next_preview(): void
        {
            $old   = self::preview()->getId();
            $other = str_repeat('c', 32);   // another class keeps its own secret
            foreach (['aa_type' => 'AA\\Import\\Run', 'state' => InstallRun::ERROR, 'sealed' => 'secret of another class', 'sealed_until' => '1'] as $property => $value) {
                self::storedSet($other, $property, $value);
            }
            self::storedSet($old, 'sealed_until', (string) (time() - 1));

            $fresh = self::preview()->getId();

            $this->assertSame(['', ''], [self::stored($old)['sealed'], self::stored($old)['sealed_until']]);
            $this->assertSame('secret of another class', self::stored($other)['sealed']);
            $this->assertSame(self::TOKEN, InstallRun::get($fresh)->token());
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

        public function test_a_run_opened_at_the_login_takes_the_fingerprint_of_its_preview(): void
        {
            $id = InstallRun::preview(InstallRun::TRANSFER, true, '7', self::USER, self::TOKEN, self::NOW)->getId();
            $this->assertSame('', InstallRun::get($id)->fingerprint());
            $this->assertSame(self::NOW, InstallRun::get($id)->sourceStarted(), 'before the preview: its own export');

            self::locked($id, static fn(InstallRun $run) => $run->previewed(hash('sha256', self::DEFINITIONS), self::NOW - 3600, false));

            $run = InstallRun::get($id);
            $this->assertSame([InstallRun::PREVIEW, hash('sha256', self::DEFINITIONS), self::TOKEN], [$run->state(), $run->fingerprint(), $run->token()]);
            $this->assertSame(self::NOW - 3600, $run->sourceStarted(), 'the clock of the source');
            $this->assertSame(self::NOW, $run->started());
        }

        /** the write reads the source again: another package, or another answer to "is it this AA?", is another source */
        public function test_the_write_matches_the_preview_in_package_identity_and_targets(): void
        {
            $id = self::preview()->getId();
            self::locked($id, static fn(InstallRun $run) => $run->previewed(hash('sha256', self::DEFINITIONS), self::NOW, false, [self::SLICE1 => self::SLICE2]));
            $run = InstallRun::get($id);

            $this->assertTrue($run->matchesPreview(self::DEFINITIONS, false, [self::SLICE1 => self::SLICE2]));
            $this->assertFalse($run->matchesPreview(self::DEFINITIONS . ' ', false, [self::SLICE1 => self::SLICE2]), 'another package');
            $this->assertFalse($run->matchesPreview(self::DEFINITIONS, true, [self::SLICE1 => self::SLICE2]), 'the Central row names this AA now');
            $this->assertFalse($run->matchesPreview(self::DEFINITIONS, false, []), 'a write form of an older preview');
        }

        public function test_continue_after_a_new_login_keeps_the_new_token_sealed(): void
        {
            $id      = $this->runIn(InstallRun::ERROR);
            $renewed = 'eyJhbGciOiJIUzI1NiJ9.renewed-token';

            self::locked($id, static fn(InstallRun $run) => $run->resume(self::USER, $renewed, InstallRun::sealedUntil($renewed)));

            $this->assertSame(InstallRun::ITEMS, InstallRun::get($id)->state());
            $this->assertSame($renewed, InstallRun::get($id)->token());
            $this->assertStringNotContainsString('renewed-token', self::stored($id)['sealed']);
        }

        public function test_continue_without_a_login_keeps_the_token(): void
        {
            $id = $this->runIn(InstallRun::ERROR);

            self::locked($id, static fn(InstallRun $run) => $run->resume(self::USER));

            $this->assertSame(self::TOKEN, InstallRun::get($id)->token());
        }

        /** AA's error handler keeps the arguments of every stack frame - a token must show as redacted */
        public function test_secret_parameters_are_kept_out_of_stack_traces(): void
        {
            foreach ([[InstallRun::class, 'preview', 'token'], [InstallRun::class, 'sealedUntil', 'sealed'], [BatchRun::class, 'resume', 'secret'], [BatchRun::class, 'seal', 'secret']] as [$class, $method, $name]) {
                $param = array_values(array_filter((new \ReflectionMethod($class, $method))->getParameters(), static fn(\ReflectionParameter $p): bool => $p->getName() === $name))[0] ?? null;
                $this->assertNotNull($param, "$class::$method \${$name}");
                $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class), "$class::$method \${$name}");
            }
        }

        // ---------------------------------------------------------------- the steps

        public function test_a_step_of_a_user_who_is_no_superadmin_any_more_stops_before_the_source(): void
        {
            $id = $this->runIn(InstallRun::ITEMS);
            $this->source(static fn() => throw new LogicException('the source must not be asked'));
            $this->superadmins->superadmins = [];

            $this->assertSame('error ' . InstallRun::DENIED, self::runQueued());

            $this->assertSame([InstallRun::DENIED, 'user'], InstallRun::get($id)->error());
            $this->assertSame([], $this->asked);
            $this->assertSame([self::USER], $this->superadmins->asked, 'asked from the perm storage about the user of the run');
        }

        public function test_an_error_of_the_batch_stops_the_run_at_its_place(): void
        {
            $id = $this->runIn(InstallRun::ERROR);

            $run = InstallRun::get($id);
            $this->assertSame(['ITEM_TOO_BIG', 'slice-module:' . self::SLICE1 . '/item:' . self::ITEM1], $run->error());
            $this->assertSame('slice-module[' . self::SLICE1 . ']/item[' . self::ITEM1 . ']', InstallRun::pathOf($run->error()[1]));
            $this->assertSame(InstallRun::at(self::SLICE1, ''), $run->position(), 'Continue sends the batch again');
            $this->assertSame([[self::SLICE1, '', 500, $this->asked[0][3]]], $this->asked);
            $this->assertGreaterThanOrEqual(1, $this->asked[0][3], 'every answer has a timeout');
            $this->assertLessThanOrEqual(30, $this->asked[0][3], 'within the step');
        }

        /** @return array<string, array{\Throwable, string, string}> what the source throws, the error code and place */
        public static function refusals(): array
        {
            $slice = 'slice-module:' . self::SLICE1;
            return [
                'the login refused'          => [new SourceFailed(SourceFailed::LOGIN, 'refused'), SourceFailed::LOGIN, 'source'],
                'no batch of the slice'      => [ExportRefused::denied(), InstallRun::SOURCE_DENIED, $slice],
                'the source slice is gone'   => [new \RuntimeException('Unknown slice'), InstallRun::COLLECT_FAILED, $slice],
            ];
        }

        /** a step the source refuses stops the run at once - only Continue goes on */
        #[DataProvider('refusals')]
        public function test_a_refused_batch_stops_the_run_at_once(\Throwable $refused, string $code, string $where): void
        {
            $id = $this->runIn(InstallRun::ITEMS);
            $this->source(static fn() => throw $refused);

            $this->assertSame("error $code", self::runQueued());

            $this->assertSame([$code, $where], InstallRun::get($id)->error());
            $this->assertSame([], self::queue());
        }

        /** a source that did not answer is asked again later, each time for half as many items; the third failure stops the run */
        public function test_a_failed_source_is_asked_again_later_for_as_many_items(): void
        {
            $id = $this->runIn(InstallRun::ITEMS);
            $this->source(static fn() => throw new SourceFailed(SourceFailed::UNREACHABLE, 'no connection'));

            self::runQueued();
            $run = InstallRun::get($id);
            $this->assertSame(InstallRun::ITEMS, $run->state());
            $this->assertTrue($run->isWaiting(time()), 'the next try waits');
            $this->assertCount(1, self::queue());
            $this->assertGreaterThan(time(), (int) self::queue()[0]['execute_after']);

            self::runQueued();
            self::runQueued();

            $this->assertSame([500, 500, 500], array_column($this->asked, 2), 'an outage is no reason for smaller batches');
            $this->assertSame([SourceFailed::UNREACHABLE, ''], InstallRun::get($id)->error());
            $this->assertSame([], self::queue());
        }

        /** a batch too big for a step is asked at once for half the items; a source silent again may be down,
         *  so it waits as an outage does - and the run stops as a source out of time, not as a batch too big */
        public function test_a_source_out_of_time_is_asked_for_half_the_items_at_once_the_first_time(): void
        {
            $id = $this->runIn(InstallRun::ITEMS);
            $this->source(static fn() => throw new SourceFailed(SourceFailed::TIMEOUT, 'did not answer in time'));

            self::runQueued();
            $this->assertFalse(InstallRun::get($id)->isWaiting(time()), 'no wait');
            $this->assertLessThanOrEqual(time(), (int) self::queue()[0]['execute_after']);
            self::runQueued();
            $this->assertTrue(InstallRun::get($id)->isWaiting(time()), 'silent again: a wait');
            self::runQueued();

            $this->assertSame([500, 250, 125], array_column($this->asked, 2));
            $this->assertSame([SourceFailed::TIMEOUT, ''], InstallRun::get($id)->error());
            $this->assertSame('62', self::stored($id)['batch_items'], 'Continue goes on with half of the last');
        }

        // ---------------------------------------------------------------- places of errors

        /** @return array<string, array{string, string}> a package path, its place in the run */
        public static function places(): array
        {
            $s = self::SLICE1;
            $i = self::ITEM1;
            return [
                'an item value' => ["slice-module[$s]/item[$i]/headline........[0]", "slice-module:$s/item:$i/headline........:0"],
                'a slice'       => ["slice-module[$s]", "slice-module:$s"],
                'no place'      => ['source', 'source'],
            ];
        }

        #[DataProvider('places')]
        public function test_a_place_keeps_the_package_path_readable(string $path, string $where): void
        {
            $this->assertSame($where, InstallRun::where($path));
            $this->assertSame($path, InstallRun::pathOf($where));
            $this->assertMatchesRegularExpression(BatchFailed::WHERE, $where);
        }

        /** a place is stored and shown as it is: package text in it may be anything */
        public function test_a_place_keeps_only_what_an_error_place_may_hold(): void
        {
            $forged = InstallRun::where("slice-module[x]/setting-values/a\"<b>'\n%0a?q=1&t=2[1]");
            $long   = InstallRun::where(str_repeat('š', 400));

            $this->assertSame('slice-module:x/setting-values/ab0aq1t2:1', $forged);
            $this->assertMatchesRegularExpression(BatchFailed::WHERE, $long);
            $this->assertLessThanOrEqual(200, strlen($long));
            $this->assertTrue(mb_check_encoding($long, 'UTF-8'), 'no character cut in half');
            new BatchFailed('X', $forged);       // a place the core takes
        }

        // ---------------------------------------------------------------- the record as stored

        /** a run written before an upgrade must read back after it: the record as this AA stores it today */
        public function test_a_stored_record_reads_back(): void
        {
            $id      = str_repeat('e', 32);
            $finding = ['level' => 'E', 'code' => 'item-too-big', 'path' => 'slice-module[' . self::SLICE1 . ']/item[' . self::ITEM1 . ']', 'ref' => '<b>'];
            $rows    = [
                'aa_type' => InstallRun::class, 'aa_owner' => strtolower(AA_ID), 'subject' => strtolower(AA_ID), 'state' => InstallRun::ITEMS,
                'user' => self::USER, 'started' => (string) (self::NOW + 1), 'moved' => (string) (self::NOW + 60), 'finished' => '',
                'position' => json_encode(InstallRun::at(self::SLICE2, self::ITEM2)), 'step' => '4', 'closing' => '', 'lease' => '', 'lease_tok' => '',
                'failures' => '0', 'next_at' => '', 'error_code' => '', 'error_path' => '',
                'counts' => '{"' . self::SLICE1 . '":3,"' . self::SLICE2 . '":1}',
                'warnings' => '{"' . self::SLICE1 . '":{"item-field":2},"AFTER_DEAD_STEP":1}', 'progress' => '', 'report' => '{"checked":5}',
                'sealed' => '', 'sealed_until' => '',
                'mode' => InstallRun::TRANSFER, 'all_bins' => '1', 'source' => '7', 'exported' => (string) self::NOW, 'source_time' => (string) (self::NOW - 5),
                'same_aa' => '', 'fingerprint' => hash('sha256', self::DEFINITIONS), 'id_map' => self::map()->toJson(),
                'slices' => '["' . self::SLICE1 . '","' . self::SLICE2 . '"]', 'targets' => '{"' . self::SLICE1 . '":"' . str_repeat('f', 32) . '"}', 'choices' => '',
                'filters' => '{"' . self::SLICE2 . '":"d-headline........-==-B"}', 'src_counts' => '{"' . self::SLICE1 . '":3,"' . self::SLICE2 . '":"2"}',
                'findings' => json_encode([$finding]),
            ];
            foreach ($rows as $property => $value) {
                self::storedSet($id, $property, (string) $value);
            }

            $run = InstallRun::get($id);

            $this->assertSame([InstallRun::ITEMS, InstallRun::TRANSFER, true, '7', self::USER], [$run->state(), $run->mode(), $run->allBins(), $run->source(), $run->user()]);
            $this->assertSame([self::NOW, self::NOW + 60, self::NOW - 5, false], [$run->started(), $run->moved(), $run->sourceStarted(), $run->sameAa()]);
            $this->assertSame([[self::SLICE1, self::SLICE2], InstallRun::at(self::SLICE2, self::ITEM2), 4], [$run->slices(), $run->position(), $run->step()]);
            $this->assertSame([self::SLICE1 => 3, self::SLICE2 => 1], $run->written());
            $this->assertSame([self::SLICE1 => ['item-field' => 2], '' => ['AFTER_DEAD_STEP' => 1]], $run->warnings(), 'a warning of no slice under no name');
            $this->assertSame([self::SLICE1 => 3, self::SLICE2 => 2], $run->sourceCounts());
            $this->assertSame([$finding], $run->findings());
            $this->assertSame([[self::SLICE1 => str_repeat('f', 32)], [self::SLICE2 => 'd-headline........-==-B']], [$run->targets(), $run->filters()]);
            $this->assertSame([str_repeat('b', 32), '', null, ['checked' => 5]], [$run->idMap()->module(self::SLICE1), $run->token(), $run->error(), $run->report()]);
        }

        public function test_the_run_has_its_own_selector_and_text_properties_only(): void
        {
            $run   = self::preview();
            $props = InstallRun::getClassProperties();

            $this->assertSame('Install_' . $run->getId(), $run->selector());
            foreach (BatchRun::STATE_PROPS as $core) {
                $this->assertArrayHasKey($core, $props);
            }
            foreach (['mode', 'all_bins', 'source', 'exported', 'source_time', 'same_aa', 'id_map', 'fingerprint', 'slices', 'targets', 'choices', 'filters', 'src_counts', 'findings'] as $own) {
                $this->assertArrayHasKey($own, $props);
            }
            foreach ($props as $name => $property) {
                $this->assertSame('object_text', AA_Property::storageType($property->getType()), (string) $name);
                $this->assertLessThanOrEqual(16, strlen((string) $name), 'object_text.property is varbinary(16)');
            }
        }

        public function test_lockForUpdate_reads_the_record_with_a_locking_read_in_the_transaction(): void
        {
            $id = $this->runIn(InstallRun::ITEMS);
            TableDb::$log = [];

            $locked = DB_AA::transaction(static fn() => InstallRun::lockForUpdate($id));

            $this->assertSame(InstallRun::at(self::SLICE1, ''), $locked->position());
            $begin  = array_search('BEGIN', TableDb::$log, true);
            $commit = array_search('COMMIT', TableDb::$log, true);
            $reads  = array_keys(array_filter(TableDb::$log, static fn(string $sql): bool => str_contains($sql, '`object_text`') && str_ends_with($sql, ' FOR UPDATE')));
            $this->assertNotSame([], $reads, 'a locking read of the record');
            $this->assertTrue(($begin !== false) && ($commit !== false) && ($begin < min($reads)) && (max($reads) < $commit), 'inside the transaction');
        }

        public function test_unfinished_are_the_previews_items_and_errors_of_this_class(): void
        {
            $ids = array_map($this->runIn(...), [InstallRun::PREVIEW, InstallRun::ITEMS, InstallRun::ERROR, InstallRun::DONE, InstallRun::ENDED]);
            self::storedSet(str_repeat('d', 32), 'state', InstallRun::ITEMS);

            $this->assertEqualsCanonicalizing([$ids[0], $ids[1], $ids[2]], array_map(static fn(InstallRun $run): string => $run->getId(), InstallRun::unfinished()));
        }

        // ---------------------------------------------------------------- cleanup

        public function test_a_new_preview_deletes_only_previews_older_than_a_day(): void
        {
            $old_preview = self::preview()->getId();
            $new_preview = self::preview()->getId();
            $old_items   = $this->runIn(InstallRun::ITEMS);
            self::storedSet($old_preview, 'started', (string) (time() - 86400));
            self::storedSet($new_preview, 'started', (string) (time() - 86400 + 120));
            self::storedSet($old_items, 'started', (string) (time() - 2 * 86400));
            $other = str_repeat('e', 32);   // another class that has a state "preview"
            foreach (['aa_type' => 'AA\Util\Other', 'state' => InstallRun::PREVIEW, 'started' => '1'] as $property => $value) {
                self::storedSet($other, $property, $value);
            }

            $fresh = self::preview();

            $this->assertSame([], self::stored($old_preview));
            $this->assertNotSame([], self::stored($new_preview));
            $this->assertNotSame([], self::stored($old_items));
            $this->assertNotSame([], self::stored($other));
            $this->assertNotSame([], self::stored($fresh->getId()));
        }

        /** the reports of the last 100 finished runs stay; an unfinished run stays whatever its age */
        public function test_a_new_preview_keeps_the_last_hundred_finished_runs(): void
        {
            $open     = $this->runIn(InstallRun::ITEMS);
            $finished = [];
            for ($i = 1; $i <= 101; $i++) {
                $id = sprintf('%032x', 0xf000 + $i);
                foreach (['aa_type' => InstallRun::class, 'aa_owner' => strtolower(AA_ID), 'subject' => strtolower(AA_ID), 'state' => InstallRun::DONE, 'started' => '1', 'finished' => (string) (1000 + $i)] as $property => $value) {
                    TableDb::$tables['object_text'][] = ['id' => TableDb::$next['object_text']++, 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
                }
                $finished[] = $id;
            }
            self::storedSet($open, 'started', '1');

            self::preview();

            $this->assertSame([], self::stored($finished[0]), 'the oldest finished run');
            foreach (array_slice($finished, 1) as $id) {
                $this->assertNotSame([], self::stored($id));
            }
            $this->assertNotSame([], self::stored($open));
        }

        public function test_earlier_links_are_those_of_the_newest_written_run_of_the_same_source(): void
        {
            [$b, $c, $d, $e] = [str_repeat('b', 32), str_repeat('c', 32), str_repeat('d', 32), str_repeat('e', 32)];
            $slices  = [$b => 'B', $c => 'C', $d => 'D', $e => 'E'];
            $written = static function (string $source, IdMap $map, int $started): string {
                $id = InstallRun::preview(InstallRun::INSTALL, false, $source, self::USER, '', self::NOW)->getId();
                self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten($map, self::DEFINITIONS, []));
                self::storedSet($id, 'started', (string) $started);
                return $id;
            };
            $written('7', new IdMap([self::SLICE1 => $b]), self::NOW - 20);
            $written('7', new IdMap([self::SLICE2 => $d], [self::SLICE1 => $c]), self::NOW - 10);     // a link of its preview
            $written('8', new IdMap([self::SLICE2 => $e]), self::NOW);
            self::storedSet(self::preview()->getId(), 'started', (string) (self::NOW + 10));          // no map yet

            $this->assertSame([self::SLICE1 => $c, self::SLICE2 => $d], InstallRun::earlierLinks('7', [self::SLICE1, self::SLICE2, self::ITEM1], $slices));
            $this->assertSame([self::SLICE2 => $e], InstallRun::earlierLinks('8', [self::SLICE1, self::SLICE2], $slices));
            $this->assertSame([], InstallRun::earlierLinks('9', [self::SLICE1], $slices));
            $this->assertSame([self::SLICE1 => $b], InstallRun::earlierLinks('7', [self::SLICE1], [$b => 'B']), 'the newest one not on offer: an older one');
        }

        public function test_a_damaged_map_hides_only_the_links_of_its_run(): void
        {
            $b  = str_repeat('b', 32);
            $id = InstallRun::preview(InstallRun::INSTALL, false, '7', self::USER, '', self::NOW)->getId();
            self::locked($id, static fn(InstallRun $run) => $run->definitionsWritten(new IdMap([self::SLICE1 => $b]), self::DEFINITIONS, []));
            self::storedSet($id, 'started', (string) (self::NOW - 10));
            $bad = InstallRun::preview(InstallRun::INSTALL, false, '7', self::USER, '', self::NOW)->getId();
            self::storedSet($bad, 'id_map', '{"modules":');

            $this->assertSame([self::SLICE1 => $b], InstallRun::earlierLinks('7', [self::SLICE1], [$b => 'B']));
        }
    }
}
