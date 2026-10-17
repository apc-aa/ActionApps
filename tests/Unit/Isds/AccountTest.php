<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Credential\Secret;
use AA\Http\ClientCertificate;
use AA\Isds\Account;
use AA\Isds\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Fixtures\Certificates;

final class AccountTest extends TestCase
{
    private const PASSWORD = 'Pa55-word-never-shown';

    /** throwaway certificates of this run */
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/Certificates.php';
        self::$dir           = Certificates::directory();
        [$certificate, $key] = Certificates::make();
        [$another, $hisKey]  = Certificates::make('Another', 43);
        Certificates::file(self::$dir, 'both.pem', $certificate . $key);
        Certificates::file(self::$dir, 'chain.pem', str_replace("\n", "\r\n", $certificate . $another));
        Certificates::file(self::$dir, 'encrypted.key', Certificates::encrypted($key, 'Pass-phrase'));
        Certificates::file(self::$dir, 'another.pem', $another . $hisKey);
        Certificates::file(self::$dir, 'garbage.pem', 'not a certificate');
    }

    public static function tearDownAfterClass(): void
    {
        Certificates::remove(self::$dir);
    }

    private static function account(string $environment = Account::TEST, string $login = 'user01', string $password = self::PASSWORD): Account
    {
        return new Account($environment, $login, new Secret($password));
    }

    #[DataProvider('urls')]
    public function test_url_byService(string $service, string $expected): void
    {
        self::assertSame($expected, self::account()->url($service));
    }

    public static function urls(): array
    {
        return [
            'messages' => ['dz', 'https://ws1.datovka-test.gov.cz/DS/dz'],
            'info'     => ['dx', 'https://ws1.datovka-test.gov.cz/DS/dx'],
            'search'   => ['df', 'https://ws1.datovka-test.gov.cz/DS/df'],
            'account'  => ['DsManage', 'https://ws1.datovka-test.gov.cz/DS/DsManage'],
        ];
    }

    /**
     * WS_manipulace_s_datovymi_zpravami.pdf 1.2.1.1 and 1.2.1.3, Provozni_rad_ISDS.pdf p. 15: basic auth
     * on ws1, a system certificate on ws1c/cert, a hosted filing service on ws1c/hspis
     */
    #[DataProvider('urlsByKind')]
    public function test_url_byKindAndEnvironment(string $kind, string $environment, string $expected): void
    {
        $account = ($kind === Account::BASIC) ? self::account($environment) : Account::withCertificate($environment, new ClientCertificate('/etc/aa/isds.pem'), ($kind === Account::HOSTED) ? 'kv62bqf' : '');

        self::assertSame($kind, $account->kind);
        self::assertSame($expected, $account->url('dz'));
    }

    public static function urlsByKind(): array
    {
        return [
            'basic, test'        => [Account::BASIC, Account::TEST, 'https://ws1.datovka-test.gov.cz/DS/dz'],
            'cert, test'         => [Account::CERT, Account::TEST, 'https://ws1c.datovka-test.gov.cz/cert/DS/dz'],
            'cert, production'   => [Account::CERT, Account::PRODUCTION, 'https://ws1c.datovka.gov.cz/cert/DS/dz'],
            'hosted, test'       => [Account::HOSTED, Account::TEST, 'https://ws1c.datovka-test.gov.cz/hspis/DS/dz'],
            'hosted, production' => [Account::HOSTED, Account::PRODUCTION, 'https://ws1c.datovka.gov.cz/hspis/DS/dz'],
        ];
    }

    // the hosted service names the box in basic auth with an empty password (Provozni_rad_ISDS.pdf p. 15)
    public function test_headers_byKind(): void
    {
        $certificate = new ClientCertificate('/etc/aa/isds.pem');

        self::assertSame([], Account::withCertificate(Account::PRODUCTION, $certificate)->headers());
        self::assertSame(['Authorization' => 'Basic ' . base64_encode('kv62bqf:')], Account::withCertificate(Account::PRODUCTION, $certificate, 'kv62bqf')->headers());
    }

    #[DataProvider('notCertificateLogins')]
    public function test_construct_refusesCertificateLogin(string $login, string $password): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Account(Account::PRODUCTION, $login, new Secret($password), new ClientCertificate('/etc/aa/isds.pem'));
    }

    public static function notCertificateLogins(): array
    {
        return [
            'with a password'          => ['', self::PASSWORD],
            'hosted, not a box id'     => ['KV62BQF', ''],
            'hosted, a name'           => ['user01', ''],
            'hosted with a password'   => ['kv62bqf', self::PASSWORD],
        ];
    }

    // the kind follows from the certificate and the login
    public function test_construct_kindOfLogin(): void
    {
        $certificate = new ClientCertificate('/etc/aa/isds.pem');

        self::assertSame(Account::BASIC, self::account()->kind);
        self::assertSame(Account::CERT, (new Account(Account::PRODUCTION, '', new Secret(''), $certificate))->kind);
        self::assertSame(Account::HOSTED, (new Account(Account::PRODUCTION, 'kv62bqf', new Secret(''), $certificate))->kind);
    }

    // the box id is in ISDS answers and in advice - it stays; a short passphrase would cut up host names and dates
    public function test_redact_certificateLogins(): void
    {
        $of = static fn(string $passphrase): Account => Account::withCertificate(Account::TEST, new ClientCertificate('/etc/aa/isds.pem', passphrase: new Secret($passphrase)), 'kv62bqf');

        self::assertSame('box kv62bqf, *** key', $of('Pass-phrase-never-shown')->redact('box kv62bqf, Pass-phrase-never-shown key'));
        self::assertSame(strlen('Pass-phrase-never-shown'), $of('Pass-phrase-never-shown')->longestSecret());
        self::assertSame('ws1c.datovka.gov.cz 2026-10-17', $of('2026')->redact('ws1c.datovka.gov.cz 2026-10-17'));
        self::assertSame('*** at ws1c', $of('12345678')->redact('12345678 at ws1c'), '8 bytes are enough');
        self::assertSame(0, $of('')->longestSecret());
    }

    /**
     * A refusal of a certificate lasts until a new certificate: a new passphrase, a re-encrypted key, a
     * chain added to the file or other line ends do not release it
     */
    public function test_fingerprint_certificateByItself(): void
    {
        $of = static fn(string $file, string $key = '', string $box = '', string $environment = Account::TEST): string => Account::withCertificate($environment, new ClientCertificate(self::$dir . "/$file", ($key === '') ? '' : self::$dir . "/$key", new Secret('Pass-phrase')), $box)->fingerprint('key');

        self::assertSame($of('both.pem'), $of('chain.pem'));
        self::assertSame($of('both.pem'), $of('both.pem', 'encrypted.key'));
        self::assertNotSame($of('both.pem'), $of('another.pem'));
        self::assertNotSame($of('both.pem'), $of('both.pem', box: 'kv62bqf'));
        self::assertNotSame($of('both.pem', box: 'kv62bqf'), $of('both.pem', box: 'kv62bqg'));
        self::assertNotSame($of('both.pem'), $of('both.pem', environment: Account::PRODUCTION));
    }

    // never a fingerprint any certificate would share
    #[DataProvider('unreadableCertificates')]
    public function test_fingerprint_failsClosed(string $file): void
    {
        $this->expectException(ConfigException::class);
        Account::withCertificate(Account::TEST, new ClientCertificate(self::$dir . "/$file"))->fingerprint('key');
    }

    public static function unreadableCertificates(): array
    {
        return ['no file' => ['none.pem'], 'not a certificate' => ['garbage.pem']];
    }

    // production lets an application in with a certificate only, never with a password given to a server
    public function test_construct_refusesProduction(): void
    {
        try {
            self::account(Account::PRODUCTION);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('certificate', $e->getMessage());
        }
    }

    #[DataProvider('notEnvironments')]
    public function test_construct_refusesUnknownEnvironment(string $environment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::account($environment);
    }

    public static function notEnvironments(): array
    {
        return ['empty' => [''], 'upper case' => ['TEST'], 'short' => ['prod'], 'other' => ['staging']];
    }

    #[DataProvider('notLogins')]
    public function test_construct_refusesLogin(string $login): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::account(login: $login);
    }

    public static function notLogins(): array
    {
        return ['empty' => [''], 'colon' => ['user:01']];
    }

    // WS_souvisejici_s_pristupem_do_ISDS.pdf 1.9: 8 to 64 characters of ASCII
    #[DataProvider('impossiblePasswords')]
    public function test_construct_refusesPassword(string $password): void
    {
        try {
            self::account(password: $password);
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('password', $e->getMessage());
        }
    }

    public static function impossiblePasswords(): array
    {
        return [
            'empty'     => [''],
            'seven'     => ['Pa55-wo'],
            '65'        => [str_repeat('a', 65)],
            'not ascii' => ['Pa55-heslíčko'],
            'line end'  => ["Pa55-word\n"],
        ];
    }

    public function test_construct_acceptsPasswordLimits(): void
    {
        self::assertSame('user01', self::account(password: 'Pa5 -~{}')->login);
        self::assertSame('user01', self::account(password: str_repeat('a', 64))->login);
    }

    public function test_headers_basicAuth(): void
    {
        self::assertSame(['Authorization' => 'Basic ' . base64_encode('user01:' . self::PASSWORD)], self::account()->headers());
    }

    public function test_redact_loginPasswordAndPair(): void
    {
        $pair = base64_encode('user01:' . self::PASSWORD);

        self::assertSame('*** no *** in ***, ***; user0', self::account()->redact('USER01 no ' . self::PASSWORD . " in $pair, user01; user0"));
    }

    // the longest first: the password may hold the login
    public function test_redact_passwordHoldingTheLogin(): void
    {
        self::assertSame('a *** b', self::account(password: 'user01-secret')->redact('a user01-secret b'));
    }

    public function test_fingerprint_keyedAndOfAllThree(): void
    {
        $fingerprint = self::account()->fingerprint('key');

        self::assertSame(hash_hmac('sha256', "test\0user01\0" . self::PASSWORD, 'key'), $fingerprint);
        self::assertNotSame($fingerprint, self::account()->fingerprint('other key'));
        self::assertNotSame($fingerprint, self::account(password: 'Another-Pa55')->fingerprint('key'));
        self::assertNotSame($fingerprint, self::account(login: 'user02')->fingerprint('key'));
    }

    public function test_password_notInDumps(): void
    {
        $account = self::account();
        ob_start();
        var_dump($account);
        $dumps = [(string) ob_get_clean(), print_r($account, true), var_export($account, true), (string) json_encode($account), print_r((array) $account, true)];

        foreach ($dumps as $dump) {
            self::assertStringNotContainsString(self::PASSWORD, $dump);
        }
        $this->expectException(\Exception::class);
        serialize($account);
    }

    public function test_construct_passwordNotInTraceArgs(): void
    {
        $ignore = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            new Account('staging', 'user01', new Secret(self::PASSWORD));
            self::fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            $args = print_r($e->getTrace()[0]['args'] ?? [], true);
            self::assertStringContainsString('staging', $args, 'the trace keeps arguments');
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage() . $args);
            self::assertStringNotContainsString('user01', $args, 'the login is sensitive too');
        } finally {
            ini_set('zend.exception_ignore_args', $ignore);
        }
    }
}
