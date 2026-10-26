<?php

declare(strict_types=1);

/**
 * ViewSlices::requestGroupBy() - the group_by a request gave a listing: a field AA never shows groups
 * nothing (logged), another field or an alias stays, anything else is text.
 */

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

namespace Tests\Unit\Security {

    use AA\Security\ViewSlices;
    use AA\Util\LogOnce;
    use AA_Field;
    use AA_Module;
    use Closure;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ViewSlicesGroupByTest extends TestCase
    {
        /** a public slice */
        private const NEWS = '0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a';
        /** readers - text...........2 holds the copy of their password */
        private const READERS = '0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b';

        private const PARAM = 'set[42]=group_by-';

        public static function setUpBeforeClass(): void
        {
            require_once dirname(__DIR__) . '/Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            (new FakeSlice(self::NEWS))->setFields(new FakeFields(self::NEWS, [self::field(self::NEWS, 'headline........'), self::field(self::NEWS, 'text...........2')]));
            (new FakeSlice(self::READERS))->setFields(new FakeFields(self::READERS, [
                self::field(self::READERS, 'headline........'),
                self::field(self::READERS, 'password........', 'pwd:text...........2:phrase'),
                self::field(self::READERS, 'text...........2'),
            ]));
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
            $ids = [self::NEWS, self::READERS];
            Closure::bind(static function () use ($ids): void {
                foreach ($ids as $id) {
                    unset(AA_Module::$_modules[$id]);
                }
            }, null, AA_Module::class)();
        }

        private static function field(string $slice_id, string $id, string $insert = ''): AA_Field
        {
            return new AA_Field(['id' => $id, 'slice_id' => pack_id($slice_id), 'text_stored' => 1, 'input_insert_func' => $insert] + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        /** @return string[] the selectors of the log rows written */
        private static function logged(): array
        {
            $inserts = array_filter(RecordingDb::$log, static fn($s) => str_starts_with(ltrim($s), 'INSERT INTO `log`'));
            return array_values(array_map(static fn($s) => preg_match("/'(field:[^']*)'/", $s, $m) ? $m[1] : $s, $inserts));
        }

        public function test_requestGroupBy_fieldOrAlias_kept(): void
        {
            self::assertSame('headline........', ViewSlices::requestGroupBy(self::PARAM, 'headline........', [self::NEWS, self::READERS]));
            self::assertSame('_#HEADLINE', ViewSlices::requestGroupBy(self::PARAM, '_#HEADLINE', [self::READERS]));
            self::assertSame('HEADLINE', ViewSlices::requestGroupBy(self::PARAM, 'HEADLINE', [self::READERS]));
            self::assertSame('', ViewSlices::requestGroupBy(self::PARAM, '', [self::READERS]));
            self::assertSame([], self::logged());
        }

        /** the copy of a password under a generic id, in any listed slice - not only the first one */
        public function test_requestGroupBy_protectedField_groupsNothing(): void
        {
            self::assertSame('', ViewSlices::requestGroupBy(self::PARAM, 'password........', [self::READERS]));
            self::assertSame('', ViewSlices::requestGroupBy(self::PARAM, 'text...........2', [self::NEWS, self::READERS]));

            self::assertSame([ParamImplode([ViewSlices::PROTECTED_FIELD, self::READERS, 'password........']), ParamImplode([ViewSlices::PROTECTED_FIELD, self::READERS, 'text...........2'])], self::logged());
        }

        /** a password or secret has its value in the item even where the slice does not define the field */
        public function test_requestGroupBy_protectedTypeNotDefined_groupsNothing(): void
        {
            self::assertSame('', ViewSlices::requestGroupBy(self::PARAM, 'secret.........1', [self::NEWS]));
            self::assertSame([], self::logged(), 'the URL chooses the id - no row for it');
        }

        /** items listed by their ids (cmd[]=x-) are bound to no slice when set[]=sort- drops the sort - each item's slice counts */
        public function test_requestGroupBy_itemsOfAnySlice_eachSliceAsked(): void
        {
            $news   = '1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a';
            $reader = '1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b';
            $slices = [$news => self::NEWS, $reader => self::READERS];
            RecordingDb::$answer = static function (string $sql) use ($slices): array {
                if (!preg_match('/^SELECT [\\w,*]+ FROM item WHERE/', $sql)) {
                    return [];
                }
                preg_match_all('/0x([0-9a-f]{32})/', $sql, $m);
                return array_map(static fn(string $id): array => array_merge(array_fill_keys(\AA::Metabase()->getColumnNames('item'), ''), [
                    'short_id' => '1', 'id' => pack_id($id), 'slice_id' => pack_id($slices[$id]), 'status_code' => '1', 'publish_date' => '0', 'expiry_date' => '2000000000',
                ]), array_values(array_intersect($m[1], array_keys($slices))));
            };

            self::assertSame('', ViewSlices::requestGroupBy(self::PARAM, 'text...........2', new \zids([$news, $reader], 'l')));
            self::assertSame([ParamImplode([ViewSlices::PROTECTED_FIELD, self::READERS, 'text...........2'])], self::logged());
            self::assertSame('headline........', ViewSlices::requestGroupBy(self::PARAM, 'headline........', new \zids([$news, $reader], 'l')));
        }

        /** a heading that is no field or alias would run as a template in the item - it is text, as noitem */
        public function test_requestGroupBy_otherText_isText(): void
        {
            self::assertSame('&lt;b&gt;x_&#35;ABCDEFGH', ViewSlices::requestGroupBy(self::PARAM, '<b>x_#ABCDEFGH', [self::NEWS]));
        }
    }
}
