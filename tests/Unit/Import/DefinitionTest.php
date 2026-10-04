<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use AA\Import\Definition;
use AA\Import\Mapping;
use AA\Import\Registry;
use AA\IO\DB\DB_AA;
use AA\IO\SourceRefused;
use AA\Managed_Object;
use AA_Field;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Later\Fixtures\BatchRunTables;
use Tests\Unit\Legacy\Fixtures\FakeFields;
use Tests\Unit\Legacy\Fixtures\FakeSlice;

// the in-memory object_text, the config constants, FakeSlice and the stored() helpers of the batch run tests
require_once __DIR__ . '/../Later/Fixtures/BatchRunTables.php';

/**
 * Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides with the
 * stub tests/Unit/IO/DB loads when that directory runs first; the sources load into the global namespace.
 */
#[RunTestsInSeparateProcesses]
final class DefinitionTest extends TestCase
{
    use BatchRunTables {
        setUp as private tablesSetUp;
        tearDown as private tablesTearDown;
    }

    private const SLICE  = '5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b5b';
    private const OTHER  = '6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c6c';
    private const MAPPED = 'custom:definitionmapped';
    private const PLAIN  = 'custom:definitionplain';
    private const BROKEN = 'custom:definitionbroken';
    private const FIELDS = ['headline........', 'relation........', 'text...........1'];
    private const HIDDEN = ['settings', 'mapping', 'key_fields', 'store_mode', 'validate', 'after_stream'];

    protected function setUp(): void
    {
        $this->tablesSetUp();
        $sources = __DIR__ . '/fixtures/definition';
        Registry::setCustomRootForTests(dirname($sources), $sources);
        Registry::sources();   // loads the source classes the tests use directly
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
        self::perms(PS_FEEDING, PS_FEEDING);
    }

    protected function tearDown(): void
    {
        $this->tablesTearDown();
        Registry::setCustomRootForTests(null);
        [$_GET, $_POST, $_REQUEST] = [[], [], []];
        unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
    }

    // ------------------------------------------------------------ formRefusal()

    public function test_formRefusal_sourceChangeOfExistingRefused(): void
    {
        $id = self::create('prices');

        $error = self::post($id, 'prices', self::PLAIN);

        self::assertSame('Change the source in the import detail.', $error);
        self::assertSame(self::MAPPED, self::stored($id)['source']);
    }

    public function test_formRefusal_withoutFeedingRefused(): void
    {
        self::perms(PS_EDIT_ALL_ITEMS, PS_FEEDING);   // feeding of another slice does not count

        $error = self::post('', 'prices', self::MAPPED);

        self::assertIsString($error);
        self::assertStringContainsString('feeding', $error);
        self::assertSame([], TableDb::writes(), 'nothing stored');
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'colon'         => ['prices:2024'];
        yield 'like an id'    => ['ABCDEF0123456789abcdef0123456789'];
        yield 'none'          => [''];
    }

    #[DataProvider('badNames')]
    public function test_formRefusal_badNameRefused(string $name): void
    {
        self::assertIsString(self::post('', $name, self::MAPPED));
        self::assertSame([], TableDb::writes());
    }

    public function test_formRefusal_duplicateNameInSlice(): void
    {
        $id = self::create('prices');

        self::assertSame('Another import of the slice has this name.', self::post('', 'prices', self::PLAIN));
        self::assertNull(self::post($id, 'prices', self::MAPPED), 'the import keeps its own name');
        self::assertNull(self::post('', 'prices', self::MAPPED, self::OTHER), 'another slice may use the name');
    }

    /** names compare as object_text.value does (a case-insensitive collation, trailing spaces ignored) */
    public function test_nameKey_matchesAsTheColumn(): void
    {
        self::assertSame(Definition::nameKey('prices'), Definition::nameKey('Prices '));
        self::assertSame(Definition::nameKey('cena'), Definition::nameKey('CENA'));
        self::assertNotSame(Definition::nameKey('prices'), Definition::nameKey('prices2'));
        self::assertNotSame(Definition::nameKey('prices'), Definition::nameKey(' prices'));
    }

    public function test_formRefusal_unknownSource(): void
    {
        self::assertSame('The import source is not available.', self::post('', 'prices', 'custom:nothing'));
        self::assertSame([], TableDb::writes());
    }

    public function test_formRefusal_mappingProblems(): void
    {
        $def = self::definition('prices');
        $def->applyMapping(new Mapping([['to' => 'text...........9', 't' => 'value', 'p' => ['new_content' => 'x', 'new_flag' => 't']]], [], 'insert_if_new'));

        $error = $def->formRefusal();

        self::assertIsString($error);
        self::assertStringContainsString('UNKNOWN_FIELD', $error);
    }

    public function test_formRefusal_sourceFailingDefaultMapping(): void
    {
        self::assertSame('The import source did not give its mapping.', self::post('', 'prices', self::BROKEN));
        self::assertSame([], TableDb::writes());
    }

    public function test_formRefusal_validDefinitionPasses(): void
    {
        $def = self::definition('prices');
        $def->applyMapping(new Mapping([['to' => 'headline........', 't' => 'value', 'p' => ['new_content' => 'x', 'new_flag' => 't']]], [], 'insert_if_new'));

        self::assertNull($def->formRefusal());
    }

    public function test_formRefusal_existingBrokenDefinitionCanBeDisabledAndRenamed(): void
    {
        $id = self::create('prices');
        self::storedSet($id, 'mapping', '{"rows":[{"to":"text...........9","t":"value","p":{"new_content":"x","new_flag":"t"}}]}');
        self::storedSet($id, 'source', 'custom:gone');   // the source left the installation

        self::assertNull(self::post($id, 'old prices', 'custom:gone', self::SLICE, ['enabled' => ['0']]));

        self::assertSame(['old prices', ''], [self::stored($id)['aa_name'], self::stored($id)['enabled']]);
    }

    public function test_formRefusal_newRefusedWithoutTransactions(): void
    {
        $id = self::create('prices');
        TableDb::$engines['object_text'] = 'MyISAM';

        $error = self::post('', 'rents', self::MAPPED);

        self::assertIsString($error);
        self::assertStringContainsString('InnoDB', $error);
        self::assertNull(self::post($id, 'old prices', self::MAPPED), 'an existing one can still be renamed');
        self::assertSame('old prices', self::stored($id)['aa_name']);
    }

    // ------------------------------------------------------------ factoryFromForm()

    public function test_factoryFromForm_keepsHiddenProperties(): void
    {
        $id = self::create('prices');
        self::storedSet($id, 'settings', '{"year":"2024"}');   // set in the import detail meanwhile
        $before = self::stored($id);

        // the form sends three properties - and forged ones, which are ignored
        $error = self::post($id, 'new prices', self::MAPPED, self::SLICE, ['enabled' => ['0'], 'mapping' => ['{"rows":[]}'], 'store_mode' => ['overwrite'], 'settings' => ['{}']]);

        self::assertNull($error);
        $after = self::stored($id);
        self::assertSame(['new prices', ''], [$after['aa_name'], $after['enabled']]);
        foreach (self::HIDDEN as $property) {
            self::assertSame($before[$property], $after[$property], $property);
        }
    }

    public function test_factoryFromForm_checkboxOfTheWidget(): void
    {
        self::assertNull(self::post('', 'prices', self::MAPPED, self::SLICE, ['enabled' => ['chb' => ['def' => '0', 0 => '1']]]));
        self::assertTrue(Definition::byNameOrId(self::SLICE, 'prices')?->isEnabled());

        $id = (string) Definition::byNameOrId(self::SLICE, 'prices')?->getId();
        self::assertNull(self::post($id, 'prices', self::MAPPED, self::SLICE, ['enabled' => ['chb' => ['def' => '0']]]));
        self::assertFalse(Definition::byNameOrId(self::SLICE, 'prices')?->isEnabled());
    }

    public function test_factoryFromForm_withoutFeedingRunsNoSourceCode(): void
    {
        self::perms(PS_FORMS, PS_FEEDING);
        $_POST['aa'] = ['n1_' . self::SLICE => ['aa_name' => ['prices'], 'source' => [self::MAPPED], 'enabled' => ['1']]];

        $def = Definition::factoryFromForm(self::SLICE, Definition::class);

        self::assertSame('', $def->snapshot()['mapping'], 'no default mapping asked for');
    }

    public function test_new_takesDefaultMappingFromSource(): void
    {
        $id = self::create('prices');

        $def     = Definition::fresh($id);
        $default = (new \DefinitionMappedSource())->defaultMapping();
        self::assertNotNull($def);
        self::assertEquals($default, $def->mapping());
        self::assertSame($default->toJson(), self::stored($id)['mapping']);
        self::assertSame('["relation........"]', self::stored($id)['key_fields']);
        self::assertSame([self::MAPPED, true], [$def->sourceType(), $def->isEnabled()]);
    }

    public function test_new_forgedPropertiesIgnored(): void
    {
        $forged = ['mapping' => ['{"rows":[]}'], 'store_mode' => ['overwrite'], 'settings' => ['{"slice":"' . self::OTHER . '"}']];

        self::assertNull(self::post('', 'prices', self::PLAIN, self::SLICE, $forged));

        $def = Definition::byNameOrId(self::SLICE, 'prices');
        self::assertNotNull($def);
        self::assertEquals((new \DefinitionPlainSource())->defaultMapping(), $def->mapping());
        self::assertSame('', $def->snapshot()['settings']);
    }

    // ------------------------------------------------------------ save()

    public function test_save_insideTransactionWithLock(): void
    {
        $def = self::definition('prices');
        $def->applyMapping((new \DefinitionMappedSource())->defaultMapping());
        TableDb::$log = [];

        $id = $def->save();

        self::assertSame($def->getId(), $id);
        $log = TableDb::$log;
        self::assertSame('BEGIN', $log[0]);
        self::assertStringContainsString((string) $id, $log[1]);
        self::assertStringEndsWith(' FOR UPDATE', $log[1], 'the first statement locks the rows of the definition');
        self::assertSame('COMMIT', end($log));
    }

    public function test_save_existingIsOneLockingReadThenOnlyNameAndEnabled(): void
    {
        $id = self::create('prices');
        $_POST['aa'] = ["u$id" => ['aa_name' => ['new prices'], 'source' => [self::MAPPED], 'enabled' => ['1']]];
        $def = Definition::factoryFromForm(self::SLICE, Definition::class);
        TableDb::$log = [];

        self::assertSame($id, $def->save());

        $reads = array_values(array_filter(TableDb::$log, static fn(string $s): bool => str_starts_with($s, 'SELECT') && str_contains($s, "property, value FROM `object_text`")));
        self::assertCount(1, $reads, 'one read decides');
        self::assertStringEndsWith(' FOR UPDATE', $reads[0]);
        self::assertStringContainsString($id, $reads[0]);
        foreach (TableDb::writes() as $write) {
            self::assertMatchesRegularExpression("/'(aa_name|enabled)'/", $write, 'only the name and enabled are rewritten');
        }
    }

    public function test_save_concurrentSettingsChangeKept(): void
    {
        $id = self::create('prices');
        $_POST['aa'] = ["u$id" => ['aa_name' => ['new prices'], 'source' => [self::MAPPED], 'enabled' => ['1']]];
        $def = Definition::factoryFromForm(self::SLICE, Definition::class);
        self::assertNull($def->formRefusal());

        self::storedSet($id, 'settings', '{"year":"2025"}');   // the import detail saved meanwhile
        $def->save();

        self::assertSame(['new prices', '{"year":"2025"}'], [self::stored($id)['aa_name'], self::stored($id)['settings']]);
    }

    public function test_save_forgedExistingIdOnAddCreatesCleanDefinition(): void
    {
        $existing = self::create('prices');
        self::storedSet($existing, 'settings', '{"year":"2024"}');
        $before = self::stored($existing);
        // the form of a new definition, posted with the key of an existing one
        $_POST['aa'] = ["u$existing" => ['aa_name' => ['copy'], 'source' => [self::PLAIN], 'enabled' => ['1']]];

        self::assertNull((new Managed_Object(Definition::class))->save(self::SLICE, ''));

        $copy = Definition::byNameOrId(self::SLICE, 'copy');
        self::assertNotNull($copy);
        self::assertNotSame($existing, $copy->getId());
        self::assertSame('', $copy->snapshot()['settings'], 'nothing copied');
        self::assertEquals((new \DefinitionPlainSource())->defaultMapping(), $copy->mapping());
        self::assertSame($before, self::stored($existing), 'the existing one untouched');
    }

    public function test_save_objectOfAnotherSliceOrClassRefused(): void
    {
        $other = self::create('rents', self::OTHER);
        $before = self::stored($other);
        $def = self::definition('rents');
        $def->setId($other)->setProperty('source', self::MAPPED);

        self::assertFalse($def->save(), 'a definition of another slice');
        self::assertSame($before, self::stored($other));

        $task = 'abababababababababababababababab';
        self::storeTask($task, 'tasks');
        self::assertFalse($def->setId($task)->save(), 'an object of another class');
        self::assertSame('AA_Plannedtask', self::stored($task)['aa_type']);
    }

    public function test_save_changedSourceRefused(): void
    {
        $id = self::create('prices');
        $def = Definition::fresh($id);
        self::assertNotNull($def);

        self::assertFalse($def->setProperty('source', self::PLAIN)->save());
        self::assertSame(self::MAPPED, self::stored($id)['source']);
    }

    // ------------------------------------------------------------ fresh()

    public function test_fresh_lockOutsideTransactionRefused(): void
    {
        $this->expectException(LogicException::class);
        Definition::fresh(self::SLICE, true);
    }

    public function test_fresh_lockTargetsTheId(): void
    {
        $id = self::create('prices');
        TableDb::$log = [];

        $def = DB_AA::transaction(static fn() => Definition::fresh($id, true));

        self::assertSame($id, $def?->getId());
        self::assertSame(['BEGIN', 'COMMIT'], [TableDb::$log[0], TableDb::$log[2]], 'one statement');
        self::assertStringContainsString($id, TableDb::$log[1]);
        self::assertStringEndsWith(' FOR UPDATE', TableDb::$log[1]);
    }

    public function test_fresh_readsAfterConcurrentSave(): void
    {
        $id    = self::create('prices');
        $first = Definition::fresh($id);

        self::storedSet($id, 'settings', '{"year":"2025"}');   // another process saved meanwhile

        self::assertSame('', $first?->snapshot()['settings']);
        self::assertSame('{"year":"2025"}', Definition::fresh($id)?->snapshot()['settings']);
        self::assertNull(Definition::fresh(self::SLICE), 'no object');
        self::assertNull(Definition::fresh('not an id'));
    }

    // ------------------------------------------------------------ byNameOrId()

    public function test_byNameOrId_onlyOwnSlice(): void
    {
        $own   = self::create('prices');
        $other = self::create('rents', self::OTHER);

        self::assertSame($own, Definition::byNameOrId(self::SLICE, 'prices')?->getId());
        self::assertSame($own, Definition::byNameOrId(strtoupper(self::SLICE), 'prices')?->getId(), 'slice id in any case');
        self::assertSame($own, Definition::byNameOrId(self::SLICE, $own)?->getId());
        self::assertSame($own, Definition::byNameOrId(strtoupper(self::SLICE), strtoupper($own))?->getId());
        self::assertNull(Definition::byNameOrId(self::SLICE, $other), 'an id of another slice');
        self::assertNull(Definition::byNameOrId(self::SLICE, 'rents'), 'a name of another slice');
        self::assertNull(Definition::byNameOrId(self::SLICE, ''));
    }

    public function test_byNameOrId_ambiguousNameNull(): void
    {
        self::create('prices');
        $second = self::create('rents');
        self::storedSet($second, 'aa_name', 'prices');   // saved from code, which checks no names

        self::assertNull(Definition::byNameOrId(self::SLICE, 'prices'));
        self::assertSame($second, Definition::byNameOrId(self::SLICE, $second)?->getId(), 'the id still works');
    }

    public function test_byNameOrId_otherObjectOfTheNameIgnored(): void
    {
        $id = 'abababababababababababababababab';
        self::storeTask($id, 'prices');

        self::assertNull(Definition::byNameOrId(self::SLICE, 'prices'));
        self::assertNull(Definition::byNameOrId(self::SLICE, $id));
        self::assertNull(self::post('', 'prices', self::MAPPED), 'a task of the name is no other import');
    }

    // ------------------------------------------------------------ snapshot(), mapping()

    public function test_snapshot_containsAllRunProperties(): void
    {
        $id = self::create('prices');
        self::storedSet($id, 'settings', '{"year":"2024"}');
        $stored = self::stored($id);

        $snapshot = Definition::fresh($id)?->snapshot();

        self::assertSame(['slice', 'source', 'settings', 'mapping', 'key_fields', 'store_mode', 'validate', 'after_stream'], array_keys((array) $snapshot));
        self::assertSame(self::SLICE, $snapshot['slice'] ?? null);
        foreach (array_slice(array_keys((array) $snapshot), 1) as $property) {
            self::assertSame($stored[$property], $snapshot[$property] ?? null, $property);
        }
        self::assertEquals((new \DefinitionMappedSource())->defaultMapping(), Definition::mappingOf((array) $snapshot), 'a run rebuilds the mapping from it');
    }

    public function test_storeMode_overwriteRefused(): void
    {
        $def = self::definition('prices');
        $def->applyMapping((new \DefinitionPlainSource())->defaultMapping());
        $def->setProperty('store_mode', 'overwrite');

        self::assertIsString($def->formRefusal());
        try {
            $def->mapping();
            self::fail('SourceRefused expected');
        } catch (SourceRefused $e) {
            self::assertSame('DEFINITION', $e->reason);
        }
    }

    /** @return iterable<string, array{string, string}> property, stored value */
    public static function brokenMappings(): iterable
    {
        yield 'mapping not JSON'     => ['mapping', '{rows'];
        yield 'key fields no list'   => ['key_fields', '{"a":"relation........"}'];
        yield 'key fields no array'  => ['key_fields', '"relation........"'];
        yield 'validation not JSON'  => ['validate', '[1'];
        yield 'no store mode'        => ['store_mode', ''];
    }

    #[DataProvider('brokenMappings')]
    public function test_mapping_brokenPropertyRefused(string $property, string $value): void
    {
        $def = self::definition('prices');
        $def->applyMapping((new \DefinitionMappedSource())->defaultMapping());
        $def->setProperty($property, $value);

        $this->expectException(SourceRefused::class);
        $def->mapping();
    }

    // ------------------------------------------------------------ settingsOf()

    public function test_settingsOf_textValues(): void
    {
        self::assertSame([], Definition::settingsOf(['settings' => '']));
        self::assertSame([], Definition::settingsOf(['settings' => '{}']));
        self::assertSame(['from_year' => '2006', 'slice' => 'abc'], Definition::settingsOf(['settings' => '{"from_year":2006,"slice":"abc","list":[1]}']));
    }

    /** @return iterable<string, array{string}> */
    public static function brokenSettings(): iterable
    {
        yield 'not JSON'       => ['{"slice":'];
        yield 'a string'       => ['"abc"'];
        yield 'a list'         => ['["a","b"]'];
        yield 'a numbered key' => ['{"0":"a"}'];
    }

    /** broken settings would quietly become none - and the source would run on its defaults */
    #[DataProvider('brokenSettings')]
    public function test_settingsOf_brokenRefused(string $json): void
    {
        try {
            Definition::settingsOf(['settings' => $json]);
            self::fail("$json read as settings");
        } catch (SourceRefused $e) {
            self::assertSame('DEFINITION', $e->reason);
        }
    }

    // ------------------------------------------------------------ mayManage(), isEnabled()

    public function test_mayManage_feedingOfTheOwnerSlice(): void
    {
        $def = self::definition('prices');
        self::assertTrue($def->mayManage(AuthGetUid()));

        self::perms(PS_EDIT_ALL_ITEMS . PS_FORMS, PS_FEEDING);
        self::assertFalse($def->mayManage(AuthGetUid()));
    }

    public function test_isEnabled(): void
    {
        $def = self::definition('prices');
        self::assertTrue($def->isEnabled());
        $def->setProperty('enabled', '');
        self::assertFalse($def->isEnabled());
    }

    // ------------------------------------------------------------ helpers

    /** the permission letters of the (anonymous) test user on SLICE and OTHER */
    private static function perms(string $own, string $other): void
    {
        $GLOBALS['permission_uid'] = AuthGetUid();
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => $own, self::OTHER => $other]];
    }

    /**
     * The Manager form of a definition posted with $name, $source and $more; '' $id = a new one.
     * @param array<string, array<mixed>> $more
     * @return ?string the error the form shows, null = saved
     */
    private static function post(string $id, string $name, string $source, string $slice = self::SLICE, array $more = []): ?string
    {
        $key         = ($id === '') ? "n1_$slice" : "u$id";
        $_POST['aa'] = [$key => $more + ['aa_name' => [$name], 'source' => [$source], 'enabled' => ['1']]];
        return (new Managed_Object(Definition::class))->save($slice, $id);
    }

    /** a new definition saved through the form; its id */
    private static function create(string $name, string $slice = self::SLICE): string
    {
        self::assertNull(self::post('', $name, self::MAPPED, $slice));
        $def = Definition::byNameOrId($slice, $name);
        self::assertNotNull($def);
        return (string) $def->getId();
    }

    /** an unsaved definition of SLICE */
    private static function definition(string $name): Definition
    {
        $def = new Definition();
        $def->setNew('0123456789abcdef0123456789abcdef', self::SLICE, $name);
        $def->setProperty('source', self::PLAIN)->setProperty('enabled', '1');
        return $def;
    }

    /** a planned task of SLICE called $name, stored at $id */
    private static function storeTask(string $id, string $name): void
    {
        foreach (['aa_type' => 'AA_Plannedtask', 'aa_owner' => self::SLICE, 'aa_name' => $name] as $property => $value) {
            TableDb::$tables['object_text'][] = ['id' => 900 + count(TableDb::rows('object_text')), 'object_id' => $id, 'property' => $property, 'priority' => 0, 'value' => $value, 'flag' => null];
        }
    }
}
