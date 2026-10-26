<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA;
use AA\Isds\Access;
use AA\Isds\Account;
use AA\Isds\ActionResult;
use AA\Isds\Actions;
use AA\Isds\Advice;
use AA\Isds\DatovkaConf;
use AA\Isds\Drafts;
use AA\Isds\Gate;
use AA\Isds\SendResult;
use AA\Isds\SendRun;
use AA\Isds\SendStart;
use AA\Isds\Outcome;
use AA\Isds\Registry\DownloadedRegister;
use AA\Isds\Registry\Sync;
use AA\Isds\Schema;
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
use Tests\Unit\Legacy\Fixtures\RecordingDb;

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
        \AA_Items::singleton()->doInvalidate();
        FakeRecipients::forget();
        FakeActions::reset();
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        AA::$headers = [];
        $_SERVER     = $this->server;
        $_POST       = [];
        $_GET        = [];
        $_FILES      = [];
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

    public function test_advice_emptyOutsideGate(): void
    {
        $gate = self::gate();

        self::assertSame(['', '', ''], [$gate->advice(Actions::BUSY), $gate->advice(SendRun::REFUSED, 'run'), $gate->advice('isds', '1233')]);
        self::assertSame(Advice::text(Actions::BUSY), self::inside($gate, static fn(): string => $gate->advice(Actions::BUSY)), 'inside');
    }

    /** @return array<string, array{string, string}> code, when */
    public static function unknownAdvice(): array
    {
        // literals: a data provider runs before Actions can be loaded
        return [
            'no such code'                => ['NO_SUCH_CODE', ''],
            'no such code of a run'       => ['NO_SUCH_CODE', 'run'],
            'empty'                       => ['', ''],
            // a run waits for the next round of delivery: no action of the page says it
            'a code only of a run'        => ['ISDS_WATCH', ''],
            // a text that names what the template cannot give - the size, the count
            'a text needing a number'     => ['COUNT_CHANGED', ''],
            'a question of no kind'       => ['BUSY', 'later'],
            'an action code in a run'     => ['SAVED', 'run'],
        ];
    }

    #[DataProvider('unknownAdvice')]
    public function test_advice_unknownEmpty(string $code, string $when): void
    {
        $gate = self::gate();

        self::assertSame('', self::inside($gate, static fn(): string => $gate->advice($code, $when)));
    }

    // a refusal while sending is no answer to the question of queueing, whose the login is
    public function test_advice_runWording(): void
    {
        $gate = self::gate();
        [$asked, $run, $watch] = self::inside($gate, static fn(): array => [$gate->advice(SendRun::REFUSED), $gate->advice(SendRun::REFUSED, 'run'), $gate->advice(SendRun::WATCHING, 'run')]);

        self::assertStringContainsString('nothing was sent', $asked);
        self::assertStringNotContainsString('nothing was sent', $run);
        self::assertStringContainsString('After refused messages Continue sends one more', $run);
        self::assertStringContainsString('does not mean every message went', $watch);
        self::assertSame('', self::inside($gate, static fn(): string => $gate->advice(SendRun::WATCHING)), 'waiting for a round is no answer to an action');
    }

    /** @return array<string, array{string, bool}> status, whether AA has a text of it */
    public static function isdsStatuses(): array
    {
        return [
            'known'         => ['1233', true],
            'unknown'       => ['9999', false],
            'no digits'     => ['12a3', false],
            'too long'      => ['123456', false],
            'none'          => ['', false],
            'a line end'    => ["1233\n", false],
        ];
    }

    #[DataProvider('isdsStatuses')]
    public function test_advice_isdsStatus(string $status, bool $known): void
    {
        $gate = self::gate();
        $text = self::inside($gate, static fn(): string => $gate->advice('isds', $status));

        self::assertSame($known ? Advice::isds($status) : '', $text);
        self::assertSame($known, $text !== '');
    }

    public function test_advice_escaped(): void
    {
        $gate = self::gate();

        self::assertStringContainsString('The subject, &quot;to hands&quot; or', self::inside($gate, static fn(): string => $gate->advice('isds', '1225')));
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

            // of a run given up the queue tells it, which is not here
            public function verifiable(): bool
            {
                return ($this->state() === SendRun::ENDED) || parent::verifiable();
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

    /**
     * A run with $props, whose frozen recipients and queue are not here: its state alone decides
     * @param array<string, string> $props
     */
    private static function quietRun(array $props): SendRun
    {
        return new class ($props) extends SendRun {
            public function recipients(): array
            {
                return [];
            }

            public function verifiable(): bool
            {
                return false;
            }
        };
    }

    /**
     * @return array<string, array{array{slice: string, active: bool, filter: string, state: string}, array<string, string>, bool}> what the gate
     *         reads of ITEM, own properties of its run ([] = none), whether Discard is offered
     */
    public static function discardOffered(): array
    {
        $draft = ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => '', 'state' => 'draft'];
        $run   = ['site' => self::SITE, 'camp_slice' => self::CAMPAIGN, 'msg_slice' => self::MESSAGE];

        return [
            'a draft'                        => [$draft, [], true],
            'a draft in the trash'           => [['active' => false] + $draft, [], false],
            'queued, its record deleted'     => [['state' => 'queued'] + $draft, [], false],
            'a draft with a run given up'    => [$draft, $run + ['state' => 'stopped', 'stop_by' => self::READER], false],
            'a draft with a run that ended'  => [$draft, $run + ['state' => 'done'], false],
            'a draft run by another binding' => [$draft, ['msg_slice' => Sending::OTHER, 'state' => 'error'] + $run, false],
        ];
    }

    // only a draft never queued may go to the trash: the template offers Discard only where the gate gives its form
    /**
     * @param array{slice: string, active: bool, filter: string, state: string} $campaign
     * @param array<string, string>                                              $run
     */
    #[DataProvider('discardOffered')]
    public function test_fields_discardOnlyOfADraftNeverQueued(array $campaign, array $run, bool $offered): void
    {
        session_id('gate-test-session');
        $gate            = self::gate();
        $gate->campaigns = [self::ITEM => $campaign];
        $gate->runs      = ($run === []) ? [] : [self::ITEM => self::quietRun($run)];

        $discard = self::inside($gate, static fn(): string => $gate->fields('discard', self::ITEM));

        self::assertSame($offered, $discard !== '');
        if ($offered) {
            $inputs = self::inputs($discard);
            self::assertSame(['discard', self::ITEM], [$inputs['isds_action'], $inputs['isds_campaign']]);
            self::assertTrue(FormToken::check(Gate::purpose('discard', self::page(), self::ITEM), $inputs['isds_token']));
            self::assertArrayNotHasKey('isds_nonce', $inputs);
        }
        self::assertSame('', self::inside($gate, static fn(): string => $gate->fields('discard')), 'no form of a new draft');
    }

    // one who may send but not move items to the trash gets no form of Discard - the rest of the page stays
    public function test_fields_discardOnlyWithTheRightToTrash(): void
    {
        session_id('gate-test-session');
        FakePerms::install([Sending::SET => [self::CAMPAIGN => PS_EDIT_ALL_ITEMS]], [self::READER => [Sending::READERS, Sending::SET]]);
        $gate            = self::gate();
        $gate->campaigns = [self::ITEM => ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => '', 'state' => 'draft']];

        [$discard, $save] = self::inside($gate, static fn(): array => [$gate->fields('discard', self::ITEM), $gate->fields('save', self::ITEM)]);

        self::assertSame('', $discard);
        self::assertSame('save', self::inputs((string) $save)['isds_action']);
    }

    // the template asks twice - the right is read once a request
    public function test_fields_discardRightReadOnce(): void
    {
        session_id('gate-test-session');
        $perms           = FakePerms::install([Sending::SET => [self::CAMPAIGN => AA_Perm::ROLES['EDITOR']['id']]], [self::READER => [Sending::READERS, Sending::SET]]);
        $gate            = self::gate();
        $gate->campaigns = [self::ITEM => ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => '', 'state' => 'draft']];

        $forms = self::inside($gate, static function () use ($gate, $perms): array {
            $first = $gate->fields('discard', self::ITEM);
            $perms->grants[Sending::SET][self::CAMPAIGN] = PS_EDIT_ALL_ITEMS;

            return [$first, $gate->fields('discard', self::ITEM)];
        });

        self::assertSame(['discard', 'discard'], array_map(static fn(string $f): string => self::inputs($f)['isds_action'] ?? '', $forms));
    }

    public function test_fields_newCampaignOnlyToSave(): void
    {
        session_id('gate-test-session');
        $gate   = self::gate();
        $fields = self::inside($gate, static fn(): array => [$gate->fields('save'), $gate->fields('send'), $gate->fields('clone', '')]);

        self::assertTrue(FormToken::check(Gate::purpose('save', self::page(), ''), self::inputs($fields[0])['isds_token']));
        self::assertSame(['', ''], [$fields[1], $fields[2]]);
    }

    // the form of a new draft and of a copy names it by a nonce of its own, new each time it is shown
    public function test_fields_nonceOnlyForNewAndClone(): void
    {
        session_id('gate-test-session');
        $gate   = self::gate();
        $fields = self::inside($gate, static fn(): array => array_map(
            static fn(array $f): array => self::inputs($gate->fields(...$f)),
            [['save'], ['save'], ['clone', self::ITEM], ['clone', self::ITEM], ['save', self::ITEM], ['send', self::ITEM], ['resume', self::ITEM]],
        ));

        $nonces = array_map(static fn(array $f): string => $f['isds_nonce'] ?? '', array_slice($fields, 0, 4));
        foreach ($nonces as $nonce) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonce);
        }
        self::assertSame($nonces, array_unique($nonces), 'each form shown makes its own draft or copy');
        self::assertSame([[], [], []], array_map(static fn(array $f): array => array_intersect_key($f, ['isds_nonce' => 1]), array_slice($fields, 4)));
    }

    /** @return list<?string> isds_filter_seen of the forms of a draft and of others, of the campaigns of a page with a gate */
    private static function filterSeen(FakeGate $gate): array
    {
        session_id('gate-test-session');

        return self::inside($gate, static fn(): array => array_map(
            static fn(array $f): ?string => self::inputs($gate->fields(...$f))['isds_filter_seen'] ?? null,
            [['save', self::ITEM], ['save'], ['save', self::LETTER], ['save', self::BENESOV], ['send', self::ITEM], ['clone', self::ITEM]],
        ));
    }

    /** a gate whose page has ITEM with a filter, LETTER with one of a type it no longer offers, BENESOV in the trash */
    private static function filterGate(string $chosen, string $broken): FakeGate
    {
        $gate            = self::gate();
        $gate->setup     = self::page();
        $gate->campaigns = [
            self::ITEM    => ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => $chosen, 'state' => 'draft'],
            self::LETTER  => ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => $broken, 'state' => 'draft'],
            self::BENESOV => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => $chosen, 'state' => 'draft'],
        ];

        return $gate;
    }

    // what the form of a draft tells of the filter stored: Drafts writes the recipients only over that one -
    // over one the page cannot read too, so that a choice made anew replaces it (BAD_FILTER)
    public function test_fields_saveTellsTheFilterSeen(): void
    {
        $chosen = Sending::filter(['include' => [self::ADAMOV]]);
        $broken = Sending::filter(['types' => ['kraj']]);
        $gate   = self::filterGate($chosen, $broken);

        self::assertSame([Drafts::filterSeen($chosen), Drafts::filterSeen(''), Drafts::filterSeen($broken), null, null, null], self::filterSeen($gate));
        // what the template prefills the controls with: nothing of the broken one
        self::assertSame(['', ''], self::inside($gate, static fn(): array => [$gate->filter(self::LETTER, 'membership'), $gate->filter(self::LETTER, 'include')]));
    }

    // without the settings of data boxes of the source slice the page shows no choice to store over another
    public function test_fields_saveTellsNoFilterWithoutSourceSettings(): void
    {
        $gate = self::filterGate(Sending::filter(['include' => [self::ADAMOV]]), Sending::filter(['types' => ['kraj']]));
        unset(self::rulesOf($gate)->configs[self::SOURCE]);

        self::assertSame([null, null, null, null, null, null], self::filterSeen($gate));
    }

    /** @return array<string, array{\Closure(FakeGate): void}> what keeps a page from taking a box of the register */
    public static function notFixable(): array
    {
        return [
            'the test environment'      => [static function (FakeGate $gate): void {
                $gate->setup = self::page();
            }],
            // removed since the settings of the page were checked
            'no settings of data boxes' => [static function (FakeGate $gate): void {
                unset(self::rulesOf($gate)->configs[self::SOURCE]);
            }],
            'no register downloaded'    => [static function (FakeGate $gate): void {
                $gate->register = null;
            }],
            'a register AA has no record of' => [static function (FakeGate $gate): void {
                $gate->register = ['state' => DownloadedRegister::UNRECORDED, 'time' => 0, 'size' => 0, 'sha256' => ''];
            }],
            'the register gone'         => [static function (FakeGate $gate): void {
                $gate->register = ['state' => DownloadedRegister::REPLACED] + $gate->register;
            }],
            'an old register'           => [static function (FakeGate $gate): void {
                $gate->register = ['time' => $gate->now - Sync::OLD_DAYS * 86400 - 1] + $gate->register;
            }],
            'no upload directory'       => [static function (FakeGate $gate): void {
                $gate->registerFails = new \RuntimeException('IMG_UPLOAD_PATH is not set');
            }],
        ];
    }

    /** the production page of the slices, a register downloaded */
    private static function fixGate(string $login = self::READER): FakeGate
    {
        $gate           = self::gate(login: $login);
        $gate->setup    = Setup::fromSnapshot(self::SITE, self::CAMPAIGN, self::MESSAGE, self::SOURCE, ['mesto' => 'Cities'], environment: Account::PRODUCTION);
        $gate->register = ['state' => DownloadedRegister::RECORDED, 'time' => $gate->now - 3600, 'size' => 17, 'sha256' => str_repeat('a', 64)];

        return $gate;
    }

    private static function rulesOf(FakeGate $gate): FakeSliceRules
    {
        return (new ReflectionProperty(FakeGate::class, 'rules'))->getValue($gate);
    }

    // a form whose action could only refuse is not offered; the gate gives none to who may not send
    public function test_fields_fixboxOnlyForWhoMay(): void
    {
        session_id('gate-test-session');
        $gate   = self::fixGate();
        $inputs = self::inputs(self::inside($gate, static fn(): string => $gate->fields('fixbox', self::ITEM)));

        self::assertSame(['fixbox', self::ITEM], [$inputs['isds_action'], $inputs['isds_campaign']]);
        self::assertTrue(FormToken::check(Gate::purpose('fixbox', $gate->setup, self::ITEM), $inputs['isds_token']));
        self::assertArrayNotHasKey('isds_nonce', $inputs);

        $refused = self::fixGate(login: Sending::USER);
        self::assertNull(self::inside($refused, static fn(): string => $refused->fields('fixbox', self::ITEM)), 'no UI');
        self::assertSame('', $refused->fields('fixbox', self::ITEM), 'outside the UI');
    }

    /** @param \Closure(FakeGate): void $change */
    #[DataProvider('notFixable')]
    public function test_fields_fixboxOnlyWhereTheRegisterOffersBoxes(Closure $change): void
    {
        $gate = self::fixGate();
        $change($gate);

        self::assertSame(['', 'stop'], self::inside($gate, static fn(): array => [$gate->fields('fixbox', self::ITEM), self::inputs($gate->fields('stop', self::ITEM))['isds_action'] ?? '']));
    }

    // the value of the form of Fix box, for the rows the register offers a box for - read once a request
    public function test_regbox_onlyWhatTheRegisterOffers(): void
    {
        $gate          = self::fixGate();
        $gate->offered = [self::ADAMOV => 'a7kbrrn|0123456789ab', self::BENESOV => '<b>|"x'];

        $values = self::inside($gate, static fn(): array => [$gate->regbox(strtoupper(self::ADAMOV)), $gate->regbox(self::BENESOV), $gate->regbox(self::CHEB), $gate->regbox('x')]);

        self::assertSame(['a7kbrrn|0123456789ab', '&lt;b&gt;|&quot;x', '', ''], $values);
        self::assertSame(1, $gate->offeredReads);
        self::assertSame('', $gate->regbox(self::ADAMOV), 'outside the UI');
    }

    /** @param \Closure(FakeGate): void $change */
    #[DataProvider('notFixable')]
    public function test_regbox_onlyWhereFixBoxIsOffered(Closure $change): void
    {
        $gate          = self::fixGate();
        $gate->offered = [self::ADAMOV => 'a7kbrrn|0123456789ab'];
        $change($gate);

        self::assertSame('', self::inside($gate, static fn(): string => $gate->regbox(self::ADAMOV)));
        self::assertSame(0, $gate->offeredReads, 'the register not paired');
    }

    // settings that no longer fit, a register that cannot be read: the page of the register says what
    public function test_regbox_failureGivesNothing(): void
    {
        $gate          = self::fixGate();
        $gate->offered = new \RuntimeException('The register is damaged');

        self::assertSame(['', ''], self::inside($gate, static fn(): array => [$gate->regbox(self::ADAMOV), $gate->regbox(self::BENESOV)]));
        self::assertSame(1, $gate->offeredReads, 'not tried again on every row');
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

    /** 60 towns with a box each and 3 without: two pages of those who get the letter */
    public function test_recipients_pagedIdsInResolveOrder(): void
    {
        $items = [];
        foreach (range(1, 63) as $n) {
            $items[sprintf('a%031d', $n)] = Sending::item(sprintf('Obec %02d', 64 - $n), 'mesto', '1', ($n % 21 === 0) ? '' : sprintf('b%02dx234', $n));
        }
        $gate            = self::gate();
        $gate->campaigns = [self::ITEM => ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => Sending::filter(), 'state' => 'draft']];
        $gate->resolver  = new FakeRecipients($items);
        // by name: the item of the last n first
        $sendable = array_values(array_filter(array_reverse(array_keys($items)), static fn(string $id): bool => (int) substr($id, 1) % 21 !== 0));
        $page     = static function (string $page) use ($gate): array {
            $_GET = ['isds_page' => $page];

            return self::inside($gate, static fn(): array => [$gate->recipients(self::ITEM, 'ids'), $gate->recipients(self::ITEM, 'skippedids'), $gate->recipients(self::ITEM, 'count')]);
        };

        self::assertSame([implode('-', array_slice($sendable, 0, 50)), sprintf('a%031d-a%031d-a%031d', 63, 42, 21), '60'], $page('1'));
        self::assertSame([implode('-', array_slice($sendable, 50)), '', '60'], $page('2'));
        self::assertSame(['', '', '60'], $page('3'));
        self::assertCount(1, FakeRecipients::$read, 'the questions of a request resolve once');
    }

    // ------------------------------------------------------------ the views of the page and the ids they list

    /** the database of the views, whose rows of view are $views */
    private static function viewsAre(int ...$views): void
    {
        RecordingDb::reset();
        RecordingDb::$answer = static fn(string $sql): array => array_map(static fn(int $id): array => ['id' => (string) $id], $views);
        $GLOBALS['spareDBs'] = [new RecordingDb()];
    }

    public function test_view_onlyViewsOfTheBinding(): void
    {
        self::viewsAre(12);
        $gate = self::gate();

        self::assertSame('<view 12>', self::inside($gate, static fn(): string => $gate->view('isds_history', self::ITEM, 'listlen-50')));
        self::assertSame(['SELECT id FROM view WHERE slice_id IN (0x' . self::CAMPAIGN . ',0x' . self::MESSAGE . ") AND field3 = 'isds_history'"], RecordingDb::$log);
        self::assertSame([[12, self::ITEM, 'listlen-50']], $gate->shownViews);
    }

    // two: State Check names them (CheckIsdsViews); none: renamed or deleted, only the page meets it
    public function test_view_twoOfOneNameNone(): void
    {
        $gate = self::gate();
        self::viewsAre(12, 31);
        self::assertSame('', self::inside($gate, static fn(): string => $gate->view('isds_history')));
        self::assertSame([], $gate->logged);
        AA::Contentcache()->clear();
        self::viewsAre();
        self::assertSame('', self::inside($gate, static fn(): string => $gate->view('isds_history')), 'none of the name');

        self::assertSame([], $gate->shownViews);
        self::assertSame([[self::SITE . ':isds_history', 'No view of the slices of the page has this name']], $gate->logged);
    }

    // the page shows only names State Check can see: isdscampainer and isds_<part>
    public function test_view_otherNamesRefusedWithoutAQuery(): void
    {
        self::viewsAre(12);
        $gate = self::gate();

        self::assertSame(['', '', '', '', ''], self::inside($gate, static fn(): array => [$gate->view('foo', self::ITEM), $gate->view('isds_', self::ITEM), $gate->view(''), $gate->view('isdscampainerx'), $gate->view('ISDS_history')]));
        self::assertSame([[], [], []], [RecordingDb::$log, $gate->shownViews, $gate->logged]);
        self::assertSame('<view 12>', self::inside($gate, static fn(): string => $gate->view('isdscampainer')), 'the entry view');
    }

    public function test_view_outsideGateEmpty(): void
    {
        self::viewsAre(12);
        $gate = self::gate();

        self::assertSame('', $gate->view('isds_history', self::ITEM));
        self::assertSame(['', ''], [$gate->campaigns(), $gate->param('tab')]);
        self::assertSame([[], []], [RecordingDb::$log, $gate->shownViews]);
    }

    // the ids as given, in their order; what is not an id never reaches the view
    public function test_view_idsKeepGateOrder(): void
    {
        self::viewsAre(12);
        $gate = self::gate();

        self::inside($gate, static fn(): array => [
            $gate->view('isds_messages', strtoupper(self::BRNO) . '-' . self::ADAMOV),
            $gate->view('isds_messages', self::CHEB . '-123-x-' . self::ADAMOV . '-'),
            $gate->view('isds_messages', " \n" . self::DECIN . ' - ' . self::CHEB . "\n"),
            $gate->view('isds_messages'),
        ]);

        self::assertSame([[12, self::BRNO . '-' . self::ADAMOV, null], [12, self::CHEB . '-' . self::ADAMOV, null], [12, self::DECIN . '-' . self::CHEB, null], [12, '', null]], $gate->shownViews);
        self::assertCount(1, RecordingDb::$log, 'the view is looked for once a request');
    }

    public function test_campaigns_pageOfThirtyLastEditedFirst(): void
    {
        $gate               = self::gate();
        $gate->campaignList = array_map(static fn(int $n): string => sprintf('ca%030d', $n), range(1, 65));
        $asked              = static function (string $page) use ($gate): array {
            $_GET = ['isds_page' => $page];

            return self::inside($gate, static fn(): array => [$gate->campaigns(), $gate->campaigns('count'), $gate->campaigns('all')]);
        };

        self::assertSame([implode('-', array_slice($gate->campaignList, 0, 30)), '65', ''], $asked('1'));
        self::assertSame([implode('-', array_slice($gate->campaignList, 60)), '65', ''], $asked('3'));
        self::assertSame(['', '65', ''], $asked('4'));
        self::assertSame(1, $gate->campaignSearches, 'one search a request');
    }

    /** @return array<string, array{string, string, string}> name, the value of the URL, what the template gets */
    public static function params(): array
    {
        return [
            'tab'                => ['tab', 'history', 'history'],
            'tab check'          => ['tab', 'check', 'check'],
            'tab unknown'        => ['tab', 'compose', ''],
            'tab in capitals'    => ['tab', 'History', ''],
            'show a group'       => ['show', 'problem', 'problem'],
            'show the tests'     => ['show', 'test', 'test'],
            'show unknown'       => ['show', 'all', ''],
            'step'               => ['step', 'files', 'files'],
            'step with more'     => ['step', 'send ', ''],
            'part'               => ['part', 'search', 'search'],
            'list'               => ['list', 'skip', 'skip'],
            'no such parameter'  => ['campaign', 'x', ''],
            'a template'         => ['tab', '{datovkabulk:env}', ''],
        ];
    }

    #[DataProvider('params')]
    public function test_param_onlyFixedValues(string $name, string $value, string $got): void
    {
        $_GET = ["isds_$name" => $value];
        $gate = self::gate();

        self::assertSame($got, self::inside($gate, static fn(): string => $gate->param($name)));
    }

    public function test_param_arrayOrJunkEmpty(): void
    {
        $_GET = ['isds_tab' => ['history'], 'isds_show' => ['problem' => 'problem'], 'isds_step' => '', 'isds_page' => ['2']];
        $gate = self::gate();

        self::assertSame(['', '', '', '', '1'], self::inside($gate, static fn(): array => [$gate->param('tab'), $gate->param('show'), $gate->param('step'), $gate->param('part'), $gate->param('page')]));
    }

    /** @return array<string, array{mixed, string}> isds_page, page */
    public static function pages(): array
    {
        return [
            'none'           => [null, '1'],
            'the second'     => ['2', '2'],
            'four digits'    => ['9999', '9999'],
            'leading zeros'  => ['0007', '7'],
            'zero'           => ['0', '1'],
            'five digits'    => ['10000', '1'],
            'negative'       => ['-2', '1'],
            'with more'      => ['2x', '1'],
            'a new line'     => ["2\n", '1'],
            'a plus'         => ['+2', '1'],
        ];
    }

    #[DataProvider('pages')]
    public function test_param_pageDigitsOnly(mixed $page, string $got): void
    {
        $_GET = is_null($page) ? [] : ['isds_page' => $page];
        $gate = self::gate();

        self::assertSame($got, self::inside($gate, static fn(): string => $gate->param('page')));
    }

    /** what url($pairs) gives the UI of the page at $uri */
    private static function urlAt(string $uri, string $pairs): string
    {
        $_SERVER['REQUEST_URI'] = $uri;
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $_GET);
        $gate = self::gate();

        return self::inside($gate, static fn(): string => $gate->url($pairs));
    }

    /** @return array<string, array{string, string, string}> the page, the pairs of the link, its href as the template prints it */
    public static function urls(): array
    {
        $item = self::ITEM;

        return [
            // a page with its own query: the bare "?isds_..." of before lost site_id
            'the query of the page stays' => ['/aadev/modules/site/site.php?site_id=b6519d506ccc9e604b40d84b5fa09f91', "isds_campaign=$item&isds_tab=check", "/aadev/modules/site/site.php?site_id=b6519d506ccc9e604b40d84b5fa09f91&amp;isds_campaign=$item&amp;isds_tab=check"],
            'a routed page'               => ['/cz/rozesilka', 'isds_tab=history', '/cz/rozesilka?isds_tab=history'],
            // each link says all it wants of the page: nothing isds_ of the URL stays, as PHP reads the names
            'the pairs of the page go'    => ["/cz/rozesilka?isds_tab=history&a=1&isds_r=0123456789abcdef&isds.step=send&isds_page%5B%5D=2&isds_Q=y&q=a%26b&isds_campaign=$item#top", 'isds_page=2', '/cz/rozesilka?a=1&amp;q=a%26b&amp;isds_page=2'],
            'no pairs'                    => ["/cz/rozesilka?a=1&isds_campaign=$item", '', '/cz/rozesilka?a=1'],
            'nothing left'                => ["/cz/rozesilka?isds_campaign=$item", '', '/cz/rozesilka'],
            'a fragment'                  => ['/cz/rozesilka?a=1', 'isds_show=ok#isds-msgs', '/cz/rozesilka?a=1&amp;isds_show=ok#isds-msgs'],
            'only a fragment'             => ['/cz/rozesilka', '#isds-list', '/cz/rozesilka#isds-list'],
            'a fragment of anything'      => ['/cz/rozesilka', 'isds_tab=history#a"b', '/cz/rozesilka?isds_tab=history'],
            // a value with no value says nothing: {ifset} need not guard {datovkabulk:campaign} of a new campaign
            'an empty value'              => ['/cz/rozesilka', 'isds_campaign=&isds_tab=history', '/cz/rozesilka?isds_tab=history'],
            // a value adds no pair nor path of its own
            'a value encoded'             => ['/cz/rozesilka', 'isds_q=a?b/c', '/cz/rozesilka?isds_q=a%3Fb%2Fc'],
            'only names of the page'      => ['/cz/rozesilka', 'site_id=x&isds_tab=history&isds_TAB=x&isds_=x&=x&isds_tab[]=x', '/cz/rozesilka?isds_tab=history'],
            // the browser sent it so, the attribute must hold it
            'the query escaped'           => ['/cz/rozesilka?a="><b>&c=\'', 'isds_tab=history', '/cz/rozesilka?a=&quot;&gt;&lt;b&gt;&amp;c=&apos;&amp;isds_tab=history'],
            'the path escaped'            => ['/cz/"x"', '', '/cz/&quot;x&quot;'],
        ];
    }

    #[DataProvider('urls')]
    public function test_url_keepsTheQueryOfThePage(string $uri, string $pairs, string $href): void
    {
        self::assertSame($href, self::urlAt($uri, $pairs));
    }

    // the same rule as the redirect after an action
    #[DataProvider('foreignPaths')]
    public function test_url_neverLeavesTheHost(string $uri): void
    {
        self::assertSame('/?isds_tab=history', self::urlAt($uri, 'isds_tab=history'));
    }

    public function test_url_outsideTheUiNothing(): void
    {
        $_SERVER['REQUEST_URI'] = '/cz/rozesilka?a=1';

        self::assertSame('', self::gate()->url('isds_tab=history'));
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

    /**
     * Save of ITEM posted from the page at $uri by the form of the draft with $fields; the core says $code,
     * and $refiltered when it stored another filter
     * @param array<string, mixed> $fields
     */
    private static function postSave(string $code, array $fields, string $uri = '/cz/rozesilka?tab=2', bool $refiltered = false): void
    {
        FakeActions::$save = static fn(): array => ['code' => $code, 'campaign' => self::ITEM, 'field' => ($code === Actions::INPUT) ? Drafts::SUBJECT : '', 'file' => '', 'refiltered' => $refiltered];
        self::postResume(uri: $uri);
        $_POST = ['isds_action' => 'save', 'isds_token' => FormToken::get(Gate::purpose('save', self::page(), self::ITEM))] + $fields + $_POST;
    }

    /** @return array<string, array{string, string}> what the core says of a save, and what the URL after it adds */
    public static function savesAndSteps(): array
    {
        return [
            'saved'             => [Actions::SAVED, '&isds_step=search&isds_q=Brno'],
            // the letter saved, the choice not: no other card opens, the page shows the choice stored
            'filter changed'    => [Actions::FILTER_CHANGED, ''],
            // the page stays at the form, with what to fix
            'refused'           => [Actions::INPUT, ''],
            'busy'              => [SendStart::BUSY, ''],
            'no draft any more' => [SendStart::NOT_DRAFT, ''],
        ];
    }

    // "Save and search" without JavaScript: the text is saved first, then the page shows the step
    #[DataProvider('savesAndSteps')]
    public function test_redirect_keepsStepAndQueryOnlyWhenSaved(string $code, string $added): void
    {
        self::postSave($code, ['isds_step' => 'search', 'isds_q' => 'Brno']);

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&isds_campaign=' . self::ITEM . preg_quote($added, '~') . '&isds_r=[0-9a-f]{16}\z~', $this->redirectOf(self::actionGate()));
    }

    // only the form of the draft goes on to a step
    public function test_redirect_noStepAfterAnotherAction(): void
    {
        self::postResume();
        $_POST += ['isds_step' => 'search', 'isds_q' => 'Brno'];

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&isds_campaign=' . self::ITEM . '&isds_r=[0-9a-f]{16}\z~', $this->redirectOf(self::actionGate()));
    }

    // the draft in the trash is no campaign to show: the page goes on with none, and tells what became of it
    public function test_redirect_noCampaignAfterDiscard(): void
    {
        FakeActions::$discard = static fn(): string => Actions::DISCARDED;
        self::postResume(uri: '/cz/rozesilka?tab=2&isds_campaign=' . self::ITEM . '&isds_step=files');
        $_POST = ['isds_action' => 'discard', 'isds_token' => FormToken::get(Gate::purpose('discard', self::page(), self::ITEM))] + $_POST;
        $gate  = self::actionGate();

        $url = $this->redirectOf($gate);

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&isds_r=[0-9a-f]{16}\z~', $url);
        self::getAfter($url);
        self::assertSame([Actions::DISCARDED, ActionResult::DONE, 'discard'], array_slice(self::shown($gate), 1));
        self::assertSame('', self::inside($gate, static fn(): string => $gate->campaign()));
    }

    // the search field of the draft form is posted by every save: the source is searched only for Search
    public function test_redirect_queryOnlyForSearch(): void
    {
        self::postSave(Actions::SAVED, ['isds_step' => 'test', 'isds_q' => 'Brno']);

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&isds_campaign=' . self::ITEM . '&isds_step=test&isds_r=[0-9a-f]{16}\z~', $this->redirectOf(self::actionGate()));
    }

    /** @return array<string, array{mixed, string}> isds_step posted, and what param:step tells after the save */
    public static function steps(): array
    {
        return [
            'test'            => ['test', 'test'],
            'send'            => ['send', 'send'],
            'search'          => ['search', 'search'],
            'files'           => ['files', 'files'],
            'another'         => ['history', ''],
            'in capitals'     => ['Test', ''],
            'with a space'    => ['test ', ''],
            'a list'          => [['test'], ''],
            'a parameter too' => ['test&isds_tab=check', ''],
            'empty'           => ['', ''],
        ];
    }

    #[DataProvider('steps')]
    public function test_redirect_stepOnlyFixed(mixed $step, string $shown): void
    {
        self::postSave(Actions::SAVED, ['isds_step' => $step]);
        $url = $this->redirectOf(self::actionGate());

        self::assertSame(($shown === '') ? 0 : 1, substr_count($url, 'isds_step='));
        self::assertStringNotContainsString('isds_tab', $url);
        self::getAfter($url);
        $gate = self::actionGate();
        self::assertSame($shown, self::inside($gate, static fn(): string => $gate->param('step')));
    }

    /** @return array<string, array{mixed, ?string}> isds_q posted, and the text the page then looks for; null = none */
    public static function queries(): array
    {
        return [
            'a name'                => ['Brno', 'Brno'],
            'cut to 100 letters'    => [str_repeat('č', 150), str_repeat('č', 100)],
            'what a URL would read' => ['%_ a&isds_step=send#x', '%_ a&isds_step=send#x'],
            'spaces trimmed'        => [' Ústí nad Labem ', 'Ústí nad Labem'],
            // empty once cut
            'spaces, then a name'   => [str_repeat(' ', 100) . 'Brno', null],
            'only spaces'           => ['   ', null],
            'not UTF-8'             => ["Brn\xF3", null],
            'a list'                => [['Brno'], null],
            'empty'                 => ['', null],
        ];
    }

    // cut before it is encoded: a cut escape or letter would make no text the search takes
    #[DataProvider('queries')]
    public function test_redirect_queryCutBeforeEncoding(mixed $query, ?string $looked): void
    {
        self::postSave(Actions::SAVED, ['isds_step' => 'search', 'isds_q' => $query]);
        $url = $this->redirectOf(self::actionGate());

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&isds_campaign=' . self::ITEM . '&isds_step=search(&isds_q=[A-Za-z0-9%_.-]+)?&isds_r=[0-9a-f]{16}\z~', $url);
        self::getAfter($url);
        self::assertSame([$looked, 'search'], [$_GET['isds_q'] ?? null, $_GET['isds_step']]);
    }

    /** @return array<string, array{\Closure(string): void, string}> an action posted from a page at a step, and what of its URL stays */
    public static function actionsAfterAStep(): array
    {
        $resume = static function (?string $code): \Closure {
            return static function (string $uri) use ($code): void {
                FakeActions::$resume = is_null($code) ? null : static fn(): string => $code;
                self::postResume(uri: $uri);
            };
        };
        $save = static fn(string $code, bool $refiltered = false): \Closure => static fn(string $uri) => self::postSave($code, [], $uri, $refiltered);

        return [
            // its form sets the step anew - none here: the card closes, and so does the page of its list
            'saved'                => [$save(Actions::SAVED), 'isds_tab=history&isds_Q=y&isds_show=ok'],
            // the choice not saved: the card stays, the page of its list while the lists are those it showed
            'an item gone'         => [$save(Actions::FILTER_CHANGED), 'isds_tab=history&isds_step=send&isds_q=x&isds_page=3&isds_Q=y&isds_show=ok'],
            'a stale page'         => [$save(Actions::FILTER_CHANGED, true), 'isds_tab=history&isds_step=send&isds_q=x&isds_Q=y&isds_show=ok'],
            // done: the step goes, the page of the list stays - a row fixed on page 3 stays on page 3
            'continued'            => [$resume(null), 'isds_tab=history&isds_page=3&isds_Q=y&isds_show=ok'],
            // not done: the card it failed on stays
            'not continued'        => [$resume(Actions::WRONG_STATE), 'isds_tab=history&isds_step=send&isds_q=x&isds_page=3&isds_Q=y&isds_show=ok'],
            'not saved'            => [$save(Actions::INPUT), 'isds_tab=history&isds_step=send&isds_q=x&isds_page=3&isds_Q=y&isds_show=ok'],
        ];
    }

    // a part of the page asked for alone never comes back; the step, the search and the page of the list only as the action tells
    #[DataProvider('actionsAfterAStep')]
    public function test_redirect_dropsOldStepQueryPartPage(\Closure $post, string $kept): void
    {
        $post('/cz/rozesilka?isds_tab=history&isds_step=send&isds_q=x&isds_part=progress&isds_page=3&isds_Q=y&isds_show=ok');

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?' . preg_quote($kept, '~') . '&isds_campaign=' . self::ITEM . '&isds_r=[0-9a-f]{16}\z~', $this->redirectOf(self::actionGate()));
    }

    /**
     * @return array<string, array{string, bool, array<string, string>, string}> what the core says of a save, whether the lists
     *         are others than the form showed, the form, and the query after it - posted from a page at step search, page 3
     */
    public static function savesAndPages(): array
    {
        // an autosave posts the step of the URL
        $search = ['isds_step' => 'search', 'isds_q' => 'Brno'];
        $send   = ['isds_step' => 'send'];
        $item   = 'isds_campaign=' . self::ITEM;

        return [
            // an autosave every few seconds must not send the user back to the first page of the recipients
            'the filter the same'        => [Actions::SAVED, false, $search, "isds_page=3&$item&isds_step=search&isds_q=Brno"],
            'another filter'             => [Actions::SAVED, true, $search, "$item&isds_step=search&isds_q=Brno"],
            'a pick'                     => [Actions::SAVED, true, ['isds_pick' => 'include-' . self::ITEM] + $search, "$item&isds_step=search&isds_q=Brno"],
            // the list of another card has pages of its own
            'another step'               => [Actions::SAVED, false, $send, "$item&isds_step=send"],
            // the letter saved, the choice not: the card stays, its page while the lists are those it showed
            'an item gone'               => [Actions::FILTER_CHANGED, false, $search, "isds_page=3&$item&isds_step=search&isds_q=Brno"],
            'a stale page'               => [Actions::FILTER_CHANGED, true, $search, "$item&isds_step=search&isds_q=Brno"],
            // nor does a choice not stored open another card
            'an item gone, another step' => [Actions::FILTER_CHANGED, false, $send, "isds_step=search&isds_q=x&isds_page=3&$item"],
            'a stale page, another step' => [Actions::FILTER_CHANGED, true, $send, "isds_step=search&isds_q=x&$item"],
        ];
    }

    /** @param array<string, string> $form */
    #[DataProvider('savesAndPages')]
    public function test_redirect_pageOfTheListOnlyWhileTheFilterStays(string $code, bool $refiltered, array $form, string $query): void
    {
        self::postSave($code, $form, '/cz/rozesilka?isds_step=search&isds_q=x&isds_page=3', $refiltered);

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?' . preg_quote($query, '~') . '&isds_r=[0-9a-f]{16}\z~', $this->redirectOf(self::actionGate()));
    }

    // a Save with files posted again is told the first result - with its filter stored, from the session
    public function test_redirect_secondClickKnowsTheFilterChanged(): void
    {
        self::postSave(Actions::SAVED, [], '/cz/rozesilka?isds_page=3', true);
        $form   = $_POST;
        $_FILES = ['isds_files' => ['name' => ['a.pdf'], 'type' => ['application/pdf'], 'tmp_name' => ['/tmp/php1'], 'error' => [UPLOAD_ERR_OK], 'size' => [10]]];
        $first  = $this->redirectOf(self::actionGate());
        Actions::forget();
        FakeActions::$save = null;
        self::postResume(uri: '/cz/rozesilka?isds_page=3');
        $_POST = $form;

        $second = $this->redirectOf(self::actionGate());

        self::assertCount(1, FakeActions::$saved);
        self::assertStringNotContainsString('isds_page', $first . $second);
    }

    // PHP reads "isds.step", "isds step", "isds+part" and "isds[page" as isds_step, isds_part, isds_page
    public function test_redirect_dropsNamesAsPhpReadsThem(): void
    {
        self::postSave(Actions::SAVED, [], refiltered: true, uri: '/cz/rozesilka?isds.step=send&isds%20q=x&isds+part=progress&isds[page=3&isds.r=0123456789abcdef&isds.campaign=c&isds_page%5B%5D=2&keep=1');

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?keep=1&isds_campaign=' . self::ITEM . '&isds_r=[0-9a-f]{16}\z~', $this->redirectOf(self::actionGate()));
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

    // a script may fill one shown form of Fix box for each row: another recipient is another post
    public function test_result_fixboxOfAnotherRecipientActsAgain(): void
    {
        FakeActions::$fixbox = static fn(): array => ['code' => Sync::FIXED, 'old' => '', 'new' => 'a7kbrrn'];
        $gate                = static function (): FakeGate {
            $gate        = self::actionGate();
            $gate->setup = self::fixGate()->setup;

            return $gate;
        };
        self::postResume();
        $form = ['isds_action' => 'fixbox', 'isds_token' => FormToken::get(Gate::purpose('fixbox', $gate()->setup, self::ITEM)), 'isds_recipient' => self::ADAMOV, 'isds_box' => 'a7kbrrn|0123456789ab'] + $_POST;
        foreach ([self::ADAMOV, self::BENESOV, self::ADAMOV] as $recipient) {
            Actions::forget();
            $_POST = ['isds_recipient' => $recipient] + $form;
            $this->redirectOf($gate());
        }

        self::assertSame([self::ADAMOV, self::BENESOV], array_column(FakeActions::$fixed, 1), 'the same recipient again only shows what was done');
    }

    // one shown form of Verify, or of a copy to one recipient, may serve each message of a list: another message is another post
    public function test_result_ofAnotherMessageActsAgain(): void
    {
        self::postResume();
        $form = ['isds_action' => 'verify', 'isds_token' => FormToken::get(Gate::purpose('verify', self::page(), self::ITEM))] + $_POST;
        foreach ([self::ADAMOV, self::BENESOV, self::ADAMOV] as $message) {
            Actions::forget();
            $_POST = ['isds_message' => $message] + $form;
            $this->redirectOf(self::actionGate());
        }

        self::assertSame([self::ADAMOV, self::BENESOV], array_column(FakeActions::$verified, 2), 'the same message again only shows what was done');
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

    // a Save with files would attach them again: the same form posted again shows what the first post did
    public function test_result_saveWithFilesNotRepeated(): void
    {
        FakeActions::$save = static fn(): array => ['code' => Actions::SAVED, 'campaign' => self::ITEM, 'field' => '', 'file' => '', 'refiltered' => false];
        self::postResume();
        $_POST  = ['isds_action' => 'save', 'isds_token' => FormToken::get(Gate::purpose('save', self::page(), self::ITEM))] + $_POST;
        $form   = $_POST;
        $_FILES = ['isds_files' => ['name' => ['a.pdf'], 'type' => ['application/pdf'], 'tmp_name' => ['/tmp/php1'], 'error' => [UPLOAD_ERR_OK], 'size' => [10]]];
        $this->redirectOf(self::actionGate());
        Actions::forget();
        $_POST = $form;

        self::getAfter($this->redirectOf(self::actionGate()));

        self::assertCount(1, FakeActions::$saved);
        self::assertSame(Actions::SAVED, self::shown(self::actionGate())[1]);

        // the same form with its file input left empty is an autosave again: each post saves
        $_SERVER['REQUEST_METHOD']     = 'POST';
        $_POST                         = $form;
        $_FILES['isds_files']['error'] = [UPLOAD_ERR_NO_FILE];
        foreach ([1, 2] as $post) {
            Actions::forget();
            $this->redirectOf(self::actionGate());
        }
        self::assertCount(3, FakeActions::$saved);
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
        FakeActions::$save = static fn(): array => ['code' => Actions::SAVED, 'campaign' => self::ITEM, 'field' => '', 'file' => '', 'refiltered' => false];
        self::postResume();
        $_POST = ['isds_action' => 'save', 'isds_campaign' => '', 'isds_token' => FormToken::get(Gate::purpose('save', self::page(), '')), 'isds_nonce' => str_repeat('a', 32), 'isds_subject' => 'Výzva'];

        $url = $this->redirectOf(self::actionGate());

        self::assertMatchesRegularExpression('~^/cz/rozesilka\?tab=2&isds_campaign=' . self::ITEM . '&isds_r=[0-9a-f]{16}$~', $url);
        self::getAfter($url);
        $gate = self::actionGate();
        self::assertSame(['The draft is saved.', self::ITEM], self::inside($gate, static fn(): array => [$gate->result(), $gate->campaign()]));
    }

    // the page tells the filter it read, Drafts compares that under the lock with the one it reads: one
    // item, read both ways, gives the same bytes - else every choice of recipients would be dropped
    public function test_stored_gateAndDraftsReadTheSameFilter(): void
    {
        $filter = " {\"types\":[\"město\"],\"membership\":\"all\",\"include\":[],\"exclude\":[]}\n";
        RecordingDb::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        RecordingDb::$answer = static function (string $sql) use ($filter): array {
            if (preg_match('/^SELECT (.+?) FROM item WHERE/', $sql, $m)) {
                $row = ['id' => pack_id(self::ITEM), 'slice_id' => pack_id(self::CAMPAIGN), 'status_code' => '1', 'publish_date' => '1', 'expiry_date' => '4000000000'] + array_fill_keys(AA::Metabase()->getColumnNames('item'), '1');
                return [($m[1] === '*') ? $row : array_intersect_key($row, array_flip(explode(',', $m[1])))];
            }
            return str_starts_with($sql, 'SELECT * FROM content WHERE') ? [['item_id' => pack_id(self::ITEM), 'field_id' => Schema::CAMPAIGN_FILTER, 'number' => 0, 'text' => $filter, 'flag' => FLAG_TEXT_STORED]] : [];
        };
        // a slice with no reading password: nothing masked
        FakeSliceRules::fieldsSlice(self::CAMPAIGN, [Schema::CAMPAIGN_FILTER => 'qte']);

        $gate   = (new ReflectionMethod(Gate::class, 'stored'))->invoke(new Gate(), self::ITEM)['filter'] ?? null;
        $drafts = Drafts::stored(self::ITEM)?->getValue(Schema::CAMPAIGN_FILTER);

        self::assertSame([$filter, $filter], [$gate, $drafts]);
        self::assertSame(Drafts::filterSeen($gate), Drafts::filterSeen((string) $drafts));
    }

    // autosaves add a result every few seconds: they never push out the one that keeps a Test from going twice
    public function test_result_autosavesKeepTheGuardOfATest(): void
    {
        FakeActions::$test = static fn(): TestResult => TestResult::of(Outcome::of(new SendResult('1835603', '0000', 'OK')), 'msg', 'dj7j6mk', '4711-T2', true, true);
        FakeActions::$save = static fn(): array => ['code' => Actions::SAVED, 'campaign' => self::ITEM, 'field' => '', 'file' => '', 'refiltered' => false];
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
            // a draft discarded: Back, another tab - its editor would save nothing
            'a draft in the trash'     => ['ca00000000000000000000000000000e', ''],
            // its sending must stay stoppable
            'in the trash, sending'    => ['ca00000000000000000000000000000d', 'ca00000000000000000000000000000d'],
            'in the trash, sent by another binding' => ['ca00000000000000000000000000000c', ''],
            // holding bin, pending, expired: not in the trash, shown as before
            'a draft not active, not in the trash' => ['ca00000000000000000000000000000b', 'ca00000000000000000000000000000b'],
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
            'ca00000000000000000000000000000e' => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'draft', 'trashed' => true],
            'ca00000000000000000000000000000d' => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'sending', 'trashed' => true],
            'ca00000000000000000000000000000c' => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'sending', 'trashed' => true],
            'ca00000000000000000000000000000b' => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'draft', 'trashed' => false],
            self::LETTER                       => ['slice' => self::MESSAGE, 'active' => true, 'filter' => '', 'state' => ''],
        ];
        $run        = ['site' => self::SITE, 'camp_slice' => self::CAMPAIGN, 'msg_slice' => self::MESSAGE, 'state' => 'sending'];
        $gate->runs = [
            'ca00000000000000000000000000000d' => self::quietRun($run),
            'ca00000000000000000000000000000c' => self::quietRun(['msg_slice' => Sending::OTHER] + $run),
        ];
        $_GET = is_null($campaign) ? [] : ['isds_campaign' => $campaign];

        self::assertSame($read, self::inside($gate, static fn(): string => $gate->campaign()));
        self::assertSame('', $gate->campaign(), 'outside the UI');
    }

    /** @return array<string, array{string, string}> isds_campaign of the URL, the code the page is told with no action */
    public static function trashedNotices(): array
    {
        return [
            'a draft in the trash'  => ['ca00000000000000000000000000000e', 'DISCARDED'],
            'in the trash, sending' => ['ca00000000000000000000000000000d', ''],
            'an active draft'       => [self::ITEM, ''],
            'a draft in the holding bin' => ['ca00000000000000000000000000000b', ''],
            'no item'               => ['ca00000000000000000000000000000f', ''],
        ];
    }

    // the page opened on a draft in the trash says what became of it, as after Discard - not a new draft unexplained
    #[DataProvider('trashedNotices')]
    public function test_result_draftInTheTrashToldAsDiscarded(string $campaign, string $code): void
    {
        $gate            = self::gate();
        $gate->campaigns = [
            self::ITEM                         => ['slice' => self::CAMPAIGN, 'active' => true, 'filter' => '', 'state' => 'draft'],
            'ca00000000000000000000000000000e' => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'draft', 'trashed' => true],
            'ca00000000000000000000000000000d' => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'sending', 'trashed' => true],
            'ca00000000000000000000000000000b' => ['slice' => self::CAMPAIGN, 'active' => false, 'filter' => '', 'state' => 'draft', 'trashed' => false],
        ];
        $gate->runs      = ['ca00000000000000000000000000000d' => self::quietRun(['site' => self::SITE, 'camp_slice' => self::CAMPAIGN, 'msg_slice' => self::MESSAGE, 'state' => 'sending'])];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET                      = ['isds_campaign' => $campaign];

        self::assertSame([$code, ($code === '') ? '' : ActionResult::DONE], array_slice(self::shown($gate), 1, 2));
    }

    public function test_testboxes_escapedDashSeparated(): void
    {
        $gate        = self::gate();
        $gate->setup = Setup::fromSnapshot(self::SITE, self::CAMPAIGN, self::MESSAGE, self::SOURCE, ['mesto' => 'Cities'], testBoxes: ['dj7j6mk', 'ab12cde', '<b>']);

        self::assertSame('dj7j6mk-ab12cde-&lt;b&gt;', self::inside($gate, static fn(): string => $gate->testBoxes()));
        self::assertSame('', $gate->testBoxes(), 'outside the UI');
    }
}
