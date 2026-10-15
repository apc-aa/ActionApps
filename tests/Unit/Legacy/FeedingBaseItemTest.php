<?php

declare(strict_types=1);

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

    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    /**
     * GetBaseItem() and IsItemFed() - feeding traces an item back through the relation table to
     * decide whether it already reached a slice (then it is not fed again). include/feeding.php pulls in stringexpand.php, so
     * the two functions are extracted from the real file.
     */
    final class FeedingBaseItemTest extends TestCase
    {
        private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        /** destination item => source item of a feed relation */
        private array $fed = [];
        /** item => slice */
        private array $slice = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            if (function_exists('aa_test_GetBaseItem')) {
                return;
            }
            $source = (string) file_get_contents(__DIR__ . '/../../../include/feeding.php');
            $rename = ['GetBaseItem(' => 'aa_test_GetBaseItem(', 'IsItemFed(' => 'aa_test_IsItemFed(', 'DB_AA::' => '\AA\IO\DB\DB_AA::'];
            eval(strtr(self::extract($source, 'GetBaseItem'), $rename));
            eval(strtr(self::extract($source, 'IsItemFed'), $rename));
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            RecordingDb::$answer = fn(string $sql) => $this->answer($sql);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        private function answer(string $sql): array
        {
            if (str_starts_with($sql, 'SELECT source_id FROM relation WHERE') && preg_match('/destination_id = 0x([0-9a-f]{32})/i', $sql, $m)) {
                return isset($this->fed[$m[1]]) ? [['source_id' => pack_id($this->fed[$m[1]])]] : [];
            }
            if (str_starts_with($sql, 'SELECT slice_id FROM `item`') && preg_match('/id = 0x([0-9a-f]{32})/i', $sql, $m)) {
                return isset($this->slice[$m[1]]) ? [['slice_id' => pack_id($this->slice[$m[1]])]] : [];
            }
            return [];
        }

        public function test_base_of_a_fed_item_is_its_source(): void
        {
            $this->fed = [self::B => self::A];

            $this->assertSame(self::A, aa_test_GetBaseItem(self::B));
        }

        public function test_item_fed_to_itself_ends_the_trace(): void
        {
            $this->fed = [self::A => self::A];

            $this->assertSame(self::A, aa_test_GetBaseItem(self::A));
        }

        public function test_loop_of_two_ends_the_trace(): void
        {
            $this->fed = [self::A => self::B, self::B => self::A];

            $this->assertContains(aa_test_GetBaseItem(self::A), [self::A, self::B], 'a loop has no real base - it just has to end');
        }

        public function test_item_from_the_destination_slice_counts_as_fed(): void
        {
            // a packed id holding a quote or a backslash byte - one of about five slice ids
            $slice       = '27aa5caa00aa5caa27aa5caa00aa5caa';
            $this->slice = [self::A => $slice];

            $this->assertTrue(aa_test_IsItemFed(self::A, $slice));
            $this->assertFalse(aa_test_IsItemFed(self::A, 'cccccccccccccccccccccccccccccccc'));
        }

        /** Source of one function, from its keyword to the closing brace (comments/strings safe) */
        private static function extract(string $source, string $name): string
        {
            $tokens = token_get_all($source);
            $start  = null;
            $depth  = 0;
            $out    = '';
            foreach ($tokens as $i => $token) {
                if (is_null($start)) {
                    if (is_array($token) && $token[0] === T_FUNCTION && ($tokens[$i + 2][1] ?? '') === $name) {
                        $start = $i;
                    } else {
                        continue;
                    }
                }
                $text = is_array($token) ? $token[1] : $token;
                $out .= $text;
                if ($text === '{') {
                    $depth++;
                } elseif ($text === '}' && --$depth === 0) {
                    return $out;
                }
            }
            self::fail("$name not found in include/feeding.php");
        }
    }
}
