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
     * getConstantValue() behind {const_name:<field>}, {constant:…} and the (const_*) list parameters.
     * An item value that is not in the constant group (a deleted constant, free text) must give an empty
     * string - {const_*} passes the result to QuoteColons(string), so null was a fatal TypeError on live views.
     *
     * include/stringexpand.php cannot be loaded on its own, so the function is extracted from the real file.
     */
    final class ConstantValueTest extends TestCase
    {
        private const ROWS = [
            ['id' => "\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11\x11", 'group_id' => 'colors', 'name' => 'Red', 'value' => 'r', 'class' => '', 'pri' => '100', 'ancestors' => '', 'description' => 'warm', 'short_id' => '7'],
        ];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/constants.php';
            if (function_exists('aa_test_getConstantValue')) {
                return;
            }
            $source = (string) file_get_contents(__DIR__ . '/../../../include/stringexpand.php');
            eval(str_replace('function getConstantValue(', 'function aa_test_getConstantValue(', self::extract($source, 'getConstantValue')));
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            \AA::Contentcache()->clear_soft();
            $GLOBALS['spareDBs'] = [new RecordingDb(), new RecordingDb()];
            RecordingDb::$answer = static fn(string $sql) => str_contains($sql, 'FROM constant') ? self::ROWS : [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            \AA::Contentcache()->clear_soft();
        }

        public function test_known_value_gives_the_column(): void
        {
            $this->assertSame('Red', aa_test_getConstantValue('colors', 'name', 'r'));
            $this->assertSame('warm', aa_test_getConstantValue('colors', 'description', 'r'));
        }

        public function test_value_outside_the_group_gives_empty_string(): void
        {
            $this->assertSame('', aa_test_getConstantValue('colors', 'name', 'not-a-constant'));
            $this->assertSame('', aa_test_getConstantValue('colors', 'name', ''));
            $this->assertSame('', aa_test_getConstantValue('colors', '_#NAME###_', 'not-a-constant'), 'expression branch');
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
            self::fail("$name not found in include/stringexpand.php");
        }
    }
}
