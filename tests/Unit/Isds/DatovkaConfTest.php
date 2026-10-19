<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\DatovkaConf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Files that define DATOVKA_CONF are read in a process of their own: PHP defines the constant only once.
 * A file that fails before defining it is read here.
 */
final class DatovkaConfTest extends TestCase
{
    /** a value no problem and no stray may show */
    private const SECRET = 'Secret-never-shown';

    private const UNREACHABLE = 'misc/datovka/datovka_conf.php links to a file PHP cannot reach (gone, outside open_basedir, or in a directory PHP may not enter) - AA runs as if the file were not there';

    private const LINK_DIR = 'AA_TEST_DATOVKA_CONF_LINK_DIR';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/aa-datovka-conf-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file) && !is_link($file)) {
                chmod($file, 0o600);
            }
            if (is_link($file) || is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->dir);
    }

    /**
     * Where test_fromFile_linkOutsideOpenBasedirIsNoMissingFile links. Under open_basedir unlink() checks the
     * target, so the test cannot remove its link: this process does, after the class. A test of its own
     * process inherits the environment.
     */
    public static function setUpBeforeClass(): void
    {
        if (getenv(self::LINK_DIR) === false) {
            putenv(self::LINK_DIR . '=' . sys_get_temp_dir() . '/aa-datovka-conf-link-' . bin2hex(random_bytes(6)));
        }
    }

    public static function tearDownAfterClass(): void
    {
        $dir = getenv(self::LINK_DIR);
        if (is_string($dir) && @is_dir($dir)) {
            @unlink("$dir/datovka_conf.php");
            @rmdir($dir);
        }
        putenv(self::LINK_DIR);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3) . '/';
    }

    private function file(string $php, string $name = 'datovka_conf.php'): string
    {
        file_put_contents("$this->dir/$name", $php);

        return "$this->dir/$name";
    }

    /** @param list<string> $texts */
    private function assertShowsNoValue(array $texts): void
    {
        foreach ($texts as $text) {
            $this->assertStringNotContainsString(self::SECRET, $text);
        }
    }

    private function assertDefaults(DatovkaConf $conf): void
    {
        $this->assertSame([], $conf->instances());
        foreach (['test', 'standard'] as $environment) {
            $this->assertSame('', $conf->ats($environment)['cert']);
            $this->assertSame('', $conf->ats($environment)['atsId']);
        }
    }

    public function test_ats_addressesOfIsds(): void
    {
        $test       = DatovkaConf::fromArray([])->ats('test');
        $production = DatovkaConf::fromArray([])->ats('standard');

        $this->assertSame('https://www.datovka-test.gov.cz/as/login', $test['url_login']);
        $this->assertSame('https://cert.datovka-test.gov.cz/asws/extIs2Endpoint', $test['url_GetCredential']);
        $this->assertSame('https://cert.datovka-test.gov.cz/asws/konceptEndpoint', $test['url_SetConcept']);
        $this->assertSame('https://www.datovka-test.gov.cz/as/koncept/view', $test['url_ViewConcept']);
        $this->assertSame('https://www.datovka.gov.cz/as/login', $production['url_login']);
        $this->assertSame('https://cert.datovka.gov.cz/asws/extIs2Endpoint', $production['url_GetCredential']);
        $this->assertSame('https://cert.datovka.gov.cz/asws/konceptEndpoint', $production['url_SetConcept']);
        $this->assertSame('https://www.datovka.gov.cz/as/koncept/view', $production['url_ViewConcept']);
        foreach ([$test, $production] as $ats) {
            $this->assertSame(self::root() . 'misc/datovka/wsdls/GetCredential.wsdl', $ats['wsdl_GetCredential']);
            $this->assertSame(self::root() . 'misc/datovka/wsdls/SetConcept.wsdl', $ats['wsdl_SetConcept']);
            $this->assertFileExists($ats['wsdl_GetCredential']);
            $this->assertFileExists($ats['wsdl_SetConcept']);
        }
    }

    public function test_ats_unknownEnvironmentRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DatovkaConf::fromArray([])->ats('production');
    }

    public function test_fromArray_relativeCertStartsInCertDir(): void
    {
        $conf = DatovkaConf::fromArray([
            'test'     => ['cert' => 'ats-test.pem'],
            'standard' => ['cert' => '/etc/aa-isds/ats.pem'],
        ]);

        $this->assertSame(self::root() . 'config/certs/ats-test.pem', $conf->ats('test')['cert']);
        $this->assertSame('/etc/aa-isds/ats.pem', $conf->ats('standard')['cert']);
    }

    public function test_fromArray_namesStrays(): void
    {
        $conf = DatovkaConf::fromArray([
            'instance' => [1000 => ['url' => 'https://example.org/apc-aa/']],
            'test'     => ['atsid' => self::SECRET, 'url_login' => 'https://example.org/', 'wsdl_SetConcept' => '/tmp/x.wsdl'],
            'accounts' => ['NSZM' => ['password' => self::SECRET]],
            7          => self::SECRET,
        ]);

        $this->assertSame(["DATOVKA_CONF['test']['atsid']", "DATOVKA_CONF['instance']", 'DATOVKA_CONF[7]'], $conf->strays());
        $this->assertSame([], $conf->problems());
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function wrongParts(): iterable
    {
        yield 'instances not an array' => [['instances' => self::SECRET], "DATOVKA_CONF['instances'] "];
        yield 'instance id 0' => [['instances' => [0 => ['url' => self::SECRET]]], "DATOVKA_CONF['instances'][0] "];
        yield 'instance id over 9999' => [['instances' => [10000 => ['url' => self::SECRET]]], "DATOVKA_CONF['instances'][10000] "];
        yield 'instance id a word' => [['instances' => ['main' => ['url' => self::SECRET]]], "DATOVKA_CONF['instances']['main'] "];
        yield 'instance without url' => [['instances' => [1000 => ['URL' => self::SECRET]]], "DATOVKA_CONF['instances'][1000] "];
        yield 'instance url not a string' => [['instances' => [1000 => ['url' => [self::SECRET]]]], "DATOVKA_CONF['instances'][1000] "];
        yield 'instance a bare url' => [['instances' => [1000 => self::SECRET]], "DATOVKA_CONF['instances'][1000] "];
        yield 'instance url empty' => [['instances' => [1000 => ['url' => '']]], "DATOVKA_CONF['instances'][1000] "];
        yield 'instance url over http' => [['instances' => [1000 => ['url' => 'http://' . self::SECRET . '.org/']]], "DATOVKA_CONF['instances'][1000] "];
        yield 'instance url without the closing slash' => [['instances' => [1000 => ['url' => 'https://' . self::SECRET . '.org/apc-aa']]], "DATOVKA_CONF['instances'][1000] "];
        yield 'environment not an array' => [['test' => self::SECRET], "DATOVKA_CONF['test'] "];
        yield 'cert not a string' => [['test' => ['cert' => [self::SECRET]]], "DATOVKA_CONF['test']['cert'] "];
        yield 'atsId not a string' => [['standard' => ['atsId' => 1234]], "DATOVKA_CONF['standard']['atsId'] "];
    }

    /** @param array<mixed> $settings */
    #[DataProvider('wrongParts')]
    public function test_fromArray_wrongPartLeftOut(array $settings, string $where): void
    {
        $conf = DatovkaConf::fromArray($settings);

        $this->assertCount(1, $conf->problems());
        $this->assertStringStartsWith($where, $conf->problems()[0]);
        $this->assertShowsNoValue($conf->problems());
        $this->assertDefaults($conf);
    }

    public function test_fromArray_rightPartsOfWrongOnesKept(): void
    {
        $conf = DatovkaConf::fromArray([
            'instances' => [1000 => ['url' => 'https://example.org/apc-aa/'], 0 => ['url' => 'https://example.net/']],
            'test'      => ['cert' => 7, 'atsId' => 'a1b2c3d4e5f60718'],
        ]);

        $this->assertSame([1000 => 'https://example.org/apc-aa/'], $conf->instances());
        $this->assertSame('a1b2c3d4e5f60718', $conf->ats('test')['atsId']);
        $this->assertSame('', $conf->ats('test')['cert']);
        $this->assertCount(2, $conf->problems());
    }

    public function test_fromFile_missingGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile("$this->dir/datovka_conf.php");

        $this->assertDefaults($conf);
        $this->assertSame([], $conf->problems());
        $this->assertSame([], $conf->strays());
    }

    /** PHP quotes the code around a syntax error - the value after the missing comma here */
    public function test_fromFile_invalidPhpGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile($this->file("<?php\nconst DATOVKA_CONF = [\n    'test' => ['atsId' => ''] '" . self::SECRET . "',\n];\n"));

        $this->assertDefaults($conf);
        $this->assertSame(['misc/datovka/datovka_conf.php is not valid PHP (line 3) - AA runs as if the file were not there'], $conf->problems());
    }

    public function test_fromFile_errorGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile($this->file("<?php\nconst DATOVKA_CONF = [\n    'test' => ['cert' => AA_NO_SUCH_CONSTANT . '" . self::SECRET . "'],\n];\n"));

        $this->assertDefaults($conf);
        $this->assertSame(['misc/datovka/datovka_conf.php stops with Error - AA runs as if the file were not there'], $conf->problems());
    }

    public function test_fromFile_unreadableGivesDefaults(): void
    {
        $file = $this->file("<?php\nconst DATOVKA_CONF = [];\n");
        chmod($file, 0o000);
        clearstatcache();
        if (is_readable($file)) {
            $this->markTestSkipped('root reads any file');
        }

        $conf = DatovkaConf::fromFile($file);

        $this->assertDefaults($conf);
        $this->assertSame(['PHP cannot read misc/datovka/datovka_conf.php - give it the owner, group and mode of include/config.php, and check that open_basedir lets PHP there'], $conf->problems());
    }

    /** another tree over the same database links the file of the first one, which may be gone */
    public function test_fromFile_brokenLinkIsNoMissingFile(): void
    {
        symlink("$this->dir/gone.php", "$this->dir/datovka_conf.php");

        $conf = DatovkaConf::fromFile("$this->dir/datovka_conf.php");

        $this->assertDefaults($conf);
        $this->assertSame([self::UNREACHABLE], $conf->problems());
    }

    /** /aa-dev/ links the file of /aaa/: open_basedir may hide the target, and then even the link */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fromFile_linkOutsideOpenBasedirIsNoMissingFile(): void
    {
        $dir = (string) getenv(self::LINK_DIR);
        mkdir($dir);
        symlink(PHP_BINARY, "$dir/datovka_conf.php");
        $this->assertTrue(class_exists(DatovkaConf::class));
        // the temporary directory holds the result PHPUnit hands back from this process
        $allowed = [self::root(), sys_get_temp_dir() . '/'];
        foreach ($allowed as $path) {
            if (str_starts_with(PHP_BINARY, $path)) {
                $this->markTestSkipped('PHP itself is in an allowed directory');
            }
        }
        ini_set('open_basedir', implode(PATH_SEPARATOR, $allowed));
        // a warning would show the path and the allowed directories; PHPUnit only records it
        error_reporting(E_ALL);
        set_error_handler(static fn(int $no, string $text): bool => (error_reporting() & $no) ? throw new \ErrorException($text) : true);
        $this->expectOutputString('');

        try {
            $conf = DatovkaConf::fromFile("$dir/datovka_conf.php");
        } finally {
            restore_error_handler();
        }

        $this->assertDefaults($conf);
        $this->assertSame([self::UNREACHABLE], $conf->problems());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fromFile_takesOwnSettingsOnly(): void
    {
        // the form of the files installations made from the datovka_conf.php AA used to ship
        define('AA_BASE_PATH', '/var/www/example.org/apc-aa/');
        $file = $this->file(<<<'PHP'
            <?php
            const DATOVKA_CONF = [
                'instances' => [
                    1000 => ['url' => 'https://www.example.org/apc-aa/'],   // id of the installation
                    1001 => ['url' => 'https://other.example.net/apc-aa/'],
                ],
                'test' => [
                    'cert'               => AA_BASE_PATH . '/config/certs/ats-test.crt',
                    'atsId'              => 'a1b2c3d4e5f60718',
                    'url_login'          => 'https://www.czebox.cz/as/login',
                    'url_GetCredential'  => 'https://cert.czebox.cz/asws/extIs2Endpoint',
                    'url_SetConcept'     => 'https://cert.czebox.cz/asws/konceptEndpoint',
                    'url_ViewConcept'    => 'https://www.czebox.cz/as/koncept/view',
                    'wsdl_GetCredential' => __DIR__ . '/wsdls/GetCredential.wsdl',
                    'wsdl_SetConcept'    => __DIR__ . '/wsdls/SetConcept.wsdl',
                ],
                'standard' => [
                    'cert'  => 'ats.crt',
                    'atsId' => '',
                ],
            ];
            PHP);

        $conf = DatovkaConf::fromFile($file);

        $this->assertSame([1000 => 'https://www.example.org/apc-aa/', 1001 => 'https://other.example.net/apc-aa/'], $conf->instances());
        $this->assertSame('/var/www/example.org/apc-aa//config/certs/ats-test.crt', $conf->ats('test')['cert']);
        $this->assertSame('a1b2c3d4e5f60718', $conf->ats('test')['atsId']);
        $this->assertSame(DatovkaConf::ATS['test']['url_login'], $conf->ats('test')['url_login']);
        $this->assertSame(self::root() . 'misc/datovka/wsdls/SetConcept.wsdl', $conf->ats('test')['wsdl_SetConcept']);
        $this->assertSame(self::root() . 'config/certs/ats.crt', $conf->ats('standard')['cert']);
        $this->assertSame([], $conf->problems());
        $this->assertSame([], $conf->strays());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fromFile_printsNothing(): void
    {
        $this->expectOutputString('');

        $conf = DatovkaConf::fromFile($this->file("\u{FEFF} \n<?php\nconst DATOVKA_CONF = ['test' => ['atsId' => 'a1b2c3d4e5f60718']];\n?>\n\n"));

        $this->assertSame('a1b2c3d4e5f60718', $conf->ats('test')['atsId']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fromFile_noConstantGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile($this->file("<?php\n\$datovka_conf = ['test' => ['atsId' => '" . self::SECRET . "']];\n"));

        $this->assertDefaults($conf);
        $this->assertSame(['misc/datovka/datovka_conf.php does not define DATOVKA_CONF - AA runs as if the file were not there'], $conf->problems());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fromFile_notArrayGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile($this->file("<?php\nconst DATOVKA_CONF = '" . self::SECRET . "';\n"));

        $this->assertDefaults($conf);
        $this->assertSame(['DATOVKA_CONF in misc/datovka/datovka_conf.php is not an array - AA runs as if the file were not there'], $conf->problems());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_load_readsOnce(): void
    {
        $this->assertSame(DatovkaConf::load(), DatovkaConf::load());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_default_validTemplateWithoutSecrets(): void
    {
        $file = self::root() . DatovkaConf::FILE . '.default';
        $this->expectOutputString('');

        $conf = DatovkaConf::fromFile($file);

        $this->assertSame([], $conf->problems());
        $this->assertSame([], $conf->strays());
        $this->assertDefaults($conf);
        $template = constant('DATOVKA_CONF');
        $this->assertSame(['instances', 'test', 'standard'], array_keys($template));
        $this->assertSame(['cert', 'atsId'], array_keys($template['test']));
        $this->assertSame(['cert', 'atsId'], array_keys($template['standard']));
        $text = (string) file_get_contents($file);
        $this->assertStringNotContainsString('AA_BASE_PATH', $text, 'a directly requested file must print nothing, not an error');
        $this->assertDoesNotMatchRegularExpression('/\b[0-9a-f]{16}\b/', $text, 'no atsId, not even in a comment');
        $this->assertDoesNotMatchRegularExpression('/[\'"](password|passphrase|login)[\'"]\s*=>/i', $text);
    }

    /** a copy of the file must reach neither git nor dist - nor the public SVN mirror built from it */
    public function test_FILE_leftOutOfGitAndDist(): void
    {
        $lines = static fn(string $file): array => file(self::root() . $file, FILE_IGNORE_NEW_LINES) ?: [];

        $this->assertContains('/' . DatovkaConf::FILE, $lines('.gitignore'));
        $this->assertContains('/' . DatovkaConf::FILE, $lines('dev/release/dist-gitignore'));
        $this->assertContains('- /' . DatovkaConf::FILE, $lines('dev/release/dist-exclude.txt'));
    }
}
