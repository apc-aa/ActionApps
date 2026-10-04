<?php

declare(strict_types=1);

// AA_Object (include/statestore.php) and the queue need the config constants include/config.php gives

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 4) . '/',
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

namespace Tests\Unit\Later\Fixtures {

    use AA\IO\DB\DB_AA;
    use AA\Later\BatchStep;
    use LogicException;
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';
    require_once __DIR__ . '/ScriptedBatchRun.php';
    require_once __DIR__ . '/QueueRefusingDb.php';
    require_once __DIR__ . '/BothStepModes.php';

    /** A ScriptedBatchRun over TableDb's object_text and toexecute, and the ways a test reads and changes them. */
    trait BatchRunTables
    {
        /** after the real clock, so the queue keeps the times the run asks for */
        private const NOW     = 1_900_000_000;
        private const SUBJECT = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';
        private const USER    = 'uid-1';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../../include/constants.php';
            require_once __DIR__ . '/../../../../include/util.php';
            require_once __DIR__ . '/../../Central/Package/fixtures/TableDb.php';
        }

        protected function setUp(): void
        {
            TableDb::reset();
            TableDb::$engines    = ['object_text' => 'InnoDB', 'toexecute' => 'InnoDB'];
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
            ScriptedBatchRun::reset();
            ScriptedBatchRun::$now      = self::NOW;
            ScriptedBatchRun::$microNow = (float) self::NOW;
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
            ScriptedBatchRun::reset();
            QueueRefusingDb::$refused = 0;
            set_time_limit(0);   // a task later() runs at once sets a 30 s limit - it must not outlive the test
        }

        /** a run started as its users start it - in their own transaction */
        private static function start(?string $state = null, array $props = []): ScriptedBatchRun
        {
            return DB_AA::transaction(static fn() => ScriptedBatchRun::start($props + ['subject' => self::SUBJECT, 'user' => self::USER, 'position' => ['n' => 0], 'note' => 'mine'], $state));
        }

        /** @return array<string, string> property => value of the stored record */
        private static function stored(string $id): array
        {
            $rows = array_filter(TableDb::rows('object_text'), static fn(array $r): bool => $r['object_id'] === $id);
            return array_map('strval', array_column($rows, 'value', 'property'));
        }

        /** changes a stored property behind the run's back, as another process would */
        private static function storedSet(string $id, string $property, string $value): void
        {
            foreach (TableDb::$tables['object_text'] as $i => $row) {
                if (($row['object_id'] === $id) && ($row['property'] === $property)) {
                    TableDb::$tables['object_text'][$i]['value'] = $value;
                    return;
                }
            }
            TableDb::$tables['object_text'][] = ['id' => 999, 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
        }

        /** @return list<array<string, mixed>> the queue rows */
        private static function queue(): array
        {
            return TableDb::rows('toexecute');
        }

        private static function stepOf(array $row): BatchStep
        {
            $step = unserialize((string) $row['object']);
            self::assertInstanceOf(BatchStep::class, $step);
            return $step;
        }

        /** runs the oldest queued step as the queue does: the row goes away after the step */
        private static function runQueued(): string
        {
            $row = self::queue()[0] ?? throw new LogicException('Nothing queued');
            $ret = self::stepOf($row)->toexecutelater();
            TableDb::$tables['toexecute'] = array_values(array_filter(TableDb::rows('toexecute'), static fn(array $r): bool => $r['id'] !== $row['id']));
            return $ret;
        }
    }
}
