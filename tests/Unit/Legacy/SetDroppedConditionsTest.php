<?php

declare(strict_types=1);

/**
 * Conditions AA_Set::query() leaves out of the search without a word - a field the slice lacks, an empty value,
 * a value the search cannot parse... The search then finds more items than asked, so a mailing selecting its
 * readers by them would go to everyone. droppedConditions() names them, without asking for any item.
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

namespace Tests\Unit\Legacy {

    use AA\Util\LogOnce;
    use AA_Condition;
    use AA_Field;
    use AA_Module;
    use AA_Set;
    use Closure;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\FakeFields;
    use Tests\Unit\Legacy\Fixtures\FakeSlice;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class SetDroppedConditionsTest extends TestCase
    {
        /** the searched slice; relation.......1 points to TARGET, text...........2 is no relation */
        private const OWN = '1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a';
        private const TARGET = '1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b1b';
        /** its relation.......2 points to OWN */
        private const SOURCE = '1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c1c';
        /** no module has this id */
        private const NONE = '1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d1d';

        /** the search with no condition at all - every active item of the slice */
        private const ALL = 'SELECT DISTINCT item.id as itemid FROM item WHERE item.slice_id = 0x' . self::OWN
            . " AND item.status_code=1 AND item.publish_date <= '%NOW%' AND item.expiry_date > '%NOW%' ORDER BY item.publish_date DESC";

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['slice_info'] = $GLOBALS['vid'] = $GLOBALS['view_info'] = null;   // read by the SQL comment

            (new FakeSlice(self::OWN))->setFields(new FakeFields(self::OWN, [
                self::field(self::OWN, 'headline........'),
                self::field(self::OWN, 'number..........', 'fld', '', 0),
                self::field(self::OWN, 'text...........2'),
                self::field(self::OWN, 'relation.......1', 'sel:#sLiCe-' . self::TARGET),
            ]));
            (new FakeSlice(self::TARGET))->setFields(new FakeFields(self::TARGET, [self::field(self::TARGET, 'headline........')]));
            (new FakeSlice(self::SOURCE))->setFields(new FakeFields(self::SOURCE, [
                self::field(self::SOURCE, 'headline........'),
                self::field(self::SOURCE, 'relation.......2', 'sel:#sLiCe-' . self::OWN),
            ]));
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['slice_info'], $GLOBALS['vid'], $GLOBALS['view_info']);
            RecordingDb::reset();
            LogOnce::reset();
            Closure::bind(static function (string ...$ids): void {
                foreach ($ids as $id) {
                    unset(AA_Module::$_modules[$id]);
                }
            }, null, AA_Module::class)(self::OWN, self::TARGET, self::SOURCE, self::NONE);
        }

        private static function field(string $slice_id, string $id, string $widget = '', string $insert = '', int $text_stored = 1): AA_Field
        {
            return new AA_Field(['id' => $id, 'slice_id' => pack_id($slice_id), 'text_stored' => $text_stored, 'input_show_func' => $widget, 'input_insert_func' => $insert] + array_fill_keys(AA_Field::USED_FIELDS, ''));
        }

        /** the legacy search code warns about view globals and fields of a missing slice */
        private static function quietly(callable $fn): mixed
        {
            set_error_handler(static fn(int $no): bool => ($no === E_WARNING));
            try {
                return $fn();
            } finally {
                restore_error_handler();
            }
        }

        /** @return list<string> */
        private static function dropped(AA_Set $set): array
        {
            return self::quietly(static fn(): array => $set->droppedConditions());
        }

        /** the search AA_Set sends, without the debug comment and quoted as by MySQL (the test db is SQLite) */
        private static function sql(string $conds): string
        {
            RecordingDb::$log = [];
            $set = new AA_Set(self::OWN, $conds);
            self::quietly(static fn() => $set->query());
            $selects = array_values(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'as itemid')));
            self::assertCount(1, $selects);
            $sql = str_replace('"', '`', preg_replace('/\s+/', ' ', explode(' -- ', $selects[0])[0]));
            return trim(preg_replace("/(publish_date <= |expiry_date > )'\\d+'/", "\$1'%NOW%'", $sql));
        }

        /** @return array<string, array{string, list<string>}> conds => what is dropped */
        public static function droppedConds(): array
        {
            $target = self::TARGET;
            $source = self::SOURCE;
            return [
                'unknown field'                   => ['d-text...........9-==-x', ['text...........9-==-x']],
                'unknown field with a language'   => ['d-text...........9|en-==-x', ['text...........9|en-==-x']],
                'all_fields with a typo'          => ['d-all_fieldsx-==-x', ['all_fieldsx-==-x']],
                'a name that is no field'         => ['d-discussion-==-x', ['discussion-==-x']],
                'the name of the value'           => ['d-value-==-x', ['value-==-x']],
                'empty value'                     => ['d-headline........-==-', ['headline........-==-']],
                'empty value of LIKE'             => ['d-headline........-LIKE-', ['headline........-LIKE-']],
                'syntax error of RLIKE'           => ['d-headline........-RLIKE-"x', ['headline........-RLIKE-"x']],
                'unknown operator'                => ['d-headline........-XYZ-x', ['headline........-XYZ-x']],
                'relation field that is no field' => ['d-headline........@relation.......9-==-x', ['headline........@relation.......9-==-x']],
                'all_fields over no field'        => ['d-all_fields@relation.......9-==-x', ['all_fields@relation.......9-==-x']],
                'relation field that is no relation' => ['d-headline........@text...........2-==-x', ['headline........@text...........2-==-x']],
                'far field the target lacks'      => ['d-text...........7@relation.......1-==-x', ['text...........7@relation.......1-==-x']],
                'field the remote slice lacks'    => ["d-text...........7@$source/relation.......2-==-x", ["text...........7@$source/relation.......2-==-x"]],
                'remote relation field it lacks'  => ["d-headline........@$target/relation.......9-==-x", ["headline........@$target/relation.......9-==-x"]],
                'remote slice that is none'       => ['d-headline........@' . self::NONE . '/relation.......2-==-x', ['headline........@' . self::NONE . '/relation.......2-==-x']],
            ];
        }

        /** today's search: the condition is not in it, every item is found */
        #[DataProvider('droppedConds')]
        public function test_query_droppedCondition_searchesWithoutIt(string $conds): void
        {
            self::assertSame(self::ALL, self::sql($conds));
        }

        #[DataProvider('droppedConds')]
        public function test_droppedConditions_namesIt(string $conds, array $expected): void
        {
            self::assertSame($expected, self::dropped(new AA_Set(self::OWN, $conds)));
        }

        /** @return array<string, array{string}> */
        public static function keptConds(): array
        {
            return [
                'known field'                => ['d-headline........-==-x'],
                'two known fields'           => ['d-headline........,text...........2-LIKE-x'],
                'ISNULL without a value'     => ['d-headline........-ISNULL-'],
                'number field compared'      => ['d-number..........->-5'],
                'known field with language'  => ['d-headline........|en-==-x'],
                'all_fields'                 => ['d-all_fields-LIKE-x'],
                'far field of the relation'  => ['d-headline........@relation.......1-==-x'],
                'any field of the relation'  => ['d-all_fields@relation.......1-==-x'],
                'field of the remote slice'  => ['d-headline........@' . self::SOURCE . '/relation.......2-==-x'],
                // matches nothing, which narrows the search - nothing to warn about
                'number field with text'     => ['d-number..........-==-abc'],
            ];
        }

        #[DataProvider('keptConds')]
        public function test_query_keptCondition_isInTheSearch(string $conds): void
        {
            self::assertNotSame(self::ALL, self::sql($conds));
        }

        #[DataProvider('keptConds')]
        public function test_droppedConditions_keptCondition_none(string $conds): void
        {
            self::assertSame([], self::dropped(new AA_Set(self::OWN, $conds)));
        }

        /** a field of two unknown: the condition still searches the other one, yet it says something else than written */
        public function test_droppedConditions_oneOfTwoFieldsUnknown_named(): void
        {
            self::assertSame(['headline........,text...........9-==-x'], self::dropped(new AA_Set(self::OWN, 'd-headline........,text...........9-==-x')));
        }

        public function test_droppedConditions_onlyTheDroppedOnes_inTheirOrder(): void
        {
            $set = new AA_Set(self::OWN, 'd-text...........9-==-x-headline........-==-y-headline........-==-');
            $set->addCondition(new AA_Condition('text...........8', '==', '1'));

            self::assertSame(['text...........9-==-x', 'headline........-==-', 'text...........8-==-1'], self::dropped($set));
        }

        public function test_droppedConditions_asksForNoItem(): void
        {
            RecordingDb::$log = [];

            self::dropped(new AA_Set(self::OWN, 'd-text...........9-==-x-headline........-==-y'));

            self::assertSame([], array_values(array_filter(RecordingDb::$log, static fn($s) => str_contains($s, 'as itemid'))));
        }
    }
}
