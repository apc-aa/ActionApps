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
    /** an id the way UUIDs are written: 8-4-4-4-12 */
    private static function dashed(string $id): string
    {
        return implode('-', [substr($id, 0, 8), substr($id, 8, 4), substr($id, 12, 4), substr($id, 16, 4), substr($id, 20)]);
    }

    private static function productionAccount(string $name = 'NSZM', ?string $slices = self::PAIR, ?string $site = self::SITE, ?string $password = self::PROD_PASSWORD): array
    {
        return array_filter([
            "AA_APIKEY_ISDS_{$name}_SITE"     => $site,
            "AA_APIKEY_ISDS_{$name}_SLICES"   => $slices,
            "AA_APIKEY_ISDS_{$name}_LOGIN"    => self::LOGIN,
            "AA_APIKEY_ISDS_{$name}_PASSWORD" => $password,
        ], static fn(?string $v): bool => !is_null($v));
    }

    /**
     * @param array<string, ?string> $kinds kind => value of the account NSZM; null leaves one out
     * @return array<string, string> the account with its site and slices
     */
    private static function withKinds(string $environment, array $kinds): array
    {
        $prefix    = Accounts::prefix($environment, 'NSZM');
        $constants = ["{$prefix}_SITE" => self::SITE, "{$prefix}_SLICES" => self::PAIR];
        foreach (array_filter($kinds, static fn(?string $v): bool => !is_null($v)) as $kind => $value) {
            $constants["{$prefix}_$kind"] = $value;
        }

        return $constants;
    }

    /** the only refusal a template author gets: about the site and slices asked for, nothing about the accounts there are */
    private static function refusalText(string $site = self::SITE, string $campaign = self::CAMPAIGN, string $message = self::MESSAGE): string
    {
        return "No usable ISDS account in include/config.php is bound to this site and these slices. If a test account is bound and you mean it, ask for the test environment. To bind a test account, set AA_APIKEY_ISDSTEST_<ACCOUNT>_SITE to '$site', AA_APIKEY_ISDSTEST_<ACCOUNT>_SLICES to '$campaign,$message' (the campaign slice first), AA_APIKEY_ISDSTEST_<ACCOUNT>_LOGIN and AA_APIKEY_ISDSTEST_<ACCOUNT>_PASSWORD; a production account logs in with a certificate (AA_APIKEY_ISDS_<ACCOUNT>_CERT) instead. The State Check lists the accounts and their problems";
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
     * Any production account, even a broken one, keeps '' from test - unless the site and slice ids
     * readable in it are all of others. A forgotten SITE and SLICES proves nothing.
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
            'a login, no site, no slices'     => [self::productionAccount(slices: null, site: null), true],
            'a certificate only'              => [['AA_APIKEY_ISDS_NSZM_CERT' => '/etc/aa/nszm.pem'], true],
            'a certificate, other slices'     => [['AA_APIKEY_ISDS_NSZM_CERT' => '/etc/aa/nszm.pem', 'AA_APIKEY_ISDS_NSZM_SLICES' => self::PAIR2], false],
            'test written as ISDS_TEST'       => [['AA_APIKEY_ISDS_TEST_LOGIN' => self::LOGIN, 'AA_APIKEY_ISDS_TEST_PASSWORD' => self::PROD_PASSWORD], true],
            'a vendor of two words'           => [['AA_APIKEY_ISDS_REGISTRY_LOGIN' => 'registry-login'], true],
            // a list broken by one bad part still names its slices
            'our pair and a third slice'      => [self::productionAccount(slices: self::PAIR . ',' . self::CAMPAIGN2, site: self::SITE2), true],
            'our campaign slice alone'        => [self::productionAccount(slices: self::CAMPAIGN, site: self::SITE2), true],
            'our message slice with a typo'   => [self::productionAccount(slices: self::PAIR . 'x', site: self::SITE2), true],
            'our pair by a semicolon'         => [self::productionAccount(slices: self::CAMPAIGN . ';' . self::MESSAGE, site: self::SITE2), true],
            'our pair by a space'             => [self::productionAccount(slices: self::CAMPAIGN . ' ' . self::MESSAGE, site: self::SITE2), true],
            'our site and a bad part'         => [self::productionAccount(slices: self::PAIR2, site: self::SITE . ',x'), true],
            // a run of hex digits is read whole: no id of others is cut out of a typo
            'a digit before our site'         => [self::productionAccount(slices: null, site: 'a' . self::SITE), true],
            'a digit before each slice'       => [self::productionAccount(slices: '0' . self::CAMPAIGN . ',0' . self::MESSAGE, site: null), true],
            'our pair glued, a digit before'  => [self::productionAccount(slices: 'f' . self::MESSAGE . self::CAMPAIGN, site: self::SITE2), true],
            'our pair glued'                  => [self::productionAccount(slices: self::CAMPAIGN . self::MESSAGE, site: self::SITE2), true],
            'our ids in capitals'             => [self::productionAccount(slices: strtoupper(self::PAIR), site: self::SITE2), true],
            'ids of others glued'             => [self::productionAccount(slices: self::CAMPAIGN2 . self::MESSAGE2, site: self::SITE2), false],
            'our slice glued after another'   => [self::productionAccount(slices: self::CAMPAIGN2 . self::MESSAGE, site: self::SITE2), true],
            'a digit before a slice of others' => [self::productionAccount(slices: '1' . self::CAMPAIGN2 . ',' . self::MESSAGE2, site: self::SITE2), true],
            // a word that is no id may be one of ours with a typo; short words are not
            'our site with dashes'            => [self::productionAccount(slices: self::PAIR2, site: self::dashed(self::SITE)), true],
            'a site of others with dashes'    => [self::productionAccount(slices: self::PAIR2, site: self::dashed(self::SITE2)), true],
            'our slice a digit short'         => [self::productionAccount(slices: substr(self::CAMPAIGN, 0, 31) . ',' . self::MESSAGE2, site: self::SITE2), true],
            'our slice with o for 0'          => [self::productionAccount(slices: str_replace('0', 'o', self::CAMPAIGN) . ',' . self::MESSAGE2, site: self::SITE2), true],
            'a word of 16 letters'            => [self::productionAccount(slices: self::PAIR2 . ',abcdefghijklmnop', site: self::SITE2), true],
            'a word of 15 letters'            => [self::productionAccount(slices: self::PAIR2 . ',abcdefghijklmno', site: self::SITE2), false],
            'short hex words beside others'   => [self::productionAccount(slices: self::PAIR2, site: self::SITE2 . ' # cafe beef dead'), false],
        ];
    }

    /** bound to nothing, yet State Check lists it */
    public function test_bindings_productionBoundToNothing(): void
    {
        $binding = self::binding(['AA_APIKEY_ISDS_TEST_LOGIN' => self::LOGIN, 'AA_APIKEY_ISDS_TEST_PASSWORD' => self::PROD_PASSWORD], 'TEST');

        self::assertSame([Account::PRODUCTION, [], '', ''], [$binding->environment, $binding->sites, $binding->campaignSlice, $binding->messageSlice]);
        self::assertSame([
            'Set AA_APIKEY_ISDS_TEST_SITE in config.php to the id of the site the account sends for - more sites separated by commas',
            'Set AA_APIKEY_ISDS_TEST_SLICES in config.php to the ids of the campaign slice and the message slice the account sends for, separated by a comma',
            'Set AA_APIKEY_ISDS_TEST_CERT in config.php to the file of the certificate - production ISDS lets an application in with a certificate only',
            'Remove AA_APIKEY_ISDS_TEST_LOGIN and AA_APIKEY_ISDS_TEST_PASSWORD from config.php - AA never logs in to production ISDS with a name and a password',
        ], $binding->problems);
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
    public function test_bindings_productionNeverWithPassword(?string $password, string $remove): void
    {
        $constants = self::productionAccount(password: $password);
        $binding   = self::binding($constants);

        $problems = [
            'Set AA_APIKEY_ISDS_NSZM_CERT in config.php to the file of the certificate - production ISDS lets an application in with a certificate only',
            "Remove $remove from config.php - AA never logs in to production ISDS with a name and a password",
        ];
        self::assertSame([Account::PRODUCTION, [self::SITE], self::CAMPAIGN, self::MESSAGE, $problems], [$binding->environment, $binding->sites, $binding->campaignSlice, $binding->messageSlice, $binding->problems]);
        self::assertRefused($constants, Account::PRODUCTION);
    }

    public static function productionPasswords(): array
    {
        $both = 'AA_APIKEY_ISDS_NSZM_LOGIN and AA_APIKEY_ISDS_NSZM_PASSWORD';
        return [
            'login only' => [null, 'AA_APIKEY_ISDS_NSZM_LOGIN'],
            'password'   => [self::PROD_PASSWORD, $both],
            'damaged'    => [self::PROD_PASSWORD . "\n", $both],
            'empty'      => ['', $both],
        ];
    }

    /**
     * What is set decides the login: BOX a hosted filing service, CERT a system certificate, else a name
     * and a password - in test only. A mix is a problem that says what to remove or add, never a guess.
     * @param array<string, string> $set    kind => value of the account NSZM, besides its site and slices
     * @param list<string>          $problems
     */
    #[DataProvider('logins')]
    public function test_bindings_kindOfLogin(string $environment, array $set, string $kind, array $problems): void
    {
        $constants = self::withKinds($environment, $set);
        $binding   = self::binding($constants);

        self::assertSame([$kind, $problems], [$binding->kind, $binding->problems]);
        if ($problems) {
            self::assertRefused($constants, $environment);
        } else {
            $account = Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, $environment, $constants);
            self::assertSame([$environment, $kind], [$account->environment, $account->kind]);
        }
    }

    public static function logins(): array
    {
        $pem  = '/etc/aa/isds.pem';
        $mix  = ' from config.php - an account with a certificate logs in without a name and a password';
        $cert = static fn(string $vendor, string $of): string => "Set AA_APIKEY_{$vendor}_NSZM_CERT in config.php to the PEM file of the certificate $of - a full path outside the directory of AA";
        return [
            'name and password, test'       => [Account::TEST, [Accounts::LOGIN => self::LOGIN, Accounts::PASSWORD => self::PASSWORD], Account::BASIC, []],
            'name and password, production' => [Account::PRODUCTION, [Accounts::LOGIN => self::LOGIN, Accounts::PASSWORD => self::PROD_PASSWORD], Account::BASIC, [
                'Set AA_APIKEY_ISDS_NSZM_CERT in config.php to the file of the certificate - production ISDS lets an application in with a certificate only',
                'Remove AA_APIKEY_ISDS_NSZM_LOGIN and AA_APIKEY_ISDS_NSZM_PASSWORD from config.php - AA never logs in to production ISDS with a name and a password',
            ]],
            'certificate, test'             => [Account::TEST, [Accounts::CERT => $pem], Account::CERT, []],
            'certificate, production'       => [Account::PRODUCTION, [Accounts::CERT => $pem], Account::CERT, []],
            'with its key and passphrase'   => [Account::PRODUCTION, [Accounts::CERT => $pem, Accounts::KEY => '/etc/aa/isds.key', Accounts::PASSPHRASE => 'Pass-phrase'], Account::CERT, []],
            'hosted'                        => [Account::PRODUCTION, [Accounts::BOX => ' KV62BQF ', Accounts::CERT => $pem], Account::HOSTED, []],
            'hosted, test'                  => [Account::TEST, [Accounts::BOX => 'dj7j6mk', Accounts::CERT => $pem], Account::HOSTED, []],
            'a box without certificate'     => [Account::PRODUCTION, [Accounts::BOX => 'kv62bqf'], Account::HOSTED, [$cert('ISDS', 'of the hosting service')]],
            'an empty certificate'          => [Account::PRODUCTION, [Accounts::CERT => ''], Account::CERT, [$cert('ISDS', 'ISDS registered for the data box')]],
            'an empty box'                  => [Account::PRODUCTION, [Accounts::BOX => '', Accounts::CERT => $pem], Account::HOSTED, ['Set AA_APIKEY_ISDS_NSZM_BOX in config.php to the id of the data box the account sends from']],
            'not a box'                     => [Account::PRODUCTION, [Accounts::BOX => 'kv62bq', Accounts::CERT => $pem], Account::HOSTED, ['AA_APIKEY_ISDS_NSZM_BOX is not the id of a data box - 7 letters or digits']],
            'damaged certificate'           => [Account::PRODUCTION, [Accounts::CERT => "$pem\n"], Account::CERT, ['AA_APIKEY_ISDS_NSZM_CERT holds a line break or NUL - fix it in the configuration']],
            'certificate and a login'       => [Account::TEST, [Accounts::CERT => $pem, Accounts::LOGIN => self::LOGIN], Account::CERT, ['Remove AA_APIKEY_ISDSTEST_NSZM_LOGIN' . $mix]],
            'certificate and a password'    => [Account::PRODUCTION, [Accounts::CERT => $pem, Accounts::PASSWORD => self::PROD_PASSWORD], Account::CERT, ['Remove AA_APIKEY_ISDS_NSZM_PASSWORD' . $mix]],
            'hosted, name and password'     => [Account::TEST, [Accounts::BOX => 'kv62bqf', Accounts::CERT => $pem, Accounts::LOGIN => self::LOGIN, Accounts::PASSWORD => ''], Account::HOSTED, ['Remove AA_APIKEY_ISDSTEST_NSZM_LOGIN and AA_APIKEY_ISDSTEST_NSZM_PASSWORD' . $mix]],
            'a key without certificate'     => [Account::TEST, [Accounts::LOGIN => self::LOGIN, Accounts::PASSWORD => self::PASSWORD, Accounts::KEY => '/etc/aa/isds.key'], Account::BASIC, [
                'Set AA_APIKEY_ISDSTEST_NSZM_CERT in config.php, or remove AA_APIKEY_ISDSTEST_NSZM_KEY - they belong to a certificate',
            ]],
            'a passphrase alone'            => [Account::TEST, [Accounts::PASSPHRASE => 'Pass-phrase'], Account::BASIC, [
                'Set AA_APIKEY_ISDSTEST_NSZM_CERT in config.php, or remove AA_APIKEY_ISDSTEST_NSZM_PASSPHRASE - they belong to a certificate',
                'Set AA_APIKEY_ISDSTEST_NSZM_LOGIN and AA_APIKEY_ISDSTEST_NSZM_PASSWORD in config.php - the account logs in with a name and a password',
            ]],
        ];
    }

    // production is meant where it is bound, the files as config.php names them
    public function test_forSlices_certificateAccount(): void
    {
        $constants = self::testAccount() + self::withKinds(Account::PRODUCTION, [Accounts::CERT => ' nszm.pem ', Accounts::KEY => ' /etc/aa-isds/nszm.key', Accounts::PASSPHRASE => 'Pass-phrase-never-shown'])
            + ['AA_BASE_PATH' => '/srv/aa/'];

        $account = Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $constants);

        self::assertSame([Account::PRODUCTION, Account::CERT, 'https://ws1c.datovka.gov.cz/cert/DS/dz', []], [$account->environment, $account->kind, $account->url('dz'), $account->headers()]);
        self::assertSame(['/srv/aa/config/certs/nszm.pem', '/etc/aa-isds/nszm.key', 'Pass-phrase-never-shown'], [$account->certificate?->certificate, $account->certificate?->key, $account->certificate?->passphrase()]);
        self::assertSame(self::LOGIN, Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, Account::TEST, $constants)->login, 'test asked, test given');
    }

    // the directory PHP happens to run in is no base for a relative path
    public function test_bindings_relativePathWithoutBase(): void
    {
        $binding = self::binding(self::withKinds(Account::PRODUCTION, [Accounts::CERT => 'nszm.pem']));

        self::assertSame(['Give AA_APIKEY_ISDS_NSZM_CERT in config.php a full path - AA_BASE_PATH, where a relative one starts, is not defined'], $binding->problems);
    }

    // the hosted filing service names the box it sends from, with an empty password
    public function test_forSlices_hostedAccount(): void
    {
        $account = Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', self::withKinds(Account::PRODUCTION, [Accounts::CERT => '/etc/aa/ecn.pem', Accounts::BOX => 'KV62BQF']));

        self::assertSame([Account::HOSTED, 'kv62bqf', 'https://ws1c.datovka.gov.cz/hspis/DS/dz'], [$account->kind, $account->login, $account->url('dz')]);
        self::assertSame(['Authorization' => 'Basic ' . base64_encode('kv62bqf:')], $account->headers());
        self::assertSame('/etc/aa/ecn.pem', $account->certificate?->keyFile());
    }

    // State Check checks the files of an account it cannot use yet
    public function test_certificate_ofAnyBinding(): void
    {
        $constants = self::withKinds(Account::PRODUCTION, [Accounts::CERT => '/etc/aa/nszm.pem']);
        unset($constants['AA_APIKEY_ISDS_NSZM_SITE']);

        self::assertFalse(self::binding($constants)->usable());
        self::assertSame('/etc/aa/nszm.pem', Accounts::certificate(self::binding($constants), $constants)?->certificate);
        self::assertNull(Accounts::certificate(self::binding(self::testAccount()), self::testAccount()));
    }

    /**
     * A production constant AA does not read - a typo in the name of the account or of the constant -
     * keeps '' from test, unless the ids in it, or in the account of its name, are all of others.
     * @param array<string, string> $production
     */
    #[DataProvider('productionStrays')]
    public function test_forSlices_productionStrayNeverTestInItsPlace(array $production, bool $refused): void
    {
        $constants = self::testAccount() + $production;

        if ($refused) {
            self::assertRefused($constants);
        } else {
            self::assertSame(Account::TEST, Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $constants)->environment);
        }
        self::assertSame(self::LOGIN, Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, Account::TEST, $constants)->login, 'asking for test is always fine');
    }

    public static function productionStrays(): array
    {
        $elsewhere = self::withKinds(Account::PRODUCTION, [Accounts::CERT => '/etc/aa/nszm.pem']);
        $elsewhere = ['AA_APIKEY_ISDS_NSZM_SITE' => self::SITE2, 'AA_APIKEY_ISDS_NSZM_SLICES' => self::PAIR2] + $elsewhere;
        return [
            'the account in small letters'        => [['AA_APIKEY_ISDS_Nszm_SLICES' => self::PAIR, 'AA_APIKEY_ISDS_Nszm_CERT' => 'nszm.pem'], true],
            'its slices are others'               => [['AA_APIKEY_ISDS_Nszm_SLICES' => self::PAIR2, 'AA_APIKEY_ISDS_Nszm_CERT' => 'nszm.pem'], false],
            'SITES for SITE'                      => [['AA_APIKEY_ISDS_NSZM_SITES' => self::SITE, 'AA_APIKEY_ISDS_NSZM_CERT' => 'nszm.pem'], true],
            'SITES of another site, no slices'    => [['AA_APIKEY_ISDS_NSZM_SITES' => self::SITE2, 'AA_APIKEY_ISDS_NSZM_CERT' => 'nszm.pem'], false],
            'only a certificate'                  => [['AA_APIKEY_ISDS_Nszm_CERT' => 'nszm.pem'], true],
            'a certificate, the site of others'   => [['AA_APIKEY_ISDS_Nszm_CERT' => 'nszm.pem', 'AA_APIKEY_ISDS_nszm_SITE' => self::SITE2], false],
            'a typo, the account bound elsewhere' => [['AA_APIKEY_ISDS_NSZM_CRET' => 'nszm.pem'] + $elsewhere, false],
            'a typo, an account of no ids'        => [['AA_APIKEY_ISDS_NSZM_CRET' => 'nszm.pem', 'AA_APIKEY_ISDS_NSZM_CERT' => 'nszm.pem'], true],
            'no account at all'                   => [['AA_APIKEY_ISDS_LOGIN' => self::LOGIN], true],
            'the vendor alone'                    => [['AA_APIKEY_ISDS' => 'x'], true],
            'damaged ids of others'               => [['AA_APIKEY_ISDS_Nszm_SLICES' => self::PAIR2 . "\n"], false],
            'ids of others and a bad part'        => [['AA_APIKEY_ISDS_Nszm_SLICES' => self::PAIR2 . ',x'], false],
            'our pair and a bad part'             => [['AA_APIKEY_ISDS_Nszm_SLICES' => self::PAIR . ',x', 'AA_APIKEY_ISDS_Nszm_SITE' => self::SITE2], true],
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
    public function test_account_ofAUsableBinding(): void
    {
        $constants = self::testAccount();

        $account = Accounts::account(self::binding($constants), $constants);

        self::assertSame([Account::TEST, self::LOGIN], [$account->environment, $account->login]);
        self::assertSame(['Authorization' => 'Basic ' . base64_encode(self::LOGIN . ':' . self::PASSWORD)], $account->headers());
    }

    // a binding without its site or slices is not one to send with
    public function test_account_refusesAnUnusableBinding(): void
    {
        $constants = self::testAccount(site: null);
        try {
            Accounts::account(self::binding($constants), $constants);
            self::fail('ConfigException expected');
        } catch (ConfigException $e) {
            self::assertStringContainsString('AA_APIKEY_ISDSTEST_NSZM_SITE', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    public function test_constants_namesOfAnAccount(): void
    {
        self::assertSame('AA_APIKEY_ISDSTEST_NSZM', Accounts::prefix(Account::TEST, 'NSZM'));
        self::assertSame('AA_APIKEY_ISDS_', Accounts::prefix(Account::PRODUCTION, ''));
        self::assertSame('AA_APIKEY_ISDS_NSZM_LOGIN', Accounts::constant(Account::PRODUCTION, 'NSZM', Accounts::LOGIN));
        self::assertSame(['AA_APIKEY_ISDSTEST_<ACCOUNT>_SITE', 'AA_APIKEY_ISDSTEST_<ACCOUNT>_SLICES', 'AA_APIKEY_ISDSTEST_<ACCOUNT>_LOGIN', 'AA_APIKEY_ISDSTEST_<ACCOUNT>_PASSWORD'], Accounts::constants(Account::TEST, Accounts::PLACEHOLDER, Account::BASIC));
        self::assertSame(['AA_APIKEY_ISDS_NSZM_SITE', 'AA_APIKEY_ISDS_NSZM_SLICES', 'AA_APIKEY_ISDS_NSZM_BOX', 'AA_APIKEY_ISDS_NSZM_CERT', 'AA_APIKEY_ISDS_NSZM_KEY', 'AA_APIKEY_ISDS_NSZM_PASSPHRASE'], Accounts::constants(Account::PRODUCTION, 'NSZM', Account::HOSTED));
        self::assertCount(8, Accounts::constants(Account::TEST, Accounts::PLACEHOLDER), 'of any login');
    }

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
