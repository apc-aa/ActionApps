<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Isds\Access;
use AA\Isds\Account;
use AA\Isds\Gate;
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
        class_exists(\AA_Stringexpand_Jsonasoc::class);   // stringexpand.php: aa_json_decode()
        if (!defined('CACHE_TTL')) {
            define('CACHE_TTL', 600);                       // AA::Pagecache()
        }
    }

    /** the office: readers in a set the campaign slice grants the editor's role */
    protected function setUp(): void
    {
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
        Gate::report('');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_id('');
        }
    }

    private static function settings(): string
    {
        return (string) json_encode(['campaign_slice' => self::CAMPAIGN, 'message_slice' => self::MESSAGE, 'source_slice' => self::SOURCE, 'types' => ['mesto' => 'Cities']]);
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
}
