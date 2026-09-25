<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Definition_Module;
use AA_Definition_Module_Alerts;
use AA_Definition_Module_Chunk;
use AA_Definition_Module_Site;
use AA_Definition_Module_Slice;
use AA_Difference;
use AA_Sync_Action;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Fixtures\FakeChunkTarget;
use Tests\Unit\Central\Fixtures\RecordingMetabase;
use Tests\Unit\Legacy\Fixtures\CentralTestHelpers;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

// class declarations only - file level, since the data providers below build these objects
require_once dirname(__DIR__, 3) . '/central/include/actionapps.class.php';
require_once __DIR__ . '/fixtures/central_fixtures.php';   // the trait must exist before the class

/**
 * Characterization of the module definitions Central copies and compares between AAs
 * (AA_Definition_Module and its Slice / Site / Alerts kinds): what loadForId() reads, the shape
 * of the loaded data, compareWith() per kind, the chunking of an import, and the serialized form
 * which travels between installations (Get_Module_Defs answer, toexecute queue).
 * No DB: RecordingDb answers the SELECTs (see CentralSynchronizeTest for the same scaffolding).
 *
 * Pins today's behaviour ahead of the move to src/Central/; "pinned; phase 4 may change" marks
 * behaviour that is questionable rather than wanted.
 */
final class CentralDefinitionModuleTest extends TestCase
{
    use CentralTestHelpers;

    private const TEMPLATE_ID = '0123456789abcdef0123456789abcdef';
    private const DEST_ID     = 'fedcba9876543210fedcba9876543210';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';    // _m(), safe(), recursive_array_replace()
        require_once __DIR__ . '/fixtures/store_fixtures.php';   // RecordingDb
        require_once __DIR__ . '/../../../include/varset.php';   // Cvarset::sqlin() of loadForId()
        require_once __DIR__ . '/../Central/fixtures/ChunkFixtures.php';   // FakeChunkTarget
    }

    /** this AA as the chunk mode (ChunkImporter, C4.4 part 6) sees it: the template and the destination
     *  slices exist, no view number is taken */
    private static function target(): FakeChunkTarget
    {
        return new FakeChunkTarget([self::TEMPLATE_ID => 'S', self::DEST_ID => 'S']);
    }

    protected function setUp(): void
    {
        RecordingDb::reset();   // other tests leave their statements and answers behind
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        $this->restoreMetabase();
        $this->removeLocalResponder();
    }

    private static function def(string $class, ?string $id, array $data): AA_Definition_Module
    {
        $def = new $class();
        (new \ReflectionProperty(AA_Definition_Module::class, 'module_id'))->setValue($def, $id);
        (new \ReflectionProperty(AA_Definition_Module::class, 'data'))->setValue($def, $data);
        return $def;
    }

    /** The statements sent, with the packed module id shown as {ID} */
    private static function log(): array
    {
        return array_map(static fn(string $sql): string => str_replace(q_pack_id(self::TEMPLATE_ID), '{ID}', $sql), RecordingDb::$log);
    }

    private static function load(AA_Definition_Module $def, mixed $limited): void
    {
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $def->loadForId(self::TEMPLATE_ID, $limited);
    }

    // --- loadForId() -----------------------------------------------------------------------------

    private const SLICE_DEFINITION_SQL = [
        "SELECT module.* FROM module  WHERE module.id = '{ID}'",
        "SELECT slice.* FROM slice  WHERE slice.id = '{ID}'",
        "SELECT field.* FROM field  WHERE field.slice_id = '{ID}'",
        "SELECT view.* FROM view  WHERE view.slice_id = '{ID}'",
        "SELECT email.* FROM email  WHERE email.owner_module_id = '{ID}'",
        "SELECT email_notify.* FROM email_notify  WHERE email_notify.slice_id = '{ID}'",
        "SELECT profile.* FROM profile  WHERE profile.slice_id = '{ID}'",
        "SELECT rssfeeds.* FROM rssfeeds  WHERE rssfeeds.slice_id = '{ID}'",
        "SELECT constant_slice.* FROM constant_slice  WHERE constant_slice.slice_id = '{ID}'",
        "SELECT constant.* FROM constant INNER JOIN constant_slice ON constant.group_id=constant_slice.group_id WHERE constant_slice.slice_id = '{ID}'",
        // no constants: the group names are still asked for, with a condition that never holds
        "SELECT * FROM constant WHERE group_id='lt_groupNames' AND 2=1",
    ];

    private const SLICE_ITEMS_SQL = [
        "SELECT item.* FROM item  WHERE item.slice_id = '{ID}'",
        "SELECT content.* FROM content INNER JOIN item ON content.item_id=item.id WHERE item.slice_id = '{ID}'",
        "SELECT discussion.* FROM discussion INNER JOIN item ON discussion.item_id=item.id WHERE item.slice_id = '{ID}'",
    ];

    private const SITE_SQL = [
        "SELECT module.* FROM module  WHERE module.id = '{ID}'",
        "SELECT site.* FROM site  WHERE site.id = '{ID}'",
        "SELECT site_spot.* FROM site_spot  WHERE site_spot.site_id = '{ID}'",
    ];

    private const ALERTS_SQL = [
        "SELECT module.* FROM module  WHERE module.id = '{ID}'",
        "SELECT alerts_collection.* FROM alerts_collection  WHERE alerts_collection.module_id = '{ID}'",
        "SELECT alerts_collection_filter.* FROM alerts_collection_filter INNER JOIN alerts_collection ON alerts_collection_filter.collectionid=alerts_collection.id WHERE alerts_collection.module_id = '{ID}'",
        "SELECT alerts_collection_howoften.* FROM alerts_collection_howoften INNER JOIN alerts_collection ON alerts_collection_howoften.collectionid=alerts_collection.id WHERE alerts_collection.module_id = '{ID}'",
    ];

    /** @return array<string, array{class-string<AA_Definition_Module>, mixed, list<string>}> */
    public static function loads(): array
    {
        return [
            'slice definitions'       => [AA_Definition_Module_Slice::class, ['definitions' => true], self::SLICE_DEFINITION_SQL],
            'slice legacy true'       => [AA_Definition_Module_Slice::class, true, self::SLICE_DEFINITION_SQL],
            'slice items'             => [AA_Definition_Module_Slice::class, ['items' => true], self::SLICE_ITEMS_SQL],
            'slice both'              => [AA_Definition_Module_Slice::class, ['definitions' => true, 'items' => true], [...self::SLICE_DEFINITION_SQL, ...self::SLICE_ITEMS_SQL]],
            'slice nothing'           => [AA_Definition_Module_Slice::class, [], []],
            'slice false'             => [AA_Definition_Module_Slice::class, false, []],
            // Site and Alerts ignore $limited - they always load everything they know
            'site'                    => [AA_Definition_Module_Site::class, false, self::SITE_SQL],
            'site, items only'        => [AA_Definition_Module_Site::class, ['items' => true], self::SITE_SQL],
            'alerts'                  => [AA_Definition_Module_Alerts::class, false, self::ALERTS_SQL],
            'alerts, definitions'     => [AA_Definition_Module_Alerts::class, ['definitions' => true], self::ALERTS_SQL],
            'base class'              => [AA_Definition_Module::class, true, []],
        ];
    }

    #[DataProvider('loads')]
    public function test_loadForId_reads_the_tables_of_its_kind(string $class, mixed $limited, array $sql): void
    {
        $def = new $class();
        self::load($def, $limited);

        $this->assertSame($sql, self::log());
    }

    #[DataProvider('loads')]
    public function test_loadForId_keeps_every_table_read_even_empty(string $class, mixed $limited, array $sql): void
    {
        $def = new $class();
        self::load($def, $limited);

        $tables = [];
        foreach ($sql as $statement) {
            if (preg_match('/^SELECT (\w+)\.\* FROM/', $statement, $m)) {
                $tables[] = $m[1];
            }
        }
        $this->assertSame(array_fill_keys($tables, []), $def->getArray(), 'one key per table read, in order, [] when no row');
    }

    public function test_loadForId_of_a_kind_keeps_the_id(): void
    {
        foreach ([AA_Definition_Module_Slice::class, AA_Definition_Module_Site::class, AA_Definition_Module_Alerts::class] as $class) {
            $def = new $class();
            self::load($def, ['definitions' => true]);
            $this->assertSame(self::TEMPLATE_ID, $def->getId(), $class);
        }
    }

    public function test_loadForId_of_the_base_class_loses_the_id(): void
    {
        $def = new AA_Definition_Module();
        $def->loadForId(self::TEMPLATE_ID, true);

        // pinned; phase 4 may change - it sets the id and then clear()s it
        $this->assertNull($def->getId());
        $this->assertSame([], $def->getArray());
    }

    public function test_loadForId_forgets_what_was_loaded_before(): void
    {
        $def = self::def(AA_Definition_Module_Slice::class, self::DEST_ID, ['field' => ['x' => ['id' => 'x']]]);
        self::load($def, []);

        $this->assertSame([], $def->getArray());
        $this->assertSame(self::TEMPLATE_ID, $def->getId());
    }

    public function test_loadForId_keys_rows_by_unpacked_id_and_unpacks_packed_columns(): void
    {
        $packed = pack_id(self::TEMPLATE_ID);
        RecordingDb::$answer = static fn(string $sql): array => match (true) {
            str_starts_with($sql, 'SELECT module.*') => [['id' => $packed, 'name' => 'News', 'type' => 'S']],
            str_starts_with($sql, 'SELECT field.*')  => [
                ['id' => 'headline........', 'slice_id' => $packed, 'name' => 'Headline'],
                ['id' => 'text...........1', 'slice_id' => $packed, 'name' => 'Text'],
            ],
            str_starts_with($sql, 'SELECT view.*')   => [['id' => 12, 'slice_id' => $packed, 'name' => 'List']],
            default                                  => [],
        };
        $def = new AA_Definition_Module_Slice();
        self::collectingWarnings(static fn() => self::load($def, ['definitions' => true]), $warnings);
        $data = $def->getArray();

        // pinned; phase 4 may change - a packed column the row does not have comes back as '' (module.owner)
        $this->assertSame([self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'News', 'type' => 'S', 'owner' => '']], $data['module']);
        $this->assertCount(2, $warnings);   // the missing column is read, then unpacked as null
        $this->assertStringContainsString('owner', $warnings[0]);
        $this->assertSame(['headline........', 'text...........1'], array_keys($data['field']));
        $this->assertSame(self::TEMPLATE_ID, $data['field']['headline........']['slice_id']);
        $this->assertSame([12 => ['id' => 12, 'slice_id' => self::TEMPLATE_ID, 'name' => 'List']], $data['view']);
    }

    // --- getId(), getName(), getArray() ----------------------------------------------------------

    public function test_new_definition_is_empty(): void
    {
        foreach ([AA_Definition_Module::class, AA_Definition_Module_Slice::class, AA_Definition_Module_Site::class, AA_Definition_Module_Alerts::class] as $class) {
            $def = new $class();
            $this->assertNull($def->getId(), $class);
            $this->assertSame([], $def->getArray(), $class);
        }
    }

    public function test_getName_reads_module_name_directly_under_the_table(): void
    {
        $this->assertSame('News', self::def(AA_Definition_Module_Slice::class, self::TEMPLATE_ID, ['module' => ['name' => 'News']])->getName());
    }

    public function test_getName_of_a_loaded_definition_is_null(): void
    {
        // pinned; phase 4 may change - loadForId() keys the module row by id ([module][id][name]),
        // getName() reads [module][name]: null and a warning (no caller uses it today)
        $def = self::def(AA_Definition_Module_Slice::class, self::TEMPLATE_ID, ['module' => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'News']]]);
        $name = self::collectingWarnings(static fn() => $def->getName(), $warnings);

        $this->assertNull($name);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('name', $warnings[0]);
    }

    // --- compareWith() per kind ------------------------------------------------------------------

    /** @return array<string, array{class-string<AA_Definition_Module>}> */
    public static function kindsWithoutCompare(): array
    {
        return [
            'base'   => [AA_Definition_Module::class],
            'site'   => [AA_Definition_Module_Site::class],
            'alerts' => [AA_Definition_Module_Alerts::class],
        ];
    }

    #[DataProvider('kindsWithoutCompare')]
    public function test_compareWith_is_a_no_op_except_for_slices(string $class): void
    {
        $data     = ['module' => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'A']]];
        $template = self::def($class, self::TEMPLATE_ID, $data);

        // pinned; phase 4 may change - only slices can be synchronized; the others answer null
        $this->assertNull($template->compareWith(self::def($class, self::DEST_ID, [])));
        $this->assertNull($template->compareWith(null));
    }

    /** @param AA_Difference[] $diffs
     *  @return list<string> each difference as "type: description" (tags stripped) */
    private static function describe(array $diffs): array
    {
        $rows = [];
        foreach ($diffs as $diff) {
            ob_start();
            $diff->printOut();
            $html = (string) ob_get_clean();
            preg_match('/class="diff_(\w+)"><td>(.*?)<\/td>/s', $html, $m);
            $rows[] = $m[1] . ': ' . html_entity_decode(strip_tags($m[2]));
        }
        return $rows;
    }

    private static function slice(string $id, array $fields = []): AA_Definition_Module_Slice
    {
        return self::def(AA_Definition_Module_Slice::class, $id, [
            'module' => [$id => ['id' => $id, 'name' => 'News']],
            'slice'  => [$id => ['id' => $id, 'name' => 'News']],
            'field'  => $fields,
        ]);
    }

    public function test_compareWith_identical_slices_gives_one_info_row_per_synchronized_table(): void
    {
        $field = static fn(string $slice_id): array => ['headline........' => ['id' => 'headline........', 'slice_id' => $slice_id, 'name' => 'Headline']];
        $diffs = self::slice(self::TEMPLATE_ID, $field(self::TEMPLATE_ID))->compareWith(self::slice(self::DEST_ID, $field(self::DEST_ID)));

        $d = self::DEST_ID;
        $this->assertSame([
            "info: {$d}->module->{$d} are identical",
            "info: {$d}->slice->{$d} are identical",
            "info: {$d}->field->headline........ are identical",   // slice_id is the module column - not compared
            "info: {$d}->view is not defined for both AAs",
            "info: {$d}->email is not defined for both AAs",
            "info: {$d}->constant_slice is not defined for both AAs",
            "info: {$d}->constant is not defined for both AAs",
        ], self::describe($diffs));
        $this->assertCount(count(AA_Sync_Action::TABLES), $diffs);
    }

    public function test_compareWith_uses_the_destination_id_in_every_identifier(): void
    {
        $template = self::slice(self::TEMPLATE_ID, ['headline........' => ['id' => 'headline........', 'name' => 'New']]);
        $dest     = self::slice(self::DEST_ID, ['headline........' => ['id' => 'headline........', 'name' => 'Old']]);

        $diffs       = $template->compareWith($dest);
        $identifiers = [];
        foreach ($diffs as $diff) {
            foreach ((new \ReflectionProperty($diff, 'actions'))->getValue($diff) as $action) {
                $identifiers[] = (new \ReflectionProperty($action, 'identifier'))->getValue($action)->toString();
            }
        }

        $row = self::DEST_ID . '->field->headline........';
        $this->assertSame([$row, "{$row}->name"], $identifiers, 'the whole row, then the one value');
        $this->assertSame("different_row: The record for $row is different", self::describe($diffs)[2]);
    }

    public function test_compareWith_table_missing_in_destination_offers_an_insert_the_remote_refuses(): void
    {
        // a destination without the `field` key (not an empty array) - the whole table is one INSERT without a row
        $template = self::slice(self::TEMPLATE_ID, ['headline........' => ['id' => 'headline........', 'name' => 'Headline']]);
        $dest     = self::def(AA_Definition_Module_Slice::class, self::DEST_ID, []);

        $inserts = [];
        foreach ($template->compareWith($dest) as $diff) {
            foreach ((new \ReflectionProperty($diff, 'actions'))->getValue($diff) as $action) {
                $inserts[] = $action;
            }
        }
        $identifiers = array_map(static fn($a) => (new \ReflectionProperty($a, 'identifier'))->getValue($a)->toString(), $inserts);
        $this->assertSame([self::DEST_ID . '->module->' . self::DEST_ID, self::DEST_ID . '->slice->' . self::DEST_ID, self::DEST_ID . '->field'], $identifiers);

        // pinned; phase 4 may change - offered pre-checked as "Create new", but doAction() needs a row
        $this->assertSame('Wrong command - row is not defined - ' . self::DEST_ID . '->field', $inserts[2]->doAction());
    }

    // --- _getChunks() seen through planModuleImport() and moduleImport() -----------------------

    /** AA::$metabase before a test replaced it; false = not replaced */
    private mixed $saved_metabase = false;

    /** Metabase which records the inserts of moduleImport() instead of writing */
    private function fakeMetabase(): RecordingMetabase
    {
        $property             = new \ReflectionProperty(\AA::class, 'metabase');
        $this->saved_metabase = $property->getValue();
        $metabase             = new RecordingMetabase();   // real keys; a row named 'bad' fails
        $property->setValue(null, $metabase);
        return $metabase;
    }

    private function restoreMetabase(): void
    {
        if ($this->saved_metabase !== false) {
            (new \ReflectionProperty(\AA::class, 'metabase'))->setValue(null, $this->saved_metabase);
            $this->saved_metabase = false;
        }
    }

    /** number of import tasks planModuleImport() queued - one per chunk */
    private static function plannedChunks(AA_Definition_Module $def): int
    {
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $count               = $def->planModuleImport(new \AA_Actionapps(new \ItemContent(['id' => [['value' => '12']]])));   // a queued task needs the central_conf id
        self::assertCount($count, RecordingDb::inserts('toexecute'));
        return $count;
    }

    public function test_one_chunk_per_non_empty_table(): void
    {
        $def = self::def(AA_Definition_Module_Slice::class, self::TEMPLATE_ID, [
            'module' => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID]],
            'view'   => [],
            'field'  => ['a' => ['id' => 'a', 'slice_id' => self::TEMPLATE_ID], 'b' => ['id' => 'b', 'slice_id' => self::TEMPLATE_ID]],
        ]);

        $this->assertSame(2, self::plannedChunks($def), 'an empty table gives no chunk');
        $metabase = $this->fakeMetabase();   // after planning - Toexecute writes through the real one
        $this->assertSame(['OK: 1 module ([' . self::TEMPLATE_ID . ']); ', 'OK: 2 field ([a,' . self::TEMPLATE_ID . '][b,' . self::TEMPLATE_ID . ']); '], $def->moduleImport(null, self::target()));
        // changed in C4.4 part 6 (chunk mode, design § 10.1): rows name their module (F4), the new module gets
        // the installation columns (ChunkImporterTest)
        $module = ['id' => self::TEMPLATE_ID, 'deleted' => 0, 'created_by' => 'importer', 'created_at' => 1700000000];
        $this->assertSame([['module', $module], ['field', ['id' => 'a', 'slice_id' => self::TEMPLATE_ID]], ['field', ['id' => 'b', 'slice_id' => self::TEMPLATE_ID]]], $metabase->inserted);
    }

    public function test_a_table_is_split_by_1000_rows(): void
    {
        $rows = [];
        for ($i = 0; $i < 2001; $i++) {
            $rows["item$i"] = ['id' => "item$i", 'slice_id' => self::TEMPLATE_ID];   // F4 since C4.4 part 6
        }
        $def = self::def(AA_Definition_Module_Slice::class, self::TEMPLATE_ID, ['item' => $rows]);

        $this->assertSame(3, self::plannedChunks($def));
        $this->fakeMetabase();
        $report = $def->moduleImport(null, self::target());
        $this->assertCount(3, $report, 'one report line per chunk');
        $this->assertStringStartsWith('OK: 1000 item ([item0][item1]', $report[0]);
        $this->assertStringStartsWith('OK: 1000 item ([item1000][item1001]', $report[1]);
        $this->assertSame('OK: 1 item ([item2000]); ', $report[2]);
    }

    public function test_nothing_to_import_without_data(): void
    {
        $this->assertSame(0, self::plannedChunks(new AA_Definition_Module_Slice()));
        $this->assertSame([], (new AA_Definition_Module_Slice())->moduleImport(), 'nothing imported, nothing reported');
    }

    public function test_without_replaces_the_values_are_imported_as_they_are(): void
    {
        $metabase = $this->fakeMetabase();
        $row      = ['id' => 12, 'slice_id' => self::TEMPLATE_ID, 'name' => null, 'even' => 1.5];

        self::def(AA_Definition_Module_Slice::class, self::TEMPLATE_ID, ['view' => [12 => $row]])->moduleImport(null, self::target());

        $this->assertSame([['view', $row]], $metabase->inserted);
    }

    public function test_replaces_go_into_every_value_and_stringify_it(): void
    {
        $metabase = $this->fakeMetabase();
        $def      = self::def(AA_Definition_Module_Slice::class, self::TEMPLATE_ID, [
            'view' => [12 => ['id' => 12, 'slice_id' => self::TEMPLATE_ID, 'name' => null, 'even' => '{view.php3?vid=' . self::TEMPLATE_ID . '}']],
        ]);

        self::collectingWarnings(static fn() => $def->moduleImport([self::TEMPLATE_ID => self::DEST_ID], self::target()), $warnings);

        // pinned; phase 4 may change - every value goes through str_replace(): ints become strings, NULL becomes ''
        $this->assertSame([['view', ['id' => '12', 'slice_id' => self::DEST_ID, 'name' => '', 'even' => '{view.php3?vid=' . self::DEST_ID . '}']]], $metabase->inserted, 'inside texts, too');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('str_replace', $warnings[0]);
    }

    // --- serialized form: exchanged with remote AAs of other versions ----------------------------

    /** The classes keep their global names and property names: remote AAs of other versions
     *  unserialize them by these names (AA_Request::ask() and ::decode() allow exactly them). */
    public function test_serialized_definition_names_the_class_and_protected_properties(): void
    {
        $def = self::def(AA_Definition_Module_Slice::class, 'm', ['module' => ['m' => ['id' => 'm']]]);

        $this->assertSame(
            "O:26:\"AA_Definition_Module_Slice\":2:{s:12:\"\0*\0module_id\";s:1:\"m\";s:7:\"\0*\0data\";a:1:{s:6:\"module\";a:1:{s:1:\"m\";a:1:{s:2:\"id\";s:1:\"m\";}}}}",
            serialize($def),
        );
        $this->assertSame(
            "O:26:\"AA_Definition_Module_Chunk\":2:{s:12:\"\0*\0module_id\";s:1:\"m\";s:7:\"\0*\0data\";a:1:{s:5:\"field\";a:1:{s:1:\"r\";a:1:{s:2:\"id\";s:1:\"r\";}}}}",
            serialize(new AA_Definition_Module_Chunk('m', 'field', ['r' => ['id' => 'r']])),
        );
    }

    /** Get_Module_Defs answers as the responder of today's code sends them (captured literally) */
    private const WIRE_SLICE = "O:11:\"AA_Response\":2:{s:21:\"\0AA_Response\0response\";a:1:{s:32:\"0123456789abcdef0123456789abcdef\";O:26"
        . ":\"AA_Definition_Module_Slice\":2:{s:12:\"\0*\0module_id\";s:32:\"0123456789abcdef0123456789abcdef\";s:7:\"\0*"
        . "\0data\";a:2:{s:6:\"module\";a:1:{s:32:\"0123456789abcdef0123456789abcdef\";a:3:{s:2:\"id\";s:32:\"0123456789"
        . "abcdef0123456789abcdef\";s:4:\"name\";s:4:\"News\";s:8:\"priority\";i:3;}}s:5:\"field\";a:1:{s:16:\"headline.."
        . "......\";a:3:{s:2:\"id\";s:16:\"headline........\";s:8:\"slice_id\";s:32:\"0123456789abcdef0123456789abcdef\""
        . ";s:9:\"input_pri\";i:100;}}}}}s:18:\"\0AA_Response\0error\";i:0;}";

    private const WIRE_SITE = "O:11:\"AA_Response\":2:{s:21:\"\0AA_Response\0response\";a:1:{s:32:\"0123456789abcdef0123456789abcdef\";O:25"
        . ":\"AA_Definition_Module_Site\":2:{s:12:\"\0*\0module_id\";s:32:\"0123456789abcdef0123456789abcdef\";s:7:\"\0*\0"
        . "data\";a:2:{s:6:\"module\";a:1:{s:32:\"0123456789abcdef0123456789abcdef\";a:3:{s:2:\"id\";s:32:\"0123456789a"
        . "bcdef0123456789abcdef\";s:4:\"name\";s:4:\"News\";s:8:\"priority\";i:3;}}s:9:\"site_spot\";a:1:{i:1;a:3:{s:7:"
        . "\"spot_id\";i:1;s:7:\"site_id\";s:32:\"0123456789abcdef0123456789abcdef\";s:7:\"content\";s:8:\"<b>x</b>\";}}}"
        . "}}s:18:\"\0AA_Response\0error\";i:0;}";

    private const WIRE_ALERTS = "O:11:\"AA_Response\":2:{s:21:\"\0AA_Response\0response\";a:1:{s:32:\"0123456789abcdef0123456789abcdef\";O:27"
        . ":\"AA_Definition_Module_Alerts\":2:{s:12:\"\0*\0module_id\";s:32:\"0123456789abcdef0123456789abcdef\";s:7:\"\0"
        . "*\0data\";a:2:{s:6:\"module\";a:1:{s:32:\"0123456789abcdef0123456789abcdef\";a:3:{s:2:\"id\";s:32:\"012345678"
        . "9abcdef0123456789abcdef\";s:4:\"name\";s:4:\"News\";s:8:\"priority\";i:3;}}s:17:\"alerts_collection\";a:0:{}}"
        . "}}s:18:\"\0AA_Response\0error\";i:0;}";

    /** a Do_Import_Module_Chunk request as today's Central sends it (captured literally) */
    private const WIRE_CHUNK_REQUEST = "O:10:\"AA_Request\":2:{s:7:\"command\";s:22:\"Do_Import_Module_Chunk\";s:6:\"params\";a:1:{s:16:\"definition_"
        . "chunk\";O:26:\"AA_Definition_Module_Chunk\":2:{s:12:\"\0*\0module_id\";s:32:\"0123456789abcdef0123456789abcd"
        . "ef\";s:7:\"\0*\0data\";a:1:{s:4:\"view\";a:1:{i:12;a:3:{s:2:\"id\";i:12;s:8:\"slice_id\";s:32:\"0123456789abcdef"
        . "0123456789abcdef\";s:4:\"name\";s:4:\"List\";}}}}}}";

    /** @return array<string, array{string, class-string<AA_Definition_Module>, array}> answer, class, getArray() */
    public static function wireDefinitions(): array
    {
        $module = ['module' => [self::TEMPLATE_ID => ['id' => self::TEMPLATE_ID, 'name' => 'News', 'priority' => 3]]];
        return [
            'slice'  => [self::WIRE_SLICE, AA_Definition_Module_Slice::class, $module + ['field' => ['headline........' => ['id' => 'headline........', 'slice_id' => self::TEMPLATE_ID, 'input_pri' => 100]]]],
            'site'   => [self::WIRE_SITE, AA_Definition_Module_Site::class, $module + ['site_spot' => [1 => ['spot_id' => 1, 'site_id' => self::TEMPLATE_ID, 'content' => '<b>x</b>']]]],
            'alerts' => [self::WIRE_ALERTS, AA_Definition_Module_Alerts::class, $module + ['alerts_collection' => []]],
        ];
    }

    #[DataProvider('wireDefinitions')]
    public function test_requestDefinitions_reads_a_literal_answer_through_the_real_ask(string $answer, string $class, array $data): void
    {
        // JWT: no login request, the one request reads the answer
        $content = new \ItemContent();
        $content->setValue('AA_HTTP_DOMAIN', $this->localResponder($answer));
        $content->setValue('AA_BASE_DIR', '');
        $content->setValue('AA_ADMIN_PWD', 'jwt:T');

        $defs = (new \AA_Actionapps($content))->requestDefinitions('Slice', [self::TEMPLATE_ID]);

        $this->assertSame([self::TEMPLATE_ID], array_keys($defs));
        $def = $defs[self::TEMPLATE_ID];
        $this->assertInstanceOf($class, $def);
        $this->assertSame(self::TEMPLATE_ID, $def->getId());
        $this->assertSame($data, $def->getArray(), 'types kept (int stays int)');
    }

    public function test_decode_reads_a_literal_import_chunk_request_and_imports_it(): void
    {
        $metabase = $this->fakeMetabase();

        $request = \AA_Request::decode(self::WIRE_CHUNK_REQUEST);

        $this->assertSame('Do_Import_Module_Chunk', $request->getCommand());
        $chunk = $request->getParameters()['definition_chunk'];
        $this->assertInstanceOf(AA_Definition_Module_Chunk::class, $chunk);
        $this->assertSame(['OK: 1 view ([12]); '], $chunk->importModuleChunk(self::target()));
        $this->assertSame([['view', ['id' => 12, 'slice_id' => self::TEMPLATE_ID, 'name' => 'List']]], $metabase->inserted);
    }
}
