<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Account;
use AA\Isds\Accounts;
use AA\Isds\Binding;
use AA\Isds\ConfigException;
use AA\Isds\DatovkaConf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The accounts are passed in as DATOVKA_CONF['accounts'] - DatovkaConf::fromArray() reads them as it reads
 * the file. Only test_forSlices_fromAFile reads a file, in a process of its own: PHP defines the constant once.
 */
final class AccountsTest extends TestCase
{
    private const SITE      = '51de0000000000000000000000000001';
    private const SITE2     = '51de0000000000000000000000000002';
    private const CAMPAIGN  = 'cafe0000000000000000000000000001';
    private const MESSAGE   = 'beef0000000000000000000000000002';
    private const CAMPAIGN2 = 'cafe0000000000000000000000000003';
    private const MESSAGE2  = 'beef0000000000000000000000000004';
    private const PAIR      = [self::CAMPAIGN, self::MESSAGE];
    private const PAIR2     = [self::CAMPAIGN2, self::MESSAGE2];

    private const LOGIN         = 'nszm01login';
    private const PASSWORD      = 'Test-Pa55-never-shown';
    private const PROD_PASSWORD = 'Prod-Pa55-never-shown';

    /**
     * @param ?list<string> $slices
     * @param ?list<string> $sites
     * @return array<string, array<string, mixed>> a test account, as DATOVKA_CONF['accounts']; null leaves a key out
     */
    private static function testAccount(string $name = 'NSZM', ?array $slices = self::PAIR, ?string $login = self::LOGIN, ?string $password = self::PASSWORD, ?array $sites = [self::SITE]): array
    {
        return [$name => self::filled(['environment' => Account::TEST, 'auth' => Account::BASIC, 'sites' => $sites, 'slices' => $slices, 'login' => $login, 'password' => $password])];
    }

    /**
     * A production account as an admin might try: with a name and a password. sites and slices as written,
     * broken ones too.
     * @return array<string, array<string, mixed>>
     */
    private static function productionAccount(string $name = 'PROD', mixed $slices = self::PAIR, mixed $sites = [self::SITE], ?string $password = self::PROD_PASSWORD): array
    {
        return [$name => self::filled(['environment' => Account::PRODUCTION, 'auth' => Account::BASIC, 'sites' => $sites, 'slices' => $slices, 'login' => self::LOGIN, 'password' => $password])];
    }

    /**
     * @param string                $auth '' leaves auth out
     * @param array<string, ?string> $keys the keys of the login; null leaves one out
     * @return array<string, array<string, mixed>> the account, bound to SITE and PAIR
     */
    private static function withAuth(string $environment, string $auth, array $keys, string $name = 'NSZM'): array
    {
        return [$name => self::filled(['environment' => $environment, 'auth' => ($auth === '') ? null : $auth, 'sites' => [self::SITE], 'slices' => self::PAIR] + $keys)];
    }

    /**
     * @param array<string, mixed> $keys
     * @return array<string, mixed> without the keys of null
     */
    private static function filled(array $keys): array
    {
        return array_filter($keys, static fn(mixed $value): bool => !is_null($value));
    }

    /** @param array<array-key, mixed> $accounts */
    private static function conf(array $accounts): DatovkaConf
    {
        return DatovkaConf::fromArray([DatovkaConf::ACCOUNTS => $accounts]);
    }

    /** an id the way UUIDs are written: 8-4-4-4-12 */
    private static function dashed(string $id): string
    {
        return implode('-', [substr($id, 0, 8), substr($id, 8, 4), substr($id, 12, 4), substr($id, 16, 4), substr($id, 20)]);
    }

    /** where the file sets a key of the account NSZM */
    private static function key(string $key): string
    {
        return "DATOVKA_CONF['accounts']['NSZM']['$key']";
    }

    /** the only refusal a template author gets: about the site and slices asked for, nothing about the accounts there are */
    private static function refusalText(string $site = self::SITE, string $campaign = self::CAMPAIGN, string $message = self::MESSAGE): string
    {
        return "No usable ISDS account in misc/datovka/datovka_conf.php is bound to this site and these slices. If a test account is bound and you mean it, ask for the test environment. To bind a test account, add to DATOVKA_CONF['accounts'] an account with 'environment' => 'test', 'auth' => 'basic', 'sites' => ['$site'], 'slices' => ['$campaign', '$message'] (the campaign slice first), 'login' and 'password'; a production account logs in with a certificate ('auth' => 'cert' or 'hosted') instead. The State Check lists the accounts and their problems";
    }

    /**
     * forSlices() and binding() refuse alike
     * @param array<array-key, mixed> $accounts
     */
    private static function assertRefused(array $accounts, string $environment = '', string $site = self::SITE, string $campaign = self::CAMPAIGN, string $message = self::MESSAGE): void
    {
        $conf  = self::conf($accounts);
        $calls = [
            'forSlices' => static fn() => Accounts::forSlices($site, $campaign, $message, $environment, $conf),
            'binding'   => static fn() => Accounts::binding($site, $campaign, $message, $environment, $conf),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                self::fail("$name: ConfigException expected");
            } catch (ConfigException $e) {
                self::assertSame(self::refusalText(strtolower($site), strtolower($campaign), strtolower($message)), $e->getMessage(), $name);
                self::assertNull($e->getPrevious(), $name);
            }
        }
    }

    /** @param array<array-key, mixed> $accounts */
    private static function forSlices(array $accounts, string $environment = '', string $site = self::SITE, string $campaign = self::CAMPAIGN, string $message = self::MESSAGE): Account
    {
        return Accounts::forSlices($site, $campaign, $message, $environment, self::conf($accounts));
    }

    /** @param array<array-key, mixed> $accounts */
    private static function binding(array $accounts, string $name = 'NSZM'): Binding
    {
        foreach (Accounts::bindings(self::conf($accounts)) as $binding) {
            if ($binding->name === $name) {
                return $binding;
            }
        }
        self::fail("no binding $name");
    }

    public function test_forSlices_testAccountOfTheSiteAndPair(): void
    {
        $account = self::forSlices(self::testAccount());

        self::assertSame(Account::TEST, $account->environment);
        self::assertSame(self::LOGIN, $account->login);
        self::assertSame('https://ws1.datovka-test.gov.cz/DS/dz', $account->url('dz'));
        self::assertSame(['Authorization' => 'Basic ' . base64_encode(self::LOGIN . ':' . self::PASSWORD)], $account->headers());
    }

    /**
     * Production only where the file binds it (and AA cannot log in there with a password), never quietly
     * test in its place: a run sent to the test ISDS reaches nobody.
     * @param list<string> $bound environments with an account bound to the site and pair
     */
    #[DataProvider('environments')]
    public function test_forSlices_environment(array $bound, string $asked, bool $sends): void
    {
        $accounts = [];
        if (in_array(Account::TEST, $bound, true)) {
            $accounts += self::testAccount();
        }
        if (in_array(Account::PRODUCTION, $bound, true)) {
            $accounts += self::productionAccount();
        }

        if ($sends) {
            self::assertSame(Account::TEST, self::forSlices($accounts, $asked)->environment);
        } else {
            self::assertRefused($accounts, $asked);
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
     * readable in its sites, slices and unknown keys are all of others. No sites and slices prove nothing.
     * @param array<array-key, mixed> $production
     */
    #[DataProvider('productionNearby')]
    public function test_forSlices_noneAskedNeverTestInPlaceOfProduction(array $production, bool $refused): void
    {
        $accounts = self::testAccount() + $production;

        if ($refused) {
            self::assertRefused($accounts);
        } else {
            self::assertSame(Account::TEST, self::forSlices($accounts)->environment);
        }
        self::assertSame(self::LOGIN, self::forSlices($accounts, Account::TEST)->login, 'asking for test is always fine');
    }

    public static function productionNearby(): array
    {
        $others = ['environment' => Account::PRODUCTION, 'auth' => Account::HOSTED, 'sites' => [self::SITE2], 'slices' => self::PAIR2, 'box' => 'kv62bqf'];
        return [
            'the pair reversed, another site' => [self::productionAccount(slices: [self::MESSAGE, self::CAMPAIGN], sites: [self::SITE2]), true],
            'a typo in the slices'            => [self::productionAccount(slices: [self::CAMPAIGN . ';' . self::MESSAGE]), true],
            'one slice of the pair'           => [self::productionAccount(slices: [self::CAMPAIGN, self::MESSAGE2], sites: [self::SITE2]), true],
            'this site, other slices'         => [self::productionAccount(slices: self::PAIR2), true],
            'nothing readable'                => [self::productionAccount(slices: null, sites: ['x']), true],
            'damaged slices, no site'         => [self::productionAccount(slices: [self::CAMPAIGN, self::MESSAGE . "\n"], sites: null), true],
            'another site and slices'         => [self::productionAccount(slices: self::PAIR2, sites: [self::SITE2]), false],
            'empty site and slices'           => [self::productionAccount(slices: [], sites: []), true],
            'unreadable slices, another site' => [self::productionAccount(slices: ['x'], sites: [self::SITE2]), false],
            'unreadable site, other slices'   => [self::productionAccount(slices: self::PAIR2, sites: ['x']), false],
            'a login, no site, no slices'     => [self::productionAccount(slices: null, sites: null), true],
            'a certificate only'              => [['PROD' => ['environment' => Account::PRODUCTION, 'auth' => Account::CERT, 'cert' => '/etc/aa/nszm.pem']], true],
            'a certificate, other slices'     => [['PROD' => ['environment' => Account::PRODUCTION, 'auth' => Account::CERT, 'cert' => '/etc/aa/nszm.pem', 'slices' => self::PAIR2]], false],
            // an account written as a text is read as any other
            'no array, a site of others'      => [['PROD' => self::SITE2], false],
            'no array, our site'              => [['PROD' => self::SITE], true],
            'no array, no id'                 => [['PROD' => 'production'], true],
            // what is not written exactly as test is production
            'environment in capitals'         => [['PROD' => ['environment' => 'Test'] + self::testAccount()['NSZM']], true],
            'environment misspelt, others'    => [['PROD' => ['environment' => 'tset', 'sites' => [self::SITE2], 'slices' => self::PAIR2]], false],
            'no environment'                  => [['PROD' => ['auth' => Account::BASIC, 'sites' => [self::SITE2], 'slices' => self::PAIR]], true],
            // the ids of an account are in sites, slices and the keys AA does not read - never in a path, a login or a passphrase
            'ids of others, our id in a path' => [['PROD' => ['cert' => '/etc/aa-isds/' . self::CAMPAIGN . '.pem', 'key' => '/etc/aa-isds/a-key-of-a-long-name.pem', 'passphrase' => 'Pass-phrase-of-a-long-name', 'login' => 'a-login-of-a-long-name'] + $others], false],
            'our site in a misspelt key'      => [['PROD' => ['site' => [self::SITE]] + $others], true],
            // keys of a list are read too
            'our site as a key of sites'      => [['PROD' => ['sites' => [self::SITE => 'dataplan']] + $others], true],
            'our pair as keys of slices'      => [['PROD' => ['slices' => [self::CAMPAIGN => 'campaigns', self::MESSAGE => 'messages']] + $others], true],
            'our site as a key, misspelt key' => [['PROD' => ['note' => [self::SITE => 'dataplan']] + $others], true],
            'others as keys of sites'         => [['PROD' => ['sites' => [self::SITE2 => 'other']] + $others], false],
            'a site of others misspelt'       => [['PROD' => ['site' => self::SITE2] + $others], false],
            // a list broken by one bad part still names its slices
            'our pair and a third slice'      => [self::productionAccount(slices: [...self::PAIR, self::CAMPAIGN2], sites: [self::SITE2]), true],
            'our campaign slice alone'        => [self::productionAccount(slices: [self::CAMPAIGN], sites: [self::SITE2]), true],
            'our message slice with a typo'   => [self::productionAccount(slices: [self::CAMPAIGN, self::MESSAGE . 'x'], sites: [self::SITE2]), true],
            'our pair in one text'            => [self::productionAccount(slices: self::CAMPAIGN . ',' . self::MESSAGE, sites: [self::SITE2]), true],
            'our pair by a space'             => [self::productionAccount(slices: [self::CAMPAIGN . ' ' . self::MESSAGE], sites: [self::SITE2]), true],
            'our site and a bad part'         => [self::productionAccount(slices: self::PAIR2, sites: [self::SITE, 'x']), true],
            // a run of hex digits is read whole: no id of others is cut out of a typo
            'a digit before our site'         => [self::productionAccount(slices: null, sites: ['a' . self::SITE]), true],
            'a digit before each slice'       => [self::productionAccount(slices: ['0' . self::CAMPAIGN, '0' . self::MESSAGE], sites: null), true],
            'our pair glued, a digit before'  => [self::productionAccount(slices: ['f' . self::MESSAGE . self::CAMPAIGN], sites: [self::SITE2]), true],
            'our pair glued'                  => [self::productionAccount(slices: [self::CAMPAIGN . self::MESSAGE], sites: [self::SITE2]), true],
            'our ids in capitals'             => [self::productionAccount(slices: [strtoupper(self::CAMPAIGN), strtoupper(self::MESSAGE)], sites: [self::SITE2]), true],
            'ids of others glued'             => [self::productionAccount(slices: [self::CAMPAIGN2 . self::MESSAGE2], sites: [self::SITE2]), false],
            'our slice glued after another'   => [self::productionAccount(slices: [self::CAMPAIGN2 . self::MESSAGE], sites: [self::SITE2]), true],
            'a digit before a slice of others' => [self::productionAccount(slices: ['1' . self::CAMPAIGN2, self::MESSAGE2], sites: [self::SITE2]), true],
            // a word that is no id may be one of ours with a typo; short words are not
            'our site with dashes'            => [self::productionAccount(slices: self::PAIR2, sites: [self::dashed(self::SITE)]), true],
            'a site of others with dashes'    => [self::productionAccount(slices: self::PAIR2, sites: [self::dashed(self::SITE2)]), true],
            'our slice a digit short'         => [self::productionAccount(slices: [substr(self::CAMPAIGN, 0, 31), self::MESSAGE2], sites: [self::SITE2]), true],
            'our slice with o for 0'          => [self::productionAccount(slices: [str_replace('0', 'o', self::CAMPAIGN), self::MESSAGE2], sites: [self::SITE2]), true],
            'a word of 16 letters'            => [self::productionAccount(slices: [...self::PAIR2, 'abcdefghijklmnop'], sites: [self::SITE2]), true],
            'a word of 15 letters'            => [self::productionAccount(slices: [...self::PAIR2, 'abcdefghijklmno'], sites: [self::SITE2]), false],
            'short hex words beside others'   => [self::productionAccount(slices: self::PAIR2, sites: [self::SITE2 . ' # cafe beef dead']), false],
        ];
    }

    /** each site and pair its binding; production where it is bound, with the login auth names */
    public function test_binding_eachSiteAndPairItsAccount(): void
    {
        $two  = self::conf(self::testAccount('A', login: 'login-a') + self::testAccount('B', self::PAIR2, 'login-b', sites: [self::SITE2]));
        $both = self::conf(self::testAccount() + self::withAuth(Account::PRODUCTION, Account::CERT, ['cert' => '/etc/aa/nszm.pem'], 'PROD'));
        $row  = static fn(Binding $b): array => [$b->name, $b->environment, $b->kind];

        self::assertSame(['A', Account::TEST, Account::BASIC], $row(Accounts::binding(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $two)));
        self::assertSame(['B', Account::TEST, Account::BASIC], $row(Accounts::binding(self::SITE2, self::CAMPAIGN2, self::MESSAGE2, '', $two)));
        self::assertSame(['PROD', Account::PRODUCTION, Account::CERT], $row(Accounts::binding(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $both)));
        self::assertSame(['NSZM', Account::TEST, Account::BASIC], $row(Accounts::binding(self::SITE, self::CAMPAIGN, self::MESSAGE, Account::TEST, $both)));
    }

    /** bound to nothing, yet State Check lists it */
    public function test_bindings_productionBoundToNothing(): void
    {
        $binding = self::binding(['TEST' => ['environment' => Account::PRODUCTION, 'auth' => Account::BASIC, 'login' => self::LOGIN, 'password' => self::PROD_PASSWORD]], 'TEST');

        self::assertSame([Account::PRODUCTION, [], '', ''], [$binding->environment, $binding->sites, $binding->campaignSlice, $binding->messageSlice]);
        self::assertSame([
            "Set DATOVKA_CONF['accounts']['TEST']['sites'] to the ids of the sites the account sends for: ['<site id>']",
            "Set DATOVKA_CONF['accounts']['TEST']['slices'] to the ids of the campaign slice and the message slice the account sends for: ['<campaign slice id>', '<message slice id>']",
            "Set DATOVKA_CONF['accounts']['TEST']['auth'] to 'cert' or 'hosted' and remove DATOVKA_CONF['accounts']['TEST']['login'] and DATOVKA_CONF['accounts']['TEST']['password'] - AA never logs in to production ISDS with a name and a password",
        ], $binding->problems);
    }

    /** a typo never sends to the test ISDS in place of production: the account is production, and broken */
    #[DataProvider('environmentTypos')]
    public function test_bindings_environmentNotTestIsProduction(?string $environment): void
    {
        $accounts                        = self::testAccount();
        $accounts['NSZM']['environment'] = $environment;

        $binding = self::binding($accounts);

        self::assertSame(Account::PRODUCTION, $binding->environment);
        self::assertSame(['Set ' . self::key('environment') . " to 'test' or 'production'"], $binding->problems, 'the environment to fix first');
        $accounts['NSZM']['cert'] = '/etc/aa/nszm.pem';
        self::assertSame(['Set ' . self::key('environment') . " to 'test' or 'production'"], self::binding($accounts)->problems, 'no keys to remove yet');
        self::assertRefused($accounts);
        self::assertRefused($accounts, Account::TEST);
    }

    /** @return array<string, array{?string}> */
    public static function environmentTypos(): array
    {
        return ['misspelt' => ['tset'], 'in capitals' => ['TEST'], 'with a space' => ['test '], 'missing' => [null]];
    }

    /** slice ids are public: the site, which the caller passes, decides */
    #[DataProvider('sites')]
    public function test_forSlices_site(array $sites, string $asked, bool $sends): void
    {
        $accounts = self::testAccount(sites: $sites);

        if ($sends) {
            self::assertSame(self::LOGIN, self::forSlices($accounts, site: $asked)->login);
        } else {
            self::assertRefused($accounts, site: $asked);
        }
    }

    public static function sites(): array
    {
        return [
            'the site'                  => [[self::SITE], self::SITE, true],
            'one of more, capitals'     => [[' ' . strtoupper(self::SITE2), "\t" . strtoupper(self::SITE) . ' '], self::SITE, true],
            'asked in capitals'         => [[self::SITE], strtoupper(self::SITE), true],
            'another site'              => [[self::SITE], self::SITE2, false],
            'a list without this site'  => [[self::SITE2, self::CAMPAIGN], self::SITE, false],
        ];
    }

    /** @param list<string> $slices */
    #[DataProvider('pairs')]
    public function test_forSlices_pair(array $slices, string $campaign, string $message, bool $sends): void
    {
        $accounts = self::testAccount(slices: $slices);

        if ($sends) {
            self::assertSame(self::LOGIN, self::forSlices($accounts, campaign: $campaign, message: $message)->login);
        } else {
            self::assertRefused($accounts, '', self::SITE, $campaign, $message);
        }
    }

    public static function pairs(): array
    {
        return [
            'exact'                      => [self::PAIR, self::CAMPAIGN, self::MESSAGE, true],
            'capitals and spaces'        => [[' ' . strtoupper(self::CAMPAIGN), "\t" . strtoupper(self::MESSAGE) . ' '], self::CAMPAIGN, self::MESSAGE, true],
            'asked in capitals'          => [self::PAIR, strtoupper(self::CAMPAIGN), strtoupper(self::MESSAGE), true],
            'another message slice'      => [self::PAIR, self::CAMPAIGN, self::MESSAGE2, false],
            'another campaign slice'     => [self::PAIR, self::CAMPAIGN2, self::MESSAGE, false],
            'campaign slice as messages' => [self::PAIR, self::MESSAGE2, self::CAMPAIGN, false],
            'the other way round'        => [self::PAIR, self::MESSAGE, self::CAMPAIGN, false],
            'another pair'               => [self::PAIR, self::CAMPAIGN2, self::MESSAGE2, false],
        ];
    }

    #[DataProvider('brokenSlices')]
    public function test_bindings_brokenSlices(mixed $slices, string $problem): void
    {
        $accounts                   = self::testAccount();
        $accounts['NSZM']['slices'] = $slices;
        $binding                    = self::binding($accounts);

        self::assertSame(['', ''], [$binding->campaignSlice, $binding->messageSlice]);
        self::assertSame([$problem], $binding->problems);
        self::assertRefused($accounts);
    }

    public static function brokenSlices(): array
    {
        $broken = self::key('slices') . " is not a pair of slices - write ['<campaign slice id>', '<message slice id>'], 32 hexadecimal digits each";
        return [
            'empty'             => [[], 'Set ' . self::key('slices') . " to the ids of the campaign slice and the message slice the account sends for: ['<campaign slice id>', '<message slice id>']"],
            'an empty text'     => ['', 'Set ' . self::key('slices') . " to the ids of the campaign slice and the message slice the account sends for: ['<campaign slice id>', '<message slice id>']"],
            'one slice'         => [[self::CAMPAIGN], $broken],
            'three slices'      => [[...self::PAIR, self::CAMPAIGN2], $broken],
            'one text of both'  => [[self::CAMPAIGN . ',' . self::MESSAGE], $broken],
            'a text, no list'   => [self::CAMPAIGN . ',' . self::MESSAGE, $broken],
            'empty part'        => [[self::CAMPAIGN, '', self::MESSAGE], $broken],
            'short id'          => [[substr(self::CAMPAIGN, 1), self::MESSAGE], $broken],
            'not hexadecimal'   => [['g' . substr(self::CAMPAIGN, 1), self::MESSAGE], $broken],
            'one slice twice'   => [[self::CAMPAIGN, strtoupper(self::CAMPAIGN)], $broken],
            'a number'          => [[self::CAMPAIGN, 7], $broken],
            'a line break in'   => [[self::CAMPAIGN . "\n" . self::MESSAGE], $broken],
        ];
    }

    #[DataProvider('brokenSites')]
    public function test_bindings_brokenSite(mixed $sites, string $problem): void
    {
        $accounts                  = self::testAccount();
        $accounts['NSZM']['sites'] = $sites;
        $binding                   = self::binding($accounts);

        self::assertSame([], $binding->sites);
        self::assertSame([$problem], $binding->problems);
        self::assertRefused($accounts);
    }

    public static function brokenSites(): array
    {
        $broken = self::key('sites') . " is not a list of site ids - write ['<site id>', ...], 32 hexadecimal digits each";
        $set    = 'Set ' . self::key('sites') . " to the ids of the sites the account sends for: ['<site id>']";
        return [
            'missing'         => [null, $set],
            'empty'           => [[], $set],
            'an empty text'   => ['', $set],
            'not hexadecimal' => [['x' . substr(self::SITE, 1)], $broken],
            'empty part'      => [[self::SITE, '', self::SITE2], $broken],
            'one text of two' => [[self::SITE . ',' . self::SITE2], $broken],
            'a text, no list' => [self::SITE, $broken],
        ];
    }

    /**
     * An empty key is a missing one, and another account's keys are not this account's. No problem shows a value.
     * @param array<array-key, mixed> $accounts
     */
    #[DataProvider('keyProblems')]
    public function test_bindings_keyProblem(array $accounts, string $problem): void
    {
        self::assertSame([$problem], self::binding($accounts)->problems);
        self::assertRefused($accounts);
    }

    public static function keyProblems(): array
    {
        $set  = static fn(string $what): string => "Set $what - the account logs in with a name and a password";
        $both = $set(self::key('login') . ' and ' . self::key('password'));
        $fix  = 'Fix ' . self::key('login') . ' or ' . self::key('password') . ': ';
        return [
            'login'                      => [self::testAccount(login: null), $set(self::key('login'))],
            'password'                   => [self::testAccount(password: null), $set(self::key('password'))],
            'empty password'             => [self::testAccount(password: ''), $set(self::key('password'))],
            'both'                       => [self::testAccount(login: null, password: null), $both],
            'another account keys'       => [self::testAccount(login: null, password: null) + self::testAccount('OTHER', self::PAIR2, sites: [self::SITE2]), $both],
            'password with a line break' => [self::testAccount(password: self::PASSWORD . "\r\nX: 1"), self::key('password') . ' holds a line break or NUL - remove it'],
            'login with NUL'             => [self::testAccount(login: self::LOGIN . "\0"), self::key('login') . ' holds a line break or NUL - remove it'],
            'login not a text'           => [['NSZM' => ['login' => [self::LOGIN]] + self::testAccount()['NSZM']], self::key('login') . ' is not a text - write it in quotes'],
            'password not a text'        => [['NSZM' => ['password' => [self::PASSWORD]] + self::testAccount()['NSZM']], self::key('password') . ' is not a text - write it in quotes'],
            'password a number'          => [['NSZM' => ['password' => 12345678] + self::testAccount()['NSZM']], self::key('password') . ' is not a text - write it in quotes'],
            'login with a colon'         => [self::testAccount(login: self::LOGIN . ':x'), $fix . 'The ISDS login is empty or contains a colon'],
            'login of spaces'            => [self::testAccount(login: '   '), $set(self::key('login'))],
            'password of spaces'         => [self::testAccount(password: "  \t "), $set(self::key('password'))],
            'short password'             => [self::testAccount(password: 'Pa55-wo'), $fix . 'An ISDS password is 8 to 64 letters, digits and ASCII signs'],
            'password not ascii'         => [self::testAccount(password: 'Heslíčko-never-shown'), $fix . 'An ISDS password is 8 to 64 letters, digits and ASCII signs'],
        ];
    }

    /** a production password does not belong in the file at all - even a damaged one */
    #[DataProvider('productionPasswords')]
    public function test_bindings_productionNeverWithPassword(?string $password, string $remove): void
    {
        $accounts = self::productionAccount('NSZM', password: $password);
        $binding  = self::binding($accounts);

        $problems = ['Set ' . self::key('auth') . " to 'cert' or 'hosted' and remove $remove - AA never logs in to production ISDS with a name and a password"];
        self::assertSame([Account::PRODUCTION, [self::SITE], self::CAMPAIGN, self::MESSAGE, $problems], [$binding->environment, $binding->sites, $binding->campaignSlice, $binding->messageSlice, $binding->problems]);
        self::assertRefused($accounts, Account::PRODUCTION);
    }

    public static function productionPasswords(): array
    {
        $both = self::key('login') . ' and ' . self::key('password');
        return [
            'login only' => [null, self::key('login')],
            'password'   => [self::PROD_PASSWORD, $both],
            'damaged'    => [self::PROD_PASSWORD . "\n", $both],
            'empty'      => ['', self::key('login')],
        ];
    }

    /**
     * auth says how the account logs in: basic - a name and a password, in test only; cert - a system
     * certificate; hosted - a hosted filing service and its box. Keys of another login are a problem that
     * says what to remove, never a guess.
     * @param array<string, string> $set    the keys of the login, besides environment, auth, sites and slices
     * @param list<string>          $problems
     */
    #[DataProvider('logins')]
    public function test_bindings_kindOfLogin(string $environment, string $auth, array $set, string $kind, array $problems): void
    {
        $accounts = self::withAuth($environment, $auth, $set);
        $binding  = self::binding($accounts);

        self::assertSame([$kind, $problems], [$binding->kind, $binding->problems]);
        if ($problems) {
            self::assertRefused($accounts, $environment);
        } else {
            $account = self::forSlices($accounts, $environment);
            self::assertSame([$environment, $kind], [$account->environment, $account->kind]);
        }
    }

    public static function logins(): array
    {
        $pem    = '/etc/aa/isds.pem';
        $cert   = static fn(string $of): string => 'Set ' . self::key('cert') . " to the PEM file of the certificate $of - a full path outside the directory of AA";
        $remove = static fn(string $keys, string $auth, string $what = 'it'): string => "Remove $keys - an account with auth '$auth' does not use $what";
        $auth   = 'Set ' . self::key('auth') . " to 'basic', 'cert' or 'hosted'";
        return [
            'name and password, test'       => [Account::TEST, Account::BASIC, ['login' => self::LOGIN, 'password' => self::PASSWORD], Account::BASIC, []],
            'name and password, production' => [Account::PRODUCTION, Account::BASIC, ['login' => self::LOGIN, 'password' => self::PROD_PASSWORD], Account::BASIC, [
                'Set ' . self::key('auth') . " to 'cert' or 'hosted' and remove " . self::key('login') . ' and ' . self::key('password') . ' - AA never logs in to production ISDS with a name and a password',
            ]],
            // the certificate is right, auth is not: never also told to remove it
            'basic with a certificate, production' => [Account::PRODUCTION, Account::BASIC, ['cert' => $pem], Account::BASIC, [
                'Set ' . self::key('auth') . " to 'cert' or 'hosted' - AA never logs in to production ISDS with a name and a password",
            ]],
            'basic, all keys, production'   => [Account::PRODUCTION, Account::BASIC, ['login' => self::LOGIN, 'password' => self::PROD_PASSWORD, 'cert' => $pem, 'box' => 'kv62bqf'], Account::BASIC, [
                'Set ' . self::key('auth') . " to 'cert' or 'hosted' and remove " . self::key('login') . ' and ' . self::key('password') . ' - AA never logs in to production ISDS with a name and a password',
            ]],
            'certificate, test'             => [Account::TEST, Account::CERT, ['cert' => $pem], Account::CERT, []],
            'certificate, production'       => [Account::PRODUCTION, Account::CERT, ['cert' => $pem], Account::CERT, []],
            'with its key and passphrase'   => [Account::PRODUCTION, Account::CERT, ['cert' => $pem, 'key' => '/etc/aa/isds.key', 'passphrase' => 'Pass-phrase'], Account::CERT, []],
            'hosted'                        => [Account::PRODUCTION, Account::HOSTED, ['box' => ' KV62BQF ', 'cert' => $pem], Account::HOSTED, []],
            'hosted, test'                  => [Account::TEST, Account::HOSTED, ['box' => 'dj7j6mk', 'cert' => $pem], Account::HOSTED, []],
            'a box without certificate'     => [Account::PRODUCTION, Account::HOSTED, ['box' => 'kv62bqf'], Account::HOSTED, [$cert('of the hosting service')]],
            'an empty certificate'          => [Account::PRODUCTION, Account::CERT, ['cert' => ''], Account::CERT, [$cert('ISDS registered for the data box')]],
            'a certificate of spaces'       => [Account::PRODUCTION, Account::CERT, ['cert' => '  '], Account::CERT, [$cert('ISDS registered for the data box')]],
            'hosted, a certificate of spaces' => [Account::PRODUCTION, Account::HOSTED, ['box' => 'kv62bqf', 'cert' => " \t"], Account::HOSTED, [$cert('of the hosting service')]],
            'a key of spaces'               => [Account::PRODUCTION, Account::CERT, ['cert' => $pem, 'key' => '  '], Account::CERT, []],
            'a name, a key of spaces'       => [Account::TEST, Account::BASIC, ['login' => self::LOGIN, 'password' => self::PASSWORD, 'key' => '  ', 'cert' => ' '], Account::BASIC, []],
            'an empty box'                  => [Account::PRODUCTION, Account::HOSTED, ['box' => '', 'cert' => $pem], Account::HOSTED, ['Set ' . self::key('box') . ' to the id of the data box the account sends from']],
            'not a box'                     => [Account::PRODUCTION, Account::HOSTED, ['box' => 'kv62bq', 'cert' => $pem], Account::HOSTED, [self::key('box') . ' is not the id of a data box - 7 letters or digits']],
            'damaged certificate'           => [Account::PRODUCTION, Account::CERT, ['cert' => "$pem\n"], Account::CERT, [self::key('cert') . ' holds a line break or NUL - remove it']],
            'certificate and a login'       => [Account::TEST, Account::CERT, ['cert' => $pem, 'login' => self::LOGIN], Account::CERT, [$remove(self::key('login'), Account::CERT)]],
            'certificate and a password'    => [Account::PRODUCTION, Account::CERT, ['cert' => $pem, 'password' => self::PROD_PASSWORD], Account::CERT, [$remove(self::key('password'), Account::CERT)]],
            'certificate and a box'         => [Account::PRODUCTION, Account::CERT, ['cert' => $pem, 'box' => 'kv62bqf'], Account::CERT, [$remove(self::key('box'), Account::CERT)]],
            'hosted, name and password'     => [Account::TEST, Account::HOSTED, ['box' => 'kv62bqf', 'cert' => $pem, 'login' => self::LOGIN, 'password' => self::PASSWORD], Account::HOSTED, [$remove(self::key('login') . ' and ' . self::key('password'), Account::HOSTED, 'them')]],
            'hosted, an empty password'     => [Account::TEST, Account::HOSTED, ['box' => 'kv62bqf', 'cert' => $pem, 'password' => ''], Account::HOSTED, []],
            'a key without certificate'     => [Account::TEST, Account::BASIC, ['login' => self::LOGIN, 'password' => self::PASSWORD, 'key' => '/etc/aa/isds.key'], Account::BASIC, [$remove(self::key('key'), Account::BASIC)]],
            'a passphrase alone'            => [Account::TEST, Account::BASIC, ['passphrase' => 'Pass-phrase'], Account::BASIC, [
                $remove(self::key('passphrase'), Account::BASIC),
                'Set ' . self::key('login') . ' and ' . self::key('password') . ' - the account logs in with a name and a password',
            ]],
            'no auth'                       => [Account::TEST, '', ['login' => self::LOGIN, 'password' => self::PASSWORD], '', [$auth]],
            'auth misspelt'                 => [Account::PRODUCTION, 'certificate', ['cert' => $pem], '', [$auth]],
        ];
    }

    // production is meant where it is bound; a relative path starts in config/certs/ of AA
    public function test_forSlices_certificateAccount(): void
    {
        $accounts = self::testAccount() + self::withAuth(Account::PRODUCTION, Account::CERT, ['cert' => ' nszm.pem ', 'key' => ' /etc/aa-isds/nszm.key', 'passphrase' => 'Pass-phrase-never-shown'], 'PROD');

        $account = self::forSlices($accounts);

        self::assertSame([Account::PRODUCTION, Account::CERT, 'https://ws1c.datovka.gov.cz/cert/DS/dz', []], [$account->environment, $account->kind, $account->url('dz'), $account->headers()]);
        self::assertSame([DatovkaConf::base() . 'config/certs/nszm.pem', '/etc/aa-isds/nszm.key', 'Pass-phrase-never-shown'], [$account->certificate?->certificate, $account->certificate?->key, $account->certificate?->passphrase()]);
        self::assertSame(self::LOGIN, self::forSlices($accounts, Account::TEST)->login, 'test asked, test given');
    }

    // the hosted filing service names the box it sends from, with an empty password
    public function test_forSlices_hostedAccount(): void
    {
        $account = self::forSlices(self::withAuth(Account::PRODUCTION, Account::HOSTED, ['cert' => '/etc/aa/ecn.pem', 'box' => 'KV62BQF']));

        self::assertSame([Account::HOSTED, 'kv62bqf', 'https://ws1c.datovka.gov.cz/hspis/DS/dz'], [$account->kind, $account->login, $account->url('dz')]);
        self::assertSame(['Authorization' => 'Basic ' . base64_encode('kv62bqf:')], $account->headers());
        self::assertSame('/etc/aa/ecn.pem', $account->certificate?->keyFile());
    }

    // State Check checks the files of an account it cannot use yet
    public function test_certificate_ofAnyBinding(): void
    {
        $accounts = self::withAuth(Account::PRODUCTION, Account::CERT, ['cert' => '/etc/aa/nszm.pem']);
        unset($accounts['NSZM']['sites']);

        self::assertFalse(self::binding($accounts)->usable());
        self::assertSame('/etc/aa/nszm.pem', Accounts::certificate(self::binding($accounts), self::conf($accounts))?->certificate);
        self::assertNull(Accounts::certificate(self::binding(self::testAccount()), self::conf(self::testAccount())));
    }

    /**
     * What AA does not read - an account of a name in another form, a misspelt key - keeps '' from test
     * unless the ids in it, or in the account it belongs to, are all of others. Only what is written
     * exactly as test is no production.
     * @param array<array-key, mixed> $production
     */
    #[DataProvider('productionStrays')]
    public function test_forSlices_productionStrayNeverTestInItsPlace(array $production, bool $refused): void
    {
        $accounts = self::testAccount() + $production;

        if ($refused) {
            self::assertRefused($accounts);
        } else {
            self::assertSame(Account::TEST, self::forSlices($accounts)->environment);
        }
        self::assertSame(self::LOGIN, self::forSlices($accounts, Account::TEST)->login, 'asking for test is always fine');
    }

    public static function productionStrays(): array
    {
        $production = ['environment' => Account::PRODUCTION, 'auth' => Account::CERT, 'cert' => 'nszm.pem'];
        return [
            'the account in small letters'        => [['Prod' => ['slices' => self::PAIR] + $production], true],
            'its slices are others'               => [['Prod' => ['slices' => self::PAIR2] + $production], false],
            'site for sites'                      => [['PROD' => ['site' => [self::SITE]] + $production], true],
            'site of another site, no slices'     => [['PROD' => ['site' => [self::SITE2]] + $production], false],
            'only a certificate'                  => [['Prod' => ['cert' => 'nszm.pem']], true],
            'a certificate, the site of others'   => [['Prod' => ['cert' => 'nszm.pem', 'sites' => [self::SITE2]]], false],
            'a typo, the account bound elsewhere' => [['PROD' => ['cret' => 'nszm.pem', 'sites' => [self::SITE2], 'slices' => self::PAIR2] + $production], false],
            'a typo, an account of no ids'        => [['PROD' => ['cret' => 'nszm.pem'] + $production], true],
            'a name of digits'                    => [[7 => ['sites' => [self::SITE]] + $production], true],
            'a name with a dash'                  => [['PROD-2' => ['sites' => [self::SITE2], 'slices' => self::PAIR2] + $production], false],
            'damaged ids of others'               => [['Prod' => ['slices' => [self::CAMPAIGN2, self::MESSAGE2 . "\n"]]], false],
            'ids of others and a bad part'        => [['Prod' => ['slices' => [...self::PAIR2, 'x']]], false],
            'our pair and a bad part'             => [['Prod' => ['slices' => [...self::PAIR, 'x'], 'sites' => [self::SITE2]]], true],
            'a test account in small letters'     => [['Nszm' => ['environment' => Account::TEST, 'sites' => [self::SITE], 'slices' => self::PAIR]], false],
        ];
    }

    /**
     * A production account a bracket out of its place - at the top, in test or standard, under a misspelt
     * accounts - is no account, yet keeps '' from test unless its ids are all of others. A text there is no account.
     * @param array<array-key, mixed> $settings DATOVKA_CONF besides the accounts
     */
    #[DataProvider('outOfPlace')]
    public function test_forSlices_productionOutOfPlaceNeverTestInItsPlace(array $settings, bool $refused): void
    {
        $conf = DatovkaConf::fromArray($settings + [DatovkaConf::ACCOUNTS => self::testAccount()]);
        $call = static fn(string $environment): Account => Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, $environment, $conf);

        if ($refused) {
            try {
                $call('');
                self::fail('ConfigException expected');
            } catch (ConfigException $e) {
                self::assertSame(self::refusalText(), $e->getMessage());
            }
        } else {
            self::assertSame(Account::TEST, $call('')->environment);
        }
        self::assertSame(self::LOGIN, $call(Account::TEST)->login, 'asking for test is always fine');
        self::assertSame(['NSZM'], array_map(static fn(Binding $b): string => $b->name, Accounts::bindings($conf)), 'no account');
    }

    public static function outOfPlace(): array
    {
        $production = ['environment' => Account::PRODUCTION, 'auth' => Account::HOSTED, 'sites' => [self::SITE], 'slices' => self::PAIR, 'box' => 'kv62bqf', 'cert' => '/etc/aa-isds/hosting.pem'];
        $others     = ['sites' => [self::SITE2], 'slices' => self::PAIR2] + $production;
        return [
            'at the top'                => [['PROD' => $production], true],
            'in standard'               => [['standard' => ['cert' => '', 'atsId' => '', 'PROD' => $production]], true],
            'in test'                   => [['test' => ['PROD' => $production]], true],
            'under a misspelt accounts' => [['acounts' => ['PROD' => $production]], true],
            'without sites and slices'  => [['PROD' => ['environment' => Account::PRODUCTION, 'cert' => '/etc/aa-isds/hosting.pem']], true],
            'our site as a key'         => [['PROD' => ['sites' => [self::SITE => 'dataplan']] + $others], true],
            'at the top, of others'     => [['PROD' => $others], false],
            'in standard, of others'    => [['standard' => ['PROD' => $others]], false],
            'a test account at the top' => [['PROD' => ['environment' => Account::TEST] + $production], false],
            'a text at the top'         => [['note' => self::SITE], false],
            'named in instances'        => [['instances' => ['PROD' => $production]], true],
            'numbered in instances'     => [['instances' => [1000 => ['url' => 'https://example.org/aa/'] + $others]], false],
            // a production account inside a test one: its closing bracket written after the other
            'inside the test account'   => [[DatovkaConf::ACCOUNTS => ['NSZM' => self::testAccount()['NSZM'] + ['PROD' => $production]]], true],
            'numbered in the test one'  => [[DatovkaConf::ACCOUNTS => ['NSZM' => self::testAccount()['NSZM'] + [0 => $production]]], true],
            'deeper in the test one'    => [[DatovkaConf::ACCOUNTS => ['NSZM' => self::testAccount()['NSZM'] + ['old' => ['PROD' => $production]]]], true],
            'inside a test block on top' => [['X' => ['environment' => Account::TEST, 'PROD' => $production]], true],
            'inside the test one, of others' => [[DatovkaConf::ACCOUNTS => ['NSZM' => self::testAccount()['NSZM'] + ['PROD' => $others]]], false],
        ];
    }

    /**
     * A production account in an array anywhere - under each key of another test account, by name or by
     * number, levels down, in an installation - keeps '' from test. A real list of sites or slices holds
     * texts only, so nothing of a sound file counts.
     * @param array<array-key, mixed> $settings DATOVKA_CONF; its accounts beside the test account NSZM
     */
    #[DataProvider('nested')]
    public function test_forSlices_productionAnywhereNeverTestInItsPlace(array $settings): void
    {
        $conf = DatovkaConf::fromArray([DatovkaConf::ACCOUNTS => ($settings[DatovkaConf::ACCOUNTS] ?? []) + self::testAccount()] + $settings);

        try {
            Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $conf);
            self::fail('ConfigException expected');
        } catch (ConfigException $e) {
            self::assertSame(self::refusalText(), $e->getMessage());
        }
        self::assertSame(self::LOGIN, Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, Account::TEST, $conf)->login, 'asking for test is always fine');
    }

    public static function nested(): array
    {
        $production = ['environment' => Account::PRODUCTION, 'auth' => Account::HOSTED, 'sites' => [self::SITE], 'slices' => self::PAIR, 'box' => 'kv62bqf', 'cert' => '/etc/aa-isds/hosting.pem'];
        $other      = self::testAccount('OTHER', self::PAIR2, 'other-login', sites: [self::SITE2])['OTHER'];
        $cases      = [];
        foreach (['environment', 'auth', 'sites', 'slices', 'login', 'password', 'box', 'cert', 'key', 'passphrase'] as $key) {
            $cases["under $key of a test account, by name"]   = [[DatovkaConf::ACCOUNTS => ['OTHER' => [$key => ['NSZM' => $production]] + $other]]];
            $cases["under $key of a test account, by number"] = [[DatovkaConf::ACCOUNTS => ['OTHER' => [$key => ['x', $production]] + $other]]];
        }
        return $cases + [
            'four levels down a test account'  => [[DatovkaConf::ACCOUNTS => ['OTHER' => $other + ['old' => [1 => ['a' => [2 => $production]]]]]]],
            'a list item of the accounts'      => [[DatovkaConf::ACCOUNTS => [$production]]],
            'in the sites of a test block'     => [['X' => ['environment' => Account::TEST, 'sites' => ['NSZM' => $production]]]],
            'beside the url of an installation' => [['instances' => [1000 => ['url' => 'https://www.example.org/aa/', 'NSZM' => $production]]]],
            'a numbered installation'          => [['instances' => [1001 => $production]]],
            'in place of a certificate'        => [['standard' => ['cert' => ['NSZM' => $production], 'atsId' => '']]],
            'by number in standard'            => [['standard' => ['cert' => '', 'atsId' => '', 7 => $production]]],
            'in place of an address of ISDS'   => [['test' => ['cert' => '', 'url_login' => ['NSZM' => $production]]]],
            'an installation by name'          => [['instances' => [1000 => ['url' => 'https://www.example.org/aa/']] + $production]],
            'the installations'                => [['instances' => $production]],
        ];
    }

    /**
     * Files as they are written - the template, the form of NSZM, an old file with the addresses of ISDS,
     * a second test account - leave test to '' when production is bound elsewhere
     * @param array<array-key, mixed> $settings DATOVKA_CONF; its accounts beside the test account NSZM, or in its place
     */
    #[DataProvider('soundFiles')]
    public function test_forSlices_soundFileLeavesTest(array $settings): void
    {
        $conf = DatovkaConf::fromArray([DatovkaConf::ACCOUNTS => ($settings[DatovkaConf::ACCOUNTS] ?? []) + self::testAccount()] + $settings);

        self::assertSame([Account::TEST, self::LOGIN], [Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $conf)->environment, Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $conf)->login]);
    }

    public static function soundFiles(): array
    {
        $ofOthers = ['environment' => Account::PRODUCTION, 'auth' => Account::HOSTED, 'sites' => [self::SITE2], 'slices' => self::PAIR2, 'box' => 'kv62bqf', 'cert' => '/etc/aa-isds/ecn-hspis.pem', 'key' => '', 'passphrase' => ''];
        $ats      = ['cert' => 'ecntest2.crt', 'atsId' => 'a1b2c3d4e5f60718'];
        return [
            'the template'           => [['instances' => [], 'test' => ['cert' => '', 'atsId' => ''], 'standard' => ['cert' => '', 'atsId' => '']]],
            'the form of NSZM'       => [['instances' => [1000 => ['url' => 'https://www.dataplan.info/aa-dev/']], 'test' => $ats, 'standard' => ['cert' => '/etc/aa-isds/ats.pem', 'atsId' => ''], DatovkaConf::ACCOUNTS => ['PROD' => $ofOthers]]],
            'a second test account'  => [[DatovkaConf::ACCOUNTS => ['PROD' => $ofOthers] + self::testAccount('OTHER', self::PAIR2, 'other-login', sites: [self::SITE2])]],
            'an old file'            => [[
                'instances' => [1000 => ['url' => 'https://www.dataplan.info/aa-dev/'], 1001 => ['url' => 'https://adamsbeauty.ecn.cz/aa-dev/']],
                'test'      => ['cert' => '/srv/aa/config/certs/myTestCert.crt', 'atsId' => '', 'url_login' => 'https://www.datovka-test.gov.cz/as/login', 'wsdl_SetConcept' => '/srv/aa/misc/datovka/wsdls/SetConcept.wsdl'],
                'standard'  => ['cert' => '/srv/aa/config/certs/myRealCert.crt', 'atsId' => '', 'url_login' => 'https://www.datovka.gov.cz/as/login'],
                DatovkaConf::ACCOUNTS => ['PROD' => $ofOthers],
            ]],
            'slices named'           => [[DatovkaConf::ACCOUNTS => ['NSZM' => ['slices' => ['campaigns' => self::CAMPAIGN, 'messages' => self::MESSAGE]] + self::testAccount()['NSZM']]]],
            'an empty list beside'   => [[DatovkaConf::ACCOUNTS => ['NSZM' => self::testAccount()['NSZM'] + ['notes' => []]]]],
            'old slices of others'   => [[DatovkaConf::ACCOUNTS => ['NSZM' => self::testAccount()['NSZM'] + ['slices_old' => self::PAIR2]]]],
            'a list for a login'     => [[DatovkaConf::ACCOUNTS => ['OTHER' => ['login' => ['a', 'b']] + self::testAccount('OTHER', self::PAIR2, sites: [self::SITE2])['OTHER']]]],
            'empty lists'            => [['X' => [], 'test' => ['cert' => [], 'atsId' => '']]],
        ];
    }

    /**
     * State Check of a database deals with the accounts of its sites, and with those of no site read; it
     * asks once about the sites of all and the slices of those of no site
     */
    public function test_here_theBindingsOfTheSitesOfThisDatabase(): void
    {
        $slicesD  = ['cafe0000000000000000000000000007', 'beef0000000000000000000000000008'];
        $accounts = self::testAccount('A') + self::testAccount('B', self::PAIR2, 'login-b', sites: [self::SITE2, self::SITE])
            + self::testAccount('C', ['cafe0000000000000000000000000005', 'beef0000000000000000000000000006'], 'login-c', sites: [self::SITE2])
            + self::testAccount('D', $slicesD, 'login-d', sites: null)
            + ['E' => self::SITE];
        $bindings = Accounts::bindings(self::conf($accounts));

        self::assertSame([self::SITE, self::SITE2, ...$slicesD], Accounts::moduleIds($bindings), 'E names no slices');
        self::assertSame(['E', 'A', 'B', 'D'], array_map(static fn(Binding $b): string => $b->name, Accounts::here($bindings, [self::SITE, self::CAMPAIGN])));
        self::assertSame(['E', 'D'], array_map(static fn(Binding $b): string => $b->name, Accounts::here($bindings, [])));
        self::assertSame([[], []], [Accounts::moduleIds([]), Accounts::here([], [self::SITE])]);
    }

    // a path of spaces names no file
    public function test_certificate_pathOfSpacesNamesNoFile(): void
    {
        $accounts = self::withAuth(Account::PRODUCTION, Account::CERT, ['cert' => '/etc/aa/nszm.pem', 'key' => ' ']);

        $certificate = Accounts::certificate(self::binding($accounts), self::conf($accounts));

        self::assertSame(['/etc/aa/nszm.pem', '', '/etc/aa/nszm.pem'], [$certificate?->certificate, $certificate?->key, $certificate?->keyFile()]);
        $accounts['NSZM']['cert'] = '  ';
        self::assertNull(Accounts::certificate(self::binding($accounts), self::conf($accounts)));
    }

    /** a pair reversed or mistyped in a template must not send with the account of another pair */
    #[DataProvider('sharedSlices')]
    public function test_bindings_sliceOfTwoAccounts(array $other, string $problem): void
    {
        $accounts = self::testAccount('A') + $other;

        foreach (['A', 'B'] as $name) {
            self::assertSame([$problem], self::binding($accounts, $name)->problems, $name);
        }
        self::assertRefused($accounts);
        self::assertRefused($accounts, '', self::SITE, self::MESSAGE, self::CAMPAIGN);
    }

    public static function sharedSlices(): array
    {
        $problem = "DATOVKA_CONF['accounts']['A']['slices'], DATOVKA_CONF['accounts']['B']['slices'] name the same slice - a slice belongs to one account";
        return [
            'the same pair'               => [self::testAccount('B', [strtoupper(self::CAMPAIGN), strtoupper(self::MESSAGE)]), $problem],
            'the pair reversed'           => [self::testAccount('B', [self::MESSAGE, self::CAMPAIGN]), $problem],
            'one slice'                   => [self::testAccount('B', [self::CAMPAIGN, self::MESSAGE2]), $problem],
            'the same pair, another site' => [self::testAccount('B', sites: [self::SITE2]), $problem],
        ];
    }

    // one environment per slice: a test account and a production one may share the pair
    public function test_bindings_sliceOfTwoEnvironments(): void
    {
        $accounts = self::testAccount() + self::withAuth(Account::PRODUCTION, Account::CERT, ['cert' => '/etc/aa/nszm.pem'], 'PROD');

        self::assertSame([true, true], [self::binding($accounts)->usable(), self::binding($accounts, 'PROD')->usable()]);
    }

    public function test_forSlices_eachSiteAndPairItsAccount(): void
    {
        $accounts = self::testAccount('A', login: 'login-a') + self::testAccount('B', self::PAIR2, 'login-b', sites: [self::SITE2]);

        self::assertSame('login-a', self::forSlices($accounts)->login);
        self::assertSame('login-b', self::forSlices($accounts, site: self::SITE2, campaign: self::CAMPAIGN2, message: self::MESSAGE2)->login);
        self::assertRefused($accounts, '', self::SITE2, self::CAMPAIGN, self::MESSAGE);
        // the slices of B named on the site of A
        self::assertRefused($accounts, Account::TEST, self::SITE, self::CAMPAIGN2, self::MESSAGE2);
    }

    public function test_account_ofAUsableBinding(): void
    {
        $accounts = self::testAccount();

        $account = Accounts::account(self::binding($accounts), self::conf($accounts));

        self::assertSame([Account::TEST, self::LOGIN], [$account->environment, $account->login]);
        self::assertSame(['Authorization' => 'Basic ' . base64_encode(self::LOGIN . ':' . self::PASSWORD)], $account->headers());
    }

    // a binding without its site or slices is not one to send with
    public function test_account_refusesAnUnusableBinding(): void
    {
        $accounts = self::testAccount(sites: null);
        try {
            Accounts::account(self::binding($accounts), self::conf($accounts));
            self::fail('ConfigException expected');
        } catch (ConfigException $e) {
            self::assertStringStartsWith("Fix DATOVKA_CONF['accounts']['NSZM'] in misc/datovka/datovka_conf.php first: Set " . self::key('sites'), $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    // an account not usable yet - or no more - is no binding to read keys of: none are there
    public function test_account_ofAGoneAccount(): void
    {
        $binding = self::binding(self::testAccount());

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Set ' . self::key('login') . ' and ' . self::key('password'));
        Accounts::account($binding, self::conf([]));
    }

    public function test_label_settingAndKeys(): void
    {
        self::assertSame('NSZM (test)', Accounts::label(Account::TEST, 'NSZM'));
        self::assertSame("DATOVKA_CONF['accounts']['NSZM']", Accounts::setting('NSZM'));
        self::assertSame(self::key('login'), Accounts::setting('NSZM', Accounts::LOGIN));
        self::assertSame(['environment', 'auth', 'sites', 'slices', 'login', 'password'], Accounts::keys(Account::TEST, Account::BASIC));
        self::assertSame(['environment', 'auth', 'sites', 'slices', 'box', 'cert', 'key', 'passphrase'], Accounts::keys(Account::PRODUCTION, Account::HOSTED));
        self::assertSame(Accounts::keys(Account::PRODUCTION, Account::HOSTED), Accounts::keys(Account::PRODUCTION, Account::BASIC), 'production never lists a name and a password');
        self::assertSame(['environment', 'auth', 'sites', 'slices', 'cert', 'key', 'passphrase'], Accounts::keys(Account::TEST, Account::CERT));
        self::assertCount(10, Accounts::keys(Account::TEST), 'of any login');
        self::assertSame(Accounts::keys(Account::TEST), Accounts::keys(Account::TEST, ''), 'auth not known');
    }

    public function test_strays_whatIsNoAccount(): void
    {
        $accounts = self::testAccount() + [
            'nszm'   => ['environment' => Account::TEST, 'sites' => [self::SITE2]],
            'NSZM-2' => ['password' => self::PASSWORD],
            '2NSZM'  => [],
            7        => self::SITE2,
        ];
        $accounts['NSZM'] += ['pasword' => self::PASSWORD, 'Login' => self::LOGIN, 3 => 'x'];

        self::assertSame(['NSZM'], array_map(static fn(Binding $b): string => $b->name, Accounts::bindings(self::conf($accounts))));
        self::assertSame([
            self::key('pasword'), self::key('Login'), "DATOVKA_CONF['accounts']['NSZM'][3]",
            "DATOVKA_CONF['accounts']['nszm']", "DATOVKA_CONF['accounts']['NSZM-2']", "DATOVKA_CONF['accounts']['2NSZM']", "DATOVKA_CONF['accounts'][7]",
        ], Accounts::strays(self::conf($accounts)));
        self::assertSame([
            "DATOVKA_CONF['accounts']['nszm']", "DATOVKA_CONF['accounts']['NSZM-2']", "DATOVKA_CONF['accounts']['2NSZM']", "DATOVKA_CONF['accounts'][7]",
        ], Accounts::strays(self::conf($accounts), ['ECN']), 'the keys of the accounts named only; each name AA does not read');
        self::assertTrue(self::binding($accounts)->usable(), 'a stray beside does not break an account');
    }

    // the entry of an account is an array of its keys
    public function test_bindings_notAnArray(): void
    {
        $binding = self::binding(['NSZM' => self::SITE]);

        self::assertSame([Account::PRODUCTION, '', ["DATOVKA_CONF['accounts']['NSZM'] is not an array - give the account its keys in one"]], [$binding->environment, $binding->kind, $binding->problems]);
        self::assertRefused(['NSZM' => self::SITE]);
    }

    public function test_bindings_listWithoutKeys(): void
    {
        $accounts = self::testAccount('NSZM') + self::testAccount('ABC', self::PAIR2, password: null, sites: [self::SITE, self::SITE2])
            + self::productionAccount('PROD', self::PAIR2, [self::SITE2]) + ['LONELY' => ['environment' => Account::TEST, 'auth' => Account::BASIC, 'login' => self::LOGIN]];

        $bindings = Accounts::bindings(self::conf($accounts));
        $rows     = array_map(static fn(Binding $b): array => [$b->environment, $b->name, $b->sites, $b->campaignSlice, $b->messageSlice, $b->usable()], $bindings);
        self::assertSame([
            [Account::PRODUCTION, 'PROD', [self::SITE2], self::CAMPAIGN2, self::MESSAGE2, false],
            [Account::TEST, 'ABC', [self::SITE, self::SITE2], self::CAMPAIGN2, self::MESSAGE2, false],
            [Account::TEST, 'LONELY', [], '', '', false],
            [Account::TEST, 'NSZM', [self::SITE], self::CAMPAIGN, self::MESSAGE, true],
        ], $rows);
        self::assertSame([
            "Set DATOVKA_CONF['accounts']['LONELY']['sites'] to the ids of the sites the account sends for: ['<site id>']",
            "Set DATOVKA_CONF['accounts']['LONELY']['slices'] to the ids of the campaign slice and the message slice the account sends for: ['<campaign slice id>', '<message slice id>']",
            "Set DATOVKA_CONF['accounts']['LONELY']['password'] - the account logs in with a name and a password",
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
        Accounts::forSlices($site, $campaign, $message, $environment, self::conf(self::testAccount()));
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

    /** the file holds the passwords of all accounts - a trace with the arguments must show none of them */
    public function test_forSlices_keysNotInTraces(): void
    {
        $other = 'Other-Pa55-never-in-a-trace';
        $conf  = self::conf(self::testAccount() + self::testAccount('OTHER', self::PAIR2, 'other-login-never-in-a-trace', $other, [self::SITE2]));
        $calls = [
            'refused'     => static fn() => Accounts::forSlices(self::SITE2, self::CAMPAIGN, self::MESSAGE, '', $conf),
            'environment' => static fn() => Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, 'staging', $conf),
            'unusable'    => static fn() => Accounts::account(new Binding('NSZM', Account::TEST, [self::SITE], self::CAMPAIGN, self::MESSAGE, ['broken'], Account::BASIC), $conf),
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
                    self::assertStringContainsString(($case === 'unusable') ? 'NSZM' : self::CAMPAIGN, $traces, "$case: the trace keeps arguments");
                    foreach ([$other, self::PASSWORD, self::LOGIN, 'other-login-never-in-a-trace'] as $value) {
                        self::assertStringNotContainsString($value, $traces, $case);
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $ignore);
        }
    }

    /** the one test with a real file: as the administrator writes it */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_forSlices_fromAFile(): void
    {
        $file = sys_get_temp_dir() . '/aa-accounts-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($file, "<?php\nconst DATOVKA_CONF = ['accounts' => [\n    'REAL' => [\n        'environment' => 'test',\n        'auth'        => 'basic',\n        'sites'       => ['" . strtoupper(self::SITE) . "'],\n        'slices'      => ['" . self::CAMPAIGN . "', '" . self::MESSAGE . "'],\n        'login'       => 'real-login',\n        'password'    => 'Real-Pa55-never-shown',\n    ],\n]];\n");
        try {
            $conf = DatovkaConf::fromFile($file);
        } finally {
            unlink($file);
        }

        $account = Accounts::forSlices(self::SITE, self::CAMPAIGN, self::MESSAGE, '', $conf);

        self::assertSame(['real-login', ['Authorization' => 'Basic ' . base64_encode('real-login:Real-Pa55-never-shown')]], [$account->login, $account->headers()]);
        self::assertSame([[], []], [$conf->problems(), Accounts::strays($conf)]);
    }
}
