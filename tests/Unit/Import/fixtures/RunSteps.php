<?php

declare(strict_types=1);

namespace Tests\Unit\Import\Fixtures;

use AA;
use AA\Cache\PageCache;
use AA\Import\Definition;
use AA\Import\Run;
use AA\Import\Stream;
use AA\Import\StreamState;
use AA\IO\DB\DB_AA;
use AA_Field;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Legacy\Fixtures\FakeFields;
use Tests\Unit\Legacy\Fixtures\FakeSlice;

// the in-memory object_text and toexecute, the config constants, FakeSlice and the stored() helpers of the batch run tests
require_once __DIR__ . '/../../Later/Fixtures/BatchRunTables.php';

/**
 * Run steps through the queue for the tests of AA\Import\Run: a definition of SLICE over a
 * ScriptedSource, savers that record what they would store, the page cache invalidations in
 * $invalidated. Each step's budget is the core's - 27 s outside a queue round - and each item
 * costs TestRun::$itemSeconds of the run's clock.
 */
trait RunSteps
{
    use BatchRunTables {
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const SLICE  = '5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b';
    private const OTHER  = '6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c';
    private const DEF    = '0123456789abcdef0123456789abcdef';
    private const FIELDS = ['headline........', 'relation........', 'text...........1', 'text...........2'];
    /** a mapping a job without parameters can use */
    private const NO_PARAM_MAPPING = '{"rows":[{"to":"relation........","t":"copyfield","p":{"field2copy":"key"}}]}';

    /** @var list<array{array<string>|string, string}> invalidateFor() calls of the page cache */
    private static array $invalidated = [];

    protected function setUp(): void
    {
        $this->tablesSetUp();
        // loaded per test, in its own process: the run's classes reach include/locsess.php
        require_once __DIR__ . '/TestRun.php';
        require_once __DIR__ . '/ProbedSource.php';
        require_once __DIR__ . '/CheckedSource.php';
        TestRun::reset();
        TestRun::$now      = self::NOW;
        TestRun::$microNow = (float) self::NOW;
        foreach ([self::SLICE, self::OTHER] as $slice_id) {
            $slice  = new FakeSlice($slice_id);
            $fields = array_map(static fn(string $id): AA_Field => new AA_Field([
                'id'                => $id,
                'slice_id'          => $slice_id,
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld',
                'input_insert_func' => 'qte',
                'input_validate'    => '',
                'content_edit'      => 0,
                'html_default'      => 0,
                'multiple'          => 0,
            ]), self::FIELDS);
            $slice->setFields(new FakeFields($slice_id, $fields));
        }
        self::perms(PS_FEEDING . PS_EDIT_ALL_ITEMS, PS_EDIT_ALL_ITEMS);
        self::$invalidated = [];
        $cache         = new class extends PageCache {
            public ?\Closure $record = null;

            public function invalidateFor($strings, $type = 'M', ?int $stored_before = null): int
            {
                ($this->record)($strings, $type);
                return 0;
            }
        };
        $cache->record = static function (mixed $strings, string $type): void {
            self::$invalidated[] = [$strings, $type];
        };
        (new \ReflectionProperty(AA::class, 'pagecache'))->setValue(null, $cache);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(AA::class, 'pagecache'))->setValue(null, null);
        TestRun::reset();
        $this->tablesTearDown();
        unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
    }

    private static function perms(string $own, string $other): void
    {
        $GLOBALS['permission_uid'] = AuthGetUid();
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => $own, self::OTHER => $other]];
    }

    private static function sorted(?array $position): array
    {
        $position = (array) $position;
        ksort($position);
        return $position;
    }

    /** a cursor already on $job, the stream's first, as a step before left it */
    private static function onJob(string $job): array
    {
        return ['job' => $job, 'pages' => 1, 'cur' => sha1($job)] + Run::START;
    }

    /** a source of $streams streams s1.. of $jobs jobs a, b, .. of $items items each - $source when given */
    private static function source(int $streams, int $jobs, int $items, float $seconds = 0.0, bool $probed = false, ?ScriptedSource $source = null): ScriptedSource
    {
        $source             ??= $probed ? new ProbedSource() : new ScriptedSource();
        TestRun::$itemSeconds = $seconds;
        for ($s = 1; $s <= $streams; $s++) {
            $source->streams[] = new Stream("s$s", "Stream $s", ['fld' => 'text...........1']);
            for ($j = 0; $j < $jobs; $j++) {
                $key = "s$s:" . chr(ord('a') + $j);
                $source->jobs["s$s"][] = ['key' => $key, 'items' => array_map(static fn(int $n): array => ['key' => "k-$key-$n", 'val' => "v-$key-$n"], ($items > 0) ? range(1, $items) : [])];
            }
        }
        TestRun::$source = $source;
        return $source;
    }

    /** @return list<string> the keys of every item of source($streams, $jobs, $items), in order */
    private static function keys(int $streams, int $jobs, int $items): array
    {
        $keys = [];
        for ($s = 1; $s <= $streams; $s++) {
            for ($j = 0; $j < $jobs; $j++) {
                for ($n = 1; $n <= $items; $n++) {
                    $keys[] = "k-s$s:" . chr(ord('a') + $j) . "-$n";
                }
            }
        }
        return $keys;
    }

    /**
     * A stored definition of SLICE and a run of it, started as Service starts one.
     * @param array<string, string> $props of the run
     * @param array<string, string> $definition properties of the definition
     */
    private static function startRun(ScriptedSource $source, array $props = [], array $definition = []): TestRun
    {
        $def = new Definition();
        $def->setNew(self::DEF, self::SLICE, 'prices');
        $definition += [
            'source'     => 'custom:scripted',
            'mapping'    => '{"rows":[{"to":"relation........","t":"copyfield","p":{"field2copy":"key"}},{"to":"param:fld","t":"copyfield","p":{"field2copy":"val"}}]}',
            'key_fields' => '["relation........"]',
            'store_mode' => 'update/insert',
            'enabled'    => '1',
        ];
        foreach ($definition as $property => $value) {
            $def->setProperty($property, $value);
        }
        self::assertSame(self::DEF, $def->save());
        $keys = array_map(static fn(Stream $s): string => $s->key, $source->streams);

        return DB_AA::transaction(static fn() => TestRun::start($props + [
            'subject'     => self::DEF,
            'user'        => self::USER,
            'position'    => Run::START,
            'mode'        => 'run',
            'dry'         => '',
            'limit'       => '',
            'snapshot'    => json_encode($def->snapshot(), JSON_THROW_ON_ERROR),
            'stream_keys' => json_encode($keys, JSON_THROW_ON_ERROR),
            'trigger'     => 'manual',
        ]));
    }

    /** @param array<string, string> $versions stream key => version stored for the definition */
    private static function streamVersions(array $versions): void
    {
        $state = StreamState::create(self::DEF);
        foreach ($versions as $key => $version) {
            $state->record($key, ['v' => $version]);
        }
        $state->store();
    }

    /** the queued step due first, run as the queue runs it - at its time, with a fresh step clock */
    private static function runOne(): string
    {
        $rows = self::queue();
        usort($rows, static fn(array $a, array $b): int => [(int) $a['execute_after'], (int) $a['id']] <=> [(int) $b['execute_after'], (int) $b['id']]);
        $row = $rows[0] ?? throw new \LogicException('Nothing queued');
        TestRun::$now      = max((int) TestRun::$now, (int) $row['execute_after']);
        TestRun::$microNow = (float) TestRun::$now;
        $ret               = self::stepOf($row)->toexecutelater();
        TableDb::$tables['toexecute'] = array_values(array_filter(self::queue(), static fn(array $r): bool => $r['id'] !== $row['id']));
        return $ret;
    }

    /** @return list<string> what each step said, till the run is finished */
    private static function runAll(string $id, int $max = 30): array
    {
        $said = [];
        while (in_array(TestRun::get($id)?->state(), ['running'], true) && self::queue() && (count($said) < $max)) {
            $said[] = self::runOne();
        }
        return $said;
    }

    /** @return array{string, string} the state and the error code of the run */
    private static function errorOf(string $id): array
    {
        $stored = self::stored($id);
        return [$stored['state'], $stored['error_code'] ?? ''];
    }
}
