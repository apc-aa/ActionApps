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

    /**
     * NormalizePhpSelf() (include/locsess.php), called by the admin bootstraps: admin links and form
     * actions are built from PHP_SELF, which also holds whatever path follows the script in the URL.
     */
    final class NormalizePhpSelfTest extends TestCase
    {
        private array $server_backup = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';
        }

        protected function setUp(): void
        {
            $this->server_backup = $_SERVER;
            unset($_SERVER['PHP_SELF'], $_SERVER['SCRIPT_NAME'], $_SERVER['PATH_INFO'], $_SERVER['DOCUMENT_URI'], $_SERVER['HTTPS']);
            $_SERVER['HTTP_HOST']   = 'example.org';
            $_SERVER['SERVER_PORT'] = '80';
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->server_backup;
        }

        public function test_normalizePhpSelf_pathAfterScript_isDropped(): void
        {
            $_SERVER['SCRIPT_NAME'] = '/aa/admin/itemedit.php';
            $_SERVER['PHP_SELF']    = '/aa/admin/itemedit.php/"><svg onload=alert(1)>';

            NormalizePhpSelf();

            self::assertSame('/aa/admin/itemedit.php', $_SERVER['PHP_SELF']);
            self::assertSame('http://example.org/aa/admin/', self_base());
        }

        public function test_normalizePhpSelf_ordinaryRequest_keepsIt(): void
        {
            $_SERVER['SCRIPT_NAME'] = '/aa/admin/itemedit.php';
            $_SERVER['PHP_SELF']    = '/aa/admin/itemedit.php';

            NormalizePhpSelf();

            self::assertSame('/aa/admin/itemedit.php', $_SERVER['PHP_SELF']);
        }

        public function test_normalizePhpSelf_documentUri_pathAfterScriptDropped_ssiPageKept(): void
        {
            $_SERVER['SCRIPT_NAME']  = '/aa/admin/itemedit.php';
            $_SERVER['PHP_SELF']     = '/aa/admin/itemedit.php';
            $_SERVER['DOCUMENT_URI'] = '/aa/admin/itemedit.php/"><svg onload=alert(1)>';
            NormalizePhpSelf();
            self::assertSame('/aa/admin/itemedit.php', $_SERVER['DOCUMENT_URI']);

            $_SERVER['DOCUMENT_URI'] = '/cz/form.shtml';   // itemedit.php included in a page by SSI
            NormalizePhpSelf();
            self::assertSame('/cz/form.shtml', $_SERVER['DOCUMENT_URI']);
        }

        /** the admin pages get it from their bootstraps */
        public function test_adminBootstraps_normalizePhpSelf(): void
        {
            foreach (['include/init_page.php', 'central/include/init_central.php'] as $bootstrap) {
                $src = (string) file_get_contents(__DIR__ . '/../../../' . $bootstrap);
                self::assertMatchesRegularExpression('/^NormalizePhpSelf\(\);$/m', $src, $bootstrap);
            }
        }

        /** a server whose SCRIPT_NAME names something else (a CGI wrapper) or nothing - left as it is */
        public function test_normalizePhpSelf_scriptNameNotItsStart_keepsIt(): void
        {
            $_SERVER['SCRIPT_NAME'] = '/cgi-bin/php';
            $_SERVER['PHP_SELF']    = '/aa/admin/index.php';
            NormalizePhpSelf();
            self::assertSame('/aa/admin/index.php', $_SERVER['PHP_SELF']);

            unset($_SERVER['SCRIPT_NAME']);
            NormalizePhpSelf();
            self::assertSame('/aa/admin/index.php', $_SERVER['PHP_SELF']);
        }
    }
}
