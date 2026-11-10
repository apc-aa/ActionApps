<?php

declare(strict_types=1);

/**
 * RedefineSiteTemplates recreates the site template when it is missing, and when old installs still
 * have it of the legacy kind with "template.php3" - a control file AA never had, which the form of
 * every new site offers.
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

        /** @return array<string, array{int, ?array<string, mixed>, bool}> modules found, the site row, test() passes */
        public static function templates(): array
        {
            return [
                'stock: router home'          => [1, ['flag' => 1, 'state_file' => '/en/home'], true],
                'recreated: home path'        => [1, ['flag' => 0, 'state_file' => '/en/home'], true],
                'old install: template.php3'  => [1, ['flag' => 0, 'state_file' => 'template.php3'], false],
                'control file that is there'  => [1, ['flag' => 0, 'state_file' => 'ecn_as_example.php'], true],
                'router with a file-like home' => [1, ['flag' => 1, 'state_file' => 'template.php3'], true],
                'no module row'               => [0, null, false],
            ];
        }

        /** @param ?array<string, mixed> $site */
        #[DataProvider('templates')]
        public function test_test_fails_for_a_missing_or_stale_template(int $modules, ?array $site, bool $passes): void
        {
            RecordingDb::$answer = static fn(string $sql): array => str_contains($sql, 'count(*) as cnt')
                ? [['cnt' => $modules]]
                : (($site === null) ? [] : [$site]);

            $this->assertSame($passes, (new RedefineSiteTemplates())->test());
        }
    }
}
