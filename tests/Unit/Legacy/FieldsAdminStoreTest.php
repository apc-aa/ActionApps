<?php

declare(strict_types=1);

// RecordingDb (store_fixtures) loads the real locsess.php, which needs the constants
// include/config.php normally provides.

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

namespace Tests\Unit\Legacy {

    use AA_Fields;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * AA_Fields::storeAdminRows() - the rows of the Fields page (admin/se_fields.php), posted as
     * name[fid], pri[fid], req[fid], shw[fid]. The field ids are keys from the request: only
     * an existing field of the slice may be changed, and the New_Field row copies a template field
     * of a slice the user may pick from.
     */
    final class FieldsAdminStoreTest extends TestCase
    {
        private const SLICE = '0123456789abcdef0123456789abcdef';
        private const OTHER = 'fedcba9876543210fedcba9876543210';
        private const THIRD = 'aaaabbbbccccddddaaaabbbbccccdddd';
        private const CORE  = '41415f436f72655f4669656c64732e2e';   // AA_Core_Fields..

        private const OWN_IDS_SQL = 'SELECT `id` FROM `field` WHERE slice_id = 0x0123456789abcdef0123456789abcdef';

        private const OWN_FIELDS = ['headline........', 'text............', 'text...........1', '_upload_url.....'];

        /** the text template field as AA_Core_Fields.. has it */
        private const TEMPLATE = [
            'id'            => 'text',
            'type'          => '',
            'slice_id'      => 'AA_Core_Fields..',
            'name'          => 'Text',
            'input_help'    => 'Some text',
            'input_default' => 'txt:',
            'input_pri'     => '100',
            'required'      => '0',
            'input_show'    => '1',
            'alias1'        => '_#TEXT____',
            'text_stored'   => '1',
        ];

        /** templates by "<slice>-<field id>" */
        private const TEMPLATES = [
            self::CORE . '-text'               => self::TEMPLATE,
            // no text_stored - the column is NULL
            self::CORE . '-number'             => ['id' => 'number', 'name' => 'Number', 'input_pri' => '100', 'in_item_tbl' => '0'],
            self::CORE . '-slice_id'           => ['id' => 'slice_id', 'name' => 'Slice', 'in_item_tbl' => 'slice_id', 'text_stored' => '1'],
            self::OTHER . '-headline........' => ['id' => 'headline........', 'name' => 'Title', 'input_help' => 'from other', 'in_item_tbl' => '', 'text_stored' => '1'],
        ];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            RecordingDb::$answer = static function (string $sql): array {
                if ($sql === self::OWN_IDS_SQL) {
                    return array_map(static fn($id) => ['id' => $id], self::OWN_FIELDS);
                }
                if (str_contains($sql, 'FROM `module`')) {
                    return [
                        ['unpackid' => self::CORE, 'name' => 'AA Core Fields'],
                        ['unpackid' => self::OTHER, 'name' => 'Other'],
                        ['unpackid' => self::SLICE, 'name' => 'Mine'],
                        ['unpackid' => self::THIRD, 'name' => 'Third'],
                    ];
                }
                foreach (self::TEMPLATES as $ftype => $row) {
                    [$tpl_slice, $tpl_id] = explode('-', $ftype, 2);
                    if ($sql === "SELECT * FROM `field` WHERE slice_id = 0x$tpl_slice AND id = '$tpl_id' LIMIT  1") {
                        return [$row];
                    }
                }
                return [];
            };
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            unset($GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        }

        /** @param array<string, string> $slices  slice id => the permission letters of the user */
        private static function perms(array $slices, string $aa = ''): void
        {
            $GLOBALS['permission_uid'] = AuthGetUid();
            $GLOBALS['permission_to']  = ['aa' => [AA_ID => $aa], 'slice' => $slices];
        }

        /** @return string[] the writes to the field table */
        private static function writes(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => !str_starts_with($sql, 'SELECT')));
        }

        private static function store(array $name, array $pri = [], array $req = [], array $shw = [], string $ftype = '', bool $slice_fields = false): array
        {
            return AA_Fields::storeAdminRows(self::SLICE, $name, $pri, $req, $shw, $ftype, $slice_fields, [self::CORE, self::SLICE]);
        }

        public function test_updatesOwnFields(): void
        {
            $err = self::store(
                ['headline........' => "Titulek 'x'", 'text...........1' => 'Popis'],
                ['headline........' => '10', 'text...........1' => '200'],
                ['headline........' => 'on'],
                ['text...........1' => 'on'],
            );

            $this->assertSame([], $err);
            $this->assertSame([
                "UPDATE `field` SET `name`='Titulek \\'x\\'', `input_pri`='10', `required`='1', `input_show`='0' WHERE id='headline........' AND slice_id=0x0123456789abcdef0123456789abcdef",
                "UPDATE `field` SET `name`='Popis', `input_pri`='200', `required`='0', `input_show`='1' WHERE id='text...........1' AND slice_id=0x0123456789abcdef0123456789abcdef",
            ], self::writes());
        }

        public static function badKeys(): array
        {
            return [
                'or 1=1'        => ["x' OR 1=1 -- "],
                'valid + tail'  => ["headline........' OR 1=1 -- "],
                'subquery'      => ["headline........' AND (SELECT 1 FROM perms)='1"],
                'backslash'     => ['headline.......\\'],
                'newline'       => ["headline........\n"],
                'case'          => ['HEADLINE........'],
                'numeric'       => ['0'],
            ];
        }

        /** keys that are no field of the slice are no row of the form */
        #[\PHPUnit\Framework\Attributes\DataProvider('badKeys')]
        public function test_injectedKeyIsIgnored(string $key): void
        {
            $err = self::store([$key => 'hacked'], [$key => '1'], [$key => 'on'], [$key => 'on']);

            $this->assertSame([], $err);
            $this->assertSame([], self::writes());
        }

        /** a field id of another slice is no field of this one - the WHERE is always bound to the slice */
        public function test_fieldOfAnotherSliceIsNotTouched(): void
        {
            self::store(['text...........7' => 'taken over', 'headline........' => 'Own'], ['text...........7' => '1', 'headline........' => '1']);

            $this->assertSame([
                "UPDATE `field` SET `name`='Own', `input_pri`='1', `required`='0', `input_show`='0' WHERE id='headline........' AND slice_id=0x0123456789abcdef0123456789abcdef",
            ], self::writes());
        }

        public static function badPriorities(): array
        {
            return [
                'or'        => ['1 OR 1=1'],
                'quote'     => ["1', name='x"],
                'subquery'  => ['(SELECT 1)'],
                'negative'  => ['-1'],
                'float'     => ['1.5'],
                'empty'     => [''],
                'array'     => [['1']],
            ];
        }

        /** the priority is stored as a number - anything else is an error of the form, nothing is written */
        #[\PHPUnit\Framework\Attributes\DataProvider('badPriorities')]
        public function test_nonNumericPriorityIsRefused(string|array $pri): void
        {
            $err = self::store(['headline........' => 'Headline'], ['headline........' => $pri]);

            $this->assertNotSame([], $err);
            $this->assertSame([], self::writes());
        }

        #[\PHPUnit\Framework\Attributes\DataProvider('badPriorities')]
        public function test_nonNumericPriorityOfNewFieldIsRefused(string|array $pri): void
        {
            $err = self::store(['New_Field' => 'Perex'], ['New_Field' => $pri], [], [], self::CORE . '-text');

            $this->assertNotSame([], $err);
            $this->assertSame([], self::writes());
        }

        /** a missing priority is reported as the priority, not as the field */
        public function test_missingPriorityIsPriorityError(): void
        {
            $err = self::store(['headline........' => 'Headline']);

            $this->assertSame(['prior'], array_keys($err));
            $this->assertStringContainsString(_m('Priority'), $err['prior']);
            $this->assertSame([], self::writes());
        }

        public function test_nonStringNameIsRefused(): void
        {
            $err = self::store(['headline........' => ['x']], ['headline........' => '1']);

            $this->assertNotSame([], $err);
            $this->assertSame([], self::writes());
        }

        /** the New_Field row copies the template and numbers the new field after the fields of its type */
        public function test_newFieldCopiesTheTemplate(): void
        {
            $err = self::store(
                ['New_Field' => "Perex 'krátký'", 'headline........' => 'Headline'],
                ['New_Field' => '1000', 'headline........' => '5'],
                ['New_Field' => 'on'],
                [],
                self::CORE . '-text',
            );

            $this->assertSame([], $err);
            $inserted = RecordingDb::inserts('field');
            $this->assertCount(1, $inserted);
            $this->assertSame([
                'alias1'        => '_#TEXT____',
                'alias2'        => '',
                'feed'          => 0,
                'id'            => 'text...........2',
                'input_default' => 'txt:',
                'input_help'    => 'Some text',
                'input_pri'     => 1000,
                'input_show'    => 0,
                'name'          => "Perex 'krátký'",
                'required'      => 1,
                'slice_id'      => self::SLICE,
                'text_stored'   => 1,
                'type'          => '',
            ], self::columns($inserted[0], ['alias1', 'alias2', 'feed', 'id', 'input_default', 'input_help', 'input_pri', 'input_show', 'name', 'required', 'slice_id', 'text_stored', 'type']));
            $this->assertContains(
                "UPDATE `field` SET `name`='Headline', `input_pri`='5', `required`='0', `input_show`='0' WHERE id='headline........' AND slice_id=0x0123456789abcdef0123456789abcdef",
                self::writes(),
            );
        }

        /** slice (setting) fields are prefixed by underscore */
        public function test_newSliceFieldGetsUnderscore(): void
        {
            $err = self::store(['New_Field' => 'Setting'], ['New_Field' => '1'], [], ['New_Field' => 'on'], self::CORE . '-text', true);

            $this->assertSame([], $err);
            $inserted = RecordingDb::inserts('field');
            $this->assertCount(1, $inserted);
            $this->assertSame('_text...........', $inserted[0]['id']);
            $this->assertSame(1, $inserted[0]['input_show']);
        }

        /** the first field of a type has no number; a template without text_stored stores numbers, as the old copy did */
        public function test_firstFieldOfTypeAndUnsetTextStored(): void
        {
            $this->assertSame([], self::store(['New_Field' => 'Count'], ['New_Field' => '10'], [], [], self::CORE . '-number'));

            $inserted = RecordingDb::inserts('field');
            $this->assertCount(1, $inserted);
            $this->assertSame(['id' => 'number..........', 'input_help' => '', 'text_stored' => 0], self::columns($inserted[0], ['id', 'input_help', 'text_stored']));
        }

        /** a user added template has a full id - the new field is numbered by its type */
        public function test_templateOfAnotherOfferedSliceByFullId(): void
        {
            $err = AA_Fields::storeAdminRows(self::SLICE, ['New_Field' => 'Titulek 2'], ['New_Field' => '7'], [], [], self::OTHER . '-headline........', false, [self::CORE, self::SLICE, self::OTHER]);

            $this->assertSame([], $err);
            $inserted = RecordingDb::inserts('field');
            $this->assertCount(1, $inserted);
            $this->assertSame(['id' => 'headline.......1', 'slice_id' => self::SLICE, 'name' => 'Titulek 2', 'input_help' => 'from other', 'input_pri' => 7], self::columns($inserted[0], ['id', 'slice_id', 'name', 'input_help', 'input_pri']));
        }

        public function test_emptyNewFieldIsNotAdded(): void
        {
            $this->assertSame([], self::store(['New_Field' => ''], ['New_Field' => '1000'], [], [], self::CORE . '-text'));
            $this->assertSame([], self::writes());
        }

        public static function badTemplates(): array
        {
            return [
                'foreign slice'   => [self::OTHER . '-headline........'],
                'item column'     => [self::CORE . '-slice_id'],
                'unknown field'   => [self::CORE . '-nosuchfield'],
                'injected field'  => [self::CORE . "-text' OR 1=1 -- "],
                'backslash field' => [self::CORE . "-x\\' OR 1=1 -- "],
                'no slice'        => ['text'],
                'empty'           => [''],
            ];
        }

        /** the template must be an existing field of a slice offered on the page */
        #[\PHPUnit\Framework\Attributes\DataProvider('badTemplates')]
        public function test_badTemplateAddsNothing(string $ftype): void
        {
            $err = self::store(['New_Field' => 'Perex'], ['New_Field' => '1000'], [], [], $ftype);

            $this->assertNotSame([], $err);
            $this->assertSame([], self::writes());
            foreach (RecordingDb::$log as $sql) {
                $this->assertStringNotContainsString(self::OTHER, $sql, 'no look into a slice not offered on the page');
            }
        }

        /** the offered slices are those where the user may change fields - not those where he only writes items */
        public function test_templateSlicesByFieldsPermission(): void
        {
            self::perms([self::CORE => '', self::SLICE => PS_FIELDS, self::OTHER => PS_EDIT_SELF_ITEMS . PS_EDIT_ALL_ITEMS, self::THIRD => PS_FIELDS]);

            $this->assertSame([
                self::CORE  => '* Action Aplication Core',
                self::SLICE => '* Mine' . _m(' (this)'),
                self::THIRD => 'Third',
            ], AA_Fields::templateSlices(self::SLICE));
        }

        public function test_superadminIsOfferedAllSlices(): void
        {
            self::perms([self::CORE => '', self::SLICE => '', self::OTHER => '', self::THIRD => ''], \AA_Perm::ROLES['SUPER']['perm']);

            $this->assertSame([self::CORE, self::SLICE, self::OTHER, self::THIRD], array_keys(AA_Fields::templateSlices(self::SLICE)));
        }

        /** @return array the given columns of an inserted row, in the given order */
        private static function columns(array $row, array $cols): array
        {
            return array_map(static fn($col) => $row[$col] ?? 'missing', array_combine($cols, $cols));
        }
    }
}
