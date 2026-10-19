<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Credential\Secret;
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

    /** @param string $failure '', 'unreadable', 'broken' or 'accounts' - broken in the accounts only */
    private function assertFailure(string $failure, DatovkaConf $conf): void
    {
        $this->assertSame(
            [$failure === 'unreadable', in_array($failure, ['broken', 'accounts'], true), in_array($failure, ['unreadable', 'broken'], true)],
            [$conf->unreadable(), $conf->broken(), $conf->lost()],
        );
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
        $this->assertSame(["DATOVKA_CONF['test']['url_login']", "DATOVKA_CONF['test']['wsdl_SetConcept']"], $conf->ignored(), 'of an older file - ATS gives them');
        $this->assertSame([], $conf->problems());
    }

    /** an installation holds url only; one left out names its problem, not its keys */
    public function test_fromArray_strayKeysOfAnInstallation(): void
    {
        $conf = DatovkaConf::fromArray(['instances' => [
            1000 => ['url' => 'https://example.org/aa/', 'name' => self::SECRET, 'NSZM' => ['password' => self::SECRET]],
            1001 => ['url' => 'http://example.org/aa/', 'note' => self::SECRET],
        ]]);

        $this->assertSame(["DATOVKA_CONF['instances'][1000]['name']", "DATOVKA_CONF['instances'][1000]['NSZM']"], $conf->strays());
        $this->assertCount(1, $conf->problems());
        $this->assertShowsNoValue([...$conf->strays(), ...$conf->problems()]);
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
        $this->assertSame([], $conf->accounts());
        $this->assertFailure('', $conf);
    }

    // a run sending with an account fails on the settings, as on a file that is no valid PHP - no sender went away
    public function test_fromArray_accountsNotAnArrayIsBroken(): void
    {
        $conf = DatovkaConf::fromArray(['accounts' => self::SECRET, 'test' => ['atsId' => 'a1b2c3d4e5f60718']]);

        $this->assertSame(["DATOVKA_CONF['accounts'] is not an array - AA has no ISDS account"], $conf->problems());
        $this->assertSame([[], 'a1b2c3d4e5f60718'], [$conf->accounts(), $conf->ats('test')['atsId']], 'the rest is read');
        $this->assertFailure('accounts', $conf);
    }

    // the problem that keeps AA from the file comes first
    public function test_fromArray_accountsNotAnArrayFirst(): void
    {
        $conf = DatovkaConf::fromArray(['test' => ['cert' => 7], 'accounts' => self::SECRET]);

        $this->assertSame(["DATOVKA_CONF['accounts'] is not an array - AA has no ISDS account", "DATOVKA_CONF['test']['cert'] is not a string - AA leaves it out"], $conf->problems());
    }

    /**
     * Each problem is logged with the problem as the selector - keys only; the params tell the one that
     * kept AA from the file or its accounts, and the site, so the State Check of another site can tell it.
     */
    public function test_logProblems_logsEachProblemWithItsSite(): void
    {
        $conf = DatovkaConf::fromArray(['test' => ['cert' => [self::SECRET]], 'accounts' => self::SECRET, 'instances' => [0 => ['url' => self::SECRET]]]);
        $log  = [];

        $this->assertSame($conf, $conf->logProblems(static function (string $event, string $selector, array $params) use (&$log): bool {
            $log[] = [$event, $selector, $params];

            return true;
        }, 'https://example.org/aa/'));

        $this->assertSame([
            ['ISDS_FILE', "DATOVKA_CONF['accounts'] is not an array - AA has no ISDS account", ['accounts', 'https://example.org/aa/']],
            ['ISDS_FILE', "DATOVKA_CONF['test']['cert'] is not a string - AA leaves it out", ['', 'https://example.org/aa/']],
            ['ISDS_FILE', $conf->problems()[2], ['', 'https://example.org/aa/']],
        ], $log);
        $this->assertShowsNoValue([...array_column($log, 1), ...array_merge(...array_column($log, 2))]);
    }

    public function test_logProblems_unreadableTellsSo(): void
    {
        symlink("$this->dir/gone.php", "$this->dir/datovka_conf.php");
        $log = [];

        DatovkaConf::fromFile("$this->dir/datovka_conf.php")->logProblems(static function (string $event, string $selector, array $params) use (&$log): bool {
            $log[] = [$event, $selector, $params];

            return true;
        }, '');

        $this->assertSame([['ISDS_FILE', self::UNREACHABLE, ['unread', '']]], $log);
    }

    // a query each: a file of many problems logs the first ones
    public function test_logProblems_atMostTen(): void
    {
        $count = 0;
        $log   = static function () use (&$count): bool {
            return (bool) ++$count;
        };

        DatovkaConf::fromArray(['instances' => array_fill(10001, 12, ['url' => ''])])->logProblems($log, '');
        DatovkaConf::fromArray(['instances' => [1000 => ['url' => 'https://example.org/aa/']]])->logProblems($log, '');

        $this->assertSame(10, $count);
    }

    /**
     * The token of a message of this database: an installation missing in instances, by its id; any other
     * token under one selector. Nothing while AA cannot use the file - it lists no installation then.
     */
    public function test_logRefusedAnswer_knownTokenByItsId(): void
    {
        $log    = [];
        $logger = static function (string ...$row) use (&$log): bool {
            $log[] = $row;

            return true;
        };
        $asked = 0;
        $conf  = DatovkaConf::fromArray(['instances' => [1000 => ['url' => 'https://example.org/aa/']]]);

        $conf->logRefusedAnswer(1001, static fn(): bool => true, $logger);
        $conf->logRefusedAnswer(7, static fn(): bool => false, $logger);
        DatovkaConf::fromArray(['accounts' => 'x'])->logRefusedAnswer(1002, static fn(): bool => true, $logger);
        symlink("$this->dir/gone.php", "$this->dir/link.php");
        foreach ([DatovkaConf::fromFile($this->file("<?php\nconst DATOVKA_CONF = [\n")), DatovkaConf::fromFile("$this->dir/link.php")] as $lost) {
            $lost->logRefusedAnswer(1003, static function () use (&$asked): bool {
                return (bool) ++$asked;
            }, $logger);
        }

        $this->assertSame([['ISDS_INST', 'known:1001', '1001'], ['ISDS_INST', 'unknown', '7'], ['ISDS_INST', 'known:1002', '1002']], $log, 'the accounts aside, instances are read');
        $this->assertSame(0, $asked, 'no database query for a file AA cannot use');
    }

    /** @return iterable<string, array{string, list<list<string>>}> */
    public static function twice(): iterable
    {
        $file = static fn(string $body): string => "<?php\nconst DATOVKA_CONF = [\n$body\n];\n";

        yield 'an account' => [$file("'accounts' => [\n    'NSZM' => ['sites' => ['a']],\n    'ECN' => [],\n    'NSZM' => ['sites' => ['b']],\n],"), [['accounts', 'NSZM']]];
        yield 'in either quotes' => [$file("'accounts' => ['NSZM' => [], \"NSZM\" => []],"), [['accounts', 'NSZM']]];
        yield 'in the long syntax' => ["<?php\nconst DATOVKA_CONF = array(\n'accounts' => array('NSZM' => array(), 'NSZM' => array()),\n);\n", [['accounts', 'NSZM']]];
        yield 'defined' => ["<?php\ndefined('DATOVKA_CONF') || define('DATOVKA_CONF', ['accounts' => ['NSZM' => [], 'NSZM' => []]]);\n", [['accounts', 'NSZM']]];
        yield 'any name, as written' => [$file("'accounts' => ['nszm' => [], 'nszm' => []],"), [['accounts', 'nszm']]];
        yield 'commented out' => [$file("'accounts' => [\n    // 'NSZM' => [],\n    # 'NSZM' => [],\n    /* 'NSZM' => [], */\n    'NSZM' => [],\n],"), []];
        yield 'deeper' => [$file("'accounts' => ['NSZM' => ['sites' => ['a'], 'NSZM' => [], 'x' => ['NSZM' => 1, 'NSZM' => 2]]],"), []];
        yield 'a value, not a key' => [$file("'accounts' => ['NSZM' => ['note' => 'NSZM', 'other' => 'NSZM']],"), []];
        yield 'inside texts' => [$file("'accounts' => [\n    'NSZM' => ['note' => <<<TXT\n        'NSZM' => [\n        TXT, 'n' => <<<'TXT'\n        'NSZM' => [\n        TXT, 'm' => \"{\$x} 'NSZM' => [\"],\n    'ECN' => [], 'ECN' => [],\n],"), [['accounts', 'ECN']]];
        yield 'with a backslash' => [$file("'accounts' => ['N\\SZM' => [], 'N\\SZM' => []],"), []];
        yield 'a key of the file' => [$file("'accounts' => [],\n'instances' => [],\n'accounts' => ['NSZM' => []],"), [['accounts']]];
        yield 'both accounts' => [$file("'accounts' => ['NSZM' => []],\n'accounts' => ['NSZM' => []],"), [['accounts'], ['accounts', 'NSZM']]];
        yield 'a key AA does not read' => [$file("'note' => 1, 'note' => 2, 7 => 1, 7 => 2,"), []];
        yield 'another array' => ["<?php\n\$a = ['accounts' => ['NSZM' => 1, 'NSZM' => 2]];\nconst DATOVKA_CONF = ['accounts' => ['NSZM' => []]];\n", []];
        yield 'after the array' => ["<?php\nconst DATOVKA_CONF = ['accounts' => ['NSZM' => []]];\n\$a = ['NSZM' => 1, 'NSZM' => 2];\n", []];
        yield 'no valid PHP' => [$file("'accounts' => ['NSZM' => [] 'NSZM' => [],"), []];
        yield 'no valid PHP further on' => [$file("'accounts' => ['NSZM' => [], 'NSZM' => []],\n'test' => ['cert' => ,"), [['accounts', 'NSZM']]];
        yield 'after a comment' => [$file("'accounts' => [\n 'NSZM' => [],\n // production\n 'NSZM' => [],\n],"), [['accounts', 'NSZM']]];
        yield 'a list of names' => [$file("'accounts' => ['NSZM', 'NSZM'],"), []];
        yield 'an array where the constant is only asked about' => ["<?php\nif (!defined('DATOVKA_CONF')) {\n    \$z = ['accounts' => ['NSZM' => 1, 'NSZM' => 2]];\n}\n", []];
        yield 'defined in double quotes' => ["<?php\ndefine(\"DATOVKA_CONF\", ['accounts' => ['NSZM' => [], 'NSZM' => []]]);\n", [['accounts', 'NSZM']]];
        yield 'after an attribute' => ["<?php\n#[Pure]\nfunction f(): void {}\nconst DATOVKA_CONF = ['accounts' => ['NSZM' => [], 'NSZM' => []]];\n", [['accounts', 'NSZM']]];
        yield 'an attribute inside' => ["<?php\ndefine('DATOVKA_CONF', ['x' => #[Pure] fn() => 1, 'accounts' => ['NSZM' => [], 'NSZM' => []]]);\n", [['accounts', 'NSZM']]];
        yield 'another constant first' => ["<?php\nconst OTHER = ['accounts' => ['NSZM' => 1, 'NSZM' => 2]];\ndefine('OTHER2', ['accounts' => ['NSZM' => 1, 'NSZM' => 2]]);\nconst DATOVKA_CONF = ['accounts' => ['ECN' => []]];\n", []];
        yield 'the name as a key' => ["<?php\n\$a = ['DATOVKA_CONF' => ['accounts' => ['NSZM' => 1, 'NSZM' => 2]]];\n", []];
        yield 'the name inside' => [$file("'note' => ['DATOVKA_CONF', ['x']],\n'accounts' => ['NSZM' => [], 'NSZM' => []],"), [['accounts', 'NSZM']]];
    }

    /** @param list<list<string>> $paths */
    #[DataProvider('twice')]
    public function test_repeated_keysWrittenTwice(string $php, array $paths): void
    {
        $this->assertSame($paths, DatovkaConf::repeated($this->file($php)));
    }

    public function test_repeated_nothingToRead(): void
    {
        $this->assertSame([], DatovkaConf::repeated("$this->dir/datovka_conf.php"));
        $this->assertSame([], DatovkaConf::repeated($this->dir));
    }

    // a basic check cannot afford the tokens of a long file: not looked at, and the caller knows
    public function test_repeated_longFileNotLookedAt(): void
    {
        $body = "'accounts' => ['NSZM' => [], 'NSZM' => []],\n";
        $php  = "<?php\nconst DATOVKA_CONF = [\n$body];\n";

        $this->assertSame([['accounts', 'NSZM']], DatovkaConf::repeated($this->file(str_pad($php, DatovkaConf::MAX_BYTES, ' '))));
        $this->assertNull(DatovkaConf::repeated($this->file(str_pad($php, DatovkaConf::MAX_BYTES + 1, ' '))));
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

    /**
     * Accounts reads the accounts and names what is wrong in them. Only what AA reads as it is shows in a
     * dump: a password never, under a misspelt key neither, nor an account written as a text.
     */
    public function test_accounts_asWrittenSecretsHidden(): void
    {
        $accounts = [
            'NSZM'  => ['environment' => 'test', 'auth' => 'basic', 'sites' => ['x'], 'login' => 'nszm-login', 'password' => self::SECRET, 'pasword' => self::SECRET . '-3', 'Password' => self::SECRET . '-4', 'heslo' => ['old' => self::SECRET . '-5', 6 => 7]],
            'PROD'  => ['environment' => 'production', 'auth' => 'cert', 'cert' => 'nszm.pem', 'key' => 'nszm.key', 'box' => 'kv62bqf', 'slices' => ['y'], 'passphrase' => self::SECRET . '-2'],
            'NUM'   => ['password' => 12345678, 'passphrase' => [self::SECRET]],
            'NONE'  => ['password' => null, 'note' => null],
            'nszm'  => self::SECRET . '-6',
            7       => [],
            // what AA reads as a text or a list of texts is as written only when it is one
            'LIST'  => ['login' => ['nszm01', self::SECRET . '-7'], 'cert' => (object) ['p' => self::SECRET . '-8'], 'sites' => ['x', 'password' => self::SECRET . '-9', [self::SECRET . '-10']], 'slices' => 7, 'box' => true],
            'BARE'  => ['sites' => self::SECRET . '-11', 'password' => [self::SECRET . '-12']],
        ];

        $conf = DatovkaConf::fromArray(['accounts' => $accounts]);
        $read = $conf->accounts();

        $this->assertSame(['NSZM', 'PROD', 'NUM', 'NONE', 'nszm', 7, 'LIST', 'BARE'], array_keys($read));
        $this->assertSame(['environment', 'auth', 'sites', 'login', 'password', 'pasword', 'Password', 'heslo'], array_keys($read['NSZM']));
        $this->assertSame(['environment' => 'test', 'auth' => 'basic', 'sites' => ['x'], 'login' => 'nszm-login'], array_slice($read['NSZM'], 0, 4), 'read as they are');
        $this->assertSame(['environment' => 'production', 'auth' => 'cert', 'cert' => 'nszm.pem', 'key' => 'nszm.key', 'box' => 'kv62bqf', 'slices' => ['y']], array_slice($read['PROD'], 0, 6), 'read as they are');
        $revealed = static fn(mixed $value): mixed => ($value instanceof Secret) ? $value->reveal() : $value;
        $this->assertSame([self::SECRET, self::SECRET . '-3', self::SECRET . '-4', self::SECRET . '-2', self::SECRET . '-6'], array_map($revealed, [$read['NSZM']['password'], $read['NSZM']['pasword'], $read['NSZM']['Password'], $read['PROD']['passphrase'], $read['nszm']]));
        $this->assertSame(['old' => self::SECRET . '-5', 6 => '7'], array_map($revealed, $read['NSZM']['heslo']), 'at any depth');
        $this->assertSame([false, [self::SECRET]], [$read['NUM']['password'], array_map($revealed, $read['NUM']['passphrase'])], 'no text: Accounts asks for quotes');
        $this->assertSame([self::SECRET . '-11', [self::SECRET . '-12']], [$revealed($read['BARE']['sites']), array_map($revealed, $read['BARE']['password'])], 'a list where a text belongs, a text where a list belongs');
        $this->assertSame(['password' => null, 'note' => null], $read['NONE']);
        $this->assertSame([], $read[7]);
        $this->assertSame([['nszm01', self::SECRET . '-7'], '', 'x', self::SECRET . '-9', [self::SECRET . '-10'], '7', '1'], [array_map($revealed, $read['LIST']['login']), $revealed($read['LIST']['cert']), $read['LIST']['sites'][0], $revealed($read['LIST']['sites']['password']), array_map($revealed, $read['LIST']['sites'][1]), $revealed($read['LIST']['slices']), $revealed($read['LIST']['box'])]);
        // only the file and its accounts are AA's business here: Accounts::strays() names the misspelt keys
        $this->assertSame([[], [], []], [$conf->problems(), $conf->strays(), $conf->loose()]);

        ob_start();
        var_dump($conf);
        $dumps = ob_get_clean() . print_r($conf, true) . var_export($conf, true) . json_encode($conf->accounts());
        $this->assertStringContainsString('nszm-login', $dumps, 'the dumps show the accounts');
        $this->assertStringNotContainsString(self::SECRET, $dumps);
    }

    /** an account a bracket out of its place: at the top, in test or standard - its texts hidden, Accounts looks for ids in it */
    public function test_loose_arraysUnderKeysAaDoesNotKnow(): void
    {
        $block = ['environment' => 'production', 'sites' => ['x'], 'password' => self::SECRET, 'nested' => ['n' => self::SECRET . '-2']];
        $conf  = DatovkaConf::fromArray([
            'NSZM'      => $block,
            'note'      => self::SECRET . '-3',
            'test'      => ['atsId' => '', 'ECN' => ['sites' => ['y']], 'url_login' => ['z']],
            'standard'  => ['PROD' => 'production'],
            'accounts'  => [],
            // an installation holds the text url only
            'instances' => ['ABC' => ['w'], 0 => ['url' => 'https://example.org/aa/'], 1000 => ['url' => 'http://example.org/aa/'], 1001 => ['url' => 'https://example.net/aa/', 'NSZM' => ['sites' => ['v']]]],
        ]);

        $revealed = static function (mixed $value) use (&$revealed): mixed {
            return is_array($value) ? array_map($revealed, $value) : (($value instanceof Secret) ? $value->reveal() : $value);
        };
        $this->assertSame([['sites' => ['y']], ['z'], ['w'], ['sites' => ['v']], $block], array_map($revealed, $conf->loose()), 'arrays only, as written - url_login holds a text');
        $this->assertSame([1001 => 'https://example.net/aa/'], $conf->instances());
        $this->assertSame(["DATOVKA_CONF['test']['ECN']", "DATOVKA_CONF['test']['url_login']", "DATOVKA_CONF['standard']['PROD']", "DATOVKA_CONF['instances'][1001]['NSZM']", "DATOVKA_CONF['NSZM']", "DATOVKA_CONF['note']"], $conf->strays(), 'url_login holds no address');
        $this->assertSame([], $conf->ignored());
        $this->assertInstanceOf(Secret::class, $conf->loose()[4]['environment']);

        ob_start();
        var_dump($conf);
        $dumps = ob_get_clean() . print_r($conf, true) . var_export($conf, true);
        $this->assertStringNotContainsString(self::SECRET, $dumps);
    }

    /** @return iterable<string, array{string, string}> */
    public static function paths(): iterable
    {
        yield 'relative' => ['nszm.pem', self::root() . 'config/certs/nszm.pem'];
        yield 'in a directory of its own' => ['nszm/cert.pem', self::root() . 'config/certs/nszm/cert.pem'];
        yield 'full' => ['/etc/aa-isds/nszm.pem', '/etc/aa-isds/nszm.pem'];
        yield 'with spaces' => [' /etc/aa-isds/nszm.pem ', '/etc/aa-isds/nszm.pem'];
        yield 'relative with spaces' => [" nszm.pem\t", self::root() . 'config/certs/nszm.pem'];
        yield 'none' => ['', ''];
        yield 'only spaces' => ['  ', ''];
    }

    /** one rule for every file the settings name: the certificate of the registration and those of the accounts */
    #[DataProvider('paths')]
    public function test_path_relativeStartsInCertDir(string $path, string $full): void
    {
        $this->assertSame($full, DatovkaConf::path($path));
        $this->assertSame(self::root(), DatovkaConf::base());
    }

    public function test_fromFile_missingGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile("$this->dir/datovka_conf.php");

        $this->assertDefaults($conf);
        $this->assertSame([], $conf->problems());
        $this->assertSame([], $conf->strays());
        $this->assertFailure('', $conf);
    }

    /** PHP quotes the code around a syntax error - the value after the missing comma here */
    public function test_fromFile_invalidPhpGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile($this->file("<?php\nconst DATOVKA_CONF = [\n    'test' => ['atsId' => ''] '" . self::SECRET . "',\n];\n"));

        $this->assertDefaults($conf);
        $this->assertSame(['misc/datovka/datovka_conf.php is not valid PHP (line 3) - AA runs as if the file were not there'], $conf->problems());
        $this->assertFailure('broken', $conf);
    }

    /** an anonymous class is named with the file and the line of the code after a NUL - neither may reach the log */
    public function test_fromFile_anonymousErrorNamedByItsParent(): void
    {
        $conf = DatovkaConf::fromFile($this->file("<?php\nthrow new class ('" . self::SECRET . "') extends \\RuntimeException {};\n"));

        $this->assertSame(['misc/datovka/datovka_conf.php stops with RuntimeException@anonymous - AA runs as if the file were not there'], $conf->problems());
        $this->assertFailure('broken', $conf);
    }

    public function test_fromFile_errorGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile($this->file("<?php\nconst DATOVKA_CONF = [\n    'test' => ['cert' => AA_NO_SUCH_CONSTANT . '" . self::SECRET . "'],\n];\n"));

        $this->assertDefaults($conf);
        $this->assertSame(['misc/datovka/datovka_conf.php stops with Error - AA runs as if the file were not there'], $conf->problems());
        $this->assertFailure('broken', $conf);
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
        $this->assertFailure('unreadable', $conf);
    }

    /** a directory PHP cannot list may hold the file: no file is a file without accounts, not knowing is not */
    public function test_fromFile_unlistedDirectoryIsNoMissingFile(): void
    {
        $dir = "$this->dir/unlisted";
        mkdir($dir, 0o300);
        try {
            if (@scandir($dir) !== false) {
                $this->markTestSkipped('root lists any directory');
            }

            $conf = DatovkaConf::fromFile("$dir/datovka_conf.php");

            $this->assertDefaults($conf);
            $this->assertSame(['PHP cannot list the directory of misc/datovka/datovka_conf.php, so it cannot tell whether the file is there - check that open_basedir lets PHP there and that PHP may read the directory'], $conf->problems());
            $this->assertFailure('unreadable', $conf);
        } finally {
            chmod($dir, 0o700);
            rmdir($dir);
        }
    }

    /** another tree over the same database links the file of the first one, which may be gone */
    public function test_fromFile_brokenLinkIsNoMissingFile(): void
    {
        symlink("$this->dir/gone.php", "$this->dir/datovka_conf.php");

        $conf = DatovkaConf::fromFile("$this->dir/datovka_conf.php");

        $this->assertDefaults($conf);
        $this->assertSame([self::UNREACHABLE], $conf->problems());
        $this->assertFailure('unreadable', $conf);
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
        $this->assertFailure('unreadable', $conf);
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
        $this->assertCount(6, $conf->ignored());
        $this->assertSame([], $conf->accounts());
        $this->assertFailure('', $conf);
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
        $this->assertFailure('broken', $conf);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_fromFile_notArrayGivesDefaults(): void
    {
        $conf = DatovkaConf::fromFile($this->file("<?php\nconst DATOVKA_CONF = '" . self::SECRET . "';\n"));

        $this->assertDefaults($conf);
        $this->assertSame(['DATOVKA_CONF in misc/datovka/datovka_conf.php is not an array - AA runs as if the file were not there'], $conf->problems());
        $this->assertFailure('broken', $conf);
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
        $this->assertSame(['instances', 'test', 'standard', 'accounts'], array_keys($template));
        $this->assertSame([], $conf->accounts());
        $this->assertSame(['cert', 'atsId'], array_keys($template['test']));
        $this->assertSame(['cert', 'atsId'], array_keys($template['standard']));
        $text = (string) file_get_contents($file);
        $this->assertStringNotContainsString('AA_BASE_PATH', $text, 'a directly requested file must print nothing, not an error');
        $this->assertDoesNotMatchRegularExpression('/\b[0-9a-f]{16}\b/', $text, 'no atsId, not even in a comment');
        // the example of an account shows a placeholder such as '<password>' or nothing in their place
        $this->assertMatchesRegularExpression('/[\'"]password[\'"]\s*=>/', $text, 'the example of an account is there');
        $this->assertDoesNotMatchRegularExpression('/[\'"](password|passphrase|login|box)[\'"]\s*+=>\s*+(?![\'"](<[^<>\'"]*>)?[\'"])/i', $text);
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
