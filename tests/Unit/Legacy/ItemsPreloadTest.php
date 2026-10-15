<?php

declare(strict_types=1);

/**
 * Characterization test for the two per-item item loaders that now prefetch a whole level:
 *   - AA_Supertree::getMenu()              (include/tree.class.php)   - {menu:...}
 *   - AA_Stringexpand_Fulltext::expand()   (include/stringexpand.php) - {fulltext:...}
 *
 * Both used to call AA_Items::getItem() per id, i.e. one item + one content SELECT
 * per id outside the per-request cache. They now AA_Items::preload() the ids first,
 * so a level costs two SELECTs however many ids it has. The rendered output must not
 * change at all - the expected strings below were captured against the per-id code.
 *
 * The DB is a RecordingDb answering the item/content SELECTs from an in-memory
 * item list (see items()); no database, session or slice password is involved.
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

    use AA;
    use AA_Items;
    use AA_Module;
    use AA_Slice;
    use AA_Stringexpand_Fulltext;
    use AA_Supertree;
    use AA_Supertree_Reverse;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /** Slice fake with readable properties and a fixed alias table (no DB behind getFields()). */
    final class PreloadSlice extends AA_Slice
    {
        public function __construct(string $slice_id, private array $props)
        {
            $this->module_id = $slice_id;
            AA_Module::$_modules[$slice_id] = $this;    // what AA_Slice::getModule() returns
        }

        public function getId(): string
        {
            return $this->module_id;
        }

        public function isValid(): bool
        {
            return true;
        }

        public function getProperty($prop)
        {
            return $this->props[$prop] ?? null;
        }

        public function aliases($additional = null, $type = ''): array
        {
            return ['_#HEADLINE' => GetAliasDef('f_h', 'headline........', '')];
        }

        public function isField($fname): bool
        {
            return in_array($fname, $this->props['fields'] ?? [], true);
        }
    }

    /** Exposes the slice filter of the real reverse tree. */
    final class ExposedReverseTree extends AA_Supertree_Reverse
    {
        public function slicesWithRelationField(array $slices): array
        {
            return $this->_slicesWithRelationField($slices);
        }
    }

    /** Supertree with a preset child map, so getMenu() needs no relation loader. */
    final class PresetSupertree extends AA_Supertree
    {
        public function preset(array $children): void
        {
            $this->_i = $children;
        }
    }

    final class ItemsPreloadTest extends TestCase
    {
        private const SLICE = '11111111111111111111111111111111';
        private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        private const C = 'cccccccccccccccccccccccccccccccc';
        private const D = 'dddddddddddddddddddddddddddddddd';
        private const E = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        private const MISSING = 'ffffffffffffffffffffffffffffffff';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
            $GLOBALS['err']      = [];
            AA_Items::singleton()->doInvalidate();
            new PreloadSlice(self::SLICE, [
                'fulltext_format_top'    => '[',
                'fulltext_format'        => '{_#HEADLINE}',
                'fulltext_format_bottom' => ']',
            ]);
            RecordingDb::$answer = fn(string $sql) => $this->answer($sql);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            AA::$perm            = null;
        }

        /** id => [short_id, status_code, headline] */
        private function items(): array
        {
            return [
                self::A => [1, 1, 'TA'],
                self::B => [2, 1, 'TB'],
                self::C => [3, 2, 'TC'],     // holding bin - not active
                self::D => [4, 1, 'TD'],
                self::E => [5, 1, 'TE'],
            ];
        }

        /** Answers GetItemContent()'s two SELECTs from items(); the ids come as 0x... literals. */
        private function answer(string $sql): array
        {
            preg_match_all('/0x([0-9a-f]{32})/i', $sql, $long_ids);
            $asked = array_intersect_key($this->items(), array_flip(array_map('strtolower', $long_ids[1])));
            if (preg_match('/item\.short_id\s*(?:=|IN)\s*\(?([^)\s]+)/', $sql, $short_ids)) {   // short ids come as '4','5'
                $shorts = array_map(static fn($v) => (int) trim($v, "'"), explode(',', $short_ids[1]));
                $asked += array_filter($this->items(), static fn($row) => in_array($row[0], $shorts, true));
            }
            $rows  = [];
            if (str_starts_with($sql, 'SELECT * FROM item WHERE')) {
                $columns = array_fill_keys(AA::Metabase()->getColumnNames('item'), '');
                foreach ($asked as $id => [$short_id, $status, $headline]) {
                    $rows[] = [
                        'id'           => pack_id($id),
                        'slice_id'     => pack_id(self::SLICE),
                        'short_id'     => $short_id,
                        'status_code'  => $status,
                        'publish_date' => 0,
                        'expiry_date'  => 4000000000,
                    ] + $columns;
                }
            } elseif (str_starts_with($sql, 'SELECT * FROM content WHERE')) {
                foreach ($asked as $id => [$short_id, $status, $headline]) {
                    $rows[] = ['item_id' => pack_id($id), 'field_id' => 'headline........', 'number' => 0, 'text' => $headline, 'flag' => FLAG_TEXT_STORED];
                }
            }
            return $rows;
        }

        private function assertItemLoads(int $item_selects, int $content_selects): void
        {
            $this->assertSame(
                ['item' => $item_selects, 'content' => $content_selects],
                ['item' => RecordingDb::count('SELECT * FROM item WHERE'), 'content' => RecordingDb::count('SELECT * FROM content WHERE')],
                "statements sent:\n" . implode("\n", RecordingDb::$log),
            );
        }

        private function menuTree(): PresetSupertree
        {
            $tree = new PresetSupertree('relation........');
            $tree->preset([self::A => [], self::B => [self::D, self::E], self::C => [], self::D => [], self::E => []]);
            return $tree;
        }

        // ---------------------------------------------------------------- {menu:}

        public function test_expanded_menu_renders_the_same_html_as_the_per_item_loader(): void
        {
            $html = $this->menuTree()->getMenu([self::A, self::B, self::C], [self::B, self::D], 'headline........', false, true);

            $this->assertSame(
                "\n<ul>\n"
                . "  <li id=\"menu-" . self::A . "\" class=\"\">TA</li>\n"
                . "  <li id=\"menu-" . self::B . "\" class=\"inpath\">TB\n<ul>\n"
                . "  <li id=\"menu-" . self::D . "\" class=\"inpath active xidmatch\">TD</li>\n"
                . "  <li id=\"menu-" . self::E . "\" class=\"\">TE</li>\n"
                . "</ul>\n</li>\n"
                . "  <li id=\"menu-" . self::C . "\" class=\"\">TC</li>\n"
                . "</ul>\n",
                $html,
            );
        }

        public function test_expanded_menu_stops_at_a_cycle(): void
        {
            $tree = new PresetSupertree('relation........');
            $tree->preset([self::A => [self::B], self::B => [self::A, self::D], self::D => []]);

            $html = $tree->getMenu([self::A], [], 'headline........', false, true);

            $this->assertSame(
                "\n<ul>\n"
                . "  <li id=\"menu-" . self::A . "\" class=\"\">TA\n<ul>\n"
                . "  <li id=\"menu-" . self::B . "\" class=\"\">TB\n<ul>\n"
                . "  <li id=\"menu-" . self::A . "\" class=\"\">TA</li>\n"
                . "  <li id=\"menu-" . self::D . "\" class=\"\">TD</li>\n"
                . "</ul>\n</li>\n"
                . "</ul>\n</li>\n"
                . "</ul>\n",
                $html,
            );
        }

        public function test_a_menu_level_costs_one_item_load_not_one_per_entry(): void
        {
            $this->menuTree()->getMenu([self::A, self::B, self::C], [self::B, self::D], 'headline........', false, true);

            // two levels (A,B,C and D,E) - each one item SELECT + one content SELECT
            $this->assertItemLoads(2, 2);
        }

        public function test_collapsed_menu_loads_only_the_top_level(): void
        {
            $html = $this->menuTree()->getMenu([self::A, self::B, self::C], [], 'headline........');

            $this->assertSame(
                "\n<ul>\n"
                . "  <li id=\"menu-" . self::A . "\">TA</li>\n"
                . "  <li id=\"menu-" . self::B . "\">TB</li>\n"
                . "  <li id=\"menu-" . self::C . "\">TC</li>\n"
                . "</ul>\n",
                $html,
            );
            $this->assertItemLoads(1, 1);
        }

        public function test_unknown_menu_id_is_skipped_and_costs_one_extra_lookup(): void
        {
            $html = $this->menuTree()->getMenu([self::A, self::MISSING, self::C], [], 'headline........');

            $this->assertSame(
                "\n<ul>\n"
                . "  <li id=\"menu-" . self::A . "\">TA</li>\n"
                . "  <li id=\"menu-" . self::C . "\">TC</li>\n"
                . "</ul>\n",
                $html,
            );
            // preload asks for all three; the missing one is retried alone by getItem() - as before
            $this->assertItemLoads(2, 1);
        }

        public function test_already_cached_items_are_not_loaded_again(): void
        {
            AA_Items::getItem(self::A);
            RecordingDb::reset();
            RecordingDb::$answer = fn(string $sql) => $this->answer($sql);

            $this->menuTree()->getMenu([self::A, self::B], [], 'headline........');

            $this->assertItemLoads(1, 1);
            $item_selects = implode("\n", array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'SELECT * FROM item')));
            $this->assertStringNotContainsStringIgnoringCase(self::A, $item_selects, 'A must not be asked for again');
        }

        // -------------------------------------------------- AA_Supertree_Reverse

        public function test_reverse_tree_queries_only_slices_that_define_the_relation_field(): void
        {
            $with    = '22222222222222222222222222222222';
            $without = '33333333333333333333333333333333';
            $unknown = '44444444444444444444444444444444';   // no module at all - getModule() gives null
            new PreloadSlice($with, ['fields' => ['relation........']]);
            new PreloadSlice($without, ['fields' => ['headline........']]);

            $tree = new ExposedReverseTree('relation........');

            $this->assertSame([$with], $tree->slicesWithRelationField([$without, $with, $unknown]));
            $this->assertSame([], $tree->slicesWithRelationField([$without]));
        }

        // ---------------------------------------------------------------- AA_Items::preload()

        public function test_preload_asks_once_for_the_uncached_ids_and_caches_the_known_ones(): void
        {
            AA_Items::getItem(self::A);
            RecordingDb::reset();
            RecordingDb::$answer = fn(string $sql) => $this->answer($sql);

            AA_Items::preload(new \zids([self::A, self::B, self::MISSING, self::D, self::C], 'l'));

            $this->assertItemLoads(1, 1);
            $item_select = implode("\n", array_filter(RecordingDb::$log, static fn($s) => str_starts_with($s, 'SELECT * FROM item')));
            preg_match_all('/0x([0-9a-f]{32})/i', $item_select, $asked);
            $this->assertSame([self::B, self::MISSING, self::D, self::C], array_map('strtolower', $asked[1]));

            RecordingDb::reset();
            foreach ([self::A => 'TA', self::B => 'TB', self::C => 'TC', self::D => 'TD'] as $id => $headline) {
                $this->assertSame($headline, AA_Items::getItem($id)?->f_1('headline........'));
            }
            $this->assertItemLoads(0, 0);
        }

        public function test_preload_of_short_ids(): void
        {
            AA_Items::preload(new \zids(['2', '4'], 's'));
            RecordingDb::reset();

            $this->assertSame('TB', AA_Items::getItem(new \zids('2', 's'))?->f_1('headline........'));
            $this->assertSame('TD', AA_Items::getItem(new \zids('4', 's'))?->f_1('headline........'));
            $this->assertItemLoads(0, 0);
        }

        public function test_preload_of_cached_ids_asks_nothing(): void
        {
            AA_Items::preload(new \zids([self::A, self::B], 'l'));
            RecordingDb::reset();

            AA_Items::preload(new \zids([self::B, self::A], 'l'));

            $this->assertItemLoads(0, 0);
        }

        // ---------------------------------------------------------------- {fulltext:}

        public function test_fulltext_renders_active_items_in_order_and_skips_inactive(): void
        {
            $out = (new AA_Stringexpand_Fulltext([]))->expand(self::B . '-' . self::C . '-' . self::A);

            $this->assertSame('[TB][TA]', $out);   // C is in the holding bin
        }

        public function test_fulltext_loads_all_ids_at_once(): void
        {
            (new AA_Stringexpand_Fulltext([]))->expand(self::B . '-' . self::C . '-' . self::A . '-' . self::D);

            $this->assertItemLoads(1, 1);
        }

        public function test_fulltext_mixes_short_and_long_ids(): void
        {
            // short ids and long ids cannot share one zids - they are loaded per id type
            $out = (new AA_Stringexpand_Fulltext([]))->expand(self::A . '-4-' . self::B);

            $this->assertSame('[TA][TD][TB]', $out);
        }

        public function test_fulltext_ignores_garbage_and_empty_input(): void
        {
            $this->assertSame('', (new AA_Stringexpand_Fulltext([]))->expand(''));
            $this->assertSame('', (new AA_Stringexpand_Fulltext([]))->expand('not-an-id'));
            $this->assertItemLoads(0, 0);
        }
    }
}
