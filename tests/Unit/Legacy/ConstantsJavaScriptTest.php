<?php

declare(strict_types=1);

/**
 * createConstsJavaScript() in include/constedit_util.php: the rows of a constant group as the
 * JavaScript tree `new Array(new Array('name','value',pri,'desc',id,false,new Array(...)),...)`
 * which javascript/constedit.js reads (hcConsts[hcid]) - in the hierarchical constants editor
 * ($admin = true) and in input forms with the 'hco' widget ($admin = false, shorter nodes).
 *
 * Characterization part: pins the output for ordinary data (flat group, hierarchy, broken
 * ancestors, '#' for name == value, $get_method, quotes and CR/LF).
 * Security part (finding C1.11): the tree used to be PHP source text run through eval() and
 * then printed with the same escaping that ignored '\' - a constant value ending with '\'
 * turned the next field into PHP code, and '</script>' or '\' in a constant broke out of the
 * JS string and the <script> element. The DB is a RecordingDb answering the constant SELECT.
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

    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ConstantsJavaScriptTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/constedit_util.php';
            // the file sets it at its top level - global in the pages, a local of this method here
            $GLOBALS['hcCol'] = ['Name' => 0, 'Value' => 1, 'Prior' => 2, 'Desc' => 3, 'ID' => 4, 'Dirty' => 5, 'Child' => 6];
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            unset($GLOBALS['get_method'], $GLOBALS['hc_pwned']);
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            unset($GLOBALS['get_method'], $GLOBALS['hc_pwned']);
        }

        /** a 16 char constant id made of one letter */
        private static function id(string $letter): string
        {
            return str_repeat($letter, 16);
        }

        /** ids of the ancestors, root first, concatenated - as the `ancestors` column stores them */
        private static function path(string $letters): string
        {
            return implode('', array_map(self::id(...), str_split($letters)));
        }

        /**
         * One `constant` row as DB_PDO returns it (numbers as strings).
         * @param string $at letters of the ancestors (root first) + own id letter, e.g. 'ABC'
         */
        private static function row(string $at, string $name, string $value, int $pri, ?string $desc, int $short_id): array
        {
            return [
                'id'          => self::id(substr($at, -1)),
                'group_id'    => 'test_group',
                'name'        => $name,
                'value'       => $value,
                'class'       => null,
                'pri'         => (string) $pri,
                'ancestors'   => strlen($at) > 1 ? self::path(substr($at, 0, -1)) : '',
                'description' => $desc,
                'short_id'    => (string) $short_id,
            ];
        }

        /** createConstsJavaScript() over these rows (in DB order) */
        private static function js(array $rows, bool $admin = true): string
        {
            RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'FROM constant WHERE group_id') ? $rows : [];
            return createConstsJavaScript('test_group', $admin);
        }

        private static function flatRows(): array
        {
            return [
                self::row('C', 'Cherry', 'cherry', 20, 'Red fruit', 3),
                self::row('A', 'Apple', 'Apple', 10, null, 1),        // name == value -> '#'
                self::row('B', 'Banana', 'ban', 10, 'Yellow', 2),
                self::row('D', '1.0', '1', 30, '', 4),                // '#' would save '1.0' as the value
            ];
        }

        /**
         * A > B > C > D, A > B > E, A > F, G > H > J > K, I - three top nodes, going back up one
         * level (D -> E), a level (E -> F), to the top (F -> G) and three levels at once (K -> I).
         * Children have priorities against their key order to show they stay unsorted.
         */
        private static function treeRows(): array
        {
            return [
                self::row('I', 'Africa', 'af', 10, 'Continent 3', 9),
                self::row('ABE', 'Brno', 'brno', 1, 'City 2', 5),
                self::row('A', 'Europe', 'eu', 30, 'Continent 1', 1),
                self::row('AB', 'Czechia', 'cz', 50, 'Country 1', 2),
                self::row('ABC', 'Prague', 'prg', 5, 'City 1', 3),
                self::row('ABCD', 'Karlin', 'karlin', 1, 'District', 4),
                self::row('AF', 'Austria', 'at', 10, 'Country 2', 6),
                self::row('G', 'Asia', 'asia', 20, 'Continent 2', 7),
                self::row('GH', 'Japan', 'jp', 1, 'Country 3', 8),
                self::row('GHJ', 'Tokyo', 'tokyo', 1, 'City 3', 10),
                self::row('GHJK', 'Shibuya', 'shibuya', 1, 'District 2', 11),
            ];
        }

        /** rows whose ancestors do not form a tree - they end up as extra top level nodes */
        private static function brokenRows(): array
        {
            return [
                self::row('A', 'Root', 'root', 50, 'ok', 1),
                self::row('AB', 'Child', 'child', 1, 'ok', 2),
                self::row('ACX', 'Orphan under missing C', 'orphan', 1, 'parent missing', 3),  // becomes B's sibling
                self::row('AD', 'Second child', 'second', 1, 'lands on top level', 4),
                self::row('ADEF', 'Skips a layer', 'skip', 40, 'missing layer', 5),
                self::row('MN', 'Unknown parent', 'unknown', 30, 'same length, other parent', 6),
                self::row('.O', 'Sorted first', 'first', 20, 'first row not on top level', 7),
                ['ancestors' => 'ab'] + self::row('Q', 'Bad ancestors', 'bad', 10, 'length not a multiple of 16', 8),
            ];
        }

        private static function textRows(): array
        {
            return [
                self::row('A', "O'Brien", "it's\r\nok", 1, "line1\nline2 'q'", 1),
                self::row('B', "Tab\there", 'v"dq" > & ok', 2, 'desc "double" > & ok', 2),
                self::row('C', "Same'quote", "Same'quote", 3, "cr\ronly", 3),
            ];
        }

        // ---- characterization: the output for ordinary data ----------------------------------

        public function test_empty_group(): void
        {
            $this->assertSame('new Array()', self::js([]));
        }

        public function test_flat_group(): void
        {
            $this->assertSame(
                "new Array("
                . "new Array('Apple','#',10,'',1,false),"
                . "new Array('Banana','ban',10,'Yellow',2,false),"
                . "new Array('Cherry','cherry',20,'Red fruit',3,false),"
                . "new Array('1.0','1',30,'',4,false))",
                self::js(self::flatRows()),
            );
        }

        public function test_flat_group_not_admin(): void
        {
            $this->assertSame(
                "new Array(new Array('Apple','#'),new Array('Banana','ban'),new Array('Cherry','cherry'),new Array('1.0','1'))",
                self::js(self::flatRows(), false),
            );
        }

        public function test_hierarchical_group(): void
        {
            $this->assertSame(
                "new Array("
                . "new Array('Africa','af',10,'Continent 3',9,false),"
                . "new Array('Asia','asia',20,'Continent 2',7,false,new Array("
                . "new Array('Japan','jp',1,'Country 3',8,false,new Array("
                . "new Array('Tokyo','tokyo',1,'City 3',10,false,new Array("
                . "new Array('Shibuya','shibuya',1,'District 2',11,false))))))),"
                . "new Array('Europe','eu',30,'Continent 1',1,false,new Array("
                . "new Array('Czechia','cz',50,'Country 1',2,false,new Array("
                . "new Array('Prague','prg',5,'City 1',3,false,new Array("
                . "new Array('Karlin','karlin',1,'District',4,false))),"
                . "new Array('Brno','brno',1,'City 2',5,false))),"
                . "new Array('Austria','at',10,'Country 2',6,false))))",
                self::js(self::treeRows()),
            );
        }

        public function test_hierarchical_group_not_admin(): void
        {
            $this->assertSame(
                "new Array("
                . "new Array('Africa','af'),"
                . "new Array('Asia','asia',new Array(new Array('Japan','jp',new Array(new Array('Tokyo','tokyo',new Array(new Array('Shibuya','shibuya'))))))),"
                . "new Array('Europe','eu',new Array("
                . "new Array('Czechia','cz',new Array(new Array('Prague','prg',new Array(new Array('Karlin','karlin'))),new Array('Brno','brno'))),"
                . "new Array('Austria','at'))))",
                self::js(self::treeRows(), false),
            );
        }

        public function test_rows_with_broken_ancestors(): void
        {
            $this->assertSame(
                "new Array("
                . "new Array('Second child','second',1,'lands on top level',4,false),"
                . "new Array('Bad ancestors','bad',10,'length not a multiple of 16',8,false),"
                . "new Array('Sorted first','first',20,'first row not on top level',7,false),"
                . "new Array('Unknown parent','unknown',30,'same length, other parent',6,false),"
                . "new Array('Skips a layer','skip',40,'missing layer',5,false),"
                . "new Array('Root','root',50,'ok',1,false,new Array("
                . "new Array('Child','child',1,'ok',2,false),"
                . "new Array('Orphan under missing C','orphan',1,'parent missing',3,false))))",
                self::js(self::brokenRows()),
            );
        }

        public function test_rows_with_broken_ancestors_not_admin(): void
        {
            $this->assertSame(
                "new Array("
                . "new Array('Second child','second'),new Array('Bad ancestors','bad'),new Array('Sorted first','first'),"
                . "new Array('Unknown parent','unknown'),new Array('Skips a layer','skip'),"
                . "new Array('Root','root',new Array(new Array('Child','child'),new Array('Orphan under missing C','orphan'))))",
                self::js(self::brokenRows(), false),
            );
        }

        public function test_only_broken_rows(): void
        {
            $this->assertSame(
                "new Array(new Array('Lost too','lost2',1,'no parent',2,false),new Array('Lost','lost',2,'no parent',1,false))",
                self::js([
                    self::row('XY', 'Lost', 'lost', 2, 'no parent', 1),
                    self::row('XZ', 'Lost too', 'lost2', 1, 'no parent', 2),
                ]),
            );
        }

        public function test_quotes_and_line_breaks(): void
        {
            $this->assertSame(
                "new Array("
                . "new Array('O\\'Brien','it\\'sok',1,'line1line2 \\'q\\'',1,false),"
                . "new Array('Tab\there','v\"dq\" > & ok',2,'desc \"double\" > & ok',2,false),"
                . "new Array('Same\\'quote','#',3,'cronly',3,false))",
                self::js(self::textRows()),
            );
        }

        public function test_get_method_encodes_ampersand_in_value(): void
        {
            $GLOBALS['get_method'] = true;
            $this->assertSame(
                "new Array("
                . "new Array('Rock & Roll','rock%26roll',1,'a & b',1,false),"
                . "new Array('a&b','a%26b',2,'',2,false),"
                . "new Array('x','#',3,'',3,false))",
                self::js([
                    self::row('A', 'Rock & Roll', 'rock&roll', 1, 'a & b', 1),
                    self::row('B', 'a&b', 'a&b', 2, '', 2),      // the value becomes a%26b - no longer '#'
                    self::row('C', 'x', 'x', 3, '', 3),
                ]),
            );
        }

        // ---- security: data stays data --------------------------------------------------------

        /**
         * Reads back the JS createConstsJavaScript() emits - only `new Array(...)`, '...' strings,
         * integers and false. Anything else throws, so a string that broke out fails the parse.
         * Strings are decoded as JS does (\\ \' \xHH \uHHHH); a raw line terminator is refused.
         */
        private static function parseJs(string $js): array
        {
            $pos  = 0;
            $tree = self::parseArray($js, $pos);
            if ($pos !== strlen($js)) {
                throw new \UnexpectedValueException("text after the array at $pos: " . substr($js, $pos, 40));
            }
            return $tree;
        }

        private static function parseArray(string $js, int &$pos): array
        {
            if (substr($js, $pos, 10) !== 'new Array(') {
                throw new \UnexpectedValueException("'new Array(' expected at $pos: " . substr($js, $pos, 40));
            }
            $pos  += 10;
            $items = [];
            if (($js[$pos] ?? '') === ')') {
                ++$pos;
                return $items;
            }
            while (true) {
                $items[] = self::parseValue($js, $pos);
                $sep     = $js[$pos++] ?? '';
                if ($sep === ')') {
                    return $items;
                }
                if ($sep !== ',') {
                    throw new \UnexpectedValueException("',' or ')' expected at " . ($pos - 1) . ': ' . substr($js, $pos - 1, 40));
                }
            }
        }

        private static function parseValue(string $js, int &$pos): mixed
        {
            if (substr($js, $pos, 10) === 'new Array(') {
                return self::parseArray($js, $pos);
            }
            if (substr($js, $pos, 5) === 'false') {
                $pos += 5;
                return false;
            }
            if (preg_match('/\G-?\d+/', $js, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                return (int) $m[0];
            }
            if (preg_match("/\\G'((?:[^'\\\\\\r\\n\\x{2028}\\x{2029}]|\\\\(?:x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|[\\\\']))*)'/u", $js, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                return preg_replace_callback(
                    '/\\\\(x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|.)/u',
                    static fn(array $e): string => strlen($e[1]) > 1 ? mb_chr((int) hexdec(substr($e[1], 1)), 'UTF-8') : $e[1],
                    $m[1],
                );
            }
            throw new \UnexpectedValueException("value expected at $pos: " . substr($js, $pos, 40));
        }

        /** a value ending with '\' used to end the PHP string early - the description became PHP code */
        public function test_backslash_before_description_does_not_run_php(): void
        {
            $desc = ',1,"d",1,false)); $GLOBALS["hc_pwned"] = "yes"; //';
            $js   = self::js([self::row('A', 'N', 'x\\', 10, $desc, 5)]);

            $this->assertArrayNotHasKey('hc_pwned', $GLOBALS, 'the description was run as PHP code');
            $this->assertSame([['N', 'x\\', 10, $desc, 5, false]], self::parseJs($js));
        }

        /** the same through the name - the only free text the 'hco' form widget ($admin = false) reads */
        public function test_backslash_after_name_does_not_run_php(): void
        {
            $value = ',"v",1,"",1,false)); $GLOBALS["hc_pwned"] = "yes"; //';
            $js    = self::js([self::row('A', 'n\\', $value, 1, '', 1)], false);

            $this->assertArrayNotHasKey('hc_pwned', $GLOBALS, 'the value was run as PHP code');
            $this->assertSame([['n\\', $value]], self::parseJs($js));
        }

        /**
         * Asserts the JS is one well-formed tree holding exactly these rows - a parent chain,
         * rows[0] on top - and that nothing in it can end the <script> element.
         */
        private function assertChainRoundTrips(array $rows): void
        {
            foreach ([true, false] as $admin) {
                $js = self::js($rows, $admin);

                $this->assertStringNotContainsString('<', $js, 'no </script> nor <!-- can end or change the <script> element');
                $this->assertStringNotContainsString("\u{2028}", $js, 'U+2028 ends a JS string in older engines');
                $this->assertStringNotContainsString("\u{2029}", $js, 'U+2029 ends a JS string in older engines');

                $expected = [];
                foreach (array_reverse($rows) as $r) {
                    $node     = $admin ? [$r['name'], $r['value'], (int) $r['pri'], $r['description'], (int) $r['short_id'], false] : [$r['name'], $r['value']];
                    $expected = [$expected ? [...$node, $expected] : $node];
                }
                $this->assertSame($expected, self::parseJs($js), $admin ? 'admin' : 'form widget');
            }
        }

        /** '</script>', '<!--' and JS line terminators in any field, at any level */
        public function test_markup_and_line_terminators_stay_inside_the_string(): void
        {
            $this->assertChainRoundTrips([
                self::row('A', '</script><script>alert(1)</script>', '<!--', 1, "u2028 \u{2028} u2029 \u{2029}", 1),
                self::row('AB', '<b>bold</b>', '<img src=x onerror=alert(1)>', 2, '</SCRIPT >', 2),
                self::row('ABC', "a\u{2028}b", "<!-- -->", 3, "<\u{2029}>", 3),
            ]);
        }

        /** backslashes and quotes break neither the PHP nor the JS string */
        public function test_backslashes_and_quotes_stay_inside_the_string(): void
        {
            $this->assertChainRoundTrips([
                self::row('A', 'a\\', "\\'); alert(1); //", 1, "back\\slash 'single' \"double\"", 1),
                self::row('AB', "it's \"q\"", '\\x3C/script\\u003E', 2, '\\', 2),
                self::row('ABC', 'C:\\dir\\new', "\\\\'", 3, "end\\", 3),
            ]);
        }
    }
}
