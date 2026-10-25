<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Isds\Access;
use AA\Isds\Account;
use AA\Isds\ActionResult;
use AA\Isds\Actions;
use AA\Isds\DatovkaConf;
use AA\Isds\Gate;
use AA\Isds\SendResult;
use AA\Isds\SendRun;
use AA\Isds\SendStart;
use AA\Isds\Outcome;
use AA\Isds\Setup;
use AA\Isds\SetupException;
use AA\Isds\TestResult;
use AA\Later\BatchFailed;
use AA\Util\FormToken;
use AA_Credentials;
use AA_Perm;
use AA_Stringexpand_Encrypt;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Tests\Unit\Isds\Fixtures\FakeActions;
use Tests\Unit\Isds\Fixtures\FakeGate;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\FakeRecipients;
use Tests\Unit\Isds\Fixtures\FakeSliceRules;
use Tests\Unit\Isds\Fixtures\Redirected;
use Tests\Unit\Isds\Fixtures\Sending;

// data providers read its ids before setUpBeforeClass()
require_once __DIR__ . '/fixtures/Sending.php';

/**
 * {datovkabulk:page}: the deferred UI and the two slices open only on a page of the bound site for
 * whoever AA lets send; for anyone else the UI stays unexpanded and the slices closed. An opened slice
 * never goes into the token {ajax} carries. An open gate runs the action its form posts before the UI,
 * then sends the browser to the page with the key of the result; no other site may frame it.
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

    /** @var array<string, mixed> $_SERVER before the test */
    private array $server = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/Registry/fixtures/PropertySlice.php';
        require_once __DIR__ . '/fixtures/FakeSliceRules.php';
        require_once __DIR__ . '/fixtures/FakePerms.php';
        require_once __DIR__ . '/fixtures/FakeGate.php';
        require_once __DIR__ . '/fixtures/FakeRecipients.php';
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
        FakeActions::reset();
        $this->server = $_SERVER;
        $_SESSION     = [];
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
        FakeActions::reset();
        AA::$headers = [];
        $_SERVER     = $this->server;
        $_POST       = [];
        $_GET        = [];
        $_SESSION    = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id('');
        }
    }

    /** cities and districts offered, cities chosen at first, members in Sending::MEMBER */
    private static function settings(): string
    {
        return (string) json_encode(['campaign_slice' => self::CAMPAIGN, 'message_slice' => self::MESSAGE, 'source_slice' => self::SOURCE, 'types' => ['mesto' => 'Cities', 'mestocast' => 'City districts'], 'default_types' => ['mesto'], 'member_field' => Sending::MEMBER]);
    }

    /** the slices of SITE, each closed by a password of its own; datovka_conf.php binds them to SITE */
    private static function rules(): FakeSliceRules
    {
        $rules                         = FakeSliceRules::ready(self::SITE, self::CAMPAIGN, self::MESSAGE)->withSource(self::SITE, self::SOURCE);
        $rules->slices[self::CAMPAIGN] = FakeSliceRules::sliceOf(self::CAMPAIGN, true, ['reading_password' => AA_Credentials::encrypt('gate-campaign')]);
        $rules->slices[self::MESSAGE]  = FakeSliceRules::sliceOf(self::MESSAGE, false, ['reading_password' => AA_Credentials::encrypt('gate-message')]);

        return $rules;
    }

    private static function gate(?string $site = self::SITE, string $login = self::READER, ?FakeSliceRules $rules = null, ?DatovkaConf $conf = null): FakeGate
    {
        return new FakeGate($site, $rules ?? self::rules(), $conf ?? Sending::bound(), FakePerms::login($login));
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
     * Settings the site cannot use - whoever may send learns only how datovka_conf.php binds an account
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
            'slices not bound in the file'   => [static fn(string $login): FakeGate => self::gate(login: $login, conf: Sending::bound(self::SITE, [self::CAMPAIGN, self::SOURCE]))],
            'no account in the file'         => [static fn(string $login): FakeGate => self::gate(login: $login, conf: Sending::conf([]))],
        ];
    }

    /** @param \Closure(string): FakeGate $gate */
    #[DataProvider('notBound')]
    public function test_page_notBound(\Closure $gate): void
    {
        self::assertSame(self::REFUSAL, $this->render($gate(Sending::USER)), 'who may not send');
        $office = $this->render($gate(self::READER));
        self::assertStringContainsString('No usable ISDS account in misc/datovka/datovka_conf.php is bound to this site and these slices.', $office);
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

    public function test_fields_purposeBindsBinding(): void
    {
        session_id('gate-test-session');
        $gate   = self::gate();
        $inputs = self::inputs(self::inside($gate, static fn(): string => $gate->fields('send', strtoupper(self::ITEM))));
        $page   = self::page();
        $other  = static fn(string $site, string $message, string $environment): Setup => Setup::fromSnapshot($site, self::CAMPAIGN, $message, self::SOURCE, ['mesto' => 'Cities'], environment: $environment);

        self::assertSame(['isds_action', 'isds_campaign', 'isds_token', 'isds_form'], array_keys($inputs));
        self::assertSame(['send', self::ITEM], [$inputs['isds_action'], $inputs['isds_campaign']]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}\z/', $inputs['isds_form']);
        self::assertTrue(FormToken::check(Gate::purpose('send', $page, self::ITEM), $inputs['isds_token']));
        self::assertFalse(FormToken::check(Gate::purpose('stop', $page, self::ITEM), $inputs['isds_token']), 'another action');
        self::assertFalse(FormToken::check(Gate::purpose('send', $page, self::MESSAGE), $inputs['isds_token']), 'another campaign');
        self::assertFalse(FormToken::check(Gate::purpose('send', $other(self::SITE, self::MESSAGE, Account::PRODUCTION), self::ITEM), $inputs['isds_token']), 'the production page of the slices');
        self::assertFalse(FormToken::check(Gate::purpose('send', $other(self::SITE, Sending::OTHER, Account::TEST), self::ITEM), $inputs['isds_token']), 'another message slice');
        self::assertFalse(FormToken::check(Gate::purpose('send', $other(self::SITE2, self::MESSAGE, Account::TEST), self::ITEM), $inputs['isds_token']), 'another site');
    }

    public function test_fields_resume(): void
    {
        session_id('gate-test-session');
        $gate   = self::gate();
        $inputs = self::inputs(self::inside($gate, static fn(): string => $gate->fields('resume', self::ITEM)));

        self::assertSame(['resume', self::ITEM], [$inputs['isds_action'], $inputs['isds_campaign']]);
        self::assertTrue(FormToken::check(Gate::purpose('resume', self::page(), self::ITEM), $inputs['isds_token']));
    }

    /**
     * The runs of ITEM, and whether the page may offer to give them up
     * @return array<string, array{array<string, string>, bool, bool}> own properties of the run, [] = no run; offered;
     *         its recipients can be read
     */
    public static function abandonOffered(): array
    {
        // the states as literals: a data provider runs before SendRun can be loaded
        $page = ['site' => self::SITE, 'camp_slice' => self::CAMPAIGN, 'msg_slice' => self::MESSAGE];

        return [
            'error, stopped already'                  => [$page + ['state' => 'error', 'stop_by' => self::READER], true, true],
            // no figure can be trusted, giving up is still offered
            'error, stopped already, figures unknown' => [$page + ['state' => 'error', 'stop_by' => self::READER], true, false],
            'watch_error'                             => [$page + ['state' => 'error', 'watch_from' => '1700000000'], true, true],
            'error, a stop still ends it'             => [$page + ['state' => 'error'], false, true],
            'sending, stopped already'                => [$page + ['state' => 'sending', 'stop_by' => self::READER], false, true],
            'given up'                                => [$page + ['state' => 'stopped', 'stop_by' => self::READER], false, true],
            'no run'                                  => [[], false, true],
            'a run of another message slice'          => [['msg_slice' => Sending::OTHER, 'state' => 'error', 'stop_by' => self::READER] + $page, false, true],
        ];
    }

    // Stop of such a run only asks to give it up: the template offers Abandon in its place, and nowhere else
    /** @param array<string, string> $run */
    #[DataProvider('abandonOffered')]
    public function test_fields_abandonOnlyWhereStopNoLongerEnds(array $run, bool $offered, bool $readable): void
    {
        session_id('gate-test-session');
        $gate       = self::actionGate();
        $gate->runs = ($run === []) ? [] : [self::ITEM => new class ($run, $readable) extends SendRun {
            /** @param array<string, string> $props */
            public function __construct(array $props, private bool $readable)
            {
                parent::__construct($props);
            }

            public function recipients(): array
            {
                return $this->readable ? [] : throw new BatchFailed('CHANGED');
            }
        }];

        [$abandon, $stop, $progress, $total] = self::inside($gate, static fn(): array => [$gate->fields('abandon', self::ITEM), $gate->fields('stop', self::ITEM), $gate->progress(self::ITEM, 'abandon'), $gate->progress(self::ITEM, 'total')]);

        self::assertSame($offered, $abandon !== '');
        if ($offered) {
            self::assertTrue(FormToken::check(Gate::purpose('abandon', self::page(), self::ITEM), self::inputs($abandon)['isds_token']));
            self::assertSame($readable ? '0' : '', $total, 'the figures read, or not');
        }
        self::assertSame('stop', self::inputs($stop)['isds_action'], 'Stop is given whatever the run');
        self::assertSame('', $progress, 'no question of progress');
    }

    public function test_fields_newCampaignOnlyToSave(): void
    {
        session_id('gate-test-session');
        $gate   = self::gate();
        $fields = self::inside($gate, static fn(): array => [$gate->fields('save'), $gate->fields('send'), $gate->fields('clone', '')]);

        self::assertTrue(FormToken::check(Gate::purpose('save', self::page(), ''), self::inputs($fields[0])['isds_token']));
        self::assertSame(['', ''], [$fields[1], $fields[2]]);
    }

    // the form of a new draft names it by a nonce of its own, new each time it is shown
    public function test_fields_nonceOnlyForNewDraft(): void
    {
        session_id('gate-test-session');
        $gate   = self::gate();
        $fields = self::inside($gate, static fn(): array => array_map(
            static fn(array $f): array => self::inputs($gate->fields(...$f)),
            [['save'], ['save'], ['save', self::ITEM], ['send', self::ITEM], ['resume', self::ITEM]],
        ));

        [$new, $again] = array_map(static fn(array $f): string => $f['isds_nonce'] ?? '', $fields);
        foreach ([$new, $again] as $nonce) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
        }
        self::assertNotSame($new, $again, 'each form shown makes its own draft');
        self::assertSame([[], [], []], array_map(static fn(array $f): array => array_intersect_key($f, ['isds_nonce' => 1]), array_slice($fields, 2)));
    }

    public function test_fields_unknownActionOrCampaign(): void
    {
        $gate = self::gate();

        self::assertSame(['', '', '', ''], self::inside($gate, static fn(): array => [$gate->fields('delete', self::ITEM), $gate->fields('send', '12" onclick="x'), $gate->fields('', self::ITEM), $gate->fields('save', 'x')]));
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
     * @param array<string, string|array{slice: string, active: bool, filter: string, state: string}> $campaigns item id => the
     *        filter of an active item of the campaign slice, or what stored() gives
     */
    private static function recipientGate(array $campaigns): FakeGate
    {
        $gate            = self::gate();
        $gate->campaigns = array_map(static fn(string|array $c): array => is_string($c) ? ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => $c, 'state' => 'draft'] : $c, $campaigns);
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

    /** @return array<string, array{string, string, array<string, string|array{slice: string, active: bool, filter: string, state: string}>}> */
    public static function notACampaign(): array
    {
        $stored = [self::ITEM => ''];

        return [
            'not an id'              => ['12" onclick="x', 'count', $stored],
            'no such item'           => [self::ITEM, 'count', []],
            'an item of messages'    => [self::LETTER, 'count', [self::LETTER => ['slice' => self::MESSAGE, 'active' => true, 'filter' => '', 'state' => 'sent']]],
            'an item of the source'  => [self::ADAMOV, 'count', [self::ADAMOV => ['slice' => self::SOURCE, 'active' => true, 'filter' => '', 'state' => '']]],
            'a campaign in a bin'    => [self::ITEM, 'count', [self::ITEM => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'draft']]],
            // a read without the password masks the status code too - the item is in no bin
            'a campaign read masked' => [self::ITEM, 'count', [self::ITEM => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => 'Error: Missing Reading Password', 'state' => 'Error: Missing Reading Password']]],
            'unknown question'       => [self::ITEM, 'all', $stored],
        ];
    }

    /** @param array<string, string|array{slice: string, active: bool, filter: string, state: string}> $campaigns */
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

    // ------------------------------------------------------------ actions

    /** the settings of the page as the gate checks them */
    private static function page(): Setup
    {
        return Setup::fromTemplate(self::settings(), self::SITE, self::rules(), Sending::bound());
    }

    /** the page of a sending in error: ITEM a campaign of it, its run by the binding of the page */
    private static function actionGate(string $login = self::READER): FakeGate
    {
        $gate            = self::gate(login: $login);
        $gate->campaigns = [self::ITEM => ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => '', 'state' => 'sending']];
        $gate->runs      = [self::ITEM => new SendRun(['site' => self::SITE, 'camp_slice' => self::CAMPAIGN, 'msg_slice' => self::MESSAGE, 'state' => SendRun::ERROR])];

        return $gate;
    }

    /** Continue of ITEM posted from the page at $uri, its token for $setup */
    private static function postResume(?Setup $setup = null, string $uri = '/cz/rozesilka?tab=2'): void
    {
        session_id('gate-test-session');
        $_SERVER['REQUEST_METHOD']      = 'POST';
        $_SERVER['HTTP_HOST']           = 'www.example.cz';
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        $_SERVER['REQUEST_URI']         = $uri;
        $_GET                           = [];
        $_POST                          = ['isds_action' => 'resume', 'isds_campaign' => self::ITEM, 'isds_token' => FormToken::get(Gate::purpose('resume', $setup ?? self::page(), self::ITEM)), 'isds_form' => bin2hex(random_bytes(8))];
    }

    /** the gate renders the page of a POST and sends the browser on: where to */
    private function redirectOf(Gate $gate): string
    {
        try {
            $this->render($gate);
        } catch (Redirected $redirect) {
            return $redirect->url;
        }
        self::fail('The gate sends the browser on after an action');
    }

    /** the GET the browser makes after the redirect, or a reload of it */
    private static function getAfter(string $url): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = $url;
        $_POST                     = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $_GET);
    }

    /** @return list<string> what {datovkabulk:result} gives the UI: text, code, status, action */
    private static function shown(Gate $gate): array
    {
        return self::inside($gate, static fn(): array => [$gate->result(), $gate->result('code'), $gate->result('status'), $gate->result('action')]);
    }

    /** @return array<string, string> the headers the page is sent with */
    private static function headers(): array
    {
        AA::$headers += ['type' => 'text/html', 'encoding' => 'utf-8'];   // no language files read

        return AA::getHeaders();
    }

    /** a reader without the role, a site page that is none, ... - never acts, whatever the form */
    #[DataProvider('refused')]
    public function test_page_refusedUserTriggersNoAction(\Closure $gate): void
    {
        self::postResume();
        $refused            = $gate();
        $refused->campaigns = self::actionGate()->campaigns;
        $refused->runs      = self::actionGate()->runs;

        self::assertSame(self::REFUSAL, $this->render($refused));
        self::assertSame([[], []], [FakeActions::$resumed, $_SESSION]);
    }

    // a test page and a production page of one campaign slice: the form of one acts only on its own
    public function test_page_twoGatesOnlyOwnFormActs(): void
    {
        $production        = self::actionGate();
        $production->setup = Setup::fromSnapshot(self::SITE, self::CAMPAIGN, self::MESSAGE, self::SOURCE, ['mesto' => 'Cities'], environment: Account::PRODUCTION);
        $test              = self::actionGate();
        $test->setup       = Setup::fromSnapshot(self::SITE, self::CAMPAIGN, self::MESSAGE, self::SOURCE, ['mesto' => 'Cities'], environment: Account::TEST);
        self::postResume($test->setup);

        self::assertSame(['The form has expired, or it does not belong to this page. Load the page again.', Actions::EXPIRED, ActionResult::EXPIRED, ''], self::shown($production), 'shown in place: a later gate may take the form');
        self::assertSame([], FakeActions::$resumed);
        $url = $this->redirectOf($test);
        self::assertSame([[self::ITEM, self::READER]], FakeActions::$resumed);

        self::getAfter($url);
        self::assertSame(['', ''], [self::shown($production)[1], $production->result()], 'the result is the test page\'s');
        self::assertSame(Actions::RESUMED, self::shown($test)[1]);
    }

    public function test_page_noStoreBeforeAction(): void
    {
        $gate                = self::actionGate();
        $seen                = [];
        FakeActions::$resume = static function () use ($gate, &$seen): string {
            $seen = [$gate->noStores, AA::$headers['XFO'] ?? ''];

            return '';
        };
        self::postResume();

        $this->redirectOf($gate);

        self::assertSame([1, 'X-Frame-Options: SAMEORIGIN'], $seen, 'the page is kept out of the cache and of frames before anything is done');
    }

    // the UI of the POST is never expanded: the browser loads the page anew, with what the action did
    public function test_page_actionBeforeUi(): void
    {
        $order               = [];
        FakeActions::$resume = static function () use (&$order): string {
            $order[] = 'action';

            return '';
        };
        self::postResume();

        $this->redirectOf(self::actionGate());

        self::assertSame([['action'], []], [$order, $this->expanded]);
    }

    // ?debug printed before the gate: the page goes out without the headers against framing, so it
    // gives no form a frame of another site could make the user click
    public function test_page_noFormsWhenFramingCannotBeSent(): void
    {
        session_id('gate-test-session');
        $gate              = self::actionGate();
        $gate->headersSent = true;

        self::assertSame(['', ''], self::inside($gate, static fn(): array => [$gate->fields('resume', self::ITEM), $gate->fields('save')]));
        self::assertNotSame('', self::inside(self::actionGate(), static fn(): string => self::actionGate()->fields('resume', self::ITEM)), 'with the headers it does');
    }

    public function test_page_framingForbiddenWhenOpen(): void
    {
        $this->render(self::gate());

        $headers = self::headers();
        self::assertSame('X-Frame-Options: SAMEORIGIN', $headers['XFO']);
        self::assertSame("Content-Security-Policy: frame-ancestors 'self'", $headers['CSP']);
    }

    // a template may set its CSP after the gate as well as before it
    public function test_page_framingMergedWithTemplateCsp(): void
    {
        AA::$headers['CSP'] = "Content-Security-Policy: default-src 'self'; img-src *";
        $this->render(self::gate());
        self::assertSame("Content-Security-Policy: default-src 'self'; img-src *; frame-ancestors 'self'", self::headers()['CSP']);

        AA::$headers['CSP'] = "Content-Security-Policy: script-src 'self';";
        self::assertSame("Content-Security-Policy: script-src 'self'; frame-ancestors 'self'", self::headers()['CSP']);
    }

    // a browser takes the first frame-ancestors and then ignores X-Frame-Options
    public function test_page_framingTemplateFrameAncestorsOverridden(): void
    {
        $this->render(self::gate());
        AA::$headers['CSP'] = "Content-Security-Policy: frame-ancestors https://cizi.example; default-src 'self'; FRAME-ANCESTORS *";

        self::assertSame("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'", self::headers()['CSP']);
    }

    // a {view} of the UI printing with ?debug sends the headers before the page ends: X-Frame-Options must be out already
    public function test_page_framingHeaderSentAtTheGate(): void
    {
        $gate = self::gate();
        $this->render($gate);
        self::assertSame(['X-Frame-Options: SAMEORIGIN'], $gate->sentHeaders);

        $late              = self::gate();
        $late->headersSent = true;
        $this->render($late);
        self::assertSame([], $late->sentHeaders, 'gone already: nothing to send');

        $refused = self::gate(login: Sending::USER);
        $this->render($refused);
        self::assertSame([], $refused->sentHeaders, 'a refused gate offers nothing to frame');
    }

    public function test_page_refusedGateLeavesFramingAsItWas(): void
    {
        $this->render(self::gate(login: Sending::USER));

        self::assertArrayNotHasKey('XFO', self::headers());
    }

    public function test_page_flashOfRevokedUserNotShown(): void
    {
        self::postResume();
        $url = $this->redirectOf(self::actionGate());
        self::getAfter($url);
        FakePerms::install([]);

        self::assertSame(self::REFUSAL, $this->render(self::actionGate()));
    }

    // another login of the same browser session sees nothing of it either
    public function test_page_flashOfOtherUserNotShown(): void
    {
        self::postResume();
        self::getAfter($this->redirectOf(self::actionGate()));
        FakePerms::install([Sending::USER => [self::CAMPAIGN => PS_EDIT_ALL_ITEMS]]);

        self::assertSame(['', '', '', ''], self::shown(self::actionGate(Sending::USER)));
    }

    /** @return array<string, array{string}> */
    public static function foreignPaths(): array
    {
        return [
            'another host'            => ['//cizi.example/cz/x'],
            'another host, backslash' => ['/\cizi.example/cz/x'],
            'a header in the path'    => ["/cz/x\r\nSet-Cookie: a=b"],
            'a header in the query'   => ["/cz/x?a=1\nSet-Cookie: a=b"],
            'a tab'                   => ["/\t/cizi.example"],
            'no path'                 => ['cizi.example/cz/x'],
            'none'                    => [''],
        ];
    }

    #[DataProvider('foreignPaths')]
    public function test_redirect_neverLeavesTheHost(string $uri): void
    {
        self::postResume(uri: $uri);

        self::assertMatchesRegularExpression('~^/\?isds_campaign=' . self::ITEM . '&isds_r=[0-9a-f]{16}\z~', $this->redirectOf(self::actionGate()));
    }

    public function test_redirect_keepsQueryReplacesKeys(): void
    {
        self::postResume(uri: '/cz/rozesilka?tab=2&isds_r=0123456789abcdef&isds_campaign=x&isds_r%5B%5D=1&q=a%26b#top');

        $url = $this->redirectOf(self::actionGate());

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&q=a%26b&isds_campaign=' . self::ITEM . '&isds_r=[0-9a-f]{16}\z~', $url);
    }

    // nor does it act on a form: the page of the result would go out unguarded too
    public function test_redirect_noRedirectAfterHeaders(): void
    {
        $gate              = self::actionGate();
        $gate->headersSent = true;
        self::postResume();

        self::assertSame([Actions::EXPIRED, ActionResult::EXPIRED, ''], array_slice(self::shown($gate), 1));
        self::assertSame([[], []], [FakeActions::$resumed, $_SESSION], 'nothing done, nothing kept');
    }

    // the second click finds the sending going on already - it shows what the first did, not a refusal
    public function test_result_doubleClickShowsTheFirstResult(): void
    {
        self::postResume();
        $first = $this->redirectOf(self::actionGate());
        $form  = $_POST;
        Actions::forget();
        FakeActions::$resume = static fn(): string => Actions::WRONG_STATE;
        self::postResume();
        $_POST = $form;

        $second = $this->redirectOf(self::actionGate());

        self::assertSame([[self::ITEM, self::READER]], FakeActions::$resumed, 'the core asked once');
        self::assertNotSame($first, $second);
        self::getAfter($second);
        self::assertSame(['The sending goes on.', Actions::RESUMED, ActionResult::DONE, 'resume'], self::shown(self::actionGate()));
    }

    /** @return array<string, array{\Closure(): string}> what the core says to the first click */
    public static function didNotRun(): array
    {
        return [
            'busy'          => [static fn(): string => Actions::BUSY],
            'a step works'  => [static fn(): string => Actions::LEASE],
            'not waiting'   => [static fn(): string => Actions::WRONG_STATE],
            'not the right' => [static fn(): string => Actions::FORBIDDEN],
        ];
    }

    // only what ran is repeated: a click refused, or turned away while another request held the
    // campaign, does not hold the same form back
    /** @param \Closure(): string $first */
    #[DataProvider('didNotRun')]
    public function test_result_onlyAnActionThatRanIsRepeated(\Closure $first): void
    {
        FakeActions::$resume = $first;
        self::postResume();
        $form = $_POST;
        $this->redirectOf(self::actionGate());
        Actions::forget();
        FakeActions::$resume = null;
        $_POST               = $form;

        self::getAfter($this->redirectOf(self::actionGate()));

        self::assertCount(2, FakeActions::$resumed);
        self::assertSame(Actions::RESUMED, self::shown(self::actionGate())[1]);
    }

    // an internal error may have done part of the work: the click again shows it, never does it twice
    public function test_result_failedIsRepeated(): void
    {
        FakeActions::$resume = static fn(): string => throw new \RuntimeException('broken');
        self::postResume();
        $form = $_POST;
        $this->redirectOf(self::actionGate());
        Actions::forget();
        FakeActions::$resume = null;
        $_POST               = $form;

        self::getAfter($this->redirectOf(self::actionGate()));

        self::assertCount(1, FakeActions::$resumed);
        self::assertSame([Actions::INTERNAL, ActionResult::FAILED], array_slice(self::shown(self::actionGate()), 1, 2));
    }

    // an autosave posts the form shown once again and again: each post acts
    public function test_result_repostedActionNotRepeated(): void
    {
        $gate   = self::actionGate();
        $form   = bin2hex(random_bytes(8));
        $token  = 'a-token';
        $result = new ActionResult('save', ActionResult::DONE, 'SAVED', self::ITEM);
        $_SESSION['aa_isds_result']['0123456789abcdef'] = ['binding' => self::page()->bindingKey(), 'uid' => self::READER, 'at' => $gate->now, 'form' => hash('sha256', "$token:$form"), 'result' => $result->toArray()];
        $answered = static fn(): ?ActionResult => (new ReflectionMethod(Gate::class, 'answered'))->invoke($gate, self::page(), self::READER);

        $_POST = ['isds_action' => 'resume', 'isds_token' => $token, 'isds_form' => $form];
        self::assertEquals($result, $answered(), 'the same form of another action is answered');
        $_POST['isds_action'] = 'save';
        self::assertNull($answered());
    }

    /** @return array<string, array{\Closure(array<string, string>, FakeGate): void}> */
    public static function notTheSameForm(): array
    {
        return [
            // Continue again after the sending failed again: the token is the same in every form of it
            'the form shown again'      => [static function (array $first, FakeGate $gate): void {}],
            'a form that does not tell' => [static function (array $first, FakeGate $gate): void {
                $_POST = ['isds_form' => '<x>'] + $first;
            }],
            'the same form, forgotten'  => [static function (array $first, FakeGate $gate): void {
                $_POST     = $first;
                $gate->now += 600;
            }],
        ];
    }

    /** @param \Closure(array<string, string>, FakeGate): void $again */
    #[DataProvider('notTheSameForm')]
    public function test_result_otherFormActsAgain(\Closure $again): void
    {
        self::postResume();
        $first = $_POST;
        $this->redirectOf(self::actionGate());
        Actions::forget();
        self::postResume();
        $gate = self::actionGate();
        $again($first, $gate);

        $this->redirectOf($gate);

        self::assertCount(2, FakeActions::$resumed);
    }

    // the progress reloaded every few seconds and a second tab get no isds_r: they neither show nor take it
    public function test_result_pollDoesNotConsumeFlash(): void
    {
        self::postResume();
        $url = $this->redirectOf(self::actionGate());

        self::getAfter('/cz/rozesilka?tab=2');
        self::assertSame(['', '', '', ''], self::shown(self::actionGate()), 'the poll');
        self::getAfter($url);
        self::assertSame(['The sending goes on.', Actions::RESUMED, ActionResult::DONE, 'resume'], self::shown(self::actionGate()));
        self::assertSame(Actions::RESUMED, self::shown(self::actionGate())[1], 'a reload shows it again');
    }

    // two tabs, two actions: each page shows its own result
    public function test_result_otherTabSeesNoResult(): void
    {
        self::postResume();
        $first = $this->redirectOf(self::actionGate());
        Actions::forget();
        FakeActions::$resume = static fn(): string => Actions::BUSY;
        self::postResume();
        $second = $this->redirectOf(self::actionGate());

        self::getAfter($first);
        self::assertSame(Actions::RESUMED, self::shown(self::actionGate())[1]);
        self::getAfter($second);
        self::assertSame([Actions::BUSY, ActionResult::BUSY], array_slice(self::shown(self::actionGate()), 1, 2));
    }

    public function test_result_unknownKeyShowsNothing(): void
    {
        self::postResume();
        $this->redirectOf(self::actionGate());

        self::getAfter('/cz/rozesilka?isds_r=0123456789abcdef');
        self::assertSame(['', '', '', ''], self::shown(self::actionGate()));
    }

    /** @return array<string, array{\Closure(string): void}> */
    public static function malformedKeys(): array
    {
        return [
            'an array'        => [static function (string $key): void {
                $_GET['isds_r'] = [$key];
            }],
            'capitals'        => [static function (string $key): void {
                $_GET['isds_r'] = strtoupper($key);
            }],
            'a line end'      => [static function (string $key): void {
                $_GET['isds_r'] = "$key\n";
            }],
            'posted, not got' => [static function (string $key): void {
                $_POST = ['isds_r' => $key];
                $_GET  = [];
            }],
            // another form posted to the page after the action, its URL with the key
            'a POST'          => [static function (string $key): void {
                $_POST                     = ['comment' => 'x'];
                $_SERVER['REQUEST_METHOD'] = 'POST';
            }],
        ];
    }

    /** @param \Closure(string): void $spoil */
    #[DataProvider('malformedKeys')]
    public function test_result_malformedKeyShowsNothing(\Closure $spoil): void
    {
        self::postResume();
        self::getAfter($this->redirectOf(self::actionGate()));
        $spoil((string) $_GET['isds_r']);

        self::assertSame(['', '', '', ''], self::shown(self::actionGate()));
    }

    public function test_result_expiresAfterTenMinutes(): void
    {
        self::postResume();
        self::getAfter($this->redirectOf(self::actionGate()));
        $gate = self::actionGate();

        $gate->now += 599;
        self::assertSame(Actions::RESUMED, self::shown($gate)[1]);
        $gate->now += 1;
        self::assertSame('', self::shown($gate)[1]);
    }

    // autosaves every few seconds keep adding results: the oldest go
    public function test_result_keepsTheTenNewest(): void
    {
        $urls = [];
        for ($i = 0; $i < 11; ++$i) {
            Actions::forget();
            self::postResume();
            $urls[] = $this->redirectOf(self::actionGate());
        }

        self::assertCount(10, $_SESSION['aa_isds_result']);
        self::getAfter($urls[0]);
        self::assertSame('', self::shown(self::actionGate())[1]);
        self::getAfter($urls[1]);
        self::assertSame(Actions::RESUMED, self::shown(self::actionGate())[1]);
    }

    public function test_result_detailCutOnCharBoundary(): void
    {
        $detail = str_repeat('ž', 1001) . '<b>';
        $result = new ActionResult('resume', ActionResult::REFUSED, Actions::UNKNOWN, self::ITEM, $detail);
        self::assertSame(str_repeat('ž', 1000), $result->detail);

        $_SESSION['aa_isds_result']['0123456789abcdef'] = ['binding' => self::page()->bindingKey(), 'uid' => self::READER, 'at' => 1_800_000_000, 'result' => ['detail' => '<b>' . $detail] + $result->toArray()];
        self::getAfter('/cz/rozesilka?isds_r=0123456789abcdef');
        $text = self::shown(self::actionGate())[0];

        self::assertStringStartsWith('This sending is not on this page. &lt;b&gt;žž', $text, 'escaped');
        self::assertTrue(mb_check_encoding(htmlspecialchars_decode($text), 'UTF-8'));
        self::assertSame(ActionResult::DETAIL_MAX - 1, strlen(htmlspecialchars_decode($text)) - strlen('This sending is not on this page. '), 'cut whole characters');
    }

    // ------------------------------------------------------------ Send and Test

    /**
     * $action of ITEM posted from the page, with the fields the template adds to its form
     * @param array<string, string> $fields
     */
    private static function postAction(string $action, array $fields): void
    {
        self::postResume();
        $_POST = ['isds_action' => $action, 'isds_token' => FormToken::get(Gate::purpose($action, self::page(), self::ITEM))] + $fields + $_POST;
    }

    public function test_send_queuedRedirects(): void
    {
        FakeActions::$queue = static fn(): SendStart => SendStart::queued('run-1');
        self::postAction('send', ['isds_confirm' => '2']);

        $url = $this->redirectOf(self::actionGate());

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&isds_campaign=' . self::ITEM . '&isds_r=[0-9a-f]{16}$~', $url);
        self::getAfter($url);
        self::assertSame(['The sending is queued - recipients: 2. It goes on by itself, you may close the page.', Actions::QUEUED, ActionResult::DONE, 'send'], self::shown(self::actionGate()));
    }

    // a double click on Send: the core asked once, the second click shows what the first did
    public function test_send_doubleClickQueuesOnce(): void
    {
        FakeActions::$queue = static fn(): SendStart => SendStart::queued('run-1');
        self::postAction('send', ['isds_confirm' => '2']);
        $form = $_POST;
        $this->redirectOf(self::actionGate());
        Actions::forget();
        FakeActions::$queue = static fn(): SendStart => SendStart::refused(SendStart::NOT_DRAFT);
        $_POST              = $form;

        self::getAfter($this->redirectOf(self::actionGate()));

        self::assertCount(1, FakeActions::$queued);
        self::assertSame(Actions::QUEUED, self::shown(self::actionGate())[1]);
    }

    // the detail may quote the draft or a file name: it reaches the page escaped, after what to do
    public function test_result_sendDetailEscaped(): void
    {
        FakeActions::$queue = static fn(): SendStart => SendStart::refused(SendStart::CONTENT, 'The file <img src=x onerror=alert(1)>.pdf cannot be read');
        self::postAction('send', ['isds_confirm' => '2']);

        self::getAfter($this->redirectOf(self::actionGate()));

        self::assertSame('The letter cannot go as a data message: The file &lt;img src=x onerror=alert(1)&gt;.pdf cannot be read', self::shown(self::actionGate())[0]);
    }

    // what a test cost goes across the redirect as a second code, and the page tells it after the result
    public function test_result_testChargeSurvivesRedirect(): void
    {
        FakeActions::$test = static fn(): TestResult => TestResult::of(Outcome::of(new SendResult('1835603', '0000', 'OK')), 'msg', 'dj7j6mk', '4711-T2', true, true);
        self::postAction('test', ['isds_box' => 'dj7j6mk']);

        self::getAfter($this->redirectOf(self::actionGate()));
        $gate = self::actionGate();

        self::assertSame(
            ['The test message has gone (ID 1835603 in ISDS, reference number 4711-T2). The test message went as a paid postal data message.', TestResult::CHARGED],
            self::inside($gate, static fn(): array => [$gate->result(), $gate->result('charge')]),
        );
    }

    // ------------------------------------------------------------ Save

    // a new draft: the browser goes to the page of its id, which the template then reads
    public function test_save_newDraftRedirectsToIt(): void
    {
        FakeActions::$save = static fn(): array => ['code' => Actions::SAVED, 'campaign' => self::ITEM, 'field' => ''];
        self::postResume();
        $_POST = ['isds_action' => 'save', 'isds_campaign' => '', 'isds_token' => FormToken::get(Gate::purpose('save', self::page(), '')), 'isds_nonce' => str_repeat('a', 32), 'isds_subject' => 'Výzva'];

        $url = $this->redirectOf(self::actionGate());

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&isds_campaign=' . self::ITEM . '&isds_r=[0-9a-f]{16}$~', $url);
        self::getAfter($url);
        $gate = self::actionGate();
        self::assertSame(['The draft is saved.', self::ITEM], self::inside($gate, static fn(): array => [$gate->result(), $gate->campaign()]));
    }

    // autosaves add a result every few seconds: they never push out the one that keeps a Test from going twice
    public function test_result_autosavesKeepTheGuardOfATest(): void
    {
        FakeActions::$test = static fn(): TestResult => TestResult::of(Outcome::of(new SendResult('1835603', '0000', 'OK')), 'msg', 'dj7j6mk', '4711-T2', true, true);
        FakeActions::$save = static fn(): array => ['code' => Actions::SAVED, 'campaign' => self::ITEM, 'field' => ''];
        self::postAction('test', ['isds_box' => 'dj7j6mk']);
        $test = $_POST;
        $this->redirectOf(self::actionGate());
        for ($i = 0; $i < 12; ++$i) {
            Actions::forget();
            self::postAction('save', ['isds_subject' => "Výzva $i"]);
            $this->redirectOf(self::actionGate());
        }
        Actions::forget();
        self::postResume();
        $_POST = $test;

        self::getAfter($this->redirectOf(self::actionGate()));

        self::assertCount(1, FakeActions::$tested, 'the test form posted again sent nothing');
        self::assertSame(Actions::TEST_SENT, self::shown(self::actionGate())[1]);
        self::assertCount(10, $_SESSION['aa_isds_result']);
    }

    /** @return array<string, array{mixed, string}> isds_campaign of the URL, what the template reads */
    public static function campaignsOfTheUrl(): array
    {
        return [
            'a campaign of the page'   => [self::ITEM, self::ITEM],
            'in capitals'              => [strtoupper(self::ITEM), self::ITEM],
            'in the trash'             => ['ca00000000000000000000000000000e', 'ca00000000000000000000000000000e'],
            'an item of another slice' => [self::LETTER, ''],
            'no item'                  => ['ca00000000000000000000000000000f', ''],
            'not an id'                => [self::ITEM . '"><b>', ''],
            'an array'                 => [[self::ITEM], ''],
            'none'                     => [null, ''],
        ];
    }

    #[DataProvider('campaignsOfTheUrl')]
    public function test_campaign_onlyItemOfThePage(mixed $campaign, string $read): void
    {
        $gate            = self::gate();
        $gate->campaigns = [
            self::ITEM                         => ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => '', 'state' => 'draft'],
            'ca00000000000000000000000000000e' => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'draft'],
            self::LETTER                       => ['slice' => self::MESSAGE, 'active' => true, 'filter' => '', 'state' => ''],
        ];
        $_GET = is_null($campaign) ? [] : ['isds_campaign' => $campaign];

        self::assertSame($read, self::inside($gate, static fn(): string => $gate->campaign()));
        self::assertSame('', $gate->campaign(), 'outside the UI');
    }

    public function test_testboxes_escapedDashSeparated(): void
    {
        $gate        = self::gate();
        $gate->setup = Setup::fromSnapshot(self::SITE, self::CAMPAIGN, self::MESSAGE, self::SOURCE, ['mesto' => 'Cities'], testBoxes: ['dj7j6mk', 'ab12cde', '<b>']);

        self::assertSame('dj7j6mk-ab12cde-&lt;b&gt;', self::inside($gate, static fn(): string => $gate->testBoxes()));
        self::assertSame('', $gate->testBoxes(), 'outside the UI');
    }
}
