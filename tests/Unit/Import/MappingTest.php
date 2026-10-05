<?php

declare(strict_types=1);

// The target slice (FakeSlice) and the transformations load include/locsess.php, which needs
// the constants include/config.php normally provides. Safe test values, defined first.

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

    /** a class named like a transformation that is none */
    final class AA_Transformation_NotOne {}
}

namespace Tests\Unit\Import {

    use AA\Import\Mapping;
    use AA\IO\SourceRefused;
    use AA_Field;
    use AA_Transformation_Exactvalues;
    use AA_Transformation_Value;
    use AA_Value;
    use ItemContent;
    use Tests\Unit\IO\Fixtures\ArrayGrabber;
    use Tests\Unit\IO\Fixtures\RecordingSaver;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * Each test in its own process: FakeSlice loads include/locsess.php, whose getDB() collides
     * with the stub tests/Unit/IO/DB loads when that directory runs first.
     */
    #[RunTestsInSeparateProcesses]
    final class MappingTest extends TestCase
    {
        private const SLICE = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';

        /** fields of the target slice; the system ones are there so a refusal is not "no such field" */
        private const FIELDS = [
            'headline........', 'relation........', 'text...........1', 'text...........2',
            'publish_date....', 'status_code.....', 'posted_by.......', 'expiry_date.....', 'slice_id........',
            'password.......1',
        ];

        /** protected by its insert function, not by its name */
        private const TWO_FA = 'text...........3';

        private FakeSlice $slice;

        protected function setUp(): void
        {
            // the warnings are locsess.php missing the web request and config of a real install
            set_error_handler(static fn() => true, E_WARNING | E_DEPRECATED);
            try {
                require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            } finally {
                restore_error_handler();
            }
            // an unknown slice is looked up in the module table - an empty one here
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $this->slice = $slice = new FakeSlice(self::SLICE);
            $field = static fn(string $id, string $insert = 'qte') => new AA_Field([
                'id'                => $id,
                'slice_id'          => self::SLICE,
                'text_stored'       => 1,
                'in_item_tbl'       => '',
                'input_show_func'   => 'fld',
                'input_insert_func' => $insert,
                'input_validate'    => '',
                'content_edit'      => 0,
                'html_default'      => 0,
                'multiple'          => 0,
            ]);
            $fields   = array_map($field, self::FIELDS);
            $fields[] = $field(self::TWO_FA, '2fa');
            $slice->setFields(new FakeFields(self::SLICE, $fields));
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
            RecordingDb::reset();
        }

        // ------------------------------------------------------------ helpers

        /** @return array{to:string, t:string, p:array<string, string>} */
        private static function row(string $to, string $content = 'x', string $t = 'value'): array
        {
            return ['to' => $to, 't' => $t, 'p' => ['new_content' => $content, 'new_flag' => 'h']];
        }

        private function assertRefused(string $reason, callable $call): void
        {
            try {
                $call();
                self::fail("SourceRefused($reason) expected");
            } catch (SourceRefused $e) {
                self::assertSame($reason, $e->reason);
            }
        }

        // ------------------------------------------------------------ forJob()

        public function test_forJob_paramTargetResolved(): void
        {
            $mapping = new Mapping([self::row('relation........', 'r'), self::row('param:fld', '{math:{index:hodnota}}')]);

            $t13s = $mapping->forJob(['fld' => 'text...........1', 'other' => 'headline........'], self::SLICE);

            self::assertSame(['relation........', 'text...........1'], array_keys($t13s));
            self::assertInstanceOf(AA_Transformation_Value::class, $t13s['text...........1']);
            self::assertSame('{math:{index:hodnota}}', $t13s['text...........1']->getParam('new_content'));
            self::assertSame('h', $t13s['text...........1']->getParam('new_flag'));
        }

        /** @return iterable<string, array{array<string, string>}> */
        public static function badParamTargets(): iterable
        {
            yield 'not a field of the slice' => [['fld' => 'text...........9']];
            yield 'not a field id at all'    => [['fld' => 'headline']];
            yield 'empty'                    => [['fld' => '']];
            yield 'parameter missing'        => [['other' => 'text...........1']];
        }

        /** @param array<string, string> $params */
        #[DataProvider('badParamTargets')]
        public function test_forJob_paramTargetNotAFieldRefused(array $params): void
        {
            $mapping = new Mapping([self::row('param:fld')]);

            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob($params, self::SLICE));
        }

        /** @return iterable<string, array{string}> */
        public static function systemFields(): iterable
        {
            foreach (['status_code.....', 'posted_by.......', 'publish_date....', 'expiry_date.....', 'slice_id........'] as $id) {
                yield $id => [$id];
            }
        }

        /** the data of a stream must not pick fields the engine keeps in the item table */
        #[DataProvider('systemFields')]
        public function test_forJob_paramTargetSystemFieldRefused(string $field_id): void
        {
            $mapping = new Mapping([self::row('param:fld')]);

            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob(['fld' => $field_id], self::SLICE));
        }

        /** a fixed target is chosen by the definition, not by the data - a system field is fine there */
        public function test_forJob_fixedSystemFieldAllowed(): void
        {
            $mapping = new Mapping([self::row('publish_date....', '{index:rok}')]);

            self::assertSame(['publish_date....'], array_keys($mapping->forJob([], self::SLICE)));
        }

        /** @return iterable<string, array{list<array{to:string, t:string, p:array<string, string>}>, array<string, string>}> */
        public static function duplicateTargets(): iterable
        {
            yield 'two fixed rows'          => [[self::row('headline........'), self::row('headline........')], []];
            yield 'parameter + fixed'       => [[self::row('text...........1'), self::row('param:fld')], ['fld' => 'text...........1']];
            yield 'two parameters, one field' => [[self::row('param:a'), self::row('param:b')], ['a' => 'text...........2', 'b' => 'text...........2']];
        }

        /**
         * @param list<array{to:string, t:string, p:array<string, string>}> $rows
         * @param array<string, string> $params
         */
        #[DataProvider('duplicateTargets')]
        public function test_forJob_duplicateTarget(array $rows, array $params): void
        {
            $mapping = new Mapping($rows);

            $this->assertRefused('DUPLICATE_TARGET', static fn() => $mapping->forJob($params, self::SLICE));
        }

        public function test_forJob_keepsRowOrder(): void
        {
            $mapping = new Mapping([
                self::row('text...........2'),
                self::row('param:fld'),
                self::row('headline........', 'x', 'exactvalues'),
                self::row('relation........'),
            ]);

            $t13s = $mapping->forJob(['fld' => 'text...........1'], self::SLICE);

            self::assertSame(['text...........2', 'text...........1', 'headline........', 'relation........'], array_keys($t13s));
            self::assertInstanceOf(AA_Transformation_Exactvalues::class, $t13s['headline........']);
        }

        /** a field deleted from the slice after the definition was saved stops the job, not each item */
        public function test_forJob_missingFixedFieldRefused(): void
        {
            $mapping = new Mapping([self::row('text...........9')]);

            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob([], self::SLICE));
        }

        public function test_forJob_unknownTransformationRefused(): void
        {
            $mapping = new Mapping([self::row('headline........', 'x', 'nosuch')]);

            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob([], self::SLICE));
        }

        public function test_forJob_unknownSliceRefused(): void
        {
            $mapping = new Mapping([self::row('headline........')]);

            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob([], '6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b'));
        }

        public function test_forJob_transformationNameIgnoresCase(): void
        {
            $mapping = new Mapping([self::row('headline........', 'x', 'Value')]);

            self::assertInstanceOf(AA_Transformation_Value::class, $mapping->forJob([], self::SLICE)['headline........']);
        }

        // ------------------------------------------------------------ problems()

        public function test_problems_none(): void
        {
            $mapping = new Mapping([self::row('relation........'), self::row('publish_date....'), self::row('param:fld')], ['relation........', 'publish_date....']);

            self::assertSame([], $mapping->problems(self::SLICE));
        }

        public function test_problems_unknownTransformation(): void
        {
            $mapping = new Mapping([self::row('headline........', 'x', 'nosuch'), self::row('relation........')], ['relation........']);

            self::assertSame(['UNKNOWN_TRANSFORMATION'], $mapping->problems(self::SLICE));
        }

        /** only a class the AA offers as a transformation may be built from the data */
        public function test_problems_classOutsideTransformationsIsUnknown(): void
        {
            // declared above with the right prefix, but not a transformation
            $mapping = new Mapping([self::row('headline........', 'x', 'notone'), self::row('relation........')], ['relation........']);

            self::assertSame(['UNKNOWN_TRANSFORMATION'], $mapping->problems(self::SLICE));
        }

        /** @return iterable<string, array{list<string>}> */
        public static function keyFieldsNotTargets(): iterable
        {
            yield 'not mapped at all'       => [['headline........']];
            yield 'reached only by a param' => [['text...........1']];
            yield 'one of two missing'      => [['relation........', 'headline........']];
        }

        /** @param list<string> $key_fields */
        #[DataProvider('keyFieldsNotTargets')]
        public function test_problems_keyFieldsNotTargets(array $key_fields): void
        {
            $mapping = new Mapping([self::row('relation........'), self::row('param:fld')], $key_fields);

            self::assertSame(['KEY_NOT_TARGET'], $mapping->problems(self::SLICE));
        }

        public function test_problems_missingFixedField(): void
        {
            $mapping = new Mapping([self::row('text...........9'), self::row('relation........')], ['relation........']);

            self::assertSame(['UNKNOWN_FIELD'], $mapping->problems(self::SLICE));
        }

        public function test_problems_duplicateFixedTarget(): void
        {
            $mapping = new Mapping([self::row('relation........'), self::row('relation........')], ['relation........']);

            self::assertSame(['DUPLICATE_TARGET'], $mapping->problems(self::SLICE));
        }

        public function test_problems_unknownSlice(): void
        {
            $mapping = new Mapping([self::row('relation........')]);

            self::assertSame(['NO_SLICE'], $mapping->problems('6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b6b'));
        }

        /** @return iterable<string, array{string, array<string, string>}> */
        public static function allowedTransformations(): iterable
        {
            yield 'value'       => ['value', ['new_flag' => 'h', 'new_content' => 'x']];
            yield 'exactvalues' => ['exactvalues', ['new_content' => 'x']];
            yield 'addvalue'    => ['addvalue', ['new_content' => 'x']];
            yield 'parsemulti'  => ['parsemulti', ['new_flag' => 'h', 'source' => 'x', 'delimiter' => ',']];
            yield 'setflag'     => ['setflag', ['new_flag' => 'h']];
            yield 'replace'     => ['replace', ['searchpattern' => 'a', 'replacestring' => 'b']];
            yield 'copyfield'   => ['copyfield', ['field2copy' => 'headline........']];
        }

        /** @param array<string, string> $p */
        #[DataProvider('allowedTransformations')]
        public function test_forJob_allowedTransformationBuilt(string $t, array $p): void
        {
            $mapping = new Mapping([['to' => 'relation........', 't' => $t, 'p' => $p]], ['relation........']);

            self::assertSame([], $mapping->problems(self::SLICE));
            self::assertStringEndsWith('_' . $t, strtolower($mapping->forJob([], self::SLICE)['relation........']::class));
        }

        /** @return iterable<string, array{string}> */
        public static function notAllowedTransformations(): iterable
        {
            // existing AA transformations: the first two evaluate source data as AA expressions,
            // recompute returns true where the Saver expects a value
            foreach (['translate', 'regexpreplace', 'recompute'] as $t) {
                yield $t => [$t];
            }
        }

        #[DataProvider('notAllowedTransformations')]
        public function test_problems_notAllowedTransformation(string $t): void
        {
            $mapping = new Mapping([['to' => 'relation........', 't' => $t, 'p' => ['new_flag' => 'h', 'translation' => 'a:b', 'searchpattern' => 'a', 'replacestring' => 'b', 'unalias' => '1']]], ['relation........']);

            self::assertSame(['UNKNOWN_TRANSFORMATION'], $mapping->problems(self::SLICE));
            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob([], self::SLICE));
        }

        /** @return iterable<string, array{string, array<string, string>}> */
        public static function missingParameters(): iterable
        {
            yield 'value without flag'          => ['value', ['new_content' => 'x']];
            yield 'parsemulti without delimiter' => ['parsemulti', ['new_flag' => 'h', 'source' => 'x']];
            yield 'copyfield without any'       => ['copyfield', []];
            yield 'replace, other name'         => ['replace', ['searchpattern' => 'a', 'replace' => 'b']];
            yield 'parsemulti, empty delimiter' => ['parsemulti', ['new_flag' => 'h', 'source' => 'x', 'delimiter' => '']];
        }

        /**
         * the constructors read these without a default - a missing one would be a PHP warning per job
         * @param array<string, string> $p
         */
        #[DataProvider('missingParameters')]
        public function test_problems_missingParameter(string $t, array $p): void
        {
            $mapping = new Mapping([['to' => 'relation........', 't' => $t, 'p' => $p]], ['relation........']);

            self::assertSame(['BAD_PARAMS'], $mapping->problems(self::SLICE));
            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob([], self::SLICE));
        }

        /** @return iterable<string, array{string}> */
        public static function protectedFields(): iterable
        {
            yield 'password field' => ['password.......1'];
            yield '2FA secret'     => [self::TWO_FA];
        }

        #[DataProvider('protectedFields')]
        public function test_forJob_paramTargetProtectedRefused(string $field_id): void
        {
            $mapping = new Mapping([self::row('param:fld')]);

            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob(['fld' => $field_id], self::SLICE));
        }

        #[DataProvider('protectedFields')]
        public function test_problems_protectedFixedTarget(string $field_id): void
        {
            $mapping = new Mapping([self::row($field_id), self::row('relation........')], ['relation........']);

            self::assertSame(['PROTECTED_FIELD'], $mapping->problems(self::SLICE));
            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob([], self::SLICE));
        }

        /** @return iterable<string, array{string, list<string>}> */
        public static function storeModesWithoutKey(): iterable
        {
            yield 'update/insert' => ['update/insert', ['NO_KEY']];
            yield 'update'        => ['update', ['NO_KEY']];
            yield 'insert'        => ['insert', ['NO_KEY']];
        }

        /** @param list<string> $expected */
        #[DataProvider('storeModesWithoutKey')]
        public function test_problems_noKey(string $store_mode, array $expected): void
        {
            $mapping = new Mapping([self::row('relation........')], [], $store_mode);

            self::assertSame($expected, $mapping->problems(self::SLICE));
        }

        public function test_idMode_keyFieldsAsSaverReadsThem(): void
        {
            $mapping = new Mapping([self::row('relation........')], ['relation........', 'publish_date....'], 'update');

            self::assertSame('["relation........","publish_date...."]', $mapping->idMode());
        }

        /** "[]" would let the Saver match any item of the slice */
        public function test_idMode_withoutKeyRefused(): void
        {
            $this->assertRefused('DEFINITION', static fn() => (new Mapping([self::row('relation........')]))->idMode());
        }

        /** the key reaches the Saver's lookup: an existing item is updated, not duplicated */
        public function test_idMode_drivesSaverLookup(): void
        {
            require_once __DIR__ . '/../IO/fixtures/ArrayGrabber.php';
            require_once __DIR__ . '/../IO/fixtures/RecordingSaver.php';
            $GLOBALS['permission_uid'] = '';
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [self::SLICE => PS_EDIT_ALL_ITEMS]];
            $source = new ItemContent();
            $source->setItemID('1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a');
            $source->setAaValue('relation........', new AA_Value('from the source'));
            $mapping = new Mapping([['to' => 'relation........', 't' => 'exactvalues', 'p' => ['new_content' => 'known']]], ['relation........']);

            $saver = new RecordingSaver(new ArrayGrabber([$source]), $mapping->forJob([], self::SLICE), self::SLICE, $mapping->storeMode, $mapping->idMode());
            $saver->existing = ['known' => '3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a'];
            $saver->run();

            self::assertSame([['known']], $saver->lookups);
            self::assertSame(['3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a'], $saver->storedIds());
        }

        public function test_problems_sliceInTrash(): void
        {
            $this->slice->deleted = true;
            $mapping = new Mapping([self::row('relation........')]);

            self::assertSame(['NO_SLICE'], $mapping->problems(self::SLICE));
            $this->assertRefused('DEFINITION', static fn() => $mapping->forJob([], self::SLICE));
        }

        public function test_problems_eachCodeOnce(): void
        {
            $mapping = new Mapping([self::row('text...........8', 'x', 'nosuch'), self::row('text...........9', 'x', 'nosuch2')], ['headline........']);

            self::assertSame(['UNKNOWN_TRANSFORMATION', 'UNKNOWN_FIELD', 'KEY_NOT_TARGET'], $mapping->problems(self::SLICE));
        }

        public function test_problemText_ownWordsForEachCode(): void
        {
            $codes = ['NO_SLICE', 'UNKNOWN_TRANSFORMATION', 'BAD_PARAMS', 'UNKNOWN_FIELD', 'PROTECTED_FIELD', 'DUPLICATE_TARGET', 'NO_KEY', 'KEY_NOT_TARGET'];

            $texts = array_map(Mapping::problemText(...), $codes);

            self::assertNotContains(Mapping::problemText('NOT_A_CODE'), $texts);
            self::assertCount(count($codes), array_unique($texts));
        }

        // ------------------------------------------------------------ fromJson(), toJson(), constructor

        /** @return iterable<string, array{string}> */
        public static function invalidJson(): iterable
        {
            $row = '{"to":"headline........","t":"value","p":{"new_content":"x"}}';
            yield 'not JSON'             => ['{"rows":['];
            yield 'empty'                => [''];
            yield 'a list'               => ['[' . $row . ']'];
            yield 'a string'             => ['"rows"'];
            yield 'no rows'              => ['{}'];
            yield 'other top key'        => ['{"rows":[],"key_fields":[]}'];
            yield 'rows an object'       => ['{"rows":{"a":' . $row . '}}'];
            yield 'row a string'         => ['{"rows":["headline........"]}'];
            yield 'row without to'       => ['{"rows":[{"t":"value","p":{}}]}'];
            yield 'row without t'        => ['{"rows":[{"to":"headline........","p":{}}]}'];
            yield 'row with other key'   => ['{"rows":[{"to":"headline........","t":"value","p":{},"x":"1"}]}'];
            yield 'to not a field id'    => ['{"rows":[{"to":"headline","t":"value","p":{}}]}'];
            yield 'to a number'          => ['{"rows":[{"to":5,"t":"value","p":{}}]}'];
            yield 'param name bad'       => ['{"rows":[{"to":"param:Fld","t":"value","p":{}}]}'];
            yield 'param name empty'     => ['{"rows":[{"to":"param:","t":"value","p":{}}]}'];
            yield 't with namespace'     => ['{"rows":[{"to":"headline........","t":"AA\\\\IO\\\\Saver","p":{}}]}'];
            yield 't empty'              => ['{"rows":[{"to":"headline........","t":"","p":{}}]}'];
            yield 't an array'           => ['{"rows":[{"to":"headline........","t":["value"],"p":{}}]}'];
            yield 'p a list'             => ['{"rows":[{"to":"headline........","t":"value","p":["x"]}]}'];
            yield 'p a string'           => ['{"rows":[{"to":"headline........","t":"value","p":"x"}]}'];
            yield 'p value a number'     => ['{"rows":[{"to":"headline........","t":"value","p":{"new_content":1}}]}'];
            yield 'p value null'         => ['{"rows":[{"to":"headline........","t":"value","p":{"new_content":null}}]}'];
            yield 'p value an array'     => ['{"rows":[{"to":"headline........","t":"value","p":{"new_content":["x"]}}]}'];
            yield 'p key bad'            => ['{"rows":[{"to":"headline........","t":"value","p":{"New content":"x"}}]}'];
            yield 'p value an object'    => ['{"rows":[{"to":"headline........","t":"value","p":{"a":{"b":"c"}}}]}'];
            yield 'too big'              => ['{"rows":[{"to":"headline........","t":"value","p":{"new_content":"' . str_repeat('x', 70000) . '"}}]}'];
            yield 'too many rows'        => ['{"rows":[' . implode(',', array_fill(0, 201, $row)) . ']}'];
        }

        #[DataProvider('invalidJson')]
        public function test_fromJson_invalid(string $json): void
        {
            $this->assertRefused('DEFINITION', static fn() => Mapping::fromJson($json));
        }

        public function test_toJson_roundTrip(): void
        {
            $mapping = new Mapping([
                self::row('relation........', '{ids:…}'),
                ['to' => 'param:fld', 't' => 'setflag', 'p' => []],
                self::row('headline........', "\"quoted\"\nnext line / slash"),
            ], ['relation........'], 'insert', ['{index:x}'], '{recompute:{index:itemID}}');

            $json  = $mapping->toJson();
            $again = Mapping::fromJson($json);

            self::assertSame($mapping->rows, $again->rows);
            self::assertSame($json, $again->toJson());
            // the other properties of the definition are stored apart
            self::assertSame(['rows'], array_keys(json_decode($json, true)));
            self::assertStringContainsString('"p":{}', $json, 'empty parameters stay an object');
        }

        public function test_toJson_nonAsciiStoredAsAscii(): void
        {
            $mapping = new Mapping([self::row('headline........', '{index:název} 😀')]);

            $json = $mapping->toJson();

            self::assertMatchesRegularExpression('/^[\x20-\x7e]*\z/', $json, 'only printable ASCII');
            self::assertSame($mapping->rows, Mapping::fromJson($json)->rows);
        }

        public function test_fromJson_missingParametersAreEmpty(): void
        {
            $mapping = Mapping::fromJson('{"rows":[{"to":"param:fld","t":"setflag"}]}');

            self::assertSame([['to' => 'param:fld', 't' => 'setflag', 'p' => []]], $mapping->rows);
        }

        public function test_construct_defaults(): void
        {
            $mapping = new Mapping([]);

            self::assertSame([], $mapping->keyFields);
            self::assertSame('update/insert', $mapping->storeMode);
            self::assertSame([], $mapping->validate);
            self::assertSame('', $mapping->afterStream);
        }

        /** @return iterable<string, array{0: array<mixed>, 1: list<string>, 2: string, 3?: array<mixed>}> */
        public static function invalidConstruction(): iterable
        {
            yield 'store mode overwrite'   => [[], [], 'overwrite'];
            yield 'store mode unknown'     => [[], [], 'by_grabber'];
            yield 'store mode keyless'     => [[], ['relation........'], 'insert_if_new'];
            yield 'key field not an id'    => [[], ['relation'], 'update/insert'];
            yield 'key field twice'        => [[], ['relation........', 'relation........'], 'update/insert'];
            yield 'row target bad'         => [[['to' => 'param:a-b', 't' => 'value', 'p' => []]], [], 'update/insert'];
            yield 'row parameter not text' => [[['to' => 'param:a', 't' => 'value', 'p' => ['x' => 1]]], [], 'update/insert'];
            yield 'row without parameters' => [[['to' => 'param:a', 't' => 'value']], [], 'update/insert'];
            yield 'rows not a list'        => [['a' => ['to' => 'param:a', 't' => 'value', 'p' => []]], [], 'update/insert'];
            yield 'row with other key'     => [[['to' => 'param:a', 't' => 'value', 'p' => [], 'x' => '1']], [], 'update/insert'];
            yield 'parameter not UTF-8'    => [[['to' => 'param:a', 't' => 'value', 'p' => ['new_content' => "caf\xE9"]]], [], 'update/insert'];
            yield 'validation not text'    => [[], [], 'update/insert', ['{x}', 1]];
        }

        /**
         * the class is built by sources in code as well, so the code gets the same checks as the JSON
         * @param array<mixed> $rows
         * @param list<string> $key_fields
         * @param array<mixed> $validate
         */
        #[DataProvider('invalidConstruction')]
        public function test_construct_invalidRefused(array $rows, array $key_fields, string $store_mode, array $validate = []): void
        {
            $this->assertRefused('DEFINITION', static fn() => new Mapping($rows, $key_fields, $store_mode, $validate));
        }

        /** @return iterable<string, array{string}> */
        public static function storeModes(): iterable
        {
            foreach (['update/insert', 'update', 'insert'] as $mode) {
                yield $mode => [$mode];
            }
        }

        #[DataProvider('storeModes')]
        public function test_construct_storeModes(string $mode): void
        {
            self::assertSame($mode, (new Mapping([], [], $mode))->storeMode);
        }
    }
}
