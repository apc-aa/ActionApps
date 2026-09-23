<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Definition_Module;
use AA_Definition_Module_Chunk;
use AA_Definition_Module_Slice;
use AA_Task_Import_Module_Chunk;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * Central - Copy Slice (central/copyslice2.php) imports one template definition into several
 * destination AAs, each time with freshly generated module ids. The definition object is shared
 * by all destinations, so the id replacement must not consume it. No DB: the metabase is a fake
 * which records the inserts, the toexecute queue a RecordingDb.
 */
final class CentralCopySliceTest extends TestCase
{
    private const TEMPLATE_ID = '0123456789abcdef0123456789abcdef';
    private const FIRST_ID    = '11111111111111111111111111111111';
    private const SECOND_ID   = '22222222222222222222222222222222';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';        // recursive_array_replace()
        require_once __DIR__ . '/fixtures/store_fixtures.php';       // RecordingDb
        require_once __DIR__ . '/../../../include/varset.php';       // Cvarset of Toexecute::later()
    }

    /** AA::$metabase before a test replaced it; false = not replaced */
    private mixed $saved_metabase = false;

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        if ($this->saved_metabase !== false) {
            (new \ReflectionProperty(\AA::class, 'metabase'))->setValue(null, $this->saved_metabase);
            $this->saved_metabase = false;
        }
    }

    /** Metabase which records the inserts instead of writing; rows named 'bad' fail. */
    private function fakeMetabase(): \AA_Metabase
    {
        $property             = new \ReflectionProperty(\AA::class, 'metabase');
        $this->saved_metabase = $property->getValue();
        $metabase             = new class extends \AA_Metabase {
            /** @var list<array{string, array}> */
            public array $inserted = [];

            public function getKeys($tablename): array
            {
                return $tablename === 'field' ? ['id', 'slice_id'] : ['id'];   // order as in metabase_schema.php
            }

            public function doInsert(string $tablename, array $data, ?string $nohalt = null): bool|int
            {
                $this->inserted[] = [$tablename, $data];
                return ($data['name'] ?? '') !== 'bad';
            }
        };
        $property->setValue(null, $metabase);
        return $metabase;
    }

    /** @return array<string, array<string, array<string, string>>> */
    private static function templateData(): array
    {
        return [
            'module' => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'News']],
            'field'  => ['headline........' => ['id' => 'headline........', 'slice_id' => self::TEMPLATE_ID, 'name' => 'Headline']],
        ];
    }

    private static function template(): AA_Definition_Module_Slice
    {
        $def = new AA_Definition_Module_Slice();
        (new \ReflectionProperty($def, 'module_id'))->setValue($def, self::TEMPLATE_ID);
        (new \ReflectionProperty($def, 'data'))->setValue($def, self::templateData());
        return $def;
    }

    /** @param AA_Definition_Module_Chunk[] $chunks
     *  @return list<string> "table:slice id" of each row the chunks would write */
    private static function sliceIdsOfRows(array $chunks): array
    {
        $ids = [];
        foreach ($chunks as $chunk) {
            foreach ((new \ReflectionProperty($chunk, 'data'))->getValue($chunk) as $table => $rows) {
                foreach ($rows as $row) {
                    $ids[] = $table . ':' . ($row['slice_id'] ?? $row['id']);
                }
            }
        }
        return $ids;
    }

    public function test_getChunks_replaces_ids_per_call_and_keeps_the_definition(): void
    {
        $def        = self::template();
        $get_chunks = new \ReflectionMethod(AA_Definition_Module::class, '_getChunks');

        $first  = $get_chunks->invoke($def, [self::TEMPLATE_ID => self::FIRST_ID]);
        $second = $get_chunks->invoke($def, [self::TEMPLATE_ID => self::SECOND_ID]);

        $this->assertSame(['module:' . self::FIRST_ID, 'field:' . self::FIRST_ID], self::sliceIdsOfRows($first));
        $this->assertSame(['module:' . self::SECOND_ID, 'field:' . self::SECOND_ID], self::sliceIdsOfRows($second), 'second destination got the ids of the first one');
        $this->assertSame(self::templateData(), $def->getArray(), 'the template definition must stay untouched');
    }

    public function test_importModuleChunk_reports_ok_and_failed_rows(): void
    {
        $this->fakeMetabase();
        $chunk = new AA_Definition_Module_Chunk(self::TEMPLATE_ID, 'field', [
            ['id' => 'headline........', 'slice_id' => self::FIRST_ID, 'name' => 'Headline'],
            ['id' => 'text...........1', 'slice_id' => self::FIRST_ID, 'name' => 'bad'],
            ['id' => 'text...........2', 'slice_id' => self::FIRST_ID, 'name' => 'Text'],
        ]);

        $this->assertSame([
            'OK: 2 field ([headline........,' . self::FIRST_ID . '][text...........2,' . self::FIRST_ID . ']); ',
            'Err: 1 field ([text...........1,' . self::FIRST_ID . ']); ',
        ], $chunk->importModuleChunk());
    }

    public function test_importModuleChunk_all_ok_has_no_err_line(): void
    {
        $this->fakeMetabase();
        $chunk = new AA_Definition_Module_Chunk(self::TEMPLATE_ID, 'module', [['id' => self::FIRST_ID, 'name' => 'News']]);

        $this->assertSame(['OK: 1 module ([' . self::FIRST_ID . ']); '], $chunk->importModuleChunk());
    }

    public function test_importModuleChunk_all_failed_has_no_ok_line(): void
    {
        $this->fakeMetabase();
        $chunk = new AA_Definition_Module_Chunk(self::TEMPLATE_ID, 'module', [['id' => self::FIRST_ID, 'name' => 'bad']]);

        $this->assertSame(['Err: 1 module ([' . self::FIRST_ID . ']); '], $chunk->importModuleChunk());
    }

    public function test_importModuleChunk_empty_chunk_reports_nothing(): void
    {
        $metabase = $this->fakeMetabase();

        $this->assertSame([], (new AA_Definition_Module_Chunk(self::TEMPLATE_ID, 'field', []))->importModuleChunk());
        $this->assertSame([], $metabase->inserted);
    }

    public function test_moduleImport_returns_the_report_of_each_destination(): void
    {
        $metabase = $this->fakeMetabase();
        $def      = self::template();

        $first  = $def->moduleImport([self::TEMPLATE_ID => self::FIRST_ID]);
        $second = $def->moduleImport([self::TEMPLATE_ID => self::SECOND_ID]);

        $this->assertSame([
            'OK: 1 module ([' . self::FIRST_ID . ']); ',
            'OK: 1 field ([headline........,' . self::FIRST_ID . ']); ',
        ], $first);
        $this->assertSame([
            'OK: 1 module ([' . self::SECOND_ID . ']); ',
            'OK: 1 field ([headline........,' . self::SECOND_ID . ']); ',
        ], $second);
        $this->assertSame(
            [self::FIRST_ID, self::FIRST_ID, self::SECOND_ID, self::SECOND_ID],
            array_map(static fn(array $insert): string => $insert[1]['slice_id'] ?? $insert[1]['id'], $metabase->inserted),
        );
    }

    public function test_planModuleImport_queues_each_destination_with_its_own_ids(): void
    {
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $def = self::template();
        $aa  = new \AA_Actionapps(new \ItemContent());

        $this->assertSame(2, $def->planModuleImport($aa, [self::TEMPLATE_ID => self::FIRST_ID]));
        $this->assertSame(2, $def->planModuleImport($aa, [self::TEMPLATE_ID => self::SECOND_ID]));

        $chunks = [];
        foreach (RecordingDb::inserts('toexecute') as $row) {
            $task     = unserialize($row['object']);
            $this->assertInstanceOf(AA_Task_Import_Module_Chunk::class, $task);
            $chunks[] = (new \ReflectionProperty($task, 'module_definition_chunk'))->getValue($task);
        }
        $this->assertSame(
            ['module:' . self::FIRST_ID, 'field:' . self::FIRST_ID, 'module:' . self::SECOND_ID, 'field:' . self::SECOND_ID],
            self::sliceIdsOfRows($chunks),
        );
    }

    /** @return array<string, array{mixed, string}> */
    public static function remoteImportAnswers(): array
    {
        return [
            'report lines' => [['OK: 1 module ([x]); ', ['nested'], 'Err: 1 field ([y]); '], "OK: 1 module ([x]); \nErr: 1 field ([y]); "],
            'no rows'      => [[], ''],
            'error'        => ['Connection refused', 'Connection refused'],
        ];
    }

    /** Toexecute keeps a task whose toexecutelater() throws and runs it again - the import would be doubled. */
    #[\PHPUnit\Framework\Attributes\DataProvider('remoteImportAnswers')]
    public function test_task_import_module_chunk_logs_the_remote_report(mixed $answer, string $expected): void
    {
        $aa = new class ($answer) extends \AA_Actionapps {
            public function __construct(private mixed $answer) {}

            public function importModuleChunk(AA_Definition_Module_Chunk $definition_chunk)
            {
                return $this->answer;
            }
        };
        $chunk = new AA_Definition_Module_Chunk(self::FIRST_ID, 'module', []);

        $this->assertSame($expected, (new AA_Task_Import_Module_Chunk($chunk, $aa))->toexecutelater());
    }
}
