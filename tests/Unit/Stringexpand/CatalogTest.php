<?php

declare(strict_types=1);

/**
 * The catalog of {command:...} expressions the code editor offers: names, parameters, one sentence
 * of documentation - read from the AA_Stringexpand_* classes themselves, so it cannot drift.
 */

namespace {
    // the classes need include/util.php, which reaches include/locsess.php - no database
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

namespace Tests\Unit\Stringexpand {

    use AA\Stringexpand\Catalog;
    use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;

    /** in its own process: the real stringexpand.php must not meet the stand-ins other tests declare */
    #[RunClassInSeparateProcess]
    final class CatalogTest extends TestCase
    {
        /** @var array<string, array<string, mixed>> */
        private static array $commands;

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../include/util.php';
            self::$commands = Catalog::commands();
        }

        public function test_commands_ifset_hasItsThreeParameters(): void
        {
            self::assertSame(['condition', 'text', 'else_text'], self::$commands['ifset']['params']);
            self::assertArrayNotHasKey('repeat', self::$commands['ifset']);
            self::assertStringStartsWith('If $condition is filled', self::$commands['ifset']['desc']);
        }

        public function test_commands_ifeq_repeatsOptionAndTextBeforeTheElse(): void
        {
            $ifeq = self::$commands['ifeq'];
            self::assertSame(['etalon'], $ifeq['params']);
            self::assertSame(['option', 'text'], $ifeq['repeat']);
            self::assertSame(['else_text'], $ifeq['tail']);
        }

        public function test_commands_withoutParams_takeTheNamesOfExpand(): void
        {
            self::assertSame(['vid', 'ids', 'settings'], self::$commands['view']['params']);
            self::assertSame(['format', 'timestamp'], self::$commands['now']['params']);
            self::assertSame([], self::$commands['serverload']['params']);
        }

        public function test_commands_listEveryCommandClassOfTheThreeSources(): void
        {
            $expected = [];
            foreach (Catalog::SOURCES as $file) {
                preg_match_all('/^class\s+AA_Stringexpand_(\w+)/m', (string) file_get_contents(AA_BASE_PATH . $file), $m);
                $expected = array_merge($expected, array_map('strtolower', $m[1]));
            }
            $expected = array_diff($expected, ['nevercache', 'nevercachenotrim']);
            self::assertSame([], array_values(array_diff($expected, array_keys(self::$commands))));
            self::assertSame([], array_values(array_diff(array_keys(self::$commands), $expected)), 'nothing from elsewhere');
            self::assertArrayHasKey('poll', self::$commands);
            self::assertArrayHasKey('xseo', self::$commands);
        }

        public function test_params_everyVariadicCommandNamesItsParameters(): void
        {
            foreach (array_keys(self::$commands) as $name) {
                $class = new ReflectionClass('AA_Stringexpand_' . $name);
                $last  = array_slice($class->getMethod('expand')->getParameters(), -1)[0] ?? null;
                if ($last?->isVariadic()) {
                    self::assertTrue($class->hasConstant('PARAMS'), "{{$name}} takes any number of parameters - name them in const PARAMS");
                }
                if ($class->hasConstant('PARAMS')) {
                    $groups = array_filter($class->getConstant('PARAMS'), 'is_array');
                    self::assertLessThanOrEqual(1, count($groups), "{{$name}}: one repeated group at most");
                    foreach (array_merge(...array_map(static fn($p) => (array) $p, $class->getConstant('PARAMS'))) as $param) {
                        self::assertMatchesRegularExpression('/^[a-z_0-9]+$/', $param, "{{$name}}");
                    }
                }
            }
        }

        /** the docblocks are free-form - a fragment of an example would mislead more than nothing */
        public function test_desc_skipsWhatDoesNotDescribe(): void
        {
            self::assertSame('returns last "directory" name in AA_Router_Seo', self::$commands['xseo']['desc'], 'the text after "{xseo} - "');
            self::assertStringStartsWith('in a planned task, queues a run', self::$commands['import']['desc']);
            self::assertSame('', self::$commands['aabox']['desc'], '"Class AA_Stringexpand_Aabox" says nothing');
            self::assertSame('', self::$commands['require']['desc'], 'the note of the third example is no description');
            self::assertSame('', self::$commands['foreach']['desc'], '"you can use named variables if you use json:" introduces examples');
            self::assertSame('Translation', self::$commands['tr']['desc'], '"usage: {tr:Send}" on the next line');
        }

        public function test_desc_isOneShortLine(): void
        {
            foreach (self::$commands as $name => $command) {
                self::assertLessThanOrEqual(160, mb_strlen($command['desc']), "{{$name}}");
                self::assertStringNotContainsString("\n", $command['desc'], "{{$name}}");
            }
        }
    }
}
