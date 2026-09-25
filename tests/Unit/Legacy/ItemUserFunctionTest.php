<?php

declare(strict_types=1);

/**
 * Alias function f_u ("call a user function", AA_Item::f_u()) calls only a user function of the
 * installation: a usr_* name defined by PHP code (not internal), taken from the parameter before
 * alias substitution. Anything else - an internal function, a name an alias or a field value would
 * supply through the substitution - gives '' and a debug message. The rest of the parameter is still
 * alias-substituted as before. (In {alias:<field>:f_u:{..}} the inner {..} are expanded before f_u
 * runs, so there item data can pick which usr_* function - still only usr_*; not tested here.)
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

    /** a user function as an installation's include/usr_aliasfnc.php defines it; echoes what f_u
     *  passes: the field and the parameter string (already alias-substituted) */
    function usr_test_fu_echo(array $content, string $col, string $param): string
    {
        return "$col|$param|" . count($content);
    }
}

namespace Tests\Unit\Legacy {

    use AA\Util\UserAliasFunction;
    use AA_Item;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    final class ItemUserFunctionTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        }

        /** @return array<string, array{string, ?string}> */
        public static function names(): array
        {
            return [
                'user function'             => ['usr_test_fu_echo', 'usr_test_fu_echo'],
                'case of PHP names'         => ['usr_TEST_fu_echo', 'usr_test_fu_echo'],
                'internal function'         => ['phpinfo', null],
                'internal, usr_ lookalike'  => ['usr_phpinfo', null],
                'not defined'               => ['usr_no_such_function', null],
                'prefix only'               => ['usr_', null],
                'prefix in upper case'      => ['USR_test_fu_echo', null],
                'static method'             => ['AA_Item::getItem', null],
                'namespaced'                => ['\\usr_test_fu_echo', null],
                'alias'                     => ['_#FNCNAME_', null],
                'trailing newline'          => ["usr_test_fu_echo\n", null],
                'too long'                  => ['usr_' . str_repeat('a', 61), null],
                'empty'                     => ['', null],
            ];
        }

        #[DataProvider('names')]
        public function test_only_defined_usr_user_functions_resolve(string $name, ?string $expected): void
        {
            $this->assertSame($expected, UserAliasFunction::resolve($name));
        }

        /** no internal function is named usr_*, so the isInternal() branch is checked on its own */
        public function test_internal_function_is_not_a_user_function(): void
        {
            $this->assertNull(UserAliasFunction::userFunction('phpinfo'));
            $this->assertNull(UserAliasFunction::userFunction('strlen'));
            $this->assertNull(UserAliasFunction::userFunction('usr_no_such_function'));
            $this->assertSame('usr_test_fu_echo', UserAliasFunction::userFunction('usr_test_fu_echo'));
        }

        private function item(): AA_Item
        {
            return new AA_Item(
                [
                    'headline........' => [['value' => 'Hello', 'flag' => 0]],
                    'fnc.............' => [['value' => 'usr_test_fu_echo', 'flag' => 0]],
                ],
                [
                    '_#HEADLINE' => GetAliasDef('f_h', 'headline........'),
                    '_#FNCNAME_' => GetAliasDef('f_h', 'fnc.............'),
                ],
            );
        }

        /** f_u() of the item, its debug output captured */
        private function fu(string $param, ?string &$output = null): string
        {
            ob_start();
            try {
                return $this->item()->f_u('headline........', $param);
            } finally {
                $output = (string) ob_get_clean();
            }
        }

        public function test_user_function_gets_the_substituted_parameter(): void
        {
            $this->assertSame('headline........|usr_test_fu_echo:Hello:x|2', $this->fu('usr_test_fu_echo:_#HEADLINE:x'));
        }

        public function test_user_function_without_parameters(): void
        {
            $this->assertSame('headline........|usr_test_fu_echo|2', $this->fu('usr_test_fu_echo'));
        }

        /** @return array<string, array{string}> */
        public static function rejected(): array
        {
            return [
                'internal function'          => ['phpinfo'],
                'internal with parameters'   => ['system:id'],
                'usr_ not defined'           => ['usr_no_such_function:x'],
                'alias as the name'          => ['_#FNCNAME_:x'],
                'field id as the parameter'  => ['fnc.............'],     // subst_alias() gives the field value
                'expression as the name'     => ['{fnc.............}:x'],
                'static method'              => ['AA_Item::getItem'],
            ];
        }

        #[DataProvider('rejected')]
        public function test_anything_else_gives_nothing(string $param): void
        {
            $this->assertSame('', $this->fu($param));
        }

        public function test_rejected_name_is_not_echoed_raw(): void
        {
            $this->assertSame('', $this->fu('<img src=x onerror=alert(1)>:x', $output));
            $this->assertStringNotContainsString('<img', $output);
            $this->assertStringContainsString('&lt;img', $output);
        }
    }
}
