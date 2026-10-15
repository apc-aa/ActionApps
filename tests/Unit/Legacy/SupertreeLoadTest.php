<?php

declare(strict_types=1);

/**
 * Characterization test for AA_Supertree::load() (include/tree.class.php) - the forward tree
 * of {tree}, {treestring}, {path} and {sortstring}: one item load per level, children read
 * from the relation field, links to trashed, missing or foreign-slice items dropped.
 *
 * The DB is a RecordingDb answering GetItemContent()'s two SELECTs from an in-memory tree.
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

    use AA_Module;
    use AA_Slice;
    use AA_Supertree;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** Slice without a reading password, registered where AA_Slice::getModule() looks (no DB). */
    final class TreeSlice extends AA_Slice
    {
        public function __construct(string $slice_id)
        {
            $this->module_id = $slice_id;
            AA_Module::$_modules[$slice_id] = $this;
        }

        public function getProperty($prop)
        {
            return null;
        }

        public static function unregister(string ...$slice_ids): void
        {
            foreach ($slice_ids as $slice_id) {
                unset(AA_Module::$_modules[$slice_id]);
            }
        }
    }

    final class SupertreeLoadTest extends TestCase
    {
        private const S1 = '11111111111111111111111111111111';
        private const S2 = '22222222222222222222222222222222';

        /** id => [slice, active, children] - ids are 'r', 'a', ... repeated to 32 hex chars */
        private const TREE = [
            'r' => [self::S1, true,  ['a', 'b']],
            'a' => [self::S1, true,  ['c', 'd']],
            'b' => [self::S2, true,  ['c', 'e', 'f']],   // c again; e trashed; f does not exist
            'c' => [self::S1, true,  []],
            'd' => [self::S1, true,  ['c']],
            'e' => [self::S1, false, ['c']],
        ];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/tree.class.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['err']      = [];
            RecordingDb::$answer = fn(string $sql) => $this->answer($sql);
            new TreeSlice(self::S1);
            new TreeSlice(self::S2);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TreeSlice::unregister(self::S1, self::S2);
        }

        private const IDS = ['r' => '9', 'a' => 'a', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'e', 'f' => 'f'];

        private static function id(string $short): string
        {
            return str_repeat(self::IDS[$short], 32);
        }

        /** 'r', 'a', ... for an id of the tree, '' for any other */
        private static function short(string $id): string
        {
            return (string) array_search($id[0] ?? '', self::IDS, true);
        }

        /** Answers the item SELECT (only active ones, as the bin condition would) and the content SELECT. */
        private function answer(string $sql): array
        {
            preg_match_all('/0x([0-9a-f]{32})/i', $sql, $m);
            $asked = array_filter(array_map(static fn($hex) => self::short(strtolower($hex)), $m[1]));
            $rows  = [];
            foreach ($asked as $short) {
                if (!isset(self::TREE[$short]) || !self::TREE[$short][1]) {
                    continue;
                }
                [$slice, , $children] = self::TREE[$short];
                if (str_starts_with($sql, 'SELECT id,slice_id FROM item')) {
                    $rows[] = ['id' => pack_id(self::id($short)), 'slice_id' => pack_id($slice)];
                } elseif (str_contains($sql, 'FROM content')) {
                    foreach (array_map(self::id(...), $children) ?: [''] as $n => $child) {   // a leaf stores an empty relation
                        $rows[] = ['item_id' => pack_id(self::id($short)), 'field_id' => 'relation........', 'number' => $n, 'text' => $child, 'flag' => 0];
                    }
                }
            }
            return $rows;
        }

        /** @return string[] short names of the ids */
        private static function shorts(array $ids): array
        {
            return array_map(self::short(...), $ids);
        }

        public function test_getIds_in_tree_order_without_dead_links(): void
        {
            $ids = (new AA_Supertree('relation........'))->getIds(self::id('r'));

            $this->assertSame(['r', 'a', 'c', 'd', 'c', 'b', 'c'], self::shorts($ids), "statements sent:\n" . implode("\n", RecordingDb::$log));
        }

        public function test_getTreeString_nests_the_children(): void
        {
            $tree = (new AA_Supertree('relation........'))->getTreeString(self::id('r'));

            $this->assertSame(strtr('r(a(c-d(c))-b(c))', ['r' => self::id('r'), 'a' => self::id('a'), 'b' => self::id('b'), 'c' => self::id('c'), 'd' => self::id('d')]), $tree);
        }

        public function test_getIds_with_restricted_slices_drops_the_other_slice(): void
        {
            $ids = (new AA_Supertree('relation........', null, [self::S1]))->getIds(self::id('r'));

            $this->assertSame(['r', 'a', 'c', 'd', 'c'], self::shorts($ids));
        }

        public function test_load_one_item_select_per_level(): void
        {
            (new AA_Supertree('relation........'))->getIds(self::id('r'));

            // levels r / a,b / c,d,e,f - each one item SELECT
            $this->assertSame(3, RecordingDb::count('SELECT id,slice_id FROM item'));
        }

        /** one supertree serves every {tree} of a request - an item found trashed for one root stays out of the next */
        public function test_item_trashed_in_an_earlier_load_stays_out_of_a_later_tree(): void
        {
            $tree = new AA_Supertree('relation........');

            $this->assertSame(['e'], self::shorts($tree->getIds(self::id('e'))));
            $this->assertSame(['r', 'a', 'c', 'd', 'c', 'b', 'c'], self::shorts($tree->getIds(self::id('r'))));
        }

        public function test_deleted_root_is_itself_and_is_not_looked_up_again(): void
        {
            $tree = new AA_Supertree('relation........');

            $this->assertSame(['f'], self::shorts($tree->getIds(self::id('f'))));
            $this->assertSame(self::id('f'), $tree->getTreeString(self::id('f')));
            $this->assertSame(1, RecordingDb::count('SELECT id,slice_id FROM item'));
        }
    }
}
