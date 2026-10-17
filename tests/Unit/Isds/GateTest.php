<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Isds\Access;
use AA\Isds\Account;
use AA\Isds\Gate;
use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Setup;
use AA\Isds\SetupException;
use AA\Util\FormToken;
use AA_Credentials;
use AA_Perm;
use AA_Stringexpand_Encrypt;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tests\Unit\Isds\Fixtures\FakeGate;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\FakeRecipients;
use Tests\Unit\Isds\Fixtures\FakeRegisterIndex;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\Sending;

// data providers read its ids before setUpBeforeClass()
require_once __DIR__ . '/fixtures/Sending.php';

/**
 * {datovkabulk:page}: the deferred UI and the two slices open only on a page of the bound site for
 * whoever AA lets send; for anyone else the UI stays unexpanded and the slices closed. An opened slice
 * never goes into the token {ajax} carries.
 */
final class GateTest extends TestCase
{
    private const SITE     = Sending::SITE;
    private const SITE2    = Sending::SITE2;
    private const CAMPAIGN = Sending::CAMPAIGN;
    private const MESSAGE  = Sending::MESSAGE;
    private const SOURCE   = Sending::SOURCE;
    private const READER   = Sending::READER;
    private const ITEM     = 'ca00000000000000000000000000000b';   // a campaign - an item of the campaign slice
    private const UI       = '<form>{view:history}</form>';
    private const REFUSAL  = '<p>Only the office sends letters.</p>';
    private const LETTER   = 'be00000000000000000000000000000d';   // a message - an item of the message slice

    // items of the source slice
    private const ADAMOV  = 'a0000000000000000000000000000001';
    private const BENESOV = 'b0000000000000000000000000000002';
    private const CHEB    = 'c0000000000000000000000000000003';
    private const DECIN   = 'd0000000000000000000000000000004';
    private const BRNO    = 'e0000000000000000000000000000005';

    /** @var list<string> the UI each expansion got */
    private array $expanded = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/fixtures/FakeSliceRules.php';
        require_once __DIR__ . '/fixtures/FakePerms.php';
        require_once __DIR__ . '/fixtures/FakeGate.php';
        require_once __DIR__ . '/fixtures/FakeRecipients.php';
        require_once __DIR__ . '/fixtures/FakeRegisterIndex.php';
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode()
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);                       // AA::Pagecache()
        }
    }

    /** the office: readers in a set the campaign slice grants the editor's role */
    protected function setUp(): void
    {
        FakeRecipients::forget();
        FakePerms::install([Sending::SET => [self::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]], [self::READER => [Sending::READERS, Sending::SET]]);
    }

    /** what a test changed of the request stays in it */
    protected function tearDown(): void
    {
        FakeSliceRules::forget();
        FakePerms::uninstall();
        Closure::bind(function (): void {
            foreach (get_object_vars($this) as $name => $value) {
                if (is_array($value)) {
                    $this->$name = [];
                }
            }
        }, AA_Credentials::singleton(), AA_Credentials::class)();
        (new ReflectionProperty(AA::class, 'pagecache'))->setValue(null, null);
        AA::Contentcache()->clear();
        FakeRecipients::forget();
        Gate::report('');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id('');
        }
    }

    /** cities and districts offered, cities chosen at first, members in Sending::MEMBER */
    private static function settings(): string
    {
        return (string) json_encode(['campaign_slice' => self::CAMPAIGN, 'message_slice' => self::MESSAGE, 'source_slice' => self::SOURCE, 'types' => ['mesto' => 'Cities', 'mestocast' => 'City districts'], 'default_types' => ['mesto'], 'member_field' => Sending::MEMBER]);
    }

    /** the slices of SITE, each closed by a password of its own; include/config.php binds them to SITE */
    private static function rules(): FakeSliceRules
    {
        $rules                         = FakeSliceRules::ready(self::SITE, self::CAMPAIGN, self::MESSAGE)->withSource(self::SITE, self::SOURCE);
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => AA_Credentials::encrypt('gate-campaign')]);
        $rules->slices[self::MESSAGE]  = FakeSliceRules::sliceOf(self::MESSAGE, false, ['reading_password' => AA_Credentials::encrypt('gate-message')]);

        return $rules;
    }

    /** @param array<string, string>|null $constants */
    private static function gate(?string $site = self::SITE, string $login = self::READER, ?FakeSliceRules $rules = null, ?array $constants = null): FakeGate
    {
        return new FakeGate($site, $rules ?? self::rules(), $constants ?? Sending::bound(), FakePerms::login($login));
    }

    /** what the gate gives the page, the UI recorded as the template would expand it */
    private function render(Gate $gate, string $settings = ''): string
    {
        return $gate->page(($settings === '') ? self::settings() : $settings, self::UI, self::REFUSAL, function (string $ui): string {
            $this->expanded[] = $ui;

            return "<main>$ui</main>";
        });
    }

    /** what the UI gets from $inside, run inside the UI of the page */
    private static function inside(Gate $gate, Closure $inside): mixed
    {
        $got = null;
        $gate->page(self::settings(), self::UI, self::REFUSAL, static function () use ($inside, &$got): string {
            $got = $inside();

            return '';
        });

        return $got;
    }

    /** nothing of the two slices is open to this request, nor to a request a token of it would make */
    private function assertLocked(): void
    {
        self::assertFalse(AA_Credentials::singleton()->checkSlice(self::CAMPAIGN), 'the campaign slice is locked');
        self::assertFalse(AA_Credentials::singleton()->checkSlice(self::MESSAGE), 'the message slice is locked');
        $this->assertNoPasswordInToken();
    }

    private function assertNoPasswordInToken(): void
    {
        self::assertSame(['spwd' => []], AA_Stringexpand_Encrypt::decrypt_time_token(AA_Credentials::singleton()->getAsToken(), 'slice-pwd'));
    }

    /** @return array<string, string> name => value of the hidden inputs */
    private static function inputs(string $html): array
    {
        preg_match_all('/<input type=hidden name="([^"]*)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER);
        self::assertSame($html, implode('', array_column($m, 0)), 'hidden inputs only');

        return array_combine(array_column($m, 1), array_map('htmlspecialchars_decode', array_column($m, 2)));
    }

    /** @return array<string, array{\Closure(): FakeGate}> */
    public static function refused(): array
    {
        // roles as AA_Perm::ROLES stores them: '1' author, '2' editor, '3' administrator
        $perms = static fn(array $grants, array $groups): FakeGate => (FakePerms::install($grants, $groups) ? self::gate() : self::gate());

        return [
            'not logged in'                 => [static fn(): FakeGate => self::gate(login: '')],
            'nobody'                        => [static fn(): FakeGate => self::gate(login: 'nobody')],
            'view.php, slice.php, cron'     => [static fn(): FakeGate => self::gate(site: null)],
            'reader outside the set'        => [static fn(): FakeGate => $perms([Sending::SET => [self::CAMPAIGN => '2']], [self::READER => [Sending::READERS]])],
            'reader with a lower own grant' => [static fn(): FakeGate => $perms([Sending::SET => [self::CAMPAIGN => '2'], self::READER => [self::CAMPAIGN => '1']], [self::READER => [Sending::READERS, Sending::SET]])],
            'user of AA without the right'  => [static fn(): FakeGate => self::gate(login: Sending::USER)],
            'right on another slice'        => [static fn(): FakeGate => $perms([Sending::SET => [self::MESSAGE => '2', self::SOURCE => '3']], [self::READER => [Sending::SET]])],
        ];
    }

    /** @param \Closure(): FakeGate $gate */
    #[DataProvider('refused')]
    public function test_page_refused(\Closure $gate): void
    {
        self::assertSame(self::REFUSAL, $this->render($gate()));
        self::assertSame([], $this->expanded, 'the UI is never expanded');
        $this->assertLocked();
    }

    /**
     * Settings the site cannot use - whoever may send learns only how include/config.php binds an account
     * @return array<string, array{\Closure(string): FakeGate}>
     */
    public static function notBound(): array
    {
        $rules = static function (): FakeSliceRules {
            $rules                       = self::rules();
            $rules->sites[]              = self::SITE2;
            $rules->related[self::SITE2] = [self::CAMPAIGN, self::MESSAGE, self::SOURCE];

            return $rules;
        };

        return [
            'template of another site'       => [static fn(string $login): FakeGate => self::gate(site: self::SITE2, login: $login, rules: $rules())],
            'slices not bound in config.php' => [static fn(string $login): FakeGate => self::gate(login: $login, constants: Sending::bound(self::SITE, self::CAMPAIGN . ',' . self::SOURCE))],
            'no account in config.php'       => [static fn(string $login): FakeGate => self::gate(login: $login, constants: [])],
        ];
    }

    /** @param \Closure(string): FakeGate $gate */
    #[DataProvider('notBound')]
    public function test_page_notBound(\Closure $gate): void
    {
        self::assertSame(self::REFUSAL, $this->render($gate(Sending::USER)), 'who may not send');
        $office = $this->render($gate(self::READER));
        self::assertStringContainsString('No usable ISDS account in include/config.php is bound to this site and these slices.', $office);
        self::assertStringNotContainsString('ISDS campaigns', $office);
        self::assertSame([], $this->expanded, 'the UI is never expanded');
        $this->assertLocked();
    }

    public function test_page_readerThroughTheSetGetsTheUi(): void
    {
        $gate = self::gate();

        self::assertSame('<main>' . self::UI . '</main>', $this->render($gate));
        self::assertSame([self::UI], $this->expanded, 'once');
        self::assertSame(1, $gate->noStores, 'the page differs by the user - never into the page cache');
        self::assertTrue(AA_Credentials::singleton()->checkSlice(self::CAMPAIGN));
        self::assertTrue(AA_Credentials::singleton()->checkSlice(self::MESSAGE));
        self::assertTrue((new ReflectionProperty(\AA\Cache\PageCache::class, 'cache_nostore'))->getValue(AA::Pagecache()), 'the slices opened keep it out too');
        $this->assertNoPasswordInToken();
    }

    public function test_page_userOfAaWhoEditsAllItems(): void
    {
        FakePerms::install([Sending::USER => [self::CAMPAIGN => PS_EDIT_ALL_ITEMS]]);

        self::assertSame('<main>' . self::UI . '</main>', $this->render(self::gate(login: Sending::USER)));
    }

    public function test_subcommands_onlyInsideTheUi(): void
    {
        $gate   = self::gate();
        $inside = self::inside($gate, static fn(): array => [$gate->env(), $gate->sender(), $gate->fields('save'), $gate->result()]);

        self::assertSame(Account::TEST, $inside[0]);
        self::assertSame('', $inside[1], 'a login with a name and a password does not tell the box');
        self::assertSame('save', self::inputs($inside[2])['isds_action']);
        self::assertSame(['', '', '', ''], [$gate->env(), $gate->sender(), $gate->fields('save'), $gate->result()], 'outside');
    }

    public function test_subcommands_escaped(): void
    {
        self::rules();      // opening asks the slices for their reading password - never the database
        $gate = new class (FakePerms::login(self::READER)) extends Gate {
            public function __construct(private Access $login) {}

            protected function site(): ?string
            {
                return Sending::SITE;
            }

            protected function noStore(): void {}

            protected function setup(string $settings, string $site): Setup|SetupException
            {
                return Setup::fromSnapshot(Sending::SITE, Sending::CAMPAIGN, Sending::MESSAGE, Sending::SOURCE, ['mesto' => 'Cities'], environment: '<test>', senderBox: '"box"');
            }

            protected function access(): Access
            {
                return $this->login;
            }
        };

        self::assertSame(['&lt;test&gt;', '&quot;box&quot;'], self::inside($gate, static fn(): array => [$gate->env(), $gate->sender()]));
    }

    public function test_subcommands_closedAfterAFailedUi(): void
    {
        $gate = self::gate();
        try {
            $gate->page(self::settings(), self::UI, self::REFUSAL, static fn(): string => throw new \RuntimeException('broken view'));
            self::fail('the UI throws');
        } catch (\RuntimeException) {
        }

        self::assertSame('', $gate->env());
    }

    public function test_subcommands_innerGateLeavesTheOuterOpen(): void
    {
        $gate = self::gate();

        self::assertSame(Account::TEST, self::inside($gate, static function () use ($gate): string {
            $gate->page(self::settings(), self::UI, self::REFUSAL, static fn(): string => '');

            return $gate->env();
        }));
    }

    public function test_fields_tokenBindsTheActionTheCampaignAndItsSlice(): void
    {
        session_id('gate-test-session');
        $gate   = self::gate();
        $inputs = self::inputs(self::inside($gate, static fn(): string => $gate->fields('send', strtoupper(self::ITEM))));

        self::assertSame(['isds_action', 'isds_campaign', 'isds_token'], array_keys($inputs));
        self::assertSame(['send', self::ITEM], [$inputs['isds_action'], $inputs['isds_campaign']]);
        self::assertTrue(FormToken::check(Gate::purpose('send', self::CAMPAIGN, self::ITEM), $inputs['isds_token']));
        self::assertFalse(FormToken::check(Gate::purpose('stop', self::CAMPAIGN, self::ITEM), $inputs['isds_token']), 'another action');
        self::assertFalse(FormToken::check(Gate::purpose('send', self::CAMPAIGN, self::MESSAGE), $inputs['isds_token']), 'another campaign');
        self::assertFalse(FormToken::check(Gate::purpose('send', self::MESSAGE, self::ITEM), $inputs['isds_token']), 'the campaign slice of another page');
    }

    public function test_fields_newCampaignOnlyToSave(): void
    {
        session_id('gate-test-session');
        $gate   = self::gate();
        $fields = self::inside($gate, static fn(): array => [$gate->fields('save'), $gate->fields('send'), $gate->fields('clone', '')]);

        self::assertTrue(FormToken::check(Gate::purpose('save', self::CAMPAIGN, ''), self::inputs($fields[0])['isds_token']));
        self::assertSame(['', ''], [$fields[1], $fields[2]]);
    }

    public function test_fields_unknownActionOrCampaign(): void
    {
        $gate = self::gate();

        self::assertSame(['', '', '', ''], self::inside($gate, static fn(): array => [$gate->fields('delete', self::ITEM), $gate->fields('send', '12" onclick="x'), $gate->fields('', self::ITEM), $gate->fields('save', 'x')]));
    }

    public function test_result_escapedInsideTheUiOnly(): void
    {
        $gate = self::gate();
        Gate::report('Sent to <b>5</b> boxes');

        self::assertSame('Sent to &lt;b&gt;5&lt;/b&gt; boxes', self::inside($gate, static fn(): string => $gate->result()));
        self::assertSame('', $gate->result());
    }

    // State Check and the log tell the administrator; a reader learns nothing of the setup
    public function test_page_notReadyRefusedToWhoCannotFixIt(): void
    {
        $rules                         = self::rules();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '']);
        $gate                          = self::gate(login: Sending::USER, rules: $rules);

        self::assertSame(self::REFUSAL, $this->render($gate));
        self::assertSame([], $this->expanded);
        self::assertSame([[self::SITE . ':' . self::CAMPAIGN, 'Slice "ISDS campaigns" has no reading password - fill in Password for Reading in the settings of the slice']], $gate->logged);
        self::assertFalse(AA_Credentials::singleton()->checkSlice(self::MESSAGE));
        $this->assertNoPasswordInToken();
    }

    public function test_page_notReadyTellsWhoCanFixIt(): void
    {
        $rules                         = self::rules();
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => '']);
        $rules->names[self::CAMPAIGN]  = 'Campaigns <script>x</script>';

        $out = $this->render(self::gate(rules: $rules));

        self::assertStringStartsWith('<div class="aa-isds-setup" role="alert"><p>The mass sending on this page is not ready - fix this first:</p><ul><li>Slice &quot;Campaigns &lt;script&gt;x&lt;/script&gt;&quot; has no reading password', $out);
        self::assertStringContainsString('change_id=' . self::CAMPAIGN, $out, 'a link to the page that fixes it');
        self::assertStringContainsString('>Slice settings: Campaigns &lt;script&gt;x&lt;/script&gt;</a>', $out);
        self::assertStringNotContainsString('<script>', $out);
        self::assertSame([], $this->expanded);
        self::assertFalse(AA_Credentials::singleton()->checkSlice(self::MESSAGE));
        $this->assertNoPasswordInToken();
    }

    // perm_mode 1 or 2: site.php restricts AA::$perm to the site's readers, whom no right is stored for -
    // nobody sends, nobody learns why on the page; the log and State Check tell it
    public function test_page_siteLettingOnlyReadersIn(): void
    {
        $rules                        = self::rules();
        $rules->permModes[self::SITE] = '1';
        FakePerms::install([], [self::READER => [Sending::READERS, Sending::SET]]);

        foreach ([self::READER, Sending::USER, 'coder'] as $login) {
            $gate = self::gate(login: $login, rules: $rules);
            self::assertSame(self::REFUSAL, $this->render($gate), $login);
            self::assertSame([[self::SITE . ':' . self::CAMPAIGN, 'Site "Terminal" lets only readers of its Reader Management slices log in, so nobody gets the right to send - set User login databases to "AA + all Reader Management slices in AA (old behavior)" in the settings of the site']], $gate->logged);
        }
        self::assertSame([], $this->expanded);
        $this->assertLocked();
    }

    public function test_page_brokenSettingsTellWhoCanFixIt(): void
    {
        FakePerms::install(['coder' => [self::SITE => PS_MODW_EDIT_CODE]]);
        $gate = self::gate(login: 'coder');
        $out  = $this->render($gate, '{"campaign_slice":"<b>"}');

        self::assertStringContainsString('<li>Set campaign_slice to the id of a slice - 32 hexadecimal digits</li>', $out);
        self::assertStringContainsString('<li>Set message_slice to the id of a slice - 32 hexadecimal digits</li>', $out);
        self::assertStringNotContainsString('Where to fix it', $out, 'no page fixes a template');
        self::assertSame([[self::SITE . ':-', 'Set campaign_slice to the id of a slice - 32 hexadecimal digits']], $gate->logged);
        $this->assertLocked();
    }

    // ------------------------------------------------------------ recipients

    /**
     * The gate with the territory of the source slice: Benešov has no box, Děčín the box of Adamov
     * @param array<string, string|array{slice: string, active: bool, filter: string}> $campaigns item id => the
     *        filter of an active item of the campaign slice, or what stored() gives
     * @param ?RegisterIndex $register null = one with every box of the territory, none with an IČO
     */
    private static function recipientGate(array $campaigns, ?RegisterIndex $register = null): FakeGate
    {
        $gate            = self::gate();
        $gate->index     = $register ?? new FakeRegisterIndex(RegisterIndex::OK, ['ada1234' => ['', 'Město Adamov'], 'che1234' => ['', 'Město Cheb'], 'brn1234' => ['', 'Statutární město Brno']]);
        $gate->campaigns = array_map(static fn(string|array $c): array => is_string($c) ? ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => $c] : $c, $campaigns);
        $gate->resolver  = new FakeRecipients([
            self::ADAMOV  => Sending::item('Adamov', 'mesto', '1', 'ada1234'),
            self::BENESOV => Sending::item('Benešov', 'mesto', '1', ''),
            self::CHEB    => Sending::item('Cheb', 'mesto', '0', 'che1234'),
            self::DECIN   => Sending::item('Děčín', 'mestocast', '0', 'ADA1234'),
            self::BRNO    => Sending::item('Brno', 'mestocast', '1', 'brn1234'),
        ]);

        return $gate;
    }

    /** @return list<string> what the UI gets for the campaign: count, skipped, ids */
    private static function counts(FakeGate $gate, string $campaign): array
    {
        return self::inside($gate, static fn(): array => [$gate->recipients($campaign, 'count'), $gate->recipients($campaign, 'skipped'), $gate->recipients($campaign, 'ids')]);
    }

    // members of the cities plus two districts: Benešov has no box, Děčín shares the box of Adamov
    public function test_recipients_byTheStoredFilter(): void
    {
        $gate = self::recipientGate([self::ITEM => Sending::filter(['membership' => 'members', 'include' => [self::BRNO, strtoupper(self::DECIN)]])]);

        self::assertSame(['2', '2', self::ADAMOV . '-' . self::BRNO], self::counts($gate, strtoupper(self::ITEM)));
        self::assertCount(1, FakeRecipients::$read, 'the three answers of one page resolve once');
        self::assertSame('', $gate->recipients(self::ITEM, 'count'), 'outside the UI');
    }

    public function test_recipients_defaultFilterWhenNoneStored(): void
    {
        self::assertSame(['2', '1', self::ADAMOV . '-' . self::CHEB], self::counts(self::recipientGate([self::ITEM => " \n"]), self::ITEM));
    }

    /** @return array<string, array{string, string, array<string, string|array{slice: string, active: bool, filter: string}>}> */
    public static function notACampaign(): array
    {
        $stored = [self::ITEM => ''];

        return [
            'not an id'              => ['12" onclick="x', 'count', $stored],
            'no such item'           => [self::ITEM, 'count', []],
            'an item of messages'    => [self::LETTER, 'count', [self::LETTER => ['slice' => self::MESSAGE, 'active' => true, 'filter' => '']]],
            'an item of the source'  => [self::ADAMOV, 'count', [self::ADAMOV => ['slice' => self::SOURCE, 'active' => true, 'filter' => '']]],
            'a campaign in a bin'    => [self::ITEM, 'count', [self::ITEM => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '']]],
            // a read without the password masks the status code too - the item is in no bin
            'a campaign read masked' => [self::ITEM, 'count', [self::ITEM => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => 'Error: Missing Reading Password']]],
            'unknown question'       => [self::ITEM, 'all', $stored],
        ];
    }

    /** @param array<string, string|array{slice: string, active: bool, filter: string}> $campaigns */
    #[DataProvider('notACampaign')]
    public function test_recipients_notACampaignOfThePage(string $campaign, string $what, array $campaigns): void
    {
        $gate = self::recipientGate($campaigns);

        self::assertSame('', self::inside($gate, static fn(): string => $gate->recipients($campaign, $what)));
        self::assertSame([[], []], [FakeRecipients::$read, $gate->recipientsLogged]);
    }

    /** @return array<string, array{string, string}> */
    public static function brokenFilter(): array
    {
        return [
            'type not offered' => [Sending::filter(['types' => ['kraj']]), 'The page does not offer the types kraj'],
            'broken JSON'      => ['{"types":', 'The recipient filter is a JSON object of types, membership, include, exclude'],
        ];
    }

    // nothing for the page, the reason once to the log - the three answers share one failure
    #[DataProvider('brokenFilter')]
    public function test_recipients_brokenFilterLogged(string $filter, string $problem): void
    {
        $gate = self::recipientGate([self::ITEM => $filter]);

        self::assertSame(['', '', ''], self::counts($gate, self::ITEM));
        self::assertSame([[self::SITE . ':' . self::CAMPAIGN . ':' . self::ITEM, $problem]], $gate->recipientsLogged);
        self::assertSame([], $gate->logged, 'not a problem of the settings');
    }

    // ------------------------------------------------------------ the register of data boxes

    /** @return array<string, array{string}> */
    public static function registerStates(): array
    {
        return [
            'ok'         => [RegisterIndex::OK],
            'old'        => [RegisterIndex::OLD],
            'missing'    => [RegisterIndex::MISSING],
            'unreadable' => [RegisterIndex::UNREADABLE],
        ];
    }

    // looked at once a request, by the preview and register() alike, items stored meanwhile or not; a
    // new request looks again
    public function test_registerIndex_oneARequest(): void
    {
        $gate  = new class extends Gate {
            public function index(): RegisterIndex
            {
                return $this->registerIndex();
            }
        };
        $index = $gate->index();

        self::assertSame($index, $gate->index());
        AA::Contentcache()->clear_soft();
        self::assertSame($index, $gate->index(), 'an item stored');
        AA::Contentcache()->clear();
        self::assertNotSame($index, $gate->index());
    }

    #[DataProvider('registerStates')]
    public function test_register_states(string $state): void
    {
        $gate        = self::gate();
        $gate->index = new FakeRegisterIndex($state);

        self::assertSame($state, self::inside($gate, static fn(): string => $gate->register()));
        self::assertSame('', $gate->register(), 'outside');
    }

    /** @return array<string, array{string}> */
    public static function unusableRegister(): array
    {
        return [
            'missing'    => [RegisterIndex::MISSING],
            'unreadable' => [RegisterIndex::UNREADABLE],
        ];
    }

    // no count is true unchecked - {datovkabulk:register} tells the page why, and it is no fault of the campaign
    #[DataProvider('unusableRegister')]
    public function test_recipients_withoutRegisterIsEmpty(string $state): void
    {
        $register = new FakeRegisterIndex($state);
        $gate     = self::recipientGate([self::ITEM => Sending::filter()], $register);

        self::assertSame(['', '', ''], self::counts($gate, self::ITEM));
        self::assertSame([[], [], []], [FakeRecipients::$read, $register->lookups, $gate->recipientsLogged], 'nothing resolved, nothing logged');
    }

    // the index replaced while the request reads it, and not made again
    public function test_recipients_registerFailingWhileReadIsNoFaultOfTheCampaign(): void
    {
        $gate = self::recipientGate([self::ITEM => Sending::filter()], new FakeRegisterIndex(RegisterIndex::OK, [], true));

        self::assertSame(['', RegisterIndex::UNREADABLE], self::inside($gate, static fn(): array => [$gate->recipients(self::ITEM, 'count'), $gate->register()]));
        self::assertSame([], $gate->recipientsLogged);
    }

    // downloaded over a week ago: the page warns, yet counts against it
    public function test_recipients_oldRegisterStillCounts(): void
    {
        $register = new FakeRegisterIndex(RegisterIndex::OLD, ['ada1234' => ['', 'Město Adamov'], 'brn1234' => ['', 'Statutární město Brno']]);
        $gate     = self::recipientGate([self::ITEM => Sending::filter(['types' => ['mesto', 'mestocast']])], $register);

        // Benešov has no box, Děčín shares the box of Adamov, the box of Cheb is outside the register
        self::assertSame(['2', '3', self::ADAMOV . '-' . self::BRNO], self::counts($gate, self::ITEM));
    }
}
