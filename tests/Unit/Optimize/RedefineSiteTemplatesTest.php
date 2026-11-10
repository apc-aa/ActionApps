<?php

declare(strict_types=1);

/**
 * RedefineSiteTemplates recreates the site template when it is missing. When old installs still have it
 * of the legacy kind with "template.php3" - a control file AA never had, which the form of every new site
 * offers - it only gives the template the router home.
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

namespace Tests\Unit\Optimize {

    use AA\Optimize\RedefineSiteTemplates;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Legacy\Fixtures\RecordingDb;

    final class RedefineSiteTemplatesTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        }

        protected function setUp(): void
        {
            RecordingDb::reset();
            $GLOBALS['spareDBs'] = [new RecordingDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            RecordingDb::reset();
        }

        /** answers the module and the site row of the template as the DB holds them
         *  @param ?array<string, mixed> $module @param ?array<string, mixed> $site */
        private static function template(?array $module, ?array $site): void
        {
            RecordingDb::$answer = static fn(string $sql): array => match (true) {
                str_contains($sql, 'FROM `module`') => ($module === null) ? [] : [$module],
                str_contains($sql, 'FROM `site`')   => ($site === null) ? [] : [$site],
                default                             => [],
            };
        }

        /** @return list<string> the statements that write */
        private static function writes(): array
        {
            return array_values(array_filter(RecordingDb::$log, static fn(string $sql): bool => !str_starts_with($sql, 'SELECT')));
        }

        /** @return array<string, array{?array<string, mixed>, ?array<string, mixed>, bool}> module row, site row, test() passes */
        public static function templates(): array
        {
            return [
                'stock: router home'           => [['deleted' => 0], ['flag' => 1, 'state_file' => '/en/home'], true],
                'recreated: home path'         => [['deleted' => 0], ['flag' => 0, 'state_file' => '/en/home'], true],
                'old install: template.php3'   => [['deleted' => 0], ['flag' => 0, 'state_file' => 'template.php3'], false],
                'control file that is there'   => [['deleted' => 0], ['flag' => 0, 'state_file' => 'ecn_as_example.php'], true],
                'router with a file-like home' => [['deleted' => 0], ['flag' => 1, 'state_file' => 'template.php3'], true],
                'switched off (deleted)'       => [['deleted' => 1], ['flag' => 0, 'state_file' => 'template.php3'], true],
                'no module row'                => [null, null, false],
            ];
        }

        /** @param ?array<string, mixed> $module @param ?array<string, mixed> $site */
        #[DataProvider('templates')]
        public function test_test_fails_for_a_missing_or_stale_template(?array $module, ?array $site, bool $passes): void
        {
            self::template($module, $site);

            $this->assertSame($passes, (new RedefineSiteTemplates())->test());
            $this->assertSame([], self::writes(), 'a test writes nothing');
        }

        /** a live template keeps its name, language, URL, structure and spots - only the control file goes */
        public function test_repair_of_a_stale_template_writes_only_its_home(): void
        {
            self::template(['deleted' => 0], ['flag' => 0, 'state_file' => 'template.php3']);

            $this->assertTrue((new RedefineSiteTemplates())->repair());

            $writes = self::writes();
            $this->assertCount(1, $writes, implode("\n", $writes));
            $this->assertStringStartsWith('UPDATE `site` SET ', $writes[0]);
            $this->assertStringContainsString("'/en/home'", $writes[0]);
            $this->assertMatchesRegularExpression('/`flag`\s*=\s*\'?1\b/', $writes[0], 'the router reads the home');
            $this->assertStringContainsString('0x' . bin2hex(\AA_Module_Site::TEMPLATE_ID), $writes[0]);
            $this->assertStringNotContainsString('structure', $writes[0]);
        }

        /** a missing template is written whole, as before */
        public function test_repair_of_a_missing_template_writes_it_whole(): void
        {
            self::template(null, null);

            $this->assertTrue((new RedefineSiteTemplates())->repair());

            $writes = self::writes();
            $this->assertCount(2, array_filter($writes, static fn(string $sql): bool => str_starts_with($sql, 'REPLACE INTO ')), implode("\n", $writes));
            $site = array_values(array_filter($writes, static fn(string $sql): bool => str_starts_with($sql, 'REPLACE INTO site ')));
            $this->assertMatchesRegularExpression("~VALUES \\('SiteTemplate\\.{4}', '/en/home', '.*', 1\\)$~s", $site[0] ?? '', 'the router reads the home');
        }
    }
}
