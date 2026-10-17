<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Isds\SliceRules;
use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckIsdsAccounts;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Optimize\Fixtures\ScriptedIsdsAccountsCheck;

/**
 * CheckIsdsAccounts: the ISDS accounts of config.php with what to fix in their constants - named,
 * never their values - and whether their site and slices pass SliceRules. The constants are passed
 * in, the slices come from FakeSliceRules.
 */
final class CheckIsdsAccountsTest extends TestCase
{
    private const SITE     = '51de0000000000000000000000000001';
    private const SITE2    = '5e7e0000000000000000000000000002';
    private const CAMPAIGN = 'cafe0000000000000000000000000001';
    private const MESSAGE  = 'beef0000000000000000000000000002';

    private const LOGIN    = 'nszm01login';
    private const PASSWORD = 'Test-Pa55-never-shown';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/../Isds/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/../Isds/fixtures/FakeSliceRules.php';
        require_once __DIR__ . '/fixtures/ScriptedIsdsAccountsCheck.php';
    }

    protected function tearDown(): void
    {
        FakeSliceRules::forget();
    }

    public function test_types_basicOnly(): void
    {
        self::assertSame([AbstractOptimize::CHECK_BASIC], CheckIsdsAccounts::types());
    }

    public function test_findings_noAccountIsOk(): void
    {
        $findings = self::check(['AA_APIKEY_CSU' => 'x', 'AA_ID' => 'y'])->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('No ISDS account is set up in include/config.php', $findings[0]->title);
    }

    public function test_findings_readyIsOk(): void
    {
        $findings = self::check(self::account())->findings();

        self::assertSame([Finding::OK], self::severities($findings));
        self::assertSame('ISDS accounts ready to send: AA_APIKEY_ISDSTEST_NSZM', $findings[0]->title);
    }

    public function test_findings_brokenAccountNamesTheConstantsNeverTheValues(): void
    {
        $findings = self::check(self::account(password: 'short-1'))->findings();

        self::assertSame([Finding::ERROR], self::severities($findings), 'the slices are fine');
        $finding = $findings[0];
        self::assertSame('ISDS account AA_APIKEY_ISDSTEST_NSZM cannot be used', $finding->title);
        self::assertSame('account:test:NSZM', $finding->key);
        self::assertStringContainsString('AA_APIKEY_ISDSTEST_NSZM_PASSWORD', $finding->evidence[0]['Problem']);
        self::assertStringContainsString('AA_APIKEY_ISDSTEST_NSZM_SITE, AA_APIKEY_ISDSTEST_NSZM_SLICES, AA_APIKEY_ISDSTEST_NSZM_LOGIN, AA_APIKEY_ISDSTEST_NSZM_PASSWORD', $finding->advice);
        self::assertNoSecret($findings, ['short-1']);
    }

    // without its site the slices are still checked; without its slices there is nothing to check
    public function test_findings_accountWithoutSiteOrSlices(): void
    {
        $check = self::check(self::account(site: null));
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, ['reading_password' => '']);

        self::assertSame(['account:test:NSZM', 'slices:test:NSZM'], self::keys($check->findings()));

        $constants = self::account();
        unset($constants['AA_APIKEY_ISDSTEST_NSZM_SLICES']);
        $findings = self::check($constants)->findings();
        self::assertSame(['account:test:NSZM'], self::keys($findings));
        self::assertStringContainsString('Set AA_APIKEY_ISDSTEST_NSZM_SLICES in config.php', $findings[0]->evidence[0]['Problem']);
        self::assertNoSecret($findings);
    }

    public function test_findings_straysNamed(): void
    {
        $findings = self::check(self::account() + ['AA_APIKEY_ISDSTEST_LOGIN' => 'stray-value-1', 'AA_APIKEY_ISDSTEST_Nszm_PASSWORD' => 'stray-value-2'])->findings();

        self::assertSame([Finding::WARNING], self::severities($findings));
        $finding = $findings[0];
        self::assertSame('include/config.php has ISDS constants AA does not read: 2', $finding->title);
        self::assertSame([2, 'strays'], [$finding->count, $finding->key]);
        self::assertSame([['Constant' => 'AA_APIKEY_ISDSTEST_LOGIN'], ['Constant' => 'AA_APIKEY_ISDSTEST_Nszm_PASSWORD']], $finding->evidence);
        self::assertStringContainsString('AA_APIKEY_ISDSTEST_<ACCOUNT>_SITE, AA_APIKEY_ISDSTEST_<ACCOUNT>_SLICES, AA_APIKEY_ISDSTEST_<ACCOUNT>_LOGIN, AA_APIKEY_ISDSTEST_<ACCOUNT>_PASSWORD', $finding->advice);
        self::assertStringContainsString('production ones start with AA_APIKEY_ISDS_.', $finding->advice);
        self::assertNoSecret($findings, ['stray-value-1', 'stray-value-2']);
    }

    // production cannot send yet: its account and its slices are warnings
    public function test_findings_productionAccountWarns(): void
    {
        $check = self::check(self::account(environment: 'ISDS'));
        $check->rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '']);

        $findings = $check->findings();

        self::assertSame([Finding::WARNING, Finding::WARNING], self::severities($findings));
        self::assertSame('ISDS account AA_APIKEY_ISDS_NSZM cannot be used: AA cannot log in to production ISDS yet', $findings[0]->title);
        self::assertSame(['account:production:NSZM', 'slices:production:NSZM'], self::keys($findings));
        self::assertStringContainsString('Remove AA_APIKEY_ISDS_NSZM_PASSWORD', implode(' ', array_column($findings[0]->evidence, 'Problem')));
        self::assertNoSecret($findings);
    }

    public function test_findings_sliceProblemWithItsPage(): void
    {
        $check = self::check(self::account());
        $check->rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '0']);
        $check->rules->outlets[self::CAMPAIGN] = ['notify'];
        $check->rules->names[self::CAMPAIGN]   = 'Rozesílky <b>NSZM</b>';

        $findings = $check->findings();

        self::assertSame([Finding::ERROR], self::severities($findings));
        $finding = $findings[0];
        self::assertSame('The slices of ISDS account AA_APIKEY_ISDSTEST_NSZM are not ready for sending', $finding->title);
        self::assertSame(['slices:test:NSZM', 2, 'Rozesílky <b>NSZM</b>'], [$finding->key, $finding->count, $finding->place]);
        self::assertSame(safe('Slice "Rozesílky <b>NSZM</b>" has no reading password - fill in Password for Reading in the settings of the slice'), $finding->evidence[0]['Problem']);
        self::assertSame('Fix what the evidence names before sending from these slices.', $finding->advice);
        self::assertSame(['Slice settings: Rozesílky <b>NSZM</b>', 'Email Notification: Rozesílky <b>NSZM</b>'], array_keys($finding->links));
        self::assertStringContainsString(SliceRules::SLICE_PAGE . '?change_id=' . self::CAMPAIGN, $finding->links['Slice settings: Rozesílky <b>NSZM</b>']);
        self::assertStringContainsString(SliceRules::NOTIFY_PAGE . '?change_id=' . self::CAMPAIGN, $finding->links['Email Notification: Rozesílky <b>NSZM</b>']);
    }

    public function test_findings_openSliceOfAnUnusableAccount(): void
    {
        $check = self::check(self::account(login: null));
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, ['permit_anonymous_post' => '1']);

        $findings = $check->findings();

        self::assertSame([Finding::ERROR, Finding::ERROR], self::severities($findings));
        self::assertSame(['account:test:NSZM', 'slices:test:NSZM'], self::keys($findings));
        self::assertStringContainsString('Allow anonymous posting of items: Not allowed', $findings[1]->evidence[0]['Problem']);
    }

    // a slice's own problem once, a site's for each site; two sites of one name told apart
    public function test_findings_twoSitesOfOneName(): void
    {
        $check = self::check(self::account(site: self::SITE . ',' . self::SITE2));
        $check->rules->sites[]              = self::SITE2;
        $check->rules->names[self::SITE2]   = 'Terminal';
        $check->rules->slices[self::MESSAGE] = FakeSliceRules::sliceOf(self::MESSAGE, false, without: ['switch.........1']);

        $finding = $check->findings()[0];

        self::assertSame([
            ['Problem' => 'Site &quot;Terminal&quot; does not use slice &quot;ISDS campaigns&quot; - add the slice to Uses modules in the settings of the site'],
            ['Problem' => 'Site &quot;Terminal&quot; does not use slice &quot;ISDS messages&quot; - add the slice to Uses modules in the settings of the site'],
            ['Problem' => 'Slice &quot;ISDS messages&quot; lacks fields of a message slice: switch.........1'],
        ], $finding->evidence);
        self::assertSame(['Site settings: Terminal', 'Fields: ISDS messages'], array_keys($finding->links));
        self::assertStringContainsString('change_id=' . self::SITE2, $finding->links['Site settings: Terminal']);

        $check->rules->related[self::SITE] = [];
        $links = $check->findings()[0]->links;
        self::assertSame(['Site settings: Terminal (51de0000)', 'Site settings: Terminal (5e7e0000)', 'Fields: ISDS messages'], array_keys($links));
        self::assertStringContainsString('change_id=' . self::SITE, $links['Site settings: Terminal (51de0000)']);
    }

    // ------------------------------------------------------------ helpers

    /** @param array<string, string> $constants */
    private static function check(array $constants): ScriptedIsdsAccountsCheck
    {
        $check            = new ScriptedIsdsAccountsCheck(FakeSliceRules::ready(self::SITE, self::CAMPAIGN, self::MESSAGE));
        $check->constants = $constants;

        return $check;
    }

    /** @return array<string, string> the constants of an account; null leaves one out */
    private static function account(?string $site = self::SITE, ?string $login = self::LOGIN, ?string $password = self::PASSWORD, string $environment = 'ISDSTEST'): array
    {
        return array_filter([
            "AA_APIKEY_{$environment}_NSZM_SITE"     => $site,
            "AA_APIKEY_{$environment}_NSZM_SLICES"   => self::CAMPAIGN . ',' . self::MESSAGE,
            "AA_APIKEY_{$environment}_NSZM_LOGIN"    => $login,
            "AA_APIKEY_{$environment}_NSZM_PASSWORD" => $password,
        ], static fn(?string $v): bool => !is_null($v));
    }

    /**
     * @param Finding[]    $findings
     * @param list<string> $more other values that must not show
     */
    private static function assertNoSecret(array $findings, array $more = []): void
    {
        $all = serialize($findings);
        foreach ([self::PASSWORD, self::LOGIN, ...$more] as $secret) {
            self::assertStringNotContainsString($secret, $all);
        }
    }

    /**
     * @param Finding[] $findings
     * @return list<string>
     */
    private static function severities(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->severity, array_values($findings));
    }

    /**
     * @param Finding[] $findings
     * @return list<string>
     */
    private static function keys(array $findings): array
    {
        return array_map(static fn(Finding $f): string => $f->key, array_values($findings));
    }
}
