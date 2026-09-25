<?php

declare(strict_types=1);

/**
 * Alias function f_u ("call a user function", AA_Item::f_u()) calls only a function of the
 * installation, named in the parameter before alias substitution:
 *  - a usr_* name defined by PHP code (not internal) - called;
 *  - another function the installation defines (include/usr_aliasfnc.php, include/custom/, a file
 *    outside AA) - still called, so old sites keep working, and logged as deprecated;
 *  - anything else - an internal function, a function of AA itself, an undefined one, a name an alias
 *    or a field value would supply through the substitution - gives '', a debug message and a log row.
 * The log rows are deduplicated (AA\Util\LogOnce) and shown by State Check. The rest of the parameter
 * is still alias-substituted as before. (In {alias:<field>:f_u:{..}} the inner {..} are expanded before
 * f_u runs, so there item data can pick which function - still only one of the installation's.)
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

    /** a usr_* function that takes the right parameters but fails inside (this file is strict_types) */
    function usr_test_fu_fails_inside(array $content, string $col, string $param): string
    {
        return (string) strlen($content);
    }

    /** a usr_* function with a signature f_u cannot satisfy - the call fails with ArgumentCountError */
    function usr_test_fu_needs_four(array $content, string $col, string $param, string $fourth): string
    {
        return $fourth;
    }

    /** Functions in files AA does not ship: a temporary directory outside the AA base - a function a
     *  helper file of include/usr_aliasfnc.php defines (registered as the require of usr_aliasfnc.php
     *  registers it, UserAliasFunction::addInstallationFunctions()) and one a host application defines
     *  (not registered) - and a fake AA base with include/custom/, modules/site/sites/, include/config.php
     *  and a core file; UserAliasFunction::classify() takes the base. */
    function aa_test_fu_fixture_dir(): string
    {
        static $dir = null;
        if ($dir === null) {
            $dir = sys_get_temp_dir() . '/aa_fu_' . getmypid() . '_' . bin2hex(random_bytes(4));
            $files = [
                'helper.php'                          => 'aa_test_legacy_fu',
                'hostapp.php'                         => 'aa_test_hostapp_fu',
                'base/include/custom/site/fnc.php'    => 'aa_test_custom_fu',
                'base/modules/site/sites/site_x.php'  => 'aa_test_sitefile_fu',
                'base/include/config.php'             => 'aa_test_config_fu',
                'base/src/Core.php'                   => 'aa_test_core_fu',
            ];
            foreach ($files as $file => $function) {
                @mkdir(dirname("$dir/$file"), 0700, true);
                file_put_contents("$dir/$file", "<?php\nfunction $function(array \$content, string \$col, string \$param): string\n{\n    return '$function|' . \$param;\n}\n");
                $before = get_defined_functions()['user'];
                require_once "$dir/$file";
                if ($file === 'helper.php') {
                    \AA\Util\UserAliasFunction::addInstallationFunctions(array_diff(get_defined_functions()['user'], $before));
                }
            }
            // an installation file that pulls in an AA core file: the require diff tags its functions too
            file_put_contents("$dir/base/src/Helpers.php", "<?php\nfunction aa_test_tagged_core_fu(array \$content, string \$col, string \$param): string\n{\n    return '';\n}\n");
            file_put_contents("$dir/usr_aliasfnc_fixture.php", "<?php\nrequire_once __DIR__ . '/base/src/Helpers.php';\n");
            $before = get_defined_functions()['user'];
            require_once "$dir/usr_aliasfnc_fixture.php";
            \AA\Util\UserAliasFunction::addInstallationFunctions(array_diff(get_defined_functions()['user'], $before));
            file_put_contents("$dir/badsig.php", "<?php\nfunction aa_test_badsig_fu(int \$x): string\n{\n    return '';\n}\n");
            require_once "$dir/badsig.php";
            \AA\Util\UserAliasFunction::addInstallationFunctions(['aa_test_badsig_fu']);
        }
        return $dir;
    }
}

namespace Tests\Unit\Legacy {

    use AA\Util\LogOnce;
    use AA\Util\UserAliasFunction;
    use AA_Item;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class ItemUserFunctionTest extends TestCase
    {
        private const SLICE = '0123456789abcdef0123456789abcdef';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            \aa_test_fu_fixture_dir();
        }

        public static function tearDownAfterClass(): void
        {
            $dir = \aa_test_fu_fixture_dir();
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            LogOnce::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
            LogOnce::reset();
        }

        /** @return string[] the INSERTs into the log */
        private static function logInserts(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql) => str_starts_with($sql, 'INSERT INTO `log`')));
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
                    'u_slice_id......' => [['value' => self::SLICE, 'flag' => 0]],
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
            $this->assertSame('headline........|usr_test_fu_echo:Hello:x|3', $this->fu('usr_test_fu_echo:_#HEADLINE:x'));
        }

        public function test_user_function_without_parameters(): void
        {
            $this->assertSame('headline........|usr_test_fu_echo|3', $this->fu('usr_test_fu_echo'));
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

        /** @return array<string, array{string, string, ?string, string}> name => [level, function to call, reason] */
        public static function levels(): array
        {
            return [
                'usr_ function'                => ['usr_test_fu_echo', UserAliasFunction::USER, 'usr_test_fu_echo', ''],
                'defined by usr_aliasfnc.php'  => ['aa_test_legacy_fu', UserAliasFunction::INSTALLATION, 'aa_test_legacy_fu', ''],
                'case of PHP names'            => ['AA_TEST_legacy_fu', UserAliasFunction::INSTALLATION, 'aa_test_legacy_fu', ''],
                'host application, outside AA' => ['aa_test_hostapp_fu', UserAliasFunction::REJECTED, null, UserAliasFunction::FOREIGN],
                'AA core: huhl'                => ['huhl', UserAliasFunction::REJECTED, null, UserAliasFunction::CORE],
                'AA core: ParamExplode'        => ['ParamExplode', UserAliasFunction::REJECTED, null, UserAliasFunction::CORE],
                'internal'                     => ['phpinfo', UserAliasFunction::REJECTED, null, UserAliasFunction::INTERNAL],
                'internal, usr_ lookalike'     => ['usr_phpinfo', UserAliasFunction::REJECTED, null, UserAliasFunction::UNDEFINED],
                'undefined'                    => ['nszm_no_such_function', UserAliasFunction::REJECTED, null, UserAliasFunction::UNDEFINED],
                'empty'                        => ['', UserAliasFunction::REJECTED, null, UserAliasFunction::EMPTY],
                'alias'                        => ['_#FNCNAME_', UserAliasFunction::REJECTED, null, UserAliasFunction::BAD_NAME],
                'field id'                     => ['fnc.............', UserAliasFunction::REJECTED, null, UserAliasFunction::BAD_NAME],
                'expression'                   => ['{fnc.............}', UserAliasFunction::REJECTED, null, UserAliasFunction::BAD_NAME],
                'static method'                => ['AA_Item::getItem', UserAliasFunction::REJECTED, null, UserAliasFunction::BAD_NAME],
                'namespaced'                   => ['\\aa_test_legacy_fu', UserAliasFunction::REJECTED, null, UserAliasFunction::BAD_NAME],
                'trailing newline'             => ["aa_test_legacy_fu\n", UserAliasFunction::REJECTED, null, UserAliasFunction::BAD_NAME],
                'leading digit'                => ['1abc', UserAliasFunction::REJECTED, null, UserAliasFunction::BAD_NAME],
            ];
        }

        #[DataProvider('levels')]
        public function test_classify_levels(string $name, string $level, ?string $function, string $reason): void
        {
            $verdict = UserAliasFunction::classify($name);
            $this->assertSame($level, $verdict['level']);
            $this->assertSame($function, $verdict['function']);
            $this->assertSame($reason, $verdict['reason']);
        }

        public function test_host_application_function_shows_its_file(): void
        {
            $this->assertSame(\aa_test_fu_fixture_dir() . '/hostapp.php', UserAliasFunction::classify('aa_test_hostapp_fu')['file'], 'outside AA - the full path');
        }

        /** where the installation keeps its functions - judged against the AA base the caller gives */
        public function test_installation_files_against_the_aa_base(): void
        {
            $base = \aa_test_fu_fixture_dir() . '/base/';

            $this->assertSame(UserAliasFunction::INSTALLATION, UserAliasFunction::classify('aa_test_custom_fu', $base)['level'], 'include/custom/');
            $this->assertSame(UserAliasFunction::INSTALLATION, UserAliasFunction::classify('aa_test_sitefile_fu', $base)['level'], 'modules/site/sites/');
            $config = UserAliasFunction::classify('aa_test_config_fu', $base);
            $this->assertSame([UserAliasFunction::REJECTED, UserAliasFunction::CORE, 'include/config.php'], [$config['level'], $config['reason'], $config['file']], 'include/config.php holds no user functions - AA itself');
            $this->assertSame(UserAliasFunction::INSTALLATION, UserAliasFunction::classify('aa_test_legacy_fu', $base)['level'], 'registered by the usr_aliasfnc.php require - wherever its file is');
            $core = UserAliasFunction::classify('aa_test_core_fu', $base);
            $this->assertSame([UserAliasFunction::REJECTED, null, UserAliasFunction::CORE], [$core['level'], $core['function'], $core['reason']], 'any other file of the AA base is AA itself');
            $this->assertSame('src/Core.php', $core['file'], 'the file relative to the base');
            $this->assertSame(UserAliasFunction::FOREIGN, UserAliasFunction::classify('aa_test_hostapp_fu', $base)['reason']);
        }

        /** usr_aliasfnc.php may require an AA file (or autoload a class whose file defines functions) - the
         *  require diff tags those too, but a function declared in an AA core file stays AA core */
        public function test_a_tagged_function_of_an_aa_core_file_stays_core(): void
        {
            $verdict = UserAliasFunction::classify('aa_test_tagged_core_fu', \aa_test_fu_fixture_dir() . '/base/');

            $this->assertSame([UserAliasFunction::REJECTED, null, UserAliasFunction::CORE, 'src/Helpers.php'], [$verdict['level'], $verdict['function'], $verdict['reason'], $verdict['file']]);
        }

        public function test_the_file_rules(): void
        {
            $base = '/srv/aa/';
            $this->assertTrue(UserAliasFunction::isInstallationFile('/srv/aa/include/usr_aliasfnc.php', $base));
            $this->assertTrue(UserAliasFunction::isInstallationFile('/srv/aa/include/usr_aliasfnc.php3', $base));
            $this->assertTrue(UserAliasFunction::isInstallationFile('/srv/aa/include/custom/nszm/stringexpand.php', $base));
            $this->assertTrue(UserAliasFunction::isInstallationFile('/srv/aa/modules/site/sites/site_nszm.php', $base));
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/include/config.php', $base), 'no user functions in the configuration');
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/include/config_local.php', $base));
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/modules/site/site.php', $base));
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/modules/site/sites/index.php', $base), 'site control files are site_*.php');
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/sites/nszm/functions.php', $base), 'outside AA: a host application, auto_prepend_file, include_path - not the installation');
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/include/util.php', $base));
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/vendor/x/y.php', $base));
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/include/customer.php', $base), 'include/custom/ is a directory');
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/include/custom/../util.php', $base), 'no way out of include/custom/');
            $this->assertFalse(UserAliasFunction::isInstallationFile('/srv/aa/admin/usr_aliasfnc.php', $base), 'AA loads include/usr_aliasfnc.php only');
            $this->assertFalse(UserAliasFunction::isInstallationFile("/srv/aa/include/custom/x.php(3) : eval()'d code", $base), 'eval()-defined');
            $this->assertFalse(UserAliasFunction::isInstallationFile('', $base));
        }

        public function test_item_php_registers_what_usr_aliasfnc_defines(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . '/include/item.php');
            $this->assertMatchesRegularExpression('/get_defined_functions\(\)\[\'user\'\].*require_once __DIR__ \. "\/usr_aliasfnc\.php".*UserAliasFunction::addInstallationFunctions\(/s', $src, 'the require is bracketed by the before/after function lists');
        }

        public function test_installation_function_is_still_called_and_logged_as_deprecated(): void
        {
            $this->assertSame('aa_test_legacy_fu|aa_test_legacy_fu:Hello', $this->fu('aa_test_legacy_fu:_#HEADLINE'));

            $inserts = self::logInserts();
            $this->assertCount(1, $inserts);
            $this->assertStringContainsString("'" . UserAliasFunction::LOG_EVENT . "'", $inserts[0]);
            $this->assertStringContainsString("'" . UserAliasFunction::INSTALLATION . ':' . self::SLICE . ":headline........:aa_test_legacy_fu'", $inserts[0], 'selector: level, slice, field, function');
        }

        public function test_host_application_function_is_not_called(): void
        {
            $this->assertSame('', $this->fu('aa_test_hostapp_fu:x'));
            $this->assertStringContainsString("'" . UserAliasFunction::FOREIGN . ':' . \aa_test_fu_fixture_dir() . '/hostapp.php', self::logInserts()[0], 'params: reason and the file');
        }

        public function test_usr_function_is_not_logged(): void
        {
            $this->fu('usr_test_fu_echo:x');
            $this->assertSame([], RecordingDb::$log, 'the usual case costs no query');
        }

        public function test_rejected_function_is_logged_with_the_reason(): void
        {
            $this->assertSame('', $this->fu('huhl:x'));

            $inserts = self::logInserts();
            $this->assertCount(1, $inserts);
            $this->assertStringContainsString("'" . UserAliasFunction::REJECTED . ':' . self::SLICE . ":headline........:huhl'", $inserts[0]);
            $this->assertStringContainsString("'" . UserAliasFunction::CORE . ':include/util.php', $inserts[0], 'params: reason and the file');
        }

        /** @return array<string, array{string, string}> */
        public static function failingCalls(): array
        {
            return [
                'usr_, too few arguments'   => ['usr_test_fu_needs_four:x', UserAliasFunction::USER],
                'installation, wrong types' => ['aa_test_badsig_fu:x', UserAliasFunction::INSTALLATION],
            ];
        }

        /** a function f_u cannot call with (content, field, param) must not kill the page */
        #[DataProvider('failingCalls')]
        public function test_a_call_with_the_wrong_signature_gives_nothing_and_is_logged(string $param, string $level): void
        {
            $this->assertSame('', $this->fu($param));

            $name = explode(':', $param)[0];
            $this->assertStringContainsString("'" . UserAliasFunction::REJECTED . ':' . self::SLICE . ":headline........:$name'", implode("\n", self::logInserts()));
            $this->assertStringContainsString("'" . UserAliasFunction::CALL_FAILED . ':', implode("\n", self::logInserts()));
        }

        /** a TypeError from inside a working function is logged neutrally - what was thrown and where */
        public function test_a_failure_inside_the_function_logs_the_exception_and_place(): void
        {
            $this->assertSame('', $this->fu('usr_test_fu_fails_inside:x'));

            $insert = self::logInserts()[0];
            $this->assertMatchesRegularExpression("~'" . UserAliasFunction::CALL_FAILED . ':TypeError at tests/Unit/Legacy/ItemUserFunctionTest\\.php#:\\d+\'~', $insert);
        }

        public function test_a_page_of_items_logs_once(): void
        {
            for ($i = 0; $i < 20; ++$i) {
                $this->fu('aa_test_legacy_fu:x');
                $this->fu('phpinfo');
            }
            $this->assertCount(2, self::logInserts());
            $this->assertSame(2, RecordingDb::count('SELECT'), 'one look-up per key and request');
        }

        public function test_item_without_its_slice_logs_an_empty_slice(): void
        {
            $item = new AA_Item(['headline........' => [['value' => 'Hello', 'flag' => 0]]], []);
            ob_start();
            try {
                $this->assertSame('', $item->f_u('headline........', 'phpinfo'));
            } finally {
                ob_end_clean();
            }
            $this->assertStringContainsString("'" . UserAliasFunction::REJECTED . "::headline........:phpinfo'", self::logInserts()[0]);
        }

        /** the log page (admin/aa_log.php) shows the selector - only name characters reach it */
        public function test_logged_name_and_field_keep_only_name_characters(): void
        {
            ob_start();
            try {
                $this->item()->f_u('<b>"x"', "bad\nname<script>'&:x");
            } finally {
                ob_end_clean();
            }
            $insert = self::logInserts()[0];
            $this->assertStringContainsString(':?b??x?:' . UserAliasFunction::BAD_NAME_LOGGED . "'", $insert, 'field id keeps [A-Za-z0-9_.]; a bad name is not logged at all');
            $this->assertStringNotContainsString('<', $insert);
        }

        /** item data picks the name in {alias:<field>:f_u:{..}} - bad names must not make a log key each */
        public function test_bad_names_share_one_log_key(): void
        {
            $this->fu('{a}:x');
            $this->fu('_#FNCNAME_:x');
            $this->fu('x y z:x');

            $this->assertCount(1, self::logInserts());
        }

        public function test_other_reasons_log_the_sanitised_name(): void
        {
            $this->fu('nszm_no_such_function:x');
            $this->assertStringContainsString(':headline........:nszm_no_such_function', self::logInserts()[0]);
        }
    }
}
