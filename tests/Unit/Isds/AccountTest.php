<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Credential\Secret;
use AA\Isds\Account;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    private const PASSWORD = 'Pa55-word-never-shown';

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

    // a typo in a URL cannot take a test account to production
    public function test_hosts_testIsNotProduction(): void
    {
        $hosts = (new \ReflectionClass(Account::class))->getConstant('HOSTS');

        self::assertSame([Account::TEST => 'https://ws1.datovka-test.gov.cz', Account::PRODUCTION => 'https://ws1.datovka.gov.cz'], $hosts);
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
