<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Account;
use AA\Isds\Accounts;
use AA\Isds\Binding;
use AA\Isds\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The constants are passed in as Store::userConstants() would give them - defined ones cannot be
 * undefined. Only test_forSlices_definedConstants and test_forSlices_keysNotInTraces define real
 * ones, under names, sites and slices no other test uses, so the order of the tests does not matter.
 */
final class AccountsTest extends TestCase
{
    private const SITE      = '51de0000000000000000000000000001';
    private const SITE2     = '51de0000000000000000000000000002';
    private const CAMPAIGN  = 'cafe0000000000000000000000000001';
    private const MESSAGE   = 'beef0000000000000000000000000002';
    private const CAMPAIGN2 = 'cafe0000000000000000000000000003';
    private const MESSAGE2  = 'beef0000000000000000000000000004';
    private const PAIR      = self::CAMPAIGN . ',' . self::MESSAGE;
    private const PAIR2     = self::CAMPAIGN2 . ',' . self::MESSAGE2;

    private const LOGIN         = 'nszm01login';
    private const PASSWORD      = 'Test-Pa55-never-shown';
    private const PROD_PASSWORD = 'Prod-Pa55-never-shown';

    /** @return array<string, string> constants of a test account; null leaves one out */
    private static function testAccount(string $name = 'NSZM', ?string $slices = self::PAIR, ?string $login = self::LOGIN, ?string $password = self::PASSWORD, ?string $site = self::SITE): array
    {
        return array_filter([
            "AA_APIKEY_ISDSTEST_{$name}_SITE"     => $site,
            "AA_APIKEY_ISDSTEST_{$name}_SLICES"   => $slices,
            "AA_APIKEY_ISDSTEST_{$name}_LOGIN"    => $login,
            "AA_APIKEY_ISDSTEST_{$name}_PASSWORD" => $password,
        ], static fn(?string $v): bool => !is_null($v));
    }

    /** @return array<string, string> a production account as an admin might try: with a name and a password */
    private static function productionAccount(string $name = 'NSZM', ?string $slices = self::PAIR, ?string $site = self::SITE, ?string $password = self::PROD_PASSWORD): array
    {
        return array_filter([
            "AA_APIKEY_ISDS_{$name}_SITE"     => $site,
            "AA_APIKEY_ISDS_{$name}_SLICES"   => $slices,
            "AA_APIKEY_ISDS_{$name}_LOGIN"    => self::LOGIN,
            "AA_APIKEY_ISDS_{$name}_PASSWORD" => $password,
        ], static fn(?string $v): bool => !is_null($v));
    }

    /** the only refusal a template author gets: about the site and slices asked for, nothing about the accounts there are */
    private static function refusalText(string $site = self::SITE, string $campaign = self::CAMPAIGN, string $message = self::MESSAGE): string
    {
        return "No usable ISDS account in include/config.php is bound to this site and these slices. If a test account is bound and you mean it, ask for the test environment. To bind a test account, set AA_APIKEY_ISDSTEST_<ACCOUNT>_SITE to '$site', AA_APIKEY_ISDSTEST_<ACCOUNT>_SLICES to '$campaign,$message' (the campaign slice first), AA_APIKEY_ISDSTEST_<ACCOUNT>_LOGIN and AA_APIKEY_ISDSTEST_<ACCOUNT>_PASSWORD; the State Check lists the accounts and their problems";
    }

    private static function assertRefused(array $constants, string $environment = '', string $site = self::SITE, string $campaign = self::CAMPAIGN, string $message = self::MESSAGE): void
    {
        try {
            Accounts::forSlices($site, $campaign, $message, $environment, $constants);
            self::fail('ConfigException expected');
        } catch (ConfigException $e) {
            self::assertSame(self::refusalText(strtolower($site), strtolower($campaign), strtolower($message)), $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    /** @param array<string, string> $constants */
    private static function binding(array $constants, string $name = 'NSZM'): Binding
    {
        foreach (Accounts::bindings($constants) as $binding) {
            if ($binding->name === $name) {
                return $binding;
            }
        }
        self::fail("no binding $name");
    }

    public function test_forSlices_testAccountOfTheSiteAndPair(): void
    {
        $account = Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', self::testAccount());

        self::assertSame(Account::TEST, $account->environment);
        self::assertSame(self::LOGIN, $account->login);
        self::assertSame('https://ws1.datovka-test.gov.cz/DS/dz', $account->url('dz'));
        self::assertSame(['Authorization' => 'Basic ' . base64_encode(self::LOGIN . ':' . self::PASSWORD)], $account->headers());
    }

    /**
     * Production only where config.php binds it (and AA cannot log in there yet), never quietly test
     * in its place: a run sent to the test ISDS reaches nobody.
     * @param list<string> $bound environments with an account bound to the site and pair
     */
    #[DataProvider('environments')]
    public function test_forSlices_environment(array $bound, string $asked, bool $sends): void
    {
        $constants = [];
        if (in_array(Account::TEST, $bound, true)) {
            $constants += self::testAccount();
        }
        if (in_array(Account::PRODUCTION, $bound, true)) {
            $constants += self::productionAccount();
        }

        if ($sends) {
            self::assertSame(Account::TEST, Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, $asked, $constants)->environment);
        } else {
            self::assertRefused($constants, $asked);
        }
    }

    public static function environments(): array
    {
        return [
            'test bound, none asked'             => [[Account::TEST], '', true],
            'test bound, test asked'             => [[Account::TEST], Account::TEST, true],
            'test bound, production asked'       => [[Account::TEST], Account::PRODUCTION, false],
            'production bound, none asked'       => [[Account::PRODUCTION], '', false],
            'production bound, test asked'       => [[Account::PRODUCTION], Account::TEST, false],
            'production bound, production asked' => [[Account::PRODUCTION], Account::PRODUCTION, false],
            'both bound, none asked'             => [[Account::TEST, Account::PRODUCTION], '', false],
            'both bound, test asked'             => [[Account::TEST, Account::PRODUCTION], Account::TEST, true],
            'nothing bound, none asked'          => [[], '', false],
            'nothing bound, test asked'          => [[], Account::TEST, false],
        ];
    }

    /**
     * A production account that may be meant for the site or the slices, even a broken one, keeps ''
     * from test - unless no SITE or SLICES constant of it is there, not even an empty or unreadable
     * one: then it is bound to nothing and does not.
     */
    #[DataProvider('productionNearby')]
    public function test_forSlices_noneAskedNeverTestInPlaceOfProduction(array $production, bool $refused): void
    {
        $constants = self::testAccount() + $production;

        if ($refused) {
            self::assertRefused($constants);
        } else {
            self::assertSame(Account::TEST, Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $constants)->environment);
        }
        self::assertSame(self::LOGIN, Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, Account::TEST, $constants)->login, 'asking for test is always fine');
    }

    public static function productionNearby(): array
    {
        return [
            'the pair reversed, another site' => [self::productionAccount(slices: self::MESSAGE . ',' . self::CAMPAIGN, site: self::SITE2), true],
            'a typo in the slices'            => [self::productionAccount(slices: self::CAMPAIGN . ';' . self::MESSAGE), true],
            'one slice of the pair'           => [self::productionAccount(slices: self::CAMPAIGN . ',' . self::MESSAGE2, site: self::SITE2), true],
            'this site, other slices'         => [self::productionAccount(slices: self::PAIR2), true],
            'nothing readable'                => [self::productionAccount(slices: null, site: 'x'), true],
            'damaged slices, no site'         => [self::productionAccount(slices: self::PAIR . "\n", site: null), true],
            'another site and slices'         => [self::productionAccount(slices: self::PAIR2, site: self::SITE2), false],
            'empty site and slices'           => [self::productionAccount(slices: '', site: ''), true],
            'unreadable slices, another site' => [self::productionAccount(slices: 'x', site: self::SITE2), false],
            'unreadable site, other slices'   => [self::productionAccount(slices: self::PAIR2, site: 'x'), false],
            'a login, no site, no slices'     => [self::productionAccount(slices: null, site: null), false],
            'test written as ISDS_TEST'       => [['AA_APIKEY_ISDS_TEST_LOGIN' => self::LOGIN, 'AA_APIKEY_ISDS_TEST_PASSWORD' => self::PROD_PASSWORD], false],
            'a vendor of two words'           => [['AA_APIKEY_ISDS_REGISTRY_LOGIN' => 'registry-login'], false],
        ];
    }

    /** bound to nothing, yet State Check lists it */
    public function test_bindings_productionBoundToNothing(): void
    {
        $binding = self::binding(['AA_APIKEY_ISDS_TEST_LOGIN' => self::LOGIN, 'AA_APIKEY_ISDS_TEST_PASSWORD' => self::PROD_PASSWORD], 'TEST');

        self::assertSame([Account::PRODUCTION, [], '', '', false], [$binding->environment, $binding->sites, $binding->campaignSlice, $binding->messageSlice, $binding->targeted]);
        self::assertSame([
            'Set AA_APIKEY_ISDS_TEST_SITE in config.php to the id of the site the account sends for - more sites separated by commas',
            'Set AA_APIKEY_ISDS_TEST_SLICES in config.php to the ids of the campaign slice and the message slice the account sends for, separated by a comma',
            'The production account TEST cannot be used: production ISDS lets an application in with a certificate only, and AA cannot log in with one yet',
            'Remove AA_APIKEY_ISDS_TEST_PASSWORD from config.php - AA never logs in to production ISDS with a password',
        ], $binding->problems);
        self::assertTrue(self::binding(self::productionAccount(slices: null, site: 'x'))->targeted);
    }

    /** slice ids are public: the site, which the caller passes, decides */
    #[DataProvider('sites')]
    public function test_forSlices_site(string $sites, string $asked, bool $sends): void
    {
        $constants = self::testAccount(site: $sites);

        if ($sends) {
            self::assertSame(self::LOGIN, Accounts::forSlices($asked, self::CAMPAIGN, self::MESSAGE, '', $constants)->login);
        } else {
            self::assertRefused($constants, site: $asked);
        }
    }

    public static function sites(): array
    {
        return [
            'the site'                  => [self::SITE, self::SITE, true],
            'one of more, capitals'     => [' ' . strtoupper(self::SITE2) . " ,\t" . strtoupper(self::SITE) . ' ', self::SITE, true],
            'asked in capitals'         => [self::SITE, strtoupper(self::SITE), true],
            'another site'              => [self::SITE, self::SITE2, false],
            'a list without this site'  => [self::SITE2 . ',' . self::CAMPAIGN, self::SITE, false],
        ];
    }

    #[DataProvider('pairs')]
    public function test_forSlices_pair(string $slices, string $campaign, string $message, bool $sends): void
    {
        $constants = self::testAccount(slices: $slices);

        if ($sends) {
            self::assertSame(self::LOGIN, Accounts::forSlices(self::SITE, $campaign, $message, '', $constants)->login);
        } else {
            self::assertRefused($constants, '', self::SITE, $campaign, $message);
        }
    }

    public static function pairs(): array
    {
        return [
            'exact'                      => [self::PAIR, self::CAMPAIGN, self::MESSAGE, true],
            'capitals and spaces'        => [' ' . strtoupper(self::CAMPAIGN) . " ,\t" . strtoupper(self::MESSAGE) . ' ', self::CAMPAIGN, self::MESSAGE, true],
            'asked in capitals'          => [self::PAIR, strtoupper(self::CAMPAIGN), strtoupper(self::MESSAGE), true],
            'another message slice'      => [self::PAIR, self::CAMPAIGN, self::MESSAGE2, false],
            'another campaign slice'     => [self::PAIR, self::CAMPAIGN2, self::MESSAGE, false],
            'campaign slice as messages' => [self::PAIR, self::MESSAGE2, self::CAMPAIGN, false],
            'the other way round'        => [self::PAIR, self::MESSAGE, self::CAMPAIGN, false],
            'another pair'               => [self::PAIR, self::CAMPAIGN2, self::MESSAGE2, false],
        ];
    }

    #[DataProvider('brokenSlices')]
    public function test_bindings_brokenSlices(string $slices, string $problem): void
    {
        $constants = self::testAccount(slices: $slices);
        $binding   = self::binding($constants);

        self::assertSame(['', ''], [$binding->campaignSlice, $binding->messageSlice]);
        self::assertSame([$problem], $binding->problems);
        self::assertRefused($constants);
    }

    public static function brokenSlices(): array
    {
        $broken = 'AA_APIKEY_ISDSTEST_NSZM_SLICES is not a pair of slices - write the id of the campaign slice, a comma and the id of the message slice, 32 hexadecimal digits each';
        return [
            'empty'           => ['', 'Set AA_APIKEY_ISDSTEST_NSZM_SLICES in config.php to the ids of the campaign slice and the message slice the account sends for, separated by a comma'],
            'one slice'       => [self::CAMPAIGN, $broken],
            'three slices'    => [self::PAIR . ',' . self::CAMPAIGN2, $broken],
            'semicolon'       => [self::CAMPAIGN . ';' . self::MESSAGE, $broken],
            'space'           => [self::CAMPAIGN . ' ' . self::MESSAGE, $broken],
            'empty part'      => [self::CAMPAIGN . ',,' . self::MESSAGE, $broken],
            'short id'        => [substr(self::CAMPAIGN, 1) . ',' . self::MESSAGE, $broken],
            'not hexadecimal' => ['g' . substr(self::CAMPAIGN, 1) . ',' . self::MESSAGE, $broken],
            'one slice twice' => [self::CAMPAIGN . ',' . strtoupper(self::CAMPAIGN), $broken],
            'line break'      => [self::CAMPAIGN . ",\n" . self::MESSAGE, 'AA_APIKEY_ISDSTEST_NSZM_SLICES holds a line break or NUL - fix it in the configuration'],
        ];
    }

    #[DataProvider('brokenSites')]
    public function test_bindings_brokenSite(?string $site, string $problem): void
    {
        $constants = self::testAccount(site: $site);
        $binding   = self::binding($constants);

        self::assertSame([], $binding->sites);
        self::assertSame([$problem], $binding->problems);
        self::assertRefused($constants);
    }

    public static function brokenSites(): array
    {
        $broken = 'AA_APIKEY_ISDSTEST_NSZM_SITE is not a list of site ids - 32 hexadecimal digits each, separated by commas';
        $set    = 'Set AA_APIKEY_ISDSTEST_NSZM_SITE in config.php to the id of the site the account sends for - more sites separated by commas';
        return [
            'missing'         => [null, $set],
            'empty'           => ['', $set],
            'not hexadecimal' => ['x' . substr(self::SITE, 1), $broken],
            'empty part'      => [self::SITE . ',,' . self::SITE2, $broken],
            'semicolon'       => [self::SITE . ';' . self::SITE2, $broken],
            'line break'      => [self::SITE . "\r\n", 'AA_APIKEY_ISDSTEST_NSZM_SITE holds a line break or NUL - fix it in the configuration'],
        ];
    }

    /** an empty constant is a missing one, and the vendor's own keys or another account's are not this account's */
    #[DataProvider('keyProblems')]
    public function test_bindings_keyProblem(array $constants, string $problem): void
    {
        self::assertSame([$problem], self::binding($constants)->problems);
        self::assertRefused($constants);
    }

    public static function keyProblems(): array
    {
        $set        = static fn(string $what): string => "Set $what in config.php - the account logs in with a name and a password";
        $both       = $set('AA_APIKEY_ISDSTEST_NSZM_LOGIN and AA_APIKEY_ISDSTEST_NSZM_PASSWORD');
        $fix        = 'Fix AA_APIKEY_ISDSTEST_NSZM_LOGIN or AA_APIKEY_ISDSTEST_NSZM_PASSWORD in config.php: ';
        $vendorKeys = ['AA_APIKEY_ISDSTEST' => self::PASSWORD, 'AA_APIKEY_ISDSTEST_LOGIN' => self::LOGIN, 'AA_APIKEY_ISDSTEST_PASSWORD' => self::PASSWORD];
        return [
            'login'                      => [self::testAccount(login: null), $set('AA_APIKEY_ISDSTEST_NSZM_LOGIN')],
            'password'                   => [self::testAccount(password: null), $set('AA_APIKEY_ISDSTEST_NSZM_PASSWORD')],
            'empty password'             => [self::testAccount(password: ''), $set('AA_APIKEY_ISDSTEST_NSZM_PASSWORD')],
            'both'                       => [self::testAccount(login: null, password: null), $both],
            'only the vendor keys'       => [self::testAccount(login: null, password: null) + $vendorKeys, $both],
            'another account keys'       => [self::testAccount(login: null, password: null) + self::testAccount('OTHER', self::PAIR2, site: self::SITE2), $both],
            'password with a line break' => [self::testAccount(password: self::PASSWORD . "\r\nX: 1"), 'AA_APIKEY_ISDSTEST_NSZM_PASSWORD holds a line break or NUL - fix it in the configuration'],
            'login with NUL'             => [self::testAccount(login: self::LOGIN . "\0"), 'AA_APIKEY_ISDSTEST_NSZM_LOGIN holds a line break or NUL - fix it in the configuration'],
            'login with a colon'         => [self::testAccount(login: self::LOGIN . ':x'), $fix . 'The ISDS login is empty or contains a colon'],
            'short password'             => [self::testAccount(password: 'Pa55-wo'), $fix . 'An ISDS password is 8 to 64 letters, digits and ASCII signs'],
            'password not ascii'         => [self::testAccount(password: 'Heslíčko-never-shown'), $fix . 'An ISDS password is 8 to 64 letters, digits and ASCII signs'],
        ];
    }

    /** a production password does not belong in config.php at all - even a damaged or an empty one */
    #[DataProvider('productionPasswords')]
    public function test_bindings_productionNeverWithPassword(?string $password, bool $remove): void
    {
        $constants = self::productionAccount(password: $password);
        $binding   = self::binding($constants);

        $problems = ['The production account NSZM cannot be used: production ISDS lets an application in with a certificate only, and AA cannot log in with one yet'];
        if ($remove) {
            $problems[] = 'Remove AA_APIKEY_ISDS_NSZM_PASSWORD from config.php - AA never logs in to production ISDS with a password';
        }
        self::assertSame([Account::PRODUCTION, [self::SITE], self::CAMPAIGN, self::MESSAGE, $problems], [$binding->environment, $binding->sites, $binding->campaignSlice, $binding->messageSlice, $binding->problems]);
        self::assertRefused($constants, Account::PRODUCTION);
    }

    public static function productionPasswords(): array
    {
        return [
            'none'     => [null, false],
            'password' => [self::PROD_PASSWORD, true],
            'damaged'  => [self::PROD_PASSWORD . "\n", true],
            'empty'    => ['', true],
        ];
    }

    /** a pair reversed or mistyped in a template must not send with the account of another pair */
    #[DataProvider('sharedSlices')]
    public function test_bindings_sliceOfTwoAccounts(array $other, string $problem): void
    {
        $constants = self::testAccount('A') + $other;

        foreach (['A', 'B'] as $name) {
            self::assertSame([$problem], self::binding($constants, $name)->problems, $name);
        }
        self::assertRefused($constants);
        self::assertRefused($constants, '', self::SITE, self::MESSAGE, self::CAMPAIGN);
    }

    public static function sharedSlices(): array
    {
        $problem = 'AA_APIKEY_ISDSTEST_A_SLICES, AA_APIKEY_ISDSTEST_B_SLICES name the same slice - a slice belongs to one account';
        return [
            'the same pair'              => [self::testAccount('B', strtoupper(self::PAIR)), $problem],
            'the pair reversed'          => [self::testAccount('B', self::MESSAGE . ',' . self::CAMPAIGN), $problem],
            'one slice'                  => [self::testAccount('B', self::CAMPAIGN . ',' . self::MESSAGE2), $problem],
            'the same pair, another site' => [self::testAccount('B', site: self::SITE2), $problem],
        ];
    }

    public function test_forSlices_eachSiteAndPairItsAccount(): void
    {
        $constants = self::testAccount('A', login: 'login-a') + self::testAccount('B', self::PAIR2, 'login-b', site: self::SITE2);

        self::assertSame('login-a', Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $constants)->login);
        self::assertSame('login-b', Accounts::forSlices(self::SITE2, self::CAMPAIGN2, self::MESSAGE2, '', $constants)->login);
        self::assertRefused($constants, '', self::SITE2, self::CAMPAIGN, self::MESSAGE);
        // the slices of B named on the site of A
        self::assertRefused($constants, Account::TEST, self::SITE, self::CAMPAIGN2, self::MESSAGE2);
    }

    /** the vendor and the account come from the constant names as Store spells them, nothing else */
    public function test_strays_whatIsNoAccount(): void
    {
        $constants = [
            'AA_APIKEY_ISDS_TEST_NSZM_SLICES'   => self::PAIR,
            'AA_APIKEY_ISDS_TEST_NSZM_PASSWORD' => self::PASSWORD,
            'AA_APIKEY_ISDS_REGISTRY'           => 'x',
            'AA_APIKEY_ISDSTEST_nszm_SLICES'    => self::PAIR,
            'AA_APIKEY_ISDSTEST_NSZM_PASWORD'   => self::PASSWORD,
            'AA_APIKEY_ISDSTEST'                => self::PASSWORD,
            'AA_APIKEY_ISDSTEST_LOGIN'          => self::LOGIN,
            'AA_APIKEY_IsdsTest_NSZM_LOGIN'     => self::LOGIN,
            'aa_apikey_isdstest_NSZM_SITE'      => self::SITE,
            'AA_APIKEY_ISDSTESTX_NSZM_SLICES'   => self::PAIR,
            'AA_APIKEY_CSU'                     => 'x',
        ];

        self::assertSame([], Accounts::bindings($constants));
        self::assertSame([
            'AA_APIKEY_ISDS_TEST_NSZM_SLICES', 'AA_APIKEY_ISDS_TEST_NSZM_PASSWORD', 'AA_APIKEY_ISDS_REGISTRY',
            'AA_APIKEY_ISDSTEST_nszm_SLICES', 'AA_APIKEY_ISDSTEST_NSZM_PASWORD', 'AA_APIKEY_ISDSTEST', 'AA_APIKEY_ISDSTEST_LOGIN',
            'AA_APIKEY_IsdsTest_NSZM_LOGIN', 'aa_apikey_isdstest_NSZM_SITE',
        ], Accounts::strays($constants));
        self::assertRefused($constants);
    }

    public function test_bindings_listWithoutKeys(): void
    {
        $constants = self::testAccount('NSZM') + self::testAccount('ABC', self::PAIR2, password: null, site: self::SITE . ',' . self::SITE2)
            + self::productionAccount('NSZM', self::PAIR2, self::SITE2) + ['AA_APIKEY_ISDSTEST_LONELY_LOGIN' => self::LOGIN, 'DB_PASSWORD' => self::PROD_PASSWORD];

        $bindings = Accounts::bindings($constants);
        $rows     = array_map(static fn(Binding $b): array => [$b->environment, $b->name, $b->sites, $b->campaignSlice, $b->messageSlice, $b->usable()], $bindings);
        self::assertSame([
            [Account::PRODUCTION, 'NSZM', [self::SITE2], self::CAMPAIGN2, self::MESSAGE2, false],
            [Account::TEST, 'ABC', [self::SITE, self::SITE2], self::CAMPAIGN2, self::MESSAGE2, false],
            [Account::TEST, 'LONELY', [], '', '', false],
            [Account::TEST, 'NSZM', [self::SITE], self::CAMPAIGN, self::MESSAGE, true],
        ], $rows);
        self::assertSame([
            'Set AA_APIKEY_ISDSTEST_LONELY_SITE in config.php to the id of the site the account sends for - more sites separated by commas',
            'Set AA_APIKEY_ISDSTEST_LONELY_SLICES in config.php to the ids of the campaign slice and the message slice the account sends for, separated by a comma',
            'Set AA_APIKEY_ISDSTEST_LONELY_PASSWORD in config.php - the account logs in with a name and a password',
        ], $bindings[2]->problems);

        $dumps = print_r($bindings, true) . var_export($bindings, true) . json_encode($bindings) . serialize($bindings);
        foreach ([self::PASSWORD, self::PROD_PASSWORD, self::LOGIN] as $value) {
            self::assertStringNotContainsString($value, $dumps);
        }
    }

    #[DataProvider('notArguments')]
    public function test_forSlices_refusesArguments(string $site, string $campaign, string $message, string $environment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Accounts::forSlices($site, $campaign, $message, $environment, self::testAccount());
    }

    public static function notArguments(): array
    {
        return [
            'short campaign slice' => [self::SITE, substr(self::CAMPAIGN, 1), self::MESSAGE, ''],
            'empty message slice'  => [self::SITE, self::CAMPAIGN, '', ''],
            'packed id'            => [self::SITE, self::CAMPAIGN, (string) hex2bin(self::MESSAGE), ''],
            'empty site'           => ['', self::CAMPAIGN, self::MESSAGE, ''],
            'site with a space'    => [self::SITE . ' ', self::CAMPAIGN, self::MESSAGE, ''],
            'capital environment'  => [self::SITE, self::CAMPAIGN, self::MESSAGE, 'TEST'],
            'other environment'    => [self::SITE, self::CAMPAIGN, self::MESSAGE, 'staging'],
        ];
    }

    /** config.php holds the database password too - a trace with the arguments must show none of it */
    public function test_forSlices_keysNotInTraces(): void
    {
        $dbPassword = 'Db-Pa55-never-in-a-trace';
        $realKey    = 'Real-Pa55-never-in-a-trace';
        if (!defined('AA_APIKEY_ISDSTEST_ACCOUNTSTRACE_PASSWORD')) {
            define('AA_APIKEY_ISDSTEST_ACCOUNTSTRACE_PASSWORD', $realKey);
        }
        $constants = self::testAccount() + ['DB_PASSWORD' => $dbPassword];
        $calls     = [
            'refused, constants given' => static fn() => Accounts::forSlices(self::SITE2, self::CAMPAIGN, self::MESSAGE, '', $constants),
            'refused, defined ones'    => static fn() => Accounts::forSlices(self::SITE2, self::CAMPAIGN, self::MESSAGE),
            'environment, given'       => static fn() => Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, 'staging', $constants),
            'environment, defined'     => static fn() => Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, 'staging'),
        ];

        $ignore = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            foreach ($calls as $case => $call) {
                try {
                    $call();
                    self::fail("$case: an exception expected");
                } catch (\RuntimeException|\InvalidArgumentException $e) {
                    $traces = '';
                    for ($t = $e; !is_null($t); $t = $t->getPrevious()) {
                        // the frames of AA - PHPUnit's hold the data of other tests
                        $frames = array_filter($t->getTrace(), static fn(array $f): bool => str_starts_with($f['class'] ?? '', 'AA\\'));
                        ob_start();
                        var_dump($frames);
                        $traces .= print_r($frames, true) . ob_get_clean();
                    }
                    self::assertStringContainsString(self::CAMPAIGN, $traces, "$case: the trace keeps arguments");
                    foreach ([$dbPassword, $realKey, self::PASSWORD, self::LOGIN] as $value) {
                        self::assertStringNotContainsString($value, $traces, $case);
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignore);
        }
    }

    /** the one test with a real account */
    public function test_forSlices_definedConstants(): void
    {
        $site     = '51de00000000000000000000000000f0';
        $campaign = 'cafe00000000000000000000000000f1';
        $message  = 'beef00000000000000000000000000f2';
        foreach (self::testAccount('ACCOUNTSTESTREAL', strtoupper("$campaign,$message"), 'real-login', site: $site) as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }

        self::assertSame('real-login', Accounts::forSlices($site, $campaign, $message)->login);
        $names = array_map(static fn(Binding $b): string => $b->name, Accounts::bindings());
        self::assertContains('ACCOUNTSTESTREAL', $names);
        self::assertNotContains('AA_APIKEY_ISDSTEST_ACCOUNTSTESTREAL_SLICES', Accounts::strays());
    }
}
